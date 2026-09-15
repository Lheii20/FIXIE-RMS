<?php

declare(strict_types=1);

require_once 'config/db_connect.php';
require_once 'config/functions.php';
require_once 'config/workflow_access.php';
require_once 'config/e_signature.php';

drms_require_login();

$userId = (int) $_SESSION['user_id'];
$profileUser = drms_esign_user($conn, $userId);
$isSignatory = drms_esign_profile_role_allowed((string) $profileUser['role']);
$profile = $isSignatory ? drms_esign_profile($conn, $userId) : null;
$messageTone = isset($_GET['success']) ? 'success' : (isset($_GET['error']) ? 'error' : '');
$message = trim((string) ($_GET[$messageTone] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Electronic Signature - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/e-signature.css?v=<?php echo filemtime(__DIR__ . '/assets/css/e-signature.css'); ?>">
</head>
<body class="page-settings">
<?php include 'sidebar.php'; ?>
<main class="main-content fade-in settings-main">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <a href="settings.php" class="small text-decoration-none text-muted"><i class="fas fa-arrow-left me-1"></i> Account settings</a>
            <h2 class="fw-bold mt-2 mb-1">Electronic signature</h2>
            <p class="text-muted mb-0">Set the identity used when your assigned approval stages are electronically signed.</p>
        </div>
        <span class="badge rounded-pill <?php echo $profile && $profile['profile_status'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary'; ?> px-3 py-2">
            <?php echo $profile && $profile['profile_status'] === 'Active' ? 'Profile active' : 'Setup required'; ?>
        </span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo $messageTone === 'success' ? 'success' : 'danger'; ?> border-0 shadow-sm" data-drms-toast="<?php echo htmlspecialchars($messageTone, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fas fa-<?php echo $messageTone === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!$isSignatory): ?>
        <section class="drms-esign-card">
            <div class="drms-esign-card__head"><span class="drms-esign-card__icon"><i class="fas fa-shield-alt"></i></span><div><span class="drms-esign-card__eyebrow">Role-controlled access</span><h1>Electronic signatures are not assigned to this account</h1></div></div>
            <div class="drms-esign-card__body"><p class="drms-esign-help mb-0">Only GM, Finance, President, and Supply Chain roles assigned to controlled approval stages can set up an electronic signature. This restriction does not affect your existing account settings.</p></div>
        </section>
    <?php else: ?>
        <section class="drms-esign-card">
            <div class="drms-esign-card__head"><span class="drms-esign-card__icon"><i class="fas fa-signature"></i></span><div><span class="drms-esign-card__eyebrow">Controlled signatory identity</span><h1>Set up your signing profile</h1></div></div>
            <div class="drms-esign-card__body">
                <p class="drms-esign-help">Your profile records a stable display name and title. The optional image is the visual representation saved with future signature events; every approval still requires your active signed-in account and explicit consent.</p>
                <div class="drms-esign-facts">
                    <div class="drms-esign-fact"><small>Account role</small><strong><?php echo htmlspecialchars((string) $profileUser['role'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="drms-esign-fact"><small>Assigned use</small><strong><?php
                        echo match ((string) $profileUser['role']) {
                            'GM' => 'GM review',
                            'Finance' => 'Finance review',
                            'President' => 'Final approval',
                            'Supply Chain' => 'Logistics approval',
                            default => 'Assigned approval',
                        };
                    ?></strong></div>
                    <div class="drms-esign-fact"><small>Profile status</small><strong><?php echo $profile ? htmlspecialchars((string) $profile['profile_status'], ENT_QUOTES, 'UTF-8') : 'Not configured'; ?></strong></div>
                </div>
                <form action="actions/signature_profile_handler.php" method="post" enctype="multipart/form-data" data-esign-profile-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Signature display name</label><input class="form-control" name="display_name" maxlength="150" required value="<?php echo htmlspecialchars((string) ($profile['display_name'] ?? $profileUser['full_name']), ENT_QUOTES, 'UTF-8'); ?>"><div class="form-text">Shown in the electronic-signature audit record.</div></div>
                        <div class="col-md-6"><label class="form-label">Signatory title</label><input class="form-control" name="signatory_title" maxlength="100" required value="<?php echo htmlspecialchars((string) ($profile['signatory_title'] ?? $profileUser['role']), ENT_QUOTES, 'UTF-8'); ?>"><div class="form-text">Example: General Manager, Finance Head, President, or Supply Chain Reviewer.</div></div>
                        <div class="col-md-7"><label class="form-label">Signature image <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" type="file" name="signature_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG, or WebP only; maximum 2 MB. It is stored in protected system storage.</div><?php if (!empty($profile['signature_image_path'])): ?><label class="drms-esign-choice mt-3"><input type="checkbox" name="remove_signature_image" value="1"><span>Remove the current visual signature image. Password verification is still required to save this change.</span></label><?php endif; ?></div>
                        <div class="col-md-5"><label class="form-label">Current visual signature</label><div class="drms-esign-image"><?php if (!empty($profile['signature_image_path'])): ?><img src="signature_image.php" alt="Current electronic signature image"><?php else: ?><span class="drms-esign-image__empty"><i class="far fa-image me-1"></i>No image uploaded</span><?php endif; ?></div></div>
                        <div class="col-12"><label class="form-label">Current account password</label><input class="form-control" type="password" name="current_password" maxlength="128" autocomplete="current-password" required placeholder="Required to save your signature profile"><div class="form-text">This confirms that the profile is being maintained by the authorized account holder.</div></div>
                    </div>
                    <div class="drms-esign-action"><a class="btn btn-light border" href="settings.php">Cancel</a><button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save signature profile</button></div>
                </form>
            </div>
        </section>
    <?php endif; ?>
</main>
<script src="assets/js/e-signature.js?v=<?php echo filemtime(__DIR__ . '/assets/js/e-signature.js'); ?>"></script>
<script>document.querySelector('[data-esign-profile-form]')?.addEventListener('submit',async function(event){event.preventDefault();const form=this;if(!form.display_name.value.trim()||!form.signatory_title.value.trim()||!form.current_password.value){window.DRMSFeedback?.toast('Complete the signature name, title, and current password.','danger',3000);return}if(window.DRMSFeedback){const ok=await window.DRMSFeedback.confirm({title:'Save electronic-signature profile?',message:'This updates the controlled identity used for your future approval signatures.',confirmText:'Save profile',tone:'warning'});if(!ok)return}form.submit()})</script>
</body>
</html>
