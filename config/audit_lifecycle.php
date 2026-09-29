<?php

declare(strict_types=1);

/**
 * Fixie DRMS audit lifecycle policy.
 *
 * Compliance events are never selected for automatic removal.  This policy
 * applies only to low-value navigation telemetry that does not prove a
 * business decision, approval, signature, payment, record filing, download,
 * or security event.
 *
 * Change the values here only after the company approves its retention rule.
 */

if (!function_exists('drms_audit_lifecycle_policy')) {
    function drms_audit_lifecycle_policy(): array
    {
        return [
            // Keep navigation telemetry long enough for short-term usage
            // analysis, while preventing it from becoming the audit table's
            // permanent majority.
            'telemetry_retention_days' => 90,

            // Small batches avoid a long table lock on shared hosting.
            'cleanup_batch_size' => 500,

            // Only these low-value automatic telemetry actions are eligible.
            // Do not add approval, signature, payment, file, login, download,
            // workflow, or record-management action types to this list.
            'telemetry_action_types' => [
                'PAGE_VIEW',
                'SEARCH',
                'FILTER',
            ],
        ];
    }
}

if (!function_exists('drms_audit_lifecycle_validate_policy')) {
    function drms_audit_lifecycle_validate_policy(array $policy): array
    {
        $days = (int) ($policy['telemetry_retention_days'] ?? 0);
        $batchSize = (int) ($policy['cleanup_batch_size'] ?? 0);
        $actionTypes = $policy['telemetry_action_types'] ?? null;

        if ($days < 30 || $days > 3650) {
            throw new RuntimeException('Audit telemetry retention must be between 30 and 3650 days.');
        }

        if ($batchSize < 50 || $batchSize > 2000) {
            throw new RuntimeException('Audit telemetry cleanup batch size must be between 50 and 2000.');
        }

        if (!is_array($actionTypes) || $actionTypes === []) {
            throw new RuntimeException('At least one telemetry action type is required.');
        }

        $cleanTypes = [];
        foreach ($actionTypes as $actionType) {
            $actionType = strtoupper(trim((string) $actionType));
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $actionType)) {
                throw new RuntimeException('Invalid audit telemetry action type.');
            }
            $cleanTypes[$actionType] = $actionType;
        }

        return [
            'telemetry_retention_days' => $days,
            'cleanup_batch_size' => $batchSize,
            'telemetry_action_types' => array_values($cleanTypes),
        ];
    }
}

