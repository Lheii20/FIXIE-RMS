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

    $input_username = trim($_POST['username']); // Ito ay pwedeng username o email
$password = $_POST['password'];

// I-check kung ang nilagay ay nag-ma-match sa 'username' OR sa 'email'
$stmt_user = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
$stmt_user->bind_param("ss", $input_username, $input_username);
$stmt_user->execute();
$user = $stmt_user->get_result()->fetch_assoc();

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

// ... (Iyong mga nakaraang login logic sa taas) ...

// I-load ang PHPMailer para sa Forgot Password
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ==========================================
// FORGOT PASSWORD LOGIC (SEND EMAIL)
// ==========================================
if (isset($_POST['forgot_password']) || (isset($_POST['action']) && $_POST['action'] === 'forgot_password')) {
    require_once '../config/db_connect.php';
    require_once '../libs/src/Exception.php';
    require_once '../libs/src/PHPMailer.php';
    require_once '../libs/src/SMTP.php';

    $email = trim($_POST['email']);

    // 1. Siguraduhing may password_resets table (Foolproof auto-create)
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Hanapin kung nag-eexist ang email sa users table
    $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ? AND status = 'Active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        
        // 3. Gumawa ng secure token at expiry (1 hour)
        $token = bin2hex(random_bytes(32)); 
        $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));

        // Alisin ang lumang token ng user na ito at i-save ang bago
        $conn->query("DELETE FROM password_resets WHERE email = '$email'");
        $stmt2 = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt2->bind_param("sss", $email, $token, $expires);
        $stmt2->execute();

        // 4. I-setup ang Email Content at i-send via PHPMailer
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'], 2) . "/reset_password.php?token=" . $token;
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = 'tamayolhei5@gmail.com';     
            $mail->Password   = 'wewnzrsryelddatr';   
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('no-reply@fixieventures.com', 'Fixie DRMS Security');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - Fixie DRMS';
            $mail->Body    = "Hello " . htmlspecialchars($user['full_name']) . ",<br><br>
                              We received a request to reset your password. Click the secure link below to proceed:<br><br>
                              <a href='{$reset_link}' style='padding: 10px 20px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a><br><br>
                              Or copy and paste this link to your browser:<br>
                              {$reset_link}<br><br>
                              <i>Note: This link is valid for 1 hour. If you did not request this, please ignore this email.</i>";

            $mail->send();
            header("Location: ../forgot_password.php?success=" . urlencode("A secure recovery link has been sent to your email."));
            exit();
        } catch (Exception $e) {
            header("Location: ../forgot_password.php?error=" . urlencode("Failed to send email. Mailer Error."));
            exit();
        }
    } else {
        // Security best practice: Huwag ipaalam kung registered ang email o hindi. I-redirect pabalik as success/neutral.
        header("Location: ../forgot_password.php?success=" . urlencode("If the email is registered, a recovery link has been sent."));
        exit();
    }
}

// ==========================================
// RESET PASSWORD LOGIC (PROCESS NEW PASSWORD)
// ==========================================
if (isset($_POST['reset_password_submit']) || (isset($_POST['action']) && $_POST['action'] === 'reset_password_submit')) {
    require_once '../config/db_connect.php';
    
    $token = trim($_POST['token']);
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];

    if ($new_pass !== $conf_pass) {
        header("Location: ../reset_password.php?token=$token&error=" . urlencode("Passwords do not match."));
        exit();
    }

    // Hanapin ang token sa database
    $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $email = $res->fetch_assoc()['email'];
        $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);

        // I-update ang password sa users table
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $update_stmt->bind_param("ss", $hashed_password, $email);
        $update_stmt->execute();

        // Burahin ang nagamit na token
        $conn->query("DELETE FROM password_resets WHERE email = '$email'");

        header("Location: ../index.php?success=" . urlencode("Password successfully reset. You may now log in."));
        exit();
    } else {
        header("Location: ../index.php?error=" . urlencode("Invalid or expired password reset link."));
        exit();
    }
}
?>
?>