<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/config/db_connect.php';
require_once $projectRoot . '/config/functions.php';
require_once $projectRoot . '/config/rbac_policy.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$permissionDescriptions = [
    'can_manage_users' => 'Manage user accounts, roles, status, sessions, and role-appropriate capabilities.',
];
foreach (drms_rbac_capability_catalog() as $name => $definition) {
    $permissionDescriptions[$name] = (string) $definition['description'];
}

$permissionUpsert = $conn->prepare(
    'INSERT INTO permissions (permission_name, description)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE description = VALUES(description)'
);

$userQuery = $conn->prepare(
    'SELECT user_id, username, role
     FROM users
     ORDER BY user_id ASC
     FOR UPDATE'
);
$assignmentQuery = $conn->prepare(
    'SELECT permission_name
     FROM user_permissions
     WHERE user_id = ?
     ORDER BY permission_name ASC'
);

$permissionsUpserted = 0;
$usersNormalized = 0;
$assignmentsRemoved = 0;
$defaultsAdded = 0;

$conn->begin_transaction();
try {
    foreach ($permissionDescriptions as $name => $description) {
        $permissionUpsert->bind_param('ss', $name, $description);
        $permissionUpsert->execute();
        $permissionsUpserted++;
    }

    $userQuery->execute();
    $users = $userQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($users as $user) {
        $userId = (int) $user['user_id'];
        $role = (string) $user['role'];
        if (!in_array($role, drms_rbac_roles(), true)) {
            throw new RuntimeException(
                'User @' . $user['username'] . ' has an unsupported role: ' . $role
            );
        }

        $assignmentQuery->bind_param('i', $userId);
        $assignmentQuery->execute();
        $assignedRows = $assignmentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $assigned = array_map(
            static fn(array $row): string => (string) $row['permission_name'],
            $assignedRows
        );

        $editableAllowed = drms_rbac_capabilities_for_role($role);
        $kept = array_values(array_intersect($assigned, $editableAllowed));
        $assignmentsRemoved += count(array_diff($assigned, $kept));

        if ($kept === []) {
            $defaults = drms_rbac_default_capabilities_for_role($role);
            $kept = $defaults;
            $defaultsAdded += count($defaults);
        }

        drms_rbac_replace_user_capabilities($conn, $userId, $role, $kept);
        $usersNormalized++;
    }

    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, "RBAC Phase 1 installation FAILED:\n- " . $error->getMessage() . "\n");
    exit(1);
} finally {
    $permissionUpsert->close();
    $userQuery->close();
    $assignmentQuery->close();
}

echo "RBAC Phase 1 installation PASSED.\n";
echo "- Permission definitions synchronized: {$permissionsUpserted}\n";
echo "- User capability sets normalized: {$usersNormalized}\n";
echo "- Incompatible legacy assignments removed: {$assignmentsRemoved}\n";
echo "- Least-privilege defaults added: {$defaultsAdded}\n";
echo "- Collections policy: Finance only\n";

