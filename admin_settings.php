<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

// Security Check
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// ==========================================
// AUTO-SETUP SYSTEM SETTINGS TABLE (Foolproof)
// ==========================================
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
)");

// Insert default values if they don't exist yet
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
    ('session_timeout', '30'), 
    ('max_upload_size', '5'),
    ('maintenance_mode', '0')
");

// Fetch current settings
$settings = [];
$res = $conn->query("SELECT * FROM system_settings");
while($row = $res->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Prepare Toast Messages
$toastMsg = '';
$toastType = '';
if(isset($_GET['success'])) {
    $toastType = 'success';
    if($_GET['success'] == 'SettingsUpdated') $toastMsg = 'System settings updated successfully.';
    else $toastMsg = htmlspecialchars($_GET['success']);
} elseif(isset($_GET['error'])) {
    $toastType = 'error';
    $toastMsg = htmlspecialchars($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Global Settings - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Premium SaaS UI Styles */
        .saas-panel { 
            background: #ffffff; border-radius: 16px; padding: 2rem; 
            border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -1px rgba(0,0,0,0.02); 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); height: 100%;
        }
        .saas-panel:hover { border-color: #cbd5e1; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01); transform: translateY(-2px); }
        .panel-icon-wrapper { width: 54px; height: 54px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 1.25rem; }
        
        .sleek-input { border-radius: 8px; border: 1px solid #cbd5e1; padding: 0.65rem 1rem; font-size: 0.95rem; color: #1e293b; transition: all 0.2s; }
        .sleek-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
        .form-label-sleek { font-size: 0.85rem; font-weight: 700; color: #475569; margin-bottom: 0.5rem; letter-spacing: 0.3px; }
        
        .small-toast { font-size: 0.85rem !important; padding: 0.5rem !important; }
        .text-slate-800 { color: #1e293b !important; }
        .text-slate-500 { color: #64748b !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="container-fluid" style="max-width: 900px; margin: 0 auto; padding-top: 1rem;">
            
            <div class="mb-4">
                <h3 class="fw-bold text-slate-800 mb-1" style="letter-spacing: -0.5px;">Global System Settings</h3>
                <p class="text-slate-500 mb-0" style="font-size: 0.9rem;">Configure core system behaviors, security parameters, and limits.</p>
            </div>

            <form action="actions/settings_handler.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_settings">

                <div class="row g-4 align-items-stretch">
                    
                    <!-- SECURITY & SESSION -->
                    <div class="col-md-6">
                        <div class="saas-panel">
                            <div class="panel-icon-wrapper bg-warning bg-opacity-10 text-warning">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <h5 class="fw-bold text-slate-800 mb-2">Security & Sessions</h5>
                            <p class="text-slate-500 mb-4" style="font-size: 0.85rem; line-height: 1.6;">
                                Manage how long users can stay idle before the system automatically logs them out to protect sensitive data.
                            </p>
                            
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

                    <!-- STORAGE & UPLOAD -->
                    <div class="col-md-6">
                        <div class="saas-panel">
                            <div class="panel-icon-wrapper bg-info bg-opacity-10 text-info">
                                <i class="fas fa-hdd"></i>
                            </div>
                            <h5 class="fw-bold text-slate-800 mb-2">Storage Limits</h5>
                            <p class="text-slate-500 mb-4" style="font-size: 0.85rem; line-height: 1.6;">
                                Set the maximum allowable file size for document uploads. Keeping this low prevents rapid server storage exhaustion.
                            </p>
                            
                            <div class="mt-auto">
                                <label class="form-label-sleek">Max Upload Size (per file)</label>
                                <select name="max_upload_size" class="form-select sleek-input">
                                    <option value="2" <?php echo ($settings['max_upload_size'] == '2') ? 'selected' : ''; ?>>2 MB</option>
                                    <option value="5" <?php echo ($settings['max_upload_size'] == '5') ? 'selected' : ''; ?>>5 MB (Standard)</option>
                                    <option value="10" <?php echo ($settings['max_upload_size'] == '10') ? 'selected' : ''; ?>>10 MB</option>
                                    <option value="25" <?php echo ($settings['max_upload_size'] == '25') ? 'selected' : ''; ?>>25 MB (Large files)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary fw-bold shadow-sm" style="border-radius: 8px; padding: 0.6rem 2rem; font-size: 0.95rem;">
                        <i class="fas fa-save me-2"></i> Save Configurations
                    </button>
                </div>
            </form>
            
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            customClass: { popup: 'small-toast' }
        });

        const toastMsg = "<?php echo $toastMsg; ?>";
        const toastType = "<?php echo $toastType; ?>";
        if (toastMsg !== '') {
            Toast.fire({ icon: toastType, title: toastMsg });
            window.history.replaceState(null, null, window.location.pathname);
        }
    </script>
</body>
</html>