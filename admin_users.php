<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { header("Location: dashboard.php"); exit(); }

$toastMsg = ''; $toastType = '';
if(isset($_GET['success'])) {
    $toastType = 'success';
    if($_GET['success'] == 'UserUpdated') $toastMsg = 'User information updated successfully.'; elseif($_GET['success'] == 'UserCreated') $toastMsg = 'User created and the secure account-setup email was sent.'; elseif($_GET['success'] == 'UserStatusUpdated') $toastMsg = 'User account status updated.'; elseif($_GET['success'] == 'UserForceLoggedOut') $toastMsg = 'The user session was forcefully terminated.'; elseif($_GET['success'] == 'PermissionsUpdated') $toastMsg = 'User access capabilities updated successfully.'; elseif($_GET['success'] == 'PasswordResetCodeSent') $toastMsg = 'A secure six-digit password-reset code was sent to the user.'; elseif($_GET['success'] == 'UserCreatedButEmailFailed') { $toastType = 'warning'; $toastMsg = 'User created but the setup email could not be sent. Check the SMTP configuration.'; } else $toastMsg = 'Action completed successfully.'; 
} elseif(isset($_GET['error'])) {
    $toastType = 'error';
    if($_GET['error'] == 'CannotChangeAdminRole') $toastMsg = 'An Administrator account cannot be demoted from User Management.'; elseif($_GET['error'] == 'CannotSuspendSelf') $toastMsg = 'You cannot suspend your own account.'; elseif($_GET['error'] == 'CannotForceLogoutSelf') $toastMsg = 'Use the normal logout action for your own account.'; elseif($_GET['error'] == 'LastActiveAdmin') $toastMsg = 'The last active Administrator account cannot be suspended.'; elseif($_GET['error'] == 'UserDeletionDisabled') $toastMsg = 'User deletion is disabled to preserve record ownership and audit history. Suspend the account instead.'; elseif($_GET['error'] == 'DuplicateUsername') $toastMsg = 'That username is already assigned to another account.'; elseif($_GET['error'] == 'DuplicateEmail') $toastMsg = 'That recovery email is already assigned or pending on another account.'; elseif($_GET['error'] == 'InvalidUsername') $toastMsg = 'Use 3–50 lowercase letters or numbers, with period, underscore, or hyphen only as separators.'; elseif($_GET['error'] == 'InvalidFullName') $toastMsg = 'Enter a valid full name containing 2 to 100 characters.'; elseif($_GET['error'] == 'InvalidEmail') $toastMsg = 'Enter a valid recovery email address with no more than 100 characters.'; elseif($_GET['error'] == 'InvalidRole') $toastMsg = 'Select a valid system role.'; elseif($_GET['error'] == 'InvalidStatus') $toastMsg = 'The submitted account status is invalid.'; elseif($_GET['error'] == 'InvalidPermission') $toastMsg = 'One or more submitted capabilities are invalid.'; elseif($_GET['error'] == 'InvalidAction') $toastMsg = 'The submitted user-management action is invalid.'; elseif($_GET['error'] == 'CannotResetOwnPassword') $toastMsg = 'Use Account Settings to change your own password.'; elseif($_GET['error'] == 'AccountSuspended') $toastMsg = 'Reactivate the account before sending a password-reset code.'; elseif($_GET['error'] == 'MissingRecoveryEmail') $toastMsg = 'Set a valid recovery email before sending a password-reset code.'; elseif($_GET['error'] == 'PasswordResetCooldown') $toastMsg = 'Please wait 60 seconds before sending another password-reset code.'; elseif($_GET['error'] == 'PasswordResetEmailFailed') $toastMsg = 'The reset-code email could not be sent. No new reset code was activated.'; elseif($_GET['error'] == 'PasswordRecoveryNotInitialized') $toastMsg = 'Password recovery is not initialized. Install its SQL migration first.'; elseif($_GET['error'] == 'LegacyPasswordResetDisabled') $toastMsg = 'Reset links and temporary passwords are disabled. Send a secure reset code instead.'; elseif($_GET['error'] == 'UserNotFound') $toastMsg = 'The selected user account was not found.'; elseif($_GET['error'] == 'CreateFailed') $toastMsg = 'The user account could not be created.'; elseif($_GET['error'] == 'UpdateFailed') $toastMsg = 'Failed to update the user account.'; else $toastMsg = 'The requested user-management action could not be completed.'; 
}

ensure_rbac_tables_exist($conn);

$user_perms = []; $perm_query = $conn->query("SELECT user_id, permission_name FROM user_permissions");
if ($perm_query) { while($p = $perm_query->fetch_assoc()) { $user_perms[$p['user_id']][] = $p['permission_name']; } }
$all_permissions = []; $all_p_query = $conn->query("SELECT * FROM permissions WHERE permission_name != 'can_manage_users'");
if ($all_p_query) { while($p = $all_p_query->fetch_assoc()) { $all_permissions[] = $p; } }
$all_perms_json = json_encode($all_permissions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Users - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="page-admin-users">
<?php include 'sidebar.php'; ?>
<div class="main-content fade-in">
    <div class="admin-page-header d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div class="admin-page-title">
            <h2 class="fw-bold mb-1 text-dark tracking-tight"><i class="fas fa-users-cog text-primary me-2"></i>User Management</h2>
            <p class="text-muted mb-0 small">Administer user accounts, security tokens, and dynamic system permissions.</p>
        </div>
        <button class="admin-primary-action btn btn-primary fw-medium px-4 py-2 shadow-sm rounded-8" data-bs-toggle="modal" data-bs-target="#addUserModal" aria-label="Add new user">
            <i class="fas fa-plus" aria-hidden="true"></i><span class="admin-action-label">Add New User</span>
        </button>
    </div>

    <div class="sleek-search-container admin-users-toolbar">
        <div class="input-group sleek-input-group admin-users-search">
            <span class="input-group-text border-0 bg-transparent text-muted px-3"><i class="fas fa-search"></i></span>
            <input type="text" id="customSearchInput" class="form-control sleek-search-input px-0" placeholder="Search users...">
        </div>
        
        <div class="admin-users-role-filter d-flex align-items-center gap-3 w-100">
            <span class="text-muted small fw-bold text-uppercase d-none d-sm-inline tracking-wide">Filter:</span>
            <select id="roleFilter" class="admin-role-select form-select shadow-none bg-light rounded-8">
                <option value="">All Roles</option>
                <option value="Admin">Admin</option>
                <option value="President">President</option>
                <option value="GM">General Manager</option>
                <option value="Finance">Finance</option>
                <option value="Procurement">Procurement</option>
                <option value="Supply Chain">Supply Chain</option>
                <option value="Sales Staff">Sales Staff</option>
            </select>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-12 admin-list-card">
        <div class="card-body p-0" style="min-height: 400px;">
            <div class="table-responsive admin-users-table-wrap">
                <table class="table w-100 admin-users-table" id="usersTable">
                    <thead class="bg-light">
                        <tr><th class="ps-4">User Identity</th><th>Username / Recovery</th><th>Assigned Role</th><th>Account Status</th><th>Presence</th><th class="text-center pe-4" style="width: 80px;">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php 
                        $query = "SELECT *, (last_active >= NOW() - INTERVAL 5 MINUTE) as is_online FROM users ORDER BY is_online DESC, full_name ASC";
                        $users = $conn->query($query);
                        if ($users) {
                            while($u = $users->fetch_assoc()):
                                $u_perms = isset($user_perms[$u['user_id']]) ? json_encode($user_perms[$u['user_id']]) : '[]';
                        ?>
                        <tr>
                            <td class="ps-4 admin-primary-cell">
                                <div class="d-flex align-items-center gap-3 admin-user-identity">
                                    <div class="position-relative d-inline-block">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary border shadow-sm box-44 overflow-hidden">
                                            <?php if(!empty($u['avatar']) && file_exists($u['avatar'])): ?><img src="download.php?file=<?php echo rawurlencode(basename($u['avatar'])); ?>&amp;type=avatar" class="w-100 h-100 object-fit-cover" alt="Avatar"><?php else: ?><span class="fw-bold" style="font-size: 1.1rem;"><?php echo strtoupper(substr($u['full_name'], 0, 1)); ?></span><?php endif; ?>
                                        </div>
                                        <?php if(isset($u['is_online']) && $u['is_online']): ?><span class="position-absolute bottom-0 end-0 bg-success border border-2 border-white rounded-circle box-12"></span><?php else: ?><span class="position-absolute bottom-0 end-0 bg-secondary border border-2 border-white rounded-circle box-12"></span><?php endif; ?>
                                    </div>
                                    <div class="admin-user-summary">
                                        <h6 class="mb-0 fw-bold text-dark"><?php echo e($u['full_name']); ?></h6>
                                        <span class="badge bg-light text-secondary border mt-1">ID: #<?php echo $u['user_id']; ?></span>
                                        <div class="admin-user-mobile-meta d-md-none">
                                            <span class="admin-mobile-role"><?php echo e($u['role']); ?></span>
                                            <span class="admin-mobile-status <?php echo $u['status'] === 'Active' ? 'is-active' : 'is-suspended'; ?>"><?php echo e($u['status']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="text-dark fw-medium"><?php echo e($u['username']); ?></div>
                                <?php if (!empty(trim((string) $u['email']))): ?>
                                    <div class="text-muted small text-truncate" style="max-width: 260px;" title="<?php echo e($u['email']); ?>"><i class="fas fa-envelope me-1" aria-hidden="true"></i><?php echo e($u['email']); ?></div>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle mt-1"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Recovery ready</span>
                                <?php else: ?>
                                    <div class="text-muted small"><i class="fas fa-envelope-open me-1" aria-hidden="true"></i>Not configured</div>
                                    <span class="badge bg-warning bg-opacity-10 text-warning-emphasis border border-warning-subtle mt-1"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>No recovery email</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1 rounded-3"><i class="fas fa-id-badge me-1"></i> <?php echo e($u['role']); ?></span></td>
                            <td><?php if ($u['status'] === 'Active'): ?><span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-2 py-1 rounded-3"><i class="fas fa-check-circle me-1"></i> Active</span><?php else: ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 rounded-3"><i class="fas fa-ban me-1"></i> Suspended</span><?php endif; ?></td>
                            <td><?php if(isset($u['is_online']) && $u['is_online']): ?><span class="text-success fw-medium small"><i class="fas fa-wifi me-1"></i> Online</span><?php else: ?><span class="text-secondary small"><i class="fas fa-history me-1"></i> <?php echo (!empty($u['last_active']) && $u['last_active'] !== '0000-00-00 00:00:00') ? date('M d, H:i', strtotime($u['last_active'])) : 'Offline'; ?></span><?php endif; ?></td>
                            <td class="text-center pe-4 position-relative admin-action-cell">
                                <div class="dropdown admin-row-actions">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item fw-medium" href="#" onclick="openPermissionsModal(<?php echo $u['user_id']; ?>, '<?php echo addslashes(e($u['full_name'])); ?>', <?php echo htmlspecialchars($u_perms); ?>)"><i class="fas fa-sliders-h text-primary"></i> Capabilities</a></li>
                                        <li><a class="dropdown-item fw-medium" href="#" data-user-id="<?php echo (int)$u['user_id']; ?>" data-username="<?php echo e($u['username']); ?>" data-full-name="<?php echo e($u['full_name']); ?>" data-email="<?php echo e($u['email']); ?>" data-role="<?php echo e($u['role']); ?>" onclick="openEditUserModal(this); return false;"><i class="fas fa-user-edit text-success"></i> Edit Details</a></li>
                                        <?php if(isset($u['is_online']) && $u['is_online'] && $u['user_id'] != $_SESSION['user_id']): ?><li><a class="dropdown-item text-warning fw-medium" href="#" onclick="confirmForceLogout(<?php echo $u['user_id']; ?>)"><i class="fas fa-sign-out-alt"></i> Force Logout</a></li><?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php if ((int) $u['user_id'] !== (int) $_SESSION['user_id']): ?>
                                            <?php if ($u['status'] === 'Active'): ?><li><a class="dropdown-item text-warning fw-medium" href="#" onclick="confirmSuspend(<?php echo $u['user_id']; ?>)"><i class="fas fa-user-slash"></i> Suspend Account</a></li><?php else: ?><li><a class="dropdown-item text-success fw-medium" href="#" onclick="confirmUnsuspend(<?php echo $u['user_id']; ?>)"><i class="fas fa-user-check"></i> Reactivate Account</a></li><?php endif; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <form action="actions/user_handler.php" method="POST" id="force-logout-form-<?php echo $u['user_id']; ?>" class="d-none"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="force_logout"><input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>"></form>
                                <form action="actions/user_handler.php" method="POST" id="suspend-form-<?php echo $u['user_id']; ?>" class="d-none"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>"><input type="hidden" name="status" value="Suspended"></form>
                                <form action="actions/user_handler.php" method="POST" id="unsuspend-form-<?php echo $u['user_id']; ?>" class="d-none"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>"><input type="hidden" name="status" value="Active"></form>
                            </td>
                        </tr>
                        <?php endwhile; } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade sleek-modal" id="permissionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow">
            <div class="modal-header">
                <div><h5 class="modal-title fw-bold text-dark"><i class="fas fa-shield-alt text-primary me-2"></i> Manage Capabilities</h5><p class="text-muted small mb-0 mt-1" id="permModalSubtitle">Adjust specific access permissions for the user.</p></div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="actions/user_handler.php" method="POST" id="permissionsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="update_permissions"><input type="hidden" name="target_user_id" id="perm_target_user_id">
                    <div id="permissionsList"></div>
                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top"><button type="button" class="btn btn-light sleek-btn border px-4" data-bs-dismiss="modal">Discard</button><button type="submit" class="btn btn-primary sleek-btn px-4 fw-medium"><i class="fas fa-check me-2"></i> Apply Capabilities</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade sleek-modal" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header"><h5 class="modal-title fw-bold text-dark"><i class="fas fa-user-plus text-primary me-2"></i> Add New User</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form action="actions/user_handler.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="create_user">
                    <div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Full Name</label><input type="text" name="full_name" class="form-control form-control-lg bg-light" required minlength="2" maxlength="100" autocomplete="name" placeholder="e.g. John Doe"></div>
                    <div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Username</label><input type="text" name="username" class="form-control form-control-lg bg-light" required minlength="3" maxlength="50" pattern="[a-z0-9]+([._-][a-z0-9]+)*" autocomplete="username" autocapitalize="none" spellcheck="false" oninput="this.value = this.value.toLowerCase()" placeholder="e.g. john.doe"><div class="form-text">Use lowercase letters or numbers; period, underscore, and hyphen are allowed only as separators.</div></div>
                    <div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Recovery Email</label><input type="email" name="email" class="form-control form-control-lg bg-light" required maxlength="100" autocomplete="email" inputmode="email" spellcheck="false" placeholder="user@company.com"><div class="form-text">Must be valid and unique. Login and password recovery codes are sent here.</div></div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Assigned Role</label>
                        <select name="role" class="form-select form-select-lg bg-light" required>
                            <option value="Sales Staff">Sales Staff</option><option value="Finance">Finance</option><option value="Procurement">Procurement</option><option value="Supply Chain">Supply Chain</option><option value="GM">General Manager</option><option value="President">President</option><option value="Admin">System Administrator</option>
                        </select>
                        <div class="form-text mt-2"><i class="fas fa-info-circle text-primary"></i> The user will receive an email to securely set their password.</div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4"><button type="button" class="btn btn-light sleek-btn border" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary sleek-btn fw-medium">Send Invite</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade sleek-modal" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header"><h5 class="modal-title fw-bold text-dark"><i class="fas fa-edit text-success me-2"></i>Edit Account</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form action="actions/user_handler.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" id="edit_user_id">
                    <div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Full Name</label><input type="text" name="full_name" id="edit_full_name" class="form-control form-control-lg bg-light" required minlength="2" maxlength="100" autocomplete="name"></div>
                    <div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Recovery Email</label><input type="email" name="email" id="edit_email" class="form-control form-control-lg bg-light" required maxlength="100" autocomplete="email" inputmode="email" spellcheck="false"><div class="form-text">Required for OTP sign-in and password recovery. It cannot be shared by another user.</div></div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Assigned Role</label>
                        <select name="role" id="edit_role" class="form-select form-select-lg bg-light" required>
                            <option value="Admin">System Administrator</option><option value="President">President</option><option value="GM">General Manager</option><option value="Finance">Finance</option><option value="Procurement">Procurement</option><option value="Supply Chain">Supply Chain</option><option value="Sales Staff">Sales Staff</option>
                        </select>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4"><button type="button" id="sendResetCodeButton" class="btn btn-outline-warning sleek-btn"><i class="fas fa-key me-1"></i>Send Reset Code</button><button type="button" class="btn btn-light sleek-btn border" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success sleek-btn fw-medium">Update User</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<form action="actions/user_handler.php" method="POST" id="secureResetCodeForm" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="send_password_reset_code">
    <input type="hidden" name="user_id" id="reset_code_user_id">
</form>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    let table = $('#usersTable').DataTable({
        "ordering": false, "pageLength": 10, "lengthChange": false,
        "language": { "search": "", "searchPlaceholder": "Search users...", "info": "<span class='text-muted small'>Showing _START_ to _END_ of _TOTAL_ users</span>", "paginate": { "previous": "<i class='fas fa-chevron-left'></i>", "next": "<i class='fas fa-chevron-right'></i>" } },
        "dom": '<"admin-table-scroll"t><"admin-pagination-bar d-flex justify-content-between align-items-center border-top"ip>'
    });
    $('#customSearchInput').on('keyup', function() { table.search(this.value).draw(); });
    $('#roleFilter').on('change', function() { table.column(2).search(this.value).draw(); });
});

const allPermissions = <?php echo $all_perms_json; ?>; let selectedUserForReset = null;

function openEditUserModal(trigger) {
    selectedUserForReset = { id: trigger.dataset.userId, username: trigger.dataset.username, email: trigger.dataset.email };
    document.getElementById('edit_user_id').value = selectedUserForReset.id; document.getElementById('edit_full_name').value = trigger.dataset.fullName; document.getElementById('edit_email').value = trigger.dataset.email; document.getElementById('edit_role').value = trigger.dataset.role; document.getElementById('sendResetCodeButton').style.display = String(selectedUserForReset.id) === '<?php echo (int)$_SESSION['user_id']; ?>' ? 'none' : '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
}

document.getElementById('sendResetCodeButton').addEventListener('click', function () {
    if (!selectedUserForReset) return;
    const username = selectedUserForReset.username;
    Swal.fire({
        title: 'Send secure reset code?',
        text: 'This will revoke active sessions and previous recovery codes for @' + username + ', then email a six-digit code valid for 10 minutes to ' + (selectedUserForReset.email || 'the saved recovery email') + '. The current password changes only after the code is verified.',
        icon: 'warning',
        showCancelButton: true,
        reverseButtons: true,
        confirmButtonText: '<i class="fas fa-key me-1"></i> Send Reset Code',
        cancelButtonText: 'Cancel',
        buttonsStyling: false,
        customClass: { popup: 'sleek-popup', confirmButton: 'btn btn-warning sleek-btn', cancelButton: 'btn btn-light border sleek-btn' }
    }).then(result => {
        if (!result.isConfirmed) return;
        document.getElementById('reset_code_user_id').value = selectedUserForReset.id;
        document.getElementById('secureResetCodeForm').submit();
    });
});

function openPermissionsModal(userId, userName, userPerms) {
    document.getElementById('perm_target_user_id').value = userId; document.getElementById('permModalSubtitle').innerText = "Toggle functional access capabilities for " + userName;
    let html = '<div class="permission-grid">';
    allPermissions.forEach(p => {
        let isChecked = userPerms.includes(p.permission_name) ? 'checked' : '';
        html += `<div class="permission-row bg-white"><div class="pe-3"><div class="permission-title text-dark">${escapeHtml(formatPermName(p.permission_name))}</div><div class="permission-desc text-muted">${escapeHtml(p.description || '')}</div></div><label class="sleek-switch mb-0 flex-shrink-0"><input type="checkbox" name="permissions[]" value="${escapeHtml(p.permission_name)}" ${isChecked}><span class="sleek-slider border shadow-sm"></span></label></div>`;
    });
    html += '</div>'; document.getElementById('permissionsList').innerHTML = html;
    new bootstrap.Modal(document.getElementById('permissionsModal')).show();
}
function formatPermName(name) { return name.replace('can_', '').split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' '); }
function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }

$('#permissionsForm').on('submit', function(e) { e.preventDefault(); let form = this; Swal.fire({ title: 'Apply Capabilities?', text: "Are you sure you want to update this user's system access?", icon: 'question', showCancelButton: true, confirmButtonColor: '#3b82f6', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Apply Changes', customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn btn-primary', cancelButton: 'sleek-btn border bg-light text-dark' } }).then((result) => { if (result.isConfirmed) { form.submit(); } }); });

<?php if(!empty($toastMsg)): ?>
const Toast = Swal.mixin({ toast: true, position: 'bottom-end', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'sleek-popup small-toast shadow-sm border' } });
Toast.fire({ icon: <?php echo json_encode($toastType); ?>, title: <?php echo json_encode($toastMsg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?> });
<?php endif; ?>

function confirmSuspend(id) { Swal.fire({ title: 'Suspend account?', text: "The user will not be able to log in.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#f59e0b', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, suspend', customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn text-white', cancelButton: 'sleek-btn border bg-light text-dark' } }).then((result) => { if (result.isConfirmed) { document.getElementById('suspend-form-' + id).submit(); } }) }
function confirmUnsuspend(id) { Swal.fire({ title: 'Reactivate account?', text: "The user will regain access to the system.", icon: 'success', showCancelButton: true, confirmButtonColor: '#10b981', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, reactivate', customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn', cancelButton: 'sleek-btn border bg-light text-dark' } }).then((result) => { if (result.isConfirmed) { document.getElementById('unsuspend-form-' + id).submit(); } }) }
function confirmForceLogout(id) { Swal.fire({ title: 'Force Logout?', text: "The user's active session will be terminated.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#1e293b', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Force Logout', customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn text-white', cancelButton: 'sleek-btn border bg-light text-dark' } }).then((result) => { if (result.isConfirmed) { document.getElementById('force-logout-form-' + id).submit(); } }) }
</script>
</body>
</html>
