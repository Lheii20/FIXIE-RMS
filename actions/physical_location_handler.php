<?php
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }

    if ($_POST['action'] === 'update_location') {
        $can_manage = has_permission($conn, $_SESSION['user_id'], 'can_manage_folders');
        $is_top_mgmt = has_permission($conn, $_SESSION['user_id'], 'can_view_all_folders');
        $return_url = $_POST['return_url'] ?? '../virtual_cabinet.php';
        
        if (!$can_manage && !$is_top_mgmt) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Permission denied to manage physical storage."));
            exit();
        }

        $doc_id = intval($_POST['doc_id']);
        $status = trim($_POST['status']); 
        $current_holder = trim($_POST['current_holder'] ?? '');
        $expected_return = !empty($_POST['expected_return']) ? $_POST['expected_return'] : null;
        $remarks = trim($_POST['remarks'] ?? '');

        if ($doc_id <= 0 || !in_array($status, ['Stored', 'Borrowed', 'Returned'])) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Invalid parameters."));
            exit();
        }

        $conn->begin_transaction();

        try {
            // Smart Status Conversion: Kung "Returned" ang action, i-save as "Stored" ang final location
            $final_status = ($status === 'Returned') ? 'Stored' : $status;

            // Update physical location state
            $stmt_check = $conn->prepare("SELECT id FROM virt_document_locations WHERE document_id = ?");
            $stmt_check->bind_param("i", $doc_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $stmt_upd = $conn->prepare("UPDATE virt_document_locations SET status = ?, last_updated = NOW() WHERE document_id = ?");
                $stmt_upd->bind_param("si", $final_status, $doc_id);
                $stmt_upd->execute();
            } else {
                $stmt_ins = $conn->prepare("INSERT INTO virt_document_locations (document_id, status) VALUES (?, ?)");
                $stmt_ins->bind_param("is", $doc_id, $final_status);
                $stmt_ins->execute();
            }

            // Write to Enterprise Borrowing Log
            if ($status === 'Borrowed' || $status === 'Returned') {
                $log_stmt = $conn->prepare("INSERT INTO physical_borrowing_logs (document_id, action_type, user_id, current_holder_name, expected_return_date, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                $log_stmt->bind_param("isisss", $doc_id, $status, $_SESSION['user_id'], $current_holder, $expected_return, $remarks);
                $log_stmt->execute();
            }

            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_PHYSICAL_STATUS', "Physical status of Doc $doc_id changed to '$status'");
            }

            $conn->commit();
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Physical status and logs updated."));
            
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Failed to update status."));
        }
        exit();
    }
} else {
    header("Location: ../documents.php");
    exit();
}