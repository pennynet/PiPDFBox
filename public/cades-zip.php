<?php
declare(strict_types=1);

/**
 * Erstellt ein ZIP mit dem unveränderten Original-PDF und der detached CAdES-Signatur.
 * Das Archiv wird zunächst temporär geschrieben, geprüft und anschließend atomar veröffentlicht.
 */
function createCadesZipArchive(string $jobDir, string $pdfPath, string $signaturePath, string $stem): bool {
    if (!class_exists(ZipArchive::class) || $stem === '' || $stem === '.' || $stem === '..'
        || preg_match('/[\\\\\/\\x00]/', $stem)) {
        return false;
    }

    $realJobDir = realpath($jobDir);
    $realPdf = realpath($pdfPath);
    $realSignature = realpath($signaturePath);
    if ($realJobDir === false || $realPdf === false || $realSignature === false
        || !str_starts_with($realPdf, $realJobDir . DIRECTORY_SEPARATOR)
        || !str_starts_with($realSignature, $realJobDir . DIRECTORY_SEPARATOR)
        || !is_file($realPdf) || !is_file($realSignature)) {
        return false;
    }

    $pdfEntry = $stem . '.pdf';
    $signatureEntry = $stem . '.p7s';
    $archivePath = $realJobDir . DIRECTORY_SEPARATOR . $stem . '.zip';
    if (file_exists($archivePath)) {
        return false;
    }

    try {
        $temporaryPath = $realJobDir . DIRECTORY_SEPARATOR . '.cades-' . bin2hex(random_bytes(12)) . '.tmp.zip';
    } catch (Throwable $error) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        @unlink($temporaryPath);
        return false;
    }

    $added = $zip->addFile($realPdf, $pdfEntry)
        && $zip->addFile($realSignature, $signatureEntry);
    $closed = $zip->close();
    if (!$added || !$closed) {
        @unlink($temporaryPath);
        return false;
    }

    $check = new ZipArchive();
    $opened = $check->open($temporaryPath, ZipArchive::RDONLY);
    $valid = $opened === true
        && $check->numFiles === 2
        && $check->locateName($pdfEntry) !== false
        && $check->locateName($signatureEntry) !== false;
    if ($opened === true) {
        $check->close();
    }
    if (!$valid || !@rename($temporaryPath, $archivePath)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}
