<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

/**
 * Phase 5M3A maintenance engine.
 *
 * Important safeguards:
 * - CLI runner only (enforced by cron_maintenance.php).
 * - A non-blocking file lock prevents overlapping runs.
 * - Each task is isolated so one failure does not hide later results.
 * - --dry-run never changes the database or document files.
 * - Official Records are never destroyed here; they are only moved through
 *   retention states and flagged for an authorized disposition decision.
 */

function drms_maintenance_default_options(): array
{
    return [
        'dry_run' => false,
        'quiet' => false,
        'task' => 'all',
    ];
}

function drms_maintenance_task_names(): array
{
    return [
        'archive_due_records',
        'flag_disposition_due',
        'send_retention_alerts',
        'purge_recycled_working_files',
        'sync_collection_reminders',
        'cleanup_security_data',
    ];
}

function drms_maintenance_parse_arguments(array $arguments): array
{
    $options = drms_maintenance_default_options();

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($argument === '--quiet') {
            $options['quiet'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (strpos($argument, '--task=') === 0) {
            $options['task'] = trim(substr($argument, 7));
            continue;
        }

        throw new InvalidArgumentException('Unknown maintenance argument: ' . $argument);
    }

    $allowed = array_merge(['all'], drms_maintenance_task_names());
    if (!in_array($options['task'], $allowed, true)) {
        throw new InvalidArgumentException(
            'Unknown task "' . $options['task'] . '". Allowed tasks: ' . implode(', ', $allowed)
        );
    }

    return $options;
}

function drms_maintenance_help_text(): string
{
    return implode(PHP_EOL, [
        'Fixie DRMS scheduled maintenance',
        '',
        'Usage:',
        '  php cron_maintenance.php [--dry-run] [--quiet] [--task=TASK]',
        '',
        'Options:',
        '  --dry-run       Evaluate work without changing the database or files.',
        '  --quiet         Suppress normal console output; errors still appear.',
        '  --task=TASK     Run one task only. Default: all.',
        '  --help, -h      Show this help.',
        '',
        'Tasks:',
        '  ' . implode(PHP_EOL . '  ', drms_maintenance_task_names()),
        '',
    ]);
}

function drms_maintenance_emit(string $message, bool $quiet = false, bool $error = false): void
{
    if ($quiet && !$error) {
        return;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    if ($error && defined('STDERR')) {
        fwrite(STDERR, $line);
        return;
    }

    echo $line;
}

function drms_maintenance_ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create maintenance directory: ' . $path);
    }
}

function drms_maintenance_acquire_lock(string $projectRoot)
{
    $maintenanceDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'maintenance';
    drms_maintenance_ensure_directory($maintenanceDirectory);

    $lockPath = $maintenanceDirectory . DIRECTORY_SEPARATOR . 'maintenance.lock';
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the maintenance lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, json_encode([
        'pid' => getmypid(),
        'started_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES));
    fflush($handle);

    return $handle;
}

function drms_maintenance_release_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function drms_maintenance_write_report(string $projectRoot, array $report): string
{
    $month = date('Y-m');
    $logDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR .
        'maintenance' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $month;
    drms_maintenance_ensure_directory($logDirectory);

    $runId = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($report['run_id'] ?? uniqid('run_', true)));
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode the maintenance report.');
    }

    $reportPath = $logDirectory . DIRECTORY_SEPARATOR . $runId . '.json';
    if (file_put_contents($reportPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the maintenance report.');
    }

    $latestPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR .
        'maintenance' . DIRECTORY_SEPARATOR . 'latest.json';
    file_put_contents($latestPath, $json . PHP_EOL, LOCK_EX);

    return $reportPath;
}

function drms_maintenance_count(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

function drms_maintenance_task_archive_due(mysqli $conn, bool $dryRun): array
{
    $where = "d.record_phase = 'Official'
        AND d.status = 'Active'
        AND d.is_legal_hold = 0
        AND DATE_ADD(
            DATE_ADD(COALESCE(d.declared_at, d.uploaded_at), INTERVAL COALESCE(p.active_years, 0) YEAR),
            INTERVAL COALESCE(p.active_months, 0) MONTH
        ) <= NOW()";

    $eligible = drms_maintenance_count($conn, "
        SELECT COUNT(*) AS total
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        INNER JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
        WHERE $where
    ");

    $affected = 0;
    if (!$dryRun && $eligible > 0) {
        $conn->query("
            UPDATE documents d
            LEFT JOIN document_categories dc ON d.category = dc.sub_category
            INNER JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
            SET d.status = 'Archived'
            WHERE $where
        ");
        $affected = $conn->affected_rows;
    }

    return [
        'evaluated' => $eligible,
        'affected' => $affected,
        'message' => $dryRun
            ? "$eligible Official Record(s) would move to Archived."
            : $affected . ' Official Record(s) moved to Archived.',
    ];
}

function drms_maintenance_task_flag_disposition(mysqli $conn, bool $dryRun): array
{
    $where = "d.record_phase = 'Official'
        AND d.status = 'Archived'
        AND d.disposition_status = 'Pending'
        AND d.is_legal_hold = 0
        AND DATE_ADD(
            DATE_ADD(
                COALESCE(d.declared_at, d.uploaded_at),
                INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR
            ),
            INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH
        ) <= NOW()";

    $eligible = drms_maintenance_count($conn, "
        SELECT COUNT(*) AS total
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        INNER JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
        WHERE $where
    ");

    $affected = 0;
    if (!$dryRun && $eligible > 0) {
        $conn->query("
            UPDATE documents d
            LEFT JOIN document_categories dc ON d.category = dc.sub_category
            INNER JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
            SET d.disposition_status = 'Ready for Disposition',
                d.dss_recommendation = CASE
                    WHEN p.action_after_retention = 'Permanent Archive'
                        THEN 'Retention complete: decide whether to retain this record permanently.'
                    ELSE 'Retention complete: decide whether this record can be destroyed.'
                END
            WHERE $where
        ");
        $affected = $conn->affected_rows;
    }

    return [
        'evaluated' => $eligible,
        'affected' => $affected,
        'message' => $dryRun
            ? "$eligible Official Record(s) would be marked Ready for Disposition."
            : $affected . ' Official Record(s) marked Ready for Disposition.',
    ];
}

function drms_maintenance_insert_notification(
    mysqli $conn,
    int $userId,
    string $role,
    string $message,
    string $key,
    string $targetUrl
): bool {
    $statement = $conn->prepare("
        INSERT INTO notifications
            (target_role, recipient_user_id, message, notification_key, target_url)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE notif_id = LAST_INSERT_ID(notif_id)
    ");
    $statement->bind_param('sisss', $role, $userId, $message, $key, $targetUrl);
    $statement->execute();
    $created = $statement->affected_rows === 1;
    $notificationId = (int) $conn->insert_id;
    $statement->close();

    if ($notificationId < 1) {
        throw new RuntimeException('Unable to resolve the retention notification.');
    }

    $state = $conn->prepare("
        INSERT IGNORE INTO notification_user_states
            (notif_id, user_id, is_read, is_pinned, is_deleted)
        VALUES (?, ?, 0, 0, 0)
    ");
    $state->bind_param('ii', $notificationId, $userId);
    $state->execute();
    $state->close();

    return $created;
}

function drms_maintenance_task_retention_alerts(mysqli $conn, bool $dryRun): array
{
    $result = $conn->query("
        SELECT
            d.doc_id,
            d.file_name,
            DATE_ADD(
                DATE_ADD(
                    COALESCE(d.declared_at, d.uploaded_at),
                    INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR
                ),
                INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH
            ) AS disposition_at,
            DATEDIFF(
                DATE_ADD(
                    DATE_ADD(
                        COALESCE(d.declared_at, d.uploaded_at),
                        INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR
                    ),
                    INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH
                ),
                CURDATE()
            ) AS days_remaining
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        INNER JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
        WHERE d.record_phase = 'Official'
          AND d.disposition_status = 'Pending'
          AND d.is_legal_hold = 0
        HAVING days_remaining IN (30, 15)
        ORDER BY d.doc_id
    ");

    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }

    $managers = [];
    $managerResult = $conn->query("
        SELECT user_id, role
        FROM users
        WHERE role IN ('Admin', 'GM', 'President')
          AND status = 'Active'
        ORDER BY user_id
    ");
    while ($manager = $managerResult->fetch_assoc()) {
        $managers[] = $manager;
    }

    $candidateCount = count($documents) * count($managers);
    if ($dryRun) {
        return [
            'evaluated' => count($documents),
            'affected' => 0,
            'message' => "$candidateCount recipient alert(s) would be evaluated for " . count($documents) . ' record(s).',
        ];
    }

    $created = 0;
    foreach ($documents as $document) {
        $days = (int) $document['days_remaining'];
        $date = date('Y-m-d', strtotime((string) $document['disposition_at']));
        $message = 'Retention alert: Official Record ' . $document['file_name'] .
            " reaches its retention date in $days days ($date). Review it before making a disposition decision.";

        foreach ($managers as $manager) {
            $userId = (int) $manager['user_id'];
            $key = 'retention:document:' . (int) $document['doc_id'] .
                ':due:' . $date . ':warning:' . $days . ':user:' . $userId;
            if (drms_maintenance_insert_notification(
                $conn,
                $userId,
                (string) $manager['role'],
                $message,
                $key,
                'documents.php?disposition=1'
            )) {
                $created++;
            }
        }
    }

    return [
        'evaluated' => count($documents),
        'affected' => $created,
        'message' => "$created new retention alert(s) created; existing keyed alerts were not duplicated.",
    ];
}

function drms_maintenance_normalize_path(string $path): string
{
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function drms_maintenance_resolve_upload_path(string $projectRoot, string $storedPath): ?string
{
    $storedPath = trim($storedPath);
    if ($storedPath === '' || strpos($storedPath, '[SECURELY DESTROYED') === 0) {
        return null;
    }

    $normalized = drms_maintenance_normalize_path($storedPath);
    $isAbsolute = preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1 || strpos($normalized, DIRECTORY_SEPARATOR) === 0;
    $candidate = $isAbsolute ? $normalized : $projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

    if (!is_file($candidate)) {
        return null;
    }

    $realFile = realpath($candidate);
    $uploadRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    if ($realFile === false || $uploadRoot === false) {
        throw new RuntimeException('Unable to validate a recycled document file path.');
    }

    $prefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncasecmp($realFile, $prefix, strlen($prefix)) !== 0) {
        throw new RuntimeException('Refused to purge a file outside the protected uploads directory: ' . $storedPath);
    }

    return $realFile;
}

function drms_maintenance_path_is_shared(mysqli $conn, int $docId, string $storedPath): bool
{
    $statement = $conn->prepare("
        SELECT (
            (SELECT COUNT(*) FROM documents WHERE doc_id <> ? AND file_path = ?)
            +
            (SELECT COUNT(*) FROM document_versions WHERE doc_id <> ? AND file_path = ?)
        ) AS total
    ");
    $statement->bind_param('isis', $docId, $storedPath, $docId, $storedPath);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function drms_maintenance_restore_quarantine(array $movedFiles): void
{
    foreach (array_reverse($movedFiles) as $move) {
        if (is_file($move['quarantine']) && !file_exists($move['original'])) {
            @rename($move['quarantine'], $move['original']);
        }
    }
}

function drms_maintenance_task_purge_recycled(
    mysqli $conn,
    bool $dryRun,
    string $projectRoot,
    string $runId
): array {
    $result = $conn->query("
        SELECT doc_id, file_name, file_path
        FROM documents
        WHERE status = 'Recycled'
          AND COALESCE(record_phase, 'Working') <> 'Official'
          AND deleted_at IS NOT NULL
          AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY doc_id
    ");

    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }

    if ($dryRun || !$documents) {
        return [
            'evaluated' => count($documents),
            'affected' => 0,
            'message' => $dryRun
                ? count($documents) . ' recycled working file record(s) would be purged.'
                : 'No recycled working files have reached the 30-day purge date.',
        ];
    }

    $quarantineRoot = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR .
        'maintenance' . DIRECTORY_SEPARATOR . 'quarantine' . DIRECTORY_SEPARATOR . $runId;
    drms_maintenance_ensure_directory($quarantineRoot);

    $purged = 0;
    $warnings = [];

    foreach ($documents as $document) {
        $docId = (int) $document['doc_id'];
        $pathRows = [['file_path' => (string) $document['file_path']]];
        $versionStatement = $conn->prepare('SELECT file_path FROM document_versions WHERE doc_id = ?');
        $versionStatement->bind_param('i', $docId);
        $versionStatement->execute();
        $versionResult = $versionStatement->get_result();
        while ($versionPath = $versionResult->fetch_assoc()) {
            $pathRows[] = $versionPath;
        }
        $versionStatement->close();

        $moved = [];
        $seen = [];

        try {
            foreach ($pathRows as $pathRow) {
                $storedPath = (string) ($pathRow['file_path'] ?? '');
                if ($storedPath === '' || isset($seen[$storedPath])) {
                    continue;
                }
                $seen[$storedPath] = true;

                if (drms_maintenance_path_is_shared($conn, $docId, $storedPath)) {
                    continue;
                }

                $absolutePath = drms_maintenance_resolve_upload_path($projectRoot, $storedPath);
                if ($absolutePath === null) {
                    continue;
                }

                $quarantinePath = $quarantineRoot . DIRECTORY_SEPARATOR . $docId . '_' .
                    bin2hex(random_bytes(5)) . '_' . basename($absolutePath);
                if (!rename($absolutePath, $quarantinePath)) {
                    throw new RuntimeException('Unable to quarantine a recycled file before database deletion.');
                }
                $moved[] = ['original' => $absolutePath, 'quarantine' => $quarantinePath];
            }

            $conn->begin_transaction();
            $delete = $conn->prepare("
                DELETE FROM documents
                WHERE doc_id = ?
                  AND status = 'Recycled'
                  AND COALESCE(record_phase, 'Working') <> 'Official'
                  AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $delete->bind_param('i', $docId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                throw new RuntimeException('The recycled record changed before it could be purged.');
            }
            $delete->close();

            if (function_exists('drms_log_audit_action')) {
                drms_log_audit_action(
                    $conn,
                    null,
                    'SYSTEM_AUTO_PURGE',
                    'Scheduled maintenance permanently removed a working file after 30 days in the Recycle Bin: ' .
                        (string) $document['file_name']
                );
            }
            $conn->commit();

            foreach ($moved as $move) {
                if (is_file($move['quarantine']) && !@unlink($move['quarantine'])) {
                    $warnings[] = 'A quarantined file could not be wiped for document ID ' . $docId . '.';
                }
            }
            $purged++;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
            drms_maintenance_restore_quarantine($moved);
            $warnings[] = 'Document ID ' . $docId . ' was skipped: ' . $error->getMessage();
        }
    }

    return [
        'evaluated' => count($documents),
        'affected' => $purged,
        'warnings' => $warnings,
        'message' => "$purged recycled working file record(s) safely purged.",
    ];
}

function drms_maintenance_task_collection_reminders(mysqli $conn, bool $dryRun): array
{
    $financeResult = $conn->query("
        SELECT user_id
        FROM users
        WHERE role = 'Finance'
          AND status = 'Active'
        ORDER BY user_id
    ");
    $financeIds = [];
    while ($row = $financeResult->fetch_assoc()) {
        $financeIds[] = (int) $row['user_id'];
    }

    if ($dryRun) {
        $receivables = drms_maintenance_count($conn, "
            SELECT COUNT(*) AS total
            FROM purchase_orders po
            WHERE po.status = 'Delivered'
              AND po.collection_status IN ('Unpaid', 'Partially Paid')
        ");
        return [
            'evaluated' => $receivables,
            'affected' => 0,
            'message' => count($financeIds) . " active Finance user(s) and $receivables open receivable(s) would be evaluated.",
        ];
    }

    $helperPath = __DIR__ . DIRECTORY_SEPARATOR . 'collection_reminders.php';
    if (!is_file($helperPath)) {
        throw new RuntimeException('Collection reminder helper is missing.');
    }
    require_once $helperPath;

    if (!function_exists('phase5c_sync_collection_reminders')) {
        throw new RuntimeException('Collection reminder helper is unavailable.');
    }

    $created = 0;
    $evaluated = 0;
    foreach ($financeIds as $financeId) {
        $result = phase5c_sync_collection_reminders($conn, $financeId);
        $created += (int) ($result['created'] ?? 0);
        $evaluated += (int) ($result['evaluated'] ?? 0);
    }

    return [
        'evaluated' => $evaluated,
        'affected' => $created,
        'message' => "$created new collection reminder(s) created for " . count($financeIds) . ' active Finance user(s).',
    ];
}

function drms_maintenance_task_cleanup_security(mysqli $conn, bool $dryRun): array
{
    $counts = [
        'login_attempts' => drms_maintenance_count(
            $conn,
            "SELECT COUNT(*) AS total FROM login_attempts WHERE attempt_time < NOW() - INTERVAL 10 MINUTE"
        ),
        'password_otps' => drms_maintenance_count(
            $conn,
            "SELECT COUNT(*) AS total FROM password_reset_otps WHERE created_at < NOW() - INTERVAL 2 DAY"
        ),
        'legacy_reset_tokens' => drms_maintenance_count(
            $conn,
            "SELECT COUNT(*) AS total FROM users WHERE reset_token IS NOT NULL AND reset_token_expire < NOW()"
        ),
        'setup_tokens' => drms_maintenance_count(
            $conn,
            "SELECT COUNT(*) AS total FROM users WHERE setup_token IS NOT NULL AND setup_token_expire < NOW()"
        ),
        'email_codes' => drms_maintenance_count(
            $conn,
            "SELECT COUNT(*) AS total FROM users WHERE email_verification_code IS NOT NULL AND email_code_expire < NOW()"
        ),
    ];

    $eligible = array_sum($counts);
    if ($dryRun) {
        return [
            'evaluated' => $eligible,
            'affected' => 0,
            'details' => $counts,
            'message' => "$eligible expired security row(s) or token(s) would be cleaned.",
        ];
    }

    $affected = 0;
    $conn->query("DELETE FROM login_attempts WHERE attempt_time < NOW() - INTERVAL 10 MINUTE");
    $affected += $conn->affected_rows;
    $conn->query("DELETE FROM password_reset_otps WHERE created_at < NOW() - INTERVAL 2 DAY");
    $affected += $conn->affected_rows;
    $conn->query("UPDATE users SET reset_token = NULL, reset_token_expire = NULL WHERE reset_token IS NOT NULL AND reset_token_expire < NOW()");
    $affected += $conn->affected_rows;
    $conn->query("
        UPDATE users
        SET setup_token = NULL,
            setup_token_purpose = NULL,
            setup_token_sent_at = NULL,
            setup_token_expire = NULL
        WHERE setup_token IS NOT NULL
          AND setup_token_expire < NOW()
    ");
    $affected += $conn->affected_rows;
    $conn->query("
        UPDATE users
        SET email_verification_code = NULL,
            email_code_expire = NULL,
            email_verification_attempts = 0
        WHERE email_verification_code IS NOT NULL
          AND email_code_expire < NOW()
    ");
    $affected += $conn->affected_rows;

    return [
        'evaluated' => $eligible,
        'affected' => $affected,
        'details' => $counts,
        'message' => "$affected expired security row(s) or token(s) cleaned.",
    ];
}

function drms_maintenance_execute_task(
    string $task,
    mysqli $conn,
    bool $dryRun,
    string $projectRoot,
    string $runId
): array {
    switch ($task) {
        case 'archive_due_records':
            return drms_maintenance_task_archive_due($conn, $dryRun);
        case 'flag_disposition_due':
            return drms_maintenance_task_flag_disposition($conn, $dryRun);
        case 'send_retention_alerts':
            return drms_maintenance_task_retention_alerts($conn, $dryRun);
        case 'purge_recycled_working_files':
            return drms_maintenance_task_purge_recycled($conn, $dryRun, $projectRoot, $runId);
        case 'sync_collection_reminders':
            return drms_maintenance_task_collection_reminders($conn, $dryRun);
        case 'cleanup_security_data':
            return drms_maintenance_task_cleanup_security($conn, $dryRun);
    }

    throw new InvalidArgumentException('Unsupported maintenance task: ' . $task);
}

function drms_maintenance_run(mysqli $conn, string $projectRoot, array $options = []): array
{
    $options = array_merge(drms_maintenance_default_options(), $options);
    $projectRoot = rtrim($projectRoot, '/\\');
    $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $quiet = (bool) $options['quiet'];
    $dryRun = (bool) $options['dry_run'];
    $requestedTask = (string) $options['task'];
    $runStarted = microtime(true);

    $lock = drms_maintenance_acquire_lock($projectRoot);
    if ($lock === null) {
        return [
            'run_id' => $runId,
            'status' => 'busy',
            'dry_run' => $dryRun,
            'started_at' => date(DATE_ATOM),
            'finished_at' => date(DATE_ATOM),
            'tasks' => [],
            'message' => 'Another maintenance run is already active.',
            'exit_code' => 2,
        ];
    }

    $report = [
        'run_id' => $runId,
        'status' => 'running',
        'dry_run' => $dryRun,
        'requested_task' => $requestedTask,
        'started_at' => date(DATE_ATOM),
        'php_sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'tasks' => [],
    ];

    try {
        $tasks = $requestedTask === 'all' ? drms_maintenance_task_names() : [$requestedTask];
        drms_maintenance_emit(
            'Maintenance run ' . $runId . ' started' . ($dryRun ? ' in DRY-RUN mode.' : '.'),
            $quiet
        );

        foreach ($tasks as $task) {
            $taskStarted = microtime(true);
            try {
                $result = drms_maintenance_execute_task($task, $conn, $dryRun, $projectRoot, $runId);
                $warnings = array_values($result['warnings'] ?? []);
                $entry = array_merge([
                    'status' => $warnings ? 'warning' : 'success',
                    'duration_ms' => (int) round((microtime(true) - $taskStarted) * 1000),
                ], $result);
                $report['tasks'][$task] = $entry;
                drms_maintenance_emit($task . ': ' . (string) ($result['message'] ?? 'Completed.'), $quiet);
                foreach ($warnings as $warning) {
                    drms_maintenance_emit($task . ' warning: ' . $warning, $quiet, true);
                }
            } catch (Throwable $error) {
                $report['tasks'][$task] = [
                    'status' => 'failed',
                    'duration_ms' => (int) round((microtime(true) - $taskStarted) * 1000),
                    'error' => $error->getMessage(),
                ];
                drms_maintenance_emit($task . ' failed: ' . $error->getMessage(), $quiet, true);
            }
        }

        $failed = array_filter(
            $report['tasks'],
            static fn(array $task): bool => ($task['status'] ?? '') === 'failed'
        );
        $warnings = array_filter(
            $report['tasks'],
            static fn(array $task): bool => ($task['status'] ?? '') === 'warning'
        );

        $report['status'] = $failed ? 'failed' : ($warnings ? 'completed_with_warnings' : 'success');
        $report['finished_at'] = date(DATE_ATOM);
        $report['duration_ms'] = (int) round((microtime(true) - $runStarted) * 1000);
        $report['exit_code'] = $failed ? 1 : 0;
        $report['report_path'] = drms_maintenance_write_report($projectRoot, $report);

        drms_maintenance_emit(
            'Maintenance run finished with status: ' . $report['status'] . '.',
            $quiet,
            $report['status'] === 'failed'
        );

        return $report;
    } finally {
        drms_maintenance_release_lock($lock);
    }
}
