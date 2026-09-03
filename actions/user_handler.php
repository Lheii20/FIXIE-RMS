<?php
require_once __DIR__ . '/../config/session_bootstrap.php';
require_once __DIR__ . '/../config/upload_policy.php';
require '../config/db_connect.php';
require '../config/functions.php';
require '../config/mailer.php';

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

function drms_user_settings_redirect(string $type, string $code): void
{
    header('Location: ../settings.php?' . $type . '=' . rawurlencode($code));
    exit();
}

function drms_normalize_account_email($value): string
{
    return strtolower(trim((string) $value));
}

function drms_valid_account_email(string $email): bool
{
    return $email !== ''
        && strlen($email) <= 100
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function drms_valid_account_password(string $password): bool
{
    return strlen($password) >= 8
        && strlen($password) <= 128
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password);
}

function drms_admin_users_redirect(string $type, string $code): void
{
    header('Location: ../admin_users.php?' . $type . '=' . rawurlencode($code));
    exit();
}

function drms_valid_full_name(string $fullName): bool
{
    return strlen($fullName) >= 2 && strlen($fullName) <= 100;
}

function drms_normalize_username($value): string
{
    return strtolower(trim((string) $value));
}

function drms_valid_username(string $username): bool
{
    return preg_match('/^(?=.{3,50}$)[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $username) === 1;
}

function drms_expire_user_access_tokens(mysqli $conn, int $userId): void
{
    $resetStmt = $conn->prepare(
        "UPDATE password_reset_otps SET status = 'Expired'
         WHERE user_id = ? AND status IN ('Pending', 'Verified')"
    );
    $resetStmt->bind_param('i', $userId);
    $resetStmt->execute();
    $resetStmt->close();

    $loginOtpStmt = $conn->prepare(
        "UPDATE otp_auth_tokens SET status = 'Expired'
         WHERE user_id = ? AND status = 'Pending'"
    );
    $loginOtpStmt->bind_param('i', $userId);
    $loginOtpStmt->execute();
    $loginOtpStmt->close();
}

function drms_get_user_for_update(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT user_id, username, full_name, email, role, status,
                setup_token_purpose, setup_token_sent_at
         FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: ../dashboard.php?error=SecurityTokenMismatch");
        exit();
    }

    $action = $_POST['action'] ?? '';

    $self_service_actions = [
        'upload_avatar',
        'update_basic_info',
        'verify_email_code',
        'cancel_email_change',
        'change_password_direct'
    ];

    if (in_array($action, $self_service_actions, true)) {
        $current_user_id = (int) $_SESSION['user_id'];

        if ($action === 'change_password_direct') {
            $current_password = (string) ($_POST['current_password'] ?? '');
            $new_password = (string) ($_POST['new_password'] ?? '');
            $confirm_password = (string) ($_POST['confirm_password'] ?? '');

            if ($new_password !== $confirm_password) {
                drms_user_settings_redirect('error', 'PasswordMismatch');
            }

            if (!drms_valid_account_password($new_password)) {
                drms_user_settings_redirect('error', 'WeakPassword');
            }

            $password_stmt = $conn->prepare(
                "SELECT password_hash FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1"
            );
            $password_stmt->bind_param('i', $current_user_id);
            $password_stmt->execute();
            $password_user = $password_stmt->get_result()->fetch_assoc();

            if (!$password_user || !password_verify($current_password, $password_user['password_hash'])) {
                drms_user_settings_redirect('error', 'WrongCurrentPassword');
            }

            if (password_verify($new_password, $password_user['password_hash'])) {
                drms_user_settings_redirect('error', 'PasswordReused');
            }

            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $new_session_token = bin2hex(random_bytes(32));
            $change_stmt = $conn->prepare(
                "UPDATE users
                 SET password_hash = ?, session_token = ?, require_pass_change = 0,
                     reset_token = NULL, reset_token_expire = NULL,
                     setup_token = NULL, setup_token_purpose = NULL,
                     setup_token_sent_at = NULL, setup_token_expire = NULL,
                     last_active = NOW()
                 WHERE user_id = ?"
            );
            $change_stmt->bind_param('ssi', $new_password_hash, $new_session_token, $current_user_id);
            $change_stmt->execute();

            if ($change_stmt->affected_rows !== 1) {
                drms_user_settings_redirect('error', 'AccountUpdateFailed');
            }

            drms_expire_user_access_tokens($conn, $current_user_id);

            session_regenerate_id(true);
            $_SESSION['session_token'] = $new_session_token;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['last_activity'] = time();

            log_audit_action($conn, $current_user_id, 'PASSWORD_CHANGE', 'User changed the account password; other sessions and pending recovery codes were revoked.');
            drms_user_settings_redirect('success', 'PasswordUpdated');
        }

        if ($action === 'cancel_email_change') {
            $cancel_stmt = $conn->prepare(
                "UPDATE users
                 SET pending_email = NULL, email_verification_code = NULL,
                     email_code_expire = NULL, email_verification_attempts = 0,
                     email_verification_last_sent_at = NULL
                 WHERE user_id = ?"
            );
            $cancel_stmt->bind_param('i', $current_user_id);
            $cancel_stmt->execute();
            log_audit_action($conn, $current_user_id, 'EMAIL_CHANGE_CANCELLED', 'User cancelled the pending recovery-email change.');
            drms_user_settings_redirect('success', 'EmailChangeCancelled');
        }

        if ($action === 'verify_email_code') {
            $verification_code = preg_replace('/\D/', '', (string) ($_POST['verification_code'] ?? ''));
            if (!preg_match('/^\d{6}$/', $verification_code)) {
                drms_user_settings_redirect('error', 'InvalidCode');
            }

            $verify_stmt = $conn->prepare(
                "SELECT pending_email, email_verification_code,
                        email_verification_attempts,
                        (email_code_expire > NOW()) AS code_is_current
                 FROM users WHERE user_id = ? LIMIT 1"
            );
            $verify_stmt->bind_param('i', $current_user_id);
            $verify_stmt->execute();
            $verification = $verify_stmt->get_result()->fetch_assoc();

            $pending_email = drms_normalize_account_email($verification['pending_email'] ?? '');
            $stored_code_hash = (string) ($verification['email_verification_code'] ?? '');
            $attempts = (int) ($verification['email_verification_attempts'] ?? 0);
            $not_expired = (int) ($verification['code_is_current'] ?? 0) === 1;

            if ($pending_email === '' || !$not_expired || $attempts >= 5 || !password_verify($verification_code, $stored_code_hash)) {
                $new_attempts = min(5, $attempts + 1);
                $failed_status = $new_attempts >= 5 ? 'Expired' : 'Pending';
                if ($failed_status === 'Expired' || !$not_expired) {
                    $failed_stmt = $conn->prepare(
                        "UPDATE users
                         SET email_verification_attempts = ?, email_verification_code = NULL,
                             email_code_expire = NULL
                         WHERE user_id = ?"
                    );
                } else {
                    $failed_stmt = $conn->prepare(
                        "UPDATE users SET email_verification_attempts = ? WHERE user_id = ?"
                    );
                }
                $failed_stmt->bind_param('ii', $new_attempts, $current_user_id);
                $failed_stmt->execute();
                drms_user_settings_redirect('error', $new_attempts >= 5 ? 'TooManyEmailCodeAttempts' : 'InvalidCode');
            }

            $duplicate_stmt = $conn->prepare(
                "SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1"
            );
            $duplicate_stmt->bind_param('si', $pending_email, $current_user_id);
            $duplicate_stmt->execute();
            if ($duplicate_stmt->get_result()->num_rows > 0) {
                drms_user_settings_redirect('error', 'EmailAlreadyInUse');
            }

            $new_session_token = bin2hex(random_bytes(32));
            $apply_email_stmt = $conn->prepare(
                "UPDATE users
                 SET email = ?, pending_email = NULL, email_verification_code = NULL,
                      email_code_expire = NULL, email_verification_attempts = 0,
                      email_verification_last_sent_at = NULL,
                      session_token = ?, last_active = NOW()
                 WHERE user_id = ? AND pending_email = ?
                   AND email_verification_code = ?
                   AND email_code_expire > NOW()
                   AND email_verification_attempts < 5"
            );
            $apply_email_stmt->bind_param('ssiss', $pending_email, $new_session_token, $current_user_id, $pending_email, $stored_code_hash);
            try {
                $apply_email_stmt->execute();
            } catch (mysqli_sql_exception $email_error) {
                if ((int) $email_error->getCode() === 1062) {
                    drms_user_settings_redirect('error', 'EmailAlreadyInUse');
                }
                throw $email_error;
            }

            if ($apply_email_stmt->affected_rows !== 1) {
                drms_user_settings_redirect('error', 'InvalidCode');
            }

            drms_expire_user_access_tokens($conn, $current_user_id);
            session_regenerate_id(true);
            $_SESSION['session_token'] = $new_session_token;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['last_activity'] = time();
            log_audit_action($conn, $current_user_id, 'EMAIL_CHANGED', 'User verified and changed the account recovery email.');
            drms_user_settings_redirect('success', 'EmailVerified');
        }

        if ($action === 'update_basic_info') {
            $full_name = trim((string) ($_POST['full_name'] ?? ''));
            $requested_email = drms_normalize_account_email($_POST['email'] ?? '');

            if ($full_name === '' || strlen($full_name) > 100) {
                drms_user_settings_redirect('error', 'InvalidFullName');
            }
            if (!drms_valid_account_email($requested_email)) {
                drms_user_settings_redirect('error', 'InvalidEmail');
            }

            $account_stmt = $conn->prepare(
                "SELECT email, pending_email,
                        TIMESTAMPDIFF(SECOND, email_verification_last_sent_at, NOW()) AS seconds_since_last
                 FROM users WHERE user_id = ? LIMIT 1"
            );
            $account_stmt->bind_param('i', $current_user_id);
            $account_stmt->execute();
            $account = $account_stmt->get_result()->fetch_assoc();
            if (!$account) {
                drms_user_settings_redirect('error', 'AccountUpdateFailed');
            }

            $current_email = drms_normalize_account_email($account['email'] ?? '');
            if ($requested_email === $current_email) {
                $name_stmt = $conn->prepare(
                    "UPDATE users
                     SET full_name = ?, pending_email = NULL,
                         email_verification_code = NULL, email_code_expire = NULL,
                         email_verification_attempts = 0,
                         email_verification_last_sent_at = NULL
                     WHERE user_id = ?"
                );
                $name_stmt->bind_param('si', $full_name, $current_user_id);
                $name_stmt->execute();
                $_SESSION['fullname'] = $full_name;
                log_audit_action($conn, $current_user_id, 'PROFILE_UPDATED', 'User updated the account full name.');
                drms_user_settings_redirect('success', 'ProfileUpdated');
            }

            $seconds_since_last = $account['seconds_since_last'] !== null
                ? max(0, (int) $account['seconds_since_last'])
                : null;
            if ($seconds_since_last !== null && $seconds_since_last < 60) {
                drms_user_settings_redirect('error', 'EmailCodeCooldown');
            }

            $duplicate_email_stmt = $conn->prepare(
                "SELECT user_id FROM users
                 WHERE user_id <> ? AND (email = ? OR pending_email = ?) LIMIT 1"
            );
            $duplicate_email_stmt->bind_param('iss', $current_user_id, $requested_email, $requested_email);
            $duplicate_email_stmt->execute();
            if ($duplicate_email_stmt->get_result()->num_rows > 0) {
                drms_user_settings_redirect('error', 'EmailAlreadyInUse');
            }

            $email_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $email_code_hash = password_hash($email_code, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                $pending_stmt = $conn->prepare(
                    "UPDATE users
                     SET full_name = ?, pending_email = ?, email_verification_code = ?,
                         email_code_expire = DATE_ADD(NOW(), INTERVAL 10 MINUTE), email_verification_attempts = 0,
                         email_verification_last_sent_at = NOW()
                     WHERE user_id = ?"
                );
                $pending_stmt->bind_param('sssi', $full_name, $requested_email, $email_code_hash, $current_user_id);
                $pending_stmt->execute();

                $mail = new PHPMailer(true);
                drms_configure_mailer($mail, ['from_name' => 'Fixie DRMS Security']);
                $mail->addAddress($requested_email, $full_name);
                $mail->Subject = 'Fixie DRMS - Verify Your New Recovery Email';
                $mail->isHTML(false);
                $mail->Body = "Hello " . $full_name . ",\n\n"
                    . "Your Fixie DRMS recovery-email verification code is:\n\n"
                    . $email_code . "\n\n"
                    . "This code expires in 10 minutes and allows up to 5 attempts.\n"
                    . "If you did not request this change, ignore this email and cancel the pending change in Account Settings.";
                $mail->send();

                $conn->commit();
            } catch (Throwable $email_change_error) {
                $conn->rollback();
                error_log('Account recovery-email change failed: ' . $email_change_error->getMessage());
                drms_user_settings_redirect('error', 'VerificationEmailFailed');
            }

            $_SESSION['fullname'] = $full_name;
            log_audit_action($conn, $current_user_id, 'EMAIL_CHANGE_REQUESTED', 'User requested verification of a new recovery email.');
            drms_user_settings_redirect('success', 'CodeSent');
        }

        if ($action === 'upload_avatar') {
            try {
                $validated_avatar = drms_upload_validate(
                    $conn,
                    $_FILES['avatar'] ?? null,
                    'profile'
                );
            } catch (DrmsUploadValidationException $avatar_validation_error) {
                $validation_code = $avatar_validation_error->validationCode();
                if (
                    $validation_code === 'FileSizeExceeded' ||
                    (
                        $validation_code === 'UploadError' &&
                        strpos($avatar_validation_error->getMessage(), 'exceeds') !== false
                    )
                ) {
                    drms_user_settings_redirect('error', 'AvatarTooLarge');
                }
                if (in_array($validation_code, ['InvalidFileType', 'InvalidFileContent'], true)) {
                    drms_user_settings_redirect('error', 'InvalidAvatarType');
                }
                drms_user_settings_redirect('error', 'AvatarUploadFailed');
            }

            $avatar_directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
            if (!is_dir($avatar_directory) && !mkdir($avatar_directory, 0755, true)) {
                drms_user_settings_redirect('error', 'AvatarUploadFailed');
            }

            $avatar_filename = 'avatar_' . $current_user_id . '_' . bin2hex(random_bytes(8)) . '.' . $validated_avatar['extension'];
            $absolute_avatar_path = $avatar_directory . DIRECTORY_SEPARATOR . $avatar_filename;
            $stored_avatar_path = 'uploads/avatars/' . $avatar_filename;

            if (!move_uploaded_file($validated_avatar['tmp_name'], $absolute_avatar_path)) {
                drms_user_settings_redirect('error', 'AvatarUploadFailed');
            }

            $old_avatar_stmt = $conn->prepare("SELECT avatar FROM users WHERE user_id = ? LIMIT 1");
            $old_avatar_stmt->bind_param('i', $current_user_id);
            $old_avatar_stmt->execute();
            $old_avatar = (string) ($old_avatar_stmt->get_result()->fetch_assoc()['avatar'] ?? '');

            try {
                $avatar_stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE user_id = ?");
                $avatar_stmt->bind_param('si', $stored_avatar_path, $current_user_id);
                $avatar_stmt->execute();
            } catch (Throwable $avatar_error) {
                @unlink($absolute_avatar_path);
                throw $avatar_error;
            }

            $_SESSION['avatar'] = $stored_avatar_path;

            if ($old_avatar !== '' && preg_match('#^uploads/avatars/[A-Za-z0-9._-]+$#', $old_avatar)) {
                $old_absolute_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_avatar);
                $avatar_root = realpath($avatar_directory);
                $old_real_path = realpath($old_absolute_path);
                if ($avatar_root !== false && $old_real_path !== false && is_file($old_real_path) && strncmp($old_real_path, $avatar_root . DIRECTORY_SEPARATOR, strlen($avatar_root . DIRECTORY_SEPARATOR)) === 0) {
                    @unlink($old_real_path);
                }
            }

            log_audit_action($conn, $current_user_id, 'AVATAR_UPDATED', 'User updated the account profile photo.');
            drms_user_settings_redirect('success', 'AvatarUpdated');
        }
    }

    // STRICT ADMIN CHECK - Binalik natin para super secure ang User Management
    if ($_SESSION['role'] !== 'Admin') {
        die("Security Violation: Only the main Administrator can manage users.");
    }

    // Keep every user-management action aligned with the database and workflow roles.
    $allowed_roles = ['Admin', 'President', 'GM', 'Finance', 'Procurement', 'Supply Chain', 'Sales Staff'];
    $allowed_statuses = ['Active', 'Suspended'];
    ensure_rbac_tables_exist($conn);

    // Recovery emails are stored in a single normalized format so uniqueness checks
    // behave the same in User Management, sign-in OTP, and password recovery.
    $normalize_email = static function ($value): string {
        return strtolower(trim((string) $value));
    };

    $is_valid_recovery_email = static function (string $email): bool {
        return $email !== ''
            && strlen($email) <= 100
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    };

    // ===============================================
    // UPDATE USER PERMISSIONS (RBAC)
    // ===============================================
    if ($action == 'update_permissions') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $perms = $_POST['permissions'] ?? [];

        if ($target_user_id < 1 || !is_array($perms) || count($perms) > 50) {
            drms_admin_users_redirect('error', 'InvalidPermission');
        }

        $requested_permissions = [];
        foreach ($perms as $permission) {
            if (!is_string($permission) || strlen($permission) > 50) {
                drms_admin_users_redirect('error', 'InvalidPermission');
            }
            $requested_permissions[] = $permission;
        }
        $requested_permissions = array_values(array_unique($requested_permissions));

        $valid_permissions = [];
        $permission_query = $conn->query(
            "SELECT permission_name FROM permissions WHERE permission_name <> 'can_manage_users'"
        );
        while ($permission_row = $permission_query->fetch_assoc()) {
            $valid_permissions[] = (string) $permission_row['permission_name'];
        }
        if (array_diff($requested_permissions, $valid_permissions)) {
            drms_admin_users_redirect('error', 'InvalidPermission');
        }

        $conn->begin_transaction();
        try {
            $target_user = drms_get_user_for_update($conn, $target_user_id);
            if (!$target_user) {
                throw new RuntimeException('USER_NOT_FOUND');
            }

            $del = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $del->bind_param("i", $target_user_id);
            $del->execute();
            
            if (!empty($requested_permissions)) {
                $ins = $conn->prepare("INSERT INTO user_permissions (user_id, permission_name) VALUES (?, ?)");
                foreach ($requested_permissions as $p) {
                    $ins->bind_param("is", $target_user_id, $p);
                    $ins->execute();
                }
            }
            $conn->commit();
            log_audit_action(
                $conn,
                (int) $_SESSION['user_id'],
                'UPDATE_PERMISSIONS',
                'Updated capabilities for @' . $target_user['username'] . '.'
            );
            drms_admin_users_redirect('success', 'PermissionsUpdated');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e->getMessage() === 'USER_NOT_FOUND') {
                drms_admin_users_redirect('error', 'UserNotFound');
            }
            error_log('Permission update failed: ' . $e->getMessage());
            drms_admin_users_redirect('error', 'UpdateFailed');
        }
    }

    // ===============================================
    // ADD NEW USER (ZERO-TRUST TOKEN LINK)
    // ===============================================
    if ($action == 'create_user') {
        $fullname = trim((string) ($_POST['full_name'] ?? ''));
        $username = drms_normalize_username($_POST['username'] ?? '');
        $email = $normalize_email($_POST['email'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if (!drms_valid_full_name($fullname)) {
            drms_admin_users_redirect('error', 'InvalidFullName');
        }
        if (!drms_valid_username($username)) {
            drms_admin_users_redirect('error', 'InvalidUsername');
        }

        if (!$is_valid_recovery_email($email)) {
            drms_admin_users_redirect('error', 'InvalidEmail');
        }

        if (!in_array($role, $allowed_roles, true)) {
            drms_admin_users_redirect('error', 'InvalidRole');
        }

        $username_check = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $username_check->bind_param("s", $username);
        $username_check->execute();
        if ($username_check->get_result()->num_rows > 0) {
            drms_admin_users_redirect('error', 'DuplicateUsername');
        }

        $email_check = $conn->prepare(
            'SELECT user_id FROM users WHERE LOWER(TRIM(email)) = ? OR LOWER(TRIM(pending_email)) = ? LIMIT 1'
        );
        $email_check->bind_param("ss", $email, $email);
        $email_check->execute();
        if ($email_check->get_result()->num_rows > 0) {
            drms_admin_users_redirect('error', 'DuplicateEmail');
        }

        $setup_token = bin2hex(random_bytes(32));
        $setup_token_hash = hash('sha256', $setup_token);
        $dummy_hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password_hash, role, status, require_pass_change, setup_token, setup_token_purpose, setup_token_sent_at, setup_token_expire) VALUES (?, ?, ?, ?, ?, 'Active', 0, ?, 'Account Setup', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))");
            $stmt->bind_param("ssssss", $fullname, $username, $email, $dummy_hash, $role, $setup_token_hash);
            $stmt->execute();
            $new_user_id = $stmt->insert_id;
            
            // Clone the established capabilities of the oldest active account in the same role.
            $role_clone_q = $conn->prepare(
                "SELECT up.permission_name
                 FROM user_permissions up
                 WHERE up.user_id = (
                    SELECT MIN(u.user_id) FROM users u
                    WHERE u.role = ? AND u.status = 'Active' AND u.user_id <> ?
                 )"
            );
            $role_clone_q->bind_param("si", $role, $new_user_id);
            $role_clone_q->execute();
            $role_res = $role_clone_q->get_result();
            if ($role_res->num_rows > 0) {
                $ins_perm = $conn->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_name) VALUES (?, ?)");
                while($p_row = $role_res->fetch_assoc()) {
                    $ins_perm->bind_param("is", $new_user_id, $p_row['permission_name']);
                    $ins_perm->execute();
                }
            }
            $conn->commit();
        } catch (Throwable $create_error) {
            $conn->rollback();
            error_log('User creation failed: ' . $create_error->getMessage());
            if ((int) $create_error->getCode() === 1062) {
                $duplicate_key = strtolower($create_error->getMessage());
                drms_admin_users_redirect(
                    'error',
                    strpos($duplicate_key, 'username') !== false ? 'DuplicateUsername' : 'DuplicateEmail'
                );
            }
            drms_admin_users_redirect('error', 'CreateFailed');
        }

            $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https" : "http";
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            if ($host === '') {
                $host = 'localhost';
            }
            $script = dirname($_SERVER['SCRIPT_NAME']);
            $base_dir = rtrim(str_replace('/actions', '', $script), '/');
            $setup_link = $protocol . "://" . $host . $base_dir . "/setup_password.php?token=" . rawurlencode($setup_token) . "&email=" . rawurlencode($email);
            $email_fullname = htmlspecialchars($fullname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $email_username = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $email_setup_link = htmlspecialchars($setup_link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            
            $mail = new PHPMailer(true);
            try {
                drms_configure_mailer($mail, ['from_name' => 'Fixie DRMS Security']);
                $mail->addAddress($email, $fullname);
                $mail->Subject = 'Welcome to Fixie DRMS - Secure Account Setup Required';
                $mail->isHTML(true);
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;'>
                    <div style='background-color: #2a617b; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin: 0;'>Welcome, {$email_fullname}!</h2>
                    </div>
                    <div style='padding: 25px; line-height: 1.6;'>
                        <p>An administrator has provisioned an enterprise account for you with the username: <b>{$email_username}</b>.</p>
                        <p>To securely activate your account and define your own password, please click the secure link below:</p>
                        <div style='margin: 25px 0;'>
                            <a href='{$email_setup_link}' style='background-color: #2a617b; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Set Up My Password</a>
                        </div>
                        <p style='color: #dc2626; font-size: 13px;'><strong>Security Notice:</strong> This link will automatically expire in 24 hours. If it expires, contact the system administrator.</p>
                    </div>
                    <div style='background-color: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; color: #777;'>
                        &copy; " . date('Y') . " Fixie DRMS. All rights reserved.
                    </div>
                </div>";
                
                $mail->send();
                if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_USER', "Created new user: $username and sent setup link.");
                drms_admin_users_redirect('success', 'UserCreated');
            } catch (Exception $e) {
                error_log("PHPMailer Error: " . $mail->ErrorInfo);
                if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_USER', "Created new user: $username (Email Failed)");
                drms_admin_users_redirect('success', 'UserCreatedButEmailFailed');
            }
    }

    // ===============================================
    // UPDATE USER
    // ===============================================
    if ($action == 'update_user') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $fullname = trim((string) ($_POST['full_name'] ?? ''));
        $email = $normalize_email($_POST['email'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($target_user_id < 1) {
            drms_admin_users_redirect('error', 'UserNotFound');
        }
        if (!drms_valid_full_name($fullname)) {
            drms_admin_users_redirect('error', 'InvalidFullName');
        }

        if (!$is_valid_recovery_email($email)) {
            drms_admin_users_redirect('error', 'InvalidEmail');
        }

        if (!in_array($role, $allowed_roles, true)) {
            drms_admin_users_redirect('error', 'InvalidRole');
        }

        $conn->begin_transaction();
        try {
            $target_user = drms_get_user_for_update($conn, $target_user_id);
            if (!$target_user) {
                throw new RuntimeException('USER_NOT_FOUND');
            }
            if ($target_user['role'] === 'Admin' && $role !== 'Admin') {
                throw new RuntimeException('CANNOT_CHANGE_ADMIN_ROLE');
            }

            $email_check = $conn->prepare(
                "SELECT user_id FROM users
                 WHERE user_id <> ? AND (LOWER(TRIM(email)) = ? OR LOWER(TRIM(pending_email)) = ?)
                 LIMIT 1"
            );
            $email_check->bind_param("iss", $target_user_id, $email, $email);
            $email_check->execute();
            if ($email_check->get_result()->num_rows > 0) {
                throw new RuntimeException('DUPLICATE_EMAIL');
            }

            $role_changed = $target_user['role'] !== $role;
            $email_changed = drms_normalize_account_email($target_user['email'] ?? '') !== $email;
            $stmt = $conn->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, pending_email = NULL,
                     email_verification_code = NULL, email_code_expire = NULL,
                     email_verification_attempts = 0, email_verification_last_sent_at = NULL,
                     session_token = CASE WHEN role <> ? THEN NULL ELSE session_token END,
                     role = ?
                 WHERE user_id = ?'
            );
            $stmt->bind_param("ssssi", $fullname, $email, $role, $role, $target_user_id);
            $stmt->execute();

            if ($role_changed || $email_changed) {
                $clear_setup_link = $conn->prepare(
                    'UPDATE users
                     SET session_token = NULL,
                         setup_token = NULL, setup_token_purpose = NULL,
                         setup_token_sent_at = NULL, setup_token_expire = NULL
                     WHERE user_id = ?'
                );
                $clear_setup_link->bind_param('i', $target_user_id);
                $clear_setup_link->execute();
            }

            if ($role_changed || $email_changed) {
                drms_expire_user_access_tokens($conn, $target_user_id);
            }

            if ($role_changed) {
                $clear_permissions = $conn->prepare('DELETE FROM user_permissions WHERE user_id = ?');
                $clear_permissions->bind_param('i', $target_user_id);
                $clear_permissions->execute();

                $role_permissions = $conn->prepare(
                    "SELECT up.permission_name
                     FROM user_permissions up
                     WHERE up.user_id = (
                        SELECT MIN(u.user_id) FROM users u
                        WHERE u.role = ? AND u.status = 'Active' AND u.user_id <> ?
                     )"
                );
                $role_permissions->bind_param('si', $role, $target_user_id);
                $role_permissions->execute();
                $role_permission_rows = $role_permissions->get_result();
                $insert_permission = $conn->prepare(
                    'INSERT IGNORE INTO user_permissions (user_id, permission_name) VALUES (?, ?)'
                );
                while ($role_permission = $role_permission_rows->fetch_assoc()) {
                    $permission_name = (string) $role_permission['permission_name'];
                    $insert_permission->bind_param('is', $target_user_id, $permission_name);
                    $insert_permission->execute();
                }
            }

            $conn->commit();
            log_audit_action(
                $conn,
                (int) $_SESSION['user_id'],
                'UPDATE_USER',
                'Updated account details for @' . $target_user['username'] .
                ($role_changed ? '; role changed and active sessions were revoked.' : '.')
            );
            drms_admin_users_redirect('success', 'UserUpdated');
        } catch (Throwable $update_error) {
            $conn->rollback();
            if ($update_error->getMessage() === 'USER_NOT_FOUND') {
                drms_admin_users_redirect('error', 'UserNotFound');
            }
            if ($update_error->getMessage() === 'CANNOT_CHANGE_ADMIN_ROLE') {
                drms_admin_users_redirect('error', 'CannotChangeAdminRole');
            }
            if ($update_error->getMessage() === 'DUPLICATE_EMAIL' || (int) $update_error->getCode() === 1062) {
                drms_admin_users_redirect('error', 'DuplicateEmail');
            }
            error_log('User update failed: ' . $update_error->getMessage());
            drms_admin_users_redirect('error', 'UpdateFailed');
        }
    }

    // ===============================================
    // SEND SECURE PASSWORD RESET CODE (ADMIN-INITIATED)
    // ===============================================
    if ($action === 'send_password_reset_code') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id < 1) {
            drms_admin_users_redirect('error', 'UserNotFound');
        }
        if ($target_user_id === (int) $_SESSION['user_id']) {
            drms_admin_users_redirect('error', 'CannotResetOwnPassword');
        }

        $mail_attempted = false;
        $conn->begin_transaction();
        try {
            $target_user = drms_get_user_for_update($conn, $target_user_id);
            if (!$target_user) {
                throw new RuntimeException('USER_NOT_FOUND');
            }
            if ($target_user['status'] !== 'Active') {
                throw new RuntimeException('ACCOUNT_SUSPENDED');
            }
            $target_email = drms_normalize_account_email($target_user['email'] ?? '');
            if (!drms_valid_account_email($target_email)) {
                throw new RuntimeException('MISSING_RECOVERY_EMAIL');
            }

            $recent_stmt = $conn->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
                 FROM password_reset_otps
                 WHERE user_id = ?
                 ORDER BY reset_id DESC
                 LIMIT 1"
            );
            $recent_stmt->bind_param('i', $target_user_id);
            $recent_stmt->execute();
            $recent_reset = $recent_stmt->get_result()->fetch_assoc();
            if ($recent_reset && max(0, (int) $recent_reset['elapsed_seconds']) < 60) {
                throw new RuntimeException('RESET_CODE_COOLDOWN');
            }

            $otp_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
            $request_ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'), 0, 45);

            drms_expire_user_access_tokens($conn, $target_user_id);

            $revoke_stmt = $conn->prepare(
                "UPDATE users
                 SET session_token = NULL,
                     reset_token = NULL, reset_token_expire = NULL,
                     setup_token = CASE WHEN setup_token_purpose = 'Password Reset' THEN NULL ELSE setup_token END,
                     setup_token_sent_at = CASE WHEN setup_token_purpose = 'Password Reset' THEN NULL ELSE setup_token_sent_at END,
                     setup_token_expire = CASE WHEN setup_token_purpose = 'Password Reset' THEN NULL ELSE setup_token_expire END,
                     setup_token_purpose = CASE WHEN setup_token_purpose = 'Password Reset' THEN NULL ELSE setup_token_purpose END
                 WHERE user_id = ?"
            );
            $revoke_stmt->bind_param('i', $target_user_id);
            $revoke_stmt->execute();

            $insert_stmt = $conn->prepare(
                "INSERT INTO password_reset_otps
                    (user_id, email, otp_hash, expires_at, max_attempts, requested_ip)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 5, ?)"
            );
            $insert_stmt->bind_param('isss', $target_user_id, $target_email, $otp_hash, $request_ip);
            $insert_stmt->execute();

            $mail_attempted = true;
            $mail = new PHPMailer(true);
            drms_configure_mailer($mail, ['from_name' => 'Fixie DRMS Security']);
            $mail->addAddress($target_email, (string) $target_user['full_name']);
            $mail->Subject = 'Fixie DRMS - Password Reset Code';
            $mail->isHTML(false);
            $mail->Body = "Hello " . trim((string) $target_user['full_name']) . ",\n\n"
                . "An Administrator initiated password recovery for your Fixie DRMS account.\n\n"
                . "Your six-digit password reset code is:\n\n"
                . $otp_code . "\n\n"
                . "This code expires in 10 minutes and can only be used once. You have a maximum of 5 verification attempts.\n"
                . "To continue, open the Fixie DRMS login page, select Forgot Password, enter your registered email, then choose I Already Have a Code.\n\n"
                . "Your active sessions and older recovery codes were revoked. Your password changes only after this code is verified.\n"
                . "If you did not expect this request, contact your Administrator immediately.\n\n"
                . "Fixie Computer Ventures";
            $mail->send();

            $conn->commit();
            try {
                log_audit_action(
                    $conn,
                    (int) $_SESSION['user_id'],
                    'ADMIN_PASSWORD_RESET_OTP',
                    'Sent a one-time password-reset code to @' . $target_user['username'] . '; active sessions and previous recovery codes were revoked.'
                );
            } catch (Throwable $audit_error) {
                error_log('Password-reset code audit failed: ' . $audit_error->getMessage());
            }
            drms_admin_users_redirect('success', 'PasswordResetCodeSent');
        } catch (Throwable $reset_error) {
            $conn->rollback();
            if ($reset_error->getMessage() === 'USER_NOT_FOUND') {
                drms_admin_users_redirect('error', 'UserNotFound');
            }
            if ($reset_error->getMessage() === 'ACCOUNT_SUSPENDED') {
                drms_admin_users_redirect('error', 'AccountSuspended');
            }
            if ($reset_error->getMessage() === 'MISSING_RECOVERY_EMAIL') {
                drms_admin_users_redirect('error', 'MissingRecoveryEmail');
            }
            if ($reset_error->getMessage() === 'RESET_CODE_COOLDOWN') {
                drms_admin_users_redirect('error', 'PasswordResetCooldown');
            }
            if ($reset_error instanceof mysqli_sql_exception && (int) $reset_error->getCode() === 1146) {
                drms_admin_users_redirect('error', 'PasswordRecoveryNotInitialized');
            }
            error_log('Admin password-reset code failed: ' . $reset_error->getMessage());
            if ($mail_attempted) {
                drms_admin_users_redirect('error', 'PasswordResetEmailFailed');
            }
            drms_admin_users_redirect('error', 'UpdateFailed');
        }
    }

    if (in_array($action, ['send_password_reset_link', 'force_password_reset'], true)) {
        drms_admin_users_redirect('error', 'LegacyPasswordResetDisabled');
    }

    // ===============================================
    // LEGACY HARD DELETE IS DISABLED
    // ===============================================
    if ($action == 'delete_user') {
        drms_admin_users_redirect('error', 'UserDeletionDisabled');
    }

    // ===============================================
    // UPDATE STATUS
    // ===============================================
    if ($action == 'update_status') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_status = (string) ($_POST['status'] ?? '');

        if ($target_user_id < 1) {
            drms_admin_users_redirect('error', 'UserNotFound');
        }
        if (!in_array($new_status, $allowed_statuses, true)) {
            drms_admin_users_redirect('error', 'InvalidStatus');
        }
        if ($target_user_id === (int) $_SESSION['user_id'] && $new_status === 'Suspended') {
            drms_admin_users_redirect('error', 'CannotSuspendSelf');
        }

        $conn->begin_transaction();
        try {
            $target_user = drms_get_user_for_update($conn, $target_user_id);
            if (!$target_user) {
                throw new RuntimeException('USER_NOT_FOUND');
            }

            if ($target_user['role'] === 'Admin' && $target_user['status'] === 'Active' && $new_status === 'Suspended') {
                $admin_count = (int) ($conn->query(
                    "SELECT COUNT(*) AS total FROM users WHERE role = 'Admin' AND status = 'Active'"
                )->fetch_assoc()['total'] ?? 0);
                if ($admin_count <= 1) {
                    throw new RuntimeException('LAST_ACTIVE_ADMIN');
                }
            }

            $stmt = $conn->prepare(
                "UPDATE users
                 SET status = ?, session_token = CASE WHEN ? = 'Suspended' THEN NULL ELSE session_token END,
                     setup_token = CASE WHEN ? = 'Suspended' THEN NULL ELSE setup_token END,
                     setup_token_purpose = CASE WHEN ? = 'Suspended' THEN NULL ELSE setup_token_purpose END,
                     setup_token_sent_at = CASE WHEN ? = 'Suspended' THEN NULL ELSE setup_token_sent_at END,
                     setup_token_expire = CASE WHEN ? = 'Suspended' THEN NULL ELSE setup_token_expire END,
                     reset_token = CASE WHEN ? = 'Suspended' THEN NULL ELSE reset_token END,
                     reset_token_expire = CASE WHEN ? = 'Suspended' THEN NULL ELSE reset_token_expire END
                 WHERE user_id = ?"
            );
            $stmt->bind_param('ssssssssi', $new_status, $new_status, $new_status, $new_status, $new_status, $new_status, $new_status, $new_status, $target_user_id);
            $stmt->execute();

            if ($new_status === 'Suspended') {
                drms_expire_user_access_tokens($conn, $target_user_id);
            }

            $conn->commit();
            log_audit_action(
                $conn,
                (int) $_SESSION['user_id'],
                'UPDATE_STATUS',
                'Changed @' . $target_user['username'] . ' account status from ' . $target_user['status'] . ' to ' . $new_status .
                ($new_status === 'Suspended' ? '; active sessions and access tokens were revoked.' : '.')
            );
            drms_admin_users_redirect('success', 'UserStatusUpdated');
        } catch (Throwable $status_error) {
            $conn->rollback();
            if ($status_error->getMessage() === 'USER_NOT_FOUND') {
                drms_admin_users_redirect('error', 'UserNotFound');
            }
            if ($status_error->getMessage() === 'LAST_ACTIVE_ADMIN') {
                drms_admin_users_redirect('error', 'LastActiveAdmin');
            }
            error_log('Account status update failed: ' . $status_error->getMessage());
            drms_admin_users_redirect('error', 'UpdateFailed');
        }
    }

    // ===============================================
    // FORCE LOGOUT (Invalidate Session Token)
    // ===============================================
    if ($action == 'force_logout') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id < 1) {
            drms_admin_users_redirect('error', 'UserNotFound');
        }
        if ($target_user_id === (int) $_SESSION['user_id']) {
            drms_admin_users_redirect('error', 'CannotForceLogoutSelf');
        }

        $target_stmt = $conn->prepare('SELECT username FROM users WHERE user_id = ? LIMIT 1');
        $target_stmt->bind_param('i', $target_user_id);
        $target_stmt->execute();
        $target_user = $target_stmt->get_result()->fetch_assoc();
        if (!$target_user) {
            drms_admin_users_redirect('error', 'UserNotFound');
        }

        $stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        log_audit_action(
            $conn,
            (int) $_SESSION['user_id'],
            'FORCE_LOGOUT',
            'Admin force logged out @' . $target_user['username'] . '.'
        );
        drms_admin_users_redirect('success', 'UserForceLoggedOut');
    }

    drms_admin_users_redirect('error', 'InvalidAction');
}
