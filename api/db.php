<?php
// ─────────────────────────────────────────────
//  RiderLog — PDO Connection
// ─────────────────────────────────────────────
require_once __DIR__ . '/helpers.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        $msg = PRODUCTION ? 'Database connection failed.' : $e->getMessage();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    return $pdo;
}
