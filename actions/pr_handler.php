<?php
session_start();
require '../config/db_connect.php';

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
            $stmt = $conn->prepare("INSERT INTO purchase_requests (pr_number, client_name, amount, status, created_by) VALUES (?, ?, ?, 'Pending', ?)");
            $stmt->bind_param("ssdi", $pr_number, $client_name, $amount, $created_by);
            $stmt->execute();
            $pr_id = $conn->insert_id;

            $item_stmt = $conn->prepare("INSERT INTO pr_items (pr_id, category, brand, item_name, specifications, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $item) {
                $cat = trim($item['category'] ?? '');
                $brand = trim($item['brand'] ?? 'Generic/Other');
                $name = trim($item['name'] ?? '');
                $specs = trim($item['specs'] ?? '');
                $qty = (int)($item['qty'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $total = (float)($item['total'] ?? 0);
                
                $item_stmt->bind_param("issssidd", $pr_id, $cat, $brand, $name, $specs, $qty, $price, $total);
                $item_stmt->execute();
            }

            // Update Quotation Status to Converted to PR
            $conn->query("UPDATE quotations SET status = 'Converted to PR' WHERE quotation_id = $quotation_id");

            $conn->query("INSERT INTO notifications (target_role, message) VALUES ('GM', 'New Purchase Request Needs Approval: $pr_number')");
            $conn->query("INSERT INTO notifications (target_role, message) VALUES ('President', 'New Purchase Request Needs Approval: $pr_number')");

            $conn->commit();
            header("Location: ../pr_list.php?success=Purchase Request Created Successfully");
            
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../create_pr.php?error=DatabaseError");
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

        $conn->query("INSERT INTO notifications (target_role, message) VALUES ('Procurement', 'PR $pr_number is Approved. Ready for PO Conversion.')");
        $conn->query("INSERT INTO notifications (target_role, message) VALUES ('Sales Staff', 'Your PR $pr_number has been Approved by Management.')");

        header("Location: ../view_pr.php?id=$pr_id&success=PR Approved Successfully");
        exit();
    }

    if ($action == 'reject_pr') {
        if (!in_array($_SESSION['role'], ['GM', 'President'])) {
            die("Unauthorized Action: Only GM or President can reject PRs.");
        }
        
        $pr_id = intval($_POST['pr_id']);
        
        // Status Validation Before Rejecting
        $status_check = $conn->query("SELECT status, pr_number FROM purchase_requests WHERE pr_id = $pr_id")->fetch_assoc();
        if ($status_check['status'] !== 'Pending') {
            header("Location: ../view_pr.php?id=$pr_id&error=PR is already processed.");
            exit();
        }
        
        $conn->query("UPDATE purchase_requests SET status = 'Rejected' WHERE pr_id = $pr_id");
        $pr_number = $status_check['pr_number'];

        $conn->query("INSERT INTO notifications (target_role, message) VALUES ('Sales Staff', 'Your PR $pr_number was Rejected by Management.')");

        header("Location: ../view_pr.php?id=$pr_id&success=PR Rejected");
        exit();
    }
}

header("Location: ../dashboard.php");
exit();
?>