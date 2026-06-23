<?php
session_start();
require '../config/db_connect.php';

header('Content-Type: application/json');

// STRICT SECURITY
if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'Security token mismatch.']);
    exit();
}

$action = $_POST['action'] ?? '';
$notif_id = isset($_POST['notif_id']) ? intval($_POST['notif_id']) : 0;
$role = $_SESSION['role'];

// Handle Bulk Actions
if (in_array($action, ['bulk_delete', 'bulk_read', 'bulk_pin'])) {
    $ids = isset($_POST['notif_ids']) ? json_decode($_POST['notif_ids'], true) : [];
    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No notifications selected.']);
        exit();
    }

    $clean_ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($clean_ids), '?'));
    $types = str_repeat('i', count($clean_ids)) . 's';
    $params = $clean_ids;
    $params[] = $role;

    if ($action === 'bulk_delete') {
        // Naka lock: Ang nabasa (is_read = 1) lang ang pwedeng ma-delete.
        $sql = "DELETE FROM notifications WHERE notif_id IN ($placeholders) AND target_role = ? AND is_read = 1";
    } elseif ($action === 'bulk_read') {
        $sql = "UPDATE notifications SET is_read = 1 WHERE notif_id IN ($placeholders) AND target_role = ?";
    } elseif ($action === 'bulk_pin') {
        $sql = "UPDATE notifications SET is_pinned = 1 WHERE notif_id IN ($placeholders) AND target_role = ?";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
    exit();
}

// Handle Single Actions
if ($action === 'delete' && $notif_id > 0) {
    // Check sa backend, hindi allowed magdelete kapag unread (is_read = 0)
    $stmt = $conn->prepare("DELETE FROM notifications WHERE notif_id = ? AND target_role = ? AND is_read = 1");
    $stmt->bind_param("is", $notif_id, $role);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete unread notification. Paki-read muna.']);
    }
    $stmt->close();
} 
elseif ($action === 'mark_read' && $notif_id > 0) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND target_role = ?");
    $stmt->bind_param("is", $notif_id, $role);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
}
elseif ($action === 'pin' && $notif_id > 0) {
    $stmt = $conn->prepare("UPDATE notifications SET is_pinned = NOT is_pinned WHERE notif_id = ? AND target_role = ?");
    $stmt->bind_param("is", $notif_id, $role);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
}
elseif ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE target_role = ? AND is_read = 0");
    $stmt->bind_param("s", $role);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
} 
else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
}
?>