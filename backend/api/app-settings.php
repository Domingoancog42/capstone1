<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, X-HRIS-Actor-Id, X-HRIS-Actor-Name, X-HRIS-Actor-Role');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');

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
require __DIR__ . '/app-settings-store.php';
require __DIR__ . '/audit-log-helper.php';

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

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respond(200, [
                'settings' => fetchAppSettings($conn),
            ]);
            break;

        case 'PUT':
            $payload = parseJsonPayload();
            $settings = $payload['settings'] ?? $payload;

            if (!is_array($settings) || $settings === []) {
                respond(422, [
                    'message' => 'At least one valid setting value is required.',
                ]);
            }

            $savedSettings = saveAppSettings($conn, $settings);

            writeAuditLog(
                $conn,
                'security',
                'settings.updated',
                'Security settings were updated.',
                'app_settings',
                'security',
                [
                    'settings' => $savedSettings,
                ]
            );

            respond(200, [
                'message' => 'Settings updated successfully.',
                'settings' => $savedSettings,
            ]);
            break;

        default:
            respond(405, [
                'message' => 'Method not allowed.',
            ]);
    }
} catch (Throwable $throwable) {
    respond(500, [
        'message' => 'Unable to process settings right now.',
    ]);
}
