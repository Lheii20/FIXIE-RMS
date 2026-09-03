  <?php 
  require 'config/db_connect.php'; 
  require 'config/functions.php';
require_once __DIR__ . '/config/physical_records.php';
$vc3PhysicalPathSql = drms_copy_path_sql(); 

  if(!isset($_SESSION['user_id'])) {
      header("Location: index.php");
      exit();
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
  $can_manage_disposition = has_permission($conn, $_SESSION['user_id'], 'can_manage_disposition');
  $can_approve_disposition = has_permission($conn, $_SESSION['user_id'], 'can_approve_disposition');
  $can_execute_disposition = has_permission($conn, $_SESSION['user_id'], 'can_execute_disposition');

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
      // Documents store the sub-folder name as their category, so sub-folder
      // names must remain unique across the complete records structure.
      $stmt = $conn->prepare("SELECT id FROM document_categories WHERE sub_category = ? LIMIT 1");
      $stmt->bind_param("s", $sub);
      $stmt->execute();
      return $stmt->get_result()->num_rows > 0;
  }

  function protectedSystemParentExists($conn, $parent) {
      $stmt = $conn->prepare(
          "SELECT id
           FROM document_categories
           WHERE parent_category = ?
             AND is_system_folder = 1
           LIMIT 1"
      );
      $stmt->bind_param("s", $parent);
      $stmt->execute();
      return $stmt->get_result()->num_rows > 0;
  }

  function recordPrefixExists($conn, $record_prefix) {
      $stmt = $conn->prepare(
          "SELECT id
           FROM document_categories
           WHERE record_prefix = ?
           LIMIT 1"
      );
      $stmt->bind_param("s", $record_prefix);
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
          $return_url = $_POST['return_url'] ?? 'documents.php';
        
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
        
          $sep = strpos($return_url, '?') !== false ? '&' : '?';
          header("Location: " . $return_url . $sep . "success=" . urlencode("Share settings updated successfully."));
          exit();
      }

      if ($_POST['action'] === 'rename_file') {
          if ($role === 'Admin') redirectDocumentsWithMessage("error", "System Administrators cannot modify documents.");
        
          $doc_id = intval($_POST['doc_id']);
          $new_name = trim($_POST['new_name']);
          $return_url = $_POST['return_url'] ?? 'documents.php';
        
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
          $return_url = $_POST['return_url'] ?? 'documents.php';
        
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
              header("Location: documents.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
              exit();
          }
          $policy_id = intval($_POST['policy_id']);
          $policy_name = trim($_POST['policy_name']);
          $act_years = intval($_POST['active_years']);
          $act_months = intval($_POST['active_months']);
          $arch_years = intval($_POST['archive_years']);
          $arch_months = intval($_POST['archive_months']);
          $action_after = trim($_POST['action_after_retention'] ?? 'Destroy');
          $allowed_retention_actions = ['Destroy', 'Permanent Archive'];

          if (!in_array($action_after, $allowed_retention_actions, true)) {
              header("Location: documents.php?error=" . urlencode("Select a valid action after retention."));
              exit();
          }

          if ($act_years < 0 || $arch_years < 0 || $act_months < 0 || $act_months > 11 || $arch_months < 0 || $arch_months > 11) {
              header("Location: documents.php?error=" . urlencode("Retention years must be zero or greater, and months must be from 0 to 11."));
              exit();
          }
        
          if (($act_years + $arch_years + $act_months + $arch_months) < 1) {
              header("Location: documents.php?error=" . urlencode("Total retention period must be at least 1 month."));
              exit();
          }

          $stmt_edit = $conn->prepare("UPDATE retention_policies SET policy_name=?, active_years=?, active_months=?, archive_years=?, archive_months=?, action_after_retention=? WHERE policy_id=?");
          $stmt_edit->bind_param("siiiisi", $policy_name, $act_years, $act_months, $arch_years, $arch_months, $action_after, $policy_id);
          if ($stmt_edit->execute()) {
              if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'UPDATE_POLICY', "Updated Policy ID: $policy_id; active {$act_years}Y {$act_months}M, archive {$arch_years}Y {$arch_months}M, action: $action_after.");
              header("Location: documents.php?success=" . urlencode("Retention Policy updated successfully."));
              exit();
          } else {
              header("Location: documents.php?error=" . urlencode("Failed to update policy."));
              exit();
          }
      }

      if ($_POST['action'] === 'create_policy') {
          if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
              header("Location: documents.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
              exit();
          }

          $policy_name = trim($_POST['policy_name'] ?? '');
          $act_years = intval($_POST['active_years'] ?? 0);
          $act_months = intval($_POST['active_months'] ?? 0);
          $arch_years = intval($_POST['archive_years'] ?? 0);
          $arch_months = intval($_POST['archive_months'] ?? 0);
          $action_after = trim($_POST['action_after_retention'] ?? 'Destroy');
          $allowed_retention_actions = ['Destroy', 'Permanent Archive'];

          if ($policy_name === '') {
              header("Location: documents.php?error=" . urlencode("Policy name is required."));
              exit();
          }
          if (!in_array($action_after, $allowed_retention_actions, true)) {
              header("Location: documents.php?error=" . urlencode("Select a valid action after retention."));
              exit();
          }
          if ($act_years < 0 || $arch_years < 0 || $act_months < 0 || $act_months > 11 || $arch_months < 0 || $arch_months > 11) {
              header("Location: documents.php?error=" . urlencode("Retention years must be zero or greater, and months must be from 0 to 11."));
              exit();
          }
          if (($act_years + $arch_years + $act_months + $arch_months) < 1) {
              header("Location: documents.php?error=" . urlencode("Total retention period must be at least 1 month."));
              exit();
          }

          $stmt_create_policy = $conn->prepare("INSERT INTO retention_policies (policy_name, active_years, active_months, archive_years, archive_months, action_after_retention) VALUES (?, ?, ?, ?, ?, ?)");
          $stmt_create_policy->bind_param("siiiis", $policy_name, $act_years, $act_months, $arch_years, $arch_months, $action_after);

          if ($stmt_create_policy->execute()) {
              if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'CREATE_POLICY', "Created Policy: $policy_name; active {$act_years}Y {$act_months}M, archive {$arch_years}Y {$arch_months}M, action: $action_after.");
              header("Location: documents.php?success=" . urlencode("Retention Policy created successfully."));
              exit();
          } else {
              header("Location: documents.php?error=" . urlencode("Failed to create policy."));
              exit();
          }
      }

      // =============== DAGDAG NA CODE PARA SA DELETE POLICY ===============
      if ($_POST['action'] === 'delete_policy') {
          if (!has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')) {
              header("Location: documents.php?error=" . urlencode("You do not have permission to edit Retention Policies."));
              exit();
          }

          $policy_id = intval($_POST['policy_id']);

          // 1. Suriin kung may folder na gumagamit pa ng policy na ito
          $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM document_categories WHERE policy_id = ?");
          $chk->bind_param("i", $policy_id);
          $chk->execute();
          $in_use = $chk->get_result()->fetch_assoc()['cnt'];

          if ($in_use > 0) {
              header("Location: documents.php?error=" . urlencode("Cannot delete policy. It is currently assigned to $in_use folder(s). Please reassign them first."));
              exit();
          }

          // 2. Kapag walang gumagamit, tuluyang burahin
          $stmt_del = $conn->prepare("DELETE FROM retention_policies WHERE policy_id = ?");
          $stmt_del->bind_param("i", $policy_id);

          if ($stmt_del->execute()) {
              if (function_exists('log_audit_action')) log_audit_action($conn, $_SESSION['user_id'], 'DELETE_POLICY', "Permanently deleted Retention Policy ID: $policy_id.");
              header("Location: documents.php?success=" . urlencode("Retention Policy deleted successfully."));
              exit();
          } else {
              header("Location: documents.php?error=" . urlencode("Failed to delete policy."));
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
              header("Location: documents.php?" . $new_qs);
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
          $record_prefix = strtoupper(trim($_POST['record_prefix'] ?? ''));
        
          $roles_to_assign = [];

          if ($is_new_parent) {
              if (!$can_manage) redirectDocumentsWithMessage("error", "You do not have permission to create Parent Folders.");
              $parent = trim($_POST['new_parent_category'] ?? '');
              if ($parent === '') redirectDocumentsWithMessage("error", "Parent Folder name cannot be empty.");
              if (strcasecmp($parent, 'PO Lifecycle Official Records') === 0) {
                  redirectDocumentsWithMessage("error", "That name is reserved for the protected system records folder.");
              }
              if (parentFolderExists($conn, $parent)) redirectDocumentsWithMessage("error", "Parent Folder already exists.");
            
              if (!$is_top_mgmt) {
                  $roles_to_assign[] = $role;
              } else {
                  $roles_to_assign = isset($_POST['assigned_roles']) ? array_map('trim', $_POST['assigned_roles']) : [];
              }

              $sub = '';
              $record_prefix = null;
              $folder_policy = null;
          } else {
              if ($parent === '') redirectDocumentsWithMessage("error", "Please select a Parent Folder.");
              if (!parentFolderExists($conn, $parent)) redirectDocumentsWithMessage("error", "Selected Parent Folder does not exist.");
              if (protectedSystemParentExists($conn, $parent)) {
                  redirectDocumentsWithMessage("error", "Protected system folders cannot accept manually created sub-folders.", $parent);
              }
              if ($sub === '') redirectDocumentsWithMessage("error", "Sub-folder name is required.", $parent);
              if (subFolderExists($conn, $parent, $sub)) redirectDocumentsWithMessage("error", "That sub-folder name is already used in the records structure.", $parent);
              if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $record_prefix)) {
                  redirectDocumentsWithMessage("error", "Record Code must contain 2-10 uppercase letters or numbers and must begin with a letter.", $parent);
              }
              if (recordPrefixExists($conn, $record_prefix)) {
                  redirectDocumentsWithMessage("error", "Record Code $record_prefix is already assigned to another folder.", $parent);
              }
            
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

          $drawer_id = null; // VC3: digital classification does not assign physical storage.
          $roles_to_assign = array_values(array_unique(array_filter($roles_to_assign)));
          $assigned_to_role = !empty($roles_to_assign)
              ? implode(', ', $roles_to_assign)
              : null;
          $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, policy_id, classification_keywords, assigned_to_role, record_prefix, drawer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
          $stmt_create->bind_param("ssisssi", $parent, $sub, $folder_policy, $keywords, $assigned_to_role, $record_prefix, $drawer_id);
        
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
                  if (protectedSystemParentExists($conn, $parent_name)) {
                      redirectDocumentsWithMessage(
                          "error",
                          "The PO Lifecycle Official Records folder is protected and cannot be deleted."
                      );
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
                  $folder_stmt = $conn->prepare(
                      "SELECT id, is_system_folder
                       FROM document_categories
                       WHERE sub_category = ?
                         AND parent_category = ?
                       LIMIT 1"
                  );
                  $folder_stmt->bind_param("ss", $sub_name, $parent_name);
                  $folder_stmt->execute();
                  $folder_row = $folder_stmt->get_result()->fetch_assoc();
                  $folder_stmt->close();

                  if (!$folder_row) {
                      redirectDocumentsWithMessage("error", "Folder not found.", $parent_name);
                  }
                  if ((int) $folder_row['is_system_folder'] === 1) {
                      redirectDocumentsWithMessage(
                          "error",
                          "Protected workflow folders cannot be deleted.",
                          $parent_name
                      );
                  }

                  $chk = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE category = ?");
                  $chk->bind_param("s", $sub_name);
                  $chk->execute();
                  $total_files = $chk->get_result()->fetch_assoc()['total'];
                
                  if ($total_files == 0) {
                      $del_id = (int) $folder_row['id'];
                      $conn->query("DELETE FROM category_role_access WHERE category_id = $del_id");
                    
                      $del = $conn->prepare("DELETE FROM document_categories WHERE id = ?");
                      $del->bind_param("i", $del_id);
                      $del->execute();

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
  $parent_system_flags = [];
  $folder_metadata = [];

  $cat_query = $conn->query("
      SELECT
          dc.id,
          TRIM(dc.parent_category) as p_cat,
          TRIM(dc.sub_category) as s_cat,
          MAX(dc.record_prefix) as record_prefix,
          MAX(dc.system_folder_key) as system_folder_key,
          MAX(dc.is_system_folder) as is_system_folder,
          MAX(dc.system_sort_order) as system_sort_order,
          GROUP_CONCAT(cra.role_name) as roles
      FROM document_categories dc
      LEFT JOIN category_role_access cra ON dc.id = cra.category_id
      GROUP BY dc.id
      ORDER BY
          MAX(dc.is_system_folder) DESC,
          dc.parent_category ASC,
          COALESCE(MAX(dc.system_sort_order), 32767) ASC,
          dc.id ASC
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
          if (!isset($parent_system_flags[$p_key])) {
              $parent_system_flags[$p_key] = false;
          }
          if ((int) $row['is_system_folder'] === 1) {
              $parent_system_flags[$p_key] = true;
          }
        
          if ($s_cat !== '') {
              $s_exists = false;
              foreach($parent_folders[$p_key] as $ext_s) {
                  if(strcasecmp($ext_s, $s_cat) == 0) { $s_exists = true; break; }
              }
              if(!$s_exists) { $parent_folders[$p_key][] = $s_cat; }
              if (!isset($folder_metadata[$p_key])) {
                  $folder_metadata[$p_key] = [];
              }
              $folder_metadata[$p_key][$s_cat] = [
                  'record_prefix' => trim((string) $row['record_prefix']),
                  'system_folder_key' => trim((string) $row['system_folder_key']),
                  'is_system_folder' => (int) $row['is_system_folder'] === 1,
                  'system_sort_order' => $row['system_sort_order'],
              ];
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

  $current_parent_is_system = !empty($parent_filter) &&
      !empty($parent_system_flags[$parent_filter]);

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
      $count_sql = "SELECT category, COUNT(*) as cnt FROM documents WHERE status = 'Active' AND record_phase = 'Official' AND category IN ($placeholders) GROUP BY category";
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
  $search = trim((string) ($_GET['search'] ?? ''));
  $type_filter = $_GET['type'] ?? '';
  $parent_filter = $_GET['parent'] ?? '';
  $doc_status = (string) ($_GET['doc_status'] ?? '');
  $view_disposition = isset($_GET['disposition']) && $_GET['disposition'] == '1';
  $view_disposition_history = isset($_GET['disposition_history']) && $_GET['disposition_history'] == '1';
  $view_disposition_section = $view_disposition || $view_disposition_history;
  $view_archives = isset($_GET['view_archives']) && $_GET['view_archives'] == '1';
  $view_shared = isset($_GET['shared']) && $_GET['shared'] == '1';
  $sort = (string) ($_GET['sort'] ?? 'date_desc');

  $allowed_record_statuses = ['Archived'];
  $allowed_record_sorts = ['date_desc', 'date_asc', 'name_asc', 'name_desc'];
  if (!in_array($doc_status, $allowed_record_statuses, true)) $doc_status = '';
  if (!in_array($sort, $allowed_record_sorts, true)) $sort = 'date_desc';
  if (!$view_archives && !$view_disposition_section && !$view_shared && $doc_status === 'Archived') {
      $view_archives = true;
  }
  if ($view_archives || $view_disposition_section || $view_shared) $doc_status = '';
  $records_query_active = $search !== '' || $doc_status !== '' || $sort !== 'date_desc';

  $order_by = "d.uploaded_at DESC";
  if ($sort === 'date_asc') $order_by = "d.uploaded_at ASC";
  elseif ($sort === 'name_asc') $order_by = "d.file_name ASC";
  elseif ($sort === 'name_desc') $order_by = "d.file_name DESC";

  // ----------------------------------------------------
  // SYSTEM ADMINISTRATOR HARD REDIRECT
  // ----------------------------------------------------
  if ($role === 'Admin') {
      if ($view_disposition_section || $view_archives || $view_shared) {
          header("Location: documents.php?error=" . urlencode("Unauthorized Access: System Administrators are restricted from viewing document directories."));
          exit();
      }
  }

  if ($view_disposition_section && !$can_view_disposition && $role !== 'Admin') {
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
  if($view_disposition_history) $return_params[] = "disposition_history=1";
  if($view_shared) $return_params[] = "shared=1";
  if(!empty($search)) $return_params[] = "search=".urlencode($search);
  if(!empty($doc_status)) $return_params[] = "doc_status=".urlencode($doc_status);
  if($sort !== 'date_desc') $return_params[] = "sort=".urlencode($sort);

  $exact_return_url = "documents.php" . (!empty($return_params) ? "?" . implode("&", $return_params) : "");

  $page_title = "Official Records";
  $page_subtitle = "Signed, finalized, and locked digital records subject to retention.";
  $show_back_btn = false;
  $back_url = "documents.php";

  if ($view_disposition) {
      $page_title = "Ready for Disposition";
      $page_subtitle = "Official records whose retention period has ended and await an authorized disposition decision.";
      $show_back_btn = true;
  } elseif ($view_disposition_history) {
      $page_title = "Disposition History";
      $page_subtitle = "Digital disposition evidence. Destroyed files cannot be opened; registered paper copies remain tracked in Virtual Cabinet.";
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
  $breadcrumbs[] = ['label' => 'Official Records', 'url' => 'documents.php', 'active' => empty($view_archives) && empty($view_disposition_section) && empty($view_shared) && empty($parent_filter) && empty($type_filter)];

  if ($view_archives) {
      $breadcrumbs[] = ['label' => 'Archived', 'url' => 'documents.php?view_archives=1', 'active' => empty($parent_filter) && empty($type_filter)];
  } elseif ($view_disposition) {
      $breadcrumbs[] = ['label' => 'Ready for Disposition', 'url' => 'documents.php?disposition=1', 'active' => empty($parent_filter) && empty($type_filter)];
  } elseif ($view_disposition_history) {
      $breadcrumbs[] = ['label' => 'Disposition History', 'url' => 'documents.php?disposition_history=1', 'active' => true];
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

  if (empty($view_archives) && empty($view_disposition_section) && empty($view_shared) && empty($parent_filter) && empty($type_filter)) {
      $breadcrumbs[0]['active'] = true;
  }

  $hide_upload_button = $view_archives || $view_disposition_section || $view_shared;
  if ($current_parent_is_system) {
      $hide_upload_button = true;
  }
  if (!has_permission($conn, $_SESSION['user_id'], 'can_upload_documents') || $role === 'Admin') {
      $hide_upload_button = true;
  }

  // ==========================================
  // DOCUMENTS QUERIES (UNIFIED RBAC & SHARE CHECK)
  // ==========================================
  $disposition_docs = null;
  if ($view_disposition_section) {
      $retention_base_sql = "COALESCE(d.declared_at, d.uploaded_at)";
      if ($view_disposition_history) {
          $disp_where = [
              "d.record_phase = 'Official'",
              "d.disposition_status IN ('Destroyed', 'Permanently Archived')",
              "req.status = 'Executed'"
          ];
      } else {
          $disp_where = [
              "d.record_phase = 'Official'",
              "p.policy_id IS NOT NULL",
              "COALESCE(d.disposition_status, 'Pending') NOT IN ('Destroyed', 'Permanently Archived')",
              "(d.disposition_status = 'Ready for Disposition' OR (COALESCE(d.disposition_status, 'Pending') = 'Pending' AND DATE_ADD(DATE_ADD($retention_base_sql, INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR), INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH) <= NOW()))",
              "d.is_legal_hold = 0"
          ];
      }
    
      $disp_params = [];
      $disp_types = "";
    
      if (!empty($search)) {
        $disp_where[] = "(d.file_name LIKE ? OR d.original_file_name LIKE ? OR d.record_number LIKE ? OR d.business_reference LIKE ? OR d.doc_type LIKE ? OR d.category LIKE ?)";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_params[] = "%$search%";
        $disp_types .= "ssssss";
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
                 DATE_ADD(DATE_ADD($retention_base_sql, INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR), INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH) AS retention_date,
                 locker.full_name AS locked_by_name,
                 req.request_id AS disposition_request_id,
                 req.requested_action AS disposition_requested_action,
                 req.reason AS disposition_reason,
                 req.retention_authority AS disposition_authority,
                 req.requested_by AS disposition_requested_by,
                 req.requested_at AS disposition_requested_at,
                 req.status AS disposition_request_status,
                 req.reviewed_by AS disposition_reviewed_by,
                 req.reviewed_at AS disposition_reviewed_at,
                 req.review_notes AS disposition_review_notes,
                 req.executed_by AS disposition_executed_by,
                 req.executed_at AS disposition_executed_at,
                 req.execution_method AS disposition_execution_method,
                 req.execution_notes AS disposition_execution_notes,
                 req.execution_result_hash AS disposition_execution_result_hash,
                 req.certificate_id AS disposition_certificate_id,
                 requester.full_name AS disposition_requester_name,
                 reviewer.full_name AS disposition_reviewer_name,
                 executor.full_name AS disposition_executor_name,
                 pdl.evidence_number AS physical_disposal_evidence_number,
                 pdl.disposal_method AS physical_disposal_method,
                 pdl.disposed_at AS physical_disposed_at,
                 pdl.disposed_by_name AS physical_disposed_by_name,
                 pdl.source_path AS physical_disposal_source_path,
                 (SELECT COUNT(*) FROM virt_document_locations retained_copy WHERE retained_copy.document_id=d.doc_id) AS registered_physical_copy,
                 $vc3PhysicalPathSql as full_physical_path
          FROM documents d
          LEFT JOIN document_categories dc ON d.category = dc.sub_category
          LEFT JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          LEFT JOIN users locker ON d.locked_by = locker.user_id
          LEFT JOIN disposition_requests req ON req.request_id = (
              SELECT latest_req.request_id
              FROM disposition_requests latest_req
              WHERE latest_req.doc_id = d.doc_id
              ORDER BY latest_req.request_id DESC
              LIMIT 1
          )
          LEFT JOIN users requester ON requester.user_id = req.requested_by
          LEFT JOIN users reviewer ON reviewer.user_id = req.reviewed_by
          LEFT JOIN users executor ON executor.user_id = req.executed_by
          LEFT JOIN physical_disposition_logs pdl ON pdl.document_id=d.doc_id
/* VC3 physical path is resolved by its independent folder ID. 0 */
          WHERE $disp_where_clause
          ORDER BY " . ($view_disposition_history ? "req.executed_at DESC, d.doc_id DESC" : "retention_date ASC");
        
      // SECURITY: Prevent backend from fetching actual documents if role is Admin
      if ($role !== 'Admin') {
          $stmt_disp = $conn->prepare($disp_query_sql);
          if (!empty($disp_params)) $stmt_disp->bind_param($disp_types, ...$disp_params);
          $stmt_disp->execute();
          $disposition_docs = $stmt_disp->get_result();
      }
  }

  $where = [];
  $effective_doc_status = $view_archives ? 'Archived' : ($doc_status !== '' ? $doc_status : 'Active');
  $where[] = "d.status = '" . $effective_doc_status . "'";
  if ($effective_doc_status === 'Archived') {
      $where[] = "COALESCE(d.disposition_status, '') <> 'Destroyed'";
  }
  $where[] = "d.record_phase = 'Official'"; // STRICT ENFORCEMENT: Only Official Records
  $where[] = "COALESCE(d.disposition_status, '') <> 'Destroyed'";

  $params = [];
  $types = "";

  if (!empty($search)) {
    $where[] = "(d.file_name LIKE ? OR d.original_file_name LIKE ? OR d.record_number LIKE ? OR d.business_reference LIKE ? OR d.doc_type LIKE ? OR d.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ssssss";
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

  // Tanging OFFICIAL RECORDS lang ang lalabas dito
  if (!$view_archives && !$view_disposition_section && !$view_shared) {
      $where[] = "d.record_phase = 'Official'";
  }

  $whereClause = implode(' AND ', $where);

  $query = "SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name, locker.full_name AS locked_by_name,
                   vdl.status AS physical_status, vdl.physical_folder_id, NULL AS drawer_id, vdl.physical_folder_id AS cat_id,
                   $vc3PhysicalPathSql as full_physical_path
            FROM documents d
            LEFT JOIN purchase_orders p ON d.po_id = p.po_id
            LEFT JOIN users u ON d.uploaded_by = u.user_id
            LEFT JOIN users locker ON d.locked_by = locker.user_id
            LEFT JOIN virt_document_locations vdl ON d.doc_id = vdl.document_id
            LEFT JOIN document_categories dc ON d.category = dc.sub_category
/* VC3 physical path is resolved by its independent folder ID. 1 */
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
      <title>Official Records - Fixie DRMS</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="assets/css/bootstrap.min.css" rel="stylesheet">
      <link href="assets/css/style.css" rel="stylesheet">
      <link rel="stylesheet" href="assets/css/all.min.css">
      <link href="assets/css/mobile-drive-lists.css" rel="stylesheet">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
      <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    
  <link rel="stylesheet" href="assets/css/physical-records.css?v=vc4b2-1">
  <style>
    #dispositionExecutionModal .modal-content{max-height:calc(100dvh - 32px);overflow:hidden}
    #dispositionExecutionModal .modal-header,#dispositionExecutionModal .modal-footer{flex:none}
    #dispositionExecutionForm{display:flex;flex-direction:column;min-height:0;overflow:hidden}
    #dispositionExecutionForm .modal-body{overflow:auto;min-height:0}
    #dispositionDigitalScope[hidden]{display:none!important}
    .vc4b-digital-scope{border:1px solid #c8d3e2;border-radius:8px;padding:12px;background:#f7f9fc;font-size:14px;line-height:1.5}
    .vc4b-digital-scope label{display:flex;gap:10px;align-items:flex-start;margin:0;color:#24364d}
    .vc4b-digital-scope input{flex:0 0 17px;width:17px;height:17px;margin-top:3px;accent-color:#1959d4}
  </style>
</head>
  <body class="bg-f8f9fa page-documents">
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
                          <?php if($view_disposition_history): ?><i class="fas fa-clock-rotate-left text-secondary me-2"></i><?php endif; ?>
                          <?php if($view_shared): ?><i class="fas fa-user-friends text-info me-2"></i><?php endif; ?>
                          <?php echo $page_title; ?>
                      </h3>
                      <p class="text-muted mb-0 small"><?php echo $page_subtitle; ?></p>
                  </div>
              </div>
            
              <div class="d-flex gap-2 align-items-center">
                  <?php if (
                      $can_manage &&
                      !$view_archives &&
                      !$view_disposition_section &&
                      !$view_shared &&
                      !$records_query_active &&
                      empty($type_filter)
                  ): ?>
                      <?php if (empty($parent_filter)): ?>
                          <button type="button" class="btn btn-sm btn-primary px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#createParentFolderModal">
                              <i class="fas fa-folder-plus me-1"></i> New folder
                          </button>
                      <?php elseif (!$current_parent_is_system): ?>
                          <button type="button" class="btn btn-sm btn-primary px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#createSubFolderModal">
                              <i class="fas fa-folder-plus me-1"></i> New sub-folder
                          </button>
                      <?php endif; ?>
                  <?php endif; ?>

                  <!-- 3-DOTS OPTIONS MENU -->
                  <div class="dropdown">
                      <button class="btn bg-transparent border-0 shadow-none d-flex align-items-center justify-content-center hover-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="body" style="width: 40px; height: 40px; transition: all 0.2s;" title="More Actions">
                          <i class="fas fa-ellipsis-v text-dark fs-5"></i>
                      </button>
                    
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 mt-2 border-0" style="min-width: 220px;">
                        
                          <?php if ($role !== 'Admin'): ?>
                              <li>
                                  <a class="dropdown-item fw-medium py-2 <?php echo $view_archives ? 'active text-white bg-secondary' : 'text-dark'; ?>" href="documents.php?view_archives=1">
                                      <i class="fas fa-archive <?php echo $view_archives ? 'text-white' : 'text-secondary'; ?> me-2 w-15px"></i> Archived Records
                                  </a>
                              </li>
                            
                              <?php if ($can_view_disposition): ?>
                              <li>
                                  <a class="dropdown-item fw-medium py-2 <?php echo $view_disposition ? 'active text-white bg-warning' : 'text-dark'; ?>" href="documents.php?disposition=1">
                                      <i class="fas fa-trash-alt <?php echo $view_disposition ? 'text-white' : 'text-warning'; ?> me-2 w-15px"></i> Ready for Disposition
                                  </a>
                              </li>
                              <li>
                                  <a class="dropdown-item fw-medium py-2 <?php echo $view_disposition_history ? 'active text-white bg-secondary' : 'text-dark'; ?>" href="documents.php?disposition_history=1">
                                      <i class="fas fa-clock-rotate-left <?php echo $view_disposition_history ? 'text-white' : 'text-secondary'; ?> me-2 w-15px"></i> Disposition History
                                  </a>
                              </li>
                              <?php endif; ?>
                          <?php endif; ?>

                          <!-- Edit Retention Policies -->
                          <?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
                          <li>
                              <button type="button" class="dropdown-item fw-medium py-2 text-dark" data-bs-toggle="modal" data-bs-target="#editPoliciesModal">
                                  <i class="fas fa-balance-scale text-danger me-2 w-15px"></i> Retention Settings
                              </button>
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
            
              <?php
                  $records_context_params = [];
                  if ($view_archives) $records_context_params['view_archives'] = '1';
                  if ($view_disposition) $records_context_params['disposition'] = '1';
                  if ($view_disposition_history) $records_context_params['disposition_history'] = '1';
                  if ($view_shared) $records_context_params['shared'] = '1';
                  if (!empty($parent_filter)) $records_context_params['parent'] = $parent_filter;
                  if (!empty($type_filter)) $records_context_params['type'] = $type_filter;

                  $records_filter_reset_params = $records_context_params;
                  if ($search !== '') $records_filter_reset_params['search'] = $search;

                  $records_search_clear_params = $records_context_params;
                  if ($doc_status !== '') $records_search_clear_params['doc_status'] = $doc_status;
                  if ($sort !== 'date_desc') $records_search_clear_params['sort'] = $sort;

                  $records_filter_count = ($doc_status !== '' ? 1 : 0) + ($sort !== 'date_desc' ? 1 : 0);
                  $records_filter_reset_url = 'documents.php' . (!empty($records_filter_reset_params) ? '?' . http_build_query($records_filter_reset_params) : '');
                  $records_search_clear_url = 'documents.php' . (!empty($records_search_clear_params) ? '?' . http_build_query($records_search_clear_params) : '');
                  $show_record_status_filter = !$view_archives && !$view_disposition_section && !$view_shared;
              ?>
              <form method="GET" action="documents.php" class="records-search-form" role="search">
                  <?php foreach ($records_context_params as $param_name => $param_value): ?>
                      <input type="hidden" name="<?php echo htmlspecialchars($param_name); ?>" value="<?php echo htmlspecialchars($param_value); ?>">
                  <?php endforeach; ?>

                  <div class="records-search-control">
                      <label class="records-search-field" for="documentSearchInput">
                          <span class="records-search-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
                          <input type="search" name="search" id="documentSearchInput" placeholder="Search name, record number, or reference" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                          <?php if ($search !== ''): ?>
                              <a class="records-search-clear" href="<?php echo htmlspecialchars($records_search_clear_url); ?>" title="Clear search" aria-label="Clear search"><i class="fas fa-times"></i></a>
                          <?php endif; ?>
                      </label>

                      <button class="records-search-submit" type="submit" title="Search records" aria-label="Search records">
                          <i class="fas fa-arrow-right" aria-hidden="true"></i>
                      </button>

                      <div class="dropdown records-filter-dropdown">
                          <button class="records-filter-toggle<?php echo $records_filter_count > 0 ? ' is-active' : ''; ?>" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                              <i class="fas fa-sliders-h" aria-hidden="true"></i>
                              <span>Filters</span>
                              <?php if ($records_filter_count > 0): ?><span class="records-filter-count"><?php echo $records_filter_count; ?></span><?php endif; ?>
                          </button>

                          <div class="dropdown-menu dropdown-menu-end records-filter-menu">
                              <div class="records-filter-heading">
                                  <div>
                                      <strong>Refine records</strong>
                                      <span>Choose how records are displayed.</span>
                                  </div>
                                  <i class="fas fa-filter" aria-hidden="true"></i>
                              </div>

                              <div class="records-filter-fields">
                                  <?php if ($show_record_status_filter): ?>
                                      <label class="records-filter-field">
                                          <span>Record status</span>
                                          <select name="doc_status">
                                              <option value="">Current records</option>
                                              <option value="Archived" <?php echo $doc_status === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                                          </select>
                                      </label>
                                  <?php endif; ?>

                                  <label class="records-filter-field">
                                      <span>Sort by</span>
                                      <select name="sort">
                                          <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Newest first</option>
                                          <option value="date_asc" <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Oldest first</option>
                                          <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A–Z</option>
                                          <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z–A</option>
                                      </select>
                                  </label>
                              </div>

                              <div class="records-filter-actions">
                                  <a href="<?php echo htmlspecialchars($records_filter_reset_url); ?>">Reset filters</a>
                                  <button type="submit"><i class="fas fa-check me-1" aria-hidden="true"></i>Apply</button>
                              </div>
                          </div>
                      </div>
                  </div>
              </form>
          </div>
      </div>
      <!-- END HEADER -->

      <?php if (!$view_archives && !$view_disposition_section && !$view_shared && !$records_query_active): ?>
         
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
                          $is_system_parent = !empty($parent_system_flags[$p]);
                      ?>
                      <div class="col-md-4 col-sm-6">
                          <div class="folder-card p-3 h-100 position-relative" onclick="window.location='documents.php?parent=<?php echo urlencode($p); ?>'">
                              <div class="d-flex align-items-center pe-4">
                                  <div class="folder-icon-box bg-light text-primary border">
                                      <i class="fas fa-folder fa-lg"></i>
                                  </div>
                                  <div class="ms-3 flex-grow-1">
                                      <h6 class="mb-1 fw-bold text-dark text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($p); ?></h6>
                                      <p class="text-muted small mb-0">
                                          <?php if ($is_system_parent): ?><i class="fas fa-lock me-1"></i>Protected workflow folders<?php else: ?><i class="fas fa-file-alt me-1"></i><?php echo $role === 'Admin' ? 'Restricted' : $fileCount . ' active files'; ?><?php endif; ?>
                                      </p>
                                  </div>
                              </div>

                              <?php if ($can_manage && !$is_system_parent): ?>
                                  <div class="action-dropdown dropdown position-absolute top-0 end-0 m-2">
                                      <button class="btn-dots bg-transparent border-0 shadow-none dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="body" onclick="event.stopPropagation();" aria-label="Folder actions"><i class="fas fa-ellipsis-v small"></i></button>
                                      <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3" onclick="event.stopPropagation();">
                                          <li>
                                              <form action="documents.php" method="POST" class="m-0">
                                                  <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                  <input type="hidden" name="action" value="delete_folder">
                                                  <input type="hidden" name="delete_type" value="parent">
                                                  <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($p); ?>">
                                                  <button type="button" class="dropdown-item fw-medium text-danger" onclick="confirmFolderDelete(this, 'main')"><i class="fas fa-trash-alt me-2"></i> Delete empty folder</button>
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
                          $folder_meta = $folder_metadata[$parent_filter][$s] ?? [];
                          $is_system_subfolder = !empty($folder_meta['is_system_folder']);
                          $folder_record_prefix = trim((string) ($folder_meta['record_prefix'] ?? ''));
                        
                          // Kukunin natin ang kasalukuyang policy ng folder na ito
                          $current_pol_name = "No Policy Assigned";
                          $q_pol = $conn->prepare("SELECT p.policy_name FROM document_categories dc LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id WHERE dc.parent_category = ? AND dc.sub_category = ? LIMIT 1");
                          $q_pol->bind_param("ss", $parent_filter, $s);
                          $q_pol->execute();
                          $r_pol = $q_pol->get_result()->fetch_assoc();
                          if($r_pol && $r_pol['policy_name']) { $current_pol_name = $r_pol['policy_name']; }
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
                                  <div class="d-flex align-items-center gap-2 min-w-0">
                                      <?php if ($folder_record_prefix !== ''): ?>
                                          <span class="badge bg-light text-primary border fw-semibold"><?php echo htmlspecialchars($folder_record_prefix); ?></span>
                                      <?php endif; ?>
                                      <span class="text-muted small fw-medium text-truncate"><?php echo $role === 'Admin' ? 'Restricted' : $fileCount . ' items'; ?></span>
                                  </div>
                                  <i class="fas <?php echo $is_system_subfolder ? 'fa-lock' : 'fa-chevron-right'; ?> text-primary opacity-50 small"></i>
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

                                      <?php if (!$is_system_subfolder): ?>
                                          <li><hr class="dropdown-divider"></li>
                                          <li>
                                              <form action="documents.php" method="POST" class="m-0">
                                                  <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                  <input type="hidden" name="action" value="delete_folder">
                                                  <input type="hidden" name="delete_type" value="sub">
                                                  <input type="hidden" name="parent_name" value="<?php echo htmlspecialchars($parent_filter); ?>">
                                                  <input type="hidden" name="sub_name" value="<?php echo htmlspecialchars($s); ?>">
                                                  <button type="button" class="dropdown-item fw-medium text-danger" onclick="confirmFolderDelete(this, 'sub')"><i class="fas fa-trash-alt me-2"></i> Delete empty folder</button>
                                              </form>
                                          </li>
                                      <?php endif; ?>
                                     
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

      <?php if ($view_disposition_section || (!empty($type_filter)) || $view_archives || $view_shared || $records_query_active): ?>
        
          <?php if ($role === 'Admin'): ?>
              <div class="col-12 text-center py-5 bg-white border rounded-4 shadow-sm mt-3 flex-grow-1">
                  <div class="mb-3"><i class="fas fa-shield-alt text-muted opacity-50 fa-4x"></i></div>
                  <h5 class="text-dark fw-bold">Document Access Restricted</h5>
                  <p class="text-muted mb-0">As a System Administrator, you can view and manage the folder structure, but you are not authorized to view, access, or manage the actual documents inside.</p>
              </div>
          <?php else: ?>

              <?php if($view_disposition_section): ?>
                  <div class="file-list-container shadow-sm">
                      <table id="documentsTable" class="table table-hover align-middle mb-0 w-100">
                          <thead>
                              <tr>
                                  <th class="ps-4">Document Details</th>
                                  <th><?php echo $view_disposition_history ? 'Final Disposition' : 'Required Action'; ?></th>
                                  <th><?php echo $view_disposition_history ? 'Completed On' : 'Retention Date'; ?></th>
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
                                  $is_destroyed_record = ($doc['disposition_status'] ?? '') === 'Destroyed';
                                  $can_open_binary = $has_file_access && !$is_destroyed_record && !$view_disposition_history;
                                  $document_file_url = 'download.php?type=document&record_id=' . (int) $doc['doc_id'];

                                  $request_id = (int) ($doc['disposition_request_id'] ?? 0);
                                  $request_status = $doc['disposition_request_status'] ?? '';
                                  $requester_id = (int) ($doc['disposition_requested_by'] ?? 0);
                                  $is_requester = $requester_id === (int) $_SESSION['user_id'];
                                  $record_disposition_status = $doc['disposition_status'] ?? 'Pending';
                                  $can_submit_request = $can_manage_disposition
                                      && $record_disposition_status === 'Ready for Disposition'
                                      && !in_array($request_status, ['Pending', 'Approved', 'Executed'], true);
                                  $can_review_request = $can_approve_disposition && $request_status === 'Pending' && !$is_requester;
                                  $can_execute_request = $can_execute_disposition && $request_status === 'Approved';
                              ?>
                              <tr id="target-doc-<?php echo $doc['doc_id']; ?>" class="<?php echo $can_open_binary ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($can_open_binary): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($document_file_url), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
                                  <td class="ps-4 py-3">
                                      <div class="d-flex align-items-center">
                                          <div class="file-icon-md bg-light text-primary me-3 border transition-all rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                              <?php 
                                                  if($is_destroyed_record) echo '<i class="fas fa-file-circle-xmark text-dark fs-5"></i>';
                                                  elseif(!$has_file_access) echo '<i class="fas fa-lock text-danger fs-5"></i>';
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
                                                      <?php if (!empty($doc['original_file_name']) && $doc['original_file_name'] !== $doc['file_name']): ?>
                                                          <div class="text-muted fs-xs text-truncate" title="Original file name: <?php echo htmlspecialchars($doc['original_file_name']); ?>">Original: <?php echo htmlspecialchars($doc['original_file_name']); ?></div>
                                                      <?php endif; ?>
                                                  </div>
                                                
                                                  <?php if($is_legal_hold): ?>
                                                      <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 ms-2" style="font-size: 0.7rem; font-weight: 600;" title="Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                          <i class="fas fa-balance-scale"></i> Legal Hold
                                                      </span>
                                                  <?php endif; ?>
                                              </div>
                                              <div class="d-flex align-items-center mt-1">
                                                  <?php if (!empty($doc['record_number'])): ?>
                                                      <span class="text-primary small fw-semibold" title="Official Record ID"><i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($doc['record_number']); ?></span>
                                                      <span class="text-muted opacity-50 mx-2">&bull;</span>
                                                  <?php endif; ?>
                                                <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category']); ?></span>
                                                <?php if (!empty($doc['business_reference'])): ?>
                                                    <span class="text-muted opacity-50 mx-2">&bull;</span>
                                                    <span class="text-muted small">Ref: <?php echo htmlspecialchars($doc['business_reference']); ?></span>
                                                <?php endif; ?>
                                              </div>
                                          </div>
                                      </div>
                                  </td>
                                  <td>
                                      <?php if ($view_disposition_history): ?>
                                          <?php if ($is_destroyed_record): ?>
                                              <span class="badge bg-dark text-white border px-2 py-1"><i class="fas fa-shield-alt me-1"></i> Digital file destroyed</span>
                                          <?php else: ?>
                                              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-2 py-1"><i class="fas fa-archive me-1"></i> Permanently Archived</span>
                                          <?php endif; ?>
                                      <?php else: ?>
                                          <span class="badge bg-warning text-dark border border-warning px-2 py-1">
                                              <?php echo htmlspecialchars($doc['action_after_retention'] ?? 'Review required'); ?>
                                          </span>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <?php if ($view_disposition_history): ?>
                                          <div class="text-dark fw-bold"><i class="fas fa-check-circle text-success me-1"></i> <?php echo !empty($doc['disposition_executed_at']) ? date('M d, Y', strtotime($doc['disposition_executed_at'])) : 'Recorded'; ?></div>
                                          <div class="text-muted small"><?php echo !empty($doc['disposition_executed_at']) ? date('h:i A', strtotime($doc['disposition_executed_at'])) : 'Completion time unavailable'; ?></div>
                                      <?php else: ?>
                                          <div class="text-danger fw-bold"><i class="fas fa-clock me-1"></i> <?php echo date('M d, Y', strtotime($doc['retention_date'])); ?></div>
                                          <div class="text-muted small">Expired</div>
                                      <?php endif; ?>
                                  </td>
                                  <td class="text-end pe-4" onclick="event.stopPropagation();">
                                      <div class="d-flex justify-content-end gap-2">
                                          <?php if ($has_file_access): ?>
                                              <?php if ($is_legal_hold): ?>
                                                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2" title="Action blocked by Legal Hold: <?php echo $legal_hold_reason; ?>">
                                                      <i class="fas fa-balance-scale me-1"></i> Managed by Policy
                                                  </span>
                                              <?php else: ?>
                                                  <?php if ($request_status === 'Pending'): ?>
                                                      <div class="d-flex flex-column align-items-end gap-1">
                                                          <span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-3 py-2">
                                                              <i class="fas fa-hourglass-half me-1"></i> Pending review
                                                          </span>
                                                          <span class="text-muted fs-xs">Request #<?php echo $request_id; ?> · <?php echo htmlspecialchars($doc['disposition_requester_name'] ?? 'Requester'); ?></span>
                                                          <div class="d-flex gap-1 mt-1">
                                                              <?php if ($can_review_request): ?>
                                                                  <button type="button" class="btn btn-sm btn-success fw-semibold px-2 py-1" onclick="openDispositionReviewModal(this, 'approve_disposition')" data-request-id="<?php echo $request_id; ?>" data-file-name="<?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES); ?>" data-request-reason="<?php echo htmlspecialchars($doc['disposition_reason'] ?? '', ENT_QUOTES); ?>">
                                                                      <i class="fas fa-check me-1"></i> Approve
                                                                  </button>
                                                                  <button type="button" class="btn btn-sm btn-outline-danger fw-semibold px-2 py-1" onclick="openDispositionReviewModal(this, 'reject_disposition')" data-request-id="<?php echo $request_id; ?>" data-file-name="<?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES); ?>" data-request-reason="<?php echo htmlspecialchars($doc['disposition_reason'] ?? '', ENT_QUOTES); ?>">
                                                                      <i class="fas fa-times me-1"></i> Reject
                                                                  </button>
                                                              <?php elseif ($is_requester): ?>
                                                                  <form action="actions/disposition_handler.php" method="POST" class="m-0">
                                                                      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                      <input type="hidden" name="action" value="cancel_disposition">
                                                                      <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                                                                      <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold px-2 py-1" onclick="confirmCancelDisposition(this)">
                                                                          <i class="fas fa-ban me-1"></i> Cancel
                                                                      </button>
                                                                  </form>
                                                              <?php endif; ?>
                                                          </div>
                                                      </div>
                                                  <?php elseif ($request_status === 'Approved'): ?>
                                                      <div class="d-flex flex-column align-items-end gap-1">
                                                          <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2">
                                                              <i class="fas fa-check-circle me-1"></i> Approved
                                                          </span>
                                                          <span class="text-muted fs-xs">Execution pending · Request #<?php echo $request_id; ?></span>
                                                          <?php if ($can_execute_request): ?>
                                                              <button type="button" class="btn btn-sm <?php echo ($doc['disposition_requested_action'] ?? '') === 'Destroy' ? 'btn-danger' : 'btn-primary'; ?> fw-semibold px-2 py-1 mt-1" onclick="openDispositionExecutionModal(this)" data-request-id="<?php echo $request_id; ?>" data-file-name="<?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES); ?>" data-execution-action="<?php echo htmlspecialchars($doc['disposition_requested_action'] ?? '', ENT_QUOTES); ?>" data-record-number="<?php echo htmlspecialchars($doc['record_number'] ?? '', ENT_QUOTES); ?>">
                                                                  <i class="fas <?php echo ($doc['disposition_requested_action'] ?? '') === 'Destroy' ? 'fa-shield-alt' : 'fa-archive'; ?> me-1"></i>
                                                                  <?php echo ($doc['disposition_requested_action'] ?? '') === 'Destroy' ? 'Destroy digital file' : 'Complete archive'; ?>
                                                              </button>
                                                          <?php endif; ?>
                                                      </div>
                                                  <?php elseif ($request_status === 'Executed'): ?>
                                                      <div class="d-flex flex-column align-items-end gap-1">
                                                          <?php if (($doc['disposition_requested_action'] ?? '') === 'Destroy'): ?>
                                                              <span class="badge bg-dark text-white border px-3 py-2">
                                                                  <i class="fas fa-shield-alt me-1"></i> Digital destruction certified
                                                              </span>
                                                              <?php if (!empty($doc['disposition_certificate_id'])): ?>
                                                                  <a href="view_destruction_certificate.php?id=<?php echo (int) $doc['disposition_certificate_id']; ?>" class="btn btn-sm btn-outline-dark fw-semibold px-2 py-1 mt-1">
                                                                      <i class="fas fa-certificate me-1"></i> View certificate
                                                                  </a>
                                                              <?php endif; ?>
                                                              <?php if (!empty($doc['physical_disposal_evidence_number'])): ?>
                                                                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-2 py-1 mt-1">
                                                                      <i class="fas fa-shield-alt me-1"></i> Physical copy disposed · <?php echo htmlspecialchars($doc['physical_disposal_evidence_number']); ?>
                                                                  </span>
                                                              <?php elseif ((int)($doc['registered_physical_copy'] ?? 0) > 0): ?>
                                                                  <span class="badge bg-light text-dark border px-2 py-1 mt-1">
                                                                      <i class="fas fa-archive me-1"></i> Physical copy retained in cabinet
                                                                  </span>
                                                              <?php else: ?>
                                                                  <span class="text-muted fs-xs">No registered physical copy; no paper-disposal evidence recorded.</span>
                                                              <?php endif; ?>
                                                          <?php else: ?>
                                                              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2">
                                                                  <i class="fas fa-archive me-1"></i> Permanently archived
                                                              </span>
                                                          <?php endif; ?>
                                                          <span class="text-muted fs-xs">Executed by <?php echo htmlspecialchars($doc['disposition_executor_name'] ?? 'Authorized user'); ?></span>
                                                      </div>
                                                  <?php elseif ($can_submit_request): ?>
                                                      <button type="button" class="btn btn-sm btn-warning text-dark fw-semibold px-3 py-1" onclick="openDispositionRequestModal(this)" data-doc-id="<?php echo (int) $doc['doc_id']; ?>" data-file-name="<?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES); ?>" data-policy-action="<?php echo htmlspecialchars($doc['action_after_retention'] ?? 'Destroy', ENT_QUOTES); ?>">
                                                          <i class="fas fa-paper-plane me-1"></i> Request review
                                                      </button>
                                                  <?php else: ?>
                                                      <span class="badge bg-light text-secondary border px-3 py-2" title="A disposition manager must submit the request.">
                                                          <i class="fas fa-user-shield me-1"></i> Awaiting request
                                                      </span>
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
                                  $document_file_url = 'download.php?type=document&record_id=' . (int) $doc['doc_id'];
                              ?>
                              <tr id="target-doc-<?php echo $doc['doc_id']; ?>" class="<?php echo $has_file_access ? 'cursor-pointer file-row-title' : ''; ?>" <?php if($has_file_access): ?>onclick="openDocumentViewer('<?php echo htmlspecialchars(addslashes($document_file_url), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', <?php echo $is_img ? 'true' : 'false'; ?>)"<?php endif; ?>>
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
                                                      <h6 class="mb-0 text-dark fw-bold text-truncate w-100" title="<?php echo htmlspecialchars($doc['file_name']); ?>">
                                                          <?php echo htmlspecialchars($doc['file_name']); ?>
                                                      </h6>
                                                      <?php if (!empty($doc['original_file_name']) && $doc['original_file_name'] !== $doc['file_name']): ?>
                                                          <div class="text-muted fs-xs text-truncate" title="Original file name: <?php echo htmlspecialchars($doc['original_file_name']); ?>">Original: <?php echo htmlspecialchars($doc['original_file_name']); ?></div>
                                                      <?php endif; ?>
                                                  </div>
                                                
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
                                                  <?php if (!empty($doc['record_number'])): ?>
                                                      <span class="text-primary small fw-semibold" title="Official Record ID"><i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($doc['record_number']); ?></span>
                                                      <span class="text-muted opacity-50 mx-2">&bull;</span>
                                                  <?php endif; ?>
                                                <span class="text-muted small"><i class="fas fa-folder text-secondary me-1"></i> <?php echo htmlspecialchars($doc['category'] ?: $doc['doc_type']); ?></span>
                                                <?php if (!empty($doc['business_reference'])): ?>
                                                    <span class="text-muted opacity-50 mx-2">&bull;</span>
                                                    <span class="text-muted small">Ref: <?php echo htmlspecialchars($doc['business_reference']); ?></span>
                                                <?php endif; ?>
                                                  <?php if ($doc['current_version'] > 1): ?>
                                                      <span class="badge bg-light text-primary border ms-2" style="font-size: 0.7rem;">v<?php echo number_format($doc['current_version'], 1); ?></span>
                                                  <?php endif; ?>
                                              </div>
                                          </div>
                                      </div>
                                  </td>
                                  <td>
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
                                  <td>
                                      <div class="fw-medium text-dark"><?php echo htmlspecialchars($doc['full_name']); ?></div>
                                  </td>
                                  <td>
                                      <div class="text-dark fw-medium"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></div>
                                      <div class="text-muted small"><?php echo date('h:i A', strtotime($doc['uploaded_at'])); ?></div>
                                  </td>
                                  <td class="text-end pe-4 position-relative">
                                      <div class="action-dropdown dropdown">
                                          <button class="btn-dots bg-transparent border-0 shadow-none dropdown-toggle d-inline-flex align-items-center justify-content-center" type="button" data-bs-toggle="dropdown" data-bs-display="static" style="width: 35px; height: 35px;" onclick="event.stopPropagation();">
                                              <i class="fas fa-ellipsis-v text-dark"></i>
                                          </button>
                                          <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-1" onclick="event.stopPropagation();">
                                              <?php if ($has_file_access): ?>
                                                  <li>
                                                      <button type="button" class="dropdown-item fw-medium text-dark" 
                                                              onclick="viewFileDetails('<?php echo htmlspecialchars(addslashes($doc['file_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($doc['category'] ?: $doc['doc_type']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($document_file_url), ENT_QUOTES); ?>', '<?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?>', '<?php echo htmlspecialchars(addslashes($doc['full_name']), ENT_QUOTES); ?>', '<?php echo base64_encode($doc['rename_history'] ?? '[]'); ?>')">
                                                          <i class="fas fa-info-circle text-primary me-2"></i> View Details
                                                      </button>
                                                  </li>
                                                  <li><hr class="dropdown-divider"></li>

                                                  <li>
                                                      <a class="dropdown-item fw-medium text-dark" href="<?php echo htmlspecialchars($document_file_url); ?>" download>
                                                          <i class="fas fa-download text-success me-2"></i> Download Record
                                                      </a>
                                                  </li>
                                                  <li>
                                                      <!-- Forced 'false' sa dulo para i-disable ang "Upload New Version" button sa UI -->
                                                      <button type="button" class="dropdown-item fw-medium text-dark" onclick="openVersionModal(<?php echo $doc['doc_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>', false)">
                                                          <i class="fas fa-history text-info me-2"></i> View Version History
                                                      </button>
                                                  </li>
                                                
                                                
                                                  <?php if ($can_manage || $is_top_mgmt): ?>
                                                      <li><hr class="dropdown-divider"></li>
                                                      <li>
                                                                  <?php 
                                                                      $p_stat = $doc['physical_status'] ?? 'Digital'; 
                                                                      $p_stat = ($p_stat === 'Returned') ? 'Stored' : $p_stat;
                                                                    
                                                                      if ($p_stat === 'Digital') {
                                                                          $stat_color = 'text-secondary';
                                                                          $stat_icon = 'fa-laptop';
                                                                          $btn_text = 'No registered physical copy';
                                                                      } else {
                                                                          $stat_color = ($p_stat === 'Borrowed') ? 'text-warning' : 'text-success';
                                                                          $stat_icon = ($p_stat === 'Borrowed') ? 'fa-hand-holding' : 'fa-check-circle';
                                                                          $btn_text = empty($doc['physical_folder_id']) ? 'Physical: ' . $p_stat . ' · Unassigned' : 'Physical: ' . $p_stat;
                                                                      }
                                                                  ?>
                                                                  <button type="button" class="dropdown-item fw-medium text-dark" onclick="openPhysicalRecordProfile(<?php echo (int)$doc['doc_id']; ?>)">
                                                                      <i class="fas <?php echo $stat_icon; ?> <?php echo $stat_color; ?> me-2 w-15px text-center"></i> <?php echo $btn_text; ?>
                                                                  </button>
                                                              </li>
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
      <div class="modal-dialog modal-fullscreen m-0">
          <div class="modal-content border-0 bg-transparent rounded-0">
            
              <!-- Modern Dark Glass Overlay (Edge-to-Edge) -->
              <div class="position-absolute w-100 h-100" style="background-color: rgba(15, 23, 42, 0.9); backdrop-filter: blur(8px); z-index: 1040;"></div>
            
              <!-- Ultra-Sleek White Header Bar -->
              <div class="d-flex justify-content-between align-items-center px-4 py-2 position-absolute top-0 w-100 bg-white shadow-sm" style="z-index: 1060;">
                  <div class="d-flex align-items-center text-dark">
                      <i class="fas fa-file-image text-primary fs-5 me-3"></i>
                      <h6 class="fw-bold mb-0 text-truncate letter-spacing-tight" id="viewerFileName" style="max-width: 60vw; font-size: 0.95rem;">Document Preview</h6>
                  </div>
                  <div class="d-flex gap-2 align-items-center">
                      <a id="viewerDownloadBtn" href="#" download class="btn btn-light rounded-circle shadow-none d-flex align-items-center justify-content-center border-0 text-primary preview-action-btn" style="width: 35px; height: 35px; background: #f1f5f9;" title="Download File">
                          <i class="fas fa-download fs-6"></i>
                      </a>
                      <button type="button" class="btn btn-light rounded-circle shadow-none d-flex align-items-center justify-content-center border-0 text-danger preview-action-btn" data-bs-dismiss="modal" title="Close Preview" style="width: 35px; height: 35px; background: #f1f5f9;">
                          <i class="fas fa-times fs-5"></i>
                      </button>
                  </div>
              </div>

              <div class="modal-body p-0 d-flex justify-content-center align-items-center overflow-hidden" style="height: 100vh; width: 100vw; touch-action: none; position: relative; z-index: 1050; padding-top: 45px !important;">
                
                  <!-- Modern Loader -->
                  <div id="viewerLoader" class="position-absolute text-center" style="z-index: 1040;">
                      <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem; border-width: 0.2em;"></div>
                      <div class="fw-bold text-white text-uppercase letter-spacing-tight" style="font-size: 0.85rem; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">Loading Document...</div>
                  </div>

                  <div id="viewerContentWrapper" style="transition: transform 0.2s ease; transform-origin: center center; display:flex; justify-content:center; align-items:center; width: 100%; height: 100%;">
                      <img id="documentViewerImage" src="" draggable="false" class="shadow-lg rounded-3" style="display:none; max-width: 95vw; max-height: 85vh; object-fit: contain;" />
                      <iframe id="documentViewerFrame" src="" class="shadow-lg rounded-3 bg-white" style="display:none; width: 90vw; height: 85vh; border: none;"></iframe>
                  </div>
              </div>

              <!-- Modern White Pill Zoom Controls -->
              <div class="position-absolute bottom-0 start-50 translate-middle-x mb-4" style="z-index: 1060;" id="zoomControlsContainer">
                  <div class="d-flex align-items-center bg-white rounded-pill px-2 py-1 shadow-sm border border-light">
                      <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none preview-zoom-btn" onclick="zoomViewer('out')" title="Zoom Out">
                          <i class="fas fa-minus fs-6"></i>
                      </button>
                      <span id="viewerZoomLevel" class="text-dark fw-bold px-3 fs-sm" style="min-width: 65px; text-align: center; cursor: default;">100%</span>
                      <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none preview-zoom-btn" onclick="zoomViewer('in')" title="Zoom In">
                          <i class="fas fa-plus fs-6"></i>
                      </button>
                      <div class="border-start mx-1" style="height: 18px;"></div>
                      <button type="button" class="btn btn-link text-secondary shadow-none p-2 text-decoration-none preview-zoom-btn" onclick="zoomViewer('reset')" title="Fit to Screen">
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
                  <form action="documents.php" method="POST" id="shareForm">
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

  <?php require __DIR__ . '/includes/physical_record_profile.php'; ?>

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

                      <div class="form-check border rounded-3 bg-light px-3 py-3 mb-4">
                          <input class="form-check-input ms-0 me-2" type="checkbox" name="official_signature_confirmed" value="1" id="declareSignatureConfirmed" required>
                          <label class="form-check-label fs-sm text-dark" for="declareSignatureConfirmed">
                              I confirm that this copy contains the required signature(s) and is ready to become an Official Record.
                          </label>
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
                      <input type="hidden" name="action" value="upload">
                      <input type="hidden" name="record_intake" value="official">
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
                                          if (!empty($parent_system_flags[$p])) continue;
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
                        
                          <div class="d-flex justify-content-center mt-3 flex-column align-items-center">
                              <span id="uploadChosenFileText" class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1 fs-xs fw-medium text-truncate mb-2" style="max-width: 100%;">No file selected yet.</span>
                              <!-- Document Preview inside Main Upload Modal -->
                              <img id="mainUploadPreview" class="d-none rounded-3 shadow-sm border border-secondary border-opacity-25" style="max-height: 180px; max-width: 100%; object-fit: contain;" alt="Selected file preview">
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

                      <!-- Camera UI migrated to standalone modal -->

                      <div class="mb-4 mt-3">
                          <label class="form-label text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Initial Sharing Access</label>
                        
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

                      <div class="form-check border rounded-3 bg-white px-3 py-3 mb-4 shadow-sm">
                          <input class="form-check-input ms-0 me-2" type="checkbox" name="official_signature_confirmed" value="1" id="uploadSignatureConfirmed" required>
                          <label class="form-check-label fs-sm text-dark" for="uploadSignatureConfirmed">
                              I confirm that the uploaded copy contains the required signature(s) and may be filed as an Official Record.
                          </label>
                      </div>

                      <!-- DAGDAG: Nilagyan ng id="uploadSubmitBtn" -->
                      <button type="submit" id="uploadSubmitBtn" class="btn btn-primary w-100 fw-bold shadow-sm rounded-pill py-2 modal-btn-hover transition-all">
                          <i class="fas fa-check-circle me-2"></i> Upload and Index File
                      </button>
                  </form>
              </div>
          </div>
      </div>
  </div>
  <?php endif; ?>

  <!-- SEPARATE CAMERA MODAL -->
  <div class="modal fade sleek-modal" id="cameraModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
          <div class="modal-content shadow-lg border-0 rounded-4">
              <div class="modal-header bg-white border-bottom pb-3 pt-4 px-4 rounded-top-4">
                  <div>
                      <h5 class="modal-title fw-bold text-dark fs-5 letter-spacing-tight"><i class="fas fa-camera text-primary me-2"></i>Document Scanner</h5>
                      <p class="text-muted mb-0 fs-xs mt-1">Position your document within the frame.</p>
                  </div>
                  <button type="button" class="btn-close shadow-none" onclick="closeCameraModal()" style="margin-top: -15px;"></button>
              </div>
              <div class="modal-body p-4 bg-f8f9fa rounded-bottom-4">
                  <div id="cameraPanel">
                      <!-- Modern Enterprise Scanner Viewfinder (No Crosshair) -->
                      <div class="p-2 bg-white border rounded-4 shadow-sm mx-auto" style="max-width: 100%;">
                          <div class="position-relative rounded-3 overflow-hidden bg-black" style="border: 1px solid #e2e8f0;">
                                  <!-- Pinalitan ng Portrait Aspect Ratio (3/4 or approx 8.5x11/13) para saktong document frame -->
                                  <div class="position-relative w-100" style="aspect-ratio: 3/4.2;">
                                      <video id="cameraVideo" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" autoplay playsinline muted></video>
                                      <!-- Pinalitan ng object-fit-contain para makita ng buo ang na-crop na document nang walang putol -->
                                      <img id="cameraPreviewImage" class="position-absolute top-0 start-0 w-100 h-100 object-fit-contain d-none" style="background-color: #000;" alt="Captured photo preview">
                                      <canvas id="cameraCanvas" class="d-none"></canvas>
                                  </div>
                                
                                  <!-- Document Scanner Guides & Overlay -->
                              <div class="position-absolute top-0 start-0 w-100 h-100 pointer-events-none d-flex flex-column justify-content-between p-3" style="background: rgba(0,0,0,0.05);">
                                  <div class="d-flex justify-content-between">
                                      <div style="width: 30px; height: 30px; border-top: 3px solid rgba(255,255,255,0.9); border-left: 3px solid rgba(255,255,255,0.9); border-top-left-radius: 8px;"></div>
                                      <div style="width: 30px; height: 30px; border-top: 3px solid rgba(255,255,255,0.9); border-right: 3px solid rgba(255,255,255,0.9); border-top-right-radius: 8px;"></div>
                                  </div>
                                  <div class="d-flex justify-content-between">
                                      <div style="width: 30px; height: 30px; border-bottom: 3px solid rgba(255,255,255,0.9); border-left: 3px solid rgba(255,255,255,0.9); border-bottom-left-radius: 8px;"></div>
                                      <div style="width: 30px; height: 30px; border-bottom: 3px solid rgba(255,255,255,0.9); border-right: 3px solid rgba(255,255,255,0.9); border-bottom-right-radius: 8px;"></div>
                                  </div>
                              </div>
                            
                              <!-- Floating Status Pill inside Camera -->
                              <div class="position-absolute bottom-0 start-50 translate-middle-x w-100 text-center mb-3 px-2 pointer-events-none">
                                  <div id="cameraStatusMessage" class="d-inline-block bg-white px-3 py-2 fs-xs fw-bold shadow rounded-pill text-truncate" style="max-width: 90%; border: 1px solid rgba(0,0,0,0.1);"></div>
                              </div>
                          </div>
                      </div>
                    
                      <!-- Sleek Camera Controls -->
                      <div class="d-flex justify-content-center gap-2 mt-4">
                          <button type="button" class="btn btn-light bg-white border fw-medium px-4 shadow-sm rounded-pill transition-all" onclick="closeCameraModal()">Cancel</button>
                          <button type="button" id="capturePhotoBtn" class="btn btn-primary rounded-pill shadow-sm px-4 py-2 fw-bold d-flex align-items-center modal-btn-hover transition-all">
                              <i class="fas fa-camera fs-5 me-2"></i> Capture
                          </button>
                          <button type="button" id="retakePhotoBtn" class="btn btn-light bg-white border border-secondary border-opacity-25 rounded-pill shadow-sm px-4 py-2 fw-bold text-dark d-none align-items-center modal-btn-hover transition-all">
                              <i class="fas fa-redo-alt text-muted me-2"></i> Retake
                          </button>
                          <button type="button" id="usePhotoBtn" class="btn btn-success rounded-pill shadow-sm px-4 py-2 fw-bold d-none align-items-center modal-btn-hover transition-all">
                              <i class="fas fa-check-circle me-2"></i> Confirm & Use
                          </button>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </div>

  <?php endif; ?> <!-- End If Non-Admin For Restricted Modals -->

  <?php if ($view_disposition && $can_manage_disposition): ?>
  <!-- DISPOSITION REQUEST MODAL -->
  <div class="modal fade sleek-modal" id="dispositionRequestModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow rounded-4">
              <div class="modal-header border-bottom px-4 py-3">
                  <div>
                      <h5 class="modal-title fw-bold text-dark fs-6 mb-1"><i class="fas fa-file-signature text-warning me-2"></i>Request Disposition Review</h5>
                      <p class="text-muted fs-xs mb-0">The file will not be deleted when this request is submitted.</p>
                  </div>
                  <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
              </div>
              <form action="actions/disposition_handler.php" method="POST">
                  <div class="modal-body px-4 py-3">
                      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" value="request_disposition">
                      <input type="hidden" name="doc_id" id="dispositionRequestDocId">

                      <div class="row g-3 mb-3">
                          <div class="col-8">
                              <label class="form-label fs-xs fw-bold text-uppercase text-muted">Official record</label>
                              <div class="form-control bg-light fs-sm fw-semibold text-dark text-truncate" id="dispositionRequestFileName"></div>
                          </div>
                          <div class="col-4">
                              <label class="form-label fs-xs fw-bold text-uppercase text-muted">Policy action</label>
                              <div class="form-control bg-light fs-sm fw-semibold text-dark text-center" id="dispositionRequestPolicyAction"></div>
                          </div>
                      </div>

                      <div>
                          <label for="dispositionRequestReason" class="form-label fs-xs fw-bold text-uppercase text-muted">Reason for request</label>
                          <textarea name="reason" id="dispositionRequestReason" class="form-control shadow-none fs-sm" rows="4" minlength="10" maxlength="1000" placeholder="Explain why the record is ready for the policy-directed disposition action." required></textarea>
                          <div class="form-text fs-xs">Required: 10–1000 characters. The independent reviewer will see this explanation.</div>
                      </div>
                  </div>
                  <div class="modal-footer border-top px-4 py-3">
                      <button type="button" class="btn btn-light btn-sm border px-3" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-warning btn-sm text-dark fw-bold px-4">
                          <i class="fas fa-paper-plane me-1"></i> Submit Request
                      </button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  <?php endif; ?>

  <?php if ($view_disposition && $can_approve_disposition): ?>
  <!-- DISPOSITION REVIEW MODAL -->
  <div class="modal fade sleek-modal" id="dispositionReviewModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow rounded-4">
              <div class="modal-header border-bottom px-4 py-3">
                  <div>
                      <h5 class="modal-title fw-bold text-dark fs-6 mb-1" id="dispositionReviewTitle"><i class="fas fa-user-check text-success me-2"></i>Review Disposition Request</h5>
                      <p class="text-muted fs-xs mb-0">Your decision is recorded separately from the requester.</p>
                  </div>
                  <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
              </div>
              <form action="actions/disposition_handler.php" method="POST" id="dispositionReviewForm">
                  <div class="modal-body px-4 py-3">
                      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" id="dispositionReviewAction">
                      <input type="hidden" name="request_id" id="dispositionReviewRequestId">

                      <div class="mb-3">
                          <label class="form-label fs-xs fw-bold text-uppercase text-muted">Official record</label>
                          <div class="form-control bg-light fs-sm fw-semibold text-dark text-truncate" id="dispositionReviewFileName"></div>
                      </div>

                      <div class="mb-3">
                          <label class="form-label fs-xs fw-bold text-uppercase text-muted">Requester's reason</label>
                          <div class="border rounded-3 bg-light px-3 py-2 fs-sm text-dark" id="dispositionReviewReason"></div>
                      </div>

                      <div>
                          <label for="dispositionReviewNotes" class="form-label fs-xs fw-bold text-uppercase text-muted" id="dispositionReviewNotesLabel">Review notes</label>
                          <textarea name="review_notes" id="dispositionReviewNotes" class="form-control shadow-none fs-sm" rows="3" maxlength="1000" placeholder="Optional approval notes."></textarea>
                          <div class="form-text fs-xs" id="dispositionReviewHelp">Notes are optional when approving.</div>
                      </div>
                  </div>
                  <div class="modal-footer border-top px-4 py-3">
                      <button type="button" class="btn btn-light btn-sm border px-3" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-success btn-sm fw-bold px-4" id="dispositionReviewSubmit">
                          <i class="fas fa-check me-1"></i> Approve Request
                      </button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  <?php endif; ?>

  <?php if ($view_disposition && $can_execute_disposition): ?>
  <!-- DISPOSITION EXECUTION MODAL -->
  <div class="modal fade sleek-modal" id="dispositionExecutionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow rounded-4">
              <div class="modal-header border-bottom px-4 py-3">
                  <div>
                      <h5 class="modal-title fw-bold text-dark fs-6 mb-1" id="dispositionExecutionTitle"><i class="fas fa-shield-alt text-danger me-2"></i>Execute Approved Disposition</h5>
                      <p class="text-muted fs-xs mb-0">Execution is allowed only after an independent approval.</p>
                  </div>
                  <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
              </div>
              <form action="actions/disposition_handler.php" method="POST" id="dispositionExecutionForm">
                  <div class="modal-body px-4 py-3">
                      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" value="execute_disposition">
                      <input type="hidden" name="request_id" id="dispositionExecutionRequestId">

                      <div class="row g-3 mb-3">
                          <div class="col-8">
                              <label class="form-label fs-xs fw-bold text-uppercase text-muted">Official record</label>
                              <div class="form-control bg-light fs-sm fw-semibold text-dark text-truncate" id="dispositionExecutionFileName"></div>
                          </div>
                          <div class="col-4">
                              <label class="form-label fs-xs fw-bold text-uppercase text-muted">Approved action</label>
                              <div class="form-control bg-light fs-sm fw-bold text-center" id="dispositionExecutionAction"></div>
                          </div>
                      </div>

                      <div class="alert mb-3 fs-sm" id="dispositionExecutionWarning"></div>

                      <div class="vc4b-digital-scope mb-3" id="dispositionDigitalScope" hidden>
                          <label for="dispositionDigitalScopeConfirmed"><input type="checkbox" id="dispositionDigitalScopeConfirmed" name="digital_scope_confirmed" value="1"><span>I understand this permanently deletes the digital file only. Any registered paper copy remains tracked in Virtual Cabinet and needs a separate physical-disposal decision.</span></label>
                      </div>

                      <div class="mb-3">
                          <label for="dispositionExecutionConfirmation" class="form-label fs-xs fw-bold text-uppercase text-muted">Typed confirmation</label>
                          <input type="text" name="execution_confirmation" id="dispositionExecutionConfirmation" class="form-control shadow-none fw-bold text-uppercase" autocomplete="off" required>
                          <div class="form-text fs-xs" id="dispositionExecutionConfirmationHelp"></div>
                      </div>

                      <div>
                          <label for="dispositionExecutionNotes" class="form-label fs-xs fw-bold text-uppercase text-muted">Execution notes</label>
                          <textarea name="execution_notes" id="dispositionExecutionNotes" class="form-control shadow-none fs-sm" rows="3" maxlength="1000" placeholder="Optional operational notes for the audit trail."></textarea>
                      </div>
                  </div>
                  <div class="modal-footer border-top px-4 py-3">
                      <button type="button" class="btn btn-light btn-sm border px-3" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-danger btn-sm fw-bold px-4" id="dispositionExecutionSubmit">
                          <i class="fas fa-shield-alt me-1"></i> Execute
                      </button>
                  </div>
              </form>
          </div>
      </div>
  </div>
  <?php endif; ?>

  <!-- EDIT RETENTION POLICIES MODAL -->
  <?php if (has_permission($conn, $_SESSION['user_id'], 'can_edit_policies')): ?>
  <div class="modal fade sleek-modal" id="editPoliciesModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
              <div class="modal-header bg-light border-bottom">
                  <h5 class="modal-title fw-bold text-dark"><i class="fas fa-balance-scale me-2 text-primary"></i> Retention Settings</h5>
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
                              <form action="documents.php" method="POST">
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
                                      <div class="col-12">
                                          <label class="form-label fw-semibold small text-secondary text-uppercase">Action after retention</label>
                                          <select name="action_after_retention" class="form-select bg-white fw-medium shadow-none border-0" required>
                                              <option value="Destroy">Destroy after approved review</option>
                                              <option value="Permanent Archive">Keep in permanent archive</option>
                                          </select>
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
                                  <form action="documents.php" method="POST">
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
                                          <div class="col-12">
                                              <label class="form-label fw-semibold small text-secondary text-uppercase">Action after retention</label>
                                              <select name="action_after_retention" class="form-select bg-light fw-medium" required>
                                                  <option value="Destroy" <?php echo ($pol['action_after_retention'] ?? '') === 'Destroy' ? 'selected' : ''; ?>>Destroy after approved review</option>
                                                  <option value="Permanent Archive" <?php echo ($pol['action_after_retention'] ?? '') === 'Permanent Archive' ? 'selected' : ''; ?>>Keep in permanent archive</option>
                                              </select>
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
                                  <form id="deletePolicyForm_<?php echo $pol['policy_id']; ?>" action="documents.php" method="POST" class="d-none">
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
  <?php if (!empty($parent_filter) && empty($type_filter) && $can_manage && !$current_parent_is_system): ?>
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

                      <div class="mb-3">
                          <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Record Code <span class="text-danger">*</span></label>
                          <input type="text" name="record_prefix" class="form-control shadow-none text-uppercase" maxlength="10" pattern="[A-Za-z][A-Za-z0-9]{1,9}" placeholder="e.g. CON, INV, HR" required>
                          <div class="form-text fs-xs mt-1"><i class="fas fa-barcode text-primary me-1"></i>Used in the actual Official Record name, such as <strong>CON-2026-0001.pdf</strong>. The code must be unique.</div>
                      </div>

                      <p class="form-text">This is a digital classification folder. Assign paper copies separately through their Physical Record profile.</p>

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
                  <form action="documents.php" method="POST">
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
                  <form action="documents.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : ''; ?>" method="POST">
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
  <!-- OpenCV.js for Smart Document Edge Detection & Auto-Crop -->
  <script async src="https://docs.opencv.org/4.8.0/opencv.js" onload="console.log('OpenCV Engine Loaded');"></script>
  <script src="assets/js/mobile-document-viewer.js"></script>

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

      const urlParams = new URLSearchParams(window.location.search);
      const targetDoc = urlParams.get('doc');
      if (targetDoc) {
          setTimeout(() => {
              let el = document.getElementById('target-doc-' + targetDoc);
              if (el) {
                  // I-scroll ng saktong gitna para kitang kita
                  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  el.classList.add('highlight-target-file');
                
                  // Remove highlighting class strictly after the 4-second animation finishes
                  setTimeout(() => {
                      el.classList.remove('highlight-target-file');
                  }, 4000); 
              }
          }, 500); // Standard rendering delay
      }

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
          const { cameraVideo, cameraPreviewImage, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();

          stopUploadCameraStream();
          clearCapturedPhotoUrl();

          if (cameraVideo) cameraVideo.classList.remove('d-none');
          if (cameraPreviewImage) {
              cameraPreviewImage.classList.add('d-none');
              cameraPreviewImage.removeAttribute('src');
          }
          if (capturePhotoBtn) capturePhotoBtn.classList.remove('d-none');
          if (retakePhotoBtn) retakePhotoBtn.classList.add('d-none');
          if (usePhotoBtn) {
              usePhotoBtn.classList.add('d-none');
              usePhotoBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirm & Use';
              usePhotoBtn.className = 'btn btn-success rounded-pill shadow-sm px-4 py-2 fw-bold d-none align-items-center modal-btn-hover transition-all';
              usePhotoBtn.style.pointerEvents = 'auto';
          }
          setUploadStatus('');
      }

      function closeCameraModal() {
          stopUploadCameraStream();
          const camModal = bootstrap.Modal.getInstance(document.getElementById('cameraModal'));
          if(camModal) camModal.hide();
          new bootstrap.Modal(document.getElementById('uploadModal')).show();
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
          const { fileInput, cameraVideo } = uploadModalElements();
          if (!cameraVideo) return;

          const camModalEl = document.getElementById('cameraModal');
          if (!camModalEl.classList.contains('show')) {
              const upModal = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
              if(upModal) upModal.hide();
              new bootstrap.Modal(camModalEl).show();
          }

          resetUploadCameraPanel();
          if (fileInput) fileInput.value = '';
          setChosenFileText('No file selected yet.');
        
          const mainPreview = document.getElementById('mainUploadPreview');
          if (mainPreview) {
              mainPreview.classList.add('d-none');
              mainPreview.src = '';
          }

          setUploadStatus('Requesting camera access...');

          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
              setUploadStatus('Camera is not supported in this browser. Use Browse Files instead.', true);
              return;
          }

          // HD Constraints
          const constraintsList = [
              { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false },
              { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
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
              setUploadStatus('Unable to open the camera. Please use Browse Files instead.', true);
              return;
          }

          cameraVideo.srcObject = uploadCameraStream;
          try { await cameraVideo.play(); } catch (error) { }

          setUploadStatus('Live camera ready. Capture a photo when you are ready.');
      }

      function captureUploadPhoto() {
          const { cameraVideo, cameraPreviewImage, cameraCanvas, capturePhotoBtn, retakePhotoBtn, usePhotoBtn } = uploadModalElements();
          if (!cameraVideo || !cameraCanvas || !cameraPreviewImage) return;

          if (!cameraVideo.videoWidth || !cameraVideo.videoHeight) {
              setUploadStatus('Camera preview is not ready yet. Please try again.', true);
              return;
          }

          const vWidth = cameraVideo.videoWidth;
          const vHeight = cameraVideo.videoHeight;
          const context = cameraCanvas.getContext('2d');
          if (!context) return;

          // SMART PORTRAIT CROPPER
          const targetAspectRatio = 3 / 4.2; 
          let cropWidth, cropHeight, sx, sy;

          if ((vWidth / vHeight) > targetAspectRatio) {
              cropHeight = vHeight;
              cropWidth = vHeight * targetAspectRatio;
              sx = (vWidth - cropWidth) / 2;
              sy = 0;
          } else {
              cropWidth = vWidth;
              cropHeight = vWidth / targetAspectRatio;
              sx = 0;
              sy = (vHeight - cropHeight) / 2;
          }

          // I-set ang canvas sa totoong portrait dimensions ng na-crop na image
          cameraCanvas.width = Math.round(cropWidth);
          cameraCanvas.height = Math.round(cropHeight);

          // Kukunin lang ang saktong nakikita sa viewfinder (Ito ang magsisilbing Fallback natin)
          context.drawImage(
              cameraVideo, 
              sx, sy, cropWidth, cropHeight, 
              0, 0, cameraCanvas.width, cameraCanvas.height
          );

          // ========================================================
          // SMART OPENCV DOCUMENT EDGE DETECTION & PERSPECTIVE WARP
          // ========================================================
          let src = null, dst = null, contours = null, hierarchy = null, maxContour = null;
          let srcCoords = null, dstCoords = null, M = null, warped = null, approx = null;

          try {
              if (typeof cv !== 'undefined' && cv.Mat) {
                  src = cv.imread(cameraCanvas);
                  dst = new cv.Mat();
                
                  // 1. Grayscale and Blur for Edge Detection
                  cv.cvtColor(src, dst, cv.COLOR_RGBA2GRAY, 0);
                  cv.GaussianBlur(dst, dst, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);
                  cv.Canny(dst, dst, 75, 200, 3, false);
                
                  contours = new cv.MatVector();
                  hierarchy = new cv.Mat();
                  cv.findContours(dst, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE);
                
                  let maxArea = 0;
                  maxContour = new cv.Mat();
                  let found = false;
                
                  // 2. Hanapin ang pinakamalaking quadrilateral (Paper Edges)
                  for (let i = 0; i < contours.size(); ++i) {
                      let cnt = contours.get(i);
                      let area = cv.contourArea(cnt);
                    
                      // Kailangang sakop ng papel ang at least 20% ng viewfinder
                      if (area > maxArea && area > (cameraCanvas.width * cameraCanvas.height * 0.20)) { 
                          let peri = cv.arcLength(cnt, true);
                          approx = new cv.Mat();
                          cv.approxPolyDP(cnt, approx, 0.02 * peri, true);
                        
                          if (approx.rows === 4) {
                              maxArea = area;
                              approx.copyTo(maxContour);
                              found = true;
                          }
                          approx.delete();
                          approx = null; // Prevent double deletion sa finally block
                      }
                  }
                
                  if (found) {
                      setUploadStatus('Document edges detected! Applying auto-crop...', false);
                      let pts = [];
                      for (let i = 0; i < 4; i++) {
                          pts.push({ x: maxContour.data32S[i * 2], y: maxContour.data32S[i * 2 + 1] });
                      }
                    
                      // 3. Ayusin ang corners: Top-Left, Top-Right, Bottom-Right, Bottom-Left
                      pts.sort((a, b) => a.y - b.y);
                      let top = pts.slice(0, 2).sort((a, b) => a.x - b.x);
                      let bottom = pts.slice(2, 4).sort((a, b) => a.x - b.x);
                      let orderedPts = [top[0], top[1], bottom[1], bottom[0]];
                    
                      // 4. Kalkulahin ang totoong width at height ng papel
                      let widthA = Math.hypot(orderedPts[2].x - orderedPts[3].x, orderedPts[2].y - orderedPts[3].y);
                      let widthB = Math.hypot(orderedPts[1].x - orderedPts[0].x, orderedPts[1].y - orderedPts[0].y);
                      let maxWidth = Math.max(widthA, widthB);
                    
                      let heightA = Math.hypot(orderedPts[1].x - orderedPts[2].x, orderedPts[1].y - orderedPts[2].y);
                      let heightB = Math.hypot(orderedPts[0].x - orderedPts[3].x, orderedPts[0].y - orderedPts[3].y);
                      let maxHeight = Math.max(heightA, heightB);
                    
                      srcCoords = cv.matFromArray(4, 1, cv.CV_32FC2, [
                          orderedPts[0].x, orderedPts[0].y,
                          orderedPts[1].x, orderedPts[1].y,
                          orderedPts[2].x, orderedPts[2].y,
                          orderedPts[3].x, orderedPts[3].y
                      ]);
                    
                      dstCoords = cv.matFromArray(4, 1, cv.CV_32FC2, [
                          0, 0,
                          maxWidth - 1, 0,
                          maxWidth - 1, maxHeight - 1,
                          0, maxHeight - 1
                      ]);
                    
                      // 5. Flatten ang papel (Perspective Warp) at i-overwrite sa Canvas
                      M = cv.getPerspectiveTransform(srcCoords, dstCoords);
                      warped = new cv.Mat();
                      cv.warpPerspective(src, warped, M, new cv.Size(maxWidth, maxHeight), cv.INTER_LINEAR, cv.BORDER_CONSTANT, new cv.Scalar());
                    
                      cameraCanvas.width = maxWidth;
                      cameraCanvas.height = maxHeight;
                      cv.imshow(cameraCanvas, warped);
                  }
              }
          } catch (e) {
              console.warn("OpenCV Auto-Crop Error (Fallback to normal crop):", e);
          } finally {
              // SAFE MEMORY CLEANUP: Ito ay palaging tatakbo kahit magka-error ang OpenCV
              if (src) src.delete();
              if (dst) dst.delete();
              if (contours) contours.delete();
              if (hierarchy) hierarchy.delete();
              if (maxContour) maxContour.delete();
              if (srcCoords) srcCoords.delete();
              if (dstCoords) dstCoords.delete();
              if (M) M.delete();
              if (warped) warped.delete();
              if (approx) approx.delete(); 
          }
          // ========================================================

          cameraCanvas.toBlob(function(blob) {
              if (!blob) {
                  setUploadStatus('Unable to capture the photo. Please try again.', true);
                  return;
              }

              clearCapturedPhotoUrl();
              const file = setFileInputFromBlob(blob);
              if (!file) return;

              if (usePhotoBtn) {
                  usePhotoBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Confirm & Use';
                  usePhotoBtn.className = 'btn btn-success rounded-pill shadow-sm px-4 py-2 fw-bold align-items-center modal-btn-hover transition-all';
                  usePhotoBtn.style.pointerEvents = 'auto';
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
          setUploadStatus('Captured photo confirmed and ready for upload.');
        
          const mainPreview = document.getElementById('mainUploadPreview');
          if (mainPreview && capturedPhotoUrl) {
              mainPreview.src = capturedPhotoUrl;
              mainPreview.classList.remove('d-none');
          }
        
          closeCameraModal();
          analyzeDocument(fileInput.files[0]); 
      }

      function browseUploadFiles() {
          const { fileInput } = uploadModalElements();
          if (!fileInput) return;
          fileInput.click();
      }

      function handleUploadFileSelection(event) {
          const file = event.target.files && event.target.files[0];
          const mainPreview = document.getElementById('mainUploadPreview');
        
          if (!file) {
              setChosenFileText('No file selected yet.');
              document.getElementById('classificationSuggestion').classList.add('d-none');
              if (mainPreview) mainPreview.classList.add('d-none');
              return;
          }

          setChosenFileText(`Selected file: ${file.name}`);
        
          if (file.type.startsWith('image/')) {
              if (mainPreview) {
                  mainPreview.src = URL.createObjectURL(file);
                  mainPreview.classList.remove('d-none');
              }
          } else {
              if (mainPreview) mainPreview.classList.add('d-none');
          }
        
          analyzeDocument(file); 
      }

      // CUSTOM DROPDOWN HELPERS
      function setUploadCategory(val, suggestedText = null) {
          document.getElementById('uploadCategoryInput').value = val;
          const textSpan = document.getElementById('uploadCategoryText');
          textSpan.innerText = suggestedText ? suggestedText : val;
          textSpan.classList.remove('text-muted');
          textSpan.classList.add('text-dark', 'fw-bold');
          if(suggestedText) textSpan.classList.add('text-success');
          else textSpan.classList.remove('text-success');
      }

      function setUploadAccess(val, textHTML) {
          document.getElementById('uploadAccessInput').value = val;
          document.getElementById('uploadAccessText').innerHTML = textHTML;
      }

      function setPhysicalStatus(val, textHTML) {
          document.getElementById('uploadPhysicalInput').value = val;
          document.getElementById('uploadPhysicalText').innerHTML = textHTML;
      }

      // DOCUMENT CLASSIFICATION AJAX ENGINE
      async function analyzeDocument(file) {
          const suggestionBox = document.getElementById('classificationSuggestion');
          const loader = document.getElementById('classificationLoader');
          const icon = document.getElementById('classificationIcon');
          const nameDisplay = document.getElementById('suggestedFolderName');
          const actions = document.getElementById('classificationActions');
          const submitBtn = document.getElementById('uploadSubmitBtn'); // Kinuha ang Submit Button
        
          // DAGDAG: I-disable ang Submit Button para iwas spam-click habang nag-s-scan
          if (submitBtn) {
              submitBtn.disabled = true;
              submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Scanning document...';
              submitBtn.classList.replace('btn-primary', 'btn-secondary');
              submitBtn.style.cursor = 'not-allowed';
          }
        
          suggestionBox.classList.remove('d-none', 'bg-success', 'border-success');
          suggestionBox.classList.add('bg-primary', 'border-primary');
          icon.classList.replace('fa-check-circle', 'fa-magic');
          icon.classList.replace('text-success', 'text-primary');
          nameDisplay.classList.remove('text-success');
        
          loader.classList.remove('d-none');
          icon.classList.add('d-none');
          actions.classList.add('d-none');

          let clientSideText = "";
        
          if (file.type.startsWith('image/')) {
              nameDisplay.innerText = "Preprocessing Image...";
              try {
                  const safeImage = await new Promise((resolve, reject) => {
                      const img = new Image();
                      img.onload = () => {
                          const canvas = document.createElement('canvas');
                          canvas.width = img.width; canvas.height = img.height;
                          canvas.getContext('2d').drawImage(img, 0, 0);
                          resolve(canvas.toDataURL('image/png'));
                          URL.revokeObjectURL(img.src);
                      };
                      img.onerror = () => reject("UNSUPPORTED_FORMAT");
                      img.src = URL.createObjectURL(file);
                  });

                  const worker = await Tesseract.createWorker("eng", 1, {
                      logger: function(m) {
                          if (m.status === 'recognizing text') nameDisplay.innerText = "Scanning Image: " + Math.round(m.progress * 100) + "%";
                          else nameDisplay.innerText = "OCR: " + m.status + "...";
                      }
                  });
                
                  nameDisplay.innerText = "Extracting text from image...";
                  const ret = await worker.recognize(safeImage);
                  clientSideText = ret.data.text;
                  await worker.terminate();
              } catch (error) {
                  nameDisplay.innerText = error === "UNSUPPORTED_FORMAT" ? "Unsupported Format. Please use a real JPG or PNG." : "Image Scan Failed. (Check Console)";
              }
          }
          else if (file.type === 'application/pdf') {
              nameDisplay.innerText = "Reading PDF Content...";
              try {
                  const arrayBuffer = await file.arrayBuffer();
                  const pdfjsLib = window['pdfjs-dist/build/pdf'];
                  pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

                  const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                  const maxPages = Math.min(pdf.numPages, 3);
                  for (let i = 1; i <= maxPages; i++) {
                      const page = await pdf.getPage(i);
                      const textContent = await page.getTextContent();
                      clientSideText += textContent.items.map(item => item.str).join(' ') + " ";
                  }
              } catch (error) { }
          }

          nameDisplay.innerText = "Analyzing extracted content...";

          let formData = new FormData();
          formData.append('action', 'analyze_document');
          formData.append('document', file);
          formData.append('ocr_text', clientSideText); 
          formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

          $.ajax({
              url: 'actions/document_handler.php', 
              type: 'POST',
              data: formData,
              processData: false,
              contentType: false,
              success: function(response) {
                  loader.classList.add('d-none');
                  icon.classList.remove('d-none');
                
                  if (response.status === 'success' && response.suggested_category) {
                      nameDisplay.innerText = response.suggested_category;
                      actions.classList.remove('d-none');
                    
                      document.getElementById('btnAcceptSuggestion').onclick = function() {
                          const catInput = document.getElementById('uploadCategoryInput');
                          if (catInput) setUploadCategory(response.suggested_category, response.suggested_category + ' (Auto-Suggested)');
                        
                          suggestionBox.classList.replace('bg-primary', 'bg-success');
                          suggestionBox.classList.replace('border-primary', 'border-success');
                          icon.classList.replace('fa-magic', 'fa-check-circle');
                          icon.classList.replace('text-primary', 'text-success');
                          nameDisplay.classList.add('text-success');
                          actions.classList.add('d-none');
                      };
                    
                      document.getElementById('btnRejectSuggestion').onclick = function() { suggestionBox.classList.add('d-none'); };
                  } else {
                      suggestionBox.classList.add('d-none'); 
                  }
              },
              error: function() { suggestionBox.classList.add('d-none'); },
              complete: function() {
                  // DAGDAG: I-enable ulit ang Submit Button kapag tapos na ang scanning (success man o error)
                  if (submitBtn) {
                      submitBtn.disabled = false;
                      submitBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i> Upload and Index File';
                      submitBtn.classList.replace('btn-secondary', 'btn-primary');
                      submitBtn.style.cursor = 'pointer';
                  }
              }
          });
      }

      // MODAL EVENT LISTENERS
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
              if (fileInput) fileInput.value = '';
              setChosenFileText('No file selected yet.');
              const mainPreview = document.getElementById('mainUploadPreview');
              if (mainPreview) {
                  mainPreview.classList.add('d-none');
                  mainPreview.src = '';
              }
              document.getElementById('classificationSuggestion').classList.add('d-none');
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
                      // FIX: Tanggalin ang animation/transition delay habang hinihila para ultra-smooth!
                      document.getElementById('viewerContentWrapper').style.transition = 'none'; 
                  }
              };
              window.onmousemove = function(e) {
                  if (!isDragging) return;
                  translateX = e.clientX - startX;
                  translateY = e.clientY - startY;
                  updateTransform();
              };
              window.onmouseup = function() {
                  if (isDragging) {
                      isDragging = false;
                      imgViewer.style.cursor = currentZoom > 1 ? 'grab' : 'default';
                      // FIX: Ibalik ang animation kapag binitiwan na para smooth ang zoom
                      document.getElementById('viewerContentWrapper').style.transition = 'transform 0.2s ease'; 
                  }
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
          const wrapper = document.getElementById('viewerContentWrapper');
        
          wrapper.style.transition = 'transform 0.2s ease'; // Ensure zoom animation is active
        
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

      function openPhysicalLocationModal(docId) { window.openPhysicalRecordProfile(docId); }

      function confirmReplacePhysical() {
          Swal.fire({
              title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Replace Physical Copy?</span>',
              html: '<p class="text-muted fs-sm mb-0">Confirm that you have printed the latest digital version, replaced the old physical copy in the cabinet, and segregated the old copy as Superseded.</p>',
              icon: 'warning',
              width: 400,
              padding: '1.5rem',
              showCancelButton: true,
              confirmButtonText: 'Verify Replacement',
              cancelButtonText: 'Cancel',
              customClass: {
                  popup: 'rounded-4 shadow-lg border-0',
                  confirmButton: 'btn btn-warning text-dark btn-sm fw-bold px-4 rounded-pill w-100',
                  cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border w-100 bg-white text-dark',
                  actions: 'd-flex w-100 mt-4 gap-2 flex-row-reverse'
              },
              buttonsStyling: false,
              focusCancel: true
          }).then((result) => {
              if (result.isConfirmed) {
                  document.getElementById('formReplacePhysical').submit();
              }
          });
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


      function openDispositionExecutionModal(buttonElement) {
          const modalElement = document.getElementById('dispositionExecutionModal');
          if (!modalElement) return;

          const executionAction = buttonElement.dataset.executionAction || '';
          const isDestroy = executionAction === 'Destroy';
          const confirmationWord = isDestroy ? 'DESTROY' : 'ARCHIVE';
          const confirmationInput = document.getElementById('dispositionExecutionConfirmation');
          const submit = document.getElementById('dispositionExecutionSubmit');
          const scopeConfirmation = document.getElementById('dispositionDigitalScopeConfirmed');
          document.getElementById('dispositionDigitalScope').hidden = !isDestroy;
          scopeConfirmation.checked = false;
          scopeConfirmation.required = isDestroy;
          scopeConfirmation.disabled = !isDestroy;

          document.getElementById('dispositionExecutionRequestId').value = buttonElement.dataset.requestId || '';
          document.getElementById('dispositionExecutionFileName').textContent = buttonElement.dataset.fileName || 'Official Record';
          document.getElementById('dispositionExecutionAction').textContent = executionAction;
          document.getElementById('dispositionExecutionNotes').value = '';

          confirmationInput.value = '';
          confirmationInput.placeholder = confirmationWord;
          document.getElementById('dispositionExecutionConfirmationHelp').textContent = `Type ${confirmationWord} exactly to continue.`;

          const warning = document.getElementById('dispositionExecutionWarning');
          if (isDestroy) {
              document.getElementById('dispositionExecutionTitle').innerHTML = '<i class="fas fa-shield-alt text-danger me-2"></i>Destroy Digital File';
              warning.className = 'alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-25 text-danger mb-3 fs-sm';
              warning.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>The verified digital file will be permanently removed. Its certificate records digital deletion, not paper disposal. Registered physical copies remain in Virtual Cabinet.';
              submit.className = 'btn btn-danger btn-sm fw-bold px-4';
              submit.innerHTML = '<i class="fas fa-shield-alt me-1"></i> Destroy Digital File';
          } else {
              document.getElementById('dispositionExecutionTitle').innerHTML = '<i class="fas fa-archive text-primary me-2"></i>Complete Permanent Archive';
              warning.className = 'alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 text-primary mb-3 fs-sm';
              warning.innerHTML = '<i class="fas fa-info-circle me-2"></i>The Official Record and verified file will be retained permanently. No file will be deleted.';
              submit.className = 'btn btn-primary btn-sm fw-bold px-4';
              submit.innerHTML = '<i class="fas fa-archive me-1"></i> Complete Archive';
          }

          new bootstrap.Modal(modalElement).show();
      }

      function openDispositionRequestModal(buttonElement) {
          const modalElement = document.getElementById('dispositionRequestModal');
          if (!modalElement) return;

          document.getElementById('dispositionRequestDocId').value = buttonElement.dataset.docId || '';
          document.getElementById('dispositionRequestFileName').textContent = buttonElement.dataset.fileName || 'Official Record';
          document.getElementById('dispositionRequestPolicyAction').textContent = buttonElement.dataset.policyAction || 'Review';
          document.getElementById('dispositionRequestReason').value = '';

          new bootstrap.Modal(modalElement).show();
      }

      function openDispositionReviewModal(buttonElement, decisionAction) {
          const modalElement = document.getElementById('dispositionReviewModal');
          if (!modalElement) return;

          const isReject = decisionAction === 'reject_disposition';
          const notes = document.getElementById('dispositionReviewNotes');
          const submit = document.getElementById('dispositionReviewSubmit');

          document.getElementById('dispositionReviewAction').value = decisionAction;
          document.getElementById('dispositionReviewRequestId').value = buttonElement.dataset.requestId || '';
          document.getElementById('dispositionReviewFileName').textContent = buttonElement.dataset.fileName || 'Official Record';
          document.getElementById('dispositionReviewReason').textContent = buttonElement.dataset.requestReason || 'No reason provided.';

          notes.value = '';
          notes.required = isReject;
          notes.minLength = isReject ? 10 : 0;
          notes.placeholder = isReject ? 'Explain why the request is being rejected.' : 'Optional approval notes.';

          document.getElementById('dispositionReviewHelp').textContent = isReject
              ? 'Required: at least 10 characters for a rejection.'
              : 'Notes are optional when approving.';
          document.getElementById('dispositionReviewTitle').innerHTML = isReject
              ? '<i class="fas fa-times-circle text-danger me-2"></i>Reject Disposition Request'
              : '<i class="fas fa-user-check text-success me-2"></i>Approve Disposition Request';

          submit.className = isReject
              ? 'btn btn-danger btn-sm fw-bold px-4'
              : 'btn btn-success btn-sm fw-bold px-4';
          submit.innerHTML = isReject
              ? '<i class="fas fa-times me-1"></i> Reject Request'
              : '<i class="fas fa-check me-1"></i> Approve Request';

          new bootstrap.Modal(modalElement).show();
      }

      function confirmCancelDisposition(buttonElement) {
          const form = buttonElement.closest('form');
          if (!form) return;

          Swal.fire({
              title: '<span class="fs-5 fw-bold text-dark">Cancel Request?</span>',
              html: '<p class="text-muted fs-sm mb-0">The pending request will be cancelled without changing or deleting the record.</p>',
              icon: 'question',
              width: 380,
              showCancelButton: true,
              confirmButtonText: 'Cancel Request',
              cancelButtonText: 'Keep Pending',
              customClass: {
                  popup: 'rounded-4 shadow-lg border-0',
                  confirmButton: 'btn btn-outline-danger btn-sm fw-bold px-4 rounded-pill',
                  cancelButton: 'btn btn-light btn-sm fw-medium px-4 rounded-pill border'
              },
              buttonsStyling: false,
              focusCancel: true
          }).then((result) => {
              if (result.isConfirmed) form.submit();
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
                  <form id="renameForm_${docId}" action="documents.php" method="POST" class="text-start mt-3">
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
                  else if (act.type === 'physical_replaced') {
                      actTitle = 'Synchronized Physical Copy';
                      dotColor = 'bg-info';
                      actContent = `<div class="bg-info bg-opacity-10 rounded-3 p-2 border border-info border-opacity-25 mt-1">
                                      <div class="fs-xs text-dark fw-medium"><i class="fas fa-sync-alt text-info me-1"></i> Physical copy replaced. <br>Superseded: <span class="text-danger fw-bold">v${parseFloat(act.old_version).toFixed(1)}</span> <i class="fas fa-arrow-right mx-1 text-muted"></i> Synced to: <span class="text-success fw-bold">v${parseFloat(act.new_version).toFixed(1)}</span></div>
                                    </div>`;
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
          document.getElementById('declareSignatureConfirmed').checked = false;
          new bootstrap.Modal(document.getElementById('declareOfficialModal')).show();
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
  <script src="assets/js/physical-record-profile.js?v=vc4b2-1"></script>
</body>
  </html>
