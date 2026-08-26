<?php
require 'config/db_connect.php';
require 'config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales Staff') {
    header('Location: dashboard.php');
    exit();
}

$quotation_id = isset($_GET['quotation_id']) ? (int) $_GET['quotation_id'] : 0;
$quotation = null;
$quotation_items = [];
$official_client_po = null;

if ($quotation_id > 0) {
    $quotation_stmt = $conn->prepare(
        "SELECT *
         FROM quotations
         WHERE quotation_id = ?
           AND status = 'PO Received'"
    );
    $quotation_stmt->bind_param('i', $quotation_id);
    $quotation_stmt->execute();
    $quotation = $quotation_stmt->get_result()->fetch_assoc();

    if ($quotation) {
        $item_stmt = $conn->prepare(
            "SELECT *
             FROM quotation_items
             WHERE quotation_id = ?
             ORDER BY item_id"
        );
        $item_stmt->bind_param('i', $quotation_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();

        while ($item_row = $item_result->fetch_assoc()) {
            $quotation_items[] = $item_row;
        }

        $official_po_stmt = $conn->prepare(
            "SELECT
                approval_record_id,
                internal_reference,
                actual_client_po_number,
                client_po_date,
                final_approval_date,
                proof_original_name,
                proof_file_path,
                recorded_at
             FROM client_approval_records
             WHERE quotation_id = ?
               AND record_type = 'Official Client PO'
               AND record_status = 'Active'
             ORDER BY final_approval_date DESC, recorded_at DESC, approval_record_id DESC
             LIMIT 1"
        );
        $official_po_stmt->bind_param('i', $quotation_id);
        $official_po_stmt->execute();
        $official_client_po = $official_po_stmt->get_result()->fetch_assoc();
    }
}

$is_structured_prf = $quotation &&
    $official_client_po &&
    !empty($official_client_po['actual_client_po_number']) &&
    !empty($official_client_po['client_po_date']) &&
    !empty($official_client_po['final_approval_date']) &&
    !empty($official_client_po['proof_file_path']);

$year = date('Y');
$pr_prefix = 'PR-' . $year . '-';
$sequence_stmt = $conn->query(
    "SELECT MAX(CAST(SUBSTRING_INDEX(pr_number, '-', -1) AS UNSIGNED)) AS latest_sequence
     FROM purchase_requests
     WHERE pr_number REGEXP '^PR-[0-9]{4,6}-[0-9]+$'"
);
$sequence_row = $sequence_stmt ? $sequence_stmt->fetch_assoc() : null;
$next_pr_number = ((int) ($sequence_row['latest_sequence'] ?? 0)) + 1;
$display_pr_number = $pr_prefix . str_pad((string) $next_pr_number, 4, '0', STR_PAD_LEFT);

$category_map = [
    '01' => '1 - Hardware',
    '02' => '2 - CCTVs',
    '03' => '3 - Peripherals',
    '04' => '4 - Office Supplies',
    '05' => '5 - WIFI / LAN',
    '06' => '6 - Printers',
];

$official_po_file_url = $is_structured_prf
    ? 'download.php?type=client_approval&record_id=' .
        (int) $official_client_po['approval_record_id']
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Prepare Purchase Request Form - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
</head>
<body class="page-create-pr prf-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell">
            <header class="prf-page-header">
                <a
                    href="quotations_list.php"
                    class="prf-back-button"
                    aria-label="Back to quotations"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Purchase request form</div>
                    <h2>Prepare PRF</h2>
                    <p>Review the client order, enter supplier costs, then submit it for approval.</p>
                </div>

                <?php if ($is_structured_prf): ?>
                    <span class="prf-workflow-chip">
                        <i class="fas fa-route"></i>
                        Sequential approval
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
                id="prValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$quotation): ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <h3>No eligible quotation selected</h3>
                    <p>Select a quotation with a received Client PO that has not yet been converted.</p>
                    <a href="quotations_list.php" class="btn btn-primary">
                        Return to quotation tracker
                    </a>
                </section>

            <?php elseif ($is_structured_prf): ?>
                <section class="prf-source-card">
                    <div class="prf-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>PR number</span>
                            <strong><?php echo htmlspecialchars($display_pr_number); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($quotation['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Quotation</span>
                            <strong><?php echo htmlspecialchars($quotation['quotation_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Official Client PO</span>
                            <strong><?php echo htmlspecialchars($official_client_po['actual_client_po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Final client approval</span>
                            <strong><?php echo date('M d, Y', strtotime($official_client_po['final_approval_date'])); ?></strong>
                        </div>
                        <div class="prf-source-action">
                            <a
                                href="<?php echo htmlspecialchars($official_po_file_url); ?>"
                                target="_blank"
                                rel="noopener"
                                class="prf-document-link"
                            >
                                <i class="fas fa-paperclip"></i>
                                View attached PO
                            </a>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card" aria-label="PRF approval route">
                    <div class="prf-route-label">Approval route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step is-current">
                            <span>1</span>
                            <div><strong>GM review</strong><small>Commercial check</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>2</span>
                            <div><strong>Finance review</strong><small>Funds & supplier</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>3</span>
                            <div><strong>Owner approval</strong><small>Final signatory</small></div>
                        </div>
                    </div>
                </section>

                <form
                    action="actions/pr_handler.php"
                    method="POST"
                    enctype="multipart/form-data"
                    id="prfV2Form"
                    novalidate
                >
                    <input type="hidden" name="action" value="create_pr_v2">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                    >
                    <input type="hidden" name="quotation_id" value="<?php echo $quotation_id; ?>">
                    <input type="hidden" name="pr_number" value="<?php echo htmlspecialchars($display_pr_number); ?>">

                    <div class="prf-layout">
                        <div class="prf-main-column">
                            <section class="prf-card">
                                <div class="prf-card-header">
                                    <div>
                                        <span class="prf-section-kicker">Cost worksheet</span>
                                        <h3>Quoted items and supplier cost</h3>
                                    </div>
                                    <span class="prf-readonly-note">
                                        <i class="fas fa-lock"></i>
                                        Selling prices are locked
                                    </span>
                                </div>

                                <div class="prf-cost-table-wrap">
                                    <table class="prf-cost-table" id="prfCostTable">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Selling / unit</th>
                                                <th>Supplier cost / unit</th>
                                                <th class="text-end">Cost total</th>
                                                <th class="text-end">Line profit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($quotation_items as $item): ?>
                                                <?php
                                                $category_code = (string) ($item['category'] ?? '');
                                                $category_label = $category_map[$category_code] ?? $category_code;
                                                ?>
                                                <tr
                                                    data-prf-cost-row
                                                    data-quantity="<?php echo (int) $item['quantity']; ?>"
                                                    data-selling-total="<?php echo htmlspecialchars((string) $item['total_price']); ?>"
                                                >
                                                    <td data-label="Item">
                                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                        <small>
                                                            <?php echo htmlspecialchars($category_label); ?>
                                                            <?php if (!empty($item['brand'])): ?>
                                                                · <?php echo htmlspecialchars($item['brand']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                        <?php if (!empty($item['specifications'])): ?>
                                                            <span><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="Qty" class="text-center prf-quantity">
                                                        <?php echo (int) $item['quantity']; ?>
                                                    </td>
                                                    <td data-label="Selling / unit" class="text-end prf-money-locked">
                                                        ₱<?php echo number_format((float) $item['unit_price'], 2); ?>
                                                    </td>
                                                    <td data-label="Supplier cost / unit">
                                                        <div class="prf-money-input">
                                                            <span>₱</span>
                                                            <input
                                                                type="number"
                                                                name="item_costs[<?php echo (int) $item['item_id']; ?>]"
                                                                class="form-control"
                                                                min="0.01"
                                                                step="0.01"
                                                                inputmode="decimal"
                                                                placeholder="0.00"
                                                                required
                                                                data-prf-unit-cost
                                                                aria-label="Supplier unit cost for <?php echo htmlspecialchars($item['item_name']); ?>"
                                                            >
                                                        </div>
                                                    </td>
                                                    <td data-label="Cost total" class="text-end" data-prf-cost-total>₱0.00</td>
                                                    <td data-label="Line profit" class="text-end prf-line-profit" data-prf-line-profit>
                                                        ₱<?php echo number_format((float) $item['total_price'], 2); ?>
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
                                        <span class="prf-section-kicker">Supplier record</span>
                                        <h3>Supplier and payment details</h3>
                                    </div>
                                    <span class="prf-required-note"><span>*</span> Required fields</span>
                                </div>

                                <div class="prf-form-grid">
                                    <div class="prf-field prf-span-2">
                                        <label for="supplierName">Supplier name <span>*</span></label>
                                        <input
                                            type="text"
                                            name="supplier_name"
                                            id="supplierName"
                                            class="form-control"
                                            maxlength="150"
                                            autocomplete="organization"
                                            required
                                        >
                                    </div>

                                    <div class="prf-field">
                                        <label for="supplierReference">Supplier reference <small>Optional</small></label>
                                        <input
                                            type="text"
                                            name="supplier_reference"
                                            id="supplierReference"
                                            class="form-control"
                                            maxlength="100"
                                            placeholder="Quote or invoice no."
                                        >
                                    </div>

                                    <div class="prf-field">
                                        <label for="supplierQuoteDate">Quotation date <small>Optional</small></label>
                                        <input
                                            type="date"
                                            name="supplier_quote_date"
                                            id="supplierQuoteDate"
                                            class="form-control"
                                            max="<?php echo date('Y-m-d'); ?>"
                                        >
                                    </div>

                                    <div class="prf-field">
                                        <label for="paymentMethod">Payment method <span>*</span></label>
                                        <select
                                            name="payment_method"
                                            id="paymentMethod"
                                            class="form-select"
                                            required
                                        >
                                            <option value="" selected disabled>Select method</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="Check">Check</option>
                                            <option value="Cash on Delivery">Cash on Delivery</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>

                                    <div class="prf-field">
                                        <label for="paymentTerms">Supplier payment terms <small>Optional</small></label>
                                        <input
                                            type="text"
                                            name="payment_terms"
                                            id="paymentTerms"
                                            class="form-control"
                                            maxlength="150"
                                            placeholder="e.g. Full payment before pickup"
                                        >
                                    </div>

                                    <div class="prf-conditional-group prf-span-2" data-payment-panel="Bank Transfer" hidden>
                                        <div class="prf-conditional-heading">
                                            <i class="fas fa-university"></i> Bank transfer details
                                        </div>
                                        <div class="prf-form-grid">
                                            <div class="prf-field">
                                                <label for="bankName">Bank name <span>*</span></label>
                                                <input type="text" name="bank_name" id="bankName" class="form-control" maxlength="150">
                                            </div>
                                            <div class="prf-field">
                                                <label for="bankAccountName">Account name <span>*</span></label>
                                                <input type="text" name="bank_account_name" id="bankAccountName" class="form-control" maxlength="150">
                                            </div>
                                            <div class="prf-field prf-span-2">
                                                <label for="bankAccountNumber">Account number <span>*</span></label>
                                                <input type="text" name="bank_account_number" id="bankAccountNumber" class="form-control" maxlength="100" inputmode="numeric">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="prf-conditional-group prf-span-2" data-payment-panel="Check" hidden>
                                        <div class="prf-conditional-heading">
                                            <i class="fas fa-money-check-alt"></i> Check details
                                        </div>
                                        <div class="prf-field">
                                            <label for="checkPayee">Check payee <span>*</span></label>
                                            <input type="text" name="check_payee" id="checkPayee" class="form-control" maxlength="150">
                                        </div>
                                    </div>

                                    <div class="prf-field">
                                        <label for="otherExpense">Other approved expense <small>Optional</small></label>
                                        <div class="prf-money-input">
                                            <span>₱</span>
                                            <input
                                                type="number"
                                                name="other_expense_amount"
                                                id="otherExpense"
                                                class="form-control"
                                                min="0"
                                                step="0.01"
                                                inputmode="decimal"
                                                value="0.00"
                                            >
                                        </div>
                                    </div>

                                    <div class="prf-field">
                                        <label for="supplierQuoteFile">Supplier quotation <small>Optional</small></label>
                                        <label class="prf-file-control" for="supplierQuoteFile">
                                            <i class="fas fa-paperclip"></i>
                                            <span data-prf-file-name>Select PDF or image</span>
                                            <strong>Browse</strong>
                                        </label>
                                        <input
                                            type="file"
                                            name="supplier_quote_file"
                                            id="supplierQuoteFile"
                                            class="visually-hidden"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            data-prf-file
                                        >
                                        <small class="prf-help-text">PDF, JPG, or PNG · maximum 10 MB</small>
                                    </div>

                                    <div class="prf-field prf-span-2">
                                        <label for="supplierRemarks">Remarks</label>
                                        <textarea
                                            name="supplier_remarks"
                                            id="supplierRemarks"
                                            class="form-control"
                                            rows="2"
                                            maxlength="2000"
                                            placeholder="Add a concise note for GM and Finance"
                                        ></textarea>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <aside class="prf-summary-card" aria-label="PRF financial summary">
                            <div class="prf-summary-heading">
                                <span>Financial summary</span>
                                <small>Auto-calculated</small>
                            </div>

                            <div class="prf-summary-row">
                                <span>Client selling amount</span>
                                <strong data-prf-selling-total>₱<?php echo number_format((float) $quotation['amount'], 2); ?></strong>
                            </div>
                            <div class="prf-summary-row">
                                <span>Cost of goods</span>
                                <strong data-prf-cogs>₱0.00</strong>
                            </div>
                            <div class="prf-summary-row">
                                <span>Other expense</span>
                                <strong data-prf-other-expense>₱0.00</strong>
                            </div>
                            <div class="prf-summary-row prf-summary-request">
                                <span>Funds requested</span>
                                <strong data-prf-requested-fund>₱0.00</strong>
                            </div>

                            <div class="prf-profit-panel" data-prf-profit-panel>
                                <span>Projected gross profit</span>
                                <strong data-prf-gross-profit>
                                    ₱<?php echo number_format((float) $quotation['amount'], 2); ?>
                                </strong>
                                <small data-prf-margin>100.00% margin</small>
                            </div>

                            <div class="prf-summary-note">
                                <i class="fas fa-info-circle"></i>
                                Finance will verify supplier and payment information after GM review.
                            </div>

                            <button type="submit" class="prf-submit-button" data-prf-submit>
                                <span>Submit to GM</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </aside>
                    </div>
                </form>

            <?php else: ?>
                <section class="prf-alert prf-alert-warning" role="status">
                    <i class="fas fa-history"></i>
                    <div>
                        <strong>Legacy Client PO record</strong>
                        <span>
                            This quotation was created before structured Client PO records.
                            It will continue through the existing legacy PR workflow.
                        </span>
                    </div>
                </section>

                <form action="actions/pr_handler.php" method="POST" id="legacyPrForm">
                    <input type="hidden" name="action" value="create_pr">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                    <input type="hidden" name="quotation_id" id="prQuotationId" value="<?php echo $quotation_id; ?>">
                    <input type="hidden" name="amount" id="prAmount" value="<?php echo htmlspecialchars((string) $quotation['amount']); ?>">
                    <input type="hidden" name="pr_number" id="prNumber" value="<?php echo htmlspecialchars($display_pr_number); ?>">
                    <input type="hidden" name="client_name" id="prClientName" value="<?php echo htmlspecialchars($quotation['client_name']); ?>">

                    <section class="prf-card">
                        <div class="prf-card-header">
                            <div>
                                <span class="prf-section-kicker">Legacy request</span>
                                <h3><?php echo htmlspecialchars($display_pr_number); ?></h3>
                            </div>
                            <span class="prf-workflow-chip is-legacy">Legacy workflow</span>
                        </div>

                        <div class="prf-legacy-meta">
                            <div><span>Client</span><strong><?php echo htmlspecialchars($quotation['client_name']); ?></strong></div>
                            <div><span>Quotation</span><strong><?php echo htmlspecialchars($quotation['quotation_number']); ?></strong></div>
                            <div><span>Client PO reference</span><strong id="prClientPo"><?php echo htmlspecialchars((string) $quotation['client_po_number']); ?></strong></div>
                            <div><span>Selling amount</span><strong>₱<?php echo number_format((float) $quotation['amount'], 2); ?></strong></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table mb-0" id="prItemsTable">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Category & brand</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit price</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotation_items as $index => $item): ?>
                                        <tr>
                                            <td data-label="Item">
                                                <input type="hidden" name="items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][specs]" value="<?php echo htmlspecialchars((string) $item['specifications']); ?>">
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                <small><?php echo nl2br(htmlspecialchars((string) $item['specifications'])); ?></small>
                                            </td>
                                            <td data-label="Category & brand">
                                                <input type="hidden" name="items[<?php echo $index; ?>][category]" value="<?php echo htmlspecialchars((string) $item['category']); ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][brand]" value="<?php echo htmlspecialchars((string) $item['brand']); ?>">
                                                <?php echo htmlspecialchars((string) $item['category']); ?> · <?php echo htmlspecialchars((string) $item['brand']); ?>
                                            </td>
                                            <td data-label="Qty" class="text-center">
                                                <input type="hidden" name="items[<?php echo $index; ?>][qty]" value="<?php echo (int) $item['quantity']; ?>">
                                                <?php echo (int) $item['quantity']; ?>
                                            </td>
                                            <td data-label="Unit price" class="text-end">
                                                <input type="hidden" name="items[<?php echo $index; ?>][price]" value="<?php echo htmlspecialchars((string) $item['unit_price']); ?>">
                                                ₱<?php echo number_format((float) $item['unit_price'], 2); ?>
                                            </td>
                                            <td data-label="Total" class="text-end">
                                                <input type="hidden" name="items[<?php echo $index; ?>][total]" value="<?php echo htmlspecialchars((string) $item['total_price']); ?>">
                                                <strong>₱<?php echo number_format((float) $item['total_price'], 2); ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="prf-legacy-actions">
                            <button type="submit" class="prf-submit-button">
                                Submit legacy PR <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </section>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/prf-form.js?v=<?php echo filemtime(__DIR__ . '/assets/js/prf-form.js'); ?>"></script>
</body>
</html>
