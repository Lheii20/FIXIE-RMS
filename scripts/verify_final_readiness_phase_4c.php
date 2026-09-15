<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase4cRead(string $root, string $relative, array &$failures): string
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

function phase4cMarkers(
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
        $passes[] = $file . ' lifecycle safeguards are present.';
    }
}

function phase4cTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) === 1;
}

function phase4cColumns(mysqli $conn, string $table): array
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

function phase4cIndexes(mysqli $conn, string $table): array
{
    $result = $conn->query('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');
    $indexes = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $name = (string) ($row['Key_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $sequence < 1) {
                continue;
            }
            $indexes[$name]['unique'] = (int) ($row['Non_unique'] ?? 1) === 0;
            $indexes[$name]['columns'][$sequence] = (string) ($row['Column_name'] ?? '');
        }
    } finally {
        $result->free();
    }
    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return $indexes;
}

function phase4cHasIndex(array $indexes, array $columns, bool $unique): bool
{
    foreach ($indexes as $index) {
        if (
            ($index['unique'] ?? false) === $unique &&
            ($index['columns'] ?? []) === $columns
        ) {
            return true;
        }
    }
    return false;
}

$checks = [
    'actions/document_handler.php' => [
        'POST and CSRF enforcement' => "REQUEST_METHOD'] == 'POST'",
        'central mutation authorization' => 'function drmsDocumentMutationAccess(',
        'Admin record-content exclusion' => "role === 'Admin'",
        'Converted-source protection' => "['Official', 'Converted']",
        'legal-hold deletion protection' => "is_legal_hold = 0",
        'working-record deletion scope' => "record_phase IN ('Working', 'For Review')",
        'protected storage resolver' => 'drms_storage_resolve_existing_file',
        'version-binary cleanup' => 'SELECT file_path FROM document_versions WHERE doc_id = ?',
        'transactional permanent deletion' => '$conn->begin_transaction();',
        'GM declaration request gate' => 'drms_declaration_assert_reviewer',
        'Official source conversion' => "record_phase = 'Converted'",
    ],
    'actions/version_handler.php' => [
        'Editor access guard' => "access['level'] !== 'Editor'",
        'working-only version upload' => "['Working', 'For Review']",
        'transactional version update' => '$conn->begin_transaction();',
        'pre-official history marker' => '[Pre-official working version]',
        'source-to-official lineage' => 'source_document.official_doc_id = current_document.doc_id',
    ],
    'config/official_declarations.php' => [
        'GM-only declaration reviewer' => 'Only the General Manager can review and approve Official Record declaration requests.',
        'source hash verification' => 'drms_declaration_assert_unchanged',
        'Official declaration signature event' => "'signature_stage' => 'Official Declaration'",
        'approved request linkage' => 'official_document_id = ?',
    ],
    'official_declarations.php' => [
        'GM-only queue' => 'Official Record declaration requests are available only to the General Manager.',
        'electronic-signature trigger' => 'data-declaration-sign',
        'secure document viewer' => 'download.php?type=document',
    ],
    'download.php' => [
        'authenticated access gate' => "empty(\$_SESSION['user_id'])",
        'Admin content exclusion' => "if (\$role === 'Admin')",
        'document-level authorization' => 'drms_document_is_accessible',
        'destroyed-record block' => "disposition_status'] ?? '') === 'Destroyed'",
        'protected path resolver' => 'drms_storage_resolve_existing_file',
        'no-store response' => "Cache-Control: private, no-store",
    ],
    'actions/disposition_handler.php' => [
        'POST-only guard' => "REQUEST_METHOD'] !== 'POST'",
        'CSRF guard' => 'hash_equals',
        'Official-only disposition' => "record_phase'] !== 'Official'",
        'legal-hold guard' => "is_legal_hold",
        'file-integrity verification' => 'hash_file',
        'destruction certificate' => 'destruction_certificates',
        'transactional disposition' => '$conn->begin_transaction();',
    ],
    'config/physical_records.php' => [
        'Admin physical-record exclusion' => "role<>'Admin'",
        'document-level physical access' => 'function drms_copy_access(',
        'single physical-copy registration' => 'uq_vc3_document_copy',
        'optimistic revision guard' => 'hash_equals($doc[\'revision\']',
        'transactional physical action' => '$conn->begin_transaction();',
        'separate physical disposal evidence' => 'physical_disposition_logs',
    ],
    'documents.php' => [
        'Official-only listing' => "d.record_phase = 'Official'",
        'Official filing date' => 'COALESCE(d.declared_at, d.uploaded_at) AS official_filed_at',
        'declaration-based retention date' => '$retention_base_sql = "COALESCE(d.declared_at, d.uploaded_at)"',
        'destroyed binary exclusion' => "disposition_status, '') <> 'Destroyed'",
    ],
    'general_docs.php' => [
        'working-only listing' => "d.record_phase = 'Working' OR d.record_phase = 'For Review'",
        'canonical disposition redirect' => "header('Location: documents.php?disposition=1')",
    ],
];

foreach ($checks as $file => $markers) {
    $contents = phase4cRead($projectRoot, $file, $failures);
    phase4cMarkers($file, $contents, $markers, $failures, $passes);
}

$documentHandler = phase4cRead($projectRoot, 'actions/document_handler.php', $failures);
if (
    $documentHandler !== '' &&
    strpos($documentHandler, "disposition_status = 'Archived'") !== false
) {
    $failures[] = 'actions/document_handler.php still writes the invalid legacy disposition_status value Archived.';
}
if (
    $documentHandler !== '' &&
    strpos($documentHandler, "['Admin', 'President', 'GM']") !== false
) {
    $failures[] = 'actions/document_handler.php still grants System Admin permanent record deletion access.';
}

try {
    require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $requiredTables = [
        'documents',
        'document_versions',
        'official_declaration_requests',
        'document_signature_events',
        'document_categories',
        'official_record_sequences',
        'retention_policies',
        'disposition_requests',
        'destruction_certificates',
        'virt_document_locations',
        'physical_borrowing_logs',
        'physical_movement_logs',
        'physical_disposition_logs',
    ];
    foreach ($requiredTables as $table) {
        if (!phase4cTableExists($conn, $table)) {
            $failures[] = 'Missing required record-lifecycle table: ' . $table;
        }
    }

    if (phase4cTableExists($conn, 'documents')) {
        $columns = phase4cColumns($conn, 'documents');
        foreach ([
            'official_doc_id', 'source_module', 'source_record_id',
            'business_reference', 'original_file_name', 'record_number',
            'file_hash', 'record_phase', 'declared_at', 'declared_by',
            'disposition_status', 'policy_id', 'is_legal_hold',
        ] as $column) {
            if (!isset($columns[$column])) {
                $failures[] = 'documents is missing required lifecycle column: ' . $column;
            }
        }
        $statusType = (string) ($columns['status']['Type'] ?? '');
        $dispositionType = (string) ($columns['disposition_status']['Type'] ?? '');
        foreach (['Active', 'Archived', 'Recycled'] as $status) {
            if (strpos($statusType, "'{$status}'") === false) {
                $failures[] = 'documents.status is missing value: ' . $status;
            }
        }
        foreach (['Pending', 'Ready for Disposition', 'Destroyed', 'Permanently Archived'] as $status) {
            if (strpos($dispositionType, "'{$status}'") === false) {
                $failures[] = 'documents.disposition_status is missing value: ' . $status;
            }
        }

        $indexes = phase4cIndexes($conn, 'documents');
        if (!phase4cHasIndex($indexes, ['record_number'], true)) {
            $failures[] = 'documents does not enforce unique Official Record numbers.';
        }
        if (!phase4cHasIndex($indexes, ['source_module', 'source_record_id'], true)) {
            $failures[] = 'documents does not enforce one Official Record per workflow source.';
        }
    }

    if (phase4cTableExists($conn, 'virt_document_locations')) {
        $indexes = phase4cIndexes($conn, 'virt_document_locations');
        if (!phase4cHasIndex($indexes, ['document_id'], true)) {
            $failures[] = 'virt_document_locations does not enforce one physical-copy registration per record.';
        }
    }

    if (phase4cTableExists($conn, 'disposition_requests')) {
        $indexes = phase4cIndexes($conn, 'disposition_requests');
        if (!phase4cHasIndex($indexes, ['active_request_doc_id'], true)) {
            $failures[] = 'disposition_requests does not prevent duplicate active requests per record.';
        }
    }

    if ($failures === []) {
        $passes[] = 'Required record, declaration, version, physical filing, retention, and disposition schema passed live read-only preflight.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live database preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 4C verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4C verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nThis verifier is read-only. It did not create, alter, archive, restore, or delete any record.\n";
