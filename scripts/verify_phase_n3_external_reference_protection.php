<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/config/db_connect.php';

$failures = [];

function phase_n3_pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function phase_n3_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function phase_n3_file_has(string $path, string $marker): bool
{
    $contents = is_file($path) ? file_get_contents($path) : false;

    return is_string($contents) && str_contains($contents, $marker);
}

function phase_n3_column_exists(mysqli $conn, string $table, string $column): bool
{
    $allowedTables = [
        'po_supplier_fund_releases',
        'payments',
    ];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    /* MariaDB does not support a bound placeholder in SHOW COLUMNS. */
    $result = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
    if (!$result) {
        return false;
    }

    $exists = false;
    while ($row = $result->fetch_assoc()) {
        if (($row['Field'] ?? '') === $column) {
            $exists = true;
            break;
        }
    }

    return $exists;
}

function phase_n3_index_exists(mysqli $conn, string $table, string $index): bool
{
    $allowedTables = [
        'po_supplier_fund_releases',
        'payments',
    ];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    /* MariaDB also rejects prepared placeholders in SHOW INDEX clauses. */
    $result = $conn->query('SHOW INDEX FROM `' . $table . '`');
    if (!$result) {
        return false;
    }

    $exists = false;
    while ($row = $result->fetch_assoc()) {
        if (($row['Key_name'] ?? '') === $index) {
            $exists = true;
            break;
        }
    }

    return $exists;
}

echo "Fixie DRMS Phase N3 external-reference protection verification\n";
echo "Project: {$projectRoot}\n\n";

$requiredFiles = [
    'config/external_references.php',
    'actions/po_handler.php',
    'actions/collection_payment_handler.php',
    'release_funding.php',
    'record_collection_payment.php',
];

foreach ($requiredFiles as $relativePath) {
    if (is_file($projectRoot . '/' . $relativePath)) {
        phase_n3_pass($relativePath . ' is present');
    } else {
        phase_n3_fail($failures, 'Missing required file: ' . $relativePath);
    }
}

$markers = [
    'actions/po_handler.php' => 'drms_normalize_external_reference(',
    'actions/collection_payment_handler.php' => 'drms_normalize_external_reference(',
];

foreach ($markers as $relativePath => $marker) {
    if (phase_n3_file_has($projectRoot . '/' . $relativePath, $marker)) {
        phase_n3_pass($relativePath . ' normalizes its external reference');
    } else {
        phase_n3_fail($failures, $relativePath . ' is missing external-reference normalization.');
    }
}

$databaseRequirements = [
    ['po_supplier_fund_releases', 'external_reference_key', 'uq_fund_release_external_reference'],
    ['payments', 'external_reference_key', 'uq_payment_external_reference'],
];

foreach ($databaseRequirements as [$table, $column, $index]) {
    if (phase_n3_column_exists($conn, $table, $column)) {
        phase_n3_pass($table . '.' . $column . ' is installed');
    } else {
        phase_n3_fail($failures, 'Missing ' . $table . '.' . $column . '. Run the Phase N3 SQL migration.');
    }

    if (phase_n3_index_exists($conn, $table, $index)) {
        phase_n3_pass($index . ' is installed');
    } else {
        phase_n3_fail($failures, 'Missing ' . $index . '. Run the Phase N3 SQL migration.');
    }
}

if (empty($failures)) {
    echo "\nPhase N3 verification PASSED.\n";
    exit(0);
}

echo "\nPhase N3 verification FAILED:\n";
foreach ($failures as $failure) {
    echo "- {$failure}\n";
}
exit(1);
