<?php 
require 'config/db_connect.php'; 
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Procurement') {
    header("Location: dashboard.php");
    exit();
}

$year = date('Y');
$po_prefix = "PO-" . $year . "-";
$like_prefix = $po_prefix . "%";

$po_stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY CAST(SUBSTRING_INDEX(po_number, '-', -1) AS UNSIGNED) DESC LIMIT 1");
$po_stmt->bind_param("s", $like_prefix);
$po_stmt->execute();
$po_res = $po_stmt->get_result();

if ($po_res->num_rows > 0) {
    $last_po = $po_res->fetch_assoc()['po_number'];
    $last_po_num = intval(substr($last_po, -4));
    $next_po_num = $last_po_num + 1;
} else {
    $next_po_num = 1;
}
$display_po_number = $po_prefix . str_pad($next_po_num, 4, "0", STR_PAD_LEFT);

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

if (isset($_GET['pr_id'])) {
    $pr_id = intval($_GET['pr_id']);
    $pr_query = $conn->query("SELECT client_name, amount FROM purchase_requests WHERE pr_id = $pr_id AND status = 'Approved'");
    
    if ($pr_query && $pr_query->num_rows > 0) {
        $pr_data = $pr_query->fetch_assoc();
        $pr_id_val = $pr_id;
        $client_name_val = htmlspecialchars($pr_data['client_name']);
        $pr_amount_val = floatval($pr_data['amount']);
        
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
        $pr_items_json = json_encode($items_arr);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create PO - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        body { background: #f4f7fb; padding-bottom: 100px; } 
        .req-star { color: #ef4444; font-weight: bold; margin-left: 2px; }

        .rounded-custom { border-radius: 6px !important; }

        .form-label { font-size: 0.8rem; font-weight: 700; color: #4b5563; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.4rem; }
        .soft-input { background: #f3f4f6; border: 2px solid transparent; border-radius: 6px !important; padding: 0.45rem 0.75rem; font-size: 0.85rem; font-weight: 500; color: #1f2937; transition: all 0.3s ease; }
        .soft-input::placeholder { color: #9ca3af; font-weight: 400; }
        .soft-input:focus { background: #fff; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); outline: none; }
        .soft-input.is-invalid { border-color: #ef4444; background: #fef2f2; }

        select.soft-input {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 1rem; padding-right: 2.2rem; cursor: pointer;
        }

        .split-card { background: transparent; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .left-panel { background-color: #f8fafc; border-right: 1px solid #e5e7eb; border-radius: 12px 0 0 12px; }
        .right-panel { background-color: #fff; border-radius: 0 12px 12px 0; }
        
        .table-container { max-height: 420px; overflow-y: auto; border: 1px solid #f3f4f6; border-radius: 6px; }
        .table-glass { margin-bottom: 0; }
        .table-glass th { background: #f9fafb; color: #6b7280; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; padding: 0.75rem 0.5rem; font-weight: 700; position: sticky; top: 0; z-index: 10; }
        .table-glass td { padding: 0.75rem 0.5rem; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        
        .table-container::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
        .table-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }

        .vertical-stepper { display: flex; flex-direction: column; position: relative; }
        .step-node { display: flex; gap: 12px; align-items: flex-start; position: relative; z-index: 2; opacity: 0.5; transition: 0.3s ease; }
        .step-node.active, .step-node.completed { opacity: 1; }
        .step-icon { width: 30px; height: 30px; border-radius: 6px; background: #fff; border: 2px solid #adb5bd; display: flex; align-items: center; justify-content: center; color: #adb5bd; transition: 0.3s; font-size: 0.8rem; font-weight: bold; }
        .step-node.active .step-icon { border-color: #0d6efd; background: #0d6efd; color: #fff; box-shadow: 0 0 0 4px rgba(13,110,253,0.15); }
        .step-node.completed .step-icon { border-color: #198754; background: #198754; color: #fff; }
        .step-text h6 { margin: 0; font-weight: 700; color: #343a40; font-size: 0.85rem; }
        .step-text small { font-size: 0.7rem; color: #6c757d; }
        .step-line { width: 2px; height: 30px; background: #dee2e6; margin-left: 14px; margin-top: -5px; margin-bottom: -5px; z-index: 1; transition: 0.3s; }
        .step-node.completed + .step-line { background: #198754; }

        .glass-bar-container { position: fixed; bottom: 20px; left: 0; right: 0; display: flex; justify-content: center; z-index: 1000; pointer-events: none; }
        .glass-bar { pointer-events: auto; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(13, 110, 253, 0.2); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); padding: 12px 25px; width: 90%; max-width: 1300px; display: flex; justify-content: space-between; align-items: center; transition: all 0.3s ease; }
        
        .wizard-step { display: none; }
        .wizard-step.active-step { display: block; animation: slideUp 0.3s ease forwards; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .soft-input-group { display: flex; align-items: stretch; }
        .soft-input-group .input-group-text { background: #f3f4f6; border: 2px solid transparent; border-right: none; border-radius: 6px 0 0 6px !important; color: #6b7280; font-weight: 600; font-size: 0.85rem; padding: 0 0.5rem; }
        .soft-input-group .soft-input { border-radius: 0 6px 6px 0 !important; border-left: none; }
        .soft-input-group:focus-within .input-group-text { background: #fff; border-color: #3b82f6; border-right: none; color: #0d6efd; }
        
        @media (max-width: 991px) {
            .table-glass thead { display: none; }
            .table-glass tbody tr { display: block; margin-bottom: 1rem; border: 1px solid #e5e7eb; border-radius: 6px; padding: 1rem; background: #fff; }
            .table-glass tbody td { display: block; padding: 0.25rem 0 !important; border: none; }
            .glass-bar { flex-direction: column; gap: 10px; padding: 15px; bottom: 10px; width: 95%; }
            .glass-bar .d-flex { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1300px;">
            
            <div class="d-flex align-items-center mb-4 mt-2">
                <a href="po_list.php" class="btn btn-white shadow-sm rounded-custom d-flex align-items-center justify-content-center me-3" style="width: 38px; height: 38px; border: 1px solid #e5e7eb;"><i class="fas fa-arrow-left text-secondary"></i></a>
                <div>
                    <h4 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">Purchase Order</h4>
                    <p class="text-muted mb-0" style="font-size: 0.85rem;">Draft your official purchase document.</p>
                </div>
            </div>

            <div class="split-card row g-0">
                
                <!-- LEFT PANEL -->
                <div class="col-lg-3 left-panel d-none d-lg-block">
                    <div class="p-4 position-sticky" style="top: 85px; z-index: 10;">
                        <h6 class="fw-bold text-muted text-uppercase mb-4" style="font-size: 0.75rem; letter-spacing: 1px;">Encoding Progress</h6>
                        
                        <div class="vertical-stepper">
                            <div class="step-node active" id="nav-step1">
                                <div class="step-icon">1</div>
                                <div class="step-text">
                                    <h6>Basic Info</h6>
                                    <small>Client details & Document</small>
                                </div>
                            </div>
                            <div class="step-line" id="nav-line"></div>
                            <div class="step-node" id="nav-step2">
                                <div class="step-icon">2</div>
                                <div class="step-text">
                                    <h6>Order Items</h6>
                                    <small>Specifications & Pricing</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL (Form Area) -->
                <div class="col-lg-9 p-3 p-md-4 right-panel">
                    
                    <div class="d-lg-none mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center">
                        <span class="badge bg-primary text-white" id="mobile-step-indicator">Step 1 of 2</span>
                        <h5 class="fw-bold text-primary m-0" id="mobileGrandTotal">₱ 0.00</h5>
                    </div>

                    <form action="actions/po_handler.php" method="POST" id="poForm" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="grand_total" id="hiddenGrandTotal">
                        <?php if($pr_id_val): ?><input type="hidden" name="pr_id" value="<?php echo $pr_id_val; ?>"><?php endif; ?>

                        <!-- STEP 1 -->
                        <div class="wizard-step active-step" id="step1">
                            <h5 class="fw-bold text-dark mb-3">Client Information</h5>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Generated PO Number</label>
                                    <input type="text" name="po_number" class="form-control soft-input text-primary fw-bold" style="background-color: #eff6ff; border-color: transparent;" value="<?php echo $display_po_number; ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Client / Agency Name <span class="req-star">*</span></label>
                                    <input type="text" name="client_name" id="clientName" class="form-control soft-input" value="<?php echo $client_name_val; ?>" placeholder="e.g. Department of Education" required>
                                </div>
                                <div class="col-md-12 mt-3">
                                    <div class="p-3 rounded-custom" style="background-color: #f8fafc; border: 2px dashed #cbd5e1; text-align: center;">
                                        <i class="fas fa-cloud-upload-alt text-primary fs-4 mb-1 opacity-75"></i>
                                        <label class="form-label d-block text-dark mb-1">Attach Quotation File <small class="text-muted text-lowercase fw-normal">(Optional)</small></label>
                                        <input type="file" name="po_document" class="form-control soft-input mx-auto" accept=".pdf,.png,.jpg,.jpeg" style="max-width: 350px;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2 -->
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
                                            <th width="20%">Category & Brand <span class="req-star">*</span></th>
                                            <th width="32%">Description & Specs <span class="req-star">*</span></th>
                                            <th width="10%">Qty <span class="req-star">*</span></th>
                                            <th width="16%">Unit Price <span class="req-star">*</span></th>
                                            <th width="16%">Line Total</th>
                                            <th width="6%" class="text-center">Del</th>
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

    <!-- FLOATING GLASS BAR -->
    <div class="glass-bar-container">
        <div class="glass-bar">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary rounded-custom d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                    <i class="fas fa-calculator fs-5"></i>
                </div>
                <div>
                    <small class="text-primary text-uppercase fw-bold d-block" style="font-size: 0.65rem; letter-spacing: 1px;">Calculated Total</small>
                    <h4 class="fw-bold text-primary m-0" id="floatingGrandTotal" style="letter-spacing: -0.5px;">₱ 0.00</h4>
                </div>
            </div>
            
            <div id="btn-group-step1" class="d-flex gap-2">
                <button type="button" class="btn btn-primary fw-bold px-4 rounded-custom shadow-sm" onclick="goToStep('step2')">
                    Proceed to Items <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </div>

            <div id="btn-group-step2" class="d-flex gap-2 d-none">
                <button type="button" class="btn btn-light fw-bold px-3 rounded-custom border" onclick="goToStep('step1')">Back</button>
                <button type="button" class="btn btn-success fw-bold px-4 rounded-custom shadow-sm" onclick="document.getElementById('poForm').submit();">
                    Submit Order <i class="fas fa-check-circle ms-1"></i>
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

        const dbCategories = <?php echo json_encode($categories); ?>;
        const dbBrands = <?php echo json_encode($brands); ?>;
        let itemIndex = 0;
        const prefilledItems = <?php echo $pr_items_json; ?>;
        const originalPrAmount = <?php echo json_encode($pr_amount_val); ?>;
        const draftKey = 'po_draft_' + <?php echo json_encode($pr_id_val ? $pr_id_val : 'new'); ?>;
        
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
                <td>
                    <select name="items[${itemIndex}][category]" class="form-select soft-input mb-1" required>${catOptions}</select>
                    <select name="items[${itemIndex}][brand]" class="form-select soft-input text-muted">${brandOptions}</select>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][name]" class="form-control soft-input mb-1 fw-bold" placeholder="Item Name" value="${name}" required>
                    <textarea name="items[${itemIndex}][specs]" class="form-control soft-input spec-textarea" rows="1" placeholder="Specifications..." style="resize: none; overflow: hidden; min-height: 40px;" oninput="this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px';">${specs}</textarea>
                </td>
                <td>
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control soft-input text-center qty-input" value="${qty}" min="1" step="1" ${origQtyAttr} oninput="this.value = this.value.replace(/[^0-9]/g, ''); calculateRow(this);" required>
                </td>
                <td>
                    <div class="soft-input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="0.01" name="items[${itemIndex}][price]" class="form-control soft-input price-input" placeholder="0.00" value="${price}" oninput="calculateRow(this)" onkeypress="return isNumberKey(event)" required>
                    </div>
                </td>
                <td>
                    <input type="text" class="form-control bg-transparent text-end fw-bold total-display border-0 px-0 fs-6 text-primary" value="0.00" readonly>
                    <input type="hidden" name="items[${itemIndex}][total]" class="total-input" value="0">
                </td>
                <td class="align-middle">
                    <div class="d-flex align-items-center justify-content-center h-100">
                        <button type="button" class="btn text-danger bg-danger bg-opacity-10 border-0 rounded-custom d-inline-flex align-items-center justify-content-center mx-auto" style="width:32px; height:32px; padding:0;" onclick="removeRow(this)" title="Delete Row"><i class="fas fa-trash-alt m-0" style="font-size:0.8rem;"></i></button>
                    </div>
                </td>
            `;
            
            if (data && data.brand) {
                const brandSelect = row.querySelector('.brand-select');
                brandSelect.value = data.brand;
                if (!brandSelect.value) { brandSelect.value = 'Generic/Other'; }
            }

            // Trigger auto-resize para sa draft at pre-filled data
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