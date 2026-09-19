<?php
declare(strict_types=1);

/**
 * ROSSWERK Datei-Upload — schlanker Endpunkt ohne Framework.
 * Nimmt Fahrzeugfotos/Dokumente aus dem Framer-Formular entgegen, legt sie ab
 * und liefert einen Abhol-Link zurueck. Der Link wandert als verstecktes
 * Formularfeld in die bestehende Framer-Benachrichtigung — kein SMTP noetig.
 */

const STORE      = '/home/ploi/upload.rosswerk.de/storage';
const MAX_FILES  = 10;
const MAX_BYTES  = 15 * 1024 * 1024;   // 15 MB je Datei
const KEEP_DAYS  = 90;
const ORIGINS    = [
    'https://rosswerk.framer.website',
    'https://rosswerk.de',
    'https://www.rosswerk.de',
    'https://miracle-board-876242.framer.app',
];
const ALLOWED = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf',
];

function cors(): void {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($o, ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function json(int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function safeName(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name) ?? 'datei';
    return mb_substr(trim($name), 0, 80) ?: 'datei';
}

/** Alles aelter als KEEP_DAYS loeschen. Laeuft per Cron und zusaetzlich bei jedem Upload. */
function aufraeumen(): void {
    $grenze = time() - KEEP_DAYS * 86400;
    foreach (glob(STORE . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) > $grenze) continue;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

$pfad   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
cors();

if ($method === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- Gesundheitscheck ---------- */
if ($pfad === '/health') {
    json(200, ['ok' => true, 'dienst' => 'rosswerk-upload', 'zeit' => gmdate('c')]);
}

/* ---------- Upload ---------- */
if ($pfad === '/api/upload') {
    if ($method !== 'POST') json(405, ['ok' => false, 'fehler' => 'Nur POST erlaubt.']);

    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && !in_array($o, ORIGINS, true)) {
        json(403, ['ok' => false, 'fehler' => 'Herkunft nicht erlaubt.']);
    }

    // Bestehenden Stapel weiterbefuellen (Nutzer waehlt Fotos in mehreren Schritten).
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) $id = bin2hex(random_bytes(16));

    $dir = STORE . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        json(500, ['ok' => false, 'fehler' => 'Ablage nicht beschreibbar.']);
    }

    $vorhanden = count(glob($dir . '/*') ?: []);
    $eingang   = $_FILES['files'] ?? null;
    if (!$eingang || !is_array($eingang['name'])) {
        json(400, ['ok' => false, 'fehler' => 'Keine Datei empfangen.']);
    }

    $gespeichert = [];
    $fehler      = [];
    $finfo       = new finfo(FILEINFO_MIME_TYPE);

    foreach ($eingang['name'] as $i => $name) {
        if ($vorhanden + count($gespeichert) >= MAX_FILES) {
            $fehler[] = safeName((string)$name) . ': Mehr als ' . MAX_FILES . ' Dateien sind nicht moeglich.';
            continue;
        }
        $code = $eingang['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($code !== UPLOAD_ERR_OK) {
            $grund = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'groesser als 15 MB.',
                UPLOAD_ERR_PARTIAL                        => 'nur teilweise uebertragen.',
                UPLOAD_ERR_NO_FILE                        => 'keine Datei.',
                default                                   => 'Uebertragung fehlgeschlagen.',
            };
            $fehler[] = safeName((string)$name) . ': ' . $grund;
            continue;
        }
        $tmp = $eingang['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) { $fehler[] = safeName((string)$name) . ': ungueltig.'; continue; }
        if (filesize($tmp) > MAX_BYTES) {
            $fehler[] = safeName((string)$name) . ': groesser als 15 MB.';
            continue;
        }
        $typ = $finfo->file($tmp) ?: '';
        if (!isset(ALLOWED[$typ])) {
            $fehler[] = safeName((string)$name) . ': nur Bilder oder PDF.';
            continue;
        }
        $ziel = sprintf('%s/%02d-%s', $dir, $vorhanden + count($gespeichert) + 1, safeName((string)$name));
        if (!move_uploaded_file($tmp, $ziel)) {
            $fehler[] = safeName((string)$name) . ': konnte nicht abgelegt werden.';
            continue;
        }
        @chmod($ziel, 0640);
        $gespeichert[] = ['name' => basename($ziel), 'bytes' => filesize($ziel), 'typ' => $typ];
    }

    if (!$gespeichert && $fehler) json(422, ['ok' => false, 'fehler' => implode(' ', $fehler)]);

    @touch($dir);
    if (random_int(1, 20) === 1) aufraeumen();

    $basis = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'upload.rosswerk.de');
    json(200, [
        'ok'       => true,
        'id'       => $id,
        'dateien'  => $gespeichert,
        'anzahl'   => $vorhanden + count($gespeichert),
        'link'     => $basis . '/f/' . $id,
        'hinweise' => $fehler,
    ]);
}

/* ---------- Abholseite ---------- */
if (preg_match('#^/f/([a-f0-9]{32})/?$#', $pfad, $m)) {
    $dir = STORE . '/' . $m[1];
    if (!is_dir($dir)) { http_response_code(404); echo 'Nicht gefunden oder abgelaufen.'; exit; }
    $dateien = array_values(array_filter(glob($dir . '/*') ?: [], 'is_file'));
    sort($dateien);
    $ablauf = date('d.m.Y', filemtime($dir) + KEEP_DAYS * 86400);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>ROSSWERK — Kundenupload</title>';
    echo '<style>body{background:#000;color:#fff;font:15px/1.6 system-ui,sans-serif;margin:0;padding:32px}';
    echo 'h1{font-size:20px;letter-spacing:.08em;text-transform:uppercase;margin:0 0 4px}';
    echo 'p{color:#B6BCC9;margin:0 0 24px}a{color:#F2BD91}';
    echo 'ul{list-style:none;padding:0;max-width:640px}li{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:14px 18px;margin:0 0 10px;display:flex;justify-content:space-between;gap:16px}';
    echo 'small{color:#B6BCC9}</style>';
    echo '<h1>Kundenupload</h1><p>' . count($dateien) . ' Datei(en) &middot; verfuegbar bis ' . $ablauf . '</p><ul>';
    foreach ($dateien as $f) {
        $n = rawurlencode(basename($f));
        echo '<li><a href="/f/' . $m[1] . '/' . $n . '">' . htmlspecialchars(basename($f)) . '</a>';
        echo '<small>' . round(filesize($f) / 1048576, 2) . ' MB</small></li>';
    }
    echo '</ul>';
    exit;
}

/* ---------- Einzelne Datei ausliefern ---------- */
if (preg_match('#^/f/([a-f0-9]{32})/(.+)$#', $pfad, $m)) {
    $datei = STORE . '/' . $m[1] . '/' . basename(rawurldecode($m[2]));
    if (!is_file($datei)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($datei) ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($datei));
    header('Content-Disposition: inline; filename="' . basename($datei) . '"');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($datei);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "ROSSWERK Upload-Dienst.\n";
