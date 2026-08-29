<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

const PHASE6B4_COLLECTION_TERM_DAYS = 15;

function phase4d_redirect(
    int $po_id,
    string $type,
    string $message
): void {
    $destination = $po_id > 0
        ? '../complete_delivery.php?po_id=' . $po_id
        : '../po_list.php?filter=my_tasks';
    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The client delivery could not be completed. No workflow changes were saved.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($destination, $type, $public_message);
}

function phase4d_parse_datetime(string $value): ?DateTime
{
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    $has_errors = is_array($errors) &&
        ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (
        !$date ||
        $has_errors ||
        $date->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $date;
}

function phase4d_length_is_valid(
    ?string $value,
    int $maximum,
    bool $required = false
): bool {
    $text = trim((string) $value);
    if ($required && $text === '') {
        return false;
    }

    return strlen($text) <= $maximum;
}

function phase4d_auto_assign_finance(
    mysqli $conn,
    int $po_id,
    int $assigned_by
): int {
    $role = 'Finance';
    $candidate_stmt = $conn->prepare(
        "SELECT
            user_account.user_id,
            COUNT(active_task.assignment_id) AS active_tasks
         FROM users user_account
         LEFT JOIN purchase_order_task_assignments active_task
            ON active_task.assigned_to = user_account.user_id
           AND active_task.assignment_status = 'Active'
         WHERE user_account.role = ?
           AND user_account.status = 'Active'
         GROUP BY user_account.user_id
         ORDER BY active_tasks ASC, user_account.user_id ASC
         LIMIT 1"
    );
    $candidate_stmt->bind_param('s', $role);
    $candidate_stmt->execute();
    $candidate = $candidate_stmt->get_result()->fetch_assoc();

    if (!$candidate) {
        throw new DomainException(
            'No active Finance user is available for collection monitoring.'
        );
    }

    $assigned_to = (int) $candidate['user_id'];
    $assignment_stmt = $conn->prepare(
        "INSERT INTO purchase_order_task_assignments (
            po_id,
            assigned_to,
            assigned_by,
            assigned_role,
            assignment_status,
            assigned_at
         ) VALUES (?, ?, ?, 'Finance', 'Active', NOW())"
    );
    $assignment_stmt->bind_param(
        'iii',
        $po_id,
        $assigned_to,
        $assigned_by
    );
    $assignment_stmt->execute();

    return $assigned_to;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../po_list.php?filter=my_tasks');
    exit();
}

$po_id = (int) ($_POST['po_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$session_token = (string) ($_SESSION['csrf_token'] ?? '');
$posted_token = (string) ($_POST['csrf_token'] ?? '');

if (
    $session_token === '' ||
    $posted_token === '' ||
    !hash_equals($session_token, $posted_token)
) {
    phase4d_redirect(
        $po_id,
        'error',
        'Security token validation failed. Refresh the form and try again.'
    );
}

if ($action !== 'complete_client_delivery') {
    phase4d_redirect($po_id, 'error', 'Invalid delivery-completion action.');
}

if ($_SESSION['role'] !== 'Supply Chain') {
    phase4d_redirect(
        $po_id,
        'error',
        'Only Supply Chain can record the final client delivery.'
    );
}

if ($po_id < 1) {
    phase4d_redirect(0, 'error', 'Select a valid scheduled delivery.');
}

$actual_handover_input = trim(
    (string) ($_POST['actual_handover_at'] ?? '')
);
$acknowledgement_type = trim(
    (string) ($_POST['acknowledgement_type'] ?? '')
);
$client_receipt_reference = trim(
    (string) ($_POST['client_receipt_reference'] ?? '')
);
$recipient_name = trim((string) ($_POST['recipient_name'] ?? ''));
$recipient_position = trim(
    (string) ($_POST['recipient_position'] ?? '')
);
$recipient_contact = trim(
    (string) ($_POST['recipient_contact'] ?? '')
);
$delivered_quantity_input = trim(
    (string) ($_POST['delivered_item_quantity'] ?? '')
);
$delivery_condition = trim(
    (string) ($_POST['delivery_condition'] ?? '')
);
$discrepancy_notes = trim(
    (string) ($_POST['discrepancy_notes'] ?? '')
);
$confirmed = isset($_POST['delivery_completion_confirmation']) &&
    $_POST['delivery_completion_confirmation'] === '1';

$allowed_acknowledgements = [
    'Signed Delivery Receipt',
    'Client Email Confirmation',
    'Electronic Acknowledgement',
    'Other',
];
$allowed_conditions = [
    'Complete and Accepted',
    'Accepted with Noted Issue',
];

$actual_handover_at = phase4d_parse_datetime($actual_handover_input);
if (!$actual_handover_at || $actual_handover_at > new DateTime('now')) {
    phase4d_redirect(
        $po_id,
        'error',
        'Enter a valid non-future client handover date and time.'
    );
}

if (!in_array(
    $acknowledgement_type,
    $allowed_acknowledgements,
    true
)) {
    phase4d_redirect(
        $po_id,
        'error',
        'Select a valid client acknowledgement type.'
    );
}

if (!in_array($delivery_condition, $allowed_conditions, true)) {
    phase4d_redirect($po_id, 'error', 'Select a valid delivery condition.');
}

if (
    !phase4d_length_is_valid($client_receipt_reference, 100) ||
    !phase4d_length_is_valid($recipient_name, 150, true) ||
    !phase4d_length_is_valid($recipient_position, 100) ||
    !phase4d_length_is_valid($recipient_contact, 100) ||
    !phase4d_length_is_valid($discrepancy_notes, 2000)
) {
    phase4d_redirect(
        $po_id,
        'error',
        'One or more client receipt fields exceed the allowed length.'
    );
}

if (
    $delivery_condition === 'Accepted with Noted Issue' &&
    $discrepancy_notes === ''
) {
    phase4d_redirect(
        $po_id,
        'error',
        'Describe the noted delivery issue before completion.'
    );
}

$delivered_quantity = filter_var(
    $delivered_quantity_input,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000000]]
);
if ($delivered_quantity === false) {
    phase4d_redirect(
        $po_id,
        'error',
        'Delivered quantity must be a valid whole number.'
    );
}

if (!$confirmed) {
    phase4d_redirect(
        $po_id,
        'error',
        'Confirm the client handover and accepted quantity before completion.'
    );
}

if (
    !isset($_FILES['delivery_receipt_proof']) ||
    $_FILES['delivery_receipt_proof']['error'] !== UPLOAD_ERR_OK ||
    !is_uploaded_file($_FILES['delivery_receipt_proof']['tmp_name'])
) {
    phase4d_redirect(
        $po_id,
        'error',
        'Client acknowledgement proof is required.'
    );
}

$proof = $_FILES['delivery_receipt_proof'];
$proof_extension = strtolower(
    pathinfo((string) $proof['name'], PATHINFO_EXTENSION)
);
$allowed_proofs = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$proof_mime = (new finfo(FILEINFO_MIME_TYPE))
    ->file($proof['tmp_name']);

if (
    $proof['size'] < 1 ||
    $proof['size'] > 10 * 1024 * 1024 ||
    !isset($allowed_proofs[$proof_extension]) ||
    $proof_mime !== $allowed_proofs[$proof_extension]
) {
    phase4d_redirect(
        $po_id,
        'error',
        'Client acknowledgement proof must be a valid PDF, JPG, or PNG file up to 10 MB.'
    );
}

$upload_directory = __DIR__ . '/../uploads/';
$stored_absolute_path = null;
$user_id = (int) $_SESSION['user_id'];

try {
    if (
        !is_dir($upload_directory) &&
        !mkdir($upload_directory, 0755, true) &&
        !is_dir($upload_directory)
    ) {
        throw new RuntimeException(
            'The delivery-receipt proof directory could not be prepared.'
        );
    }

    $conn->begin_transaction();

    $delivery_stmt = $conn->prepare(
        "SELECT
            po.po_number,
            po.client_name,
            po.amount,
            po.status,
            po.collection_status,
            COALESCE(
                (
                    SELECT SUM(payment.amount_paid)
                    FROM payments payment
                    WHERE payment.po_id = po.po_id
                ),
                0
            ) AS total_client_paid,
            delivery_request.delivery_request_id,
            delivery_request.request_number,
            delivery_request.request_status,
            delivery_request.submitted_at,
            plan.delivery_plan_id,
            plan.logistics_status,
            plan.reviewed_at,
            plan.provider_type,
            plan.provider_name,
            plan.planned_pickup_at,
            plan.planned_delivery_at,
            item_total.expected_item_quantity
         FROM purchase_orders po
         INNER JOIN po_delivery_requests delivery_request
            ON delivery_request.po_id = po.po_id
           AND delivery_request.record_status = 'Active'
         INNER JOIN po_delivery_plans plan
            ON plan.delivery_request_id =
                delivery_request.delivery_request_id
           AND plan.record_status = 'Active'
         INNER JOIN (
            SELECT
                po_id,
                COALESCE(SUM(quantity), 0) AS expected_item_quantity
            FROM po_items
            GROUP BY po_id
         ) item_total
            ON item_total.po_id = po.po_id
         WHERE po.po_id = ?
         ORDER BY delivery_request.request_cycle DESC
         LIMIT 1
         FOR UPDATE"
    );
    $delivery_stmt->bind_param('i', $po_id);
    $delivery_stmt->execute();
    $delivery = $delivery_stmt->get_result()->fetch_assoc();

    if (
        !$delivery ||
        $delivery['status'] !== 'For Pick-up/Delivery' ||
        $delivery['request_status'] !== 'Scheduled' ||
        $delivery['logistics_status'] !== 'Scheduled'
    ) {
        throw new DomainException(
            'This PO is no longer ready for client delivery completion.'
        );
    }

    try {
        enforce_po_task_ownership(
            $conn,
            $po_id,
            $user_id,
            'Supply Chain'
        );
    } catch (Throwable $ownership_error) {
        throw new DomainException($ownership_error->getMessage());
    }

    $rule_stmt = $conn->prepare(
        "SELECT next_status, next_location, notify_target
         FROM workflow_rules
         WHERE current_status = 'For Pick-up/Delivery'
           AND action_key = 'mark_delivered'
           AND required_role = 'Supply Chain'
         LIMIT 1"
    );
    $rule_stmt->execute();
    $rule = $rule_stmt->get_result()->fetch_assoc();

    if (!$rule || $rule['next_status'] !== 'Delivered') {
        throw new DomainException(
            'The final delivery workflow rule is unavailable.'
        );
    }

    $reviewed_at = new DateTime($delivery['reviewed_at']);
    if ($actual_handover_at < $reviewed_at) {
        throw new DomainException(
            'Client handover cannot be earlier than the approved logistics schedule.'
        );
    }

    $expected_item_quantity =
        (int) $delivery['expected_item_quantity'];
    if (
        $expected_item_quantity < 1 ||
        (int) $delivered_quantity !== $expected_item_quantity
    ) {
        throw new DomainException(
            'Delivered quantity must equal the complete PO quantity of ' .
            number_format($expected_item_quantity) .
            '. Do not mark a partial handover as Delivered.'
        );
    }

    $existing_receipt_stmt = $conn->prepare(
        "SELECT delivery_receipt_id
         FROM po_delivery_receipts
         WHERE po_id = ?
           AND record_status = 'Active'
         LIMIT 1
         FOR UPDATE"
    );
    $existing_receipt_stmt->bind_param('i', $po_id);
    $existing_receipt_stmt->execute();
    if ($existing_receipt_stmt->get_result()->num_rows > 0) {
        throw new DomainException(
            'An active client delivery receipt already exists for this PO.'
        );
    }

    $cycle_stmt = $conn->prepare(
        "SELECT COALESCE(MAX(receipt_cycle), 0) + 1 AS next_cycle
         FROM po_delivery_receipts
         WHERE po_id = ?
         FOR UPDATE"
    );
    $cycle_stmt->bind_param('i', $po_id);
    $cycle_stmt->execute();
    $receipt_cycle = (int) (
        $cycle_stmt->get_result()->fetch_assoc()['next_cycle'] ?? 1
    );

    $stored_file_name = date('YmdHis') . '_client_delivery_' .
        bin2hex(random_bytes(12)) . '.' . $proof_extension;
    $stored_absolute_path = $upload_directory . $stored_file_name;
    if (!move_uploaded_file($proof['tmp_name'], $stored_absolute_path)) {
        throw new RuntimeException(
            'The client acknowledgement proof could not be saved.'
        );
    }

    $proof_file_hash = hash_file('sha256', $stored_absolute_path);
    if (!$proof_file_hash) {
        throw new RuntimeException(
            'The client acknowledgement proof hash could not be generated.'
        );
    }

    $proof_original_name = substr(
        basename((string) $proof['name']),
        0,
        255
    );
    $actual_handover_sql =
        $actual_handover_at->format('Y-m-d H:i:s');
    // The client term is 15 calendar days beginning on the verified actual
    // handover date. It is calculated only on the server and is never accepted
    // from a browser-submitted due-date field.
    $collection_term_days = PHASE6B4_COLLECTION_TERM_DAYS;
    $collection_due_date = (clone $actual_handover_at)
        ->modify('+' . $collection_term_days . ' days')
        ->format('Y-m-d');
    $client_receipt_reference = $client_receipt_reference !== ''
        ? $client_receipt_reference
        : null;
    $recipient_position = $recipient_position !== ''
        ? $recipient_position
        : null;
    $recipient_contact = $recipient_contact !== ''
        ? $recipient_contact
        : null;
    $discrepancy_notes = $discrepancy_notes !== ''
        ? $discrepancy_notes
        : null;
    $delivery_request_id = (int) $delivery['delivery_request_id'];
    $delivery_plan_id = (int) $delivery['delivery_plan_id'];

    $receipt_stmt = $conn->prepare(
        "INSERT INTO po_delivery_receipts (
            po_id,
            receipt_cycle,
            delivery_request_id,
            delivery_plan_id,
            client_receipt_reference,
            actual_handover_at,
            acknowledgement_type,
            recipient_name,
            recipient_position,
            recipient_contact,
            expected_item_quantity,
            delivered_item_quantity,
            delivery_condition,
            discrepancy_notes,
            proof_original_name,
            proof_file_path,
            proof_file_hash,
            collection_term_days,
            collection_due_date,
            recorded_by
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
         )"
    );
    $receipt_stmt->bind_param(
        'iiiissssssiisssssisi',
        $po_id,
        $receipt_cycle,
        $delivery_request_id,
        $delivery_plan_id,
        $client_receipt_reference,
        $actual_handover_sql,
        $acknowledgement_type,
        $recipient_name,
        $recipient_position,
        $recipient_contact,
        $expected_item_quantity,
        $delivered_quantity,
        $delivery_condition,
        $discrepancy_notes,
        $proof_original_name,
        $stored_file_name,
        $proof_file_hash,
        $collection_term_days,
        $collection_due_date,
        $user_id
    );
    $receipt_stmt->execute();
    $delivery_receipt_id = (int) $conn->insert_id;

    if ($delivery_receipt_id < 1) {
        throw new RuntimeException(
            'The client delivery receipt could not be saved.'
        );
    }

    $document_type = 'Proof of Delivery';
    $document_record_number = 'POD-' .
        str_pad((string) $po_id, 4, '0', STR_PAD_LEFT) . '-' .
        str_pad((string) $receipt_cycle, 2, '0', STR_PAD_LEFT);
    $document_file_path = 'uploads/' .
        $stored_file_name;
    $document_category = 'Delivery Receipts';
    $document_tags = 'client receipt,proof of delivery';
    $document_stmt = $conn->prepare(
        "INSERT INTO documents (
            po_id,
            doc_type,
            file_name,
            record_number,
            file_path,
            category,
            tags,
            file_hash,
            uploaded_by,
            status,
            record_phase,
            declared_at,
            declared_by
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            'Active', 'Official', NOW(), ?
         )"
    );
    $document_stmt->bind_param(
        'isssssssii',
        $po_id,
        $document_type,
        $stored_file_name,
        $document_record_number,
        $document_file_path,
        $document_category,
        $document_tags,
        $proof_file_hash,
        $user_id,
        $user_id
    );
    $document_stmt->execute();
    $document_id = (int) $conn->insert_id;

    $plan_stmt = $conn->prepare(
        "UPDATE po_delivery_plans
         SET logistics_status = 'Completed',
             completed_at = ?
         WHERE delivery_plan_id = ?
           AND logistics_status = 'Scheduled'"
    );
    $plan_stmt->bind_param(
        'si',
        $actual_handover_sql,
        $delivery_plan_id
    );
    $plan_stmt->execute();
    if ($plan_stmt->affected_rows !== 1) {
        throw new DomainException(
            'The logistics plan changed before delivery completion.'
        );
    }

    $request_stmt = $conn->prepare(
        "UPDATE po_delivery_requests
         SET request_status = 'Completed'
         WHERE delivery_request_id = ?
           AND request_status = 'Scheduled'"
    );
    $request_stmt->bind_param('i', $delivery_request_id);
    $request_stmt->execute();
    if ($request_stmt->affected_rows !== 1) {
        throw new DomainException(
            'The delivery request changed before completion.'
        );
    }

    $new_status = $rule['next_status'];
    $remaining_collection_balance = max(
        round(
            (float) $delivery['amount'] -
            (float) $delivery['total_client_paid'],
            2
        ),
        0
    );
    $collection_is_paid =
        $delivery['collection_status'] === 'Paid' ||
        $remaining_collection_balance <= 0.01;
    $new_location = $collection_is_paid
        ? 'Client / Completed'
        : (trim((string) $rule['next_location']) !== ''
            ? $rule['next_location']
            : 'Finance Dept. (Collection)');
    $po_stmt = $conn->prepare(
        "UPDATE purchase_orders
         SET status = ?,
             current_location = ?,
             actual_delivery_date = DATE(?),
             expected_collection_date = ?,
             is_viewed = 0
         WHERE po_id = ?
           AND status = 'For Pick-up/Delivery'"
    );
    $po_stmt->bind_param(
        'ssssi',
        $new_status,
        $new_location,
        $actual_handover_sql,
        $collection_due_date,
        $po_id
    );
    $po_stmt->execute();
    if ($po_stmt->affected_rows !== 1) {
        throw new DomainException(
            'The PO status changed before delivery completion.'
        );
    }

    $history_remarks = 'Client delivery completed. Receipt: ' .
        $document_record_number . '. Accepted by ' . $recipient_name .
        '. Collection due ' . $collection_due_date . ' (' .
        $collection_term_days . ' calendar days).';
    $history_stmt = $conn->prepare(
        "INSERT INTO po_history (
            po_id,
            changed_by,
            status_from,
            status_to,
            remarks
         ) VALUES (
            ?, ?, 'For Pick-up/Delivery', 'Delivered', ?
         )"
    );
    $history_stmt->bind_param(
        'iis',
        $po_id,
        $user_id,
        $history_remarks
    );
    $history_stmt->execute();

    complete_po_task_assignment(
        $conn,
        $po_id,
        $user_id,
        'Client delivery and acknowledgement completed'
    );
    $assigned_finance_user = null;
    if (!$collection_is_paid) {
        $assigned_finance_user = phase4d_auto_assign_finance(
            $conn,
            $po_id,
            $user_id
        );
    }

    $notify_target = trim((string) $rule['notify_target']) !== ''
        ? $rule['notify_target']
        : 'Finance';
    create_role_notification(
        $conn,
        $notify_target,
        $collection_is_paid
            ? 'PO ' . $delivery['po_number'] .
                ' was received by the client and is already fully paid from verified advance collection.'
            : 'PO ' . $delivery['po_number'] .
                ' was received by the client. Remaining collection of ₱' .
                number_format($remaining_collection_balance, 2) .
                ' is due on ' .
                date('M d, Y', strtotime($collection_due_date)) . '.'
    );

    log_audit_action(
        $conn,
        $user_id,
        'COMPLETE_CLIENT_DELIVERY',
        $history_remarks,
        [
            'status' => 'For Pick-up/Delivery',
            'logistics_status' => 'Scheduled',
        ],
        [
            'status' => 'Delivered',
            'logistics_status' => 'Completed',
            'delivery_receipt_id' => $delivery_receipt_id,
            'document_id' => $document_id,
            'recipient_name' => $recipient_name,
            'actual_handover_at' => $actual_handover_sql,
            'collection_term_days' => $collection_term_days,
            'collection_due_date' => $collection_due_date,
            'collection_status' => $delivery['collection_status'],
            'remaining_collection_balance' =>
                $remaining_collection_balance,
            'assigned_finance_user' => $assigned_finance_user,
        ]
    );

    $conn->commit();
    header(
        'Location: ../view_po.php?id=' . $po_id . '&success=' .
        rawurlencode($collection_is_paid
            ? 'Client delivery recorded. The PO was already fully paid through verified advance collection.'
            : 'Client delivery recorded. Finance collection is due on ' .
                date('M d, Y', strtotime($collection_due_date)) . '.')
    );
    exit();
} catch (Throwable $error) {
    $conn->rollback();

    if ($stored_absolute_path && is_file($stored_absolute_path)) {
        unlink($stored_absolute_path);
    }

    drms_log_workflow_failure(
        'Client delivery completion for PO ' . $po_id,
        $error
    );

    if ($error instanceof DomainException) {
        $public_error = $error->getMessage();
    } elseif ($error instanceof RuntimeException) {
        $public_error =
            'The delivery evidence could not be stored. No workflow changes were saved. Check the upload folder and try again.';
    } else {
        $public_error =
            'The client delivery could not be completed. No workflow changes were saved.';
    }
    phase4d_redirect($po_id, 'error', $public_error);
}

