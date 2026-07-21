<?php 
session_start();
if(isset($_SESSION['user_id'])){ header("Location: dashboard.php"); exit(); }

// Toast Message Logic
$toastError = '';
$toastSuccess = '';

if(isset($_GET['error'])) {
    if($_GET['error'] == 'ForceLoggedOutByAdmin') $toastError = "Your session was terminated by an Administrator.";
    else if($_GET['error'] == 'AccountLockedWaitAdmin') $toastError = "This account is currently locked or suspended.";
    else if($_GET['error'] == 'TooManyAttemptsWait5Mins') $toastError = "Security threshold reached. Please try again later.";
    else if($_GET['error'] == 'InvalidCredentials' || $_GET['error'] == 'WrongCredentials') $toastError = "Wrong credentials.";
    else $toastError = htmlspecialchars($_GET['error']);
}

if(isset($_GET['success'])) {
    $toastSuccess = htmlspecialchars($_GET['success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/custom_fixie.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
</head>
<body class="auth-page">

    <div class="auth-wrapper">
        
        <!-- Left Side: Dynamic Branding with Animated Gradient BG -->
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

                    <form action="actions/auth.php" method="POST">
                        
                        <!-- Floating Label for Username/Email -->
                        <div class="form-floating-custom">
                            <input type="text" id="auth_username" name="username" placeholder=" " required autocomplete="username">
                            <label for="auth_username">Username or Email</label>
                        </div>

                        <!-- Floating Label for Password with Eye Toggle Icon -->
                        <div class="form-floating-custom mb-2 position-relative">
                            <input type="password" id="auth_password" name="password" placeholder=" " required autocomplete="current-password" style="padding-right: 40px;">
                            <label for="auth_password">Password</label>
                            <i class="fas fa-eye position-absolute" id="togglePassword" style="right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; z-index: 10;"></i>
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

                    <div id="emailError" class="alert-system alert-error d-none"></div>

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
                        <p>A 6-digit code has been dispatched to<br><strong id="displayEmail" class="color-text-main"></strong></p>
                    </div>

                    <div id="verifyError" class="alert-system alert-error d-none"></div>

                    <div class="text-center d-block mb-3 fs-085rem-600">ENTER AUTH CODE</div>
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
                        <button type="button" id="btnResend" onclick="sendVerificationCode()" class="btn-link p-0 fw-bold text-decoration-none init-hidden link-resend">Resend Token</button>
                    </div>

                    <button type="button" class="btn-text-only border-0 text-muted mt-2" onclick="switchView('view-email')">
                        Change email address
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Backend Interaction Logic -->
    <script>
        // System-matched Toast Notification Trigger (Bottom Right)
        const toastError = "<?php echo $toastError; ?>";
        const toastSuccess = "<?php echo $toastSuccess; ?>";

        if(toastError || toastSuccess) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true,
                customClass: {
                    popup: 'sleek-popup small-toast shadow-sm border'
                }
            });
            
            if(toastError) {
                Toast.fire({ icon: 'error', title: toastError });
            } else if(toastSuccess) {
                Toast.fire({ icon: 'success', title: toastSuccess });
            }
            
            window.history.replaceState(null, null, window.location.pathname);
        }

        // Toggle Password Visibility
        document.getElementById('togglePassword').addEventListener('click', function () {
            const pwdInput = document.getElementById('auth_password');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                this.classList.remove('fa-eye');
                this.classList.add('fa-eye-slash');
            } else {
                pwdInput.type = 'password';
                this.classList.remove('fa-eye-slash');
                this.classList.add('fa-eye');
            }
        });

        // Setup OTP logic
        let countdownInterval;

        function switchView(viewId) {
            document.querySelectorAll('.auth-view').forEach(v => {
                v.style.display = 'none';
                v.classList.remove('active');
            });
            
            const target = document.getElementById(viewId);
            target.style.display = 'block';
            
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
                errorDiv.classList.remove('d-none');
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
                    errorDiv.classList.remove('d-none');
                    errorDiv.style.display = 'flex';
                }
            } catch (err) {
                errorDiv.innerHTML = '<i class="fas fa-wifi mt-1"></i><div>Connection failed. Please try again.</div>';
                errorDiv.classList.remove('d-none');
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
                errorDiv.classList.remove('d-none');
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
                    errorDiv.classList.remove('d-none');
                    errorDiv.style.display = 'flex';
                    inputs.forEach(i => i.value = ''); 
                    inputs[0].focus();
                    btn.classList.remove('is-processing');
                }
            } catch (err) {
                errorDiv.innerHTML = '<i class="fas fa-wifi mt-1"></i><div>Connection failed. Please try again.</div>';
                errorDiv.classList.remove('d-none');
                errorDiv.style.display = 'flex';
                btn.classList.remove('is-processing');
            }
        }

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
                    resendBtn.classList.remove('init-hidden');
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