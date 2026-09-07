<?php
declare(strict_types=1);

/**
 * Online Phase 2 compatibility verifier.
 *
 * Usage:
 *   php scripts/verify_online_phase_2.php
 *   php scripts/verify_online_phase_2.php --sql="C:\path\to\fixie_drms.sql"
 *
 * Database checks are read-only. Temporary upload fixtures are deleted before
 * the script exits.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$failures = [];
$passes = 0;

function phase2_result(bool $passed, string $message): void
{
    global $failures, $passes;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if ($passed) {
        $passes++;
    } else {
        $failures[] = $message;
    }
}

function phase2_sql_path(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--sql=')) {
            $path = trim(substr($argument, 6), "\"'");
            return $path === '' ? null : $path;
        }
    }
    return null;
}

function phase2_check_sql(string $path): void
{
    phase2_result(is_file($path), 'SQL export exists');
    if (!is_file($path)) {
        return;
    }

    $size = filesize($path);
    phase2_result($size !== false && $size > 0, 'SQL export is not empty');
    phase2_result($size !== false && $size <= 10 * 1024 * 1024, 'SQL export is within the 10 MB free-host transfer limit');

    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        phase2_result(false, 'SQL export can be read');
        return;
    }
    phase2_result(true, 'SQL export can be read');

    $unsafePatterns = [
        '/^\s*CREATE\s+DATABASE\b/im' => 'CREATE DATABASE statement is absent',
        '/^\s*USE\s+`?[^;]+/im' => 'USE database statement is absent',
        '/\bDEFINER\s*=/i' => 'DEFINER clause is absent',
        '/^\s*(GRANT|REVOKE)\b/im' => 'GRANT/REVOKE statement is absent',
        '/^\s*SET\s+GLOBAL\b/im' => 'SET GLOBAL statement is absent',
        '/utf8mb4_0900_/i' => 'MySQL 8-only utf8mb4_0900 collation is absent',
    ];

    foreach ($unsafePatterns as $pattern => $message) {
        phase2_result(preg_match($pattern, $sql) !== 1, $message);
    }
}

$requiredFiles = [
    'config/runtime.php',
    'config/upload_policy.php',
    'admin_settings.php',
    'actions/settings_handler.php',
];
foreach ($requiredFiles as $relativePath) {
    phase2_result(
        is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)),
        "Required Phase 2 file exists: {$relativePath}"
    );
}

try {
    require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php';
    require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'upload_policy.php';

    foreach (['mysqli', 'fileinfo', 'zip', 'mbstring', 'openssl', 'json'] as $extension) {
        phase2_result(extension_loaded($extension), "Required PHP extension is loaded: {$extension}");
    }

    $runtime = drms_runtime_config();
    $profile = (string) $runtime['app']['hosting_profile'];
    $hostLimit = drms_runtime_host_upload_limit_mb();
    $serverLimit = drms_upload_server_limit_mb();
    $allowedSizes = drms_upload_allowed_document_limits_mb();

    phase2_result($hostLimit >= 2, 'Configured hosting upload limit is valid');
    phase2_result($serverLimit >= 2, 'Effective PHP/server upload limit supports at least 2 MB');
    phase2_result($allowedSizes !== [], 'At least one document upload-size option is available');
    phase2_result(max($allowedSizes ?: [0]) <= $serverLimit, 'Admin upload options do not exceed the effective server limit');

    if ($profile === 'infinityfree') {
        phase2_result($hostLimit <= 10, 'InfinityFree profile is capped at 10 MB per file');
        phase2_result(!in_array(25, $allowedSizes, true), 'InfinityFree profile removes the unsupported 25 MB option');
        phase2_result((int) $runtime['database']['port'] === 3306, 'InfinityFree database port is 3306');
        phase2_result(
            !in_array(strtolower((string) $runtime['database']['host']), ['localhost', '127.0.0.1', '::1'], true),
            'InfinityFree uses its assigned MySQL hostname instead of localhost'
        );
    }

    $database = $runtime['database'];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(
        (string) $database['host'],
        (string) $database['user'],
        (string) $database['password'],
        (string) $database['name'],
        (int) $database['port']
    );
    $conn->set_charset('utf8mb4');
    drms_runtime_database_timezone($conn);

    $databaseResult = $conn->query('SELECT DATABASE() AS active_database, @@session.time_zone AS session_timezone, VERSION() AS server_version');
    $databaseRow = $databaseResult->fetch_assoc();
    phase2_result((string) ($databaseRow['active_database'] ?? '') !== '', 'Read-only database connection succeeds');
    phase2_result(
        (string) ($databaseRow['session_timezone'] ?? '') === drms_runtime_database_timezone_value(),
        'MySQL and PHP use the same session timezone'
    );
    phase2_result((string) ($databaseRow['server_version'] ?? '') !== '', 'Database server version is readable');
    $databaseResult->free();

    $tableResult = $conn->query('SHOW TABLES');
    phase2_result($tableResult->num_rows > 0, 'Selected application database contains tables');
    $tableResult->free();

    $documentLimit = drms_upload_document_limit_mb($conn);
    phase2_result($documentLimit <= $serverLimit, 'Document policy is capped by the effective server limit');
    phase2_result(drms_upload_policy_limit_mb($conn, 'proof') <= $serverLimit, 'Proof policy is capped by the effective server limit');
    phase2_result(drms_upload_policy_limit_mb($conn, 'profile') <= $serverLimit, 'Profile-photo policy is capped by the effective server limit');

    $previousContentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    $postLimitBytes = drms_upload_ini_bytes((string) ini_get('post_max_size'));
    if ($postLimitBytes !== null) {
        $_SERVER['CONTENT_LENGTH'] = (string) ($postLimitBytes + 1);
        try {
            drms_upload_validate($conn, null, 'document', false);
            phase2_result(false, 'Oversized POST request receives the correct validation error');
        } catch (DrmsUploadValidationException $uploadException) {
            phase2_result(
                $uploadException->validationCode() === 'RequestSizeExceeded',
                'Oversized POST request receives the correct validation error'
            );
        } finally {
            if ($previousContentLength === null) {
                unset($_SERVER['CONTENT_LENGTH']);
            } else {
                $_SERVER['CONTENT_LENGTH'] = $previousContentLength;
            }
        }
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'drms_p2_');
    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to create a temporary upload test file.');
    }
    try {
        file_put_contents($temporaryPath, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $validated = drms_upload_validate($conn, [
            'name' => 'phase2-test.pdf',
            'tmp_name' => $temporaryPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($temporaryPath),
        ], 'document', false);
        phase2_result(($validated['mime'] ?? '') === 'application/pdf', 'Central upload validator accepts a valid PDF fixture');
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    $conn->close();

    echo PHP_EOL;
    echo 'Hosting profile       : ' . $profile . PHP_EOL;
    echo 'Host file limit       : ' . $hostLimit . ' MB' . PHP_EOL;
    echo 'Effective server limit: ' . $serverLimit . ' MB' . PHP_EOL;
    echo 'Document limit        : ' . $documentLimit . ' MB' . PHP_EOL;
} catch (Throwable $exception) {
    phase2_result(false, 'Compatibility verification completed: ' . $exception->getMessage());
}

$sqlPath = phase2_sql_path(array_slice($argv, 1));
if ($sqlPath !== null) {
    echo PHP_EOL . 'SQL import preflight' . PHP_EOL;
    phase2_check_sql($sqlPath);
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Online Phase 2 verification FAILED with ' . count($failures) . ' issue(s).' . PHP_EOL;
    exit(1);
}

echo "Online Phase 2 verification PASSED ({$passes} checks)." . PHP_EOL;
exit(0);
