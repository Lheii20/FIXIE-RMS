<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

// Function para sa Audit Log (kung existing sa inyo)
if (!function_exists('log_audit_action')) {
    function log_audit_action($conn, $user_id, $action_type, $description) {
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
        if($stmt) {
            $stmt->bind_param("isss", $user_id, $action_type, $description, $ip_address);
            $stmt->execute();
        }
    }
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// API: KUNIN ANG VERSION HISTORY PARA SA MODAL
if ($action === 'get_history') {
    $doc_id = intval($_GET['doc_id']);
    
    $stmt = $conn->prepare("
        SELECT v.*, u.full_name 
        FROM document_versions v 
        LEFT JOIN users u ON v.uploaded_by = u.user_id 
        WHERE v.doc_id = ? 
        ORDER BY v.version_id DESC
    ");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $versions = [];
    while($row = $res->fetch_assoc()) {
        $versions[] = [
            'version' => $row['version_number'],
            'file_name' => $row['file_name'],
            'uploaded_by' => $row['full_name'] ?? 'System',
            'date' => date('M d, Y h:i A', strtotime($row['uploaded_at'])),
            'remarks' => $row['remarks'],
            'path' => "download.php?file=" . urlencode(basename($row['file_path']))
        ];
    }
    
    echo json_encode(['status' => 'success', 'data' => $versions]);
    exit();
}

// ACTION: UPLOAD NG BAGONG VERSION
if ($action === 'upload_version') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }

    $doc_id = intval($_POST['doc_id']);
    $remarks = trim($_POST['remarks']);
    $user_id = $_SESSION['user_id'];
    $source_page = $_POST['source_page'] ?? '../documents.php';
    
    // Dynamic URL separator para maiwasan ang dobleng "??" sa URL
    $separator = (strpos($source_page, '?') !== false) ? '&' : '?';

    // 1. Kunin ang current file info mula sa documents table
    $stmt = $conn->prepare("SELECT file_name, current_version FROM documents WHERE doc_id = ?");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    $curr_doc = $stmt->get_result()->fetch_assoc();

    if (!$curr_doc) {
        header("Location: $source_page" . $separator . "error=" . urlencode("Document not found."));
        exit();
    }

    $old_version = !empty($curr_doc['current_version']) ? $curr_doc['current_version'] : '1.0';
    $new_version_num = number_format(floatval($old_version) + 1.0, 1);

    if (isset($_FILES['new_document']) && $_FILES['new_document']['error'] == 0) {
        
        $file = $_FILES['new_document'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
        
        // 2. VALIDATION MUNA: I-check ang file type bago galawin ang database!
        if (!in_array($file_ext, $allowed)) {
            header("Location: $source_page" . $separator . "error=" . urlencode("Invalid file type. Allowed formats: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, JPEG."));
            exit(); // Hihinto agad dito, kaya walang maling history na mase-save.
        }

        // 3. I-BACKUP ang current file papunta sa document_versions table (Dahil pasado na sa file type)
        $bkp_sql = "INSERT INTO document_versions (doc_id, version_number, file_name, file_path, uploaded_by, uploaded_at, remarks)
                    SELECT doc_id, COALESCE(current_version, '1.0'), file_name, file_path, uploaded_by, uploaded_at, ?
                    FROM documents WHERE doc_id = ?";
        $bkp_stmt = $conn->prepare($bkp_sql);
        $bkp_stmt->bind_param("si", $remarks, $doc_id);
        $bkp_stmt->execute();

        // 4. Secure at paggawa ng unique filename
        $new_file_name = preg_replace("/[^a-zA-Z0-9.\-_ ]/", "", $file['name']);
        $unique_name = time() . '_' . bin2hex(random_bytes(4)) . '_' . $new_file_name;
        $upload_path = '../uploads/' . $unique_name;
        
        // 5. I-UPLOAD ang BAGONG file sa server
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            $db_path = 'uploads/' . $unique_name;

            // 6. I-UPDATE ang documents table gamit ang BAGONG FILE at BAGONG VERSION
            $upd = $conn->prepare("UPDATE documents SET file_name = ?, file_path = ?, current_version = ?, uploaded_by = ?, uploaded_at = CURRENT_TIMESTAMP WHERE doc_id = ?");
            $upd->bind_param("sssii", $new_file_name, $db_path, $new_version_num, $user_id, $doc_id);
            
            if ($upd->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'UPDATE_VERSION', $doc_id, "Uploaded v$new_version_num for Doc ID: $doc_id", $source_page);
                } else {
                    log_audit_action($conn, $user_id, 'UPDATE_VERSION', "Uploaded v$new_version_num for Doc ID: $doc_id");
                }
                header("Location: $source_page" . $separator . "success=" . urlencode("Version updated to v$new_version_num successfully."));
                exit();
            }
        } else {
            header("Location: $source_page" . $separator . "error=" . urlencode("Server upload failed. Please try again."));
            exit();
        }
    } else {
        header("Location: $source_page" . $separator . "error=" . urlencode("No file uploaded or file is corrupted."));
        exit();
    }
}
?>