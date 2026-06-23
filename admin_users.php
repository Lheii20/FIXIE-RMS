<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

// ===============================================
// AUTO-PATCH: Add missing columns for Online Presence
// ===============================================
$check_col_activity = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
if ($check_col_activity && $check_col_activity->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN last_activity DATETIME NULL");
}
$check_col_session = $conn->query("SHOW COLUMNS FROM users LIKE 'session_token'");
if ($check_col_session && $check_col_session->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN session_token VARCHAR(255) NULL");
}

// Ligtas na Online Presence Update
if (isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $conn->query("UPDATE users SET last_activity = NOW() WHERE user_id = $uid");
}

// BINALIK SA ADMIN ANG STRICT ACCESS PARA SA USER MANAGEMENT
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

$toastMsg = '';
$toastType = '';
if(isset($_GET['success'])) {
    $toastType = 'success';
    if($_GET['success'] == 'UserUpdated') $toastMsg = 'User information updated successfully.';
    elseif($_GET['success'] == 'UserDeleted') $toastMsg = 'User deleted successfully.';
    elseif($_GET['success'] == 'UserCreated') $toastMsg = 'User created & temporary password emailed.';
    elseif($_GET['success'] == 'UserStatusUpdated') $toastMsg = 'User account status updated.';
    elseif($_GET['success'] == 'UserForceLoggedOut') $toastMsg = 'The user session was forcefully terminated.';
    elseif($_GET['success'] == 'PermissionsUpdated') $toastMsg = 'User access capabilities updated seamlessly.';
    elseif($_GET['success'] == 'UserCreatedButEmailFailed') { 
        $toastType = 'warning'; 
        $toastMsg = 'User created but system failed to send email. Check SMTP setup.'; 
    }
    else $toastMsg = 'Action completed successfully.'; 
} elseif(isset($_GET['error'])) {
    $toastType = 'error';
    if($_GET['error'] == 'WeakPassword') $toastMsg = 'Password must be 8+ chars with uppercase, lowercase, and a number.';
    elseif($_GET['error'] == 'CannotChangeAdminRole') $toastMsg = 'Security Violation: Admin role cannot be demoted.';
    elseif($_GET['error'] == 'CannotDeleteSelf') $toastMsg = 'You cannot delete your own account.';
    elseif($_GET['error'] == 'CannotSuspendSelf') $toastMsg = 'You cannot suspend your own account.';
    elseif($_GET['error'] == 'UserOrEmailExists') $toastMsg = 'Username or Email is already registered.';
    elseif($_GET['error'] == 'UpdateFailed') $toastMsg = 'Failed to update system permissions.';
    else $toastMsg = htmlspecialchars($_GET['error']); 
}

// ===============================================
// FIX: PWERSAHANG TATAWAGIN ANG FUNCTION PARA MA-INJECT ANG BAGONG CAPABILITIES
// ===============================================
ensure_rbac_tables_exist($conn);

// Fetch user permissions for JS integration
$user_perms = [];
$perm_query = $conn->query("SELECT user_id, permission_name FROM user_permissions");
if ($perm_query) {
    while($p = $perm_query->fetch_assoc()) {
        $user_perms[$p['user_id']][] = $p['permission_name'];
    }
}

$all_permissions = [];
// Hindi na isasama ang can_manage_users dahil pinalitan na natin ng can_view_audit_logs
$all_p_query = $conn->query("SELECT * FROM permissions WHERE permission_name != 'can_manage_users'");
if ($all_p_query) {
    while($p = $all_p_query->fetch_assoc()) {
        $all_permissions[] = $p;
    }
}
$all_perms_json = json_encode($all_permissions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Users - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .small-toast { font-size: 0.85rem !important; padding: 0.5rem !important; }
        .sleek-popup { border-radius: 12px !important; }
        .sleek-btn { padding: 0.4rem 1.2rem !important; font-size: 0.9rem !important; border-radius: 6px !important; }
        
        .online-dot { width: 12px; height: 12px; }
        
        /* Modern Filter Bar Styling */
        .sleek-search-container { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .sleek-search-input { background: #f8fafc; border: none; box-shadow: none; font-size: 0.9rem; }
        .sleek-search-input:focus { background: #ffffff; box-shadow: none; border-color: #3b82f6; }
        .sleek-input-group { border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #f8fafc; transition: all 0.2s; }
        .sleek-input-group:focus-within { border-color: #3b82f6; background: #ffffff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        /* Dropdown Fixes */
        .table-responsive { overflow: visible !important; }
        .dataTables_wrapper { overflow: visible !important; }
        .card { overflow: visible !important; }

        .btn-dots { background: #ffffff; border: 1px solid #e2e8f0; color: #64748b; width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .btn-dots:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .dropdown-menu { z-index: 1050 !important; border: 1px solid #e2e8f0; box-shadow: 0 10px 20px -5px rgba(0,0,0,0.15); border-radius: 12px; padding: 0.5rem; min-width: 180px; }
        .dropdown-item { padding: 0.55rem 0.75rem; border-radius: 6px; font-size: 0.88rem; font-weight: 500; color: #334155; display: flex; align-items: center; gap: 12px; transition: 0.2s; cursor: pointer; }
        a.dropdown-item:hover { background-color: #f8fafc; color: #0f172a; transform: translateX(2px); }
        
        /* Modern Sleek Toggle Switch */
        .sleek-switch { position: relative; display: inline-block; width: 44px; height: 24px; margin-bottom: 0; flex-shrink: 0; cursor: pointer; }
        .sleek-switch input { opacity: 0; width: 0; height: 0; }
        .sleek-slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .3s; border-radius: 24px; }
        .sleek-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        input:checked + .sleek-slider { background-color: #10b981; }
        input:checked + .sleek-slider:before { transform: translateX(20px); }
        
        /* Permissions Grid UX */
        .permission-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 768px) { .permission-grid { grid-template-columns: 1fr; } }
        .permission-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 12px; transition: background-color 0.2s, border-color 0.2s; }
        .permission-row:hover { background-color: #f8fafc; border-color: #cbd5e1; }
        .permission-title { font-weight: 600; font-size: 0.92rem; margin-bottom: 3px; }
        .permission-desc { font-size: 0.78rem; line-height: 1.3; }

        /* DataTables Customization */
        table.dataTable { border-collapse: collapse !important; }
        table.dataTable thead th { border-bottom: 2px solid #e2e8f0 !important; color: #64748b; font-weight: 600; letter-spacing: 0.5px; }
        table.dataTable tbody td { border-bottom: 1px solid #f1f5f9; padding: 1rem 0.75rem; vertical-align: middle; transition: background 0.2s; }
        table.dataTable tbody tr:hover td { background-color: #f8fafc; }
    </style>
</head>
<body style="background-color: #f8f9fa;">

<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-dark" style="letter-spacing: -0.5px;"><i class="fas fa-users-cog text-primary me-2"></i>User Management</h2>
            <p class="text-muted mb-0 small">Administer user accounts, security tokens, and dynamic system permissions.</p>
        </div>
        <button class="btn btn-primary fw-medium px-4 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal" style="border-radius: 8px;">
            <i class="fas fa-plus me-2"></i> Add New User
        </button>
    </div>

    <div class="sleek-search-container">
        <div class="d-flex align-items-center gap-2" style="flex: 1; max-width: 350px;">
            <div class="input-group sleek-input-group">
                <span class="input-group-text border-0 bg-transparent text-muted px-3"><i class="fas fa-search"></i></span>
                <input type="text" id="customSearchInput" class="form-control sleek-search-input px-0" placeholder="Search by name, username, or email...">
            </div>
        </div>
        
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Filter Role:</span>
            <select id="roleFilter" class="form-select shadow-none bg-light" style="width: 200px; border-radius: 8px; font-size: 0.9rem; border: 1px solid #cbd5e1;">
                <option value="">All Roles</option>
                <option value="Admin">Admin</option>
                <option value="President">President</option>
                <option value="GM">General Manager</option>
                <option value="Finance">Finance</option>
                <option value="Procurement">Procurement</option>
                <option value="Supply Chain">Supply Chain</option>
                <option value="Sales">Sales Staff / End User</option>
            </select>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-0" style="min-height: 400px; padding-bottom: 60px !important;">
            <div class="table-responsive">
                <table class="table w-100" id="usersTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">User Identity</th>
                            <th>Username / Email</th>
                            <th>Assigned Role</th>
                            <th>Account Status</th>
                            <th>Presence</th>
                            <th class="text-center pe-4" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $query = "SELECT *, (last_activity >= NOW() - INTERVAL 5 MINUTE) as is_online FROM users ORDER BY is_online DESC, full_name ASC";
                        $users = $conn->query($query);
                        if ($users) {
                            while($u = $users->fetch_assoc()):
                                $u_perms = isset($user_perms[$u['user_id']]) ? json_encode($user_perms[$u['user_id']]) : '[]';
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="position-relative d-inline-block">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary border shadow-sm" style="width: 44px; height: 44px; overflow: hidden;">
                                            <?php if(!empty($u['avatar']) && file_exists($u['avatar'])): ?>
                                                <img src="<?php echo htmlspecialchars($u['avatar']); ?>" class="w-100 h-100 object-fit-cover" alt="Avatar">
                                            <?php else: ?>
                                                <span class="fw-bold" style="font-size: 1.1rem;"><?php echo strtoupper(substr($u['full_name'], 0, 1)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if(isset($u['is_online']) && $u['is_online']): ?>
                                            <span class="position-absolute bottom-0 end-0 bg-success border border-2 border-white rounded-circle online-dot"></span>
                                        <?php else: ?>
                                            <span class="position-absolute bottom-0 end-0 bg-secondary border border-2 border-white rounded-circle online-dot"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold text-dark"><?php echo e($u['full_name']); ?></h6>
                                        <span class="badge bg-light text-secondary border mt-1">ID: #<?php echo $u['user_id']; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="text-dark fw-medium"><?php echo e($u['username']); ?></div>
                                <div class="text-muted small"><i class="fas fa-envelope me-1"></i><?php echo e($u['email']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1 rounded-3">
                                    <i class="fas fa-id-badge me-1"></i> <?php echo e($u['role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'Active'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-2 py-1 rounded-3">
                                        <i class="fas fa-check-circle me-1"></i> Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 rounded-3">
                                        <i class="fas fa-ban me-1"></i> Suspended
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(isset($u['is_online']) && $u['is_online']): ?>
                                    <span class="text-success fw-medium small"><i class="fas fa-wifi me-1"></i> Online</span>
                                <?php else: ?>
                                    <span class="text-secondary small"><i class="fas fa-history me-1"></i> <?php echo (!empty($u['last_activity']) && $u['last_activity'] !== '0000-00-00 00:00:00') ? date('M d, H:i', strtotime($u['last_activity'])) : 'Offline'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4 position-relative">
                                <div class="dropdown">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li>
                                            <a class="dropdown-item fw-medium" href="#" onclick="openPermissionsModal(<?php echo $u['user_id']; ?>, '<?php echo addslashes(e($u['full_name'])); ?>', <?php echo htmlspecialchars($u_perms); ?>)">
                                                <i class="fas fa-sliders-h text-primary"></i> Capabilities
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $u['user_id']; ?>">
                                                <i class="fas fa-user-edit text-success"></i> Edit Details
                                            </a>
                                        </li>
                                        <?php if(isset($u['is_online']) && $u['is_online'] && $u['user_id'] != $_SESSION['user_id']): ?>
                                        <li>
                                            <a class="dropdown-item text-warning fw-medium" href="#" onclick="confirmForceLogout(<?php echo $u['user_id']; ?>)">
                                                <i class="fas fa-sign-out-alt"></i> Force Logout
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php if ($u['status'] === 'Active'): ?>
                                        <li>
                                            <a class="dropdown-item text-warning fw-medium" href="#" onclick="confirmSuspend(<?php echo $u['user_id']; ?>)">
                                                <i class="fas fa-user-slash"></i> Suspend Account
                                            </a>
                                        </li>
                                        <?php else: ?>
                                        <li>
                                            <a class="dropdown-item text-success fw-medium" href="#" onclick="confirmUnsuspend(<?php echo $u['user_id']; ?>)">
                                                <i class="fas fa-user-check"></i> Unlock Account
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li>
                                            <a class="dropdown-item text-danger fw-bold" href="#" onclick="confirmDelete(<?php echo $u['user_id']; ?>)">
                                                <i class="fas fa-trash-alt"></i> Delete User
                                            </a>
                                        </li>
                                    </ul>
                                </div>

                                <form action="actions/user_handler.php" method="POST" id="force-logout-form-<?php echo $u['user_id']; ?>" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="force_logout">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                </form>
                                <form action="actions/user_handler.php" method="POST" id="delete-form-<?php echo $u['user_id']; ?>" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                </form>
                                <form action="actions/user_handler.php" method="POST" id="suspend-form-<?php echo $u['user_id']; ?>" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <input type="hidden" name="status" value="Suspended">
                                </form>
                                <form action="actions/user_handler.php" method="POST" id="unsuspend-form-<?php echo $u['user_id']; ?>" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <input type="hidden" name="status" value="Active">
                                </form>
                            </td>
                        </tr>

                        <div class="modal fade" id="editUserModal<?php echo $u['user_id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content sleek-popup border-0 shadow">
                                    <div class="modal-header border-bottom-0 pb-0">
                                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-edit text-success me-2"></i> Edit Account</h5>
                                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body pt-3">
                                        <form action="actions/user_handler.php" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold text-uppercase">Full Name</label>
                                                <input type="text" name="full_name" class="form-control form-control-lg bg-light" value="<?php echo e($u['full_name']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold text-uppercase">Email</label>
                                                <input type="email" name="email" class="form-control form-control-lg bg-light" value="<?php echo e($u['email']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold text-uppercase">Assigned Role</label>
                                                <select name="role" class="form-select form-select-lg bg-light" required>
                                                    <option value="Admin" <?php if($u['role'] == 'Admin') echo 'selected'; ?>>System Administrator</option>
                                                    <option value="President" <?php if($u['role'] == 'President') echo 'selected'; ?>>President</option>
                                                    <option value="GM" <?php if($u['role'] == 'GM') echo 'selected'; ?>>General Manager</option>
                                                    <option value="Finance" <?php if($u['role'] == 'Finance') echo 'selected'; ?>>Finance</option>
                                                    <option value="Sales" <?php if($u['role'] == 'Sales') echo 'selected'; ?>>Sales / End User</option>
                                                    <option value="Auditor" <?php if($u['role'] == 'Auditor') echo 'selected'; ?>>Auditor</option>
                                                </select>
                                            </div>
                                            <div class="d-flex justify-content-end gap-2 mt-4">
                                                <button type="button" class="btn btn-light sleek-btn border" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success sleek-btn fw-medium">Update User</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endwhile; 
                        } 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="permissionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-shield-alt text-primary me-2"></i> Manage Capabilities</h5>
                    <p class="text-muted small mb-0 mt-1" id="permModalSubtitle">Adjust specific access permissions for the user.</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3 pb-4">
                <form action="actions/user_handler.php" method="POST" id="permissionsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="target_user_id" id="perm_target_user_id">
                    
                    <div id="permissionsList" class="mt-2">
                        </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light sleek-btn border px-4" data-bs-dismiss="modal">Discard</button>
                        <button type="submit" class="btn btn-primary sleek-btn px-4 fw-medium"><i class="fas fa-check me-2"></i> Apply Capabilities</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-user-plus text-primary me-2"></i> Add New User</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="actions/user_handler.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_user">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Full Name</label>
                        <input type="text" name="full_name" class="form-control form-control-lg bg-light" required placeholder="e.g. John Doe">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Username</label>
                        <input type="text" name="username" class="form-control form-control-lg bg-light" required placeholder="johndoe123">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-lg bg-light" required placeholder="user@company.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Assigned Role</label>
                        <select name="role" class="form-select form-select-lg bg-light" required>
                            <option value="Sales">Sales / End User</option>
                            <option value="Finance">Finance</option>
                            <option value="Procurement">Procurement</option>
                            <option value="GM">General Manager</option>
                            <option value="President">President</option>
                            <option value="Admin">System Administrator</option>
                            <option value="Auditor">Auditor</option>
                        </select>
                        <div class="form-text mt-2"><i class="fas fa-info-circle text-primary"></i> The user will receive an email to securely set their password.</div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-light sleek-btn border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn fw-medium">Send Invite</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    let table = $('#usersTable').DataTable({
        "ordering": false,
        "pageLength": 10,
        "lengthChange": false,
        "language": {
            "search": "",
            "searchPlaceholder": "Search users...",
            "info": "<span class='text-muted small'>Showing _START_ to _END_ of _TOTAL_ users</span>",
            "paginate": {
                "previous": "<i class='fas fa-chevron-left'></i>",
                "next": "<i class='fas fa-chevron-right'></i>"
            }
        },
        "dom": 'rt<"d-flex justify-content-between align-items-center mt-3"ip>'
    });
    
    // Connect custom search input
    $('#customSearchInput').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Connect custom role filter (Column index 2 is Assigned Role)
    $('#roleFilter').on('change', function() {
        table.column(2).search(this.value).draw();
    });
});

const allPermissions = <?php echo $all_perms_json; ?>;

function openPermissionsModal(userId, userName, userPerms) {
    document.getElementById('perm_target_user_id').value = userId;
    document.getElementById('permModalSubtitle').innerText = "Toggle functional access capabilities for " + userName;
    
    let html = '<div class="permission-grid">';
    allPermissions.forEach(p => {
        let isChecked = userPerms.includes(p.permission_name) ? 'checked' : '';
        html += `
        <div class="permission-row bg-white">
            <div class="pe-3">
                <div class="permission-title text-dark">${formatPermName(p.permission_name)}</div>
                <div class="permission-desc text-muted">${p.description}</div>
            </div>
            <label class="sleek-switch mb-0 flex-shrink-0">
                <input type="checkbox" name="permissions[]" value="${p.permission_name}" ${isChecked}>
                <span class="sleek-slider border shadow-sm"></span>
            </label>
        </div>`;
    });
    html += '</div>';
    
    document.getElementById('permissionsList').innerHTML = html;
    new bootstrap.Modal(document.getElementById('permissionsModal')).show();
}

function formatPermName(name) {
    return name.replace('can_', '').split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

// Intercept Permissions Form Submit with "Are you sure?"
$('#permissionsForm').on('submit', function(e) {
    e.preventDefault();
    let form = this;
    Swal.fire({
        title: 'Apply Capabilities?',
        text: "Are you sure you want to update this user's system access?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Apply Changes',
        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn btn-primary', cancelButton: 'sleek-btn border bg-light text-dark' }
    }).then((result) => {
        if (result.isConfirmed) { form.submit(); }
    });
});

// BOTTOM-RIGHT SweetAlert Notifications
<?php if(!empty($toastMsg)): ?>
const Toast = Swal.mixin({
    toast: true, position: 'bottom-end', showConfirmButton: false, timer: 4000, timerProgressBar: true,
    customClass: { popup: 'sleek-popup small-toast shadow-sm border' }
});
Toast.fire({ icon: '<?php echo $toastType; ?>', title: '<?php echo $toastMsg; ?>' });
<?php endif; ?>

function confirmDelete(id) {
    Swal.fire({
        title: 'Delete this user?',
        text: "This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn', cancelButton: 'sleek-btn border bg-light text-dark' }
    }).then((result) => { if (result.isConfirmed) { document.getElementById('delete-form-' + id).submit(); } })
}

function confirmSuspend(id) {
    Swal.fire({
        title: 'Suspend account?',
        text: "The user will not be able to log in.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, suspend',
        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn text-white', cancelButton: 'sleek-btn border bg-light text-dark' }
    }).then((result) => { if (result.isConfirmed) { document.getElementById('suspend-form-' + id).submit(); } })
}

function confirmUnsuspend(id) {
    Swal.fire({
        title: 'Unlock account?',
        text: "The user will regain access to the system.",
        icon: 'success',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, unlock',
        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn', cancelButton: 'sleek-btn border bg-light text-dark' }
    }).then((result) => { if (result.isConfirmed) { document.getElementById('unsuspend-form-' + id).submit(); } })
}

function confirmForceLogout(id) {
    Swal.fire({
        title: 'Force Logout?',
        text: "The user's active session will be terminated.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1e293b',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Force Logout',
        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn text-white', cancelButton: 'sleek-btn border bg-light text-dark' }
    }).then((result) => { if (result.isConfirmed) { document.getElementById('force-logout-form-' + id).submit(); } })
}
</script>
</body>
</html>