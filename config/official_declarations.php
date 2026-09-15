<?php
/** Signed-copy declaration route. Callers retain transaction ownership. */
require_once __DIR__ . '/signature_workflow.php';
require_once __DIR__ . '/e_signature.php';
require_once __DIR__ . '/storage_security.php';
require_once __DIR__ . '/client_po_acknowledgement.php';

function drms_declaration_user(mysqli $conn, int $id): array
{
    $s = $conn->prepare("SELECT user_id, full_name, role, status, account_status FROM users WHERE user_id = ?");
    $s->bind_param('i', $id);
    $s->execute();
    $u = $s->get_result()->fetch_assoc();
    if (!$u || $u['status'] !== 'Active' || $u['account_status'] !== 'Active' ||
        !drms_signature_role_can_request_declaration($u['role'])) {
        throw new DomainException('Your active business account is required for this action.');
    }
    return $u;
}

function drms_declaration_can_submit(array $doc, array $user): bool
{
    // Ownership or explicit Editor permission is required; viewing a shared
    // document does not authorize submitting it on its author's behalf.
    $permissions = json_decode((string) ($doc['file_permissions'] ?? ''), true);
    return in_array($user['role'], ['GM', 'President'], true) ||
        (int) $doc['uploaded_by'] === (int) $user['user_id'] ||
        (is_array($permissions) && ($permissions['user_' . $user['user_id']] ?? '') === 'Editor');
}

function drms_declaration_working(array $doc, array $folder): void
{
    if ($doc['status'] !== 'Active' || !in_array($doc['record_phase'], ['Working', 'For Review'], true) ||
        !empty($doc['official_doc_id']) || !empty($doc['is_locked'])) {
        throw new DomainException('Choose an active, unlocked Company File that has not been finalized.');
    }
    if ((int) $folder['is_system_folder'] === 1) {
        throw new DomainException('This record is filed through its existing approval workflow.');
    }
}

function drms_declaration_fingerprint(array $doc): string
{
    $path = drms_storage_resolve_existing_file((string) $doc['file_path']);
    $hash = hash_file('sha256', $path);
    if ($hash === false) throw new DomainException('The document file cannot be verified.');
    return drms_signature_validate_hash($hash);
}

function drms_declaration_text($value, int $max, bool $required = false): string
{
    if (!is_string($value)) throw new DomainException('Enter valid text in the form.');
    $text = trim($value);
    if (($required && $text === '') || mb_strlen($text) > $max) {
        throw new DomainException('Complete the required fields and keep text within the displayed limits.');
    }
    return $text;
}

function drms_declaration_notify(mysqli $conn, array $request, string $role, ?int $userId, string $message, string $suffix): void
{
    // A requester no longer opens the management-only queue from a
    // notification. Their notification sends them back to Company Files;
    // the GM continues to receive the direct queue link.
    $link = $userId === null
        ? 'official_declarations.php?request_id=' . (int) $request['request_id']
        : 'general_docs.php';
    phase6b2_create_notification($conn, $role, $message,
        $link,
        'declaration:' . (int) $request['request_id'] . ':' . $suffix, $userId);
}

function drms_declaration_submit(mysqli $conn, array $doc, array $user, array $input): int
{
    if (!drms_declaration_can_submit($doc, $user)) {
        throw new DomainException('Only the owner, an assigned editor, or management can submit this file.');
    }
    $folder = drms_get_official_folder_profile($conn, (string) ($doc['category'] ?: $doc['doc_type']));
    drms_declaration_working($doc, $folder);
    // Official-record declaration is a single-accountability control: every
    // request is routed to the General Manager. This avoids parallel queues
    // where the same document may be approved by different management roles.
    $role = drms_declaration_text($input['signer_role'] ?? '', 30, true);
    if ($role !== 'GM') throw new DomainException('Official Record declaration requests must be sent to the General Manager.');
    $s = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND status = 'Active' AND account_status = 'Active' LIMIT 1");
    $s->bind_param('s', $role); $s->execute();
    if (!$s->get_result()->fetch_row()) throw new DomainException('No active reviewer is available for that role.');
    if (($input['signed_copy_confirmed'] ?? '') !== '1') throw new DomainException('Confirm that the uploaded document already contains its required signatures.');
    $name = drms_declaration_text($input['external_name'] ?? '', 150, true);
    $title = drms_declaration_text($input['external_role'] ?? '', 100);
    $date = drms_declaration_text($input['external_date'] ?? '', 10);
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            throw new DomainException('Enter a valid signature date that is not in the future.');
        }
    }
    $date = $date ?: null;
    $remarks = drms_declaration_text($input['remarks'] ?? '', 2000);
    $id = (int) $doc['doc_id'];
    $s = $conn->prepare("SELECT request_id FROM official_declaration_requests WHERE document_id = ? AND request_status = 'Pending' FOR UPDATE");
    $s->bind_param('i', $id); $s->execute();
    if ($s->get_result()->fetch_row()) throw new DomainException('A declaration request is already pending for this document. The General Manager will review it.');
    $hash = drms_declaration_fingerprint($doc);
    $version = drms_declaration_text((string) $doc['current_version'], 20, true);
    $ref = 'DEC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
    $uid = (int) $user['user_id'];
    $s = $conn->prepare("INSERT INTO official_declaration_requests
        (request_reference, document_id, source_file_hash, source_version, declaration_basis,
        requested_signer_role, external_signatory_name, external_signatory_role, external_signature_date,
        request_remarks, requested_by) VALUES (?, ?, ?, ?, 'External Signed Copy', ?, ?, ?, ?, ?, ?)");
    $s->bind_param('sisssssssi', $ref, $id, $hash, $version, $role, $name, $title, $date, $remarks, $uid);
    $s->execute(); $requestId = (int) $conn->insert_id;
    drms_declaration_notify($conn, ['request_id' => $requestId], $role, null,
        $user['full_name'] . ' submitted ' . $ref . ' for signed-copy verification.', 'submitted');
    log_audit_action($conn, $uid, 'REQUEST_OFFICIAL_DECLARATION', 'Submitted ' . $ref . ' for document ' . $id);
    return $requestId;
}

function drms_declaration_locked_request(mysqli $conn, int $requestId, int $docId): array
{
    $s = $conn->prepare('SELECT * FROM official_declaration_requests WHERE request_id = ? AND document_id = ? FOR UPDATE');
    $s->bind_param('ii', $requestId, $docId); $s->execute();
    $request = $s->get_result()->fetch_assoc();
    if (!$request || $request['request_status'] !== 'Pending') throw new DomainException('This request is unavailable or has already been processed.');
    return $request;
}

function drms_declaration_assert_reviewer(array $request, array $user): void
{
    // The GM is the exclusive declaration reviewer. The request role is not
    // trusted here so legacy requests previously routed to another role can
    // still be safely completed by the GM instead of becoming stranded.
    if ($user['role'] !== 'GM') {
        throw new DomainException('Only the General Manager can review and approve Official Record declaration requests.');
    }
}

function drms_declaration_assert_unchanged(array $doc, array $request): string
{
    $hash = drms_declaration_fingerprint($doc);
    if (!hash_equals($request['source_file_hash'], $hash) || (string) $doc['current_version'] !== $request['source_version']) {
        throw new DomainException('The document changed after submission. Return or cancel this request, then submit the latest signed copy.');
    }
    return $hash;
}

function drms_declaration_close(mysqli $conn, array $request, array $user, string $decision, string $reason): void
{
    if ($decision === 'Cancelled') {
        if ((int) $request['requested_by'] !== (int) $user['user_id']) throw new DomainException('Only the requester can cancel this request.');
    } elseif ($decision === 'Returned') {
        drms_declaration_assert_reviewer($request, $user);
        if ($reason === '') throw new DomainException('Enter the reason for returning this document.');
    } else throw new DomainException('Invalid request decision.');
    $id = (int) $request['request_id']; $uid = (int) $user['user_id'];
    $s = $conn->prepare("UPDATE official_declaration_requests SET request_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?, closed_at = NOW() WHERE request_id = ? AND request_status = 'Pending'");
    $s->bind_param('sisi', $decision, $uid, $reason, $id); $s->execute();
    if ($s->affected_rows !== 1) throw new DomainException('The request changed. Refresh and try again.');
    $requester = drms_declaration_requester($conn, $request);
    drms_declaration_notify($conn, $request, $requester['role'], (int) $requester['user_id'],
        $request['request_reference'] . ' was ' . strtolower($decision) . '.', strtolower($decision));
    log_audit_action($conn, $uid, 'CLOSE_OFFICIAL_DECLARATION', $decision . ' ' . $request['request_reference'] . ': ' . $reason);
}

function drms_declaration_requester(mysqli $conn, array $request): array
{
    $s = $conn->prepare('SELECT user_id, role FROM users WHERE user_id = ?');
    $s->bind_param('i', $request['requested_by']); $s->execute();
    return $s->get_result()->fetch_assoc() ?: ['user_id' => $request['requested_by'], 'role' => ''];
}

function drms_declaration_reauthenticate(mysqli $conn, int $uid, string $password): array
{
    // Persistent account-scoped limit, serialized across sessions. Failed
    // attempts are committed separately from the document transaction.
    $key = 'declaration-password:' . $uid;
    $conn->begin_transaction();
    try {
        $s = $conn->prepare("SELECT user_id, role, full_name, password_hash, status, account_status, require_pass_change FROM users WHERE user_id = ? FOR UPDATE");
        $s->bind_param('i', $uid); $s->execute(); $user = $s->get_result()->fetch_assoc();
        $s = $conn->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > NOW() - INTERVAL 15 MINUTE');
        $s->bind_param('s', $key); $s->execute();
        $attempts = (int) $s->get_result()->fetch_row()[0];
        if ($attempts >= 5) throw new DomainException('Too many password attempts. Try again in 15 minutes.');
        $valid = $user && $user['status'] === 'Active' && $user['account_status'] === 'Active' &&
            !(int) $user['require_pass_change'] && in_array($user['role'], ['GM', 'President'], true) &&
            $password !== '' && password_verify($password, $user['password_hash']);
        if (!$valid) {
            $ip = drms_signature_client_ip() ?? '';
            $s = $conn->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)');
            $s->bind_param('ss', $key, $ip); $s->execute(); $conn->commit();
            throw new DomainException('Your current password and active management account are required. Nothing was filed.');
        }
        $s = $conn->prepare('DELETE FROM login_attempts WHERE username = ?');
        $s->bind_param('s', $key); $s->execute(); $conn->commit();
        unset($user['password_hash']); return $user;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function drms_declaration_finish(mysqli $conn, array $request, array $signature, int $officialId, string $hash, string $remarks): void
{
    $rid = (int) $request['request_id'];
    $docId = (int) $request['document_id'];
    $uid = (int) ($signature['user']['user_id'] ?? 0);
    if ($uid < 1) {
        throw new DomainException('The electronic-signature account is invalid.');
    }
    $version = (string) $request['source_version'];
    $consent = 'I reviewed this exact document version and verified that it contains the required signatures. I authorize filing it as an Official Record.';
    drms_esign_record_event(
        $conn,
        $signature,
        [
            'request_id' => $rid,
            'record_module' => 'General Document',
            'record_id' => $docId,
            'document_id' => $docId,
            'official_document_id' => $officialId,
            'signature_stage' => 'Official Declaration',
            'signed_file_hash' => $hash,
            'signed_version' => $version,
            'consent_text' => $consent,
            'remarks' => $remarks,
        ]
    );
    $s = $conn->prepare("UPDATE official_declaration_requests SET request_status = 'Approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?, official_document_id = ?, closed_at = NOW() WHERE request_id = ? AND request_status = 'Pending'");
    $s->bind_param('isii', $uid, $remarks, $officialId, $rid); $s->execute();
    if ($s->affected_rows !== 1) throw new DomainException('This request has already been processed.');
    $recipient = drms_declaration_requester($conn, $request);
    drms_declaration_notify($conn, $request, $recipient['role'], (int) $recipient['user_id'],
        $request['request_reference'] . ' was verified and filed as an Official Record.', 'approved');
}
