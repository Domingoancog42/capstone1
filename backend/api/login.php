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

require __DIR__ . '/connection-pdo.php';
require __DIR__ . '/app-settings-store.php';
require __DIR__ . '/audit-log-helper.php';
require __DIR__ . '/auth-security-helper.php';

function usersTableHasLegacyNameColumn(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `users` LIKE 'name'");
    return (bool) $statement->fetch();
}

$payload = json_decode(file_get_contents('php://input'), true);
$loginIdentifier = trim((string) ($payload['username'] ?? ''));
$password = (string) ($payload['password'] ?? '');

if ($loginIdentifier === '' || $password === '') {
    http_response_code(422);
    echo json_encode([
        'message' => 'Username or email and password are required.',
    ]);
    exit;
}

try {
    $appSettings = fetchAppSettings($conn);
    $hasLegacyNameColumn = usersTableHasLegacyNameColumn($conn);
    $displayNameSelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(users.username, ''), users.name, '') AS display_name"
        : "COALESCE(users.username, '') AS display_name";
    $legacyNameWhereClause = $hasLegacyNameColumn
        ? ' OR users.name = :legacy_name'
        : '';
    $statement = $conn->prepare(
        "SELECT users.id, users.username, {$displayNameSelect}, users.email, status_lookup.name AS status_name, users.password_hash, roles.name AS role_name
         FROM users
         INNER JOIN roles ON roles.id = users.role_id
         INNER JOIN `status` AS `status_lookup` ON `status_lookup`.`id` = `users`.`status_id`
         WHERE users.username = :login_username
            OR users.email = :login_email{$legacyNameWhereClause}
         LIMIT 1"
    );
    $params = [
        'login_email' => $loginIdentifier,
        'login_username' => $loginIdentifier,
    ];

    if ($hasLegacyNameColumn) {
        $params['legacy_name'] = $loginIdentifier;
    }

    $statement->execute($params);

    $user = $statement->fetch();

    if ($user && isUserTemporarilyLocked($conn, (int) $user['id'])) {
        writeAuditLog(
            $conn,
            'auth',
            'login.locked',
            'A login attempt was blocked because the account is temporarily locked.',
            'user',
            (int) $user['id'],
            [
                'username' => $user['username'] ?: $user['display_name'],
            ],
            [
                'actor_id' => (int) $user['id'],
                'actor_name' => $user['username'] ?: $user['display_name'],
                'actor_role' => $user['role_name'],
            ]
        );

        http_response_code(423);
        echo json_encode([
            'message' => 'This account is temporarily locked due to repeated failed login attempts. Please try again later.',
        ]);
        exit;
    }

    if (
        !$user
        || empty($user['password_hash'])
        || !password_verify($password, $user['password_hash'])
    ) {
        if ($user) {
            $failedAttemptState = registerFailedLoginAttempt(
                $conn,
                (int) $user['id'],
                (int) $appSettings['max_login_attempts'],
                (int) $appSettings['lockout_duration_minutes']
            );

            writeAuditLog(
                $conn,
                'auth',
                $failedAttemptState['is_locked'] ? 'login.lockout-triggered' : 'login.failed',
                $failedAttemptState['is_locked']
                    ? 'An account was temporarily locked after repeated failed logins.'
                    : 'A login attempt failed.',
                'user',
                (int) $user['id'],
                [
                    'failed_attempts' => $failedAttemptState['failed_attempts'],
                    'locked_until' => $failedAttemptState['locked_until'],
                    'username' => $user['username'] ?: $user['display_name'],
                ],
                [
                    'actor_id' => (int) $user['id'],
                    'actor_name' => $user['username'] ?: $user['display_name'],
                    'actor_role' => $user['role_name'],
                ]
            );

            if ($failedAttemptState['is_locked']) {
                http_response_code(423);
                echo json_encode([
                    'message' => 'Too many failed login attempts. This account has been temporarily locked.',
                ]);
                exit;
            }
        } else {
            writeAuditLog(
                $conn,
                'auth',
                'login.failed',
                'A login attempt failed for an unknown username or email.',
                'login_identifier',
                $loginIdentifier,
                [
                    'identifier' => $loginIdentifier,
                ]
            );
        }

        http_response_code(401);
        echo json_encode([
            'message' => 'Invalid username or password.',
        ]);
        exit;
    }

    if (($user['status_name'] ?? 'Inactive') !== 'Active') {
        writeAuditLog(
            $conn,
            'auth',
            'login.denied',
            'A login attempt was denied because the account is not active.',
            'user',
            (int) $user['id'],
            [
                'status' => $user['status_name'],
                'username' => $user['username'] ?: $user['display_name'],
            ],
            [
                'actor_id' => (int) $user['id'],
                'actor_name' => $user['username'] ?: $user['display_name'],
                'actor_role' => $user['role_name'],
            ]
        );
        http_response_code(403);
        echo json_encode([
            'message' => 'Only active accounts can access the dashboard.',
        ]);
        exit;
    }

    clearUserSecurityState($conn, (int) $user['id']);
    writeAuditLog(
        $conn,
        'auth',
        'login.success',
        'A user signed in successfully.',
        'user',
        (int) $user['id'],
        [
            'username' => $user['username'] ?: $user['display_name'],
        ],
        [
            'actor_id' => (int) $user['id'],
            'actor_name' => $user['username'] ?: $user['display_name'],
            'actor_role' => $user['role_name'],
        ]
    );

    echo json_encode([
        'message' => 'Login successful.',
        'user' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['display_name'],
            'sessionTimeoutMinutes' => (int) $appSettings['session_timeout_minutes'],
            'status' => $user['status_name'],
            'username' => $user['username'] ?: $user['display_name'],
            'role' => $user['role_name'],
        ],
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Unable to log in right now.',
    ]);
}
