<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

function phaseSkeletonRead(string $root, string $relative, array &$errors): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $errors[] = "Missing file: {$relative}";
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = "Unable to read file: {$relative}";
        return '';
    }
    return $contents;
}

function phaseSkeletonRequire(string $contents, string $marker, string $message, array &$errors): void
{
    if ($contents === '' || !str_contains($contents, $marker)) {
        $errors[] = $message;
    }
}

$css = phaseSkeletonRead($root, 'assets/css/loading-skeleton.css', $errors);
$helper = phaseSkeletonRead($root, 'assets/js/loading-skeleton.js', $errors);
$sidebar = phaseSkeletonRead($root, 'sidebar.php', $errors);
$cabinet = phaseSkeletonRead($root, 'virtual_cabinet.php', $errors);
$cabinetJs = phaseSkeletonRead($root, 'assets/js/physical-cabinet.js', $errors);
$cabinetCss = phaseSkeletonRead($root, 'assets/css/virtual-cabinet.css', $errors);
$profile = phaseSkeletonRead($root, 'includes/physical_record_profile.php', $errors);
$locations = phaseSkeletonRead($root, 'assets/js/storage-location-manager.js', $errors);
$documents = phaseSkeletonRead($root, 'documents.php', $errors);
$generalDocs = phaseSkeletonRead($root, 'general_docs.php', $errors);
$viewPo = phaseSkeletonRead($root, 'view_po.php', $errors);
$viewQuotation = phaseSkeletonRead($root, 'view_quotation.php', $errors);
$login = phaseSkeletonRead($root, 'index.php', $errors);
$recovery = phaseSkeletonRead($root, 'forgot_password.php', $errors);

phaseSkeletonRequire($css, '@keyframes drms-skeleton-sweep', 'Shared skeleton animation is missing.', $errors);
phaseSkeletonRequire($css, '.dataTables_wrapper .dataTables_processing', 'DataTables skeleton presentation is missing.', $errors);
phaseSkeletonRequire($helper, 'window.DRMSSkeleton = Object.freeze', 'Shared skeleton helper is missing.', $errors);
phaseSkeletonRequire($sidebar, 'assets/css/loading-skeleton.css', 'Authenticated pages do not load the skeleton stylesheet.', $errors);
phaseSkeletonRequire($sidebar, 'assets/js/loading-skeleton.js', 'Authenticated pages do not load the skeleton helper.', $errors);
phaseSkeletonRequire($login, 'assets/css/loading-skeleton.css', 'Login page does not load the skeleton stylesheet.', $errors);
phaseSkeletonRequire($recovery, 'assets/css/loading-skeleton.css', 'Recovery page does not load the skeleton stylesheet.', $errors);
phaseSkeletonRequire($cabinet, 'vc5-skeleton-cell', 'Virtual Cabinet initial file-list skeleton is missing.', $errors);
phaseSkeletonRequire($cabinetJs, "tableSkeleton();setInlineLoading", 'Virtual Cabinet async file-list skeleton is missing.', $errors);
phaseSkeletonRequire($cabinetJs, "treeSkeleton();", 'Virtual Cabinet directory skeleton is missing.', $errors);
phaseSkeletonRequire($cabinetCss, 'td.vc5-skeleton-cell{text-align:center!important}', 'Virtual Cabinet centered loading-cell fix is missing.', $errors);
phaseSkeletonRequire($profile, 'drms-skeleton-profile-grid', 'Physical record profile skeleton is missing.', $errors);
phaseSkeletonRequire($locations, "DRMSSkeleton.table('Loading storage locations'", 'Storage-location list skeleton is missing.', $errors);
phaseSkeletonRequire($documents, "DRMSSkeleton.lines('Loading version history')", 'Company/official record history skeleton is missing.', $errors);
phaseSkeletonRequire($generalDocs, "DRMSSkeleton.lines('Loading version history')", 'Company file history skeleton is missing.', $errors);
phaseSkeletonRequire($viewPo, "DRMSSkeleton.preview('Loading document preview')", 'PO preview skeleton is missing.', $errors);
phaseSkeletonRequire($viewQuotation, "DRMSSkeleton.preview('Loading document preview')", 'Quotation preview skeleton is missing.', $errors);

if (str_contains($cabinetJs, "tableMessage('Loading physical copies")) {
    $errors[] = 'The old Virtual Cabinet text loader is still active.';
}
if (str_contains($profile, 'vcp-loading-spinner')) {
    $errors[] = 'The old physical-profile spinner is still active.';
}

if ($errors !== []) {
    fwrite(STDERR, "UI Skeleton Loading Phase 1 verification FAILED:\n\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "UI Skeleton Loading Phase 1 verification PASSED.\n";
