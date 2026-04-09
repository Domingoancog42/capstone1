<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

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

require __DIR__ . '/connection-pdo.php';
require __DIR__ . '/app-settings-store.php';

const ACCOUNT_STATUS_NAMES = ['Pending', 'Active', 'Inactive'];

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function parseJsonPayload(): array
{
    $payload = json_decode(file_get_contents('php://input'), true);
    return is_array($payload) ? $payload : [];
}

function getUserIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $userId = filter_var($rawId, FILTER_VALIDATE_INT);
    return $userId === false ? null : $userId;
}

function usersTableHasLegacyNameColumn(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `users` LIKE 'name'");
    return (bool) $statement->fetch();
}

function getRoleIdByName(PDO $conn, string $roleName): ?int
{
    $statement = $conn->prepare('SELECT `id` FROM `roles` WHERE `name` = :name LIMIT 1');
    $statement->execute([
        'name' => $roleName,
    ]);

    $role = $statement->fetch();
    return $role ? (int) $role['id'] : null;
}

function getStatusIdByName(PDO $conn, string $statusName): ?int
{
    $statement = $conn->prepare('SELECT `id` FROM `status` WHERE `name` = :name LIMIT 1');
    $statement->execute([
        'name' => $statusName,
    ]);

    $status = $statement->fetch();
    return $status ? (int) $status['id'] : null;
}

function getPasswordCategoryCount(string $password): int
{
    $matches = [
        preg_match('/[a-z]/', $password) === 1,
        preg_match('/[A-Z]/', $password) === 1,
        preg_match('/\d/', $password) === 1,
        preg_match('/[^A-Za-z0-9]/', $password) === 1,
    ];

    return count(array_filter($matches));
}

function validateUserPayload(PDO $conn, array $payload, array $appSettings, bool $requirePassword = false): array
{
    $username = trim((string) ($payload['username'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $roleName = trim((string) ($payload['role'] ?? ''));
    $status = trim((string) ($payload['status'] ?? ''));

    if ($username === '' || $email === '' || $roleName === '' || $status === '') {
        respond(422, [
            'message' => 'Username, role, email, and status are required.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'message' => 'Please enter a valid email address.',
        ]);
    }

    if (!in_array($status, ACCOUNT_STATUS_NAMES, true)) {
        respond(422, [
            'message' => 'Please select a valid user status.',
        ]);
    }

    if ($requirePassword && $password === '') {
        respond(422, [
            'message' => 'Password is required when creating a user.',
        ]);
    }

    if ($password !== '' && strlen($password) < (int) $appSettings['password_min_length']) {
        respond(422, [
            'message' => 'Password does not meet the current security policy requirements.',
        ]);
    }

    if (
        $password !== '' &&
        getPasswordCategoryCount($password) < (int) $appSettings['password_min_category_count']
    ) {
        respond(422, [
            'message' => 'Password does not meet the current security policy requirements.',
        ]);
    }

    $roleId = getRoleIdByName($conn, $roleName);

    if ($roleId === null) {
        respond(422, [
            'message' => 'The selected role was not found in the database.',
        ]);
    }

    $statusId = getStatusIdByName($conn, $status);

    if ($statusId === null) {
        respond(422, [
            'message' => 'The selected status was not found in the database.',
        ]);
    }

    return [
        'email' => $email,
        'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
        'role_id' => $roleId,
        'role_name' => $roleName,
        'status_id' => $statusId,
        'status' => $status,
        'username' => $username,
    ];
}

function fetchManagedUsers(PDO $conn, bool $hasLegacyNameColumn): array
{
    $usernameSelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`users`.`username`, ''), `users`.`name`, '') AS `username`"
        : "COALESCE(`users`.`username`, '') AS `username`";
    $statement = $conn->query(
        "SELECT
            `users`.`id`,
            {$usernameSelect},
            `roles`.`name` AS `role`,
            COALESCE(`users`.`email`, '') AS `email`,
            `status_lookup`.`name` AS `status`
         FROM `users`
         INNER JOIN `roles` ON `roles`.`id` = `users`.`role_id`
         INNER JOIN `status` AS `status_lookup` ON `status_lookup`.`id` = `users`.`status_id`
         WHERE `users`.`username` IS NULL OR `users`.`username` <> 'admin'
         ORDER BY `users`.`id` DESC"
    );

    return $statement->fetchAll();
}

function fetchManagedUserById(PDO $conn, int $userId, bool $hasLegacyNameColumn): ?array
{
    $usernameSelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`users`.`username`, ''), `users`.`name`, '') AS `username`"
        : "COALESCE(`users`.`username`, '') AS `username`";
    $statement = $conn->prepare(
        "SELECT
            `users`.`id`,
            {$usernameSelect},
            `roles`.`name` AS `role`,
            COALESCE(`users`.`email`, '') AS `email`,
            `status_lookup`.`name` AS `status`
         FROM `users`
         INNER JOIN `roles` ON `roles`.`id` = `users`.`role_id`
         INNER JOIN `status` AS `status_lookup` ON `status_lookup`.`id` = `users`.`status_id`
         WHERE `users`.`id` = :id
           AND (`users`.`username` IS NULL OR `users`.`username` <> 'admin')
         LIMIT 1"
    );
    $statement->execute([
        'id' => $userId,
    ]);

    $user = $statement->fetch();
    return $user ?: null;
}

function isDuplicateEntryError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

try {
    $hasLegacyNameColumn = usersTableHasLegacyNameColumn($conn);
    $appSettings = fetchAppSettings($conn);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'users' => fetchManagedUsers($conn, $hasLegacyNameColumn),
            ]);
            break;

        case 'POST':
            $payload = validateUserPayload($conn, parseJsonPayload(), $appSettings, true);

            try {
                if ($hasLegacyNameColumn) {
                    $statement = $conn->prepare(
                        'INSERT INTO `users` (`username`, `name`, `email`, `password_hash`, `role_id`, `status_id`)
                         VALUES (:username, :legacy_name, :email, :password_hash, :role_id, :status_id)'
                    );
                    $statement->execute([
                        'email' => $payload['email'],
                        'legacy_name' => $payload['username'],
                        'password_hash' => $payload['password_hash'],
                        'role_id' => $payload['role_id'],
                        'status_id' => $payload['status_id'],
                        'username' => $payload['username'],
                    ]);
                } else {
                    $statement = $conn->prepare(
                        'INSERT INTO `users` (`username`, `email`, `password_hash`, `role_id`, `status_id`)
                         VALUES (:username, :email, :password_hash, :role_id, :status_id)'
                    );
                    $statement->execute([
                        'email' => $payload['email'],
                        'password_hash' => $payload['password_hash'],
                        'role_id' => $payload['role_id'],
                        'status_id' => $payload['status_id'],
                        'username' => $payload['username'],
                    ]);
                }
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That username or email address is already in use.',
                    ]);
                }

                throw $exception;
            }

            respond(201, [
                'message' => 'User added successfully.',
                'user' => fetchManagedUserById($conn, (int) $conn->lastInsertId(), $hasLegacyNameColumn),
            ]);
            break;

        case 'PUT':
            $userId = getUserIdFromQuery();

            if ($userId === null) {
                respond(422, [
                    'message' => 'A valid user id is required.',
                ]);
            }

            if (fetchManagedUserById($conn, $userId, $hasLegacyNameColumn) === null) {
                respond(404, [
                    'message' => 'The selected user was not found.',
                ]);
            }

            $payload = validateUserPayload($conn, parseJsonPayload(), $appSettings);

            try {
                $query = "UPDATE `users`
                          SET
                             `username` = :username,
                             `email` = :email,
                             `role_id` = :role_id,
                             `status_id` = :status_id";
                $params = [
                    'email' => $payload['email'],
                    'id' => $userId,
                    'role_id' => $payload['role_id'],
                    'status_id' => $payload['status_id'],
                    'username' => $payload['username'],
                ];

                if ($hasLegacyNameColumn) {
                    $query .= ',
                             `name` = :legacy_name';
                    $params['legacy_name'] = $payload['username'];
                }

                if ($payload['password_hash'] !== null) {
                    $query .= ',
                             `password_hash` = :password_hash';
                    $params['password_hash'] = $payload['password_hash'];
                }

                $query .= "
                     WHERE `id` = :id
                       AND (`username` IS NULL OR `username` <> 'admin')";

                $statement = $conn->prepare($query);
                $statement->execute($params);
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That username or email address is already in use.',
                    ]);
                }

                throw $exception;
            }

            respond(200, [
                'message' => 'User updated successfully.',
                'user' => fetchManagedUserById($conn, $userId, $hasLegacyNameColumn),
            ]);
            break;

        case 'DELETE':
            $userId = getUserIdFromQuery();

            if ($userId === null) {
                respond(422, [
                    'message' => 'A valid user id is required.',
                ]);
            }

            if (fetchManagedUserById($conn, $userId, $hasLegacyNameColumn) === null) {
                respond(404, [
                    'message' => 'The selected user was not found.',
                ]);
            }

            $statement = $conn->prepare(
                "DELETE FROM `users`
                 WHERE `id` = :id
                   AND (`username` IS NULL OR `username` <> 'admin')"
            );
            $statement->execute([
                'id' => $userId,
            ]);

            respond(200, [
                'message' => 'User deleted successfully.',
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process users right now.',
    ]);
}
