<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { die("Unauthorized access."); }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Strict CSRF Enforcement 
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid CSRF Token");
    }

    $action = $_POST['action'] ?? 'upload';
    $user_id = $_SESSION['user_id'];
    $source = $_POST['source'] ?? '';

    function getRedirectUrl($conn, $doc_id = null, $po_id = null, $source = '') {
        if ($source === 'dashboard') {
            return "../dashboard.php?tab=retention";
        }
        if ($po_id) {
            return "../view_po.php?id=" . $po_id;
        }
        if ($doc_id) {
            $stmt = $conn->prepare("SELECT po_id FROM documents WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['po_id']) {
                    return "../view_po.php?id=" . $row['po_id'];
                }
            }
        }
        return "../documents.php"; 
    }

    if ($action == 'archive') {
        $allowed = ['GM', 'President'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);

        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Archived' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_audit_action')) {
                    log_audit_action($conn, $user_id, 'ARCHIVE_FILE', "Archived Document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Archived");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            error_log("Archive Error: " . $e->getMessage());
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
        }
        exit();
    }

    if ($action == 'restore') {
        $allowed = ['GM', 'President'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);
        
        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_audit_action')) {
                    log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored Document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Restored");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            error_log("Restore Error: " . $e->getMessage());
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
        }
        exit();
    }

    if ($action == 'delete') {
        $allowed = ['GM', 'President'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);
        
        $stmt = $conn->prepare("SELECT file_path, file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($res->num_rows > 0) {
            $file = $res->fetch_assoc();
            
            $fixedUploadDir = "../uploads/";
            $safeFileName = basename($file['file_name']); 
            $physicalPath = $fixedUploadDir . $safeFileName;

            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
            
            $del = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
            $del->bind_param("i", $doc_id);
            $del->execute();
            
            $desc = "Permanently deleted file: " . $file['file_name'];
            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $user_id, 'DELETE_FILE', $desc);
            }
        }
        
        header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Deleted");
        exit();
    }

    if ($action == 'renew') {
        // Ang manual Expiry Date ay inalis na sa design. 
        // Ang "Renew" ngayon ay ire-reset lamang ang timestamp base sa file na iu-upload.
        $doc_id = intval($_POST['doc_id']);
        $file = $_FILES['document'];

        $redirectUrl = ($source === 'dashboard') ? "../dashboard.php?tab=retention" : "../documents.php";

        $max_file_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_file_size) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=FileSizeExceeded");
            exit();
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeTypes)) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidFileExtension");
            exit();
        }

        $fileHash = hash_file('sha256', $file['tmp_name']);
        
        $uploadDir = "../uploads/"; 
        $dbDir = "uploads/";        

        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $newFileName = time() . "_renewed_" . bin2hex(random_bytes(4)) . "." . $ext;
        $targetPath = $uploadDir . $newFileName; 
        $dbPath = $dbDir . $newFileName;         
        $finalFileName = basename($file['name']);

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $oldStmt = $conn->prepare("SELECT file_name FROM documents WHERE doc_id = ?");
            $oldStmt->bind_param("i", $doc_id);
            $oldStmt->execute();
            $oldRes = $oldStmt->get_result();
            $oldName = "";
            if ($row = $oldRes->fetch_assoc()) {
                $oldName = $row['file_name'];
            }

            // AUTO-RENEW Logic: Irereset ang uploaded_at at ibabalik ang disposition sa Pending, tatanggalin ang expiry
            $stmt = $conn->prepare("UPDATE documents SET file_name = ?, file_path = ?, file_hash = ?, expiry_date = NULL, uploaded_by = ?, uploaded_at = CURRENT_TIMESTAMP, disposition_status = 'Pending' WHERE doc_id = ?");
            $stmt->bind_param("sssii", $finalFileName, $dbPath, $fileHash, $user_id, $doc_id);
            
            if($stmt->execute()) {
                $logMsg = "Renewed Document ID: $doc_id (Replaced: $oldName). Retention Timer Reset.";
                if (function_exists('log_audit_action')) {
                    log_audit_action($conn, $user_id, 'RENEW_FILE', $logMsg);
                }
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Document successfully updated and retention timer reset");
            } else {
                 header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
            }
        } else {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UploadFailed");
        }
        exit();
    }

    if ($action == 'upload' || (isset($_FILES['document']) && $action != 'renew')) {
        $po_id = isset($_POST['po_id']) && !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
        $doc_type = $_POST['doc_type'] ?? 'General'; 
        
        $category = $_POST['category'] ?? null;
        
        // SYSTEM AUTOMATION: Query ang database upang kunin ang Policy ID batay sa Category na pinili
        $policy_id = null;
        if (!empty($category)) {
            $pol_stmt = $conn->prepare("SELECT policy_id FROM document_categories WHERE sub_category = ? LIMIT 1");
            $pol_stmt->bind_param("s", $category);
            $pol_stmt->execute();
            $pol_res = $pol_stmt->get_result();
            if ($pol_row = $pol_res->fetch_assoc()) {
                $policy_id = $pol_row['policy_id'];
            }
        }

        $custom_name = !empty($_POST['document_title']) ? trim($_POST['document_title']) : '';
        if (!empty($custom_name)) {
            $custom_name = preg_replace('/[^A-Za-z0-9_\-\s]/', '', $custom_name);
        }

        $file = $_FILES['document'];
        $redirectUrl = getRedirectUrl($conn, null, $po_id, $source);

        $max_file_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_file_size) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=FileSizeExceeded");
            exit();
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeTypes)) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidFileExtension");
            exit();
        }

        $fileHash = hash_file('sha256', $file['tmp_name']);
        $checkStmt = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
        $checkStmt->bind_param("s", $fileHash);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DuplicateFileDetected");
            exit();
        }

        $uploadDir = "../uploads/"; 
        $dbDir = "uploads/";        

        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $newFileName = time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $targetPath = $uploadDir . $newFileName; 
        $dbPath = $dbDir . $newFileName;         

        $finalFileName = !empty($custom_name) ? $custom_name . "." . $ext : basename($file['name']);
        $tags = $_POST['tags'] ?? '';
        $expiry_date = null; // TULUYAN NANG TINANGGAL ANG MANUAL EXPIRATION.

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            
            if ($po_id === null) {
                // 9 Params: s(doc_type), s(finalFileName), s(dbPath), s(fileHash), s(category), s(tags), s(expiry_date), i(uploaded_by), i(policy_id)
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, category, tags, expiry_date, uploaded_by, status, policy_id, disposition_status) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 'Pending')");
                $stmt->bind_param("sssssssii", $doc_type, $finalFileName, $dbPath, $fileHash, $category, $tags, $expiry_date, $user_id, $policy_id);
            } else {
                // 10 Params: i(po_id), s(doc_type), s(finalFileName), s(dbPath), s(fileHash), s(category), s(tags), s(expiry_date), i(uploaded_by), i(policy_id)
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, category, tags, expiry_date, uploaded_by, status, policy_id, disposition_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 'Pending')");
                $stmt->bind_param("isssssssii", $po_id, $doc_type, $finalFileName, $dbPath, $fileHash, $category, $tags, $expiry_date, $user_id, $policy_id);
            }
            
            if($stmt->execute()) {
                $ref = $po_id ? "PO ID: $po_id" : "Folder: $category";
                $logMsg = "Uploaded $doc_type ($finalFileName) to $ref. Auto-applied Policy ID: $policy_id";
                if (function_exists('log_audit_action')) {
                    log_audit_action($conn, $user_id, 'UPLOAD', $logMsg);
                }
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=UploadSuccess");
            } else {
                 header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
            }
        } else {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UploadFailed");
        }
    }
}
?>