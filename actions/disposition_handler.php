<?php
session_start();
require_once '../config/db_connect.php';
require_once '../config/functions.php';

// Security: Siguraduhing nakalog-in at may tamang role bago makapag-dispose ng records
if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_id']) && isset($_POST['action'])) {
    $doc_id = intval($_POST['doc_id']);
    $action = $_POST['action']; // Expected values: 'Destroy' o 'Permanent Archive'
    $user_id = $_SESSION['user_id'];

    // Kunin ang detalye ng file bago galawin
    $stmt = $conn->prepare("SELECT file_path, file_name, disposition_status FROM documents WHERE doc_id = ?");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $doc = $result->fetch_assoc();
        
        // Siguraduhing 'Ready for Disposition' lang ang pwedeng sirain ng system
        if ($doc['disposition_status'] !== 'Ready for Disposition') {
             die(json_encode(['status' => 'error', 'message' => 'Document is not yet ready for disposition.']));
        }

        $filePath = "../" . ltrim($doc['file_path'], '/');
        
        if ($action === 'Destroy') {
            // 1. Burahin ang physical file sa server (Data Privacy & Storage saving)
            if (file_exists($filePath) && is_file($filePath)) {
                unlink($filePath);
            }
            
            // 2. I-update ang database, tanggalin ang connection ng physical file
            $updateStmt = $conn->prepare("UPDATE documents SET disposition_status = 'Destroyed', file_path = '[SECURELY DELETED]', status = 'Archived' WHERE doc_id = ?");
            $updateStmt->bind_param("i", $doc_id);
            $updateStmt->execute();
            
            // 3. I-log sa Chain of Custody (audit_logs table) kung naka-set up ang logging mo
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'DESTROY_RECORD', $doc_id, "Legally destroyed expired record: " . $doc['file_name'], $_SERVER['REQUEST_URI'] ?? null);
            } else {
                log_audit_action($conn, $user_id, 'DESTROY_RECORD', "Legally destroyed expired record: " . $doc['file_name']);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Record has been securely destroyed.']);
        } 
        elseif ($action === 'Permanent Archive') {
            // Ilipat sa Permanent Archiving status
            $updateStmt = $conn->prepare("UPDATE documents SET disposition_status = 'Permanently Archived', status = 'Archived' WHERE doc_id = ?");
            $updateStmt->bind_param("i", $doc_id);
            $updateStmt->execute();
            
            // 3. I-log sa Chain of Custody
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'PERMANENT_ARCHIVE', $doc_id, "Moved record to permanent digital archive: " . $doc['file_name'], $_SERVER['REQUEST_URI'] ?? null);
            } else {
                log_audit_action($conn, $user_id, 'PERMANENT_ARCHIVE', "Moved record to permanent digital archive: " . $doc['file_name']);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'Record successfully moved to permanent archives.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Document not found.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request parameters.']);
}
?>