<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';

drms_require_workflow_roles([
    'Sales Staff',
    'Procurement',
    'GM',
    'President',
    'Finance',
]);

$pr_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($pr_id < 1) {
    header('Location: pr_list.php?error=' . rawurlencode('Invalid Purchase Request.'));
    exit();
}

$pr_stmt = $conn->prepare(
    "SELECT
        p.*,
        creator.full_name AS creator_name,
        creator.role AS creator_role,
        final_approver.full_name AS final_approver_name,
        q.quotation_number,
        q.status AS quotation_status,
        client_po.actual_client_po_number,
        client_po.client_po_date,
        client_po.final_approval_date AS client_final_approval_date,
        client_po.proof_original_name AS client_po_file_name,
        client_po.proof_file_path AS client_po_file_path
     FROM purchase_requests p
     LEFT JOIN users creator
       ON creator.user_id = p.created_by
     LEFT JOIN users final_approver
       ON final_approver.user_id = p.final_approved_by
     LEFT JOIN quotations q
       ON q.quotation_id = p.quotation_id
     LEFT JOIN client_approval_records client_po
       ON client_po.approval_record_id = p.client_approval_record_id
     WHERE p.pr_id = ?
     LIMIT 1"
);
$pr_stmt->bind_param('i', $pr_id);
$pr_stmt->execute();
$pr = $pr_stmt->get_result()->fetch_assoc();

if (!$pr) {
    header('Location: pr_list.php?error=' . rawurlencode('Purchase Request not found.'));
    exit();
}

$items_stmt = $conn->prepare(
    "SELECT *
     FROM pr_items
     WHERE pr_id = ?
     ORDER BY item_id"
);
$items_stmt->bind_param('i', $pr_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

$supplier = null;
$approval_records = [];
$current_approval = null;

$supplier_stmt = $conn->prepare(
    "SELECT *
     FROM pr_supplier_details
     WHERE pr_id = ?
       AND record_status = 'Active'
     LIMIT 1"
);
$supplier_stmt->bind_param('i', $pr_id);
$supplier_stmt->execute();
$supplier = $supplier_stmt->get_result()->fetch_assoc();

$approval_stmt = $conn->prepare(
    "SELECT
        approval.*,
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
$approval_stmt->bind_param('ii', $pr_id, $pr_id);
$approval_stmt->execute();
$approval_result = $approval_stmt->get_result();

while ($approval = $approval_result->fetch_assoc()) {
    $approval_records[] = $approval;
    if (
        $approval['decision'] === 'Pending' &&
        $approval['approval_stage'] === $pr['current_approval_stage']
    ) {
        $current_approval = $approval;
    }
}

// An official route is proven by its recorded approval stages, not a version flag.
$is_sequential = !empty($approval_records);

$role = (string) $_SESSION['role'];
$can_decide = $is_sequential &&
    $pr['status'] === 'Pending' &&
    $current_approval &&
    $role === $current_approval['required_role'];
$can_convert = $is_sequential &&
    $role === 'Procurement' &&
    $pr['status'] === 'Approved' &&
    $pr['current_approval_stage'] === 'Official Approved' &&
    !empty($pr['final_approved_by']) &&
    !empty($pr['final_approved_at']);

$approval_action = 'approve_pr_stage';
$rejection_action = 'reject_pr_stage';
$decision_stage = $is_sequential && $current_approval
    ? $current_approval['approval_stage']
    : 'Management Approval';
$approval_button_label = $is_sequential && $decision_stage === 'Owner Approval'
    ? 'Final approve'
    : 'Approve stage';

$status_label = str_replace('_', ' ', (string) $pr['status']);
$status_class = 'is-pending';
$status_icon = 'fa-clock';

if ($pr['status'] === 'Approved') {
    $status_label = $is_sequential ? 'Officially Approved' : 'Approved';
    $status_class = 'is-approved';
    $status_icon = 'fa-check-circle';
} elseif ($pr['status'] === 'Rejected') {
    $status_label = 'Rejected';
    $status_class = 'is-rejected';
    $status_icon = 'fa-times-circle';
} elseif ($pr['status'] === 'Converted_to_PO') {
    $status_label = 'Converted to PO';
    $status_class = 'is-converted';
    $status_icon = 'fa-file-invoice';
} elseif ($is_sequential) {
    $status_label = (string) $pr['current_approval_stage'];
}

$client_po_link = !empty($pr['client_po_file_path'])
    ? 'download.php?type=client_approval&record_id=' .
        (int) $pr['client_approval_record_id']
    : '';
$supplier_quote_link = $supplier && !empty($supplier['supplier_quote_file_path'])
    ? 'download.php?type=supplier_quote&record_id=' .
        (int) $supplier['supplier_detail_id']
    : '';

$category_map = [
    '01' => 'Hardware',
    '02' => 'CCTVs',
    '03' => 'Peripherals',
    '04' => 'Office Supplies',
    '05' => 'WIFI / LAN',
    '06' => 'Printers',
];

function prf_review_date(?string $value, string $format = 'M d, Y'): string
{
    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : '—';
}

function prf_review_money($value): string
{
    return '₱' . number_format((float) $value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($pr['pr_number']); ?> - Purchase Request</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="assets/css/prf-review.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-review.css'); ?>" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="page-view-pr prf-review-page workflow-ui">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-review-shell">
            <header class="prf-review-header">
                <a href="pr_list.php" class="prf-review-back" aria-label="Back to purchase requests">
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-review-heading">
                    <span class="prf-review-eyebrow">Purchase request</span>
                    <h2><?php echo htmlspecialchars($pr['pr_number']); ?></h2>
                    <p><?php echo htmlspecialchars($pr['client_name']); ?></p>
                </div>

                <div class="prf-review-header-actions">
                    <span class="prf-review-version"><i class="fas fa-route"></i> PRF workflow</span>
                    <span class="prf-review-status <?php echo $status_class; ?>">
                        <i class="fas <?php echo $status_icon; ?>"></i>
                        <?php echo htmlspecialchars($status_label); ?>
                    </span>
                </div>
            </header>

            <?php if (isset($_GET['success']) && trim((string) $_GET['success']) !== ''): ?>
                <div class="prf-review-alert is-success" data-prf-review-alert role="status">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars((string) $_GET['success']); ?></span>
                    <button type="button" data-dismiss-prf-alert aria-label="Dismiss"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && trim((string) $_GET['error']) !== ''): ?>
                <div class="prf-review-alert is-error" data-prf-review-alert role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars((string) $_GET['error']); ?></span>
                    <button type="button" data-dismiss-prf-alert aria-label="Dismiss"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>

            <section class="prf-review-source-card">
                <div class="prf-review-source-item is-primary">
                    <span>Client</span>
                    <strong><?php echo htmlspecialchars($pr['client_name']); ?></strong>
                </div>
                <div class="prf-review-source-item">
                    <span>Source quotation</span>
                    <?php if (!empty($pr['quotation_number'])): ?>
                        <a href="view_quotation.php?id=<?php echo (int) $pr['quotation_id']; ?>">
                            <?php echo htmlspecialchars($pr['quotation_number']); ?>
                        </a>
                    <?php else: ?>
                        <strong>—</strong>
                    <?php endif; ?>
                </div>
                <div class="prf-review-source-item">
                    <span>Official Client PO</span>
                    <strong><?php echo htmlspecialchars($pr['actual_client_po_number'] ?: 'Not recorded'); ?></strong>
                </div>
                <div class="prf-review-source-item">
                    <span>Submitted by</span>
                    <strong><?php echo htmlspecialchars($pr['creator_name'] ?: 'Unknown user'); ?></strong>
                    <small><?php echo htmlspecialchars($pr['creator_role'] ?: '—'); ?></small>
                </div>
                <div class="prf-review-source-item">
                    <span>Date submitted</span>
                    <strong><?php echo prf_review_date($pr['submitted_for_approval_at'] ?: $pr['date_created'], 'M d, Y · h:i A'); ?></strong>
                </div>
                <div class="prf-review-source-documents">
                    <?php if ($client_po_link !== ''): ?>
                        <a href="<?php echo htmlspecialchars($client_po_link); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-paperclip"></i> Client PO
                        </a>
                    <?php endif; ?>
                    <?php if ($supplier_quote_link !== ''): ?>
                        <a href="<?php echo htmlspecialchars($supplier_quote_link); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-file-alt"></i> Supplier quote
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($is_sequential): ?>
                <section class="prf-review-route-card" aria-label="PRF approval progress">
                    <div class="prf-review-section-title">
                        <div>
                            <span>Approval progress</span>
                            <h3>Sequential review route</h3>
                        </div>
                        <small>Stages cannot be skipped</small>
                    </div>

                    <div class="prf-review-route">
                        <?php foreach ($approval_records as $approval): ?>
                            <?php
                            $step_class = 'is-waiting';
                            $step_icon = (string) $approval['stage_sequence'];

                            if ($approval['decision'] === 'Approved') {
                                $step_class = 'is-approved';
                                $step_icon = '<i class="fas fa-check"></i>';
                            } elseif ($approval['decision'] === 'Rejected') {
                                $step_class = 'is-rejected';
                                $step_icon = '<i class="fas fa-times"></i>';
                            } elseif ($approval['decision'] === 'Returned') {
                                $step_class = 'is-closed';
                                $step_icon = '<i class="fas fa-minus"></i>';
                            } elseif ($approval['approval_stage'] === $pr['current_approval_stage']) {
                                $step_class = 'is-current';
                            }

                            $stage_display = $approval['approval_stage'] === 'Owner Approval'
                                ? 'Owner / President Approval'
                                : $approval['approval_stage'];
                            ?>
                            <article class="prf-review-route-step <?php echo $step_class; ?>">
                                <div class="prf-review-step-marker"><?php echo $step_icon; ?></div>
                                <div class="prf-review-step-copy">
                                    <div class="prf-review-step-heading">
                                        <strong><?php echo htmlspecialchars($stage_display); ?></strong>
                                        <span><?php echo htmlspecialchars($approval['decision']); ?></span>
                                    </div>
                                    <small>Required role: <?php echo htmlspecialchars($approval['required_role']); ?></small>

                                    <?php if ($approval['acted_by_name']): ?>
                                        <p>
                                            <?php echo htmlspecialchars($approval['acted_by_name']); ?>
                                            · <?php echo prf_review_date($approval['acted_at'], 'M d, Y · h:i A'); ?>
                                        </p>
                                    <?php elseif ($approval['decision'] === 'Pending' && $step_class === 'is-current'): ?>
                                        <p>Waiting for the assigned reviewer</p>
                                    <?php else: ?>
                                        <p>Not yet available</p>
                                    <?php endif; ?>

                                    <?php if (!empty($approval['decision_remarks'])): ?>
                                        <blockquote><?php echo nl2br(htmlspecialchars($approval['decision_remarks'])); ?></blockquote>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="prf-review-layout">
                <div class="prf-review-main-column">
                    <?php if ($is_sequential): ?>
                        <section class="prf-review-card">
                            <div class="prf-review-card-header">
                                <div>
                                    <span>Financial review</span>
                                    <h3>Cost and profit summary</h3>
                                </div>
                                <small>Prepared by Sales Staff</small>
                            </div>

                            <div class="prf-review-financial-grid">
                                <div><span>Client selling amount</span><strong><?php echo prf_review_money($pr['amount']); ?></strong></div>
                                <div><span>Cost of goods</span><strong><?php echo prf_review_money($pr['cost_of_goods_amount']); ?></strong></div>
                                <div><span>Other expense</span><strong><?php echo prf_review_money($pr['other_expense_amount']); ?></strong></div>
                                <div class="is-requested"><span>Funds requested</span><strong><?php echo prf_review_money($pr['requested_fund_amount']); ?></strong></div>
                                <div class="is-profit <?php echo (float) $pr['gross_profit_amount'] < 0 ? 'is-negative' : ''; ?>">
                                    <span>Projected gross profit</span>
                                    <strong><?php echo prf_review_money($pr['gross_profit_amount']); ?></strong>
                                    <small><?php echo number_format((float) $pr['gross_margin_percent'], 2); ?>% margin</small>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="prf-review-card">
                        <div class="prf-review-card-header">
                            <div>
                                <span><?php echo $is_sequential ? 'Cost worksheet' : 'Requested items'; ?></span>
                                <h3>Item breakdown</h3>
                            </div>
                            <small><?php echo count($items); ?> item line<?php echo count($items) === 1 ? '' : 's'; ?></small>
                        </div>

                        <div class="prf-review-table-wrap">
                            <table class="prf-review-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Selling / unit</th>
                                        <?php if ($is_sequential): ?>
                                            <th class="text-end">Supplier cost / unit</th>
                                            <th class="text-end">Cost total</th>
                                            <th class="text-end">Line profit</th>
                                        <?php else: ?>
                                            <th class="text-end">Selling total</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php $category = $category_map[$item['category']] ?? $item['category']; ?>
                                        <tr>
                                            <td data-label="Item">
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                <small>
                                                    <?php echo htmlspecialchars((string) $category); ?>
                                                    <?php if (!empty($item['brand'])): ?>
                                                        · <?php echo htmlspecialchars($item['brand']); ?>
                                                    <?php endif; ?>
                                                </small>
                                                <?php if (!empty($item['specifications'])): ?>
                                                    <span><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Qty" class="text-center"><?php echo (int) $item['quantity']; ?></td>
                                            <td data-label="Selling / unit" class="text-end"><?php echo prf_review_money($item['unit_price']); ?></td>
                                            <?php if ($is_sequential): ?>
                                                <td data-label="Supplier cost / unit" class="text-end"><?php echo prf_review_money($item['unit_cost']); ?></td>
                                                <td data-label="Cost total" class="text-end"><?php echo prf_review_money($item['total_cost']); ?></td>
                                                <td data-label="Line profit" class="text-end prf-review-profit <?php echo (float) $item['line_profit_amount'] < 0 ? 'is-negative' : ''; ?>">
                                                    <?php echo prf_review_money($item['line_profit_amount']); ?>
                                                </td>
                                            <?php else: ?>
                                                <td data-label="Selling total" class="text-end"><strong><?php echo prf_review_money($item['total_price']); ?></strong></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($is_sequential && $supplier): ?>
                        <section class="prf-review-card">
                            <div class="prf-review-card-header">
                                <div>
                                    <span>Supplier record</span>
                                    <h3>Supplier and payment details</h3>
                                </div>
                                <?php if ($supplier_quote_link !== ''): ?>
                                    <a class="prf-review-header-link" href="<?php echo htmlspecialchars($supplier_quote_link); ?>" target="_blank" rel="noopener">
                                        <i class="fas fa-paperclip"></i> View quotation
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="prf-review-detail-grid">
                                <div><span>Supplier name</span><strong><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong></div>
                                <div><span>Supplier reference</span><strong><?php echo htmlspecialchars($supplier['supplier_reference'] ?: '—'); ?></strong></div>
                                <div><span>Quotation date</span><strong><?php echo prf_review_date($supplier['supplier_quote_date']); ?></strong></div>
                                <div><span>Payment method</span><strong><?php echo htmlspecialchars($supplier['payment_method']); ?></strong></div>
                                <div class="prf-review-span-2"><span>Payment terms</span><strong><?php echo htmlspecialchars($supplier['payment_terms'] ?: '—'); ?></strong></div>

                                <?php if ($supplier['payment_method'] === 'Bank Transfer'): ?>
                                    <div><span>Bank name</span><strong><?php echo htmlspecialchars($supplier['bank_name']); ?></strong></div>
                                    <div><span>Account name</span><strong><?php echo htmlspecialchars($supplier['bank_account_name']); ?></strong></div>
                                    <div class="prf-review-span-2"><span>Account number</span><strong class="prf-review-monospace"><?php echo htmlspecialchars($supplier['bank_account_number']); ?></strong></div>
                                <?php elseif ($supplier['payment_method'] === 'Check'): ?>
                                    <div class="prf-review-span-2"><span>Check payee</span><strong><?php echo htmlspecialchars($supplier['check_payee']); ?></strong></div>
                                <?php endif; ?>

                                <?php if (!empty($supplier['remarks'])): ?>
                                    <div class="prf-review-span-2"><span>Supplier remarks</span><p><?php echo nl2br(htmlspecialchars($supplier['remarks'])); ?></p></div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

                <aside class="prf-review-side-column">
                    <?php if ($can_decide): ?>
                        <form
                            action="actions/pr_handler.php"
                            method="POST"
                            id="prfDecisionForm"
                            class="prf-review-decision-card"
                            data-pr-number="<?php echo htmlspecialchars($pr['pr_number']); ?>"
                            data-decision-stage="<?php echo htmlspecialchars($decision_stage); ?>"
                            novalidate
                        >
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="action" id="prfDecisionAction" value="">
                            <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">

                            <div class="prf-review-decision-heading">
                                <span>Your decision</span>
                                <small><?php echo htmlspecialchars($decision_stage); ?></small>
                            </div>

                            <?php if ($is_sequential): ?>
                                <div class="prf-review-role-note">
                                    <i class="fas fa-user-shield"></i>
                                    Signed in as <?php echo htmlspecialchars($role); ?>
                                </div>
                            <?php endif; ?>

                            <label for="decisionRemarks">
                                Decision remarks
                                <small>Required when rejecting</small>
                            </label>
                            <textarea
                                name="remarks"
                                id="decisionRemarks"
                                rows="3"
                                maxlength="2000"
                                placeholder="Add a concise review note"
                            ></textarea>
                            <div class="prf-review-field-error d-none" id="decisionRemarksError" role="alert"></div>

                            <div class="prf-review-decision-actions">
                                <button
                                    type="button"
                                    class="prf-review-reject-button"
                                    data-prf-decision="reject"
                                    data-action-value="<?php echo $rejection_action; ?>"
                                >
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <button
                                    type="button"
                                    class="prf-review-approve-button"
                                    data-prf-decision="approve"
                                    data-action-value="<?php echo $approval_action; ?>"
                                >
                                    <i class="fas fa-check"></i> <?php echo $approval_button_label; ?>
                                </button>
                            </div>

                            <p class="prf-review-decision-footnote">
                                <?php if ($decision_stage !== 'Owner Approval'): ?>
                                    Approval forwards this PRF to the next reviewer.
                                <?php else: ?>
                                    This is the final approval that makes the PRF official.
                                <?php endif; ?>
                            </p>
                        </form>
                    <?php elseif ($can_convert): ?>
                        <section class="prf-review-side-card is-ready">
                            <div class="prf-review-side-icon"><i class="fas fa-check"></i></div>
                            <span>Official PRF</span>
                            <h3>Ready for PO conversion</h3>
                            <p>The approval route is complete. Procurement can prepare the supplier Purchase Order.</p>
                            <a href="create_po.php?pr_id=<?php echo $pr_id; ?>">
                                Convert to PO <i class="fas fa-arrow-right"></i>
                            </a>
                        </section>
                    <?php elseif ($pr['status'] === 'Rejected'): ?>
                        <section class="prf-review-side-card is-rejected">
                            <div class="prf-review-side-icon"><i class="fas fa-times"></i></div>
                            <span>Decision recorded</span>
                            <h3>PRF rejected</h3>
                            <p><?php echo nl2br(htmlspecialchars($pr['remarks'] ?: 'No rejection reason was recorded.')); ?></p>
                        </section>
                    <?php elseif ($is_sequential && $pr['status'] === 'Pending' && $current_approval): ?>
                        <section class="prf-review-side-card is-waiting">
                            <div class="prf-review-side-icon"><i class="fas fa-hourglass-half"></i></div>
                            <span>Current stage</span>
                            <h3><?php echo htmlspecialchars($pr['current_approval_stage']); ?></h3>
                            <p>This PRF is waiting for a <?php echo htmlspecialchars($current_approval['required_role']); ?> user.</p>
                        </section>
                    <?php elseif ($pr['status'] === 'Approved'): ?>
                        <section class="prf-review-side-card is-ready">
                            <div class="prf-review-side-icon"><i class="fas fa-check"></i></div>
                            <span>Approval complete</span>
                            <h3><?php echo $is_sequential ? 'Officially approved' : 'Approval record incomplete'; ?></h3>
                            <p>
                                <?php if (!empty($pr['final_approver_name'])): ?>
                                    Final approval by <?php echo htmlspecialchars($pr['final_approver_name']); ?> on
                                    <?php echo prf_review_date($pr['final_approved_at'], 'M d, Y · h:i A'); ?>.
                                <?php else: ?>
                                    This request is ready for the next procurement step.
                                <?php endif; ?>
                            </p>
                        </section>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/prf-review.js?v=<?php echo filemtime(__DIR__ . '/assets/js/prf-review.js'); ?>"></script>
</body>
</html>
