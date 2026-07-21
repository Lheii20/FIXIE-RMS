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
// AUTO-MIGRATION: LEGAL HOLD FEATURE
// ==========================================
$check_legal_hold = $conn->query("SHOW COLUMNS FROM documents LIKE 'is_legal_hold'");
if ($check_legal_hold && $check_legal_hold->num_rows == 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN is_legal_hold TINYINT(1) DEFAULT 0, ADD COLUMN legal_hold_reason TEXT DEFAULT NULL, ADD COLUMN legal_hold_by INT DEFAULT NULL, ADD COLUMN legal_hold_at DATETIME DEFAULT NULL");
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

// FIX: Alamin kung sino lang ang pwedeng maka-click ng PO Link
$can_view_po = in_array($role, ['Admin', 'GM', 'President', 'Finance', 'Procurement', 'Supply Chain']);

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

$all_users = [];
$u_query = $conn->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' AND role NOT IN ('Admin', 'GM', 'President') ORDER BY full_name ASC");
if ($u_query) {
    while($u = $u_query->fetch_assoc()) {
        $all_users[] = $u;
    }
}

// ==========================================
// FORM HANDLER: GDRIVE SHARING, CHECK-IN/OUT, FOLDERS, LEGAL HOLD
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }

    if ($_POST['action'] === 'toggle_legal_hold') {
        if (!$is_top_mgmt && !$can_manage) {
            redirectDocumentsWithMessage("error", "You do not have permission to manage Legal Holds.");
        }
        $doc_id = intval($_POST['doc_id']);
        $current_state = intval($_POST['current_state']);
        $return_url = $_POST['return_url'] ?? 'documents.php';
        
        $stmt = $conn->prepare("SELECT file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $doc_info = $stmt->get_result()->fetch_assoc();

        if ($current_state == 0) {
            $reason = trim($_POST['legal_hold_reason']);
            if (empty($reason)) redirectDocumentsWithMessage("error", "Reason is required for Legal Hold.");
            
            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_legal_hold = 1, legal_hold_reason = ?, legal_hold_by = ?, legal_hold_at = NOW() WHERE doc_id = ?");
            $upd->bind_param("sii", $reason, $uid, $doc_id);
            $upd->execute();
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'APPLY_LEGAL_HOLD', "Applied Legal Hold on Document: " . $doc_info['file_name'] . " (Reason: $reason)");
            
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Legal Hold applied successfully. Standard retention policies are now overridden."));
            exit();
        } else {
            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_legal_hold = 0, legal_hold_reason = NULL, legal_hold_by = NULL, legal_hold_at = NULL WHERE doc_id = ?");
            $upd->bind_param("i", $doc_id);
            $upd->execute();
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'REMOVE_LEGAL_HOLD', "Removed Legal Hold from Document: " . $doc_info['file_name']);
            
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Legal Hold removed successfully. Auto-deletion/archiving logic restored."));
            exit();
        }
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

if (!function_exists('getSubFolderCount')) {
    function getSubFolderCount($sub_name, $db_counts) {
        foreach ($db_counts as $cat => $count) {
            if (strcasecmp($cat, $sub_name) === 0) {
                return $count;
            }
        }
        return 0;
    }
}

if (!function_exists('getParentFolderCount')) {
    function getParentFolderCount($parent_name, $parent_folders, $db_counts) {
        $total = 0;
        if (isset($parent_folders[$parent_name])) {
            foreach ($parent_folders[$parent_name] as $sub) {
                $total += getSubFolderCount($sub, $db_counts);
            }
        }
        return $total;
    }
}

// ==========================================
// PARAMETERS & FILTERS & SORTING
// ==========================================
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$parent_filter = $_GET['parent'] ?? '';
$doc_status = $_GET['doc_status'] ?? ''; 
$view_disposition = isset($_GET['disposition']) && $_GET['disposition'] == '1';
$view_archives = isset($_GET['view_archives']) && $_GET['view_archives'] == '1';
$view_shared = isset($_GET['shared']) && $_GET['shared'] == '1';
$sort = $_GET['sort'] ?? 'date_desc';

$order_by = "d.uploaded_at DESC";
if ($sort === 'date_asc') $order_by = "d.uploaded_at ASC";
elseif ($sort === 'name_asc') $order_by = "d.file_name ASC";
elseif ($sort === 'name_desc') $order_by = "d.file_name DESC";

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
    
    if (!empty($type_filter)) {
        $type_url_params = [];
        if ($view_archives) $type_url_params[] = 'view_archives=1';
        if (!empty($parent_filter) && $is_top_mgmt) $type_url_params[] = 'parent=' . urlencode($parent_filter);
        $type_url_params[] = 'type=' . urlencode($type_filter);
        
        $type_url = 'documents.php?' . implode('&', $type_url_params);
        $breadcrumbs[] = ['label' => htmlspecialchars($type_filter), 'url' => $type_url, 'active' => true];
    }
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

$where = [];
if ($view_archives) {
    $where[] = "d.status = 'Archived'";
} else {
    $where[] = "d.status = 'Active'";
}

$params = [];
$types = "";

if (!empty($search)) {
    $where[] = "(d.file_name LIKE ? OR d.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($view_shared) {
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
          ORDER BY $order_by";

$stmt = $conn->prepare($query);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$documents = $stmt->get_result();

$toastMsg = '';
$toastType = '';
if(isset($_GET['success'])) {
    $toastType = 'success';
    $toastMsg = htmlspecialchars($_GET['success']);
} elseif(isset($_GET['error'])) {
    $toastType = 'error';
    $toastMsg = htmlspecialchars($_GET['error']);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
        /* ==============================================================
           "APP-LIKE" STRICT FLEXBOX LAYOUT OVERRIDE 
           Ensures NO page scroll. Only table scrolls, pagination is fixed.
        ============================================================== */
        body.bg-f8f9fa {
            overflow: hidden !important; 
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            height: 100vh !important;
            max-height: 100vh !important;
            padding-top: 75px !important; /* Space for the global top navbar */
            padding-bottom: 15px !important;
            overflow: hidden !important;
            background-color: #f8f9fa;
        }

        /* The Header & Filter areas */
        .header-section {
            flex-shrink: 0;
            z-index: 10;
        }

        /* Folders (if any) */
        .folders-section {
            flex-shrink: 0;
            /* Wala ng scroll at max-height dito para sumunod sa content */
        }
        
        /* The main container holding the table */
        .file-list-container {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-height: 0; /* Crucial for flexbox scrolling inside */
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 0 !important;
        }

        /* DataTables wrapper config */
        .dataTables_wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }

        /* Table scroller area */
        .table-scroll-container {
            flex-grow: 1;
            overflow-y: auto;
            overflow-x: auto;
            min-height: 0;
        }
        .table-scroll-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-scroll-container::-webkit-scrollbar-track { background: #f8f9fa; }
        .table-scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .table-scroll-container::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Table rules inside scroll container */
        #documentsTable {
            margin: 0 !important;
            width: 100% !important;
            border-collapse: collapse !important;
        }
        #documentsTable thead th {
            position: sticky;
            top: 0;
            background: #f8f9fa !important;
            z-index: 5;
            border-bottom: 1px solid #e2e8f0 !important;
            box-shadow: 0 1px 0 #e2e8f0; 
        }

        /* Fixed Pagination Bar at Bottom using CSS Grid for exact placement */
        .bottom-pagination-bar {
            flex-shrink: 0;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            padding: 10px 20px;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            font-size: 0.85rem;
            gap: 15px; /* Prevent overlap */
        }
        
        .bottom-pagination-bar .dataTables_info {
            justify-self: start;
            padding: 0 !important;
            color: #64748b !important;
            font-weight: 500;
            white-space: nowrap;
        }

        /* Length Menu Dropdown styling */
        .bottom-pagination-bar .dataTables_length {
            justify-self: center;
            color: #64748b;
            font-weight: 500;
            margin: 0;
            white-space: nowrap;
        }
        
        .bottom-pagination-bar .dataTables_length label {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bottom-pagination-bar .dataTables_length select {
            appearance: none;
            -webkit-appearance: none;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 30px 4px 12px !important; /* 30px sa right para saktong may space ang arrow at number */
            background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center; /* Pwesto ng arrow */
            background-size: 14px;
            color: #1e293b;
            outline: none;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
        }

        .bottom-pagination-bar .dataTables_length select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .bottom-pagination-bar .dataTables_paginate {
            justify-self: end;
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100%;
            overflow-x: auto; /* Para if sobrang liit ng screen, mag-s-scroll horizontally lang ang pagination */
        }
        
        .bottom-pagination-bar .dataTables_paginate::-webkit-scrollbar { display: none; }
        
        .bottom-pagination-bar .pagination {
            margin: 0 !important;
            gap: 4px;
        }
        
        .bottom-pagination-bar .page-link {
            padding: 4px 10px !important;
            border-radius: 6px !important;
            border: none !important;
            color: #475569;
            font-weight: 600;
            background-color: transparent;
        }
        
        .bottom-pagination-bar .page-item.active .page-link {
            background-color: #2563eb !important;
            color: #fff !important;
        }
        
        .bottom-pagination-bar .page-item .page-link:hover {
            background-color: #f1f5f9;
            color: #2563eb;
        }
        
        .bottom-pagination-bar .page-item.disabled .page-link {
            color: #cbd5e1 !important;
        }
    </style>
</head>
<body class="bg-f8f9fa">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <!-- SEAMLESS STATIC HEADER (No scrolling, no ugly borders) -->
    <div class="header-section mb-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
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
                <a href="documents.php?shared=1" class="btn <?php echo $view_shared ? 'btn-info text-white' : 'btn-white bg-white border text-dark'; ?> fw-medium px-3 text-nowrap shadow-sm rounded-3" title="View files explicitly shared with me">
                    <i class="fas fa-user-friends <?php echo $view_shared ? 'text-white' : 'text-info'; ?> me-2"></i> Shared with Me
                </a>
                <?php if ($can_manage && empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm rounded-3" data-bs-toggle="modal" data-bs-target="#createParentFolderModal">
                        <i class="fas fa-folder-plus me-2"></i> Create Parent Folder
                    </button>
                <?php elseif (!empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared && $can_manage): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm rounded-3" data-bs-toggle="modal" data-bs-target="#createSubFolderModal">
                        <i class="fas fa-folder-plus me-2"></i> Create Sub-folder
                    </button>
                <?php elseif (!empty($type_filter) && !$hide_upload_button): ?>
                    <button class="btn btn-primary fw-medium px-4 text-nowrap shadow-sm rounded-3" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-upload me-2"></i> Upload File
                    </button>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn bg-white border text-dark shadow-sm dropdown-toggle fw-medium px-3 rounded-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="body">
                        <i class="fas fa-cog text-dark me-2"></i> Options
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3">
                        <li>
                            <a class="dropdown-item fw-medium py-2 <?php echo $view_archives ? 'active text-dark' : 'text-dark'; ?>" href="documents.php?view_archives=1">
                                <i class="fas fa-archive text-dark me-2"></i> View Archives
                            </a>
                        </li>
                        
                        <?php if ($can_view_disposition): ?>
                        <li>
                            <a class="dropdown-item fw-medium py-2 <?php echo $view_disposition ? 'active text-dark' : 'text-dark'; ?>" href="documents.php?disposition=1">
                                <i class="fas fa-trash-alt text-dark me-2"></i> Ready for Disposition
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item fw-medium py-2 text-dark" href="#" data-bs-toggle="modal" data-bs-target="#editPoliciesModal">
                                <i class="fas fa-balance-scale text-dark me-2"></i> Edit Retention Policies
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-1">
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
            
            <form method="GET" action="documents.php" class="d-flex m-0 align-items-center">
                <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                <?php if($view_shared): ?><input type="hidden" name="shared" value="1"><?php endif; ?>
                <?php if(!empty($parent_filter)): ?><input type="hidden" name="parent" value="<?php echo htmlspecialchars($parent_filter); ?>"><?php endif; ?>
                <?php if(!empty($type_filter)): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                
                <div class="input-group input-group-sm sleek-search shadow-sm rounded-3" style="width: 380px;">
                    <span class="input-group-text bg-white border-0 text-muted"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" id="documentSearchInput" class="form-control border-0 shadow-none px-2" placeholder="Search in Drive..." value="<?php echo htmlspecialchars($search); ?>">
                    
                    <button class="btn bg-white border-0 text-muted shadow-none px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Sort options" data-bs-boundary="body">
                        <i class="fas fa-sort-amount-down"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                        <li><h6 class="dropdown-header text-uppercase fs-xs fw-bold">Sort By</h6></li>
                        <li><button type="submit" name="sort" value="date_desc" class="dropdown-item text-dark fs-sm <?php echo $sort == 'date_desc' ? 'active' : ''; ?>">Newest First</button></li>
                        <li><button type="submit" name="sort" value="date_asc" class="dropdown-item text-dark fs-sm <?php echo $sort == 'date_asc' ? 'active' : ''; ?>">Oldest First</button></li>
                        <li><button type="submit" name="sort" value="name_asc" class="dropdown-item text-dark fs-sm <?php echo $sort == 'name_asc' ? 'active' : ''; ?>">Name (A-Z)</button></li>
                        <li><button type="submit" name="sort" value="name_desc" class="dropdown-item text-dark fs-sm <?php echo $sort == 'name_desc' ? 'active' : ''; ?>">Name (Z-A)</button></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
    <!-- END HEADER -->

    <?php if (!$view_archives && !$view_disposition && !$view_shared && empty($search)): ?>
        
        <?php if (empty($parent_filter) && empty($type_filter)): ?>
            <div class="folders-section mb-2">
                <div class="row g-3">
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
                            <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-3">
                                <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window" onclick="event.stopPropagation();"><i class="fas fa-ellipsis-v"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" onclick="event.stopPropagation();">
                                    <li>
                                        <form action="documents.php" method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete this Main Folder? ALL Sub-folders inside it must be completely empty first.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_folder">
                                            <input type="hidden" name="delete_type" value="parent">
                                            <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($p); ?>">
                                            <button type="submit" class="dropdown-item fw-medium text-dark"><i class="fas fa-trash-alt text-dark me-2"></i> Delete Main Folder</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($visible_parents == 0): ?>
                        <div class="col-12 text-center py-4 bg-white border rounded-4 shadow-sm">
                            <div class="mb-2"><i class="fas fa-folder-open text-muted opacity-50 fa-3x"></i></div>
                            <h6 class="text-dark fw-bold">No Folders Assigned</h6>
                            <p class="text-muted mb-0 small">You currently do not have access to any document folders.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($parent_filter) && empty($type_filter) && isset($parent_folders[$parent_filter])): ?>
            <div class="folders-section mb-2">
                <div class="row g-3">
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
                            <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-2">
                                <button class="btn-dots bg-transparent border-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window" onclick="event.stopPropagation();"><i class="fas fa-ellipsis-v small"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width: 200px;" onclick="event.stopPropagation();">
                                    <li>
                                        <form action="documents.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this sub-folder? It must be empty.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_folder">
                                            <input type="hidden" name="delete_type" value="sub">
                                            <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($parent_filter); ?>">
                                            <input type="hidden" name="sub_name" value="<?php echo htmlspecialchars($s); ?>">
                                            <button type="submit" class="dropdown-item fw-medium text-dark"><i class="fas fa-trash-alt text-dark me-2"></i> Delete Folder</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($visible_subs == 0): ?>
                        <div class="col-12 text-center py-4 bg-white border rounded-4 shadow-sm">
                            <div class="mb-2"><i class="fas fa-folder text-muted opacity-50 fa-3x"></i></div>
                            <h6 class="text-dark fw-bold">Empty Parent Folder</h6>
                            <p class="text-muted mb-0 small">There are no sub-folders available here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($view_disposition || (!empty($type_filter)) || $view_archives || $view_shared || !empty($search)): ?>
        
        <?php if($view_disposition): ?>
            <div class="file-list-container shadow-sm">
                <table id="documentsTable" class="table table-hover align-middle mb-0 w-100">
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
                            
                            $is_legal_hold = (bool)$doc['is_legal_hold'];
                            $legal_hold_reason = htmlspecialchars($doc['legal_hold_reason'] ?? '');

                            $has_file_access = true;
                            if ($is_restricted && !$is_system_admin && !$is_mine && !isset($file_permissions['user_'.$_SESSION['user_id']])) {
                                $has_file_access = false;
                            }
                        ?>
                        <tr class="<?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center">
                                    <div class="file-icon-md bg-light text-primary me-3 border transition-all rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <?php 
                                            if(!$has_file_access) echo '<i class="fas fa-lock text-danger fs-5"></i>';
                                            elseif($is_img) echo '<i class="far fa-image text-info fs-5"></i>';
                                            elseif($ext == 'pdf') echo '<i class="fas fa-file-pdf text-danger fs-5"></i>';
                                            elseif(in_array($ext, ['doc','docx'])) echo '<i class="fas fa-file-word text-primary fs-5"></i>';
                                            elseif(in_array($ext, ['xls','xlsx'])) echo '<i class="fas fa-file-excel text-success fs-5"></i>';
                                            else echo '<i class="fas fa-file text-secondary fs-5"></i>';
                                        ?>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center">
                                            <h6 class="mb-1 text-dark fw-bold text-wrap" style="max-width: 300px;"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                            <?php if($is_restricted): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="File-Level Restriction Applied">
                                                    <i class="fas fa-user-shield"></i> Restricted
                                                </span>
                                            <?php endif; ?>
                                            <?php if($is_legal_hold): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                    <i class="fas fa-balance-scale"></i> Legal Hold
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td onclick="event.stopPropagation();">
                                <span class="badge bg-warning text-dark border border-warning px-2 py-1">
                                    <?php echo htmlspecialchars($doc['action_after_retention'] ?? 'Review required'); ?>
                                </span>
                            </td>
                            <td onclick="event.stopPropagation();">
                                <div class="text-danger fw-bold"><i class="fas fa-clock me-1"></i> <?php echo date('M d, Y', strtotime($doc['retention_date'])); ?></div>
                                <div class="text-muted small">Expired</div>
                            </td>
                            <td class="text-end pe-4" onclick="event.stopPropagation();">
                                <div class="d-flex justify-content-end gap-2">
                                    <?php if ($has_file_access): ?>
                                        <?php if ($is_legal_hold): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2" title="Action blocked by Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                <i class="fas fa-balance-scale me-1"></i> Blocked by Hold
                                            </span>
                                        <?php else: ?>
                                            <?php if (has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                            <form action="actions/upload_handler.php" method="POST" class="d-inline" onsubmit="return confirm('Confirm Archiving of this document?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                <input type="hidden" name="source" value="disposition">
                                                <button type="submit" class="btn btn-sm btn-secondary text-white fw-medium shadow-sm" title="Archive" onclick="event.stopPropagation();">
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
                                                <button type="submit" class="btn btn-sm btn-danger text-white fw-medium shadow-sm" title="Destroy File" onclick="event.stopPropagation();">
                                                    <i class="fas fa-fire me-1"></i> Destroy
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2">
                                            <i class="fas fa-ban me-1"></i> Restricted
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="file-list-container shadow-sm">
                <table id="documentsTable" class="table table-hover align-middle mb-0 w-100">
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
                            
                            $is_locked = (bool)$doc['is_locked'];
                            $locked_by = $doc['locked_by'];
                            $locked_by_name = htmlspecialchars($doc['locked_by_name'] ?? '');

                            $is_legal_hold = (bool)$doc['is_legal_hold'];
                            $legal_hold_reason = htmlspecialchars($doc['legal_hold_reason'] ?? '');
                            
                            $is_mine = ($doc['uploaded_by'] == $_SESSION['user_id']);
                            $is_lock_owner = ($locked_by == $_SESSION['user_id']);
                            $can_override_lock = in_array($_SESSION['role'], ['Admin', 'GM', 'President']);
                            $is_locked_by_other = ($is_locked && !$is_lock_owner);

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
                        <tr class="<?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center">
                                    <div class="file-icon-md bg-light text-primary me-3 border transition-all rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                        <?php 
                                            if(!$has_file_access) echo '<i class="fas fa-lock text-danger fs-5"></i>';
                                            elseif($is_img) echo '<i class="far fa-image text-info fs-5"></i>';
                                            elseif($ext == 'pdf') echo '<i class="fas fa-file-pdf text-danger fs-5"></i>';
                                            elseif(in_array($ext, ['doc','docx'])) echo '<i class="fas fa-file-word text-primary fs-5"></i>';
                                            elseif(in_array($ext, ['xls','xlsx'])) echo '<i class="fas fa-file-excel text-success fs-5"></i>';
                                            else echo '<i class="fas fa-file text-secondary fs-5"></i>';
                                        ?>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center">
                                            <h6 class="mb-1 text-dark fw-bold text-wrap" style="max-width: 300px;"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                            
                                            <?php if($is_locked): ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Currently being worked on">
                                                    <i class="fas fa-lock"></i> Locked by <?php echo $is_lock_owner ? 'You' : explode(' ', trim($locked_by_name))[0]; ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if($access_type === 'Restricted'): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Restricted Access">
                                                    <i class="fas fa-user-shield"></i> Restricted
                                                </span>
                                            <?php endif; ?>

                                            <?php if($is_legal_hold): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                    <i class="fas fa-balance-scale"></i> Legal Hold
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td onclick="event.stopPropagation();">
                                <?php if($doc['po_id']): ?>
                                    <?php if($can_view_po): ?>
                                        <a href="view_po.php?id=<?php echo $doc['po_id']; ?>" class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle text-decoration-none px-2 py-1" onclick="event.stopPropagation();">
                                            <i class="fas fa-link me-1"></i> <?php echo htmlspecialchars($doc['po_number']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1">
                                            <i class="fas fa-hashtag me-1"></i> <?php echo htmlspecialchars($doc['po_number']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars($doc['client_name']); ?></div>
                                <?php else: ?>
                                    <span class="text-muted small border px-2 py-1 rounded-2 bg-light">Independent File</span>
                                <?php endif; ?>
                            </td>
                            <td onclick="event.stopPropagation();">
                                <div class="fw-medium text-dark"><?php echo htmlspecialchars($doc['full_name']); ?></div>
                            </td>
                            <td onclick="event.stopPropagation();">
                                <div class="text-dark fw-medium"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></div>
                                <div class="text-muted small"><?php echo date('h:i A', strtotime($doc['uploaded_at'])); ?></div>
                            </td>
                            <td class="text-end pe-4 position-relative">
                                <div class="action-dropdown dropdown">
                                    <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="window" onclick="event.stopPropagation();"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" onclick="event.stopPropagation();">
                                        
                                        <?php if ($has_file_access): ?>
                                            <li>
                                                <a class="dropdown-item fw-medium text-dark" href="<?php echo htmlspecialchars($doc['file_path']); ?>" download>
                                                    <i class="fas fa-download text-dark me-2"></i> Download
                                                </a>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item fw-medium text-dark" onclick="openVersionModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', <?php echo $can_edit_file ? 'true' : 'false'; ?>)">
                                                    <i class="fas fa-code-branch text-dark me-2"></i> Version History
                                                </button>
                                            </li>
                                            
                                            <?php if (!$view_archives && $can_edit_file): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            
                                            <?php if (!$is_locked): ?>
                                                <li>
                                                    <form action="documents.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="toggle_lock">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <input type="hidden" name="current_state" value="0">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                        <button type="submit" class="dropdown-item fw-medium text-dark">
                                                            <i class="fas fa-lock text-dark me-2"></i> Check-out (Lock File)
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php elseif ($is_lock_owner || $can_override_lock): ?>
                                                <li>
                                                    <form action="documents.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="toggle_lock">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <input type="hidden" name="current_state" value="1">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                        <button type="submit" class="dropdown-item fw-medium text-dark">
                                                            <i class="fas fa-unlock text-dark me-2"></i> Check-in (Unlock)
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_mine || $is_top_mgmt): ?>
                                            <li>
                                                <button type="button" class="dropdown-item fw-medium text-dark" 
                                                        onclick="openShareModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', '<?php echo htmlspecialchars($doc['access_type']); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_permissions'] ?? '{}')); ?>', '<?php echo htmlspecialchars(addslashes($doc['full_name'])); ?>')">
                                                    <i class="fas fa-user-plus text-dark me-2"></i> Share Settings
                                                </button>
                                            </li>
                                            <?php endif; ?>

                                            <?php if ($can_manage || $is_top_mgmt): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php if (!$is_legal_hold): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item fw-medium text-dark" onclick="openLegalHoldModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>')">
                                                            <i class="fas fa-balance-scale text-dark me-2"></i> Apply Legal Hold
                                                        </button>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form action="documents.php" method="POST" onsubmit="return confirm('Remove Legal Hold from this document? Standard retention policies will resume.');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="action" value="toggle_legal_hold">
                                                            <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                            <input type="hidden" name="current_state" value="1">
                                                            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                            <button type="submit" class="dropdown-item fw-medium text-dark">
                                                                <i class="fas fa-balance-scale-left text-dark me-2"></i> Remove Legal Hold
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php endif; ?>

                                            <?php if($can_edit_file): ?>
                                                <?php if($view_archives && has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="actions/upload_handler.php" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="restore">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <button type="submit" class="dropdown-item fw-medium text-dark"><i class="fas fa-undo-alt text-dark me-2"></i> Restore Active</button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                                
                                                <?php if(!$view_archives && has_permission($conn, $_SESSION['user_id'], 'can_archive_documents')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <?php if ($is_legal_hold): ?>
                                                        <span class="dropdown-item fw-medium text-muted" title="Cannot archive: Legal Hold is active" style="cursor: not-allowed;">
                                                            <i class="fas fa-archive text-muted me-2"></i> Archive File
                                                        </span>
                                                    <?php else: ?>
                                                        <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Archive this document?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="action" value="archive">
                                                            <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                            <button type="submit" class="dropdown-item fw-medium text-dark <?php echo $is_locked_by_other && !$can_override_lock ? 'disabled text-muted' : ''; ?>"><i class="fas fa-archive text-dark me-2"></i> Archive File</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </li>
                                                <?php endif; ?>

                                                <?php if(has_permission($conn, $_SESSION['user_id'], 'can_delete_documents')): ?>
                                                <li>
                                                    <?php if ($is_legal_hold): ?>
                                                        <span class="dropdown-item fw-medium text-muted" title="Cannot delete: Legal Hold is active" style="cursor: not-allowed;">
                                                            <i class="fas fa-trash-alt text-muted me-2"></i> Permanent Delete
                                                        </span>
                                                    <?php else: ?>
                                                        <form action="actions/upload_handler.php" method="POST" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                            <button type="submit" class="dropdown-item fw-medium text-dark <?php echo $is_locked_by_other && !$can_override_lock ? 'disabled text-muted' : ''; ?>"><i class="fas fa-trash-alt text-dark me-2"></i> Permanent Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </li>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <li>
                                                <span class="dropdown-item fw-medium text-muted" style="cursor: not-allowed;">
                                                    <i class="fas fa-ban text-dark me-2 opacity-75"></i> Access Restricted
                                                </span>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- NEW: VERSION CONTROL MODAL -->
<div class="modal fade sleek-modal" id="versionControlModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light border-bottom-0 pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-code-branch text-primary me-2"></i>Version Control</h5>
                    <p class="text-muted mb-0 fs-xs mt-1" id="vcFileName">document_name.pdf</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <div class="row g-4">
                    <div class="col-md-7" id="vcTimelineCol">
                        <h6 class="fw-bold text-muted mb-3 text-uppercase fs-xs letter-spacing-tight">File History</h6>
                        <div id="versionHistoryTimeline" class="pe-2" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center text-muted small py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading history...</div>
                        </div>
                    </div>
                    <div class="col-md-5" id="vcUploadSection">
                        <div class="bg-white p-3 rounded-4 shadow-sm border border-light h-100">
                            <h6 class="fw-bold text-muted mb-3 text-uppercase fs-xs letter-spacing-tight">Upload New Version</h6>
                            <form action="actions/version_handler.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="upload_version">
                                <input type="hidden" name="doc_id" id="vcDocId">
                                <input type="hidden" name="source_page" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                
                                <div class="mb-3">
                                    <div class="border border-dashed p-3 text-center rounded-3 bg-light position-relative" style="border-color: #cbd5e1 !important; border-style: dashed !important; border-width: 2px !important;">
                                        <i class="fas fa-cloud-upload-alt text-primary fs-3 mb-1 opacity-75"></i>
                                        <div class="fs-xs text-muted mb-2">Drag file or click below</div>
                                        <input type="file" name="new_document" class="form-control form-control-sm border-0 shadow-none bg-white fs-xs w-100" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted fs-xs fw-bold text-uppercase">Version Remarks</label>
                                    <textarea name="remarks" class="form-control shadow-none border-light bg-light fs-sm" rows="2" placeholder="e.g. Updated signatures, revised terms..." required style="resize: none;"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm rounded-3">
                                    <i class="fas fa-upload me-1"></i> Upload Version
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- IN-SYSTEM DOCUMENT VIEWER MODAL -->
<div class="modal fade" id="documentViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content border-0" style="background-color: rgba(15, 23, 42, 0.95); backdrop-filter: blur(8px);">
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

            <div class="modal-body p-0 d-flex justify-content-center align-items-center overflow-hidden" style="height: 100vh; touch-action: none;">
                <div id="viewerLoader" class="position-absolute text-center" style="z-index: 1040;">
                    <div class="spinner-border text-light opacity-50 mb-3" role="status" style="width: 2.5rem; height: 2.5rem; border-width: 0.15em;"></div>
                    <div class="fw-bold text-white opacity-50 text-uppercase" style="font-size: 0.75rem; letter-spacing: 2px;">Loading Document</div>
                </div>

                <div id="viewerContentWrapper" style="transition: transform 0.2s cubic-bezier(0.2, 0, 0, 1); transform-origin: center center; display:flex; justify-content:center; align-items:center; width: 100%; height: 100%;">
                    <img id="documentViewerImage" src="" draggable="false" class="shadow-lg rounded-2" style="display:none; max-width: 90vw; max-height: 85vh; object-fit: contain;" />
                    <iframe id="documentViewerFrame" src="" class="shadow-lg rounded-3 bg-white border-0" style="display:none; width: 80vw; height: 85vh;"></iframe>
                </div>
            </div>

            <div class="position-absolute bottom-0 start-50 translate-middle-x mb-4" style="z-index: 1060;" id="zoomControlsContainer">
                <div class="d-flex align-items-center border border-secondary border-opacity-50 rounded-pill px-3 py-2 shadow-lg" style="background-color: rgba(30, 41, 59, 0.95) !important;">
                    <button type="button" class="btn btn-link text-white shadow-none p-1 text-decoration-none" onclick="zoomViewer('out')" title="Zoom Out" style="opacity: 0.8;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                        <i class="fas fa-minus fs-6"></i>
                    </button>
                    <span id="viewerZoomLevel" class="text-white fw-medium px-3" style="font-size: 0.875rem; min-width: 60px; text-align: center; cursor: default;">100%</span>
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

<!-- SHARE MODAL -->
<div class="modal fade sleek-modal" id="shareDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-share-alt text-primary me-2"></i>Share Settings</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST" id="shareForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="share_document">
                    <input type="hidden" name="doc_id" id="shareDocId">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-light text-primary rounded-circle d-flex justify-content-center align-items-center me-3" style="width: 40px; height: 40px;">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="overflow-hidden">
                                <h6 class="mb-0 fw-bold text-truncate" id="shareDocName" style="max-width: 350px;">Document Name</h6>
                                <small class="text-muted" id="shareDocOwner">Owner: User</small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">General Access</label>
                        <select name="access_type" id="shareAccessType" class="form-select form-select-sm shadow-none" onchange="toggleShareList()">
                            <option value="Folder Default">Folder Default (Inherit Permissions)</option>
                            <option value="Restricted">Restricted (Only selected people)</option>
                        </select>
                        <div class="form-text small mt-1" id="shareAccessHelpText">Inherits permissions based on the parent folder's department assignment.</div>
                    </div>

                    <div id="restrictedUsersSection" class="d-none">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight mb-2 d-flex justify-content-between align-items-center">
                            <span>People with Access</span>
                        </label>
                        <div class="border rounded-3" style="max-height: 250px; overflow-y: auto;">
                            <?php foreach ($all_users as $u): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom user-share-row">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle box-32 fs-xs me-2"><?php echo strtoupper(substr($u['full_name'], 0, 1)); ?></div>
                                    <div class="lh-12">
                                        <div class="fw-bold fs-sm text-dark"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                        <div class="fs-xs text-muted"><?php echo htmlspecialchars($u['role']); ?></div>
                                    </div>
                                </div>
                                <select name="user_roles[<?php echo $u['user_id']; ?>]" class="form-select form-select-sm w-auto shadow-none border-0 bg-light fs-xs fw-medium text-secondary">
                                    <option value="None">No Access</option>
                                    <option value="Viewer">Viewer</option>
                                    <option value="Editor">Editor</option>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-light sleek-btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn-sm px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- LEGAL HOLD MODAL -->
<div class="modal fade sleek-modal" id="legalHoldModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-balance-scale text-danger me-2"></i>Apply Legal Hold</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="toggle_legal_hold">
                    <input type="hidden" name="doc_id" id="holdDocId">
                    <input type="hidden" name="current_state" value="0">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    
                    <div class="alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-25 text-danger fs-sm mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i> <strong>Warning:</strong> Applying a legal hold suspends all automated archiving and disposition policies for this record. It cannot be deleted or archived until the hold is lifted.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Target Document</label>
                        <input type="text" class="form-control bg-light fs-sm" id="holdDocName" readonly>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Reason for Legal Hold <span class="text-danger">*</span></label>
                        <textarea name="legal_hold_reason" class="form-control shadow-none" rows="3" placeholder="e.g. Subpoena received, pending internal audit..." required></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light sleek-btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger sleek-btn-sm px-4">Confirm Legal Hold</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- UPLOAD MODAL -->
<?php if (!empty($type_filter) && !$hide_upload_button): ?>
<div class="modal fade sleek-modal" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light border-bottom-0 pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-cloud-upload-alt text-primary me-2"></i>Upload Record</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Indexing to: <span class="fw-bold text-primary"><?php echo htmlspecialchars($type_filter); ?></span></p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <form action="actions/upload_handler.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($type_filter); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase">Select File <span class="text-danger">*</span></label>
                        <div class="border border-dashed p-4 text-center rounded-3 bg-white position-relative" style="border-color: #cbd5e1 !important; border-style: dashed !important; border-width: 2px !important;">
                            <i class="fas fa-file-import text-primary fs-2 mb-2 opacity-75"></i>
                            <div class="fs-sm text-dark fw-medium mb-1">Click to browse or drag file here</div>
                            <div class="fs-xs text-muted mb-3">PDF, DOCX, XLSX, JPG, PNG (Max 5MB)</div>
                            <input type="file" name="document" class="form-control border-0 shadow-none bg-light fs-sm mx-auto" required style="max-width: 250px;">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase">Initial Sharing Access</label>
                        <select name="initial_access" class="form-select shadow-none border-light bg-white fs-sm">
                            <option value="Folder Default">Folder Default (Inherit Permissions)</option>
                            <option value="Restricted">Restricted (Only me for now)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm rounded-3 py-2">
                        <i class="fas fa-check-circle me-1"></i> Upload and Index File
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- EDIT RETENTION POLICIES MODAL -->
<?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
<div class="modal fade sleek-modal" id="editPoliciesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light border-bottom-0 pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-balance-scale text-primary me-2"></i>Retention Policies</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Configure legal compliance and data lifecycle rules.</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <div class="table-responsive">
                    <table class="table table-hover bg-white border rounded-3 overflow-hidden shadow-sm">
                        <thead class="bg-light">
                            <tr>
                                <th class="fs-xs text-muted text-uppercase fw-bold border-0">Policy Name</th>
                                <th class="fs-xs text-muted text-uppercase fw-bold border-0">Retention Period</th>
                                <th class="fs-xs text-muted text-uppercase fw-bold border-0">Action After Expiry</th>
                                <th class="fs-xs text-muted text-uppercase fw-bold border-0 text-end">Edit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($policies as $pol): ?>
                            <tr>
                                <td class="fw-medium text-dark align-middle border-light"><?php echo htmlspecialchars($pol['policy_name']); ?></td>
                                <td class="align-middle border-light"><span class="badge bg-primary bg-opacity-10 text-primary px-2"><?php echo $pol['retention_years']; ?> Years</span></td>
                                <td class="align-middle border-light"><span class="badge bg-warning bg-opacity-10 text-warning px-2"><?php echo htmlspecialchars($pol['action_after_retention']); ?></span></td>
                                <td class="text-end align-middle border-light">
                                    <button class="btn btn-sm btn-light border shadow-none" onclick="editPolicy(<?php echo $pol['policy_id']; ?>, '<?php echo htmlspecialchars(addslashes($pol['policy_name'])); ?>', <?php echo $pol['retention_years']; ?>, '<?php echo htmlspecialchars(addslashes($pol['action_after_retention'])); ?>')">
                                        <i class="fas fa-edit text-secondary"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="editPolicyFormContainer" class="d-none mt-4 p-3 border rounded-3 bg-white shadow-sm">
                    <h6 class="fw-bold text-dark mb-3 fs-sm"><i class="fas fa-pen me-2 text-primary"></i>Modify Policy</h6>
                    <form action="documents.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="edit_policy">
                        <input type="hidden" name="policy_id" id="editPolId">
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fs-xs fw-bold text-muted text-uppercase">Policy Name</label>
                                <input type="text" name="policy_name" id="editPolName" class="form-control form-control-sm shadow-none" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fs-xs fw-bold text-muted text-uppercase">Retention Years</label>
                                <input type="number" name="retention_years" id="editPolYears" class="form-control form-control-sm shadow-none" min="1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fs-xs fw-bold text-muted text-uppercase">Action After Expiry</label>
                                <select name="action_after_retention" id="editPolAction" class="form-select form-select-sm shadow-none">
                                    <option value="Review before archive">Review before archive</option>
                                    <option value="Auto-archive">Auto-archive</option>
                                    <option value="Review for permanent deletion">Review for permanent deletion</option>
                                    <option value="Auto-delete">Auto-delete</option>
                                </select>
                            </div>
                            <div class="col-12 text-end mt-3">
                                <button type="button" class="btn btn-sm btn-light border px-3" onclick="document.getElementById('editPolicyFormContainer').classList.add('d-none')">Cancel</button>
                                <button type="submit" class="btn btn-sm btn-success px-4 ms-2 shadow-sm fw-bold">Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CREATE PARENT FOLDER MODAL -->
<?php if ($can_manage && empty($parent_filter) && empty($type_filter)): ?>
<div class="modal fade sleek-modal" id="createParentFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-folder-plus text-primary me-2"></i>Create Parent Folder</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="hidden" name="parent_category" value="NEW_PARENT_FOLDER">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Main Folder Name <span class="text-danger">*</span></label>
                        <input type="text" name="new_parent_category" class="form-control shadow-none border-light bg-light" placeholder="e.g. Human Resources, Finance Dept" required>
                    </div>

                    <?php if($is_top_mgmt): ?>
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight mb-2">Assign System Roles <span class="text-danger">*</span></label>
                        <div class="border rounded-3 p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                            <?php 
                            $roles_query = $conn->query("SELECT DISTINCT role FROM users WHERE role NOT IN ('Admin', 'President', 'GM') ORDER BY role ASC");
                            if($roles_query) {
                                while($r = $roles_query->fetch_assoc()) {
                                    echo '<div class="form-check mb-2">
                                            <input class="form-check-input shadow-none" type="checkbox" name="assigned_roles[]" value="'.htmlspecialchars($r['role']).'" id="role_'.htmlspecialchars($r['role']).'">
                                            <label class="form-check-label text-dark fw-medium" for="role_'.htmlspecialchars($r['role']).'">'.htmlspecialchars($r['role']).'</label>
                                          </div>';
                                }
                            }
                            ?>
                        </div>
                        <div class="form-text fs-xs mt-1"><i class="fas fa-info-circle me-1 text-primary"></i>Admins and Executives automatically have access.</div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light sleek-btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn-sm px-4">Create Folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CREATE SUB-FOLDER MODAL -->
<?php if (!empty($parent_filter) && empty($type_filter) && $can_manage): ?>
<div class="modal fade sleek-modal" id="createSubFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-folder-plus text-primary me-2"></i>Create Sub-folder</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="documents.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="hidden" name="parent_category" value="<?php echo htmlspecialchars($parent_filter); ?>">
                    
                    <div class="alert bg-light border text-muted fs-sm mb-3">
                        Creating inside: <span class="fw-bold text-dark"><?php echo htmlspecialchars($parent_filter); ?></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Sub-folder Name <span class="text-danger">*</span></label>
                        <input type="text" name="new_folder_name" class="form-control shadow-none" placeholder="e.g. Employee Contracts, Q1 Reports" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Retention Policy <span class="text-danger">*</span></label>
                        <select name="folder_policy" class="form-select shadow-none bg-light" required>
                            <option value="">-- Select Legal Policy --</option>
                            <?php foreach ($policies as $pol): ?>
                                <option value="<?php echo $pol['policy_id']; ?>">
                                    <?php echo htmlspecialchars($pol['policy_name']); ?> (<?php echo $pol['retention_years']; ?> Years)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light sleek-btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn-sm px-4">Create Sub-folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        $('#documentsTable').DataTable({
            "order": [],
            "pageLength": 15,
            "lengthChange": true,
            "lengthMenu": [[10, 15, 25, 50, 100], [10, 15, 25, 50, 100]], 
            "searching": false, 
            "info": true,
            // DOM Structure: Table inside scroll container, Info/Length/Paginate in bottom fixed bar
            "dom": '<"table-scroll-container"t><"bottom-pagination-bar"ilp>',
            "language": {
                "emptyTable": "<div class='text-center p-5 text-muted'><i class='fas fa-folder-open fa-3x mb-3 opacity-50'></i><br><h5>No documents found</h5><p class='mb-0 fs-sm'>Upload a file to get started.</p></div>",
                "info": "Showing _START_ to _END_ of _TOTAL_ items",
                "lengthMenu": "Items per page _MENU_",
                "paginate": {
                    "previous": "<i class='fas fa-chevron-left'></i>",
                    "next": "<i class='fas fa-chevron-right'></i>"
                }
            }
        });

        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            customClass: { popup: 'small-toast shadow-sm border' }
        });

        const toastMsg = "<?php echo $toastMsg; ?>";
        const toastType = "<?php echo $toastType; ?>";

        if (toastMsg !== '') {
            Toast.fire({ icon: toastType, title: toastMsg });
            window.history.replaceState(null, null, '<?php echo $exact_return_url; ?>');
        }
    });

    let currentZoom = 1;
    let isDragging = false;
    let startX, startY, translateX = 0, translateY = 0;

    function openDocumentViewer(filePath, fileName, isImage) {
        document.getElementById('viewerFileName').innerText = fileName;
        document.getElementById('viewerDownloadBtn').href = filePath;
        document.getElementById('viewerDownloadBtn').download = fileName;
        
        const loader = document.getElementById('viewerLoader');
        const imgViewer = document.getElementById('documentViewerImage');
        const frameViewer = document.getElementById('documentViewerFrame');
        const zoomControls = document.getElementById('zoomControlsContainer');
        
        loader.style.display = 'block';
        imgViewer.style.display = 'none';
        frameViewer.style.display = 'none';
        
        currentZoom = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
        document.getElementById('viewerZoomLevel').innerText = '100%';

        if (isImage) {
            zoomControls.style.display = 'block';
            imgViewer.onload = function() { loader.style.display = 'none'; imgViewer.style.display = 'block'; };
            imgViewer.src = filePath;
            
            imgViewer.onmousedown = function(e) {
                if(currentZoom > 1) {
                    isDragging = true;
                    startX = e.clientX - translateX;
                    startY = e.clientY - translateY;
                    imgViewer.style.cursor = 'grabbing';
                }
            };
            window.onmousemove = function(e) {
                if (!isDragging) return;
                translateX = e.clientX - startX;
                translateY = e.clientY - startY;
                updateTransform();
            };
            window.onmouseup = function() {
                isDragging = false;
                imgViewer.style.cursor = currentZoom > 1 ? 'grab' : 'default';
            };
        } else {
            zoomControls.style.display = 'none';
            frameViewer.onload = function() { loader.style.display = 'none'; frameViewer.style.display = 'block'; };
            frameViewer.src = filePath + '#toolbar=0&navpanes=0&scrollbar=0'; 
        }

        new bootstrap.Modal(document.getElementById('documentViewerModal')).show();
    }

    function zoomViewer(action) {
        const img = document.getElementById('documentViewerImage');
        if (action === 'in' && currentZoom < 3) currentZoom += 0.25;
        else if (action === 'out' && currentZoom > 0.5) currentZoom -= 0.25;
        else if (action === 'reset') { currentZoom = 1; translateX = 0; translateY = 0; }
        
        document.getElementById('viewerZoomLevel').innerText = Math.round(currentZoom * 100) + '%';
        img.style.cursor = currentZoom > 1 ? 'grab' : 'default';
        
        if(currentZoom <= 1) { translateX = 0; translateY = 0; }
        updateTransform();
    }
    
    function updateTransform() {
        document.getElementById('viewerContentWrapper').style.transform = `scale(${currentZoom}) translate(${translateX/currentZoom}px, ${translateY/currentZoom}px)`;
    }

    document.getElementById('documentViewerModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('documentViewerImage').src = '';
        document.getElementById('documentViewerFrame').src = '';
        currentZoom = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
    });

    function openVersionModal(docId, fileName, canEdit) {
        document.getElementById('vcFileName').innerText = fileName;
        document.getElementById('vcDocId').value = docId;
        
        if (canEdit) {
            document.getElementById('vcUploadSection').style.display = 'block';
            document.getElementById('vcTimelineCol').classList.remove('col-md-12');
            document.getElementById('vcTimelineCol').classList.add('col-md-7');
        } else {
            document.getElementById('vcUploadSection').style.display = 'none';
            document.getElementById('vcTimelineCol').classList.remove('col-md-7');
            document.getElementById('vcTimelineCol').classList.add('col-md-12');
        }

        document.getElementById('versionHistoryTimeline').innerHTML = '<div class="text-center text-muted small py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading history...</div>';
        new bootstrap.Modal(document.getElementById('versionControlModal')).show();

        $.ajax({
            url: 'actions/version_handler.php',
            type: 'GET',
            data: { action: 'get_history', doc_id: docId },
            success: function(response) {
                let html = '<div class="vc-timeline">';
                if (response.success && response.data.length > 0) {
                    response.data.forEach(function(v, index) {
                        let isLatest = (index === 0);
                        let badgeClass = isLatest ? 'vc-badge-current' : 'vc-badge-old';
                        let badgeIcon = isLatest ? '<i class="fas fa-star" style="font-size: 8px;"></i>' : '<i class="fas fa-history" style="font-size: 8px;"></i>';
                        let actionBtn = isLatest ? '' : `<a href="${v.file_path}" download class="btn btn-sm btn-light border px-2 shadow-none py-1 fs-xs fw-medium text-dark"><i class="fas fa-download me-1 text-muted"></i>Download</a>`;
                        
                        html += `
                            <div class="vc-item">
                                <div class="vc-badge ${badgeClass}">${badgeIcon}</div>
                                <div class="vc-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge ${isLatest ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-secondary bg-opacity-10 text-secondary border border-secondary'} me-2">Version ${v.version_number}</span>
                                            <span class="fs-xs fw-bold text-dark">${v.uploaded_by_name}</span>
                                        </div>
                                        <div class="text-end">
                                            <div class="fs-xs fw-medium text-muted">${v.uploaded_at_formatted}</div>
                                            <div class="mt-1">${actionBtn}</div>
                                        </div>
                                    </div>
                                    <div class="fs-sm text-muted bg-light p-2 rounded border" style="font-style: italic;">"${v.remarks}"</div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += '<div class="text-muted fs-sm text-center py-3">No version history found.</div>';
                }
                html += '</div>';
                document.getElementById('versionHistoryTimeline').innerHTML = html;
            },
            error: function() {
                document.getElementById('versionHistoryTimeline').innerHTML = '<div class="text-danger fs-sm text-center py-3">Failed to load history.</div>';
            }
        });
    }

    function openShareModal(docId, fileName, accessType, filePermissionsStr, ownerName) {
        document.getElementById('shareDocId').value = docId;
        document.getElementById('shareDocName').innerText = fileName;
        document.getElementById('shareDocOwner').innerText = 'Owner: ' + ownerName;
        
        const accessSelect = document.getElementById('shareAccessType');
        accessSelect.value = accessType;
        
        const perms = JSON.parse(filePermissionsStr || '{}');
        
        document.querySelectorAll('select[name^="user_roles"]').forEach(select => {
            select.value = 'None'; 
            const userId = select.name.match(/\[(\d+)\]/)[1];
            if (perms['user_' + userId]) {
                select.value = perms['user_' + userId];
            }
        });

        toggleShareList();
        new bootstrap.Modal(document.getElementById('shareDocumentModal')).show();
    }

    function toggleShareList() {
        const type = document.getElementById('shareAccessType').value;
        const list = document.getElementById('restrictedUsersSection');
        const help = document.getElementById('shareAccessHelpText');
        
        if (type === 'Restricted') {
            list.classList.remove('d-none');
            help.innerText = "Only explicitly added people can access this file.";
            help.classList.add('text-danger');
            help.classList.remove('text-muted');
        } else {
            list.classList.add('d-none');
            help.innerText = "Inherits permissions based on the parent folder's department assignment.";
            help.classList.add('text-muted');
            help.classList.remove('text-danger');
            
            document.querySelectorAll('select[name^="user_roles"]').forEach(select => {
                select.value = 'None';
            });
        }
    }

    function openLegalHoldModal(docId, fileName) {
        document.getElementById('holdDocId').value = docId;
        document.getElementById('holdDocName').value = fileName;
        new bootstrap.Modal(document.getElementById('legalHoldModal')).show();
    }

    <?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
    function editPolicy(id, name, years, action) {
        document.getElementById('editPolId').value = id;
        document.getElementById('editPolName').value = name;
        document.getElementById('editPolYears').value = years;
        document.getElementById('editPolAction').value = action;
        document.getElementById('editPolicyFormContainer').classList.remove('d-none');
        document.getElementById('editPolicyFormContainer').scrollIntoView({ behavior: 'smooth' });
    }
    <?php endif; ?>
</script>
</body>
</html>