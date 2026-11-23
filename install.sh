#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/opt/pipdfbox"
SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(cd -- "$(dirname -- "$SCRIPT_PATH")" && pwd)"
VERSION_FILE="$SCRIPT_DIR/VERSION"
VERSION="unbekannt"
if [[ -f "$VERSION_FILE" ]]; then
    VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"
fi
PUBLIC_DIR="$APP_DIR/public"
CONFIG_DIR="/etc/pipdfbox"
CONFIG_FILE="$CONFIG_DIR/config.php"
DATA_DIR="/var/lib/pipdfbox"
PORT_FILE="$CONFIG_DIR/port"
NGINX_SITE="/etc/nginx/sites-available/pipdfbox"
NGINX_ENABLED="/etc/nginx/sites-enabled/pipdfbox"
PHP_FPM_SERVICE="php8.4-fpm"
PHP_INI_FILE="/etc/php/8.4/fpm/conf.d/99-pipdfbox.ini"
GITHUB_ARCHIVE="https://codeload.github.com/pennynet/PiPDFBox/tar.gz/refs/heads/main"
OPENSC_VERSION="0.27.1"
# PiPDFBox verwendet ausschließlich diesen projekt-eigenen OpenSC-Prefix.
# Debian-Pakete unter /usr werden bewusst weder gepinnt noch deaktiviert;
# apt upgrade darf Systemupdates weiterhin normal einspielen.
OPENSC_PREFIX="/opt/opensc-${OPENSC_VERSION}"
OPENSC_SHA256="976f4a23eaf3397a1a2c3a7aac80bf971a8c3d829c9a79f06145bfaeeae5eca7"
PYTHON_LIB_DIR="$APP_DIR/python"
PKCS11_TOOL="$OPENSC_PREFIX/bin/pkcs11-tool"
PKCS11_MODULE="$OPENSC_PREFIX/lib/opensc-pkcs11.so"
POLKIT_RULE="/etc/polkit-1/rules.d/61-pcsc-www-data.rules"

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

info() { printf "${GREEN}%s${NC}\n" "$*"; }
error() { printf "${RED}%s${NC}\n" "$*" >&2; }
pause() { read -r -p "Weiter mit Enter ..."; }

require_root() {
    if [[ "$EUID" -ne 0 ]]; then
        error "Bitte so starten: sudo /opt/pipdfbox/install.sh"
        exit 1
    fi
}

require_supported_os() {
    if [[ ! -r /etc/os-release ]]; then
        error "Betriebssystem konnte nicht erkannt werden. Unterstützt wird Debian 13 (Trixie)."
        return 1
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ "${VERSION_CODENAME:-}" != "trixie" || ( "${ID:-}" != "debian" && "${ID:-}" != "raspbian" ) ]]; then
        error "Nicht unterstütztes Betriebssystem: ${PRETTY_NAME:-unbekannt}. Erwartet wird Debian 13 (Trixie)."
        return 1
    fi
}


download_app_from_github() {
    local temp_dir archive extracted
    command -v curl >/dev/null || { error "curl fehlt. Bitte installieren: sudo apt install curl"; return 1; }
    command -v tar >/dev/null || { error "tar fehlt."; return 1; }

    temp_dir="$(mktemp -d /tmp/pipdfbox-github.XXXXXX)"
    archive="$temp_dir/source.tar.gz"

    info "Lade PiPDFBox von GitHub herunter ..."
    if ! curl -fL --retry 2 --connect-timeout 15 "$GITHUB_ARCHIVE" -o "$archive"; then
        rm -rf "$temp_dir"
        error "GitHub-Download fehlgeschlagen."
        return 1
    fi

    if ! tar -xzf "$archive" -C "$temp_dir"; then
        rm -rf "$temp_dir"
        error "GitHub-Archiv konnte nicht entpackt werden."
        return 1
    fi

    extracted="$(find "$temp_dir" -mindepth 1 -maxdepth 1 -type d -name 'PiPDFBox-*' -print -quit)"
    if [[ -z "$extracted" || ! -f "$extracted/public/index.php" || ! -f "$extracted/worker.py" || ! -f "$extracted/opensc.py" ]]; then
        rm -rf "$temp_dir"
        error "Das GitHub-Archiv enthält nicht die erwarteten PiPDFBox-Dateien."
        return 1
    fi

    # Zielverzeichnis bei einer Erstinstallation anlegen. Bestehende lokale
    # Zusatzdateien und .git bleiben erhalten.
    if ! install -d -m 755 "$APP_DIR"; then
        rm -rf "$temp_dir"
        error "Anwendungsverzeichnis konnte nicht angelegt werden: $APP_DIR"
        return 1
    fi

    if ! cp -a "$extracted/." "$APP_DIR/"; then
        rm -rf "$temp_dir"
        error "Anwendungscode konnte nicht vollständig nach $APP_DIR kopiert werden."
        return 1
    fi

    # Wenn APP_DIR ein Git-Checkout ist, die Eigentümer von .git für den
    # Anwendungscode übernehmen. So bleiben spätere Git-Operationen möglich.
    if [[ -d "$APP_DIR/.git" ]] && ! chown -R --reference="$APP_DIR/.git" "$APP_DIR"; then
        rm -rf "$temp_dir"
        error "Eigentümer des Git-Checkouts konnten nicht angepasst werden."
        return 1
    fi

    if [[ -f "$extracted/VERSION" ]]; then
        VERSION="$(tr -d '[:space:]' < "$extracted/VERSION")"
    fi
    rm -rf "$temp_dir"
    info "Anwendungscode wurde aus GitHub aktualisiert."
}


# Laufzeit-Abhängigkeiten der PiPDFBox und der Installationsroutine.
REQUIRED_PACKAGES=(
    ca-certificates
    curl
    tar
    sudo
    polkitd
    nginx
    php8.4-cli
    php8.4-fpm
    php8.4-mbstring
    php8.4-zip
    ghostscript
    libreoffice
    libreoffice-writer
    libreoffice-calc
    libreoffice-impress
    qpdf
    poppler-utils
    python3
    python3-pip
    python3-pypdf
    python3-pil
    ocrmypdf
    tesseract-ocr
    tesseract-ocr-deu
    tesseract-ocr-eng
    tesseract-ocr-osd
    iproute2
    pcscd
    libccid
    libifd-cyberjack6
    libpcsclite-dev
    libssl-dev
    libreadline-dev
    autoconf
    automake
    build-essential
    docbook-xsl
    xsltproc
    libtool
    pkg-config
    zlib1g-dev
)

install_libreoffice() {
    echo
    info "Installiere LibreOffice für Word-, Excel- und PowerPoint-Dateien ..."
    apt-get update || {
        error "Paketlisten konnten nicht aktualisiert werden. LibreOffice wurde nicht installiert."
        return 1
    }
    DEBIAN_FRONTEND=noninteractive apt-get install -y libreoffice-writer libreoffice-calc libreoffice-impress || {
        error "LibreOffice-Pakete konnten nicht installiert werden. Bitte die apt-Fehlermeldung prüfen."
        return 1
    }
    command -v libreoffice >/dev/null || {
        error "LibreOffice wurde installiert, aber der Befehl 'libreoffice' ist nicht verfügbar."
        return 1
    }
    info "LibreOffice ist installiert: $(command -v libreoffice)"
}

check_dependencies() {
    local missing=() package
    echo
    info "Prüfe benötigte Pakete ..."
    for package in "${REQUIRED_PACKAGES[@]}"; do
        if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q "install ok installed"; then
            echo "[OK] $package"
        else
            echo "[FEHLT] $package"
            missing+=("$package")
        fi
    done

    if (( ${#missing[@]} > 0 )); then
        echo
        info "Installiere fehlende Pakete automatisch ..."
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
    else
        info "Alle benötigten Pakete sind bereits installiert."
    fi

    # Nach der Installation nochmals kontrollieren.
    for package in "${REQUIRED_PACKAGES[@]}"; do
        dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q "install ok installed" || {
            error "Abhängigkeit fehlt weiterhin: $package"
            return 1
        }
    done

    command -v php >/dev/null || { error "PHP CLI ist nicht verfügbar."; return 1; }
    php8.4 -m | grep -qi '^session$' || { error "PHP-Erweiterung session fehlt."; return 1; }
    php8.4 -m | grep -qi '^json$' || { error "PHP-Erweiterung json fehlt."; return 1; }
    php8.4 -m | grep -qi '^mbstring$' || { error "PHP-Erweiterung mbstring fehlt."; return 1; }
    php8.4 -m | grep -qi '^zip$' || { error "PHP-Erweiterung zip fehlt."; return 1; }
    php8.4 -r 'exit(extension_loaded("fileinfo") && function_exists("proc_open") ? 0 : 1);' || {
        error "PHP-Erweiterung fileinfo oder Funktion proc_open fehlt."
        return 1
    }
    phpenmod -v 8.4 zip || { error "PHP-Erweiterung zip konnte nicht aktiviert werden."; return 1; }
    systemctl restart php8.4-fpm || { error "PHP-FPM konnte nicht neu gestartet werden."; return 1; }
    command -v ocrmypdf >/dev/null || { error "OCRmyPDF ist nicht verfügbar."; return 1; }
    command -v libreoffice >/dev/null || { error "LibreOffice ist nicht verfügbar."; return 1; }
    command -v tesseract >/dev/null || { error "Tesseract ist nicht verfügbar."; return 1; }
    tesseract --list-langs 2>/dev/null | grep -qx 'deu' || { error "Tesseract-Sprachpaket Deutsch fehlt."; return 1; }
    tesseract --list-langs 2>/dev/null | grep -qx 'eng' || { error "Tesseract-Sprachpaket Englisch fehlt."; return 1; }
    tesseract --list-langs 2>/dev/null | grep -qx 'osd' || { error "Tesseract-Sprachpaket für Orientierungserkennung fehlt."; return 1; }
    python3 -c 'import PIL; import pypdf' 2>/dev/null || {
        error "Python-Module Pillow oder pypdf fehlen."
        return 1
    }
    info "Abhängigkeitsprüfung erfolgreich."
}

check_app() {
    [[ -f "$PUBLIC_DIR/index.php" && -f "$APP_DIR/worker.py" && -f "$APP_DIR/opensc.py" ]] || {
        error "PiPDFBox-Dateien fehlen unter $APP_DIR."
        return 1
    }
}

install_opensc() {
    local temp_dir source_dir archive
    if [[ -x "$OPENSC_PREFIX/bin/pkcs11-tool" && -f "$OPENSC_PREFIX/lib/opensc-pkcs11.so" ]]; then
        info "OpenSC $OPENSC_VERSION ist bereits installiert."
    else
        temp_dir="$(mktemp -d /tmp/pipdfbox-opensc.XXXXXX)"
        archive="$temp_dir/opensc-$OPENSC_VERSION.tar.gz"
        info "Baue OpenSC $OPENSC_VERSION aus dem offiziellen Quellarchiv ..."
        if ! curl -fL --retry 2 --connect-timeout 15 \
            "https://github.com/OpenSC/OpenSC/releases/download/$OPENSC_VERSION/opensc-$OPENSC_VERSION.tar.gz" \
            -o "$archive"; then
            rm -rf "$temp_dir"
            error "OpenSC-Quellarchiv konnte nicht geladen werden."
            return 1
        fi
        if ! echo "$OPENSC_SHA256  $archive" | sha256sum -c -; then
            rm -rf "$temp_dir"
            error "SHA-256-Prüfung des OpenSC-Quellarchivs fehlgeschlagen."
            return 1
        fi
        if ! tar -xzf "$archive" -C "$temp_dir"; then
            rm -rf "$temp_dir"
            error "OpenSC-Quellarchiv konnte nicht entpackt werden."
            return 1
        fi
        source_dir="$temp_dir/opensc-$OPENSC_VERSION"
        if [[ ! -x "$source_dir/configure" ]]; then
            rm -rf "$temp_dir"
            error "OpenSC-Quellarchiv enthält kein configure-Skript."
            return 1
        fi
        (
            cd "$source_dir"
            ./configure --prefix="$OPENSC_PREFIX" --sysconfdir="/etc/opensc-$OPENSC_VERSION" --disable-strict
            make -j"$(nproc)"
            make install
        ) || {
            rm -rf "$temp_dir"
            error "OpenSC $OPENSC_VERSION konnte nicht gebaut werden."
            return 1
        }
        rm -rf "$temp_dir"
    fi

    # Den projektspezifischen Build dokumentieren. Das ist eine lokale
    # Installationsnotiz, keine apt-Paketdatei und keine Paket-Pinning-Regel.
    install -d -m 755 "$OPENSC_PREFIX/share/pipdfbox"
    cat > "$OPENSC_PREFIX/share/pipdfbox/opensc-build-info.txt" <<EOF
PiPDFBox OpenSC build
Version: $OPENSC_VERSION
Prefix: $OPENSC_PREFIX
Source: https://github.com/OpenSC/OpenSC/releases/download/$OPENSC_VERSION/opensc-$OPENSC_VERSION.tar.gz
SHA-256: $OPENSC_SHA256
PKCS#11 module: $PKCS11_MODULE
PKCS#11 tool: $PKCS11_TOOL
EOF

    # Nur die Bibliothek aus dem festen Prefix beim dynamischen Linker anmelden.
    # Debian-OpenSC unter /usr bleibt davon unabhängig.
    printf '%s\n' "$OPENSC_PREFIX/lib" > "/etc/ld.so.conf.d/opensc-${OPENSC_VERSION}.conf"
    ldconfig
    [[ -x "$OPENSC_PREFIX/bin/pkcs11-tool" && -f "$OPENSC_PREFIX/lib/opensc-pkcs11.so" ]] || {
        error "OpenSC-Werkzeuge oder PKCS#11-Modul fehlen nach der Installation."
        return 1
    }
}

install_python_signing_dependencies() {
    install -d -m 755 "$PYTHON_LIB_DIR"
    info "Installiere pyHanko 0.38.2 und benötigte Python-Abhängigkeiten ..."
    python3 -m pip install \
        --target "$PYTHON_LIB_DIR" \
        --upgrade \
        --ignore-installed \
        --no-warn-script-location \
        --disable-pip-version-check \
        "pyHanko==0.38.2" \
        "cryptography>=48.0.0" || {
            error "pyHanko konnte nicht installiert werden."
            return 1
        }
    # Die mit --target installierten Pakete müssen für PHP-FPM (www-data)
    # lesbar und Verzeichnisse durchsuchbar sein.
    chmod -R a+rX "$PYTHON_LIB_DIR"
    python3 -c "import sys; sys.path.insert(0, '$PYTHON_LIB_DIR'); import pyhanko, cryptography" || {
        error "pyHanko oder cryptography kann nicht importiert werden."
        return 1
    }
}

install_pcsc_polkit_rule() {
    local polkit_group
    polkit_group="$(getent group polkitd | cut -d: -f1)"
    if [[ -z "$polkit_group" ]]; then
        error "Gruppe polkitd fehlt; PC/SC-Regel für www-data kann nicht eingerichtet werden."
        return 1
    fi

    install -d -m 755 "$(dirname "$POLKIT_RULE")"
    cat > "$POLKIT_RULE" <<'POLKIT'
polkit.addRule(function(action, subject) {
    if (
        (action.id == "org.debian.pcsc-lite.access_pcsc" ||
         action.id == "org.debian.pcsc-lite.access_card") &&
        subject.user == "www-data"
    ) {
        return polkit.Result.YES;
    }
});
POLKIT
    chown root:"$polkit_group" "$POLKIT_RULE"
    chmod 640 "$POLKIT_RULE"
}

install_signing_dependencies() {
    install_opensc || return 1
    install_python_signing_dependencies || return 1
    install_pcsc_polkit_rule || return 1
    if systemctl cat pcscd.socket >/dev/null 2>&1; then
        systemctl enable --now pcscd.socket
    else
        systemctl enable --now pcscd
    fi
    info "Signier-Abhängigkeiten wurden installiert."
}

port_in_use_by_other_service() {
    local wanted="$1"
    local current="${2:-}"
    [[ "$wanted" == "$current" ]] && return 1
    ss -H -ltn | awk '{print $4}' | grep -Eq "(:|\\])${wanted}$"
}

get_available_port() {
    local current="${1:-}" value
    while true; do
        if ! IFS= read -r -p "Gewünschter Port (1024–65535): " value; then
            return 1
        fi
        if [[ ! "$value" =~ ^[0-9]+$ ]] || (( value < 1024 || value > 65535 )); then
            error "Ungültiger Port. Bitte einen Wert zwischen 1024 und 65535 eingeben."
            continue
        fi
        if port_in_use_by_other_service "$value" "$current"; then
            error "Port $value ist bereits belegt. Bitte einen anderen Port eingeben."
            continue
        fi
        printf '%s' "$value"
        return 0
    done
}

backup_file() {
    local file="$1"
    local backup_path

    if [[ -f "$file" ]]; then
        backup_path="$file.backup.$(date +%Y%m%d-%H%M%S)"
        if ! cp -a "$file" "$backup_path"; then
            error "Sicherung konnte nicht erstellt werden: $backup_path"
            return 1
        fi
        info "Sicherung erstellt: $backup_path"
    fi

    return 0
}

write_nginx_config() {
    local port="$1"
    local target="$2"
    cat > "$target" <<EOF
server {
    listen $port default_server;
    listen [::]:$port default_server;
    server_name _;
    root $PUBLIC_DIR;
    index index.php;

    client_max_body_size 900M;
    server_tokens off;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 900s;
    }

    location ~* \.(?:py|ini|log|sh)$ {
        deny all;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
}

apply_nginx_config() {
    local port="$1"
    local temp_file="" old_link_target="" had_link=0
    local backup="" had_site=0

    if ! temp_file="$(mktemp "${NGINX_SITE}.tmp.XXXXXX")"; then
        error "Temporäre Nginx-Konfiguration konnte nicht angelegt werden."
        return 1
    fi
    if ! write_nginx_config "$port" "$temp_file"; then
        error "Neue Nginx-Konfiguration konnte nicht geschrieben werden."
        rm -f "$temp_file"
        return 1
    fi

    if [[ -L "$NGINX_ENABLED" ]]; then
        had_link=1
        if ! old_link_target="$(readlink "$NGINX_ENABLED")"; then
            error "Bisheriges Nginx-Ziel konnte nicht gelesen werden."
            rm -f "$temp_file"
            return 1
        fi
    elif [[ -e "$NGINX_ENABLED" ]]; then
        error "$NGINX_ENABLED existiert und ist kein Symlink. Bitte erst manuell prüfen."
        rm -f "$temp_file"
        return 1
    fi

    if [[ -f "$NGINX_SITE" ]]; then
        had_site=1
        backup="${NGINX_SITE}.rollback.$$"
        if ! cp -a "$NGINX_SITE" "$backup"; then
            error "Sicherung der bisherigen Nginx-Konfiguration fehlgeschlagen."
            rm -f "$temp_file" "$backup"
            return 1
        fi
        if ! backup_file "$NGINX_SITE"; then
            error "Zusätzliche Sicherung der Nginx-Konfiguration fehlgeschlagen."
            rm -f "$temp_file" "$backup"
            return 1
        fi
    fi

    rollback_nginx_config() {
        local reason="$1"
        local restore_ok=1
        error "$reason – vorherige Nginx-Konfiguration wird wiederhergestellt."

        if (( had_site )); then
            if ! cp -a "$backup" "$NGINX_SITE"; then
                error "KRITISCH: Vorherige Nginx-Konfiguration konnte nicht wiederhergestellt werden: $backup"
                restore_ok=0
            fi
        else
            if ! rm -f "$NGINX_SITE"; then
                error "KRITISCH: Neue Nginx-Konfiguration konnte nicht entfernt werden."
                restore_ok=0
            fi
        fi

        if (( had_link )); then
            if ! ln -sfn "$old_link_target" "$NGINX_ENABLED"; then
                error "KRITISCH: Vorheriger Nginx-Symlink konnte nicht wiederhergestellt werden."
                restore_ok=0
            fi
        else
            if ! rm -f "$NGINX_ENABLED"; then
                error "KRITISCH: Neuer Nginx-Symlink konnte nicht entfernt werden."
                restore_ok=0
            fi
        fi

        rm -f "$temp_file"
        if (( restore_ok )); then
            if nginx -t; then
                if systemctl reload nginx; then
                    rm -f "$backup"
                else
                    error "KRITISCH: Nginx konnte nach dem Rollback nicht neu geladen werden."
                    restore_ok=0
                fi
            else
                error "KRITISCH: Auch die wiederhergestellte Nginx-Konfiguration ist ungültig."
                restore_ok=0
            fi
        fi

        if (( ! restore_ok && had_site )); then
            error "Die Rollback-Sicherung bleibt zur manuellen Wiederherstellung erhalten: $backup"
        fi
        return 1
    }

    if ! install -m 644 "$temp_file" "$NGINX_SITE"; then
        rollback_nginx_config "Installation der neuen Nginx-Konfiguration fehlgeschlagen"
        return 1
    fi
    if ! ln -sfn "$NGINX_SITE" "$NGINX_ENABLED"; then
        rollback_nginx_config "Aktivieren der Nginx-Site fehlgeschlagen"
        return 1
    fi
    if ! nginx -t; then
        rollback_nginx_config "Nginx-Konfigurationstest fehlgeschlagen"
        return 1
    fi
    if ! systemctl reload nginx; then
        rollback_nginx_config "Nginx-Reload fehlgeschlagen"
        return 1
    fi

    rm -f "$temp_file" "$backup"
}

install_app() {
    # Office-Konvertierung ist eine Voraussetzung der Neuinstallation.
    install_libreoffice || return 1
    check_dependencies || return 1
    download_app_from_github || return 1
    check_app || return 1
    install_signing_dependencies || return 1
    install_sealium_key_support || return 1
    systemctl is-active --quiet "$PHP_FPM_SERVICE" || {
        error "$PHP_FPM_SERVICE ist nicht aktiv."
        return 1
    }

    echo
    info "PiPDFBox – Einrichtung"
    local old_port="" port password password2 hash answer config_tmp
    [[ -f "$PORT_FILE" ]] && old_port="$(cat "$PORT_FILE")"

    port="$(get_available_port "$old_port")" || return 1

    read -r -s -p "Neues Zugangspasswort: " password
    echo
    read -r -s -p "Passwort wiederholen: " password2
    echo
    if [[ -z "$password" || "$password" != "$password2" ]]; then
        unset password password2
        error "Passwörter sind leer oder stimmen nicht überein."
        return 1
    fi
    hash="$(printf '%s' "$password" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"
    unset password password2
    [[ -n "$hash" ]] || { error "Passwort-Hash konnte nicht erzeugt werden."; return 1; }

    echo
    read -r -p "Vorhandene PDF-Aufträge unter $DATA_DIR löschen? (ja/NEIN): " answer
    if [[ "$answer" == "ja" ]]; then
        rm -rf -- "$DATA_DIR"
    fi

    install -d -o www-data -g www-data -m 700 "$DATA_DIR" "$DATA_DIR/jobs" "$DATA_DIR/certificates"
    install_cleanup_timer
    install -d -o root -g www-data -m 750 "$CONFIG_DIR"

    cat > "$PHP_INI_FILE" <<'PHPINI'
upload_max_filesize = 400M
max_file_uploads = 110
post_max_size = 900M
max_execution_time = 900
max_input_time = 240
memory_limit = 256M
PHPINI

    config_tmp="$(mktemp "${CONFIG_FILE}.tmp.XXXXXX")"
    cat > "$config_tmp" <<EOF
<?php
return [
    'password_hash' => '$hash',
    'data_dir' => '$DATA_DIR',
    'parallel_gs' => true,
];
EOF
    chown root:www-data "$config_tmp"
    chmod 640 "$config_tmp"
    backup_file "$CONFIG_FILE"
    install -m 640 -o root -g www-data "$config_tmp" "$CONFIG_FILE"
    rm -f "$config_tmp"

    apply_nginx_config "$port" || {
        error "Nginx wurde nicht umgestellt. Bitte Konfiguration prüfen."
        return 1
    }

    printf '%s\n' "$port" > "$PORT_FILE"
    chown root:www-data "$PORT_FILE"
    chmod 640 "$PORT_FILE"
    systemctl restart "$PHP_FPM_SERVICE"

    # Launcher erst nach erfolgreicher vollständiger Einrichtung bereitstellen.
    install_launcher

    info "PiPDFBox wurde eingerichtet."
    echo "Aufruf: http://<IP-Adresse-des-Raspberry-Pi>:$port"
}

change_port() {
    local old_port new_port
    [[ -f "$PORT_FILE" ]] || { error "Keine gespeicherte Portnummer. Bitte zuerst einrichten."; return 1; }
    old_port="$(cat "$PORT_FILE")"
    echo "Aktueller Port: $old_port"
    new_port="$(get_available_port "$old_port")" || return 1
    [[ "$new_port" == "$old_port" ]] && { info "Dieser Port ist bereits eingestellt."; return 0; }

    apply_nginx_config "$new_port" || return 1
    printf '%s\n' "$new_port" > "$PORT_FILE"
    chown root:www-data "$PORT_FILE"
    chmod 640 "$PORT_FILE"
    info "Port wurde geändert."
    echo "Neue Adresse: http://<IP-Adresse-des-Raspberry-Pi>:$new_port"
}

check_installation() {
    echo
    info "PiPDFBox – Installationsprüfung"
    local failed=0 file
    check_dependencies || failed=1
    if [[ -x "$PKCS11_TOOL" && -f "$PKCS11_MODULE" ]]; then
        echo "[OK] OpenSC $OPENSC_VERSION"
    else
        echo "[FEHLT] OpenSC $OPENSC_VERSION"
        failed=1
    fi
    if python3 -c "import sys; sys.path.insert(0, '$PYTHON_LIB_DIR'); import pyhanko, cryptography" 2>/dev/null; then
        echo "[OK] pyHanko und cryptography"
    else
        echo "[FEHLT] pyHanko oder cryptography"
        failed=1
    fi
    for file in "$PUBLIC_DIR/index.php" "$APP_DIR/worker.py" "$APP_DIR/opensc.py" "$CONFIG_FILE" "$NGINX_SITE" "$PORT_FILE"; do
        if [[ -f "$file" ]]; then
            echo "[OK] $file"
        else
            echo "[FEHLT] $file"
            failed=1
        fi
    done
    echo "Installierte Version: $(cat "$APP_DIR/VERSION" 2>/dev/null || echo unbekannt)"
    [[ ! -f "$PORT_FILE" ]] || echo "Port: $(cat "$PORT_FILE")"
    if systemctl is-active --quiet nginx; then echo "[OK] nginx aktiv"; else echo "[FEHLT] nginx nicht aktiv"; failed=1; fi
    if systemctl is-active --quiet pcscd.socket || systemctl is-active --quiet pcscd; then
        echo "[OK] PC/SC-Dienst aktiv"
    else
        echo "[FEHLT] PC/SC-Dienst nicht aktiv"
        failed=1
    fi
    # Nur die Polkit-Regel prüfen. pkcs11-tool --list-slots kann am
    # Kartenleser eine CAN-Eingabe auslösen und gehört nicht in den
    # nicht-interaktiven Installationscheck.
    if [[ -f "$POLKIT_RULE" ]] &&
        grep -qF 'action.id == "org.debian.pcsc-lite.access_pcsc"' "$POLKIT_RULE" &&
        grep -qF 'action.id == "org.debian.pcsc-lite.access_card"' "$POLKIT_RULE" &&
        grep -qF 'subject.user == "www-data"' "$POLKIT_RULE"; then
        echo "[OK] PC/SC-Polkit-Regel für www-data vorhanden"
    else
        echo "[FEHLT] PC/SC-Polkit-Regel für www-data"
        failed=1
    fi
    if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then echo "[OK] $PHP_FPM_SERVICE aktiv"; else echo "[FEHLT] $PHP_FPM_SERVICE nicht aktiv"; failed=1; fi
    if nginx -t; then echo "[OK] Nginx-Konfiguration"; else failed=1; fi
    return "$failed"
}


install_sealium_key_support() {
    install -o root -g root -m 750 "$APP_DIR/sealium-key-helper.sh" /usr/local/sbin/pipdfbox-set-sealium-key
    cat > /etc/sudoers.d/pipdfbox-sealium <<'SUDOERS'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/pipdfbox-set-sealium-key ""
SUDOERS
    chmod 440 /etc/sudoers.d/pipdfbox-sealium
    visudo -cf /etc/sudoers.d/pipdfbox-sealium >/dev/null
    install -d -o root -g www-data -m 750 "$CONFIG_DIR"
}

install_cleanup_timer() {
    cat > /etc/systemd/system/pipdfbox-cleanup.service <<'EOF'
[Unit]
Description=PiPDFBox – alte PDF-Aufträge bereinigen

[Service]
Type=oneshot
User=www-data
Group=www-data
ExecStart=/usr/bin/python3 /opt/pipdfbox/cleanup.py
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/lib/pipdfbox
EOF
    cat > /etc/systemd/system/pipdfbox-cleanup.timer <<'EOF'
[Unit]
Description=PiPDFBox – stündliche Bereinigung alter PDF-Aufträge

[Timer]
OnBootSec=10min
OnUnitActiveSec=1h
Persistent=true
Unit=pipdfbox-cleanup.service

[Install]
WantedBy=timers.target
EOF
    chmod 644 /etc/systemd/system/pipdfbox-cleanup.service /etc/systemd/system/pipdfbox-cleanup.timer
    systemctl daemon-reload
    systemctl enable --now pipdfbox-cleanup.timer
}

install_launcher() {
    install -d -m 755 /usr/local/bin
    ln -sfn "$APP_DIR/install.sh" /usr/local/bin/pipdfbox
}

update_app() {
    echo
    info "PiPDFBox – Anwendung aktualisieren"
    # Zuerst den aktuellen Installer und Anwendungscode laden. Die danach
    # ausgeführte Paketprüfung verwendet so die aktuelle Abhängigkeitsliste.
    download_app_from_github || return 1
    # Office-Konvertierung separat installieren, damit ein Fehler bei einem
    # anderen optionalen Paket die LibreOffice-Installation nicht verhindert.
    install_libreoffice || return 1
    check_dependencies || return 1
    phpenmod -v 8.4 zip || { error "PHP-ZIP konnte nicht aktiviert werden."; return 1; }
    systemctl restart php8.4-fpm || { error "PHP-FPM konnte nicht neu gestartet werden."; return 1; }
    check_app || return 1
    install_signing_dependencies || return 1
    install -d -o www-data -g www-data -m 700 "$DATA_DIR/certificates"
    install_sealium_key_support || return 1
    install_launcher
    install_cleanup_timer
    info "Anwendungscode aktualisiert. Einstellungen und PDF-Aufträge bleiben erhalten."
}

uninstall_app() {
    local choice answer
    echo
    info "PiPDFBox – Deinstallation"
    echo "1) Nur Anwendung entfernen (Einstellungen und PDF-Aufträge behalten)"
    echo "2) Vollständig entfernen (alle PiPDFBox-Dateien und Einstellungen löschen)"
    echo "0) Abbrechen"
    read -r -p "Auswahl: " choice
    case "$choice" in
        1)
            read -r -p "Anwendung wirklich entfernen? (ja/NEIN): " answer
            [[ "$answer" == "ja" ]] || { info "Abgebrochen."; return 0; }
            ;;
        2)
            echo
            echo "Gelöscht werden Anwendung, Konfiguration, Sealium-Schlüssel,"
            echo "TSA-Einstellungen, D-TRUST-/BaltStamp-Zertifikate, PDF-Aufträge"
            echo "und der PiPDFBox-eigene OpenSC-Build."
            echo "Gemeinsam genutzte Systempakete und Dienste bleiben erhalten."
            read -r -p "Vollständige Deinstallation bestätigen (ALLES): " answer
            [[ "$answer" == "ALLES" ]] || { info "Abgebrochen."; return 0; }
            ;;
        0) info "Abgebrochen."; return 0 ;;
        *) error "Ungültige Auswahl."; return 1 ;;
    esac

    if command -v nginx >/dev/null 2>&1; then
        nginx -t || { error "Nginx-Test fehlgeschlagen. Abbruch."; return 1; }
    fi

    systemctl disable --now pipdfbox-cleanup.timer 2>/dev/null || true
    systemctl stop pipdfbox-cleanup.service 2>/dev/null || true
    rm -f /etc/systemd/system/pipdfbox-cleanup.timer
    rm -f /etc/systemd/system/pipdfbox-cleanup.service
    systemctl daemon-reload
    systemctl reset-failed pipdfbox-cleanup.service 2>/dev/null || true

    # Ausschließlich die von PiPDFBox angelegte Nginx-Site und deren
    # zeitgestempelte Sicherungs-/Rollback-Dateien entfernen.
    rm -f "$NGINX_ENABLED" "$NGINX_SITE"
    if [[ -d /etc/nginx/sites-available ]]; then
        find /etc/nginx/sites-available -maxdepth 1 -type f \
            \( -name 'pipdfbox.backup.*' -o -name 'pipdfbox.rollback.*' -o -name 'pipdfbox.tmp.*' \) \
            -delete
    fi
    if command -v nginx >/dev/null 2>&1; then
        systemctl reload nginx || {
            error "Nginx konnte nach Entfernen der PiPDFBox-Site nicht neu geladen werden."
            return 1
        }
    fi

    rm -f /usr/local/bin/pipdfbox
    rm -f /usr/local/sbin/pipdfbox-set-sealium-key
    rm -f /etc/sudoers.d/pipdfbox-sealium
    rm -f "$POLKIT_RULE"

    if [[ -f "$PHP_INI_FILE" ]]; then
        rm -f "$PHP_INI_FILE"
        if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
            systemctl restart "$PHP_FPM_SERVICE" || {
                error "PHP-FPM konnte nach Entfernen der PiPDFBox-Konfiguration nicht neu gestartet werden."
                return 1
            }
        fi
    fi

    # Nur temporäre Dateien mit eindeutigem PiPDFBox-Präfix entfernen.
    find /tmp -mindepth 1 -maxdepth 1 \
        \( -name 'pipdfbox-github.*' -o -name 'pipdfbox-opensc.*' \
        -o -name 'pipdfbox-opensc-*' -o -name 'pipdfbox-cert-*' \
        -o -name 'pipdfbox-sealium-*' -o -name 'pipdfbox-worker-*' \) \
        -exec rm -rf -- {} +

    rm -rf -- "$APP_DIR"

    if [[ "$choice" == "2" ]]; then
        # Zugangsdaten, API-Schlüssel, TSA-/Dateinamen-Konfiguration,
        # CA-Zertifikate, Aufträge und Worker-Lock.
        rm -rf -- "$CONFIG_DIR" "$DATA_DIR"

        # Ausschließlich der eigene OpenSC-Build, nicht Debian-OpenSC.
        rm -rf -- "$OPENSC_PREFIX" "/etc/opensc-$OPENSC_VERSION"
        rm -f -- "/etc/ld.so.conf.d/opensc-${OPENSC_VERSION}.conf"
        ldconfig

        info "PiPDFBox vollständig entfernt: Programm, Konfiguration, Schlüssel, Zertifikate, Aufträge und eigener OpenSC-Build."
    else
        info "Anwendung und Systemintegration entfernt. Konfiguration, Schlüssel, Zertifikate und PDF-Aufträge bleiben erhalten."
    fi

    info "Gemeinsam genutzte Systempakete und Dienste (Nginx, PHP, Python, PC/SC, Debian-OpenSC, LibreOffice) bleiben installiert."
}

main() {
    require_root
    require_supported_os || exit 1
    while true; do
        echo
        echo "================================"
        echo "   PiPDFBox Version $VERSION"
        echo "================================"
        echo "Quelle: GitHub (pennynet/PiPDFBox, main)"
        echo "1) Neuinstallation / Einrichtung"
        echo "2) Port ändern"
        echo "3) Installation prüfen"
        echo "4) Anwendung aktualisieren"
        echo "5) PiPDFBox deinstallieren"
        echo "0) Beenden"
        echo
        local choice
        read -r -p "Auswahl: " choice
        case "$choice" in
            1) install_app ;;
            2) change_port ;;
            3) check_installation ;;
            4) update_app ;;
            5) uninstall_app ;;
            0) exit 0 ;;
            *) error "Ungültige Auswahl." ;;
        esac
        echo
        pause
    done
}

main "$@"
