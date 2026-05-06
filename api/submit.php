<?php
// ─────────────────────────────────────────────
//  RiderLog — Submit API
//
//  POST /api/submit.php
//  Body: { budget_id, type, date, entries: [...] }
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_method('POST');

$body = get_json_body();
require_fields($body, ['budget_id', 'type', 'date', 'entries']);

$budget_id = (int)    $body['budget_id'];
$type      = sanitize_type($body['type']);
$date      = sanitize_date($body['date']);
$entries   = $body['entries'];

if ($budget_id <= 0)                         respond_error('Invalid budget_id.', 422);
if (!is_array($entries) || !count($entries)) respond_error('entries must be a non-empty array.', 422);

$pdo = get_db();

$stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id AND closed_at IS NULL LIMIT 1');
$stmt->execute([':id' => $budget_id]);
$budget = $stmt->fetch();
if (!$budget) respond_error('Budget not found or already closed.', 404);

$budget_remaining = (float) $budget['remaining'];

// Compute totals
$total_fee  = 0.0;
$total_toll = 0.0;
foreach ($entries as $e) {
    $total_fee  += sanitize_float($e['fee']        ?? 0);
    $total_toll += sanitize_float($e['toll_entry'] ?? 0) + sanitize_float($e['toll_back'] ?? 0);
}

$total_expenses = $total_toll;
$budget_before  = $budget_remaining;
$budget_after   = round($budget_remaining - $total_expenses, 2);

try {
    $pdo->beginTransaction();

    // Insert submission
    $stmt = $pdo->prepare('
        INSERT INTO submissions
            (budget_id, type, date, total_fee, total_toll, total_expenses, budget_before, budget_after)
        VALUES
            (:budget_id, :type, :date, :total_fee, :total_toll, :total_expenses, :budget_before, :budget_after)
    ');
    $stmt->execute([
        ':budget_id'      => $budget_id,
        ':type'           => $type,
        ':date'           => $date,
        ':total_fee'      => $total_fee,
        ':total_toll'     => $total_toll,
        ':total_expenses' => $total_expenses,
        ':budget_before'  => $budget_before,
        ':budget_after'   => $budget_after,
    ]);
    $submission_id = (int) $pdo->lastInsertId();

    // Insert rider entries
    $stmt = $pdo->prepare('
        INSERT INTO rider
            (submission_id, budget_id, type, service, name, vehicle, loc, date, fee, toll_entry, toll_back, photo)
        VALUES
            (:submission_id, :budget_id, :type, :service, :name, :vehicle, :loc, :date, :fee, :toll_entry, :toll_back, :photo)
    ');
    foreach ($entries as $i => $e) {
        $entry_date = isset($e['date']) ? sanitize_date($e['date']) : $date;

        // Support both old `photo` (single) and new `photos` (array) fields
        $incoming_photos = [];
        if (!empty($e['photos']) && is_array($e['photos'])) {
            $incoming_photos = $e['photos'];
        } elseif (!empty($e['photo'])) {
            $incoming_photos = [$e['photo']];
        }
        $photo = handle_photos($incoming_photos, [], $entry_date, $i + 1);
        $stmt->execute([
            ':submission_id' => $submission_id,
            ':budget_id'     => $budget_id,
            ':type'          => $type,
            ':service'       => trim($e['service']  ?? ''),
            ':name'          => trim($e['name']      ?? ''),
            ':vehicle'       => trim($e['vehicle']   ?? ''),
            ':loc'           => trim($e['loc']       ?? ''),
            ':date'          => $entry_date,
            ':fee'           => sanitize_float($e['fee']        ?? 0),
            ':toll_entry'    => sanitize_float($e['toll_entry'] ?? 0),
            ':toll_back'     => sanitize_float($e['toll_back']  ?? 0),
            ':photo'         => $photo,
        ]);
    }

    // Deduct from budget
    $pdo->prepare('UPDATE budgets SET remaining = :remaining WHERE id = :id')
        ->execute([':remaining' => $budget_after, ':id' => $budget_id]);

    // Auto-close if depleted
    if ($budget_after <= 0) {
        $pdo->prepare('UPDATE budgets SET closed_at = NOW() WHERE id = :id')
            ->execute([':id' => $budget_id]);
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    respond_error(PRODUCTION ? 'Database error during submission.' : $e->getMessage(), 500);
}

respond_ok([
    'submission_id'   => $submission_id,
    'budget_id'       => $budget_id,
    'type'            => $type,
    'date'            => $date,
    'entry_count'     => count($entries),
    'total_fee'       => $total_fee,
    'total_toll'      => $total_toll,
    'total_expenses'  => $total_expenses,
    'budget_before'   => $budget_before,
    'budget_after'    => $budget_after,
    'budget_depleted' => $budget_after <= 0,
], 'Submission saved.');
