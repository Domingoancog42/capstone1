<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    ensureAuditLogsTableExists($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        respond(405, [
            'message' => 'Method not allowed.',
        ]);
    }

    $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
    $category = trim((string) ($_GET['category'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));
    $limit = $limit === false ? 100 : max(1, min(250, $limit));

    $query = 'SELECT `id`, `actor_id`, `actor_name`, `actor_role`, `category`, `action`, `entity_type`, `entity_id`, `summary`, `details_json`, `ip_address`, `created_at`
              FROM `audit_logs`
              WHERE 1 = 1';
    $params = [];

    if ($category !== '') {
        $query .= ' AND `category` = :category';
        $params['category'] = $category;
    }

    if ($search !== '') {
        $query .= ' AND (`summary` LIKE :search OR `actor_name` LIKE :search OR `entity_type` LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $query .= ' ORDER BY `created_at` DESC, `id` DESC LIMIT ' . $limit;

    $statement = $conn->prepare($query);
    $statement->execute($params);
    $logs = array_map(
        static function (array $log): array {
            $log['details'] = !empty($log['details_json']) ? json_decode($log['details_json'], true) : null;
            unset($log['details_json']);

            return $log;
        },
        $statement->fetchAll()
    );

    $summaryStatement = $conn->query(
        'SELECT `category`, COUNT(*) AS `total`
         FROM `audit_logs`
         GROUP BY `category`
         ORDER BY `category` ASC'
    );

    respond(200, [
        'logs' => $logs,
        'summary' => $summaryStatement->fetchAll(),
    ]);
} catch (Throwable $throwable) {
    respond(500, [
        'message' => 'Unable to load audit logs right now.',
    ]);
}
