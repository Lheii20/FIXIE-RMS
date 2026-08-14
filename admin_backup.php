<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { header("Location: dashboard.php"); exit(); }
$db_name = getenv('DB_NAME') ?: "fixie_drms";

if (isset($_GET['action']) && $_GET['action'] == 'download') {
    if (function_exists('log_audit_action')) { log_audit_action($conn, $_SESSION['user_id'], 'DATABASE_BACKUP', 'Admin successfully generated and downloaded a full SQL database backup.'); }
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()){ $tables[] = $row[0]; }
    $sqlScript = "-- Fixie DRMS System Backup\n-- Generation Time: " . date('M d, Y at H:i A') . "\n-- Database: `$db_name`\n\nSET FOREIGN_KEY_CHECKS = 0;\nSTART TRANSACTION;\n\n";
    foreach($tables as $table){
        $result = $conn->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_row();
        $sqlScript .= "-- Table structure for `$table`\nDROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";
        $result = $conn->query("SELECT * FROM `$table`");
        $columnCount = $result->field_count;
        $rowCount = 0;
        while($row = $result->fetch_row()){
            if ($rowCount == 0) { $sqlScript .= "-- Dumping data for table `$table`\n"; }
            $sqlScript .= "INSERT INTO `$table` VALUES(";
            for($j = 0; $j < $columnCount; $j++){
                if(!isset($row[$j])){ $sqlScript .= "NULL"; } else { $sqlScript .= "'" . $conn->real_escape_string($row[$j]) . "'"; }
                if($j < ($columnCount - 1)){ $sqlScript .= ','; }
            }
            $sqlScript .= ");\n";
            $rowCount++;
        }
        $sqlScript .= "\n";
    }
    $sqlScript .= "COMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n";
    $filename = "backup_" . $db_name . "_" . date('Y-m-d_H-i-s') . ".sql";
    ob_clean();
    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"$filename\"");
    echo $sqlScript; exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'restore') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { header("Location: admin_backup.php?error=SecurityTokenMismatch"); exit(); }
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'sql') { header("Location: admin_backup.php?error=Invalid file format. Please upload a .sql file."); exit(); }
        $fileTmpName = $_FILES['sql_file']['tmp_name'];
        $sql_content = file_get_contents($fileTmpName);
        if (!empty($sql_content)) {
            try {
                if ($conn->multi_query($sql_content)) {
                    do { if ($res = $conn->store_result()) { $res->free(); } } while ($conn->more_results() && $conn->next_result());
                    if (function_exists('log_audit_action')) { log_audit_action($conn, $_SESSION['user_id'], 'DATABASE_RESTORE', 'Admin successfully restored the database from a backup file.'); }
                    header("Location: admin_backup.php?success=Database restored successfully."); exit();
                } else { throw new Exception($conn->error); }
            } catch (Exception $e) { header("Location: admin_backup.php?error=Restoration failed: " . urlencode($e->getMessage())); exit(); }
        } else { header("Location: admin_backup.php?error=The uploaded file is empty."); exit(); }
    } else { header("Location: admin_backup.php?error=No file selected or upload error occurred."); exit(); }
}

$toastMsg = ''; $toastType = '';
if(isset($_GET['success'])) { $toastType = 'success'; $toastMsg = htmlspecialchars($_GET['success']); } elseif(isset($_GET['error'])) { $toastType = 'error'; $toastMsg = htmlspecialchars($_GET['error']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Database Management - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/mobile-settings-admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-settings-admin.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="page-admin-backup">
    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid max-w-1000 pt-3 admin-backup-shell">
            <div class="mb-4 admin-page-header">
                <h3 class="fw-bold text-slate-800 mb-1 tracking-tight">Database Management</h3>
                <p class="text-slate-500 mb-0 fs-md">Maintain system integrity through secure backups and disaster recovery protocols.</p>
            </div>

            <div class="info-banner mb-4 shadow-sm d-flex align-items-center gap-3">
                <div class="bg-white text-primary rounded-circle d-flex justify-content-center align-items-center shadow-sm box-40 flex-shrink-0">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-slate-800 mb-1 fs-md">Best Practice Recommendation</h6>
                    <p class="text-slate-500 mb-0 fs-sm">It is highly recommended to generate and download a full backup <strong>at least once a week</strong>. Store the `.sql` file in a secure external drive or dedicated cloud storage.</p>
                </div>
            </div>

            <div class="row g-4 align-items-stretch">
                <div class="col-lg-6">
                    <div class="saas-panel">
                        <div class="panel-icon-wrapper bg-primary bg-opacity-10 text-primary"><i class="fas fa-cloud-download-alt"></i></div>
                        <h5 class="fw-bold text-slate-800 mb-2">Export Full Backup</h5>
                        <p class="text-slate-500 mb-4 fs-md" style="line-height: 1.6;">
                            Instantly package your entire database structure, user accounts, and operational records into a single, downloadable SQL file. This process runs securely in the background without affecting currently logged-in users.
                        </p>
                        <div class="mt-auto">
                            <a href="admin_backup.php?action=download" class="btn btn-primary fw-semibold w-100 shadow-sm d-flex justify-content-center align-items-center gap-2 rounded-custom py-2">
                                <i class="fas fa-file-export"></i> Generate & Download SQL
                            </a>
                            <div class="text-center mt-3">
                                <small class="text-muted fw-medium fs-xs text-uppercase tracking-wide">
                                    <i class="fas fa-check-circle text-success me-1"></i> Safe Operation
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="saas-panel">
                        <div class="panel-icon-wrapper bg-danger bg-opacity-10 text-danger"><i class="fas fa-database"></i></div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="fw-bold text-slate-800 mb-0">System Restoration</h5>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 fs-xs tracking-wide">CRITICAL</span>
                        </div>
                        <p class="text-slate-500 mb-3 fs-md" style="line-height: 1.6;">
                            Upload a previously generated backup file to revert the system. <strong class="text-danger">Proceed with caution</strong>, as this will completely overwrite the existing data.
                        </p>
                        
                        <form action="admin_backup.php" method="POST" enctype="multipart/form-data" id="restoreForm" class="mt-auto m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="restore">
                            <label class="upload-zone mb-3" id="uploadZone">
                                <input type="file" name="sql_file" id="sqlFile" accept=".sql" required onchange="updateFileName(this)">
                                <div id="uploadZoneContent">
                                    <div class="mb-2"><div class="d-inline-flex bg-white shadow-sm rounded-circle p-3 mb-2"><i class="fas fa-upload text-muted fs-4"></i></div></div>
                                    <span class="fw-bold text-slate-800 d-block mb-1 fs-md">Click to upload or drag file</span>
                                    <span class="text-muted fs-xs">Only .sql files are supported</span>
                                </div>
                            </label>
                            <button type="button" class="btn btn-light border text-danger fw-semibold w-100 d-flex justify-content-center align-items-center gap-2 rounded-custom py-2" onclick="confirmRestore()">
                                <i class="fas fa-exclamation-triangle"></i> Overwrite & Restore
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const Toast = Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'small-toast' }});
        const toastMsg = "<?php echo $toastMsg; ?>"; const toastType = "<?php echo $toastType; ?>";
        if (toastMsg !== '') { Toast.fire({ icon: toastType, title: toastMsg }); window.history.replaceState(null, null, window.location.pathname); }

        function updateFileName(input) {
            const zone = document.getElementById('uploadZone'); const content = document.getElementById('uploadZoneContent');
            if (input.files && input.files[0]) {
                const fileName = input.files[0].name; zone.classList.add('has-file');
                content.innerHTML = `<div class="mb-2"><div class="d-inline-flex bg-success bg-opacity-10 text-success rounded-circle p-3 mb-2"><i class="fas fa-check fs-4"></i></div></div><span class="fw-bold text-success d-block mb-1 text-truncate px-3 fs-md">${fileName}</span><span class="text-success text-opacity-75 fw-medium fs-xs">Ready for restoration</span>`;
            } else {
                zone.classList.remove('has-file');
                content.innerHTML = `<div class="mb-2"><div class="d-inline-flex bg-white shadow-sm rounded-circle p-3 mb-2"><i class="fas fa-upload text-muted fs-4"></i></div></div><span class="fw-bold text-slate-800 d-block mb-1 fs-md">Click to upload or drag file</span><span class="text-muted fs-xs">Only .sql files are supported</span>`;
            }
        }

        function confirmRestore() {
            const fileInput = document.getElementById('sqlFile');
            if (!fileInput.value) { Toast.fire({ icon: 'warning', title: 'Please select a .sql backup file first.' }); return; }
            Swal.fire({
                title: 'Are you absolutely sure?', html: "<span class='text-slate-500 fs-md'>This action will permanently drop the current database and restore the uploaded data. Any changes made after this backup was created will be lost.</span>", icon: 'warning', padding: '1.5rem', showCancelButton: true, confirmButtonColor: '#ef4444', cancelButtonColor: '#f1f5f9', confirmButtonText: 'Yes, Overwrite System', cancelButtonText: '<span style="color:#475569; font-weight: 500;">Cancel</span>', customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn btn-danger', cancelButton: 'sleek-btn' }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Restoring Database...', html: '<span class="text-muted fs-sm">Please wait. Do not refresh or close this tab.</span>', allowOutsideClick: false, showConfirmButton: false, customClass: { popup: 'sleek-popup' }, didOpen: () => { Swal.showLoading(); }});
                    document.getElementById('restoreForm').submit();
                }
            });
        }
    </script>
</body>
</html>
