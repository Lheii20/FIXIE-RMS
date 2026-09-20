<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

$projectRoot = dirname(__DIR__);
$failures = [];

$read = static function (string $relativePath) use ($projectRoot, &$failures): string {
    $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        $failures[] = 'Missing required file: ' . $relativePath;
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = 'Unable to read: ' . $relativePath;
        return '';
    }
    return $contents;
};

require_once $projectRoot . '/config/rbac_policy.php';

if (drms_rbac_module_roles('collections') !== ['Finance']) {
    $failures[] = 'Collections policy is not restricted to Finance only.';
}

$expectedModules = [
    'quotations' => ['Sales Staff', 'GM'],
    'purchase_requests' => ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'],
    'purchase_orders' => ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'],
    'collections' => ['Finance'],
    'user_management' => ['Admin'],
];
foreach ($expectedModules as $module => $roles) {
    if (drms_rbac_module_roles($module) !== $roles) {
        $failures[] = 'Unexpected role policy for module: ' . $module;
    }
}

$collectionPages = [
    'collection_monitoring.php',
    'collection_aging.php',
    'collection_ledger.php',
    'collection_statement.php',
    'collection_followup.php',
    'record_collection_payment.php',
];
foreach ($collectionPages as $page) {
    $contents = $read($page);
    if ($contents !== '' && strpos($contents, "drms_rbac_require_module('collections')") === false) {
        $failures[] = $page . ' is missing the central Collections page guard.';
    }
}

$collectionHandlers = [
    'actions/collection_followup_handler.php',
    'actions/collection_payment_handler.php',
];
foreach ($collectionHandlers as $handler) {
    $contents = $read($handler);
    if ($contents !== '' && strpos($contents, "drms_rbac_role_can_access_module((string) (\$_SESSION['role'] ?? ''), 'collections')") === false) {
        $failures[] = $handler . ' is missing the central Finance-only action guard.';
    }
}

$sidebar = $read('sidebar.php');
if ($sidebar !== '' && substr_count($sidebar, '$can_access_collections') < 4) {
    $failures[] = 'Collections visibility is not consistently policy-driven in the sidebar.';
}

$functions = $read('config/functions.php');
if ($functions !== '' && strpos($functions, 'drms_rbac_effective_capabilities') === false) {
    $failures[] = 'has_permission() is not filtering assignments through the role policy.';
}

$handler = $read('actions/user_handler.php');
foreach ([
    'drms_rbac_roles()',
    'drms_rbac_capabilities_for_role($target_role)',
    'drms_rbac_replace_user_capabilities',
] as $marker) {
    if ($handler !== '' && strpos($handler, $marker) === false) {
        $failures[] = 'User Management is missing RBAC enforcement marker: ' . $marker;
    }
}
if ($handler !== '' && strpos($handler, 'Clone the established capabilities') !== false) {
    $failures[] = 'User Management still clones capabilities from an older account.';
}

$adminPage = $read('admin_users.php');
foreach (['allowed_roles', 'default_roles', 'roleModuleAccess', 'permWorkflowList'] as $marker) {
    if ($adminPage !== '' && strpos($adminPage, $marker) === false) {
        $failures[] = 'Admin capability UI is missing role-aware marker: ' . $marker;
    }
}

try {
    require $projectRoot . '/config/db_connect.php';
    require_once $projectRoot . '/config/functions.php';

    $knownPermissions = ['can_manage_users'];
    $knownPermissions = array_merge(
        $knownPermissions,
        array_keys(drms_rbac_capability_catalog())
    );
    $permissionResult = $conn->query('SELECT permission_name FROM permissions');
    $databasePermissions = [];
    while ($row = $permissionResult->fetch_assoc()) {
        $databasePermissions[] = (string) $row['permission_name'];
    }
    foreach ($knownPermissions as $permission) {
        if (!in_array($permission, $databasePermissions, true)) {
            $failures[] = 'Database permission definition is missing: ' . $permission;
        }
    }

    $assignments = $conn->query(
        'SELECT u.username, u.role, up.permission_name
         FROM user_permissions up
         INNER JOIN users u ON u.user_id = up.user_id
         ORDER BY u.user_id, up.permission_name'
    );
    while ($assignment = $assignments->fetch_assoc()) {
        $role = (string) $assignment['role'];
        $permission = (string) $assignment['permission_name'];
        if (!drms_rbac_role_allows_capability($role, $permission)) {
            $failures[] = 'Incompatible database assignment: @' . $assignment['username'] .
                ' (' . $role . ') -> ' . $permission;
        }
    }
} catch (Throwable $error) {
    $failures[] = 'Database verification could not run: ' . $error->getMessage();
}

if ($failures !== []) {
    echo "RBAC Phase 1 verification FAILED:\n\n";
    foreach ($failures as $failure) {
        echo '- ' . $failure . "\n";
    }
    exit(1);
}

echo "RBAC Phase 1 verification PASSED.\n";
echo "- Collections pages, navigation, and write handlers are Finance-only.\n";
echo "- Workflow module roles match the central policy.\n";
echo "- Admin capability choices are limited by target role.\n";
echo "- Database capability assignments comply with the role policy.\n";

