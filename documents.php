<?php 
require 'config/db_connect.php'; 

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$is_top_mgmt = in_array($role, ['GM', 'President', 'Admin']);
$can_manage = $is_top_mgmt; 

function folderRoleMatches($assigned_roles, $role) {
    if (empty($assigned_roles)) return false;
    $roles = array_map('trim', explode(',', $assigned_roles));
    foreach ($roles as $assigned_role) {
        if (strcasecmp($assigned_role, $role) === 0) return true;
    }
    return false;
}

function getAssignedParentFoldersForRole($conn, $role) {
    $parents = [];
    $stmt = $conn->prepare("SELECT parent_category, assigned_to_role FROM document_categories WHERE sub_category != '' AND assigned_to_role IS NOT NULL AND assigned_to_role != '' ORDER BY parent_category ASC");
    if (!$stmt) return $parents;
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (folderRoleMatches($row['assigned_to_role'], $role)) {
            $parent = trim($row['parent_category']);
            if ($parent !== '') {
                $exists = false;
                foreach ($parents as $existing) {
                    if (strcasecmp($existing, $parent) === 0) { $exists = true; break; }
                }
                if (!$exists) $parents[] = $parent;
            }
        }
    }
    return $parents;
}

function parentFolderExists($conn, $parent) {
    $stmt = $conn->prepare("SELECT id FROM document_categories WHERE parent_category = ? LIMIT 1");
    $stmt->bind_param("s", $parent);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function subFolderExists($conn, $parent, $sub) {
    $stmt = $conn->prepare("SELECT id FROM document_categories WHERE parent_category = ? AND sub_category = ? LIMIT 1");
    $stmt->bind_param("ss", $parent, $sub);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function redirectDocumentsWithMessage($type, $message, $parent = '') {
    $url = "documents.php?" . $type . "=" . urlencode($message);
    if ($parent !== '') {
        $url = "documents.php?parent=" . urlencode($parent) . "&" . $type . "=" . urlencode($message);
    }
    header("Location: " . $url);
    exit();
}

// ==========================================
// KUNIN ANG MGA RETENTION POLICIES
// ==========================================
$policies = [];
$pol_query = $conn->query("SELECT * FROM retention_policies ORDER BY retention_years ASC");
if ($pol_query) {
    while ($p = $pol_query->fetch_assoc()) {
        $policies[] = $p;
    }
}

// ==========================================
// FORM HANDLER: CREATE, DELETE FOLDER & EDIT POLICY
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }
    
    if ($_POST['action'] === 'edit_policy') {
        if (!in_array($role, ['Admin', 'GM'])) {
            header("Location: documents.php?error=" . urlencode("Only the Admin and General Manager can edit Retention Policies."));
            exit();
        }
        
        $policy_id = intval($_POST['policy_id']);
        $policy_name = trim($_POST['policy_name']);
        $years = intval($_POST['retention_years']);
        $action_after = $_POST['action_after_retention'];
        
        $stmt_edit = $conn->prepare("UPDATE retention_policies SET policy_name=?, retention_years=?, action_after_retention=? WHERE policy_id=?");
        $stmt_edit->bind_param("sisi", $policy_name, $years, $action_after, $policy_id);
        
        if ($stmt_edit->execute()) {
            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_POLICY', "Updated Policy ID: $policy_id to $years Years ($action_after).");
            }
            header("Location: documents.php?success=" . urlencode("Retention Policy updated successfully."));
            exit();
        } else {
            header("Location: documents.php?error=" . urlencode("Failed to update policy."));
            exit();
        }
    }

    if ($_POST['action'] === 'create_folder') {
        $parent = trim($_POST['parent_category'] ?? '');
        $sub = trim($_POST['new_folder_name'] ?? '');
        $folder_policy = !empty($_POST['folder_policy']) ? intval($_POST['folder_policy']) : null;
        $is_new_parent = ($parent === 'NEW_PARENT_FOLDER');

        if ($is_new_parent) {
            if (!$is_admin) {
                redirectDocumentsWithMessage("error", "Only the System Administrator can create Parent Folders.");
            }

            $parent = trim($_POST['new_parent_category'] ?? '');
            if ($parent === '') {
                redirectDocumentsWithMessage("error", "Parent Folder name cannot be empty.");
            }
            if (parentFolderExists($conn, $parent)) {
                redirectDocumentsWithMessage("error", "Parent Folder already exists.");
            }

            $roles_assigned = isset($_POST['assigned_roles']) ? implode(',', array_map('trim', $_POST['assigned_roles'])) : '';
            if ($sub === '') {
                $roles_assigned = '';
                $folder_policy = null;
            }
        } else {
            if ($parent === '') {
                redirectDocumentsWithMessage("error", "Please select a Parent Folder.");
            }
            if (!parentFolderExists($conn, $parent)) {
                redirectDocumentsWithMessage("error", "Selected Parent Folder does not exist.");
            }
            if ($sub === '') {
                redirectDocumentsWithMessage("error", "Sub-folder name is required.", $parent);
            }
            if (subFolderExists($conn, $parent, $sub)) {
                redirectDocumentsWithMessage("error", "Sub-folder already exists in this Parent Folder.", $parent);
            }
            if (!$is_top_mgmt) {
                $assigned_parents = getAssignedParentFoldersForRole($conn, $role);
                $is_allowed_parent = false;
                foreach ($assigned_parents as $assigned_parent) {
                    if (strcasecmp($assigned_parent, $parent) === 0) { $is_allowed_parent = true; break; }
                }
                if (!$is_allowed_parent) {
                    redirectDocumentsWithMessage("error", "You can only create sub-folders inside your assigned Parent Folders.");
                }
                $roles_assigned = $role;
            } else {
                $roles_assigned = isset($_POST['assigned_roles']) ? implode(',', array_map('trim', $_POST['assigned_roles'])) : '';
            }
            if ($folder_policy === null) {
                redirectDocumentsWithMessage("error", "Retention Policy is required when creating a sub-folder.", $parent);
            }
        }

        if ($sub !== '' && $roles_assigned === '') {
            redirectDocumentsWithMessage("error", "Please assign at least one role for this sub-folder.", $parent);
        }

        $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, assigned_to_role, policy_id) VALUES (?, ?, ?, ?)");
        $stmt_create->bind_param("sssi", $parent, $sub, $roles_assigned, $folder_policy);
        if ($stmt_create->execute()) {
            $message = ($sub === '') ? "Parent Folder created successfully." : "Sub-folder created successfully.";
            redirectDocumentsWithMessage("success", $message, ($sub === '' ? '' : $parent));
        }

        redirectDocumentsWithMessage("error", "Failed to create folder.", $parent);
    }

    if ($is_top_mgmt) {
        if ($_POST['action'] === 'delete_folder') {
            $delete_type = $_POST['delete_type'];
            $parent_name = $_POST['parent_name'];
            $sub_name = $_POST['sub_name'];
            
            if ($delete_type === 'parent') {
                if ($role !== 'Admin') {
                    header("Location: documents.php?error=" . urlencode("Only the Admin can delete Main Folders."));
                    exit();
                }
                $stmt_subs = $conn->prepare("SELECT sub_category FROM document_categories WHERE parent_category = ? AND sub_category != ''");
                $stmt_subs->bind_param("s", $parent_name);
                $stmt_subs->execute();
                $res_subs = $stmt_subs->get_result();
                $total_files = 0;
                while($sub_row = $res_subs->fetch_assoc()) {
                    $chk = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE category = ?");
                    $chk->bind_param("s", $sub_row['sub_category']);
                    $chk->execute();
                    $total_files += $chk->get_result()->fetch_assoc()['total'];
                }
                if ($total_files == 0) {
                    $del = $conn->prepare("DELETE FROM document_categories WHERE parent_category = ?");
                    $del->bind_param("s", $parent_name);
                    $del->execute();
                    header("Location: documents.php?success=" . urlencode("Main Folder deleted successfully."));
                    exit();
                } else {
                    header("Location: documents.php?error=" . urlencode("Cannot delete Main Folder. Make sure ALL Sub-folders are empty."));
                    exit();
                }
            } elseif ($delete_type === 'sub') {
                $chk = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE category = ?");
                $chk->bind_param("s", $sub_name);
                $chk->execute();
                $total_files = $chk->get_result()->fetch_assoc()['total'];
                if ($total_files == 0) {
                    $del = $conn->prepare("DELETE FROM document_categories WHERE sub_category = ? AND parent_category = ?");
                    $del->bind_param("ss", $sub_name, $parent_name);
                    $del->execute();
                    header("Location: documents.php?parent=" . urlencode($parent_name) . "&success=" . urlencode("Sub-folder deleted successfully."));
                    exit();
                } else {
                    header("Location: documents.php?parent=" . urlencode($parent_name) . "&error=" . urlencode("Cannot delete folder. The folder must be completely empty."));
                    exit();
                }
            }
        }
    }
}

// ==========================================
// STRICT DEDUPLICATION FOLDER FETCHING
// ==========================================
$parent_folders = [];
$role_assigned_folders = [];

$cat_query = $conn->query("SELECT TRIM(parent_category) as p_cat, TRIM(sub_category) as s_cat, assigned_to_role FROM document_categories ORDER BY parent_category ASC, id ASC");
if ($cat_query) {
    while ($row = $cat_query->fetch_assoc()) {
        $p_cat = $row['p_cat'];
        $s_cat = $row['s_cat'];
        
        if($p_cat === '') continue;

        $p_key = $p_cat;
        foreach(array_keys($parent_folders) as $ext_p) {
            if(strcasecmp($ext_p, $p_cat) == 0) { $p_key = $ext_p; break; }
        }
        if(!isset($parent_folders[$p_key])) { $parent_folders[$p_key] = []; }

        if ($s_cat !== '') {
            $s_exists = false;
            foreach($parent_folders[$p_key] as $ext_s) {
                if(strcasecmp($ext_s, $s_cat) == 0) { $s_exists = true; break; }
            }
            if(!$s_exists) { $parent_folders[$p_key][] = $s_cat; }
        }

        if (!empty($row['assigned_to_role']) && $s_cat !== '') {
            $assigned_roles_array = explode(',', $row['assigned_to_role']);
            foreach ($assigned_roles_array as $r) {
                $r = trim($r);
                if(!isset($role_assigned_folders[$r])) $role_assigned_folders[$r] = [];
                
                $s_exists_role = false;
                foreach($role_assigned_folders[$r] as $ext_sr) {
                    if(strcasecmp($ext_sr, $s_cat) == 0) { $s_exists_role = true; break; }
                }
                if(!$s_exists_role) $role_assigned_folders[$r][] = $s_cat;
            }
        }
    }
}

if ($is_top_mgmt) {
    $user_categories = [];
    foreach ($parent_folders as $subs) {
        foreach($subs as $sub) {
            $s_exists = false;
            foreach($user_categories as $ext_u) {
                if(strcasecmp($ext_u, $sub) == 0) { $s_exists = true; break; }
            }
            if(!$s_exists) $user_categories[] = $sub;
        }
    }
} else {
    $raw_roles = $role_assigned_folders[$role] ?? [];
    $user_categories = [];
    foreach($raw_roles as $sub) {
        $s_exists = false;
        foreach($user_categories as $ext_u) {
            if(strcasecmp($ext_u, $sub) == 0) { $s_exists = true; break; }
        }
        if(!$s_exists) $user_categories[] = $sub;
    }
}

$assigned_parent_folders = $is_top_mgmt ? array_keys($parent_folders) : getAssignedParentFoldersForRole($conn, $role);
$create_parent_options = [];
foreach ($assigned_parent_folders as $assigned_parent) {
    if ($assigned_parent === '') continue;
    $exists = false;
    foreach ($create_parent_options as $existing_parent) {
        if (strcasecmp($existing_parent, $assigned_parent) === 0) { $exists = true; break; }
    }
    if (!$exists) $create_parent_options[] = $assigned_parent;
}
$can_create_folder = ($is_admin || !empty($create_parent_options));

// ==========================================
// PARAMETERS & FILTERS
// ==========================================
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$parent_filter = $_GET['parent'] ?? '';
$doc_status = $_GET['doc_status'] ?? ''; 
$view_filter = $_GET['view_filter'] ?? ''; 
$view_disposition = isset($_GET['disposition']) && $_GET['disposition'] == '1';
$view_archives = isset($_GET['view_archives']) && $_GET['view_archives'] == '1';
$can_show_create_folder = $can_create_folder && !$view_archives && !$view_disposition && empty($type_filter);

if ($is_top_mgmt && empty($parent_filter) && !empty($type_filter)) {
    foreach($parent_folders as $p => $subs) {
        foreach($subs as $s) {
            if(strcasecmp($s, $type_filter) == 0) { $parent_filter = $p; break 2; }
        }
    }
}

// ==========================================
// DYNAMIC UI TITLES & BACK URL
// ==========================================
$page_title = "Official Records";
$page_subtitle = "Automated Departmental File Management";
$show_back_btn = false;
$back_url = "documents.php";

if ($view_disposition) {
    $page_title = "Ready for Disposition";
    $page_subtitle = "These documents have reached the end of their legal retention period.";
    $show_back_btn = true;
} elseif ($view_archives) {
    $page_title = "Archived Official Records";
    $page_subtitle = "Historical and inactive documents. Search or restore if needed.";
    $show_back_btn = true;
} elseif (!empty($type_filter)) {
    $page_title = htmlspecialchars($type_filter);
    $page_subtitle = "Viewing files inside " . htmlspecialchars($type_filter);
    $show_back_btn = true;
    if (!empty($parent_filter)) {
        $back_url = "?parent=" . urlencode($parent_filter);
    }
} elseif (!empty($parent_filter)) {
    $page_title = htmlspecialchars($parent_filter);
    $page_subtitle = "Viewing sub-folders inside " . htmlspecialchars($parent_filter);
    $show_back_btn = true;
}

if (!empty($search)) {
    $page_subtitle .= " (Search Results)";
}

// Build breadcrumbs with parent/child relationships
$breadcrumbs = [];
$breadcrumbs[] = ['label' => 'Official Records', 'url' => 'documents.php', 'active' => empty($view_archives) && empty($view_disposition) && empty($parent_filter) && empty($type_filter)];

if ($view_archives) {
    $breadcrumbs[] = ['label' => 'Archived', 'url' => 'documents.php?archived=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_disposition) {
    $breadcrumbs[] = ['label' => 'Ready for Disposition', 'url' => 'documents.php?disposition=1', 'active' => empty($parent_filter) && empty($type_filter)];
}

if (!empty($parent_filter)) {
    // FIXED BREADCRUMB PARAMETER
    $parent_url = $view_archives ? 'documents.php?archived=1' : 'documents.php';
    $breadcrumbs[] = ['label' => htmlspecialchars($parent_filter), 'url' => $parent_url . ($view_archives ? '&parent=' : '?parent=') . urlencode($parent_filter), 'active' => empty($type_filter)];
}

if (!empty($type_filter)) {
    // Build URL to maintain parent context
    $type_url_params = [];
    if ($view_archives) $type_url_params[] = 'archived=1';
    if (!empty($parent_filter)) $type_url_params[] = 'parent=' . urlencode($parent_filter);
    $type_url_params[] = 'type=' . urlencode($type_filter);
    $type_url = 'documents.php?' . implode('&', $type_url_params);
    $breadcrumbs[] = ['label' => htmlspecialchars($type_filter), 'url' => $type_url, 'active' => true];
}

// If no specific filter is active, first breadcrumb is active
if (empty($view_archives) && empty($view_disposition) && empty($parent_filter) && empty($type_filter)) {
    $breadcrumbs[0]['active'] = true;
}

// ==========================================
// DROPDOWN FILTER LOGIC WITH STRICT OVERRIDE
// ==========================================
$dropdown_items = [];
$current_filter_label = "Filter Options";

if ($is_top_mgmt && empty($parent_filter) && empty($type_filter)) {
    $current_filter_label = !empty($view_filter) ? $view_filter : "All Official Records";
    $dropdown_items[] = ['label' => 'All Official Records', 'url' => 'documents.php', 'active' => empty($view_filter)];
    foreach(array_keys($parent_folders) as $p) {
        $dropdown_items[] = ['label' => $p, 'url' => "?view_filter=" . urlencode($p), 'active' => ($view_filter === $p)];
    }
} elseif ($is_top_mgmt && !empty($parent_filter) && empty($type_filter)) {
    $current_filter_label = !empty($view_filter) ? $view_filter : "All in " . $parent_filter;
    $dropdown_items[] = ['label' => "All in $parent_filter", 'url' => "?parent=" . urlencode($parent_filter), 'active' => empty($view_filter)];
    foreach($parent_folders[$parent_filter] as $sub) {
        $dropdown_items[] = ['label' => $sub, 'url' => "?parent=" . urlencode($parent_filter) . "&view_filter=" . urlencode($sub), 'active' => ($view_filter === $sub)];
    }
} elseif (!$is_top_mgmt && empty($type_filter)) {
    $current_filter_label = !empty($view_filter) ? $view_filter : "All My Assigned Folders";
    $dropdown_items[] = ['label' => 'All My Assigned Folders', 'url' => 'documents.php', 'active' => empty($view_filter)];
    foreach($user_categories as $sub) {
        $dropdown_items[] = ['label' => $sub, 'url' => "?view_filter=" . urlencode($sub), 'active' => ($view_filter === $sub)];
    }
} elseif (!empty($type_filter)) {
    $current_filter_label = !empty($doc_status) ? "Status: " . $doc_status : "All in " . $type_filter;
    $base_url = "?type=" . urlencode($type_filter);
    if (!empty($parent_filter)) $base_url .= "&parent=" . urlencode($parent_filter);
    
    $dropdown_items[] = ['label' => "All in $type_filter", 'url' => $base_url, 'active' => empty($doc_status)];
    
    if ($type_filter === 'Purchase orders') {
        $statuses = ['Pending', 'Collected', 'Rejected'];
        foreach($statuses as $stat) {
            $dropdown_items[] = ['label' => $stat, 'url' => $base_url . "&doc_status=" . urlencode($stat), 'active' => ($doc_status === $stat)];
        }
    }
}

// Preserve archive mode and disposition mode on filters
if ($view_archives) {
    foreach ($dropdown_items as &$item) {
        $separator = (strpos($item['url'], '?') !== false) ? '&' : '?';
        $item['url'] .= $separator . 'view_archives=1';
    }
} elseif ($view_disposition) {
    foreach ($dropdown_items as &$item) {
        $separator = (strpos($item['url'], '?') !== false) ? '&' : '?';
        $item['url'] .= $separator . 'disposition=1';
    }
}

// FORCE FIX: Replace any duplicate Procurement & Logistics to "Technical & Service Records"
$unique_dropdown = [];
$proc_log_found = false;

foreach($dropdown_items as $item) {
    $lbl = strtolower(trim($item['label']));
    if (strpos($lbl, 'procurement') !== false && strpos($lbl, 'logistics') !== false) {
        if (!$proc_log_found) {
            $proc_log_found = true;
            $unique_dropdown[$lbl] = $item;
        } else {
            $item['label'] = 'Technical & Service Records';
            $item['url'] = str_replace(urlencode($item['label']), urlencode('Technical & Service Records'), $item['url']);
            $unique_dropdown['technical & service records'] = $item;
        }
    } else {
        $unique_dropdown[$lbl] = $item;
    }
}
$dropdown_items = array_values($unique_dropdown);

// ==========================================
// DISPOSITION & REGULAR QUERY CONDITIONS
// ==========================================
if ($view_disposition && $is_top_mgmt) {
    $disp_where = ["d.disposition_status = 'Ready for Disposition'"];
    $disp_params = [];
    $disp_types = "";

    if (!empty($search)) {
        $disp_where[] = "(d.file_name LIKE ? OR d.category LIKE ?)";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_types .= "ss";
    }
    
    if (!empty($view_filter)) {
        $disp_where[] = "d.category = ?";
        $disp_params[] = $view_filter;
        $disp_types .= "s";
    }

    $disp_where_clause = implode(" AND ", $disp_where);
    
    $disp_query_sql = "
        SELECT d.*, p.policy_name, p.action_after_retention, u.full_name 
        FROM documents d 
        JOIN retention_policies p ON d.policy_id = p.policy_id 
        LEFT JOIN users u ON d.uploaded_by = u.user_id 
        WHERE $disp_where_clause
        ORDER BY d.uploaded_at ASC
    ";
    
    $disp_stmt = $conn->prepare($disp_query_sql);
    if(!empty($disp_params)) $disp_stmt->bind_param($disp_types, ...$disp_params);
    $disp_stmt->execute();
    $disp_query = $disp_stmt->get_result();
}

$where_conditions = ["d.status = 'Active'"];
$archived_conditions = ["d.status = 'Archived'"];
$params = [];
$types = "";

if (!$is_top_mgmt) {
    if (empty($user_categories)) {
        $where_conditions[] = "1=0"; 
        $archived_conditions[] = "1=0";
    } else {
        $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
        $where_conditions[] = "d.category IN ($placeholders)";
        $archived_conditions[] = "d.category IN ($placeholders)";
        $params = array_merge($params, $user_categories);
        $types .= str_repeat('s', count($user_categories));
    }
}

if (!empty($type_filter)) {
    if ($is_top_mgmt || in_array($type_filter, $user_categories)) {
        $where_conditions[] = "d.category = ?";
        $archived_conditions[] = "d.category = ?";
        $params[] = $type_filter;
        $types .= "s";
    } else {
        $where_conditions[] = "1=0"; 
        $archived_conditions[] = "1=0";
    }
} elseif ($is_top_mgmt && !empty($parent_filter)) {
    $allowed_subs = $parent_folders[$parent_filter] ?? [];
    if (!empty($allowed_subs)) {
        $placeholders = implode(',', array_fill(0, count($allowed_subs), '?'));
        $where_conditions[] = "d.category IN ($placeholders)";
        $archived_conditions[] = "d.category IN ($placeholders)";
        $params = array_merge($params, $allowed_subs);
        $types .= str_repeat('s', count($allowed_subs));
    }
}

if (!empty($doc_status) && $type_filter === 'Purchase orders') {
    if ($doc_status === 'Pending') {
        $where_conditions[] = "p.status IN ('Pending', 'New', 'GM-Approved', 'Finance-Approved', 'President-Approved', 'Funded')";
    } elseif ($doc_status === 'Collected') {
        $where_conditions[] = "p.status IN ('Collected', 'Delivered')";
    } elseif ($doc_status === 'Rejected') {
        $where_conditions[] = "p.status IN ('Rejected', 'Invalid')";
    }
}

if (!empty($search)) {
    $search_condition = "(d.file_name LIKE ? OR p.po_number LIKE ? OR d.tags LIKE ? OR d.category LIKE ?)";
    $where_conditions[] = $search_condition;
    $archived_conditions[] = $search_condition;
    
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

$whereClause = "WHERE " . implode(" AND ", $where_conditions);
$archivedWhereClause = "WHERE " . implode(" AND ", $archived_conditions);

$query_active = "
    SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name, rp.policy_name
    FROM documents d 
    LEFT JOIN purchase_orders p ON d.po_id = p.po_id 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    LEFT JOIN retention_policies rp ON d.policy_id = rp.policy_id
    $whereClause 
    ORDER BY d.uploaded_at DESC LIMIT 100";
$stmt = $conn->prepare($query_active);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$active_docs = $stmt->get_result();

$archivedLimit = $view_archives ? "LIMIT 200" : "LIMIT 50";
$query_archived = "
    SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name 
    FROM documents d 
    LEFT JOIN purchase_orders p ON d.po_id = p.po_id 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    $archivedWhereClause 
    ORDER BY d.uploaded_at DESC $archivedLimit";
$stmt_archived = $conn->prepare($query_archived);
if(!empty($params)) $stmt_archived->bind_param($types, ...$params);
$stmt_archived->execute();
$archived_docs = $stmt_archived->get_result();

$counts_query = $conn->query("SELECT category, COUNT(*) as cnt FROM documents WHERE status = 'Active' GROUP BY category");
$db_counts = [];
while ($r = $counts_query->fetch_assoc()) {
    $db_counts[$r['category']] = $r['cnt'];
}
function getSubFolderCount($sub, $db_counts) { return $db_counts[$sub] ?? 0; }
function getParentFolderCount($parent, $parent_folders, $db_counts) {
    $count = 0;
    foreach ($parent_folders[$parent] as $sub) { $count += ($db_counts[$sub] ?? 0); }
    return $count;
}

$restricted_upload_folders = ['Purchase orders', 'Purchase requests'];
$hide_upload_button = in_array($type_filter, $restricted_upload_folders) || $view_archives || $view_disposition;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Official Records - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .folder-card { border: 1px solid #e2e8f0; border-radius: 10px; transition: all 0.2s ease; background: #fff; cursor: pointer; }
        .folder-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transform: translateY(-2px); }
        .folder-icon-box { width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .file-icon-md { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 1.2rem; }
        .file-thumb-md { width: 40px; height: 40px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0; }
        .clickable-row td { transition: background-color 0.2s ease; vertical-align: middle; }
        .clickable-row:hover td { background-color: #f8fafc !important; cursor: pointer; }
        .sleek-search { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 4px; }
        .sleek-search .form-control { border: none; box-shadow: none; background: transparent; }
        .sleek-search .form-control:focus { box-shadow: none; }
        .sleek-search .input-group-text { border: none; background: transparent; }
        .page-location-path { font-size: 0.9rem; color: #6c757d; letter-spacing: 0.2px; }
        .page-location-path i { font-size: 0.85rem; }
        .breadcrumb-item { display: inline-flex; align-items: center; position: relative; }
        .breadcrumb-item a { 
            color: #0d6efd; 
            text-decoration: none; 
            transition: all 0.2s ease;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            background: transparent;
        }
        .breadcrumb-item a:hover { 
            color: #0b5ed7; 
            background-color: #e7f1ff;
        }
        .breadcrumb-item.active span {
            color: #212529;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            background-color: #e9ecef;
        }
        .breadcrumb-separator { 
            margin: 0 4px; 
            color: #adb5bd;
            font-weight: 300;
        }
        
        /* STICKY TOP PANEL */
        .sticky-header-panel {
            position: sticky; top: 0; z-index: 1020;
            background-color: #f8f9fa; padding: 1.5rem 1rem 1rem 1rem;
            margin: -1.5rem -1rem 1.5rem -1rem; border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03);
        }

        /* STICKY TABLE HEADERS FOR DOCUMENTS LIST */
        .table-scrollable {
            max-height: 65vh;
            overflow-y: auto;
            border-bottom: 1px solid #e2e8f0;
        }
        .table-scrollable table { margin-bottom: 0; }
        .table-scrollable thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa !important;
            z-index: 10;
            box-shadow: inset 0 -1px 0 #e2e8f0, 0 1px 0 #e2e8f0;
        }
        .table-scrollable::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scrollable::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .table-scrollable::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .table-scrollable::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .confirm-modal .modal-dialog { max-width: 420px; }
        .confirm-modal .modal-content { border-radius: 10px; overflow: hidden; }
        .confirm-modal .modal-header { min-height: 42px; padding: 0.75rem 0.9rem 0; }
        .confirm-modal .modal-body { padding: 0.25rem 1.25rem 1rem; }
        .confirm-modal .modal-footer { padding: 0.8rem 1rem; gap: 0.5rem; }
        .confirm-modal .confirm-icon {
            width: 44px; height: 44px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f8fafc; font-size: 1.15rem;
        }
        .confirm-modal h5 { font-size: 1rem; }
        .confirm-modal p { font-size: 0.82rem; line-height: 1.45; }
        .confirm-modal .btn { border-radius: 7px; padding: 0.45rem 0.9rem; font-size: 0.86rem; }
    </style>
</head>
<body style="background-color: #f8f9fa;">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <div class="sticky-header-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-3">
                    <?php if($show_back_btn): ?>
                        <a href="<?php echo $back_url; ?>" class="btn btn-sm btn-white bg-white border shadow-sm" style="border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" title="Back">
                            <i class="fas fa-arrow-left text-secondary"></i>
                        </a>
                    <?php endif; ?>
                    <div>
                        <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">
                            <?php if($view_archives): ?><i class="fas fa-archive text-secondary me-2"></i><?php endif; ?>
                            <?php if($view_disposition): ?><i class="fas fa-trash-alt text-warning me-2"></i><?php endif; ?>
                            <?php echo $page_title; ?>
                        </h3>
                        <p class="text-muted mb-0 small"><?php echo $page_subtitle; ?></p>
                    </div>
                </div>
                
                <div class="d-flex gap-2 align-items-center">
                    <?php if(!empty($user_categories) && !$hide_upload_button): ?>
                    <button class="btn btn-primary fw-medium px-3 text-nowrap shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadModal" style="border-radius: 8px;">
                        <i class="fas fa-upload me-2"></i> Upload Record
                    </button>
                    <?php endif; ?>

                    <div class="dropdown">
                        <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm mt-2">
                            <?php if(in_array($role, ['Admin', 'GM'])): ?>
                            <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#managePoliciesModal"><i class="fas fa-gavel text-secondary"></i> Manage Policies</a></li>
                            <?php endif; ?>

                            <?php if($is_top_mgmt): ?>
                            <?php 
                                $disp_count = 0;
                                $disp_chk = $conn->query("SELECT COUNT(*) as cnt FROM documents WHERE disposition_status = 'Ready for Disposition'");
                                if($disp_chk) $disp_count = $disp_chk->fetch_assoc()['cnt'];
                            ?>
                            <li>
                                <a class="dropdown-item py-2" href="?disposition=1">
                                    <i class="fas fa-exclamation-triangle text-warning"></i> Disposition Alerts
                                    <?php if($disp_count > 0): ?><span class="badge bg-danger ms-2 rounded-pill"><?php echo $disp_count; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php if(!$view_archives): ?>
                                <li><a class="dropdown-item py-2" href="?view_archives=1"><i class="fas fa-archive text-secondary"></i> View Archives</a></li>
                            <?php endif; ?>
                            
                            <?php if($can_show_create_folder): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="fas fa-folder-plus text-info"></i> <?php echo $is_admin ? 'Create Folder' : 'Create Sub-folder'; ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="page-location-path mb-3 d-flex align-items-center gap-1">
                <i class="fas fa-map-marker-alt me-2" style="color: #0d6efd;"></i>
                <?php foreach($breadcrumbs as $index => $crumb): ?>
                    <?php if($index > 0): ?><span class="breadcrumb-separator">›</span><?php endif; ?>
                    <span class="breadcrumb-item <?php echo $crumb['active'] ? 'active' : ''; ?>">
                        <?php if($crumb['active']): ?>
                            <span><?php echo $crumb['label']; ?></span>
                        <?php else: ?>
                            <a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['label']; ?></a>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <?php if(!empty($user_categories) || $view_disposition): ?>
            <div class="sleek-search shadow-sm">
                <form method="GET" class="d-flex w-100 align-items-center m-0">
                    <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                    <?php if($view_disposition): ?><input type="hidden" name="disposition" value="1"><?php endif; ?>
                    <?php if(!empty($parent_filter)): ?><input type="hidden" name="parent" value="<?php echo htmlspecialchars($parent_filter); ?>"><?php endif; ?>
                    <?php if(!empty($type_filter)): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                    <?php if(!empty($doc_status)): ?><input type="hidden" name="doc_status" value="<?php echo htmlspecialchars($doc_status); ?>"><?php endif; ?>
                    
                    <div class="input-group flex-grow-1 align-items-center">
                        <span class="input-group-text text-muted px-3"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control px-2" placeholder="Search filename, tags, PO#..." value="<?php echo htmlspecialchars($search); ?>" style="font-size: 0.9rem;">
                        <?php if(!empty($search)): ?>
                            <a href="documents.php?<?php echo $view_archives ? 'view_archives=1&' : ''; ?><?php echo $view_disposition ? 'disposition=1&' : ''; ?><?php echo !empty($parent_filter) ? 'parent='.urlencode($parent_filter) : ''; ?><?php echo !empty($type_filter) ? '&type='.urlencode($type_filter) : ''; ?>" class="input-group-text text-danger text-decoration-none px-3" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-<?php echo ($view_archives || $view_disposition) ? 'secondary' : 'primary'; ?> px-4 fw-medium" style="border-radius: 6px; font-size: 0.9rem;">Search</button>

                    <div class="border-start mx-3" style="height: 24px;"></div>

                    <div class="dropdown flex-shrink-0 pe-2">
                        <button class="btn btn-light bg-transparent border-0 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" style="font-size: 0.9rem;">
                            <i class="fas fa-filter text-muted"></i>
                            <span class="d-none d-md-inline fw-medium text-secondary text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($current_filter_label); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm mt-2" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach($dropdown_items as $item): ?>
                                <li>
                                    <a class="dropdown-item py-2 small <?php echo $item['active'] ? 'active' : ''; ?>" href="<?php echo $item['url']; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" style="border-radius: 8px;">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 8px;">
                <i class="fas fa-exclamation-circle me-2"></i> Error: <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($view_disposition && $is_top_mgmt): ?>
            
            <div class="card border-0 shadow-sm" style="border-radius: 10px;">
                <div class="table-responsive table-scrollable">
                    <table class="table align-middle w-100">
                        <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary">Document Details</th>
                                <th class="text-secondary">Version</th>
                                <th class="text-secondary">Category</th>
                                <th class="text-secondary">Policy Applied</th>
                                <th class="text-secondary">Action Setup</th>
                                <th class="text-end pe-4 text-secondary">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($disp_query && $disp_query->num_rows > 0): while($row = $disp_query->fetch_assoc()): 
                                $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-bold text-dark d-block" style="font-size: 0.9rem;">
                                            <?php echo htmlspecialchars($row['file_name']); ?>
                                        </span>
                                        <small class="text-muted" style="font-size: 0.75rem;">Uploaded: <?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                    <td><span class="badge bg-info text-dark bg-opacity-10 border border-info px-2"><?php echo htmlspecialchars($row['category'] ?? 'Uncategorized'); ?></span></td>
                                    <td><span class="fw-semibold text-primary small"><?php echo htmlspecialchars($row['policy_name']); ?></span></td>
                                    <td>
                                        <?php if($row['action_after_retention'] == 'Destroy'): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2"><i class="fas fa-fire me-1"></i> Destroy</span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success px-2"><i class="fas fa-archive me-1"></i> Permanent Archive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li><a class="dropdown-item py-2" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history text-info"></i> Version History</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item py-2 fw-medium <?php echo ($row['action_after_retention'] == 'Destroy') ? 'text-danger' : 'text-success'; ?>" href="#" onclick="handleDisposition(<?php echo $row['doc_id']; ?>, '<?php echo $row['action_after_retention']; ?>')">
                                                        <i class="fas <?php echo ($row['action_after_retention'] == 'Destroy') ? 'fa-fire' : 'fa-archive'; ?>"></i> Execute <?php echo $row['action_after_retention']; ?>
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted small">No documents currently require disposition.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view_archives): ?>
            
            <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden;">
                <div class="table-responsive table-scrollable">
                    <table class="table align-middle w-100">
                        <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary">Document Title</th>
                                <th class="text-secondary">Version</th>
                                <th class="text-secondary">Folder / Category</th>
                                <th class="text-secondary">Uploaded By</th>
                                <th class="text-secondary">Archived Date</th>
                                <th class="text-end pe-4 text-secondary">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($archived_docs->num_rows > 0): ?>
                                <?php while($row = $archived_docs->fetch_assoc()): 
                                    $fileNameOnly = basename($row['file_path']);
                                    $secureLink = "download.php?file=" . urlencode($fileNameOnly) . "&doc_id=" . intval($row['doc_id']);
                                    $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                    $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                ?>
                                <tr class="clickable-row border-bottom" onclick="if(!event.target.closest('.dropdown, button, a')){ viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); }">
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                            <span class="fw-semibold text-muted text-break" style="font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($row['file_name']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary px-2"><?php echo htmlspecialchars($row['category'] ?? 'Uncategorized'); ?></span></td>
                                    <td><div class="fw-semibold text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($row['full_name']); ?></div></td>
                                    <td><small class="text-muted" style="font-size: 0.85rem;"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></small></td>
                                    <td class="text-end pe-4">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li><a class="dropdown-item py-2" href="<?php echo $secureLink; ?>" download><i class="fas fa-download text-secondary"></i> Download</a></li>
                                                <li><a class="dropdown-item py-2" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history text-info"></i> Version History</a></li>
                                                <?php if($can_manage): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item py-2 text-success fw-medium" href="#" onclick="showWarningModal('restore', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash-restore"></i> Restore</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted small">No archived records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif(empty($user_categories)): ?>
            <div class="alert alert-warning text-center p-5 shadow-sm" style="border-radius: 10px;">
                <i class="fas fa-lock fa-3x text-warning mb-3"></i>
                <h5 class="fw-bold">No Folders Assigned</h5>
                <p class="mb-0 small">Your current role (<?php echo htmlspecialchars($role); ?>) does not have any assigned document categories here. Contact the administrator if you need access.</p>
            </div>
        <?php else: ?>

            <?php if ($is_top_mgmt && empty($parent_filter) && empty($type_filter) && empty($search)): ?>
                <div class="row g-3">
                    <?php foreach($parent_folders as $p_name => $subs): ?>
                        <?php if(!empty($view_filter) && strcasecmp($view_filter, $p_name) !== 0) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="if(!event.target.closest('.dropdown')){ window.location.href='?parent=<?php echo urlencode($p_name); ?>'; }">
                                
                                <?php if ($role === 'Admin'): ?>
                                <div class="dropdown position-absolute top-0 end-0 mt-2 me-2">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item text-danger small fw-bold py-2" href="#" onclick="event.preventDefault(); openDeleteFolderModal('parent', '<?php echo addslashes($p_name); ?>', '');"><i class="fas fa-trash-alt"></i> Delete Main Folder</a></li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex align-items-center gap-3">
                                    <div class="folder-icon-box bg-warning bg-opacity-10 text-warning">
                                        <i class="fas fa-folder fs-4"></i>
                                    </div>
                                    <div class="text-start text-truncate pe-3">
                                        <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.95rem;"><?php echo htmlspecialchars($p_name); ?></h6>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?php echo getParentFolderCount($p_name, $parent_folders, $db_counts); ?> Files</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($is_top_mgmt && !empty($parent_filter) && empty($type_filter) && empty($search)): ?>
                
                <div class="row g-3">
                    <?php 
                    $sub_folders = $parent_folders[$parent_filter] ?? [];
                    foreach($sub_folders as $sub_name): ?>
                        <?php if(!empty($view_filter) && strcasecmp($view_filter, $sub_name) !== 0) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="if(!event.target.closest('.dropdown')){ window.location.href='?type=<?php echo urlencode($sub_name); ?>&parent=<?php echo urlencode($parent_filter); ?>'; }">
                                
                                <?php if ($can_manage): ?>
                                <div class="dropdown position-absolute top-0 end-0 mt-2 me-2">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item text-danger small fw-bold py-2" href="#" onclick="event.preventDefault(); openDeleteFolderModal('sub', '<?php echo addslashes($parent_filter); ?>', '<?php echo addslashes($sub_name); ?>');"><i class="fas fa-trash-alt"></i> Delete Sub-folder</a></li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex align-items-center gap-3">
                                    <div class="folder-icon-box bg-info bg-opacity-10 text-info">
                                        <i class="fas fa-folder fs-4"></i>
                                    </div>
                                    <div class="text-start text-truncate pe-3">
                                        <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.95rem;"><?php echo htmlspecialchars($sub_name); ?></h6>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?php echo getSubFolderCount($sub_name, $db_counts); ?> Files</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif (!$is_top_mgmt && empty($type_filter) && empty($search)): ?>
                
                <div class="row g-3">
                    <?php foreach($user_categories as $sub_name): ?>
                        <?php if(!empty($view_filter) && strcasecmp($view_filter, $sub_name) !== 0) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="if(!event.target.closest('.dropdown')){ window.location.href='?type=<?php echo urlencode($sub_name); ?>'; }">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="folder-icon-box bg-warning bg-opacity-10 text-warning">
                                        <i class="fas fa-folder fs-4"></i>
                                    </div>
                                    <div class="text-start text-truncate pe-3">
                                        <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.95rem;"><?php echo htmlspecialchars($sub_name); ?></h6>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?php echo getSubFolderCount($sub_name, $db_counts); ?> Files</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden;">
                    <div class="table-responsive table-scrollable">
                        <table class="table align-middle w-100">
                            
                            <?php if($type_filter === 'Purchase orders' || $type_filter === 'Purchase requests'): ?>
                                <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                    <tr>
                                        <th class="ps-4 text-secondary">Document File</th>
                                        <th class="text-secondary">Version</th>
                                        <th class="text-secondary"><?php echo ($type_filter === 'Purchase orders') ? 'PO Details' : 'PR Details'; ?></th>
                                        <th class="text-secondary">Grand Total</th>
                                        <th class="text-secondary">Status</th>
                                        <th class="text-end pe-4 text-secondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($active_docs->num_rows > 0): ?>
                                        <?php while($row = $active_docs->fetch_assoc()): 
                                            $fileNameOnly = basename($row['file_path']);
                                            $secureLink = "download.php?file=" . urlencode($fileNameOnly) . "&doc_id=" . intval($row['doc_id']);
                                            $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                            $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                            $isPdf = ($ext == 'pdf');
                                            $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                            
                                            $po_stat = $row['po_status'] ?? 'No System Link';
                                            $stat_color = 'bg-secondary';
                                            if(in_array($po_stat, ['Pending', 'New'])) $stat_color = 'bg-warning text-dark';
                                            elseif(strpos($po_stat, 'Approved') !== false) $stat_color = 'bg-info text-dark';
                                            elseif($po_stat == 'Funded') $stat_color = 'bg-primary';
                                            elseif(in_array($po_stat, ['Collected', 'Delivered'])) $stat_color = 'bg-success';
                                            elseif(in_array($po_stat, ['Rejected', 'Invalid'])) $stat_color = 'bg-danger';
                                        ?>
                                        <tr class="clickable-row border-bottom" onclick="if(!event.target.closest('.dropdown, a, button')) { viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); }">
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                                    <span class="fw-semibold text-dark text-break" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></span>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                            <td>
                                                <div class="fw-semibold text-primary mb-1" style="font-size: 0.85rem;"><?php echo $row['po_number'] ? '#' . htmlspecialchars($row['po_number']) : 'Direct File Upload'; ?></div>
                                                <small class="text-muted" style="font-size: 0.75rem;"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($row['client_name'] ?? 'General/Internal Record'); ?></small>
                                            </td>
                                            <td class="fw-semibold text-dark" style="font-size: 0.85rem;"><?php echo $row['amount'] ? '₱ ' . number_format($row['amount'], 2) : '-'; ?></td>
                                            <td><span class="badge <?php echo $stat_color; ?> px-2 py-1 bg-opacity-10 border border-<?php echo str_replace(['bg-', ' text-dark'], '', $stat_color); ?>" style="color: inherit !important;"><?php echo htmlspecialchars($po_stat); ?></span></td>
                                            <td class="text-end pe-4">
                                                <div class="dropdown action-dropdown">
                                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <?php if($row['po_id']): ?>
                                                            <li><a class="dropdown-item py-2" href="view_po.php?id=<?php echo $row['po_id']; ?>"><i class="fas fa-file-invoice text-primary"></i> View Details</a></li>
                                                        <?php endif; ?>
                                                        <li><a class="dropdown-item py-2" href="<?php echo $secureLink; ?>" download><i class="fas fa-download text-secondary"></i> Download</a></li>
                                                        <?php if($can_manage): ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item py-2 text-warning" href="#" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive"></i> Archive Document</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center py-5 text-muted small">No records found.</td></tr>
                                    <?php endif; ?>
                                </tbody>

                            <?php else: ?>

                                <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                    <tr>
                                        <th class="ps-4 text-secondary">Document Title</th>
                                        <th class="text-secondary">Version</th>
                                        <th class="text-secondary">Folder / Tags</th>
                                        <th class="text-secondary">Ref/PO</th>
                                        <th class="text-secondary">Uploaded By</th>
                                        <th class="text-end pe-4 text-secondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($active_docs->num_rows > 0): ?>
                                        <?php while($row = $active_docs->fetch_assoc()): 
                                            $fileNameOnly = basename($row['file_path']);
                                            $secureLink = "download.php?file=" . urlencode($fileNameOnly) . "&doc_id=" . intval($row['doc_id']);
                                            $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                            $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                            $isPdf = ($ext == 'pdf');
                                            $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                        ?>
                                        <tr class="clickable-row border-bottom" onclick="if(!event.target.closest('.dropdown, a, button')) { viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); }">
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                                    
                                                    <div>
                                                        <span class="fw-semibold text-dark text-break d-block" style="font-size: 0.9rem;">
                                                            <?php echo htmlspecialchars($row['file_name']); ?>
                                                        </span>
                                                        <?php if($row['disposition_status'] == 'Ready for Disposition'): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning mt-1" style="font-size: 0.7rem;"><i class="fas fa-exclamation-triangle"></i> Pending Disposition</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                            <td>
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info mb-1 px-2"><?php echo htmlspecialchars($row['category'] ?? 'Uncategorized'); ?></span><br>
                                                <?php if(!empty($row['policy_name'])): ?>
                                                    <small class="text-primary fw-semibold d-block" style="font-size: 0.75rem;"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($row['policy_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($row['po_id']): ?><a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="badge bg-light text-primary text-decoration-none border" style="font-size: 0.75rem;">PO #<?php echo $row['po_number']; ?></a><?php else: ?><span class="text-muted" style="font-size: 0.75rem;">General File</span><?php endif; ?>
                                            </td>
                                            <td><div class="fw-semibold text-dark" style="font-size: 0.85rem;"><?php echo htmlspecialchars($row['full_name']); ?></div><small class="text-muted" style="font-size: 0.75rem;"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></small></td>
                                            <td class="text-end pe-4">
                                                <div class="dropdown action-dropdown">
                                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <li><a class="dropdown-item py-2" href="<?php echo $secureLink; ?>" download><i class="fas fa-download text-secondary"></i> Download</a></li>
                                                        <li><a class="dropdown-item py-2" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history text-info"></i> Version History</a></li>
                                                        <?php if($can_manage): ?>
                                                        <li><a class="dropdown-item py-2" href="#" onclick="openVersionModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', '<?php echo $current_v; ?>', event)"><i class="fas fa-upload text-primary"></i> Upload New Version</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item py-2 text-warning" href="#" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive"></i> Archive Document</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center py-5 text-muted small">No records found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            <?php endif; ?>
                            
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="uploadVersionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-file-upload me-2 text-primary"></i> Upload New Version</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/version_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4 bg-white">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="upload_version">
                        <input type="hidden" name="doc_id" id="v_doc_id">
                        <input type="hidden" name="source_page" value="../documents.php">

                        <div class="alert alert-info border-info bg-opacity-10 py-2 px-3 mb-3">
                            <small>Updating Document: <strong id="v_doc_name"></strong></small><br>
                            <small>Current Version: <strong id="v_curr_ver"></strong></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Select New File <span class="text-danger">*</span></label>
                            <input type="file" name="new_document" class="form-control" style="border-radius: 8px;" required>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-secondary">Reason for Update / Remarks <span class="text-danger">*</span></label>
                            <textarea name="remarks" class="form-control" style="border-radius: 8px;" rows="2" placeholder="e.g. Revised contract terms" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-medium px-4" style="border-radius: 8px;">Upload & Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="versionHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-history me-2 text-info"></i> Document Version History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="p-3 bg-white border-bottom">
                        <h6 class="mb-0 fw-bold text-primary" id="h_doc_name">Document Name</h6>
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light sticky-top" style="font-size: 0.75rem; text-transform: uppercase;">
                                <tr>
                                    <th class="ps-4 text-secondary">Ver</th>
                                    <th class="text-secondary">File Details</th>
                                    <th class="text-secondary">Remarks</th>
                                    <th class="text-end pe-4 text-secondary">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary spinner-border-sm"></div> Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(in_array($role, ['Admin', 'GM'])): ?>
    <div class="modal fade" id="managePoliciesModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-gavel me-2 text-primary"></i> Manage Retention Policies</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-white" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                <tr>
                                    <th class="ps-4 py-3 text-secondary">Policy Rule Name</th>
                                    <th class="text-secondary">Retention Period</th>
                                    <th class="text-secondary">Action Setup</th>
                                    <th class="text-end pe-4 text-secondary">Manage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($policies as $pol): ?>
                                <tr class="border-bottom">
                                    <td class="ps-4 fw-semibold text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($pol['policy_name']); ?></td>
                                    <td class="fw-semibold text-primary" style="font-size: 0.9rem;"><?php echo $pol['retention_years']; ?> Years</td>
                                    <td>
                                        <span class="badge bg-<?php echo $pol['action_after_retention'] == 'Destroy' ? 'danger' : 'success'; ?> bg-opacity-10 text-<?php echo $pol['action_after_retention'] == 'Destroy' ? 'danger' : 'success'; ?> border border-<?php echo $pol['action_after_retention'] == 'Destroy' ? 'danger' : 'success'; ?>">
                                            <i class="fas fa-<?php echo $pol['action_after_retention'] == 'Destroy' ? 'fire' : 'archive'; ?> me-1"></i> <?php echo $pol['action_after_retention']; ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-light border text-primary fw-medium px-3" style="border-radius: 6px;" onclick="openEditPolicyModal(<?php echo $pol['policy_id']; ?>, '<?php echo addslashes($pol['policy_name']); ?>', <?php echo $pol['retention_years']; ?>, '<?php echo addslashes($pol['action_after_retention']); ?>')">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPolicyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_policy">
                    <input type="hidden" name="policy_id" id="editPolicyId">
                    
                    <div class="modal-header bg-light border-bottom">
                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-edit me-2 text-primary"></i> Edit Policy Rule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body bg-white p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Policy Name <span class="text-danger">*</span></label>
                            <input type="text" name="policy_name" id="editPolicyName" class="form-control" style="border-radius: 8px;" required>
                        </div>
                        
                        <div class="mb-3 p-3 bg-primary bg-opacity-10 border border-primary rounded" style="border-radius: 8px;">
                            <label class="form-label fw-semibold small text-primary">Retention Period (Years) <span class="text-danger">*</span></label>
                            <input type="number" name="retention_years" id="editPolicyYears" class="form-control" style="border-radius: 8px;" min="0" required>
                            <small class="text-primary mt-2 d-block" style="font-size: 0.75rem;"><i class="fas fa-info-circle"></i> Modifying this will automatically adjust the expiration schedule for all records currently assigned to this policy.</small>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-secondary">Action After Expiration <span class="text-danger">*</span></label>
                            <select name="action_after_retention" id="editPolicyAction" class="form-select" style="border-radius: 8px;" required>
                                <option value="Destroy">Destroy</option>
                                <option value="Permanent Archive">Permanent Archive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-medium px-4" style="border-radius: 8px;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($can_show_create_folder): ?>
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus me-2 text-info"></i> <?php echo $is_admin ? 'Create New Folder' : 'Create Sub-folder'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="documents.php" method="POST">
                    <div class="modal-body p-4 bg-white">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="create_folder">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Parent Department / Record Type</label>
                            <select name="parent_category" class="form-select mb-2" id="parentCategorySelect" style="border-radius: 8px;" onchange="toggleNewParentInput()" required>
                                <option value="" disabled selected>-- Select Parent Folder --</option>
                                <?php foreach($create_parent_options as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                <?php endforeach; ?>
                                <?php if ($is_admin): ?>
                                <option value="NEW_PARENT_FOLDER" class="fw-bold text-primary">+ Create New Parent Folder</option>
                                <?php endif; ?>
                            </select>
                            <input type="text" name="new_parent_category" id="newParentCategoryInput" class="form-control d-none mt-2 border-primary" style="border-radius: 8px;" placeholder="Enter New Parent Folder Name">
                            <?php if(!$is_admin): ?>
                                <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="fas fa-lock me-1"></i>You can only add sub-folders to parent folders assigned to your role.</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">New Sub-Folder Name <small class="text-muted fw-normal" id="subFolderOptionalText">(Required)</small></label>
                            <input type="text" name="new_folder_name" id="newFolderInput" class="form-control" style="border-radius: 8px;" placeholder="e.g. Audit Reports 2026" required>
                        </div>

                        <div class="mb-3 p-3 bg-info bg-opacity-10 border border-info rounded" id="folderPolicyWrapper" style="border-radius: 8px;">
                            <label class="form-label fw-semibold small text-info"><i class="fas fa-shield-alt me-1"></i> Default Retention Policy</label>
                            <select name="folder_policy" id="folderPolicySelect" class="form-select border-info" style="border-radius: 8px;" required>
                                <option value="" disabled selected>-- Select Auto-Retention Rule --</option>
                                <?php foreach($policies as $pol): ?>
                                    <option value="<?php echo $pol['policy_id']; ?>">
                                        <?php echo htmlspecialchars($pol['policy_name']); ?> 
                                        (<?php echo $pol['retention_years']; ?> Yrs -> <?php echo $pol['action_after_retention']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-info mt-2 d-block" style="font-size: 0.75rem;">All files uploaded to this folder will automatically follow this retention rule.</small>
                        </div>
                        
                        <?php if($is_top_mgmt): ?>
                        <div class="mb-2" id="assignedRolesWrapper">
                            <label class="form-label fw-semibold small text-secondary">Assign to User Roles <small class="text-muted fw-normal">(Hold Ctrl/Cmd for multiple)</small></label>
                            <select name="assigned_roles[]" class="form-select" style="border-radius: 8px;" multiple size="6" required>
                                <option value="Admin">Admin</option>
                                <option value="President">President</option>
                                <option value="GM">General Manager</option>
                                <option value="Finance">Finance</option>
                                <option value="Procurement">Procurement</option>
                                <option value="Logistics">Logistics</option>
                                <option value="Sales Staff">Sales Staff</option>
                                <option value="Technical & Service">Technical & Service</option>
                            </select>
                            <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="fas fa-info-circle me-1"></i> Admin, General Manager, and President automatically have access to all folders.</small>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="assigned_roles[]" value="<?php echo htmlspecialchars($role); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info text-white fw-medium px-4" style="border-radius: 8px;"><?php echo $is_admin ? 'Create Folder' : 'Create Sub-folder'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if($is_top_mgmt): ?>
    <div class="modal fade confirm-modal" id="deleteFolderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-sm">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold d-none">Delete Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="confirm-icon text-danger mb-3"><i class="fas fa-folder-minus"></i></div>
                    <h5 class="mb-2 fw-bold text-dark">Are you sure?</h5>
                    <p class="text-muted mb-0">You are about to permanently delete <strong id="deleteFolderDisplay"></strong>. This action cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-end bg-light border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <form action="documents.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="delete_type" id="deleteType">
                        <input type="hidden" name="parent_name" id="deleteParentName">
                        <input type="hidden" name="sub_name" id="deleteSubName">
                        <button type="submit" class="btn btn-danger fw-medium">Delete Folder</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($user_categories)): ?>
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <form action="actions/upload_handler.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="upload">
                    
                    <div class="modal-header bg-light border-bottom">
                        <h5 class="modal-title fw-bold text-dark"><i class="fas fa-upload me-2 text-primary"></i> Upload & Index Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-white">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Document Title / Name <span class="text-danger">*</span></label>
                            <input type="text" name="document_title" class="form-control" style="border-radius: 8px;" placeholder="e.g. Signed Contract Q1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Select File <span class="text-danger">*</span></label>
                            <input type="file" name="document" class="form-control" style="border-radius: 8px;" required>
                        </div>
                        
                        <div class="mb-3 p-3 bg-primary bg-opacity-10 border border-primary rounded" style="border-radius: 8px;">
                            <label class="form-label fw-semibold small text-primary">Folder Assignment <span class="text-danger">*</span></label>
                            <select name="category" class="form-select border-primary" style="border-radius: 8px;" required>
                                <option value="" disabled selected>-- Select Your Folder --</option>
                                <?php 
                                if($is_top_mgmt): ?>
                                    <?php foreach($parent_folders as $p_name => $subs): ?>
                                        <optgroup label="<?php echo htmlspecialchars($p_name); ?>">
                                            <?php foreach($subs as $sub): 
                                                if ($sub === '') continue; 
                                                if(in_array($sub, $restricted_upload_folders)) continue;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($sub); ?>" <?php echo ($type_filter == $sub) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach($user_categories as $c): 
                                        if(in_array($c, $restricted_upload_folders)) continue;
                                    ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($type_filter == $c) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-primary mt-2 d-block" style="font-size: 0.75rem;"><i class="fas fa-magic"></i> The retention policy will be automatically applied based on the folder you select.</small>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-secondary">Keywords / Tags</label>
                            <input type="text" name="tags" class="form-control" style="border-radius: 8px;" placeholder="e.g. 2026, BIR, SupplierX">
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-medium px-4" style="border-radius: 8px;">Save to Repository</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark">File Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0 bg-light" id="previewBody" style="min-height: 400px; display: flex; align-items: center; justify-content: center;"></div>
            </div>
        </div>
    </div>

    <div class="modal fade confirm-modal" id="systemWarningModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-sm">
                <div class="modal-header border-0 pb-0" id="warningModalHeader">
                    <h5 class="modal-title fw-bold" id="warningModalTitle" style="display: none;">Warning</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="confirm-icon mb-3" id="warningModalIconBox"><i id="warningModalIcon" class="fas fa-exclamation-triangle"></i></div>
                    <h5 class="fw-bold text-dark mb-2">Are you sure?</h5>
                    <p id="warningModalMessage" class="mb-0 text-muted small"></p>
                </div>
                <div class="modal-footer justify-content-end bg-light border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <form id="warningModalForm" action="actions/upload_handler.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" id="warningModalAction" value="">
                        <input type="hidden" name="doc_id" id="warningModalDocId" value="">
                        <button type="submit" id="warningModalSubmitBtn" class="btn fw-medium">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // UX Enhancement: Close open dropdowns kapag nag-scroll sa table
        document.querySelectorAll('.table-scrollable').forEach(table => {
            table.addEventListener('scroll', function() {
                var dropdowns = document.querySelectorAll('.table-scrollable .dropdown-toggle[aria-expanded="true"]');
                dropdowns.forEach(function(dropdown) {
                    var inst = bootstrap.Dropdown.getInstance(dropdown);
                    if (inst) inst.hide();
                });
            });
        });

        // Version History Logic
        function openVersionModal(id, name, currentV, event) {
            if(event) event.preventDefault();
            document.getElementById('v_doc_id').value = id;
            document.getElementById('v_doc_name').innerText = name;
            document.getElementById('v_curr_ver').innerText = 'v' + currentV;
            new bootstrap.Modal(document.getElementById('uploadVersionModal')).show();
        }

        function openHistoryModal(id, name, event) {
            if(event) event.preventDefault();
            document.getElementById('h_doc_name').innerText = name;
            const tbody = document.getElementById('historyTableBody');
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary spinner-border-sm"></div> Loading...</td></tr>';
            new bootstrap.Modal(document.getElementById('versionHistoryModal')).show();

            fetch(`actions/version_handler.php?action=get_history&doc_id=${id}`)
                .then(response => response.json())
                .then(res => {
                    if(res.status === 'success') {
                        if(res.data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted small">No previous versions found.</td></tr>';
                        } else {
                            tbody.innerHTML = '';
                            res.data.forEach(v => {
                                tbody.innerHTML += `
                                    <tr>
                                        <td class="ps-4 fw-bold text-primary">v${v.version}</td>
                                        <td class="small">
                                            <span class="text-break d-block">${v.file_name}</span>
                                            <div class="text-muted mt-1" style="font-size:0.7rem;"><i class="fas fa-user-circle me-1"></i>${v.uploaded_by} &bull; ${v.date}</div>
                                        </td>
                                        <td class="small text-muted text-wrap" style="max-width:200px;">${v.remarks}</td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex gap-2 justify-content-end">
                                                <button type="button" class="btn btn-sm btn-link text-info px-1" title="Preview v${v.version}" onclick="previewVersionFile('${v.path}', '${v.file_name}')"><i class="fas fa-eye"></i></button>
                                                <a href="${v.path}" download class="btn btn-sm btn-link text-secondary px-1" title="Download v${v.version}"><i class="fas fa-download"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                `;
                            });
                        }
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger small">Error loading history.</td></tr>';
                    }
                }).catch(err => {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger small">Network error.</td></tr>';
                });
        }

        function openEditPolicyModal(id, name, years, actionStr) {
            document.getElementById('editPolicyId').value = id;
            document.getElementById('editPolicyName').value = name;
            document.getElementById('editPolicyYears').value = years;
            document.getElementById('editPolicyAction').value = actionStr;
            var manageModalEl = document.getElementById('managePoliciesModal');
            var manageModal = bootstrap.Modal.getInstance(manageModalEl);
            if(manageModal) { manageModal.hide(); }
            new bootstrap.Modal(document.getElementById('editPolicyModal')).show();
        }

        function openDeleteFolderModal(type, parentName, subName) {
            document.getElementById('deleteType').value = type;
            document.getElementById('deleteParentName').value = parentName;
            document.getElementById('deleteSubName').value = subName;
            document.getElementById('deleteFolderDisplay').innerText = (type === 'parent') ? parentName : subName;
            new bootstrap.Modal(document.getElementById('deleteFolderModal')).show();
        }

        function toggleNewParentInput() {
            var select = document.getElementById('parentCategorySelect');
            var input = document.getElementById('newParentCategoryInput');
            var subInput = document.getElementById('newFolderInput');
            var optText = document.getElementById('subFolderOptionalText');
            var policySelect = document.getElementById('folderPolicySelect');
            var policyWrapper = document.getElementById('folderPolicyWrapper');
            var rolesWrapper = document.getElementById('assignedRolesWrapper');
            var roleSelect = document.querySelector('#assignedRolesWrapper select');
            if(select.value === 'NEW_PARENT_FOLDER') {
                input.classList.remove('d-none');
                input.required = true;
                subInput.required = false;
                if(policySelect) policySelect.required = false;
                if(roleSelect) roleSelect.required = false;
                if(policyWrapper) policyWrapper.classList.add('d-none');
                if(rolesWrapper) rolesWrapper.classList.add('d-none');
                optText.innerText = "(Optional)";
            } else {
                input.classList.add('d-none');
                input.required = false;
                subInput.required = true;
                if(policySelect) policySelect.required = true;
                if(roleSelect) roleSelect.required = true;
                if(policyWrapper) policyWrapper.classList.remove('d-none');
                if(rolesWrapper) rolesWrapper.classList.remove('d-none');
                optText.innerText = "(Required)";
            }
        }

        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            if (type === 'image') { modalBody.innerHTML = `<img src="${path}" class="img-fluid" style="max-height: 80vh;">`; } 
            else if (type === 'pdf') { modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" style="border:none;"></iframe>`; } 
            else { modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary">Download File</a></div>`; }
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }

        function showWarningModal(action, docId, event) {
            if(event) event.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('systemWarningModal'));
            const icon = document.getElementById('warningModalIcon');
            const message = document.getElementById('warningModalMessage');
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            const form = document.getElementById('warningModalForm');
            form.dataset.mode = 'document';
            form.action = 'actions/upload_handler.php';
            document.getElementById('warningModalAction').value = action;
            document.getElementById('warningModalDocId').value = docId;
            if (action === 'archive') {
                icon.className = 'fas fa-box-archive text-warning'; message.innerText = 'Archive this record? You can restore it later from archives.';
                submitBtn.className = 'btn btn-warning text-dark fw-medium'; submitBtn.innerText = 'Archive';
            } else if (action === 'delete') {
                icon.className = 'fas fa-trash text-danger'; message.innerText = 'Permanently delete this record? This action cannot be undone.';
                submitBtn.className = 'btn btn-danger fw-medium'; submitBtn.innerText = 'Delete';
            } else if (action === 'restore') {
                icon.className = 'fas fa-trash-restore text-success'; message.innerText = 'Restore this record back to active files?';
                submitBtn.className = 'btn btn-success fw-medium'; submitBtn.innerText = 'Restore';
            }
            modal.show();
        }

        function previewVersionFile(path, fileName) {
            const ext = (fileName || '').split('.').pop().toLowerCase();
            const isImage = ['jpg','jpeg','png','gif'].includes(ext);
            const isPdf = ext === 'pdf';
            const type = isImage ? 'image' : (isPdf ? 'pdf' : 'other');
            const histModal = bootstrap.Modal.getInstance(document.getElementById('versionHistoryModal'));
            if (histModal) {
                histModal.hide();
                document.getElementById('versionHistoryModal').addEventListener('hidden.bs.modal', function handler() {
                    viewFile(path, type);
                    this.removeEventListener('hidden.bs.modal', handler);
                });
            } else {
                viewFile(path, type);
            }
        }

        function handleDisposition(docId, actionType) {
            const modal = new bootstrap.Modal(document.getElementById('systemWarningModal'));
            const icon = document.getElementById('warningModalIcon');
            const message = document.getElementById('warningModalMessage');
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            const form = document.getElementById('warningModalForm');

            form.dataset.mode = 'disposition';
            form.dataset.dispositionAction = actionType;
            document.getElementById('warningModalDocId').value = docId;
            document.getElementById('warningModalAction').value = actionType;

            if (actionType === 'Destroy') {
                icon.className = 'fas fa-fire text-danger';
                message.innerText = 'Permanently destroy this record from the server? This action cannot be undone.';
                submitBtn.className = 'btn btn-danger fw-medium';
                submitBtn.innerText = 'Destroy';
            } else {
                icon.className = 'fas fa-archive text-success';
                message.innerText = 'Move this record to permanent archives?';
                submitBtn.className = 'btn btn-success fw-medium';
                submitBtn.innerText = 'Archive';
            }
            modal.show();
        }

        document.getElementById('warningModalForm').addEventListener('submit', function(event) {
            if (this.dataset.mode !== 'disposition') return;

            event.preventDefault();
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerText = 'Processing...';

            $.ajax({
                url: 'actions/disposition_handler.php',
                type: 'POST',
                data: {
                    doc_id: document.getElementById('warningModalDocId').value,
                    action: this.dataset.dispositionAction
                },
                success: function(response) {
                    try {
                        let res = JSON.parse(response);
                        if(res.status === 'success') {
                            location.reload();
                        } else {
                            alert("Error: " + res.message);
                            submitBtn.disabled = false;
                        }
                    } catch(e) {
                        alert("Server error. Please try again.");
                        submitBtn.disabled = false;
                    }
                },
                error: function() {
                    alert("Network error. Please try again.");
                    submitBtn.disabled = false;
                }
            });
        });
    </script>
</body>
</html>
