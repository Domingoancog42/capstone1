<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');

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
require_once __DIR__ . '/request-approval-guard.php';

const COMPENSATORY_STATUS_PENDING = 1;
const COMPENSATORY_STATUS_APPROVED = 2;
const COMPENSATORY_STATUS_REJECTED = 3;
const COMPENSATORY_STATUS_CANCELLED = 4;

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

function getCompensatoryIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $compensatoryId = filter_var($rawId, FILTER_VALIDATE_INT);

    return $compensatoryId === false ? null : $compensatoryId;
}

function ensureCompensatoryTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `tblcompensatory` (
            `cto_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` INT UNSIGNED NOT NULL,
            `hours_applied` DECIMAL(5,2) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `status` INT NOT NULL DEFAULT 1,
            `approved_by` INT UNSIGNED DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `rejected_by` INT UNSIGNED DEFAULT NULL,
            `rejected_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`cto_id`),
            KEY `idx_tblcompensatory_employee_id` (`employee_id`),
            KEY `idx_tblcompensatory_status` (`status`),
            KEY `idx_tblcompensatory_approved_by` (`approved_by`),
            KEY `idx_tblcompensatory_rejected_by` (`rejected_by`),
            CONSTRAINT `fk_tblcompensatory_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT `fk_tblcompensatory_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL,
            CONSTRAINT `fk_tblcompensatory_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function usersTableHasLegacyNameColumn(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `users` LIKE 'name'");

    return (bool) $statement->fetch();
}

function compensatoryTableUsesAutoIncrement(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `tblcompensatory` LIKE 'cto_id'");
    $column = $statement->fetch();

    return $column && stripos((string) ($column['Extra'] ?? ''), 'auto_increment') !== false;
}

function getNextCompensatoryId(PDO $conn): int
{
    $statement = $conn->query('SELECT COALESCE(MAX(`cto_id`), 0) + 1 AS `next_id` FROM `tblcompensatory`');

    return (int) $statement->fetchColumn();
}

function validateDate(string $value, string $label): string
{
    $trimmedValue = trim($value);

    if ($trimmedValue === '') {
        respond(422, [
            'message' => sprintf('%s is required.', $label),
        ]);
    }

    $date = DateTime::createFromFormat('Y-m-d', $trimmedValue);

    if (!$date || $date->format('Y-m-d') !== $trimmedValue) {
        respond(422, [
            'message' => sprintf('Please enter a valid %s.', strtolower($label)),
        ]);
    }

    return $trimmedValue;
}

function calculateInclusiveDays(string $startDate, string $endDate): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);

    if ($end < $start) {
        respond(422, [
            'message' => 'End date must be on or after start date.',
        ]);
    }

    return (int) $start->diff($end)->days + 1;
}

function normalizeHoursApplied(array $payload): string
{
    $rawValue = trim((string) ($payload['hours_applied'] ?? ''));

    if ($rawValue === '') {
        respond(422, [
            'message' => 'Number of hours applied for is required.',
        ]);
    }

    if (!is_numeric($rawValue)) {
        respond(422, [
            'message' => 'Please enter a valid number of hours applied for.',
        ]);
    }

    $hoursApplied = (float) $rawValue;

    if ($hoursApplied <= 0) {
        respond(422, [
            'message' => 'Hours applied must be greater than zero.',
        ]);
    }

    if ($hoursApplied > 999.99) {
        respond(422, [
            'message' => 'Hours applied must not exceed 999.99.',
        ]);
    }

    return number_format($hoursApplied, 2, '.', '');
}

function fetchEmployeeProfileByEmail(PDO $conn, string $email): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `employees`.`id`,
            `employees`.`employee_id`,
            `employees`.`first_name`,
            `employees`.`middle_name`,
            `employees`.`last_name`,
            `employees`.`email`,
            `divisions`.`name` AS `division`,
            `designations`.`name` AS `designation`
         FROM `employees`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         WHERE `employees`.`email` = :email
         LIMIT 1"
    );
    $statement->execute([
        'email' => $email,
    ]);

    $employee = $statement->fetch();

    if (!$employee) {
        return null;
    }

    return [
        'designation' => $employee['designation'] ?? '',
        'division' => $employee['division'] ?? '',
        'email' => $employee['email'] ?? '',
        'employee_code' => $employee['employee_id'] ?? '',
        'employee_record_id' => (int) $employee['id'],
        'full_name' => trim(implode(' ', array_filter([
            $employee['first_name'] ?? '',
            $employee['middle_name'] ?? '',
            $employee['last_name'] ?? '',
        ]))),
    ];
}

function fetchEmployeeProfileById(PDO $conn, int $employeeRecordId): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `employees`.`id`,
            `employees`.`employee_id`,
            `employees`.`first_name`,
            `employees`.`middle_name`,
            `employees`.`last_name`,
            `employees`.`email`,
            `divisions`.`name` AS `division`,
            `designations`.`name` AS `designation`
         FROM `employees`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         WHERE `employees`.`id` = :employee_id
         LIMIT 1"
    );
    $statement->execute([
        'employee_id' => $employeeRecordId,
    ]);

    $employee = $statement->fetch();

    if (!$employee) {
        return null;
    }

    return [
        'designation' => $employee['designation'] ?? '',
        'division' => $employee['division'] ?? '',
        'email' => $employee['email'] ?? '',
        'employee_code' => $employee['employee_id'] ?? '',
        'employee_record_id' => (int) $employee['id'],
        'full_name' => trim(implode(' ', array_filter([
            $employee['first_name'] ?? '',
            $employee['middle_name'] ?? '',
            $employee['last_name'] ?? '',
        ]))),
    ];
}

function getCurrentEmployeeIdYear(): string
{
    return (new DateTimeImmutable('now'))->format('Y');
}

function formatEmployeeCode(string $year, int $sequence): string
{
    return sprintf('EMP%s-%02d', $year, $sequence);
}

function generateNextEmployeeCode(PDO $conn): string
{
    $year = getCurrentEmployeeIdYear();
    $statement = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(`employee_id`, '-', -1) AS UNSIGNED)) AS `max_sequence`
         FROM `employees`
         WHERE `employee_id` REGEXP :employee_id_pattern"
    );
    $statement->execute([
        'employee_id_pattern' => '^EMP' . $year . '-[0-9]+$',
    ]);

    $maxSequence = (int) ($statement->fetchColumn() ?: 0);

    return formatEmployeeCode($year, $maxSequence + 1);
}

function fetchDefaultDivisionAndDesignation(PDO $conn): ?array
{
    $statement = $conn->query(
        "SELECT
            `divisions`.`id` AS `division_id`,
            `designations`.`id` AS `designation_id`
         FROM `designations`
         INNER JOIN `divisions` ON `divisions`.`id` = `designations`.`division_id`
         ORDER BY `divisions`.`id` ASC, `designations`.`id` ASC
         LIMIT 1"
    );

    $row = $statement->fetch();

    if (!$row) {
        return null;
    }

    return [
        'designation_id' => (int) $row['designation_id'],
        'division_id' => (int) $row['division_id'],
    ];
}

function extractNamePartsFromIdentity(string $value): array
{
    $localPart = trim(strtolower(strtok($value, '@') ?: $value));
    $segments = preg_split('/[^a-z0-9]+/i', $localPart) ?: [];
    $segments = array_values(array_filter($segments, static fn ($segment) => $segment !== ''));

    if ($segments === []) {
        return [
            'first_name' => 'Employee',
            'last_name' => 'User',
        ];
    }

    $firstName = ucfirst($segments[0]);
    $lastName = count($segments) > 1 ? ucfirst($segments[count($segments) - 1]) : 'User';

    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
    ];
}

function provisionEmployeeProfileForEmail(PDO $conn, string $email): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `users`.`username`,
            `users`.`email`
         FROM `users`
         WHERE `users`.`email` = :email
         LIMIT 1"
    );
    $statement->execute([
        'email' => $email,
    ]);

    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    $defaults = fetchDefaultDivisionAndDesignation($conn);

    if ($defaults === null) {
        return null;
    }

    $nameParts = extractNamePartsFromIdentity((string) ($user['username'] ?? $email));
    $employeeCode = generateNextEmployeeCode($conn);
    $insertStatement = $conn->prepare(
        'INSERT INTO `employees` (
            `employee_id`,
            `first_name`,
            `last_name`,
            `email`,
            `division_id`,
            `designation_id`,
            `status`
        ) VALUES (
            :employee_id,
            :first_name,
            :last_name,
            :email,
            :division_id,
            :designation_id,
            :status
        )'
    );
    $insertStatement->execute([
        'designation_id' => $defaults['designation_id'],
        'division_id' => $defaults['division_id'],
        'email' => $email,
        'employee_id' => $employeeCode,
        'first_name' => $nameParts['first_name'],
        'last_name' => $nameParts['last_name'],
        'status' => 'Active',
    ]);

    return fetchEmployeeProfileById($conn, (int) $conn->lastInsertId());
}

function validateCreatePayload(PDO $conn, array $payload): array
{
    $employeeId = filter_var($payload['employee_id'] ?? null, FILTER_VALIDATE_INT);
    $employeeEmail = trim((string) ($payload['employee_email'] ?? ''));
    $hoursApplied = normalizeHoursApplied($payload);
    $startDate = validateDate((string) ($payload['start_date'] ?? ''), 'Start date');
    $endDate = validateDate((string) ($payload['end_date'] ?? ''), 'End date');

    if ($employeeId === false && ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL))) {
        respond(422, [
            'message' => 'A valid employee is required.',
        ]);
    }

    $employeeProfile = $employeeId !== false && $employeeId !== null
        ? fetchEmployeeProfileById($conn, (int) $employeeId)
        : fetchEmployeeProfileByEmail($conn, $employeeEmail);

    if ($employeeProfile === null && $employeeEmail !== '') {
        $employeeProfile = provisionEmployeeProfileForEmail($conn, $employeeEmail);
    }

    if ($employeeProfile === null) {
        respond(422, [
            'message' => 'The selected employee record was not found.',
        ]);
    }

    calculateInclusiveDays($startDate, $endDate);

    return [
        'employee_id' => $employeeProfile['employee_record_id'],
        'end_date' => $endDate,
        'hours_applied' => $hoursApplied,
        'start_date' => $startDate,
        'status' => COMPENSATORY_STATUS_PENDING,
    ];
}

function validateEditPayload(PDO $conn, array $payload): array
{
    $validatedPayload = validateCreatePayload($conn, $payload);

    return [
        'employee_id' => $validatedPayload['employee_id'],
        'end_date' => $validatedPayload['end_date'],
        'hours_applied' => $validatedPayload['hours_applied'],
        'start_date' => $validatedPayload['start_date'],
        'status' => COMPENSATORY_STATUS_PENDING,
    ];
}

function validateDecisionPayload(array $payload): array
{
    $status = filter_var($payload['status'] ?? null, FILTER_VALIDATE_INT);
    $adminUserId = filter_var($payload['admin_user_id'] ?? null, FILTER_VALIDATE_INT);

    if (!in_array($status, [COMPENSATORY_STATUS_APPROVED, COMPENSATORY_STATUS_REJECTED, COMPENSATORY_STATUS_CANCELLED], true)) {
        respond(422, [
            'message' => 'A valid decision status is required.',
        ]);
    }

    if (
        in_array($status, [COMPENSATORY_STATUS_APPROVED, COMPENSATORY_STATUS_REJECTED], true) &&
        ($adminUserId === false || $adminUserId === null)
    ) {
        respond(422, [
            'message' => 'A valid admin user id is required.',
        ]);
    }

    return [
        'admin_user_id' => ($adminUserId === false || $adminUserId === null)
            ? null
            : (int) $adminUserId,
        'status' => (int) $status,
    ];
}

function fetchCompensatoryRequests(PDO $conn, bool $hasLegacyNameColumn, ?string $employeeEmail = null): array
{
    $approverDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`name`, `approved_user`.`email`, '')"
        : "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`email`, '')";
    $rejectorDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`name`, `rejected_user`.`email`, '')"
        : "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`email`, '')";
    $query = "SELECT
            `tblcompensatory`.`cto_id`,
            CONCAT('CTO-', LPAD(`tblcompensatory`.`cto_id`, 3, '0')) AS `request_code`,
            `tblcompensatory`.`employee_id` AS `employee_record_id`,
            `employees`.`employee_id` AS `employee_code`,
            TRIM(CONCAT(
                `employees`.`first_name`,
                ' ',
                COALESCE(CONCAT(`employees`.`middle_name`, ' '), ''),
                `employees`.`last_name`
            )) AS `employee_name`,
            `employees`.`email` AS `employee_email`,
            `divisions`.`name` AS `division`,
            `designations`.`name` AS `designation`,
            `tblcompensatory`.`hours_applied`,
            `tblcompensatory`.`start_date`,
            `tblcompensatory`.`end_date`,
            DATEDIFF(`tblcompensatory`.`end_date`, `tblcompensatory`.`start_date`) + 1 AS `inclusive_days`,
            `tblcompensatory`.`status`,
            CASE `tblcompensatory`.`status`
                WHEN 1 THEN 'Pending'
                WHEN 2 THEN 'Approved'
                WHEN 3 THEN 'Rejected'
                WHEN 4 THEN 'Cancelled'
                ELSE 'Unknown'
            END AS `status_label`,
            `tblcompensatory`.`approved_by`,
            `tblcompensatory`.`approved_at`,
            `tblcompensatory`.`rejected_by`,
            `tblcompensatory`.`rejected_at`,
            `tblcompensatory`.`created_at`,
            {$approverDisplaySelect} AS `approved_by_name`,
            {$rejectorDisplaySelect} AS `rejected_by_name`
         FROM `tblcompensatory`
         INNER JOIN `employees` ON `employees`.`id` = `tblcompensatory`.`employee_id`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         LEFT JOIN `users` AS `approved_user` ON `approved_user`.`id` = `tblcompensatory`.`approved_by`
         LEFT JOIN `users` AS `rejected_user` ON `rejected_user`.`id` = `tblcompensatory`.`rejected_by`";
    $params = [];

    if ($employeeEmail !== null) {
        $query .= ' WHERE `employees`.`email` = :employee_email';
        $params['employee_email'] = $employeeEmail;
    }

    $query .= ' ORDER BY `tblcompensatory`.`created_at` DESC, `tblcompensatory`.`cto_id` DESC';
    $statement = $conn->prepare($query);
    $statement->execute($params);

    return $statement->fetchAll();
}

function fetchCompensatoryRequestById(PDO $conn, bool $hasLegacyNameColumn, int $compensatoryId): ?array
{
    $requests = fetchCompensatoryRequests($conn, $hasLegacyNameColumn);

    foreach ($requests as $request) {
        if ((int) $request['cto_id'] === $compensatoryId) {
            return $request;
        }
    }

    return null;
}

try {
    ensureCompensatoryTableExists($conn);
    $hasLegacyNameColumn = usersTableHasLegacyNameColumn($conn);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $employeeEmail = isset($_GET['employee_email'])
                ? trim((string) $_GET['employee_email'])
                : null;

            if ($employeeEmail !== null && $employeeEmail !== '' && !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
                respond(422, [
                    'message' => 'Please provide a valid employee email.',
                ]);
            }

            respond(200, [
                'employee' => $employeeEmail ? fetchEmployeeProfileByEmail($conn, $employeeEmail) : null,
                'compensatoryRequests' => fetchCompensatoryRequests(
                    $conn,
                    $hasLegacyNameColumn,
                    $employeeEmail ?: null
                ),
            ]);
            break;

        case 'POST':
            $payload = validateCreatePayload($conn, parseJsonPayload());
            $usesAutoIncrement = compensatoryTableUsesAutoIncrement($conn);
            $insertedCompensatoryId = null;

            if ($usesAutoIncrement) {
                $statement = $conn->prepare(
                    'INSERT INTO `tblcompensatory` (
                        `employee_id`,
                        `hours_applied`,
                        `start_date`,
                        `end_date`,
                        `status`
                    ) VALUES (
                        :employee_id,
                        :hours_applied,
                        :start_date,
                        :end_date,
                        :status
                    )'
                );
            } else {
                $insertedCompensatoryId = getNextCompensatoryId($conn);
                $statement = $conn->prepare(
                    'INSERT INTO `tblcompensatory` (
                        `cto_id`,
                        `employee_id`,
                        `hours_applied`,
                        `start_date`,
                        `end_date`,
                        `status`
                    ) VALUES (
                        :cto_id,
                        :employee_id,
                        :hours_applied,
                        :start_date,
                        :end_date,
                        :status
                    )'
                );
                $payload['cto_id'] = $insertedCompensatoryId;
            }

            $statement->execute($payload);

            if ($insertedCompensatoryId === null) {
                $insertedCompensatoryId = (int) $conn->lastInsertId();
            }

            respond(201, [
                'message' => 'Compensatory time off submitted successfully.',
                'compensatoryRequest' => fetchCompensatoryRequestById(
                    $conn,
                    $hasLegacyNameColumn,
                    $insertedCompensatoryId
                ),
            ]);
            break;

        case 'PUT':
            $compensatoryId = getCompensatoryIdFromQuery();

            if ($compensatoryId === null) {
                respond(422, [
                    'message' => 'A valid compensatory request id is required.',
                ]);
            }

            $existingRequest = fetchCompensatoryRequestById($conn, $hasLegacyNameColumn, $compensatoryId);

            if ($existingRequest === null) {
                respond(404, [
                    'message' => 'The selected compensatory request was not found.',
                ]);
            }

            $rawPayload = parseJsonPayload();

            if (($rawPayload['mode'] ?? '') === 'edit') {
                if ((int) $existingRequest['status'] !== COMPENSATORY_STATUS_PENDING) {
                    respond(422, [
                        'message' => 'Only pending compensatory requests can be edited.',
                    ]);
                }

                $payload = validateEditPayload($conn, $rawPayload);
                $statement = $conn->prepare(
                    'UPDATE `tblcompensatory`
                     SET
                        `employee_id` = :employee_id,
                        `hours_applied` = :hours_applied,
                        `start_date` = :start_date,
                        `end_date` = :end_date,
                        `status` = :status,
                        `approved_by` = NULL,
                        `approved_at` = NULL,
                        `rejected_by` = NULL,
                        `rejected_at` = NULL
                     WHERE `cto_id` = :cto_id'
                );
                $statement->execute([
                    'cto_id' => $compensatoryId,
                    'employee_id' => $payload['employee_id'],
                    'end_date' => $payload['end_date'],
                    'hours_applied' => $payload['hours_applied'],
                    'start_date' => $payload['start_date'],
                    'status' => $payload['status'],
                ]);

                respond(200, [
                    'message' => 'Compensatory request updated successfully.',
                    'compensatoryRequest' => fetchCompensatoryRequestById($conn, $hasLegacyNameColumn, $compensatoryId),
                ]);
            }

            $payload = validateDecisionPayload($rawPayload);

            if (
                in_array($payload['status'], [COMPENSATORY_STATUS_APPROVED, COMPENSATORY_STATUS_REJECTED], true) &&
                $payload['admin_user_id'] !== null &&
                isUserTryingToReviewOwnRequest(
                    $conn,
                    $payload['admin_user_id'],
                    $existingRequest['employee_email'] ?? null
                )
            ) {
                respond(403, [
                    'message' => 'You cannot approve or reject your own compensatory request. Another user must review it.',
                ]);
            }

            $isApproved = $payload['status'] === COMPENSATORY_STATUS_APPROVED;
            $isRejected = $payload['status'] === COMPENSATORY_STATUS_REJECTED;
            $statement = $conn->prepare(
                'UPDATE `tblcompensatory`
                 SET
                    `status` = :status,
                    `approved_by` = :approved_by,
                    `approved_at` = :approved_at,
                    `rejected_by` = :rejected_by,
                    `rejected_at` = :rejected_at
                 WHERE `cto_id` = :cto_id'
            );
            $statement->execute([
                'approved_at' => $isApproved ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'approved_by' => $isApproved ? $payload['admin_user_id'] : null,
                'cto_id' => $compensatoryId,
                'rejected_at' => $isRejected ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'rejected_by' => $isRejected ? $payload['admin_user_id'] : null,
                'status' => $payload['status'],
            ]);

            respond(200, [
                'message' => 'Compensatory request updated successfully.',
                'compensatoryRequest' => fetchCompensatoryRequestById($conn, $hasLegacyNameColumn, $compensatoryId),
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process compensatory requests right now.',
    ]);
}
?>
