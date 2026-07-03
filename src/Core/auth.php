<?php

require_once __DIR__ . '/response.php';

function aidfleet_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_name('AIDFLEETSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => aidfleet_cookie_path(),
            'secure' => aidfleet_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (empty($_SESSION['aidfleet_csrf']) || !is_string($_SESSION['aidfleet_csrf'])) {
        $_SESSION['aidfleet_csrf'] = bin2hex(random_bytes(32));
    }
    aidfleet_set_csrf_cookie($_SESSION['aidfleet_csrf']);
}

function aidfleet_cookie_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        return substr($script, 0, $pos + 7) ?: '/';
    }
    return '/';
}

function aidfleet_set_cookie(string $name, string $value, bool $httpOnly, int $expires = 0): void
{
    if (headers_sent()) {
        return;
    }
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => aidfleet_cookie_path(),
        'secure' => aidfleet_is_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
}

function aidfleet_set_csrf_cookie(string $token): void
{
    aidfleet_set_cookie('AIDFLEET_CSRF', $token, false);
}

function aidfleet_clear_cookie(string $name, bool $httpOnly = true): void
{
    aidfleet_set_cookie($name, '', $httpOnly, time() - 3600);
}

function aidfleet_csrf_token(): string
{
    aidfleet_session_start();
    return (string)$_SESSION['aidfleet_csrf'];
}

function aidfleet_origin_matches_current(string $origin): bool
{
    $originParts = parse_url($origin);
    if (!$originParts || empty($originParts['host'])) {
        return false;
    }

    $currentScheme = aidfleet_is_https() ? 'https' : 'http';
    $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    $originHost = strtolower((string)$originParts['host']);
    $originPort = isset($originParts['port']) ? ':' . (int)$originParts['port'] : '';

    return $originScheme === $currentScheme && ($originHost . $originPort) === $currentHost;
}

function aidfleet_require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !aidfleet_origin_matches_current($origin)) {
        aidfleet_log('system', null, 'CSRF_ORIGIN_BLOCKED', null, null, 'Blocked request from origin ' . $origin);
        aidfleet_send_json(['success' => false, 'message' => 'Invalid request origin'], 403);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin === '' && $referer !== '' && !aidfleet_origin_matches_current($referer)) {
        aidfleet_log('system', null, 'CSRF_REFERER_BLOCKED', null, null, 'Blocked request from referer ' . $referer);
        aidfleet_send_json(['success' => false, 'message' => 'Invalid request referer'], 403);
    }
}

function aidfleet_require_csrf(): void
{
    aidfleet_session_start();
    aidfleet_require_same_origin();

    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['aidfleet_csrf'] ?? '');
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        aidfleet_log('system', null, 'CSRF_TOKEN_BLOCKED', null, null, 'Missing or invalid CSRF token');
        aidfleet_send_json(['success' => false, 'message' => 'Security token expired. Refresh the page and try again.'], 403);
    }
}

function aidfleet_login(string $role, int $id, string $name, string $email): void
{
    aidfleet_session_start();
    session_regenerate_id(true);
    $_SESSION['aidfleet_csrf'] = bin2hex(random_bytes(32));
    aidfleet_set_csrf_cookie($_SESSION['aidfleet_csrf']);
    $_SESSION['aidfleet'] = [
        'role' => $role,
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ];
}

function aidfleet_logout(): void
{
    aidfleet_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        aidfleet_clear_cookie(session_name(), true);
    }
    aidfleet_clear_cookie('AIDFLEET_CSRF', false);
    session_destroy();
}

function aidfleet_current_user(): ?array
{
    aidfleet_session_start();
    $u = $_SESSION['aidfleet'] ?? null;
    if (!is_array($u)) {
        return null;
    }

    $db = aidfleet_db();
    $role = $u['role'] ?? '';
    $id = (int)($u['id'] ?? 0);
    $exists = false;

    if ($role === 'requester') {
        $exists = (bool)aidfleet_db_one($db, 'SELECT user_id FROM users WHERE user_id = ?', 'i', [$id]);
    } elseif ($role === 'driver') {
        $exists = (bool)aidfleet_db_one($db, 'SELECT driver_id FROM drivers WHERE driver_id = ?', 'i', [$id]);
    } elseif ($role === 'admin') {
        $exists = (bool)aidfleet_db_one($db, 'SELECT admin_id FROM administrators WHERE admin_id = ?', 'i', [$id]);
    }

    if (!$exists) {
        aidfleet_logout();
        return null;
    }

    return $u;
}

function aidfleet_require_auth(array $allowed_roles = []): array
{
    $u = aidfleet_current_user();
    if (!$u) {
        aidfleet_send_json(['success' => false, 'message' => 'Not authenticated. Please log in again.'], 401);
    }
    if (!empty($allowed_roles) && !in_array($u['role'], $allowed_roles, true)) {
        aidfleet_send_json(['success' => false, 'message' => 'Forbidden'], 403);
    }
    return $u;
}
