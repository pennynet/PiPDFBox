<?php
declare(strict_types=1);

$pdfFile = __DIR__ . '/handbuch.pdf';

if (!is_file($pdfFile) || !is_readable($pdfFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Das PiPDFBox-Handbuch wurde auf dem Server nicht gefunden.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="PiPDFBox-Handbuch.pdf"');
header('Content-Length: ' . (string)filesize($pdfFile));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

readfile($pdfFile);
