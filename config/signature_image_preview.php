<?php
declare(strict_types=1);

require_once __DIR__ . '/storage_security.php';

/** Verify the current account's optional visual signature before showing it. */
function drms_signature_image_preview_state(?array $profile): array
{
    $storedPath = trim((string) ($profile['signature_image_path'] ?? ''));
    if ($storedPath === '') {
        return ['status' => 'none'];
    }

    $expectedHash = strtolower(trim((string) ($profile['signature_image_hash'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
        return ['status' => 'unavailable'];
    }

    try {
        $file = drms_storage_resolve_existing_file($storedPath);
        $actualHash = hash_file('sha256', $file);
        if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
            return ['status' => 'unavailable'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['status' => 'unavailable'];
        }
        $size = filesize($file);
        if ($size === false || $size < 1) {
            return ['status' => 'unavailable'];
        }
        return ['status' => 'ready', 'file' => $file, 'mime' => $mime, 'size' => $size];
    } catch (Throwable $error) {
        return ['status' => 'unavailable'];
    }
}
