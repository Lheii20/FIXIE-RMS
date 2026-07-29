<?php 
require 'config/db_connect.php'; 
require 'config/functions.php'; 

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ==========================================
// AUTO-CLEANUP: 30-DAY RECYCLE BIN POLICY
// ==========================================
// Tahimik na buburahin ng system ang mga files na 30 days nang nasa Recycle Bin
$cleanup_stmt = $conn->query("SELECT doc_id, file_path, file_name FROM documents WHERE status = 'Recycled' AND deleted_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)");
if ($cleanup_stmt && $cleanup_stmt->num_rows > 0) {
    while ($del_doc = $cleanup_stmt->fetch_assoc()) {
        $path = $del_doc['file_path']; 
        
        // Burahin ang physical file sa server (uploads folder)
        if (file_exists($path) && is_file($path)) {
            unlink($path);
        } elseif (file_exists('../' . $path) && is_file('../' . $path)) {
            unlink('../' . $path);
        }
        
        // Burahin nang tuluyan sa database
        $hard_del = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
        $hard_del->bind_param("i", $del_doc['doc_id']);
        $hard_del->execute();
        
        // I-record sa Audit Trail bilang Automated System Action
        if (function_exists('log_audit_action')) {
            // Gumamit ng 0 o System ID para malaman na system ang nag-delete, hindi user
            $system_user_id = $_SESSION['user_id']; 
            log_audit_action($conn, $system_user_id, 'SYSTEM_AUTO_PURGE', "System Auto-Permanently Deleted document after 30 days in Recycle Bin: " . $del_doc['file_name']);
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
    $url = "general_docs.php?" . $type . "=" . urlencode($message);
    if ($parent !== '') {
        $url = "general_docs.php?parent=" . urlencode($parent) . "&" . $type . "=" . urlencode($message);
    }
    header("Location: " . $url);
    exit();
}

$policies = [];
$pol_query = $conn->query("SELECT * FROM retention_policies ORDER BY active_years ASC");
if ($pol_query) {
    while ($p = $pol_query->fetch_assoc()) {
        $policies[] = $p;
    }
}

$drawers = [];
$drawer_q = $conn->query("SELECT d.id, d.name as drawer, c.name as cabinet, r.name as room, b.name as building FROM virt_drawers d JOIN virt_cabinets c ON d.cabinet_id = c.id JOIN virt_rooms r ON c.room_id = r.id JOIN virt_buildings b ON r.building_id = b.id ORDER BY b.name, r.name, c.name, d.name");
if($drawer_q) {
    while($dr = $drawer_q->fetch_assoc()) {
        $drawers[] = $dr;
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
        if ($role === 'Admin') redirectDocumentsWithMessage("error", "System Administrators cannot modify documents.");
        
        if (!$is_top_mgmt && !$can_manage) {
            redirectDocumentsWithMessage("error", "You do not have permission to manage Legal Holds.");
        }
        $doc_id = intval($_POST['doc_id']);
        $current_state = intval($_POST['current_state']);
        $return_url = $_POST['return_url'] ?? 'general_docs.php';
        
        $stmt = $conn->prepare("SELECT file_name, rename_history FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $doc_info = $stmt->get_result()->fetch_assoc();

        // Kunin ang pangalan ng nag-a-action para sa Activity History
        $u_stmt = $conn->query("SELECT full_name FROM users WHERE user_id = ".$_SESSION['user_id']);
        $actor = $u_stmt->fetch_assoc()['full_name'] ?? 'System';
        $history = json_decode($doc_info['rename_history'] ?? '[]', true) ?: [];

        if ($current_state == 0) {
            $reason = trim($_POST['legal_hold_reason']);
            if (empty($reason)) redirectDocumentsWithMessage("error", "Reason is required for Legal Hold.");
            
            // I-record ang "Apply Hold" sa JSON
            array_unshift($history, ['type' => 'hold_apply', 'reason' => $reason, 'date' => date('Y-m-d H:i:s'), 'by' => $actor]);
            $history_json = json_encode($history);

            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_legal_hold = 1, legal_hold_reason = ?, legal_hold_by = ?, legal_hold_at = NOW(), rename_history = ? WHERE doc_id = ?");
            $upd->bind_param("sisi", $reason, $uid, $history_json, $doc_id);
            $upd->execute();
            
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'APPLY_LEGAL_HOLD', "Applied Legal Hold on Document: " . $doc_info['file_name'] . " (Reason: $reason)");
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Legal Hold applied successfully. Standard retention policies are now overridden."));
            exit();
        } else {
            // I-record ang "Remove Hold" sa JSON
            array_unshift($history, ['type' => 'hold_remove', 'date' => date('Y-m-d H:i:s'), 'by' => $actor]);
            $history_json = json_encode($history);

            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_legal_hold = 0, legal_hold_reason = NULL, legal_hold_by = NULL, legal_hold_at = NULL, rename_history = ? WHERE doc_id = ?");
            $upd->bind_param("si", $history_json, $doc_id);
            $upd->execute();
            
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'REMOVE_LEGAL_HOLD', "Removed Legal Hold from Document: " . $doc_info['file_name']);
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("Legal Hold removed successfully. Auto-deletion/archiving logic restored."));
            exit();
        }
    }
    

    if ($_POST['action'] === 'share_document') {
        if ($role === 'Admin') redirectDocumentsWithMessage("error", "System Administrators cannot share documents.");
        
        $doc_id = intval($_POST['doc_id']);
        $access_type = ($_POST['access_type'] === 'Restricted') ? 'Restricted' : 'Folder Default';
        $return_url = $_POST['return_url'] ?? 'general_docs.php';
        
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
        
        $sep = strpos($return_url, '?') !== false ? '&' : '?';
        header("Location: " . $return_url . $sep . "success=" . urlencode("Share settings updated successfully."));
        exit();
    }

    if ($_POST['action'] === 'rename_file') {
        if ($role === 'Admin') redirectDocumentsWithMessage("error", "System Administrators cannot modify documents.");
        
        $doc_id = intval($_POST['doc_id']);
        $new_name = trim($_POST['new_name']);
        $return_url = $_POST['return_url'] ?? 'general_docs.php';
        
        if(empty($new_name)) {
            header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "error=" . urlencode("Filename cannot be empty."));
            exit();
        }

        // 1. Kunin ang lumang pangalan
        $stmt = $conn->prepare("SELECT file_name, rename_history FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $doc_info = $stmt->get_result()->fetch_assoc();
        
        if ($doc_info['file_name'] !== $new_name) {
            // 2. Kunin ang pangalan ng nag-rename
            $u_stmt = $conn->query("SELECT full_name FROM users WHERE user_id = ".$_SESSION['user_id']);
            $renamer = $u_stmt->fetch_assoc()['full_name'] ?? 'System';

            // 3. I-update ang JSON history
            $history = json_decode($doc_info['rename_history'] ?? '[]', true) ?: [];
            array_unshift($history, [
                'old_name' => $doc_info['file_name'],
                'new_name' => $new_name,
                'date' => date('Y-m-d H:i:s'),
                'by' => $renamer
            ]);
            $history_json = json_encode($history);

            // 4. I-save sa database
            $upd = $conn->prepare("UPDATE documents SET file_name = ?, rename_history = ? WHERE doc_id = ?");
            $upd->bind_param("ssi", $new_name, $history_json, $doc_id);
            $upd->execute();
            
            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $_SESSION['user_id'], 'RENAME_DOCUMENT', "Renamed file from " . $doc_info['file_name'] . " to " . $new_name);
            }
        }
        
        header("Location: " . $return_url . (strpos($return_url, '?') ? '&' : '?') . "success=" . urlencode("File renamed successfully."));
        exit();
    }

    if ($_POST['action'] === 'toggle_lock') {
        if ($role === 'Admin') redirectDocumentsWithMessage("error", "System Administrators cannot lock/unlock documents.");
        
        $doc_id = intval($_POST['doc_id']);
        $current_state = intval($_POST['current_state']); 
        $target_state = $current_state ? 0 : 1;
        $return_url = $_POST['return_url'] ?? 'general_docs.php';
        
        $stmt = $conn->prepare("SELECT is_locked, locked_by, file_name, rename_history FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $doc_info = $stmt->get_result()->fetch_assoc();
        
        // Kunin ang pangalan ng nag-a-action
        $u_stmt = $conn->query("SELECT full_name FROM users WHERE user_id = ".$_SESSION['user_id']);
        $actor = $u_stmt->fetch_assoc()['full_name'] ?? 'System';
        $history = json_decode($doc_info['rename_history'] ?? '[]', true) ?: [];

        if ($target_state == 1) {
            if ($doc_info['is_locked']) {
                redirectDocumentsWithMessage("error", "File is already locked by someone else.");
            }
            
            // I-record ang "Lock"
            array_unshift($history, ['type' => 'lock', 'date' => date('Y-m-d H:i:s'), 'by' => $actor]);
            $history_json = json_encode($history);

            $uid = $_SESSION['user_id'];
            $upd = $conn->prepare("UPDATE documents SET is_locked = 1, locked_by = ?, locked_at = NOW(), rename_history = ? WHERE doc_id = ?");
            $upd->bind_param("isi", $uid, $history_json, $doc_id);
            $upd->execute();
            
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'CHECK_OUT', "Checked out (Locked) Document: " . $doc_info['file_name']);
            header("Location: " . $return_url);
            exit();
        } else {
            $uid = $_SESSION['user_id'];
            if ($doc_info['locked_by'] != $uid && !$is_top_mgmt) { 
                redirectDocumentsWithMessage("error", "Only the user who locked the file or Management can unlock it.");
            }

            // I-record ang "Unlock"
            array_unshift($history, ['type' => 'unlock', 'date' => date('Y-m-d H:i:s'), 'by' => $actor]);
            $history_json = json_encode($history);

            $upd = $conn->prepare("UPDATE documents SET is_locked = 0, locked_by = NULL, locked_at = NULL, rename_history = ? WHERE doc_id = ?");
            $upd->bind_param("si", $history_json, $doc_id);
            $upd->execute();
            
            if (function_exists('log_audit_action')) log_audit_action($conn, $uid, 'CHECK_IN', "Checked in (Unlocked) Document: " . $doc_info['file_name']);
            header("Location: " . $return_url);
            exit();
        }
    }

    if ($_POST['action'] === 'edit_policy') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
            header("Location: general_docs.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
            exit();
        }
        $policy_id = intval($_POST['policy_id']);
        $policy_name = trim($_POST['policy_name']);
        $act_years = intval($_POST['active_years']);
        $act_months = intval($_POST['active_months']);
        $arch_years = intval($_POST['archive_years']);
        $arch_months = intval($_POST['archive_months']);
        $action_after = 'Review for permanent deletion';
        
        if (($act_years + $arch_years + $act_months + $arch_months) < 1) {
            header("Location: general_docs.php?error=" . urlencode("Total retention period must be at least 1 month."));
            exit();
        }

        $stmt_edit = $conn->prepare("UPDATE retention_policies SET policy_name=?, active_years=?, active_months=?, archive_years=?, archive_months=?, action_after_retention=? WHERE policy_id=?");
        $stmt_edit->bind_param("siiiisi", $policy_name, $act_years, $act_months, $arch_years, $arch_months, $action_after, $policy_id);
        if ($stmt_edit->execute()) {
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_POLICY', "Updated Policy ID: $policy_id to $years Years ($action_after).");
            header("Location: general_docs.php?success=" . urlencode("Retention Policy updated successfully."));
            exit();
        } else {
            header("Location: general_docs.php?error=" . urlencode("Failed to update policy."));
            exit();
        }
    }

    if ($_POST['action'] === 'create_policy') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
            header("Location: general_docs.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
            exit();
        }

        $policy_name = trim($_POST['policy_name'] ?? '');
        $act_years = intval($_POST['active_years'] ?? 0);
        $act_months = intval($_POST['active_months'] ?? 0);
        $arch_years = intval($_POST['archive_years'] ?? 0);
        $arch_months = intval($_POST['archive_months'] ?? 0);
        $action_after = 'Review for permanent deletion';

        if ($policy_name === '') {
            header("Location: general_docs.php?error=" . urlencode("Policy name is required."));
            exit();
        }
        if ($act_years < 0 || $act_months < 0 || $arch_years < 0 || $arch_months < 0) {
            header("Location: general_docs.php?error=" . urlencode("Retention values must be zero or greater."));
            exit();
        }

        $stmt_create_policy = $conn->prepare("INSERT INTO retention_policies (policy_name, active_years, active_months, archive_years, archive_months, action_after_retention) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_create_policy->bind_param("siiiis", $policy_name, $act_years, $act_months, $arch_years, $arch_months, $action_after);

        if ($stmt_create_policy->execute()) {
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_POLICY', "Created Policy: $policy_name ($years Yrs, $months Mos).");
            header("Location: general_docs.php?success=" . urlencode("Retention Policy created successfully."));
            exit();
        } else {
            header("Location: general_docs.php?error=" . urlencode("Failed to create policy."));
            exit();
        }
    }

    // =============== DAGDAG NA CODE PARA SA DELETE POLICY ===============
    if ($_POST['action'] === 'delete_policy') {
        if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
            header("Location: general_docs.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
            exit();
        }

        $policy_id = intval($_POST['policy_id']);

        // 1. Suriin kung may folder na gumagamit pa ng policy na ito
        $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM document_categories WHERE policy_id = ?");
        $chk->bind_param("i", $policy_id);
        $chk->execute();
        $in_use = $chk->get_result()->fetch_assoc()['cnt'];

        if ($in_use > 0) {
            header("Location: general_docs.php?error=" . urlencode("Cannot delete policy. It is currently assigned to $in_use folder(s). Please reassign them first."));
            exit();
        }

        // 2. Kapag walang gumagamit, tuluyang burahin
        $stmt_del = $conn->prepare("DELETE FROM retention_policies WHERE policy_id = ?");
        $stmt_del->bind_param("i", $policy_id);

        if ($stmt_del->execute()) {
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'DELETE_POLICY', "Permanently deleted Retention Policy ID: $policy_id.");
            header("Location: general_docs.php?success=" . urlencode("Retention Policy deleted successfully."));
            exit();
        } else {
            header("Location: general_docs.php?error=" . urlencode("Failed to delete policy."));
            exit();
        }
    }
    // ====================================================================

    // ==========================================
    // ACTION: UPDATE FOLDER KEYWORDS
    // ==========================================
    // ==========================================
    // ACTION: FETCH & UPDATE FOLDER KEYWORDS
    // ==========================================
    
    // 1. FETCH (AJAX) - Kukuha ng existing keywords para ipakita sa text box
    if (isset($_POST['action']) && $_POST['action'] === 'get_keywords') {
        header('Content-Type: application/json');
        $cat = trim($_POST['category'] ?? '');
        
        // STRICT: Hahanapin lang eksakto ang folder name
        $stmt = $conn->prepare("SELECT classification_keywords FROM document_categories WHERE sub_category = ? LIMIT 1");
        $stmt->bind_param("s", $cat);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'keywords' => $row['classification_keywords']]);
        } else {
            echo json_encode(['status' => 'none']);
        }
        exit(); // Pipigilan nitong mag-load ang HTML para pure JSON lang ang ibato
    }

    // 2. UPDATE (FORM SUBMIT) - Magse-save ng bago o in-edit na keywords
    if (isset($_POST['action']) && $_POST['action'] === 'update_keywords') {
        $cat_name = trim($_POST['category_name'] ?? '');
        $keywords = trim($_POST['classification_keywords'] ?? '');
        
        if (!empty($cat_name)) {
            $params = $_GET; 
            unset($params['success'], $params['error']); // Linisin ang mga lumang notifications
            
            // --- DAGDAG: KEYWORD CONFLICT VALIDATION (PURE DATABASE ONLY) ---
            $input_keys = array_filter(array_map('trim', array_map('strtolower', explode(',', $keywords))));
            $duplicate_found = false;
            $duplicate_word = '';
            $conflict_folder = '';

            if (!empty($input_keys)) {
                // I-check laban sa existing keywords sa Database (Ibang Folders)
                $chk_query = $conn->prepare("SELECT sub_category, classification_keywords FROM document_categories WHERE sub_category != ? AND classification_keywords IS NOT NULL AND classification_keywords != ''");
                $chk_query->bind_param("s", $cat_name);
                $chk_query->execute();
                $chk_res = $chk_query->get_result();

                while ($row = $chk_res->fetch_assoc()) {
                    $db_keys = array_filter(array_map('trim', array_map('strtolower', explode(',', $row['classification_keywords']))));
                    foreach ($input_keys as $ik) {
                        if (in_array($ik, $db_keys)) {
                            $duplicate_found = true; $duplicate_word = $ik; $conflict_folder = $row['sub_category'];
                            break 2;
                        }
                    }
                }
            }

            // Kung may nag-conflict, ibato ang ERROR TOAST. Kung wala, i-save sa database.
            if ($duplicate_found) {
                $params['error'] = "Conflict: The keyword '" . strtoupper($duplicate_word) . "' is already used in '" . $conflict_folder . "'. Duplicates are not allowed.";
            } else {
                // STRICT: I-u-update lang eksakto ang sub-folder
                $stmt_update = $conn->prepare("UPDATE document_categories SET classification_keywords = ? WHERE sub_category = ?");
                $stmt_update->bind_param("ss", $keywords, $cat_name);
                $stmt_update->execute();
                
                $params['success'] = "Keywords successfully updated for " . $cat_name;
            }
            // -------------------------------------------
            
            $new_qs = http_build_query($params);
            header("Location: general_docs.php?" . $new_qs);
            exit();
        }
    }
    // ==========================================

    if ($_POST['action'] === 'create_folder') {
        $parent = trim($_POST['parent_category'] ?? '');
        $sub = trim($_POST['new_folder_name'] ?? '');
        $folder_policy = !empty($_POST['folder_policy']) ? intval($_POST['folder_policy']) : null;
        $is_new_parent = ($parent === 'NEW_PARENT_FOLDER');
        $keywords = trim($_POST['classification_keywords'] ?? ''); // Kukunin ang keywords mula sa UI
        
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

        $drawer_id = ($is_new_parent || empty($_POST['drawer_id'])) ? null : intval($_POST['drawer_id']);
        $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, policy_id, classification_keywords, drawer_id) VALUES (?, ?, ?, ?, ?)");
        $stmt_create->bind_param("ssisi", $parent, $sub, $folder_policy, $keywords, $drawer_id);
        
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

    // =============== DAGDAG NA CODE PARA SA EDIT FOLDER POLICY ===============
    if ($_POST['action'] === 'edit_folder_policy') {
        if (!$can_manage && !$is_top_mgmt) {
            redirectDocumentsWithMessage("error", "You do not have permission to edit folders.", $_POST['parent_name']);
        }
        $parent_name = trim($_POST['parent_name']);
        $sub_name = trim($_POST['sub_name']);
        $new_policy_id = intval($_POST['new_policy_id']);

        if ($new_policy_id <= 0) {
            redirectDocumentsWithMessage("error", "Invalid policy selected.", $parent_name);
        }

        // I-update ang policy id ng sub-category sa database
        $stmt_upd = $conn->prepare("UPDATE document_categories SET policy_id = ? WHERE parent_category = ? AND sub_category = ?");
        $stmt_upd->bind_param("iss", $new_policy_id, $parent_name, $sub_name);
        if ($stmt_upd->execute()) {
            if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_FOLDER', "Changed retention policy for folder: $sub_name under $parent_name");
            redirectDocumentsWithMessage("success", "Folder retention policy updated successfully.", $parent_name);
        } else {
            redirectDocumentsWithMessage("error", "Failed to update folder policy.", $parent_name);
        }
    }
    // =========================================================================

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
                    header("Location: general_docs.php?success=" . urlencode("Main Folder deleted successfully."));
                    exit();
                } else {
                    header("Location: general_docs.php?error=" . urlencode("Cannot delete Main Folder. Make sure ALL Sub-folders are empty."));
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
                    header("Location: general_docs.php?parent=" . urlencode($parent_name) . "&success=" . urlencode("Sub-folder deleted successfully."));
                    exit();
                } else {
                    header("Location: general_docs.php?parent=" . urlencode($parent_name) . "&error=" . urlencode("Cannot delete folder. The folder must be completely empty."));
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
    $count_sql = "SELECT category, COUNT(*) as cnt FROM documents WHERE status = 'Active' AND (record_phase = 'Working' OR record_phase = 'For Review' OR record_phase = 'Converted' OR record_phase IS NULL) AND category IN ($placeholders) GROUP BY category";
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
$view_recycled = isset($_GET['view_recycled']) && $_GET['view_recycled'] == '1';
$sort = $_GET['sort'] ?? 'date_desc';

$order_by = "d.uploaded_at DESC";
if ($sort === 'date_asc') $order_by = "d.uploaded_at ASC";
elseif ($sort === 'name_asc') $order_by = "d.file_name ASC";
elseif ($sort === 'name_desc') $order_by = "d.file_name DESC";

// ----------------------------------------------------
// SYSTEM ADMINISTRATOR HARD REDIRECT
// ----------------------------------------------------
if ($role === 'Admin') {
    if ($view_disposition || $view_archives || $view_shared) {
        header("Location: general_docs.php?error=" . urlencode("Unauthorized Access: System Administrators are restricted from viewing document directories."));
        exit();
    }
}

if ($view_disposition && !$can_view_disposition && $role !== 'Admin') {
    header("Location: general_docs.php?error=" . urlencode("Unauthorized Access: You do not have permission to view documents ready for disposition."));
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

$exact_return_url = "general_docs.php" . (!empty($return_params) ? "?" . implode("&", $return_params) : "");

$page_title = "Company Files (Working Documents)";
$page_subtitle = "Drafts, temporary files, and documents for approval.";
$show_back_btn = false;
$back_url = "general_docs.php";

if ($view_disposition) {
    $page_title = "Ready for Disposition";
    $page_subtitle = "These documents have reached the end of their legal retention period.";
    $show_back_btn = true;
} elseif ($view_archives) {
    $page_title = "Archived Company Files";
    $page_subtitle = "Historical and inactive documents. Search or restore if needed.";
    $show_back_btn = true;
} elseif ($view_recycled) {
    $page_title = "Recycle Bin";
    $page_subtitle = "Soft-deleted working documents. Restore or permanently delete.";
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
$breadcrumbs[] = ['label' => 'Company Files', 'url' => 'general_docs.php', 'active' => empty($view_archives) && empty($view_disposition) && empty($view_shared) && empty($parent_filter) && empty($type_filter)];

if ($view_archives) {
    $breadcrumbs[] = ['label' => 'Archived', 'url' => 'general_docs.php?view_archives=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_disposition) {
    $breadcrumbs[] = ['label' => 'Ready for Disposition', 'url' => 'general_docs.php?disposition=1', 'active' => empty($parent_filter) && empty($type_filter)];
} elseif ($view_shared) {
    $breadcrumbs[] = ['label' => 'Shared with Me', 'url' => 'general_docs.php?shared=1', 'active' => true];
}

if (!empty($parent_filter)) {
    $parent_url = $view_archives ? 'general_docs.php?view_archives=1' : 'general_docs.php';
    $breadcrumbs[] = ['label' => htmlspecialchars($parent_filter), 'url' => $parent_url . ($view_archives ? '&parent=' : '?parent=') . urlencode($parent_filter), 'active' => empty($type_filter)];
    
    if (!empty($type_filter)) {
        $type_url_params = [];
        if ($view_archives) $type_url_params[] = 'view_archives=1';
        if (!empty($parent_filter) && $is_top_mgmt) $type_url_params[] = 'parent=' . urlencode($parent_filter);
        $type_url_params[] = 'type=' . urlencode($type_filter);
        
        $type_url = 'general_docs.php?' . implode('&', $type_url_params);
        $breadcrumbs[] = ['label' => htmlspecialchars($type_filter), 'url' => $type_url, 'active' => true];
    }
}

if (empty($view_archives) && empty($view_disposition) && empty($view_shared) && empty($parent_filter) && empty($type_filter)) {
    $breadcrumbs[0]['active'] = true;
}

$hide_upload_button = $view_archives || $view_disposition || $view_shared || $view_recycled;
if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents') || $role === 'Admin') {
    $hide_upload_button = true;
}

// ==========================================
// DOCUMENTS QUERIES (UNIFIED RBAC & SHARE CHECK)
// ==========================================
$disposition_docs = null;
if ($view_disposition) {
    $disp_where = ["(d.disposition_status = 'Ready for Disposition' OR DATE_ADD(DATE_ADD(d.uploaded_at, INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR), INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH) <= NOW())"];
    $disp_where[] = "d.is_legal_hold = 0";
    
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
               DATE_ADD(DATE_ADD(d.uploaded_at, INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR), INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH) AS retention_date,
               locker.full_name AS locked_by_name
        FROM documents d
        LEFT JOIN document_categories dc ON d.category = dc.sub_category
        LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id
        LEFT JOIN users u ON d.uploaded_by = u.user_id
        LEFT JOIN users locker ON d.locked_by = locker.user_id
        WHERE $disp_where_clause
        ORDER BY retention_date ASC";
        
    // SECURITY: Prevent backend from fetching actual documents if role is Admin
    if ($role !== 'Admin') {
        $stmt_disp = $conn->prepare($disp_query_sql);
        if (!empty($disp_params)) $stmt_disp->bind_param($disp_types, ...$disp_params);
        $stmt_disp->execute();
        $disposition_docs = $stmt_disp->get_result();
    }
}

$view_recycled = isset($_GET['view_recycled']) && $_GET['view_recycled'] == '1';

$where = [];
if ($view_archives) {
    $where[] = "d.status = 'Archived'";
} elseif ($view_recycled) {
    $where[] = "d.status = 'Recycled'";
} else {
    $where[] = "d.status = 'Active'";
}
$where[] = "(d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase = 'Converted' OR d.record_phase IS NULL)"; // STRICT ENFORCEMENT

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

// Tanging Working, For Review, at Converted files lang ang lalabas dito sa Company Files
if (!$view_archives && !$view_disposition && !$view_shared) {
    $where[] = "(d.record_phase = 'Working' OR d.record_phase = 'For Review' OR d.record_phase = 'Converted' OR d.record_phase IS NULL)";
}

$whereClause = implode(' AND ', $where);

$query = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name, locker.full_name AS locked_by_name,
                 vdl.status AS physical_status, dc.drawer_id, dc.id AS cat_id
          FROM documents d
          LEFT JOIN purchase_orders p ON d.po_id = p.po_id
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          LEFT JOIN users locker ON d.locked_by = locker.user_id
          LEFT JOIN virt_document_locations vdl ON d.doc_id = vdl.document_id
          LEFT JOIN document_categories dc ON d.category = dc.sub_category
          WHERE $whereClause 
          ORDER BY $order_by";

$documents = null;
// SECURITY: Do not fetch actual documents if Admin
if ($role !== 'Admin') {
    $stmt = $conn->prepare($query);
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $documents = $stmt->get_result();
}

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
    <title>Company Files - Fixie DRMS</title>
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
        html, body.bg-f8f9fa {
            height: 100vh !important;
            max-height: 100vh !important;
            overflow: hidden !important; /* SAPILITANG PIPIGILAN ANG SCROLL NG BUONG PAGE */
            margin: 0 !important;
            padding: 0 !important;
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
            flex: 0 0 auto !important; /* Naka-fix ang height, hindi pwedeng lumiit o lumaki */
            z-index: 20; 
            position: relative;
        }

        /* Folders (if any) */
        .folders-section {
            flex: 0 0 auto !important; /* Naka-fix ang height, hindi pwedeng lumiit o lumaki */
            max-height: 28vh; /* Kung dumami ang folder, magkakaroon ito ng sariling scroll para hindi itulak pababa ang table */
            overflow-y: auto;
            overflow-x: hidden;
        }
        .folders-section::-webkit-scrollbar { width: 6px; }
        .folders-section::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        
        /* The main container holding the table */
        .file-list-container {
            flex: 1 1 0 !important; /* STRICT FLEX: Uukopa lamang sa natitirang space ng screen! */
            display: flex;
            flex-direction: column;
            min-height: 0 !important; /* CRITICAL: Pumipigil na lumagpas sa screen kahit dumami pa ang laman */
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 0 !important;
        }

        /* DataTables wrapper config */
        .dataTables_wrapper {
            flex: 1 1 0 !important;
            display: flex;
            flex-direction: column;
            min-height: 0 !important;
        }

        /* Table scroller area */
        .table-scroll-container {
            flex: 1 1 0 !important;
            overflow-y: auto !important; /* DITO LAMANG PWEDENG MAG-SCROLL ANG USER */
            overflow-x: auto !important;
            min-height: 0 !important;
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

        .folder-card {
            z-index: 1; 
            position: relative;
        }
        .folder-card:hover {
            z-index: 10 !important; /* Aangat kapag hinover */
        }
        .folder-card:focus-within {
            z-index: 15 !important; /* MAS MATAAS para hinding-hindi matatabunan ng hover kapag nakabukas ang dropdown */
        }
        .action-dropdown .dropdown-menu {
            z-index: 1050 !important; 
        }

        /* SLEEK 3-DOTS HOVER INDICATOR */
        .btn-dots, .hover-circle {
            border-radius: 50% !important;
            transition: background-color 0.2s ease !important;
        }
        .btn-dots:hover, .hover-circle:hover {
            background-color: rgba(100, 116, 139, 0.15) !important; /* Subtle gray hover */
        }

        /* DAGDAG: Sleek Hover Effects para sa Upload Modal */
        .upload-tile-btn {
            transition: all 0.2s ease-in-out;
        }
        .upload-tile-btn:hover {
            background-color: #f8fafc !important;
            border-color: #cbd5e1 !important;
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important;
        }
        .modal-btn-hover {
            transition: all 0.2s ease;
        }
        .modal-btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,.15) !important;
        }
        /* Custom Dropdown styling */
        .custom-select-btn {
            border: 1px solid #e2e8f0;
            background-color: #ffffff;
            transition: all 0.2s ease;
        }
        .custom-select-btn:hover, .custom-select-btn:focus {
            border-color: #94a3b8;
            box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15);
        }

        /* GDrive Style Row Highlight */
        tr.row-highlighted > td {
            background-color: #f8fafc !important; /* Light Slate background */
            transition: all 0.2s ease;
        }
        tr.row-highlighted > td:first-child {
            box-shadow: inset 4px 0 0 0 #3b82f6 !important; /* Blue indicator line sa gilid */
        }

        /* Highlighting target physical file */
        @keyframes pulseTarget {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); background-color: #eff6ff; border-color: #3b82f6; }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); background-color: #ffffff; border-color: #e2e8f0; }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); background-color: #ffffff; border-color: #e2e8f0; }
        }
        .highlight-target-file {
            animation: pulseTarget 2s ease-out 3; /* Mag-pu-pulse ito nang 3 beses */
            border-left: 4px solid #3b82f6 !important;
            background-color: #f8fafc;
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
                
                <!-- GLOBAL UPLOAD BUTTON (Sleek, Prominent, Pill-shaped) -->
                <?php if (!$hide_upload_button): ?>
                    <button class="btn btn-primary fw-bold px-4 py-2 shadow-sm rounded-pill d-flex align-items-center transition-all" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-cloud-upload-alt me-2 fs-6"></i> <span>Upload File</span>
                    </button>
                <?php endif; ?>

                <!-- 3-DOTS OPTIONS MENU -->
                <div class="dropdown">
                    <button class="btn bg-transparent border-0 shadow-none d-flex align-items-center justify-content-center hover-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="body" style="width: 40px; height: 40px; transition: all 0.2s;" title="More Actions">
                        <i class="fas fa-ellipsis-v text-dark fs-5"></i>
                    </button>
                    
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 mt-2 border-0" style="min-width: 220px;">
                        
                        <!-- Folder Creation Buttons Moved Inside -->
                        <?php if ($can_manage && empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared && !$view_recycled): ?>
                            <li>
                                <button type="button" class="dropdown-item fw-medium py-2 text-dark" data-bs-toggle="modal" data-bs-target="#createParentFolderModal">
                                    <i class="fas fa-folder-plus text-primary me-2 w-15px"></i> New Main Folder
                                </button>
                            </li>
                        <?php elseif (!empty($parent_filter) && empty($type_filter) && !$view_archives && !$view_disposition && !$view_shared && !$view_recycled && $can_manage): ?>
                            <li>
                                <button type="button" class="dropdown-item fw-medium py-2 text-dark" data-bs-toggle="modal" data-bs-target="#createSubFolderModal">
                                    <i class="fas fa-folder-plus text-primary me-2 w-15px"></i> New Sub-folder
                                </button>
                            </li>
                        <?php endif; ?>

                        <!-- Shared With Me at Recycle Bin -->
                        <?php if ($role !== 'Admin'): ?>
                            <li>
                                <a class="dropdown-item fw-medium py-2 <?php echo $view_shared ? 'active text-white bg-info' : 'text-dark'; ?>" href="general_docs.php?shared=1">
                                    <i class="fas fa-user-friends <?php echo $view_shared ? 'text-white' : 'text-info'; ?> me-2 w-15px"></i> Shared with Me
                                </a>
                            </li>
                            
                            <li>
                                <a class="dropdown-item fw-medium py-2 <?php echo isset($view_recycled) && $view_recycled ? 'active text-white bg-danger' : 'text-dark'; ?>" href="general_docs.php?view_recycled=1">
                                    <i class="fas fa-trash-restore <?php echo isset($view_recycled) && $view_recycled ? 'text-white' : 'text-danger'; ?> me-2 w-15px"></i> Recycle Bin
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
            
            <form method="GET" action="general_docs.php" class="d-flex m-0 align-items-center">
                <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                <?php if($view_shared): ?><input type="hidden" name="shared" value="1"><?php endif; ?>
                <?php if(!empty($parent_filter)): ?><input type="hidden" name="parent" value="<?php echo htmlspecialchars($parent_filter); ?>"><?php endif; ?>
                <?php if(!empty($type_filter)): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                
                <div class="input-group input-group-sm sleek-search shadow-sm rounded-3" style="width: 380px;">
                    <span class="input-group-text bg-white border-0 text-muted"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" id="documentSearchInput" class="form-control border-0 shadow-none px-2" placeholder="Search" value="<?php echo htmlspecialchars($search); ?>">
                    
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

    <?php if (!$view_archives && !$view_disposition && !$view_shared && !$view_recycled && empty($search)): ?>
         
        <?php if (empty($parent_filter) && empty($type_filter)): ?>
            <div class="folders-section mt-3 mb-2">
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
                        <div class="folder-card p-3 h-100 position-relative" onclick="window.location='general_docs.php?parent=<?php echo urlencode($p); ?>'">
                            <div class="d-flex align-items-center">
                                <div class="folder-icon-box bg-light text-primary border">
                                    <i class="fas fa-folder fa-lg"></i>
                                </div>
                                <div class="ms-3 flex-grow-1">
                                    <h6 class="mb-1 fw-bold text-dark text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($p); ?></h6>
                                    <p class="text-muted small mb-0"><i class="fas fa-file-alt me-1"></i><?php echo $role === 'Admin' ? 'Restricted' : $fileCount . ' active files'; ?></p>
                                </div>
                            </div>
                            <?php if($can_manage): ?>
                            <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-3">
                                <button class="btn-dots dropdown-toggle border-0 bg-transparent shadow-none" type="button" data-bs-toggle="dropdown" data-bs-boundary="body" onclick="event.stopPropagation();"><i class="fas fa-ellipsis-v"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-1" onclick="event.stopPropagation();">
                                    <li>
                                        <form action="general_docs.php" method="POST" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_folder">
                                            <input type="hidden" name="delete_type" value="parent">
                                            <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($p); ?>">
                                            <button type="button" class="dropdown-item fw-medium text-dark" onclick="confirmFolderDelete(this, 'main')"><i class="fas fa-trash-alt text-danger me-2"></i> Delete Main Folder</button>
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
            <div class="folders-section mt-3 mb-2">
                <div class="row g-3">
                    <?php 
                    $subs = $parent_folders[$parent_filter];
                    $visible_subs = 0;
                    foreach ($subs as $s): 
                        if (!$is_top_mgmt && !in_array($s, $user_categories)) continue;
                        $visible_subs++;
                        $fileCount = getSubFolderCount($s, $db_counts);
                        
                        // Kukunin natin ang kasalukuyang policy ng folder na ito
                        $current_pol_name = "No Policy Assigned";
                        $q_pol = $conn->prepare("SELECT p.policy_name FROM document_categories dc LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id WHERE dc.parent_category = ? AND dc.sub_category = ? LIMIT 1");
                        $q_pol->bind_param("ss", $parent_filter, $s);
                        $q_pol->execute();
                        $r_pol = $q_pol->get_result()->fetch_assoc();
                        if($r_pol && $r_pol['policy_name']) { $current_pol_name = $r_pol['policy_name']; }
                    ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="folder-card p-3 h-100 position-relative" onclick="window.location='general_docs.php?parent=<?php echo urlencode($parent_filter); ?>&type=<?php echo urlencode($s); ?>'">
                            <div class="d-flex align-items-center mb-2 pe-4">
                                <div class="folder-icon-box bg-primary bg-opacity-10 text-primary me-3 border border-primary border-opacity-25" style="width: 40px; height: 40px;">
                                    <i class="fas fa-folder-open"></i>
                                </div>
                                <h6 class="mb-0 fw-bold text-dark text-truncate flex-grow-1" style="font-size: 0.95rem;"><?php echo htmlspecialchars($s); ?></h6>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-light">
                                <span class="text-muted small fw-medium"><?php echo $role === 'Admin' ? 'Restricted' : $fileCount . ' items'; ?></span>
                                <i class="fas fa-chevron-right text-primary opacity-50 small"></i>
                            </div>
                            
                            <?php if($can_manage): ?>
                            <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2 mt-3 me-2">
                                <button class="btn-dots bg-transparent border-0 shadow-none dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="body" onclick="event.stopPropagation();"><i class="fas fa-ellipsis-v small"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-1" style="min-width: 200px;" onclick="event.stopPropagation();">
                                    
                                    <!-- DAGDAG: CHANGE POLICY BUTTON -->
                                    <li>
                                        <button type="button" class="dropdown-item fw-medium text-primary" onclick="openEditFolderModal('<?php echo htmlspecialchars(addslashes($parent_filter)); ?>', '<?php echo htmlspecialchars(addslashes($s)); ?>', '<?php echo htmlspecialchars(addslashes($current_pol_name)); ?>')">
                                            <i class="fas fa-exchange-alt text-primary me-2"></i> Change Policy
                                        </button>
                                    </li>
                                    <li>
                                        <a class="dropdown-item fw-medium py-2 text-primary" href="#" onclick="event.preventDefault(); openEditKeywordsModal('<?php echo htmlspecialchars(addslashes($s)); ?>');">
                                            <i class="fas fa-tags text-primary me-2 w-15px"></i> Edit Keywords
                                        </a>
                                    </li>
                                    
                                    <li><hr class="dropdown-divider"></li>

                                    <li>
                                        <form action="general_docs.php" method="POST" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_folder">
                                            <input type="hidden" name="delete_type" value="sub">
                                            <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($parent_filter); ?>">
                                            <input type="hidden" name="sub_name" value="<?php echo htmlspecialchars($s); ?>">
                                            <button type="button" class="dropdown-item fw-medium text-dark" onclick="confirmFolderDelete(this, 'sub')"><i class="fas fa-trash-alt text-danger me-2"></i> Delete Folder</button>
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

    <?php if ($view_disposition || (!empty($type_filter)) || $view_archives || $view_recycled || $view_shared || !empty($search)): ?>
        
        <?php if ($role === 'Admin'): ?>
            <div class="col-12 text-center py-5 bg-white border rounded-4 shadow-sm mt-3 flex-grow-1">
                <div class="mb-3"><i class="fas fa-shield-alt text-muted opacity-50 fa-4x"></i></div>
                <h5 class="text-dark fw-bold">Document Access Restricted</h5>
                <p class="text-muted mb-0">As a System Administrator, you can view and manage the folder structure, but you are not authorized to view, access, or manage the actual documents inside.</p>
            </div>
        <?php else: ?>

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
                            <?php if($disposition_docs && $disposition_docs->num_rows > 0): while($doc = $disposition_docs->fetch_assoc()): 
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
                                                <div class="d-inline-block" style="max-width: 420px;">
                                                    <h6 class="mb-0 text-dark fw-bold text-truncate d-inline-block align-middle w-100" title="<?php echo htmlspecialchars($doc['file_name']); ?>">
                                                        <?php echo htmlspecialchars($doc['file_name']); ?>
                                                    </h6>
                                                </div>
                                                
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
                                                    <i class="fas fa-balance-scale me-1"></i> Managed by Policy
                                                </span>
                                            <?php else: ?>
        <form action="actions/document_handler.php" method="POST" class="m-0">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
    <button type="button" class="btn btn-sm btn-danger fw-bold shadow-sm px-3 py-1" onclick="confirmDispositionDelete(this)">
        <i class="fas fa-trash-alt me-1"></i> Permanently Delete
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
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                
                <!-- 30-DAY AUTO PURGE WARNING INDICATOR -->
                <?php if(isset($view_recycled) && $view_recycled): ?>
                    <div class="alert alert-warning bg-warning bg-opacity-10 border-warning border-opacity-25 text-dark fs-sm mb-3 shadow-sm d-flex align-items-center" style="border-radius: 8px;">
                        <i class="fas fa-exclamation-triangle text-warning fs-4 me-3"></i> 
                        <div>
                            <strong>Recycle Bin Policy:</strong> Items in this bin will be permanently deleted automatically after 30 days.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="file-list-container shadow-sm">
                    <table id="documentsTable" class="table table-hover align-middle mb-0 w-100">
                        <thead>
                            <?php if(isset($view_recycled) && $view_recycled): ?>
                            <tr>
                                <th class="ps-4">File Name</th>
                                <th>Original Location</th>
                                <th>File Size</th>
                                <th>Uploader</th>
                                <th>Date Deleted</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <th class="ps-4">File Name</th>
                                <th>Link / Reference</th>
                                <th>Uploaded By</th>
                                <th>Date Added</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if($documents && $documents->num_rows > 0): while($doc = $documents->fetch_assoc()): 
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
                                                <h6 class="mb-0 text-dark fw-bold text-truncate d-inline-block align-middle" style="max-width: 420px;" title="<?php echo htmlspecialchars($doc['file_name']); ?>">
                                                    <?php echo htmlspecialchars($doc['file_name']); ?>
                                                </h6>
                                                
                                                <?php if($is_locked): ?>
                                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Currently being worked on">
                                                        <i class="fas fa-lock"></i> Locked by <?php echo $is_lock_owner ? 'You' : explode(' ', trim($locked_by_name))[0]; ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if($is_legal_hold): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                        <i class="fas fa-balance-scale"></i> Legal Hold
                                                    </span>
                                                <?php endif; ?>
                                                <?php if($doc['record_phase'] === 'Converted'): ?>
                                                    <span class="badge bg-secondary text-white px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;">
                                                        <i class="fas fa-lock"></i> Official Record Created
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex align-items-center mt-1">
                                                <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category'] ?: $doc['doc_type']); ?></span>
                                                <?php if ($doc['current_version'] > 1): ?>
                                                    <span class="badge bg-light text-primary border ms-2" style="font-size: 0.7rem;">v<?php echo number_format($doc['current_version'], 1); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <?php if(isset($view_recycled) && $view_recycled): ?>
                                    <!-- RECYCLE BIN COLUMNS -->
                                    <td onclick="event.stopPropagation();">
                                        <span class="text-dark fw-medium"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category'] ?: $doc['doc_type']); ?></span>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <?php 
                                            $file_size = 'Unknown';
                                            if (file_exists($doc['file_path'])) {
                                                $bytes = filesize($doc['file_path']);
                                                $kb = $bytes / 1024;
                                                $file_size = $kb >= 1024 ? number_format($kb / 1024, 2) . ' MB' : number_format($kb, 2) . ' KB';
                                            }
                                        ?>
                                        <span class="text-muted fs-sm fw-medium"><?php echo $file_size; ?></span>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <div class="fw-medium text-dark"><?php echo htmlspecialchars($doc['full_name']); ?></div>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <div class="text-danger fw-bold"><?php echo $doc['deleted_at'] ? date('M d, Y', strtotime($doc['deleted_at'])) : 'Unknown'; ?></div>
                                        <div class="text-muted small"><?php echo $doc['deleted_at'] ? date('h:i A', strtotime($doc['deleted_at'])) : ''; ?></div>
                                    </td>
                                <?php else: ?>
                                    <!-- NORMAL ACTIVE COLUMNS -->
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
                                <?php endif; ?>

                                <td class="text-end pe-4 position-relative">
                                    <div class="action-dropdown dropdown">
                                        <button class="btn-dots bg-transparent border-0 shadow-none dropdown-toggle d-inline-flex align-items-center justify-content-center" type="button" data-bs-toggle="dropdown" data-bs-display="static" style="width: 35px; height: 35px;" onclick="event.stopPropagation();">
                                            <i class="fas fa-ellipsis-v text-dark"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-1" onclick="event.stopPropagation();">
                                            
                                            <?php if (isset($view_recycled) && $view_recycled): ?>
                                                <!-- RECYCLE BIN EXCLUSIVE MENU -->
                                                <li>
                                                    <button type="button" class="dropdown-item fw-medium text-dark" 
                                                            onclick="viewFileDetails('<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['category'] ?: $doc['doc_type']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?>', '<?php echo htmlspecialchars(addslashes($doc['full_name']), ENT_QUOTES); ?>', '<?php echo base64_encode($doc['rename_history'] ?? '[]'); ?>')">
                                                        <i class="fas fa-info-circle text-primary me-2"></i> View Details
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form action="actions/document_handler.php" method="POST" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="restore_recycled">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                        <button type="submit" class="dropdown-item fw-bold text-success">
                                                            <i class="fas fa-trash-restore me-2"></i> Restore File
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php if (in_array($_SESSION['role'], ['Admin', 'President', 'GM'])): ?>
                                                <li>
                                                    <form action="actions/document_handler.php" method="POST" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="permanent_delete">
                                                        <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                        <button type="button" class="dropdown-item fw-bold text-danger" onclick="confirmPermanentDelete(this)">
                                                            <i class="fas fa-times-circle me-2"></i> Permanent Delete
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <!-- NORMAL ACTIVE FILE MENU -->
                                                <?php if ($has_file_access): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item fw-medium text-dark" 
                                                                onclick="viewFileDetails('<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['category'] ?: $doc['doc_type']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_path']), ENT_QUOTES); ?>', '<?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?>', '<?php echo htmlspecialchars(addslashes($doc['full_name']), ENT_QUOTES); ?>', '<?php echo base64_encode($doc['rename_history'] ?? '[]'); ?>')">
                                                            <i class="fas fa-info-circle text-primary me-2"></i> View Details
                                                        </button>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>

                                                    <?php if ($doc['record_phase'] === 'Converted'): ?>
                                                        <li>
                                                            <span class="dropdown-item fw-bold text-muted py-2" style="cursor: not-allowed; background-color: #f8f9fa;">
                                                                <i class="fas fa-lock text-secondary me-2"></i> Record Finalized
                                                            </span>
                                                        </li>
                                                    <?php else: ?>
                                                        <?php if ($can_edit_file && !$is_locked): ?>
                                                        <li>
                                                            <button type="button" class="dropdown-item fw-medium text-dark" onclick="renameFile(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>')">
                                                                <i class="fas fa-edit text-warning me-2"></i> Rename File
                                                            </button>
                                                        </li>
                                                        <?php endif; ?>
                                                        <li>
                                                            <a class="dropdown-item fw-medium text-dark" href="<?php echo htmlspecialchars($doc['file_path']); ?>" download>
                                                                <i class="fas fa-download text-success me-2"></i> Download
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <button type="button" class="dropdown-item fw-medium text-dark" onclick="openVersionModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', <?php echo $can_edit_file ? 'true' : 'false'; ?>)">
                                                                <i class="fas fa-code-branch text-info me-2"></i> Version History
                                                            </button>
                                                        </li>
                                                        
                                                        <?php if ($can_manage || $is_top_mgmt): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <button type="button" class="dropdown-item fw-bold text-success" onclick="openDeclareOfficialModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>')">
                                                                    <i class="fas fa-certificate me-2"></i> Declare as Official Record
                                                                </button>
                                                            </li>
                                                            <li>
                                                                <?php 
                                                                    $p_stat = $doc['physical_status'] ?? 'Stored'; 
                                                                    $p_stat = ($p_stat === 'Returned') ? 'Stored' : $p_stat;
                                                                    $stat_color = ($p_stat === 'Borrowed') ? 'text-warning' : 'text-success';
                                                                    $stat_icon = ($p_stat === 'Borrowed') ? 'fa-hand-holding' : 'fa-check-circle';
                                                                ?>
                                                                <button type="button" class="dropdown-item fw-medium text-dark" onclick="openPhysicalLocationModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', '<?php echo htmlspecialchars(addslashes($doc['category'])); ?>', '<?php echo $p_stat; ?>', '<?php echo $doc['drawer_id'] ?? ''; ?>', '<?php echo $doc['cat_id'] ?? ''; ?>')">
                                                                    <i class="fas <?php echo $stat_icon; ?> <?php echo $stat_color; ?> me-2 w-15px text-center"></i> Physical: <?php echo $p_stat; ?>
                                                                </button>
                                                            </li>
                                                            <li>
                                                                <form action="actions/document_handler.php" method="POST" class="m-0">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="doc_id" value="<?php echo $doc['doc_id']; ?>">
                                                                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                                                                    <button type="button" class="dropdown-item fw-medium text-danger" onclick="confirmSoftDelete(this)">
                                                                        <i class="fas fa-trash me-2"></i> Move to Recycle Bin
                                                                    </button>
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
    <?php endif; ?>
</div>

<?php if ($role !== 'Admin'): ?>
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
        <div class="modal-content border-0 bg-transparent">
            
            <!-- Modern Dark Glass Overlay -->
            <div class="position-absolute w-100 h-100" style="background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(5px); z-index: 1040;"></div>
            
            <!-- Sleek White Header Bar -->
            <div class="d-flex justify-content-between align-items-center px-4 py-3 position-absolute top-0 w-100 shadow-sm" style="z-index: 1060; background: #ffffff;">
                <div class="d-flex align-items-center text-dark">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 36px; height: 36px;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h6 class="fw-bold mb-0 text-truncate letter-spacing-tight" id="viewerFileName" style="max-width: 500px;">Document Preview</h6>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <a id="viewerDownloadBtn" href="#" download class="btn btn-sm btn-outline-primary fw-bold px-4 rounded-pill shadow-sm transition-all">
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                    <button type="button" class="btn btn-light rounded-circle shadow-none d-flex align-items-center justify-content-center border" data-bs-dismiss="modal" title="Close" style="width: 36px; height: 36px; transition: all 0.2s;">
                        <i class="fas fa-times fs-5 text-secondary"></i>
                    </button>
                </div>
            </div>

            <div class="modal-body p-0 d-flex justify-content-center align-items-center overflow-hidden" style="height: 100vh; touch-action: none; position: relative; z-index: 1050; padding-top: 60px !important;">
                
                <!-- Modern Loader -->
                <div id="viewerLoader" class="position-absolute text-center" style="z-index: 1040;">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem; border-width: 0.2em;"></div>
                    <div class="fw-bold text-white text-uppercase letter-spacing-tight" style="font-size: 0.85rem; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">Loading Document...</div>
                </div>

                <div id="viewerContentWrapper" style="transition: transform 0.2s cubic-bezier(0.2, 0, 0, 1); transform-origin: center center; display:flex; justify-content:center; align-items:center; width: 100%; height: 100%;">
                    <img id="documentViewerImage" src="" draggable="false" class="shadow-lg" style="display:none; max-width: 90vw; max-height: 85vh; object-fit: contain;" />
                    <iframe id="documentViewerFrame" src="" class="shadow-lg bg-white" style="display:none; width: 85vw; height: 85vh; border: none;"></iframe>
                </div>
            </div> <!-- DITO YUNG NAWAWALANG DIV KAYA NASIRA ANG SYSTEM -->

            <!-- Modern White Pill Zoom Controls -->
            <div class="position-absolute bottom-0 start-50 translate-middle-x mb-4" style="z-index: 1060;" id="zoomControlsContainer">
                <div class="d-flex align-items-center bg-white rounded-pill px-2 py-1 shadow">
                    <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none" onclick="zoomViewer('out')" title="Zoom Out">
                        <i class="fas fa-minus fs-6"></i>
                    </button>
                    <span id="viewerZoomLevel" class="text-dark fw-bold px-3 fs-sm" style="min-width: 65px; text-align: center; cursor: default;">100%</span>
                    <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none" onclick="zoomViewer('in')" title="Zoom In">
                        <i class="fas fa-plus fs-6"></i>
                    </button>
                    <div class="border-start mx-1" style="height: 18px;"></div>
                    <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none" onclick="zoomViewer('reset')" title="Fit to Screen">
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
        <div class="modal-content shadow-lg border-0" style="border-radius: 12px; overflow: hidden;">
            
            <!-- Minimalist Header -->
            <div class="modal-header border-bottom border-light pb-3 pt-4 px-4 bg-white">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="modal-title fw-bold text-dark fs-5 letter-spacing-tight">Share Document</h5>
                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                    </div>
                    <!-- Inline Document Info -->
                    <div class="d-flex align-items-center bg-f8f9fa rounded-3 p-2 px-3 border border-light">
                        <i class="fas fa-file-alt text-primary fs-5 me-3"></i>
                        <div class="overflow-hidden w-100">
                            <h6 class="mb-0 fw-bold text-dark text-truncate fs-sm" id="shareDocName" title="Document Name">Document Name</h6>
                            <div class="text-muted fs-xs fw-medium mt-1" id="shareDocOwner">Owner: User</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-body p-0 bg-white">
                <form action="general_docs.php" method="POST" id="shareForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="share_document">
                    <input type="hidden" name="doc_id" id="shareDocId">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    
                    <!-- General Access -->
                    <div class="p-4 border-bottom border-light">
                        <h6 class="fw-bold fs-xs text-muted text-uppercase letter-spacing-tight mb-3">General Access</h6>
                        <div class="d-flex align-items-start">
                            <div class="bg-light text-secondary rounded-circle d-flex justify-content-center align-items-center me-3 mt-1 flex-shrink-0 border shadow-sm" style="width: 42px; height: 42px;">
                                <i class="fas fa-link"></i>
                            </div>
                            <div class="w-100">
                                <!-- CUSTOM MODERN DROPDOWN FOR GENERAL ACCESS -->
                                <div class="dropdown custom-general-access-dropdown w-100">
                                    <!-- Hidden input na nagpapasa sa Database at binabasa ng JS -->
                                    <input type="hidden" name="access_type" id="shareAccessType" value="Folder Default">
                                    
                                    <button class="btn btn-light bg-f8f9fa text-dark fs-sm fw-bold text-start rounded-3 shadow-none d-flex justify-content-between align-items-center w-100 border border-light py-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="transition: all 0.2s;">
                                        <span id="generalAccessSelectedText">Folder Default (Inherit Permissions)</span>
                                        <i class="fas fa-chevron-down text-secondary" style="font-size: 12px;"></i>
                                    </button>
                                    
                                    <!-- Ang Floating Modern Dropdown Menu -->
                                    <ul class="dropdown-menu dropdown-menu-start shadow-lg border-0 rounded-4 mt-1 p-2 w-100">
                                        <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 mb-1" onclick="updateGeneralAccessSelection('Folder Default', 'Folder Default (Inherit Permissions)')"><i class="fas fa-folder-open text-primary me-2 w-15px"></i> Folder Default (Inherit Permissions)</button></li>
                                        <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2" onclick="updateGeneralAccessSelection('Restricted', 'Restricted (Only selected people)')"><i class="fas fa-user-shield text-danger me-2 w-15px"></i> Restricted (Only selected people)</button></li>
                                    </ul>
                                </div>
                                <div class="form-text fs-xs mt-2 text-muted fw-medium" id="shareAccessHelpText">
                                    Inherits permissions based on the parent folder's department assignment.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Restricted Users Section -->
                    <div id="restrictedUsersSection" class="d-none">
                        <div class="px-4 pt-3 pb-2 border-bottom border-light bg-light">
                            <h6 class="fw-bold fs-xs text-muted text-uppercase letter-spacing-tight mb-0">People with Access</h6>
                        </div>
                        <div class="px-2" style="max-height: 260px; overflow-y: auto;">
                            <?php foreach ($all_users as $u): ?>
                            <div class="d-flex justify-content-between align-items-center p-3 border-bottom border-light hover-bg-light transition-all rounded-2 my-1 mx-1">
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold text-white shadow-sm" style="width: 36px; height: 36px; font-size: 13px; background-color: #64748b;">
                                        <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="lh-sm">
                                        <div class="fw-bold fs-sm text-dark mb-1"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                        <div class="fs-xs text-muted fw-medium"><?php echo htmlspecialchars($u['role']); ?></div>
                                    </div>
                                </div>
                                <!-- CUSTOM MODERN DROPDOWN -->
                                <div class="dropdown custom-role-dropdown" data-user-id="<?php echo $u['user_id']; ?>">
                                    <!-- Hidden input na nagpapasa sa Database -->
                                    <input type="hidden" name="user_roles[<?php echo $u['user_id']; ?>]" id="role_input_<?php echo $u['user_id']; ?>" value="None">
                                    
                                    <!-- Ang sleek pill button na mukhang Select Box -->
                                    <button class="btn btn-sm btn-white bg-white text-dark fs-xs fw-bold text-start rounded-pill shadow-none d-flex justify-content-between align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="border: 1px solid #cbd5e1; width: 140px; padding: 6px 16px; transition: all 0.2s;">
                                        <span class="selected-role-text">No Access</span>
                                        <i class="fas fa-chevron-down text-secondary" style="font-size: 10px;"></i>
                                    </button>
                                    
                                    <!-- Ang Floating Modern Dropdown Menu -->
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 mt-1 p-2" style="min-width: 140px;">
                                        <li><button type="button" class="dropdown-item rounded-3 fs-xs fw-medium py-2 mb-1" onclick="updateRoleSelection(<?php echo $u['user_id']; ?>, 'None', 'No Access')">No Access</button></li>
                                        <li><button type="button" class="dropdown-item rounded-3 fs-xs fw-medium py-2 mb-1" onclick="updateRoleSelection(<?php echo $u['user_id']; ?>, 'Viewer', 'Viewer')">Viewer</button></li>
                                        <li><button type="button" class="dropdown-item rounded-3 fs-xs fw-medium py-2" onclick="updateRoleSelection(<?php echo $u['user_id']; ?>, 'Editor', 'Editor')">Editor</button></li>
                                    </ul>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Footer Buttons -->
                    <div class="d-flex justify-content-end gap-2 p-3 bg-light border-top border-light">
                        <button type="button" class="btn btn-light fw-bold px-4 py-2 rounded-pill border bg-white text-dark shadow-sm fs-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4 py-2 rounded-pill shadow-sm fs-sm">Save Settings</button>
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
                <form action="general_docs.php" method="POST">
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

<!-- PHYSICAL STATUS MODAL -->
<div class="modal fade sleek-modal" id="physicalLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4">
            <div class="modal-header bg-white border-bottom pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5 letter-spacing-tight"><i class="fas fa-map-marker-alt text-success me-2"></i>Physical Document Status</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Update the physical availability of this document.</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <div class="alert bg-white border text-dark fs-sm mb-4 shadow-sm rounded-3">
                    <i class="fas fa-file-alt text-primary me-2"></i><span class="fw-bold" id="plDocName"></span><br>
                    <small class="text-muted"><i class="fas fa-folder text-secondary me-1 mt-2"></i> Stored in Folder: <span id="plDocCategory" class="fw-bold"></span></small>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Current Physical Status</label>
                    <div id="plDynamicStatusBox" class="p-3 bg-white border rounded-3 shadow-sm d-flex align-items-center">
                        <!-- Dynamic Status Injected Here by JS -->
                    </div>
                    <div class="form-text fs-xs mt-2"><i class="fas fa-info-circle text-primary me-1"></i> Check-out and Check-in activities must be managed directly through the Virtual Cabinet.</div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top border-light">
                    <button type="button" class="btn btn-light bg-white border fw-medium px-4 shadow-sm rounded-pill" data-bs-dismiss="modal">Close</button>
                    <a href="#" id="plGoToCabinetBtn" class="btn btn-primary fw-bold px-4 shadow-sm rounded-pill d-none">
                        <i class="fas fa-external-link-alt me-2"></i> Manage in Virtual Cabinet
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DECLARE OFFICIAL MODAL -->
<div class="modal fade sleek-modal" id="declareOfficialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-certificate text-success me-2"></i>Declare Official Record</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="actions/document_handler.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="declare_official">
                    <input type="hidden" name="doc_id" id="declareDocId">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    
                    <div class="alert alert-success bg-success bg-opacity-10 border-success border-opacity-25 text-success fs-sm mb-3">
                        <i class="fas fa-info-circle me-2"></i> <strong>Confirmation:</strong> Declaring this as an Official Record will finalize it and move it to the Official Records directory.
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Document Name</label>
                        <input type="text" class="form-control bg-light fs-sm text-dark fw-bold" id="declareDocName" readonly>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light sleek-btn-sm border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success sleek-btn-sm px-4 fw-bold">Confirm & Move</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- UPLOAD MODAL -->
<?php if (!$hide_upload_button): ?>
<div class="modal fade sleek-modal" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 550px;">
        <div class="modal-content shadow-lg border-0 rounded-4">
            <div class="modal-header bg-white border-bottom pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5 letter-spacing-tight"><i class="fas fa-cloud-upload-alt text-primary me-2"></i>Upload Record</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Select a target folder or scan the document.</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <form action="actions/document_handler.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="file" name="document" id="uploadDocumentInput" class="d-none" required>
                    
                    <div class="mb-4 position-relative">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Target Folder <span class="text-danger">*</span></label>
                        
                        <!-- CUSTOM DROPDOWN PARA SA FOLDERS -->
                        <div class="dropdown w-100">
                            <!-- Naka-hide na HTML5 input para gumana ang "required" pop-up ng browser nang hindi nasisira ang design -->
                            <input type="text" name="category" id="uploadCategoryInput" required style="opacity: 0; position: absolute; bottom: 0; left: 50%; pointer-events: none; z-index: -1;">
                            
                            <button class="btn custom-select-btn d-flex justify-content-between align-items-center w-100 py-2 rounded-3 text-start shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="uploadCategoryBtn">
                                <span id="uploadCategoryText" class="text-muted fw-medium fs-sm">Select Target Folder</span>
                                <i class="fas fa-chevron-down text-secondary fs-xs"></i>
                            </button>
                            <ul class="dropdown-menu shadow-lg border-0 rounded-4 mt-1 w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                <?php 
                                if (!empty($parent_filter) && isset($parent_folders[$parent_filter])) {
                                    echo '<li><h6 class="dropdown-header fw-bold text-primary px-3">'.htmlspecialchars($parent_filter).'</h6></li>';
                                    foreach($parent_folders[$parent_filter] as $s) {
                                        if ($is_top_mgmt || in_array($s, $user_categories)) {
                                            $selected_js = ($type_filter === $s) ? "setUploadCategory('".htmlspecialchars(addslashes($s))."');" : "setUploadCategory('".htmlspecialchars(addslashes($s))."');";
                                            echo '<li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 text-dark" onclick="'.$selected_js.'"><i class="fas fa-folder text-secondary me-2 opacity-50"></i>'.htmlspecialchars($s).'</button></li>';
                                        }
                                    }
                                } else {
                                    foreach($parent_folders as $p => $subs) {
                                        echo '<li><h6 class="dropdown-header fw-bold text-primary px-3 mt-2 border-bottom pb-1">'.htmlspecialchars($p).'</h6></li>';
                                        foreach($subs as $s) {
                                            if ($is_top_mgmt || in_array($s, $user_categories)) {
                                                echo '<li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 text-dark" onclick="setUploadCategory(\''.htmlspecialchars(addslashes($s)).'\')"><i class="fas fa-folder text-secondary me-2 opacity-50"></i>'.htmlspecialchars($s).'</button></li>';
                                            }
                                        }
                                    }
                                }
                                ?>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Input Method <span class="text-danger">*</span></label>
                        
                        <!-- REFINED TILES MAY HOVER NA -->
                        <div class="row g-3">
                            <div class="col-6">
                                <button type="button" id="uploadBrowseBtn" class="btn btn-white bg-white border border-light w-100 py-3 rounded-4 shadow-sm text-dark d-flex flex-column align-items-center justify-content-center upload-tile-btn">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center mb-2" style="width: 45px; height: 45px;">
                                        <i class="fas fa-folder-open fs-5"></i>
                                    </div>
                                    <span class="fw-bold fs-sm">Browse Files</span>
                                </button>
                            </div>
                            <div class="col-6">
                                <button type="button" id="uploadCameraBtn" class="btn btn-white bg-white border border-light w-100 py-3 rounded-4 shadow-sm text-dark d-flex flex-column align-items-center justify-content-center upload-tile-btn">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center mb-2" style="width: 45px; height: 45px;">
                                        <i class="fas fa-camera fs-5"></i>
                                    </div>
                                    <span class="fw-bold fs-sm">Use Camera</span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-center mt-3">
                            <span id="uploadChosenFileText" class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1 fs-xs fw-medium text-truncate" style="max-width: 100%;">No file selected yet.</span>
                        </div>

                        <!-- START: Document Classification UI -->
                        <div id="classificationSuggestion" class="d-none mt-3 p-3 rounded-4 bg-primary bg-opacity-10 border border-primary border-opacity-25 shadow-sm">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="spinner-border spinner-border-sm text-primary d-none" id="classificationLoader" role="status"></div>
                                    <i class="fas fa-magic text-primary" id="classificationIcon"></i>
                                    <div class="lh-1 text-start">
                                        <span class="fs-xs fw-bold text-primary text-uppercase letter-spacing-tight d-block mb-1">Suggested Folder</span>
                                        <span class="fs-sm fw-bold text-dark" id="suggestedFolderName">Scanning...</span>
                                    </div>
                                </div>
                                <div id="classificationActions" class="d-none">
                                    <button type="button" class="btn btn-sm btn-light border fw-bold fs-xs px-3 py-1 me-1 shadow-sm text-secondary rounded-pill modal-btn-hover" id="btnRejectSuggestion">Ignore</button>
                                    <button type="button" class="btn btn-sm btn-primary fw-bold fs-xs px-3 py-1 shadow-sm rounded-pill modal-btn-hover" id="btnAcceptSuggestion">Apply</button>
                                </div>
                            </div>
                        </div>
                        <!-- END: Document Classification UI -->
                    </div>

                    <div id="cameraPanel" class="d-none mb-4 mt-4">
                        <div class="position-relative rounded-4 bg-dark overflow-hidden shadow border border-4 border-dark mx-auto" style="max-width: 100%;">
                            <div class="ratio ratio-4x3 bg-black">
                                <video id="cameraVideo" class="w-100 h-100 object-fit-cover" autoplay playsinline muted></video>
                                <img id="cameraPreviewImage" class="w-100 h-100 object-fit-cover d-none" alt="Captured photo preview">
                                <canvas id="cameraCanvas" class="d-none"></canvas>
                            </div>
                            <div class="position-absolute top-0 start-0 w-100 h-100 pointer-events-none" style="background: rgba(0,0,0,0.15);">
                                <div class="position-absolute top-0 start-0 border-top border-start border-white m-3" style="width: 35px; height: 35px; border-width: 3px !important; border-top-left-radius: 6px;"></div>
                                <div class="position-absolute top-0 end-0 border-top border-end border-white m-3" style="width: 35px; height: 35px; border-width: 3px !important; border-top-right-radius: 6px;"></div>
                                <div class="position-absolute bottom-0 start-0 border-bottom border-start border-white m-3" style="width: 35px; height: 35px; border-width: 3px !important; border-bottom-left-radius: 6px;"></div>
                                <div class="position-absolute bottom-0 end-0 border-bottom border-end border-white m-3" style="width: 35px; height: 35px; border-width: 3px !important; border-bottom-right-radius: 6px;"></div>
                                <div class="position-absolute top-50 start-50 translate-middle text-white opacity-50"><i class="fas fa-plus fs-4"></i></div>
                            </div>
                        </div>
                        
                        <div id="cameraStatusMessage" class="fs-xs fw-bold text-center text-muted mt-3 mb-3 px-3 py-1 bg-white rounded-pill border mx-auto shadow-sm" style="width: fit-content; max-width: 100%;"></div>
                        
                        <div class="d-flex justify-content-center gap-2">
                            <button type="button" id="capturePhotoBtn" class="btn btn-primary rounded-pill shadow-sm px-4 py-2 fw-bold d-flex align-items-center modal-btn-hover">
                                <i class="fas fa-camera fs-5 me-2"></i> Capture
                            </button>
                            <button type="button" id="retakePhotoBtn" class="btn btn-light bg-white border border-secondary rounded-pill shadow-sm px-4 py-2 fw-bold text-dark d-none align-items-center modal-btn-hover">
                                <i class="fas fa-redo-alt me-2"></i> Retake
                            </button>
                            <button type="button" id="usePhotoBtn" class="btn btn-success rounded-pill shadow-sm px-4 py-2 fw-bold d-none align-items-center modal-btn-hover">
                                <i class="fas fa-check-circle me-2"></i> Confirm & Use
                            </button>
                        </div>
                    </div>

                    <div class="mb-4 mt-3">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Initial Sharing Access</label>

                        <!-- DAGDAG NA PHYSICAL STATUS QUESTION -->
                    <div class="mb-4 mt-3">
                        <label class="form-label text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Physical File Status</label>
                        <div class="dropdown w-100">
                            <input type="hidden" name="physical_status" id="uploadPhysicalInput" value="Digital">
                            <button class="btn custom-select-btn d-flex justify-content-between align-items-center w-100 py-2 rounded-3 text-start shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <span id="uploadPhysicalText" class="text-dark fw-medium fs-sm"><i class="fas fa-laptop text-primary me-2 opacity-75"></i> Digital Only (No physical copy yet)</span>
                                <i class="fas fa-chevron-down text-secondary fs-xs"></i>
                            </button>
                            <ul class="dropdown-menu shadow-lg border-0 rounded-4 mt-1 w-100 p-2">
                                <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 mb-1 text-dark" onclick="setPhysicalStatus('Digital', '<i class=\'fas fa-laptop text-primary me-2 opacity-75\'></i> Digital Only (No physical copy yet)')">Digital Only (No physical copy yet)</button></li>
                                <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 text-dark" onclick="setPhysicalStatus('Stored', '<i class=\'fas fa-check-circle text-success me-2 opacity-75\'></i> Physical copy is stored in Cabinet')">Physical copy is stored in Cabinet</button></li>
                            </ul>
                        </div>
                        <div class="form-text fs-xs mt-2 text-muted fw-medium"><i class="fas fa-info-circle text-primary me-1"></i> Cabinet location is automatically based on your selected Target Folder.</div>
                    </div>
                        
                        <!-- CUSTOM DROPDOWN PARA SA INITIAL ACCESS -->
                        <div class="dropdown w-100">
                            <input type="hidden" name="initial_access" id="uploadAccessInput" value="Folder Default">
                            <button class="btn custom-select-btn d-flex justify-content-between align-items-center w-100 py-2 rounded-3 text-start shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <span id="uploadAccessText" class="text-dark fw-medium fs-sm"><i class="fas fa-folder-open text-primary me-2 opacity-75"></i> Folder Default (Inherit Permissions)</span>
                                <i class="fas fa-chevron-down text-secondary fs-xs"></i>
                            </button>
                            <ul class="dropdown-menu shadow-lg border-0 rounded-4 mt-1 w-100 p-2">
                                <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 mb-1 text-dark" onclick="setUploadAccess('Folder Default', '<i class=\'fas fa-folder-open text-primary me-2 opacity-75\'></i> Folder Default (Inherit Permissions)')">Folder Default (Inherit Permissions)</button></li>
                                <li><button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 text-dark" onclick="setUploadAccess('Restricted', '<i class=\'fas fa-lock text-danger me-2 opacity-75\'></i> Restricted (Only me for now)')">Restricted (Only me for now)</button></li>
                            </ul>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm rounded-pill py-2 modal-btn-hover">
                        <i class="fas fa-check-circle me-2"></i> Upload and Index File
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?> <!-- End If Non-Admin For Restricted Modals -->

<!-- EDIT RETENTION POLICIES MODAL -->
<?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
<div class="modal fade sleek-modal" id="editPoliciesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-balance-scale me-2 text-primary"></i> Retention Policies Dictionary</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3 pb-4 bg-white">
                
                <!-- TRIGGER BUTTON AT HEADER -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <h6 class="fw-bold text-dark mb-0">Existing Policies</h6>
                    <button class="btn btn-sm btn-primary fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCreatePolicy" aria-expanded="false" aria-controls="collapseCreatePolicy">
                        <i class="fas fa-plus me-1"></i> Add New Policy
                    </button>
                </div>

                <!-- NAKA-HIDE NA CREATE FORM (Lalabas lang pag pinindot ang button) -->
                <div class="collapse mb-4" id="collapseCreatePolicy">
                    <div class="card border border-primary border-opacity-25 shadow-sm rounded-3">
                        <div class="card-body p-4 bg-primary bg-opacity-10">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-folder-plus me-2"></i>New Policy Details</h6>
                            <form action="general_docs.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="create_policy">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold small text-secondary text-uppercase">Policy Name</label>
                                        <input type="text" name="policy_name" class="form-control bg-white fw-medium shadow-none border-0" placeholder="e.g. HR Files" required>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <label class="form-label fw-semibold small text-secondary text-uppercase">Active (Yrs)</label>
                                        <input type="number" name="active_years" class="form-control bg-white fw-bold text-primary shadow-none border-0" min="0" value="0" required>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <label class="form-label fw-semibold small text-secondary text-uppercase">Active (Mos)</label>
                                        <input type="number" name="active_months" class="form-control bg-white fw-bold text-primary shadow-none border-0" min="0" max="11" value="0" required>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <label class="form-label fw-semibold small text-secondary text-uppercase">Archive (Yrs)</label>
                                        <input type="number" name="archive_years" class="form-control bg-white fw-bold text-danger shadow-none border-0" min="0" value="0" required>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <label class="form-label fw-semibold small text-secondary text-uppercase">Archive (Mos)</label>
                                        <input type="number" name="archive_months" class="form-control bg-white fw-bold text-danger shadow-none border-0" min="0" max="11" value="0" required>
                                    </div>
                                    <div class="col-12 text-end mt-4">
                                        <button type="button" class="btn btn-light fw-medium border px-3 me-2" data-bs-toggle="collapse" data-bs-target="#collapseCreatePolicy">Cancel</button>
                                        <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm">Save Policy</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="accordion" id="policiesAccordion">
                    <?php foreach($policies as $idx => $pol): 
                        // Kunin ang mga folders na naka-connect sa policy na ito
                        $pol_id = $pol['policy_id'];
                        $linked_folders = [];
                        $q_linked = $conn->query("SELECT parent_category, sub_category FROM document_categories WHERE policy_id = $pol_id");
                        if ($q_linked) {
                            while($lf = $q_linked->fetch_assoc()) {
                                $linked_folders[] = $lf['parent_category'] . ' > ' . $lf['sub_category'];
                            }
                        }
                        $linked_folders_json = htmlspecialchars(json_encode($linked_folders), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="accordion-item border mb-2 rounded-3 overflow-hidden bg-white">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-light fw-bold text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePol<?php echo $pol['policy_id']; ?>" aria-expanded="false">
                                <div class="d-flex w-100 justify-content-between align-items-center pe-3">
                                    <span><i class="fas fa-shield-alt text-primary me-2"></i> <?php echo htmlspecialchars($pol['policy_name']); ?></span>
                                    <span class="badge bg-white text-dark border shadow-sm" style="font-size: 0.75rem;">
                                        Act: <?php echo (int)$pol['active_years']; ?>Y <?php echo (int)$pol['active_months']; ?>M | 
                                        Arc: <?php echo (int)$pol['archive_years']; ?>Y <?php echo (int)$pol['archive_months']; ?>M
                                    </span>
                                </div>
                            </button>
                        </h2>
                        <div id="collapsePol<?php echo $pol['policy_id']; ?>" class="accordion-collapse collapse" data-bs-parent="#policiesAccordion">
                            <div class="accordion-body">
                                <form action="general_docs.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="edit_policy">
                                    <input type="hidden" name="policy_id" value="<?php echo $pol['policy_id']; ?>">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label small fw-bold text-muted text-uppercase">Policy Identifier Name</label>
                                            <input type="text" name="policy_name" class="form-control bg-light fw-medium" value="<?php echo htmlspecialchars($pol['policy_name']); ?>" required>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label fw-semibold small text-secondary text-uppercase">Active (Yrs)</label>
                                            <input type="number" name="active_years" class="form-control bg-light fw-bold text-primary" value="<?php echo (int)$pol['active_years']; ?>" min="0" required>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label fw-semibold small text-secondary text-uppercase">Active (Mos)</label>
                                            <input type="number" name="active_months" class="form-control bg-light fw-bold text-primary" value="<?php echo (int)$pol['active_months']; ?>" min="0" max="11" required>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label fw-semibold small text-secondary text-uppercase">Archive (Yrs)</label>
                                            <input type="number" name="archive_years" class="form-control bg-light fw-bold text-danger" value="<?php echo (int)$pol['archive_years']; ?>" min="0" required>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label fw-semibold small text-secondary text-uppercase">Archive (Mos)</label>
                                            <input type="number" name="archive_months" class="form-control bg-light fw-bold text-danger" value="<?php echo (int)$pol['archive_months']; ?>" min="0" max="11" required>
                                        </div>
                                        
                                        <div class="col-12 mt-3 d-flex justify-content-between align-items-center">
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-info px-3 py-2 fw-medium shadow-sm me-2" onclick="viewConnectedFolders(this)" data-folders="<?php echo $linked_folders_json; ?>">
                                                    <i class="fas fa-eye me-1"></i> View Connected Folders
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger px-3 py-2 fw-medium shadow-sm" onclick="confirmDeletePolicy(<?php echo $pol['policy_id']; ?>)">
                                                    <i class="fas fa-trash-alt me-1"></i> Delete Policy
                                                </button>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary px-3 py-2 fw-medium shadow-sm">
                                                <i class="fas fa-save me-1"></i> Update Policy Settings
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                
                                <!-- HIDDEN FORM PARA SA DELETE POLICY -->
                                <form id="deletePolicyForm_<?php echo $pol['policy_id']; ?>" action="general_docs.php" method="POST" class="d-none">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete_policy">
                                    <input type="hidden" name="policy_id" value="<?php echo $pol['policy_id']; ?>">
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
                <form action="general_docs.php" method="POST">
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
                <form action="general_docs.php" method="POST">
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

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Physical Storage (Drawer) <span class="text-danger">*</span></label>
                        <select name="drawer_id" class="form-select shadow-none bg-light" required>
                            <option value="">-- Select Physical Cabinet/Drawer --</option>
                            <?php foreach ($drawers as $dr): ?>
                                <option value="<?php echo $dr['id']; ?>">
                                    <?php echo htmlspecialchars($dr['building'] . ' > ' . $dr['room'] . ' > ' . $dr['cabinet'] . ' > ' . $dr['drawer']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Retention Policy <span class="text-danger">*</span></label>
                        <select name="folder_policy" class="form-select shadow-none bg-light" required>
                            <option value="">-- Select Legal Policy --</option>
                            <?php foreach ($policies as $pol): ?>
                                <option value="<?php echo $pol['policy_id']; ?>">
                                    <?php echo htmlspecialchars($pol['policy_name']); ?> (Act: <?php echo $pol['active_years']; ?>Y <?php echo $pol['active_months']; ?>M, Arc: <?php echo $pol['archive_years']; ?>Y <?php echo $pol['archive_months']; ?>M)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Auto-Classification Keywords <span class="text-muted fw-normal fs-xs">(Optional)</span></label>
                        <textarea name="classification_keywords" class="form-control shadow-none bg-light fs-sm" rows="2" placeholder="e.g. invoice, billing, receipt (comma separated)"></textarea>
                        <div class="form-text fs-xs mt-1"><i class="fas fa-magic text-primary me-1"></i> Files containing these words will be auto-suggested to this folder during upload.</div>
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

<!-- CHANGE FOLDER POLICY MODAL -->
<?php if (!empty($parent_filter) && empty($type_filter) && $can_manage): ?>
<div class="modal fade sleek-modal" id="editFolderPolicyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-exchange-alt text-primary me-2"></i>Change Folder Policy</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <form action="general_docs.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_folder_policy">
                    <input type="hidden" name="parent_name" id="efParentName">
                    <input type="hidden" name="sub_name" id="efSubName">
                    
                    <div class="alert bg-light border text-muted fs-sm mb-3">
                        Updating policy for folder: <br><span class="fw-bold text-dark fs-6" id="efFolderNameDisplay"></span><br>
                        <span class="d-inline-block mt-1">Current Policy: <strong class="text-primary" id="efCurrentPolicyDisplay"></strong></span>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Assign New Policy <span class="text-danger">*</span></label>
                        <select name="new_policy_id" class="form-select shadow-none bg-light" required>
                            <option value="">-- Select New Policy --</option>
                            <?php foreach ($policies as $pol): ?>
                                <option value="<?php echo $pol['policy_id']; ?>">
                                    <?php echo htmlspecialchars($pol['policy_name']); ?> (Act: <?php echo $pol['active_years']; ?>Y <?php echo $pol['active_months']; ?>M, Arc: <?php echo $pol['archive_years']; ?>Y <?php echo $pol['archive_months']; ?>M)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light sleek-btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary sleek-btn-sm px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- EDIT KEYWORDS MODAL -->
<div class="modal fade sleek-modal" id="editKeywordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light border-bottom-0 pb-3 pt-4 px-4 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5"><i class="fas fa-tags text-primary me-2"></i>Edit Keywords</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Folder: <span class="fw-bold text-primary" id="ekFolderNameDisplay"></span></p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" style="margin-top: -15px;"></button>
            </div>
            <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                <form action="general_docs.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : ''; ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_keywords">
                    <input type="hidden" name="category_name" id="ekCategoryName">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Auto-Classification Keywords</label>
                        <div class="position-relative">
                            <textarea name="classification_keywords" id="ekKeywordsInput" class="form-control shadow-none bg-white fs-sm" rows="3" placeholder="e.g. invoice, billing, receipt (comma separated)" disabled></textarea>
                            <div class="spinner-border spinner-border-sm text-primary position-absolute" id="ekLoader" style="top: 15px; right: 15px; display: none;" role="status"></div>
                        </div>
                        
                        <!-- DAGDAG: REAL-TIME ERROR DISPLAY -->
                        <div id="ekConflictWarning" class="alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-25 text-danger fs-xs mt-2 d-none p-2 mb-0"></div>
                        
                        <div class="form-text fs-xs mt-2"><i class="fas fa-info-circle text-primary me-1"></i> Add, edit, or remove keywords. Separate multiple keywords using commas.</div>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light bg-white border fw-medium px-4 shadow-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="ekSaveBtn" class="btn btn-primary fw-bold px-4 shadow-sm rounded-3"><i class="fas fa-save me-2"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
    $(document).ready(function() {
        if (document.getElementById('documentsTable')) {
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
                },
                "drawCallback": function(settings) {
                    // Ensure table wrapper always fills remaining height
                    $('.dataTables_wrapper').css('height', '100%');
                }
            });

            // Trigger DataTables redraw on window resize to fix scrolling bounds
            $(window).on('resize', function() { table.columns.adjust(); });
        }

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

    <?php if ($role !== 'Admin'): ?>
    let uploadCameraStream = null;
    let capturedPhotoUrl = '';

    function uploadModalElements() {
        return {
            modal: document.getElementById('uploadModal'),
            fileInput: document.getElementById('uploadDocumentInput'),
            browseBtn: document.getElementById('uploadBrowseBtn'),
            cameraBtn: document.getElementById('uploadCameraBtn'),
            chosenFileText: document.getElementById('uploadChosenFileText'),
            cameraPanel: document.getElementById('cameraPanel'),
            cameraVideo: document.getElementById('cameraVideo'),
            cameraPreviewImage: document.getElementById('cameraPreviewImage'),
            cameraCanvas: document.getElementById('cameraCanvas'),
            cameraStatusMessage: document.getElementById('cameraStatusMessage'),
            capturePhotoBtn: document.getElementById('capturePhotoBtn'),
            retakePhotoBtn: document.getElementById('retakePhotoBtn'),
            usePhotoBtn: document.getElementById('usePhotoBtn')
        };
    }

    function setUploadStatus(message, isError = false) {
        const { cameraStatusMessage } = uploadModalElements();
        if (!cameraStatusMessage) return;
        cameraStatusMessage.textContent = message;
        cameraStatusMessage.classList.toggle('text-danger', isError);
        cameraStatusMessage.classList.toggle('text-muted', !isError);
    }

    function setChosenFileText(text) {
        const { chosenFileText } = uploadModalElements();
        if (chosenFileText) {
            chosenFileText.textContent = text;
        }
    }

    function clearCapturedPhotoUrl() {
        if (capturedPhotoUrl) {
            URL.revokeObjectURL(capturedPhotoUrl);
            capturedPhotoUrl = '';
        }
    }

    function stopUploadCameraStream() {
        if (uploadCameraStream) {
            uploadCameraStream.getTracks().forEach(track => track.stop());
            uploadCameraStream = null;
        }
        const { cameraVideo } = uploadModalElements();
        if (cameraVideo) {
            cameraVideo.srcObject = null;
        }
    }

    function resetUploadCameraPanel() {
        const { cameraPanel, cameraVideo, cameraPreviewImage, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
        if (!cameraPanel) return;

        stopUploadCameraStream();
        clearCapturedPhotoUrl();

        cameraPanel.classList.add('d-none');
        if (cameraVideo) cameraVideo.classList.remove('d-none');
        if (cameraPreviewImage) {
            cameraPreviewImage.classList.add('d-none');
            cameraPreviewImage.removeAttribute('src');
        }
        if (capturePhotoBtn) capturePhotoBtn.classList.remove('d-none');
        if (retakePhotoBtn) retakePhotoBtn.classList.add('d-none');
        if (usePhotoBtn) usePhotoBtn.classList.add('d-none');
        setUploadStatus('');
    }

    function getCapturedFileName() {
        const stamp = new Date().toISOString().replace(/[:.]/g, '-');
        return `camera-capture-${stamp}.jpg`;
    }

    function setFileInputFromBlob(blob) {
        const { fileInput } = uploadModalElements();
        if (!fileInput) return null;

        const file = new File([blob], getCapturedFileName(), { type: 'image/jpeg' });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        fileInput.files = dataTransfer.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        return file;
    }

    async function startUploadCamera() {
        const { fileInput, cameraPanel, cameraVideo, cameraPreviewImage, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
        if (!cameraPanel || !cameraVideo) return;

        resetUploadCameraPanel();
        if (fileInput) {
            fileInput.value = '';
        }
        setChosenFileText('No file selected yet.');
        cameraPanel.classList.remove('d-none');
        setUploadStatus('Requesting camera access...');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setUploadStatus('Camera is not supported in this browser. Use Browse Files instead.', true);
            return;
        }

        const constraintsList = [
            { video: { facingMode: { ideal: 'environment' } }, audio: false },
            { video: true, audio: false }
        ];

        let lastError = null;
        for (const constraints of constraintsList) {
            try {
                uploadCameraStream = await navigator.mediaDevices.getUserMedia(constraints);
                break;
            } catch (error) {
                lastError = error;
                uploadCameraStream = null;
            }
        }

        if (!uploadCameraStream) {
            const errorName = lastError && lastError.name ? lastError.name : 'Error';
            const friendlyMessage = errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError'
                ? 'Camera permission was denied. You can still use Browse Files.'
                : errorName === 'NotFoundError' || errorName === 'OverconstrainedError'
                    ? 'No usable camera was found on this device. Use Browse Files instead.'
                    : 'Unable to open the camera right now. Use Browse Files instead.';
            setUploadStatus(friendlyMessage, true);
            return;
        }

        cameraVideo.srcObject = uploadCameraStream;
        try {
            await cameraVideo.play();
        } catch (error) {
            // Some browsers resolve the stream but still require a user gesture before playback.
        }

        if (cameraPreviewImage) cameraPreviewImage.classList.add('d-none');
        if (capturePhotoBtn) capturePhotoBtn.classList.remove('d-none');
        if (retakePhotoBtn) retakePhotoBtn.classList.add('d-none');
        if (usePhotoBtn) usePhotoBtn.classList.add('d-none');
        setUploadStatus('Live camera ready. Capture a photo when you are ready.');
    }

    function captureUploadPhoto() {
        const { cameraVideo, cameraPreviewImage, cameraCanvas, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
        if (!cameraVideo || !cameraCanvas || !cameraPreviewImage) return;

        if (!cameraVideo.videoWidth || !cameraVideo.videoHeight) {
            setUploadStatus('Camera preview is not ready yet. Please try again.', true);
            return;
        }

        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        const context = cameraCanvas.getContext('2d');
        if (!context) {
            setUploadStatus('Unable to access the camera canvas. Please try again.', true);
            return;
        }
        context.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);

        cameraCanvas.toBlob(function(blob) {
            if (!blob) {
                setUploadStatus('Unable to capture the photo. Please try again.', true);
                return;
            }

            clearCapturedPhotoUrl();
            const file = setFileInputFromBlob(blob);
            if (!file) {
                setUploadStatus('Unable to prepare the captured photo for upload.', true);
                return;
            }

            capturedPhotoUrl = URL.createObjectURL(file);
            cameraPreviewImage.src = capturedPhotoUrl;
            cameraPreviewImage.classList.remove('d-none');
            cameraVideo.classList.add('d-none');
            if (capturePhotoBtn) capturePhotoBtn.classList.add('d-none');
            if (retakePhotoBtn) retakePhotoBtn.classList.remove('d-none');
            if (usePhotoBtn) usePhotoBtn.classList.remove('d-none');
            setUploadStatus('Photo captured. Review it, then choose Use Photo or Retake.');
        }, 'image/jpeg', 0.92);
    }

    function useCapturedUploadPhoto() {
        const { fileInput } = uploadModalElements();
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            setUploadStatus('Capture a photo first before using it.', true);
            return;
        }

        setChosenFileText(`Selected file: ${fileInput.files[0].name}`);
        setUploadStatus('Captured photo selected for upload.');
        stopUploadCameraStream();
        
        analyzeDocument(fileInput.files[0]); // Added Document Analysis
    }

    function browseUploadFiles() {
        const { fileInput } = uploadModalElements();
        if (!fileInput) return;

        resetUploadCameraPanel();
        fileInput.click();
    }

    function handleUploadFileSelection(event) {
        const file = event.target.files && event.target.files[0];
        if (!file) {
            setChosenFileText('No file selected yet.');
            document.getElementById('classificationSuggestion').classList.add('d-none');
            return;
        }

        setChosenFileText(`Selected file: ${file.name}`);
        if (!file.type.startsWith('image/')) {
            resetUploadCameraPanel();
        }
        
        analyzeDocument(file); // Added Document Analysis
    }

    // --- DAGDAG: CUSTOM DROPDOWN HELPERS ---
    function setUploadCategory(val, suggestedText = null) {
        document.getElementById('uploadCategoryInput').value = val;
        const textSpan = document.getElementById('uploadCategoryText');
        textSpan.innerText = suggestedText ? suggestedText : val;
        textSpan.classList.remove('text-muted');
        textSpan.classList.add('text-dark', 'fw-bold');
        if(suggestedText) {
            textSpan.classList.add('text-success');
        } else {
            textSpan.classList.remove('text-success');
        }
    }

    function setUploadAccess(val, textHTML) {
        document.getElementById('uploadAccessInput').value = val;
        document.getElementById('uploadAccessText').innerHTML = textHTML;
    }

    function setPhysicalStatus(val, textHTML) {
        document.getElementById('uploadPhysicalInput').value = val;
        document.getElementById('uploadPhysicalText').innerHTML = textHTML;
    }

    // --- REFINED CAMERA CAPTURE FUNCTION (RESETTER) ---
    function captureUploadPhoto() {
        const { cameraVideo, cameraPreviewImage, cameraCanvas, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
        if (!cameraVideo || !cameraCanvas || !cameraPreviewImage) return;

        if (!cameraVideo.videoWidth || !cameraVideo.videoHeight) {
            setUploadStatus('Camera preview is not ready yet. Please try again.', true);
            return;
        }

        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        const context = cameraCanvas.getContext('2d');
        if (!context) {
            setUploadStatus('Unable to access the camera canvas. Please try again.', true);
            return;
        }
        context.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);

        cameraCanvas.toBlob(function(blob) {
            if (!blob) {
                setUploadStatus('Unable to capture the photo. Please try again.', true);
                return;
            }

            clearCapturedPhotoUrl();
            const file = setFileInputFromBlob(blob);
            if (!file) {
                setUploadStatus('Unable to prepare the captured photo for upload.', true);
                return;
            }

            // RESET THE CONFIRM BUTTON just in case galing sa retake
            if (usePhotoBtn) {
                usePhotoBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirm & Use';
                usePhotoBtn.className = 'btn btn-success rounded-pill shadow-sm px-4 py-2 fw-bold align-items-center modal-btn-hover';
                usePhotoBtn.style.pointerEvents = 'auto'; // I-enable ulit ang click
            }

            capturedPhotoUrl = URL.createObjectURL(file);
            cameraPreviewImage.src = capturedPhotoUrl;
            cameraPreviewImage.classList.remove('d-none');
            cameraVideo.classList.add('d-none');
            
            if (capturePhotoBtn) capturePhotoBtn.classList.add('d-none');
            if (retakePhotoBtn) retakePhotoBtn.classList.remove('d-none');
            if (usePhotoBtn) usePhotoBtn.classList.remove('d-none');
            
            setUploadStatus('Photo captured. Review it, then choose Use Photo or Retake.');
        }, 'image/jpeg', 0.92);
    }

    // --- REFINED CAMERA USE FUNCTION (INTERACTIVE CHECKMARK) ---
    function useCapturedUploadPhoto() {
        const { fileInput } = uploadModalElements();
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            setUploadStatus('Capture a photo first before using it.', true);
            return;
        }

        setChosenFileText(`Selected file: ${fileInput.files[0].name}`);
        setUploadStatus('Captured photo confirmed and ready for upload.');
        
        // HINDI na natin itatago ang camera panel para kita pa rin yung photo at "Retake"
        // stopUploadCameraStream();  <- Tinanggal ito
        
        // INTERACTIVE CHECKMARK
        const useBtn = document.getElementById('usePhotoBtn');
        if (useBtn) {
            useBtn.innerHTML = '<i class="fas fa-check-double me-2"></i> Confirmed & Applied';
            // Palitan ang style para halatang na-click na
            useBtn.className = 'btn btn-outline-success bg-white text-success border border-success rounded-pill shadow-sm px-4 py-2 fw-bold align-items-center';
            useBtn.style.pointerEvents = 'none'; // I-disable pansamantala para iwas spam-click
        }
        
        // I-trigger agad ang AI Document Scanner
        analyzeDocument(fileInput.files[0]); 
    }

    // START: Document Classification AJAX Engine
    async function analyzeDocument(file) {
        const suggestionBox = document.getElementById('classificationSuggestion');
        const loader = document.getElementById('classificationLoader');
        const icon = document.getElementById('classificationIcon');
        const nameDisplay = document.getElementById('suggestedFolderName');
        const actions = document.getElementById('classificationActions');
        
        // Reset UI to scanning state
        suggestionBox.classList.remove('d-none', 'bg-success', 'border-success');
        suggestionBox.classList.add('bg-primary', 'border-primary');
        icon.classList.replace('fa-check-circle', 'fa-magic');
        icon.classList.replace('text-success', 'text-primary');
        nameDisplay.classList.remove('text-success');
        
        loader.classList.remove('d-none');
        icon.classList.add('d-none');
        actions.classList.add('d-none');

        let clientSideText = "";
        
        // 1. Kapag Image, gamitin ang Tesseract OCR
        if (file.type.startsWith('image/')) {
            nameDisplay.innerText = "Preprocessing Image...";
            try {
                // DAGDAG: STRICT IMAGE SANITIZER
                const safeImage = await new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        canvas.width = img.width;
                        canvas.height = img.height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0);
                        resolve(canvas.toDataURL('image/png'));
                        URL.revokeObjectURL(img.src);
                    };
                    img.onerror = () => {
                        // KUNG MAG-FAIL ANG BROWSER NA BUKSAN ANG IMAGE, IHINTO AGAD ANG PROSESO.
                        // Ibig sabihin nito ay corrupted ang file o isa itong HEIC file na ni-rename to JPG.
                        reject("UNSUPPORTED_FORMAT");
                    };
                    img.src = URL.createObjectURL(file);
                });

                console.warn("=== INITIALIZING TESSERACT OCR ===");
                const worker = await Tesseract.createWorker("eng", 1, {
                    logger: function(m) {
                        console.log("OCR Status:", m.status, Math.round(m.progress * 100) + "%");
                        if (m.status === 'recognizing text') {
                            nameDisplay.innerText = "Scanning Image: " + Math.round(m.progress * 100) + "%";
                        } else {
                            nameDisplay.innerText = "OCR: " + m.status + "...";
                        }
                    }
                });
                
                nameDisplay.innerText = "Extracting text from image...";
                const ret = await worker.recognize(safeImage);
                clientSideText = ret.data.text;
                
                console.warn("=== FRONTEND OCR EXTRACTED TEXT ===");
                console.warn(clientSideText ? clientSideText : "[WALANG NABASANG TEXT SA IMAGE - BLANK]");
                console.warn("===================================");
                
                await worker.terminate();
            } catch (error) {
                if (error === "UNSUPPORTED_FORMAT") {
                    console.warn("Browser cannot render this image. It might be a HEIC file renamed to JPG, or a corrupted file.");
                    nameDisplay.innerText = "Unsupported Format. Please use a real JPG or PNG.";
                } else {
                    console.error("=== OCR CRASHED ===");
                    console.error("Error Details:", error);
                    nameDisplay.innerText = "Image Scan Failed. (Check Console)";
                }
            }
        }
        // 2. Kapag PDF, gamitin ang Mozilla PDF.js
        else if (file.type === 'application/pdf') {
            nameDisplay.innerText = "Reading PDF Content...";
            try {
                const arrayBuffer = await file.arrayBuffer();
                const pdfjsLib = window['pdfjs-dist/build/pdf'];
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                
                // Basahin hanggang unang 3 pages para mabilis
                const maxPages = Math.min(pdf.numPages, 3);
                for (let i = 1; i <= maxPages; i++) {
                    const page = await pdf.getPage(i);
                    const textContent = await page.getTextContent();
                    const pageText = textContent.items.map(item => item.str).join(' ');
                    clientSideText += pageText + " ";
                }
            } catch (error) {
                console.error("PDF Parsing Failed:", error);
            }
        }

        nameDisplay.innerText = "Analyzing extracted content...";

        let formData = new FormData();
        formData.append('action', 'analyze_document');
        formData.append('document', file);
        formData.append('ocr_text', clientSideText); 
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        $.ajax({
            url: 'actions/document_handler.php', // NA-UPDATE NA NATIN ITO SA BAGONG FILENAME MO
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.warn("=== PHP TEXT EXTRACTION DEBUG ===");
                console.warn("EXTRACTED TEXT:\n", response.debug_text ? response.debug_text : "[BLANK]");
                console.warn("=================================");

                loader.classList.add('d-none');
                icon.classList.remove('d-none');
                
                if (response.status === 'success' && response.suggested_category) {
                    nameDisplay.innerText = response.suggested_category;
                    actions.classList.remove('d-none');
                    
                    document.getElementById('btnAcceptSuggestion').onclick = function() {
                        const catInput = document.getElementById('uploadCategoryInput');
                        if (catInput) {
                            setUploadCategory(response.suggested_category, response.suggested_category + ' (Auto-Suggested)');
                        }
                        
                        suggestionBox.classList.replace('bg-primary', 'bg-success');
                        suggestionBox.classList.replace('border-primary', 'border-success');
                        icon.classList.replace('fa-magic', 'fa-check-circle');
                        icon.classList.replace('text-primary', 'text-success');
                        nameDisplay.classList.add('text-success');
                        actions.classList.add('d-none');
                    };
                    
                    document.getElementById('btnRejectSuggestion').onclick = function() {
                        suggestionBox.classList.add('d-none');
                    };
                } else {
                    suggestionBox.classList.add('d-none'); 
                }
            },
            error: function(xhr) {
                console.error("Server Error:", xhr.responseText);
                suggestionBox.classList.add('d-none');
            }
        });
    }
    // END: Document Classification AJAX Engine

    const uploadModal = document.getElementById('uploadModal');
    if (uploadModal) {
        const { fileInput, browseBtn, cameraBtn, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
        if (browseBtn) browseBtn.addEventListener('click', browseUploadFiles);
        if (cameraBtn) cameraBtn.addEventListener('click', startUploadCamera);
        if (capturePhotoBtn) capturePhotoBtn.addEventListener('click', captureUploadPhoto);
        if (retakePhotoBtn) retakePhotoBtn.addEventListener('click', startUploadCamera);
        if (usePhotoBtn) usePhotoBtn.addEventListener('click', useCapturedUploadPhoto);
        if (fileInput) fileInput.addEventListener('change', handleUploadFileSelection);

        uploadModal.addEventListener('hidden.bs.modal', function () {
            resetUploadCameraPanel();
            if (fileInput) fileInput.value = '';
            setChosenFileText('No file selected yet.');
        });
    }

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
        
        let unsupportedMsg = document.getElementById('viewerUnsupportedMsg');
        if (unsupportedMsg) unsupportedMsg.style.display = 'none';
        
        loader.style.display = 'block';
        imgViewer.style.display = 'none';
        frameViewer.style.display = 'none';
        
        currentZoom = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
        document.getElementById('viewerZoomLevel').innerText = '100%';

        const ext = fileName.split('.').pop().toLowerCase();
        const isPdf = (ext === 'pdf');

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
        } else if (isPdf) {
            zoomControls.style.display = 'none';
            frameViewer.onload = function() { loader.style.display = 'none'; frameViewer.style.display = 'block'; };
            frameViewer.src = filePath + '#toolbar=0&navpanes=0&scrollbar=0'; 
        } else {
            // KAPAG DOCX O EXCEL (Hindi kayang i-preview ng local browser)
            zoomControls.style.display = 'none';
            loader.style.display = 'none';
            
            if (!unsupportedMsg) {
                unsupportedMsg = document.createElement('div');
                unsupportedMsg.id = 'viewerUnsupportedMsg';
                unsupportedMsg.className = 'text-center';
                unsupportedMsg.style.zIndex = '1050';
                document.getElementById('viewerContentWrapper').appendChild(unsupportedMsg);
            }
            unsupportedMsg.style.display = 'block';
            unsupportedMsg.innerHTML = `
                <div class="p-5 rounded-4 shadow-lg bg-white border text-dark text-center" style="max-width: 420px;">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                        <i class="fas fa-file-word fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Preview Not Available</h5>
                    <p class="text-muted fs-sm mb-4">Local browsers cannot view MS Office files directly. Please download the file to view its contents safely.</p>
                    <a href="${filePath}" download="${fileName}" class="btn btn-primary fw-bold px-4 py-2 rounded-pill shadow-sm w-100 transition-all">
                        <i class="fas fa-download me-2"></i> Download File
                    </a>
                </div>
            `;
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
        
        let unsupportedMsg = document.getElementById('viewerUnsupportedMsg');
        if (unsupportedMsg) unsupportedMsg.style.display = 'none';
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
            dataType: 'json',
            success: function(response) {
                // Siguraduhing nababasa bilang JSON object ang bato ng server
                let res = typeof response === 'string' ? JSON.parse(response) : response;
                
                let html = '<div class="vc-timeline">';
                if (res.success && res.data && res.data.length > 0) {
                    res.data.forEach(function(v, index) {
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
        
        // I-update ang Hidden Input at Custom Text ng General Access
        const accessInput = document.getElementById('shareAccessType');
        accessInput.value = accessType;
        const accessText = (accessType === 'Restricted') ? 'Restricted (Only selected people)' : 'Folder Default (Inherit Permissions)';
        document.getElementById('generalAccessSelectedText').innerText = accessText;
        
        const perms = JSON.parse(filePermissionsStr || '{}');
        
        document.querySelectorAll('input[name^="user_roles"]').forEach(input => {
            input.value = 'None'; 
            const userId = input.name.match(/\[(\d+)\]/)[1];
            let roleText = 'No Access';
            
            if (perms['user_' + userId]) {
                input.value = perms['user_' + userId];
                roleText = perms['user_' + userId];
            }
            
            // I-update ang text ng pill button
            const textSpan = document.querySelector('.custom-role-dropdown[data-user-id="'+userId+'"] .selected-role-text');
            if(textSpan) textSpan.innerText = roleText;
        });

        toggleShareList();
        new bootstrap.Modal(document.getElementById('shareDocumentModal')).show();
    }

    // Bagong function para sa custom General Access dropdown
    function updateGeneralAccessSelection(value, text) {
        document.getElementById('shareAccessType').value = value;
        document.getElementById('generalAccessSelectedText').innerText = text;
        toggleShareList(); // I-trigger ang pag-hide/show ng users list
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
            
            document.querySelectorAll('input[name^="user_roles"]').forEach(input => {
                input.value = 'None';
                const userId = input.name.match(/\[(\d+)\]/)[1];
                const textSpan = document.querySelector('.custom-role-dropdown[data-user-id="'+userId+'"] .selected-role-text');
                if(textSpan) textSpan.innerText = 'No Access';
            });
        }
    }

    // Function na nag-a-update ng text at value kapag pumili sa bagong custom dropdown
    function updateRoleSelection(userId, value, text) {
        document.getElementById('role_input_' + userId).value = value;
        document.querySelector('.custom-role-dropdown[data-user-id="' + userId + '"] .selected-role-text').innerText = text;
    }

    function openLegalHoldModal(docId, fileName) {
        document.getElementById('holdDocId').value = docId;
        document.getElementById('holdDocName').value = fileName;
        new bootstrap.Modal(document.getElementById('legalHoldModal')).show();
    }

    function openPhysicalLocationModal(docId, fileName, category, currentStatus, drawerId, folderId) {
        document.getElementById('plDocName').innerText = fileName;
        document.getElementById('plDocCategory').innerText = category;
        
        let statusBox = document.getElementById('plDynamicStatusBox');
        let cabLink = document.getElementById('plGoToCabinetBtn');
        
        // Kapag naka-map ang document sa Virtual Cabinet
        if (drawerId && folderId) {
            cabLink.href = 'virtual_cabinet.php?drawer=' + drawerId + '&folder=' + folderId + '&doc=' + docId;
            cabLink.classList.remove('d-none');
            
            if (currentStatus === 'Borrowed') {
                statusBox.innerHTML = '<div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm flex-shrink-0" style="width: 42px; height: 42px;"><i class="fas fa-hand-holding fs-5"></i></div><div><h6 class="mb-0 fw-bold text-dark">Currently Borrowed</h6><div class="fs-xs text-muted">Physical file is checked out from the cabinet.</div></div>';
                statusBox.className = 'p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 shadow-sm d-flex align-items-center';
            } else {
                statusBox.innerHTML = '<div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm flex-shrink-0" style="width: 42px; height: 42px;"><i class="fas fa-check-circle fs-5"></i></div><div><h6 class="mb-0 fw-bold text-success">Stored in Cabinet</h6><div class="fs-xs text-muted">Physical file is securely stored and available.</div></div>';
                statusBox.className = 'p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 shadow-sm d-flex align-items-center';
            }
        } 
        // Kapag WALA pang virtual cabinet mapping ang file
        else {
            cabLink.href = '#';
            cabLink.classList.add('d-none');
            
            statusBox.innerHTML = '<div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm flex-shrink-0" style="width: 42px; height: 42px;"><i class="fas fa-exclamation-circle fs-5"></i></div><div><h6 class="mb-0 fw-bold text-dark">Unmapped Document</h6><div class="fs-xs text-muted">This document is not yet assigned to a physical drawer.</div></div>';
            statusBox.className = 'p-3 bg-light border border-secondary border-opacity-25 rounded-3 shadow-sm d-flex align-items-center';
        }

        new bootstrap.Modal(document.getElementById('physicalLocationModal')).show();
    }
    <?php endif; ?>

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

    function confirmRemoveLegalHold(buttonElement) {
        const form = $(buttonElement).closest('form');
        
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Remove Legal Hold?</span>',
            html: '<p class="text-muted fs-sm mb-0">Standard auto-deletion and archiving retention policies will resume for this document.</p>',
            icon: 'warning',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Remove Hold',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-primary btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    function confirmToggleLock(buttonElement, actionType) {
        const form = $(buttonElement).closest('form');
        
        let titleText = actionType === 'lock' ? 'Lock Document?' : 'Unlock Document?';
        let descText = actionType === 'lock' 
            ? 'This prevents others from editing or uploading new versions until you unlock it.' 
            : 'This will allow other authorized users to edit or upload new versions again.';
        let iconType = actionType === 'lock' ? 'warning' : 'info';
        let confirmText = actionType === 'lock' ? 'Lock File' : 'Unlock File';
        let confirmBtnClass = actionType === 'lock' 
            ? 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100' 
            : 'btn btn-success btn-sm fw-bold px-4 rounded-pill w-100';

        Swal.fire({
            title: `<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">${titleText}</span>`,
            html: `<p class="text-muted fs-sm mb-0">${descText}</p>`,
            icon: iconType,
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: confirmBtnClass,
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }


    function confirmDispositionDelete(buttonElement) {
        const form = $(buttonElement).closest('form');
        
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Permanently Delete?</span>',
            html: '<p class="text-muted fs-sm mb-0">This document will be deleted from the database. This cannot be undone.</p>',
            icon: 'warning',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    function confirmDeletePolicy(policyId) {
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Delete Policy?</span>',
            html: '<p class="text-muted fs-sm mb-0">If this is assigned to a folder, the deletion will be safely blocked.</p>',
            icon: 'warning',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deletePolicyForm_' + policyId).submit();
            }
        });
    }

    function confirmFolderDelete(buttonElement, folderType) {
        const form = $(buttonElement).closest('form');
        
        let titleText = folderType === 'main' ? 'Delete Main Folder?' : 'Delete Sub-folder?';
        let descText = folderType === 'main' 
            ? 'All sub-folders inside must be completely empty first.' 
            : 'This folder must be completely empty before deletion.';

        Swal.fire({
            title: `<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">${titleText}</span>`,
            html: `<p class="text-muted fs-sm mb-0">${descText}</p>`,
            icon: 'warning',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    function openEditFolderModal(parentName, subName, currentPolicy) {
        document.getElementById('efParentName').value = parentName;
        document.getElementById('efSubName').value = subName;
        document.getElementById('efFolderNameDisplay').innerText = subName;
        document.getElementById('efCurrentPolicyDisplay').innerText = currentPolicy;
        new bootstrap.Modal(document.getElementById('editFolderPolicyModal')).show();
    }

    function viewConnectedFolders(btnElement) {
        let folders = JSON.parse(btnElement.getAttribute('data-folders'));
        if (folders.length === 0) {
            Swal.fire({
                title: 'No Connected Folders',
                text: 'This policy is currently not assigned to any folder. It is safe to delete.',
                icon: 'success',
                confirmButtonColor: '#3b82f6',
                customClass: { popup: 'sleek-popup', confirmButton: 'btn btn-primary shadow-sm px-4' },
                buttonsStyling: false
            });
            return;
        }

        let folderListHtml = '<ul class="text-start mb-0" style="max-height: 200px; overflow-y: auto;">';
        folders.forEach(f => {
            folderListHtml += `<li><strong>${f}</strong></li>`;
        });
        folderListHtml += '</ul>';

        Swal.fire({
            title: 'Connected Folders',
            html: `<p class="text-muted fs-sm mb-2">This policy is assigned to ${folders.length} folder(s):</p>` + folderListHtml,
            icon: 'info',
            confirmButtonColor: '#3b82f6',
            customClass: { popup: 'sleek-popup', confirmButton: 'btn btn-primary shadow-sm px-4' },
            buttonsStyling: false
        });
    }

    function openEditKeywordsModal(categoryName) {
        document.getElementById('ekFolderNameDisplay').innerText = categoryName;
        document.getElementById('ekCategoryName').value = categoryName;
        
        const input = document.getElementById('ekKeywordsInput');
        const loader = document.getElementById('ekLoader');
        
        // Reset the UI and show loading spinner
        input.value = '';
        input.disabled = true;
        loader.style.display = 'block';

        // Reset the UI and show loading spinner
        input.value = '';
        input.disabled = true;
        loader.style.display = 'block';
        $('#ekConflictWarning').addClass('d-none');
        $('#ekSaveBtn').prop('disabled', false);
        
        // Prepare data to send
        let formData = new FormData();
        formData.append('action', 'get_keywords');
        formData.append('category', categoryName);
        
        // KUNIN ANG CSRF TOKEN PARA HINDI MAHARANG NG SECURITY
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        formData.append('csrf_token', csrfToken);
        
        // Fetch current keywords mula sa malinis na API file
        $.ajax({
            url: 'actions/document_handler.php', 
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                loader.style.display = 'none';
                input.disabled = false;
                
                // Kapag may nahanap na keywords, ilagay sa text box
                if (response.status === 'success' && response.keywords) {
                    input.value = response.keywords;
                }
            },
            error: function(xhr) {
                loader.style.display = 'none';
                input.disabled = false;
                console.error("AJAX Error: Server blocked the request or failed.", xhr.responseText);
            }
        });
        
        new bootstrap.Modal(document.getElementById('editKeywordsModal')).show();
    }

    // DAGDAG: REAL-TIME KEYWORD CONFLICT LISTENER
    let keywordTimeout = null;

    $('#ekKeywordsInput').on('input', function() {
        clearTimeout(keywordTimeout);
        const keywords = $(this).val();
        const categoryName = $('#ekCategoryName').val();
        const warningBox = $('#ekConflictWarning');
        const saveBtn = $('#ekSaveBtn');
        const loader = $('#ekLoader');
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;

        if (!keywords.trim()) {
            warningBox.addClass('d-none');
            saveBtn.prop('disabled', false);
            return;
        }

        // Delay typing para hindi ma-spam ang server
        keywordTimeout = setTimeout(() => {
            loader.show();
            saveBtn.prop('disabled', true); // I-disable habang nagche-check

            let formData = new FormData();
            formData.append('action', 'check_keyword_conflicts');
            formData.append('category', categoryName);
            formData.append('keywords', keywords);
            formData.append('csrf_token', csrfToken);

            $.ajax({
                url: 'actions/document_handler.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    loader.hide();
                    if (response.status === 'conflict') {
                        // Magpakita ng pulang warning at panatilihing naka-disable ang button
                        warningBox.html('<i class="fas fa-exclamation-triangle me-1"></i> <strong>Conflict Detected:</strong><br>' + response.messages.join('<br>')).removeClass('d-none');
                        saveBtn.prop('disabled', true); 
                    } else {
                        // Clear ang error, at i-enable ulit ang Save
                        warningBox.addClass('d-none');
                        saveBtn.prop('disabled', false); 
                    }
                },
                error: function() {
                    loader.hide();
                    saveBtn.prop('disabled', false);
                }
            });
        }, 500); // 500ms typing delay
    });

    // ==========================================
    // AUTO-CLOSE DROPDOWN FIX
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        const actionDropdowns = document.querySelectorAll('.action-dropdown .dropdown-toggle');
        
        actionDropdowns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                // Hanapin ang lahat ng ibang 3-dots dropdowns at isara sila
                actionDropdowns.forEach(function(otherBtn) {
                    if (otherBtn !== btn) {
                        const bsDropdown = bootstrap.Dropdown.getInstance(otherBtn);
                        // Kung bukas ang iba, i-hide ito
                        if (bsDropdown) {
                            bsDropdown.hide();
                        }
                    }
                });
            });
        });
    });

    // ==========================================
    // VIEW DETAILS & ACTIVITY TIMELINE
    // ==========================================
    function viewFileDetails(fileName, category, filePath, uploadedAt, uploadedBy, renameHistoryBase64) {
        
        // Ligtas na ide-decode ang Base64 pabalik sa JSON string!
        let renameHistoryJson = '[]';
        try { 
            renameHistoryJson = atob(renameHistoryBase64); 
        } catch(e) { console.error("History Decode Error:", e); }

        fetch(filePath, { method: 'HEAD' }).then(response => {
            let bytes = response.headers.get('content-length');
            let sizeFormatted = 'Unknown Size';
            if (bytes) {
                let kb = bytes / 1024;
                sizeFormatted = kb >= 1024 ? (kb / 1024).toFixed(2) + ' MB' : kb.toFixed(2) + ' KB';
            }
            renderDetailsModal(fileName, category, sizeFormatted, uploadedAt, uploadedBy, renameHistoryJson);
        }).catch(() => {
            renderDetailsModal(fileName, category, 'Unknown Size', uploadedAt, uploadedBy, renameHistoryJson);
        });
    }

    // ==========================================
    // RENAME FUNCTION (SWEETALERT PROMPT)
    // ==========================================
    function renameFile(docId, currentName) {
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Rename File</span>',
            html: `
                <form id="renameForm_${docId}" action="general_docs.php" method="POST" class="text-start mt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="rename_file">
                    <input type="hidden" name="doc_id" value="${docId}">
                    <input type="hidden" name="return_url" value="${window.location.href}">
                    <label class="form-label text-muted fs-xs fw-bold text-uppercase">New File Name</label>
                    <input type="text" name="new_name" class="form-control shadow-none bg-light" value="${currentName}" required>
                </form>
            `,
            icon: 'info',
            width: 400,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Save Changes',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-primary btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('renameForm_' + docId).submit();
            }
        });
    }

    function renderDetailsModal(fileName, category, sizeFormatted, uploadedAt, uploadedBy, renameHistoryJson) {
        let historyArray = [];
        try { historyArray = JSON.parse(renameHistoryJson || '[]'); } catch(e) {}

        // DYNAMIC FILE TYPE & ICON LOGIC (GDrive Style)
        let ext = fileName.split('.').pop().toLowerCase();
        let fileType = "Document";
        let iconClass = "fas fa-file-alt text-secondary";
        
        if (['pdf'].includes(ext)) { fileType = "PDF Document"; iconClass = "fas fa-file-pdf text-danger"; }
        else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) { fileType = "Image File"; iconClass = "fas fa-image text-info"; }
        else if (['doc', 'docx'].includes(ext)) { fileType = "Word Document"; iconClass = "fas fa-file-word text-primary"; }
        else if (['xls', 'xlsx', 'csv'].includes(ext)) { fileType = "Excel Spreadsheet"; iconClass = "fas fa-file-excel text-success"; }
        else if (['ppt', 'pptx'].includes(ext)) { fileType = "PowerPoint"; iconClass = "fas fa-file-powerpoint text-warning"; }
        else if (['zip', 'rar'].includes(ext)) { fileType = "Compressed Archive"; iconClass = "fas fa-file-archive text-secondary"; }

        // DETERMINE LAST MODIFIED DATE
        let lastModified = uploadedAt;
        if (historyArray.length > 0) {
            let lastActDate = new Date(historyArray[0].date);
            lastModified = lastActDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
        }

        // GDRIVE-STYLE ACTIVITY TIMELINE DYNAMIC RENDERER
        let timelineHTML = '';
        if (historyArray.length === 0) {
            timelineHTML = '<div class="text-muted fs-sm py-5 text-center"><i class="fas fa-history fs-3 mb-2 opacity-25"></i><br>No recent activity.</div>';
        } else {
            historyArray.forEach((act) => {
                let dateObj = new Date(act.date);
                let formattedDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                
                let actTitle = '';
                let actContent = '';
                let dotColor = 'bg-primary';

                // Determine Action Layout Base on JSON "type"
                if (act.type === 'lock') {
                    actTitle = 'Checked out the item';
                    dotColor = 'bg-warning';
                    actContent = '<div class="fs-xs text-muted"><i class="fas fa-lock me-1"></i> Locked for exclusive editing</div>';
                } 
                else if (act.type === 'unlock') {
                    actTitle = 'Checked in the item';
                    dotColor = 'bg-success';
                    actContent = '<div class="fs-xs text-muted"><i class="fas fa-unlock me-1"></i> Unlocked and available</div>';
                } 
                else if (act.type === 'hold_apply') {
                    actTitle = 'Applied Legal Hold';
                    dotColor = 'bg-danger';
                    actContent = `<div class="bg-danger bg-opacity-10 rounded-3 p-2 border border-danger border-opacity-25 mt-1">
                                    <div class="fs-xs text-danger fw-bold"><i class="fas fa-balance-scale me-1"></i> Reason: ${act.reason}</div>
                                  </div>`;
                } 
                else if (act.type === 'hold_remove') {
                    actTitle = 'Removed Legal Hold';
                    dotColor = 'bg-secondary';
                    actContent = '<div class="fs-xs text-muted"><i class="fas fa-balance-scale-left me-1"></i> Standard policies resumed</div>';
                } 
                else {
                    // Fallback to Rename logic
                    actTitle = 'Renamed an item';
                    dotColor = 'bg-primary';
                    actContent = `<div class="bg-light rounded-3 p-2 border mt-1" style="border-color: #dadce0 !important;">
                                    <div class="fs-xs text-muted text-decoration-line-through mb-1">${act.old_name}</div>
                                    <div class="fs-sm text-dark fw-medium"><i class="${iconClass} me-2"></i>${act.new_name}</div>
                                  </div>`;
                }

                timelineHTML += `
                    <div class="position-relative ps-4 pb-4" style="border-left: 2px solid #e8eaed; margin-left: 8px;">
                        <div class="position-absolute ${dotColor} rounded-circle" style="width: 10px; height: 10px; left: -6px; top: 5px; outline: 3px solid #fff;"></div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fs-sm fw-bold text-dark">${act.by}</span>
                            <span class="fs-xs text-muted">${formattedDate}</span>
                        </div>
                        <div class="fs-sm text-dark">${actTitle}</div>
                        ${actContent}
                    </div>
                `;
            });
        }

        Swal.fire({
            html: `
                <div class="text-start" style="margin-top: -10px;">
                    <!-- GDRIVE STYLE HEADER -->
                    <div class="d-flex align-items-center mb-4 pb-3 border-bottom">
                        <div class="fs-1 me-3">
                            <i class="${iconClass}"></i>
                        </div>
                        <div class="overflow-hidden">
                            <h5 class="fw-bold text-dark mb-0 text-truncate" title="${fileName}">${fileName}</h5>
                        </div>
                    </div>

                    <!-- TAB INDICATORS -->
                    <ul class="nav nav-tabs nav-justified border-bottom-0 mb-3" style="border-bottom: 1px solid #dadce0 !important;">
                        <li class="nav-item">
                            <button type="button" id="btn-tab-details" class="nav-link active fw-semibold fs-sm border-0 bg-transparent w-100" 
                                style="color: #0b57d0; border-bottom: 3px solid #0b57d0 !important; border-radius: 0;"
                                onclick="document.getElementById('pane-details').classList.remove('d-none'); document.getElementById('pane-activity').classList.add('d-none'); 
                                this.style.color = '#0b57d0'; this.style.borderBottom = '3px solid #0b57d0'; 
                                let actBtn = document.getElementById('btn-tab-activity'); actBtn.style.color = '#5f6368'; actBtn.style.borderBottom = 'none';">Details</button>
                        </li>
                        <li class="nav-item">
                            <button type="button" id="btn-tab-activity" class="nav-link fw-semibold fs-sm border-0 bg-transparent w-100" 
                                style="color: #5f6368; border-bottom: none; border-radius: 0;"
                                onclick="document.getElementById('pane-activity').classList.remove('d-none'); document.getElementById('pane-details').classList.add('d-none'); 
                                this.style.color = '#0b57d0'; this.style.borderBottom = '3px solid #0b57d0'; 
                                let detBtn = document.getElementById('btn-tab-details'); detBtn.style.color = '#5f6368'; detBtn.style.borderBottom = 'none';">Activity</button>
                        </li>
                    </ul>

                    <div class="tab-content" style="max-height: 380px; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin;">
                        
                        <!-- DETAILS PANE -->
                        <div id="pane-details" class="px-2 pb-2 mt-2">
                            <h6 class="fw-bold fs-sm mb-3" style="color: #3c4043;">System Properties</h6>
                            
                            <div class="d-flex mb-3">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Type</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1">${fileType}</div>
                            </div>
                            <div class="d-flex mb-3">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Size</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1">${sizeFormatted}</div>
                            </div>
                            <div class="d-flex mb-3">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Location</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1 d-flex align-items-center">
                                    <i class="fas fa-folder text-secondary me-2"></i> ${category}
                                </div>
                            </div>
                            <div class="d-flex mb-3 mt-4">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Owner</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1 d-flex align-items-center">
                                    <div class="rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; background-color: #8ab4f8; font-size: 12px;">
                                        ${uploadedBy.charAt(0).toUpperCase()}
                                    </div>
                                    ${uploadedBy}
                                </div>
                            </div>
                            <div class="d-flex mb-3">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Modified</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1">${lastModified}</div>
                            </div>
                            <div class="d-flex mb-3">
                                <div class="fs-sm" style="width: 90px; color: #5f6368;">Created</div>
                                <div class="fs-sm text-dark fw-medium flex-grow-1">${uploadedAt}</div>
                            </div>
                        </div>

                        <!-- ACTIVITY PANE -->
                        <div id="pane-activity" class="d-none px-2 pt-3">
                            ${timelineHTML}
                            <div class="position-relative ps-4 pb-1" style="border-left: 2px solid #e8eaed; margin-left: 8px;">
                                <div class="position-absolute bg-secondary rounded-circle" style="width: 10px; height: 10px; left: -6px; top: 5px; outline: 3px solid #fff;"></div>
                                <div class="fs-sm fw-bold text-dark mb-1">Item uploaded</div>
                                <div class="fs-xs text-muted">${uploadedAt}</div>
                            </div>
                        </div>

                    </div>
                </div>
            `,
            width: 440,
            padding: '1.25rem',
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Done',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                cancelButton: 'btn btn-light fw-bold px-4 py-2 rounded-pill w-100 mt-2 border',
            },
            buttonsStyling: false
        });
    }

    function openDeclareOfficialModal(docId, fileName) {
        document.getElementById('declareDocId').value = docId;
        document.getElementById('declareDocName').value = fileName;
        new bootstrap.Modal(document.getElementById('declareOfficialModal')).show();
    }

    function confirmSoftDelete(buttonElement) {
        const form = $(buttonElement).closest('form');
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Move to Recycle Bin?</span>',
            html: '<p class="text-muted fs-sm mb-0">This file will be removed from your workspace but can be restored later.</p>',
            icon: 'warning',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Move to Bin',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    function confirmPermanentDelete(buttonElement) {
        const form = $(buttonElement).closest('form');
        Swal.fire({
            title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Permanently Delete?</span>',
            html: '<p class="text-muted fs-sm mb-0">This document will be wiped from the server completely. This action CANNOT be undone.</p>',
            icon: 'error',
            width: 360,
            padding: '1.5rem',
            showCancelButton: true,
            confirmButtonText: 'Permanently Delete',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger btn-sm fw-bold px-4 rounded-pill w-100',
                cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
            },
            buttonsStyling: false,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    // ==========================================
    // LIGTAS NA G-DRIVE 3-DOTS MENU (NO TABLE CRASH)
    // ==========================================
    $(document).ready(function() {
        // 1. Kapag binuksan ang 3-dots
        $('body').on('show.bs.dropdown', '.action-dropdown', function(e) {
            let $toggle = e.relatedTarget ? $(e.relatedTarget) : $(this).find('.dropdown-toggle');
            let $menu = $(this).find('.dropdown-menu');
            let $row = $(this).closest('tr');
            
            // GDrive Highlight Row: Iilaw ang row para alam mo kung nasaan ka
            $row.addClass('row-highlighted');

            // I-save ang original parent para maibalik mamaya
            $menu.data('original-parent', $(this));

            // Ilabas ng tuluyan sa Body para hindi makain ng table scroll
            $('body').append($menu.detach());

            // FIX: I-display block pansamantala (ngunit invisible) para makuha ang saktong sukat ng menu!
            $menu.css({ display: 'block', visibility: 'hidden' });

            // Kalkulahin ang saktong pwesto ng menu
            let btnOffset = $toggle.offset();
            let menuWidth = $menu.outerWidth();
            let menuHeight = $menu.outerHeight();
            
            let topPos = btnOffset.top + $toggle.outerHeight() + 2;
            
            // I-align nang saktong-sakto sa kanang bahagi ng button para hindi lumagpas sa screen
            let leftPos = btnOffset.left + $toggle.outerWidth() - menuWidth;

            // SMART FLIP: Kung hindi kasya sa ibaba ng screen, paitaas ito bubukas!
            if (topPos + menuHeight > $(window).height() + $(window).scrollTop()) {
                topPos = btnOffset.top - menuHeight - 2;
            }

            $menu.css({
                'display': 'block',
                'visibility': 'visible',
                'position': 'absolute',
                'top': topPos + 'px',
                'left': leftPos + 'px',
                'z-index': '9999',
                'min-width': '230px', /* I-lock ang minimum width */
                'width': 'max-content' /* Pigilang ma-deform ang text */
            });
        });

        // 2. Kapag isinara ang 3-dots
        $('body').on('hide.bs.dropdown', '.action-dropdown', function(e) {
            let $toggle = e.relatedTarget ? $(e.relatedTarget) : $(this).find('.dropdown-toggle');
            
            // Alisin ang ilaw/highlight sa row
            $toggle.closest('tr').removeClass('row-highlighted');

            // Ibalik ang nakalutang na menu nang maayos sa loob ng table
            $('body > .dropdown-menu').each(function() {
                let $menu = $(this);
                let $parent = $menu.data('original-parent');
                if ($parent) {
                    $parent.append($menu.detach());
                    // I-reset lahat ng custom css na inilagay natin
                    $menu.css({
                        'display': '',
                        'visibility': '',
                        'position': '',
                        'top': '',
                        'left': '',
                        'z-index': '',
                        'min-width': '',
                        'width': ''
                    });
                }
            });
        });

        // 3. Ligtas na FORCE CLOSE kapag nag-scroll ang table o nag-paginate
        $(document).on('scroll', '.table-scroll-container', function() {
            $('body').trigger('click'); 
        });
        
        if ($.fn.DataTable.isDataTable('#documentsTable')) {
            $('#documentsTable').on('draw.dt', function () {
                $('body').trigger('click');
            });
        }
    });
</script>
</body>
</html>