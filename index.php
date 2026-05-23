<?php require 'config/db_connect.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body, html {
            height: 100%;
            margin: 0;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f7f9;
        }
        
        /* Modern Corporate FCV Palette - Balanced & Professional */
        .brand-section {
            background: linear-gradient(135deg, #1d3a4d 0%, #2a617b 100%);
            position: relative;
            overflow: hidden;
        }
        
        .brand-section::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 10%, transparent 20%);
            background-size: 25px 25px;
            opacity: 0.6;
            animation: moveBackground 60s linear infinite;
        }

        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .form-section {
            background-color: #ffffff;
            box-shadow: -15px 0 35px rgba(0, 0, 0, 0.04);
            z-index: 10;
        }

        .custom-input-group {
            position: relative;
            margin-bottom: 2.2rem;
        }
        
        .custom-input {
            width: 100%;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            border-radius: 0;
            padding: 10px 40px;
            font-size: 1.05rem;
            background-color: transparent;
            transition: all 0.3s ease;
            color: #1e293b;
        }
        
        .custom-input:focus {
            outline: none;
            border-bottom-color: #2a617b; /* FCV Corporate Blue */
            box-shadow: none;
        }

        .input-icon-left {
            position: absolute;
            left: 5px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .custom-input:focus ~ .input-icon-left {
            color: #2a617b;
        }

        .btn-toggle-pass {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 10px;
        }

        .btn-login {
            background-color: #2a617b; /* FCV Corporate Blue */
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 14px;
            font-size: 1rem;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            background-color: #1d465a;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(42, 97, 123, 0.3);
            color: #ffffff;
        }

        .brand-logo-img {
            width: 120px;
            height: auto;
            filter: drop-shadow(0px 6px 12px rgba(0,0,0,0.25));
        }
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
                        Records Management System
                    </p>
                </div>
            </div>
            <div class="col-lg-5 col-md-6 d-flex align-items-center justify-content-center form-section fade-in">
                <div class="w-100 px-4 px-xl-5" style="max-width: 450px;">
                    <div class="text-center d-md-none mb-4">
                        <img src="assets/images/fixie_logo.png" alt="Logo" style="width: 80px;">
                        <h4 class="fw-bold mt-2 text-dark">Fixie DRMS</h4>
                    </div>
                    <div class="mb-5">
                        <h2 class="fw-bold text-dark mb-2">Welcome back</h2>
                        <p class="text-muted">Please enter your credentials to continue.</p>
                    </div>
                    
                    <?php if(isset($_GET['success'])): ?>
                        <div class="alert alert-success d-flex align-items-center small shadow-sm rounded-3" role="alert">
                            <i class="fas fa-check-circle me-2"></i><div><?php echo htmlspecialchars($_GET['success']); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if(isset($_GET['error'])): ?>
                        <div class="alert alert-danger d-flex align-items-center small shadow-sm rounded-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div>
                                <?php 
                                    if($_GET['error'] == 'InvalidCredentials') echo "Invalid Username or Password.";
                                    elseif($_GET['error'] == 'AccountLockedWaitAdmin') echo "Account is locked pending Admin approval.";
                                    elseif($_GET['error'] == 'TooManyAttemptsWait5Mins') echo "Too many failed attempts. Please try again after 5 minutes.";
                                    else echo "Login Failed.";
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form action="actions/auth.php" method="POST" class="mt-4">
                        <div class="custom-input-group">
                            <input type="text" name="username" class="custom-input" placeholder="Username" required autocomplete="off">
                            <i class="fas fa-user input-icon-left"></i>
                        </div>
                        <div class="custom-input-group mb-2">
                            <input type="password" name="password" id="loginPass" class="custom-input" placeholder="Password" required>
                            <i class="fas fa-lock input-icon-left"></i>
                            <button type="button" class="btn-toggle-pass" onclick="togglePass('loginPass', 'loginIcon')">
                                <i class="fas fa-eye" id="loginIcon"></i>
                            </button>
                        </div>
                        <div class="text-end mb-4">
                            <a href="forgot_password.php" class="text-decoration-none small fw-semibold" style="color: #2a617b;">Forgot Password?</a>
                        </div>
                        <button type="submit" name="login" class="btn w-100 btn-login fw-bold shadow-sm">
                            SIGN IN <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </form>
                    <div class="text-center mt-5">
                        <small class="text-muted">&copy; <?php echo date("Y"); ?> Fixie Computer Ventures.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye");
            }
        }
    </script>
</body>
</html>