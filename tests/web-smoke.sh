#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# PiPDFBox local web smoke test.
# Runs an isolated copy on 127.0.0.1 and uses synthetic PDFs only.
# Does not run install.sh, change system services, or write outside a temp dir.

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/pipdfbox-web-smoke.XXXXXX")"
SERVER_PID=""

cleanup() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT INT TERM

fail() {
    echo "FEHLER: $*" >&2
    if [[ -f "$TMP_DIR/server.log" ]]; then
        echo "--- PHP-Testserver-Log ---" >&2
        tail -n 40 "$TMP_DIR/server.log" >&2 || true
    fi
    exit 1
}

for cmd in php python3 curl; do
    command -v "$cmd" >/dev/null 2>&1 || fail "Benötigtes Programm fehlt: $cmd"
done

php -r 'exit(extension_loaded("fileinfo") && extension_loaded("session") && function_exists("proc_open") ? 0 : 1);' \
    || fail "PHP benötigt fileinfo, session und proc_open."
python3 -c 'import pypdf' >/dev/null 2>&1 \
    || fail "Python-Modul pypdf fehlt (für Test-PDFs und Ergebnisprüfung)."

PHP_BIN="$(command -v php)"
PYTHON_BIN="$(command -v python3)"
TEST_PASSWORD="PiPDFBox-local-smoke-test-only"

mkdir -p \
    "$TMP_DIR/public" \
    "$TMP_DIR/data/jobs" \
    "$TMP_DIR/config" \
    "$TMP_DIR/sessions" \
    "$TMP_DIR/uploads"

cp -a "$ROOT_DIR/public/." "$TMP_DIR/public/"
cp "$ROOT_DIR/worker.py" "$TMP_DIR/worker.py"

# Rewrite only the temporary test copy so it cannot touch production paths.
python3 - \
    "$TMP_DIR/public/index.php" \
    "$TMP_DIR/public/download.php" \
    "$TMP_DIR/worker.py" \
    "$TMP_DIR/config/config.php" \
    "$TMP_DIR/data" \
    "$PYTHON_BIN" <<'PY'
from pathlib import Path
import sys

index_path, download_path, worker_path, config_path, data_path = map(Path, sys.argv[1:6])
python_bin = sys.argv[6]

index = index_path.read_text(encoding="utf-8")
old_config = "$configFile = '/etc/pipdfbox/config.php';"
new_config = f"$configFile = '{config_path.as_posix()}';"
old_worker = "['/usr/bin/python3', '/opt/pipdfbox/worker.py']"
new_worker = f"['{python_bin}', '{worker_path.as_posix()}']"

if index.count(old_config) != 1 or index.count(old_worker) != 1:
    raise SystemExit("Erwartete Pfadstellen in index.php sind nicht eindeutig.")
index = index.replace(old_config, new_config).replace(old_worker, new_worker)
index_path.write_text(index, encoding="utf-8")

download = download_path.read_text(encoding="utf-8")
old_require = "require '/etc/pipdfbox/config.php'"
new_require = f"require '{config_path.as_posix()}'"
if download.count(old_require) != 1:
    raise SystemExit("Erwartete Konfigurationsstelle in download.php fehlt oder ist mehrdeutig.")
download_path.write_text(download.replace(old_require, new_require), encoding="utf-8")
PY

PASSWORD_HASH="$(
    PIPDFBOX_TEST_PASSWORD="$TEST_PASSWORD" \
    "$PHP_BIN" -r 'echo password_hash(getenv("PIPDFBOX_TEST_PASSWORD"), PASSWORD_DEFAULT);'
)"
python3 - "$TMP_DIR/config/config.php" "$TMP_DIR/data" "$PASSWORD_HASH" <<'PY'
from pathlib import Path
import sys
config_path, data_path = map(Path, sys.argv[1:3])
password_hash = sys.argv[3]
config_path.write_text(
    "<?php\nreturn [\n"
    f"  'password_hash' => '{password_hash}',\n"
    f"  'data_dir' => '{data_path.as_posix()}',\n"
    "];\n",
    encoding="utf-8",
)
PY

"$PHP_BIN" -l "$TMP_DIR/public/index.php" >/dev/null || fail "PHP-Syntaxfehler in index.php."
"$PHP_BIN" -l "$TMP_DIR/public/download.php" >/dev/null || fail "PHP-Syntaxfehler in download.php."

python3 - "$TMP_DIR/data" <<'PY'
from pathlib import Path
from pypdf import PdfWriter
import sys

target = Path(sys.argv[1])
for name, page_count in (("synthetic_A.pdf", 2), ("synthetic_B.pdf", 1)):
    writer = PdfWriter()
    for _ in range(page_count):
        writer.add_blank_page(width=595, height=842)
    with (target / name).open("wb") as stream:
        writer.write(stream)
PY

PORT="$(python3 - <<'PY'
import socket
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    print(sock.getsockname()[1])
PY
)"
BASE_URL="http://127.0.0.1:$PORT"
COOKIE_FILE="$TMP_DIR/cookies.txt"

"$PHP_BIN" \
    -d "session.save_path=$TMP_DIR/sessions" \
    -d "upload_tmp_dir=$TMP_DIR/uploads" \
    -d "upload_max_filesize=8M" \
    -d "post_max_size=16M" \
    -S "127.0.0.1:$PORT" \
    -t "$TMP_DIR/public" >"$TMP_DIR/server.log" 2>&1 &
SERVER_PID=$!

server_ready=0
for _ in $(seq 1 50); do
    if curl -fsS "$BASE_URL/" -o "$TMP_DIR/initial.html" 2>/dev/null; then
        server_ready=1
        break
    fi
    sleep 0.1
done
[[ "$server_ready" == "1" ]] || fail "PHP-Testserver ist nicht gestartet."

extract_csrf() {
    python3 - "$1" <<'PY'
import re
import sys
html = open(sys.argv[1], encoding="utf-8").read()
match = re.search(r'name="csrf"\s+value="([a-f0-9]{64})"', html)
if not match:
    raise SystemExit("CSRF-Token fehlt")
print(match.group(1))
PY
}

extract_download_path() {
    python3 - "$1" <<'PY'
import re
import sys
html = open(sys.argv[1], encoding="utf-8").read()
match = re.search(r'href="(/download\.php\?job=[a-f0-9]{32}&file=[A-Za-z0-9_.-]+)"', html)
if not match:
    raise SystemExit("Ergebnis-Downloadlink fehlt")
print(match.group(1))
PY
}

assert_http() {
    local actual="$1" expected="$2" label="$3"
    [[ "$actual" == "$expected" ]] || fail "$label: HTTP $actual statt HTTP $expected."
}

# Login: the login form itself does not use CSRF in this application version.
login_code="$(
    curl -sS -o "$TMP_DIR/login-response.html" -w '%{http_code}' \
        -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
        --data-urlencode "password=$TEST_PASSWORD" "$BASE_URL/"
)"
assert_http "$login_code" "302" "Anmeldung"

curl -fsS -c "$COOKIE_FILE" -b "$COOKIE_FILE" "$BASE_URL/" -o "$TMP_DIR/app.html"
grep -q "PDF-Dateien verarbeiten" "$TMP_DIR/app.html" || fail "Angemeldete Oberfläche fehlt."
csrf="$(extract_csrf "$TMP_DIR/app.html")"

run_upload_and_download() {
    local operation="$1"
    local output_html="$2"
    shift 2
    local upload_code download_path download_code

    upload_code="$(
        curl -sS -o "$output_html" -w '%{http_code}' \
            -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
            -F "csrf=$csrf" -F "action=$operation" \
            "$@" "$BASE_URL/"
    )"
    assert_http "$upload_code" "200" "$operation Upload"

    download_path="$(extract_download_path "$output_html")"
    download_code="$(
        curl -sS -o "$TMP_DIR/$operation.result" -w '%{http_code}' \
            -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
            "$BASE_URL$download_path"
    )"
    assert_http "$download_code" "200" "$operation Download"
    printf '%s\n' "$download_path" > "$TMP_DIR/$operation.path"
    echo "OK: $operation Upload und Download"
}

run_upload_and_download merge "$TMP_DIR/merge.html" \
    -F "pdfs[]=@$TMP_DIR/data/synthetic_A.pdf;type=application/pdf" \
    -F "pdfs[]=@$TMP_DIR/data/synthetic_B.pdf;type=application/pdf"

python3 - "$TMP_DIR/merge.result" <<'PY'
from pypdf import PdfReader
import sys
if len(PdfReader(sys.argv[1], strict=True).pages) != 3:
    raise SystemExit("Merge-Ergebnis hat nicht 3 Seiten.")
print("OK: Merge-Ergebnis ist eine gültige PDF mit 3 Seiten")
PY

unauth_code="$(
    curl -sS -o /dev/null -w '%{http_code}' \
        "$BASE_URL$(cat "$TMP_DIR/merge.path")"
)"
assert_http "$unauth_code" "403" "Nicht angemeldeter Download"
echo "OK: Download ohne Anmeldung wird mit HTTP 403 verweigert"

csrf="$(extract_csrf "$TMP_DIR/merge.html")"
run_upload_and_download split "$TMP_DIR/split.html" \
    -F "pdfs[]=@$TMP_DIR/data/synthetic_A.pdf;type=application/pdf"

python3 - "$TMP_DIR/split.result" <<'PY'
from io import BytesIO
from zipfile import ZipFile
from pypdf import PdfReader
import sys

with ZipFile(sys.argv[1]) as archive:
    names = sorted(archive.namelist())
    page_counts = [len(PdfReader(BytesIO(archive.read(name)), strict=True).pages) for name in names]
if names != ["Seite_001.pdf", "Seite_002.pdf"] or page_counts != [1, 1]:
    raise SystemExit("Split-ZIP enthält nicht die erwarteten zwei Einzelseiten-PDFs.")
print("OK: Split-ZIP enthält zwei gültige Einzelseiten-PDFs")
PY

csrf="$(extract_csrf "$TMP_DIR/split.html")"
run_upload_and_download interleave "$TMP_DIR/interleave.html" \
    -F "pdfs[]=@$TMP_DIR/data/synthetic_A.pdf;type=application/pdf" \
    -F "pdfs[]=@$TMP_DIR/data/synthetic_B.pdf;type=application/pdf"

python3 - "$TMP_DIR/interleave.result" <<'PY'
from pypdf import PdfReader
import sys
if len(PdfReader(sys.argv[1], strict=True).pages) != 3:
    raise SystemExit("Interleave-Ergebnis hat nicht 3 Seiten.")
print("OK: Interleave-Ergebnis ist eine gültige PDF mit 3 Seiten")
PY

echo "PiPDFBox Web-Smoke-Test erfolgreich abgeschlossen."
echo "Geprüft: Anmeldung, CSRF-Verarbeitung, Merge, Split, Interleave, Download-Schutz."
echo "Nicht Bestandteil dieses Smoke-Tests: PHP-FPM, Nginx, Komprimierung und Nummerierung."
