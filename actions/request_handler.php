<?php
session_start();
require '../config/db_connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../libs/src/Exception.php';
require '../libs/src/PHPMailer.php';
require '../libs/src/SMTP.php';

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $public_actions = ['request_forgot_password', 'verify_reset_code', 'execute_reset_password'];
    if (!in_array($action, $public_actions)) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Security Error: Invalid CSRF Token");
        }
    }

    if ($action == 'request_forgot_password') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        
        $u = $conn->prepare("SELECT user_id, full_name FROM users WHERE username = ? AND email = ?");
        $u->bind_param("ss", $username, $email);
        $u->execute();
        $res = $u->get_result();

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $user_id = $user['user_id'];
            $fullname = $user['full_name'];
            
            $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expire = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?");
            $upd->bind_param("si", $code, $user_id);
            $upd->execute();

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'tamayolhei5@gmail.com'; 
                $mail->Password   = 'wewnzrsryelddatr';   
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;
                $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

                $mail->setFrom($mail->Username, 'Fixie DRMS System');
                $mail->addAddress($email, $fullname);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Code - Fixie DRMS';
                $mail->Body    = "<h3>Hello {$fullname},</h3><p>Use the code below to reset your password:</p><h1 style='color: #2563EB; letter-spacing: 5px;'>{$code}</h1><p>Expires in 15 mins.</p>";

                $mail->send();
                header("Location: ../forgot_password.php?step=2&email=" . urlencode($email));
            } catch (Exception $e) {
                header("Location: ../forgot_password.php?error=EmailError");
            }
        } else {
            header("Location: ../forgot_password.php?error=AccountMismatch");
        }
        exit();
    }

    if ($action == 'verify_reset_code') {
        $email = $_POST['email'];
        $code = trim($_POST['code']);

        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND reset_token = ? AND reset_token_expire > NOW()");
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            header("Location: ../reset_password.php?token=" . $code . "&email=" . urlencode($email));
        } else {
            header("Location: ../forgot_password.php?step=2&email=" . urlencode($email) . "&error=InvalidCode");
        }
        exit();
    }

    if ($action == 'execute_reset_password') {
        $code = $_POST['token'];
        $email = $_POST['email'];
        $new_pass = trim($_POST['new_password']);

        $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND reset_token = ? AND reset_token_expire > NOW()");
        $chk->bind_param("ss", $email, $code);
        $chk->execute();
        $res = $chk->get_result();

        if ($res->num_rows > 0) {
            $user_id = $res->fetch_assoc()['user_id'];
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expire = NULL WHERE user_id = ?");
            $upd->bind_param("si", $new_hash, $user_id);
            
            if ($upd->execute()) {
                header("Location: ../index.php?success=PasswordResetComplete");
            } else {
                header("Location: ../reset_password.php?token=$code&email=$email&error=SystemError");
            }
        } else {
            header("Location: ../forgot_password.php?error=InvalidOrExpiredToken");
        }
        exit();
    }

    if ($action == 'submit_request') {
        if(!isset($_SESSION['user_id'])) die("Unauthorized");
        
        $user_id = $_SESSION['user_id'];
        $type = $_POST['request_type']; // Should be "Change Username"
        $new_val = trim($_POST['new_value']);
        $current_pwd = $_POST['current_password'];

        // Verify current password before allowing a request
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current_pwd, $res['password_hash'])) {
            header("Location: ../settings.php?error=WrongCurrentPassword");
            exit();
        }

        $ins = $conn->prepare("INSERT INTO user_requests (user_id, request_type, new_value, status) VALUES (?, ?, ?, 'Pending')");
        $ins->bind_param("iss", $user_id, $type, $new_val);
        
        if($ins->execute()){
            header("Location: ../settings.php?success=RequestSubmitted");
        } else {
            header("Location: ../settings.php?error=DatabaseError");
        }
        exit();
    }

    if ($action == 'manage_request') {
        if ($_SESSION['role'] !== 'Admin') die("Unauthorized");

        $req_id = $_POST['request_id'];
        $decision = $_POST['decision'];

        $q = $conn->prepare("SELECT * FROM user_requests WHERE request_id = ?");
        $q->bind_param("i", $req_id);
        $q->execute();
        $req = $q->get_result()->fetch_assoc();

        if ($req) {
            $status_req = ($decision == 'Approve') ? 'Approved' : 'Rejected';

            if ($decision == 'Approve') {
                if ($req['request_type'] == 'Change Username') {
                    $upd = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?");
                    $upd->bind_param("si", $req['new_value'], $req['user_id']);
                    $upd->execute();
                } 
                // Removed the "Change Password" approval logic completely
            }

            $upd_req = $conn->prepare("UPDATE user_requests SET status = ? WHERE request_id = ?");
            $upd_req->bind_param("si", $status_req, $req_id);
            $upd_req->execute();
        }

        header("Location: ../admin_requests.php?success=ActionCompleted");
        exit();
    }
}
?>