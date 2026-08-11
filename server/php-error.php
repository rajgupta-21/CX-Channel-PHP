<?php

declare(strict_types=1);

/**
 * php-error.php — global error & exception handler for the FASCAL PHP backend.
 *
 * Register it on the very first line of the entry point (server/index.php):
 *
 *     require_once __DIR__ . '/php-error.php';
 *
 * What it does:
 *  - Converts PHP errors/warnings/notices and uncaught exceptions into proper
 *    responses instead of raw output or a white screen.
 *  - API routes (/api/* and /auth/*) get a JSON body in the exact shape the
 *    frontend already parses: {"message": "..."} plus the right HTTP status.
 *  - Everything else gets a plain-text error page.
 *  - Every occurrence is appended to server/logs/php-error.log (auto-created),
 *    so support can debug 500s without phoning home.
 *  - Full stack details only leak when APP_DEBUG=true; in production clients
 *    receive a generic message and the real detail stays in the log file.
 */

// PHP hoists function declarations before execution, so a function_exists()
// guard is always true here -- use a constant-based guard instead.
if (defined('PHP_ERROR_LOADED')) {
    return;
}
define('PHP_ERROR_LOADED', true);

// --------------------------------------------------------------- helpers

function php_error_log_dir(): string
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return sys_get_temp_dir();
    }
    return $dir;
}

function php_error_log_path(): string
{
    return php_error_log_dir() . '/php-error.log';
}

function php_error_debug(): bool
{
    if (function_exists('env')) {
        return env('APP_DEBUG') === 'true';
    }
    return false;
}

function php_error_is_api(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return str_starts_with($path, '/api') || str_starts_with($path, '/auth') || str_starts_with($path, '/uploads');
}

function php_error_writelog(string $line): void
{
    @file_put_contents(php_error_log_path(), $line, FILE_APPEND | LOCK_EX);
}

function php_error_respond(string $message, int $status = 500): never
{
    if (!headers_sent()) {
        http_response_code($status);
        if (php_error_is_api()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $message . "\n";
        }
    }
    exit;
}

// --------------------------------------------------------------- handlers

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    $label = match ($severity) {
        E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'FATAL',
        E_WARNING, E_USER_WARNING                  => 'WARNING',
        default                                    => 'NOTICE',
    };

    php_error_writelog(sprintf(
        "[%s] %s: %s in %s:%d\n",
        date('c'),
        $label,
        $message,
        $file,
        $line
    ));

    if (in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        php_error_respond(
            php_error_debug() ? "$message ($file:$line)" : 'Internal server error.',
            500
        );
    }

    return true;
});

set_exception_handler(function (Throwable $e): void {
    php_error_writelog(sprintf(
        "[%s] EXCEPTION: %s in %s:%d\n%s\n",
        date('c'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    php_error_respond(
        php_error_debug() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Internal server error.',
        $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500
    );
});

register_shutdown_function(function (): void {
    $last = error_get_last();
    if ($last === null) {
        return;
    }
    if (!in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    php_error_writelog(sprintf(
        "[%s] FATAL: %s in %s:%d\n",
        date('c'),
        $last['message'],
        $last['file'],
        $last['line']
    ));

    if (php_error_debug()) {
        php_error_respond($last['message'] . ' (' . basename($last['file']) . ':' . $last['line'] . ')', 500);
    }
});