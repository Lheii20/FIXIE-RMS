<?php
require 'config/db_connect.php';
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Finance') {
    header('Location: dashboard.php');
    exit();
}

function phase5d_page_date(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($value));
}

function phase5d_page_datetime(?string $value, string $fallback = 'Not recorded'): string
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
$recent_payments = [];
$eligibility_error = '';
$request_error = substr(trim((string) ($_GET['error'] ?? '')), 0, 250);

if ($po_id > 0) {
    try {
        $record_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.amount,
                po.status,
                po.current_location,
                po.date_created,
                po.actual_delivery_date,
                po.expected_collection_date,
                po.source_pr_workflow_version,
                receipt.actual_handover_at,
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
        $record_stmt->close();

        if ($record) {
            $active_assignment = get_active_po_task_assignment($conn, $po_id);

            $payment_stmt = $conn->prepare(
                "SELECT
                    payment.payment_id,
                    payment.amount_paid,
                    payment.payment_date,
                    payment.payment_method,
                    payment.reference_number,
                    recorder.full_name AS recorded_by_name
                 FROM payments payment
                 LEFT JOIN users recorder
                    ON recorder.user_id = payment.recorded_by
                 WHERE payment.po_id = ?
                 ORDER BY payment.payment_date DESC, payment.payment_id DESC
                 LIMIT 3"
            );
            $payment_stmt->bind_param('i', $po_id);
            $payment_stmt->execute();
            $recent_payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $payment_stmt->close();

            $balance_check = max(
                round((float) $record['amount'] - (float) $record['total_paid'], 2),
                0
            );

            if (!in_array($record['status'], ['Delivered', 'Partially-Collected'], true)) {
                $eligibility_error = 'This PO is not an open delivered receivable.';
            } elseif ($balance_check <= 0) {
                $eligibility_error = 'This PO no longer has an outstanding balance.';
            } elseif (!$active_assignment) {
                $eligibility_error = 'This receivable has no active Finance assignment. Open the PO and claim the task first.';
            } elseif ($active_assignment['assigned_role'] !== 'Finance') {
                $eligibility_error = 'This task is currently assigned to ' . $active_assignment['assigned_role'] . '.';
            } elseif ((int) $active_assignment['assigned_to'] !== $current_user_id) {
                $eligibility_error = 'This collection task is assigned to ' . $active_assignment['assignee_name'] . '.';
            }
        }
    } catch (mysqli_sql_exception $error) {
        error_log('Phase 5D collection payment page failed: ' . $error->getMessage());
        $record = null;
        $eligibility_error = 'The collection payment records could not be loaded.';
    }
}

$balance = $record
    ? max(round((float) $record['amount'] - (float) $record['total_paid'], 2), 0)
    : 0;
$collection_due_date = $record
    ? ($record['receipt_due_date'] ?: $record['expected_collection_date'])
    : null;
$collection_started_at = $record
    ? ($record['actual_handover_at'] ?: (
        $record['actual_delivery_date']
            ? $record['actual_delivery_date'] . ' 00:00:00'
            : $record['date_created']
    ))
    : null;

$today = new DateTimeImmutable('today');
$due_key = 'missing';
$due_label = 'Missing due date';
$due_detail = 'Review the delivery record';
if ($collection_due_date && strtotime($collection_due_date) !== false) {
    $due_date = new DateTimeImmutable($collection_due_date);
    $days_until_due = (int) $today->diff($due_date)->format('%r%a');

    if ($days_until_due < 0) {
        $due_key = 'overdue';
        $due_label = 'Overdue';
        $due_detail = abs($days_until_due) . (abs($days_until_due) === 1 ? ' day overdue' : ' days overdue');
    } elseif ($days_until_due === 0) {
        $due_key = 'today';
        $due_label = 'Due today';
        $due_detail = 'Payment is due today';
    } elseif ($days_until_due <= 3) {
        $due_key = 'soon';
        $due_label = 'Due soon';
        $due_detail = 'Due in ' . $days_until_due . ($days_until_due === 1 ? ' day' : ' days');
    } else {
        $due_key = 'track';
        $due_label = 'On track';
        $due_detail = $days_until_due . ' days remaining';
    }
}

$current_datetime = date('Y-m-d\TH:i');
$po_created_input = $record && $record['date_created']
    ? date('Y-m-d\TH:i', strtotime($record['date_created']))
    : $current_datetime;
$delivery_input = $collection_started_at && strtotime($collection_started_at) !== false
    ? date('Y-m-d\TH:i', strtotime($collection_started_at))
    : $current_datetime;
$can_record = $record && $eligibility_error === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Client Payment - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/collection-payment.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-payment.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="prf-page payment-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell payment-shell">
            <header class="prf-page-header">
                <a href="collection_monitoring.php" class="prf-back-button" aria-label="Back to Collection Monitoring">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Finance client collection</div>
                    <h2>Record verified client payment</h2>
                    <p>Confirm the amount, reference, and proof before changing the PO collection balance.</p>
                </div>
                <?php if ($can_record): ?>
                    <span class="prf-workflow-chip payment-chip">
                        <i class="fas fa-shield-check"></i>
                        Assigned to Finance
                    </span>
                <?php endif; ?>
            </header>

            <?php if ($request_error !== ''): ?>
                <div class="prf-alert prf-alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($request_error); ?></span>
                </div>
            <?php endif; ?>

            <div id="paymentValidationMessage" class="prf-alert prf-alert-danger d-none" role="alert" aria-live="polite"></div>

            <?php if (!$record): ?>
                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-database"></i>
                        <div><strong>Payment form is unavailable</strong><span><?php echo htmlspecialchars($eligibility_error); ?></span></div>
                    </section>
                <?php endif; ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-money-check-dollar"></i></div>
                    <h3>No receivable selected</h3>
                    <p>Select an open collection assigned to your Finance account.</p>
                    <a href="collection_monitoring.php?filter=mine" class="btn btn-primary">View my collection tasks</a>
                </section>
            <?php else: ?>
                <section class="prf-source-card payment-source">
                    <div class="prf-source-grid payment-source-grid">
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
                            <strong><?php echo htmlspecialchars(phase5d_page_date($collection_due_date, 'Missing due date')); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Finance owner</span>
                            <strong><?php echo htmlspecialchars((string) ($active_assignment['assignee_name'] ?? 'Unassigned')); ?></strong>
                        </div>
                        <div class="prf-source-action">
                            <a href="view_po.php?id=<?php echo (int) $record['po_id']; ?>" class="prf-document-link">
                                View PO <i class="fas fa-arrow-up-right-from-square"></i>
                            </a>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card payment-route" aria-label="Collection workflow">
                    <span class="prf-route-label">Collection route</span>
                    <div class="prf-route-steps">
                        <div class="prf-route-step payment-complete-step">
                            <span><i class="fas fa-check"></i></span>
                            <div><small>Completed</small><strong>Client delivery</strong></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow" aria-hidden="true"></i>
                        <div class="prf-route-step is-current">
                            <span>2</span>
                            <div><small>Current</small><strong>Payment verification</strong></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow" aria-hidden="true"></i>
                        <div class="prf-route-step">
                            <span>3</span>
                            <div><small>Next</small><strong>Collected</strong></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-warning payment-lock-alert" role="alert">
                        <i class="fas fa-lock"></i>
                        <div><strong>Payment recording is locked</strong><span><?php echo htmlspecialchars($eligibility_error); ?></span></div>
                    </section>
                <?php endif; ?>

                <form
                    action="actions/collection_payment_handler.php"
                    method="POST"
                    enctype="multipart/form-data"
                    id="collectionPaymentForm"
                    class="payment-layout"
                    data-balance="<?php echo htmlspecialchars(number_format($balance, 2, '.', '')); ?>"
                    data-po-created="<?php echo htmlspecialchars($po_created_input); ?>"
                    data-delivered="<?php echo htmlspecialchars($delivery_input); ?>"
                    data-now="<?php echo htmlspecialchars($current_datetime); ?>"
                    novalidate
                >
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <input type="hidden" name="action" value="record_collection_payment">
                    <input type="hidden" name="return_to" value="payment_form">
                    <input type="hidden" name="po_id" value="<?php echo (int) $record['po_id']; ?>">

                    <div class="payment-main">
                        <section class="prf-card">
                            <div class="prf-card-header payment-card-heading">
                                <div>
                                    <span class="prf-section-kicker">Collection position</span>
                                    <h3>Current client balance</h3>
                                </div>
                                <span class="payment-risk-chip payment-risk-<?php echo htmlspecialchars($due_key); ?>">
                                    <?php echo htmlspecialchars($due_label); ?>
                                </span>
                            </div>
                            <div class="payment-context-grid">
                                <div><span>PO amount</span><strong>₱<?php echo number_format((float) $record['amount'], 2); ?></strong></div>
                                <div><span>Previously collected</span><strong>₱<?php echo number_format((float) $record['total_paid'], 2); ?></strong></div>
                                <div class="payment-context-balance"><span>Outstanding</span><strong>₱<?php echo number_format($balance, 2); ?></strong></div>
                                <div><span>Due position</span><strong><?php echo htmlspecialchars($due_detail); ?></strong></div>
                            </div>
                        </section>

                        <section class="prf-card">
                            <div class="prf-card-header">
                                <div>
                                    <span class="prf-section-kicker">Verified receipt</span>
                                    <h3>Payment details</h3>
                                </div>
                                <span class="prf-required-note"><i class="fas fa-asterisk"></i> Required fields</span>
                            </div>

                            <div class="payment-form-content">
                                <fieldset class="payment-type-fieldset" <?php echo $can_record ? '' : 'disabled'; ?>>
                                    <legend>Payment classification <span>*</span></legend>
                                    <div class="payment-type-grid">
                                        <label class="payment-type-option">
                                            <input type="radio" name="payment_classification" value="Full Payment">
                                            <span class="payment-type-icon"><i class="fas fa-circle-check"></i></span>
                                            <span><strong>Full payment</strong><small>Exact remaining balance</small></span>
                                        </label>
                                        <label class="payment-type-option payment-type-option-active">
                                            <input type="radio" name="payment_classification" value="Partial Payment" checked>
                                            <span class="payment-type-icon"><i class="fas fa-chart-pie"></i></span>
                                            <span><strong>Partial payment</strong><small>Balance remains open</small></span>
                                        </label>
                                        <label class="payment-type-option">
                                            <input type="radio" name="payment_classification" value="Advance / Down Payment">
                                            <span class="payment-type-icon"><i class="fas fa-hand-holding-dollar"></i></span>
                                            <span><strong>Advance / down</strong><small>Received before delivery</small></span>
                                        </label>
                                    </div>
                                </fieldset>

                                <div class="payment-field-grid">
                                    <div class="prf-field">
                                        <label for="paymentDate">Payment received <span>*</span></label>
                                        <input type="datetime-local" class="form-control" id="paymentDate" name="payment_date" value="<?php echo htmlspecialchars($current_datetime); ?>" required <?php echo $can_record ? '' : 'disabled'; ?>>
                                        <small class="prf-help-text" id="paymentDateHelp">Use the date and time confirmed by the bank, cheque, or official receipt.</small>
                                    </div>
                                    <div class="prf-field">
                                        <label for="paymentMethod">Payment method <span>*</span></label>
                                        <select class="form-select" id="paymentMethod" name="payment_method" required <?php echo $can_record ? '' : 'disabled'; ?>>
                                            <option value="">Select method</option>
                                            <option value="Bank Transfer">Bank transfer</option>
                                            <option value="Cheque">Cheque</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="prf-field">
                                        <label for="paymentAmount">Amount received <span>*</span></label>
                                        <div class="payment-money-control">
                                            <span>₱</span>
                                            <input type="number" class="form-control" id="paymentAmount" name="amount_paid" min="0.01" max="<?php echo htmlspecialchars(number_format($balance, 2, '.', '')); ?>" step="0.01" inputmode="decimal" placeholder="0.00" required <?php echo $can_record ? '' : 'disabled'; ?>>
                                        </div>
                                        <small class="prf-help-text">Must not exceed ₱<?php echo number_format($balance, 2); ?>.</small>
                                    </div>
                                    <div class="prf-field">
                                        <label for="referenceNumber">Reference / receipt no. <span>*</span></label>
                                        <input type="text" class="form-control" id="referenceNumber" name="reference_number" maxlength="100" placeholder="e.g. TRX-2026-00125" autocomplete="off" required <?php echo $can_record ? '' : 'disabled'; ?>>
                                        <small class="prf-help-text">A duplicate reference for this PO will be rejected.</small>
                                    </div>
                                </div>

                                <div class="payment-proof-panel">
                                    <div class="payment-proof-copy">
                                        <span class="payment-proof-icon"><i class="fas fa-file-shield"></i></span>
                                        <div><strong>Payment proof <span>*</span></strong><small>PDF, JPG, or PNG · maximum 10 MB</small></div>
                                    </div>
                                    <label class="payment-file-picker" for="paymentProof">
                                        <input type="file" id="paymentProof" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required <?php echo $can_record ? '' : 'disabled'; ?>>
                                        <span><i class="fas fa-paperclip"></i> Choose proof</span>
                                        <small id="paymentProofName">No file selected</small>
                                    </label>
                                </div>

                                <div class="prf-field payment-remarks-field">
                                    <label for="paymentRemarks">Verification remarks <small>Optional</small></label>
                                    <textarea class="form-control" id="paymentRemarks" name="payment_remarks" rows="3" maxlength="1000" placeholder="Add the bank, cheque, receipt, or client confirmation details that Finance verified." <?php echo $can_record ? '' : 'disabled'; ?>></textarea>
                                    <div class="payment-remarks-meta"><small>Keep sensitive bank credentials out of the remarks.</small><span id="paymentRemarksCount">0 / 1000</span></div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <aside class="payment-sidebar">
                        <section class="prf-card payment-summary-card">
                            <div class="prf-card-header"><div><span class="prf-section-kicker">Live calculation</span><h3>Settlement summary</h3></div></div>
                            <div class="payment-summary-body">
                                <div class="payment-summary-row"><span>Outstanding</span><strong>₱<?php echo number_format($balance, 2); ?></strong></div>
                                <div class="payment-summary-row"><span>This payment</span><strong id="paymentPreview">₱0.00</strong></div>
                                <div class="payment-summary-row payment-summary-total"><span>Balance after</span><strong id="balanceAfterPreview">₱<?php echo number_format($balance, 2); ?></strong></div>
                                <div class="payment-status-preview" id="paymentStatusPreview"><i class="fas fa-circle-info"></i><span>Enter a payment amount to preview the PO status.</span></div>
                            </div>
                        </section>

                        <?php if (!empty($recent_payments)): ?>
                            <section class="prf-card payment-history-card">
                                <div class="prf-card-header"><div><span class="prf-section-kicker">Audit context</span><h3>Recent payments</h3></div></div>
                                <div class="payment-history-list">
                                    <?php foreach ($recent_payments as $payment): ?>
                                        <div class="payment-history-item">
                                            <div><strong>₱<?php echo number_format((float) $payment['amount_paid'], 2); ?></strong><span><?php echo htmlspecialchars($payment['payment_method']); ?></span></div>
                                            <small><?php echo htmlspecialchars(phase5d_page_datetime($payment['payment_date'])); ?><br><?php echo htmlspecialchars($payment['reference_number']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <section class="prf-card payment-confirm-card">
                            <div class="payment-boundary-note">
                                <i class="fas fa-circle-exclamation"></i>
                                <p><strong>Final balance control</strong><span>Submitting creates an auditable payment record. A zero balance automatically marks the PO as Collected.</span></p>
                            </div>
                            <label class="payment-confirmation">
                                <input type="checkbox" name="payment_confirmation" value="1" id="paymentConfirmation" <?php echo $can_record ? '' : 'disabled'; ?>>
                                <span>I verified the client payment amount, reference number, date, and attached proof.</span>
                            </label>
                            <button type="submit" class="btn payment-submit-button" id="paymentSubmitButton" <?php echo $can_record ? '' : 'disabled'; ?>>
                                <span>Record verified payment</span><i class="fas fa-arrow-right"></i>
                            </button>
                            <a href="collection_monitoring.php?filter=mine" class="payment-cancel-link">Cancel and return to monitoring</a>
                        </section>
                    </aside>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <?php if ($record): ?>
        <script src="assets/js/collection-payment.js?v=<?php echo filemtime(__DIR__ . '/assets/js/collection-payment.js'); ?>"></script>
    <?php endif; ?>
</body>
</html>
