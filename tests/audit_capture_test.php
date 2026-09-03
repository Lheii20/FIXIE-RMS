<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Each case runs in a separate PHP process so the one-request capture constant
// and session throttle cannot leak between tests. No database is connected.
$cases = [
    'dashboard navigation' => ['path' => 'dashboard.php'],
    'PO list navigation' => ['path' => 'po_list.php'],
    'record directory search' => ['path' => 'documents.php', 'get' => ['search' => 'invoice']],
    'record directory filter' => ['path' => 'documents.php', 'get' => ['type' => 'PDF']],
    'Audit Trail pagination' => ['path' => 'audit_logs.php', 'get' => ['page' => '7']],
    'notification list navigation' => ['path' => 'notifications.php'],
    'repeated session polling' => ['path' => 'api/check_session.php', 'get' => ['activity' => '1'], 'repeat' => 100],
    'cabinet read request' => ['path' => 'actions/cabinet_fetcher.php'],
    'DSS read request' => ['path' => 'api/dss_data.php'],
    'ordinary PO record access' => ['path' => 'view_po.php', 'get' => ['id' => '1'], 'expected' => 'VIEW_RECORD'],
    'PR access with type parameter' => ['path' => 'view_pr.php', 'get' => ['id' => '1', 'type' => 'pr'], 'expected' => 'VIEW_RECORD'],
    'quotation access with search parameter' => ['path' => 'view_quotation.php', 'get' => ['id' => '1', 'search' => 'QTN'], 'expected' => 'VIEW_RECORD'],
    'secure download attempt' => ['path' => 'download.php', 'get' => ['type' => 'document', 'record_id' => '1'], 'expected' => 'DOWNLOAD_ATTEMPT'],
    'create request retained' => ['path' => 'actions/pr_handler.php', 'method' => 'POST', 'post' => ['action' => 'create_pr'], 'expected' => 'CREATE_REQUEST'],
    'approval request retained' => ['path' => 'actions/po_handler.php', 'method' => 'POST', 'post' => ['action' => 'approve_po'], 'expected' => 'APPROVE_REQUEST'],
    'delete request retained' => ['path' => 'actions/document_handler.php', 'method' => 'POST', 'post' => ['action' => 'delete'], 'expected' => 'DELETE_REQUEST'],
    'upload request retained' => ['path' => 'actions/document_handler.php', 'method' => 'POST', 'post' => ['action' => 'upload'], 'expected' => 'UPLOAD_REQUEST'],
    'unclassified payment POST retained' => ['path' => 'actions/collection_payment_handler.php', 'method' => 'POST', 'post' => ['action' => 'record_payment'], 'expected' => 'FORM_SUBMIT'],
    'unknown POST retained' => ['path' => 'actions/example_handler.php', 'method' => 'POST', 'post' => ['action' => 'custom_operation'], 'expected' => 'FORM_SUBMIT'],
    'public login attempt retained' => ['path' => 'actions/auth.php', 'method' => 'POST', 'anonymous' => true, 'post' => ['login' => '1', 'username' => 'test-user'], 'expected' => 'LOGIN_ATTEMPT'],
    'password request does not expose credentials' => ['path' => 'actions/user_handler.php', 'method' => 'POST', 'post' => ['action' => 'change_password', 'password' => 'DO-NOT-LOG-THIS', 'csrf_token' => 'DO-NOT-LOG-THIS'], 'expected' => 'UPDATE_REQUEST'],
    'anonymous record request stays uncaptured' => ['path' => 'view_po.php', 'anonymous' => true],
    'retired endpoint remains excluded' => ['path' => 'api/log_action.php', 'method' => 'POST'],
    'explicit print audit is not duplicated' => ['path' => 'api/log_print.php', 'method' => 'POST'],
    'explicit export audit is not duplicated' => ['path' => 'api/audit_logs_export.php', 'method' => 'POST'],
    'non-GET/POST method stays uncaptured' => ['path' => 'view_po.php', 'method' => 'HEAD'],
    'record-view throttle remains active' => ['path' => 'view_po.php', 'get' => ['id' => '1'], 'throttled' => true],
    'duplicate capture in one request is prevented' => ['path' => 'view_po.php', 'get' => ['id' => '1'], 'repeat' => 2, 'expected' => 'VIEW_RECORD'],
];

if (($argv[1] ?? '') === '--case') {
    $index = (int) ($argv[2] ?? -1);
    $case = array_values($cases)[$index] ?? null;
    if ($case === null) exit(2);

    $capturedEvents = [];
    function drms_log_audit_action($conn, $userId, $action, $description, $old = null, $new = null) {
        $GLOBALS['capturedEvents'][] = ['action' => $action, 'description' => $description];
        return 1;
    }
    require dirname(__DIR__) . '/config/audit_bootstrap.php';

    $_GET = $case['get'] ?? [];
    $_POST = $case['post'] ?? [];
    $_FILES = [];
    $_SESSION = !empty($case['anonymous']) ? [] : ['user_id' => 123];
    $method = $case['method'] ?? 'GET';
    $_SERVER = [
        'REQUEST_METHOD' => $method,
        'SCRIPT_NAME' => '/fixie_drms/' . $case['path'],
        'REQUEST_URI' => '/fixie_drms/' . $case['path'] . '?' . http_build_query($_GET),
    ];
    if (!empty($case['throttled'])) {
        $_SESSION['audit_last_signature'] = sha1($method . '|' . $_SERVER['REQUEST_URI'] . '|VIEW_RECORD');
        $_SESSION['audit_last_signature_time'] = time();
    }

    $connection = mysqli_init();
    for ($run = 0; $run < ($case['repeat'] ?? 1); $run++) {
        drms_capture_request_audit($connection);
    }
    $expected = $case['expected'] ?? null;
    $expectedCount = $expected === null ? 0 : 1;
    if (count($capturedEvents) !== $expectedCount) {
        fwrite(STDERR, 'Unexpected event count: ' . count($capturedEvents));
        exit(1);
    }
    if ($expected !== null && $capturedEvents[0]['action'] !== $expected) {
        fwrite(STDERR, 'Unexpected action: ' . $capturedEvents[0]['action']);
        exit(1);
    }
    if (strpos(json_encode($capturedEvents), 'DO-NOT-LOG-THIS') !== false) {
        fwrite(STDERR, 'Sensitive credential was captured.');
        exit(1);
    }
    if (defined('DRMS_AUDIT_REQUEST_CAPTURED') !== ($expectedCount === 1)) {
        fwrite(STDERR, 'Unexpected request-capture marker.');
        exit(1);
    }
    exit(0);
}

$passed = 0;
$failed = 0;
foreach (array_keys($cases) as $index => $label) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--case', (string) $index],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start isolated test process.\n");
        exit(1);
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode === 0 && trim($errors) === '') {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}: " . trim($output . ' ' . $errors) . "\n";
    }
}
echo "\nResult: {$passed} passed, {$failed} failed. No database connection or writes.\n";
exit($failed === 0 ? 0 : 1);
