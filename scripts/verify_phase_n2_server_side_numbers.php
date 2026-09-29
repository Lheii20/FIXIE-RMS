<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/config/db_connect.php';

$failures = [];

function phase_n2_pass(string $message): void
{
    echo "PASS  {$message}\n";
}

function phase_n2_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function phase_n2_file_contains(
    string $path,
    string $needle
): bool {
    $contents = is_file($path) ? file_get_contents($path) : false;

    return is_string($contents) && str_contains($contents, $needle);
}

echo "Fixie DRMS Phase N2 server-side numbering verification\n";
echo "Project: {$projectRoot}\n\n";

$requiredFiles = [
    'config/business_document_numbers.php',
    'create_quotation.php',
    'create_pr.php',
    'actions/quotation_handler.php',
    'actions/pr_handler.php',
    'actions/po_handler.php',
    'config/functions.php',
];

foreach ($requiredFiles as $relativePath) {
    if (is_file($projectRoot . '/' . $relativePath)) {
        phase_n2_pass($relativePath . ' is present');
    } else {
        phase_n2_fail($failures, 'Missing required file: ' . $relativePath);
    }
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'business_document_sequences'");
if ($tableCheck && $tableCheck->num_rows === 1) {
    phase_n2_pass('Central business-number sequence table is present');
} else {
    phase_n2_fail($failures, 'Missing business_document_sequences table. Complete Phase N1 first.');
}

$checks = [
    'config/functions.php' => "drms_allocate_business_document_number(\$conn, 'quotation')",
    'actions/pr_handler.php' => "drms_allocate_business_document_number(\$conn, 'prf')",
    'actions/po_handler.php' => "drms_allocate_business_document_number(\$conn, 'po')",
];

foreach ($checks as $relativePath => $marker) {
    if (phase_n2_file_contains($projectRoot . '/' . $relativePath, $marker)) {
        phase_n2_pass($relativePath . ' uses the central allocator');
    } else {
        phase_n2_fail($failures, $relativePath . ' is missing the central allocator marker.');
    }
}

$forbiddenBrowserInputs = [
    'create_quotation.php' => 'name="quotation_number"',
    'create_pr.php' => 'name="pr_number"',
];

foreach ($forbiddenBrowserInputs as $relativePath => $marker) {
    if (!phase_n2_file_contains($projectRoot . '/' . $relativePath, $marker)) {
        phase_n2_pass($relativePath . ' no longer posts its internal number');
    } else {
        phase_n2_fail($failures, $relativePath . ' still posts an internal number from the browser.');
    }
}

if (empty($failures)) {
    echo "\nPhase N2 verification PASSED.\n";
    exit(0);
}

echo "\nPhase N2 verification FAILED:\n";
foreach ($failures as $failure) {
    echo "- {$failure}\n";
}
exit(1);
