<?php
declare(strict_types=1);

/** Account-level cooldown. All timestamps and eligibility come from the database. */
function drms_profile_policy_installed(mysqli $conn): bool
{
    $result = $conn->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
        AND COLUMN_NAME IN ('profile_info_changed_at','pending_profile_full_name')");
    return (int) $result->fetch_assoc()['n'] === 2;
}

function drms_profile_account(mysqli $conn, int $userId, bool $lock = false): ?array
{
    $stmt = $conn->prepare("SELECT user_id, full_name, email, pending_email, pending_profile_full_name,
        email_verification_code, email_verification_attempts,
        (email_code_expire > NOW()) AS code_is_current,
        TIMESTAMPDIFF(SECOND,email_verification_last_sent_at,NOW()) AS seconds_since_last,
        profile_info_changed_at,
        DATE_ADD(profile_info_changed_at,INTERVAL 30 DAY) AS next_edit_at,
        (profile_info_changed_at IS NULL OR profile_info_changed_at <= DATE_SUB(NOW(),INTERVAL 30 DAY)) AS can_edit
        FROM users WHERE user_id=? AND status='Active'" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function drms_profile_edit_state(mysqli $conn, int $userId): array
{
    if (!drms_profile_policy_installed($conn)) {
        return ['installed'=>false, 'can_edit'=>false, 'next_edit_at'=>null];
    }
    $row = drms_profile_account($conn, $userId);
    return ['installed'=>true, 'can_edit'=>!empty($row['can_edit']), 'next_edit_at'=>$row['next_edit_at'] ?? null];
}

class DrmsProfileEditError extends RuntimeException {}

/**
 * Owns its transaction and row lock. Callbacks send mail, append an audit event,
 * and revoke old access codes using this same connection. Never changes roles.
 */
function drms_profile_process(mysqli $conn, int $userId, string $action, array $input,
    callable $sendCode, callable $audit, callable $revokeCodes): array
{
    if (!drms_profile_policy_installed($conn)) {
        return ['type'=>'error','code'=>'ProfilePolicyUnavailable'];
    }
    $conn->begin_transaction();
    try {
        $account = drms_profile_account($conn, $userId, true);
        if (!$account) throw new DrmsProfileEditError('AccountUpdateFailed');
        $pendingEmail = strtolower(trim((string) ($account['pending_email'] ?? '')));

        if ($action === 'cancel_email_change') {
            $stmt = $conn->prepare('UPDATE users SET pending_email=NULL,pending_profile_full_name=NULL,
                email_verification_code=NULL,email_code_expire=NULL,email_verification_attempts=0,
                email_verification_last_sent_at=NULL WHERE user_id=?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            if ($pendingEmail !== '') $audit('EMAIL_CHANGE_CANCELLED','User cancelled the pending profile/email change.');
            $conn->commit();
            return ['type'=>'success','code'=>'EmailChangeCancelled'];
        }

        // Locking the account makes simultaneous tabs obey the same cooldown.
        if (empty($account['can_edit'])) throw new DrmsProfileEditError('ProfileEditCooldown');

        if ($action === 'verify_email_code') {
            $code = $input['verification_code'] ?? '';
            if (!is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) throw new DrmsProfileEditError('InvalidCode');
            $attempts = (int) $account['email_verification_attempts'];
            if ($pendingEmail === '' || empty($account['code_is_current']) || $attempts >= 5
                || !password_verify($code, (string) $account['email_verification_code'])) {
                $attempts = min(5, $attempts + 1);
                $stmt = $conn->prepare('UPDATE users SET email_verification_attempts=?,
                    email_verification_code=IF(? >= 5 OR email_code_expire IS NULL OR email_code_expire <= NOW(),NULL,email_verification_code),
                    email_code_expire=IF(? >= 5,NULL,email_code_expire) WHERE user_id=?');
                $stmt->bind_param('iiii', $attempts, $attempts, $attempts, $userId);
                $stmt->execute();
                $conn->commit();
                return ['type'=>'error','code'=>$attempts >= 5 ? 'TooManyEmailCodeAttempts' : 'InvalidCode'];
            }
            $name = $account['pending_profile_full_name'] ?? $account['full_name'];
            drms_profile_check_email_available($conn, $userId, $pendingEmail);
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare('UPDATE users SET full_name=?,email=?,profile_info_changed_at=NOW(),
                pending_email=NULL,pending_profile_full_name=NULL,email_verification_code=NULL,
                email_code_expire=NULL,email_verification_attempts=0,email_verification_last_sent_at=NULL,
                session_token=?,last_active=NOW() WHERE user_id=?');
            $stmt->bind_param('sssi', $name, $pendingEmail, $token, $userId);
            $stmt->execute();
            $revokeCodes();
            if ($name !== $account['full_name']) $audit('PROFILE_UPDATED','User verified and updated the account full name; the 30-day profile edit limit started.');
            $audit('EMAIL_CHANGED','User verified and changed the recovery email; the 30-day profile edit limit started.');
            $conn->commit();
            return ['type'=>'success','code'=>'EmailVerified','full_name'=>$name,'session_token'=>$token];
        }

        if (!in_array($action, ['update_basic_info','resend_profile_email'], true)) throw new DrmsProfileEditError('AccountUpdateFailed');
        if ($action === 'resend_profile_email') {
            if ($pendingEmail === '') throw new DrmsProfileEditError('NoPendingEmailChange');
            $name = (string) ($account['pending_profile_full_name'] ?? $account['full_name']);
            $email = $pendingEmail;
        } else {
            if ($pendingEmail !== '') throw new DrmsProfileEditError('ProfileChangePending');
            if (!is_string($input['full_name'] ?? null) || !is_string($input['email'] ?? null)) throw new DrmsProfileEditError('InvalidFullName');
            $name = trim($input['full_name']);
            $email = strtolower(trim($input['email']));
            if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) < 2 || mb_strlen($name) > 100
                || preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new DrmsProfileEditError('InvalidFullName');
            $currentEmail = strtolower(trim((string) ($account['email'] ?? '')));
            if ($email === $currentEmail) {
                if ($name === $account['full_name']) {
                    $conn->commit();
                    return ['type'=>'success','code'=>'ProfileUnchanged'];
                }
                $stmt = $conn->prepare('UPDATE users SET full_name=?,profile_info_changed_at=NOW() WHERE user_id=?');
                $stmt->bind_param('si', $name, $userId);
                $stmt->execute();
                $audit('PROFILE_UPDATED','User updated the account full name; the 30-day profile edit limit started.');
                $conn->commit();
                return ['type'=>'success','code'=>'ProfileUpdated','full_name'=>$name];
            }
        }

        if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DrmsProfileEditError('InvalidEmail');
        if ($account['seconds_since_last'] !== null && (int) $account['seconds_since_last'] < 60) throw new DrmsProfileEditError('EmailCodeCooldown');
        drms_profile_check_email_available($conn, $userId, $email);
        $code = str_pad((string) random_int(0,999999),6,'0',STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET pending_email=?,pending_profile_full_name=?,email_verification_code=?,
            email_code_expire=DATE_ADD(NOW(),INTERVAL 10 MINUTE),email_verification_attempts=0,
            email_verification_last_sent_at=NOW() WHERE user_id=?');
        $stmt->bind_param('sssi', $email, $name, $hash, $userId);
        $stmt->execute();
        try {
            $sendCode($email, $name, $code);
        } catch (Throwable $error) {
            error_log('Profile verification mail failed: ' . $error->getMessage());
            throw new DrmsProfileEditError('VerificationEmailFailed');
        }
        $audit('EMAIL_CHANGE_REQUESTED','User requested a profile/email verification code. Existing profile information remains unchanged until verification.');
        $conn->commit();
        return ['type'=>'success','code'=>'CodeSent'];
    } catch (Throwable $error) {
        $conn->rollback();
        if ($error instanceof DrmsProfileEditError) return ['type'=>'error','code'=>$error->getMessage()];
        if ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062) return ['type'=>'error','code'=>'EmailAlreadyInUse'];
        error_log('Profile information update failed: ' . $error->getMessage());
        return ['type'=>'error','code'=>'AccountUpdateFailed'];
    }
}

function drms_profile_check_email_available(mysqli $conn, int $userId, string $email): void
{
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE user_id<>? AND (email=? OR pending_email=?) LIMIT 1');
    $stmt->bind_param('iss', $userId, $email, $email);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) throw new DrmsProfileEditError('EmailAlreadyInUse');
}
