<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

function phase5b_redirect(
    int $po_id,
    string $type,
    string $message
): void {
    $destination = $po_id > 0
        ? '../collection_followup.php?po_id=' . $po_id
        : '../collection_monitoring.php';
    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The collection follow-up could not be saved. No collection data was changed.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($destination, $type, $public_message);
}

function phase5b_parse_datetime(string $value): ?DateTime
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

function phase5b_parse_date(string $value): ?DateTime
{
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $has_errors = is_array($errors) &&
        ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (
        !$date ||
        $has_errors ||
        $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $date;
}

function phase5b_text_is_valid(
    ?string $value,
    int $maximum,
    bool $required = false,
    int $minimum = 0
): bool {
    $text = trim((string) $value);
    if ($required && $text === '') {
        return false;
    }

    return strlen($text) >= $minimum && strlen($text) <= $maximum;
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
$session_token = (string) ($_SESSION['csrf_token'] ?? '');
$posted_token = (string) ($_POST['csrf_token'] ?? '');

if (
    $session_token === '' ||
    $posted_token === '' ||
    !hash_equals($session_token, $posted_token)
) {
    phase5b_redirect(
        $po_id,
        'error',
        'Security token validation failed. Refresh the form and try again.'
    );
}

if ($action !== 'record_collection_followup') {
    phase5b_redirect($po_id, 'error', 'Invalid collection follow-up action.');
}

if (($_SESSION['role'] ?? '') !== 'Finance') {
    phase5b_redirect(
        $po_id,
        'error',
        'Only Finance can record client collection follow-ups.'
    );
}

if ($po_id < 1) {
    phase5b_redirect(0, 'error', 'Select a valid open receivable.');
}

$contact_attempted_input = trim(
    (string) ($_POST['contact_attempted_at'] ?? '')
);
$contact_channel = trim((string) ($_POST['contact_channel'] ?? ''));
$contact_person = trim((string) ($_POST['contact_person'] ?? ''));
$contact_detail = trim((string) ($_POST['contact_detail'] ?? ''));
$followup_outcome = trim((string) ($_POST['followup_outcome'] ?? ''));
$commitment_amount_input = trim(
    (string) ($_POST['commitment_amount'] ?? '')
);
$promised_payment_input = trim(
    (string) ($_POST['promised_payment_date'] ?? '')
);
$next_followup_input = trim(
    (string) ($_POST['next_followup_date'] ?? '')
);
$followup_notes = trim((string) ($_POST['followup_notes'] ?? ''));
$confirmed = isset($_POST['followup_confirmation']) &&
    $_POST['followup_confirmation'] === '1';

$allowed_channels = [
    'Phone Call',
    'Email',
    'SMS',
    'Messaging App',
    'Client Portal',
    'In Person',
    'Other',
];
$allowed_outcomes = [
    'Promise to Pay',
    'Payment Processing',
    'Requested Documents',
    'Dispute or Concern',
    'No Response',
    'Unable to Reach',
    'Other',
];

$contact_attempted_at = phase5b_parse_datetime(
    $contact_attempted_input
);
if (
    !$contact_attempted_at ||
    $contact_attempted_at > new DateTime('now')
) {
    phase5b_redirect(
        $po_id,
        'error',
        'Enter a valid non-future client contact date and time.'
    );
}

if (!in_array($contact_channel, $allowed_channels, true)) {
    phase5b_redirect($po_id, 'error', 'Select a valid contact channel.');
}

if (!in_array($followup_outcome, $allowed_outcomes, true)) {
    phase5b_redirect($po_id, 'error', 'Select a valid follow-up outcome.');
}

if (
    !phase5b_text_is_valid($contact_person, 150, true) ||
    !phase5b_text_is_valid($contact_detail, 150) ||
    !phase5b_text_is_valid($followup_notes, 2000, true, 10)
) {
    phase5b_redirect(
        $po_id,
        'error',
        'Complete the client contact and follow-up notes within the allowed lengths.'
    );
}

$next_followup_date = phase5b_parse_date($next_followup_input);
if (!$next_followup_date) {
    phase5b_redirect($po_id, 'error', 'Select the next follow-up date.');
}

$contact_date = (clone $contact_attempted_at)->setTime(0, 0);
$today_date = new DateTime('today');
$latest_allowed_followup = (clone $today_date)->modify('+365 days');
if (
    $next_followup_date < $contact_date ||
    $next_followup_date < $today_date ||
    $next_followup_date > $latest_allowed_followup
) {
    phase5b_redirect(
        $po_id,
        'error',
        'Next follow-up must be today or later and within one year.'
    );
}

$commitment_amount = null;
$promised_payment_date = null;
if ($followup_outcome === 'Promise to Pay') {
    if (
        $commitment_amount_input === '' ||
        !is_numeric($commitment_amount_input)
    ) {
        phase5b_redirect(
            $po_id,
            'error',
            'Enter the amount committed by the client.'
        );
    }

    $commitment_amount = round((float) $commitment_amount_input, 2);
    $promised_payment_date = phase5b_parse_date(
        $promised_payment_input
    );
    if ($commitment_amount <= 0 || !$promised_payment_date) {
        phase5b_redirect(
            $po_id,
            'error',
            'Enter a valid promised amount and payment date.'
        );
    }

    if ($promised_payment_date < $contact_date) {
        phase5b_redirect(
            $po_id,
            'error',
            'Promised payment date cannot be earlier than the client contact date.'
        );
    }
}

if (!$confirmed) {
    phase5b_redirect(
        $po_id,
        'error',
        'Confirm that the collection follow-up details are accurate.'
    );
}

$user_id = (int) $_SESSION['user_id'];

try {
    $conn->begin_transaction();

    $po_stmt = $conn->prepare(
        "SELECT
            po.po_number,
            po.client_name,
            po.amount,
            po.status,
            po.collection_status,
            COALESCE(
                NULLIF(receipt.actual_handover_at, ''),
                CONCAT(NULLIF(po.actual_delivery_date, ''), ' 00:00:00'),
                (
                    SELECT MIN(history.timestamp)
                    FROM po_history history
                    WHERE history.po_id = po.po_id
                      AND history.status_to = 'Delivered'
                )
            ) AS collection_started_at
         FROM purchase_orders po
         LEFT JOIN po_delivery_receipts receipt
            ON receipt.delivery_receipt_id = (
                SELECT MAX(latest_receipt.delivery_receipt_id)
                FROM po_delivery_receipts latest_receipt
                WHERE latest_receipt.po_id = po.po_id
                  AND latest_receipt.record_status = 'Active'
            )
         WHERE po.po_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();

    if (
        !$po ||
        $po['status'] !== 'Delivered' ||
        $po['collection_status'] === 'Paid'
    ) {
        throw new DomainException(
            'This PO is no longer open for collection follow-up.'
        );
    }

    if (empty($po['collection_started_at'])) {
        throw new DomainException(
            'The delivery completion timestamp is missing. Complete or correct the client delivery record first.'
        );
    }

    try {
        enforce_po_task_ownership($conn, $po_id, $user_id, 'Finance');
    } catch (Throwable $ownership_error) {
        throw new DomainException($ownership_error->getMessage());
    }

    $assignment = get_active_po_task_assignment($conn, $po_id, true);
    if (
        !$assignment ||
        $assignment['assigned_role'] !== 'Finance' ||
        (int) $assignment['assigned_to'] !== $user_id
    ) {
        throw new DomainException(
            'This receivable must be assigned to you before recording a follow-up.'
        );
    }

    $collection_started_at = new DateTime($po['collection_started_at']);
    if ($contact_attempted_at < $collection_started_at) {
        throw new DomainException(
            'Client collection contact cannot be earlier than the recorded delivery.'
        );
    }

    $payment_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
         FROM payments
         WHERE po_id = ?"
    );
    $payment_stmt->bind_param('i', $po_id);
    $payment_stmt->execute();
    $total_paid = round(
        (float) $payment_stmt->get_result()->fetch_assoc()['total_paid'],
        2
    );
    $balance = max(round((float) $po['amount'] - $total_paid, 2), 0);

    if ($balance <= 0.01) {
        throw new DomainException(
            'This PO no longer has an outstanding collection balance.'
        );
    }

    if (
        $commitment_amount !== null &&
        $commitment_amount > $balance + 0.01
    ) {
        throw new DomainException(
            'The promised amount cannot exceed the outstanding balance of ₱' .
            number_format($balance, 2) . '.'
        );
    }

    $cycle_stmt = $conn->prepare(
        "SELECT COALESCE(MAX(followup_cycle), 0) + 1 AS next_cycle
         FROM po_collection_followups
         WHERE po_id = ?
         FOR UPDATE"
    );
    $cycle_stmt->bind_param('i', $po_id);
    $cycle_stmt->execute();
    $followup_cycle = (int) (
        $cycle_stmt->get_result()->fetch_assoc()['next_cycle'] ?? 1
    );

    $contact_attempted_sql = $contact_attempted_at->format(
        'Y-m-d H:i:s'
    );
    $promised_payment_sql = $promised_payment_date
        ? $promised_payment_date->format('Y-m-d')
        : null;
    $next_followup_sql = $next_followup_date->format('Y-m-d');
    $contact_detail = $contact_detail !== '' ? $contact_detail : null;

    $insert_stmt = $conn->prepare(
        "INSERT INTO po_collection_followups (
            po_id,
            followup_cycle,
            contact_attempted_at,
            contact_channel,
            contact_person,
            contact_detail,
            followup_outcome,
            commitment_amount,
            promised_payment_date,
            next_followup_date,
            followup_notes,
            recorded_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insert_stmt->bind_param(
        'iisssssdsssi',
        $po_id,
        $followup_cycle,
        $contact_attempted_sql,
        $contact_channel,
        $contact_person,
        $contact_detail,
        $followup_outcome,
        $commitment_amount,
        $promised_payment_sql,
        $next_followup_sql,
        $followup_notes,
        $user_id
    );
    $insert_stmt->execute();
    $followup_id = (int) $conn->insert_id;

    if ($followup_id < 1) {
        throw new RuntimeException(
            'The collection follow-up record could not be saved.'
        );
    }

    $notification_key_column = $conn->query(
        "SHOW COLUMNS FROM notifications LIKE 'notification_key'"
    );
    if (
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
             WHERE state.user_id = ?
               AND state.is_deleted = 0
               AND notification.notification_key LIKE ?"
        );
        $resolve_stmt->bind_param(
            'is',
            $user_id,
            $resolved_key_pattern
        );
        $resolve_stmt->execute();
        $resolve_stmt->close();
    }

    $audit_description = 'Recorded collection follow-up #' .
        $followup_cycle . ' for PO ' . $po['po_number'] .
        '. Outcome: ' . $followup_outcome .
        '. Next follow-up: ' . $next_followup_sql . '.';
    log_audit_action(
        $conn,
        $user_id,
        'RECORD_COLLECTION_FOLLOWUP',
        $audit_description,
        null,
        [
            'followup_id' => $followup_id,
            'po_id' => $po_id,
            'outcome' => $followup_outcome,
            'commitment_amount' => $commitment_amount,
            'promised_payment_date' => $promised_payment_sql,
            'next_followup_date' => $next_followup_sql,
            'outstanding_balance' => $balance,
            'collection_status' => $po['collection_status'],
        ]
    );

    $conn->commit();
    header(
        'Location: ../collection_monitoring.php?success=' .
        rawurlencode(
            'Collection follow-up for PO ' . $po['po_number'] .
            ' was recorded. Next follow-up is ' .
            date('M d, Y', strtotime($next_followup_sql)) . '.'
        )
    );
    exit();
} catch (Throwable $error) {
    $conn->rollback();

    drms_log_workflow_failure(
        'Collection follow-up for PO ' . $po_id,
        $error
    );

    if ($error instanceof DomainException) {
        $public_error = $error->getMessage();
    } else {
        $public_error =
            'The collection follow-up could not be saved. No collection data was changed.';
    }

    phase5b_redirect($po_id, 'error', $public_error);
}

