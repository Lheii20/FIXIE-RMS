<?php
require 'config/db_connect.php';
require_once 'config/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied. Please log in first.');
}

$user_id = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$type = trim((string) ($_GET['type'] ?? 'document'));
$record_id_input = (string) ($_GET['record_id'] ?? $_GET['doc_id'] ?? '');
$record_id = ctype_digit($record_id_input) ? (int) $record_id_input : 0;
$stored_path = '';
$download_name = '';
$audit_document_id = null;

function drms_download_error(int $status, string $message): void
{
    http_response_code($status);
    exit($message);
}

function drms_download_role_allowed(string $role, array $roles): bool
{
    return in_array($role, $roles, true);
}

function drms_document_is_accessible(
    mysqli $conn,
    array $document,
    int $user_id,
    string $role
): bool {
    // System Administrators maintain the application but do not read company records.
    if ($role === 'Admin') {
        return false;
    }

    if ((int) ($document['uploaded_by'] ?? 0) === $user_id) {
        return true;
    }

    if (drms_download_role_allowed($role, ['GM', 'President'])) {
        return true;
    }

    if (
        ($document['doc_type'] ?? '') === 'Proof of Delivery' &&
        drms_download_role_allowed($role, ['Finance', 'Supply Chain'])
    ) {
        return true;
    }

    if (
        !empty($document['po_id']) &&
        ($document['doc_type'] ?? '') !== 'Proof of Delivery' &&
        drms_download_role_allowed(
            $role,
            ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain']
        )
    ) {
        return true;
    }

    if (has_permission($conn, $user_id, 'can_view_all_folders')) {
        return true;
    }

    $permissions = json_decode(
        (string) ($document['file_permissions'] ?? ''),
        true
    );
    $user_permission = is_array($permissions)
        ? ($permissions['user_' . $user_id] ?? '')
        : '';
    if (in_array($user_permission, ['Viewer', 'Editor'], true)) {
        return true;
    }

    if (($document['access_type'] ?? 'Folder Default') !== 'Folder Default') {
        return false;
    }

    $category = trim((string) ($document['category'] ?? ''));
    if ($category === '') {
        return false;
    }

    $category_stmt = $conn->prepare(
        "SELECT
            GROUP_CONCAT(
                DISTINCT dc.assigned_to_role
                SEPARATOR ','
            ) AS assigned_to_role,
            MAX(CASE WHEN cra.role_name = ? THEN 1 ELSE 0 END) AS has_role_access
         FROM document_categories dc
         LEFT JOIN category_role_access cra
            ON cra.category_id = dc.id
         WHERE dc.sub_category = ?
         LIMIT 1"
    );
    $category_stmt->bind_param('ss', $role, $category);
    $category_stmt->execute();
    $category_record = $category_stmt->get_result()->fetch_assoc();
    $category_stmt->close();

    if (!$category_record) {
        return false;
    }

    if ((int) ($category_record['has_role_access'] ?? 0) === 1) {
        return true;
    }

    $assigned_roles = array_filter(array_map(
        'trim',
        explode(',', (string) ($category_record['assigned_to_role'] ?? ''))
    ));

    foreach ($assigned_roles as $assigned_role) {
        if (strcasecmp($assigned_role, $role) === 0) {
            return true;
        }
    }

    return false;
}

function drms_resolve_upload_path(string $stored_path): string
{
    $uploads_root = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploads_root === false) {
        drms_download_error(404, 'File storage is unavailable.');
    }

    $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stored_path);
    $normalized_path = ltrim($normalized_path, DIRECTORY_SEPARATOR);
    if (stripos($normalized_path, 'uploads' . DIRECTORY_SEPARATOR) === 0) {
        $normalized_path = substr(
            $normalized_path,
            strlen('uploads' . DIRECTORY_SEPARATOR)
        );
    }

    $resolved_path = realpath(
        $uploads_root . DIRECTORY_SEPARATOR . $normalized_path
    );
    $required_prefix = $uploads_root . DIRECTORY_SEPARATOR;

    if (
        $resolved_path === false ||
        !is_file($resolved_path) ||
        strncmp($resolved_path, $required_prefix, strlen($required_prefix)) !== 0
    ) {
        drms_download_error(404, 'File not found.');
    }

    return $resolved_path;
}

try {
    if ($type === 'avatar') {
        $avatar_file = basename((string) ($_GET['file'] ?? ''));
        if ($avatar_file === '') {
            drms_download_error(400, 'Invalid avatar file.');
        }

        $avatar_path = 'uploads/avatars/' . $avatar_file;
        $avatar_stmt = $conn->prepare(
            "SELECT user_id, avatar
             FROM users
             WHERE (avatar = ? OR avatar = ?)
               AND (user_id = ? OR ? = 'Admin')
             ORDER BY CASE WHEN user_id = ? THEN 0 ELSE 1 END
             LIMIT 1"
        );
        $avatar_stmt->bind_param(
            'ssisi',
            $avatar_path,
            $avatar_file,
            $user_id,
            $role,
            $user_id
        );
        $avatar_stmt->execute();
        $avatar_record = $avatar_stmt->get_result()->fetch_assoc();
        $avatar_stmt->close();

        if (
            !$avatar_record ||
            basename((string) ($avatar_record['avatar'] ?? '')) !== $avatar_file ||
            (
                (int) ($avatar_record['user_id'] ?? 0) !== $user_id &&
                $role !== 'Admin'
            )
        ) {
            drms_download_error(403, 'Access denied.');
        }

        $stored_path = $avatar_path;
        $download_name = $avatar_file;
    } elseif ($type === 'client_approval') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid client approval record.');
        }

        $stmt = $conn->prepare(
            "SELECT proof_original_name, proof_file_path, recorded_by
             FROM client_approval_records
             WHERE approval_record_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = drms_download_role_allowed(
            $role,
            ['Sales Staff', 'Procurement', 'GM', 'Finance', 'President']
        ) || (int) ($record['recorded_by'] ?? 0) === $user_id;
        if (!$record || !$allowed) {
            drms_download_error(403, 'Access denied.');
        }

        $stored_path = 'uploads/pos/' . basename($record['proof_file_path']);
        $download_name = (string) $record['proof_original_name'];
    } elseif ($type === 'supplier_quote') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid supplier quotation record.');
        }

        $stmt = $conn->prepare(
            "SELECT
                supplier_quote_original_name,
                supplier_quote_file_path,
                created_by
             FROM pr_supplier_details
             WHERE supplier_detail_id = ?
               AND record_status = 'Active'
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = drms_download_role_allowed(
            $role,
            ['Sales Staff', 'Procurement', 'GM', 'Finance', 'President']
        ) || (int) ($record['created_by'] ?? 0) === $user_id;
        if (!$record || !$allowed || empty($record['supplier_quote_file_path'])) {
            drms_download_error(403, 'Access denied.');
        }

        $stored_path = 'uploads/supplier_quotes/' .
            basename($record['supplier_quote_file_path']);
        $download_name = (string) ($record['supplier_quote_original_name'] ?? '');
    } elseif ($type === 'fund_release') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid fund release record.');
        }

        $stmt = $conn->prepare(
            "SELECT proof_original_name, proof_file_path, released_by
             FROM po_supplier_fund_releases
             WHERE fund_release_id = ?
               AND record_status = 'Active'
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = drms_download_role_allowed(
            $role,
            ['Procurement', 'GM', 'Finance', 'President']
        ) || (int) ($record['released_by'] ?? 0) === $user_id;
        if (!$record || !$allowed) {
            drms_download_error(403, 'Access denied.');
        }

        $stored_path = 'uploads/fund_releases/' .
            basename($record['proof_file_path']);
        $download_name = (string) $record['proof_original_name'];
    } elseif ($type === 'payment_proof') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid payment record.');
        }

        $stmt = $conn->prepare(
            "SELECT payment_id, proof_file_path, recorded_by
             FROM payments
             WHERE payment_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $allowed = drms_download_role_allowed(
            $role,
            ['Finance', 'GM', 'President']
        ) || (int) ($record['recorded_by'] ?? 0) === $user_id;
        if (!$record || !$allowed || empty($record['proof_file_path'])) {
            drms_download_error(403, 'Access denied.');
        }

        $stored_path = 'uploads/payments/' .
            basename($record['proof_file_path']);
        $download_name = basename($record['proof_file_path']);
    } elseif ($type === 'document_version') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid document version record.');
        }

        $stmt = $conn->prepare(
            "SELECT
                dv.version_id,
                dv.version_number,
                dv.file_name AS version_file_name,
                dv.file_path AS version_file_path,
                d.doc_id,
                d.po_id,
                d.doc_type,
                d.file_name,
                d.category,
                d.uploaded_by,
                d.status,
                d.disposition_status,
                d.access_type,
                d.file_permissions
             FROM document_versions dv
             JOIN documents d ON d.doc_id = dv.doc_id
             WHERE dv.version_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            !$record ||
            !drms_document_is_accessible($conn, $record, $user_id, $role)
        ) {
            drms_download_error(403, 'Access denied.');
        }
        if (($record['disposition_status'] ?? '') === 'Destroyed') {
            drms_download_error(
                410,
                'This record was securely destroyed. Its stored versions are no longer available.'
            );
        }

        $stored_path = (string) $record['version_file_path'];
        $version_name = trim((string) ($record['version_file_name'] ?? ''));
        $download_name = $version_name !== ''
            ? $version_name
            : (string) $record['file_name'];
        $audit_document_id = (int) $record['doc_id'];
    } elseif ($type === 'document') {
        if ($record_id < 1) {
            drms_download_error(400, 'Invalid document record.');
        }

        $stmt = $conn->prepare(
            "SELECT
                doc_id,
                po_id,
                doc_type,
                file_name,
                file_path,
                category,
                uploaded_by,
                status,
                disposition_status,
                access_type,
                file_permissions
             FROM documents
             WHERE doc_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            !$record ||
            !drms_document_is_accessible($conn, $record, $user_id, $role)
        ) {
            drms_download_error(403, 'Access denied.');
        }

        if (
            ($record['disposition_status'] ?? '') === 'Destroyed' ||
            str_starts_with((string) ($record['file_path'] ?? ''), '[SECURELY DESTROYED')
        ) {
            drms_download_error(
                410,
                'This record was securely destroyed. Only its disposition history and destruction certificate remain available.'
            );
        }

        $stored_path = (string) $record['file_path'];
        $download_name = (string) $record['file_name'];
        $audit_document_id = (int) $record['doc_id'];
    } else {
        drms_download_error(400, 'Invalid file type.');
    }
} catch (mysqli_sql_exception $error) {
    error_log('Secure file lookup failed: ' . $error->getMessage());
    drms_download_error(500, 'The file could not be opened right now.');
}

$absolute_path = drms_resolve_upload_path($stored_path);
$safe_name = trim(str_replace(["\r", "\n", '"'], '', $download_name));
if ($safe_name === '') {
    $safe_name = basename($absolute_path);
}

if ($audit_document_id !== null) {
    log_document_action(
        $conn,
        $user_id,
        'DOWNLOAD_DOC',
        $audit_document_id,
        'Opened document: ' . $safe_name,
        $_SERVER['REQUEST_URI'] ?? null
    );
}

$file_info = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $file_info->file($absolute_path) ?: 'application/octet-stream';
$inline_types = [
    'application/pdf',
    'image/gif',
    'image/jpeg',
    'image/png',
    'image/webp',
];
$disposition = in_array($mime_type, $inline_types, true)
    ? 'inline'
    : 'attachment';
$fallback_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe_name);
if ($fallback_name === '' || $fallback_name === null) {
    $fallback_name = 'document';
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($absolute_path));
header('Content-Disposition: ' . $disposition . '; filename="' .
    $fallback_name . '"; filename*=UTF-8\'\'' . rawurlencode($safe_name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

while (ob_get_level() > 0) {
    ob_end_clean();
}

$stream = fopen($absolute_path, 'rb');
if ($stream === false) {
    drms_download_error(500, 'The file could not be opened right now.');
}

fpassthru($stream);
fclose($stream);
exit();
