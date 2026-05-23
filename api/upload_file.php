<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { die("Unauthorized"); }

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document'])) {
    
    // Softer CSRF Check
    if (isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF Token Mismatch in Official Record Upload.");
        }
    }

    $po_id = !empty($_POST['po_id']) ? $_POST['po_id'] : NULL;
    $category = !empty($_POST['category']) ? $_POST['category'] : 'Uncategorized';
    $tags = !empty($_POST['tags']) ? $_POST['tags'] : NULL;
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
    $file = $_FILES['document'];
    
    // Server-side validation for Expiry Date
    if ($expiry_date) {
        $current_date = date('Y-m-d');
        if ($expiry_date < $current_date) {
            header("Location: ../documents.php?error=Invalid Expiry Date. You cannot select a date in the past.");
            exit;
        }
    }

    $document_title = !empty($_POST['document_title']) ? trim($_POST['document_title']) : basename($file['name']);
    
    $fileHash = hash_file('sha256', $file['tmp_name']);

    $check = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
    $check->bind_param("s", $fileHash);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        header("Location: ../documents.php?error=Duplicate file detected! This document already exists.");
        exit;
    }

    $targetDir = "../uploads/";
    $physicalFileName = time() . "_" . basename($file['name']);
    $targetFilePath = $targetDir . $physicalFileName;

    if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
        $stmt = $conn->prepare("INSERT INTO documents (po_id, file_name, file_path, file_hash, uploaded_by, category, tags, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssisss", $po_id, $document_title, $targetFilePath, $fileHash, $_SESSION['user_id'], $category, $tags, $expiry_date);
        
        if ($stmt->execute()) {
            $desc = "Indexed and uploaded Official Record: " . $document_title . " [" . $category . "]";
            log_audit_action($conn, $_SESSION['user_id'], 'UPLOAD_RECORD', $desc);
            
            header("Location: ../documents.php?success=Record successfully indexed and saved to repository.");
        } else {
            header("Location: ../documents.php?error=Database insert error.");
        }
    } else {
        header("Location: ../documents.php?error=File save error.");
    }
}
?>