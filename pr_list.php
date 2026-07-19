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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/custom_fixie.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- Premium Header Area -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1 text-slate-900 tracking-tight">Purchase Requests</h3>
                <span class="text-muted fs-sm">Review and manage all requested procurements</span>
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
                    <a href="pr_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
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

            <!-- Table Container -->
            <div id="grid-content" class="init-hidden">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th class="w-32-pct">Request Details</th>
                                <th class="w-18-pct">Estimated Value</th>
                                <th class="w-18-pct">Status</th>
                                <th class="w-20-pct">Date Encoded</th>
                                <th class="w-12-pct text-end pe-4">Actions</th>
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
                                    <td class="ps-4" data-label="Request Details">
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
                                    <td class="currency-data" data-label="Estimated Value">
                                        ₱<?php echo number_format($row['amount'], 2); ?>
                                    </td>
                                    <td data-label="Status">
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo str_replace('_', ' ', $row['status']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Date Encoded">
                                        <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                        <span class="data-label"><?php echo date('h:i A', strtotime($row['date_created'])); ?></span>
                                    </td>
                                    <td class="text-end pe-4" data-label="Actions">
                                        <div class="action-flex">
                                            <?php 
                                            // Executive Approval Logic for PRs
                                            if (in_array($_SESSION['role'], ['GM', 'President', 'Admin']) && $row['status'] === 'Pending'): ?>
                                                <button type="button" class="btn-quick-act btn-quick-approve" onclick="confirmApprovePR(event, '<?php echo $row['pr_id']; ?>', '<?php echo htmlspecialchars($row['pr_number']); ?>')"><i class="fas fa-check me-1"></i> Approve</button>
                                                <button type="button" class="btn-quick-act btn-quick-reject ms-1" onclick="confirmRejectPR(event, '<?php echo $row['pr_id']; ?>', '<?php echo htmlspecialchars($row['pr_number']); ?>')"><i class="fas fa-times"></i></button>
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

    <!-- Hidden Form for SweetAlert Submission to ensure NO NATIVE FORMS interefere -->
    <form id="dynamicActionForm" action="actions/pr_handler.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" id="dynamicAction">
        <input type="hidden" name="pr_id" id="dynamicPrId">
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
        function confirmApprovePR(e, id, prNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Request?',
                html: "<span class='text-muted fs-09rem'>Are you sure you want to approve <b>" + prNumber + "</b>?</span>",
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
                    $('#dynamicAction').val('approve_pr');
                    $('#dynamicPrId').val(id);
                    $('#dynamicRemarks').val('');
                    $('#dynamicActionForm').submit();
                }
            });
        }

        // Strict Rejection Function
        function confirmRejectPR(e, id, prNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Reject Request',
                html: "<span class='text-muted fs-09rem'>Please state the reason for rejecting <b>" + prNumber + "</b>:</span>",
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
                    $('#dynamicAction').val('reject_pr'); 
                    $('#dynamicPrId').val(id);
                    $('#dynamicRemarks').val(result.value);
                    $('#dynamicActionForm').submit();
                }
            });
        }
    </script>
</body>
</html>