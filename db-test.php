<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/server/db.php';

    $pdo = db();

    echo json_encode([
        'success' => true,
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'tables' => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}