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

function phase4fNormalizeStoredPath(string $path): string
{
    $normalized = str_replace('\\', '/', trim($path));
    $normalized = preg_replace(
        '#^(?:\.\./)+uploads/+#i',
        'uploads/',
        $normalized
    ) ?? $normalized;
    return $normalized;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$columnAdded = false;
$hashed = 0;
$normalizedPaths = 0;
$alreadyProtected = 0;
$unavailable = [];

try {
    $columnResult = $conn->query(
        "SHOW COLUMNS FROM document_versions LIKE 'file_hash'"
    );
    $column = $columnResult->fetch_assoc() ?: null;
    $columnResult->free();

    if ($column === null) {
        $conn->query(
            'ALTER TABLE document_versions '
            . 'ADD COLUMN file_hash CHAR(64) NULL DEFAULT NULL AFTER file_path'
        );
        $columnAdded = true;
    } else {
        $type = strtolower((string) ($column['Type'] ?? ''));
        if (!preg_match('/^char\(64\)$/', $type)) {
            throw new RuntimeException(
                'document_versions.file_hash exists but is not CHAR(64).'
            );
        }
    }

    $result = $conn->query(
        'SELECT version_id, file_path, file_hash '
        . 'FROM document_versions ORDER BY version_id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    $update = $conn->prepare(
        'UPDATE document_versions SET file_path = ?, file_hash = ? '
        . 'WHERE version_id = ?'
    );
    $pathOnly = $conn->prepare(
        'UPDATE document_versions SET file_path = ? WHERE version_id = ?'
    );

    $conn->begin_transaction();
    try {
        foreach ($rows as $row) {
            $versionId = (int) $row['version_id'];
            $originalPath = (string) ($row['file_path'] ?? '');
            $storedPath = phase4fNormalizeStoredPath($originalPath);
            $existingHash = trim((string) ($row['file_hash'] ?? ''));

            if ($storedPath !== $originalPath) {
                $pathOnly->bind_param('si', $storedPath, $versionId);
                $pathOnly->execute();
                $normalizedPaths++;
            }

            if (drms_file_integrity_is_hash($existingHash)) {
                $alreadyProtected++;
                continue;
            }
            if ($existingHash !== '') {
                $unavailable[] = $versionId;
                continue;
            }

            try {
                $absolutePath = drms_storage_resolve_existing_file($storedPath);
                $hash = drms_file_integrity_hash($absolutePath);
            } catch (Throwable $error) {
                $unavailable[] = $versionId;
                continue;
            }

            $update->bind_param('ssi', $storedPath, $hash, $versionId);
            $update->execute();
            $hashed++;
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    } finally {
        $update->close();
        $pathOnly->close();
    }

    echo "Phase 4F installer completed.\n\n";
    echo '- file_hash column: '
        . ($columnAdded ? 'added' : 'already installed') . "\n";
    echo '- Existing version hashes created: ' . $hashed . "\n";
    echo '- Existing protected hashes retained: ' . $alreadyProtected . "\n";
    echo '- Legacy stored paths normalized: ' . $normalizedPaths . "\n";
    if ($unavailable) {
        echo '- Historical versions without a readable stored file: '
            . count(array_unique($unavailable)) . "\n";
        echo "  These rows were not re-baselined. Their unavailable binaries remain blocked.\n";
    } else {
        echo "- Every historical version has a protected hash.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Phase 4F installer FAILED:\n\n");
    fwrite(STDERR, '- ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
