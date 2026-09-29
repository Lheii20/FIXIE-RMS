<?php
declare(strict_types=1);
$projectRoot = dirname(__DIR__);
$pagePath = $projectRoot . '/pr_list.php';
$failures = [];
function phase_l2b_check(bool $condition, string $message, array &$failures): void { echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . PHP_EOL; if (!$condition) $failures[] = $message; }
echo "Fixie DRMS Long-run Phase L2B PRF pagination verification\nProject: $projectRoot\n\n";
phase_l2b_check(is_file($pagePath), 'pr_list.php is present', $failures);
if (is_file($pagePath)) {
    $source = (string) file_get_contents($pagePath);
    foreach (['SELECT COUNT(*) AS total', 'LIMIT ? OFFSET ?', '$per_page = 15;', "\$data_types = \$types . 'ii';", "\$page_query['queue'] = 'mine';", 'tx-pagination-bar', 'dataTables_paginate paging_simple_numbers'] as $marker) phase_l2b_check(str_contains($source, $marker), 'Server-side pagination marker: ' . $marker, $failures);
    phase_l2b_check(!str_contains($source, "$('#dataTable').DataTable"), 'Client-side DataTables pagination is removed', $failures);
}
if ($failures !== []) { echo "\nLong-run Phase L2B verification FAILED:\n"; foreach ($failures as $failure) echo '- ' . $failure . PHP_EOL; exit(1); }
echo "\nLong-run Phase L2B verification PASSED.\n";
