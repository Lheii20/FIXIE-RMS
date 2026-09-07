<?php

declare(strict_types=1);

/**
 * Central storage boundary for uploaded business records.
 *
 * Uploaded files remain below /uploads so the application stays compatible
 * with shared hosting. Direct web access is denied by uploads/.htaccess;
 * authenticated delivery continues through download.php.
 */

function drms_storage_root(): string
{
    $root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('The protected upload storage is unavailable.');
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function drms_storage_path_is_within(string $path, string $root): bool
{
    $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR);
    $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
    $prefix = $normalizedRoot . DIRECTORY_SEPARATOR;

    if (DIRECTORY_SEPARATOR === '\\') {
        return strcasecmp($normalizedPath, $normalizedRoot) === 0
            || strncasecmp($normalizedPath, $prefix, strlen($prefix)) === 0;
    }

    return $normalizedPath === $normalizedRoot
        || strncmp($normalizedPath, $prefix, strlen($prefix)) === 0;
}

function drms_storage_clean_relative_path(string $storedPath): string
{
    if ($storedPath === '' || strpos($storedPath, "\0") !== false) {
        throw new RuntimeException('The requested storage path is invalid.');
    }

    $normalized = str_replace('\\', '/', trim($storedPath));
    $normalized = preg_replace('#^\./+#', '', $normalized) ?? $normalized;
    $normalized = preg_replace('#^uploads/+#i', '', $normalized) ?? $normalized;
    $normalized = ltrim($normalized, '/');

    if ($normalized === '') {
        throw new RuntimeException('The requested storage path is invalid.');
    }

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('The requested storage path is invalid.');
        }
    }

    return $normalized;
}

function drms_storage_resolve_existing_file(string $storedPath): string
{
    $root = drms_storage_root();
    $relativePath = drms_storage_clean_relative_path($storedPath);
    $candidate = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);

    if (
        $resolved === false
        || !is_file($resolved)
        || is_link($candidate)
        || !drms_storage_path_is_within($resolved, $root)
    ) {
        throw new RuntimeException('The requested file is unavailable.');
    }

    return $resolved;
}

function drms_storage_validate_destination(string $destination): string
{
    if ($destination === '' || strpos($destination, "\0") !== false) {
        throw new RuntimeException('The upload destination is invalid.');
    }

    $root = drms_storage_root();
    $parent = realpath(dirname($destination));
    $fileName = basename($destination);

    if (
        $parent === false
        || !is_dir($parent)
        || !drms_storage_path_is_within($parent, $root)
        || $fileName === ''
        || $fileName === '.'
        || $fileName === '..'
        || is_link($destination)
        || file_exists($destination)
    ) {
        throw new RuntimeException('The upload destination is outside protected storage or already exists.');
    }

    return $parent . DIRECTORY_SEPARATOR . $fileName;
}

function drms_storage_move_uploaded_file(string $temporaryPath, string $destination): bool
{
    try {
        $safeDestination = drms_storage_validate_destination($destination);
    } catch (RuntimeException $error) {
        error_log('Secure upload destination rejected: ' . $error->getMessage());
        return false;
    }

    if (!is_uploaded_file($temporaryPath)) {
        error_log('Secure upload rejected: the source is not an HTTP upload.');
        return false;
    }

    if (!move_uploaded_file($temporaryPath, $safeDestination)) {
        return false;
    }

    $resolved = realpath($safeDestination);
    $root = drms_storage_root();
    if (
        $resolved === false
        || !is_file($resolved)
        || is_link($safeDestination)
        || !drms_storage_path_is_within($resolved, $root)
    ) {
        if (is_file($safeDestination) && !is_link($safeDestination)) {
            @unlink($safeDestination);
        }
        error_log('Secure upload rejected after storage-boundary verification.');
        return false;
    }

    // Best effort: shared hosts may control the final mode through their own ACL.
    @chmod($resolved, 0640);
    return true;
}

