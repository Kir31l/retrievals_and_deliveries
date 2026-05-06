<?php
// ─────────────────────────────────────────────
//  RiderLog — Update Entry API
//
//  POST /api/update_entry.php
//  Body: { id, service, name, vehicle, loc, fee, toll_entry, toll_back, photo }
//
//  Updates a single rider row.
//  photo = '__keep__'  → leave existing photo unchanged
//  photo = null/''     → clear the photo
//  photo = 'data:...'  → replace with new photo
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_method('POST', 'PUT');

$body = get_json_body();
require_fields($body, ['id']);

$rider_id = (int) $body['id'];
if ($rider_id <= 0) respond_error('Invalid id.', 422);

$pdo = get_db();

// Fetch existing row
$stmt = $pdo->prepare('SELECT * FROM rider WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $rider_id]);
$existing = $stmt->fetch();
if (!$existing) respond_error('Rider entry not found.', 404);

// Fetch submission for date reference
$submission_id = (int) $existing['submission_id'];
$date          = $existing['date'];

// Handle photos — supports:
//   photos: [...] — new array of data-URIs / '__keep__' markers
//   photo: '...'  — legacy single photo (wrapped into array)
$existing_photos = decode_photos($existing['photo']);

if (isset($body['photos']) && is_array($body['photos'])) {
    // New multi-photo path
    $photo = handle_photos($body['photos'], $existing_photos, $date, $rider_id);
} elseif (isset($body['photo'])) {
    $photo_input = $body['photo'];
    if ($photo_input === '__keep__') {
        $photo = $existing['photo']; // unchanged
    } elseif (empty($photo_input)) {
        $photo = null; // cleared
    } else {
        // Wrap the single new photo into the array, keeping existing ones after it
        $new_photo = handle_photo($photo_input, $date, $rider_id);
        if ($new_photo !== null) {
            $merged = array_merge([$new_photo], $existing_photos);
            $photo  = encode_photos($merged);
        } else {
            $photo = $existing['photo'];
        }
    }
} else {
    $photo = $existing['photo']; // unchanged
}

// Build updated values
$service    = trim($body['service']    ?? $existing['service']    ?? '');
$name       = trim($body['name']       ?? $existing['name']       ?? '');
$vehicle    = trim($body['vehicle']    ?? $existing['vehicle']    ?? '');
$loc        = trim($body['loc']        ?? $existing['loc']        ?? '');
$fee        = sanitize_float($body['fee']        ?? $existing['fee']);
$toll_entry = sanitize_float($body['toll_entry'] ?? $existing['toll_entry']);
$toll_back  = sanitize_float($body['toll_back']  ?? $existing['toll_back']);

try {
    $pdo->beginTransaction();

    // Update rider row
    $pdo->prepare('
        UPDATE rider SET
            service    = :service,
            name       = :name,
            vehicle    = :vehicle,
            loc        = :loc,
            fee        = :fee,
            toll_entry = :toll_entry,
            toll_back  = :toll_back,
            photo      = :photo
        WHERE id = :id
    ')->execute([
        ':service'    => $service,
        ':name'       => $name,
        ':vehicle'    => $vehicle,
        ':loc'        => $loc,
        ':fee'        => $fee,
        ':toll_entry' => $toll_entry,
        ':toll_back'  => $toll_back,
        ':photo'      => $photo,
        ':id'         => $rider_id,
    ]);

    // Recalculate submission totals from all its rider rows
    if ($submission_id) {
        $stmt = $pdo->prepare('SELECT fee, toll_entry, toll_back FROM rider WHERE submission_id = :sid');
        $stmt->execute([':sid' => $submission_id]);
        $all_rows = $stmt->fetchAll();

        $new_fee  = 0.0;
        $new_toll = 0.0;
        foreach ($all_rows as $r) {
            $new_fee  += (float) $r['fee'];
            $new_toll += (float) $r['toll_entry'] + (float) $r['toll_back'];
        }
        $new_expenses = $new_toll;

        // Get current submission for budget adjustment
        $sub_stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id LIMIT 1');
        $sub_stmt->execute([':id' => $submission_id]);
        $sub = $sub_stmt->fetch();

        $old_expenses    = (float) $sub['total_expenses'];
        $expense_diff    = $new_expenses - $old_expenses;
        $new_budget_after = round((float) $sub['budget_before'] - $new_expenses, 2);

        $pdo->prepare('
            UPDATE submissions SET
                total_fee      = :total_fee,
                total_toll     = :total_toll,
                total_expenses = :total_expenses,
                budget_after   = :budget_after
            WHERE id = :id
        ')->execute([
            ':total_fee'      => $new_fee,
            ':total_toll'     => $new_toll,
            ':total_expenses' => $new_expenses,
            ':budget_after'   => $new_budget_after,
            ':id'             => $submission_id,
        ]);

        // Adjust budget remaining
        $pdo->prepare('
            UPDATE budgets SET remaining = remaining - :diff WHERE id = :bid
        ')->execute([':diff' => $expense_diff, ':bid' => $sub['budget_id']]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    respond_error(PRODUCTION ? 'Update failed.' : $e->getMessage(), 500);
}

respond_ok([
    'id'         => $rider_id,
    'service'    => $service,
    'name'       => $name,
    'vehicle'    => $vehicle,
    'loc'        => $loc,
    'fee'        => $fee,
    'toll_entry' => $toll_entry,
    'toll_back'  => $toll_back,
], 'Entry updated.');
