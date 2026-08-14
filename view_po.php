<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$po_id = $_GET['id'] ?? 0;
if(!is_numeric($po_id)) die("Invalid PO ID");

$current_role = $_SESSION['role'];
$current_user_id = (int)$_SESSION['user_id'];
ensure_user_notification_states($conn, $current_user_id, $current_role);

// Opening a PO marks only this user's matching role notification as read.
$mark_sql = "UPDATE notification_user_states nus
             INNER JOIN notifications n ON n.notif_id = nus.notif_id
             SET nus.is_read = 1, nus.read_at = COALESCE(nus.read_at, NOW())
             WHERE nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0 AND nus.is_read = 0
             AND (n.message LIKE CONCAT('%PO #', ?, '%') OR n.message LIKE CONCAT('%PO #', (SELECT po_number FROM purchase_orders WHERE po_id=?), '%'))";
$stmt_mark = $conn->prepare($mark_sql);
$stmt_mark->bind_param("isii", $current_user_id, $current_role, $po_id, $po_id);
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

// Shared PO visibility with one optional accountable task owner.
$active_task_assignment = get_active_po_task_assignment($conn, (int)$po_id);
$eligible_task_roles = get_po_eligible_roles($conn, $status);
$is_task_eligible = in_array($role, $eligible_task_roles, true);
$is_task_assignee = $active_task_assignment && (int)$active_task_assignment['assigned_to'] === (int)$current_user_id;
$task_locked_for_another_user = $active_task_assignment && !$is_task_assignee;
$task_claim_required = false; // Wala nang manual claim
$can_execute_task = !$task_locked_for_another_user;

$other_eligible_users = 0;
if ($is_task_assignee && $active_task_assignment) {
    $stmt_cnt = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE role = ? AND status = 'Active' AND user_id != ?");
    $stmt_cnt->bind_param("si", $active_task_assignment['assigned_role'], $current_user_id);
    $stmt_cnt->execute();
    $other_eligible_users = $stmt_cnt->get_result()->fetch_assoc()['c'];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="page-view-po">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <div class="view-doc-toolbar view-po-toolbar d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 shadow-sm border rounded-12-imp">
            <a href="po_list.php" class="view-doc-back btn btn-sm btn-light border px-3 shadow-sm fw-600 rounded-8" aria-label="Back to purchase orders">
                <i class="fas fa-arrow-left me-2"></i><span>Back</span>
            </a>
            
            <div class="view-po-toolbar-actions d-flex align-items-center gap-2 text-end">
                
                <?php if ($is_approver && $can_execute_task): ?>
                    <div class="view-po-decision-actions d-inline-flex align-items-center gap-2 m-0 p-0">
                        <button type="button" class="btn btn-sm btn-success px-4 shadow-sm fw-bold rounded-8" 
                                onclick="<?php echo $approve_action === 'mark_delivered' ? "openDeliveryProofModal()" : "confirmApprovePO(event, '" . $approve_action . "', '" . $po['po_id'] . "', '" . htmlspecialchars($po['po_number'], ENT_QUOTES) . "', '" . htmlspecialchars($approve_label, ENT_QUOTES) . "')"; ?>">
                            <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($approve_label); ?>
                        </button>

                        <?php if ($can_reject): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger px-4 shadow-sm bg-white fw-bold rounded-8" 
                                    onclick="confirmRejectPO(event, '<?php echo $po['po_id']; ?>', '<?php echo htmlspecialchars($po['po_number']); ?>')">
                                <i class="fas fa-times-circle me-1"></i> Reject
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="vr bg-secondary opacity-25 mx-2 vr-divider"></div>
                <?php endif; ?>

                <button class="view-doc-print btn btn-sm btn-primary shadow-sm px-3 fw-bold rounded-8" onclick="logAndPrint('PO #<?php echo htmlspecialchars($po['po_number']); ?>')" aria-label="Print purchase order">
                    <i class="fas fa-print me-1"></i><span>Print PO</span>
                </button>
                
                <div class="view-po-status-block border-start ps-3 ms-2 text-start lh-12">
                    <span class="badge badge-status status-<?php echo str_replace([' ', '/'], '_', $po['status']); ?> px-3 py-1 mb-1 d-inline-block shadow-sm"><?php echo $po['status']; ?></span><br>
                    <small class="text-muted fw-bold fs-xs"><i class="fas fa-map-marker-alt text-danger opacity-75"></i> <?php echo htmlspecialchars($po['current_location']); ?></small>
                </div>
            </div>
        </div>

        <?php if ($is_task_eligible || $active_task_assignment): ?>
        <div class="card border-0 shadow-sm mb-4 no-print rounded-12 view-po-task-card">
            <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center box-38"><i class="fas fa-user-check"></i></div>
                    <div>
                        <div class="fw-bold text-dark fs-09rem">Task ownership</div>
                        <?php if ($active_task_assignment): ?>
                            <div class="small text-muted">Assigned to <strong><?php echo htmlspecialchars($active_task_assignment['assignee_name']); ?></strong> · <?php echo htmlspecialchars($active_task_assignment['assigned_role']); ?></div>
                        <?php elseif ($is_task_eligible): ?>
                            <div class="small text-muted">This is an unassigned shared task for your role.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($is_task_assignee && $other_eligible_users > 0): ?>
                    <form action="actions/po_handler.php" method="POST" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="action" value="reassign_task">
                        <input type="hidden" name="po_id" value="<?php echo (int)$po_id; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary px-3 fw-bold rounded-8" onclick="return confirm('Ipasa ang task na ito sa next available user?');"><i class="fas fa-random me-1"></i> Re-assign Task</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($task_locked_for_another_user): ?><div class="card-footer bg-light border-0 small text-muted py-2 px-4">Only the assigned user can complete the current task. The PO remains visible to the whole department.</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row g-4 screen-only-cards view-po-content-grid">
            <div class="col-lg-8">
                
                <div class="card border-0 shadow-sm mb-4 rounded-16 view-info-card po-info-card">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3 pb-2 border-bottom border-light">
                            <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-3 box-40">
                                <i class="fas fa-info-circle fs-5"></i>
                            </div>
                            <h6 class="text-uppercase text-dark fw-bold m-0 tracking-wide">Purchase Order Information</h6>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1 fs-xs">PO Number</small>
                                <div class="fs-5 fw-bold text-primary">#<?php echo htmlspecialchars($po['po_number']); ?></div>
                            </div>
                            <div class="col-md-6 view-info-total-field">
                                <small class="text-muted d-block mb-1 fs-xs">Total Amount</small>
                                <div class="fs-5 fw-bold text-dark">₱ <?php echo number_format($po['amount'], 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1 fs-xs">Client Name</small>
                                <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($po['client_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1 fs-xs">Quotation Ref</small>
                                <?php if($source_quotation): ?>
                                    <a href="view_quotation.php?id=<?php echo (int)$source_quotation['quotation_id']; ?>" class="fw-medium text-primary text-decoration-none"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($source_quotation['quotation_number']); ?></a>
                                <?php else: ?>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($po['quotation_number']) ?: '--'; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1 fs-xs">Prepared By</small>
                                <div class="fw-medium text-dark"><i class="fas fa-user-circle text-muted me-1"></i> <?php echo htmlspecialchars($po['creator_name']); ?></div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1 fs-xs">Date Created</small>
                                <div class="fw-medium text-dark"><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo date('F d, Y h:i A', strtotime($po['date_created'])); ?></div>
                            </div>
                        </div>

                        <!-- Sleek Inline Rejection Reason -->
                        <?php if(strpos($po['status'], 'Rejected') !== false && !empty($rejection_reason)): ?>
                        <div class="reject-callout">
                            <div class="text-danger fw-bold small text-uppercase mb-1 tracking-wide"><i class="fas fa-exclamation-triangle me-1"></i> Reason for Rejection</div>
                            <div class="text-dark fw-medium fs-09rem">&ldquo;<?php echo nl2br(htmlspecialchars($rejection_reason)); ?>&rdquo;</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4 rounded-16 overflow-hidden view-items-card po-items-card">
                    <div class="card-header bg-white fw-bold py-3 border-bottom border-light">
                        <i class="fas fa-list-alt me-2 text-primary"></i> Order Specifications
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle view-items-table po-items-table">
                                <thead class="bg-light text-secondary table-header-sm">
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
                                                <div class="fw-bold text-dark fs-md"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="text-muted fst-italic mt-1 fs-08rem"><?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?></div>
                                            </td>
                                            <td class="text-center fw-bold text-dark"><?php echo $item['quantity']; ?></td>
                                            <td class="text-end text-muted fw-medium font-monospace">₱ <?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td class="text-end pe-4 fw-bold text-primary font-monospace">₱ <?php echo number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="view-mobile-grand-total d-md-none" aria-label="Grand Total">
                    <span>Grand Total</span>
                    <strong>₱ <?php echo number_format($po['amount'], 2); ?></strong>
                </div>

                <?php 
                $payment_visible_statuses = ['Delivered', 'Partially Paid', 'Partially-Collected', 'Collected'];
                
                if(in_array($po['status'], $payment_visible_statuses) || stripos($po['current_location'], 'Delivered') !== false || stripos($po['current_location'], 'Collection') !== false): 
                ?>
                <div class="card border-0 shadow-sm mb-4 payment-card rounded-16 overflow-hidden">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center border-bottom border-light">
                        <span class="fs-6 text-dark"><i class="fas fa-hand-holding-usd me-2 text-success"></i> Payment History</span>
                        
                        <?php if($balance > 0.01): ?>
                            <span class="badge bg-warning text-dark px-3 py-2 shadow-sm rounded-8">Balance: ₱ <?php echo number_format($balance, 2); ?></span>
                        <?php else: ?>
                            <span class="badge bg-success px-3 py-2 shadow-sm rounded-8"><i class="fas fa-check-double me-1"></i> Fully Paid</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 po-payment-table">
                            <thead class="bg-light text-secondary table-header-sm">
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
                                            <div class="fw-bold text-dark fs-09rem"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></div>
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
                                                <a href="download.php?type=payment_proof&file=<?php echo rawurlencode(basename($pay['proof_file_path'])); ?>" target="_blank" rel="noopener" class="small text-primary text-decoration-none"><i class="fas fa-paperclip me-1"></i>View proof</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-success align-middle py-3 font-monospace fs-105rem">+ ₱ <?php echo number_format($pay['amount_paid'], 2); ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted small"><i class="fas fa-info-circle fs-4 mb-2 d-block opacity-50"></i> No payments recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($balance > 0.01 && $_SESSION['role'] == 'Finance' && $can_execute_task): ?>
                    <div class="card-footer bg-light p-4 border-top">
                        <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-plus-circle me-2"></i> Record New Payment</h6>
                        <form action="actions/po_handler.php" method="POST" enctype="multipart/form-data" id="paymentForm" class="po-payment-form">
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
                                    <input type="datetime-local" name="payment_date" class="form-control form-control-sm fw-medium shadow-sm rounded-custom" required value="<?php echo date('Y-m-d\TH:i'); ?>">
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
                                    <div class="input-group input-group-sm shadow-sm rounded-custom overflow-hidden">
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
                                    <button type="submit" class="btn btn-success btn-sm fw-bold w-100 shadow-sm btn-save-pay" onclick="return confirm('Save this payment?');">
                                        <i class="fas fa-save me-1"></i> Save
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php elseif($balance > 0.01 && $_SESSION['role'] == 'Finance'): ?>
                    <div class="card-footer bg-light p-3 border-top small text-muted"><i class="fas fa-lock me-1"></i> <?php echo $task_locked_for_another_user ? 'Payment entry is reserved for the assigned Finance user.' : 'Claim this task before recording payment.'; ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4 rounded-16 po-attachments-card">
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
                                    $secureLink = "download.php?file=" . rawurlencode($fileNameOnly) . "&doc_id=" . intval($doc['doc_id']);
                                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                ?>
                                    <li class="mb-2 p-2 bg-light rounded border d-flex align-items-center justify-content-between po-attachment-row">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden">
                                            <?php if($isImage): ?>
                                                <img src="<?php echo $secureLink; ?>" class="file-thumbnail bg-white" onclick="viewFile('<?php echo $secureLink; ?>', 'image')">
                                            <?php elseif($isPdf): ?>
                                                <div class="file-icon text-danger bg-white shadow-sm" onclick="viewFile('<?php echo $secureLink; ?>', 'pdf')"><i class="fas fa-file-pdf"></i></div>
                                            <?php else: ?>
                                                <div class="file-icon text-primary bg-white shadow-sm"><i class="fas fa-file-alt"></i></div>
                                            <?php endif; ?>
                                            
                                            <div class="text-truncate">
                                                <a href="#" class="text-dark text-decoration-none fw-bold small d-block text-truncate" 
                                                   onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); return false;">
                                                    <?php echo htmlspecialchars($doc['file_name']); ?>
                                                </a>
                                                <small class="text-muted fs-xs"><?php echo strtoupper($ext); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex gap-2 po-attachment-actions">
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
                                <img id="uploadPreview" src="#" alt="Preview" class="img-fluid rounded shadow-sm preview-thumb-box">
                                <div class="small text-muted mt-1 fst-italic">Image Preview</div>
                            </div>

                            <label class="form-label small fw-bold text-primary">Upload New File</label>
                            <div class="input-group">
                                <input type="file" name="document" class="form-control form-control-sm preview-img-box" required onchange="previewSelectedFile(this)">
                                <button class="btn btn-sm btn-primary fw-bold preview-btn-box">Upload</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-16 po-activity-card">
                    <div class="card-header bg-white fw-bold py-3 border-bottom border-light">
                        <i class="fas fa-history me-2 text-muted"></i> Activity Log
                    </div>
                    <div class="list-group list-group-flush scrollable-350">
                        <?php
                        $hist_sql = "SELECT h.*, u.full_name FROM po_history h JOIN users u ON h.changed_by = u.user_id WHERE po_id = ? ORDER BY timestamp DESC";
                        $stmt = $conn->prepare($hist_sql);
                        $stmt->bind_param("i", $po_id);
                        $stmt->execute();
                        $hist = $stmt->get_result();
                        
                        while($row = $hist->fetch_assoc()): ?>
                            <div class="list-group-item border-0 border-bottom px-4 py-3 po-activity-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-bold small text-dark"><?php echo htmlspecialchars($row['full_name']); ?></span>
                                    <small class="text-muted fs-xs"><i class="far fa-clock me-1"></i><?php echo date('M d, H:i', strtotime($row['timestamp'])); ?></small>
                                </div>
                                <div class="small mt-1 d-flex align-items-center">
                                    <span class="badge bg-secondary px-2 fs-065rem"><?php echo htmlspecialchars($row['status_from']); ?></span>
                                    <i class="fas fa-angle-right mx-2 text-muted"></i>
                                    <span class="badge <?php echo (strpos($row['status_to'], 'Rejected') !== false) ? 'bg-danger' : 'bg-success'; ?> px-2 fs-065rem"><?php echo htmlspecialchars($row['status_to']); ?></span>
                                </div>
                                
                                <?php if (!empty($row['remarks'])): ?>
                                    <div class="mt-2 text-dark fst-italic remarks-box">
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

            <div class="d-flex justify-content-between align-items-start print-header-border">
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
                    <div class="print-po-text">
                        PO Number: <strong class="text-primary-print">#<?php echo htmlspecialchars($po['po_number']); ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-7">
                    <div class="info-box h-100 bg-print-light">
                        <div class="info-label">Vendor / Billed To:</div>
                        <h4 class="fw-bold m-0 text-dark fs-14pt"><?php echo htmlspecialchars($po['client_name']); ?></h4>
                        <?php if($po['quotation_number']): ?>
                            <div class="mt-2 fs-95pt-muted"><strong>Quotation Ref:</strong> <?php echo htmlspecialchars($po['quotation_number']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-5">
                    <div class="info-box h-100">
                        <table class="w-100-fs-95">
                            <tr>
                                <td class="info-label pb-10-w-45">Date Issued:</td>
                                <td class="print-td-right"><?php echo date('F d, Y', strtotime($po['date_created'])); ?></td>
                            </tr>
                            <tr>
                                <td class="info-label py-10px">Status:</td>
                                <td class="print-td-right-primary"><?php echo htmlspecialchars($po['status']); ?></td>
                            </tr>
                            <tr>
                                <td class="info-label pt-10px">Prepared By:</td>
                                <td class="print-td-right-pt"><?php echo htmlspecialchars($po['creator_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <table class="print-table">
                <thead>
                    <tr>
                        <th class="w-5-pct-center">#</th>
                        <th class="w-50-pct-left">ITEM DESCRIPTION & SPECIFICATIONS</th>
                        <th class="w-10-pct-center">QTY</th>
                        <th class="w-15-pct-right">UNIT PRICE</th>
                        <th class="w-20-pct-right">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $ctr = 1; foreach($items_data as $item): ?>
                    <tr>
                        <td class="print-td-ctr-muted"><?php echo $ctr++; ?></td>
                        <td>
                            <div class="print-item-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="print-item-specs"><?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?></div>
                        </td>
                        <td class="print-td-ctr-500"><?php echo $item['quantity']; ?></td>
                        <td class="print-td-r-nowrap">₱ <?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="print-td-r-bold-nowrap">₱ <?php echo number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="print-tf-label">Grand Total</td>
                        <td class="print-tf-total">₱ <?php echo number_format($po['amount'], 2); ?></td>
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
    <div class="modal fade view-file-preview-modal" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-16px-clean">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">File Preview</h5>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0 bg-light mt-3 preview-body-flex" id="previewBody">
                </div>
            </div>
        </div>
    </div>

    <!-- Required proof of delivery modal (Supply Chain) -->
    <div class="modal fade view-form-modal" id="deliveryProofModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-16-hidden">
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
    <form id="dynamicActionForm" action="actions/po_handler.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
        <input type="hidden" name="action" id="dynamicAction">
        <input type="hidden" name="po_id" id="dynamicPoId">
        <input type="hidden" name="remarks" id="dynamicRemarks">
    </form>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
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
                modalBody.innerHTML = `<img src="${path}" class="img-fluid max-h-80vh">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" class="border-none"></iframe>`;
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

        function confirmApprovePO(e, actionKey, id, poNumber, btnLabel) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Approve Order?',
                html: "<span class='text-muted fs-09rem'>Confirm approval for PO <b>" + poNumber + "</b>?</span>",
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

        function confirmRejectPO(e, id, poNumber) {
            e.preventDefault();
            e.stopPropagation();

            Swal.fire({
                title: 'Reject Order',
                html: "<span class='text-muted fs-09rem'>Please state the reason for rejecting <b>" + poNumber + "</b>:</span>",
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
