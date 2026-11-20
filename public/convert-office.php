<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

header('Cache-Control: no-store');

function fail(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['logged_in'])) {
    fail(401, 'Bitte zuerst anmelden.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Ungültige Anfrage.');
}
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    fail(403, 'Sitzung abgelaufen. Bitte Seite neu laden.');
}

$file = $_FILES['office_file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail(400, 'Office-Datei konnte nicht hochgeladen werden.');
}
if (($file['size'] ?? 0) < 1 || $file['size'] > 400 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
    fail(413, 'Die Office-Datei ist leer oder überschreitet 400 MB.');
}

$originalName = basename((string)($file['name'] ?? ''));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
if (!in_array($extension, $allowed, true)) {
    fail(415, 'Dieses Office-Dateiformat wird nicht unterstützt.');
}
if (!is_executable('/usr/bin/libreoffice')) {
    fail(503, 'LibreOffice ist nicht installiert. Bitte PiPDFBox aktualisieren.');
}

set_time_limit(240);
$tempDir = sys_get_temp_dir() . '/pipdfbox-office-' . bin2hex(random_bytes(12));
if (!mkdir($tempDir, 0700, true)) {
    fail(500, 'Temporäres Verzeichnis konnte nicht angelegt werden.');
}
$cleanup = static function (string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $item) {
        if (is_file($item)) @unlink($item);
        elseif (is_dir($item)) {
            foreach (glob($item . '/*') ?: [] as $nested) if (is_file($nested)) @unlink($nested);
            @rmdir($item);
        }
    }
    @rmdir($dir);
};

$stem = pathinfo($originalName, PATHINFO_FILENAME);
$stem = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $stem) ?: 'dokument';
$inputPath = $tempDir . '/' . $stem . '.' . $extension;
if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
    $cleanup($tempDir);
    fail(500, 'Office-Datei konnte nicht zwischengespeichert werden.');
}

$profile = $tempDir . '/profile';
$command = [
    '/usr/bin/libreoffice',
    '-env:UserInstallation=file://' . $profile,
    '--headless',
    '--convert-to', 'pdf',
    '--outdir', $tempDir,
    $inputPath,
];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) {
    $cleanup($tempDir);
    fail(500, 'LibreOffice konnte nicht gestartet werden.');
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$outputPath = $tempDir . '/' . $stem . '.pdf';
if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) < 8) {
    $cleanup($tempDir);
    error_log('PiPDFBox Office-Konvertierung fehlgeschlagen: ' . trim((string)$stderr . ' ' . (string)$stdout));
    fail(422, 'Die Office-Datei konnte nicht in PDF umgewandelt werden. Bitte Dateiformat und Datei prüfen.');
}

$handle = fopen($outputPath, 'rb');
$signature = $handle ? fread($handle, 5) : '';
if ($handle) fclose($handle);
if ($signature !== '%PDF-') {
    $cleanup($tempDir);
    fail(422, 'LibreOffice hat keine gültige PDF-Datei erzeugt.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="converted.pdf"');
header('Content-Length: ' . filesize($outputPath));
readfile($outputPath);
$cleanup($tempDir);
