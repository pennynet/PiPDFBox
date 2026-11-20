#!/usr/bin/env bash
set -Eeuo pipefail
# Eng begrenzter Root-Helper: nimmt ausschließlich den API-Schlüssel über stdin an.
if [[ $# -ne 0 || ${EUID} -ne 0 ]]; then
    exit 2
fi
IFS= read -r key || true
if [[ ! "$key" =~ ^[A-Za-z0-9._~-]{20,512}$ ]]; then
    unset key
    exit 3
fi
install -d -o root -g www-data -m 750 /etc/pipdfbox
tmp="$(mktemp /etc/pipdfbox/.sealium-key.XXXXXX)"
trap 'rm -f "$tmp"; unset key' EXIT
printf '%s' "$key" > "$tmp"
unset key
chown root:www-data "$tmp"
chmod 640 "$tmp"
mv -f "$tmp" /etc/pipdfbox/sealium.key
trap - EXIT
