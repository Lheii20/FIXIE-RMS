<?php
declare(strict_types=1);
$projectRoot = dirname(__DIR__); $pagePath = $projectRoot . '/quotations_list.php'; $failures = [];
function phase_l2c_check(bool $condition, string $message, array &$failures): void { echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . PHP_EOL; if (!$condition) $failures[] = $message; }
echo "Fixie DRMS Long-run Phase L2C quotation pagination verification\nProject: $projectRoot\n\n";
phase_l2c_check(is_file($pagePath), 'quotations_list.php is present', $failures);
if (is_file($pagePath)) { $source = (string) file_get_contents($pagePath); foreach (['SELECT COUNT(*) AS total FROM quotations q', 'LIMIT ? OFFSET ?', '$per_page = 15;', 'latest_approval.record_type', 'client_approval_records search_car', 'tx-pagination-bar', 'data-client-approval-trigger', 'For GM Acknowledgement'] as $marker) phase_l2c_check(str_contains($source, $marker), 'Server-side pagination marker: ' . $marker, $failures); phase_l2c_check(!str_contains($source, "$('#dataTable').DataTable"), 'Client-side DataTables pagination is removed', $failures); }
if ($failures !== []) { echo "\nLong-run Phase L2C verification FAILED:\n"; foreach ($failures as $failure) echo '- ' . $failure . PHP_EOL; exit(1); }
echo "\nLong-run Phase L2C verification PASSED.\n";
