<?php
require_once __DIR__ . '/session_bootstrap.php';

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
    
} catch (mysqli_sql_exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("System Maintenance: Unable to connect to the database. Please try again later.");
}

if (empty($_SESSION['csrf_token']) && php_sapi_name() !== 'cli') {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// =========================================================================
// CENTRAL SESSION ENFORCER
// Handles forced logout and inactivity before protected page/action logic runs.
// =========================================================================
if (!function_exists('drms_request_expects_json')) {
    function drms_request_expects_json(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT'])
                && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}

if (!function_exists('drms_end_session')) {
    function drms_end_session(mysqli $conn, int $userId, string $sessionToken, string $status, string $errorCode, bool $clearCurrentToken = false): void
    {
        if ($clearCurrentToken && $userId > 0 && $sessionToken !== '') {
            $clearTokenStmt = $conn->prepare(
                "UPDATE users SET session_token = NULL WHERE user_id = ? AND session_token = ?"
            );
            $clearTokenStmt->bind_param('is', $userId, $sessionToken);
            $clearTokenStmt->execute();
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

        if (drms_request_expects_json()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo json_encode(['status' => $status]);
            exit();
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $pathToRoot = (strpos($scriptName, '/api/') !== false || strpos($scriptName, '/actions/') !== false)
            ? '../'
            : '';
        header('Location: ' . $pathToRoot . 'index.php?error=' . rawurlencode($errorCode));
        exit();
    }
}

if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $current_token = (string) ($_SESSION['session_token'] ?? '');
    
    $stmt_check = $conn->prepare("SELECT session_token, status FROM users WHERE user_id = ?");
    $stmt_check->bind_param("i", $uid);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    $sessionUser = $res_check->fetch_assoc();
    if (!$sessionUser || $current_token === '' || !hash_equals((string) ($sessionUser['session_token'] ?? ''), $current_token) || $sessionUser['status'] !== 'Active') {
        // Do not clear the database token here: it may belong to a newer session.
        drms_end_session($conn, $uid, $current_token, 'force_logout', 'ForceLoggedOutByAdmin');
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        )"
    );
    $conn->query(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value)
         VALUES ('session_timeout', '30')"
    );

    $timeoutMinutes = 30;
    $timeoutStmt = $conn->prepare(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout' LIMIT 1"
    );
    $timeoutStmt->execute();
    $timeoutRow = $timeoutStmt->get_result()->fetch_assoc();
    $configuredTimeout = (int) ($timeoutRow['setting_value'] ?? 30);
    if (in_array($configuredTimeout, [15, 30, 60, 120], true)) {
        $timeoutMinutes = $configuredTimeout;
    }

    $now = time();
    $lastUserActivity = (int) ($_SESSION['last_activity'] ?? $now);
    if (($now - $lastUserActivity) > ($timeoutMinutes * 60)) {
        drms_end_session($conn, $uid, $current_token, 'session_expired', 'SessionExpired', true);
    }

    $isSessionProbe = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'check_session.php';
    $probeReportsActivity = $isSessionProbe && (string) ($_GET['activity'] ?? '0') === '1';
    $isMeaningfulActivity = !$isSessionProbe || $probeReportsActivity;

    if ($isMeaningfulActivity) {
        $_SESSION['last_activity'] = $now;

        $lastActiveStmt = $conn->prepare(
            "UPDATE users
             SET last_active = NOW()
             WHERE user_id = ?
               AND (last_active IS NULL OR last_active < NOW() - INTERVAL 1 MINUTE)"
        );
        $lastActiveStmt->bind_param('i', $uid);
        $lastActiveStmt->execute();
    }
}

require_once __DIR__ . '/audit_bootstrap.php';
if (php_sapi_name() !== 'cli') {
    drms_capture_request_audit($conn);
}
?>
