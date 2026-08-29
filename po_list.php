<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require_once 'config/workflow_access.php';

drms_require_workflow_roles([
    'Procurement',
    'GM',
    'President',
    'Finance',
    'Supply Chain',
]);

$current_user_id = (int)$_SESSION['user_id'];
$current_role = $_SESSION['role'];
ensure_collaboration_tables_exist($conn);
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
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';

$sql = "SELECT p.*, a.assignment_id, a.assigned_to, a.assigned_role, u.full_name AS assignee_name
        FROM purchase_orders p
        LEFT JOIN purchase_order_task_assignments a ON a.po_id = p.po_id AND a.assignment_status = 'Active'
        LEFT JOIN users u ON u.user_id = a.assigned_to
        WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (p.po_number LIKE ? OR p.client_name LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($filter != 'all') {
    if ($filter == 'Pending') { $sql .= " AND p.status = 'Pending'"; }
    elseif ($filter == 'In_Progress') { $sql .= " AND p.status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved')"; }
    elseif ($filter == 'GM-Approved') { $sql .= " AND p.status = 'GM-Approved'"; }
    elseif ($filter == 'Finance-Approved') { $sql .= " AND p.status = 'Finance-Approved'"; }
    elseif ($filter == 'President-Approved') { $sql .= " AND p.status = 'President-Approved'"; }
    elseif ($filter == 'Funded') { $sql .= " AND p.status = 'Funded'"; }
    elseif ($filter == 'Delivery_Queue') { $sql .= " AND p.status IN ('Delivery Requested', 'For Pick-up/Delivery')"; }
    elseif ($filter == 'Completed') { $sql .= " AND p.status = 'Delivered'"; }
    elseif ($filter == 'Awaiting_Collection') { $sql .= " AND p.status = 'Delivered' AND p.collection_status IN ('Unpaid', 'Partially Paid')"; }
    elseif ($filter == 'Paid') { $sql .= " AND p.status = 'Delivered' AND p.collection_status = 'Paid'"; }
    elseif ($filter == 'Rejected') { $sql .= " AND p.status = 'Rejected'"; }
    elseif ($filter == 'my_tasks') { $sql .= " AND a.assigned_to = ?"; $params[] = $current_user_id; $types .= 'i'; }
    elseif ($filter == 'unassigned') { $sql .= " AND a.assignment_id IS NULL"; }
}

$sql .= " ORDER BY p.date_created DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch workflow rules once
$wf_rules_array = [];
$wf_query = $conn->query("SELECT * FROM workflow_rules");
if ($wf_query) {
    while ($rule = $wf_query->fetch_assoc()) {
        $wf_rules_array[$rule['required_role']][$rule['current_status']][] = $rule;
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
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
                <?php if($_SESSION['role'] == 'Procurement'): ?>
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
                
                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                    <option value="In_Progress" <?php echo ($filter == 'In_Progress') ? 'selected' : ''; ?>>All Approval Stages</option>
                    <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Awaiting GM Approval</option>
                    <option value="GM-Approved" <?php echo ($filter == 'GM-Approved') ? 'selected' : ''; ?>>Awaiting Finance Validation</option>
                    <option value="Finance-Approved" <?php echo ($filter == 'Finance-Approved') ? 'selected' : ''; ?>>Awaiting Owner Approval</option>
                    <option value="President-Approved" <?php echo ($filter == 'President-Approved') ? 'selected' : ''; ?>>Awaiting Fund Release</option>
                    <option value="Funded" <?php echo ($filter == 'Funded') ? 'selected' : ''; ?>>Supplier Coordination</option>
                    <option value="Delivery_Queue" <?php echo ($filter == 'Delivery_Queue') ? 'selected' : ''; ?>>Delivery Coordination</option>
                    <option value="Completed" <?php echo ($filter == 'Completed') ? 'selected' : ''; ?>>Delivered Orders</option>
                    <option value="Awaiting_Collection" <?php echo ($filter == 'Awaiting_Collection') ? 'selected' : ''; ?>>Delivered · Awaiting Payment</option>
                    <option value="Paid" <?php echo ($filter == 'Paid') ? 'selected' : ''; ?>>Delivered · Fully Paid</option>
                    <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    <option value="my_tasks" <?php echo ($filter == 'my_tasks') ? 'selected' : ''; ?>>My Tasks</option>
                    <option value="unassigned" <?php echo ($filter == 'unassigned') ? 'selected' : ''; ?>>Unassigned Tasks</option>
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="po_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Procurement'): ?>
                    <a href="create_po.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center" title="Create Purchase Order" aria-label="Create Purchase Order">
                        <i class="fas fa-plus me-2"></i> Create Order
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="grid-card">
            
            <div id="grid-skeleton" class="skeleton-wrapper">
                <?php for($i=0; $i<6; $i++): ?>
                <div class="skeleton-row border-bottom border-light pb-3">
                    <div class="skeleton-cell skeleton-box"></div>
                    <div class="flex-1">
                        <div class="skeleton-cell w-50 mb-2"></div>
                        <div class="skeleton-cell w-25 h-10px"></div>
                    </div>
                    <div class="skeleton-cell w-15-pct"></div>
                    <div class="skeleton-cell w-15-pct"></div>
                    <div class="skeleton-cell w-15-pct"></div>
                    <div class="skeleton-cell ms-auto w-8-pct"></div>
                </div>
                <?php endfor; ?>
            </div>

            <div id="grid-content" class="init-hidden">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th class="w-23-pct">Order Details</th>
                                <th class="w-13-pct">Amount</th>
                                <th class="w-16-pct">Current Location</th>
                                <th class="w-14-pct">Status</th>
                                <th class="w-14-pct">Task Owner</th>
                                <th class="w-11-pct">Date Created</th>
                                <th class="w-9-pct text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    $s = $row['status'];
                                    $badge = 'bg-soft-primary';
                                    $icon = 'fa-spinner fa-spin';
                                    if($s == 'Pending') { $badge = 'bg-soft-warning'; $icon = 'fa-clock'; }
                                    elseif(in_array($s, ['Collected', 'Delivered'])) { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                                    elseif(strpos($s, 'Rejected') !== false) { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                                ?>
                                <tr>
                                    <td data-label="Order Details">
                                        <div class="order-info-block">
                                            <div class="doc-icon-box">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </div>
                                            <div class="doc-details">
    <span class="doc-title"><?php echo htmlspecialchars($row['po_number']); ?></span>
    <span class="mobile-list-subline">
        <span class="data-label"><?php echo htmlspecialchars($row['client_name']); ?></span>
        <span class="mobile-list-status <?php echo $badge; ?>">
            <?php echo str_replace('-', ' ', $row['status']); ?>
        </span>
    </span>
</div>
                                        </div>
                                    </td>
                                    <td data-label="Amount" class="currency-data">
                                        ₱<?php echo number_format($row['amount'], 2); ?>
                                    </td>
                                    <td data-label="Location">
                                        <div class="d-flex align-items-center data-value fw-medium text-muted">
                                            <i class="fas fa-map-pin me-2 text-danger opacity-75"></i> 
                                            <?php echo htmlspecialchars($row['current_location']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Status">
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo str_replace('-', ' ', $row['status']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Task Owner">
                                        <?php if (!empty($row['assigned_to'])): ?>
                                            <div class="data-value fw-semibold text-dark"><i class="fas fa-user-check text-primary me-1"></i><?php echo htmlspecialchars($row['assignee_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['assigned_role']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fas fa-users me-1"></i>Shared queue</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Date">
                                        <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                    </td>
                                    <td data-label="Actions" class="text-end pe-4">
                                        <div class="action-flex">
                                            <?php
                                            $role = $_SESSION['role'];
                                            $is_approver = false;
                                            $approve_action = '';
                                            $can_reject = false;

                                            if (isset($wf_rules_array[$role][$row['status']])) {
                                                $is_approver = true;
                                                foreach ($wf_rules_array[$role][$row['status']] as $rule) {
                                                    if ($rule['action_key'] === 'reject') {
                                                        $can_reject = true;
                                                    } else {
                                                        $approve_action = $rule['action_key'];
                                                    }
                                                }
                                            }

                                            $assigned_to_another = !empty($row['assigned_to']) && (int)$row['assigned_to'] !== $current_user_id;
                                            $claim_required = $is_approver && empty($row['assigned_to']) && role_requires_task_claim($conn, $role);
                                            ?>
                                            <a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="btn-view-icon" title="View Details">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <form id="dynamicActionForm" action="actions/po_handler.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" id="dynamicAction">
        <input type="hidden" name="po_id" id="dynamicPoId">
        <input type="hidden" name="remarks" id="dynamicRemarks">
    </form>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            var table = $('#dataTable').DataTable({
                "order": [], 
                "bStateSave": false, 
                "pageLength": 15,
                "language": {
                    "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                    "infoEmpty": "No entries found",
                    "paginate": {
                        "previous": "<i class='fas fa-angle-left'></i>",
                        "next": "<i class='fas fa-angle-right'></i>"
                    }
                },
                "dom": 't<"d-flex justify-content-between align-items-center border-top"ip>',
                "initComplete": function() {
                    setTimeout(() => {
                        $('#grid-skeleton').hide();
                        $('#grid-content').fadeIn(300);
                    }, 200); 
                }
            });
        });

        
    </script>
</body>
</html>
