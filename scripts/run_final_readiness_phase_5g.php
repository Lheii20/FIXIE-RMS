<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ob_start();

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__, 2);
$connection = null;
$sessions = [];
$originalTokens = [];
$temporaryProfileIds = [];
$temporaryFiles = [];
$run = [];
$passes = [];

function phase5gPass(string $message): void
{
    global $passes;
    $passes[] = $message;
    echo '[PASS] ' . $message . PHP_EOL;
}

function phase5gAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    phase5gPass($message);
}

function phase5gReadJson(string $path): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        throw new RuntimeException('Required E2E marker is unavailable: ' . $path);
    }
    $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('The E2E marker is invalid.');
    }
    return $decoded;
}

function phase5gRow(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $result->free();
    return $row ?: [];
}

function phase5gCount(mysqli $conn, string $sql): int
{
    $row = phase5gRow($conn, $sql);
    return (int) (array_values($row)[0] ?? 0);
}

function phase5gEscape(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function phase5gCreatePdf(string $path, string $label): void
{
    $safeLabel = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $label);
    $stream = 'BT /F1 11 Tf 40 740 Td (' . $safeLabel . ') Tj ET';
    $objects = [
        '1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj',
        '2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj',
        '3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <</Font <</F1 5 0 R>>>> /Contents 4 0 R>> endobj',
        '4 0 obj <</Length ' . strlen($stream) . ">> stream\n" . $stream . "\nendstream endobj",
        '5 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($index = 1; $index <= 5; $index++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$index]) . "\n";
    }
    $pdf .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";
    if (file_put_contents($path, $pdf, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create temporary PDF evidence.');
    }
}

function phase5gHeader(string $headers, string $name): ?string
{
    foreach (preg_split('/\r\n|\n|\r/', $headers) ?: [] as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

function phase5gRequest(
    string $baseUrl,
    string $path,
    array $session,
    string $method = 'GET',
    array $fields = [],
    array $files = [],
    string $accept = 'text/html,application/xhtml+xml'
): array {
    foreach ($files as $field => $file) {
        $fields[$field] = new CURLFile(
            (string) $file['path'],
            (string) ($file['mime'] ?? 'application/pdf'),
            (string) ($file['name'] ?? basename((string) $file['path']))
        );
    }

    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize HTTP request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_COOKIE => $session['cookie_name'] . '=' . $session['session_id'],
        CURLOPT_USERAGENT => 'Fixie-DRMS-Phase5G-E2E/1.0',
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $fields;
    }
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed for ' . $path . ': ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $headers = substr((string) $response, 0, $headerSize);
    $body = substr((string) $response, $headerSize);
    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
        'location' => phase5gHeader($headers, 'Location') ?? '',
        'content_type' => phase5gHeader($headers, 'Content-Type') ?? '',
    ];
}

function phase5gPost(
    string $baseUrl,
    string $path,
    array $session,
    array $fields,
    array $files = []
): array {
    $response = phase5gRequest($baseUrl, $path, $session, 'POST', $fields, $files);
    if ($response['status'] !== 302) {
        throw new RuntimeException($path . ' returned HTTP ' . $response['status'] . ' instead of a workflow redirect.');
    }
    if (preg_match('/(?:^|[?&])error=/', $response['location']) === 1) {
        throw new RuntimeException($path . ' rejected the action: ' . rawurldecode($response['location']));
    }
    return $response;
}

function phase5gPostExpectedError(
    string $baseUrl,
    string $path,
    array $session,
    array $fields,
    string $expectedText
): void {
    $response = phase5gRequest($baseUrl, $path, $session, 'POST', $fields);
    $decodedLocation = urldecode($response['location']);
    phase5gAssert(
        $response['status'] === 302 &&
        preg_match('/(?:^|[?&])error=/', $response['location']) === 1 &&
        stripos($decodedLocation, $expectedText) !== false,
        'Retention correctly blocks a premature disposition request.'
    );
}

function phase5gJson(array $response, int $expectedStatus = 200): array
{
    if ($response['status'] !== $expectedStatus) {
        throw new RuntimeException('JSON route returned HTTP ' . $response['status'] . ' instead of ' . $expectedStatus . '.');
    }
    $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON route returned an invalid response.');
    }
    return $decoded;
}

function phase5gCreateSession(mysqli $conn, array $marker, array $user): array
{
    global $originalTokens;
    $userId = (int) $user['user_id'];
    $sessionToken = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $sessionId = bin2hex(random_bytes(24));
    $originalTokens[$userId] = $user['session_token'];

    $statement = $conn->prepare('UPDATE users SET session_token = ? WHERE user_id = ?');
    $statement->bind_param('si', $sessionToken, $userId);
    $statement->execute();
    $statement->close();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ini_set('session.use_strict_mode', '0');
    session_name((string) $marker['session_name']);
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('Unable to create an isolated web session for ' . $user['role'] . '.');
    }
    $_SESSION = [
        'user_id' => $userId,
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'fullname' => (string) $user['full_name'],
        'avatar' => (string) ($user['avatar'] ?? ''),
        'session_token' => $sessionToken,
        'last_activity' => time(),
        'csrf_token' => $csrf,
    ];
    session_write_close();

    return [
        'cookie_name' => (string) $marker['session_name'],
        'session_id' => $sessionId,
        'csrf' => $csrf,
        'user_id' => $userId,
        'role' => (string) $user['role'],
    ];
}

function phase5gEnsureGmSignatureProfile(mysqli $conn, array $gm): array
{
    $userId = (int) $gm['user_id'];
    $existing = phase5gRow(
        $conn,
        'SELECT signature_profile_id, profile_status FROM user_signature_profiles WHERE user_id = ' . $userId . ' LIMIT 1'
    );
    if ($existing && $existing['profile_status'] === 'Active') {
        return [];
    }
    if ($existing) {
        throw new RuntimeException('The E2E GM has a non-active electronic-signature profile. Activate it before Phase 5G.');
    }
    $name = (string) $gm['full_name'];
    $title = 'General Manager';
    $statement = $conn->prepare(
        "INSERT INTO user_signature_profiles (user_id, display_name, signatory_title, profile_status) VALUES (?, ?, ?, 'Active')"
    );
    $statement->bind_param('iss', $userId, $name, $title);
    $statement->execute();
    $profileId = (int) $conn->insert_id;
    $statement->close();
    return [$profileId];
}

try {
    if (!is_dir($projectRoot) || realpath($projectRoot) === false) {
        throw new RuntimeException('The isolated application folder was not found.');
    }
    $projectRoot = (string) realpath($projectRoot);
    $marker = phase5gReadJson($projectRoot . '/.fixie-e2e-environment.json');
    phase5gAssert(
        ($marker['format'] ?? '') === 'FIXIE_DRMS_ISOLATED_E2E' &&
        strcasecmp((string) realpath((string) ($marker['test_project'] ?? '')), $projectRoot) === 0 &&
        stripos((string) ($marker['test_database'] ?? ''), 'e2e') !== false,
        'The runner is confined to the installed Phase 5A E2E environment.'
    );

    require_once $projectRoot . '/config/runtime.php';
    $runtime = drms_runtime_config();
    phase5gAssert(
        (string) $runtime['database']['name'] === (string) $marker['test_database'] &&
        rtrim((string) $runtime['app']['url'], '/') === rtrim((string) $marker['application_url'], '/'),
        'The E2E URL and database match the environment marker.'
    );
    phase5gAssert(
        $runtime['features']['scheduled_maintenance'] === false &&
        $runtime['features']['server_backup'] === false &&
        $runtime['mail']['required'] === false,
        'Mail, scheduled maintenance, and server backup remain disabled for this run.'
    );

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = $runtime['database'];
    $connection = new mysqli(
        (string) $db['host'],
        (string) $db['user'],
        (string) $db['password'],
        (string) $db['name'],
        (int) $db['port']
    );
    $connection->set_charset('utf8mb4');

    $actors = [];
    foreach (['Sales Staff', 'GM'] as $role) {
        $user = phase5gRow(
            $connection,
            'SELECT user_id, username, full_name, role, avatar, session_token FROM users ' .
            'WHERE role = ' . phase5gEscape($connection, $role) .
            " AND status = 'Active' AND account_status = 'Active' AND require_pass_change = 0 ORDER BY user_id LIMIT 1"
        );
        if (!$user) {
            throw new RuntimeException('No ready E2E actor is available for role: ' . $role);
        }
        $actors[$role] = $user;
    }
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM user_permissions WHERE user_id = " . (int) $actors['Sales Staff']['user_id'] . " AND permission_name = 'can_upload_documents'") === 1,
        'The Sales Staff test actor can upload Company Files.'
    );
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM user_permissions WHERE user_id = " . (int) $actors['GM']['user_id'] . " AND permission_name IN ('can_view_all_folders','can_manage_folders','can_manage_disposition')") === 3,
        'The GM test actor can manage records, physical filing, and disposition requests.'
    );

    $folder = phase5gRow(
        $connection,
        "SELECT dc.id, dc.parent_category, dc.sub_category, dc.record_prefix, dc.policy_id, dc.is_system_folder,
                p.active_years, p.active_months, p.archive_years, p.archive_months
         FROM document_categories dc
         JOIN category_role_access cra ON cra.category_id = dc.id AND cra.role_name = 'Sales Staff'
         JOIN retention_policies p ON p.policy_id = dc.policy_id
         WHERE dc.sub_category = 'Purchase requests'
           AND dc.is_system_folder = 0
           AND dc.record_prefix REGEXP '^[A-Z][A-Z0-9]{1,9}$'
         LIMIT 1"
    );
    phase5gAssert(
        $folder &&
        ((int) $folder['active_years'] + (int) $folder['active_months'] + (int) $folder['archive_years'] + (int) $folder['archive_months']) > 0,
        'A Sales-accessible folder has a valid record code and non-zero retention period.'
    );
    $physicalFolder = phase5gRow(
        $connection,
        "SELECT id, code, name FROM virt_physical_folders WHERE is_active = 1 ORDER BY (name = 'Purchase Request') DESC, id LIMIT 1"
    );
    phase5gAssert((bool) $physicalFolder, 'An active Virtual Cabinet physical folder is available.');

    $temporaryProfileIds = phase5gEnsureGmSignatureProfile($connection, $actors['GM']);
    phase5gPass('The GM has an active electronic-signature profile.');
    foreach ($actors as $role => $actor) {
        $sessions[$role] = phase5gCreateSession($connection, $marker, $actor);
    }
    phase5gPass('Sales Staff and GM sessions were created only for the isolated application.');
    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    $baseUrl = (string) $marker['application_url'];
    $health = phase5gRequest($baseUrl, 'index.php', $sessions['GM']);
    phase5gAssert(in_array($health['status'], [200, 302], true), 'The isolated Apache application is reachable.');

    $runId = strtoupper(date('ymdHis') . substr(bin2hex(random_bytes(3)), 0, 6));
    $displayBase = 'E2E Signed RMS Lifecycle ' . $runId;
    $initialPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixie_phase5g_initial_' . strtolower($runId) . '.pdf';
    $revisedPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixie_phase5g_revised_' . strtolower($runId) . '.pdf';
    $temporaryFiles = [$initialPdf, $revisedPdf];
    phase5gCreatePdf($initialPdf, 'Signed RMS lifecycle initial ' . $runId);
    phase5gCreatePdf($revisedPdf, 'Signed RMS lifecycle revised ' . $runId);
    $initialHash = hash_file('sha256', $initialPdf);
    $revisedHash = hash_file('sha256', $revisedPdf);

    phase5gPost($baseUrl, 'actions/document_handler.php', $sessions['Sales Staff'], [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'upload',
        'category' => (string) $folder['sub_category'],
        'doc_type' => 'Signed supporting record',
        'document_name' => $displayBase,
        'record_intake' => 'working',
        'physical_status' => 'Digital',
        'source' => 'general_docs',
    ], [
        'document' => ['path' => $initialPdf, 'mime' => 'application/pdf', 'name' => 'signed-rms-' . $runId . '.pdf'],
    ]);
    $working = phase5gRow(
        $connection,
        'SELECT * FROM documents WHERE uploaded_by = ' . (int) $actors['Sales Staff']['user_id'] .
        ' AND file_hash = ' . phase5gEscape($connection, (string) $initialHash) .
        " AND record_phase = 'Working' ORDER BY doc_id DESC LIMIT 1"
    );
    phase5gAssert($working && $working['category'] === $folder['sub_category'], 'Sales Staff uploaded a real Working Company File through the HTTP handler.');
    $workingId = (int) $working['doc_id'];
    $run['working_doc_id'] = $workingId;

    // Make the test fixture prove that official filing time is independent of
    // the original Company File upload time, without changing its binary.
    $connection->query("UPDATE documents SET uploaded_at = '2020-03-12 09:00:00' WHERE doc_id = {$workingId}");

    $workingSearch = phase5gRequest(
        $baseUrl,
        'general_docs.php?type=' . rawurlencode((string) $folder['sub_category']) . '&search=' . rawurlencode($displayBase),
        $sessions['Sales Staff']
    );
    phase5gAssert(
        $workingSearch['status'] === 200 && stripos($workingSearch['body'], $displayBase . '.pdf') !== false,
        'Company Files search retrieves the uploaded Working file.'
    );

    phase5gPost($baseUrl, 'actions/version_handler.php', $sessions['Sales Staff'], [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'upload_version',
        'doc_id' => (string) $workingId,
        'remarks' => 'Signed revision verified for declaration.',
        'source_page' => 'general_docs.php?type=' . rawurlencode((string) $folder['sub_category']),
    ], [
        'new_document' => ['path' => $revisedPdf, 'mime' => 'application/pdf', 'name' => 'signed-rms-revised-' . $runId . '.pdf'],
    ]);
    $working = phase5gRow($connection, 'SELECT * FROM documents WHERE doc_id = ' . $workingId);
    phase5gAssert(
        (float) $working['current_version'] === 2.0 && hash_equals((string) $revisedHash, (string) $working['file_hash']),
        'A real new Company File version became v2.0 with its new SHA-256 hash.'
    );
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM document_versions WHERE doc_id = {$workingId}") === 2,
        'The Working file retains both v1.0 and v2.0 before declaration.'
    );

    $profileResponse = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/cabinet_fetcher.php?action=get_document_profile&doc_id=' . $workingId,
        $sessions['GM'],
        'GET',
        [],
        [],
        'application/json'
    ));
    phase5gAssert(!empty($profileResponse['ok']) && !empty($profileResponse['document']['revision']), 'Virtual Cabinet loaded the Working file profile.');
    $assignResponse = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/physical_location_handler.php',
        $sessions['GM'],
        'POST',
        [
            'csrf_token' => $sessions['GM']['csrf'],
            'action' => 'assign_copy',
            'doc_id' => (string) $workingId,
            'revision' => (string) $profileResponse['document']['revision'],
            'folder' => 'folder:' . (int) $physicalFolder['id'],
            'reason' => 'Phase 5G confirms the signed paper copy filing position.',
            'confirmed' => '1',
        ],
        [],
        'application/json'
    ));
    phase5gAssert(
        !empty($assignResponse['ok']) &&
        phase5gCount($connection, "SELECT COUNT(*) FROM virt_document_locations WHERE document_id = {$workingId} AND physical_folder_id = " . (int) $physicalFolder['id']) === 1,
        'The physical copy was assigned to a real Virtual Cabinet folder.'
    );

    phase5gPost($baseUrl, 'actions/official_declaration_handler.php', $sessions['Sales Staff'], [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'submit',
        'doc_id' => (string) $workingId,
        'request_id' => '0',
        'signer_role' => 'GM',
        'external_name' => 'Authorized External Signatory',
        'external_role' => 'Client Representative',
        'external_date' => date('Y-m-d'),
        'signed_copy_confirmed' => '1',
        'remarks' => 'Signed copy is complete and ready for GM verification.',
    ]);
    $request = phase5gRow(
        $connection,
        "SELECT * FROM official_declaration_requests WHERE document_id = {$workingId} ORDER BY request_id DESC LIMIT 1"
    );
    phase5gAssert(
        $request && $request['request_status'] === 'Pending' &&
        hash_equals((string) $revisedHash, (string) $request['source_file_hash']),
        'Staff submission created a Pending GM declaration request bound to the exact v2.0 file hash.'
    );
    $requestId = (int) $request['request_id'];
    $run['request_id'] = $requestId;

    $gmQueue = phase5gRequest($baseUrl, 'official_declarations.php?request_id=' . $requestId, $sessions['GM']);
    phase5gAssert(
        $gmQueue['status'] === 200 && stripos($gmQueue['body'], (string) $request['request_reference']) !== false,
        'The GM can open the submitted declaration request.'
    );
    $staffQueue = phase5gRequest($baseUrl, 'official_declarations.php?request_id=' . $requestId, $sessions['Sales Staff']);
    phase5gAssert(
        $staffQueue['status'] === 200 &&
        stripos($staffQueue['body'], 'Only the General Manager can open Official Record declaration requests.') !== false &&
        stripos($staffQueue['body'], (string) $request['request_reference']) === false,
        'A non-GM account cannot inspect the GM declaration decision workspace.'
    );

    phase5gPost($baseUrl, 'actions/document_handler.php', $sessions['GM'], [
        'csrf_token' => $sessions['GM']['csrf'],
        'action' => 'declare_official',
        'doc_id' => (string) $workingId,
        'request_id' => (string) $requestId,
        'remarks' => 'Phase 5G GM signature verification completed.',
        'e_signature_confirmed' => '1',
    ]);
    $working = phase5gRow($connection, 'SELECT * FROM documents WHERE doc_id = ' . $workingId);
    $officialId = (int) ($working['official_doc_id'] ?? 0);
    $official = phase5gRow($connection, 'SELECT * FROM documents WHERE doc_id = ' . $officialId);
    $run['official_doc_id'] = $officialId;
    $run['record_number'] = (string) ($official['record_number'] ?? '');

    $prefix = preg_quote((string) $folder['record_prefix'], '/');
    phase5gAssert(
        $working['record_phase'] === 'Converted' && (int) $working['is_locked'] === 1 &&
        $official && $official['record_phase'] === 'Official' && (int) $official['is_locked'] === 1,
        'GM e-signature converted the Company File and created one locked Official Record.'
    );
    phase5gAssert(
        preg_match('/^' . $prefix . '-' . date('Y') . '-\d{4}$/', (string) $official['record_number']) === 1 &&
        $official['file_name'] === $official['record_number'] . '.pdf',
        'The Official Record and stored filename use the folder code, year, and controlled sequence.'
    );
    phase5gAssert(
        hash_equals((string) $revisedHash, (string) $official['file_hash']) &&
        preg_match('#^uploads/official/[a-z0-9]+/#', (string) $official['file_path']) === 1,
        'The Official Record has an independently stored immutable copy with the verified v2.0 hash.'
    );
    phase5gAssert(
        substr((string) $working['uploaded_at'], 0, 10) === '2020-03-12' &&
        substr((string) $official['uploaded_at'], 0, 10) === date('Y-m-d') &&
        substr((string) $official['declared_at'], 0, 10) === date('Y-m-d'),
        'Official filing date is independent from the original Company File upload date.'
    );
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM official_declaration_requests WHERE request_id = {$requestId} AND request_status = 'Approved' AND official_document_id = {$officialId}") === 1 &&
        phase5gCount($connection, "SELECT COUNT(*) FROM document_signature_events WHERE request_id = {$requestId} AND official_document_id = {$officialId} AND signature_stage = 'Official Declaration' AND signature_status = 'Valid'") === 1,
        'The declaration request and one valid GM electronic-signature event point to the same Official Record.'
    );
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM virt_document_locations WHERE document_id = {$workingId}") === 0 &&
        phase5gCount($connection, "SELECT COUNT(*) FROM virt_document_locations WHERE document_id = {$officialId} AND physical_folder_id = " . (int) $physicalFolder['id']) === 1,
        'Physical filing transferred from the Converted source to the Official Record without duplication.'
    );
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM document_versions WHERE doc_id = {$officialId}") >= 3 &&
        phase5gCount($connection, "SELECT COUNT(*) FROM document_versions WHERE doc_id = {$officialId} AND remarks LIKE '[Pre-official working version]%' ") >= 2,
        'The Official Record preserved the complete pre-official version lineage plus its immutable snapshot.'
    );

    $history = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/version_handler.php?action=get_history&doc_id=' . $officialId,
        $sessions['Sales Staff'],
        'GET',
        [],
        [],
        'application/json'
    ));
    $activityTitles = array_map(static fn(array $item): string => (string) ($item['title'] ?? ''), $history['activity'] ?? []);
    phase5gAssert(
        !empty($history['success']) && count($history['data'] ?? []) >= 3 &&
        in_array('Company File uploaded', $activityTitles, true) &&
        in_array('Official Record filed', $activityTitles, true),
        'The official version-history endpoint shows both Company File origin and Official filing activity.'
    );

    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM documents WHERE doc_id = {$workingId} AND record_phase IN ('Working','For Review')") === 0,
        'The Converted source is excluded from the Company Files dataset.'
    );
    $officialSearch = phase5gRequest(
        $baseUrl,
        'documents.php?type=' . rawurlencode((string) $folder['sub_category']) . '&search=' . rawurlencode((string) $official['record_number']),
        $sessions['Sales Staff']
    );
    phase5gAssert(
        $officialSearch['status'] === 200 && substr_count($officialSearch['body'], (string) $official['record_number']) >= 1,
        'Official Records search retrieves the new record by controlled record number.'
    );

    $download = phase5gRequest(
        $baseUrl,
        'download.php?type=document&record_id=' . $officialId,
        $sessions['Sales Staff'],
        'GET',
        [],
        [],
        'application/pdf'
    );
    phase5gAssert(
        $download['status'] === 200 &&
        stripos($download['content_type'], 'application/pdf') !== false &&
        hash_equals((string) $official['file_hash'], hash('sha256', $download['body'])),
        'Authorized secure retrieval returns the exact Official Record binary.'
    );

    $cabinetSearch = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/cabinet_fetcher.php?action=get_documents&scope=all&custody=all&query=' . rawurlencode((string) $official['record_number']) . '&page=1',
        $sessions['GM'],
        'GET',
        [],
        [],
        'application/json'
    ));
    $cabinetIds = array_map(static fn(array $item): int => (int) ($item['doc_id'] ?? 0), $cabinetSearch['data'] ?? []);
    phase5gAssert(
        in_array($officialId, $cabinetIds, true) && !in_array($workingId, $cabinetIds, true),
        'Virtual Cabinet search returns the Official Record and not its Converted source.'
    );

    $officialProfile = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/cabinet_fetcher.php?action=get_document_profile&doc_id=' . $officialId,
        $sessions['GM'],
        'GET',
        [],
        [],
        'application/json'
    ));
    $borrow = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/physical_location_handler.php',
        $sessions['GM'],
        'POST',
        [
            'csrf_token' => $sessions['GM']['csrf'],
            'action' => 'borrow_copy',
            'doc_id' => (string) $officialId,
            'revision' => (string) $officialProfile['document']['revision'],
            'holder_id' => (string) $actors['Sales Staff']['user_id'],
            'expected_return' => (new DateTimeImmutable('+1 day'))->format('Y-m-d'),
            'reason' => 'Phase 5G controlled physical-copy check-out.',
            'confirmed' => '1',
        ],
        [],
        'application/json'
    ));
    phase5gAssert(!empty($borrow['ok']), 'Physical-copy check-out was recorded.');
    $borrowedProfile = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/cabinet_fetcher.php?action=get_document_profile&doc_id=' . $officialId,
        $sessions['GM'],
        'GET',
        [],
        [],
        'application/json'
    ));
    phase5gAssert(($borrowedProfile['document']['physical_status'] ?? '') === 'Borrowed', 'Virtual Cabinet reports the checked-out custody state.');
    $returned = phase5gJson(phase5gRequest(
        $baseUrl,
        'actions/physical_location_handler.php',
        $sessions['GM'],
        'POST',
        [
            'csrf_token' => $sessions['GM']['csrf'],
            'action' => 'return_copy',
            'doc_id' => (string) $officialId,
            'revision' => (string) $borrowedProfile['document']['revision'],
            'reason' => 'Phase 5G controlled physical-copy return.',
            'confirmed' => '1',
        ],
        [],
        'application/json'
    ));
    phase5gAssert(
        !empty($returned['ok']) &&
        phase5gCount($connection, "SELECT COUNT(*) FROM physical_borrowing_logs WHERE document_id = {$officialId} AND action_type IN ('Borrowed','Returned')") >= 2,
        'Physical-copy return completed with a preserved custody trail.'
    );

    phase5gPostExpectedError($baseUrl, 'actions/disposition_handler.php', $sessions['GM'], [
        'csrf_token' => $sessions['GM']['csrf'],
        'action' => 'request_disposition',
        'doc_id' => (string) $officialId,
        'reason' => 'Phase 5G verifies that retention prevents premature disposition.',
    ], 'has not completed its retention period');
    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM disposition_requests WHERE doc_id = {$officialId}") === 0 &&
        phase5gCount($connection, "SELECT COUNT(*) FROM documents WHERE doc_id = {$officialId} AND record_phase = 'Official' AND is_locked = 1") === 1,
        'The blocked disposition left the Official Record and request queue unchanged.'
    );

    phase5gAssert(
        phase5gCount($connection, "SELECT COUNT(*) FROM audit_logs WHERE user_id IN (" . (int) $actors['Sales Staff']['user_id'] . ',' . (int) $actors['GM']['user_id'] . ") AND action_type IN ('UPLOAD_RECORD','UPDATE_VERSION','ASSIGN_COPY','REQUEST_OFFICIAL_DECLARATION','DECLARE_OFFICIAL','BORROW_COPY','RETURN_COPY') AND (description LIKE '%{$workingId}%' OR description LIKE '%{$officialId}%' OR description LIKE '%" . $connection->real_escape_string($runId) . "%')") >= 6,
        'Material record, version, declaration, filing, and custody actions are present in the audit log.'
    );

    echo "\nPhase 5G record-lifecycle verification PASSED.\n\n";
    echo '- Run ID: ' . $runId . "\n";
    echo '- Company File ID: ' . $workingId . " (Converted)\n";
    echo '- Declaration request ID: ' . $requestId . " (Approved)\n";
    echo '- Official Record: ' . $official['record_number'] . ' (ID ' . $officialId . ")\n";
    echo '- Digital version lineage: v1.0 -> v2.0 -> immutable Official snapshot' . "\n";
    echo '- Physical custody: assigned -> checked out -> returned' . "\n";
    echo '- Retention safeguard: premature disposition blocked' . "\n";
    echo "\nThe acceptance records remain only in fixie_drms_e2e for inspection. The live application and fixie_drms database were not used.\n";
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    fwrite(STDERR, "\nPhase 5G record-lifecycle verification FAILED:\n\n- " . $error->getMessage() . "\n");
    if ($run) {
        fwrite(STDERR, '- Partial E2E identifiers: ' . json_encode($run, JSON_UNESCAPED_SLASHES) . "\n");
    }
    $phase5gExit = 1;
} finally {
    if ($connection instanceof mysqli) {
        foreach ($originalTokens as $userId => $token) {
            if ($token === null || $token === '') {
                $connection->query('UPDATE users SET session_token = NULL WHERE user_id = ' . (int) $userId);
            } else {
                $statement = $connection->prepare('UPDATE users SET session_token = ? WHERE user_id = ?');
                $statement->bind_param('si', $token, $userId);
                $statement->execute();
                $statement->close();
            }
        }
        foreach ($temporaryProfileIds as $profileId) {
            $connection->query('DELETE FROM user_signature_profiles WHERE signature_profile_id = ' . (int) $profileId);
        }
        $connection->close();
    }
    foreach ($temporaryFiles as $temporaryFile) {
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
    if (isset($sessions) && is_array($sessions) && session_module_name() === 'files') {
        $savePath = session_save_path();
        if (str_contains($savePath, ';')) {
            $savePath = substr($savePath, strrpos($savePath, ';') + 1);
        }
        if ($savePath !== '' && is_dir($savePath)) {
            foreach ($sessions as $session) {
                $sessionFile = rtrim($savePath, "/\\") . DIRECTORY_SEPARATOR . 'sess_' . $session['session_id'];
                if (is_file($sessionFile)) {
                    @unlink($sessionFile);
                }
            }
        }
    }
}

exit($phase5gExit ?? 0);
