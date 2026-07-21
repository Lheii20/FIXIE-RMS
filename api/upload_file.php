<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { die("Unauthorized"); }

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document'])) {
    
    if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents')) {
        die("Access Denied");
    }

    // Strict CSRF Check
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header("Location: ../documents.php?error=SecurityTokenMismatch");
        exit;
    }

    $po_id = null;
    if (!empty($_POST['po_id'])) {
        if (!ctype_digit((string)$_POST['po_id'])) {
            header("Location: ../documents.php?error=InvalidPO");
            exit;
        }
        $po_id = intval($_POST['po_id']);
    }

    $category = !empty($_POST['category']) ? trim($_POST['category']) : 'Uncategorized';
    $tags = !empty($_POST['tags']) ? trim($_POST['tags']) : null;
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $file = $_FILES['document'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        header("Location: ../documents.php?error=UploadFailed");
        exit;
    }

    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
    ];
    $max_file_size = 50 * 1024 * 1024;
    if ($file['size'] < 1 || $file['size'] > $max_file_size) {
        header("Location: ../documents.php?error=FileSizeExceeded");
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMimeTypes[$detectedMime]) || $allowedMimeTypes[$detectedMime] !== $ext) {
        header("Location: ../documents.php?error=InvalidFileType");
        exit;
    }

    // Server-side validation for Expiry Date
    if ($expiry_date) {
        $current_date = date('Y-m-d');
        if ($expiry_date < $current_date) {
            header("Location: ../documents.php?error=Invalid Expiry Date. You cannot select a date in the past.");
            exit;
        }
    }

    $base_name = pathinfo($file['name'], PATHINFO_FILENAME);
    $max_len = 150 - strlen($ext) - 1;
    $base_name = substr($base_name, 0, $max_len);
    $sanitized_file_name = preg_replace("/[^a-zA-Z0-9._ -]/", "_", $base_name . '.' . $ext);
    $document_title = !empty($_POST['document_title']) ? trim($_POST['document_title']) : $sanitized_file_name;
    
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
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        header("Location: ../documents.php?error=FileSaveError");
        exit;
    }
    $physicalFileName = time() . "_" . bin2hex(random_bytes(4)) . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $sanitized_file_name);
    $targetFilePath = $targetDir . $physicalFileName;
    $dbPath = "uploads/" . $physicalFileName;

    if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
        $stmt = $conn->prepare("INSERT INTO documents (po_id, file_name, file_path, file_hash, uploaded_by, category, tags, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssisss", $po_id, $document_title, $dbPath, $fileHash, $_SESSION['user_id'], $category, $tags, $expiry_date);
        
        if ($stmt->execute()) {
            $doc_id = $stmt->insert_id;
            $desc = "Indexed and uploaded Official Record: " . $document_title . " [" . $category . "]";
            log_document_action($conn, $_SESSION['user_id'], 'UPLOAD_RECORD', $doc_id, $desc, $_SERVER['REQUEST_URI'] ?? null);
            
            header("Location: ../documents.php?success=Record successfully indexed and saved to repository.");
        } else {
            unlink($targetFilePath);
            header("Location: ../documents.php?error=Database insert error.");
        }
    } else {
        header("Location: ../documents.php?error=File save error.");
    }
}
?>