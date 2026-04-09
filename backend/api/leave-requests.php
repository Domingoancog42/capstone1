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

const LEAVE_REQUEST_STATUS_PENDING = 1;
const LEAVE_REQUEST_STATUS_APPROVED = 2;
const LEAVE_REQUEST_STATUS_REJECTED = 3;
const LEAVE_REQUEST_STATUS_CANCELLED = 4;
const LEAVE_REQUEST_STATUS_LABELS = [
    LEAVE_REQUEST_STATUS_PENDING => 'Pending',
    LEAVE_REQUEST_STATUS_APPROVED => 'Approved',
    LEAVE_REQUEST_STATUS_REJECTED => 'Rejected',
    LEAVE_REQUEST_STATUS_CANCELLED => 'Cancelled',
];
const APPROVAL_PAY_TYPES = ['With Pay', 'Without Pay', 'Mixed'];

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

function getLeaveRequestIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $leaveRequestId = filter_var($rawId, FILTER_VALIDATE_INT);

    return $leaveRequestId === false ? null : $leaveRequestId;
}

function ensureLeaveRequestTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `leave_request` (
            `leave_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` INT UNSIGNED NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `leave_type` VARCHAR(100) NOT NULL,
            `status` INT NOT NULL DEFAULT 1,
            `approved_by` INT UNSIGNED DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `rejected_by` INT UNSIGNED DEFAULT NULL,
            `rejected_at` DATETIME DEFAULT NULL,
            `approval_pay_type` VARCHAR(20) DEFAULT NULL,
            `approved_days_with_pay` DECIMAL(5,2) DEFAULT NULL,
            `approved_days_without_pay` DECIMAL(5,2) DEFAULT NULL,
            `decision_note` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`leave_id`),
            KEY `idx_leave_request_employee_id` (`employee_id`),
            KEY `idx_leave_request_status` (`status`),
            KEY `idx_leave_request_approved_by` (`approved_by`),
            KEY `idx_leave_request_rejected_by` (`rejected_by`),
            CONSTRAINT `fk_leave_request_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT `fk_leave_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL,
            CONSTRAINT `fk_leave_request_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function tableExists(PDO $conn, string $tableName): bool
{
    $statement = $conn->query('SHOW TABLES LIKE ' . $conn->quote($tableName));

    return (bool) $statement->fetchColumn();
}

function usersTableHasLegacyNameColumn(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `users` LIKE 'name'");

    return (bool) $statement->fetch();
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

function calculateRequestedDays(string $startDate, string $endDate): int
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

function normalizeOptionalString(array $payload, string $key, int $maxLength): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (mb_strlen($value) > $maxLength) {
        respond(422, [
            'message' => sprintf('%s must not exceed %d characters.', str_replace('_', ' ', $key), $maxLength),
        ]);
    }

    return $value;
}

function normalizeOptionalDecimal(array $payload, string $key): ?string
{
    if (!array_key_exists($key, $payload)) {
        return null;
    }

    $value = trim((string) ($payload[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        respond(422, [
            'message' => sprintf('Please enter a valid %s value.', str_replace('_', ' ', $key)),
        ]);
    }

    $numericValue = (float) $value;

    if ($numericValue < 0) {
        respond(422, [
            'message' => sprintf('%s must not be negative.', str_replace('_', ' ', $key)),
        ]);
    }

    return number_format($numericValue, 2, '.', '');
}

function fetchActiveLeaveTypeNames(PDO $conn): array
{
    if (!tableExists($conn, 'leave_types')) {
        return [];
    }

    $statement = $conn->query(
        "SELECT `name`
         FROM `leave_types`
         WHERE `is_archived` = 0
         ORDER BY `sort_order` ASC, `name` ASC"
    );

    return $statement->fetchAll(PDO::FETCH_COLUMN);
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
    $leaveType = trim((string) ($payload['leave_type'] ?? ''));
    $reason = normalizeOptionalString($payload, 'reason', 255);
    $startDate = validateDate((string) ($payload['start_date'] ?? ''), 'Start date');
    $endDate = validateDate((string) ($payload['end_date'] ?? ''), 'End date');

    if ($employeeId === false && ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL))) {
        respond(422, [
            'message' => 'A valid employee is required.',
        ]);
    }

    if ($leaveType === '') {
        respond(422, [
            'message' => 'Leave type is required.',
        ]);
    }

    if (mb_strlen($leaveType) > 100) {
        respond(422, [
            'message' => 'Leave type must not exceed 100 characters.',
        ]);
    }

    $availableLeaveTypes = fetchActiveLeaveTypeNames($conn);

    if ($availableLeaveTypes !== [] && !in_array($leaveType, $availableLeaveTypes, true)) {
        respond(422, [
            'message' => 'The selected leave type was not found.',
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

    calculateRequestedDays($startDate, $endDate);

    return [
        'employee_id' => $employeeProfile['employee_record_id'],
        'end_date' => $endDate,
        'leave_type' => $leaveType,
        'reason' => $reason,
        'start_date' => $startDate,
        'status' => LEAVE_REQUEST_STATUS_PENDING,
    ];
}

function validateEditPayload(PDO $conn, array $payload): array
{
    $validatedPayload = validateCreatePayload($conn, $payload);

    return [
        'employee_id' => $validatedPayload['employee_id'],
        'end_date' => $validatedPayload['end_date'],
        'leave_type' => $validatedPayload['leave_type'],
        'reason' => $validatedPayload['reason'],
        'start_date' => $validatedPayload['start_date'],
        'status' => LEAVE_REQUEST_STATUS_PENDING,
    ];
}

function validateDecisionPayload(array $payload): array
{
    $status = filter_var($payload['status'] ?? null, FILTER_VALIDATE_INT);
    $adminUserId = filter_var($payload['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
    $approvalPayType = normalizeOptionalString($payload, 'approval_pay_type', 20);
    $approvedDaysWithPay = normalizeOptionalDecimal($payload, 'approved_days_with_pay');
    $approvedDaysWithoutPay = normalizeOptionalDecimal($payload, 'approved_days_without_pay');
    $decisionNote = normalizeOptionalString($payload, 'decision_note', 5000);

    if (!in_array($status, [LEAVE_REQUEST_STATUS_APPROVED, LEAVE_REQUEST_STATUS_REJECTED, LEAVE_REQUEST_STATUS_CANCELLED], true)) {
        respond(422, [
            'message' => 'A valid decision status is required.',
        ]);
    }

    if (
        in_array($status, [LEAVE_REQUEST_STATUS_APPROVED, LEAVE_REQUEST_STATUS_REJECTED], true) &&
        ($adminUserId === false || $adminUserId === null)
    ) {
        respond(422, [
            'message' => 'A valid admin user id is required.',
        ]);
    }

    if ($approvalPayType !== null && !in_array($approvalPayType, APPROVAL_PAY_TYPES, true)) {
        respond(422, [
            'message' => 'Please select a valid approval pay type.',
        ]);
    }

    return [
        'admin_user_id' => ($adminUserId === false || $adminUserId === null)
            ? null
            : (int) $adminUserId,
        'approval_pay_type' => $approvalPayType,
        'approved_days_with_pay' => $approvedDaysWithPay,
        'approved_days_without_pay' => $approvedDaysWithoutPay,
        'decision_note' => $decisionNote,
        'status' => (int) $status,
    ];
}

function fetchLeaveRequests(PDO $conn, bool $hasLegacyNameColumn, ?string $employeeEmail = null): array
{
    $approverDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`name`, `approved_user`.`email`, '')"
        : "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`email`, '')";
    $rejectorDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`name`, `rejected_user`.`email`, '')"
        : "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`email`, '')";
    $query = "SELECT
            `leave_request`.`leave_id`,
            CONCAT('LR-', LPAD(`leave_request`.`leave_id`, 3, '0')) AS `request_code`,
            `leave_request`.`employee_id` AS `employee_record_id`,
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
            `leave_request`.`start_date`,
            `leave_request`.`end_date`,
            DATEDIFF(`leave_request`.`end_date`, `leave_request`.`start_date`) + 1 AS `requested_days`,
            `leave_request`.`reason`,
            `leave_request`.`leave_type`,
            `leave_request`.`status`,
            CASE `leave_request`.`status`
                WHEN 1 THEN 'Pending'
                WHEN 2 THEN 'Approved'
                WHEN 3 THEN 'Rejected'
                WHEN 4 THEN 'Cancelled'
                ELSE 'Unknown'
            END AS `status_label`,
            `leave_request`.`approved_by`,
            `leave_request`.`approved_at`,
            `leave_request`.`rejected_by`,
            `leave_request`.`rejected_at`,
            `leave_request`.`approval_pay_type`,
            `leave_request`.`approved_days_with_pay`,
            `leave_request`.`approved_days_without_pay`,
            `leave_request`.`decision_note`,
            `leave_request`.`created_at`,
            {$approverDisplaySelect} AS `approved_by_name`,
            {$rejectorDisplaySelect} AS `rejected_by_name`
         FROM `leave_request`
         INNER JOIN `employees` ON `employees`.`id` = `leave_request`.`employee_id`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         LEFT JOIN `users` AS `approved_user` ON `approved_user`.`id` = `leave_request`.`approved_by`
         LEFT JOIN `users` AS `rejected_user` ON `rejected_user`.`id` = `leave_request`.`rejected_by`";
    $params = [];

    if ($employeeEmail !== null) {
        $query .= ' WHERE `employees`.`email` = :employee_email';
        $params['employee_email'] = $employeeEmail;
    }

    $query .= ' ORDER BY `leave_request`.`created_at` DESC, `leave_request`.`leave_id` DESC';
    $statement = $conn->prepare($query);
    $statement->execute($params);

    return $statement->fetchAll();
}

function fetchLeaveRequestById(PDO $conn, bool $hasLegacyNameColumn, int $leaveRequestId): ?array
{
    $requests = fetchLeaveRequests($conn, $hasLegacyNameColumn);

    foreach ($requests as $request) {
        if ((int) $request['leave_id'] === $leaveRequestId) {
            return $request;
        }
    }

    return null;
}

try {
    ensureLeaveRequestTableExists($conn);
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
                'requests' => fetchLeaveRequests($conn, $hasLegacyNameColumn, $employeeEmail ?: null),
            ]);
            break;

        case 'POST':
            $payload = validateCreatePayload($conn, parseJsonPayload());
            $statement = $conn->prepare(
                'INSERT INTO `leave_request` (
                    `employee_id`,
                    `start_date`,
                    `end_date`,
                    `reason`,
                    `leave_type`,
                    `status`
                ) VALUES (
                    :employee_id,
                    :start_date,
                    :end_date,
                    :reason,
                    :leave_type,
                    :status
                )'
            );
            $statement->execute($payload);

            respond(201, [
                'message' => 'Leave request submitted successfully.',
                'request' => fetchLeaveRequestById($conn, $hasLegacyNameColumn, (int) $conn->lastInsertId()),
            ]);
            break;

        case 'PUT':
            $leaveRequestId = getLeaveRequestIdFromQuery();

            if ($leaveRequestId === null) {
                respond(422, [
                    'message' => 'A valid leave request id is required.',
                ]);
            }

            $existingRequest = fetchLeaveRequestById($conn, $hasLegacyNameColumn, $leaveRequestId);

            if ($existingRequest === null) {
                respond(404, [
                    'message' => 'The selected leave request was not found.',
                ]);
            }

            $rawPayload = parseJsonPayload();

            if (($rawPayload['mode'] ?? '') === 'edit') {
                if ((int) $existingRequest['status'] !== LEAVE_REQUEST_STATUS_PENDING) {
                    respond(422, [
                        'message' => 'Only pending leave requests can be edited.',
                    ]);
                }

                $payload = validateEditPayload($conn, $rawPayload);
                $statement = $conn->prepare(
                    'UPDATE `leave_request`
                     SET
                        `employee_id` = :employee_id,
                        `start_date` = :start_date,
                        `end_date` = :end_date,
                        `reason` = :reason,
                        `leave_type` = :leave_type,
                        `status` = :status,
                        `approved_by` = NULL,
                        `approved_at` = NULL,
                        `rejected_by` = NULL,
                        `rejected_at` = NULL,
                        `approval_pay_type` = NULL,
                        `approved_days_with_pay` = NULL,
                        `approved_days_without_pay` = NULL,
                        `decision_note` = NULL
                     WHERE `leave_id` = :leave_id'
                );
                $statement->execute([
                    'employee_id' => $payload['employee_id'],
                    'end_date' => $payload['end_date'],
                    'leave_id' => $leaveRequestId,
                    'leave_type' => $payload['leave_type'],
                    'reason' => $payload['reason'],
                    'start_date' => $payload['start_date'],
                    'status' => $payload['status'],
                ]);

                respond(200, [
                    'message' => 'Leave request updated successfully.',
                    'request' => fetchLeaveRequestById($conn, $hasLegacyNameColumn, $leaveRequestId),
                ]);
            }

            $payload = validateDecisionPayload($rawPayload);

            if (
                in_array($payload['status'], [LEAVE_REQUEST_STATUS_APPROVED, LEAVE_REQUEST_STATUS_REJECTED], true) &&
                $payload['admin_user_id'] !== null &&
                isUserTryingToReviewOwnRequest(
                    $conn,
                    $payload['admin_user_id'],
                    $existingRequest['employee_email'] ?? null
                )
            ) {
                respond(403, [
                    'message' => 'You cannot approve or reject your own leave request. Another user must review it.',
                ]);
            }

            $isApproved = $payload['status'] === LEAVE_REQUEST_STATUS_APPROVED;
            $isRejected = $payload['status'] === LEAVE_REQUEST_STATUS_REJECTED;
            $statement = $conn->prepare(
                'UPDATE `leave_request`
                 SET
                    `status` = :status,
                    `approved_by` = :approved_by,
                    `approved_at` = :approved_at,
                    `rejected_by` = :rejected_by,
                    `rejected_at` = :rejected_at,
                    `approval_pay_type` = :approval_pay_type,
                    `approved_days_with_pay` = :approved_days_with_pay,
                    `approved_days_without_pay` = :approved_days_without_pay,
                    `decision_note` = :decision_note
                 WHERE `leave_id` = :leave_id'
            );
            $statement->execute([
                'approval_pay_type' => $isApproved ? $payload['approval_pay_type'] : null,
                'approved_at' => $isApproved ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'approved_by' => $isApproved ? $payload['admin_user_id'] : null,
                'approved_days_with_pay' => $isApproved ? $payload['approved_days_with_pay'] : null,
                'approved_days_without_pay' => $isApproved ? $payload['approved_days_without_pay'] : null,
                'decision_note' => ($isApproved || $isRejected) ? $payload['decision_note'] : null,
                'leave_id' => $leaveRequestId,
                'rejected_at' => $isRejected ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'rejected_by' => $isRejected ? $payload['admin_user_id'] : null,
                'status' => $payload['status'],
            ]);

            respond(200, [
                'message' => 'Leave request updated successfully.',
                'request' => fetchLeaveRequestById($conn, $hasLegacyNameColumn, $leaveRequestId),
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process leave requests right now.',
    ]);
}
