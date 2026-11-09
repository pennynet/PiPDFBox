# PiPDFBox

**Version 0.1**

PiPDFBox ist eine Webanwendung für den Raspberry Pi zur Verarbeitung von PDF-Dateien.

## Funktionen

- PDF-Dateien über den Browser hochladen
- PDF-Aufträge serverseitig verarbeiten
- Verarbeitung über die Weboberfläche verfolgen
- Ergebnisse herunterladen
- Automatische Prüfung und Installation benötigter Abhängigkeiten
- Webserver-Port ändern
- Anwendung aktualisieren
- Installation prüfen
- Anwendung deinstallieren

## Voraussetzungen

- Raspberry Pi mit Debian
- Netzwerkverbindung
- Benutzer mit sudo-Rechten

## Installation

Repository herunterladen:

    git clone https://github.com/pennynet/PiPDFBox.git
    cd PiPDFBox

Installation starten:

    sudo bash install.sh

## Verwaltung

Nach der Installation:

    pipdfbox

Das Verwaltungsmenü bietet folgende Funktionen:

1. Installation
2. Port ändern
3. Installation prüfen
4. Anwendung aktualisieren
5. Anwendung deinstallieren
0. Beenden

## Upload-Einstellungen

Vorgesehene PHP-Standardwerte:

| Parameter | Wert |
|---|---:|
| upload_max_filesize | 400M |
| post_max_size | 900M |
| max_execution_time | 900 |
| max_input_time | 240 |
| memory_limit | 256M |

## Aktualisierung

Die Anwendung kann über das Verwaltungsmenü aktualisiert werden.

## Deinstallation

Die Deinstallation erfolgt über das Verwaltungsmenü. Dabei kann gewählt werden, ob nur die Anwendung oder zusätzlich Konfiguration und PDF-Aufträge entfernt werden.

## Projekt

GitHub: https://github.com/pennynet/PiPDFBox

## Version

0.1 – Erste Entwicklungs- und Testversion.
