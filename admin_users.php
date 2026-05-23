<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Users - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">User Management</h2>
                <p class="text-muted mb-0">Create and manage system access.</p>
            </div>
            <button class="btn btn-primary px-4 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i> Add New User
            </button>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i> Action completed successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i> Error: 
                <?php 
                if($_GET['error'] == 'WeakPassword') echo "Password must be at least 8 characters long, contain an uppercase letter, a lowercase letter, and a number.";
                else echo htmlspecialchars($_GET['error']); 
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm p-3">
            <div class="table-responsive">
                <table id="usersTable" class="table table-hover align-middle mb-0" style="width:100%;">
                    <thead class="bg-light text-uppercase small text-secondary">
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $users = get_all_users($conn);
                        while($u = $users->fetch_assoc()): 
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary border" style="width: 40px; height: 40px; overflow: hidden;">
                                        <?php if(!empty($u['avatar']) && file_exists($u['avatar'])): ?>
                                            <img src="download.php?file=<?php echo basename($u['avatar']); ?>&type=avatar" class="rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <span class="fw-bold"><?php echo strtoupper(substr($u['full_name'], 0, 1)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                        <small class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo $u['role']; ?></span></td>
                            <td>
                                <?php if($u['status'] === 'Active'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success px-3">Active</span>
                                <?php elseif($u['status'] === 'Pending_Approval'): ?>
                                    <span class="badge bg-danger px-3">Locked (Pending)</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary px-3"><?php echo $u['status']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                            <td class="text-end pe-4">
                                <?php if($u['user_id'] != $_SESSION['user_id']): ?>
                                    <form action="actions/user_handler.php" method="POST" onsubmit="return confirm('Delete this user?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php else: ?>
                                    <small class="text-muted fst-italic">Current User</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/user_handler.php" method="POST" id="createUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="create_user">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="Sales Staff">Sales Staff</option>
                                <option value="Procurement">Procurement</option>
                                <option value="Finance">Finance</option>
                                <option value="GM">General Manager</option>
                                <option value="President">President</option>
                                <option value="Supply Chain">Supply Chain</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="create_password" class="form-control" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#create_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="p-3 bg-light rounded border">
                            <p class="small fw-bold mb-2 text-dark">Password Requirements:</p>
                            <ul class="list-unstyled small mb-0" id="createUserPassReqs">
                                <li id="req-create-length" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 8 characters</li>
                                <li id="req-create-upper" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 uppercase letter (A-Z)</li>
                                <li id="req-create-lower" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 lowercase letter (a-z)</li>
                                <li id="req-create-num" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 number (0-9)</li>
                                <li id="req-create-match" class="text-danger"><i class="fas fa-times me-2"></i>Passwords match</li>
                            </ul>
                        </div>

                    </div>
                    
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-create-user" disabled>Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable({
                "pageLength": 10,
                "language": {
                    "search": "Filter Users:"
                }
            });

            $('.toggle-password').click(function() {
                let targetInput = $($(this).data('target'));
                let icon = $(this).find('i');
                
                if (targetInput.attr('type') === 'password') {
                    targetInput.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    targetInput.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });

            function validateCreatePassword() {
                let pass = $('#create_password').val();
                let confirm = $('#confirm_password').val();

                let lengthValid = pass.length >= 8;
                let upperValid = /[A-Z]/.test(pass);
                let lowerValid = /[a-z]/.test(pass);
                let numValid = /[0-9]/.test(pass);
                let matchValid = (pass === confirm) && (pass.length > 0);

                function toggleReq(id, isValid) {
                    let el = $('#' + id);
                    if (isValid) {
                        el.removeClass('text-danger').addClass('text-success');
                        el.find('i').removeClass('fa-times').addClass('fa-check');
                    } else {
                        el.removeClass('text-success').addClass('text-danger');
                        el.find('i').removeClass('fa-check').addClass('fa-times');
                    }
                }

                toggleReq('req-create-length', lengthValid);
                toggleReq('req-create-upper', upperValid);
                toggleReq('req-create-lower', lowerValid);
                toggleReq('req-create-num', numValid);
                toggleReq('req-create-match', matchValid);

                if (lengthValid && upperValid && lowerValid && numValid && matchValid) {
                    $('#btn-create-user').prop('disabled', false);
                } else {
                    $('#btn-create-user').prop('disabled', true);
                }
            }

            $('#create_password, #confirm_password').on('input', validateCreatePassword);
        });
    </script>
</body>
</html>