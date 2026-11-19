# PiPDFBox – Installation und Wiederherstellung

Diese Anleitung ergänzt den Installer für Debian GNU/Linux 13 (Trixie). Der unterstützte Standardweg ist die Installation über `install.sh`; die manuelle Einrichtung einzelner Systemdienste ist normalerweise nicht erforderlich.

## Voraussetzungen

- Raspberry Pi mit Debian 13 (Trixie)
- sudo-Rechte
- Netzwerkzugriff auf GitHub und die Debian-Paketquellen
- Berechtigter Zugriff auf das PiPDFBox-Repository (derzeit privat)

Der Installer installiert bzw. prüft die benötigten Pakete, darunter Nginx, PHP 8.4 FPM, Ghostscript, qpdf, Poppler, Python 3, pypdf, Pillow, OCRmyPDF und Tesseract-Sprachdaten für Deutsch und Englisch.

## Neuinstallation

```bash
git clone https://github.com/pennynet/PiPDFBox.git
cd PiPDFBox
sudo bash install.sh
```

Das Repository ist privat. Verwende einen berechtigten GitHub-Zugang über SSH oder eine sichere Git-Credential-Verwaltung. Zugangstoken niemals in die URL schreiben, in Dateien ablegen oder committen.

Im Menü **1) Neuinstallation / Einrichtung** wählen und den Anweisungen folgen. Der Installer fragt den gewünschten Port und ein Zugangspasswort ab.

Anschließend im Browser öffnen:

```
http://<IP-Adresse-des-Raspberry-Pi>:<gewählter-Port>
```

## Verzeichnisse

| Pfad | Zweck |
|---|---|
| `/opt/pipdfbox` | Anwendungscode |
| `/etc/pipdfbox` | Zugangskonfiguration und Port |
| `/var/lib/pipdfbox/jobs` | Uploads, Ergebnisse und temporäre Auftragsdateien |
| `/etc/systemd/system/pipdfbox-cleanup.service` | Dienst für die automatische Bereinigung |
| `/etc/systemd/system/pipdfbox-cleanup.timer` | Stündlicher Bereinigungs-Timer |

Die systemd-Unit-Dateien werden vom Installer erzeugt und gehören nicht ins Git-Repository.

## Aktualisierung

`sudo pipdfbox` starten und Menüpunkt **4) Anwendung aktualisieren** wählen. Die Aktualisierung lädt den aktuellen Programmstand von GitHub und richtet den Bereinigungs-Timer erneut ein. Konfiguration und vorhandene Aufträge bleiben erhalten.

Alternativ kann der Installer direkt gestartet werden:

```bash
sudo bash /opt/pipdfbox/install.sh
```

## Installation prüfen

Im Verwaltungsmenü **3) Installation prüfen** auswählen. Zusätzlich kann der Bereinigungs-Timer kontrolliert werden:

```bash
systemctl status pipdfbox-cleanup.timer
systemctl list-timers pipdfbox-cleanup.timer
```

## Automatische Bereinigung

Ein systemd-Timer startet zehn Minuten nach dem Booten und danach stündlich die Bereinigung. Auftragsverzeichnisse mit einem Alter von mehr als 24 Stunden werden entfernt. Wenn gerade ein PDF-Worker läuft, überspringt das Bereinigungsskript den Durchlauf und versucht es beim nächsten Timer-Lauf erneut.

## Deinstallation

Im Verwaltungsmenü **5) PiPDFBox deinstallieren** auswählen.

- **Nur Anwendung entfernen:** Programmdateien, Nginx-Site, Launcher, PiPDFBox-spezifische PHP-FPM-Konfiguration und Bereinigungs-Timer werden entfernt. Konfiguration und PDF-Aufträge bleiben erhalten.
- **Alles entfernen:** Zusätzlich werden `/etc/pipdfbox` und `/var/lib/pipdfbox` gelöscht.

Allgemeine Systempakete (z. B. Nginx, PHP, Python, Ghostscript) werden bewusst nicht automatisch deinstalliert.

## Wiederherstellung

Für eine Wiederherstellung zunächst PiPDFBox neu installieren. Falls Konfiguration oder Auftragsdaten gesichert wurden, diese nur bei gestoppter Anwendung und mit passenden Dateirechten zurückspielen.

Programmcode und Dokumentation liegen in GitHub. Die lokale Konfiguration unter `/etc` und Auftragsdaten unter `/var/lib` sind nicht Bestandteil des Repositorys und müssen separat gesichert werden, wenn sie erhalten bleiben sollen.

## Sicherheitshinweise

- Keine Passwörter, Tokens, privaten PDFs oder lokalen Konfigurationsdateien committen.
- Die Anwendung ist für ein vertrauenswürdiges lokales Netzwerk vorgesehen.
- PiPDFBox nicht ohne zusätzliche Absicherung direkt aus dem Internet erreichbar machen.
- Vor manuellen Änderungen an Dateien unter `/etc` Sicherungskopien anlegen.
