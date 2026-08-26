<?php
require 'config/db_connect.php';
require 'config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Procurement') {
    header('Location: dashboard.php');
    exit();
}

function phase3c_display_date(?string $value, string $fallback = 'Not provided'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($value));
}

function phase3c_display_datetime(?string $value, string $fallback = 'Not recorded'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y · h:i A', strtotime($value));
}

function phase3c_mask_account(?string $value): string
{
    $account = trim((string) $value);
    if ($account === '') {
        return 'Not provided';
    }

    $visible = substr($account, -4);
    return '•••• ' . $visible;
}

$pr_id = isset($_GET['pr_id']) ? (int) $_GET['pr_id'] : 0;
$pr = null;
$pr_items = [];
$approval_records = [];
$approval_map = [];
$eligibility_error = '';

if ($pr_id > 0) {
    $pr_stmt = $conn->prepare(
        "SELECT
            request.*,
            quotation.quotation_number,
            client_po.internal_reference AS client_po_internal_reference,
            client_po.actual_client_po_number,
            client_po.client_po_date,
            client_po.final_approval_date AS client_final_approval_date,
            client_po.proof_original_name AS client_po_file_name,
            client_po.proof_file_path AS client_po_file_path,
            supplier.supplier_detail_id,
            supplier.supplier_name,
            supplier.supplier_reference,
            supplier.supplier_quote_date,
            supplier.quoted_cost_amount,
            supplier.payment_method,
            supplier.payment_terms,
            supplier.bank_name,
            supplier.bank_account_name,
            supplier.bank_account_number,
            supplier.check_payee,
            supplier.supplier_quote_original_name,
            supplier.supplier_quote_file_path,
            supplier.remarks AS supplier_remarks,
            final_approver.full_name AS final_approver_name
         FROM purchase_requests request
         INNER JOIN quotations quotation
            ON quotation.quotation_id = request.quotation_id
         INNER JOIN client_approval_records client_po
            ON client_po.approval_record_id = request.client_approval_record_id
           AND client_po.quotation_id = request.quotation_id
           AND client_po.record_type = 'Official Client PO'
           AND client_po.record_status = 'Active'
         INNER JOIN pr_supplier_details supplier
            ON supplier.pr_id = request.pr_id
           AND supplier.record_status = 'Active'
         LEFT JOIN users final_approver
            ON final_approver.user_id = request.final_approved_by
         WHERE request.pr_id = ?
           AND request.status = 'Approved'
           AND request.workflow_version = 2
           AND request.current_approval_stage = 'Official Approved'
           AND request.final_approved_by IS NOT NULL
           AND request.final_approved_at IS NOT NULL
           AND client_po.actual_client_po_number IS NOT NULL
           AND client_po.actual_client_po_number <> ''
           AND client_po.final_approval_date IS NOT NULL
           AND NOT EXISTS (
                SELECT 1
                FROM purchase_orders existing_po
                WHERE existing_po.pr_id = request.pr_id
           )
         LIMIT 1"
    );
    $pr_stmt->bind_param('i', $pr_id);
    $pr_stmt->execute();
    $pr = $pr_stmt->get_result()->fetch_assoc();

    if ($pr) {
        $items_stmt = $conn->prepare(
            "SELECT
                item_id,
                category,
                brand,
                item_name,
                specifications,
                quantity,
                unit_price,
                unit_cost,
                total_price,
                total_cost,
                line_profit_amount
             FROM pr_items
             WHERE pr_id = ?
             ORDER BY item_id"
        );
        $items_stmt->bind_param('i', $pr_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        while ($item = $items_result->fetch_assoc()) {
            $pr_items[] = $item;
        }

        $approval_stmt = $conn->prepare(
            "SELECT
                approval.approval_cycle,
                approval.stage_sequence,
                approval.approval_stage,
                approval.required_role,
                approval.decision,
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
        $approval_stmt->bind_param('ii', $pr_id, $pr_id);
        $approval_stmt->execute();
        $approval_result = $approval_stmt->get_result();

        while ($approval = $approval_result->fetch_assoc()) {
            $approval_records[] = $approval;
            $approval_map[$approval['approval_stage']] = $approval;
        }

        $required_approvals = [
            'GM Review' => ['role' => 'GM', 'sequence' => 1],
            'Finance Review' => ['role' => 'Finance', 'sequence' => 2],
            'Owner Approval' => ['role' => 'President', 'sequence' => 3],
        ];

        if (count($approval_records) !== count($required_approvals)) {
            $eligibility_error = 'The latest PRF approval cycle is incomplete.';
        }

        foreach ($required_approvals as $stage => $requirement) {
            $approval = $approval_map[$stage] ?? null;
            if (
                !$approval ||
                $approval['required_role'] !== $requirement['role'] ||
                (int) $approval['stage_sequence'] !== $requirement['sequence'] ||
                $approval['decision'] !== 'Approved' ||
                empty($approval['acted_by_name']) ||
                empty($approval['acted_at'])
            ) {
                $eligibility_error =
                    'The PRF must contain completed GM, Finance, and Owner approvals.';
                break;
            }
        }

        if (empty($pr_items)) {
            $eligibility_error = 'The approved PRF does not contain any items.';
        }
    }
}

$can_create_po = $pr && $eligibility_error === '';
$display_po_number = 'PO-' . date('Y') . '-[Auto]';
$client_po_file_url = $pr && !empty($pr['client_po_file_path'])
    ? 'download.php?type=client_approval&record_id=' .
        (int) $pr['client_approval_record_id']
    : '';
$supplier_quote_url = $pr && !empty($pr['supplier_quote_file_path'])
    ? 'download.php?type=supplier_quote&record_id=' .
        (int) $pr['supplier_detail_id']
    : '';
$gross_profit_is_negative = $pr && (float) $pr['gross_profit_amount'] < 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create Purchase Order - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/po-conversion.css?v=<?php echo filemtime(__DIR__ . '/assets/css/po-conversion.css'); ?>" rel="stylesheet">
</head>
<body class="page-create-po prf-page po-conversion-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell">
            <header class="prf-page-header">
                <a
                    href="pr_list.php?queue=mine"
                    class="prf-back-button"
                    aria-label="Back to approved Purchase Requests"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Purchase order conversion</div>
                    <h2>Create PO from approved PRF</h2>
                    <p>Verify the locked approval, supplier, and financial snapshot before funding.</p>
                </div>

                <?php if ($can_create_po): ?>
                    <span class="prf-workflow-chip">
                        <i class="fas fa-lock"></i>
                        Approved PRF handoff
                    </span>
                <?php endif; ?>
            </header>

            <?php if (isset($_GET['error']) && trim((string) $_GET['error']) !== ''): ?>
                <div class="prf-alert prf-alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars((string) $_GET['error']); ?></span>
                </div>
            <?php endif; ?>

            <div
                id="poValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$pr): ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-file-circle-check"></i></div>
                    <h3>No eligible approved PRF selected</h3>
                    <p>
                        Select a Version 2 PRF that completed GM, Finance, and Owner approval
                        and has not yet been converted to a PO.
                    </p>
                    <a href="pr_list.php?queue=mine" class="btn btn-primary">
                        View PRFs ready for PO
                    </a>
                </section>

            <?php else: ?>
                <section class="prf-source-card po-source-card">
                    <div class="prf-source-grid po-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>New PO number</span>
                            <strong><?php echo htmlspecialchars($display_po_number); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Approved PRF</span>
                            <strong><?php echo htmlspecialchars($pr['pr_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($pr['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Quotation</span>
                            <strong><?php echo htmlspecialchars($pr['quotation_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Official Client PO</span>
                            <strong><?php echo htmlspecialchars($pr['actual_client_po_number']); ?></strong>
                        </div>
                        <div class="prf-source-action">
                            <?php if ($client_po_file_url !== ''): ?>
                                <a
                                    href="<?php echo htmlspecialchars($client_po_file_url); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="prf-document-link"
                                >
                                    <i class="fas fa-paperclip"></i>
                                    View Client PO
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card po-approval-route" aria-label="Completed PRF approval and next PO step">
                    <div class="prf-route-label">Verified route</div>
                    <div class="prf-route-steps">
                        <?php
                        $route_stages = [
                            'GM Review' => 'GM review',
                            'Finance Review' => 'Finance review',
                            'Owner Approval' => 'Owner approval',
                        ];
                        ?>
                        <?php foreach ($route_stages as $stage_key => $stage_label): ?>
                            <?php $route_approval = $approval_map[$stage_key] ?? null; ?>
                            <div class="prf-route-step po-route-complete">
                                <span><i class="fas fa-check"></i></span>
                                <div>
                                    <strong><?php echo htmlspecialchars($stage_label); ?></strong>
                                    <small>
                                        <?php echo htmlspecialchars((string) ($route_approval['acted_by_name'] ?? 'Missing')); ?>
                                    </small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <?php endforeach; ?>
                        <div class="prf-route-step is-current po-route-next">
                            <span>4</span>
                            <div><strong>Funding release</strong><small>Next · Finance</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>PO creation is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/po_handler.php"
                        method="POST"
                        enctype="multipart/form-data"
                        id="approvedPrfPoForm"
                        novalidate
                    >
                        <input type="hidden" name="action" value="create">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >
                        <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">

                        <div class="po-conversion-layout">
                            <div class="po-main-column">
                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Approved order</span>
                                            <h3>Locked PRF items</h3>
                                        </div>
                                        <span class="prf-readonly-note">
                                            <i class="fas fa-lock"></i>
                                            Values cannot be edited
                                        </span>
                                    </div>

                                    <div class="po-item-table-wrap">
                                        <table class="po-item-table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Category / brand</th>
                                                    <th class="text-center">Qty</th>
                                                    <th class="text-end">Selling / unit</th>
                                                    <th class="text-end">Cost / unit</th>
                                                    <th class="text-end">Selling total</th>
                                                    <th class="text-end">Cost total</th>
                                                    <th class="text-end">Line profit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pr_items as $item): ?>
                                                    <tr>
                                                        <td data-label="Item">
                                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                            <?php if (trim((string) $item['specifications']) !== ''): ?>
                                                                <small><?php echo nl2br(htmlspecialchars((string) $item['specifications'])); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td data-label="Category / brand">
                                                            <?php echo htmlspecialchars(trim((string) $item['category']) !== '' ? $item['category'] : 'Uncategorized'); ?>
                                                            <small><?php echo htmlspecialchars(trim((string) $item['brand']) !== '' ? $item['brand'] : 'No brand'); ?></small>
                                                        </td>
                                                        <td data-label="Qty" class="text-center po-table-quantity">
                                                            <?php echo (int) $item['quantity']; ?>
                                                        </td>
                                                        <td data-label="Selling / unit" class="text-end po-table-money">
                                                            ₱<?php echo number_format((float) $item['unit_price'], 2); ?>
                                                        </td>
                                                        <td data-label="Cost / unit" class="text-end po-table-money">
                                                            ₱<?php echo number_format((float) $item['unit_cost'], 2); ?>
                                                        </td>
                                                        <td data-label="Selling total" class="text-end po-table-money">
                                                            ₱<?php echo number_format((float) $item['total_price'], 2); ?>
                                                        </td>
                                                        <td data-label="Cost total" class="text-end po-table-money">
                                                            ₱<?php echo number_format((float) $item['total_cost'], 2); ?>
                                                        </td>
                                                        <td
                                                            data-label="Line profit"
                                                            class="text-end po-table-profit<?php echo (float) $item['line_profit_amount'] < 0 ? ' is-negative' : ''; ?>"
                                                        >
                                                            ₱<?php echo number_format((float) $item['line_profit_amount'], 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Supplier handoff</span>
                                            <h3>Approved supplier and payment details</h3>
                                        </div>
                                        <span class="prf-readonly-note">
                                            <i class="fas fa-check-circle"></i>
                                            Finance verified
                                        </span>
                                    </div>

                                    <div class="po-supplier-grid">
                                        <div class="po-detail po-detail-primary">
                                            <span>Supplier</span>
                                            <strong><?php echo htmlspecialchars($pr['supplier_name']); ?></strong>
                                        </div>
                                        <div class="po-detail">
                                            <span>Supplier reference</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $pr['supplier_reference']) !== '' ? $pr['supplier_reference'] : 'Not provided'); ?></strong>
                                        </div>
                                        <div class="po-detail">
                                            <span>Supplier quote date</span>
                                            <strong><?php echo htmlspecialchars(phase3c_display_date($pr['supplier_quote_date'])); ?></strong>
                                        </div>
                                        <div class="po-detail">
                                            <span>Payment method</span>
                                            <strong><?php echo htmlspecialchars($pr['payment_method']); ?></strong>
                                        </div>
                                        <div class="po-detail">
                                            <span>Payment terms</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $pr['payment_terms']) !== '' ? $pr['payment_terms'] : 'Not provided'); ?></strong>
                                        </div>

                                        <?php if ($pr['payment_method'] === 'Bank Transfer'): ?>
                                            <div class="po-detail">
                                                <span>Bank</span>
                                                <strong><?php echo htmlspecialchars((string) $pr['bank_name']); ?></strong>
                                            </div>
                                            <div class="po-detail">
                                                <span>Account name</span>
                                                <strong><?php echo htmlspecialchars((string) $pr['bank_account_name']); ?></strong>
                                            </div>
                                            <div class="po-detail">
                                                <span>Account number</span>
                                                <strong class="po-sensitive-value">
                                                    <?php echo htmlspecialchars(phase3c_mask_account($pr['bank_account_number'])); ?>
                                                </strong>
                                            </div>
                                        <?php elseif ($pr['payment_method'] === 'Check'): ?>
                                            <div class="po-detail">
                                                <span>Check payee</span>
                                                <strong><?php echo htmlspecialchars((string) $pr['check_payee']); ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="po-detail po-detail-wide">
                                            <span>Supplier remarks</span>
                                            <strong><?php echo nl2br(htmlspecialchars(trim((string) $pr['supplier_remarks']) !== '' ? $pr['supplier_remarks'] : 'No supplier remarks.')); ?></strong>
                                        </div>

                                        <div class="po-detail po-detail-action">
                                            <span>Supplier quotation</span>
                                            <?php if ($supplier_quote_url !== ''): ?>
                                                <a
                                                    href="<?php echo htmlspecialchars($supplier_quote_url); ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="prf-document-link"
                                                >
                                                    <i class="fas fa-paperclip"></i>
                                                    View supplier quote
                                                </a>
                                            <?php else: ?>
                                                <strong>Not attached</strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Additional evidence</span>
                                            <h3>Optional PO supporting document</h3>
                                        </div>
                                        <span class="prf-readonly-note">Optional</span>
                                    </div>

                                    <div class="po-document-panel">
                                        <div class="po-document-copy">
                                            <i class="fas fa-file-shield"></i>
                                            <div>
                                                <strong>Official Client PO is already linked</strong>
                                                <span>Attach only an additional PDF or image when Procurement needs it.</span>
                                            </div>
                                        </div>

                                        <div class="prf-field po-file-field">
                                            <label class="prf-file-control" for="poSupportingDocument">
                                                <i class="fas fa-paperclip"></i>
                                                <span data-po-file-name>Select supporting file</span>
                                                <strong>Browse</strong>
                                            </label>
                                            <input
                                                type="file"
                                                name="po_document"
                                                id="poSupportingDocument"
                                                class="visually-hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                data-po-file
                                            >
                                            <small class="prf-help-text">PDF, JPG, or PNG · maximum 10 MB</small>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card po-summary-card" aria-label="Approved PO financial snapshot">
                                <div class="prf-summary-heading">
                                    <span>Approved snapshot</span>
                                    <small>Read-only</small>
                                </div>

                                <div class="po-summary-reference">
                                    <span>PRF final approval</span>
                                    <strong><?php echo htmlspecialchars(phase3c_display_datetime($pr['final_approved_at'])); ?></strong>
                                    <small><?php echo htmlspecialchars((string) $pr['final_approver_name']); ?></small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>Client selling amount</span>
                                    <strong>₱<?php echo number_format((float) $pr['amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Cost of goods</span>
                                    <strong>₱<?php echo number_format((float) $pr['cost_of_goods_amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Other expense</span>
                                    <strong>₱<?php echo number_format((float) $pr['other_expense_amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Funds requested</span>
                                    <strong>₱<?php echo number_format((float) $pr['requested_fund_amount'], 2); ?></strong>
                                </div>

                                <div class="prf-profit-panel<?php echo $gross_profit_is_negative ? ' is-negative' : ''; ?>">
                                    <span>Approved gross profit</span>
                                    <strong>₱<?php echo number_format((float) $pr['gross_profit_amount'], 2); ?></strong>
                                    <small><?php echo number_format((float) $pr['gross_margin_percent'], 2); ?>% margin</small>
                                </div>

                                <div class="po-next-step">
                                    <i class="fas fa-coins"></i>
                                    <div>
                                        <strong>Next: Finance funding</strong>
                                        <span>No duplicate GM–Finance–Owner approval.</span>
                                    </div>
                                </div>

                                <label class="po-confirmation" for="poConfirmApprovedData">
                                    <input
                                        type="checkbox"
                                        id="poConfirmApprovedData"
                                        data-po-confirm
                                        required
                                    >
                                    <span>I verified the locked PRF, supplier, and Client PO references.</span>
                                </label>

                                <button type="submit" class="prf-submit-button" data-po-submit>
                                    <span>Create PO &amp; send to Finance</span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </aside>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/po-conversion.js?v=<?php echo filemtime(__DIR__ . '/assets/js/po-conversion.js'); ?>"></script>
</body>
</html>
