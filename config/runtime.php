<?php

declare(strict_types=1);

/**
 * Portable runtime configuration for local XAMPP, restricted free hosting,
 * and regular paid hosting. Secrets may come from environment variables or
 * from config/runtime.local.php. The local file must never be committed or
 * included in a package shared with another person.
 */

if (!function_exists('drms_runtime_env')) {
    function drms_runtime_env(string $name, string $fallback = ''): string
    {
        $value = getenv($name);
        return $value === false || trim((string) $value) === ''
            ? $fallback
            : trim((string) $value);
    }
}

if (!function_exists('drms_runtime_env_bool')) {
    function drms_runtime_env_bool(string $name, bool $fallback): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $fallback;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException('Invalid boolean runtime setting: ' . $name);
        }
        return $parsed;
    }
}

if (!function_exists('drms_runtime_merge')) {
    function drms_runtime_merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_string($key) &&
                isset($base[$key]) &&
                is_array($base[$key]) &&
                is_array($value)
            ) {
                $base[$key] = drms_runtime_merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }
}

if (!function_exists('drms_runtime_config')) {
    function drms_runtime_config(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }

        $config = [
            'app' => [
                'environment' => strtolower(drms_runtime_env('APP_ENV', 'production')),
                'url' => rtrim(drms_runtime_env('APP_URL'), '/'),
                'timezone' => drms_runtime_env('APP_TIMEZONE', 'Asia/Manila'),
                'hosting_profile' => strtolower(drms_runtime_env('DRMS_HOSTING_PROFILE', 'standard')),
                'trust_proxy_https' => drms_runtime_env_bool('DRMS_TRUST_PROXY_HTTPS', false),
            ],
            'database' => [
                'host' => drms_runtime_env('DB_HOST', 'localhost'),
                'port' => (int) drms_runtime_env('DB_PORT', '3306'),
                'user' => drms_runtime_env('DB_USER', 'root'),
                'password' => drms_runtime_env('DB_PASS'),
                'name' => drms_runtime_env('DB_NAME', 'fixie_drms'),
            ],
            'mail' => [
                'host' => drms_runtime_env('DRMS_SMTP_HOST', 'smtp.gmail.com'),
                'port' => (int) drms_runtime_env('DRMS_SMTP_PORT', '587'),
                'username' => drms_runtime_env('DRMS_SMTP_USER'),
                'password' => drms_runtime_env('DRMS_SMTP_PASS'),
                'encryption' => strtolower(drms_runtime_env('DRMS_SMTP_SECURE', 'tls')),
                'from' => drms_runtime_env('DRMS_MAIL_FROM'),
                'from_name' => drms_runtime_env('DRMS_MAIL_FROM_NAME', 'Fixie DRMS Security'),
                'timeout_seconds' => (int) drms_runtime_env('DRMS_SMTP_TIMEOUT', '20'),
                'required' => drms_runtime_env_bool('DRMS_MAIL_REQUIRED', false),
            ],
            'uploads' => [
                'host_max_mb' => (int) drms_runtime_env('DRMS_HOST_MAX_UPLOAD_MB', '25'),
            ],
            'features' => [
                'scheduled_maintenance' => null,
                'server_backup' => null,
                'large_uploads' => null,
            ],
        ];

        $localPath = __DIR__ . '/runtime.local.php';
        if (is_file($localPath)) {
            $local = require $localPath;
            if (!is_array($local)) {
                throw new RuntimeException('config/runtime.local.php must return an array.');
            }
            $config = drms_runtime_merge($config, $local);
        }

        $allowedEnvironments = ['production', 'development', 'local', 'testing'];
        if (!in_array($config['app']['environment'] ?? null, $allowedEnvironments, true)) {
            throw new RuntimeException('Invalid application environment.');
        }

        $allowedProfiles = ['standard', 'local', 'infinityfree', 'shared', 'managed'];
        if (!in_array($config['app']['hosting_profile'] ?? null, $allowedProfiles, true)) {
            throw new RuntimeException('Invalid hosting profile.');
        }

        $timezone = trim((string) ($config['app']['timezone'] ?? ''));
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $error) {
            throw new RuntimeException('Invalid application timezone.', 0, $error);
        }

        $applicationUrl = trim((string) ($config['app']['url'] ?? ''));
        if ($applicationUrl !== '' && filter_var($applicationUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Invalid application URL.');
        }

        foreach (['host', 'user', 'name'] as $key) {
            if (trim((string) ($config['database'][$key] ?? '')) === '') {
                throw new RuntimeException('Incomplete database configuration.');
            }
        }
        $databasePort = (int) ($config['database']['port'] ?? 0);
        if ($databasePort < 1 || $databasePort > 65535) {
            throw new RuntimeException('Invalid database port.');
        }

        $profile = (string) ($config['app']['hosting_profile'] ?? 'standard');
        if ($profile === 'infinityfree') {
            $databaseHost = strtolower(trim((string) $config['database']['host']));
            if (in_array($databaseHost, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('InfinityFree requires the MySQL hostname shown in its control panel, not localhost.');
            }
            if ($databasePort !== 3306) {
                throw new RuntimeException('InfinityFree database connections must use port 3306.');
            }
            if (
                str_contains((string) $config['database']['user'], '00000000') ||
                str_contains((string) $config['database']['name'], '00000000') ||
                str_contains((string) $config['database']['password'], 'REPLACE_WITH')
            ) {
                throw new RuntimeException('Replace every InfinityFree database placeholder in config/runtime.local.php.');
            }
        }

        $mailPort = (int) ($config['mail']['port'] ?? 0);
        if ($mailPort < 1 || $mailPort > 65535) {
            throw new RuntimeException('Invalid SMTP port.');
        }
        if (!in_array($config['mail']['encryption'] ?? null, ['tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP encryption must be tls or ssl.');
        }

        $mailTimeout = (int) ($config['mail']['timeout_seconds'] ?? 0);
        if ($mailTimeout < 5 || $mailTimeout > 120) {
            throw new RuntimeException('SMTP timeout must be between 5 and 120 seconds.');
        }

        if ($profile === 'infinityfree') {
            $config['mail']['required'] = true;
            $supportedSmtpPair =
                ($mailPort === 587 && $config['mail']['encryption'] === 'tls') ||
                ($mailPort === 465 && $config['mail']['encryption'] === 'ssl');
            if (!$supportedSmtpPair) {
                throw new RuntimeException('InfinityFree SMTP must use port 587 with TLS or port 465 with SSL.');
            }
        }

        $mailRequired = (bool) ($config['mail']['required'] ?? false);
        $mailUsername = trim((string) ($config['mail']['username'] ?? ''));
        $mailPassword = trim((string) ($config['mail']['password'] ?? ''));
        $mailFrom = trim((string) ($config['mail']['from'] ?? ''));
        $mailFrom = $mailFrom !== '' ? $mailFrom : $mailUsername;
        if ($mailRequired && ($mailUsername === '' || $mailPassword === '' || $mailFrom === '')) {
            throw new RuntimeException('SMTP credentials are required for this hosting profile.');
        }
        if (
            ($mailUsername !== '' || $mailPassword !== '' || $mailFrom !== '') &&
            ($mailUsername === '' || $mailPassword === '' || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false)
        ) {
            throw new RuntimeException('SMTP credentials are incomplete or the sender address is invalid.');
        }
        if ($mailRequired && str_contains($mailPassword, 'REPLACE_WITH')) {
            throw new RuntimeException('Replace the SMTP password placeholder in config/runtime.local.php.');
        }

        $hostMaxUploadMb = (int) ($config['uploads']['host_max_mb'] ?? 0);
        if ($hostMaxUploadMb < 2 || $hostMaxUploadMb > 1024) {
            throw new RuntimeException('The hosting upload limit must be between 2 MB and 1024 MB.');
        }
        if ($profile === 'infinityfree') {
            $hostMaxUploadMb = min($hostMaxUploadMb, 10);
        }

        $restricted = ($config['app']['hosting_profile'] ?? '') === 'infinityfree';
        foreach (['scheduled_maintenance', 'server_backup', 'large_uploads'] as $feature) {
            if (($config['features'][$feature] ?? null) === null) {
                $config['features'][$feature] = !$restricted;
            }
            if (!is_bool($config['features'][$feature])) {
                throw new RuntimeException('Invalid feature setting: ' . $feature);
            }
            if ($restricted) {
                // These operations require capabilities InfinityFree does not
                // provide. An accidental local override must not re-enable them.
                $config['features'][$feature] = false;
            }
        }

        $config['app']['url'] = $applicationUrl;
        $config['app']['timezone'] = $timezone;
        $config['app']['trust_proxy_https'] = (bool) ($config['app']['trust_proxy_https'] ?? false);
        $config['database']['port'] = $databasePort;
        $config['mail']['port'] = $mailPort;
        $config['mail']['timeout_seconds'] = $mailTimeout;
        $config['mail']['required'] = $mailRequired;
        $config['mail']['from'] = $mailFrom;
        $config['uploads']['host_max_mb'] = $hostMaxUploadMb;
        date_default_timezone_set($timezone);

        return $config;
    }
}

if (!function_exists('drms_runtime_mail_is_configured')) {
    function drms_runtime_mail_is_configured(): bool
    {
        $mail = drms_runtime_section('mail');
        return trim((string) $mail['host']) !== ''
            && trim((string) $mail['username']) !== ''
            && trim((string) $mail['password']) !== ''
            && filter_var((string) $mail['from'], FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('drms_runtime_host_upload_limit_mb')) {
    function drms_runtime_host_upload_limit_mb(): int
    {
        return (int) drms_runtime_section('uploads')['host_max_mb'];
    }
}

if (!function_exists('drms_runtime_section')) {
    function drms_runtime_section(string $section): array
    {
        $config = drms_runtime_config();
        if (!isset($config[$section]) || !is_array($config[$section])) {
            throw new InvalidArgumentException('Unknown runtime section: ' . $section);
        }
        return $config[$section];
    }
}

if (!function_exists('drms_runtime_feature_enabled')) {
    function drms_runtime_feature_enabled(string $feature): bool
    {
        $features = drms_runtime_section('features');
        if (!array_key_exists($feature, $features)) {
            throw new InvalidArgumentException('Unknown runtime feature: ' . $feature);
        }
        return $features[$feature] === true;
    }
}

if (!function_exists('drms_runtime_database_timezone_value')) {
    function drms_runtime_database_timezone_value(): string
    {
        $timezone = new DateTimeZone((string) drms_runtime_section('app')['timezone']);
        return (new DateTimeImmutable('now', $timezone))->format('P');
    }
}

if (!function_exists('drms_runtime_database_timezone')) {
    function drms_runtime_database_timezone(mysqli $connection): void
    {
        $offset = drms_runtime_database_timezone_value();
        $statement = $connection->prepare('SET time_zone = ?');
        $statement->bind_param('s', $offset);
        $statement->execute();
        $statement->close();
    }
}

if (!function_exists('drms_runtime_request_is_https')) {
    function drms_runtime_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        $app = drms_runtime_section('app');
        if (empty($app['trust_proxy_https'])) {
            return false;
        }

        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }
}
