<?php
declare(strict_types=1);

$arguments = array_values(array_slice($argv, 1));
$checkOnly = in_array('--check', $arguments, true);
$projectRoot = null;
foreach ($arguments as $argument) {
    if ($argument !== '--check' && $projectRoot === null) {
        $projectRoot = rtrim($argument, "/\\");
    }
}
$projectRoot = $projectRoot ?: dirname(__DIR__);

function phase4aColumns(mysqli $conn, string $table): array
{
    $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    try {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['Field']] = true;
        }
    } finally {
        $result->free();
    }
    return $columns;
}

function phase4aIndexes(mysqli $conn, string $table): array
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

function phase4aHasIndexPrefix(array $indexes, array $requiredPrefix): bool
{
    foreach ($indexes as $columns) {
        if (array_slice($columns, 0, count($requiredPrefix)) === $requiredPrefix) {
            return true;
        }
    }
    return false;
}

try {
    require $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'maintenance_db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection was not created.');
    }

    $requiredUserColumns = [
        'require_pass_change',
        'session_token',
        'setup_token',
        'setup_token_purpose',
        'setup_token_sent_at',
        'setup_token_expire',
    ];
    $userColumns = phase4aColumns($conn, 'users');
    $missingUserColumns = array_values(array_filter(
        $requiredUserColumns,
        static fn(string $column): bool => !isset($userColumns[$column])
    ));
    if ($missingUserColumns !== []) {
        throw new RuntimeException(
            'Required authentication columns are missing from users: ' . implode(', ', $missingUserColumns) .
            '. Import the complete current database schema before installing Phase 4A.'
        );
    }

    $otpColumns = phase4aColumns($conn, 'otp_auth_tokens');
    foreach (['otp_id', 'user_id', 'email', 'expires_at', 'status', 'created_at'] as $column) {
        if (!isset($otpColumns[$column])) {
            throw new RuntimeException('otp_auth_tokens is missing required column: ' . $column);
        }
    }

    $requiredIndexes = [
        'idx_otp_auth_email_status_id' => ['email', 'status', 'otp_id'],
        'idx_otp_auth_user_id' => ['user_id', 'otp_id'],
        'idx_otp_auth_status_expiry' => ['status', 'expires_at'],
        'idx_otp_auth_created_at' => ['created_at'],
    ];
    $indexes = phase4aIndexes($conn, 'otp_auth_tokens');
    $pending = [];
    foreach ($requiredIndexes as $name => $columns) {
        if (!phase4aHasIndexPrefix($indexes, $columns)) {
            $pending[$name] = $columns;
        }
    }

    if ($pending === []) {
        echo "Phase 4A authentication indexes are already installed.\n";
        exit(0);
    }
    if ($checkOnly) {
        echo 'Phase 4A check passed. ' . count($pending) . " authentication index(es) are ready to be installed.\n";
        foreach ($pending as $name => $columns) {
            echo ' - ' . $name . ' (' . implode(', ', $columns) . ")\n";
        }
        exit(0);
    }

    foreach ($pending as $name => $columns) {
        $safeName = '`' . str_replace('`', '``', $name) . '`';
        $safeColumns = implode(', ', array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $columns
        ));
        $conn->query("ALTER TABLE `otp_auth_tokens` ADD INDEX {$safeName} ({$safeColumns})");
        echo 'Installed ' . $name . ".\n";
    }

    $installedIndexes = phase4aIndexes($conn, 'otp_auth_tokens');
    foreach ($requiredIndexes as $name => $columns) {
        if (!phase4aHasIndexPrefix($installedIndexes, $columns)) {
            throw new RuntimeException('Index verification failed after installation: ' . $name);
        }
    }
    echo "Phase 4A authentication indexes installed successfully.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 4A installation FAILED: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
