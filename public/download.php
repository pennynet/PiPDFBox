<?php
declare(strict_types=1); session_start();
if (empty($_SESSION['logged_in'])) { http_response_code(403); exit('Nicht angemeldet.'); }
$cfg=require '/etc/pipdfbox/config.php'; $job=(string)($_GET['job']??''); $file=(string)($_GET['file']??'');
if (!preg_match('/^[a-f0-9]{32}$/',$job) || !preg_match('/^[\\p{L}\\p{N}_.-]+$/u',$file) || str_contains($file,'..')) { http_response_code(400); exit('Ungültige Anfrage.'); }
$path=$cfg['data_dir'].'/jobs/'.$job.'/'.$file;
if (!is_file($path) || !str_starts_with(realpath($path),realpath($cfg['data_dir'].'/jobs/'.$job).DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Datei nicht gefunden.'); }
header('Content-Type: '.(str_ends_with(strtolower($file),'.zip')?'application/zip':(str_ends_with(strtolower($file),'.p7s')?'application/pkcs7-signature':'application/pdf')));
header('Content-Length: '.filesize($path));
$disposition = (($_GET['inline'] ?? '') === '1' && str_ends_with(strtolower($file), '.pdf')) ? 'inline' : 'attachment';
$downloadName = basename($file);
$fallbackName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $downloadName) ?: 'download';
header('Content-Disposition: '.$disposition.'; filename="'.$fallbackName.'"; filename*=UTF-8\'\''.rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff'); readfile($path);
