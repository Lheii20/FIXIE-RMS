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

    // ==========================================
    // SOFT DELETE (RECYCLE BIN) WORKFLOW
    // ==========================================
    if ($action == 'delete') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? getRedirectUrl($conn, $doc_id, null, $source);

        $stmt = $conn->prepare("SELECT file_name, record_phase FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($row = $res->fetch_assoc()) {
            if ($row['record_phase'] === 'Official') {
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=Official Records must go through the disposition workflow.");
                exit();
            }

            // SOFT DELETE: Move to Recycle Bin instead of unlinking
            $soft_del = $conn->prepare("UPDATE documents SET status = 'Recycled', deleted_at = NOW() WHERE doc_id = ?");
            $soft_del->bind_param("i", $doc_id);
            
            if ($soft_del->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'SOFT_DELETE_FILE', $doc_id, "Moved working document to Recycle Bin: " . $row['file_name'], $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'SOFT_DELETE_FILE', "Moved working document to Recycle Bin: " . $row['file_name']);
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Moved to Recycle Bin.");
            } else {
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DeleteFailed");
            }
        }
        exit();
    }

    // ==========================================
    // RESTORE RECYCLED DOCUMENT
    // ==========================================
    if ($action == 'restore_recycled') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? '../general_docs.php';

        $res_stmt = $conn->prepare("UPDATE documents SET status = 'Active', deleted_at = NULL WHERE doc_id = ?");
        $res_stmt->bind_param("i", $doc_id);
        if ($res_stmt->execute()) {
            log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored document ID $doc_id from Recycle Bin.");
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Document Restored Successfully.");
        }
        exit();
    }

    // ==========================================
    // PERMANENT DELETE (AUTHORIZED ONLY)
    // ==========================================
    if ($action == 'permanent_delete') {
        if (!in_array($_SESSION['role'], ['Admin', 'President', 'GM'])) die("Unauthorized Action.");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? '../general_docs.php';

        $stmt = $conn->prepare("SELECT file_path, file_name FROM documents WHERE doc_id = ? AND status = 'Recycled'");
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
                log_audit_action($conn, $user_id, 'PERMANENT_DELETE', "Permanently wiped document: " . $row['file_name']);
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Permanently Deleted.");
            }
        }
        exit();
    }

    if ($action == 'upload') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents')) die("Access Denied");
        
        $doc_category = trim($_POST['category'] ?? '');
        $doc_type = trim($_POST['doc_type'] ?? '');
        $document_name = trim($_POST['document_name'] ?? '');
        $file = $_FILES['document'] ?? null;
        $po_id = isset($_POST['po_id']) ? intval($_POST['po_id']) : null;
        
        $redirectUrl = getRedirectUrl($conn, null, $po_id, $source);

        // The destination must be explicit. HTTP_REFERER is optional and can be
        // forged, so it must never decide whether a record is already official.
        $record_intake = trim($_POST['record_intake'] ?? 'working');
        $signature_confirmed = ($_POST['official_signature_confirmed'] ?? '') === '1';
        $is_official_intake = $record_intake === 'official' && $signature_confirmed;
        $record_phase = $is_official_intake ? 'Official' : 'Working';
        $declared_at = $is_official_intake ? date('Y-m-d H:i:s') : null;
        $declared_by = $is_official_intake ? $user_id : null;

        if ($record_intake === 'official' && !$signature_confirmed) {
            $redirectUrl = "../documents.php?type=" . urlencode($doc_category);
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode("Confirm that the uploaded copy contains the required signature before filing it as an Official Record."));
            exit();
        }

        $redirectUrl = $is_official_intake
            ? "../documents.php?type=" . urlencode($doc_category)
            : "../general_docs.php?type=" . urlencode($doc_category);

        if ((empty($doc_category) && empty($doc_type)) || !$file || $file['error'] !== UPLOAD_ERR_OK) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidInput");
            exit();
        }

        if (!empty($doc_category) && !userCanUseOfficialFolder($conn, $doc_category, $_SESSION['role'])) {
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
            
            // Gamitin ang in-input na document_name kung meron, kung wala, original filename
            $final_name_to_save = !empty($document_name) ? $document_name . '.' . $ext : $sanitized_file_name;

            $policy_id = null;
            if ($doc_category !== '') {
                $policy_stmt = $conn->prepare("SELECT policy_id FROM document_categories WHERE sub_category = ? AND policy_id IS NOT NULL ORDER BY id ASC LIMIT 1");
                $policy_stmt->bind_param("s", $doc_category);
                $policy_stmt->execute();
                $policy_row = $policy_stmt->get_result()->fetch_assoc();
                $policy_id = $policy_row ? (int) $policy_row['policy_id'] : null;
                $policy_stmt->close();
            }

            // Official intake records the declaration actor/date and snapshots
            // the retention policy assigned to the selected folder.
            $stmt = $conn->prepare("INSERT INTO documents (po_id, file_name, file_path, category, doc_type, status, record_phase, uploaded_by, file_hash, policy_id, declared_at, declared_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssisisi", $po_id, $final_name_to_save, $db_path, $doc_category, $doc_type, $status, $record_phase, $user_id, $fileHash, $policy_id, $declared_at, $declared_by);
            
            if ($stmt->execute()) {
                $new_doc_id = $stmt->insert_id;

                // =================================================================
                // NEW: PHYSICAL RECORD INITIALIZATION FROM UI QUESTION
                // =================================================================
                $physical_status = $_POST['physical_status'] ?? 'Digital';
                
                if ($physical_status === 'Stored') {
                    $phys_status = 'Stored';
                    $stmt_phys = $conn->prepare("INSERT INTO virt_document_locations (document_id, status) VALUES (?, ?)");
                    $stmt_phys->bind_param("is", $new_doc_id, $phys_status);
                    $stmt_phys->execute();
                }

                $record_label = $is_official_intake ? 'signed Official Record' : 'Working Document';
                $audit_description = "Indexed and uploaded $record_label: " . $sanitized_file_name . " [$doc_category]";
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'UPLOAD_RECORD', $new_doc_id, $audit_description, $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'UPLOAD_RECORD', $audit_description);
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

// ==========================================
    // DECLARE AS OFFICIAL RECORD (ENTERPRISE WORKFLOW)
    // ==========================================
    if ($action == 'declare_official') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_manage_folders') && !in_array($_SESSION['role'], ['Admin', 'GM', 'President'])) {
            die("Access Denied");
        }

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? '../general_docs.php';

        $record_lock_name = null;
        $record_lock_acquired = false;

        try {
            $conn->begin_transaction();

            // 1. Fetch the original working document
            $stmt = $conn->prepare("SELECT * FROM documents WHERE doc_id = ? FOR UPDATE");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $orig = $stmt->get_result()->fetch_assoc();

            if (!$orig) throw new Exception("Document not found.");
            if (!in_array($orig['record_phase'], ['Working', 'For Review'], true)) {
                throw new Exception("Only a Working or For Review document can be declared as an Official Record.");
            }

            if (($_POST['official_signature_confirmed'] ?? '') !== '1') {
                throw new Exception("Confirm that the document contains the required signature before declaring it official.");
            }
            
            // Validate Enterprise Physical Synchronization
            if (isset($orig['physical_version']) && $orig['current_version'] != $orig['physical_version']) {
                throw new Exception("Cannot declare this document as an Official Record. The stored physical copy (v" . number_format($orig['physical_version'], 1) . ") is not synchronized with the latest digital version (v" . number_format($orig['current_version'], 1) . "). Please physically replace and verify it first.");
            }

            // 2. Generate the existing REC number safely. The naming-series
            // redesign is handled in a later phase; no company prefix is added.
            $year = date('Y');
            $record_lock_name = "official_record_number_$year";
            $lock_stmt = $conn->prepare("SELECT GET_LOCK(?, 5) AS lock_acquired");
            $lock_stmt->bind_param("s", $record_lock_name);
            $lock_stmt->execute();
            $lock_row = $lock_stmt->get_result()->fetch_assoc();
            $record_lock_acquired = (int) ($lock_row['lock_acquired'] ?? 0) === 1;
            $lock_stmt->close();

            if (!$record_lock_acquired) {
                throw new Exception("The record number service is busy. Please try again.");
            }

            $prefix = "REC-$year-";
            $sequence_stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(record_number, ?) AS UNSIGNED)), 0) + 1 AS next_number FROM documents WHERE record_number LIKE CONCAT(?, '%')");
            $sequence_start = strlen($prefix) + 1;
            $sequence_stmt->bind_param("is", $sequence_start, $prefix);
            $sequence_stmt->execute();
            $sequence_row = $sequence_stmt->get_result()->fetch_assoc();
            $record_number = sprintf("REC-%s-%04d", $year, (int) $sequence_row['next_number']);
            $sequence_stmt->close();

            // 3. Clone as Official Record (Locks it and moves it to Virtual Cabinet)
            $cat = !empty($orig['category']) ? $orig['category'] : $orig['doc_type'];
            
            // Safe variables for PHP 8 binding
            $po_id = $orig['po_id'];
            $file_name = $orig['file_name'];
            $file_path = $orig['file_path'];
            $doc_type = $orig['doc_type'];
            $status = $orig['status'];
            $uploaded_by = $orig['uploaded_by'];
            $uploaded_at = $orig['uploaded_at'];
            $file_hash = $orig['file_hash'];
            $current_version = $orig['current_version'];
            $access_type = $orig['access_type'];
            $file_permissions = $orig['file_permissions'];

            $physical_version = $orig['physical_version'] ?? $current_version;
            $policy_id = !empty($orig['policy_id']) ? (int) $orig['policy_id'] : null;

            if ($policy_id === null && $cat !== '') {
                $policy_stmt = $conn->prepare("SELECT policy_id FROM document_categories WHERE sub_category = ? AND policy_id IS NOT NULL ORDER BY id ASC LIMIT 1");
                $policy_stmt->bind_param("s", $cat);
                $policy_stmt->execute();
                $policy_row = $policy_stmt->get_result()->fetch_assoc();
                $policy_id = $policy_row ? (int) $policy_row['policy_id'] : null;
                $policy_stmt->close();
            }

            $insert = $conn->prepare("INSERT INTO documents (po_id, file_name, file_path, category, doc_type, status, uploaded_by, uploaded_at, file_hash, current_version, physical_version, access_type, file_permissions, policy_id, record_phase, declared_at, declared_by, record_number, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Official', NOW(), ?, ?, 1)");
            $insert->bind_param("isssssissssssiis", 
                $po_id, $file_name, $file_path, $cat, $doc_type, 
                $status, $uploaded_by, $uploaded_at, $file_hash, 
                $current_version, $physical_version, $access_type, $file_permissions, 
                $policy_id, $user_id, $record_number
            );
            $insert->execute();
            $official_doc_id = $insert->insert_id;

            // 4. Update Original to 'Converted' (Preserves Working Copy, locks editing)
            $update = $conn->prepare("UPDATE documents SET record_phase = 'Converted', official_doc_id = ?, is_locked = 1 WHERE doc_id = ?");
            $update->bind_param("ii", $official_doc_id, $doc_id);
            $update->execute();

            // ==========================================================
            // 5. TRANSFER PHYSICAL TRACKING TO THE NEW OFFICIAL RECORD
            // ==========================================================
            // Ilipat ang pointer ng physical cabinet sa bagong Official Record ID
            $transfer_phys = $conn->prepare("UPDATE virt_document_locations SET document_id = ? WHERE document_id = ?");
            $transfer_phys->bind_param("ii", $official_doc_id, $doc_id);
            $transfer_phys->execute();

            // Ilipat rin ang borrowing history para hindi mawala ang record kung sino ang mga nanghiram noon
            $transfer_b_logs = $conn->prepare("UPDATE physical_borrowing_logs SET document_id = ? WHERE document_id = ?");
            $transfer_b_logs->bind_param("ii", $official_doc_id, $doc_id);
            $transfer_b_logs->execute();

            $transfer_m_logs = $conn->prepare("UPDATE physical_movement_logs SET document_id = ? WHERE document_id = ?");
            $transfer_m_logs->bind_param("ii", $official_doc_id, $doc_id);
            $transfer_m_logs->execute();
            // ==========================================================

            $conn->commit();

            if ($record_lock_acquired) {
                $release_stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
                $release_stmt->bind_param("s", $record_lock_name);
                $release_stmt->execute();
                $release_stmt->close();
                $record_lock_acquired = false;
            }

            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $user_id, 'DECLARE_OFFICIAL', "Declared Doc ID $doc_id as Official Record $record_number");
            }
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=" . urlencode("Success! Working copy locked and Official Record $record_number generated."));
            
        } catch (Throwable $e) {
            $conn->rollback();
            if ($record_lock_acquired && $record_lock_name !== null) {
                $release_stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
                $release_stmt->bind_param("s", $record_lock_name);
                $release_stmt->execute();
                $release_stmt->close();
            }
            error_log("Declare Official Error: " . $e->getMessage());
            // FIX: Ipinasa ang totoong $e->getMessage() para malaman ng user kung bakit na-block
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode($e->getMessage()));
        }
        exit();
    }
?>
