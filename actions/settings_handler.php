<?php
session_start();
require '../config/db_connect.php';

// Security check: Must be an Admin to update global settings
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') { 
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_settings') {
    
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: ../admin_settings.php?error=SecurityTokenMismatch");
        exit();
    }

    // Get input values
    $session_timeout = intval($_POST['session_timeout']);
    $max_upload_size = intval($_POST['max_upload_size']);

    // Prepare Update Statement
    $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    
    // Update Session Timeout
    $key = 'session_timeout'; 
    $val = (string)$session_timeout;
    $stmt->bind_param("ss", $val, $key); 
    $stmt->execute();
    
    // Update Max Upload Size
    $key = 'max_upload_size'; 
    $val = (string)$max_upload_size;
    $stmt->bind_param("ss", $val, $key); 
    $stmt->execute();

    // Audit Trail Log
    if (function_exists('log_audit_action')) {
        log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_SETTINGS', 'Admin updated global system settings (Session Timeout: ' . $session_timeout . ' mins, Max Upload Size: ' . $max_upload_size . ' MB).');
    }

    header("Location: ../admin_settings.php?success=SettingsUpdated");
    exit();
}

header("Location: ../admin_settings.php");
exit();
?>