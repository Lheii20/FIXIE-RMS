<?php

declare(strict_types=1);

// This verifier lives in <project>/scripts. Go up one level only.
$projectRoot = dirname(__DIR__);
$policyPath = $projectRoot . '/config/audit_lifecycle.php';
$runnerPath = $projectRoot . '/cron_audit_lifecycle.php';

$failures = [];

function phase_l1_check(bool $condition, string $message, array &$failures): void
{
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
}

echo "Fixie DRMS Long-run Phase L1 audit lifecycle verification\n";
echo 'Project: ' . $projectRoot . PHP_EOL . PHP_EOL;

phase_l1_check(is_file($policyPath), 'config/audit_lifecycle.php is present', $failures);
phase_l1_check(is_file($runnerPath), 'CLI audit lifecycle runner is present', $failures);

if (is_file($policyPath)) {
    require_once $policyPath;
    try {
        $policy = drms_audit_lifecycle_validate_policy(drms_audit_lifecycle_policy());
        phase_l1_check(
            $policy['telemetry_retention_days'] === 90,
            'Telemetry retention is set to the approved 90-day default',
            $failures
        );
        phase_l1_check(
            $policy['telemetry_action_types'] === ['PAGE_VIEW', 'SEARCH', 'FILTER'],
            'Only non-material telemetry action types are eligible for cleanup',
            $failures
        );
    } catch (Throwable $error) {
        phase_l1_check(false, 'Audit lifecycle policy is valid: ' . $error->getMessage(), $failures);
    }
}

if (is_file($runnerPath)) {
    $runner = (string) file_get_contents($runnerPath);
    $requiredMarkers = [
        'PHP_SAPI !== \'cli\'',
        "'--dry-run'",
        "'--apply'",
        'No action was taken. Run --dry-run first',
        'action_type IN ($actionSql)',
        'timestamp < ?',
        'cleanup_batch_size',
        'Only configured telemetry action types were eligible',
    ];
    foreach ($requiredMarkers as $marker) {
        phase_l1_check(
            str_contains($runner, $marker),
            'Runner contains safeguard: ' . $marker,
            $failures
        );
    }
    phase_l1_check(
        !str_contains($runner, 'DELETE FROM audit_logs WHERE timestamp <'),
        'Runner does not contain an unscoped audit-log delete',
        $failures
    );
}

if ($failures !== []) {
    echo PHP_EOL . "Long-run Phase L1 verification FAILED:\n";
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "Long-run Phase L1 verification PASSED.\n";
echo "Next safe check: php cron_audit_lifecycle.php --dry-run\n";
