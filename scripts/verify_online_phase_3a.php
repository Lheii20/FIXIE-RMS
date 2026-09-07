<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Online Phase 3A verifier.
 *
 * Safe local verification (does not send email):
 *   php scripts/verify_online_phase_3a.php
 *
 * Optional real SMTP delivery test, only when explicitly requested:
 *   php scripts/verify_online_phase_3a.php --send-to="recipient@example.com"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$applicationRoot = getenv('DRMS_PROJECT_ROOT') ?: $root;
$failures = [];
$passes = 0;

function phase3a_result(bool $passed, string $message): void
{
    global $failures, $passes;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if ($passed) {
        $passes++;
    } else {
        $failures[] = $message;
    }
}

function phase3a_send_target(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--send-to=')) {
            $target = trim(substr($argument, 10), "\"'");
            return $target === '' ? null : $target;
        }
    }
    return null;
}

$required = [
    'config/runtime.php',
    'config/mailer.php',
    'actions/otp_handler.php',
    'actions/password_recovery_otp.php',
    'actions/user_handler.php',
    'admin_users.php',
];

foreach ($required as $relativePath) {
    phase3a_result(
        is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)),
        "Required file exists: {$relativePath}"
    );
}

foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $libraryFile) {
    phase3a_result(
        is_file($applicationRoot . '/libs/src/' . $libraryFile),
        'Required PHPMailer library exists: libs/src/' . $libraryFile
    );
}

try {
    require_once $root . '/config/runtime.php';
    require_once $root . '/config/mailer.php';

    $runtime = drms_runtime_config();
    $mail = $runtime['mail'];

    phase3a_result((int) $mail['timeout_seconds'] >= 5, 'SMTP timeout is valid');
    phase3a_result(in_array($mail['encryption'], ['tls', 'ssl'], true), 'SMTP encryption is TLS or SSL');

    if ($runtime['app']['hosting_profile'] === 'infinityfree') {
        $validPair =
            ((int) $mail['port'] === 587 && $mail['encryption'] === 'tls') ||
            ((int) $mail['port'] === 465 && $mail['encryption'] === 'ssl');
        phase3a_result($validPair, 'InfinityFree uses a supported SMTP port/encryption pair');
        phase3a_result((bool) $mail['required'], 'InfinityFree requires working SMTP configuration');
    }

    $otpSource = (string) file_get_contents($root . '/actions/otp_handler.php');
    $recoverySource = (string) file_get_contents($root . '/actions/password_recovery_otp.php');
    $userSource = (string) file_get_contents($root . '/actions/user_handler.php');

    phase3a_result(
        !str_contains($otpSource, "getenv('DRMS_MAIL_FROM') ?:"),
        'Login OTP does not override the configured sender address'
    );
    phase3a_result(
        !str_contains($recoverySource, "getenv('DRMS_MAIL_FROM') ?:"),
        'Password recovery does not override the configured sender address'
    );
    phase3a_result(
        str_contains($otpSource, 'password_hash($otpCode, PASSWORD_DEFAULT)'),
        'Login OTP is stored as a password hash'
    );
    phase3a_result(
        str_contains($recoverySource, 'password_hash($otpCode, PASSWORD_DEFAULT)'),
        'Password-recovery OTP is stored as a password hash'
    );

    $createStart = strpos($userSource, "if (\$action == 'create_user')");
    $createEnd = strpos($userSource, '// UPDATE USER', $createStart === false ? 0 : $createStart);
    $createBlock = $createStart !== false && $createEnd !== false
        ? substr($userSource, $createStart, $createEnd - $createStart)
        : '';
    phase3a_result($createBlock !== '', 'New-user creation block is present');
    phase3a_result(
        str_contains($createBlock, "'Active', 1, NULL, NULL, NULL, NULL"),
        'New accounts require password creation after verified Email OTP'
    );
    phase3a_result(
        !str_contains($createBlock, '$setup_link') && !str_contains($createBlock, 'setup_token_hash'),
        'New-account activation no longer depends on an emailed setup link'
    );
    phase3a_result(
        str_contains($createBlock, 'Log in via Email OTP'),
        'Welcome email contains the Email OTP activation instructions'
    );

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

    foreach (['otp_auth_tokens', 'password_reset_otps'] as $table) {
        $statement = $conn->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->bind_param('s', $table);
        $statement->execute();
        $exists = (int) $statement->get_result()->fetch_assoc()['total'] === 1;
        $statement->close();
        phase3a_result($exists, "Required OTP table exists: {$table}");
    }

    $columnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'require_pass_change'");
    phase3a_result($columnResult->num_rows === 1, 'Users table supports mandatory first-password creation');
    $columnResult->free();
    $conn->close();

    if (drms_runtime_mail_is_configured()) {
        require_once $applicationRoot . '/libs/src/Exception.php';
        require_once $applicationRoot . '/libs/src/PHPMailer.php';
        require_once $applicationRoot . '/libs/src/SMTP.php';

        $configuredMailer = new PHPMailer(true);
        drms_configure_mailer($configuredMailer);
        phase3a_result($configuredMailer->Mailer === 'smtp', 'PHPMailer is configured for authenticated SMTP');
        phase3a_result($configuredMailer->SMTPAuth === true, 'SMTP authentication is enabled');
        phase3a_result($configuredMailer->Timeout === (int) $mail['timeout_seconds'], 'PHPMailer uses the configured timeout');

        $sendTarget = phase3a_send_target(array_slice($argv, 1));
        if ($sendTarget !== null) {
            if (filter_var($sendTarget, FILTER_VALIDATE_EMAIL) === false) {
                phase3a_result(false, 'SMTP test recipient is a valid email address');
            } else {
                $configuredMailer->addAddress($sendTarget);
                $configuredMailer->isHTML(false);
                $configuredMailer->Subject = 'Fixie DRMS SMTP verification';
                $configuredMailer->Body = 'Fixie DRMS successfully verified its production SMTP configuration at ' . date('Y-m-d H:i:s T') . '.';
                phase3a_result($configuredMailer->send(), 'Real SMTP test email was accepted for delivery');
            }
        } else {
            echo '[SKIP] No email was sent. Add --send-to="address" only when you want a real delivery test.' . PHP_EOL;
        }
    } else {
        echo '[SKIP] SMTP credentials are not configured in this local CLI environment.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    phase3a_result(false, 'Phase 3A verification completed: ' . $exception->getMessage());
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'Online Phase 3A verification FAILED with ' . count($failures) . ' issue(s).' . PHP_EOL;
    exit(1);
}

echo "Online Phase 3A verification PASSED ({$passes} checks)." . PHP_EOL;
exit(0);
