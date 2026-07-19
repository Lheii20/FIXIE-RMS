<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Procurement') {
    header("Location: dashboard.php");
    exit();
}

$display_po_number = "PO-" . date('Y') . "-[Auto-Generated]";

$categories = [];
$cats_query = $conn->query("SELECT code, name FROM item_categories ORDER BY code ASC");
if ($cats_query) {
    while($row = $cats_query->fetch_assoc()) { $categories[] = $row; }
}

$brands = [];
$brands_query = $conn->query("SELECT brand_name FROM brands ORDER BY brand_name ASC");
if ($brands_query) {
    while($row = $brands_query->fetch_assoc()) { $brands[] = $row['brand_name']; }
}

$pr_id_val = '';
$client_name_val = '';
$pr_amount_val = 0;
$pr_items_json = '[]';
$source_quotation_number = '';

if (isset($_GET['pr_id'])) {
    $pr_id = intval($_GET['pr_id']);
    $pr_query = $conn->query("SELECT pr.client_name, pr.amount, q.quotation_number AS source_quotation_number 
                              FROM purchase_requests pr 
                              LEFT JOIN quotations q ON q.quotation_id = pr.quotation_id 
                              WHERE pr.pr_id = $pr_id AND pr.status = 'Approved'");
    
    if ($pr_query && $pr_query->num_rows > 0) {
        $pr_data = $pr_query->fetch_assoc();
        $pr_id_val = $pr_id;
        $client_name_val = htmlspecialchars($pr_data['client_name']);
        $pr_amount_val = floatval($pr_data['amount']);
        $source_quotation_number = $pr_data['source_quotation_number'] ?? '';
        
        $items_query = $conn->query("SELECT category, brand, item_name, specifications, quantity, unit_price, total_price FROM pr_items WHERE pr_id = $pr_id");
        $items_arr = [];
        
        while ($i = $items_query->fetch_assoc()) {
            $cat_code = '01';
            $c = strtolower($i['category']);
            $matched = false;
            foreach ($categories as $dbc) {
                if (stripos($c, strtolower($dbc['name'])) !== false || stripos(strtolower($dbc['name']), $c) !== false) {
                    $cat_code = $dbc['code']; $matched = true; break;
                }
            }
            if (!$matched) {
                if(strpos($c, 'cctv') !== false) $cat_code = '02';
                elseif(strpos($c, 'peripheral') !== false) $cat_code = '03';
                elseif(strpos($c, 'office') !== false) $cat_code = '04';
                elseif(strpos($c, 'wifi') !== false || strpos($c, 'lan') !== false) $cat_code = '05';
                elseif(strpos($c, 'print') !== false) $cat_code = '06';
            }
            $items_arr[] = [
                'category' => $cat_code,
                'brand' => htmlspecialchars($i['brand'] ?? 'Generic/Other'),
                'name' => htmlspecialchars($i['item_name']),
                'specs' => htmlspecialchars($i['specifications'] ?? ''),
                'qty' => $i['quantity'],
                'price' => $i['unit_price'],
                'origQty' => $i['quantity']
            ];
        }
        $pr_items_json = json_encode($items_arr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create PO - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in">
        <div class="container-fluid max-w-1300">
            
            <div class="d-flex align-items-center mb-4 mt-2">
                <a href="po_list.php" class="btn btn-white shadow-sm rounded-custom d-flex align-items-center justify-content-center me-3 box-38 border"><i class="fas fa-arrow-left text-secondary"></i></a>
                <div>
                    <h4 class="fw-bold text-dark mb-0 tracking-tight">Purchase Order</h4>
                    <p class="text-muted mb-0 fs-sm">Draft your official purchase document.</p>
                </div>
            </div>

            <div class="split-card row g-0">
                
                <div class="col-lg-3 left-panel d-none d-lg-block">
                    <div class="p-4 position-sticky sticky-top-85">
                        <h6 class="fw-bold text-muted text-uppercase mb-4 fs-xs tracking-wider">Encoding Progress</h6>
                        
                        <div class="vertical-stepper">
                            <div class="step-node active" id="nav-step1">
                                <div class="step-icon">1</div>
                                <div class="step-text">
                                    <h6 class="fw-bold fs-sm text-dark mb-0">Basic Info</h6>
                                    <small class="text-muted fs-xs">Client details & Document</small>
                                </div>
                            </div>
                            <div class="step-line" id="nav-line"></div>
                            <div class="step-node" id="nav-step2">
                                <div class="step-icon">2</div>
                                <div class="step-text">
                                    <h6 class="fw-bold fs-sm text-dark mb-0">Order Items</h6>
                                    <small class="text-muted fs-xs">Specifications & Pricing</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-9 p-3 p-md-4 right-panel">
                    
                    <div id="mobileGrandTotalWrapper" class="d-lg-none mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center">
                        <span class="badge bg-primary text-white" id="mobile-step-indicator">Step 1 of 2</span>
                        <h5 class="fw-bold text-primary m-0" id="mobileGrandTotal">₱ 0.00</h5>
                    </div>

                    <form action="actions/po_handler.php" method="POST" id="poForm" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" id="hiddenGrandTotal">
                        <?php if($pr_id_val): ?><input type="hidden" name="pr_id" value="<?php echo $pr_id_val; ?>"><?php endif; ?>

                        <div class="wizard-step active-step" id="step1">
                            <h5 class="fw-bold text-dark mb-3">Client Information</h5>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label-sleek">Generated PO Number</label>
                                    <input type="text" class="form-control soft-input text-primary fw-bold bg-light-blue" value="<?php echo $display_po_number; ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-sleek">Client / Agency Name <span class="req-star">*</span></label>
                                    <input type="text" name="client_name" id="clientName" class="form-control soft-input" value="<?php echo $client_name_val; ?>" placeholder="e.g. Department of Education" required>
                                </div>

                                <?php if($source_quotation_number): ?>
                                <div class="col-md-12">
                                    <div class="fs-sm text-muted"><i class="fas fa-link text-primary me-1"></i>Source quotation: <strong class="text-dark"><?php echo htmlspecialchars($source_quotation_number); ?></strong></div>
                                </div>
                                <?php endif; ?>

                                <div class="col-md-12 mt-3">
                                    <div class="p-3 rounded-custom dashed-upload-box">
                                        <i class="fas fa-cloud-upload-alt text-primary fs-4 mb-1 opacity-75"></i>
                                        <label class="form-label-sleek d-block text-dark mb-1">Attach Quotation File <small class="text-muted text-lowercase fw-normal">(Optional)</small></label>
                                        <input type="file" name="po_document" class="form-control soft-input mx-auto max-w-350" accept=".pdf,.png,.jpg,.jpeg">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="wizard-step" id="step2">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-dark m-0">Line Items</h5>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-custom px-3" onclick="addItemRow()">
                                    <i class="fas fa-plus me-1"></i> Add Row
                                </button>
                            </div>
                            
                            <div id="dynamicWarnings" class="bg-danger bg-opacity-10 rounded-custom px-3 py-2 mb-3 d-none d-flex align-items-center">
                                <small id="warnQty" class="text-danger fw-bold me-3 d-none"><i class="fas fa-exclamation-circle me-1"></i> Quantity limit exceeded</small>
                                <small id="warnBudget" class="text-danger fw-bold d-none"><i class="fas fa-exclamation-circle me-1"></i> Over Budget Detected</small>
                            </div>

                            <div class="table-container">
                                <table class="table table-glass w-100" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th class="w-20">Category & Brand <span class="req-star">*</span></th>
                                            <th style="width: 32%;">Description & Specs <span class="req-star">*</span></th>
                                            <th style="width: 10%;">Qty <span class="req-star">*</span></th>
                                            <th style="width: 16%;">Unit Price <span class="req-star">*</span></th>
                                            <th style="width: 16%;">Line Total</th>
                                            <th style="width: 6%;" class="text-center">Del</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="glass-bar-container">
        <div class="glass-bar">
            <div class="d-flex align-items-center gap-2">
                <div class="bg-primary bg-opacity-10 text-primary rounded-custom d-flex align-items-center justify-content-center d-none d-md-flex box-42">
                    <i class="fas fa-calculator fs-5"></i>
                </div>
                <div class="calc-total-box">
                    <small class="text-primary text-uppercase fw-bold d-block fs-xs tracking-wider">Calculated Total</small>
                    <h4 class="fw-bold text-primary m-0 tracking-tight text-nowrap" id="floatingGrandTotal">₱ 0.00</h4>
                </div>
            </div>
            
            <div id="btn-group-step1" class="d-flex gap-2 ms-auto align-items-center">
                <button type="button" class="btn btn-primary fw-bold rounded-custom shadow-sm btn-glass-action" onclick="goToStep('step2')">
                    <span class="d-none d-sm-inline">Proceed to Items</span>
                    <span class="d-inline d-sm-none">Next</span> 
                    <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </div>
            <div id="btn-group-step2" class="d-flex gap-2 ms-auto align-items-center d-none">
                <button type="button" class="btn btn-light fw-bold rounded-custom border btn-glass-action" onclick="goToStep('step1')">
                    <i class="fas fa-arrow-left me-1"></i> 
                    <span class="d-none d-sm-inline">Back</span>
                </button>
                <button type="button" class="btn btn-success fw-bold rounded-custom shadow-sm btn-glass-action" onclick="document.getElementById('poForm').submit();">
                    <span class="d-none d-sm-inline">Submit Order</span>
                    <span class="d-inline d-sm-none">Submit</span> 
                    <i class="fas fa-check-circle ms-1"></i>
                </button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });

        function goToStep(step) {
            if(step === 'step2') {
                let isValid = true;
                $('#step1 [required]').each(function() {
                    if (!$(this).val()) { $(this).addClass('is-invalid'); isValid = false; } 
                    else { $(this).removeClass('is-invalid'); }
                });
                if(!isValid) {
                    Toast.fire({ icon: 'error', title: 'Please complete all required fields.' });
                    return;
                }
                $('#step1').removeClass('active-step'); $('#step2').addClass('active-step');
                
                $('#nav-step1').removeClass('active').addClass('completed');
                $('#nav-step1 .step-icon').html('<i class="fas fa-check"></i>');
                $('#nav-step2').addClass('active');
                $('#btn-group-step1').addClass('d-none');
                $('#btn-group-step2').removeClass('d-none');
            } else {
                $('#step2').removeClass('active-step'); $('#step1').addClass('active-step');
                
                $('#nav-step2').removeClass('active');
                $('#nav-step1').removeClass('completed').addClass('active');
                $('#nav-step1 .step-icon').html('1');
                $('#btn-group-step2').addClass('d-none');
                $('#btn-group-step1').removeClass('d-none');
            }
        }

        const dbCategories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const dbBrands = <?php echo json_encode($brands, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let itemIndex = 0;
        const prefilledItems = <?php echo $pr_items_json; ?>; 
        const originalPrAmount = <?php echo json_encode($pr_amount_val, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const draftKey = 'po_draft_' + <?php echo json_encode($pr_id_val ? $pr_id_val : 'new', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        
        function addItemRow(data = null) {
            const tbody = document.getElementById('itemsBody');
            const row = tbody.insertRow();
            
            const cat = data ? data.category : '';
            const brand = data ? data.brand : 'Generic/Other';
            const name = data ? data.name : '';
            const specs = data ? data.specs : '';
            const qty = data ? data.qty : 1;
            const price = data ? parseFloat(data.price).toFixed(2) : '';
            const origQtyAttr = (data && data.origQty) ? `data-orig-qty="${data.origQty}"` : `data-orig-qty="0"`;

            let catOptions = `<option value="" disabled ${!cat ? 'selected' : ''}>Category...</option>`;
            dbCategories.forEach(c => { catOptions += `<option value="${c.code}" ${cat == c.code ? 'selected' : ''}>${parseInt(c.code)} - ${c.name}</option>`; });
            
            let brandOptions = `<option value="Generic/Other" ${brand === 'Generic/Other' ? 'selected' : ''}>Select Brand</option>`;
            dbBrands.forEach(b => { if(b !== 'Generic/Other') { brandOptions += `<option value="${b}" ${brand === b ? 'selected' : ''}>${b}</option>`; } });
            
            row.innerHTML = `
                <td data-label="Category & Brand">
                    <select name="items[${itemIndex}][category]" class="form-select soft-input mb-2" required>${catOptions}</select>
                    <select name="items[${itemIndex}][brand]" class="form-select soft-input text-muted brand-select">${brandOptions}</select>
                </td>
                <td data-label="Description & Specs">
                    <input type="text" name="items[${itemIndex}][name]" class="form-control soft-input mb-2 fw-bold" placeholder="Item Name" value="${name}" required>
                    <textarea name="items[${itemIndex}][specs]" class="form-control soft-input spec-textarea" rows="1" placeholder="Specifications..." oninput="this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px';">${specs}</textarea>
                </td>
                <td data-label="Quantity">
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control soft-input text-center qty-input" value="${qty}" min="1" step="1" ${origQtyAttr} oninput="this.value = this.value.replace(/[^0-9]/g, ''); calculateRow(this);" required>
                </td>
                <td data-label="Unit Price">
                    <div class="soft-input-group w-100">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="0.01" name="items[${itemIndex}][price]" class="form-control soft-input price-input" placeholder="0.00" value="${price}" oninput="calculateRow(this)" onkeypress="return isNumberKey(event)" required>
                    </div>
                </td>
                <td data-label="Line Total">
                    <input type="text" class="form-control bg-transparent text-lg-end fw-bold total-display border-0 px-0 fs-6 text-primary" value="0.00" readonly>
                    <input type="hidden" class="total-input" value="0">
                </td>
                <td data-label="Action" class="align-middle border-0">
                    <div class="d-flex align-items-center justify-content-lg-center h-100 mt-2 mt-lg-0">
                        <button type="button" class="btn text-danger bg-danger bg-opacity-10 border-0 rounded-custom d-inline-flex align-items-center justify-content-center w-100 max-w-120 h-36 p-0" onclick="removeRow(this)" title="Delete Row"><i class="fas fa-trash-alt m-0 fs-sm"></i> <span class="d-lg-none ms-2 fw-bold fs-sm">Remove Item</span></button>
                    </div>
                </td>
            `;
            
            if (data && data.brand) {
                const brandSelect = row.querySelector('.brand-select');
                if (brandSelect) {
                    brandSelect.value = data.brand;
                    if (!brandSelect.value) { brandSelect.value = 'Generic/Other'; }
                }
            }

            const specTextArea = row.querySelector('.spec-textarea');
            if(specTextArea && specTextArea.value) {
                setTimeout(() => {
                    specTextArea.style.height = 'auto';
                    specTextArea.style.height = specTextArea.scrollHeight + 'px';
                }, 10);
            }

            const newPriceInput = row.querySelector('.price-input');
            calculateRow(newPriceInput);

            itemIndex++;
        }
        
        function isNumberKey(evt) {
            var charCode = (evt.which) ? evt.which : evt.keyCode;
            if (charCode != 46 && charCode > 31 && (charCode < 48 || charCode > 57)) return false;
            return true;
        }
        
        function calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const total = qty * price;
            
            row.querySelector('.total-display').value = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            row.querySelector('.total-input').value = total;
            calculateGrandTotal();
        }
        
        function calculateGrandTotal() {
            let grandTotal = 0; let qtyExceeded = false;
            document.querySelectorAll('.total-input').forEach(input => { grandTotal += parseFloat(input.value) || 0; });
            document.querySelectorAll('.qty-input').forEach(input => {
                const origQty = parseInt(input.getAttribute('data-orig-qty')) || 0;
                const currentQty = parseInt(input.value) || 0;
                if (origQty > 0 && currentQty > origQty) { qtyExceeded = true; }
            });
            
            const formattedTotal = '₱ ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('floatingGrandTotal').innerText = formattedTotal;
            document.getElementById('mobileGrandTotal').innerText = formattedTotal;
            document.getElementById('hiddenGrandTotal').value = grandTotal;
            
            checkWarnings(grandTotal, qtyExceeded);
        }
        
        function checkWarnings(grandTotal, qtyExceeded) {
            const warningBox = document.getElementById('dynamicWarnings');
            const warnQty = document.getElementById('warnQty');
            const warnBudget = document.getElementById('warnBudget');
            let showWarnings = false;

            document.querySelectorAll('.qty-input').forEach(input => {
                const origQty = parseInt(input.getAttribute('data-orig-qty')) || 0;
                const currentQty = parseInt(input.value) || 0;
                if (origQty > 0 && currentQty > origQty) { input.classList.add('is-invalid'); } 
                else { input.classList.remove('is-invalid'); }
            });

            if (qtyExceeded) { warnQty.classList.remove('d-none'); showWarnings = true; } else { warnQty.classList.add('d-none'); }
            if (originalPrAmount > 0 && grandTotal > originalPrAmount) { warnBudget.classList.remove('d-none'); showWarnings = true; } else { warnBudget.classList.add('d-none'); }

            if (showWarnings) { warningBox.classList.remove('d-none'); } else { warningBox.classList.add('d-none'); }
        }
        
        function removeRow(btn) { btn.closest('tr').remove(); calculateGrandTotal(); saveDraft(); }

        function saveDraft() {
            let items = [];
            document.querySelectorAll('#itemsBody tr').forEach((row) => {
                let cat = row.querySelector('select[name$="[category]"]').value;
                let brand = row.querySelector('select[name$="[brand]"]').value;
                let name = row.querySelector('input[name$="[name]"]').value;
                let specs = row.querySelector('textarea[name$="[specs]"]').value;
                let qty = row.querySelector('input[name$="[qty]"]').value;
                let price = row.querySelector('input[name$="[price]"]').value;
                let origQty = row.querySelector('input[name$="[qty]"]').getAttribute('data-orig-qty');
                items.push({ category: cat, brand: brand, name: name, specs: specs, qty: qty, price: price, origQty: origQty });
            });
            let draftData = { client_name: document.getElementById('clientName').value, items: items };
            localStorage.setItem(draftKey, JSON.stringify(draftData));
        }

        function loadDraft() {
            let draft = localStorage.getItem(draftKey);
            if (draft) {
                try {
                    draft = JSON.parse(draft);
                    if(draft.client_name) document.getElementById('clientName').value = draft.client_name;
                    if (draft.items && draft.items.length > 0) {
                        draft.items.forEach(item => addItemRow(item));
                        return true;
                    }
                } catch(e) {}
            }
            return false;
        }

        document.getElementById('poForm').addEventListener('input', saveDraft);
        document.getElementById('poForm').addEventListener('change', saveDraft);
        document.getElementById('poForm').addEventListener('submit', function() { localStorage.removeItem(draftKey); });
        
        window.onload = function() {
            if (!loadDraft()) {
                if (prefilledItems.length > 0) { prefilledItems.forEach(item => addItemRow(item)); } 
                else { addItemRow(); }
            }
        };
    </script>
</body>
</html>