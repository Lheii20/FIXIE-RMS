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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View PR <?php echo htmlspecialchars($pr['pr_number']); ?> - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1200px;">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center">
                    <a href="pr_list.php" class="btn btn-light border me-3"><i class="fas fa-arrow-left"></i></a>
                    <div>
                        <h2 class="fw-bold mb-0 text-dark">Purchase Request Details</h2>
                        <p class="text-muted mb-0">Review the requested items before approval.</p>
                    </div>
                </div>
                <div class="d-flex align-items-center">
                    <?php if($pr['status'] == 'Pending'): ?>
                        <span class="badge bg-warning text-dark border border-warning fs-6 px-3 py-2">Pending Approval</span>
                    <?php elseif($pr['status'] == 'Approved'): ?>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success fs-6 px-3 py-2">Approved</span>
                    <?php elseif($pr['status'] == 'Rejected'): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger fs-6 px-3 py-2">Rejected</span>
                    <?php elseif($pr['status'] == 'Converted_to_PO'): ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary fs-6 px-3 py-2">Converted to PO</span>
                    <?php endif; ?>

                    <?php if($can_convert): ?>
                        <a href="create_po.php?pr_id=<?php echo $pr_id; ?>" class="btn btn-primary ms-3 shadow-sm">
                            <i class="fas fa-file-invoice me-1"></i> Convert to PO
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            
            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-md-8">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted fw-bold small mb-3">General Information</h6>
                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <small class="text-muted d-block">PR Number</small>
                                    <span class="fw-bold text-primary fs-5">#<?php echo htmlspecialchars($pr['pr_number']); ?></span>
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <small class="text-muted d-block">Client Name</small>
                                    <span class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($pr['client_name']); ?></span>
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <small class="text-muted d-block">Requested By</small>
                                    <span class="fw-medium text-dark"><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($pr['full_name']); ?> (<?php echo htmlspecialchars($pr['role']); ?>)</span>
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <small class="text-muted d-block">Date Created</small>
                                    <span class="fw-medium text-dark"><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo date('F d, Y h:i A', strtotime($pr['date_created'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 h-100 bg-primary bg-opacity-10 border-primary">
                        <div class="card-body d-flex flex-column justify-content-center text-center">
                            <h6 class="text-uppercase text-primary fw-bold small mb-2">Grand Total</h6>
                            <h2 class="fw-bold text-primary mb-0">₱ <?php echo number_format($pr['amount'], 2); ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-list-ul me-2 text-primary"></i> Requested Items List</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary small text-uppercase">
                                <tr>
                                    <th class="ps-4">Item & Specifications</th>
                                    <th>Category & Brand</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end pe-4">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($item = $items_stmt->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <?php if(!empty($item['specifications'])): ?>
                                            <small class="text-muted fst-italic"><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($item['category']); ?></span>
                                        <?php if(!empty($item['brand'])): ?>
                                            <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($item['brand']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-medium"><?php echo $item['quantity']; ?></td>
                                    <td class="text-end">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="text-end pe-4 fw-bold">₱<?php echo number_format($item['total_price'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if($can_approve): ?>
            <div class="card shadow-sm border-0 border-top border-warning border-3 mb-5">
                <div class="card-body p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">Approval Decision</h5>
                        <p class="text-muted small m-0">Please review the details above before making a decision. This action will notify the requestor.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <form action="actions/pr_handler.php" method="POST" onsubmit="return confirm('Are you sure you want to REJECT this request?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="reject_pr">
                            <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">
                            <button type="submit" class="btn btn-outline-danger px-4 fw-bold"><i class="fas fa-times me-2"></i> Reject PR</button>
                        </form>
                        
                        <form action="actions/pr_handler.php" method="POST" onsubmit="return confirm('Are you sure you want to APPROVE this request?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="approve_pr">
                            <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">
                            <button type="submit" class="btn btn-success px-4 fw-bold"><i class="fas fa-check me-2"></i> Approve PR</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>