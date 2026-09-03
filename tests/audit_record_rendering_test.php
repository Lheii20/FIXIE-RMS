<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = $argv[1] ?? dirname(__DIR__);
$page = $projectRoot . DIRECTORY_SEPARATOR . 'audit_logs.php';
$source = file_get_contents($page);
if ($source === false) {
    fwrite(STDERR, "Cannot read audit_logs.php.\n");
    exit(1);
}

// Extract ONLY this pure formatting function from the actual page. Never
// include/execute the page bootstrap, SQL, sessions, or request handlers.
$start = strpos($source, 'function parseAuditRecord(');
$end = strpos($source, '$filterState = drms_audit_normalize_filters');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "The formatting function boundaries changed; update this test.\n");
    exit(1);
}
eval(substr($source, $start, $end - $start));

$passed = 0;
$failed = 0;
function check_rendering(string $name, callable $check): void
{
    global $passed, $failed;
    try {
        $check();
        $passed++;
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL: {$name}: {$error->getMessage()}\n";
    }
}

$cases = [
    'user name is escaped' => ['<img src=x onerror=alert(1)>', 'LOGIN', '', null],
    'document payload is escaped' => ['Tester', 'UPLOAD_DOCUMENT', '', json_encode(['document_name' => '<img src=x onerror=alert(1)>'])],
    'PO number payload is escaped' => ['Tester', 'CREATE_PO', '', json_encode(['po_number' => '<svg onload=alert(1)>'])],
    'PR identifier payload is escaped' => ['Tester', 'VIEW_RECORD', '', json_encode(['pr_id' => '<svg onload=alert(1)>'])],
    'unknown action label is escaped' => ['Tester', 'CUSTOM<img src=x onerror=alert(1)>', '', null],
    'update action label is escaped' => ['Tester', 'UPDATE_<img src=x onerror=alert(1)>', '', null],
    'delete action label is escaped' => ['Tester', 'DELETE_<img src=x onerror=alert(1)>', '', null],
    'raw description is escaped' => ['Tester', 'CUSTOM', '<img src=x onerror=alert(1)>', null],
];
foreach ($cases as $label => $args) {
    check_rendering($label, function () use ($args): void {
        $record = parseAuditRecord(...$args);
        $markup = $record['sentence'] . $record['details'];
        if (preg_match('/<(?:img|svg|script)\b/i', $markup) || strpos($markup, '&lt;') === false) {
            throw new RuntimeException('Markup-like input was not preserved as escaped text.');
        }
    });
}
check_rendering('normal PR format is preserved', function (): void {
    $record = parseAuditRecord('Tester', 'VIEW_RECORD', '', '{"pr_id":7}');
    if (strpos($record['sentence'], 'Purchase Request PR-0007') === false) {
        throw new RuntimeException('The normal PR identifier changed.');
    }
});
check_rendering('normal sentence formatting is preserved', function (): void {
    $record = parseAuditRecord('Mika & Co.', 'LOGIN', 'Successful login');
    if ($record['sentence'] !== "<span class='fw-bold text-main'>Mika &amp; Co.</span> <span class='text-muted'>accessed</span> the system.") {
        throw new RuntimeException('The normal sentence text or span classes changed.');
    }
});

echo "\nResult: {$passed} passed, {$failed} failed. No database connection or writes.\n";
exit($failed === 0 ? 0 : 1);
