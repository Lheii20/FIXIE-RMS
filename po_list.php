<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$current_user_id = (int)$_SESSION['user_id'];
$current_role = $_SESSION['role'];
ensure_collaboration_tables_exist($conn);
$search = $_GET['search'] ?? '';
$valid_filters = ['all', 'Pending', 'In_Progress', 'Completed', 'Rejected', 'my_tasks', 'unassigned'];
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
    elseif ($filter == 'In_Progress') { $sql .= " AND p.status IN ('GM-Approved', 'Finance-Approved', 'President-Approved', 'Funded')"; }
    elseif ($filter == 'Completed') { $sql .= " AND p.status = 'Collected'"; }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1 text-slate-900 tracking-tight">Purchase Orders</h3>
                <span class="text-muted fs-sm">Monitor and manage all company transactions</span>
            </div>

            <form method="GET" action="po_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="sleek-search-input" placeholder="Search reference or client..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                    <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="In_Progress" <?php echo ($filter == 'In_Progress') ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo ($filter == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                    <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    <option value="my_tasks" <?php echo ($filter == 'my_tasks') ? 'selected' : ''; ?>>My Tasks</option>
                    <option value="unassigned" <?php echo ($filter == 'unassigned') ? 'selected' : ''; ?>>Unassigned Tasks</option>
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="po_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Procurement'): ?>
                    <a href="create_po.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center">
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
                                                <span class="data-label"><?php echo htmlspecialchars($row['client_name']); ?></span>
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
                                            if ($is_approver && !$assigned_to_another && !$claim_required && isset($row['is_viewed']) && $row['is_viewed'] == 1) {
                                                echo '<button type="button" class="btn-quick-act btn-quick-approve" onclick="confirmApprovePO(event, \''.$approve_action.'\', \''.$row['po_id'].'\', \''.htmlspecialchars($row['po_number']).'\')"><i class="fas fa-check me-1"></i>Approve</button>';
                                                
                                                if ($can_reject) {
                                                    echo '<button type="button" class="btn-quick-act btn-quick-reject" onclick="confirmRejectPO(event, \''.$row['po_id'].'\', \''.htmlspecialchars($row['po_number']).'\')"><i class="fas fa-times me-1"></i>Reject</button>';
                                                }
                                            }
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

        function confirmApprovePO(e, actionKey, id, poNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Order?',
                html: "<span class='text-muted fs-09rem'>Are you sure you want to approve PO <b>" + poNumber + "</b>?</span>",
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, Approve',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: { 
                    popup: 'sleek-popup', 
                    confirmButton: 'btn btn-success px-4 py-2 shadow-sm fw-bold', 
                    cancelButton: 'btn btn-light px-4 py-2 border fw-bold ms-2' 
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#dynamicAction').val(actionKey);
                    $('#dynamicPoId').val(id);
                    $('#dynamicRemarks').val('');
                    $('#dynamicActionForm').submit();
                }
            });
        }

        function confirmRejectPO(e, id, poNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Reject Order',
                html: "<span class='text-muted fs-09rem'>Please state the reason for rejecting <b>" + poNumber + "</b>:</span>",
                icon: 'warning',
                input: 'textarea',
                inputPlaceholder: 'Enter your reason here (Required)...',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-times me-1"></i> Submit Rejection',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: { 
                    popup: 'sleek-popup', 
                    confirmButton: 'btn btn-danger px-4 py-2 shadow-sm fw-bold', 
                    cancelButton: 'btn btn-light px-4 py-2 border fw-bold ms-2' 
                },
                preConfirm: (reason) => {
                    if (!reason || reason.trim() === '') {
                        Swal.showValidationMessage('Rejection reason cannot be empty!');
                        return false;
                    }
                    return reason.trim();
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#dynamicAction').val('reject'); 
                    $('#dynamicPoId').val(id);
                    $('#dynamicRemarks').val(result.value);
                    $('#dynamicActionForm').submit();
                }
            });
        }
    </script>
</body>
</html>