<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

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

$source_quotation = null;
if (!empty($po['pr_id'])) {
    $source_quote_stmt = $conn->prepare("SELECT q.quotation_id, q.quotation_number FROM purchase_requests pr INNER JOIN quotations q ON q.quotation_id = pr.quotation_id WHERE pr.pr_id = ?");
    $source_quote_stmt->bind_param("i", $po['pr_id']);
    $source_quote_stmt->execute();
    $source_quotation = $source_quote_stmt->get_result()->fetch_assoc();
}

// Check if PO is rejected and fetch rejection reason safely
$po_remarks = isset($po['remarks']) ? $po['remarks'] : '';
$rejection_reason = "";

if (strpos($po['status'], 'Rejected') !== false) {
    if (!empty($po_remarks)) {
        $rejection_reason = $po_remarks;
    } else {
        // Fallback: Check if there is a po_history table and fetch the latest rejection remarks
        $check_hist = $conn->query("SHOW TABLES LIKE 'po_history'");
        if ($check_hist && $check_hist->num_rows > 0) {
            $rej_stmt = $conn->prepare("SELECT remarks FROM po_history WHERE po_id = ? AND status_to LIKE '%Rejected%' ORDER BY timestamp DESC LIMIT 1");
            if ($rej_stmt) {
                $rej_stmt->bind_param("i", $po_id);
                $rej_stmt->execute();
                $rej_res = $rej_stmt->get_result();
                if ($r = $rej_res->fetch_assoc()) {
                    $rejection_reason = $r['remarks'];
                }
            }
        }
    }
}

// DYNAMIC WORKFLOW LOGIC
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

$conn->query("CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `proof_file_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$total_paid = 0;
$payments = [];
$balance = $po['amount'];

$stmt = $conn->prepare("SELECT p.*, u.full_name AS recorded_by_name FROM payments p LEFT JOIN users u ON u.user_id = p.recorded_by WHERE p.po_id = ? ORDER BY p.payment_date DESC");
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
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, .main-content { font-family: 'Inter', sans-serif; }
        .file-thumbnail { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .file-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #f8f9fa; border-radius: 4px; font-size: 1.2rem; border: 1px solid #ddd; }
        
        .payment-card { border-left: 4px solid #198754 !important; }
        .btn-check:checked + .btn-outline-success { background-color: #198754; color: white; border-color: #198754; }
        .btn-check:checked + .btn-outline-warning { background-color: #ffc107; color: #000; border-color: #ffc107; }
        
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

        .reject-callout {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
        }

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
        
        <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 shadow-sm border" style="border-radius: 12px !important;">
            <a href="po_list.php" class="btn btn-sm btn-light border px-3 shadow-sm" style="font-weight: 600; border-radius: 8px;">
                <i class="fas fa-arrow-left me-2"></i> Back
            </a>
            
            <div class="d-flex align-items-center gap-2 text-end">
                
                <?php if ($is_approver): ?>
                    <div class="d-inline-flex align-items-center gap-2 m-0 p-0">
                        <button type="button" class="btn btn-sm btn-success px-4 shadow-sm fw-bold" style="border-radius: 8px;" 
                                onclick="<?php echo $approve_action === 'mark_delivered' ? "openDeliveryProofModal()" : "confirmApprovePO(event, '" . $approve_action . "', '" . $po['po_id'] . "', '" . htmlspecialchars($po['po_number'], ENT_QUOTES) . "', '" . htmlspecialchars($approve_label, ENT_QUOTES) . "')"; ?>">
                            <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($approve_label); ?>
                        </button>

                        <?php if ($can_reject): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger px-4 shadow-sm bg-white fw-bold" style="border-radius: 8px;" 
                                    onclick="confirmRejectPO(event, '<?php echo $po['po_id']; ?>', '<?php echo htmlspecialchars($po['po_number']); ?>')">
                                <i class="fas fa-times-circle me-1"></i> Reject
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="vr bg-secondary opacity-25 mx-2" style="width: 2px; height: 30px;"></div>
                <?php endif; ?>

                <button class="btn btn-sm btn-primary shadow-sm px-3 fw-bold" style="border-radius: 8px;" onclick="logAndPrint('PO #<?php echo htmlspecialchars($po['po_number']); ?>')">
                    <i class="fas fa-print me-1"></i> Print PO
                </button>
                
                <div class="border-start ps-3 ms-2 text-start" style="line-height: 1.2;">
                    <span class="badge badge-status status-<?php echo str_replace([' ', '/'], '_', $po['status']); ?> px-3 py-1 mb-1 d-inline-block shadow-sm"><?php echo $po['status']; ?></span><br>
                    <small class="text-muted fw-bold" style="font-size: 0.75rem;"><i class="fas fa-map-marker-alt text-danger opacity-75"></i> <?php echo htmlspecialchars($po['current_location']); ?></small>
                </div>
            </div>
        </div>

        <div class="row g-4 screen-only-cards">
            <div class="col-lg-8">
                
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3 pb-2 border-bottom border-light">
                            <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                <i class="fas fa-info-circle fs-5"></i>
                            </div>
                            <h6 class="text-uppercase text-dark fw-bold m-0" style="letter-spacing: 0.5px;">Purchase Order Information</h6>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">PO Number</small>
                                <div class="fs-5 fw-bold text-primary">#<?php echo htmlspecialchars($po['po_number']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Total Amount</small>
                                <div class="fs-5 fw-bold text-dark">₱ <?php echo number_format($po['amount'], 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Client Name</small>
                                <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($po['client_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Quotation Ref</small>
                                <?php if($source_quotation): ?>
                                    <a href="view_quotation.php?id=<?php echo (int)$source_quotation['quotation_id']; ?>" class="fw-medium text-primary text-decoration-none"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($source_quotation['quotation_number']); ?></a>
                                <?php else: ?>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($po['quotation_number']) ?: '--'; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Prepared By</small>
                                <div class="fw-medium text-dark"><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($po['creator_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1" style="font-size: 0.75rem;">Date Created</small>
                                <div class="fw-medium text-dark"><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo date('F d, Y h:i A', strtotime($po['date_created'])); ?></div>
                            </div>
                        </div>

                        <!-- Sleek Inline Rejection Reason -->
                        <?php if(strpos($po['status'], 'Rejected') !== false && !empty($rejection_reason)): ?>
                        <div class="reject-callout">
                            <div class="text-danger fw-bold small text-uppercase mb-1" style="letter-spacing: 0.5px;"><i class="fas fa-exclamation-triangle me-1"></i> Reason for Rejection</div>
                            <div class="text-dark fw-medium" style="font-size: 0.9rem;">&ldquo;<?php echo nl2br(htmlspecialchars($rejection_reason)); ?>&rdquo;</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; overflow: hidden;">
                    <div class="card-header bg-white fw-bold py-3 border-bottom border-light">
                        <i class="fas fa-list-alt me-2 text-primary"></i> Order Specifications
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="bg-light text-secondary" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <tr>
                                        <th class="ps-4 py-3 border-bottom-0">Item Details</th>
                                        <th class="text-center py-3 border-bottom-0">Qty</th>
                                        <th class="text-end py-3 border-bottom-0">Unit Price</th>
                                        <th class="text-end pe-4 py-3 border-bottom-0">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($items_data as $item): ?>
                                        <tr>
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="badge bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($item['category'] ?? 'Item'); ?></span>
                                                </div>
                                                <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="text-muted fst-italic mt-1" style="font-size: 0.8rem;"><?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?></div>
                                            </td>
                                            <td class="text-center fw-bold text-dark"><?php echo $item['quantity']; ?></td>
                                            <td class="text-end text-muted fw-medium" style="font-family: monospace;">₱ <?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td class="text-end pe-4 fw-bold text-primary" style="font-family: monospace;">₱ <?php echo number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2); ?></td>
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
                <div class="card border-0 shadow-sm mb-4 payment-card" style="border-radius: 16px; overflow: hidden;">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center border-bottom border-light">
                        <span class="fs-6 text-dark"><i class="fas fa-hand-holding-usd me-2 text-success"></i> Payment History</span>
                        
                        <?php if($balance > 0.01): ?>
                            <span class="badge bg-warning text-dark px-3 py-2 shadow-sm" style="border-radius: 8px;">Balance: ₱ <?php echo number_format($balance, 2); ?></span>
                        <?php else: ?>
                            <span class="badge bg-success px-3 py-2 shadow-sm" style="border-radius: 8px;"><i class="fas fa-check-double me-1"></i> Fully Paid</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light text-secondary" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                <tr>
                                    <th class="ps-4 py-3 border-bottom-0">Date & Time</th>
                                    <th class="py-3 border-bottom-0">Payment Details</th>
                                    <th class="py-3 border-bottom-0">Reference &amp; Proof</th>
                                    <th class="text-end pe-4 py-3 border-bottom-0">Amount Paid</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($payments) > 0): 
                                    foreach($payments as $pay): ?>
                                    <tr>
                                        <td class="ps-4 py-3">
                                            <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></div>
                                            <div class="text-muted small"><?php echo date('h:i A', strtotime($pay['payment_date'])); ?></div>
                                        </td>
                                        <td class="py-3 align-middle">
                                            <?php if(stripos($pay['notes'], 'Full') !== false): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success me-1 px-2 py-1">Full Payment</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-dark border border-warning me-1 px-2 py-1">Partial Payment</span>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($pay['payment_method'] ?? '--'); ?><?php if(!empty($pay['recorded_by_name'])): ?> · Recorded by <?php echo htmlspecialchars($pay['recorded_by_name']); ?><?php endif; ?></div>
                                        </td>
                                        <td class="py-3 align-middle">
                                            <div class="small fw-bold text-dark text-break"><?php echo htmlspecialchars($pay['reference_number'] ?? '--'); ?></div>
                                            <?php if(!empty($pay['proof_file_path'])): ?>
                                                <a href="uploads/payments/<?php echo rawurlencode(basename($pay['proof_file_path'])); ?>" target="_blank" rel="noopener" class="small text-primary text-decoration-none"><i class="fas fa-paperclip me-1"></i>View proof</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-success align-middle py-3" style="font-family: monospace; font-size: 1.05rem;">+ ₱ <?php echo number_format($pay['amount_paid'], 2); ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted small"><i class="fas fa-info-circle fs-4 mb-2 d-block opacity-50"></i> No payments recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($balance > 0.01 && $_SESSION['role'] == 'Finance'): ?>
                    <div class="card-footer bg-light p-4 border-top">
                        <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-plus-circle me-2"></i> Record New Payment</h6>
                        <form action="actions/po_handler.php" method="POST" enctype="multipart/form-data" id="paymentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_payment">
                            <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                            
                            <div class="row g-3 align-items-end">
                                <div class="col-md-2">
                                    <label class="small fw-bold text-muted mb-1">Payment Type</label>
                                    <div class="btn-group w-100 shadow-sm" role="group">
                                        <input type="radio" class="btn-check" name="pay_type" id="pay_full" autocomplete="off" onclick="togglePaymentInput('full')">
                                        <label class="btn btn-outline-success btn-sm fw-bold" for="pay_full">Full</label>
                                        
                                        <input type="radio" class="btn-check" name="pay_type" id="pay_partial" autocomplete="off" checked onclick="togglePaymentInput('partial')">
                                        <label class="btn btn-outline-warning btn-sm fw-bold text-dark" for="pay_partial">Partial</label>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted mb-1">Date Received</label>
                                    <input type="datetime-local" name="payment_date" class="form-control form-control-sm fw-medium shadow-sm" style="border-radius: 6px;" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="small fw-bold text-muted mb-1">Method</label>
                                    <select name="payment_method" class="form-select form-select-sm shadow-sm" required>
                                        <option value="" selected disabled>Select</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="GCash">GCash</option>
                                        <option value="Cheque">Cheque</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="small fw-bold text-muted mb-1">Amount</label>
                                    <div class="input-group input-group-sm shadow-sm" style="border-radius: 6px; overflow: hidden;">
                                        <span class="input-group-text bg-white text-success fw-bold border-end-0">₱</span>
                                        <input type="number" step="0.01" name="amount_paid" id="amount_input" class="form-control fw-bold text-success border-start-0 ps-0" max="<?php echo $balance; ?>" required>
                                        
                                        <input type="hidden" id="balance_val" value="<?php echo $balance; ?>">
                                        <input type="hidden" name="payment_notes" id="notes_input" value="Partial Payment">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted mb-1">Reference No.</label>
                                    <input type="text" name="reference_number" class="form-control form-control-sm shadow-sm" maxlength="100" placeholder="OR / Txn no." required>
                                </div>

                                <div class="col-md-10">
                                    <label class="small fw-bold text-muted mb-1">Payment Proof <span class="text-danger">*</span></label>
                                    <input type="file" name="payment_proof" class="form-control form-control-sm shadow-sm" accept=".pdf,.png,.jpg,.jpeg" required>
                                    <small class="text-muted">Upload the receipt, deposit slip, or transaction screenshot (PDF/JPG/PNG, max. 10 MB).</small>
                                </div>

                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-success btn-sm fw-bold w-100 shadow-sm" style="border-radius: 6px; height: 31px;" onclick="return confirm('Save this payment?');">
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
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px;">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center border-bottom border-light">
                        <span><i class="fas fa-folder-open me-2 text-warning"></i> Attachments</span>
                    </div>
                    <div class="card-body p-3">
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
                                <li class="text-muted small text-center py-4 border rounded border-dashed bg-light"><i class="fas fa-inbox fs-4 mb-2 opacity-50 d-block"></i> No documents attached yet.</li>
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
                                <input type="file" name="document" class="form-control form-control-sm" style="border-radius: 6px 0 0 6px;" required onchange="previewSelectedFile(this)">
                                <button class="btn btn-sm btn-primary fw-bold" style="border-radius: 0 6px 6px 0;">Upload</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm" style="border-radius: 16px;">
                    <div class="card-header bg-white fw-bold py-3 border-bottom border-light">
                        <i class="fas fa-history me-2 text-muted"></i> Activity Log
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 350px; overflow-y: auto;">
                        <?php
                        $hist_sql = "SELECT h.*, u.full_name FROM po_history h JOIN users u ON h.changed_by = u.user_id WHERE po_id = ? ORDER BY timestamp DESC";
                        $stmt = $conn->prepare($hist_sql);
                        $stmt->bind_param("i", $po_id);
                        $stmt->execute();
                        $hist = $stmt->get_result();
                        
                        while($row = $hist->fetch_assoc()): ?>
                            <div class="list-group-item border-0 border-bottom px-4 py-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-bold small text-dark"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                    <small class="text-muted" style="font-size: 0.75rem;"><i class="far fa-clock me-1"></i><?php echo date('M d, H:i', strtotime($row['timestamp'])); ?></small>
                                </div>
                                <div class="small mt-1 d-flex align-items-center">
                                    <span class="badge bg-secondary px-2" style="font-size: 0.65rem;"><?php echo htmlspecialchars($row['status_from']); ?></span>
                                    <i class="fas fa-angle-right mx-2 text-muted"></i>
                                    <span class="badge <?php echo (strpos($row['status_to'], 'Rejected') !== false) ? 'bg-danger' : 'bg-success'; ?> px-2" style="font-size: 0.65rem;"><?php echo htmlspecialchars($row['status_to']); ?></span>
                                </div>
                                
                                <?php if (!empty($row['remarks'])): ?>
                                    <div class="mt-2 text-dark fst-italic" style="font-size: 0.8rem; background: #fef2f2; padding: 8px 12px; border-left: 3px solid #ef4444; border-radius: 6px;">
                                        "<?php echo nl2br(htmlspecialchars($row['remarks'])); ?>"
                                    </div>
                                <?php endif; ?>
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

    <!-- Required proof of delivery modal (Supply Chain) -->
    <div class="modal fade" id="deliveryProofModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden;">
                <form action="actions/po_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header border-bottom-0 pb-0">
                        <div>
                            <h5 class="modal-title fw-bold text-dark">Confirm Delivery</h5>
                            <p class="text-muted small mb-0">Attach proof before forwarding this PO to Finance for collection.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body pt-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="mark_delivered">
                        <input type="hidden" name="po_id" value="<?php echo (int)$po_id; ?>">
                        <label class="form-label fw-bold small text-uppercase text-muted">Proof of Delivery <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="delivery_proof" accept=".pdf,.png,.jpg,.jpeg" required>
                        <div class="form-text">Upload a signed delivery receipt, acknowledgement, or delivery screenshot (PDF/JPG/PNG, max. 10 MB).</div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success fw-bold">Submit Proof &amp; Mark Delivered</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hidden Form for SweetAlert Submission -->
    <form id="dynamicActionForm" action="actions/po_handler.php" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
        <input type="hidden" name="action" id="dynamicAction">
        <input type="hidden" name="po_id" id="dynamicPoId">
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
            window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $po_id; ?>");
        <?php endif; ?>

        <?php if(isset($_GET['error'])): ?>
            Toast.fire({
                icon: 'error',
                title: '<?php echo addslashes(htmlspecialchars($_GET['error'])); ?>'
            });
            window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $po_id; ?>");
        <?php endif; ?>

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
                modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary fw-bold">Download File</a></div>`;
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

        function openDeliveryProofModal() {
            const modal = new bootstrap.Modal(document.getElementById('deliveryProofModal'));
            modal.show();
        }

        // Safe Approval Function for PO
        function confirmApprovePO(e, actionKey, id, poNumber, btnLabel) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Order?',
                html: "<span class='text-muted' style='font-size: 0.9rem;'>Confirm approval for PO <b>" + poNumber + "</b>?</span>",
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, ' + btnLabel,
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: { 
                    popup: 'sleek-popup', 
                    confirmButton: 'btn btn-success px-4 py-2 shadow-sm fw-bold', 
                    cancelButton: 'btn btn-light px-4 py-2 border fw-bold ms-2' 
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#dynamicAction').val(actionKey); 
                    $('#dynamicPoId').val(id);
                    $('#dynamicRemarks').val('');
                    $('#dynamicActionForm').submit();
                }
            });
        }

        // Strict Rejection Function for PO
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
