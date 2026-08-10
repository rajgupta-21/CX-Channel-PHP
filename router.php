<?php

declare(strict_types=1);

// Router for `php -S localhost:3000 router.php` in CX-Channel-PHP.
// Routes /api/* and /uploads/* to server/index.php, serves static frontend
// files from the project root.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/') || str_starts_with($path, '/uploads/') || str_starts_with($path, '/auth/')) {
    require __DIR__ . '/server/index.php';
    return true;
}

if ($path === '/' || $path === '') {
    readfile(__DIR__ . '/landing.html');
    return true;
}

$file = realpath(__DIR__ . $path);
if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
    return false;
}

readfile(__DIR__ . '/landing.html');
return true;