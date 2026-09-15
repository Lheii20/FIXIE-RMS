<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];
$warnings = [];

function phase4gRead(string $root, string $relative, array &$failures): string
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

function phase4gMarkers(
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
        $passes[] = $file . ' workflow-evidence safeguards are present.';
    }
}

$download = phase4gRead($projectRoot, 'download.php', $failures);
phase4gMarkers(
    'download.php',
    $download,
    [
        'shared integrity helper' => 'config/file_integrity.php',
        'client approval hash lookup' => "'client_approval'",
        'supplier quotation hash lookup' => 'supplier_quote_file_hash',
        'fund-release hash lookup' => "'fund_release'",
        'payment-proof hash lookup' => "'payment_proof'",
        'document integrity retained' => "'document_version'",
        'protected-type gate' => '$is_integrity_protected_file',
        'pre-stream verification' => 'drms_file_integrity_verify($absolute_path, $expected_file_hash)',
        'safe blocked response' => 'The protected file was not opened.',
        'no-store response' => 'Cache-Control: private, no-store',
    ],
    $failures,
    $passes
);

foreach (
    [
        'client_approval',
        'supplier_quote',
        'fund_release',
        'payment_proof',
        'document',
        'document_version',
    ] as $protectedType
) {
    if (!preg_match(
        "/['\"]" . preg_quote($protectedType, '/') . "['\"]/",
        $download
    )) {
        $failures[] = 'download.php does not protect type: ' . $protectedType;
    }
}

$verifyPosition = strrpos($download, 'drms_file_integrity_verify(');
$streamPosition = strpos($download, "fopen(\$absolute_path, 'rb')");
if (
    $verifyPosition === false ||
    $streamPosition === false ||
    $verifyPosition > $streamPosition
) {
    $failures[] = 'download.php does not verify protected evidence before streaming.';
}

$handlerChecks = [
    'actions/quotation_handler.php' => [
        'approval proof SHA-256' => '$proof_file_hash = hash_file(',
        'approval hash persistence' => 'proof_file_hash,',
    ],
    'actions/pr_handler.php' => [
        'supplier quote SHA-256' => "hash_file('sha256', \$absolute_path)",
        'supplier quote hash persistence' => 'supplier_quote_file_hash,',
    ],
    'actions/po_handler.php' => [
        'fund-release SHA-256' => '$funding_file_hash = hash_file(',
        'fund-release hash persistence' => 'proof_file_hash,',
    ],
    'actions/collection_payment_handler.php' => [
        'payment-proof SHA-256' => '$proof_file_hash = strtolower((string) hash_file(',
        'payment-proof hash persistence' => 'proof_file_hash',
    ],
];
foreach ($handlerChecks as $file => $markers) {
    $contents = phase4gRead($projectRoot, $file, $failures);
    phase4gMarkers(
        $file,
        $contents,
        $markers,
        $failures,
        $passes
    );
}

$installer = phase4gRead(
    $projectRoot,
    'scripts/install_final_readiness_phase_4g.php',
    $failures
);
phase4gMarkers(
    'scripts/install_final_readiness_phase_4g.php',
    $installer,
    [
        'all four evidence sources' => "'payment proof' => [",
        'column schema preflight' => 'SHOW COLUMNS FROM',
        'existing-hash preservation' => 'drms_file_integrity_is_hash($storedHash)',
        'stored-file resolution' => 'drms_storage_resolve_existing_file($runtimePath)',
        'existing file verification' => 'drms_file_integrity_verify($absolutePath, $storedHash)',
        'missing-hash backfill' => 'drms_file_integrity_hash($absolutePath)',
        'race-safe update' => "COALESCE(`{\$hashColumn}`, '') = ''",
        'transaction rollback' => '$conn->rollback();',
    ],
    $failures,
    $passes
);

try {
    require_once $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'storage_security.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'file_integrity.php';
    require $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $sources = [
        'client approval proof' => [
            'table' => 'client_approval_records',
            'id' => 'approval_record_id',
            'path' => 'proof_file_path',
            'hash' => 'proof_file_hash',
            'prefix' => 'uploads/pos/',
        ],
        'supplier quotation' => [
            'table' => 'pr_supplier_details',
            'id' => 'supplier_detail_id',
            'path' => 'supplier_quote_file_path',
            'hash' => 'supplier_quote_file_hash',
            'prefix' => 'uploads/supplier_quotes/',
        ],
        'fund-release proof' => [
            'table' => 'po_supplier_fund_releases',
            'id' => 'fund_release_id',
            'path' => 'proof_file_path',
            'hash' => 'proof_file_hash',
            'prefix' => 'uploads/fund_releases/',
        ],
        'payment proof' => [
            'table' => 'payments',
            'id' => 'payment_id',
            'path' => 'proof_file_path',
            'hash' => 'proof_file_hash',
            'prefix' => 'uploads/payments/',
        ],
    ];

    $verified = 0;
    $unavailable = 0;
    foreach ($sources as $label => $source) {
        $table = $source['table'];
        $idColumn = $source['id'];
        $pathColumn = $source['path'];
        $hashColumn = $source['hash'];

        $columnResult = $conn->query(
            "SHOW COLUMNS FROM `{$table}` LIKE '{$hashColumn}'"
        );
        $column = $columnResult->fetch_assoc() ?: null;
        $columnResult->free();
        if ($column === null) {
            $failures[] = "Missing required integrity column {$table}.{$hashColumn}.";
            continue;
        }
        if (!preg_match('/^char\(64\)$/i', (string) ($column['Type'] ?? ''))) {
            $failures[] = "{$table}.{$hashColumn} must be CHAR(64).";
            continue;
        }

        $result = $conn->query(
            "SELECT `{$idColumn}` AS evidence_id, "
            . "`{$pathColumn}` AS stored_path, `{$hashColumn}` AS stored_hash "
            . "FROM `{$table}` "
            . "WHERE COALESCE(`{$pathColumn}`, '') <> '' "
            . "ORDER BY `{$idColumn}` ASC"
        );
        while ($row = $result->fetch_assoc()) {
            $evidenceId = (int) $row['evidence_id'];
            $storedHash = trim((string) ($row['stored_hash'] ?? ''));
            $runtimePath = $source['prefix']
                . basename((string) $row['stored_path']);
            try {
                $absolutePath = drms_storage_resolve_existing_file($runtimePath);
            } catch (Throwable) {
                $unavailable++;
                continue;
            }
            if (!drms_file_integrity_is_hash($storedHash)) {
                $failures[] = ucfirst($label) . " ID {$evidenceId} has no valid SHA-256 baseline.";
                continue;
            }
            try {
                drms_file_integrity_verify($absolutePath, $storedHash);
                $verified++;
            } catch (DrmsFileIntegrityException) {
                $failures[] = ucfirst($label) . " ID {$evidenceId} failed its SHA-256 integrity check.";
            }
        }
        $result->free();
    }
    $passes[] = $verified
        . ' readable workflow evidence file(s) passed SHA-256 verification.';
    if ($unavailable > 0) {
        $warnings[] = $unavailable
            . ' sample/legacy evidence row(s) point to files that are no longer stored; opening them remains blocked.';
    }
} catch (Throwable $error) {
    $failures[] = 'Live workflow-evidence preflight failed: ' . $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 4G verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 4G verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
foreach ($warnings as $warning) {
    echo '- Note: ' . $warning . "\n";
}
echo "\nThis verifier is read-only. It did not change records, hashes, or stored files.\n";
