<?php
// ─────────────────────────────────────────────
//  RiderLog — Photo API
//  GET /api/photo.php?id=123         → first photo
//  GET /api/photo.php?id=123&idx=0   → photo at index 0 (0-based)
//  GET /api/photo.php?id=123&count=1 → returns JSON { count: N }
// ─────────────────────────────────────────────
define('SKIP_JSON_HEADERS', true);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_method('GET');

$id  = (int) ($_GET['id']  ?? 0);
$idx = (int) ($_GET['idx'] ?? 0);
if ($id <= 0) respond_error('Invalid id.', 422);

$pdo  = get_db();
$stmt = $pdo->prepare('SELECT photo FROM rider WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row)                respond_error('Entry not found.', 404);
if (empty($row['photo'])) respond_error('No photo for this entry.', 404);

// Decode stored value (supports old single-string and new JSON-array format)
$photos = decode_photos($row['photo']);

// If ?count=1 just return the count as JSON
if (!empty($_GET['count'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['count' => count($photos)]);
    exit;
}

if (empty($photos)) respond_error('No photos for this entry.', 404);
if ($idx < 0 || $idx >= count($photos)) respond_error('Photo index out of range.', 404);

$photo = $photos[$idx];

// Disk path
if (!str_starts_with($photo, 'data:')) {
    $path = __DIR__ . '/../../' . ltrim($photo, '/');
    if (!file_exists($path)) respond_error('Photo file not found.', 404);
    header('Content-Type: ' . mime_content_type($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// Base64 data-URI
[$meta, $encoded] = explode(',', $photo, 2);
preg_match('/data:(image\/[a-z+]+);base64/', $meta, $m);
$mime = $m[1] ?? 'image/jpeg';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
echo base64_decode($encoded);
exit;
