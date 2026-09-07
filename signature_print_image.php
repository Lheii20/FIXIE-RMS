<?php

declare(strict_types=1);

/**
 * Protected visual signature renderer for approved workflow print and review layouts.
 *
 * The signature snapshot belongs to the approval event, not the signer's
 * current profile. This preserves the visual evidence that existed when the
 * approval was made while keeping the uploaded image outside public storage.
 */
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/workflow_access.php';
require_once __DIR__ . '/config/storage_security.php';

if (empty($_SESSION['user_id']) || !drms_user_has_workflow_role([
    'Sales Staff',
    'Procurement',
    'GM',
    'President',
    'Finance',
])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Access denied.');
}

try {
    $signatureId = (int) ($_GET['id'] ?? 0);
    if ($signatureId < 1) {
        throw new DomainException('The requested signature is invalid.');
    }

    $statement = $conn->prepare(
        "SELECT signature_image_path, signature_image_hash
         FROM document_signature_events
         WHERE signature_id = ?
           AND record_module IN ('PRF', 'PO', 'Client PO', 'General Document')
           AND signature_type = 'Electronic Approval'
           AND signature_status = 'Valid'
         LIMIT 1"
    );
    $statement->bind_param('i', $signatureId);
    $statement->execute();
    $signature = $statement->get_result()->fetch_assoc();
    $statement->close();

    $storedPath = trim((string) ($signature['signature_image_path'] ?? ''));
    $expectedHash = strtolower(trim((string) ($signature['signature_image_hash'] ?? '')));
    if ($storedPath === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        throw new DomainException('No visual signature is available.');
    }

    $file = drms_storage_resolve_existing_file($storedPath);
    $actualHash = hash_file('sha256', $file);
    if ($actualHash === false || !hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Signature image integrity check failed.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Signature image type is invalid.');
    }

    $size = filesize($file);
    if ($size === false || $size < 1) {
        throw new RuntimeException('Signature image is unavailable.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) $size);
    header('Content-Disposition: inline; filename="prf-electronic-signature"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($file);
} catch (Throwable $error) {
    error_log('Workflow print signature image unavailable: ' . $error->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Signature image unavailable.');
}
