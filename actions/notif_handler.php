<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Security token mismatch.']);
    exit();
}

$action = $_POST['action'] ?? '';
$notif_id = (int)($_POST['notif_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
ensure_user_notification_states($conn, $user_id, $role);

function get_owned_notification_state($conn, $notif_id, $user_id, $role) {
    $stmt = $conn->prepare("SELECT nus.notif_id, nus.is_read
        FROM notification_user_states nus
        INNER JOIN notifications n ON n.notif_id = nus.notif_id
        WHERE nus.notif_id = ? AND nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0");
    $stmt->bind_param("iis", $notif_id, $user_id, $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function personal_notification_update($conn, $sql, $types, array $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $ok ? $affected : false;
}

if (in_array($action, ['bulk_delete', 'bulk_read', 'bulk_pin'], true)) {
    $ids = isset($_POST['notif_ids']) ? json_decode($_POST['notif_ids'], true) : [];
    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No notifications selected.']);
        exit();
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        echo json_encode(['status' => 'error', 'message' => 'No valid notifications selected.']);
        exit();
    }

    $holders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids)) . 'is';
    $params = array_merge($ids, [$user_id, $role]);
    if ($action === 'bulk_delete') {
        $sql = "UPDATE notification_user_states nus
            INNER JOIN notifications n ON n.notif_id = nus.notif_id
            SET nus.is_deleted = 1
            WHERE nus.notif_id IN ($holders) AND nus.user_id = ? AND n.target_role = ? AND nus.is_read = 1";
    } elseif ($action === 'bulk_read') {
        $sql = "UPDATE notification_user_states nus
            INNER JOIN notifications n ON n.notif_id = nus.notif_id
            SET nus.is_read = 1, nus.read_at = COALESCE(nus.read_at, NOW())
            WHERE nus.notif_id IN ($holders) AND nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0";
    } else {
        $sql = "UPDATE notification_user_states nus
            INNER JOIN notifications n ON n.notif_id = nus.notif_id
            SET nus.is_pinned = 1
            WHERE nus.notif_id IN ($holders) AND nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0";
    }
    $affected = personal_notification_update($conn, $sql, $types, $params);
    echo json_encode($affected !== false ? ['status' => 'success'] : ['status' => 'error', 'message' => 'Notification update failed.']);
    exit();
}

if ($action === 'mark_all_read') {
    $affected = personal_notification_update($conn, "UPDATE notification_user_states nus
        INNER JOIN notifications n ON n.notif_id = nus.notif_id
        SET nus.is_read = 1, nus.read_at = COALESCE(nus.read_at, NOW())
        WHERE nus.user_id = ? AND n.target_role = ? AND nus.is_deleted = 0 AND nus.is_read = 0", "is", [$user_id, $role]);
    echo json_encode($affected !== false ? ['status' => 'success'] : ['status' => 'error']);
    exit();
}

if ($notif_id < 1 || !in_array($action, ['delete', 'mark_read', 'mark_unread', 'pin'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit();
}

$owned = get_owned_notification_state($conn, $notif_id, $user_id, $role);
if (!$owned) {
    echo json_encode(['status' => 'error', 'message' => 'Notification not found in your inbox.']);
    exit();
}

if ($action === 'delete') {
    if (!(int)$owned['is_read']) {
        echo json_encode(['status' => 'error', 'message' => 'Read the notification before removing it from your inbox.']);
        exit();
    }
    $affected = personal_notification_update($conn, "UPDATE notification_user_states SET is_deleted = 1 WHERE notif_id = ? AND user_id = ?", "ii", [$notif_id, $user_id]);
} elseif ($action === 'mark_read') {
    $affected = personal_notification_update($conn, "UPDATE notification_user_states SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE notif_id = ? AND user_id = ? AND is_deleted = 0", "ii", [$notif_id, $user_id]);
} elseif ($action === 'mark_unread') {
    $affected = personal_notification_update($conn, "UPDATE notification_user_states SET is_read = 0, read_at = NULL WHERE notif_id = ? AND user_id = ? AND is_deleted = 0", "ii", [$notif_id, $user_id]);
} else {
    $affected = personal_notification_update($conn, "UPDATE notification_user_states SET is_pinned = NOT is_pinned WHERE notif_id = ? AND user_id = ? AND is_deleted = 0", "ii", [$notif_id, $user_id]);
}

echo json_encode($affected !== false ? ['status' => 'success'] : ['status' => 'error']);
?>
