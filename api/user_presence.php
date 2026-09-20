<?php
declare(strict_types=1);

require __DIR__ . '/../config/db_connect.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit();
}

$result = $conn->query(
    "SELECT u.user_id, u.status,
            EXISTS (
                SELECT 1 FROM user_sessions s
                WHERE s.user_id = u.user_id
                  AND s.ended_at IS NULL
                  AND BINARY s.auth_token_hash = BINARY SHA2(u.session_token, 256)
                  AND s.last_seen_at >= NOW() - INTERVAL 75 SECOND
            ) AS is_online
     FROM users u"
);
$presence = [];
while ($row = $result->fetch_assoc()) {
    $presence[(string) $row['user_id']] =
        $row['status'] === 'Active' && (int) $row['is_online'] === 1;
}
echo json_encode(['status' => 'valid', 'presence' => $presence]);
