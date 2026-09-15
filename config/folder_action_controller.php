<?php

require_once __DIR__ . '/folder_action_security.php';

if (!function_exists('drms_folder_action_redirect')) {
    function drms_folder_action_redirect(
        string $page,
        string $type,
        string $message,
        string $parent = ''
    ): never {
        if (!in_array($page, ['general_docs.php', 'documents.php'], true)) {
            $page = 'general_docs.php';
        }
        $params = [];
        foreach (['parent', 'type', 'view_filter', 'search', 'page'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $params[$key] = mb_substr($_GET[$key], 0, 255);
            }
        }
        if ($parent !== '') {
            $params['parent'] = $parent;
            unset($params['type']);
        }
        $params[$type === 'success' ? 'success' : 'error'] = $message;
        header('Location: ' . $page . '?' . http_build_query($params));
        exit();
    }
}

if (!function_exists('drms_folder_action_assign_roles')) {
    function drms_folder_action_assign_roles(
        mysqli $conn,
        int $categoryId,
        array $roles
    ): void {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO category_role_access (category_id, role_name) '
            . 'VALUES (?, ?)'
        );
        foreach ($roles as $assignedRole) {
            $stmt->bind_param('is', $categoryId, $assignedRole);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Folder role assignment failed.');
            }
        }
        $stmt->close();
    }
}

if (!function_exists('drms_folder_action_parent_is_protected')) {
    function drms_folder_action_parent_is_protected(
        mysqli $conn,
        string $parent
    ): bool {
        $stmt = $conn->prepare(
            'SELECT id FROM document_categories '
            . 'WHERE parent_category = ? AND is_system_folder = 1 LIMIT 1'
        );
        $stmt->bind_param('s', $parent);
        $stmt->execute();
        $protected = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $protected;
    }
}

if (!function_exists('drms_folder_action_handle')) {
    function drms_folder_action_handle(
        mysqli $conn,
        string $page,
        bool $allowCreate,
        int $userId,
        string $role
    ): void {
        $action = is_string($_POST['action'] ?? null)
            ? $_POST['action']
            : '';
        $handled = [
            'get_keywords',
            'update_keywords',
            'create_folder',
            'edit_folder_policy',
            'delete_folder',
        ];
        if (!in_array($action, $handled, true)) {
            return;
        }

        try {
            drms_folder_action_require_manager($conn, $userId, $role);

            if ($action === 'get_keywords') {
                $category = drms_folder_action_normalize_name(
                    $_POST['category'] ?? null,
                    'Folder name'
                );
                $folder = drms_folder_action_find_by_sub($conn, $category);
                if (!$folder) {
                    throw new DomainException(
                        'The selected record folder no longer exists.'
                    );
                }
                drms_folder_action_require_access(
                    $conn,
                    $userId,
                    $role,
                    (string) $folder['parent_category'],
                    (string) $folder['sub_category']
                );
                drms_folder_action_json([
                    'status' => 'success',
                    'keywords' => (string) ($folder['classification_keywords'] ?? ''),
                ]);
            }

            if ($action === 'update_keywords') {
                $category = drms_folder_action_normalize_name(
                    $_POST['category_name'] ?? null,
                    'Folder name'
                );
                $keywords = drms_folder_action_normalize_keywords(
                    $_POST['classification_keywords'] ?? ''
                );
                $folder = drms_folder_action_find_by_sub($conn, $category);
                if (!$folder) {
                    throw new DomainException(
                        'The selected record folder no longer exists.'
                    );
                }
                drms_folder_action_require_access(
                    $conn,
                    $userId,
                    $role,
                    (string) $folder['parent_category'],
                    (string) $folder['sub_category']
                );
                drms_folder_action_require_mutable($folder);
                $conflicts = drms_folder_action_keyword_conflicts(
                    $conn,
                    $category,
                    $keywords
                );
                if ($conflicts) {
                    throw new DomainException(
                        "Keyword '" . $conflicts[0]['keyword']
                        . "' is already assigned to folder '"
                        . $conflicts[0]['folder'] . "'."
                    );
                }

                $stmt = $conn->prepare(
                    'UPDATE document_categories '
                    . 'SET classification_keywords = ? '
                    . 'WHERE id = ? AND is_system_folder = 0'
                );
                $folderId = (int) $folder['id'];
                $stmt->bind_param('si', $keywords, $folderId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Folder keywords could not be updated.');
                }
                $stmt->close();
                if (function_exists('log_audit_action')) {
                    log_audit_action(
                        $conn,
                        $userId,
                        'UPDATE_FOLDER_KEYWORDS',
                        'Updated classification keywords for folder: ' . $category
                    );
                }
                drms_folder_action_redirect(
                    $page,
                    'success',
                    'Folder classification keywords were updated.',
                    (string) $folder['parent_category']
                );
            }

            if ($action === 'create_folder') {
                if (!$allowCreate) {
                    throw new DomainException(
                        'Create folders from Company Files. Official Records mirrors the same controlled folder structure.'
                    );
                }
                $postedParent = is_string($_POST['parent_category'] ?? null)
                    ? trim($_POST['parent_category'])
                    : '';
                $isNewParent = $postedParent === 'NEW_PARENT_FOLDER';
                $isManagement = drms_folder_action_is_management(
                    $conn,
                    $userId,
                    $role
                );
                $roles = $isManagement
                    ? drms_folder_action_normalize_roles($_POST['assigned_roles'] ?? null)
                    : drms_folder_action_normalize_roles([$role]);

                if ($isNewParent) {
                    $parent = drms_folder_action_normalize_name(
                        $_POST['new_parent_category'] ?? null,
                        'Parent Folder name'
                    );
                    if (drms_folder_action_find($conn, $parent) !== null) {
                        throw new DomainException('Parent Folder already exists.');
                    }
                    $sub = '';
                    $policyId = null;
                    $keywords = '';
                    $prefix = null;
                } else {
                    $parent = drms_folder_action_normalize_name(
                        $postedParent,
                        'Parent Folder name'
                    );
                    drms_folder_action_require_access(
                        $conn,
                        $userId,
                        $role,
                        $parent
                    );
                    if (drms_folder_action_parent_is_protected($conn, $parent)) {
                        throw new DomainException(
                            'Protected workflow folders cannot accept manually created sub-folders.'
                        );
                    }
                    $sub = drms_folder_action_normalize_name(
                        $_POST['new_folder_name'] ?? null,
                        'Sub-folder name'
                    );
                    if (drms_folder_action_find_by_sub($conn, $sub) !== null) {
                        throw new DomainException(
                            'Sub-folder names must be unique across the record system.'
                        );
                    }
                    $policyId = filter_var(
                        $_POST['folder_policy'] ?? null,
                        FILTER_VALIDATE_INT
                    );
                    $policyId = $policyId === false ? 0 : (int) $policyId;
                    drms_folder_action_require_policy($conn, $policyId);
                    $keywords = drms_folder_action_normalize_keywords(
                        $_POST['classification_keywords'] ?? ''
                    );
                    $prefix = drms_folder_action_normalize_prefix(
                        $_POST['record_prefix'] ?? null
                    );
                    $conflicts = drms_folder_action_keyword_conflicts(
                        $conn,
                        $sub,
                        $keywords
                    );
                    if ($conflicts) {
                        throw new DomainException(
                            "Keyword '" . $conflicts[0]['keyword']
                            . "' is already assigned to folder '"
                            . $conflicts[0]['folder'] . "'."
                        );
                    }
                }

                $assigned = implode(', ', $roles);
                $drawerId = null;
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare(
                        'INSERT INTO document_categories '
                        . '(parent_category, sub_category, policy_id, '
                        . 'classification_keywords, assigned_to_role, '
                        . 'record_prefix, drawer_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param(
                        'ssisssi',
                        $parent,
                        $sub,
                        $policyId,
                        $keywords,
                        $assigned,
                        $prefix,
                        $drawerId
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('The folder could not be created.');
                    }
                    $categoryId = (int) $stmt->insert_id;
                    $stmt->close();
                    drms_folder_action_assign_roles($conn, $categoryId, $roles);
                    $conn->commit();
                } catch (Throwable $error) {
                    $conn->rollback();
                    throw $error;
                }

                if (function_exists('log_audit_action')) {
                    $description = $sub === ''
                        ? 'Created Parent Folder: ' . $parent
                        : 'Created Sub-folder: ' . $sub . ' under ' . $parent;
                    log_audit_action(
                        $conn,
                        $userId,
                        'CREATE_FOLDER',
                        $description
                    );
                }
                drms_folder_action_redirect(
                    $page,
                    'success',
                    $sub === ''
                        ? 'Parent Folder created successfully.'
                        : 'Sub-folder created successfully.',
                    $sub === '' ? '' : $parent
                );
            }

            if ($action === 'edit_folder_policy') {
                $parent = drms_folder_action_normalize_name(
                    $_POST['parent_name'] ?? null,
                    'Parent Folder name'
                );
                $sub = drms_folder_action_normalize_name(
                    $_POST['sub_name'] ?? null,
                    'Sub-folder name'
                );
                $policyId = filter_var(
                    $_POST['new_policy_id'] ?? null,
                    FILTER_VALIDATE_INT
                );
                $policyId = $policyId === false ? 0 : (int) $policyId;
                drms_folder_action_require_policy($conn, $policyId);
                $folder = drms_folder_action_require_access(
                    $conn,
                    $userId,
                    $role,
                    $parent,
                    $sub
                );
                drms_folder_action_require_mutable($folder);
                $folderId = (int) $folder['id'];
                $stmt = $conn->prepare(
                    'UPDATE document_categories SET policy_id = ? '
                    . 'WHERE id = ? AND is_system_folder = 0'
                );
                $stmt->bind_param('ii', $policyId, $folderId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Folder policy could not be updated.');
                }
                $stmt->close();
                if (function_exists('log_audit_action')) {
                    log_audit_action(
                        $conn,
                        $userId,
                        'UPDATE_FOLDER_POLICY',
                        'Changed retention policy for folder: ' . $sub
                        . ' under ' . $parent
                    );
                }
                drms_folder_action_redirect(
                    $page,
                    'success',
                    'Folder retention policy was updated.',
                    $parent
                );
            }

            if ($action === 'delete_folder') {
                $deleteType = is_string($_POST['delete_type'] ?? null)
                    ? $_POST['delete_type']
                    : '';
                if (!in_array($deleteType, ['parent', 'sub'], true)) {
                    throw new DomainException('Invalid folder deletion request.');
                }
                $parent = drms_folder_action_normalize_name(
                    $_POST['parent_name'] ?? null,
                    'Parent Folder name'
                );
                $sub = $deleteType === 'sub'
                    ? drms_folder_action_normalize_name(
                        $_POST['sub_name'] ?? null,
                        'Sub-folder name'
                    )
                    : null;
                $folder = drms_folder_action_require_access(
                    $conn,
                    $userId,
                    $role,
                    $parent,
                    $sub
                );
                drms_folder_action_require_mutable($folder);
                if (drms_folder_action_parent_is_protected($conn, $parent)) {
                    throw new DomainException(
                        'Protected workflow folders cannot be changed or deleted manually.'
                    );
                }

                if ($deleteType === 'parent') {
                    $stmt = $conn->prepare(
                        'SELECT COUNT(*) AS total FROM documents d '
                        . 'WHERE d.category = ? OR d.category IN '
                        . '(SELECT dc.sub_category FROM document_categories dc '
                        . 'WHERE dc.parent_category = ? AND dc.sub_category <> \'\')'
                    );
                    $stmt->bind_param('ss', $parent, $parent);
                } else {
                    $stmt = $conn->prepare(
                        'SELECT COUNT(*) AS total FROM documents WHERE category = ?'
                    );
                    $stmt->bind_param('s', $sub);
                }
                $stmt->execute();
                $documentCount = (int) $stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();
                if ($documentCount > 0) {
                    throw new DomainException(
                        'This folder cannot be deleted because it still contains records.'
                    );
                }

                $conn->begin_transaction();
                try {
                    if ($deleteType === 'parent') {
                        $stmt = $conn->prepare(
                            'DELETE cra FROM category_role_access cra '
                            . 'INNER JOIN document_categories dc '
                            . 'ON dc.id = cra.category_id '
                            . 'WHERE dc.parent_category = ? '
                            . 'AND dc.is_system_folder = 0'
                        );
                        $stmt->bind_param('s', $parent);
                        $stmt->execute();
                        $stmt->close();
                        $stmt = $conn->prepare(
                            'DELETE FROM document_categories '
                            . 'WHERE parent_category = ? AND is_system_folder = 0'
                        );
                        $stmt->bind_param('s', $parent);
                    } else {
                        $folderId = (int) $folder['id'];
                        $stmt = $conn->prepare(
                            'DELETE FROM category_role_access WHERE category_id = ?'
                        );
                        $stmt->bind_param('i', $folderId);
                        $stmt->execute();
                        $stmt->close();
                        $stmt = $conn->prepare(
                            'DELETE FROM document_categories '
                            . 'WHERE id = ? AND is_system_folder = 0'
                        );
                        $stmt->bind_param('i', $folderId);
                    }
                    if (!$stmt->execute() || $stmt->affected_rows < 1) {
                        throw new RuntimeException('The folder was not deleted.');
                    }
                    $stmt->close();
                    $conn->commit();
                } catch (Throwable $error) {
                    $conn->rollback();
                    throw $error;
                }

                if (function_exists('log_audit_action')) {
                    log_audit_action(
                        $conn,
                        $userId,
                        'DELETE_FOLDER',
                        $deleteType === 'parent'
                            ? 'Deleted empty Parent Folder: ' . $parent
                            : 'Deleted empty Sub-folder: ' . $sub
                                . ' under ' . $parent
                    );
                }
                drms_folder_action_redirect(
                    $page,
                    'success',
                    $deleteType === 'parent'
                        ? 'Empty Parent Folder deleted successfully.'
                        : 'Empty Sub-folder deleted successfully.',
                    $deleteType === 'parent' ? '' : $parent
                );
            }
        } catch (DomainException $error) {
            if (in_array($action, ['get_keywords'], true)) {
                drms_folder_action_json([
                    'status' => 'error',
                    'message' => $error->getMessage(),
                ], 403);
            }
            drms_folder_action_redirect(
                $page,
                'error',
                $error->getMessage()
            );
        } catch (Throwable $error) {
            error_log('Folder action failed: ' . $error->getMessage());
            if ($action === 'get_keywords') {
                drms_folder_action_json([
                    'status' => 'error',
                    'message' => 'The folder action could not be completed.',
                ], 500);
            }
            drms_folder_action_redirect(
                $page,
                'error',
                'The folder action could not be completed. No folder changes were saved.'
            );
        }
    }
}
