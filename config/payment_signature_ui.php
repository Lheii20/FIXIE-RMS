<?php

declare(strict_types=1);

if (!function_exists('drms_payment_signature_chip')) {
    function drms_payment_signature_chip(
        array $evidence,
        int $paymentId,
        bool $linked = true
    ): string {
        $state = (string) ($evidence['state'] ?? 'legacy');
        $event = is_array($evidence['event'] ?? null)
            ? $evidence['event']
            : [];

        if ($state === 'verified') {
            $label = 'Finance e-signed';
            $icon = 'fa-circle-check';
            $title = 'Verified Finance signature by ' .
                (string) ($event['signer_name'] ?? 'Finance');
        } elseif ($state === 'invalid') {
            $label = 'Signature check failed';
            $icon = 'fa-triangle-exclamation';
            $title = 'The stored Finance signature did not pass verification.';
        } else {
            $state = 'legacy';
            $label = 'Pre-e-sign payment';
            $icon = 'fa-clock-rotate-left';
            $title = 'This payment predates Finance electronic signing.';
        }

        $content = sprintf(
            '<i class="fas %s" aria-hidden="true"></i><span>%s</span>',
            htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
        if (!$linked) {
            return sprintf(
                '<span class="payment-esign-chip payment-esign-chip--%s" title="%s">%s</span>',
                htmlspecialchars($state, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $content
            );
        }

        return sprintf(
            '<a class="payment-esign-chip payment-esign-chip--%s" href="payment_signature_evidence.php?payment_id=%d" title="%s" aria-label="Open payment signature evidence: %s">%s</a>',
            htmlspecialchars($state, ENT_QUOTES, 'UTF-8'),
            $paymentId,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $content
        );
    }
}
