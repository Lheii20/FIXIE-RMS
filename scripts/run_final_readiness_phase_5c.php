<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Keep CLI output buffered until every temporary web session has been created.
// PHP otherwise treats early console output as sent headers and refuses to
// change the session name or ID, even in CLI mode.
ob_start();

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__, 2);
$connection = null;
$sessions = [];
$originalTokens = [];
$temporaryProfiles = [];
$proofPath = null;
$passes = [];
$run = [];

function phase5cPass(string $message): void
{
    global $passes;
    $passes[] = $message;
    echo '[PASS] ' . $message . PHP_EOL;
}

function phase5cAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    phase5cPass($message);
}

function phase5cReadJson(string $path): array
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

function phase5cRow(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $result->free();
    return $row ?: [];
}

function phase5cCount(mysqli $conn, string $sql): int
{
    $row = phase5cRow($conn, $sql);
    return (int) (array_values($row)[0] ?? 0);
}

function phase5cEscape(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function phase5cMoney(float $actual, float $expected, string $label): void
{
    phase5cAssert(
        abs(round($actual, 2) - round($expected, 2)) <= 0.01,
        $label . ' equals ' . number_format($expected, 2) . '.'
    );
}

function phase5cCreatePdf(string $path, string $runId): void
{
    $label = 'Fixie DRMS isolated acceptance evidence ' . $runId;
    $stream = "BT /F1 11 Tf 40 740 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $label) . ") Tj ET";
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

function phase5cHeader(string $headers, string $name): ?string
{
    foreach (preg_split('/\r\n|\n|\r/', $headers) ?: [] as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

function phase5cPost(
    string $baseUrl,
    string $path,
    array $session,
    array $fields,
    array $files = []
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
        throw new RuntimeException('Unable to initialize HTTP workflow request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_COOKIE => $session['cookie_name'] . '=' . $session['session_id'],
        CURLOPT_USERAGENT => 'Fixie-DRMS-Phase5C-E2E/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);
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
    $location = phase5cHeader($headers, 'Location') ?? '';
    $decodedLocation = rawurldecode($location);
    if ($status !== 302) {
        throw new RuntimeException($path . ' returned HTTP ' . $status . ' instead of a workflow redirect.');
    }
    if (preg_match('/(?:^|[?&])error=/', $location) === 1) {
        throw new RuntimeException($path . ' rejected the action: ' . $decodedLocation);
    }
    return ['status' => $status, 'location' => $location];
}

function phase5cCreateSession(
    mysqli $conn,
    array $marker,
    array $user
): array {
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

function phase5cWaitUntil(DateTimeImmutable $target, string $purpose): void
{
    $remaining = $target->getTimestamp() - time() + 1;
    if ($remaining <= 0) {
        return;
    }
    echo '[WAIT] ' . $purpose . ' (' . $remaining . " seconds maximum).\n";
    flush();
    while ($remaining > 0) {
        $step = min(30, $remaining);
        sleep($step);
        $remaining = $target->getTimestamp() - time() + 1;
        if ($remaining > 0) {
            echo '[WAIT] ' . $remaining . " seconds remaining.\n";
            flush();
        }
    }
}

function phase5cEnsureSignatureProfiles(mysqli $conn, array $actors): array
{
    $created = [];
    foreach (['GM', 'Finance', 'President', 'Supply Chain'] as $role) {
        $user = $actors[$role];
        $userId = (int) $user['user_id'];
        $existing = phase5cRow(
            $conn,
            'SELECT signature_profile_id, profile_status FROM user_signature_profiles WHERE user_id = ' . $userId . ' LIMIT 1'
        );
        if ($existing && $existing['profile_status'] === 'Active') {
            continue;
        }
        if ($existing) {
            throw new RuntimeException('The ' . $role . ' E2E account has a non-active signature profile. Activate it before Phase 5C.');
        }
        $displayName = (string) $user['full_name'];
        $title = $role === 'Supply Chain' ? 'Supply Chain Authorized Signatory' : $role;
        $statement = $conn->prepare(
            "INSERT INTO user_signature_profiles (user_id, display_name, signatory_title, profile_status) VALUES (?, ?, ?, 'Active')"
        );
        $statement->bind_param('iss', $userId, $displayName, $title);
        $statement->execute();
        $created[] = (int) $conn->insert_id;
        $statement->close();
    }
    return $created;
}

try {
    if (!is_dir($projectRoot) || realpath($projectRoot) === false) {
        throw new RuntimeException('The isolated application folder was not found.');
    }
    $projectRoot = (string) realpath($projectRoot);
    $marker = phase5cReadJson($projectRoot . '/.fixie-e2e-environment.json');
    phase5cAssert(
        ($marker['format'] ?? '') === 'FIXIE_DRMS_ISOLATED_E2E' &&
        strcasecmp((string) realpath((string) ($marker['test_project'] ?? '')), $projectRoot) === 0 &&
        stripos((string) ($marker['test_database'] ?? ''), 'e2e') !== false,
        'The runner is confined to the installed Phase 5A E2E environment.'
    );

    require_once $projectRoot . '/config/runtime.php';
    $runtime = drms_runtime_config();
    phase5cAssert(
        (string) $runtime['database']['name'] === (string) $marker['test_database'] &&
        rtrim((string) $runtime['app']['url'], '/') === rtrim((string) $marker['application_url'], '/'),
        'The E2E URL and database match the environment marker.'
    );
    phase5cAssert(
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
    foreach (['Sales Staff', 'GM', 'Finance', 'President', 'Procurement', 'Supply Chain'] as $role) {
        $roleSql = phase5cEscape($connection, $role);
        $user = phase5cRow(
            $connection,
            "SELECT user_id, username, full_name, role, avatar, session_token FROM users " .
            "WHERE role = {$roleSql} AND status = 'Active' AND account_status = 'Active' " .
            'AND require_pass_change = 0 ORDER BY user_id LIMIT 1'
        );
        if (!$user) {
            throw new RuntimeException('No ready E2E actor is available for role: ' . $role);
        }
        $actors[$role] = $user;
    }
    phase5cPass('Every happy-path workflow role has an active test actor.');

    $temporaryProfiles = phase5cEnsureSignatureProfiles($connection, $actors);
    phase5cPass('All four signatory roles have an active electronic-signature profile.');

    foreach ($actors as $role => $actor) {
        $sessions[$role] = phase5cCreateSession($connection, $marker, $actor);
    }
    phase5cPass('Role-specific authenticated sessions were created only for the isolated application.');
    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    $runId = strtoupper(date('ymdHis') . substr(bin2hex(random_bytes(3)), 0, 6));
    $numericSequence = date('mdHis') . (string) random_int(1000, 9999);
    $year = date('Y');
    $quotationNumber = 'QTN-' . $year . '-' . $numericSequence;
    $prNumber = 'PR-' . $year . '-' . $numericSequence;
    $clientPoNumber = 'E2E-CPO-' . $runId;
    $clientName = 'E2E Acceptance Client ' . $runId;
    $baseUrl = (string) $marker['application_url'];
    $proofPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixie_phase5c_' . strtolower($runId) . '.pdf';
    phase5cCreatePdf($proofPath, $runId);
    $proof = ['path' => $proofPath, 'mime' => 'application/pdf'];
    $today = date('Y-m-d');

    phase5cPost($baseUrl, 'actions/quotation_handler.php', $sessions['Sales Staff'], [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'create_detailed_quotation',
        'quotation_number' => $quotationNumber,
        'client_name' => $clientName,
        'amount' => '150000.00',
        'items[0][category]' => 'Computers',
        'items[0][brand]' => 'Fixie E2E',
        'items[0][name]' => 'Business Laptop',
        'items[0][specs]' => '16 GB RAM / 512 GB SSD',
        'items[0][qty]' => '10',
        'items[0][price]' => '12000.00',
        'items[0][total]' => '120000.00',
        'items[1][category]' => 'Accessories',
        'items[1][brand]' => 'Fixie E2E',
        'items[1][name]' => 'USB-C Dock',
        'items[1][specs]' => 'Dual-display business dock',
        'items[1][qty]' => '10',
        'items[1][price]' => '3000.00',
        'items[1][total]' => '30000.00',
    ]);
    $quotation = phase5cRow(
        $connection,
        'SELECT * FROM quotations WHERE quotation_number = ' . phase5cEscape($connection, $quotationNumber) . ' LIMIT 1'
    );
    phase5cAssert($quotation && $quotation['status'] === 'Pending Approval', 'Quotation creation reached Pending Approval.');
    $quotationId = (int) $quotation['quotation_id'];
    $run['quotation_id'] = $quotationId;
    phase5cMoney((float) $quotation['amount'], 150000.00, 'Quotation header total');
    phase5cMoney(
        (float) phase5cRow($connection, 'SELECT COALESCE(SUM(total_price),0) AS total FROM quotation_items WHERE quotation_id = ' . $quotationId)['total'],
        150000.00,
        'Quotation item total'
    );

    $proof['name'] = 'official-client-po-' . $runId . '.pdf';
    phase5cPost($baseUrl, 'actions/quotation_handler.php', $sessions['Sales Staff'], [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'receive_po',
        'quotation_id' => (string) $quotationId,
        'approval_mode' => 'Official Client PO',
        'actual_client_po_number' => $clientPoNumber,
        'client_po_date' => $today,
        'final_approval_date' => $today,
        'remarks' => 'Phase 5C signed client PO evidence.',
    ], ['po_file' => $proof]);
    $quotation = phase5cRow($connection, 'SELECT * FROM quotations WHERE quotation_id = ' . $quotationId);
    phase5cAssert($quotation['status'] === 'For GM Acknowledgement', 'Official Client PO submission reached GM acknowledgement.');
    $clientApproval = phase5cRow(
        $connection,
        "SELECT * FROM client_approval_records WHERE quotation_id = {$quotationId} AND record_type = 'Official Client PO' ORDER BY approval_record_id DESC LIMIT 1"
    );
    phase5cAssert(
        $clientApproval && preg_match('/^[a-f0-9]{64}$/', (string) $clientApproval['proof_file_hash']) === 1,
        'Official Client PO proof was stored with a SHA-256 hash.'
    );
    $approvalRecordId = (int) $clientApproval['approval_record_id'];

    phase5cPost($baseUrl, 'actions/client_po_acknowledgement_handler.php', $sessions['GM'], [
        'csrf_token' => $sessions['GM']['csrf'],
        'quotation_id' => (string) $quotationId,
        'approval_record_id' => (string) $approvalRecordId,
        'decision' => 'Acknowledged',
        'remarks' => 'Phase 5C GM acknowledgement.',
        'e_signature_confirmed' => '1',
    ]);
    $quotation = phase5cRow($connection, 'SELECT * FROM quotations WHERE quotation_id = ' . $quotationId);
    phase5cAssert($quotation['status'] === 'PO Received', 'GM electronic acknowledgement made the Client PO eligible for PRF creation.');
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM document_signature_events WHERE record_module = 'Client PO' AND record_id = {$approvalRecordId} AND signature_stage = 'GM Acknowledgement' AND signature_status = 'Valid'") === 1,
        'GM Client PO signature event is valid and unique.'
    );

    $quotationItems = $connection->query('SELECT item_id, item_name FROM quotation_items WHERE quotation_id = ' . $quotationId . ' ORDER BY item_id');
    $itemCosts = [];
    while ($item = $quotationItems->fetch_assoc()) {
        $itemCosts[(int) $item['item_id']] = $item['item_name'] === 'Business Laptop' ? '9000.00' : '2000.00';
    }
    $quotationItems->free();
    $prFields = [
        'csrf_token' => $sessions['Sales Staff']['csrf'],
        'action' => 'create_pr',
        'quotation_id' => (string) $quotationId,
        'pr_number' => $prNumber,
        'supplier_name' => 'E2E Verified Supplier',
        'supplier_reference' => 'SUP-' . $runId,
        'supplier_quote_date' => $today,
        'payment_method' => 'Bank Transfer',
        'payment_terms' => 'Pay upon verified release',
        'bank_name' => 'E2E Test Bank',
        'bank_account_name' => 'E2E Verified Supplier',
        'bank_account_number' => '000-' . substr($runId, -8),
        'check_payee' => '',
        'other_expense_amount' => '5000.00',
        'supplier_remarks' => 'Isolated Phase 5C supplier snapshot.',
    ];
    foreach ($itemCosts as $itemId => $cost) {
        $prFields['item_costs[' . $itemId . ']'] = $cost;
    }
    $proof['name'] = 'supplier-quotation-' . $runId . '.pdf';
    phase5cPost(
        $baseUrl,
        'actions/pr_handler.php',
        $sessions['Sales Staff'],
        $prFields,
        ['supplier_quote_file' => $proof]
    );
    $pr = phase5cRow($connection, 'SELECT * FROM purchase_requests WHERE pr_number = ' . phase5cEscape($connection, $prNumber) . ' LIMIT 1');
    phase5cAssert($pr && $pr['status'] === 'Pending' && $pr['current_approval_stage'] === 'GM Review', 'PRF creation started the sequential GM review.');
    $prId = (int) $pr['pr_id'];
    $run['pr_id'] = $prId;
    phase5cMoney((float) $pr['amount'], 150000.00, 'PRF selling amount');
    phase5cMoney((float) $pr['cost_of_goods_amount'], 110000.00, 'PRF cost of goods');
    phase5cMoney((float) $pr['other_expense_amount'], 5000.00, 'PRF other expense');
    phase5cMoney((float) $pr['requested_fund_amount'], 115000.00, 'PRF requested fund');
    phase5cMoney((float) $pr['gross_profit_amount'], 35000.00, 'PRF gross profit');
    phase5cAssert(abs((float) $pr['gross_margin_percent'] - 23.3333) <= 0.001, 'PRF gross margin equals 23.3333%.');

    foreach ([
        ['role' => 'GM', 'next' => 'Finance Review'],
        ['role' => 'Finance', 'next' => 'Owner Approval'],
        ['role' => 'President', 'next' => 'Official Approved'],
    ] as $approvalStep) {
        $role = $approvalStep['role'];
        phase5cPost($baseUrl, 'actions/pr_handler.php', $sessions[$role], [
            'csrf_token' => $sessions[$role]['csrf'],
            'action' => 'approve_pr_stage',
            'pr_id' => (string) $prId,
            'remarks' => 'Phase 5C ' . $role . ' approval.',
            'e_signature_confirmed' => '1',
        ]);
        $pr = phase5cRow($connection, 'SELECT * FROM purchase_requests WHERE pr_id = ' . $prId);
        phase5cAssert(
            (string) $pr['current_approval_stage'] === $approvalStep['next'],
            $role . ' approval advanced the PRF to ' . $approvalStep['next'] . '.'
        );
    }
    phase5cAssert($pr['status'] === 'Approved', 'Owner approval finalized the PRF as Approved.');
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM document_signature_events WHERE record_module = 'PRF' AND record_id = {$prId} AND signature_status = 'Valid'") === 3,
        'PRF contains exactly three valid sequential signature events.'
    );

    phase5cPost($baseUrl, 'actions/po_handler.php', $sessions['Procurement'], [
        'csrf_token' => $sessions['Procurement']['csrf'],
        'action' => 'create',
        'pr_id' => (string) $prId,
    ]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE pr_id = ' . $prId . ' LIMIT 1');
    phase5cAssert($po && $po['status'] === 'President-Approved', 'Approved PRF conversion created an authorized PO ready for Finance funding.');
    $poId = (int) $po['po_id'];
    $run['po_id'] = $poId;
    $run['po_number'] = (string) $po['po_number'];
    phase5cMoney((float) $po['amount'], 150000.00, 'PO selling amount');
    phase5cMoney((float) $po['requested_fund_amount'], 115000.00, 'PO requested fund snapshot');
    phase5cMoney((float) $po['gross_profit_amount'], 35000.00, 'PO gross profit snapshot');
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM documents WHERE source_module = 'Internal Purchase Order' AND source_record_id = {$poId} AND record_phase = 'Official'") === 1,
        'The authorized PO was filed once as an Official Record.'
    );

    $proof['name'] = 'fund-release-' . $runId . '.pdf';
    phase5cPost($baseUrl, 'actions/po_handler.php', $sessions['Finance'], [
        'csrf_token' => $sessions['Finance']['csrf'],
        'action' => 'mark_funded',
        'po_id' => (string) $poId,
        'released_amount' => '115000.00',
        'release_method' => 'Bank Transfer',
        'reference_number' => 'FUND-' . $runId,
        'released_at' => date('Y-m-d\TH:i'),
        'funding_remarks' => 'Phase 5C verified supplier funding.',
    ], ['funding_proof' => $proof]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'Funded', 'Finance funding advanced the PO to Funded.');
    phase5cMoney(
        (float) phase5cRow($connection, "SELECT released_amount FROM po_supplier_fund_releases WHERE po_id = {$poId} AND record_status = 'Active' LIMIT 1")['released_amount'],
        115000.00,
        'Verified supplier fund release'
    );

    $readyAt = date('Y-m-d\TH:i');
    $preferredPickup = (new DateTimeImmutable('now'))->modify('+10 minutes')->format('Y-m-d\TH:i');
    $preferredDelivery = (new DateTimeImmutable('now'))->modify('+20 minutes')->format('Y-m-d\TH:i');
    phase5cPost($baseUrl, 'actions/delivery_request_handler.php', $sessions['Procurement'], [
        'csrf_token' => $sessions['Procurement']['csrf'],
        'action' => 'submit_delivery_request',
        'po_id' => (string) $poId,
        'request_type' => 'Pick-up and Delivery',
        'supplier_ready_confirmed_at' => $readyAt,
        'supplier_confirmation_reference' => 'READY-' . $runId,
        'supplier_contact_name' => 'E2E Supplier Contact',
        'supplier_contact_number' => '09170000000',
        'supplier_contact_email' => 'supplier-e2e@example.test',
        'pickup_address' => 'E2E Supplier Warehouse, Test City',
        'delivery_address' => 'E2E Client Office, Test City',
        'preferred_pickup_at' => $preferredPickup,
        'preferred_delivery_at' => $preferredDelivery,
        'package_count' => '20',
        'handling_instructions' => 'Keep all cartons dry and upright.',
        'procurement_remarks' => 'Phase 5C delivery request.',
        'delivery_confirmation' => '1',
    ]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'Delivery Requested', 'Procurement submitted the funded PO to Supply Chain.');
    $deliveryRequest = phase5cRow($connection, "SELECT * FROM po_delivery_requests WHERE po_id = {$poId} AND record_status = 'Active' ORDER BY request_cycle DESC LIMIT 1");
    phase5cAssert($deliveryRequest && $deliveryRequest['request_status'] === 'Submitted', 'Delivery Request record is Submitted.');

    phase5cPost($baseUrl, 'actions/delivery_request_handler.php', $sessions['Supply Chain'], [
        'csrf_token' => $sessions['Supply Chain']['csrf'],
        'action' => 'approve_delivery_schedule',
        'po_id' => (string) $poId,
        'provider_type' => 'Company Fleet',
        'provider_name' => 'Fixie Computer Ventures',
        'planned_pickup_at' => $preferredPickup,
        'planned_delivery_at' => $preferredDelivery,
        'driver_name' => 'E2E Test Driver',
        'driver_contact_number' => '09171111111',
        'vehicle_type' => 'Delivery Van',
        'vehicle_plate_number' => 'E2E-5C',
        'tracking_reference' => 'TRK-' . $runId,
        'route_or_plot_notes' => 'Isolated acceptance route.',
        'logistics_confirmation' => '1',
        'e_signature_confirmed' => '1',
    ]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'For Pick-up/Delivery', 'Supply Chain signature approved the final logistics schedule.');
    $deliveryPlan = phase5cRow($connection, 'SELECT * FROM po_delivery_plans WHERE delivery_request_id = ' . (int) $deliveryRequest['delivery_request_id'] . " AND record_status = 'Active' LIMIT 1");
    phase5cAssert($deliveryPlan['logistics_status'] === 'Scheduled', 'Logistics plan is Scheduled.');

    // HTML datetime-local inputs have minute precision. Wait until the minute
    // after logistics review so the recorded handover is both non-future and
    // strictly later than reviewed_at.
    $reviewedAt = new DateTimeImmutable((string) $deliveryPlan['reviewed_at']);
    $handoverMinute = $reviewedAt->modify('+1 minute')->setTime(
        (int) $reviewedAt->modify('+1 minute')->format('H'),
        (int) $reviewedAt->modify('+1 minute')->format('i'),
        0
    );
    phase5cWaitUntil($handoverMinute, 'Waiting for a valid post-review handover minute');
    $proof['name'] = 'delivery-receipt-' . $runId . '.pdf';
    phase5cPost($baseUrl, 'actions/delivery_completion_handler.php', $sessions['Supply Chain'], [
        'csrf_token' => $sessions['Supply Chain']['csrf'],
        'action' => 'complete_client_delivery',
        'po_id' => (string) $poId,
        'actual_handover_at' => $handoverMinute->format('Y-m-d\TH:i'),
        'acknowledgement_type' => 'Signed Delivery Receipt',
        'client_receipt_reference' => 'DR-' . $runId,
        'recipient_name' => 'E2E Client Representative',
        'recipient_position' => 'IT Coordinator',
        'recipient_contact' => '09172222222',
        'delivered_item_quantity' => '20',
        'delivery_condition' => 'Complete and Accepted',
        'discrepancy_notes' => '',
        'delivery_completion_confirmation' => '1',
        'e_signature_confirmed' => '1',
    ], ['delivery_receipt_proof' => $proof]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'Delivered', 'Verified client receipt completed the operational PO as Delivered.');
    $expectedDue = $handoverMinute->modify('+15 days')->format('Y-m-d');
    phase5cAssert((string) $po['expected_collection_date'] === $expectedDue, 'Collection due date is exactly 15 calendar days after handover.');
    phase5cAssert((string) $po['collection_status'] === 'Unpaid', 'Operational completion leaves the separate collection status Unpaid.');

    // Wait for the next minute so the Finance payment timestamp is strictly
    // after the minute-precision handover and is correctly classified as a
    // post-delivery partial/full payment.
    $paymentMinute = $handoverMinute->modify('+1 minute');
    phase5cWaitUntil($paymentMinute, 'Waiting for a valid post-delivery collection minute');
    $paymentDate = $paymentMinute->format('Y-m-d\TH:i');

    $proof['name'] = 'partial-payment-' . $runId . '.pdf';
    phase5cPost($baseUrl, 'actions/collection_payment_handler.php', $sessions['Finance'], [
        'csrf_token' => $sessions['Finance']['csrf'],
        'action' => 'record_collection_payment',
        'return_to' => 'view_po',
        'po_id' => (string) $poId,
        'amount_paid' => '60000.00',
        'payment_method' => 'Bank Transfer',
        'reference_number' => 'PART-' . $runId,
        'payment_date' => $paymentDate,
        'payment_classification' => 'Partial Payment',
        'payment_remarks' => 'Phase 5C partial client payment.',
        'payment_confirmation' => '1',
        'e_signature_confirmed' => '1',
    ], ['payment_proof' => $proof]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'Delivered' && $po['collection_status'] === 'Partially Paid', 'Partial payment changed only collection status to Partially Paid.');
    phase5cMoney(
        (float) phase5cRow($connection, 'SELECT COALESCE(SUM(amount_paid),0) AS total FROM payments WHERE po_id = ' . $poId)['total'],
        60000.00,
        'Collected amount after partial payment'
    );

    $proof['name'] = 'full-payment-' . $runId . '.pdf';
    phase5cPost($baseUrl, 'actions/collection_payment_handler.php', $sessions['Finance'], [
        'csrf_token' => $sessions['Finance']['csrf'],
        'action' => 'record_collection_payment',
        'return_to' => 'view_po',
        'po_id' => (string) $poId,
        'amount_paid' => '90000.00',
        'payment_method' => 'Bank Transfer',
        'reference_number' => 'FULL-' . $runId,
        'payment_date' => $paymentDate,
        'payment_classification' => 'Full Payment',
        'payment_remarks' => 'Phase 5C final client payment.',
        'payment_confirmation' => '1',
        'e_signature_confirmed' => '1',
    ], ['payment_proof' => $proof]);
    $po = phase5cRow($connection, 'SELECT * FROM purchase_orders WHERE po_id = ' . $poId);
    phase5cAssert($po['status'] === 'Delivered' && $po['collection_status'] === 'Paid', 'Final payment completed collection without changing Delivered status.');
    phase5cMoney(
        (float) phase5cRow($connection, 'SELECT COALESCE(SUM(amount_paid),0) AS total FROM payments WHERE po_id = ' . $poId)['total'],
        150000.00,
        'Final collected amount'
    );
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM payments WHERE po_id = {$poId} AND payment_classification IN ('Partial Payment','Full Payment')") === 2,
        'Payment ledger contains one partial and one full payment entry.'
    );
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM document_signature_events WHERE record_module = 'Client Payment Confirmation' AND signature_status = 'Valid' AND record_id IN (SELECT payment_id FROM payments WHERE po_id = {$poId})") === 2,
        'Both client payments have valid Finance signature events.'
    );
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM documents WHERE record_phase = 'Official' AND ((source_module = 'Client PO Approval' AND source_record_id = {$approvalRecordId}) OR (source_module = 'Purchase Requisition Form' AND source_record_id = {$prId}) OR (source_module = 'Internal Purchase Order' AND source_record_id = {$poId}) OR (source_module = 'Supplier Fund Release' AND source_record_id IN (SELECT fund_release_id FROM po_supplier_fund_releases WHERE po_id = {$poId})) OR (source_module = 'Delivery Request' AND source_record_id = " . (int) $deliveryRequest['delivery_request_id'] . ") OR (source_module = 'Logistics Plan' AND source_record_id = " . (int) $deliveryPlan['delivery_plan_id'] . ") OR (source_module = 'Delivery Receipt' AND source_record_id IN (SELECT delivery_receipt_id FROM po_delivery_receipts WHERE po_id = {$poId})) OR (source_module = 'Client Payment' AND source_record_id IN (SELECT payment_id FROM payments WHERE po_id = {$poId})))") >= 9,
        'The workflow produced linked Official Records for its signed and verified evidence.'
    );
    phase5cAssert(
        phase5cCount($connection, "SELECT COUNT(*) FROM audit_logs WHERE user_id IN (" . implode(',', array_map(static fn (array $actor): int => (int) $actor['user_id'], $actors)) . ") AND timestamp >= (SELECT date_created FROM purchase_orders WHERE po_id = {$poId}) AND action_type IN ('CREATE_QUOTATION','RECEIVE_OFFICIAL_CLIENT_PO','ACKNOWLEDGE_CLIENT_PO','CREATE_PR','APPROVE_PR_STAGE','FINAL_APPROVE_PR','CREATE_PO','RELEASE_SUPPLIER_FUNDING','SUBMIT_DELIVERY_REQUEST','APPROVE_DELIVERY_SCHEDULE','COMPLETE_CLIENT_DELIVERY','RECORD_COLLECTION_PAYMENT')") >= 10,
        'Material happy-path actions were captured in the audit log.'
    );

    echo "\nPhase 5C happy-path verification PASSED.\n\n";
    echo '- Run ID: ' . $runId . "\n";
    echo '- Quotation: ' . $quotationNumber . ' (ID ' . $quotationId . ")\n";
    echo '- PRF: ' . $prNumber . ' (ID ' . $prId . ")\n";
    echo '- PO: ' . $run['po_number'] . ' (ID ' . $poId . ")\n";
    echo "- Selling amount: 150,000.00\n";
    echo "- Approved fund: 115,000.00\n";
    echo "- Gross profit: 35,000.00\n";
    echo "- Final collection: Paid\n";
    echo "- Operational status: Delivered\n";
    echo '- Due date: ' . $expectedDue . "\n";
    echo "\nThe acceptance records remain in fixie_drms_e2e for inspection. No main-system record was changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "\nPhase 5C happy-path verification FAILED:\n\n- " . $error->getMessage() . "\n");
    if ($run) {
        fwrite(STDERR, '- Partial E2E identifiers: ' . json_encode($run, JSON_UNESCAPED_SLASHES) . "\n");
    }
    $phase5cExit = 1;
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
        foreach ($temporaryProfiles as $profileId) {
            $connection->query('DELETE FROM user_signature_profiles WHERE signature_profile_id = ' . (int) $profileId);
        }
        $connection->close();
    }
    if ($proofPath !== null && is_file($proofPath)) {
        @unlink($proofPath);
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

exit($phase5cExit ?? 0);
