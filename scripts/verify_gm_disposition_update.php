<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'GM-only reviewer capability' => [
        'config/rbac_policy.php',
        "'description' => 'The General Manager approves the retention-rule action or retains/defers the Official Record for a later review.'",
    ],
    'Operational staff request capability' => [
        'config/rbac_policy.php',
        "'allowed_roles' => ['Finance', 'Procurement', 'Supply Chain', 'Sales Staff']",
    ],
    'Admin hard redirect from disposition records' => [
        'documents.php',
        'System Administrators are restricted from viewing document directories.',
    ],
    'GM review action guard' => [
        'actions/disposition_handler.php',
        '$userRole !== \'GM\' || !has_permission($conn, $userId, \'can_approve_disposition\')',
    ],
    'Retain/defer review action' => [
        'actions/disposition_handler.php',
        "'retain_disposition'",
    ],
    'No reject decision in the GM review flow' => [
        'actions/disposition_handler.php',
        'in_array($action, [\'approve_disposition\', \'retain_disposition\'], true)',
    ],
    'Approve and delete action is visible to the GM' => [
        'documents.php',
        "'Approve &amp; Delete'",
    ],
    'Retain/defer closes the pending request without deleting the file' => [
        'actions/disposition_handler.php',
        '$decision = \'Cancelled\';',
    ],
    'Deferred review date is stored without changing policy date' => [
        'actions/disposition_handler.php',
        "disposition_review_due_at = ?",
    ],
    'Queue respects future defer date' => [
        'documents.php',
        "d.disposition_status = 'Deferred' AND d.disposition_review_due_at IS NOT NULL AND d.disposition_review_due_at <= NOW()",
    ],
    'Maintenance returns deferred records when due' => [
        'config/maintenance_engine.php',
        "d.disposition_review_due_at <= NOW()",
    ],
    'Migration installs defer-date column and account grants' => [
        'database/migrations/phase_gm_disposition_review.sql',
        "ALTER TABLE documents ADD COLUMN disposition_review_due_at DATETIME NULL DEFAULT NULL AFTER disposition_status",
    ],
    'Review form includes next review date' => [
        'documents.php',
        'name="defer_until" id="dispositionDeferUntil"',
    ],
];

$failed = 0;
foreach ($checks as $label => [$relativePath, $needle]) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $contents = is_file($path) ? file_get_contents($path) : false;
    $passed = is_string($contents) && strpos($contents, $needle) !== false;
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}

$handlerSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'disposition_handler.php');
if (is_string($handlerSource) && strpos($handlerSource, 'reject_disposition') === false) {
    echo 'PASS  No reject action endpoint is accepted' . PHP_EOL;
} else {
    echo 'FAIL  No reject action endpoint is accepted' . PHP_EOL;
    $failed++;
}

foreach (['documents.php', 'actions/disposition_handler.php', 'config/rbac_policy.php', 'config/maintenance_engine.php'] as $relativePath) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        echo 'FAIL  Missing replacement file: ' . $relativePath . PHP_EOL;
        $failed++;
        continue;
    }
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    $passed = $exitCode === 0;
    echo ($passed ? 'PASS  ' : 'FAIL  ') . 'PHP syntax: ' . $relativePath . PHP_EOL;
    if (!$passed) {
        echo implode(PHP_EOL, $output) . PHP_EOL;
        $failed++;
    }
}

if ($failed > 0) {
    fwrite(STDERR, "GM disposition update verification FAILED: $failed check(s)." . PHP_EOL);
    exit(1);
}

echo 'GM disposition update verification PASSED (static checks only; database/browser test still required).' . PHP_EOL;
