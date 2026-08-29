<?php

/**
 * Shared public-feedback helpers for PO lifecycle pages and handlers.
 * Technical details are written to the PHP error log and are never displayed
 * directly to the user.
 */

if (!function_exists('drms_feedback_clean_text')) {
    function drms_feedback_clean_text($value, $limit = 320)
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
        }

        return strlen($text) > $limit
            ? substr($text, 0, max(0, $limit - 3)) . '...'
            : $text;
    }
}

if (!function_exists('drms_public_feedback_message')) {
    function drms_public_feedback_message($raw_message, $fallback)
    {
        $message = drms_feedback_clean_text($raw_message);
        $fallback = drms_feedback_clean_text($fallback);

        if ($message === '') {
            return '';
        }

        $message_key = strtolower(str_replace(
            [' ', '_', '-'],
            '',
            $message
        ));

        $legacy_code_messages = [
            'databaseerror' =>
                'The record could not be saved. No changes were completed. Please try again.',
            'filesizeexceeded' =>
                'The selected file is too large. Choose a file within the allowed size limit.',
            'invalidfileextension' =>
                'The selected file type is not allowed. Choose an accepted document or image file.',
            'invalidfiletypesecurity' =>
                'The selected file could not be verified. Choose a valid document or image file.',
            'duplicatefiledetected' =>
                'This file has already been uploaded. Review the existing attachment or choose another file.',
            'uploadfailed' =>
                'The file could not be uploaded. Check the file and try again.',
            'forceloggedoutbyadmin' =>
                'Your session is no longer active. Please sign in again.',
        ];

        if (isset($legacy_code_messages[$message_key])) {
            return $legacy_code_messages[$message_key];
        }

        $technical_patterns = [
            '/\bphase\s*[0-9]/i',
            '/\bmigration\b/i',
            '/\b(database|mysqli|sqlstate|sql syntax)\b/i',
            '/\b(unknown column|unknown table|foreign key|constraint)\b/i',
            '/\b(stack trace|fatal error|uncaught exception)\b/i',
            '/\b(execute failed|query failed)\b/i',
        ];

        foreach ($technical_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return $fallback !== ''
                    ? $fallback
                    : 'The requested action is temporarily unavailable. Please try again.';
            }
        }

        return $message;
    }
}

if (!function_exists('drms_log_workflow_failure')) {
    function drms_log_workflow_failure($context, Throwable $error)
    {
        error_log(sprintf(
            '[%s] %s (code %s): %s',
            drms_feedback_clean_text($context, 120),
            get_class($error),
            (string) $error->getCode(),
            $error->getMessage()
        ));
    }
}

if (!function_exists('drms_redirect_with_feedback')) {
    function drms_redirect_with_feedback($location, $type, $message)
    {
        $type = in_array($type, ['error', 'success', 'warning'], true)
            ? $type
            : 'error';
        $separator = strpos($location, '?') === false ? '?' : '&';
        header(
            'Location: ' . $location . $separator . $type . '=' .
            rawurlencode(drms_feedback_clean_text($message))
        );
        exit();
    }
}

