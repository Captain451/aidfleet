<?php

function aidfleet_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https && strtolower((string)$https) !== 'off') {
        return true;
    }
    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function aidfleet_apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), payment=(), geolocation=(self)');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    if (aidfleet_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function aidfleet_send_json($data, int $status_code = 200): void
{
    if (ob_get_length()) ob_clean();
    http_response_code($status_code);
    aidfleet_apply_security_headers();
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function aidfleet_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        aidfleet_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    if (!in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) && function_exists('aidfleet_require_csrf')) {
        aidfleet_require_csrf();
    }
}

function aidfleet_get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function aidfleet_require_fields(array $input, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($input[$f]) || trim((string)$input[$f]) === '') {
            aidfleet_send_json(['success' => false, 'message' => "Missing field: {$f}"], 400);
        }
    }
}

