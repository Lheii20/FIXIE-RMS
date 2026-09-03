<?php
require '../config/db_connect.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthenticated']);
    exit();
}

echo json_encode(['status' => 'valid']);
?>
