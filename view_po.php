<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require_once 'config/workflow_access.php';
require_once 'config/po_record_timeline.php';
require_once 'config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

drms_require_workflow_roles([
    'Procurement',
    'GM',
    'President',
    'Finance',
    'Supply Chain',
]);

$po_id = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($po_id === false || $po_id === null) {
    drms_redirect_with_feedback(
        'po_list.php',
        'error',
        'Select a valid purchase order to continue.'
    );
}
$po_id = (int) $po_id;
$request_success = drms_public_feedback_message(
    $_GET['success'] ?? '',
    'The purchase-order action was completed successfully.'
);
$request_error = drms_public_feedback_message(
    $_GET['error'] ?? '',
    'The requested purchase-order action could not be completed. No changes were saved.'
);

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

if ($po_query->num_rows === 0) {
    drms_redirect_with_feedback(
        'po_list.php',
        'error',
        'The selected purchase order was not found or is no longer available.'
    );
}
$po = $po_query->fetch_assoc();

$official_po_record_stmt = $conn->prepare(
    "SELECT
        doc_id,
        record_number,
        file_name,
        declared_at
     FROM documents
     WHERE source_module = 'Internal Purchase Order'
       AND source_record_id = ?
       AND record_phase = 'Official'
       AND status <> 'Recycled'
     LIMIT 1"
);
$official_po_record_stmt->bind_param('i', $po_id);
$official_po_record_stmt->execute();
$official_po_record =
    $official_po_record_stmt->get_result()->fetch_assoc();
$official_po_record_stmt->close();
$official_po_record_link = $official_po_record
    ? 'download.php?type=document&record_id=' .
        (int) $official_po_record['doc_id']
    : '';

$source_quotation = null;
$source_pr_approval_by_stage = [];
if (!empty($po['pr_id'])) {
    $source_quote_stmt = $conn->prepare(
        "SELECT
            pr.pr_number,
            pr.current_approval_stage,
            pr.final_approved_by,
            pr.final_approved_at,
            q.quotation_id,
            q.quotation_number,
            client_po.actual_client_po_number,
            client_po.client_po_date,
            supplier.supplier_name,
            supplier.supplier_reference,
            supplier.supplier_quote_date,
            supplier.payment_method,
            supplier.payment_terms,
            supplier.remarks AS supplier_remarks,
            final_approver.full_name AS pr_final_approver_name
         FROM purchase_requests pr
         LEFT JOIN quotations q
            ON q.quotation_id = pr.quotation_id
         LEFT JOIN client_approval_records client_po
            ON client_po.approval_record_id = pr.client_approval_record_id
           AND client_po.record_status = 'Active'
         LEFT JOIN pr_supplier_details supplier
            ON supplier.pr_id = pr.pr_id
           AND supplier.record_status = 'Active'
         LEFT JOIN users final_approver
            ON final_approver.user_id = pr.final_approved_by
         WHERE pr.pr_id = ?
         LIMIT 1"
    );
    $source_quote_stmt->bind_param("i", $po['pr_id']);
    $source_quote_stmt->execute();
    $source_quotation = $source_quote_stmt->get_result()->fetch_assoc();

    $source_pr_approval_stmt = $conn->prepare(
        "SELECT
            approval.approval_stage,
            approval.required_role,
            approval.decision,
            approval.acted_by,
            approval.acted_at,
            actor.full_name AS acted_by_name
         FROM pr_approval_records approval
         LEFT JOIN users actor
            ON actor.user_id = approval.acted_by
         WHERE approval.pr_id = ?
           AND approval.approval_cycle = (
                SELECT MAX(cycle_record.approval_cycle)
                FROM pr_approval_records cycle_record
                WHERE cycle_record.pr_id = ?
           )
         ORDER BY approval.stage_sequence"
    );
    $source_pr_approval_stmt->bind_param("ii", $po['pr_id'], $po['pr_id']);
    $source_pr_approval_stmt->execute();
    $source_pr_approval_result = $source_pr_approval_stmt->get_result();
    while ($source_pr_approval = $source_pr_approval_result->fetch_assoc()) {
        $source_pr_approval_by_stage[$source_pr_approval['approval_stage']] =
            $source_pr_approval;
    }
}

$po_approval_history_by_status = [];
$po_approval_history_stmt = $conn->prepare(
    "SELECT
        history.status_from,
        history.status_to,
        history.changed_by,
        history.timestamp AS acted_at,
        actor.full_name AS acted_by_name
     FROM po_history history
     LEFT JOIN users actor
        ON actor.user_id = history.changed_by
     WHERE history.po_id = ?
       AND history.status_to IN (
            'GM-Approved',
            'Finance-Approved',
            'President-Approved'
       )
     ORDER BY history.history_id"
);
$po_approval_history_stmt->bind_param('i', $po_id);
$po_approval_history_stmt->execute();
$po_approval_history_result = $po_approval_history_stmt->get_result();
while ($po_approval_history = $po_approval_history_result->fetch_assoc()) {
    $po_approval_history_by_status[$po_approval_history['status_to']] =
        $po_approval_history;
}

$print_po_signatory_stages = [
    [
        'pr_stage' => 'GM Review',
        'po_status' => 'GM-Approved',
        'expected_from' => 'Pending',
        'label' => 'Reviewed by General Manager',
    ],
    [
        'pr_stage' => 'Finance Review',
        'po_status' => 'Finance-Approved',
        'expected_from' => 'GM-Approved',
        'label' => 'Checked by Finance',
    ],
    [
        'pr_stage' => 'Owner Approval',
        'po_status' => 'President-Approved',
        'expected_from' => 'Finance-Approved',
        'label' => 'Approved by Owner / President',
    ],
];

$source_pr_is_authorized = $source_quotation &&
    $source_quotation['current_approval_stage'] === 'Official Approved' &&
    !empty($source_quotation['final_approved_by']) &&
    !empty($source_quotation['final_approved_at']);

foreach (['GM Review', 'Finance Review', 'Owner Approval'] as $required_pr_stage) {
    $required_approval = $source_pr_approval_by_stage[$required_pr_stage] ?? null;
    if (
        !$required_approval ||
        $required_approval['decision'] !== 'Approved' ||
        empty($required_approval['acted_by']) ||
        empty($required_approval['acted_at'])
    ) {
        $source_pr_is_authorized = false;
        break;
    }
}

$direct_president_approval =
    $po_approval_history_by_status['President-Approved'] ?? null;
$direct_po_is_authorized = $direct_president_approval &&
    $direct_president_approval['status_from'] === 'Finance-Approved' &&
    !empty($direct_president_approval['changed_by']) &&
    !empty($direct_president_approval['acted_at']);

$official_po_statuses = [
    'President-Approved',
    'Funded',
    'Delivery Requested',
    'For Pick-up/Delivery',
    'Delivered',
    'Partially-Collected',
    'Collected',
];
$is_official_po = in_array($po['status'], $official_po_statuses, true) &&
    ($source_pr_is_authorized || $direct_po_is_authorized);
$print_po_record_status = $is_official_po
    ? 'OFFICIAL' . ($official_po_record
        ? ' - RMS ' . $official_po_record['record_number']
        : '')
    : 'DRAFT';
$print_po_approval_basis = $source_pr_is_authorized
    ? 'Authorization inherited from official PRF ' .
        ($source_quotation['pr_number'] ?? '')
    : 'Authorization recorded through the PO approval route';

$po_print_category_map = [
    '01' => 'Hardware',
    '02' => 'CCTVs',
    '03' => 'Peripherals',
    '04' => 'Office Supplies',
    '05' => 'WIFI / LAN',
    '06' => 'Printers',
];

function po_print_date(?string $value, string $format = 'M d, Y'): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : '—';
}

function po_print_money($value): string
{
    return '₱' . number_format((float) $value, 2);
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

$stmt_rules = $conn->prepare(
    "SELECT *
     FROM workflow_rules
     WHERE required_role = ?
       AND current_status = ?
       AND NOT (
            action_key = 'mark_delivered'
            AND current_status <> 'For Pick-up/Delivery'
       )"
);
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
  `proof_original_name` varchar(255) DEFAULT NULL,
  `proof_file_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$total_paid = 0;
$payments = [];
$balance = $po['amount'];

$stmt = $conn->prepare(
    "SELECT
        p.*,
        u.full_name AS recorded_by_name,
        payment_record.doc_id AS payment_record_doc_id,
        payment_record.record_number AS payment_record_number
     FROM payments p
     LEFT JOIN users u
        ON u.user_id = p.recorded_by
     LEFT JOIN documents payment_record
        ON payment_record.source_module = 'Client Payment'
       AND payment_record.source_record_id = p.payment_id
       AND payment_record.record_phase = 'Official'
       AND payment_record.status <> 'Recycled'
       AND payment_record.is_locked = 1
     WHERE p.po_id = ?
     ORDER BY p.payment_date DESC"
);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$payment_query = $stmt->get_result();

while($p = $payment_query->fetch_assoc()){
    $total_paid += $p['amount_paid'];
    $payments[] = $p;
}
$balance = $po['amount'] - $total_paid;

$fund_release = null;
$delivery_request = null;
$delivery_receipt = null;
$fund_release_stmt = $conn->prepare(
        "SELECT
            funding.*,
            finance_user.full_name AS released_by_name,
            supplier.supplier_name,
            funding_record.doc_id AS funding_record_doc_id,
            funding_record.record_number AS funding_record_number
         FROM po_supplier_fund_releases funding
         LEFT JOIN users finance_user
            ON finance_user.user_id = funding.released_by
         LEFT JOIN pr_supplier_details supplier
            ON supplier.supplier_detail_id =
                funding.supplier_detail_id
         LEFT JOIN documents funding_record
            ON funding_record.source_module = 'Supplier Fund Release'
            AND funding_record.source_record_id = funding.fund_release_id
            AND funding_record.record_phase = 'Official'
            AND funding_record.status <> 'Recycled'
         WHERE funding.po_id = ?
           AND funding.record_status = 'Active'
         ORDER BY funding.release_cycle DESC
         LIMIT 1"
    );
$fund_release_stmt->bind_param('i', $po_id);
$fund_release_stmt->execute();
$fund_release =
    $fund_release_stmt->get_result()->fetch_assoc();

$delivery_request_stmt = $conn->prepare(
        "SELECT
            delivery_request.*,
            plan.delivery_plan_id,
            plan.logistics_status,
            plan.provider_type,
            plan.provider_name,
            plan.planned_pickup_at,
            plan.planned_delivery_at,
            plan.return_reason,
            preparer.full_name AS prepared_by_name,
            reviewer.full_name AS reviewed_by_name,
            returner.full_name AS returned_by_name,
            request_record.doc_id AS request_record_doc_id,
            request_record.record_number AS request_record_number,
            plan_record.doc_id AS plan_record_doc_id,
            plan_record.record_number AS plan_record_number
         FROM po_delivery_requests delivery_request
         LEFT JOIN po_delivery_plans plan
            ON plan.delivery_request_id =
                delivery_request.delivery_request_id
           AND plan.record_status = 'Active'
         LEFT JOIN users preparer
            ON preparer.user_id = delivery_request.prepared_by
         LEFT JOIN users reviewer
            ON reviewer.user_id = plan.reviewed_by
         LEFT JOIN users returner
            ON returner.user_id = plan.returned_by
         LEFT JOIN documents request_record
            ON request_record.source_module = 'Delivery Request'
            AND request_record.source_record_id =
                delivery_request.delivery_request_id
            AND request_record.record_phase = 'Official'
            AND request_record.status <> 'Recycled'
         LEFT JOIN documents plan_record
            ON plan_record.source_module = 'Logistics Plan'
            AND plan_record.source_record_id = plan.delivery_plan_id
            AND plan_record.record_phase = 'Official'
            AND plan_record.status <> 'Recycled'
         WHERE delivery_request.po_id = ?
           AND delivery_request.record_status = 'Active'
         ORDER BY delivery_request.request_cycle DESC
         LIMIT 1"
    );
$delivery_request_stmt->bind_param('i', $po_id);
$delivery_request_stmt->execute();
$delivery_request =
    $delivery_request_stmt->get_result()->fetch_assoc();

$delivery_receipt_stmt = $conn->prepare(
        "SELECT
            receipt.*,
            recorder.full_name AS recorded_by_name,
            receipt_document.doc_id AS receipt_document_id,
            receipt_document.record_number AS receipt_record_number
         FROM po_delivery_receipts receipt
         LEFT JOIN users recorder
            ON recorder.user_id = receipt.recorded_by
         LEFT JOIN documents receipt_document
            ON receipt_document.source_module = 'Delivery Receipt'
           AND receipt_document.source_record_id =
                receipt.delivery_receipt_id
           AND receipt_document.record_phase = 'Official'
           AND receipt_document.status <> 'Recycled'
         WHERE receipt.po_id = ?
           AND receipt.record_status = 'Active'
         ORDER BY receipt.receipt_cycle DESC
         LIMIT 1"
    );
$delivery_receipt_stmt->bind_param('i', $po_id);
$delivery_receipt_stmt->execute();
$delivery_receipt =
    $delivery_receipt_stmt->get_result()->fetch_assoc();

$timeline_empty_message = 'No recorded events are available yet.';
try {
    $record_timeline = get_po_record_timeline($conn, (int) $po_id);
} catch (Throwable $error) {
    drms_log_workflow_failure('View PO timeline load', $error);
    $record_timeline = [];
    $timeline_empty_message =
        'Some timeline events could not be loaded. Refresh the page or try again later.';
}

$delivery_planning_ui_pending =
    $po['status'] === 'Delivery Requested';
$delivery_completion_ui_pending =
    $po['status'] === 'For Pick-up/Delivery';

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
    <link href="assets/css/funding-release.css?v=<?php echo filemtime(__DIR__ . '/assets/css/funding-release.css'); ?>" rel="stylesheet">
    <link href="assets/css/delivery-request.css?v=<?php echo filemtime(__DIR__ . '/assets/css/delivery-request.css'); ?>" rel="stylesheet">
    <link href="assets/css/delivery-completion.css?v=<?php echo filemtime(__DIR__ . '/assets/css/delivery-completion.css'); ?>" rel="stylesheet">
    <link href="assets/css/po-record-timeline.css?v=<?php echo filemtime(__DIR__ . '/assets/css/po-record-timeline.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
    <link href="assets/css/po-print.css?v=<?php echo (string) (@filemtime(__DIR__ . '/assets/css/po-print.css') ?: 1); ?>" rel="stylesheet">
</head>
<body class="page-view-po workflow-ui">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <div class="view-doc-toolbar view-po-toolbar d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 shadow-sm border rounded-12-imp">
            <a href="po_list.php" class="view-doc-back btn btn-sm btn-light border px-3 shadow-sm fw-600 rounded-8" aria-label="Back to purchase orders">
                <i class="fas fa-arrow-left me-2"></i><span>Back</span>
            </a>
            
            <div class="view-po-toolbar-actions d-flex align-items-center gap-2 text-end">
                
                <?php if ($is_approver && $can_execute_task): ?>
                    <div class="view-po-decision-actions d-inline-flex align-items-center gap-2 m-0 p-0">
                        <?php
                        $funding_action =
                            $approve_action === 'mark_funded';
                        $delivery_request_action =
                            $approve_action === 'create_delivery_request';
                        $logistics_review_action =
                            $delivery_planning_ui_pending &&
                            $role === 'Supply Chain';
                        $delivery_completion_action =
                            $delivery_completion_ui_pending &&
                            $role === 'Supply Chain';
                        if ($delivery_completion_action) {
                            $primary_action_onclick =
                                "window.location.href='complete_delivery.php?po_id=" .
                                (int) $po['po_id'] . "'";
                        } elseif ($logistics_review_action) {
                            $primary_action_onclick =
                                "window.location.href='review_delivery_request.php?po_id=" .
                                (int) $po['po_id'] . "'";
                        } elseif ($funding_action) {
                            $primary_action_onclick =
                                "window.location.href='release_funding.php?po_id=" .
                                (int) $po['po_id'] . "'";
                        } elseif ($delivery_request_action) {
                            $primary_action_onclick =
                                "window.location.href='create_delivery_request.php?po_id=" .
                                (int) $po['po_id'] . "'";
                        } else {
                            $primary_action_onclick =
                                "confirmApprovePO(event, '" .
                                $approve_action . "', '" .
                                $po['po_id'] . "', '" .
                                htmlspecialchars(
                                    $po['po_number'],
                                    ENT_QUOTES
                                ) . "', '" .
                                htmlspecialchars(
                                    $approve_label,
                                    ENT_QUOTES
                                ) . "')";
                        }
                        ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-success px-4 shadow-sm fw-bold rounded-8"
                            onclick="<?php echo $primary_action_onclick; ?>"
                        >
                            <i class="fas <?php echo $funding_action ? 'fa-coins' : ($delivery_request_action ? 'fa-clipboard-check' : ($logistics_review_action ? 'fa-route' : ($delivery_completion_action ? 'fa-box-open' : 'fa-check-circle'))); ?> me-1"></i>
                            <?php echo htmlspecialchars($delivery_completion_action ? 'Complete Client Delivery' : ($logistics_review_action ? 'Review & Schedule' : $approve_label)); ?>
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

                <?php if ($official_po_record): ?>
                    <a
                        href="<?php echo htmlspecialchars($official_po_record_link); ?>"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-danger shadow-sm px-3 fw-bold rounded-8"
                        aria-label="Open the locked Official PO PDF"
                    >
                        <i class="fas fa-file-pdf me-1"></i>
                        <span>View <?php echo htmlspecialchars($official_po_record['record_number']); ?></span>
                    </a>
                <?php endif; ?>

                <button type="button" class="view-doc-print btn btn-sm btn-primary shadow-sm px-3 fw-bold rounded-8" onclick="logAndPrint()" aria-label="Print or save this purchase order as PDF">
                    <i class="fas fa-print me-1"></i><span>Print / Save PDF</span>
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

        <?php if ($fund_release): ?>
            <section class="funding-proof-summary no-print" aria-label="Supplier funding evidence">
                <div class="funding-proof-lead">
                    <div class="funding-proof-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="funding-proof-title">
                        <strong>Supplier funding verified</strong>
                        <span>
                            Release cycle <?php echo (int) $fund_release['release_cycle']; ?>
                            · Finance evidence recorded
                        </span>
                    </div>
                </div>
                <div class="funding-proof-item">
                    <span>Released amount</span>
                    <strong>₱<?php echo number_format((float) $fund_release['released_amount'], 2); ?></strong>
                </div>
                <div class="funding-proof-item">
                    <span>Supplier</span>
                    <strong><?php echo htmlspecialchars((string) $fund_release['supplier_name']); ?></strong>
                </div>
                <div class="funding-proof-item">
                    <span>Method / reference</span>
                    <strong>
                        <?php echo htmlspecialchars($fund_release['release_method']); ?>
                        · <?php echo htmlspecialchars($fund_release['reference_number']); ?>
                    </strong>
                </div>
                <div class="funding-proof-item">
                    <span>Released by</span>
                    <strong><?php echo htmlspecialchars((string) $fund_release['released_by_name']); ?></strong>
                </div>
                <div class="funding-proof-item">
                    <span>Released at</span>
                    <strong><?php echo date('M d, Y · h:i A', strtotime($fund_release['released_at'])); ?></strong>
                </div>
                <?php if (in_array($role, ['Procurement', 'GM', 'President', 'Finance'], true)): ?>
                    <a
                        href="<?php echo !empty($fund_release['funding_record_doc_id'])
                            ? 'download.php?type=document&amp;record_id=' .
                                (int) $fund_release['funding_record_doc_id']
                            : 'download.php?type=fund_release&amp;record_id=' .
                                (int) $fund_release['fund_release_id']; ?>"
                        target="_blank"
                        rel="noopener"
                        class="funding-proof-link"
                        aria-label="<?php echo !empty($fund_release['funding_record_number'])
                            ? 'Open locked Official Record ' .
                                htmlspecialchars(
                                    $fund_release['funding_record_number'],
                                    ENT_QUOTES
                                )
                            : 'Open supplier fund-release proof'; ?>"
                    >
                        <i class="fas <?php echo !empty($fund_release['funding_record_doc_id'])
                            ? 'fa-file-alt'
                            : 'fa-paperclip'; ?>"></i>
                        <?php echo !empty($fund_release['funding_record_number'])
                            ? 'View ' . htmlspecialchars(
                                $fund_release['funding_record_number']
                            )
                            : 'View proof'; ?>
                    </a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($delivery_request): ?>
            <section class="delivery-request-summary no-print" aria-label="Delivery request record">
                <div class="delivery-request-summary-icon">
                    <i class="fas fa-truck-loading"></i>
                </div>
                <div class="delivery-request-summary-title">
                    <strong><?php echo htmlspecialchars($delivery_request['request_number']); ?></strong>
                    <span>
                        <?php echo htmlspecialchars($delivery_request['request_status']); ?>
                        · Logistics <?php echo htmlspecialchars((string) ($delivery_request['logistics_status'] ?? 'Pending Review')); ?>
                    </span>
                </div>
                <div class="delivery-request-summary-item">
                    <span><?php echo $delivery_request['logistics_status'] === 'Scheduled' ? 'Provider' : 'Request type'; ?></span>
                    <strong><?php echo htmlspecialchars($delivery_request['logistics_status'] === 'Scheduled' ? trim((string) $delivery_request['provider_type'] . ' · ' . (string) $delivery_request['provider_name'], ' ·') : $delivery_request['request_type']); ?></strong>
                </div>
                <div class="delivery-request-summary-item">
                    <span>Supplier ready</span>
                    <strong><?php echo date('M d, Y · h:i A', strtotime($delivery_request['supplier_ready_confirmed_at'])); ?></strong>
                </div>
                <div class="delivery-request-summary-item">
                    <span><?php echo $delivery_request['logistics_status'] === 'Scheduled' ? 'Final pick-up' : 'Preferred pick-up'; ?></span>
                    <?php $pickup_display = $delivery_request['logistics_status'] === 'Scheduled' ? $delivery_request['planned_pickup_at'] : $delivery_request['preferred_pickup_at']; ?>
                    <strong><?php echo !empty($pickup_display) ? date('M d, Y · h:i A', strtotime($pickup_display)) : 'Not required'; ?></strong>
                </div>
                <div class="delivery-request-summary-item">
                    <span><?php echo $delivery_request['logistics_status'] === 'Scheduled' ? 'Final delivery' : 'Preferred delivery'; ?></span>
                    <?php $delivery_display = $delivery_request['logistics_status'] === 'Scheduled' ? $delivery_request['planned_delivery_at'] : $delivery_request['preferred_delivery_at']; ?>
                    <strong><?php echo !empty($delivery_display) ? date('M d, Y · h:i A', strtotime($delivery_display)) : 'Not required'; ?></strong>
                </div>
                <div class="delivery-request-summary-item">
                    <span><?php echo $delivery_request['logistics_status'] === 'Scheduled' ? 'Reviewed by' : ($delivery_request['logistics_status'] === 'Returned' ? 'Returned by' : 'Prepared by'); ?></span>
                    <strong><?php echo htmlspecialchars((string) ($delivery_request['logistics_status'] === 'Scheduled' ? ($delivery_request['reviewed_by_name'] ?? 'Supply Chain') : ($delivery_request['logistics_status'] === 'Returned' ? ($delivery_request['returned_by_name'] ?? 'Supply Chain') : ($delivery_request['prepared_by_name'] ?? 'Procurement')))); ?></strong>
                </div>
                <?php if (!empty($delivery_request['request_record_doc_id']) || !empty($delivery_request['plan_record_doc_id'])): ?>
                    <div class="d-flex flex-column gap-1 align-items-stretch">
                        <?php if (!empty($delivery_request['request_record_doc_id'])): ?>
                            <a
                                href="download.php?type=document&amp;record_id=<?php echo (int) $delivery_request['request_record_doc_id']; ?>"
                                target="_blank"
                                rel="noopener"
                                class="delivery-request-summary-status text-decoration-none"
                                aria-label="Open locked Official Record <?php echo htmlspecialchars($delivery_request['request_record_number'], ENT_QUOTES); ?>"
                            >
                                <i class="fas fa-file-alt"></i>
                                <?php echo htmlspecialchars($delivery_request['request_record_number']); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($delivery_request['plan_record_doc_id'])): ?>
                            <a
                                href="download.php?type=document&amp;record_id=<?php echo (int) $delivery_request['plan_record_doc_id']; ?>"
                                target="_blank"
                                rel="noopener"
                                class="delivery-request-summary-status text-decoration-none"
                                aria-label="Open locked Official Record <?php echo htmlspecialchars($delivery_request['plan_record_number'], ENT_QUOTES); ?>"
                            >
                                <i class="fas fa-route"></i>
                                <?php echo htmlspecialchars($delivery_request['plan_record_number']); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="delivery-request-summary-status">
                        <i class="fas fa-clock"></i>
                        <?php echo htmlspecialchars((string) ($delivery_request['logistics_status'] ?? 'Pending Review')); ?>
                    </span>
                <?php endif; ?>
            </section>
            <?php if ($delivery_request['logistics_status'] === 'Returned' && !empty($delivery_request['return_reason'])): ?>
                <section class="delivery-return-notice no-print" role="alert">
                    <i class="fas fa-undo-alt"></i>
                    <div>
                        <strong>Delivery request returned for correction</strong>
                        <span><?php echo htmlspecialchars($delivery_request['return_reason']); ?></span>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($delivery_receipt): ?>
            <section class="delivery-receipt-summary no-print" aria-label="Verified client delivery receipt">
                <div class="delivery-receipt-summary-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <div class="delivery-receipt-summary-title">
                    <strong>Client delivery verified</strong>
                    <span>
                        <?php echo htmlspecialchars((string) ($delivery_receipt['client_receipt_reference'] ?: 'Official proof of delivery recorded')); ?>
                    </span>
                </div>
                <div class="delivery-receipt-summary-item">
                    <span>Actual handover</span>
                    <strong><?php echo date('M d, Y · h:i A', strtotime($delivery_receipt['actual_handover_at'])); ?></strong>
                </div>
                <div class="delivery-receipt-summary-item">
                    <span>Received by</span>
                    <strong><?php echo htmlspecialchars((string) $delivery_receipt['recipient_name']); ?></strong>
                </div>
                <div class="delivery-receipt-summary-item">
                    <span>Delivered quantity</span>
                    <strong><?php echo number_format((int) $delivery_receipt['delivered_item_quantity']); ?> / <?php echo number_format((int) $delivery_receipt['expected_item_quantity']); ?></strong>
                </div>
                <div class="delivery-receipt-summary-item">
                    <span>Condition</span>
                    <strong><?php echo htmlspecialchars((string) $delivery_receipt['delivery_condition']); ?></strong>
                </div>
                <div class="delivery-receipt-summary-item">
                    <span>Collection due</span>
                    <strong><?php echo date('M d, Y', strtotime($delivery_receipt['collection_due_date'])); ?></strong>
                </div>
                <?php if (
                    !empty($delivery_receipt['receipt_document_id']) &&
                    in_array($role, ['GM', 'President', 'Finance', 'Supply Chain'], true)
                ): ?>
                    <a
                        href="download.php?type=document&amp;record_id=<?php echo (int) $delivery_receipt['receipt_document_id']; ?>"
                        target="_blank"
                        rel="noopener"
                        class="delivery-receipt-proof-link"
                        aria-label="Open locked Official Record <?php echo htmlspecialchars((string) $delivery_receipt['receipt_record_number'], ENT_QUOTES); ?>"
                    >
                        <i class="fas fa-paperclip"></i>
                        <?php echo htmlspecialchars((string) $delivery_receipt['receipt_record_number']); ?>
                    </a>
                <?php endif; ?>
            </section>
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
                                <?php if($source_quotation && $role !== 'Supply Chain'): ?>
                                    <a href="view_quotation.php?id=<?php echo (int)$source_quotation['quotation_id']; ?>" class="fw-medium text-primary text-decoration-none"><i class="fas fa-link me-1"></i><?php echo htmlspecialchars($source_quotation['quotation_number']); ?></a>
                                <?php elseif($source_quotation): ?>
                                    <div class="fw-medium text-dark"><?php echo htmlspecialchars($source_quotation['quotation_number']); ?></div>
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
                $pre_delivery_payment_statuses = [
                    'President-Approved',
                    'Funded',
                    'Delivery Requested',
                    'For Pick-up/Delivery',
                ];
                $pre_delivery_payment_window = in_array(
                    $po['status'],
                    $pre_delivery_payment_statuses,
                    true
                );
                $payment_visible_statuses = array_merge(
                    $pre_delivery_payment_statuses,
                    ['Delivered']
                );
                
                if(in_array($po['status'], $payment_visible_statuses, true)):
                ?>
                <div class="card border-0 shadow-sm mb-4 payment-card rounded-16 overflow-hidden">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center border-bottom border-light">
                        <span class="fs-6 text-dark"><i class="fas fa-hand-holding-usd me-2 text-success"></i> Payment History</span>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge bg-light text-dark border px-3 py-2 rounded-8">
                                Collection: <?php echo htmlspecialchars((string) ($po['collection_status'] ?? 'Unpaid')); ?>
                            </span>
                            <?php if(!$pre_delivery_payment_window && in_array($_SESSION['role'], ['Finance', 'GM', 'President'], true)): ?>
                                <a href="collection_statement.php?po_id=<?php echo (int) $po_id; ?>" class="btn btn-sm btn-outline-primary fw-bold rounded-8 px-3" target="_blank" rel="noopener">
                                    <i class="fas fa-file-invoice-dollar me-1"></i> Statement
                                </a>
                            <?php endif; ?>
                            <?php if($balance > 0.01): ?>
                                <span class="badge bg-warning text-dark px-3 py-2 shadow-sm rounded-8">Balance: ₱ <?php echo number_format($balance, 2); ?></span>
                            <?php else: ?>
                                <span class="badge bg-success px-3 py-2 shadow-sm rounded-8"><i class="fas fa-check-double me-1"></i> Fully Paid</span>
                            <?php endif; ?>
                        </div>
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
                                            <?php if(($pay['payment_classification'] ?? '') === 'Advance / Down Payment'): ?>
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary me-1 px-2 py-1">Advance / Down Payment</span>
                                            <?php elseif(($pay['payment_classification'] ?? '') === 'Full Payment' || stripos((string) $pay['notes'], 'Full') !== false): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success me-1 px-2 py-1">Full Payment</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-dark border border-warning me-1 px-2 py-1">Partial Payment</span>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($pay['payment_method'] ?? '--'); ?><?php if(!empty($pay['recorded_by_name'])): ?> · Recorded by <?php echo htmlspecialchars($pay['recorded_by_name']); ?><?php endif; ?></div>
                                        </td>
                                        <td class="py-3 align-middle">
                                            <div class="small fw-bold text-dark text-break"><?php echo htmlspecialchars($pay['reference_number'] ?? '--'); ?></div>
                                            <?php if(!empty($pay['payment_record_doc_id'])): ?>
                                                <a href="download.php?type=document&amp;record_id=<?php echo (int) $pay['payment_record_doc_id']; ?>" target="_blank" rel="noopener" class="small text-primary text-decoration-none" aria-label="Open locked Official Record <?php echo htmlspecialchars((string) $pay['payment_record_number'], ENT_QUOTES); ?>"><i class="fas fa-paperclip me-1"></i><?php echo htmlspecialchars((string) $pay['payment_record_number']); ?></a>
                                            <?php elseif(!empty($pay['proof_file_path'])): ?>
                                                <a href="download.php?type=payment_proof&amp;record_id=<?php echo (int) $pay['payment_id']; ?>" target="_blank" rel="noopener" class="small text-primary text-decoration-none"><i class="fas fa-paperclip me-1"></i>Legacy proof</a>
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
                    
                    <?php if($balance > 0.01 && $_SESSION['role'] == 'Finance' && $pre_delivery_payment_window): ?>
                    <div class="card-footer bg-light p-3 border-top d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="small text-muted"><i class="fas fa-shield-alt me-1"></i> A verified down payment updates only Collection Status; the current PO workflow task remains unchanged.</div>
                        <a href="record_collection_payment.php?po_id=<?php echo (int) $po_id; ?>" class="btn btn-primary btn-sm fw-bold px-3 rounded-8">
                            <i class="fas fa-hand-holding-dollar me-1"></i> Record Down Payment
                        </a>
                    </div>
                    <?php elseif($balance > 0.01 && $_SESSION['role'] == 'Finance' && $po['status'] === 'Delivered' && $is_task_assignee): ?>
                    <div class="card-footer bg-light p-3 border-top d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="small text-muted"><i class="fas fa-shield-alt me-1"></i> Record the verified client payment through the official Collection Payment form.</div>
                        <a href="record_collection_payment.php?po_id=<?php echo (int) $po_id; ?>" class="btn btn-success btn-sm fw-bold px-3 rounded-8">
                            <i class="fas fa-hand-holding-dollar me-1"></i> Record Client Payment
                        </a>
                    </div>
                    <?php elseif($balance > 0.01 && $_SESSION['role'] == 'Finance'): ?>
                    <div class="card-footer bg-light p-3 border-top small text-muted"><i class="fas fa-lock me-1"></i> <?php echo $task_locked_for_another_user ? 'Payment entry is reserved for the assigned Finance user.' : 'No active Finance assignment is available for this collection task.'; ?></div>
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
                            $stmt = $conn->prepare("SELECT * FROM documents WHERE po_id = ? AND COALESCE(disposition_status, '') <> 'Destroyed'");
                            $stmt->bind_param("i", $po_id);
                            $stmt->execute();
                            $docs = $stmt->get_result();

                            if($docs->num_rows > 0):
                                while($doc = $docs->fetch_assoc()):
                                    $fileNameOnly = basename($doc['file_path']);
                                    $secureLink = "download.php?type=document&record_id=" . intval($doc['doc_id']);
                                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                ?>
                                    <li class="mb-2 p-2 bg-light rounded border d-flex align-items-center justify-content-between po-attachment-row">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden">
                                            <?php if($isImage): ?>
                                                <img
                                                    src="<?php echo $secureLink; ?>"
                                                    class="file-thumbnail bg-white"
                                                    alt="Preview <?php echo htmlspecialchars($doc['file_name']); ?>"
                                                    role="button"
                                                    tabindex="0"
                                                    onclick="viewFile('<?php echo $secureLink; ?>', 'image')"
                                                    onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); viewFile('<?php echo $secureLink; ?>', 'image'); }"
                                                >
                                            <?php elseif($isPdf): ?>
                                                <div
                                                    class="file-icon text-danger bg-white shadow-sm"
                                                    role="button"
                                                    tabindex="0"
                                                    aria-label="Preview <?php echo htmlspecialchars($doc['file_name']); ?>"
                                                    onclick="viewFile('<?php echo $secureLink; ?>', 'pdf')"
                                                    onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); viewFile('<?php echo $secureLink; ?>', 'pdf'); }"
                                                ><i class="fas fa-file-pdf"></i></div>
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
                                            <a href="<?php echo $secureLink; ?>" class="btn btn-sm btn-white border" title="Download" aria-label="Download <?php echo htmlspecialchars($doc['file_name']); ?>"><i class="fas fa-download text-primary"></i></a>
                                            <?php if($can_delete_files): ?>
                                            <form action="actions/po_handler.php" method="POST" onsubmit="return confirm('Permanently delete this file?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-white border text-danger" title="Delete attachment" aria-label="Delete <?php echo htmlspecialchars($doc['file_name']); ?>"><i class="fas fa-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endwhile; 
                            else: ?>
                                <li class="po-attachments-empty border border-dashed">
                                    <span class="po-attachments-empty-icon" aria-hidden="true">
                                        <i class="fas fa-inbox"></i>
                                    </span>
                                    <span class="po-attachments-empty-text">No documents attached yet.</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                        
                        <?php if($can_upload_files): ?>
                        <hr>
                        <form action="actions/po_handler.php" method="POST" enctype="multipart/form-data">
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

                <div class="card border-0 shadow-sm rounded-16 po-timeline-card">
                    <div class="card-header bg-white py-3 border-bottom border-light d-flex align-items-center justify-content-between gap-2">
                        <span class="po-timeline-heading">
                            <i class="fas fa-timeline me-2 text-primary"></i>
                            Record Timeline
                        </span>
                        <span class="po-timeline-count"><?php echo count($record_timeline); ?> events</span>
                    </div>
                    <div class="po-record-timeline" aria-label="Complete purchase order record timeline">
                        <?php if (empty($record_timeline)): ?>
                            <div class="po-timeline-empty">
                                <i class="fas fa-clock-rotate-left"></i>
                                <span><?php echo htmlspecialchars($timeline_empty_message); ?></span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($record_timeline as $timeline_event): ?>
                                <article class="po-timeline-event timeline-tone-<?php echo htmlspecialchars($timeline_event['tone']); ?>">
                                    <div class="po-timeline-marker" aria-hidden="true">
                                        <i class="fas <?php echo htmlspecialchars($timeline_event['icon']); ?>"></i>
                                    </div>
                                    <div class="po-timeline-content">
                                        <div class="po-timeline-meta">
                                            <span class="po-timeline-category"><?php echo htmlspecialchars($timeline_event['category']); ?></span>
                                            <time datetime="<?php echo htmlspecialchars(date('c', strtotime($timeline_event['occurred_at']))); ?>">
                                                <?php echo date('M d, Y · h:i A', strtotime($timeline_event['occurred_at'])); ?>
                                            </time>
                                        </div>
                                        <div class="po-timeline-title"><?php echo htmlspecialchars($timeline_event['title']); ?></div>
                                        <?php if ($timeline_event['detail'] !== ''): ?>
                                            <div class="po-timeline-detail"><?php echo htmlspecialchars($timeline_event['detail']); ?></div>
                                        <?php endif; ?>
                                        <div class="po-timeline-actor">
                                            <i class="far fa-user"></i>
                                            <?php echo htmlspecialchars($timeline_event['actor']); ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <section class="po-print-document" aria-label="Printable purchase order">
            <?php if (!$is_official_po): ?>
                <div class="po-print-draft-banner">Draft copy — not an official purchase order</div>
            <?php endif; ?>

            <header class="po-print-header">
                <div class="po-print-brand">
                    <img src="assets/images/fixie_logo.png" alt="Fixie Computer Ventures logo">
                    <div>
                        <strong>Fixie Computer Ventures</strong>
                        <span>Computer products and business solutions</span>
                        <small>Internal procurement document</small>
                    </div>
                </div>
                <div class="po-print-title">
                    <span>Purchase Order</span>
                    <h1><?php echo htmlspecialchars($po['po_number']); ?></h1>
                    <strong class="po-print-record-status <?php echo $is_official_po ? 'is-official' : 'is-draft'; ?>">
                        <?php echo $print_po_record_status; ?>
                    </strong>
                </div>
            </header>

            <section class="po-print-reference-grid" aria-label="Document references">
                <div>
                    <span>PO number</span>
                    <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                    <?php if ($official_po_record): ?>
                        <small>RMS: <?php echo htmlspecialchars($official_po_record['record_number']); ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Date prepared</span>
                    <strong><?php echo po_print_date($po['date_created']); ?></strong>
                </div>
                <div>
                    <span>Source PRF</span>
                    <strong><?php echo htmlspecialchars($source_quotation['pr_number'] ?? 'Not recorded'); ?></strong>
                </div>
                <div>
                    <span>Operational status</span>
                    <strong><?php echo htmlspecialchars($po['status']); ?></strong>
                </div>
            </section>

            <section class="po-print-party-grid" aria-label="Client and supplier details">
                <article>
                    <span class="po-print-section-label">Client order</span>
                    <h2><?php echo htmlspecialchars($po['client_name']); ?></h2>
                    <dl>
                        <div><dt>Client PO</dt><dd><?php echo htmlspecialchars($source_quotation['actual_client_po_number'] ?? 'Not recorded'); ?></dd></div>
                        <div><dt>Client PO date</dt><dd><?php echo po_print_date($source_quotation['client_po_date'] ?? null); ?></dd></div>
                        <div><dt>Quotation</dt><dd><?php echo htmlspecialchars($source_quotation['quotation_number'] ?? ($po['quotation_number'] ?: 'Not recorded')); ?></dd></div>
                        <div><dt>Prepared by</dt><dd><?php echo htmlspecialchars($po['creator_name'] ?: 'Not recorded'); ?></dd></div>
                    </dl>
                </article>
                <article>
                    <span class="po-print-section-label">Supplier details</span>
                    <h2><?php echo htmlspecialchars($source_quotation['supplier_name'] ?? 'Not recorded'); ?></h2>
                    <dl>
                        <div><dt>Supplier reference</dt><dd><?php echo htmlspecialchars(($source_quotation['supplier_reference'] ?? '') ?: 'Not recorded'); ?></dd></div>
                        <div><dt>Quotation date</dt><dd><?php echo po_print_date($source_quotation['supplier_quote_date'] ?? null); ?></dd></div>
                        <div><dt>Payment method</dt><dd><?php echo htmlspecialchars($source_quotation['payment_method'] ?? 'Not recorded'); ?></dd></div>
                        <div><dt>Payment terms</dt><dd><?php echo htmlspecialchars(($source_quotation['payment_terms'] ?? '') ?: 'Not recorded'); ?></dd></div>
                    </dl>
                </article>
            </section>

            <section class="po-print-section">
                <div class="po-print-section-heading">
                    <div>
                        <span>Order items</span>
                        <h2>Supplier cost worksheet</h2>
                    </div>
                    <small><?php echo count($items_data); ?> item line<?php echo count($items_data) === 1 ? '' : 's'; ?></small>
                </div>

                <table class="po-print-items">
                    <thead>
                        <tr>
                            <th class="is-number">#</th>
                            <th>Item description and specifications</th>
                            <th class="is-quantity">Qty</th>
                            <th class="is-money">Unit cost</th>
                            <th class="is-money">Cost total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items_data)): ?>
                            <tr><td colspan="5" class="po-print-empty">No item lines recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items_data as $index => $item): ?>
                                <?php
                                $print_category =
                                    $po_print_category_map[$item['category']] ??
                                    $item['category'];
                                $print_unit_cost = $item['unit_cost'] !== null
                                    ? (float) $item['unit_cost']
                                    : (float) $item['unit_price'];
                                $print_line_cost = $item['total_cost'] !== null
                                    ? (float) $item['total_cost']
                                    : ((int) $item['quantity'] * $print_unit_cost);
                                ?>
                                <tr>
                                    <td class="is-number"><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                        <small>
                                            <?php echo htmlspecialchars((string) $print_category); ?>
                                            <?php if (!empty($item['brand'])): ?>
                                                · <?php echo htmlspecialchars($item['brand']); ?>
                                            <?php endif; ?>
                                        </small>
                                        <?php if (!empty($item['specifications'])): ?>
                                            <p><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="is-quantity"><?php echo (int) $item['quantity']; ?></td>
                                    <td class="is-money"><?php echo po_print_money($print_unit_cost); ?></td>
                                    <td class="is-money"><strong><?php echo po_print_money($print_line_cost); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="po-print-financial-section" aria-label="Financial summary">
                <div class="po-print-financial-copy">
                    <span class="po-print-section-label">Financial position</span>
                    <h2>Approved funding and profitability</h2>
                    <p>This internal copy preserves the approved client amount, supplier cost, requested funding, and projected profit inherited from the official PRF.</p>
                    <?php if (!empty($po['remarks'])): ?>
                        <div class="po-print-remarks">
                            <span>PO remarks</span>
                            <p><?php echo nl2br(htmlspecialchars($po['remarks'])); ?></p>
                        </div>
                    <?php elseif (!empty($source_quotation['supplier_remarks'])): ?>
                        <div class="po-print-remarks">
                            <span>Supplier remarks</span>
                            <p><?php echo nl2br(htmlspecialchars($source_quotation['supplier_remarks'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <dl class="po-print-financial-list">
                    <div><dt>Client selling amount</dt><dd><?php echo po_print_money($po['amount']); ?></dd></div>
                    <div><dt>Cost of goods</dt><dd><?php echo po_print_money($po['cost_of_goods_amount'] ?? 0); ?></dd></div>
                    <div><dt>Other expense</dt><dd><?php echo po_print_money($po['other_expense_amount'] ?? 0); ?></dd></div>
                    <div class="is-requested"><dt>Approved funds</dt><dd><?php echo po_print_money($po['requested_fund_amount'] ?? 0); ?></dd></div>
                    <div class="is-profit"><dt>Projected gross profit</dt><dd><?php echo po_print_money($po['gross_profit_amount'] ?? 0); ?></dd></div>
                    <div><dt>Projected margin</dt><dd><?php echo number_format((float) ($po['gross_margin_percent'] ?? 0), 2); ?>%</dd></div>
                </dl>
            </section>

            <section class="po-print-approvals" aria-label="Approval record">
                <div class="po-print-section-heading">
                    <div>
                        <span>Signatories</span>
                        <h2>Preparation and authorization record</h2>
                    </div>
                    <small><?php echo htmlspecialchars($print_po_approval_basis); ?></small>
                </div>

                <div class="po-print-signature-grid">
                    <article>
                        <div class="po-print-signature-line"></div>
                        <strong><?php echo htmlspecialchars($po['creator_name'] ?: 'Not recorded'); ?></strong>
                        <span>Prepared by Procurement</span>
                        <small><?php echo po_print_date($po['date_created'], 'M d, Y · h:i A'); ?></small>
                    </article>

                    <?php foreach ($print_po_signatory_stages as $signatory_stage): ?>
                        <?php
                        $pr_signatory =
                            $source_pr_approval_by_stage[$signatory_stage['pr_stage']] ??
                            null;
                        $po_signatory =
                            $po_approval_history_by_status[$signatory_stage['po_status']] ??
                            null;

                        $signatory_name = '';
                        $signatory_date = null;
                        $signatory_decision = 'Pending';
                        $signatory_source = '';

                        if (
                            $pr_signatory &&
                            $pr_signatory['decision'] === 'Approved' &&
                            !empty($pr_signatory['acted_by']) &&
                            !empty($pr_signatory['acted_at'])
                        ) {
                            $signatory_name =
                                $pr_signatory['acted_by_name'] ?: 'Recorded approver';
                            $signatory_date = $pr_signatory['acted_at'];
                            $signatory_decision = 'Approved';
                            $signatory_source = 'Official PRF approval';
                        } elseif (
                            $po_signatory &&
                            $po_signatory['status_from'] ===
                                $signatory_stage['expected_from'] &&
                            !empty($po_signatory['changed_by']) &&
                            !empty($po_signatory['acted_at'])
                        ) {
                            $signatory_name =
                                $po_signatory['acted_by_name'] ?: 'Recorded approver';
                            $signatory_date = $po_signatory['acted_at'];
                            $signatory_decision = 'Approved';
                            $signatory_source = 'PO approval history';
                        }
                        ?>
                        <article>
                            <div class="po-print-signature-line"></div>
                            <strong><?php echo htmlspecialchars($signatory_name ?: 'Pending signatory'); ?></strong>
                            <span><?php echo htmlspecialchars($signatory_stage['label']); ?></span>
                            <small>
                                <?php echo htmlspecialchars($signatory_decision); ?>
                                <?php if ($signatory_date): ?>
                                    · <?php echo po_print_date($signatory_date, 'M d, Y · h:i A'); ?>
                                <?php endif; ?>
                                <?php if ($signatory_source !== ''): ?>
                                    <br><?php echo htmlspecialchars($signatory_source); ?>
                                <?php endif; ?>
                            </small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <footer class="po-print-footer">
                <span>Generated from the Fixie DRMS on <?php echo date('M d, Y · h:i A'); ?>.</span>
                <strong>
                    <?php echo $is_official_po
                        ? 'Official status verified from the completed authorization record' .
                            ($official_po_record
                                ? ' and filed as ' .
                                    htmlspecialchars($official_po_record['record_number']) . '.'
                                : '.')
                        : 'This copy remains a draft until final Owner / President authorization is verified.'; ?>
                </strong>
            </footer>
        </section>
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

        <?php if($request_success !== ''): ?>
            Toast.fire({
                icon: 'success',
                title: <?php echo json_encode($request_success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            });
            window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $po_id; ?>");
        <?php endif; ?>

        <?php if($request_error !== ''): ?>
            Toast.fire({
                icon: 'error',
                title: <?php echo json_encode($request_error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
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
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading preview...</span></div>';
            
            if (type === 'image') {
                modalBody.innerHTML = `<img src="${path}" class="img-fluid max-h-80vh" alt="Document preview">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" class="border-none" title="PDF document preview"></iframe>`;
            } else {
                modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary fw-bold">Download File</a></div>`;
            }
            myModal.show();
        }
        
        function logAndPrint() {
            const auditPayload = new URLSearchParams({
                action: 'log_print',
                record_type: 'purchase_order',
                record_id: '<?php echo (int) $po_id; ?>',
                csrf_token: <?php echo json_encode((string) ($_SESSION['csrf_token'] ?? '')); ?>
            });

            fetch('api/log_print.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: auditPayload.toString()
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Print audit request failed with status ' + response.status);
                }
                return response.json();
            })
            .catch(error => {
                console.warn('The print activity could not be recorded.', error);
            })
            .finally(() => {
                window.print();
            });
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
