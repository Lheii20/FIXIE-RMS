<?php
session_start();
require '../config/db_connect.php';
require '../config/mailer.php';

// I-load ang PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/src/Exception.php';
require '../libs/src/PHPMailer.php';
require '../libs/src/SMTP.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================
    // STEP 1: SEND OTP (Unchanged)
    // =========================================
    if ($action === 'send_code') {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ? AND status = 'Active' LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $expire_stmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE email = ? AND status = 'Pending'");
            $expire_stmt->bind_param("s", $email);
            $expire_stmt->execute();
            $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            $insert = $conn->prepare("INSERT INTO otp_auth_tokens (user_id, email, otp_code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
            $insert->bind_param("iss", $user['user_id'], $email, $otp_code);
            
            if ($insert->execute()) {
                $mail = new PHPMailer(true);
                try {
                    drms_configure_mailer($mail, [
                        'from' => getenv('DRMS_MAIL_FROM') ?: 'no-reply@fixieventures.com',
                        'from_name' => 'Fixie DRMS System'
                    ]);
                    $mail->addAddress($email);

                    $mail->isHTML(false);
                    $mail->Subject = 'Your Login Verification Code - Fixie DRMS';
                    $mail->Body    = "Hello " . htmlspecialchars($user['full_name']) . ",\n\n"
                                   . "Your login verification code is:\n\n"
                                   . $otp_code . "\n\n"
                                   . "This code will expire in 5 minutes.\n"
                                   . "If you did not request this login, please secure your account immediately.\n\n"
                                   . "Best regards,\nFixie Computer Ventures";

                    $mail->send();
                    echo json_encode(['status' => 'success', 'message' => 'Verification code sent to your email.']);
                    exit;

                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo]);
                    exit;
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'message' => 'If the email is registered, a code has been sent.']);
        exit;
    }

    // =========================================
    // STEP 2: VERIFY OTP (FIXED SESSION LOGIC)
    // =========================================
    if ($action === 'verify_code') {
        $email = trim($_POST['email'] ?? '');
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');

        $stmt = $conn->prepare("SELECT * FROM otp_auth_tokens WHERE email = ? AND status = 'Pending' AND expires_at > NOW() ORDER BY otp_id DESC LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $token = $stmt->get_result()->fetch_assoc();

        if ($token) {
            if ($token['attempt_count'] >= 3) {
                $conn->query("UPDATE otp_auth_tokens SET status = 'Expired' WHERE otp_id = {$token['otp_id']}");
                echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Please request a new code.']);
                exit;
            }

            if ((string)$code === (string)$token['otp_code']) {
                // SUCCESS
                $conn->query("UPDATE otp_auth_tokens SET status = 'Verified' WHERE otp_id = {$token['otp_id']}");

                $u_stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND status = 'Active'");
                $u_stmt->bind_param("i", $token['user_id']);
                $u_stmt->execute();
                $user_data = $u_stmt->get_result()->fetch_assoc();

                if ($user_data) {
                    session_regenerate_id(true);
                    
                    // Generate missing Security Token for real-time validation
                    $session_token = bin2hex(random_bytes(32));

                    $_SESSION['user_id'] = $user_data['user_id'];
                    $_SESSION['role'] = $user_data['role'];
                    $_SESSION['username'] = $user_data['username'];
                    $_SESSION['fullname'] = $user_data['full_name'];
                    $_SESSION['avatar'] = $user_data['avatar'] ?? '';
                    $_SESSION['session_token'] = $session_token;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    // Write Session Token to DB so `db_connect.php` doesn't reject it
                    $conn->query("UPDATE users SET session_token = '$session_token', last_active = NOW() WHERE user_id = " . intval($user_data['user_id']));

                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
                    $log_desc = "User logged in successfully via Email OTP";
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'LOGIN', ?, ?)");
                    $log_stmt->bind_param("iss", $user_data['user_id'], $log_desc, $ip);
                    $log_stmt->execute();

                    echo json_encode(['status' => 'success', 'redirect' => 'dashboard.php']);
                    exit;
                }
            } else {
                // FAIL
                $conn->query("UPDATE otp_auth_tokens SET attempt_count = attempt_count + 1 WHERE otp_id = {$token['otp_id']}");
                echo json_encode(['status' => 'error', 'message' => 'Invalid verification code. Please check your latest email.']);
                exit;
            }
        }
        
        echo json_encode(['status' => 'error', 'message' => 'Code is invalid or has expired. Request a new one.']);
        exit;
    }
}
?>