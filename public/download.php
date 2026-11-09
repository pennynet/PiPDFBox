<?php
declare(strict_types=1); session_start();
if (empty($_SESSION['logged_in'])) { http_response_code(403); exit('Nicht angemeldet.'); }
$cfg=require '/etc/pipdfbox/config.php'; $job=(string)($_GET['job']??''); $file=(string)($_GET['file']??'');
if (!preg_match('/^[a-f0-9]{32}$/',$job) || !preg_match('/^[A-Za-z0-9_.-]+$/',$file) || str_contains($file,'..')) { http_response_code(400); exit('Ungültige Anfrage.'); }
$path=$cfg['data_dir'].'/jobs/'.$job.'/'.$file;
if (!is_file($path) || !str_starts_with(realpath($path),realpath($cfg['data_dir'].'/jobs/'.$job).DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Datei nicht gefunden.'); }
header('Content-Type: '.(str_ends_with(strtolower($file),'.zip')?'application/zip':'application/pdf'));
header('Content-Length: '.filesize($path)); header('Content-Disposition: attachment; filename="'.basename($file).'"'); header('X-Content-Type-Options: nosniff'); readfile($path);
