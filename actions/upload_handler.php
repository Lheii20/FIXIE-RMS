<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) { die("Unauthorized access."); }

function uploadFolderRoleMatches($assigned_roles, $role) {
    if (empty($assigned_roles)) return false;
    $roles = array_map('trim', explode(',', $assigned_roles));
    foreach ($roles as $assigned_role) {
        if (strcasecmp($assigned_role, $role) === 0) return true;
    }
    return false;
}

function userCanUseOfficialFolder($conn, $category, $role) {
    if (in_array($role, ['Admin', 'GM', 'President'])) return true;
    if (empty($category)) return false;

    $stmt = $conn->prepare("SELECT assigned_to_role FROM document_categories WHERE sub_category = ? LIMIT 1");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return uploadFolderRoleMatches($row['assigned_to_role'], $role);
    }
    return false;
}

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
        $allowed = ['GM', 'President', 'Admin'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);

        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Archived' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'ARCHIVE_FILE', $doc_id, "Archived Document ID: $doc_id", $redirectUrl);
                } else {
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
        $allowed = ['GM', 'President', 'Admin'];
        if (!in_array($_SESSION['role'], $allowed)) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);
        
        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'RESTORE_FILE', $doc_id, "Restored Document ID: $doc_id", $redirectUrl);
                } else {
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
        $allowed = ['GM', 'President', 'Admin'];
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
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'DELETE_FILE', $doc_id, $desc, $redirectUrl);
            } else {
                log_audit_action($conn, $user_id, 'DELETE_FILE', $desc);
            }
        }
        
        header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Deleted");
        exit();
    }

    if ($action == 'renew') {
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

            // AUTO-RENEW Logic: Irereset ang uploaded_at at ibabalik ang disposition sa Pending, tatanggalin ang lumang expiry para mag re-compute sa background logic (kung meron mang manual run) o iiwang null kung manual monitoring muna
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
        if (!empty($category) && !userCanUseOfficialFolder($conn, $category, $_SESSION['role'])) {
            header("Location: ../documents.php?error=" . urlencode("You can only upload records to folders assigned to your role."));
            exit();
        }
        
        // ==========================================
        // 1. KUNIN ANG POLICY ID MULA SA FOLDER
        // ==========================================
        $policy_id = null;
        $expiry_date = null;

        if (!empty($category)) {
            // Nanggaling sa Official Records (document_categories)
            $pol_stmt = $conn->prepare("SELECT policy_id FROM document_categories WHERE sub_category = ? LIMIT 1");
            $pol_stmt->bind_param("s", $category);
            $pol_stmt->execute();
            $pol_res = $pol_stmt->get_result();
            if ($pol_row = $pol_res->fetch_assoc()) {
                $policy_id = $pol_row['policy_id'];
            }
        } elseif (empty($category) && !empty($doc_type) && $doc_type !== 'General') {
            // Nanggaling sa Company Files (company_folders)
            $pol_stmt = $conn->prepare("SELECT policy_id FROM company_folders WHERE folder_name = ? LIMIT 1");
            $pol_stmt->bind_param("s", $doc_type);
            $pol_stmt->execute();
            $pol_res = $pol_stmt->get_result();
            if ($pol_row = $pol_res->fetch_assoc()) {
                $policy_id = $pol_row['policy_id'];
            }
        }

        // ==========================================
        // 2. AUTOMATIC DATE MATH (EXPIRATION COMPUTATION)
        // ==========================================
        if ($policy_id) {
            $stmt_years = $conn->prepare("SELECT retention_years FROM retention_policies WHERE policy_id = ? LIMIT 1");
            $stmt_years->bind_param("i", $policy_id);
            $stmt_years->execute();
            $res_years = $stmt_years->get_result();
            if ($row_years = $res_years->fetch_assoc()) {
                $years = intval($row_years['retention_years']);
                if ($years > 0) {
                    // I-add ang retention years sa kasalukuyang date
                    $expiry_date = date('Y-m-d', strtotime("+$years years"));
                }
            }
        }

        $custom_name = !empty($_POST['document_name']) ? trim($_POST['document_name']) : (!empty($_POST['document_title']) ? trim($_POST['document_title']) : '');
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

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            
            if ($po_id === null) {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, category, tags, expiry_date, uploaded_by, status, policy_id, disposition_status) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 'Pending')");
                $stmt->bind_param("sssssssii", $doc_type, $finalFileName, $dbPath, $fileHash, $category, $tags, $expiry_date, $user_id, $policy_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, category, tags, expiry_date, uploaded_by, status, policy_id, disposition_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 'Pending')");
                $stmt->bind_param("isssssssii", $po_id, $doc_type, $finalFileName, $dbPath, $fileHash, $category, $tags, $expiry_date, $user_id, $policy_id);
            }
            
            if($stmt->execute()) {
                $doc_id = $stmt->insert_id;
                $ref = $po_id ? "PO ID: $po_id" : "Folder: " . (!empty($category) ? $category : $doc_type);
                $logMsg = "Uploaded $doc_type ($finalFileName) to $ref. Auto-applied Policy ID: " . ($policy_id ?? 'None') . ". Expiry set to: " . ($expiry_date ?? 'Indefinite');
                
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'UPLOAD', $doc_id, $logMsg, $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'UPLOAD', $logMsg);
                }
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=" . urlencode("Upload Success. Automatic retention timer activated."));
            } else {
                 header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
            }
        } else {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UploadFailed");
        }
    }
}
?>