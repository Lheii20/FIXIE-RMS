<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ob_start();
require __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/storage_locations.php';

function drms_storage_response(int $status, array $body): void
{
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
try {
    $actor = (int)($_SESSION['user_id'] ?? 0);
    if ($actor < 1) { throw new DrmsStorageError('Sign in again before managing locations.', 401); }
    drms_storage_authorize($conn, $actor);
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        drms_storage_ready($conn);
        drms_storage_response(200, ['ok'=>true, 'nodes'=>array_values(drms_storage_snapshot($conn))]);
    }
    if ($method !== 'POST') { throw new DrmsStorageError('Use GET to view or POST to change locations.', 405); }
    drms_storage_csrf($_POST['csrf_token'] ?? null, $_SESSION['csrf_token'] ?? null);
    $result = drms_storage_mutate($conn, $actor, $_POST, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    drms_storage_response(200, ['ok'=>true] + $result);
} catch (DrmsStorageError $error) {
    drms_storage_response($error->getCode() ?: 400, ['ok'=>false, 'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Storage location request failed: ' . $error->getMessage());
    drms_storage_response(500, ['ok'=>false, 'message'=>'The location could not be processed. No incomplete location change was saved. Refresh and try again; ask the administrator to check the error log if it continues.']);
}
