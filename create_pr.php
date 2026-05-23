<?php
require 'config/db_connect.php';
require 'config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales Staff') {
    header("Location: dashboard.php");
    exit();
}

$quotation_id = isset($_GET['quotation_id']) ? intval($_GET['quotation_id']) : 0;
$q_data = null;
$q_items = [];

if ($quotation_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM quotations WHERE quotation_id = ? AND status = 'PO Received'");
    $stmt->bind_param("i", $quotation_id);
    $stmt->execute();
    $q_data = $stmt->get_result()->fetch_assoc();

    if ($q_data) {
        $item_stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ?");
        $item_stmt->bind_param("i", $quotation_id);
        $item_stmt->execute();
        $item_res = $item_stmt->get_result();
        while ($i_row = $item_res->fetch_assoc()) {
            $q_items[] = $i_row;
        }
    }
}

$year = date('Y');
$pr_prefix = "PR-" . $year . "-";
$pr_stmt = $conn->query("SELECT pr_number FROM purchase_requests ORDER BY pr_id DESC LIMIT 1");
$next_pr_num = ($pr_stmt->num_rows > 0) ? intval(substr($pr_stmt->fetch_assoc()['pr_number'], -4)) + 1 : 1;
$display_pr_number = $pr_prefix . str_pad($next_pr_num, 4, "0", STR_PAD_LEFT);

// BAGO: Category Map para maging detailed text ang display imbes na number codes lang
$category_map = [
    "01" => "1 - Hardware",
    "02" => "2 - CCTVs",
    "03" => "3 - Peripherals",
    "04" => "4 - Office Supplies",
    "05" => "5 - WIFI / LAN",
    "06" => "6 - Printers"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Create Purchase Request - Fixie DRMS</title>
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
                    <h2 class="fw-bold mb-0">Submit Purchase Request</h2>
                    <p class="text-muted mb-0">Review auto-filled items from Quotation before submitting PR.</p>
                </div>
            </div>

            <?php if(!$q_data): ?>
                <div class="card border-0 shadow-sm text-center py-5 mt-4">
                    <div class="card-body py-5">
                        <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-4 opacity-50"></i>
                        <h3 class="fw-bold text-dark">No Quotation Selected</h3>
                        <p class="text-muted mb-4">You must select a valid Quotation with a received Client PO from the tracker to generate a Purchase Request.</p>
                        <a href="quotations_list.php" class="btn btn-primary px-4 shadow-sm">
                            <i class="fas fa-arrow-left me-2"></i> Go to Quotations Tracker
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <form action="actions/pr_handler.php" method="POST">
                    <input type="hidden" name="action" value="create_pr">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="quotation_id" value="<?php echo $quotation_id; ?>">
                    <input type="hidden" name="amount" value="<?php echo $q_data['amount']; ?>">

                    <div class="row g-4">
                        <div class="col-lg-3">
                            <div class="card shadow-sm h-100 border-0">
                                <div class="card-header bg-white fw-bold py-3 text-primary">
                                    <i class="fas fa-info-circle me-2"></i> PR Info
                                </div>
                                <div class="card-body">
                                    <label class="small text-muted fw-bold">PR Number</label>
                                    <input type="text" name="pr_number" class="form-control bg-light fw-bold text-primary mb-3" value="<?php echo $display_pr_number; ?>" readonly>
                                    
                                    <label class="small text-muted fw-bold">Client Name</label>
                                    <input type="text" name="client_name" class="form-control bg-light mb-3" value="<?php echo htmlspecialchars($q_data['client_name']); ?>" readonly>
                                    
                                    <label class="small text-muted fw-bold">From Client PO / Tracker</label>
                                    <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($q_data['client_po_number']); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-9">
                            <div class="card shadow-sm h-100 border-0">
                                <div class="card-header bg-white py-3">
                                    <span class="fw-bold text-dark"></i>Items from Quotation</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light small">
                                                <tr>
                                                    <th>Category & Brand</th>
                                                    <th>Item Name & Specs</th>
                                                    <th class="text-center">Qty</th>
                                                    <th>Unit Price</th>
                                                    <th class="text-end pe-3">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody class="align-middle">
                                                <?php foreach($q_items as $idx => $item): ?>
                                                <?php 
                                                    // Convert category code to detailed string
                                                    $cat_code = $item['category'];
                                                    $cat_display = isset($category_map[$cat_code]) ? $category_map[$cat_code] : $cat_code; 
                                                ?>
                                                <tr>
                                                    <td class="bg-light" style="width: 25%;">
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][category]" value="<?php echo htmlspecialchars($cat_code); ?>">
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][brand]" value="<?php echo htmlspecialchars($item['brand']); ?>">
                                                        <?php echo htmlspecialchars($cat_display); ?><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['brand']); ?></small>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][name]" value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][specs]" value="<?php echo htmlspecialchars($item['specifications']); ?>">
                                                        <span class="fw-bold"><?php echo htmlspecialchars($item['item_name']); ?></span><br>
                                                        <small class="text-muted"><?php echo nl2br(htmlspecialchars($item['specifications'])); ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][qty]" value="<?php echo $item['quantity']; ?>">
                                                        <?php echo $item['quantity']; ?>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][price]" value="<?php echo $item['unit_price']; ?>">
                                                        ₱ <?php echo number_format($item['unit_price'], 2); ?>
                                                    </td>
                                                    <td class="bg-light fw-bold text-end pe-3">
                                                        <input type="hidden" name="items[<?php echo $idx; ?>][total]" value="<?php echo $item['total_price']; ?>">
                                                        ₱ <?php echo number_format($item['total_price'], 2); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="card-footer bg-white text-end p-4">
                                    <h5 class="text-muted mb-1">Total PR Amount</h5>
                                    <h2 class="fw-bold text-primary m-0">₱ <?php echo number_format($q_data['amount'], 2); ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 mt-4 mb-5">
                        <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">Submit<i class="fas fa-paper-plane ms-2"></i></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>