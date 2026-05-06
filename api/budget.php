<?php
// ─────────────────────────────────────────────
//  RiderLog — Budget API
//
//  GET  /api/budget.php           → active budget (or null)
//  GET  /api/budget.php?all=1     → all budgets
//  POST /api/budget.php           → open new budget { amount, notes? }
//  POST /api/budget.php?close=1   → close active budget
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$pdo = get_db();

// ── GET ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (!empty($_GET['all'])) {
        $stmt = $pdo->query('
            SELECT b.*,
                COUNT(s.id)                        AS submission_count,
                COALESCE(SUM(s.total_expenses), 0) AS total_spent
            FROM budgets b
            LEFT JOIN submissions s ON s.budget_id = b.id
            GROUP BY b.id
            ORDER BY b.opened_at DESC
        ');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']               = (int)   $r['id'];
            $r['initial_amount']   = (float) $r['initial_amount'];
            $r['remaining']        = (float) $r['remaining'];
            $r['submission_count'] = (int)   $r['submission_count'];
            $r['total_spent']      = (float) $r['total_spent'];
            $r['is_active']        = is_null($r['closed_at']);
        }
        respond_ok($rows);
    }

    $stmt   = $pdo->query('SELECT * FROM budgets WHERE closed_at IS NULL ORDER BY opened_at DESC LIMIT 1');
    $budget = $stmt->fetch();
    if (!$budget) respond_ok(null);

    $budget['id']             = (int)   $budget['id'];
    $budget['initial_amount'] = (float) $budget['initial_amount'];
    $budget['remaining']      = (float) $budget['remaining'];
    respond_ok($budget);
}

// ── POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Close active budget
    if (!empty($_GET['close'])) {
        $stmt = $pdo->prepare('
            UPDATE budgets SET closed_at = NOW()
            WHERE closed_at IS NULL
            ORDER BY opened_at DESC
            LIMIT 1
        ');
        $stmt->execute();
        if ($stmt->rowCount() === 0) respond_error('No active budget to close.', 400);
        respond_ok(null, 'Budget closed.');
    }

    // Open new budget
    $body   = get_json_body();
    require_fields($body, ['amount']);
    $amount = sanitize_float($body['amount']);
    if ($amount <= 0) respond_error('amount must be greater than 0.', 422);
    $notes  = trim($body['notes'] ?? '');

    // Auto-close any lingering active budget
    $pdo->exec('UPDATE budgets SET closed_at = NOW() WHERE closed_at IS NULL');

    $stmt = $pdo->prepare('INSERT INTO budgets (initial_amount, remaining, notes) VALUES (:initial_amount, :remaining, :notes)');
    $stmt->execute([':initial_amount' => $amount, ':remaining' => $amount, ':notes' => $notes ?: null]);
    $id = (int) $pdo->lastInsertId();

    respond_ok([
        'id'             => $id,
        'initial_amount' => $amount,
        'remaining'      => $amount,
        'notes'          => $notes ?: null,
        'is_active'      => true,
    ], 'New budget opened.');
}

respond_error('Method not allowed.', 405);
