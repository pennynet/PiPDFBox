# PiPDFBox – Installation und Wiederherstellung

Diese Anleitung beschreibt die Neuinstallation von PiPDFBox auf Debian GNU/Linux 13 (Trixie).

Die Programmdateien werden aus dem privaten GitHub-Repository geladen. Temporäre PDF-Dateien und Auftragsdaten werden nicht gesichert.

## 1. Voraussetzungen

- Debian 13 (Trixie)
- Python 3.13
- PHP 8.4 mit PHP-FPM
- Nginx
- Ghostscript
- qpdf
- Poppler

## 2. Systempakete installieren

    sudo apt update
    sudo apt install -y nginx php8.4-fpm php8.4-cli php8.4-common php8.4-mbstring php8.4-xml php8.4-zip ghostscript qpdf poppler-utils python3 python3-pypdf python3-pil git

## 3. GitHub-Repository laden

Das Repository ist privat. Für den Zugriff ist eine GitHub-Anmeldung mit einem geeigneten Personal Access Token oder SSH-Zugang erforderlich.

    sudo git clone https://github.com/pennynet/PiPDFBox.git /opt/pipdfbox

Das Token niemals in die URL schreiben oder in GitHub speichern.

## 4. Datenverzeichnis einrichten

    sudo install -d -o www-data -g www-data -m 700 /var/lib/pipdfbox
    sudo install -d -o www-data -g www-data -m 700 /var/lib/pipdfbox/jobs

Das Verzeichnis enthält nur temporäre Aufträge und PDFs. Es wird bei einer Neuinstallation leer angelegt.

## 5. Nginx konfigurieren

Datei `/etc/nginx/sites-available/pipdfbox` anlegen:

    server {
        listen 8181 default_server;
        listen [::]:8181 default_server;
        server_name _;
        root /opt/pipdfbox/public;
        index index.php;
        client_max_body_size 900M;
        server_tokens off;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
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

Site aktivieren und prüfen:

    sudo ln -s /etc/nginx/sites-available/pipdfbox /etc/nginx/sites-enabled/pipdfbox
    sudo nginx -t
    sudo systemctl reload nginx

Falls bereits eine Datei oder ein Link mit diesem Namen existiert, zuerst prüfen und nicht überschreiben.

## 6. PHP-FPM konfigurieren

Datei `/etc/php/8.4/fpm/conf.d/99-pipdfbox.ini` anlegen:

    upload_max_filesize=400M
    post_max_size=900M
    max_execution_time=900
    max_input_time=240
    memory_limit=256M

In `/etc/php/8.4/fpm/pool.d/www.conf` müssen diese Werte gelten:

    user = www-data
    group = www-data
    listen = /run/php/php8.4-fpm.sock
    request_terminate_timeout = 900

PHP-FPM neu starten:

    sudo systemctl restart php8.4-fpm

## 7. Dienste aktivieren

    sudo systemctl enable nginx
    sudo systemctl enable php8.4-fpm
    sudo systemctl restart nginx
    sudo systemctl restart php8.4-fpm

## 8. Installation prüfen

Python-Bibliotheken:

    python3 -m pip show pypdf Pillow

Programme:

    command -v python3
    command -v gs
    command -v qpdf
    command -v pdftoppm

PHP-FPM-Werte:

    sudo php-fpm8.4 -i | grep -E '^(max_execution_time|memory_limit|upload_max_filesize|post_max_size|max_input_time)'

Nginx-Konfiguration:

    sudo nginx -t

Danach im Browser öffnen:

    http://<IP-Adresse-des-Raspberry-Pi>:8181

Alle fünf Funktionen mit Test-PDFs prüfen: Zusammenführen, Teilen, Interleave, Komprimieren und Nummerieren.

## 9. Spätere Aktualisierung

Im bestehenden Installationsverzeichnis:

    cd /opt/pipdfbox
    sudo git pull

## 10. Wichtige Hinweise

- GitHub enthält Programmcode und diese Anleitung.
- Temporäre PDF-Dateien und Auftragsdaten unter `/var/lib/pipdfbox` werden nicht gesichert.
- Systemkonfigurationen unter `/etc` sind nicht Bestandteil des GitHub-Repositories.
- Zugangstoken, Passwörter und private PDFs gehören niemals ins Repository.
- Vor Änderungen an Systemkonfigurationen die betroffenen Dateien separat sichern.
