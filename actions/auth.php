<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

// AUTO-SETUP SECURITY COLUMNS
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'require_pass_change'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN require_pass_change TINYINT(1) DEFAULT 0");
}
$check_token_col = $conn->query("SHOW COLUMNS FROM users LIKE 'setup_token'");
if ($check_token_col && $check_token_col->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN setup_token VARCHAR(255) NULL");
    $conn->query("ALTER TABLE users ADD COLUMN setup_token_expire DATETIME NULL");
}

$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// ==========================================
// STANDARD LOGIN LOGIC
// ==========================================
if(isset($_POST['login'])){
    
    $stmt_check_attempts = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = ? AND attempt_time > NOW() - INTERVAL 5 MINUTE");
    $stmt_check_attempts->bind_param("s", $ip_address);
    $stmt_check_attempts->execute();
    $attempts = $stmt_check_attempts->get_result()->fetch_assoc()['attempts'];

    if ($attempts >= 5) {
        header("Location: ../index.php?error=TooManyAttemptsWait5Mins");
        exit();
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $user = get_user_by_username($conn, $username);

    if($user){
        if(password_verify($password, $user['password_hash'])){
            
            if($user['status'] !== 'Active') {
                header("Location: ../index.php?error=AccountLockedWaitAdmin");
                exit();
            }

            // Legacy support for forced password resets (Not using Setup Token)
            if (isset($user['require_pass_change']) && $user['require_pass_change'] == 1) {
                $_SESSION['temp_user_id'] = $user['user_id'];
                $_SESSION['temp_fullname'] = $user['full_name'];
                header("Location: ../setup_password.php");
                exit();
            }

            session_regenerate_id(true); 
            
            $stmt_clear = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt_clear->bind_param("s", $ip_address);
            $stmt_clear->execute();

            $session_token = bin2hex(random_bytes(32));

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['fullname'] = $user['full_name'];
            $_SESSION['avatar'] = $user['avatar'];
            $_SESSION['session_token'] = $session_token;
            
            $conn->query("UPDATE users SET session_token = '$session_token', last_active = NOW() WHERE user_id = " . $user['user_id']);
            log_audit_action($conn, $user['user_id'], 'LOGIN', 'User logged in successfully');
            header("Location: ../dashboard.php");
            exit();

        } else {
            $stmt_fail = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time) VALUES (?, ?, NOW())");
            $stmt_fail->bind_param("ss", $ip_address, $username);
            $stmt_fail->execute();
            header("Location: ../index.php?error=InvalidCredentials");
            exit();
        }
    } else {
        $stmt_fail = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time) VALUES (?, ?, NOW())");
        $stmt_fail->bind_param("ss", $ip_address, $username);
        $stmt_fail->execute();
        header("Location: ../index.php?error=InvalidCredentials");
        exit();
    }
}

// ==========================================
// TOKEN & SESSION PASSWORD SETUP LOGIC
// ==========================================
if(isset($_POST['setup_password'])){
    $flow_type = $_POST['flow_type'] ?? '';
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    
    $temp_id = 0;

    // VALIDATE FLOW SOURCE
    if ($flow_type === 'token') {
        $token = $_POST['token'] ?? '';
        $email = $_POST['email'] ?? '';
        $error_append = "&error=";
        $return_url = "../setup_password.php?token=" . urlencode($token) . "&email=" . urlencode($email);
        
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND setup_token = ? AND setup_token_expire > NOW()");
        $stmt->bind_param("ss", $email, $token);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($res->num_rows === 0) {
            header("Location: ../index.php?error=InvalidOrExpiredToken"); 
            exit();
        }
        $temp_id = $res->fetch_assoc()['user_id'];
        
    } elseif ($flow_type === 'session') {
        $error_append = "?error=";
        $return_url = "../setup_password.php";
        
        if(!isset($_SESSION['temp_user_id'])) { 
            header("Location: ../index.php"); 
            exit(); 
        }
        $temp_id = $_SESSION['temp_user_id'];
    } else {
        header("Location: ../index.php"); 
        exit();
    }

    // PASSWORD VALIDATION
    if ($new_pass !== $confirm_pass) {
        header("Location: " . $return_url . $error_append . "PasswordMismatch"); 
        exit();
    }

    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_pass)) {
        header("Location: " . $return_url . $error_append . "WeakPassword"); 
        exit();
    }

    // FINALIZE ACCOUNT ACTIVATION
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $session_token = bin2hex(random_bytes(32));
    
    $stmt_upd = $conn->prepare("UPDATE users SET password_hash = ?, require_pass_change = 0, setup_token = NULL, setup_token_expire = NULL, session_token = ?, last_active = NOW() WHERE user_id = ?");
    $stmt_upd->bind_param("ssi", $hash, $session_token, $temp_id);
    
    if($stmt_upd->execute()) {
        $u_q = $conn->query("SELECT * FROM users WHERE user_id = $temp_id");
        $verified_user = $u_q->fetch_assoc();

        session_regenerate_id(true); 
        
        $_SESSION['user_id'] = $verified_user['user_id'];
        $_SESSION['role'] = $verified_user['role'];
        $_SESSION['fullname'] = $verified_user['full_name'];
        $_SESSION['avatar'] = $verified_user['avatar'];
        $_SESSION['session_token'] = $session_token;
        
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_fullname']);

        if (function_exists('log_audit_action')) {
            log_audit_action($conn, $verified_user['user_id'], 'LOGIN', 'User successfully activated account via setup and logged in');
        }

        header("Location: ../dashboard.php");
        exit();
    } else {
        header("Location: " . $return_url . $error_append . "DatabaseError"); 
        exit();
    }
}

// ==========================================
// SECURE LOGOUT LOGIC
// ==========================================
if(isset($_GET['logout'])){
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid CSRF Token on Logout");
    }

    if (isset($_SESSION['user_id'])) {
        $conn->query("UPDATE users SET session_token = NULL WHERE user_id = " . intval($_SESSION['user_id']));
        log_audit_action($conn, $_SESSION['user_id'], 'LOGOUT', 'User securely logged out of the system');
    }

    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: ../index.php");
    exit();
}
?>