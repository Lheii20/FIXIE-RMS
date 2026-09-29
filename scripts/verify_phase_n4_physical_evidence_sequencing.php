<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/config/db_connect.php';

$failures = [];

function phase_n4_pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function phase_n4_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function phase_n4_contains(string $path, string $marker): bool
{
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (!is_string($contents)) {
        return false;
    }

    /* VS Code may save the live PHP files with Windows CRLF line endings. */
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    return str_contains($contents, $marker);
}

echo "Fixie DRMS Phase N4 physical-evidence numbering verification\n";
echo "Project: {$projectRoot}\n\n";

$files = [
    'config/business_document_numbers.php',
    'config/physical_records.php',
];
foreach ($files as $relativePath) {
    if (is_file($projectRoot . '/' . $relativePath)) {
        phase_n4_pass($relativePath . ' is present');
    } else {
        phase_n4_fail($failures, 'Missing ' . $relativePath);
    }
}

if (phase_n4_contains(
    $projectRoot . '/config/business_document_numbers.php',
    "'physical_disposition'"
)) {
    phase_n4_pass('Central allocator supports PCD evidence numbers');
} else {
    phase_n4_fail($failures, 'Central allocator is missing physical_disposition support.');
}

if (phase_n4_contains(
    $projectRoot . '/config/physical_records.php',
    "drms_allocate_business_document_number(\n                \$conn,\n                'physical_disposition'"
)) {
    phase_n4_pass('Physical disposal uses the central allocator');
} else {
    phase_n4_fail($failures, 'Physical disposal still uses legacy MAX()+1 numbering.');
}

$sequenceStatement = $conn->prepare(
    "SELECT last_sequence
     FROM business_document_sequences
     WHERE document_type = 'physical_disposition'
       AND document_year = YEAR(CURDATE())
     LIMIT 1"
);
if ($sequenceStatement) {
    $sequenceStatement->execute();
    $sequenceRow = $sequenceStatement->get_result()->fetch_assoc();
    $sequenceStatement->close();
    if ($sequenceRow) {
        phase_n4_pass('Current-year PCD sequence is seeded');
    } else {
        phase_n4_pass('No current-year PCD records exist; allocator will safely start at 000001.');
    }
} else {
    phase_n4_fail($failures, 'Unable to inspect the physical-disposition sequence.');
}

if (empty($failures)) {
    echo "\nPhase N4 verification PASSED.\n";
    exit(0);
}

echo "\nPhase N4 verification FAILED:\n";
foreach ($failures as $failure) {
    echo "- {$failure}\n";
}
exit(1);
