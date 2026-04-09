<?php

function ensureUserSecurityStateTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `user_security_state` (
            `user_id` INT UNSIGNED NOT NULL,
            `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME DEFAULT NULL,
            `last_failed_at` DATETIME DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_user_security_state_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetchUserSecurityState(PDO $conn, int $userId): ?array
{
    ensureUserSecurityStateTableExists($conn);

    $statement = $conn->prepare(
        'SELECT `user_id`, `failed_attempts`, `locked_until`, `last_failed_at`
         FROM `user_security_state`
         WHERE `user_id` = :user_id
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
    ]);

    $state = $statement->fetch();

    return $state ?: null;
}

function clearUserSecurityState(PDO $conn, int $userId): void
{
    ensureUserSecurityStateTableExists($conn);

    $statement = $conn->prepare(
        'INSERT INTO `user_security_state` (`user_id`, `failed_attempts`, `locked_until`, `last_failed_at`)
         VALUES (:user_id, 0, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            `failed_attempts` = 0,
            `locked_until` = NULL,
            `last_failed_at` = NULL'
    );
    $statement->execute([
        'user_id' => $userId,
    ]);
}

function registerFailedLoginAttempt(
    PDO $conn,
    int $userId,
    int $maxLoginAttempts,
    int $lockoutDurationMinutes
): array {
    ensureUserSecurityStateTableExists($conn);

    $currentState = fetchUserSecurityState($conn, $userId);
    $failedAttempts = (int) ($currentState['failed_attempts'] ?? 0) + 1;
    $lockedUntil = null;

    if ($failedAttempts >= $maxLoginAttempts) {
        $lockedUntil = gmdate('Y-m-d H:i:s', time() + ($lockoutDurationMinutes * 60));
        $failedAttempts = 0;
    }

    $statement = $conn->prepare(
        'INSERT INTO `user_security_state` (`user_id`, `failed_attempts`, `locked_until`, `last_failed_at`)
         VALUES (:user_id, :failed_attempts, :locked_until, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            `failed_attempts` = VALUES(`failed_attempts`),
            `locked_until` = VALUES(`locked_until`),
            `last_failed_at` = VALUES(`last_failed_at`)'
    );
    $statement->execute([
        'failed_attempts' => $failedAttempts,
        'locked_until' => $lockedUntil,
        'user_id' => $userId,
    ]);

    return [
        'failed_attempts' => $failedAttempts,
        'is_locked' => $lockedUntil !== null,
        'locked_until' => $lockedUntil,
    ];
}

function isUserTemporarilyLocked(PDO $conn, int $userId): bool
{
    $state = fetchUserSecurityState($conn, $userId);

    if (!$state || empty($state['locked_until'])) {
        return false;
    }

    return strtotime((string) $state['locked_until']) > time();
}
