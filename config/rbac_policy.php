<?php
declare(strict_types=1);

/**
 * Central RBAC policy for Fixie DRMS.
 *
 * Workflow pages remain role-driven because their approval order is part of
 * the business process. Record-management capabilities are user-specific but
 * may only be assigned when they are appropriate for the user's role.
 */

if (!function_exists('drms_rbac_roles')) {
    function drms_rbac_roles(): array
    {
        return [
            'Admin',
            'President',
            'GM',
            'Finance',
            'Procurement',
            'Supply Chain',
            'Sales Staff',
        ];
    }
}

if (!function_exists('drms_rbac_module_policy')) {
    function drms_rbac_module_policy(): array
    {
        return [
            'quotations' => ['Sales Staff', 'GM'],
            'purchase_requests' => ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'],
            'purchase_orders' => ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'],
            'collections' => ['Finance'],
            'records' => drms_rbac_roles(),
            'user_management' => ['Admin'],
        ];
    }
}

if (!function_exists('drms_rbac_module_roles')) {
    function drms_rbac_module_roles(string $module): array
    {
        return drms_rbac_module_policy()[$module] ?? [];
    }
}

if (!function_exists('drms_rbac_role_can_access_module')) {
    function drms_rbac_role_can_access_module(string $role, string $module): bool
    {
        return in_array($role, drms_rbac_module_roles($module), true);
    }
}

if (!function_exists('drms_rbac_require_module')) {
    function drms_rbac_require_module(
        string $module,
        string $deniedPage = 'dashboard.php',
        string $loginPage = 'index.php'
    ): void {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $loginPage);
            exit();
        }

        $role = (string) ($_SESSION['role'] ?? '');
        if (!drms_rbac_role_can_access_module($role, $module)) {
            header('Location: ' . $deniedPage);
            exit();
        }
    }
}

if (!function_exists('drms_rbac_capability_catalog')) {
    function drms_rbac_capability_catalog(): array
    {
        return [
            'can_upload_documents' => [
                'permission_name' => 'can_upload_documents',
                'label' => 'Upload company files',
                'description' => 'Create working-file records and upload their digital documents.',
                'group' => 'Record handling',
                'risk' => 'standard',
                'allowed_roles' => ['Sales Staff', 'Procurement', 'Supply Chain', 'Finance', 'GM'],
                'default_roles' => ['Sales Staff', 'Procurement', 'Supply Chain', 'Finance', 'GM'],
            ],
            'can_archive_documents' => [
                'permission_name' => 'can_archive_documents',
                'label' => 'Archive records',
                'description' => 'Move eligible active records into the archive while preserving their history.',
                'group' => 'Record handling',
                'risk' => 'elevated',
                'allowed_roles' => ['GM'],
                'default_roles' => ['GM'],
            ],
            'can_delete_documents' => [
                'permission_name' => 'can_delete_documents',
                'label' => 'Delete working records',
                'description' => 'Permanently remove eligible non-official working records. Official disposal remains a separate controlled process.',
                'group' => 'Record handling',
                'risk' => 'sensitive',
                'allowed_roles' => ['GM'],
                'default_roles' => [],
            ],
            'can_manage_folders' => [
                'permission_name' => 'can_manage_folders',
                'label' => 'Manage record locations',
                'description' => 'Create and maintain digital folders and physical storage locations.',
                'group' => 'Records administration',
                'risk' => 'elevated',
                'allowed_roles' => ['GM'],
                'default_roles' => ['GM'],
            ],
            'can_edit_policies' => [
                'permission_name' => 'can_edit_policies',
                'label' => 'Edit retention policies',
                'description' => 'Change retention periods and disposition rules used by record classifications.',
                'group' => 'Records administration',
                'risk' => 'sensitive',
                'allowed_roles' => ['GM'],
                'default_roles' => ['GM'],
            ],
            'can_view_all_folders' => [
                'permission_name' => 'can_view_all_folders',
                'label' => 'View all record folders',
                'description' => 'View records across departments, subject to document-level safeguards.',
                'group' => 'Oversight',
                'risk' => 'elevated',
                'allowed_roles' => ['GM', 'President'],
                'default_roles' => ['GM', 'President'],
            ],
            'can_view_disposition' => [
                'permission_name' => 'can_view_disposition',
                'label' => 'View disposition queue',
                'description' => 'Review records that reached retention or are involved in a disposition request.',
                'group' => 'Disposition control',
                'risk' => 'elevated',
                'allowed_roles' => ['GM', 'President'],
                'default_roles' => ['GM', 'President'],
            ],
            'can_manage_disposition' => [
                'permission_name' => 'can_manage_disposition',
                'label' => 'Prepare disposition decisions',
                'description' => 'Submit eligible records for destruction or permanent archival processing.',
                'group' => 'Disposition control',
                'risk' => 'sensitive',
                'allowed_roles' => ['GM'],
                'default_roles' => ['GM'],
            ],
            'can_approve_disposition' => [
                'permission_name' => 'can_approve_disposition',
                'label' => 'Approve disposition decisions',
                'description' => 'Approve or reject a disposition decision independently from its preparer.',
                'group' => 'Disposition control',
                'risk' => 'sensitive',
                'allowed_roles' => ['President'],
                'default_roles' => ['President'],
            ],
            'can_execute_disposition' => [
                'permission_name' => 'can_execute_disposition',
                'label' => 'Execute approved disposition',
                'description' => 'Complete a separately approved destruction or permanent archive action.',
                'group' => 'Disposition control',
                'risk' => 'sensitive',
                'allowed_roles' => ['GM'],
                'default_roles' => ['GM'],
            ],
            'can_view_audit_logs' => [
                'permission_name' => 'can_view_audit_logs',
                'label' => 'View audit trail',
                'description' => 'Read system and document activity logs for management oversight.',
                'group' => 'Oversight',
                'risk' => 'elevated',
                'allowed_roles' => ['GM', 'President'],
                'default_roles' => ['GM', 'President'],
            ],
        ];
    }
}

if (!function_exists('drms_rbac_intrinsic_capabilities')) {
    function drms_rbac_intrinsic_capabilities(string $role): array
    {
        return $role === 'Admin' ? ['can_manage_users'] : [];
    }
}

if (!function_exists('drms_rbac_capabilities_for_role')) {
    function drms_rbac_capabilities_for_role(string $role): array
    {
        $allowed = [];
        foreach (drms_rbac_capability_catalog() as $name => $definition) {
            if (in_array($role, $definition['allowed_roles'], true)) {
                $allowed[] = $name;
            }
        }
        return $allowed;
    }
}

if (!function_exists('drms_rbac_default_capabilities_for_role')) {
    function drms_rbac_default_capabilities_for_role(string $role): array
    {
        $defaults = [];
        foreach (drms_rbac_capability_catalog() as $name => $definition) {
            if (in_array($role, $definition['default_roles'], true)) {
                $defaults[] = $name;
            }
        }
        return $defaults;
    }
}

if (!function_exists('drms_rbac_role_allows_capability')) {
    function drms_rbac_role_allows_capability(string $role, string $capability): bool
    {
        return in_array($capability, drms_rbac_intrinsic_capabilities($role), true)
            || in_array($capability, drms_rbac_capabilities_for_role($role), true);
    }
}

if (!function_exists('drms_rbac_effective_capabilities')) {
    function drms_rbac_effective_capabilities(string $role, array $assigned): array
    {
        $effective = drms_rbac_intrinsic_capabilities($role);
        foreach ($assigned as $capability) {
            $capability = trim((string) $capability);
            if ($capability !== '' && drms_rbac_role_allows_capability($role, $capability)) {
                $effective[] = $capability;
            }
        }
        return array_values(array_unique($effective));
    }
}

if (!function_exists('drms_rbac_replace_user_capabilities')) {
    function drms_rbac_replace_user_capabilities(
        mysqli $conn,
        int $userId,
        string $role,
        ?array $requested = null
    ): array {
        if ($userId < 1 || !in_array($role, drms_rbac_roles(), true)) {
            throw new InvalidArgumentException('Invalid RBAC user or role.');
        }

        $capabilities = $requested === null
            ? drms_rbac_default_capabilities_for_role($role)
            : array_values(array_unique(array_map('strval', $requested)));
        $allowed = drms_rbac_capabilities_for_role($role);
        if (array_diff($capabilities, $allowed)) {
            throw new InvalidArgumentException('A capability is not allowed for the selected role.');
        }

        $delete = $conn->prepare('DELETE FROM user_permissions WHERE user_id = ?');
        $delete->bind_param('i', $userId);
        $delete->execute();
        $delete->close();

        if ($capabilities !== []) {
            $insert = $conn->prepare(
                'INSERT INTO user_permissions (user_id, permission_name) VALUES (?, ?)'
            );
            foreach ($capabilities as $capability) {
                $insert->bind_param('is', $userId, $capability);
                $insert->execute();
            }
            $insert->close();
        }

        return $capabilities;
    }
}
