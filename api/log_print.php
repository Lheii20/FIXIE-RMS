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
    
    if (isset($_POST['doc_id']) && ctype_digit($_POST['doc_id'])) {
        log_document_action($conn, $user_id, 'PRINT_DOC', intval($_POST['doc_id']), $desc, $_SERVER['REQUEST_URI'] ?? null);
    } else {
        log_audit_action($conn, $user_id, 'PRINT_DOC', $desc);
    }
    
    echo json_encode(['status' => 'success']);
}
?>