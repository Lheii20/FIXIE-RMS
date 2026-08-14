<?php require 'config/db_connect.php'; require 'config/functions.php'; 

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { header("Location: dashboard.php"); exit(); }

$status_filter = $_GET['status'] ?? 'Pending'; $search = $_GET['search'] ?? '';
$where_clauses = []; $params = []; $types = "";

if ($status_filter !== 'All') { $where_clauses[] = "r.status = ?"; $params[] = $status_filter; $types .= "s"; }
if (!empty($search)) { $where_clauses[] = "(u.full_name LIKE ? OR u.username LIKE ? OR r.request_type LIKE ? OR r.reason LIKE ?)"; $search_term = "%$search%"; $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]); $types .= "ssss"; }
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$query = "SELECT r.*, u.full_name, u.role, u.status AS user_status, u.username as current_username FROM user_requests r JOIN users u ON r.user_id = u.user_id $where_sql ORDER BY r.requested_at DESC";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$reqs = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Account Requests - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="page-admin-requests">
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in">
        
        <div class="admin-page-header admin-requests-header d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 mt-2 gap-3">
            <div class="admin-page-title">
                <h4 class="fw-bold text-dark mb-0 tracking-tight">Account Requests</h4>
                <p class="text-muted mb-0 fs-sm">Review and manage user security and profile requests.</p>
            </div>
            
            <form method="GET" class="admin-requests-toolbar d-flex flex-column flex-sm-row align-items-sm-center gap-2 m-0 w-100">
                <div class="admin-request-search position-relative w-100">
                    <input type="search" name="search" class="form-control form-control-sm pe-4 shadow-none w-100 rounded-custom" placeholder="Search user or reason..." value="<?php echo htmlspecialchars($search); ?>">
                    <i class="admin-search-icon fas fa-search position-absolute text-muted" aria-hidden="true"></i>
                </div>
                
                <div class="admin-request-filter-row d-flex gap-2 w-100">
                    <select name="status" class="admin-status-select form-select form-select-sm shadow-none w-100 rounded-custom" onchange="this.form.submit()">
                        <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="All" <?php echo $status_filter == 'All' ? 'selected' : ''; ?>>All Requests</option>
                    </select>
                    <?php if(!empty($search) || $status_filter !== 'Pending'): ?>
                        <a href="admin_requests.php" class="admin-filter-reset btn btn-sm btn-light border text-muted shadow-none flex-shrink-0 rounded-custom px-2" title="Reset Filters" aria-label="Reset filters"><i class="fas fa-undo-alt"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-container admin-requests-table-wrap">
            <table class="modern-table admin-requests-table">
                <thead>
                    <tr><th width="15%">Date</th><th width="25%">User Information</th><th width="35%">Request Type & Reason</th><th width="10%">Status</th><th width="15%" class="text-end pe-4">Actions</th></tr>
                </thead>
                <tbody>
                    <?php 
                    if($reqs && $reqs->num_rows > 0):
                        while($row = $reqs->fetch_assoc()): 
                            $timestamp = strtotime($row['requested_at']); $dateFmt = date('M d, Y', $timestamp); $timeFmt = date('h:i A', $timestamp);
                            $parts = preg_split('/\s+/', trim($row['full_name'])); $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1)) . (count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '');
                            $reqType = htmlspecialchars($row['request_type']); $newVal = htmlspecialchars($row['new_value']); $reason = htmlspecialchars($row['reason']); $fullName = htmlspecialchars($row['full_name']); $userName = htmlspecialchars($row['current_username']); $role = htmlspecialchars($row['role']);
                    ?>
                    <tr>
                        <td><span class="text-dark fw-medium d-block fs-sm"><?php echo $dateFmt; ?></span><span class="text-muted fw-medium fs-xs"><?php echo $timeFmt; ?></span></td>
                        <td>
                            <div class="user-cell">
                                <div class="avatar-circle"><?php echo $initials; ?></div>
                                <div>
                                    <div class="fw-bold text-dark fs-sm"><?php echo $fullName; ?></div>
                                    <div class="text-muted mt-1 fs-xs admin-request-desktop-identity"><span class="fw-semibold text-primary">@<?php echo $userName; ?></span> &bull; <?php echo $role; ?></div>
                                    <div class="admin-request-mobile-meta d-md-none">
                                        <span class="admin-mobile-request-datetime"><i class="far fa-clock" aria-hidden="true"></i><span><?php echo $dateFmt; ?></span><span aria-hidden="true">&bull;</span><span><?php echo $timeFmt; ?></span></span>
                                        <span class="admin-mobile-request-status is-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
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
                                    <i class="fas fa-arrow-right text-muted fs-xs"></i>
                                    <span class="fw-bold text-dark fs-sm">@<?php echo $newVal; ?></span>
                                </div>
                                <?php if(!empty($reason)): ?><div class="text-truncate-custom text-muted fst-italic fs-xs">"<?php echo $reason; ?>"</div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['status'] === 'Pending'): ?> <span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-2 py-1">Pending</span>
                            <?php elseif($row['status'] === 'Approved'): ?> <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1">Approved</span>
                            <?php else: ?> <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1">Rejected</span> <?php endif; ?>
                        </td>
                        <td class="text-end pe-4 admin-action-cell">
                            <div class="d-none d-md-inline-flex gap-1">
                                <button type="button" class="action-btn btn-view" title="View Details" data-id="<?php echo $row['request_id']; ?>" data-date="<?php echo $dateFmt . ' ' . $timeFmt; ?>" data-fullname="<?php echo $fullName; ?>" data-username="<?php echo $userName; ?>" data-role="<?php echo $role; ?>" data-type="<?php echo $reqType; ?>" data-newval="<?php echo $newVal; ?>" data-reason="<?php echo $reason; ?>" data-status="<?php echo $row['status']; ?>" onclick="openRequestModal(this)"><i class="fas fa-eye"></i></button>
                                <?php if($row['status'] === 'Pending'): ?>
                                    <form action="actions/request_handler.php" method="POST" class="m-0 p-0 d-inline-flex gap-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="manage_request"><input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                                        <button type="button" data-decision="Approve" data-requester="<?php echo $fullName; ?>" class="action-btn btn-approve" onclick="confirmRequestDecision(event, this)" title="Approve"><i class="fas fa-check"></i></button>
                                        <button type="button" data-decision="Reject" data-requester="<?php echo $fullName; ?>" class="action-btn btn-reject" onclick="confirmRequestDecision(event, this)" title="Reject"><i class="fas fa-times"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if($row['status'] === 'Pending'): ?>
                                <div class="dropdown admin-request-mobile-actions d-md-none">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window" aria-expanded="false" aria-label="Request actions"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><button type="button" class="dropdown-item" data-id="<?php echo $row['request_id']; ?>" data-date="<?php echo $dateFmt . ' ' . $timeFmt; ?>" data-fullname="<?php echo $fullName; ?>" data-username="<?php echo $userName; ?>" data-role="<?php echo $role; ?>" data-type="<?php echo $reqType; ?>" data-newval="<?php echo $newVal; ?>" data-reason="<?php echo $reason; ?>" data-status="<?php echo $row['status']; ?>" onclick="openRequestModal(this)"><i class="fas fa-eye text-primary"></i> View Details</button></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form action="actions/request_handler.php" method="POST" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="manage_request"><input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                                                <button type="button" data-decision="Approve" data-requester="<?php echo $fullName; ?>" class="dropdown-item text-success" onclick="confirmRequestDecision(event, this)"><i class="fas fa-check"></i> Approve</button>
                                                <button type="button" data-decision="Reject" data-requester="<?php echo $fullName; ?>" class="dropdown-item text-danger" onclick="confirmRequestDecision(event, this)"><i class="fas fa-times"></i> Reject</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <button type="button" class="admin-mobile-open d-md-none" aria-label="View request details" data-id="<?php echo $row['request_id']; ?>" data-date="<?php echo $dateFmt . ' ' . $timeFmt; ?>" data-fullname="<?php echo $fullName; ?>" data-username="<?php echo $userName; ?>" data-role="<?php echo $role; ?>" data-type="<?php echo $reqType; ?>" data-newval="<?php echo $newVal; ?>" data-reason="<?php echo $reason; ?>" data-status="<?php echo $row['status']; ?>" onclick="openRequestModal(this)"><i class="fas fa-chevron-right"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr class="admin-empty-row"><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-search text-muted opacity-25 mb-3 fa-2x"></i><h6 class="fw-bold text-dark mb-1">No requests found</h6><p class="text-muted small mb-0">Try adjusting your search or filter.</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- VIEW REQUEST MODAL -->
    <div class="modal fade sleek-modal admin-request-modal" id="requestDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header admin-request-modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-file-invoice me-2 text-primary"></i>Request Details</h5>
                        <p class="text-muted mb-0">Review the submitted account request.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="admin-request-profile">
                        <div class="avatar-circle bg-white border box-42 text-dark" id="modInitials">U</div>
                        <div class="admin-request-profile-copy">
                            <div class="fw-bold text-dark" id="modFullName">Full Name</div>
                            <div class="admin-request-profile-meta"><span class="fw-semibold text-primary" id="modUsername">@username</span><span aria-hidden="true">&bull;</span><span id="modRole">Role</span></div>
                        </div>
                        <div id="modStatusBadge" class="admin-request-profile-status"></div>
                    </div>

                    <div class="admin-request-detail-grid">
                        <div class="admin-request-detail-item"><div class="data-label">Date Submitted</div><div class="data-value" id="modDate"></div></div>
                        <div class="admin-request-detail-item"><div class="data-label">Action Requested</div><div class="data-value"><span class="admin-request-type-badge" id="modType">Type</span></div></div>
                        <div class="admin-request-detail-item" id="modNewValueContainer"><div class="data-label">Proposed Username</div><div class="data-value text-primary" id="modNewValue"></div></div>
                    </div>
                    <div class="admin-request-reason" id="modReasonContainer"><div class="data-label">Reason / Remarks</div><div class="reason-box" id="modReason"></div></div>
                </div>
                <div class="modal-footer d-flex justify-content-between" id="modFooterActions">
                    <button type="button" class="btn btn-white border bg-white text-muted fw-medium px-4 shadow-sm rounded-custom" data-bs-dismiss="modal">Close</button>
                    <form action="actions/request_handler.php" method="POST" id="modActionForm" class="m-0 p-0 d-inline-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="manage_request"><input type="hidden" name="request_id" id="modReqId" value="">
                        <button type="button" data-decision="Reject" class="btn btn-danger fw-medium px-4 shadow-sm rounded-custom" onclick="confirmRequestDecision(event, this)">Reject</button>
                        <button type="button" data-decision="Approve" class="btn btn-success fw-medium px-4 shadow-sm rounded-custom" onclick="confirmRequestDecision(event, this)">Approve</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function openRequestModal(btnElement) {
            const id = btnElement.getAttribute('data-id'); const date = btnElement.getAttribute('data-date'); const fullName = btnElement.getAttribute('data-fullname'); const userName = btnElement.getAttribute('data-username'); const role = btnElement.getAttribute('data-role'); const type = btnElement.getAttribute('data-type'); const newVal = btnElement.getAttribute('data-newval'); const reason = btnElement.getAttribute('data-reason'); const status = btnElement.getAttribute('data-status');
            document.getElementById('modDate').innerText = date; document.getElementById('modFullName').innerText = fullName; document.getElementById('modUsername').innerText = '@' + userName; document.getElementById('modRole').innerText = role;
            
            let parts = fullName.trim().split(/\s+/); let init = (parts[0] ? parts[0].charAt(0).toUpperCase() : 'U') + (parts.length > 1 ? parts[parts.length-1].charAt(0).toUpperCase() : '');
            document.getElementById('modInitials').innerText = init;
            document.getElementById('modType').innerHTML = '<i class="fas fa-' + (type === 'Unlock Account' ? 'unlock' : 'user-edit') + ' me-1"></i>' + type;

            if (type === 'Change Username') { document.getElementById('modNewValueContainer').style.display = 'block'; document.getElementById('modNewValue').innerText = '@' + newVal; } else { document.getElementById('modNewValueContainer').style.display = 'none'; }
            if (reason && reason.trim() !== '') { document.getElementById('modReasonContainer').style.display = 'block'; document.getElementById('modReason').innerText = reason; } else { document.getElementById('modReasonContainer').style.display = 'none'; }

            let badgeHtml = '';
            if (status === 'Pending') badgeHtml = '<span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-3 py-1">Pending</span>';
            else if (status === 'Approved') badgeHtml = '<span class="badge bg-success text-white px-3 py-1 shadow-sm">Approved</span>';
            else badgeHtml = '<span class="badge bg-danger text-white px-3 py-1 shadow-sm">Rejected</span>';
            document.getElementById('modStatusBadge').innerHTML = badgeHtml;

            const form = document.getElementById('modActionForm');
            if (status === 'Pending') {
                form.classList.remove('d-none');
                form.classList.add('d-inline-flex');
                document.getElementById('modReqId').value = id;
            } else {
                form.classList.remove('d-inline-flex');
                form.classList.add('d-none');
            }
            new bootstrap.Modal(document.getElementById('requestDetailsModal')).show();
        }

        function confirmRequestDecision(event, button) {
            event.preventDefault();
            event.stopPropagation();

            const form = button.closest('form');
            const decision = button.dataset.decision;
            const requester = button.dataset.requester || document.getElementById('modFullName').textContent.trim() || 'this user';
            const isApprove = decision === 'Approve';

            Swal.fire({
                title: isApprove ? 'Approve Request?' : 'Reject Request?',
                text: (isApprove ? 'Approve' : 'Reject') + ' the account request submitted by ' + requester + '?',
                icon: isApprove ? 'question' : 'warning',
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: isApprove ? '<i class="fas fa-check me-1"></i> Approve' : '<i class="fas fa-times me-1"></i> Reject',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: {
                    popup: 'sleek-popup request-decision-alert',
                    confirmButton: isApprove ? 'btn btn-success request-alert-confirm' : 'btn btn-danger request-alert-confirm',
                    cancelButton: 'btn btn-light border request-alert-cancel'
                }
            }).then((result) => {
                if (!result.isConfirmed) return;

                let decisionInput = form.querySelector('input[name="decision"]');
                if (!decisionInput) {
                    decisionInput = document.createElement('input');
                    decisionInput.type = 'hidden';
                    decisionInput.name = 'decision';
                    form.appendChild(decisionInput);
                }
                decisionInput.value = decision;
                form.submit();
            });
        }

        <?php if(isset($_GET['success'])): ?>
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: 'success',
            title: 'Request updated successfully.',
            showConfirmButton: false,
            timer: 2600,
            timerProgressBar: true,
            customClass: { popup: 'small-toast request-feedback-toast shadow-sm border' }
        });
        <?php elseif(isset($_GET['error'])): ?>
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: 'error',
            title: 'Unable to update the request.',
            showConfirmButton: false,
            timer: 3200,
            timerProgressBar: true,
            customClass: { popup: 'small-toast request-feedback-toast shadow-sm border' }
        });
        <?php endif; ?>
    </script>
</body>
</html>
