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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create Quotation - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 1400px;">
            <div class="d-flex align-items-center mb-4">
                <a href="quotations_list.php" class="btn btn-light border me-3"><i class="fas fa-arrow-left"></i></a>
                <div>
                    <h2 class="fw-bold mb-0">Create Detailed Quotation</h2>
                    <p class="text-muted mb-0">Generate a quote with full item specifications for the client.</p>
                </div>
            </div>

            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <form action="actions/quotation_handler.php" method="POST" id="quotationForm">
                <input type="hidden" name="action" value="create_detailed_quotation">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

                <div class="row g-4">
                    <div class="col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white fw-bold text-primary py-3">
                                <i class="fas fa-file-invoice-dollar me-2"></i> Quotation Info
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Quotation Number</label>
                                    <input type="text" name="quotation_number" class="form-control bg-light fw-bold text-primary" value="<?php echo $display_q_number; ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Client / Agency Name</label>
                                    <input type="text" name="client_name" class="form-control" placeholder="Enter Company Name" required>
                                </div>
                                <hr>
                                <div class="alert alert-primary border-0 bg-primary bg-opacity-10 text-primary">
                                    <small><i class="fas fa-calculator me-1"></i> <strong>Grand Total</strong> is auto-calculated.</small>
                                </div>
                                <input type="hidden" name="amount" id="hiddenGrandTotal" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                                <span class="fw-bold text-dark"><i class="fas fa-boxes me-2 text-primary"></i> Quoted Items</span>
                                <button type="button" class="btn btn-sm btn-primary shadow-sm" onclick="addItemRow()">
                                    <i class="fas fa-plus me-1"></i> Add Item
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
                                        <tbody id="itemsBody"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-white text-end p-4">
                                <h5 class="text-muted mb-1">Total Amount</h5>
                                <h2 class="fw-bold text-primary m-0" id="displayGrandTotal">₱ 0.00</h2>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-4 mb-5">
                    <a href="quotations_list.php" class="btn btn-light px-4 border">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">Save & Track Quotation <i class="fas fa-save ms-2"></i></button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemIndex = 0;
        function addItemRow() {
            const tbody = document.getElementById('itemsBody');
            const row = tbody.insertRow();
            row.innerHTML = `
                <td class="bg-light">
                    <select name="items[${itemIndex}][category]" class="form-select form-select-sm mb-2" required>
                        <option value="" disabled selected>Category...</option>
                        <option value="01">1 - Hardware</option>
                        <option value="02">2 - CCTVs</option>
                        <option value="03">3 - Peripherals</option>
                        <option value="04">4 - Office Supplies</option>
                        <option value="05">5 - WIFI / LAN</option>
                        <option value="06">6 - Printers</option>
                    </select>
                    <select name="items[${itemIndex}][brand]" class="form-select form-select-sm text-muted">
                        <option value="Generic/Other" selected>Brand...</option>
                        <option value="Lenovo">Lenovo</option><option value="HP">HP</option>
                        <option value="Dell">Dell</option><option value="ASUS">ASUS</option>
                        <option value="Epson">Epson</option><option value="Hikvision">Hikvision</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="items[${itemIndex}][name]" class="form-control form-control-sm mb-2 fw-bold" placeholder="Item Name" required>
                    <textarea name="items[${itemIndex}][specs]" class="form-control form-control-sm" rows="2" placeholder="Specs..."></textarea>
                </td>
                <td>
                    <input type="number" name="items[${itemIndex}][qty]" class="form-control form-control-sm text-center qty-input" value="1" min="1" oninput="calculateRow(this);" required>
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white">₱</span>
                        <input type="number" step="0.01" min="1" name="items[${itemIndex}][price]" class="form-control price-input" oninput="calculateRow(this)" required>
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
            document.getElementById('displayGrandTotal').innerText = '₱ ' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('hiddenGrandTotal').value = grandTotal;
        }
        function removeRow(btn) { btn.closest('tr').remove(); calculateGrandTotal(); }
        window.onload = addItemRow;
    </script>
</body>
</html>