<?php

/**
 * Convert a same-application URL into a safe project-relative destination.
 * External URLs, handler endpoints, malformed paths, and traversal attempts
 * are rejected before a Back button can use them.
 */
function drms_normalize_navigation_target($candidate)
{
    if (!is_string($candidate)) {
        return null;
    }

    $candidate = trim(html_entity_decode($candidate, ENT_QUOTES, 'UTF-8'));
    if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return null;
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return null;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    if (isset($parts['host'])) {
        $request_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $request_host = preg_replace('/:\d+$/', '', $request_host);
        $candidate_host = strtolower((string) $parts['host']);

        if ($request_host === '' || !hash_equals($request_host, $candidate_host)) {
            return null;
        }

        if (
            isset($parts['scheme']) &&
            !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            return null;
        }
    } elseif (isset($parts['scheme'])) {
        return null;
    }

    $path = str_replace('\\', '/', (string) ($parts['path'] ?? ''));
    if ($path === '') {
        return null;
    }

    $script_name = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $application_path = rtrim(str_replace('\\', '/', dirname($script_name)), '/.');

    if ($path[0] === '/') {
        if ($application_path !== '' && $application_path !== '/') {
            $application_prefix = $application_path . '/';
            if (strpos($path, $application_prefix) !== 0) {
                return null;
            }
            $path = substr($path, strlen($application_prefix));
        } else {
            $path = ltrim($path, '/');
        }
    }

    if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
        return null;
    }

    $path = ltrim($path, '/');
    if (
        $path === '' ||
        strpos($path, '..') !== false ||
        !preg_match('/^[A-Za-z0-9_\/-]+\.php$/', $path)
    ) {
        return null;
    }

    $lower_path = strtolower($path);
    if (
        strpos($lower_path, 'actions/') === 0 ||
        in_array(
            $lower_path,
            ['index.php', 'logout.php', 'forgot_password.php', 'reset_password.php'],
            true
        )
    ) {
        return null;
    }

    $target = $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $target .= '?' . $parts['query'];
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $target .= '#' . rawurlencode(rawurldecode($parts['fragment']));
    }

    return $target;
}

/**
 * Resolve and remember where a record-detail page was opened from.
 * The explicit return_url wins, followed by the same-origin referrer and the
 * previously remembered location for this record.
 */
function drms_contextual_back_url($fallback, $context_key)
{
    $safe_fallback = drms_normalize_navigation_target($fallback);
    if ($safe_fallback === null) {
        $safe_fallback = 'dashboard.php';
    }

    if (!isset($_SESSION['drms_navigation_context']) || !is_array($_SESSION['drms_navigation_context'])) {
        $_SESSION['drms_navigation_context'] = [];
    }

    $context_key = preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $context_key);
    if ($context_key === '') {
        return $safe_fallback;
    }

    $candidates = [];
    if (isset($_GET['return_url']) && is_string($_GET['return_url'])) {
        $candidates[] = $_GET['return_url'];
    }
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $candidates[] = (string) $_SERVER['HTTP_REFERER'];
    }

    $current_script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ($candidates as $candidate) {
        $target = drms_normalize_navigation_target($candidate);
        if ($target === null) {
            continue;
        }

        $target_path = (string) (parse_url($target, PHP_URL_PATH) ?? '');
        if ($target_path === '' || basename($target_path) === $current_script) {
            continue;
        }

        $_SESSION['drms_navigation_context'][$context_key] = $target;

        if (count($_SESSION['drms_navigation_context']) > 100) {
            $_SESSION['drms_navigation_context'] = array_slice(
                $_SESSION['drms_navigation_context'],
                -100,
                null,
                true
            );
        }

        return $target;
    }

    $remembered = $_SESSION['drms_navigation_context'][$context_key] ?? null;
    $safe_remembered = drms_normalize_navigation_target($remembered);
    return $safe_remembered ?? $safe_fallback;
}
