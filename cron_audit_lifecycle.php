<?php

declare(strict_types=1);

/**
 * CLI-only audit telemetry lifecycle runner.
 *
 * It intentionally removes only old PAGE_VIEW, SEARCH, and FILTER telemetry.
 * Material audit entries remain untouched.  A real delete requires --apply;
 * running without it is safe and makes no database change.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/config/runtime.php';

if (!drms_runtime_feature_enabled('scheduled_maintenance')) {
    fwrite(STDERR, "Audit lifecycle maintenance is disabled for the current hosting profile.\n");
    exit(2);
}

require_once __DIR__ . '/config/maintenance_db.php';
require_once __DIR__ . '/config/audit_lifecycle.php';

function drms_audit_lifecycle_usage(): string
{
    return implode(PHP_EOL, [
        'Fixie DRMS audit telemetry lifecycle',
        '',
        'Usage:',
        '  php cron_audit_lifecycle.php --dry-run',
        '  php cron_audit_lifecycle.php --apply',
        '',
        'Only old PAGE_VIEW, SEARCH, and FILTER audit telemetry can be removed.',
        'Approvals, e-signatures, payments, files, downloads, records, and security events are preserved.',
        '',
    ]);
}

function drms_audit_lifecycle_options(array $arguments): array
{
    $options = ['mode' => 'none', 'help' => false];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            if ($options['mode'] !== 'none') {
                throw new InvalidArgumentException('Choose either --dry-run or --apply, not both.');
            }
            $options['mode'] = 'dry_run';
            continue;
        }
        if ($argument === '--apply') {
            if ($options['mode'] !== 'none') {
                throw new InvalidArgumentException('Choose either --dry-run or --apply, not both.');
            }
            $options['mode'] = 'apply';
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    return $options;
}

function drms_audit_lifecycle_action_sql(mysqli $conn, array $actionTypes): string
{
    $quoted = [];
    foreach ($actionTypes as $actionType) {
        $quoted[] = "'" . $conn->real_escape_string($actionType) . "'";
    }
    return implode(', ', $quoted);
}

function drms_audit_lifecycle_candidates(mysqli $conn, string $actionSql, string $cutoff): array
{
    $statement = $conn->prepare(
        "SELECT action_type, COUNT(*) AS total, MIN(timestamp) AS oldest_at, MAX(timestamp) AS newest_at\n" .
        "FROM audit_logs\n" .
        "WHERE action_type IN ($actionSql)\n" .
        "  AND timestamp < ?\n" .
        "GROUP BY action_type\n" .
        "ORDER BY action_type"
    );
    $statement->bind_param('s', $cutoff);
    $statement->execute();
    $result = $statement->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'action_type' => (string) $row['action_type'],
            'total' => (int) $row['total'],
            'oldest_at' => (string) ($row['oldest_at'] ?? ''),
            'newest_at' => (string) ($row['newest_at'] ?? ''),
        ];
    }
    $statement->close();

    return $rows;
}

function drms_audit_lifecycle_delete_batches(
    mysqli $conn,
    string $actionSql,
    string $cutoff,
    int $batchSize
): int {
    $removed = 0;

    while (true) {
        $select = $conn->prepare(
            "SELECT log_id FROM audit_logs\n" .
            "WHERE action_type IN ($actionSql)\n" .
            "  AND timestamp < ?\n" .
            "ORDER BY log_id\n" .
            "LIMIT ?"
        );
        $select->bind_param('si', $cutoff, $batchSize);
        $select->execute();
        $result = $select->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['log_id'];
        }
        $select->close();

        if ($ids === []) {
            break;
        }

        $idList = implode(',', $ids);
        $delete = $conn->prepare(
            "DELETE FROM audit_logs\n" .
            "WHERE log_id IN ($idList)\n" .
            "  AND action_type IN ($actionSql)\n" .
            "  AND timestamp < ?"
        );
        $delete->bind_param('s', $cutoff);
        $delete->execute();
        $deletedThisBatch = $delete->affected_rows;
        $delete->close();
        $removed += $deletedThisBatch;

        if ($deletedThisBatch < count($ids)) {
            // A concurrent request changed one or more rows. Re-check safely.
            continue;
        }
    }

    return $removed;
}

function drms_audit_lifecycle_write_report(array $report): string
{
    $directory = __DIR__ . '/storage/maintenance/audit_telemetry/' . date('Y-m');
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the audit lifecycle report directory.');
    }

    $name = 'audit_telemetry_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $directory . '/' . $name;
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the audit lifecycle report.');
    }

    return $path;
}

try {
    $options = drms_audit_lifecycle_options($argv ?? []);
    if ($options['help']) {
        echo drms_audit_lifecycle_usage();
        exit(0);
    }
    if ($options['mode'] === 'none') {
        fwrite(STDERR, "No action was taken. Run --dry-run first, then use --apply only after review.\n");
        exit(2);
    }

    $policy = drms_audit_lifecycle_validate_policy(drms_audit_lifecycle_policy());
    $timezone = new DateTimeZone((string) drms_runtime_section('app')['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
    $cutoff = $now->sub(new DateInterval('P' . $policy['telemetry_retention_days'] . 'D'));
    $cutoffSql = $cutoff->format('Y-m-d H:i:s');
    $actionSql = drms_audit_lifecycle_action_sql($conn, $policy['telemetry_action_types']);
    $candidates = drms_audit_lifecycle_candidates($conn, $actionSql, $cutoffSql);
    $candidateCount = array_sum(array_column($candidates, 'total'));
    $isDryRun = $options['mode'] === 'dry_run';
    $removed = $isDryRun
        ? 0
        : drms_audit_lifecycle_delete_batches($conn, $actionSql, $cutoffSql, $policy['cleanup_batch_size']);

    $report = [
        'run_at' => $now->format(DATE_ATOM),
        'mode' => $isDryRun ? 'dry_run' : 'apply',
        'status' => 'success',
        'cutoff_at' => $cutoff->format(DATE_ATOM),
        'policy' => $policy,
        'candidates' => $candidates,
        'candidate_count' => $candidateCount,
        'removed_count' => $removed,
        'preservation_note' => 'Only configured telemetry action types were eligible. Material audit events were excluded.',
    ];
    $report['report_path'] = drms_audit_lifecycle_write_report($report);

    echo ($isDryRun ? 'DRY RUN' : 'APPLIED') . ': ' . $candidateCount .
        ' old telemetry row(s) found; ' . $removed . ' row(s) removed.' . PHP_EOL;
    echo 'Report: ' . $report['report_path'] . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Audit lifecycle failed: ' . $error->getMessage() . PHP_EOL);
    error_log('Fixie DRMS audit lifecycle failure: ' . $error->getMessage());
    exit(1);
}
