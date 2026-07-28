<?php
require '../config/db_connect.php';
require '../config/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'get_folders') {
    $drawer_id = intval($_GET['drawer_id']);
    
    $stmt = $conn->prepare("SELECT id, sub_category as name, 'Folder' as type FROM document_categories WHERE drawer_id = ? AND sub_category != '' ORDER BY sub_category ASC");
    $stmt->bind_param("i", $drawer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $folders = [];
    while ($row = $res->fetch_assoc()) {
        $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE category = ? AND status = 'Active'");
        $count_stmt->bind_param("s", $row['name']);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result()->fetch_assoc();
        
        $row['doc_count'] = $count_res['total'];
        $folders[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $folders]);
    exit();
}

if ($action === 'get_documents') {
    $folder_id = intval($_GET['folder_id']);
    
    $stmt = $conn->prepare("SELECT sub_category FROM document_categories WHERE id = ?");
    $stmt->bind_param("i", $folder_id);
    $stmt->execute();
    $cat_res = $stmt->get_result()->fetch_assoc();
    
    if(!$cat_res) {
        echo json_encode(['status' => 'error', 'message' => 'Folder not found.']);
        exit();
    }
    $cat = $cat_res['sub_category'];

    $stmt2 = $conn->prepare("
        SELECT d.file_name, d.category, d.po_id, d.doc_id, 
               COALESCE(l.status, 'Stored') as status, 
               COALESCE(l.last_updated, d.uploaded_at) as last_updated
        FROM documents d 
        LEFT JOIN virt_document_locations l ON d.doc_id = l.document_id 
        WHERE d.category = ? AND d.status = 'Active'
        ORDER BY last_updated DESC
    ");
    $stmt2->bind_param("s", $cat);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    
    $documents = [];
    while ($row = $res2->fetch_assoc()) {
        $row['last_updated_formatted'] = date('M d, Y h:i A', strtotime($row['last_updated']));
        $documents[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $documents]);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
exit();