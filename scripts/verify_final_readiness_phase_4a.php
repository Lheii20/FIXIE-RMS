<?php
declare(strict_types=1);

$arguments = array_values(array_slice($argv, 1));
$staticOnly = in_array('--static', $arguments, true);
$projectRoot = null;
$authOverride = null;
$installerOverride = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--auth=')) {
        $authOverride = substr($argument, 7);
    } elseif (str_starts_with($argument, '--installer=')) {
        $installerOverride = substr($argument, 12);
    } elseif ($argument !== '--static' && $projectRoot === null) {
        $projectRoot = rtrim($argument, "/\\");
    }
}
$projectRoot = $projectRoot ?: dirname(__DIR__);
$errors = [];

function phase4aFail(array &$errors, string $message): void
{
    $errors[] = $message;
}

function phase4aSource(string $path, array &$errors): string
{
    $source = is_file($path) ? file_get_contents($path) : false;
    if ($source === false) {
        phase4aFail($errors, 'Missing or unreadable file: ' . $path);
        return '';
    }
    return $source;
}

function phase4aReadIndexes(mysqli $conn, string $table): array
{
    $result = $conn->query('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');
    $indexes = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $name = (string) ($row['Key_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            $column = (string) ($row['Column_name'] ?? '');
            if ($name !== '' && $sequence > 0 && $column !== '') {
                $indexes[$name][$sequence] = $column;
            }
        }
    } finally {
        $result->free();
    }
    foreach ($indexes as &$columns) {
        ksort($columns);
        $columns = array_values($columns);
    }
    unset($columns);
    return $indexes;
}

function phase4aHasIndexPrefix(array $indexes, array $prefix): bool
{
    foreach ($indexes as $columns) {
        if (array_slice($columns, 0, count($prefix)) === $prefix) {
            return true;
        }
    }
    return false;
}

$authPath = $authOverride ?: $projectRoot . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'auth.php';
$auth = phase4aSource($authPath, $errors);
$session = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session_bootstrap.php', $errors);
$dbConnect = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db_connect.php', $errors);
$workflowAccess = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'workflow_access.php', $errors);
$loginOtp = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'otp_handler.php', $errors);
$recoveryOtp = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'password_recovery_otp.php', $errors);
$loginPage = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'index.php', $errors);
$recoveryPage = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'forgot_password.php', $errors);
$sidebar = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'sidebar.php', $errors);
$retiredApi = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'login.php', $errors);
$installerPath = $installerOverride ?: $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'install_final_readiness_phase_4a.php';
$installer = phase4aSource($installerPath, $errors);

foreach (['SHOW COLUMNS FROM users', 'ALTER TABLE users', 'AUTO-SETUP SECURITY COLUMNS'] as $runtimeSchemaMarker) {
    if (stripos($auth, $runtimeSchemaMarker) !== false) {
        phase4aFail($errors, 'Runtime authentication schema mutation remains: ' . $runtimeSchemaMarker);
    }
}
foreach ([
    'auth_has_valid_csrf_token()',
    'password_verify($password, $user[\'password_hash\'])',
    'FROM login_attempts',
    '$identity_limit = 5;',
    '$network_limit = 20;',
    'session_regenerate_id(true);',
    "\$_SESSION['session_token'] = \$session_token;",
    "\$_SESSION['csrf_token'] = bin2hex(random_bytes(32));",
    "UPDATE users SET session_token = NULL WHERE user_id = ? AND session_token = ?",
] as $marker) {
    if (strpos($auth, $marker) === false) {
        phase4aFail($errors, 'Standard login/logout protection is missing: ' . $marker);
    }
}

foreach ([
    "'secure' => \$sessionCookieSecure",
    "'httponly' => true",
    "'samesite' => 'Strict'",
    "session.use_strict_mode",
    "session.use_only_cookies",
] as $marker) {
    if (strpos($session, $marker) === false) {
        phase4aFail($errors, 'Session-cookie protection is missing: ' . $marker);
    }
}
foreach ([
    'hash_equals((string) ($sessionUser[\'session_token\'] ?? \'\'), $current_token)',
    "\$sessionUser['status'] !== 'Active'",
    'SessionExpired',
    'ForceLoggedOutByAdmin',
    "WHERE setting_key = 'session_timeout'",
] as $marker) {
    if (strpos($dbConnect, $marker) === false) {
        phase4aFail($errors, 'Central protected-session enforcement is missing: ' . $marker);
    }
}

foreach ([$loginOtp, $recoveryOtp] as $otpSource) {
    foreach (['REQUEST_METHOD', 'csrf_token', 'random_int(0, 999999)', 'password_hash(', 'password_verify(', 'attempt_count', 'expires_at > NOW()', 'status = \'Pending\''] as $marker) {
        if (strpos($otpSource, $marker) === false) {
            phase4aFail($errors, 'An OTP flow is missing protection: ' . $marker);
        }
    }
}
foreach ([
    "UPDATE otp_auth_tokens SET status = 'Verified'",
    'affected_rows !== 1',
    'session_regenerate_id(true);',
    "\$_SESSION['last_activity'] = time();",
] as $marker) {
    if (strpos($loginOtp, $marker) === false) {
        phase4aFail($errors, 'Login OTP one-time/session protection is missing: ' . $marker);
    }
}
foreach ([
    "status = 'Used'",
    "session_token = NULL",
    'RECOVERY_RESET_WINDOW_SECONDS',
    'verified_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
] as $marker) {
    if (strpos($recoveryOtp, $marker) === false) {
        phase4aFail($errors, 'Password-recovery OTP protection is missing: ' . $marker);
    }
}

if (strpos($loginPage, 'actions/auth.php') === false || strpos($loginPage, 'actions/otp_handler.php') === false || strpos($loginPage, 'csrf_token') === false) {
    phase4aFail($errors, 'The login page is not connected to both protected authentication routes.');
}
if (strpos($recoveryPage, 'actions/password_recovery_otp.php') === false || strpos($recoveryPage, 'csrf_token') === false) {
    phase4aFail($errors, 'The forgot-password page is not connected to the protected OTP route.');
}
if (substr_count($sidebar, 'action="actions/auth.php"') < 2 || substr_count($sidebar, 'name="csrf_token"') < 2 || substr_count($sidebar, 'name="logout"') < 2) {
    phase4aFail($errors, 'Desktop and mobile logout forms must both remain POST/CSRF protected.');
}
if (strpos($retiredApi, 'http_response_code(410)') === false || strpos($retiredApi, 'legacy login endpoint is no longer available') === false) {
    phase4aFail($errors, 'The legacy login API is not securely retired.');
}
foreach (['drms_require_login', 'drms_require_workflow_roles', 'in_array($current_role, $allowed_roles, true)'] as $marker) {
    if (strpos($workflowAccess, $marker) === false) {
        phase4aFail($errors, 'Workflow role guard is missing: ' . $marker);
    }
}

$rolePageRequirements = [
    'quotations_list.php' => ['drms_require_workflow_roles', "'Sales Staff'", "'GM'"],
    'pr_list.php' => ['drms_require_workflow_roles', "'Sales Staff'", "'Procurement'", "'GM'", "'President'", "'Finance'"],
    'po_list.php' => ['drms_require_workflow_roles', "'Procurement'", "'GM'", "'President'", "'Finance'", "'Supply Chain'"],
    'view_quotation.php' => ['drms_require_workflow_roles', "'Sales Staff'", "'GM'"],
    'view_pr.php' => ['drms_require_workflow_roles', "'Sales Staff'", "'Procurement'", "'GM'", "'President'", "'Finance'"],
    'view_po.php' => ['drms_require_workflow_roles', "'Procurement'", "'GM'", "'President'", "'Finance'", "'Supply Chain'"],
    'admin_users.php' => ["\$_SESSION['role'] !== 'Admin'"],
    'collection_monitoring.php' => ["\$allowed_roles = ['Finance', 'GM', 'President'];"],
    'official_declarations.php' => ['Only the General Manager can open Official Record declaration requests.'],
];
foreach ($rolePageRequirements as $relativePath => $markers) {
    $pageSource = phase4aSource($projectRoot . DIRECTORY_SEPARATOR . $relativePath, $errors);
    foreach ($markers as $marker) {
        if (strpos($pageSource, $marker) === false) {
            phase4aFail($errors, $relativePath . ' lost role-access marker: ' . $marker);
        }
    }
}

foreach ([
    "ALTER TABLE `otp_auth_tokens` ADD INDEX",
    "['email', 'status', 'otp_id']",
    "['user_id', 'otp_id']",
    "['status', 'expires_at']",
    "['created_at']",
] as $marker) {
    if (strpos($installer, $marker) === false) {
        phase4aFail($errors, 'Phase 4A installer marker is missing: ' . $marker);
    }
}

if (!$staticOnly && $errors === []) {
    try {
        require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
        if (!isset($conn) || !($conn instanceof mysqli)) {
            throw new RuntimeException('Database connection was not created.');
        }

        $requiredColumns = [
            'users' => ['user_id', 'username', 'password_hash', 'email', 'role', 'status', 'require_pass_change', 'session_token'],
            'login_attempts' => ['ip_address', 'username', 'attempt_time'],
            'otp_auth_tokens' => ['otp_id', 'user_id', 'email', 'otp_code', 'expires_at', 'attempt_count', 'status', 'created_at'],
            'password_reset_otps' => ['reset_id', 'user_id', 'email', 'otp_hash', 'expires_at', 'attempt_count', 'max_attempts', 'status', 'verified_at', 'used_at'],
            'system_settings' => ['setting_key', 'setting_value'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $result = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
            $found = [];
            try {
                while ($row = $result->fetch_assoc()) {
                    $found[(string) $row['Field']] = true;
                }
            } finally {
                $result->free();
            }
            foreach ($columns as $column) {
                if (!isset($found[$column])) {
                    phase4aFail($errors, 'Authentication schema is missing ' . $table . '.' . $column);
                }
            }
        }

        $indexRequirements = [
            ['login_attempts', ['ip_address', 'username', 'attempt_time']],
            ['login_attempts', ['ip_address', 'attempt_time']],
            ['otp_auth_tokens', ['email', 'status', 'otp_id']],
            ['otp_auth_tokens', ['user_id', 'otp_id']],
            ['otp_auth_tokens', ['status', 'expires_at']],
            ['otp_auth_tokens', ['created_at']],
            ['password_reset_otps', ['email', 'status', 'expires_at']],
            ['password_reset_otps', ['user_id', 'created_at']],
        ];
        $indexCache = [];
        foreach ($indexRequirements as [$table, $prefix]) {
            $indexCache[$table] ??= phase4aReadIndexes($conn, $table);
            if (!phase4aHasIndexPrefix($indexCache[$table], $prefix)) {
                phase4aFail($errors, 'Authentication lookup index is missing: ' . $table . '(' . implode(', ', $prefix) . ')');
            }
        }

        $timeoutResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout' LIMIT 1");
        try {
            $timeout = (int) (($timeoutResult->fetch_assoc()['setting_value'] ?? 30));
        } finally {
            $timeoutResult->free();
        }
        if (!in_array($timeout, [15, 30, 60, 120], true)) {
            phase4aFail($errors, 'Configured session timeout is outside the supported values.');
        }
    } catch (Throwable $error) {
        phase4aFail($errors, 'Installed authentication preflight failed: ' . $error->getMessage());
    } finally {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Phase 4A verification FAILED:\n - " . implode("\n - ", array_values(array_unique($errors))) . "\n");
    exit(1);
}

echo "Phase 4A verification PASSED.\n";
echo "Login requests no longer perform runtime schema changes.\n";
echo "Password and email-OTP login, password recovery, session enforcement, role guards, and CSRF logout remain connected.\n";
echo $staticOnly
    ? "Static authentication verification completed; install the indexes and run the full verifier next.\n"
    : "Required authentication tables, timeout configuration, and lookup indexes passed live read-only preflight.\n";
