<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];
$warnings = [];

function phase4fRead(string $root, string $relative, array &$failures): string
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

function phase4fMarkers(
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
        $passes[] = $file . ' integrity safeguards are present.';
    }
}

$integrity = phase4fRead(
    $projectRoot,
    'config/file_integrity.php',
    $failures
);
phase4fMarkers(
    'config/file_integrity.php',
    $integrity,
    [
        'strict SHA-256 format' => "'/^[a-f0-9]{64}$/i'",
        'protected file hashing' => "hash_file('sha256'",
        'symbolic-link rejection' => 'is_link($absolutePath)',
        'constant-time hash comparison' => 'hash_equals(',
        'missing-baseline rejection' => 'integrity baseline for this file is unavailable',
        'mismatch rejection' => 'does not match its protected integrity hash',
    ],
    $failures,
    $passes
);

$installer = phase4fRead(
    $projectRoot,
    'scripts/install_final_readiness_phase_4f.php',
    $failures
);
phase4fMarkers(
    'scripts/install_final_readiness_phase_4f.php',
    $installer,
    [
        'schema preflight' => "SHOW COLUMNS FROM document_versions LIKE 'file_hash'",
        'compatible column migration' => 'ADD COLUMN file_hash CHAR(64)',
        'legacy path normalization' => 'phase4fNormalizeStoredPath(',
        'existing hash preservation' => 'drms_file_integrity_is_hash($existingHash)',
        'secure path resolution' => 'drms_storage_resolve_existing_file($storedPath)',
        'version hash backfill' => 'SET file_path = ?, file_hash = ?',
        'transaction rollback' => '$conn->rollback();',
    ],
    $failures,
    $passes
);

$download = phase4fRead($projectRoot, 'download.php', $failures);
phase4fMarkers(
    'download.php',
    $download,
    [
        'shared integrity helper' => 'config/file_integrity.php',
        'version hash lookup' => 'dv.file_hash AS version_file_hash',
        'document hash lookup' => 'file_hash,',
        'protected download gate' => '$is_integrity_protected_file',
        'pre-stream verification' => 'drms_file_integrity_verify($absolute_path, $expected_file_hash)',
        'safe blocked response' => 'The protected file was not opened.',
        'no-store response' => 'Cache-Control: private, no-store',
    ],
    $failures,
    $passes
);
$protectedGateStart = strpos(
    $download,
    '$is_integrity_protected_file = in_array('
);
$protectedGateEnd = $protectedGateStart === false
    ? false
    : strpos($download, ');', $protectedGateStart);
$protectedGate = (
    $protectedGateStart !== false &&
    $protectedGateEnd !== false
)
    ? substr(
        $download,
        $protectedGateStart,
        $protectedGateEnd - $protectedGateStart
    )
    : '';
foreach (['document', 'document_version'] as $requiredProtectedType) {
    if (
        strpos(
            $protectedGate,
            "'" . $requiredProtectedType . "'"
        ) === false
    ) {
        $failures[] = 'download.php no longer protects type: '
            . $requiredProtectedType;
    }
}
$verifyPosition = strpos($download, 'drms_file_integrity_verify(');
$streamPosition = strpos($download, "fopen(\$absolute_path, 'rb')");
if (
    $verifyPosition === false ||
    $streamPosition === false ||
    $verifyPosition > $streamPosition
) {
    $failures[] = 'download.php does not verify controlled files before streaming.';
}

$versionHandler = phase4fRead(
    $projectRoot,
    'actions/version_handler.php',
    $failures
);
phase4fMarkers(
    'actions/version_handler.php',
    $versionHandler,
    [
        'shared integrity helper' => 'config/file_integrity.php',
        'current binary verification' => 'drms_file_integrity_verify(',
        'current version hash snapshot' => '$locked_document[\'file_hash\']',
        'new version hash snapshot' => '$new_file_hash,',
        'integrity failure rollback message' => 'A new version was not accepted.',
        'transaction rollback' => '$conn->rollback();',
    ],
    $failures,
    $passes
);
if (substr_count($versionHandler, 'file_hash,') < 2) {
    $failures[] = 'actions/version_handler.php does not persist hashes for every version insert.';
}

$documentHandler = phase4fRead(
    $projectRoot,
    'actions/document_handler.php',
    $failures
);
phase4fMarkers(
    'actions/document_handler.php',
    $documentHandler,
    [
        'copied lineage hash' => 'source_version.file_hash',
        'source snapshot hash' => '$source_file_hash,',
        'official snapshot hash' => '$file_hash,',
        'Phase 4E folder security prerequisite' => 'config/folder_action_security.php',
    ],
    $failures,
    $passes
);
if (substr_count($documentHandler, "INSERT INTO document_versions (") !== 3) {
    $failures[] = 'actions/document_handler.php has an unexpected version-snapshot insert count.';
}

if ($integrity !== '') {
    require_once $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'file_integrity.php';
    try {
        $knownHash = hash('sha256', 'Fixie DRMS Phase 4F');
        if (!drms_file_integrity_is_hash($knownHash)) {
            $failures[] = 'Valid SHA-256 format regression check failed.';
        }
        if (drms_file_integrity_is_hash('not-a-sha256-hash')) {
            $failures[] = 'Invalid SHA-256 format regression check failed.';
        }
        $passes[] = 'SHA-256 metadata validation regression checks passed.';
    } catch (Throwable $error) {
        $failures[] = 'Integrity helper regression check failed: ' . $error->getMessage();
    }
}

try {
    require_once $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'storage_security.php';
    require $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $columnResult = $conn->query(
        "SHOW COLUMNS FROM document_versions LIKE 'file_hash'"
    );
    $column = $columnResult->fetch_assoc() ?: null;
    $columnResult->free();
    if ($column === null) {
        $failures[] = 'Phase 4F installer has not added document_versions.file_hash.';
    } elseif (!preg_match('/^char\(64\)$/i', (string) ($column['Type'] ?? ''))) {
        $failures[] = 'document_versions.file_hash must be CHAR(64).';
    }

    if ($column !== null) {
        $result = $conn->query(
            'SELECT version_id, file_path, file_hash FROM document_versions '
            . 'ORDER BY version_id ASC'
        );
        $verifiedVersions = 0;
        $unavailableVersions = 0;
        while ($version = $result->fetch_assoc()) {
            try {
                $absolute = drms_storage_resolve_existing_file(
                    (string) $version['file_path']
                );
            } catch (Throwable) {
                $unavailableVersions++;
                continue;
            }
            if (!drms_file_integrity_is_hash($version['file_hash'] ?? null)) {
                $failures[] = 'Readable historical version ID '
                    . (int) $version['version_id']
                    . ' has no valid integrity baseline.';
                continue;
            }
            try {
                drms_file_integrity_verify($absolute, $version['file_hash']);
                $verifiedVersions++;
            } catch (DrmsFileIntegrityException) {
                $failures[] = 'Historical version ID '
                    . (int) $version['version_id']
                    . ' failed its SHA-256 integrity check.';
            }
        }
        $result->free();
        $passes[] = $verifiedVersions
            . ' readable historical version file(s) passed SHA-256 verification.';
        if ($unavailableVersions > 0) {
            $warnings[] = $unavailableVersions
                . ' sample/legacy version row(s) point to files that are no longer stored; downloads for them remain blocked.';
        }
    }
} catch (Throwable $error) {
    $failures[] = 'Live version-integrity preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4F verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4F verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
foreach ($warnings as $warning) {
    echo '- Note: ' . $warning . "\n";
}
echo "\nThis verifier is read-only. It did not change the schema, records, hashes, or stored files.\n";
