<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = getenv('DRMS_PROJECT_ROOT') ?: 'C:\xampp\htdocs\fixie_drms';
$queryHelper = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'audit_query.php';
$databaseBootstrap = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';

if (!is_file($databaseBootstrap)) {
    fwrite(STDERR, "Missing database bootstrap: {$databaseBootstrap}\n");
    exit(1);
}

require $databaseBootstrap;
require $queryHelper;

// Stable read-only snapshot: concurrent users must not change the row counts
// halfway through a count-versus-page comparison. No fixtures are inserted.
$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);

$passed = 0;
$failed = 0;

function audit_test_pass(string $label, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS: {$label}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: {$label} — {$error->getMessage()}\n";
    }
}

audit_test_pass('base count includes every relevant event without a 3,000-row cap', function () use ($conn): void {
    $helperCount = drms_audit_scalar(
        $conn,
        'SELECT COUNT(*) FROM audit_logs a WHERE ' . drms_audit_base_where_sql('a')
    );
    $directResult = $conn->query(
        "SELECT COUNT(*)
         FROM audit_logs
         WHERE action_type NOT IN ('PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT')"
    );
    $directCount = (int) $directResult->fetch_row()[0];
    if ($helperCount !== $directCount) {
        throw new RuntimeException("Expected {$directCount}, received {$helperCount}.");
    }
});

audit_test_pass('summary classification counts execute across the complete audit set', function () use ($conn): void {
    $categoryCase = drms_audit_category_case_sql('a');
    $result = $conn->query(
        "SELECT
            COUNT(*) AS total_events,
            COALESCE(SUM(({$categoryCase}) = 'Deletion'), 0) AS deletion_events,
            COALESCE(SUM(({$categoryCase}) = 'Security'), 0) AS security_events
         FROM audit_logs a
         WHERE " . drms_audit_base_where_sql('a')
    );
    $summary = $result->fetch_assoc();
    if ($summary === null) {
        throw new RuntimeException('The summary query returned no result row.');
    }
    if (
        (int) ($summary['deletion_events'] ?? 0) > (int) $summary['total_events'] ||
        (int) ($summary['security_events'] ?? 0) > (int) $summary['total_events']
    ) {
        throw new RuntimeException('A summary category exceeded the total event count.');
    }
});

audit_test_pass('prepared search agrees with the reference query even on empty data', function () use ($conn): void {
    $where = drms_audit_build_where(['search' => 'LOGIN']);
    $count = drms_audit_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM audit_logs a
         LEFT JOIN users u ON u.user_id = a.user_id
         WHERE {$where['sql']}",
        $where['types'],
        $where['params']
    );
    $reference = drms_audit_scalar(
        $conn,
        "SELECT COUNT(*) FROM audit_logs a
         LEFT JOIN users u ON u.user_id = a.user_id
         WHERE a.action_type NOT IN ('PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT')
         AND (u.full_name LIKE '%LOGIN%' OR u.role LIKE '%LOGIN%'
              OR a.action_type LIKE '%LOGIN%' OR a.description LIKE '%LOGIN%'
              OR a.ip_address LIKE '%LOGIN%')"
    );
    if ($count !== $reference) {
        throw new RuntimeException('Prepared search count differs from its reference.');
    }
});

audit_test_pass('every module filter executes through the database', function () use ($conn): void {
    foreach (drms_audit_module_values() as $module) {
        $where = drms_audit_build_where(['module' => $module]);
        drms_audit_scalar(
            $conn,
            "SELECT COUNT(*)
             FROM audit_logs a
             LEFT JOIN users u ON u.user_id = a.user_id
             WHERE {$where['sql']}",
            $where['types'],
            $where['params']
        );
    }
});

audit_test_pass('generic view events are classified by their server description', function () use ($conn): void {
    $moduleCase = drms_audit_module_case_sql('a');
    $result = $conn->query(
        "SELECT {$moduleCase} AS audit_module
         FROM (
             SELECT 'VIEW_RECORD' AS action_type, NULL AS new_payload,
                    'Viewed record details in Purchase Order Details' AS description
             UNION ALL SELECT 'VIEW_RECORD', NULL,
                    'Viewed record details in Purchase Request Details'
             UNION ALL SELECT 'VIEW_RECORD', NULL,
                    'Viewed record details in View Quotation'
         ) a"
    );
    $modules = [];
    while ($row = $result->fetch_assoc()) {
        $modules[] = (string) $row['audit_module'];
    }
    foreach (['Purchase Orders', 'Purchase Requests', 'Quotations'] as $expectedModule) {
        if (!in_array($expectedModule, $modules, true)) {
            throw new RuntimeException("VIEW_RECORD is missing module: {$expectedModule}");
        }
    }
});

audit_test_pass('every category filter executes through the database', function () use ($conn): void {
    foreach (drms_audit_category_values() as $category) {
        $where = drms_audit_build_where(['category' => $category]);
        drms_audit_scalar(
            $conn,
            "SELECT COUNT(*)
             FROM audit_logs a
             LEFT JOIN users u ON u.user_id = a.user_id
             WHERE {$where['sql']}",
            $where['types'],
            $where['params']
        );
    }
});

audit_test_pass('database pagination returns no more than the requested page length', function () use ($conn): void {
    $where = drms_audit_build_where([]);
    $statement = $conn->prepare(
        "SELECT a.log_id
         FROM audit_logs a
         LEFT JOIN users u ON u.user_id = a.user_id
         WHERE {$where['sql']}
         ORDER BY a.`timestamp` DESC, a.log_id DESC
         LIMIT ? OFFSET ?"
    );
    $types = $where['types'] . 'ii';
    $params = $where['params'];
    $params[] = 15;
    $params[] = 0;
    drms_audit_bind_params($statement, $types, $params);
    $statement->execute();
    $actual = $statement->get_result()->num_rows;
    $expected = min(15, drms_audit_scalar($conn,
        'SELECT COUNT(*) FROM audit_logs a WHERE ' . drms_audit_base_where_sql('a')));
    if ($actual !== $expected) {
        throw new RuntimeException('First-page row count does not match the complete count.');
    }
    $statement->close();
});

// Derived tables exist only for the SELECT. No CREATE/INSERT/DELETE or schema
// changes are needed, including when the user's actual audit table is empty.
function audit_fixture_sql(): string
{
    $digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9)';
    return "(SELECT (th.n * 1000 + hu.n * 100 + te.n * 10 + un.n + 1) AS log_id,
                'LOGIN' AS action_type, 'fixture' AS description,
                NULL AS new_payload, 1 AS user_id, '127.0.0.1' AS ip_address,
                CAST('2026-01-01 12:00:00' AS DATETIME) AS `timestamp`
            FROM {$digits} th CROSS JOIN {$digits} hu
            CROSS JOIN {$digits} te CROSS JOIN {$digits} un
            WHERE (th.n * 1000 + hu.n * 100 + te.n * 10 + un.n) < 3005)";
}

function audit_fixture_page(mysqli $conn, int $offset): array
{
    $fixture = audit_fixture_sql();
    $statement = $conn->prepare("SELECT log_id FROM {$fixture} a
        WHERE " . drms_audit_base_where_sql('a') . '
        ORDER BY a.`timestamp` DESC, a.log_id DESC LIMIT ? OFFSET ?');
    drms_audit_bind_params($statement, 'ii', [15, $offset]);
    $statement->execute();
    $ids = [];
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) $ids[] = (int) $row['log_id'];
    $statement->close();
    return $ids;
}

audit_test_pass('empty turnover dataset gives zero count and zero rows', function () use ($conn): void {
    $fixture = audit_fixture_sql();
    $where = drms_audit_base_where_sql('a') . ' AND 1 = 0';
    $count = drms_audit_scalar($conn, "SELECT COUNT(*) FROM {$fixture} a WHERE {$where}");
    $rows = $conn->query("SELECT log_id FROM {$fixture} a WHERE {$where} LIMIT 15")->num_rows;
    if ($count !== 0 || $rows !== 0) throw new RuntimeException('Empty-set behavior failed.');
});

audit_test_pass('rows beyond the former 3,000 cap remain reachable', function () use ($conn): void {
    $fixture = audit_fixture_sql();
    $count = drms_audit_scalar($conn, "SELECT COUNT(*) FROM {$fixture} a WHERE " . drms_audit_base_where_sql('a'));
    if ($count !== 3005 || audit_fixture_page($conn, 3000) !== [5, 4, 3, 2, 1]) {
        throw new RuntimeException('Oldest rows could not be reached after offset 3000.');
    }
});

audit_test_pass('equal timestamps use log ID for stable non-overlapping pages', function () use ($conn): void {
    if (audit_fixture_page($conn, 0) !== range(3005, 2991) ||
        audit_fixture_page($conn, 15) !== range(2990, 2976)) {
        throw new RuntimeException('Pagination tie-break order failed.');
    }
});

audit_test_pass('SQL-looking search text stays a literal parameter', function () use ($conn): void {
    $fixture = audit_fixture_sql();
    $where = drms_audit_build_where(['search' => "x' OR 1=1 --"]);
    $count = drms_audit_scalar($conn,
        "SELECT COUNT(*) FROM {$fixture} a
         LEFT JOIN (SELECT 1 AS user_id, 'Test' AS full_name, 'Admin' AS role) u
           ON a.user_id = u.user_id WHERE {$where['sql']}",
        $where['types'], $where['params']);
    if ($count !== 0) throw new RuntimeException('Search text changed SQL behavior.');
});

audit_test_pass('module category and search filters combine on all fixture rows', function () use ($conn): void {
    $fixture = audit_fixture_sql();
    $where = drms_audit_build_where(['module' => 'Authentication', 'category' => 'Security', 'search' => 'LOGIN']);
    $count = drms_audit_scalar($conn,
        "SELECT COUNT(*) FROM {$fixture} a
         LEFT JOIN (SELECT 1 AS user_id, 'Test' AS full_name, 'Admin' AS role) u
           ON a.user_id = u.user_id WHERE {$where['sql']}",
        $where['types'], $where['params']);
    if ($count !== 3005) throw new RuntimeException('Combined filters lost matching rows.');
});

audit_test_pass('page-length and filter whitelist defaults are bounded', function (): void {
    foreach ([-1, 0, 3000, 'all', null] as $value) {
        if (drms_audit_page_length($value) !== 15) throw new RuntimeException('Unbounded page length accepted.');
    }
    $filters = drms_audit_normalize_filters(['module' => 'invalid', 'category' => 'invalid']);
    if ($filters['module'] !== '' || $filters['category'] !== '') throw new RuntimeException('Invalid filter accepted.');
});

if ((string) getenv('DRMS_REQUIRE_AUDIT_INDEXES') !== '0') {
    audit_test_pass('all Phase 5M5B audit indexes are installed', function () use ($conn): void {
        $required = [
            'idx_audit_timestamp_log_id' => ['timestamp', 'log_id'],
            'idx_audit_action_timestamp_log' => ['action_type', 'timestamp', 'log_id'],
            'idx_audit_user_timestamp_log' => ['user_id', 'timestamp', 'log_id'],
        ];
        $found = [];
        $result = $conn->query('SHOW INDEX FROM audit_logs');
        while ($row = $result->fetch_assoc()) {
            $found[(string) $row['Key_name']][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }
        foreach ($required as $indexName => $columns) {
            if (!isset($found[$indexName])) {
                throw new RuntimeException("Missing index: {$indexName}");
            }
            ksort($found[$indexName]);
            if (array_values($found[$indexName]) !== $columns) {
                throw new RuntimeException("Unexpected index columns/order: {$indexName}");
            }
        }
    });
}

$conn->rollback();
echo "\nResult: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
