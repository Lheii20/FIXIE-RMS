<?php
require 'config/db_connect.php';
require 'config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales Staff') {
    header("Location: dashboard.php");
    exit();
}

$year = date('Y');
$q_prefix = "QTN-" . $year . "-";
$like_prefix = $q_prefix . "%";

$stmt = $conn->prepare("SELECT quotation_number FROM quotations WHERE quotation_number LIKE ? ORDER BY CAST(SUBSTRING_INDEX(quotation_number, '-', -1) AS UNSIGNED) DESC LIMIT 1");
$stmt->bind_param("s", $like_prefix);
$stmt->execute();
$res = $stmt->get_result();

$next_num = ($res->num_rows > 0) ? intval(substr($res->fetch_assoc()['quotation_number'], -4)) + 1 : 1;
$display_q_number = $q_prefix . str_pad($next_num, 4, "0", STR_PAD_LEFT);

$categories = [];
$cats_query = $conn->query("SELECT code, name FROM item_categories ORDER BY code ASC");
if ($cats_query) {
    while($row = $cats_query->fetch_assoc()) { $categories[] = $row; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create Quotation - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/vendor/sweetalert2/11.26.25/sweetalert2.min.css">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="page-create-quotation workflow-ui">
    <?php include 'sidebar.php'; ?>
    <link href="assets/css/create-quotation.css?v=<?php echo filemtime(__DIR__ . '/assets/css/create-quotation.css'); ?>" rel="stylesheet">
    <main class="main-content fade-in">
        <div class="quotation-draft-shell">
            <header class="qd-page-header">
                <div class="qd-title-group">
                    <a href="quotations_list.php" class="qd-back-button" aria-label="Back to quotations" title="Back to quotations">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    </a>
                    <div>
                        <span class="qd-eyebrow">Sales workspace</span>
                        <h1>Draft quotation</h1>
                        <p>Prepare client details, quoted items, and pricing in one focused workspace.</p>
                    </div>
                </div>
                <span class="qd-draft-badge"><i class="fas fa-pen" aria-hidden="true"></i> Draft</span>
            </header>

            <nav class="qd-progress" aria-label="Quotation creation progress">
                <div class="step-node active" id="nav-step1" aria-current="step">
                    <span class="step-icon">1</span>
                    <span class="step-text"><strong>Client details</strong><small>Reference and recipient</small></span>
                </div>
                <span class="step-line" id="nav-line" aria-hidden="true"></span>
                <div class="step-node" id="nav-step2">
                    <span class="step-icon">2</span>
                    <span class="step-text"><strong>Quoted items</strong><small>Scope and pricing</small></span>
                </div>
                <span class="qd-mobile-step" id="mobile-step-indicator">Step 1 of 2</span>
            </nav>

            <form action="actions/quotation_handler.php" method="POST" id="quotationForm" onkeydown="return event.key != 'Enter';">
                <input type="hidden" name="action" value="create_detailed_quotation">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="amount" id="hiddenGrandTotal" value="0">

                <section class="wizard-step active-step qd-form-card" id="step1" aria-labelledby="quotationDetailsHeading">
                    <header class="qd-section-header">
                        <span class="qd-section-icon"><i class="fas fa-file-lines" aria-hidden="true"></i></span>
                        <div><span>Step 1</span><h2 id="quotationDetailsHeading">Quotation details</h2><p>Identify the quotation and the client who will receive it.</p></div>
                    </header>
                    <div class="qd-detail-grid">
                        <div class="qd-field">
                            <label for="quotationNumber">Quotation number</label>
                            <div class="qd-input-wrap is-reference"><i class="fas fa-hashtag" aria-hidden="true"></i><input type="text" id="quotationNumber" name="quotation_number" class="form-control soft-input" value="<?php echo htmlspecialchars($display_q_number, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
                            <small>Generated automatically and reserved when the quotation is saved.</small>
                        </div>
                        <div class="qd-field">
                            <label for="clientName">Client or agency <span class="req-star">Required</span></label>
                            <div class="qd-input-wrap"><i class="fas fa-building" aria-hidden="true"></i><input type="text" name="client_name" id="clientName" class="form-control soft-input" placeholder="Enter the registered client or agency name" maxlength="150" autocomplete="organization" required></div>
                            <small>Use the name that should appear on the printed quotation.</small>
                        </div>
                    </div>
                    <aside class="qd-guidance"><i class="fas fa-circle-info" aria-hidden="true"></i><p><strong>Before proceeding:</strong> confirm the client name carefully. It becomes part of the quotation record and approval trail.</p></aside>
                </section>

                <section class="wizard-step qd-form-card qd-items-card" id="step2" aria-labelledby="quotationItemsHeading">
                    <header class="qd-section-header qd-items-heading">
                        <span class="qd-section-icon"><i class="fas fa-boxes-stacked" aria-hidden="true"></i></span>
                        <div><span>Step 2</span><h2 id="quotationItemsHeading">Quoted items</h2><p>Add each product or service with its quantity and client price.</p></div>
                        <div class="qd-item-tools"><span id="quotationItemCount">1 item</span><button type="button" class="btn btn-outline-primary" onclick="addItemRow()"><i class="fas fa-plus" aria-hidden="true"></i>Add item</button></div>
                    </header>

                    <div class="table-container qd-table-region" role="region" aria-label="Quotation items" tabindex="0">
                        <table class="table table-glass" id="itemsTable">
                            <caption class="visually-hidden">Products and services included in this quotation</caption>
                            <thead><tr><th>Category and item <span class="req-star">*</span></th><th>Specifications</th><th>Quantity <span class="req-star">*</span></th><th>Unit price <span class="req-star">*</span></th><th>Line total</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                </section>
            </form>

            <footer class="qd-actionbar" aria-label="Quotation form actions">
                <div class="qd-total">
                    <span class="qd-total-icon"><i class="fas fa-calculator" aria-hidden="true"></i></span>
                    <span><small>Quotation total</small><strong id="floatingGrandTotal">₱ 0.00</strong><strong id="mobileGrandTotal">₱ 0.00</strong></span>
                </div>
                <div id="btn-group-step1" class="qd-action-group">
                    <a href="quotations_list.php" class="btn btn-outline-secondary">Cancel</a>
                    <button type="button" class="btn btn-primary" onclick="goToStep('step2')"><span>Continue to items</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                </div>
                <div id="btn-group-step2" class="qd-action-group d-none">
                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep('step1')"><i class="fas fa-arrow-left" aria-hidden="true"></i><span>Back</span></button>
                    <button type="button" class="btn btn-success" onclick="submitQuotationForm();"><i class="fas fa-floppy-disk" aria-hidden="true"></i><span>Save quotation</span></button>
                </div>
            </footer>
        </div>
    </main>

    <script src="assets/vendor/jquery/3.7.0/jquery.min.js"></script>
    <script src="assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/sweetalert2/11.26.25/sweetalert2.all.min.js"></script>
    <script>
        const Toast = Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 2800, timerProgressBar: true, customClass: { popup: 'form-validation-toast shadow-sm border' } });

        <?php if(isset($_GET['error'])): ?>
            Toast.fire({ icon: 'error', title: '<?php echo addslashes(htmlspecialchars($_GET['error'])); ?>' });
            window.history.replaceState(null, null, window.location.pathname);
        <?php endif; ?>

        function hasValidRequiredValue(field) {
            const value = typeof field.value === 'string' ? field.value.trim() : field.value;
            return value !== '' && field.checkValidity();
        }

        function clearQuotationFieldErrorOnEntry(event) {
            const field = event.target;
            if (!field.matches('[required]')) return;

            const value = typeof field.value === 'string' ? field.value.trim() : field.value;
            if (value !== '') field.classList.remove('is-invalid');
        }

        function showInvalidQuotationField(field, message) {
            field.classList.add('is-invalid');
            if (field.closest('#step1') && !document.getElementById('step1').classList.contains('active-step')) {
                goToStep('step1');
            }
            Toast.fire({ icon: 'error', title: message });
            window.setTimeout(() => {
                field.focus({ preventScroll: true });
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field.reportValidity();
            }, 100);
        }

        function validateQuotationForm() {
            const form = document.getElementById('quotationForm');
            const rows = form.querySelectorAll('#itemsBody tr');

            if (rows.length === 0) {
                Toast.fire({ icon: 'error', title: 'Add at least one item before saving the quotation.' });
                return false;
            }

            const requiredFields = Array.from(form.querySelectorAll('[required]'));
            requiredFields.forEach(field => {
                if (hasValidRequiredValue(field)) field.classList.remove('is-invalid');
            });
            const invalidField = requiredFields.find(field => !hasValidRequiredValue(field));
            if (invalidField) {
                showInvalidQuotationField(invalidField, 'Please complete every required field before saving.');
                return false;
            }

            if ((parseFloat(document.getElementById('hiddenGrandTotal').value) || 0) <= 0) {
                Toast.fire({ icon: 'error', title: 'The quotation total must be greater than zero.' });
                return false;
            }

            return true;
        }

        function submitQuotationForm() {
            const form = document.getElementById('quotationForm');
            if (validateQuotationForm()) {
                form.requestSubmit();
            }
        }

        function goToStep(step) {
            if(step === 'step2') {
                const stepOneFields = Array.from(document.querySelectorAll('#step1 [required]'));
                stepOneFields.forEach(field => {
                    if (hasValidRequiredValue(field)) field.classList.remove('is-invalid');
                });
                const firstInvalid = stepOneFields.find(field => !hasValidRequiredValue(field));
                if (firstInvalid) {
                    showInvalidQuotationField(firstInvalid, 'Please complete all required quotation information.');
                    return;
                }
                $('#step1').removeClass('active-step'); $('#step2').addClass('active-step');
                
                $('#nav-step1').removeClass('active').addClass('completed');
                $('#nav-step1 .step-icon').html('<i class="fas fa-check"></i>');
                $('#nav-step2').addClass('active');
                $('#mobile-step-indicator').text('Step 2 of 2');
                $('#btn-group-step1').addClass('d-none');
                $('#btn-group-step2').removeClass('d-none');
            } else {
                $('#step2').removeClass('active-step'); $('#step1').addClass('active-step');
                
                $('#nav-step2').removeClass('active');
                $('#nav-step1').removeClass('completed').addClass('active');
                $('#nav-step1 .step-icon').html('1');
                $('#mobile-step-indicator').text('Step 1 of 2');
                $('#btn-group-step2').addClass('d-none');
                $('#btn-group-step1').removeClass('d-none');
            }
        }

        const dbCategories = <?php echo json_encode($categories); ?>;
        let itemIndex = 0;

        function updateQuotationItemCount() {
            const rows = Array.from(document.querySelectorAll('#itemsBody tr'));
            rows.forEach((row, index) => {
                const sequence = row.querySelector('.qd-item-sequence');
                if (sequence) sequence.textContent = String(index + 1).padStart(2, '0');
            });
            const count = rows.length;
            document.getElementById('quotationItemCount').textContent = `${count} ${count === 1 ? 'item' : 'items'}`;
        }

        function addItemRow() {
            const tbody = document.getElementById('itemsBody');
            const row = tbody.insertRow();

            let catOptions = `<option value="" disabled selected>Select category</option>`;
            dbCategories.forEach(c => { catOptions += `<option value="${c.code}">${parseInt(c.code)} - ${c.name}</option>`; });
            
            row.innerHTML = `
                <td data-label="Category and item">
                    <span class="qd-item-sequence" aria-hidden="true">${String(itemIndex + 1).padStart(2, '0')}</span>
                    <input type="hidden" name="items[${itemIndex}][brand]" value="Generic/Other">
                    <select name="items[${itemIndex}][category]" class="form-select soft-input qd-category-select" aria-label="Item category" required>${catOptions}</select>
                    <input type="text" name="items[${itemIndex}][name]" class="form-control soft-input qd-item-name" placeholder="Product or service name" maxlength="150" required>
                </td>
                <td data-label="Specifications">
                    <textarea name="items[${itemIndex}][specs]" class="form-control soft-input spec-textarea" rows="2" maxlength="2000" placeholder="Model, configuration, inclusions, or other details" oninput="this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px';"></textarea>
                </td>
                <td data-label="Quantity">
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control soft-input text-center qty-input" aria-label="Quantity" value="1" min="1" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calculateRow(this);" required>
                </td>
                <td data-label="Unit Price">
                    <div class="soft-input-group w-100">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="0.01" name="items[${itemIndex}][price]" class="form-control soft-input price-input" aria-label="Unit price" placeholder="0.00" oninput="calculateRow(this)" required>
                    </div>
                </td>
                <td data-label="Line Total">
                    <div class="soft-input-group qd-line-total-group">
                        <span class="input-group-text" aria-hidden="true">₱</span>
                        <input type="text" class="form-control soft-input total-display" aria-label="Line total" value="0.00" readonly>
                    </div>
                    <input type="hidden" name="items[${itemIndex}][total]" class="total-input" value="0">
                </td>
                <td data-label="Action">
                    <button type="button" class="qd-remove-item" onclick="removeRow(this)" title="Remove item" aria-label="Remove this quotation item"><i class="fas fa-trash-alt" aria-hidden="true"></i><span>Remove</span></button>
                </td>
            `;

            const specTextArea = row.querySelector('.spec-textarea');
            if(specTextArea && specTextArea.value) {
                setTimeout(() => {
                    specTextArea.style.height = 'auto';
                    specTextArea.style.height = specTextArea.scrollHeight + 'px';
                }, 10);
            }
            itemIndex++;
            updateQuotationItemCount();
        }

        function calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const total = qty * price;
            
            row.querySelector('.total-display').value = total.toLocaleString('en-US', {minimumFractionDigits: 2});
            row.querySelector('.total-input').value = total;
            calculateGrandTotal();
        }

        function calculateGrandTotal() {
            let grandTotal = 0;
            document.querySelectorAll('.total-input').forEach(i => grandTotal += parseFloat(i.value) || 0);
            
            const formattedTotal = '₱ ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('floatingGrandTotal').innerText = formattedTotal;
            document.getElementById('mobileGrandTotal').innerText = formattedTotal;
            document.getElementById('hiddenGrandTotal').value = grandTotal;
        }

        function removeRow(btn) { btn.closest('tr').remove(); calculateGrandTotal(); updateQuotationItemCount(); }

        const quotationFormElement = document.getElementById('quotationForm');
        quotationFormElement.addEventListener('input', clearQuotationFieldErrorOnEntry);
        quotationFormElement.addEventListener('change', clearQuotationFieldErrorOnEntry);
        quotationFormElement.addEventListener('submit', function(event) {
            if (!validateQuotationForm()) {
                event.preventDefault();
            }
        });

        window.onload = addItemRow;
    </script>
</body>
</html>

