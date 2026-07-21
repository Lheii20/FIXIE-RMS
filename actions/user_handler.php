<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';
require '../config/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../libs/src/Exception.php';
require '../libs/src/PHPMailer.php';
require '../libs/src/SMTP.php';

// AUTO-SETUP SECURITY COLUMNS (Ensures DB won't crash)
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'require_pass_change'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN require_pass_change TINYINT(1) DEFAULT 0");
}
$check_token_col = $conn->query("SHOW COLUMNS FROM users LIKE 'setup_token'");
if ($check_token_col && $check_token_col->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN setup_token VARCHAR(255) NULL");
    $conn->query("ALTER TABLE users ADD COLUMN setup_token_expire DATETIME NULL");
}

if (!isset($_SESSION['user_id'])) { 
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: ../dashboard.php?error=SecurityTokenMismatch");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // STRICT ADMIN CHECK - Binalik natin para super secure ang User Management
    if ($_SESSION['role'] !== 'Admin') {
        die("Security Violation: Only the main Administrator can manage users.");
    }

    // Keep every user-management action aligned with the users.role ENUM and workflow roles.
    $allowed_roles = ['Admin', 'President', 'GM', 'Finance', 'Procurement', 'Supply Chain', 'Sales Staff'];

    // ===============================================
    // UPDATE USER PERMISSIONS (RBAC)
    // ===============================================
    if ($action == 'update_permissions') {
        $target_user_id = intval($_POST['target_user_id']);
        $perms = $_POST['permissions'] ?? [];
        
        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $del->bind_param("i", $target_user_id);
            $del->execute();
            
            if (!empty($perms)) {
                $ins = $conn->prepare("INSERT INTO user_permissions (user_id, permission_name) VALUES (?, ?)");
                foreach ($perms as $p) {
                    $ins->bind_param("is", $target_user_id, $p);
                    $ins->execute();
                }
            }
            $conn->commit();
            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_PERMISSIONS', "Updated capabilities for user ID: $target_user_id");
            }
            header("Location: ../admin_users.php?success=PermissionsUpdated");
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../admin_users.php?error=UpdateFailed");
        }
        exit();
    }

    // ===============================================
    // ADD NEW USER (ZERO-TRUST TOKEN LINK)
    // ===============================================
    if ($action == 'create_user') {
        $fullname = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'] ?? '';

        if (!in_array($role, $allowed_roles, true)) {
            header("Location: ../admin_users.php?error=InvalidRole");
            exit();
        }

        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        if($check->get_result()->num_rows > 0){
            header("Location: ../admin_users.php?error=UserOrEmailExists"); 
            exit();
        }

        $setup_token = bin2hex(random_bytes(32));
        $expire_time = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $dummy_hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password_hash, role, status, require_pass_change, setup_token, setup_token_expire) VALUES (?, ?, ?, ?, ?, 'Active', 0, ?, ?)");
        $stmt->bind_param("sssssss", $fullname, $username, $email, $dummy_hash, $role, $setup_token, $expire_time);
        
        if($stmt->execute()){ 
            $new_user_id = $stmt->insert_id;
            
            // Assign default role capabilities IF matching existing base defaults
            $role_clone_q = $conn->prepare("SELECT permission_name FROM user_permissions u_p JOIN users u ON u.user_id = u_p.user_id WHERE u.role = ? LIMIT 20");
            $role_clone_q->bind_param("s", $role);
            $role_clone_q->execute();
            $role_res = $role_clone_q->get_result();
            if ($role_res->num_rows > 0) {
                $ins_perm = $conn->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_name) VALUES (?, ?)");
                while($p_row = $role_res->fetch_assoc()) {
                    $ins_perm->bind_param("is", $new_user_id, $p_row['permission_name']);
                    $ins_perm->execute();
                }
            }

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $script = dirname($_SERVER['SCRIPT_NAME']);
            $base_dir = rtrim(str_replace('/actions', '', $script), '/');
            $setup_link = $protocol . "://" . $host . $base_dir . "/setup_password.php?token=" . $setup_token . "&email=" . urlencode($email);
            
            $mail = new PHPMailer(true);
            try {
                drms_configure_mailer($mail, ['from_name' => 'Fixie DRMS Security']);
                $mail->addAddress($email, $fullname);
                $mail->Subject = 'Welcome to Fixie DRMS - Secure Account Setup Required';
                $mail->isHTML(true);
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                    <div style='background-color: #2a617b; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin: 0;'>Welcome, {$fullname}!</h2>
                    </div>
                    <div style='padding: 25px; line-height: 1.6;'>
                        <p>An administrator has provisioned an enterprise account for you with the username: <b>{$username}</b>.</p>
                        <p>To securely activate your account and define your own password, please click the secure link below:</p>
                        <div style='margin: 25px 0;'>
                            <a href='{$setup_link}' style='background-color: #2a617b; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Set Up My Password</a>
                        </div>
                        <p style='color: #dc2626; font-size: 13px;'><strong>Security Notice:</strong> This link will automatically expire in 24 hours. If it expires, contact the system administrator.</p>
                    </div>
                    <div style='background-color: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; color: #777;'>
                        &copy; " . date('Y') . " Fixie DRMS. All rights reserved.
                    </div>
                </div>";
                
                $mail->send();
                if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_USER', "Created new user: $username and sent setup link.");
                header("Location: ../admin_users.php?success=UserCreated");
            } catch (Exception $e) {
                error_log("PHPMailer Error: " . $mail->ErrorInfo);
                if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_USER', "Created new user: $username (Email Failed)");
                header("Location: ../admin_users.php?success=UserCreatedButEmailFailed");
            }
        } else {
            header("Location: ../admin_users.php?error=CreateFailed");
        }
        exit();
    }

    // ===============================================
    // UPDATE USER
    // ===============================================
    if ($action == 'update_user') {
        $target_user_id = intval($_POST['user_id']);
        $fullname = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'] ?? '';

        if (!in_array($role, $allowed_roles, true)) {
            header("Location: ../admin_users.php?error=InvalidRole");
            exit();
        }

        $check_admin = $conn->query("SELECT role FROM users WHERE user_id = $target_user_id")->fetch_assoc();
        if ($check_admin && $check_admin['role'] === 'Admin' && $role !== 'Admin') {
            header("Location: ../admin_users.php?error=CannotChangeAdminRole");
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, role=? WHERE user_id=?");
        $stmt->bind_param("sssi", $fullname, $email, $role, $target_user_id);
        
        if($stmt->execute()){
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_USER', "Updated details for user ID: $target_user_id");
            header("Location: ../admin_users.php?success=UserUpdated");
        } else {
            header("Location: ../admin_users.php?error=UpdateFailed");
        }
        exit();
    }

    // ===============================================
    // FORCE PASSWORD RESET (ADMIN-INITIATED)
    // The user signs in once with this temporary password, then must create a permanent one.
    // ===============================================
    if ($action == 'force_password_reset') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $temporary_password = $_POST['temporary_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($target_user_id < 1 || $target_user_id === (int)$_SESSION['user_id']) {
            header("Location: ../admin_users.php?error=CannotResetOwnPassword");
            exit();
        }

        if ($temporary_password !== $confirm_password) {
            header("Location: ../admin_users.php?error=PasswordsDoNotMatch");
            exit();
        }

        if (strlen($temporary_password) < 8 || !preg_match('/[A-Z]/', $temporary_password) || !preg_match('/[a-z]/', $temporary_password) || !preg_match('/\d/', $temporary_password)) {
            header("Location: ../admin_users.php?error=WeakTemporaryPassword");
            exit();
        }

        $user_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $user_stmt->bind_param("i", $target_user_id);
        $user_stmt->execute();
        $target_user = $user_stmt->get_result()->fetch_assoc();
        if (!$target_user) {
            header("Location: ../admin_users.php?error=UserNotFound");
            exit();
        }

        $password_hash = password_hash($temporary_password, PASSWORD_DEFAULT);
        $new_session_token = bin2hex(random_bytes(32));
        $reset_stmt = $conn->prepare("UPDATE users SET password_hash = ?, require_pass_change = 1, setup_token = NULL, setup_token_expire = NULL, session_token = ? WHERE user_id = ?");
        $reset_stmt->bind_param("ssi", $password_hash, $new_session_token, $target_user_id);

        if ($reset_stmt->execute()) {
            log_audit_action($conn, $_SESSION['user_id'], 'FORCE_PASSWORD_RESET', "Forced password reset for user: {$target_user['username']}");
            header("Location: ../admin_users.php?success=PasswordReset");
        } else {
            header("Location: ../admin_users.php?error=UpdateFailed");
        }
        exit();
    }

    // ===============================================
    // DELETE USER
    // ===============================================
    if ($action == 'delete_user') {
        $target_user_id = intval($_POST['user_id']);
        
        if ($target_user_id == $_SESSION['user_id']) {
            header("Location: ../admin_users.php?error=CannotDeleteSelf");
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $target_user_id);
        if($stmt->execute()){
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'DELETE_USER', "Deleted user ID: $target_user_id");
            header("Location: ../admin_users.php?success=UserDeleted");
        } else {
            header("Location: ../admin_users.php?error=DeleteFailed");
        }
        exit();
    }

    // ===============================================
    // UPDATE STATUS
    // ===============================================
    if ($action == 'update_status') {
        $target_user_id = intval($_POST['user_id']);
        $new_status = $_POST['status'];

        if ($target_user_id == $_SESSION['user_id'] && $new_status == 'Suspended') {
            header("Location: ../admin_users.php?error=CannotSuspendSelf");
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_status, $target_user_id);
        if($stmt->execute()){
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_STATUS', "Changed status to $new_status for user ID: $target_user_id");
            header("Location: ../admin_users.php?success=UserStatusUpdated");
        } else {
            header("Location: ../admin_users.php?error=UpdateFailed");
        }
        exit();
    }

    // ===============================================
    // FORCE LOGOUT (Invalidate Session Token)
    // ===============================================
    if ($action == 'force_logout') {
        $target_user_id = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $target_user_id);
        
        if ($stmt->execute()) {
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'FORCE_LOGOUT', "Admin force logged out user ID: $target_user_id");
            header("Location: ../admin_users.php?success=UserForceLoggedOut");
        } else {
            header("Location: ../admin_users.php?error=ActionFailed");
        }
        exit();
    }
}
