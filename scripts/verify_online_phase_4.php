<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$passes = [];
$failures = [];

function phase4_check(bool $condition, string $message): void
{
    global $passes, $failures;
    if ($condition) {
        $passes[] = $message;
        echo "[PASS] {$message}" . PHP_EOL;
        return;
    }

    $failures[] = $message;
    echo "[FAIL] {$message}" . PHP_EOL;
}

function phase4_source(string $projectRoot, string $relativePath): string
{
    $path = $projectRoot . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $relativePath);
    }
    return $source;
}

$requiredFiles = [
    'config/runtime.php',
    'config/runtime.local.php.example',
    'config/backup_restore.php',
    'actions/backup_handler.php',
    'admin_backup.php',
    'cron_maintenance.php',
    'scripts/verify_online_phase_4.php',
];

foreach ($requiredFiles as $relativePath) {
    phase4_check(
        is_file($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)),
        'Required file exists: ' . $relativePath
    );
}

if ($failures !== []) {
    echo PHP_EOL . 'ONLINE PHASE 4 FAILED: required files are missing.' . PHP_EOL;
    exit(1);
}

$runtimeSource = phase4_source($projectRoot, 'config/runtime.php');
$backupSource = phase4_source($projectRoot, 'config/backup_restore.php');
$handlerSource = phase4_source($projectRoot, 'actions/backup_handler.php');
$adminSource = phase4_source($projectRoot, 'admin_backup.php');
$cronSource = phase4_source($projectRoot, 'cron_maintenance.php');
$exampleSource = phase4_source($projectRoot, 'config/runtime.local.php.example');

phase4_check(
    strpos($runtimeSource, 'if ($restricted)') !== false
        && strpos($runtimeSource, '$config[\'features\'][$feature] = false;') !== false,
    'InfinityFree restrictions cannot be accidentally re-enabled.'
);
phase4_check(
    strpos($backupSource, "require_once __DIR__ . '/runtime.php';") !== false,
    'Backup engine loads the portable runtime configuration.'
);
phase4_check(
    strpos($backupSource, "drms_runtime_section('database')") !== false,
    'Backup database connection uses runtime.local.php settings.'
);
phase4_check(
    strpos($backupSource, "getenv('DB_HOST')") === false
        && strpos($backupSource, "getenv('DB_NAME')") === false,
    'Backup engine no longer falls back to a different database configuration.'
);
phase4_check(
    strpos($backupSource, 'function drms_backup_capability_report()') !== false,
    'Backup engine exposes a hosting capability report.'
);
phase4_check(
    substr_count($backupSource, 'drms_backup_assert_server_operations_available();') >= 2,
    'Create and restore engines both enforce the capability gate.'
);
phase4_check(
    strpos($backupSource, "function_exists('proc_open')") !== false,
    'Disabled process execution is detected before use.'
);
phase4_check(
    strpos($handlerSource, "'BackupUnavailable'") !== false,
    'Backup handler has a dedicated unavailable-host result.'
);

$handlerGate = strpos($handlerSource, "in_array(\$action, ['create_backup', 'restore_backup'], true)");
$createBranch = strpos($handlerSource, "if (\$action === 'create_backup')");
phase4_check(
    $handlerGate !== false && $createBranch !== false && $handlerGate < $createBranch,
    'Backup handler blocks unsupported operations before work begins.'
);
phase4_check(
    strpos($handlerSource, "function_exists('set_time_limit')") !== false,
    'Optional execution-time control is called safely.'
);
phase4_check(
    strpos($adminSource, 'drms_backup_capability_report()') !== false,
    'Backup page reads actual host capability.'
);
phase4_check(
    strpos($adminSource, 'Safe manual hosting backup') !== false,
    'Backup page provides a manual shared-host recovery procedure.'
);
phase4_check(
    substr_count($adminSource, '!$server_backup_available') >= 5,
    'Create and restore controls are disabled when unsupported.'
);

$cronFeatureGate = strpos($cronSource, "drms_runtime_feature_enabled('scheduled_maintenance')");
$cronDatabaseLoad = strpos($cronSource, "require_once __DIR__ . '/config/maintenance_db.php';");
phase4_check(
    $cronFeatureGate !== false
        && $cronDatabaseLoad !== false
        && $cronFeatureGate < $cronDatabaseLoad,
    'Scheduled maintenance is rejected before opening a database connection.'
);
phase4_check(
    strpos($cronSource, "exit(2);") !== false,
    'Unsupported Cron execution returns a clear non-zero status.'
);

phase4_check(
    substr_count($exampleSource, '<?php') === 1
        && str_starts_with(ltrim($exampleSource), '<?php'),
    'Runtime example is a clean PHP configuration file.'
);
phase4_check(
    strpos($exampleSource, "'hosting_profile' => 'infinityfree'") !== false,
    'Runtime example selects the InfinityFree deployment profile.'
);
phase4_check(
    strpos($exampleSource, "'scheduled_maintenance' => false") !== false,
    'Runtime example disables unsupported scheduled maintenance.'
);
phase4_check(
    strpos($exampleSource, "'server_backup' => false") !== false,
    'Runtime example disables unsupported server backup and restore.'
);
phase4_check(
    strpos($exampleSource, "REPLACE_WITH_DATABASE_PASSWORD") !== false
        && strpos($exampleSource, "REPLACE_WITH_SMTP_APP_PASSWORD") !== false,
    'Runtime example contains placeholders instead of real secrets.'
);

$exampleConfig = require $projectRoot . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'runtime.local.php.example';
phase4_check(is_array($exampleConfig), 'Runtime example returns a PHP array.');
phase4_check(
    ($exampleConfig['features']['server_backup'] ?? null) === false
        && ($exampleConfig['features']['scheduled_maintenance'] ?? null) === false,
    'Runtime example feature flags evaluate to safe values.'
);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'backup_restore.php';

$runtimeDatabase = drms_runtime_section('database');
$backupDatabase = drms_backup_database_config();
phase4_check(
    $backupDatabase['host'] === (string) $runtimeDatabase['host']
        && $backupDatabase['port'] === (string) $runtimeDatabase['port']
        && $backupDatabase['user'] === (string) $runtimeDatabase['user']
        && $backupDatabase['database'] === (string) $runtimeDatabase['name'],
    'Backup engine resolves the same database target as the web application.'
);

$capability = drms_backup_capability_report();
$expectedAvailability = $capability['enabled_by_config'] === true
    && $capability['zip_available'] === true
    && $capability['process_available'] === true
    && $capability['storage_available'] === true
    && $capability['database_tools_available'] === true;
phase4_check(
    $capability['available'] === $expectedAvailability,
    'Capability result matches all required server prerequisites.'
);
phase4_check(
    is_string($capability['profile']) && $capability['profile'] !== '',
    'Capability report identifies the active hosting profile.'
);
phase4_check(
    $capability['available'] === true || $capability['reasons'] !== [],
    'Unavailable server operations always include a user-facing reason.'
);

if ($capability['profile'] === 'infinityfree') {
    phase4_check(
        drms_runtime_feature_enabled('server_backup') === false,
        'InfinityFree profile keeps server backup disabled.'
    );
    phase4_check(
        drms_runtime_feature_enabled('scheduled_maintenance') === false,
        'InfinityFree profile keeps scheduled maintenance disabled.'
    );
} else {
    phase4_check(true, 'Non-InfinityFree profile preserves its configured backup capability.');
    phase4_check(true, 'Non-InfinityFree profile preserves its configured maintenance capability.');
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'ONLINE PHASE 4 FAILED: ' . count($failures) . ' check(s) need attention.' . PHP_EOL;
    exit(1);
}

echo 'ONLINE PHASE 4 PASSED: ' . count($passes) . ' checks completed successfully.' . PHP_EOL;
echo 'Active profile: ' . $capability['profile'] . PHP_EOL;
echo 'Complete server backup: ' . ($capability['available'] ? 'available' : 'manual-host procedure required') . PHP_EOL;
exit(0);
