<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, X-HRIS-Actor-Id, X-HRIS-Actor-Name, X-HRIS-Actor-Role');
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
require __DIR__ . '/audit-log-helper.php';

const SYSTEM_TABLE_PREFIXES = ['audit_logs', 'system_backups'];

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

function ensureSystemBackupsTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `system_backups` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action_type` VARCHAR(20) NOT NULL,
            `backup_name` VARCHAR(255) NOT NULL,
            `table_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `record_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `performed_by` VARCHAR(150) DEFAULT NULL,
            `performed_role` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_system_backups_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetchAllApplicationTables(PDO $conn): array
{
    $statement = $conn->query('SHOW TABLES');
    $tables = [];

    foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
        $tableName = (string) ($row[0] ?? '');

        if ($tableName === '') {
            continue;
        }

        $tables[] = $tableName;
    }

    return $tables;
}

function isInternalBackupTable(string $tableName): bool
{
    return in_array($tableName, SYSTEM_TABLE_PREFIXES, true);
}

function exportDatabaseSnapshot(PDO $conn): array
{
    $tables = fetchAllApplicationTables($conn);
    $snapshot = [
        'created_at' => gmdate('c'),
        'database' => 'hris',
        'tables' => [],
        'version' => 1,
    ];
    $recordCount = 0;

    foreach ($tables as $tableName) {
        if (isInternalBackupTable($tableName)) {
            continue;
        }

        $rows = $conn->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`')->fetchAll();
        $snapshot['tables'][$tableName] = $rows;
        $recordCount += count($rows);
    }

    $snapshot['table_count'] = count($snapshot['tables']);
    $snapshot['record_count'] = $recordCount;

    return $snapshot;
}

function insertBackupHistoryRecord(PDO $conn, string $actionType, string $backupName, int $tableCount, int $recordCount): void
{
    ensureSystemBackupsTableExists($conn);
    $actor = getAuditActorContext();

    $statement = $conn->prepare(
        'INSERT INTO `system_backups` (`action_type`, `backup_name`, `table_count`, `record_count`, `performed_by`, `performed_role`)
         VALUES (:action_type, :backup_name, :table_count, :record_count, :performed_by, :performed_role)'
    );
    $statement->execute([
        'action_type' => $actionType,
        'backup_name' => $backupName,
        'performed_by' => $actor['actor_name'] ?? null,
        'performed_role' => $actor['actor_role'] ?? null,
        'record_count' => $recordCount,
        'table_count' => $tableCount,
    ]);
}

function fetchBackupHistory(PDO $conn): array
{
    ensureSystemBackupsTableExists($conn);
    $statement = $conn->query(
        'SELECT `id`, `action_type`, `backup_name`, `table_count`, `record_count`, `performed_by`, `performed_role`, `created_at`
         FROM `system_backups`
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 10'
    );

    return $statement->fetchAll();
}

function fetchCoreTableSummary(PDO $conn): array
{
    $tableNames = [
        'users',
        'employees',
        'divisions',
        'designations',
        'leave_types',
        'leave_request',
        'tbltravel_orders',
        'tblcompensatory',
        'tblpass_slips',
        'dtr',
    ];
    $summary = [];

    foreach ($tableNames as $tableName) {
        $count = 0;

        try {
            $count = (int) $conn->query(
                'SELECT COUNT(*) FROM `' . str_replace('`', '``', $tableName) . '`'
            )->fetchColumn();
        } catch (Throwable $throwable) {
            $count = 0;
        }

        $summary[] = [
            'count' => $count,
            'table' => $tableName,
        ];
    }

    return $summary;
}

function restoreDatabaseSnapshot(PDO $conn, array $snapshot): array
{
    $tables = $snapshot['tables'] ?? null;

    if (!is_array($tables) || $tables === []) {
        respond(422, [
            'message' => 'The backup file does not contain any restorable tables.',
        ]);
    }

    $restoredTableCount = 0;
    $restoredRecordCount = 0;
    $existingTables = array_flip(fetchAllApplicationTables($conn));

    $conn->beginTransaction();

    try {
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $tableName => $rows) {
            if (!isset($existingTables[$tableName]) || isInternalBackupTable($tableName)) {
                continue;
            }

            $conn->exec('DELETE FROM `' . str_replace('`', '``', $tableName) . '`');

            if (!is_array($rows) || $rows === []) {
                $restoredTableCount++;
                continue;
            }

            $columns = array_keys((array) $rows[0]);
            $columnList = implode(
                ', ',
                array_map(
                    static fn(string $columnName): string => '`' . str_replace('`', '``', $columnName) . '`',
                    $columns
                )
            );
            $valueList = implode(
                ', ',
                array_map(
                    static fn(string $columnName): string => ':' . $columnName,
                    $columns
                )
            );
            $statement = $conn->prepare(
                'INSERT INTO `' . str_replace('`', '``', $tableName) . '` (' . $columnList . ') VALUES (' . $valueList . ')'
            );

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $statement->execute($row);
                $restoredRecordCount++;
            }

            $restoredTableCount++;
        }

        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
        $conn->commit();
    } catch (Throwable $throwable) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        try {
            $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $innerThrowable) {
        }

        throw $throwable;
    }

    return [
        'record_count' => $restoredRecordCount,
        'table_count' => $restoredTableCount,
    ];
}

try {
    ensureAuditLogsTableExists($conn);
    ensureSystemBackupsTableExists($conn);
    $action = trim((string) ($_GET['action'] ?? 'summary'));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'download') {
            $snapshot = exportDatabaseSnapshot($conn);
            $backupName = 'hris-backup-' . gmdate('Ymd-His') . '.json';

            insertBackupHistoryRecord(
                $conn,
                'backup',
                $backupName,
                (int) $snapshot['table_count'],
                (int) $snapshot['record_count']
            );
            writeAuditLog(
                $conn,
                'backup',
                'backup.created',
                'A manual data backup was created.',
                'backup',
                $backupName,
                [
                    'record_count' => $snapshot['record_count'],
                    'table_count' => $snapshot['table_count'],
                ]
            );

            respond(200, [
                'backup' => $snapshot,
                'fileName' => $backupName,
            ]);
        }

        respond(200, [
            'history' => fetchBackupHistory($conn),
            'summary' => fetchCoreTableSummary($conn),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, [
            'message' => 'Method not allowed.',
        ]);
    }

    if ($action !== 'restore') {
        respond(422, [
            'message' => 'A valid backup action is required.',
        ]);
    }

    $payload = parseJsonPayload();
    $backup = $payload['backup'] ?? null;

    if (!is_array($backup)) {
        respond(422, [
            'message' => 'A valid backup payload is required.',
        ]);
    }

    $restoreResult = restoreDatabaseSnapshot($conn, $backup);
    $backupName = trim((string) ($payload['fileName'] ?? 'Imported backup'));

    insertBackupHistoryRecord(
        $conn,
        'restore',
        $backupName,
        (int) $restoreResult['table_count'],
        (int) $restoreResult['record_count']
    );
    writeAuditLog(
        $conn,
        'backup',
        'backup.restored',
        'A backup restore was completed.',
        'backup',
        $backupName,
        $restoreResult
    );

    respond(200, [
        'message' => 'Backup restored successfully.',
        'result' => $restoreResult,
    ]);
} catch (Throwable $throwable) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    respond(500, [
        'message' => 'Unable to process backup data right now.',
    ]);
}
