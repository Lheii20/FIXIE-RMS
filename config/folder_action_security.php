<?php

if (!function_exists('drms_folder_action_require_manager')) {
    function drms_folder_action_require_manager(
        mysqli $conn,
        int $userId,
        string $role
    ): void {
        if (
            $userId < 1 ||
            $role === 'Admin' ||
            !has_permission($conn, $userId, 'can_manage_folders')
        ) {
            throw new DomainException(
                'You do not have permission to manage record folders.'
            );
        }
    }
}

if (!function_exists('drms_folder_action_is_management')) {
    function drms_folder_action_is_management(
        mysqli $conn,
        int $userId,
        string $role
    ): bool {
        return
            in_array($role, ['GM', 'President'], true) ||
            has_permission($conn, $userId, 'can_view_all_folders');
    }
}

if (!function_exists('drms_folder_action_normalize_name')) {
    function drms_folder_action_normalize_name(
        mixed $value,
        string $label
    ): string {
        if (!is_string($value)) {
            throw new DomainException($label . ' is required.');
        }

        $name = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($name) || $name === '') {
            throw new DomainException($label . ' is required.');
        }
        if (mb_strlen($name) > 100) {
            throw new DomainException($label . ' cannot exceed 100 characters.');
        }
        if (preg_match('~[\\\\/\x00-\x1F\x7F]~u', $name)) {
            throw new DomainException(
                $label . ' cannot contain slashes or control characters.'
            );
        }

        return $name;
    }
}

if (!function_exists('drms_folder_action_normalize_prefix')) {
    function drms_folder_action_normalize_prefix(mixed $value): string
    {
        $prefix = is_string($value) ? strtoupper(trim($value)) : '';
        if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $prefix)) {
            throw new DomainException(
                'Record Code must contain 2-10 uppercase letters or numbers and begin with a letter.'
            );
        }
        return $prefix;
    }
}

if (!function_exists('drms_folder_action_normalize_keywords')) {
    function drms_folder_action_normalize_keywords(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if (mb_strlen($value) > 1500) {
            throw new DomainException('Keywords cannot exceed 1,500 characters.');
        }

        $normalized = [];
        $seen = [];
        foreach (explode(',', $value) as $rawKeyword) {
            $keyword = preg_replace('/\s+/u', ' ', trim($rawKeyword));
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }
            if (mb_strlen($keyword) > 60) {
                throw new DomainException(
                    'Each classification keyword cannot exceed 60 characters.'
                );
            }
            if (preg_match('/[\x00-\x1F\x7F<>]/u', $keyword)) {
                throw new DomainException(
                    'Classification keywords contain unsupported characters.'
                );
            }
            $key = mb_strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $keyword;
        }

        if (count($normalized) > 30) {
            throw new DomainException(
                'A folder can contain a maximum of 30 classification keywords.'
            );
        }
        return implode(', ', $normalized);
    }
}

if (!function_exists('drms_folder_action_allowed_roles')) {
    function drms_folder_action_allowed_roles(): array
    {
        return [
            'Procurement',
            'GM',
            'Finance',
            'President',
            'Supply Chain',
            'Sales Staff',
        ];
    }
}

if (!function_exists('drms_folder_action_normalize_roles')) {
    function drms_folder_action_normalize_roles(mixed $roles): array
    {
        if (!is_array($roles)) {
            throw new DomainException(
                'Select at least one role that should access this folder.'
            );
        }

        $allowed = drms_folder_action_allowed_roles();
        $clean = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new DomainException('An invalid folder role was submitted.');
            }
            $role = trim($role);
            if (!in_array($role, $allowed, true)) {
                throw new DomainException('An invalid folder role was submitted.');
            }
            $clean[$role] = $role;
        }
        if (!$clean) {
            throw new DomainException(
                'Select at least one role that should access this folder.'
            );
        }
        return array_values($clean);
    }
}

if (!function_exists('drms_folder_action_find')) {
    function drms_folder_action_find(
        mysqli $conn,
        string $parent,
        ?string $sub = null
    ): ?array {
        if ($sub === null) {
            $stmt = $conn->prepare(
                'SELECT id, parent_category, sub_category, policy_id, '
                . 'classification_keywords, record_prefix, is_system_folder '
                . 'FROM document_categories '
                . 'WHERE parent_category = ? ORDER BY id ASC LIMIT 1'
            );
            $stmt->bind_param('s', $parent);
        } else {
            $stmt = $conn->prepare(
                'SELECT id, parent_category, sub_category, policy_id, '
                . 'classification_keywords, record_prefix, is_system_folder '
                . 'FROM document_categories '
                . 'WHERE parent_category = ? AND sub_category = ? LIMIT 1'
            );
            $stmt->bind_param('ss', $parent, $sub);
        }
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $folder;
    }
}

if (!function_exists('drms_folder_action_find_by_sub')) {
    function drms_folder_action_find_by_sub(
        mysqli $conn,
        string $sub
    ): ?array {
        $stmt = $conn->prepare(
            'SELECT id, parent_category, sub_category, policy_id, '
            . 'classification_keywords, record_prefix, is_system_folder '
            . 'FROM document_categories WHERE sub_category = ? LIMIT 2'
        );
        $stmt->bind_param('s', $sub);
        $stmt->execute();
        $result = $stmt->get_result();
        $folder = $result->fetch_assoc() ?: null;
        $ambiguous = $result->fetch_assoc();
        $stmt->close();
        if ($ambiguous) {
            throw new DomainException(
                'This folder name is ambiguous. Assign unique sub-folder names before continuing.'
            );
        }
        return $folder;
    }
}

if (!function_exists('drms_folder_action_require_access')) {
    function drms_folder_action_require_access(
        mysqli $conn,
        int $userId,
        string $role,
        string $parent,
        ?string $sub = null
    ): array {
        $folder = drms_folder_action_find($conn, $parent, $sub);
        if (!$folder) {
            throw new DomainException('The selected record folder no longer exists.');
        }
        if (drms_folder_action_is_management($conn, $userId, $role)) {
            return $folder;
        }

        $sql =
            'SELECT dc.id FROM document_categories dc '
            . 'LEFT JOIN category_role_access cra '
            . 'ON cra.category_id = dc.id AND cra.role_name = ? '
            . 'WHERE dc.parent_category = ? ';
        if ($sub !== null) {
            $sql .= 'AND dc.sub_category = ? ';
        }
        $sql .=
            "AND (cra.category_id IS NOT NULL OR FIND_IN_SET(?, "
            . "REPLACE(COALESCE(dc.assigned_to_role, ''), ', ', ',')) > 0) "
            . 'LIMIT 1';
        $stmt = $conn->prepare($sql);
        if ($sub === null) {
            $stmt->bind_param('sss', $role, $parent, $role);
        } else {
            $stmt->bind_param('ssss', $role, $parent, $sub, $role);
        }
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$allowed) {
            throw new DomainException(
                'You cannot manage a folder outside your assigned record folders.'
            );
        }
        return $folder;
    }
}

if (!function_exists('drms_folder_action_require_mutable')) {
    function drms_folder_action_require_mutable(array $folder): void
    {
        if ((int) ($folder['is_system_folder'] ?? 0) === 1) {
            throw new DomainException(
                'Protected workflow folders cannot be changed or deleted manually.'
            );
        }
    }
}

if (!function_exists('drms_folder_action_require_policy')) {
    function drms_folder_action_require_policy(
        mysqli $conn,
        int $policyId
    ): void {
        if ($policyId < 1) {
            throw new DomainException('Select a valid retention policy.');
        }
        $stmt = $conn->prepare(
            'SELECT policy_id FROM retention_policies WHERE policy_id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $policyId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            throw new DomainException(
                'The selected retention policy no longer exists.'
            );
        }
    }
}

if (!function_exists('drms_folder_action_keyword_conflicts')) {
    function drms_folder_action_keyword_conflicts(
        mysqli $conn,
        string $category,
        string $keywords
    ): array {
        $input = [];
        foreach (explode(',', $keywords) as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '') {
                $input[mb_strtolower($keyword)] = $keyword;
            }
        }
        if (!$input) {
            return [];
        }

        $stmt = $conn->prepare(
            "SELECT sub_category, classification_keywords "
            . "FROM document_categories WHERE sub_category <> ? "
            . "AND classification_keywords IS NOT NULL "
            . "AND classification_keywords <> ''"
        );
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicts = [];
        while ($row = $result->fetch_assoc()) {
            $used = [];
            foreach (explode(',', (string) $row['classification_keywords']) as $keyword) {
                $keyword = trim($keyword);
                if ($keyword !== '') {
                    $used[mb_strtolower($keyword)] = true;
                }
            }
            foreach ($input as $normalized => $display) {
                if (isset($used[$normalized])) {
                    $conflicts[] = [
                        'keyword' => $display,
                        'folder' => (string) $row['sub_category'],
                    ];
                }
            }
        }
        $stmt->close();
        return $conflicts;
    }
}

if (!function_exists('drms_folder_action_require_policy')) {
    function drms_folder_action_require_policy(
        mysqli $conn,
        int $policyId
    ): void {
        if ($policyId < 1) {
            throw new DomainException('Select a valid retention policy.');
        }
        $stmt = $conn->prepare(
            'SELECT policy_id FROM retention_policies WHERE policy_id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $policyId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            throw new DomainException(
                'The selected retention policy no longer exists.'
            );
        }
    }
}

if (!function_exists('drms_folder_action_keyword_conflicts')) {
    function drms_folder_action_keyword_conflicts(
        mysqli $conn,
        string $currentFolder,
        string $keywords
    ): array {
        if ($keywords === '') {
            return [];
        }
        $input = [];
        foreach (explode(',', $keywords) as $keyword) {
            $key = mb_strtolower(trim($keyword));
            if ($key !== '') {
                $input[$key] = true;
            }
        }

        $stmt = $conn->prepare(
            'SELECT sub_category, classification_keywords '
            . 'FROM document_categories '
            . 'WHERE sub_category <> ? '
            . "AND classification_keywords IS NOT NULL "
            . "AND classification_keywords <> ''"
        );
        $stmt->bind_param('s', $currentFolder);
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicts = [];
        while ($row = $result->fetch_assoc()) {
            foreach (explode(',', (string) $row['classification_keywords']) as $raw) {
                $existing = mb_strtolower(trim($raw));
                if ($existing !== '' && isset($input[$existing])) {
                    $conflicts[$existing] = [
                        'keyword' => trim($raw),
                        'folder' => (string) $row['sub_category'],
                    ];
                }
            }
        }
        $stmt->close();
        return array_values($conflicts);
    }
}

if (!function_exists('drms_folder_action_json')) {
    function drms_folder_action_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode($payload, JSON_THROW_ON_ERROR);
        exit();
    }
}
