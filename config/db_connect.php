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
    // 1. AUTO-ARCHIVE RULE: Awtomatikong i-archive ang lagpas 3 days na expired
    // =========================================================================
    $conn->query("
        UPDATE documents 
        SET status = 'Archived' 
        WHERE status = 'Active' 
        AND expiry_date IS NOT NULL 
        AND expiry_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    ");

    // =========================================================================
    // 2. RETENTION & DISPOSITION RULE (Core RMS Feature)
    // Awtomatikong magfa-flag ng "Ready for Disposition" kapag ang dokumento 
    // ay lagpas na sa itinakdang retention period mula nung na-upload ito.
    // =========================================================================
    $conn->query("
        UPDATE documents d
        JOIN retention_policies p ON d.policy_id = p.policy_id
        SET d.disposition_status = 'Ready for Disposition'
        WHERE d.disposition_status = 'Pending' 
        AND DATE_ADD(d.uploaded_at, INTERVAL (p.retention_years * 12 + p.retention_months) MONTH) <= CURDATE()
    ");

} catch (mysqli_sql_exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("System Maintenance: Unable to connect to the database. Please try again later.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', 
        'secure' => isset($_SERVER['HTTPS']), // Magiging true kung naka HTTPS
        'httponly' => true,                   // XSS Protection
        'samesite' => 'Strict'                // CSRF Protection
    ]);
    session_start();
}

// Generate CSRF token minsan lang para maiwasan ang redundant code
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
?>