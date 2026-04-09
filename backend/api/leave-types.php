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

const DEFAULT_LEAVE_TYPES = [
    [
        'description' => 'Leave for rest, recreation, or personal reasons.',
        'name' => 'Vacation',
        'sort_order' => 1,
    ],
    [
        'description' => 'Leave required after using the maximum number of vacation leave credits, as required by policy.',
        'name' => 'Mandatory/Forced Leave',
        'sort_order' => 2,
    ],
    [
        'description' => 'Leave for illness, injury, or medical treatment of the employee.',
        'name' => 'Sick',
        'sort_order' => 3,
    ],
    [
        'description' => 'Leave granted to a female employee for childbirth and recovery.',
        'name' => 'Maternity',
        'sort_order' => 4,
    ],
    [
        'description' => 'Leave granted to a male employee to support his spouse during childbirth.',
        'name' => 'Paternity',
        'sort_order' => 5,
    ],
    [
        'description' => 'Leave for personal matters or important family events.',
        'name' => 'Special Privilege',
        'sort_order' => 6,
    ],
    [
        'description' => 'Leave granted to employees who are certified solo parents.',
        'name' => 'Solo Parent',
        'sort_order' => 7,
    ],
    [
        'description' => 'Leave granted for examination or completion of studies.',
        'name' => 'Study',
        'sort_order' => 8,
    ],
    [
        'description' => 'Leave granted to victims of violence against women and their children.',
        'name' => '10-Day VAWC',
        'sort_order' => 9,
    ],
    [
        'description' => 'Leave for medical rehabilitation or therapy.',
        'name' => 'Rehabilitation Privilege',
        'sort_order' => 10,
    ],
    [
        'description' => 'Leave granted to women who undergo surgery due to gynecological disorders.',
        'name' => 'Special Leave Benefits for Women',
        'sort_order' => 11,
    ],
    [
        'description' => 'Leave granted during natural disasters or calamities.',
        'name' => 'Special Emergency(Calamity)',
        'sort_order' => 12,
    ],
    [
        'description' => null,
        'name' => 'Adoption',
        'sort_order' => 13,
    ],
    [
        'description' => null,
        'name' => 'Others',
        'sort_order' => 14,
    ],
];

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

function getLeaveTypeIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $leaveTypeId = filter_var($rawId, FILTER_VALIDATE_INT);

    return $leaveTypeId === false ? null : $leaveTypeId;
}

function ensureLeaveTypesTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `leave_types` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
            `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_leave_types_name` (`name`),
            KEY `idx_leave_types_sort_order` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function seedDefaultLeaveTypesIfEmpty(PDO $conn): void
{
    $existingCount = (int) $conn->query('SELECT COUNT(*) FROM `leave_types`')->fetchColumn();

    if ($existingCount > 0) {
        return;
    }

    $statement = $conn->prepare(
        'INSERT INTO `leave_types` (`name`, `description`, `sort_order`)
         VALUES (:name, :description, :sort_order)'
    );

    foreach (DEFAULT_LEAVE_TYPES as $leaveType) {
        $statement->execute([
            'description' => $leaveType['description'],
            'name' => $leaveType['name'],
            'sort_order' => $leaveType['sort_order'],
        ]);
    }
}

function fetchLeaveTypes(PDO $conn): array
{
    $statement = $conn->query(
        "SELECT
            `id`,
            `name`,
            `description`,
            `sort_order`,
            `is_archived`
         FROM `leave_types`
         ORDER BY `is_archived` ASC, `sort_order` ASC, `name` ASC"
    );

    return $statement->fetchAll();
}

function fetchLeaveTypeById(PDO $conn, int $leaveTypeId): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `id`,
            `name`,
            `description`,
            `sort_order`,
            `is_archived`
         FROM `leave_types`
         WHERE `id` = :id
         LIMIT 1"
    );
    $statement->execute([
        'id' => $leaveTypeId,
    ]);

    $leaveType = $statement->fetch();

    return $leaveType ?: null;
}

function getNextLeaveTypeSortOrder(PDO $conn): int
{
    return (int) $conn->query('SELECT COALESCE(MAX(`sort_order`), 0) + 1 FROM `leave_types`')
        ->fetchColumn();
}

function isDuplicateEntryError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

try {
    ensureLeaveTypesTableExists($conn);
    seedDefaultLeaveTypesIfEmpty($conn);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'leaveTypes' => fetchLeaveTypes($conn),
            ]);
            break;

        case 'POST':
            $payload = parseJsonPayload();
            $name = trim((string) ($payload['name'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));

            if ($name === '') {
                respond(422, [
                    'message' => 'Leave type name is required.',
                ]);
            }

            if (mb_strlen($name) > 150) {
                respond(422, [
                    'message' => 'Leave type name must not exceed 150 characters.',
                ]);
            }

            if (mb_strlen($description) > 500) {
                respond(422, [
                    'message' => 'Description must not exceed 500 characters.',
                ]);
            }

            try {
                $statement = $conn->prepare(
                    'INSERT INTO `leave_types` (`name`, `description`, `sort_order`)
                     VALUES (:name, :description, :sort_order)'
                );
                $statement->execute([
                    'description' => $description !== '' ? $description : null,
                    'name' => $name,
                    'sort_order' => getNextLeaveTypeSortOrder($conn),
                ]);
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That leave type already exists.',
                    ]);
                }

                throw $exception;
            }

            $leaveTypeId = (int) $conn->lastInsertId();
            $createdLeaveType = fetchLeaveTypeById($conn, $leaveTypeId);
            writeAuditLog(
                $conn,
                'settings',
                'leave-type.created',
                'A leave type was added.',
                'leave_type',
                $leaveTypeId,
                [
                    'leave_type' => $createdLeaveType,
                ]
            );
            respond(201, [
                'leaveType' => $createdLeaveType,
                'message' => 'Leave type added successfully.',
            ]);
            break;

        case 'PUT':
            $leaveTypeId = getLeaveTypeIdFromQuery();

            if ($leaveTypeId === null) {
                respond(422, [
                    'message' => 'A valid leave type id is required.',
                ]);
            }

            if (fetchLeaveTypeById($conn, $leaveTypeId) === null) {
                respond(404, [
                    'message' => 'The selected leave type was not found.',
                ]);
            }

            $payload = parseJsonPayload();
            $hasName = array_key_exists('name', $payload);
            $hasDescription = array_key_exists('description', $payload);
            $name = trim((string) ($payload['name'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));
            $hasArchiveFlag = array_key_exists('isArchived', $payload);
            $isArchived = filter_var($payload['isArchived'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (!$hasName && !$hasDescription && !$hasArchiveFlag) {
                respond(422, [
                    'message' => 'Leave type details or archive state are required.',
                ]);
            }

            if ($hasName && $name === '') {
                respond(422, [
                    'message' => 'Leave type name is required.',
                ]);
            }

            if ($hasName && mb_strlen($name) > 150) {
                respond(422, [
                    'message' => 'Leave type name must not exceed 150 characters.',
                ]);
            }

            if ($hasDescription && mb_strlen($description) > 500) {
                respond(422, [
                    'message' => 'Description must not exceed 500 characters.',
                ]);
            }

            if ($hasArchiveFlag && $isArchived === null) {
                respond(422, [
                    'message' => 'Please provide a valid archive value.',
                ]);
            }

            $query = 'UPDATE `leave_types` SET';
            $updates = [];
            $params = [
                'id' => $leaveTypeId,
            ];

            if ($hasName) {
                $updates[] = ' `name` = :name';
                $params['name'] = $name;
            }

            if ($hasDescription) {
                $updates[] = ' `description` = :description';
                $params['description'] = $description !== '' ? $description : null;
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
                        'message' => 'That leave type already exists.',
                    ]);
                }

                throw $exception;
            }

            $updatedLeaveType = fetchLeaveTypeById($conn, $leaveTypeId);
            writeAuditLog(
                $conn,
                'settings',
                $hasArchiveFlag && $isArchived ? 'leave-type.archived' : 'leave-type.updated',
                $hasArchiveFlag && $isArchived ? 'A leave type was archived.' : 'A leave type was updated.',
                'leave_type',
                $leaveTypeId,
                [
                    'leave_type' => $updatedLeaveType,
                ]
            );
            respond(200, [
                'leaveType' => $updatedLeaveType,
                'message' => 'Leave type updated successfully.',
            ]);
            break;

        case 'DELETE':
            $leaveTypeId = getLeaveTypeIdFromQuery();

            if ($leaveTypeId === null) {
                respond(422, [
                    'message' => 'A valid leave type id is required.',
                ]);
            }

            if (fetchLeaveTypeById($conn, $leaveTypeId) === null) {
                respond(404, [
                    'message' => 'The selected leave type was not found.',
                ]);
            }

            $deletedLeaveType = fetchLeaveTypeById($conn, $leaveTypeId);

            $statement = $conn->prepare('DELETE FROM `leave_types` WHERE `id` = :id');
            $statement->execute([
                'id' => $leaveTypeId,
            ]);

            writeAuditLog(
                $conn,
                'settings',
                'leave-type.deleted',
                'A leave type was deleted.',
                'leave_type',
                $leaveTypeId,
                [
                    'leave_type' => $deletedLeaveType,
                ]
            );
            respond(200, [
                'message' => 'Leave type deleted successfully.',
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process leave types right now.',
    ]);
}
