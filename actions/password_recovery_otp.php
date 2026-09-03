<?php
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/session_bootstrap.php';

require_once '../config/db_connect.php';
require_once '../config/mailer.php';
require_once '../libs/src/Exception.php';
require_once '../libs/src/PHPMailer.php';
require_once '../libs/src/SMTP.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const RECOVERY_OTP_EXPIRY_MINUTES = 10;
const RECOVERY_OTP_MAX_ATTEMPTS = 5;
const RECOVERY_RESEND_SECONDS = 60;
const RECOVERY_RESET_WINDOW_SECONDS = 600;

function recovery_response($status, $message, $httpCode = 200, $extra = [])
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit();
}

function recovery_clear_session()
{
    unset(
        $_SESSION['password_recovery_email'],
        $_SESSION['password_recovery_user_id'],
        $_SESSION['password_recovery_reset_id'],
        $_SESSION['password_recovery_verified_until'],
        $_SESSION['password_recovery_last_request'],
        $_SESSION['password_recovery_last_email']
    );
}

function recovery_valid_csrf()
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $requestToken = $_POST['csrf_token'] ?? '';
    return $sessionToken !== '' && $requestToken !== '' && hash_equals($sessionToken, $requestToken);
}

function recovery_clean_name($name)
{
    $clean = preg_replace('/[\r\n]+/', ' ', (string) $name);
    return trim($clean) !== '' ? trim($clean) : 'User';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recovery_response('error', 'Method not allowed.', 405);
}

if (!recovery_valid_csrf()) {
    recovery_response('error', 'Your recovery session expired. Refresh the page and try again.', 419);
}

$action = trim($_POST['action'] ?? '');
$ipAddress = substr($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN', 0, 45);

try {
    $conn->query("UPDATE password_reset_otps SET status = 'Expired' WHERE status = 'Pending' AND expires_at <= NOW()");
    $conn->query("DELETE FROM password_reset_otps WHERE created_at < NOW() - INTERVAL 2 DAY");

    if ($action === 'use_existing_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            recovery_response('error', 'Enter a valid registered email address.', 422);
        }

        $_SESSION['password_recovery_email'] = $email;
        $_SESSION['password_recovery_last_email'] = $email;
        unset(
            $_SESSION['password_recovery_user_id'],
            $_SESSION['password_recovery_reset_id'],
            $_SESSION['password_recovery_verified_until']
        );

        recovery_response(
            'success',
            'Enter the six-digit code from your recovery email.',
            200
        );
    }

    if ($action === 'send_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            recovery_response('error', 'Enter a valid email address.', 422);
        }

        $now = time();
        $lastRequest = (int) ($_SESSION['password_recovery_last_request'] ?? 0);
        $lastEmail = $_SESSION['password_recovery_last_email'] ?? '';
        if ($lastEmail === $email && $lastRequest > 0 && ($now - $lastRequest) < RECOVERY_RESEND_SECONDS) {
            $wait = RECOVERY_RESEND_SECONDS - ($now - $lastRequest);
            recovery_response(
                'success',
                'If this email belongs to an active account, a verification code has been sent.',
                200,
                ['retry_after' => $wait]
            );
        }

        $_SESSION['password_recovery_last_request'] = $now;
        $_SESSION['password_recovery_last_email'] = $email;
        $_SESSION['password_recovery_email'] = $email;
        unset(
            $_SESSION['password_recovery_user_id'],
            $_SESSION['password_recovery_reset_id'],
            $_SESSION['password_recovery_verified_until']
        );

        $userStmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE LOWER(email) = ? AND status = 'Active' LIMIT 2");
        $userStmt->bind_param('s', $email);
        $userStmt->execute();
        $matchingUsers = $userStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $user = count($matchingUsers) === 1 ? $matchingUsers[0] : null;

        if (!$user) {
            password_hash(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT), PASSWORD_DEFAULT);
            recovery_response(
                'success',
                'If this email belongs to an active account, a verification code has been sent.',
                200,
                ['retry_after' => RECOVERY_RESEND_SECONDS]
            );
        }

        $userId = (int) $user['user_id'];
        $recentStmt = $conn->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
             FROM password_reset_otps
             WHERE user_id = ?
             ORDER BY reset_id DESC LIMIT 1"
        );
        $recentStmt->bind_param('i', $userId);
        $recentStmt->execute();
        $recent = $recentStmt->get_result()->fetch_assoc();

        if ($recent) {
            $secondsSinceLast = max(0, (int) $recent['elapsed_seconds']);
            if ($secondsSinceLast < RECOVERY_RESEND_SECONDS) {
                $wait = max(1, RECOVERY_RESEND_SECONDS - $secondsSinceLast);
                recovery_response(
                    'success',
                    'If this email belongs to an active account, a verification code has been sent.',
                    200,
                    ['retry_after' => $wait]
                );
            }
        }

        $expireStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Expired' WHERE user_id = ? AND status IN ('Pending', 'Verified')");
        $expireStmt->bind_param('i', $userId);
        $expireStmt->execute();

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare(
            "INSERT INTO password_reset_otps
                (user_id, email, otp_hash, expires_at, max_attempts, requested_ip)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?, ?)"
        );
        $maxAttempts = RECOVERY_OTP_MAX_ATTEMPTS;
        $insertStmt->bind_param('issis', $userId, $email, $otpHash, $maxAttempts, $ipAddress);
        $insertStmt->execute();
        $resetId = (int) $conn->insert_id;

        $mail = new PHPMailer(true);
        try {
            drms_configure_mailer($mail, [
                'from' => getenv('DRMS_MAIL_FROM') ?: 'no-reply@fixieventures.com',
                'from_name' => 'Fixie DRMS Security'
            ]);
            $mail->addAddress($email);
            $mail->isHTML(false);
            $mail->Subject = 'Password Recovery Code - Fixie DRMS';
            $mail->Body = "Hello " . recovery_clean_name($user['full_name']) . ",\n\n"
                . "Your Fixie DRMS password recovery code is:\n\n"
                . $otpCode . "\n\n"
                . "This code expires in " . RECOVERY_OTP_EXPIRY_MINUTES . " minutes and can only be used once.\n"
                . "If you did not request a password reset, ignore this email and inform your administrator.\n\n"
                . "Fixie Computer Ventures";
            $mail->send();
        } catch (Throwable $mailError) {
            $expireFailedStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Expired' WHERE reset_id = ?");
            $expireFailedStmt->bind_param('i', $resetId);
            $expireFailedStmt->execute();
            $_SESSION['password_recovery_last_request'] = 0;
            error_log('Password recovery email failed: ' . $mailError->getMessage());
            recovery_response('error', 'The verification email could not be sent. Check the mail configuration and try again.', 503);
        }

        recovery_response(
            'success',
            'If this email belongs to an active account, a verification code has been sent.',
            200,
            ['retry_after' => RECOVERY_RESEND_SECONDS]
        );
    }

    if ($action === 'verify_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $sessionEmail = $_SESSION['password_recovery_email'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !hash_equals($sessionEmail, $email)) {
            recovery_response('error', 'Start a new password recovery request.', 400);
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            recovery_response('error', 'Enter the complete six-digit verification code.', 422);
        }

        $tokenStmt = $conn->prepare(
            "SELECT reset_id, user_id, otp_hash, attempt_count, max_attempts
             FROM password_reset_otps
             WHERE email = ? AND status = 'Pending' AND expires_at > NOW()
             ORDER BY reset_id DESC LIMIT 1"
        );
        $tokenStmt->bind_param('s', $email);
        $tokenStmt->execute();
        $token = $tokenStmt->get_result()->fetch_assoc();

        if (!$token) {
            recovery_response('error', 'The code is invalid or expired. Request a new code.', 422);
        }

        $resetId = (int) $token['reset_id'];
        $attemptCount = (int) $token['attempt_count'];
        $maxAttempts = (int) $token['max_attempts'];

        if ($attemptCount >= $maxAttempts) {
            $expireStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Expired' WHERE reset_id = ?");
            $expireStmt->bind_param('i', $resetId);
            $expireStmt->execute();
            recovery_response('error', 'Too many failed attempts. Request a new code.', 429);
        }

        if (!password_verify($code, $token['otp_hash'])) {
            $newAttemptCount = $attemptCount + 1;
            $newStatus = $newAttemptCount >= $maxAttempts ? 'Expired' : 'Pending';
            $attemptStmt = $conn->prepare("UPDATE password_reset_otps SET attempt_count = ?, status = ? WHERE reset_id = ?");
            $attemptStmt->bind_param('isi', $newAttemptCount, $newStatus, $resetId);
            $attemptStmt->execute();
            $remaining = max(0, $maxAttempts - $newAttemptCount);
            $message = $remaining > 0
                ? "Invalid verification code. {$remaining} attempt(s) remaining."
                : 'Too many failed attempts. Request a new code.';
            recovery_response('error', $message, $remaining > 0 ? 422 : 429);
        }

        $verifyStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Verified', verified_at = NOW() WHERE reset_id = ? AND status = 'Pending'");
        $verifyStmt->bind_param('i', $resetId);
        $verifyStmt->execute();
        if ($verifyStmt->affected_rows !== 1) {
            recovery_response('error', 'The code could not be verified. Request a new code.', 409);
        }

        session_regenerate_id(true);
        $_SESSION['password_recovery_user_id'] = (int) $token['user_id'];
        $_SESSION['password_recovery_reset_id'] = $resetId;
        $_SESSION['password_recovery_verified_until'] = time() + RECOVERY_RESET_WINDOW_SECONDS;

        recovery_response('success', 'Email verified. Create your new password.', 200);
    }

    if ($action === 'reset_password') {
        $userId = (int) ($_SESSION['password_recovery_user_id'] ?? 0);
        $resetId = (int) ($_SESSION['password_recovery_reset_id'] ?? 0);
        $verifiedUntil = (int) ($_SESSION['password_recovery_verified_until'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($userId <= 0 || $resetId <= 0 || $verifiedUntil < time()) {
            recovery_clear_session();
            recovery_response('error', 'Your verified recovery session expired. Request a new code.', 401);
        }
        if ($newPassword !== $confirmPassword) {
            recovery_response('error', 'The password confirmation does not match.', 422);
        }
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,128}$/', $newPassword)) {
            recovery_response('error', 'Use at least 8 characters with uppercase, lowercase, and a number.', 422);
        }

        $conn->begin_transaction();
        try {
            $resetStmt = $conn->prepare(
                "SELECT user_id, email FROM password_reset_otps
                 WHERE reset_id = ? AND user_id = ? AND status = 'Verified'
                   AND verified_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                 FOR UPDATE"
            );
            $resetStmt->bind_param('ii', $resetId, $userId);
            $resetStmt->execute();
            $verifiedReset = $resetStmt->get_result()->fetch_assoc();
            if (!$verifiedReset) {
                throw new RuntimeException('RECOVERY_AUTH_EXPIRED');
            }

            $userStmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ? AND status = 'Active' FOR UPDATE");
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            if (!$user) {
                throw new RuntimeException('ACCOUNT_UNAVAILABLE');
            }
            if (password_verify($newPassword, $user['password_hash'])) {
                throw new RuntimeException('PASSWORD_REUSED');
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateUserStmt = $conn->prepare(
                "UPDATE users
                 SET password_hash = ?, session_token = NULL, require_pass_change = 0,
                     reset_token = NULL, reset_token_expire = NULL,
                     setup_token = NULL, setup_token_purpose = NULL,
                     setup_token_sent_at = NULL, setup_token_expire = NULL
                 WHERE user_id = ?"
            );
            $updateUserStmt->bind_param('si', $passwordHash, $userId);
            $updateUserStmt->execute();

            $usedStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Used', used_at = NOW() WHERE reset_id = ?");
            $usedStmt->bind_param('i', $resetId);
            $usedStmt->execute();

            $expireOthersStmt = $conn->prepare("UPDATE password_reset_otps SET status = 'Expired' WHERE user_id = ? AND reset_id <> ? AND status IN ('Pending', 'Verified')");
            $expireOthersStmt->bind_param('ii', $userId, $resetId);
            $expireOthersStmt->execute();

            $expireLoginOtpStmt = $conn->prepare(
                "UPDATE otp_auth_tokens SET status = 'Expired'
                 WHERE user_id = ? AND status = 'Pending'"
            );
            $expireLoginOtpStmt->bind_param('i', $userId);
            $expireLoginOtpStmt->execute();

            $description = 'User reset the account password through verified email OTP; active sessions were revoked.';
            $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'PASSWORD_RESET', ?, ?)");
            $auditStmt->bind_param('iss', $userId, $description, $ipAddress);
            $auditStmt->execute();

            $conn->commit();
        } catch (Throwable $transactionError) {
            $conn->rollback();
            if ($transactionError->getMessage() === 'PASSWORD_REUSED') {
                recovery_response('error', 'Choose a password different from your current password.', 422);
            }
            if (in_array($transactionError->getMessage(), ['RECOVERY_AUTH_EXPIRED', 'ACCOUNT_UNAVAILABLE'], true)) {
                recovery_clear_session();
                recovery_response('error', 'Your recovery authorization is no longer valid. Request a new code.', 401);
            }
            throw $transactionError;
        }

        recovery_clear_session();
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        recovery_response(
            'success',
            'Password updated successfully. Sign in using your new password.',
            200,
            ['redirect' => 'index.php?success=' . rawurlencode('Password updated successfully. Sign in using your new password.')]
        );
    }

    recovery_response('error', 'Unknown recovery action.', 400);
} catch (mysqli_sql_exception $databaseError) {
    error_log('Password recovery database error: ' . $databaseError->getMessage());
    if (stripos($databaseError->getMessage(), 'password_reset_otps') !== false) {
        recovery_response('error', 'Password recovery is not initialized. Install the included SQL migration first.', 503);
    }
    recovery_response('error', 'The recovery service is temporarily unavailable. Try again later.', 500);
} catch (Throwable $error) {
    error_log('Password recovery error: ' . $error->getMessage());
    recovery_response('error', 'The recovery service is temporarily unavailable. Try again later.', 500);
}
