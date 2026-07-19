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

$brands = [];
$brands_query = $conn->query("SELECT brand_name FROM brands ORDER BY brand_name ASC");
if ($brands_query) {
    while($row = $brands_query->fetch_assoc()) { $brands[] = $row['brand_name']; }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="container-fluid max-w-1300">
            
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 mt-2">
                <div class="d-flex align-items-center">
                    <a href="quotations_list.php" class="btn btn-white shadow-sm rounded-custom d-flex align-items-center justify-content-center me-3 box-38 border"><i class="fas fa-arrow-left text-secondary"></i></a>
                    <div>
                        <h4 class="fw-bold text-dark mb-0 tracking-tight">Generate Quotation</h4>
                        <p class="text-muted mb-0 d-none d-sm-block fs-sm">Draft an official client quotation.</p>
                    </div>
                </div>
            </div>

            <div class="split-card row g-0">
                
                <!-- LEFT PANEL -->
                <div class="col-lg-3 left-panel d-none d-lg-block">
                    <div class="p-4 position-sticky sticky-top-85">
                        <h6 class="fw-bold text-muted text-uppercase mb-4 fs-xs tracking-wider">Creation Progress</h6>
                        
                        <div class="vertical-stepper">
                            <div class="step-node active" id="nav-step1">
                                <div class="step-icon">1</div>
                                <div class="step-text">
                                    <h6 class="fw-bold fs-sm text-dark mb-0">Basic Info</h6>
                                    <small class="text-muted fs-xs">Client details & Ref</small>
                                </div>
                            </div>
                            <div class="step-line" id="nav-line"></div>
                            <div class="step-node" id="nav-step2">
                                <div class="step-icon">2</div>
                                <div class="step-text">
                                    <h6 class="fw-bold fs-sm text-dark mb-0">Quoted Items</h6>
                                    <small class="text-muted fs-xs">Specifications & Pricing</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL (Form Area) -->
                <div class="col-lg-9 p-3 p-md-4 right-panel">
                    
                    <div id="mobileGrandTotalWrapper" class="d-lg-none mb-3 pb-2 border-bottom d-flex justify-content-between align-items-center">
                        <span class="badge bg-primary text-white" id="mobile-step-indicator">Step 1 of 2</span>
                        <h5 class="fw-bold text-primary m-0" id="mobileGrandTotal">₱ 0.00</h5>
                    </div>

                    <form action="actions/quotation_handler.php" method="POST" id="quotationForm" onkeydown="return event.key != 'Enter';">
                        <input type="hidden" name="action" value="create_detailed_quotation">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <input type="hidden" name="amount" id="hiddenGrandTotal" value="0">

                        <!-- STEP 1 -->
                        <div class="wizard-step active-step" id="step1">
                            <h5 class="fw-bold text-dark mb-3">Quotation Information</h5>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label-sleek">Generated Quote Number</label>
                                    <input type="text" name="quotation_number" class="form-control soft-input text-primary fw-bold bg-light-blue" value="<?php echo $display_q_number; ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-sleek">Client / Agency Name <span class="req-star">*</span></label>
                                    <input type="text" name="client_name" id="clientName" class="form-control soft-input" placeholder="e.g. Acme Corporation" required>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2 -->
                        <div class="wizard-step" id="step2">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-dark m-0">Item Breakdown</h5>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-custom px-3" onclick="addItemRow()">
                                    <i class="fas fa-plus me-1"></i> Add Row
                                </button>
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

    <!-- REFINED ULTRA-THIN GLASS BAR -->
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
                <button type="button" class="btn btn-success fw-bold rounded-custom shadow-sm btn-glass-action" onclick="document.getElementById('quotationForm').submit();">
                    <span class="d-none d-sm-inline">Save Quote</span>
                    <span class="d-inline d-sm-none">Save</span> 
                    <i class="fas fa-save ms-1"></i>
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
        const dbBrands = <?php echo json_encode($brands); ?>;
        let itemIndex = 0;

        function addItemRow() {
            const tbody = document.getElementById('itemsBody');
            const row = tbody.insertRow();

            let catOptions = `<option value="" disabled selected>Category...</option>`;
            dbCategories.forEach(c => { catOptions += `<option value="${c.code}">${parseInt(c.code)} - ${c.name}</option>`; });
            
            let brandOptions = `<option value="Generic/Other" selected>Select Brand</option>`;
            dbBrands.forEach(b => { if(b !== 'Generic/Other') { brandOptions += `<option value="${b}">${b}</option>`; } });

            row.innerHTML = `
                <td data-label="Category & Brand">
                    <select name="items[${itemIndex}][category]" class="form-select soft-input mb-2" required>${catOptions}</select>
                    <select name="items[${itemIndex}][brand]" class="form-select soft-input text-muted">${brandOptions}</select>
                </td>
                <td data-label="Description & Specs">
                    <input type="text" name="items[${itemIndex}][name]" class="form-control soft-input mb-2 fw-bold" placeholder="Item Name" required>
                    <textarea name="items[${itemIndex}][specs]" class="form-control soft-input spec-textarea" rows="1" placeholder="Specifications..." oninput="this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px';"></textarea>
                </td>
                <td data-label="Quantity">
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control soft-input text-center qty-input" value="1" min="1" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calculateRow(this);" required>
                </td>
                <td data-label="Unit Price">
                    <div class="soft-input-group w-100">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="0.01" name="items[${itemIndex}][price]" class="form-control soft-input price-input" placeholder="0.00" oninput="calculateRow(this)" required>
                    </div>
                </td>
                <td data-label="Line Total">
                    <input type="text" class="form-control bg-transparent text-lg-end fw-bold total-display border-0 px-0 fs-6 text-primary" value="0.00" readonly>
                    <input type="hidden" name="items[${itemIndex}][total]" class="total-input" value="0">
                </td>
                <td data-label="Action" class="align-middle border-0">
                    <div class="d-flex align-items-center justify-content-lg-center h-100 mt-2 mt-lg-0">
                        <button type="button" class="btn text-danger bg-danger bg-opacity-10 border-0 rounded-custom d-inline-flex align-items-center justify-content-center w-100 max-w-120 h-36 p-0" onclick="removeRow(this)" title="Delete Row"><i class="fas fa-trash-alt m-0 fs-sm"></i> <span class="d-lg-none ms-2 fw-bold fs-sm">Remove Item</span></button>
                    </div>
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

        function removeRow(btn) { btn.closest('tr').remove(); calculateGrandTotal(); }

        window.onload = addItemRow;
    </script>
</body>
</html>