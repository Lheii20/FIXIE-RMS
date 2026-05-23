<?php
session_start();
require '../config/db_connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../libs/src/Exception.php';
require '../libs/src/PHPMailer.php';
require '../libs/src/SMTP.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: ../dashboard.php?error=SecurityTokenMismatch");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action == 'create_user') {
        if ($_SESSION['role'] !== 'Admin') die("Access Denied");
        $fullname = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role = $_POST['role'];

        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if($check->get_result()->num_rows > 0){
            header("Location: ../admin_users.php?error=UsernameExists"); exit();
        }

        // Password Validation for Create User
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
            header("Location: ../admin_users.php?error=WeakPassword"); 
            exit();
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role, status) VALUES (?, ?, ?, ?, 'Active')");
        $stmt->bind_param("ssss", $fullname, $username, $hash, $role);
        
        if($stmt->execute()){ header("Location: ../admin_users.php?success=UserCreated"); } 
        else { header("Location: ../admin_users.php?error=DatabaseError"); }
        exit();
    }

    if ($action == 'request_username') {
        $user_id = $_SESSION['user_id'];
        $new_username = trim($_POST['new_username']);

        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param("s", $new_username);
        $check->execute();
        if($check->get_result()->num_rows > 0){
            header("Location: ../settings.php?error=UsernameTaken"); 
            exit();
        }

        $request_type = 'Change Username';
        $status = 'Pending';
        $stmt = $conn->prepare("INSERT INTO user_requests (user_id, request_type, new_value, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $request_type, $new_username, $status);
        
        if($stmt->execute()) {
            header("Location: ../settings.php?success=UsernameRequestSubmitted");
        } else {
            header("Location: ../settings.php?error=RequestFailed");
        }
        exit();
    }

    if ($action == 'change_password_direct') {
        $user_id = $_SESSION['user_id'];
        $current_pass = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current_pass, $res['password_hash'])) {
            header("Location: ../settings.php?error=WrongCurrentPassword");
            exit();
        }

        if ($new_pass !== $confirm_pass) {
            header("Location: ../settings.php?error=PasswordMismatch");
            exit();
        }

        // Updated Password Validation for Change Password
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_pass)) {
            header("Location: ../settings.php?error=WeakPassword"); 
            exit();
        }

        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $update->bind_param("si", $hash, $user_id);
        
        if ($update->execute()) {
            header("Location: ../settings.php?success=PasswordUpdated");
        } else {
            header("Location: ../settings.php?error=DatabaseError");
        }
        exit();
    }

    if ($action == 'update_basic_info') {
        $user_id = $_SESSION['user_id'];
        $full_name = trim($_POST['full_name']);
        $new_email = trim($_POST['email']);

        // Email Format Validation
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            header("Location: ../settings.php?error=InvalidEmailFormat");
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE user_id = ?");
        $stmt->bind_param("si", $full_name, $user_id);
        $stmt->execute();
        $_SESSION['fullname'] = $full_name;

        $q = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
        $q->bind_param("i", $user_id);
        $q->execute();
        $current_email = $q->get_result()->fetch_assoc()['email'];

        if ($new_email !== $current_email) {
            $check = $conn->prepare("SELECT user_id FROM users WHERE (email = ? OR pending_email = ?) AND user_id != ?");
            $check->bind_param("ssi", $new_email, $new_email, $user_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                header("Location: ../settings.php?error=EmailAlreadyInUse");
                exit();
            }

            $verification_code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

            $upd = $conn->prepare("UPDATE users SET pending_email = ?, email_verification_code = ?, email_code_expire = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?");
            $upd->bind_param("ssi", $new_email, $verification_code, $user_id);
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
                
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom($mail->Username, 'Fixie DRMS Security');
                $mail->addAddress($new_email, $full_name);

                $mail->isHTML(true);
                $mail->Subject = 'Your Email Verification Code';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; padding: 20px;'>
                        <h2>Email Verification</h2>
                        <p>Hello {$full_name},</p>
                        <p>You requested to use this email for your Fixie DRMS account. Please use the verification code below to confirm:</p>
                        <h1 style='color: #2563EB; letter-spacing: 5px; background: #F8FAFC; padding: 15px; border-radius: 8px; width: fit-content;'>{$verification_code}</h1>
                        <p>This code will expire in 15 minutes.</p>
                        <p>If you didn't request this change, you can safely ignore this email.</p>
                    </div>
                ";

                $mail->send();
                header("Location: ../settings.php?success=CodeSent");
                exit();
                
            } catch (Exception $e) {
                $conn->query("UPDATE users SET pending_email = NULL, email_verification_code = NULL WHERE user_id = $user_id");
                die("<div style='padding: 20px; font-family: sans-serif;'>
                        <h2 style='color: red;'>Email Error</h2>
                        <p><b>Technical Error:</b> {$mail->ErrorInfo}</p>
                        <a href='../settings.php'>Back to Settings</a>
                     </div>");
            }
        }

        header("Location: ../settings.php?success=InfoUpdated");
        exit();
    }

    if ($action == 'verify_email_code') {
        $user_id = $_SESSION['user_id'];
        $code_entered = trim($_POST['verification_code']);

        $stmt = $conn->prepare("SELECT pending_email FROM users WHERE user_id = ? AND email_verification_code = ? AND email_code_expire > NOW()");
        $stmt->bind_param("is", $user_id, $code_entered);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $pending_email = $res->fetch_assoc()['pending_email'];
            $upd = $conn->prepare("UPDATE users SET email = ?, pending_email = NULL, email_verification_code = NULL, email_code_expire = NULL WHERE user_id = ?");
            $upd->bind_param("si", $pending_email, $user_id);
            $upd->execute();

            header("Location: ../settings.php?success=EmailVerified");
        } else {
            header("Location: ../settings.php?error=InvalidCode");
        }
        exit();
    }

    if ($action == 'cancel_email_change') {
        $user_id = $_SESSION['user_id'];
        $conn->query("UPDATE users SET pending_email = NULL, email_verification_code = NULL, email_code_expire = NULL WHERE user_id = $user_id");
        header("Location: ../settings.php");
        exit();
    }

    if ($action == 'delete_user') {
        if ($_SESSION['role'] !== 'Admin') { header("Location: ../dashboard.php?error=Unauthorized"); exit(); }
        $user_id_to_delete = $_POST['user_id'];
        if ($user_id_to_delete == $_SESSION['user_id']) { header("Location: ../admin_users.php?error=CannotDeleteSelf"); exit(); }

        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id_to_delete);
        if ($stmt->execute()) { header("Location: ../admin_users.php?success=UserDeleted"); } 
        else { header("Location: ../admin_users.php?error=DeleteFailed"); }
        exit();
    }

    if ($action == 'admin_update_self') {
        if ($_SESSION['role'] !== 'Admin') { header("Location: ../dashboard.php?error=Unauthorized"); exit(); }
        $user_id = $_SESSION['user_id'];
        $username = trim($_POST['username']);
        $new_pass = $_POST['new_password'];

        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?");
        $stmt->bind_param("si", $username, $user_id);
        $stmt->execute();

        if (!empty($new_pass)) {
            // Updated Password Validation for Admin Update Self
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_pass)) {
                header("Location: ../settings.php?error=WeakPasswordAdmin"); exit();
            }
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt2->bind_param("si", $hash, $user_id);
            $stmt2->execute();
        }
        header("Location: ../settings.php?success=CredentialsUpdated");
        exit();
    }

    if ($action == 'upload_avatar') {
        $user_id = $_SESSION['user_id'];
        if(isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0){
            
            // Avatar Size Validation (5MB Max)
            $max_avatar_size = 5 * 1024 * 1024;
            if ($_FILES['avatar']['size'] > $max_avatar_size) {
                header("Location: ../settings.php?error=AvatarSizeTooLarge");
                exit();
            }

            // MIME Type Validation (Hindi lang extension)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->file($_FILES['avatar']['tmp_name']);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg'];

            if (!in_array($mime_type, $allowed_mimes)) {
                header("Location: ../settings.php?error=InvalidAvatarMimeType");
                exit();
            }

            $allowed = ['jpg', 'jpeg', 'png'];
            $filename = $_FILES['avatar']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if(in_array($ext, $allowed)){
                $new_name = "avatar_" . $user_id . "_" . time() . "." . $ext;
                $target = "../uploads/avatars/" . $new_name;
                if (!is_dir('../uploads/avatars')) { mkdir('../uploads/avatars', 0777, true); }
                if(move_uploaded_file($_FILES['avatar']['tmp_name'], $target)){
                    $db_path = "uploads/avatars/" . $new_name;
                    $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE user_id = ?");
                    $stmt->bind_param("si", $db_path, $user_id);
                    $stmt->execute();
                    $_SESSION['avatar'] = $db_path;
                    header("Location: ../settings.php?success=AvatarUpdated");
                    exit();
                }
            }
        }
        header("Location: ../settings.php?error=UploadFailed");
        exit();
    }
}
header("Location: ../dashboard.php");
exit();
?>