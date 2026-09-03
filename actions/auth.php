<?php
require_once __DIR__ . '/../config/session_bootstrap.php';
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

function auth_has_valid_csrf_token(): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    return is_string($sessionToken)
        && is_string($submittedToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

// ==========================================
// STANDARD LOGIN LOGIC
// ==========================================
if (isset($_POST['login'])) {
    if (!auth_has_valid_csrf_token()) {
        header("Location: ../index.php?error=LoginSessionExpired");
        exit();
    }

    $input_username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $input_username = substr($input_username, 0, 100);
    $password = (string) ($_POST['password'] ?? '');

    if ($input_username === '' || $password === '') {
        header("Location: ../index.php?error=WrongCredentials");
        exit();
    }

    // Protect one identity without immediately locking every user on the same
    // office network. A broader network ceiling still blocks automated attacks.
    $identity_limit = 5;
    $network_limit = 20;

    $identity_attempt_stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts
         FROM login_attempts
         WHERE ip_address = ?
           AND username = ?
           AND attempt_time > NOW() - INTERVAL 5 MINUTE"
    );
    $identity_attempt_stmt->bind_param("ss", $ip_address, $input_username);
    $identity_attempt_stmt->execute();
    $identity_attempts = (int) $identity_attempt_stmt->get_result()->fetch_assoc()['attempts'];

    $network_attempt_stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts
         FROM login_attempts
         WHERE ip_address = ?
           AND attempt_time > NOW() - INTERVAL 5 MINUTE"
    );
    $network_attempt_stmt->bind_param("s", $ip_address);
    $network_attempt_stmt->execute();
    $network_attempts = (int) $network_attempt_stmt->get_result()->fetch_assoc()['attempts'];

    if ($identity_attempts >= $identity_limit || $network_attempts >= $network_limit) {
        header("Location: ../index.php?error=TooManyAttemptsWait5Mins");
        exit();
    }

    $record_failed_attempt = static function () use ($conn, $ip_address, $input_username): void {
        $failed_stmt = $conn->prepare(
            "INSERT INTO login_attempts (ip_address, username, attempt_time)
             VALUES (?, ?, NOW())"
        );
        $failed_stmt->bind_param("ss", $ip_address, $input_username);
        $failed_stmt->execute();
    };

    $stmt_user = $conn->prepare(
        "SELECT user_id, username, password_hash, full_name, email, role, status,
                avatar, require_pass_change
         FROM users
         WHERE username = ? OR email = ?
         LIMIT 1"
    );
    $stmt_user->bind_param("ss", $input_username, $input_username);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $record_failed_attempt();
        header("Location: ../index.php?error=WrongCredentials");
        exit();
    }

    if ($user['status'] !== 'Active') {
        header("Location: ../index.php?error=AccountLockedWaitAdmin");
        exit();
    }

    $clear_attempts_stmt = $conn->prepare(
        "DELETE FROM login_attempts WHERE ip_address = ? AND username = ?"
    );
    $clear_attempts_stmt->bind_param("ss", $ip_address, $input_username);
    $clear_attempts_stmt->execute();

    if ((int) ($user['require_pass_change'] ?? 0) === 1) {
        session_regenerate_id(true);
        $_SESSION['temp_user_id'] = (int) $user['user_id'];
        $_SESSION['temp_fullname'] = $user['full_name'];
        header("Location: ../setup_password.php");
        exit();
    }

    session_regenerate_id(true);
    $session_token = bin2hex(random_bytes(32));

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['fullname'] = $user['full_name'];
    $_SESSION['avatar'] = $user['avatar'];
    $_SESSION['session_token'] = $session_token;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $session_stmt = $conn->prepare(
        "UPDATE users SET session_token = ?, last_active = NOW() WHERE user_id = ?"
    );
    $session_stmt->bind_param("si", $session_token, $user['user_id']);
    $session_stmt->execute();

    log_audit_action($conn, $user['user_id'], 'LOGIN', 'User logged in successfully');
    header("Location: ../dashboard.php");
    exit();
}

// ==========================================
// TOKEN & SESSION PASSWORD SETUP LOGIC
// ==========================================
if(isset($_POST['setup_password'])){
    if (!auth_has_valid_csrf_token()) {
        header("Location: ../index.php?error=LoginSessionExpired");
        exit();
    }

    $flow_type = (string) ($_POST['flow_type'] ?? '');
    $new_pass = (string) ($_POST['new_password'] ?? '');
    $confirm_pass = (string) ($_POST['confirm_password'] ?? '');
    
    $temp_id = 0;
    $setup_token_hash = '';

    // VALIDATE FLOW SOURCE
    if ($flow_type === 'token') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $error_append = "&error=";
        $return_url = "../setup_password.php?token=" . rawurlencode($token) . "&email=" . rawurlencode($email);

        if (!preg_match('/^[a-f0-9]{64}$/', $token) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            header("Location: ../index.php?error=InvalidOrExpiredToken");
            exit();
        }

        $setup_token_hash = hash('sha256', $token);
        $stmt = $conn->prepare(
            "SELECT user_id
             FROM users
             WHERE LOWER(email) = ? AND setup_token = ? AND setup_token_expire > NOW()
               AND setup_token_purpose = 'Account Setup'
               AND status = 'Active'
             LIMIT 1"
        );
        $stmt->bind_param("ss", $email, $setup_token_hash);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($res->num_rows === 0) {
            header("Location: ../index.php?error=InvalidOrExpiredToken"); 
            exit();
        }
        $token_user = $res->fetch_assoc();
        $temp_id = (int) $token_user['user_id'];
        
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

    if (strlen($new_pass) > 128 || !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_pass)) {
        header("Location: " . $return_url . $error_append . "WeakPassword"); 
        exit();
    }

    // FINALIZE ACCOUNT ACTIVATION
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $session_token = bin2hex(random_bytes(32));
    
    if ($flow_type === 'token') {
        $stmt_upd = $conn->prepare(
            "UPDATE users
             SET password_hash = ?, require_pass_change = 0,
                 setup_token = NULL, setup_token_purpose = NULL,
                 setup_token_sent_at = NULL, setup_token_expire = NULL,
                 reset_token = NULL, reset_token_expire = NULL,
                 session_token = ?, last_active = NOW()
             WHERE user_id = ?
               AND setup_token = ?
               AND setup_token_purpose = 'Account Setup'
               AND setup_token_expire > NOW()
               AND status = 'Active'"
        );
        $stmt_upd->bind_param("ssis", $hash, $session_token, $temp_id, $setup_token_hash);
    } else {
        $stmt_upd = $conn->prepare(
            "UPDATE users
             SET password_hash = ?, require_pass_change = 0,
                 setup_token = NULL, setup_token_purpose = NULL,
                 setup_token_sent_at = NULL, setup_token_expire = NULL,
                 reset_token = NULL, reset_token_expire = NULL,
                 session_token = ?, last_active = NOW()
             WHERE user_id = ? AND status = 'Active'"
        );
        $stmt_upd->bind_param("ssi", $hash, $session_token, $temp_id);
    }

    $activation_succeeded = $stmt_upd->execute() && $stmt_upd->affected_rows === 1;

    if ($activation_succeeded) {
        $verified_stmt = $conn->prepare("SELECT user_id, role, full_name, avatar FROM users WHERE user_id = ? LIMIT 1");
        $verified_stmt->bind_param('i', $temp_id);
        $verified_stmt->execute();
        $verified_user = $verified_stmt->get_result()->fetch_assoc();

        if (!$verified_user) {
            header("Location: ../index.php?error=InvalidOrExpiredToken");
            exit();
        }

        $expire_recovery = $conn->prepare(
            "UPDATE password_reset_otps SET status = 'Expired'
             WHERE user_id = ? AND status IN ('Pending', 'Verified')"
        );
        $expire_recovery->bind_param('i', $temp_id);
        $expire_recovery->execute();

        $expire_login_otp = $conn->prepare(
            "UPDATE otp_auth_tokens SET status = 'Expired'
             WHERE user_id = ? AND status = 'Pending'"
        );
        $expire_login_otp->bind_param('i', $temp_id);
        $expire_login_otp->execute();

        session_regenerate_id(true); 
        
        $_SESSION['user_id'] = $verified_user['user_id'];
        $_SESSION['role'] = $verified_user['role'];
        $_SESSION['fullname'] = $verified_user['full_name'];
        $_SESSION['avatar'] = $verified_user['avatar'];
        $_SESSION['session_token'] = $session_token;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_fullname']);

        if (function_exists('log_audit_action')) {
            $setup_description = $flow_type === 'token'
                ? 'User securely activated account through a one-time setup token and logged in'
                : 'User completed the required password change and logged in';
            log_audit_action($conn, $verified_user['user_id'], 'LOGIN', $setup_description);
        }

        header("Location: ../dashboard.php");
        exit();
    } else {
        if ($flow_type === 'token') {
            header("Location: ../index.php?error=InvalidOrExpiredToken");
        } else {
            header("Location: " . $return_url . $error_append . "DatabaseError");
        }
        exit();
    }
}

// ==========================================
// SECURE LOGOUT LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $logout_token = $_POST['csrf_token'] ?? '';
    $session_csrf_token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($logout_token) || !is_string($session_csrf_token) || $session_csrf_token === '' || !hash_equals($session_csrf_token, $logout_token)) {
        header("Location: ../index.php?error=LoginSessionExpired");
        exit();
    }

    if (isset($_SESSION['user_id'])) {
        $logout_user_id = (int) $_SESSION['user_id'];
        $logout_session_token = (string) ($_SESSION['session_token'] ?? '');
        $logout_stmt = $conn->prepare(
            "UPDATE users SET session_token = NULL WHERE user_id = ? AND session_token = ?"
        );
        $logout_stmt->bind_param('is', $logout_user_id, $logout_session_token);
        $logout_stmt->execute();
        log_audit_action($conn, $logout_user_id, 'LOGOUT', 'User securely logged out of the system');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Strict'
        ]);
    }
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// Password recovery now uses the dedicated OTP endpoint:
// actions/password_recovery_otp.php. Link-based reset handling was removed.
?>
