<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/e_signature.php';
require_once __DIR__ . '/config/storage_security.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

try {
    $requestedUserId = (int) ($_GET['user_id'] ?? $_SESSION['user_id']);
    $currentUserId = (int) $_SESSION['user_id'];
    if ($requestedUserId < 1 || $requestedUserId !== $currentUserId) {
        throw new DomainException('You may only view your own signature image.');
    }
    $profile = drms_esign_profile($conn, $requestedUserId);
    if (!$profile || empty($profile['signature_image_path'])) {
        throw new DomainException('No signature image is available.');
    }
    $file = drms_storage_resolve_existing_file((string) $profile['signature_image_path']);
    $hash = hash_file('sha256', $file);
    if ($hash === false || !hash_equals((string) $profile['signature_image_hash'], $hash)) {
        throw new RuntimeException('Signature image integrity check failed.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Signature image type is invalid.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($file));
    header('Content-Disposition: inline; filename="electronic-signature"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($file);
} catch (Throwable $error) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Signature image unavailable.');
}
