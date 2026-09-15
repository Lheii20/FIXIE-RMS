<?php
declare(strict_types=1);

/**
 * Shared authorization and feedback helpers for document metadata actions.
 * The caller must load db_connect.php and functions.php first.
 */

function drms_record_action_access(
    mysqli $conn,
    int $documentId,
    int $userId,
    string $role
): ?array {
    if ($documentId < 1 || $userId < 1 || $role === 'Admin') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT doc_id, official_doc_id, file_name, category, status,
                record_phase, disposition_status, uploaded_by, access_type,
                file_permissions, is_locked, locked_by, is_legal_hold,
                rename_history
         FROM documents
         WHERE doc_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$document) {
        return null;
    }

    if (
        in_array($role, ['GM', 'President'], true) ||
        has_permission($conn, $userId, 'can_view_all_folders')
    ) {
        $document['record_access_level'] = 'Management';
        return $document;
    }

    if ((int) ($document['uploaded_by'] ?? 0) === $userId) {
        $document['record_access_level'] = 'Owner';
        return $document;
    }

    $permissions = json_decode(
        (string) ($document['file_permissions'] ?? ''),
        true
    );
    $directAccess = is_array($permissions)
        ? (string) ($permissions['user_' . $userId] ?? '')
        : '';
    if (in_array($directAccess, ['Viewer', 'Editor'], true)) {
        $document['record_access_level'] = $directAccess;
        return $document;
    }

    if (($document['access_type'] ?? 'Folder Default') !== 'Folder Default') {
        return null;
    }

    $category = trim((string) ($document['category'] ?? ''));
    if ($category === '') {
        return null;
    }
    $folderStmt = $conn->prepare(
        "SELECT
            MAX(CASE WHEN cra.role_name = ? THEN 1 ELSE 0 END) AS explicit_access,
            GROUP_CONCAT(DISTINCT dc.assigned_to_role SEPARATOR ',') AS legacy_roles
         FROM document_categories dc
         LEFT JOIN category_role_access cra ON cra.category_id = dc.id
         WHERE dc.sub_category = ?"
    );
    $folderStmt->bind_param('ss', $role, $category);
    $folderStmt->execute();
    $folder = $folderStmt->get_result()->fetch_assoc();
    $folderStmt->close();

    $folderRoleAllowed = (int) ($folder['explicit_access'] ?? 0) === 1;
    if (!$folderRoleAllowed) {
        foreach (array_filter(array_map(
            'trim',
            explode(',', (string) ($folder['legacy_roles'] ?? ''))
        )) as $assignedRole) {
            if (strcasecmp($assignedRole, $role) === 0) {
                $folderRoleAllowed = true;
                break;
            }
        }
    }
    if (!$folderRoleAllowed) {
        return null;
    }

    $document['record_access_level'] = 'Folder Editor';
    return $document;
}

function drms_record_action_require_editor(
    mysqli $conn,
    int $documentId,
    int $userId,
    string $role
): array {
    $document = drms_record_action_access(
        $conn,
        $documentId,
        $userId,
        $role
    );
    if (
        !$document ||
        ($document['record_access_level'] ?? '') === 'Viewer'
    ) {
        throw new DomainException(
            'Record not found or Editor access is required for this action.'
        );
    }
    return $document;
}

function drms_record_action_require_owner(
    mysqli $conn,
    int $documentId,
    int $userId,
    string $role
): array {
    $document = drms_record_action_access(
        $conn,
        $documentId,
        $userId,
        $role
    );
    if (
        !$document ||
        !in_array(
            (string) ($document['record_access_level'] ?? ''),
            ['Owner', 'Management'],
            true
        )
    ) {
        throw new DomainException(
            'Only the record owner or Management can change sharing settings.'
        );
    }
    return $document;
}

function drms_record_action_safe_return(
    $candidate,
    string $fallback
): string {
    if (!is_string($candidate) || preg_match('/[\r\n]/', $candidate)) {
        return $fallback;
    }
    $parts = parse_url(trim($candidate));
    if ($parts === false) {
        return $fallback;
    }

    $path = (string) ($parts['path'] ?? '');
    $page = basename(str_replace('\\', '/', $path));
    if (!in_array($page, ['general_docs.php', 'documents.php'], true)) {
        return $fallback;
    }

    return $page . (
        isset($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : ''
    );
}

function drms_record_action_feedback(
    string $target,
    string $type,
    string $message
): void {
    $separator = strpos($target, '?') !== false ? '&' : '?';
    header(
        'Location: ' . $target . $separator . $type . '=' .
        rawurlencode($message)
    );
    exit();
}

function drms_record_action_history($encoded): array
{
    $history = json_decode((string) ($encoded ?? ''), true);
    return is_array($history) ? $history : [];
}

function drms_record_action_actor_name(mysqli $conn, int $userId): string
{
    $stmt = $conn->prepare(
        "SELECT full_name
         FROM users
         WHERE user_id = ?
           AND status = 'Active'
         LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string) ($row['full_name'] ?? '')) ?: 'System';
}
