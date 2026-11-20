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
        '3. Nummerieren, Komprimieren und Archivieren',
        'PDF nummerieren:',
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
        'Eine PDF auswählen. Die Signierung verwendet OpenSC, PKCS#11 und pyHanko',
        'mit dem vorgesehenen D-TRUST-Signaturzertifikat auf einer kompatiblen',
        'Smartcard. Karte und Leser müssen angeschlossen und eingerichtet sein.',
        'Die PIN-Eingabe erfolgt über den geschützten Authentisierungspfad des',
        'Kartenlesers; PiPDFBox verarbeitet die PIN nicht selbst.',
        '',
        'Optionen:',
        '- Sichtbaren Stempel anbringen: fügt eine zusätzliche Signaturseite mit',
        '  einem sichtbaren Siegel sowie Signaturangaben an.',
        '- Zeitstempelserver nutzen: fordert zusätzlich einen RFC-3161-Zeitstempel',
        '  beim ausgewählten TSA-Dienst an. Diese Option ist standardmäßig aktiv.',
        'Der Zeitstempel kann unabhängig vom sichtbaren Stempel ein- oder',
        'ausgeschaltet werden. Ergebnis: signiert.pdf.',
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
        '2. Originalname + Aktion: zum Beispiel test-nummeriert.pdf.',
        '3. Datum + Originalname: zum Beispiel 261120-test.pdf (JJMMTT).',
        '4. Datum + Prefix + Originalname: vor jeder Verarbeitung erscheint ein',
        '   Dialog zur Eingabe des Prefix, zum Beispiel 261120-Rechnung-test.pdf.',
        'Der Prefix darf höchstens 80 Zeichen lang sein. Nicht zulässige Zeichen',
        'werden für den Dateinamen bereinigt. Die Einstellung bleibt gespeichert.',
        'Bei ZIP-Ergebnissen wird der Ergebnisname nicht wie eine PDF umbenannt.',
    ],
    [
        '7. Ergebnis, Meldungen und Ablaufsteuerung',
        'Nach erfolgreicher Verarbeitung wird das Ergebnis als Kachel angezeigt.',
        'Dort kann es geöffnet, heruntergeladen, gelöscht oder – bei PDF-Ergebnissen',
        '– über den Übernehmen-Pfeil wieder als Eingabedatei verwendet werden.',
        'ZIP-Ergebnisse lassen sich im integrierten ZIP-Browser ansehen.',
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
];

$objects = [];
$addObject = static function (string $body) use (&$objects): int {
    $objects[] = $body;
    return count($objects);
};

$fontId = $addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
$pageIds = [];

foreach ($pages as $pageLines) {
    $commands = ["BT", "/F1 16 Tf", "50 790 Td"];
    $title = (string)array_shift($pageLines);
    $title = iconv('UTF-8', 'Windows-1252//TRANSLIT', $title) ?: $title;
    $title = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title);
    $commands[] = '(' . $title . ') Tj';
    $commands[] = '/F1 10 Tf';

    $y = 758;
    foreach ($pageLines as $line) {
        $line = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string)$line) ?: (string)$line;
        $line = wordwrap($line, 88, "\n", true);
        foreach (explode("\n", $line) as $wrappedLine) {
            if ($y < 48) {
                break;
            }
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $wrappedLine);
            $commands[] = '1 0 0 1 50 ' . $y . ' Tm';
            $commands[] = '(' . $escaped . ') Tj';
            $y -= 15;
        }
    }
    $commands[] = 'ET';

    $stream = gzcompress(implode("\n", $commands), 9);
    $contentId = $addObject('<< /Length ' . strlen($stream) . ' /Filter /FlateDecode >>' . "\nstream\n" . $stream . "\nendstream");
    $pageIds[] = $addObject('<< /Type /Page /Parent PAGES 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>');
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
