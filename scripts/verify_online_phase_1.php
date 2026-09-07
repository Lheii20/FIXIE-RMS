<?php
declare(strict_types=1);

/**
 * Online Phase 1 verification
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\verify_online_phase_1.php
 *   C:\xampp\php\php.exe scripts\verify_online_phase_1.php --skip-database
 *
 * This script performs syntax/configuration checks and, unless explicitly
 * skipped, a read-only database connection check. It never changes records.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$skipDatabase = in_array('--skip-database', $argv, true);
$failures = [];
$passes = [];

function phase1_pass(string $message): void
{
    global $passes;
    $passes[] = $message;
    echo "[PASS] {$message}" . PHP_EOL;
}

function phase1_fail(string $message): void
{
    global $failures;
    $failures[] = $message;
    echo "[FAIL] {$message}" . PHP_EOL;
}

function phase1_assert(bool $condition, string $message): void
{
    $condition ? phase1_pass($message) : phase1_fail($message);
}

function phase1_lint(string $path): bool
{
    if (!function_exists('proc_open')) {
        return true;
    }

    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
    $specification = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $specification, $pipes);

    if (!is_resource($process)) {
        return false;
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}

$requiredFiles = [
    'config/runtime.php',
    'config/runtime.local.php.example',
    'config/.htaccess',
    'config/session_bootstrap.php',
    'config/db_connect.php',
    'config/maintenance_db.php',
    'config/mailer.php',
];

foreach ($requiredFiles as $relativePath) {
    phase1_assert(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)), "Required file exists: {$relativePath}");
}

$phpFiles = array_filter($requiredFiles, static fn(string $path): bool => str_ends_with($path, '.php'));
foreach ($phpFiles as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath)) {
        phase1_assert(phase1_lint($absolutePath), "PHP syntax is valid: {$relativePath}");
    }
}

$protectionPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.htaccess';
if (is_file($protectionPath)) {
    $protection = (string) file_get_contents($protectionPath);
    phase1_assert(
        str_contains($protection, 'Require all denied') && str_contains($protection, 'Deny from all'),
        'Apache protection denies direct web access to configuration files'
    );
}

$localConfigPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.local.php';
if (is_file($localConfigPath)) {
    $localConfigSource = (string) file_get_contents($localConfigPath);
    phase1_assert(
        !str_contains($localConfigSource, 'REPLACE_WITH')
            && !str_contains($localConfigSource, 'your-domain.example')
            && !str_contains($localConfigSource, 'sql000.infinityfree.com')
            && !str_contains($localConfigSource, 'if0_00000000'),
        'Installed runtime.local.php does not contain template placeholders'
    );
}

try {
    require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php';
    $runtime = drms_runtime_config();

    phase1_assert(in_array($runtime['app']['environment'], ['production', 'development', 'local', 'testing'], true), 'Application environment is valid');
    phase1_assert(in_array($runtime['app']['hosting_profile'], ['standard', 'local', 'infinityfree', 'shared', 'managed'], true), 'Hosting profile is valid');
    phase1_assert(date_default_timezone_get() === $runtime['app']['timezone'], 'PHP timezone matches the portable configuration');
    phase1_assert((int) $runtime['database']['port'] >= 1, 'Database port is valid');
    phase1_assert((int) $runtime['mail']['port'] >= 1, 'SMTP port is valid');

    if ($runtime['app']['hosting_profile'] === 'infinityfree') {
        phase1_assert(drms_runtime_feature_enabled('scheduled_maintenance') === false, 'InfinityFree profile disables scheduled maintenance');
        phase1_assert(drms_runtime_feature_enabled('server_backup') === false, 'InfinityFree profile disables server-side backup');
        phase1_assert(drms_runtime_feature_enabled('large_uploads') === false, 'InfinityFree profile disables large uploads');
    }

    echo PHP_EOL;
    echo 'Active hosting profile : ' . $runtime['app']['hosting_profile'] . PHP_EOL;
    echo 'Application timezone   : ' . $runtime['app']['timezone'] . PHP_EOL;
    echo 'SMTP credentials       : ' . (($runtime['mail']['username'] !== '' && $runtime['mail']['password'] !== '') ? 'configured' : 'not configured') . PHP_EOL;
} catch (Throwable $exception) {
    phase1_fail('Portable runtime configuration loads successfully: ' . $exception->getMessage());
}

if ($skipDatabase) {
    echo PHP_EOL . '[SKIP] Read-only database connection check was skipped.' . PHP_EOL;
} elseif (function_exists('drms_runtime_config')) {
    try {
        require $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
        $result = $conn->query('SELECT DATABASE() AS active_database, @@session.time_zone AS session_timezone');
        $row = $result ? $result->fetch_assoc() : null;

        phase1_assert(is_array($row) && (string) ($row['active_database'] ?? '') !== '', 'Read-only database connection succeeds');
        phase1_assert(
            is_array($row) && (string) ($row['session_timezone'] ?? '') === drms_runtime_database_timezone_value(),
            'MySQL session timezone matches the application timezone'
        );

        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $conn->close();
    } catch (Throwable $exception) {
        phase1_fail('Read-only database verification: ' . $exception->getMessage());
    }
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Online Phase 1 verification FAILED with ' . count($failures) . ' issue(s).' . PHP_EOL;
    exit(1);
}

echo 'Online Phase 1 verification PASSED (' . count($passes) . ' checks).' . PHP_EOL;
exit(0);
