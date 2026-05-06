<?php
// ─────────────────────────────────────────────
//  RiderLog — Funds API
//  GET  /api/funds.php?date=YYYY-MM-DD&type=delivery
//  POST /api/funds.php  { date, type, amount }
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = isset($_GET['date']) ? sanitize_date($_GET['date']) : date('Y-m-d');
    $type = isset($_GET['type']) ? sanitize_type($_GET['type']) : 'delivery';

    $stmt = $pdo->prepare('SELECT id, date, type, amount, updated_at FROM funds WHERE date = :date AND type = :type LIMIT 1');
    $stmt->execute([':date' => $date, ':type' => $type]);
    $row = $stmt->fetch();

    if (!$row) respond_ok(['date' => $date, 'type' => $type, 'amount' => 0.0]);
    $row['amount'] = (float) $row['amount'];
    respond_ok($row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    require_fields($body, ['date', 'type', 'amount']);

    $date   = sanitize_date($body['date']);
    $type   = sanitize_type($body['type']);
    $amount = sanitize_float($body['amount']);

    if ($amount < 0) respond_error('amount must be non-negative.', 422);

    $stmt = $pdo->prepare('
        INSERT INTO funds (date, type, amount)
        VALUES (:date, :type, :amount)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = NOW()
    ');
    $stmt->execute([':date' => $date, ':type' => $type, ':amount' => $amount]);
    respond_ok(['date' => $date, 'type' => $type, 'amount' => $amount], 'Funds updated.');
}

respond_error('Method not allowed.', 405);
