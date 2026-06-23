<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$search = $_GET['search'] ?? '';
$valid_filters = ['all', 'Pending', 'Approved', 'Converted_to_PO', 'Rejected'];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';

$sql = "SELECT * FROM purchase_requests WHERE 1=1";
$params = [];
$types = "";

// Search Logic
if (!empty($search)) {
    $sql .= " AND (pr_number LIKE ? OR client_name LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

// Filter Logic
if ($filter != 'all') {
    $sql .= " AND status = ?";
    $params[] = $filter;
    $types .= "s";
}

$sql .= " ORDER BY date_created DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Purchase Requests - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
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
            background: #f8fafc;
            color: #8b5cf6; /* Distinct subtle purple for PRs */
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

        /* Modal Overrides */
        .sleek-modal .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- Premium Header Area -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;">Purchase Requests</h3>
                <span class="text-muted" style="font-size: 0.85rem;">Review and manage all requested procurements</span>
            </div>

            <form method="GET" action="pr_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="sleek-search-input" placeholder="Search PR or Client..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                    <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Pending Review</option>
                    <option value="Approved" <?php echo ($filter == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                    <option value="Converted_to_PO" <?php echo ($filter == 'Converted_to_PO') ? 'selected' : ''; ?>>Converted to PO</option>
                    <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="pr_list.php" class="btn btn-light border d-flex align-items-center justify-content-center" style="border-radius: 10px; width: 42px;" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Sales Staff'): ?>
                    <a href="quotations_list.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center">
                        <i class="fas fa-plus me-2"></i> Submit Request
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

            <!-- Table Container (Native CSS Scrolling for 100% width) -->
            <div id="grid-content" style="display: none;">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th style="width: 32%;">Request Details</th>
                                <th style="width: 18%;">Estimated Value</th>
                                <th style="width: 18%;">Status</th>
                                <th style="width: 20%;">Date Encoded</th>
                                <th style="width: 12%;" class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    // PR Badge logic
                                    $s = $row['status'];
                                    $badge = 'bg-soft-warning';
                                    $icon = 'fa-clock';
                                    
                                    if($s == 'Approved') { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                                    elseif($s == 'Converted_to_PO') { $badge = 'bg-soft-primary'; $icon = 'fa-file-invoice'; }
                                    elseif($s == 'Rejected') { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="order-info-block">
                                            <div class="doc-icon-box">
                                                <i class="fas fa-file-signature"></i>
                                            </div>
                                            <div class="doc-details">
                                                <span class="doc-title"><?php echo htmlspecialchars($row['pr_number']); ?></span>
                                                <span class="data-label"><?php echo htmlspecialchars($row['client_name']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="currency-data">
                                        ₱<?php echo number_format($row['amount'], 2); ?>
                                    </td>
                                    <td>
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo str_replace('_', ' ', $row['status']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                        <span class="data-label"><?php echo date('h:i A', strtotime($row['date_created'])); ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="action-flex">
                                            <?php 
                                            // Executive Approval Logic for PRs
                                            if (in_array($_SESSION['role'], ['GM', 'President', 'Admin']) && $row['status'] === 'Pending'): ?>
                                                <button type="button" class="btn-quick-act btn-quick-approve" onclick="openActionModal('approve_pr', '<?php echo $row['pr_id']; ?>', 'Confirm approval for Request #<?php echo $row['pr_number']; ?>?', 'success')"><i class="fas fa-check me-1"></i> Approve</button>
                                                <button type="button" class="btn-quick-act btn-quick-reject ms-1" onclick="openActionModal('reject_pr', '<?php echo $row['pr_id']; ?>', 'REJECT Request #<?php echo $row['pr_number']; ?>?', 'danger')"><i class="fas fa-times"></i></button>
                                            <?php endif; ?>
                                            
                                            <a href="view_pr.php?id=<?php echo $row['pr_id']; ?>" class="btn-view-icon" title="View Details">
                                                <i class="fas fa-arrow-right"></i>
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

    <!-- Soft confirmation modal -->
    <div class="modal fade sleek-modal" id="actionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-3">
                <form action="actions/pr_handler.php" method="POST" id="actionForm">
                    <div class="modal-body text-center">
                        <div id="modalIconWrap" class="mb-3 mx-auto d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; border-radius: 50%;">
                            <i id="modalIcon" class="fs-4"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-2" id="actionModalTitle">Confirm Action</h6>
                        <p class="text-muted mb-4" style="font-size: 0.85rem;" id="actionModalMessage"></p>
                        
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" id="modalActionInput" value="">
                        <input type="hidden" name="pr_id" id="modalPrIdInput" value="">
                        
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light w-50" data-bs-dismiss="modal" style="border-radius: 10px; font-weight: 500; color: #64748b;">Cancel</button>
                            <button type="submit" class="btn w-50 text-white" id="actionModalBtn" style="border-radius: 10px; font-weight: 600;">Proceed</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable WITHOUT scrollX/scrollY to allow native 100% width CSS to work perfectly
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

        function openActionModal(action, id, message, type = 'success') {
            document.getElementById('modalActionInput').value = action;
            document.getElementById('modalPrIdInput').value = id;
            document.getElementById('actionModalMessage').innerText = message;
            
            let btn = document.getElementById('actionModalBtn');
            let title = document.getElementById('actionModalTitle');
            let wrap = document.getElementById('modalIconWrap');
            let icon = document.getElementById('modalIcon');
            
            if (type === 'danger') {
                title.innerText = 'Reject Request';
                btn.style.background = '#ef4444';
                wrap.style.background = '#fef2f2';
                wrap.style.color = '#ef4444';
                icon.className = 'fas fa-exclamation-triangle';
            } else {
                title.innerText = 'Approve Request';
                btn.style.background = '#10b981';
                wrap.style.background = '#ecfdf5';
                wrap.style.color = '#10b981';
                icon.className = 'fas fa-check-circle';
            }
            
            var myModal = new bootstrap.Modal(document.getElementById('actionModal'));
            myModal.show();
        }
    </script>
</body>
</html>