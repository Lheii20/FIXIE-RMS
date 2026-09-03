<?php
require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/upload_policy.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'data' => [], 'message' => 'Unauthorized access.']);
    exit;
}

function drms_version_return_url(string $candidate): string
{
    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '../general_docs.php';
    }

    $allowed_pages = ['documents.php', 'general_docs.php'];
    $page = basename(str_replace('\\', '/', (string) ($parts['path'] ?? '')));
    if (!in_array($page, $allowed_pages, true)) {
        return '../general_docs.php';
    }

    $query_params = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query_params);
        unset($query_params['success'], $query_params['error']);
    }

    return '../' . $page . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
}

function drms_version_redirect(string $url, string $type, string $message): void
{
    header('Location: ' . $url . (strpos($url, '?') !== false ? '&' : '?') . $type . '=' . urlencode($message));
    exit;
}

function drms_version_access(mysqli $conn, int $doc_id, int $user_id, string $role): ?array
{
    if ($doc_id < 1 || $role === 'Admin') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT doc_id, file_name, file_path, file_hash, uploaded_by, uploaded_at,
               status, record_phase, disposition_status, category, access_type,
               file_permissions, current_version,
               (SELECT full_name FROM users WHERE user_id = documents.uploaded_by LIMIT 1) AS uploaded_by_name
        FROM documents
        WHERE doc_id = ?
          AND status != 'Recycled'
          AND COALESCE(disposition_status, '') <> 'Destroyed'
          AND (record_phase != 'Converted' OR record_phase IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param('i', $doc_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$document) {
        return null;
    }

    $access_level = 'None';
    if ((int) $document['uploaded_by'] === $user_id || has_permission($conn, $user_id, 'can_view_all_folders')) {
        $access_level = 'Editor';
    } else {
        $permissions = json_decode((string) ($document['file_permissions'] ?? ''), true);
        $direct_level = is_array($permissions) ? ($permissions['user_' . $user_id] ?? '') : '';
        if (in_array($direct_level, ['Viewer', 'Editor'], true)) {
            $access_level = $direct_level;
        } elseif (($document['access_type'] ?? 'Folder Default') === 'Folder Default') {
            $category = trim((string) ($document['category'] ?? ''));
            if ($category !== '') {
                $category_stmt = $conn->prepare("
                    SELECT 1
                    FROM document_categories dc
                    INNER JOIN category_role_access cra ON cra.category_id = dc.id
                    WHERE dc.sub_category = ?
                      AND cra.role_name = ?
                    LIMIT 1
                ");
                $category_stmt->bind_param('ss', $category, $role);
                $category_stmt->execute();
                if ($category_stmt->get_result()->fetch_row()) {
                    $access_level = 'Editor';
                }
                $category_stmt->close();
            }
        }
    }

    if ($access_level === 'None') {
        return null;
    }

    return ['document' => $document, 'level' => $access_level];
}

$user_id = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_version') {
    $source_page = drms_version_return_url((string) ($_POST['source_page'] ?? '../general_docs.php'));
    $session_token = (string) ($_SESSION['csrf_token'] ?? '');
    $request_token = (string) ($_POST['csrf_token'] ?? '');
    if ($session_token === '' || $request_token === '' || !hash_equals($session_token, $request_token)) {
        drms_version_redirect($source_page, 'error', 'Security validation failed. Please refresh the page and try again.');
    }

    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $access = drms_version_access($conn, $doc_id, $user_id, $role);

    if (!$access || $access['level'] !== 'Editor') {
        drms_version_redirect($source_page, 'error', 'Record not found or you do not have permission to upload a version.');
    }

    $document = $access['document'];
    if (($document['status'] ?? '') !== 'Active' || !in_array(($document['record_phase'] ?? ''), ['Working', 'For Review'], true)) {
        drms_version_redirect($source_page, 'error', 'Only active working documents can receive a new version.');
    }

    if ($remarks === '') {
        drms_version_redirect($source_page, 'error', 'Enter version remarks before uploading.');
    }
    $remarks = mb_substr($remarks, 0, 1000);

    $file = $_FILES['new_document'] ?? null;
    try {
        $validated_upload = drms_upload_validate(
            $conn,
            $file,
            'document'
        );
    } catch (DrmsUploadValidationException $upload_error) {
        drms_version_redirect(
            $source_page,
            'error',
            $upload_error->getMessage()
        );
    }

    $extension = $validated_upload['extension'];

    $base_name = pathinfo(basename((string) $file['name']), PATHINFO_FILENAME);
    $base_name = preg_replace('/[^\pL\pN ._()\-]+/u', '_', $base_name) ?: 'document';
    $base_name = trim($base_name, " ._-");
    if ($base_name === '') {
        $base_name = 'document';
    }
    $display_name = mb_substr($base_name, 0, 140) . '.' . $extension;

    $upload_directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true)) {
        drms_version_redirect($source_page, 'error', 'File storage is unavailable.');
    }

    $stored_disk_name = bin2hex(random_bytes(16)) . '.' . $extension;
    $stored_absolute_path = $upload_directory . DIRECTORY_SEPARATOR . $stored_disk_name;
    $stored_database_path = 'uploads/' . $stored_disk_name;
    $new_file_hash = hash_file(
        'sha256',
        $validated_upload['tmp_name']
    );

    if ($new_file_hash === false) {
        drms_version_redirect($source_page, 'error', 'Unable to verify the uploaded file.');
    }
    if (!empty($document['file_hash']) && hash_equals((string) $document['file_hash'], $new_file_hash)) {
        drms_version_redirect($source_page, 'error', 'The selected file is identical to the current version.');
    }

    $file_moved = false;
    $conn->begin_transaction();
    try {
        $lock_stmt = $conn->prepare("
            SELECT file_name, file_path, file_hash, uploaded_by, uploaded_at,
                   status, record_phase, disposition_status, current_version
            FROM documents
            WHERE doc_id = ?
            FOR UPDATE
        ");
        $lock_stmt->bind_param('i', $doc_id);
        $lock_stmt->execute();
        $locked_document = $lock_stmt->get_result()->fetch_assoc();
        $lock_stmt->close();

        if (
            !$locked_document ||
            ($locked_document['status'] ?? '') !== 'Active' ||
            !in_array(($locked_document['record_phase'] ?? ''), ['Working', 'For Review'], true) ||
            ($locked_document['disposition_status'] ?? '') === 'Destroyed'
        ) {
            throw new RuntimeException('Only active working documents can receive a new version.');
        }

        $version_stmt = $conn->prepare("
            SELECT COALESCE(MAX(CAST(version_number AS DECIMAL(10,1))), 0) AS highest_version,
                   COUNT(*) AS version_count
            FROM document_versions
            WHERE doc_id = ?
        ");
        $version_stmt->bind_param('i', $doc_id);
        $version_stmt->execute();
        $version_state = $version_stmt->get_result()->fetch_assoc() ?: [];
        $version_stmt->close();

        $current_version = max(
            (float) ($locked_document['current_version'] ?? 1.0),
            (float) ($version_state['highest_version'] ?? 0)
        );

        if ((int) ($version_state['version_count'] ?? 0) === 0) {
            $original_version = number_format((float) ($locked_document['current_version'] ?? 1.0), 1, '.', '');
            $original_remarks = 'Original Document Upload';
            $original_stmt = $conn->prepare("
                INSERT INTO document_versions
                    (doc_id, version_number, file_name, file_path, uploaded_by, uploaded_at, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $original_stmt->bind_param(
                'isssiss',
                $doc_id,
                $original_version,
                $locked_document['file_name'],
                $locked_document['file_path'],
                $locked_document['uploaded_by'],
                $locked_document['uploaded_at'],
                $original_remarks
            );
            $original_stmt->execute();
            $original_stmt->close();
        }

        if (!move_uploaded_file($validated_upload['tmp_name'], $stored_absolute_path)) {
            throw new RuntimeException('Unable to store the uploaded version.');
        }
        $file_moved = true;

        $new_version = number_format($current_version + 1.0, 1, '.', '');
        $insert_stmt = $conn->prepare("
            INSERT INTO document_versions
                (doc_id, version_number, file_name, file_path, remarks, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->bind_param(
            'issssi',
            $doc_id,
            $new_version,
            $display_name,
            $stored_database_path,
            $remarks,
            $user_id
        );
        $insert_stmt->execute();
        $insert_stmt->close();

        $update_stmt = $conn->prepare("
            UPDATE documents
            SET file_path = ?, file_name = ?, current_version = ?, file_hash = ?
            WHERE doc_id = ?
        ");
        $update_stmt->bind_param(
            'ssssi',
            $stored_database_path,
            $display_name,
            $new_version,
            $new_file_hash,
            $doc_id
        );
        $update_stmt->execute();
        $update_stmt->close();

        if (function_exists('log_audit_action')) {
            log_audit_action($conn, $user_id, 'UPDATE_VERSION', "Uploaded v$new_version for Doc ID: $doc_id");
        }

        $conn->commit();
        drms_version_redirect($source_page, 'success', "Version updated to v$new_version successfully.");
    } catch (Throwable $e) {
        $conn->rollback();
        if ($file_moved && is_file($stored_absolute_path)) {
            @unlink($stored_absolute_path);
        }
        error_log('Document version upload failed: ' . $e->getMessage());
        $known_messages = [
            'Only active working documents can receive a new version.',
            'Unable to store the uploaded version.'
        ];
        $message = in_array($e->getMessage(), $known_messages, true)
            ? $e->getMessage()
            : 'Unable to upload the new version. Please try again.';
        drms_version_redirect($source_page, 'error', $message);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_history') {
    header('Content-Type: application/json');
    $doc_id = (int) ($_GET['doc_id'] ?? 0);
    $access = drms_version_access($conn, $doc_id, $user_id, $role);

    if (!$access) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'data' => [],
            'message' => 'Record not found or you do not have access to its version history.'
        ]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT dv.version_id, dv.version_number, dv.remarks, dv.uploaded_at,
               u.full_name AS uploader
        FROM document_versions dv
        LEFT JOIN users u ON dv.uploaded_by = u.user_id
        WHERE dv.doc_id = ?
        ORDER BY CAST(dv.version_number AS DECIMAL(10,1)) DESC, dv.uploaded_at DESC
    ");
    $stmt->bind_param('i', $doc_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $versions = [];
    while ($row = $result->fetch_assoc()) {
        $versions[] = [
            'version_number' => number_format((float) $row['version_number'], 1),
            'remarks' => htmlspecialchars((string) ($row['remarks'] ?? ''), ENT_QUOTES),
            'uploaded_at_formatted' => date('M d, Y h:i A', strtotime((string) $row['uploaded_at'])),
            'uploaded_by_name' => htmlspecialchars((string) ($row['uploader'] ?? 'Unknown'), ENT_QUOTES),
            'file_path' => 'download.php?type=document_version&record_id=' . (int) $row['version_id']
        ];
    }
    $stmt->close();

    if (count($versions) === 0) {
        $document = $access['document'];
        $versions[] = [
            'version_number' => number_format((float) ($document['current_version'] ?? 1.0), 1),
            'remarks' => 'Original Document Upload',
            'uploaded_at_formatted' => date('M d, Y h:i A', strtotime((string) $document['uploaded_at'])),
            'uploaded_by_name' => htmlspecialchars((string) ($document['uploaded_by_name'] ?? 'Original uploader'), ENT_QUOTES),
            'file_path' => 'download.php?type=document&record_id=' . $doc_id
        ];
    }

    echo json_encode(['success' => true, 'data' => $versions]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'data' => [], 'message' => 'Invalid action.']);
