<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/config/db_connect.php';

$failures = [];

function phase_n1_pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function phase_n1_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function phase_n1_index_exists(mysqli $conn, string $table, string $index): bool
{
    $statement = $conn->prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?');
    if (!$statement) {
        return false;
    }

    $statement->bind_param('s', $index);
    $statement->execute();
    $result = $statement->get_result();
    $exists = $result && $result->num_rows > 0;
    $statement->close();

    return $exists;
}

echo "Fixie DRMS Phase N1 business-document numbering verification\n";
echo 'Project: ' . $projectRoot . "\n\n";

$servicePath = $projectRoot . '/config/business_document_numbers.php';
if (is_file($servicePath)) {
    phase_n1_pass('Central business-number allocator is installed');
} else {
    phase_n1_fail($failures, 'Missing config/business_document_numbers.php');
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'business_document_sequences'");
if ($tableCheck && $tableCheck->num_rows === 1) {
    phase_n1_pass('business_document_sequences table is installed');
} else {
    phase_n1_fail($failures, 'Missing business_document_sequences table. Run the Phase N1 SQL migration.');
}

if (phase_n1_index_exists($conn, 'quotations', 'uq_quotations_quotation_number')) {
    phase_n1_pass('Quotation-number uniqueness constraint is installed');
} else {
    phase_n1_fail($failures, 'Missing uq_quotations_quotation_number. Run the Phase N1 SQL migration after resolving duplicates.');
}

$duplicates = $conn->query(
    "SELECT quotation_number
     FROM quotations
     WHERE TRIM(quotation_number) <> ''
     GROUP BY quotation_number
     HAVING COUNT(*) > 1
     LIMIT 1"
);
if ($duplicates && $duplicates->num_rows === 0) {
    phase_n1_pass('No existing duplicate quotation numbers were found');
} else {
    phase_n1_fail($failures, 'Existing duplicate quotation numbers must be corrected before uniqueness can be guaranteed.');
}

if (empty($failures)) {
    echo "\nPhase N1 verification PASSED. Phase N2 can move QTN and PRF allocation entirely to the server.\n";
    exit(0);
}

echo "\nPhase N1 verification FAILED:\n";
foreach ($failures as $failure) {
    echo "- {$failure}\n";
}
exit(1);
