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
            if(strpos($c, 'cctv') !== false) $cat_code = '02';
            elseif(strpos($c, 'peripheral') !== false) $cat_code = '03';
            elseif(strpos($c, 'office') !== false) $cat_code = '04';
            elseif(strpos($c, 'wifi') !== false || strpos($c, 'lan') !== false) $cat_code = '05';
            elseif(strpos($c, 'print') !== false) $cat_code = '06';

            $items_arr[] = [
                'category' => $cat_code,
                'brand' => htmlspecialchars($i['brand'] ?? 'Generic/Other'),
                'name' => htmlspecialchars($i['item_name']),
                'specs' => htmlspecialchars($i['specifications'] ?? ''),
                'qty' => $i['quantity'],
                'price' => $i['unit_price']
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
    <style>
        .warning-text {
            font-size: 0.80rem;
            letter-spacing: 0.2px;
            opacity: 0.9;
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1400px;">
            
            <div class="d-flex align-items-center mb-4">
                <a href="po_list.php" class="btn btn-light border me-3"><i class="fas fa-arrow-left"></i></a>
                <div>
                    <h2 class="fw-bold mb-0">Create Purchase Order</h2>
                    <p class="text-muted mb-0">Enter client details and product specifications.</p>
                </div>
            </div>

            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <form action="actions/po_handler.php" method="POST" id="poForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create">
                <?php if($pr_id_val): ?>
                    <input type="hidden" name="pr_id" value="<?php echo $pr_id_val; ?>">
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white fw-bold text-primary py-3">
                                <i class="fas fa-user-tie me-2"></i> Client Information
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted text-uppercase">PO Number</label>
                                    <input type="text" name="po_number" class="form-control bg-light fw-bold text-success" value="<?php echo $display_po_number; ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Client Name</label>
                                    <input type="text" name="client_name" id="clientName" class="form-control" value="<?php echo $client_name_val; ?>" placeholder="Agency / Company Name" required>
                                </div>
                                
                                <div class="mb-3 mt-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Attach Quotation File</label>
                                    <input type="file" name="po_document" class="form-control form-control-sm" accept=".pdf,.png,.jpg,.jpeg">
                                    <small class="text-muted" style="font-size: 0.70rem;">(Optional) This will be visible in the approval flow.</small>
                                </div>

                                <hr>
                                <div class="alert alert-primary mb-0 border-0 bg-primary bg-opacity-10 text-primary">
                                    <small><i class="fas fa-calculator me-1"></i> <strong>Grand Total</strong> is calculated automatically.</small>
                                </div>
                                <input type="hidden" name="grand_total" id="hiddenGrandTotal">
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                                <span class="fw-bold text-dark"><i class="fas fa-boxes me-2 text-primary"></i> Order Items</span>
                                <button type="button" class="btn btn-sm btn-primary shadow-sm" onclick="addItemRow()">
                                    <i class="fas fa-plus me-1"></i> Add Item Row
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0 align-middle" id="itemsTable">
                                        <thead class="bg-light text-secondary small text-uppercase">
                                            <tr>
                                                <th width="20%">Category & Brand</th>
                                                <th width="25%">Item Name & Specs</th>
                                                <th width="10%">Qty</th>
                                                <th width="15%">Unit Price</th>
                                                <th width="15%">Total</th>
                                                <th width="5%"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsBody">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-white text-end p-4">
                                <h5 class="text-muted mb-1">Total Amount</h5>
                                <h2 class="fw-bold text-primary m-0" id="displayGrandTotal">₱ 0.00</h2>
                                
                                <div id="dynamicWarnings" class="mt-2 d-none">
                                    <div id="warnQty" class="text-danger warning-text fw-bold d-none">
                                        <i class="fas fa-exclamation-circle me-1"></i> Note: Quantity exceeds the original PR request.
                                    </div>
                                    <div id="warnBudget" class="text-danger warning-text fw-bold d-none">
                                        <i class="fas fa-exclamation-circle me-1"></i> Note: Total amount exceeds the approved PR budget (₱ <?php echo number_format($pr_amount_val, 2); ?>).
                                    </div>
                                </div>
                                
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-4 mb-5">
                    <a href="po_list.php" class="btn btn-light px-4 border">Cancel</a>
                    <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                        Submit Order <i class="fas fa-paper-plane ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let itemIndex = 0;
        const prefilledItems = <?php echo $pr_items_json; ?>;
        const originalPrAmount = <?php echo json_encode($pr_amount_val); ?>;
        
        function addItemRow(data = null) {
            const tbody = document.getElementById('itemsBody');
            const row = tbody.insertRow();
            
            const cat = data ? data.category : '';
            const brand = data ? data.brand : 'Generic/Other';
            const name = data ? data.name : '';
            const specs = data ? data.specs : '';
            const qty = data ? data.qty : 1;
            const price = data ? parseFloat(data.price).toFixed(2) : '';
            
            const origQtyAttr = data ? `data-orig-qty="${data.qty}"` : `data-orig-qty="0"`;
            
            row.innerHTML = `
                <td class="bg-light">
                    <select name="items[${itemIndex}][category]" class="form-select form-select-sm mb-2" required>
                        <option value="" disabled ${!cat ? 'selected' : ''}>Select Category...</option>
                        <option value="01" ${cat == '01' ? 'selected' : ''}>1 - Hardware</option>
                        <option value="02" ${cat == '02' ? 'selected' : ''}>2 - CCTVs</option>
                        <option value="03" ${cat == '03' ? 'selected' : ''}>3 - Peripherals</option>
                        <option value="04" ${cat == '04' ? 'selected' : ''}>4 - Office Supplies</option>
                        <option value="05" ${cat == '05' ? 'selected' : ''}>5 - WIFI / LAN</option>
                        <option value="06" ${cat == '06' ? 'selected' : ''}>6 - Printers</option>
                    </select>
                    <select name="items[${itemIndex}][brand]" class="form-select form-select-sm text-muted brand-select">
                        <option value="Generic/Other">Select Brand (Optional)...</option>
                        <option value="Lenovo">Lenovo</option>
                        <option value="HP">HP</option>
                        <option value="Dell">Dell</option>
                        <option value="Acer">Acer</option>
                        <option value="ASUS">ASUS</option>
                        <option value="Apple">Apple</option>
                        <option value="Epson">Epson</option>
                        <option value="Brother">Brother</option>
                        <option value="Canon">Canon</option>
                        <option value="Hikvision">Hikvision</option>
                        <option value="Dahua">Dahua</option>
                        <option value="Ubiquiti">Ubiquiti</option>
                        <option value="Logitech">Logitech</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][name]" class="form-control form-control-sm mb-2 fw-bold" placeholder="Item Name" value="${name}" required>
                    <textarea name="items[${itemIndex}][specs]" class="form-control form-control-sm" rows="2" placeholder="Specs..." style="font-size: 0.85rem;">${specs}</textarea>
                </td>
                <td>
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control form-control-sm text-center qty-input" 
                        value="${qty}" min="1" step="1" ${origQtyAttr}
                        oninput="this.value = this.value.replace(/[^0-9]/g, ''); calculateRow(this);" required>
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white">₱</span>
                        <input type="number" step="0.01" min="0.01" name="items[${itemIndex}][price]" class="form-control price-input" 
                            placeholder="0.00" value="${price}" oninput="calculateRow(this)" onkeypress="return isNumberKey(event)" required>
                    </div>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm bg-light text-end fw-bold total-display border-0" value="0.00" readonly>
                    <input type="hidden" name="items[${itemIndex}][total]" class="total-input" value="0">
                </td>
                <td class="text-center align-middle">
                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeRow(this)"><i class="fas fa-trash-alt"></i></button>
                </td>
            `;
            
            if (data && data.brand) {
                const brandSelect = row.querySelector('.brand-select');
                brandSelect.value = data.brand;
                if (!brandSelect.value) {
                    brandSelect.value = 'Generic/Other'; 
                }
            } else {
                row.querySelector('.brand-select').value = 'Generic/Other';
            }

            const newPriceInput = row.querySelector('.price-input');
            calculateRow(newPriceInput);
            
            itemIndex++;
        }
        
        function isNumberKey(evt) {
            var charCode = (evt.which) ? evt.which : evt.keyCode;
            if (charCode != 46 && charCode > 31 && (charCode < 48 || charCode > 57))
                return false;
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
            let grandTotal = 0;
            let qtyExceeded = false;
            
            document.querySelectorAll('.total-input').forEach(input => {
                grandTotal += parseFloat(input.value) || 0;
            });
            
            document.querySelectorAll('.qty-input').forEach(input => {
                const origQty = parseInt(input.getAttribute('data-orig-qty')) || 0;
                const currentQty = parseInt(input.value) || 0;
                if (origQty > 0 && currentQty > origQty) {
                    qtyExceeded = true;
                }
            });

            document.getElementById('displayGrandTotal').innerText = '₱ ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('hiddenGrandTotal').value = grandTotal;
            
            checkWarnings(grandTotal, qtyExceeded);
        }
        
        function checkWarnings(grandTotal, qtyExceeded) {
            const warningBox = document.getElementById('dynamicWarnings');
            const warnQty = document.getElementById('warnQty');
            const warnBudget = document.getElementById('warnBudget');
            let showWarnings = false;

            if (qtyExceeded) {
                warnQty.classList.remove('d-none');
                showWarnings = true;
            } else {
                warnQty.classList.add('d-none');
            }

            if (originalPrAmount > 0 && grandTotal > originalPrAmount) {
                warnBudget.classList.remove('d-none');
                showWarnings = true;
            } else {
                warnBudget.classList.add('d-none');
            }

            if (showWarnings) {
                warningBox.classList.remove('d-none');
            } else {
                warningBox.classList.add('d-none');
            }
        }
        
        function removeRow(btn) {
            btn.closest('tr').remove();
            calculateGrandTotal();
        }
        
        window.onload = function() {
            if (prefilledItems.length > 0) {
                prefilledItems.forEach(item => addItemRow(item));
            } else {
                addItemRow();
            }
        };
    </script>
</body>
</html>