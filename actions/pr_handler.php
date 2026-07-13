<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Strict CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid CSRF Token");
    }

    $action = $_POST['action'];

    if ($action == 'create_pr') {
        if ($_SESSION['role'] !== 'Sales Staff') {
            header("Location: ../quotations_list.php?error=Only Sales Staff can create a Purchase Request.");
            exit();
        }

        $pr_number = trim($_POST['pr_number']);
        $client_name = trim($_POST['client_name']);
        $amount = floatval($_POST['amount']);
        $created_by = $_SESSION['user_id'];
        $items = $_POST['items'] ?? [];
        $quotation_id = isset($_POST['quotation_id']) ? intval($_POST['quotation_id']) : 0;

        if ($quotation_id === 0) {
             header("Location: ../create_pr.php?error=You must select an existing Quotation with a Client PO.");
             exit();
        }

        // Empty Items Validation
        if (empty($items)) {
            header("Location: ../create_pr.php?error=ItemsListCannotBeEmpty");
            exit();
        }

        // Price Zero Validation
        foreach ($items as $item) {
            if (floatval($item['price']) <= 0) {
                header("Location: ../create_pr.php?error=Item price cannot be zero or less.");
                exit();
            }
        }

        // Server-Side Calculation Validation
        $calculated_total = 0;
        foreach ($items as $item) {
            $qty = (int)($item['qty'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $calculated_total += ($qty * $price);
        }

        if (abs($amount - $calculated_total) > 0.01) {
            header("Location: ../create_pr.php?error=AmountCalculationMismatch");
            exit();
        }

        $conn->begin_transaction();
        try {
            // The PR must originate from one client-approved quotation only.
            $quote_stmt = $conn->prepare("SELECT quotation_number, client_name, amount, status FROM quotations WHERE quotation_id = ? FOR UPDATE");
            $quote_stmt->bind_param("i", $quotation_id);
            $quote_stmt->execute();
            $quote = $quote_stmt->get_result()->fetch_assoc();

            if (!$quote || $quote['status'] !== 'PO Received') {
                throw new Exception('The selected quotation is not client-approved or was already converted.');
            }

            $existing_pr_stmt = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE quotation_id = ? LIMIT 1 FOR UPDATE");
            $existing_pr_stmt->bind_param("i", $quotation_id);
            $existing_pr_stmt->execute();
            if ($existing_pr_stmt->get_result()->num_rows > 0) {
                throw new Exception('A Purchase Request already exists for this quotation.');
            }

            // Client name and quoted total are taken from the approved quotation, not the browser form.
            $client_name = $quote['client_name'];
            if (abs((float) $quote['amount'] - $amount) > 0.01) {
                throw new Exception('The PR amount no longer matches the approved quotation.');
            }

            // Copy line items directly from the database source to prevent hidden form values from being altered.
            $source_items_stmt = $conn->prepare("SELECT category, brand, item_name, specifications, quantity, unit_price, total_price FROM quotation_items WHERE quotation_id = ?");
            $source_items_stmt->bind_param("i", $quotation_id);
            $source_items_stmt->execute();
            $source_items_result = $source_items_stmt->get_result();
            $source_items = [];
            $source_total = 0;
            while ($source_item = $source_items_result->fetch_assoc()) {
                $source_items[] = $source_item;
                $source_total += (float) $source_item['total_price'];
            }
            if (empty($source_items) || abs($source_total - (float) $quote['amount']) > 0.01) {
                throw new Exception('The source quotation items do not match its approved total.');
            }

            $stmt = $conn->prepare("INSERT INTO purchase_requests (pr_number, quotation_id, client_name, amount, status, created_by) VALUES (?, ?, ?, ?, 'Pending', ?)");
            $stmt->bind_param("sisdi", $pr_number, $quotation_id, $client_name, $amount, $created_by);
            $stmt->execute();
            $pr_id = $conn->insert_id;

            $item_stmt = $conn->prepare("INSERT INTO pr_items (pr_id, category, brand, item_name, specifications, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($source_items as $item) {
                $cat = trim($item['category'] ?? '');
                $brand = trim($item['brand'] ?? 'Generic/Other');
                $name = trim($item['item_name'] ?? '');
                $specs = trim($item['specifications'] ?? '');
                $qty = (int)($item['quantity'] ?? 1);
                $price = (float)($item['unit_price'] ?? 0);
                $total = (float)($item['total_price'] ?? 0);
                
                $item_stmt->bind_param("issssidd", $pr_id, $cat, $brand, $name, $specs, $qty, $price, $total);
                $item_stmt->execute();
            }

            // Convert only the locked client-approved quotation.
            $quote_update = $conn->prepare("UPDATE quotations SET status = 'Converted to PR' WHERE quotation_id = ? AND status = 'PO Received'");
            $quote_update->bind_param("i", $quotation_id);
            $quote_update->execute();
            if ($quote_update->affected_rows !== 1) {
                throw new Exception('The quotation status changed before the PR could be saved.');
            }

            create_role_notification($conn, 'GM', "New Purchase Request Needs Approval: $pr_number");
            create_role_notification($conn, 'President', "New Purchase Request Needs Approval: $pr_number");

            log_audit_action($conn, $created_by, 'CREATE_PR', "Created PR $pr_number from quotation {$quote['quotation_number']}", null, [
                'pr_number' => $pr_number,
                'quotation_id' => $quotation_id,
                'quotation_number' => $quote['quotation_number'],
                'amount' => $amount
            ]);

            $conn->commit();
            header("Location: ../pr_list.php?success=Purchase Request Created Successfully");
             
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../create_pr.php?quotation_id=$quotation_id&error=" . rawurlencode($e->getMessage()));
        }
        exit();
    }

    if ($action == 'approve_pr') {
        if (!in_array($_SESSION['role'], ['GM', 'President'])) {
            die("Unauthorized Action: Only GM or President can approve PRs.");
        }
        
        $pr_id = intval($_POST['pr_id']);
        
        // Status Validation Before Approving
        $status_check = $conn->query("SELECT status, pr_number FROM purchase_requests WHERE pr_id = $pr_id")->fetch_assoc();
        if ($status_check['status'] !== 'Pending') {
            header("Location: ../view_pr.php?id=$pr_id&error=PR is already processed.");
            exit();
        }
        
        $conn->query("UPDATE purchase_requests SET status = 'Approved' WHERE pr_id = $pr_id");
        $pr_number = $status_check['pr_number'];

        log_audit_action($conn, $_SESSION['user_id'], 'APPROVE_PR', "Approved PR $pr_number", ['status' => 'Pending'], ['status' => 'Approved']);

        create_role_notification($conn, 'Procurement', "PR $pr_number is Approved. Ready for PO Conversion.");
        create_role_notification($conn, 'Sales Staff', "Your PR $pr_number has been Approved by Management.");

        header("Location: ../view_pr.php?id=$pr_id&success=PR Approved Successfully");
        exit();
    }

    if ($action == 'reject_pr') {
        if (!in_array($_SESSION['role'], ['GM', 'President'])) {
            die("Unauthorized Action: Only GM or President can reject PRs.");
        }
        
        $pr_id = intval($_POST['pr_id']);
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        
        // Status Validation Before Rejecting
        $status_check = $conn->query("SELECT status, pr_number FROM purchase_requests WHERE pr_id = $pr_id")->fetch_assoc();
        if ($status_check['status'] !== 'Pending') {
            header("Location: ../view_pr.php?id=$pr_id&error=PR is already processed.");
            exit();
        }

        // =================================================================================
        // AUTO-PATCH: Siguraduhing may "remarks" column ang table
        // =================================================================================
        $check_col = $conn->query("SHOW COLUMNS FROM purchase_requests LIKE 'remarks'");
        if ($check_col && $check_col->num_rows == 0) {
            $conn->query("ALTER TABLE purchase_requests ADD COLUMN remarks TEXT NULL");
        }
        
        // Update request securely including the remarks
        $stmt_upd = $conn->prepare("UPDATE purchase_requests SET status = 'Rejected', remarks = ? WHERE pr_id = ?");
        $stmt_upd->bind_param("si", $remarks, $pr_id);
        $stmt_upd->execute();
        
        $pr_number = $status_check['pr_number'];

        // Record history securely
        $check_hist = $conn->query("SHOW TABLES LIKE 'pr_history'");
        if ($check_hist && $check_hist->num_rows > 0) {
            $check_hist_col = $conn->query("SHOW COLUMNS FROM pr_history LIKE 'remarks'");
            if ($check_hist_col && $check_hist_col->num_rows == 0) {
                $conn->query("ALTER TABLE pr_history ADD COLUMN remarks TEXT NULL");
            }

            $user_id = $_SESSION['user_id'];
            $hist_stmt = $conn->prepare("INSERT INTO pr_history (pr_id, changed_by, status_from, status_to, remarks) VALUES (?, ?, 'Pending', 'Rejected', ?)");
            if ($hist_stmt) {
                $hist_stmt->bind_param("iis", $pr_id, $user_id, $remarks);
                $hist_stmt->execute();
            }
        }

        // Escape para iwas error sa single quotes sa chat string
        $safe_remarks = $conn->real_escape_string($remarks);
        create_role_notification($conn, 'Sales Staff', "Your PR $pr_number was Rejected by Management. Reason: $safe_remarks");
        log_audit_action($conn, $_SESSION['user_id'], 'REJECT_PR', "Rejected PR $pr_number. Reason: $remarks", ['status' => 'Pending'], ['status' => 'Rejected', 'remarks' => $remarks]);

        header("Location: ../view_pr.php?id=$pr_id&success=PR Rejected");
        exit();
    }
}

header("Location: ../dashboard.php");
exit();
?>
