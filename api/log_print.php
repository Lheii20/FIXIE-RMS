<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

// I-check kung naka-login ang user
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_print') {
    $user_id = $_SESSION['user_id'];
    $doc_name = $_POST['doc_name'] ?? 'Unknown Document';
    
    $desc = "Printed document: " . $doc_name;
    
    // I-log sa database gamit ang existing function sa functions.php
    log_audit_action($conn, $user_id, 'PRINT_DOC', $desc);
    
    echo json_encode(['status' => 'success']);
}
?>