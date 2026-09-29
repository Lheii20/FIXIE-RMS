<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pagePath = $projectRoot . '/po_list.php';
$failures = [];

function phase_l2a_check(bool $condition, string $message, array &$failures): void
{
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
}

echo "Fixie DRMS Long-run Phase L2A PO pagination verification\n";
echo 'Project: ' . $projectRoot . PHP_EOL . PHP_EOL;
phase_l2a_check(is_file($pagePath), 'po_list.php is present', $failures);

if (is_file($pagePath)) {
    $source = (string) file_get_contents($pagePath);
    foreach ([
        'SELECT COUNT(DISTINCT p.po_id) AS total',
        'LIMIT ? OFFSET ?',
        '$per_page = 15;',
        "$data_types = \$types . 'ii';",
        'http_build_query($query)',
        'tx-pagination-bar',
        'dataTables_paginate paging_simple_numbers',
    ] as $marker) {
        phase_l2a_check(str_contains($source, $marker), 'Server-side pagination marker: ' . $marker, $failures);
    }
    phase_l2a_check(!str_contains($source, "$('#dataTable').DataTable"), 'Client-side DataTables pagination is removed', $failures);
}

if ($failures !== []) {
    echo PHP_EOL . "Long-run Phase L2A verification FAILED:\n";
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "Long-run Phase L2A verification PASSED.\n";
