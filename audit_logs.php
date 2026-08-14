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

function parseAuditRecord($userName, $actionType, $description, $newPayloadJson = null, $oldPayloadJson = null) {
    $subject = htmlspecialchars($userName ?: "System Admin");
    $verb = "performed an action on";
    $object = "a system record";
    $details = "";
    $icon = "info-circle";
    $color = "secondary";
    $module = "System";
    $status = "Success";
    
    $descLower = strtolower($description);
    
    $newPayload = is_string($newPayloadJson) ? json_decode($newPayloadJson, true) : [];
    if (!is_array($newPayload)) $newPayload = [];

    $extract = function($key, $regex = null, $regexGroup = 1) use ($newPayload, $description) {
        if (isset($newPayload[$key]) && $newPayload[$key] !== '') {
            return $newPayload[$key];
        }
        if ($regex && preg_match($regex, $description, $matches) && isset($matches[$regexGroup])) {
            return $matches[$regexGroup];
        }
        return null;
    };

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
        $docName = $extract('document_name', '/Official Record: (.*?) \[(.*?)\]/i', 1) ?? $extract('title');
        $docCat = $extract('category', '/Official Record: (.*?) \[(.*?)\]/i', 2);
        
        if ($docName) {
            $object = "a document record: <span class=\"fw-bold text-dark\">" . htmlspecialchars($docName) . "</span>";
            $module = "Document Management";
            $details = $docCat ? "Category classified under " . htmlspecialchars($docCat) . "." : htmlspecialchars($description);
        } else {
            $object = "a new document";
            $module = "Document Management";
            $details = htmlspecialchars($description);
        }
        $icon = "cloud-upload-alt";
        $color = "primary";
    } elseif ($actionType === 'CREATE_PO') {
        $verb = "created";
        $poNumber = $extract('po_number', '/new PO: (PO-\d{4}-\d{4})/i');
        $prId = $extract('pr_id', '/PR ID: (\d+)/i');
        $object = $poNumber ? "Purchase Order <span class=\"fw-bold text-dark\">" . htmlspecialchars($poNumber) . "</span>" : "a new Purchase Order";
        $details = $prId ? "Successfully mapped to Purchase Request ID: " . htmlspecialchars($prId) . "." : htmlspecialchars($description);
        
        $module = "Purchase Orders";
        $icon = "file-invoice";
        $color = "primary";
    } elseif ($actionType === 'APPROVE_PO' || $actionType === 'WORKFLOW_ACTION') {
        $verb = "approved";
        $poId = $extract('po_id', '/PO (\d+)/i') ?? $extract('po_number', '/PO #(\S+)/i');
        $statusTo = $extract('to_status', '/to (.*)/i') ?? $extract('next_status');
        
        $object = $poId ? "Purchase Order <span class=\"fw-bold text-dark\">" . htmlspecialchars($poId) . "</span>" : "a Purchase Order";
        $details = $statusTo ? "Status advanced to " . htmlspecialchars($statusTo) . "." : (isset($newPayload['remarks']) ? "Remarks: " . htmlspecialchars($newPayload['remarks']) : htmlspecialchars($description));
        
        $module = "Purchase Orders";
        $icon = "check-double";
        $color = "success";
    } elseif ($actionType === 'ADD_PAYMENT') {
        $verb = "processed a payment for";
        $poId = $extract('po_id', '/PO (\d+)/i');
        $amount = $extract('amount', '/payment of (P[\d,]+)/i') ?? $extract('amount_paid');
        
        $object = $poId ? "Purchase Order <span class=\"fw-bold text-dark\">" . htmlspecialchars($poId) . "</span>" : "a Purchase Order";
        $details = $amount ? "Payment amount applied: " . htmlspecialchars($amount) . "." : htmlspecialchars($description);
        
        $module = "Finance";
        $icon = "coins";
        $color = "warning";
    } elseif ($actionType === 'ARCHIVE_FILE') {
        $verb = "archived";
        $docId = $extract('doc_id', '/Document ID: (\d+)/i') ?? $extract('document_id');
        
        $object = $docId ? "Document ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($docId) . "</span>" : "a document record";
        $module = "Document Management";
        $details = "Record successfully moved to archive storage.";
        $icon = "archive";
        $color = "secondary";
    } elseif ($actionType === 'RESTORE_FILE') {
        $verb = "restored";
        $docId = $extract('doc_id', '/Document ID: (\d+)/i') ?? $extract('document_id');
        
        $object = $docId ? "Document ID <span class=\"fw-bold text-dark\">" . htmlspecialchars($docId) . "</span>" : "a document record";
        $module = "Document Management";
        $details = "Record retrieved and restored from archive storage.";
        $icon = "trash-restore";
        $color = "info";
    } elseif ($actionType === 'PRINT_DOC') {
        $verb = "printed";
        $docName = $extract('document_name', '/document: (.*)/i') ?? $extract('file_name');
        
        $object = $docName ? "the document <span class=\"fw-bold text-dark\">" . htmlspecialchars($docName) . "</span>" : "a document";
        $module = "Document Management";
        $icon = "print";
        $color = "secondary";
    } elseif ($actionType === 'DOWNLOAD_DOC') {
        $verb = "downloaded";
        $docName = $extract('document_name', '/document: (.*)/i') ?? $extract('file_name');
        
        $object = $docName ? "the file <span class=\"fw-bold text-dark\">" . htmlspecialchars($docName) . "</span>" : "a document file";
        $module = "Document Management";
        $icon = "download";
        $color = "info";
    } elseif ($actionType === 'CREATE_QUOTATION') {
        $verb = "encoded";
        $qNum = $extract('quotation_number', '/Quotation (#\S+)/i');
        $cName = $extract('client_name', '/for client (.*?)\./i');
        
        $object = $qNum ? "Quotation <span class=\"fw-bold text-dark\">" . htmlspecialchars($qNum) . "</span>" : "a new Quotation";
        $details = $cName ? "Client designated as " . htmlspecialchars($cName) . "." : htmlspecialchars($description);
        
        $module = "Quotations";
        $icon = "file-signature";
        $color = "primary";
    } elseif ($actionType === 'RECEIVE_CLIENT_PO') {
        $verb = "received";
        $cpo = $extract('client_po_number', '/Client PO (#\S+)/i') ?? $extract('client_po_number', '/Ref: (CPO-\S+)/i');
        $qId = $extract('quotation_id', '/Quotation ID: (\d+)/i');
        
        $object = $cpo ? "Client PO <span class=\"fw-bold text-dark\">" . htmlspecialchars($cpo) . "</span>" : "a Client PO";
        if ($qId) $details = "Mapped to Quotation ID: " . htmlspecialchars($qId);
        
        $module = "Quotations";
        $icon = "handshake";
        $color = "success";
    } elseif ($actionType === 'VIEW_RECORD') {
        $verb = "accessed";
        $prId = $extract('pr_id', '/Purchase Request ID: (\d+)/i') ?? $extract('record_id');
        
        if ($prId) {
            $object = "Purchase Request PR-" . str_pad($prId, 4, '0', STR_PAD_LEFT);
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
        
        $docId = $extract('doc_id', '/Doc ID: (\d+)/i');
        $version = $extract('version', '/Uploaded (v\d+\.\d+)/i');
        
        if ($actionType === 'UPDATE_VERSION' && $docId) {
            $object = "a version update for Document ID " . htmlspecialchars($docId);
            $module = "Document Management";
            $details = $version ? "Updated to version: " . htmlspecialchars($version) : htmlspecialchars($description);
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

    $category = "System";
    if (in_array($color, ['success', 'warning']) && strpos($actionType, 'APPROVE') !== false) $category = "Approval";
    elseif ($color === 'primary' || strpos($actionType, 'CREATE') !== false || strpos($actionType, 'UPLOAD') !== false) $category = "Creation";
    elseif ($color === 'warning' || strpos($actionType, 'UPDATE') !== false || strpos($actionType, 'EDIT') !== false) $category = "Modification";
    elseif ($color === 'danger' || strpos($actionType, 'DELETE') !== false) $category = "Deletion";
    elseif (in_array($actionType, ['LOGIN', 'LOGOUT'])) $category = "Security";
    else $category = "System Access";

    $fullSentence = "<span class='fw-bold text-main'>$subject</span> <span class='text-muted'>$verb</span> $object.";

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

$excluded_sql = "'PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT'"; 
$query = "
    SELECT a.*, u.full_name, u.role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.user_id 
    WHERE a.action_type NOT IN ($excluded_sql) 
    ORDER BY a.`timestamp` DESC 
    LIMIT 3000";
$logs = $conn->query($query);

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
        
        $parsed = parseAuditRecord(
            $row['full_name'], 
            $row['action_type'], 
            $row['description'], 
            $row['new_payload'] ?? null, 
            $row['old_payload'] ?? null
        );
        
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
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body class="page-audit-logs">
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in">
        
        <header class="admin-page-header audit-page-header mb-4 pb-2 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="admin-page-title flex-grow-1">
                <h5 class="fw-bold mb-1 text-main tracking-tight"><i class="fas fa-shield-alt text-primary me-2"></i>System Audit Trail</h5>
                <p class="text-muted mb-0 fs-sm">Monitor enterprise activity, security events, and user actions.</p>
            </div>
            
            <button class="audit-export-action btn-modern btn-primary-modern" onclick="openExportModal()" aria-label="Export audit logs">
                <i class="fas fa-file-export" aria-hidden="true"></i><span class="audit-export-label">Export Configuration</span>
            </button>
        </header>

        <div class="row g-3 mb-4 audit-kpi-grid">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-blue">
                    <div class="kpi-corp-header">
                        <div><p class="kpi-corp-title">Tracked Events</p><h3 class="kpi-corp-value"><?= number_format($totalLogs) ?></h3></div>
                        <div class="kpi-corp-icon bg-soft-primary"><i class="fas fa-stream"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-rose">
                    <div class="kpi-corp-header">
                        <div><p class="kpi-corp-title">Critical Actions</p><h3 class="kpi-corp-value"><?= number_format($criticalCount) ?></h3></div>
                        <div class="kpi-corp-icon bg-soft-danger"><i class="fas fa-trash-alt"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-emerald">
                    <div class="kpi-corp-header">
                        <div><p class="kpi-corp-title">Active Accounts</p><h3 class="kpi-corp-value"><?= number_format($activeUsersCount) ?></h3></div>
                        <div class="kpi-corp-icon bg-soft-success"><i class="fas fa-users"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-corp-card accent-amber">
                    <div class="kpi-corp-header">
                        <div><p class="kpi-corp-title">Security Events</p><h3 class="kpi-corp-value"><?= number_format($authEvents) ?></h3></div>
                        <div class="kpi-corp-icon bg-soft-warning"><i class="fas fa-shield-alt"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="corp-widget p-0 overflow-hidden mb-4 d-flex flex-column bg-white audit-log-widget">
            
            <div class="p-3 border-bottom d-flex flex-wrap gap-3 align-items-end audit-filter-bar">
                <div class="min-w-180 audit-filter-module">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Module Focus</label>
                    <select class="form-select form-select-sm sleek-input" id="filterModule">
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
                <div class="min-w-160 audit-filter-category">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Action Category</label>
                    <select class="form-select form-select-sm sleek-input" id="filterCategory">
                        <option value="">All Categories</option>
                        <option value="Security">Security & Auth</option>
                        <option value="Creation">Creation</option>
                        <option value="Modification">Modification</option>
                        <option value="Approval">Approval</option>
                        <option value="Deletion">Deletion</option>
                    </select>
                </div>
                <div class="flex-grow-1 ms-auto max-w-350 audit-filter-search">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Search Records</label>
                    <div class="input-group input-group-sm sleek-input-group">
                        <span class="input-group-text bg-transparent text-muted border-end-0"><i class="fas fa-search fa-xs"></i></span>
                        <input type="text" id="searchTable" class="form-control sleek-input border-start-0 ps-0" placeholder="Search user, action...">
                    </div>
                </div>
            </div>

            <div class="table-responsive flex-grow-1 audit-table-wrap">
                <table id="auditTable" class="table table-corp align-middle mb-0 w-100 audit-responsive-table">
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
                        <tr class="timeline-row audit-clickable-row"
                            role="button"
                            tabindex="0"
                            aria-label="View audit details for <?= htmlspecialchars(strip_tags($p['sentence'])) ?>"
                            onclick="viewAuditDetails(this)"
                            onkeydown="if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); viewAuditDetails(this); }"
                            data-log-id="<?= htmlspecialchars($log['log_id']) ?>"
                            data-user="<?= htmlspecialchars($log['full_name'] ?: 'System Administrator') ?>"
                            data-role="<?= $safeRole ?>"
                            data-action="<?= htmlspecialchars($log['action_type']) ?>"
                            data-ip="<?= htmlspecialchars($log['ip_address']) ?>"
                            data-time="<?= htmlspecialchars($timeFormatted) ?>"
                            data-module="<?= htmlspecialchars($p['module']) ?>"
                            data-desc="<?= htmlspecialchars($log['description']) ?>"
                            data-sentence="<?= htmlspecialchars($p['sentence']) ?>">
                            <td class="ps-4 max-w-380 audit-primary-cell">
                                <div class="d-flex align-items-start py-1">
                                    <div class="icon-circle-sm bg-soft-<?= $p['color'] ?> flex-shrink-0 me-3 mt-1">
                                        <i class="fas fa-<?= $p['icon'] ?>"></i>
                                    </div>
                                    <div>
                                        <div class="timeline-desc"><?= $p['sentence'] ?></div>
                                        <?php if(!empty($p['details'])): ?>
                                            <div class="timeline-details"><i class="fas fa-level-up-alt fa-rotate-90 text-muted me-1 fs-xs"></i> <?= $p['details'] ?></div>
                                        <?php endif; ?>
                                        <div class="audit-mobile-meta d-md-none">
                                            <span class="audit-mobile-module"><?= htmlspecialchars($p['module']) ?></span>
                                            <span class="audit-mobile-category bg-soft-<?= $p['color'] ?>"><?= htmlspecialchars($p['category']) ?></span>
                                            <span class="audit-mobile-date"><?= date('M d, Y', strtotime($log['timestamp'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="text-muted fw-semibold fs-sm"><?= $p['module'] ?></span></td>
                            <td><span class="badge badge-soft bg-soft-<?= $p['color'] ?>"><?= $p['category'] ?></span></td>
                            <td data-order="<?= strtotime($log['timestamp']) ?>">
                                <div class="text-main fw-semibold fs-sm"><?= date('M d, Y', strtotime($log['timestamp'])) ?></div>
                                <div class="text-muted fs-xs"><?= date('h:i:s A', strtotime($log['timestamp'])) ?></div>
                            </td>
                            <td class="text-end pe-4 audit-action-cell">
                                <span class="btn-view-details" aria-hidden="true"><span class="audit-view-label">View</span><i class="audit-view-icon fas fa-chevron-right"></i></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="audit-pagination-bar p-3 border-top bg-light d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="text-muted fw-medium fs-sm" id="customPageInfo">Showing 0-0 of 0 records</div>
                <div class="audit-pagination-controls d-flex align-items-center flex-wrap gap-4">
                    <div class="audit-page-length d-flex align-items-center gap-2">
                        <span class="text-muted fw-medium fs-xs">Rows per page:</span>
                        <select id="customPageLength" class="form-select form-select-sm sleek-input py-1 w-auto">
                            <option value="15">15</option><option value="30">30</option><option value="50">50</option><option value="100">100</option>
                        </select>
                    </div>
                    <div class="audit-page-jump d-flex align-items-center gap-2 text-muted fw-medium fs-xs">
                        Page <input type="number" id="customPageInput" class="page-input-styled" min="1" value="1"> of <span id="customTotalPages">1</span>
                    </div>
                    <div class="audit-page-buttons btn-group shadow-sm">
                        <button class="btn-pagination" id="customPrevBtn" aria-label="Previous page"><i class="fas fa-chevron-left fs-xs"></i><span class="audit-pagination-label">Previous</span></button>
                        <button class="btn-pagination" id="customNextBtn" aria-label="Next page"><span class="audit-pagination-label">Next</span><i class="fas fa-chevron-right fs-xs"></i></button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade sleek-modal audit-details-modal" id="auditDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-main fs-md"><i class="fas fa-microchip text-primary me-2"></i> Log Details</h5>
                    <button type="button" class="btn-close fs-xs" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-4">
                        <div class="p-3 bg-white border rounded shadow-sm d-flex align-items-start border-light">
                            <div id="techHumanReadable" class="fs-md text-slate-800 tracking-wide"></div>
                        </div>
                    </div>
                    
                    <div id="techChangesSection"></div>
                    
                    <div class="mb-4">
                        <label class="text-uppercase fw-bold mb-2 fs-xs text-slate-500 tracking-wide">Technical Metadata</label>
                        <div class="bg-white border rounded shadow-sm p-3 border-light">
                            <div class="row gy-3">
                                <div class="col-md-6"><div class="fs-xs text-slate-500">Record ID</div><div class="fw-semibold fs-sm text-main" id="techLogId"></div></div>
                                <div class="col-md-6"><div class="fs-xs text-slate-500">User Identity</div><div class="fw-semibold text-primary fs-sm" id="techUser"></div></div>
                                <div class="col-md-6"><div class="fs-xs text-slate-500">Action Code</div><div><span class="badge bg-soft-secondary font-monospace fs-xs" id="techAction"></span></div></div>
                                <div class="col-md-6"><div class="fs-xs text-slate-500">Client IP</div><div class="font-monospace fs-sm text-slate-500" id="techIp"></div></div>
                                <div class="col-md-6"><div class="fs-xs text-slate-500">Module Affected</div><div class="fw-semibold fs-sm text-main" id="techModule"></div></div>
                                <div class="col-md-6"><div class="fs-xs text-slate-500">System Timestamp</div><div class="fw-semibold fs-sm text-main" id="techTime"></div></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="text-uppercase fw-bold mb-2 fs-xs text-slate-500 tracking-wide">Raw Payload</label>
                        <div class="p-3 font-monospace rounded shadow-sm fs-xs text-success bg-dark text-break">
                            > <span id="techDesc"></span><span class="cursor-blink">_</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade sleek-modal audit-export-modal" id="exportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-main fs-md"><i class="fas fa-file-export text-primary me-2"></i> Export Audit Logs</h5>
                    <button type="button" class="btn-close fs-xs" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 text-muted fs-sm">Pagination controls only affect your screen display. Select the full data scope you want to include in your Excel (.xlsx) report below.</div>
                    
                    <div class="list-group list-group-flush border rounded">
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer bg-soft-primary border-start border-primary border-3" id="lblExportFiltered">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="filtered" checked>
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-bold text-main">Export Filtered Results</h6><span class="badge bg-primary rounded-pill" id="exportFilteredCount">0</span></div>
                                <small class="text-muted fs-xs">Includes all records matching current search and dropdown filters.</small>
                            </div>
                        </label>
                        
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportCurrent">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="current">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-semibold text-main">Export Current Page</h6><span class="badge bg-secondary rounded-pill" id="exportCurrentCount">0</span></div>
                                <small class="text-muted fs-xs">Includes only the visible rows strictly on this specific page.</small>
                            </div>
                        </label>

                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportAll">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="all">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-semibold text-danger">Export All Audit Logs</h6><span class="badge bg-danger rounded-pill" id="exportAllCount">0</span></div>
                                <small class="text-muted fs-xs">Exports the complete system audit history available.</small>
                            </div>
                        </label>
                    </div>
                    <div class="alert alert-warning mt-3 mb-0 d-none fs-xs" id="exportWarning">
                        <i class="fas fa-exclamation-triangle me-1"></i> <strong>Heavy Process:</strong> You are exporting a large dataset. The browser may take a few moments to compile the Excel file. Please do not close the window.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light border text-muted fw-semibold fs-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary sleek-btn-sm px-4" id="btnConfirmExport" onclick="processDataExport()">
                        <i class="fas fa-download"></i> Generate Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        $(document).ready(function() {
            var table = $('#auditTable').DataTable({
                "order": [[ 3, "desc" ]], 
                "dom": '<"d-none"f>rt', 
                "pageLength": 15,
                "language": {
                    "emptyTable": "<div class='text-center p-5 text-muted'><i class='fas fa-inbox fa-3x mb-3 opacity-50'></i><br><h5>No records found</h5><p class='mb-0 fs-sm'>No audit records found for the selected filters.</p></div>"
                }
            });

            function updateCustomPagination() {
                let info = table.page.info();
                let startRange = info.recordsDisplay > 0 ? (info.start + 1) : 0;
                let endRange = info.end;
                let totalFilteredStr = Number(info.recordsDisplay).toLocaleString();
                let filterStatus = (info.recordsDisplay !== info.recordsTotal) ? ' filtered' : '';
                
                $('#customPageInfo').html(`Showing <span class="fw-bold text-main">${startRange}-${endRange}</span> of <span class="fw-bold text-main">${totalFilteredStr}</span>${filterStatus} activities`);
                
                let totalPages = info.pages === 0 ? 1 : info.pages;
                let currentPage = info.page + 1;
                
                $('#customTotalPages').text(totalPages);
                $('#customPageInput').val(currentPage);
                
                $('#customPrevBtn').prop('disabled', info.page === 0);
                $('#customNextBtn').prop('disabled', info.page === (info.pages - 1) || info.pages === 0);
                $('#customPageInput').prop('max', totalPages);
            }

            table.on('draw', function() { updateCustomPagination(); });
            updateCustomPagination();

            $('#customPrevBtn').on('click', function() { table.page('previous').draw('page'); });
            $('#customNextBtn').on('click', function() { table.page('next').draw('page'); });
            
            $('#customPageLength').on('change', function() { table.page.len(parseInt($(this).val())).draw(); });
            $('#customPageInput').on('change', function() {
                let requestedPage = parseInt($(this).val());
                let info = table.page.info();
                if (requestedPage >= 1 && requestedPage <= info.pages) { table.page(requestedPage - 1).draw('page'); } 
                else { $(this).val(info.page + 1); }
            });

            $('#searchTable').on('keyup', function() { table.search(this.value).draw(); });
            $('#filterModule').on('change', function() { table.column(1).search(this.value).draw(); });
            $('#filterCategory').on('change', function() { table.column(2).search(this.value).draw(); });

            $('input[name="exportOption"]').on('change', function() {
                $('.list-group-item').removeClass('bg-soft-primary').removeClass('border-start').removeClass('border-primary').removeClass('border-3');
                $(this).closest('.list-group-item').addClass('bg-soft-primary border-start border-primary border-3');
                
                let selectedVal = $(this).val();
                let countNum = 0;
                if(selectedVal === 'filtered') countNum = parseInt($('#exportFilteredCount').text());
                else if(selectedVal === 'current') countNum = parseInt($('#exportCurrentCount').text());
                else countNum = parseInt($('#exportAllCount').text());

                if (countNum > 1500) { $('#exportWarning').removeClass('d-none'); } else { $('#exportWarning').addClass('d-none'); }
            });
        });

        function viewAuditDetails(btn) {
            const d = btn.dataset;
            $('#techLogId').text(d.logId); $('#techUser').text(d.user); $('#techAction').text(d.action); $('#techIp').text(d.ip); $('#techTime').text(d.time); $('#techModule').text(d.module); $('#techDesc').text(d.desc); $('#techHumanReadable').html(d.sentence);
            
            let techChangesDiv = document.getElementById('techChangesSection');
            techChangesDiv.innerHTML = ''; 
            let match = String(d.desc).match(/changed from (.*?) to (.*)/i);
            
            if (match) {
                techChangesDiv.innerHTML = `
                    <div class="row mb-4 gx-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-white h-100 shadow-sm border-light border-start border-danger border-3">
                                <div class="fw-bold mb-1 fs-xs text-danger text-uppercase">PREVIOUS VALUE</div>
                                <div class="fw-semibold mt-2 fs-md text-main">${match[1]}</div>
                            </div>
                        </div>
                        <div class="col-sm-6 mt-2 mt-sm-0">
                            <div class="border rounded p-3 bg-white h-100 shadow-sm border-light border-start border-success border-3">
                                <div class="fw-bold mb-1 fs-xs text-success text-uppercase">UPDATED VALUE</div>
                                <div class="fw-semibold mt-2 fs-md text-main">${match[2]}</div>
                            </div>
                        </div>
                    </div>
                `;
            }
            new bootstrap.Modal(document.getElementById('auditDetailsModal')).show();
        }

        function openExportModal() {
            let tableAPI = $('#auditTable').DataTable();
            $('#exportFilteredCount').text(tableAPI.rows({ search: 'applied' }).count());
            $('#exportCurrentCount').text(tableAPI.rows({ page: 'current' }).count());
            $('#exportAllCount').text(tableAPI.rows().count());
            $('input[name="exportOption"]:checked').trigger('change');
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }

        function processDataExport() {
            let btn = $('#btnConfirmExport'); let originalContent = btn.html();
            btn.html('<i class="fas fa-spinner fa-spin me-2"></i> Processing...').prop('disabled', true);
            
            let exportType = $('input[name="exportOption"]:checked').val();
            let tableAPI = $('#auditTable').DataTable();
            let selector = {}; let typeName = "";
            
            if (exportType === 'current') { selector = { page: 'current' }; typeName = "Current Visible Page"; } 
            else if (exportType === 'filtered') { selector = { search: 'applied' }; typeName = "Filtered Results"; } 
            else { selector = {}; typeName = "All Audit Logs"; }

            let rows = tableAPI.rows(selector).nodes();
            let recordCount = rows.length;
            if (recordCount === 0) { alert("No records found matching your criteria to export."); btn.html(originalContent).prop('disabled', false); return; }

            setTimeout(() => {
                let exportData = [];
                $(rows).each(function() {
                    let viewBtn = $(this).find('.btn-view-details');
                    let rawSentence = viewBtn.data('sentence');
                    exportData.push({
                        "Log ID": viewBtn.data('log-id'), "Date & Time": viewBtn.data('time'), "User Identity": viewBtn.data('user'),
                        "User Role": viewBtn.data('role'), "Action Type": viewBtn.data('action'), "Module Focus": viewBtn.data('module'),
                        "Activity Description": rawSentence ? rawSentence.replace(/<[^>]+>/g, '') : '', "Technical Context": viewBtn.data('desc'), "Client IP Address": viewBtn.data('ip')
                    });
                });
                try {
                    let worksheet = XLSX.utils.json_to_sheet(exportData); let workbook = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(workbook, worksheet, "System Audit Export");
                    worksheet['!cols'] = [{wch: 10}, {wch: 22}, {wch: 22}, {wch: 15}, {wch: 20}, {wch: 20}, {wch: 65}, {wch: 45}, {wch: 16}];
                    XLSX.writeFile(workbook, "System_Audit_Logs_" + new Date().toISOString().slice(0,10) + ".xlsx");
                    
                    $.post(window.location.href, { log_export_activity: 1, record_count: recordCount, export_type: typeName }).always(function() {
                        btn.html(originalContent).prop('disabled', false); bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
                    });
                } catch (err) {
                    console.error("Export Error:", err); alert("An error occurred during export processing. Check console for details.");
                    btn.html(originalContent).prop('disabled', false);
                }
            }, 50);
        }
    </script>
</body>
</html>
