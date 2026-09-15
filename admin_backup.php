<?php

require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/backup_restore.php';

if (empty($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$feedback_messages = [
    'BackupCreated' => ['type' => 'success', 'message' => 'Complete backup created and verified successfully.'],
    'BackupUnavailable' => ['type' => 'warning', 'message' => 'Complete server backup and restore are unavailable on the current hosting environment. Use the manual hosting backup steps shown on this page.'],
    'SecurityTokenMismatch' => ['type' => 'error', 'message' => 'Your session validation expired. Refresh the page and try again.'],
    'BackupBusy' => ['type' => 'warning', 'message' => 'Another backup or restore operation is currently running.'],
    'BackupFailed' => ['type' => 'error', 'message' => 'The complete backup could not be created. Check the server error log.'],
    'InvalidBackup' => ['type' => 'error', 'message' => 'The selected backup is missing, damaged, or not a supported Fixie DRMS package.'],
    'WrongPassword' => ['type' => 'error', 'message' => 'The Administrator password is incorrect.'],
    'ConfirmationMismatch' => ['type' => 'error', 'message' => 'Type RESTORE exactly before starting a system restore.'],
    'RestoreFailed' => ['type' => 'error', 'message' => 'The restore could not be completed. Existing data was not intentionally removed.'],
    'RestoreRolledBack' => ['type' => 'warning', 'message' => 'The restore failed, but automatic rollback returned the previous system state.'],
];

$toast_type = '';
$toast_message = '';
foreach (['success', 'error'] as $feedback_kind) {
    $feedback_code = trim((string) ($_GET[$feedback_kind] ?? ''));
    if ($feedback_code !== '' && isset($feedback_messages[$feedback_code])) {
        $toast_type = $feedback_messages[$feedback_code]['type'];
        $toast_message = $feedback_messages[$feedback_code]['message'];
        break;
    }
}

$backup_capability = drms_backup_capability_report();
$server_backup_available = $backup_capability['available'] === true;
$hosting_profile = (string) $backup_capability['profile'];
$backup_packages = [];
$storage_error = '';
if ($server_backup_available) {
    try {
        $backup_packages = drms_backup_list_packages();
    } catch (Throwable $error) {
        error_log('Backup list failed: ' . $error->getMessage());
        $storage_error = 'Protected backup storage is unavailable. Check folder permissions before creating a backup.';
    }
}
$restorable_packages = array_values(array_filter(
    $backup_packages,
    static fn(array $package): bool => $package['valid_manifest'] === true
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup & Restore - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/mobile-settings-admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-settings-admin.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/sweetalert2/11.26.25/sweetalert2.min.css">
</head>
<body class="page-admin-backup">
<?php include 'sidebar.php'; ?>

<main class="main-content fade-in">
    <div class="container-fluid max-w-1400 pt-3 admin-backup-shell">
        <header class="admin-page-header d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
                <h2 class="fw-bold text-slate-800 mb-1 tracking-tight">Backup & Restore</h2>
                <p class="text-slate-500 mb-0 fs-md">Protect the database and uploaded company records as one recoverable package.</p>
            </div>
            <span class="badge <?php echo $server_backup_available ? 'bg-success text-success border-success-subtle' : 'bg-warning text-warning-emphasis border-warning-subtle'; ?> bg-opacity-10 border px-3 py-2 rounded-pill">
                <i class="fas <?php echo $server_backup_available ? 'fa-shield-alt' : 'fa-server'; ?> me-1" aria-hidden="true"></i>
                <?php echo $server_backup_available ? 'Server recovery available' : 'Manual host backup required'; ?>
            </span>
        </header>

        <?php if ($storage_error !== ''): ?>
            <div class="alert alert-danger border d-flex align-items-start gap-2" role="alert">
                <i class="fas fa-exclamation-circle mt-1" aria-hidden="true"></i>
                <span><?php echo e($storage_error); ?></span>
            </div>
        <?php endif; ?>

        <section class="info-banner mb-4 d-flex align-items-start gap-3">
            <div class="bg-white text-primary rounded-circle d-flex justify-content-center align-items-center border box-40 flex-shrink-0">
                <i class="fas <?php echo $server_backup_available ? 'fa-archive' : 'fa-info-circle'; ?>" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <?php if ($server_backup_available): ?>
                    <h6 class="fw-bold text-slate-800 mb-1 fs-md">One complete recovery package</h6>
                    <p class="text-slate-500 mb-0 fs-sm">Each ZIP contains the database, uploaded records, and a SHA-256 integrity manifest. Restore uses a server-side package so it is not limited by the browser's upload size.</p>
                <?php else: ?>
                    <h6 class="fw-bold text-slate-800 mb-1 fs-md">Hosting-aware protection is active</h6>
                    <p class="text-slate-500 mb-1 fs-sm">The <strong><?php echo e($hosting_profile); ?></strong> profile cannot safely run the complete server backup engine, so create and restore actions are disabled before they can fail or leave a partial package.</p>
                    <?php foreach ($backup_capability['reasons'] as $capability_reason): ?>
                        <p class="text-slate-500 mb-0 fs-sm"><i class="fas fa-info-circle me-1" aria-hidden="true"></i><?php echo e($capability_reason); ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$server_backup_available): ?>
            <section class="card border rounded-12 shadow-none mb-4" aria-labelledby="manualHostBackupTitle">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-start gap-3">
                        <div class="panel-icon-wrapper bg-primary bg-opacity-10 text-primary mb-0 flex-shrink-0"><i class="fas fa-download" aria-hidden="true"></i></div>
                        <div class="min-w-0">
                            <h5 id="manualHostBackupTitle" class="fw-bold text-slate-800 mb-2">Safe manual hosting backup</h5>
                            <ol class="text-slate-600 fs-sm mb-2 ps-3">
                                <li class="mb-1">Export the assigned database from the hosting control panel or phpMyAdmin as SQL.</li>
                                <li class="mb-1">Download the complete <code>uploads</code> directory through FTP.</li>
                                <li>Keep the SQL export, uploaded files, and a protected copy of <code>runtime.local.php</code> together outside the public website.</li>
                            </ol>
                            <p class="text-muted small mb-0">For paid hosting, enable <code>server_backup</code> only after the host confirms PHP ZIP, process execution, writable private storage, and MySQL client tools.</p>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div class="row g-4 align-items-stretch mb-4">
            <div class="col-lg-5">
                <section class="saas-panel h-100" aria-labelledby="createBackupTitle">
                    <div class="panel-icon-wrapper bg-primary bg-opacity-10 text-primary"><i class="fas fa-archive" aria-hidden="true"></i></div>
                    <h5 id="createBackupTitle" class="fw-bold text-slate-800 mb-2">Create complete backup</h5>
                    <p class="text-slate-500 mb-4 fs-md" style="line-height:1.6">Creates a consistent SQL export and copies every stored record into a verified ZIP kept in protected server storage.</p>
                    <form action="actions/backup_handler.php" method="POST" class="mt-auto m-0" id="createBackupForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="create_backup">
                        <button type="button" class="btn btn-primary fw-semibold w-100 d-flex justify-content-center align-items-center gap-2 rounded-custom py-2" onclick="confirmBackupCreation()" <?php echo (!$server_backup_available || $storage_error !== '') ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus" aria-hidden="true"></i> Generate complete backup
                        </button>
                    </form>
                </section>
            </div>

            <div class="col-lg-7">
                <section class="saas-panel h-100" aria-labelledby="restoreBackupTitle">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                        <div class="panel-icon-wrapper bg-danger bg-opacity-10 text-danger mb-0"><i class="fas fa-undo-alt" aria-hidden="true"></i></div>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1">CRITICAL</span>
                    </div>
                    <h5 id="restoreBackupTitle" class="fw-bold text-slate-800 mb-2">Restore complete system state</h5>
                    <p class="text-slate-500 mb-3 fs-md" style="line-height:1.6">The system verifies every package entry, creates an automatic pre-restore backup, and blocks concurrent system activity during restoration.</p>
                    <form action="actions/backup_handler.php" method="POST" id="restoreBackupForm" class="mt-auto m-0">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="restore_backup">
                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <label for="restoreBackup" class="form-label fw-semibold small mb-1">Backup package</label>
                                <select class="form-select sleek-input" name="backup" id="restoreBackup" required <?php echo (!$server_backup_available || empty($restorable_packages)) ? 'disabled' : ''; ?>>
                                    <option value="">Select a protected backup</option>
                                    <?php foreach ($restorable_packages as $package): ?>
                                        <option value="<?php echo e($package['filename']); ?>"><?php echo e($package['filename']); ?> — <?php echo e(drms_backup_human_bytes($package['size'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="restorePassword" class="form-label fw-semibold small mb-1">Current Admin password</label>
                                <input type="password" class="form-control sleek-input" name="current_password" id="restorePassword" autocomplete="current-password" required <?php echo (!$server_backup_available || empty($restorable_packages)) ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="restoreConfirmation" class="form-label fw-semibold small mb-1">Type RESTORE</label>
                                <input type="text" class="form-control sleek-input text-uppercase" name="confirmation" id="restoreConfirmation" autocomplete="off" spellcheck="false" required <?php echo (!$server_backup_available || empty($restorable_packages)) ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                        <button type="button" class="btn btn-light border border-danger-subtle text-danger fw-semibold w-100 d-flex justify-content-center align-items-center gap-2 rounded-custom py-2" onclick="confirmSystemRestore()" <?php echo (!$server_backup_available || empty($restorable_packages)) ? 'disabled' : ''; ?>>
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Verify and restore selected backup
                        </button>
                    </form>
                </section>
            </div>
        </div>

        <section class="card border rounded-12 shadow-none overflow-hidden" aria-labelledby="storedBackupsTitle">
            <div class="card-header bg-white border-bottom px-3 px-md-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 id="storedBackupsTitle" class="fw-bold text-slate-800 mb-1">Protected backup history</h5>
                    <p class="text-slate-500 small mb-0">Packages remain on this server until an authorized administrator manages the backup folder.</p>
                </div>
                <span class="badge bg-light text-slate-700 border px-3 py-2"><?php echo count($backup_packages); ?> package<?php echo count($backup_packages) === 1 ? '' : 's'; ?></span>
            </div>

            <?php if (empty($backup_packages)): ?>
                <div class="card-body text-center py-5">
                    <div class="d-inline-flex align-items-center justify-content-center bg-light text-secondary rounded-circle box-44 mb-3"><i class="fas fa-box-open" aria-hidden="true"></i></div>
                    <h6 class="fw-bold mb-1">No complete backups yet</h6>
                    <p class="text-muted small mb-0">Generate the first package before major configuration or deployment changes.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light"><tr><th class="ps-3 ps-md-4">Package</th><th>Type</th><th>Contents</th><th>Package size</th><th>Status</th><th class="text-end pe-3 pe-md-4">Download</th></tr></thead>
                        <tbody>
                        <?php foreach ($backup_packages as $package): ?>
                            <tr>
                                <td class="ps-3 ps-md-4">
                                    <div class="fw-semibold text-dark text-truncate" style="max-width:330px" title="<?php echo e($package['filename']); ?>"><?php echo e($package['filename']); ?></div>
                                    <small class="text-muted"><?php echo date('M d, Y · h:i A', $package['modified_at']); ?></small>
                                </td>
                                <td>
                                    <?php if ($package['backup_type'] === 'pre_restore'): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning-emphasis border border-warning-subtle">Pre-restore safety</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="d-block small fw-semibold text-dark"><?php echo number_format($package['upload_count']); ?> uploaded files</span><small class="text-muted">DB <?php echo e(drms_backup_human_bytes($package['database_bytes'])); ?> · Files <?php echo e(drms_backup_human_bytes($package['upload_bytes'])); ?></small></td>
                                <td class="fw-semibold"><?php echo e(drms_backup_human_bytes($package['size'])); ?></td>
                                <td>
                                    <?php if ($package['valid_manifest']): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>Recognized</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle"><i class="fas fa-times-circle me-1" aria-hidden="true"></i>Invalid manifest</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 pe-md-4">
                                    <?php if ($package['valid_manifest']): ?>
                                        <a class="btn btn-sm btn-light border rounded-8" href="actions/backup_handler.php?action=download&amp;backup=<?php echo rawurlencode($package['filename']); ?>" aria-label="Download <?php echo e($package['filename']); ?>"><i class="fas fa-download" aria-hidden="true"></i><span class="d-none d-xl-inline ms-1">Download</span></a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light border rounded-8" type="button" disabled>Unavailable</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script src="assets/vendor/jquery/3.7.0/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/sweetalert2/11.26.25/sweetalert2.all.min.js"></script>
<script>
const toastMessage = <?php echo json_encode($toast_message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const toastType = <?php echo json_encode($toast_type, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
if (toastMessage && window.Swal) {
    Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 4500, timerProgressBar: true, customClass: { popup: 'small-toast' } }).fire({ icon: toastType, title: toastMessage });
    window.history.replaceState(null, '', window.location.pathname);
}

async function confirmBackupCreation() {
    const approved = await window.DRMSFeedback.confirm({
        title: 'Create complete backup?',
        message: 'The database and every uploaded record will be packaged and verified before the backup is made available.',
        confirmText: 'Create backup',
        cancelText: 'Cancel',
        tone: 'info',
        focusConfirm: true,
        allowOutsideClick: false
    });

    if (!approved) return;
    window.DRMSFeedback.toast('Creating and verifying the complete backup. Keep this tab open.', 'info', 5000);
    document.getElementById('createBackupForm').submit();
}

async function confirmSystemRestore() {
    const form = document.getElementById('restoreBackupForm');
    const backup = document.getElementById('restoreBackup');
    const password = document.getElementById('restorePassword');
    const confirmation = document.getElementById('restoreConfirmation');

    if (!backup.value || !password.value || confirmation.value.trim().toUpperCase() !== 'RESTORE') {
        await window.DRMSFeedback.alert({
            title: 'Complete the restore fields',
            message: 'Select a backup, enter your current Admin password, and type RESTORE exactly.',
            confirmText: 'Review fields',
            tone: 'warning'
        });
        if (!backup.value) {
            backup.focus();
        } else if (!password.value) {
            password.focus();
        } else {
            confirmation.focus();
        }
        return;
    }

    const approved = await window.DRMSFeedback.confirm({
        title: 'Restore this complete backup?',
        message: 'Current database records and uploaded files will be replaced together. A verified pre-restore rollback package will be created automatically.',
        confirmText: 'Start protected restore',
        cancelText: 'Cancel',
        tone: 'danger',
        allowOutsideClick: false
    });

    if (!approved) return;
    confirmation.value = 'RESTORE';
    window.DRMSFeedback.toast('Restoring system state. Do not close this tab or stop Apache and MySQL.', 'warning', 6000);
    form.submit();
}
</script>
</body>
</html>

