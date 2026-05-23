<?php 
require 'config/db_connect.php'; 

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];
$is_top_mgmt = in_array($role, ['GM', 'President', 'Admin']);
$can_manage = $is_top_mgmt; 

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
    
    // HANDLER: EDIT RETENTION POLICY
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
            header("Location: documents.php?success=" . urlencode("Retention Policy updated successfully. System will auto-adjust related records."));
            exit();
        } else {
            header("Location: documents.php?error=" . urlencode("Failed to update policy."));
            exit();
        }
    }

    if ($is_top_mgmt) {
        // HANDLER: CREATE FOLDER
        if ($_POST['action'] === 'create_folder') {
            $parent = trim($_POST['parent_category']);
            
            if ($parent === 'NEW_PARENT_FOLDER') {
                if ($role !== 'Admin') {
                    header("Location: documents.php?error=" . urlencode("Only the System Administrator can create Main Folders."));
                    exit();
                }
                $parent = trim($_POST['new_parent_category']);
            }
            
            $sub = trim($_POST['new_folder_name'] ?? '');
            $roles_assigned = isset($_POST['assigned_roles']) ? implode(',', $_POST['assigned_roles']) : '';
            $folder_policy = !empty($_POST['folder_policy']) ? intval($_POST['folder_policy']) : null;
            
            if (!empty($parent)) {
                $stmt_create = $conn->prepare("INSERT INTO document_categories (parent_category, sub_category, assigned_to_role, policy_id) VALUES (?, ?, ?, ?)");
                $stmt_create->bind_param("sssi", $parent, $sub, $roles_assigned, $folder_policy);
                
                if ($stmt_create->execute()) {
                    header("Location: documents.php?success=" . urlencode("Folder configuration updated successfully."));
                    exit();
                } else {
                    header("Location: documents.php?error=" . urlencode("Failed to update folder."));
                    exit();
                }
            } else {
                header("Location: documents.php?error=" . urlencode("Parent Folder name cannot be empty."));
                exit();
            }
        }
        
        // HANDLER: DELETE FOLDER
        if ($_POST['action'] === 'delete_folder') {
            $delete_type = $_POST['delete_type'];
            $parent_name = $_POST['parent_name'];
            $sub_name = $_POST['sub_name'];
            
            if ($delete_type === 'parent') {
                if ($role !== 'Admin') {
                    header("Location: documents.php?error=" . urlencode("Only the System Administrator can delete Main Folders."));
                    exit();
                }
                
                $stmt_subs = $conn->prepare("SELECT sub_category FROM document_categories WHERE parent_category = ? AND sub_category != ''");
                $stmt_subs->bind_param("s", $parent_name);
                $stmt_subs->execute();
                $res_subs = $stmt_subs->get_result();
                
                $total_files = 0;
                while($sub_row = $res_subs->fetch_assoc()) {
                    $cat = $sub_row['sub_category'];
                    $chk = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE category = ?");
                    $chk->bind_param("s", $cat);
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
                    header("Location: documents.php?error=" . urlencode("Cannot delete Main Folder. Make sure ALL Sub-folders inside it are completely empty."));
                    exit();
                }
            } 
            elseif ($delete_type === 'sub') {
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
// DYNAMIC ROLE-BASED FOLDER STRUCTURE MULA SA DATABASE
// ==========================================
$parent_folders = [];
$role_assigned_folders = [];

$cat_query = $conn->query("SELECT parent_category, sub_category, assigned_to_role, policy_id FROM document_categories ORDER BY parent_category ASC, id ASC");
if ($cat_query) {
    while ($row = $cat_query->fetch_assoc()) {
        if (!isset($parent_folders[$row['parent_category']])) {
            $parent_folders[$row['parent_category']] = [];
        }
        if ($row['sub_category'] !== '') {
            $parent_folders[$row['parent_category']][] = $row['sub_category'];
        }
        if (!empty($row['assigned_to_role']) && $row['sub_category'] !== '') {
            $assigned_roles_array = explode(',', $row['assigned_to_role']);
            foreach ($assigned_roles_array as $r) {
                $r = trim($r);
                $role_assigned_folders[$r][] = $row['sub_category'];
            }
        }
    }
}

if ($is_top_mgmt) {
    $user_categories = [];
    foreach ($parent_folders as $subs) {
        $user_categories = array_merge($user_categories, $subs);
    }
} else {
    $user_categories = $role_assigned_folders[$role] ?? [];
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

if ($is_top_mgmt && empty($parent_filter) && !empty($type_filter)) {
    foreach($parent_folders as $p => $subs) {
        if(in_array($type_filter, $subs)) { 
            $parent_filter = $p; 
            break; 
        }
    }
}

// ==========================================
// DYNAMIC CONTEXTUAL FILTER LOGIC 
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

// ==========================================
// DISPOSITION QUERY
// ==========================================
if ($view_disposition && $is_top_mgmt) {
    $disp_query = $conn->query("
        SELECT d.*, p.policy_name, p.action_after_retention, u.full_name 
        FROM documents d 
        JOIN retention_policies p ON d.policy_id = p.policy_id 
        LEFT JOIN users u ON d.uploaded_by = u.user_id 
        WHERE d.disposition_status = 'Ready for Disposition'
        ORDER BY d.uploaded_at ASC
    ");
}

// ==========================================
// REGULAR QUERY CONDITIONS BUILDER
// ==========================================
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

// ==========================================
// DATABASE FETCH EXECUTION
// ==========================================
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

$query_archived = "
    SELECT d.*, p.po_number, p.client_name, p.amount, p.status as po_status, u.full_name 
    FROM documents d 
    LEFT JOIN purchase_orders p ON d.po_id = p.po_id 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    $archivedWhereClause 
    ORDER BY d.uploaded_at DESC LIMIT 50";
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
$hide_upload_button = in_array($type_filter, $restricted_upload_folders);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Official Records - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        /* NEW MODERN SLEEK UI CSS */
        .folder-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            transition: all 0.2s ease;
            background: #fff;
            cursor: pointer;
        }
        .folder-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        .folder-icon-box {
            width: 44px; height: 44px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .file-icon-md {
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px; font-size: 1.2rem;
        }
        .file-thumb-md {
            width: 40px; height: 40px;
            object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .clickable-row td { transition: background-color 0.2s ease; vertical-align: middle; }
        .clickable-row:hover td { background-color: #f8fafc !important; cursor: pointer; }
        .sleek-search {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 4px;
        }
        .sleek-search .form-control { border: none; box-shadow: none; background: transparent; }
        .sleek-search .form-control:focus { box-shadow: none; }
        .sleek-search .input-group-text { border: none; background: transparent; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <div class="d-flex justify-content-between align-items-end border-bottom pb-4 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="letter-spacing: -0.5px;">Official Records</h3>
                <p class="text-muted mb-0 small">Automated Departmental File Management</p>
            </div>
            
            <div class="d-flex gap-2 align-items-center">
                <?php if(!empty($user_categories) && !$hide_upload_button): ?>
                <button class="btn btn-primary fw-medium px-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#uploadModal" style="border-radius: 8px;">
                    <i class="fas fa-upload me-2"></i> Upload Record
                </button>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-light border text-secondary" style="border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="border-radius: 8px;">
                        
                        <?php if(in_array($role, ['Admin', 'GM'])): ?>
                        <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#managePoliciesModal"><i class="fas fa-gavel me-2 text-secondary"></i> Manage Policies</a></li>
                        <?php endif; ?>

                        <?php if($is_top_mgmt): ?>
                        <?php 
                            $disp_count = 0;
                            $disp_chk = $conn->query("SELECT COUNT(*) as cnt FROM documents WHERE disposition_status = 'Ready for Disposition'");
                            if($disp_chk) $disp_count = $disp_chk->fetch_assoc()['cnt'];
                        ?>
                        <li>
                            <a class="dropdown-item py-2" href="?disposition=1">
                                <i class="fas fa-exclamation-triangle me-2 text-warning"></i> Disposition Alerts
                                <?php if($disp_count > 0): ?><span class="badge bg-danger ms-2 rounded-pill"><?php echo $disp_count; ?></span><?php endif; ?>
                            </a>
                        </li>
                        <?php endif; ?>

                        <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#archivesModal"><i class="fas fa-archive me-2 text-secondary"></i> View Archives</a></li>
                        
                        <?php if($is_top_mgmt): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="fas fa-folder-plus me-2 text-info"></i> Create Folder</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
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
            <div class="mb-3">
                <a href="documents.php" class="btn btn-sm btn-light border text-secondary shadow-sm"><i class="fas fa-arrow-left me-1"></i> Back to Folders</a>
            </div>
            <div class="alert alert-warning border-warning shadow-sm" style="border-radius: 8px;">
                <h6 class="fw-bold mb-1"><i class="fas fa-trash-alt me-2"></i> Ready for Disposition</h6>
                <p class="mb-0 small">These documents have reached the end of their legal retention period. Please review and apply the recommended action.</p>
            </div>

            <div class="card border-0 shadow-sm" style="border-radius: 10px;">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary">Document Details</th>
                                <th class="text-secondary">Category</th>
                                <th class="text-secondary">Policy Applied</th>
                                <th class="text-secondary">Action Setup</th>
                                <th class="text-end pe-4 text-secondary">Execute Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($disp_query && $disp_query->num_rows > 0): while($row = $disp_query->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-bold text-dark d-block" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></span>
                                        <small class="text-muted" style="font-size: 0.75rem;">Uploaded: <?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></small>
                                    </td>
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
                                        <button onclick="handleDisposition(<?php echo $row['doc_id']; ?>, '<?php echo $row['action_after_retention']; ?>')" class="btn btn-sm fw-medium <?php echo ($row['action_after_retention'] == 'Destroy') ? 'btn-danger' : 'btn-success'; ?>" style="border-radius: 6px;">
                                            Execute <?php echo $row['action_after_retention']; ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted small">No documents currently require disposition.</td></tr>
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
            
            <div class="sleek-search shadow-sm mb-4">
                <form method="GET" class="d-flex w-100 align-items-center m-0">
                    <?php if(!empty($parent_filter)): ?><input type="hidden" name="parent" value="<?php echo htmlspecialchars($parent_filter); ?>"><?php endif; ?>
                    <?php if(!empty($type_filter)): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                    <?php if(!empty($doc_status)): ?><input type="hidden" name="doc_status" value="<?php echo htmlspecialchars($doc_status); ?>"><?php endif; ?>
                    
                    <div class="input-group flex-grow-1 align-items-center">
                        <span class="input-group-text text-muted px-3"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control px-2" placeholder="Search filename, tags, PO#..." value="<?php echo htmlspecialchars($search); ?>" style="font-size: 0.9rem;">
                        <?php if(!empty($search)): ?>
                            <a href="documents.php<?php echo !empty($parent_filter) ? '?parent='.urlencode($parent_filter) : ''; ?><?php echo !empty($type_filter) ? '&type='.urlencode($type_filter) : ''; ?>" class="input-group-text text-danger text-decoration-none px-3" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-primary px-4 fw-medium" style="border-radius: 6px; font-size: 0.9rem;">Search</button>

                    <div class="border-start mx-3" style="height: 24px;"></div>

                    <div class="dropdown flex-shrink-0 pe-2">
                        <button class="btn btn-light bg-transparent border-0 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" style="font-size: 0.9rem;">
                            <i class="fas fa-filter text-muted"></i>
                            <span class="d-none d-md-inline fw-medium text-secondary text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($current_filter_label); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="max-height: 300px; overflow-y: auto; border-radius: 8px;">
                            <?php foreach($dropdown_items as $item): ?>
                                <li>
                                    <a class="dropdown-item py-2 small <?php echo $item['active'] ? 'active' : ''; ?>" href="<?php echo $item['url']; ?>"><?php echo htmlspecialchars($item['label']); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </form>
            </div>

            <?php if ($is_top_mgmt && empty($parent_filter) && empty($type_filter) && empty($search)): ?>
                
                <div class="row g-3">
                    <?php foreach($parent_folders as $p_name => $subs): ?>
                        <?php if(!empty($view_filter) && $view_filter !== $p_name) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="window.location.href='?parent=<?php echo urlencode($p_name); ?>'">
                                
                                <?php if ($role === 'Admin'): ?>
                                <div class="dropdown position-absolute top-0 end-0 mt-2 me-2" onclick="event.stopPropagation();">
                                    <button class="btn btn-sm btn-link text-muted p-0 border-0" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 8px;">
                                        <li><a class="dropdown-item text-danger small fw-bold py-2" href="#" onclick="event.stopPropagation(); openDeleteFolderModal('parent', '<?php echo addslashes($p_name); ?>', ''); return false;"><i class="fas fa-trash-alt me-2"></i> Delete Main Folder</a></li>
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
                
                <div class="mb-4 d-flex align-items-center">
                    <a href="documents.php" class="btn btn-sm btn-light border text-secondary me-3 shadow-sm" style="border-radius: 6px;"><i class="fas fa-arrow-left"></i></a>
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i class="fas fa-folder-open text-warning me-2"></i> <?php echo htmlspecialchars($parent_filter); ?></h5>
                </div>
                
                <div class="row g-3">
                    <?php 
                    $sub_folders = $parent_folders[$parent_filter] ?? [];
                    foreach($sub_folders as $sub_name): ?>
                        <?php if(!empty($view_filter) && $view_filter !== $sub_name) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="window.location.href='?type=<?php echo urlencode($sub_name); ?>&parent=<?php echo urlencode($parent_filter); ?>'">
                                
                                <?php if ($can_manage): ?>
                                <div class="dropdown position-absolute top-0 end-0 mt-2 me-2" onclick="event.stopPropagation();">
                                    <button class="btn btn-sm btn-link text-muted p-0 border-0" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 8px;">
                                        <li><a class="dropdown-item text-danger small fw-bold py-2" href="#" onclick="event.stopPropagation(); openDeleteFolderModal('sub', '<?php echo addslashes($parent_filter); ?>', '<?php echo addslashes($sub_name); ?>'); return false;"><i class="fas fa-trash-alt me-2"></i> Delete Sub-folder</a></li>
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
                        <?php if(!empty($view_filter) && $view_filter !== $sub_name) continue; ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="folder-card position-relative p-3 h-100" onclick="window.location.href='?type=<?php echo urlencode($sub_name); ?>'">
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
                <div class="mb-4 d-flex align-items-center">
                    <a href="documents.php<?php echo !empty($parent_filter) ? '?parent='.urlencode($parent_filter) : ''; ?>" class="btn btn-sm btn-light border text-secondary me-3 shadow-sm" style="border-radius: 6px;"><i class="fas fa-arrow-left"></i></a>
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i class="fas fa-folder-open text-info me-2"></i> <?php echo !empty($type_filter) ? htmlspecialchars($type_filter) : 'Search Results'; ?></h5>
                </div>

                <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden;">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            
                            <?php if($type_filter === 'Purchase orders' || $type_filter === 'Purchase requests'): ?>
                                <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                    <tr>
                                        <th class="ps-4 text-secondary">Document File</th>
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
                                            $secureLink = "download.php?file=" . $fileNameOnly;
                                            $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                            $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                            $isPdf = ($ext == 'pdf');
                                            
                                            $po_stat = $row['po_status'] ?? 'No System Link';
                                            $stat_color = 'bg-secondary';
                                            if(in_array($po_stat, ['Pending', 'New'])) $stat_color = 'bg-warning text-dark';
                                            elseif(strpos($po_stat, 'Approved') !== false) $stat_color = 'bg-info text-dark';
                                            elseif($po_stat == 'Funded') $stat_color = 'bg-primary';
                                            elseif(in_array($po_stat, ['Collected', 'Delivered'])) $stat_color = 'bg-success';
                                            elseif(in_array($po_stat, ['Rejected', 'Invalid'])) $stat_color = 'bg-danger';
                                        ?>
                                        <tr class="clickable-row border-bottom" onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>')">
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                                    <span class="fw-semibold text-dark text-break" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-primary mb-1" style="font-size: 0.85rem;"><?php echo $row['po_number'] ? '#' . htmlspecialchars($row['po_number']) : 'Direct File Upload'; ?></div>
                                                <small class="text-muted" style="font-size: 0.75rem;"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($row['client_name'] ?? 'General/Internal Record'); ?></small>
                                            </td>
                                            <td class="fw-semibold text-dark" style="font-size: 0.85rem;"><?php echo $row['amount'] ? '₱ ' . number_format($row['amount'], 2) : '-'; ?></td>
                                            <td><span class="badge <?php echo $stat_color; ?> px-2 py-1 bg-opacity-10 border border-<?php echo str_replace(['bg-', ' text-dark'], '', $stat_color); ?>" style="color: inherit !important;"><?php echo htmlspecialchars($po_stat); ?></span></td>
                                            <td class="text-end pe-4 text-nowrap">
                                                <div class="btn-group">
                                                    <?php if($row['po_id']): ?><a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="btn btn-sm btn-light border text-primary" onclick="event.stopPropagation();" title="View Transaction Data"><i class="fas fa-file-invoice"></i></a><?php endif; ?>
                                                    <a href="<?php echo $secureLink; ?>" download class="btn btn-sm btn-light border text-secondary" title="Download Document" onclick="event.stopPropagation();"><i class="fas fa-download"></i></a>
                                                    <?php if($can_manage): ?>
                                                    <button type="button" class="btn btn-sm btn-light border text-warning" title="Archive Document" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-5 text-muted small">No records found.</td></tr>
                                    <?php endif; ?>
                                </tbody>

                            <?php else: ?>

                                <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                    <tr>
                                        <th class="ps-4 text-secondary">Document Title</th>
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
                                            $secureLink = "download.php?file=" . $fileNameOnly;
                                            $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                            $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                            $isPdf = ($ext == 'pdf');
                                        ?>
                                        <tr class="clickable-row border-bottom" onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>')">
                                            <td class="ps-4 py-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                                    
                                                    <div>
                                                        <span class="fw-semibold text-dark text-break d-block" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></span>
                                                        <?php if($row['disposition_status'] == 'Ready for Disposition'): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning mt-1" style="font-size: 0.7rem;"><i class="fas fa-exclamation-triangle"></i> Pending Disposition</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info mb-1 px-2"><?php echo htmlspecialchars($row['category'] ?? 'Uncategorized'); ?></span><br>
                                                <?php if(!empty($row['policy_name'])): ?>
                                                    <small class="text-primary fw-semibold d-block" style="font-size: 0.75rem;"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($row['policy_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($row['po_id']): ?><a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="badge bg-light text-primary text-decoration-none border" style="font-size: 0.75rem;" onclick="event.stopPropagation();">PO #<?php echo $row['po_number']; ?></a><?php else: ?><span class="text-muted" style="font-size: 0.75rem;">General File</span><?php endif; ?>
                                            </td>
                                            <td><div class="fw-semibold text-dark" style="font-size: 0.85rem;"><?php echo htmlspecialchars($row['full_name']); ?></div><small class="text-muted" style="font-size: 0.75rem;"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></small></td>
                                            <td class="text-end pe-4 text-nowrap">
                                                <div class="btn-group">
                                                    <a href="<?php echo $secureLink; ?>" download class="btn btn-sm btn-light border text-secondary" title="Download" onclick="event.stopPropagation();"><i class="fas fa-download"></i></a>
                                                    <?php if($can_manage): ?>
                                                    <button type="button" class="btn btn-sm btn-light border text-warning" title="Archive" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-5 text-muted small">No records found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            <?php endif; ?>
                            
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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

    <?php if($is_top_mgmt): ?>
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus me-2 text-info"></i> Create New Folder</h5>
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
                                <?php foreach(array_keys($parent_folders) as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                <?php endforeach; ?>
                                <?php if ($_SESSION['role'] === 'Admin'): ?>
                                <option value="NEW_PARENT_FOLDER" class="fw-bold text-primary">+ Create New Parent Folder</option>
                                <?php endif; ?>
                            </select>
                            <input type="text" name="new_parent_category" id="newParentCategoryInput" class="form-control d-none mt-2 border-primary" style="border-radius: 8px;" placeholder="Enter New Parent Folder Name">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">New Sub-Folder Name <small class="text-muted fw-normal" id="subFolderOptionalText">(Required)</small></label>
                            <input type="text" name="new_folder_name" id="newFolderInput" class="form-control" style="border-radius: 8px;" placeholder="e.g. Audit Reports 2026" required>
                        </div>

                        <div class="mb-3 p-3 bg-info bg-opacity-10 border border-info rounded" style="border-radius: 8px;">
                            <label class="form-label fw-semibold small text-info"><i class="fas fa-shield-alt me-1"></i> Default Retention Policy</label>
                            <select name="folder_policy" class="form-select border-info" style="border-radius: 8px;" required>
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
                        
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-secondary">Assign to User Roles <small class="text-muted fw-normal">(Hold Ctrl/Cmd for multiple)</small></label>
                            <select name="assigned_roles[]" class="form-select" style="border-radius: 8px;" multiple size="6" required>
                                <option value="Admin">Admin</option>
                                <option value="President">President</option>
                                <option value="GM">General Manager</option>
                                <option value="Finance">Finance</option>
                                <option value="Procurement">Procurement</option>
                                <option value="Sales Staff">Sales Staff</option>
                                <option value="Technical">Technical Staff</option>
                                <option value="Supply Chain">Supply Chain</option>
                            </select>
                            <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="fas fa-info-circle me-1"></i> Admin, General Manager, and President automatically have access to all folders.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info text-white fw-medium px-4" style="border-radius: 8px;">Create Folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="deleteFolderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px;">
                <div class="modal-header bg-danger text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> Delete Folder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <div class="mb-3"><i class="fas fa-folder-minus fa-3x text-danger"></i></div>
                    <h5 class="mb-2 fw-bold text-dark">Are you sure?</h5>
                    <p class="text-muted small">You are about to permanently delete the folder <strong id="deleteFolderDisplay"></strong>.<br>This action cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-center bg-light border-top">
                    <button type="button" class="btn btn-light border px-4" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                    <form action="documents.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="delete_type" id="deleteType">
                        <input type="hidden" name="parent_name" id="deleteParentName">
                        <input type="hidden" name="sub_name" id="deleteSubName">
                        <button type="submit" class="btn btn-danger px-4 fw-medium" style="border-radius: 8px;">Yes, Delete Folder</button>
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

    <div class="modal fade" id="archivesModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-archive me-2 text-secondary"></i> Archived Records</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 bg-white">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-white" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr><th class="ps-4 text-secondary py-3">Document Title</th><th class="text-secondary">Category</th><th class="text-secondary">Archived Date</th><th class="text-end pe-4 text-secondary">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if($archived_docs->num_rows > 0): while($row = $archived_docs->fetch_assoc()): ?>
                                <tr class="border-bottom">
                                    <td class="ps-4 fw-semibold text-muted" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><?php echo htmlspecialchars($row['category']); ?></span></td>
                                    <td class="text-muted small"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></td>
                                    <td class="text-end pe-4">
                                        <?php if($can_manage): ?>
                                        <button type="button" class="btn btn-sm btn-light border text-success" title="Restore" onclick="showWarningModal('restore', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash-restore"></i></button>
                                        <?php else: ?><span class="text-muted small fst-italic">Read Only</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted small">No archived records.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="systemWarningModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header border-0 pb-0" id="warningModalHeader">
                    <h5 class="modal-title fw-bold" id="warningModalTitle" style="display: none;">Warning</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4 pt-2">
                    <div class="mb-3" id="warningModalIconBox"><i id="warningModalIcon" class="fas fa-exclamation-triangle fa-3x"></i></div>
                    <h5 class="fw-bold text-dark mb-2">Are you sure?</h5>
                    <p id="warningModalMessage" class="mb-0 text-muted small"></p>
                </div>
                <div class="modal-footer justify-content-center bg-light border-top">
                    <button type="button" class="btn btn-light border px-4" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                    <form id="warningModalForm" action="actions/upload_handler.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" id="warningModalAction" value="">
                        <input type="hidden" name="doc_id" id="warningModalDocId" value="">
                        <button type="submit" id="warningModalSubmitBtn" class="btn px-4 fw-medium" style="border-radius: 8px;">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openEditPolicyModal(id, name, years, actionStr) {
            document.getElementById('editPolicyId').value = id;
            document.getElementById('editPolicyName').value = name;
            document.getElementById('editPolicyYears').value = years;
            document.getElementById('editPolicyAction').value = actionStr;
            
            var manageModalEl = document.getElementById('managePoliciesModal');
            var manageModal = bootstrap.Modal.getInstance(manageModalEl);
            if(manageModal) { manageModal.hide(); }
            
            var editModal = new bootstrap.Modal(document.getElementById('editPolicyModal'));
            editModal.show();
        }

        function openDeleteFolderModal(type, parentName, subName) {
            document.getElementById('deleteType').value = type;
            document.getElementById('deleteParentName').value = parentName;
            document.getElementById('deleteSubName').value = subName;
            document.getElementById('deleteFolderDisplay').innerText = (type === 'parent') ? parentName : subName;
            var delModal = new bootstrap.Modal(document.getElementById('deleteFolderModal'));
            delModal.show();
        }

        function toggleNewParentInput() {
            var select = document.getElementById('parentCategorySelect');
            var input = document.getElementById('newParentCategoryInput');
            var subInput = document.getElementById('newFolderInput');
            var optText = document.getElementById('subFolderOptionalText');

            if(select.value === 'NEW_PARENT_FOLDER') {
                input.classList.remove('d-none');
                input.required = true;
                subInput.required = false; 
                optText.innerText = "(Optional if creating a Main Folder only)";
            } else {
                input.classList.add('d-none');
                input.required = false;
                subInput.required = true; 
                optText.innerText = "(Required)";
            }
        }

        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            const myModal = new bootstrap.Modal(document.getElementById('previewModal'));
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            if (type === 'image') {
                modalBody.innerHTML = `<img src="${path}" class="img-fluid" style="max-height: 80vh;">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" style="border:none;"></iframe>`;
            } else {
                modalBody.innerHTML = `<div class="p-5"><i class="fas fa-file-download fa-3x text-muted mb-3"></i><p>This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary">Download File</a></div>`;
            }
            myModal.show();
        }

        function showWarningModal(action, docId, event) {
            event.stopPropagation();
            const modal = new bootstrap.Modal(document.getElementById('systemWarningModal'));
            const iconBox = document.getElementById('warningModalIconBox');
            const icon = document.getElementById('warningModalIcon');
            const message = document.getElementById('warningModalMessage');
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            
            document.getElementById('warningModalAction').value = action;
            document.getElementById('warningModalDocId').value = docId;

            if (action === 'archive') {
                icon.className = 'fas fa-box-archive fa-3x text-warning';
                message.innerText = 'Are you sure you want to archive this record?';
                submitBtn.className = 'btn btn-warning text-dark px-4 fw-medium';
                submitBtn.innerText = 'Yes, Archive it';
            } else if (action === 'delete') {
                icon.className = 'fas fa-trash fa-3x text-danger';
                message.innerText = 'Are you sure you want to permanently delete this record? This action cannot be undone.';
                submitBtn.className = 'btn btn-danger px-4 fw-medium';
                submitBtn.innerText = 'Yes, Delete it';
            } else if (action === 'restore') {
                icon.className = 'fas fa-trash-restore fa-3x text-success';
                message.innerText = 'Are you sure you want to restore this record back to active?';
                submitBtn.className = 'btn btn-success px-4 fw-medium';
                submitBtn.innerText = 'Yes, Restore it';
            }
            modal.show();
        }

        function handleDisposition(docId, actionType) {
            let confirmMsg = actionType === 'Destroy' ? "Are you sure you want to PERMANENTLY DESTROY and delete this record from the server?" : "Are you sure you want to move this record to PERMANENT ARCHIVES?";
            if(confirm(confirmMsg)) {
                $.ajax({
                    url: 'actions/disposition_handler.php',
                    type: 'POST',
                    data: { doc_id: docId, action: actionType },
                    success: function(response) {
                        try {
                            let res = JSON.parse(response);
                            if(res.status === 'success') { alert(res.message); location.reload(); } 
                            else { alert("Error: " + res.message); }
                        } catch(e) { alert("Server error. Check console."); console.error(response); }
                    }
                });
            }
        }
    </script>
</body>
</html>