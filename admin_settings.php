<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require_once 'config/upload_policy.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { header("Location: dashboard.php"); exit(); }

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('session_timeout', '30'), ('max_upload_size', '5'), ('maintenance_mode', '0')");

$settings = [];
$res = $conn->query("SELECT * FROM system_settings");
while($row = $res->fetch_assoc()){ $settings[$row['setting_key']] = $row['setting_value']; }

$allowed_upload_sizes = drms_upload_allowed_document_limits_mb();
$effective_upload_size = drms_upload_document_limit_mb($conn);
$server_upload_limit = drms_upload_server_limit_mb();

$toastMsg = ''; $toastType = '';
if(isset($_GET['success'])) {
    $toastType = 'success';
    $toastMsg = $_GET['success'] === 'SettingsUpdated'
        ? 'System settings updated successfully.'
        : htmlspecialchars($_GET['success']);
} elseif(isset($_GET['error'])) {
    $toastType = 'error';
    if ($_GET['error'] === 'InvalidSessionTimeout') {
        $toastMsg = 'Select one of the available session timeout options.';
    } elseif ($_GET['error'] === 'InvalidUploadSize') {
        $toastMsg = 'Select an upload size supported by the current hosting server.';
    } elseif ($_GET['error'] === 'SecurityTokenMismatch') {
        $toastMsg = 'Your session validation expired. Refresh the page and try again.';
    } else {
        $toastMsg = htmlspecialchars($_GET['error']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Global Settings - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/mobile-settings-admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-settings-admin.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/sweetalert2/11.26.25/sweetalert2.min.css">
</head>
<body class="page-admin-settings">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="container-fluid max-w-900 pt-3 admin-settings-shell">
            
            <div class="mb-4 admin-page-header">
                <h3 class="fw-bold text-slate-800 mb-1 tracking-tight">Global System Settings</h3>
                <p class="text-slate-500 mb-0 fs-md">Configure core system behaviors, security parameters, and limits.</p>
            </div>

            <form action="actions/settings_handler.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="row g-4 align-items-stretch">
                    <div class="col-md-6">
                        <div class="saas-panel">
                            <div class="panel-icon-wrapper bg-warning bg-opacity-10 text-warning"><i class="fas fa-shield-alt"></i></div>
                            <h5 class="fw-bold text-slate-800 mb-2">Security & Sessions</h5>
                            <p class="text-slate-500 mb-4 fs-sm" style="line-height: 1.6;">Manage how long users can stay idle before the system automatically logs them out to protect sensitive data.</p>
                            <div class="mt-auto">
                                <label class="form-label-sleek">Session Idle Timeout</label>
                                <select name="session_timeout" class="form-select sleek-input">
                                    <option value="15" <?php echo ($settings['session_timeout'] == '15') ? 'selected' : ''; ?>>15 Minutes (Strict)</option>
                                    <option value="30" <?php echo ($settings['session_timeout'] == '30') ? 'selected' : ''; ?>>30 Minutes (Recommended)</option>
                                    <option value="60" <?php echo ($settings['session_timeout'] == '60') ? 'selected' : ''; ?>>1 Hour (Extended)</option>
                                    <option value="120" <?php echo ($settings['session_timeout'] == '120') ? 'selected' : ''; ?>>2 Hours (Lenient)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="saas-panel">
                            <div class="panel-icon-wrapper bg-info bg-opacity-10 text-info"><i class="fas fa-hdd"></i></div>
                            <h5 class="fw-bold text-slate-800 mb-2">Storage Limits</h5>
                            <p class="text-slate-500 mb-4 fs-sm" style="line-height: 1.6;">Set the maximum allowable file size for document uploads. Keeping this low prevents rapid server storage exhaustion.</p>
                            <div class="mt-auto">
                                <label class="form-label-sleek">Max Upload Size (per file)</label>
                                <select name="max_upload_size" class="form-select sleek-input">
                                    <?php foreach ($allowed_upload_sizes as $upload_size): ?>
                                        <option value="<?php echo $upload_size; ?>" <?php echo ($effective_upload_size === $upload_size) ? 'selected' : ''; ?>>
                                            <?php echo $upload_size; ?> MB<?php echo $upload_size === 5 ? ' (Standard)' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="d-block text-muted mt-2">Current hosting limit: <?php echo $server_upload_limit; ?> MB per file. Unsupported sizes are removed automatically.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary fw-bold shadow-sm w-100 w-sm-auto rounded-custom px-5 py-2 fs-md">
                        <i class="fas fa-save me-2"></i> Save Configurations
                    </button>
                </div>
            </form>
            
        </div>
    </div>

    <script src="assets/vendor/jquery/3.7.0/jquery.min.js"></script>
    <script src="assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/sweetalert2/11.26.25/sweetalert2.all.min.js"></script>
    
    <script>
        const Toast = Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 3000, timerProgressBar: true, customClass: { popup: 'small-toast' } });
        const toastMsg = "<?php echo $toastMsg; ?>"; const toastType = "<?php echo $toastType; ?>";
        if (toastMsg !== '') { Toast.fire({ icon: toastType, title: toastMsg }); window.history.replaceState(null, null, window.location.pathname); }
    </script>
</body>
</html>

