<?php
require 'config/db_connect.php';
require 'config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales Staff') {
    header("Location: dashboard.php");
    exit();
}

$query = "SELECT * FROM quotations ORDER BY created_at DESC";
$result = $conn->query($query);
$quotations = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $quotations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Quotations Tracker - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1400px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold mb-0">Quotations Tracker</h2>
                    <p class="text-muted mb-0">Manage quotations and automated client approvals.</p>
                </div>
                <a href="create_quotation.php" class="btn btn-primary shadow-sm">
                    <i class="fas fa-plus me-2"></i> Create Quotation
                </a>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Quotation #</th>
                                    <th>Client Name</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Tracking PO #</th>
                                    <th class="text-end pe-4" style="width: 220px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($quotations)): ?>
                                    <?php foreach ($quotations as $row): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold"><?php echo htmlspecialchars($row['quotation_number']); ?></td>
                                            <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                            <td>₱ <?php echo number_format($row['amount'], 2); ?></td>
                                            <td>
                                                <?php if($row['status'] == 'Pending PO'): ?>
                                                    <span class="badge bg-warning text-dark">Waiting for Client</span>
                                                <?php elseif($row['status'] == 'PO Received'): ?>
                                                    <span class="badge bg-info text-dark">Approved by Client</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Converted to PR</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if(!empty($row['po_file_path'])): ?>
                                                    <span class="text-primary fw-bold small"><?php echo htmlspecialchars($row['client_po_number']); ?></span>
                                                    <br><small class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($row['approval_mode']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="d-inline-flex gap-1 align-items-center justify-content-end w-100">
                                                    
                                                    <?php if(!empty($row['po_file_path'])): ?>
                                                        <button type="button" class="btn btn-sm btn-light border py-1 px-2 text-secondary shadow-sm" data-bs-toggle="modal" data-bs-target="#previewModal<?php echo $row['quotation_id']; ?>" title="Preview Approval (<?php echo htmlspecialchars($row['approval_mode']); ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($row['status'] == 'Pending PO'): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#receivePoModal<?php echo $row['quotation_id']; ?>">
                                                            <i class="fas fa-upload me-1"></i> Upload Approval
                                                        </button>
                                                    <?php elseif ($row['status'] == 'PO Received'): ?>
                                                        <a href="create_pr.php?quotation_id=<?php echo $row['quotation_id']; ?>" class="btn btn-sm btn-success fw-bold shadow-sm">
                                                            <i class="fas fa-share me-1"></i> Create PR
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-light text-muted border-0" disabled style="font-size: 0.8rem; font-weight: 600;">Done</button>
                                                    <?php endif; ?>

                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5">No quotations tracked yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($quotations)): ?>
        <?php foreach ($quotations as $row): ?>
            <?php if ($row['status'] == 'Pending PO'): ?>
                <div class="modal fade" id="receivePoModal<?php echo $row['quotation_id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <form action="actions/quotation_handler.php" method="POST" enctype="multipart/form-data">
                                <div class="modal-header">
                                    <h5 class="modal-title fw-bold">Upload Client Approval</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="receive_po">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                                    <input type="hidden" name="quotation_id" value="<?php echo $row['quotation_id']; ?>">

                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold">Approval Mode <span class="text-danger">*</span></label>
                                        <select name="approval_mode" class="form-select" required>
                                            <option value="Formal PO">Formal Purchase Order</option>
                                            <option value="Signed Quotation">Signed Quotation</option>
                                            <option value="Email Confirmation">Email Confirmation</option>
                                            <option value="Chat/Viber Agreement">Chat / Viber Agreement</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold">Upload Proof (PDF, JPG, PNG) <span class="text-danger">*</span></label>
                                        <input type="file" name="po_file" class="form-control" accept=".pdf, .jpg, .jpeg, .png" required>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-sm btn-light border px-3" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">Upload & Approve</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($quotations)): ?>
        <?php foreach ($quotations as $row): ?>
            <?php if (!empty($row['po_file_path'])): ?>
                <?php 
                    $file_ext = strtolower(pathinfo($row['po_file_path'], PATHINFO_EXTENSION));
                    $file_url = "uploads/pos/" . htmlspecialchars($row['po_file_path']);
                ?>
                <div class="modal fade" id="previewModal<?php echo $row['quotation_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header">
                                <h5 class="modal-title fw-bold text-dark">
                                    <i class="fas fa-eye text-info d-inline-block me-2"></i> File Preview: <?php echo htmlspecialchars($row['quotation_number']); ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body bg-light p-3 text-center">
                                <div class="alert bg-white border shadow-sm text-start py-2 mb-3">
                                    <div class="row small text-muted g-2">
                                        <div class="col-md-6"><strong>Client Name:</strong> <?php echo htmlspecialchars($row['client_name']); ?></div>
                                        <div class="col-md-6"><strong>Approval Mode:</strong> <?php echo htmlspecialchars($row['approval_mode']); ?></div>
                                        <div class="col-md-6"><strong>Auto PO Number:</strong> <span class="text-primary fw-bold"><?php echo htmlspecialchars($row['client_po_number']); ?></span></div>
                                        <div class="col-md-6"><strong>Total Amount:</strong> ₱ <?php echo number_format($row['amount'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="preview-container bg-white rounded border p-2 shadow-sm overflow-auto" style="max-height: 520px;">
                                    <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png'])): ?>
                                        <img src="<?php echo $file_url; ?>" class="img-fluid rounded" alt="Client Approval Attachment" style="max-height: 480px; object-fit: contain;">
                                    <?php elseif ($file_ext === 'pdf'): ?>
                                        <embed src="<?php echo $file_url; ?>" type="application/pdf" width="100%" height="480px" class="rounded">
                                    <?php else: ?>
                                        <div class="p-4 text-muted">
                                            <i class="fas fa-file fa-3x mb-2"></i><br>
                                            Preview not supported for this file type.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <a href="<?php echo $file_url; ?>" download class="btn btn-sm btn-primary px-3 fw-bold shadow-sm">
                                    <i class="fas fa-download me-1"></i> Download Original
                                </a>
                                <button type="button" class="btn btn-sm btn-secondary px-3 border" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>