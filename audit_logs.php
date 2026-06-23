<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] !== 'Admin' && !has_permission($conn, $_SESSION['user_id'], 'can_view_audit_logs')) {
    header("Location: dashboard.php");
    exit();
}

function audit_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$user_map = [];
$user_map_query = $conn->query("SELECT user_id, full_name FROM users");
if ($user_map_query) {
    while ($u = $user_map_query->fetch_assoc()) {
        $user_map[$u['user_id']] = $u['full_name'];
    }
}

function formatNaturalAction($action) {
    if (empty($action)) return 'Unknown';
    if ($action === 'DOWNLOAD_DOC') return 'Accessed File';
    if ($action === 'DOWNLOAD_ATTEMPT') return 'Access Attempt';
    
    $action = str_replace(['_', '-'], ' ', $action);
    $words = explode(' ', strtolower($action));
    $formatted = [];
    foreach ($words as $word) {
        if ($word === 'po') $formatted[] = 'Purchase Order';
        elseif ($word === 'pr') $formatted[] = 'Purchase Request';
        else $formatted[] = ucfirst($word);
    }
    return trim(implode(' ', $formatted));
}

// Format shorter natural sentences for the main grid
function formatNaturalSentence($desc, $actionRaw = '', $user_map = []) {
    if (empty($desc)) return 'System action executed.';
    
    // Alisin ang sobrang habang text para compact
    $desc = explode('| Details:', $desc)[0];
    
    $desc = str_ireplace(
        ['Submitted mark read in Notif Handler', 'Submitted bulk pin in Notif Handler', 'Login attempt for username=', 'Opened or downloaded a document'],
        ['Marked notification as read', 'Pinned multiple notifications', 'Failed login (User: ', 'Accessed document'],
        $desc
    );

    $desc = preg_replace('/\s+/', ' ', $desc);
    
    if(strlen($desc) > 55) {
        $desc = substr($desc, 0, 52) . '...';
    }
    
    return trim($desc);
}

function audit_action_group($action) {
    $action = strtoupper((string)$action);
    if (strpos($action, 'LOGIN') !== false || strpos($action, 'LOGOUT') !== false || strpos($action, 'AUTH') !== false) return 'Access';
    if (strpos($action, 'ATTEMPT') !== false || strpos($action, 'FAIL') !== false || strpos($action, 'ERROR') !== false || strpos($action, 'DENIED') !== false) return 'Security';
    if (strpos($action, 'CREATE') !== false || strpos($action, 'UPLOAD') !== false || strpos($action, 'RECEIVE') !== false || strpos($action, 'SUBMIT') !== false) return 'Create';
    if (strpos($action, 'UPDATE') !== false || strpos($action, 'EDIT') !== false || strpos($action, 'CHANGE') !== false || strpos($action, 'APPROVE') !== false || strpos($action, 'REJECT') !== false || strpos($action, 'MANAGE_REQUEST') !== false) return 'Update';
    if (strpos($action, 'DELETE') !== false || strpos($action, 'DESTROY') !== false) return 'Delete';
    if (strpos($action, 'ARCHIVE') !== false || strpos($action, 'RESTORE') !== false) return 'Archive';
    if (strpos($action, 'PRINT') !== false || strpos($action, 'DOWNLOAD') !== false || strpos($action, 'VIEW') !== false) return 'View'; 
    return 'System';
}

function audit_badge_style($group) {
    $styles = [
        'Access' => 'badge bg-info bg-opacity-10 text-info border border-info',
        'Security' => 'badge bg-danger text-white',
        'Create' => 'badge bg-success bg-opacity-10 text-success border border-success',
        'Update' => 'badge bg-warning bg-opacity-10 text-dark border border-warning',
        'Delete' => 'badge bg-danger bg-opacity-10 text-danger border border-danger',
        'Archive' => 'badge bg-secondary bg-opacity-10 text-secondary border border-secondary',
        'View' => 'badge bg-light text-dark border',
        'System' => 'badge bg-light text-muted border'
    ];
    return $styles[$group] ?? $styles['System'];
}

$excluded_sql = "'PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT'";

// Updated query to fetch JSON columns
$query = "
    SELECT a.*, u.full_name, u.role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.user_id 
    WHERE a.action_type NOT IN ($excluded_sql) 
    ORDER BY a.`timestamp` DESC 
    LIMIT 1500";
    
$logs = $conn->query($query);
$generatedBy = $_SESSION['full_name'] ?? ($_SESSION['fullname'] ?? 'System Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audit Trail - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        body, .main-content {
            background-color: #f4f7fb !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .table-container {
            max-height: calc(100vh - 165px);
            overflow-y: auto;
        }

        .modern-table > :not(caption) > * > * {
            padding: 0.85rem 1.2rem;
            border-bottom-color: #f1f5f9;
        }

        .modern-table thead th {
            position: sticky;
            top: 0;
            background-color: #f8fafc;
            color: #64748b;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            border-bottom: 2px solid #e2e8f0;
            z-index: 10;
        }

        .modern-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .modern-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .user-cell { display: flex; align-items: center; gap: 0.75rem; }
        .avatar-circle {
            width: 36px; height: 36px; border-radius: 50%; background-color: #f1f5f9;
            color: #475569; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center;
            justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;
        }

        .table-container::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .table-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .table-container::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .btn-excel { background-color: #107c41; color: white; transition: all 0.2s; border: none; }
        .btn-excel:hover { background-color: #0c5c30; color: white; }

        /* JSON View Button */
        .btn-json-view {
            background: #f8fafc; color: #3b82f6; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 0.35rem 0.65rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.3px; transition: 0.2s; white-space: nowrap;
        }
        .btn-json-view:hover { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }

        /* JSON Modal Styling */
        .json-modal .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .json-block {
            background: #0f172a; color: #38bdf8; padding: 1.25rem; border-radius: 12px;
            font-family: 'Consolas', 'Courier New', monospace; font-size: 0.8rem;
            max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word;
        }
        .json-block::-webkit-scrollbar { width: 6px; }
        .json-block::-webkit-scrollbar-track { background: #1e293b; border-radius: 4px; }
        .json-block::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }

        @media print {
            body, .main-content { background: #fff !important; }
            .saas-navbar, .no-print, .btn-json-view { display: none !important; }
            .main-content { padding: 0 !important; max-width: none !important; }
            .card { border: 0 !important; box-shadow: none !important; }
            .table-container { max-height: none !important; overflow: visible !important; }
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    
    <div class="d-none d-print-block mb-4 border-bottom pb-2">
        <h3 class="fw-bold mb-0">Enterprise Security & Audit Log</h3>
        <p class="text-muted small mb-0">Fixie Computer Ventures | Generated: <?php echo date('Y-m-d H:i:s'); ?> by <?php echo audit_h($generatedBy); ?></p>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 no-print gap-3">
        <div>
            <h4 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">Audit Trail</h4>
        </div>
        
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="position-relative">
                <input type="search" id="auditSearch" class="form-control form-control-sm pe-4 shadow-none" style="width: 280px; border-radius: 6px; border-color: #cbd5e1;" placeholder="Search logs...">
                <i class="fas fa-search position-absolute text-muted" style="top: 50%; right: 12px; transform: translateY(-50%); font-size: 0.8rem; pointer-events: none;"></i>
            </div>
            
            <select id="actionFilter" class="form-select form-select-sm shadow-none" style="width: 150px; border-radius: 6px; border-color: #cbd5e1; cursor: pointer;">
                <option value="">All Categories</option>
                <option value="Access">Auth & Access</option>
                <option value="Security">Security Risks</option>
                <option value="Create">Creation</option>
                <option value="Update">Modification</option>
                <option value="Delete">Deletion</option>
                <option value="View">Document Loads</option>
            </select>

            <select id="rowLimit" class="form-select form-select-sm shadow-none" style="width: 100px; border-radius: 6px; border-color: #cbd5e1; cursor: pointer;">
                <option value="50">Show 50</option>
                <option value="100">Show 100</option>
                <option value="500">Show 500</option>
                <option value="1500">Show All</option>
            </select>

            <button type="button" id="clearFilters" class="btn btn-sm btn-light border text-muted shadow-none" title="Reset Filters" style="border-radius: 6px;">
                <i class="fas fa-undo-alt"></i>
            </button>
            
            <button type="button" id="exportExcelBtn" class="btn btn-sm btn-excel shadow-sm fw-medium px-3" style="border-radius: 6px;" title="Download as Styled Excel File">
                <i class="fas fa-file-excel me-1"></i> Excel
            </button>

            <button type="button" class="btn btn-sm btn-primary text-white shadow-sm fw-medium px-3" style="border-radius: 6px;" onclick="window.print()" title="Print Log">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 8px; overflow: hidden; background: #fff;">
        <div class="table-container">
            <table class="table modern-table align-middle mb-0" id="auditTable">
                <thead>
                    <tr>
                        <th width="15%">Date & Time</th>
                        <th width="25%">Actor Profile</th>
                        <th width="15%">Event Type</th>
                        <th width="33%">Context / Description</th>
                        <th width="12%" class="text-end">IP & Payload</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($row = $logs->fetch_assoc()): ?>
                            <?php
                                $actionRaw = strtoupper($row['action_type'] ?? 'UNKNOWN');
                                $group = audit_action_group($actionRaw);
                                $actionNatural = formatNaturalAction($actionRaw);
                                $descNatural = formatNaturalSentence($row['description'] ?? '', $actionRaw, $user_map);
                                
                                $actor = !empty($row['full_name']) ? trim($row['full_name']) : 'Unknown/System';
                                $role = !empty($row['role']) ? $row['role'] : 'External';
                                $timestamp = strtotime($row['timestamp'] ?? 'now');
                                
                                $parts = preg_split('/\s+/', $actor);
                                $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1)) . (count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '');

                                $isRisk = in_array($group, ['Security', 'Delete']);
                                $searchText = strtolower($actor . ' ' . $role . ' ' . $actionNatural . ' ' . $group . ' ' . $descNatural . ' ' . ($row['ip_address'] ?? ''));
                                
                                // JSON Payload Variables
                                $hasPayload = (!empty($row['old_payload']) || !empty($row['new_payload']));
                                $b64Old = base64_encode($row['old_payload'] ?? '');
                                $b64New = base64_encode($row['new_payload'] ?? '');
                                $b64Desc = base64_encode($row['description'] ?? '');
                            ?>
                            <tr data-group="<?php echo audit_h($group); ?>" data-search="<?php echo audit_h($searchText); ?>">
                                <td>
                                    <span class="text-dark fw-medium d-block" style="font-size: 0.9rem;"><?php echo date('M d, Y', $timestamp); ?></span> 
                                    <span class="text-muted fw-medium" style="font-size: 0.75rem; letter-spacing: 0.5px;"><?php echo date('h:i A', $timestamp); ?></span>
                                </td>
                                <td>
                                    <div class="user-cell">
                                        <div class="avatar-circle <?php echo ($actor === 'Unknown/System') ? 'bg-danger bg-opacity-10 text-danger border-danger' : ''; ?>">
                                            <?php echo audit_h($initials); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?php echo audit_h($actor); ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem; margin-top: 2px;">
                                                <i class="fas fa-shield-alt me-1" style="font-size: 0.7rem;"></i><?php echo audit_h($role); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?php echo audit_badge_style($group); ?> px-2 py-1" style="font-weight: 600; letter-spacing: 0.3px;">
                                        <?php echo audit_h($actionNatural); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="<?php echo $isRisk ? 'fw-bold text-dark' : 'text-secondary'; ?>" style="font-size: 0.85rem; line-height: 1.4;">
                                        <?php echo audit_h($descNatural); ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="text-muted fw-medium mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                        <?php echo audit_h($row['ip_address'] ?? '-'); ?>
                                    </div>
                                    <button type="button" class="btn-json-view" onclick="openPayloadModal('<?php echo $b64Old; ?>', '<?php echo $b64New; ?>', '<?php echo $b64Desc; ?>', '<?php echo $row['log_id']; ?>')">
                                        <i class="fas fa-code me-1"></i> Details
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div id="auditEmpty" class="text-center py-5 no-print" style="display:none;">
                <p class="text-muted mb-0"><i class="fas fa-search me-2"></i>No matching events found in the current view.</p>
            </div>
        </div>
        
        <div class="card-footer bg-white border-top px-4 py-3 d-flex justify-content-between align-items-center no-print">
            <div id="auditInfo" class="text-muted small fw-medium">Loading...</div>
        </div>
    </div>

</div>

<div class="modal fade json-modal" id="payloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <div>
                    <h5 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">State Change Details</h5>
                    <span class="text-muted" style="font-size: 0.8rem;" id="modalLogId">Log ID: ---</span>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <h6 class="fw-bold text-muted text-uppercase mb-2" style="font-size: 0.75rem; letter-spacing: 0.5px;">Full Audit Context</h6>
                    <div class="p-3 bg-light border" style="border-radius: 8px; font-size: 0.85rem; color: #334155;" id="modalDescText"></div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="fw-bold text-danger text-uppercase mb-2" style="font-size: 0.75rem; letter-spacing: 0.5px;"><i class="fas fa-minus-circle me-1"></i> Previous State</h6>
                        <pre class="json-block" id="modalOldPayload">No previous data stored.</pre>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-success text-uppercase mb-2" style="font-size: 0.75rem; letter-spacing: 0.5px;"><i class="fas fa-plus-circle me-1"></i> New State / Input</h6>
                        <pre class="json-block" id="modalNewPayload">No new data stored.</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-light fw-medium px-4 py-2" data-bs-dismiss="modal" style="border-radius: 10px;">Close Panel</button>
            </div>
        </div>
    </div>
</div>

<script>
// JSON Formatting Helper
function prettyFormatJSON(b64String) {
    try {
        if (!b64String) return "No data recorded.";
        const decoded = atob(b64String);
        if (!decoded || decoded === 'null') return "No data recorded.";
        
        // Handle if it's already a string vs JSON
        let parsed;
        try { parsed = JSON.parse(decoded); } 
        catch (e) { return decoded; } // If not JSON, return plain text

        return JSON.stringify(parsed, null, 2);
    } catch (e) {
        return "Error parsing data.";
    }
}

// Open Modal Function
function openPayloadModal(b64Old, b64New, b64Desc, logId) {
    document.getElementById('modalLogId').innerText = "Log Record: #" + logId;
    document.getElementById('modalDescText').innerText = atob(b64Desc) || "No description provided.";
    
    document.getElementById('modalOldPayload').textContent = prettyFormatJSON(b64Old);
    document.getElementById('modalNewPayload').textContent = prettyFormatJSON(b64New);
    
    var myModal = new bootstrap.Modal(document.getElementById('payloadModal'));
    myModal.show();
}

document.addEventListener('DOMContentLoaded', function () {
    const rows = Array.from(document.querySelectorAll('#auditTable tbody tr[data-search]'));
    const searchInput = document.getElementById('auditSearch');
    const actionFilter = document.getElementById('actionFilter');
    const rowLimitSelect = document.getElementById('rowLimit');
    const clearFilters = document.getElementById('clearFilters');
    const info = document.getElementById('auditInfo');
    const empty = document.getElementById('auditEmpty');
    const exportExcelBtn = document.getElementById('exportExcelBtn');

    function matches(row) {
        const q = searchInput.value.trim().toLowerCase();
        const group = actionFilter.value;
        const text = row.dataset.search || '';
        const rowGroup = row.dataset.group || '';
        return (!q || text.includes(q)) && (!group || rowGroup === group);
    }

    function render() {
        if(rows.length === 0) return;
        
        const limit = parseInt(rowLimitSelect.value, 10) || 50;
        const filtered = rows.filter(matches);
        
        rows.forEach(row => row.style.display = 'none');
        const toShow = filtered.slice(0, limit);
        toShow.forEach(row => row.style.display = '');
        
        if (filtered.length === 0) {
            empty.style.display = 'block';
            document.querySelector('#auditTable').style.display = 'none';
        } else {
            empty.style.display = 'none';
            document.querySelector('#auditTable').style.display = 'table';
        }
        
        info.innerHTML = `Showing <span class="text-dark fw-bold">${toShow.length}</span> out of <span class="text-dark fw-bold">${filtered.length}</span> events`;
    }

    searchInput.addEventListener('input', render);
    actionFilter.addEventListener('change', render);
    rowLimitSelect.addEventListener('change', render);
    
    clearFilters.addEventListener('click', () => {
        searchInput.value = ''; 
        actionFilter.value = ''; 
        rowLimitSelect.value = '50';
        render();
    });

    // Enterprise Excel Export
    exportExcelBtn.addEventListener('click', () => {
        const filteredForExport = rows.filter(matches);
        if (filteredForExport.length === 0) { alert("No records to export."); return; }

        let excelData = [["Date & Time", "Actor Profile", "Event Type", "Context / Description", "IP Address"]];

        filteredForExport.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length === 5) {
                // To avoid copying the "Details" button text
                let ipText = cols[4].querySelector('div').innerText;
                excelData.push([
                    cols[0].innerText.replace(/\s+/g, ' ').trim(),
                    cols[1].innerText.replace(/\s+/g, ' ').trim(),
                    cols[2].innerText.replace(/\s+/g, ' ').trim(),
                    cols[3].innerText.replace(/\s+/g, ' ').trim(),
                    ipText
                ]);
            }
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        ws['!cols'] = [{ wch: 22 }, { wch: 30 }, { wch: 20 }, { wch: 75 }, { wch: 18 }];

        XLSX.utils.book_append_sheet(wb, ws, "Audit Logs");
        const now = new Date();
        const timestamp = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        XLSX.writeFile(wb, `Audit_Trail_Export_${timestamp}.xlsx`);
    });

    window.addEventListener('beforeprint', () => { rows.forEach(row => row.style.display = matches(row) ? '' : 'none'); });
    window.addEventListener('afterprint', render);
    
    render();
});
</script>
</body>
</html>