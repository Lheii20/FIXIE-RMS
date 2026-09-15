<?php
declare(strict_types=1);

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);

require $projectRoot . DIRECTORY_SEPARATOR
    . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
require_once $projectRoot . DIRECTORY_SEPARATOR
    . 'config' . DIRECTORY_SEPARATOR . 'storage_security.php';
require_once $projectRoot . DIRECTORY_SEPARATOR
    . 'config' . DIRECTORY_SEPARATOR . 'file_integrity.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$evidenceSources = [
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

$pending = [];
$protected = 0;
$backfilled = 0;
$unavailable = [];

try {
    foreach ($evidenceSources as $label => $source) {
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
            throw new RuntimeException(
                "Missing required integrity column {$table}.{$hashColumn}."
            );
        }
        if (!preg_match('/^char\(64\)$/i', (string) ($column['Type'] ?? ''))) {
            throw new RuntimeException(
                "{$table}.{$hashColumn} must be CHAR(64)."
            );
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
            $storedPath = trim((string) ($row['stored_path'] ?? ''));
            $storedHash = strtolower(trim((string) ($row['stored_hash'] ?? '')));
            $runtimePath = $source['prefix'] . basename($storedPath);

            if ($storedHash !== '' && !drms_file_integrity_is_hash($storedHash)) {
                throw new RuntimeException(
                    ucfirst($label) . " ID {$evidenceId} has an invalid stored SHA-256 value. It was not replaced."
                );
            }

            try {
                $absolutePath = drms_storage_resolve_existing_file($runtimePath);
            } catch (Throwable) {
                $unavailable[] = $label . ' ID ' . $evidenceId;
                continue;
            }

            if ($storedHash !== '') {
                drms_file_integrity_verify($absolutePath, $storedHash);
                $protected++;
                continue;
            }

            $pending[] = [
                'label' => $label,
                'table' => $table,
                'id_column' => $idColumn,
                'hash_column' => $hashColumn,
                'id' => $evidenceId,
                'hash' => drms_file_integrity_hash($absolutePath),
            ];
        }
        $result->free();
    }

    $conn->begin_transaction();
    try {
        foreach ($pending as $item) {
            $table = $item['table'];
            $idColumn = $item['id_column'];
            $hashColumn = $item['hash_column'];
            $update = $conn->prepare(
                "UPDATE `{$table}` SET `{$hashColumn}` = ? "
                . "WHERE `{$idColumn}` = ? "
                . "AND COALESCE(`{$hashColumn}`, '') = ''"
            );
            $update->bind_param('si', $item['hash'], $item['id']);
            $update->execute();
            if ($update->affected_rows === 1) {
                $backfilled++;
            }
            $update->close();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    echo "Phase 4G installer completed.\n\n";
    echo '- Existing protected evidence files verified: ' . $protected . "\n";
    echo '- Missing evidence hashes safely backfilled: ' . $backfilled . "\n";
    if ($unavailable) {
        echo '- Evidence rows whose stored file is unavailable: '
            . count($unavailable) . "\n";
        echo "  No hash was fabricated for those missing sample/legacy files.\n";
    } else {
        echo "- Every evidence row with a path has a readable stored file.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Phase 4G installer FAILED:\n\n");
    fwrite(STDERR, '- ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
