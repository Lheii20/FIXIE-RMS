<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

// Safe default: inspection only. DDL needs the explicit --apply argument.
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "Usage: php scripts/migrate_virtual_cabinet_vc1.php [--check|--apply]\n";
    echo "Default is --check. Target: fixie_drms on MariaDB 10.4+. Back up first; DDL is not transactional.\n";
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--check'] && $arguments !== ['--apply']) {
    fwrite(STDERR, "Invalid arguments. Use --check or --apply, not both.\n");
    exit(1);
}
$apply = $arguments === ['--apply'];
$root = dirname(__DIR__);
$locked = false;
$conn = null;
$completed = 0;
$exitCode = 0;
try {
    foreach (['config/physical_storage_schema.php', 'config/maintenance_db.php'] as $file) {
        if (!is_file($root . '/' . $file)) {
            throw new RuntimeException('Missing required file: ' . $file);
        }
    }
    require $root . '/config/physical_storage_schema.php';
    if (!drms_vc1_sync_guard_present($root)) {
        throw new RuntimeException('Replace sync_cabinets.php with the VC1 retired-entry-point version first.');
    }
    // Unlike db_connect.php, this existing CLI bootstrap has no session/audit/schema side effects.
    require $root . '/config/maintenance_db.php';
    $report = drms_vc1_inspect($conn);
    echo 'Database: ' . $report['database'] . ' | Server: ' . $report['version'] . "\n";
    if ($report['issues']) {
        throw new RuntimeException(implode("\n", $report['issues']));
    }
    if (!$apply) {
        foreach ($report['steps'] as $step) {
            echo 'PENDING: ' . $step['label'] . "\n";
        }
        echo $report['steps'] ? "VC1 preflight passed. No schema or records changed. Back up, then rerun with --apply.\n" : "VC1 foundation already installed. No changes needed.\n";
    } else {
        $locked = (int) $conn->query("SELECT GET_LOCK('fixie_drms:virtual_cabinet_vc1', 10)")->fetch_row()[0] === 1;
        if (!$locked) {
            throw new RuntimeException('Another VC1 installer is running. Try again after it finishes.');
        }
        // Re-inspect under the lock so repeated/concurrent installers cannot duplicate changes.
        $report = drms_vc1_inspect($conn);
        if ($report['issues']) {
            throw new RuntimeException(implode("\n", $report['issues']));
        }
        foreach ($report['steps'] as $step) {
            echo 'APPLY: ' . $step['label'] . "\n";
            $conn->query($step['sql']);
            $completed++;
        }
        $after = drms_vc1_inspect($conn);
        if ($after['issues'] || $after['steps']) {
            throw new RuntimeException('Post-install inspection failed. ' . implode(' ', $after['issues']));
        }
        echo "VC1 foundation installed and verified. Schema steps applied: $completed.\n";
        echo "No records were moved, renamed, deleted or assigned. Existing pages remain unchanged.\n";
    }
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, 'STOP: ' . $error->getMessage() . "\n");
    if ($apply) {
        fwrite(STDERR, "DDL changes are not rolled back. Successful steps: $completed. Keep the error, correct the cause, then rerun; completed compatible steps are preserved.\n");
    }
    fwrite(STDERR, "If the connection failed, start MySQL in XAMPP and check config/maintenance_db.php and the DB_* environment settings.\n");
} finally {
    if ($locked && $conn instanceof mysqli) {
        try {
            $conn->query("SELECT RELEASE_LOCK('fixie_drms:virtual_cabinet_vc1')");
        } catch (Throwable $cleanupError) {
            // A lost connection releases its advisory lock on the server.
            fwrite(STDERR, "The migration connection closed before lock cleanup. Rerun --check after reconnecting.\n");
        }
    }
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
exit($exitCode);
