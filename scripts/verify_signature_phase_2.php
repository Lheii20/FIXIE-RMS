<?php
/** Read-only installation check. No sessions, DDL, records or notifications are written. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$required = [
    'config/signature_workflow.php', 'config/official_declarations.php',
    'actions/official_declaration_handler.php', 'actions/document_handler.php',
    'official_declarations.php', 'general_docs.php', 'documents.php',
    'assets/css/official-declarations.css', 'assets/js/official-declarations.js',
];
try {
    foreach ($required as $file) {
        if (!is_file($root . '/' . $file)) throw new RuntimeException('Missing file: ' . $file);
    }
    $handler = file_get_contents($root . '/actions/document_handler.php');
    foreach (['drms_declaration_reauthenticate(', 'drms_declaration_locked_request(',
        'drms_declaration_assert_unchanged(', 'drms_declaration_finish('] as $marker) {
        if (strpos($handler, $marker) === false) throw new RuntimeException('The document handler is missing declaration integration.');
    }
    foreach (['documents.php','general_docs.php'] as $file) {
        $source = file_get_contents($root . '/' . $file);
        if (strpos($source, 'href="official_declarations.php') === false || strpos($source, 'openDeclareOfficialModal(') !== false) {
            throw new RuntimeException($file . ' still needs the updated declaration navigation.');
        }
    }
    require_once $root . '/config/runtime.php';
    require_once $root . '/config/signature_workflow.php';
    $config = drms_runtime_section('database');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($config['host'], $config['user'], $config['password'], $config['name'], (int) $config['port']);
    unset($config);
    if (!drms_signature_tables_ready($db)) throw new RuntimeException('Import the Signature Phase 1 SQL migration in the configured database first.');
    $columns = [
        'official_declaration_requests' => ['request_id','request_reference','document_id','source_file_hash','source_version','declaration_basis','requested_signer_role','external_signatory_name','external_signatory_role','external_signature_date','request_remarks','request_status','requested_by','requested_at','reviewed_by','reviewed_at','review_remarks','official_document_id','closed_at'],
        'document_signature_events' => ['signature_id','verification_code','request_id','record_module','record_id','document_id','official_document_id','signature_stage','signature_type','signer_user_id','signer_name','signer_role','signature_method','signed_file_hash','signed_version','consent_text','signed_at','ip_address','user_agent','remarks','signature_status'],
        'notifications' => ['target_role','recipient_user_id','message','target_url','notification_key'],
        'login_attempts' => ['username','ip_address','attempt_time'],
        'users' => ['user_id','full_name','role','password_hash','status','account_status','require_pass_change'],
    ];
    foreach ($columns as $table => $names) {
        $actual = [];
        foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`') as $column) $actual[] = $column['Field'];
        $missing = array_diff($names, $actual);
        if ($missing) throw new RuntimeException('Missing columns in ' . $table . ': ' . implode(', ', $missing));
        echo '[OK] ' . $table . PHP_EOL;
    }
    if (drms_signature_role_can_sign('Admin', 'General Document')) throw new RuntimeException('The signature authority rules are not current.');
    echo '[OK] Required files and declaration integration' . PHP_EOL;
    echo 'Signature Phase 2 installation verification passed.' . PHP_EOL;
    echo 'Next: test one signed Company File with a staff and the requested management account.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
