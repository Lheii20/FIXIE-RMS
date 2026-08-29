<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

function phase5d_payment_redirect(
    int $po_id,
    string $return_to,
    string $type,
    string $message
): void {
    if ($return_to === 'view_po' && $po_id > 0) {
        $destination = '../view_po.php?id=' . $po_id;
    } elseif ($type === 'success') {
        $destination = '../collection_monitoring.php';
    } elseif ($po_id > 0) {
        $destination = '../record_collection_payment.php?po_id=' . $po_id;
    } else {
        $destination = '../collection_monitoring.php';
    }

    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The client payment could not be saved. No collection balance was changed.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($destination, $type, $public_message);
}

function phase5d_payment_datetime(string $value): ?DateTime
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

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../collection_monitoring.php');
    exit();
}

$po_id = (int) ($_POST['po_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$return_to = trim((string) ($_POST['return_to'] ?? 'payment_form'));
if (!in_array($return_to, ['payment_form', 'view_po'], true)) {
    $return_to = 'payment_form';
}

$session_token = (string) ($_SESSION['csrf_token'] ?? '');
$posted_token = (string) ($_POST['csrf_token'] ?? '');
if (
    $session_token === '' ||
    $posted_token === '' ||
    !hash_equals($session_token, $posted_token)
) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Security token validation failed. Refresh the form and try again.'
    );
}

if ($action !== 'record_collection_payment' && $action !== 'add_payment') {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Invalid collection payment action.'
    );
}

if (($_SESSION['role'] ?? '') !== 'Finance') {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Only Finance can record client collection payments.'
    );
}

if ($po_id < 1) {
    phase5d_payment_redirect(
        0,
        $return_to,
        'error',
        'Select a valid open receivable.'
    );
}

$amount_input = trim((string) ($_POST['amount_paid'] ?? ''));
$payment_method = trim((string) ($_POST['payment_method'] ?? ''));
$reference_number = trim((string) ($_POST['reference_number'] ?? ''));
$payment_date_input = trim((string) ($_POST['payment_date'] ?? ''));
$classification = trim(
    (string) ($_POST['payment_classification'] ?? 'Auto')
);
$payment_remarks = trim((string) ($_POST['payment_remarks'] ?? ''));
$confirmed = isset($_POST['payment_confirmation']) &&
    $_POST['payment_confirmation'] === '1';

$allowed_methods = [
    'Cash',
    'Bank Transfer',
    'GCash',
    'Cheque',
    'Other',
];
$allowed_classifications = [
    'Auto',
    'Full Payment',
    'Partial Payment',
    'Advance / Down Payment',
];

if ($amount_input === '' || !is_numeric($amount_input)) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Enter a valid client payment amount.'
    );
}
$amount_paid = round((float) $amount_input, 2);

if (
    $amount_paid <= 0 ||
    !in_array($payment_method, $allowed_methods, true) ||
    !in_array($classification, $allowed_classifications, true) ||
    $reference_number === '' ||
    strlen($reference_number) > 100 ||
    strlen($payment_remarks) > 1000
) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Complete the payment method, reference, amount, and remarks within the allowed limits.'
    );
}

$payment_date = phase5d_payment_datetime($payment_date_input);
if (!$payment_date || $payment_date > new DateTime('now')) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Enter a valid non-future payment date and time.'
    );
}

if (!$confirmed) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Confirm that the client payment and proof were verified.'
    );
}

if (
    !isset($_FILES['payment_proof']) ||
    $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK ||
    !is_uploaded_file($_FILES['payment_proof']['tmp_name'])
) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Payment proof is required.'
    );
}

$proof = $_FILES['payment_proof'];
$proof_extension = strtolower(
    pathinfo((string) $proof['name'], PATHINFO_EXTENSION)
);
$allowed_proofs = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$proof_mime = (new finfo(FILEINFO_MIME_TYPE))->file(
    $proof['tmp_name']
);

if (
    $proof['size'] < 1 ||
    $proof['size'] > 10 * 1024 * 1024 ||
    !isset($allowed_proofs[$proof_extension]) ||
    $proof_mime !== $allowed_proofs[$proof_extension]
) {
    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        'Payment proof must be a valid PDF, JPG, or PNG file up to 10 MB.'
    );
}

$user_id = (int) $_SESSION['user_id'];
$payment_directory = __DIR__ . '/../uploads/payments/';
$proof_file_path = null;

try {
    // Run the collaboration-table guard before opening the payment
    // transaction so its optional setup cannot cause an implicit DDL commit.
    ensure_collaboration_tables_exist($conn);

    if (
        !is_dir($payment_directory) &&
        !mkdir($payment_directory, 0755, true) &&
        !is_dir($payment_directory)
    ) {
        throw new RuntimeException(
            'Unable to create the payment-proof folder.'
        );
    }

    $conn->begin_transaction();

    $po_stmt = $conn->prepare(
        "SELECT
            po.po_number,
            po.client_name,
            po.amount,
            po.status,
            po.collection_status,
            po.current_location,
            po.date_created,
            COALESCE(
                NULLIF(receipt.actual_handover_at, ''),
                CONCAT(NULLIF(po.actual_delivery_date, ''), ' 00:00:00'),
                (
                    SELECT MIN(history.timestamp)
                    FROM po_history history
                    WHERE history.po_id = po.po_id
                      AND history.status_to = 'Delivered'
                )
            ) AS delivery_completed_at
         FROM purchase_orders po
         LEFT JOIN po_delivery_receipts receipt
            ON receipt.delivery_receipt_id = (
                SELECT MAX(receipt_candidate.delivery_receipt_id)
                FROM po_delivery_receipts receipt_candidate
                WHERE receipt_candidate.po_id = po.po_id
                  AND receipt_candidate.record_status = 'Active'
            )
         WHERE po.po_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();
    $po_stmt->close();

    $payment_eligible_statuses = [
        'President-Approved',
        'Funded',
        'Delivery Requested',
        'For Pick-up/Delivery',
        'Delivered',
    ];
    if (!$po || !in_array($po['status'], $payment_eligible_statuses, true)) {
        throw new DomainException(
            'Client payment can be recorded only after the PO reaches final approval.'
        );
    }

    $po_created_at = new DateTime($po['date_created']);
    $delivery_completed_at = !empty($po['delivery_completed_at'])
        ? new DateTime($po['delivery_completed_at'])
        : null;
    $delivery_is_complete = $po['status'] === 'Delivered';

    if ($delivery_is_complete && $delivery_completed_at === null) {
        throw new DomainException(
            'The delivery completion timestamp is missing. Complete or correct the client delivery record first.'
        );
    }

    // A pre-delivery down payment is a Finance evidence entry running beside
    // the operational PO task, so it must not claim or complete that task.
    // Once delivery is complete, the existing assigned-Finance control remains.
    $assignment = get_active_po_task_assignment($conn, $po_id, true);
    if ($delivery_is_complete && (
        !$assignment ||
        $assignment['assigned_role'] !== 'Finance' ||
        (int) $assignment['assigned_to'] !== $user_id
    )) {
        throw new DomainException(
            'This receivable must be assigned to you before recording payment.'
        );
    }

    $paid_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
         FROM payments
         WHERE po_id = ?"
    );
    $paid_stmt->bind_param('i', $po_id);
    $paid_stmt->execute();
    $total_paid = round(
        (float) $paid_stmt->get_result()->fetch_assoc()['total_paid'],
        2
    );
    $paid_stmt->close();

    if ($po['collection_status'] === 'Paid') {
        throw new DomainException(
            'This PO is already marked as fully paid.'
        );
    }

    $balance_before = max(
        round((float) $po['amount'] - $total_paid, 2),
        0
    );
    if ($balance_before <= 0) {
        throw new DomainException(
            'This PO no longer has an outstanding collection balance.'
        );
    }
    if ($amount_paid > $balance_before) {
        throw new DomainException(
            'Payment cannot exceed the remaining balance of ₱' .
            number_format($balance_before, 2) . '.'
        );
    }

    $payment_is_before_delivery = !$delivery_is_complete || (
        $delivery_completed_at !== null &&
        $payment_date <= $delivery_completed_at
    );

    if ($classification === 'Auto') {
        if ($payment_is_before_delivery) {
            $classification = 'Advance / Down Payment';
        } else {
            $classification = abs($amount_paid - $balance_before) < 0.005
                ? 'Full Payment'
                : 'Partial Payment';
        }
    }

    if (
        $classification === 'Full Payment' &&
        abs($amount_paid - $balance_before) >= 0.005
    ) {
        throw new DomainException(
            'Full payment must equal the exact outstanding balance.'
        );
    }
    if (
        $classification === 'Partial Payment' &&
        $amount_paid >= $balance_before
    ) {
        throw new DomainException(
            'Partial payment must be lower than the outstanding balance.'
        );
    }

    if ($payment_date < $po_created_at) {
        throw new DomainException(
            'Payment date cannot be earlier than the recorded PO.'
        );
    }
    if (
        $classification !== 'Advance / Down Payment' &&
        $payment_is_before_delivery
    ) {
        throw new DomainException(
            'Use Advance / Down Payment for a client payment received before delivery.'
        );
    }
    if (
        $classification === 'Advance / Down Payment' &&
        !$payment_is_before_delivery
    ) {
        throw new DomainException(
            'A payment received after delivery must be recorded as partial or full payment.'
        );
    }

    $duplicate_stmt = $conn->prepare(
        "SELECT payment_id
         FROM payments
         WHERE po_id = ?
           AND TRIM(reference_number) = ?
         LIMIT 1"
    );
    $duplicate_stmt->bind_param(
        'is',
        $po_id,
        $reference_number
    );
    $duplicate_stmt->execute();
    $duplicate_payment = $duplicate_stmt
        ->get_result()
        ->fetch_assoc();
    $duplicate_stmt->close();
    if ($duplicate_payment) {
        throw new DomainException(
            'This payment reference is already recorded for the PO.'
        );
    }

    $proof_file_path = date('YmdHis') . '_collection_' .
        bin2hex(random_bytes(8)) . '.' . $proof_extension;
    if (!move_uploaded_file(
        $proof['tmp_name'],
        $payment_directory . $proof_file_path
    )) {
        throw new RuntimeException(
            'The payment proof could not be saved.'
        );
    }

    $balance_after = max(
        round($balance_before - $amount_paid, 2),
        0
    );
    $new_collection_status = $balance_after <= 0.01
        ? 'Paid'
        : 'Partially Paid';
    $payment_label = $classification;
    $payment_notes = $payment_label;
    if ($payment_remarks !== '') {
        $payment_notes .= ' | ' . $payment_remarks;
    }

    $payment_datetime = $payment_date->format('Y-m-d H:i:s');
    $insert_stmt = $conn->prepare(
        "INSERT INTO payments (
            po_id,
            amount_paid,
            payment_date,
            notes,
            payment_classification,
            recorded_by,
            payment_method,
            reference_number,
            proof_file_path
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insert_stmt->bind_param(
        'idsssisss',
        $po_id,
        $amount_paid,
        $payment_datetime,
        $payment_notes,
        $classification,
        $user_id,
        $payment_method,
        $reference_number,
        $proof_file_path
    );
    $insert_stmt->execute();
    $payment_id = (int) $conn->insert_id;
    $insert_stmt->close();

    if ($payment_id < 1) {
        throw new RuntimeException(
            'The payment record could not be saved.'
        );
    }

    // Only the financial collection position changes. The operational PO status,
    // location, and currently assigned delivery/procurement task stay untouched.
    $po_update = $conn->prepare(
        "UPDATE purchase_orders
         SET collection_status = ?,
             collection_status_updated_at = NOW()
         WHERE po_id = ?"
    );
    $po_update->bind_param('si', $new_collection_status, $po_id);
    $po_update->execute();
    $po_update->close();

    $history_remarks = $payment_label . ' recorded. Balance: ₱' .
        number_format($balance_after, 2) . '.';
    $collection_history_stmt = $conn->prepare(
        "INSERT INTO po_collection_status_history (
            po_id,
            payment_id,
            collection_status_from,
            collection_status_to,
            balance_before,
            balance_after,
            remarks,
            changed_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $collection_history_stmt->bind_param(
        'iissddsi',
        $po_id,
        $payment_id,
        $po['collection_status'],
        $new_collection_status,
        $balance_before,
        $balance_after,
        $history_remarks,
        $user_id
    );
    $collection_history_stmt->execute();
    $collection_history_stmt->close();

    if ($delivery_is_complete && $new_collection_status === 'Paid') {
        complete_po_task_assignment(
            $conn,
            $po_id,
            $user_id,
            'Collection completed through verified client payment'
        );
    }

    $notifications_table = $conn->query(
        "SHOW TABLES LIKE 'notifications'"
    );
    $notification_states_table = $conn->query(
        "SHOW TABLES LIKE 'notification_user_states'"
    );
    if (
        $notifications_table &&
        $notifications_table->num_rows > 0 &&
        $notification_states_table &&
        $notification_states_table->num_rows > 0
    ) {
        $notification_key_column = $conn->query(
            "SHOW COLUMNS FROM notifications LIKE 'notification_key'"
        );
    } else {
        $notification_key_column = false;
    }

    if (
        $delivery_is_complete &&
        $notification_key_column &&
        $notification_key_column->num_rows > 0
    ) {
        $resolved_key_pattern = 'collection:po:' . $po_id . ':%';
        $resolve_stmt = $conn->prepare(
            "UPDATE notification_user_states state
             INNER JOIN notifications notification
                ON notification.notif_id = state.notif_id
             SET
                state.is_read = 1,
                state.read_at = COALESCE(state.read_at, NOW())
             WHERE state.is_deleted = 0
               AND notification.notification_key LIKE ?"
        );
        $resolve_stmt->bind_param('s', $resolved_key_pattern);
        $resolve_stmt->execute();
        $resolve_stmt->close();

        $notification_resolve_stmt = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE notification_key LIKE ?"
        );
        $notification_resolve_stmt->bind_param(
            's',
            $resolved_key_pattern
        );
        $notification_resolve_stmt->execute();
        $notification_resolve_stmt->close();
    }

    log_audit_action(
        $conn,
        $user_id,
        'RECORD_COLLECTION_PAYMENT',
        'Recorded verified ' . strtolower($payment_label) .
            ' of ₱' . number_format($amount_paid, 2) .
            ' for PO ' . $po['po_number'] . '.',
        [
            'operational_status' => $po['status'],
            'collection_status' => $po['collection_status'],
            'balance' => $balance_before,
        ],
        [
            'payment_id' => $payment_id,
            'operational_status' => $po['status'],
            'collection_status' => $new_collection_status,
            'balance' => $balance_after,
            'payment_classification' => $classification,
            'payment_method' => $payment_method,
            'reference_number' => $reference_number,
        ]
    );

    $conn->commit();

    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'success',
        $new_collection_status === 'Paid'
            ? 'Full client payment was verified. Collection status is now Paid; the PO workflow stage was not changed.'
            : 'Client payment was verified. The remaining balance is ₱' .
                number_format($balance_after, 2) . '.'
    );
} catch (Throwable $error) {
    $conn->rollback();

    if (
        $proof_file_path &&
        is_file($payment_directory . $proof_file_path)
    ) {
        unlink($payment_directory . $proof_file_path);
    }

    drms_log_workflow_failure(
        'Client payment recording for PO ' . $po_id,
        $error
    );

    if ($error instanceof DomainException) {
        $public_error = $error->getMessage();
    } else {
        $public_error =
            'The client payment could not be saved. No collection balance was changed.';
    }

    phase5d_payment_redirect(
        $po_id,
        $return_to,
        'error',
        $public_error
    );
}

