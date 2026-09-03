<?php

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/backup_restore.php';

if (
    empty($_SESSION['user_id']) ||
    (string) ($_SESSION['role'] ?? '') !== 'Admin'
) {
    http_response_code(403);
    exit('Access denied.');
}

function drms_backup_redirect(string $kind, string $code): void
{
    $allowed_kinds = ['success', 'error'];
    $allowed_codes = [
        'BackupCreated',
        'SecurityTokenMismatch',
        'BackupBusy',
        'BackupFailed',
        'InvalidBackup',
        'WrongPassword',
        'ConfirmationMismatch',
        'RestoreFailed',
        'RestoreRolledBack',
    ];

    if (!in_array($kind, $allowed_kinds, true)) {
        $kind = 'error';
    }
    if (!in_array($code, $allowed_codes, true)) {
        $code = 'BackupFailed';
    }

    header('Location: ../admin_backup.php?' . $kind . '=' . rawurlencode($code));
    exit;
}

function drms_backup_open_audit_connection(): ?mysqli
{
    $config = drms_backup_database_config();
    try {
        $connection = new mysqli(
            $config['host'],
            $config['user'],
            $config['password'],
            $config['database'],
            (int) $config['port']
        );
        $connection->set_charset('utf8mb4');
        return $connection;
    } catch (Throwable $error) {
        error_log('Backup audit reconnect failed: ' . $error->getMessage());
        return null;
    }
}

function drms_backup_end_current_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

function drms_backup_render_restore_success(string $safety_filename): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    $safe_backup = htmlspecialchars($safety_filename, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Restore completed - Fixie DRMS</title></head>';
    echo '<body style="margin:0;background:#f8fafc;color:#0f172a;';
    echo 'font:14px/1.5 Arial,sans-serif;display:grid;min-height:100vh;place-items:center">';
    echo '<main style="width:min(520px,calc(100% - 32px));background:#fff;';
    echo 'border:1px solid #cbd5e1;border-radius:14px;padding:28px;box-sizing:border-box">';
    echo '<div style="width:44px;height:44px;border-radius:10px;background:#dcfce7;';
    echo 'color:#047857;display:grid;place-items:center;font-size:22px;margin-bottom:16px">&#10003;</div>';
    echo '<h1 style="font-size:20px;margin:0 0 8px">System restore completed</h1>';
    echo '<p style="margin:0 0 12px;color:#475569">The database and uploaded-record repository were restored as one verified package.</p>';
    echo '<p style="margin:0 0 20px;color:#475569">A rollback copy was retained as <strong>' . $safe_backup . '</strong>.</p>';
    echo '<a href="../index.php" style="display:inline-flex;min-height:40px;align-items:center;';
    echo 'padding:0 16px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;';
    echo 'font-weight:700">Return to sign in</a></main></body></html>';
    exit;
}

$request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_REQUEST['action'] ?? ''));
$admin_user_id = (int) $_SESSION['user_id'];

if ($request_method === 'GET' && $action === 'download') {
    try {
        $filename = basename((string) ($_GET['backup'] ?? ''));
        $archive_path = drms_backup_resolve_archive($filename);
        log_audit_action(
            $conn,
            $admin_user_id,
            'FULL_BACKUP_DOWNLOAD',
            'Downloaded protected full backup package: ' . $filename
        );

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($archive_path));
        header(
            'Content-Disposition: attachment; filename="' .
            str_replace('"', '', $filename) . '"'
        );
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $stream = fopen($archive_path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the backup archive.');
        }
        fpassthru($stream);
        fclose($stream);
        exit;
    } catch (Throwable $error) {
        error_log('Backup download failed: ' . $error->getMessage());
        drms_backup_redirect('error', 'InvalidBackup');
    }
}

if ($request_method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$posted_token = (string) ($_POST['csrf_token'] ?? '');
$session_token = (string) ($_SESSION['csrf_token'] ?? '');
if (
    $posted_token === '' ||
    $session_token === '' ||
    !hash_equals($session_token, $posted_token)
) {
    drms_backup_redirect('error', 'SecurityTokenMismatch');
}

if ($action === 'create_backup') {
    $operation_lock = null;
    try {
        set_time_limit(0);
        $operation_lock = drms_backup_acquire_operation_lock();
        $package = drms_backup_create_package('manual');
        log_audit_action(
            $conn,
            $admin_user_id,
            'FULL_BACKUP_CREATED',
            sprintf(
                'Created verified full backup %s with %d uploaded files.',
                $package['filename'],
                (int) $package['manifest']['uploads']['file_count']
            )
        );
        drms_backup_redirect('success', 'BackupCreated');
    } catch (Throwable $error) {
        error_log('Full backup creation failed: ' . $error->getMessage());
        $code = str_contains(strtolower($error->getMessage()), 'already running')
            ? 'BackupBusy'
            : 'BackupFailed';
        drms_backup_redirect('error', $code);
    } finally {
        drms_backup_release_operation_lock($operation_lock);
    }
}

if ($action === 'restore_backup') {
    $filename = basename((string) ($_POST['backup'] ?? ''));
    $confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));
    $current_password = (string) ($_POST['current_password'] ?? '');

    if (!drms_backup_archive_name_is_valid($filename)) {
        drms_backup_redirect('error', 'InvalidBackup');
    }
    if ($confirmation !== 'RESTORE') {
        drms_backup_redirect('error', 'ConfirmationMismatch');
    }

    $password_statement = $conn->prepare(
        "SELECT password_hash
         FROM users
         WHERE user_id = ?
           AND role = 'Admin'
           AND status = 'Active'
         LIMIT 1"
    );
    $password_statement->bind_param('i', $admin_user_id);
    $password_statement->execute();
    $admin = $password_statement->get_result()->fetch_assoc();
    $password_statement->close();
    if (
        !$admin ||
        !password_verify($current_password, (string) $admin['password_hash'])
    ) {
        drms_backup_redirect('error', 'WrongPassword');
    }

    $operation_lock = null;
    $restore_marker_active = false;
    try {
        set_time_limit(0);
        $operation_lock = drms_backup_acquire_operation_lock();
        $archive_path = drms_backup_resolve_archive($filename);

        // Reject a damaged or foreign package before maintenance mode begins.
        $database_config = drms_backup_database_config();
        drms_backup_verify_package($archive_path, $database_config['database']);

        log_audit_action(
            $conn,
            $admin_user_id,
            'FULL_RESTORE_STARTED',
            'Started verified full restore from package: ' . $filename
        );
        drms_backup_write_restore_marker($admin_user_id, $filename);
        $restore_marker_active = true;

        $conn->close();
        $restore = drms_backup_restore_package($archive_path);

        $audit_connection = drms_backup_open_audit_connection();
        if ($audit_connection instanceof mysqli) {
            log_audit_action(
                $audit_connection,
                $admin_user_id,
                'FULL_RESTORE_COMPLETED',
                'Completed verified full restore from package: ' . $filename
            );
            $audit_connection->close();
        }

        drms_backup_clear_restore_marker();
        $restore_marker_active = false;
        drms_backup_release_operation_lock($operation_lock);
        $operation_lock = null;
        drms_backup_end_current_session();
        drms_backup_render_restore_success(
            (string) $restore['safety_backup']['filename']
        );
    } catch (Throwable $error) {
        error_log('Full restore failed: ' . $error->getMessage());
        $rolled_back = str_contains(
            strtolower($error->getMessage()),
            'automatic rollback restored'
        );

        $audit_connection = drms_backup_open_audit_connection();
        if ($audit_connection instanceof mysqli) {
            log_audit_action(
                $audit_connection,
                $admin_user_id,
                'FULL_RESTORE_FAILED',
                $rolled_back
                    ? 'Full restore failed; automatic rollback completed.'
                    : 'Full restore failed before completion.'
            );
            $audit_connection->close();
        }

        if ($restore_marker_active) {
            drms_backup_clear_restore_marker();
        }
        drms_backup_release_operation_lock($operation_lock);
        $operation_lock = null;
        drms_backup_redirect(
            'error',
            $rolled_back ? 'RestoreRolledBack' : 'RestoreFailed'
        );
    } finally {
        if ($restore_marker_active) {
            drms_backup_clear_restore_marker();
        }
        drms_backup_release_operation_lock($operation_lock);
    }
}

http_response_code(400);
exit('Invalid backup action.');
