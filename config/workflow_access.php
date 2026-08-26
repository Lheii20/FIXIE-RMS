<?php

if (!function_exists('drms_require_login')) {
    function drms_require_login(string $login_page = 'index.php'): void
    {
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        header('Location: ' . $login_page);
        exit();
    }
}

if (!function_exists('drms_require_workflow_roles')) {
    function drms_require_workflow_roles(
        array $allowed_roles,
        string $denied_page = 'dashboard.php',
        string $login_page = 'index.php'
    ): void {
        drms_require_login($login_page);

        $current_role = (string) ($_SESSION['role'] ?? '');
        if (!in_array($current_role, $allowed_roles, true)) {
            header('Location: ' . $denied_page);
            exit();
        }
    }
}

if (!function_exists('drms_user_has_workflow_role')) {
    function drms_user_has_workflow_role(array $allowed_roles): bool
    {
        return in_array(
            (string) ($_SESSION['role'] ?? ''),
            $allowed_roles,
            true
        );
    }
}

