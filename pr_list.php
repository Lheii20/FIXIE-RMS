<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';
require_once __DIR__ . '/config/frontend_assets.php';

drms_require_workflow_roles(['Sales Staff', 'Procurement', 'GM', 'President', 'Finance']);

$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$valid_filters = ['all', 'Pending', 'Approved', 'Converted_to_PO', 'Rejected'];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters, true)) ? $_GET['filter'] : 'all';
$queue = (isset($_GET['queue']) && $_GET['queue'] === 'mine') ? 'mine' : '';
$per_page = 15;
$requested_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$pagination_page = $requested_page !== false && $requested_page !== null ? max(1, (int) $requested_page) : 1;

$from_where_sql = ' FROM purchase_requests WHERE 1 = 1';
$params = [];
$types = '';

// Preserve the dashboard handoff queue exactly, while paginating its results
// on the database rather than loading every request in the browser.
if ($queue === 'mine') {
    if ($_SESSION['role'] === 'GM') {
        $from_where_sql .= " AND status = 'Pending' AND current_approval_stage = 'GM Review'";
    } elseif ($_SESSION['role'] === 'Finance') {
        $from_where_sql .= " AND status = 'Pending' AND current_approval_stage = 'Finance Review'";
    } elseif ($_SESSION['role'] === 'President') {
        $from_where_sql .= " AND status = 'Pending' AND current_approval_stage = 'Owner Approval'";
    } elseif ($_SESSION['role'] === 'Procurement') {
        $from_where_sql .= " AND status = 'Approved' AND current_approval_stage = 'Official Approved' AND final_approved_by IS NOT NULL AND final_approved_at IS NOT NULL";
    }
}

if ($search !== '') {
    $from_where_sql .= ' AND (pr_number LIKE ? OR client_name LIKE ?)';
    $search_parameter = '%' . $search . '%';
    $params[] = $search_parameter;
    $params[] = $search_parameter;
    $types .= 'ss';
}
if ($filter !== 'all') {
    $from_where_sql .= ' AND status = ?';
    $params[] = $filter;
    $types .= 's';
}

$count_statement = $conn->prepare('SELECT COUNT(*) AS total' . $from_where_sql);
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
$data_sql = 'SELECT pr_id, pr_number, client_name, amount, status, current_approval_stage, date_created' .
    $from_where_sql .
    ' ORDER BY date_created DESC, pr_id DESC LIMIT ? OFFSET ?';
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
if ($search !== '') $page_query['search'] = $search;
if ($filter !== 'all') $page_query['filter'] = $filter;
if ($queue === 'mine') $page_query['queue'] = 'mine';
$page_url = static function (int $page) use ($page_query): string {
    $query = $page_query;
    $query['page'] = $page;
    return 'pr_list.php?' . http_build_query($query);
};
$visible_pages = [];
for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
    if ($total_pages <= 7 || $page_number === 1 || $page_number === $total_pages || abs($page_number - $pagination_page) <= 1) {
        $visible_pages[] = $page_number;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Purchase Requests - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/style.css" rel="stylesheet"><link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/compact-mobile-lists.css" rel="stylesheet"><link href="assets/css/mobile-drive-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-drive-lists.css'); ?>" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet"><link href="assets/css/transaction-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/transaction-lists.css'); ?>" rel="stylesheet">
</head>
<body class="page-pr-list workflow-ui">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="page-header">
            <div class="list-title-row d-flex align-items-center justify-content-between gap-2">
                <div class="list-title-copy"><h3 class="fw-bold mb-0 text-slate-900 tracking-tight">Purchase Requests</h3><span class="list-title-subtitle text-muted fs-sm d-none d-md-block mt-1"><?php if ($queue === 'mine' && $_SESSION['role'] === 'Procurement'): ?>Officially approved PRFs ready for PO conversion<?php elseif ($queue === 'mine'): ?>Purchase Requests currently assigned to your approval stage<?php else: ?>Review and manage all requested procurements<?php endif; ?></span></div>
                <?php if ($_SESSION['role'] === 'Sales Staff'): ?><a href="quotations_list.php" class="mobile-list-create-action d-inline-flex d-md-none align-items-center justify-content-center" title="Create Purchase Request" aria-label="Create Purchase Request"><svg class="mobile-list-create-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"></path></svg><span class="visually-hidden">Create Purchase Request</span></a><?php endif; ?>
            </div>
            <form method="GET" action="pr_list.php" class="sleek-filter-bar m-0">
                <?php if ($queue === 'mine'): ?><input type="hidden" name="queue" value="mine"><?php endif; ?>
                <div class="sleek-search-group"><i class="fas fa-search"></i><input type="text" name="search" class="sleek-search-input" placeholder="Search PR or Client..." value="<?php echo htmlspecialchars($search); ?>"></div>
                <select name="filter" class="sleek-select" aria-label="Filter purchase requests by status" onchange="this.form.submit()"><option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Records</option><option value="Pending" <?php echo $filter === 'Pending' ? 'selected' : ''; ?>>Pending Review</option><option value="Approved" <?php echo $filter === 'Approved' ? 'selected' : ''; ?>>Approved</option><option value="Converted_to_PO" <?php echo $filter === 'Converted_to_PO' ? 'selected' : ''; ?>>Converted to PO</option><option value="Rejected" <?php echo $filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option></select>
                <?php if ($search !== '' || $filter !== 'all' || $queue === 'mine'): ?><a href="pr_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a><?php endif; ?>
                <?php if ($_SESSION['role'] === 'Sales Staff'): ?><a href="quotations_list.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center" title="Create Purchase Request" aria-label="Create Purchase Request"><i class="fas fa-plus me-2"></i> Submit Request</a><?php endif; ?>
            </form>
        </div>

        <div class="grid-card">
            <div id="grid-skeleton" class="skeleton-wrapper" style="display: none;"><?php for ($i = 0; $i < 6; $i++): ?><div class="skeleton-row border-bottom border-light pb-3"><div class="skeleton-cell skeleton-box"></div><div class="flex-1"><div class="skeleton-cell w-50 mb-2"></div><div class="skeleton-cell w-25 h-10px"></div></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell ms-auto w-8-pct"></div></div><?php endfor; ?></div>
            <div id="grid-content" class="is-ready"><div class="table-responsive-custom"><div id="dataTable_wrapper" class="dataTables_wrapper"><div class="tx-table-scroll"><table id="dataTable" class="table premium-table"><thead><tr><th class="w-32-pct">Request Details</th><th class="w-18-pct">Estimated Value</th><th class="w-18-pct">Status</th><th class="w-20-pct">Date Encoded</th><th class="w-12-pct text-end pe-4">Actions</th></tr></thead><tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()):
                        $status = (string) $row['status'];
                        $official_stages = ['GM Review', 'Finance Review', 'Owner Approval', 'Official Approved'];
                        $stage = (string) ($row['current_approval_stage'] ?? '');
                        $is_official_pr = in_array($stage, $official_stages, true);
                        $display_status = $is_official_pr && $status === 'Pending' ? $stage : str_replace('_', ' ', $status);
                        $stage_role_map = ['GM Review' => 'GM', 'Finance Review' => 'Finance', 'Owner Approval' => 'President'];
                        $is_current_reviewer = $is_official_pr && $status === 'Pending' && isset($stage_role_map[$stage]) && $_SESSION['role'] === $stage_role_map[$stage];
                        $badge = 'bg-soft-warning'; $icon = 'fa-clock';
                        if ($status === 'Approved') { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                        elseif ($status === 'Converted_to_PO') { $badge = 'bg-soft-primary'; $icon = 'fa-file-invoice'; }
                        elseif ($status === 'Rejected') { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                    ?>
                        <tr><td class="ps-4" data-label="Request Details"><div class="order-info-block"><div class="doc-icon-box"><i class="fas fa-file-signature"></i></div><div class="doc-details"><span class="doc-title"><?php echo htmlspecialchars((string) $row['pr_number']); ?></span><span class="mobile-list-subline"><span class="data-label"><?php echo htmlspecialchars((string) $row['client_name']); ?></span><span class="mobile-list-status <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars($display_status); ?></span></span></div></div></td><td class="currency-data" data-label="Estimated Value">₱<?php echo number_format((float) $row['amount'], 2); ?></td><td data-label="Status"><div class="badge-soft <?php echo htmlspecialchars($badge); ?>"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i> <?php echo htmlspecialchars($display_status); ?></div></td><td data-label="Date Encoded"><span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime((string) $row['date_created'])); ?></span><span class="data-label"><?php echo date('h:i A', strtotime((string) $row['date_created'])); ?></span></td><td class="text-end pe-4" data-label="Actions"><div class="action-flex"><a href="view_pr.php?id=<?php echo (int) $row['pr_id']; ?>" class="btn-view-icon" title="<?php echo $is_current_reviewer ? 'Review current approval stage' : 'View details'; ?>" aria-label="<?php echo $is_current_reviewer ? 'Review current approval stage' : 'View purchase request details'; ?>"><i class="fas <?php echo $is_current_reviewer ? 'fa-clipboard-check' : 'fa-arrow-right'; ?>"></i></a></div></td></tr>
                    <?php endwhile; ?>
                <?php else: ?><tr><td colspan="5" class="dataTables_empty">No purchase requests match the current search or filter.</td></tr><?php endif; ?>
            </tbody></table></div>
            </div></div></div>
            <div class="server-pagination-bar" aria-label="Purchase request pagination"><div class="server-pagination-info">Showing <strong><?php echo $first_row; ?>–<?php echo $last_row; ?></strong> of <strong><?php echo $total_rows; ?></strong> requests</div><nav class="server-pagination-controls" aria-label="Purchase request pages"><?php if ($pagination_page > 1): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page - 1)); ?>" aria-label="Previous page"><i class="fas fa-angle-left"></i><span>Previous</span></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><i class="fas fa-angle-left"></i><span>Previous</span></span><?php endif; ?><?php $previous_visible = 0; foreach ($visible_pages as $page_number): ?><?php if ($previous_visible > 0 && $page_number > $previous_visible + 1): ?><span class="server-page-ellipsis">…</span><?php endif; ?><?php if ($page_number === $pagination_page): ?><span class="server-page-button is-current" aria-current="page"><?php echo $page_number; ?></span><?php else: ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($page_number)); ?>"><?php echo $page_number; ?></a><?php endif; ?><?php $previous_visible = $page_number; ?><?php endforeach; ?><?php if ($pagination_page < $total_pages): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page + 1)); ?>" aria-label="Next page"><span>Next</span><i class="fas fa-angle-right"></i></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><span>Next</span><i class="fas fa-angle-right"></i></span><?php endif; ?></nav></div>
        </div>
    </div>
    <?= drms_frontend_script_tags(['bootstrap']) ?>
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
<?php if ($result instanceof mysqli_result) $result->free(); ?>
