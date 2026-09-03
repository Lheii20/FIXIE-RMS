<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

function redirect_to_settings(string $type, string $message): void
{
    header('Location: ../settings.php?' . $type . '=' . rawurlencode($message));
    exit();
}

function redirect_to_requests(string $type, string $message): void
{
    header('Location: ../admin_requests.php?' . $type . '=' . rawurlencode($message));
    exit();
}

function is_valid_username(string $username): bool
{
    return preg_match('/^(?=.{3,50}$)[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $username) === 1;
}

function create_personal_notification(
    mysqli $conn,
    int $userId,
    string $targetRole,
    string $message,
    string $targetUrl,
    string $notificationKey
): bool {
    ensure_collaboration_tables_exist($conn);

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notifications
            (target_role, recipient_user_id, message, target_url, notification_key)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sisss', $targetRole, $userId, $message, $targetUrl, $notificationKey);
    $stmt->execute();
    $notificationId = (int) $stmt->insert_id;
    $stmt->close();

    if ($notificationId < 1) {
        $find = $conn->prepare('SELECT notif_id FROM notifications WHERE notification_key = ? LIMIT 1');
        $find->bind_param('s', $notificationKey);
        $find->execute();
        $notificationId = (int) ($find->get_result()->fetch_assoc()['notif_id'] ?? 0);
        $find->close();
    }

    if ($notificationId < 1) {
        return false;
    }

    $state = $conn->prepare(
        "INSERT IGNORE INTO notification_user_states
            (notif_id, user_id, is_read, is_pinned, is_deleted)
         VALUES (?, ?, 0, 0, 0)"
    );
    $state->bind_param('ii', $notificationId, $userId);
    $saved = $state->execute();
    $state->close();

    return $saved;
}

function write_request_audit(mysqli $conn, int $actorId, string $actionType, string $description): void
{
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isss', $actorId, $actionType, $description, $ipAddress);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit();
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$postedToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(403);
    exit('Security validation failed. Refresh the page and try again.');
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'submit_request') {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionRole = (string) ($_SESSION['role'] ?? '');
    if ($userId < 1) {
        header('Location: ../index.php');
        exit();
    }
    if ($sessionRole === 'Admin') {
        redirect_to_settings('error', 'RequestNotAllowed');
    }

    $requestType = trim((string) ($_POST['request_type'] ?? ''));
    $newUsername = strtolower(trim((string) ($_POST['new_value'] ?? '')));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');

    if ($requestType !== 'Change Username') {
        redirect_to_settings('error', 'RequestNotAllowed');
    }
    if (!is_valid_username($newUsername)) {
        redirect_to_settings('error', 'InvalidUsername');
    }
    if (strlen($reason) < 3 || strlen($reason) > 500) {
        redirect_to_settings('error', 'InvalidReason');
    }
    if ($currentPassword === '') {
        redirect_to_settings('error', 'WrongCurrentPassword');
    }

    try {
        $conn->begin_transaction();

        $userStmt = $conn->prepare(
            "SELECT username, password_hash
             FROM users
             WHERE user_id = ? AND status = 'Active'
             LIMIT 1 FOR UPDATE"
        );
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user || !password_verify($currentPassword, (string) $user['password_hash'])) {
            $conn->rollback();
            redirect_to_settings('error', 'WrongCurrentPassword');
        }

        $currentUsername = strtolower((string) $user['username']);
        if (hash_equals($currentUsername, $newUsername)) {
            $conn->rollback();
            redirect_to_settings('error', 'UsernameUnchanged');
        }

        $duplicate = $conn->prepare('SELECT user_id FROM users WHERE LOWER(username) = ? LIMIT 1');
        $duplicate->bind_param('s', $newUsername);
        $duplicate->execute();
        $usernameExists = $duplicate->get_result()->num_rows > 0;
        $duplicate->close();
        if ($usernameExists) {
            $conn->rollback();
            redirect_to_settings('error', 'UsernameAlreadyExists');
        }

        $pending = $conn->prepare(
            "SELECT request_id
             FROM user_requests
             WHERE request_type = 'Change Username'
               AND status = 'Pending'
               AND (user_id = ? OR LOWER(new_value) = ?)
             LIMIT 1 FOR UPDATE"
        );
        $pending->bind_param('is', $userId, $newUsername);
        $pending->execute();
        $pendingExists = $pending->get_result()->num_rows > 0;
        $pending->close();
        if ($pendingExists) {
            $conn->rollback();
            redirect_to_settings('error', 'PendingRequestExists');
        }

        $insert = $conn->prepare(
            "INSERT INTO user_requests (user_id, request_type, new_value, reason, status)
             VALUES (?, 'Change Username', ?, ?, 'Pending')"
        );
        $insert->bind_param('iss', $userId, $newUsername, $reason);
        $insert->execute();
        $requestId = (int) $insert->insert_id;
        $insert->close();

        create_role_notification(
            $conn,
            'Admin',
            'Action required: @' . $currentUsername . ' submitted a username-change request.'
        );
        write_request_audit(
            $conn,
            $userId,
            'SUBMIT_REQUEST',
            'Submitted username-change request #' . $requestId . ' from @' . $currentUsername . ' to @' . $newUsername . '.'
        );

        $conn->commit();
        redirect_to_settings('success', 'RequestSubmitted');
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log('Username request submission failed: ' . $exception->getMessage());
        if ($exception->getCode() === 1062) {
            $duplicateMessage = $exception->getMessage();
            $feedback = strpos($duplicateMessage, 'uq_user_requests_pending_user') !== false
                ? 'PendingRequestExists'
                : 'UsernameAlreadyExists';
            redirect_to_settings('error', $feedback);
        }
        redirect_to_settings('error', 'RequestFailed');
    }
}

if ($action === 'manage_request') {
    $adminId = (int) ($_SESSION['user_id'] ?? 0);
    if ($adminId < 1 || (string) ($_SESSION['role'] ?? '') !== 'Admin') {
        header('Location: ../dashboard.php');
        exit();
    }

    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $decision = (string) ($_POST['decision'] ?? '');
    if (!$requestId || $requestId < 1) {
        redirect_to_requests('error', 'RequestNotFound');
    }
    if (!in_array($decision, ['Approve', 'Reject'], true)) {
        redirect_to_requests('error', 'InvalidDecision');
    }

    try {
        $conn->begin_transaction();

        $requestStmt = $conn->prepare(
            "SELECT r.user_id, r.request_type, r.new_value, r.status,
                    u.username AS current_username, u.full_name, u.role
             FROM user_requests r
             INNER JOIN users u ON u.user_id = r.user_id
             WHERE r.request_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $request = $requestStmt->get_result()->fetch_assoc();
        $requestStmt->close();

        if (!$request) {
            $conn->rollback();
            redirect_to_requests('error', 'RequestNotFound');
        }
        if ((string) $request['status'] !== 'Pending') {
            $conn->rollback();
            redirect_to_requests('error', 'RequestAlreadyResolved');
        }
        if ((string) $request['request_type'] !== 'Change Username') {
            $conn->rollback();
            redirect_to_requests('error', 'UnsupportedRequestType');
        }

        $targetUserId = (int) $request['user_id'];
        $newUsername = strtolower(trim((string) $request['new_value']));
        $finalStatus = $decision === 'Approve' ? 'Approved' : 'Rejected';

        if ($decision === 'Approve') {
            if (!is_valid_username($newUsername)) {
                $conn->rollback();
                redirect_to_requests('error', 'InvalidUsername');
            }

            $duplicate = $conn->prepare(
                'SELECT user_id FROM users WHERE LOWER(username) = ? AND user_id <> ? LIMIT 1'
            );
            $duplicate->bind_param('si', $newUsername, $targetUserId);
            $duplicate->execute();
            $usernameExists = $duplicate->get_result()->num_rows > 0;
            $duplicate->close();
            if ($usernameExists) {
                $conn->rollback();
                redirect_to_requests('error', 'UsernameAlreadyExists');
            }

            $updateUser = $conn->prepare('UPDATE users SET username = ? WHERE user_id = ?');
            $updateUser->bind_param('si', $newUsername, $targetUserId);
            $updateUser->execute();
            $updateUser->close();
        }

        $updateRequest = $conn->prepare(
            'UPDATE user_requests
             SET status = ?, resolved_at = NOW(), resolved_by = ?
             WHERE request_id = ? AND status = \'Pending\''
        );
        $updateRequest->bind_param('sii', $finalStatus, $adminId, $requestId);
        $updateRequest->execute();
        if ($updateRequest->affected_rows !== 1) {
            $updateRequest->close();
            $conn->rollback();
            redirect_to_requests('error', 'RequestAlreadyResolved');
        }
        $updateRequest->close();

        $notificationMessage = 'Your username-change request was ' . strtolower($finalStatus) . ' by the Admin.';
        create_personal_notification(
            $conn,
            $targetUserId,
            (string) $request['role'],
            $notificationMessage,
            'settings.php',
            'account_request:' . $requestId . ':' . strtolower($finalStatus)
        );

        write_request_audit(
            $conn,
            $adminId,
            'MANAGE_REQUEST',
            'Admin ' . strtolower($finalStatus) . ' username-change request #' . $requestId .
            ' for ' . (string) $request['full_name'] . ' (@' . (string) $request['current_username'] . ').'
        );

        $conn->commit();
        redirect_to_requests('success', 'ActionCompleted');
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log('Username request decision failed: ' . $exception->getMessage());
        redirect_to_requests('error', $exception->getCode() === 1062 ? 'UsernameAlreadyExists' : 'RequestFailed');
    }
}

// Password recovery is handled only by the verified email OTP flow.
if (in_array($action, ['request_forgot_password', 'verify_reset_code'], true)) {
    header('Location: ../forgot_password.php?error=UseEmailOtp');
    exit();
}

header('Location: ../dashboard.php');
exit();
