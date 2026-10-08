<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/e_signature.php';
require_once __DIR__ . '/config/signature_image_preview.php';

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
    $preview = drms_signature_image_preview_state($profile);
    if ($preview['status'] !== 'ready') {
        throw new DomainException('The saved visual signature image is unavailable.');
    }
    header('Content-Type: ' . $preview['mime']);
    header('Content-Length: ' . (string) $preview['size']);
    header('Content-Disposition: inline; filename="electronic-signature"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($preview['file']);
} catch (Throwable $error) {
    error_log('Signature image preview failed: ' . $error->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Signature image unavailable.');
}
