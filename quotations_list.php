<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$search = $_GET['search'] ?? '';

// Database statuses are kept stable for the existing PR workflow.
$valid_filters = ['all', 'Pending Approval', 'PO Received', 'Converted to PR'];

// Catch old 'Pending PO' links to prevent breaking
$filter = $_GET['filter'] ?? 'all';
if ($filter === 'Pending PO') $filter = 'Pending Approval';
if (!in_array($filter, $valid_filters)) $filter = 'all';

$sql = "SELECT * FROM quotations WHERE 1=1";
$params = [];
$types = "";

// Search Logic
if (!empty($search)) {
    $sql .= " AND (quotation_number LIKE ? OR client_name LIKE ? OR client_po_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

// Filter Logic
if ($filter != 'all') {
    $sql .= " AND status = ?";
    $params[] = $filter;
    $types .= "s";
}

$sql .= " ORDER BY created_at DESC";
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
    <title>Client Quotations - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/custom_fixie.css" rel="stylesheet"> <!-- External CSS Link -->
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- Premium Header Area -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1 text-slate-900 tracking-tight">Client Quotations</h3>
                <span class="text-muted fs-sm">Track outgoing offers and Client Purchase Orders</span>
            </div>

            <form method="GET" action="quotations_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="sleek-search-input" placeholder="Search QTN, Client, or PO..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                    <option value="Pending Approval" <?php echo ($filter == 'Pending Approval') ? 'selected' : ''; ?>>Waiting for Client Approval</option>
                    <option value="PO Received" <?php echo ($filter == 'PO Received') ? 'selected' : ''; ?>>Client Approved</option>
                    <option value="Converted to PR" <?php echo ($filter == 'Converted to PR') ? 'selected' : ''; ?>>Converted to PR</option>
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="quotations_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Sales Staff'): ?>
                    <a href="create_quotation.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center">
                        <i class="fas fa-plus me-2"></i> Draft Quotation
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
                    <div class="skeleton-cell ms-auto w-12-pct"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Table Container -->
            <div id="grid-content" class="init-hidden">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th class="w-25-pct">Quotation Details</th>
                                <th class="w-15-pct">Quoted Value</th>
                                <th class="w-15-pct">Client PO Ref</th>
                                <th class="w-15-pct">Status</th>
                                <th class="w-15-pct">Date Created</th>
                                <th class="w-15-pct text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    // Friendly labels for the existing quotation workflow statuses.
                                    $s = $row['status'];
                                    $badge = 'bg-soft-warning';
                                    $icon = 'fa-clock';
                                    $status_label = 'Waiting for Client Approval';
                                    
                                    if($s == 'PO Received') { $badge = 'bg-soft-success'; $icon = 'fa-check-double'; $status_label = 'Client Approved'; }
                                    elseif($s == 'Converted to PR') { $badge = 'bg-soft-primary'; $icon = 'fa-exchange-alt'; $status_label = 'Converted to PR'; }
                                    
                                    // Escaped values for HTML output.
                                    $q_id = (int)($row['quotation_id'] ?? 0);
                                    $q_num = htmlspecialchars($row['quotation_number'] ?? '');
                                    $c_name = htmlspecialchars($row['client_name'] ?? '');
                                    $amt = number_format((float)($row['amount'] ?? 0), 2);
                                    $cpo = htmlspecialchars($row['client_po_number'] ?? '');
                                    $date_c = $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : '--';
                                    $time_c = $row['created_at'] ? date('h:i A', strtotime($row['created_at'])) : '--';
                                ?>
                                <tr>
                                    <td class="ps-4" data-label="Quotation Details">
                                        <div class="order-info-block">
                                            <div class="doc-icon-box">
                                                <i class="fas fa-file-contract"></i>
                                            </div>
                                            <div class="doc-details">
                                                <span class="doc-title"><?php echo $q_num; ?></span>
                                                <span class="data-label"><?php echo $c_name; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="currency-data" data-label="Quoted Value">
                                        ₱<?php echo $amt; ?>
                                    </td>
                                    <td data-label="Client PO Ref">
                                        <?php if(!empty($cpo)): ?>
                                            <span class="data-value text-success"><i class="fas fa-file-invoice me-1"></i> <?php echo $cpo; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic fs-08rem">Waiting...</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($status_label); ?>
                                        </div>
                                    </td>
                                    <td data-label="Date Created">
                                        <span class="data-value d-block fw-normal"><?php echo $date_c; ?></span>
                                        <span class="data-label"><?php echo $time_c; ?></span>
                                    </td>
                                    <td class="text-end pe-4" data-label="Actions">
                                        <div class="action-flex">
                                            <?php if ($_SESSION['role'] === 'Sales Staff'): ?>
                                                
                                                <?php if ($s === 'Pending Approval' || $s === 'Pending PO'): ?>
                                                    <button type="button" class="btn-quick-act btn-quick-outline submit-approval-btn" data-quotation-id="<?php echo $q_id; ?>" data-quotation-number="<?php echo htmlspecialchars($row['quotation_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fas fa-file-signature me-1"></i> Submit Approval
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($s === 'PO Received'): ?>
                                                    <!-- Proceed to PR creation -->
                                                    <a href="create_pr.php?quotation_id=<?php echo $q_id; ?>" class="btn-quick-act btn-quick-approve text-decoration-none">
                                                        <i class="fas fa-arrow-right me-1"></i> Create PR
                                                    </a>
                                                <?php endif; ?>

                                            <?php endif; ?>
                                            
                                            <!-- Universal View Details -->
                                            <a href="view_quotation.php?id=<?php echo $q_id; ?>" class="btn-view-icon" title="View Document">
                                                <i class="fas fa-eye"></i>
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

    <!-- Client approval submission modal -->
    <div class="modal fade" id="receivePoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-1 border-0 modal-24px">
                <form action="actions/quotation_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header border-0 pb-0 pt-4 px-4 justify-content-center position-relative">
                        <button type="button" class="btn-close position-absolute end-0 me-4 fs-xs" data-bs-dismiss="modal"></button>
                        <div class="text-center w-100">
                            <div class="mb-3 mx-auto d-flex align-items-center justify-content-center bg-soft-success text-success box-56">
                                <i class="fas fa-file-signature fs-4"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1 tracking-tight">Submit Client Approval</h5>
                            <p class="text-muted mb-0 fs-sm">Record the client confirmation for <strong id="modalQuoteNumber" class="text-primary"></strong>.</p>
                        </div>
                    </div>
                    
                    <div class="modal-body px-4 py-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="receive_po">
                        <input type="hidden" name="quotation_id" id="modalQuotationId" value="">
                        
                        <div class="d-flex align-items-start gap-2 p-3 mb-4 rounded-3 bg-slate-50-border">
                            <i class="fas fa-shield-alt text-primary mt-1"></i>
                            <small class="text-muted">Attach a clear proof of the client’s approval. The quotation will become <strong class="text-success">Client Approved</strong> after submission.</small>
                        </div>

                        <div class="mb-4 text-start">
                            <label class="form-label label-upper-muted">Mode of Approval <span class="text-danger">*</span></label>
                            <select name="approval_mode" class="form-select form-select-lg sleek-select w-100 input-sleek-lg" required>
                                <option value="" disabled selected>Select the approval channel...</option>
                                <option value="Messenger Chat">Messenger Chat</option>
                                <option value="Viber / WhatsApp Chat">Viber / WhatsApp Chat</option>
                                <option value="Email Confirmation">Email Confirmation</option>
                                <option value="Signed Quotation">Signed Quotation</option>
                                <option value="Official Client PO">Official Client PO</option>
                                <option value="In-Person Confirmation">In-Person Confirmation</option>
                                <option value="Other Written Confirmation">Other Written Confirmation</option>
                            </select>
                        </div>

                        <!-- Stylized File Upload Area -->
                        <div class="mb-4 text-start">
                            <label class="form-label label-upper-muted">Proof of Approval <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="file" name="po_file" id="approvalProofFile" class="form-control form-control-lg input-file-dashed" accept=".pdf,.png,.jpg,.jpeg" required>
                                <i class="fas fa-cloud-upload-alt icon-input-left"></i>
                            </div>
                            <div class="form-text mt-2 d-flex justify-content-between gap-2 fs-xs text-slate-500">
                                <span><i class="fas fa-info-circle text-primary me-1"></i> PDF, JPG, or PNG only (max. 10 MB)</span>
                                <span id="selectedProofName" class="text-truncate"></span>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2 pt-2">
                            <button type="button" class="btn btn-light w-50 py-2 btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success w-50 py-2 btn-modal-submit">Submit Approval</button>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            try {
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
            } catch(e) {
                console.error("DataTables Error: ", e);
                $('#grid-skeleton').hide();
                $('#grid-content').fadeIn(300);
            }
            
            // Absolute Fallback
            setTimeout(() => {
                if($('#grid-skeleton').is(':visible')) {
                    $('#grid-skeleton').hide();
                    $('#grid-content').fadeIn(300);
                }
            }, 1000);
        });

        // SweetAlert2 Toast Notification Configuration
        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            customClass: { popup: 'shadow-lg rounded-3' },
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        // Trigger Toasts based on PHP GET parameters
        <?php if(isset($_GET['success'])): ?>
            Toast.fire({
                icon: 'success',
                title: '<?php echo addslashes(htmlspecialchars($_GET['success'])); ?>'
            });
            window.history.replaceState(null, null, window.location.pathname);
        <?php endif; ?>

        <?php if(isset($_GET['error'])): ?>
            Toast.fire({
                icon: 'error',
                title: '<?php echo addslashes(htmlspecialchars($_GET['error'])); ?>'
            });
            window.history.replaceState(null, null, window.location.pathname);
        <?php endif; ?>

        function openReceivePoModal(quotationId, quotationNumber) {
            document.getElementById('modalQuotationId').value = quotationId;
            document.getElementById('modalQuoteNumber').innerText = quotationNumber;
            document.getElementById('approvalProofFile').value = '';
            document.getElementById('selectedProofName').innerText = '';
            
            var myModal = new bootstrap.Modal(document.getElementById('receivePoModal'));
            myModal.show();
        }

        document.addEventListener('click', function (event) {
            const button = event.target.closest('.submit-approval-btn');
            if (!button) return;
            openReceivePoModal(button.dataset.quotationId, button.dataset.quotationNumber);
        });

        document.getElementById('approvalProofFile').addEventListener('change', function () {
            const file = this.files[0];
            const selectedName = document.getElementById('selectedProofName');
            selectedName.innerText = file ? file.name : '';
        });
    </script>
</body>
</html>