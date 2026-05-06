<?php
// ─────────────────────────────────────────────
//  RiderLog — Submissions API
//
//  GET    /api/submissions.php?budget_id=3  → list for budget
//  GET    /api/submissions.php?id=42        → single + entries
//  DELETE /api/submissions.php?id=42        → delete (refunds budget)
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$pdo = get_db();

// ── DELETE ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) respond_error('Invalid id.', 422);

    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $sub = $stmt->fetch();
    if (!$sub) respond_error('Submission not found.', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE budgets SET remaining = remaining + :exp
            WHERE id = :bid AND closed_at IS NULL
        ')->execute([':exp' => $sub['total_expenses'], ':bid' => $sub['budget_id']]);

        $pdo->prepare('DELETE FROM submissions WHERE id = :id')->execute([':id' => $id]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respond_error(PRODUCTION ? 'Delete failed.' : $e->getMessage(), 500);
    }
    respond_ok(null, 'Submission deleted and budget refunded.');
}

require_method('GET');

// ── Single submission ─────────────────────────
if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($id <= 0) respond_error('Invalid id.', 422);

    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $sub = $stmt->fetch();
    if (!$sub) respond_error('Submission not found.', 404);

    $stmt = $pdo->prepare('
        SELECT id, type, service, name, vehicle, loc, date,
               fee, toll_entry, toll_back, photo
        FROM rider WHERE submission_id = :id ORDER BY id ASC
    ');
    $stmt->execute([':id' => $id]);
    $entries = $stmt->fetchAll();

    $sub = cast_sub($sub);
    foreach ($entries as &$e) {
        $e['fee']         = (float) $e['fee'];
        $e['toll_entry']  = (float) $e['toll_entry'];
        $e['toll_back']   = (float) $e['toll_back'];
        $photos           = decode_photos($e['photo'] ?? null);
        $e['photo_count'] = count($photos);
        $e['has_photo']   = $e['photo_count'] > 0;
        unset($e['photo']); // don't send raw photo data in the listing
    }
    respond_ok(array_merge($sub, ['entries' => $entries]));
}

// ── List by budget ────────────────────────────
if (isset($_GET['budget_id'])) {
    $bid = (int) $_GET['budget_id'];
    if ($bid <= 0) respond_error('Invalid budget_id.', 422);

    $stmt = $pdo->prepare('
        SELECT s.*,
               (SELECT COUNT(*) FROM rider r WHERE r.submission_id = s.id) AS entry_count
        FROM submissions s
        WHERE s.budget_id = :bid
        ORDER BY s.submitted_at DESC
    ');
    $stmt->execute([':bid' => $bid]);
    $rows = array_map('cast_sub', $stmt->fetchAll());
    respond_ok($rows);
}

respond_error('Missing required parameter: id or budget_id.', 422);

function cast_sub(array $s): array {
    foreach (['total_fee','total_toll','total_expenses','budget_before','budget_after'] as $f)
        if (isset($s[$f])) $s[$f] = (float) $s[$f];
    foreach (['id','budget_id','entry_count'] as $f)
        if (isset($s[$f])) $s[$f] = (int) $s[$f];
    return $s;
}
