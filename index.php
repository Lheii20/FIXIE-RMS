<?php
require_once __DIR__ . '/config/session_bootstrap.php';
if(isset($_SESSION['user_id'])){ header("Location: dashboard.php"); exit(); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Toast Message Logic
$toastError = '';
$toastSuccess = '';

if(isset($_GET['error'])) {
    if($_GET['error'] == 'ForceLoggedOutByAdmin') $toastError = "Your session was terminated by an Administrator.";
    else if($_GET['error'] == 'AccountLockedWaitAdmin') $toastError = "This account is currently locked or suspended.";
    else if($_GET['error'] == 'TooManyAttemptsWait5Mins') $toastError = "Security threshold reached. Please try again after 5 minutes.";
    else if($_GET['error'] == 'LoginSessionExpired') $toastError = "Your login session expired. Refresh the page and try again.";
    else if($_GET['error'] == 'SessionExpired') $toastError = "For your security, you were signed out after a period of inactivity.";
    else if($_GET['error'] == 'InvalidOrExpiredToken') $toastError = "This account-setup link is invalid, expired, or has already been used.";
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- Floating Label for Username/Email -->
                        <div class="form-floating-custom">
                            <input type="text" id="auth_username" name="username" placeholder=" " required maxlength="100" autocomplete="username" spellcheck="false">
                            <label for="auth_username">Username or Email</label>
                        </div>

                        <!-- Floating Label for Password with Eye Toggle Icon -->
                        <div class="form-floating-custom mb-2 position-relative">
                            <input type="password" id="auth_password" name="password" placeholder=" " required maxlength="255" autocomplete="current-password" style="padding-right: 40px;">
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

                    <button type="button" id="btnSendCode" class="btn-corporate btn-primary-alt mt-2" onclick="sendVerificationCode(false)">
                        <span class="spinner-mini"></span>
                        <span class="btn-label">Send Code</span>
                    </button>

                    <button type="button" class="btn-text-only border-0 text-muted" onclick="switchView('view-traditional')">
                        <i class="fas fa-arrow-left me-1"></i> Return to standard login
                    </button>
                </div>

                <!-- VIEW 3: VERIFY OTP CODE -->
                <div id="view-verify" class="auth-view">
                    <div class="auth-header">
                        <h2>Verify Identity</h2>
                        <p>A 6-digit code was sent to<br><strong id="displayEmail" class="color-text-main"></strong><br><span class="text-muted">The code expires in 5 minutes.</span></p>
                    </div>

                    <div id="verifyError" class="alert-system alert-error d-none"></div>

                    <div class="text-center d-block mb-3 fs-085rem-600">ENTER AUTH CODE</div>
                    <div class="otp-container otp-single-container">
                        <input type="text" id="loginOtpCode" class="otp-code-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" aria-label="Six-digit authentication code" placeholder="000000">
                    </div>

                    <button type="button" id="btnVerifyCode" class="btn-corporate btn-primary-alt" onclick="verifyOTP()">
                        <span class="spinner-mini"></span>
                        <span class="btn-label">Validate & Access</span>
                    </button>

                    <div class="text-center mt-4">
                        <div class="timer-display" id="timerDisplay">Resend available in 01:00</div>
                        <button type="button" id="btnResend" onclick="sendVerificationCode(true)" class="btn-link p-0 fw-bold text-decoration-none init-hidden link-resend">Resend Code</button>
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

        // Secure email OTP login
        const otpCsrfToken = <?php echo json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const otpInput = document.getElementById('loginOtpCode');
        let countdownInterval = null;

        function showOtpAlert(container, message, type = 'error') {
            container.classList.remove('d-none', 'alert-error', 'alert-success');
            container.classList.add(type === 'success' ? 'alert-success' : 'alert-error');
            container.replaceChildren();
            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-check-circle mt-1' : 'fas fa-exclamation-triangle mt-1';
            const text = document.createElement('div');
            text.textContent = message;
            container.append(icon, text);
            container.style.display = 'flex';
        }

        function hideOtpAlert(container) {
            container.style.display = 'none';
            container.replaceChildren();
        }

        function switchView(viewId) {
            document.querySelectorAll('.auth-view').forEach((view) => {
                view.style.display = 'none';
                view.classList.remove('active');
            });

            const target = document.getElementById(viewId);
            target.style.display = 'block';

            if (viewId === 'view-email') {
                clearInterval(countdownInterval);
                clearOtpInputs();
            }

            setTimeout(() => {
                target.classList.add('active');
                if (viewId === 'view-email') document.getElementById('targetEmail').focus();
                if (viewId === 'view-verify') otpInput.focus();
            }, 10);
        }

        async function otpRequest(action, payload) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', otpCsrfToken);
            Object.entries(payload).forEach(([key, value]) => formData.append(key, value));

            const response = await fetch('actions/otp_handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });

            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('The login service returned an invalid response.');
            }

            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'The request could not be completed.');
            }
            return data;
        }

        async function sendVerificationCode(isResend = false) {
            const emailField = document.getElementById('targetEmail');
            const email = emailField.value.trim().toLowerCase();
            const button = document.getElementById(isResend ? 'btnResend' : 'btnSendCode');
            const alertBox = document.getElementById(isResend ? 'verifyError' : 'emailError');

            if (!emailField.checkValidity()) {
                showOtpAlert(alertBox, 'Enter a valid registered email address.');
                emailField.focus();
                return;
            }

            hideOtpAlert(alertBox);
            button.disabled = true;
            button.classList.add('is-processing');

            try {
                const data = await otpRequest('send_code', { email });
                document.getElementById('displayEmail').textContent = email;
                clearOtpInputs();
                switchView('view-verify');
                showOtpAlert(document.getElementById('verifyError'), data.message, 'success');
                startCountdown(data.retry_after || 60);
            } catch (error) {
                showOtpAlert(alertBox, error.message);
                button.disabled = false;
            } finally {
                button.classList.remove('is-processing');
                if (!isResend) button.disabled = false;
            }
        }

        async function verifyOTP() {
            const email = document.getElementById('targetEmail').value.trim().toLowerCase();
            const code = otpInput.value;
            const button = document.getElementById('btnVerifyCode');
            const alertBox = document.getElementById('verifyError');

            if (!/^\d{6}$/.test(code)) {
                showOtpAlert(alertBox, 'Enter the complete six-digit verification code.');
                otpInput.focus();
                return;
            }

            hideOtpAlert(alertBox);
            button.disabled = true;
            button.classList.add('is-processing');

            try {
                const data = await otpRequest('verify_code', { email, code });
                clearInterval(countdownInterval);
                button.classList.remove('is-processing');
                button.style.backgroundColor = 'var(--success-text)';
                button.innerHTML = '<i class="fas fa-check me-2"></i> Access Granted';
                setTimeout(() => { window.location.href = data.redirect; }, 500);
            } catch (error) {
                showOtpAlert(alertBox, error.message);
                clearOtpInputs();
                otpInput.focus();
                button.disabled = false;
                button.classList.remove('is-processing');
            }
        }

        otpInput.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
        });

        otpInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') verifyOTP();
        });

        function clearOtpInputs() {
            otpInput.value = '';
        }

        function startCountdown(seconds = 60) {
            clearInterval(countdownInterval);
            let remaining = Math.max(1, Number(seconds) || 60);
            const display = document.getElementById('timerDisplay');
            const resendButton = document.getElementById('btnResend');

            resendButton.disabled = true;
            resendButton.style.display = 'none';
            display.style.display = 'block';

            const render = () => {
                const minutes = Math.floor(remaining / 60);
                const secondsPart = remaining % 60;
                display.textContent = `Resend available in ${String(minutes).padStart(2, '0')}:${String(secondsPart).padStart(2, '0')}`;
            };

            render();
            countdownInterval = setInterval(() => {
                remaining -= 1;
                render();
                if (remaining <= 0) {
                    clearInterval(countdownInterval);
                    display.style.display = 'none';
                    resendButton.classList.remove('init-hidden');
                    resendButton.style.display = 'inline-block';
                    resendButton.disabled = false;
                }
            }, 1000);
        }

        document.getElementById('targetEmail').addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                sendVerificationCode(false);
            }
        });
    </script>
</body>
</html>
