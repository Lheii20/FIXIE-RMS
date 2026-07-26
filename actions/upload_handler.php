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
    if (has_permission($conn, $_SESSION['user_id'], 'can_view_all_folders')) return true;
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

    // ==========================================
    // AJAX FETCH PARA SA EXISTING KEYWORDS
    // ==========================================
    if ($action === 'get_keywords') {
        header('Content-Type: application/json');
        
        // Linisin ang anumang sobrang spaces o errors bago mag-bato ng JSON
        if (ob_get_length()) ob_clean(); 
        
        $cat = trim($_POST['category'] ?? '');
        
        // 1. KUNIN ANG MGA DEFAULT CORE RULES NATIN
        $default_rules = [
            'Purchase Orders' => ['purchase order', 'po number', 'po#', 'po '],
            'Purchase Requests' => ['purchase request', 'pr number', 'pr#', 'pr '],
            'Quotations' => ['quotation', 'quote', 'qtn '],
            'Invoices' => ['invoice', 'inv#', 'billing statement'],
            'Contracts & Agreements' => ['contract', 'agreement', 'terms and conditions', 'nda', 'memorandum'],
            'Service Tickets' => ['service ticket', 'ticket#', 'issue #', 'maintenance report'],
            'Job Orders' => ['job order', 'jo#', 'work order'],
            'Internal Memos' => ['internal memo', 'memorandum', 'internal notice']
        ];
        
        // 2. KUNIN ANG MGA DINAGDAG MO SA DATABASE
        $stmt = $conn->prepare("SELECT classification_keywords FROM document_categories WHERE sub_category = ? LIMIT 1");
        $stmt->bind_param("s", $cat);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $db_keywords = "";
        if ($row = $res->fetch_assoc()) {
            $db_keywords = $row['classification_keywords'];
        }
        
        // 3. PAGSAMAHIN SILA PARA MAKITA SA MODAL
        $combined = [];
        if (isset($default_rules[$cat])) {
            $combined = $default_rules[$cat];
        }
        if (!empty($db_keywords)) {
            $db_arr = array_map('trim', explode(',', $db_keywords));
            $combined = array_merge($combined, $db_arr);
        }
        
        // Tanggalin ang mga naulit (duplicates) at i-format nang maayos
        $clean_combined = array_unique(array_filter($combined));
        $final_string = implode(', ', $clean_combined);
        
        echo json_encode(['status' => 'success', 'keywords' => $final_string]);
        exit();
    }

    // START: Rule-Based Automatic Document Classification (PRODUCTION VERSION)
    if ($action === 'analyze_document') {
        header('Content-Type: application/json');
        
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'none']);
            exit();
        }

        $file = $_FILES['document'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmpPath = $file['tmp_name'];
        
        // SMART FALLBACK: Palaging isama ang filename para sa mga encoded PDFs at Images
        $extractedText = $file['name'] . " "; 

        // 1. Native Text Extraction Base
        if ($ext === 'docx') {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                if ($zip->open($tmpPath) === TRUE) {
                    if (($index = $zip->locateName('word/document.xml')) !== false) {
                        $data = $zip->getFromIndex($index);
                        $data = str_replace('<', ' <', $data); 
                        $extractedText .= " " . strip_tags($data);
                    }
                    $zip->close();
                }
            }
        } elseif (in_array($ext, ['txt', 'csv'])) {
            $extractedText .= " " . file_get_contents($tmpPath);
        } elseif ($ext === 'pdf') {
            require_once '../config/PdfToText.php';
            $extractedText .= " " . PdfToText::extract($tmpPath);
        }

        // 2. Text Normalization
        $extractedText = strtolower($extractedText);
        $extractedText = preg_replace('/[_\-\s]+/', ' ', $extractedText);

        // 1. DEFAULT CORE RULES (Hindi mawawala ang mga ito)
        $rules = [
            'Purchase Orders' => ['purchase order', 'po number', 'po#', 'po '],
            'Purchase Requests' => ['purchase request', 'pr number', 'pr#', 'pr '],
            'Quotations' => ['quotation', 'quote', 'qtn '],
            'Invoices' => ['invoice', 'inv#', 'billing statement'],
            'Contracts & Agreements' => ['contract', 'agreement', 'terms and conditions', 'nda', 'memorandum'],
            'Service Tickets' => ['service ticket', 'ticket#', 'issue #', 'maintenance report'],
            'Job Orders' => ['job order', 'jo#', 'work order'],
            'Internal Memos' => ['internal memo', 'memorandum', 'internal notice']
        ];

        // 2. DYNAMIC DATABASE RULES (Idadagdag ang mga bago mong nilagay sa UI)
        $rule_query = $conn->query("SELECT sub_category, classification_keywords FROM document_categories WHERE classification_keywords IS NOT NULL AND classification_keywords != ''");
        
        if ($rule_query) {
            while ($row = $rule_query->fetch_assoc()) {
                // STRICTLY use sub_category only. Iwasan ang pag-suggest ng Parent folder.
                $target_folder = trim($row['sub_category']);
                
                if (!empty($target_folder)) {
                    $keys = array_map('trim', explode(',', $row['classification_keywords']));
                    $clean_keys = array_filter($keys);
                    
                    if (!empty($clean_keys)) {
                        if (!isset($rules[$target_folder])) {
                            $rules[$target_folder] = [];
                        }
                        // Pagsamahin ang mga default at bagong keywords
                        $rules[$target_folder] = array_merge($rules[$target_folder], $clean_keys);
                    }
                }
            }
        }

        $suggested_category = null;
        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                // I-check kung may laman ang keyword at kung nahanap ba ito sa text
                if ($keyword !== '' && strpos($extractedText, strtolower($keyword)) !== false) {
                    $suggested_category = $category;
                    break 2; // Huminto agad sa unang match para tipid sa memory
                }
            }
        }

        if ($suggested_category) {
            // Siguruhing nag-e-exist pa rin ang folder bago i-suggest
            $stmt = $conn->prepare("SELECT id FROM document_categories WHERE sub_category = ? OR parent_category = ? LIMIT 1");
            $stmt->bind_param("ss", $suggested_category, $suggested_category);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'success', 'suggested_category' => $suggested_category]);
                exit();
            }
        }
        
        echo json_encode(['status' => 'none']);
        exit();
    }
    // END: Rule-Based Automatic Document Classification

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
        if (!has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')) die("Access Denied");

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
        if (!has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);

        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active', disposition_status = 'Archived' WHERE doc_id = ?");
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
        if (!has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);

        $stmt = $conn->prepare("SELECT file_path, file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($row = $res->fetch_assoc()) {
            $path = '../' . $row['file_path'];
            if(file_exists($path) && is_file($path)) {
                unlink($path); 
            }
            
            $del_stmt = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
            $del_stmt->bind_param("i", $doc_id);
            if ($del_stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'DELETE_FILE', $doc_id, "Deleted Document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'DELETE_FILE', "Permanently Deleted Document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Deleted");
            } else {
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DeleteFailed");
            }
        } else {
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=FileNotFound");
        }
        exit();
    }

    if ($action == 'upload') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents')) die("Access Denied");
        
        $doc_category = trim($_POST['category'] ?? '');
        $file = $_FILES['document'] ?? null;
        $po_id = isset($_POST['po_id']) ? intval($_POST['po_id']) : null;
        
        $redirectUrl = getRedirectUrl($conn, null, $po_id, $source);
        if (!empty($doc_category)) {
            $redirectUrl = "../documents.php?type=" . urlencode($doc_category);
        }

        if (empty($doc_category) || !$file || $file['error'] !== UPLOAD_ERR_OK) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidInput");
            exit();
        }

        if (!userCanUseOfficialFolder($conn, $doc_category, $_SESSION['role'])) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UnauthorizedFolder");
            exit();
        }

        $max_file_size = 50 * 1024 * 1024; 
        if ($file['size'] > $max_file_size) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=FileSizeExceeded");
            exit();
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

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeTypes)) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidFileExtension");
            exit();
        }

        // --- FILENAME SANITIZATION & LENGTH LIMIT (Fix implemented here) ---
        // Enforce a strict mb_substr limit before saving the file to prevent DB truncation
        // This ensures the extension is preserved while keeping the string limit up to 150 characters.
        $base_name = pathinfo($file['name'], PATHINFO_FILENAME);
        $max_len = 150 - mb_strlen($ext) - 1; // reserve space for the dot and extension
        $base_name = mb_substr($base_name, 0, $max_len);
        $sanitized_file_name = $base_name . '.' . $ext;
        // -------------------------------------------------------------------

        $fileHash = hash_file('sha256', $file['tmp_name']);
        
        $checkStmt = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
        $checkStmt->bind_param("s", $fileHash);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DuplicateFile");
            exit();
        }

        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        // Applying the sanitized filename here as well for physical file saving
        $new_filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace("/[^a-zA-Z0-9.-]/", "_", $sanitized_file_name);
        $dest_path = $upload_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            $db_path = 'uploads/' . $new_filename;
            $status = 'Active';

            // Inserting sanitized filename to DB
            $stmt = $conn->prepare("INSERT INTO documents (po_id, file_name, file_path, category, status, uploaded_by, file_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssis", $po_id, $sanitized_file_name, $db_path, $doc_category, $status, $user_id, $fileHash);
            
            if ($stmt->execute()) {
                $new_doc_id = $stmt->insert_id;
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'UPLOAD_RECORD', $new_doc_id, "Indexed and uploaded Official Record: " . $sanitized_file_name . " [$doc_category]", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'UPLOAD_RECORD', "Indexed and uploaded Official Record: " . $sanitized_file_name . " [$doc_category]");
                }
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Uploaded");
            } else {
                unlink($dest_path);
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DatabaseError");
            }
        } else {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UploadFailed");
        }
    }
}
?>