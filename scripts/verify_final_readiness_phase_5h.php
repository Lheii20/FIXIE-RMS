<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$failures = [];
$passed = 0;

function phase5h_check(bool $condition, string $failureMessage): void
{
    global $failures, $passed;

    if ($condition) {
        $passed++;
        return;
    }

    $failures[] = $failureMessage;
}

function phase5h_read(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $contents = file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

$cssPath = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app-header.css';
$sidebarPath = $projectRoot . DIRECTORY_SEPARATOR . 'sidebar.php';
$companyFilesPath = $projectRoot . DIRECTORY_SEPARATOR . 'general_docs.php';
$officialRecordsPath = $projectRoot . DIRECTORY_SEPARATOR . 'documents.php';

$css = phase5h_read($cssPath);
$sidebar = phase5h_read($sidebarPath);
$companyFiles = phase5h_read($companyFilesPath);
$officialRecords = phase5h_read($officialRecordsPath);

phase5h_check($css !== '', 'Missing assets/css/app-header.css.');
phase5h_check($sidebar !== '', 'Missing sidebar.php.');
phase5h_check($companyFiles !== '', 'Missing general_docs.php.');
phase5h_check($officialRecords !== '', 'Missing documents.php.');

phase5h_check(
    str_contains($sidebar, 'assets/css/app-header.css'),
    'sidebar.php does not load the canonical app-header stylesheet.'
);
phase5h_check(
    str_contains($companyFiles, 'page-general-docs'),
    'general_docs.php is missing the page-general-docs body scope.'
);
phase5h_check(
    str_contains($officialRecords, 'page-documents'),
    'documents.php is missing the page-documents body scope.'
);
phase5h_check(
    str_contains($companyFiles, 'href="official_declarations.php"'),
    'The Company Files declaration-request action is missing.'
);
phase5h_check(
    str_contains($officialRecords, 'href="official_declarations.php"'),
    'The Official Records declaration-request action is missing.'
);

$phaseMarker = 'Phase 5H: keep record-list titles readable beside mobile-only actions.';
$phaseOffset = strpos($css, $phaseMarker);
$phaseCss = $phaseOffset === false ? '' : substr($css, $phaseOffset);

phase5h_check($phaseCss !== '', 'The Phase 5H responsive-header block is missing.');
phase5h_check(
    str_contains($phaseCss, '@media screen and (max-width: 767.98px)'),
    'The Phase 5H rules are not limited to the intended mobile breakpoint.'
);
phase5h_check(
    str_contains($phaseCss, '.page-documents, .page-general-docs'),
    'The Phase 5H rules do not cover both record pages.'
);
phase5h_check(
    str_contains($phaseCss, '"record-heading"') && str_contains($phaseCss, '"record-actions"'),
    'The two-row mobile header grid is incomplete.'
);
phase5h_check(
    str_contains($phaseCss, 'grid-area: record-heading !important;'),
    'The record heading is not assigned to its own mobile row.'
);
phase5h_check(
    str_contains($phaseCss, 'grid-area: record-actions !important;'),
    'The existing record actions are not assigned to their own mobile row.'
);
phase5h_check(
    str_contains($phaseCss, 'a[href="official_declarations.php"]'),
    'The long declaration-request action does not have a mobile-safe rule.'
);
phase5h_check(
    str_contains($phaseCss, 'word-break: normal !important;'),
    'The title/subtitle word-break correction is missing.'
);
phase5h_check(
    substr_count($css, '{') === substr_count($css, '}'),
    'app-header.css has unbalanced braces.'
);
phase5h_check(
    !preg_match('/a\[href="official_declarations\.php"\][^{]*\{[^}]*display\s*:\s*none/is', $phaseCss),
    'The Phase 5H block must not hide the declaration-request action.'
);

if ($failures !== []) {
    fwrite(STDERR, "Phase 5H verification FAILED:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 5H verification PASSED ({$passed} checks)." . PHP_EOL;
echo "The Company Files and Official Records mobile headers preserve readable titles and every existing action." . PHP_EOL;

