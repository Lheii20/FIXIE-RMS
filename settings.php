<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require_once 'config/workflow_access.php';

drms_require_login();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$query = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$query->bind_param('i', $user_id);
$query->execute();
$user_result = $query->get_result();
if ($user_result->num_rows == 0) {
    session_destroy();
    header("Location: index.php");
    exit();
}
$user = $user_result->fetch_assoc();
$current_device_hash = drms_registry_key_hash();
$current_auth_hash = hash('sha256', (string) ($_SESSION['session_token'] ?? ''));
$session_window_minutes = (int) ($_SESSION['drms_session_timeout_minutes'] ?? 30);
if (!in_array($session_window_minutes, [15, 30, 60, 120], true)) $session_window_minutes = 30;
$session_cutoff = date('Y-m-d H:i:s', time() - $session_window_minutes * 60);
$security_sessions = [];
$session_query = $conn->prepare(
    'SELECT device_key_hash, auth_token_hash, ip_address, user_agent,
            signed_in_at, last_seen_at, ended_at, ended_reason
     FROM user_sessions WHERE user_id = ? ORDER BY signed_in_at DESC LIMIT 8'
);
$session_query->bind_param('i', $user_id);
$session_query->execute();
$session_result = $session_query->get_result();
while ($session_row = $session_result->fetch_assoc()) $security_sessions[] = $session_row;
$session_query->close();
$other_count_query = $conn->prepare(
    'SELECT COUNT(*) AS total FROM user_sessions
     WHERE user_id = ? AND BINARY device_key_hash <> BINARY ? AND BINARY auth_token_hash = BINARY ?
       AND ended_at IS NULL AND last_seen_at >= ?'
);
$other_count_query->bind_param('isss', $user_id, $current_device_hash, $current_auth_hash, $session_cutoff);
$other_count_query->execute();
$other_sessions_count = (int) ($other_count_query->get_result()->fetch_assoc()['total'] ?? 0);
$other_count_query->close();
$security_error_codes = ['SecurityTokenMismatch', 'SecurityPasswordRequired', 'WrongSecurityPassword', 'SecurityActionCooldown', 'InvalidSecurityAction', 'AccountUpdateFailed'];
$is_security_success = (string) ($_GET['success'] ?? '') === 'OtherDevicesSignedOut';
$security_error_code = (string) ($_GET['error'] ?? '');
$is_security_error = in_array($security_error_code, $security_error_codes, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Settings - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/mobile-settings-admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-settings-admin.css'); ?>" rel="stylesheet">
    <link href="assets/css/account-security.css?v=<?php echo filemtime(__DIR__ . '/assets/css/account-security.css'); ?>" rel="stylesheet">
    <link href="assets/css/e-signature.css?v=<?php echo file_exists(__DIR__ . '/assets/css/e-signature.css') ? filemtime(__DIR__ . '/assets/css/e-signature.css') : '1'; ?>" rel="stylesheet">
</head>
<body class="page-settings">

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in settings-main">
        <div class="mb-5 settings-header">
            <h2 class="fw-bold mb-1">Account Settings</h2>
            <p class="text-muted mb-0">Manage your profile, appearance, and account security.</p>
        </div>

        <?php if(isset($_GET['success']) && !$is_security_success): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm">
                <i class="fas fa-check-circle me-2"></i> 
                <?php 
                if($_GET['success'] == 'ThemeUpdated') echo "Your appearance preference has been saved.";
                elseif($_GET['success'] == 'CodeSent') echo "A 6-digit verification code has been sent to your new email.";
                elseif($_GET['success'] == 'EmailVerified') echo "Email successfully verified and updated!";
                elseif($_GET['success'] == 'PasswordUpdated') echo "Your password has been successfully updated!";
                elseif($_GET['success'] == 'ProfileUpdated') echo "Your profile information has been updated.";
                elseif($_GET['success'] == 'AvatarUpdated') echo "Your profile photo has been updated.";
                elseif($_GET['success'] == 'EmailChangeCancelled') echo "The pending email change has been cancelled.";
                elseif($_GET['success'] == 'RequestSubmitted') echo "Your username change request has been sent to the Admin.";
                else echo "Action completed successfully!";
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error']) && !$is_security_error): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm">
                <i class="fas fa-exclamation-circle me-2"></i> Error: 
                <?php 
                if($_GET['error'] == 'PreferenceSaveFailed') echo "The appearance preference could not be saved. Check that the user-preferences migration has been installed.";
                elseif($_GET['error'] == 'InvalidPreference') echo "Select a valid appearance option.";
                elseif($_GET['error'] == 'InvalidCode') echo "The verification code is incorrect or has expired.";
                elseif($_GET['error'] == 'EmailAlreadyInUse') echo "That email address is already in use by another account.";
                elseif($_GET['error'] == 'WrongCurrentPassword') echo "The current password you entered is incorrect.";
                elseif($_GET['error'] == 'PasswordMismatch') echo "The new passwords do not match.";
                elseif($_GET['error'] == 'WeakPassword' || $_GET['error'] == 'WeakPasswordAdmin') echo "Password must be at least 8 characters long, contain an uppercase letter, a lowercase letter, and a number.";
                elseif($_GET['error'] == 'PasswordReused') echo "Your new password must be different from your current password.";
                elseif($_GET['error'] == 'InvalidEmail') echo "Enter a valid recovery email address.";
                elseif($_GET['error'] == 'InvalidFullName') echo "Enter a valid full name with no more than 100 characters.";
                elseif($_GET['error'] == 'EmailCodeCooldown') echo "Please wait 60 seconds before requesting another email code.";
                elseif($_GET['error'] == 'TooManyEmailCodeAttempts') echo "Too many incorrect codes. Save your information again to request a new code.";
                elseif($_GET['error'] == 'VerificationEmailFailed') echo "The verification email could not be sent. Your existing email was not changed.";
                elseif($_GET['error'] == 'AvatarTooLarge') echo "The profile photo must not exceed 5 MB.";
                elseif($_GET['error'] == 'InvalidAvatarType') echo "Use a valid JPG, PNG, or WebP profile photo.";
                elseif($_GET['error'] == 'AvatarUploadFailed') echo "The profile photo could not be uploaded.";
                elseif($_GET['error'] == 'AccountUpdateFailed') echo "The account could not be updated. Please try again.";
                elseif($_GET['error'] == 'InvalidUsername') echo "Use 3–50 lowercase letters or numbers. A period, underscore, or hyphen may separate username parts.";
                elseif($_GET['error'] == 'UsernameAlreadyExists') echo "That username is already assigned to another account.";
                elseif($_GET['error'] == 'UsernameUnchanged') echo "Enter a username that is different from your current username.";
                elseif($_GET['error'] == 'PendingRequestExists') echo "You already have a pending username-change request, or that username is currently reserved by another pending request.";
                elseif($_GET['error'] == 'InvalidReason') echo "Enter a brief reason containing 3 to 500 characters.";
                elseif($_GET['error'] == 'RequestNotAllowed') echo "This account cannot submit that type of request.";
                elseif($_GET['error'] == 'RequestFailed') echo "The username-change request could not be saved. Please try again.";
                else echo htmlspecialchars($_GET['error']); 
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <section class="drms-appearance-card" aria-labelledby="appearanceHeading">
            <div class="drms-appearance-card__copy">
                <span class="drms-appearance-card__icon" aria-hidden="true"><i class="fas fa-circle-half-stroke"></i></span>
                <div>
                    <h3 id="appearanceHeading">Appearance</h3>
                    <p>Choose the display that works best for your account. Printouts stay light.</p>
                </div>
            </div>
            <form action="actions/user_preferences_handler.php" method="post" class="drms-appearance-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_theme">
                <fieldset class="drms-theme-options" <?php echo empty($drms_preferences['installed']) ? 'disabled' : ''; ?>>
                    <legend class="visually-hidden">Theme</legend>
                    <label class="drms-theme-option">
                        <input type="radio" name="theme" value="light" <?php echo $drms_preferences['theme'] === 'light' ? 'checked' : ''; ?>>
                        <span><i class="fas fa-sun" aria-hidden="true"></i> Light</span>
                    </label>
                    <label class="drms-theme-option">
                        <input type="radio" name="theme" value="dark" <?php echo $drms_preferences['theme'] === 'dark' ? 'checked' : ''; ?>>
                        <span><i class="fas fa-moon" aria-hidden="true"></i> Dark</span>
                    </label>
                </fieldset>
                <button type="submit" class="btn btn-primary drms-appearance-save" <?php echo empty($drms_preferences['installed']) ? 'disabled' : ''; ?>>Save</button>
            </form>
            <?php if (empty($drms_preferences['installed'])): ?>
                <p class="drms-appearance-note">Ask the administrator to install the user-preferences migration first.</p>
            <?php endif; ?>
        </section>

        <div class="row g-4 settings-grid">
            
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
                                    <input type="file" name="avatar" id="avatarInput" class="d-none" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()">
                                    <button type="button" class="btn btn-sm btn-outline-primary bg-white" onclick="document.getElementById('avatarInput').click()">
                                        <i class="fas fa-camera me-1"></i> Change Photo
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if(in_array($role, ['GM', 'Finance', 'President', 'Supply Chain'], true)): ?>
                <section class="drms-esign-card mb-4">
                    <div class="drms-esign-card__head">
                        <span class="drms-esign-card__icon"><i class="fas fa-signature"></i></span>
                        <div>
                            <span class="drms-esign-card__eyebrow">Approval security</span>
                            <h1>Electronic signature</h1>
                        </div>
                    </div>
                    <div class="drms-esign-card__body">
                        <p class="drms-esign-help mb-3">Set the protected name, title, and optional signature image recorded with your assigned approvals. Each signature uses your active signed-in account and explicit consent.</p>
                        <a href="signature_profile.php" class="btn btn-outline-primary btn-sm fw-bold"><i class="fas fa-pen-nib me-1"></i> Set up electronic signature</a>
                    </div>
                </section>
                <?php endif; ?>

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
                                    <label class="visually-hidden" for="settingsVerificationCode">Email verification code</label>
                                    <input type="text" name="verification_code" id="settingsVerificationCode" class="form-control form-control-sm text-center fw-bold" placeholder="000000" minlength="6" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required style="letter-spacing: 5px;">
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
                                <label class="form-label small fw-bold" for="profileFullName">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                                    <input type="text" name="full_name" id="profileFullName" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" maxlength="100" autocomplete="name" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold" for="profileEmail">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                    <input type="email" name="email" id="profileEmail" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter email to enable recovery" maxlength="100" autocomplete="email" inputmode="email" spellcheck="false" required>
                                </div>
                                <?php if(empty($user['email'])): ?>
                                    <small class="text-danger mt-1 d-block"><i class="fas fa-exclamation-triangle"></i> Set and verify your email to enable password recovery.</small>
                                <?php elseif(empty($user['pending_email'])): ?>
                                    <small class="text-success mt-1 d-block"><i class="fas fa-check-circle"></i> Recovery email active</small>
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
                                <label class="form-label small fw-bold" for="currPass">Current Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span>
                                    <input type="password" name="current_password" id="currPass" class="form-control border-end-0" maxlength="128" autocomplete="current-password" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" data-field-label="current password" aria-label="Show current password" aria-controls="currPass" aria-pressed="false" onclick="togglePass('currPass', 'iconCurr', this)">
                                        <i class="fas fa-eye" id="iconCurr" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="newPass">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-shield-alt text-muted"></i></span>
                                    <input type="password" name="new_password" id="newPass" class="form-control border-end-0" minlength="8" maxlength="128" autocomplete="new-password" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" data-field-label="new password" aria-label="Show new password" aria-controls="newPass" aria-pressed="false" onclick="togglePass('newPass', 'iconNew', this)">
                                        <i class="fas fa-eye" id="iconNew" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="confirmNewPass">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-check text-muted"></i></span>
                                    <input type="password" name="confirm_password" id="confirmNewPass" class="form-control border-end-0" minlength="8" maxlength="128" autocomplete="new-password" required>
                                    <button class="btn border border-start-0 text-secondary" type="button" data-field-label="password confirmation" aria-label="Show password confirmation" aria-controls="confirmNewPass" aria-pressed="false" onclick="togglePass('confirmNewPass', 'iconConfirm', this)">
                                        <i class="fas fa-eye" id="iconConfirm" aria-hidden="true"></i>
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

                            <p class="small text-muted mb-3"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Updating your password securely signs out any other active session.</p>

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
                                <label class="form-label small fw-bold" for="desiredUsername">Desired New Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-at text-muted"></i></span>
                                    <input type="text" name="new_value" id="desiredUsername" class="form-control" required minlength="3" maxlength="50" pattern="[a-z0-9]+([._-][a-z0-9]+)*" autocomplete="username" autocapitalize="none" spellcheck="false" oninput="this.value = this.value.toLowerCase()" placeholder="e.g. juan.delacruz">
                                </div>
                                <small class="text-muted d-block mt-1">Use 3–50 lowercase letters or numbers. Period, underscore, and hyphen are allowed only as separators.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="usernameChangeReason">Reason for Change</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-comment-dots text-muted"></i></span>
                                    <input type="text" name="reason" id="usernameChangeReason" class="form-control" required minlength="3" maxlength="500" placeholder="Brief reason (e.g. Spelling correction)">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-danger" for="reqCurrPass">Verify Current Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                                    <input type="password" name="current_password" id="reqCurrPass" class="form-control border-end-0" maxlength="255" autocomplete="current-password" required placeholder="Required for security">
                                    <button class="btn border border-start-0 text-secondary" type="button" data-field-label="current password" aria-label="Show current password" aria-controls="reqCurrPass" aria-pressed="false" onclick="togglePass('reqCurrPass', 'iconReqCurr', this)">
                                        <i class="fas fa-eye" id="iconReqCurr" aria-hidden="true"></i>
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

        <section id="security-center" class="settings-security-card" aria-labelledby="securityCenterTitle">
            <div class="settings-security-heading">
                <div>
                    <span class="settings-security-kicker">ACCOUNT PROTECTION</span>
                    <h3 id="securityCenterTitle">Security Center</h3>
                    <p>Review recent sign-ins and control where your account is open.</p>
                </div>
                <button type="button" class="settings-security-action" data-bs-toggle="modal" data-bs-target="#signOutOthersModal">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    <span>Sign out other devices<?php echo $other_sessions_count > 0 ? ' (' . $other_sessions_count . ')' : ''; ?></span>
                </button>
            </div>
            <?php if ($is_security_success): ?>
                <div class="settings-security-message is-success" role="status">Other device sessions were signed out. This device stays active.</div>
            <?php elseif ($is_security_error): ?>
                <div class="settings-security-message is-error" role="alert"><?php echo $security_error_code === 'SecurityActionCooldown'
                    ? 'Too many incorrect attempts. Try again in five minutes.'
                    : ($security_error_code === 'WrongSecurityPassword' || $security_error_code === 'SecurityPasswordRequired'
                        ? 'Enter your current password to sign out other devices.'
                        : ($security_error_code === 'AccountUpdateFailed'
                            ? 'The security action could not be completed. Please try again.'
                            : 'The security request could not be verified. Refresh and try again.')); ?></div>
            <?php endif; ?>
            <div class="settings-session-list">
                <?php if (!$security_sessions): ?>
                    <p class="settings-session-empty">No sign-in history is available yet.</p>
                <?php else: ?>
                    <?php foreach ($security_sessions as $security_session):
                        $is_current = hash_equals($current_device_hash, (string) $security_session['device_key_hash']);
                        $token_current = hash_equals($current_auth_hash, (string) $security_session['auth_token_hash']);
                        $last_seen_ts = strtotime((string) $security_session['last_seen_at']) ?: 0;
                        $still_active = $security_session['ended_at'] === null && $token_current && $last_seen_ts >= time() - $session_window_minutes * 60;
                        $is_online = $still_active && $last_seen_ts >= time() - 75;
                        $session_state = $is_current ? 'This device' : ($is_online ? 'Online' : ($still_active ? 'Inactive' : 'Ended'));
                        $state_class = $is_current ? 'is-current' : ($is_online ? 'is-online' : 'is-muted');
                    ?>
                    <div class="settings-session-row">
                        <div class="settings-session-icon"><i class="fas fa-desktop" aria-hidden="true"></i></div>
                        <div class="settings-session-copy">
                            <strong><?php echo e(drms_registry_device_label((string) ($security_session['user_agent'] ?? ''))); ?></strong>
                            <span>Signed in <?php echo e(date('M d, Y · h:i A', strtotime((string) $security_session['signed_in_at']))); ?><?php if (!empty($security_session['ip_address'])): ?> · IP <?php echo e($security_session['ip_address']); ?><?php endif; ?></span>
                        </div>
                        <div class="settings-session-meta">
                            <span class="settings-session-state <?php echo $state_class; ?>"><?php echo e($session_state); ?></span>
                            <small>Seen <?php echo e(date('M d · h:i A', $last_seen_ts)); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="settings-security-footnote">Online status refreshes while a system page is open. A closed browser may appear online for up to about 75 seconds.</p>
        </section>
    </div>

    <div class="modal fade" id="signOutOthersModal" tabindex="-1" aria-labelledby="signOutOthersTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content settings-security-modal">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" id="signOutOthersTitle">Sign out other devices</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="actions/session_security_handler.php" method="post">
                    <div class="modal-body">
                        <p>Other sessions, including ones not yet listed here, will close on their next request. This device stays signed in.</p>
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="sign_out_other_devices">
                        <label class="form-label" for="securityCurrentPassword">Current password</label>
                        <input class="form-control" type="password" id="securityCurrentPassword" name="current_password" maxlength="128" autocomplete="current-password" required>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Sign out others</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/vendor/jquery/3.7.0/jquery.min.js"></script>
    <script src="assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
    <script>
        function togglePass(inputId, iconId, toggleButton) {
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

            const isVisible = input.type === "text";
            if (toggleButton) {
                const fieldLabel = toggleButton.dataset.fieldLabel || "password";
                toggleButton.setAttribute("aria-pressed", isVisible ? "true" : "false");
                toggleButton.setAttribute("aria-label", (isVisible ? "Hide " : "Show ") + fieldLabel);
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

