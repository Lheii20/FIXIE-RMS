<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

// Siguraduhing logged in ang user at may ipinasang data
if(isset($_SESSION['user_id']) && isset($_POST['action']) && isset($_POST['desc'])) {
    
    $action = trim($_POST['action']);
    $desc = trim($_POST['desc']);
    
    // Anti-Spam: Para hindi mag-log ng paulit-ulit kung pinipindot-pindot ng user ang tab nang mabilis
    $current_time = time();
    if(!isset($_SESSION['last_ajax_desc']) || $_SESSION['last_ajax_desc'] !== $desc || ($current_time - ($_SESSION['last_ajax_time'] ?? 0)) > 2) {
        
        // I-save sa audit trail
        log_audit_action($conn, $_SESSION['user_id'], $action, $desc);
        
        // I-update ang huling activity
        $_SESSION['last_ajax_desc'] = $desc;
        $_SESSION['last_ajax_time'] = $current_time;
    }
    
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or incomplete data']);
}
?>