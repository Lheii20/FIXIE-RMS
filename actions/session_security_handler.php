<?php
declare(strict_types=1);

require __DIR__ . '/../config/db_connect.php';
require __DIR__ . '/../config/functions.php';

function drms_security_redirect(string $kind, string $code): void
{
    header('Location: ../settings.php?' . $kind . '=' . rawurlencode($code) . '#security-center');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id'])) {
    http_response_code(405);
    exit('Action unavailable.');
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    drms_security_redirect('error', 'SecurityTokenMismatch');
}
if (($_POST['action'] ?? '') !== 'sign_out_other_devices') {
    drms_security_redirect('error', 'InvalidSecurityAction');
}

$userId = (int) $_SESSION['user_id'];
$keyHash = drms_registry_key_hash();
$password = (string) ($_POST['current_password'] ?? '');
if ((int) ($_SESSION['drms_security_retry_after'] ?? 0) > time()) {
    drms_security_redirect('error', 'SecurityActionCooldown');
}
if ($keyHash === '' || $password === '') {
    drms_security_redirect('error', 'SecurityPasswordRequired');
}

$stmt = $conn->prepare(
    "SELECT password_hash FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    $failures = (int) ($_SESSION['drms_security_failures'] ?? 0) + 1;
    $_SESSION['drms_security_failures'] = $failures;
    if ($failures >= 5) $_SESSION['drms_security_retry_after'] = time() + 300;
    log_audit_action($conn, $userId, 'SESSION_REVOKE_DENIED', 'Incorrect password for sign-out-other-devices request.');
    drms_security_redirect('error', 'WrongSecurityPassword');
}
unset($_SESSION['drms_security_failures'], $_SESSION['drms_security_retry_after']);

$oldToken = (string) ($_SESSION['session_token'] ?? '');
$newToken = bin2hex(random_bytes(32));
$newTokenHash = hash('sha256', $newToken);
$revokedCount = 0;
try {
    $conn->begin_transaction();
    // Rotating the account token also invalidates pre-migration PHP sessions
    // that have not yet enrolled in the device registry.
    $rotate = $conn->prepare(
        "UPDATE users SET session_token = ?
         WHERE user_id = ? AND session_token = ? AND status = 'Active'"
    );
    $rotate->bind_param('sis', $newToken, $userId, $oldToken);
    $rotate->execute();
    if ($rotate->affected_rows !== 1) throw new RuntimeException('TOKEN_CHANGED');
    $rotate->close();

    $revoke = $conn->prepare(
        "UPDATE user_sessions
         SET ended_at = NOW(), ended_reason = 'user_revoked'
         WHERE user_id = ? AND device_key_hash <> ? AND ended_at IS NULL"
    );
    $revoke->bind_param('is', $userId, $keyHash);
    $revoke->execute();
    $revokedCount = $revoke->affected_rows;
    $revoke->close();

    $keep = $conn->prepare(
        'UPDATE user_sessions SET auth_token_hash = ?, last_seen_at = NOW()
         WHERE user_id = ? AND device_key_hash = ? AND ended_at IS NULL'
    );
    $keep->bind_param('sis', $newTokenHash, $userId, $keyHash);
    $keep->execute();
    if ($keep->affected_rows !== 1) throw new RuntimeException('CURRENT_SESSION_MISSING');
    $keep->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Sign out other devices failed: ' . $error->getMessage());
    drms_security_redirect('error', 'AccountUpdateFailed');
}

session_regenerate_id(true);
$_SESSION['session_token'] = $newToken;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['last_activity'] = time();
$_SESSION['drms_presence_synced_at'] = time();

log_audit_action(
    $conn,
    $userId,
    'SESSION_REVOKE',
    'User signed out ' . $revokedCount . ' other device session(s).'
);
drms_security_redirect('success', 'OtherDevicesSignedOut');
