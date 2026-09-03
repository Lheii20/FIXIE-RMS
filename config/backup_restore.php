<?php

// Complete backup/restore engine for Fixie DRMS. This helper intentionally
// stores archives outside uploads/ and exposes no direct HTTP file path.

function drms_backup_project_root(): string
{
    return dirname(__DIR__);
}

function drms_backup_storage_root(): string
{
    return drms_backup_project_root() . DIRECTORY_SEPARATOR . 'storage';
}

function drms_backup_archive_root(): string
{
    return drms_backup_storage_root() . DIRECTORY_SEPARATOR . 'backups';
}

function drms_backup_work_root(): string
{
    return drms_backup_storage_root() . DIRECTORY_SEPARATOR . 'backup_work';
}

function drms_backup_restore_marker_path(): string
{
    return drms_backup_storage_root() . DIRECTORY_SEPARATOR .
        'restore_in_progress.json';
}

function drms_backup_ensure_directory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create protected backup storage.');
    }
}

function drms_backup_initialize_storage(): void
{
    drms_backup_ensure_directory(drms_backup_storage_root());
    drms_backup_ensure_directory(drms_backup_archive_root());
    drms_backup_ensure_directory(drms_backup_work_root());
}

function drms_backup_database_config(): array
{
    return [
        'host' => (string) (getenv('DB_HOST') ?: 'localhost'),
        'port' => (string) (getenv('DB_PORT') ?: '3306'),
        'user' => (string) (getenv('DB_USER') ?: 'root'),
        'password' => (string) (getenv('DB_PASS') ?: ''),
        'database' => (string) (getenv('DB_NAME') ?: 'fixie_drms'),
    ];
}

function drms_backup_safe_random(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

function drms_backup_create_work_directory(string $prefix): string
{
    drms_backup_initialize_storage();
    $safe_prefix = preg_replace('/[^a-z0-9_-]/i', '_', $prefix) ?: 'operation';
    $directory = drms_backup_work_root() . DIRECTORY_SEPARATOR .
        $safe_prefix . '_' . date('Ymd_His') . '_' . drms_backup_safe_random(5);
    drms_backup_ensure_directory($directory);
    return $directory;
}

function drms_backup_path_is_within(string $path, string $root): bool
{
    $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $normalized_root = rtrim(
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root),
        DIRECTORY_SEPARATOR
    );

    return $normalized_path === $normalized_root ||
        strncmp(
            $normalized_path,
            $normalized_root . DIRECTORY_SEPARATOR,
            strlen($normalized_root . DIRECTORY_SEPARATOR)
        ) === 0;
}

function drms_backup_remove_tree(string $directory): void
{
    if (!file_exists($directory)) {
        return;
    }

    $work_root = realpath(drms_backup_work_root());
    $resolved = realpath($directory);
    if (
        $work_root === false ||
        $resolved === false ||
        $resolved === $work_root ||
        !drms_backup_path_is_within($resolved, $work_root)
    ) {
        throw new RuntimeException('Refused to remove an unsafe working path.');
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
                throw new RuntimeException('Unable to remove a backup working directory.');
            }
        } elseif (!unlink($item->getPathname())) {
            throw new RuntimeException('Unable to remove a backup working file.');
        }
    }

    if (!rmdir($resolved)) {
        throw new RuntimeException('Unable to finish backup workspace cleanup.');
    }
}

function drms_backup_acquire_operation_lock()
{
    drms_backup_initialize_storage();
    $lock_path = drms_backup_storage_root() . DIRECTORY_SEPARATOR .
        'backup_restore.lock';
    $handle = fopen($lock_path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the backup operation lock.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('Another backup or restore operation is already running.');
    }

    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    return $handle;
}

function drms_backup_release_operation_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function drms_backup_find_binary(string $binary): string
{
    $environment_name = $binary === 'mysqldump'
        ? 'MYSQLDUMP_PATH'
        : 'MYSQL_PATH';
    $configured = trim((string) getenv($environment_name));

    $extension = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
    $candidates = [];
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    // PHP_BINARY points to php.exe under CLI, but it can point to Apache's
    // httpd.exe when this code runs from the web interface. Resolve XAMPP from
    // multiple stable locations instead of relying on that single value.
    $possible_roots = [];
    $project_root = drms_backup_project_root();
    $possible_roots[] = dirname(dirname($project_root));

    $document_root = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($document_root !== '') {
        $possible_roots[] = dirname($document_root);
    }

    $possible_roots[] = dirname(dirname(PHP_BINARY));
    $possible_roots = array_values(array_unique(array_filter($possible_roots)));

    foreach ($possible_roots as $possible_root) {
        $candidates[] = rtrim($possible_root, '/\\') . DIRECTORY_SEPARATOR .
            'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR .
            $binary . $extension;
    }

    $process_path = (string) getenv('PATH');
    foreach (explode(PATH_SEPARATOR, $process_path) as $path_directory) {
        $path_directory = trim($path_directory, " \t\n\r\0\x0B\"");
        if ($path_directory !== '') {
            $candidates[] = rtrim($path_directory, '/\\') .
                DIRECTORY_SEPARATOR . $binary . $extension;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        sprintf('%s executable was not found.', $binary)
    );
}

function drms_backup_option_value(string $value): string
{
    if (preg_match('/[\r\n]/', $value)) {
        throw new RuntimeException('Invalid database client configuration.');
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function drms_backup_create_client_option_file(
    string $work_directory,
    array $database_config
): string {
    $path = $work_directory . DIRECTORY_SEPARATOR . 'mysql-client.ini';
    $content = "[client]\r\n" .
        'host=' . drms_backup_option_value($database_config['host']) . "\r\n" .
        'port=' . drms_backup_option_value($database_config['port']) . "\r\n" .
        'user=' . drms_backup_option_value($database_config['user']) . "\r\n" .
        'password=' . drms_backup_option_value($database_config['password']) . "\r\n" .
        "protocol=tcp\r\ndefault-character-set=utf8mb4\r\n";

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to prepare database client credentials.');
    }
    @chmod($path, 0600);
    return $path;
}

function drms_backup_run_process(
    array $command,
    ?string $stdin_path = null,
    ?string $stdout_path = null
): array {
    $descriptors = [
        0 => $stdin_path !== null
            ? ['file', $stdin_path, 'rb']
            : ['pipe', 'r'],
        1 => $stdout_path !== null
            ? ['file', $stdout_path, 'wb']
            : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes = [];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        drms_backup_project_root(),
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the database backup utility.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stdout = '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $stderr = '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }

    $exit_code = proc_close($process);
    return [
        'exit_code' => (int) $exit_code,
        'stdout' => $stdout,
        'stderr' => trim($stderr),
    ];
}

function drms_backup_dump_database(
    string $destination,
    string $work_directory
): void {
    $config = drms_backup_database_config();
    $option_file = drms_backup_create_client_option_file(
        $work_directory,
        $config
    );

    try {
        $command = [
            drms_backup_find_binary('mysqldump'),
            '--defaults-extra-file=' . $option_file,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--add-drop-table',
            $config['database'],
        ];
        $result = drms_backup_run_process($command, null, $destination);
        if (
            $result['exit_code'] !== 0 ||
            !is_file($destination) ||
            filesize($destination) < 1
        ) {
            throw new RuntimeException(
                'Database export failed: ' . ($result['stderr'] ?: 'unknown error')
            );
        }
    } finally {
        if (is_file($option_file)) {
            @unlink($option_file);
        }
    }
}

function drms_backup_import_database(
    string $sql_path,
    string $work_directory
): void {
    if (!is_file($sql_path) || filesize($sql_path) < 1) {
        throw new RuntimeException('The database restore file is missing or empty.');
    }

    $config = drms_backup_database_config();
    $option_file = drms_backup_create_client_option_file(
        $work_directory,
        $config
    );

    try {
        $command = [
            drms_backup_find_binary('mysql'),
            '--defaults-extra-file=' . $option_file,
            '--batch',
            '--default-character-set=utf8mb4',
            $config['database'],
        ];
        $result = drms_backup_run_process($command, $sql_path, null);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                'Database import failed: ' . ($result['stderr'] ?: 'unknown error')
            );
        }
    } finally {
        if (is_file($option_file)) {
            @unlink($option_file);
        }
    }
}

function drms_backup_relative_upload_files(): array
{
    $upload_root = drms_backup_project_root() . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $upload_root,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Symbolic links are not allowed in uploads.');
        }
        if (!$item->isFile() || $item->getFilename() === '.htaccess') {
            continue;
        }

        $absolute = $item->getPathname();
        $relative = substr($absolute, strlen($upload_root) + 1);
        $relative = str_replace('\\', '/', $relative);
        if (!drms_backup_archive_path_is_safe('uploads/' . $relative)) {
            throw new RuntimeException('An unsafe upload path was found.');
        }

        $files[] = [
            'absolute' => $absolute,
            'path' => 'uploads/' . $relative,
            'size' => (int) $item->getSize(),
            'sha256' => hash_file('sha256', $absolute),
        ];
    }

    usort($files, static fn(array $a, array $b): int =>
        strcmp($a['path'], $b['path'])
    );
    return $files;
}

function drms_backup_archive_path_is_safe(string $path): bool
{
    if (
        $path === '' ||
        str_contains($path, "\0") ||
        str_contains($path, '\\') ||
        str_starts_with($path, '/') ||
        preg_match('/^[A-Za-z]:/', $path)
    ) {
        return false;
    }

    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function drms_backup_create_package(string $type = 'manual'): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZIP extension is not available.');
    }
    if (!in_array($type, ['manual', 'pre_restore'], true)) {
        throw new InvalidArgumentException('Invalid backup package type.');
    }

    drms_backup_initialize_storage();
    $work_directory = drms_backup_create_work_directory('create');
    $database_path = $work_directory . DIRECTORY_SEPARATOR . 'database.sql';
    $partial_path = '';

    try {
        drms_backup_dump_database($database_path, $work_directory);
        $upload_files = drms_backup_relative_upload_files();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $filename = sprintf(
            'fixie_drms_%s_%s_%s.zip',
            $type,
            date('Ymd_His'),
            drms_backup_safe_random(4)
        );
        $final_path = drms_backup_archive_root() . DIRECTORY_SEPARATOR . $filename;
        $partial_path = $final_path . '.partial';

        $zip = new ZipArchive();
        if ($zip->open($partial_path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Unable to create the backup archive.');
        }

        if (!$zip->addFile($database_path, 'database.sql')) {
            $zip->close();
            throw new RuntimeException('Unable to add the database export to the archive.');
        }

        $manifest_files = [];
        $total_upload_bytes = 0;
        foreach ($upload_files as $file) {
            if (!$zip->addFile($file['absolute'], $file['path'])) {
                $zip->close();
                throw new RuntimeException('Unable to add an uploaded record to the archive.');
            }
            $manifest_files[] = [
                'path' => $file['path'],
                'size' => $file['size'],
                'sha256' => $file['sha256'],
            ];
            $total_upload_bytes += $file['size'];
        }

        $database_config = drms_backup_database_config();
        $manifest = [
            'format' => 'FIXIE_DRMS_FULL_BACKUP',
            'format_version' => 1,
            'backup_type' => $type,
            'created_at_utc' => $timestamp,
            'application' => 'Record Management with Decision Support System for Fixie Computer Ventures',
            'database' => [
                'name' => $database_config['database'],
                'file' => 'database.sql',
                'size' => (int) filesize($database_path),
                'sha256' => hash_file('sha256', $database_path),
            ],
            'uploads' => [
                'file_count' => count($manifest_files),
                'total_bytes' => $total_upload_bytes,
                'files' => $manifest_files,
            ],
        ];
        $manifest_json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (!$zip->addFromString('manifest.json', $manifest_json)) {
            $zip->close();
            throw new RuntimeException('Unable to add the backup manifest.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize the backup archive.');
        }

        drms_backup_verify_package($partial_path, $database_config['database']);
        if (!rename($partial_path, $final_path)) {
            throw new RuntimeException('Unable to publish the completed backup archive.');
        }

        return [
            'filename' => $filename,
            'path' => $final_path,
            'size' => (int) filesize($final_path),
            'manifest' => $manifest,
        ];
    } finally {
        if ($partial_path !== '' && is_file($partial_path)) {
            @unlink($partial_path);
        }
        if (is_dir($work_directory)) {
            try {
                drms_backup_remove_tree($work_directory);
            } catch (Throwable $cleanup_error) {
                error_log('Backup workspace cleanup failed: ' . $cleanup_error->getMessage());
            }
        }
    }
}

function drms_backup_hash_zip_entry(ZipArchive $zip, string $name): string
{
    $stream = $zip->getStream($name);
    if ($stream === false) {
        throw new RuntimeException('Unable to read a backup archive entry.');
    }
    $context = hash_init('sha256');
    hash_update_stream($context, $stream);
    fclose($stream);
    return hash_final($context);
}

function drms_backup_verify_package(
    string $archive_path,
    ?string $expected_database = null
): array {
    if (!is_file($archive_path) || !is_readable($archive_path)) {
        throw new RuntimeException('The selected backup archive is unavailable.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZIP extension is not available.');
    }

    $zip = new ZipArchive();
    if ($zip->open($archive_path, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('The selected backup archive is damaged.');
    }

    try {
        $manifest_stat = $zip->statName('manifest.json');
        if (!$manifest_stat || (int) $manifest_stat['size'] > 16 * 1024 * 1024) {
            throw new RuntimeException('The backup manifest is missing or invalid.');
        }

        $manifest_json = $zip->getFromName('manifest.json');
        $manifest = json_decode(
            (string) $manifest_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($manifest) ||
            ($manifest['format'] ?? '') !== 'FIXIE_DRMS_FULL_BACKUP' ||
            (int) ($manifest['format_version'] ?? 0) !== 1
        ) {
            throw new RuntimeException('This is not a supported Fixie DRMS backup.');
        }

        $database = $manifest['database'] ?? null;
        if (
            !is_array($database) ||
            ($database['file'] ?? '') !== 'database.sql' ||
            !preg_match('/^[a-f0-9]{64}$/', (string) ($database['sha256'] ?? '')) ||
            (int) ($database['size'] ?? 0) < 1 ||
            (int) ($database['size'] ?? 0) > 5 * 1024 * 1024 * 1024
        ) {
            throw new RuntimeException('The database manifest is invalid.');
        }
        if (
            $expected_database !== null &&
            (string) ($database['name'] ?? '') !== $expected_database
        ) {
            throw new RuntimeException('The backup belongs to a different database.');
        }

        $upload_section = $manifest['uploads'] ?? null;
        $upload_files = is_array($upload_section)
            ? ($upload_section['files'] ?? null)
            : null;
        if (!is_array($upload_files) || count($upload_files) > 100000) {
            throw new RuntimeException('The upload manifest is invalid.');
        }

        $expected_entries = [
            'manifest.json' => ['size' => (int) $manifest_stat['size']],
            'database.sql' => [
                'size' => (int) $database['size'],
                'sha256' => (string) $database['sha256'],
            ],
        ];
        $computed_upload_bytes = 0;
        foreach ($upload_files as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            $size = is_array($file) ? (int) ($file['size'] ?? -1) : -1;
            $hash = is_array($file) ? (string) ($file['sha256'] ?? '') : '';
            if (
                !str_starts_with($path, 'uploads/') ||
                !drms_backup_archive_path_is_safe($path) ||
                basename($path) === '.htaccess' ||
                $size < 0 ||
                !preg_match('/^[a-f0-9]{64}$/', $hash) ||
                isset($expected_entries[$path])
            ) {
                throw new RuntimeException('An upload manifest entry is invalid.');
            }
            $expected_entries[$path] = [
                'size' => $size,
                'sha256' => $hash,
            ];
            $computed_upload_bytes += $size;
            if ($computed_upload_bytes > 10 * 1024 * 1024 * 1024) {
                throw new RuntimeException('The backup archive exceeds the restore limit.');
            }
        }

        if (
            (int) ($upload_section['file_count'] ?? -1) !== count($upload_files) ||
            (int) ($upload_section['total_bytes'] ?? -1) !== $computed_upload_bytes
        ) {
            throw new RuntimeException('The upload manifest totals do not match.');
        }

        $seen = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if (
                !drms_backup_archive_path_is_safe($name) ||
                !isset($expected_entries[$name]) ||
                isset($seen[$name]) ||
                (int) ($stat['size'] ?? -1) !== $expected_entries[$name]['size']
            ) {
                throw new RuntimeException('The backup archive contains an unexpected entry.');
            }

            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $operating_system = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operating_system, $attributes)) {
                    $file_type = ($attributes >> 16) & 0170000;
                    if ($file_type === 0120000) {
                        throw new RuntimeException('Symbolic links are not allowed in backups.');
                    }
                }
            }
            $seen[$name] = true;
        }

        if (count($seen) !== count($expected_entries)) {
            throw new RuntimeException('The backup archive is incomplete.');
        }

        foreach ($expected_entries as $name => $expected) {
            if (!isset($expected['sha256'])) {
                continue;
            }
            if (!hash_equals(
                $expected['sha256'],
                drms_backup_hash_zip_entry($zip, $name)
            )) {
                throw new RuntimeException(
                    'Backup integrity verification failed for ' . $name . '.'
                );
            }
        }

        return [
            'manifest' => $manifest,
            'entries' => $expected_entries,
        ];
    } catch (JsonException $error) {
        throw new RuntimeException('The backup manifest is not valid JSON.', 0, $error);
    } finally {
        $zip->close();
    }
}

function drms_backup_extract_verified_package(
    string $archive_path,
    array $verification,
    string $work_directory
): array {
    $zip = new ZipArchive();
    if ($zip->open($archive_path, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('The selected backup archive could not be reopened.');
    }

    $database_path = $work_directory . DIRECTORY_SEPARATOR . 'database.sql';
    $uploads_path = $work_directory . DIRECTORY_SEPARATOR . 'uploads';
    drms_backup_ensure_directory($uploads_path);

    try {
        foreach ($verification['entries'] as $entry => $expected) {
            if ($entry === 'manifest.json') {
                continue;
            }
            $destination = $entry === 'database.sql'
                ? $database_path
                : $work_directory . DIRECTORY_SEPARATOR .
                    str_replace('/', DIRECTORY_SEPARATOR, $entry);
            $destination_directory = dirname($destination);
            drms_backup_ensure_directory($destination_directory);

            $source = $zip->getStream($entry);
            $target = fopen($destination, 'wb');
            if ($source === false || $target === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new RuntimeException('Unable to extract a verified backup entry.');
            }

            $copied = stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
            if ($copied === false || $copied !== $expected['size']) {
                throw new RuntimeException('A backup entry was not extracted completely.');
            }
            if (
                isset($expected['sha256']) &&
                !hash_equals($expected['sha256'], hash_file('sha256', $destination))
            ) {
                throw new RuntimeException('An extracted backup entry failed verification.');
            }
        }
    } finally {
        $zip->close();
    }

    $live_security_file = drms_backup_project_root() . DIRECTORY_SEPARATOR .
        'uploads' . DIRECTORY_SEPARATOR . '.htaccess';
    $staged_security_file = $uploads_path . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($live_security_file)) {
        if (!copy($live_security_file, $staged_security_file)) {
            throw new RuntimeException('Unable to preserve upload access protection.');
        }
    } else {
        $deny_rules = "Options -Indexes\n\n<IfModule mod_authz_core.c>\n" .
            "    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n" .
            "    Deny from all\n</IfModule>\n";
        if (file_put_contents($staged_security_file, $deny_rules, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create upload access protection.');
        }
    }

    return [
        'database_path' => $database_path,
        'uploads_path' => $uploads_path,
    ];
}

function drms_backup_archive_name_is_valid(string $filename): bool
{
    return (bool) preg_match(
        '/^fixie_drms_(manual|pre_restore)_\d{8}_\d{6}_[a-f0-9]{8}\.zip$/',
        $filename
    );
}

function drms_backup_resolve_archive(string $filename): string
{
    $filename = basename($filename);
    if (!drms_backup_archive_name_is_valid($filename)) {
        throw new RuntimeException('Invalid backup selection.');
    }

    drms_backup_initialize_storage();
    $root = realpath(drms_backup_archive_root());
    $path = realpath(drms_backup_archive_root() . DIRECTORY_SEPARATOR . $filename);
    if (
        $root === false ||
        $path === false ||
        !is_file($path) ||
        !drms_backup_path_is_within($path, $root)
    ) {
        throw new RuntimeException('The selected backup archive was not found.');
    }
    return $path;
}

function drms_backup_read_manifest_summary(string $archive_path): array
{
    $zip = new ZipArchive();
    if ($zip->open($archive_path) !== true) {
        return ['valid' => false];
    }

    try {
        $json = $zip->getFromName('manifest.json');
        if ($json === false || strlen($json) > 16 * 1024 * 1024) {
            return ['valid' => false];
        }
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($manifest) ||
            ($manifest['format'] ?? '') !== 'FIXIE_DRMS_FULL_BACKUP' ||
            (int) ($manifest['format_version'] ?? 0) !== 1
        ) {
            return ['valid' => false];
        }
        return ['valid' => true, 'manifest' => $manifest];
    } catch (Throwable $error) {
        return ['valid' => false];
    } finally {
        $zip->close();
    }
}

function drms_backup_list_packages(): array
{
    drms_backup_initialize_storage();
    $packages = [];
    $iterator = new DirectoryIterator(drms_backup_archive_root());
    foreach ($iterator as $item) {
        if (
            !$item->isFile() ||
            !drms_backup_archive_name_is_valid($item->getFilename())
        ) {
            continue;
        }
        $summary = drms_backup_read_manifest_summary($item->getPathname());
        $manifest = $summary['manifest'] ?? [];
        $packages[] = [
            'filename' => $item->getFilename(),
            'size' => (int) $item->getSize(),
            'modified_at' => (int) $item->getMTime(),
            'valid_manifest' => (bool) ($summary['valid'] ?? false),
            'backup_type' => (string) ($manifest['backup_type'] ?? 'unknown'),
            'created_at_utc' => (string) ($manifest['created_at_utc'] ?? ''),
            'upload_count' => (int) ($manifest['uploads']['file_count'] ?? 0),
            'upload_bytes' => (int) ($manifest['uploads']['total_bytes'] ?? 0),
            'database_bytes' => (int) ($manifest['database']['size'] ?? 0),
        ];
    }

    usort($packages, static fn(array $a, array $b): int =>
        $b['modified_at'] <=> $a['modified_at']
    );
    return $packages;
}

function drms_backup_write_restore_marker(int $user_id, string $filename): void
{
    drms_backup_initialize_storage();
    $payload = json_encode([
        'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'user_id' => $user_id,
        'backup' => basename($filename),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (file_put_contents(
        drms_backup_restore_marker_path(),
        $payload,
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Unable to activate restore maintenance mode.');
    }
}

function drms_backup_clear_restore_marker(): void
{
    $path = drms_backup_restore_marker_path();
    if (is_file($path) && !@unlink($path)) {
        error_log('Unable to remove the restore maintenance marker: ' . $path);
    }
}

function drms_backup_restore_package(string $archive_path): array
{
    $database_config = drms_backup_database_config();
    $verification = drms_backup_verify_package(
        $archive_path,
        $database_config['database']
    );

    // A verified rollback point is mandatory before touching either live store.
    $safety_backup = drms_backup_create_package('pre_restore');
    $work_directory = drms_backup_create_work_directory('restore');
    $previous_uploads = $work_directory . DIRECTORY_SEPARATOR . 'previous_uploads';
    $failed_uploads = $work_directory . DIRECTORY_SEPARATOR . 'failed_uploads';
    $live_uploads = drms_backup_project_root() . DIRECTORY_SEPARATOR . 'uploads';
    $uploads_swapped = false;

    try {
        $extracted = drms_backup_extract_verified_package(
            $archive_path,
            $verification,
            $work_directory
        );

        if (is_dir($live_uploads) && !rename($live_uploads, $previous_uploads)) {
            throw new RuntimeException('Unable to stage the current upload repository.');
        }
        if (!rename($extracted['uploads_path'], $live_uploads)) {
            if (is_dir($previous_uploads)) {
                @rename($previous_uploads, $live_uploads);
            }
            throw new RuntimeException('Unable to activate the restored upload repository.');
        }
        $uploads_swapped = true;

        try {
            drms_backup_import_database(
                $extracted['database_path'],
                $work_directory
            );
        } catch (Throwable $restore_error) {
            // Put the current physical repository back before rolling the
            // database back to the mandatory pre-restore package.
            if (is_dir($live_uploads)) {
                @rename($live_uploads, $failed_uploads);
            }
            if (is_dir($previous_uploads)) {
                @rename($previous_uploads, $live_uploads);
            }
            $uploads_swapped = false;

            $rollback_verification = drms_backup_verify_package(
                $safety_backup['path'],
                $database_config['database']
            );
            $rollback_directory = drms_backup_create_work_directory('rollback');
            try {
                $rollback = drms_backup_extract_verified_package(
                    $safety_backup['path'],
                    $rollback_verification,
                    $rollback_directory
                );
                drms_backup_import_database(
                    $rollback['database_path'],
                    $rollback_directory
                );
            } catch (Throwable $rollback_error) {
                error_log(
                    'CRITICAL restore rollback failure: ' .
                    $rollback_error->getMessage()
                );
                throw new RuntimeException(
                    'Restore and automatic database rollback both failed. ' .
                    'Use the generated pre-restore backup for manual recovery.',
                    0,
                    $restore_error
                );
            } finally {
                if (is_dir($rollback_directory)) {
                    try {
                        drms_backup_remove_tree($rollback_directory);
                    } catch (Throwable $cleanup_error) {
                        error_log('Rollback workspace cleanup failed: ' . $cleanup_error->getMessage());
                    }
                }
            }

            throw new RuntimeException(
                'Restore failed; the automatic rollback restored the previous system state.',
                0,
                $restore_error
            );
        }

        if (is_dir($previous_uploads)) {
            try {
                drms_backup_remove_tree($previous_uploads);
            } catch (Throwable $cleanup_error) {
                error_log('Previous upload cleanup failed: ' . $cleanup_error->getMessage());
            }
        }

        return [
            'manifest' => $verification['manifest'],
            'safety_backup' => $safety_backup,
        ];
    } finally {
        if (!$uploads_swapped && !is_dir($live_uploads) && is_dir($previous_uploads)) {
            @rename($previous_uploads, $live_uploads);
        }
        if (is_dir($work_directory)) {
            try {
                drms_backup_remove_tree($work_directory);
            } catch (Throwable $cleanup_error) {
                error_log('Restore workspace cleanup failed: ' . $cleanup_error->getMessage());
            }
        }
    }
}

function drms_backup_human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}
