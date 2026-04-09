<?php

const DEFAULT_APP_SETTINGS = [
    'lockout_duration_minutes' => 15,
    'max_login_attempts' => 5,
    'password_min_category_count' => 3,
    'password_min_length' => 8,
    'password_reset_expiry_minutes' => 15,
    'session_timeout_minutes' => 30,
];

function ensureAppSettingsTableExists(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `app_settings` (
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function seedDefaultAppSettings(PDO $conn): void
{
    $statement = $conn->prepare(
        'INSERT INTO `app_settings` (`setting_key`, `setting_value`)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`'
    );

    foreach (DEFAULT_APP_SETTINGS as $settingKey => $settingValue) {
        $statement->execute([
            'setting_key' => $settingKey,
            'setting_value' => (string) $settingValue,
        ]);
    }
}

function normalizeAppSettingValue(string $settingKey, $settingValue): int
{
    $numericValue = filter_var($settingValue, FILTER_VALIDATE_INT);

    if ($numericValue === false) {
        return DEFAULT_APP_SETTINGS[$settingKey];
    }

    return match ($settingKey) {
        'password_min_length' => max(8, min(128, $numericValue)),
        'password_min_category_count' => max(1, min(4, $numericValue)),
        'session_timeout_minutes' => max(5, min(1440, $numericValue)),
        'max_login_attempts' => max(1, min(20, $numericValue)),
        'lockout_duration_minutes' => max(1, min(1440, $numericValue)),
        'password_reset_expiry_minutes' => max(5, min(120, $numericValue)),
        default => $numericValue,
    };
}

function fetchAppSettings(PDO $conn): array
{
    ensureAppSettingsTableExists($conn);
    seedDefaultAppSettings($conn);

    $statement = $conn->query(
        'SELECT `setting_key`, `setting_value`
         FROM `app_settings`'
    );
    $settings = DEFAULT_APP_SETTINGS;

    foreach ($statement->fetchAll() as $row) {
        $settingKey = (string) ($row['setting_key'] ?? '');

        if (!array_key_exists($settingKey, DEFAULT_APP_SETTINGS)) {
            continue;
        }

        $settings[$settingKey] = normalizeAppSettingValue(
            $settingKey,
            $row['setting_value'] ?? DEFAULT_APP_SETTINGS[$settingKey]
        );
    }

    return $settings;
}

function saveAppSettings(PDO $conn, array $settings): array
{
    ensureAppSettingsTableExists($conn);

    $statement = $conn->prepare(
        'INSERT INTO `app_settings` (`setting_key`, `setting_value`)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)'
    );

    foreach ($settings as $settingKey => $settingValue) {
        if (!array_key_exists($settingKey, DEFAULT_APP_SETTINGS)) {
            continue;
        }

        $normalizedValue = normalizeAppSettingValue($settingKey, $settingValue);
        $statement->execute([
            'setting_key' => $settingKey,
            'setting_value' => (string) $normalizedValue,
        ]);
    }

    return fetchAppSettings($conn);
}
