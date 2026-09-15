<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$defaultDestination = dirname($projectRoot) . DIRECTORY_SEPARATOR
    . basename($projectRoot) . '_e2e';
$destinationRoot = isset($argv[2]) && trim((string) $argv[2]) !== ''
    ? rtrim((string) $argv[2], "/\\")
    : $defaultDestination;

if (!is_dir($projectRoot) || realpath($projectRoot) === false) {
    fwrite(STDERR, "Phase 5A setup FAILED:\n\n- Source project was not found.\n");
    exit(1);
}
$projectRoot = (string) realpath($projectRoot);
$destinationParent = dirname($destinationRoot);
if (!is_dir($destinationParent) || realpath($destinationParent) === false) {
    fwrite(STDERR, "Phase 5A setup FAILED:\n\n- Destination parent directory does not exist.\n");
    exit(1);
}
$destinationRoot = (string) realpath($destinationParent)
    . DIRECTORY_SEPARATOR . basename($destinationRoot);
$destinationName = basename($destinationRoot);

if (
    !preg_match('/^[A-Za-z0-9_-]+$/', $destinationName) ||
    stripos($destinationName, 'e2e') === false
) {
    fwrite(
        STDERR,
        "Phase 5A setup FAILED:\n\n- Test folder name must contain e2e and use only letters, numbers, hyphens, or underscores.\n"
    );
    exit(1);
}
if (
    strcasecmp($projectRoot, $destinationRoot) === 0 ||
    str_starts_with(
        strtolower($destinationRoot . DIRECTORY_SEPARATOR),
        strtolower($projectRoot . DIRECTORY_SEPARATOR)
    ) ||
    str_starts_with(
        strtolower($projectRoot . DIRECTORY_SEPARATOR),
        strtolower($destinationRoot . DIRECTORY_SEPARATOR)
    )
) {
    fwrite(STDERR, "Phase 5A setup FAILED:\n\n- Source and test paths must be separate sibling locations.\n");
    exit(1);
}
if (file_exists($destinationRoot)) {
    fwrite(
        STDERR,
        "Phase 5A setup FAILED:\n\n- Test folder already exists: {$destinationRoot}\nNo file was overwritten.\n"
    );
    exit(1);
}
if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.fixie-e2e-environment.json')) {
    fwrite(STDERR, "Phase 5A setup FAILED:\n\n- Refused to clone an existing E2E environment.\n");
    exit(1);
}

require_once $projectRoot . DIRECTORY_SEPARATOR
    . 'config' . DIRECTORY_SEPARATOR . 'runtime.php';
require_once $projectRoot . DIRECTORY_SEPARATOR
    . 'config' . DIRECTORY_SEPARATOR . 'backup_restore.php';

$runtime = drms_runtime_config();
$sourceDatabase = (string) $runtime['database']['name'];
$databaseName = isset($argv[3]) && trim((string) $argv[3]) !== ''
    ? trim((string) $argv[3])
    : $sourceDatabase . '_e2e';
if (
    !preg_match('/^[A-Za-z0-9_]+$/', $databaseName) ||
    stripos($databaseName, 'e2e') === false ||
    strcasecmp($databaseName, $sourceDatabase) === 0
) {
    fwrite(
        STDERR,
        "Phase 5A setup FAILED:\n\n- Test database name must be different, contain e2e, and use only letters, numbers, or underscores.\n"
    );
    exit(1);
}

function phase5aPathIsInside(string $path, string $root): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    return strcasecmp($path, $root) === 0 ||
        str_starts_with(
            strtolower($path . DIRECTORY_SEPARATOR),
            strtolower($root . DIRECTORY_SEPARATOR)
        );
}

function phase5aRemoveCreatedTree(string $directory, string $sourceRoot): void
{
    if (!is_dir($directory)) {
        return;
    }
    $resolved = realpath($directory);
    if (
        $resolved === false ||
        stripos(basename($resolved), 'e2e') === false ||
        strcasecmp($resolved, $sourceRoot) === 0 ||
        phase5aPathIsInside($sourceRoot, $resolved)
    ) {
        throw new RuntimeException('Refused to clean an unsafe test path.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolved,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Unable to clean a test directory.');
            }
        } elseif (!unlink($item->getPathname())) {
            throw new RuntimeException('Unable to clean a test file.');
        }
    }
    if (!rmdir($resolved)) {
        throw new RuntimeException('Unable to finish test-environment cleanup.');
    }
}

function phase5aCreateWorkDirectory(): string
{
    $tempRoot = realpath(sys_get_temp_dir());
    if ($tempRoot === false || !is_writable($tempRoot)) {
        throw new RuntimeException('The operating-system temporary directory is unavailable.');
    }
    $directory = $tempRoot . DIRECTORY_SEPARATOR
        . 'fixie_phase5a_' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, false) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the isolated setup work directory.');
    }
    return $directory;
}

function phase5aRemoveWorkDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $tempRoot = realpath(sys_get_temp_dir());
    $resolved = realpath($directory);
    if (
        $tempRoot === false ||
        $resolved === false ||
        !str_starts_with(basename($resolved), 'fixie_phase5a_') ||
        !phase5aPathIsInside($resolved, $tempRoot) ||
        strcasecmp($resolved, $tempRoot) === 0
    ) {
        throw new RuntimeException('Refused to clean an unsafe setup work path.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolved,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Unable to clean a setup work directory.');
            }
        } elseif (!unlink($item->getPathname())) {
            throw new RuntimeException('Unable to clean a setup work file.');
        }
    }
    if (!rmdir($resolved)) {
        throw new RuntimeException('Unable to finish setup work cleanup.');
    }
}

function phase5aExcludedPath(string $relativePath): bool
{
    $relative = str_replace('\\', '/', ltrim($relativePath, '/\\'));
    $exact = [
        'config/runtime.local.php',
        'storage/restore_in_progress.json',
        'storage/backup_restore.lock',
    ];
    if (in_array($relative, $exact, true)) {
        return true;
    }
    foreach (['.git/', '.agents/', '.codex/', 'storage/backups/', 'storage/backup_work/'] as $prefix) {
        if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    return false;
}

function phase5aCopyProject(
    string $sourceRoot,
    string $destinationRoot
): array {
    if (!mkdir($destinationRoot, 0700, false) && !is_dir($destinationRoot)) {
        throw new RuntimeException('Unable to create the isolated application folder.');
    }
    $fileCount = 0;
    $totalBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $sourceRoot,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $absolute = $item->getPathname();
        $relative = substr($absolute, strlen($sourceRoot) + 1);
        if (phase5aExcludedPath($relative)) {
            continue;
        }
        if ($item->isLink()) {
            throw new RuntimeException(
                'Symbolic links are not accepted in the isolated project copy: '
                . $relative
            );
        }
        $target = $destinationRoot . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true)) {
                throw new RuntimeException('Unable to create test directory: ' . $relative);
            }
            continue;
        }
        $targetParent = dirname($target);
        if (!is_dir($targetParent) && !mkdir($targetParent, 0700, true)) {
            throw new RuntimeException('Unable to create a test file directory.');
        }
        if (!copy($absolute, $target)) {
            throw new RuntimeException('Unable to copy test file: ' . $relative);
        }
        $fileCount++;
        $totalBytes += (int) $item->getSize();
    }
    return ['file_count' => $fileCount, 'total_bytes' => $totalBytes];
}

function phase5aDirectoryManifest(string $directory): array
{
    if (!is_dir($directory)) {
        return [
            'file_count' => 0,
            'total_bytes' => 0,
            'sha256' => hash('sha256', ''),
        ];
    }
    $entries = [];
    $totalBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isFile()) {
            throw new RuntimeException('Upload storage contains an unsupported symbolic link.');
        }
        $relative = str_replace(
            '\\',
            '/',
            substr($item->getPathname(), strlen($directory) + 1)
        );
        $size = (int) $item->getSize();
        $hash = hash_file('sha256', $item->getPathname());
        if ($hash === false) {
            throw new RuntimeException('Unable to hash upload copy: ' . $relative);
        }
        $entries[$relative] = $relative . "\0" . $size . "\0" . $hash;
        $totalBytes += $size;
    }
    ksort($entries, SORT_STRING);
    return [
        'file_count' => count($entries),
        'total_bytes' => $totalBytes,
        'sha256' => hash('sha256', implode("\n", $entries)),
    ];
}

function phase5aAppendSessionPolicy(
    string $destinationRoot,
    string $sessionName,
    string $cookiePath
): void {
    $htaccessPath = $destinationRoot . DIRECTORY_SEPARATOR . '.htaccess';
    $htaccess = is_file($htaccessPath)
        ? (string) file_get_contents($htaccessPath)
        : '';
    $marker = '# FIXIE_PHASE_5A_E2E_SESSION';
    if (!str_contains($htaccess, $marker)) {
        $block = "\r\n{$marker}\r\n"
            . "<IfModule php_module>\r\n"
            . "    php_value session.name {$sessionName}\r\n"
            . "    php_value session.cookie_path {$cookiePath}\r\n"
            . "</IfModule>\r\n"
            . "<IfModule mod_php.c>\r\n"
            . "    php_value session.name {$sessionName}\r\n"
            . "    php_value session.cookie_path {$cookiePath}\r\n"
            . "</IfModule>\r\n"
            . "<IfModule mod_php8.c>\r\n"
            . "    php_value session.name {$sessionName}\r\n"
            . "    php_value session.cookie_path {$cookiePath}\r\n"
            . "</IfModule>\r\n";
        if (file_put_contents($htaccessPath, $htaccess . $block, LOCK_EX) === false) {
            throw new RuntimeException('Unable to add isolated Apache session settings.');
        }
    }

    $userIniPath = $destinationRoot . DIRECTORY_SEPARATOR . '.user.ini';
    $userIni = is_file($userIniPath)
        ? rtrim((string) file_get_contents($userIniPath)) . "\r\n"
        : '';
    if (!str_contains($userIni, $marker)) {
        $userIni .= $marker . "\r\n"
            . 'session.name = "' . $sessionName . "\"\r\n"
            . 'session.cookie_path = "' . $cookiePath . "\"\r\n";
        if (file_put_contents($userIniPath, $userIni, LOCK_EX) === false) {
            throw new RuntimeException('Unable to add isolated CGI session settings.');
        }
    }
}

function phase5aWriteRuntime(
    string $destinationRoot,
    array $runtime,
    string $databaseName,
    string $applicationUrl
): void {
    $runtime['app']['environment'] = 'production';
    $runtime['app']['url'] = $applicationUrl;
    $runtime['app']['hosting_profile'] = 'local';
    $runtime['app']['trust_proxy_https'] = false;
    $runtime['database']['name'] = $databaseName;
    $runtime['mail']['username'] = '';
    $runtime['mail']['password'] = '';
    $runtime['mail']['from'] = '';
    $runtime['mail']['required'] = false;
    $runtime['features']['scheduled_maintenance'] = false;
    $runtime['features']['server_backup'] = false;

    $contents = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "// Generated for the isolated Phase 5A E2E environment.\n"
        . 'return ' . var_export($runtime, true) . ";\n";
    $path = $destinationRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'runtime.local.php';
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the isolated runtime configuration.');
    }
    @chmod($path, 0600);
}

function phase5aTableExists(mysqli $conn, string $table): bool
{
    $statement = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->bind_param('s', $table);
    $statement->execute();
    $exists = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0) === 1;
    $statement->close();
    return $exists;
}

$workDirectory = null;
$databaseCreated = false;
$destinationCreated = false;
$serverConnection = null;

try {
    drms_backup_find_binary('mysqldump');
    $mysqlBinary = drms_backup_find_binary('mysql');
    $workDirectory = phase5aCreateWorkDirectory();
    $databaseDump = $workDirectory . DIRECTORY_SEPARATOR . 'database.sql';
    drms_backup_dump_database($databaseDump, $workDirectory);

    $database = $runtime['database'];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $serverConnection = new mysqli(
        (string) $database['host'],
        (string) $database['user'],
        (string) $database['password'],
        '',
        (int) $database['port']
    );
    $databaseCheck = $serverConnection->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.SCHEMATA '
        . 'WHERE SCHEMA_NAME = ?'
    );
    $databaseCheck->bind_param('s', $databaseName);
    $databaseCheck->execute();
    $databaseExists = (int) (
        $databaseCheck->get_result()->fetch_assoc()['total'] ?? 0
    ) === 1;
    $databaseCheck->close();
    if ($databaseExists) {
        throw new RuntimeException(
            "Test database already exists: {$databaseName}. No database was overwritten."
        );
    }

    $serverConnection->query(
        "CREATE DATABASE `{$databaseName}` "
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
    );
    $databaseCreated = true;

    $targetDatabaseConfig = drms_backup_database_config();
    $targetDatabaseConfig['database'] = $databaseName;
    $optionFile = drms_backup_create_client_option_file(
        $workDirectory,
        $targetDatabaseConfig
    );
    try {
        $import = drms_backup_run_process(
            [
                $mysqlBinary,
                '--defaults-extra-file=' . $optionFile,
                '--batch',
                '--default-character-set=utf8mb4',
                $databaseName,
            ],
            $databaseDump,
            null
        );
        if ($import['exit_code'] !== 0) {
            throw new RuntimeException(
                'Test database import failed: '
                . ($import['stderr'] ?: 'unknown database client error')
            );
        }
    } finally {
        if (is_file($optionFile)) {
            @unlink($optionFile);
        }
    }

    $testDatabase = new mysqli(
        (string) $database['host'],
        (string) $database['user'],
        (string) $database['password'],
        $databaseName,
        (int) $database['port']
    );
    $testDatabase->set_charset('utf8mb4');
    $testDatabase->query(
        'UPDATE users SET session_token = NULL, last_active = NULL, last_activity = NULL'
    );
    foreach (
        ['login_attempts', 'otp_auth_tokens', 'password_reset_otps', 'password_resets']
        as $ephemeralTable
    ) {
        if (phase5aTableExists($testDatabase, $ephemeralTable)) {
            $testDatabase->query("DELETE FROM `{$ephemeralTable}`");
        }
    }
    $tableCountResult = $testDatabase->query(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE()'
    );
    $tableCount = (int) (
        $tableCountResult->fetch_assoc()['total'] ?? 0
    );
    $tableCountResult->free();
    $testDatabase->close();

    $sourceUploadManifest = phase5aDirectoryManifest(
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads'
    );
    $copyStats = phase5aCopyProject($projectRoot, $destinationRoot);
    $destinationCreated = true;
    $applicationUrl = 'http://localhost/' . $destinationName;
    $cookiePath = '/' . $destinationName . '/';
    $sessionName = 'FIXIEDRMSE2E'
        . strtoupper(substr(hash('sha256', $destinationRoot), 0, 8));
    phase5aWriteRuntime(
        $destinationRoot,
        $runtime,
        $databaseName,
        $applicationUrl
    );
    phase5aAppendSessionPolicy(
        $destinationRoot,
        $sessionName,
        $cookiePath
    );
    $uploadManifest = phase5aDirectoryManifest(
        $destinationRoot . DIRECTORY_SEPARATOR . 'uploads'
    );
    if ($uploadManifest !== $sourceUploadManifest) {
        throw new RuntimeException(
            'The isolated upload repository does not exactly match the source snapshot.'
        );
    }

    $marker = [
        'format' => 'FIXIE_DRMS_ISOLATED_E2E',
        'format_version' => 1,
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'source_project' => $projectRoot,
        'source_database' => $sourceDatabase,
        'test_project' => $destinationRoot,
        'test_database' => $databaseName,
        'application_url' => $applicationUrl,
        'session_name' => $sessionName,
        'session_cookie_path' => $cookiePath,
        'copied_file_count' => (int) $copyStats['file_count'],
        'copied_total_bytes' => (int) $copyStats['total_bytes'],
        'uploads_file_count' => (int) $uploadManifest['file_count'],
        'uploads_total_bytes' => (int) $uploadManifest['total_bytes'],
        'uploads_manifest_sha256' => (string) $uploadManifest['sha256'],
        'database_table_count' => $tableCount,
    ];
    $markerPath = $destinationRoot . DIRECTORY_SEPARATOR
        . '.fixie-e2e-environment.json';
    $markerJson = json_encode(
        $marker,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($markerPath, $markerJson . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to finalize the E2E environment marker.');
    }

    echo "Phase 5A isolated E2E environment created.\n\n";
    echo '- Test URL: ' . $applicationUrl . "\n";
    echo '- Test folder: ' . $destinationRoot . "\n";
    echo '- Test database: ' . $databaseName . "\n";
    echo '- Copied application files: ' . $copyStats['file_count'] . "\n";
    echo '- Imported database tables: ' . $tableCount . "\n";
    echo '- SMTP, scheduled maintenance, and server backup: disabled in test clone' . "\n";
    echo '- Browser session: isolated from the main localhost application' . "\n";
    echo "\nRun the Phase 5A verifier from the new test folder before signing in.\n";
} catch (Throwable $error) {
    if ($serverConnection instanceof mysqli && $databaseCreated) {
        try {
            $serverConnection->query("DROP DATABASE `{$databaseName}`");
        } catch (Throwable $cleanupError) {
            error_log('Unable to remove failed E2E database: ' . $cleanupError->getMessage());
        }
    }
    if ($destinationCreated || is_dir($destinationRoot)) {
        try {
            phase5aRemoveCreatedTree($destinationRoot, $projectRoot);
        } catch (Throwable $cleanupError) {
            error_log('Unable to remove failed E2E folder: ' . $cleanupError->getMessage());
        }
    }
    fwrite(STDERR, "Phase 5A setup FAILED:\n\n");
    fwrite(STDERR, '- ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($serverConnection instanceof mysqli) {
        $serverConnection->close();
    }
    if (is_string($workDirectory) && is_dir($workDirectory)) {
        try {
            phase5aRemoveWorkDirectory($workDirectory);
        } catch (Throwable $cleanupError) {
            error_log('Unable to clean Phase 5A work files: ' . $cleanupError->getMessage());
        }
    }
}
