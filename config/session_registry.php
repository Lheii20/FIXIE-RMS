<?php
declare(strict_types=1);

// A per-browser random key is stored only in the PHP session. The database
// keeps its SHA-256 digest, never the usable key or PHP session ID.
function drms_registry_key_hash(): string
{
    $key = (string) ($_SESSION['drms_device_key'] ?? '');
    return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? hash('sha256', $key) : '';
}

function drms_registry_touch(mysqli $conn, int $userId, string $accountToken): bool
{
    if ($userId < 1 || $accountToken === '') return false;
    $accountHash = hash('sha256', $accountToken);
    $keyHash = drms_registry_key_hash();

    if ($keyHash === '') {
        $deviceKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $deviceKey);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $conn->prepare(
            'INSERT INTO user_sessions
             (user_id, device_key_hash, auth_token_hash, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issss', $userId, $keyHash, $accountHash, $ip, $agent);
        $stmt->execute();
        $stmt->close();
        $_SESSION['drms_device_key'] = $deviceKey;
        $_SESSION['drms_presence_synced_at'] = time();
        return true;
    }

    $stmt = $conn->prepare(
        'SELECT auth_token_hash, ended_at
         FROM user_sessions WHERE user_id = ? AND device_key_hash = ? LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $keyHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['ended_at'] !== null) return false;

    $lastSync = (int) ($_SESSION['drms_presence_synced_at'] ?? 0);
    if (!hash_equals((string) $row['auth_token_hash'], $accountHash) || time() - $lastSync >= 20) {
        $update = $conn->prepare(
            'UPDATE user_sessions SET auth_token_hash = ?, last_seen_at = NOW()
             WHERE user_id = ? AND device_key_hash = ? AND ended_at IS NULL'
        );
        $update->bind_param('sis', $accountHash, $userId, $keyHash);
        $update->execute();
        $update->close();
        $_SESSION['drms_presence_synced_at'] = time();
    }
    return true;
}

function drms_registry_end_current(mysqli $conn, int $userId, string $reason): void
{
    $keyHash = drms_registry_key_hash();
    if ($userId < 1 || $keyHash === '') return;
    $stmt = $conn->prepare(
        'UPDATE user_sessions SET ended_at = NOW(), ended_reason = ?
         WHERE user_id = ? AND device_key_hash = ? AND ended_at IS NULL'
    );
    $stmt->bind_param('sis', $reason, $userId, $keyHash);
    $stmt->execute();
    $stmt->close();
}

function drms_registry_device_label(string $agent): string
{
    $browser = str_contains($agent, 'Edg/') ? 'Edge'
        : (str_contains($agent, 'Chrome/') ? 'Chrome'
        : (str_contains($agent, 'Firefox/') ? 'Firefox'
        : (str_contains($agent, 'Safari/') ? 'Safari' : 'Browser')));
    $platform = str_contains($agent, 'Windows') ? 'Windows'
        : (str_contains($agent, 'Android') ? 'Android'
        : ((str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) ? 'iOS'
        : (str_contains($agent, 'Mac OS') ? 'macOS'
        : (str_contains($agent, 'Linux') ? 'Linux' : 'Unknown device'))));
    return $browser . ' on ' . $platform;
}
