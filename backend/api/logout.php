<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

$allowedOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'message' => 'Method not allowed.',
    ]);
    exit;
}

$sessionName = session_name();
$hasSessionCookie = isset($_COOKIE[$sessionName]);

if (session_status() === PHP_SESSION_NONE && $hasSessionCookie) {
    session_start();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

if ($hasSessionCookie && ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        $sessionName,
        '',
        time() - 42000,
        $params['path'] ?: '/',
        $params['domain'] ?? '',
        (bool) ($params['secure'] ?? false),
        (bool) ($params['httponly'] ?? true)
    );
}

echo json_encode([
    'message' => 'Logout successful.',
]);
