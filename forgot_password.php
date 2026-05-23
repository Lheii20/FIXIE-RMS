<?php
require 'config/db_connect.php';
$step = isset($_GET['step']) ? $_GET['step'] : 1;
$email = isset($_GET['email']) ? $_GET['email'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body, html { height: 100%; margin: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f9; }
        
        .brand-section {
            background: linear-gradient(135deg, #1d3a4d 0%, #2a617b 100%);
            position: relative; overflow: hidden;
        }
        .brand-section::before {
            content: ""; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 10%, transparent 20%);
            background-size: 25px 25px; opacity: 0.6; animation: moveBackground 60s linear infinite;
        }
        @keyframes moveBackground { 0% { transform: translate(0, 0); } 100% { transform: translate(50px, 50px); } }
        .form-section { background-color: #ffffff; box-shadow: -15px 0 35px rgba(0, 0, 0, 0.04); z-index: 10; }
        .custom-input-group { position: relative; margin-bottom: 2.2rem; }
        .custom-input { width: 100%; border: none; border-bottom: 2px solid #e2e8f0; border-radius: 0; padding: 10px 40px; font-size: 1.05rem; background-color: transparent; transition: all 0.3s ease; color: #1e293b; }
        .custom-input:focus { outline: none; border-bottom-color: #2a617b; box-shadow: none; }
        .input-icon-left { position: absolute; left: 5px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.2rem; transition: color 0.3s ease; }
        .custom-input:focus ~ .input-icon-left { color: #2a617b; }
        
        .btn-primary-custom {
            background-color: #2a617b; color: #ffffff !important; border: none; border-radius: 8px; padding: 14px; font-size: 1rem; letter-spacing: 0.5px; transition: all 0.3s ease;
        }
        .btn-primary-custom:hover { background-color: #1d465a; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(42, 97, 123, 0.3); }
        .brand-logo-img { width: 120px; height: auto; filter: drop-shadow(0px 6px 12px rgba(0,0,0,0.25)); }
        .code-input { padding-left: 0; text-align: center; letter-spacing: 8px; font-size: 2rem; color: #1e293b; }
    </style>
</head>
<body>
    <div class="container-fluid h-100 p-0">
        <div class="row g-0 h-100">
            <div class="col-lg-7 col-md-6 d-none d-md-flex align-items-center justify-content-center brand-section">
                <div class="text-center text-white px-5" style="z-index: 2;">
                    <img src="assets/images/fixie_logo.png" alt="Fixie Logo" class="brand-logo-img mb-4">
                    <h1 class="display-5 fw-bolder mb-3" style="letter-spacing: -0.5px;">Fixie Computer Ventures</h1>
                    <p class="lead fw-light text-white-50" style="max-width: 500px; margin: 0 auto; font-size: 1.1rem;">
                        Records Management System</p>
                </div>
            </div>
            <div class="col-lg-5 col-md-6 d-flex align-items-center justify-content-center form-section fade-in">
                <div class="w-100 px-4 px-xl-5" style="max-width: 450px;">
                    <div class="text-center d-md-none mb-4">
                        <img src="assets/images/fixie_logo.png" alt="Logo" style="width: 80px;">
                        <h4 class="fw-bold mt-2 text-dark">Fixie DRMS</h4>
                    </div>
                    <div class="mb-5">
                        <h2 class="fw-bold text-dark mb-2">Account Recovery</h2>
                        <p class="text-muted">
                            <?php echo ($step == 1) ? "Enter your Username and registered Email address to receive a 6-digit reset code." : "Enter the 6-digit code sent to <b class='text-dark'>".htmlspecialchars($email)."</b>"; ?>
                        </p>
                    </div>
                    <?php if(isset($_GET['error'])): ?>
                        <div class="alert alert-danger d-flex align-items-center small shadow-sm rounded-3 mb-4" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div>
                                <?php 
                                if($_GET['error'] == 'AccountMismatch') echo "No matching account found with that Username and Email combination.";
                                elseif($_GET['error'] == 'InvalidCode') echo "The code you entered is incorrect or expired.";
                                elseif($_GET['error'] == 'EmailError') echo "Problem sending the code. Please try again.";
                                else echo "An error occurred."; 
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if($step == 1): ?>
                        <form action="actions/request_handler.php" method="POST">
                            <input type="hidden" name="action" value="request_forgot_password">
                            <div class="custom-input-group">
                                <input type="text" name="username" class="custom-input" placeholder="Username" required autocomplete="off">
                                <i class="fas fa-user input-icon-left"></i>
                            </div>
                            <div class="custom-input-group mb-4">
                                <input type="email" name="email" class="custom-input" placeholder="Registered Email Address" required autocomplete="off">
                                <i class="fas fa-envelope input-icon-left"></i>
                            </div>
                            <button type="submit" class="btn w-100 btn-primary-custom fw-bold shadow-sm">
                                Send Reset Code <i class="fas fa-paper-plane ms-2"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <form action="actions/request_handler.php" method="POST">
                            <input type="hidden" name="action" value="verify_reset_code">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                            <div class="custom-input-group mb-4">
                                <input type="text" name="code" class="custom-input code-input fw-bold" placeholder="000000" maxlength="6" required autocomplete="off">
                            </div>
                            <button type="submit" class="btn w-100 btn-primary-custom fw-bold shadow-sm">
                                Verify Code <i class="fas fa-check-circle ms-2"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="index.php" class="text-decoration-none small fw-semibold" style="color: #2a617b;">
                            <i class="fas fa-arrow-left me-1"></i> Back to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>