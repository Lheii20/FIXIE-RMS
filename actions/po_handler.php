<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { die("Unauthorized access."); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid Token");
    }

    $action = $_POST['action'] ?? 'upload';
    $user_id = $_SESSION['user_id'];

    function getRedirectUrl($conn, $doc_id = null, $po_id = null) {
        if ($po_id) {
            return "../view_po.php?id=" . $po_id;
        }
        if ($doc_id) {
            $stmt = $conn->prepare("SELECT po_id FROM documents WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['po_id']) {
                    return "../view_po.php?id=" . $row['po_id'];
                }
            }
        }
        return "../general_docs.php"; 
    }

    if ($action == 'create') {
        $client_name = trim($_POST['client_name']);
        $items = $_POST['items'] ?? [];
        $pr_id = isset($_POST['pr_id']) && !empty($_POST['pr_id']) ? intval($_POST['pr_id']) : null;
        
        if (empty($items)) {
            header("Location: ../create_po.php?error=PO Items Cannot Be Empty");
            exit();
        }

        // =================================================================================
        // BACKEND DEFINITIVE CALCULATION (SECURITY FIX)
        // =================================================================================
        $definitive_amount = 0;
        foreach ($items as &$item) {
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            
            if ($price <= 0 || $qty <= 0) {
                header("Location: ../create_po.php?error=Item quantity and price must be greater than zero.");
                exit();
            }

            $line_total = $qty * $price;
            $item['calculated_total'] = $line_total; 
            $definitive_amount += $line_total;
        }
        unset($item);

        // =================================================================================
        // SECURE TRANSACTION BLOCK PARA MAIWASAN ANG RACE CONDITION
        // =================================================================================
        try {
            $conn->begin_transaction();

            // 1. GENERATE NEXT PO NUMBER (Gamit ang FOR UPDATE row lock)
            $year = date('Y');
            $po_prefix = "PO-" . $year . "-";
            $like_prefix = $po_prefix . "%";
            
            $po_stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY CAST(SUBSTRING_INDEX(po_number, '-', -1) AS UNSIGNED) DESC LIMIT 1 FOR UPDATE");
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
            $po_number = $po_prefix . str_pad($next_po_num, 4, "0", STR_PAD_LEFT);

            // 2. GENERATE QUOTATION NUMBER (Gamit din ang FOR UPDATE lock)
            $base_category = '01'; 
            if (!empty($items) && isset($items[0]['category'])) {
                $base_category = $items[0]['category']; 
            }

            $qt_stmt = $conn->query("SELECT quotation_number FROM purchase_orders ORDER BY po_id DESC LIMIT 1 FOR UPDATE");
            $next_qt_num = 1;

            if($qt_stmt && $qt_stmt->num_rows > 0) {
                $last_qt = $qt_stmt->fetch_assoc()['quotation_number'];
                $parts = explode(' ', $last_qt);
                if(isset($parts[0])) {
                    $cat_seq = explode('-', $parts[0]);
                    if(isset($cat_seq[1])) {
                        $next_qt_num = intval($cat_seq[1]) + 1;
                    }
                }
            }
            $padded_qt_num = str_pad($next_qt_num, 4, "0", STR_PAD_LEFT);
            $quotation_number = $base_category . "-" . $padded_qt_num . " " . $client_name;

            // 3. EXECUTE MAIN PO INSERT
            $status = 'Pending';
            $location = 'Office of the GM';

            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, quotation_number, client_name, amount, status, current_location, created_by, pr_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssdssii", $po_number, $quotation_number, $client_name, $definitive_amount, $status, $location, $user_id, $pr_id);
            $stmt->execute();
            $po_id = $conn->insert_id;

            // 4. PROCESS PR AND LINE ITEMS
            if ($pr_id) {
                $conn->query("UPDATE purchase_requests SET status = 'Converted_to_PO' WHERE pr_id = $pr_id");
            }

            if (!empty($items)) {
                $item_stmt = $conn->prepare("INSERT INTO po_items (po_id, category, brand, item_name, specifications, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($items as $item) {
                    $item_stmt->bind_param("issssidd", 
                        $po_id, 
                        $item['category'], 
                        $item['brand'], 
                        $item['name'], 
                        $item['specs'], 
                        $item['qty'], 
                        $item['price'], 
                        $item['calculated_total']
                    );
                    $item_stmt->execute();
                }
            }

            // 5. UPDATE HISTORY AND NOTIFICATIONS
            $conn->query("INSERT INTO po_history (po_id, changed_by, status_from, status_to) VALUES ($po_id, $user_id, 'New', 'Pending')");
            $conn->query("INSERT INTO notifications (target_role, message) VALUES ('GM', 'New Purchase Order Requires Approval: PO #$po_id')");
            
            // 6. PROCESS ATTACHED QUOTATION FILE SECURELY
            if (isset($_FILES['po_document']) && !empty($_FILES['po_document']['name'])) {
                $file = $_FILES['po_document'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['pdf', 'png', 'jpg', 'jpeg'];
                
                if (in_array($ext, $allowed_ext)) {
                    $newFileName = time() . "_quote_" . bin2hex(random_bytes(4)) . "." . $ext;
                    $uploadDir = "../uploads/";
                    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
                    
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $dbPath = "uploads/" . $newFileName;
                        $fileHash = hash_file('sha256', $targetPath);
                        $doc_stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (?, 'Quotation', ?, ?, ?, ?, 'Active')");
                        $doc_stmt->bind_param("isssi", $po_id, $file['name'], $dbPath, $fileHash, $user_id);
                        $doc_stmt->execute();
                    }
                }
            }

            $conn->commit();

            log_audit_action($conn, $user_id, 'CREATE_PO', "Created new PO: $po_number mapped to PR ID: $pr_id with verified amount: ₱$definitive_amount");
            header("Location: ../po_list.php?success=PO Successfully Created!");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../create_po.php?error=Transaction failed. Please try again.");
            exit();
        }
    }

    $workflow_actions = ['approve_gm', 'approve_finance', 'approve_president', 'mark_funded', 'mark_delivered', 'reject', 'add_payment'];
    
    if (in_array($action, $workflow_actions)) {
        $po_id = intval($_POST['po_id']);
        
        $fetch_po = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_id = ?");
        $fetch_po->bind_param("i", $po_id);
        $fetch_po->execute();
        $po_res = $fetch_po->get_result();
        $actual_po_number = ($po_res->num_rows > 0) ? $po_res->fetch_assoc()['po_number'] : $po_id;
        $fetch_po->close();

        if ($action == 'reject') {
            if (!in_array($_SESSION['role'], ['GM', 'Finance', 'President'])) {
                die("Unauthorized Action: Only approvers can reject a Purchase Order.");
            }

            $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

            // =================================================================================
            // AUTO-PATCH: Siguraduhing may "remarks" column ang tables
            // =================================================================================
            $check_col = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'remarks'");
            if ($check_col && $check_col->num_rows == 0) {
                $conn->query("ALTER TABLE purchase_orders ADD COLUMN remarks TEXT NULL");
            }
            
            $check_hist_col = $conn->query("SHOW COLUMNS FROM po_history LIKE 'remarks'");
            if ($check_hist_col && $check_hist_col->num_rows == 0) {
                $conn->query("ALTER TABLE po_history ADD COLUMN remarks TEXT NULL");
            }

            // Kunin yung existing status bago siya gawing Rejected
            $stmt_old = $conn->prepare("SELECT status FROM purchase_orders WHERE po_id = ?");
            $stmt_old->bind_param("i", $po_id);
            $stmt_old->execute();
            $old_status = $stmt_old->get_result()->fetch_assoc()['status'] ?? 'Pending';

            $update = $conn->prepare("UPDATE purchase_orders SET status = 'Rejected', current_location = 'Voided', remarks = ? WHERE po_id = ?");
            $update->bind_param("si", $remarks, $po_id);
            if($update->execute()) {
                $hist = $conn->prepare("INSERT INTO po_history (po_id, changed_by, status_from, status_to, remarks) VALUES (?, ?, ?, 'Rejected', ?)");
                $hist->bind_param("iiss", $po_id, $user_id, $old_status, $remarks);
                $hist->execute();
                
                log_audit_action($conn, $user_id, 'REJECT_PO', "Rejected PO ID: $po_id ($actual_po_number). Reason: $remarks");
            }
            header("Location: ../view_po.php?id=$po_id&success=PO Rejected");
            exit();
        }

        $new_status = "";
        $new_loc = "";
        $notif_role = "";
        $notif_msg = "";

        if ($action == 'approve_gm' && $_SESSION['role'] == 'GM') {
            $new_status = 'GM-Approved';
            $new_loc = 'Finance Dept.';
            $notif_role = 'Finance';
            $notif_msg = "New PO Requires Validation: PO #$po_id";
        } elseif ($action == 'approve_finance' && $_SESSION['role'] == 'Finance') {
            $new_status = 'Finance-Approved';
            $new_loc = 'Office of the President';
            $notif_role = 'President';
            $notif_msg = "New PO Requires Sign-off: PO #$po_id";
        } elseif ($action == 'approve_president' && $_SESSION['role'] == 'President') {
            $new_status = 'President-Approved';
            $new_loc = 'Finance Dept.';
            $notif_role = 'Finance';
            $notif_msg = "PO Approved by President. Ready for Funding: PO #$po_id";
        } elseif ($action == 'mark_funded' && $_SESSION['role'] == 'Finance') {
            $new_status = 'Funded';
            $new_loc = 'Supply Chain Dept.';
            $notif_role = 'Supply Chain';
            $notif_msg = "PO Funded. Ready for Delivery: PO #$po_id";
        } elseif ($action == 'mark_delivered' && $_SESSION['role'] == 'Supply Chain') {
            $new_status = 'Delivered';
            $new_loc = 'Finance Dept. (Collection)';
            $notif_role = 'Finance';
            $notif_msg = "PO Delivered. Awaiting Collection: PO #$po_id";
        }

        if ($new_status != "") {
            $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE po_id = ?");
            $stmt->bind_param("i", $po_id);
            $stmt->execute();
            $old_status = $stmt->get_result()->fetch_assoc()['status'];

            $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, is_viewed = 0 WHERE po_id = ?");
            $update->bind_param("ssi", $new_status, $new_loc, $po_id);
            if ($update->execute()) {
                $hist = $conn->prepare("INSERT INTO po_history (po_id, changed_by, status_from, status_to) VALUES (?, ?, ?, ?)");
                $hist->bind_param("iiss", $po_id, $user_id, $old_status, $new_status);
                $hist->execute();

                if ($notif_role != "") {
                    $conn->query("INSERT INTO notifications (target_role, message) VALUES ('$notif_role', '$notif_msg')");
                }
                
                log_audit_action($conn, $user_id, 'APPROVE_PO', "Advanced PO $actual_po_number to $new_status");
            }
            header("Location: ../view_po.php?id=$po_id&success=PO Updated Successfully");
            exit();
        }
        
        if ($action == 'add_payment') {
            if ($_SESSION['role'] != 'Finance') {
                header("Location: ../view_po.php?id=$po_id&error=Unauthorized: Only Finance can add payments");
                exit();
            }

            $amount_paid = floatval($_POST['amount_paid']);
            $payment_date = $_POST['payment_date'];
            $notes = $_POST['payment_notes'] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO payments (po_id, amount_paid, payment_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("idss", $po_id, $amount_paid, $payment_date, $notes);
            
            if($stmt->execute()) {
                $check = $conn->query("SELECT amount, (SELECT SUM(amount_paid) FROM payments WHERE po_id = purchase_orders.po_id) as paid FROM purchase_orders WHERE po_id = $po_id")->fetch_assoc();
                $balance = $check['amount'] - $check['paid'];
                
                $new_status = ($balance <= 1.00) ? 'Collected' : 'Partially-Collected';
                
                $update_po = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = 'Finance Dept. (Collection)' WHERE po_id = ?");
                $update_po->bind_param("si", $new_status, $po_id);
                $update_po->execute();
                
                log_audit_action($conn, $user_id, 'ADD_PAYMENT', "Added payment of P$amount_paid to PO $actual_po_number");
                header("Location: ../view_po.php?id=$po_id&success=Payment Successfully Recorded");
            } else {
                header("Location: ../view_po.php?id=$po_id&error=DatabaseError");
            }
            exit();
        }
        
        header("Location: ../view_po.php?id=$po_id&error=Action not processed or unauthorized.");
        exit();
    }

    if ($action == 'archive') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);

        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Archived' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'ARCHIVE_FILE', $doc_id, "Archived document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'ARCHIVE_FILE', "Archived document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Archived");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
        }
        exit();
    }

    if ($action == 'restore') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);
        
        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'RESTORE_FILE', $doc_id, "Restored document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Restored");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
        }
        exit();
    }

    if ($action == 'delete') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);
        
        $stmt = $conn->prepare("SELECT file_path, file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($res->num_rows > 0) {
            $file = $res->fetch_assoc();
            
            $fixedUploadDir = "../uploads/";
            $safeFileName = basename($file['file_name']); 
            $physicalPath = $fixedUploadDir . $safeFileName;

            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
            
            $del = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
            $del->bind_param("i", $doc_id);
            $del->execute();
            
            $desc = "Deleted file: " . $file['file_name'];
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'DELETE_FILE', $doc_id, $desc, $redirectUrl);
            } else {
                log_audit_action($conn, $user_id, 'DELETE_FILE', $desc);
            }
        }
        
        header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Deleted");
        exit();
    }

    if ($action == 'upload' || isset($_FILES['document'])) {
        $po_id = isset($_POST['po_id']) && !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
        $doc_type = $_POST['doc_type'] ?? 'General'; 
        $file = $_FILES['document'];

        $redirectUrl = getRedirectUrl($conn, null, $po_id);

        $max_file_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_file_size) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=FileSizeExceeded");
            exit();
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeTypes)) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidFileExtension");
            exit();
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->file($file['tmp_name']);
        if (!array_key_exists($fileMimeType, $allowedMimeTypes)) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidFileTypeSecurity");
            exit();
        }

        $fileHash = hash_file('sha256', $file['tmp_name']);
        $checkStmt = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
        $checkStmt->bind_param("s", $fileHash);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DuplicateFileDetected");
            exit();
        }

        $uploadDir = "../uploads/"; 
        $dbDir = "uploads/";        

        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $newFileName = time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $targetPath = $uploadDir . $newFileName; 
        $dbPath = $dbDir . $newFileName;         

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            if ($po_id === null) {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (NULL, ?, ?, ?, ?, ?, 'Active')");
                $stmt->bind_param("ssssi", $doc_type, $newFileName, $dbPath, $fileHash, $user_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                $stmt->bind_param("issssi", $po_id, $doc_type, $newFileName, $dbPath, $fileHash, $user_id);
            }
            
            if($stmt->execute()) {
                log_audit_action($conn, $user_id, 'UPLOAD', "Uploaded file: $newFileName");
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=UploadSuccess");
            } else {
                 header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
            }
        } else {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UploadFailed");
        }
    }
}
?>