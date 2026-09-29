<?php
declare(strict_types=1);

/**
 * Normalizes real-world reference values without inventing a replacement.
 *
 * These values come from banks, cheques, receipts, suppliers, and clients;
 * they remain user-entered evidence. The normalized representation removes
 * accidental spacing and uses a consistent case for reliable duplicate checks.
 */
if (!function_exists('drms_normalize_external_reference')) {
    function drms_normalize_external_reference(
        string $reference,
        string $label = 'Reference'
    ): string {
        $reference = trim($reference);

        if (preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new DomainException(
                $label . ' contains unsupported control characters.'
            );
        }

        $reference = preg_replace('/\s+/', '', $reference) ?? '';

        return strtoupper($reference);
    }
}
