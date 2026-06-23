<?php
require '../config/db_connect.php'; 
require '../config/functions.php'; 

header('Content-Type: application/json');

$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// 1. Check DB for recent failed attempts
$stmt_check_attempts = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = ? AND attempt_time > NOW() - INTERVAL 5 MINUTE");
$stmt_check_attempts->bind_param("s", $ip_address);
$stmt_check_attempts->execute();
$attempts = $stmt_check_attempts->get_result()->fetch_assoc()['attempts'];

if ($attempts >= 5) {
    echo json_encode(["status" => "error", "message" => "Too many failed attempts. Please try again after 5 minutes."]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, password_hash, role, status FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $hash, $role, $status);
        $stmt->fetch();

        if (password_verify($password, $hash)) {
            
            if ($status !== 'Active') {
                echo json_encode(["status" => "error", "message" => "Account is locked or inactive. Please contact Admin."]);
                exit();
            }

            session_regenerate_id(true);
            
            // Clear IP attempts on successful login
            $stmt_clear = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt_clear->bind_param("s", $ip_address);
            $stmt_clear->execute();

            $session_token = bin2hex(random_bytes(32));

            $_SESSION['user_id'] = $id;
            $_SESSION['role'] = $role;
            $_SESSION['session_token'] = $session_token;

            $conn->query("UPDATE users SET session_token = '$session_token', last_active = NOW() WHERE user_id = " . intval($id));

            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $id, 'LOGIN', 'User logged in via API');
            }

            echo json_encode(["status" => "success", "redirect" => "dashboard.php"]);
        } else {
            // Log failed attempt to DB
            $stmt_fail = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time) VALUES (?, ?, NOW())");
            $stmt_fail->bind_param("ss", $ip_address, $username);
            $stmt_fail->execute();
            
            echo json_encode(["status" => "error", "message" => "Invalid password"]);
        }
    } else {
        // Log failed attempt to DB
        $stmt_fail = $conn->prepare("INSERT INTO login_attempts (ip_address, username, attempt_time) VALUES (?, ?, NOW())");
        $stmt_fail->bind_param("ss", $ip_address, $username);
        $stmt_fail->execute();

        echo json_encode(["status" => "error", "message" => "User not found"]);
    }
    $stmt->close();
}
?>