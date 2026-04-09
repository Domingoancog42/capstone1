<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

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

const ATTENDANCE_IMPORT_HEADERS = [
    'employee_id',
    'attendance_date',
    'time_in',
    'time_out',
    'status',
    'remarks',
];
const ATTENDANCE_TEMPLATE_SAMPLE_EMPLOYEE_IDS = [
    'EMPLOYEE_ID_HERE',
    'EMPLOYEE_ID_SAMPLE',
];

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function tableExists(PDO $conn, string $tableName): bool
{
    $statement = $conn->query('SHOW TABLES LIKE ' . $conn->quote($tableName));

    return (bool) $statement->fetchColumn();
}

function ensureDtrTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `dtr` (
            `attendance_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` VARCHAR(50) NOT NULL,
            `attendance_date` DATE NOT NULL,
            `time_in` TIME DEFAULT NULL,
            `time_out` TIME DEFAULT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Present',
            `remarks` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`attendance_id`),
            UNIQUE KEY `uniq_dtr_employee_date` (`employee_id`, `attendance_date`),
            KEY `idx_dtr_attendance_date` (`attendance_date`),
            CONSTRAINT `fk_dtr_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function migrateLegacyAttendanceRecords(PDO $conn): void
{
    if (!tableExists($conn, 'attendance_records')) {
        return;
    }

    $conn->exec(
        "INSERT IGNORE INTO `dtr` (
            `employee_id`,
            `attendance_date`,
            `time_in`,
            `time_out`,
            `status`,
            `remarks`,
            `created_at`
        )
        SELECT
            `employees`.`employee_id`,
            `attendance_records`.`attendance_date`,
            `attendance_records`.`time_in`,
            `attendance_records`.`time_out`,
            `attendance_records`.`status`,
            `attendance_records`.`remarks`,
            `attendance_records`.`created_at`
        FROM `attendance_records`
        INNER JOIN `employees` ON `employees`.`id` = `attendance_records`.`employee_record_id`"
    );
}

function fetchAttendance(PDO $conn): array
{
    $statement = $conn->query(
        "SELECT
            `dtr`.`attendance_id` AS `id`,
            `dtr`.`attendance_id`,
            `dtr`.`employee_id`,
            TRIM(CONCAT(
                `employees`.`first_name`,
                ' ',
                COALESCE(CONCAT(`employees`.`middle_name`, ' '), ''),
                `employees`.`last_name`
            )) AS `full_name`,
            `divisions`.`name` AS `division`,
            `designations`.`name` AS `designation`,
            `dtr`.`attendance_date`,
            `dtr`.`time_in`,
            `dtr`.`time_out`,
            `dtr`.`status`,
            `dtr`.`remarks`,
            `dtr`.`created_at`
         FROM `dtr`
         INNER JOIN `employees` ON `employees`.`employee_id` = `dtr`.`employee_id`
         INNER JOIN `divisions` ON `divisions`.`id` = `employees`.`division_id`
         INNER JOIN `designations` ON `designations`.`id` = `employees`.`designation_id`
         ORDER BY `dtr`.`attendance_date` DESC, `dtr`.`employee_id` ASC, `dtr`.`attendance_id` DESC"
    );

    return $statement->fetchAll();
}

function normalizeCsvHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);

    return trim($value, '_');
}

function rowHasData(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return true;
        }
    }

    return false;
}

function normalizeUploadedDate(string $value, int $rowNumber): string
{
    $formats = ['Y-m-d', 'm/d/Y', 'n/j/Y'];
    $trimmedValue = trim($value);

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $trimmedValue);

        if ($date && $date->format($format) === $trimmedValue) {
            return $date->format('Y-m-d');
        }
    }

    throw new RuntimeException(sprintf('Row %d: attendance_date must use a valid date format.', $rowNumber));
}

function normalizeUploadedTime(?string $value, int $rowNumber, string $fieldName): ?string
{
    $trimmedValue = trim((string) $value);

    if ($trimmedValue === '') {
        return null;
    }

    $normalizedValue = preg_replace('/\s+/', ' ', $trimmedValue);
    $formats = [
        'H:i:s',
        'H:i',
        'G:i:s',
        'G:i',
        'h:i A',
        'h:i a',
        'h:i:s A',
        'h:i:s a',
        'g:i A',
        'g:i a',
        'g:iA',
        'g:ia',
        'g:i:s A',
        'g:i:s a',
        'g:i:sA',
        'g:i:sa',
    ];

    foreach ($formats as $format) {
        $time = DateTime::createFromFormat($format, $normalizedValue);

        if ($time && $time->format($format) === $normalizedValue) {
            return $time->format('H:i:s');
        }
    }

    $timestamp = strtotime('1970-01-01 ' . $normalizedValue);

    if ($timestamp !== false) {
        return date('H:i:s', $timestamp);
    }

    throw new RuntimeException(sprintf('Row %d: %s must use a valid time format.', $rowNumber, $fieldName));
}

function getUploadedFile(): array
{
    $file = $_FILES['file'] ?? null;

    if (!is_array($file)) {
        respond(422, [
            'message' => 'Please upload a CSV file.',
        ]);
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        respond(422, [
            'message' => 'Please choose a CSV file to import.',
        ]);
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        respond(422, [
            'message' => 'The attendance file could not be uploaded.',
        ]);
    }

    return $file;
}

function employeeExistsByEmployeeId(PDOStatement $statement, string $employeeId): bool
{
    $statement->execute([
        'employee_id' => $employeeId,
    ]);

    return (bool) $statement->fetch();
}

function getExistingAttendanceRecordId(PDOStatement $statement, string $employeeId, string $attendanceDate): ?int
{
    $statement->execute([
        'attendance_date' => $attendanceDate,
        'employee_id' => $employeeId,
    ]);

    $record = $statement->fetch();

    return $record ? (int) $record['attendance_id'] : null;
}

function isTemplateSampleRow(array $rowData): bool
{
    return in_array(strtoupper($rowData['employee_id'] ?? ''), ATTENDANCE_TEMPLATE_SAMPLE_EMPLOYEE_IDS, true);
}

function importAttendanceFile(PDO $conn, array $file): array
{
    $handle = fopen($file['tmp_name'], 'rb');

    if ($handle === false) {
        respond(422, [
            'message' => 'The uploaded attendance file could not be opened.',
        ]);
    }

    $headerRow = fgetcsv($handle);

    if ($headerRow === false) {
        fclose($handle);
        respond(422, [
            'message' => 'The attendance file is empty.',
        ]);
    }

    $normalizedHeaders = array_map(
        static fn($header) => normalizeCsvHeader((string) $header),
        $headerRow
    );
    $missingHeaders = array_values(array_diff(ATTENDANCE_IMPORT_HEADERS, $normalizedHeaders));

    if ($missingHeaders !== []) {
        fclose($handle);
        respond(422, [
            'message' => 'The uploaded file is missing required columns: ' . implode(', ', $missingHeaders) . '.',
        ]);
    }

    $headerIndex = array_flip($normalizedHeaders);
    $employeeLookupStatement = $conn->prepare(
        'SELECT `employee_id` FROM `employees` WHERE `employee_id` = :employee_id LIMIT 1'
    );
    $attendanceLookupStatement = $conn->prepare(
        'SELECT `attendance_id` FROM `dtr` WHERE `employee_id` = :employee_id AND `attendance_date` = :attendance_date LIMIT 1'
    );
    $insertStatement = $conn->prepare(
        'INSERT INTO `dtr` (
            `employee_id`,
            `attendance_date`,
            `time_in`,
            `time_out`,
            `status`,
            `remarks`
        ) VALUES (
            :employee_id,
            :attendance_date,
            :time_in,
            :time_out,
            :status,
            :remarks
        )'
    );
    $updateStatement = $conn->prepare(
        'UPDATE `dtr`
         SET
            `time_in` = :time_in,
            `time_out` = :time_out,
            `status` = :status,
            `remarks` = :remarks
         WHERE `attendance_id` = :attendance_id'
    );

    $conn->beginTransaction();
    $employeeIdCache = [];
    $errors = [];
    $importedCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    $rowNumber = 1;

    try {
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (!rowHasData($row)) {
                $skippedCount++;
                continue;
            }

            $rowData = [];

            foreach (ATTENDANCE_IMPORT_HEADERS as $header) {
                $rowData[$header] = trim((string) ($row[$headerIndex[$header]] ?? ''));
            }

            if (isTemplateSampleRow($rowData)) {
                $skippedCount++;
                continue;
            }

            try {
                if ($rowData['employee_id'] === '') {
                    throw new RuntimeException(sprintf('Row %d: employee_id is required.', $rowNumber));
                }

                if ($rowData['attendance_date'] === '') {
                    throw new RuntimeException(sprintf('Row %d: attendance_date is required.', $rowNumber));
                }

                if (!array_key_exists($rowData['employee_id'], $employeeIdCache)) {
                    $employeeIdCache[$rowData['employee_id']] = employeeExistsByEmployeeId(
                        $employeeLookupStatement,
                        $rowData['employee_id']
                    );
                }

                if (!$employeeIdCache[$rowData['employee_id']]) {
                    throw new RuntimeException(
                        sprintf('Row %d: employee_id "%s" was not found.', $rowNumber, $rowData['employee_id'])
                    );
                }

                $attendanceDate = normalizeUploadedDate($rowData['attendance_date'], $rowNumber);
                $timeIn = normalizeUploadedTime($rowData['time_in'], $rowNumber, 'time_in');
                $timeOut = normalizeUploadedTime($rowData['time_out'], $rowNumber, 'time_out');
                $status = $rowData['status'] !== '' ? $rowData['status'] : 'Present';
                $remarks = $rowData['remarks'] !== '' ? $rowData['remarks'] : null;

                if (strlen($status) > 50) {
                    throw new RuntimeException(sprintf('Row %d: status must not exceed 50 characters.', $rowNumber));
                }

                if ($remarks !== null && strlen($remarks) > 255) {
                    throw new RuntimeException(sprintf('Row %d: remarks must not exceed 255 characters.', $rowNumber));
                }

                $existingRecordId = getExistingAttendanceRecordId(
                    $attendanceLookupStatement,
                    $rowData['employee_id'],
                    $attendanceDate
                );

                if ($existingRecordId !== null) {
                    $updateStatement->execute([
                        'attendance_id' => $existingRecordId,
                        'remarks' => $remarks,
                        'status' => $status,
                        'time_in' => $timeIn,
                        'time_out' => $timeOut,
                    ]);
                    $updatedCount++;
                } else {
                    $insertStatement->execute([
                        'attendance_date' => $attendanceDate,
                        'employee_id' => $rowData['employee_id'],
                        'remarks' => $remarks,
                        'status' => $status,
                        'time_in' => $timeIn,
                        'time_out' => $timeOut,
                    ]);
                    $importedCount++;
                }
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($errors !== []) {
            $conn->rollBack();
            respond(422, [
                'message' => 'Attendance import failed. Please fix the file and try again.',
                'errors' => array_slice($errors, 0, 10),
            ]);
        }

        if ($importedCount === 0 && $updatedCount === 0) {
            $conn->rollBack();
            respond(422, [
                'message' => 'No attendance records were found in the uploaded file.',
            ]);
        }

        $conn->commit();

        return [
            'importedCount' => $importedCount,
            'skippedCount' => $skippedCount,
            'updatedCount' => $updatedCount,
        ];
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $exception;
    }
}

try {
    ensureDtrTableExists($conn);
    migrateLegacyAttendanceRecords($conn);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'attendance' => fetchAttendance($conn),
            ]);
            break;

        case 'POST':
            $file = getUploadedFile();
            $importSummary = importAttendanceFile($conn, $file);

            respond(200, [
                'attendance' => fetchAttendance($conn),
                'imported' => $importSummary['importedCount'],
                'message' => sprintf(
                    'Attendance imported successfully. Imported %d new record%s, updated %d existing record%s, and skipped %d blank row%s.',
                    $importSummary['importedCount'],
                    $importSummary['importedCount'] === 1 ? '' : 's',
                    $importSummary['updatedCount'],
                    $importSummary['updatedCount'] === 1 ? '' : 's',
                    $importSummary['skippedCount'],
                    $importSummary['skippedCount'] === 1 ? '' : 's'
                ),
                'skipped' => $importSummary['skippedCount'],
                'updated' => $importSummary['updatedCount'],
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (Throwable $exception) {
    respond(500, [
        'message' => 'Unable to process attendance right now.',
    ]);
}
