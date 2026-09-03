<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require 'config/audit_query.php';
require_once __DIR__ . '/config/frontend_assets.php';

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
            $object = "Purchase Request PR-" . htmlspecialchars(str_pad((string) $prId, 4, '0', STR_PAD_LEFT));
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
        $object = htmlspecialchars(str_replace('_', ' ', strtolower($actionType))) . " record";
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
        $object = htmlspecialchars(str_replace('_', ' ', strtolower($actionType)));
        $icon = "trash-alt";
        $color = "danger";
        $module = "System Operations";
        $details = htmlspecialchars($description);
    } else {
        $verb = "executed";
        $object = htmlspecialchars(str_replace('_', ' ', strtolower($actionType))) . " action";
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

$filterState = drms_audit_normalize_filters($_GET);
$perPage = drms_audit_page_length($_GET['per_page'] ?? 15);
$requestedPage = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$requestedPage = $requestedPage === false ? 1 : (int) $requestedPage;

$baseWhere = drms_audit_base_where_sql('a');
$moduleCase = drms_audit_module_case_sql('a');
$categoryCase = drms_audit_category_case_sql('a');

$summarySql = "SELECT
        COUNT(*) AS tracked_events,
        COALESCE(SUM(({$categoryCase}) = 'Deletion'), 0) AS critical_actions,
        COUNT(DISTINCT a.user_id) AS active_accounts,
        COALESCE(SUM(({$categoryCase}) = 'Security'), 0) AS security_events
    FROM audit_logs a
    WHERE {$baseWhere}";
$summaryResult = $conn->query($summarySql);
$summary = $summaryResult ? $summaryResult->fetch_assoc() : [];

$totalLogs = (int) ($summary['tracked_events'] ?? 0);
$criticalCount = (int) ($summary['critical_actions'] ?? 0);
$activeUsersCount = (int) ($summary['active_accounts'] ?? 0);
$authEvents = (int) ($summary['security_events'] ?? 0);

$where = drms_audit_build_where($filterState);
$filteredCount = drms_audit_scalar(
    $conn,
    "SELECT COUNT(*)
     FROM audit_logs a
     LEFT JOIN users u ON u.user_id = a.user_id
     WHERE {$where['sql']}",
    $where['types'],
    $where['params']
);

$totalPages = max(1, (int) ceil($filteredCount / $perPage));
$currentPage = min($requestedPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$query = "SELECT
        a.*,
        u.full_name,
        u.role,
        {$moduleCase} AS audit_module,
        {$categoryCase} AS audit_category
    FROM audit_logs a
    LEFT JOIN users u ON u.user_id = a.user_id
    WHERE {$where['sql']}
    ORDER BY a.`timestamp` DESC, a.log_id DESC
    LIMIT ? OFFSET ?";
$queryTypes = $where['types'] . 'ii';
$queryParams = $where['params'];
$queryParams[] = $perPage;
$queryParams[] = $offset;

$statement = $conn->prepare($query);
drms_audit_bind_params($statement, $queryTypes, $queryParams);
$statement->execute();
$logs = $statement->get_result();

$parsedLogs = [];
$categoryColors = [
    'Security' => 'success',
    'Creation' => 'primary',
    'Modification' => 'warning',
    'Approval' => 'success',
    'Deletion' => 'danger',
    'System Access' => 'secondary',
];
while ($row = $logs->fetch_assoc()) {
    $parsed = parseAuditRecord(
        $row['full_name'],
        $row['action_type'],
        $row['description'],
        $row['new_payload'] ?? null,
        $row['old_payload'] ?? null
    );
    $parsed['module'] = (string) $row['audit_module'];
    $parsed['category'] = (string) $row['audit_category'];
    $parsed['color'] = $categoryColors[$parsed['category']] ?? 'secondary';
    $row['parsed'] = $parsed;
    $parsedLogs[] = $row;
}
$statement->close();

$firstRecord = $filteredCount > 0 ? $offset + 1 : 0;
$lastRecord = $filteredCount > 0
    ? min($offset + count($parsedLogs), $filteredCount)
    : 0;
$hasActiveFilters = $filterState['search'] !== '' ||
    $filterState['module'] !== '' ||
    $filterState['category'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audit Trail - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <?= drms_frontend_script_tags(['jquery', 'bootstrap', 'xlsx']) ?>
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
            
            <form method="GET" id="auditFilterForm" class="p-3 border-bottom d-flex flex-wrap gap-3 align-items-end audit-filter-bar">
                <input type="hidden" name="page" id="auditPageField" value="<?= (int) $currentPage ?>">
                <input type="hidden" name="per_page" id="auditPerPageField" value="<?= (int) $perPage ?>">
                <div class="min-w-180 audit-filter-module">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Module Focus</label>
                    <select class="form-select form-select-sm sleek-input" id="filterModule" name="module">
                        <option value="">All Modules</option>
                        <option value="Authentication" <?= $filterState['module'] === 'Authentication' ? 'selected' : '' ?>>Authentication</option>
                        <option value="Document Management" <?= $filterState['module'] === 'Document Management' ? 'selected' : '' ?>>Document Management</option>
                        <option value="Purchase Orders" <?= $filterState['module'] === 'Purchase Orders' ? 'selected' : '' ?>>Purchase Orders</option>
                        <option value="Purchase Requests" <?= $filterState['module'] === 'Purchase Requests' ? 'selected' : '' ?>>Purchase Requests</option>
                        <option value="Quotations" <?= $filterState['module'] === 'Quotations' ? 'selected' : '' ?>>Quotations Tracker</option>
                        <option value="Finance" <?= $filterState['module'] === 'Finance' ? 'selected' : '' ?>>Finance</option>
                        <option value="System Operations" <?= $filterState['module'] === 'System Operations' ? 'selected' : '' ?>>System Operations</option>
                    </select>
                </div>
                <div class="min-w-160 audit-filter-category">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Action Category</label>
                    <select class="form-select form-select-sm sleek-input" id="filterCategory" name="category">
                        <option value="">All Categories</option>
                        <option value="Security" <?= $filterState['category'] === 'Security' ? 'selected' : '' ?>>Security & Auth</option>
                        <option value="Creation" <?= $filterState['category'] === 'Creation' ? 'selected' : '' ?>>Creation</option>
                        <option value="Modification" <?= $filterState['category'] === 'Modification' ? 'selected' : '' ?>>Modification</option>
                        <option value="Approval" <?= $filterState['category'] === 'Approval' ? 'selected' : '' ?>>Approval</option>
                        <option value="Deletion" <?= $filterState['category'] === 'Deletion' ? 'selected' : '' ?>>Deletion</option>
                    </select>
                </div>
                <div class="flex-grow-1 ms-auto max-w-350 audit-filter-search">
                    <label class="form-label fw-semibold text-muted mb-1 fs-xs text-uppercase">Search Records</label>
                    <div class="input-group input-group-sm sleek-input-group">
                        <span class="input-group-text bg-transparent text-muted border-end-0"><i class="fas fa-search fa-xs"></i></span>
                        <input type="search" id="searchTable" name="search" value="<?= htmlspecialchars($filterState['search']) ?>" class="form-control sleek-input border-start-0 ps-0" placeholder="Search user, action..." maxlength="100" autocomplete="off">
                        <button type="submit" class="btn btn-outline-primary px-3" aria-label="Search audit records"><i class="fas fa-arrow-right fa-xs" aria-hidden="true"></i></button>
                    </div>
                </div>
            </form>

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
                        <?php if (!$parsedLogs): ?>
                            <tr>
                                <td colspan="5" class="text-center p-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
                                    <h5>No records found</h5>
                                    <p class="mb-0 fs-sm">No audit records match the selected filters.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="audit-pagination-bar p-3 border-top bg-light d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="text-muted fw-medium fs-sm" id="customPageInfo">
                    Showing <span class="fw-bold text-main"><?= number_format($firstRecord) ?>-<?= number_format($lastRecord) ?></span>
                    of <span class="fw-bold text-main"><?= number_format($filteredCount) ?></span>
                    <?= $hasActiveFilters ? 'filtered ' : '' ?>activities
                </div>
                <div class="audit-pagination-controls d-flex align-items-center flex-wrap gap-4">
                    <div class="audit-page-length d-flex align-items-center gap-2">
                        <span class="text-muted fw-medium fs-xs">Rows per page:</span>
                        <select id="customPageLength" class="form-select form-select-sm sleek-input py-1 w-auto">
                            <?php foreach (drms_audit_allowed_page_lengths() as $length): ?>
                                <option value="<?= $length ?>" <?= $perPage === $length ? 'selected' : '' ?>><?= $length ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-page-jump d-flex align-items-center gap-2 text-muted fw-medium fs-xs">
                        Page <input type="number" id="customPageInput" class="page-input-styled" min="1" max="<?= $totalPages ?>" value="<?= $currentPage ?>"> of <span id="customTotalPages"><?= number_format($totalPages) ?></span>
                    </div>
                    <div class="audit-page-buttons btn-group shadow-sm">
                        <button type="button" class="btn-pagination" id="customPrevBtn" data-page="<?= max(1, $currentPage - 1) ?>" aria-label="Previous page" <?= $currentPage <= 1 ? 'disabled' : '' ?>><i class="fas fa-chevron-left fs-xs"></i><span class="audit-pagination-label">Previous</span></button>
                        <button type="button" class="btn-pagination" id="customNextBtn" data-page="<?= min($totalPages, $currentPage + 1) ?>" aria-label="Next page" <?= $currentPage >= $totalPages ? 'disabled' : '' ?>><span class="audit-pagination-label">Next</span><i class="fas fa-chevron-right fs-xs"></i></button>
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
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-bold text-main">Export Filtered Results</h6><span class="badge bg-primary rounded-pill" id="exportFilteredCount" data-count="<?= $filteredCount ?>"><?= number_format($filteredCount) ?></span></div>
                                <small class="text-muted fs-xs">Includes all records matching current search and dropdown filters.</small>
                            </div>
                        </label>
                        
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportCurrent">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="current">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-semibold text-main">Export Current Page</h6><span class="badge bg-secondary rounded-pill" id="exportCurrentCount" data-count="<?= count($parsedLogs) ?>"><?= number_format(count($parsedLogs)) ?></span></div>
                                <small class="text-muted fs-xs">Includes only the visible rows strictly on this specific page.</small>
                            </div>
                        </label>

                        <label class="list-group-item d-flex gap-3 align-items-center p-3 cursor-pointer" id="lblExportAll">
                            <input class="form-check-input flex-shrink-0" type="radio" name="exportOption" value="all">
                            <div class="w-100">
                                <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0 fw-semibold text-danger">Export All Audit Logs</h6><span class="badge bg-danger rounded-pill" id="exportAllCount" data-count="<?= $totalLogs ?>"><?= number_format($totalLogs) ?></span></div>
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
            const filterForm = document.getElementById('auditFilterForm');
            const pageField = document.getElementById('auditPageField');
            const perPageField = document.getElementById('auditPerPageField');
            const totalPages = <?= (int) $totalPages ?>;

            function submitFilters(pageNumber) {
                pageField.value = Math.min(Math.max(parseInt(pageNumber, 10) || 1, 1), totalPages);
                filterForm.submit();
            }

            $(filterForm).on('submit', function() {
                pageField.value = 1;
            });

            $('#filterModule, #filterCategory').on('change', function() {
                submitFilters(1);
            });

            $('#searchTable').on('search', function() {
                if (this.value === '') {
                    submitFilters(1);
                }
            });

            $('#customPageLength').on('change', function() {
                perPageField.value = parseInt(this.value, 10) || 15;
                submitFilters(1);
            });

            $('#customPageInput').on('change', function() {
                const requestedPage = parseInt(this.value, 10);
                if (requestedPage >= 1 && requestedPage <= totalPages) {
                    submitFilters(requestedPage);
                } else {
                    this.value = <?= (int) $currentPage ?>;
                }
            });

            $('#customPrevBtn, #customNextBtn').on('click', function() {
                if (!this.disabled) {
                    submitFilters(this.dataset.page);
                }
            });

            $('input[name="exportOption"]').on('change', function() {
                $('.list-group-item').removeClass('bg-soft-primary').removeClass('border-start').removeClass('border-primary').removeClass('border-3');
                $(this).closest('.list-group-item').addClass('bg-soft-primary border-start border-primary border-3');
                
                let selectedVal = $(this).val();
                let countNum = 0;
                if(selectedVal === 'filtered') countNum = Number($('#exportFilteredCount').data('count'));
                else if(selectedVal === 'current') countNum = Number($('#exportCurrentCount').data('count'));
                else countNum = Number($('#exportAllCount').data('count'));

                if (countNum > 1500) { $('#exportWarning').removeClass('d-none'); } else { $('#exportWarning').addClass('d-none'); }
            });
        });

        function viewAuditDetails(btn) {
            const d = btn.dataset;
            $('#techLogId').text(d.logId); $('#techUser').text(d.user); $('#techAction').text(d.action); $('#techIp').text(d.ip); $('#techTime').text(d.time); $('#techModule').text(d.module); $('#techDesc').text(d.desc || '');

            // Reuse the server-escaped timeline markup without parsing a data
            // attribute as HTML. This preserves its existing bold/muted spans.
            const summary = document.getElementById('techHumanReadable');
            const timeline = btn.querySelector('.timeline-desc');
            summary.replaceChildren();
            if (timeline) {
                timeline.childNodes.forEach(node => summary.appendChild(node.cloneNode(true)));
            } else {
                summary.textContent = d.desc || '';
            }

            const techChangesDiv = document.getElementById('techChangesSection');
            techChangesDiv.replaceChildren();
            const match = String(d.desc || '').match(/changed from (.*?) to (.*)/i);

            if (match) {
                const row = document.createElement('div');
                row.className = 'row mb-4 gx-3';
                const changes = [
                    { label: 'PREVIOUS VALUE', value: match[1], color: 'danger', column: 'col-sm-6' },
                    { label: 'UPDATED VALUE', value: match[2], color: 'success', column: 'col-sm-6 mt-2 mt-sm-0' }
                ];
                changes.forEach(change => {
                    const column = document.createElement('div');
                    column.className = change.column;
                    const card = document.createElement('div');
                    card.className = 'border rounded p-3 bg-white h-100 shadow-sm border-light border-start border-' + change.color + ' border-3';
                    const label = document.createElement('div');
                    label.className = 'fw-bold mb-1 fs-xs text-' + change.color + ' text-uppercase';
                    label.textContent = change.label;
                    const value = document.createElement('div');
                    value.className = 'fw-semibold mt-2 fs-md text-main';
                    // Audit values are data, never markup (including old logs).
                    value.textContent = change.value;
                    card.appendChild(label);
                    card.appendChild(value);
                    column.appendChild(card);
                    row.appendChild(column);
                });
                techChangesDiv.appendChild(row);
            }
            new bootstrap.Modal(document.getElementById('auditDetailsModal')).show();
        }

        function openExportModal() {
            $('input[name="exportOption"]:checked').trigger('change');
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }

        function processDataExport() {
            const btn = $('#btnConfirmExport');
            const originalContent = btn.html();
            btn.html('<i class="fas fa-spinner fa-spin me-2"></i> Processing...').prop('disabled', true);

            const payload = new URLSearchParams({
                csrf_token: <?php echo json_encode((string) ($_SESSION['csrf_token'] ?? '')); ?>,
                scope: String($('input[name="exportOption"]:checked').val() || 'filtered'),
                search: <?php echo json_encode($filterState['search']); ?>,
                module: <?php echo json_encode($filterState['module']); ?>,
                category: <?php echo json_encode($filterState['category']); ?>,
                page: '<?php echo (int) $currentPage; ?>',
                per_page: '<?php echo (int) $perPage; ?>'
            });

            fetch('api/audit_logs_export.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: payload.toString()
            })
            .then(async response => {
                const data = await response.json();
                if (!response.ok || data.status !== 'success') {
                    throw new Error(data.message || 'The export request failed.');
                }
                return data;
            })
            .then(data => {
                if (!Array.isArray(data.records) || data.records.length === 0) {
                    alert('No records were found for the selected export scope.');
                    return;
                }

                const worksheet = XLSX.utils.json_to_sheet(data.records);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'System Audit Export');
                worksheet['!cols'] = [
                    {wch: 10}, {wch: 22}, {wch: 22}, {wch: 15}, {wch: 24},
                    {wch: 22}, {wch: 18}, {wch: 70}, {wch: 18}
                ];
                XLSX.writeFile(
                    workbook,
                    'System_Audit_Logs_' + new Date().toISOString().slice(0, 10) + '.xlsx'
                );

                const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
                if (modal) {
                    modal.hide();
                }
            })
            .catch(error => {
                console.error('Audit export error:', error);
                alert(error.message || 'The audit export could not be generated.');
            })
            .finally(() => {
                btn.html(originalContent).prop('disabled', false);
            });
        }
    </script>
</body>
</html>
