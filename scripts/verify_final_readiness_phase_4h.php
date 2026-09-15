<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase4hRead(string $root, string $relative, array &$failures): string
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

function phase4hMarkers(
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
        $passes[] = $file . ' protected-file audit safeguards are present.';
    }
}

$download = phase4hRead($projectRoot, 'download.php', $failures);
phase4hMarkers(
    'download.php',
    $download,
    [
        'central audit helper' => 'function drms_download_audit_event(',
        'document download event' => "'DOWNLOAD_DOC'",
        'workflow evidence event' => "'DOWNLOAD_EVIDENCE'",
        'integrity-block event' => "'FILE_INTEGRITY_BLOCKED'",
        'client approval label' => "'client_approval' => 'client approval proof'",
        'supplier quotation label' => "'supplier_quote' => 'supplier quotation'",
        'fund release label' => "'fund_release' => 'fund-release proof'",
        'payment proof label' => "'payment_proof' => 'payment proof'",
        'server-generated event metadata' => "'evidence_id' => \$recordId",
        'non-blocking audit failure handling' => 'Protected file audit event could not be recorded:',
        'Phase 4G integrity verification' => 'drms_file_integrity_verify($absolute_path, $expected_file_hash)',
        'safe integrity response' => 'The protected file was not opened.',
        'successful-open outcome' => "'opened',",
        'blocked outcome' => "'integrity_blocked'",
    ],
    $failures,
    $passes
);

$integrityCall = strpos(
    $download,
    'drms_file_integrity_verify($absolute_path, $expected_file_hash)'
);
$blockedAuditCall = strpos(
    $download,
    "            'integrity_blocked'\n"
);
if ($blockedAuditCall === false) {
    $blockedAuditCall = strpos($download, "            'integrity_blocked'\r\n");
}
$blockedResponse = strpos(
    $download,
    'File integrity verification failed. The protected file was not opened.'
);
if (
    $integrityCall === false ||
    $blockedAuditCall === false ||
    $blockedResponse === false ||
    $integrityCall > $blockedAuditCall ||
    $blockedAuditCall > $blockedResponse
) {
    $failures[] = 'download.php does not audit an integrity block before returning its safe response.';
}

$successAuditCall = strrpos($download, 'drms_download_audit_event(');
$streamOpenCall = strpos($download, "fopen(\$absolute_path, 'rb')");
$streamOutputCall = strpos($download, 'fpassthru($stream)');
if (
    $successAuditCall === false ||
    $streamOpenCall === false ||
    $streamOutputCall === false ||
    $streamOpenCall > $successAuditCall ||
    $successAuditCall > $streamOutputCall
) {
    $failures[] = 'download.php does not record successful access after opening and before streaming the file.';
}

$functions = phase4hRead(
    $projectRoot,
    'config/functions.php',
    $failures
);
phase4hMarkers(
    'config/functions.php',
    $functions,
    [
        'general audit writer' => 'function log_audit_action(',
        'document-linked audit writer' => 'function log_document_action(',
    ],
    $failures,
    $passes
);

$auditQuery = phase4hRead(
    $projectRoot,
    'config/audit_query.php',
    $failures
);
phase4hMarkers(
    'config/audit_query.php',
    $auditQuery,
    [
        'download event classification' => "LIKE '%DOWNLOAD%'",
        'file event classification' => "LIKE '%FILE%'",
    ],
    $failures,
    $passes
);

try {
    require $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $requiredAuditColumns = [
        'audit_logs' => [
            'log_id',
            'user_id',
            'action_type',
            'description',
            'old_payload',
            'new_payload',
            'ip_address',
            'timestamp',
        ],
        'document_audit_trail' => [
            'trail_id',
            'audit_log_id',
            'doc_id',
            'user_id',
            'action_type',
            'description',
            'ip_address',
            'source_page',
            'created_at',
        ],
    ];

    foreach ($requiredAuditColumns as $table => $requiredColumns) {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
        $actualColumns = [];
        while ($column = $result->fetch_assoc()) {
            $actualColumns[] = (string) $column['Field'];
        }
        $result->free();
        $missingColumns = array_values(array_diff(
            $requiredColumns,
            $actualColumns
        ));
        if ($missingColumns) {
            $failures[] = $table . ' is missing required columns: '
                . implode(', ', $missingColumns);
        } else {
            $passes[] = $table . ' supports the required protected-file events.';
        }
    }

    $actionColumnResult = $conn->query(
        "SHOW COLUMNS FROM audit_logs LIKE 'action_type'"
    );
    $actionColumn = $actionColumnResult->fetch_assoc() ?: null;
    $actionColumnResult->free();
    if (
        $actionColumn === null ||
        !preg_match('/^varchar\((\d+)\)$/i', (string) $actionColumn['Type'], $match) ||
        (int) $match[1] < strlen('FILE_INTEGRITY_BLOCKED')
    ) {
        $failures[] = 'audit_logs.action_type is too short for Phase 4H events.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live audit-schema preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4H verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4H verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nThis verifier is read-only. It did not create audit events or change files, records, or schema.\n";
