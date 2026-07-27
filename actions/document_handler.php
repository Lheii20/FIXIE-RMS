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
        if (ob_get_length()) ob_clean(); 
        
        $cat = trim($_POST['category'] ?? '');
        
        $stmt = $conn->prepare("SELECT classification_keywords FROM document_categories WHERE sub_category = ? LIMIT 1");
        $stmt->bind_param("s", $cat);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $db_keywords = "";
        if ($row = $res->fetch_assoc()) {
            $db_keywords = $row['classification_keywords'];
        }
        
        $combined = [];
        if (!empty($db_keywords)) {
            $combined = array_map('trim', explode(',', $db_keywords));
        }
        
        $clean_combined = array_unique(array_filter($combined));
        $final_string = implode(', ', $clean_combined);
        
        echo json_encode(['status' => 'success', 'keywords' => $final_string]);
        exit();
    }

    // ==========================================
    // DAGDAG: REAL-TIME KEYWORD CONFLICT CHECKER (AJAX)
    // ==========================================
    if ($action === 'check_keyword_conflicts') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean(); 
        
        $cat_name = trim($_POST['category'] ?? '');
        $keywords = trim($_POST['keywords'] ?? '');
        
        $input_keys = array_filter(array_map('trim', array_map('strtolower', explode(',', $keywords))));
        $conflicts = [];

        if (!empty($input_keys)) {
            // PURE DATABASE CHECK ONLY. Wala nang naka-hardcode na system defaults.
            $chk_query = $conn->prepare("SELECT sub_category, classification_keywords FROM document_categories WHERE sub_category != ? AND classification_keywords IS NOT NULL AND classification_keywords != ''");
            $chk_query->bind_param("s", $cat_name);
            $chk_query->execute();
            $chk_res = $chk_query->get_result();

            while ($row = $chk_res->fetch_assoc()) {
                $db_keys = array_filter(array_map('trim', array_map('strtolower', explode(',', $row['classification_keywords']))));
                foreach ($input_keys as $ik) {
                    if (in_array($ik, $db_keys)) {
                        $conflicts[] = "<b>'" . strtoupper($ik) . "'</b> is already used in <b>" . $row['sub_category'] . "</b>";
                    }
                }
            }
        }

        if (!empty($conflicts)) {
            echo json_encode(['status' => 'conflict', 'messages' => array_unique($conflicts)]);
        } else {
            echo json_encode(['status' => 'clear']);
        }
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
        
        // DAGDAG: Tanggapin ang text mula sa Client-Side OCR (para sa images)
        if (!empty($_POST['ocr_text'])) {
            $extractedText .= " " . $_POST['ocr_text'];
        }

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
            // CRASH FIX: Patakbuhin lang ang lumang PHP extractor kung WALANG naipasang text ang PDF.js mula sa frontend
            if (empty(trim($_POST['ocr_text'] ?? ''))) {
                require_once '../config/PdfToText.php';
                $extractedText .= " " . PdfToText::extract($tmpPath);
            }
        }

        // 2. Text Normalization
        // CRASH PREVENTER: Linisin muna ang mga "binary garbage" bago linisin ang spaces para hindi mag-crash ang PHP
        $extractedText = preg_replace('/[^\x20-\x7E]/', ' ', $extractedText); 
        $extractedText = strtolower($extractedText);
        $extractedText = preg_replace('/[_\-\s]+/', ' ', $extractedText);

        // PURE DYNAMIC DATABASE RULES ONLY (Wala nang naka-hardcode na default rules)
        $rules = [];
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

        $scores = [];
        $highest_score = 0;
        $suggested_category = null;

        foreach ($rules as $category => $keywords) {
            $scores[$category] = 0;
            
            foreach ($keywords as $keyword) {
                $keyword = trim(strtolower($keyword));
                if ($keyword === '') continue;

                // Use lookarounds to enforce strict word boundaries including symbols like '#'
                $pattern = '/(?<=^|\W)' . preg_quote($keyword, '/') . '(?=$|\W)/i';
                
                // CRASH-PROOF: Pigilan ang Fatal Error kung mabibigo ang regex dahil sa binary chars.
                // Ang @preg_match_all ay nagre-return ng bilang kung ilang beses nag-match, at `false` kung pumalya.
                $occurrences = @preg_match_all($pattern, $extractedText);
                
                if ($occurrences !== false && $occurrences > 0) {
                    // Weighting: Multi-word phrases (e.g., "purchase order") carry strong weight (3 pts) 
                    // Short acronyms or single words (e.g., "po", "invoice") carry lower weight (1 pt)
                    $word_count = str_word_count($keyword);
                    $weight = ($word_count > 1) ? 3 : 1; 
                    
                    $scores[$category] += ($occurrences * $weight);
                }
            }
            
            // Track the category with the highest confidence score
            if ($scores[$category] > $highest_score) {
                $highest_score = $scores[$category];
                $suggested_category = $category;
            }
        }

        // Confidence Threshold: Lowered to 1 since Regex boundaries already prevent substring false positives
        if ($highest_score < 1) {
            $suggested_category = null; 
        }

        if ($suggested_category) {
            // Siguruhing nag-e-exist pa rin ang folder bago i-suggest
            $stmt = $conn->prepare("SELECT id FROM document_categories WHERE sub_category = ? OR parent_category = ? LIMIT 1");
            $stmt->bind_param("ss", $suggested_category, $suggested_category);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode([
                    'status' => 'success', 
                    'suggested_category' => $suggested_category,
                    'debug_text' => substr($extractedText, 0, 1000) // DAGDAG: Ipadala ang text para ma-debug
                ]);
                exit();
            }
        }
        
        echo json_encode([
            'status' => 'none',
            'debug_text' => substr($extractedText, 0, 1000) // DAGDAG: Ipadala ang text para ma-debug
        ]);
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