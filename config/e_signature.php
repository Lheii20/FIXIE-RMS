<?php

declare(strict_types=1);

/**
 * Fixie DRMS - Signature Phase 3
 *
 * Reusable electronic-signature rules. This file deliberately does not alter
 * any approval status by itself; PRF and PO actions will call it in later
 * phases after the signatory has completed this profile setup.
 */

require_once __DIR__ . '/signature_workflow.php';

if (!function_exists('drms_esign_signatory_roles')) {
    function drms_esign_signatory_roles(): array
    {
        return ['GM', 'Finance', 'President', 'Supply Chain'];
    }
}

if (!function_exists('drms_esign_profile_role_allowed')) {
    function drms_esign_profile_role_allowed(string $role): bool
    {
        return in_array(drms_signature_normalize_text($role), drms_esign_signatory_roles(), true);
    }
}

if (!function_exists('drms_esign_text')) {
    function drms_esign_text($value, int $maximumLength, string $label): string
    {
        if (!is_string($value)) {
            throw new DomainException('Enter a valid ' . strtolower($label) . '.');
        }

        $text = drms_signature_normalize_text($value);
        if ($text === '' || mb_strlen($text) > $maximumLength) {
            throw new DomainException($label . ' is required and must fit within the displayed limit.');
        }

        return $text;
    }
}

if (!function_exists('drms_esign_user')) {
    function drms_esign_user(mysqli $conn, int $userId): array
    {
        $statement = $conn->prepare(
            "SELECT user_id, full_name, role, status, account_status, require_pass_change, password_hash
             FROM users
             WHERE user_id = ?
             LIMIT 1"
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$user || $user['status'] !== 'Active' || $user['account_status'] !== 'Active') {
            throw new DomainException('Your active account is required for electronic signing.');
        }

        return $user;
    }
}

if (!function_exists('drms_esign_assert_profile_owner')) {
    function drms_esign_assert_profile_owner(array $user): void
    {
        if (!drms_esign_profile_role_allowed((string) ($user['role'] ?? ''))) {
            throw new DomainException('Only GM, Finance, President, or Supply Chain accounts assigned to controlled approval stages can maintain an electronic-signature profile.');
        }
        if ((int) ($user['require_pass_change'] ?? 0) === 1) {
            throw new DomainException('Change your temporary password before setting up an electronic signature.');
        }
    }
}

if (!function_exists('drms_esign_profile')) {
    function drms_esign_profile(mysqli $conn, int $userId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT signature_profile_id, user_id, display_name, signatory_title,
                       signature_image_path, signature_image_hash, profile_status,
                       created_at, updated_at
                FROM user_signature_profiles
                WHERE user_id = ?
                LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $conn->prepare($sql);
        $statement->bind_param('i', $userId);
        $statement->execute();
        $profile = $statement->get_result()->fetch_assoc();
        $statement->close();

        return $profile ?: null;
    }
}

if (!function_exists('drms_esign_profile_ready')) {
    function drms_esign_profile_ready(mysqli $conn, int $userId): array
    {
        $user = drms_esign_user($conn, $userId);
        drms_esign_assert_profile_owner($user);
        $profile = drms_esign_profile($conn, $userId);

        if (!$profile || $profile['profile_status'] !== 'Active') {
            throw new DomainException('Set up and activate your electronic-signature profile before signing this approval.');
        }

        return ['user' => $user, 'profile' => $profile];
    }
}

if (!function_exists('drms_esign_password_key')) {
    function drms_esign_password_key(int $userId): string
    {
        return 'e-signature-password:' . $userId;
    }
}

if (!function_exists('drms_esign_reauthenticate')) {
    /**
     * Checks a current password with a persistent 5-attempt/15-minute limit.
     * The plain-text password is never saved, logged, or returned.
     */
    function drms_esign_reauthenticate(mysqli $conn, int $userId, string $password): array
    {
        $key = drms_esign_password_key($userId);
        $started = false;
        try {
            $conn->begin_transaction();
            $started = true;
            $user = drms_esign_user($conn, $userId);
            drms_esign_assert_profile_owner($user);
            $attempts = $conn->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > NOW() - INTERVAL 15 MINUTE'
            );
            $attempts->bind_param('s', $key);
            $attempts->execute();
            $count = (int) ($attempts->get_result()->fetch_row()[0] ?? 0);
            $attempts->close();
            if ($count >= 5) {
                throw new DomainException('Too many password attempts. Try again in 15 minutes.');
            }

            if ($password === '' || !password_verify($password, (string) $user['password_hash'])) {
                $ipAddress = drms_signature_client_ip() ?? '';
                $failure = $conn->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)');
                $failure->bind_param('ss', $key, $ipAddress);
                $failure->execute();
                $failure->close();
                $conn->commit();
                $started = false;
                throw new DomainException('Your current password is incorrect. Nothing was signed or changed.');
            }

            $clear = $conn->prepare('DELETE FROM login_attempts WHERE username = ?');
            $clear->bind_param('s', $key);
            $clear->execute();
            $clear->close();
            $conn->commit();
            $started = false;
            unset($user['password_hash']);
            return $user;
        } catch (Throwable $error) {
            if ($started) {
                $conn->rollback();
            }
            throw $error;
        }
    }
}

if (!function_exists('drms_esign_save_profile')) {
    /**
     * Persists only a signatory's identity metadata. Optional signature-image
     * fields are supplied only after a successful, validated HTTP upload.
     */
    function drms_esign_save_profile(
        mysqli $conn,
        int $userId,
        string $displayName,
        string $signatoryTitle,
        ?string $signatureImagePath = null,
        ?string $signatureImageHash = null,
        bool $removeImage = false
    ): array {
        $user = drms_esign_user($conn, $userId);
        drms_esign_assert_profile_owner($user);
        $displayName = drms_esign_text($displayName, 150, 'Signature display name');
        $signatoryTitle = drms_esign_text($signatoryTitle, 100, 'Signatory title');
        if ($signatureImagePath !== null && !preg_match('#^uploads/signatures/[A-Za-z0-9._-]+$#', $signatureImagePath)) {
            throw new DomainException('The signature image location is invalid.');
        }
        if ($signatureImageHash !== null) {
            $signatureImageHash = drms_signature_validate_hash($signatureImageHash);
        }

        $existing = drms_esign_profile($conn, $userId, true);
        $path = $removeImage ? null : ($signatureImagePath ?? ($existing['signature_image_path'] ?? null));
        $hash = $removeImage ? null : ($signatureImageHash ?? ($existing['signature_image_hash'] ?? null));

        if ($existing) {
            $statement = $conn->prepare(
                "UPDATE user_signature_profiles
                 SET display_name = ?, signatory_title = ?, signature_image_path = ?, signature_image_hash = ?,
                     profile_status = 'Active', updated_at = NOW()
                 WHERE user_id = ?"
            );
            $statement->bind_param('ssssi', $displayName, $signatoryTitle, $path, $hash, $userId);
        } else {
            $statement = $conn->prepare(
                "INSERT INTO user_signature_profiles
                 (user_id, display_name, signatory_title, signature_image_path, signature_image_hash, profile_status)
                 VALUES (?, ?, ?, ?, ?, 'Active')"
            );
            $statement->bind_param('issss', $userId, $displayName, $signatoryTitle, $path, $hash);
        }
        $statement->execute();
        $statement->close();

        return drms_esign_profile($conn, $userId) ?? [];
    }
}

if (!function_exists('drms_esign_assert_payload')) {
    function drms_esign_assert_payload(array $input): void
    {
        if (($input['e_signature_confirmed'] ?? '') !== '1') {
            throw new DomainException('Confirm the electronic-signature statement before continuing.');
        }
    }
}

if (!function_exists('drms_esign_prepare')) {
    /**
     * Validates an approval against the active, session-enforced account and
     * the signatory's stored profile. Password re-entry is intentionally not
     * required for each decision: the session, role, CSRF token, explicit
     * consent, IP/user-agent audit data, and immutable event are the controls.
     * Call drms_esign_record_event only inside the successful transaction.
     */
    function drms_esign_prepare(
        mysqli $conn,
        int $userId,
        string $recordModule,
        string $signatureStage,
        array $input
    ): array {
        drms_esign_assert_payload($input);
        $user = drms_esign_user($conn, $userId);
        drms_esign_assert_profile_owner($user);
        if (!drms_signature_role_can_sign((string) $user['role'], $recordModule, $signatureStage)) {
            throw new DomainException('Your organizational role is not authorized to sign this approval stage.');
        }
        $profile = drms_esign_profile($conn, $userId);
        if (!$profile || $profile['profile_status'] !== 'Active') {
            throw new DomainException('Set up and activate your electronic-signature profile before signing this approval.');
        }

        return ['user' => $user, 'profile' => $profile];
    }
}

if (!function_exists('drms_esign_record_event')) {
    function drms_esign_record_event(mysqli $conn, array $signature, array $event): int
    {
        $user = $signature['user'] ?? [];
        $profile = $signature['profile'] ?? [];
        $module = drms_esign_text($event['record_module'] ?? '', 60, 'Record module');
        $stage = drms_esign_text($event['signature_stage'] ?? '', 100, 'Approval stage');
        $recordId = (int) ($event['record_id'] ?? 0);
        if ($recordId < 1) {
            throw new DomainException('The approval record is invalid.');
        }
        $hash = drms_signature_validate_hash((string) ($event['signed_file_hash'] ?? ''));
        $version = drms_esign_text((string) ($event['signed_version'] ?? '1.0'), 20, 'Document version');
        $consent = drms_esign_text((string) ($event['consent_text'] ?? ''), 2000, 'Electronic-signature statement');
        $documentId = isset($event['document_id']) ? (int) $event['document_id'] : null;
        $officialDocumentId = isset($event['official_document_id']) ? (int) $event['official_document_id'] : null;
        $requestId = isset($event['request_id']) ? (int) $event['request_id'] : null;
        $remarks = isset($event['remarks']) ? drms_signature_normalize_text((string) $event['remarks']) : null;
        if ($remarks !== null && mb_strlen($remarks) > 2000) {
            throw new DomainException('Signature remarks must fit within the displayed limit.');
        }
        $verificationCode = bin2hex(random_bytes(16));
        $userId = (int) ($user['user_id'] ?? 0);
        $name = drms_esign_text((string) ($profile['display_name'] ?? ''), 150, 'Signature display name');
        $role = drms_esign_text((string) ($user['role'] ?? ''), 50, 'Signatory role');
        $path = $profile['signature_image_path'] ?? null;
        $imageHash = $profile['signature_image_hash'] ?? null;
        $ipAddress = drms_signature_client_ip();
        $userAgent = drms_signature_user_agent();
        $method = 'Active session-confirmed electronic signature';
        $statement = $conn->prepare(
            "INSERT INTO document_signature_events
             (verification_code, request_id, record_module, record_id, document_id, official_document_id,
              signature_stage, signature_type, signer_user_id, signer_name, signer_role, signature_method,
              signed_file_hash, signed_version, consent_text, signature_image_path, signature_image_hash,
              ip_address, user_agent, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Electronic Approval', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->bind_param(
            'sisiiisisssssssssss',
            $verificationCode, $requestId, $module, $recordId, $documentId, $officialDocumentId,
            $stage, $userId, $name, $role, $method, $hash, $version, $consent, $path, $imageHash,
            $ipAddress, $userAgent, $remarks
        );
        $statement->execute();
        $signatureId = (int) $conn->insert_id;
        $statement->close();

        return $signatureId;
    }
}
