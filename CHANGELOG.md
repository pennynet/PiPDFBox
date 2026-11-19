# Changelog

Alle wesentlichen Änderungen an PiPDFBox werden hier dokumentiert.

## [1.0.0] – 2026-11-17

Erste stabile Version.

### Funktionen
- PDFs zusammenführen, aufteilen und verschachteln
- PDFs mit individuellem Vorspann nummerieren
- PDF-Komprimierung mit Profilwahl und optional parallelen Ghostscript-Läufen
- PDF/A-Erstellung in den Varianten PDF/A-1b, PDF/A-2b und PDF/A-3b
- OCR/Texterkennung für Deutsch, Englisch oder Deutsch + Englisch
- Weboberfläche mit Datei-Upload und Ergebnis-Download
- Installations-, Aktualisierungs-, Prüf- und Deinstallationsmenü

### Sicherheit und Betrieb
- CSRF-Schutz für Anmeldung und PDF-Aktionen
- Serverseitige Prüfung der Uploads und Begrenzung von Dateianzahl und Dateigröße
- Globaler exklusiver Worker-Lock verhindert parallele PDF-Worker
- Automatische Löschung kompletter Auftragsverzeichnisse nach mehr als 24 Stunden
- systemd-Timer für die regelmäßige Bereinigung
- Deinstallation entfernt den Bereinigungs-Timer und seine systemd-Units

### Dokumentation und Qualität
- Installations- und Wiederherstellungsanleitung
- Raspberry-Pi-5-Leistungsnotizen und Benchmark-Hilfsprogramm
- Lokaler Web-Smoke-Test
