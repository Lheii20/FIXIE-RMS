<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$search = $_GET['search'] ?? '';
$valid_filters = ['all', 'Pending', 'In_Progress', 'Completed', 'Rejected'];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';

$sql = "SELECT * FROM purchase_orders WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (po_number LIKE ? OR client_name LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($filter != 'all') {
    if ($filter == 'Pending') { $sql .= " AND status = 'Pending'"; }
    elseif ($filter == 'In_Progress') { $sql .= " AND status IN ('GM-Approved', 'Finance-Approved', 'President-Approved', 'Funded')"; }
    elseif ($filter == 'Completed') { $sql .= " AND status = 'Collected'"; }
    elseif ($filter == 'Rejected') { $sql .= " AND status = 'Rejected'"; }
}

$sql .= " ORDER BY date_created DESC";
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
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Import premium font for sleek typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, .main-content {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7fb;
            color: #334155;
        }

        /* Top Header Design */
        .page-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Controls */
        .sleek-filter-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .sleek-search-group {
            position: relative;
            min-width: 280px;
        }
        .sleek-search-group i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .sleek-search-input {
            width: 100%;
            padding: 0.65rem 1rem 0.65rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 0.85rem;
            transition: 0.2s;
        }
        .sleek-search-input:focus {
            background: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }

        .sleek-select {
            padding: 0.65rem 2rem 0.65rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 0.75rem center/12px;
            font-size: 0.85rem;
            color: #334155;
            appearance: none;
            cursor: pointer;
            transition: 0.2s;
        }
        .sleek-select:focus { border-color: #3b82f6; outline: none; }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 0.65rem 1.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            transition: 0.3s ease;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        }
        .btn-gradient-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.3);
            color: #ffffff;
        }

        /* Main Data Grid Card */
        .grid-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .table-responsive-custom {
            width: 100%;
            max-height: calc(100vh - 270px);
            overflow-y: auto;
            overflow-x: auto;
        }

        .premium-table {
            width: 100% !important;
            margin: 0 !important;
            border-collapse: separate !important;
            border-spacing: 0;
        }

        .premium-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc !important;
            color: #64748b !important;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.5rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
            z-index: 10;
        }

        .premium-table tbody td {
            padding: 1rem 1.5rem !important;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9 !important;
            color: #334155;
            transition: background 0.2s;
        }

        .premium-table tbody tr:hover td {
            background: #fcfcfd !important;
        }

        .premium-table tbody tr:last-child td {
            border-bottom: none !important;
        }

        /* Order Info Block */
        .order-info-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .doc-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #3b82f6;
            font-size: 1.15rem;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
        }
        .doc-details {
            display: flex;
            flex-direction: column;
        }
        .doc-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }

        .data-label { color: #64748b; font-size: 0.75rem; }
        .data-value { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
        .currency-data { font-family: 'Inter', monospace; font-weight: 600; color: #0f172a; font-size: 0.95rem; }
        
        /* Modern Soft Badges */
        .badge-soft {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .bg-soft-warning { background: #fffbeb; color: #d97706; }
        .bg-soft-primary { background: #eff6ff; color: #2563eb; }
        .bg-soft-success { background: #ecfdf5; color: #059669; }
        .bg-soft-danger { background: #fef2f2; color: #dc2626; }

        /* Action Buttons */
        .action-flex {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .btn-view-icon {
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            background: #f1f5f9;
            color: #64748b;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-view-icon:hover { background: #e2e8f0; color: #0f172a; }
        
        .btn-quick-act {
            padding: 0 1rem;
            height: 34px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            transition: 0.2s;
        }
        .btn-quick-approve { background: #10b981; color: #fff; box-shadow: 0 2px 4px rgba(16,185,129,0.2); }
        .btn-quick-approve:hover { background: #059669; transform: translateY(-1px); }
        .btn-quick-reject { background: #fee2e2; color: #ef4444; }
        .btn-quick-reject:hover { background: #fca5a5; color: #dc2626; }

        /* Skeleton Loader */
        .skeleton-wrapper { padding: 1.5rem; }
        .skeleton-row { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; align-items: center; }
        .skeleton-cell { height: 14px; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: loading 1.5s infinite; border-radius: 6px; }
        .skeleton-box { width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; }
        @keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Datatables Footer Adjustments */
        .dataTables_wrapper .dataTables_paginate { padding: 1rem 1.5rem; display: flex; justify-content: flex-end; }
        .dataTables_wrapper .dataTables_info { padding: 1.2rem 1.5rem; font-size: 0.8rem; color: #64748b !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: none !important; background: transparent !important; padding: 0.3rem 0.8rem; margin: 0 0.15rem;
            border-radius: 8px; color: #64748b !important; font-size: 0.85rem; font-weight: 500; cursor: pointer;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #f1f5f9 !important; color: #0f172a !important; font-weight: 600; }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) { background: #e2e8f0 !important; color: #0f172a !important; }

        /* SweetAlert Sleek Overrides */
        .sleek-popup { border-radius: 16px !important; font-family: 'Inter', sans-serif; }
        .swal2-textarea { 
            font-size: 0.9rem !important; 
            border-radius: 10px !important; 
            border: 1px solid #cbd5e1 !important; 
            box-shadow: none !important; 
        }
        .swal2-textarea:focus {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- Premium Header Area -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;">Purchase Orders</h3>
                <span class="text-muted" style="font-size: 0.85rem;">Monitor and manage all company transactions</span>
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
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="po_list.php" class="btn btn-light border d-flex align-items-center justify-content-center" style="border-radius: 10px; width: 42px;" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Procurement'): ?>
                    <a href="create_po.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center">
                        <i class="fas fa-plus me-2"></i> Create Order
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Data Grid Layout -->
        <div class="grid-card">
            
            <!-- Sleek Skeleton -->
            <div id="grid-skeleton" class="skeleton-wrapper">
                <?php for($i=0; $i<6; $i++): ?>
                <div class="skeleton-row border-bottom border-light pb-3">
                    <div class="skeleton-cell skeleton-box"></div>
                    <div style="flex: 1;">
                        <div class="skeleton-cell w-50 mb-2"></div>
                        <div class="skeleton-cell w-25" style="height: 10px;"></div>
                    </div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell ms-auto" style="width: 8%;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Table Container -->
            <div id="grid-content" style="display: none;">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Order Details</th>
                                <th style="width: 15%;">Amount</th>
                                <th style="width: 20%;">Current Location</th>
                                <th style="width: 15%;">Status</th>
                                <th style="width: 13%;">Date Created</th>
                                <th style="width: 12%;" class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    // Badge logic
                                    $s = $row['status'];
                                    $badge = 'bg-soft-primary';
                                    $icon = 'fa-spinner fa-spin';
                                    if($s == 'Pending') { $badge = 'bg-soft-warning'; $icon = 'fa-clock'; }
                                    elseif(in_array($s, ['Collected', 'Delivered'])) { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                                    elseif(strpos($s, 'Rejected') !== false) { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                                ?>
                                <tr>
                                    <td class="ps-4">
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
                                    <td class="currency-data">
                                        ₱<?php echo number_format($row['amount'], 2); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center data-value fw-medium text-muted">
                                            <i class="fas fa-map-pin me-2 text-danger opacity-75"></i> 
                                            <?php echo htmlspecialchars($row['current_location']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo str_replace('-', ' ', $row['status']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                    </td>
                                    <td class="text-end pe-4">
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

                                            if ($is_approver && isset($row['is_viewed']) && $row['is_viewed'] == 1) {
                                                echo '<button type="button" class="btn-quick-act btn-quick-approve" onclick="confirmApprovePO(event, \''.$approve_action.'\', \''.$row['po_id'].'\', \''.htmlspecialchars($row['po_number']).'\')"><i class="fas fa-check me-1"></i> Approve</button>';
                                                
                                                if ($can_reject) {
                                                    echo '<button type="button" class="btn-quick-act btn-quick-reject ms-1" onclick="confirmRejectPO(event, \''.$row['po_id'].'\', \''.htmlspecialchars($row['po_number']).'\')"><i class="fas fa-times"></i></button>';
                                                }
                                            }
                                            ?>
                                            <a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="btn-view-icon" title="View Details">
                                                <i class="fas fa-chevron-right" style="font-size: 0.75rem;"></i>
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

    <!-- Hidden Form for SweetAlert Submission to ensure NO NATIVE FORMS interefere -->
    <form id="dynamicActionForm" action="actions/po_handler.php" method="POST" style="display: none;">
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

        // Safe Approval Function
        function confirmApprovePO(e, actionKey, id, poNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Order?',
                html: "<span class='text-muted' style='font-size: 0.9rem;'>Are you sure you want to approve PO <b>" + poNumber + "</b>?</span>",
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
                    $('#dynamicAction').val(actionKey); // Usually maps to 'approve_gm', 'approve_finance', etc.
                    $('#dynamicPoId').val(id);
                    $('#dynamicRemarks').val('');
                    $('#dynamicActionForm').submit();
                }
            });
        }

        // Strict Rejection Function
        function confirmRejectPO(e, id, poNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Reject Order',
                html: "<span class='text-muted' style='font-size: 0.9rem;'>Please state the reason for rejecting <b>" + poNumber + "</b>:</span>",
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