<?php
// Shared session policy for authentication and protected application requests.
if (PHP_SAPI === 'cli') {
    return;
}

// Production-safe error policy. Detailed errors are written to the configured
// PHP error log instead of being exposed in the browser. Developers can opt in
// to browser diagnostics locally by setting APP_ENV=development.
$drms_environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
$drms_show_browser_errors = in_array(
    $drms_environment,
    ['development', 'local', 'testing'],
    true
);

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $drms_show_browser_errors ? '1' : '0');
ini_set('display_startup_errors', $drms_show_browser_errors ? '1' : '0');

unset($drms_environment, $drms_show_browser_errors);

$sessionWasAlreadyActive = session_status() === PHP_SESSION_ACTIVE;
$sessionCookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$sessionCookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $sessionCookieSecure,
    'httponly' => true,
    'samesite' => 'Strict'
];
$sessionCookieOptions = $sessionCookieParams;
unset($sessionCookieOptions['lifetime']);
$sessionCookieOptions['expires'] = 0;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $sessionCookieSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.gc_maxlifetime', '7200');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '5');

    session_set_cookie_params($sessionCookieParams);
    session_start();
}

// A restore replaces both the database and uploaded-record repository. Block
// concurrent application requests during that short critical section so no PO,
// PR, payment, or document write can land between the two restored snapshots.
$drms_restore_marker = dirname(__DIR__) . '/storage/restore_in_progress.json';
$drms_restore_handler = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ===
    'backup_handler.php';

if (is_file($drms_restore_marker)) {
    $drms_restore_marker_age = time() - (int) @filemtime($drms_restore_marker);

    // Recover automatically from a marker left by a terminated PHP process.
    if ($drms_restore_marker_age > 14400) {
        @unlink($drms_restore_marker);
    } elseif (!$drms_restore_handler) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: text/html; charset=UTF-8');
        exit(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>System restoration in progress</title></head>' .
            '<body style="margin:0;background:#f8fafc;color:#0f172a;' .
            'font:14px/1.5 Arial,sans-serif;display:grid;min-height:100vh;' .
            'place-items:center"><main style="width:min(440px,calc(100% - 32px));' .
            'background:#fff;border:1px solid #cbd5e1;border-radius:12px;' .
            'padding:24px;box-sizing:border-box;text-align:center">' .
            '<h1 style="font-size:18px;margin:0 0 8px">System restoration in progress</h1>' .
            '<p style="margin:0;color:#475569">Please wait a moment, then refresh this page.</p>' .
            '</main></body></html>'
        );
    }
}

unset(
    $drms_restore_marker,
    $drms_restore_handler,
    $drms_restore_marker_age
);

// Some legacy handlers start the session before loading db_connect.php. Reissue
// the same server-generated ID with the secure cookie attributes in that case.
if ($sessionWasAlreadyActive && session_status() === PHP_SESSION_ACTIVE && session_id() !== '' && !headers_sent()) {
    setcookie(session_name(), session_id(), $sessionCookieOptions);
}
