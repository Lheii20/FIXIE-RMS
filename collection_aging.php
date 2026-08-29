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

function phase5h_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function phase5h_date(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($value));
}

function phase5h_url(string $bucket, string $search, int $page = 1): string
{
    $parameters = ['bucket' => $bucket];
    if ($search !== '') {
        $parameters['search'] = $search;
    }
    if ($page > 1) {
        $parameters['page'] = $page;
    }

    return 'collection_aging.php?' . http_build_query($parameters);
}

$bucket_labels = [
    'all' => 'All open',
    'current' => 'Current',
    'overdue_1_15' => '1–15 days',
    'overdue_16_30' => '16–30 days',
    'overdue_31_plus' => '31+ days',
    'missing_due' => 'Missing due date',
];
$active_bucket = trim((string) ($_GET['bucket'] ?? 'all'));
if (!array_key_exists($active_bucket, $bucket_labels)) {
    $active_bucket = 'all';
}

$search = trim((string) ($_GET['search'] ?? ''));
$search = substr($search, 0, 100);
$page = max((int) ($_GET['page'] ?? 1), 1);
$per_page = 20;
$today = new DateTimeImmutable('today');
$all_rows = [];
$filtered_rows = [];
$paged_rows = [];
$top_clients = [];
$page_error = '';

$bucket_summary = [];
foreach ($bucket_labels as $bucket_key => $bucket_label) {
    $bucket_summary[$bucket_key] = ['count' => 0, 'amount' => 0.0];
}

try {
    $sql = "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.amount,
                po.status,
                po.collection_status,
                po.date_created,
                po.actual_delivery_date,
                po.expected_collection_date,
                receipt.actual_handover_at,
                receipt.collection_due_date AS receipt_due_date,
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
            WHERE po.status = 'Delivered'
              AND po.collection_status IN ('Unpaid', 'Partially Paid')";

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
        $statement->bind_param('sss', $search_term, $search_term, $search_term);
    }

    $statement->execute();
    $result = $statement->get_result();

    while ($row = $result->fetch_assoc()) {
        $amount = round((float) $row['amount'], 2);
        $total_paid = round((float) $row['total_paid'], 2);
        $balance = max(round($amount - $total_paid, 2), 0);

        if ($balance <= 0) {
            continue;
        }

        $due_date_value = $row['receipt_due_date'] ?: $row['expected_collection_date'];
        $due_date = null;
        $days_until_due = null;
        $days_overdue = null;
        $bucket = 'missing_due';
        $bucket_label = 'Missing due date';
        $age_detail = 'Finance review required';
        $priority = 3;

        if ($due_date_value && strtotime($due_date_value) !== false) {
            $due_date = new DateTimeImmutable($due_date_value);
            $days_until_due = (int) $today->diff($due_date)->format('%r%a');

            if ($days_until_due >= 0) {
                $bucket = 'current';
                $bucket_label = $days_until_due === 0 ? 'Due today' : 'Current';
                $age_detail = $days_until_due === 0
                    ? 'Collection due today'
                    : $days_until_due . ($days_until_due === 1 ? ' day remaining' : ' days remaining');
                $priority = 4;
            } else {
                $days_overdue = abs($days_until_due);
                if ($days_overdue <= 15) {
                    $bucket = 'overdue_1_15';
                    $bucket_label = '1–15 days overdue';
                    $priority = 2;
                } elseif ($days_overdue <= 30) {
                    $bucket = 'overdue_16_30';
                    $bucket_label = '16–30 days overdue';
                    $priority = 1;
                } else {
                    $bucket = 'overdue_31_plus';
                    $bucket_label = '31+ days overdue';
                    $priority = 0;
                }
                $age_detail = $days_overdue . ($days_overdue === 1 ? ' day overdue' : ' days overdue');
            }
        }

        $collection_percentage = $amount > 0
            ? min(max(($total_paid / $amount) * 100, 0), 100)
            : 0;
        $is_mine = (int) ($row['assigned_to'] ?? 0) === $current_user_id;

        $followup_key = 'none';
        $followup_label = 'No client contact';
        $followup_detail = 'No follow-up has been recorded';
        if (!empty($row['followup_id'])) {
            $followup_label = (string) ($row['last_followup_outcome'] ?: 'Contact recorded');
            $followup_detail = 'Latest ' . phase5h_date($row['last_followup_at']);
            $next_followup_value = (string) ($row['next_followup_date'] ?? '');

            if ($next_followup_value !== '' && strtotime($next_followup_value) !== false) {
                $next_followup = new DateTimeImmutable($next_followup_value);
                $days_until_followup = (int) $today->diff($next_followup)->format('%r%a');
                if ($days_until_followup < 0) {
                    $followup_key = 'overdue';
                    $followup_detail = 'Next follow-up overdue since ' . phase5h_date($next_followup_value);
                } elseif ($days_until_followup === 0) {
                    $followup_key = 'due';
                    $followup_detail = 'Next follow-up is due today';
                } else {
                    $followup_key = 'scheduled';
                    $followup_detail = 'Next follow-up ' . phase5h_date($next_followup_value);
                }
            }
        }

        $row['amount_value'] = $amount;
        $row['total_paid_value'] = $total_paid;
        $row['balance_value'] = $balance;
        $row['collection_percentage'] = $collection_percentage;
        $row['due_date_value'] = $due_date_value;
        $row['due_timestamp'] = $due_date ? $due_date->getTimestamp() : PHP_INT_MAX;
        $row['days_until_due'] = $days_until_due;
        $row['days_overdue'] = $days_overdue;
        $row['aging_bucket'] = $bucket;
        $row['aging_label'] = $bucket_label;
        $row['age_detail'] = $age_detail;
        $row['priority'] = $priority;
        $row['followup_key'] = $followup_key;
        $row['followup_label'] = $followup_label;
        $row['followup_detail'] = $followup_detail;
        $row['is_mine'] = $is_mine;
        $all_rows[] = $row;

        $bucket_summary['all']['count']++;
        $bucket_summary['all']['amount'] += $balance;
        $bucket_summary[$bucket]['count']++;
        $bucket_summary[$bucket]['amount'] += $balance;
    }

    usort(
        $all_rows,
        static function (array $left, array $right): int {
            $priority_compare = $left['priority'] <=> $right['priority'];
            if ($priority_compare !== 0) {
                return $priority_compare;
            }

            $date_compare = $left['due_timestamp'] <=> $right['due_timestamp'];
            if ($date_compare !== 0) {
                return $date_compare;
            }

            return $right['balance_value'] <=> $left['balance_value'];
        }
    );

    foreach ($all_rows as $row) {
        $client_key = strtolower(trim((string) $row['client_name']));
        if (!isset($top_clients[$client_key])) {
            $top_clients[$client_key] = [
                'name' => (string) $row['client_name'],
                'balance' => 0.0,
                'open_count' => 0,
                'overdue_count' => 0,
            ];
        }
        $top_clients[$client_key]['balance'] += $row['balance_value'];
        $top_clients[$client_key]['open_count']++;
        if (str_starts_with($row['aging_bucket'], 'overdue_')) {
            $top_clients[$client_key]['overdue_count']++;
        }

        if ($active_bucket === 'all' || $row['aging_bucket'] === $active_bucket) {
            $filtered_rows[] = $row;
        }
    }

    uasort(
        $top_clients,
        static function (array $left, array $right): int {
            $balance_compare = $right['balance'] <=> $left['balance'];
            if ($balance_compare !== 0) {
                return $balance_compare;
            }
            return $right['overdue_count'] <=> $left['overdue_count'];
        }
    );
    $top_clients = array_slice(array_values($top_clients), 0, 5);
} catch (mysqli_sql_exception $error) {
    error_log('Phase 6B3B collection aging failed: ' . $error->getMessage());
    $page_error = 'Collection aging data could not be loaded. Install Phase 6B3A first, then refresh this page.';
}

$total_results = count($filtered_rows);
$total_pages = max((int) ceil($total_results / $per_page), 1);
$page = min($page, $total_pages);
$page_start = ($page - 1) * $per_page;
$paged_rows = array_slice($filtered_rows, $page_start, $per_page);
$showing_start = $total_results > 0 ? $page_start + 1 : 0;
$showing_end = min($page_start + $per_page, $total_results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collections Receivables - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/collection-aging.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-aging.css'); ?>" rel="stylesheet">
    <link href="assets/css/collection-navigation.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-navigation.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="aging-page workflow-ui">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="aging-shell">
            <div class="collection-workspace-header">
                <header class="aging-header">
                    <div>
                        <div class="aging-eyebrow">Collections · Receivables</div>
                        <h2>Receivables aging &amp; priority</h2>
                        <p>Prioritize open client balances using the verified remaining amount and collection due date.</p>
                    </div>
                </header>

                <?php $collection_section = 'receivables'; include 'includes/collection_navigation.php'; ?>
            </div>

            <?php if ($page_error !== ''): ?>
                <section class="aging-alert aging-alert-danger" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($page_error); ?></span>
                </section>
            <?php endif; ?>

            <section class="aging-kpi-grid" aria-label="Collection aging summary">
                <article class="aging-kpi aging-kpi-total">
                    <span>Total outstanding</span>
                    <strong><?php echo phase5h_money($bucket_summary['all']['amount']); ?></strong>
                    <small><?php echo number_format($bucket_summary['all']['count']); ?> open receivable<?php echo $bucket_summary['all']['count'] === 1 ? '' : 's'; ?></small>
                </article>
                <article class="aging-kpi aging-kpi-current">
                    <span>Current</span>
                    <strong><?php echo phase5h_money($bucket_summary['current']['amount']); ?></strong>
                    <small><?php echo number_format($bucket_summary['current']['count']); ?> within payment term</small>
                </article>
                <article class="aging-kpi aging-kpi-watch">
                    <span>1–15 days overdue</span>
                    <strong><?php echo phase5h_money($bucket_summary['overdue_1_15']['amount']); ?></strong>
                    <small><?php echo number_format($bucket_summary['overdue_1_15']['count']); ?> early follow-up</small>
                </article>
                <article class="aging-kpi aging-kpi-high">
                    <span>16–30 days overdue</span>
                    <strong><?php echo phase5h_money($bucket_summary['overdue_16_30']['amount']); ?></strong>
                    <small><?php echo number_format($bucket_summary['overdue_16_30']['count']); ?> high priority</small>
                </article>
                <article class="aging-kpi aging-kpi-critical">
                    <span>31+ days overdue</span>
                    <strong><?php echo phase5h_money($bucket_summary['overdue_31_plus']['amount']); ?></strong>
                    <small><?php echo number_format($bucket_summary['overdue_31_plus']['count']); ?> critical account<?php echo $bucket_summary['overdue_31_plus']['count'] === 1 ? '' : 's'; ?></small>
                </article>
            </section>

            <?php if ($bucket_summary['missing_due']['count'] > 0): ?>
                <a href="<?php echo htmlspecialchars(phase5h_url('missing_due', $search)); ?>" class="aging-data-alert">
                    <span><i class="fas fa-calendar-xmark"></i><strong><?php echo number_format($bucket_summary['missing_due']['count']); ?> record<?php echo $bucket_summary['missing_due']['count'] === 1 ? '' : 's'; ?> need a due date</strong></span>
                    <span><?php echo phase5h_money($bucket_summary['missing_due']['amount']); ?> cannot be aged accurately <i class="fas fa-arrow-right"></i></span>
                </a>
            <?php endif; ?>

            <section class="aging-overview-grid">
                <div class="aging-workspace">
                    <div class="aging-tools">
                        <form method="GET" action="collection_aging.php" class="aging-search-form">
                            <input type="hidden" name="bucket" value="<?php echo htmlspecialchars($active_bucket); ?>">
                            <label class="aging-search">
                                <i class="fas fa-search"></i>
                                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search PO, client, or Finance owner" maxlength="100">
                            </label>
                            <button type="submit">Search</button>
                            <?php if ($search !== ''): ?>
                                <a href="<?php echo htmlspecialchars(phase5h_url($active_bucket, '')); ?>" class="aging-clear" aria-label="Clear search"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </form>

                        <nav class="aging-tabs" aria-label="Aging buckets">
                            <?php foreach ($bucket_labels as $bucket_key => $bucket_label): ?>
                                <a href="<?php echo htmlspecialchars(phase5h_url($bucket_key, $search)); ?>" class="<?php echo $active_bucket === $bucket_key ? 'is-active' : ''; ?>">
                                    <span><?php echo htmlspecialchars($bucket_label); ?></span>
                                    <strong><?php echo number_format($bucket_summary[$bucket_key]['count']); ?></strong>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </div>

                    <div class="aging-table-heading">
                        <div>
                            <strong><?php echo htmlspecialchars($bucket_labels[$active_bucket]); ?></strong>
                            <span>Showing <?php echo number_format($showing_start); ?>–<?php echo number_format($showing_end); ?> of <?php echo number_format($total_results); ?><?php echo $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : ''; ?></span>
                        </div>
                        <span><i class="fas fa-arrow-down-wide-short"></i>Oldest and highest-risk first</span>
                    </div>

                    <div class="aging-table-wrap">
                        <table class="aging-table">
                            <thead>
                                <tr>
                                    <th>Purchase order</th>
                                    <th>Financial position</th>
                                    <th>Due date &amp; age</th>
                                    <th>Latest follow-up</th>
                                    <th>Finance owner</th>
                                    <th aria-label="Actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$paged_rows): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="aging-empty">
                                                <i class="fas fa-file-circle-check"></i>
                                                <strong>No matching open receivables</strong>
                                                <span>There are no collection records under this aging bucket.</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paged_rows as $row): ?>
                                        <tr class="aging-row aging-row-<?php echo htmlspecialchars($row['aging_bucket']); ?>">
                                            <td data-label="Purchase order">
                                                <div class="aging-po">
                                                    <span><i class="fas fa-file-invoice-dollar"></i></span>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($row['po_number']); ?></strong>
                                                        <b><?php echo htmlspecialchars($row['client_name']); ?></b>
                                                        <small><?php echo htmlspecialchars($row['collection_status']); ?><?php echo $row['is_mine'] ? ' · My task' : ''; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Financial position">
                                                <div class="aging-financials">
                                                    <strong><?php echo phase5h_money($row['balance_value']); ?></strong>
                                                    <span>Outstanding</span>
                                                    <small><?php echo phase5h_money($row['total_paid_value']); ?> paid of <?php echo phase5h_money($row['amount_value']); ?></small>
                                                    <div class="aging-progress"><span style="width: <?php echo number_format($row['collection_percentage'], 2, '.', ''); ?>%"></span></div>
                                                </div>
                                            </td>
                                            <td data-label="Due date &amp; age">
                                                <div class="aging-due">
                                                    <span class="aging-badge aging-badge-<?php echo htmlspecialchars($row['aging_bucket']); ?>"><?php echo htmlspecialchars($row['aging_label']); ?></span>
                                                    <strong><?php echo htmlspecialchars(phase5h_date($row['due_date_value'], 'No due date')); ?></strong>
                                                    <small><?php echo htmlspecialchars($row['age_detail']); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Latest follow-up">
                                                <div class="aging-followup">
                                                    <span class="aging-followup-status aging-followup-<?php echo htmlspecialchars($row['followup_key']); ?>"><?php echo htmlspecialchars($row['followup_label']); ?></span>
                                                    <strong><?php echo htmlspecialchars($row['last_followup_channel'] ?: 'No channel'); ?></strong>
                                                    <small><?php echo htmlspecialchars($row['followup_detail']); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Finance owner">
                                                <?php if (!empty($row['assignee_name'])): ?>
                                                    <div class="aging-owner">
                                                        <span><?php echo htmlspecialchars(strtoupper(substr($row['assignee_name'], 0, 1))); ?></span>
                                                        <div><strong><?php echo htmlspecialchars($row['assignee_name']); ?></strong><small><?php echo htmlspecialchars((string) $row['assigned_role']); ?></small></div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="aging-unassigned"><i class="fas fa-user-clock"></i><span>Unassigned</span></div>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Action">
                                                <div class="aging-actions">
                                                    <?php if ($current_role === 'Finance' && $row['is_mine']): ?>
                                                        <a href="collection_followup.php?po_id=<?php echo (int) $row['po_id']; ?>" class="aging-row-action aging-row-action-primary" title="Record client follow-up"><i class="fas fa-phone"></i><span>Follow up</span></a>
                                                    <?php else: ?>
                                                        <a href="view_po.php?id=<?php echo (int) $row['po_id']; ?>" class="aging-row-action aging-row-action-primary" title="Open purchase order"><i class="fas fa-eye"></i><span>Review</span></a>
                                                    <?php endif; ?>
                                                    <a href="collection_statement.php?po_id=<?php echo (int) $row['po_id']; ?>" class="aging-row-action" title="Open collection statement"><i class="fas fa-file-invoice"></i><span>Statement</span></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <nav class="aging-pagination" aria-label="Aging report pages">
                            <a href="<?php echo htmlspecialchars(phase5h_url($active_bucket, $search, max($page - 1, 1))); ?>" class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
                            <span>Page <strong><?php echo number_format($page); ?></strong> of <?php echo number_format($total_pages); ?></span>
                            <a href="<?php echo htmlspecialchars(phase5h_url($active_bucket, $search, min($page + 1, $total_pages))); ?>" class="<?php echo $page >= $total_pages ? 'is-disabled' : ''; ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
                        </nav>
                    <?php endif; ?>
                </div>

                <aside class="aging-exposure">
                    <div class="aging-exposure-heading">
                        <div>
                            <span>Client exposure</span>
                            <strong>Largest open balances</strong>
                        </div>
                        <i class="fas fa-ranking-star"></i>
                    </div>

                    <?php if (!$top_clients): ?>
                        <div class="aging-exposure-empty">No open client balance to rank.</div>
                    <?php else: ?>
                        <div class="aging-client-list">
                            <?php foreach ($top_clients as $index => $client): ?>
                                <a href="<?php echo htmlspecialchars(phase5h_url('all', $client['name'])); ?>" class="aging-client-item">
                                    <span class="aging-client-rank"><?php echo $index + 1; ?></span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                                        <small><?php echo number_format($client['open_count']); ?> open · <?php echo number_format($client['overdue_count']); ?> overdue</small>
                                    </div>
                                    <b><?php echo phase5h_money($client['balance']); ?></b>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="aging-priority-key">
                        <strong>Priority guide</strong>
                        <span><i class="is-critical"></i>31+ days · immediate escalation</span>
                        <span><i class="is-high"></i>16–30 days · high-priority follow-up</span>
                        <span><i class="is-watch"></i>1–15 days · active follow-up</span>
                        <span><i class="is-current"></i>Current · monitor before due</span>
                    </div>
                </aside>
            </section>
        </div>
    </main>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
