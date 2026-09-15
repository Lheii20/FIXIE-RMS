<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$failures = [];
$passed = 0;

function phase5i_read(string $projectRoot, string $relativePath): string
{
    $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        return '';
    }

    $contents = file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

function phase5i_check(bool $condition, string $message): void
{
    global $failures, $passed;

    if ($condition) {
        $passed++;
        return;
    }

    $failures[] = $message;
}

$files = [
    'sidebar.php',
    'quotations_list.php',
    'pr_list.php',
    'po_list.php',
    'general_docs.php',
    'settings.php',
    'notifications.php',
    'assets/css/app-control.css',
];

$contents = [];
foreach ($files as $file) {
    $contents[$file] = phase5i_read($projectRoot, $file);
    phase5i_check($contents[$file] !== '', "Missing required Phase 5I file: {$file}");
}

$sidebar = $contents['sidebar.php'];
phase5i_check(str_contains($sidebar, 'id="commandPaletteTrigger"'), 'The global search trigger is missing its stable ID.');
phase5i_check(str_contains($sidebar, 'aria-label="Search or jump to a page"'), 'The global search trigger has no accessible name.');
phase5i_check(str_contains($sidebar, 'aria-haspopup="dialog"'), 'The global search trigger does not expose its dialog relationship.');
phase5i_check(str_contains($sidebar, 'aria-controls="commandPaletteOverlay"'), 'The global search trigger does not identify the command palette.');
phase5i_check(str_contains($sidebar, 'aria-keyshortcuts="Control+K"'), 'The global search keyboard shortcut is not exposed.');
phase5i_check(str_contains($sidebar, 'role="dialog" aria-modal="true"'), 'The command palette is not exposed as a modal dialog.');
phase5i_check(str_contains($sidebar, 'aria-label="Search and navigation"'), 'The command palette dialog has no accessible name.');
phase5i_check(str_contains($sidebar, 'aria-label="Search commands and pages"'), 'The command-palette input has no accessible name.');
phase5i_check(str_contains($sidebar, 'aria-label="Close search and navigation"'), 'The command-palette close control has no accessible name.');
phase5i_check(str_contains($sidebar, "cpOverlay.classList.add('show')"), 'Opening the command palette does not activate its visible state.');
phase5i_check(str_contains($sidebar, "cpOverlay.classList.remove('show')"), 'Closing the command palette does not clear its visible state.');
phase5i_check(str_contains($sidebar, "cpTrigger.setAttribute('aria-expanded', 'true')"), 'Opening the command palette does not update aria-expanded.');
phase5i_check(str_contains($sidebar, "cpTrigger.setAttribute('aria-expanded', 'false')"), 'Closing the command palette does not reset aria-expanded.');
phase5i_check(str_contains($sidebar, 'cpTrigger.focus();'), 'Keyboard focus is not returned after the command palette closes.');

$filterLabels = [
    'quotations_list.php' => 'aria-label="Filter quotations by status"',
    'pr_list.php' => 'aria-label="Filter purchase requests by status"',
    'po_list.php' => 'aria-label="Filter purchase orders by status"',
];
foreach ($filterLabels as $file => $marker) {
    phase5i_check(str_contains($contents[$file], $marker), "The status filter in {$file} has no accessible name.");
}

$generalDocs = $contents['general_docs.php'];
phase5i_check(str_contains($generalDocs, 'aria-label="More actions for folder '), 'Company Files folder menus have no contextual accessible name.');
phase5i_check(str_contains($generalDocs, 'aria-haspopup="menu"'), 'Company Files folder action buttons do not expose their menu relationship.');
phase5i_check(str_contains($generalDocs, 'fa-ellipsis-v" aria-hidden="true"'), 'The decorative Company Files menu icon is exposed unnecessarily.');

$notifications = $contents['notifications.php'];
phase5i_check(str_contains($notifications, 'role="group" aria-label="Filter notifications"'), 'Notification filters are not exposed as one named group.');
phase5i_check(str_contains($notifications, 'id="filter-all" aria-pressed="true"'), 'The initial notification-filter state is missing.');
phase5i_check(str_contains($notifications, "btn.setAttribute('aria-pressed', 'false')"), 'Inactive notification filters do not reset aria-pressed.');
phase5i_check(str_contains($notifications, "activeBtn.setAttribute('aria-pressed', 'true')"), 'The active notification filter does not update aria-pressed.');

$settings = $contents['settings.php'];
$labelledSettingsControls = [
    'settingsVerificationCode',
    'profileFullName',
    'profileEmail',
    'currPass',
    'newPass',
    'confirmNewPass',
    'desiredUsername',
    'usernameChangeReason',
    'reqCurrPass',
];
foreach ($labelledSettingsControls as $controlId) {
    phase5i_check(
        str_contains($settings, 'for="' . $controlId . '"') && str_contains($settings, 'id="' . $controlId . '"'),
        "Settings label association is incomplete for {$controlId}."
    );
}

$passwordControls = ['currPass', 'newPass', 'confirmNewPass', 'reqCurrPass'];
foreach ($passwordControls as $controlId) {
    phase5i_check(
        str_contains($settings, 'aria-controls="' . $controlId . '"'),
        "The password visibility button does not identify {$controlId}."
    );
}
phase5i_check(str_contains($settings, 'function togglePass(inputId, iconId, toggleButton)'), 'The accessible password-toggle function is missing.');
phase5i_check(str_contains($settings, 'toggleButton.setAttribute("aria-pressed", isVisible ? "true" : "false")'), 'Password visibility state is not exposed through aria-pressed.');
phase5i_check(str_contains($settings, 'toggleButton.setAttribute("aria-label", (isVisible ? "Hide " : "Show ") + fieldLabel)'), 'Password visibility labels do not update dynamically.');
phase5i_check(substr_count($settings, 'aria-hidden="true"></i>') >= 4, 'One or more password visibility icons are exposed as duplicate control names.');

$appControl = $contents['assets/css/app-control.css'];
phase5i_check(str_contains($appControl, 'Phase 5I: visible keyboard focus for global search controls.'), 'The Phase 5I keyboard-focus style block is missing.');
phase5i_check(str_contains($appControl, '.saas-search-trigger:focus-visible'), 'The global search trigger has no visible keyboard focus rule.');
phase5i_check(str_contains($appControl, '.cp-header .cp-esc:focus-visible'), 'The command-palette close button has no visible keyboard focus rule.');
phase5i_check(substr_count($appControl, '{') === substr_count($appControl, '}'), 'assets/css/app-control.css has unbalanced braces.');

if ($failures !== []) {
    fwrite(STDERR, "Phase 5I verification FAILED:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 5I verification PASSED ({$passed} checks)." . PHP_EOL;
echo "Search, filters, folder actions, notification state, and Settings controls expose consistent keyboard and accessibility behavior." . PHP_EOL;

