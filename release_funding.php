<?php
require 'config/db_connect.php';
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Finance') {
    header('Location: dashboard.php');
    exit();
}

function phase3e_mask_account(?string $value): string
{
    $account = trim((string) $value);
    if ($account === '') {
        return 'Not provided';
    }

    return '•••• ' . substr($account, -4);
}

function phase3e_date(?string $value, string $fallback = 'Not provided'): string
{
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y', strtotime($value));
}

$po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$current_user_id = (int) $_SESSION['user_id'];
$po = null;
$active_assignment = null;
$existing_release = null;
$eligibility_error = '';

if ($po_id > 0) {
    $po_stmt = $conn->prepare(
        "SELECT
            po.*,
            supplier.supplier_name,
            supplier.supplier_reference,
            supplier.supplier_quote_date,
            supplier.payment_method,
            supplier.payment_terms,
            supplier.bank_name,
            supplier.bank_account_name,
            supplier.bank_account_number,
            supplier.check_payee,
            supplier.supplier_quote_original_name,
            supplier.supplier_quote_file_path,
            supplier.supplier_detail_id,
            pr.pr_number,
            client_po.approval_record_id AS client_approval_record_id,
            client_po.actual_client_po_number,
            client_po.proof_file_path AS client_po_file_path
         FROM purchase_orders po
         INNER JOIN purchase_requests pr
            ON pr.pr_id = po.pr_id
         INNER JOIN pr_supplier_details supplier
            ON supplier.supplier_detail_id = po.supplier_detail_id
           AND supplier.pr_id = po.pr_id
           AND supplier.record_status = 'Active'
         INNER JOIN client_approval_records client_po
            ON client_po.approval_record_id =
                pr.client_approval_record_id
           AND client_po.record_type = 'Official Client PO'
           AND client_po.record_status = 'Active'
         WHERE po.po_id = ?
           AND po.source_pr_workflow_version = 2
         LIMIT 1"
    );
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();

    if ($po) {
        $active_assignment = get_active_po_task_assignment($conn, $po_id);

        $existing_release_stmt = $conn->prepare(
            "SELECT fund_release_id
             FROM po_supplier_fund_releases
             WHERE po_id = ?
               AND record_status = 'Active'
             LIMIT 1"
        );
        $existing_release_stmt->bind_param('i', $po_id);
        $existing_release_stmt->execute();
        $existing_release =
            $existing_release_stmt->get_result()->fetch_assoc();

        if ($po['status'] !== 'President-Approved') {
            $eligibility_error =
                'This PO is no longer waiting for Finance funding.';
        } elseif (!is_numeric($po['requested_fund_amount']) ||
            (float) $po['requested_fund_amount'] <= 0) {
            $eligibility_error =
                'This PO does not have a valid approved fund amount.';
        } elseif ($existing_release) {
            $eligibility_error =
                'An active supplier fund-release record already exists.';
        } elseif (!$active_assignment) {
            $eligibility_error =
                'This funding task does not have an active Finance assignment.';
        } elseif ((int) $active_assignment['assigned_to'] !==
            $current_user_id) {
            $eligibility_error =
                'This funding task is assigned to ' .
                $active_assignment['assignee_name'] . '.';
        }
    }
}

$can_release = $po && $eligibility_error === '';
$release_datetime_value = date('Y-m-d\TH:i');
$client_po_file_url = $po && !empty($po['client_po_file_path'])
    ? 'download.php?type=client_approval&record_id=' .
        (int) $po['client_approval_record_id']
    : '';
$supplier_quote_url = $po && !empty($po['supplier_quote_file_path'])
    ? 'download.php?type=supplier_quote&record_id=' .
        (int) $po['supplier_detail_id']
    : '';
$reference_placeholder = $po && $po['payment_method'] === 'Cash'
    ? 'Official receipt number'
    : ($po && $po['payment_method'] === 'Check'
        ? 'Check number'
        : 'Transaction or payment reference');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Release Supplier Funding - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/funding-release.css?v=<?php echo filemtime(__DIR__ . '/assets/css/funding-release.css'); ?>" rel="stylesheet">
</head>
<body class="prf-page funding-release-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell">
            <header class="prf-page-header">
                <a
                    href="<?php echo $po_id > 0 ? 'view_po.php?id=' . $po_id : 'po_list.php?filter=my_tasks'; ?>"
                    class="prf-back-button"
                    aria-label="Back to Purchase Order"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Finance funding release</div>
                    <h2>Record supplier payment</h2>
                    <p>Confirm the approved amount and attach payment evidence before Procurement prepares the delivery request.</p>
                </div>

                <?php if ($can_release): ?>
                    <span class="prf-workflow-chip funding-ready-chip">
                        <i class="fas fa-shield-check"></i>
                        Finance assigned
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
                id="fundingValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$po): ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-coins"></i></div>
                    <h3>No eligible funding record selected</h3>
                    <p>Select an approved Version 2 PO assigned to Finance.</p>
                    <a href="po_list.php?filter=my_tasks" class="btn btn-primary">
                        View my Finance tasks
                    </a>
                </section>

            <?php else: ?>
                <section class="prf-source-card funding-source-card">
                    <div class="prf-source-grid funding-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>Purchase Order</span>
                            <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Approved PRF</span>
                            <strong><?php echo htmlspecialchars($po['pr_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($po['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Official Client PO</span>
                            <strong><?php echo htmlspecialchars($po['actual_client_po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Task owner</span>
                            <strong><?php echo htmlspecialchars((string) ($active_assignment['assignee_name'] ?? 'Unassigned')); ?></strong>
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
                                    Client PO
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card funding-route-card" aria-label="Funding route">
                    <div class="prf-route-label">Current route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step funding-route-complete">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Official PRF</strong><small>Owner approved</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step is-current">
                            <span>2</span>
                            <div><strong>Funding release</strong><small>Current · Finance</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>3</span>
                            <div><strong>Delivery request</strong><small>Next · Procurement</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Funding release is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/po_handler.php"
                        method="POST"
                        enctype="multipart/form-data"
                        id="supplierFundingForm"
                        novalidate
                    >
                        <input type="hidden" name="action" value="mark_funded">
                        <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >
                        <input
                            type="hidden"
                            name="released_amount"
                            value="<?php echo number_format((float) $po['requested_fund_amount'], 2, '.', ''); ?>"
                        >
                        <input
                            type="hidden"
                            name="release_method"
                            value="<?php echo htmlspecialchars($po['payment_method']); ?>"
                        >

                        <div class="funding-layout">
                            <div class="funding-main-column">
                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Approved payee</span>
                                            <h3>Supplier payment instruction</h3>
                                        </div>
                                        <span class="prf-readonly-note">
                                            <i class="fas fa-lock"></i>
                                            Locked from approved PRF
                                        </span>
                                    </div>

                                    <div class="funding-payee-grid">
                                        <div class="funding-detail funding-detail-primary">
                                            <span>Supplier</span>
                                            <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong>
                                        </div>
                                        <div class="funding-detail">
                                            <span>Payment method</span>
                                            <strong><?php echo htmlspecialchars($po['payment_method']); ?></strong>
                                        </div>
                                        <div class="funding-detail">
                                            <span>Supplier reference</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $po['supplier_reference']) !== '' ? $po['supplier_reference'] : 'Not provided'); ?></strong>
                                        </div>
                                        <div class="funding-detail">
                                            <span>Supplier quote date</span>
                                            <strong><?php echo htmlspecialchars(phase3e_date($po['supplier_quote_date'])); ?></strong>
                                        </div>
                                        <div class="funding-detail">
                                            <span>Payment terms</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $po['payment_terms']) !== '' ? $po['payment_terms'] : 'Not provided'); ?></strong>
                                        </div>

                                        <?php if ($po['payment_method'] === 'Bank Transfer'): ?>
                                            <div class="funding-detail">
                                                <span>Bank</span>
                                                <strong><?php echo htmlspecialchars((string) $po['bank_name']); ?></strong>
                                            </div>
                                            <div class="funding-detail">
                                                <span>Account name</span>
                                                <strong><?php echo htmlspecialchars((string) $po['bank_account_name']); ?></strong>
                                            </div>
                                            <div class="funding-detail">
                                                <span>Account number</span>
                                                <strong class="funding-account-value">
                                                    <?php echo htmlspecialchars(phase3e_mask_account($po['bank_account_number'])); ?>
                                                </strong>
                                            </div>
                                        <?php elseif ($po['payment_method'] === 'Check'): ?>
                                            <div class="funding-detail">
                                                <span>Check payee</span>
                                                <strong><?php echo htmlspecialchars((string) $po['check_payee']); ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="funding-detail funding-detail-action">
                                            <span>Supplier quotation</span>
                                            <?php if ($supplier_quote_url !== ''): ?>
                                                <a
                                                    href="<?php echo htmlspecialchars($supplier_quote_url); ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="prf-document-link"
                                                >
                                                    <i class="fas fa-paperclip"></i>
                                                    View quotation
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
                                            <span class="prf-section-kicker">Release evidence</span>
                                            <h3>Payment transaction details</h3>
                                        </div>
                                        <span class="prf-required-note"><span>*</span> Required fields</span>
                                    </div>

                                    <div class="prf-form-grid funding-form-grid">
                                        <div class="prf-field">
                                            <label for="fundingReference">Payment reference <span>*</span></label>
                                            <input
                                                type="text"
                                                name="reference_number"
                                                id="fundingReference"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="<?php echo htmlspecialchars($reference_placeholder); ?>"
                                                autocomplete="off"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="fundingReleasedAt">Release date and time <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="released_at"
                                                id="fundingReleasedAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($release_datetime_value); ?>"
                                                max="<?php echo htmlspecialchars($release_datetime_value); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="fundingProof">Payment proof <span>*</span></label>
                                            <label class="prf-file-control" for="fundingProof">
                                                <i class="fas fa-paperclip"></i>
                                                <span data-funding-file-name>Select PDF or image</span>
                                                <strong>Browse</strong>
                                            </label>
                                            <input
                                                type="file"
                                                name="funding_proof"
                                                id="fundingProof"
                                                class="visually-hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                data-funding-file
                                                required
                                            >
                                            <small class="prf-help-text">Bank confirmation, deposit slip, check voucher, or receipt · maximum 10 MB</small>
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="fundingRemarks">Finance remarks <small>Optional</small></label>
                                            <textarea
                                                name="funding_remarks"
                                                id="fundingRemarks"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Add a concise note for Procurement"
                                            ></textarea>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card funding-summary-card" aria-label="Funding release summary">
                                <div class="prf-summary-heading">
                                    <span>Release summary</span>
                                    <small>PRF-approved</small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>Client selling amount</span>
                                    <strong>₱<?php echo number_format((float) $po['amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Cost of goods</span>
                                    <strong>₱<?php echo number_format((float) $po['cost_of_goods_amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Other expense</span>
                                    <strong>₱<?php echo number_format((float) $po['other_expense_amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Amount to release</span>
                                    <strong>₱<?php echo number_format((float) $po['requested_fund_amount'], 2); ?></strong>
                                </div>

                                <div class="funding-method-panel">
                                    <span>Approved method</span>
                                    <strong><?php echo htmlspecialchars($po['payment_method']); ?></strong>
                                    <small>Changes require correction of the approved PRF.</small>
                                </div>

                                <div class="funding-next-step">
                                    <i class="fas fa-clipboard-check"></i>
                                    <div>
                                        <strong>Next: Procurement delivery request</strong>
                                        <span>Procurement confirms supplier readiness before Supply Chain reviews the logistics schedule.</span>
                                    </div>
                                </div>

                                <label class="funding-confirmation" for="fundingConfirmation">
                                    <input
                                        type="checkbox"
                                        id="fundingConfirmation"
                                        data-funding-confirm
                                        required
                                    >
                                    <span>I confirm that the approved amount was released using the recorded supplier payment method.</span>
                                </label>

                                <button type="submit" class="prf-submit-button" data-funding-submit>
                                    <span>Save proof &amp; mark Funded</span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </aside>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/funding-release.js?v=<?php echo filemtime(__DIR__ . '/assets/js/funding-release.js'); ?>"></script>
</body>
</html>

