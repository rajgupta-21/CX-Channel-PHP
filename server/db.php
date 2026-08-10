<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function parse_database_url(string $url): array
{
    $p = parse_url($url);
    return [
        'host'     => $p['host'] ?? '127.0.0.1',
        'port'     => $p['port'] ?? 3306,
        'user'     => urldecode($p['user'] ?? ''),
        'pass'     => urldecode($p['pass'] ?? ''),
        'dbname'   => trim($p['path'] ?? '', '/'),
        'charset'  => 'utf8mb4',
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $url = env('DATABASE_URL', 'mysql://root:@127.0.0.1:3306/cx_channel');
    $c = parse_database_url($url);

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['dbname'],
        $c['charset'],
    );

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}