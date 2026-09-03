<?php
require_once __DIR__ . '/config/session_bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$notice = trim($_GET['notice'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Recovery - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="page-forgot-password">
    <div class="auth-wrapper">
        <div class="brand-panel">
            <div class="brand-center-content">
                <img src="assets/images/fixie_logo.png" alt="Fixie Logo" class="main-logo" onerror="this.style.display='none'">
                <div class="sys-title">Record Management System</div>
                <div class="sys-company">Fixie Computer Ventures</div>
            </div>
            <div class="system-meta">&copy; <?php echo date('Y'); ?> Fixie Computer Ventures<br>Restricted System Access</div>
        </div>

        <main class="form-panel">
            <div class="form-container recovery-container">
                <div class="recovery-progress" aria-label="Password recovery progress">
                    <div class="recovery-progress-item is-active" data-progress-step="1"><span>1</span><small>Email</small></div>
                    <div class="recovery-progress-line"></div>
                    <div class="recovery-progress-item" data-progress-step="2"><span>2</span><small>Verify</small></div>
                    <div class="recovery-progress-line"></div>
                    <div class="recovery-progress-item" data-progress-step="3"><span>3</span><small>Password</small></div>
                </div>

                <?php if ($notice !== ''): ?>
                    <div class="alert-system alert-info recovery-server-notice">
                        <i class="fas fa-circle-info"></i>
                        <div><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <div id="recoveryAlert" class="alert-system recovery-alert" role="alert" aria-live="polite" hidden>
                    <i class="fas fa-circle-info"></i><div></div>
                </div>

                <section class="auth-view active recovery-step" id="recoveryStepEmail" data-step="1">
                    <div class="auth-header">
                        <span class="auth-eyebrow">Secure account recovery</span>
                        <h2>Forgot your password?</h2>
                        <p>Enter your registered email address. We will send a six-digit verification code instead of a reset link.</p>
                    </div>

                    <form id="recoveryEmailForm" novalidate>
                        <div class="form-floating-custom">
                            <input type="email" id="recoveryEmail" name="email" placeholder=" " required autocomplete="email" maxlength="100">
                            <label for="recoveryEmail">Registered Email Address</label>
                        </div>
                        <button type="submit" class="btn-corporate" id="sendOtpButton">
                            <i class="fas fa-paper-plane"></i><span>Send Verification Code</span>
                        </button>
                        <div class="mt-2">
                            <button type="button" class="btn-text-only recovery-existing-code-button" id="useExistingCodeButton">
                                <i class="fas fa-key"></i><span>I Already Have a Code</span>
                            </button>
                        </div>
                    </form>

                    <div class="recovery-security-note">
                        <i class="fas fa-shield-halved"></i>
                        <span>For security, the same confirmation is shown whether or not the email is registered.</span>
                    </div>
                </section>

                <section class="auth-view recovery-step" id="recoveryStepOtp" data-step="2" hidden>
                    <div class="auth-header">
                        <span class="auth-eyebrow">Identity verification</span>
                        <h2>Enter verification code</h2>
                        <p>Enter the six-digit code for <strong id="recoveryEmailDisplay"></strong>. The code expires in 10 minutes.</p>
                    </div>

                    <form id="recoveryOtpForm" novalidate>
                        <div class="recovery-otp-group" role="group" aria-label="Six-digit verification code">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 2">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 3">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 4">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 5">
                            <input class="recovery-otp-input" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 6">
                        </div>
                        <button type="submit" class="btn-corporate" id="verifyOtpButton">
                            <i class="fas fa-shield"></i><span>Verify Code</span>
                        </button>
                    </form>

                    <div class="recovery-inline-actions">
                        <button type="button" class="btn-text-only" id="changeEmailButton"><i class="fas fa-arrow-left"></i> Change email</button>
                        <button type="button" class="btn-text-only" id="resendOtpButton" disabled>Resend in <span>60</span>s</button>
                    </div>
                </section>

                <section class="auth-view recovery-step" id="recoveryStepPassword" data-step="3" hidden>
                    <div class="auth-header">
                        <span class="auth-eyebrow">Final step</span>
                        <h2>Create a new password</h2>
                        <p>Use a password that is different from your current password.</p>
                    </div>

                    <form id="recoveryPasswordForm" novalidate>
                        <div class="form-floating-custom recovery-password-field">
                            <input type="password" id="newRecoveryPassword" name="new_password" placeholder=" " required autocomplete="new-password" maxlength="128">
                            <label for="newRecoveryPassword">New Password</label>
                            <button type="button" class="recovery-password-toggle" data-target="newRecoveryPassword" aria-label="Show new password"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="form-floating-custom recovery-password-field">
                            <input type="password" id="confirmRecoveryPassword" name="confirm_password" placeholder=" " required autocomplete="new-password" maxlength="128">
                            <label for="confirmRecoveryPassword">Confirm New Password</label>
                            <button type="button" class="recovery-password-toggle" data-target="confirmRecoveryPassword" aria-label="Show confirmed password"><i class="fas fa-eye"></i></button>
                        </div>

                        <div class="recovery-password-rules" aria-label="Password requirements">
                            <span data-rule="length"><i class="fas fa-circle"></i> At least 8 characters</span>
                            <span data-rule="upper"><i class="fas fa-circle"></i> One uppercase letter</span>
                            <span data-rule="lower"><i class="fas fa-circle"></i> One lowercase letter</span>
                            <span data-rule="number"><i class="fas fa-circle"></i> One number</span>
                        </div>

                        <button type="submit" class="btn-corporate" id="resetPasswordButton">
                            <i class="fas fa-lock"></i><span>Update Password</span>
                        </button>
                    </form>
                </section>

                <div class="divider">Back to your account</div>
                <a href="index.php" class="btn-text-only recovery-login-link"><i class="fas fa-arrow-left"></i> Return to Login</a>
            </div>
        </main>
    </div>

    <script>
    (() => {
        'use strict';
        const endpoint = 'actions/password_recovery_otp.php';
        const csrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const steps = [...document.querySelectorAll('.recovery-step')];
        const progressItems = [...document.querySelectorAll('.recovery-progress-item')];
        const alertBox = document.getElementById('recoveryAlert');
        const emailInput = document.getElementById('recoveryEmail');
        const emailDisplay = document.getElementById('recoveryEmailDisplay');
        const otpInputs = [...document.querySelectorAll('.recovery-otp-input')];
        const resendButton = document.getElementById('resendOtpButton');
        const existingCodeButton = document.getElementById('useExistingCodeButton');
        const newPassword = document.getElementById('newRecoveryPassword');
        const confirmPassword = document.getElementById('confirmRecoveryPassword');
        let resendTimer = null;
        let recoveryEmail = '';

        function showStep(stepNumber) {
            steps.forEach((step) => {
                const active = Number(step.dataset.step) === stepNumber;
                step.hidden = !active;
                step.classList.toggle('active', active);
            });
            progressItems.forEach((item) => {
                const itemStep = Number(item.dataset.progressStep);
                item.classList.toggle('is-active', itemStep === stepNumber);
                item.classList.toggle('is-complete', itemStep < stepNumber);
                item.querySelector('span').textContent = itemStep < stepNumber ? '✓' : String(itemStep);
            });
            hideAlert();
            window.setTimeout(() => {
                if (stepNumber === 1) emailInput.focus();
                if (stepNumber === 2) otpInputs[0].focus();
                if (stepNumber === 3) newPassword.focus();
            }, 60);
        }

        function showAlert(message, type = 'error') {
            alertBox.className = `alert-system recovery-alert alert-${type}`;
            alertBox.querySelector('i').className = type === 'success' ? 'fas fa-circle-check' : (type === 'info' ? 'fas fa-circle-info' : 'fas fa-triangle-exclamation');
            alertBox.querySelector('div').textContent = message;
            alertBox.hidden = false;
        }

        function hideAlert() {
            alertBox.hidden = true;
            alertBox.querySelector('div').textContent = '';
        }

        function setBusy(button, busy, busyLabel) {
            if (!button.dataset.defaultLabel) button.dataset.defaultLabel = button.querySelector('span').textContent;
            button.disabled = busy;
            button.classList.toggle('is-processing', busy);
            button.querySelector('span').textContent = busy ? busyLabel : button.dataset.defaultLabel;
        }

        async function request(action, payload = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);
            Object.entries(payload).forEach(([key, value]) => formData.append(key, value));
            const response = await fetch(endpoint, {
                method: 'POST', body: formData, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) throw new Error('The recovery service returned an invalid response.');
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'The request could not be completed.');
            return data;
        }

        function startResendCountdown(seconds = 60) {
            window.clearInterval(resendTimer);
            let remaining = Math.max(1, Number(seconds) || 60);
            resendButton.disabled = true;
            resendButton.innerHTML = `Resend in <span>${remaining}</span>s`;
            resendTimer = window.setInterval(() => {
                remaining -= 1;
                const counter = resendButton.querySelector('span');
                if (counter) counter.textContent = Math.max(remaining, 0);
                if (remaining <= 0) {
                    window.clearInterval(resendTimer);
                    resendButton.disabled = false;
                    resendButton.textContent = 'Resend code';
                }
            }, 1000);
        }

        function clearOtp() { otpInputs.forEach((input) => { input.value = ''; }); }

        document.getElementById('recoveryEmailForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('sendOtpButton');
            const email = emailInput.value.trim().toLowerCase();
            if (!emailInput.checkValidity()) {
                showAlert('Enter a valid registered email address.'); emailInput.focus(); return;
            }
            hideAlert(); setBusy(button, true, 'Sending Code...');
            try {
                const data = await request('send_code', { email });
                recoveryEmail = email; emailDisplay.textContent = email; clearOtp(); showStep(2);
                showAlert(data.message, 'success'); startResendCountdown(data.retry_after || 60);
            } catch (error) { showAlert(error.message); }
            finally { setBusy(button, false, 'Sending Code...'); }
        });

        existingCodeButton.addEventListener('click', async () => {
            const email = emailInput.value.trim().toLowerCase();
            if (!emailInput.checkValidity()) {
                showAlert('Enter the registered email address that received the code.'); emailInput.focus(); return;
            }
            hideAlert(); setBusy(existingCodeButton, true, 'Preparing...');
            try {
                const data = await request('use_existing_code', { email });
                recoveryEmail = email; emailDisplay.textContent = email; clearOtp(); showStep(2);
                showAlert(data.message, 'success'); startResendCountdown(60);
            } catch (error) { showAlert(error.message); }
            finally { setBusy(existingCodeButton, false, 'Preparing...'); }
        });

        document.getElementById('recoveryOtpForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('verifyOtpButton');
            const code = otpInputs.map((input) => input.value).join('');
            if (!/^\d{6}$/.test(code)) {
                showAlert('Enter the complete six-digit verification code.');
                (otpInputs.find((input) => !input.value) || otpInputs[0]).focus(); return;
            }
            hideAlert(); setBusy(button, true, 'Verifying...');
            try {
                await request('verify_code', { email: recoveryEmail, code });
                showStep(3); showAlert('Email verified. You may now create a new password.', 'success');
            } catch (error) { showAlert(error.message); clearOtp(); otpInputs[0].focus(); }
            finally { setBusy(button, false, 'Verifying...'); }
        });

        document.getElementById('recoveryPasswordForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('resetPasswordButton');
            const password = newPassword.value;
            const confirmation = confirmPassword.value;
            if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,128}$/.test(password)) {
                showAlert('Complete all password requirements before continuing.'); newPassword.focus(); return;
            }
            if (password !== confirmation) {
                showAlert('The password confirmation does not match.'); confirmPassword.focus(); return;
            }
            hideAlert(); setBusy(button, true, 'Updating Password...');
            try {
                const data = await request('reset_password', { new_password: password, confirm_password: confirmation });
                showAlert(data.message, 'success'); button.querySelector('span').textContent = 'Password Updated';
                window.setTimeout(() => { window.location.href = data.redirect; }, 900);
            } catch (error) { showAlert(error.message); setBusy(button, false, 'Updating Password...'); }
        });

        document.getElementById('changeEmailButton').addEventListener('click', () => {
            window.clearInterval(resendTimer); clearOtp(); showStep(1);
        });

        resendButton.addEventListener('click', async () => {
            if (resendButton.disabled) return;
            resendButton.disabled = true; hideAlert();
            try {
                const data = await request('send_code', { email: recoveryEmail });
                clearOtp(); showAlert(data.message, 'success'); startResendCountdown(data.retry_after || 60); otpInputs[0].focus();
            } catch (error) { showAlert(error.message); resendButton.disabled = false; }
        });

        otpInputs.forEach((input, index) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                if (input.value && index < otpInputs.length - 1) otpInputs[index + 1].focus();
            });
            input.addEventListener('keydown', (event) => {
                if (/^\d$/.test(event.key)) {
                    event.preventDefault();
                    input.value = event.key;
                    if (index < otpInputs.length - 1) otpInputs[index + 1].focus();
                    return;
                }
                if (event.key === 'Backspace' && !input.value && index > 0) otpInputs[index - 1].focus();
                if (event.key === 'ArrowLeft' && index > 0) otpInputs[index - 1].focus();
                if (event.key === 'ArrowRight' && index < otpInputs.length - 1) otpInputs[index + 1].focus();
            });
            input.addEventListener('paste', (event) => {
                event.preventDefault();
                const digits = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
                digits.split('').forEach((digit, position) => { if (otpInputs[position]) otpInputs[position].value = digit; });
                if (digits.length) otpInputs[Math.min(digits.length, 6) - 1].focus();
            });
        });

        document.querySelectorAll('.recovery-password-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const field = document.getElementById(button.dataset.target);
                const reveal = field.type === 'password';
                field.type = reveal ? 'text' : 'password';
                button.querySelector('i').className = reveal ? 'fas fa-eye-slash' : 'fas fa-eye';
                button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            });
        });

        function updatePasswordRules() {
            const value = newPassword.value;
            const checks = { length: value.length >= 8, upper: /[A-Z]/.test(value), lower: /[a-z]/.test(value), number: /\d/.test(value) };
            Object.entries(checks).forEach(([rule, valid]) => {
                const item = document.querySelector(`[data-rule="${rule}"]`);
                item.classList.toggle('is-valid', valid);
                item.querySelector('i').className = valid ? 'fas fa-circle-check' : 'fas fa-circle';
            });
        }

        newPassword.addEventListener('input', updatePasswordRules);
        showStep(1);
    })();
    </script>
</body>
</html>
