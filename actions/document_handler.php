<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/upload_policy.php';
require_once '../config/official_declarations.php';
require_once '../config/storage_security.php';
require_once '../config/folder_action_security.php';

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

    $stmt = $conn->prepare(
        "SELECT dc.id
         FROM document_categories dc
         LEFT JOIN category_role_access cra
           ON cra.category_id = dc.id
          AND cra.role_name = ?
         WHERE dc.sub_category = ?
           AND (
               cra.category_id IS NOT NULL OR
               FIND_IN_SET(?, REPLACE(COALESCE(dc.assigned_to_role, ''), ', ', ',')) > 0
           )
         LIMIT 1"
    );
    $stmt->bind_param("sss", $role, $category, $role);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function isWorkflowManagedOfficialFolder($conn, $category) {
    if (
        !function_exists('drms_official_folder_schema_is_installed') ||
        !drms_official_folder_schema_is_installed($conn)
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM document_categories
         WHERE sub_category = ?
           AND is_system_folder = 1
         LIMIT 1"
    );
    $stmt->bind_param("s", $category);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function drmsDocumentMutationAccess(
    mysqli $conn,
    int $docId,
    int $userId,
    string $role
): ?array {
    // System Administrators maintain accounts and application settings; they
    // are deliberately excluded from the contents of company records.
    if ($docId < 1 || $userId < 1 || $role === 'Admin') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT doc_id, official_doc_id, file_name, file_path, category,
                status, record_phase, disposition_status, is_legal_hold,
                uploaded_by, access_type, file_permissions
         FROM documents
         WHERE doc_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$document) {
        return null;
    }

    if (
        in_array($role, ['GM', 'President'], true) ||
        has_permission($conn, $userId, 'can_view_all_folders')
    ) {
        $document['mutation_access'] = 'Management';
        return $document;
    }

    if ((int) ($document['uploaded_by'] ?? 0) === $userId) {
        $document['mutation_access'] = 'Owner';
        return $document;
    }

    $permissions = json_decode(
        (string) ($document['file_permissions'] ?? ''),
        true
    );
    if (
        is_array($permissions) &&
        ($permissions['user_' . $userId] ?? '') === 'Editor'
    ) {
        $document['mutation_access'] = 'Editor';
        return $document;
    }

    if (
        ($document['access_type'] ?? 'Folder Default') === 'Folder Default' &&
        userCanUseOfficialFolder(
            $conn,
            (string) ($document['category'] ?? ''),
            $role
        )
    ) {
        $document['mutation_access'] = 'Folder Editor';
        return $document;
    }

    return null;
}

function drmsDocumentMutationRedirect(
    string $url,
    string $type,
    string $message
): void {
    $fallback = '../general_docs.php';
    if (preg_match('/[\r\n]/', $url)) {
        $url = $fallback;
    }
    $parts = parse_url($url);
    if (
        $parts === false ||
        isset($parts['scheme']) ||
        isset($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        $url = $fallback;
    } else {
        $path = (string) ($parts['path'] ?? '');
        $page = basename(str_replace('\\', '/', $path));
        $allowed_pages = [
            'general_docs.php',
            'documents.php',
            'view_po.php',
            'dashboard.php',
            'official_declarations.php',
        ];
        if (!in_array($page, $allowed_pages, true)) {
            $url = $fallback;
        } else {
            if (str_starts_with($path, '/')) {
                $safe_path = $path;
            } elseif (str_starts_with($path, '../')) {
                $safe_path = '../' . $page;
            } else {
                $safe_path = '../' . $page;
            }
            $url = $safe_path .
                (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
    }

    $separator = strpos($url, '?') !== false ? '&' : '?';
    header('Location: ' . $url . $separator . $type . '=' . rawurlencode($message));
    exit();
}

function drmsDocumentLifecycleMutable(array $document): bool
{
    return
        ($document['record_phase'] ?? '') !== 'Converted' &&
        ($document['disposition_status'] ?? '') !== 'Destroyed' &&
        ($document['disposition_status'] ?? '') !== 'Permanently Archived';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Strict CSRF Enforcement 
    if (
        !is_string($_POST['csrf_token'] ?? null) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Security Error: Invalid CSRF Token");
    }

    $action = $_POST['action'] ?? 'upload';
    $user_id = $_SESSION['user_id'];
    $source = $_POST['source'] ?? '';

    // ==========================================
    // AJAX FETCH PARA SA EXISTING KEYWORDS
    // ==========================================
    if ($action === 'get_keywords') {
        try {
            drms_folder_action_require_manager(
                $conn,
                (int) $user_id,
                (string) $_SESSION['role']
            );
            $category = drms_folder_action_normalize_name(
                $_POST['category'] ?? null,
                'Folder name'
            );
            $folder = drms_folder_action_find_by_sub($conn, $category);
            if (!$folder) {
                throw new DomainException(
                    'The selected record folder no longer exists.'
                );
            }
            drms_folder_action_require_access(
                $conn,
                (int) $user_id,
                (string) $_SESSION['role'],
                (string) $folder['parent_category'],
                (string) $folder['sub_category']
            );
            drms_folder_action_json([
                'status' => 'success',
                'keywords' => (string) ($folder['classification_keywords'] ?? ''),
            ]);
        } catch (DomainException $error) {
            drms_folder_action_json([
                'status' => 'error',
                'message' => $error->getMessage(),
            ], 403);
        } catch (Throwable $error) {
            error_log('Keyword fetch failed: ' . $error->getMessage());
            drms_folder_action_json([
                'status' => 'error',
                'message' => 'Folder keywords could not be loaded.',
            ], 500);
        }
    }

    // ==========================================
    // DAGDAG: REAL-TIME KEYWORD CONFLICT CHECKER (AJAX)
    // ==========================================
    if ($action === 'check_keyword_conflicts') {
        try {
            drms_folder_action_require_manager(
                $conn,
                (int) $user_id,
                (string) $_SESSION['role']
            );
            $category = drms_folder_action_normalize_name(
                $_POST['category'] ?? null,
                'Folder name'
            );
            $folder = drms_folder_action_find_by_sub($conn, $category);
            if (!$folder) {
                throw new DomainException(
                    'The selected record folder no longer exists.'
                );
            }
            drms_folder_action_require_access(
                $conn,
                (int) $user_id,
                (string) $_SESSION['role'],
                (string) $folder['parent_category'],
                (string) $folder['sub_category']
            );
            drms_folder_action_require_mutable($folder);
            $keywords = drms_folder_action_normalize_keywords(
                $_POST['keywords'] ?? ''
            );
            $conflicts = drms_folder_action_keyword_conflicts(
                $conn,
                $category,
                $keywords
            );
            $messages = [];
            foreach ($conflicts as $conflict) {
                $messages[] = htmlspecialchars(
                    "'" . strtoupper((string) $conflict['keyword'])
                    . "' is already used in '"
                    . (string) $conflict['folder'] . "'",
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }
            drms_folder_action_json($messages
                ? ['status' => 'conflict', 'messages' => $messages]
                : ['status' => 'clear', 'messages' => []]
            );
        } catch (DomainException $error) {
            drms_folder_action_json([
                'status' => 'error',
                'message' => $error->getMessage(),
            ], 403);
        } catch (Throwable $error) {
            error_log('Keyword conflict check failed: ' . $error->getMessage());
            drms_folder_action_json([
                'status' => 'error',
                'message' => 'Keyword validation could not be completed.',
            ], 500);
        }
    }

    // START: Rule-Based Automatic Document Classification (PRODUCTION VERSION)
    if ($action === 'analyze_document') {
        header('Content-Type: application/json');
        
        if (!isset($_FILES['document'])) {
            echo json_encode(['status' => 'none']);
            exit();
        }

        $file = $_FILES['document'];
        try {
            $validated_upload = drms_upload_validate(
                $conn,
                $file,
                'document'
            );
        } catch (DrmsUploadValidationException $upload_error) {
            echo json_encode([
                'status' => 'error',
                'message' => $upload_error->getMessage(),
            ]);
            exit();
        }

        $ext = $validated_upload['extension'];
        $tmpPath = $validated_upload['tmp_name'];
        
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
            $document = drmsDocumentMutationAccess(
                $conn,
                $doc_id,
                (int) $user_id,
                (string) ($_SESSION['role'] ?? '')
            );
            if (!$document) {
                throw new DomainException(
                    'Record not found or you do not have permission to archive it.'
                );
            }
            if (!drmsDocumentLifecycleMutable($document)) {
                throw new DomainException(
                    'Converted, destroyed, and permanently archived records cannot be archived through this action.'
                );
            }
            if ((int) ($document['is_legal_hold'] ?? 0) === 1) {
                throw new DomainException(
                    'This record is under Legal Hold and cannot be archived.'
                );
            }
            if (($document['status'] ?? '') !== 'Active') {
                throw new DomainException('Only an active record can be archived.');
            }

            $stmt = $conn->prepare("UPDATE documents SET status = 'Archived' WHERE doc_id = ? AND status = 'Active'");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'ARCHIVE_FILE', $doc_id, "Archived Document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'ARCHIVE_FILE', "Archived Document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Archived");
            } else {
                throw new DomainException('The record changed before it could be archived. Refresh the page and try again.');
            }
        } catch (Throwable $e) {
            error_log("Archive Error: " . $e->getMessage());
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                $e instanceof DomainException
                    ? $e->getMessage()
                    : 'The record could not be archived. Please try again.'
            );
        }
        exit();
    }

    if ($action == 'restore') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id, null, $source);

        try {
            $document = drmsDocumentMutationAccess(
                $conn,
                $doc_id,
                (int) $user_id,
                (string) ($_SESSION['role'] ?? '')
            );
            if (!$document) {
                throw new DomainException(
                    'Record not found or you do not have permission to restore it.'
                );
            }
            if (!drmsDocumentLifecycleMutable($document)) {
                throw new DomainException(
                    'Converted, destroyed, and permanently archived records cannot be restored through this action.'
                );
            }
            if (($document['status'] ?? '') !== 'Archived') {
                throw new DomainException('Only an archived record can be restored.');
            }

            // status and disposition_status are separate lifecycles. Restoring
            // an archive must not write the invalid legacy value "Archived"
            // into the disposition_status enum.
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active' WHERE doc_id = ? AND status = 'Archived'");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'RESTORE_FILE', $doc_id, "Restored Document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored Document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Restored");
            } else {
                throw new DomainException('The record changed before it could be restored. Refresh the page and try again.');
            }
        } catch (Throwable $e) {
            error_log("Restore Error: " . $e->getMessage());
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                $e instanceof DomainException
                    ? $e->getMessage()
                    : 'The archived record could not be restored. Please try again.'
            );
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

        $row = drmsDocumentMutationAccess(
            $conn,
            $doc_id,
            (int) $user_id,
            (string) ($_SESSION['role'] ?? '')
        );
        if (!$row) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'Record not found or you do not have permission to delete it.'
            );
        }
        if (
            in_array(($row['record_phase'] ?? ''), ['Official', 'Converted'], true) ||
            !empty($row['official_doc_id'])
        ) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'Official Records and their protected Company File history cannot be moved to the Recycle Bin.'
            );
        }
        if ((int) ($row['is_legal_hold'] ?? 0) === 1) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'This record is under Legal Hold and cannot be deleted.'
            );
        }
        if (!in_array(($row['record_phase'] ?? ''), ['Working', 'For Review'], true)) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'Only a working Company File can be moved to the Recycle Bin.'
            );
        }
        if (($row['status'] ?? '') === 'Recycled') {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'This record is already in the Recycle Bin.'
            );
        }

        // SOFT DELETE: Move to Recycle Bin instead of unlinking.
        $soft_del = $conn->prepare(
            "UPDATE documents
             SET status = 'Recycled', deleted_at = NOW()
             WHERE doc_id = ?
               AND status <> 'Recycled'
               AND record_phase IN ('Working', 'For Review')
               AND official_doc_id IS NULL"
        );
        $soft_del->bind_param("i", $doc_id);
        $soft_del->execute();
        if ($soft_del->affected_rows === 1) {
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'SOFT_DELETE_FILE', $doc_id, "Moved working document to Recycle Bin: " . $row['file_name'], $redirectUrl);
            } else {
                log_audit_action($conn, $user_id, 'SOFT_DELETE_FILE', "Moved working document to Recycle Bin: " . $row['file_name']);
            }
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'success',
                'Moved to Recycle Bin.'
            );
        }
        drmsDocumentMutationRedirect(
            $redirectUrl,
            'error',
            'The record changed before it could be moved. Refresh the page and try again.'
        );
    }

    // ==========================================
    // RESTORE RECYCLED DOCUMENT
    // ==========================================
    if ($action == 'restore_recycled') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')) die("Access Denied");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? '../general_docs.php';

        $document = drmsDocumentMutationAccess(
            $conn,
            $doc_id,
            (int) $user_id,
            (string) ($_SESSION['role'] ?? '')
        );
        if (
            !$document ||
            !in_array(($document['record_phase'] ?? ''), ['Working', 'For Review'], true) ||
            !empty($document['official_doc_id']) ||
            ($document['status'] ?? '') !== 'Recycled'
        ) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'This working record is unavailable or cannot be restored by your account.'
            );
        }

        $res_stmt = $conn->prepare(
            "UPDATE documents
             SET status = 'Active', deleted_at = NULL
             WHERE doc_id = ?
               AND status = 'Recycled'
               AND record_phase IN ('Working', 'For Review')
               AND official_doc_id IS NULL"
        );
        $res_stmt->bind_param("i", $doc_id);
        $res_stmt->execute();
        if ($res_stmt->affected_rows === 1) {
            log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored document ID $doc_id from Recycle Bin.");
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'success',
                'Document restored successfully.'
            );
        }
        drmsDocumentMutationRedirect(
            $redirectUrl,
            'error',
            'The record changed before it could be restored. Refresh the page and try again.'
        );
    }

    // ==========================================
    // PERMANENT DELETE (AUTHORIZED ONLY)
    // ==========================================
    if ($action == 'permanent_delete') {
        if (!in_array($_SESSION['role'], ['President', 'GM'], true)) die("Unauthorized Action.");

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = $_POST['return_url'] ?? '../general_docs.php';

        $row = drmsDocumentMutationAccess(
            $conn,
            $doc_id,
            (int) $user_id,
            (string) ($_SESSION['role'] ?? '')
        );
        if (
            !$row ||
            ($row['status'] ?? '') !== 'Recycled' ||
            !in_array(($row['record_phase'] ?? ''), ['Working', 'For Review'], true) ||
            !empty($row['official_doc_id']) ||
            (int) ($row['is_legal_hold'] ?? 0) === 1
        ) {
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                'Only an eligible recycled Company File can be permanently deleted by management.'
            );
        }

        $stored_paths = [(string) $row['file_path']];
        $version_stmt = $conn->prepare(
            'SELECT file_path FROM document_versions WHERE doc_id = ?'
        );
        $version_stmt->bind_param('i', $doc_id);
        $version_stmt->execute();
        $version_result = $version_stmt->get_result();
        while ($version = $version_result->fetch_assoc()) {
            $stored_paths[] = (string) ($version['file_path'] ?? '');
        }
        $version_stmt->close();

        $safe_files = [];
        foreach (array_unique(array_filter($stored_paths)) as $stored_path) {
            try {
                $safe_files[] = drms_storage_resolve_existing_file($stored_path);
            } catch (RuntimeException $storage_error) {
                // A missing file does not justify retaining a recycled database
                // row forever. Invalid/outside paths are never unlinked.
                error_log(
                    'Permanent-delete storage entry skipped for document ' .
                    $doc_id . ': ' . $storage_error->getMessage()
                );
            }
        }

        $conn->begin_transaction();
        try {
            $lock_stmt = $conn->prepare(
                "SELECT file_name
                 FROM documents
                 WHERE doc_id = ?
                   AND status = 'Recycled'
                   AND record_phase IN ('Working', 'For Review')
                   AND official_doc_id IS NULL
                   AND is_legal_hold = 0
                 FOR UPDATE"
            );
            $lock_stmt->bind_param('i', $doc_id);
            $lock_stmt->execute();
            $locked = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();
            if (!$locked) {
                throw new DomainException(
                    'The recycled record changed before permanent deletion. Refresh and try again.'
                );
            }

            log_audit_action(
                $conn,
                $user_id,
                'PERMANENT_DELETE',
                'Permanently deleted recycled Company File: ' . $locked['file_name']
            );
            $del_stmt = $conn->prepare(
                "DELETE FROM documents
                 WHERE doc_id = ?
                   AND status = 'Recycled'
                   AND record_phase IN ('Working', 'For Review')
                   AND official_doc_id IS NULL
                   AND is_legal_hold = 0"
            );
            $del_stmt->bind_param("i", $doc_id);
            $del_stmt->execute();
            if ($del_stmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'The recycled record could not be removed from the database.'
                );
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Permanent document deletion failed: ' . $error->getMessage());
            drmsDocumentMutationRedirect(
                $redirectUrl,
                'error',
                $error instanceof DomainException
                    ? $error->getMessage()
                    : 'The recycled record could not be permanently deleted.'
            );
        }

        $cleanup_failed = false;
        foreach (array_unique($safe_files) as $safe_file) {
            if (is_file($safe_file) && !@unlink($safe_file)) {
                $cleanup_failed = true;
                error_log(
                    'Unable to remove recycled document binary after database deletion: ' .
                    $safe_file
                );
            }
        }

        drmsDocumentMutationRedirect(
            $redirectUrl,
            $cleanup_failed ? 'error' : 'success',
            $cleanup_failed
                ? 'The recycled record was removed, but a storage file needs administrator cleanup. Check the server log.'
                : 'Permanently deleted the recycled Company File and its stored versions.'
        );
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
        if ($record_intake === 'official') {
            header('Location: ../general_docs.php?type=' . rawurlencode($doc_category) . '&error=' . rawurlencode('Upload the signed file to Company Files, then submit a declaration request to the General Manager.'));
            exit();
        }
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

        if ((empty($doc_category) && empty($doc_type)) || !$file) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=InvalidInput");
            exit();
        }

        if ($is_official_intake && $doc_category === '') {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode("Select a records folder before filing an Official Record."));
            exit();
        }

        if ($is_official_intake) {
            $doc_type = drms_normalize_official_record_type(
                $doc_type,
                $doc_category
            );
        }

        if (!empty($doc_category) && !userCanUseOfficialFolder($conn, $doc_category, $_SESSION['role'])) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=UnauthorizedFolder");
            exit();
        }

        if (
            $doc_category !== '' &&
            isWorkflowManagedOfficialFolder($conn, $doc_category)
        ) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode("Protected workflow folders are filed automatically from their related PO approval step. Use a client-created folder for manual uploads."));
            exit();
        }

        try {
            $validated_upload = drms_upload_validate(
                $conn,
                $file,
                'document'
            );
        } catch (DrmsUploadValidationException $upload_error) {
            header(
                "Location: $redirectUrl" .
                (strpos($redirectUrl, '?') ? '&' : '?') .
                'error=' . urlencode($upload_error->getMessage())
            );
            exit();
        }

        $ext = $validated_upload['extension'];

        // --- FILENAME SANITIZATION & LENGTH LIMIT (Fix implemented here) ---
        // Enforce a strict mb_substr limit before saving the file to prevent DB truncation
        // This ensures the extension is preserved while keeping the string limit up to 150 characters.
        $base_name = pathinfo($file['name'], PATHINFO_FILENAME);
        $max_len = 150 - mb_strlen($ext) - 1; // reserve space for the dot and extension
        $base_name = mb_substr($base_name, 0, $max_len);
        $sanitized_file_name = $base_name . '.' . $ext;
        // -------------------------------------------------------------------

        $fileHash = hash_file('sha256', $validated_upload['tmp_name']);
        
        $checkStmt = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
        $checkStmt->bind_param("s", $fileHash);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=DuplicateFile");
            exit();
        }

        $policy_id = null;
        $folder_profile = null;
        if ($doc_category !== '') {
            if ($is_official_intake) {
                try {
                    $folder_profile = drms_get_official_folder_profile(
                        $conn,
                        $doc_category
                    );
                    $policy_id = (int) $folder_profile['policy_id'];
                } catch (Throwable $e) {
                    header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode($e->getMessage()));
                    exit();
                }
            } else {
                $policy_stmt = $conn->prepare("SELECT policy_id FROM document_categories WHERE sub_category = ? AND policy_id IS NOT NULL ORDER BY id ASC LIMIT 1");
                $policy_stmt->bind_param("s", $doc_category);
                $policy_stmt->execute();
                $policy_row = $policy_stmt->get_result()->fetch_assoc();
                $policy_id = $policy_row ? (int) $policy_row['policy_id'] : null;
                $policy_stmt->close();
            }
        }

        if ($is_official_intake && $policy_id === null) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode("Assign a retention policy to the selected records folder before filing an Official Record."));
            exit();
        }

        $project_root = realpath(__DIR__ . '/..');
        if ($project_root === false) {
            header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode("The protected project storage could not be located."));
            exit();
        }

        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        // Preserve the uploaded/source name separately. Official Records use
        // the controlled folder code as both the RMS ID and actual file name.
        $original_name_to_save = !empty($document_name)
            ? $document_name . '.' . $ext
            : $sanitized_file_name;
        $record_number = null;
        $record_locked = 0;
        $final_name_to_save = $original_name_to_save;
        $new_filename = time() . '_' . bin2hex(random_bytes(4)) . '_' .
            preg_replace("/[^a-zA-Z0-9.-]/", "_", $sanitized_file_name);
        $dest_path = $upload_dir . $new_filename;
        $db_path = 'uploads/' . $new_filename;

        if ($is_official_intake) {
            try {
                $record_number = drms_allocate_official_record_number(
                    $conn,
                    (string) $folder_profile['record_prefix'],
                    $declared_at
                );
                $record_locked = 1;
                $final_name_to_save = drms_build_official_file_name(
                    $record_number,
                    $original_name_to_save,
                    $file['name']
                );
                $storage = drms_prepare_official_storage_directory(
                    $project_root,
                    (string) $folder_profile['record_prefix']
                );
                $dest_path = $storage['absolute_directory'] .
                    DIRECTORY_SEPARATOR . $final_name_to_save;
                $db_path = $storage['database_directory'] . '/' .
                    $final_name_to_save;

                if (is_file($dest_path)) {
                    throw new RuntimeException(
                        'The allocated Official Record file name already exists.'
                    );
                }
            } catch (Throwable $e) {
                error_log('Official Record intake numbering failed: ' . $e->getMessage());
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode($e->getMessage()));
                exit();
            }
        }

        if (drms_storage_move_uploaded_file($validated_upload['tmp_name'], $dest_path)) {
            $status = 'Active';

            // Official intake records the declaration actor/date and snapshots
            // the retention policy assigned to the selected folder.
            $stmt = $conn->prepare("INSERT INTO documents (po_id, file_name, original_file_name, file_path, category, doc_type, status, record_phase, uploaded_by, file_hash, policy_id, declared_at, declared_by, record_number, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_types = 'i' . str_repeat('s', 7) . 'isisisi';
            $stmt->bind_param($insert_types, $po_id, $final_name_to_save, $original_name_to_save, $db_path, $doc_category, $doc_type, $status, $record_phase, $user_id, $fileHash, $policy_id, $declared_at, $declared_by, $record_number, $record_locked);
            
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

                $record_label = $is_official_intake
                    ? 'signed Official Record ' . $record_number
                    : 'Working Document';
                $audit_description = "Indexed and uploaded $record_label: " . $sanitized_file_name . " [$doc_category]";
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'UPLOAD_RECORD', $new_doc_id, $audit_description, $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'UPLOAD_RECORD', $audit_description);
                }
                $success_message = $is_official_intake
                    ? "Official Record $record_number filed successfully."
                    : 'Working Document uploaded successfully.';
                header("Location: $redirectUrl" . (strpos($redirectUrl, '?') ? '&' : '?') . "success=" . urlencode($success_message));
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
        $doc_id = intval($_POST['doc_id']);
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $redirectUrl = '../official_declarations.php' . ($request_id > 0 ? '?request_id=' . $request_id : '');

        $official_copy_absolute = null;
        $transaction_committed = false;

        try {
            if ($request_id < 1 || !drms_signature_tables_ready($conn)) {
                throw new DomainException('Submit a declaration request before filing this document.');
            }
            $e_signature = drms_esign_prepare(
                $conn,
                (int) $user_id,
                'General Document',
                'Official Declaration',
                $_POST
            );
            $verification_remarks = drms_declaration_text($_POST['remarks'] ?? '', 2000);
            $conn->begin_transaction();

            // 1. Fetch the original working document
            $stmt = $conn->prepare("SELECT * FROM documents WHERE doc_id = ? FOR UPDATE");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $orig = $stmt->get_result()->fetch_assoc();

            if (!$orig) throw new Exception("Document not found.");
            $declaration_request = drms_declaration_locked_request($conn, $request_id, $doc_id);
            drms_declaration_assert_reviewer($declaration_request, drms_declaration_user($conn, (int) $user_id));
            if ((int) ($e_signature['user']['user_id'] ?? 0) !== (int) $user_id) {
                throw new DomainException('The electronic-signature account does not match the active session.');
            }
            if ($declaration_request['declaration_basis'] !== 'External Signed Copy') {
                throw new DomainException('This action requires a document that already contains its required signatures.');
            }
            $verified_source_hash = drms_declaration_assert_unchanged($orig, $declaration_request);
            if (!in_array($orig['record_phase'], ['Working', 'For Review'], true)) {
                throw new Exception("Only a Working or For Review document can be declared as an Official Record.");
            }

            // Validate Enterprise Physical Synchronization
            $physical_check = $conn->prepare("SELECT id FROM virt_document_locations WHERE document_id = ? FOR UPDATE");
            $physical_check->bind_param('i', $doc_id);
            $physical_check->execute();
            $has_registered_physical_copy = (bool) $physical_check->get_result()->fetch_row();
            if ($has_registered_physical_copy && isset($orig['physical_version']) && $orig['current_version'] != $orig['physical_version']) {
                throw new Exception("Cannot declare this document as an Official Record. The stored physical copy (v" . number_format($orig['physical_version'], 1) . ") is not synchronized with the latest digital version (v" . number_format($orig['current_version'], 1) . "). Please physically replace and verify it first.");
            }

            // 2. Resolve the controlled folder code. The numeric sequence is
            // organization-wide per year; the prefix identifies the folder.
            $cat = !empty($orig['category']) ? $orig['category'] : $orig['doc_type'];
            $folder_profile = drms_get_official_folder_profile($conn, $cat);
            drms_declaration_working($orig, $folder_profile);
            if ((int) $folder_profile['is_system_folder'] === 1) {
                throw new Exception(
                    'Protected workflow folders are filed automatically from their related PO approval step.'
                );
            }
            $record_number = drms_allocate_official_record_number(
                $conn,
                (string) $folder_profile['record_prefix']
            );

            // 3. Clone as Official Record (Locks it and moves it to Virtual Cabinet)
            // Safe variables for PHP 8 binding
            $po_id = $orig['po_id'];
            $original_file_name = trim((string) (
                $orig['original_file_name'] ?? $orig['file_name']
            ));
            if ($original_file_name === '') {
                $original_file_name = (string) $orig['file_name'];
            }
            $doc_type = drms_normalize_official_record_type(
                $orig['doc_type'] ?? null,
                $cat
            );
            $status = $orig['status'];
            $uploaded_by = $orig['uploaded_by'];
            // The Official Record is a new controlled record. Keep the source
            // upload date in the Company File and copied version history, but
            // give the Official Record its own filing timestamp.
            $source_uploaded_at = $orig['uploaded_at'];
            $uploaded_at = date('Y-m-d H:i:s');
            $file_hash = $orig['file_hash'];
            $current_version = $orig['current_version'];
            $access_type = $orig['access_type'];
            $file_permissions = $orig['file_permissions'];

            // The official record receives its own immutable binary. This
            // prevents a future disposition from deleting the working copy or
            // another record that happens to reference the same source path.
            $project_root = realpath(__DIR__ . '/..');
            $stored_source_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim((string) $orig['file_path'], '/\\'));
            if ($project_root === false || $stored_source_path === '' || strpos($stored_source_path, '..') !== false) {
                throw new Exception('The source file path is invalid.');
            }

            $source_absolute = realpath($project_root . DIRECTORY_SEPARATOR . $stored_source_path);
            $project_prefix = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($source_absolute === false || !is_file($source_absolute) || stripos($source_absolute, $project_prefix) !== 0) {
                throw new Exception('The source file is missing or outside the protected project storage.');
            }

            $file_name = drms_build_official_file_name(
                $record_number,
                $original_file_name,
                $source_absolute
            );
            $storage = drms_prepare_official_storage_directory(
                $project_root,
                (string) $folder_profile['record_prefix']
            );
            $official_copy_absolute = $storage['absolute_directory'] .
                DIRECTORY_SEPARATOR . $file_name;

            if (is_file($official_copy_absolute)) {
                throw new Exception(
                    'The allocated Official Record file name already exists.'
                );
            }

            if (!copy($source_absolute, $official_copy_absolute)) {
                throw new Exception('Unable to create the immutable Official Record copy.');
            }
            @chmod($official_copy_absolute, 0640);

            // Verify the new official binary against the actual current source
            // file. The stored database hash can be stale after a file update,
            // so it must not block a valid declaration.
            $source_file_hash = hash_file('sha256', $source_absolute);
            $official_copy_hash = hash_file('sha256', $official_copy_absolute);
            if ($source_file_hash === false || $official_copy_hash === false) {
                throw new Exception('Official Record copy verification failed because a file hash could not be calculated.');
            }
            if (!hash_equals(strtolower($source_file_hash), strtolower($official_copy_hash))) {
                throw new Exception('Official Record copy verification failed because the protected copy does not match the source file.');
            }
            if (!hash_equals($verified_source_hash, $official_copy_hash)) {
                throw new DomainException('The source file changed during verification. Submit the latest signed copy.');
            }

            // Store the current, verified checksum on the Official Record.
            $file_hash = $source_file_hash;

            $file_path = $storage['database_directory'] . '/' . $file_name;

            $physical_version = $orig['physical_version'] ?? $current_version;
            $policy_id = (int) $folder_profile['policy_id'];

            $insert = $conn->prepare("INSERT INTO documents (po_id, file_name, original_file_name, file_path, category, doc_type, status, uploaded_by, uploaded_at, file_hash, current_version, physical_version, access_type, file_permissions, policy_id, record_phase, declared_at, declared_by, record_number, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Official', NOW(), ?, ?, 1)");
            $insert->bind_param("issssssissssssiis", 
                $po_id, $file_name, $original_file_name, $file_path, $cat, $doc_type, 
                $status, $uploaded_by, $uploaded_at, $file_hash, 
                $current_version, $physical_version, $access_type, $file_permissions, 
                $policy_id, $user_id, $record_number
            );
            $insert->execute();
            $official_doc_id = $insert->insert_id;

            // 4. Preserve the complete Company File lineage in the new
            // Official Record. The converted source is hidden from Company
            // Files, while its earlier versions remain available here.
            $copy_history_stmt = $conn->prepare(
                "INSERT INTO document_versions (
                    doc_id,
                    version_number,
                    file_name,
                    file_path,
                    file_hash,
                    uploaded_by,
                    uploaded_at,
                    remarks
                 )
                 SELECT
                    ?,
                    source_version.version_number,
                    source_version.file_name,
                    source_version.file_path,
                    source_version.file_hash,
                    source_version.uploaded_by,
                    source_version.uploaded_at,
                    CONCAT(
                        '[Pre-official working version] ',
                        COALESCE(
                            NULLIF(source_version.remarks, ''),
                            'No remarks provided.'
                        )
                    )
                 FROM document_versions source_version
                 WHERE source_version.doc_id = ?
                 ORDER BY
                    CAST(source_version.version_number AS DECIMAL(10,1)) ASC,
                    source_version.uploaded_at ASC,
                    source_version.version_id ASC"
            );
            $copy_history_stmt->bind_param('ii', $official_doc_id, $doc_id);
            $copy_history_stmt->execute();
            $copy_history_stmt->close();

            // Some Company Files have never had a separate version entry.
            // Preserve their current working copy before hiding the source.
            $source_file_name = (string) $orig['file_name'];
            $source_file_path = (string) $orig['file_path'];
            $source_version_exists_stmt = $conn->prepare(
                "SELECT 1
                 FROM document_versions
                 WHERE doc_id = ?
                   AND version_number = ?
                   AND file_path = ?
                 LIMIT 1"
            );
            $source_version_exists_stmt->bind_param(
                'iss',
                $doc_id,
                $current_version,
                $source_file_path
            );
            $source_version_exists_stmt->execute();
            $source_version_exists = (bool) $source_version_exists_stmt
                ->get_result()
                ->fetch_row();
            $source_version_exists_stmt->close();

            if (!$source_version_exists) {
                $source_snapshot_remarks =
                    '[Pre-official working version] Current working copy at declaration.';
                $source_snapshot_stmt = $conn->prepare(
                    "INSERT INTO document_versions (
                        doc_id,
                        version_number,
                        file_name,
                        file_path,
                        file_hash,
                        uploaded_by,
                        uploaded_at,
                        remarks
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $source_snapshot_stmt->bind_param(
                    'issssiss',
                    $official_doc_id,
                    $current_version,
                    $source_file_name,
                    $source_file_path,
                    $source_file_hash,
                    $uploaded_by,
                    $source_uploaded_at,
                    $source_snapshot_remarks
                );
                $source_snapshot_stmt->execute();
                $source_snapshot_stmt->close();
            }

            // Record the new protected copy as the latest item in the
            // consolidated timeline. Its path is inside uploads/official/.
            $official_snapshot_remarks =
                'Official declaration — immutable signed copy.';
            $official_snapshot_stmt = $conn->prepare(
                "INSERT INTO document_versions (
                    doc_id,
                    version_number,
                    file_name,
                    file_path,
                    file_hash,
                    uploaded_by,
                    remarks
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $official_snapshot_stmt->bind_param(
                'issssis',
                $official_doc_id,
                $current_version,
                $file_name,
                $file_path,
                $file_hash,
                $user_id,
                $official_snapshot_remarks
            );
            $official_snapshot_stmt->execute();
            $official_snapshot_stmt->close();

            // 5. Update Original to 'Converted' (Preserves Working Copy, locks editing)
            $update = $conn->prepare("UPDATE documents SET record_phase = 'Converted', official_doc_id = ?, is_locked = 1, file_hash = ? WHERE doc_id = ?");
            $update->bind_param("isi", $official_doc_id, $file_hash, $doc_id);
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

            drms_declaration_finish($conn, $declaration_request, $e_signature, $official_doc_id, $file_hash, $verification_remarks);
            $conn->commit();
            $transaction_committed = true;

            try {
                if (function_exists('log_audit_action')) {
                    log_audit_action($conn, $user_id, 'DECLARE_OFFICIAL', "Declared Doc ID $doc_id as Official Record $record_number with an independently stored immutable copy.");
                }
            } catch (Throwable $audit_error) {
                error_log('Declare Official audit warning: ' . $audit_error->getMessage());
            }
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=" . urlencode("Success! Working copy locked and Official Record $record_number generated."));
            
        } catch (Throwable $e) {
            if (!$transaction_committed) {
                $conn->rollback();
            }
            if (!$transaction_committed && $official_copy_absolute !== null && is_file($official_copy_absolute)) {
                @unlink($official_copy_absolute);
            }
            error_log("Declare Official Error: " . $e->getMessage());
            // FIX: Ipinasa ang totoong $e->getMessage() para malaman ng user kung bakit na-block
            $public_error = $e instanceof mysqli_sql_exception
                ? 'The verification could not be saved. No Official Record was created. Please try again.'
                : $e->getMessage();
            header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "error=" . urlencode($public_error));
        }
        exit();
    }
?>
