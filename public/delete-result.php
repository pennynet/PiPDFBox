<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Methode nicht erlaubt.']);
}
if (empty($_SESSION['logged_in'])) {
    respond(403, ['error' => 'Nicht angemeldet.']);
}
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $csrf)) {
    respond(403, ['error' => 'Sitzung abgelaufen. Bitte Seite neu laden.']);
}

$job = (string)($_POST['job'] ?? '');
$file = (string)($_POST['file'] ?? '');
$result = $_SESSION['result'] ?? null;
if (!is_array($result)
    || !preg_match('/^[a-f0-9]{32}$/', $job)
    || !preg_match('/^[A-Za-z0-9_.-]+$/', $file)
    || str_contains($file, '..')
    || !hash_equals((string)($result['job'] ?? ''), $job)
    || !hash_equals((string)($result['file'] ?? ''), $file)
) {
    respond(404, ['error' => 'Ausgabedatei nicht gefunden.']);
}

$config = require '/etc/pipdfbox/config.php';
$jobDir = rtrim((string)$config['data_dir'], '/') . '/jobs/' . $job;
$path = $jobDir . '/' . $file;
$realJobDir = realpath($jobDir);
$realPath = realpath($path);
if ($realJobDir === false || $realPath === false
    || !str_starts_with($realPath, $realJobDir . DIRECTORY_SEPARATOR)
    || !is_file($realPath)
) {
    respond(404, ['error' => 'Ausgabedatei nicht gefunden.']);
}
if (!@unlink($realPath)) {
    respond(500, ['error' => 'Ausgabedatei konnte nicht gelöscht werden.']);
}

unset($_SESSION['result']);
respond(200, ['deleted' => true]);
