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
if (empty($_SESSION['logged_in'])) respond(401, ['error' => 'Bitte zuerst bei PiPDFBox anmelden.']);
$keyFile = '/etc/pipdfbox/sealium.key';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, ['configured' => is_file($keyFile) && is_readable($keyFile) && filesize($keyFile) > 0]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Methode nicht erlaubt.']);
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    respond(403, ['error' => 'Sitzung abgelaufen. Bitte Seite neu laden.']);
}
$key = trim((string)($_POST['api_key'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._~+\/-=]{20,512}$/D', $key)) {
    respond(400, ['error' => 'Der API-Schlüssel hat ein ungültiges Format. Bitte den vollständigen Schlüssel prüfen.']);
}
if (!function_exists('proc_open')) respond(503, ['error' => 'Die Serverkonfiguration erlaubt das sichere Speichern nicht (proc_open).']);
$process = proc_open(
    ['/usr/bin/sudo', '-n', '/usr/local/sbin/pipdfbox-set-sealium-key'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) respond(500, ['error' => 'Speicherhelfer konnte nicht gestartet werden.']);
fwrite($pipes[0], $key . "\n");
unset($key);
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
if ($code !== 0) {
    error_log('PiPDFBox Sealium key helper failed: ' . trim((string)$stderr));
    respond(500, ['error' => 'API-Schlüssel konnte nicht sicher gespeichert werden. Bitte Installation aktualisieren.']);
}
respond(200, ['saved' => true]);
