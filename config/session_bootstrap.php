<?php
// Shared session policy for authentication and protected application requests.
require_once __DIR__ . '/runtime.php';

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

try {
    $drms_runtime = drms_runtime_config();
} catch (Throwable $runtime_error) {
    error_log('Fixie DRMS runtime configuration error: ' . $runtime_error->getMessage());
    if (PHP_SAPI === 'cli') {
        throw $runtime_error;
    }
    http_response_code(503);
    exit('System Maintenance: The server configuration is incomplete.');
}

if (PHP_SAPI === 'cli') {
    unset($drms_runtime);
    return;
}

// Production-safe error policy. Detailed errors are written to the configured
// PHP error log instead of being exposed in the browser.
$drms_environment = (string) $drms_runtime['app']['environment'];
$drms_show_browser_errors = in_array(
    $drms_environment,
    ['development', 'local', 'testing'],
    true
);

ini_set('display_errors', $drms_show_browser_errors ? '1' : '0');
ini_set('display_startup_errors', $drms_show_browser_errors ? '1' : '0');

// The Phase 5A clone has a local marker containing the unique session name and
// cookie path. Reading this server-side keeps the main and E2E browser sessions
// isolated even when PHP ignores per-directory php_value directives.
$drms_session_name = '';
$drms_session_cookie_path = '/';
$drms_e2e_marker_path = dirname(__DIR__) . '/.fixie-e2e-environment.json';
if (is_file($drms_e2e_marker_path)) {
    try {
        $drms_e2e_marker_raw = file_get_contents($drms_e2e_marker_path);
        if ($drms_e2e_marker_raw === false) {
            throw new RuntimeException('The E2E marker could not be read.');
        }
        $drms_e2e_marker = json_decode(
            $drms_e2e_marker_raw,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $drms_marker_name = trim((string) ($drms_e2e_marker['session_name'] ?? ''));
        $drms_marker_path = trim((string) ($drms_e2e_marker['session_cookie_path'] ?? ''));
        $drms_marker_format = (string) ($drms_e2e_marker['format'] ?? '');
        if (
            $drms_marker_format !== 'FIXIE_DRMS_ISOLATED_E2E' ||
            preg_match('/^[A-Za-z][A-Za-z0-9]{7,63}$/', $drms_marker_name) !== 1 ||
            preg_match('#^/[A-Za-z0-9._~!$&()+,=@%/-]*/$#', $drms_marker_path) !== 1 ||
            str_contains($drms_marker_path, '//')
        ) {
            throw new RuntimeException('The E2E session policy is invalid.');
        }
        $drms_session_name = $drms_marker_name;
        $drms_session_cookie_path = $drms_marker_path;
    } catch (Throwable $marker_error) {
        error_log('Fixie DRMS E2E marker error: ' . $marker_error->getMessage());
        http_response_code(503);
        exit('System Maintenance: The test-environment configuration is invalid.');
    }
}

$sessionWasAlreadyActive = session_status() === PHP_SESSION_ACTIVE;
$sessionCookieSecure = drms_runtime_request_is_https();
if (
    !$sessionWasAlreadyActive &&
    $drms_session_name !== '' &&
    session_status() === PHP_SESSION_NONE
) {
    session_name($drms_session_name);
}
$sessionCookieParams = [
    'lifetime' => 0,
    'path' => $drms_session_cookie_path,
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
// concurrent application requests during that short critical section.
$drms_restore_marker = dirname(__DIR__) . '/storage/restore_in_progress.json';
$drms_restore_handler = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ===
    'backup_handler.php';

if (is_file($drms_restore_marker)) {
    $drms_restore_marker_age = time() - (int) @filemtime($drms_restore_marker);
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
// the same server-generated ID with the final cookie attributes in that case.
if ($sessionWasAlreadyActive && session_status() === PHP_SESSION_ACTIVE && session_id() !== '' && !headers_sent()) {
    setcookie(session_name(), session_id(), $sessionCookieOptions);
}

unset(
    $drms_runtime,
    $drms_environment,
    $drms_show_browser_errors,
    $drms_session_name,
    $drms_session_cookie_path,
    $drms_e2e_marker_path,
    $drms_e2e_marker_raw,
    $drms_e2e_marker,
    $drms_marker_name,
    $drms_marker_path,
    $drms_marker_format,
    $sessionWasAlreadyActive,
    $sessionCookieSecure,
    $sessionCookieParams,
    $sessionCookieOptions
);

