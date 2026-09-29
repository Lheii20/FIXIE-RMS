<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/config/db_connect.php';
require $projectRoot . '/config/business_document_numbers.php';

$failures = [];

function phase_n5_pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function phase_n5_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function phase_n5_unique_index_exists(mysqli $conn, string $table, string $index): bool
{
    $allowedTables = [
        'quotations',
        'purchase_requests',
        'purchase_orders',
        'physical_disposition_logs',
    ];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $result = $conn->query('SHOW INDEX FROM `' . $table . '`');
    if (!$result) {
        return false;
    }

    while ($row = $result->fetch_assoc()) {
        if (($row['Key_name'] ?? '') === $index && (int) ($row['Non_unique'] ?? 1) === 0) {
            return true;
        }
    }

    return false;
}

function phase_n5_legacy_max(
    mysqli $conn,
    string $table,
    string $column,
    string $prefix,
    int $year
): int {
    $allowed = [
        'quotations' => 'quotation_number',
        'purchase_requests' => 'pr_number',
        'purchase_orders' => 'po_number',
        'physical_disposition_logs' => 'evidence_number',
    ];
    if (($allowed[$table] ?? null) !== $column) {
        throw new LogicException('Invalid numbering audit target.');
    }

    $pattern = '^' . $prefix . '-' . $year . '-[0-9]+$';
    $statement = $conn->prepare(
        'SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`' . $column . '`, \'-\', -1) AS UNSIGNED)), 0) AS maximum_sequence
         FROM `' . $table . '`
         WHERE `' . $column . '` REGEXP ?'
    );
    $statement->bind_param('s', $pattern);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();

    return (int) ($row['maximum_sequence'] ?? 0);
}

echo "Fixie DRMS Phase N5 numbering integrity verification\n";
echo "Project: {$projectRoot}\n";
echo "Mode: transactional smoke test; no business record is committed.\n\n";

$requiredIndexes = [
    ['quotations', 'uq_quotations_quotation_number'],
    ['purchase_requests', 'pr_number'],
    ['purchase_orders', 'po_number'],
    ['physical_disposition_logs', 'uq_vc4b2_evidence_number'],
];

foreach ($requiredIndexes as [$table, $index]) {
    if (phase_n5_unique_index_exists($conn, $table, $index)) {
        phase_n5_pass($table . '.' . $index . ' is unique');
    } else {
        phase_n5_fail($failures, 'Missing unique index ' . $table . '.' . $index);
    }
}

$year = (int) date('Y');
$numberTypes = [
    ['quotation', 'quotations', 'quotation_number', 'QTN'],
    ['prf', 'purchase_requests', 'pr_number', 'PR'],
    ['po', 'purchase_orders', 'po_number', 'PO'],
    ['physical_disposition', 'physical_disposition_logs', 'evidence_number', 'PCD'],
];

foreach ($numberTypes as [$type, $table, $column, $prefix]) {
    $maximum = phase_n5_legacy_max($conn, $table, $column, $prefix, $year);
    $statement = $conn->prepare(
        "SELECT last_sequence
         FROM business_document_sequences
         WHERE document_type = ?
           AND document_year = ?
         LIMIT 1"
    );
    $statement->bind_param('si', $type, $year);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    $stored = (int) ($row['last_sequence'] ?? 0);

    if ($stored >= $maximum) {
        phase_n5_pass(strtoupper($type) . " counter is aligned (stored {$stored}; current maximum {$maximum})");
    } else {
        phase_n5_fail($failures, strtoupper($type) . " counter is behind existing records (stored {$stored}; current maximum {$maximum})");
    }
}

/*
 * Exercise the real allocator without committing any counter or business data.
 * The transaction is always rolled back, including when a test fails.
 */
$allocated = [];
try {
    $conn->begin_transaction();

    foreach ($numberTypes as [$type, , , $prefix]) {
        $number = drms_allocate_business_document_number($conn, $type);
        $expectedPattern = $type === 'physical_disposition'
            ? '/^PCD-' . $year . '-[0-9]{6,}$/'
            : '/^' . $prefix . '-' . $year . '-[0-9]{4,}$/';

        if (!preg_match($expectedPattern, $number)) {
            throw new RuntimeException('Unexpected format allocated for ' . $type . ': ' . $number);
        }
        $allocated[] = $number;
    }

    $conn->rollback();
    phase_n5_pass('Transactional allocator smoke test passed and was rolled back: ' . implode(', ', $allocated));
} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // The original failure is more useful to the verifier output.
    }
    phase_n5_fail($failures, 'Transactional allocator smoke test failed: ' . $error->getMessage());
}

if (empty($failures)) {
    echo "\nPhase N5 verification PASSED. No business record was created by this verifier.\n";
    exit(0);
}

echo "\nPhase N5 verification FAILED:\n";
foreach ($failures as $failure) {
    echo "- {$failure}\n";
}
exit(1);
