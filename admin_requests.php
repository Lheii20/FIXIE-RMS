<?php
require 'config/db_connect.php';
require 'config/functions.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// FILTER LOGIC
$status_filter = $_GET['status'] ?? 'Pending';
$search = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = "";

// Status Filter (Default is Pending)
if ($status_filter !== 'All') {
    $where_clauses[] = "r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Search Filter
if (!empty($search)) {
    $where_clauses[] = "(u.full_name LIKE ? OR u.username LIKE ? OR r.request_type LIKE ? OR r.reason LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= "ssss";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// DESC Sorting para nasa taas ang recent
$query = "SELECT r.*, u.full_name, u.role, u.status AS user_status, u.username as current_username
          FROM user_requests r 
          JOIN users u ON r.user_id = u.user_id 
          $where_sql 
          ORDER BY r.requested_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reqs = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Account Requests - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        /* Isang font na lamang (Inter) para sa buong page para 100% consistent */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        body, .main-content {
            background-color: #f8fafc !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #1e293b;
        }

        .table-container {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .modern-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .modern-table > :not(caption) > * > * { padding: 0.85rem 1.25rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .modern-table thead th {
            background-color: #f8fafc; color: #64748b; font-size: 0.75rem; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 600; border-bottom: 2px solid #e2e8f0;
        }
        .modern-table tbody tr { transition: background-color 0.2s ease; }
        .modern-table tbody tr:hover { background-color: #f8fafc; }
        
        .user-cell { display: flex; align-items: center; gap: 0.75rem; }
        .avatar-circle {
            width: 36px; height: 36px; border-radius: 50%; background-color: #f1f5f9;
            color: #475569; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center;
            justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;
        }

        .action-btn {
            width: 32px; height: 32px; border-radius: 6px;
            transition: all 0.2s; border: none; display: inline-flex; align-items: center; justify-content: center;
            font-size: 0.85rem;
        }
        .btn-view { background-color: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe; }
        .btn-view:hover { background-color: #dbeafe; }
        .btn-approve { background-color: #ecfdf5; color: #10b981; border: 1px solid #bbf7d0; }
        .btn-approve:hover { background-color: #d1fae5; }
        .btn-reject { background-color: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
        .btn-reject:hover { background-color: #fee2e2; }

        .req-badge {
            font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.6rem; border-radius: 5px;
            display: inline-flex; align-items: center; gap: 0.3rem;
        }
        
        .text-truncate-custom {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: middle;
        }

        /* Improved Sleek Modal Styling */
        .sleek-modal .modal-content { 
            border-radius: 12px; 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); 
            overflow: hidden; 
        }
        .sleek-modal .modal-header { 
            background: #ffffff; 
            border-bottom: 1px solid #f1f5f9; 
            padding: 1.25rem 1.5rem; 
        }
        .sleek-modal .modal-title {
            font-size: 1.1rem;
            letter-spacing: -0.3px;
        }
        .sleek-modal .modal-body { 
            padding: 1.5rem; 
            background: #ffffff;
        }
        .sleek-modal .modal-footer { 
            background: #f8fafc; 
            border-top: 1px solid #f1f5f9; 
            padding: 1rem 1.5rem; 
        }
        .data-label { 
            font-size: 0.7rem; 
            text-transform: uppercase; 
            font-weight: 700; 
            color: #64748b; 
            letter-spacing: 0.5px; 
            margin-bottom: 0.2rem; 
        }
        .data-value { 
            font-size: 0.95rem; 
            font-weight: 600; 
            color: #0f172a; 
            margin-bottom: 1.25rem; 
            word-break: break-word; 
        }
        
        /* Reason Box Text Wrap Fix */
        .reason-box { 
            background: #f8fafc; 
            border: 1px solid #e2e8f0;
            border-left: 3px solid #3b82f6; 
            padding: 1rem; 
            border-radius: 6px; 
            color: #334155; 
            font-size: 0.9rem; 
            line-height: 1.6;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }

        .reason-box::-webkit-scrollbar { width: 5px; }
        .reason-box::-webkit-scrollbar-track { background: transparent; }
        .reason-box::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .reason-box::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 mt-2 gap-3">
            <div>
                <h4 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">Account Requests</h4>
                <p class="text-muted mb-0" style="font-size: 0.85rem;">Review and manage user security and profile requests.</p>
            </div>
            
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2 m-0">
                <div class="position-relative">
                    <input type="search" name="search" class="form-control form-control-sm pe-4 shadow-none" style="width: 250px; border-radius: 6px; border-color: #cbd5e1;" placeholder="Search user or reason..." value="<?php echo htmlspecialchars($search); ?>">
                    <i class="fas fa-search position-absolute text-muted" style="top: 50%; right: 12px; transform: translateY(-50%); font-size: 0.8rem; pointer-events: none;"></i>
                </div>
                
                <select name="status" class="form-select form-select-sm shadow-none" style="width: 140px; border-radius: 6px; border-color: #cbd5e1; cursor: pointer;" onchange="this.form.submit()">
                    <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="All" <?php echo $status_filter == 'All' ? 'selected' : ''; ?>>All Requests</option>
                </select>

                <?php if(!empty($search) || $status_filter !== 'Pending'): ?>
                    <a href="admin_requests.php" class="btn btn-sm btn-light border text-muted shadow-none" title="Reset Filters" style="border-radius: 6px; padding: 0.25rem 0.6rem;">
                        <i class="fas fa-undo-alt"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success border-0 shadow-sm" style="border-radius: 8px; font-size: 0.9rem;">
                <i class="fas fa-check-circle me-2"></i> Action completed successfully.
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th width="15%">Date</th>
                        <th width="25%">User Information</th>
                        <th width="35%">Request Type & Reason</th>
                        <th width="10%">Status</th>
                        <th width="15%" class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if($reqs && $reqs->num_rows > 0):
                        while($row = $reqs->fetch_assoc()): 
                            $timestamp = strtotime($row['requested_at']);
                            $dateFmt = date('M d, Y', $timestamp);
                            $timeFmt = date('h:i A', $timestamp);
                            
                            $parts = preg_split('/\s+/', trim($row['full_name']));
                            $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1)) . (count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '');
                            
                            $reqType = htmlspecialchars($row['request_type']);
                            $newVal = htmlspecialchars($row['new_value']);
                            $reason = htmlspecialchars($row['reason']);
                            $fullName = htmlspecialchars($row['full_name']);
                            $userName = htmlspecialchars($row['current_username']);
                            $role = htmlspecialchars($row['role']);
                    ?>
                    <tr>
                        <td>
                            <span class="text-dark fw-medium d-block" style="font-size: 0.85rem;"><?php echo $dateFmt; ?></span>
                            <span class="text-muted fw-medium" style="font-size: 0.75rem;"><?php echo $timeFmt; ?></span>
                        </td>
                        <td>
                            <div class="user-cell">
                                <div class="avatar-circle"><?php echo $initials; ?></div>
                                <div>
                                    <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?php echo $fullName; ?></div>
                                    <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                        <span class="fw-semibold text-primary">@<?php echo $userName; ?></span> &bull; <?php echo $role; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if($reqType == 'Unlock Account'): ?>
                                <span class="req-badge bg-warning bg-opacity-10 text-dark border border-warning"><i class="fas fa-unlock"></i> Unlock Account</span>
                            <?php else: ?>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="req-badge bg-info bg-opacity-10 text-info border border-info"><i class="fas fa-user-edit"></i> Username Change</span>
                                    <i class="fas fa-arrow-right text-muted" style="font-size: 0.7rem;"></i>
                                    <span class="fw-bold text-dark" style="font-size: 0.85rem;">@<?php echo $newVal; ?></span>
                                </div>
                                <?php if(!empty($reason)): ?>
                                    <div class="text-truncate-custom text-muted fst-italic" style="font-size: 0.75rem;">
                                        "<?php echo $reason; ?>"
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['status'] === 'Pending'): ?>
                                <span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-2 py-1">Pending</span>
                            <?php elseif($row['status'] === 'Approved'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1">Approved</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="action-btn btn-view" title="View Details" 
                                    data-id="<?php echo $row['request_id']; ?>"
                                    data-date="<?php echo $dateFmt . ' ' . $timeFmt; ?>"
                                    data-fullname="<?php echo $fullName; ?>"
                                    data-username="<?php echo $userName; ?>"
                                    data-role="<?php echo $role; ?>"
                                    data-type="<?php echo $reqType; ?>"
                                    data-newval="<?php echo $newVal; ?>"
                                    data-reason="<?php echo $reason; ?>"
                                    data-status="<?php echo $row['status']; ?>"
                                    onclick="openRequestModal(this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                
                                <?php if($row['status'] === 'Pending'): ?>
                                    <form action="actions/request_handler.php" method="POST" class="m-0 p-0 d-inline-flex gap-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="manage_request">
                                        <input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                                        
                                        <button name="decision" value="Approve" class="action-btn btn-approve" onclick="return confirm('Approve this request?');" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button name="decision" value="Reject" class="action-btn btn-reject" onclick="return confirm('Reject this request?');" title="Reject">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">
                            <i class="fas fa-search text-muted opacity-25 mb-3" style="font-size: 2.5rem;"></i>
                            <h6 class="fw-bold text-dark mb-1">No requests found</h6>
                            <p class="text-muted small mb-0">Try adjusting your search or filter.</p>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- VIEW REQUEST MODAL -->
    <div class="modal fade sleek-modal" id="requestDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-file-invoice me-2 text-primary"></i>Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3 border-bottom pb-3">
                        <div>
                            <div class="data-label">Date Submitted</div>
                            <div class="data-value mb-0 text-muted" style="font-size: 0.85rem; font-weight: 500;" id="modDate"></div>
                        </div>
                        <div class="text-end">
                            <div class="data-label">Status</div>
                            <div id="modStatusBadge"></div>
                        </div>
                    </div>

                    <div class="data-label">Requesting User</div>
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div class="avatar-circle bg-white border shadow-sm" id="modInitials" style="width: 44px; height: 44px; font-size:1.1rem; color: #0f172a;">U</div>
                        <div>
                            <div class="fw-bold text-dark" id="modFullName" style="font-size: 0.95rem;">Full Name</div>
                            <div class="text-muted" style="font-size: 0.8rem; margin-top: 2px;">
                                <span class="fw-semibold text-primary" id="modUsername">@username</span> &bull; <span id="modRole" class="fw-medium">Role</span>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="data-label">Action Requested</div>
                            <div class="data-value"><span class="badge bg-light text-dark border px-2 py-1 fw-medium" id="modType">Type</span></div>
                        </div>
                        <div class="col-sm-6" id="modNewValueContainer">
                            <div class="data-label text-primary">Proposed Username</div>
                            <div class="data-value fw-bold text-primary" id="modNewValue"></div>
                        </div>
                    </div>

                    <div id="modReasonContainer">
                        <div class="data-label mt-2">Reason / Remarks</div>
                        <div class="reason-box" id="modReason"></div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between" id="modFooterActions">
                    <button type="button" class="btn btn-white border bg-white text-muted fw-medium px-4 shadow-sm" data-bs-dismiss="modal" style="border-radius: 6px;">Close</button>
                    
                    <form action="actions/request_handler.php" method="POST" id="modActionForm" class="m-0 p-0 d-inline-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="manage_request">
                        <input type="hidden" name="request_id" id="modReqId" value="">
                        
                        <button name="decision" value="Reject" class="btn btn-danger fw-medium px-4 shadow-sm" style="border-radius: 6px;" onclick="return confirm('Reject this request?');">Reject</button>
                        <button name="decision" value="Approve" class="btn btn-success fw-medium px-4 shadow-sm" style="border-radius: 6px;" onclick="return confirm('Approve this request?');">Approve</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openRequestModal(btnElement) {
            const id = btnElement.getAttribute('data-id');
            const date = btnElement.getAttribute('data-date');
            const fullName = btnElement.getAttribute('data-fullname');
            const userName = btnElement.getAttribute('data-username');
            const role = btnElement.getAttribute('data-role');
            const type = btnElement.getAttribute('data-type');
            const newVal = btnElement.getAttribute('data-newval');
            const reason = btnElement.getAttribute('data-reason');
            const status = btnElement.getAttribute('data-status');

            document.getElementById('modDate').innerText = date;
            document.getElementById('modFullName').innerText = fullName;
            document.getElementById('modUsername').innerText = '@' + userName;
            document.getElementById('modRole').innerText = role;
            
            let parts = fullName.trim().split(/\s+/);
            let init = (parts[0] ? parts[0].charAt(0).toUpperCase() : 'U') + (parts.length > 1 ? parts[parts.length-1].charAt(0).toUpperCase() : '');
            document.getElementById('modInitials').innerText = init;

            document.getElementById('modType').innerHTML = '<i class="fas fa-' + (type === 'Unlock Account' ? 'unlock' : 'user-edit') + ' me-1"></i>' + type;
            if (type === 'Change Username') {
                document.getElementById('modNewValueContainer').style.display = 'block';
                document.getElementById('modNewValue').innerText = '@' + newVal;
            } else {
                document.getElementById('modNewValueContainer').style.display = 'none';
            }

            if (reason && reason.trim() !== '') {
                document.getElementById('modReasonContainer').style.display = 'block';
                document.getElementById('modReason').innerText = reason;
            } else {
                document.getElementById('modReasonContainer').style.display = 'none';
            }

            let badgeHtml = '';
            if (status === 'Pending') badgeHtml = '<span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-3 py-1">Pending</span>';
            else if (status === 'Approved') badgeHtml = '<span class="badge bg-success text-white px-3 py-1 shadow-sm">Approved</span>';
            else badgeHtml = '<span class="badge bg-danger text-white px-3 py-1 shadow-sm">Rejected</span>';
            document.getElementById('modStatusBadge').innerHTML = badgeHtml;

            const form = document.getElementById('modActionForm');
            if (status === 'Pending') {
                form.style.display = 'inline-flex';
                document.getElementById('modReqId').value = id;
            } else {
                form.style.display = 'none';
            }

            new bootstrap.Modal(document.getElementById('requestDetailsModal')).show();
        }
    </script>
</body>
</html>