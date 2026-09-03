<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit_query.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function drms_audit_export_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    drms_audit_export_response(405, [
        'status' => 'error',
        'message' => 'Method not allowed.',
    ]);
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($userId === false || $userId === null) {
    drms_audit_export_response(401, [
        'status' => 'error',
        'message' => 'Authentication is required.',
    ]);
}
$userId = (int) $userId;

if (
    (string) ($_SESSION['role'] ?? '') !== 'Admin' &&
    !has_permission($conn, $userId, 'can_view_audit_logs')
) {
    drms_audit_export_response(403, [
        'status' => 'error',
        'message' => 'You are not allowed to export the audit trail.',
    ]);
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = is_string($_POST['csrf_token'] ?? null)
    ? (string) $_POST['csrf_token']
    : '';
if (
    $sessionToken === '' ||
    $requestToken === '' ||
    !hash_equals($sessionToken, $requestToken)
) {
    drms_audit_export_response(403, [
        'status' => 'error',
        'message' => 'The request security token is invalid.',
    ]);
}

$scope = (string) ($_POST['scope'] ?? 'filtered');
if (!in_array($scope, ['current', 'filtered', 'all'], true)) {
    drms_audit_export_response(422, [
        'status' => 'error',
        'message' => 'Select a valid export scope.',
    ]);
}

$filterInput = $scope === 'all'
    ? []
    : [
        'search' => $_POST['search'] ?? '',
        'module' => $_POST['module'] ?? '',
        'category' => $_POST['category'] ?? '',
    ];
$where = drms_audit_build_where($filterInput);

$moduleCase = drms_audit_module_case_sql('a');
$categoryCase = drms_audit_category_case_sql('a');
$sql = "SELECT
            a.log_id,
            a.action_type,
            a.description,
            a.ip_address,
            a.`timestamp`,
            COALESCE(u.full_name, 'System Administrator') AS full_name,
            COALESCE(u.role, 'System') AS role,
            {$moduleCase} AS audit_module,
            {$categoryCase} AS audit_category
        FROM audit_logs a
        LEFT JOIN users u ON u.user_id = a.user_id
        WHERE {$where['sql']}
        ORDER BY a.`timestamp` DESC, a.log_id DESC";

$types = $where['types'];
$params = $where['params'];
if ($scope === 'current') {
    $perPage = drms_audit_page_length($_POST['per_page'] ?? 15);
    $page = filter_var(
        $_POST['page'] ?? 1,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $page = $page === false ? 1 : (int) $page;
    $offset = ($page - 1) * $perPage;
    $sql .= ' LIMIT ? OFFSET ?';
    $types .= 'ii';
    $params[] = $perPage;
    $params[] = $offset;
}

try {
    $statement = $conn->prepare($sql);
    drms_audit_bind_params($statement, $types, $params);
    $statement->execute();
    $result = $statement->get_result();

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'Log ID' => (int) $row['log_id'],
            'Date & Time' => date('M d, Y h:i:s A', strtotime((string) $row['timestamp'])),
            'User Identity' => (string) $row['full_name'],
            'User Role' => (string) $row['role'],
            'Action Type' => (string) $row['action_type'],
            'Module Focus' => (string) $row['audit_module'],
            'Category' => (string) $row['audit_category'],
            'Activity Description' => (string) $row['description'],
            'Client IP Address' => (string) ($row['ip_address'] ?: 'UNKNOWN'),
        ];
    }
    $statement->close();

    $scopeLabels = [
        'current' => 'Current Visible Page',
        'filtered' => 'Filtered Results',
        'all' => 'All Tracked Audit Logs',
    ];
    $recordCount = count($records);
    log_audit_action(
        $conn,
        $userId,
        'EXPORT_AUDIT_LOGS',
        'Prepared ' . number_format($recordCount) .
            ' audit records for export. Scope: ' . $scopeLabels[$scope] . '. Format: Excel (.xlsx).',
        null,
        [
            'scope' => $scope,
            'record_count' => $recordCount,
            'filters' => $where['filters'],
        ]
    );

    drms_audit_export_response(200, [
        'status' => 'success',
        'count' => $recordCount,
        'records' => $records,
    ]);
} catch (Throwable $error) {
    error_log('Audit export failed: ' . $error->getMessage());
    drms_audit_export_response(500, [
        'status' => 'error',
        'message' => 'The audit export could not be prepared.',
    ]);
}
