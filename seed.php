<?php

declare(strict_types=1);

// Seeds default users, mirroring server/seed.js in the Node app.
// Run in Docker:  docker compose exec app php seed.php
// Run locally:    php seed.php

require_once __DIR__ . '/server/db.php';

$users = [
    ['username' => 'admin',     'password' => 'admin123',   'role' => 'team',     'department' => 'admin'],
    ['username' => 'service',   'password' => 'service123', 'role' => 'team',     'department' => 'service'],
    ['username' => 'customer1', 'password' => 'cust123',    'role' => 'customer', 'department' => null, 'email' => 'customer1@example.com'],
];

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO users (first_name, last_name, username, email, password, role, department)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE id = id',
);

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $stmt->execute([
        $u['username'],
        '',
        $u['username'],
        $u['email'] ?? null,
        $hash,
        $u['role'],
        $u['department'],
    ]);
}

echo "Seeded " . count($users) . " users\n";