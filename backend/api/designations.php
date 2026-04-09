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

function getDesignationIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $designationId = filter_var($rawId, FILTER_VALIDATE_INT);
    return $designationId === false ? null : $designationId;
}

function fetchDesignations(PDO $conn): array
{
    $statement = $conn->query(
        "SELECT
            `designations`.`id`,
            `designations`.`name`,
            `designations`.`division_id`,
            `designations`.`is_archived`,
            `divisions`.`name` AS `division`
         FROM `designations`
         INNER JOIN `divisions` ON `divisions`.`id` = `designations`.`division_id`
         ORDER BY `designations`.`is_archived` ASC, `divisions`.`name` ASC, `designations`.`name` ASC"
    );

    return $statement->fetchAll();
}

function fetchDesignationById(PDO $conn, int $designationId): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `designations`.`id`,
            `designations`.`name`,
            `designations`.`division_id`,
            `designations`.`is_archived`,
            `divisions`.`name` AS `division`
         FROM `designations`
         INNER JOIN `divisions` ON `divisions`.`id` = `designations`.`division_id`
         WHERE `designations`.`id` = :id
         LIMIT 1"
    );
    $statement->execute([
        'id' => $designationId,
    ]);

    $designation = $statement->fetch();
    return $designation ?: null;
}

function divisionExists(PDO $conn, int $divisionId): bool
{
    $statement = $conn->prepare(
        'SELECT `id` FROM `divisions` WHERE `id` = :id LIMIT 1'
    );
    $statement->execute([
        'id' => $divisionId,
    ]);

    return (bool) $statement->fetch();
}

function isDuplicateEntryError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'designations' => fetchDesignations($conn),
            ]);
            break;

        case 'POST':
            $payload = parseJsonPayload();
            $name = trim((string) ($payload['name'] ?? ''));
            $divisionId = filter_var($payload['divisionId'] ?? null, FILTER_VALIDATE_INT);

            if ($name === '') {
                respond(422, [
                    'message' => 'Designation name is required.',
                ]);
            }

            if ($divisionId === false) {
                respond(422, [
                    'message' => 'A valid division is required.',
                ]);
            }

            if (!divisionExists($conn, (int) $divisionId)) {
                respond(422, [
                    'message' => 'The selected division was not found.',
                ]);
            }

            try {
                $statement = $conn->prepare(
                    'INSERT INTO `designations` (`division_id`, `name`) VALUES (:division_id, :name)'
                );
                $statement->execute([
                    'division_id' => (int) $divisionId,
                    'name' => $name,
                ]);
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That designation already exists for the selected division.',
                    ]);
                }

                throw $exception;
            }

            $designationId = (int) $conn->lastInsertId();
            $createdDesignation = fetchDesignationById($conn, $designationId);
            writeAuditLog(
                $conn,
                'settings',
                'designation.created',
                'A designation was added.',
                'designation',
                $designationId,
                [
                    'designation' => $createdDesignation,
                ]
            );
            respond(201, [
                'designation' => $createdDesignation,
                'message' => 'Designation added successfully.',
            ]);
            break;

        case 'PUT':
            $designationId = getDesignationIdFromQuery();

            if ($designationId === null) {
                respond(422, [
                    'message' => 'A valid designation id is required.',
                ]);
            }

            if (fetchDesignationById($conn, $designationId) === null) {
                respond(404, [
                    'message' => 'The selected designation was not found.',
                ]);
            }

            $payload = parseJsonPayload();
            $name = trim((string) ($payload['name'] ?? ''));
            $divisionId = filter_var($payload['divisionId'] ?? null, FILTER_VALIDATE_INT);
            $hasArchiveFlag = array_key_exists('isArchived', $payload);
            $isArchived = filter_var($payload['isArchived'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($name === '' && $divisionId === false && !$hasArchiveFlag) {
                respond(422, [
                    'message' => 'Designation details or archive state are required.',
                ]);
            }

            if ($hasArchiveFlag && $isArchived === null) {
                respond(422, [
                    'message' => 'Please provide a valid archive value.',
                ]);
            }

            if ($divisionId !== false && !divisionExists($conn, (int) $divisionId)) {
                respond(422, [
                    'message' => 'The selected division was not found.',
                ]);
            }

            $query = 'UPDATE `designations` SET';
            $updates = [];
            $params = [
                'id' => $designationId,
            ];

            if ($name !== '') {
                $updates[] = ' `name` = :name';
                $params['name'] = $name;
            }

            if ($divisionId !== false) {
                $updates[] = ' `division_id` = :division_id';
                $params['division_id'] = (int) $divisionId;
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
                        'message' => 'That designation already exists for the selected division.',
                    ]);
                }

                throw $exception;
            }

            $updatedDesignation = fetchDesignationById($conn, $designationId);
            writeAuditLog(
                $conn,
                'settings',
                $hasArchiveFlag && $isArchived ? 'designation.archived' : 'designation.updated',
                $hasArchiveFlag && $isArchived ? 'A designation was archived.' : 'A designation was updated.',
                'designation',
                $designationId,
                [
                    'designation' => $updatedDesignation,
                ]
            );
            respond(200, [
                'designation' => $updatedDesignation,
                'message' => 'Designation updated successfully.',
            ]);
            break;

        case 'DELETE':
            $designationId = getDesignationIdFromQuery();

            if ($designationId === null) {
                respond(422, [
                    'message' => 'A valid designation id is required.',
                ]);
            }

            if (fetchDesignationById($conn, $designationId) === null) {
                respond(404, [
                    'message' => 'The selected designation was not found.',
                ]);
            }

            $deletedDesignation = fetchDesignationById($conn, $designationId);

            $statement = $conn->prepare(
                'DELETE FROM `designations` WHERE `id` = :id'
            );
            $statement->execute([
                'id' => $designationId,
            ]);

            writeAuditLog(
                $conn,
                'settings',
                'designation.deleted',
                'A designation was deleted.',
                'designation',
                $designationId,
                [
                    'designation' => $deletedDesignation,
                ]
            );
            respond(200, [
                'message' => 'Designation deleted successfully.',
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process designations right now.',
    ]);
}
