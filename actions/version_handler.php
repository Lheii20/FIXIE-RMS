<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
    exit;
}

// ==========================================
// UPLOAD NEW VERSION LOGIC
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'upload_version') {
    $doc_id = intval($_POST['doc_id']);
    $remarks = trim($_POST['remarks']);
    $source_page = $_POST['source_page'] ?? '../documents.php';
    $user_id = $_SESSION['user_id'];

    // 1. Kuhanin ang current highest version
    $stmt = $conn->prepare("SELECT version_number FROM document_versions WHERE doc_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $current_version = 1.0;
    if ($res->num_rows > 0) {
        $current_version = floatval($res->fetch_assoc()['version_number']);
    }
    // I-increment by 1.0 (e.g. 1.0 -> 2.0)
    $new_version = number_format($current_version + 1.0, 1); 

    // 2. I-process ang file upload
    if (isset($_FILES['new_document']) && $_FILES['new_document']['error'] === 0) {
        $file_name = time() . '_' . $_FILES['new_document']['name'];
        $target_dir = "../uploads/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $target_file = $target_dir . $file_name;
        $db_file_path = "uploads/" . $file_name;

        if (move_uploaded_file($_FILES['new_document']['tmp_name'], $target_file)) {
            
            // 3. I-save sa document_versions history table
            $stmt_insert = $conn->prepare("INSERT INTO document_versions (doc_id, version_number, file_path, remarks, uploaded_by) VALUES (?, ?, ?, ?, ?)");
            $stmt_insert->bind_param("isssi", $doc_id, $new_version, $db_file_path, $remarks, $user_id);
            $stmt_insert->execute();

            // 4. I-update ang main document table file_path para yung latest ang maddownload sa labas
            $stmt_upd = $conn->prepare("UPDATE documents SET file_path = ?, file_name = ? WHERE doc_id = ?");
            $stmt_upd->bind_param("ssi", $db_file_path, $_FILES['new_document']['name'], $doc_id);
            $stmt_upd->execute();

            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $user_id, 'UPDATE_VERSION', "Uploaded v$new_version for Doc ID: $doc_id");
            }

            header("Location: $source_page" . (strpos($source_page, '?') ? '&' : '?') . "success=" . urlencode("Version updated to v$new_version successfully."));
            exit;
        }
    }
    
    header("Location: $source_page" . (strpos($source_page, '?') ? '&' : '?') . "error=" . urlencode("Failed to upload new version."));
    exit;
}

// ==========================================
// FETCH VERSION HISTORY LOGIC (AJAX)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    $doc_id = intval($_GET['doc_id']);

    $stmt = $conn->prepare("
        SELECT dv.*, u.full_name as uploader 
        FROM document_versions dv 
        LEFT JOIN users u ON dv.uploaded_by = u.user_id 
        WHERE dv.doc_id = ? 
        ORDER BY dv.uploaded_at DESC
    ");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $versions = [];
    while ($row = $result->fetch_assoc()) {
        $versions[] = [
            'version' => $row['version_number'],
            'remarks' => htmlspecialchars($row['remarks']),
            'date' => date('M d, Y h:i A', strtotime($row['uploaded_at'])),
            'uploader' => htmlspecialchars($row['uploader']),
            'file_path' => htmlspecialchars($row['file_path'])
        ];
    }

    // Default V1.0 kung wala pang bagong version na naupload
    if (count($versions) == 0) {
        $stmt_main = $conn->prepare("SELECT d.file_path, d.uploaded_at, u.full_name as uploader FROM documents d LEFT JOIN users u ON d.uploaded_by = u.user_id WHERE d.doc_id = ?");
        $stmt_main->bind_param("i", $doc_id);
        $stmt_main->execute();
        $main_doc = $stmt_main->get_result()->fetch_assoc();
        
        if ($main_doc) {
            $versions[] = [
                'version' => '1.0',
                'remarks' => 'Original Document Upload',
                'date' => date('M d, Y h:i A', strtotime($main_doc['uploaded_at'])),
                'uploader' => htmlspecialchars($main_doc['uploader']),
                'file_path' => htmlspecialchars($main_doc['file_path'])
            ];
        }
    }

    echo json_encode(['status' => 'success', 'versions' => $versions]);
    exit;
}
?>