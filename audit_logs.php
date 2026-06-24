<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

// -------------------------------------------------------------------------
// SERVER-SIDE ACTION LOGGING FOR EXPORT ACTIVITY
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_export_activity'])) {
    ob_clean(); // Prevent any prior HTML/warnings from breaking JSON
    header('Content-Type: application/json');
    
    $recordCount = isset($_POST['record_count']) ? (int)$_POST['record_count'] : 0;
    $exportType = isset($_POST['export_type']) ? $_POST['export_type'] : 'Unknown Scope';
    
    $userId = $_SESSION['user_id'];
    $action = 'EXPORT_AUDIT_LOGS';
    
    // Construct an enterprise-standard activity description
    $desc = "System Admin exported " . number_format($recordCount) . " audit records. Selected option: $exportType. Format: Excel (.xlsx).";
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isss", $userId, $action, $desc, $ip);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
            exit();
        }
    }
    echo json_encode(['status' => 'error']);
    exit();
}
// -------------------------------------------------------------------------

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] !== 'Admin' && !has_permission($conn, $_SESSION['user_id'], 'can_view_audit_logs')) {
    header("Location: dashboard.php");
    exit();
}

/**
 * Enterprise Parser: Converts raw system action logs into human-friendly statements.
 */
function parseAuditRecord($userName, $actionType, $description) {
    $subject = htmlspecialchars($userName ?: "System Admin");
    $verb = "performed an action on";
    $object = "a system record";
    $details = "";
    $icon = "info-circle";
    $color = "secondary";
    $module = "System";
    $status = "Success";
    
    $descLower = strtolower($description);

    // 1. Determine Natural Language Mapping based on Action Type
    if ($actionType === 'LOGIN') {
        $verb = "accessed";
        $object = "the system";
        $icon = "sign-in-alt";
        $color = "success";
        $module = "Authentication";
        if (strpos($descLower, 'fail') !== false || strpos($descLower, 'invalid') !== false) {
            $status = "Failed";
            $color = "danger";
            $details = "Invalid login attempt.";
        } else {
            $details = "User successfully authenticated.";
        }
    } elseif ($actionType === 'LOGOUT') {
        $verb = "logged out of";
        $object = "the system";
        $icon = "sign-out-alt";
        $color = "secondary";
        $module = "Authentication";
        $details = "Session securely terminated.";
    } elseif (strpos($actionType, 'UPLOAD') !== false) {
        $verb = "uploaded";
        if (preg_match('/Official Record: (.*?) \[(.*?)\]/i', $description, $matches)) {
            $object = "a document record: <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
            $module = "Document Management";
            $details = "Category classified under " . htmlspecialchars($matches[2]) . ".";
        } else {
            $object = "a new document";
            $module = "Document Management";
            $details = htmlspecialchars($description);
        }
        $icon = "cloud-upload-alt";
        $color = "primary";
    } elseif ($actionType === 'CREATE_PO') {
        $verb = "created";
        if (preg_match('/new PO: (PO-\d{4}-\d{4})/i', $description, $matches)) {
            $object = "Purchase Order <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a new Purchase Order";
        }
        if (preg_match('/PR ID: (\d+)/i', $description, $m)) {
            $details = "Successfully mapped to Purchase Request ID: " . $m[1] . ".";
        } else {
            $details = htmlspecialchars($description);
        }
        $module = "Purchase Orders";
        $icon = "file-invoice";
        $color = "primary";
    } elseif ($actionType === 'APPROVE_PO') {
        $verb = "approved";
        if (preg_match('/PO (\d+)/i', $description, $matches)) {
            $object = "Purchase Order ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a Purchase Order";
        }
        if (preg_match('/to (.*)/i', $description, $matches)) {
            $details = "Status advanced to " . htmlspecialchars($matches[1]) . ".";
        }
        $module = "Purchase Orders";
        $icon = "check-double";
        $color = "success";
    } elseif ($actionType === 'ADD_PAYMENT') {
        $verb = "processed a payment for";
        if (preg_match('/PO (\d+)/i', $description, $matches)) {
            $object = "Purchase Order ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a Purchase Order";
        }
        if (preg_match('/payment of (P\d+)/i', $description, $matches)) {
            $details = "Payment amount applied: " . htmlspecialchars($matches[1]) . ".";
        }
        $module = "Finance";
        $icon = "coins";
        $color = "warning";
    } elseif ($actionType === 'ARCHIVE_FILE') {
        $verb = "archived";
        if (preg_match('/Document ID: (\d+)/i', $description, $matches)) {
            $object = "Document ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a document record";
        }
        $module = "Document Management";
        $details = "Record successfully moved to archive storage.";
        $icon = "archive";
        $color = "secondary";
    } elseif ($actionType === 'RESTORE_FILE') {
        $verb = "restored";
        if (preg_match('/Document ID: (\d+)/i', $description, $matches)) {
            $object = "Document ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a document record";
        }
        $module = "Document Management";
        $details = "Record retrieved and restored from archive storage.";
        $icon = "trash-restore";
        $color = "info";
    } elseif ($actionType === 'PRINT_DOC') {
        $verb = "printed";
        if (preg_match('/document: (.*)/i', $description, $matches)) {
            $object = "the document <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a document";
        }
        $module = "Document Management";
        $icon = "print";
        $color = "secondary";
    } elseif ($actionType === 'DOWNLOAD_DOC') {
        $verb = "downloaded";
        if (preg_match('/document: (.*)/i', $description, $matches)) {
            $object = "the file <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a document file";
        }
        $module = "Document Management";
        $icon = "download";
        $color = "info";
    } elseif ($actionType === 'CREATE_QUOTATION') {
        $verb = "encoded";
        if (preg_match('/Quotation (#\S+)/i', $description, $matches)) {
            $object = "Quotation <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a new Quotation";
        }
        if (preg_match('/for client (.*?)\./i', $description, $matches)) {
            $details = "Client designated as " . htmlspecialchars($matches[1]) . ".";
        }
        $module = "Quotations";
        $icon = "file-signature";
        $color = "primary";
    } elseif ($actionType === 'RECEIVE_CLIENT_PO') {
        $verb = "received";
        if (preg_match('/Client PO (#\S+)/i', $description, $matches)) {
            $object = "Client PO <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } elseif (preg_match('/Ref: (CPO-\S+)/i', $description, $matches)) {
            $object = "Client Approval Ref: <span class=\"fw-bold text-dark\">" . htmlspecialchars($matches[1]) . "</span>";
        } else {
            $object = "a Client PO";
        }
        if (preg_match('/Quotation ID: (\d+)/i', $description, $m)) {
            $details = "Mapped to Quotation ID: " . $m[1];
        }
        $module = "Quotations";
        $icon = "handshake";
        $color = "success";
    } elseif ($actionType === 'VIEW_RECORD') {
        $verb = "accessed";
        if (preg_match('/Purchase Request ID: (\d+)/i', $description, $matches)) {
            $object = "Purchase Request PR-" . str_pad($matches[1], 4, '0', STR_PAD_LEFT);
            $module = "Purchase Requests";
        } else {
            $object = "system record details";
            $module = "Records Management";
        }
        $details = "Viewed complete record information.";
        $icon = "folder-open";
        $color = "info";
    } elseif (strpos($actionType, 'UPDATE') !== false || strpos($actionType, 'EDIT') !== false) {
        $verb = "updated";
        $object = str_replace('_', ' ', strtolower($actionType)) . " record";
        $icon = "edit";
        $color = "warning";
        $module = "System Operations";
        if ($actionType === 'UPDATE_VERSION' && preg_match('/Doc ID: (\d+)/i', $description, $matches)) {
            $object = "a version update for Document ID " . $matches[1];
            $module = "Document Management";
            if (preg_match('/Uploaded (v\d+\.\d+)/i', $description, $m2)) {
                $details = "Updated to version: " . $m2[1];
            }
        } else {
            $details = htmlspecialchars($description);
        }
    } elseif (strpos($actionType, 'DELETE') !== false || strpos($actionType, 'REMOVE') !== false) {
        $verb = "deleted";
        $object = str_replace('_', ' ', strtolower($actionType));
        $icon = "trash-alt";
        $color = "danger";
        $module = "System Operations";
        $details = htmlspecialchars($description);
    } else {
        $verb = "executed";
        $object = str_replace('_', ' ', strtolower($actionType)) . " action";
        $icon = "cog";
        $color = "secondary";
        $module = "System Access";
        $details = htmlspecialchars($description);
    }

    // 2. Assign high-level categorization for filters
    $category = "System";
    if (in_array($color, ['success', 'warning']) && strpos($actionType, 'APPROVE') !== false) $category = "Approval";
    elseif ($color === 'primary' || strpos($actionType, 'CREATE') !== false || strpos($actionType, 'UPLOAD') !== false) $category = "Creation";
    elseif ($color === 'warning' || strpos($actionType, 'UPDATE') !== false || strpos($actionType, 'EDIT') !== false) $category = "Modification";
    elseif ($color === 'danger' || strpos($actionType, 'DELETE') !== false) $category = "Deletion";
    elseif (in_array($actionType, ['LOGIN', 'LOGOUT'])) $category = "Security";
    else $category = "System Access";

    // 3. Construct natural language sentence
    $fullSentence = "<span class='fw-bold' style='color: #0f172a;'>$subject</span> <span style='color: #64748b;'>$verb</span> $object.";

    return [
        'sentence' => $fullSentence,
        'details' => $details,
        'icon' => $icon,
        'color' => $color,
        'module' => $module,
        'status' => $status,
        'category' => $category
    ];
}

// Data Fetching & Processing
$excluded_sql = "'PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT'"; 
$query = "
    SELECT a.*, u.full_name, u.role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.user_id 
    WHERE a.action_type NOT IN ($excluded_sql) 
    ORDER BY a.`timestamp` DESC 
    LIMIT 3000";
    
$logs = $conn->query($query);

// Analytics Computation
$totalLogs = 0;
$criticalCount = 0;
$authEvents = 0;
$distinctUsers = [];
$parsedLogs = [];

if ($logs) {
    while ($row = $logs->fetch_assoc()) {
        $totalLogs++;
        if (!empty($row['user_id'])) {
            $distinctUsers[$row['user_id']] = true;
        }
        
        $parsed = parseAuditRecord($row['full_name'], $row['action_type'], $row['description']);
        
        if ($parsed['category'] === 'Deletion') {
            $criticalCount++;
        }
        if ($parsed['category'] === 'Security') {
            $authEvents++;
        }
        
        $row['parsed'] = $parsed;
        $parsedLogs[] = $row;
    }
}
$activeUsersCount = count($distinctUsers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audit Trail - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    
    <style>
        :root {
            --bg-body: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --primary: #2563eb;
            --primary-glow: rgba(37, 99, 235, 0.15);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0ea5e9;
            --secondary: #64748b;
        }

        body, .main-content {
            background-color: var(--bg-body) !important;
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            color: var(--text-main);
        }
        
        .main-content {
            padding-top: 90px !important;
            padding-bottom: 3rem !important;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }

        .kpi-corp-card { 
            background: var(--card-bg); 
            border-radius: 8px; 
            border: 1px solid var(--border-light); 
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03); 
            padding: 1rem 1.15rem; 
            height: 100%; 
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .accent-blue { border-top: 3px solid var(--primary); }
        .accent-rose { border-top: 3px solid var(--danger); }
        .accent-emerald { border-top: 3px solid var(--success); }
        .accent-amber { border-top: 3px solid var(--warning); }
        
        .kpi-corp-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .kpi-corp-title { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin: 0; }
        .kpi-corp-value { font-size: 1.4rem; font-weight: 700; color: var(--text-main); line-height: 1; margin: 0; margin-top: 6px; }
        .kpi-corp-icon { width: 34px; height: 34px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }

        .corp-widget { 
            background: var(--card-bg); 
            border-radius: 8px; 
            border: 1px solid var(--border-light); 
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03); 
        }

        .btn-modern {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.45rem 1rem;
            border-radius: 6px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary-modern {
            background-color: var(--primary);
            border: 1px solid var(--primary);
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn-primary-modern:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
            color: #ffffff;
            box-shadow: 0 4px 12px var(--primary-glow);
        }
        
        .btn-view-details {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            transition: all 0.2s;
        }
        .btn-view-details:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #f8fafc;
        }

        .badge-soft {
            font-weight: 600;
            font-size: 0.7rem;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            letter-spacing: 0.3px;
        }

        .bg-soft-primary { background-color: rgba(37, 99, 235, 0.1); color: var(--primary); border: 1px solid rgba(37, 99, 235, 0.2); }
        .bg-soft-success { background-color: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
        .bg-soft-warning { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.2); }
        .bg-soft-danger { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); }
        .bg-soft-info { background-color: rgba(14, 165, 233, 0.1); color: var(--info); border: 1px solid rgba(14, 165, 233, 0.2); }
        .bg-soft-secondary { background-color: rgba(100, 116, 139, 0.1); color: var(--secondary); border: 1px solid rgba(100, 116, 139, 0.2); }

        .form-control-sleek, .form-select-sleek {
            border: 1px solid #cbd5e1;
            font-size: 0.85rem;
            border-radius: 6px;
            color: #334155;
            background-color: #f8fafc;
            box-shadow: none;
            padding: 0.45rem 0.75rem;
        }
        .form-control-sleek:focus, .form-select-sleek:focus {
            background-color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .table-corp th {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            background: #f8fafc;
            border-bottom: 1px solid var(--border-light);
            padding: 12px 16px;
        }
        .table-corp td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
        }
        .timeline-row:hover td { background-color: #f8fafc; }

        .icon-circle-sm {
            width: 32px; height: 32px;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
        }

        /* Modal Adjustments */
        .modal-content { border-radius: 8px; border: none; }
        .modal-header { border-bottom: 1px solid var(--border-light); background: #ffffff; border-radius: 8px 8px 0 0; }
        .modal-body { background: #f8fafc; }
        .modal-footer { border-top: 1px solid var(--border-light); background: #ffffff; border-radius: 0 0 8px 8px; }
        
        .cursor-pointer { cursor: pointer; }

        /* Custom Enterprise Pagination Styling */
        .btn-pagination {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.35rem 0.75rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-pagination:hover:not(:disabled) {
            background: #f8fafc;
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-pagination:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }
        .btn-pagination:first-child { border-top-left-radius: 6px; border-bottom-left-radius: 6px; }
        .btn-pagination:last-child { border-top-right-radius: 6px; border-bottom-right-radius: 6px; }
        
        .page-input-styled {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-align: center;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }
        .page-input-styled:focus { border-color: var(--primary); }
        .page-input-styled::-webkit-inner-spin-button, 
        .page-input-styled::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in">
        
        <header class="mb-4 pb-2 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="flex-grow-1">
                <h5 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;"><i class="fas fa-shield-alt text-primary me-2"></i>System Audit Trail</h5>
                <p class="text-muted mb-0" style="font-size: 0.8rem;">Monitor enterprise activity, security events, and user actions.</p>
            </div>
            
            <button class="btn-modern btn-primary-modern" onclick="openExportModal()">
                <i class="fas fa-file-export"></i> Export Configuration
            </button>
        </header>

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-blue">
                    <div class="kpi-corp-header">
                        <div>
                            <p class="kpi-corp-title">Tracked Events</p>
                            <h3 class="kpi-corp-value"><?= number_format($totalLogs) ?></h3>
                        </div>
                        <div class="kpi-corp-icon bg-soft-primary">
                            <i class="fas fa-stream"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-rose">
                    <div class="kpi-corp-header">
                        <div>
                            <p class="kpi-corp-title">Critical Actions</p>
                            <h3 class="kpi-corp-value"><?= number_format($criticalCount) ?></h3>
                        </div>
                        <div class="kpi-corp-icon bg-soft-danger">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-emerald">
                    <div class="kpi-corp-header">
                        <div>
                            <p class="kpi-corp-title">Active Accounts</p>
                            <h3 class="kpi-corp-value"><?= number_format($activeUsersCount) ?></h3>
                        </div>
                        <div class="kpi-corp-icon bg-soft-success">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-amber">
                    <div class="kpi-corp-header">
                        <div>
                            <p class="kpi-corp-title">Security Events</p>
                            <h3 class="kpi-corp-value"><?= number_format($authEvents) ?></h3>
                        </div>
                        <div class="kpi-corp-icon bg-soft-warning">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="corp-widget p-0 overflow-hidden mb-4 d-flex flex-column bg-white">
            
            <div class="p-3 border-bottom d-flex flex-wrap gap-3 align-items-end">
                <div style="min-width: 180px;">
                    <label class="form-label fw-semibold text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase;">Module Focus</label>
                    <select class="form-select form-select-sm form-select-sleek" id="filterModule">
                        <option value="">All Modules</option>
                        <option value="Authentication">Authentication</option>
                        <option value="Document Management">Document Management</option>
                        <option value="Purchase Orders">Purchase Orders</option>
                        <option value="Purchase Requests">Purchase Requests</option>
                        <option value="Quotations">Quotations Tracker</option>
                        <option value="Finance">Finance</option>
                        <option value="System">System Operations</option>
                    </select>
                </div>
                <div style="min-width: 160px;">
                    <label class="form-label fw-semibold text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase;">Action Category</label>
                    <select class="form-select form-select-sm form-select-sleek" id="filterCategory">
                        <option value="">All Categories</option>
                        <option value="Security">Security & Auth</option>
                        <option value="Creation">Creation</option>
                        <option value="Modification">Modification</option>
                        <option value="Approval">Approval</option>
                        <option value="Deletion">Deletion</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="max-width: 350px; margin-left: auto;">
                    <label class="form-label fw-semibold text-muted mb-1" style="font-size: 0.7rem; text-transform: uppercase;">Search Records</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-transparent text-muted" style="border-color: #cbd5e1; border-right: none;"><i class="fas fa-search fa-xs"></i></span>
                        <input type="text" id="searchTable" class="form-control form-control-sleek ps-0" style="border-left: none;" placeholder="Search user, action...">
                    </div>
                </div>
            </div>

            <div class="table-responsive flex-grow-1">
                <table id="auditTable" class="table table-corp align-middle mb-0 w-100">
                    <thead>
                        <tr>
                            <th class="ps-4">Activity Timeline</th>
                            <th>Module Focus</th>
                            <th>Category</th>
                            <th>Date & Time</th>
                            <th class="text-end pe-4">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($parsedLogs as $log): 
                            $p = $log['parsed'];
                            $timeFormatted = date('M d, Y h:i A', strtotime($log['timestamp']));
                            $safeRole = htmlspecialchars($log['role'] ?? 'Unknown');
                        ?>
                        <tr class="timeline-row">
                            <td class="ps-4" style="max-width: 380px;">
                                <div class="d-flex align-items-start py-1">
                                    <div class="icon-circle-sm bg-soft-<?= $p['color'] ?> flex-shrink-0 me-3 mt-1">
                                        <i class="fas fa-<?= $p['icon'] ?>"></i>
                                    </div>
                                    <div>
                                        <div class="timeline-desc"><?= $p['sentence'] ?></div>
                                        <?php if(!empty($p['details'])): ?>
                                            <div class="timeline-details"><i class="fas fa-level-up-alt fa-rotate-90 text-muted me-1" style="font-size: 0.65rem;"></i> <?= $p['details'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="text-muted fw-semibold" style="font-size: 0.8rem;"><?= $p['module'] ?></span>
                            </td>
                            <td>
                                <span class="badge badge-soft bg-soft-<?= $p['color'] ?>"><?= $p['category'] ?></span>
                            </td>
                            <td data-order="<?= strtotime($log['timestamp']) ?>">
                                <div class="text-main fw-semibold" style="font-size: 0.85rem; color: #0f172a;"><?= date('M d, Y', strtotime($log['timestamp'])) ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i:s A', strtotime($log['timestamp'])) ?></div>
                            </td>
                            <td class="text-end pe-4">
                                <button class="btn-view-details" 
                                        onclick="viewAuditDetails(this)"
                                        data-log-id="<?= htmlspecialchars($log['log_id']) ?>"
                                        data-user="<?= htmlspecialchars($log['full_name'] ?: 'System Administrator') ?>"
                                        data-role="<?= $safeRole ?>"
                                        data-action="<?= htmlspecialchars($log['action_type']) ?>"
                                        data-ip="<?= htmlspecialchars($log['ip_address']) ?>"
                                        data-time="<?= htmlspecialchars($timeFormatted) ?>"
                                        data-module="<?= htmlspecialchars($p['module']) ?>"
                                        data-desc="<?= htmlspecialchars($log['description']) ?>"
                                        data-sentence="<?= htmlspecialchars($p['sentence']) ?>"
                                        >
                                    View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-3 border-top bg-light d-flex flex-wrap align-items-center justify-content-between gap-3">
                
                <div class="text-muted fw-medium" style="font-size: 0.8rem;" id="customPageInfo">
                    Showing 0-0 of 0 records
                </div>

                <div class="d-flex align-items-center flex-wrap gap-4">
                    
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted fw-medium" style="font-size: 0.75rem;">Rows per page:</span>
                        <select id="customPageLength" class="form-select form-select-sm form-select-sleek" style="width: 70px; padding-top: 0.25rem; padding-bottom: 0.25rem;">
                            <option value="15">15</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    
                    <div class="d-flex align-items-center gap-2 text-muted fw-medium" style="font-size: 0.75rem;">
                        Page 
                        <input type="number" id="customPageInput" class="page-input-styled" style="width: 50px; padding: 0.25rem;" min="1" value="1">
                        of <span id="customTotalPages">1</span>
                    </div>

                    <div class="btn-group shadow-sm">
                        <button class="btn-pagination" id="customPrevBtn">
                            <i class="fas fa-chevron-left me-2" style="font-size: 0.65rem;"></i> Previous
                        </button>
                        <button class="btn-pagination" id="customNextBtn">
                            Next <i class="fas fa-chevron-right ms-2" style="font-size: 0.65rem;"></i>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="auditDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow">
                <div class="modal-header py-3 px-4">
                    <h5 class="modal-title fw-bold text-main" style="font-size: 1.05rem; color: #0f172a;">
                        <i class="fas fa-microchip text-primary me-2"></i> Log Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <div class="p-3 bg-white border rounded shadow-sm d-flex align-items-start" style="border-color: #e2e8f0 !important;">
                            <div id="techHumanReadable" style="font-size: 0.95rem; line-height: 1.5; color: #334155;"></div>
                        </div>
                    </div>
                    
                    <div id="techChangesSection"></div>
                    
                    <div class="mb-4">
                        <label class="text-uppercase fw-bold mb-2" style="font-size: 0.65rem; color: #64748b; letter-spacing: 0.5px;">Technical Metadata</label>
                        <div class="bg-white border rounded shadow-sm p-3" style="border-color: #e2e8f0 !important;">
                            <div class="row gy-3">
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">Record ID</div>
                                    <div class="fw-semibold" id="techLogId" style="font-size: 0.85rem; color: #0f172a;"></div>
                                </div>
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">User Identity</div>
                                    <div class="fw-semibold text-primary" id="techUser" style="font-size: 0.85rem;"></div>
                                </div>
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">Action Code</div>
                                    <div><span class="badge bg-soft-secondary font-monospace" style="font-size: 0.75rem;" id="techAction"></span></div>
                                </div>
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">Client IP</div>
                                    <div class="font-monospace" id="techIp" style="font-size: 0.85rem; color: #475569;"></div>
                                </div>
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">Module Affected</div>
                                    <div class="fw-semibold" id="techModule" style="font-size: 0.85rem; color: #0f172a;"></div>
                                </div>
                                <div class="col-md-6">
                                    <div style="font-size: 0.7rem; color: #64748b;">System Timestamp</div>
                                    <div class="fw-semibold" id="techTime" style="font-size: 0.85rem; color: #0f172a;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-uppercase fw-bold mb-2" style="font-size: 0.65rem; color: #64748b; letter-spacing: 0.5px;">Raw Payload</label>
                        <div class="p-3 font-monospace rounded shadow-sm" style="background-color: #0f172a; color: #10b981; font-size: 0.8rem; word-break: break-all;">
                            > <span id="techDesc"></span><span class="cursor-blink">_</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header py-3 px-4 bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-main" style="font-size: 1.05rem;">
                        <i class="fas fa-file-export text-primary me-2"></i> Export Audit Logs
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3 text-muted" style="font-size: 0.85rem;">
                        Pagination controls only affect your screen display. Select the full data scope you want to include in your Excel (.xlsx) report below.
                    </div>
                    
                    <div class="list-group list-group-flush border rounded">
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer bg-soft-primary" id="lblExportFiltered" style="border-left: 4px solid var(--primary);">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="filtered" checked style="font-size: 1.2rem;">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold text-main">Export Filtered Results</h6>
                                    <span class="badge bg-primary rounded-pill" id="exportFilteredCount">0</span>
                                </div>
                                <small class="text-muted" style="font-size: 0.75rem;">Includes all records matching current search and dropdown filters.</small>
                            </div>
                        </label>
                        
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportCurrent">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="current" style="font-size: 1.2rem;">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-semibold text-main">Export Current Page</h6>
                                    <span class="badge bg-secondary rounded-pill" id="exportCurrentCount">0</span>
                                </div>
                                <small class="text-muted" style="font-size: 0.75rem;">Includes only the visible rows strictly on this specific page.</small>
                            </div>
                        </label>

                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportAll">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="all" style="font-size: 1.2rem;">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-semibold text-danger">Export All Audit Logs</h6>
                                    <span class="badge bg-danger rounded-pill" id="exportAllCount">0</span>
                                </div>
                                <small class="text-muted" style="font-size: 0.75rem;">Exports the complete system audit history available.</small>
                            </div>
                        </label>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0 d-none" id="exportWarning" style="font-size: 0.8rem; background-color: #fffbeb; border-color: #fde68a; color: #92400e;">
                        <i class="fas fa-exclamation-triangle me-1"></i> <strong>Heavy Process:</strong> You are exporting a large dataset. The browser may take a few moments to compile the Excel file. Please do not close the window.
                    </div>
                </div>
                <div class="modal-footer border-top bg-light py-3 px-4">
                    <button type="button" class="btn btn-light border text-muted fw-semibold" data-bs-dismiss="modal" style="font-size: 0.85rem;">Cancel</button>
                    <button type="button" class="btn btn-primary-modern px-4" id="btnConfirmExport" onclick="processDataExport()">
                        <i class="fas fa-download"></i> Generate Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        $(document).ready(function() {
            // Initialize Sleek DataTables with native pagination hidden ("dom" attribute strips "p" and "i")
            var table = $('#auditTable').DataTable({
                "order": [[ 3, "desc" ]], 
                "dom": '<"d-none"f>rt', // Only search input and table data rendered natively
                "pageLength": 15,
                "language": {
                    "emptyTable": "<div class='text-center p-5 text-muted'><i class='fas fa-inbox fa-3x mb-3 opacity-50'></i><br><h5>No records found</h5><p class='mb-0' style='font-size: 0.85rem;'>No audit records found for the selected filters.</p></div>"
                }
            });

            // ---------------------------------------------------------
            // CUSTOM ENTERPRISE PAGINATION LOGIC
            // ---------------------------------------------------------
            
            // Function to sync custom HTML Footer with DataTables engine state
            function updateCustomPagination() {
                let info = table.page.info();
                
                // Formulate the Info String (e.g. "Showing 1-15 of 350 filtered activities")
                let startRange = info.recordsDisplay > 0 ? (info.start + 1) : 0;
                let endRange = info.end;
                let totalFilteredStr = Number(info.recordsDisplay).toLocaleString();
                let filterStatus = (info.recordsDisplay !== info.recordsTotal) ? ' filtered' : '';
                
                $('#customPageInfo').html(`Showing <span class="fw-bold text-main">${startRange}-${endRange}</span> of <span class="fw-bold text-main">${totalFilteredStr}</span>${filterStatus} activities`);
                
                // Update Inputs & Buttons
                let totalPages = info.pages === 0 ? 1 : info.pages;
                let currentPage = info.page + 1;
                
                $('#customTotalPages').text(totalPages);
                $('#customPageInput').val(currentPage);
                
                // Disable/Enable states
                $('#customPrevBtn').prop('disabled', info.page === 0);
                $('#customNextBtn').prop('disabled', info.page === (info.pages - 1) || info.pages === 0);
                $('#customPageInput').prop('max', totalPages);
            }

            // Sync on every table draw (search, filter, sort, page change)
            table.on('draw', function() {
                updateCustomPagination();
            });

            // Initial manual sync
            updateCustomPagination();

            // Next & Prev Button Clicks
            $('#customPrevBtn').on('click', function() { table.page('previous').draw('page'); });
            $('#customNextBtn').on('click', function() { table.page('next').draw('page'); });
            
            // Rows per page Dropdown change
            $('#customPageLength').on('change', function() {
                let len = parseInt($(this).val());
                table.page.len(len).draw();
            });

            // "Go to Page" Input change
            $('#customPageInput').on('change', function() {
                let requestedPage = parseInt($(this).val());
                let info = table.page.info();
                
                // Validate bounds
                if (requestedPage >= 1 && requestedPage <= info.pages) {
                    table.page(requestedPage - 1).draw('page');
                } else {
                    $(this).val(info.page + 1); // Reset to valid current page
                }
            });


            // ---------------------------------------------------------
            // BIND FILTERS TO DATATABLES NATIVE SEARCH
            // ---------------------------------------------------------
            $('#searchTable').on('keyup', function() {
                table.search(this.value).draw();
            });

            $('#filterModule').on('change', function() {
                table.column(1).search(this.value).draw();
            });
            
            $('#filterCategory').on('change', function() {
                table.column(2).search(this.value).draw();
            });


            // ---------------------------------------------------------
            // EXPORT MODAL LOGIC
            // ---------------------------------------------------------
            $('input[name="exportOption"]').on('change', function() {
                $('.list-group-item').removeClass('bg-soft-primary').css('border-left', 'none');
                $(this).closest('.list-group-item').addClass('bg-soft-primary').css('border-left', '4px solid var(--primary)');
                
                let selectedVal = $(this).val();
                let countNum = 0;
                if(selectedVal === 'filtered') countNum = parseInt($('#exportFilteredCount').text());
                else if(selectedVal === 'current') countNum = parseInt($('#exportCurrentCount').text());
                else countNum = parseInt($('#exportAllCount').text());

                if (countNum > 1500) {
                    $('#exportWarning').removeClass('d-none');
                } else {
                    $('#exportWarning').addClass('d-none');
                }
            });
        });

        // Populate and open the Technical View Modal
        function viewAuditDetails(btn) {
            const d = btn.dataset;
            
            $('#techLogId').text(d.logId);
            $('#techUser').text(d.user);
            $('#techAction').text(d.action);
            $('#techIp').text(d.ip);
            $('#techTime').text(d.time);
            $('#techModule').text(d.module);
            $('#techDesc').text(d.desc);
            $('#techHumanReadable').html(d.sentence);
            
            let techChangesDiv = document.getElementById('techChangesSection');
            techChangesDiv.innerHTML = ''; 
            let descString = String(d.desc);
            let match = descString.match(/changed from (.*?) to (.*)/i);
            
            if (match) {
                techChangesDiv.innerHTML = `
                    <div class="row mb-4 gx-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-white h-100 shadow-sm" style="border-color: #e2e8f0 !important; border-left: 3px solid #ef4444 !important;">
                                <div class="fw-bold mb-1" style="font-size: 0.65rem; color: #ef4444; text-transform: uppercase;">PREVIOUS VALUE</div>
                                <div class="fw-semibold mt-2" style="font-size: 0.9rem; color: #0f172a;">${match[1]}</div>
                            </div>
                        </div>
                        <div class="col-sm-6 mt-2 mt-sm-0">
                            <div class="border rounded p-3 bg-white h-100 shadow-sm" style="border-color: #e2e8f0 !important; border-left: 3px solid #10b981 !important;">
                                <div class="fw-bold mb-1" style="font-size: 0.65rem; color: #10b981; text-transform: uppercase;">UPDATED VALUE</div>
                                <div class="fw-semibold mt-2" style="font-size: 0.9rem; color: #0f172a;">${match[2]}</div>
                            </div>
                        </div>
                    </div>
                `;
            }

            new bootstrap.Modal(document.getElementById('auditDetailsModal')).show();
        }

        // Initialize and open Export Options Modal
        function openExportModal() {
            let tableAPI = $('#auditTable').DataTable();
            
            // Extract accurate counts directly from DataTables engine ensuring pagination doesn't limit export scope
            let filteredCount = tableAPI.rows({ search: 'applied' }).count();
            let currentCount = tableAPI.rows({ page: 'current' }).count();
            let allCount = tableAPI.rows().count();
            
            $('#exportFilteredCount').text(filteredCount);
            $('#exportCurrentCount').text(currentCount);
            $('#exportAllCount').text(allCount);
            
            // Re-trigger selection to apply warnings if needed
            $('input[name="exportOption"]:checked').trigger('change');
            
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }

        // Execute data extraction, formatting, and file generation
        function processDataExport() {
            let btn = $('#btnConfirmExport');
            let originalContent = btn.html();
            btn.html('<i class="fas fa-spinner fa-spin me-2"></i> Processing...').prop('disabled', true);
            
            let exportType = $('input[name="exportOption"]:checked').val();
            let tableAPI = $('#auditTable').DataTable();
            let selector = {};
            let typeName = "";
            
            // Map selector behavior to user choice (safely avoiding pagination restrictions)
            if (exportType === 'current') {
                selector = { page: 'current' };
                typeName = "Current Visible Page";
            } else if (exportType === 'filtered') {
                selector = { search: 'applied' };
                typeName = "Filtered Results";
            } else {
                selector = {}; // Entire loaded dataset
                typeName = "All Audit Logs";
            }

            // Extract the nodes representing the selected data
            let rows = tableAPI.rows(selector).nodes();
            let recordCount = rows.length;

            if (recordCount === 0) {
                alert("No records found matching your criteria to export.");
                btn.html(originalContent).prop('disabled', false);
                return;
            }

            // Using timeout allows the UI to render the "Processing" spinner before locking thread for heavy exports
            setTimeout(() => {
                let exportData = [];
                
                // Build an Enterprise JSON payload stripping HTML out
                $(rows).each(function() {
                    let viewBtn = $(this).find('.btn-view-details');
                    let rawSentence = viewBtn.data('sentence');
                    let cleanSentence = rawSentence ? rawSentence.replace(/<[^>]+>/g, '') : '';
                    
                    exportData.push({
                        "Log ID": viewBtn.data('log-id'),
                        "Date & Time": viewBtn.data('time'),
                        "User Identity": viewBtn.data('user'),
                        "User Role": viewBtn.data('role'),
                        "Action Type": viewBtn.data('action'),
                        "Module Focus": viewBtn.data('module'),
                        "Activity Description": cleanSentence,
                        "Technical Context": viewBtn.data('desc'),
                        "Client IP Address": viewBtn.data('ip')
                    });
                });

                try {
                    // Convert robust JSON array into SheetJS Worksheet
                    let worksheet = XLSX.utils.json_to_sheet(exportData);
                    let workbook = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(workbook, worksheet, "System Audit Export");
                    
                    // Column Width Adjustments for Professional formatting
                    worksheet['!cols'] = [
                        {wch: 10}, // ID
                        {wch: 22}, // Date
                        {wch: 22}, // User
                        {wch: 15}, // Role
                        {wch: 20}, // Action
                        {wch: 20}, // Module
                        {wch: 65}, // Description
                        {wch: 45}, // Tech Context
                        {wch: 16}  // IP
                    ];

                    // Trigger File Download
                    XLSX.writeFile(workbook, "System_Audit_Logs_" + new Date().toISOString().slice(0,10) + ".xlsx");
                    
                    // Server-side action tracking
                    $.post(window.location.href, {
                        log_export_activity: 1,
                        record_count: recordCount,
                        export_type: typeName
                    }).always(function() {
                        btn.html(originalContent).prop('disabled', false);
                        bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
                    });
                    
                } catch (err) {
                    console.error("Export Error:", err);
                    alert("An error occurred during export processing. Check console for details.");
                    btn.html(originalContent).prop('disabled', false);
                }
            }, 50); // slight UI delay
        }
    </script>
</body>
</html>