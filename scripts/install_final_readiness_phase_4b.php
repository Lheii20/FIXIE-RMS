<?php
declare(strict_types=1);

$arguments = array_values(array_slice($argv, 1));
$checkOnly = in_array('--check', $arguments, true);
$projectRoot = null;
foreach ($arguments as $argument) {
    if ($argument !== '--check' && $projectRoot === null) {
        $projectRoot = rtrim($argument, "/\\");
    }
}
$projectRoot = $projectRoot ?: dirname(__DIR__);

function phase4bTableExists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    try {
        return $result->num_rows === 1;
    } finally {
        $result->free();
    }
}

function phase4bWorkflowIndexes(mysqli $conn): array
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

    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return $indexes;
}

function phase4bHasTransitionUniqueIndex(array $indexes): bool
{
    $required = ['current_status', 'action_key', 'required_role'];
    foreach ($indexes as $index) {
        if (
            ($index['unique'] ?? false) === true &&
            ($index['columns'] ?? []) === $required
        ) {
            return true;
        }
    }
    return false;
}

function phase4bExpectedRoutes(): array
{
    return [
        ['Pending', 'approve_gm', 'GM', 'GM-Approved'],
        ['Pending', 'reject', 'GM', 'Rejected'],
        ['GM-Approved', 'approve_finance', 'Finance', 'Finance-Approved'],
        ['GM-Approved', 'reject', 'Finance', 'Rejected'],
        ['Finance-Approved', 'approve_president', 'President', 'President-Approved'],
        ['Finance-Approved', 'reject', 'President', 'Rejected'],
        ['President-Approved', 'mark_funded', 'Finance', 'Funded'],
        ['Funded', 'create_delivery_request', 'Procurement', 'Delivery Requested'],
        ['Delivery Requested', 'approve_delivery_schedule', 'Supply Chain', 'For Pick-up/Delivery'],
        ['Delivery Requested', 'return_delivery_request', 'Supply Chain', 'Funded'],
        ['For Pick-up/Delivery', 'mark_delivered', 'Supply Chain', 'Delivered'],
    ];
}

function phase4bAssertExpectedRoutes(mysqli $conn): void
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM workflow_rules
         WHERE current_status = ?
           AND action_key = ?
           AND required_role = ?
           AND next_status = ?'
    );
    foreach (phase4bExpectedRoutes() as $route) {
        [$status, $action, $role, $nextStatus] = $route;
        $stmt->bind_param('ssss', $status, $action, $role, $nextStatus);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ((int) ($row['total'] ?? 0) !== 1) {
            $stmt->close();
            throw new RuntimeException(
                "Missing or ambiguous workflow route: {$status} / {$action} / {$role} -> {$nextStatus}. " .
                'Import the complete current database schema before installing Phase 4B.'
            );
        }
    }
    $stmt->close();
}

try {
    require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }
    if (!phase4bTableExists($conn, 'workflow_rules')) {
        throw new RuntimeException('Required table workflow_rules is missing.');
    }

    $duplicateResult = $conn->query(
        'SELECT current_status, action_key, required_role, COUNT(*) AS total
         FROM workflow_rules
         GROUP BY current_status, action_key, required_role
         HAVING COUNT(*) > 1
         LIMIT 1'
    );
    $duplicate = $duplicateResult->fetch_assoc();
    $duplicateResult->free();
    if ($duplicate) {
        throw new RuntimeException(
            'Duplicate workflow routes already exist for ' .
            $duplicate['current_status'] . ' / ' .
            $duplicate['action_key'] . ' / ' .
            $duplicate['required_role'] . '. Resolve that data conflict before installing Phase 4B.'
        );
    }

    phase4bAssertExpectedRoutes($conn);

    $obsoleteStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM workflow_rules
         WHERE current_status = 'Funded'
           AND action_key = 'mark_delivered'
           AND required_role = 'Supply Chain'"
    );
    $obsoleteStmt->execute();
    $obsolete = (int) ($obsoleteStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $obsoleteStmt->close();

    $hasUniqueIndex = phase4bHasTransitionUniqueIndex(phase4bWorkflowIndexes($conn));
    if ($checkOnly) {
        echo "Phase 4B workflow preflight PASSED.\n";
        echo $obsolete > 0
            ? " - {$obsolete} obsolete direct-delivery shortcut(s) ready for removal.\n"
            : " - No obsolete direct-delivery shortcut is present.\n";
        echo $hasUniqueIndex
            ? " - Workflow route uniqueness is already enforced.\n"
            : " - Workflow route uniqueness index is ready to be installed.\n";
        exit(0);
    }

    if ($obsolete > 0) {
        $conn->begin_transaction();
        try {
            $deleteStmt = $conn->prepare(
                "DELETE FROM workflow_rules
                 WHERE current_status = 'Funded'
                   AND action_key = 'mark_delivered'
                   AND required_role = 'Supply Chain'"
            );
            $deleteStmt->execute();
            $removed = $deleteStmt->affected_rows;
            $deleteStmt->close();
            if (!$conn->commit()) {
                throw new RuntimeException('The workflow cleanup transaction could not be committed.');
            }
            echo "Removed {$removed} obsolete direct-delivery workflow shortcut(s).\n";
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    } else {
        echo "The obsolete direct-delivery shortcut is already absent.\n";
    }

    if (!$hasUniqueIndex) {
        $conn->query(
            'ALTER TABLE `workflow_rules`
             ADD UNIQUE INDEX `uq_workflow_transition`
             (`current_status`, `action_key`, `required_role`)'
        );
        echo "Installed uq_workflow_transition.\n";
    } else {
        echo "Workflow route uniqueness is already enforced.\n";
    }

    phase4bAssertExpectedRoutes($conn);
    if (!phase4bHasTransitionUniqueIndex(phase4bWorkflowIndexes($conn))) {
        throw new RuntimeException('Workflow route uniqueness verification failed after installation.');
    }

    $shortcutResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM workflow_rules
         WHERE current_status = 'Funded'
           AND action_key = 'mark_delivered'
           AND required_role = 'Supply Chain'"
    );
    $shortcutCount = (int) ($shortcutResult->fetch_assoc()['total'] ?? 0);
    $shortcutResult->free();
    if ($shortcutCount !== 0) {
        throw new RuntimeException('The obsolete direct-delivery shortcut remains after installation.');
    }

    echo "Phase 4B transaction workflow safeguards installed successfully.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 4B installation FAILED: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
