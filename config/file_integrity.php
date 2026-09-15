<?php
declare(strict_types=1);

final class DrmsFileIntegrityException extends RuntimeException
{
}

if (!function_exists('drms_file_integrity_is_hash')) {
    function drms_file_integrity_is_hash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{64}$/i', trim($value)) === 1;
    }
}

if (!function_exists('drms_file_integrity_hash')) {
    function drms_file_integrity_hash(string $absolutePath): string
    {
        if (!is_file($absolutePath) || is_link($absolutePath)) {
            throw new DrmsFileIntegrityException(
                'The protected file is unavailable.'
            );
        }
        $hash = hash_file('sha256', $absolutePath);
        if (!is_string($hash) || !drms_file_integrity_is_hash($hash)) {
            throw new DrmsFileIntegrityException(
                'The protected file could not be verified.'
            );
        }
        return strtolower($hash);
    }
}

if (!function_exists('drms_file_integrity_verify')) {
    function drms_file_integrity_verify(
        string $absolutePath,
        mixed $expectedHash
    ): string {
        if (!drms_file_integrity_is_hash($expectedHash)) {
            throw new DrmsFileIntegrityException(
                'The integrity baseline for this file is unavailable.'
            );
        }
        $expected = strtolower(trim((string) $expectedHash));
        $actual = drms_file_integrity_hash($absolutePath);
        if (!hash_equals($expected, $actual)) {
            throw new DrmsFileIntegrityException(
                'The stored file does not match its protected integrity hash.'
            );
        }
        return $actual;
    }
}
