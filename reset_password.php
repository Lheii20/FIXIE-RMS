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
    
    <style>
        /* Exact same styling variables from index.php */
        :root {
            --brand-dark: #0f172a;
            --brand-primary: #2563eb;
            --brand-primary-hover: #1d4ed8;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --bg-light: #f8fafc;
            --border-color: #cbd5e1;
            --error-bg: #fef2f2;
            --error-text: #b91c1c;
            --success-bg: #f0fdf4;
            --success-text: #15803d;
        }

        body { margin: 0; font-family: 'Inter', sans-serif; background-color: var(--bg-light); color: var(--text-main); height: 100vh; overflow: hidden; display: flex; }
        .auth-wrapper { display: flex; width: 100%; height: 100%; }

        .brand-panel { flex: 1; background: linear-gradient(-45deg, #0f172a, #1e293b, #1e3a8a, #0f172a); background-size: 400% 400%; animation: gradientAnim 15s ease infinite; color: #ffffff; padding: 4rem; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; overflow: hidden; text-align: center; }
        @keyframes gradientAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .brand-panel::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(rgba(255, 255, 255, 0.07) 2px, transparent 2px); background-size: 40px 40px; z-index: 0; }
        .brand-panel::after { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 120%; height: 120%; background: radial-gradient(circle, rgba(37, 99, 235, 0.2) 0%, transparent 60%); z-index: 0; }
        .brand-center-content { z-index: 1; display: flex; flex-direction: column; align-items: center; }
        
        .main-logo { width: 160px; height: auto; max-height: 160px; object-fit: contain; margin-bottom: 2rem; filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.4)); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .main-logo:hover { transform: translateY(-8px) scale(1.05); filter: drop-shadow(0 20px 25px rgba(0, 0, 0, 0.6)); }
        
        .sys-title { font-size: 2rem; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 0.5rem; color: #ffffff; text-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .sys-company { font-size: 1.15rem; font-weight: 500; color: #94a3b8; letter-spacing: 0.5px; }
        .system-meta { position: absolute; bottom: 2rem; z-index: 1; font-size: 0.8rem; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 1px; }

        .form-panel { width: 100%; max-width: 500px; background: #ffffff; padding: 4rem; display: flex; flex-direction: column; justify-content: center; box-shadow: -15px 0 40px rgba(0, 0, 0, 0.05); z-index: 10; overflow-y: auto; }
        .form-container { width: 100%; max-width: 360px; margin: 0 auto; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .auth-header { margin-bottom: 2.5rem; text-align: center; }
        .auth-header h2 { font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem; letter-spacing: -0.5px; }
        .auth-header p { font-size: 0.95rem; color: var(--text-muted); margin: 0; }

        /* Floating Label Animations */
        .form-floating-custom { position: relative; margin-bottom: 1.5rem; }
        .form-floating-custom input { width: 100%; padding: 1.1rem 1rem 0.9rem 1rem; font-size: 1rem; font-family: 'Inter', sans-serif; color: var(--text-main); background-color: transparent; border: 1.5px solid var(--border-color); border-radius: 8px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box; }
        .form-floating-custom label { position: absolute; top: 50%; left: 1rem; transform: translateY(-50%); font-size: 0.95rem; color: var(--text-muted); pointer-events: none; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); background-color: #ffffff; padding: 0 0.4rem; margin: 0 -0.4rem; }
        .form-floating-custom input:focus, .form-floating-custom input:not(:placeholder-shown) { border-color: var(--brand-primary); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
        .form-floating-custom input:focus ~ label, .form-floating-custom input:not(:placeholder-shown) ~ label { top: 0; transform: translateY(-50%) scale(0.85); color: var(--brand-primary); font-weight: 600; }

        .btn-corporate { width: 100%; padding: 0.9rem; background-color: var(--brand-dark); color: #ffffff; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-top: 1rem; }
        .btn-corporate:hover { background-color: #1e293b; transform: translateY(-2px); box-shadow: 0 6px 10px -2px rgba(0, 0, 0, 0.15); }
        .btn-text-only { width: 100%; padding: 0.85rem; background: transparent; color: var(--text-main); border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-top: 1rem; display: block; text-align: center; text-decoration: none;}
        .btn-text-only:hover { background: var(--bg-light); border-color: #94a3b8; }

        .alert-system { padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 10px; font-weight: 500; border-left: 4px solid transparent;}
        .alert-error { background: var(--error-bg); color: var(--error-text); border-color: #fca5a5; border-left-color: var(--error-text); }
        .alert-success { background: var(--success-bg); color: var(--success-text); border-color: #bbf7d0; border-left-color: var(--success-text); }

        @media (max-width: 992px) {
            .auth-wrapper { flex-direction: column; }
            .brand-panel { flex: none; padding: 3rem 2rem; height: auto; justify-content: center; }
            .main-logo { width: 120px; max-height: 120px; margin-bottom: 1rem; }
            .sys-title { font-size: 1.5rem; }
            .sys-company { font-size: 1rem; }
            .system-meta { display: none; } 
            .form-panel { max-width: 100%; padding: 3rem 2rem; flex: 1; }
        }
    </style>
</head>
<body>

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