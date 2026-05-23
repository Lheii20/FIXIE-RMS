<?php
require 'config/db_connect.php';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$valid = false;

if (!empty($token) && !empty($email)) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND reset_token = ? AND reset_token_expire > NOW()");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $valid = true;
    }
}

if (!$valid) {
    header("Location: forgot_password.php?error=InvalidOrExpiredToken");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: var(--bg-body); display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { max-width: 450px; width: 100%; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md); border: 1px solid var(--border-color); }
    </style>
</head>
<body>

    <div class="card login-card p-4 fade-in">
        <div class="text-center mb-4">
            <img src="assets/images/fixie_logo.png" alt="Fixie Logo" width="80" class="mb-3">
            <h4 class="fw-bold text-dark mb-1">New Password</h4>
            <p class="text-muted small">Set your new password for <b><?php echo htmlspecialchars($email); ?></b></p>
        </div>

        <form action="actions/request_handler.php" method="POST" id="resetForm">
            <input type="hidden" name="action" value="execute_reset_password">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">New Password</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8" placeholder="Minimum 8 characters">
            </div>
            
            <div class="mb-4">
                <label class="form-label small fw-bold text-muted">Confirm New Password</label>
                <input type="password" id="confirm_password" class="form-control" required placeholder="Retype your password">
                <div id="passwordError" class="text-danger small mt-1 d-none">Passwords do not match!</div>
            </div>

            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 mb-3">Update Password</button>
        </form>
    </div>

    <script>
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            if (document.getElementById('new_password').value !== document.getElementById('confirm_password').value) {
                e.preventDefault();
                document.getElementById('passwordError').classList.remove('d-none');
            }
        });
    </script>
</body>
</html>