<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase4bRead(string $projectRoot, string $relativePath, array &$failures): string
{
    $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        $failures[] = 'Missing required file: ' . $relativePath;
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = 'Could not read required file: ' . $relativePath;
        return '';
    }
    return $contents;
}

function phase4bRequireMarkers(
    string $relativePath,
    string $contents,
    array $markers,
    array &$failures,
    array &$passes
): void {
    if ($contents === '') {
        return;
    }
    $missing = [];
    foreach ($markers as $label => $marker) {
        if (strpos($contents, $marker) === false) {
            $missing[] = is_string($label) ? $label : $marker;
        }
    }
    if ($missing !== []) {
        $failures[] = $relativePath . ' is missing: ' . implode(', ', $missing);
        return;
    }
    $passes[] = $relativePath . ' workflow guards and transitions are present.';
}

function phase4bTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) === 1;
}

function phase4bColumns(mysqli $conn, string $table): array
{
    $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
    } finally {
        $result->free();
    }
    return $columns;
}

function phase4bHasTransitionUniqueIndex(mysqli $conn): bool
{
    $result = $conn->query('SHOW INDEX FROM `workflow_rules`');
    $indexes = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $name = (string) ($row['Key_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $sequence < 1) {
                continue;
            }
            $indexes[$name]['unique'] = (int) ($row['Non_unique'] ?? 1) === 0;
            $indexes[$name]['columns'][$sequence] = (string) ($row['Column_name'] ?? '');
        }
    } finally {
        $result->free();
    }
    foreach ($indexes as $index) {
        ksort($index['columns']);
        if (
            ($index['unique'] ?? false) === true &&
            array_values($index['columns']) === ['current_status', 'action_key', 'required_role']
        ) {
            return true;
        }
    }
    return false;
}

$staticChecks = [
    'actions/quotation_handler.php' => [
        'POST-only guard' => "REQUEST_METHOD'] !== 'POST'",
        'CSRF guard' => "csrf_token",
        'official Client PO type' => 'Official Client PO',
        'GM acknowledgement queue' => "status = 'For GM Acknowledgement'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/client_po_acknowledgement_handler.php' => [
        'GM-only access' => "drms_require_workflow_roles(['GM']",
        'CSRF guard' => 'csrf_token',
        'GM electronic signature' => "'signature_stage' => 'GM Acknowledgement'",
        'PO Received transition' => "SET status = 'PO Received'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/pr_handler.php' => [
        'CSRF guard' => 'csrf_token',
        'stage role enforcement' => "required_role",
        'electronic signature event' => "'signature_stage' => \$approval_stage",
        'final PRF state' => "current_approval_stage = 'Official Approved'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/po_handler.php' => [
        'CSRF guard' => 'csrf_token',
        'PO signature stage mapping' => 'phase5_po_signature_stage_for_action',
        'verified funding proof' => 'drms_upload_validate',
        'Procurement funding handoff' => "\$new_funding_location = 'Procurement Dept.'",
        'safe fallback funding location' => "'mark_funded' => 'Procurement Dept.'",
        'direct-delivery block' => 'Direct delivery completion is disabled.',
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/delivery_request_handler.php' => [
        'POST-only guard' => "REQUEST_METHOD'] !== 'POST'",
        'CSRF guard' => 'csrf_token',
        'Procurement request transition' => 'create_delivery_request',
        'Supply Chain approval transition' => 'approve_delivery_schedule',
        'Supply Chain signature event' => "'signature_stage' => 'Supply Chain Approval'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/delivery_completion_handler.php' => [
        'POST-only guard' => "REQUEST_METHOD'] !== 'POST'",
        'CSRF guard' => 'csrf_token',
        'Supply Chain role guard' => "\$_SESSION['role'] !== 'Supply Chain'",
        'delivery receipt gate' => "current_status = 'For Pick-up/Delivery'",
        'delivery signature event' => "'signature_stage' => 'Delivery Completion'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'actions/collection_payment_handler.php' => [
        'POST-only guard' => "REQUEST_METHOD'] !== 'POST'",
        'CSRF guard' => 'csrf_token',
        'Finance role guard' => "\$_SESSION['role'] ?? '') !== 'Finance'",
        'partial-payment support' => "'Partially Paid'",
        'separate collection status update' => 'SET collection_status = ?',
        'Finance verification signature' => "'signature_stage' => 'Finance Verification'",
        'atomic transaction' => 'begin_transaction()',
    ],
    'view_po.php' => [
        'legacy action visibility filter' => "action_key = 'mark_delivered'",
        'verified delivery-state exception' => "current_status <> 'For Pick-up/Delivery'",
        'delivery request action' => 'create_delivery_request',
    ],
];

foreach ($staticChecks as $file => $markers) {
    $contents = phase4bRead($projectRoot, $file, $failures);
    phase4bRequireMarkers($file, $contents, $markers, $failures, $passes);
}

$poHandler = phase4bRead($projectRoot, 'actions/po_handler.php', $failures);
if ($poHandler !== '' && strpos($poHandler, "'mark_funded' => 'Supply Chain Dept.'") !== false) {
    $failures[] = 'actions/po_handler.php still contains the obsolete Supply Chain funding handoff.';
}

try {
    require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $requiredTables = [
        'quotations',
        'client_approval_records',
        'purchase_requests',
        'pr_approval_records',
        'purchase_orders',
        'po_history',
        'po_supplier_fund_releases',
        'po_delivery_requests',
        'po_delivery_plans',
        'po_delivery_receipts',
        'payments',
        'po_collection_status_history',
        'document_signature_events',
        'workflow_rules',
    ];
    foreach ($requiredTables as $table) {
        if (!phase4bTableExists($conn, $table)) {
            $failures[] = 'Missing required workflow table: ' . $table;
        }
    }

    if (phase4bTableExists($conn, 'purchase_orders')) {
        $poColumns = phase4bColumns($conn, 'purchase_orders');
        foreach (['status', 'current_location', 'collection_status', 'collection_status_updated_at'] as $column) {
            if (!isset($poColumns[$column])) {
                $failures[] = 'purchase_orders is missing required column: ' . $column;
            }
        }
    }

    if (phase4bTableExists($conn, 'workflow_rules')) {
        $expectedRoutes = [
            ['Pending', 'approve_gm', 'GM', 'GM-Approved', 'Finance', 'Finance'],
            ['Pending', 'reject', 'GM', 'Rejected', 'Procurement', 'Procurement'],
            ['GM-Approved', 'approve_finance', 'Finance', 'Finance-Approved', 'President', 'President'],
            ['GM-Approved', 'reject', 'Finance', 'Rejected', 'Procurement', 'Procurement'],
            ['Finance-Approved', 'approve_president', 'President', 'President-Approved', 'Finance', 'Finance'],
            ['Finance-Approved', 'reject', 'President', 'Rejected', 'Procurement', 'Procurement'],
            ['President-Approved', 'mark_funded', 'Finance', 'Funded', 'Procurement Dept.', 'Procurement'],
            ['Funded', 'create_delivery_request', 'Procurement', 'Delivery Requested', 'Supply Chain Dept.', 'Supply Chain'],
            ['Delivery Requested', 'approve_delivery_schedule', 'Supply Chain', 'For Pick-up/Delivery', 'Supply Chain Dept.', 'Procurement'],
            ['Delivery Requested', 'return_delivery_request', 'Supply Chain', 'Funded', 'Procurement Dept.', 'Procurement'],
            ['For Pick-up/Delivery', 'mark_delivered', 'Supply Chain', 'Delivered', 'Finance Dept. (Collection)', 'Finance'],
        ];
        $routeStmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM workflow_rules
             WHERE current_status = ?
               AND action_key = ?
               AND required_role = ?
               AND next_status = ?
               AND next_location = ?
               AND notify_target = ?'
        );
        foreach ($expectedRoutes as $route) {
            [$status, $action, $role, $nextStatus, $location, $notify] = $route;
            $routeStmt->bind_param('ssssss', $status, $action, $role, $nextStatus, $location, $notify);
            $routeStmt->execute();
            $count = (int) ($routeStmt->get_result()->fetch_assoc()['total'] ?? 0);
            if ($count !== 1) {
                $failures[] = "Invalid workflow route: {$status} / {$action} / {$role}.";
            }
        }
        $routeStmt->close();

        $obsoleteResult = $conn->query(
            "SELECT COUNT(*) AS total
             FROM workflow_rules
             WHERE current_status = 'Funded'
               AND action_key = 'mark_delivered'
               AND required_role = 'Supply Chain'"
        );
        $obsoleteCount = (int) ($obsoleteResult->fetch_assoc()['total'] ?? 0);
        $obsoleteResult->free();
        if ($obsoleteCount !== 0) {
            $failures[] = 'Obsolete Funded-to-Delivered shortcut remains in workflow_rules.';
        }
        if (!phase4bHasTransitionUniqueIndex($conn)) {
            $failures[] = 'workflow_rules does not enforce unique status/action/role transitions.';
        }
    }

    if ($failures === []) {
        $passes[] = 'Required workflow tables, columns, and authoritative route configuration passed live read-only preflight.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live database preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 4B verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4B verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nNo sample PO, approval, delivery, payment, or Official Record was created or changed by this verifier.\n";
