<?php 
session_start();
require 'config/db_connect.php';

// Check which flow the user is accessing the setup page from
$is_token_flow = isset($_GET['token']) && isset($_GET['email']);
$is_session_flow = isset($_SESSION['temp_user_id']); // Legacy/Forced Reset Support

if (!$is_token_flow && !$is_session_flow) {
    header("Location: index.php");
    exit();
}

$fullname = 'User';
$error_msg = '';
$valid_request = false;

if ($is_token_flow) {
    $token = $_GET['token'];
    $email = $_GET['email'];
    
    // Verify Token Expiration & Link
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE email = ? AND setup_token = ? AND setup_token_expire > NOW()");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        $fullname = $user['full_name'];
        $valid_request = true;
    } else {
        $error_msg = "This setup link has already been used, is invalid, or has expired after 24 hours.";
    }
} elseif ($is_session_flow) {
    $fullname = $_SESSION['temp_fullname'] ?? 'User';
    $valid_request = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Password - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body, html {
            height: 100%; margin: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f9;
        }
        .brand-section {
            background: linear-gradient(135deg, #1d3a4d 0%, #2a617b 100%); position: relative; overflow: hidden;
        }
        .brand-section::before {
            content: ""; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 10%, transparent 20%); background-size: 25px 25px; opacity: 0.6; animation: moveBackground 60s linear infinite;
        }
        @keyframes moveBackground { 0% { transform: translate(0, 0); } 100% { transform: translate(50px, 50px); } }
        .form-section { background-color: #ffffff; box-shadow: -15px 0 35px rgba(0, 0, 0, 0.04); z-index: 10; }
        
        .custom-input-group { position: relative; margin-bottom: 1.5rem; }
        .custom-input { width: 100%; border: none; border-bottom: 2px solid #e2e8f0; border-radius: 0; padding: 10px 40px; font-size: 1.05rem; background-color: transparent; transition: all 0.3s ease; color: #1e293b; }
        .custom-input:focus { outline: none; border-bottom-color: #2a617b; box-shadow: none; }
        .input-icon-left { position: absolute; left: 5px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.2rem; transition: color 0.3s ease; }
        .custom-input:focus ~ .input-icon-left { color: #2a617b; }
        .btn-toggle-pass { position: absolute; right: 0; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; padding: 10px; }
        
        .btn-submit { background-color: #2a617b; color: #ffffff; border: none; border-radius: 8px; padding: 14px; font-size: 1rem; letter-spacing: 0.5px; transition: all 0.3s ease; }
        .btn-submit:hover:not(:disabled) { background-color: #1d465a; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(42, 97, 123, 0.3); color: #ffffff; }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }

        .req-list { list-style: none; padding-left: 0; font-size: 0.85rem; margin-bottom: 2rem; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .req-list li { margin-bottom: 5px; display: flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .req-list li i { width: 16px; text-align: center; }
        
        .brand-logo-img { width: 120px; height: auto; filter: drop-shadow(0px 6px 12px rgba(0,0,0,0.25)); }
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
                        Enterprise Records Management
                    </p>
                </div>
            </div>
            
            <div class="col-lg-5 col-md-6 d-flex align-items-center justify-content-center form-section fade-in">
                <div class="w-100 px-4 px-xl-5" style="max-width: 450px;">
                    <div class="text-center d-md-none mb-4">
                        <img src="assets/images/fixie_logo.png" alt="Logo" style="width: 80px;">
                    </div>
                    
                    <?php if(!$valid_request): ?>
                        <div class="text-center py-4">
                            <div class="bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 70px; height: 70px;">
                                <i class="fas fa-times-circle fs-2"></i>
                            </div>
                            <h3 class="fw-bold text-dark mb-2">Invalid Setup Link</h3>
                            <p class="text-muted" style="font-size: 0.95rem;"><?php echo htmlspecialchars($error_msg); ?></p>
                            <a href="index.php" class="btn btn-primary mt-4 px-4 fw-bold" style="border-radius: 8px;">Return to Login Page</a>
                        </div>
                    <?php else: ?>
                    
                        <div class="mb-4">
                            <div class="bg-warning bg-opacity-10 text-warning d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 50px; height: 50px;">
                                <i class="fas fa-shield-alt fs-4"></i>
                            </div>
                            <h3 class="fw-bold text-dark mb-2">Security Setup</h3>
                            <p class="text-muted" style="font-size: 0.95rem;">Welcome, <strong class="text-dark"><?php echo htmlspecialchars($fullname); ?></strong>.<br>For your security, please setup your permanent password before proceeding.</p>
                        </div>
                        
                        <?php if(isset($_GET['error'])): ?>
                            <div class="alert alert-danger d-flex align-items-center small shadow-sm rounded-3" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <div>
                                    <?php 
                                        if($_GET['error'] == 'PasswordMismatch') echo "Passwords do not match.";
                                        elseif($_GET['error'] == 'WeakPassword') echo "Password does not meet the security requirements.";
                                        else echo htmlspecialchars($_GET['error']);
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <form action="actions/auth.php" method="POST" class="mt-4">
                            
                            <?php if($is_token_flow): ?>
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                                <input type="hidden" name="flow_type" value="token">
                            <?php else: ?>
                                <input type="hidden" name="flow_type" value="session">
                            <?php endif; ?>

                            <div class="custom-input-group">
                                <input type="password" name="new_password" id="newPass" class="custom-input" placeholder="Enter New Password" required autocomplete="off">
                                <i class="fas fa-lock input-icon-left"></i>
                                <button type="button" class="btn-toggle-pass" onclick="togglePass('newPass', 'newIcon')">
                                    <i class="fas fa-eye" id="newIcon"></i>
                                </button>
                            </div>
                            
                            <div class="custom-input-group mb-4">
                                <input type="password" name="confirm_password" id="confirmPass" class="custom-input" placeholder="Confirm New Password" required autocomplete="off">
                                <i class="fas fa-check-double input-icon-left"></i>
                                <button type="button" class="btn-toggle-pass" onclick="togglePass('confirmPass', 'confirmIcon')">
                                    <i class="fas fa-eye" id="confirmIcon"></i>
                                </button>
                            </div>
                            
                            <ul class="req-list" id="passReqs">
                                <li id="req-len" class="text-danger"><i class="fas fa-times"></i> At least 8 characters long</li>
                                <li id="req-up" class="text-danger"><i class="fas fa-times"></i> Contains an uppercase letter</li>
                                <li id="req-low" class="text-danger"><i class="fas fa-times"></i> Contains a lowercase letter</li>
                                <li id="req-num" class="text-danger"><i class="fas fa-times"></i> Contains a number</li>
                                <li id="req-match" class="text-danger"><i class="fas fa-times"></i> Passwords exactly match</li>
                            </ul>

                            <button type="submit" name="setup_password" id="btnSubmit" class="btn w-100 btn-submit fw-bold shadow-sm" disabled>
                                SECURE ACCOUNT & LOGIN <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    
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
                icon.className = "fas fa-eye-slash"; 
            } else { 
                input.type = "password"; 
                icon.className = "fas fa-eye"; 
            }
        }

        const np = document.getElementById('newPass');
        const cp = document.getElementById('confirmPass');
        const btn = document.getElementById('btnSubmit');

        if(np && cp && btn) {
            function checkReq(id, valid) {
                const el = document.getElementById(id);
                if(valid) { 
                    el.className = "text-success fw-bold"; 
                    el.querySelector('i').className = "fas fa-check text-success"; 
                } else { 
                    el.className = "text-danger"; 
                    el.querySelector('i').className = "fas fa-times text-danger"; 
                }
                return valid;
            }

            function validate() {
                let v = np.value; 
                let c = cp.value;
                
                let r1 = checkReq('req-len', v.length >= 8);
                let r2 = checkReq('req-up', /[A-Z]/.test(v));
                let r3 = checkReq('req-low', /[a-z]/.test(v));
                let r4 = checkReq('req-num', /[0-9]/.test(v));
                let r5 = checkReq('req-match', v === c && v.length > 0);
                
                btn.disabled = !(r1 && r2 && r3 && r4 && r5);
            }
            
            np.addEventListener('input', validate); 
            cp.addEventListener('input', validate);
        }
    </script>
</body>
</html>