<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch Main Quotation Data
$stmt = $conn->prepare("SELECT q.*, u.full_name as creator_name, u.role FROM quotations q LEFT JOIN users u ON q.created_by = u.user_id WHERE q.quotation_id = ?");
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$q_res = $stmt->get_result();

if($q_res->num_rows == 0) {
    header("Location: quotations_list.php?error=Quotation Not Found");
    exit();
}
$quote = $q_res->fetch_assoc();

// Fetch Quotation Items
$items_data = [];
$stmt_items = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ?");
$stmt_items->bind_param("i", $quotation_id);
$stmt_items->execute();
$items_res = $stmt_items->get_result();
while($i = $items_res->fetch_assoc()) {
    $items_data[] = $i;
}

// Keep the downstream PR visible from its source quotation.
$linked_pr = null;
$linked_pr_stmt = $conn->prepare("SELECT pr_id, pr_number, status FROM purchase_requests WHERE quotation_id = ? ORDER BY pr_id DESC LIMIT 1");
$linked_pr_stmt->bind_param("i", $quotation_id);
$linked_pr_stmt->execute();
$linked_pr = $linked_pr_stmt->get_result()->fetch_assoc();

$role = $_SESSION['role'];
$is_sales_staff = ($role === 'Sales Staff');
$can_create_pr = ($is_sales_staff && $quote['status'] === 'PO Received');

// Badge Logic
$s = $quote['status'];
$badge = 'bg-soft-warning';
$icon = 'fa-clock';
$status_label = 'Waiting for Client Approval';

if($s == 'PO Received') { $badge = 'bg-soft-success'; $icon = 'fa-check-double'; $status_label = 'Client Approved'; }
elseif($s == 'Converted to PR') { $badge = 'bg-soft-primary'; $icon = 'fa-exchange-alt'; $status_label = 'Converted to PR'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View Quotation <?php echo htmlspecialchars($quote['quotation_number']); ?> - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, .main-content { font-family: 'Inter', sans-serif; }
        .file-thumbnail { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #f8f9fa; border-radius: 4px; font-size: 1.2rem; border: 1px solid #ddd; }
        
        .bg-soft-warning { background: #fffbeb; color: #d97706; }
        .bg-soft-primary { background: #eff6ff; color: #2563eb; }
        .bg-soft-success { background: #ecfdf5; color: #059669; }

        @media screen { .print-only-quote { display: none; } }
        
        @media print {
            @page { size: A4; margin: 0; }
            body { background: white !important; color: #212529 !important; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important; font-size: 10pt !important; padding: 15mm !important; }
            .sidebar, .navbar, .no-print, .screen-only-cards { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; background: transparent !important; box-shadow: none !important; }
            .print-only-quote { display: block !important; width: 100%; }
            .print-header-brand { font-size: 26pt; font-weight: 900; color: #0d6efd !important; margin: 0; line-height: 1.1; letter-spacing: -0.5px; -webkit-print-color-adjust: exact; }
            .print-header-sub { font-size: 9pt; color: #6c757d !important; margin-top: 6px; line-height: 1.4; }
            .print-title-doc { font-size: 22pt; font-weight: 800; color: #343a40 !important; text-transform: uppercase; letter-spacing: 2px; }
            .info-box { border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-bottom: 25px; }
            .info-label { font-size: 8pt; text-transform: uppercase; color: #6c757d !important; font-weight: 700; margin-bottom: 4px; letter-spacing: 0.5px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 25px; border: 1px solid #dee2e6; }
            .print-table th { background-color: #0d6efd !important; color: white !important; -webkit-print-color-adjust: exact; padding: 12px 10px; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid #0d6efd; }
            .print-table td { padding: 12px 10px; border: 1px solid #dee2e6; font-size: 10pt; vertical-align: top; }
            .print-table tfoot td { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; padding: 15px 10px; border-top: 2px solid #0d6efd; }
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1200px;">
            
            <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 rounded shadow-sm border" style="border-radius: 12px !important;">
                <div class="d-flex align-items-center gap-3">
                    <a href="quotations_list.php" class="btn btn-sm btn-light border shadow-sm px-3" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-arrow-left me-2"></i> Back
                    </a>
                    <button class="btn btn-sm btn-primary shadow-sm px-3 fw-bold" style="border-radius: 8px;" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print
                    </button>
                </div>
                <div class="d-flex align-items-center gap-3 text-end">
                    <?php if($can_create_pr): ?>
                        <a href="create_pr.php?quotation_id=<?php echo $quotation_id; ?>" class="btn btn-sm btn-success shadow-sm fw-bold px-4" style="border-radius: 8px;">
                            <i class="fas fa-arrow-right me-1"></i> Create PR
                        </a>
                        <div class="vr bg-secondary opacity-25" style="width: 2px; height: 30px;"></div>
                    <?php endif; ?>
                    
                    <div style="line-height: 1.2;">
                        <span class="badge <?php echo $badge; ?> px-3 py-1 shadow-sm"><i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($status_label); ?></span>
                    </div>
                </div>
            </div>

            <div class="row g-4 screen-only-cards">
                <div class="col-md-8">
                    <div class="card shadow-sm border-0 h-100" style="border-radius: 16px;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3 pb-2 border-bottom border-light">
                                <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-file-contract fs-5"></i>
                                </div>
                                <h6 class="text-uppercase text-dark fw-bold m-0" style="letter-spacing: 0.5px;">Quotation Details</h6>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Quotation No.</small>
                                    <span class="fw-bold text-primary fs-5">#<?php echo htmlspecialchars($quote['quotation_number']); ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Client Name</small>
                                    <span class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($quote['client_name']); ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Prepared By</small>
                                    <span class="fw-medium text-dark"><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($quote['creator_name']); ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Date Issued</small>
                                    <span class="fw-medium text-dark"><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo date('F d, Y h:i A', strtotime($quote['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm border-0 mb-4 bg-primary bg-opacity-10 border-primary" style="border-radius: 16px;">
                        <div class="card-body p-4 text-center">
                            <h6 class="text-uppercase text-primary fw-bold small mb-2">Grand Total Estimate</h6>
                            <h2 class="fw-bold text-primary mb-0">₱ <?php echo number_format($quote['amount'], 2); ?></h2>
                        </div>
                    </div>

                    <?php if(!empty($quote['client_po_number'])): ?>
                    <div class="card shadow-sm border-0 border-start border-4 border-success" style="border-radius: 16px;">
                        <div class="card-body p-4">
                            <h6 class="text-uppercase text-success fw-bold small mb-3"><i class="fas fa-check-circle me-1"></i> Client Approval</h6>
                            <div class="mb-2">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Approval Reference</small>
                                <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($quote['client_po_number']); ?></span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Mode of Approval</small>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($quote['approval_mode']); ?></span>
                            </div>

                            <?php if(!empty($quote['po_file_path'])): 
                                $secureLink = "uploads/pos/" . htmlspecialchars($quote['po_file_path']);
                                $ext = strtolower(pathinfo($quote['po_file_path'], PATHINFO_EXTENSION));
                                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                            ?>
                            <div class="p-2 bg-light rounded border d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2" style="overflow: hidden;">
                                    <?php if($isImage): ?>
                                        <img src="<?php echo $secureLink; ?>" class="file-thumbnail bg-white" onclick="viewFile('<?php echo $secureLink; ?>', 'image')" style="cursor: pointer;">
                                    <?php else: ?>
                                        <div class="file-icon text-danger bg-white shadow-sm" onclick="viewFile('<?php echo $secureLink; ?>', 'pdf')" style="cursor: pointer;"><i class="fas fa-file-pdf"></i></div>
                                    <?php endif; ?>
                                    <div class="text-truncate">
                                        <span class="text-dark fw-bold small d-block text-truncate">Proof of Approval</span>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo strtoupper($ext); ?></small>
                                    </div>
                                </div>
                                <a href="<?php echo $secureLink; ?>" download class="btn btn-sm btn-white border"><i class="fas fa-download text-primary"></i></a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card shadow-sm border-0 border-start border-4 border-warning" style="border-radius: 16px;">
                        <div class="card-body p-4">
                            <h6 class="text-uppercase text-warning fw-bold small mb-2"><i class="fas fa-clock me-1"></i> Client Approval</h6>
                            <p class="text-muted small m-0 fst-italic">Waiting for the client’s confirmation and supporting proof of approval.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($linked_pr): ?>
            <div class="alert alert-primary border-0 shadow-sm d-flex align-items-center justify-content-between mt-4 mb-4" style="border-radius: 14px;">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
                        <i class="fas fa-link"></i>
                    </div>
                    <div>
                        <div class="fw-bold">Linked Purchase Request: <?php echo htmlspecialchars($linked_pr['pr_number']); ?></div>
                        <small class="text-muted">Status: <?php echo htmlspecialchars($linked_pr['status']); ?></small>
                    </div>
                </div>
                <a href="view_pr.php?id=<?php echo (int)$linked_pr['pr_id']; ?>" class="btn btn-sm btn-outline-primary fw-bold">View PR</a>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-bottom border-light">
                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-list-ul me-2 text-primary"></i> Quoted Items</h6>
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
                                <?php foreach($items_data as $item): ?>
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
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PRINT LAYOUT (Hidden from screen) -->
        <div class="print-only-quote">
            <div class="d-flex justify-content-between align-items-start" style="border-bottom: 3px solid #0d6efd; padding-bottom: 20px; margin-bottom: 30px;">
                <div>
                    <h1 class="print-header-brand">Fixie Computer Ventures</h1>
                    <div class="print-header-sub">
                        <strong>Driven by Innovation, Defined by Service.</strong><br>
                        123 Technology Avenue, Tech Hub City, Philippines 1000<br>
                        Phone: (02) 8123-4567 | Email: sales@fixie.com
                    </div>
                </div>
                <div class="text-end">
                    <div class="print-title-doc">QUOTATION</div>
                    <div style="font-size: 13pt; margin-top: 8px; font-weight: 500;">
                        Quote No: <strong style="color: #0d6efd !important;">#<?php echo htmlspecialchars($quote['quotation_number']); ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-7">
                    <div class="info-box h-100" style="background-color: #f8f9fa !important; -webkit-print-color-adjust: exact;">
                        <div class="info-label">Prepared For:</div>
                        <h4 class="fw-bold m-0 text-dark" style="font-size: 14pt;"><?php echo htmlspecialchars($quote['client_name']); ?></h4>
                    </div>
                </div>
                <div class="col-5">
                    <div class="info-box h-100">
                        <table style="width: 100%; font-size: 9.5pt;">
                            <tr>
                                <td class="info-label" style="padding-bottom: 10px; width: 45%;">Date Issued:</td>
                                <td style="text-align: right; font-weight: bold; padding-bottom: 10px; color: #212529; border-bottom: 1px solid #eee;"><?php echo date('F d, Y', strtotime($quote['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <td class="info-label" style="padding: 10px 0;">Prepared By:</td>
                                <td style="text-align: right; font-weight: bold; padding: 10px 0; color: #212529; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($quote['creator_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <table class="print-table">
                <thead>
                    <tr>
                        <th style="text-align: center; width: 5%;">#</th>
                        <th style="text-align: left; width: 50%;">ITEM DESCRIPTION & SPECIFICATIONS</th>
                        <th style="text-align: center; width: 10%;">QTY</th>
                        <th style="text-align: right; width: 15%;">UNIT PRICE</th>
                        <th style="text-align: right; width: 20%;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $ctr = 1; foreach($items_data as $item): ?>
                    <tr>
                        <td style="text-align: center; color: #6c757d; font-weight: bold;"><?php echo $ctr++; ?></td>
                        <td>
                            <div style="font-weight: bold; color: #000; font-size: 10.5pt;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div style="color: #495057; font-size: 9pt; margin-top: 4px; line-height: 1.4;"><?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?></div>
                        </td>
                        <td style="text-align: center; font-weight: 500;"><?php echo $item['quantity']; ?></td>
                        <td style="text-align: right; white-space: nowrap;">₱ <?php echo number_format($item['unit_price'], 2); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #000; white-space: nowrap;">₱ <?php echo number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align: right; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Grand Total Estimate</td>
                        <td style="text-align: right; font-weight: 900; font-size: 14pt; color: #0d6efd !important; white-space: nowrap;">₱ <?php echo number_format($quote['amount'], 2); ?></td>
                    </tr>
                </tfoot>
            </table>
            
            <p style="text-align: center; margin-top: 30px; font-size: 9pt; color: #6c757d; font-style: italic;">
                This quotation is subject to terms and conditions. Valid for 30 days from the date of issue.
            </p>
        </div>

    </div>

    <!-- File Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none;">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">File Preview</h5>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0 bg-light mt-3" id="previewBody" style="min-height: 400px; display: flex; align-items: center; justify-content: center;">
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            const myModal = new bootstrap.Modal(document.getElementById('previewModal'));
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            
            if (type === 'image') {
                modalBody.innerHTML = `<img src="${path}" class="img-fluid" style="max-height: 80vh;">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" style="border:none;"></iframe>`;
            } else {
                modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary fw-bold">Download File</a></div>`;
            }
            myModal.show();
        }
    </script>
</body>
</html>
