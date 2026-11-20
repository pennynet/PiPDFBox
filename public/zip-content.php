<?php
declare(strict_types=1);

session_start();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function zipError(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['logged_in'])) {
    zipError(403, 'Nicht angemeldet.');
}

$cfg = require '/etc/pipdfbox/config.php';
$job = (string)($_GET['job'] ?? '');
$file = (string)($_GET['file'] ?? '');
$mode = (string)($_GET['mode'] ?? '');

if (!preg_match('/^[a-f0-9]{32}$/', $job) ||
    !preg_match('/^[A-Za-z0-9_.-]+\.zip$/i', $file) ||
    str_contains($file, '..')) {
    zipError(400, 'Ungültige ZIP-Anfrage.');
}

// Zugriff ausschließlich auf das aktuell in dieser Sitzung erzeugte Ergebnis.
$result = $_SESSION['result'] ?? null;
if (!is_array($result) || ($result['job'] ?? '') !== $job || ($result['file'] ?? '') !== $file) {
    zipError(403, 'Diese ZIP-Datei gehört nicht zum aktuellen Ergebnis.');
}

$jobDir = realpath($cfg['data_dir'] . '/jobs/' . $job);
$archivePath = $jobDir ? realpath($jobDir . DIRECTORY_SEPARATOR . $file) : false;
if (!$jobDir || !$archivePath ||
    !str_starts_with($archivePath, $jobDir . DIRECTORY_SEPARATOR) ||
    !is_file($archivePath)) {
    zipError(404, 'ZIP-Datei nicht gefunden.');
}

if (!class_exists(ZipArchive::class)) {
    zipError(500, 'Die PHP-ZIP-Erweiterung ist nicht installiert.');
}

$zip = new ZipArchive();
if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
    zipError(422, 'Die ZIP-Datei kann nicht geöffnet werden.');
}

function safeArchivePath(string $path): ?string {
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
        return $path === '' ? '' : null;
    }
    $parts = explode('/', rtrim($path, '/'));
    foreach ($parts as $part) {
        if ($part === '..' || $part === '.' || $part === '') return null;
    }
    return implode('/', $parts);
}

if ($mode === 'list') {
    $requested = safeArchivePath((string)($_GET['path'] ?? ''));
    if ($requested === null) {
        $zip->close();
        zipError(400, 'Ungültiger Ordnerpfad.');
    }

    $prefix = $requested === '' ? '' : $requested . '/';
    $entries = [];
    $directories = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || !isset($stat['name'])) continue;
        $entryName = (string)$stat['name'];
        $normalized = safeArchivePath($entryName);
        if ($normalized === null || $normalized === '') continue;
        if (!str_starts_with($normalized . (str_ends_with($entryName, '/') ? '/' : ''), $prefix)) continue;

        $relative = substr($normalized, strlen($prefix));
        if ($relative === '') continue;
        $parts = explode('/', $relative);
        $child = $parts[0];
        if ($child === '') continue;

        if (count($parts) > 1 || str_ends_with($entryName, '/')) {
            $directories[$child] = true;
        } else {
            $entries[$child] = [
                'name' => $child,
                'directory' => false,
                'size' => (int)($stat['size'] ?? 0)
            ];
        }
    }

    foreach (array_keys($directories) as $directory) {
        $entries[$directory] = ['name' => $directory, 'directory' => true, 'size' => 0];
    }
    $zip->close();

    $items = array_values($entries);
    usort($items, static function(array $a, array $b): int {
        if ($a['directory'] !== $b['directory']) return $a['directory'] ? -1 : 1;
        return strnatcasecmp($a['name'], $b['name']);
    });

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['entries' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($mode === 'download') {
    $entry = safeArchivePath((string)($_GET['entry'] ?? ''));
    if ($entry === null || $entry === '') {
        $zip->close();
        zipError(400, 'Ungültiger Dateipfad.');
    }
    $index = $zip->locateName($entry, 0);
    if ($index === false) {
        $zip->close();
        zipError(404, 'Datei im ZIP-Archiv nicht gefunden.');
    }
    $stream = $zip->getStream($entry);
    if ($stream === false) {
        $zip->close();
        zipError(422, 'Datei im ZIP-Archiv kann nicht gelesen werden.');
    }
    $name = basename($entry);
    $isPdf = str_ends_with(strtolower($name), '.pdf');
    $mime = $isPdf ? 'application/pdf' : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($isPdf ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\\", "\r", "\n"], '_', $name) . '"');
    $stat = $zip->statName($entry);
    if (is_array($stat) && isset($stat['size'])) header('Content-Length: ' . (int)$stat['size']);
    fpassthru($stream);
    fclose($stream);
    $zip->close();
    exit;
}

$zip->close();
zipError(400, 'Unbekannter ZIP-Modus.');
