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

// Ihiwalay ang path para sa mga avatars at documents
if ($type === 'avatar') {
    $filepath = 'uploads/avatars/' . $file;
} else {
    $filepath = 'uploads/' . $file;
}

if (!file_exists($filepath)) {
    http_response_code(404);
    exit("File not found.");
}

// ==========================================
// IDOR PROTECTION: STRICT ACCESS CONTROL
// DATA PRIVACY FIX: Admin no longer has "Super-Viewer" access.
// ==========================================
if ($type !== 'avatar') {
    // Ang GM at President ay may Executive Privilege sa lahat ng folders.
    $is_executive = in_array($role, ['GM', 'President']);
    
    if (!$is_executive) {
        $allowed = false;
        $doc_found = false;
        $doc_category = null;
        $uploader_id = null;

        // 1. Hanapin ang category at uploader via doc_id kung available
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

        // 2. Fallback: Hanapin via file_name kung tinangkang hulaan ang URL nang walang doc_id
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

        // 3. I-evaluate ang Access ng User o Admin
        if ($doc_found) {
            if (empty($doc_category)) {
                // Kung walang category, ito ay General Document (Company Files) na accessible sa lahat
                $allowed = true;
            } elseif ($uploader_id == $_SESSION['user_id']) {
                // Pinapayagan kung ang mismong user/admin ang nag-upload ng file
                $allowed = true;
            } else {
                // Kung ito ay Official Record, i-check kung explicitly authorized ang Role sa sub_category
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

        // 4. I-block kung hindi authorized (Kasama ang Admin na walang explicit access)
        if (!$allowed) {
            http_response_code(403);
            exit("Access Denied: Data Privacy Violation. You only have metadata access. You do not have permission to view or download the contents of this confidential document.");
        }
    }
}

// ==========================================
// LOGGING & FILE SERVING
// ==========================================
if ($type !== 'avatar' && isset($_SESSION['user_id']) && isset($_GET['doc_id']) && ctype_digit($_GET['doc_id'])) {
    require_once 'config/functions.php';
    log_document_action($conn, $_SESSION['user_id'], 'DOWNLOAD_DOC', intval($_GET['doc_id']), "Downloaded document: $file", $_SERVER['REQUEST_URI'] ?? null);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($filepath));

// I-inline ang display para sa mga images at PDF, ibabagsak naman as attachment ang docs, excel, atbp.
if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'])) {
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