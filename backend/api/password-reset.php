<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'message' => 'Method not allowed.',
    ]);
    exit;
}

require __DIR__ . '/connection-pdo.php';
require __DIR__ . '/app-settings-store.php';
require __DIR__ . '/audit-log-helper.php';
require __DIR__ . '/auth-security-helper.php';
require __DIR__ . '/smtp-config.php';
require dirname(__DIR__, 2) . '/hris/PHPMailer-master/src/Exception.php';
require dirname(__DIR__, 2) . '/hris/PHPMailer-master/src/PHPMailer.php';
require dirname(__DIR__, 2) . '/hris/PHPMailer-master/src/SMTP.php';

const RESET_CODE_LENGTH = 6;
const RESET_REQUEST_GENERIC_MESSAGE = 'If an active account exists for that email, a verification code has been sent.';

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

function usersTableHasLegacyNameColumn(PDO $conn): bool
{
    $statement = $conn->query("SHOW COLUMNS FROM `users` LIKE 'name'");
    return (bool) $statement->fetch();
}

function ensurePasswordResetCodesTable(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `password_reset_codes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_password_reset_codes_user_id` (`user_id`),
            KEY `idx_password_reset_codes_expires_at` (`expires_at`),
            CONSTRAINT `fk_password_reset_codes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function cleanupPasswordResetCodes(PDO $conn): void
{
    $statement = $conn->prepare(
        'DELETE FROM `password_reset_codes`
         WHERE `used_at` IS NOT NULL
            OR `expires_at` < UTC_TIMESTAMP()'
    );
    $statement->execute();
}

function getPasswordCategoryCount(string $password): int
{
    $matches = [
        preg_match('/[a-z]/', $password) === 1,
        preg_match('/[A-Z]/', $password) === 1,
        preg_match('/\d/', $password) === 1,
        preg_match('/[^A-Za-z0-9]/', $password) === 1,
    ];

    return count(array_filter($matches));
}

function validateResetPassword(string $password, array $appSettings): void
{
    if ($password === '') {
        respond(422, [
            'message' => 'A new password is required.',
        ]);
    }

    if (
        strlen($password) < (int) $appSettings['password_min_length'] ||
        getPasswordCategoryCount($password) < (int) $appSettings['password_min_category_count']
    ) {
        respond(422, [
            'message' => 'Password does not meet the current security policy requirements.',
        ]);
    }
}

function getRequestedAction(): string
{
    return trim((string) ($_GET['action'] ?? ''));
}

function fetchActiveUserByEmail(PDO $conn, string $email, bool $hasLegacyNameColumn): ?array
{
    $displayNameSelect = $hasLegacyNameColumn
        ? "COALESCE(NULLIF(`users`.`username`, ''), `users`.`name`, `users`.`email`) AS `display_name`"
        : "COALESCE(NULLIF(`users`.`username`, ''), `users`.`email`) AS `display_name`";
    $statement = $conn->prepare(
        "SELECT `users`.`id`, `users`.`email`, {$displayNameSelect}
         FROM `users`
         INNER JOIN `status` AS `status_lookup` ON `status_lookup`.`id` = `users`.`status_id`
         WHERE `users`.`email` = :email
           AND `status_lookup`.`name` = 'Active'
         LIMIT 1"
    );
    $statement->execute([
        'email' => $email,
    ]);

    $user = $statement->fetch();
    return $user ?: null;
}

function generateResetCode(): string
{
    return str_pad((string) random_int(0, 999999), RESET_CODE_LENGTH, '0', STR_PAD_LEFT);
}

function sendPasswordResetCode(
    string $recipientEmail,
    string $recipientName,
    string $resetCode,
    array $appSettings
): void
{
    $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($recipientEmail, $recipientName);
    $mail->isHTML(true);
    $mail->Subject = 'HRIS Password Reset Verification Code';
    $expiryMinutes = (int) $appSettings['password_reset_expiry_minutes'];
    $mail->Body =
        '<p>Hello ' . $safeRecipientName . ',</p>' .
        '<p>We received a password reset request for your HRIS account.</p>' .
        '<p style="font-size: 24px; font-weight: 700; letter-spacing: 0.3em; margin: 24px 0;">' . $resetCode . '</p>' .
        '<p>This code will expire in ' . $expiryMinutes . ' minutes.</p>' .
        '<p>If you did not request this change, you can safely ignore this email.</p>' .
        '<p>REGION X MGB</p>';
    $mail->AltBody =
        "Hello {$recipientName},\n\n" .
        "We received a password reset request for your HRIS account.\n\n" .
        "Your verification code is: {$resetCode}\n" .
        'This code will expire in ' . $expiryMinutes . " minutes.\n\n" .
        "If you did not request this change, you can safely ignore this email.\n\n" .
        "REGION X MGB";

    $mail->send();
}

try {
    $action = getRequestedAction();

    if (!in_array($action, ['request-code', 'reset-password'], true)) {
        respond(422, [
            'message' => 'A valid password reset action is required.',
        ]);
    }

    $payload = parseJsonPayload();
    $email = trim((string) ($payload['email'] ?? ''));
    $appSettings = fetchAppSettings($conn);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, [
            'message' => 'Please enter a valid email address.',
        ]);
    }

    $hasLegacyNameColumn = usersTableHasLegacyNameColumn($conn);
    ensurePasswordResetCodesTable($conn);
    cleanupPasswordResetCodes($conn);

    if ($action === 'request-code') {
        $user = fetchActiveUserByEmail($conn, $email, $hasLegacyNameColumn);

        if ($user === null) {
            respond(200, [
                'message' => RESET_REQUEST_GENERIC_MESSAGE,
                'expires_in_minutes' => (int) $appSettings['password_reset_expiry_minutes'],
            ]);
        }

        $deleteStatement = $conn->prepare('DELETE FROM `password_reset_codes` WHERE `user_id` = :user_id');
        $deleteStatement->execute([
            'user_id' => $user['id'],
        ]);

        $resetCode = generateResetCode();
        $insertStatement = $conn->prepare(
            'INSERT INTO `password_reset_codes` (`user_id`, `code_hash`, `expires_at`)
             VALUES (:user_id, :code_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . (int) $appSettings['password_reset_expiry_minutes'] . ' MINUTE))'
        );
        $insertStatement->execute([
            'code_hash' => password_hash($resetCode, PASSWORD_DEFAULT),
            'user_id' => $user['id'],
        ]);

        $passwordResetId = (int) $conn->lastInsertId();

        try {
            sendPasswordResetCode(
                $user['email'],
                trim((string) ($user['display_name'] ?? '')) ?: $user['email'],
                $resetCode,
                $appSettings
            );
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            $rollbackStatement = $conn->prepare('DELETE FROM `password_reset_codes` WHERE `id` = :id');
            $rollbackStatement->execute([
                'id' => $passwordResetId,
            ]);

            respond(500, [
                'message' => 'Unable to send the verification code right now. Please try again later.',
            ]);
        }

        writeAuditLog(
            $conn,
            'auth',
            'password-reset.requested',
            'A password reset verification code was requested.',
            'user',
            (int) $user['id'],
            [
                'email' => $user['email'],
            ],
            [
                'actor_id' => (int) $user['id'],
                'actor_name' => trim((string) ($user['display_name'] ?? '')) ?: $user['email'],
            ]
        );
        respond(200, [
            'message' => RESET_REQUEST_GENERIC_MESSAGE,
            'expires_in_minutes' => (int) $appSettings['password_reset_expiry_minutes'],
        ]);
    }

    $code = preg_replace('/\D/', '', (string) ($payload['code'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if (strlen($code) !== RESET_CODE_LENGTH) {
        respond(422, [
            'message' => 'Please enter the 6-digit verification code sent to your email.',
        ]);
    }

    validateResetPassword($password, $appSettings);

    $user = fetchActiveUserByEmail($conn, $email, $hasLegacyNameColumn);

    if ($user === null) {
        respond(422, [
            'message' => 'The verification code is invalid or has expired.',
        ]);
    }

    $codeStatement = $conn->prepare(
        'SELECT `id`, `code_hash`
         FROM `password_reset_codes`
         WHERE `user_id` = :user_id
           AND `used_at` IS NULL
           AND `expires_at` >= UTC_TIMESTAMP()
         ORDER BY `created_at` DESC, `id` DESC
         LIMIT 1'
    );
    $codeStatement->execute([
        'user_id' => $user['id'],
    ]);

    $passwordReset = $codeStatement->fetch();

    if (!$passwordReset || !password_verify($code, $passwordReset['code_hash'])) {
        respond(422, [
            'message' => 'The verification code is invalid or has expired.',
        ]);
    }

    $conn->beginTransaction();

    try {
        $updateUserStatement = $conn->prepare(
            'UPDATE `users`
             SET `password_hash` = :password_hash
             WHERE `id` = :id'
        );
        $updateUserStatement->execute([
            'id' => $user['id'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $markUsedStatement = $conn->prepare(
            'UPDATE `password_reset_codes`
             SET `used_at` = UTC_TIMESTAMP()
             WHERE `id` = :id'
        );
        $markUsedStatement->execute([
            'id' => $passwordReset['id'],
        ]);

        $clearOtherCodesStatement = $conn->prepare(
            'DELETE FROM `password_reset_codes`
             WHERE `user_id` = :user_id
               AND `id` <> :id'
        );
        $clearOtherCodesStatement->execute([
            'id' => $passwordReset['id'],
            'user_id' => $user['id'],
        ]);

        clearUserSecurityState($conn, (int) $user['id']);

        $conn->commit();
    } catch (Throwable $throwable) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $throwable;
    }

    writeAuditLog(
        $conn,
        'auth',
        'password-reset.completed',
        'A password reset was completed successfully.',
        'user',
        (int) $user['id'],
        [
            'email' => $user['email'],
        ],
        [
            'actor_id' => (int) $user['id'],
            'actor_name' => trim((string) ($user['display_name'] ?? '')) ?: $user['email'],
        ]
    );
    respond(200, [
        'message' => 'Password reset successful. You can now sign in with your new password.',
    ]);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    respond(500, [
        'message' => 'Unable to process password reset right now.',
    ]);
}
?>
