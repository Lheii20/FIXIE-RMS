<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';
require_once __DIR__ . '/config/frontend_assets.php';

drms_require_workflow_roles(['Sales Staff', 'GM']);

$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$valid_filters = ['all', 'Pending Approval', 'For GM Acknowledgement', 'PO Received', 'Converted to PR'];
$filter = (string) ($_GET['filter'] ?? 'all');
if ($filter === 'Pending PO') $filter = 'Pending Approval';
if (!in_array($filter, $valid_filters, true)) $filter = 'all';

$per_page = 15;
$requested_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$pagination_page = $requested_page !== false && $requested_page !== null ? max(1, (int) $requested_page) : 1;

$where_sql = ' WHERE 1 = 1';
$params = [];
$types = '';
if ($search !== '') {
    $where_sql .= "
        AND (
            q.quotation_number LIKE ? OR q.client_name LIKE ? OR q.client_po_number LIKE ?
            OR EXISTS (
                SELECT 1 FROM client_approval_records search_car
                WHERE search_car.quotation_id = q.quotation_id
                  AND (search_car.actual_client_po_number LIKE ? OR search_car.internal_reference LIKE ?)
            )
        )";
    $search_parameter = '%' . $search . '%';
    for ($index = 0; $index < 5; $index++) $params[] = $search_parameter;
    $types .= 'sssss';
}
if ($filter !== 'all') {
    $where_sql .= ' AND q.status = ?';
    $params[] = $filter;
    $types .= 's';
}

// Count and page on the server. The client still receives the same visual
// layout and action controls, but it never loads the complete quotation table.
$count_statement = $conn->prepare('SELECT COUNT(*) AS total FROM quotations q' . $where_sql);
if ($params !== []) $count_statement->bind_param($types, ...$params);
$count_statement->execute();
$count_row = $count_statement->get_result()->fetch_assoc();
$total_rows = (int) ($count_row['total'] ?? 0);
$count_statement->close();

$total_pages = max(1, (int) ceil($total_rows / $per_page));
$pagination_page = min($pagination_page, $total_pages);
$offset = ($pagination_page - 1) * $per_page;
$data_sql = "
    SELECT
        q.quotation_id, q.quotation_number, q.client_name, q.amount, q.status,
        q.client_po_number, q.created_at,
        latest_approval.record_type AS latest_approval_record_type,
        latest_approval.internal_reference AS latest_approval_reference
    FROM quotations q
    LEFT JOIN client_approval_records latest_approval
      ON latest_approval.approval_record_id = (
            SELECT car.approval_record_id
            FROM client_approval_records car
            WHERE car.quotation_id = q.quotation_id AND car.record_status = 'Active'
            ORDER BY car.recorded_at DESC, car.approval_record_id DESC
            LIMIT 1
      )" . $where_sql . "
    ORDER BY q.created_at DESC, q.quotation_id DESC
    LIMIT ? OFFSET ?";
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
$page_url = static function (int $page) use ($page_query): string { $query = $page_query; $query['page'] = $page; return 'quotations_list.php?' . http_build_query($query); };
$visible_pages = [];
for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
    if ($total_pages <= 7 || $page_number === 1 || $page_number === $total_pages || abs($page_number - $pagination_page) <= 1) $visible_pages[] = $page_number;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Client Quotations - Fixie DRMS</title><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet"><link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/compact-mobile-lists.css" rel="stylesheet"><link href="assets/css/mobile-drive-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-drive-lists.css'); ?>" rel="stylesheet"><link href="assets/css/client-approval.css?v=<?php echo filemtime(__DIR__ . '/assets/css/client-approval.css'); ?>" rel="stylesheet"><link href="assets/css/client-po-acknowledgement.css?v=<?php echo filemtime(__DIR__ . '/assets/css/client-po-acknowledgement.css'); ?>" rel="stylesheet">
    <?= drms_frontend_style_tags(['sweetalert2-css']) ?>
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet"><link href="assets/css/transaction-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/transaction-lists.css'); ?>" rel="stylesheet">
</head>
<body class="page-quotation-list workflow-ui">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="page-header">
            <div class="list-title-row d-flex align-items-center justify-content-between gap-2"><div class="list-title-copy"><h3 class="fw-bold mb-0 text-slate-900 tracking-tight">Client Quotations</h3><span class="list-title-subtitle text-muted fs-sm d-none d-md-block mt-1"><?php echo $_SESSION['role'] === 'GM' ? 'Review official Client POs routed for your acknowledgment' : 'Track quotations, supporting confirmations, and official Client POs'; ?></span></div><?php if ($_SESSION['role'] === 'Sales Staff'): ?><a href="create_quotation.php" class="mobile-list-create-action d-inline-flex d-md-none align-items-center justify-content-center" title="Create Quotation" aria-label="Create Quotation"><svg class="mobile-list-create-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg><span class="visually-hidden">Create Quotation</span></a><?php endif; ?></div>
            <form method="GET" action="quotations_list.php" class="sleek-filter-bar m-0"><div class="sleek-search-group"><button type="submit" class="sleek-search-submit" title="Search quotations" aria-label="Search quotations"><i class="fas fa-search" aria-hidden="true"></i></button><input type="text" name="search" class="sleek-search-input" placeholder="Search QTN, client, PO, or reference" value="<?php echo htmlspecialchars($search); ?>"></div><select name="filter" class="sleek-select" aria-label="Filter quotations by status" onchange="this.form.submit()"><option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Records</option><option value="Pending Approval" <?php echo $filter === 'Pending Approval' ? 'selected' : ''; ?>>Waiting for Official PO</option><option value="For GM Acknowledgement" <?php echo $filter === 'For GM Acknowledgement' ? 'selected' : ''; ?>>For GM Acknowledgement</option><option value="PO Received" <?php echo $filter === 'PO Received' ? 'selected' : ''; ?>>Official Client PO Received</option><option value="Converted to PR" <?php echo $filter === 'Converted to PR' ? 'selected' : ''; ?>>Converted to PR</option></select><?php if ($search !== '' || $filter !== 'all'): ?><a href="quotations_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a><?php endif; ?><?php if ($_SESSION['role'] === 'Sales Staff'): ?><a href="create_quotation.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center" title="Create Quotation"><i class="fas fa-plus me-2"></i> Draft Quotation</a><?php endif; ?></form>
        </div>

        <div class="grid-card"><div id="grid-skeleton" class="skeleton-wrapper" style="display: none;"><?php for ($index = 0; $index < 6; $index++): ?><div class="skeleton-row border-bottom border-light pb-3"><div class="skeleton-cell skeleton-box"></div><div class="flex-1"><div class="skeleton-cell w-50 mb-2"></div><div class="skeleton-cell w-25 h-10px"></div></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell w-15-pct"></div><div class="skeleton-cell ms-auto w-12-pct"></div></div><?php endfor; ?></div>
            <div id="grid-content" class="is-ready"><div class="table-responsive-custom"><div id="dataTable_wrapper" class="dataTables_wrapper"><div class="tx-table-scroll"><table id="dataTable" class="table premium-table"><thead><tr><th class="w-25-pct">Quotation Details</th><th class="w-15-pct">Quoted Value</th><th class="w-15-pct">Client PO / Reference</th><th class="w-15-pct">Status</th><th class="w-15-pct">Date Created</th><th class="w-15-pct text-end pe-4">Actions</th></tr></thead><tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()):
                        $status = (string) $row['status']; $badge = 'bg-soft-warning'; $icon = 'fa-clock'; $status_label = 'Waiting for Client Approval';
                        if ($status === 'Pending Approval' && ($row['latest_approval_record_type'] ?? '') === 'Supporting Confirmation') { $badge = 'bg-soft-primary'; $icon = 'fa-comments'; $status_label = 'Confirmation Recorded'; }
                        elseif ($status === 'For GM Acknowledgement') { $badge = 'bg-soft-warning'; $icon = 'fa-user-check'; $status_label = 'For GM Acknowledgement'; }
                        elseif ($status === 'PO Received') { $badge = 'bg-soft-success'; $icon = 'fa-check-double'; $status_label = 'Official Client PO Received'; }
                        elseif ($status === 'Converted to PR') { $badge = 'bg-soft-primary'; $icon = 'fa-exchange-alt'; $status_label = 'Converted to PR'; }
                        $quotation_id = (int) $row['quotation_id']; $quotation_number = (string) $row['quotation_number']; $client_name = (string) $row['client_name']; $amount = number_format((float) $row['amount'], 2); $client_po_number = trim((string) ($row['client_po_number'] ?? '')); $latest_reference = trim((string) ($row['latest_approval_reference'] ?? '')); $date_created = !empty($row['created_at']) ? date('M d, Y', strtotime((string) $row['created_at'])) : '--'; $time_created = !empty($row['created_at']) ? date('h:i A', strtotime((string) $row['created_at'])) : '--';
                    ?>
                        <tr><td class="ps-4" data-label="Quotation Details"><div class="order-info-block"><div class="doc-icon-box"><i class="fas fa-file-contract"></i></div><div class="doc-details"><span class="doc-title"><?php echo htmlspecialchars($quotation_number); ?></span><span class="mobile-list-subline"><span class="data-label"><?php echo htmlspecialchars($client_name); ?></span><span class="mobile-list-status <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars($status_label); ?></span></span></div></div></td><td class="currency-data" data-label="Quoted Value">₱<?php echo $amount; ?></td><td data-label="Client PO / Reference"><?php if ($client_po_number !== ''): ?><span class="data-value text-success"><i class="fas fa-file-invoice me-1"></i><?php echo htmlspecialchars($client_po_number); ?></span><?php elseif ($latest_reference !== ''): ?><span class="data-value text-primary"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($latest_reference); ?></span><span class="data-label d-block">Supporting record</span><?php else: ?><span class="text-muted fst-italic fs-08rem">Waiting...</span><?php endif; ?></td><td data-label="Status"><div class="badge-soft <?php echo htmlspecialchars($badge); ?>"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i><?php echo htmlspecialchars($status_label); ?></div></td><td data-label="Date Created"><span class="data-value d-block fw-normal"><?php echo htmlspecialchars($date_created); ?></span><span class="data-label"><?php echo htmlspecialchars($time_created); ?></span></td><td class="text-end pe-4" data-label="Actions"><div class="action-flex"><?php if ($_SESSION['role'] === 'Sales Staff' && in_array($status, ['Pending Approval', 'Pending PO'], true)): ?><button type="button" class="btn-quick-act btn-quick-outline client-approval-trigger d-none d-md-inline-flex" data-client-approval-trigger data-client-approval-modal-id="clientApprovalModal" data-quotation-id="<?php echo $quotation_id; ?>" data-quotation-number="<?php echo htmlspecialchars($quotation_number, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-file-signature me-1"></i> Record Response</button><?php endif; ?><?php if ($_SESSION['role'] === 'Sales Staff' && $status === 'PO Received'): ?><a href="create_pr.php?quotation_id=<?php echo $quotation_id; ?>" class="btn-quick-act btn-quick-approve text-decoration-none"><i class="fas fa-arrow-right me-1"></i> Create PR</a><?php endif; ?><?php if ($_SESSION['role'] === 'GM' && $status === 'For GM Acknowledgement'): ?><a href="view_quotation.php?id=<?php echo $quotation_id; ?>" class="btn-quick-act btn-quick-outline po-ack-list-action text-decoration-none"><i class="fas fa-file-signature me-1"></i> Review PO</a><?php endif; ?><a href="view_quotation.php?id=<?php echo $quotation_id; ?>" class="btn-view-icon" title="View Document" aria-label="View quotation"><i class="fas fa-chevron-right"></i></a></div></td></tr>
                    <?php endwhile; ?>
                <?php else: ?><tr><td colspan="6" class="dataTables_empty">No quotations match the current search or filter.</td></tr><?php endif; ?>
            </tbody></table></div></div></div></div>
            <div class="server-pagination-bar" aria-label="Quotation pagination"><div class="server-pagination-info">Showing <strong><?php echo $first_row; ?>–<?php echo $last_row; ?></strong> of <strong><?php echo $total_rows; ?></strong> quotations</div><nav class="server-pagination-controls" aria-label="Quotation pages"><?php if ($pagination_page > 1): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page - 1)); ?>" aria-label="Previous page"><i class="fas fa-angle-left"></i><span>Previous</span></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><i class="fas fa-angle-left"></i><span>Previous</span></span><?php endif; ?><?php $previous_visible = 0; foreach ($visible_pages as $page_number): ?><?php if ($previous_visible > 0 && $page_number > $previous_visible + 1): ?><span class="server-page-ellipsis">…</span><?php endif; ?><?php if ($page_number === $pagination_page): ?><span class="server-page-button is-current" aria-current="page"><?php echo $page_number; ?></span><?php else: ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($page_number)); ?>"><?php echo $page_number; ?></a><?php endif; ?><?php $previous_visible = $page_number; ?><?php endforeach; ?><?php if ($pagination_page < $total_pages): ?><a class="server-page-button" href="<?php echo htmlspecialchars($page_url($pagination_page + 1)); ?>" aria-label="Next page"><span>Next</span><i class="fas fa-angle-right"></i></a><?php else: ?><span class="server-page-button is-disabled" aria-disabled="true"><span>Next</span><i class="fas fa-angle-right"></i></span><?php endif; ?></nav></div>
        </div>
    </div>
    <?php if ($_SESSION['role'] === 'Sales Staff'): $client_approval_modal_id = 'clientApprovalModal'; $client_approval_quotation_id = ''; $client_approval_quotation_number = ''; require __DIR__ . '/includes/client_approval_modal.php'; endif; ?>
    <?= drms_frontend_script_tags(['jquery', 'bootstrap', 'sweetalert2']) ?>
    <?php if ($_SESSION['role'] === 'Sales Staff'): ?><script src="assets/js/client-approval-form.js?v=<?php echo filemtime(__DIR__ . '/assets/js/client-approval-form.js'); ?>"></script><?php endif; ?>
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

        const Toast = typeof Swal !== 'undefined' ? Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'shadow-lg rounded-3' } }) : null;
        const successMessage = <?php echo json_encode($_GET['success'] ?? null); ?>; const errorMessage = <?php echo json_encode($_GET['error'] ?? null); ?>;
        if (successMessage && Toast) Toast.fire({ icon: 'success', title: successMessage }); if (errorMessage && Toast) Toast.fire({ icon: 'error', title: errorMessage });
        if (successMessage || errorMessage) { const cleanUrl = new URL(window.location.href); cleanUrl.searchParams.delete('success'); cleanUrl.searchParams.delete('error'); window.history.replaceState({}, '', cleanUrl); }
    </script>
</body>
</html>
<?php if ($result instanceof mysqli_result) $result->free(); ?>
