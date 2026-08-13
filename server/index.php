
text/x-generic index.php ( PHP script, UTF-8 Unicode text )
<?php

declare(strict_types=1);

/**
 * FASCAL PHP front controller — mirrors the Express routes in server/server.js
 * so the frontend keeps calling the same endpoints with the same JSON shapes.
 */

error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/requests.php';
require_once __DIR__ . '/routes/support.php';

ini_set('display_errors', env('APP_DEBUG') === 'true' ? '1' : '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const ROUTES_EXTRA = [];

function is_server_root_page(string $path): bool
{
    return in_array($path, ['/', '/landing.html', '/index.html', '/customer.html', '/login.html', '/signup.html', '/team-signup.html'], true);
}

function server_root_page_file(string $path): ?string
{
    if ($path === '/') {
        return __DIR__ . '/../landing.html';
    }
    return __DIR__ . '/..' . $path;
}

function static_file_route(string $path): bool
{
    if (str_starts_with($path, '/uploads/')) {
        $file = __DIR__ . $path;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $map = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
                'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
                'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
            ];
            header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string) filesize($file));
            readfile($file);
            return true;
        }
        json_response(['message' => 'Route not found.'], 404);
    }

    if (is_server_root_page($path)) {
        $file = server_root_page_file($path);
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        return true;
    }

    if ($path !== '/' && str_contains($path, '.') && !str_contains($path, '/')) {
        $file = __DIR__ . '/..' . $path;
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $map = [
                'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
                'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            ];
            header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }

    return false;
}

function resolve_route(): ?array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
   $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$basePath = '/CX-Channel-PHP';

if (str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}

$path = '/' . ltrim($path, '/');

    $routes = [
        ['GET',  '/api/requests',                'requests_list'],
        ['GET',  '/api/requests/{id}',           'requests_detail'],
        ['POST', '/api/requests',                'requests_create'],
        ['PUT',  '/api/requests/{id}',           'requests_update'],
        ['DELETE', '/api/requests/{id}',         'requests_delete'],
        ['GET',  '/api/export/csv',              'requests_export_csv'],
        ['GET',  '/api/stats',                   'requests_stats'],
        ['POST', '/api/auth/login',              'auth_login'],
        ['POST', '/api/auth/customer-login',     'auth_customer_login'],
        ['POST', '/auth/signup',                 'auth_signup'],
        ['GET',  '/api/support',                 'support_list'],
        ['GET',  '/api/support/stats',           'support_stats'],
        ['GET',  '/api/support/export/csv',      'support_export_csv'],
        ['GET',  '/api/support/{id}',            'support_detail'],
        ['POST', '/api/support',                 'support_create'],
        ['PUT',  '/api/support/{id}',            'support_update'],
        ['DELETE', '/api/support/{id}',          'support_delete'],
        ['GET',  '/api/test-smtp',               'route_test_smtp'],
        ['GET',  '/api/test-mail',               'route_test_mail'],
    ];

    foreach ($routes as [$routeMethod, $routePath, $handler]) {
        if ($routeMethod !== $method) {
            continue;
        }

        $pattern = preg_replace('/\{[a-zA-Z_]+\}/', '([^/]+)', $routePath);
        if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
            $params = [];
            preg_match_all('/\{([a-zA-Z_]+)\}/', $routePath, $names);
            foreach ($names[1] as $idx => $name) {
                $params[$name] = rawurldecode($matches[$idx + 1] ?? '');
            }
            return ['handler' => $handler, 'params' => $params];
        }
    }

    return null;
}

function route_param(string $name): string
{
    global $_ROUTE_PARAMS;
    return $_ROUTE_PARAMS[$name] ?? '';
}

function set_route_params(array $params): void
{
    $GLOBALS['_ROUTE_PARAMS'] = $params;
}

function route_test_smtp(): never
{
    try {
        $start = microtime(true);
        verify_mailer();
        json_response(['message' => 'SMTP verified']);
    } catch (Throwable $e) {
        $suffix = (microtime(true) - $start > 10) ? ' — SMTP verify timeout' : ' — SMTP verify failed';
        json_error($e->getMessage() . $suffix, 500);
    }
}

function route_test_mail(): never
{
    try {
        $start = microtime(true);
        $result = send_mail([
            'to'      => env('TEAM_EMAIL'),
            'subject' => 'FASCAL test mail',
            'text'    => 'Hello, this is a test mail.',
            'html'    => '<p>Hello, this is a test mail.</p>',
        ]);
        json_response([
            'message'   => 'Mail sent successfully.',
            'messageId' => $result['messageId'] ?? null,
        ]);
    } catch (Throwable $e) {
        json_error($e->getMessage() . (microtime(true) - $start > 10 ? ' — SMTP send timeout' : ''), 500);
    }
}

// ---------------------------------------------------------------

$route = resolve_route();
if ($route !== null) {
    set_route_params($route['params']);
    call_user_func($route['handler']);
    exit;
}

if (static_file_route($path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/')) {
    exit;
}

if (is_server_root_page($path)) {
    exit;
}

json_response(['message' => 'Route not found.'], 404);