<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, X-HRIS-Actor-Id, X-HRIS-Actor-Name, X-HRIS-Actor-Role');
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
require __DIR__ . '/audit-log-helper.php';

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

function getDivisionIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $divisionId = filter_var($rawId, FILTER_VALIDATE_INT);
    return $divisionId === false ? null : $divisionId;
}

function fetchDivisions(PDO $conn): array
{
    $statement = $conn->query(
        "SELECT
            `id`,
            `name`,
            `is_archived`
         FROM `divisions`
         ORDER BY `is_archived` ASC, `name` ASC"
    );

    return $statement->fetchAll();
}

function fetchDivisionById(PDO $conn, int $divisionId): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `id`,
            `name`,
            `is_archived`
         FROM `divisions`
         WHERE `id` = :id
         LIMIT 1"
    );
    $statement->execute([
        'id' => $divisionId,
    ]);

    $division = $statement->fetch();
    return $division ?: null;
}

function isDuplicateEntryError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

function isForeignKeyConstraintError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1451;
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'divisions' => fetchDivisions($conn),
            ]);
            break;

        case 'POST':
            $payload = parseJsonPayload();
            $name = trim((string) ($payload['name'] ?? ''));

            if ($name === '') {
                respond(422, [
                    'message' => 'Division name is required.',
                ]);
            }

            try {
                $statement = $conn->prepare(
                    'INSERT INTO `divisions` (`name`) VALUES (:name)'
                );
                $statement->execute([
                    'name' => $name,
                ]);
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That division already exists.',
                    ]);
                }

                throw $exception;
            }

            $divisionId = (int) $conn->lastInsertId();
            $createdDivision = fetchDivisionById($conn, $divisionId);
            writeAuditLog(
                $conn,
                'settings',
                'division.created',
                'A division was added.',
                'division',
                $divisionId,
                [
                    'division' => $createdDivision,
                ]
            );
            respond(201, [
                'division' => $createdDivision,
                'message' => 'Division added successfully.',
            ]);
            break;

        case 'PUT':
            $divisionId = getDivisionIdFromQuery();

            if ($divisionId === null) {
                respond(422, [
                    'message' => 'A valid division id is required.',
                ]);
            }

            if (fetchDivisionById($conn, $divisionId) === null) {
                respond(404, [
                    'message' => 'The selected division was not found.',
                ]);
            }

            $payload = parseJsonPayload();
            $name = trim((string) ($payload['name'] ?? ''));
            $hasArchiveFlag = array_key_exists('isArchived', $payload);
            $isArchived = filter_var($payload['isArchived'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($name === '' && !$hasArchiveFlag) {
                respond(422, [
                    'message' => 'Division name or archive state is required.',
                ]);
            }

            if ($hasArchiveFlag && $isArchived === null) {
                respond(422, [
                    'message' => 'Please provide a valid archive value.',
                ]);
            }

            $query = 'UPDATE `divisions` SET';
            $updates = [];
            $params = [
                'id' => $divisionId,
            ];

            if ($name !== '') {
                $updates[] = ' `name` = :name';
                $params['name'] = $name;
            }

            if ($hasArchiveFlag) {
                $updates[] = ' `is_archived` = :is_archived';
                $params['is_archived'] = $isArchived ? 1 : 0;
            }

            $query .= implode(',', $updates);
            $query .= ' WHERE `id` = :id';

            try {
                $statement = $conn->prepare($query);
                $statement->execute($params);
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That division already exists.',
                    ]);
                }

                throw $exception;
            }

            $updatedDivision = fetchDivisionById($conn, $divisionId);
            writeAuditLog(
                $conn,
                'settings',
                $hasArchiveFlag && $isArchived ? 'division.archived' : 'division.updated',
                $hasArchiveFlag && $isArchived ? 'A division was archived.' : 'A division was updated.',
                'division',
                $divisionId,
                [
                    'division' => $updatedDivision,
                ]
            );
            respond(200, [
                'division' => $updatedDivision,
                'message' => 'Division updated successfully.',
            ]);
            break;

        case 'DELETE':
            $divisionId = getDivisionIdFromQuery();

            if ($divisionId === null) {
                respond(422, [
                    'message' => 'A valid division id is required.',
                ]);
            }

            if (fetchDivisionById($conn, $divisionId) === null) {
                respond(404, [
                    'message' => 'The selected division was not found.',
                ]);
            }

            $deletedDivision = fetchDivisionById($conn, $divisionId);

            try {
                $statement = $conn->prepare(
                    'DELETE FROM `divisions` WHERE `id` = :id'
                );
                $statement->execute([
                    'id' => $divisionId,
                ]);
            } catch (PDOException $exception) {
                if (isForeignKeyConstraintError($exception)) {
                    respond(409, [
                        'message' => 'This division cannot be deleted because it is still connected to one or more designations.',
                    ]);
                }

                throw $exception;
            }

            writeAuditLog(
                $conn,
                'settings',
                'division.deleted',
                'A division was deleted.',
                'division',
                $divisionId,
                [
                    'division' => $deletedDivision,
                ]
            );
            respond(200, [
                'message' => 'Division deleted successfully.',
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process divisions right now.',
    ]);
}
