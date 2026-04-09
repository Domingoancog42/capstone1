<?php

function ensureAuditLogsTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_id` INT UNSIGNED DEFAULT NULL,
            `actor_name` VARCHAR(150) DEFAULT NULL,
            `actor_role` VARCHAR(100) DEFAULT NULL,
            `category` VARCHAR(50) NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` VARCHAR(100) DEFAULT NULL,
            `summary` VARCHAR(255) NOT NULL,
            `details_json` LONGTEXT DEFAULT NULL,
            `ip_address` VARCHAR(64) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_logs_category` (`category`),
            KEY `idx_audit_logs_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function getAuditActorContext(): array
{
    $actorId = filter_input(INPUT_SERVER, 'HTTP_X_HRIS_ACTOR_ID', FILTER_VALIDATE_INT);
    $actorName = trim((string) ($_SERVER['HTTP_X_HRIS_ACTOR_NAME'] ?? ''));
    $actorRole = trim((string) ($_SERVER['HTTP_X_HRIS_ACTOR_ROLE'] ?? ''));

    return [
        'actor_id' => $actorId === false ? null : $actorId,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'actor_role' => $actorRole !== '' ? $actorRole : null,
    ];
}

function getClientIpAddress(): ?string
{
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $firstIp = trim((string) ($parts[0] ?? ''));

        return $firstIp !== '' ? $firstIp : null;
    }

    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $remoteAddress !== '' ? $remoteAddress : null;
}

function writeAuditLog(
    PDO $conn,
    string $category,
    string $action,
    string $summary,
    ?string $entityType = null,
    $entityId = null,
    array $details = [],
    array $actorOverride = []
): void {
    ensureAuditLogsTableExists($conn);

    $actorContext = array_merge(getAuditActorContext(), $actorOverride);
    $statement = $conn->prepare(
        'INSERT INTO `audit_logs`
            (`actor_id`, `actor_name`, `actor_role`, `category`, `action`, `entity_type`, `entity_id`, `summary`, `details_json`, `ip_address`)
         VALUES
            (:actor_id, :actor_name, :actor_role, :category, :action, :entity_type, :entity_id, :summary, :details_json, :ip_address)'
    );
    $statement->execute([
        'action' => $action,
        'actor_id' => $actorContext['actor_id'] ?? null,
        'actor_name' => $actorContext['actor_name'] ?? null,
        'actor_role' => $actorContext['actor_role'] ?? null,
        'category' => $category,
        'details_json' => $details !== [] ? json_encode($details) : null,
        'entity_id' => $entityId !== null ? (string) $entityId : null,
        'entity_type' => $entityType,
        'ip_address' => getClientIpAddress(),
        'summary' => $summary,
    ]);
}
