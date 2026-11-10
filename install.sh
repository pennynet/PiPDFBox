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
    if [[ -z "$extracted" || ! -f "$extracted/public/index.php" || ! -f "$extracted/worker.py" ]]; then
        rm -rf "$temp_dir"
        error "Das GitHub-Archiv enthält nicht die erwarteten PiPDFBox-Dateien."
        return 1
    fi

    # Bestehendes Verzeichnis nicht löschen: nur den Anwendungscode aktualisieren.
    # install.sh bleibt erhalten, ebenso .git und lokale Zusatzdateien.
    cp -a "$extracted/." "$APP_DIR/"
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
    nginx
    php8.4-cli
    php8.4-fpm
    php8.4-mbstring
    ghostscript
    qpdf
    poppler-utils
    python3
    python3-pypdf
    python3-pil
    iproute2
)

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
    php -m | grep -qi '^session$' || { error "PHP-Erweiterung session fehlt."; return 1; }
    php -m | grep -qi '^json$' || { error "PHP-Erweiterung json fehlt."; return 1; }
    php -m | grep -qi '^mbstring$' || { error "PHP-Erweiterung mbstring fehlt."; return 1; }
    python3 -c 'import PIL; import pypdf' 2>/dev/null || {
        error "Python-Module Pillow oder pypdf fehlen."
        return 1
    }
    info "Abhängigkeitsprüfung erfolgreich."
}

check_app() {
    [[ -f "$PUBLIC_DIR/index.php" && -f "$APP_DIR/worker.py" ]] || {
        error "PiPDFBox-Dateien fehlen unter $APP_DIR."
        return 1
    }
}

get_port() {
    local value
    read -r -p "Gewünschter Port (1024–65535): " value
    [[ "$value" =~ ^[0-9]+$ ]] && (( value >= 1024 && value <= 65535 )) || {
        error "Ungültiger Port."
        return 1
    }
    printf '%s' "$value"
}

port_in_use_by_other_service() {
    local wanted="$1"
    local current="${2:-}"
    [[ "$wanted" == "$current" ]] && return 1
    ss -H -ltn | awk '{print $4}' | grep -Eq "(:|\\])${wanted}$"
}

backup_file() {
    local file="$1"
    if [[ -f "$file" ]]; then
        cp -a "$file" "$file.backup.$(date +%Y%m%d-%H%M%S)"
        info "Sicherung erstellt: $file"
    fi
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
    local temp_file old_link_target="" had_link=0 backup=""
    temp_file="$(mktemp "${NGINX_SITE}.tmp.XXXXXX")"
    write_nginx_config "$port" "$temp_file"

    if [[ -L "$NGINX_ENABLED" ]]; then
        had_link=1
        old_link_target="$(readlink "$NGINX_ENABLED")"
    elif [[ -e "$NGINX_ENABLED" ]]; then
        error "$NGINX_ENABLED existiert und ist kein Symlink. Bitte erst manuell prüfen."
        rm -f "$temp_file"
        return 1
    fi

    backup_file "$NGINX_SITE"
    if [[ -f "$NGINX_SITE" ]]; then
        backup="${NGINX_SITE}.rollback.$$"
        cp -a "$NGINX_SITE" "$backup"
    fi

    install -m 644 "$temp_file" "$NGINX_SITE"
    rm -f "$temp_file"
    ln -sfn "$NGINX_SITE" "$NGINX_ENABLED"

    if ! nginx -t; then
        error "Nginx-Test fehlgeschlagen – vorherige Konfiguration wird wiederhergestellt."
        if [[ -n "$backup" && -f "$backup" ]]; then
            cp -a "$backup" "$NGINX_SITE"
            rm -f "$backup"
        else
            rm -f "$NGINX_SITE"
        fi
        if (( had_link )); then
            ln -sfn "$old_link_target" "$NGINX_ENABLED"
        else
            rm -f "$NGINX_ENABLED"
        fi
        nginx -t || true
        return 1
    fi

    [[ -n "$backup" ]] && rm -f "$backup"
    systemctl reload nginx
}

install_app() {
    check_dependencies || return 1
    download_app_from_github || return 1
    check_app || return 1
    systemctl is-active --quiet "$PHP_FPM_SERVICE" || {
        error "$PHP_FPM_SERVICE ist nicht aktiv."
        return 1
    }

    echo
    info "PiPDFBox – Einrichtung"
    local old_port="" port password password2 hash answer config_tmp
    [[ -f "$PORT_FILE" ]] && old_port="$(cat "$PORT_FILE")"

    port="$(get_port)" || return 1
    if port_in_use_by_other_service "$port" "$old_port"; then
        error "Port $port wird bereits von einem anderen Dienst verwendet."
        return 1
    fi

    read -r -s -p "Neues Zugangspasswort: " password
    echo
    read -r -s -p "Passwort wiederholen: " password2
    echo
    if [[ -z "$password" || "$password" != "$password2" ]]; then
        unset password password2
        error "Passwörter sind leer oder stimmen nicht überein."
        return 1
    fi
    hash="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$password")"
    unset password password2
    [[ -n "$hash" ]] || { error "Passwort-Hash konnte nicht erzeugt werden."; return 1; }

    echo
    read -r -p "Vorhandene PDF-Aufträge unter $DATA_DIR löschen? (ja/NEIN): " answer
    if [[ "$answer" == "ja" ]]; then
        rm -rf -- "$DATA_DIR"
    fi

    install -d -o www-data -g www-data -m 700 "$DATA_DIR" "$DATA_DIR/jobs"
    install -d -o root -g www-data -m 750 "$CONFIG_DIR"

    cat > "$PHP_INI_FILE" <<'PHPINI'
upload_max_filesize = 400M
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

    info "PiPDFBox wurde eingerichtet."
    echo "Aufruf: http://<IP-Adresse-des-Raspberry-Pi>:$port"
}

change_port() {
    local old_port new_port
    [[ -f "$PORT_FILE" ]] || { error "Keine gespeicherte Portnummer. Bitte zuerst einrichten."; return 1; }
    old_port="$(cat "$PORT_FILE")"
    echo "Aktueller Port: $old_port"
    new_port="$(get_port)" || return 1
    [[ "$new_port" == "$old_port" ]] && { info "Dieser Port ist bereits eingestellt."; return 0; }

    if port_in_use_by_other_service "$new_port" "$old_port"; then
        error "Port $new_port wird bereits von einem anderen Dienst verwendet."
        return 1
    fi

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
    for file in "$PUBLIC_DIR/index.php" "$APP_DIR/worker.py" "$CONFIG_FILE" "$NGINX_SITE" "$PORT_FILE"; do
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
    if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then echo "[OK] $PHP_FPM_SERVICE aktiv"; else echo "[FEHLT] $PHP_FPM_SERVICE nicht aktiv"; failed=1; fi
    if nginx -t; then echo "[OK] Nginx-Konfiguration"; else failed=1; fi
    return "$failed"
}


install_launcher() {
    install -d -m 755 /usr/local/bin
    ln -sfn "$APP_DIR/install.sh" /usr/local/bin/pipdfbox
}

update_app() {
    echo
    info "PiPDFBox – Anwendung aktualisieren"
    check_dependencies || return 1
    download_app_from_github || return 1
    check_app || return 1
    install_launcher
    info "Anwendungscode aktualisiert. Einstellungen und PDF-Aufträge bleiben erhalten."
}

uninstall_app() {
    local choice answer
    echo
    info "PiPDFBox – Deinstallation"
    echo "1) Nur Anwendung entfernen (Einstellungen und PDF-Aufträge behalten)"
    echo "2) Alles entfernen (einschließlich Einstellungen und PDF-Aufträge)"
    echo "0) Abbrechen"
    read -r -p "Auswahl: " choice
    case "$choice" in
        1)
            read -r -p "Anwendung wirklich entfernen? (ja/NEIN): " answer
            [[ "$answer" == "ja" ]] || { info "Abgebrochen."; return 0; }
            ;;
        2)
            read -r -p "Alle Einstellungen und PDF-Aufträge unwiderruflich löschen? (ALLES): " answer
            [[ "$answer" == "ALLES" ]] || { info "Abgebrochen."; return 0; }
            ;;
        0) info "Abgebrochen."; return 0 ;;
        *) error "Ungültige Auswahl."; return 1 ;;
    esac

    rm -f "$NGINX_ENABLED" "$NGINX_SITE"
    if command -v nginx >/dev/null 2>&1; then
        nginx -t || { error "Nginx-Test fehlgeschlagen. Abbruch."; return 1; }
        systemctl reload nginx
    fi
    rm -f /usr/local/bin/pipdfbox
    rm -rf "$APP_DIR"
    if [[ "$choice" == "2" ]]; then
        rm -rf "$CONFIG_DIR" "$DATA_DIR"
        info "PiPDFBox einschließlich Konfiguration und PDF-Aufträgen entfernt."
    else
        info "Anwendung entfernt. Konfiguration und PDF-Aufträge bleiben erhalten."
    fi
}

main() {
    require_root
    install_launcher
    while true; do
        echo
        echo "================================"
        echo "             PiPDFBox Version $VERSION"
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
