<?php
declare(strict_types=1);

/*
 * Das Handbuch wird hier direkt als PDF erzeugt. Damit ist keine separat
 * übertragene Binärdatei erforderlich, die beim Update beschädigt werden kann.
 */

$pages = [
    [
        'PiPDFBox – Handbuch',
        'Bedienung, Funktionen, Abläufe und Administration',
        '',
        'Stand: November 2026 | Projekt: pennynet/PiPDFBox',
        '',
        'PiPDFBox ist eine lokal betriebene Webanwendung für Raspberry Pi 5 und',
        'Debian 13. Sie verarbeitet PDF-Dateien auf dem eigenen Raspberry Pi.',
        'Office-Dokumente können vor der Verarbeitung lokal in PDF umgewandelt werden.',
        '',
        'Dieses Handbuch beschreibt die derzeit implementierten Funktionen und',
        'die Abläufe der Weboberfläche. Einzelne Optionen hängen von installierter',
        'Software, angeschlossener Hardware und der jeweiligen PDF-Datei ab.',
    ],
    [
        'Beschreibung und Zweck',
        'PiPDFBox ist eine lokal betriebene PDF-Werkstatt. Die Weboberfläche nimmt',
        'Dateien entgegen; die Verarbeitung erfolgt auf dem Raspberry Pi. Uploads,',
        'Verarbeitung und Ergebnisse verbleiben damit im eigenen Netzwerk und unter',
        'der Kontrolle des Betreibers. Es wird kein externer PDF-Konvertierungsdienst',
        'für die eigentlichen Dokumentaufgaben benötigt.',
        '',
        'Die Anwendung verbindet eine PHP-Weboberfläche mit einem separaten',
        'Python-Worker. Nginx nimmt Browseranfragen entgegen und leitet PHP-Aufrufe',
        'an PHP-FPM weiter. Der Worker übernimmt rechenintensive PDF-Aufgaben.',
        'Ein globaler Worker-Lock verhindert parallele PDF-Verarbeitungen.',
        '',
        'PiPDFBox unterstützt Zusammenführen, Aufteilen, Verschachteln, Paginieren,',
        'Komprimieren, PDF/A-Erstellung, Texterkennung, Office-Konvertierung und',
        'digitale Signaturen. Ergebnisse können angezeigt, heruntergeladen und –',
        'soweit sinnvoll – erneut als Eingabe verwendet werden.',
        '',
        'Die Signaturfunktionen benötigen einen kompatiblen Kartenleser und eine',
        'geeignete D-TRUST-Signaturkarte. Der private Schlüssel verbleibt auf der',
        'Karte. Die PIN wird über den geschützten PIN-Eingabepfad des Kartenlesers',
        'eingegeben. Für bestimmte Signaturprofile wird ein RFC-3161-Zeitstempeldienst',
        'angesprochen.',
        '',
        'Dieses Dokument besteht aus der Bedienungsanleitung und einer technischen',
        'Anlage für Installation, Administration und Fehleranalyse. Die konkrete',
        'Ausstattung kann sich durch System- und Sicherheitsupdates verändern.',
    ],
    [
        '1. Anmeldung und Grundprinzip',
        'Die Weboberfläche im Browser öffnen und mit dem eingerichteten Passwort',
        'anmelden. Die Sitzung wird durch ein CSRF-Token geschützt; bei einer',
        'abgelaufenen Sitzung die Seite neu laden und erneut anmelden.',
        '',
        'Dateien hinzufügen:',
        '- PDF-Dateien sowie Word, Excel und PowerPoint auswählen oder ablegen.',
        '- Unterstützte Office-Endungen: DOC, DOCX, XLS, XLSX, PPT und PPTX.',
        '- Office-Dateien werden mit LibreOffice im Hintergrund in PDF umgewandelt.',
        '- Die fertigen PDFs erscheinen anschließend wie normale Eingabedateien.',
        '- Maximal 100 Dateien je Auftrag, 400 MB je Datei und 800 MB insgesamt.',
        '',
        'Die Dateikacheln zeigen die ausgewählten Dateien. Anklicken öffnet eine',
        'Vorschau. Dateien lassen sich einzeln oder gesammelt entfernen und per',
        'Maus durch Ziehen neu sortieren. Die Reihenfolge ist für die Verarbeitung',
        'maßgeblich. Das Ergebnis erscheint ebenfalls als Kachel.',
        '',
        'Die Anwendung akzeptiert nur Dateien mit PDF-Kennung und passendem',
        'Dateityp. Passwortgeschützte PDFs werden vom Verarbeitungs-Worker nicht',
        'unterstützt. Je nach Funktion gelten zusätzliche Seiten- und Dateizahlen.',
    ],
    [
        '2. PDF-Aufgaben – Zusammenführen und Aufteilen',
        'PDFs zusammenführen:',
        'Mindestens zwei PDFs auswählen. Die Kachelreihenfolge bestimmt die',
        'Reihenfolge im zusammengeführten Dokument. „Reihung umkehren“ kehrt die',
        'Auswahlreihenfolge um. Ergebnis: eine PDF-Datei.',
        '',
        'PDF aufteilen:',
        'Genau eine PDF auswählen. Jede Seite wird in eine eigene PDF-Datei',
        'geschrieben; diese Einzelseiten werden als ZIP-Datei bereitgestellt.',
        'Die Funktion ist auf höchstens 500 Seiten begrenzt.',
        '',
        'PDFs verschachteln (Vorder-/Rückseiten):',
        'Genau zwei PDFs auswählen. Die Seiten werden abwechselnd übernommen:',
        'zuerst Seite 1 der ersten PDF, dann Seite 1 der zweiten PDF, danach Seite 2',
        'der ersten usw. Ist eine Datei länger, werden ihre übrigen Seiten angefügt.',
        '„Rückseiten-PDF umkehren“ kehrt nur die Seitenfolge der zweiten PDF um.',
        'Ergebnis: eine PDF-Datei.',
    ],
    [
        '3. Paginieren, Komprimieren und Archivieren',
        'PDF paginieren:',
        'Genau eine PDF auswählen. Optional einen Vorspann eingeben, zum Beispiel',
        '„Vertraulich“. Auf jeder Seite wird oben eine Nummer im Format',
        '„Vorspann Seitenzahl/Gesamtseiten“ eingefügt. Der Vorspann darf höchstens',
        '150 Zeichen lang sein. Unterstützt werden PDFs mit 1 bis 500 Seiten.',
        '',
        'PDF komprimieren:',
        'Genau eine PDF auswählen und eine Stufe wählen:',
        '- Maximal: stärkste Reduktion, niedrigere Bildauflösung.',
        '- Stark: mittlere Einstellung und Standardauswahl.',
        '- Mittel: höhere Bildauflösung, tendenziell größere Ausgabedatei.',
        'Die Anwendung erstellt optimierte Kandidaten und übernimmt die kleinste',
        'geeignete Datei. Eine Graustufenversion wird nur berücksichtigt, wenn sie',
        'mindestens 20 Prozent kleiner als die Farbversion ist.',
        '„Parallelverarbeitung mit Ghostscript“ startet zwei Ghostscript-Läufe',
        'parallel. Das kann mehr Arbeitsspeicher benötigen und ist optional.',
        '',
        'PDF archivieren (PDF/A):',
        'Genau eine PDF auswählen und PDF/A-1b, PDF/A-2b oder PDF/A-3b wählen.',
        'Die Umwandlung erfolgt über OCRmyPDF mit übersprungenen Textseiten.',
        'Eine erfolgreiche Konvertierung ersetzt keine unabhängige PDF/A-Validierung.',
    ],
    [
        '4. Texterkennung (OCR) und Office-Konvertierung',
        'PDF Texterkennung:',
        'Genau eine PDF auswählen und Deutsch, Englisch oder Deutsch + Englisch',
        'einstellen. OCRmyPDF und Tesseract erzeugen eine durchsuchbare Textebene.',
        'Seiten werden automatisch gedreht und schief stehende Seiten begradigt.',
        'Bereits vorhandene Textseiten werden übersprungen. Die Erkennungsqualität',
        'hängt von Scanauflösung, Kontrast, Ausrichtung und Sprache ab.',
        'Die Verarbeitung hat ein Zeitlimit von 14 Minuten.',
        '',
        'Office-Dateien:',
        'DOC/DOCX, XLS/XLSX und PPT/PPTX werden mit LibreOffice im Headless-Modus',
        'in PDF umgewandelt und danach in die Dateiauswahl aufgenommen. Die',
        'Konvertierung erfolgt einzeln nacheinander. Pro Office-Datei gelten 400 MB.',
        'Bei einem Konvertierungsfehler wird eine Fehlermeldung angezeigt; bereits',
        'erfolgreich übernommene Dateien bleiben in der Auswahl.',
    ],
    [
        '5. Signieren und Signaturprüfung',
        'PDF signieren:',
        'Eine PDF auswählen und zwischen PAdES und CAdES wählen. Beide Verfahren',
        'verwenden OpenSC, PKCS#11 und pyHanko mit dem vorgesehenen D-TRUST-',
        'Signaturzertifikat auf einer kompatiblen Smartcard. Karte und Leser müssen',
        'angeschlossen und eingerichtet sein. Die PIN-Eingabe erfolgt über den',
        'geschützten Authentisierungspfad des Kartenlesers; PiPDFBox verarbeitet',
        'die PIN nicht selbst.',
        '',
        'PAdES:',
        '- Die Signatur wird in die PDF eingebettet. Ergebnis: signiert.pdf.',
        '- B-LTA: optional werden Informationen zur Langzeitvalidierung und ein',
        '  RFC-3161-Zeitstempel des ausgewählten TSA-Dienstes eingebunden.',
        '- Sichtbaren Stempel anbringen: fügt eine zusätzliche Signaturseite mit',
        '  einem sichtbaren Siegel sowie Signaturangaben an.',
        '',
        'CAdES (getrennte Signatur):',
        '- Die Signatur wird als separate .p7s-Datei erzeugt und nicht in die PDF',
        '  eingebettet. PiPDFBox verpackt anschließend das unveränderte Original-PDF',
        '  und die .p7s-Signatur gemeinsam in eine ZIP-Datei.',
        '- Das ZIP enthält genau zwei Dateien mit gemeinsamem Dateinamensstamm:',
        '  <Name>.pdf (Original) und <Name>.p7s (detached Signatur).',
        '- Das ZIP-Ergebnis heißt <Name>.zip und kann im ZIP-Browser angesehen',
        '  oder als Ganzes heruntergeladen werden.',
        '- Für CAdES wird der RFC-3161-Zeitstempel des ausgewählten TSA-Dienstes',
        '  verwendet. Sichtbarer Stempel und PAdES-B-LTA sind für CAdES nicht aktiv.',
        '',
        'Signaturprüfung:',
        'Bei PDFs mit erkanntem Signaturbereich erscheint an der Dateikachel ein',
        'Prüfknopf. Die Prüfung kann dort gestartet werden; auch die Ausgabedatei',
        'einer Signierung besitzt eine Prüfmöglichkeit. Die Prüfung zeigt die',
        'ermittelten Signaturinformationen und den Prüfstatus an.',
        'Die automatische Erkennung des Prüfknopfs sucht nach dem PDF-Marker',
        '/ByteRange. Das ist eine strukturelle Erkennung und keine vollständige',
        'kryptografische Validierung. Ein angezeigter Prüfknopf allein bedeutet',
        'nicht, dass die Signatur gültig oder vertrauenswürdig ist.',
        '',
        'Warnung bei Weiterverarbeitung:',
        'Wird eine signierte PDF erneut verarbeitet, weist ein Dialog darauf hin:',
        '„Bei der Weiterverarbeitung wird die Signatur ungültig!“ Mit Abbrechen',
        'wird der Vorgang beendet, mit OK fortgesetzt. Das gilt für signierte PDFs',
        'unabhängig davon, ob die Signatur von PiPDFBox oder extern stammt.',
    ],
    [
        '6. Einstellungen und Dateinamen',
        'Einstellungen über den Link in der Kopfzeile öffnen.',
        '',
        'Zeitstempelserver:',
        'BalTstamp ist als Server hinterlegt. Ein eigener Server kann mit einer',
        'Bezeichnung und HTTP- oder HTTPS-URL hinzugefügt und ausgewählt werden.',
        'Die URL darf keine Zugangsdaten oder URL-Fragmente enthalten. Die Auswahl',
        'wird gespeichert und für Signaturen mit aktiviertem Zeitstempel verwendet.',
        '',
        'Sealium API:',
        'Der API-Schlüssel kann in den Sealium-Einstellungen hinterlegt werden.',
        'Ein bereits gespeicherter Schlüssel wird aus Sicherheitsgründen nicht',
        'angezeigt; ein neu eingegebener Schlüssel ersetzt den bisherigen.',
        '',
        'Dateinamen – vier Modi:',
        '1. Bisherige Dateinamen: Standardnamen der jeweiligen Funktion bleiben.',
        '2. Originalname + Aktion: zum Beispiel test-paginiert.pdf.',
        '3. Datum + Originalname: zum Beispiel 261120-test.pdf (JJMMTT).',
        '4. Datum + Prefix + Originalname: vor jeder Verarbeitung erscheint ein',
        '   Dialog zur Eingabe des Prefix, zum Beispiel 261120-Rechnung-test.pdf.',
        'Der Prefix darf höchstens 80 Zeichen lang sein. Nicht zulässige Zeichen',
        'werden für den Dateinamen bereinigt. Die Einstellung bleibt gespeichert.',
        'Bei CAdES-ZIP werden Archiv und beide Einträge aus dem gewählten',
        'Dateinamensmodus abgeleitet: <Name>.zip mit <Name>.pdf und <Name>.p7s.',
    ],
    [
        '7. Ergebnis, Meldungen und Ablaufsteuerung',
        'Nach erfolgreicher Verarbeitung wird das Ergebnis als Kachel angezeigt.',
        'Dort kann es geöffnet, heruntergeladen, gelöscht oder – bei PDF-Ergebnissen',
        '– über den Übernehmen-Pfeil wieder als Eingabedatei verwendet werden.',
        'ZIP-Ergebnisse lassen sich im integrierten ZIP-Browser ansehen.',
        'Bei CAdES enthält das ZIP genau das unveränderte Original-PDF und die',
        'zugehörige getrennte .p7s-Signatur. Beide Einträge haben denselben',
        'Dateinamensstamm; die ZIP-Datei kann gemeinsam heruntergeladen werden.',
        '',
        'Die Anwendung prüft Dateianzahl, Größe, PDF-Kennung, MIME-Typ und die',
        'für die jeweilige Aufgabe erforderliche Anzahl von Eingabedateien.',
        'Ein globaler Worker-Lock verhindert parallele PDF-Worker. Weitere Aufträge',
        'warten, bis der laufende Auftrag beendet ist.',
        'Nach dem Absenden wird per Post/Redirect/Get weitergeleitet, damit ein',
        'Browser-Refresh denselben Auftrag nicht erneut startet.',
        '',
        'Statusmeldungen:',
        '- Grüne Erfolgsmeldungen verschwinden nach 5 Sekunden.',
        '- Rote Fehlermeldungen verschwinden nach 10 Sekunden.',
        '- „Verarbeitung läuft …“ bleibt während des Auftrags sichtbar.',
        'Die Dateiauswahl und die Ergebnis-Kachel werden im Browser verwaltet.',
        'Office-Konvertierungen laufen vor dem eigentlichen PDF-Auftrag.',
    ],
    [
        '8. Installation, Aktualisierung und Wartung',
        'PiPDFBox ist für Raspberry Pi 5 mit Debian 13 vorgesehen. Der Installer',
        'richtet unter anderem Nginx, PHP-FPM, Python-Abhängigkeiten und die',
        'benötigten PDF-Werkzeuge ein. Für Office-Konvertierung wird LibreOffice',
        'benötigt; für OCR/PDF-A die OCRmyPDF-/Tesseract-Werkzeuge, für Komprimierung',
        'qpdf und Ghostscript, für Signaturen OpenSC und pyHanko.',
        '',
        'Installer-Menü:',
        'sudo pipdfbox',
        '1) Neuinstallation / Einrichtung',
        '2) Port ändern',
        '3) Installation prüfen',
        '4) Anwendung aktualisieren',
        '5) PiPDFBox deinstallieren',
        '0) Beenden',
        '',
        'Bei der Aktualisierung werden Anwendungscode und Installer aus dem',
        'GitHub-Projekt geladen, Abhängigkeiten geprüft und Dienste neu gestartet.',
        'Einstellungen und PDF-Aufträge bleiben dabei erhalten. Nach dem Update',
        'die Browserseite mit Strg+F5 neu laden.',
        '',
        'Die Deinstallation kann nur den Anwendungscode entfernen oder zusätzlich',
        'Konfiguration und gespeicherte Aufträge löschen. Die zweite Variante ist',
        'destruktiv und sollte nur nach bewusster Bestätigung gewählt werden.',
    ],
    [
        '9. Speicherung, Bereinigung und Sicherheit',
        'Auftragsdateien liegen unter /var/lib/pipdfbox/jobs. Temporäre Aufträge',
        'werden nach 24 Stunden gelöscht. Ein systemd-Timer startet die Bereinigung',
        'stündlich; beim Systemstart erfolgt der erste Lauf nach 10 Minuten.',
        'Läuft gerade ein PDF-Worker, wartet die Bereinigung bis zum nächsten Lauf.',
        '',
        'Die TSA-Konfiguration liegt in /var/lib/pipdfbox/tsa-servers.json, die',
        'Dateinamen-Einstellung in /var/lib/pipdfbox/filename-settings.json.',
        'Konfigurations- und Auftragsdaten bei Bedarf separat sichern.',
        '',
        'PiPDFBox nicht ungeschützt ins Internet stellen. Zugriff auf das lokale',
        'Netzwerk begrenzen, System und Browser aktuell halten und regelmäßige',
        'Backups der benötigten Konfigurationen vorsehen. Smartcard-PIN niemals',
        'in Dateinamen, Notizen oder ungeschützten Dateien speichern.',
        '',
        'Signatur und Zeitstempel:',
        'Ein erfolgreicher Signatur- oder Zeitstempelvorgang beweist für sich allein',
        'weder qualifizierten Status noch eine vertrauenswürdige Zertifikatskette.',
        'Signaturen und PDF/A-Dateien bei Bedarf mit unabhängigen Prüfwerkzeugen',
        'validieren. Beim erneuten Bearbeiten signierter PDFs wird die Signatur',
        'ungültig; PiPDFBox zeigt vor der Weiterverarbeitung eine Warnung.',
    ],
    [
        '10. Fehlerdiagnose',
        'Anmeldung nicht möglich:',
        'Passwort prüfen. Bei einer abgelaufenen Sitzung die Seite neu laden.',
        '',
        'Weboberfläche nicht erreichbar:',
        'Netzwerk, Raspberry-Pi-Adresse und konfigurierten Port prüfen. Danach',
        'Nginx- und PHP-FPM-Dienst sowie die Installationsprüfung verwenden.',
        '',
        'Office-Datei wird nicht übernommen:',
        'LibreOffice-Installation, Dateiformat und Dateigröße prüfen. Unterstützt',
        'werden DOC/DOCX, XLS/XLSX und PPT/PPTX; maximal 400 MB pro Datei.',
        '',
        'PDF-Auftrag schlägt fehl:',
        'Prüfen, ob die Datei ein gültiges, nicht passwortgeschütztes PDF ist, ob',
        'die Anzahl der Dateien zur Funktion passt und genügend Speicherplatz frei',
        'ist. Bei OCR die Sprache und Scanqualität kontrollieren.',
        '',
        'Signatur oder Zeitstempel schlägt fehl:',
        'Kartenleser, Smartcard, Zertifikat, OpenSC, PIN-Eingabe und Verbindung',
        'zum ausgewählten TSA-Endpunkt prüfen. Ein einfacher HTTP-GET-Test eines',
        'TSA-Endpunkts ist kein Nachweis für die RFC-3161-Funktion.',
        '',
        'Nach einem Update:',
        'Anwendung über das Installationsmenü aktualisieren und im Browser Strg+F5',
        'drücken, damit keine alte JavaScript- oder CSS-Version aus dem Cache läuft.',
    ],
    [
        'Anlage A – Technische Dokumentation',
        'Diese Anlage richtet sich an Administratoren und technisch versierte',
        'Anwender. Sie beschreibt die im PiPDFBox-Installer festgelegte Architektur',
        'und die vorgesehenen Komponenten. Debian-Sicherheitsupdates können einzelne',
        'Paketstände verändern; festgelegte Projektversionen sind ausdrücklich genannt.',
        '',
        'A.1 Systemarchitektur und Verzeichnisse',
        'Zielplattform ist Raspberry Pi 5 mit Debian GNU/Linux 13 (Trixie). Nginx',
        'stellt /opt/pipdfbox/public bereit. PHP 8.4 FPM führt PHP-Endpunkte über',
        '/run/php/php8.4-fpm.sock aus. Der Python-Worker verarbeitet PDF-Aufträge.',
        '',
        'Wichtige Pfade:',
        '- /opt/pipdfbox: Anwendungscode.',
        '- /opt/pipdfbox/python: pyHanko und Python-Abhängigkeiten der Signierung.',
        '- /opt/opensc-0.27.1: eigens gebautes OpenSC samt PKCS#11-Modul.',
        '- /etc/pipdfbox: Zugangskonfiguration und Webserver-Port.',
        '- /var/lib/pipdfbox/jobs: Uploads, Ergebnisse und temporäre Auftragsdaten.',
        '- /var/lib/pipdfbox/certificates: zwischengespeicherte öffentliche CA-Zertifikate.',
        'Laufzeit- und Konfigurationsdaten gehören nicht ins Git-Repository.',
        'Auftragsverzeichnisse werden nach mehr als 24 Stunden bereinigt.',
        '',
        'A.2 Installierte Debian-Pakete und Aufgaben',
        '- sudo: administrativer Aufruf des Installations- und Verwaltungsmenüs.',
        '- polkitd: Autorisierungsdienst für den gezielten PC/SC-Zugriff von www-data.',
        '- nginx: HTTP-Webserver und Weiterleitung an PHP-FPM.',
        '- php8.4-cli und php8.4-fpm: PHP-Laufzeit und FastCGI-Dienst.',
        '- php8.4-mbstring und php8.4-zip: Zeichenketten- und ZIP-Unterstützung.',
        '- python3, python3-pip, python3-pypdf und python3-pil: Worker-Laufzeit,',
        '  Python-Paketinstallation, PDF-Bibliothek und Bildverarbeitung.',
        '- ghostscript: PDF-Neuberechnung und Komprimierung.',
        '- qpdf: PDF-Struktur- und Stream-Optimierung.',
        '- poppler-utils: zusätzliche PDF-Werkzeuge.',
        '- libreoffice, libreoffice-writer, libreoffice-calc und libreoffice-impress:',
        '  Konvertierung von Word-, Excel- und PowerPoint-Dateien nach PDF.',
        '- ocrmypdf: OCR-Pipeline und PDF/A-Konvertierung.',
        '- tesseract-ocr, tesseract-ocr-deu, tesseract-ocr-eng und',
        '  tesseract-ocr-osd: Texterkennung für Deutsch/Englisch und Orientierung.',
        '- pcscd: PC/SC-Dienst für Smartcard-Leser.',
        '- libccid und libifd-cyberjack6: Treiberunterstützung für Kartenleser.',
        '- libpcsclite-dev: PC/SC-Entwicklungsdateien für den OpenSC-Build.',
        '- libssl-dev, libreadline-dev und zlib1g-dev: Entwicklungsbibliotheken.',
        '- autoconf, automake, build-essential, docbook-xsl, xsltproc, libtool',
        '  und pkg-config: Konfigurations- und Build-Werkzeuge für OpenSC.',
        'ca-certificates, curl, tar und iproute2 unterstützen Downloads,',
        'Zertifikatsprüfung, Archivverarbeitung und Portprüfung.',
    ],
    [
        'Anlage A – Technische Dokumentation (Fortsetzung)',
        'A.3 Warum OpenSC selbst gebaut wird',
        'Der Installer baut OpenSC 0.27.1 aus dem offiziellen Release-Quellarchiv.',
        'Vor dem Entpacken wird die SHA-256-Prüfsumme gegen den im Installer',
        'hinterlegten Wert geprüft. Installiert wird in einem eigenen Prefix:',
        '/opt/opensc-0.27.1. Die Bibliothek wird über ld.so.conf.d beim dynamischen',
        'Linker registriert.',
        '',
        'Der separate Build stellt sicher, dass PiPDFBox gezielt die vorgesehene',
        'OpenSC-Version und deren Werkzeuge verwendet – unabhängig davon, welche',
        'Version eine Debian-Paketquelle bereitstellt. opensc.py erwartet dort',
        'pkcs11-tool und opensc-pkcs11.so. Diese festen Pfade vermeiden eine',
        'unbeabsichtigte Nutzung eines anderen PKCS#11-Moduls.',
        '',
        'OpenSC vermittelt den Zugriff auf Smartcards. PKCS#11 ist die standardisierte',
        'Schnittstelle zu kryptografischen Token. pkcs11-tool liest das öffentliche',
        'Signaturzertifikat und benötigte öffentliche CA-Zertifikate aus. Die',
        'private Signaturoperation wird an Karte und Kartenleser delegiert.',
        '',
        'A.4 PC/SC, Kartenleser und PIN-Schutz',
        'pcscd stellt den PC/SC-Dienst bereit. libccid und libifd-cyberjack6 liefern',
        'Treiberunterstützung. Der Installer aktiviert pcscd.socket, falls vorhanden,',
        'andernfalls den pcscd-Dienst. Eine Polkit-Regel gestattet dem PHP-FPM-Benutzer',
        'www-data den erforderlichen PC/SC-Zugriff. Diese Berechtigung ist gezielt',
        'und sollte nicht pauschal auf weitere Benutzer ausgeweitet werden.',
        '',
        'Die PIN wird nicht als Webformularwert von PiPDFBox verarbeitet. Sie wird',
        'über den geschützten PIN-Eingabepfad (Protected Authentication Path) des',
        'Kartenlesers eingegeben. Der private Schlüssel ist nicht exportierbar und',
        'verbleibt auf der Smartcard. PiPDFBox nutzt das öffentliche Zertifikat und',
        'übergibt den kryptografischen Auftrag an OpenSC/PKCS#11.',
        '',
        'A.5 pyHanko und Python-Signaturkomponenten',
        'pyHanko 0.38.2 und cryptography werden mit pip in /opt/pipdfbox/python',
        'installiert. Es wird keine virtuelle Python-Umgebung angelegt. opensc.py',
        'ergänzt diesen Pfad zur Laufzeit. pyHanko erzeugt die Signaturcontainer und',
        'PDF-Signaturprofile; der externe Signer lässt den privaten Schlüssel über',
        'pkcs11-tool und die Smartcard verwenden. cryptography wird ab Version',
        '48.0.0 verlangt; pip installiert die dazugehörigen Python-Abhängigkeiten.',
    ],
    [
        'Anlage A – Technische Dokumentation (Fortsetzung)',
        'A.6 PAdES – Signatur innerhalb der PDF',
        'Bei PAdES wird ein PDF-Signaturfeld angelegt. Der Signaturcontainer ist',
        'in die PDF eingebettet; ByteRange beschreibt die signierten Dokumentbereiche.',
        'Die Signatur wird mit SHA-256 erstellt und durch den Smartcard-Schlüssel',
        'kryptografisch erzeugt.',
        '',
        'Normales PAdES benötigt keinen Zeitstempelserver. Optional kann B-LTA',
        'aktiviert werden. Dann fordert pyHanko einen RFC-3161-Zeitstempel an und',
        'bettet Validierungsinformationen sowie Daten für die Langzeitprüfung ein.',
        'B-LTA ist nicht standardmäßig aktiviert.',
        '',
        'Der sichtbare Stempel ist eine zusätzliche grafische Darstellung. Er ist',
        'nicht die kryptografische Signatur selbst und ersetzt weder Signaturfeld',
        'noch Zertifikatsprüfung. Der sichtbare Stempel ist nur bei PAdES verfügbar.',
        '',
        'A.7 CAdES – getrennte Signatur',
        'Bei CAdES wird eine detached CMS/CAdES-Signatur als separate .p7s-Datei',
        'erzeugt. Die PDF selbst bleibt unverändert. Die Signatur bezieht sich',
        'kryptografisch auf die Bytes des Originaldokuments. PiPDFBox verpackt',
        'Original-PDF und .p7s-Datei in ein ZIP-Archiv.',
        '',
        'Das Archiv enthält genau zwei Einträge mit gemeinsamem Namensstamm:',
        '- <Name>.pdf: das unveränderte Original-PDF.',
        '- <Name>.p7s: die detached Signatur.',
        'Der Archivname lautet <Name>.zip. CAdES verwendet den konfigurierten',
        'RFC-3161-Zeitstempeldienst. Sichtbarer Stempel und PAdES-B-LTA sind für',
        'dieses getrennte Signaturprofil nicht vorgesehen.',
        '',
        'A.8 Zertifikatsketten und Vertrauensmodell',
        'Das Signaturzertifikat wird von der Karte ausgelesen. opensc.py beschafft',
        'und prüft die öffentlichen D-TRUST-CA-Zertifikate und legt sie unter',
        '/var/lib/pipdfbox/certificates ab. Dabei werden CA-Eigenschaften und die',
        'Beziehungen von Aussteller und Inhaber geprüft. Private Schlüssel werden',
        'dort nicht gespeichert.',
        '',
        'Für BaltStamp wird das TSA-Zwischenzertifikat aus der TSA-Antwort gelesen.',
        'Der BaltStamp-Root wird anhand eines im Programm hinterlegten SHA-256-',
        'Fingerabdrucks geprüft. Das ist ein anwendungsspezifischer Vertrauensanker.',
        'Eine erfolgreiche Signaturerstellung beweist nicht automatisch, dass jede',
        'Gegenstelle die Zertifikatskette als vertrauenswürdig einstuft.',
    ],
    [
        'Anlage A – Technische Dokumentation (Fortsetzung)',
        'A.9 RFC 3161 und der Zeitstempelserver',
        'Ein RFC-3161-Time-Stamping-Authority-Dienst (TSA) bestätigt einen',
        'kryptografischen Zeitstempel zu einem übermittelten Hashwert. Der Client',
        'sendet nicht nur eine Datumsangabe: Er fordert eine signierte Antwort an,',
        'die Hashwert und bestätigten Zeitpunkt miteinander verknüpft. Die Antwort',
        'enthält außerdem Informationen zur TSA-Zertifikatskette.',
        '',
        'PiPDFBox verwendet pyHankos HTTPTimeStamper. Als voreingestellter Endpunkt',
        'ist http://tsa2.baltstamp.lt hinterlegt. In den Einstellungen kann ein',
        'anderer HTTP- oder HTTPS-Endpunkt gespeichert und ausgewählt werden.',
        'Zugangsdaten in der URL und URL-Fragmente sind nicht zulässig.',
        '',
        'Die Verwendung hängt vom Signaturprofil ab:',
        '- Normales PAdES: kein TSA-Aufruf erforderlich.',
        '- PAdES B-LTA: TSA-Aufruf für Dokumentzeitstempel und Langzeitvalidierung.',
        '- CAdES: RFC-3161-Zeitstempel für die getrennte Signatur.',
        '',
        'Ein erfolgreicher HTTP-Aufruf oder eine erreichbare TSA-Adresse ist noch',
        'kein Nachweis für eine funktionierende RFC-3161-Funktion. Entscheidend',
        'sind eine gültige Zeitstempelantwort, deren Prüfung und die Validierung',
        'der zugehörigen Zertifikatskette.',
        '',
        'A.10 Signaturprüfung und Grenzen',
        'Die Weboberfläche erkennt mögliche PDF-Signaturbereiche strukturell über',
        'den Marker /ByteRange. Das ist eine Erkennungshilfe, keine vollständige',
        'kryptografische Validierung. Bei einer belastbaren Bewertung sind unter',
        'anderem Signaturintegrität, Zertifikatskette, Gültigkeitszeitraum,',
        'Sperrstatus und gegebenenfalls Zeitstempel zu berücksichtigen.',
        '',
        'Ein Zeitstempel macht eine Signatur nicht automatisch qualifiziert oder',
        'rechtlich wirksam. Auch der qualifizierte Status eines Zertifikats und',
        'seine Eignung für den konkreten Zweck müssen gesondert beurteilt werden.',
        'Nachträgliche Änderungen signierter PDF-Bytes können die Signatur ungültig',
        'machen. Für relevante Prüfungen sind unabhängige Validierungswerkzeuge',
        'einzusetzen.',
        '',
        'A.11 Worker, Aufträge und Bereinigung',
        'PHP übergibt dem Python-Worker Argumente als getrennte Prozessparameter,',
        'nicht als Shell-Kommandozeile. Der Worker prüft Eingabepfade und erwartet',
        'Auftragsdateien im jeweiligen Auftragsordner. Ein globaler Lock serialisiert',
        'PDF-Worker und begrenzt gleichzeitige CPU- und RAM-Last.',
        '',
        'Auftragsverzeichnisse unter /var/lib/pipdfbox/jobs enthalten Uploads,',
        'temporäre Dateien und Ergebnisse. Der systemd-Timer startet zehn Minuten',
        'nach dem Boot und danach stündlich. Aufträge älter als 24 Stunden werden',
        'gelöscht. Bei laufendem Worker wird ein Bereinigungslauf übersprungen.',
        '',
        'A.12 Administration und Diagnose',
        'Das Verwaltungsmenü wird mit sudo pipdfbox gestartet. Menüpunkt 3 prüft',
        'die Installation, Menüpunkt 4 aktualisiert die Anwendung. Für die Diagnose',
        'sind insbesondere nginx, php8.4-fpm, pcscd.socket bzw. pcscd und',
        'pipdfbox-cleanup.timer relevant. nginx -t prüft die Webserverkonfiguration.',
        'Die Anwendung ist für ein vertrauenswürdiges lokales Netzwerk ausgelegt.',
        'Internetzugriff erfordert zusätzliche Maßnahmen wie TLS, restriktive',
        'Zugriffskontrolle, Firewall-Regeln und regelmäßige Sicherheitsupdates.',
    ],
];

$objects = [];
$addObject = static function (string $body) use (&$objects): int {
    $objects[] = $body;
    return count($objects);
};

$fontId = $addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
$pageIds = [];

/**
 * Jede physische Seite erhält ab Seite 2 die zentrierte Kopfzeilennummer.
 * Längere Kapitel werden auf Folgeseiten fortgesetzt, statt abgeschnitten.
 */
$physicalPage = 0;
$addPdfPage = static function (string $title, array $lines, bool $cover = false) use (&$physicalPage, &$pageIds, $addObject, $fontId): void {
    $physicalPage++;
    $commands = [];
    if (!$cover) {
        $label = '- ' . $physicalPage . ' -';
        $label = iconv('UTF-8', 'Windows-1252//TRANSLIT', $label) ?: $label;
        $label = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $label);
        $commands[] = 'BT /F1 9 Tf 1 0 0 1 285 812 Tm (' . $label . ') Tj ET';
    }

    $title = iconv('UTF-8', 'Windows-1252//TRANSLIT', $title) ?: $title;
    $title = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title);
    $commands[] = 'BT ' . ($cover ? '/F1 24 Tf' : '/F1 15 Tf') . ' 1 0 0 1 50 ' . ($cover ? '770' : '780') . ' Tm (' . $title . ') Tj ET';

    $y = $cover ? 720 : 748;
    foreach ($lines as $line) {
        $line = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string)$line) ?: (string)$line;
        foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $wrappedLine) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $wrappedLine);
            $commands[] = 'BT /F1 10 Tf 1 0 0 1 50 ' . $y . ' Tm (' . $escaped . ') Tj ET';
            $y -= 14;
        }
    }

    $stream = gzcompress(implode("\n", $commands), 9);
    $contentId = $addObject('<< /Length ' . strlen($stream) . ' /Filter /FlateDecode >>' . "\nstream\n" . $stream . "\nendstream");
    $pageIds[] = $addObject('<< /Type /Page /Parent PAGES 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>');
};

foreach ($pages as $pageIndex => $pageLines) {
    $title = (string)array_shift($pageLines);
    $cover = ($pageIndex === 0);
    // Erst umbrechen, dann nach tatsächlichen Druckzeilen aufteilen.
    // So können lange Absätze die untere Seitenkante nicht unbemerkt überlaufen.
    $wrappedLines = [];
    foreach ($pageLines as $line) {
        $line = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string)$line) ?: (string)$line;
        foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $wrappedLine) {
            $wrappedLines[] = $wrappedLine;
        }
    }
    $chunks = array_chunk($wrappedLines, $cover ? 44 : 47);
    if ($chunks === []) {
        $chunks = [[]];
    }
    foreach ($chunks as $chunkIndex => $chunk) {
        $pageTitle = $chunkIndex === 0 ? $title : $title . ' (Fortsetzung)';
        $addPdfPage($pageTitle, $chunk, $cover && $chunkIndex === 0);
    }
}

$pagesId = $addObject('<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] >>');
foreach ($pageIds as $id) {
    $objects[$id - 1] = str_replace('PAGES', (string)$pagesId, $objects[$id - 1]);
}
$catalogId = $addObject('<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>');
$infoId = $addObject('<< /Title (PiPDFBox Handbuch) /Author (PiPDFBox) >>');

$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [0];
foreach ($objects as $index => $body) {
    $offsets[] = strlen($pdf);
    $objectNumber = $index + 1;
    $pdf .= $objectNumber . " 0 obj\n" . $body . "\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i < count($offsets); $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root " . $catalogId . " 0 R /Info " . $infoId . " 0 R >>\n";
$pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="PiPDFBox-Handbuch.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $pdf;
