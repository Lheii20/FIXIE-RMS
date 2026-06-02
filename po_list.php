<?php 
require 'config/db_connect.php'; 
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

// I-fetch ang lahat ng workflow rules ng minsan lang para maiwasan ang N+1 query issue sa loop
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
    <title>PO List - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Purchase Orders</h2>
                <p class="text-muted mb-0">Manage and track all transaction records.</p>
            </div>
            <?php if($_SESSION['role'] == 'Procurement'): ?>
                <a href="create_po.php" class="btn btn-primary px-4 py-2 text-nowrap shadow-sm">
                    <i class="fas fa-plus me-2"></i> New Order
                </a>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm mb-4 p-3 bg-white">
            <form method="GET" action="po_list.php" class="row g-2 align-items-center m-0" autocomplete="off">
                <div class="col-md-5 col-lg-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 bg-light" 
                               placeholder="Search PO Number or Client..." 
                               value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-3">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-filter text-muted"></i> Filter By</span>
                        <select name="filter" class="form-select bg-light" autocomplete="off">
                            <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                            <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="In_Progress" <?php echo ($filter == 'In_Progress') ? 'selected' : ''; ?>>In Progress / Active</option>
                            <option value="Completed" <?php echo ($filter == 'Completed') ? 'selected' : ''; ?>>Completed (Collected)</option>
                            <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-3 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm"><i class="fas fa-check me-1"></i> Apply</button>
                    <?php if(!empty($search) || $filter != 'all'): ?>
                        <a href="po_list.php" class="btn btn-light border shadow-sm text-danger" title="Clear Filters"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive p-3">
                <table id="dataTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="bg-light text-uppercase small text-secondary">
                        <tr>
                            <th class="ps-3">PO Details</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Timeline</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-3 text-nowrap">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-primary bg-opacity-10 text-primary rounded p-2">
                                            <i class="fas fa-file-invoice"></i>
                                        </div>
                                        <div class="fw-bold text-dark">#<?php echo htmlspecialchars($row['po_number']); ?></div>
                                    </div>
                                </td>
                                <td class="fw-medium text-secondary"><?php echo htmlspecialchars($row['client_name']); ?></td>
                                <td class="fw-bold text-dark text-nowrap">₱ <?php echo number_format($row['amount'], 2); ?></td>
                                <td>
                                    <span class="badge-status status-<?php echo str_replace([' ', '/'], '_', $row['status']); ?>">
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><i class="fas fa-map-pin me-1 text-danger"></i> <?php echo htmlspecialchars($row['current_location']); ?></small>
                                </td>
                                <td class="text-nowrap">
                                    <div class="d-flex flex-column" style="font-size: 0.75rem;">
                                        <span class="text-muted">Created: <?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                        <?php if(isset($row['expected_collection_date']) && $row['expected_collection_date']): ?>
                                            <span class="text-success fw-bold">Due: <?php echo date('M d', strtotime($row['expected_collection_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end pe-3 text-nowrap">
                                    <div class="d-flex align-items-center justify-content-end gap-1">
                                        <a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="btn btn-sm btn-outline-primary shadow-sm">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                        
                                        <?php
                                        $role = $_SESSION['role'];
                                        $status = $row['status'];
                                        $is_approver = false;
                                        $approve_action = '';
                                        $approve_label = '';
                                        $can_reject = false;

                                        if (isset($wf_rules_array[$role][$status])) {
                                            $is_approver = true;
                                            foreach ($wf_rules_array[$role][$status] as $rule) {
                                                if ($rule['action_key'] === 'reject') {
                                                    $can_reject = true;
                                                } else {
                                                    $approve_action = $rule['action_key'];
                                                    $approve_label = $rule['button_label'];
                                                }
                                            }
                                        }

                                        if ($is_approver && isset($row['is_viewed']) && $row['is_viewed'] == 1) {
                                            echo '<button type="button" class="btn btn-sm btn-success shadow-sm mx-1" onclick="openActionModal(\''.$approve_action.'\', \''.$row['po_id'].'\', \'Confirm approval for PO #'.$row['po_number'].'?\', \'success\', \''.$approve_label.'\')"><i class="fas fa-check me-1"></i> '.$approve_label.'</button>';
                                            
                                            if ($can_reject) {
                                                echo '<button type="button" class="btn btn-sm btn-outline-danger shadow-sm" onclick="openActionModal(\'reject\', \''.$row['po_id'].'\', \'Are you sure you want to REJECT PO #'.$row['po_number'].'?\', \'danger\', \'Reject\')"><i class="fas fa-times me-1"></i> Reject</button>';
                                            }
                                        }
                                        ?>
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

    <!-- Premium Minimalist Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 360px;">
            <div class="modal-content border-0" style="box-shadow: 0 20px 25px -5px rgba(0,0,0,0.08), 0 10px 10px -5px rgba(0,0,0,0.04); border-radius: var(--border-radius-lg) !important; overflow: hidden;">
                <form action="actions/po_handler.php" method="POST" id="actionForm">
                    <!-- Absolute Close Button -->
                    <button type="button" class="btn-close position-absolute" data-bs-dismiss="modal" aria-label="Close" style="top: 15px; right: 15px; font-size: 0.75rem; z-index: 10; box-shadow: none; outline: none;"></button>
                    
                    <div class="modal-body text-center pt-5 pb-4 px-4">
                        <!-- Icon Container -->
                        <div id="modalIconContainer" class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 52px; height: 52px; border-radius: 50%;">
                            <i id="actionModalIcon" class="fs-5"></i>
                        </div>
                        
                        <!-- Text -->
                        <h6 class="fw-bold text-dark mb-2" id="actionModalMessage" style="letter-spacing: -0.2px; line-height: 1.4;">Confirm Action</h6>
                        <p class="mb-4 text-muted" style="font-size: 0.78rem; line-height: 1.5;">Please review and confirm if you wish to proceed with this action in the system workflow transaction trail.</p>
                        
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" id="modalActionInput" value="">
                        <input type="hidden" name="po_id" id="modalPoIdInput" value="">
                        
                        <!-- Buttons -->
                        <div class="row g-2">
                            <div class="col-6">
                                <button type="button" class="btn btn-light w-100 py-2 border text-uppercase" data-bs-dismiss="modal" style="font-size: 0.75rem !important; font-weight: 600 !important; border-color: #dee2e6 !important; background-color: #fff !important; color: #64748b !important;">Cancel</button>
                            </div>
                            <div class="col-6">
                                <button type="submit" class="btn w-100 py-2 text-uppercase text-white" id="actionModalBtn" style="font-size: 0.75rem !important; font-weight: 600 !important; box-shadow: none !important;">Confirm</button>
                            </div>
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
            var table = $('#dataTable').DataTable({
                "order": [], 
                "bStateSave": false, 
                "pageLength": 10,
                "dom": '<"table-responsive"t><"d-flex justify-content-between align-items-center mt-3"ip>'
            });
        });

        // Dynamic Professional Action Modal Handler
        function openActionModal(action, id, message, type, buttonLabel) {
            document.getElementById('modalActionInput').value = action;
            document.getElementById('modalPoIdInput').value = id;
            document.getElementById('actionModalMessage').innerText = message;
            
            let iconContainer = document.getElementById('modalIconContainer');
            let btn = document.getElementById('actionModalBtn');
            let icon = document.getElementById('actionModalIcon');
            
            btn.innerText = buttonLabel;
            
            if(type === 'success') {
                iconContainer.style.backgroundColor = '#ecfdf5';
                iconContainer.style.color = '#10b981';
                icon.className = 'fas fa-check';
                
                btn.style.backgroundColor = '#10b981';
                btn.style.borderColor = '#10b981';
            } else if (type === 'danger') {
                iconContainer.style.backgroundColor = '#fef2f2';
                iconContainer.style.color = '#ef4444';
                icon.className = 'fas fa-exclamation-triangle';
                
                btn.style.backgroundColor = '#ef4444';
                btn.style.borderColor = '#ef4444';
            }
            
            var myModal = new bootstrap.Modal(document.getElementById('actionModal'));
            myModal.show();
        }
    </script>
</body>
</html>