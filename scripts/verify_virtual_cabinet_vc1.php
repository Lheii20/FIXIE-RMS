<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}
if (count($argv) !== 1) {
    fwrite(STDERR, "Usage: php scripts/verify_virtual_cabinet_vc1.php (no arguments)\n");
    exit(1);
}
$root = dirname(__DIR__);
$conn = null;
$exitCode = 0;
try {
    foreach (['config/physical_storage_schema.php', 'config/maintenance_db.php', 'scripts/migrate_virtual_cabinet_vc1.php'] as $file) {
        if (!is_file($root . '/' . $file)) {
            throw new RuntimeException('Missing required file: ' . $file);
        }
    }
    require $root . '/config/physical_storage_schema.php';
    if (!drms_vc1_sync_guard_present($root)) {
        throw new RuntimeException('The legacy cabinet-sync entry point has not been safely retired.');
    }
    echo "PASS: legacy cabinet-sync guard.\n";
    require $root . '/config/maintenance_db.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $report = drms_vc1_inspect($conn);
    echo 'Database: ' . $report['database'] . ' | Server: ' . $report['version'] . "\n";
    if ($report['issues']) {
        throw new RuntimeException(implode("\n", $report['issues']));
    }
    if ($report['steps']) {
        throw new RuntimeException('VC1 is incomplete. Run the migration --check, then --apply. Pending: ' . implode('; ', array_column($report['steps'], 'label')));
    }
    echo "PASS: physical boxes and physical folders have independent IDs, parent checks, unique codes and protective foreign keys.\n";
    echo "PASS: optional physical-folder link, NULL default and index are installed.\n";
    $invalid = $conn->query('SELECT COUNT(*) AS n FROM virt_document_locations l LEFT JOIN virt_physical_folders f ON f.id = l.physical_folder_id WHERE l.physical_folder_id IS NOT NULL AND f.id IS NULL')->fetch_assoc();
    if ((int) $invalid['n'] !== 0) {
        throw new RuntimeException('Orphan physical-folder assignments found. No rows have been repaired or deleted.');
    }
    $counts = $conn->query('SELECT COUNT(*) AS total, COALESCE(SUM(physical_folder_id IS NULL),0) AS unassigned, COALESCE(SUM(physical_folder_id IS NOT NULL),0) AS assigned FROM virt_document_locations')->fetch_assoc();
    echo 'INFO: existing physical-copy rows: ' . $counts['total'] . '; assigned: ' . $counts['assigned'] . '; location not assigned: ' . $counts['unassigned'] . ".\n";
    echo "Unassigned is expected after VC1. It does not prove a hard copy exists or that it is digital-only.\n";
    echo "VC1 verification passed. Read-only: no schema, record, status or audit changes.\n";
    echo "Scope: foundation only. Location-management UI and record assignment follow in VC2/VC3.\n";
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    if ($conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $cleanupError) {
            // The verification transaction is read-only; disconnect also ends it.
        }
        $conn->close();
    }
}
exit($exitCode);
