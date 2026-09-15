<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase4eRead(string $root, string $relative, array &$failures): string
{
    $path = $root . DIRECTORY_SEPARATOR
        . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        $failures[] = 'Missing required file: ' . $relative;
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = 'Could not read required file: ' . $relative;
        return '';
    }
    return $contents;
}

function phase4eMarkers(
    string $file,
    string $contents,
    array $markers,
    array &$failures,
    array &$passes
): void {
    if ($contents === '') {
        return;
    }
    $missing = [];
    foreach ($markers as $label => $marker) {
        if (strpos($contents, $marker) === false) {
            $missing[] = $label;
        }
    }
    if ($missing) {
        $failures[] = $file . ' is missing: ' . implode(', ', $missing);
    } else {
        $passes[] = $file . ' folder-action safeguards are present.';
    }
}

function phase4eColumns(mysqli $conn, string $table): array
{
    $safe = str_replace('`', '``', $table);
    $result = $conn->query('SHOW COLUMNS FROM `' . $safe . '`');
    $columns = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['Field']] = $row;
        }
    } finally {
        $result->free();
    }
    return $columns;
}

$security = phase4eRead(
    $projectRoot,
    'config/folder_action_security.php',
    $failures
);
phase4eMarkers(
    'config/folder_action_security.php',
    $security,
    [
        'folder manager permission gate' => "'can_manage_folders'",
        'Admin content exclusion' => "role === 'Admin'",
        'management visibility scope' => "['GM', 'President']",
        'folder-role access check' => 'category_role_access',
        '100-character folder limit' => 'cannot exceed 100 characters',
        'record-code validation' => "'/^[A-Z][A-Z0-9]{1,9}$/'",
        'keyword normalization' => 'function drms_folder_action_normalize_keywords(',
        'role allowlist' => 'function drms_folder_action_allowed_roles(',
        'GM assignment option' => "'GM'",
        'President assignment option' => "'President'",
        'protected-folder gate' => 'function drms_folder_action_require_mutable(',
        'retention policy existence check' => 'function drms_folder_action_require_policy(',
        'keyword conflict service' => 'function drms_folder_action_keyword_conflicts(',
        'safe JSON encoding' => 'JSON_THROW_ON_ERROR',
    ],
    $failures,
    $passes
);

$controller = phase4eRead(
    $projectRoot,
    'config/folder_action_controller.php',
    $failures
);
phase4eMarkers(
    'config/folder_action_controller.php',
    $controller,
    [
        'Company Files-only create switch' => 'bool $allowCreate',
        'Official Records create rejection' => 'Create folders from Company Files.',
        'manager authorization' => 'drms_folder_action_require_manager(',
        'assigned-folder authorization' => 'drms_folder_action_require_access(',
        'protected parent check' => 'drms_folder_action_parent_is_protected(',
        'protected folder mutation check' => 'drms_folder_action_require_mutable(',
        'global sub-folder name check' => 'Sub-folder names must be unique across the record system.',
        'policy validation' => 'drms_folder_action_require_policy(',
        'transaction start' => '$conn->begin_transaction();',
        'transaction rollback' => '$conn->rollback();',
        'guarded system-folder updates' => 'AND is_system_folder = 0',
        'prepared role cleanup' => 'DELETE FROM category_role_access WHERE category_id = ?',
        'generic production error' => 'No folder changes were saved.',
    ],
    $failures,
    $passes
);

foreach (
    [
        'general_docs.php' => [
            'page' => 'general_docs.php',
            'allow_create' => 'true',
        ],
        'documents.php' => [
            'page' => 'documents.php',
            'allow_create' => 'false',
        ],
    ] as $page => $dispatchPolicy
) {
    $contents = phase4eRead($projectRoot, $page, $failures);
    phase4eMarkers(
        $page,
        $contents,
        [
            'shared folder controller' => 'config/folder_action_controller.php',
            'secure action dispatch' => 'drms_folder_action_handle(',
            'constant-time CSRF validation' => 'hash_equals(',
            'protected folder metadata' => '$folder_metadata',
            'protected sub-folder UI gate' => '$is_system_subfolder',
            'keyword validation failure state' => "response.status === 'error'",
        ],
        $failures,
        $passes
    );
    $dispatchPattern = '~drms_folder_action_handle\s*\(\s*\$conn\s*,\s*'
        . preg_quote("'" . $dispatchPolicy['page'] . "'", '~')
        . '\s*,\s*' . $dispatchPolicy['allow_create'] . '\s*,~s';
    if (!preg_match($dispatchPattern, $contents)) {
        $failures[] = $page . ' is missing its page-specific create policy.';
    }
    $dispatchPosition = strpos($contents, 'drms_folder_action_handle(');
    $legacyPosition = strpos($contents, "\$_POST['action'] === 'get_keywords'");
    if (
        $dispatchPosition === false ||
        $legacyPosition === false ||
        $dispatchPosition > $legacyPosition
    ) {
        $failures[] = $page
            . ' does not dispatch folder actions through the secure controller first.';
    }
}

$handler = phase4eRead(
    $projectRoot,
    'actions/document_handler.php',
    $failures
);
phase4eMarkers(
    'actions/document_handler.php',
    $handler,
    [
        'shared folder security helper' => 'config/folder_action_security.php',
        'constant-time CSRF check' => 'hash_equals(',
        'keyword manager authorization' => 'drms_folder_action_require_manager(',
        'keyword folder authorization' => 'drms_folder_action_require_access(',
        'protected keyword gate' => 'drms_folder_action_require_mutable(',
        'normalized keywords' => 'drms_folder_action_normalize_keywords(',
        'escaped conflict messages' => 'ENT_QUOTES | ENT_SUBSTITUTE',
        'safe JSON response' => 'drms_folder_action_json(',
    ],
    $failures,
    $passes
);
if (strpos($handler, "\$_POST['csrf_token'] !== \$_SESSION['csrf_token']") !== false) {
    $failures[] = 'actions/document_handler.php still uses the legacy direct CSRF comparison.';
}

if ($security !== '') {
    require_once $securityPath = $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'folder_action_security.php';
    try {
        if (drms_folder_action_normalize_name('  Client   Contracts  ', 'Folder') !== 'Client Contracts') {
            $failures[] = 'Folder name normalization regression check failed.';
        }
        if (drms_folder_action_normalize_prefix(' inv ') !== 'INV') {
            $failures[] = 'Record Code normalization regression check failed.';
        }
        if (drms_folder_action_normalize_keywords('invoice, Invoice, billing') !== 'invoice, billing') {
            $failures[] = 'Keyword de-duplication regression check failed.';
        }
        $roles = drms_folder_action_normalize_roles(['GM', 'President', 'GM']);
        if ($roles !== ['GM', 'President']) {
            $failures[] = 'Folder role normalization regression check failed.';
        }
        foreach (
            [
                fn () => drms_folder_action_normalize_name('../Finance', 'Folder'),
                fn () => drms_folder_action_normalize_prefix('1BAD'),
                fn () => drms_folder_action_normalize_roles(['Admin']),
            ] as $invalidCase
        ) {
            try {
                $invalidCase();
                $failures[] = 'An invalid folder metadata regression case was accepted.';
            } catch (DomainException) {
                // Expected.
            }
        }
        $passes[] = 'Folder name, code, keyword, and role validation regression checks passed.';
    } catch (Throwable $error) {
        $failures[] = 'Folder metadata regression checks failed: ' . $error->getMessage();
    }
}

try {
    require $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $categoryColumns = phase4eColumns($conn, 'document_categories');
    foreach (
        [
            'parent_category', 'sub_category', 'classification_keywords',
            'assigned_to_role', 'record_prefix', 'system_folder_key',
            'is_system_folder', 'policy_id',
        ] as $column
    ) {
        if (!isset($categoryColumns[$column])) {
            $failures[] = 'document_categories is missing column: ' . $column;
        }
    }
    $roleColumns = phase4eColumns($conn, 'category_role_access');
    foreach (['category_id', 'role_name'] as $column) {
        if (!isset($roleColumns[$column])) {
            $failures[] = 'category_role_access is missing column: ' . $column;
        }
    }

    $duplicateResult = $conn->query(
        "SELECT sub_category FROM document_categories "
        . "WHERE TRIM(sub_category) <> '' GROUP BY sub_category "
        . 'HAVING COUNT(*) > 1 LIMIT 1'
    );
    if ($duplicateResult->num_rows > 0) {
        $failures[] = 'Existing sub-folder names are ambiguous across Parent Folders.';
    }
    $duplicateResult->free();

    $systemResult = $conn->query(
        'SELECT COUNT(*) AS total, '
        . 'SUM(CASE WHEN record_prefix IS NULL OR TRIM(record_prefix) = \'\' '
        . 'OR policy_id IS NULL THEN 1 ELSE 0 END) AS invalid_total '
        . 'FROM document_categories WHERE is_system_folder = 1'
    );
    $system = $systemResult->fetch_assoc();
    $systemResult->free();
    if ((int) ($system['total'] ?? 0) < 1) {
        $failures[] = 'No protected workflow folders were found.';
    }
    if ((int) ($system['invalid_total'] ?? 0) > 0) {
        $failures[] = 'A protected workflow folder is missing its Record Code or retention policy.';
    }

    if (!$failures) {
        $passes[] = 'Folder, role-access, retention, and protected-folder schema passed live read-only preflight.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live database preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4E verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4E verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nThis verifier is read-only. It did not create, edit, delete, or reassign any folder or keyword.\n";
