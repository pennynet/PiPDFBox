# Entwicklungsübergabe – PDF-Signatur in PiPDFBox

Stand: 2026-11-18

Dieses Dokument ist der verbindliche Wiedereinstiegspunkt für die nächste Arbeitssitzung. Es hält den bestätigten Stand, offene Fragen und die nächsten Schritte fest. Beobachtungen des Nutzers, GitHub-Status und Schlussfolgerungen sind voneinander zu unterscheiden.

## 1. Architekturentscheidung

**Es wird vorerst kein separates Repository `PiPDFBox-PDFSigning` angelegt.** Ein solches Projekt hat bisher nicht existiert.

Die PDF-Signaturfunktion wird als Feature in `pennynet/PiPDFBox` entwickelt. `pennynet/PiPDFBox-CardManager` bleibt ein eigenständiger Dienst für Smartcard-/Leser-Erkennung und kryptografische Kartenoperationen.

Zuständigkeiten:

- **PiPDFBox:** Weboberfläche und Benutzerworkflow, PDF-Verarbeitung, PAdES-Erstellung/-Einbettung mit pyHanko, PDF-Signaturvalidierung und Ergebnisdarstellung.
- **CardManager:** PC/SC und Leser-/Kartenerkennung, PKCS#11, Zertifikats-/Public-Key-Metadaten sowie später autorisierte Signaturoperationen über die Karte.
- **Grenze:** PiPDFBox/PHP-FPM erhält weder private Schlüssel noch PIN/CAN. Kein generischer PKCS#11-Passthrough. Die geschützte PIN-/CAN-Eingabe bleibt beim unterstützten Leser-/Middleware-Pfad.

Diese Aufteilung folgt der bestehenden Architektur-Dokumentation des CardManager-Repositories. Die konkrete API-Integration ist noch nicht implementiert und muss anhand des aktuellen CardManager-API-Vertrags abgestimmt werden.

## 2. GitHub-Stand

### PiPDFBox

- Repository: https://github.com/pennynet/PiPDFBox
- Default-Branch: `main`
- Ausgangs-Commit von `main` beim Erstellen dieser Übergabe: `effcfc4e917ff0ab799029b0ddeeca6011fa5005`
- README-Version: 1.0.0
- In der README ist die PDF-Signatur-Kachel ausdrücklich als „In Planung“ markiert.
- Die aktuelle Main-Version enthält noch keine PDF-Signatur-/PAdES-Implementierung in Worker oder Backend.

Für diese Übergabe wurde der Dokumentationsbranch angelegt:

- `docs/pdf-signing-development-checkpoint`
- Er basiert auf `main`.
- Dieses Dokument liegt unter `docs/DEVELOPMENT-CHECKPOINT-SIGNING.md`.

Der Branch ist bewusst noch nicht in `main` gemergt. Die nächste Sitzung kann daraus einen Draft-PR erstellen oder den Branch als Dokumentationsbasis verwenden.

### PiPDFBox-CardManager

- Repository: https://github.com/pennynet/PiPDFBox-CardManager
- PR #2 wurde bereits gemergt: Referenzdokumentation D-TRUST/cyberJack.
- PR #3: `feature/dtrust-pkcs11-discovery` – PKCS#11-Token-Erkennung.
- PR #4: https://github.com/pennynet/PiPDFBox-CardManager/pull/4 – Draft, Branch `feature/dtrust-signing-certificate-discovery`, basiert auf PR-#3-Branch.
- PR #4 implementiert read-only Zertifikatserkennung und enthält die CAN/PACE-Klarstellung. Nicht ungeprüft mergen.
- Der aktuelle CardManager-Entwicklungscheckpoint liegt in diesem Branch: https://github.com/pennynet/PiPDFBox-CardManager/blob/feature/dtrust-signing-certificate-discovery/docs/DEVELOPMENT-CHECKPOINT.md

Der CardManager-Checkpoint ist für Details zu Tests, Hardwarebeobachtungen und PKCS#11-Implementierung maßgeblich.

## 3. Bestätigte Pi-Hardware und Software

Auf dem Raspberry Pi (ARM64, Raspberry Pi OS / Debian Trixie):

- Leser: REINER SCT cyberJack RFID standard, USB-ID `0c4b:0500`.
- Karte: D-TRUST Card 5.1 Standard.
- OpenSC-PKCS#11-Modul: `/usr/lib/aarch64-linux-gnu/opensc-pkcs11.so`.
- Installierte Komponenten laut bisheriger Sitzung: `pcscd 2.3.3-1`, `libpcsclite 2.3.3-1`, `libccid 1.6.2-1`, `libifd-cyberjack6 3.99.5final.sp17-0.1`, `opensc 0.27.1-3`.
- `opensc-tool -l` erkannte Leser/Karte.
- `pkcs11-tool --list-slots` zeigte Signature-PIN- und Authentication-PIN-Token.
- Die Karte stellt einen geschützten Authentisierungspfad bereit.

Keine Seriennummern, PKCS#11-URIs, Zertifikatsfingerprints, Schlüsselmaterial oder PIN/CAN-Werte in diese Übergabe aufnehmen.

## 4. CAN/PACE – verbindliche Produktentscheidung

CAN/PACE ist bei D-TRUST Card 5.1 ein legitimer und erwartbarer Teil der Kartenverwendung. CAN-freie Zertifikatserkennung ist **keine** Produktanforderung.

- Wenn OpenSC/Karte PACE benötigt, soll CardManager diesen Schritt ausdrücklich und verständlich als CAN-Eingabe am geschützten cyberJack-Leser ankündigen.
- CAN nur über den geschützten Leser-/Tastaturpfad eingeben.
- CAN niemals über Webformular, normale Texteingabe, Shell, Kommandozeilenargument, Umgebungsvariable oder Logs verarbeiten.
- MVP: CAN nicht persistent speichern.
- Eine optionale, explizit aktivierte sichere Speicherung für eine spätere Einmal-Eingabe bleibt als mögliche zukünftige Erweiterung offen; dafür ist ein eigenes Sicherheitsdesign erforderlich.
- CAN/PACE ist von der Signature-PIN zu unterscheiden. Die Signature-PIN autorisiert die Signatur; CAN ist kein Ersatz dafür.
- Keine Umgehung des geschützten Pfads, kein Abschalten der PIN-Pad-Sicherheit und kein Software-Fallback zur CAN-Eingabe.

## 5. CardManager – derzeitiger Implementierungsstand

PR #4 ergänzt `Pkcs11Module.list_signing_certificates()`:

- Auswahl genau eines D-TRUST-Tokens mit Label `(Signature-PIN)`.
- Read-only Session (`rw=False`), kein PIN-Login.
- Nur Zertifikatsobjekte werden gelesen; X.509 wird geparst.
- Rückgabe begrenzter öffentlicher Metadaten, u. a. SHA-256-Fingerprint, Issuer-CN, Gültigkeitszeitraum und Public-Key-Algorithmus/-Größe.
- Keine Subject-Namen oder DER-Rohdaten in der Rückgabe.
- Kein Zugriff auf Authentication-PIN-Token, keine Private-Key-Enumeration, kein Login, keine Signatur.
- Unit-Tests mit generierten Zertifikaten und Fake-PKCS#11-Objekten.
- Nutzer meldete auf dem Pi zuletzt **34 Tests bestanden**.

Wichtige Einschränkung: Reale Zertifikatserkennung wurde einmal erfolgreich auf der Karte getestet. Wiederholte Aufrufe können jedoch einen CAN/PACE-Dialog auslösen. Einige Slot-/Mechanismus-/Public-Key-Abfragen waren ohne CAN prompt möglich; Python `get_tokens()`/`slot.get_token()` lösten in den beobachteten Tests den geschützten Dialog aus. Die genaue PKCS#11-Aufrufstelle ist noch nicht abschließend isoliert. Nicht behaupten, Zertifikatserkennung sei immer CAN-frei.

Vor Signaturintegration müssen PR #3/#4 reviewed und die CardManager-Schnittstelle bzw. API-Vertrag abgestimmt werden.

## 6. Frühere pyHanko-Umgebung auf dem Pi – Wiederherstellungsbefund

Der Nutzer suchte nach einem früheren PDFSigning-Projekt und fand zwei Verzeichnisse:

- `/home/pi/tmp/pdfsign`
- `/home/pi/tmp/sda1/opt/pdfsign`

Beide Verzeichnisse sind in der untersuchten Struktur identisch. Beide enthalten nur:

- `certificates/dtrust-root.pem`
- `config/dtrust.yml`
- leeres `scripts/`
- `venv/` mit installierten Bibliotheken und Startskripten.

Beide Verzeichnisse sind **keine Git-Repositories**. In den untersuchten Bereichen wurden keine eigenen Python-Quellen, `pyproject.toml`, `requirements*.txt`, systemd-Service-Dateien oder PDFSigning-Archive gefunden. `/opt/pdfsign` war zum Zeitpunkt der Prüfung nicht vorhanden.

Die alten venv-Startskripte enthalten den Shebang `#!/opt/pdfsign/venv/bin/python3`; die kopierte Umgebung ist deshalb nicht direkt lauffähig. Die Python-Symlinks zeigen auf `/usr/bin/python3`. Die Umgebung nicht mit chmod/chown oder durch manuelles Ändern alter Interpreterpfade reparieren.

Aus den verbliebenen Metadaten konnten folgende Versionen ermittelt werden:

- Python 3.13.5
- pyHanko 0.38.1
- pyHanko CLI 0.6.0
- pyHanko CertValidator 0.33.0
- python-pkcs11 0.9.5
- cryptography 50.0.2
- pyHanko verlangt Python >=3.11 und cryptography >=48.0.0.
- PKCS#11 ist als pyHanko-Extra vorgesehen (`python-pkcs11~=0.9.3`).

Das sind **rekonstruierte Umgebungsinformationen, kein wiederhergestellter Anwendungscode**. pyHanko selbst bringt CLI-Funktionen für Signatur und Validierung mit; das belegt nicht, dass ein eigener Signaturdienst existierte.

Die Konfigurationsdatei und das Root-Zertifikat wurden nicht in GitHub übernommen. Sie können lokale oder sicherheitsrelevante Daten enthalten. Niemals die alte venv, Zertifikatsdateien oder lokale D-TRUST-Konfiguration committen. Benötigte Trust Anchors müssen später aus einer nachvollziehbaren, offiziellen Quelle bezogen und mit dokumentierter Herkunft/Fingerprint verwaltet werden.

## 7. Was noch nicht umgesetzt oder bewiesen ist

- Keine PDF-Signaturfunktion im aktuellen PiPDFBox-main.
- Kein produktiver CardManager-Signatur-Endpunkt.
- Kein Ende-zu-Ende-Test, der mit der aktuellen Software über die reale D-TRUST-Karte ein PDF signiert.
- Die früher berichtete erfolgreiche Signatur/Validierung auf einer separaten archivierten Installation ist Nutzerangabe, aber der damalige Anwendungscode wurde in den untersuchten Verzeichnissen nicht gefunden.
- Zertifikatserkennung beweist weder Vertrauenskette noch Sperrstatus, PAdES-Konformität oder QES.
- QES darf nicht allein aus Kartenmodell, Zertifikat oder erfolgreicher kryptografischer Signatur abgeleitet werden.

## 8. Nächste Arbeitsschritte

Empfohlene Reihenfolge für die nächste Sitzung:

1. Diesen Checkpoint und den CardManager-Checkpoint lesen.
2. GitHub-Status von PR #3 und PR #4 prüfen; offene Änderungen reviewen, nicht ungeprüft mergen.
3. Aktuellen Pi-Arbeitsstand prüfen, bevor Befehle ausgeführt werden:
   - `cd /opt/pipdfbox && git status --short --branch`
   - `git branch --show-current`
   - `git log -5 --oneline --decorate`
4. CardManager-API-Vertrag und den tatsächlichen Integrationsweg festlegen (Unix Socket/Service, Berechtigungen, Request-/Response-Schema, User-Presence und Leser-Prompt).
5. In PiPDFBox einen Feature-Branch für PDF-Signierung auf Basis des dann aktuellen `main` erstellen. Nicht direkt auf `main` arbeiten.
6. pyHanko als reproduzierbare Abhängigkeit definieren; zunächst nur PDF-Signaturvalidierung und isolierte PDF-Verarbeitung testen.
7. Danach Signaturworkflow mit CardManager-Schnittstelle entwerfen. Signatur-PIN ausschließlich über geschützten Leserpfad.
8. Erst nach Unit-, Integrations- und realen Hardwaretests Draft-PR erstellen. Kein Merge ohne ausdrückliche Freigabe.

## 9. Arbeitsregeln für die Fortsetzung

- Schrittweise vorgehen und nach Pi-Befehlen die Ausgabe abwarten.
- Vor jeder Änderung Status/Branch prüfen.
- Ausschließlich Feature-Branches und Draft-PRs; keine direkten Änderungen an `main`.
- Nichts löschen, verschieben oder überschreiben ohne vorherige Freigabe.
- Änderungen und Ergebnisse zeitnah in GitHub dokumentieren.
- Keine CAN/PIN-, Schlüssel-, Seriennummern- oder persönlichen Zertifikatsdaten in Chat, Shell-Logs oder GitHub ablegen.
- Hardwaretests nur mit realem Leser/Karte und geschütztem PIN-Pad-Pfad; keine Sicherheitsumgehungen.

## 10. Starttext für einen neuen Chat

„Bitte lies zuerst `docs/DEVELOPMENT-CHECKPOINT-SIGNING.md` in `pennynet/PiPDFBox` auf Branch `docs/pdf-signing-development-checkpoint` sowie den CardManager-Checkpoint auf Branch `feature/dtrust-signing-certificate-discovery`. Wir machen mit der Integration der PDF-Signaturfunktion in PiPDFBox weiter – kein separates PDFSigning-Repository. Bitte prüfe zuerst den aktuellen GitHub-/Pi-Stand und führe mich anschließend schrittweise weiter. Keine Änderungen an main und keine Merge-Aktion ohne meine ausdrückliche Zustimmung.“
