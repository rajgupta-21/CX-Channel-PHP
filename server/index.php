<?php

declare(strict_types=1);

/**
 * FASCAL PHP Front Controller
 *
 * Mirrors the Express routes from the original Node application.
 *
 * Production path:
 *
 * https://new.fastech-india.com/CX-Channel-PHP/
 *
 * API:
 *
 * /CX-Channel-PHP/api/...
 *
 * Uploads:
 *
 * /CX-Channel-PHP/uploads/...
 */

error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| Application dependencies
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/requests.php';
require_once __DIR__ . '/routes/support.php';


/*
|--------------------------------------------------------------------------
| PHP error display
|--------------------------------------------------------------------------
|
| APP_DEBUG=true
|     -> show PHP errors
|
| APP_DEBUG=false
|     -> hide PHP errors from users
|
|--------------------------------------------------------------------------
*/

ini_set(
    'display_errors',
    env('APP_DEBUG') === 'true' ? '1' : '0'
);


/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

header('Access-Control-Allow-Origin: *');

header(
    'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS'
);

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization'
);


/*
|--------------------------------------------------------------------------
| OPTIONS / CORS preflight
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'
) {
    http_response_code(204);
    exit;
}


/*
|--------------------------------------------------------------------------
| Application base path
|--------------------------------------------------------------------------
|
| The application is NOT running from:
|
| https://new.fastech-india.com/
|
| It is running from:
|
| https://new.fastech-india.com/CX-Channel-PHP/
|
|--------------------------------------------------------------------------
*/

const APP_BASE_PATH = '/CX-Channel-PHP';


/*
|--------------------------------------------------------------------------
| Frontend pages
|--------------------------------------------------------------------------
*/

function is_server_root_page(string $path): bool
{
    return in_array(
        $path,
        [
            '/',
            '/landing.html',
            '/index.html',
            '/customer.html',
            '/login.html',
            '/create-admin.html',
        ],
        true
    );
}


/*
|--------------------------------------------------------------------------
| Resolve frontend page file
|--------------------------------------------------------------------------
*/

function server_root_page_file(string $path): ?string
{
    if ($path === '/') {
        return __DIR__ . '/../landing.html';
    }

    return __DIR__ . '/..' . $path;
}


/*
|--------------------------------------------------------------------------
| Remove application base path
|--------------------------------------------------------------------------
|
| Example:
|
| /CX-Channel-PHP/api/auth/login
|
| becomes:
|
| /api/auth/login
|
|--------------------------------------------------------------------------
*/

function normalize_application_path(string $path): string
{
    /*
     * Remove query string if present.
     */
    $path = parse_url(
        $path,
        PHP_URL_PATH
    ) ?: '/';


    /*
     * Remove /CX-Channel-PHP
     */
    if (
        str_starts_with(
            $path,
            APP_BASE_PATH
        )
    ) {
        $path = substr(
            $path,
            strlen(APP_BASE_PATH)
        );
    }


    /*
     * Ensure leading slash
     */
    return '/' . ltrim(
        $path,
        '/'
    );
}


/*
|--------------------------------------------------------------------------
| Static file handling
|--------------------------------------------------------------------------
|
| Handles:
|
| /uploads/example.jpg
|
| /login.html
|
| /script.js
|
| /style.css
|
|--------------------------------------------------------------------------
*/

function static_file_route(string $path): bool
{
    /*
     * -------------------------------------------------------------
     * Uploaded files
     * -------------------------------------------------------------
     *
     * __DIR__:
     *
     * /home1/fasbom/new.fastech-india.com/CX-Channel-PHP/server
     *
     * /..:
     *
     * /home1/fasbom/new.fastech-india.com/CX-Channel-PHP
     *
     * Therefore:
     *
     * /uploads/file.jpg
     *
     * becomes:
     *
     * /CX-Channel-PHP/uploads/file.jpg
     *
     * -------------------------------------------------------------
     */

    if (
        str_starts_with(
            $path,
            '/uploads/'
        )
    ) {

        $file = __DIR__ . '/..' . $path;


        /*
         * File does not exist
         */
        if (!is_file($file)) {

            json_response(
                [
                    'message' => 'File not found.'
                ],
                404
            );

            return true;
        }


        /*
         * Determine extension
         */
        $ext = strtolower(
            pathinfo(
                $file,
                PATHINFO_EXTENSION
            )
        );


        /*
         * Allowed MIME types
         */
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',

            'pdf'  => 'application/pdf',

            'txt'  => 'text/plain',

            'csv'  => 'text/csv',
        ];


        /*
         * Content-Type
         */
        header(
            'Content-Type: ' .
            (
                $map[$ext]
                ?? 'application/octet-stream'
            )
        );


        /*
         * Content-Length
         */
        header(
            'Content-Length: ' .
            (string) filesize($file)
        );


        /*
         * Send file
         */
        readfile($file);

        return true;
    }


    /*
     * -------------------------------------------------------------
     * Application root pages
     * -------------------------------------------------------------
     */

    if (
        is_server_root_page($path)
    ) {

        $file = server_root_page_file(
            $path
        );


        if (
            $file !== null &&
            is_file($file)
        ) {

            header(
                'Content-Type: text/html; charset=utf-8'
            );

            readfile($file);

            return true;
        }

        return false;
    }


    /*
     * -------------------------------------------------------------
     * Other static files
     * -------------------------------------------------------------
     *
     * Examples:
     *
     * /script.js
     * /style.css
     * /favicon.png
     *
     * -------------------------------------------------------------
     */

    if (
        $path !== '/' &&
        str_contains($path, '.') &&
        !str_contains($path, '/')
    ) {

        $file = __DIR__ . '/..' . $path;


        if (is_file($file)) {

            $ext = strtolower(
                pathinfo(
                    $file,
                    PATHINFO_EXTENSION
                )
            );


            $map = [
                'html' => 'text/html',
                'css'  => 'text/css',
                'js'   => 'application/javascript',

                'pdf'  => 'application/pdf',

                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];


            header(
                'Content-Type: ' .
                (
                    $map[$ext]
                    ?? 'application/octet-stream'
                )
            );


            header(
                'Content-Length: ' .
                (string) filesize($file)
            );


            readfile($file);

            return true;
        }
    }


    return false;
}


/*
|--------------------------------------------------------------------------
| API route resolver
|--------------------------------------------------------------------------
*/

function resolve_route(): ?array
{
    /*
     * HTTP method
     */
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';


    /*
     * Request URI
     */
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';


    /*
     * Normalize:
     *
     * /CX-Channel-PHP/api/auth/login
     *
     * becomes:
     *
     * /api/auth/login
     */
    $path = normalize_application_path(
        $requestUri
    );


    /*
     * -------------------------------------------------------------
     * Routes
     * -------------------------------------------------------------
     */

    $routes = [

        /*
         * Requests
         */
        [
            'GET',
            '/api/requests',
            'requests_list'
        ],

        [
            'GET',
            '/api/requests/{id}',
            'requests_detail'
        ],

        [
            'POST',
            '/api/requests',
            'requests_create'
        ],

        [
            'PUT',
            '/api/requests/{id}',
            'requests_update'
        ],

        [
            'DELETE',
            '/api/requests/{id}',
            'requests_delete'
        ],


        /*
         * Request exports/statistics
         */
        [
            'GET',
            '/api/export/csv',
            'requests_export_csv'
        ],

        [
            'GET',
            '/api/stats',
            'requests_stats'
        ],


        /*
         * Authentication
         */
        [
            'POST',
            '/api/auth/login',
            'auth_login'
        ],

        [
            'POST',
            '/api/auth/customer-login',
            'auth_customer_login'
        ],


        /*
         * Admin
         */
        [
            'POST',
            '/api/admin/create',
            'admin_create'
        ],


        /*
         * Support
         */
        [
            'GET',
            '/api/support',
            'support_list'
        ],

        [
            'GET',
            '/api/support/stats',
            'support_stats'
        ],

        [
            'GET',
            '/api/support/export/csv',
            'support_export_csv'
        ],

        [
            'GET',
            '/api/support/{id}',
            'support_detail'
        ],

        [
            'POST',
            '/api/support',
            'support_create'
        ],

        [
            'PUT',
            '/api/support/{id}',
            'support_update'
        ],

        [
            'DELETE',
            '/api/support/{id}',
            'support_delete'
        ],


        /*
         * SMTP testing
         */
        [
            'GET',
            '/api/test-smtp',
            'route_test_smtp'
        ],

        [
            'GET',
            '/api/test-mail',
            'route_test_mail'
        ],
    ];


    /*
     * -------------------------------------------------------------
     * Match route
     * -------------------------------------------------------------
     */

    foreach (
        $routes as [
            $routeMethod,
            $routePath,
            $handler
        ]
    ) {

        /*
         * HTTP method must match
         */
        if (
            $routeMethod !== $method
        ) {
            continue;
        }


        /*
         * Convert:
         *
         * /api/requests/{id}
         *
         * into:
         *
         * /api/requests/([^/]+)
         */
        $pattern = preg_replace(
            '/\{[a-zA-Z_]+\}/',
            '([^/]+)',
            $routePath
        );


        /*
         * Check URL
         */
        if (
            preg_match(
                '#^' . $pattern . '$#',
                $path,
                $matches
            )
        ) {

            $params = [];


            /*
             * Extract parameter names
             */
            preg_match_all(
                '/\{([a-zA-Z_]+)\}/',
                $routePath,
                $names
            );


            /*
             * Extract parameter values
             */
            foreach (
                $names[1] as $idx => $name
            ) {

                $params[$name] =
                    rawurldecode(
                        $matches[$idx + 1] ?? ''
                    );
            }


            return [
                'handler' => $handler,
                'params'  => $params,
            ];
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| Route parameter helper
|--------------------------------------------------------------------------
*/

function route_param(string $name): string
{
    global $_ROUTE_PARAMS;

    return $_ROUTE_PARAMS[$name] ?? '';
}


/*
|--------------------------------------------------------------------------
| Set route parameters
|--------------------------------------------------------------------------
*/

function set_route_params(
    array $params
): void {

    $GLOBALS['_ROUTE_PARAMS'] =
        $params;
}


/*
|--------------------------------------------------------------------------
| SMTP test route
|--------------------------------------------------------------------------
*/

function route_test_smtp(): never
{
    try {

        $start = microtime(true);

        verify_mailer();

        json_response(
            [
                'message' =>
                    'SMTP verified'
            ]
        );

    } catch (Throwable $e) {

        $suffix =
            (
                microtime(true) -
                $start
            > 10)

                ? ' — SMTP verify timeout'

                : ' — SMTP verify failed';


        json_error(
            $e->getMessage() . $suffix,
            500
        );
    }
}


/*
|--------------------------------------------------------------------------
| Test mail route
|--------------------------------------------------------------------------
*/

function route_test_mail(): never
{
    try {

        $start = microtime(true);


        $result = send_mail(
            [
                'to' =>
                    env('TEAM_EMAIL'),

                'subject' =>
                    'FASCAL test mail',

                'text' =>
                    'Hello, this is a test mail.',

                'html' =>
                    '<p>Hello, this is a test mail.</p>',
            ]
        );


        json_response(
            [
                'message' =>
                    'Mail sent successfully.',

                'messageId' =>
                    $result['messageId']
                    ?? null,
            ]
        );

    } catch (Throwable $e) {

        $suffix =
            (
                microtime(true) -
                $start
            > 10)

                ? ' — SMTP send timeout'

                : '';


        json_error(
            $e->getMessage() . $suffix,
            500
        );
    }
}


/*
|--------------------------------------------------------------------------
| Resolve API route
|--------------------------------------------------------------------------
*/

$route = resolve_route();


/*
|--------------------------------------------------------------------------
| Execute API handler
|--------------------------------------------------------------------------
*/

if (
    $route !== null
) {

    set_route_params(
        $route['params']
    );


    call_user_func(
        $route['handler']
    );


    exit;
}


/*
|--------------------------------------------------------------------------
| Static files
|--------------------------------------------------------------------------
|
| Normalize the path AGAIN here because API route resolution and
| static-file resolution are separate operations.
|
|--------------------------------------------------------------------------
*/

$path = normalize_application_path(
    $_SERVER['REQUEST_URI'] ?? '/'
);


/*
|--------------------------------------------------------------------------
| Serve static file
|--------------------------------------------------------------------------
*/

if (
    static_file_route($path)
) {
    exit;
}


/*
|--------------------------------------------------------------------------
| Frontend page
|--------------------------------------------------------------------------
*/

if (
    is_server_root_page($path)
) {
    exit;
}


/*
|--------------------------------------------------------------------------
| No route found
|--------------------------------------------------------------------------
*/

json_response(
    [
        'message' =>
            'Route not found.'
    ],
    404
);