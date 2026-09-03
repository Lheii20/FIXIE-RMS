<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function drms_print_audit_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    drms_print_audit_response(405, [
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
    drms_print_audit_response(401, [
        'status' => 'error',
        'message' => 'Authentication is required.',
    ]);
}
$userId = (int) $userId;

$allowedRoles = ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'];
if (!in_array((string) ($_SESSION['role'] ?? ''), $allowedRoles, true)) {
    drms_print_audit_response(403, [
        'status' => 'error',
        'message' => 'You are not allowed to access this purchase order.',
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
    drms_print_audit_response(403, [
        'status' => 'error',
        'message' => 'The request security token is invalid.',
    ]);
}

if (
    (string) ($_POST['action'] ?? '') !== 'log_print' ||
    (string) ($_POST['record_type'] ?? '') !== 'purchase_order'
) {
    drms_print_audit_response(422, [
        'status' => 'error',
        'message' => 'The print-audit request is invalid.',
    ]);
}

$poId = filter_var(
    $_POST['record_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($poId === false || $poId === null) {
    drms_print_audit_response(422, [
        'status' => 'error',
        'message' => 'Select a valid purchase order.',
    ]);
}
$poId = (int) $poId;

$poStatement = $conn->prepare(
    'SELECT po_number FROM purchase_orders WHERE po_id = ? LIMIT 1'
);
$poStatement->bind_param('i', $poId);
$poStatement->execute();
$purchaseOrder = $poStatement->get_result()->fetch_assoc();
$poStatement->close();

if (!$purchaseOrder) {
    drms_print_audit_response(404, [
        'status' => 'error',
        'message' => 'The purchase order was not found.',
    ]);
}

$poNumber = (string) $purchaseOrder['po_number'];
$printKey = 'purchase_order:' . $poId;
$now = time();

// Avoid duplicate entries caused by a rapid double-click without trusting any
// client-provided description or document name.
if (
    (string) ($_SESSION['last_print_audit_key'] ?? '') === $printKey &&
    ($now - (int) ($_SESSION['last_print_audit_time'] ?? 0)) <= 2
) {
    drms_print_audit_response(200, [
        'status' => 'success',
        'duplicate' => true,
    ]);
}

$auditId = log_audit_action(
    $conn,
    $userId,
    'PRINT_DOC',
    'Printed purchase order: ' . $poNumber,
    null,
    [
        'record_type' => 'purchase_order',
        'record_id' => $poId,
        'document_name' => 'Purchase Order ' . $poNumber,
    ]
);

if ($auditId === false) {
    drms_print_audit_response(500, [
        'status' => 'error',
        'message' => 'The print activity could not be recorded.',
    ]);
}

$_SESSION['last_print_audit_key'] = $printKey;
$_SESSION['last_print_audit_time'] = $now;

drms_print_audit_response(200, [
    'status' => 'success',
]);
