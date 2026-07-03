<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$pr_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->query("SELECT p.*, u.full_name, u.role FROM purchase_requests p LEFT JOIN users u ON p.created_by = u.user_id WHERE p.pr_id = $pr_id");
if($stmt->num_rows == 0) {
    header("Location: pr_list.php?error=PR Not Found");
    exit();
}
$pr = $stmt->fetch_assoc();

$items_stmt = $conn->query("SELECT * FROM pr_items WHERE pr_id = $pr_id");

$role = $_SESSION['role'];
$can_approve = in_array($role, ['GM', 'President']) && $pr['status'] == 'Pending';
$can_convert = ($role == 'Procurement' && $pr['status'] == 'Approved');

// Safely fetch rejection reason dynamically
$pr_remarks = isset($pr['remarks']) ? $pr['remarks'] : '';
$rejection_reason = "";

if ($pr['status'] == 'Rejected') {
    if (!empty($pr_remarks)) {
        $rejection_reason = $pr_remarks;
    } else {
        // Fallback: Check if there is a pr_history table and fetch the latest rejection remarks
        $check_hist = $conn->query("SHOW TABLES LIKE 'pr_history'");
        if ($check_hist && $check_hist->num_rows > 0) {
            $rej_stmt = $conn->prepare("SELECT remarks FROM pr_history WHERE pr_id = ? AND status_to LIKE '%Rejected%' ORDER BY timestamp DESC LIMIT 1");
            if($rej_stmt) {
                $rej_stmt->bind_param("i", $pr_id);
                $rej_stmt->execute();
                $rej_res = $rej_stmt->get_result();
                if ($r = $rej_res->fetch_assoc()) {
                    $rejection_reason = $r['remarks'];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View PR <?php echo htmlspecialchars($pr['pr_number']); ?> - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, .main-content { font-family: 'Inter', sans-serif; }
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
        .reject-callout {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1200px;">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center">
                    <a href="pr_list.php" class="btn btn-light border me-3 shadow-sm" style="border-radius: 10px;"><i class="fas fa-arrow-left"></i></a>
                    <div>
                        <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">Purchase Request Details</h3>
                        <p class="text-muted mb-0" style="font-size: 0.85rem;">Review the requested items before approval.</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if($pr['status'] == 'Pending'): ?>
                        <span class="badge bg-warning text-dark border border-warning px-3 py-2 shadow-sm" style="font-size: 0.8rem;"><i class="fas fa-clock me-1"></i> Pending Approval</span>
                    <?php elseif($pr['status'] == 'Approved'): ?>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 shadow-sm" style="font-size: 0.8rem;"><i class="fas fa-check-circle me-1"></i> Approved</span>
                    <?php elseif($pr['status'] == 'Rejected'): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2 shadow-sm" style="font-size: 0.8rem;"><i class="fas fa-times-circle me-1"></i> Rejected</span>
                    <?php elseif($pr['status'] == 'Converted_to_PO'): ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 shadow-sm" style="font-size: 0.8rem;"><i class="fas fa-file-invoice me-1"></i> Converted to PO</span>
                    <?php endif; ?>

                    <?php if($can_convert): ?>
                        <a href="create_po.php?pr_id=<?php echo $pr_id; ?>" class="btn btn-primary shadow-sm fw-bold px-3 ms-2" style="border-radius: 8px; font-size: 0.85rem;">
                            <i class="fas fa-plus-circle me-1"></i> Convert to PO
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-8">
                    <div class="card shadow-sm border-0 h-100" style="border-radius: 16px;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3 pb-2 border-bottom border-light">
                                <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-info-circle fs-5"></i>
                                </div>
                                <h6 class="text-uppercase text-dark fw-bold m-0" style="letter-spacing: 0.5px;">General Information</h6>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">PR Number</small>
                                    <span class="fw-bold text-primary fs-6">#<?php echo htmlspecialchars($pr['pr_number']); ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Client Name</small>
                                    <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($pr['client_name']); ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Requested By</small>
                                    <span class="fw-medium text-dark" style="font-size: 0.9rem;"><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($pr['full_name']); ?> <span class="text-muted small">(<?php echo htmlspecialchars($pr['role']); ?>)</span></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Date Created</small>
                                    <span class="fw-medium text-dark" style="font-size: 0.9rem;"><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo date('F d, Y h:i A', strtotime($pr['date_created'])); ?></span>
                                </div>
                            </div>

                            <!-- Sleek Inline Rejection Reason -->
                            <?php if($pr['status'] == 'Rejected' && !empty($rejection_reason)): ?>
                            <div class="reject-callout">
                                <div class="text-danger fw-bold small text-uppercase mb-1" style="letter-spacing: 0.5px;"><i class="fas fa-exclamation-triangle me-1"></i> Reason for Rejection</div>
                                <div class="text-dark fw-medium" style="font-size: 0.9rem;">&ldquo;<?php echo nl2br(htmlspecialchars($rejection_reason)); ?>&rdquo;</div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 h-100" style="border-radius: 16px; background: linear-gradient(135deg, #eff6ff, #dbeafe);">
                        <div class="card-body p-4 d-flex flex-column justify-content-center text-center">
                            <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3 shadow-sm" style="width: 48px; height: 48px;">
                                <i class="fas fa-coins fs-4"></i>
                            </div>
                            <h6 class="text-uppercase text-primary fw-bold small mb-2" style="letter-spacing: 0.5px;">Grand Total Estimate</h6>
                            <h2 class="fw-bold text-primary mb-0" style="letter-spacing: -0.5px;">₱ <?php echo number_format($pr['amount'], 2); ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-bottom border-light">
                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-list-ul me-2 text-primary"></i> Requested Items List</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                <tr>
                                    <th class="ps-4 py-3 border-bottom-0">Item & Specifications</th>
                                    <th class="py-3 border-bottom-0">Category & Brand</th>
                                    <th class="text-center py-3 border-bottom-0">Qty</th>
                                    <th class="text-end py-3 border-bottom-0">Unit Price</th>
                                    <th class="text-end pe-4 py-3 border-bottom-0">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($item = $items_stmt->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <?php if(!empty($item['specifications'])): ?>
                                            <div class="text-muted fst-italic mt-1" style="font-size: 0.8rem;"><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border me-1 px-2 py-1"><?php echo htmlspecialchars($item['category']); ?></span>
                                        <?php if(!empty($item['brand'])): ?>
                                            <span class="badge bg-light text-secondary border px-2 py-1"><?php echo htmlspecialchars($item['brand']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold text-dark"><?php echo $item['quantity']; ?></td>
                                    <td class="text-end text-muted fw-medium" style="font-family: monospace;">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="text-end pe-4 fw-bold text-primary" style="font-family: monospace;">₱<?php echo number_format($item['total_price'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if($can_approve): ?>
            <div class="card shadow-sm border-0 border-top border-warning border-3 mb-5" style="border-radius: 16px;">
                <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">Approval Decision</h5>
                        <p class="text-muted small m-0">Review the details above before making a decision. The requestor will be notified.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-danger px-4 fw-bold shadow-sm" style="border-radius: 8px;" onclick="confirmRejectPR(event, '<?php echo $pr_id; ?>', '<?php echo htmlspecialchars($pr['pr_number']); ?>')">
                            <i class="fas fa-times me-2"></i> Reject PR
                        </button>
                        <button type="button" class="btn btn-success px-4 fw-bold shadow-sm" style="border-radius: 8px;" onclick="confirmApprovePR(event, '<?php echo $pr_id; ?>', '<?php echo htmlspecialchars($pr['pr_number']); ?>')">
                            <i class="fas fa-check me-2"></i> Approve PR
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Hidden Form for SweetAlert Submission -->
    <form id="dynamicActionForm" action="actions/pr_handler.php" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
        <input type="hidden" name="action" id="dynamicAction">
        <input type="hidden" name="pr_id" id="dynamicPrId">
        <input type="hidden" name="remarks" id="dynamicRemarks">
    </form>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // SweetAlert2 Toast Notification Configuration (Moved to bottom-end)
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

        // Trigger Toasts based on PHP GET parameters (Alert Banners removed)
        <?php if(isset($_GET['success'])): ?>
            Toast.fire({
                icon: 'success',
                title: '<?php echo addslashes(htmlspecialchars($_GET['success'])); ?>'
            });
            window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $pr_id; ?>");
        <?php endif; ?>

        <?php if(isset($_GET['error'])): ?>
            Toast.fire({
                icon: 'error',
                title: '<?php echo addslashes(htmlspecialchars($_GET['error'])); ?>'
            });
            window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $pr_id; ?>");
        <?php endif; ?>

        // Safe Approval Function
        function confirmApprovePR(e, id, prNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Request?',
                html: "<span class='text-muted' style='font-size: 0.9rem;'>Are you sure you want to approve <b>" + prNumber + "</b>?</span>",
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
                html: "<span class='text-muted' style='font-size: 0.9rem;'>Please state the reason for rejecting <b>" + prNumber + "</b>:</span>",
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