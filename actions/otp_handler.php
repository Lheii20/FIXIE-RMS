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

const LOGIN_OTP_EXPIRY_MINUTES = 5;
const LOGIN_OTP_MAX_ATTEMPTS = 5;
const LOGIN_OTP_RESEND_SECONDS = 60;

function login_otp_response($status, $message, $httpCode = 200, $extra = [])
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit();
}

function login_otp_valid_csrf()
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $requestToken = $_POST['csrf_token'] ?? '';
    return $sessionToken !== '' && $requestToken !== '' && hash_equals($sessionToken, $requestToken);
}

function login_otp_clean_name($name)
{
    $clean = preg_replace('/[\r\n]+/', ' ', (string) $name);
    return trim($clean) !== '' ? trim($clean) : 'User';
}

function login_otp_clear_request_session()
{
    unset(
        $_SESSION['login_otp_email'],
        $_SESSION['login_otp_last_email'],
        $_SESSION['login_otp_last_request']
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    login_otp_response('error', 'Method not allowed.', 405);
}

if (!login_otp_valid_csrf()) {
    login_otp_response('error', 'Your login session expired. Refresh the page and try again.', 419);
}

$action = trim($_POST['action'] ?? '');
$ipAddress = substr($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN', 0, 45);

try {
    $conn->query("UPDATE otp_auth_tokens SET status = 'Expired' WHERE status = 'Pending' AND expires_at <= NOW()");
    $conn->query("DELETE FROM otp_auth_tokens WHERE created_at < NOW() - INTERVAL 7 DAY");

    if ($action === 'send_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            login_otp_response('error', 'Enter a valid email address.', 422);
        }

        $now = time();
        $lastEmail = $_SESSION['login_otp_last_email'] ?? '';
        $lastRequest = (int) ($_SESSION['login_otp_last_request'] ?? 0);

        if ($lastEmail === $email && $lastRequest > 0 && ($now - $lastRequest) < LOGIN_OTP_RESEND_SECONDS) {
            $wait = LOGIN_OTP_RESEND_SECONDS - ($now - $lastRequest);
            login_otp_response(
                'success',
                'If this email belongs to an active account, a verification code has been sent.',
                200,
                ['retry_after' => $wait]
            );
        }

        $_SESSION['login_otp_email'] = $email;
        $_SESSION['login_otp_last_email'] = $email;
        $_SESSION['login_otp_last_request'] = $now;

        $userStmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE LOWER(email) = ? AND status = 'Active' LIMIT 2");
        $userStmt->bind_param('s', $email);
        $userStmt->execute();
        $matchingUsers = $userStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $user = count($matchingUsers) === 1 ? $matchingUsers[0] : null;

        if (!$user) {
            password_hash(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT), PASSWORD_DEFAULT);
            login_otp_response(
                'success',
                'If this email belongs to an active account, a verification code has been sent.',
                200,
                ['retry_after' => LOGIN_OTP_RESEND_SECONDS]
            );
        }

        $userId = (int) $user['user_id'];
        $recentStmt = $conn->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
             FROM otp_auth_tokens
             WHERE user_id = ?
             ORDER BY otp_id DESC
             LIMIT 1"
        );
        $recentStmt->bind_param('i', $userId);
        $recentStmt->execute();
        $recent = $recentStmt->get_result()->fetch_assoc();

        if ($recent) {
            $secondsSinceLast = max(0, (int) $recent['elapsed_seconds']);
            if ($secondsSinceLast < LOGIN_OTP_RESEND_SECONDS) {
                $wait = max(1, LOGIN_OTP_RESEND_SECONDS - $secondsSinceLast);
                login_otp_response(
                    'success',
                    'If this email belongs to an active account, a verification code has been sent.',
                    200,
                    ['retry_after' => $wait]
                );
            }
        }

        $expireStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE user_id = ? AND status = 'Pending'");
        $expireStmt->bind_param('i', $userId);
        $expireStmt->execute();

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare(
            "INSERT INTO otp_auth_tokens (user_id, email, otp_code, expires_at, attempt_count, status)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 'Pending')"
        );
        $insertStmt->bind_param('iss', $userId, $email, $otpHash);
        $insertStmt->execute();
        $otpId = (int) $conn->insert_id;

        $mail = new PHPMailer(true);
        try {
            drms_configure_mailer($mail, [
                'from' => getenv('DRMS_MAIL_FROM') ?: 'no-reply@fixieventures.com',
                'from_name' => 'Fixie DRMS Security'
            ]);
            $mail->addAddress($email);
            $mail->isHTML(false);
            $mail->Subject = 'Login Verification Code - Fixie DRMS';
            $mail->Body = "Hello " . login_otp_clean_name($user['full_name']) . ",\n\n"
                . "Your Fixie DRMS login verification code is:\n\n"
                . $otpCode . "\n\n"
                . "This code expires in " . LOGIN_OTP_EXPIRY_MINUTES . " minutes and can only be used once.\n"
                . "If you did not request this login, ignore this email and inform your administrator.\n\n"
                . "Fixie Computer Ventures";
            $mail->send();
        } catch (Throwable $mailError) {
            $expireFailedStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE otp_id = ?");
            $expireFailedStmt->bind_param('i', $otpId);
            $expireFailedStmt->execute();
            $_SESSION['login_otp_last_request'] = 0;
            error_log('Login OTP email failed: ' . $mailError->getMessage());
            login_otp_response('error', 'The verification email could not be sent. Check the mail configuration and try again.', 503);
        }

        login_otp_response(
            'success',
            'If this email belongs to an active account, a verification code has been sent.',
            200,
            ['retry_after' => LOGIN_OTP_RESEND_SECONDS]
        );
    }

    if ($action === 'verify_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $sessionEmail = $_SESSION['login_otp_email'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $sessionEmail === '' || !hash_equals($sessionEmail, $email)) {
            login_otp_response('error', 'Start a new email OTP login request.', 400);
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            login_otp_response('error', 'Enter the complete six-digit verification code.', 422);
        }

        $tokenStmt = $conn->prepare(
            "SELECT otp_id, user_id, otp_code, attempt_count
             FROM otp_auth_tokens
             WHERE email = ? AND status = 'Pending' AND expires_at > NOW()
             ORDER BY otp_id DESC LIMIT 1"
        );
        $tokenStmt->bind_param('s', $email);
        $tokenStmt->execute();
        $token = $tokenStmt->get_result()->fetch_assoc();

        if (!$token) {
            login_otp_response('error', 'The code is invalid or expired. Request a new code.', 422);
        }

        $otpId = (int) $token['otp_id'];
        $attemptCount = (int) $token['attempt_count'];

        if ($attemptCount >= LOGIN_OTP_MAX_ATTEMPTS) {
            $expireStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE otp_id = ?");
            $expireStmt->bind_param('i', $otpId);
            $expireStmt->execute();
            login_otp_response('error', 'Too many failed attempts. Request a new code.', 429);
        }

        $hashInfo = password_get_info($token['otp_code']);
        if (($hashInfo['algo'] ?? 0) === 0) {
            $expireStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE otp_id = ?");
            $expireStmt->bind_param('i', $otpId);
            $expireStmt->execute();
            login_otp_response('error', 'This code was created by the previous login version. Request a new code.', 422);
        }

        if (!password_verify($code, $token['otp_code'])) {
            $newAttemptCount = $attemptCount + 1;
            $newStatus = $newAttemptCount >= LOGIN_OTP_MAX_ATTEMPTS ? 'Expired' : 'Pending';
            $attemptStmt = $conn->prepare("UPDATE otp_auth_tokens SET attempt_count = ?, status = ? WHERE otp_id = ?");
            $attemptStmt->bind_param('isi', $newAttemptCount, $newStatus, $otpId);
            $attemptStmt->execute();
            $remaining = max(0, LOGIN_OTP_MAX_ATTEMPTS - $newAttemptCount);
            $message = $remaining > 0
                ? "Invalid verification code. {$remaining} attempt(s) remaining."
                : 'Too many failed attempts. Request a new code.';
            login_otp_response('error', $message, $remaining > 0 ? 422 : 429);
        }

        $userId = (int) $token['user_id'];
        $userStmt = $conn->prepare(
            "SELECT user_id, username, full_name, role, avatar, require_pass_change
             FROM users
             WHERE user_id = ? AND status = 'Active'
             LIMIT 1"
        );
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();

        if (!$user) {
            $expireStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Expired' WHERE otp_id = ?");
            $expireStmt->bind_param('i', $otpId);
            $expireStmt->execute();
            login_otp_response('error', 'This account is no longer available for login.', 403);
        }

        $requiresPasswordChange = (int) ($user['require_pass_change'] ?? 0) === 1;
        $sessionToken = $requiresPasswordChange ? '' : bin2hex(random_bytes(32));

        $conn->begin_transaction();
        try {
            $verifiedStmt = $conn->prepare("UPDATE otp_auth_tokens SET status = 'Verified' WHERE otp_id = ? AND status = 'Pending'");
            $verifiedStmt->bind_param('i', $otpId);
            $verifiedStmt->execute();
            if ($verifiedStmt->affected_rows !== 1) {
                throw new RuntimeException('OTP_ALREADY_USED');
            }

            if (!$requiresPasswordChange) {
                $sessionStmt = $conn->prepare("UPDATE users SET session_token = ?, last_active = NOW() WHERE user_id = ? AND status = 'Active'");
                $sessionStmt->bind_param('si', $sessionToken, $userId);
                $sessionStmt->execute();
                if ($sessionStmt->affected_rows !== 1) {
                    throw new RuntimeException('ACCOUNT_UNAVAILABLE');
                }
            }

            $description = $requiresPasswordChange
                ? 'User verified email OTP and was required to create a new password before access.'
                : 'User logged in successfully through verified email OTP.';
            $auditAction = $requiresPasswordChange ? 'PASSWORD_SETUP_REQUIRED' : 'LOGIN';
            $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
            $auditStmt->bind_param('isss', $userId, $auditAction, $description, $ipAddress);
            $auditStmt->execute();

            $conn->commit();
        } catch (Throwable $transactionError) {
            $conn->rollback();
            if (in_array($transactionError->getMessage(), ['OTP_ALREADY_USED', 'ACCOUNT_UNAVAILABLE'], true)) {
                login_otp_response('error', 'The login code is no longer valid. Request a new code.', 409);
            }
            throw $transactionError;
        }

        session_regenerate_id(true);
        if ($requiresPasswordChange) {
            $_SESSION['temp_user_id'] = $user['user_id'];
            $_SESSION['temp_fullname'] = $user['full_name'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            login_otp_clear_request_session();
            login_otp_response('success', 'Email verified. Create your new password before signing in.', 200, ['redirect' => 'setup_password.php']);
        }
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['fullname'] = $user['full_name'];
        $_SESSION['avatar'] = $user['avatar'] ?? '';
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        login_otp_clear_request_session();

        login_otp_response('success', 'Login verified successfully.', 200, ['redirect' => 'dashboard.php']);
    }

    login_otp_response('error', 'Unknown OTP action.', 400);
} catch (mysqli_sql_exception $databaseError) {
    error_log('Login OTP database error: ' . $databaseError->getMessage());
    login_otp_response('error', 'The login service is temporarily unavailable. Try again later.', 500);
} catch (Throwable $error) {
    error_log('Login OTP error: ' . $error->getMessage());
    login_otp_response('error', 'The login service is temporarily unavailable. Try again later.', 500);
}
