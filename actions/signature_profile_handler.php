<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/e_signature.php';
require_once __DIR__ . '/../config/upload_policy.php';
require_once __DIR__ . '/../config/storage_security.php';
require_once __DIR__ . '/../config/workflow_feedback.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$redirect = '../signature_profile.php';
$newFile = null;
$oldStoredPath = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf_token'] ?? null) ||
        empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new DomainException('Your session verification expired. Reload the page and try again.');
    }
    if (!drms_signature_tables_ready($conn)) {
        throw new DomainException('Electronic signatures are unavailable until Signature Phase 1 is installed.');
    }

    $userId = (int) $_SESSION['user_id'];
    $user = drms_esign_reauthenticate($conn, $userId, (string) ($_POST['current_password'] ?? ''));
    drms_esign_assert_profile_owner($user);
    $displayName = drms_esign_text($_POST['display_name'] ?? '', 150, 'Signature display name');
    $title = drms_esign_text($_POST['signatory_title'] ?? '', 100, 'Signatory title');
    $removeImage = ($_POST['remove_signature_image'] ?? '') === '1';
    $existing = drms_esign_profile($conn, $userId);
    $oldStoredPath = $existing['signature_image_path'] ?? null;
    $imagePath = null;
    $imageHash = null;

    $hasNewImage = isset($_FILES['signature_image']) && (int) ($_FILES['signature_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    // A newly selected image always replaces the old one. The optional remove
    // checkbox is therefore ignored in that one unambiguous case.
    $removeImage = $removeImage && !$hasNewImage;
    if ($hasNewImage) {
        $validated = drms_upload_validate($conn, $_FILES['signature_image'], 'profile');
        if ((int) $validated['size'] > 2 * 1024 * 1024) {
            throw new DomainException('The electronic-signature image must not exceed 2 MB.');
        }
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('The protected signature storage could not be prepared.');
        }
        $filename = 'signature_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $validated['extension'];
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!drms_storage_move_uploaded_file((string) $validated['tmp_name'], $absolutePath)) {
            throw new RuntimeException('The signature image could not be stored securely.');
        }
        $newFile = $absolutePath;
        $imagePath = 'uploads/signatures/' . $filename;
        $imageHash = hash_file('sha256', $absolutePath);
        if ($imageHash === false) {
            throw new RuntimeException('The stored signature image could not be verified.');
        }
    }

    $conn->begin_transaction();
    try {
        drms_esign_save_profile($conn, $userId, $displayName, $title, $imagePath, $imageHash, $removeImage);
        log_audit_action($conn, $userId, 'E_SIGNATURE_PROFILE_UPDATED', 'Updated the electronic-signature profile.');
        $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    // A replaced image is removed only after its replacement was committed.
    if (($hasNewImage || $removeImage) && is_string($oldStoredPath) && $oldStoredPath !== '') {
        try {
            $oldPath = drms_storage_resolve_existing_file($oldStoredPath);
            if (preg_match('#[/\\\\]uploads[/\\\\]signatures[/\\\\]#i', $oldPath)) {
                @unlink($oldPath);
            }
        } catch (Throwable $ignored) {
            error_log('Old signature image cleanup skipped: ' . $ignored->getMessage());
        }
    }
    drms_redirect_with_feedback($redirect, 'success', 'Electronic-signature profile saved. You can now sign your assigned approval stages.');
} catch (DrmsUploadValidationException $uploadError) {
    if ($newFile !== null && is_file($newFile)) {
        @unlink($newFile);
    }
    drms_redirect_with_feedback($redirect, 'error', $uploadError->getMessage());
} catch (Throwable $error) {
    if ($newFile !== null && is_file($newFile)) {
        @unlink($newFile);
    }
    error_log('Electronic-signature profile update: ' . $error->getMessage());
    drms_redirect_with_feedback($redirect, 'error', $error instanceof DomainException ? $error->getMessage() : 'The electronic-signature profile could not be saved. Please try again.');
}
