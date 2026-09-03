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
    if ($status === 'Delivered') {
        return ['Finance'];
    }
    $roles = [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT required_role
         FROM workflow_rules
         WHERE current_status = ?
           AND NOT (
                action_key = 'mark_delivered'
                AND current_status <> 'For Pick-up/Delivery'
           )"
    );
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
        $res = $conn->query(
            "SELECT COALESCE(SUM(
                GREATEST(
                    po.amount - COALESCE(payment_summary.total_paid, 0),
                    0
                )
             ), 0)
             FROM purchase_orders po
             LEFT JOIN (
                SELECT po_id, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY po_id
             ) payment_summary
                ON payment_summary.po_id = po.po_id
             WHERE po.status = 'Delivered'
               AND po.collection_status IN ('Unpaid', 'Partially Paid')
               AND po.expected_collection_date BETWEEN
                   CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        $stats['value'] = "₱ " . number_format($res->fetch_row()[0] ?? 0, 2);
    } else {
        $stats['label'] = 'Active POs';
        $stats['value'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('Delivered', 'Rejected', 'Invalid')")->fetch_row()[0];
    }
    $stats['pending'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('Delivered', 'Rejected', 'Invalid')")->fetch_row()[0];
    $stats['completed'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered'")->fetch_row()[0];
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
    // Final client delivery requires the verified receipt workflow. Keeping
    // this guard here prevents callers from bypassing its delivery evidence
    // and contractual collection-date controls.
    if ($action_key === 'mark_delivered') {
        return 'Use the Complete Client Delivery form. Direct delivery completion is disabled.';
    }

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
// OFFICIAL RECORD IDENTIFICATION
// ==========================================
if (!function_exists('drms_allocate_official_record_number')) {
    function drms_allocate_official_record_number(
        mysqli $conn,
        string $record_prefix,
        ?string $recorded_at = null
    ): string {
        $record_prefix = strtoupper(trim($record_prefix));
        if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $record_prefix)) {
            throw new DomainException(
                'The records folder has an invalid Official Record code.'
            );
        }

        $timestamp = $recorded_at !== null && trim($recorded_at) !== ''
            ? strtotime($recorded_at)
            : time();
        if ($timestamp === false) {
            throw new DomainException('The Official Record date is invalid.');
        }

        $record_year = (int) date('Y', $timestamp);
        $sequence_stmt = $conn->prepare(
            "INSERT INTO official_record_sequences (
                record_year,
                last_sequence
             ) VALUES (
                ?,
                LAST_INSERT_ID(1)
             )
             ON DUPLICATE KEY UPDATE
                last_sequence = LAST_INSERT_ID(last_sequence + 1),
                updated_at = CURRENT_TIMESTAMP"
        );
        $sequence_stmt->bind_param('i', $record_year);
        $sequence_stmt->execute();
        $sequence_stmt->close();

        $sequence_result = $conn->query(
            'SELECT LAST_INSERT_ID() AS allocated_sequence'
        );
        $sequence_row = $sequence_result->fetch_assoc();
        $allocated_sequence = (int) ($sequence_row['allocated_sequence'] ?? 0);
        if ($allocated_sequence < 1) {
            throw new RuntimeException(
                'The Official Record number could not be allocated.'
            );
        }

        return sprintf(
            '%s-%04d-%04d',
            $record_prefix,
            $record_year,
            $allocated_sequence
        );
    }
}

if (!function_exists('drms_official_folder_schema_is_installed')) {
    function drms_official_folder_schema_is_installed(mysqli $conn): bool
    {
        $result = $conn->query(
            "SELECT
                (
                    SELECT COUNT(DISTINCT column_name)
                    FROM information_schema.COLUMNS
                    WHERE table_schema = DATABASE()
                      AND table_name = 'document_categories'
                      AND column_name IN (
                          'record_prefix',
                          'system_folder_key',
                          'is_system_folder',
                          'system_sort_order'
                      )
                ) AS category_column_count,
                (
                    SELECT COUNT(DISTINCT column_name)
                    FROM information_schema.COLUMNS
                    WHERE table_schema = DATABASE()
                      AND table_name = 'documents'
                      AND column_name = 'original_file_name'
                ) AS document_column_count"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row &&
            (int) $row['category_column_count'] === 4 &&
            (int) $row['document_column_count'] === 1;
    }
}

if (!function_exists('drms_get_official_folder_profile')) {
    function drms_get_official_folder_profile(
        mysqli $conn,
        string $category
    ): array {
        if (!drms_official_folder_schema_is_installed($conn)) {
            throw new RuntimeException(
                'Install the controlled Official Records folder migration first.'
            );
        }

        $category = trim($category);
        if ($category === '') {
            throw new InvalidArgumentException(
                'Select an Official Records folder before filing the document.'
            );
        }

        $folder_stmt = $conn->prepare(
            "SELECT
                id,
                parent_category,
                sub_category,
                record_prefix,
                system_folder_key,
                is_system_folder,
                policy_id
             FROM document_categories
             WHERE sub_category = ?
             ORDER BY is_system_folder DESC, id ASC
             LIMIT 1"
        );
        $folder_stmt->bind_param('s', $category);
        $folder_stmt->execute();
        $folder = $folder_stmt->get_result()->fetch_assoc();
        $folder_stmt->close();

        if (!$folder) {
            throw new RuntimeException(
                'The selected Official Records folder no longer exists.'
            );
        }

        $record_prefix = strtoupper(trim((string) $folder['record_prefix']));
        if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $record_prefix)) {
            throw new RuntimeException(
                'Assign a unique 2-10 character record code to the selected folder before filing an Official Record.'
            );
        }

        $policy_id = (int) ($folder['policy_id'] ?? 0);
        if ($policy_id < 1) {
            throw new RuntimeException(
                'Assign a retention policy to the Official Records folder before filing this document.'
            );
        }

        $folder['record_prefix'] = $record_prefix;
        $folder['policy_id'] = $policy_id;
        $folder['is_system_folder'] = (int) $folder['is_system_folder'];

        return $folder;
    }
}

if (!function_exists('drms_build_official_file_name')) {
    function drms_build_official_file_name(
        string $record_number,
        string $original_file_name,
        ?string $fallback_source_path = null
    ): string {
        $extension = strtolower((string) pathinfo(
            basename($original_file_name),
            PATHINFO_EXTENSION
        ));
        if ($extension === '' && $fallback_source_path !== null) {
            $extension = strtolower((string) pathinfo(
                basename($fallback_source_path),
                PATHINFO_EXTENSION
            ));
        }
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        $extension = substr((string) $extension, 0, 12);

        return $record_number . ($extension !== '' ? '.' . $extension : '');
    }
}

if (!function_exists('drms_prepare_official_storage_directory')) {
    function drms_prepare_official_storage_directory(
        string $project_root,
        string $record_prefix
    ): array {
        $storage_segment = strtolower($record_prefix);
        $absolute_directory = rtrim(
            $project_root,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR .
            'official' . DIRECTORY_SEPARATOR . $storage_segment;

        if (
            !is_dir($absolute_directory) &&
            !mkdir($absolute_directory, 0750, true) &&
            !is_dir($absolute_directory)
        ) {
            throw new RuntimeException(
                'The protected Official Records folder could not be prepared.'
            );
        }

        return [
            'absolute_directory' => $absolute_directory,
            'database_directory' => 'uploads/official/' . $storage_segment,
        ];
    }
}

if (!function_exists('drms_normalize_official_record_type')) {
    function drms_normalize_official_record_type(
        ?string $document_type,
        ?string $category
    ): string {
        $normalized_type = trim((string) $document_type);
        if ($normalized_type === '' || strcasecmp($normalized_type, 'Generic') === 0) {
            $normalized_type = trim((string) $category);
        }
        if ($normalized_type === '') {
            $normalized_type = 'General Record';
        }

        return mb_substr($normalized_type, 0, 50);
    }
}

if (!function_exists('drms_official_source_linkage_is_installed')) {
    function drms_official_source_linkage_is_installed(mysqli $conn): bool
    {
        $result = $conn->query(
            "SELECT
                (
                    SELECT COUNT(DISTINCT column_name)
                    FROM information_schema.COLUMNS
                    WHERE table_schema = DATABASE()
                      AND table_name = 'documents'
                      AND column_name IN (
                          'source_module',
                          'source_record_id',
                          'business_reference'
                      )
                ) AS source_column_count,
                (
                    SELECT COUNT(DISTINCT index_name)
                    FROM information_schema.STATISTICS
                    WHERE table_schema = DATABASE()
                      AND table_name = 'documents'
                      AND index_name = 'uq_documents_source_record'
                ) AS source_unique_index_count"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row &&
            (int) $row['source_column_count'] === 3 &&
            (int) $row['source_unique_index_count'] === 1;
    }
}

if (!function_exists('drms_file_existing_source_as_official_record')) {
    function drms_file_existing_source_as_official_record(
        mysqli $conn,
        string $source_absolute_path,
        string $original_file_name,
        string $expected_hash,
        string $category,
        string $document_type,
        int $declared_by,
        string $declared_at,
        string $source_module,
        int $source_record_id,
        ?string $business_reference = null,
        ?int $uploaded_by = null,
        ?int $po_id = null,
        ?string $tags = null
    ): array {
        if (!drms_official_source_linkage_is_installed($conn)) {
            throw new RuntimeException(
                'The Official Record source-linkage migration is not installed.'
            );
        }

        $source_module = trim($source_module);
        $category = trim($category);
        $expected_hash = strtolower(trim($expected_hash));

        if (
            $source_record_id < 1 ||
            $declared_by < 1 ||
            $category === '' ||
            !preg_match('/^[A-Za-z][A-Za-z0-9 _-]{1,49}$/', $source_module)
        ) {
            throw new InvalidArgumentException(
                'The Official Record source metadata is invalid.'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            throw new InvalidArgumentException(
                'The source document hash is invalid.'
            );
        }

        if (strtotime($declared_at) === false) {
            throw new InvalidArgumentException(
                'The Official Record declaration date is invalid.'
            );
        }

        $existing_stmt = $conn->prepare(
            "SELECT doc_id, record_number
             FROM documents
             WHERE source_module = ?
               AND source_record_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $existing_stmt->bind_param(
            'si',
            $source_module,
            $source_record_id
        );
        $existing_stmt->execute();
        $existing_record = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();

        if ($existing_record) {
            return [
                'created' => false,
                'doc_id' => (int) $existing_record['doc_id'],
                'record_number' => (string) $existing_record['record_number'],
                'storage_absolute_path' => null,
            ];
        }

        $project_root = realpath(dirname(__DIR__));
        $source_real_path = realpath($source_absolute_path);
        if ($project_root === false || $source_real_path === false) {
            throw new RuntimeException(
                'The source document could not be located.'
            );
        }

        $project_prefix = rtrim(
            $project_root,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;
        if (
            !is_file($source_real_path) ||
            stripos($source_real_path, $project_prefix) !== 0
        ) {
            throw new RuntimeException(
                'The source document is outside protected project storage.'
            );
        }

        $actual_source_hash = strtolower(
            (string) hash_file('sha256', $source_real_path)
        );
        if (
            $actual_source_hash === '' ||
            !hash_equals($expected_hash, $actual_source_hash)
        ) {
            throw new RuntimeException(
                'The source document failed integrity verification.'
            );
        }

        $folder = drms_get_official_folder_profile($conn, $category);
        $policy_id = (int) $folder['policy_id'];
        $record_prefix = (string) $folder['record_prefix'];

        $display_name = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]/u',
            ' ',
            basename($original_file_name)
        ));
        if ($display_name === '') {
            $display_name = basename($source_real_path);
        }
        $display_name = mb_substr($display_name, 0, 255);

        $document_type = drms_normalize_official_record_type(
            $document_type,
            $category
        );
        $category = mb_substr($category, 0, 100);
        $business_reference = trim((string) $business_reference);
        $business_reference = $business_reference !== ''
            ? mb_substr($business_reference, 0, 100)
            : null;
        $tags = trim((string) $tags);
        $tags = $tags !== '' ? mb_substr($tags, 0, 255) : null;
        $uploaded_by = ($uploaded_by ?? 0) > 0
            ? $uploaded_by
            : $declared_by;

        $record_number = drms_allocate_official_record_number(
            $conn,
            $record_prefix,
            $declared_at
        );
        $stored_file_name = drms_build_official_file_name(
            $record_number,
            $display_name,
            $source_real_path
        );
        $storage = drms_prepare_official_storage_directory(
            $project_root,
            $record_prefix
        );
        $stored_absolute_path = $storage['absolute_directory'] .
            DIRECTORY_SEPARATOR . $stored_file_name;

        if (is_file($stored_absolute_path)) {
            throw new RuntimeException(
                'The allocated Official Record file name already exists.'
            );
        }

        try {
            if (!copy($source_real_path, $stored_absolute_path)) {
                throw new RuntimeException(
                    'The independent Official Record copy could not be created.'
                );
            }
            @chmod($stored_absolute_path, 0640);

            $stored_hash = strtolower(
                (string) hash_file('sha256', $stored_absolute_path)
            );
            if (
                $stored_hash === '' ||
                !hash_equals($expected_hash, $stored_hash)
            ) {
                throw new RuntimeException(
                    'The Official Record copy failed integrity verification.'
                );
            }

            $database_file_path = $storage['database_directory'] . '/' .
                $stored_file_name;

            $insert_stmt = $conn->prepare(
                "INSERT INTO documents (
                    po_id,
                    doc_type,
                    file_name,
                    original_file_name,
                    record_number,
                    business_reference,
                    source_module,
                    source_record_id,
                    file_path,
                    category,
                    tags,
                    file_hash,
                    uploaded_by,
                    policy_id,
                    status,
                    record_phase,
                    declared_at,
                    declared_by,
                    is_locked
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'Active', 'Official', ?, ?, 1
                 )"
            );
            $insert_types = 'issssssissssiisi';
            $insert_stmt->bind_param(
                $insert_types,
                $po_id,
                $document_type,
                $stored_file_name,
                $display_name,
                $record_number,
                $business_reference,
                $source_module,
                $source_record_id,
                $database_file_path,
                $category,
                $tags,
                $stored_hash,
                $uploaded_by,
                $policy_id,
                $declared_at,
                $declared_by
            );
            $insert_stmt->execute();
            $doc_id = (int) $conn->insert_id;
            $insert_stmt->close();

            if ($doc_id < 1) {
                throw new RuntimeException(
                    'The Official Record index entry could not be created.'
                );
            }

            return [
                'created' => true,
                'doc_id' => $doc_id,
                'record_number' => $record_number,
                'file_name' => $stored_file_name,
                'storage_absolute_path' => $stored_absolute_path,
            ];
        } catch (Throwable $exception) {
            if (is_file($stored_absolute_path)) {
                @unlink($stored_absolute_path);
            }
            throw $exception;
        }
    }
}

if (!function_exists('drms_file_generated_pdf_as_official_record')) {
    function drms_file_generated_pdf_as_official_record(
        mysqli $conn,
        callable $pdf_factory,
        string $original_file_name,
        string $category,
        string $document_type,
        int $declared_by,
        string $declared_at,
        string $source_module,
        int $source_record_id,
        ?string $business_reference = null,
        ?int $uploaded_by = null,
        ?int $po_id = null,
        ?string $tags = null
    ): array {
        if (!drms_official_source_linkage_is_installed($conn)) {
            throw new RuntimeException(
                'The Official Record source-linkage migration is not installed.'
            );
        }
        if (!drms_official_folder_schema_is_installed($conn)) {
            throw new RuntimeException(
                'The controlled Official Records folder migration is not installed.'
            );
        }

        $source_module = trim($source_module);
        $category = trim($category);
        if (
            $source_record_id < 1 ||
            $declared_by < 1 ||
            $category === '' ||
            !preg_match('/^[A-Za-z][A-Za-z0-9 _-]{1,49}$/', $source_module) ||
            strtotime($declared_at) === false
        ) {
            throw new InvalidArgumentException(
                'The generated Official Record metadata is invalid.'
            );
        }

        $existing_stmt = $conn->prepare(
            "SELECT doc_id, record_number, file_name
             FROM documents
             WHERE source_module = ?
               AND source_record_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $existing_stmt->bind_param('si', $source_module, $source_record_id);
        $existing_stmt->execute();
        $existing_record = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();

        if ($existing_record) {
            return [
                'created' => false,
                'doc_id' => (int) $existing_record['doc_id'],
                'record_number' => (string) $existing_record['record_number'],
                'file_name' => (string) $existing_record['file_name'],
                'storage_absolute_path' => null,
            ];
        }

        $folder = drms_get_official_folder_profile($conn, $category);
        if ((int) $folder['is_system_folder'] !== 1) {
            throw new RuntimeException(
                'Generated workflow records must use a protected system folder.'
            );
        }

        $original_file_name = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]/u',
            ' ',
            basename($original_file_name)
        ));
        if ($original_file_name === '') {
            $original_file_name = 'generated-official-record.pdf';
        }
        if (strtolower((string) pathinfo(
            $original_file_name,
            PATHINFO_EXTENSION
        )) !== 'pdf') {
            $original_file_name .= '.pdf';
        }
        $original_file_name = mb_substr($original_file_name, 0, 255);

        $document_type = drms_normalize_official_record_type(
            $document_type,
            $category
        );
        $category = mb_substr($category, 0, 100);
        $business_reference = trim((string) $business_reference);
        $business_reference = $business_reference !== ''
            ? mb_substr($business_reference, 0, 100)
            : null;
        $tags = trim((string) $tags);
        $tags = $tags !== '' ? mb_substr($tags, 0, 255) : null;
        $uploaded_by = ($uploaded_by ?? 0) > 0
            ? $uploaded_by
            : $declared_by;

        $record_number = drms_allocate_official_record_number(
            $conn,
            (string) $folder['record_prefix'],
            $declared_at
        );
        $stored_file_name = drms_build_official_file_name(
            $record_number,
            $original_file_name
        );

        $project_root = realpath(dirname(__DIR__));
        if ($project_root === false) {
            throw new RuntimeException(
                'The protected project storage could not be located.'
            );
        }
        $storage = drms_prepare_official_storage_directory(
            $project_root,
            (string) $folder['record_prefix']
        );
        $stored_absolute_path = $storage['absolute_directory'] .
            DIRECTORY_SEPARATOR . $stored_file_name;
        $temporary_absolute_path = $storage['absolute_directory'] .
            DIRECTORY_SEPARATOR . '.' . $stored_file_name . '.' .
            bin2hex(random_bytes(8)) . '.tmp';

        if (is_file($stored_absolute_path)) {
            throw new RuntimeException(
                'The allocated Official Record file name already exists.'
            );
        }

        try {
            $pdf_content = $pdf_factory($record_number, $stored_file_name);
            if (
                !is_string($pdf_content) ||
                strlen($pdf_content) < 100 ||
                strncmp($pdf_content, '%PDF-', 5) !== 0 ||
                strpos($pdf_content, '%%EOF') === false
            ) {
                throw new RuntimeException(
                    'The generated Official Record is not a valid PDF document.'
                );
            }

            $written_bytes = file_put_contents(
                $temporary_absolute_path,
                $pdf_content,
                LOCK_EX
            );
            if ($written_bytes !== strlen($pdf_content)) {
                throw new RuntimeException(
                    'The generated Official Record PDF could not be written completely.'
                );
            }

            $stored_hash = strtolower((string) hash_file(
                'sha256',
                $temporary_absolute_path
            ));
            if (!preg_match('/^[a-f0-9]{64}$/', $stored_hash)) {
                throw new RuntimeException(
                    'The generated Official Record hash could not be created.'
                );
            }

            if (!rename($temporary_absolute_path, $stored_absolute_path)) {
                throw new RuntimeException(
                    'The generated Official Record PDF could not be finalized.'
                );
            }
            @chmod($stored_absolute_path, 0640);

            $database_file_path = $storage['database_directory'] . '/' .
                $stored_file_name;
            $policy_id = (int) $folder['policy_id'];

            $insert_stmt = $conn->prepare(
                "INSERT INTO documents (
                    po_id,
                    doc_type,
                    file_name,
                    original_file_name,
                    record_number,
                    business_reference,
                    source_module,
                    source_record_id,
                    file_path,
                    category,
                    tags,
                    file_hash,
                    uploaded_by,
                    policy_id,
                    status,
                    record_phase,
                    declared_at,
                    declared_by,
                    is_locked
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'Active', 'Official', ?, ?, 1
                 )"
            );
            $insert_stmt->bind_param(
                'issssssissssiisi',
                $po_id,
                $document_type,
                $stored_file_name,
                $original_file_name,
                $record_number,
                $business_reference,
                $source_module,
                $source_record_id,
                $database_file_path,
                $category,
                $tags,
                $stored_hash,
                $uploaded_by,
                $policy_id,
                $declared_at,
                $declared_by
            );
            $insert_stmt->execute();
            $doc_id = (int) $conn->insert_id;
            $insert_stmt->close();

            if ($doc_id < 1) {
                throw new RuntimeException(
                    'The generated Official Record index entry could not be created.'
                );
            }

            return [
                'created' => true,
                'doc_id' => $doc_id,
                'record_number' => $record_number,
                'file_name' => $stored_file_name,
                'storage_absolute_path' => $stored_absolute_path,
            ];
        } catch (Throwable $exception) {
            if (is_file($temporary_absolute_path)) {
                @unlink($temporary_absolute_path);
            }
            if (is_file($stored_absolute_path)) {
                @unlink($stored_absolute_path);
            }
            throw $exception;
        }
    }
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
