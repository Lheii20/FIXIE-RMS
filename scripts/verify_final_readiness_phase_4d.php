<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase4dRead(string $root, string $relative, array &$failures): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
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

function phase4dMarkers(
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
    if ($missing !== []) {
        $failures[] = $file . ' is missing: ' . implode(', ', $missing);
    } else {
        $passes[] = $file . ' metadata-action safeguards are present.';
    }
}

function phase4dTableColumns(mysqli $conn, string $table): array
{
    $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) ($row['Field'] ?? '')] = $row;
        }
    } finally {
        $result->free();
    }
    return $columns;
}

$security = phase4dRead(
    $projectRoot,
    'config/record_action_security.php',
    $failures
);
phase4dMarkers(
    'config/record_action_security.php',
    $security,
    [
        'Admin record-content exclusion' => "role === 'Admin'",
        'management access rule' => "['GM', 'President']",
        'direct Editor access' => "['Viewer', 'Editor']",
        'folder-role access' => 'category_role_access',
        'Editor requirement' => 'function drms_record_action_require_editor(',
        'owner/management requirement' => 'function drms_record_action_require_owner(',
        'local redirect allowlist' => "['general_docs.php', 'documents.php']",
        'prepared actor lookup' => 'function drms_record_action_actor_name(',
    ],
    $failures,
    $passes
);

foreach (['general_docs.php', 'documents.php'] as $page) {
    $contents = phase4dRead($projectRoot, $page, $failures);
    phase4dMarkers(
        $page,
        $contents,
        [
            'shared security helper' => "config/record_action_security.php",
            'constant-time CSRF validation' => 'hash_equals(',
            'Editor metadata authorization' => 'drms_record_action_require_editor(',
            'owner sharing authorization' => 'drms_record_action_require_owner(',
            'safe local return URL' => 'drms_record_action_safe_return(',
            'database-derived Legal Hold state' => "\$current_state = (int) (\$doc_info['is_legal_hold'] ?? 0)",
            'Legal Hold concurrency guard' => "AND is_legal_hold = 0",
            'immutable Official Record rename gate' => "['Working', 'For Review']",
            'rename concurrency guard' => 'AND file_name = ?',
            'lock concurrency guard' => "AND is_locked = 0 AND record_phase IN ('Working', 'For Review')",
            'JSON history validation' => 'JSON_THROW_ON_ERROR',
        ],
        $failures,
        $passes
    );

    foreach ([
        "\$current_state = intval(\$_POST['current_state'])" => 'trusts the submitted Legal Hold state',
        "SELECT full_name FROM users WHERE user_id = \".\$_SESSION['user_id']" => 'contains the legacy concatenated actor query',
        "UPDATE documents SET file_name = ?, rename_history = ? WHERE doc_id = ?\"" => 'contains an unguarded rename update',
    ] as $forbidden => $description) {
        if ($contents !== '' && strpos($contents, $forbidden) !== false) {
            $failures[] = $page . ' ' . $description . '.';
        }
    }
}

$companyFiles = phase4dRead($projectRoot, 'general_docs.php', $failures);
phase4dMarkers(
    'general_docs.php retention handling',
    $companyFiles,
    [
        'valid retention action input' => "action_after_retention'] ?? 'Destroy'",
        'retention action allowlist' => "['Destroy', 'Permanent Archive']",
        'month range validation' => '$act_months > 11',
        'complete policy audit details' => 'active {$act_years}Y {$act_months}M',
    ],
    $failures,
    $passes
);
if (
    $companyFiles !== '' &&
    strpos($companyFiles, "\$action_after = 'Review for permanent deletion'") !== false
) {
    $failures[] = 'general_docs.php still uses a retention action that is invalid for the database enum.';
}

$phase4cHandler = phase4dRead(
    $projectRoot,
    'actions/document_handler.php',
    $failures
);
phase4dMarkers(
    'actions/document_handler.php',
    $phase4cHandler,
    [
        'Phase 4C mutation authorization' => 'function drmsDocumentMutationAccess(',
        'Converted-source deletion protection' => "['Official', 'Converted']",
        'secure permanent-delete path' => 'drms_storage_resolve_existing_file',
    ],
    $failures,
    $passes
);

try {
    require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $documentColumns = phase4dTableColumns($conn, 'documents');
    foreach ([
        'uploaded_by', 'category', 'access_type', 'file_permissions',
        'record_phase', 'is_locked', 'locked_by', 'is_legal_hold',
        'rename_history', 'official_doc_id',
    ] as $column) {
        if (!isset($documentColumns[$column])) {
            $failures[] = 'documents is missing metadata-action column: ' . $column;
        }
    }

    $policyColumns = phase4dTableColumns($conn, 'retention_policies');
    $actionType = (string) ($policyColumns['action_after_retention']['Type'] ?? '');
    foreach (['Destroy', 'Permanent Archive'] as $value) {
        if (strpos($actionType, "'{$value}'") === false) {
            $failures[] = 'retention_policies.action_after_retention is missing value: ' . $value;
        }
    }

    $categoryColumns = phase4dTableColumns($conn, 'document_categories');
    foreach (['sub_category', 'assigned_to_role', 'policy_id'] as $column) {
        if (!isset($categoryColumns[$column])) {
            $failures[] = 'document_categories is missing access/policy column: ' . $column;
        }
    }

    $roleAccessColumns = phase4dTableColumns($conn, 'category_role_access');
    foreach (['category_id', 'role_name'] as $column) {
        if (!isset($roleAccessColumns[$column])) {
            $failures[] = 'category_role_access is missing required column: ' . $column;
        }
    }

    if ($failures === []) {
        $passes[] = 'Document metadata, folder-role access, and retention-policy schema passed live read-only preflight.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live database preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 4D verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4D verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nThis verifier is read-only. It did not rename, share, lock, place a Legal Hold, or change a retention policy.\n";
