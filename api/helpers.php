<?php
// ─────────────────────────────────────────────
//  RiderLog — Shared Helpers
// ─────────────────────────────────────────────

// ── Constants ─────────────────────────────────
if (!defined('DB_HOST'))        define('DB_HOST',        'localhost');
if (!defined('DB_PORT'))        define('DB_PORT',        '3306');
if (!defined('DB_NAME'))        define('DB_NAME',        'accounting');
if (!defined('DB_USER'))        define('DB_USER',        'root');
if (!defined('DB_PASS'))        define('DB_PASS',        '');
if (!defined('DB_CHARSET'))     define('DB_CHARSET',     'utf8mb4');
if (!defined('PHOTO_STORAGE'))  define('PHOTO_STORAGE',  'base64');
if (!defined('UPLOAD_DIR'))     define('UPLOAD_DIR',     __DIR__ . '/../uploads/');
if (!defined('MAX_PHOTO_SIZE')) define('MAX_PHOTO_SIZE', 5 * 1024 * 1024);
if (!defined('PRODUCTION'))     define('PRODUCTION',     false);

// ── CORS Headers ──────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!defined('SKIP_JSON_HEADERS')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

// ── Response helpers ──────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error(string $message, int $code = 400): void {
    respond(['success' => false, 'error' => $message], $code);
}

function respond_ok($data = null, string $message = 'OK'): void {
    $payload = ['success' => true, 'message' => $message];
    if ($data !== null) $payload['data'] = $data;
    respond($payload, 200);
}

// ── Request body ──────────────────────────────
function get_json_body(): array {
    $raw     = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) respond_error('Invalid JSON body.', 400);
    return $decoded;
}

// ── Input validation ──────────────────────────
function require_fields(array $body, array $fields): void {
    foreach ($fields as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            respond_error("Missing required field: {$field}.", 422);
        }
    }
}

function sanitize_float($val): float {
    return round((float) $val, 2);
}

function sanitize_date(string $val): string {
    $d = DateTime::createFromFormat('Y-m-d', $val);
    if (!$d || $d->format('Y-m-d') !== $val) {
        respond_error('Invalid date format. Expected YYYY-MM-DD.', 422);
    }
    return $val;
}

function sanitize_type(string $val): string {
    $val = strtolower(trim($val));
    if (!in_array($val, ['delivery', 'retrieval'], true)) {
        respond_error("Invalid type. Must be 'delivery' or 'retrieval'.", 422);
    }
    return $val;
}

// ── Photo helpers ─────────────────────────────

/**
 * Decode the stored photo column into an array of data-URIs (or file paths).
 * Supports the old single-string format AND the new JSON-array format.
 */
function decode_photos(?string $raw): array {
    if (empty($raw)) return [];
    // Try JSON array first
    $arr = json_decode($raw, true);
    if (is_array($arr)) return $arr;
    // Legacy: single photo string
    return [$raw];
}

/**
 * Encode an array of photo strings into the JSON format for storage.
 */
function encode_photos(array $photos): ?string {
    $photos = array_values(array_filter($photos, fn($p) => !empty($p)));
    if (empty($photos)) return null;
    return json_encode($photos, JSON_UNESCAPED_SLASHES);
}

/**
 * Process a single photo data-URI/value and return the storable string.
 */
function handle_photo(?string $photo_data, string $date, int $row_index): ?string {
    if (empty($photo_data)) return null;
    if ($photo_data === '__keep__') return '__keep__';

    $decoded = null;

    if (str_starts_with($photo_data, 'data:')) {
        [, $encoded] = explode(',', $photo_data, 2);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) return null;
        if (strlen($decoded) > MAX_PHOTO_SIZE) {
            respond_error("Photo for row {$row_index} exceeds the 5 MB limit.", 422);
        }
    }

    if (PHOTO_STORAGE === 'disk') {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        $filename = sprintf('%s_%d_%d.jpg', $date, $row_index, time());
        $filepath = UPLOAD_DIR . $filename;
        $bytes = $decoded ?? base64_decode($photo_data, true);
        if ($bytes === false) return null;
        file_put_contents($filepath, $bytes);
        return 'uploads/' . $filename;
    }

    // base64 mode: store the full data-URI
    return $photo_data;
}

/**
 * Process an array of incoming photo values into a storable JSON string.
 * Each item can be: 'data:...' (new), '__keep__' (placeholder), or null (skip).
 * $existing_photos is the current decoded array for __keep__ resolution.
 */
function handle_photos(array $incoming, array $existing_photos, string $date, int $row_index): ?string {
    $result = [];
    foreach ($incoming as $i => $photo_data) {
        if (empty($photo_data)) continue;
        if ($photo_data === '__keep__') {
            // Preserve existing photo at the same index if it exists
            if (isset($existing_photos[$i])) {
                $result[] = $existing_photos[$i];
            }
        } else {
            $processed = handle_photo($photo_data, $date, $row_index);
            if ($processed !== null) {
                $result[] = $processed;
            }
        }
    }
    return encode_photos($result);
}

// ── Method guard ──────────────────────────────
function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        respond_error('Method not allowed.', 405);
    }
}
