<?php
require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/upload_policy.php';
require_once '../config/file_integrity.php';

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

        $current_absolute_path = drms_storage_resolve_existing_file(
            (string) $locked_document['file_path']
        );
        try {
            drms_file_integrity_verify(
                $current_absolute_path,
                $locked_document['file_hash'] ?? null
            );
        } catch (DrmsFileIntegrityException $integrityError) {
            throw new RuntimeException(
                'The current file failed integrity verification. A new version was not accepted.'
            );
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
                    (doc_id, version_number, file_name, file_path, file_hash,
                     uploaded_by, uploaded_at, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $original_stmt->bind_param(
                'issssiss',
                $doc_id,
                $original_version,
                $locked_document['file_name'],
                $locked_document['file_path'],
                $locked_document['file_hash'],
                $locked_document['uploaded_by'],
                $locked_document['uploaded_at'],
                $original_remarks
            );
            $original_stmt->execute();
            $original_stmt->close();
        }

        if (!drms_storage_move_uploaded_file($validated_upload['tmp_name'], $stored_absolute_path)) {
            throw new RuntimeException('Unable to store the uploaded version.');
        }
        $file_moved = true;

        $new_version = number_format($current_version + 1.0, 1, '.', '');
        $insert_stmt = $conn->prepare("
            INSERT INTO document_versions
                (doc_id, version_number, file_name, file_path, file_hash,
                 remarks, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->bind_param(
            'isssssi',
            $doc_id,
            $new_version,
            $display_name,
            $stored_database_path,
            $new_file_hash,
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
            'Unable to store the uploaded version.',
            'The current file failed integrity verification. A new version was not accepted.'
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
    $version_activity_rows = [];
    while ($row = $result->fetch_assoc()) {
        $version_activity_rows[] = $row;
        $versions[] = [
            'version_number' => number_format((float) $row['version_number'], 1),
            'remarks' => htmlspecialchars((string) ($row['remarks'] ?? ''), ENT_QUOTES),
            'uploaded_at_formatted' => date('M d, Y h:i A', strtotime((string) $row['uploaded_at'])),
            'uploaded_by_name' => htmlspecialchars((string) ($row['uploader'] ?? 'Unknown'), ENT_QUOTES),
            'file_path' => 'download.php?type=document_version&record_id=' . (int) $row['version_id']
        ];
    }
    $stmt->close();

    // Build one consolidated, read-only activity stream. Official Records keep
    // their own protected version snapshots, while the linked Converted row
    // retains the actions that occurred when the file was still a Company File.
    $lineage_stmt = $conn->prepare("
        SELECT
            current_document.doc_id,
            current_document.file_name,
            current_document.original_file_name,
            current_document.record_phase,
            current_document.record_number,
            current_document.uploaded_at,
            current_document.rename_history,
            current_document.declared_at,
            current_uploader.full_name AS current_uploader_name,
            declarer.full_name AS declarer_name,
            source_document.doc_id AS source_doc_id,
            source_document.file_name AS source_file_name,
            source_document.original_file_name AS source_original_file_name,
            source_document.uploaded_at AS source_uploaded_at,
            source_document.rename_history AS source_rename_history,
            source_uploader.full_name AS source_uploader_name
        FROM documents current_document
        LEFT JOIN users current_uploader
               ON current_uploader.user_id = current_document.uploaded_by
        LEFT JOIN users declarer
               ON declarer.user_id = current_document.declared_by
        LEFT JOIN documents source_document
               ON source_document.official_doc_id = current_document.doc_id
        LEFT JOIN users source_uploader
               ON source_uploader.user_id = source_document.uploaded_by
        WHERE current_document.doc_id = ?
        ORDER BY source_document.doc_id ASC
        LIMIT 1
    ");
    $lineage_stmt->bind_param('i', $doc_id);
    $lineage_stmt->execute();
    $lineage = $lineage_stmt->get_result()->fetch_assoc() ?: [];
    $lineage_stmt->close();

    $activity = [];
    $activity_keys = [];
    $append_activity = static function (
        string $type,
        string $title,
        string $detail,
        string $actor,
        ?string $date_value
    ) use (&$activity, &$activity_keys): void {
        $timestamp = $date_value ? strtotime($date_value) : false;
        if ($timestamp === false) {
            return;
        }

        $normalized_date = date('Y-m-d H:i:s', $timestamp);
        $key = implode('|', [$type, $normalized_date, $title, $detail, $actor]);
        if (isset($activity_keys[$key])) {
            return;
        }
        $activity_keys[$key] = true;
        $activity[] = [
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'actor' => $actor !== '' ? $actor : 'System',
            'date' => date(DATE_ATOM, $timestamp),
            'date_formatted' => date('M d, Y h:i A', $timestamp),
            '_timestamp' => $timestamp
        ];
    };

    $has_source = !empty($lineage['source_doc_id']);
    $origin_file_name = (string) (
        $has_source
            ? ($lineage['source_original_file_name'] ?: $lineage['source_file_name'])
            : ($lineage['original_file_name'] ?: $lineage['file_name'] ?? '')
    );
    $origin_uploaded_at = (string) (
        $has_source ? ($lineage['source_uploaded_at'] ?? '') : ($lineage['uploaded_at'] ?? '')
    );
    $origin_uploader = (string) (
        $has_source
            ? ($lineage['source_uploader_name'] ?? 'Original uploader')
            : ($lineage['current_uploader_name'] ?? 'Original uploader')
    );

    $append_activity(
        'upload',
        $has_source ? 'Company File uploaded' : 'Record uploaded',
        $origin_file_name !== '' ? 'Original file: ' . $origin_file_name : 'Initial file received by the system.',
        $origin_uploader,
        $origin_uploaded_at
    );

    $append_legacy_history = static function ($encoded_history) use ($append_activity): void {
        $items = json_decode((string) ($encoded_history ?? ''), true);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'rename');
            $actor = (string) ($item['by'] ?? 'System');
            $date_value = isset($item['date']) ? (string) $item['date'] : null;
            $title = 'File activity recorded';
            $detail = '';

            if ($type === 'lock') {
                $title = 'Checked out the file';
                $detail = 'The file was locked for exclusive editing.';
            } elseif ($type === 'unlock') {
                $title = 'Checked in the file';
                $detail = 'The file was returned and made available.';
            } elseif ($type === 'hold_apply') {
                $title = 'Legal hold applied';
                $detail = trim((string) ($item['reason'] ?? ''));
            } elseif ($type === 'hold_remove') {
                $title = 'Legal hold removed';
                $detail = 'Standard retention and disposition rules resumed.';
            } elseif ($type === 'physical_replaced') {
                $title = 'Physical copy synchronized';
                $old_version = isset($item['old_version']) ? (string) $item['old_version'] : '';
                $new_version = isset($item['new_version']) ? (string) $item['new_version'] : '';
                $detail = ($old_version !== '' || $new_version !== '')
                    ? 'Physical version ' . $old_version . ' was replaced by version ' . $new_version . '.'
                    : 'The physical copy was synchronized with the digital record.';
            } else {
                $title = 'File renamed';
                $old_name = trim((string) ($item['old_name'] ?? ''));
                $new_name = trim((string) ($item['new_name'] ?? ''));
                $detail = ($old_name !== '' || $new_name !== '')
                    ? $old_name . ' to ' . $new_name
                    : 'The file name was updated.';
                $type = 'rename';
            }

            $append_activity($type, $title, $detail, $actor, $date_value);
        }
    };

    if ($has_source) {
        $append_legacy_history($lineage['source_rename_history'] ?? '[]');
    }

    foreach ($version_activity_rows as $version_row) {
        $remarks = trim((string) ($version_row['remarks'] ?? ''));
        if (stripos($remarks, 'Official declaration') !== false) {
            continue;
        }

        $version_number = number_format((float) ($version_row['version_number'] ?? 1), 1);
        $is_pre_official = stripos($remarks, '[Pre-official working version]') !== false;
        $clean_remarks = trim(str_ireplace('[Pre-official working version]', '', $remarks));
        $append_activity(
            'version',
            ($is_pre_official ? 'Working version ' : 'Version ') . 'v' . $version_number . ' recorded',
            $clean_remarks !== '' ? $clean_remarks : 'No version remarks were provided.',
            (string) ($version_row['uploader'] ?? 'Unknown'),
            (string) ($version_row['uploaded_at'] ?? '')
        );
    }

    $append_legacy_history($lineage['rename_history'] ?? '[]');

    if (($lineage['record_phase'] ?? '') === 'Official' && !empty($lineage['declared_at'])) {
        $record_number = trim((string) ($lineage['record_number'] ?? ''));
        $append_activity(
            'official',
            'Official Record filed',
            $record_number !== ''
                ? 'Filed under record number ' . $record_number . '.'
                : 'The signed file was declared and protected as an Official Record.',
            (string) ($lineage['declarer_name'] ?? 'Authorized signatory'),
            (string) $lineage['declared_at']
        );
    }

    usort($activity, static function (array $left, array $right): int {
        return ($right['_timestamp'] ?? 0) <=> ($left['_timestamp'] ?? 0);
    });
    foreach ($activity as &$activity_item) {
        unset($activity_item['_timestamp']);
    }
    unset($activity_item);

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

    echo json_encode([
        'success' => true,
        'data' => $versions,
        'activity' => $activity
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'data' => [], 'message' => 'Invalid action.']);
