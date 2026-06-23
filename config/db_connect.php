<?php
$host = getenv('DB_HOST') ?: "localhost";
$user = getenv('DB_USER') ?: "root";
$pass = getenv('DB_PASS') ?: "";
$db   = getenv('DB_NAME') ?: "fixie_drms";

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4"); 

    // =========================================================================
    // 1. AUTO-SETUP SESSION MANAGEMENT (Para sa Force Logout at Active Status)
    // =========================================================================
    $check_col_session = $conn->query("SHOW COLUMNS FROM users LIKE 'session_token'");
    if ($check_col_session && $check_col_session->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN session_token VARCHAR(255) NULL");
        $conn->query("ALTER TABLE users ADD COLUMN last_active DATETIME NULL");
    }

    // =========================================================================
    // 2. AUTO-SETUP DB RATE LIMITING (Brute-Force Protection)
    // =========================================================================
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        username VARCHAR(100) NOT NULL,
        attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Auto-cleanup: Burahin ang failed attempts lagpas 10 mins para hindi lumobo ang DB
    $conn->query("DELETE FROM login_attempts WHERE attempt_time < NOW() - INTERVAL 10 MINUTE");

} catch (mysqli_sql_exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("System Maintenance: Unable to connect to the database. Please try again later.");
}

// Ensure session logic doesn't break when script is executed via CLI (Cron Job)
if (session_status() === PHP_SESSION_NONE) {
    if (php_sapi_name() !== 'cli') {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '', 
            'secure' => isset($_SERVER['HTTPS']), 
            'httponly' => true,                   
            'samesite' => 'Strict'                
        ]);
        session_start();
    }
}

if (empty($_SESSION['csrf_token']) && php_sapi_name() !== 'cli') {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// =========================================================================
// REAL-TIME SESSION ENFORCER (Kicks out user if Force Logged Out by Admin)
// =========================================================================
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $current_token = $_SESSION['session_token'] ?? '';
    
    $stmt_check = $conn->prepare("SELECT session_token, status FROM users WHERE user_id = ?");
    $stmt_check->bind_param("i", $uid);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($row = $res_check->fetch_assoc()) {
        if ($row['session_token'] !== $current_token || $row['status'] !== 'Active') {
            // Session revoked or Account locked by Admin.
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();

            // Intercept if it's the invisible real-time JS checker
            $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || 
                       (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'force_logout']);
                exit();
            } else {
                // Determine root path for safe redirection
                $script_name = $_SERVER['SCRIPT_NAME'];
                $path_to_root = (strpos($script_name, '/api/') !== false || strpos($script_name, '/actions/') !== false) ? '../' : '';
                header("Location: " . $path_to_root . "index.php?error=ForceLoggedOutByAdmin");
                exit();
            }
        } else {
            // Update Active Status kung lagpas 1 minute na para iwas heavy DB writes
            $conn->query("UPDATE users SET last_active = NOW() WHERE user_id = $uid AND (last_active IS NULL OR last_active < NOW() - INTERVAL 1 MINUTE)");
        }
    }
}

require_once __DIR__ . '/audit_bootstrap.php';
if (php_sapi_name() !== 'cli') {
    drms_capture_request_audit($conn);
}
?>