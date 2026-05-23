<?php 
require 'config/db_connect.php'; 
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$query = $conn->query("SELECT * FROM users WHERE user_id = $user_id");
if ($query->num_rows == 0) {
    session_destroy();
    header("Location: index.php");
    exit();
}
$user = $query->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Settings - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="mb-5">
            <h2 class="fw-bold mb-1">Account Settings</h2>
            <p class="text-muted mb-0">Manage your profile.</p>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i> 
                <?php 
                if($_GET['success'] == 'CodeSent') echo "A 6-digit verification code has been sent to your new email.";
                elseif($_GET['success'] == 'EmailVerified') echo "Email successfully verified and updated!";
                elseif($_GET['success'] == 'PasswordUpdated') echo "Your password has been successfully updated!";
                elseif($_GET['success'] == 'RequestSubmitted') echo "Your username change request has been sent to the Admin.";
                else echo "Action completed successfully!";
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i> Error: 
                <?php 
                if($_GET['error'] == 'InvalidCode') echo "The verification code is incorrect or has expired.";
                elseif($_GET['error'] == 'EmailAlreadyInUse') echo "That email address is already in use by another account.";
                elseif($_GET['error'] == 'WrongCurrentPassword') echo "The current password you entered is incorrect.";
                elseif($_GET['error'] == 'PasswordMismatch') echo "The new passwords do not match.";
                elseif($_GET['error'] == 'WeakPassword' || $_GET['error'] == 'WeakPasswordAdmin') echo "Password must be at least 8 characters long, contain an uppercase letter, a lowercase letter, and a number.";
                else echo htmlspecialchars($_GET['error']); 
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white pt-4 border-0">
                        <h5 class="fw-bold text-primary"><i class="fas fa-user-circle me-2"></i> User Profile</h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <div class="d-flex align-items-center p-3 bg-light rounded-3 border">
                            <div class="position-relative me-3">
                                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm overflow-hidden border" style="width: 80px; height: 80px; font-size: 2rem;">
                                    <?php if(!empty($user['avatar']) && file_exists($user['avatar'])): ?>
                                        <img src="download.php?file=<?php echo basename($user['avatar']); ?>&type=avatar" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                                <span class="badge bg-secondary text-uppercase mb-2"><?php echo $user['role']; ?></span>
                                <p class="small text-muted mb-2">Username: <strong><?php echo htmlspecialchars($user['username']); ?></strong></p>
                                <form action="actions/user_handler.php" method="POST" enctype="multipart/form-data" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="upload_avatar">
                                    <input type="file" name="avatar" id="avatarInput" class="d-none" accept="image/*" onchange="this.form.submit()">
                                    <button type="button" class="btn btn-sm btn-outline-primary bg-white" onclick="document.getElementById('avatarInput').click()">
                                        <i class="fas fa-camera me-1"></i> Change Photo
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white pt-4 border-0">
                        <h5 class="fw-bold text-info"><i class="fas fa-address-card me-2"></i> Basic Information</h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        
                        <?php if(!empty($user['pending_email'])): ?>
                            <div class="alert alert-warning p-3 mb-4">
                                <h6 class="fw-bold mb-1"><i class="fas fa-envelope-open-text me-1"></i> Verify Your Email</h6>
                                <p class="small mb-2">We sent a 6-digit code to <strong><?php echo htmlspecialchars($user['pending_email']); ?></strong>.</p>
                                <form action="actions/user_handler.php" method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="verify_email_code">
                                    <input type="text" name="verification_code" class="form-control form-control-sm text-center fw-bold" placeholder="000000" maxlength="6" required style="letter-spacing: 5px;">
                                    <button type="submit" class="btn btn-sm btn-success fw-bold px-3">Verify</button>
                                </form>
                                <form action="actions/user_handler.php" method="POST" class="mt-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="cancel_email_change">
                                    <button type="submit" class="btn btn-link text-danger p-0 small" style="font-size: 0.8rem; text-decoration: none;">Cancel email change</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <form action="actions/user_handler.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="update_basic_info">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter email to enable recovery" required>
                                </div>
                                <?php if(empty($user['email'])): ?>
                                    <small class="text-danger mt-1 d-block"><i class="fas fa-exclamation-triangle"></i> Set and verify your email to enable password recovery.</small>
                                <?php elseif(empty($user['pending_email'])): ?>
                                    <small class="text-success mt-1 d-block"><i class="fas fa-check-circle"></i> Email Verified</small>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-info text-white w-100 fw-bold">Save Information</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white pt-4 border-0">
                        <h5 class="fw-bold text-warning"><i class="fas fa-lock me-2"></i> Change Password</h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <form action="actions/user_handler.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="change_password_direct">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Current Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span>
                                    <input type="password" name="current_password" id="currPass" class="form-control border-end-0" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" onclick="togglePass('currPass', 'iconCurr')">
                                        <i class="fas fa-eye" id="iconCurr"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-shield-alt text-muted"></i></span>
                                    <input type="password" name="new_password" id="newPass" class="form-control border-end-0" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" onclick="togglePass('newPass', 'iconNew')">
                                        <i class="fas fa-eye" id="iconNew"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-check text-muted"></i></span>
                                    <input type="password" name="confirm_password" id="confirmNewPass" class="form-control border-end-0" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" onclick="togglePass('confirmNewPass', 'iconConfirm')">
                                        <i class="fas fa-eye" id="iconConfirm"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded border mb-4">
                                <p class="small fw-bold mb-2 text-dark">Password Requirements:</p>
                                <ul class="list-unstyled small mb-0" id="changePassReqs">
                                    <li id="req-change-length" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 8 characters</li>
                                    <li id="req-change-upper" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 uppercase letter (A-Z)</li>
                                    <li id="req-change-lower" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 lowercase letter (a-z)</li>
                                    <li id="req-change-num" class="text-danger mb-1"><i class="fas fa-times me-2"></i>At least 1 number (0-9)</li>
                                    <li id="req-change-match" class="text-danger"><i class="fas fa-times me-2"></i>Passwords match</li>
                                </ul>
                            </div>

                            <button type="submit" class="btn btn-warning w-100 fw-bold text-dark" id="btn-update-password" disabled>Update Password</button>
                        </form>
                    </div>
                </div>

                <?php if($role !== 'Admin'): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white pt-4 border-0">
                        <h5 class="fw-bold text-secondary"><i class="fas fa-id-badge me-2"></i> Request Username Change</h5>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <p class="small text-muted mb-4">Username changes affect system audit logs and must be approved by the Administrator.</p>
                        
                        <form action="actions/request_handler.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="submit_request">
                            <input type="hidden" name="request_type" value="Change Username">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Desired New Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-at text-muted"></i></span>
                                    <input type="text" name="new_value" class="form-control" required placeholder="Enter new username">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-danger">Verify Current Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" name="current_password" id="reqCurrPass" class="form-control border-end-0" required placeholder="Required for security">
                                    <button class="btn border border-start-0 text-secondary" type="button" onclick="togglePass('reqCurrPass', 'iconReqCurr')">
                                        <i class="fas fa-eye" id="iconReqCurr"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-outline-secondary w-100 fw-bold">Submit Request to Admin</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
        
        $(document).ready(function() {
            function validateSettingsPassword() {
                let pass = $('#newPass').val();
                let confirm = $('#confirmNewPass').val();

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

                toggleReq('req-change-length', lengthValid);
                toggleReq('req-change-upper', upperValid);
                toggleReq('req-change-lower', lowerValid);
                toggleReq('req-change-num', numValid);
                toggleReq('req-change-match', matchValid);

                if (lengthValid && upperValid && lowerValid && numValid && matchValid) {
                    $('#btn-update-password').prop('disabled', false);
                } else {
                    $('#btn-update-password').prop('disabled', true);
                }
            }

            $('#newPass, #confirmNewPass').on('input', validateSettingsPassword);
        });
    </script>
</body>
</html>