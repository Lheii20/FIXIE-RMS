<?php 
require 'config/db_connect.php'; 
date_default_timezone_set('Asia/Manila');

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$po_id = $_GET['id'] ?? 0;
if(!is_numeric($po_id)) die("Invalid PO ID");

$current_role = $_SESSION['role'];

// Mark notifications as read
$mark_sql = "UPDATE notifications 
             SET is_read = 1 
             WHERE target_role = ? 
             AND is_read = 0 
             AND (message LIKE CONCAT('%PO #', ?, '%') OR message LIKE CONCAT('%PO #', (SELECT po_number FROM purchase_orders WHERE po_id=?), '%'))";
$stmt_mark = $conn->prepare($mark_sql);
$stmt_mark->bind_param("sis", $current_role, $po_id, $po_id);
$stmt_mark->execute();

$stmt = $conn->prepare("SELECT p.*, u.full_name as creator_name FROM purchase_orders p LEFT JOIN users u ON p.created_by = u.user_id WHERE p.po_id = ?");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$po_query = $stmt->get_result();

if($po_query->num_rows == 0) die("PO Not Found.");
$po = $po_query->fetch_assoc();

// DYNAMIC WORKFLOW LOGIC (Quering `workflow_rules` instead of hardcoding)
$role = $_SESSION['role'];
$status = $po['status'];
$is_approver = false;
$approve_action = '';
$approve_label = '';
$can_reject = false;

$stmt_rules = $conn->prepare("SELECT * FROM workflow_rules WHERE required_role = ? AND current_status = ?");
$stmt_rules->bind_param("ss", $role, $status);
$stmt_rules->execute();
$res_rules = $stmt_rules->get_result();

if ($res_rules->num_rows > 0) {
    $is_approver = true;
    while ($rule = $res_rules->fetch_assoc()) {
        if ($rule['action_key'] === 'reject') {
            $can_reject = true;
        } else {
            $approve_action = $rule['action_key'];
            $approve_label = $rule['button_label'];
        }
    }
}

// Automatically mark as viewed if an approver visits the page
if ($is_approver && isset($po['is_viewed']) && $po['is_viewed'] == 0) {
    $conn->query("UPDATE purchase_orders SET is_viewed = 1 WHERE po_id = $po_id");
    $po['is_viewed'] = 1;
}

$items_data = [];
$stmt_items = $conn->prepare("SELECT * FROM po_items WHERE po_id = ?");
$stmt_items->bind_param("i", $po_id);
$stmt_items->execute();
$items_res = $stmt_items->get_result();
while($i = $items_res->fetch_assoc()) {
    $items_data[] = $i;
}

// Payment table creation if missing
$conn->query("CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$total_paid = 0;
$payments = [];
$balance = $po['amount'];

$stmt = $conn->prepare("SELECT * FROM payments WHERE po_id = ? ORDER BY payment_date DESC");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$payment_query = $stmt->get_result();

while($p = $payment_query->fetch_assoc()){
    $total_paid += $p['amount_paid'];
    $payments[] = $p;
}
$balance = $po['amount'] - $total_paid;

$can_delete_files = in_array($role, ['GM', 'President', 'Procurement']);
$can_upload_files = ($role == 'Procurement');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View PO #<?php echo htmlspecialchars($po['po_number']); ?> - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .file-thumbnail { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #f8f9fa; border-radius: 4px; font-size: 1.2rem; border: 1px solid #ddd; }
        
        .payment-card { border-left: 4px solid #198754 !important; }
        .btn-check:checked + .btn-outline-success { background-color: #198754; color: white; border-color: #198754; }
        .btn-check:checked + .btn-outline-warning { background-color: #ffc107; color: #000; border-color: #ffc107; }
        
        @media screen { .print-only-po { display: none; } }
        
        @media print {
            @page { size: A4; margin: 0; }
            body { background: white !important; color: #212529 !important; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important; font-size: 10pt !important; padding: 15mm !important; }
            .sidebar, .navbar, .no-print, .screen-only-cards { display: none !important; }
            .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; background: transparent !important; box-shadow: none !important; }
            .print-only-po { display: block !important; width: 100%; }
            .draft-banner { color: #FF0000 !important; background-color: transparent !important; border: 3px solid #FF0000 !important; text-align: center; font-weight: 900; font-size: 14pt; padding: 10px; margin-bottom: 25px; text-transform: uppercase; letter-spacing: 2px; border-radius: 4px; }
            .print-header-brand { font-size: 26pt; font-weight: 900; color: #0d6efd !important; margin: 0; line-height: 1.1; letter-spacing: -0.5px; -webkit-print-color-adjust: exact; }
            .print-header-sub { font-size: 9pt; color: #6c757d !important; margin-top: 6px; line-height: 1.4; }
            .print-title-doc { font-size: 24pt; font-weight: 800; color: #343a40 !important; text-transform: uppercase; letter-spacing: 2px; }
            .info-box { border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; margin-bottom: 25px; }
            .info-label { font-size: 8pt; text-transform: uppercase; color: #6c757d !important; font-weight: 700; margin-bottom: 4px; letter-spacing: 0.5px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 25px; border: 1px solid #dee2e6; }
            .print-table th { background-color: #0d6efd !important; color: white !important; -webkit-print-color-adjust: exact; padding: 12px 10px; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid #0d6efd; }
            .print-table td { padding: 12px 10px; border: 1px solid #dee2e6; font-size: 10pt; vertical-align: top; }
            .print-table tfoot td { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; padding: 15px 10px; border-top: 2px solid #0d6efd; }
            .signature-section { margin-top: 60px; page-break-inside: avoid; }
            .sig-line { border-bottom: 1px solid #212529; margin-bottom: 8px; height: 40px; width: 80%; margin-left: auto; margin-right: auto; }
            .sig-name { font-weight: bold; font-size: 10pt; text-transform: uppercase; color: #212529 !important; }
            .sig-title { font-size: 8.5pt; color: #6c757d !important; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible no-print fade show shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible no-print fade show shadow-sm" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> Error: <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 rounded shadow-sm border">
            <a href="po_list.php" class="btn btn-sm btn-outline-secondary px-3 shadow-sm" style="font-weight: 500;">
                <i class="fas fa-arrow-left me-2"></i> Back
            </a>
            
            <div class="d-flex align-items-center gap-2 text-end">
                
                <?php if ($is_approver): ?>
                    <form action="actions/po_handler.php" method="POST" class="d-inline-flex align-items-center gap-2 m-0 p-0" onsubmit="return confirm('Execute workflow action?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="po_id" value="<?php echo $po['po_id']; ?>">
                        
                        <button type="submit" name="action" value="<?php echo $approve_action; ?>" class="btn btn-sm btn-success px-3 shadow-sm" style="font-weight: 500;">
                            <i class="fas fa-check-circle me-1"></i> <?php echo $approve_label; ?>
                        </button>

                        <?php if ($can_reject): ?>
                            <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger px-3 shadow-sm bg-white" style="font-weight: 500;" onclick="return confirm('Are you sure you want to REJECT this PO?');">
                                <i class="fas fa-times-circle me-1"></i> Reject
                            </button>
                        <?php endif; ?>
                    </form>

                    <div class="vr bg-secondary opacity-25 mx-2" style="width: 2px; height: 30px;"></div>
                <?php endif; ?>

                <button class="btn btn-sm btn-primary shadow-sm px-3" style="font-weight: 500;" onclick="logAndPrint('PO #<?php echo htmlspecialchars($po['po_number']); ?>')">
                    <i class="fas fa-print me-1"></i> Print PO
                </button>
                
                <div class="border-start ps-3 ms-2 text-start" style="line-height: 1.2;">
                    <span class="badge badge-status status-<?php echo str_replace([' ', '/'], '_', $po['status']); ?> px-3 py-1 mb-1 d-inline-block shadow-sm"><?php echo $po['status']; ?></span><br>
                    <small class="text-muted fw-bold" style="font-size: 0.75rem;">Loc: <?php echo htmlspecialchars($po['current_location']); ?></small>
                </div>
            </div>
        </div>

        <div class="row g-4 screen-only-cards">
            <div class="col-lg-8">
                
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 border-bottom-0">
                        <h3 class="fw-bold text-primary m-0">PO #<?php echo htmlspecialchars($po['po_number']); ?></h3>
                    </div>
                    <div class="card-body pt-0">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-bold">Client Name</label>
                                <div class="fs-5 fw-bold text-dark"><?php echo htmlspecialchars($po['client_name']); ?></div>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <label class="small text-muted text-uppercase fw-bold">Total Amount</label>
                                <div class="fs-4 fw-bold text-dark">₱ <?php echo number_format($po['amount'], 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-bold">Quotation Ref</label>
                                <div class="fw-medium text-dark"><?php echo htmlspecialchars($po['quotation_number']) ?: '--'; ?></div>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <label class="small text-muted text-uppercase fw-bold">Date Created</label>
                                <div class="fw-medium text-dark"><?php echo date('F d, Y', strtotime($po['date_created'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="fas fa-list-alt me-2 text-primary"></i> Order Specifications
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light small text-uppercase text-secondary">
                                    <tr>
                                        <th class="ps-4">Item Details</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end pe-4">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($items_data as $item): ?>
                                        <tr>
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($item['category'] ?? 'Item'); ?></span>
                                                </div>
                                                <div class="fw-bold mt-1 text-dark"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="small text-muted fst-italic"><?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?></div>
                                            </td>
                                            <td class="text-center fw-medium"><?php echo $item['quantity']; ?></td>
                                            <td class="text-end fw-medium" style="white-space: nowrap;">₱ <?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td class="text-end pe-4 fw-bold text-dark" style="white-space: nowrap;">₱ <?php echo number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php 
                $payment_visible_statuses = ['Delivered', 'Partially Paid', 'Partially-Collected', 'Collected'];
                
                if(in_array($po['status'], $payment_visible_statuses) || stripos($po['current_location'], 'Delivered') !== false || stripos($po['current_location'], 'Collection') !== false): 
                ?>
                <div class="card border-0 shadow-sm mb-4 payment-card">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center border-bottom">
                        <span class="fs-6 text-dark"><i class="fas fa-hand-holding-usd me-2 text-success"></i> Payment History</span>
                        
                        <?php if($balance > 0.01): ?>
                            <span class="badge bg-warning text-dark px-3 py-2 shadow-sm">Balance: ₱ <?php echo number_format($balance, 2); ?></span>
                        <?php else: ?>
                            <span class="badge bg-success px-3 py-2 shadow-sm"><i class="fas fa-check-double me-1"></i> Fully Paid</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light small text-secondary">
                                <tr>
                                    <th class="ps-4">Date & Time</th>
                                    <th>Payment Notes</th>
                                    <th class="text-end pe-4">Amount Paid</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($payments) > 0): 
                                    foreach($payments as $pay): ?>
                                    <tr>
                                        <td class="ps-4 small py-3">
                                            <div class="fw-bold text-dark"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></div>
                                            <div class="text-muted"><?php echo date('h:i A', strtotime($pay['payment_date'])); ?></div>
                                        </td>
                                        <td class="small text-muted py-3 align-middle">
                                            <?php if(stripos($pay['notes'], 'Full') !== false): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success me-1">Full Payment</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-dark border border-warning me-1">Partial Payment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-success align-middle py-3" style="white-space: nowrap;">+ ₱ <?php echo number_format($pay['amount_paid'], 2); ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted small"><i class="fas fa-info-circle me-1"></i> No payments recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($balance > 0.01 && $_SESSION['role'] == 'Finance'): ?>
                    <div class="card-footer bg-light p-4 border-top">
                        <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-plus-circle me-2"></i> Record New Payment</h6>
                        <form action="actions/po_handler.php" method="POST" id="paymentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_payment">
                            <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                            
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted mb-1">Payment Type</label>
                                    <div class="btn-group w-100 shadow-sm" role="group">
                                        <input type="radio" class="btn-check" name="pay_type" id="pay_full" autocomplete="off" onclick="togglePaymentInput('full')">
                                        <label class="btn btn-outline-success btn-sm fw-bold" for="pay_full">Full</label>
                                        
                                        <input type="radio" class="btn-check" name="pay_type" id="pay_partial" autocomplete="off" checked onclick="togglePaymentInput('partial')">
                                        <label class="btn btn-outline-warning btn-sm fw-bold text-dark" for="pay_partial">Partial</label>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="small fw-bold text-muted mb-1">Date Received</label>
                                    <input type="datetime-local" name="payment_date" class="form-control form-control-sm fw-medium shadow-sm" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted mb-1">Amount</label>
                                    <div class="input-group input-group-sm shadow-sm">
                                        <span class="input-group-text bg-white text-success fw-bold">₱</span>
                                        <input type="number" step="0.01" name="amount_paid" id="amount_input" class="form-control fw-bold text-success" max="<?php echo $balance; ?>" required>
                                        
                                        <input type="hidden" id="balance_val" value="<?php echo $balance; ?>">
                                        <input type="hidden" name="payment_notes" id="notes_input" value="Partial Payment">
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-success btn-sm fw-bold w-100 shadow-sm" onclick="return confirm('Save this payment?');">
                                        <i class="fas fa-save me-1"></i> Save
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-folder-open me-2 text-warning"></i> Attachments</span>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-3">
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM documents WHERE po_id = ?");
                            $stmt->bind_param("i", $po_id);
                            $stmt->execute();
                            $docs = $stmt->get_result();

                            if($docs->num_rows > 0):
                                while($doc = $docs->fetch_assoc()):
                                    $fileNameOnly = basename($doc['file_path']);
                                    $secureLink = "download.php?file=" . $fileNameOnly;
                                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                ?>
                                    <li class="mb-2 p-2 bg-light rounded border d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-2" style="overflow: hidden;">
                                            <?php if($isImage): ?>
                                                <img src="<?php echo $secureLink; ?>" class="file-thumbnail bg-white" onclick="viewFile('<?php echo $secureLink; ?>', 'image')" style="cursor: pointer;">
                                            <?php elseif($isPdf): ?>
                                                <div class="file-icon text-danger bg-white shadow-sm" onclick="viewFile('<?php echo $secureLink; ?>', 'pdf')" style="cursor: pointer;"><i class="fas fa-file-pdf"></i></div>
                                            <?php else: ?>
                                                <div class="file-icon text-primary bg-white shadow-sm"><i class="fas fa-file-alt"></i></div>
                                            <?php endif; ?>
                                            
                                            <div class="text-truncate">
                                                <a href="#" class="text-dark text-decoration-none fw-bold small d-block text-truncate" 
                                                   onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); return false;">
                                                    <?php echo htmlspecialchars($doc['file_name']); ?>
                                                </a>
                                                <small class="text-muted" style="font-size: 0.7rem;"><?php echo strtoupper($ext); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo $secureLink; ?>" class="btn btn-sm btn-white border" title="Download"><i class="fas fa-download text-primary"></i></a>
                                            <?php if($can_delete_files): ?>
                                            <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Permanently delete this file?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-white border text-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endwhile; 
                            else: ?>
                                <li class="text-muted small text-center py-3 border rounded border-dashed">No documents attached yet.</li>
                            <?php endif; ?>
                        </ul>
                        
                        <?php if($can_upload_files): ?>
                        <hr>
                        <form action="actions/upload_handler.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                            <input type="hidden" name="doc_type" value="Generic">
                            
                            <div id="previewContainer" class="mb-3 d-none text-center bg-light p-2 rounded border">
                                <img id="uploadPreview" src="#" alt="Preview" class="img-fluid rounded shadow-sm" style="max-height: 150px;">
                                <div class="small text-muted mt-1 fst-italic">Image Preview</div>
                            </div>

                            <label class="form-label small fw-bold text-primary">Upload New File</label>
                            <div class="input-group">
                                <input type="file" name="document" class="form-control form-control-sm" required onchange="previewSelectedFile(this)">
                                <button class="btn btn-sm btn-primary">Upload</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="fas fa-history me-2 text-muted"></i> Activity Log
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                        <?php
                        $hist_sql = "SELECT h.*, u.full_name FROM po_history h JOIN users u ON h.changed_by = u.user_id WHERE po_id = ? ORDER BY timestamp DESC";
                        $stmt = $conn->prepare($hist_sql);
                        $stmt->bind_param("i", $po_id);
                        $stmt->execute();
                        $hist = $stmt->get_result();
                        
                        while($row = $hist->fetch_assoc()): ?>
                            <div class="list-group-item border-0 border-bottom px-3 py-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-bold small text-dark"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                    <small class="text-muted" style="font-size: 0.75rem;"><?php echo date('M d, H:i', strtotime($row['timestamp'])); ?></small>
                                </div>
                                <div class="small">
                                    <span class="badge bg-secondary" style="font-size: 0.65rem;"><?php echo htmlspecialchars($row['status_from']); ?></span>
                                    <i class="fas fa-long-arrow-alt-right mx-1 text-muted"></i>
                                    <span class="badge bg-success" style="font-size: 0.65rem;"><?php echo htmlspecialchars($row['status_to']); ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="print-only-po">
            
            <?php if(!in_array($po['status'], ['Approved', 'Funded', 'Delivered', 'Collected', 'Partially-Collected'])): ?>
                <div class="draft-banner">DRAFT COPY ONLY - NOT VALID FOR PURCHASING</div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-start" style="border-bottom: 3px solid #0d6efd; padding-bottom: 20px; margin-bottom: 30px;">
                <div>
                    <h1 class="print-header-brand">Fixie Computer Ventures</h1>
                    <div class="print-header-sub">
                        <strong>Driven by Innovation, Defined by Service.</strong><br>
                        123 Technology Avenue, Tech Hub City, Philippines 1000<br>
                        Phone: (02) 8123-4567 | Email: billing@fixie.com
                    </div>
                </div>
                <div class="text-end">
                    <div class="print-title-doc">PURCHASE ORDER</div>
                    <div style="font-size: 13pt; margin-top: 8px; font-weight: 500;">
                        PO Number: <strong style="color: #0d6efd !important;">#<?php echo htmlspecialchars($po['po_number']); ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-7">
                    <div class="info-box h-100" style="background-color: #f8f9fa !important; -webkit-print-color-adjust: exact;">
                        <div class="info-label">Vendor / Billed To:</div>
                        <h4 class="fw-bold m-0 text-dark" style="font-size: 14pt;"><?php echo htmlspecialchars($po['client_name']); ?></h4>
                        <?php if($po['quotation_number']): ?>
                            <div class="mt-2" style="font-size: 9.5pt; color: #495057;"><strong>Quotation Ref:</strong> <?php echo htmlspecialchars($po['quotation_number']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-5">
                    <div class="info-box h-100">
                        <table style="width: 100%; font-size: 9.5pt;">
                            <tr>
                                <td class="info-label" style="padding-bottom: 10px; width: 45%;">Date Issued:</td>
                                <td style="text-align: right; font-weight: bold; padding-bottom: 10px; color: #212529; border-bottom: 1px solid #eee;"><?php echo date('F d, Y', strtotime($po['date_created'])); ?></td>
                            </tr>
                            <tr>
                                <td class="info-label" style="padding: 10px 0;">Status:</td>
                                <td style="text-align: right; font-weight: bold; padding: 10px 0; color: #0d6efd !important; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($po['status']); ?></td>
                            </tr>
                            <tr>
                                <td class="info-label" style="padding-top: 10px;">Prepared By:</td>
                                <td style="text-align: right; font-weight: bold; padding-top: 10px; color: #212529;"><?php echo htmlspecialchars($po['creator_name']); ?></td>
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
                        <td colspan="4" style="text-align: right; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Grand Total</td>
                        <td style="text-align: right; font-weight: 900; font-size: 14pt; color: #0d6efd !important; white-space: nowrap;">₱ <?php echo number_format($po['amount'], 2); ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="signature-section row">
                <div class="col-4 text-center">
                    <div class="sig-line"></div>
                    <div class="sig-name"><?php echo htmlspecialchars($po['creator_name']); ?></div>
                    <div class="sig-title">Prepared By (Procurement)</div>
                </div>
                <div class="col-4 text-center">
                    <div class="sig-line"></div>
                    <div class="sig-name">Finance Officer</div>
                    <div class="sig-title">Checked & Verified By</div>
                </div>
                <div class="col-4 text-center">
                    <div class="sig-line"></div>
                    <div class="sig-name">Authorized Signatory</div>
                    <div class="sig-title">Approved By</div>
                </div>
            </div>
            
        </div>
    </div>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">File Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0 bg-light" id="previewBody" style="min-height: 400px; display: flex; align-items: center; justify-content: center;">
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewSelectedFile(input) {
            const previewContainer = document.getElementById('previewContainer');
            const previewImage = document.getElementById('uploadPreview');
            const file = input.files[0];
            
            if (file) {
                if (file.type.match('image.*')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                        previewContainer.classList.remove('d-none');
                    }
                    reader.readAsDataURL(file);
                } else {
                    previewContainer.classList.add('d-none');
                }
            } else {
                previewContainer.classList.add('d-none');
            }
        }
        
        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            const myModal = new bootstrap.Modal(document.getElementById('previewModal'));
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            
            if (type === 'image') {
                modalBody.innerHTML = `<img src="${path}" class="img-fluid" style="max-height: 80vh;">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" style="border:none;"></iframe>`;
            } else {
                modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary">Download File</a></div>`;
            }
            myModal.show();
        }
        
        function togglePaymentInput(type) {
            const amountInput = document.getElementById('amount_input');
            const balanceVal = document.getElementById('balance_val').value;
            const notesInput = document.getElementById('notes_input');
            
            if (type === 'full') {
                amountInput.value = balanceVal; 
                amountInput.readOnly = true;
                notesInput.value = "Full Payment";
            } else {
                amountInput.value = ""; 
                amountInput.readOnly = false;
                amountInput.focus();
                notesInput.value = "Partial Payment";
            }
        }

        function logAndPrint(documentName) {
            fetch('api/log_print.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=log_print&doc_name=' + encodeURIComponent(documentName)
            })
            .then(response => response.json())
            .then(data => { window.print(); })
            .catch(error => { console.error('Error logging print:', error); window.print(); });
        }
    </script>
</body>
</html>