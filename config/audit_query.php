<?php

declare(strict_types=1);

function drms_audit_excluded_action_types(): array
{
    return ['PAGE_VIEW', 'FILTER', 'SEARCH', 'FORM_SUBMIT'];
}

function drms_audit_module_values(): array
{
    return [
        'Authentication',
        'Document Management',
        'Purchase Orders',
        'Purchase Requests',
        'Quotations',
        'Finance',
        'System Operations',
    ];
}

function drms_audit_category_values(): array
{
    return [
        'Security',
        'Creation',
        'Modification',
        'Approval',
        'Deletion',
        'System Access',
    ];
}

function drms_audit_module_case_sql(string $alias = 'a'): string
{
    $action = "UPPER({$alias}.action_type)";
    $description = "LOWER(COALESCE({$alias}.description, ''))";
    $payloadRecordType = "JSON_UNQUOTE(JSON_EXTRACT(COALESCE({$alias}.new_payload, '{}'), '$.record_type'))";

    return "CASE
        WHEN {$description} LIKE '%purchase request%'
            THEN 'Purchase Requests'
        WHEN {$description} LIKE '%purchase order%'
            THEN 'Purchase Orders'
        WHEN {$description} LIKE '%quotation%'
            THEN 'Quotations'
        WHEN ({$action} = 'PRINT_DOC' AND {$payloadRecordType} = 'purchase_order')
          OR {$action} REGEXP '(^|_)PO($|_)'
          OR {$action} IN (
                'WORKFLOW_ACTION', 'CLAIM_TASK', 'RELEASE_TASK',
                'REASSIGN_TASK', 'SUBMIT_DELIVERY_REQUEST',
                'APPROVE_DELIVERY_SCHEDULE', 'COMPLETE_CLIENT_DELIVERY',
                'VIEW_PO'
          )
            THEN 'Purchase Orders'
        WHEN {$action} LIKE '%LOGIN%'
          OR {$action} LIKE '%LOGOUT%'
          OR {$action} LIKE '%PASSWORD%'
          OR {$action} LIKE '%OTP%'
            THEN 'Authentication'
        WHEN {$action} LIKE '%QUOTATION%'
          OR {$action} LIKE '%CLIENT_PO%'
          OR {$action} LIKE '%CLIENT_APPROVAL%'
          OR {$action} IN ('SUBMIT_CLIENT_APPROVAL', 'RECORD_CLIENT_CONFIRMATION')
            THEN 'Quotations'
        WHEN {$action} REGEXP '(^|_)PR($|_)'
            THEN 'Purchase Requests'
        WHEN {$action} LIKE '%PAYMENT%'
          OR {$action} LIKE '%COLLECTION%'
          OR {$action} LIKE '%FUNDING%'
          OR {$action} LIKE '%FOLLOWUP%'
            THEN 'Finance'
        WHEN {$action} LIKE '%DOCUMENT%'
          OR {$action} LIKE '%FILE%'
          OR {$action} LIKE '%FOLDER%'
          OR {$action} LIKE '%POLICY%'
          OR {$action} LIKE '%UPLOAD%'
          OR {$action} LIKE '%DOWNLOAD%'
          OR {$action} LIKE '%ARCHIVE%'
          OR {$action} LIKE '%RESTORE%'
          OR {$action} LIKE '%PHYSICAL%'
          OR {$action} LIKE '%LEGAL_HOLD%'
          OR {$action} LIKE '%DISPOSITION%'
          OR {$action} LIKE '%DESTRUCTION%'
          OR {$action} LIKE '%OFFICIAL%'
          OR {$action} IN ('CHECK_IN', 'CHECK_OUT', 'PRINT_DOC')
            THEN 'Document Management'
        ELSE 'System Operations'
    END";
}

function drms_audit_category_case_sql(string $alias = 'a'): string
{
    $action = "UPPER({$alias}.action_type)";

    return "CASE
        WHEN {$action} LIKE '%LOGIN%'
          OR {$action} LIKE '%LOGOUT%'
          OR {$action} LIKE '%PASSWORD%'
          OR {$action} LIKE '%OTP%'
            THEN 'Security'
        WHEN {$action} LIKE '%DELETE%'
          OR {$action} LIKE '%DESTROY%'
          OR {$action} LIKE '%DESTRUCTION%'
          OR {$action} LIKE '%PURGE%'
          OR {$action} LIKE '%REMOVE%'
            THEN 'Deletion'
        WHEN {$action} LIKE '%APPROVE%'
          OR {$action} LIKE '%ACKNOWLEDGE%'
          OR {$action} LIKE '%FINAL_APPROVE%'
            THEN 'Approval'
        WHEN {$action} LIKE '%CREATE%'
          OR {$action} LIKE '%UPLOAD%'
          OR {$action} LIKE '%RECEIVE%'
          OR {$action} LIKE '%RECORD_%'
            THEN 'Creation'
        WHEN {$action} LIKE '%UPDATE%'
          OR {$action} LIKE '%EDIT%'
          OR {$action} LIKE '%RENAME%'
          OR {$action} LIKE '%REPLACE%'
          OR {$action} LIKE '%MAP_%'
          OR {$action} LIKE '%CHECK_%'
          OR {$action} LIKE '%APPLY_%'
          OR {$action} LIKE '%RELEASE_%'
            THEN 'Modification'
        ELSE 'System Access'
    END";
}

function drms_audit_base_where_sql(string $alias = 'a'): string
{
    $quoted = array_map(
        static fn(string $value): string => "'" . $value . "'",
        drms_audit_excluded_action_types()
    );

    return "{$alias}.action_type NOT IN (" . implode(', ', $quoted) . ')';
}

function drms_audit_normalize_filters(array $input): array
{
    $search = trim((string) ($input['search'] ?? ''));
    $search = substr($search, 0, 100);

    $module = trim((string) ($input['module'] ?? ''));
    if (!in_array($module, drms_audit_module_values(), true)) {
        $module = '';
    }

    $category = trim((string) ($input['category'] ?? ''));
    if (!in_array($category, drms_audit_category_values(), true)) {
        $category = '';
    }

    return [
        'search' => $search,
        'module' => $module,
        'category' => $category,
    ];
}

function drms_audit_build_where(array $input, string $auditAlias = 'a', string $userAlias = 'u'): array
{
    $filters = drms_audit_normalize_filters($input);
    $conditions = [drms_audit_base_where_sql($auditAlias)];
    $types = '';
    $params = [];

    if ($filters['search'] !== '') {
        $term = '%' . $filters['search'] . '%';
        $conditions[] = "(
            {$userAlias}.full_name LIKE ?
            OR {$userAlias}.role LIKE ?
            OR {$auditAlias}.action_type LIKE ?
            OR {$auditAlias}.description LIKE ?
            OR {$auditAlias}.ip_address LIKE ?
        )";
        $types .= 'sssss';
        array_push($params, $term, $term, $term, $term, $term);
    }

    if ($filters['module'] !== '') {
        $conditions[] = '(' . drms_audit_module_case_sql($auditAlias) . ') = ?';
        $types .= 's';
        $params[] = $filters['module'];
    }

    if ($filters['category'] !== '') {
        $conditions[] = '(' . drms_audit_category_case_sql($auditAlias) . ') = ?';
        $types .= 's';
        $params[] = $filters['category'];
    }

    return [
        'sql' => implode(' AND ', $conditions),
        'types' => $types,
        'params' => $params,
        'filters' => $filters,
    ];
}

function drms_audit_bind_params(mysqli_stmt $statement, string $types, array $params): void
{
    if ($types === '') {
        return;
    }

    $arguments = [$types];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $arguments[] = &$params[$index];
    }
    call_user_func_array([$statement, 'bind_param'], $arguments);
}

function drms_audit_scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $statement = $conn->prepare($sql);
    drms_audit_bind_params($statement, $types, $params);
    $statement->execute();
    $row = $statement->get_result()->fetch_row();
    $statement->close();
    return (int) ($row[0] ?? 0);
}

function drms_audit_allowed_page_lengths(): array
{
    return [15, 30, 50, 100];
}

function drms_audit_page_length($value): int
{
    $length = filter_var($value, FILTER_VALIDATE_INT);
    return in_array($length, drms_audit_allowed_page_lengths(), true)
        ? (int) $length
        : 15;
}
