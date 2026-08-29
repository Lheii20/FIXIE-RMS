<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/client_po_acknowledgement.php';
require_once '../config/workflow_feedback.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

function phase2_prf_redirect_error(int $quotation_id, string $message): void
{
    $location = '../create_pr.php?quotation_id=' . $quotation_id;
    $public_message = drms_public_feedback_message(
        $message,
        'The PRF could not be saved. No records were changed. Please try again.'
    );
    drms_redirect_with_feedback($location, 'error', $public_message);
}

function phase2_prf_money($value, string $label): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], '', trim($value));
    }

    if ($value === '' || $value === null || !is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a valid amount.');
    }

    $amount = round((float) $value, 2);

    if (!is_finite($amount)) {
        throw new InvalidArgumentException($label . ' must be a valid amount.');
    }

    return $amount;
}

function phase2_prf_text($value, string $label, int $maximum_length, bool $required = false): string
{
    $text = trim((string) $value);

    if ($required && $text === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }

    if (strlen($text) > $maximum_length) {
        throw new InvalidArgumentException(
            $label . ' must not exceed ' . $maximum_length . ' characters.'
        );
    }

    return $text;
}

function phase2_prf_optional_date($value, string $label): ?string
{
    $date_value = trim((string) $value);

    if ($date_value === '') {
        return null;
    }

    $parsed_date = DateTime::createFromFormat('Y-m-d', $date_value);
    $date_errors = DateTime::getLastErrors();
    $has_date_errors = is_array($date_errors) &&
        ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0);

    if (!$parsed_date || $has_date_errors || $parsed_date->format('Y-m-d') !== $date_value) {
        throw new InvalidArgumentException($label . ' is invalid.');
    }

    if ($date_value > date('Y-m-d')) {
        throw new InvalidArgumentException($label . ' cannot be a future date.');
    }

    return $date_value;
}

function phase2_prf_upload_supplier_quote(?array $file): ?array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The supplier quotation could not be uploaded.');
    }

    $file_size = (int) ($file['size'] ?? 0);
    if ($file_size < 1 || $file_size > 10 * 1024 * 1024) {
        throw new RuntimeException('The supplier quotation must not exceed 10 MB.');
    }

    $temporary_path = (string) ($file['tmp_name'] ?? '');
    if ($temporary_path === '' || !is_uploaded_file($temporary_path)) {
        throw new RuntimeException('The supplier quotation upload is invalid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $temporary_path) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed_mime_types = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!$mime_type || !isset($allowed_mime_types[$mime_type])) {
        throw new RuntimeException('Supplier quotation must be a PDF, JPG, or PNG file.');
    }

    $upload_directory = dirname(__DIR__) . DIRECTORY_SEPARATOR .
        'uploads' . DIRECTORY_SEPARATOR . 'supplier_quotes';

    if (!is_dir($upload_directory) &&
        !mkdir($upload_directory, 0755, true) &&
        !is_dir($upload_directory)) {
        throw new RuntimeException('The supplier quotation directory is unavailable.');
    }

    $stored_name = date('YmdHis') . '_supplier_quote_' .
        bin2hex(random_bytes(12)) . '.' . $allowed_mime_types[$mime_type];
    $absolute_path = $upload_directory . DIRECTORY_SEPARATOR . $stored_name;

    if (!move_uploaded_file($temporary_path, $absolute_path)) {
        throw new RuntimeException('The supplier quotation could not be saved.');
    }

    $file_hash = hash_file('sha256', $absolute_path);
    if (!$file_hash) {
        @unlink($absolute_path);
        throw new RuntimeException('The supplier quotation hash could not be generated.');
    }

    return [
        'original_name' => substr(basename((string) ($file['name'] ?? 'supplier-quote')), 0, 255),
        'stored_name' => $stored_name,
        'absolute_path' => $absolute_path,
        'hash' => $file_hash,
    ];
}

function phase2_prf_redirect_to_view(int $pr_id, string $type, string $message): void
{
    $safe_type = $type === 'success' ? 'success' : 'error';
    $public_message = $safe_type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The PRF action could not be completed. No workflow changes were saved.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback(
        '../view_pr.php?id=' . $pr_id,
        $safe_type,
        $public_message
    );
}

function phase2_prf_notify_role(mysqli $conn, string $target_role, string $message): int
{
    $notification_stmt = $conn->prepare(
        "INSERT INTO notifications (target_role, message)
         VALUES (?, ?)"
    );
    $notification_stmt->bind_param('ss', $target_role, $message);
    $notification_stmt->execute();
    $notification_id = (int) $notification_stmt->insert_id;

    if ($notification_id < 1) {
        throw new RuntimeException('The approval notification could not be created.');
    }

    $state_stmt = $conn->prepare(
        "INSERT IGNORE INTO notification_user_states (
            notif_id,
            user_id,
            is_read,
            is_pinned,
            is_deleted
         )
         SELECT ?, user_id, 0, 0, 0
         FROM users
         WHERE role = ?
           AND status = 'Active'"
    );
    $state_stmt->bind_param('is', $notification_id, $target_role);
    $state_stmt->execute();

    return $notification_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $session_token = (string) ($_SESSION['csrf_token'] ?? '');
    $posted_token = (string) ($_POST['csrf_token'] ?? '');
    if (
        $session_token === '' ||
        $posted_token === '' ||
        !hash_equals($session_token, $posted_token)
    ) {
        drms_redirect_with_feedback(
            '../pr_list.php',
            'error',
            'Security token validation failed. Refresh the page and try again.'
        );
    }

    $action = (string) $_POST['action'];

    /*
     * Phase 2D sequential approval engine.
     * Each decision is locked to the current database stage and its required role.
     */
    if (in_array($action, ['approve_pr_stage', 'reject_pr_stage'], true)) {
        $pr_id = (int) ($_POST['pr_id'] ?? 0);
        $actor_id = (int) $_SESSION['user_id'];
        $actor_role = (string) ($_SESSION['role'] ?? '');
        $is_approval = $action === 'approve_pr_stage';
        $transaction_started = false;

        if ($pr_id < 1) {
            header('Location: ../pr_list.php?error=' . rawurlencode('Invalid Purchase Request.'));
            exit();
        }

        try {
            $decision_remarks = phase2_prf_text(
                $_POST['remarks'] ?? '',
                $is_approval ? 'Approval note' : 'Rejection reason',
                2000,
                !$is_approval
            );

            if (!$conn->begin_transaction()) {
                throw new RuntimeException('The approval transaction could not be started.');
            }
            $transaction_started = true;

            $pr_stmt = $conn->prepare(
                "SELECT
                    pr_id,
                    pr_number,
                    status,
                    current_approval_stage,
                    created_by
                 FROM purchase_requests
                 WHERE pr_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $pr_stmt->bind_param('i', $pr_id);
            $pr_stmt->execute();
            $pr = $pr_stmt->get_result()->fetch_assoc();

            if (!$pr) {
                throw new RuntimeException('The Purchase Request no longer exists.');
            }

            if ($pr['status'] !== 'Pending') {
                throw new RuntimeException('This PRF is already processed.');
            }

            $cycle_stmt = $conn->prepare(
                "SELECT MAX(approval_cycle) AS approval_cycle
                 FROM pr_approval_records
                 WHERE pr_id = ?"
            );
            $cycle_stmt->bind_param('i', $pr_id);
            $cycle_stmt->execute();
            $cycle_row = $cycle_stmt->get_result()->fetch_assoc();
            $approval_cycle = (int) ($cycle_row['approval_cycle'] ?? 0);

            if ($approval_cycle < 1) {
                throw new RuntimeException('The PRF approval route is incomplete.');
            }

            $stage_stmt = $conn->prepare(
                "SELECT
                    pr_approval_record_id,
                    stage_sequence,
                    approval_stage,
                    required_role,
                    decision
                 FROM pr_approval_records
                 WHERE pr_id = ?
                   AND approval_cycle = ?
                   AND approval_stage = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stage_stmt->bind_param(
                'iis',
                $pr_id,
                $approval_cycle,
                $pr['current_approval_stage']
            );
            $stage_stmt->execute();
            $current_stage = $stage_stmt->get_result()->fetch_assoc();

            if (!$current_stage || $current_stage['decision'] !== 'Pending') {
                throw new RuntimeException(
                    'The current approval stage is unavailable or was already processed.'
                );
            }

            if ($actor_role !== $current_stage['required_role']) {
                throw new RuntimeException(
                    'This PRF is waiting for ' . $current_stage['required_role'] . ' review.'
                );
            }

            $decision = $is_approval ? 'Approved' : 'Rejected';
            $approval_record_id = (int) $current_stage['pr_approval_record_id'];
            $decision_stmt = $conn->prepare(
                "UPDATE pr_approval_records
                 SET decision = ?,
                     decision_remarks = NULLIF(?, ''),
                     acted_by = ?,
                     acted_at = NOW()
                 WHERE pr_approval_record_id = ?
                   AND decision = 'Pending'"
            );
            $decision_stmt->bind_param(
                'ssii',
                $decision,
                $decision_remarks,
                $actor_id,
                $approval_record_id
            );
            $decision_stmt->execute();

            if ($decision_stmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'The approval stage changed before your decision was saved.'
                );
            }

            $pr_number = (string) $pr['pr_number'];
            $approval_stage = (string) $current_stage['approval_stage'];
            $stage_sequence = (int) $current_stage['stage_sequence'];

            if (!$is_approval) {
                $closed_reason = 'Closed after rejection at ' . $approval_stage . '.';
                $close_future_stmt = $conn->prepare(
                    "UPDATE pr_approval_records
                     SET decision = 'Returned',
                         decision_remarks = ?
                     WHERE pr_id = ?
                       AND approval_cycle = ?
                       AND stage_sequence > ?
                       AND decision = 'Pending'"
                );
                $close_future_stmt->bind_param(
                    'siii',
                    $closed_reason,
                    $pr_id,
                    $approval_cycle,
                    $stage_sequence
                );
                $close_future_stmt->execute();

                $reject_pr_stmt = $conn->prepare(
                    "UPDATE purchase_requests
                     SET status = 'Rejected',
                         current_approval_stage = 'Rejected',
                         remarks = ?
                     WHERE pr_id = ?
                       AND status = 'Pending'"
                );
                $reject_pr_stmt->bind_param('si', $decision_remarks, $pr_id);
                $reject_pr_stmt->execute();

                if ($reject_pr_stmt->affected_rows !== 1) {
                    throw new RuntimeException('The PRF status could not be updated.');
                }

                phase2_prf_notify_role(
                    $conn,
                    'Sales Staff',
                    "PRF $pr_number was rejected during $approval_stage. Reason: $decision_remarks"
                );

                log_audit_action(
                    $conn,
                    $actor_id,
                    'REJECT_PR_STAGE',
                    "Rejected PRF $pr_number during $approval_stage",
                    [
                        'status' => 'Pending',
                        'current_approval_stage' => $approval_stage,
                        'decision' => 'Pending',
                    ],
                    [
                        'status' => 'Rejected',
                        'current_approval_stage' => 'Rejected',
                        'decision' => 'Rejected',
                        'remarks' => $decision_remarks,
                    ]
                );

                if (!$conn->commit()) {
                    throw new RuntimeException('The rejection could not be committed.');
                }
                $transaction_started = false;

                phase2_prf_redirect_to_view(
                    $pr_id,
                    'success',
                    "PRF rejected during $approval_stage."
                );
            }

            $next_stage_stmt = $conn->prepare(
                "SELECT
                    approval_stage,
                    required_role,
                    stage_sequence
                 FROM pr_approval_records
                 WHERE pr_id = ?
                   AND approval_cycle = ?
                   AND stage_sequence > ?
                   AND decision = 'Pending'
                 ORDER BY stage_sequence ASC
                 LIMIT 1"
            );
            $next_stage_stmt->bind_param(
                'iii',
                $pr_id,
                $approval_cycle,
                $stage_sequence
            );
            $next_stage_stmt->execute();
            $next_stage = $next_stage_stmt->get_result()->fetch_assoc();

            if ($next_stage) {
                $next_stage_name = (string) $next_stage['approval_stage'];
                $next_role = (string) $next_stage['required_role'];

                $advance_stmt = $conn->prepare(
                    "UPDATE purchase_requests
                     SET current_approval_stage = ?
                     WHERE pr_id = ?
                       AND status = 'Pending'
                       AND current_approval_stage = ?"
                );
                $advance_stmt->bind_param(
                    'sis',
                    $next_stage_name,
                    $pr_id,
                    $approval_stage
                );
                $advance_stmt->execute();

                if ($advance_stmt->affected_rows !== 1) {
                    throw new RuntimeException('The PRF could not advance to its next stage.');
                }

                phase2_prf_notify_role(
                    $conn,
                    $next_role,
                    "PRF $pr_number requires your $next_stage_name."
                );
                phase2_prf_notify_role(
                    $conn,
                    'Sales Staff',
                    "PRF $pr_number passed $approval_stage and moved to $next_stage_name."
                );

                log_audit_action(
                    $conn,
                    $actor_id,
                    'APPROVE_PR_STAGE',
                    "Approved $approval_stage for PRF $pr_number",
                    [
                        'status' => 'Pending',
                        'current_approval_stage' => $approval_stage,
                        'decision' => 'Pending',
                    ],
                    [
                        'status' => 'Pending',
                        'current_approval_stage' => $next_stage_name,
                        'decision' => 'Approved',
                    ]
                );

                if (!$conn->commit()) {
                    throw new RuntimeException('The approval could not be committed.');
                }
                $transaction_started = false;

                phase2_prf_redirect_to_view(
                    $pr_id,
                    'success',
                    "$approval_stage approved. PRF forwarded to $next_role."
                );
            }

            $final_stmt = $conn->prepare(
                "UPDATE purchase_requests
                 SET status = 'Approved',
                     current_approval_stage = 'Official Approved',
                     final_approved_by = ?,
                     final_approved_at = NOW()
                 WHERE pr_id = ?
                   AND status = 'Pending'
                   AND current_approval_stage = ?"
            );
            $final_stmt->bind_param('iis', $actor_id, $pr_id, $approval_stage);
            $final_stmt->execute();

            if ($final_stmt->affected_rows !== 1) {
                throw new RuntimeException('The final PRF approval could not be saved.');
            }

            phase2_prf_notify_role(
                $conn,
                'Procurement',
                "PRF $pr_number is officially approved and ready for PO conversion."
            );
            phase2_prf_notify_role(
                $conn,
                'Sales Staff',
                "PRF $pr_number received final Owner approval."
            );

            log_audit_action(
                $conn,
                $actor_id,
                'FINAL_APPROVE_PR',
                "Officially approved PRF $pr_number during $approval_stage",
                [
                    'status' => 'Pending',
                    'current_approval_stage' => $approval_stage,
                    'decision' => 'Pending',
                ],
                [
                    'status' => 'Approved',
                    'current_approval_stage' => 'Official Approved',
                    'decision' => 'Approved',
                    'final_approved_by' => $actor_id,
                ]
            );

            if (!$conn->commit()) {
                throw new RuntimeException('The final approval could not be committed.');
            }
            $transaction_started = false;

            phase2_prf_redirect_to_view(
                $pr_id,
                'success',
                'PRF is officially approved and ready for PO conversion.'
            );
        } catch (Throwable $exception) {
            if ($transaction_started) {
                $conn->rollback();
            }

            drms_log_workflow_failure(
                'PRF approval decision for PRF ' . $pr_id,
                $exception
            );
            $safe_error_message = $exception->getMessage();

            phase2_prf_redirect_to_view($pr_id, 'error', $safe_error_message);
        }
    }

    /*
     * Official PRF creation route.
     */
    if ($action === 'create_pr') {
        if ($_SESSION['role'] !== 'Sales Staff') {
            header('Location: ../quotations_list.php?error=' . rawurlencode(
                'Only Sales Staff can create a Purchase Request.'
            ));
            exit();
        }

        $quotation_id = (int) ($_POST['quotation_id'] ?? 0);
        $created_by = (int) $_SESSION['user_id'];
        $transaction_started = false;
        $uploaded_supplier_quote = null;

        try {
            if ($quotation_id < 1) {
                throw new InvalidArgumentException(
                    'You must select a quotation with an official signed Client PO.'
                );
            }

            $pr_number = phase2_prf_text(
                $_POST['pr_number'] ?? '',
                'PR number',
                50,
                true
            );

            if (!preg_match('/^PR-[0-9]{4,6}-[0-9]{4,}$/', $pr_number)) {
                throw new InvalidArgumentException('The PR number format is invalid.');
            }

            $supplier_name = phase2_prf_text(
                $_POST['supplier_name'] ?? '',
                'Supplier name',
                150,
                true
            );
            $supplier_reference = phase2_prf_text(
                $_POST['supplier_reference'] ?? '',
                'Supplier reference',
                100
            );
            $supplier_quote_date = phase2_prf_optional_date(
                $_POST['supplier_quote_date'] ?? '',
                'Supplier quotation date'
            );
            $payment_method = phase2_prf_text(
                $_POST['payment_method'] ?? '',
                'Payment method',
                30,
                true
            );
            $payment_terms = phase2_prf_text(
                $_POST['payment_terms'] ?? '',
                'Supplier payment terms',
                150
            );
            $bank_name = phase2_prf_text(
                $_POST['bank_name'] ?? '',
                'Bank name',
                150
            );
            $bank_account_name = phase2_prf_text(
                $_POST['bank_account_name'] ?? '',
                'Bank account name',
                150
            );
            $bank_account_number = phase2_prf_text(
                $_POST['bank_account_number'] ?? '',
                'Bank account number',
                100
            );
            $check_payee = phase2_prf_text(
                $_POST['check_payee'] ?? '',
                'Check payee',
                150
            );
            $supplier_remarks = phase2_prf_text(
                $_POST['supplier_remarks'] ?? '',
                'Supplier remarks',
                2000
            );

            $allowed_payment_methods = [
                'Cash',
                'Bank Transfer',
                'Check',
                'Cash on Delivery',
                'Other',
            ];

            if (!in_array($payment_method, $allowed_payment_methods, true)) {
                throw new InvalidArgumentException('The selected payment method is invalid.');
            }

            if ($payment_method === 'Bank Transfer' &&
                ($bank_name === '' || $bank_account_name === '' || $bank_account_number === '')) {
                throw new InvalidArgumentException(
                    'Bank name, account name, and account number are required for bank transfer.'
                );
            }

            if ($payment_method === 'Check' && $check_payee === '') {
                throw new InvalidArgumentException('Check payee is required for check payment.');
            }

            $other_expense_amount = phase2_prf_money(
                $_POST['other_expense_amount'] ?? 0,
                'Other expense amount'
            );

            if ($other_expense_amount < 0) {
                throw new InvalidArgumentException('Other expense amount cannot be negative.');
            }

            $item_costs = $_POST['item_costs'] ?? [];
            if (!is_array($item_costs) || empty($item_costs)) {
                throw new InvalidArgumentException('Enter the supplier unit cost for every item.');
            }

            $uploaded_supplier_quote = phase2_prf_upload_supplier_quote(
                $_FILES['supplier_quote_file'] ?? null
            );

            if (!$conn->begin_transaction()) {
                throw new RuntimeException('The PRF transaction could not be started.');
            }
            $transaction_started = true;

            $quote_stmt = $conn->prepare(
                "SELECT quotation_number, client_name, amount, status
                 FROM quotations
                 WHERE quotation_id = ?
                 FOR UPDATE"
            );
            $quote_stmt->bind_param('i', $quotation_id);
            if (!$quote_stmt->execute()) {
                throw new RuntimeException('The source quotation could not be checked.');
            }
            $quote = $quote_stmt->get_result()->fetch_assoc();

            if (!$quote || $quote['status'] !== 'PO Received') {
                throw new RuntimeException(
                    'The quotation is not waiting for PR creation or was already converted.'
                );
            }

            $official_po_stmt = $conn->prepare(
                "SELECT
                    approval_record_id,
                    actual_client_po_number,
                    client_po_date,
                    final_approval_date,
                    proof_file_path
                 FROM client_approval_records
                 WHERE quotation_id = ?
                   AND record_type = 'Official Client PO'
                   AND record_status = 'Active'
                 ORDER BY final_approval_date DESC, recorded_at DESC, approval_record_id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $official_po_stmt->bind_param('i', $quotation_id);
            if (!$official_po_stmt->execute()) {
                throw new RuntimeException('The official Client PO record could not be checked.');
            }
            $official_po = $official_po_stmt->get_result()->fetch_assoc();

            if (!$official_po ||
                empty($official_po['actual_client_po_number']) ||
                empty($official_po['client_po_date']) ||
                empty($official_po['final_approval_date']) ||
                empty($official_po['proof_file_path'])) {
                throw new RuntimeException(
                    'A complete official signed Client PO record is required before creating this PRF.'
                );
            }

            if (!phase6b2_is_installed($conn)) {
                throw new RuntimeException(
                    'The official Client PO acknowledgement records are unavailable. Please ask an administrator to review the workflow configuration.'
                );
            }

            $official_approval_record_id = (int) $official_po['approval_record_id'];
            $acknowledgement_stmt = $conn->prepare(
                "SELECT acknowledgement_id, decision
                 FROM client_po_internal_acknowledgements
                 WHERE approval_record_id = ?
                   AND quotation_id = ?
                   AND record_status = 'Active'
                 LIMIT 1
                 FOR UPDATE"
            );
            $acknowledgement_stmt->bind_param(
                'ii',
                $official_approval_record_id,
                $quotation_id
            );
            if (!$acknowledgement_stmt->execute()) {
                throw new RuntimeException(
                    'The General Manager acknowledgment could not be checked.'
                );
            }
            $gm_acknowledgement = $acknowledgement_stmt
                ->get_result()
                ->fetch_assoc();

            if (
                !$gm_acknowledgement ||
                $gm_acknowledgement['decision'] !== 'Acknowledged'
            ) {
                throw new RuntimeException(
                    'General Manager acknowledgment is required before creating this PRF.'
                );
            }

            $official_po_absolute_path = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                'uploads' . DIRECTORY_SEPARATOR . 'pos' . DIRECTORY_SEPARATOR .
                basename((string) $official_po['proof_file_path']);

            if (!is_file($official_po_absolute_path)) {
                throw new RuntimeException('The attached official Client PO file is missing.');
            }

            $existing_pr_stmt = $conn->prepare(
                'SELECT pr_id FROM purchase_requests WHERE quotation_id = ? LIMIT 1 FOR UPDATE'
            );
            $existing_pr_stmt->bind_param('i', $quotation_id);
            if (!$existing_pr_stmt->execute()) {
                throw new RuntimeException('Existing Purchase Requests could not be checked.');
            }
            if ($existing_pr_stmt->get_result()->num_rows > 0) {
                throw new RuntimeException('A Purchase Request already exists for this quotation.');
            }

            $duplicate_number_stmt = $conn->prepare(
                'SELECT pr_id FROM purchase_requests WHERE pr_number = ? LIMIT 1 FOR UPDATE'
            );
            $duplicate_number_stmt->bind_param('s', $pr_number);
            if (!$duplicate_number_stmt->execute()) {
                throw new RuntimeException('The PR number could not be checked.');
            }
            if ($duplicate_number_stmt->get_result()->num_rows > 0) {
                throw new RuntimeException('The PR number is already in use. Please reload the form.');
            }

            $source_items_stmt = $conn->prepare(
                "SELECT
                    item_id,
                    category,
                    brand,
                    item_name,
                    specifications,
                    quantity,
                    unit_price,
                    total_price
                 FROM quotation_items
                 WHERE quotation_id = ?
                 ORDER BY item_id
                 FOR UPDATE"
            );
            $source_items_stmt->bind_param('i', $quotation_id);
            if (!$source_items_stmt->execute()) {
                throw new RuntimeException('The quotation items could not be checked.');
            }

            $source_items_result = $source_items_stmt->get_result();
            $prepared_items = [];
            $selling_amount = 0.00;
            $cost_of_goods_amount = 0.00;

            while ($source_item = $source_items_result->fetch_assoc()) {
                $source_item_id = (int) $source_item['item_id'];

                if (!array_key_exists($source_item_id, $item_costs) &&
                    !array_key_exists((string) $source_item_id, $item_costs)) {
                    throw new InvalidArgumentException(
                        'Enter the supplier unit cost for every quoted item.'
                    );
                }

                $raw_unit_cost = $item_costs[$source_item_id] ??
                    $item_costs[(string) $source_item_id];
                $unit_cost = phase2_prf_money(
                    $raw_unit_cost,
                    'Supplier unit cost for ' . $source_item['item_name']
                );

                if ($unit_cost <= 0) {
                    throw new InvalidArgumentException(
                        'Supplier unit cost must be greater than zero for every item.'
                    );
                }

                $quantity = (int) $source_item['quantity'];
                if ($quantity < 1) {
                    throw new RuntimeException('A quotation item has an invalid quantity.');
                }

                $selling_unit_price = round((float) $source_item['unit_price'], 2);
                $selling_total = round((float) $source_item['total_price'], 2);
                $total_cost = round($unit_cost * $quantity, 2);
                $line_profit_amount = round($selling_total - $total_cost, 2);

                $selling_amount = round($selling_amount + $selling_total, 2);
                $cost_of_goods_amount = round($cost_of_goods_amount + $total_cost, 2);

                $prepared_items[] = [
                    'category' => trim((string) ($source_item['category'] ?? '')),
                    'brand' => trim((string) ($source_item['brand'] ?? '')),
                    'item_name' => trim((string) $source_item['item_name']),
                    'specifications' => trim((string) ($source_item['specifications'] ?? '')),
                    'quantity' => $quantity,
                    'selling_unit_price' => $selling_unit_price,
                    'unit_cost' => $unit_cost,
                    'selling_total' => $selling_total,
                    'total_cost' => $total_cost,
                    'line_profit_amount' => $line_profit_amount,
                ];
            }

            if (empty($prepared_items)) {
                throw new RuntimeException('The quotation does not contain any items.');
            }

            if (abs($selling_amount - (float) $quote['amount']) > 0.01) {
                throw new RuntimeException(
                    'The quotation line totals no longer match its approved selling amount.'
                );
            }

            $requested_fund_amount = round(
                $cost_of_goods_amount + $other_expense_amount,
                2
            );
            $gross_profit_amount = round($selling_amount - $requested_fund_amount, 2);
            $gross_margin_percent = $selling_amount > 0
                ? round(($gross_profit_amount / $selling_amount) * 100, 4)
                : 0.0000;

            $client_approval_record_id = (int) $official_po['approval_record_id'];
            $client_name = (string) $quote['client_name'];

            $insert_pr_stmt = $conn->prepare(
                "INSERT INTO purchase_requests (
                    pr_number,
                    quotation_id,
                    client_approval_record_id,
                    client_name,
                    amount,
                    cost_of_goods_amount,
                    other_expense_amount,
                    requested_fund_amount,
                    gross_profit_amount,
                    gross_margin_percent,
                    status,
                    workflow_version,
                    current_approval_stage,
                    submitted_for_approval_at,
                    created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 2, 'GM Review', NOW(), ?)"
            );
            $insert_pr_stmt->bind_param(
                'siisddddddi',
                $pr_number,
                $quotation_id,
                $client_approval_record_id,
                $client_name,
                $selling_amount,
                $cost_of_goods_amount,
                $other_expense_amount,
                $requested_fund_amount,
                $gross_profit_amount,
                $gross_margin_percent,
                $created_by
            );
            if (!$insert_pr_stmt->execute()) {
                throw new RuntimeException('The Purchase Request could not be saved.');
            }
            $pr_id = (int) $conn->insert_id;

            $insert_item_stmt = $conn->prepare(
                "INSERT INTO pr_items (
                    pr_id,
                    category,
                    brand,
                    item_name,
                    specifications,
                    quantity,
                    unit_price,
                    unit_cost,
                    total_price,
                    total_cost,
                    line_profit_amount
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($prepared_items as $prepared_item) {
                $category = $prepared_item['category'];
                $brand = $prepared_item['brand'];
                $item_name = $prepared_item['item_name'];
                $specifications = $prepared_item['specifications'];
                $quantity = $prepared_item['quantity'];
                $selling_unit_price = $prepared_item['selling_unit_price'];
                $unit_cost = $prepared_item['unit_cost'];
                $selling_total = $prepared_item['selling_total'];
                $total_cost = $prepared_item['total_cost'];
                $line_profit_amount = $prepared_item['line_profit_amount'];

                $insert_item_stmt->bind_param(
                    'issssiddddd',
                    $pr_id,
                    $category,
                    $brand,
                    $item_name,
                    $specifications,
                    $quantity,
                    $selling_unit_price,
                    $unit_cost,
                    $selling_total,
                    $total_cost,
                    $line_profit_amount
                );
                if (!$insert_item_stmt->execute()) {
                    throw new RuntimeException('A PRF item could not be saved.');
                }
            }

            $supplier_quote_original_name = $uploaded_supplier_quote['original_name'] ?? null;
            $supplier_quote_file_path = $uploaded_supplier_quote['stored_name'] ?? null;
            $supplier_quote_file_hash = $uploaded_supplier_quote['hash'] ?? null;

            $insert_supplier_stmt = $conn->prepare(
                "INSERT INTO pr_supplier_details (
                    pr_id,
                    supplier_name,
                    supplier_reference,
                    supplier_quote_date,
                    quoted_cost_amount,
                    payment_method,
                    payment_terms,
                    bank_name,
                    bank_account_name,
                    bank_account_number,
                    check_payee,
                    supplier_quote_original_name,
                    supplier_quote_file_path,
                    supplier_quote_file_hash,
                    remarks,
                    created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert_supplier_stmt->bind_param(
                'isssdssssssssssi',
                $pr_id,
                $supplier_name,
                $supplier_reference,
                $supplier_quote_date,
                $cost_of_goods_amount,
                $payment_method,
                $payment_terms,
                $bank_name,
                $bank_account_name,
                $bank_account_number,
                $check_payee,
                $supplier_quote_original_name,
                $supplier_quote_file_path,
                $supplier_quote_file_hash,
                $supplier_remarks,
                $created_by
            );
            if (!$insert_supplier_stmt->execute()) {
                throw new RuntimeException('The supplier details could not be saved.');
            }

            $insert_approval_stmt = $conn->prepare(
                "INSERT INTO pr_approval_records (
                    pr_id,
                    approval_cycle,
                    stage_sequence,
                    approval_stage,
                    required_role,
                    decision
                 ) VALUES (?, 1, ?, ?, ?, 'Pending')"
            );

            $approval_steps = [
                [1, 'GM Review', 'GM'],
                [2, 'Finance Review', 'Finance'],
                [3, 'Owner Approval', 'President'],
            ];

            foreach ($approval_steps as $approval_step) {
                $stage_sequence = $approval_step[0];
                $approval_stage = $approval_step[1];
                $required_role = $approval_step[2];
                $insert_approval_stmt->bind_param(
                    'iiss',
                    $pr_id,
                    $stage_sequence,
                    $approval_stage,
                    $required_role
                );
                if (!$insert_approval_stmt->execute()) {
                    throw new RuntimeException('The PRF approval route could not be created.');
                }
            }

            $quote_update_stmt = $conn->prepare(
                "UPDATE quotations
                 SET status = 'Converted to PR'
                 WHERE quotation_id = ?
                   AND status = 'PO Received'"
            );
            $quote_update_stmt->bind_param('i', $quotation_id);
            if (!$quote_update_stmt->execute() || $quote_update_stmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'The quotation status changed before the PRF could be completed.'
                );
            }

            phase2_prf_notify_role(
                $conn,
                'GM',
                "New PRF $pr_number requires GM review."
            );

            log_audit_action(
                $conn,
                $created_by,
                'CREATE_PR',
                "Created official PRF $pr_number from quotation {$quote['quotation_number']}",
                null,
                [
                    'pr_id' => $pr_id,
                    'pr_number' => $pr_number,
                    'quotation_id' => $quotation_id,
                    'client_approval_record_id' => $client_approval_record_id,
                    'selling_amount' => $selling_amount,
                    'cost_of_goods_amount' => $cost_of_goods_amount,
                    'other_expense_amount' => $other_expense_amount,
                    'requested_fund_amount' => $requested_fund_amount,
                    'gross_profit_amount' => $gross_profit_amount,
                    'gross_margin_percent' => $gross_margin_percent,
                    'current_approval_stage' => 'GM Review',
                ]
            );

            if (!$conn->commit()) {
                throw new RuntimeException('The PRF transaction could not be committed.');
            }
            $transaction_started = false;

            header(
                'Location: ../view_pr.php?id=' . $pr_id .
                '&success=' . rawurlencode('PRF submitted to the General Manager for review.')
            );
            exit();
        } catch (Throwable $exception) {
            if ($transaction_started) {
                $conn->rollback();
            }

            if ($uploaded_supplier_quote &&
                !empty($uploaded_supplier_quote['absolute_path']) &&
                is_file($uploaded_supplier_quote['absolute_path'])) {
                @unlink($uploaded_supplier_quote['absolute_path']);
            }

            drms_log_workflow_failure(
                'Official PRF creation from quotation ' . $quotation_id,
                $exception
            );
            $safe_error_message = $exception->getMessage();

            phase2_prf_redirect_error($quotation_id, $safe_error_message);
        }
    }

}

header("Location: ../dashboard.php");
exit();
?>
