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

const PASS_SLIP_STATUS_PENDING = 1;
const PASS_SLIP_STATUS_APPROVED = 2;
const PASS_SLIP_STATUS_REJECTED = 3;
const PASS_SLIP_STATUS_CANCELLED = 4;

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

function getPassSlipIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $passSlipId = filter_var($rawId, FILTER_VALIDATE_INT);

    return $passSlipId === false ? null : $passSlipId;
}

function ensurePassSlipsTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `tblpass_slips` (
            `ps_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` INT UNSIGNED NOT NULL,
            `pass_date` DATE NOT NULL,
            `departure_time` TIME NOT NULL,
            `time_returned` TIME NOT NULL,
            `destination` VARCHAR(255) NOT NULL,
            `purpose` TEXT DEFAULT NULL,
            `status` INT NOT NULL DEFAULT 1,
            `approved_by` INT UNSIGNED DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `rejected_by` INT UNSIGNED DEFAULT NULL,
            `rejected_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ps_id`),
            KEY `idx_tblpass_slips_employee_id` (`employee_id`),
            KEY `idx_tblpass_slips_status` (`status`),
            KEY `idx_tblpass_slips_approved_by` (`approved_by`),
            KEY `idx_tblpass_slips_rejected_by` (`rejected_by`),
            CONSTRAINT `fk_tblpass_slips_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT `fk_tblpass_slips_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL,
            CONSTRAINT `fk_tblpass_slips_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
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

function passSlipsTableUsesAutoIncrement(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `tblpass_slips` LIKE 'ps_id'");
    $column = $statement->fetch();

    return $column && stripos((string) ($column['Extra'] ?? ''), 'auto_increment') !== false;
}

function getNextPassSlipId(PDO $conn): int
{
    $statement = $conn->query('SELECT COALESCE(MAX(`ps_id`), 0) + 1 AS `next_id` FROM `tblpass_slips`');

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

function validateTime(string $value, string $label): string
{
    $trimmedValue = trim($value);

    if ($trimmedValue === '') {
        respond(422, [
            'message' => sprintf('%s is required.', $label),
        ]);
    }

    $formats = ['H:i:s', 'H:i'];

    foreach ($formats as $format) {
        $time = DateTime::createFromFormat($format, $trimmedValue);

        if ($time && $time->format($format) === $trimmedValue) {
            return $time->format('H:i:s');
        }
    }

    respond(422, [
        'message' => sprintf('Please enter a valid %s.', strtolower($label)),
    ]);
}

function ensureTimeReturnedAfterDeparture(string $departureTime, string $timeReturned): void
{
    $departure = DateTimeImmutable::createFromFormat('H:i:s', $departureTime);
    $returned = DateTimeImmutable::createFromFormat('H:i:s', $timeReturned);

    if (!$departure || !$returned || $returned <= $departure) {
        respond(422, [
            'message' => 'Time returned must be later than departure time.',
        ]);
    }
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
    $passDate = validateDate((string) ($payload['pass_date'] ?? ''), 'Pass date');
    $departureTime = validateTime((string) ($payload['departure_time'] ?? ''), 'Departure time');
    $timeReturned = validateTime((string) ($payload['time_returned'] ?? ''), 'Time returned');
    $destination = trim((string) ($payload['destination'] ?? ''));
    $purpose = normalizeOptionalString($payload, 'purpose', 5000);

    if ($employeeId === false && ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL))) {
        respond(422, [
            'message' => 'A valid employee is required.',
        ]);
    }

    if ($destination === '') {
        respond(422, [
            'message' => 'Destination is required.',
        ]);
    }

    if (mb_strlen($destination) > 255) {
        respond(422, [
            'message' => 'Destination must not exceed 255 characters.',
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

    ensureTimeReturnedAfterDeparture($departureTime, $timeReturned);

    return [
        'departure_time' => $departureTime,
        'destination' => $destination,
        'employee_id' => $employeeProfile['employee_record_id'],
        'pass_date' => $passDate,
        'purpose' => $purpose,
        'status' => PASS_SLIP_STATUS_PENDING,
        'time_returned' => $timeReturned,
    ];
}

function validateEditPayload(PDO $conn, array $payload): array
{
    $validatedPayload = validateCreatePayload($conn, $payload);

    return [
        'departure_time' => $validatedPayload['departure_time'],
        'destination' => $validatedPayload['destination'],
        'employee_id' => $validatedPayload['employee_id'],
        'pass_date' => $validatedPayload['pass_date'],
        'purpose' => $validatedPayload['purpose'],
        'status' => PASS_SLIP_STATUS_PENDING,
        'time_returned' => $validatedPayload['time_returned'],
    ];
}

function validateDecisionPayload(array $payload): array
{
    $status = filter_var($payload['status'] ?? null, FILTER_VALIDATE_INT);
    $adminUserId = filter_var($payload['admin_user_id'] ?? null, FILTER_VALIDATE_INT);

    if (!in_array($status, [PASS_SLIP_STATUS_APPROVED, PASS_SLIP_STATUS_REJECTED, PASS_SLIP_STATUS_CANCELLED], true)) {
        respond(422, [
            'message' => 'A valid decision status is required.',
        ]);
    }

    if (
        in_array($status, [PASS_SLIP_STATUS_APPROVED, PASS_SLIP_STATUS_REJECTED], true) &&
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

function fetchPassSlips(PDO $conn, bool $hasLegacyNameColumn, ?string $employeeEmail = null): array
{
    $approverDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`name`, `approved_user`.`email`, '')"
        : "COALESCE(NULLIF(`approved_user`.`username`, ''), `approved_user`.`email`, '')";
    $rejectorDisplaySelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`name`, `rejected_user`.`email`, '')"
        : "COALESCE(NULLIF(`rejected_user`.`username`, ''), `rejected_user`.`email`, '')";
    $query = "SELECT
            `tblpass_slips`.`ps_id`,
            CONCAT('PS-', LPAD(`tblpass_slips`.`ps_id`, 3, '0')) AS `request_code`,
            `tblpass_slips`.`employee_id` AS `employee_record_id`,
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
            `tblpass_slips`.`pass_date`,
            `tblpass_slips`.`departure_time`,
            `tblpass_slips`.`time_returned`,
            `tblpass_slips`.`destination`,
            `tblpass_slips`.`purpose`,
            `tblpass_slips`.`status`,
            CASE `tblpass_slips`.`status`
                WHEN 1 THEN 'Pending'
                WHEN 2 THEN 'Approved'
                WHEN 3 THEN 'Rejected'
                WHEN 4 THEN 'Cancelled'
                ELSE 'Unknown'
            END AS `status_label`,
            `tblpass_slips`.`approved_by`,
            `tblpass_slips`.`approved_at`,
            `tblpass_slips`.`rejected_by`,
            `tblpass_slips`.`rejected_at`,
            `tblpass_slips`.`created_at`,
            {$approverDisplaySelect} AS `approved_by_name`,
            {$rejectorDisplaySelect} AS `rejected_by_name`
         FROM `tblpass_slips`
         INNER JOIN `employees` ON `employees`.`id` = `tblpass_slips`.`employee_id`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         LEFT JOIN `users` AS `approved_user` ON `approved_user`.`id` = `tblpass_slips`.`approved_by`
         LEFT JOIN `users` AS `rejected_user` ON `rejected_user`.`id` = `tblpass_slips`.`rejected_by`";
    $params = [];

    if ($employeeEmail !== null) {
        $query .= ' WHERE `employees`.`email` = :employee_email';
        $params['employee_email'] = $employeeEmail;
    }

    $query .= ' ORDER BY `tblpass_slips`.`created_at` DESC, `tblpass_slips`.`ps_id` DESC';
    $statement = $conn->prepare($query);
    $statement->execute($params);

    return $statement->fetchAll();
}

function fetchPassSlipById(PDO $conn, bool $hasLegacyNameColumn, int $passSlipId): ?array
{
    $passSlips = fetchPassSlips($conn, $hasLegacyNameColumn);

    foreach ($passSlips as $passSlip) {
        if ((int) $passSlip['ps_id'] === $passSlipId) {
            return $passSlip;
        }
    }

    return null;
}

try {
    ensurePassSlipsTableExists($conn);
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
                'passSlips' => fetchPassSlips($conn, $hasLegacyNameColumn, $employeeEmail ?: null),
            ]);
            break;

        case 'POST':
            $payload = validateCreatePayload($conn, parseJsonPayload());
            $usesAutoIncrement = passSlipsTableUsesAutoIncrement($conn);
            $insertedPassSlipId = null;

            if ($usesAutoIncrement) {
                $statement = $conn->prepare(
                    'INSERT INTO `tblpass_slips` (
                        `employee_id`,
                        `pass_date`,
                        `departure_time`,
                        `time_returned`,
                        `destination`,
                        `purpose`,
                        `status`
                    ) VALUES (
                        :employee_id,
                        :pass_date,
                        :departure_time,
                        :time_returned,
                        :destination,
                        :purpose,
                        :status
                    )'
                );
            } else {
                $insertedPassSlipId = getNextPassSlipId($conn);
                $statement = $conn->prepare(
                    'INSERT INTO `tblpass_slips` (
                        `ps_id`,
                        `employee_id`,
                        `pass_date`,
                        `departure_time`,
                        `time_returned`,
                        `destination`,
                        `purpose`,
                        `status`
                    ) VALUES (
                        :ps_id,
                        :employee_id,
                        :pass_date,
                        :departure_time,
                        :time_returned,
                        :destination,
                        :purpose,
                        :status
                    )'
                );
                $payload['ps_id'] = $insertedPassSlipId;
            }

            $statement->execute($payload);

            if ($insertedPassSlipId === null) {
                $insertedPassSlipId = (int) $conn->lastInsertId();
            }

            respond(201, [
                'message' => 'Pass slip submitted successfully.',
                'passSlip' => fetchPassSlipById($conn, $hasLegacyNameColumn, $insertedPassSlipId),
            ]);
            break;

        case 'PUT':
            $passSlipId = getPassSlipIdFromQuery();

            if ($passSlipId === null) {
                respond(422, [
                    'message' => 'A valid pass slip id is required.',
                ]);
            }

            $existingPassSlip = fetchPassSlipById($conn, $hasLegacyNameColumn, $passSlipId);

            if ($existingPassSlip === null) {
                respond(404, [
                    'message' => 'The selected pass slip was not found.',
                ]);
            }

            $rawPayload = parseJsonPayload();

            if (($rawPayload['mode'] ?? '') === 'edit') {
                if ((int) $existingPassSlip['status'] !== PASS_SLIP_STATUS_PENDING) {
                    respond(422, [
                        'message' => 'Only pending pass slips can be edited.',
                    ]);
                }

                $payload = validateEditPayload($conn, $rawPayload);
                $statement = $conn->prepare(
                    'UPDATE `tblpass_slips`
                     SET
                        `employee_id` = :employee_id,
                        `pass_date` = :pass_date,
                        `departure_time` = :departure_time,
                        `time_returned` = :time_returned,
                        `destination` = :destination,
                        `purpose` = :purpose,
                        `status` = :status,
                        `approved_by` = NULL,
                        `approved_at` = NULL,
                        `rejected_by` = NULL,
                        `rejected_at` = NULL
                     WHERE `ps_id` = :ps_id'
                );
                $statement->execute([
                    'departure_time' => $payload['departure_time'],
                    'destination' => $payload['destination'],
                    'employee_id' => $payload['employee_id'],
                    'pass_date' => $payload['pass_date'],
                    'ps_id' => $passSlipId,
                    'purpose' => $payload['purpose'],
                    'status' => $payload['status'],
                    'time_returned' => $payload['time_returned'],
                ]);

                respond(200, [
                    'message' => 'Pass slip updated successfully.',
                    'passSlip' => fetchPassSlipById($conn, $hasLegacyNameColumn, $passSlipId),
                ]);
            }

            $payload = validateDecisionPayload($rawPayload);

            if (
                in_array($payload['status'], [PASS_SLIP_STATUS_APPROVED, PASS_SLIP_STATUS_REJECTED], true) &&
                $payload['admin_user_id'] !== null &&
                isUserTryingToReviewOwnRequest(
                    $conn,
                    $payload['admin_user_id'],
                    $existingPassSlip['employee_email'] ?? null
                )
            ) {
                respond(403, [
                    'message' => 'You cannot approve or reject your own pass slip. Another user must review it.',
                ]);
            }

            $isApproved = $payload['status'] === PASS_SLIP_STATUS_APPROVED;
            $isRejected = $payload['status'] === PASS_SLIP_STATUS_REJECTED;
            $statement = $conn->prepare(
                'UPDATE `tblpass_slips`
                 SET
                    `status` = :status,
                    `approved_by` = :approved_by,
                    `approved_at` = :approved_at,
                    `rejected_by` = :rejected_by,
                    `rejected_at` = :rejected_at
                 WHERE `ps_id` = :ps_id'
            );
            $statement->execute([
                'approved_at' => $isApproved ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'approved_by' => $isApproved ? $payload['admin_user_id'] : null,
                'ps_id' => $passSlipId,
                'rejected_at' => $isRejected ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'rejected_by' => $isRejected ? $payload['admin_user_id'] : null,
                'status' => $payload['status'],
            ]);

            respond(200, [
                'message' => 'Pass slip updated successfully.',
                'passSlip' => fetchPassSlipById($conn, $hasLegacyNameColumn, $passSlipId),
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process pass slips right now.',
    ]);
}
?>
