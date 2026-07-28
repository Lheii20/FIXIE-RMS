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
        $return_url = $_POST['return_url'] ?? '../documents.php';
        
        if (!$can_manage && !$is_top_mgmt) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("You do not have permission to manage physical storage."));
            exit();
        }

        $doc_id = intval($_POST['doc_id']);
        $status = trim($_POST['status']); 

        if ($doc_id <= 0 || !in_array($status, ['Stored', 'Borrowed', 'Returned'])) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Invalid status parameter."));
            exit();
        }

        $stmt_check = $conn->prepare("SELECT id FROM virt_document_locations WHERE document_id = ?");
        $stmt_check->bind_param("i", $doc_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $stmt_upd = $conn->prepare("UPDATE virt_document_locations SET status = ?, last_updated = NOW() WHERE document_id = ?");
            $stmt_upd->bind_param("si", $status, $doc_id);
            $stmt_upd->execute();
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO virt_document_locations (document_id, status) VALUES (?, ?)");
            $stmt_ins->bind_param("is", $doc_id, $status);
            $stmt_ins->execute();
        }

        if (function_exists('log_audit_action')) {
            log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_DOCUMENT_STATUS', "Updated physical status of Document ID: $doc_id to '$status'");
        }

        header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Physical status updated successfully."));
        exit();
    }
} else {
    header("Location: ../documents.php");
    exit();
}