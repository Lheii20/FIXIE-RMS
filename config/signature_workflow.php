<?php

/**
 * Fixie DRMS - Signature Phase 1
 *
 * Shared authority and validation rules for official declaration requests and
 * electronic signatures. Including this file does not change an existing
 * workflow by itself. Later phases call these functions explicitly.
 */

if (!function_exists('drms_signature_normalize_text')) {
    function drms_signature_normalize_text(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? $value : '';
    }
}

if (!function_exists('drms_signature_tables_ready')) {
    function drms_signature_tables_ready(mysqli $conn): bool
    {
        $requiredTables = [
            'official_declaration_requests',
            'user_signature_profiles',
            'document_signature_events',
        ];

        $tables = [];
        foreach ($conn->query('SHOW TABLES') as $row) {
            $tables[] = (string) array_values($row)[0];
        }
        return array_diff($requiredTables, $tables) === [];
    }
}

if (!function_exists('drms_signature_authorized_roles')) {
    /**
     * Returns the only role or roles allowed to sign a controlled stage.
     * Admin is deliberately excluded because administration access does not
     * make a user an organizational signatory.
     */
    function drms_signature_authorized_roles(
        string $recordModule,
        string $signatureStage = ''
    ): array {
        $module = strtolower(drms_signature_normalize_text($recordModule));
        $stage = strtolower(drms_signature_normalize_text($signatureStage));

        if (strpos($module, 'purchase requisition') !== false || $module === 'prf') {
            if ($stage === 'gm review') {
                return ['GM'];
            }
            if ($stage === 'finance review') {
                return ['Finance'];
            }
            if ($stage === 'owner approval' || $stage === 'final approval') {
                return ['President'];
            }

            return [];
        }

        if (strpos($module, 'client po') !== false) {
            return ['GM'];
        }

        if (strpos($module, 'purchase order') !== false || $module === 'po') {
            if ($stage === 'gm review') {
                return ['GM'];
            }
            if ($stage === 'finance review') {
                return ['Finance'];
            }
            if (
                $stage === 'owner approval' ||
                $stage === 'president approval' ||
                $stage === 'final approval'
            ) {
                return ['President'];
            }

            return [];
        }

        if (
            strpos($module, 'delivery receipt') !== false ||
            $module === 'client delivery receipt'
        ) {
            if (
                $stage === 'delivery completion' ||
                $stage === 'client handover certification'
            ) {
                return ['Supply Chain'];
            }

            return [];
        }

        if (
            strpos($module, 'client payment confirmation') !== false ||
            $module === 'payment confirmation'
        ) {
            if (
                $stage === 'finance verification' ||
                $stage === 'payment verification'
            ) {
                return ['Finance'];
            }

            return [];
        }

        if (
            strpos($module, 'delivery request') !== false ||
            strpos($module, 'logistics plan') !== false ||
            $module === 'delivery and logistics'
        ) {
            if (
                $stage === 'supply chain approval' ||
                $stage === 'logistics approval'
            ) {
                return ['Supply Chain'];
            }

            return [];
        }

        // Manually uploaded general records are routed through the single
        // General Manager declaration queue. Keeping this rule here matches
        // the page and transaction-level reviewer guard.
        if ($module === 'general document' || $module === 'document') {
            return ['GM'];
        }

        return [];
    }
}

if (!function_exists('drms_signature_role_can_sign')) {
    function drms_signature_role_can_sign(
        string $userRole,
        string $recordModule,
        string $signatureStage = ''
    ): bool {
        return in_array(
            drms_signature_normalize_text($userRole),
            drms_signature_authorized_roles($recordModule, $signatureStage),
            true
        );
    }
}

if (!function_exists('drms_signature_role_can_request_declaration')) {
    function drms_signature_role_can_request_declaration(string $userRole): bool
    {
        return in_array(
            drms_signature_normalize_text($userRole),
            [
                'Sales Staff',
                'Procurement',
                'Finance',
                'Supply Chain',
                'GM',
                'President',
            ],
            true
        );
    }
}

if (!function_exists('drms_signature_validate_hash')) {
    function drms_signature_validate_hash(string $fileHash): string
    {
        $fileHash = strtolower(trim($fileHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $fileHash)) {
            throw new InvalidArgumentException(
                'The document does not have a valid SHA-256 fingerprint.'
            );
        }

        return $fileHash;
    }
}

if (!function_exists('drms_signature_generate_reference')) {
    function drms_signature_generate_reference(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix));
        if ($prefix === '') {
            $prefix = 'SIG';
        }

        try {
            $randomPart = strtoupper(bin2hex(random_bytes(8)));
        } catch (Throwable $exception) {
            $randomPart = strtoupper(hash('sha256', uniqid('', true)));
            $randomPart = substr($randomPart, 0, 16);
        }

        return substr($prefix, 0, 8) . '-' . date('Ymd') . '-' . $randomPart;
    }
}

if (!function_exists('drms_signature_verify_account_password')) {
    /**
     * Re-authenticates the current signatory immediately before signing.
     * Never log, persist, or return the supplied plain-text password.
     */
    function drms_signature_verify_account_password(
        mysqli $conn,
        int $userId,
        string $password
    ): array {
        if ($userId < 1 || $password === '') {
            throw new RuntimeException(
                'Enter your current account password to continue signing.'
            );
        }

        $statement = $conn->prepare(
            "SELECT user_id, full_name, role, password_hash
             FROM users
             WHERE user_id = ?
               AND status = 'Active'
             LIMIT 1"
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (
            !$user ||
            empty($user['password_hash']) ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            throw new RuntimeException(
                'The password is incorrect. The document was not signed.'
            );
        }

        unset($user['password_hash']);

        return $user;
    }
}

if (!function_exists('drms_signature_client_ip')) {
    function drms_signature_client_ip(): ?string
    {
        $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        return filter_var($ipAddress, FILTER_VALIDATE_IP)
            ? $ipAddress
            : null;
    }
}

if (!function_exists('drms_signature_user_agent')) {
    function drms_signature_user_agent(): ?string
    {
        $userAgent = drms_signature_normalize_text(
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        return $userAgent === '' ? null : substr($userAgent, 0, 255);
    }
}

if (!function_exists('drms_signature_assert_document_is_signable')) {
    /**
     * Confirms that the pending signature is still bound to the unchanged
     * document version and checksum recorded when the request was submitted.
     */
    function drms_signature_assert_document_is_signable(
        array $document,
        string $expectedHash,
        string $expectedVersion
    ): void {
        $actualHash = drms_signature_validate_hash(
            (string) ($document['file_hash'] ?? '')
        );
        $expectedHash = drms_signature_validate_hash($expectedHash);
        $actualVersion = drms_signature_normalize_text(
            (string) ($document['current_version'] ?? '')
        );
        $expectedVersion = drms_signature_normalize_text($expectedVersion);

        if (
            !hash_equals($expectedHash, $actualHash) ||
            $expectedVersion === '' ||
            $actualVersion !== $expectedVersion
        ) {
            throw new RuntimeException(
                'The document changed after the signature request was submitted. Submit a new request for the latest version.'
            );
        }

        if (($document['record_phase'] ?? '') === 'Official') {
            throw new RuntimeException('This document is already an Official Record.');
        }

        if (($document['status'] ?? '') !== 'Active') {
            throw new RuntimeException('Only an active document can be signed or verified.');
        }
    }
}

