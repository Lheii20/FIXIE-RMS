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

function phase5e_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function phase5e_datetime(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y · g:i A', strtotime($value));
}

function phase5e_valid_date(string $value): string
{
    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $has_errors = is_array($errors) &&
        ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    return $date && !$has_errors && $date->format('Y-m-d') === $value
        ? $value
        : '';
}

function phase5e_bind(
    mysqli_stmt $statement,
    string $types,
    array &$parameters
): void {
    if ($types === '') {
        return;
    }

    $references = [];
    foreach ($parameters as $index => &$parameter) {
        $references[$index] = &$parameter;
    }
    unset($parameter);

    $statement->bind_param($types, ...$references);
}

function phase5e_classification(
    ?string $classification,
    ?string $notes
): array
{
    $classification = trim((string) $classification);
    $notes = trim((string) $notes);
    $remark = '';
    if (strpos($notes, '|') !== false) {
        [$notes, $remark] = array_map('trim', explode('|', $notes, 2));
    }

    if ($classification === 'Advance / Down Payment') {
        return ['advance', 'Advance / down payment', $remark];
    }
    if ($classification === 'Full Payment') {
        return ['full', 'Full payment', $remark];
    }
    if ($classification === 'Partial Payment') {
        return ['partial', 'Partial payment', $remark];
    }

    return ['unclassified', 'Unclassified payment', $remark];
}

function phase5e_page_url(int $page, array $filters): string
{
    $filters['page'] = $page;
    return 'collection_ledger.php?' . http_build_query(
        array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 'all'
        )
    );
}

$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$method = trim((string) ($_GET['method'] ?? 'all'));
$classification = trim((string) ($_GET['classification'] ?? 'all'));
$scope = trim((string) ($_GET['scope'] ?? 'all'));
$date_from = phase5e_valid_date(trim((string) ($_GET['date_from'] ?? '')));
$date_to = phase5e_valid_date(trim((string) ($_GET['date_to'] ?? '')));
$page = max((int) ($_GET['page'] ?? 1), 1);
$per_page = 25;
$page_error = '';

$allowed_methods = [
    'all',
    'Cash',
    'Bank Transfer',
    'GCash',
    'Cheque',
    'Other',
];
$allowed_classifications = ['all', 'full', 'partial', 'advance'];
$allowed_scopes = ['all', 'open', 'settled'];

if (!in_array($method, $allowed_methods, true)) {
    $method = 'all';
}
if (!in_array($classification, $allowed_classifications, true)) {
    $classification = 'all';
}
if (!in_array($scope, $allowed_scopes, true)) {
    $scope = 'all';
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $page_error = 'The date-from filter cannot be later than the date-to filter.';
    $date_from = '';
    $date_to = '';
}

$filters = [
    'search' => $search,
    'method' => $method,
    'classification' => $classification,
    'scope' => $scope,
    'date_from' => $date_from,
    'date_to' => $date_to,
];
$has_filters = $search !== '' || $method !== 'all' ||
    $classification !== 'all' || $scope !== 'all' ||
    $date_from !== '' || $date_to !== '';

$conditions = ['1 = 1'];
$types = '';
$parameters = [];

if ($search !== '') {
    $conditions[] = "(
        po.po_number LIKE ? OR
        po.client_name LIKE ? OR
        COALESCE(payment.reference_number, '') LIKE ? OR
        COALESCE(recorder.full_name, '') LIKE ?
    )";
    $search_term = '%' . $search . '%';
    array_push(
        $parameters,
        $search_term,
        $search_term,
        $search_term,
        $search_term
    );
    $types .= 'ssss';
}

if ($method !== 'all') {
    $conditions[] = 'payment.payment_method = ?';
    $parameters[] = $method;
    $types .= 's';
}

if ($classification === 'advance') {
    $conditions[] = "payment.payment_classification = 'Advance / Down Payment'";
} elseif ($classification === 'full') {
    $conditions[] = "payment.payment_classification = 'Full Payment'";
} elseif ($classification === 'partial') {
    $conditions[] = "payment.payment_classification = 'Partial Payment'";
}

if ($scope === 'open') {
    $conditions[] = "po.collection_status IN ('Unpaid', 'Partially Paid')";
} elseif ($scope === 'settled') {
    $conditions[] = "po.collection_status = 'Paid'";
}

if ($date_from !== '') {
    $conditions[] = 'DATE(payment.payment_date) >= ?';
    $parameters[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $conditions[] = 'DATE(payment.payment_date) <= ?';
    $parameters[] = $date_to;
    $types .= 's';
}

$where_sql = implode(' AND ', $conditions);
$payment_rows = [];
$total_rows = 0;
$total_pages = 1;
$summary = [
    'total_amount' => 0.0,
    'transaction_count' => 0,
    'po_count' => 0,
    'client_count' => 0,
    'proof_count' => 0,
];

try {
    $base_join = "
        FROM payments payment
        INNER JOIN purchase_orders po
            ON po.po_id = payment.po_id
        LEFT JOIN users recorder
            ON recorder.user_id = payment.recorded_by
    ";

    $summary_sql = "SELECT
            COALESCE(SUM(payment.amount_paid), 0) AS total_amount,
            COUNT(*) AS transaction_count,
            COUNT(DISTINCT payment.po_id) AS po_count,
            COUNT(DISTINCT po.client_name) AS client_count,
            SUM(
                payment.proof_file_path IS NOT NULL AND
                TRIM(payment.proof_file_path) <> ''
            ) AS proof_count
        {$base_join}
        WHERE {$where_sql}";
    $summary_stmt = $conn->prepare($summary_sql);
    $summary_parameters = $parameters;
    phase5e_bind($summary_stmt, $types, $summary_parameters);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result()->fetch_assoc();
    $summary_stmt->close();

    if ($summary_result) {
        $summary = [
            'total_amount' => (float) $summary_result['total_amount'],
            'transaction_count' => (int) $summary_result['transaction_count'],
            'po_count' => (int) $summary_result['po_count'],
            'client_count' => (int) $summary_result['client_count'],
            'proof_count' => (int) $summary_result['proof_count'],
        ];
    }

    $total_rows = $summary['transaction_count'];
    $total_pages = max((int) ceil($total_rows / $per_page), 1);
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;

    $row_sql = "SELECT
            payment.payment_id,
            payment.po_id,
            payment.amount_paid,
            payment.payment_date,
            payment.notes,
            payment.payment_classification,
            payment.created_at,
            payment.payment_method,
            payment.reference_number,
            payment.proof_file_path,
            recorder.full_name AS recorded_by_name,
            po.po_number,
            po.client_name,
            po.amount AS po_amount,
            po.status AS po_status,
            po.collection_status AS po_collection_status,
            COALESCE(po_payment.total_paid, 0) AS po_total_paid,
            COALESCE(po_payment.payment_count, 0) AS po_payment_count
        {$base_join}
        LEFT JOIN (
            SELECT
                po_id,
                SUM(amount_paid) AS total_paid,
                COUNT(*) AS payment_count
            FROM payments
            GROUP BY po_id
        ) po_payment
            ON po_payment.po_id = po.po_id
        WHERE {$where_sql}
        ORDER BY payment.payment_date DESC, payment.payment_id DESC
        LIMIT ? OFFSET ?";
    $row_stmt = $conn->prepare($row_sql);
    $row_parameters = $parameters;
    $row_parameters[] = $per_page;
    $row_parameters[] = $offset;
    phase5e_bind($row_stmt, $types . 'ii', $row_parameters);
    $row_stmt->execute();
    $row_result = $row_stmt->get_result();

    while ($row = $row_result->fetch_assoc()) {
        [$classification_key, $classification_label, $remark] =
            phase5e_classification(
                $row['payment_classification'],
                $row['notes']
            );
        $row['classification_key'] = $classification_key;
        $row['classification_label'] = $classification_label;
        $row['payment_remark'] = $remark;
        $row['po_balance'] = max(
            round(
                (float) $row['po_amount'] -
                (float) $row['po_total_paid'],
                2
            ),
            0
        );
        $row['proof_exists'] = !empty($row['proof_file_path']) && is_file(
            __DIR__ . '/uploads/payments/' . basename($row['proof_file_path'])
        );
        $payment_rows[] = $row;
    }
    $row_stmt->close();
} catch (mysqli_sql_exception $error) {
    error_log('Phase 6B3B collection ledger failed: ' . $error->getMessage());
    $page_error = 'The collection ledger could not be loaded. Install Phase 6B3A first, then refresh this page.';
    $payment_rows = [];
}

$first_row_number = $total_rows > 0 ? (($page - 1) * $per_page) + 1 : 0;
$last_row_number = min($page * $per_page, $total_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Collections Payments - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/collection-ledger.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-ledger.css'); ?>" rel="stylesheet">
    <link href="assets/css/collection-navigation.css?v=<?php echo filemtime(__DIR__ . '/assets/css/collection-navigation.css'); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="ledger-page workflow-ui">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="ledger-shell">
            <div class="collection-workspace-header">
                <header class="ledger-header">
                    <div>
                        <div class="ledger-eyebrow">Collections · Payments</div>
                        <h2>Payments &amp; proof ledger</h2>
                        <p>Review every recorded client payment, verification reference, proof, and responsible Finance user.</p>
                    </div>
                </header>

                <?php $collection_section = 'payments'; include 'includes/collection_navigation.php'; ?>
            </div>

            <?php if ($page_error !== ''): ?>
                <section class="ledger-alert" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($page_error); ?></span>
                </section>
            <?php endif; ?>

            <section class="ledger-kpi-grid" aria-label="Filtered payment summary">
                <article class="ledger-kpi ledger-kpi-primary">
                    <span class="ledger-kpi-icon"><i class="fas fa-coins"></i></span>
                    <div><small>Recorded collections</small><strong><?php echo phase5e_money($summary['total_amount']); ?></strong><span>Within the current filters</span></div>
                </article>
                <article class="ledger-kpi ledger-kpi-green">
                    <span class="ledger-kpi-icon"><i class="fas fa-receipt"></i></span>
                    <div><small>Transactions</small><strong><?php echo number_format($summary['transaction_count']); ?></strong><span>Across <?php echo number_format($summary['po_count']); ?> purchase order<?php echo $summary['po_count'] === 1 ? '' : 's'; ?></span></div>
                </article>
                <article class="ledger-kpi ledger-kpi-slate">
                    <span class="ledger-kpi-icon"><i class="fas fa-building"></i></span>
                    <div><small>Clients represented</small><strong><?php echo number_format($summary['client_count']); ?></strong><span>Unique client records</span></div>
                </article>
                <article class="ledger-kpi ledger-kpi-amber">
                    <span class="ledger-kpi-icon"><i class="fas fa-file-shield"></i></span>
                    <div><small>Proof references</small><strong><?php echo number_format($summary['proof_count']); ?> / <?php echo number_format($summary['transaction_count']); ?></strong><span>Stored payment-proof paths</span></div>
                </article>
            </section>

            <section class="ledger-workspace">
                <form method="GET" action="collection_ledger.php" class="ledger-filter-form">
                    <label class="ledger-search-field">
                        <span>Search records</span>
                        <div><i class="fas fa-search"></i><input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" maxlength="100" placeholder="PO, client, reference, or recorder"></div>
                    </label>
                    <label>
                        <span>Payment method</span>
                        <select name="method">
                            <?php foreach ($allowed_methods as $method_option): ?>
                                <option value="<?php echo htmlspecialchars($method_option); ?>" <?php echo $method === $method_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($method_option === 'all' ? 'All methods' : $method_option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Classification</span>
                        <select name="classification">
                            <option value="all" <?php echo $classification === 'all' ? 'selected' : ''; ?>>All classifications</option>
                            <option value="full" <?php echo $classification === 'full' ? 'selected' : ''; ?>>Full payment</option>
                            <option value="partial" <?php echo $classification === 'partial' ? 'selected' : ''; ?>>Partial payment</option>
                            <option value="advance" <?php echo $classification === 'advance' ? 'selected' : ''; ?>>Advance / down payment</option>
                        </select>
                    </label>
                    <label>
                        <span>PO position</span>
                        <select name="scope">
                            <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>All positions</option>
                            <option value="open" <?php echo $scope === 'open' ? 'selected' : ''; ?>>Open balance</option>
                            <option value="settled" <?php echo $scope === 'settled' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </label>
                    <label>
                        <span>Date from</span>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </label>
                    <label>
                        <span>Date to</span>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </label>
                    <div class="ledger-filter-actions">
                        <button type="submit"><i class="fas fa-filter"></i>Apply filters</button>
                        <?php if ($has_filters): ?><a href="collection_ledger.php" title="Clear all filters"><i class="fas fa-rotate-left"></i></a><?php endif; ?>
                    </div>
                </form>

                <div class="ledger-table-meta">
                    <div><strong>Payment records</strong><span>Showing <?php echo number_format($first_row_number); ?>–<?php echo number_format($last_row_number); ?> of <?php echo number_format($total_rows); ?></span></div>
                    <span class="ledger-readonly-chip"><i class="fas fa-lock"></i>Read-only audit view</span>
                </div>

                <div class="ledger-table-wrap">
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Payment received</th>
                                <th>PO / client</th>
                                <th>Classification</th>
                                <th>Method / reference</th>
                                <th>Amount</th>
                                <th>PO position</th>
                                <th>Recorded by</th>
                                <th>Evidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payment_rows)): ?>
                                <tr><td colspan="8"><div class="ledger-empty"><span><i class="fas fa-receipt"></i></span><strong>No payment records found</strong><p>Adjust the search or filters to review another collection period.</p></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($payment_rows as $row): ?>
                                    <tr>
                                        <td data-label="Payment received">
                                            <div class="ledger-date"><strong><?php echo htmlspecialchars(phase5e_datetime($row['payment_date'])); ?></strong><span>Logged <?php echo htmlspecialchars(phase5e_datetime($row['created_at'])); ?></span></div>
                                        </td>
                                        <td data-label="PO / client">
                                            <div class="ledger-po"><a href="view_po.php?id=<?php echo (int) $row['po_id']; ?>"><?php echo htmlspecialchars($row['po_number']); ?></a><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></div>
                                        </td>
                                        <td data-label="Classification">
                                            <span class="ledger-classification ledger-classification-<?php echo htmlspecialchars($row['classification_key']); ?>"><?php echo htmlspecialchars($row['classification_label']); ?></span>
                                            <?php if ($row['payment_remark'] !== ''): ?><small class="ledger-remark" title="<?php echo htmlspecialchars($row['payment_remark']); ?>"><?php echo htmlspecialchars($row['payment_remark']); ?></small><?php endif; ?>
                                        </td>
                                        <td data-label="Method / reference">
                                            <div class="ledger-reference"><strong><?php echo htmlspecialchars($row['payment_method'] ?: 'Not recorded'); ?></strong><span><?php echo htmlspecialchars($row['reference_number'] ?: 'No reference recorded'); ?></span></div>
                                        </td>
                                        <td data-label="Amount"><strong class="ledger-amount"><?php echo phase5e_money((float) $row['amount_paid']); ?></strong></td>
                                        <td data-label="PO position">
                                            <span class="ledger-status ledger-status-<?php echo htmlspecialchars(strtolower(str_replace([' ', '/'], '-', $row['po_collection_status']))); ?>"><?php echo htmlspecialchars($row['po_collection_status']); ?></span>
                                            <small class="ledger-balance"><?php echo $row['po_balance'] > 0 ? phase5e_money($row['po_balance']) . ' remaining' : 'Fully collected'; ?></small>
                                        </td>
                                        <td data-label="Recorded by"><div class="ledger-recorder"><span><i class="fas fa-user-check"></i></span><div><strong><?php echo htmlspecialchars($row['recorded_by_name'] ?: 'System record'); ?></strong><small>Payment #<?php echo (int) $row['payment_id']; ?></small></div></div></td>
                                        <td data-label="Evidence">
                                            <div class="ledger-evidence-actions">
                                                <?php if ($row['proof_exists']): ?>
                                                    <a href="download.php?type=payment_proof&amp;record_id=<?php echo (int) $row['payment_id']; ?>" class="ledger-proof-button" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i>Proof</a>
                                                <?php elseif (!empty($row['proof_file_path'])): ?>
                                                    <span class="ledger-proof-missing"><i class="fas fa-file-circle-xmark"></i>Missing file</span>
                                                <?php else: ?>
                                                    <span class="ledger-proof-legacy"><i class="fas fa-minus"></i>Not attached</span>
                                                <?php endif; ?>
                                                <a href="view_po.php?id=<?php echo (int) $row['po_id']; ?>" class="ledger-po-button" title="Open purchase order"><i class="fas fa-arrow-right"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav class="ledger-pagination" aria-label="Payment ledger pages">
                        <a href="<?php echo htmlspecialchars(phase5e_page_url(max($page - 1, 1), $filters)); ?>" class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
                        <span>Page <strong><?php echo number_format($page); ?></strong> of <?php echo number_format($total_pages); ?></span>
                        <a href="<?php echo htmlspecialchars(phase5e_page_url(min($page + 1, $total_pages), $filters)); ?>" class="<?php echo $page >= $total_pages ? 'is-disabled' : ''; ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
