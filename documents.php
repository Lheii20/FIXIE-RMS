<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ==========================================
// DATA MIGRATION: 1NF NORMALIZATION (Run Once)
// Awtomatikong nililipat ang mga lumang roles sa bagong junction table
// ==========================================
$check_mig = $conn->query("SELECT COUNT(*) as cnt FROM category_role_access");
if ($check_mig && $check_mig->fetch_assoc()['cnt'] == 0) {
    $res = $conn->query("SELECT id, assigned_to_role FROM document_categories WHERE assigned_to_role IS NOT NULL AND assigned_to_role != ''");
    if ($res) {
        $stmt_mig = $conn->prepare("INSERT IGNORE INTO category_role_access (category_id, role_name) VALUES (?, ?)");
        while ($row = $res->fetch_assoc()) {
            $roles = array_map('trim', explode(',', $row['assigned_to_role']));
            foreach ($roles as $r) {
                if (!empty($r)) {
                    $stmt_mig->bind_param("is", $row['id'], $r);
                    $stmt_mig->execute();
                }
            }
        }
    }
}

// ==========================================
// FIX FOR MALFORMED REDIRECT URLS
// ==========================================
function cleanMalformedGetParam($paramName) {
    if (isset($_GET[$paramName])) {
        $val = $_GET[$paramName];
        if (($pos = strpos($val, '?')) !== false) {
            $qs = substr($val, $pos + 1);
            parse_str($qs, $parsed);
            foreach($parsed as $k => $v) {
                $_GET[$k] = $v; 
            }
            $_GET[$paramName] = substr($val, 0, $pos); 
        }
    }
}
cleanMalformedGetParam('type');
cleanMalformedGetParam('parent');
cleanMalformedGetParam('view_filter');
cleanMalformedGetParam('search');

$role = $_SESSION['role'];
$is_admin = has_permission($conn, $_SESSION['user_id'], 'can_manage_users');
$is_top_mgmt = has_permission($conn, $_SESSION['user_id'], 'can_view_all_folders');
$can_manage = has_permission($conn, $_SESSION['user_id'], 'can_manage_folders'); 
$can_view_disposition = has_permission($conn, $_SESSION['user_id'], 'can_view_disposition'); 

// Mabilis na SQL JOIN approach para sa Parent Folders kapalit ng string array check
function getAssignedParentFoldersForRole($conn, $role) {
    $parents = [];
    $stmt = $conn->prepare("
        SELECT dc.parent_category 
        FROM document_categories dc
        JOIN category_role_access cra ON dc.id = cra.category_id
        WHERE cra.role_name = ?
        ORDER BY dc.parent_category ASC
    ");
    if (!$stmt) return $parents;
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $parent = trim($row['parent_category']);
        if ($parent !== '') {
            $exists = false;
            foreach ($parents as $existing) {
                if (strcasecmp($existing, $parent) === 0) { $exists = true; break; }
            }
            if (!$exists) $parents[] = $parent;
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
        if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
            header("Location: documents.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
            exit();
        }
        
        $policy_id = intval($_POST['policy_id']);
        $policy_name = trim($_POST['policy_name']);
        $years = intval($_POST['retention_years']);
        $action_after = $_POST['action_after_retention'];
        
        if ($years < 1) {
            header("Location: documents.php?error=" . urlencode("Retention period must be at least 1 year."));
            exit();
        }
        
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
        
        $roles_to_assign = [];

        if ($is_new_parent) {
            if (!$can_manage) {
                redirectDocumentsWithMessage("error", "You do not have permission to create Parent Folders.");
            }
            $parent = trim($_POST['new_parent_category'] ?? '');
            if ($parent === '') {
                redirectDocumentsWithMessage("error", "Parent Folder name cannot be empty.");
            }
            if (parentFolderExists($conn, $parent)) {
                redirectDocumentsWithMessage("error", "Parent Folder already exists.");
            }
            
            if (!$is_top_mgmt) {
                $roles_to_assign[] = $role;
            } else {
                $roles_to_assign = isset($_POST['assigned_roles']) ? array_map('trim', $_POST['assigned_roles']) : [];
            }
            $sub = '';
            $folder_policy = null;

        } else {
            if ($parent === '') redirectDocumentsWithMessage("error", "Please select a Parent Folder.");
            if (!parentFolderExists($conn, $parent)) redirectDocumentsWithMessage("error", "Selected Parent Folder does not exist.");
            if ($sub === '') redirectDocumentsWithMessage("error", "Sub-folder name is required.", $parent);
            if (subFolderExists($conn, $parent, $sub)) redirectDocumentsWithMessage("error", "Sub-folder already exists in this Parent Folder.", $parent);
            
            if (!$is_top_mgmt) {
                $assigned_parents = getAssignedParentFoldersForRole($conn, $role);
                $is_allowed_parent = false;
                foreach ($assigned_parents as $assigned_parent) {
                    if (strcasecmp($assigned_parent, $parent) === 0) { $is_allowed_parent = true; break; }
                }
                if (!$is_allowed_parent) redirectDocumentsWithMessage("error", "You can only create sub-folders inside your assigned Parent Folders.");
                $roles_to_assign[] = $role;
            } else {
                $roles_to_assign = isset($_POST['assigned_roles']) ? array_map('trim', $_POST['assigned_roles']) : [];
            }
            if ($folder_policy === null) redirectDocumentsWithMessage("error", "Retention Policy is required when creating a sub-folder.", $parent);
        }

        // Mas malinis, hindi na tayo umaasa sa assigned_to_role column na ide-delete na.
        $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, policy_id) VALUES (?, ?, ?)");
        $stmt_create->bind_param("ssi", $parent, $sub, $folder_policy);
        
        if ($stmt_create->execute()) {
            $new_category_id = $stmt_create->insert_id;

            // Ilagay ang multiple roles nang malinis via bagong 1NF table access
            if (!empty($roles_to_assign)) {
                $stmt_role = $conn->prepare("INSERT IGNORE INTO category_role_access (category_id, role_name) VALUES (?, ?)");
                foreach ($roles_to_assign as $r) {
                    if (!empty($r)) {
                        $stmt_role->bind_param("is", $new_category_id, $r);
                        $stmt_role->execute();
                    }
                }
            }

            $action_desc = ($sub === '') ? "Created New Parent Folder: $parent" : "Created Sub-folder: $sub under $parent";
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'CREATE_FOLDER', ?, ?)");
            $ip_addr = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $log_stmt->bind_param("iss", $_SESSION['user_id'], $action_desc, $ip_addr);
            $log_stmt->execute();

            $message = ($sub === '') ? "Parent Folder created successfully." : "Sub-folder created successfully.";
            redirectDocumentsWithMessage("success", $message, ($sub === '' ? '' : $parent));
        }
        redirectDocumentsWithMessage("error", "Failed to create folder.", $parent);
    }

    if ($can_manage) {
        if ($_POST['action'] === 'delete_folder') {
            $delete_type = $_POST['delete_type'];
            $parent_name = $_POST['parent_name'];
            $sub_name = $_POST['sub_name'];
            
            if ($delete_type === 'parent') {
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
                    // Manual cascade trigger iwas Error 1093 sa MySQL
                    $stmt_ids = $conn->prepare("SELECT id FROM document_categories WHERE parent_category = ?");
                    $stmt_ids->bind_param("s", $parent_name);
                    $stmt_ids->execute();
                    $res_ids = $stmt_ids->get_result();
                    $ids_to_delete = [];
                    while ($row_id = $res_ids->fetch_assoc()) {
                        $ids_to_delete[] = $row_id['id'];
                    }

                    if (!empty($ids_to_delete)) {
                        $id_list = implode(',', $ids_to_delete);
                        $conn->query("DELETE FROM category_role_access WHERE category_id IN ($id_list)");
                        
                        $del = $conn->prepare("DELETE FROM document_categories WHERE parent_category = ?");
                        $del->bind_param("s", $parent_name);
                        $del->execute();
                    }

                    $log_desc = "Permanently deleted Main Parent Folder: " . $parent_name;
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'DELETE_FOLDER', ?, ?)");
                    $ip_addr = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $log_stmt->bind_param("iss", $_SESSION['user_id'], $log_desc, $ip_addr);
                    $log_stmt->execute();

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
                    $stmt_ids = $conn->prepare("SELECT id FROM document_categories WHERE sub_category = ? AND parent_category = ?");
                    $stmt_ids->bind_param("ss", $sub_name, $parent_name);
                    $stmt_ids->execute();
                    $res_ids = $stmt_ids->get_result();
                    
                    if ($row_id = $res_ids->fetch_assoc()) {
                        $del_id = $row_id['id'];
                        $conn->query("DELETE FROM category_role_access WHERE category_id = $del_id");
                        
                        $del = $conn->prepare("DELETE FROM document_categories WHERE id = ?");
                        $del->bind_param("i", $del_id);
                        $del->execute();
                    }

                    $log_desc = "Permanently deleted Sub-folder: " . $sub_name . " under " . $parent_name;
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, 'DELETE_FOLDER', ?, ?)");
                    $ip_addr = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $log_stmt->bind_param("iss", $_SESSION['user_id'], $log_desc, $ip_addr);
                    $log_stmt->execute();

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
// STRICT DEDUPLICATION FOLDER FETCHING VIA JOIN
// ==========================================
$parent_folders = [];
$role_assigned_folders = [];
$role_assigned_parents = []; 

$cat_query = $conn->query("
    SELECT dc.id, TRIM(dc.parent_category) as p_cat, TRIM(dc.sub_category) as s_cat, GROUP_CONCAT(cra.role_name) as roles
    FROM document_categories dc
    LEFT JOIN category_role_access cra ON dc.id = cra.category_id
    GROUP BY dc.id
    ORDER BY dc.parent_category ASC, dc.id ASC
");

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

        if (!empty($row['roles'])) {
            $assigned_roles_array = explode(',', $row['roles']);
            foreach ($assigned_roles_array as $r) {
                $r = trim($r);
                if ($s_cat !== '') {
                    if(!isset($role_assigned_folders[$r])) $role_assigned_folders[$r] = [];
                    $s_exists_role = false;
                    foreach($role_assigned_folders[$r] as $ext_sr) {
                        if(strcasecmp($ext_sr, $s_cat) == 0) { $s_exists_role = true; break; }
                    }
                    if(!$s_exists_role) $role_assigned_folders[$r][] = $s_cat;
                } else {
                    if(!isset($role_assigned_parents[$r])) $role_assigned_parents[$r] = [];
                    $p_exists_role = false;
                    foreach($role_assigned_parents[$r] as $ext_pr) {
                        if(strcasecmp($ext_pr, $p_cat) == 0) { $p_exists_role = true; break; }
                    }
                    if(!$p_exists_role) $role_assigned_parents[$r][] = $p_cat;
                }
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

// ==========================================
// SECURE FOLDER DOCUMENT COUNTS (Prevent Metadata Leakage)
// ==========================================
$db_counts = [];
if (!empty($user_categories)) {
    $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
    $count_sql = "SELECT category, COUNT(*) as cnt FROM documents WHERE status = 'Active' AND category IN ($placeholders) GROUP BY category";
    $stmt_counts = $conn->prepare($count_sql);
    
    $count_types = str_repeat('s', count($user_categories));
    $stmt_counts->bind_param($count_types, ...$user_categories);
    $stmt_counts->execute();
    $counts_query = $stmt_counts->get_result();
    
    while ($r = $counts_query->fetch_assoc()) {
        $db_counts[$r['category']] = $r['cnt'];
    }
}

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

if ($view_disposition && !$can_view_disposition) {
    header("Location: documents.php?error=" . urlencode("Unauthorized Access: You do not have permission to view documents ready for disposition."));
    exit();
}

if ($is_top_mgmt && empty($parent_filter) && !empty($type_filter)) {
    foreach($parent_folders as $p => $subs) {
        foreach($subs as $s) {
            if(strcasecmp($s, $type_filter) == 0) { $parent_filter = $p; break 2; }
        }
    }
}

$return_params = [];
if(!empty($type_filter)) $return_params[] = "type=".urlencode($type_filter);
if(!empty($parent_filter)) $return_params[] = "parent=".urlencode($parent_filter);
if($view_archives) $return_params[] = "view_archives=1";
if($view_disposition) $return_params[] = "disposition=1";
$exact_return_url = "../documents.php" . (!empty($return_params) ? "?" . implode("&", $return_params) : "");

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
    if (!empty($parent_filter) && $is_top_mgmt) {
        $back_url = "?parent=" . urlencode($parent_filter);
    }
} elseif (!empty($parent_filter)) {
    $page_title = htmlspecialchars($parent_filter);
    $page_subtitle = "Viewing sub-folders inside " . htmlspecialchars($parent_filter);
    $show_back_btn = true;
}

if (!empty($search)) $page_subtitle .= " (Search Results)";

$breadcrumbs = [];
$breadcrumbs[] = ['label' => 'Official Records', 'url' => 'documents.php', 'active' => empty($view_archives) && empty($view_disposition) && empty($parent_filter) && empty($type_filter)];

if ($view_archives) {
    $breadcrumbs[] = ['label' => 'Archived', 'url' => 'documents.php?view_archives=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_disposition) {
    $breadcrumbs[] = ['label' => 'Ready for Disposition', 'url' => 'documents.php?disposition=1', 'active' => empty($parent_filter) && empty($type_filter)];
}

if (!empty($parent_filter)) {
    $parent_url = $view_archives ? 'documents.php?view_archives=1' : 'documents.php';
    $breadcrumbs[] = ['label' => htmlspecialchars($parent_filter), 'url' => $parent_url . ($view_archives ? '&parent=' : '?parent=') . urlencode($parent_filter), 'active' => empty($type_filter)];
}

if (!empty($type_filter)) {
    $type_url_params = [];
    if ($view_archives) $type_url_params[] = 'view_archives=1';
    if (!empty($parent_filter) && $is_top_mgmt) $type_url_params[] = 'parent=' . urlencode($parent_filter);
    $type_url_params[] = 'type=' . urlencode($type_filter);
    $type_url = 'documents.php?' . implode('&', $type_url_params);
    $breadcrumbs[] = ['label' => htmlspecialchars($type_filter), 'url' => $type_url, 'active' => true];
}

if (empty($view_archives) && empty($view_disposition) && empty($parent_filter) && empty($type_filter)) {
    $breadcrumbs[0]['active'] = true;
}

$hide_upload_button = $view_archives || $view_disposition;
if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents')) {
    $hide_upload_button = true;
}

if ($view_disposition) {
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
        SELECT d.*, p.policy_name, p.action_after_retention, u.full_name,
               DATE_ADD(d.uploaded_at, INTERVAL COALESCE(p.retention_years, 0) YEAR) AS retention_date
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id
        LEFT JOIN users u ON d.uploaded_by = u.user_id
        WHERE $disp_where_clause
        ORDER BY retention_date ASC";
    
    $stmt_disp = $conn->prepare($disp_query_sql);
    if (!empty($disp_params)) $stmt_disp->bind_param($disp_types, ...$disp_params);
    $stmt_disp->execute();
    $disposition_docs = $stmt_disp->get_result();
}

$where = ["d.status = 'Active'"];
$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(d.file_name LIKE ? OR d.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if (!empty($type_filter)) {
    $where[] = "d.category = ?";
    $params[] = $type_filter;
    $types .= "s";
} elseif (!$is_top_mgmt && empty($search)) {
    if (empty($user_categories)) {
        $where[] = "1 = 0"; 
    } else {
        $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
        $where[] = "d.category IN ($placeholders)";
        $params = array_merge($params, $user_categories);
        $types .= str_repeat('s', count($user_categories));
    }
}

if (!empty($doc_status)) {
    if ($doc_status == 'Archived') {
        $where = ["d.status = 'Archived'"];
    } else {
        $where[] = "d.status = ?";
        $params[] = $doc_status;
        $types .= "s";
    }
}

$whereClause = implode(' AND ', $where);
$query = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name 
          FROM documents d
          LEFT JOIN purchase_orders p ON d.po_id = p.po_id
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          WHERE $whereClause 
          ORDER BY d.uploaded_at DESC";

$stmt = $conn->prepare($query);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$documents = $stmt->get_result();

$archivedWhere = ["d.status = 'Archived'"];
if (!empty($search)) {
    $archivedWhere[] = "(d.file_name LIKE ? OR d.category LIKE ?)";
}
if (!empty($type_filter)) {
    $archivedWhere[] = "d.category = ?";
} elseif (!$is_top_mgmt && empty($search)) {
    if (!empty($user_categories)) {
        $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
        $archivedWhere[] = "d.category IN ($placeholders)";
    } else {
        $archivedWhere[] = "1 = 0";
    }
}
$archivedWhereClause = "WHERE " . implode(' AND ', $archivedWhere);
$archivedLimit = empty($search) ? "LIMIT 5" : "";

$query_archived = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name 
                   FROM documents d
                   LEFT JOIN purchase_orders p ON d.po_id = p.po_id
                   LEFT JOIN users u ON d.uploaded_by = u.user_id
                   $archivedWhereClause 
                   ORDER BY d.uploaded_at DESC $archivedLimit";
$stmt_archived = $conn->prepare($query_archived);
if(!empty($params)) $stmt_archived->bind_param($types, ...$params);
$stmt_archived->execute();
$archived_docs = $stmt_archived->get_result();

function getSubFolderCount($sub, $db_counts) {
    return $db_counts[$sub] ?? 0;
}
function getParentFolderCount($parent, $parent_folders, $db_counts) {
    $count = 0;
    foreach ($parent_folders[$parent] as $sub) {
        $count += ($db_counts[$sub] ?? 0);
    }
    return $count;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Official Records - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .folder-card { border: 1px solid #eef2f6; border-radius: 12px; transition: all 0.2s ease; background: #fff; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .folder-card:hover { border-color: #cbd5e1; box-shadow: 0 8px 12px -3px rgba(0,0,0,0.08); transform: translateY(-3px); }
        .folder-icon-box { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .file-icon-md { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 1.25rem; }
        .file-thumb-md { width: 42px; height: 42px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0; }
        .clickable-row td { transition: background-color 0.2s ease; vertical-align: middle; }
        .clickable-row:hover td { background-color: #f8fafc !important; cursor: pointer; }
        .sleek-search { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 4px; }
        .sleek-search .form-control { border: none; box-shadow: none; background: transparent; }
        .sleek-search .form-control:focus { box-shadow: none; }
        .sleek-search .input-group-text { border: none; background: transparent; }
        .page-location-path { font-size: 0.9rem; color: #64748b; font-weight: 500;}
        .breadcrumb-item a { color: #0d6efd; text-decoration: none; padding: 6px 12px; border-radius: 8px; transition: all 0.2s ease; font-weight: 600;}
        .breadcrumb-item a:hover { background-color: #eff6ff; color: #0b5ed7; }
        .breadcrumb-item.active span { color: #1e293b; font-weight: 700; padding: 6px 12px; border-radius: 8px; background-color: #f1f5f9; }
        .breadcrumb-separator { margin: 0 4px; color: #cbd5e1; }
        .sticky-header-panel { position: sticky; top: 0; z-index: 1020; background-color: #f8f9fa; padding: 1.5rem 1rem 1rem 1rem; margin: -1.5rem -1rem 1.5rem -1rem; border-bottom: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03); }
        .table-scrollable { max-height: 65vh; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; }
        .table-scrollable table { margin-bottom: 0; }
        .table-scrollable thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: inset 0 -1px 0 #e2e8f0, 0 1px 0 #e2e8f0; }
        .table-scrollable::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-scrollable::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .table-scrollable::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .table-scrollable::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .btn-dots { background: transparent; border: 1px solid transparent; color: #64748b; width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .btn-dots:hover { background: #f1f5f9; color: #0f172a; border-color: #e2e8f0; }
        .action-dropdown .dropdown-menu { border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 10px; padding: 0.5rem; }
        .action-dropdown .dropdown-item, .action-dropdown span.dropdown-item { padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.88rem; font-weight: 500; color: #334155; display: flex; align-items: center; gap: 10px; }
        .action-dropdown a.dropdown-item:hover { background-color: #f8fafc; color: #0f172a; }
        .action-dropdown .dropdown-item i { width: 16px; text-align: center; }
        .sleek-popup { border-radius: 12px !important; }
        .sleek-btn { padding: 0.4rem 1.2rem !important; font-size: 0.9rem !important; border-radius: 6px !important; }
    </style>
</head>
<body style="background-color: #f8f9fa;">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="sticky-header-panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-3">
                <?php if($show_back_btn): ?>
                    <a href="<?php echo $back_url; ?>" class="btn btn-sm btn-white bg-white border shadow-sm" style="border-radius: 10px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;" title="Back">
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
                <?php if ($can_manage && empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm" data-bs-toggle="modal" data-bs-target="#createParentFolderModal" style="border-radius: 8px;">
                        <i class="fas fa-folder-plus me-2"></i> Create Parent Folder
                    </button>
                <?php elseif (!empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && $can_manage): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm" data-bs-toggle="modal" data-bs-target="#createSubFolderModal" style="border-radius: 8px;">
                        <i class="fas fa-folder-plus me-2"></i> Create Sub-folder
                    </button>
                <?php elseif (!empty($type_filter) && !$hide_upload_button): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadModal" style="border-radius: 8px;">
                        <i class="fas fa-upload me-2"></i> Upload File
                    </button>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn-dots border bg-white shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: auto; padding: 0 15px;">
                        <i class="fas fa-cog text-secondary me-2"></i> Options
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 10px;">
                        <li>
                            <a class="dropdown-item fw-medium py-2 <?php echo $view_archives ? 'active text-primary' : ''; ?>" href="documents.php?view_archives=1">
                                <i class="fas fa-archive text-secondary me-2"></i> View Archives
                            </a>
                        </li>
                        
                        <?php if ($can_view_disposition): ?>
                        <li>
                            <a class="dropdown-item fw-medium py-2 <?php echo $view_disposition ? 'active text-warning' : ''; ?>" href="documents.php?disposition=1">
                                <i class="fas fa-trash-alt text-warning me-2"></i> Ready for Disposition
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item fw-medium py-2" href="#" data-bs-toggle="modal" data-bs-target="#editPoliciesModal">
                                <i class="fas fa-balance-scale text-primary me-2"></i> Edit Retention Policies
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 page-location-path align-items-center">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <li class="breadcrumb-item <?php echo $crumb['active'] ? 'active' : ''; ?>">
                        <?php if ($crumb['active']): ?>
                            <span><?php echo $crumb['label']; ?></span>
                        <?php else: ?>
                            <a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['label']; ?></a>
                            <span class="breadcrumb-separator"><i class="fas fa-chevron-right small"></i></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>

    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success bg-white border-success border-start border-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="fas fa-check-circle text-success fs-4 me-3"></i>
            <div>
                <strong class="d-block text-success">Success!</strong>
                <span class="text-muted small"><?php echo htmlspecialchars($_GET['success']); ?></span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger bg-white border-danger border-start border-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="fas fa-exclamation-triangle text-danger fs-4 me-3"></i>
            <div>
                <strong class="d-block text-danger">Action Failed</strong>
                <span class="text-muted small"><?php echo htmlspecialchars($_GET['error']); ?></span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$view_archives && !$view_disposition && empty($search)): ?>
        
        <?php if (empty($parent_filter) && empty($type_filter)): ?>
            <div class="row g-3 mb-4">
                <?php 
                $visible_parents = 0;
                foreach ($parent_folders as $p => $subs): 
                    $can_view_this = $is_top_mgmt;
                    if (!$can_view_this) {
                        foreach ($subs as $s) {
                            if (in_array($s, $user_categories)) { $can_view_this = true; break; }
                        }
                        if (!$can_view_this && isset($role_assigned_parents[$role])) {
                            if (in_array($p, $role_assigned_parents[$role])) { $can_view_this = true; }
                        }
                    }
                    if (!$can_view_this) continue;
                    $visible_parents++;
                    $fileCount = getParentFolderCount($p, $parent_folders, $db_counts);
                ?>
                <div class="col-md-4 col-sm-6">
                    <div class="folder-card p-3 h-100 position-relative" onclick="window.location='documents.php?parent=<?php echo urlencode($p); ?>'">
                        <div class="d-flex align-items-center">
                            <div class="folder-icon-box bg-light text-primary border">
                                <i class="fas fa-folder fa-lg"></i>
                            </div>
                            <div class="ms-3 flex-grow-1">
                                <h6 class="mb-1 fw-bold text-dark text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($p); ?></h6>
                                <p class="text-muted small mb-0"><i class="fas fa-file-alt me-1"></i><?php echo $fileCount; ?> active files</p>
                            </div>
                        </div>
                        <?php if($can_manage): ?>
                        <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-3" onclick="event.stopPropagation();">
                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <form action="documents.php" method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete this Main Folder? ALL Sub-folders inside it must be completely empty first.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="delete_type" value="parent">
                                        <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($p); ?>">
                                        <button type="submit" class="dropdown-item text-danger fw-bold"><i class="fas fa-trash-alt text-danger"></i> Delete Main Folder</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($visible_parents == 0): ?>
                    <div class="col-12 text-center py-5 bg-white border rounded-4 shadow-sm">
                        <div class="mb-3"><i class="fas fa-folder-open text-muted" style="font-size: 4rem; opacity: 0.5;"></i></div>
                        <h5 class="text-dark fw-bold">No Folders Assigned</h5>
                        <p class="text-muted mb-0">You currently do not have access to any document folders.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($parent_filter) && empty($type_filter) && isset($parent_folders[$parent_filter])): ?>
            <div class="row g-3 mb-4">
                <?php 
                $subs = $parent_folders[$parent_filter];
                $visible_subs = 0;
                foreach ($subs as $s): 
                    if (!$is_top_mgmt && !in_array($s, $user_categories)) continue;
                    $visible_subs++;
                    $fileCount = getSubFolderCount($s, $db_counts);
                ?>
                <div class="col-md-3 col-sm-6">
                    <div class="folder-card p-3 h-100 position-relative" onclick="window.location='documents.php?parent=<?php echo urlencode($parent_filter); ?>&type=<?php echo urlencode($s); ?>'">
                        <div class="d-flex align-items-center mb-2 pe-4">
                            <div class="folder-icon-box bg-primary bg-opacity-10 text-primary me-3 border border-primary border-opacity-25" style="width: 40px; height: 40px;">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <h6 class="mb-0 fw-bold text-dark text-truncate flex-grow-1" style="font-size: 0.95rem;"><?php echo htmlspecialchars($s); ?></h6>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-light">
                            <span class="text-muted small fw-medium"><?php echo $fileCount; ?> items</span>
                            <i class="fas fa-chevron-right text-primary opacity-50 small"></i>
                        </div>
                        
                        <?php if($can_manage): ?>
                        <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-2" onclick="event.stopPropagation();">
                            <button class="btn-dots bg-transparent border-0 dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v small"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width: 200px;">
                                <li>
                                    <form action="documents.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this sub-folder? It must be empty.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="delete_type" value="sub">
                                        <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($parent_filter); ?>">
                                        <input type="hidden" name="sub_name" value="<?php echo htmlspecialchars($s); ?>">
                                        <button type="submit" class="dropdown-item text-danger fw-bold"><i class="fas fa-trash-alt text-danger"></i> Delete Folder</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($visible_subs == 0): ?>
                    <div class="col-12 text-center py-5 bg-white border rounded-4 shadow-sm">
                        <div class="mb-3"><i class="fas fa-folder text-muted" style="font-size: 3rem; opacity: 0.5;"></i></div>
                        <h6 class="text-dark fw-bold">Empty Parent Folder</h6>
                        <p class="text-muted mb-0 small">There are no sub-folders available here.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    <?php endif; ?>

    <?php if ($view_disposition || (!empty($type_filter)) || $view_archives || !empty($search)): ?>
        
        <?php if($view_disposition): ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-exclamation-triangle text-warning me-2"></i> Documents Awaiting Disposition</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive table-scrollable">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Document Details</th>
                                    <th>Required Action</th>
                                    <th>Retention Date</th>
                                    <th class="text-end pe-4">Options</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($disposition_docs->num_rows > 0): while($doc = $disposition_docs->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <div class="file-icon-md bg-light text-primary me-3 border">
                                                <i class="fas fa-file-alt"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1 text-dark fw-bold"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                                <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning text-dark border border-warning px-2 py-1">
                                            <?php echo htmlspecialchars($doc['action_after_retention'] ?? 'Review required'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-danger fw-bold"><i class="fas fa-clock me-1"></i> <?php echo date('M d, Y', strtotime($doc['retention_date'])); ?></div>
                                        <div class="text-muted small">Expired</div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-light border text-primary" title="Review File">
                                                <i class="fas fa-eye"></i> Review
                                            </a>
                                            
                                            <?php if (has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                            <form action="actions/upload_handler.php" method="POST" class="d-inline" onsubmit="return confirm('Confirm Archiving of this document?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                <input type="hidden" name="source" value="disposition">
                                                <button type="submit" class="btn btn-sm btn-secondary text-white fw-medium shadow-sm" title="Archive">
                                                    <i class="fas fa-archive me-1"></i> Archive
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if (has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')): ?>
                                            <form action="actions/upload_handler.php" method="POST" class="d-inline" onsubmit="return confirm('PERMANENT DELETION: Are you absolutely sure? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                <input type="hidden" name="source" value="disposition">
                                                <button type="submit" class="btn btn-sm btn-danger text-white fw-medium shadow-sm" title="Destroy File">
                                                    <i class="fas fa-fire me-1"></i> Destroy
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fas fa-check-circle fs-3 text-success mb-2 d-block"></i> No documents currently require disposition.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark">
                        <?php echo $view_archives ? '<i class="fas fa-archive text-secondary me-2"></i> Archived Files' : '<i class="fas fa-file-alt text-primary me-2"></i> Document List'; ?>
                    </h5>
                    
                    <form method="GET" action="documents.php" class="d-flex" style="width: 300px;">
                        <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                        <?php if(!empty($parent_filter)): ?><input type="hidden" name="parent" value="<?php echo htmlspecialchars($parent_filter); ?>"><?php endif; ?>
                        <?php if(!empty($type_filter)): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                        
                        <div class="input-group sleek-search w-100">
                            <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Search files..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </form>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive table-scrollable">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">File Name</th>
                                    <th>Link / Reference</th>
                                    <th>Uploaded By</th>
                                    <th>Date Added</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($documents->num_rows > 0): while($doc = $documents->fetch_assoc()): 
                                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                    $is_img = in_array($ext, ['jpg','jpeg','png','gif']);
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <?php if($is_img): ?>
                                                <img src="<?php echo htmlspecialchars($doc['file_path']); ?>" alt="thumb" class="file-thumb-md me-3 shadow-sm">
                                            <?php else: ?>
                                                <div class="file-icon-md bg-light text-primary me-3 border">
                                                    <?php 
                                                        if($ext == 'pdf') echo '<i class="fas fa-file-pdf text-danger"></i>';
                                                        elseif(in_array($ext, ['doc','docx'])) echo '<i class="fas fa-file-word text-primary"></i>';
                                                        elseif(in_array($ext, ['xls','xlsx'])) echo '<i class="fas fa-file-excel text-success"></i>';
                                                        else echo '<i class="fas fa-file"></i>';
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h6 class="mb-1 text-dark fw-bold text-wrap" style="max-width: 300px;"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                                <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($doc['po_id']): ?>
                                            <a href="view_po.php?id=<?php echo $doc['po_id']; ?>" class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle text-decoration-none px-2 py-1">
                                                <i class="fas fa-link me-1"></i> <?php echo htmlspecialchars($doc['po_number']); ?>
                                            </a>
                                            <div class="text-muted small mt-1"><?php echo htmlspecialchars($doc['client_name']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small border px-2 py-1 rounded-2 bg-light">Independent File</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-dark"><?php echo htmlspecialchars($doc['full_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-dark fw-medium"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></div>
                                        <div class="text-muted small"><?php echo date('h:i A', strtotime($doc['uploaded_at'])); ?></div>
                                    </td>
                                    <td class="text-end pe-4 position-relative">
                                        <div class="action-dropdown dropdown">
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <a class="dropdown-item fw-medium text-primary" href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank">
                                                        <i class="fas fa-external-link-alt text-primary"></i> Open / View
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item fw-medium text-success" href="<?php echo htmlspecialchars($doc['file_path']); ?>" download>
                                                        <i class="fas fa-download text-success"></i> Download
                                                    </a>
                                                </li>
                                                
                                                <?php if($view_archives && has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="actions/upload_handler.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="restore">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success fw-bold"><i class="fas fa-undo-alt text-success"></i> Restore Active</button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <?php if(!$view_archives && has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Archive this document?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <button type="submit" class="dropdown-item text-warning fw-bold"><i class="fas fa-archive text-warning"></i> Archive File</button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>

                                                <?php if(has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')): ?>
                                                <li>
                                                    <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger fw-bold"><i class="fas fa-trash-alt text-danger"></i> Permanent Delete</button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-folder-open fs-3 mb-2 d-block opacity-50"></i> No files found in this category.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if(!$view_archives && $archived_docs->num_rows > 0 && empty($search)): ?>
            <div class="mt-4 pt-3 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-archive me-2"></i> Recently Archived in this Category</h6>
                    <a href="documents.php?view_archives=1<?php echo (!empty($parent_filter) ? '&parent='.urlencode($parent_filter) : ''); ?><?php echo (!empty($type_filter) ? '&type='.urlencode($type_filter) : ''); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">View All Archives</a>
                </div>
                <div class="row g-2">
                    <?php while($arch = $archived_docs->fetch_assoc()): ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="card border shadow-sm h-100 rounded-3" style="background-color: #f8fafc;">
                            <div class="card-body p-3 d-flex align-items-center">
                                <i class="fas fa-file-alt text-secondary fs-4 me-3"></i>
                                <div style="min-width: 0;">
                                    <h6 class="mb-0 text-truncate text-secondary fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($arch['file_name']); ?></h6>
                                    <small class="text-muted text-truncate d-block"><?php echo date('M d, Y', strtotime($arch['uploaded_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!empty($type_filter) && !$hide_upload_button): ?>
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-cloud-upload-alt text-primary me-2"></i> Upload File</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="actions/upload_handler.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($type_filter); ?>">
                    <input type="hidden" name="source" value="<?php echo htmlspecialchars($exact_return_url); ?>">
                    
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Target Folder</label>
                        <input type="text" class="form-control form-control-lg bg-light fw-bold text-primary" value="<?php echo htmlspecialchars($type_filter); ?>" readonly>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Select File</label>
                        <input class="form-control form-control-lg bg-light" type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        <div class="form-text mt-2"><i class="fas fa-info-circle text-primary"></i> Max size: 50MB. Formats: PDF, Word, Excel, Images.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light sleek-btn border px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn px-4 fw-medium"><i class="fas fa-upload me-2"></i> Secure Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_manage && empty($parent_filter) && empty($type_filter)): ?>
<div class="modal fade" id="createParentFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus text-primary me-2"></i> Create Main Folder</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="hidden" name="parent_category" value="NEW_PARENT_FOLDER">
                    <input type="hidden" name="new_folder_name" value=""> <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Main Folder Name</label>
                        <input type="text" name="new_parent_category" class="form-control form-control-lg bg-light" required placeholder="e.g. Human Resources">
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light sleek-btn border px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn px-4 fw-medium"><i class="fas fa-check me-2"></i> Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($parent_filter) && empty($type_filter) && $can_manage): ?>
<div class="modal fade" id="createSubFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus text-primary me-2"></i> Create Sub-folder</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php?parent=<?php echo urlencode($parent_filter); ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="hidden" name="parent_category" value="<?php echo htmlspecialchars($parent_filter); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Inside Main Folder</label>
                        <input type="text" class="form-control form-control-lg bg-light fw-bold" value="<?php echo htmlspecialchars($parent_filter); ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Sub-folder Name</label>
                        <input type="text" name="new_folder_name" class="form-control form-control-lg bg-light" required placeholder="e.g. Employee Records">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase">Retention Policy Rule</label>
                        <select name="folder_policy" class="form-select form-select-lg bg-light" required>
                            <option value="">-- Select Rule --</option>
                            <?php foreach($policies as $p): ?>
                                <option value="<?php echo $p['policy_id']; ?>"><?php echo htmlspecialchars($p['policy_name']); ?> (<?php echo $p['retention_years']; ?> Years -> <?php echo $p['action_after_retention']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if($is_top_mgmt): ?>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase d-block">Department Access (Visibility)</label>
                        <div class="row g-2 px-1">
                            <?php 
                            $rolesList = ['Finance', 'Sales', 'Admin', 'President', 'GM', 'Auditor'];
                            foreach($rolesList as $rl): 
                                $chk = in_array($rl, ['Admin','President','GM']) ? 'checked disabled' : '';
                            ?>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input border-secondary" type="checkbox" name="assigned_roles[]" value="<?php echo $rl; ?>" id="role_<?php echo $rl; ?>" <?php echo $chk; ?>>
                                    <label class="form-check-label fw-medium" for="role_<?php echo $rl; ?>"><?php echo $rl; ?></label>
                                    <?php if(in_array($rl, ['Admin','President','GM'])): ?>
                                        <input type="hidden" name="assigned_roles[]" value="<?php echo $rl; ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light sleek-btn border px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn px-4 fw-medium"><i class="fas fa-check me-2"></i> Create Folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
<div class="modal fade" id="editPoliciesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content sleek-popup border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-balance-scale text-primary me-2"></i> Retention Policies Dictionary</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3 pb-4">
                <div class="accordion" id="policiesAccordion">
                    <?php foreach($policies as $idx => $pol): ?>
                    <div class="accordion-item border mb-2 rounded-3 overflow-hidden bg-white">
                        <h2 class="accordion-header">
                            <button class="accordion-button bg-light fw-bold text-dark <?php echo $idx !== 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePol<?php echo $pol['policy_id']; ?>">
                                <?php echo htmlspecialchars($pol['policy_name']); ?>
                            </button>
                        </h2>
                        <div id="collapsePol<?php echo $pol['policy_id']; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#policiesAccordion">
                            <div class="accordion-body">
                                <form action="documents.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="edit_policy">
                                    <input type="hidden" name="policy_id" value="<?php echo $pol['policy_id']; ?>">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label small fw-bold text-muted text-uppercase">Policy Identifier Name</label>
                                            <input type="text" name="policy_name" class="form-control bg-light fw-medium" value="<?php echo htmlspecialchars($pol['policy_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted text-uppercase">Holding Period (Years)</label>
                                            <div class="input-group">
                                                <input type="number" name="retention_years" class="form-control bg-light fw-bold text-danger" value="<?php echo $pol['retention_years']; ?>" min="1" required>
                                                <span class="input-group-text bg-light text-muted">Years</span>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted text-uppercase">After Retention Action</label>
                                            <select name="action_after_retention" class="form-select bg-light fw-medium" required>
                                                <option value="Archive" <?php echo $pol['action_after_retention'] == 'Archive' ? 'selected' : ''; ?>>Auto-Archive</option>
                                                <option value="Delete" <?php echo $pol['action_after_retention'] == 'Delete' ? 'selected' : ''; ?>>Auto-Delete</option>
                                                <option value="Review" <?php echo $pol['action_after_retention'] == 'Review' ? 'selected' : ''; ?>>Flag for Review</option>
                                            </select>
                                        </div>
                                        <div class="col-12 mt-3 text-end">
                                            <button type="submit" class="btn btn-sm btn-primary px-3 py-2 fw-medium"><i class="fas fa-save me-1"></i> Update Policy Settings</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>