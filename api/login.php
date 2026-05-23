<?php
require '../config/db_connect.php'; 
require '../config/functions.php'; 

header('Content-Type: application/json');

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if ($_SESSION['login_attempts'] >= 5) {
    if (time() - $_SESSION['last_attempt_time'] < 300) { 
        echo json_encode(["status" => "error", "message" => "Too many failed attempts. Please try again after 5 minutes."]);
        exit();
    } else {
        $_SESSION['login_attempts'] = 0; 
    }
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
            
            $_SESSION['login_attempts'] = 0;

            $_SESSION['user_id'] = $id;
            $_SESSION['role'] = $role;

            log_audit_action($conn, $id, 'LOGIN', 'User logged in via API');

            echo json_encode(["status" => "success", "redirect" => "dashboard.php"]);
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            echo json_encode(["status" => "error", "message" => "Invalid password"]);
        }
    } else {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
        echo json_encode(["status" => "error", "message" => "User not found"]);
    }
    $stmt->close();
}
?>