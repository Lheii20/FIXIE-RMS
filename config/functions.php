<?php

function e($string) {
    if ($string === null) return '';
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// 100% BULLETPROOF AUDIT LOG FUNCTION
function log_audit_action($conn, $user_id, $action, $description) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    
    // STEP 1: Sapilitang kunin ang pinakamataas na ID sa database (Para i-bypass ang missing Auto-Increment)
    $res = $conn->query("SELECT MAX(log_id) AS max_id FROM audit_logs");
    $next_id = 1; // Default ID kung walang laman ang table
    if ($res && $row = $res->fetch_assoc()) {
        $next_id = (int)$row['max_id'] + 1;
    }

    // STEP 2: Subukang i-insert kasama ang ginawa nating Manual ID
    $stmt = $conn->prepare("INSERT INTO audit_logs (log_id, user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt) {
        $stmt->bind_param("iisss", $next_id, $user_id, $action, $description, $ip);
        
        // Kung mag-fail pa rin (halimbawa: naka-lock ang auto-increment setup ng MariaDB mo)
        if (!$stmt->execute()) {
            // STEP 3: Fallback sa normal insert na walang log_id
            $stmt2 = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
            if ($stmt2) {
                $stmt2->bind_param("isss", $user_id, $action, $description, $ip);
                $stmt2->execute();
            }
        }
    } else {
        // Kung ma-block agad ng database ang Step 2 sa preparation pa lang, dederetso dito
        $stmt2 = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
        if ($stmt2) {
            $stmt2->bind_param("isss", $user_id, $action, $description, $ip);
            $stmt2->execute();
        }
    }
}

function get_user_by_username($conn, $username) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function get_all_users($conn) {
    return $conn->query("SELECT * FROM users ORDER BY created_at DESC");
}

function create_user($conn, $fullname, $username, $password, $role) {
    $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    if($check->get_result()->num_rows > 0) return "UsernameExists";

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role, status) VALUES (?, ?, ?, ?, 'Active')");
    $stmt->bind_param("ssss", $fullname, $username, $hash, $role);
    
    return $stmt->execute() ? "Success" : "Error";
}

function delete_user($conn, $user_id) {
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    return $stmt->execute();
}

function get_dashboard_stats($conn, $role) {
    $stats = [];
    if ($role == 'Procurement' || $role == 'GM') {
        $stats['label'] = 'Total Orders';
        $stats['value'] = $conn->query("SELECT COUNT(*) FROM purchase_orders")->fetch_row()[0];
    } elseif ($role == 'Finance') {
        $stats['label'] = 'Projected Collection';
        $res = $conn->query("SELECT SUM(amount) FROM purchase_orders WHERE expected_collection_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)");
        $stats['value'] = "₱ " . number_format($res->fetch_row()[0] ?? 0, 2);
    } else {
        $stats['label'] = 'Active POs';
        $stats['value'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status != 'Collected'")->fetch_row()[0];
    }
    $stats['pending'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('Collected', 'Rejected', 'Invalid')")->fetch_row()[0];
    $stats['completed'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Collected'")->fetch_row()[0];
    return $stats;
}

function get_top_notifications($conn, $role) {
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE target_role = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    return $stmt->get_result();
}

function create_po_transaction($conn, $data, $user_id) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, client_name, quotation_number, amount, created_by, status, current_location) VALUES (?, ?, ?, ?, ?, 'Pending', 'GM')");
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        
        $stmt->bind_param("sssdi", $data['po_number'], $data['client_name'], $data['quotation_number'], $data['grand_total'], $user_id);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $new_po_id = $stmt->insert_id;

        $item_stmt = $conn->prepare("INSERT INTO po_items (po_id, category, brand, item_name, specifications, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$item_stmt) throw new Exception("Prepare items failed: " . $conn->error);

        foreach ($data['items'] as $item) {
            $qty = intval($item['qty'] ?? 1);
            $price = floatval($item['price'] ?? 0);
            $total = floatval($item['total'] ?? 0);
            $brand = !empty($item['brand']) ? $item['brand'] : 'Generic/Other';
            $category = $item['category'] ?? 'Generic';
            $name = $item['name'] ?? 'Unknown Item';
            $specs = $item['specs'] ?? '';
            
            $item_stmt->bind_param("issssidd", $new_po_id, $category, $brand, $name, $specs, $qty, $price, $total);
            if (!$item_stmt->execute()) throw new Exception("Item Insert Error: " . $item_stmt->error);
        }

        $hist_stmt = $conn->prepare("INSERT INTO po_history (po_id, status_from, status_to, changed_by, remarks) VALUES (?, 'New', 'Pending', ?, 'PO Created')");
        $hist_stmt->bind_param("ii", $new_po_id, $user_id);
        if (!$hist_stmt->execute()) throw new Exception("History Error: " . $conn->error);
        
        $msg = "New PO #{$data['po_number']} created. Pending approval.";
        $target_role = 'GM';
        $notif_stmt = $conn->prepare("INSERT INTO notifications (target_role, message) VALUES (?, ?)");
        $notif_stmt->bind_param("ss", $target_role, $msg);
        if (!$notif_stmt->execute()) throw new Exception("Notif Error: " . $conn->error);

        $conn->commit();
        return $new_po_id;
    } catch (Exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        return false;
    }
}

function get_workflow_actions($conn, $current_status, $user_role) {
    $stmt = $conn->prepare("SELECT * FROM workflow_rules WHERE current_status = ? AND required_role = ?");
    $stmt->bind_param("ss", $current_status, $user_role);
    $stmt->execute();
    return $stmt->get_result();
}

function process_workflow_action($conn, $po_id, $action_key, $user_id, $user_role, $remarks) {
    $stmt_po = $conn->prepare("SELECT status, po_number FROM purchase_orders WHERE po_id = ?");
    $stmt_po->bind_param("i", $po_id);
    $stmt_po->execute();
    $q = $stmt_po->get_result();

    if ($q->num_rows == 0) return "PO Not Found";
    $po = $q->fetch_assoc();
    $current_status = $po['status'];
    $po_number = $po['po_number'];

    $stmt = $conn->prepare("SELECT * FROM workflow_rules WHERE current_status = ? AND action_key = ? AND required_role = ?");
    $stmt->bind_param("sss", $current_status, $action_key, $user_role);
    $stmt->execute();
    $rule = $stmt->get_result()->fetch_assoc();

    if (!$rule) return "Unauthorized Action or Invalid Status";

    $new_status = $rule['next_status'];
    $location = $rule['next_location'];
    
    $upd = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ? WHERE po_id = ?");
    $upd->bind_param("ssi", $new_status, $location, $po_id);
    $upd->execute();

    if ($action_key == 'mark_delivered') {
        $upd_del = $conn->prepare("UPDATE purchase_orders SET actual_delivery_date=NOW(), expected_collection_date=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE po_id=?");
        $upd_del->bind_param("i", $po_id);
        $upd_del->execute();
    }

    $hist = $conn->prepare("INSERT INTO po_history (po_id, status_from, status_to, changed_by, remarks) VALUES (?, ?, ?, ?, ?)");
    $hist->bind_param("issis", $po_id, $current_status, $new_status, $user_id, $remarks);
    $hist->execute();

    if (!empty($rule['notify_target'])) {
        $msg = str_replace("{po_number}", $po_number, $rule['notify_message']);
        $notif = $conn->prepare("INSERT INTO notifications (target_role, message) VALUES (?, ?)");
        $notif->bind_param("ss", $rule['notify_target'], $msg);
        $notif->execute();
    }

    $audit_desc = "Workflow Action: '$action_key' on PO #$po_number. Status changed from '$current_status' to '$new_status'. Remarks: " . ($remarks ?: 'None');
    log_audit_action($conn, $user_id, 'WORKFLOW_ACTION', $audit_desc);
    return "Success";
}

// ==========================================
// QUOTATION & CLIENT PO TRACKER FUNCTIONS
// ==========================================

function create_detailed_quotation($conn, $data, $user_id) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO quotations (quotation_number, client_name, amount, created_by, status) VALUES (?, ?, ?, ?, 'Pending PO')");
        $stmt->bind_param("ssdi", $data['quotation_number'], $data['client_name'], $data['grand_total'], $user_id);
        $stmt->execute();
        $new_q_id = $stmt->insert_id;

        $item_stmt = $conn->prepare("INSERT INTO quotation_items (quotation_id, category, brand, item_name, specifications, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($data['items'] as $item) {
            $cat = trim($item['category'] ?? '');
            $brand = trim($item['brand'] ?? 'Generic/Other');
            $name = trim($item['name'] ?? '');
            $specs = trim($item['specs'] ?? '');
            $qty = (int)($item['qty'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $total = (float)($item['total'] ?? 0);
            
            $item_stmt->bind_param("issssidd", $new_q_id, $cat, $brand, $name, $specs, $qty, $price, $total);
            $item_stmt->execute();
        }

        $desc = "Created detailed Quotation #{$data['quotation_number']} for client {$data['client_name']}. Waiting for Client PO.";
        log_audit_action($conn, $user_id, 'CREATE_QUOTATION', $desc);
        
        $conn->commit();
        return $new_q_id;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function receive_client_po($conn, $quotation_id, $client_po_number, $approval_mode, $po_file_path, $user_id) {
    $stmt = $conn->prepare("UPDATE quotations SET client_po_number = ?, approval_mode = ?, po_file_path = ?, status = 'PO Received' WHERE quotation_id = ?");
    $stmt->bind_param("sssi", $client_po_number, $approval_mode, $po_file_path, $quotation_id);
    
    if ($stmt->execute()) {
        $desc = "Received client approval ($approval_mode) with Auto-Generated Ref: $client_po_number for Quotation ID: $quotation_id.";
        log_audit_action($conn, $user_id, 'RECEIVE_CLIENT_PO', $desc);
        return true;
    }
    return false;
}
?>