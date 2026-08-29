<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Finance') {
    header('Location: dashboard.php');
    exit();
}

function phase5b_page_date(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($value));
}

function phase5b_page_datetime(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y · g:i A', strtotime($value));
}

$po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$current_user_id = (int) $_SESSION['user_id'];
$record = null;
$active_assignment = null;
$latest_followup = null;
$eligibility_error = '';
$request_error = drms_public_feedback_message(
    $_GET['error'] ?? '',
    'The collection follow-up could not be recorded. No collection data was changed.'
);

if ($po_id > 0) {
    try {
        $record_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.amount,
                po.status,
                po.collection_status,
                po.current_location,
                po.expected_collection_date,
                COALESCE(
                    NULLIF(receipt.actual_handover_at, ''),
                    CONCAT(NULLIF(po.actual_delivery_date, ''), ' 00:00:00'),
                    (
                        SELECT MIN(history.timestamp)
                        FROM po_history history
                        WHERE history.po_id = po.po_id
                          AND history.status_to = 'Delivered'
                    )
                ) AS collection_started_at,
                receipt.collection_due_date AS receipt_due_date,
                receipt.recipient_name,
                COALESCE(payment_summary.total_paid, 0) AS total_paid,
                COALESCE(payment_summary.payment_count, 0) AS payment_count,
                payment_summary.last_payment_at
             FROM purchase_orders po
             LEFT JOIN po_delivery_receipts receipt
                ON receipt.delivery_receipt_id = (
                    SELECT MAX(latest_receipt.delivery_receipt_id)
                    FROM po_delivery_receipts latest_receipt
                    WHERE latest_receipt.po_id = po.po_id
                      AND latest_receipt.record_status = 'Active'
                )
             LEFT JOIN (
                SELECT
                    po_id,
                    SUM(amount_paid) AS total_paid,
                    COUNT(*) AS payment_count,
                    MAX(payment_date) AS last_payment_at
                FROM payments
                GROUP BY po_id
             ) payment_summary
                ON payment_summary.po_id = po.po_id
             WHERE po.po_id = ?
             LIMIT 1"
        );
        $record_stmt->bind_param('i', $po_id);
        $record_stmt->execute();
        $record = $record_stmt->get_result()->fetch_assoc();

        if ($record) {
            $active_assignment = get_active_po_task_assignment(
                $conn,
                $po_id
            );

            $latest_stmt = $conn->prepare(
                "SELECT
                    followup.*,
                    recorder.full_name AS recorded_by_name
                 FROM po_collection_followups followup
                 LEFT JOIN users recorder
                    ON recorder.user_id = followup.recorded_by
                 WHERE followup.po_id = ?
                   AND followup.record_status = 'Active'
                 ORDER BY followup.followup_cycle DESC
                 LIMIT 1"
            );
            $latest_stmt->bind_param('i', $po_id);
            $latest_stmt->execute();
            $latest_followup = $latest_stmt->get_result()->fetch_assoc();

            $balance = max(
                round(
                    (float) $record['amount'] -
                    (float) $record['total_paid'],
                    2
                ),
                0
            );

            if (
                $record['status'] !== 'Delivered' ||
                $record['collection_status'] === 'Paid'
            ) {
                $eligibility_error =
                    'This PO is no longer open for collection follow-up.';
            } elseif (empty($record['collection_started_at'])) {
                $eligibility_error =
                    'The delivery completion timestamp is missing. Complete or correct the client delivery record first.';
            } elseif ($balance <= 0.01) {
                $eligibility_error =
                    'This PO no longer has an outstanding balance.';
            } elseif (!$active_assignment) {
                $eligibility_error =
                    'No active Finance user is assigned to this receivable. Return to Collection Monitoring and refresh the task list.';
            } elseif ($active_assignment['assigned_role'] !== 'Finance') {
                $eligibility_error =
                    'This task is currently assigned to ' .
                    $active_assignment['assigned_role'] . '.';
            } elseif ((int) $active_assignment['assigned_to'] !==
                $current_user_id) {
                $eligibility_error =
                    'This collection task is assigned to ' .
                    $active_assignment['assignee_name'] . '.';
            }
        }
    } catch (Throwable $error) {
        drms_log_workflow_failure('Collection follow-up page load', $error);
        $record = null;
        $eligibility_error =
            'The collection follow-up details could not be loaded. Return to Collection Monitoring and try again.';
    }
}

$balance = $record
    ? max(
        round(
            (float) $record['amount'] - (float) $record['total_paid'],
            2
        ),
        0
    )
    : 0;
$collection_due_date = $record
    ? ($record['receipt_due_date'] ?: $record['expected_collection_date'])
    : null;
$today = new DateTimeImmutable('today');
$due_label = 'Missing due date';
$due_detail = 'Review the client delivery record';
$due_key = 'missing';

if ($collection_due_date && strtotime($collection_due_date) !== false) {
    $due_date = new DateTimeImmutable($collection_due_date);
    $days_until_due = (int) $today->diff($due_date)->format('%r%a');

    if ($days_until_due < 0) {
        $due_key = 'overdue';
        $due_label = 'Overdue';
        $due_detail = abs($days_until_due) .
            (abs($days_until_due) === 1 ? ' day overdue' : ' days overdue');
    } elseif ($days_until_due === 0) {
        $due_key = 'today';
        $due_label = 'Due today';
        $due_detail = 'Client payment is due today';
    } elseif ($days_until_due <= 3) {
        $due_key = 'soon';
        $due_label = 'Due soon';
        $due_detail = 'Due in ' . $days_until_due .
            ($days_until_due === 1 ? ' day' : ' days');
    } else {
        $due_key = 'track';
        $due_label = 'On track';
        $due_detail = $days_until_due . ' days remaining';
    }
}

$initial_next_followup = (new DateTimeImmutable('today +3 days'));
if ($collection_due_date && strtotime($collection_due_date) !== false) {
    $contract_due = new DateTimeImmutable($collection_due_date);
    if ($contract_due >= $today && $contract_due < $initial_next_followup) {
        $initial_next_followup = $contract_due;
    } elseif ($contract_due < $today) {
        $initial_next_followup = new DateTimeImmutable('tomorrow');
    }
}

$current_datetime = date('Y-m-d\TH:i');
$can_record = $record && $eligibility_error === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Collection Follow-up - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/collection-followup.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-followup.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="prf-page followup-page workflow-ui">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell followup-shell">
            <header class="prf-page-header">
                <a
                    href="collection_monitoring.php"
                    class="prf-back-button"
                    aria-label="Back to Collection Monitoring"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Finance client collection</div>
                    <h2>Record collection follow-up</h2>
                    <p>Document the client contact result and schedule the next action without changing the payment balance.</p>
                </div>

                <?php if ($can_record): ?>
                    <span class="prf-workflow-chip followup-chip">
                        <i class="fas fa-comments-dollar"></i>
                        Assigned collection
                    </span>
                <?php endif; ?>
            </header>

            <?php if ($request_error !== ''): ?>
                <div class="prf-alert prf-alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($request_error); ?></span>
                </div>
            <?php endif; ?>

            <div
                id="followupValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$record): ?>
                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div>
                            <strong>Collection follow-up is unavailable</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php endif; ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-comments-dollar"></i></div>
                    <h3>No receivable selected</h3>
                    <p>Select an open collection assigned to your Finance account.</p>
                    <a href="collection_monitoring.php?filter=mine" class="btn btn-primary">
                        View my collection tasks
                    </a>
                </section>
            <?php else: ?>
                <section class="prf-source-card followup-source">
                    <div class="prf-source-grid followup-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>Purchase Order</span>
                            <strong><?php echo htmlspecialchars($record['po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($record['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Outstanding</span>
                            <strong>₱<?php echo number_format($balance, 2); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Collection due</span>
                            <strong><?php echo htmlspecialchars(phase5b_page_date($collection_due_date, 'Missing due date')); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Finance owner</span>
                            <strong><?php echo htmlspecialchars((string) ($active_assignment['assignee_name'] ?? 'Unassigned')); ?></strong>
                        </div>
                        <div class="prf-source-action">
                            <a href="view_po.php?id=<?php echo $po_id; ?>" class="prf-document-link">
                                <i class="fas fa-eye"></i>
                                View PO
                            </a>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card followup-route" aria-label="Collection route">
                    <div class="prf-route-label">Current route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step followup-complete-step">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Client delivery</strong><small>Completed</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step is-current">
                            <span>2</span>
                            <div><strong>Collection follow-up</strong><small>Current · Finance</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>3</span>
                            <div><strong>Payment recording</strong><small>When received</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>4</span>
                            <div><strong>Collection closed</strong><small>Full payment</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Follow-up entry is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/collection_followup_handler.php"
                        method="POST"
                        id="collectionFollowupForm"
                        novalidate
                    >
                        <input type="hidden" name="action" value="record_collection_followup">
                        <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >

                        <div class="followup-layout">
                            <div class="followup-main">
                                <section class="prf-card followup-context-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Collection context</span>
                                            <h3>Receivable position</h3>
                                        </div>
                                        <span class="followup-risk-chip followup-risk-<?php echo htmlspecialchars($due_key); ?>">
                                            <?php echo htmlspecialchars($due_label); ?>
                                        </span>
                                    </div>

                                    <div class="followup-context-grid">
                                        <div>
                                            <span>Original receivable</span>
                                            <strong>₱<?php echo number_format((float) $record['amount'], 2); ?></strong>
                                        </div>
                                        <div>
                                            <span>Payments received</span>
                                            <strong>₱<?php echo number_format((float) $record['total_paid'], 2); ?></strong>
                                        </div>
                                        <div class="followup-context-balance">
                                            <span>Remaining balance</span>
                                            <strong>₱<?php echo number_format($balance, 2); ?></strong>
                                        </div>
                                        <div>
                                            <span>Due status</span>
                                            <strong><?php echo htmlspecialchars($due_detail); ?></strong>
                                        </div>
                                    </div>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Client contact record</span>
                                            <h3>Attempt, result, and next action</h3>
                                        </div>
                                        <span class="prf-required-note"><span>*</span> Required fields</span>
                                    </div>

                                    <div class="prf-form-grid followup-form-grid">
                                        <div class="prf-field">
                                            <label for="contactAttemptedAt">Contact date and time <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="contact_attempted_at"
                                                id="contactAttemptedAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($current_datetime); ?>"
                                                max="<?php echo htmlspecialchars($current_datetime); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="contactChannel">Contact channel <span>*</span></label>
                                            <select name="contact_channel" id="contactChannel" class="form-select" required>
                                                <option value="" selected disabled>Select channel</option>
                                                <option value="Phone Call">Phone call</option>
                                                <option value="Email">Email</option>
                                                <option value="SMS">SMS</option>
                                                <option value="Messaging App">Messaging app</option>
                                                <option value="Client Portal">Client portal</option>
                                                <option value="In Person">In person</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>

                                        <div class="prf-field">
                                            <label for="contactPerson">Contact person or office <span>*</span></label>
                                            <input
                                                type="text"
                                                name="contact_person"
                                                id="contactPerson"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="e.g. Juan Dela Cruz · Accounting Office"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="contactDetail">Contact detail <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="contact_detail"
                                                id="contactDetail"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="Phone number, email, or account used"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="followupOutcome">Follow-up outcome <span>*</span></label>
                                            <select name="followup_outcome" id="followupOutcome" class="form-select" required>
                                                <option value="" selected disabled>Select result</option>
                                                <option value="Promise to Pay">Promise to pay</option>
                                                <option value="Payment Processing">Payment is processing</option>
                                                <option value="Requested Documents">Requested documents</option>
                                                <option value="Dispute or Concern">Dispute or concern</option>
                                                <option value="No Response">No response</option>
                                                <option value="Unable to Reach">Unable to reach</option>
                                                <option value="Other">Other result</option>
                                            </select>
                                        </div>

                                        <div class="prf-field">
                                            <label for="nextFollowupDate">Next follow-up date <span>*</span></label>
                                            <input
                                                type="date"
                                                name="next_followup_date"
                                                id="nextFollowupDate"
                                                class="form-control"
                                                value="<?php echo $initial_next_followup->format('Y-m-d'); ?>"
                                                min="<?php echo date('Y-m-d'); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-conditional-group prf-span-2 followup-promise-panel" data-promise-panel hidden>
                                            <div class="followup-promise-heading">
                                                <i class="fas fa-handshake"></i>
                                                <div>
                                                    <strong>Client payment commitment</strong>
                                                    <span>Required when the client promises a specific payment.</span>
                                                </div>
                                            </div>
                                            <div class="followup-promise-grid">
                                                <div class="prf-field">
                                                    <label for="commitmentAmount">Promised amount <span>*</span></label>
                                                    <div class="followup-money-control">
                                                        <span>₱</span>
                                                        <input
                                                            type="number"
                                                            name="commitment_amount"
                                                            id="commitmentAmount"
                                                            class="form-control"
                                                            min="0.01"
                                                            max="<?php echo number_format($balance, 2, '.', ''); ?>"
                                                            step="0.01"
                                                            data-outstanding-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                                            placeholder="0.00"
                                                            disabled
                                                        >
                                                    </div>
                                                    <small class="prf-help-text">Cannot exceed the current outstanding balance.</small>
                                                </div>
                                                <div class="prf-field">
                                                    <label for="promisedPaymentDate">Promised payment date <span>*</span></label>
                                                    <input
                                                        type="date"
                                                        name="promised_payment_date"
                                                        id="promisedPaymentDate"
                                                        class="form-control"
                                                        min="<?php echo date('Y-m-d'); ?>"
                                                        disabled
                                                    >
                                                </div>
                                            </div>
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="followupNotes">Follow-up notes <span>*</span></label>
                                            <textarea
                                                name="followup_notes"
                                                id="followupNotes"
                                                class="form-control"
                                                rows="4"
                                                minlength="10"
                                                maxlength="2000"
                                                placeholder="Summarize what was discussed, client response, requested action, and any relevant concern"
                                                required
                                            ></textarea>
                                            <div class="followup-notes-meta">
                                                <small>Use objective, business-relevant details only.</small>
                                                <small><span data-followup-note-count>0</span> / 2000</small>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card followup-summary" aria-label="Collection follow-up summary">
                                <div class="prf-summary-heading">
                                    <span>Follow-up summary</span>
                                    <small>Finance action</small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>Outstanding</span>
                                    <strong>₱<?php echo number_format($balance, 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Contract due</span>
                                    <strong><?php echo htmlspecialchars(phase5b_page_date($collection_due_date, 'Missing')); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Next follow-up</span>
                                    <strong data-next-followup-preview><?php echo $initial_next_followup->format('M d, Y'); ?></strong>
                                </div>

                                <?php if ($latest_followup): ?>
                                    <div class="followup-latest-card">
                                        <span>Latest contact</span>
                                        <strong><?php echo htmlspecialchars($latest_followup['followup_outcome']); ?></strong>
                                        <small><?php echo htmlspecialchars(phase5b_page_datetime($latest_followup['contact_attempted_at'])); ?></small>
                                        <small>Next: <?php echo htmlspecialchars(phase5b_page_date($latest_followup['next_followup_date'])); ?></small>
                                    </div>
                                <?php else: ?>
                                    <div class="followup-latest-card is-empty">
                                        <i class="fas fa-comment-slash"></i>
                                        <strong>No previous follow-up</strong>
                                        <small>This will be the first recorded collection contact.</small>
                                    </div>
                                <?php endif; ?>

                                <div class="followup-boundary">
                                    <i class="fas fa-info-circle"></i>
                                    <span>This entry records a contact attempt only. It does not post a payment or extend the contractual due date.</span>
                                </div>

                                <label class="followup-confirmation" for="followupConfirmation">
                                    <input
                                        type="checkbox"
                                        name="followup_confirmation"
                                        value="1"
                                        id="followupConfirmation"
                                        required
                                    >
                                    <span>I confirm that this client contact and its outcome are accurately recorded.</span>
                                </label>

                                <button type="submit" class="prf-submit-button followup-submit" data-followup-submit>
                                    <span>Save Follow-up</span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </aside>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/collection-followup.js?v=<?php echo filemtime(__DIR__ . '/assets/js/collection-followup.js'); ?>"></script>
</body>
</html>
