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

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$error = '';
$notice = '';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Startet den PDF-Worker.
 * Bei Erfolg enthält $output ausschließlich den Dateinamen aus stdout.
 * Warnungen aus stderr werden bei Erfolg ignoriert.
 */
function runWorker(array $args, string &$output): int {
    $cmd = array_merge(
        ['/usr/bin/python3', '/opt/pipdfbox/worker.py'],
        $args
    );

    $p = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes
    );

    if (!is_resource($p)) {
        $output = 'Worker konnte nicht gestartet werden.';
        return 1;
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($p);

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

        if (password_verify(
            (string)$_POST['password'],
            $config['password_hash']
        )) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            header('Location: /');
            exit;
        }

        $error = 'Passwort falsch.';
    }
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>PiPDFBox – Anmeldung</title>
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body>
        <main class="login">
            <div class="brand">
                <span class="logo">PDF</span>
                <div>
                    <h1>PiPDFBox</h1>
                    <p>Lokale PDF-Werkstatt</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
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
        $allowed = ['merge', 'split', 'interleave', 'compress', 'number'];

        if (!in_array($op, $allowed, true)) {
            $error = 'Unbekannte Aktion.';
        } else {

            $files = $_FILES['pdfs'] ?? null;

            // Temporäre Upload-Diagnose
            if (is_array($files) && isset($files['name'])) {
                foreach ($files['name'] as $i => $originalName) {
                    $tmp = $files['tmp_name'][$i] ?? '';
                    $log = [
                        'time' => date('c'),
                        'name' => $originalName,
                        'size' => $files['size'][$i] ?? null,
                        'tmp' => $tmp,
                        'sha256' => ($tmp && is_uploaded_file($tmp))
                            ? hash_file('sha256', $tmp)
                            : null
                    ];
                    error_log('PiPDFBox UPLOAD: ' . json_encode($log, JSON_UNESCAPED_UNICODE));
                }
            }

            $valid = [];

            if (!$files || !isset($files['name']) || !is_array($files['name'])) {
                $error = 'Bitte PDF-Dateien auswählen.';
            } else {

                $count = count($files['name']);

                if ($count > 10) {
                    $error = 'Maximal 10 Dateien pro Auftrag.';
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

                        $args = [$op, $dir];

                        if ($op === 'compress') {
                            $profile = (string)($_POST['compress_profile'] ?? 'stark');
                            if (!in_array($profile, ['maximal', 'stark', 'mittel'], true)) {
                                $profile = 'stark';
                            }
                            $args[] = '--profile=' . $profile;
                        }

                        if ($op === 'number') {
                            $prefix = trim((string)($_POST['number_prefix'] ?? ''));
                            if (mb_strlen($prefix, 'UTF-8') > 150) {
                                $error = 'Der Vorspanntext darf maximal 150 Zeichen lang sein.';
                            } else {
                                $args[] = '--prefix=' . $prefix;
                            }
                        }

                        foreach ($paths as $p) {
                            $args[] = $p;
                        }
                        if ($op === 'interleave' && isset($_POST['reverse_second']) && $_POST['reverse_second'] === '1') {
                            $args[] = '--reverse-second';
                        }

                        $workerOutput = '';
                        $rc = runWorker($args, $workerOutput);

                        if ($rc === 0) {

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
                                    'name' => $base
                                ];

                                $notice = 'Verarbeitung abgeschlossen.';
                            } else {
                                $error = 'Die Verarbeitung lieferte keine Ausgabedatei.';
                            }

                        } else {
                            $error = 'PDF-Verarbeitung fehlgeschlagen: ' .
                                ($workerOutput ?: 'Unbekannter Fehler');
                        }
                    }
                }
            }
        }
    }
}

$result = $_SESSION['result'] ?? null;
?>

<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PiPDFBox</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<header>
    <div class="brand">
        <span class="logo">PDF</span>
        <div>
            <h1>PiPDFBox</h1>
            <p>Lokale PDF-Werkstatt</p>
        </div>
    </div>

    <a class="logout" href="/?logout=1">Abmelden</a>
</header>

<main class="wrap">

    <section class="intro">
        <h2>PDF-Dateien verarbeiten</h2>
        <p>
            Dateien auswählen, Funktion festlegen und Ergebnis herunterladen.
            Die Verarbeitung erfolgt direkt auf dem Raspberry Pi.
        </p>
    </section>

    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="success"><?= h($notice) ?></div>
    <?php endif; ?>

    <?php
    if (
        $result &&
        is_file($dataDir . '/jobs/' . $result['job'] . '/' . $result['file'])
    ):
    ?>
        <section class="result">
            <div>
                <strong>Fertig!</strong>
                <p><?= h($result['name']) ?></p>
            </div>

            <a
                class="primary download"
                href="/download.php?job=<?= h($result['job']) ?>&file=<?= rawurlencode($result['file']) ?>"
            >
                Download
            </a>
        </section>
    <?php endif; ?>

    <form class="panel" method="post" enctype="multipart/form-data">

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['csrf']) ?>"
        >

        <label class="drop" for="pdfs">
            <span class="uploadicon">↑</span>
            <strong>PDF-Dateien hier ablegen</strong>
            <span>oder klicken, um Dateien auszuwählen</span>
            <small>PDF · maximal 400 MB je Datei, 800 MB je Auftrag</small>

            <input
                id="pdfs"
                type="file"
                name="pdfs[]"
                accept="application/pdf,.pdf"
                multiple
                required
            >
        </label>

        <div id="filelist" class="filelist">
            Noch keine Dateien ausgewählt
        </div>

        <h2 class="tools-heading">PDF-Aufgabe auswählen</h2>

        <div class="tool-grid">

            <section class="tool-card tool-merge">
                <div class="tool-icon">▣</div>
                <div class="tool-content">
                    <h3>PDFs zusammenführen</h3>
                    <p>Mehrere PDFs zu einer Datei verbinden.</p>
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

            <section class="tool-card tool-compress">
                <div class="tool-icon">⤢</div>
                <div class="tool-content">
                    <h3>PDF komprimieren</h3>
                    <p>Dateigröße reduzieren und Qualität auswählen.</p>
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

        </div>

        <p class="muted center processing-hint">
            Bitte während der Verarbeitung das Fenster geöffnet lassen.
        </p>

    </form>

    <footer>
        PiPDFBox · Raspberry Pi · Dateien werden automatisch nach 24 Stunden gelöscht.
    </footer>

</main>

<script src="/assets/app.js?v=6"></script>

</body>
</html>
