<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

function auth_login(): never
{
    $body = body_json();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_error('Username and password are required.', 400);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND role = ? LIMIT 1');
    $stmt->execute([$username, 'team']);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_error('Invalid team credentials.', 401);
    }

    json_response([
        'message'    => 'Login successful.',
        'token'      => 'fake-jwt-' . $user['id'],
        'username'   => $user['username'],
        'role'       => $user['role'],
        'department' => $user['department'] ?? '',
    ]);
}

function auth_customer_login(): never
{
    $body = body_json();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_error('Username and password are required.', 400);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND role = ? LIMIT 1');
    $stmt->execute([$username, 'customer']);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_error('Invalid customer credentials.', 401);
    }

    json_response([
        'message'  => 'Login successful.',
        'token'    => 'fake-jwt-' . $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'email'    => $user['email'] ?? '',
    ]);
}

/**
 * Resolve the caller from the Authorization header.
 *
 * The rest of the app uses a lightweight `fake-jwt-<user id>` token issued at
 * login. This helper turns it back into the user row so protected endpoints
 * can enforce server-side authorization instead of trusting the client.
 */
function bearer_user(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+fake-jwt-(\d+)$/i', trim($header), $m)) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $m[1]]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * Require the caller to be a logged-in team member with the admin department.
 */
function require_admin(): array
{
    $user = bearer_user();
    if (!$user || $user['role'] !== 'team' || strtolower($user['department'] ?? '') !== 'admin') {
        json_error('Admin access required.', 403);
    }
    return $user;
}

/**
 * POST /api/admin/create — protected. Only a logged-in admin can create
 * another admin. Replaces the old public /auth/signup.
 */
function admin_create(): never
{
    require_admin();

    $body = body_json();
    $firstName = trim((string) ($body['firstName'] ?? ''));
    $lastName  = trim((string) ($body['lastName'] ?? ''));
    $username  = trim((string) ($body['username'] ?? ''));
    $email     = trim((string) ($body['email'] ?? ''));
    $password  = (string) ($body['password'] ?? '');

    if ($firstName === '' || $lastName === '' || $username === '' || $email === '' || $password === '') {
        json_response(['error' => 'All fields are required.'], 400);
    }
    if (strlen($password) < 8) {
        json_response(['error' => 'Password must be at least 8 characters.'], 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Username already exists.'], 409);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Email already exists.'], 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, role, department, password, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
    );
    $stmt->execute([$firstName, $lastName, $username, $email, 'team', 'admin', $hash]);

    http_response_code(201);
    json_response(['message' => 'Admin created successfully.']);
}