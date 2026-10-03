<?php
declare(strict_types=1);

/**
 * Phase 2D: additive indexes for Company Files' server-paginated list.
 *
 * Usage from the project root:
 *   php scripts/install_validation_phase_2d_record_list_indexes.php --plan
 *   php scripts/install_validation_phase_2d_record_list_indexes.php --apply
 *   php scripts/install_validation_phase_2d_record_list_indexes.php --verify
 *   php path/to/this/file.php --plan --project-root=path/to/fixie_drms
 *
 * No database changes occur unless --apply is supplied explicitly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$mode = $argv[1] ?? '--plan';
$rootOverride = $argv[2] ?? null;
if (!in_array($mode, ['--plan', '--apply', '--verify'], true)
    || count($argv) > 3
    || ($rootOverride !== null
        && ($mode === '--apply' || strpos($rootOverride, '--project-root=') !== 0))) {
    fwrite(STDERR, "Usage: php scripts/install_validation_phase_2d_record_list_indexes.php [--plan|--apply|--verify] [--project-root=PATH for read-only modes]\n");
    exit(2);
}

$projectRoot = $rootOverride !== null
    ? substr($rootOverride, strlen('--project-root='))
    : dirname(__DIR__);
$connectionFile = $projectRoot . '/config/db_connect.php';
if (!is_file($connectionFile)) {
    fwrite(STDERR, "Run the copied script from the project's scripts directory. Missing config/db_connect.php beside that project.\n");
    exit(2);
}

require_once $connectionFile;
if (!isset($conn) || !$conn instanceof mysqli) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(2);
}

// These two access paths cover the default date order in the all-files and
// selected-folder Company Files views. Existing indexes are left unchanged.
$indexes = [
    'idx_documents_status_uploaded_id' => ['status', 'uploaded_at', 'doc_id'],
    'idx_documents_status_category_uploaded_id' => ['status', 'category', 'uploaded_at', 'doc_id'],
];

function phase2dIndexColumns(mysqli $conn): array
{
    $result = $conn->query('SHOW INDEX FROM `documents`');
    $found = [];
    while ($row = $result->fetch_assoc()) {
        $name = strtolower((string) $row['Key_name']);
        $sequence = (int) $row['Seq_in_index'];
        $found[$name][$sequence] = strtolower((string) $row['Column_name']);
    }
    $result->free();
    foreach ($found as &$columns) {
        ksort($columns, SORT_NUMERIC);
        $columns = array_values($columns);
    }
    unset($columns);
    return $found;
}

try {
    $databaseResult = $conn->query('SELECT DATABASE() AS db_name');
    $databaseName = (string) ($databaseResult->fetch_assoc()['db_name'] ?? 'unknown');
    $databaseResult->free();
    echo "Fixie DRMS Phase 2D record-list indexes\n";
    echo "Database: {$databaseName}\nMode: {$mode}\n\n";

    $found = phase2dIndexColumns($conn);
    $missing = [];
    foreach ($indexes as $name => $columns) {
        $actual = $found[strtolower($name)] ?? null;
        if ($actual === null) {
            $missing[$name] = $columns;
            echo "MISSING  {$name} (" . implode(', ', $columns) . ")\n";
            continue;
        }
        if ($actual !== $columns) {
            fwrite(STDERR, "CONFLICT {$name}: an index with this name exists but has different columns. No indexes were changed.\n");
            exit(1);
        }
        echo "READY    {$name}\n";
    }

    if ($mode === '--verify') {
        if ($missing !== []) {
            fwrite(STDERR, "\nPhase 2D verification FAILED: install the missing indexes with --apply.\n");
            exit(1);
        }
        echo "\nPhase 2D verification PASSED.\n";
        exit(0);
    }

    if ($mode === '--plan') {
        echo "\nNo changes made. Make a database backup, then run --apply during a low-traffic period.\n";
        exit(0);
    }

    if ($missing === []) {
        echo "\nNo changes needed. Both indexes are already installed.\n";
        exit(0);
    }

    foreach ($missing as $name => $columns) {
        // Names and columns come only from the hard-coded allowlist above.
        $columnSql = implode(', ', array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns
        ));
        $conn->query("CREATE INDEX `{$name}` ON `documents` ({$columnSql})");
        echo "CREATED  {$name}\n";
    }

    $verified = phase2dIndexColumns($conn);
    foreach ($indexes as $name => $columns) {
        if (($verified[strtolower($name)] ?? null) !== $columns) {
            throw new RuntimeException("Index verification failed for {$name}.");
        }
    }
    echo "\nPhase 2D installation and verification PASSED.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "\nPhase 2D FAILED: " . $error->getMessage() . "\n");
    exit(1);
}
