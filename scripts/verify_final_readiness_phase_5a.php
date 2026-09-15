<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? rtrim((string) $argv[1], "/\\")
    : dirname(__DIR__);
$failures = [];
$passes = [];

function phase5aVerifyDirectoryManifest(string $directory): array
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
            throw new RuntimeException('Unable to hash upload file: ' . $relative);
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

function phase5aVerifyTableExists(mysqli $conn, string $table): bool
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

try {
    $resolvedRoot = realpath($projectRoot);
    if ($resolvedRoot === false) {
        throw new RuntimeException('The E2E project folder does not exist.');
    }
    $projectRoot = $resolvedRoot;
    $markerPath = $projectRoot . DIRECTORY_SEPARATOR
        . '.fixie-e2e-environment.json';
    if (!is_file($markerPath)) {
        throw new RuntimeException(
            'The Phase 5A environment marker is missing. Refused to verify an unmarked project.'
        );
    }
    $markerJson = file_get_contents($markerPath);
    $marker = json_decode(
        (string) $markerJson,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (($marker['format'] ?? '') !== 'FIXIE_DRMS_ISOLATED_E2E') {
        throw new RuntimeException('Invalid Phase 5A environment marker.');
    }
    if ((int) ($marker['format_version'] ?? 0) !== 1) {
        throw new RuntimeException('Unsupported Phase 5A environment version.');
    }
    if (
        strcasecmp(
            (string) ($marker['test_project'] ?? ''),
            $projectRoot
        ) !== 0
    ) {
        throw new RuntimeException('The E2E marker does not match this project path.');
    }
    if (
        strcasecmp(
            (string) ($marker['source_project'] ?? ''),
            $projectRoot
        ) === 0
    ) {
        throw new RuntimeException('The source and E2E project paths are not isolated.');
    }
    $passes[] = 'The application folder is explicitly marked as an isolated E2E copy.';

    require_once $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'runtime.php';
    $runtime = drms_runtime_config();
    $testDatabaseName = (string) ($marker['test_database'] ?? '');
    if (
        $testDatabaseName === '' ||
        stripos($testDatabaseName, 'e2e') === false ||
        strcasecmp(
            $testDatabaseName,
            (string) ($marker['source_database'] ?? '')
        ) === 0 ||
        (string) $runtime['database']['name'] !== $testDatabaseName
    ) {
        $failures[] = 'Runtime database configuration is not isolated from the source database.';
    } else {
        $passes[] = 'Runtime database points only to the marked E2E database.';
    }

    if (
        (string) $runtime['app']['url'] !==
            (string) ($marker['application_url'] ?? '') ||
        (string) $runtime['app']['environment'] !== 'production' ||
        (string) $runtime['app']['hosting_profile'] !== 'local'
    ) {
        $failures[] = 'The isolated application URL or runtime profile is incorrect.';
    } else {
        $passes[] = 'The test URL uses production-style error handling on the local profile.';
    }

    if (
        trim((string) $runtime['mail']['username']) !== '' ||
        trim((string) $runtime['mail']['password']) !== '' ||
        trim((string) $runtime['mail']['from']) !== '' ||
        (bool) $runtime['mail']['required'] !== false
    ) {
        $failures[] = 'SMTP is not fully disabled in the E2E environment.';
    } else {
        $passes[] = 'SMTP is disabled, preventing test emails from being sent.';
    }
    if (
        (bool) $runtime['features']['scheduled_maintenance'] !== false ||
        (bool) $runtime['features']['server_backup'] !== false
    ) {
        $failures[] = 'Background maintenance or server backup remains enabled in E2E.';
    } else {
        $passes[] = 'Scheduled maintenance and server backup are disabled in E2E.';
    }

    $sessionName = (string) ($marker['session_name'] ?? '');
    $cookiePath = (string) ($marker['session_cookie_path'] ?? '');
    $htaccess = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . '.htaccess'
    );
    $userIni = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . '.user.ini'
    );
    foreach (
        [
            '# FIXIE_PHASE_5A_E2E_SESSION',
            'session.name ' . $sessionName,
            'session.cookie_path ' . $cookiePath,
        ] as $apacheMarker
    ) {
        if (!str_contains($htaccess, $apacheMarker)) {
            $failures[] = '.htaccess is missing isolated session setting: '
                . $apacheMarker;
        }
    }
    foreach (
        [
            '# FIXIE_PHASE_5A_E2E_SESSION',
            'session.name = "' . $sessionName . '"',
            'session.cookie_path = "' . $cookiePath . '"',
        ] as $userIniMarker
    ) {
        if (!str_contains($userIni, $userIniMarker)) {
            $failures[] = '.user.ini is missing isolated session setting: '
                . $userIniMarker;
        }
    }
    if ($sessionName === '' || !str_starts_with($sessionName, 'FIXIEDRMSE2E')) {
        $failures[] = 'The E2E session cookie name is invalid.';
    } else {
        $passes[] = 'Apache and CGI session settings isolate the E2E browser login.';
    }

    $uploadManifest = phase5aVerifyDirectoryManifest(
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads'
    );
    $expectedUploadManifest = [
        'file_count' => (int) ($marker['uploads_file_count'] ?? -1),
        'total_bytes' => (int) ($marker['uploads_total_bytes'] ?? -1),
        'sha256' => (string) ($marker['uploads_manifest_sha256'] ?? ''),
    ];
    if ($uploadManifest !== $expectedUploadManifest) {
        $failures[] = 'The copied upload repository no longer matches its Phase 5A manifest.';
    } else {
        $passes[] = $uploadManifest['file_count']
            . ' copied upload file(s) match the isolated snapshot manifest.';
    }

    require $projectRoot . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('The isolated database connection was not created.');
    }
    $databaseResult = $conn->query('SELECT DATABASE() AS database_name');
    $connectedDatabase = (string) (
        $databaseResult->fetch_assoc()['database_name'] ?? ''
    );
    $databaseResult->free();
    if ($connectedDatabase !== $testDatabaseName) {
        $failures[] = 'The verifier connected to an unexpected database.';
    }

    $tableCountResult = $conn->query(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE()'
    );
    $tableCount = (int) (
        $tableCountResult->fetch_assoc()['total'] ?? 0
    );
    $tableCountResult->free();
    if ($tableCount !== (int) ($marker['database_table_count'] ?? -1)) {
        $failures[] = 'The isolated database table count does not match its setup marker.';
    } else {
        $passes[] = $tableCount . ' database table(s) are present in the isolated copy.';
    }

    $sessionResult = $conn->query(
        "SELECT COUNT(*) AS total FROM users "
        . "WHERE COALESCE(session_token, '') <> ''"
    );
    $activeSessions = (int) ($sessionResult->fetch_assoc()['total'] ?? 0);
    $sessionResult->free();
    if ($activeSessions !== 0) {
        $failures[] = 'Cloned active login sessions were not cleared.';
    } else {
        $passes[] = 'No active login session was copied into the E2E database.';
    }

    foreach (
        ['login_attempts', 'otp_auth_tokens', 'password_reset_otps', 'password_resets']
        as $ephemeralTable
    ) {
        if (!phase5aVerifyTableExists($conn, $ephemeralTable)) {
            continue;
        }
        $result = $conn->query("SELECT COUNT(*) AS total FROM `{$ephemeralTable}`");
        $total = (int) ($result->fetch_assoc()['total'] ?? 0);
        $result->free();
        if ($total !== 0) {
            $failures[] = "Ephemeral authentication table {$ephemeralTable} is not empty.";
        }
    }
    $passes[] = 'Temporary login and OTP records were cleared from the test copy.';
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

if ($failures) {
    fwrite(STDERR, "Phase 5A verification FAILED:\n\n");
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo "Phase 5A verification PASSED.\n\n";
foreach ($passes as $pass) {
    echo '- ' . $pass . "\n";
}
echo "\nThe isolated environment is ready for controlled end-to-end testing.\n";
echo "This verifier is read-only and did not create workflow records.\n";
