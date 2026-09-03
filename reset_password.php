<?php
require_once __DIR__ . '/config/session_bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$notice = 'Password recovery now uses a secure verification code sent to your email.';
header('Location: forgot_password.php?notice=' . urlencode($notice));
exit();
