<?php 
session_start();
if(isset($_SESSION['user_id'])){ header("Location: dashboard.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <!-- Professional Enterprise Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
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

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
            display: flex;
        }

        /* --- Split Layout Container --- */
        .auth-wrapper {
            display: flex;
            width: 100%;
            height: 100%;
        }

        /* --- Left Side: Dynamic Branding Panel --- */
        .brand-panel {
            flex: 1;
            /* Animated Dark Gradient Background */
            background: linear-gradient(-45deg, #0f172a, #1e293b, #1e3a8a, #0f172a);
            background-size: 400% 400%;
            animation: gradientAnim 15s ease infinite;
            color: #ffffff;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        @keyframes gradientAnim {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Subtle overlay pattern para hindi plain */
        .brand-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: radial-gradient(rgba(255, 255, 255, 0.07) 2px, transparent 2px);
            background-size: 40px 40px;
            z-index: 0;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 120%; height: 120%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.2) 0%, transparent 60%);
            z-index: 0;
        }

        .brand-center-content {
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* --- Logo with Transparent Drop Shadow & Hover Animation --- */
        .main-logo {
            width: 160px; 
            height: auto;
            max-height: 160px;
            object-fit: contain;
            margin-bottom: 2rem;
            /* Transparent background, shadow directly applied to image shape */
            filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.4));
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
        }

        .main-logo:hover {
            transform: translateY(-8px) scale(1.05);
            filter: drop-shadow(0 20px 25px rgba(0, 0, 0, 0.6));
        }

        .sys-title {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 0.5rem;
            color: #ffffff;
            text-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }

        .sys-company {
            font-size: 1.15rem;
            font-weight: 500;
            color: #94a3b8;
            letter-spacing: 0.5px;
        }

        .system-meta { 
            position: absolute;
            bottom: 2rem;
            z-index: 1; 
            font-size: 0.8rem; 
            color: #64748b; 
            font-weight: 500; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
        }

        /* --- Right Side: Form Panel --- */
        .form-panel {
            width: 100%;
            max-width: 600px;
            background: #ffffff;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: -15px 0 40px rgba(0, 0, 0, 0.05);
            z-index: 10;
            overflow-y: auto;
        }

        .form-container { width: 100%; max-width: 360px; margin: 0 auto; }

        .auth-header { margin-bottom: 2.5rem; text-align: center; }
        .auth-header h2 { font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem; letter-spacing: -0.5px; }
        .auth-header p { font-size: 0.95rem; color: var(--text-muted); margin: 0; }

        /* --- Floating Label Animations --- */
        .form-floating-custom {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-floating-custom input {
            width: 100%;
            padding: 1.1rem 1rem 0.9rem 1rem;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            background-color: transparent;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .form-floating-custom label {
            position: absolute;
            top: 50%;
            left: 1rem;
            transform: translateY(-50%);
            font-size: 0.95rem;
            color: var(--text-muted);
            pointer-events: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            background-color: #ffffff;
            padding: 0 0.4rem;
            margin: 0 -0.4rem; 
        }

        .form-floating-custom input:focus,
        .form-floating-custom input:not(:placeholder-shown) {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .form-floating-custom input:focus ~ label,
        .form-floating-custom input:not(:placeholder-shown) ~ label {
            top: 0;
            transform: translateY(-50%) scale(0.85);
            color: var(--brand-primary);
            font-weight: 600;
        }

        /* --- Buttons --- */
        .btn-corporate {
            width: 100%;
            padding: 0.9rem;
            background-color: var(--brand-dark);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .btn-corporate:hover { 
            background-color: #1e293b; 
            transform: translateY(-2px);
            box-shadow: 0 6px 10px -2px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary-alt { background-color: var(--brand-primary); }
        .btn-primary-alt:hover { background-color: var(--brand-primary-hover); }

        .btn-text-only {
            width: 100%;
            padding: 0.85rem;
            background: transparent;
            color: var(--text-main);
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 1rem;
        }
        .btn-text-only:hover { background: var(--bg-light); border-color: #94a3b8; }

        .forgot-pass {
            display: block;
            text-align: right;
            font-size: 0.85rem;
            color: var(--brand-primary);
            text-decoration: none;
            font-weight: 600;
            margin-top: -0.10rem;
            margin-bottom: 1.5rem;
            transition: color 0.2s;
        }
        .forgot-pass:hover { color: var(--brand-primary-hover); text-decoration: underline; }

        /* --- OTP Inputs --- */
        .otp-container { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 2rem; direction: ltr; }
        .otp-box {
            width: 100%; aspect-ratio: 1;
            text-align: center; font-size: 1.25rem; font-weight: 700;
            border: 1.5px solid var(--border-color); border-radius: 8px;
            background: #ffffff; transition: all 0.2s;
            color: var(--brand-dark); padding: 0;
        }
        .otp-box:focus { border-color: var(--brand-primary); outline: none; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15); }

        /* --- Utilities --- */
        .divider { display: flex; align-items: center; text-align: center; margin: 1.5rem 0; color: #94a3b8; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;}
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid var(--border-color); }
        .divider::before { margin-right: 1em; }
        .divider::after { margin-left: 1em; }
        
        .alert-system { padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 10px; font-weight: 500; border-left: 4px solid transparent;}
        .alert-error { background: var(--error-bg); color: var(--error-text); border-color: #fca5a5; border-left-color: var(--error-text); }
        .alert-success { background: var(--success-bg); color: var(--success-text); border-color: #bbf7d0; border-left-color: var(--success-text); }

        /* View Switching */
        .auth-view { display: none; opacity: 0; transition: opacity 0.2s ease; }
        .auth-view.active { display: block; opacity: 1; animation: fadeIn 0.3s ease forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* Loading Spinner */
        .spinner-mini { display: none; width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 0.8s linear infinite; }
        .is-processing .spinner-mini { display: inline-block; }
        .is-processing .btn-label { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .timer-display { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); }

        /* --- Responsive Adjustments --- */
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
        
        <!-- Left Side: Dynamic Branding -->
        <div class="brand-panel">
            <div class="brand-center-content">
                <!-- Transparent Logo with Hover Animation -->
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

                <!-- VIEW 1: TRADITIONAL LOGIN -->
                <div id="view-traditional" class="auth-view active">
                    <div class="auth-header">
                        <h2>Sign In</h2>
                        <p>Please enter your credentials to access the system.</p>
                    </div>

                    <?php if(isset($_GET['error'])): ?>
                        <?php
                            $err_msg = "Authentication Failed. Please verify your credentials.";
                            if($_GET['error'] == 'ForceLoggedOutByAdmin') $err_msg = "Your session was terminated by an Administrator.";
                            else if($_GET['error'] == 'AccountLockedWaitAdmin') $err_msg = "This account is currently locked or suspended.";
                            else if($_GET['error'] == 'TooManyAttemptsWait5Mins') $err_msg = "Security threshold reached. Please try again later.";
                            else if($_GET['error'] == 'InvalidCredentials') $err_msg = "The username or password provided is incorrect.";
                        ?>
                        <div class="alert-system alert-error">
                            <i class="fas fa-exclamation-triangle mt-1"></i>
                            <div><?php echo htmlspecialchars($err_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="actions/auth.php" method="POST">
                        
                        <!-- Floating Label for Username/Email -->
                        <div class="form-floating-custom">
                            <input type="text" id="auth_username" name="username" placeholder=" " required autocomplete="username">
                            <label for="auth_username">Username or Email</label>
                        </div>

                        <!-- Floating Label for Password -->
                        <div class="form-floating-custom mb-2">
                            <input type="password" id="auth_password" name="password" placeholder=" " required autocomplete="current-password">
                            <label for="auth_password">Password</label>
                        </div>
                        
                        <a href="forgot_password.php" class="forgot-pass" tabindex="-1">Forgot Password?</a>

                        <button type="submit" name="login" class="btn-corporate">Log in</button>
                    </form>

                    <div class="divider">Alternative Access</div>

                    <button type="button" class="btn-text-only" onclick="switchView('view-email')">
                        <i class="fas fa-envelope text-muted me-2"></i> Log in via Email OTP
                    </button>
                </div>

                <!-- VIEW 2: ENTER EMAIL FOR OTP -->
                <div id="view-email" class="auth-view">
                    <div class="auth-header">
                        <h2>Passwordless Login</h2>
                        <p>Request a secure verification code.</p>
                    </div>

                    <div id="emailError" class="alert-system alert-error" style="display:none;"></div>

                    <!-- Floating Label for OTP Email -->
                    <div class="form-floating-custom">
                        <input type="email" id="targetEmail" placeholder=" " required>
                        <label for="targetEmail">Registered Email</label>
                    </div>

                    <button type="button" id="btnSendCode" class="btn-corporate btn-primary-alt mt-2" onclick="sendVerificationCode()">
                        <span class="spinner-mini"></span>
                        <span class="btn-label">Transmit Code</span>
                    </button>

                    <button type="button" class="btn-text-only border-0 text-muted" onclick="switchView('view-traditional')">
                        <i class="fas fa-arrow-left me-1"></i> Return to standard login
                    </button>
                </div>

                <!-- VIEW 3: VERIFY OTP CODE -->
                <div id="view-verify" class="auth-view">
                    <div class="auth-header">
                        <h2>Verify Identity</h2>
                        <p>A 6-digit code has been dispatched to<br><strong id="displayEmail" style="color: var(--text-main);"></strong></p>
                    </div>

                    <div id="verifyError" class="alert-system alert-error" style="display:none;"></div>

                    <div class="text-center d-block mb-3" style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">ENTER AUTH CODE</div>
                    <div class="otp-container" id="otpInputs">
                        <input type="text" class="otp-box" maxlength="1" autofocus>
                        <input type="text" class="otp-box" maxlength="1">
                        <input type="text" class="otp-box" maxlength="1">
                        <input type="text" class="otp-box" maxlength="1">
                        <input type="text" class="otp-box" maxlength="1">
                        <input type="text" class="otp-box" maxlength="1">
                    </div>

                    <button type="button" id="btnVerifyCode" class="btn-corporate btn-primary-alt" onclick="verifyOTP()">
                        <span class="spinner-mini"></span>
                        <span class="btn-label">Validate & Access</span>
                    </button>

                    <div class="text-center mt-4">
                        <div class="timer-display" id="timerDisplay">Token valid for 05:00</div>
                        <button type="button" id="btnResend" onclick="sendVerificationCode()" class="btn btn-link p-0 fw-bold text-decoration-none" style="display:none; font-size: 0.85rem; color: var(--brand-primary);">Resend Token</button>
                    </div>

                    <button type="button" class="btn-text-only border-0 text-muted mt-2" onclick="switchView('view-email')">
                        Change email address
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Backend Interaction Logic -->
    <script>
        let countdownInterval;

        function switchView(viewId) {
            document.querySelectorAll('.auth-view').forEach(v => {
                v.style.display = 'none';
                v.classList.remove('active');
            });
            
            const target = document.getElementById(viewId);
            target.style.display = 'block';
            
            // Allow display block to render before triggering opacity transition
            setTimeout(() => {
                target.classList.add('active');
                if(viewId === 'view-email') document.getElementById('targetEmail').focus();
                else if(viewId === 'view-verify') document.querySelector('.otp-box').focus();
            }, 10);
        }

        async function sendVerificationCode() {
            const email = document.getElementById('targetEmail').value.trim();
            const btn = document.getElementById('btnSendCode');
            const errorDiv = document.getElementById('emailError');

            if(!email) {
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle mt-1"></i><div>Email is required.</div>';
                errorDiv.style.display = 'flex';
                return;
            }

            errorDiv.style.display = 'none';
            btn.classList.add('is-processing');

            const formData = new FormData();
            formData.append('action', 'send_code');
            formData.append('email', email);

            try {
                const response = await fetch('actions/otp_handler.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if(data.status === 'success') {
                    document.getElementById('displayEmail').innerText = email;
                    switchView('view-verify');
                    startCountdown();
                    clearOtpInputs();
                } else {
                    errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle mt-1"></i><div>${data.message}</div>`;
                    errorDiv.style.display = 'flex';
                }
            } catch (err) {
                errorDiv.innerHTML = '<i class="fas fa-wifi mt-1"></i><div>Connection failed. Please try again.</div>';
                errorDiv.style.display = 'flex';
            }
            btn.classList.remove('is-processing');
        }

        async function verifyOTP() {
            const email = document.getElementById('targetEmail').value.trim();
            const inputs = document.querySelectorAll('.otp-box');
            let code = '';
            inputs.forEach(input => code += input.value);

            const btn = document.getElementById('btnVerifyCode');
            const errorDiv = document.getElementById('verifyError');

            if(code.length !== 6) {
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle mt-1"></i><div>Incomplete token.</div>';
                errorDiv.style.display = 'flex';
                return;
            }

            errorDiv.style.display = 'none';
            btn.classList.add('is-processing');

            const formData = new FormData();
            formData.append('action', 'verify_code');
            formData.append('email', email);
            formData.append('code', code);

            try {
                const response = await fetch('actions/otp_handler.php', { method: 'POST', body: formData });
                const data = await response.json();

                if(data.status === 'success') {
                    btn.classList.remove('is-processing');
                    btn.style.backgroundColor = 'var(--success-text)';
                    btn.innerHTML = '<i class="fas fa-check me-2"></i> Access Granted';
                    setTimeout(() => { window.location.href = data.redirect; }, 500);
                } else {
                    errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle mt-1"></i><div>${data.message}</div>`;
                    errorDiv.style.display = 'flex';
                    inputs.forEach(i => i.value = ''); 
                    inputs[0].focus();
                    btn.classList.remove('is-processing');
                }
            } catch (err) {
                errorDiv.innerHTML = '<i class="fas fa-wifi mt-1"></i><div>Connection failed. Please try again.</div>';
                errorDiv.style.display = 'flex';
                btn.classList.remove('is-processing');
            }
        }

        // OTP Input UX Handling
        const otpInputs = document.querySelectorAll('.otp-box');
        otpInputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                input.value = input.value.replace(/[^0-9]/g, ''); 
                if (input.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && input.value === '' && index > 0) {
                    otpInputs[index - 1].focus();
                }
                if (e.key === 'Enter') {
                    verifyOTP();
                }
            });
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                pastedData.split('').forEach((char, i) => {
                    if (otpInputs[i]) { otpInputs[i].value = char; }
                });
                if(pastedData.length === 6) { verifyOTP(); }
            });
        });

        function clearOtpInputs() {
            otpInputs.forEach(i => i.value = '');
        }

        function startCountdown() {
            clearInterval(countdownInterval);
            let time = 300; 
            const display = document.getElementById('timerDisplay');
            const resendBtn = document.getElementById('btnResend');
            
            resendBtn.style.display = 'none';
            display.style.display = 'block';

            countdownInterval = setInterval(() => {
                const minutes = Math.floor(time / 60);
                const seconds = time % 60;
                display.innerText = `Token valid for 0${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                time--;

                if (time < 0) {
                    clearInterval(countdownInterval);
                    display.style.display = 'none';
                    resendBtn.style.display = 'inline-block';
                }
            }, 1000);
        }
        
        document.getElementById('targetEmail').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendVerificationCode();
            }
        });
    </script>
</body>
</html>