<?php
require 'config/db_connect.php';
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ==========================================
// AUTO-MIGRATION: CONCURRENCY & GDRIVE-STYLE SHARING
// ==========================================
$check_lock = $conn->query("SHOW COLUMNS FROM documents LIKE 'is_locked'");
if ($check_lock && $check_lock->num_rows == 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN is_locked TINYINT(1) DEFAULT 0, ADD COLUMN locked_by INT DEFAULT NULL, ADD COLUMN locked_at DATETIME DEFAULT NULL");
}

$check_share = $conn->query("SHOW COLUMNS FROM documents LIKE 'access_type'");
if ($check_share && $check_share->num_rows == 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN access_type VARCHAR(50) DEFAULT 'Folder Default', ADD COLUMN file_permissions JSON DEFAULT NULL");
}

// ==========================================
// DATA MIGRATION: 1NF NORMALIZATION (Run Once)
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
// URL PARAMETER CLEANUP
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
$is_system_admin = ($role === 'Admin');
$is_admin = has_permission($conn, $_SESSION['user_id'], 'can_manage_users');
$is_top_mgmt = has_permission($conn, $_SESSION['user_id'], 'can_view_all_folders');
$can_manage = has_permission($conn, $_SESSION['user_id'], 'can_manage_folders'); 
$can_view_disposition = has_permission($conn, $_SESSION['user_id'], 'can_view_disposition'); 

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

// Fetch all standard users for the Share Modal.
$all_users = [];
$u_query = $conn->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' AND role NOT IN ('Admin', 'GM', 'President') ORDER BY full_name ASC");
if ($u_query) {
    while($u = $u_query->fetch_assoc()) {
        $all_users[] = $u;
    }
}

// ==========================================
// FORM HANDLER: GDRIVE SHARING, CHECK-IN/OUT, FOLDERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }

    if ($_POST['action'] === 'share_document') {
        $doc_id = intval($_POST['doc_id']);
        $access_type = ($_POST['access_type'] === 'Restricted') ? 'Restricted' : 'Folder Default';
        $return_url = $_POST['return_url'] ?? 'documents.php';

        $perms = [];
        if (isset($_POST['user_roles']) && is_array($_POST['user_roles'])) {
            foreach ($_POST['user_roles'] as $uid => $urole) {
                if (in_array($urole, ['Viewer', 'Editor'])) {
                    $perms["user_" . intval($uid)] = $urole;
                }
            }
        }
        $perms_json = json_encode($perms);

        $stmt_chk = $conn->prepare("SELECT uploaded_by, file_name FROM documents WHERE doc_id = ?");
        $stmt_chk->bind_param("i", $doc_id);
        $stmt_chk->execute();
        $d_chk = $stmt_chk->get_result()->fetch_assoc();

        if ($d_chk['uploaded_by'] != $_SESSION['user_id'] && !$is_top_mgmt) {
            redirectDocumentsWithMessage("error", "Only the Owner or Management can change sharing settings.");
        }

        $stmt_upd = $conn->prepare("UPDATE documents SET access_type = ?, file_permissions = ? WHERE doc_id = ?");
        $stmt_upd->bind_param("ssi", $access_type, $perms_json, $doc_id);
        $stmt_upd->execute();

        if (function_exists('log_audit_action')) {
            log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_DOCUMENT', "Updated sharing settings for Document: " . $d_chk['file_name']);
        }
        header("Location: " . $return_url);
        exit();
    }

    if ($_POST['action'] === 'toggle_lock') {
        $doc_id = intval($_POST['doc_id']);
        $current_state = intval($_POST['current_state']); 
        $target_state = $current_state ? 0 : 1;
        $return_url = $_POST['return_url'] ?? 'documents.php';
        
        $stmt = $conn->prepare("SELECT is_locked, locked_by, file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $doc_info = $stmt->get_result()->fetch_assoc();
        
        if ($target_state == 1) {
            if ($doc_info['is_locked']) {
                redirectDocumentsWithMessage("error", "File is already locked by someone else.");
            }
            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_locked = 1, locked_by = ?, locked_at = NOW() WHERE doc_id = ?");
            $upd->bind_param("ii", $uid, $doc_id);
            $upd->execute();
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'CHECK_OUT', "Checked out (Locked) Document: " . $doc_info['file_name']);
            header("Location: " . $return_url);
            exit();
        } else { 
            $uid = $_SESSION['user_id'];
            if ($doc_info['locked_by'] != $uid && !$is_top_mgmt) {
                 redirectDocumentsWithMessage("error", "Only the user who locked the file or Management can unlock it.");
            }
            $upd = $conn->prepare("UPDATE documents SET is_locked = 0, locked_by = NULL, locked_at = NULL WHERE doc_id = ?");
            $upd->bind_param("i", $doc_id);
            $upd->execute();
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'CHECK_IN', "Checked in (Unlocked) Document: " . $doc_info['file_name']);
            header("Location: " . $return_url);
            exit();
        }
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
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_POLICY', "Updated Policy ID: $policy_id to $years Years ($action_after).");
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
            if (!$can_manage) redirectDocumentsWithMessage("error", "You do not have permission to create Parent Folders.");
            $parent = trim($_POST['new_parent_category'] ?? '');
            if ($parent === '') redirectDocumentsWithMessage("error", "Parent Folder name cannot be empty.");
            if (parentFolderExists($conn, $parent)) redirectDocumentsWithMessage("error", "Parent Folder already exists.");
            
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

        $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, policy_id) VALUES (?, ?, ?)");
        $stmt_create->bind_param("ssi", $parent, $sub, $folder_policy);
        
        if ($stmt_create->execute()) {
            $new_category_id = $stmt_create->insert_id;
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
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_FOLDER', $action_desc);
            $message = ($sub === '') ? "Parent Folder created successfully." : "Sub-folder created successfully.";
            redirectDocumentsWithMessage("success", $message, ($sub === '' ? '' : $parent));
        }
        redirectDocumentsWithMessage("error", "Failed to create folder.", $parent);
    }

    if ($can_manage) {
        if ($_POST['action'] === 'delete_folder') {
            $delete_type = $_POST['delete_type'];
            $parent_name = $_POST['parent_name'];
            $sub_name = $_POST['sub_name'] ?? '';
            
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
                    if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'DELETE_FOLDER', "Permanently deleted Main Parent Folder: " . $parent_name);
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
                    if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'DELETE_FOLDER', "Permanently deleted Sub-folder: " . $sub_name . " under " . $parent_name);
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
// FOLDER FETCHING
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
$view_disposition = isset($_GET['disposition']) && $_GET['disposition'] == '1';
$view_archives = isset($_GET['view_archives']) && $_GET['view_archives'] == '1';
$view_shared = isset($_GET['shared']) && $_GET['shared'] == '1';

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
if($view_shared) $return_params[] = "shared=1";
$exact_return_url = "documents.php" . (!empty($return_params) ? "?" . implode("&", $return_params) : "");

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
} elseif ($view_shared) {
    $page_title = "Shared with Me";
    $page_subtitle = "Files explicitly shared with your account.";
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
$breadcrumbs[] = ['label' => 'Official Records', 'url' => 'documents.php', 'active' => empty($view_archives) && empty($view_disposition) && empty($view_shared) && empty($parent_filter) && empty($type_filter)];

if ($view_archives) {
    $breadcrumbs[] = ['label' => 'Archived', 'url' => 'documents.php?view_archives=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_disposition) {
    $breadcrumbs[] = ['label' => 'Ready for Disposition', 'url' => 'documents.php?disposition=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_shared) {
    $breadcrumbs[] = ['label' => 'Shared with Me', 'url' => 'documents.php?shared=1', 'active' => true];
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

if (empty($view_archives) && empty($view_disposition) && empty($view_shared) && empty($parent_filter) && empty($type_filter)) {
    $breadcrumbs[0]['active'] = true;
}

$hide_upload_button = $view_archives || $view_disposition || $view_shared;
if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents')) {
    $hide_upload_button = true;
}

// ==========================================
// DOCUMENTS QUERIES (UNIFIED RBAC & SHARE CHECK)
// ==========================================
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
    
    if (!$is_top_mgmt) {
        if (empty($user_categories)) {
            $disp_where[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ?)";
            $disp_params[] = $_SESSION['user_id'];
            $disp_params[] = '%"user_' . $_SESSION['user_id'] . '"%';
            $disp_types .= "is";
        } else {
            $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
            $disp_where[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ? OR (d.access_type = 'Folder Default' AND d.category IN ($placeholders)))";
            $disp_params[] = $_SESSION['user_id'];
            $disp_params[] = '%"user_' . $_SESSION['user_id'] . '"%';
            $disp_params = array_merge($disp_params, $user_categories);
            $disp_types .= "is" . str_repeat('s', count($user_categories));
        }
    }
    
    $disp_where_clause = implode(" AND ", $disp_where);
    $disp_query_sql = "
        SELECT d.*, p.policy_name, p.action_after_retention, u.full_name, 
               DATE_ADD(d.uploaded_at, INTERVAL COALESCE(p.retention_years, 0) YEAR) AS retention_date,
               locker.full_name AS locked_by_name
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id
        LEFT JOIN users u ON d.uploaded_by = u.user_id
        LEFT JOIN users locker ON d.locked_by = locker.user_id
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

if ($view_shared) {
    // ONLY show files explicitly shared with me
    $where[] = "d.file_permissions LIKE ?";
    $params[] = '%"user_' . $_SESSION['user_id'] . '"%';
    $types .= "s";
} else {
    if (!empty($type_filter)) {
        $where[] = "d.category = ?";
        $params[] = $type_filter;
        $types .= "s";
    }
    
    if (!empty($doc_status)) {
        if ($doc_status == 'Archived') {
            $where[0] = "d.status = 'Archived'"; 
        } else {
            $where[] = "d.status = ?";
            $params[] = $doc_status;
            $types .= "s";
        }
    }

    if (!$is_top_mgmt) {
        if (empty($user_categories)) {
            $where[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ?)";
            $params[] = $_SESSION['user_id'];
            $params[] = '%"user_' . $_SESSION['user_id'] . '"%';
            $types .= "is";
        } else {
            $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
            $where[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ? OR (d.access_type = 'Folder Default' AND d.category IN ($placeholders)))";
            $params[] = $_SESSION['user_id'];
            $params[] = '%"user_' . $_SESSION['user_id'] . '"%';
            $params = array_merge($params, $user_categories);
            $types .= "is" . str_repeat('s', count($user_categories));
        }
    }
}

$whereClause = implode(' AND ', $where);
$query = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name, locker.full_name AS locked_by_name
          FROM documents d
          LEFT JOIN purchase_orders p ON d.po_id = p.po_id
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          LEFT JOIN users locker ON d.locked_by = locker.user_id
          WHERE $whereClause 
          ORDER BY d.uploaded_at DESC";
$stmt = $conn->prepare($query);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$documents = $stmt->get_result();

$archivedWhere = ["d.status = 'Archived'"];
$archivedParams = [];
$archivedTypes = "";

if (!empty($search)) {
    $archivedWhere[] = "(d.file_name LIKE ? OR d.category LIKE ?)";
    $archivedParams[] = "%$search%";
    $archivedParams[] = "%$search%";
    $archivedTypes .= "ss";
}
if (!empty($type_filter)) {
    $archivedWhere[] = "d.category = ?";
    $archivedParams[] = $type_filter;
    $archivedTypes .= "s";
} 

if (!$is_top_mgmt) {
    if (empty($user_categories)) {
        $archivedWhere[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ?)";
        $archivedParams[] = $_SESSION['user_id'];
        $archivedParams[] = '%"user_' . $_SESSION['user_id'] . '"%';
        $archivedTypes .= "is";
    } else {
        $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
        $archivedWhere[] = "(d.uploaded_by = ? OR d.file_permissions LIKE ? OR (d.access_type = 'Folder Default' AND d.category IN ($placeholders)))";
        $archivedParams[] = $_SESSION['user_id'];
        $archivedParams[] = '%"user_' . $_SESSION['user_id'] . '"%';
        $archivedParams = array_merge($archivedParams, $user_categories);
        $archivedTypes .= "is" . str_repeat('s', count($user_categories));
    }
}

$archivedWhereClause = "WHERE " . implode(' AND ', $archivedWhere);
$archivedLimit = empty($search) ? "LIMIT 5" : "";
$query_archived = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name, locker.full_name AS locked_by_name
                   FROM documents d
                   LEFT JOIN purchase_orders p ON d.po_id = p.po_id
                   LEFT JOIN users u ON d.uploaded_by = u.user_id
                   LEFT JOIN users locker ON d.locked_by = locker.user_id
                   $archivedWhereClause 
                   ORDER BY d.uploaded_at DESC $archivedLimit";
$stmt_archived = $conn->prepare($query_archived);
if(!empty($archivedParams)) $stmt_archived->bind_param($archivedTypes, ...$archivedParams);
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .badge-lock, .badge-restricted { font-size: 0.70rem; letter-spacing: 0.5px; font-weight: 600; }
        .btn-checkin { background-color: #f0fdf4 !important; color: #15803d !important; }
        .btn-checkin:hover { background-color: #dcfce7 !important; }
        
        .share-list-container { max-height: 250px; overflow-y: auto; margin-top: 15px; }
        .share-user-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .share-user-row:last-child { border-bottom: none; }
        .share-avatar { width: 36px; height: 36px; border-radius: 50%; background: #e2e8f0; color: #475569; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; margin-right: 12px; }
        .share-role-select { border: none; background: transparent; font-weight: 500; color: #475569; cursor: pointer; outline: none; box-shadow: none; width: auto; padding-right: 20px; }
        .share-role-select:hover { background: #f1f5f9; border-radius: 4px; }
        .share-role-select:focus { outline: none; box-shadow: none; }
        .general-access-box { background: #f8fafc; border-radius: 8px; padding: 15px; margin-top: 15px; display: flex; align-items: center; justify-content: space-between; }
        .general-icon { width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #475569; font-size: 16px; margin-right: 15px; }
        .add-people-box { border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px; display: flex; align-items: center; }
        .add-people-box select { border: none; outline: none; box-shadow: none; flex-grow: 1; color: #334155; }
        
        /* Interactive Row CSS */
        .cursor-pointer { cursor: pointer; }
        .file-row-title { transition: all 0.2s; }
        .file-row-title:hover h6 { color: #2563eb !important; text-decoration: underline; }
        .file-row-title:hover .file-icon-md { background-color: #dbeafe !important; transform: scale(1.05); }
        .box-40 { width: 40px; height: 40px; }
    </style>
</head>
<body class="bg-f8f9fa">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="sticky-header-panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-3">
                <?php if($show_back_btn): ?>
                    <a href="<?php echo $back_url; ?>" class="btn btn-sm btn-white bg-white border shadow-sm btn-back-custom" title="Back">
                        <i class="fas fa-arrow-left text-secondary"></i>
                    </a>
                <?php endif; ?>
                <div>
                    <h3 class="fw-bold mb-0 text-dark letter-spacing-tight">
                        <?php if($view_archives): ?><i class="fas fa-archive text-secondary me-2"></i><?php endif; ?>
                        <?php if($view_disposition): ?><i class="fas fa-trash-alt text-warning me-2"></i><?php endif; ?>
                        <?php if($view_shared): ?><i class="fas fa-user-friends text-info me-2"></i><?php endif; ?>
                        <?php echo $page_title; ?>
                    </h3>
                    <p class="text-muted mb-0 small"><?php echo $page_subtitle; ?></p>
                </div>
            </div>
            
            <div class="d-flex gap-2 align-items-center">
                
                <a href="documents.php?shared=1" class="btn <?php echo $view_shared ? 'btn-info text-white' : 'btn-white bg-white border text-dark'; ?> fw-medium px-3 text-nowrap shadow-sm br-8" title="View files explicitly shared with me">
                    <i class="fas fa-user-friends <?php echo $view_shared ? 'text-white' : 'text-info'; ?> me-2"></i> Shared with Me
                </a>

                <?php if ($can_manage && empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm br-8" data-bs-toggle="modal" data-bs-target="#createParentFolderModal">
                        <i class="fas fa-folder-plus me-2"></i> Create Parent Folder
                    </button>
                <?php elseif (!empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared && $can_manage): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm br-8" data-bs-toggle="modal" data-bs-target="#createSubFolderModal">
                        <i class="fas fa-folder-plus me-2"></i> Create Sub-folder
                    </button>
                <?php elseif (!empty($type_filter) && !$hide_upload_button): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm br-8" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-upload me-2"></i> Upload File
                    </button>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn bg-white border text-dark shadow-sm dropdown-toggle fw-medium px-3 rounded-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-cog text-secondary me-2"></i> Options
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm br-10">
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

    <?php if (!$view_archives && !$view_disposition && !$view_shared && empty($search)): ?>
        
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
                                <h6 class="mb-1 fw-bold text-dark text-truncate max-w-180"><?php echo htmlspecialchars($p); ?></h6>
                                <p class="text-muted small mb-0"><i class="fas fa-file-alt me-1"></i><?php echo $fileCount; ?> active files</p>
                            </div>
                        </div>
                        <?php if($can_manage): ?>
                        <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-3" onclick="event.stopPropagation();">
                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window"><i class="fas fa-ellipsis-v"></i></button>
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
                        <div class="mb-3"><i class="fas fa-folder-open text-muted icon-4rem-opacity"></i></div>
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
                            <div class="folder-icon-box bg-primary bg-opacity-10 text-primary me-3 border border-primary border-opacity-25 box-40">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <h6 class="mb-0 fw-bold text-dark text-truncate flex-grow-1 fs-095rem"><?php echo htmlspecialchars($s); ?></h6>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-light">
                            <span class="text-muted small fw-medium"><?php echo $fileCount; ?> items</span>
                            <i class="fas fa-chevron-right text-primary opacity-50 small"></i>
                        </div>
                        
                        <?php if($can_manage): ?>
                        <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-2" onclick="event.stopPropagation();">
                            <button class="btn-dots bg-transparent border-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window"><i class="fas fa-ellipsis-v small"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm min-w-200">
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
                        <div class="mb-3"><i class="fas fa-folder text-muted icon-3rem-opacity"></i></div>
                        <h6 class="text-dark fw-bold">Empty Parent Folder</h6>
                        <p class="text-muted mb-0 small">There are no sub-folders available here.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($view_disposition || (!empty($type_filter)) || $view_archives || $view_shared || !empty($search)): ?>
        
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
                                <?php if($disposition_docs->num_rows > 0): while($doc = $disposition_docs->fetch_assoc()): 
                                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                    $is_img = in_array($ext, ['jpg','jpeg','png','gif']);
                                    
                                    $is_restricted = ($doc['access_type'] === 'Restricted');
                                    $file_permissions = json_decode($doc['file_permissions'] ?? '{}', true) ?: [];
                                    $is_mine = ($doc['uploaded_by'] == $_SESSION['user_id']);
                                    
                                    $has_file_access = true;
                                    if ($is_restricted && !$is_system_admin && !$is_mine && !isset($file_permissions['user_'.$_SESSION['user_id']])) {
                                        $has_file_access = false;
                                    }
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center <?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                                            <div class="file-icon-md bg-light text-primary me-3 border">
                                                <?php echo $has_file_access ? '<i class="fas fa-file-alt"></i>' : '<i class="fas fa-lock text-danger"></i>'; ?>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center">
                                                    <h6 class="mb-1 text-dark fw-bold text-wrap max-w-300"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                                    <?php if($is_restricted): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2 badge-restricted" title="File-Level Restriction Applied">
                                                            <i class="fas fa-user-shield"></i> Restricted
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
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
                                            <?php if ($has_file_access): ?>
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
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2">
                                                    <i class="fas fa-ban me-1"></i> Restricted
                                                </span>
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
                    
                    <form method="GET" action="documents.php" class="d-flex w-300px">
                        <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                        <?php if($view_shared): ?><input type="hidden" name="shared" value="1"><?php endif; ?>
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
                                    
                                    // Concurrency Lock Variables
                                    $is_locked = (bool)$doc['is_locked'];
                                    $locked_by = $doc['locked_by'];
                                    $locked_by_name = htmlspecialchars($doc['locked_by_name'] ?? '');
                                    
                                    $is_mine = ($doc['uploaded_by'] == $_SESSION['user_id']);
                                    $is_lock_owner = ($locked_by == $_SESSION['user_id']);
                                    $can_override_lock = in_array($_SESSION['role'], ['Admin', 'GM', 'President']);
                                    $is_locked_by_other = ($is_locked && !$is_lock_owner);

                                    // GDrive Permissions UI Logic
                                    $access_type = $doc['access_type'] ?? 'Folder Default';
                                    $file_permissions = json_decode($doc['file_permissions'] ?? '{}', true) ?: [];
                                    
                                    $my_file_role = 'None';
                                    if ($is_mine || $is_top_mgmt) {
                                        $my_file_role = 'Editor';
                                    } else {
                                        if (isset($file_permissions['user_'.$_SESSION['user_id']])) {
                                            $my_file_role = $file_permissions['user_'.$_SESSION['user_id']];
                                        } else if ($access_type === 'Folder Default' && in_array($doc['category'], $user_categories)) {
                                            $my_file_role = 'Editor'; 
                                        }
                                    }
                                    
                                    $has_file_access = ($my_file_role !== 'None');
                                    $can_edit_file = in_array($my_file_role, ['Editor']);
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center <?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                                            <?php if($is_img && $has_file_access): ?>
                                                <img src="<?php echo htmlspecialchars($doc['file_path']); ?>" alt="thumb" class="file-thumb-md me-3 shadow-sm">
                                            <?php else: ?>
                                                <div class="file-icon-md bg-light text-primary me-3 border">
                                                    <?php 
                                                        if(!$has_file_access) echo '<i class="fas fa-lock text-danger"></i>';
                                                        elseif($ext == 'pdf') echo '<i class="fas fa-file-pdf text-danger"></i>';
                                                        elseif(in_array($ext, ['doc','docx'])) echo '<i class="fas fa-file-word text-primary"></i>';
                                                        elseif(in_array($ext, ['xls','xlsx'])) echo '<i class="fas fa-file-excel text-success"></i>';
                                                        else echo '<i class="fas fa-file"></i>';
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="d-flex align-items-center">
                                                    <h6 class="mb-1 text-dark fw-bold text-wrap max-w-300"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                                    
                                                    <?php if($is_locked): ?>
                                                        <span class="badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1 ms-2 badge-lock" title="Currently being worked on">
                                                            <i class="fas fa-lock"></i> Locked by <?php echo $is_lock_owner ? 'You' : explode(' ', trim($locked_by_name))[0]; ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if($access_type === 'Restricted'): ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2 badge-restricted" title="Restricted Access">
                                                            <i class="fas fa-user-shield"></i> Restricted
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
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
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window"><i class="fas fa-ellipsis-v"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                
                                                <?php if ($has_file_access): ?>
                                                    <li>
                                                        <a class="dropdown-item fw-medium text-success" href="<?php echo htmlspecialchars($doc['file_path']); ?>" download>
                                                            <i class="fas fa-download text-success"></i> Download
                                                        </a>
                                                    </li>
                                                    
                                                    <?php if (!$view_archives && $can_edit_file): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    
                                                    <?php if (!$is_locked): ?>
                                                        <!-- CHECK OUT -->
                                                        <li>
                                                            <form action="documents.php" method="POST">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                <input type="hidden" name="action" value="toggle_lock">
                                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                                <input type="hidden" name="current_state" value="0">
                                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                                <button type="submit" class="dropdown-item fw-medium text-warning">
                                                                    <i class="fas fa-lock text-warning"></i> Check-out (Lock File)
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php elseif ($is_lock_owner || $can_override_lock): ?>
                                                        <!-- CHECK IN -->
                                                        <li>
                                                            <form action="documents.php" method="POST">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                <input type="hidden" name="action" value="toggle_lock">
                                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                                <input type="hidden" name="current_state" value="1">
                                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                                <button type="submit" class="dropdown-item fw-bold btn-checkin">
                                                                    <i class="fas fa-unlock text-success"></i> Check-in (Unlock)
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                    
                                                    <!-- GDRIVE SHARE -->
                                                    <?php if ($is_mine || $is_top_mgmt): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item fw-medium text-secondary" 
                                                                onclick="openShareModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', '<?php echo htmlspecialchars($doc['access_type']); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_permissions'] ?? '{}')); ?>', '<?php echo htmlspecialchars(addslashes($doc['full_name'])); ?>')">
                                                            <i class="fas fa-user-plus text-secondary"></i> Share Settings
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>

                                                    <?php endif; // end !view_archives & can edit ?>

                                                    <?php if($can_edit_file): ?>
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
                                                                <button type="submit" class="dropdown-item text-warning fw-bold <?php echo $is_locked_by_other && !$can_override_lock ? 'disabled text-muted' : ''; ?>"><i class="fas fa-archive text-warning"></i> Archive File</button>
                                                            </form>
                                                        </li>
                                                        <?php endif; ?>

                                                        <?php if(has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')): ?>
                                                        <li>
                                                            <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                                <button type="submit" class="dropdown-item text-danger fw-bold <?php echo $is_locked_by_other && !$can_override_lock ? 'disabled text-muted' : ''; ?>"><i class="fas fa-trash-alt text-danger"></i> Permanent Delete</button>
                                                            </form>
                                                        </li>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                <?php else: ?>
                                                    <li>
                                                        <span class="dropdown-item fw-medium text-muted" style="cursor: not-allowed;">
                                                            <i class="fas fa-ban text-danger me-2 opacity-75"></i> Access Restricted
                                                        </span>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-folder-open fs-3 mb-2 d-block opacity-50"></i> No files found here.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if(!$view_archives && $archived_docs->num_rows > 0 && empty($search)): ?>
            <div class="mt-4 pt-3 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-archive me-2"></i> Recently Archived</h6>
                    <a href="documents.php?view_archives=1<?php echo (!empty($parent_filter) ? '&parent='.urlencode($parent_filter) : ''); ?><?php echo (!empty($type_filter) ? '&type='.urlencode($type_filter) : ''); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">View All Archives</a>
                </div>
                <div class="row g-2">
                    <?php while($arch = $archived_docs->fetch_assoc()): 
                        $ext = strtolower(pathinfo($arch['file_name'], PATHINFO_EXTENSION));
                        $is_img = in_array($ext, ['jpg','jpeg','png','gif']);
                        
                        $is_restricted = ($arch['access_type'] === 'Restricted');
                        $file_permissions = json_decode($arch['file_permissions'] ?? '{}', true) ?: [];
                        $is_mine = ($arch['uploaded_by'] == $_SESSION['user_id']);
                        
                        $has_file_access = true;
                        if ($is_restricted && !$is_system_admin && !$is_mine && !isset($file_permissions['user_'.$_SESSION['user_id']])) {
                            $has_file_access = false;
                        }
                    ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="card border shadow-sm h-100 rounded-3 bg-body <?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($arch['file_path']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($arch['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                            <div class="card-body p-3 d-flex align-items-center">
                                <?php echo $has_file_access ? '<i class="fas fa-file-alt text-secondary fs-4 me-3 file-icon-md"></i>' : '<i class="fas fa-lock text-danger fs-4 me-3"></i>'; ?>
                                <div class="min-w-0">
                                    <h6 class="mb-0 text-truncate text-secondary fw-bold fs-09rem"><?php echo htmlspecialchars($arch['file_name']); ?></h6>
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

<!-- ========================================== -->
<!-- MODALS PLACED OUTSIDE MAIN CONTENT TO FIX BUGS -->
<!-- ========================================== -->

<!-- IN-SYSTEM DOCUMENT VIEWER MODAL -->
<div class="modal fade" id="documentViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content border-0" style="background-color: rgba(15, 23, 42, 0.95); backdrop-filter: blur(8px);">
            
            <!-- Minimalist Top Toolbar -->
            <div class="d-flex justify-content-between align-items-center px-4 py-3 position-absolute top-0 w-100" style="z-index: 1060; background: linear-gradient(180deg, rgba(0,0,0,0.5) 0%, transparent 100%); pointer-events: none;">
                <div class="d-flex align-items-center text-white" style="pointer-events: auto;">
                    <i class="fas fa-file-alt fs-5 me-3 text-secondary"></i>
                    <h6 class="fw-medium mb-0 text-truncate" id="viewerFileName" style="max-width: 400px; text-shadow: 0 1px 3px rgba(0,0,0,0.5);">Document Preview</h6>
                </div>
                <div class="d-flex gap-3 align-items-center" style="pointer-events: auto;">
                    <a id="viewerDownloadBtn" href="#" download class="text-white text-decoration-none" title="Download" style="opacity: 0.8; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-download fs-5"></i>
                    </a>
                    <button type="button" class="btn btn-link text-white shadow-none p-0 ms-3 text-decoration-none" data-bs-dismiss="modal" title="Close" style="opacity: 0.8; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-times fs-4"></i>
                    </button>
                </div>
            </div>

            <!-- Viewer Body -->
            <div class="modal-body p-0 d-flex justify-content-center align-items-center overflow-hidden" style="height: 100vh; touch-action: none;">
                
                <!-- Loader -->
                <div id="viewerLoader" class="position-absolute text-center" style="z-index: 1040;">
                    <div class="spinner-border text-light opacity-50 mb-3" role="status" style="width: 2.5rem; height: 2.5rem; border-width: 0.15em;"></div>
                    <div class="fw-bold text-white opacity-50 fs-xs text-uppercase" style="letter-spacing: 2px;">Loading Document</div>
                </div>

                <!-- Dynamic Content Wrapper (For Panning & Zooming) -->
                <div id="viewerContentWrapper" style="transition: transform 0.2s cubic-bezier(0.2, 0, 0, 1); transform-origin: center center; display:flex; justify-content:center; align-items:center; width: 100%; height: 100%;">
                    <img id="documentViewerImage" src="" draggable="false" style="display:none; max-width: 90vw; max-height: 85vh; object-fit: contain; box-shadow: 0 10px 40px rgba(0,0,0,0.3); border-radius: 4px;" />
                    <iframe id="documentViewerFrame" src="" style="display:none; width: 80vw; height: 85vh; border: none; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.3); border-radius: 8px;"></iframe>
                </div>

            </div>

            <!-- GDrive Style Floating Zoom Controls -->
            <div class="position-absolute bottom-0 start-50 translate-middle-x mb-4" style="z-index: 1060;" id="zoomControlsContainer">
                <div class="d-flex align-items-center border border-secondary border-opacity-50 rounded-pill px-3 py-2 shadow-lg" style="background-color: rgba(30, 41, 59, 0.95) !important;">
                    <button type="button" class="btn btn-link text-white shadow-none p-1 text-decoration-none" onclick="zoomViewer('out')" title="Zoom Out" style="opacity: 0.8;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-minus fs-6"></i>
                    </button>
                    <span id="viewerZoomLevel" class="text-white fw-medium px-3 fs-sm" style="min-width: 60px; text-align: center; cursor: default;">100%</span>
                    <button type="button" class="btn btn-link text-white shadow-none p-1 text-decoration-none" onclick="zoomViewer('in')" title="Zoom In" style="opacity: 0.8;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-plus fs-6"></i>
                    </button>
                    <div class="border-start border-secondary opacity-50 mx-2" style="height: 18px;"></div>
                    <button type="button" class="btn btn-link text-white shadow-none p-1 text-decoration-none" onclick="zoomViewer('reset')" title="Fit to Screen" style="opacity: 0.8;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-expand fs-6"></i>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- GDRIVE-STYLE SHARE MODAL (MULTIPLE SELECT) -->
<div class="modal fade sleek-modal" id="shareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0" style="border-radius: 12px;">
            <div class="modal-header border-bottom-0 pb-2">
                <h5 class="modal-title fw-bold text-dark fs-md"><i class="fas fa-user-plus text-primary me-2"></i> Share "<span id="shareFileName" class="text-truncate d-inline-block align-bottom" style="max-width: 250px;"></span>"</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-1 pb-4">
                <form action="documents.php" method="POST" id="shareForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="share_document">
                    <input type="hidden" name="doc_id" id="shareDocId" value="">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase mb-1">Add people</label>
                        <div class="d-flex align-items-start gap-2">
                            <select id="addUserSelect" class="form-select border shadow-sm custom-scrollbar" multiple style="height: 120px;">
                                <?php foreach($all_users as $u): ?>
                                    <option value="<?php echo $u['user_id']; ?>" data-name="<?php echo htmlspecialchars($u['full_name']); ?>"><?php echo htmlspecialchars($u['full_name']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-primary shadow-sm fw-medium px-4 h-100" style="min-height: 40px;" onclick="addSelectedUser()">Add Selected</button>
                        </div>
                        <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">Hold CTRL (or CMD) to select multiple users.</small>
                    </div>
                    
                    <div class="fw-bold text-dark fs-xs mb-2">People with access</div>
                    <div class="share-list-container custom-scrollbar px-1 mb-2" id="shareUsersList">
                        <!-- Uploader Fixed Row -->
                        <div class="share-user-row">
                            <div class="d-flex align-items-center">
                                <div class="share-avatar bg-primary text-white"><i class="fas fa-user"></i></div>
                                <div>
                                    <div class="fw-bold text-dark fs-sm" id="shareUploaderName">Owner Name</div>
                                    <div class="text-muted" style="font-size: 0.70rem;">Owner</div>
                                </div>
                            </div>
                            <span class="text-muted small fw-medium">Owner</span>
                        </div>
                        <!-- Dynamic Rows Here -->
                    </div>

                    <div class="fw-bold text-dark fs-xs mt-4 mb-2">General access</div>
                    <div class="general-access-box">
                        <div class="d-flex align-items-center">
                            <div class="general-icon" id="generalAccessIcon"><i class="fas fa-globe"></i></div>
                            <div>
                                <select name="access_type" id="accessTypeSelect" class="share-role-select fw-bold text-dark fs-sm mb-1" style="padding-left: 0;" onchange="toggleGeneralAccess()">
                                    <option value="Folder Default">Folder Default</option>
                                    <option value="Restricted">Restricted</option>
                                </select>
                                <div class="text-muted" style="font-size: 0.75rem;" id="generalAccessDesc">Anyone in the assigned department folder can view and edit this file.</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4 pt-3">
                        <button type="button" class="btn btn-light sleek-btn border px-4 me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 fw-medium shadow-sm">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($type_filter) && !$hide_upload_button): ?>
<!-- UPLOAD MODAL -->
<div class="modal fade sleek-modal" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
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
<!-- CREATE PARENT FOLDER MODAL -->
<div class="modal fade sleek-modal" id="createParentFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus text-primary me-2"></i> Create Main Folder</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="hidden" name="parent_category" value="NEW_PARENT_FOLDER">
                    <input type="hidden" name="new_folder_name" value="">

                    <div class="mb-3">
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
<!-- CREATE SUB FOLDER MODAL -->
<div class="modal fade sleek-modal" id="createSubFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
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
<!-- EDIT POLICIES MODAL -->
<div class="modal fade sleek-modal" id="editPoliciesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow">
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

<script>
// Prevent Dropdown Clipping inside Tables
document.querySelectorAll('.table-scrollable, .table-responsive').forEach(function (tableContainer) {
    tableContainer.addEventListener('show.bs.dropdown', function () {
        this.style.overflow = 'inherit';
    });
    tableContainer.addEventListener('hide.bs.dropdown', function () {
        this.style.overflow = 'auto';
    });
});

// DOCUMENT VIEWER JS LOGIC
let currentZoom = 1;
const ZOOM_STEP = 0.25;
const MIN_ZOOM = 0.25;
const MAX_ZOOM = 4.0;
let isImageMode = false;

// Panning variables
let isDragging = false;
let startX, startY, initialX, initialY;
let currentTranslateX = 0, currentTranslateY = 0;

function openDocumentViewer(fileUrl, fileName, isImage) {
    document.getElementById('viewerFileName').innerText = fileName;
    document.getElementById('viewerDownloadBtn').href = fileUrl;
    isImageMode = isImage;
    
    const imgEl = document.getElementById('documentViewerImage');
    const frameEl = document.getElementById('documentViewerFrame');
    const loader = document.getElementById('viewerLoader');
    const zoomControls = document.getElementById('zoomControlsContainer');
    
    // Reset zoom and pan
    currentZoom = 1;
    currentTranslateX = 0;
    currentTranslateY = 0;
    updateZoomTransform();
    
    imgEl.style.display = 'none';
    frameEl.style.display = 'none';
    loader.style.display = 'block';
    
    if (isImage) {
        zoomControls.style.display = 'block';
        imgEl.src = fileUrl;
        imgEl.onload = function() {
            loader.style.display = 'none';
            imgEl.style.display = 'block';
        };
    } else {
        zoomControls.style.display = 'none'; // Iframe handles its own zoom/pan usually
        frameEl.src = fileUrl;
        frameEl.onload = function() {
            loader.style.display = 'none';
            frameEl.style.display = 'block';
        };
    }

    new bootstrap.Modal(document.getElementById('documentViewerModal')).show();
}

function zoomViewer(action) {
    if (action === 'in' && currentZoom < MAX_ZOOM) {
        currentZoom += ZOOM_STEP;
    } else if (action === 'out' && currentZoom > MIN_ZOOM) {
        currentZoom -= ZOOM_STEP;
    } else if (action === 'reset') {
        currentZoom = 1;
        currentTranslateX = 0;
        currentTranslateY = 0;
    }
    updateZoomTransform();
}

function updateZoomTransform() {
    const wrapper = document.getElementById('viewerContentWrapper');
    if (currentZoom <= 1) {
        currentTranslateX = 0;
        currentTranslateY = 0;
        wrapper.style.cursor = 'default';
    } else {
        wrapper.style.cursor = isDragging ? 'grabbing' : 'grab';
    }
    wrapper.style.transform = `translate(${currentTranslateX}px, ${currentTranslateY}px) scale(${currentZoom})`;
    document.getElementById('viewerZoomLevel').innerText = Math.round(currentZoom * 100) + '%';
}

// Draggable Panning Logic
const wrapper = document.getElementById('viewerContentWrapper');

wrapper.addEventListener('mousedown', dragStart);
wrapper.addEventListener('mousemove', drag);
window.addEventListener('mouseup', dragEnd);

wrapper.addEventListener('touchstart', dragStart, {passive: false});
wrapper.addEventListener('touchmove', drag, {passive: false});
window.addEventListener('touchend', dragEnd);

function dragStart(e) {
    if (!isImageMode || currentZoom <= 1) return;
    if (e.target.tagName === 'IFRAME') return;
    
    isDragging = true;
    wrapper.style.cursor = 'grabbing';
    wrapper.style.transition = 'none'; 
    
    if (e.type === 'touchstart') {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
    } else {
        startX = e.clientX;
        startY = e.clientY;
        e.preventDefault(); 
    }
    
    initialX = currentTranslateX;
    initialY = currentTranslateY;
}

function drag(e) {
    if (!isDragging) return;
    
    let clientX, clientY;
    if (e.type === 'touchmove') {
        clientX = e.touches[0].clientX;
        clientY = e.touches[0].clientY;
    } else {
        clientX = e.clientX;
        clientY = e.clientY;
    }
    
    // Scale dragging distance inversely to zoom so panning feels natural
    const dx = (clientX - startX) / currentZoom;
    const dy = (clientY - startY) / currentZoom;
    
    currentTranslateX = initialX + dx;
    currentTranslateY = initialY + dy;
    
    wrapper.style.transform = `translate(${currentTranslateX}px, ${currentTranslateY}px) scale(${currentZoom})`;
}

function dragEnd() {
    if (!isDragging) return;
    isDragging = false;
    wrapper.style.cursor = currentZoom > 1 ? 'grab' : 'default';
    wrapper.style.transition = 'transform 0.2s cubic-bezier(0.2, 0, 0, 1)'; 
}

document.getElementById('documentViewerModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('documentViewerFrame').src = '';
    document.getElementById('documentViewerImage').src = '';
    currentZoom = 1;
    currentTranslateX = 0;
    currentTranslateY = 0;
});

// GDRIVE SHARE JS LOGIC (MULTIPLE SELECT)
function openShareModal(docId, fileName, accessType, permissionsJsonStr, uploaderName) {
    document.getElementById('shareDocId').value = docId;
    document.getElementById('shareFileName').innerText = fileName;
    document.getElementById('shareUploaderName').innerText = uploaderName;
    
    // Clear list (except owner row)
    const listContainer = document.getElementById('shareUsersList');
    const rows = listContainer.querySelectorAll('.dynamic-user-row');
    rows.forEach(r => r.remove());

    // Parse JSON and populate dynamic rows
    let perms = {};
    if (permissionsJsonStr && permissionsJsonStr.trim() !== '') {
        try { perms = JSON.parse(permissionsJsonStr); } catch(e) {}
    }
    
    const allUsers = <?php echo json_encode($all_users); ?>;
    Object.keys(perms).forEach(key => {
        let uid = key.replace('user_', '');
        let role = perms[key];
        let userObj = allUsers.find(u => u.user_id == uid);
        if (userObj) {
            appendUserRow(userObj.user_id, userObj.full_name, role);
        }
    });

    // Set General Access
    document.getElementById('accessTypeSelect').value = accessType;
    toggleGeneralAccess();
    
    new bootstrap.Modal(document.getElementById('shareModal')).show();
}

function toggleGeneralAccess() {
    let type = document.getElementById('accessTypeSelect').value;
    let icon = document.getElementById('generalAccessIcon');
    let desc = document.getElementById('generalAccessDesc');
    
    if (type === 'Restricted') {
        icon.innerHTML = '<i class="fas fa-lock"></i>';
        desc.innerText = 'Only people with access can open this file.';
    } else {
        icon.innerHTML = '<i class="fas fa-globe"></i>';
        desc.innerText = 'Anyone in the assigned department folder can view and edit this file.';
    }
}

function addSelectedUser() {
    let select = document.getElementById('addUserSelect');
    let selectedOptions = Array.from(select.selectedOptions);
    
    if (selectedOptions.length === 0) return;
    
    selectedOptions.forEach(opt => {
        let uid = opt.value;
        let name = opt.getAttribute('data-name');
        
        // Prevent duplicates
        if (!document.getElementById('row_uid_' + uid)) {
            appendUserRow(uid, name, 'Viewer'); // Default as Viewer
        }
        opt.selected = false; // deselect after adding
    });
}

function appendUserRow(uid, name, role) {
    let initial = name.charAt(0).toUpperCase();
    let isViewer = role === 'Viewer' ? 'selected' : '';
    let isEditor = role === 'Editor' ? 'selected' : '';
    
    let html = `
        <div class="share-user-row dynamic-user-row" id="row_uid_${uid}">
            <div class="d-flex align-items-center">
                <div class="share-avatar">${initial}</div>
                <div>
                    <div class="fw-bold text-dark fs-sm">${name}</div>
                </div>
            </div>
            <select name="user_roles[${uid}]" class="share-role-select" onchange="if(this.value==='Remove'){ removeUserRow(${uid}) }">
                <option value="Viewer" ${isViewer}>Viewer</option>
                <option value="Editor" ${isEditor}>Editor</option>
                <option value="Remove" class="text-danger border-top mt-1">Remove access</option>
            </select>
        </div>
    `;
    document.getElementById('shareUsersList').insertAdjacentHTML('beforeend', html);
}

function removeUserRow(uid) {
    let row = document.getElementById('row_uid_' + uid);
    if (row) row.remove();
}
</script>

</body>
</html>