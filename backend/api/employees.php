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

const EMPLOYEE_STATUS_NAMES = ['Pending', 'Active', 'Inactive'];

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

function getEmployeeRecordIdFromQuery(): ?int
{
    $rawId = $_GET['id'] ?? null;
    $employeeId = filter_var($rawId, FILTER_VALIDATE_INT);
    return $employeeId === false ? null : $employeeId;
}

function normalizeOptionalString(array $payload, string $key): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));
    return $value === '' ? null : $value;
}

function normalizeOptionalDate(array $payload, string $key): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        respond(422, [
            'message' => sprintf('Please enter a valid %s date.', str_replace('_', ' ', $key)),
        ]);
    }

    return $value;
}

function normalizeOptionalNumeric(array $payload, string $key): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        respond(422, [
            'message' => sprintf('Please enter a valid %s value.', str_replace('_', ' ', $key)),
        ]);
    }

    return (string) $value;
}

function normalizeOptionalDigits(array $payload, string $key, int $maxLength): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d+$/', $value)) {
        respond(422, [
            'message' => sprintf('%s must contain numbers only.', str_replace('_', ' ', $key)),
        ]);
    }

    if (strlen($value) > $maxLength) {
        respond(422, [
            'message' => sprintf('%s must not exceed %d digits.', str_replace('_', ' ', $key), $maxLength),
        ]);
    }

    return $value;
}

function divisionExists(PDO $conn, int $divisionId): bool
{
    $statement = $conn->prepare('SELECT `id` FROM `divisions` WHERE `id` = :id LIMIT 1');
    $statement->execute([
        'id' => $divisionId,
    ]);

    return (bool) $statement->fetch();
}

function designationExists(PDO $conn, int $designationId): bool
{
    $statement = $conn->prepare('SELECT `id` FROM `designations` WHERE `id` = :id LIMIT 1');
    $statement->execute([
        'id' => $designationId,
    ]);

    return (bool) $statement->fetch();
}

function designationBelongsToDivision(PDO $conn, int $designationId, int $divisionId): bool
{
    $statement = $conn->prepare(
        'SELECT `id` FROM `designations` WHERE `id` = :designation_id AND `division_id` = :division_id LIMIT 1'
    );
    $statement->execute([
        'designation_id' => $designationId,
        'division_id' => $divisionId,
    ]);

    return (bool) $statement->fetch();
}

function getCurrentEmployeeIdYear(): string
{
    return (new DateTimeImmutable('now'))->format('Y');
}

function formatEmployeeId(string $year, int $sequence): string
{
    return sprintf('EMP%s-%02d', $year, $sequence);
}

function generateNextEmployeeId(PDO $conn): string
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

    return formatEmployeeId($year, $maxSequence + 1);
}

function validateEmployeePayload(PDO $conn, array $payload, bool $requireEmployeeId = true): array
{
    $employeeId = trim((string) ($payload['employee_id'] ?? ''));
    $firstName = trim((string) ($payload['first_name'] ?? ''));
    $lastName = trim((string) ($payload['last_name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $status = trim((string) ($payload['status'] ?? ''));
    $divisionId = filter_var($payload['division_id'] ?? null, FILTER_VALIDATE_INT);
    $designationId = filter_var($payload['designation_id'] ?? null, FILTER_VALIDATE_INT);
    $pwd = filter_var($payload['pwd'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (
        ($requireEmployeeId && $employeeId === '')
        || $firstName === ''
        || $lastName === ''
        || $email === ''
        || $status === ''
        || $divisionId === false
        || $designationId === false
    ) {
        respond(422, [
            'message' => $requireEmployeeId
                ? 'Employee ID, first name, last name, email, division, designation, and status are required.'
                : 'First name, last name, email, division, designation, and status are required.',
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'message' => 'Please enter a valid email address.',
        ]);
    }

    if (!in_array($status, EMPLOYEE_STATUS_NAMES, true)) {
        respond(422, [
            'message' => 'Please select a valid employee status.',
        ]);
    }

    if ($pwd === null) {
        respond(422, [
            'message' => 'Please provide a valid PWD value.',
        ]);
    }

    if (!divisionExists($conn, (int) $divisionId)) {
        respond(422, [
            'message' => 'The selected division was not found.',
        ]);
    }

    if (!designationExists($conn, (int) $designationId)) {
        respond(422, [
            'message' => 'The selected designation was not found.',
        ]);
    }

    if (!designationBelongsToDivision($conn, (int) $designationId, (int) $divisionId)) {
        respond(422, [
            'message' => 'The selected designation does not belong to the selected division.',
        ]);
    }

    return [
        'address' => normalizeOptionalString($payload, 'address'),
        'basic_salary' => normalizeOptionalNumeric($payload, 'basic_salary'),
        'blood_type' => normalizeOptionalString($payload, 'blood_type'),
        'city' => normalizeOptionalString($payload, 'city'),
        'civil_status' => normalizeOptionalString($payload, 'civil_status'),
        'date_hired' => normalizeOptionalDate($payload, 'date_hired'),
        'date_of_birth' => normalizeOptionalDate($payload, 'date_of_birth'),
        'designation_id' => (int) $designationId,
        'division_id' => (int) $divisionId,
        'e_signature' => normalizeOptionalString($payload, 'e_signature'),
        'email' => $email,
        'emp_gsis_id_no' => normalizeOptionalDigits($payload, 'emp_gsis_id_no', 11),
        'emp_pagibig_id_no' => normalizeOptionalDigits($payload, 'emp_pagibig_id_no', 11),
        'emp_philhealth_id_no' => normalizeOptionalDigits($payload, 'emp_philhealth_id_no', 11),
        'employee_id' => $employeeId,
        'employment_status' => normalizeOptionalString($payload, 'employment_status'),
        'first_name' => $firstName,
        'gender' => normalizeOptionalString($payload, 'gender'),
        'height' => normalizeOptionalNumeric($payload, 'height'),
        'last_name' => $lastName,
        'middle_name' => normalizeOptionalString($payload, 'middle_name'),
        'phone' => normalizeOptionalDigits($payload, 'phone', 11),
        'profile_image' => normalizeOptionalString($payload, 'profile_image'),
        'province' => normalizeOptionalString($payload, 'province'),
        'pwd' => $pwd ? 1 : 0,
        'salary_rate' => normalizeOptionalString($payload, 'salary_rate'),
        'status' => $status,
        'tin_no' => normalizeOptionalDigits($payload, 'tin_no', 11),
        'weight' => normalizeOptionalNumeric($payload, 'weight'),
        'zip_code' => normalizeOptionalDigits($payload, 'zip_code', 4),
    ];
}

function fetchEmployees(PDO $conn): array
{
    $statement = $conn->query(
        "SELECT
            `employees`.`id`,
            `employees`.`employee_id`,
            `employees`.`first_name`,
            `employees`.`middle_name`,
            `employees`.`last_name`,
            TRIM(CONCAT(
                `employees`.`first_name`,
                ' ',
                COALESCE(CONCAT(`employees`.`middle_name`, ' '), ''),
                `employees`.`last_name`
            )) AS `full_name`,
            `employees`.`date_of_birth`,
            `employees`.`address`,
            `employees`.`city`,
            `employees`.`province`,
            `employees`.`zip_code`,
            `employees`.`gender`,
            `employees`.`email`,
            `employees`.`phone`,
            `employees`.`profile_image`,
            `employees`.`e_signature`,
            `employees`.`division_id`,
            `divisions`.`name` AS `division`,
            `employees`.`designation_id`,
            `designations`.`name` AS `designation`,
            `employees`.`basic_salary`,
            `employees`.`salary_rate`,
            `employees`.`date_hired`,
            `employees`.`status`,
            `employees`.`employment_status`,
            `employees`.`pwd`,
            `employees`.`civil_status`,
            `employees`.`height`,
            `employees`.`weight`,
            `employees`.`blood_type`,
            `employees`.`emp_gsis_id_no`,
            `employees`.`emp_pagibig_id_no`,
            `employees`.`emp_philhealth_id_no`,
            `employees`.`tin_no`
         FROM `employees`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         ORDER BY `employees`.`id` DESC"
    );

    return $statement->fetchAll();
}

function fetchEmployeeById(PDO $conn, int $employeeRecordId): ?array
{
    $statement = $conn->prepare(
        "SELECT
            `employees`.`id`,
            `employees`.`employee_id`,
            `employees`.`first_name`,
            `employees`.`middle_name`,
            `employees`.`last_name`,
            TRIM(CONCAT(
                `employees`.`first_name`,
                ' ',
                COALESCE(CONCAT(`employees`.`middle_name`, ' '), ''),
                `employees`.`last_name`
            )) AS `full_name`,
            `employees`.`date_of_birth`,
            `employees`.`address`,
            `employees`.`city`,
            `employees`.`province`,
            `employees`.`zip_code`,
            `employees`.`gender`,
            `employees`.`email`,
            `employees`.`phone`,
            `employees`.`profile_image`,
            `employees`.`e_signature`,
            `employees`.`division_id`,
            `divisions`.`name` AS `division`,
            `employees`.`designation_id`,
            `designations`.`name` AS `designation`,
            `employees`.`basic_salary`,
            `employees`.`salary_rate`,
            `employees`.`date_hired`,
            `employees`.`status`,
            `employees`.`employment_status`,
            `employees`.`pwd`,
            `employees`.`civil_status`,
            `employees`.`height`,
            `employees`.`weight`,
            `employees`.`blood_type`,
            `employees`.`emp_gsis_id_no`,
            `employees`.`emp_pagibig_id_no`,
            `employees`.`emp_philhealth_id_no`,
            `employees`.`tin_no`
         FROM `employees`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         WHERE `employees`.`id` = :id
         LIMIT 1"
    );
    $statement->execute([
        'id' => $employeeRecordId,
    ]);

    $employee = $statement->fetch();
    return $employee ?: null;
}

function isDuplicateEntryError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

function isDuplicateEmployeeIdError(PDOException $exception): bool
{
    if (!isDuplicateEntryError($exception)) {
        return false;
    }

    $errorMessage = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
    return stripos($errorMessage, 'employee_id') !== false;
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'employees' => fetchEmployees($conn),
            ]);
            break;

        case 'POST':
            $payload = validateEmployeePayload($conn, parseJsonPayload(), false);
            $statement = $conn->prepare(
                'INSERT INTO `employees` (
                    `employee_id`,
                    `first_name`,
                    `middle_name`,
                    `last_name`,
                    `date_of_birth`,
                    `address`,
                    `city`,
                    `province`,
                    `zip_code`,
                    `gender`,
                    `email`,
                    `phone`,
                    `profile_image`,
                    `e_signature`,
                    `division_id`,
                    `designation_id`,
                    `basic_salary`,
                    `salary_rate`,
                    `date_hired`,
                    `status`,
                    `employment_status`,
                    `pwd`,
                    `civil_status`,
                    `height`,
                    `weight`,
                    `blood_type`,
                    `emp_gsis_id_no`,
                    `emp_pagibig_id_no`,
                    `emp_philhealth_id_no`,
                    `tin_no`
                ) VALUES (
                    :employee_id,
                    :first_name,
                    :middle_name,
                    :last_name,
                    :date_of_birth,
                    :address,
                    :city,
                    :province,
                    :zip_code,
                    :gender,
                    :email,
                    :phone,
                    :profile_image,
                    :e_signature,
                    :division_id,
                    :designation_id,
                    :basic_salary,
                    :salary_rate,
                    :date_hired,
                    :status,
                    :employment_status,
                    :pwd,
                    :civil_status,
                    :height,
                    :weight,
                    :blood_type,
                    :emp_gsis_id_no,
                    :emp_pagibig_id_no,
                    :emp_philhealth_id_no,
                    :tin_no
                )'
            );

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $payload['employee_id'] = generateNextEmployeeId($conn);

                try {
                    $statement->execute($payload);

                    respond(201, [
                        'employee' => fetchEmployeeById($conn, (int) $conn->lastInsertId()),
                        'message' => 'Employee added successfully.',
                    ]);
                } catch (PDOException $exception) {
                    if (isDuplicateEmployeeIdError($exception) && $attempt < 4) {
                        continue;
                    }

                    if (isDuplicateEmployeeIdError($exception)) {
                        respond(409, [
                            'message' => 'Unable to generate a unique employee ID right now. Please try again.',
                        ]);
                    }

                    if (isDuplicateEntryError($exception)) {
                        respond(409, [
                            'message' => 'That employee ID is already in use.',
                        ]);
                    }

                    throw $exception;
                }
            }

            respond(500, [
                'message' => 'Unable to generate an employee ID right now.',
            ]);
            break;

        case 'PUT':
            $employeeRecordId = getEmployeeRecordIdFromQuery();

            if ($employeeRecordId === null) {
                respond(422, [
                    'message' => 'A valid employee id is required.',
                ]);
            }

            $existingEmployee = fetchEmployeeById($conn, $employeeRecordId);

            if ($existingEmployee === null) {
                respond(404, [
                    'message' => 'The selected employee was not found.',
                ]);
            }

            $payload = validateEmployeePayload($conn, parseJsonPayload(), false);
            $payload['employee_id'] = $existingEmployee['employee_id'];

            try {
                $statement = $conn->prepare(
                    'UPDATE `employees`
                     SET
                        `employee_id` = :employee_id,
                        `first_name` = :first_name,
                        `middle_name` = :middle_name,
                        `last_name` = :last_name,
                        `date_of_birth` = :date_of_birth,
                        `address` = :address,
                        `city` = :city,
                        `province` = :province,
                        `zip_code` = :zip_code,
                        `gender` = :gender,
                        `email` = :email,
                        `phone` = :phone,
                        `profile_image` = :profile_image,
                        `e_signature` = :e_signature,
                        `division_id` = :division_id,
                        `designation_id` = :designation_id,
                        `basic_salary` = :basic_salary,
                        `salary_rate` = :salary_rate,
                        `date_hired` = :date_hired,
                        `status` = :status,
                        `employment_status` = :employment_status,
                        `pwd` = :pwd,
                        `civil_status` = :civil_status,
                        `height` = :height,
                        `weight` = :weight,
                        `blood_type` = :blood_type,
                        `emp_gsis_id_no` = :emp_gsis_id_no,
                        `emp_pagibig_id_no` = :emp_pagibig_id_no,
                        `emp_philhealth_id_no` = :emp_philhealth_id_no,
                        `tin_no` = :tin_no
                     WHERE `id` = :id'
                );
                $statement->execute(array_merge($payload, [
                    'id' => $employeeRecordId,
                ]));
            } catch (PDOException $exception) {
                if (isDuplicateEntryError($exception)) {
                    respond(409, [
                        'message' => 'That employee ID is already in use.',
                    ]);
                }

                throw $exception;
            }

            respond(200, [
                'employee' => fetchEmployeeById($conn, $employeeRecordId),
                'message' => 'Employee updated successfully.',
            ]);
            break;

        case 'DELETE':
            $employeeRecordId = getEmployeeRecordIdFromQuery();

            if ($employeeRecordId === null) {
                respond(422, [
                    'message' => 'A valid employee id is required.',
                ]);
            }

            if (fetchEmployeeById($conn, $employeeRecordId) === null) {
                respond(404, [
                    'message' => 'The selected employee was not found.',
                ]);
            }

            $statement = $conn->prepare('DELETE FROM `employees` WHERE `id` = :id');
            $statement->execute([
                'id' => $employeeRecordId,
            ]);

            respond(200, [
                'message' => 'Employee deleted successfully.',
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (PDOException $exception) {
    respond(500, [
        'message' => 'Unable to process employees right now.',
    ]);
}
