<?php
require_once __DIR__ . '/audit_bootstrap.php';

// ===============================================
// RBAC AUTO-SETUP & PERMISSION HELPERS
// ===============================================
function ensure_rbac_tables_exist($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_name VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS user_permissions (
        user_id INT NOT NULL,
        permission_name VARCHAR(50) NOT NULL,
        PRIMARY KEY (user_id, permission_name),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (permission_name) REFERENCES permissions(permission_name) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $default_perms = [
        ['can_upload_documents', 'Allow uploading of files and documents'],
        ['can_archive_documents', 'Allow archiving of active documents'],
        ['can_delete_documents', 'Allow permanent deletion of documents'],
        ['can_manage_folders', 'Allow creating and deleting system folders'],
        ['can_edit_policies', 'Allow editing of retention policies'],
        ['can_view_audit_logs', 'Allow viewing of the system audit trail and logs'],
        ['can_view_all_folders', 'Allow viewing of all folders regardless of department'],
        ['can_view_disposition', 'Allow viewing of documents ready for disposition']
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (permission_name, description) VALUES (?, ?)");
    foreach ($default_perms as $dp) {
        $stmt->bind_param("ss", $dp[0], $dp[1]);
        $stmt->execute();
    }
    
    $admin_q = $conn->query("SELECT user_id FROM users WHERE role = 'Admin'");
    while ($admin_user = $admin_q->fetch_assoc()) {
        $admin_id = $admin_user['user_id'];
        foreach ($default_perms as $dp) {
            $conn->query("INSERT IGNORE INTO user_permissions (user_id, permission_name) VALUES ($admin_id, '{$dp[0]}')");
        }
    }
}

function has_permission($conn, $user_id, $permission_name) {
    static $user_perms = null;
    static $tables_checked = false;
    
    if (!$tables_checked) {
        ensure_rbac_tables_exist($conn);
        $tables_checked = true;
    }
    
    if ($user_perms === null) {
        $user_perms = [];
        $stmt = $conn->prepare("SELECT user_id, permission_name FROM user_permissions");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $uid = $row['user_id'];
                if (!isset($user_perms[$uid])) $user_perms[$uid] = [];
                $user_perms[$uid][] = $row['permission_name'];
            }
        }
    }
    return isset($user_perms[$user_id]) && in_array($permission_name, $user_perms[$user_id]);
}

// ===============================================
// CORE SYSTEM FUNCTIONS
// ===============================================
function e($string) {
    if ($string === null) return '';
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// IN-UPDATE: Para tumanggap ng State-Change JSON
function log_audit_action($conn, $user_id, $action, $description, $old_payload = null, $new_payload = null) {
    return drms_log_audit_action($conn, $user_id, $action, $description, $old_payload, $new_payload);
}

function ensure_document_audit_trail_table_exists($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS document_audit_trail (
        trail_id INT AUTO_INCREMENT PRIMARY KEY,
        audit_log_id INT DEFAULT NULL,
        doc_id INT NOT NULL,
        user_id INT NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        description TEXT,
        ip_address VARCHAR(45) DEFAULT NULL,
        source_page VARCHAR(191) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (doc_id),
        INDEX (user_id),
        INDEX (audit_log_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function log_document_action($conn, $user_id, $action, $doc_id = null, $description = '', $source_page = null) {
    $source_page = $source_page ?? ($_SERVER['REQUEST_URI'] ?? null);
    $audit_log_id = log_audit_action($conn, $user_id, $action, $description);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    if ($doc_id !== null) {
        ensure_document_audit_trail_table_exists($conn);
        $stmt = $conn->prepare("INSERT INTO document_audit_trail (audit_log_id, doc_id, user_id, action_type, description, ip_address, source_page) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iiissss", $audit_log_id, $doc_id, $user_id, $action, $description, $ip, $source_page);
            $stmt->execute();
        }
    }

    return $audit_log_id;
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

// ===============================================
// SHARED QUEUE, PERSONAL NOTIFICATION STATE
// ===============================================
// Notifications remain visible to everyone in a role, but each recipient owns
// their own read, pin, and delete state in notification_user_states.
function ensure_collaboration_tables_exist($conn) {
    static $checked = false;
    if ($checked) return;

    // Avoid running DDL in normal requests. In particular, notification creation
    // may happen inside a PO transaction and MySQL DDL would implicitly commit it.
    $notification_states_exists = $conn->query("SHOW TABLES LIKE 'notification_user_states'");
    if (!$notification_states_exists || $notification_states_exists->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS notification_user_states (
        notif_id INT NOT NULL,
        user_id INT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (notif_id, user_id),
        INDEX idx_notification_user_inbox (user_id, is_deleted, is_read),
        CONSTRAINT fk_notification_state_notification FOREIGN KEY (notif_id) REFERENCES notifications(notif_id) ON DELETE CASCADE,
        CONSTRAINT fk_notification_state_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    $task_assignments_exists = $conn->query("SHOW TABLES LIKE 'purchase_order_task_assignments'");
    if (!$task_assignments_exists || $task_assignments_exists->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS purchase_order_task_assignments (
        assignment_id INT NOT NULL AUTO_INCREMENT,
        po_id INT NOT NULL,
        assigned_to INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_role VARCHAR(50) NOT NULL,
        assignment_status ENUM('Active','Released','Completed') NOT NULL DEFAULT 'Active',
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        released_at DATETIME DEFAULT NULL,
        release_reason VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (assignment_id),
        INDEX idx_po_assignment_active (po_id, assignment_status),
        INDEX idx_user_assignment_active (assigned_to, assignment_status),
        CONSTRAINT fk_po_assignment_po FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
        CONSTRAINT fk_po_assignment_user FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE CASCADE,
        CONSTRAINT fk_po_assignment_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    $checked = true;
}

function ensure_user_notification_states($conn, $user_id, $role) {
    ensure_collaboration_tables_exist($conn);
    // The deployment migration marks historic notifications as read. This fallback
    // only creates state rows for role notifications created after the user account.
    static $supports_personal_recipient = null;
    if ($supports_personal_recipient === null) {
        $recipient_column = $conn->query(
            "SHOW COLUMNS FROM notifications LIKE 'recipient_user_id'"
        );
        $supports_personal_recipient = $recipient_column &&
            $recipient_column->num_rows > 0;
    }

    if ($supports_personal_recipient) {
        $stmt = $conn->prepare("INSERT IGNORE INTO notification_user_states (notif_id, user_id, is_read, is_pinned, is_deleted)
            SELECT n.notif_id, ?, 0, 0, 0
            FROM notifications n
            INNER JOIN users u ON u.user_id = ?
            WHERE n.target_role = ?
              AND n.created_at >= u.created_at
              AND (n.recipient_user_id IS NULL OR n.recipient_user_id = ?)");
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO notification_user_states (notif_id, user_id, is_read, is_pinned, is_deleted)
            SELECT n.notif_id, ?, 0, 0, 0
            FROM notifications n
            INNER JOIN users u ON u.user_id = ?
            WHERE n.target_role = ? AND n.created_at >= u.created_at");
    }

    if ($stmt) {
        if ($supports_personal_recipient) {
            $stmt->bind_param(
                "iisi",
                $user_id,
                $user_id,
                $role,
                $user_id
            );
        } else {
            $stmt->bind_param("iis", $user_id, $user_id, $role);
        }
        $stmt->execute();
        $stmt->close();
    }
}

function create_role_notification($conn, $target_role, $message) {
    ensure_collaboration_tables_exist($conn);

    $stmt = $conn->prepare("INSERT INTO notifications (target_role, message) VALUES (?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("ss", $target_role, $message);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $notif_id = $stmt->insert_id;
    $stmt->close();

    $state_stmt = $conn->prepare("INSERT IGNORE INTO notification_user_states (notif_id, user_id, is_read, is_pinned, is_deleted)
        SELECT ?, user_id, 0, 0, 0 FROM users WHERE role = ? AND status = 'Active'");
    if ($state_stmt) {
        $state_stmt->bind_param("is", $notif_id, $target_role);
        $state_stmt->execute();
        $state_stmt->close();
    }
    return $notif_id;
}

function get_unread_notification_count($conn, $user_id, $role) {
    ensure_user_notification_states($conn, $user_id, $role);
    $stmt = $conn->prepare("SELECT COUNT(*) AS unread_count
        FROM notification_user_states nus
        INNER JOIN notifications n ON n.notif_id = nus.notif_id
        WHERE nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0 AND nus.is_read = 0");
    $stmt->bind_param("is", $user_id, $role);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
    $stmt->close();
    return $count;
}

// ===============================================
// PURCHASE ORDER TASK OWNERSHIP
// ===============================================
function get_po_eligible_roles($conn, $status) {
    if (in_array($status, ['Delivered', 'Partially-Collected'], true)) {
        return ['Finance'];
    }
    $roles = [];
    $stmt = $conn->prepare("SELECT DISTINCT required_role FROM workflow_rules WHERE current_status = ?");
    if ($stmt) {
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $roles[] = $row['required_role'];
        $stmt->close();
    }
    return $roles;
}

function get_active_po_task_assignment($conn, $po_id, $for_update = false) {
    ensure_collaboration_tables_exist($conn);
    $lock = $for_update ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare("SELECT a.*, u.full_name AS assignee_name
        FROM purchase_order_task_assignments a
        INNER JOIN users u ON u.user_id = a.assigned_to
        WHERE a.po_id = ? AND a.assignment_status = 'Active'
        ORDER BY a.assignment_id DESC LIMIT 1$lock");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $assignment ?: null;
}

function role_requires_task_claim($conn, $role) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS user_count FROM users WHERE role = ? AND status = 'Active'");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['user_count'] ?? 0);
    $stmt->close();
    return $count > 1;
}

function claim_po_task($conn, $po_id, $user_id, $role) {
    ensure_collaboration_tables_exist($conn);
    $po_stmt = $conn->prepare("SELECT status, po_number FROM purchase_orders WHERE po_id = ? FOR UPDATE");
    $po_stmt->bind_param("i", $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();
    $po_stmt->close();
    if (!$po) throw new Exception('Purchase order not found.');

    if (!in_array($role, get_po_eligible_roles($conn, $po['status']), true)) {
        throw new Exception('This purchase order is not currently assigned to your role.');
    }
    $active = get_active_po_task_assignment($conn, $po_id, true);
    if ($active) {
        if ((int)$active['assigned_to'] === (int)$user_id) return $active;
        throw new Exception('This task is already claimed by ' . $active['assignee_name'] . '.');
    }

    $insert = $conn->prepare("INSERT INTO purchase_order_task_assignments (po_id, assigned_to, assigned_by, assigned_role) VALUES (?, ?, ?, ?)");
    $insert->bind_param("iiis", $po_id, $user_id, $user_id, $role);
    if (!$insert->execute()) throw new Exception('The task could not be claimed.');
    $insert->close();
    return get_active_po_task_assignment($conn, $po_id, true);
}

function release_po_task($conn, $po_id, $user_id, $reason = 'Released by assignee') {
    $assignment = get_active_po_task_assignment($conn, $po_id, true);
    if (!$assignment) throw new Exception('There is no active task assignment for this PO.');
    if ((int)$assignment['assigned_to'] !== (int)$user_id) throw new Exception('Only the assigned user can release this task.');
    $stmt = $conn->prepare("UPDATE purchase_order_task_assignments SET assignment_status = 'Released', released_at = NOW(), release_reason = ? WHERE assignment_id = ? AND assignment_status = 'Active'");
    $stmt->bind_param("si", $reason, $assignment['assignment_id']);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new Exception('The task assignment changed before it could be released.');
    $stmt->close();
}

function complete_po_task_assignment($conn, $po_id, $user_id, $reason = 'Completed through workflow action') {
    $assignment = get_active_po_task_assignment($conn, $po_id, true);
    if (!$assignment) return;
    if ((int)$assignment['assigned_to'] !== (int)$user_id) throw new Exception('Only the assigned user can complete this task.');
    $stmt = $conn->prepare("UPDATE purchase_order_task_assignments SET assignment_status = 'Completed', released_at = NOW(), release_reason = ? WHERE assignment_id = ? AND assignment_status = 'Active'");
    $stmt->bind_param("si", $reason, $assignment['assignment_id']);
    $stmt->execute();
    $stmt->close();
}

function enforce_po_task_ownership($conn, $po_id, $user_id, $role) {
    $assignment = get_active_po_task_assignment($conn, $po_id, true);
    if ($assignment) {
        if ((int)$assignment['assigned_to'] !== (int)$user_id) {
            throw new Exception('This task is assigned to ' . $assignment['assignee_name'] . '.');
        }
        return;
    }
    if (role_requires_task_claim($conn, $role)) {
        throw new Exception('Please claim this shared task before completing it.');
    }
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

function get_top_notifications($conn, $role, $user_id = null) {
    if ($user_id === null) return false;
    ensure_user_notification_states($conn, $user_id, $role);
    $stmt = $conn->prepare("SELECT n.* FROM notifications n INNER JOIN notification_user_states nus ON nus.notif_id = n.notif_id WHERE n.target_role = ? AND nus.user_id = ? AND nus.is_deleted = 0 AND nus.is_read = 0 ORDER BY n.created_at DESC LIMIT 5");
    $stmt->bind_param("si", $role, $user_id);
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
        if (!create_role_notification($conn, $target_role, $msg)) throw new Exception("Notif Error: " . $conn->error);

        // Map payload state for JSON audit
        log_audit_action($conn, $user_id, 'CREATE_PO', "Created Purchase Order {$data['po_number']}", null, $data);

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
        create_role_notification($conn, $rule['notify_target'], $msg);
    }

    $audit_desc = "Workflow Action: '$action_key' on PO #$po_number";
    $state_change = ['from_status' => $current_status, 'to_status' => $new_status, 'remarks' => $remarks];
    log_audit_action($conn, $user_id, 'WORKFLOW_ACTION', $audit_desc, $state_change, $state_change);
    return "Success";
}

// ==========================================
// QUOTATION & CLIENT PO TRACKER FUNCTIONS
// ==========================================

function create_detailed_quotation($conn, $data, $user_id) {
    $conn->begin_transaction();
    try {
        // A newly issued quotation remains in the client-approval queue until proof is submitted.
        $stmt = $conn->prepare("INSERT INTO quotations (quotation_number, client_name, amount, created_by, status) VALUES (?, ?, ?, ?, 'Pending Approval')");
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

        $desc = "Created Quotation #{$data['quotation_number']} for client {$data['client_name']}";
        log_audit_action($conn, $user_id, 'CREATE_QUOTATION', $desc, null, $data);
        
        $conn->commit();
        return $new_q_id;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function receive_client_po($conn, $quotation_id, $client_po_number, $approval_mode, $po_file_path, $user_id) {
    /*
     * Only quotations that are still awaiting a client decision can be approved.
     * The WHERE clause prevents an accidental overwrite of an already approved
     * or converted quotation when a form is submitted twice.
     */
    $stmt = $conn->prepare(
        "UPDATE quotations
         SET client_po_number = ?, approval_mode = ?, po_file_path = ?, status = 'PO Received'
         WHERE quotation_id = ? AND status IN ('Pending Approval', 'Pending PO')"
    );
    $stmt->bind_param("sssi", $client_po_number, $approval_mode, $po_file_path, $quotation_id);

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        return false;
    }

    $desc = "Submitted client approval via $approval_mode for Quotation ID: $quotation_id";
    $payload = [
        'client_po_number' => $client_po_number,
        'approval_mode' => $approval_mode,
        'proof_file' => $po_file_path
    ];
    log_audit_action($conn, $user_id, 'SUBMIT_CLIENT_APPROVAL', $desc, null, $payload);
    return true;
}
?>
