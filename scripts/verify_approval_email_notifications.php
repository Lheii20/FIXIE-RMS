<?php
declare(strict_types=1);

/**
 * Read-only approval-email readiness verifier for Fixie DRMS.
 *
 * Run from the deployed project root:
 *   C:\xampp\php\php.exe scripts\verify_approval_email_notifications.php
 *
 * This verifier never sends email, changes preferences, or creates workflow
 * records. It validates the required implementation and environment safely.
 */

function verifier_pass(string $message): void
{
    echo "PASS  " . $message . PHP_EOL;
}

function verifier_warn(string $message): void
{
    echo "WARN  " . $message . PHP_EOL;
}

function verifier_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo "FAIL  " . $message . PHP_EOL;
}

function verifier_file_contains(array &$failures, string $path, string $needle, string $label): void
{
    if (!is_file($path)) {
        verifier_fail($failures, $label . ' is missing: ' . $path);
        return;
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || !str_contains($contents, $needle)) {
        verifier_fail($failures, $label . ' marker is missing.');
        return;
    }

    verifier_pass($label);
}

$root = dirname(__DIR__);
$failures = [];

echo "Fixie DRMS approval-email readiness verification" . PHP_EOL;
echo "Project: " . $root . PHP_EOL . PHP_EOL;

verifier_file_contains(
    $failures,
    $root . '/config/approval_email_notifications.php',
    'function drms_send_approval_email_to_role',
    'Reusable approval email service is installed'
);
verifier_file_contains(
    $failures,
    $root . '/actions/user_preferences_handler.php',
    '$action === \'set_approval_email\'',
    'Approval email preference action is installed'
);
verifier_file_contains(
    $failures,
    $root . '/settings.php',
    'approvalEmailEnabled',
    'Settings approval-email toggle is installed'
);
verifier_file_contains(
    $failures,
    $root . '/actions/quotation_handler.php',
    'Approval required: Official Client PO',
    'GM Client PO approval email hook is installed'
);
verifier_file_contains(
    $failures,
    $root . '/actions/pr_handler.php',
    'Approval required: PRF',
    'PRF approval email hooks are installed'
);
verifier_file_contains(
    $failures,
    $root . '/actions/po_handler.php',
    'Action required: PO funding release',
    'Finance PO funding email hook is installed'
);
verifier_file_contains(
    $failures,
    $root . '/actions/delivery_request_handler.php',
    'Approval required: Delivery request',
    'Logistics review email hook is installed'
);

try {
    require_once $root . '/config/db_connect.php';
    require_once $root . '/config/runtime.php';

    $columnStatement = $conn->prepare(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'user_preferences'
           AND column_name = 'approval_email_enabled'
         LIMIT 1"
    );
    $columnStatement->execute();
    $columnExists = $columnStatement->get_result()->num_rows === 1;
    $columnStatement->close();

    if (!$columnExists) {
        verifier_fail(
            $failures,
            'Database column user_preferences.approval_email_enabled is missing.'
        );
    } else {
        verifier_pass('Database preference column is available');
    }

    if (drms_runtime_mail_is_configured()) {
        verifier_pass('SMTP credentials are configured without exposing any secret');
    } else {
        verifier_fail(
            $failures,
            'SMTP is not configured. Add valid credentials to config/runtime.local.php before testing approval emails.'
        );
    }

    if ($columnExists) {
        $eligibleStatement = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM users AS u
             INNER JOIN user_preferences AS preference
                ON preference.user_id = u.user_id
             WHERE u.status = 'Active'
               AND preference.approval_email_enabled = 1
               AND u.email IS NOT NULL
               AND u.email <> ''"
        );
        $eligibleStatement->execute();
        $eligibleCount = (int) ($eligibleStatement->get_result()->fetch_assoc()['total'] ?? 0);
        $eligibleStatement->close();

        if ($eligibleCount > 0) {
            verifier_pass($eligibleCount . ' active account(s) can receive opted-in approval emails');
        } else {
            verifier_warn('No active account has enabled approval emails yet. This is expected before the first user opts in.');
        }
    }
} catch (Throwable $exception) {
    verifier_fail($failures, 'Database/runtime readiness check could not run: ' . $exception->getMessage());
}

echo PHP_EOL;
if ($failures) {
    echo 'Approval-email verification FAILED:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Approval-email verification PASSED.' . PHP_EOL;
echo 'Manual delivery test: enable the toggle on one role account, then submit one real approval-required workflow item for that role. Confirm one email and one in-app notification only.' . PHP_EOL;
exit(0);
