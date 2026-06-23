<?php

if (!function_exists('drms_audit_ip_address')) {
    function drms_audit_ip_address() {
        $candidates = [
            $_SERVER['HTTP_CLIENT_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') continue;
            $parts = explode(',', $candidate);
            $ip = trim($parts[0]);
            if ($ip !== '') return substr($ip, 0, 45);
        }

        return 'UNKNOWN';
    }
}

if (!function_exists('drms_audit_compact_text')) {
    function drms_audit_compact_text($value, $limit = 180) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', array_slice($value, 0, 5)));
        }

        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/\s+/', ' ', $value);

        if (strlen($value) > $limit) {
            $value = substr($value, 0, $limit - 3) . '...';
        }

        return $value;
    }
}

if (!function_exists('drms_audit_mask_email')) {
    function drms_audit_mask_email($email) {
        $email = trim((string)$email);
        if ($email === '' || strpos($email, '@') === false) return drms_audit_compact_text($email, 80);

        [$name, $domain] = explode('@', $email, 2);
        $name = strlen($name) <= 2 ? substr($name, 0, 1) . '*' : substr($name, 0, 2) . str_repeat('*', min(6, strlen($name) - 2));
        return $name . '@' . $domain;
    }
}

if (!function_exists('drms_log_audit_action')) {
    // IN-UPDATE: Nagdagdag ng old_payload at new_payload arguments
    function drms_log_audit_action($conn, $user_id, $action_type, $description, $old_payload = null, $new_payload = null) {
        if (!($conn instanceof mysqli)) return false;

        $user_id = ($user_id === null || $user_id === '' || (int)$user_id <= 0) ? null : (int)$user_id;
        $action_type = strtoupper(trim((string)$action_type));
        $action_type = $action_type !== '' ? substr($action_type, 0, 50) : 'SYSTEM_EVENT';
        $description = drms_audit_compact_text($description, 4000);
        $ip_address = drms_audit_ip_address();

        // Convert arrays/objects to JSON strings if provided
        $old_json = $old_payload !== null ? (is_string($old_payload) ? $old_payload : json_encode($old_payload)) : null;
        $new_json = $new_payload !== null ? (is_string($new_payload) ? $new_payload : json_encode($new_payload)) : null;

        try {
            $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, old_payload, new_payload, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $user_id, $action_type, $description, $old_json, $new_json, $ip_address);
            $stmt->execute();
            return $stmt->insert_id;
        } catch (Throwable $e) {
            error_log("Audit insert failed, retrying with manual ID: " . $e->getMessage());
        }

        try {
            $next_id = 1;
            $res = $conn->query("SELECT COALESCE(MAX(log_id), 0) + 1 AS next_id FROM audit_logs");
            if ($res && $row = $res->fetch_assoc()) {
                $next_id = (int)$row['next_id'];
            }

            $stmt = $conn->prepare("INSERT INTO audit_logs (log_id, user_id, action_type, description, old_payload, new_payload, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssss", $next_id, $user_id, $action_type, $description, $old_json, $new_json, $ip_address);
            $stmt->execute();
            return $next_id;
        } catch (Throwable $e) {
            error_log("Audit insert failed completely: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('drms_audit_relative_path')) {
    function drms_audit_relative_path() {
        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $script = str_replace('\\', '/', $script);
        $needle = '/fixie_drms/';
        $pos = strpos($script, $needle);

        if ($pos !== false) {
            return substr($script, $pos + strlen($needle));
        }

        return ltrim($script, '/');
    }
}

if (!function_exists('drms_audit_module_name')) {
    function drms_audit_module_name($path) {
        $path = str_replace('\\', '/', (string)$path);
        $base = basename($path);

        $map = [
            'dashboard.php' => 'Main Dashboard',
            'admin_users.php' => 'User Management',
            'admin_requests.php' => 'Account Requests',
            'audit_logs.php' => 'System Audit Trail',
            'settings.php' => 'Account Settings',
            'notifications.php' => 'Notifications',
            'documents.php' => 'Official Records',
            'general_docs.php' => 'Company Files',
            'pr_list.php' => 'Purchase Requests',
            'create_pr.php' => 'Create Purchase Request',
            'view_pr.php' => 'Purchase Request Details',
            'quotations_list.php' => 'Quotations Tracker',
            'create_quotation.php' => 'Create Quotation',
            'po_list.php' => 'Purchase Orders',
            'create_po.php' => 'Create Purchase Order',
            'view_po.php' => 'Purchase Order Details',
            'download.php' => 'Document Download',
            'forgot_password.php' => 'Password Recovery',
            'reset_password.php' => 'Password Reset',
            'index.php' => 'Login Page'
        ];

        if (isset($map[$base])) return $map[$base];

        $label = str_replace(['.php', '_', '-'], ['', ' ', ' '], $base);
        return ucwords(trim($label) ?: 'System Module');
    }
}

if (!function_exists('drms_audit_action_label')) {
    function drms_audit_action_label($action) {
        $action = drms_audit_compact_text($action, 80);
        if ($action === '') return 'system request';
        return strtolower(str_replace('_', ' ', $action));
    }
}

if (!function_exists('drms_audit_is_sensitive_key')) {
    function drms_audit_is_sensitive_key($key) {
        $key = strtolower((string)$key);
        $sensitive = ['password', 'pass', 'csrf', 'token', 'secret', 'code', 'otp', 'hash'];
        foreach ($sensitive as $needle) {
            if (strpos($key, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('drms_audit_payload_summary')) {
    function drms_audit_payload_summary($payload, $limit = 8) {
        $parts = [];

        foreach ($payload as $key => $value) {
            if (count($parts) >= $limit) break;
            if (drms_audit_is_sensitive_key($key)) continue;

            $clean_key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$key);
            if ($clean_key === '') continue;

            if (is_array($value)) {
                if ($clean_key === 'items') {
                    $clean_value = count($value) . ' item(s)';
                } else {
                    $flat = [];
                    foreach ($value as $item) {
                        if (is_scalar($item)) $flat[] = $item;
                        if (count($flat) >= 3) break;
                    }
                    $clean_value = $flat ? implode(', ', $flat) : count($value) . ' value(s)';
                }
            } else {
                $clean_value = $value;
            }

            if (stripos($clean_key, 'email') !== false) {
                $clean_value = drms_audit_mask_email($clean_value);
            } else {
                $clean_value = drms_audit_compact_text($clean_value, 80);
            }

            if ($clean_value === '') continue;
            $parts[] = $clean_key . '=' . $clean_value;
        }

        return implode('; ', $parts);
    }
}

if (!function_exists('drms_audit_files_summary')) {
    function drms_audit_files_summary($files) {
        $parts = [];

        foreach ($files as $field => $file) {
            if (count($parts) >= 4) break;
            $name = '';

            if (is_array($file['name'] ?? null)) {
                $names = array_filter(array_map('basename', $file['name']));
                $name = implode(', ', array_slice($names, 0, 3));
            } else {
                $name = basename((string)($file['name'] ?? ''));
            }

            if ($name !== '') {
                $parts[] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$field) . '=' . drms_audit_compact_text($name, 100);
            }
        }

        return implode('; ', $parts);
    }
}

if (!function_exists('drms_audit_type_for_request')) {
    function drms_audit_type_for_request($method, $path, $payload) {
        $base = basename($path);
        $method = strtoupper($method);
        $action = strtolower((string)($payload['action'] ?? ''));
        $decision = strtolower((string)($payload['decision'] ?? ''));

        if ($method === 'GET') {
            if ($base === 'download.php') return 'DOWNLOAD_ATTEMPT';
            if (isset($_GET['search']) && trim((string)$_GET['search']) !== '') return 'SEARCH';
            if (isset($_GET['filter']) || isset($_GET['type']) || isset($_GET['view_archives']) || isset($_GET['tab'])) return 'FILTER';
            if (strpos($base, 'view_') === 0) return 'VIEW_RECORD';
            return 'PAGE_VIEW';
        }

        if (isset($payload['login']) || $base === 'login.php' || $base === 'auth.php' && isset($payload['username'])) return 'LOGIN_ATTEMPT';
        if (strpos($action, 'delete') !== false || strpos($action, 'destroy') !== false) return 'DELETE_REQUEST';
        if (strpos($action, 'approve') !== false || $decision === 'approve') return 'APPROVE_REQUEST';
        if (strpos($action, 'reject') !== false || $decision === 'reject') return 'REJECT_REQUEST';
        if (strpos($action, 'archive') !== false) return 'ARCHIVE_REQUEST';
        if (strpos($action, 'restore') !== false) return 'RESTORE_REQUEST';
        if (strpos($action, 'upload') !== false || !empty($_FILES)) return 'UPLOAD_REQUEST';
        if (strpos($action, 'create') !== false) return 'CREATE_REQUEST';
        if (strpos($action, 'update') !== false || strpos($action, 'edit') !== false || strpos($action, 'change') !== false || strpos($action, 'renew') !== false) return 'UPDATE_REQUEST';
        if (strpos($action, 'print') !== false) return 'PRINT_REQUEST';
        if (strpos($action, 'request') !== false || strpos($action, 'submit') !== false) return 'REQUEST_SUBMIT';

        return 'FORM_SUBMIT';
    }
}

if (!function_exists('drms_audit_should_skip_request')) {
    function drms_audit_should_skip_request($path) {
        $path = str_replace('\\', '/', (string)$path);
        $base = basename($path);

        if (in_array($base, ['db_connect.php', 'functions.php', 'audit_bootstrap.php'], true)) return true;
        if ($path === '' || $base === '') return true;

        $audit_endpoints = ['api/log_action.php', 'api/log_print.php'];
        foreach ($audit_endpoints as $endpoint) {
            if (substr($path, -strlen($endpoint)) === $endpoint) return true;
        }

        return false;
    }
}

if (!function_exists('drms_audit_description_for_request')) {
    function drms_audit_description_for_request($method, $path, $payload) {
        $method = strtoupper($method);
        $module = drms_audit_module_name($path);
        $action = $payload['action'] ?? '';

        if ($method === 'GET') {
            if (basename($path) === 'download.php') {
                $description = 'Opened or downloaded a document from ' . $module;
            } elseif (strpos(basename($path), 'view_') === 0) {
                $description = 'Viewed record details in ' . $module;
            } else {
                $description = 'Opened ' . $module;
            }

            $summary = drms_audit_payload_summary($_GET);
            return $summary !== '' ? $description . ' | Parameters: ' . $summary : $description;
        }

        if (isset($payload['login']) || basename($path) === 'login.php') {
            $username = drms_audit_compact_text($payload['username'] ?? 'Unknown', 80);
            return 'Login attempt for username=' . ($username !== '' ? $username : 'Unknown');
        }

        $label = drms_audit_action_label($action);
        $description = 'Submitted ' . $label . ' in ' . $module;
        $summary = drms_audit_payload_summary($payload);
        $files = drms_audit_files_summary($_FILES);

        if ($summary !== '') $description .= ' | Details: ' . $summary;
        if ($files !== '') $description .= ' | Files: ' . $files;

        return $description;
    }
}

if (!function_exists('drms_capture_request_audit')) {
    function drms_capture_request_audit($conn) {
        if (defined('DRMS_AUDIT_REQUEST_CAPTURED')) return;
        if (!($conn instanceof mysqli)) return;

        $path = drms_audit_relative_path();
        if (drms_audit_should_skip_request($path)) return;

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'POST'], true)) return;

        $user_id = $_SESSION['user_id'] ?? null;
        $is_public_post = $method === 'POST' && (
            isset($_POST['login']) ||
            in_array(($_POST['action'] ?? ''), ['request_forgot_password', 'verify_reset_code', 'execute_reset_password'], true) ||
            basename($path) === 'login.php'
        );

        if (!$user_id && !$is_public_post) return;

        $payload = $method === 'POST' ? $_POST : $_GET;
        $action_type = drms_audit_type_for_request($method, $path, $payload);
        $description = drms_audit_description_for_request($method, $path, $payload);

        // Alisin ang buong GET parameters payload para malinis (sa future automatic capturing)
        // Ang Old/New state changes ang bahala sa details
        $clean_desc = explode('| Details:', $description)[0];

        if ($method === 'GET') {
            $signature = sha1($method . '|' . ($_SERVER['REQUEST_URI'] ?? '') . '|' . $action_type);
            $now = time();
            if (($_SESSION['audit_last_signature'] ?? '') === $signature && ($now - ($_SESSION['audit_last_signature_time'] ?? 0)) < 5) {
                return;
            }
            $_SESSION['audit_last_signature'] = $signature;
            $_SESSION['audit_last_signature_time'] = $now;
        }

        if (drms_log_audit_action($conn, $user_id, $action_type, $clean_desc) !== false) {
            define('DRMS_AUDIT_REQUEST_CAPTURED', true);
        }
    }
}
?>