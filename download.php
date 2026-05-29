<?php
require 'config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Access Denied");
}

if (!isset($_GET['file'])) {
    exit("No file specified.");
}

$file = basename($_GET['file']); 
$type = $_GET['type'] ?? 'doc'; 

if ($type === 'avatar') {
    $filepath = 'uploads/avatars/' . $file;
} else {
    $filepath = 'uploads/' . $file;
}

if (!file_exists($filepath)) {
    http_response_code(404);
    exit("File not found.");
}

if ($type !== 'avatar' && isset($_SESSION['user_id']) && isset($_GET['doc_id']) && ctype_digit($_GET['doc_id'])) {
    require_once 'config/functions.php';
    log_document_action($conn, $_SESSION['user_id'], 'DOWNLOAD_DOC', intval($_GET['doc_id']), "Downloaded document: $file", $_SERVER['REQUEST_URI'] ?? null);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($filepath));

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