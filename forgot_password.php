<?php 
session_start();
if(isset($_SESSION['user_id'])){ header("Location: dashboard.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Recovery - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <!-- Professional Enterprise Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="page-forgot-password">

    <div class="auth-wrapper">
        
        <!-- Left Side: Dynamic Branding -->
        <div class="brand-panel">
            <div class="brand-center-content">
                <img src="assets/images/fixie_logo.png" alt="Fixie Logo" class="main-logo" onerror="this.style.display='none'">
                <div class="sys-title">Record Management System</div>
                <div class="sys-company">Fixie Computer Ventures</div>
            </div>

            <div class="system-meta">
                &copy; <?php echo date('Y'); ?> Fixie Computer Ventures<br>
            </div>
        </div>

        <!-- Right Side: Form Authentication Panel -->
        <div class="form-panel">
            <div class="form-container">

                <div class="auth-view active">
                    <div class="auth-header">
                        <h2>Account Recovery</h2>
                        <p>Enter your registered email to receive reset instructions.</p>
                    </div>

                    <!-- Display Errors -->
                    <?php if(isset($_GET['error'])): ?>
                        <div class="alert-system alert-error">
                            <i class="fas fa-exclamation-triangle mt-1"></i>
                            <div><?php echo htmlspecialchars($_GET['error']); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Display Success -->
                    <?php if(isset($_GET['success'])): ?>
                        <div class="alert-system alert-success">
                            <i class="fas fa-check-circle mt-1"></i>
                            <div><?php echo htmlspecialchars($_GET['success']); ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="actions/auth.php" method="POST">
                        <input type="hidden" name="action" value="forgot_password"> <!-- Or base this on your PHP logic backend -->
                        
                        <!-- Floating Label for Email -->
                        <div class="form-floating-custom mb-4">
                            <input type="email" id="reset_email" name="email" placeholder=" " required autocomplete="email">
                            <label for="reset_email">Registered Email Address</label>
                        </div>

                        <button type="submit" name="forgot_password" class="btn-corporate">
                            <i class="fas fa-paper-plane"></i> Send Recovery Link
                        </button>
                    </form>

                    <div class="divider">Remembered your password?</div>

                    <a href="index.php" style="text-decoration: none;">
                        <button type="button" class="btn-text-only">
                            <i class="fas fa-arrow-left me-2"></i> Return to Login
                        </button>
                    </a>
                </div>

            </div>
        </div>
    </div>

</body>
</html>
