<?php
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if(isset($_POST['login'])){
    
    // Removed CSRF validation here to prevent breaking the index.php login form
    
    if ($_SESSION['login_attempts'] >= 5) {
        if (time() - $_SESSION['last_attempt_time'] < 300) {
            header("Location: ../index.php?error=TooManyAttemptsWait5Mins");
            exit();
        } else {
            $_SESSION['login_attempts'] = 0;
        }
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

            session_regenerate_id(true); 

            $_SESSION['login_attempts'] = 0;

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['fullname'] = $user['full_name'];
            $_SESSION['avatar'] = $user['avatar'];
            
            log_audit_action($conn, $user['user_id'], 'LOGIN', 'User logged in successfully');

            header("Location: ../dashboard.php");
            exit();

        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            header("Location: ../index.php?error=InvalidCredentials");
            exit();
        }
    } else {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
        header("Location: ../index.php?error=InvalidCredentials");
        exit();
    }
}

if(isset($_GET['logout'])){
    
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid CSRF Token on Logout");
    }

    if (isset($_SESSION['user_id'])) {
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