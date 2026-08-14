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

    if ($_POST['action'] === 'replace_physical_copy') {
        $can_manage = has_permission($conn, $_SESSION['user_id'], 'can_manage_folders');
        $is_top_mgmt = has_permission($conn, $_SESSION['user_id'], 'can_view_all_folders');
        $return_url = $_POST['return_url'] ?? '../virtual_cabinet.php';
        
        if (!$can_manage && !$is_top_mgmt) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Permission denied to manage physical storage."));
            exit();
        }

        $doc_id = intval($_POST['doc_id']);

        $conn->begin_transaction();
        try {
            // Check current status and versions
            $stmt_check = $conn->prepare("SELECT d.file_name, d.current_version, d.physical_version, l.status as physical_status, d.rename_history FROM documents d LEFT JOIN virt_document_locations l ON d.doc_id = l.document_id WHERE d.doc_id = ? FOR UPDATE");
            $stmt_check->bind_param("i", $doc_id);
            $stmt_check->execute();
            $doc_data = $stmt_check->get_result()->fetch_assoc();

            if (!$doc_data) throw new Exception("Document not found.");
            if ($doc_data['physical_status'] === 'Borrowed') throw new Exception("Cannot replace physical copy while it is currently borrowed.");
            if ($doc_data['current_version'] <= $doc_data['physical_version']) throw new Exception("Physical copy is already up to date.");

            $old_v = number_format($doc_data['physical_version'] ?? 1.0, 1);
            $new_v = number_format($doc_data['current_version'], 1);

            // Update physical version to match digital
            $stmt_upd = $conn->prepare("UPDATE documents SET physical_version = current_version WHERE doc_id = ?");
            $stmt_upd->bind_param("i", $doc_id);
            $stmt_upd->execute();

            // Log the "Superseded" status securely in the JSON timeline history
            $u_stmt = $conn->query("SELECT full_name FROM users WHERE user_id = ".$_SESSION['user_id']);
            $actor = $u_stmt->fetch_assoc()['full_name'] ?? 'System';
            $history = json_decode($doc_data['rename_history'] ?? '[]', true) ?: [];
            
            array_unshift($history, [
                'type' => 'physical_replaced',
                'old_version' => $old_v,
                'new_version' => $new_v,
                'date' => date('Y-m-d H:i:s'),
                'by' => $actor
            ]);
            $history_json = json_encode($history);
            
            $stmt_hist = $conn->prepare("UPDATE documents SET rename_history = ? WHERE doc_id = ?");
            $stmt_hist->bind_param("si", $history_json, $doc_id);
            $stmt_hist->execute();

            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $_SESSION['user_id'], 'REPLACE_PHYSICAL_COPY', "Replaced physical copy of {$doc_data['file_name']}. Marked v$old_v as Superseded. Synced to v$new_v.");
            }

            $conn->commit();
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Physical copy successfully replaced and synchronized."));
            
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode($e->getMessage()));
        }
        exit();
    }
} else {
    header("Location: ../documents.php");
    exit();
}