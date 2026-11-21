# PiPDFBox

**Version 1.0.0** · Lokale PDF-Werkstatt für Raspberry Pi

PiPDFBox ist eine selbst gehostete Webanwendung zur Verarbeitung von PDF-Dateien im lokalen Netzwerk. Die Verarbeitung erfolgt auf dem Raspberry Pi; die Dateien werden nicht an einen externen PDF-Dienst übertragen.

## Funktionen

| Funktion | Beschreibung |
|---|---|
| Zusammenführen | Mehrere PDFs zu einer PDF verbinden |
| Aufteilen | Eine PDF in einzelne Seiten zerlegen und als ZIP bereitstellen |
| Verschachteln | Zwei PDFs abwechselnd kombinieren, z. B. Vorder- und Rückseiten; optional fortlaufend oben zentriert nummerieren (`- 1 -`, `- 2 -`, …) |
| Nummerieren | Seitennummern mit optionalem Vorspann hinzufügen |
| Komprimieren | PDF-Dateigröße mit wählbarem Profil reduzieren |
| Archivieren | PDF/A-1b, PDF/A-2b oder PDF/A-3b erzeugen |
| Texterkennung | Scans mit OCR durchsuchbar machen (Deutsch, Englisch oder beide) |
| PDF signieren | PAdES-Signatur direkt im PDF (optional mit B-LTA-Langzeitvalidierung) oder separate CAdES-Signaturdatei (.p7s) mit D-TRUST-Signaturkarte; optional sichtbarer Stempel bei PAdES; RFC-3161-Zeitstempel bei B-LTA und CAdES |

Die Signierung benötigt einen kompatiblen Kartenleser und eine D-TRUST-Signaturkarte. Die Signatur-PIN wird ausschließlich am geschützten PIN-Pad des Kartenlesers eingegeben.

## Voraussetzungen

- Raspberry Pi (getestet auf Raspberry Pi 5 mit 8 GB RAM)
- Debian GNU/Linux 13 (Trixie)
- Netzwerkverbindung und sudo-Rechte
- Zugriff auf das GitHub-Repository

Der Installer prüft und installiert die benötigten Systempakete. Dazu gehören unter anderem Nginx, PHP 8.4 FPM, Python 3, Ghostscript, qpdf, Poppler, OCRmyPDF und Tesseract mit deutschen und englischen Sprachdaten. Zusätzlich baut er OpenSC 0.27.1 aus dem offiziellen Quellarchiv und installiert pyHanko 0.38.2 samt Python-Abhängigkeiten in einer anwendungseigenen Paketablage unter /opt/pipdfbox/python. Es wird keine virtuelle Python-Umgebung verwendet.

## Installation

Repository laden und Installer starten:

```bash
git clone https://github.com/pennynet/PiPDFBox.git
cd PiPDFBox
sudo bash install.sh
```

Das Repository ist öffentlich. Für das Klonen und den Download ist keine GitHub-Anmeldung erforderlich.

Der Installer führt durch die Einrichtung, fragt einen Webserver-Port und ein Zugangspasswort ab und richtet die Anwendung ein. Anschließend ist PiPDFBox unter folgender Adresse erreichbar:

```
http://<IP-Adresse-des-Raspberry-Pi>:<Port>
```

Der Standardport ist nicht fest vorgegeben; der Installer fragt ihn bei der Einrichtung ab.

## Verwaltung

Nach der Installation kann das Verwaltungsmenü mit folgendem Befehl geöffnet werden:

```bash
sudo pipdfbox
```

Menüpunkte:

1. Neuinstallation / Einrichtung
2. Port ändern
3. Installation prüfen
4. Anwendung aktualisieren
5. PiPDFBox deinstallieren
0. Beenden

Für eine Aktualisierung Menüpunkt **4) Anwendung aktualisieren** verwenden. Konfiguration und vorhandene PDF-Aufträge bleiben dabei erhalten.

## Upload- und Verarbeitungsgrenzen

- Maximal 100 Dateien pro Auftrag
- Maximal 400 MB je Datei
- Maximal 800 MB Gesamtgröße je Auftrag
- PDF-Verarbeitung wird global serialisiert: Es läuft höchstens ein PDF-Worker gleichzeitig.
- Bei der Komprimierung kann die optionale parallele Ghostscript-Verarbeitung verwendet werden.

## Datenspeicherung und automatische Bereinigung

- Programmdateien: `/opt/pipdfbox`
- Konfiguration: `/etc/pipdfbox`
- Auftragsdateien und Ergebnisse: `/var/lib/pipdfbox/jobs`

Auftragsverzeichnisse einschließlich hochgeladener Originale, Ergebnisse und temporärer Dateien werden automatisch gelöscht, sobald sie älter als 24 Stunden sind. Ein systemd-Timer prüft stündlich; bei laufender PDF-Verarbeitung wird die Bereinigung verschoben.

Die Anwendung ist für den Betrieb im vertrauenswürdigen lokalen Netzwerk vorgesehen. Für einen Zugriff aus dem Internet sollte sie nicht ungeschützt veröffentlicht werden; dafür wären zusätzliche Schutzmaßnahmen wie TLS und eine geeignete Zugriffskontrolle erforderlich.

## Deinstallation

Im Verwaltungsmenü **5) PiPDFBox deinstallieren** auswählen. Es gibt zwei Varianten:

- **Nur Anwendung entfernen:** Programmdateien, Webserver-Konfiguration und PiPDFBox-spezifische PHP-FPM-Einstellungen sowie der Bereinigungs-Timer werden entfernt. Konfiguration und PDF-Aufträge bleiben erhalten.
- **Alles entfernen:** Zusätzlich werden Konfiguration, PDF-Aufträge und der eigens installierte OpenSC-Build unter `/opt/opensc-0.27.1` entfernt.

Die Deinstallation entfernt keine allgemeinen Systempakete wie Nginx, PHP, Ghostscript, Python oder pcscd, da diese auch von anderen Anwendungen verwendet werden könnten.

## Dokumentation und Tests

- [PiPDFBox-Handbuch (PDF)](public/handbuch.pdf)

- [Installations- und Wiederherstellungsdetails](INSTALLATION.md)
- [Änderungshistorie](CHANGELOG.md)
- [Pi-5-Leistungsnotizen und Benchmark](docs/performance-pi5.md)
- [Web-Smoke-Test](tests/web-smoke.sh)

Lokaler Web-Smoke-Test (benötigt PHP CLI, Python 3, curl und das Python-Modul pypdf):

```bash
bash tests/web-smoke.sh
```

Worker-Benchmarks mit eigenen, nicht vertraulichen Test-PDFs:

```bash
python3 tests/benchmark_worker.py compress /pfad/zur/test.pdf --profile stark --repeat 3
```

## Versionierung

PiPDFBox verwendet Semantic Versioning. Die erste stabile Version ist **1.0.0**.

- GitHub: https://github.com/pennynet/PiPDFBox
- Release-Tag: `v1.0.0`
