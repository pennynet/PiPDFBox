<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

$configFile = '/etc/pipdfbox/config.php';

if (!is_file($configFile)) {
    http_response_code(503);
    exit('PiPDFBox ist noch nicht eingerichtet. Bitte install.sh ausführen.');
}

$config = require $configFile;
$dataDir = $config['data_dir'];

$tsaSettingsFile = rtrim($dataDir, '/') . '/tsa-servers.json';
$defaultTsaSettings = [
    'selected' => 'balstamp',
    'servers' => [
        'balstamp' => [
            'name' => 'BalTstamp',
            'url' => 'http://tsa2.baltstamp.lt',
        ],
    ],
];
$tsaSettings = $defaultTsaSettings;
if (is_file($tsaSettingsFile)) {
    $storedTsaSettings = json_decode((string)file_get_contents($tsaSettingsFile), true);
    if (is_array($storedTsaSettings) && isset($storedTsaSettings['servers']) && is_array($storedTsaSettings['servers'])) {
        $tsaSettings['servers'] = $storedTsaSettings['servers'] + $defaultTsaSettings['servers'];
        $tsaSettings['selected'] = (string)($storedTsaSettings['selected'] ?? 'balstamp');
        if (!isset($tsaSettings['servers'][$tsaSettings['selected']])) {
            $tsaSettings['selected'] = 'balstamp';
        }
    }
}
$selectedTsaUrl = (string)$tsaSettings['servers'][$tsaSettings['selected']]['url'];

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$error = (string)($_SESSION['flash_error'] ?? '');
$notice = (string)($_SESSION['flash_notice'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_notice']);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Startet den PDF-Worker.
 * Bei Erfolg enthält $output ausschließlich den Dateinamen aus stdout.
 * Warnungen aus stderr werden bei Erfolg ignoriert.
 */
function runWorker(array $args, string &$output, bool $signing = false): int {
    $script = $signing ? '/opt/pipdfbox/opensc.py' : '/opt/pipdfbox/worker.py';
    $cmd = array_merge(
        ['/usr/bin/python3', $script],
        $args
    );

    // stderr in eine Datei schreiben, damit umfangreiche OCR-Fortschrittsmeldungen
    // die Worker-Pipe nicht füllen und den Prozess blockieren.
    $stderrFile = tempnam(sys_get_temp_dir(), 'pipdfbox-worker-');
    if ($stderrFile === false) {
        $output = 'Temporäre Worker-Datei konnte nicht angelegt werden.';
        return 1;
    }

    $p = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w']
        ],
        $pipes
    );

    if (!is_resource($p)) {
        @unlink($stderrFile);
        $output = 'Worker konnte nicht gestartet werden.';
        return 1;
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $code = proc_close($p);
    $stderr = is_file($stderrFile) ? (string)file_get_contents($stderrFile) : '';
    @unlink($stderrFile);

    if ($code === 0) {
        $output = trim($stdout);
    } else {
        $output = trim($stderr . "\n" . $stdout);
    }

    return $code;
}

/* Anmeldung */
if (empty($_SESSION['logged_in'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {

        if (!hash_equals(
            $_SESSION['csrf'],
            (string)($_POST['login_csrf'] ?? '')
        )) {
            $error = 'Sitzung abgelaufen. Bitte Seite neu laden.';
        } elseif (password_verify(
            (string)$_POST['password'],
            $config['password_hash']
        )) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            header('Location: /');
            exit;
        } else {
            $error = 'Passwort falsch.';
        }
    }
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>PiPDFBox – Anmeldung</title>
        <link rel="stylesheet" href="/assets/style.css?v=28">
    </head>
    <body>
        <main class="login">
            <div class="brand">
                <span class="logo">PDF</span>
                <div>
                    <h1>PiPDFBox <span class="version">v1.0.0</span></h1>
                    <p>Lokale PDF-Werkstatt</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="login_csrf" value="<?= h($_SESSION['csrf']) ?>">
                <label for="password">Passwort</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autofocus
                >
                <button class="primary" type="submit">Anmelden</button>
            </form>

            <p class="muted">
                Alle Dateien bleiben auf diesem Raspberry Pi.
            </p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/* Zeitserver-Einstellungen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tsa') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['flash_error'] = 'Sitzung abgelaufen. Bitte Seite neu laden.';
    } else {
        $choice = (string)($_POST['tsa_server'] ?? 'balstamp');
        if ($choice === '__new__') {
            $name = trim((string)($_POST['tsa_new_name'] ?? ''));
            $url = trim((string)($_POST['tsa_new_url'] ?? ''));
            $parts = parse_url($url);
            $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false
                && is_array($parts)
                && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
                && !empty($parts['host'])
                && !isset($parts['user'])
                && !isset($parts['pass'])
                && !isset($parts['fragment']);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 60 || !$validUrl || mb_strlen($url, 'UTF-8') > 500) {
                $_SESSION['flash_error'] = 'Bitte eine Bezeichnung und eine gültige HTTP-/HTTPS-Zeitserver-URL ohne Zugangsdaten eingeben.';
            } else {
                $choice = 'custom_' . bin2hex(random_bytes(6));
                $tsaSettings['servers'][$choice] = ['name' => $name, 'url' => $url];
            }
        }
        if (!isset($_SESSION['flash_error'])) {
            if (!isset($tsaSettings['servers'][$choice])) {
                $_SESSION['flash_error'] = 'Der ausgewählte Zeitserver ist ungültig.';
            } else {
                $tsaSettings['selected'] = $choice;
                $json = json_encode($tsaSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $tmp = $tsaSettingsFile . '.tmp';
                if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $tsaSettingsFile)) {
                    @unlink($tmp);
                    $_SESSION['flash_error'] = 'Zeitserver-Einstellungen konnten nicht gespeichert werden.';
                } else {
                    $_SESSION['flash_notice'] = 'Zeitserver-Einstellungen gespeichert.';
                }
            }
        }
    }
    header('Location: /', true, 303);
    exit;
}

/* PDF-Verarbeitung */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Ein Ergebnis aus einer früheren Sitzung darf bei einem neuen Auftrag
    // niemals weiterhin als aktuelles Ergebnis angezeigt werden.
    unset($_SESSION['result']);

    if (!hash_equals(
        $_SESSION['csrf'],
        (string)($_POST['csrf'] ?? '')
    )) {
        $error = 'Sitzung abgelaufen. Bitte Seite neu laden.';
    } else {

        $op = (string)$_POST['action'];
        $allowed = ['merge', 'split', 'interleave', 'compress', 'number', 'pdfa', 'ocr', 'sign'];

        if (!in_array($op, $allowed, true)) {
            $error = 'Unbekannte Aktion.';
        } else {

            $files = $_FILES['pdfs'] ?? null;

            $valid = [];

            if (!$files || !isset($files['name']) || !is_array($files['name'])) {
                $error = 'Bitte PDF-Dateien auswählen.';
            } else {

                $count = count($files['name']);

                if ($count > 100) {
                    $error = 'Maximal 100 Dateien pro Auftrag.';
                }

                $totalBytes = 0;

                for ($i = 0; $i < $count && !$error; $i++) {

                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $error = 'Upload fehlgeschlagen (Code ' . $files['error'][$i] . ').';
                        break;
                    }

                    if ($files['size'][$i] > 400 * 1024 * 1024) {
                        $error = 'Eine Datei überschreitet 400 MB.';
                        break;
                    }

                    $totalBytes += (int)$files['size'][$i];

                    if ($totalBytes > 800 * 1024 * 1024) {
                        $error = 'Alle Dateien zusammen dürfen maximal 800 MB groß sein.';
                        break;
                    }

                    $tmp = $files['tmp_name'][$i];

                    $fh = fopen($tmp, 'rb');
                    $sig = $fh ? fread($fh, 5) : '';

                    if ($fh) {
                        fclose($fh);
                    }

                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

                    if (
                        $sig !== '%PDF-' ||
                        !in_array(
                            $mime,
                            ['application/pdf', 'application/octet-stream'],
                            true
                        )
                    ) {
                        $error = 'Nur gültige PDF-Dateien sind erlaubt.';
                        break;
                    }

                    $valid[] = $tmp;
                }

                if (!$error && count($valid) === 0) {
                    $error = 'Bitte mindestens eine PDF-Datei auswählen.';
                }

                if (!$error && $op === 'interleave' && count($valid) !== 2) {
                    $error = 'Für Vorder-/Rückseiten bitte genau zwei PDFs auswählen.';
                }

                if (!$error && $op === 'merge' && count($valid) < 2) {
                    $error = 'Zum Zusammenführen bitte mindestens zwei PDFs auswählen.';
                }

                if (!$error && $op === 'split' && count($valid) !== 1) {
                    $error = 'Zum Aufteilen bitte genau eine PDF auswählen.';
                }

                if (!$error && $op === 'compress' && count($valid) !== 1) {
                    $error = 'Zum Komprimieren bitte genau eine PDF auswählen.';
                }
                if (!$error && $op === 'number' && count($valid) !== 1) {
                    $error = 'Zum Nummerieren bitte genau eine PDF auswählen.';
                }
                if (!$error && $op === 'sign' && count($valid) !== 1) {
                    $error = 'Es kann immer nur eine PDF-Datei gleichzeitig signiert werden. Bitte genau eine Datei auswählen.';
                }
                if (!$error && in_array($op, ['pdfa', 'ocr'], true) && count($valid) !== 1) {
                    $error = 'Für diese Funktion bitte genau eine PDF auswählen.';
                }

                if (!$error) {

                    $job = bin2hex(random_bytes(16));
                    $dir = $dataDir . '/jobs/' . $job;

                    if (!mkdir($dir, 0700, true)) {
                        $error = 'Auftragsverzeichnis konnte nicht angelegt werden.';
                    }

                    $paths = [];

                    if (!$error) {
                        foreach ($valid as $i => $tmp) {

                            $dest = $dir . '/input_' . ($i + 1) . '.pdf';

                            if (!move_uploaded_file($tmp, $dest)) {
                                $error = 'Datei konnte nicht gespeichert werden.';
                                break;
                            }

                            $paths[] = $dest;
                        }
                    }

                    if (!$error) {

                        $args = $op === 'sign'
                            ? [$paths[0], $dir . '/signiert.pdf']
                            : [$op, $dir];

                        if ($op === 'sign' && ($_POST['visible_stamp'] ?? '') === '1') {
                            $args[] = '--visible-stamp';
                            $args[] = '--tsa-url=' . $selectedTsaUrl;
                        }

                        if ($op === 'merge' && ($_POST['reverse_inputs'] ?? '') === '1') {
                            $args[] = '--reverse-inputs';
                        }

                        if ($op === 'compress') {
                            $profile = (string)($_POST['compress_profile'] ?? 'stark');
                            if (!in_array($profile, ['maximal', 'stark', 'mittel'], true)) {
                                $profile = 'stark';
                            }
                            $args[] = '--profile=' . $profile;
                            if (($_POST['parallel_gs'] ?? '') === '1') {
                                $args[] = '--parallel-gs';
                            }
                        }

                        if ($op === 'number') {
                            $prefix = trim((string)($_POST['number_prefix'] ?? ''));
                            if (mb_strlen($prefix, 'UTF-8') > 150) {
                                $error = 'Der Vorspanntext darf maximal 150 Zeichen lang sein.';
                            } else {
                                $args[] = '--prefix=' . $prefix;
                            }
                        }

                        if ($op === 'pdfa') {
                            $version = (string)($_POST['pdfa_version'] ?? 'pdfa-2');
                            if (!in_array($version, ['pdfa-1', 'pdfa-2', 'pdfa-3'], true)) {
                                $version = 'pdfa-2';
                            }
                            $args[] = '--output-type=' . $version;
                        }
                        if ($op === 'ocr') {
                            $language = (string)($_POST['ocr_language'] ?? 'deu');
                            if (!in_array($language, ['deu', 'eng', 'deu+eng'], true)) {
                                $language = 'deu';
                            }
                            $args[] = '--language=' . $language;
                        }
                        if ($op !== 'sign') {
                            foreach ($paths as $p) {
                                $args[] = $p;
                            }
                        }
                        if ($op === 'interleave' && isset($_POST['reverse_second']) && $_POST['reverse_second'] === '1') {
                            $args[] = '--reverse-second';
                        }

                        $workerOutput = '';
                        $rc = 1;

                        // Globaler Worker-Lock: Auch parallele HTTP-Anfragen
                        // dürfen niemals mehrere PDF-Worker gleichzeitig starten.
                        $lockHandle = @fopen(rtrim($dataDir, '/') . '/worker.lock', 'c');
                        if ($lockHandle === false) {
                            $error = 'Worker-Sperre konnte nicht geöffnet werden.';
                        } else {
                            if (!flock($lockHandle, LOCK_EX)) {
                                $error = 'Worker-Sperre konnte nicht gesetzt werden.';
                            } else {
                                try {
                                    // Eine weitere Anfrage wartet hier, bis der
                                    // laufende PDF-Auftrag beendet ist.
                                    $rc = runWorker($args, $workerOutput, $op === 'sign');
                                } finally {
                                    flock($lockHandle, LOCK_UN);
                                }
                            }
                            fclose($lockHandle);
                        }

                        if (!$error && $rc === 0) {

                            $out = trim($workerOutput);
                            $base = basename($out);

                            if (
                                $out !== '' &&
                                $base === $out &&
                                is_file($dir . '/' . $base)
                            ) {
                                $_SESSION['result'] = [
                                    'job' => $job,
                                    'file' => $base,
                                    'name' => $base,
                                    'action' => $op
                                ];

                                $notice = $op === 'sign'
                                    ? 'PDF wurde erfolgreich signiert.'
                                    : 'Verarbeitung abgeschlossen.';
                            } else {
                                $error = 'Die Verarbeitung lieferte keine Ausgabedatei.';
                            }

                        } elseif (!$error) {
                            $error = ($op === 'sign'
                                ? 'PDF-Signierung fehlgeschlagen: '
                                : 'PDF-Verarbeitung fehlgeschlagen: ') .
                                ($workerOutput ?: 'Unbekannter Fehler');
                        }
                    }
                }
            }
        }
    }
    // Post/Redirect/Get: Browser-Refresh darf keinen Auftrag erneut starten.
    $_SESSION['flash_error'] = $error;
    $_SESSION['flash_notice'] = $notice;
    header('Location: /', true, 303);
    exit;
}

$result = $_SESSION['result'] ?? null;
?>

<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PiPDFBox</title>
    <link rel="stylesheet" href="/assets/style.css?v=43">
</head>
<body>

<header>
    <div class="brand">
        <span class="logo">PDF</span>
        <div class="brand-copy">
            <div class="brand-title-row">
                <h1>PiPDFBox <span class="version">v1.0.0</span></h1>
                <a class="github-link" href="https://github.com/pennynet/PiPDFBox" target="_blank" rel="noopener noreferrer">GitHub</a>
            </div>
            <p>Lokale PDF-Werkstatt</p>
        </div>
    </div>

    <nav class="header-actions">
        <a class="settings-link" href="#" id="settings-link">Einstellungen</a>
        <a class="signature-check-link" href="#" id="signature-check-link">Signatur prüfen</a>
        <a class="help-link" href="/handbuch.php" target="_blank" rel="noopener noreferrer">Hilfe</a>
        <a class="logout" href="/?logout=1">Abmelden</a>
    </nav>
</header>

<dialog id="settings-menu-dialog" class="settings-dialog" aria-labelledby="settings-menu-title">
    <div class="settings-menu-body">
        <h3 id="settings-menu-title">Einstellungen</h3>
        <button type="button" class="settings-menu-option" id="open-tsa-settings">Zeitstempelserver konfigurieren</button>
        <button type="button" class="settings-menu-option" id="open-sealium-settings">Sealium API konfigurieren</button>
        <div class="dialog-actions"><button type="button" class="dialog-cancel" id="settings-menu-close">Schließen</button></div>
    </div>
</dialog>

<dialog id="sealium-dialog" class="settings-dialog" aria-labelledby="sealium-dialog-title">
    <form id="sealium-settings-form" autocomplete="off">
        <h3 id="sealium-dialog-title">Sealium API konfigurieren</h3>
        <p id="sealium-key-status" class="settings-status">Status wird geladen …</p>
        <label for="sealium-api-key">API-Schlüssel</label>
        <input type="password" id="sealium-api-key" name="api_key" autocomplete="new-password" maxlength="512" placeholder="Sealium API-Schlüssel eingeben" required>
        <p class="settings-help">Ein vorhandener Schlüssel wird aus Sicherheitsgründen nicht angezeigt. Ein neuer Schlüssel ersetzt den bisherigen.</p>
        <div id="sealium-settings-message" class="settings-message" role="status" aria-live="polite"></div>
        <div class="dialog-actions">
            <button type="button" class="dialog-cancel" id="sealium-dialog-cancel">Abbrechen</button>
            <button type="submit" class="primary" id="sealium-save-button">Speichern</button>
        </div>
    </form>
</dialog>

<dialog id="sign-limit-dialog" class="settings-dialog" aria-labelledby="sign-limit-title">
    <div>
        <h3 id="sign-limit-title">PDF signieren</h3>
        <p>Es kann nur eine Datei gleichzeitig signiert werden.</p>
        <div class="dialog-actions">
            <button type="button" class="primary" id="sign-limit-close">OK</button>
        </div>
    </div>
</dialog>

<dialog id="signature-dialog" class="signature-dialog signature-report-dialog" aria-labelledby="signature-dialog-title">
    <div class="signature-dialog-body">
        <h2 id="signature-dialog-title">Signaturprüfung</h2>
        <div id="signature-result" aria-live="polite">Noch keine Prüfung durchgeführt.</div>
        <div class="dialog-actions">
            <button type="button" class="dialog-cancel" id="signature-back">Zurück</button>
            <button type="button" class="primary" id="signature-print">Drucken</button>
        </div>
    </div>
</dialog>

<main class="wrap">

    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="success"><?= h($notice) ?></div>
    <?php endif; ?>

    <form class="panel" method="post" enctype="multipart/form-data">

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['csrf']) ?>"
        >

        <div class="drop" id="pdf-dropzone">
            <div class="upload-prompt" id="upload-prompt">
                <span class="uploadicon">↑</span>
                <strong>PDF-Dateien hier ablegen</strong>
                <span>oder klicken, um Dateien auszuwählen</span>
                <small>PDF · maximal 100 Dateien, 400 MB je Datei, 800 MB je Auftrag</small>
            </div>
            <input
                id="pdfs"
                type="file"
                name="pdfs[]"
                accept="application/pdf,.pdf"
                multiple
                required
            >
            <div id="filelist" class="pdf-file-grid" aria-live="polite"
                <?php if ($result && is_file($dataDir . '/jobs/' . $result['job'] . '/' . $result['file'])): ?>
                    data-result-name="<?= h($result['name']) ?>"
                    data-result-url="/download.php?job=<?= h($result['job']) ?>&file=<?= rawurlencode($result['file']) ?>"
                    data-result-action="<?= h((string)($result['action'] ?? '')) ?>"
                    data-result-job="<?= h($result['job']) ?>"
                    data-result-file="<?= h($result['file']) ?>"
                    data-result-csrf="<?= h($_SESSION['csrf']) ?>"
                <?php endif; ?>>
                <span class="pdf-empty">Noch keine Dateien ausgewählt</span>
            </div>
        </div>

        <dialog id="pdf-viewer-dialog" class="pdf-viewer-dialog">
            <div class="pdf-viewer-head">
                <strong id="pdf-viewer-title">PDF-Vorschau</strong>
                <button type="button" class="dialog-cancel" id="pdf-viewer-close">Schließen</button>
            </div>
            <iframe id="pdf-viewer-frame" title="PDF-Vorschau"></iframe>
        </dialog>

        <dialog id="zip-browser-dialog" class="pdf-viewer-dialog zip-browser-dialog" aria-labelledby="zip-browser-title">
            <div class="pdf-viewer-head">
                <strong id="zip-browser-title">ZIP-Inhalt</strong>
                <button type="button" class="dialog-cancel" id="zip-browser-close">Schließen</button>
            </div>
            <div class="zip-browser-toolbar">
                <button type="button" class="dialog-cancel" id="zip-browser-up" disabled>↑ Übergeordneter Ordner</button>
                <span id="zip-browser-path">/</span>
                <button type="button" class="dialog-cancel zip-browser-import" id="zip-browser-import" disabled>Auswahl übernehmen (0)</button>
            </div>
            <div id="zip-browser-list" class="zip-browser-list" aria-live="polite"></div>
        </dialog>

        <h2 class="tools-heading">PDF-Aufgabe auswählen</h2>

        <div class="tool-grid">

            <section class="tool-card tool-merge">
                <div class="tool-icon">▣</div>
                <div class="tool-content">
                    <h3>PDFs zusammenführen</h3>
                    <p>Mehrere PDFs zu einer Datei verbinden.</p>
                    <label class="option-check">
                        <input type="checkbox" name="reverse_inputs" value="1">
                        Reihung umkehren
                    </label>
                    <button class="task-button" type="submit" name="action" value="merge">
                        PDFs zusammenführen
                    </button>
                </div>
            </section>

            <section class="tool-card tool-split">
                <div class="tool-icon">✂</div>
                <div class="tool-content">
                    <h3>PDF aufteilen</h3>
                    <p>Eine PDF in einzelne Seiten zerlegen (ZIP).</p>
                    <button class="task-button" type="submit" name="action" value="split">
                        PDF aufteilen
                    </button>
                </div>
            </section>

            <section class="tool-card tool-interleave">
                <div class="tool-icon">▱</div>
                <div class="tool-content">
                    <h3>PDFs verschachteln</h3>
                    <p>Zwei PDFs abwechselnd kombinieren (Vorder-/Rückseiten).</p>
                    <label class="option-check">
                        <input type="checkbox" name="reverse_second" value="1">
                        Rückseiten-PDF umkehren
                    </label>
                    <button class="task-button" type="submit" name="action" value="interleave">
                        PDFs verschachteln
                    </button>
                </div>
            </section>

            <section class="tool-card tool-qes">
                <div class="tool-icon">✍</div>
                <div class="tool-content">
                    <h3>PDF signieren</h3>
                    <p>PDF qualifiziert elektronisch signieren.</p>
                    <label class="option-check">
                        <input type="checkbox" name="visible_stamp" value="1" id="visible_stamp">
                        Stempel und Zeitserver anfügen
                    </label>
                    <button class="task-button" type="submit" name="action" value="sign">
                        PDF signieren
                    </button>
                </div>
            </section>

            <section class="tool-card tool-number">
                <div class="tool-icon">☷</div>
                <div class="tool-content">
                    <h3>PDF nummerieren</h3>
                    <p>Seitennummern mit individuellem Vorspann hinzufügen.</p>
                    <label class="option-label" for="number_prefix">
                        Vorspann für die Seitennummerierung
                    </label>
                    <input type="text" id="number_prefix" name="number_prefix"
                           maxlength="150" placeholder="z. B. Vertraulich – Projektakte">
                    <button class="task-button" type="submit" name="action" value="number">
                        PDF nummerieren
                    </button>
                </div>
            </section>

            <section class="tool-card tool-compress">
                <div class="tool-icon">⤢</div>
                <div class="tool-content">
                    <h3>PDF komprimieren</h3>
                    <p>Dateigröße reduzieren und Qualität auswählen.</p>
                    <label class="option-check" for="parallel_gs">
                        <input
                            type="checkbox"
                            id="parallel_gs"
                            name="parallel_gs"
                            value="1"
                            <?= !empty($config['parallel_gs']) ? 'checked' : '' ?>
                        >
                        Parallelverarbeitung mit Ghostscript
                    </label>
                    <label class="option-label" for="compress_profile">Komprimierungsstärke</label>
                    <select id="compress_profile" name="compress_profile">
                        <option value="maximal">Maximal – kleinste Datei</option>
                        <option value="stark" selected>Stark – gute Balance</option>
                        <option value="mittel">Mittel – bessere Bildqualität</option>
                    </select>
                    <button class="task-button" type="submit" name="action" value="compress">
                        PDF komprimieren
                    </button>
                </div>
            </section>

            <section class="tool-card tool-pdfa">
                <div class="tool-icon">▤</div>
                <div class="tool-content">
                    <h3>PDF archivieren</h3>
                    <p>PDF für die Langzeitarchivierung umwandeln.</p>
                    <label class="option-label" for="pdfa_version">PDF/A-Version</label>
                    <select id="pdfa_version" name="pdfa_version">
                        <option value="pdfa-1">PDF/A-1b – ältere Archivsysteme</option>
                        <option value="pdfa-2" selected>PDF/A-2b – empfohlen</option>
                        <option value="pdfa-3">PDF/A-3b – für eingebettete Dateien</option>
                    </select>
                    <button class="task-button" type="submit" name="action" value="pdfa">PDF archivieren</button>
                </div>
            </section>

            <section class="tool-card tool-ocr">
                <div class="tool-icon">⌕</div>
                <div class="tool-content">
                    <h3>PDF Texterkennung</h3>
                    <p>Scan-PDFs durchsuchbar machen.</p>
                    <label class="option-label" for="ocr_language">Erkennungssprache</label>
                    <select id="ocr_language" name="ocr_language">
                        <option value="deu" selected>Deutsch</option>
                        <option value="eng">Englisch</option>
                        <option value="deu+eng">Deutsch + Englisch</option>
                    </select>
                    <button class="task-button" type="submit" name="action" value="ocr">PDF Texterkennung starten</button>
                </div>
            </section>

        </div>



        <p class="muted center processing-hint">
            Bitte während der Verarbeitung das Fenster geöffnet lassen.
        </p>

    </form>

        <dialog id="tsa-dialog" class="settings-dialog" aria-labelledby="tsa-dialog-title">
            <form method="post" class="tsa-settings-form">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save_tsa">
                <h3 id="tsa-dialog-title">Zeitserver auswählen</h3>
                <label for="tsa_server">Zeitstempelserver</label>
                <select id="tsa_server" name="tsa_server">
                    <?php foreach ($tsaSettings['servers'] as $serverId => $server): ?>
                        <option value="<?= h((string)$serverId) ?>" <?= $serverId === $tsaSettings['selected'] ? 'selected' : '' ?>>
                            <?= h((string)$server['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__new__">Neuen Zeitserver eintragen …</option>
                </select>
                <div id="tsa-new-fields" hidden>
                    <label for="tsa_new_name">Bezeichnung</label>
                    <input type="text" id="tsa_new_name" name="tsa_new_name" maxlength="60" placeholder="z. B. Mein Zeitserver">
                    <label for="tsa_new_url">Zeitserver-URL</label>
                    <input type="url" id="tsa_new_url" name="tsa_new_url" maxlength="500" placeholder="https://tsa.example.com">
                </div>
                <div class="dialog-actions">
                    <button type="button" class="dialog-cancel" id="tsa-dialog-cancel">Abbrechen</button>
                    <button type="submit" class="primary">Speichern</button>
                </div>
            </form>
        </dialog>

    <footer>
        PiPDFBox · Raspberry Pi · Dateien werden automatisch nach 24 Stunden gelöscht.
    </footer>

</main>

<script src="/assets/app.js?v=31"></script>

</body>
</html>
