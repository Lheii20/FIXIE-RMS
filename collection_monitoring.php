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
$current_user_id = (int) $_SESSION['user_id'];

if (!in_array($current_role, $allowed_roles, true)) {
    header('Location: dashboard.php');
    exit();
}

function phase5a_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function phase5a_date_label(?string $date, string $fallback = 'Not recorded'): string
{
    if (!$date || strtotime($date) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($date));
}

function phase5a_filter_url(string $filter, string $search): string
{
    $parameters = ['filter' => $filter];
    if ($search !== '') {
        $parameters['search'] = $search;
    }

    return 'collection_monitoring.php?' . http_build_query($parameters);
}

$valid_filters = [
    'all',
    'mine',
    'overdue',
    'due_soon',
    'partial',
    'followup_due',
    'missing_due',
    'unassigned',
];
$active_filter = trim((string) ($_GET['filter'] ?? 'all'));
if (!in_array($active_filter, $valid_filters, true)) {
    $active_filter = 'all';
}
if ($current_role !== 'Finance' && $active_filter === 'mine') {
    $active_filter = 'all';
}

$search = trim((string) ($_GET['search'] ?? ''));
$search = substr($search, 0, 100);
$today = new DateTimeImmutable('today');
$collection_rows = [];
$filtered_rows = [];
$page_error = '';
$success_message = trim((string) ($_GET['success'] ?? ''));
$success_message = substr($success_message, 0, 250);

$summary = [
    'outstanding_amount' => 0.0,
    'overdue_count' => 0,
    'overdue_amount' => 0.0,
    'due_soon_count' => 0,
    'due_soon_amount' => 0.0,
    'missing_due_count' => 0,
    'mine_count' => 0,
    'mine_amount' => 0.0,
];

$filter_counts = array_fill_keys($valid_filters, 0);

try {
    $sql = "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.amount,
                po.status,
                po.current_location,
                po.actual_delivery_date,
                po.expected_collection_date,
                po.source_pr_workflow_version,
                receipt.actual_handover_at,
                receipt.collection_due_date AS receipt_due_date,
                receipt.recipient_name,
                COALESCE(payment_summary.total_paid, 0) AS total_paid,
                payment_summary.last_payment_at,
                COALESCE(payment_summary.payment_count, 0) AS payment_count,
                assignment.assignment_id,
                assignment.assigned_to,
                assignment.assigned_role,
                assignee.full_name AS assignee_name,
                latest_followup.followup_id,
                latest_followup.contact_attempted_at AS last_followup_at,
                latest_followup.contact_channel AS last_followup_channel,
                latest_followup.followup_outcome AS last_followup_outcome,
                latest_followup.next_followup_date
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
                    MAX(payment_date) AS last_payment_at,
                    COUNT(*) AS payment_count
                FROM payments
                GROUP BY po_id
            ) payment_summary
                ON payment_summary.po_id = po.po_id
            LEFT JOIN purchase_order_task_assignments assignment
                ON assignment.assignment_id = (
                    SELECT MAX(latest_assignment.assignment_id)
                    FROM purchase_order_task_assignments latest_assignment
                    WHERE latest_assignment.po_id = po.po_id
                      AND latest_assignment.assignment_status = 'Active'
                )
            LEFT JOIN users assignee
                ON assignee.user_id = assignment.assigned_to
            LEFT JOIN po_collection_followups latest_followup
                ON latest_followup.followup_id = (
                    SELECT MAX(followup_candidate.followup_id)
                    FROM po_collection_followups followup_candidate
                    WHERE followup_candidate.po_id = po.po_id
                      AND followup_candidate.record_status = 'Active'
                )
            WHERE po.status IN ('Delivered', 'Partially-Collected')";

    if ($search !== '') {
        $sql .= " AND (
            po.po_number LIKE ? OR
            po.client_name LIKE ? OR
            COALESCE(assignee.full_name, '') LIKE ?
        )";
    }

    $sql .= ' ORDER BY po.po_id DESC';
    $statement = $conn->prepare($sql);

    if ($search !== '') {
        $search_term = '%' . $search . '%';
        $statement->bind_param(
            'sss',
            $search_term,
            $search_term,
            $search_term
        );
    }

    $statement->execute();
    $result = $statement->get_result();

    while ($row = $result->fetch_assoc()) {
        $amount = round((float) $row['amount'], 2);
        $total_paid = round((float) $row['total_paid'], 2);
        $balance = max(round($amount - $total_paid, 2), 0);
        $due_date_value = $row['receipt_due_date'] ?:
            $row['expected_collection_date'];
        $due_date = null;
        $days_until_due = null;
        $risk_key = 'missing_due';
        $risk_label = 'Missing due date';
        $risk_detail = 'Needs Finance review';
        $risk_priority = 3;

        if ($due_date_value && strtotime($due_date_value) !== false) {
            $due_date = new DateTimeImmutable($due_date_value);
            $days_until_due = (int) $today->diff($due_date)->format('%r%a');

            if ($days_until_due < 0) {
                $risk_key = 'overdue';
                $risk_label = 'Overdue';
                $risk_detail = abs($days_until_due) .
                    (abs($days_until_due) === 1 ? ' day overdue' : ' days overdue');
                $risk_priority = 0;
            } elseif ($days_until_due === 0) {
                $risk_key = 'due_today';
                $risk_label = 'Due today';
                $risk_detail = 'Follow up today';
                $risk_priority = 1;
            } elseif ($days_until_due <= 3) {
                $risk_key = 'due_soon';
                $risk_label = 'Due soon';
                $risk_detail = 'Due in ' . $days_until_due .
                    ($days_until_due === 1 ? ' day' : ' days');
                $risk_priority = 2;
            } else {
                $risk_key = 'on_track';
                $risk_label = 'On track';
                $risk_detail = $days_until_due . ' days remaining';
                $risk_priority = 4;
            }
        }

        $collection_percentage = $amount > 0
            ? min(max(($total_paid / $amount) * 100, 0), 100)
            : 0;
        $is_mine = (int) ($row['assigned_to'] ?? 0) ===
            $current_user_id;
        $is_unassigned = empty($row['assignment_id']);

        $followup_key = 'none';
        $followup_label = 'No follow-up';
        $followup_detail = 'Record the first client contact';
        $followup_last_label = 'No contact recorded';
        $needs_followup = true;

        if (!empty($row['followup_id'])) {
            $followup_last_label = 'Latest: ' .
                ((string) ($row['last_followup_outcome'] ?? '') !== ''
                    ? (string) $row['last_followup_outcome']
                    : 'Contact recorded');

            $next_followup_value = (string) ($row['next_followup_date'] ?? '');
            if ($next_followup_value !== '' && strtotime($next_followup_value) !== false) {
                $next_followup_date = new DateTimeImmutable($next_followup_value);
                $days_until_followup = (int) $today
                    ->diff($next_followup_date)
                    ->format('%r%a');

                if ($days_until_followup < 0) {
                    $followup_key = 'overdue';
                    $followup_label = 'Follow-up overdue';
                    $followup_detail = abs($days_until_followup) .
                        (abs($days_until_followup) === 1
                            ? ' day overdue'
                            : ' days overdue');
                } elseif ($days_until_followup === 0) {
                    $followup_key = 'due';
                    $followup_label = 'Due today';
                    $followup_detail = 'Client contact is due today';
                } else {
                    $followup_key = 'scheduled';
                    $followup_label = 'Scheduled';
                    $followup_detail = 'Next ' .
                        phase5a_date_label($next_followup_value);
                    $needs_followup = false;
                }
            } else {
                $followup_key = 'none';
                $followup_label = 'Needs review';
                $followup_detail = 'Set the next follow-up date';
            }
        }

        $row['amount_value'] = $amount;
        $row['total_paid_value'] = $total_paid;
        $row['balance_value'] = $balance;
        $row['collection_percentage'] = $collection_percentage;
        $row['due_date_value'] = $due_date_value;
        $row['due_timestamp'] = $due_date ? $due_date->getTimestamp() : PHP_INT_MAX;
        $row['days_until_due'] = $days_until_due;
        $row['risk_key'] = $risk_key;
        $row['risk_label'] = $risk_label;
        $row['risk_detail'] = $risk_detail;
        $row['risk_priority'] = $risk_priority;
        $row['is_mine'] = $is_mine;
        $row['is_unassigned'] = $is_unassigned;
        $row['followup_key'] = $followup_key;
        $row['followup_label'] = $followup_label;
        $row['followup_detail'] = $followup_detail;
        $row['followup_last_label'] = $followup_last_label;
        $row['needs_followup'] = $needs_followup;
        $collection_rows[] = $row;

        $summary['outstanding_amount'] += $balance;
        if ($risk_key === 'overdue') {
            $summary['overdue_count']++;
            $summary['overdue_amount'] += $balance;
        }
        if (in_array($risk_key, ['due_today', 'due_soon'], true)) {
            $summary['due_soon_count']++;
            $summary['due_soon_amount'] += $balance;
        }
        if ($risk_key === 'missing_due') {
            $summary['missing_due_count']++;
        }
        if ($is_mine) {
            $summary['mine_count']++;
            $summary['mine_amount'] += $balance;
        }

        $filter_counts['all']++;
        if ($is_mine) {
            $filter_counts['mine']++;
        }
        if ($risk_key === 'overdue') {
            $filter_counts['overdue']++;
        }
        if (in_array($risk_key, ['due_today', 'due_soon'], true)) {
            $filter_counts['due_soon']++;
        }
        if ($row['status'] === 'Partially-Collected') {
            $filter_counts['partial']++;
        }
        if ($needs_followup) {
            $filter_counts['followup_due']++;
        }
        if ($risk_key === 'missing_due') {
            $filter_counts['missing_due']++;
        }
        if ($is_unassigned) {
            $filter_counts['unassigned']++;
        }
    }

    foreach ($collection_rows as $row) {
        $matches = match ($active_filter) {
            'mine' => $row['is_mine'],
            'overdue' => $row['risk_key'] === 'overdue',
            'due_soon' => in_array(
                $row['risk_key'],
                ['due_today', 'due_soon'],
                true
            ),
            'partial' => $row['status'] === 'Partially-Collected',
            'followup_due' => $row['needs_followup'],
            'missing_due' => $row['risk_key'] === 'missing_due',
            'unassigned' => $row['is_unassigned'],
            default => true,
        };

        if ($matches) {
            $filtered_rows[] = $row;
        }
    }

    usort(
        $filtered_rows,
        static function (array $left, array $right): int {
            $priority_compare = $left['risk_priority'] <=>
                $right['risk_priority'];
            if ($priority_compare !== 0) {
                return $priority_compare;
            }

            $date_compare = $left['due_timestamp'] <=>
                $right['due_timestamp'];
            if ($date_compare !== 0) {
                return $date_compare;
            }

            return $right['balance_value'] <=> $left['balance_value'];
        }
    );
} catch (mysqli_sql_exception $error) {
    error_log('Phase 5B collection monitoring failed: ' . $error->getMessage());
    $page_error =
        'Collection data could not be loaded. Verify that Phase 5B is installed and refresh the page.';
}

$filter_labels = [
    'all' => 'All open',
    'mine' => 'Assigned to me',
    'overdue' => 'Overdue',
    'due_soon' => 'Due in 3 days',
    'partial' => 'Partial payment',
    'followup_due' => 'Needs follow-up',
    'missing_due' => 'Missing due date',
    'unassigned' => 'Unassigned',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collections Overview - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/collection-monitoring.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-monitoring.css'); ?>" rel="stylesheet">
    <link href="assets/css/collection-navigation.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-navigation.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="collection-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="collection-shell">
            <header class="collection-header">
                <div>
                    <div class="collection-eyebrow">Finance receivables workspace</div>
                    <h2>Collections overview</h2>
                    <p>Monitor delivered PO balances, due dates, ownership, and client follow-up from one workspace.</p>
                </div>
                <div class="collection-header-meta">
                    <span><i class="fas fa-calendar-day"></i><?php echo date('M d, Y'); ?></span>
                    <span><i class="fas fa-user-shield"></i><?php echo htmlspecialchars($current_role); ?> view</span>
                </div>
            </header>

            <?php $collection_section = 'overview'; include 'includes/collection_navigation.php'; ?>

            <?php if ($page_error !== ''): ?>
                <section class="collection-alert" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($page_error); ?></span>
                </section>
            <?php endif; ?>

            <?php if ($success_message !== ''): ?>
                <section class="collection-alert collection-alert-success" role="status">
                    <i class="fas fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </section>
            <?php endif; ?>

            <section class="collection-kpi-grid" aria-label="Collection summary">
                <article class="collection-kpi collection-kpi-primary">
                    <div class="collection-kpi-icon"><i class="fas fa-wallet"></i></div>
                    <div>
                        <span>Total outstanding</span>
                        <strong><?php echo phase5a_money($summary['outstanding_amount']); ?></strong>
                        <small><?php echo number_format($filter_counts['all']); ?> open receivable<?php echo $filter_counts['all'] === 1 ? '' : 's'; ?></small>
                    </div>
                </article>

                <article class="collection-kpi collection-kpi-danger">
                    <div class="collection-kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
                    <div>
                        <span>Overdue</span>
                        <strong><?php echo number_format($summary['overdue_count']); ?></strong>
                        <small><?php echo phase5a_money($summary['overdue_amount']); ?> at risk</small>
                    </div>
                </article>

                <article class="collection-kpi collection-kpi-warning">
                    <div class="collection-kpi-icon"><i class="fas fa-clock"></i></div>
                    <div>
                        <span>Due within 3 days</span>
                        <strong><?php echo number_format($summary['due_soon_count']); ?></strong>
                        <small><?php echo phase5a_money($summary['due_soon_amount']); ?> to follow up</small>
                    </div>
                </article>

                <article class="collection-kpi collection-kpi-muted">
                    <div class="collection-kpi-icon"><i class="fas fa-calendar-xmark"></i></div>
                    <div>
                        <span>Missing due date</span>
                        <strong><?php echo number_format($summary['missing_due_count']); ?></strong>
                        <small>Legacy records needing review</small>
                    </div>
                </article>
            </section>

            <section class="collection-workspace">
                <div class="collection-tools">
                    <form method="GET" action="collection_monitoring.php" class="collection-search-form">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($active_filter); ?>">
                        <label class="collection-search">
                            <i class="fas fa-search"></i>
                            <input
                                type="search"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search PO, client, or Finance owner"
                                maxlength="100"
                            >
                        </label>
                        <button type="submit">Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="<?php echo htmlspecialchars(phase5a_filter_url($active_filter, '')); ?>" class="collection-clear-search" aria-label="Clear search">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </form>

                    <?php if ($current_role === 'Finance'): ?>
                        <a href="<?php echo htmlspecialchars(phase5a_filter_url('mine', $search)); ?>" class="collection-owner-summary">
                            <span>My assigned balance</span>
                            <strong><?php echo phase5a_money($summary['mine_amount']); ?></strong>
                            <small><?php echo number_format($summary['mine_count']); ?> active task<?php echo $summary['mine_count'] === 1 ? '' : 's'; ?></small>
                        </a>
                    <?php endif; ?>
                </div>

                <nav class="collection-filter-tabs" aria-label="Collection filters">
                    <?php foreach ($filter_labels as $filter_key => $filter_label): ?>
                        <?php if ($filter_key === 'mine' && $current_role !== 'Finance') continue; ?>
                        <a
                            href="<?php echo htmlspecialchars(phase5a_filter_url($filter_key, $search)); ?>"
                            class="<?php echo $active_filter === $filter_key ? 'is-active' : ''; ?>"
                        >
                            <span><?php echo htmlspecialchars($filter_label); ?></span>
                            <strong><?php echo number_format($filter_counts[$filter_key]); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="collection-table-heading">
                    <div>
                        <strong><?php echo htmlspecialchars($filter_labels[$active_filter]); ?></strong>
                        <span><?php echo number_format(count($filtered_rows)); ?> result<?php echo count($filtered_rows) === 1 ? '' : 's'; ?><?php echo $search !== '' ? ' for “' . htmlspecialchars($search) . '”' : ''; ?></span>
                    </div>
                    <span class="collection-sort-note"><i class="fas fa-arrow-down-wide-short"></i>Highest collection risk first</span>
                </div>

                <div class="collection-table-wrap">
                    <table class="collection-table">
                        <thead>
                            <tr>
                                <th>Purchase order</th>
                                <th>Outstanding</th>
                                <th>Collection due</th>
                                <th>Client follow-up</th>
                                <th>Collection progress</th>
                                <th>Finance owner</th>
                                <th aria-label="Actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$filtered_rows): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="collection-empty">
                                            <i class="fas fa-file-circle-check"></i>
                                            <strong>No matching receivables</strong>
                                            <span>There are no open collection records under this filter.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered_rows as $row): ?>
                                    <tr class="collection-row collection-risk-<?php echo htmlspecialchars($row['risk_key']); ?>">
                                        <td data-label="Purchase order">
                                            <div class="collection-po-cell">
                                                <span class="collection-po-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($row['po_number']); ?></strong>
                                                    <span><?php echo htmlspecialchars($row['client_name']); ?></span>
                                                    <small>
                                                        <?php echo htmlspecialchars(str_replace('-', ' ', $row['status'])); ?>
                                                        <?php if ($row['is_mine']): ?> · My task<?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Outstanding">
                                            <div class="collection-amount-cell">
                                                <strong><?php echo phase5a_money($row['balance_value']); ?></strong>
                                                <span>of <?php echo phase5a_money($row['amount_value']); ?></span>
                                                <?php if ($row['total_paid_value'] > 0): ?>
                                                    <small><?php echo phase5a_money($row['total_paid_value']); ?> received</small>
                                                <?php else: ?>
                                                    <small>No payment yet</small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Collection due">
                                            <div class="collection-due-cell">
                                                <span class="collection-risk-badge collection-risk-badge-<?php echo htmlspecialchars($row['risk_key']); ?>">
                                                    <?php echo htmlspecialchars($row['risk_label']); ?>
                                                </span>
                                                <strong><?php echo htmlspecialchars(phase5a_date_label($row['due_date_value'], 'No due date')); ?></strong>
                                                <small><?php echo htmlspecialchars($row['risk_detail']); ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Client follow-up">
                                            <div class="collection-followup-cell">
                                                <span class="collection-followup-badge collection-followup-badge-<?php echo htmlspecialchars($row['followup_key']); ?>">
                                                    <?php echo htmlspecialchars($row['followup_label']); ?>
                                                </span>
                                                <strong><?php echo htmlspecialchars($row['followup_last_label']); ?></strong>
                                                <small><?php echo htmlspecialchars($row['followup_detail']); ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Collection progress">
                                            <div class="collection-progress-cell">
                                                <div>
                                                    <strong><?php echo number_format($row['collection_percentage'], 0); ?>%</strong>
                                                    <span><?php echo number_format((int) $row['payment_count']); ?> payment<?php echo (int) $row['payment_count'] === 1 ? '' : 's'; ?></span>
                                                </div>
                                                <div class="collection-progress-track">
                                                    <span style="width: <?php echo number_format($row['collection_percentage'], 2, '.', ''); ?>%"></span>
                                                </div>
                                                <small>
                                                    <?php echo $row['last_payment_at'] ? 'Last ' . htmlspecialchars(phase5a_date_label($row['last_payment_at'])) : 'Awaiting first payment'; ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td data-label="Finance owner">
                                            <?php if (!empty($row['assignee_name'])): ?>
                                                <div class="collection-owner-cell">
                                                    <span><?php echo htmlspecialchars(strtoupper(substr($row['assignee_name'], 0, 1))); ?></span>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($row['assignee_name']); ?></strong>
                                                        <small><?php echo htmlspecialchars((string) $row['assigned_role']); ?></small>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="collection-owner-empty">
                                                    <i class="fas fa-user-clock"></i>
                                                    <span>Unassigned</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action">
                                            <?php if ($current_role === 'Finance' && $row['is_mine']): ?>
                                                <div class="collection-row-actions">
                                                    <a href="collection_followup.php?po_id=<?php echo (int) $row['po_id']; ?>" class="collection-open-button collection-followup-button" title="Record client follow-up">
                                                        <span>Follow up</span>
                                                        <i class="fas fa-phone"></i>
                                                    </a>
                                                    <a href="record_collection_payment.php?po_id=<?php echo (int) $row['po_id']; ?>" class="collection-open-button collection-payment-button" title="Record verified client payment">
                                                        <span>Payment</span>
                                                        <i class="fas fa-coins"></i>
                                                    </a>
                                                    <a href="collection_statement.php?po_id=<?php echo (int) $row['po_id']; ?>" class="collection-open-button collection-statement-button" title="Open printable client collection statement">
                                                        <span>Statement</span>
                                                        <i class="fas fa-file-invoice-dollar"></i>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <div class="collection-row-actions">
                                                    <a href="view_po.php?id=<?php echo (int) $row['po_id']; ?>" class="collection-open-button" title="Open purchase order">
                                                        <span>Review</span>
                                                        <i class="fas fa-arrow-right"></i>
                                                    </a>
                                                    <a href="collection_statement.php?po_id=<?php echo (int) $row['po_id']; ?>" class="collection-open-button collection-statement-button" title="Open printable client collection statement">
                                                        <span>Statement</span>
                                                        <i class="fas fa-file-invoice-dollar"></i>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </section>
        </div>
    </main>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
