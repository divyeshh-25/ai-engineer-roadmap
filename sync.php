<?php
/**
 * FDE Tracker - minimal cloud sync endpoint (plain PHP, no framework).
 *
 *   GET  -> returns the stored JSON (404 if nothing stored yet)
 *   POST -> validates and stores the JSON body (max 1 MB)
 *
 * Every request needs:  Authorization: Bearer <secret>
 * Configure the three settings below, then upload this file over HTTPS.
 */

// ---------------------------------------------------------------- 1. CONFIG
// Long random secret. Prefer an environment variable; otherwise paste one here.
// Generate one with:  php -r "echo bin2hex(random_bytes(32));"
$SECRET = getenv('FDE_SYNC_SECRET') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

// The exact origin your index.html is served from (scheme + host, no path, no trailing slash).
$ALLOWED_ORIGIN = getenv('FDE_SYNC_ORIGIN') ?: 'https://your-username.github.io';

// Data file stored next to this script. The bundled .htaccess blocks direct downloads of *.json.
$DATA_FILE = __DIR__ . '/fde-tracker-data.json';

$MAX_BYTES = 1024 * 1024; // 1 MB

// ---------------------------------------------------------------- 2. HELPERS
function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function bearer_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
}

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;  // behind a proxy / CDN
    $host = $_SERVER['SERVER_NAME'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1'], true);                   // local testing only
}

// ---------------------------------------------------------------- 3. HEADERS & CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Vary: Origin');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && hash_equals($ALLOWED_ORIGIN, $origin)) {
    header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 600');
} elseif ($origin !== '') {
    respond(403, ['error' => 'Origin not allowed']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ---------------------------------------------------------------- 4. SECURITY CHECKS
if (!is_https()) respond(403, ['error' => 'HTTPS required']);

if ($SECRET === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET' || strlen($SECRET) < 24) {
    respond(500, ['error' => 'Server not configured: set a secret of at least 24 characters']);
}

$token = bearer_token();
if ($token === '' || !hash_equals($SECRET, $token)) {
    usleep(random_int(300000, 700000)); // slow down guessing
    respond(401, ['error' => 'Unauthorized']);
}

// ---------------------------------------------------------------- 5. GET = read
if ($method === 'GET') {
    if (!is_file($DATA_FILE)) respond(404, ['error' => 'No data stored yet']);
    $fp = fopen($DATA_FILE, 'rb');
    if (!$fp) respond(500, ['error' => 'Could not read data']);
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    // The file only ever contains JSON that this script re-encoded itself.
    http_response_code(200);
    echo $json;
    exit;
}

// ---------------------------------------------------------------- 6. POST = write
if ($method === 'POST') {
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== 0) respond(415, ['error' => 'Content-Type must be application/json']);

    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $MAX_BYTES) respond(413, ['error' => 'Payload too large (max 1 MB)']);

    // Read at most MAX+1 bytes so an oversized body is detected without loading it all.
    $raw = file_get_contents('php://input', false, null, 0, $MAX_BYTES + 1);
    if ($raw === false || $raw === '') respond(400, ['error' => 'Empty body']);
    if (strlen($raw) > $MAX_BYTES) respond(413, ['error' => 'Payload too large (max 1 MB)']);

    $data = json_decode($raw, true, 64);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) respond(400, ['error' => 'Body is not valid JSON']);

    // Minimal shape check: must look like tracker data.
    if (($data['app'] ?? '') !== 'fde-tracker' || !is_int($data['version'] ?? null)
        || !is_string($data['updatedAt'] ?? null) || strtotime($data['updatedAt']) === false
        || !is_array($data['weeks'] ?? null)) {
        respond(422, ['error' => 'JSON is not tracker data']);
    }
    unset($data['sync']); // never store sync secrets, even if a client sends them

    // Re-encode instead of storing the raw input.
    $clean = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    if ($clean === false) respond(400, ['error' => 'Could not encode data']);

    // Atomic write: temp file + rename, under an exclusive lock.
    $lock = fopen($DATA_FILE . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) respond(500, ['error' => 'Could not lock storage']);
    $tmp = $DATA_FILE . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok = file_put_contents($tmp, $clean) !== false && rename($tmp, $DATA_FILE);
    if (!$ok && is_file($tmp)) unlink($tmp);
    @chmod($DATA_FILE, 0600);
    flock($lock, LOCK_UN);
    fclose($lock);
    if (!$ok) respond(500, ['error' => 'Could not save data (check folder permissions)']);

    respond(200, ['ok' => true, 'updatedAt' => $data['updatedAt'], 'bytes' => strlen($clean)]);
}

header('Allow: GET, POST, OPTIONS');
respond(405, ['error' => 'Method not allowed']);
