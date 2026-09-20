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

if (($_POST['action'] ?? '') !== 'set_theme') {
    header('Location: ../settings.php?error=InvalidPreference');
    exit();
}

$theme = $_POST['theme'] ?? null;
if (!is_string($theme) || !in_array($theme, ['light', 'dark'], true)) {
    header('Location: ../settings.php?error=InvalidPreference');
    exit();
}

$userId = (int) $_SESSION['user_id'];
try {
    $stmt = $conn->prepare(
        'INSERT INTO user_preferences (user_id, theme) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE theme = VALUES(theme)'
    );
    $stmt->bind_param('is', $userId, $theme);
    $stmt->execute();
    $stmt->close();
    log_audit_action($conn, $userId, 'UPDATE_PREFERENCES', 'User changed display theme to ' . $theme . '.');
} catch (Throwable $exception) {
    error_log('User theme preference could not be saved: ' . $exception->getMessage());
    header('Location: ../settings.php?error=PreferenceSaveFailed');
    exit();
}

header('Location: ../settings.php?success=ThemeUpdated');
exit();
