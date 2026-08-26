<?php
require 'config/db_connect.php';
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$allowed_roles = ['Finance', 'GM', 'President'];
$current_role = (string) ($_SESSION['role'] ?? '');
if (!in_array($current_role, $allowed_roles, true)) {
    header('Location: dashboard.php');
    exit();
}

function phase5g_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function phase5g_date(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('F d, Y', strtotime($value));
}

function phase5g_datetime(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y · g:i A', strtotime($value));
}

function phase5g_payment_label(?string $notes): string
{
    $notes = trim((string) $notes);
    if (stripos($notes, 'Advance / Down Payment') !== false) {
        return 'Advance / down payment';
    }
    if (stripos($notes, 'Full Payment') === 0) {
        return 'Full payment';
    }
    if (stripos($notes, 'Partial Payment') === 0) {
        return 'Partial payment';
    }

    return 'Legacy payment';
}

$po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$record = null;
$payments = [];
$page_error = '';
$generated_by = (string) ($_SESSION['full_name'] ?? 'Authorized user');

if ($po_id > 0) {
    try {
        $user_stmt = $conn->prepare(
            "SELECT full_name FROM users WHERE user_id = ? LIMIT 1"
        );
        $current_user_id = (int) $_SESSION['user_id'];
        $user_stmt->bind_param('i', $current_user_id);
        $user_stmt->execute();
        $user_record = $user_stmt->get_result()->fetch_assoc();
        $user_stmt->close();
        if ($user_record && trim((string) $user_record['full_name']) !== '') {
            $generated_by = $user_record['full_name'];
        }

        $record_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.quotation_number,
                po.amount,
                po.status,
                po.date_created,
                po.actual_delivery_date,
                po.expected_collection_date,
                receipt.actual_handover_at,
                receipt.client_receipt_reference,
                receipt.acknowledgement_type,
                receipt.recipient_name,
                receipt.recipient_position,
                receipt.recipient_contact,
                receipt.collection_term_days,
                receipt.collection_due_date,
                delivery_request.delivery_address,
                COALESCE(payment_summary.total_paid, 0) AS ledger_paid,
                COALESCE(payment_summary.payment_count, 0) AS payment_count,
                payment_summary.last_payment_at
             FROM purchase_orders po
             LEFT JOIN po_delivery_receipts receipt
                ON receipt.delivery_receipt_id = (
                    SELECT MAX(receipt_candidate.delivery_receipt_id)
                    FROM po_delivery_receipts receipt_candidate
                    WHERE receipt_candidate.po_id = po.po_id
                      AND receipt_candidate.record_status = 'Active'
                )
             LEFT JOIN po_delivery_requests delivery_request
                ON delivery_request.delivery_request_id =
                    receipt.delivery_request_id
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

        if ($record && !in_array(
            $record['status'],
            ['Delivered', 'Partially-Collected', 'Collected'],
            true
        )) {
            $page_error = 'A collection statement is available only after client delivery.';
            $record = null;
        }

        if ($record) {
            $payment_stmt = $conn->prepare(
                "SELECT
                    payment.payment_id,
                    payment.amount_paid,
                    payment.payment_date,
                    payment.notes,
                    payment.payment_method,
                    payment.reference_number,
                    recorder.full_name AS recorded_by_name
                 FROM payments payment
                 LEFT JOIN users recorder
                    ON recorder.user_id = payment.recorded_by
                 WHERE payment.po_id = ?
                 ORDER BY payment.payment_date ASC, payment.payment_id ASC"
            );
            $payment_stmt->bind_param('i', $po_id);
            $payment_stmt->execute();
            $payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $payment_stmt->close();
        }
    } catch (mysqli_sql_exception $error) {
        error_log('Phase 5G collection statement failed: ' . $error->getMessage());
        $record = null;
        $page_error = 'The collection statement could not be prepared from the current records.';
    }
}

$statement_date = new DateTimeImmutable('today');
$po_amount = $record ? round((float) $record['amount'], 2) : 0;
$ledger_paid = $record ? round((float) $record['ledger_paid'], 2) : 0;
$effective_collected = $record && $record['status'] === 'Collected'
    ? $po_amount
    : min($ledger_paid, $po_amount);
$legacy_settlement = max(round($effective_collected - $ledger_paid, 2), 0);
$balance = max(round($po_amount - $effective_collected, 2), 0);
$due_date_value = $record
    ? ($record['collection_due_date'] ?: $record['expected_collection_date'])
    : null;
$delivery_date_value = $record
    ? ($record['actual_handover_at'] ?: $record['actual_delivery_date'])
    : null;
$term_days = $record && $record['collection_term_days']
    ? (int) $record['collection_term_days']
    : 15;

$due_key = 'missing';
$due_label = 'Due date not recorded';
$due_message = 'Please coordinate with Fixie Finance to confirm the applicable payment deadline.';
if ($record && $balance <= 0) {
    $due_key = 'settled';
    $due_label = 'Fully settled';
    $due_message = 'No outstanding amount remains as of the statement date.';
} elseif ($due_date_value && strtotime($due_date_value) !== false) {
    $due_date = new DateTimeImmutable($due_date_value);
    $days_until_due = (int) $statement_date->diff($due_date)->format('%r%a');
    if ($days_until_due < 0) {
        $due_key = 'overdue';
        $due_label = abs($days_until_due) .
            (abs($days_until_due) === 1 ? ' day overdue' : ' days overdue');
        $due_message = 'This balance is past its recorded payment deadline. Please coordinate payment with Fixie Finance immediately.';
    } elseif ($days_until_due === 0) {
        $due_key = 'today';
        $due_label = 'Due today';
        $due_message = 'The remaining balance is due today.';
    } else {
        $due_key = $days_until_due <= 3 ? 'soon' : 'current';
        $due_label = 'Due in ' . $days_until_due .
            ($days_until_due === 1 ? ' day' : ' days');
        $due_message = 'Please arrange payment on or before ' .
            phase5g_date($due_date_value) . '.';
    }
}

$statement_number = $record
    ? 'SOA-' . preg_replace('/[^A-Za-z0-9]/', '', $record['po_number']) .
        '-' . date('Ymd')
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collection Statement<?php echo $record ? ' - ' . htmlspecialchars($record['po_number']) : ''; ?></title>
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/collection-statement.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-statement.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="statement-page">
    <div class="statement-toolbar" role="toolbar" aria-label="Statement actions">
        <div>
            <a href="<?php echo $record ? 'view_po.php?id=' . (int) $record['po_id'] : 'collection_monitoring.php'; ?>"><i class="fas fa-arrow-left"></i>Back</a>
            <span>Review the statement before printing or saving it as PDF.</span>
        </div>
        <?php if ($record): ?>
            <button type="button" onclick="window.print()"><i class="fas fa-print"></i>Print / Save PDF</button>
        <?php endif; ?>
    </div>

    <?php if (!$record): ?>
        <main class="statement-empty">
            <span><i class="fas fa-file-circle-exclamation"></i></span>
            <h1>Collection statement unavailable</h1>
            <p><?php echo htmlspecialchars($page_error ?: 'Select a delivered purchase order.'); ?></p>
            <a href="collection_monitoring.php">Return to Collection Monitoring</a>
        </main>
    <?php else: ?>
        <main class="statement-sheet">
            <header class="statement-document-header">
                <div class="statement-brand">
                    <img src="assets/images/fixie_logo.png" alt="Fixie Computer Ventures logo">
                    <div>
                        <strong>Fixie Computer Ventures</strong>
                        <span>Computer products and business solutions</span>
                    </div>
                </div>
                <div class="statement-title">
                    <span>Client collection document</span>
                    <h1>Statement of Account</h1>
                    <small>For collection reference only</small>
                </div>
            </header>

            <section class="statement-reference-grid">
                <div><span>Statement no.</span><strong><?php echo htmlspecialchars($statement_number); ?></strong></div>
                <div><span>Statement date</span><strong><?php echo phase5g_date($statement_date->format('Y-m-d')); ?></strong></div>
                <div><span>Purchase order</span><strong><?php echo htmlspecialchars($record['po_number']); ?></strong></div>
                <div><span>Client payment term</span><strong><?php echo number_format($term_days); ?> days</strong></div>
            </section>

            <section class="statement-party-grid">
                <article>
                    <span class="statement-section-label">Statement for</span>
                    <h2><?php echo htmlspecialchars($record['client_name']); ?></h2>
                    <dl>
                        <div><dt>Attention</dt><dd><?php echo htmlspecialchars($record['recipient_name'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Position</dt><dd><?php echo htmlspecialchars($record['recipient_position'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Contact</dt><dd><?php echo htmlspecialchars($record['recipient_contact'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Delivery address</dt><dd><?php echo nl2br(htmlspecialchars($record['delivery_address'] ?: 'Not recorded')); ?></dd></div>
                    </dl>
                </article>
                <article>
                    <span class="statement-section-label">Transaction reference</span>
                    <dl>
                        <div><dt>Quotation</dt><dd><?php echo htmlspecialchars($record['quotation_number'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Delivery completed</dt><dd><?php echo htmlspecialchars(phase5g_datetime($delivery_date_value)); ?></dd></div>
                        <div><dt>Client receipt ref.</dt><dd><?php echo htmlspecialchars($record['client_receipt_reference'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Acknowledgement</dt><dd><?php echo htmlspecialchars($record['acknowledgement_type'] ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Payment due</dt><dd><?php echo htmlspecialchars(phase5g_date($due_date_value, 'Not recorded')); ?></dd></div>
                    </dl>
                </article>
            </section>

            <section class="statement-balance-banner statement-balance-<?php echo htmlspecialchars($due_key); ?>">
                <div>
                    <span>Amount currently due</span>
                    <strong><?php echo phase5g_money($balance); ?></strong>
                </div>
                <div>
                    <span><?php echo htmlspecialchars($due_label); ?></span>
                    <p><?php echo htmlspecialchars($due_message); ?></p>
                </div>
            </section>

            <section class="statement-section">
                <div class="statement-section-heading">
                    <div><span>Account activity</span><h2>Payment application history</h2></div>
                    <small><?php echo number_format(count($payments)); ?> ledger entr<?php echo count($payments) === 1 ? 'y' : 'ies'; ?></small>
                </div>
                <div class="statement-table-wrap">
                    <table class="statement-table">
                        <thead><tr><th>Date received</th><th>Classification</th><th>Method</th><th>Reference</th><th>Recorded by</th><th>Amount</th></tr></thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="6" class="statement-table-empty"><?php echo $record['status'] === 'Collected' ? 'Legacy settled PO — no itemized payment entries are available in the payment ledger.' : 'No client payment has been recorded as of the statement date.'; ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(phase5g_datetime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars(phase5g_payment_label($payment['notes'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_method'] ?: 'Legacy / unspecified'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['reference_number'] ?: 'Not recorded'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?: 'Legacy record'); ?></td>
                                        <td><?php echo phase5g_money((float) $payment['amount_paid']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="statement-summary-layout">
                <div class="statement-collection-note">
                    <span class="statement-section-label">Collection note</span>
                    <p><?php echo htmlspecialchars($due_message); ?></p>
                    <small>For payment coordination, confirmation, or discrepancy concerns, please contact the authorized Fixie Finance representative handling this purchase order.</small>
                </div>
                <div class="statement-totals">
                    <div><span>Original PO amount</span><strong><?php echo phase5g_money($po_amount); ?></strong></div>
                    <div><span>Recorded ledger payments</span><strong>− <?php echo phase5g_money($ledger_paid); ?></strong></div>
                    <?php if ($legacy_settlement > 0): ?><div><span>Legacy settled value</span><strong>− <?php echo phase5g_money($legacy_settlement); ?></strong></div><?php endif; ?>
                    <div class="statement-total-due"><span>Outstanding balance</span><strong><?php echo phase5g_money($balance); ?></strong></div>
                </div>
            </section>

            <footer class="statement-footer">
                <div><span>Generated by</span><strong><?php echo htmlspecialchars($generated_by); ?></strong><small><?php echo htmlspecialchars($current_role); ?> · <?php echo htmlspecialchars(phase5g_datetime(date('Y-m-d H:i:s'))); ?></small></div>
                <div><span>Document status</span><strong>System-generated collection statement</strong><small>This is not an official receipt or tax invoice.</small></div>
            </footer>
        </main>
    <?php endif; ?>
</body>
</html>
