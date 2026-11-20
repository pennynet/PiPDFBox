<?php
declare(strict_types=1);

/*
 * Das Handbuch wird hier direkt als PDF erzeugt. Damit ist keine separat
 * übertragene Binärdatei erforderlich, die beim Update beschädigt werden kann.
 */

$pages = [
    [
        'PiPDFBox - Handbuch',
        'Bedienung, Funktionen und Administration',
        '',
        'Webbasierte PDF-Verarbeitung auf Raspberry Pi 5 und Debian 13.',
        '',
        'Dieses Handbuch beschreibt die Weboberflaeche, die Verarbeitungsschritte',
        'sowie Installation, Betrieb und Fehlerdiagnose.',
        '',
        'Projekt: pennynet/PiPDFBox | Stand: November 2026',
    ],
    [
        '1. Ueberblick',
        'PiPDFBox ist eine selbst gehostete Webanwendung zur Verarbeitung von',
        'PDF-Dateien im lokalen Netzwerk. Dateien werden lokal auf dem Raspberry Pi',
        'verarbeitet.',
        '',
        'Funktionen:',
        '- PDF-Dateien zusammenfuehren und aufteilen',
        '- Seiten mehrerer PDFs abwechselnd zusammenstellen',
        '- Seiten nummerieren und PDFs komprimieren',
        '- PDF/A-Konvertierung',
        '- OCR-Texterkennung fuer Scans',
        '- PDF-Signatur mit unterstuetzter Smartcard',
        '- RFC-3161-Zeitstempel ueber konfigurierbare TSA-Server',
        '',
        'Technik: Nginx, PHP 8.4 FPM, Python/pypdf, Ghostscript, qpdf, OCRmyPDF,',
        'Tesseract, OpenSC und pyHanko.',
    ],
    [
        '2. Bedienung',
        'Anwendung im Browser oeffnen, gewuenschte Funktion waehlen und PDF-Dateien',
        'hinzufuegen. Reihenfolge und Optionen pruefen, Verarbeitung starten und',
        'das Ergebnis herunterladen.',
        '',
        'Zusammenfuehren: Die Reihenfolge der ausgewaehlten Dateien bestimmt die',
        'Reihenfolge im Ergebnis.',
        '',
        'Aufteilen: Quelldokument und gewuenschte Seitenbereiche beziehungsweise',
        'Aufteilungsoption festlegen.',
        '',
        'Abwechselnd zusammenstellen: Seiten mehrerer PDFs werden abwechselnd',
        'uebernommen, etwa bei doppelseitig gescannten Stapeln.',
        '',
        'Seitennummerierung: Seitenzahlen einfuegen und das Ergebnis auf Position',
        'und Lesbarkeit pruefen.',
    ],
    [
        '3. Komprimierung, PDF/A und OCR',
        'Komprimierung reduziert die Dateigroesse. Staerkere Komprimierung kann',
        'Bildaufloesung und Bildqualitaet verringern. Ergebnis pruefen.',
        '',
        'PDF/A: Fuer Langzeitarchivierung vorgesehene Variante. Bei verbindlichen',
        'Anforderungen das Ergebnis zusaetzlich mit einem PDF/A-Pruefwerkzeug',
        'validieren.',
        '',
        'OCR: OCRmyPDF und Tesseract ergaenzen bei Bildscans eine durchsuchbare',
        'Textebene. Die Qualitaet haengt von Aufloesung, Kontrast, Ausrichtung und',
        'Sprache ab. Textsuche stichprobenartig testen.',
    ],
    [
        '4. Signatur und Zeitstempel',
        'Die Signaturfunktion verwendet OpenSC fuer PKCS#11-Smartcards und pyHanko',
        'zur PDF-Signierung. Kartenleser, Karte, PIN und Zertifikat muessen korrekt',
        'eingerichtet sein.',
        '',
        'Ablauf: Smartcard anschliessen und einstecken, PDF auswaehlen,',
        'Signaturoptionen festlegen, Vorgang starten und PIN-Abfrage beachten.',
        'Signatur anschliessend mit einem geeigneten PDF-Pruefprogramm kontrollieren.',
        '',
        'Die Option "Stempel und Zeitstempel anfuegen" steuert die sichtbare',
        'Stempelgestaltung und die RFC-3161-Zeitstempelanforderung. Im Dialog',
        '"Zeitstempel" BalTstamp oder einen eigenen HTTP-/HTTPS-TSA-Endpunkt waehlen.',
        '',
        'Ein erfolgreicher Abruf allein beweist weder qualifizierten Status noch',
        'eine gueltige Vertrauenskette. Beides muss gesondert geprueft werden.',
    ],
    [
        '5. Installation und Aktualisierung',
        'PiPDFBox ist fuer Raspberry Pi 5 mit Debian 13 vorgesehen. Das',
        'Installationsmenue richtet Webserver, PHP-FPM, Python-Umgebung und',
        'benoetigte Systemwerkzeuge ein. Smartcard-Signaturen benoetigen kompatible',
        'Hardware und Zertifikate.',
        '',
        'Aktualisierung im Terminal:',
        'sudo pipdfbox',
        '',
        'Im Menue "4) Anwendung aktualisieren" waehlen. Die aktuelle Version wird',
        'aus GitHub geladen und die Anwendungsdateien werden uebernommen. Danach',
        'die Weboberflaeche neu laden.',
    ],
    [
        '6. Daten, Sicherheit und Fehlerdiagnose',
        'TSA-Konfiguration:',
        '/var/lib/pipdfbox/tsa-servers.json',
        '',
        'PiPDFBox nicht ungeschuetzt ins Internet stellen. Zugriff auf das lokale',
        'Netzwerk beschraenken, System aktuell halten und auf Dateiberechtigungen',
        'sowie sichere Backups achten. Smartcard-PIN nicht in Dateinamen oder',
        'ungeschuetzten Notizen speichern.',
        '',
        'Weboberflaeche nicht erreichbar: Netzwerk, Adresse/Port sowie Nginx und',
        'PHP-FPM pruefen.',
        '',
        'PDF-Verarbeitung schlaegt fehl: Quelldatei auf Beschaedigung oder',
        'Passwortschutz pruefen; freien Speicherplatz kontrollieren.',
        '',
        'OCR schlecht: Scanqualitaet, Ausrichtung und Sprache pruefen.',
        '',
        'Smartcard/Zeitstempel: Kartenleser, OpenSC, Zertifikat, PIN, TSA-URL und',
        'Netzwerkverbindung pruefen. TSA-Dienste erwarten RFC-3161-POST-Anfragen;',
        'ein einfacher GET-Test ist nicht aussagekraeftig.',
        '',
        'Dieses Handbuch beschreibt die vorgesehenen Funktionen. Details koennen je',
        'nach Version, Systemkonfiguration und Hardware variieren.',
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
