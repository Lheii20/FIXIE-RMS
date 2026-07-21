<?php
require 'config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Access Denied: Please log in first.");
}

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit("No file specified.");
}

$file = basename($_GET['file']);
$type = $_GET['type'] ?? 'doc';
$role = $_SESSION['role'] ?? '';
$userId = (int) $_SESSION['user_id'];

if ($file === '') {
    http_response_code(400);
    exit("Invalid file.");
}

$allowed_types = ['doc', 'avatar', 'payment_proof', 'quotation_proof'];
if (!in_array($type, $allowed_types, true)) {
    http_response_code(400);
    exit("Invalid file type.");
}

if ($type === 'avatar') {
    $filepath = 'uploads/avatars/' . $file;
} elseif ($type === 'payment_proof') {
    $filepath = 'uploads/payments/' . $file;
} elseif ($type === 'quotation_proof') {
    $filepath = 'uploads/pos/' . $file;
} else {
    $filepath = 'uploads/' . $file;
}

if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    exit("File not found.");
}

if ($type === 'payment_proof') {
    $stmt = $conn->prepare("SELECT po_id, recorded_by FROM payments WHERE proof_file_path = ? LIMIT 1");
    $stmt->bind_param("s", $file);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    if (!$payment) {
        http_response_code(403);
        exit("Access Denied.");
    }

    $allowed_roles = ['Admin', 'Finance', 'GM', 'President'];
    $is_allowed_role = in_array($role, $allowed_roles, true);
    $is_recorder = ((int) ($payment['recorded_by'] ?? 0) === $userId);
    if (!$is_allowed_role && !$is_recorder) {
        http_response_code(403);
        exit("Access Denied.");
    }
}

if ($type === 'quotation_proof') {
    $stmt = $conn->prepare("SELECT quotation_id, created_by FROM quotations WHERE po_file_path = ? LIMIT 1");
    $stmt->bind_param("s", $file);
    $stmt->execute();
    $quotation = $stmt->get_result()->fetch_assoc();
    if (!$quotation) {
        http_response_code(403);
        exit("Access Denied.");
    }

    $allowed_roles = ['Admin', 'Sales Staff', 'Procurement', 'GM', 'President', 'Finance'];
    $is_allowed_role = in_array($role, $allowed_roles, true);
    $is_creator = ((int) ($quotation['created_by'] ?? 0) === $userId);
    if (!$is_allowed_role && !$is_creator) {
        http_response_code(403);
        exit("Access Denied.");
    }
}

if ($type === 'doc') {
    $is_executive = in_array($role, ['GM', 'President'], true);

    if (!$is_executive) {
        $allowed = false;
        $doc_found = false;
        $doc_category = null;
        $uploader_id = null;

        if (isset($_GET['doc_id']) && ctype_digit($_GET['doc_id'])) {
            $doc_id = intval($_GET['doc_id']);
            $stmt = $conn->prepare("SELECT category, uploaded_by FROM documents WHERE doc_id = ? LIMIT 1");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $doc_found = true;
                $doc_category = $row['category'];
                $uploader_id = $row['uploaded_by'];
            }
        }

        if (!$doc_found) {
            $stmt = $conn->prepare("SELECT category, uploaded_by FROM documents WHERE file_name = ? OR file_path LIKE ? LIMIT 1");
            $like_file = "%" . $file;
            $stmt->bind_param("ss", $file, $like_file);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $doc_found = true;
                $doc_category = $row['category'];
                $uploader_id = $row['uploaded_by'];
            }
        }

        if ($doc_found) {
            if (empty($doc_category)) {
                $allowed = true;
            } elseif ((int) $uploader_id === $userId) {
                $allowed = true;
            } else {
                $stmt = $conn->prepare("SELECT assigned_to_role FROM document_categories WHERE sub_category = ? LIMIT 1");
                $stmt->bind_param("s", $doc_category);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $assigned = $row['assigned_to_role'];
                    if (!empty($assigned)) {
                        $roles_allowed = array_map('trim', explode(',', $assigned));
                        foreach ($roles_allowed as $r) {
                            if (strcasecmp($r, $role) === 0) {
                                $allowed = true;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (!$allowed) {
            http_response_code(403);
            exit("Access Denied: Data Privacy Violation. You only have metadata access. You do not have permission to view or download the contents of this confidential document.");
        }
    }
}

if ($type === 'doc' && isset($_GET['doc_id']) && ctype_digit($_GET['doc_id'])) {
    require_once 'config/functions.php';
    log_document_action($conn, $userId, 'DOWNLOAD_DOC', intval($_GET['doc_id']), "Downloaded document: $file", $_SERVER['REQUEST_URI'] ?? null);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($filepath));

if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'], true)) {
    header("Content-Disposition: inline; filename=\"" . $file . "\"");
} else {
    header("Content-Disposition: attachment; filename=\"" . $file . "\"");
}

header("Cache-Control: private, max-age=0, must-revalidate");
header("Pragma: public");

ob_clean();
flush();
readfile($filepath);
exit;
?>

