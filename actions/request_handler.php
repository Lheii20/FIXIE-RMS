<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

// Ensure the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../dashboard.php");
    exit();
}

// CSRF Protection Check
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Security Validation Failed.");
}

$action = $_POST['action'] ?? '';

// ========================================================================
// 1. SUBMIT ACCOUNT REQUEST (Triggered by Users in settings.php)
// ========================================================================
if ($action === 'submit_request') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $request_type = trim($_POST['request_type']);
    $new_value = trim($_POST['new_value'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $current_password = $_POST['current_password'] ?? ''; 
    
    // FETCH EXACT USERNAME AND PASSWORD HASH DIRECTLY FROM DB
    $u_stmt = $conn->prepare("SELECT username, password_hash FROM users WHERE user_id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result()->fetch_assoc();
    $current_username = $u_res['username'] ?? 'Unknown';
    $hashed_password = $u_res['password_hash'] ?? '';
    $u_stmt->close();

    // SECURITY CHECK: Verify if the entered password matches their current account password
    if (!password_verify($current_password, $hashed_password)) {
        header("Location: ../settings.php?error=WrongCurrentPassword");
        exit();
    }

    // Insert into user_requests table
    $stmt = $conn->prepare("INSERT INTO user_requests (user_id, request_type, new_value, reason, status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("isss", $user_id, $request_type, $new_value, $reason);
    
    if ($stmt->execute()) {
        
        // --- ADMIN NOTIFICATION TRIGGER ---
        $admin_notif_msg = "Action Required: " . $current_username . " is requesting to " . $request_type . ".";
        create_role_notification($conn, 'Admin', $admin_notif_msg);
        // ----------------------------------

        // Audit Trail Entry
        $audit_desc = "Submitted request to " . $request_type . " to '" . $new_value . "' (Reason: " . $reason . ")";
        $stmt_audit = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'SUBMIT_REQUEST', ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt_audit->bind_param("iss", $user_id, $audit_desc, $ip);
        $stmt_audit->execute();

        header("Location: ../settings.php?success=RequestSubmitted");
        exit();
    } else {
        header("Location: ../settings.php?error=RequestFailed");
        exit();
    }
}

// ========================================================================
// 2. MANAGE ACCOUNT REQUEST (Triggered by Admin in admin_requests.php)
// ========================================================================
elseif ($action === 'manage_request') {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
        header("Location: ../dashboard.php");
        exit();
    }

    $request_id = intval($_POST['request_id']);
    $decision = $_POST['decision']; // Accepts 'Approve' or 'Reject'

    // Retrieve request data
    $stmt_req = $conn->prepare("SELECT user_id, request_type, new_value FROM user_requests WHERE request_id = ?");
    $stmt_req->bind_param("i", $request_id);
    $stmt_req->execute();
    $req_data = $stmt_req->get_result()->fetch_assoc();

    if ($req_data) {
        $req_user_id = $req_data['user_id'];
        $req_type = $req_data['request_type'];
        $new_val = $req_data['new_value'];

        // Determine final string for status update
        $final_status = ($decision === 'Approve') ? 'Approved' : 'Rejected';
        
        // Update request status in user_requests table
        $stmt_upd = $conn->prepare("UPDATE user_requests SET status = ? WHERE request_id = ?");
        $stmt_upd->bind_param("si", $final_status, $request_id);
        $stmt_upd->execute();

        // If Approved, apply the actual changes to the user's account in the database
        if ($decision === 'Approve') {
            if ($req_type === 'Change Username' && !empty($new_val)) {
                $stmt_user = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?");
                $stmt_user->bind_param("si", $new_val, $req_user_id);
                $stmt_user->execute();
            } elseif ($req_type === 'Unlock Account') {
                $stmt_user = $conn->prepare("UPDATE users SET status = 'Active', failed_attempts = 0 WHERE user_id = ?");
                $stmt_user->bind_param("i", $req_user_id);
                $stmt_user->execute();
            }
        }

        // Send a notification back to the user regarding the decision
        $user_notif_msg = "Your account request to " . $req_type . " was " . strtolower($final_status) . " by the Admin.";
        
        // Fetch target role AND full name for accurate logging and notification
        $target_role = 'User';
        $target_fullname = 'Unknown User';
        $stmt_get_user = $conn->query("SELECT role, full_name FROM users WHERE user_id = $req_user_id");
        
        if($stmt_get_user && $stmt_get_user->num_rows > 0) {
            $u_data = $stmt_get_user->fetch_assoc();
            $target_role = $u_data['role'];
            $target_fullname = $u_data['full_name'];
            
            create_role_notification($conn, $target_role, $user_notif_msg);
        }

        // Audit Trail for Admin Action - FULLY READABLE FOR HUMAN (No more IDs)
        $audit_desc = "Admin " . strtolower($final_status) . " account request (" . $req_type . ") for " . $target_fullname;
        $stmt_audit = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'MANAGE_REQUEST', ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'];
        $admin_id = $_SESSION['user_id'];
        $stmt_audit->bind_param("iss", $admin_id, $audit_desc, $ip);
        $stmt_audit->execute();

        header("Location: ../admin_requests.php?success=ActionCompleted");
        exit();
    }
    
    header("Location: ../admin_requests.php?error=RequestNotFound");
    exit();
}

// ========================================================================
// 3. FORGOT PASSWORD REQUEST
// ========================================================================
elseif ($action === 'request_forgot_password') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND email = ? LIMIT 1");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        $reset_code = sprintf("%06d", mt_rand(1, 999999));
        header("Location: ../forgot_password.php?step=2&email=" . urlencode($email));
        exit();
    } else {
        header("Location: ../forgot_password.php?step=1&error=AccountMismatch");
        exit();
    }
}

// ========================================================================
// 4. VERIFY RESET CODE
// ========================================================================
elseif ($action === 'verify_reset_code') {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);
    
    header("Location: ../reset_password.php?email=" . urlencode($email) . "&code=" . urlencode($code));
    exit();
}

else {
    header("Location: ../dashboard.php");
    exit();
}
