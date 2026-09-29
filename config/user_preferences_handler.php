<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/workflow_access.php';
require_once __DIR__ . '/../config/functions.php';

drms_require_login('../index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit();
}

$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    header('Location: ../settings.php?error=SecurityTokenMismatch');
    exit();
}

$action = (string) ($_POST['action'] ?? '');
$userId = (int) $_SESSION['user_id'];

try {
    if ($action === 'set_theme') {
        $theme = $_POST['theme'] ?? null;
        if (!is_string($theme) || !in_array($theme, ['light', 'dark'], true)) {
            throw new InvalidArgumentException('InvalidPreference');
        }

        $stmt = $conn->prepare(
            'INSERT INTO user_preferences (user_id, theme) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE theme = VALUES(theme)'
        );
        $stmt->bind_param('is', $userId, $theme);
        $stmt->execute();
        $stmt->close();
        log_audit_action($conn, $userId, 'UPDATE_PREFERENCES', 'User changed display theme to ' . $theme . '.');
        header('Location: ../settings.php?success=ThemeUpdated');
        exit();
    }

    if ($action === 'set_approval_email') {
        $enabled = isset($_POST['approval_email_enabled']) && $_POST['approval_email_enabled'] === '1' ? 1 : 0;

        $emailStatement = $conn->prepare(
            "SELECT email
             FROM users
             WHERE user_id = ?
               AND status = 'Active'
             LIMIT 1"
        );
        $emailStatement->bind_param('i', $userId);
        $emailStatement->execute();
        $account = $emailStatement->get_result()->fetch_assoc();
        $emailStatement->close();

        if ($enabled === 1 && filter_var((string) ($account['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            header('Location: ../settings.php?error=ApprovalEmailNeedsVerifiedAddress');
            exit();
        }

        $stmt = $conn->prepare(
            'INSERT INTO user_preferences (user_id, approval_email_enabled) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE approval_email_enabled = VALUES(approval_email_enabled)'
        );
        $stmt->bind_param('ii', $userId, $enabled);
        $stmt->execute();
        $stmt->close();

        log_audit_action(
            $conn,
            $userId,
            'UPDATE_PREFERENCES',
            'User ' . ($enabled === 1 ? 'enabled' : 'disabled') . ' approval email notifications.'
        );
        header('Location: ../settings.php?success=ApprovalEmailPreferenceUpdated');
        exit();
    }

    throw new InvalidArgumentException('InvalidPreference');
} catch (InvalidArgumentException $exception) {
    header('Location: ../settings.php?error=' . rawurlencode($exception->getMessage()));
    exit();
} catch (Throwable $exception) {
    error_log('User preference could not be saved: ' . $exception->getMessage());
    header('Location: ../settings.php?error=PreferenceSaveFailed');
    exit();
}
