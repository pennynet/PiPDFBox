<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/cades-zip.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FEHLER: {$message}\n");
        exit(1);
    }
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "FEHLER: PHP-ZIP-Erweiterung fehlt.\n");
    exit(1);
}

$jobDir = sys_get_temp_dir() . '/pipdfbox-cades-zip-' . bin2hex(random_bytes(8));
check(mkdir($jobDir, 0700), 'Testverzeichnis konnte nicht angelegt werden.');

try {
    $pdfBytes = "%PDF-1.7\nPiPDFBox CAdES ZIP smoke test\n%%EOF\n";
    $signatureBytes = "\x30\x03\x02\x01\x00";
    $pdfPath = $jobDir . '/input.pdf';
    $signaturePath = $jobDir . '/signed.p7s';
    file_put_contents($pdfPath, $pdfBytes);
    file_put_contents($signaturePath, $signatureBytes);
    $pdfHashBefore = hash_file('sha256', $pdfPath);

    check(createCadesZipArchive($jobDir, $pdfPath, $signaturePath, 'Überblick-signiert'), 'ZIP-Erstellung fehlgeschlagen.');

    $archivePath = $jobDir . '/Überblick-signiert.zip';
    check(is_file($archivePath), 'ZIP-Datei fehlt.');
    $zip = new ZipArchive();
    check($zip->open($archivePath, ZipArchive::RDONLY) === true, 'ZIP-Datei ist nicht lesbar.');
    check($zip->numFiles === 2, 'ZIP enthält nicht genau zwei Dateien.');
    check($zip->getFromName('Überblick-signiert.pdf') === $pdfBytes, 'PDF-Inhalt im ZIP weicht vom Original ab.');
    check($zip->getFromName('Überblick-signiert.p7s') === $signatureBytes, 'Signaturinhalt im ZIP weicht vom Original ab.');
    $zip->close();

    check(hash_file('sha256', $pdfPath) === $pdfHashBefore, 'Original-PDF wurde verändert.');
    check(!createCadesZipArchive($jobDir, $pdfPath, $signaturePath, 'Überblick-signiert'), 'Vorhandenes ZIP wurde unerwartet überschrieben.');
    echo "CAdES-ZIP-Smoke-Test erfolgreich.\n";
} finally {
    foreach (glob($jobDir . '/*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    foreach (glob($jobDir . '/.*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    rmdir($jobDir);
}
