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
        if ($_SESSION['role'] !== 'Procurement') {
            header("Location: ../po_list.php?error=Only Procurement can create a Purchase Order.");
            exit();
        }

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

            // 2. Preserve the real source quotation. A PO must never invent a new quotation number.
            $quotation_number = 'Manual PO';
            if ($pr_id) {
                $pr_stmt = $conn->prepare(
                    "SELECT pr.pr_number, pr.client_name, pr.quotation_id, q.quotation_number AS source_quotation_number
                     FROM purchase_requests pr
                     LEFT JOIN quotations q ON q.quotation_id = pr.quotation_id
                     WHERE pr.pr_id = ? AND pr.status = 'Approved' FOR UPDATE"
                );
                $pr_stmt->bind_param("i", $pr_id);
                $pr_stmt->execute();
                $source_pr = $pr_stmt->get_result()->fetch_assoc();

                if (!$source_pr) {
                    throw new Exception('Only an approved Purchase Request can be converted to a PO.');
                }

                $client_name = $source_pr['client_name'];
                $quotation_number = !empty($source_pr['source_quotation_number'])
                    ? $source_pr['source_quotation_number']
                    : 'PR ' . $source_pr['pr_number'];
            }

            // 3. EXECUTE MAIN PO INSERT
            $status = 'Pending';
            $location = 'Office of the GM';

            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, quotation_number, client_name, amount, status, current_location, created_by, pr_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssdssii", $po_number, $quotation_number, $client_name, $definitive_amount, $status, $location, $user_id, $pr_id);
            $stmt->execute();
            $po_id = $conn->insert_id;

            // 4. PROCESS PR AND LINE ITEMS
            if ($pr_id) {
                $pr_update = $conn->prepare("UPDATE purchase_requests SET status = 'Converted_to_PO' WHERE pr_id = ? AND status = 'Approved'");
                $pr_update->bind_param("i", $pr_id);
                $pr_update->execute();
                if ($pr_update->affected_rows !== 1) {
                    throw new Exception('The Purchase Request was already processed.');
                }
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
            create_role_notification($conn, 'GM', "New Purchase Order Requires Approval: PO #$po_id");
            
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

    // Claim/release preserves shared visibility, while establishing one accountable owner.
    if (in_array($action, ['claim_task', 'release_task'], true)) {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if ($po_id < 1) {
            header("Location: ../po_list.php?error=Invalid Purchase Order.");
            exit();
        }
        try {
            $conn->begin_transaction();
            if ($action === 'claim_task') {
                $assignment = claim_po_task($conn, $po_id, $user_id, $_SESSION['role']);
                $message = 'Task claimed successfully.';
                $audit = "Claimed PO task #$po_id as {$assignment['assigned_role']}";
            } else {
                release_po_task($conn, $po_id, $user_id);
                $message = 'Task released back to the shared queue.';
                $audit = "Released PO task #$po_id";
            }
            log_audit_action($conn, $user_id, strtoupper($action), $audit);
            $conn->commit();
            header("Location: ../view_po.php?id=$po_id&success=" . rawurlencode($message));
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../view_po.php?id=$po_id&error=" . rawurlencode($e->getMessage()));
        }
        exit();
    }

    $workflow_actions = ['approve_gm', 'approve_finance', 'approve_president', 'mark_funded', 'mark_delivered', 'reject', 'add_payment'];

    if (in_array($action, $workflow_actions, true)) {
        $po_id = intval($_POST['po_id'] ?? 0);
        if ($po_id < 1) {
            header("Location: ../po_list.php?error=Invalid Purchase Order.");
            exit();
        }

        // Payment is handled separately because it has financial validation and evidence requirements.
        if ($action === 'add_payment') {
            if ($_SESSION['role'] !== 'Finance') {
                header("Location: ../view_po.php?id=$po_id&error=Only Finance can record payments.");
                exit();
            }

            $amount_paid = round((float) ($_POST['amount_paid'] ?? 0), 2);
            $payment_method = trim($_POST['payment_method'] ?? '');
            $reference_number = trim($_POST['reference_number'] ?? '');
            $payment_date_input = trim($_POST['payment_date'] ?? '');
            $allowed_methods = ['Cash', 'Bank Transfer', 'GCash', 'Cheque', 'Other'];
            $payment_date = DateTime::createFromFormat('Y-m-d\\TH:i', $payment_date_input);

            if ($amount_paid <= 0 || !in_array($payment_method, $allowed_methods, true) || $reference_number === '' || strlen($reference_number) > 100 || !$payment_date || $payment_date->format('Y-m-d\\TH:i') !== $payment_date_input || $payment_date > new DateTime()) {
                header("Location: ../view_po.php?id=$po_id&error=Enter a valid amount, payment method, reference number, and non-future payment date.");
                exit();
            }

            if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['payment_proof']['tmp_name'])) {
                header("Location: ../view_po.php?id=$po_id&error=Payment proof is required.");
                exit();
            }

            $proof = $_FILES['payment_proof'];
            $proof_ext = strtolower(pathinfo($proof['name'], PATHINFO_EXTENSION));
            $allowed_proofs = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $proof_mime = (new finfo(FILEINFO_MIME_TYPE))->file($proof['tmp_name']);
            if ($proof['size'] < 1 || $proof['size'] > 10 * 1024 * 1024 || !isset($allowed_proofs[$proof_ext]) || $proof_mime !== $allowed_proofs[$proof_ext]) {
                header("Location: ../view_po.php?id=$po_id&error=Payment proof must be a valid PDF, JPG, or PNG file up to 10 MB.");
                exit();
            }

            $payment_dir = __DIR__ . '/../uploads/payments/';
            $proof_file_path = null;

            try {
                if (!is_dir($payment_dir) && !mkdir($payment_dir, 0755, true) && !is_dir($payment_dir)) {
                    throw new Exception('Unable to create the payment-proof folder.');
                }

                $conn->begin_transaction();
                $po_stmt = $conn->prepare("SELECT po_number, amount, status FROM purchase_orders WHERE po_id = ? FOR UPDATE");
                $po_stmt->bind_param("i", $po_id);
                $po_stmt->execute();
                $po = $po_stmt->get_result()->fetch_assoc();

                if (!$po || !in_array($po['status'], ['Delivered', 'Partially-Collected'], true)) {
                    throw new Exception('Payments can only be recorded after delivery.');
                }
                enforce_po_task_ownership($conn, $po_id, $user_id, $_SESSION['role']);

                $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE po_id = ?");
                $paid_stmt->bind_param("i", $po_id);
                $paid_stmt->execute();
                $total_paid = (float) $paid_stmt->get_result()->fetch_assoc()['total_paid'];
                $balance = round((float) $po['amount'] - $total_paid, 2);

                if ($balance <= 0.01 || $amount_paid > $balance + 0.01) {
                    throw new Exception('The payment amount cannot exceed the remaining balance.');
                }

                $proof_file_path = time() . '_payment_' . bin2hex(random_bytes(8)) . '.' . $proof_ext;
                if (!move_uploaded_file($proof['tmp_name'], $payment_dir . $proof_file_path)) {
                    throw new Exception('The payment proof could not be saved.');
                }

                $payment_datetime = $payment_date->format('Y-m-d H:i:s');
                $notes = $amount_paid >= $balance - 0.01 ? 'Full Payment' : 'Partial Payment';
                $payment_stmt = $conn->prepare("INSERT INTO payments (po_id, amount_paid, payment_date, notes, recorded_by, payment_method, reference_number, proof_file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $payment_stmt->bind_param("idssisss", $po_id, $amount_paid, $payment_datetime, $notes, $user_id, $payment_method, $reference_number, $proof_file_path);
                $payment_stmt->execute();

                $new_balance = round($balance - $amount_paid, 2);
                $new_status = $new_balance <= 0.01 ? 'Collected' : 'Partially-Collected';
                $po_update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = 'Finance Dept. (Collection)' WHERE po_id = ? AND status = ?");
                $po_update->bind_param("sis", $new_status, $po_id, $po['status']);
                $po_update->execute();
                if ($po_update->affected_rows !== 1) {
                    throw new Exception('The PO status changed before the payment could be saved.');
                }
                if ($new_status === 'Collected') {
                    complete_po_task_assignment($conn, $po_id, $user_id, 'Collection completed');
                }

                log_audit_action($conn, $user_id, 'ADD_PAYMENT', "Recorded $notes of P$amount_paid for PO {$po['po_number']}", ['status' => $po['status']], ['status' => $new_status, 'reference_number' => $reference_number]);
                $conn->commit();
                header("Location: ../view_po.php?id=$po_id&success=Payment Successfully Recorded");
            } catch (Exception $e) {
                $conn->rollback();
                if ($proof_file_path && is_file($payment_dir . $proof_file_path)) {
                    unlink($payment_dir . $proof_file_path);
                }
                header("Location: ../view_po.php?id=$po_id&error=" . rawurlencode($e->getMessage()));
            }
            exit();
        }

        $delivery_proof = null;
        $delivery_proof_ext = null;
        $delivery_stored_path = null;
        if ($action === 'mark_delivered') {
            if (!isset($_FILES['delivery_proof']) || $_FILES['delivery_proof']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['delivery_proof']['tmp_name'])) {
                header("Location: ../view_po.php?id=$po_id&error=Proof of delivery is required before marking this PO as delivered.");
                exit();
            }
            $delivery_proof = $_FILES['delivery_proof'];
            $delivery_proof_ext = strtolower(pathinfo($delivery_proof['name'], PATHINFO_EXTENSION));
            $allowed_delivery_proofs = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $delivery_mime = (new finfo(FILEINFO_MIME_TYPE))->file($delivery_proof['tmp_name']);
            if ($delivery_proof['size'] < 1 || $delivery_proof['size'] > 10 * 1024 * 1024 || !isset($allowed_delivery_proofs[$delivery_proof_ext]) || $delivery_mime !== $allowed_delivery_proofs[$delivery_proof_ext]) {
                header("Location: ../view_po.php?id=$po_id&error=Delivery proof must be a valid PDF, JPG, or PNG file up to 10 MB.");
                exit();
            }
        }

        // Every approval/rejection must match a rule for both the current status and the logged-in role.
        try {
            $conn->begin_transaction();
            $po_stmt = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE po_id = ? FOR UPDATE");
            $po_stmt->bind_param("i", $po_id);
            $po_stmt->execute();
            $po = $po_stmt->get_result()->fetch_assoc();
            if (!$po) {
                throw new Exception('Purchase Order not found.');
            }

            $rule_stmt = $conn->prepare("SELECT next_status, notify_target FROM workflow_rules WHERE current_status = ? AND action_key = ? AND required_role = ? LIMIT 1");
            $rule_stmt->bind_param("sss", $po['status'], $action, $_SESSION['role']);
            $rule_stmt->execute();
            $rule = $rule_stmt->get_result()->fetch_assoc();
            if (!$rule) {
                throw new Exception('This action is not allowed for your role at the current PO status.');
            }
            enforce_po_task_ownership($conn, $po_id, $user_id, $_SESSION['role']);

            if ($action === 'mark_delivered') {
                $upload_dir = __DIR__ . '/../uploads/';
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    throw new Exception('Unable to create the delivery-proof folder.');
                }
                $delivery_file_name = time() . '_delivery_' . bin2hex(random_bytes(8)) . '.' . $delivery_proof_ext;
                $delivery_stored_path = $upload_dir . $delivery_file_name;
                if (!move_uploaded_file($delivery_proof['tmp_name'], $delivery_stored_path)) {
                    throw new Exception('The proof of delivery could not be saved.');
                }

                $delivery_hash = hash_file('sha256', $delivery_stored_path);
                $doc_type = 'Proof of Delivery';
                $db_delivery_path = 'uploads/' . $delivery_file_name;
                $delivery_doc_stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                $delivery_doc_stmt->bind_param("issssi", $po_id, $doc_type, $delivery_file_name, $db_delivery_path, $delivery_hash, $user_id);
                $delivery_doc_stmt->execute();
            }

            $location_map = [
                'approve_gm' => 'Finance Dept.',
                'approve_finance' => 'Office of the President',
                'approve_president' => 'Finance Dept.',
                'mark_funded' => 'Supply Chain Dept.',
                'mark_delivered' => 'Finance Dept. (Collection)',
                'reject' => 'Voided'
            ];
            $new_status = $rule['next_status'];
            $new_location = $location_map[$action] ?? $po['status'];
            $remarks = trim($_POST['remarks'] ?? '');

            if ($action === 'reject' && $remarks === '') {
                throw new Exception('A rejection reason is required.');
            }

            if ($action === 'reject') {
                $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, remarks = ?, is_viewed = 0 WHERE po_id = ? AND status = ?");
                $update->bind_param("sssis", $new_status, $new_location, $remarks, $po_id, $po['status']);
            } elseif ($action === 'mark_delivered') {
                $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, actual_delivery_date = NOW(), expected_collection_date = DATE_ADD(NOW(), INTERVAL 30 DAY), is_viewed = 0 WHERE po_id = ? AND status = ?");
                $update->bind_param("ssis", $new_status, $new_location, $po_id, $po['status']);
            } else {
                $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, is_viewed = 0 WHERE po_id = ? AND status = ?");
                $update->bind_param("ssis", $new_status, $new_location, $po_id, $po['status']);
            }
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new Exception('The PO status changed before the action could be saved.');
            }

            $history_stmt = $conn->prepare("INSERT INTO po_history (po_id, changed_by, status_from, status_to, remarks) VALUES (?, ?, ?, ?, ?)");
            $history_stmt->bind_param("iisss", $po_id, $user_id, $po['status'], $new_status, $remarks);
            $history_stmt->execute();

            if (!empty($rule['notify_target'])) {
                $notification = "PO {$po['po_number']} is now $new_status.";
                create_role_notification($conn, $rule['notify_target'], $notification);
            }

            complete_po_task_assignment($conn, $po_id, $user_id, 'Completed through ' . $action);

            $audit_action = $action === 'reject' ? 'REJECT_PO' : 'WORKFLOW_ACTION';
            log_audit_action($conn, $user_id, $audit_action, "PO {$po['po_number']}: {$po['status']} to $new_status", ['status' => $po['status']], ['status' => $new_status, 'remarks' => $remarks]);
            $conn->commit();
            header("Location: ../view_po.php?id=$po_id&success=PO Updated Successfully");
        } catch (Exception $e) {
            $conn->rollback();
            if ($delivery_stored_path && is_file($delivery_stored_path)) {
                unlink($delivery_stored_path);
            }
            header("Location: ../view_po.php?id=$po_id&error=" . rawurlencode($e->getMessage()));
        }
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
