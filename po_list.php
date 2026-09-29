<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';
require_once __DIR__ . '/config/frontend_assets.php';

drms_require_workflow_roles([
    'Procurement',
    'GM',
    'President',
    'Finance',
    'Supply Chain',
]);

$current_user_id = (int) $_SESSION['user_id'];
$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$valid_filters = [
    'all',
    'Pending',
    'In_Progress',
    'GM-Approved',
    'Finance-Approved',
    'President-Approved',
    'Funded',
    'Delivery_Queue',
    'Completed',
    'Awaiting_Collection',
    'Paid',
    'Rejected',
    'my_tasks',
    'unassigned',
];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters, true))
    ? $_GET['filter']
    : 'all';

// Server-side pagination keeps the page responsive even after years of POs.
// The number is intentionally fixed so the list design stays consistent.
$per_page = 15;
$requested_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$pagination_page = $requested_page !== false && $requested_page !== null
    ? max(1, (int) $requested_page)
    : 1;

$from_where_sql = "
    FROM purchase_orders p
    LEFT JOIN purchase_order_task_assignments a
      ON a.po_id = p.po_id
     AND a.assignment_status = 'Active'
    LEFT JOIN users u ON u.user_id = a.assigned_to
    WHERE 1 = 1
";
$params = [];
$types = '';

if ($search !== '') {
    $from_where_sql .= ' AND (p.po_number LIKE ? OR p.client_name LIKE ?)';
    $search_parameter = '%' . $search . '%';
    $params[] = $search_parameter;
    $params[] = $search_parameter;
    $types .= 'ss';
}

if ($filter !== 'all') {
    if ($filter === 'Pending') {
        $from_where_sql .= " AND p.status = 'Pending'";
    } elseif ($filter === 'In_Progress') {
        $from_where_sql .= " AND p.status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved')";
    } elseif ($filter === 'GM-Approved') {
        $from_where_sql .= " AND p.status = 'GM-Approved'";
    } elseif ($filter === 'Finance-Approved') {
        $from_where_sql .= " AND p.status = 'Finance-Approved'";
    } elseif ($filter === 'President-Approved') {
        $from_where_sql .= " AND p.status = 'President-Approved'";
    } elseif ($filter === 'Funded') {
        $from_where_sql .= " AND p.status = 'Funded'";
    } elseif ($filter === 'Delivery_Queue') {
        $from_where_sql .= " AND p.status IN ('Delivery Requested', 'For Pick-up/Delivery')";
    } elseif ($filter === 'Completed') {
        $from_where_sql .= " AND p.status = 'Delivered'";
    } elseif ($filter === 'Awaiting_Collection') {
        $from_where_sql .= " AND p.status = 'Delivered' AND p.collection_status IN ('Unpaid', 'Partially Paid')";
    } elseif ($filter === 'Paid') {
        $from_where_sql .= " AND p.status = 'Delivered' AND p.collection_status = 'Paid'";
    } elseif ($filter === 'Rejected') {
        $from_where_sql .= " AND p.status = 'Rejected'";
    } elseif ($filter === 'my_tasks') {
        $from_where_sql .= ' AND a.assigned_to = ?';
        $params[] = $current_user_id;
        $types .= 'i';
    } elseif ($filter === 'unassigned') {
        $from_where_sql .= ' AND a.assignment_id IS NULL';
    }
}

$count_sql = 'SELECT COUNT(DISTINCT p.po_id) AS total ' . $from_where_sql;
$count_statement = $conn->prepare($count_sql);
if ($params !== []) {
    $count_statement->bind_param($types, ...$params);
}
$count_statement->execute();
$count_row = $count_statement->get_result()->fetch_assoc();
$total_rows = (int) ($count_row['total'] ?? 0);
$count_statement->close();

$total_pages = max(1, (int) ceil($total_rows / $per_page));
$pagination_page = min($pagination_page, $total_pages);
$offset = ($pagination_page - 1) * $per_page;

$data_sql = "
    SELECT
        p.po_id,
        p.po_number,
        p.client_name,
        p.amount,
        p.status,
        p.current_location,
        p.date_created,
        a.assignment_id,
        a.assigned_to,
        a.assigned_role,
        u.full_name AS assignee_name
    " . $from_where_sql . "
    ORDER BY p.date_created DESC, p.po_id DESC
    LIMIT ? OFFSET ?
";
$data_params = $params;
$data_types = $types . 'ii';
$data_params[] = $per_page;
$data_params[] = $offset;
$statement = $conn->prepare($data_sql);
$statement->bind_param($data_types, ...$data_params);
$statement->execute();
$result = $statement->get_result();
$statement->close();

$first_row = $total_rows > 0 ? $offset + 1 : 0;
$last_row = $total_rows > 0 ? min($offset + $per_page, $total_rows) : 0;
$page_query = [];
if ($search !== '') {
    $page_query['search'] = $search;
}
if ($filter !== 'all') {
    $page_query['filter'] = $filter;
}
$page_url = static function (int $page) use ($page_query): string {
    $query = $page_query;
    $query['page'] = $page;
    return 'po_list.php?' . http_build_query($query);
};
$visible_pages = [];
for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
    if (
        $total_pages <= 7 ||
        $page_number === 1 ||
        $page_number === $total_pages ||
        abs($page_number - $pagination_page) <= 1
    ) {
        $visible_pages[] = $page_number;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Purchase Orders - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/compact-mobile-lists.css" rel="stylesheet">
    <link href="assets/css/mobile-drive-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-drive-lists.css'); ?>" rel="stylesheet">
    <?= drms_frontend_style_tags(['sweetalert2-css']) ?>
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
    <link href="assets/css/transaction-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/transaction-lists.css'); ?>" rel="stylesheet">
</head>
<body class="page-po-list workflow-ui">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="page-header">
            <div class="list-title-row d-flex align-items-center justify-content-between gap-2">
                <div class="list-title-copy">
                    <h3 class="fw-bold mb-0 text-slate-900 tracking-tight">Purchase Orders</h3>
                    <span class="list-title-subtitle text-muted fs-sm d-none d-md-block mt-1">Monitor and manage all company transactions</span>
                </div>
                <?php if ($_SESSION['role'] === 'Procurement'): ?>
                    <a href="create_po.php" class="mobile-list-create-action d-inline-flex d-md-none align-items-center justify-content-center" title="Create Purchase Order" aria-label="Create Purchase Order">
                        <svg class="mobile-list-create-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        <span class="visually-hidden">Create Purchase Order</span>
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="po_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="sleek-search-input" placeholder="Search reference or client..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="filter" class="sleek-select" aria-label="Filter purchase orders by status" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="In_Progress" <?php echo $filter === 'In_Progress' ? 'selected' : ''; ?>>All Approval Stages</option>
                    <option value="Pending" <?php echo $filter === 'Pending' ? 'selected' : ''; ?>>Awaiting GM Approval</option>
                    <option value="GM-Approved" <?php echo $filter === 'GM-Approved' ? 'selected' : ''; ?>>Awaiting Finance Validation</option>
                    <option value="Finance-Approved" <?php echo $filter === 'Finance-Approved' ? 'selected' : ''; ?>>Awaiting Owner Approval</option>
                    <option value="President-Approved" <?php echo $filter === 'President-Approved' ? 'selected' : ''; ?>>Awaiting Fund Release</option>
                    <option value="Funded" <?php echo $filter === 'Funded' ? 'selected' : ''; ?>>Supplier Coordination</option>
                    <option value="Delivery_Queue" <?php echo $filter === 'Delivery_Queue' ? 'selected' : ''; ?>>Delivery Coordination</option>
                    <option value="Completed" <?php echo $filter === 'Completed' ? 'selected' : ''; ?>>Delivered Orders</option>
                    <option value="Awaiting_Collection" <?php echo $filter === 'Awaiting_Collection' ? 'selected' : ''; ?>>Delivered · Awaiting Payment</option>
                    <option value="Paid" <?php echo $filter === 'Paid' ? 'selected' : ''; ?>>Delivered · Fully Paid</option>
                    <option value="Rejected" <?php echo $filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="my_tasks" <?php echo $filter === 'my_tasks' ? 'selected' : ''; ?>>My Tasks</option>
                    <option value="unassigned" <?php echo $filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned Tasks</option>
                </select>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="po_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>

                <?php if ($_SESSION['role'] === 'Procurement'): ?>
                    <a href="create_po.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center" title="Create Purchase Order" aria-label="Create Purchase Order">
                        <i class="fas fa-plus me-2"></i> Create Order
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="grid-card">
            <div id="grid-skeleton" class="skeleton-wrapper" style="display: none;">
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <div class="skeleton-row border-bottom border-light pb-3">
                        <div class="skeleton-cell skeleton-box"></div>
                        <div class="flex-1"><div class="skeleton-cell w-50 mb-2"></div><div class="skeleton-cell w-25 h-10px"></div></div>
                        <div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell ms-auto w-8-pct"></div>
                    </div>
                <?php endfor; ?>
            </div>

            <div id="grid-content" class="is-ready">
                <div class="table-responsive-custom">
                    <div id="dataTable_wrapper" class="dataTables_wrapper">
                        <div class="tx-table-scroll">
                            <table id="dataTable" class="table premium-table">
                                <thead><tr>
                                    <th class="w-23-pct">Order Details</th><th class="w-13-pct">Amount</th><th class="w-16-pct">Current Location</th><th class="w-14-pct">Status</th><th class="w-14-pct">Task Owner</th><th class="w-11-pct">Date Created</th><th class="w-9-pct text-end pe-4">Actions</th>
                                </tr></thead>
                                <tbody>
                                    <?php if ($result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()):
                                            $status = (string) $row['status'];
                                            $badge = 'bg-soft-primary';
                                            $icon = 'fa-spinner fa-spin';
                                            if ($status === 'Pending') { $badge = 'bg-soft-warning'; $icon = 'fa-clock'; }
                                            elseif (in_array($status, ['Collected', 'Delivered'], true)) { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                                            elseif (strpos($status, 'Rejected') !== false) { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                                        ?>
                                            <tr>
                                                <td data-label="Order Details"><div class="order-info-block"><div class="doc-icon-box"><i class="fas fa-file-invoice-dollar"></i></div><div class="doc-details"><span class="doc-title"><?php echo htmlspecialchars((string) $row['po_number']); ?></span><span class="mobile-list-subline"><span class="data-label"><?php echo htmlspecialchars((string) $row['client_name']); ?></span><span class="mobile-list-status <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars(str_replace('-', ' ', $status)); ?></span></span></div></div></td>
                                                <td data-label="Amount" class="currency-data">₱<?php echo number_format((float) $row['amount'], 2); ?></td>
                                                <td data-label="Location"><div class="d-flex align-items-center data-value fw-medium text-muted"><i class="fas fa-map-pin me-2 text-danger opacity-75"></i><?php echo htmlspecialchars((string) $row['current_location']); ?></div></td>
                                                <td data-label="Status"><div class="badge-soft <?php echo htmlspecialchars($badge); ?>"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i> <?php echo htmlspecialchars(str_replace('-', ' ', $status)); ?></div></td>
                                                <td data-label="Task Owner"><?php if (!empty($row['assigned_to'])): ?><div class="data-value fw-semibold text-dark"><i class="fas fa-user-check text-primary me-1"></i><?php echo htmlspecialchars((string) $row['assignee_name']); ?></div><small class="text-muted"><?php echo htmlspecialchars((string) $row['assigned_role']); ?></small><?php else: ?><span class="text-muted small"><i class="fas fa-users me-1"></i>Shared queue</span><?php endif; ?></td>
                                                <td data-label="Date"><span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime((string) $row['date_created'])); ?></span></td>
                                                <td data-label="Actions" class="text-end pe-4"><div class="action-flex"><a href="view_po.php?id=<?php echo (int) $row['po_id']; ?>" class="btn-view-icon" title="View Details"><i class="fas fa-chevron-right"></i></a></div></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="dataTables_empty">No purchase orders match the current search or filter.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
            <div class="server-pagination-bar" aria-label="Purchase order pagination">
                <div class="server-pagination-info">Showing <strong><?php echo $first_row; ?>–<?php echo $last_row; ?></strong> of <strong><?php echo $total_rows; ?></strong> orders</div>
                <nav class="server-pagination-controls" aria-label="Purchase order pages">
                    <?php if ($pagination_page > 1): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page - 1)); ?>" aria-label="Previous page"><i class="fas fa-angle-left"></i><span>Previous</span></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><i class="fas fa-angle-left"></i><span>Previous</span></span><?php endif; ?>
                    <?php $previous_visible = 0; foreach ($visible_pages as $page_number): ?>
                        <?php if ($previous_visible > 0 && $page_number > $previous_visible + 1): ?><span class="server-page-ellipsis">…</span><?php endif; ?>
                        <?php if ($page_number === $pagination_page): ?><span class="server-page-button is-current" aria-current="page"><?php echo $page_number; ?></span><?php else: ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($page_number)); ?>"><?php echo $page_number; ?></a><?php endif; ?>
                        <?php $previous_visible = $page_number; ?>
                    <?php endforeach; ?>
                    <?php if ($pagination_page < $total_pages): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page + 1)); ?>" aria-label="Next page"><span>Next</span><i class="fas fa-angle-right"></i></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><span>Next</span><i class="fas fa-angle-right"></i></span><?php endif; ?>
                </nav>
            </div>
        </div>
    </div>

    <form id="dynamicActionForm" action="actions/po_handler.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" id="dynamicAction"><input type="hidden" name="po_id" id="dynamicPoId"><input type="hidden" name="remarks" id="dynamicRemarks">
    </form>

    <?= drms_frontend_script_tags(['bootstrap', 'sweetalert2']) ?>
    <script>
        (function () {
            function revealServerList() {
                var skeleton = document.getElementById('grid-skeleton');
                var content = document.getElementById('grid-content');
                if (skeleton) skeleton.style.display = 'none';
                if (content) {
                    content.classList.remove('init-hidden');
                    content.classList.add('is-ready');
                }
            }

            revealServerList();
            window.setTimeout(revealServerList, 1000);
        }());
    </script>
</body>
</html>
<?php
if ($result instanceof mysqli_result) {
    $result->free();
}
?>
