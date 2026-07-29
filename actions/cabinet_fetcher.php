<?php
// IWAS CRASH AT WARNINGS NA SUMISIRA SA JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

require '../config/db_connect.php';
require '../config/functions.php';

try {
    // Auto-create missing tables silently para hindi mag-error
    $conn->query("CREATE TABLE IF NOT EXISTS `physical_borrowing_logs` (
      `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
      `document_id` INT(11) NOT NULL,
      `action_type` ENUM('Borrowed', 'Returned') NOT NULL,
      `user_id` INT(11) NOT NULL,
      `current_holder_name` VARCHAR(100) NOT NULL,
      `expected_return_date` DATE NULL DEFAULT NULL,
      `action_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `remarks` TEXT NULL
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS `physical_movement_logs` (
      `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
      `document_id` INT(11) NOT NULL,
      `previous_path` VARCHAR(255) NOT NULL,
      `new_path` VARCHAR(255) NOT NULL,
      `moved_by` INT(11) NOT NULL,
      `moved_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `reason` TEXT NULL
    )");
} catch (Exception $e) { }

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'get_folders') {
        $drawer_id = intval($_GET['drawer_id']);
        
        $stmt = $conn->prepare("SELECT id, sub_category as name, 'Folder' as type FROM document_categories WHERE drawer_id = ? AND sub_category != '' ORDER BY sub_category ASC");
        $stmt->bind_param("i", $drawer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $folders = [];
        while ($row = $res->fetch_assoc()) {
            // Safe aggregation query that prevents crashing
            $count_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN record_phase IN ('Working', 'For Review', 'Converted') OR record_phase IS NULL THEN 1 ELSE 0 END) as working_cnt,
                    SUM(CASE WHEN record_phase = 'Official' THEN 1 ELSE 0 END) as official_cnt,
                    SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived_cnt
                FROM documents 
                WHERE category = ? AND status != 'Recycled'
            ");
            $count_stmt->bind_param("s", $row['name']);
            $count_stmt->execute();
            $count_res = $count_stmt->get_result()->fetch_assoc();
            
            $row['doc_count'] = $count_res['total'] ?? 0;
            $row['working_count'] = $count_res['working_cnt'] ?? 0;
            $row['official_count'] = $count_res['official_cnt'] ?? 0;
            $row['archived_count'] = $count_res['archived_cnt'] ?? 0;
            $folders[] = $row;
        }
        
        ob_end_clean();
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
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => 'Folder not found.']);
            exit();
        }
        $cat = $cat_res['sub_category'];

        // Safe Document Fetch
        $stmt2 = $conn->prepare("
            SELECT d.file_name, d.category, d.po_id, d.doc_id, d.status AS doc_status, 
                   d.record_phase, d.disposition_status,
                   COALESCE(l.status, 'Stored') as status, 
                   COALESCE(l.last_updated, d.uploaded_at) as last_updated
            FROM documents d 
            LEFT JOIN virt_document_locations l ON d.doc_id = l.document_id 
            WHERE d.category = ? AND d.status != 'Recycled'
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
        
        ob_end_clean();
        echo json_encode(['status' => 'success', 'data' => $documents]);
        exit();
    }

    if ($action === 'smart_search') {
        $search_term = trim($_GET['query'] ?? '');
        if (empty($search_term)) {
            ob_end_clean();
            echo json_encode(['status' => 'success', 'data' => []]);
            exit();
        }

        $like_term = "%" . $search_term . "%";
        
        $search_sql = "
            SELECT d.doc_id, d.file_name, d.record_number, d.status as lifecycle_status,
                   d.record_phase, d.disposition_status,
                   COALESCE(l.status, 'Stored') as physical_status,
                   CONCAT_WS(' > ', b.name, r.name, c.name, dr.name, cat.sub_category) as full_physical_path,
                   dr.id as target_drawer_id
            FROM documents d
            LEFT JOIN virt_document_locations l ON d.doc_id = l.document_id
            LEFT JOIN document_categories cat ON d.category = cat.sub_category
            LEFT JOIN virt_drawers dr ON cat.drawer_id = dr.id
            LEFT JOIN virt_cabinets c ON dr.cabinet_id = c.id
            LEFT JOIN virt_rooms r ON c.room_id = r.id
            LEFT JOIN virt_buildings b ON r.building_id = b.id
            LEFT JOIN users u ON d.uploaded_by = u.user_id
            WHERE d.status != 'Recycled' 
            AND (
                d.file_name LIKE ? OR 
                d.record_number LIKE ? OR 
                d.category LIKE ? OR 
                cat.classification_keywords LIKE ? OR
                u.full_name LIKE ?
            )
            ORDER BY d.uploaded_at DESC LIMIT 20
        ";
        
        $stmt = $conn->prepare($search_sql);
        $stmt->bind_param("sssss", $like_term, $like_term, $like_term, $like_term, $like_term);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $results = [];
        while ($row = $res->fetch_assoc()) {
            $results[] = $row;
        }
        
        ob_end_clean();
        echo json_encode(['status' => 'success', 'data' => $results]);
        exit();
    }

    if ($action === 'get_document_profile') {
        $doc_id = intval($_GET['doc_id']);
        
        $doc_sql = "
            SELECT d.*, u.full_name as owner_name, COALESCE(l.status, 'Stored') as physical_status,
                   CONCAT_WS(' > ', b.name, r.name, c.name, dr.name, cat.sub_category) as full_physical_path
            FROM documents d
            LEFT JOIN virt_document_locations l ON d.doc_id = l.document_id
            LEFT JOIN document_categories cat ON d.category = cat.sub_category
            LEFT JOIN virt_drawers dr ON cat.drawer_id = dr.id
            LEFT JOIN virt_cabinets c ON dr.cabinet_id = c.id
            LEFT JOIN virt_rooms r ON c.room_id = r.id
            LEFT JOIN virt_buildings b ON r.building_id = b.id
            LEFT JOIN users u ON d.uploaded_by = u.user_id
            WHERE d.doc_id = ?
        ";
        $stmt = $conn->prepare($doc_sql);
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();

        if (!$document) {
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => 'Document not found']);
            exit();
        }

        $borrow_sql = "SELECT pbl.*, u.full_name as recorded_by FROM physical_borrowing_logs pbl JOIN users u ON pbl.user_id = u.user_id WHERE pbl.document_id = ? ORDER BY pbl.action_date DESC";
        $b_stmt = $conn->prepare($borrow_sql);
        $b_stmt->bind_param("i", $doc_id);
        $b_stmt->execute();
        $borrow_logs = $b_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $move_sql = "SELECT pml.*, u.full_name as moved_by_name FROM physical_movement_logs pml JOIN users u ON pml.moved_by = u.user_id WHERE pml.document_id = ? ORDER BY pml.moved_at DESC";
        $m_stmt = $conn->prepare($move_sql);
        $m_stmt->bind_param("i", $doc_id);
        $m_stmt->execute();
        $move_logs = $m_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        ob_end_clean();
        echo json_encode([
            'status' => 'success', 
            'document' => $document,
            'borrow_history' => $borrow_logs,
            'movement_history' => $move_logs
        ]);
        exit();
    }

} catch (\Throwable $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    exit();
}

ob_end_clean();
echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
exit();