<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['logged_in'])) {
    respond(401, ['error' => 'Bitte zuerst bei PiPDFBox anmelden.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Methode nicht erlaubt.']);
}
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    respond(403, ['error' => 'Sitzung abgelaufen. Bitte Seite neu laden.']);
}

$file = $_FILES['pdf'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    respond(400, ['error' => 'Bitte eine PDF-Datei auswählen.']);
}
if (($file['size'] ?? 0) < 1 || $file['size'] > 400 * 1024 * 1024) {
    respond(400, ['error' => 'Die PDF muss kleiner als 400 MB sein.']);
}
$tmp = (string)$file['tmp_name'];
$fh = @fopen($tmp, 'rb');
$signature = $fh ? fread($fh, 5) : '';
if ($fh) fclose($fh);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
if ($signature !== '%PDF-' || !in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
    respond(400, ['error' => 'Die ausgewählte Datei ist keine gültige PDF.']);
}

$keyFile = '/etc/pipdfbox/sealium.key';
if (!is_readable($keyFile)) {
    respond(503, ['error' => 'Sealium-API-Key fehlt oder ist für den Webserver nicht lesbar. Bitte den API-Schlüssel unter Einstellungen hinterlegen.']);
}
$key = trim((string)file_get_contents($keyFile));
if ($key === '' || preg_match('/[\r\n"]/', $key)) {
    respond(503, ['error' => 'Der gespeicherte Sealium-API-Key ist ungültig.']);
}
if (!function_exists('proc_open')) {
    respond(503, ['error' => 'Die Serverkonfiguration erlaubt den benötigten API-Aufruf nicht (proc_open).']);
}

$config = tempnam(sys_get_temp_dir(), 'pipdfbox-sealium-');
if ($config === false) respond(500, ['error' => 'Temporäre API-Konfiguration konnte nicht erstellt werden.']);
@chmod($config, 0600);
$header = 'header = "Authorization: Bearer ' . $key . '"' . PHP_EOL;
unset($key);
if (file_put_contents($config, $header, LOCK_EX) === false) {
    @unlink($config);
    respond(500, ['error' => 'API-Konfiguration konnte nicht geschrieben werden.']);
}

$cmd = [
    '/usr/bin/curl', '--silent', '--show-error', '--fail-with-body',
    '--connect-timeout', '15', '--max-time', '180',
    '--config', $config,
    '--form', 'file=@' . $tmp . ';type=application/pdf',
    'https://api.sealium.eu/v1/validate'
];
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($process)) {
    @unlink($config);
    respond(502, ['error' => 'Sealium-Verbindung konnte nicht gestartet werden.']);
}
fclose($pipes[0]);
$body = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($config);

$data = json_decode((string)$body, true);
if ($exitCode !== 0 || !is_array($data)) {
    error_log('PiPDFBox Sealium request failed: ' . trim((string)$stderr));
    respond(502, ['error' => 'Die Signaturprüfung bei Sealium ist fehlgeschlagen. HTTP-/Verbindungsfehler; Serverprotokoll enthält Details.']);
}
respond(200, $data);
