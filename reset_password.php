<?php 
session_start();
require 'config/db_connect.php';

if(isset($_SESSION['user_id'])){ header("Location: dashboard.php"); exit(); }

$token = $_GET['token'] ?? '';
$valid_token = false;

// I-verify kung existing at valid pa ang token bago ipakita ang form
if (!empty($token)) {
    $stmt = $conn->prepare("SELECT id FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $valid_token = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="page-reset-password">

    <div class="auth-wrapper">
        <div class="brand-panel">
            <div class="brand-center-content">
                <img src="assets/images/fixie_logo.png" alt="Fixie Logo" class="main-logo" onerror="this.style.display='none'">
                <div class="sys-title">Record Management System</div>
                <div class="sys-company">Fixie Computer Ventures</div>
            </div>
            <div class="system-meta">&copy; <?php echo date('Y'); ?> Fixie Computer Ventures<br>Restricted System Access</div>
        </div>

        <div class="form-panel">
            <div class="form-container">
                
                <?php if ($valid_token): ?>
                    <div class="auth-header">
                        <h2>Create New Password</h2>
                        <p>Your new password must be different from previous used passwords.</p>
                    </div>

                    <?php if(isset($_GET['error'])): ?>
                        <div class="alert-system alert-error">
                            <i class="fas fa-exclamation-triangle mt-1"></i>
                            <div><?php echo htmlspecialchars($_GET['error']); ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="actions/auth.php" method="POST">
                        <input type="hidden" name="action" value="reset_password_submit">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <div class="form-floating-custom">
                            <input type="password" id="new_pass" name="new_password" placeholder=" " required minlength="6">
                            <label for="new_pass">New Secure Password</label>
                        </div>

                        <div class="form-floating-custom">
                            <input type="password" id="conf_pass" name="confirm_password" placeholder=" " required minlength="6">
                            <label for="conf_pass">Confirm New Password</label>
                        </div>

                        <button type="submit" name="reset_password_submit" class="btn-corporate">
                            <i class="fas fa-lock me-2"></i> Update Password
                        </button>
                    </form>
                <?php else: ?>
                    <div class="auth-header">
                        <h2>Link Expired</h2>
                        <p>The password reset token is invalid or has already expired for your security.</p>
                    </div>
                    
                    <div class="alert-system alert-error">
                        <i class="fas fa-exclamation-triangle mt-1"></i>
                        <div>Please request a new password recovery link.</div>
                    </div>

                    <a href="forgot_password.php" class="btn-corporate" style="text-decoration: none;">
                        Request New Link
                    </a>
                    <a href="index.php" class="btn-text-only">
                        <i class="fas fa-arrow-left me-2"></i> Return to Login
                    </a>
                <?php endif; ?>

            </div>
        </div>
    </div>

</body>
</html>