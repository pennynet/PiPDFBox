#!/usr/bin/env bash
set -Eeuo pipefail

# Static UI contract checks for the signature profile controls.
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
INDEX="$ROOT_DIR/public/index.php"
APP_JS="$ROOT_DIR/public/assets/app.js"
DOWNLOAD="$ROOT_DIR/public/download.php"
OPENSC="$ROOT_DIR/opensc.py"

grep -q 'id="visible_stamp"' "$INDEX"
grep -q 'id="sign_profile_pades" checked' "$INDEX"
grep -q 'id="sign_profile_cades"' "$INDEX"
grep -q 'pades.addEventListener("change"' "$INDEX"
grep -q 'cades.addEventListener("change"' "$INDEX"
grep -q 'profile.value = cades.checked ? "cades" : "pades"' "$INDEX"
grep -q 'subfilter=SigSeedSubFilter.PADES' "$OPENSC"
grep -q 'embed_validation_info=True' "$OPENSC"
grep -q 'use_pades_lta=True' "$OPENSC"
grep -q 'embed_roots=True' "$OPENSC"
grep -q 'use_cades=True' "$OPENSC"
grep -q 'application/pkcs7-signature' "$DOWNLOAD"
grep -q 'const isCades' "$APP_JS"

echo "Signaturprofil-UI und statische Backend-Konfiguration: OK"
echo "Hinweis: Karten-/TSA-/Zertifikatsketten-Tests benötigen reale Hardware und Netzwerkzugriff."
