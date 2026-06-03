<?php 
require 'config/db_connect.php'; 

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
// Pinalawak ang folder management access papunta sa Admin, GM, at President
$can_manage = in_array($role, ['GM', 'President', 'Admin']);
$can_manage_folders = in_array($role, ['GM', 'President', 'Admin']);

// Kuhanin ang active policies para sa paggawa ng Folder
$policies = [];
$pol_query = $conn->query("SELECT policy_id, policy_name, retention_years, retention_months FROM retention_policies ORDER BY policy_name ASC");
if ($pol_query) {
    while($p = $pol_query->fetch_assoc()) {
        $policies[] = $p;
    }
}

// ==========================================
// FORM HANDLER: CREATE & DELETE COMPANY FOLDER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }
    
    if ($_POST['action'] === 'create_folder') {
        if (!$can_manage_folders) {
            header("Location: general_docs.php?error=" . urlencode("Only the Management and Admin can create Company Folders."));
            exit();
        }

        $new_folder = trim($_POST['new_folder_name']);
        $policy_id = (isset($_POST['policy_id']) && $_POST['policy_id'] !== '') ? intval($_POST['policy_id']) : null;

        if (!empty($new_folder) && $policy_id !== null) {
            $dup = $conn->prepare("SELECT id FROM company_folders WHERE LOWER(folder_name) = LOWER(?) LIMIT 1");
            $dup->bind_param("s", $new_folder);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                header("Location: general_docs.php?error=" . urlencode("Folder already exists."));
                exit();
            }

            // Ininsert na din natin ang policy_id sa company_folders table
            $stmt_create = $conn->prepare("INSERT INTO company_folders (folder_name, policy_id) VALUES (?, ?)");
            $stmt_create->bind_param("si", $new_folder, $policy_id);
            if ($stmt_create->execute()) {
                header("Location: general_docs.php?success=" . urlencode("New folder successfully created and assigned to a retention policy."));
                exit();
            } else {
                header("Location: general_docs.php?error=" . urlencode("Failed to create folder. Database error."));
                exit();
            }
        }
        header("Location: general_docs.php?error=" . urlencode("Folder name and Retention Policy are required."));
        exit();
    }
    
    if ($_POST['action'] === 'delete_folder') {
        if (!$can_manage_folders) {
            header("Location: general_docs.php?error=" . urlencode("You do not have permission to delete Company Folders."));
            exit();
        }
        
        $folder_to_delete = trim($_POST['folder_name']);
        $chk = $conn->prepare("SELECT COUNT(*) as total FROM documents WHERE doc_type = ? AND po_id IS NULL AND (category IS NULL OR category = '')");
        $chk->bind_param("s", $folder_to_delete);
        $chk->execute();
        $total_files = $chk->get_result()->fetch_assoc()['total'];
        
        if ($total_files == 0) {
            $del = $conn->prepare("DELETE FROM company_folders WHERE folder_name = ?");
            $del->bind_param("s", $folder_to_delete);
            if ($del->execute()) {
                header("Location: general_docs.php?success=" . urlencode("Folder deleted successfully."));
                exit();
            }
        } else {
            header("Location: general_docs.php?error=" . urlencode("Cannot delete folder. The folder must be completely empty before deletion."));
            exit();
        }
    }
}

// ==========================================
// PARAMETERS & DB FILTERS
// ==========================================
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? 'All';
$view_archives = isset($_GET['view_archives']) && $_GET['view_archives'] == '1';

// ACTIVE DOCS QUERY BUILDER
$whereClause = "WHERE d.po_id IS NULL AND d.doc_type IS NOT NULL AND (d.category IS NULL OR d.category = '') AND d.status = 'Active'";
$params = [];
$types = "";

if (!empty($search)) {
    $whereClause .= " AND (d.file_name LIKE ?)";
    $params[] = "%$search%";
    $types .= "s";
}
if ($type_filter != 'All') {
    $whereClause .= " AND d.doc_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

$query = "
    SELECT d.*, u.full_name, u.role as uploader_role
    FROM documents d 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    $whereClause 
    ORDER BY d.uploaded_at DESC LIMIT 100";
$stmt = $conn->prepare($query);
if(!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$docs = $stmt->get_result();

// ARCHIVED DOCS QUERY BUILDER
$whereArchivedClause = "WHERE d.po_id IS NULL AND d.doc_type IS NOT NULL AND (d.category IS NULL OR d.category = '') AND d.status = 'Archived'";
$params_arc = [];
$types_arc = "";

if (!empty($search)) {
    $whereArchivedClause .= " AND (d.file_name LIKE ?)";
    $params_arc[] = "%$search%";
    $types_arc .= "s";
}
if ($type_filter != 'All') {
    $whereArchivedClause .= " AND d.doc_type = ?";
    $params_arc[] = $type_filter;
    $types_arc .= "s";
}

$query_archived = "
    SELECT d.*, u.full_name, u.role as uploader_role
    FROM documents d 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    $whereArchivedClause 
    ORDER BY d.uploaded_at DESC LIMIT 200";
$stmt_arc = $conn->prepare($query_archived);
if(!empty($params_arc)) { $stmt_arc->bind_param($types_arc, ...$params_arc); }
$stmt_arc->execute();
$archived_docs = $stmt_arc->get_result();

// ==========================================
// STRICT DEDUPLICATION FOLDER FETCHING
// ==========================================
$general_categories = [];
$folder_query = $conn->query("SELECT TRIM(folder_name) as folder_name FROM company_folders ORDER BY folder_name ASC");
if ($folder_query) {
    while ($frow = $folder_query->fetch_assoc()) {
        $clean_name = $frow['folder_name'];
        if ($clean_name !== '') {
            $found = false;
            foreach ($general_categories as $existing) {
                if (strcasecmp($existing, $clean_name) == 0) { $found = true; break; }
            }
            if (!$found) $general_categories[] = $clean_name;
        }
    }
}

$counts = array_fill_keys($general_categories, 0);
$stmt_c = $conn->query("SELECT doc_type, COUNT(*) as c FROM documents WHERE po_id IS NULL AND (category IS NULL OR category = '') AND status = 'Active' GROUP BY doc_type");
if ($stmt_c) {
    while($res_c = $stmt_c->fetch_assoc()) {
        $db_type = trim($res_c['doc_type']);
        foreach($general_categories as $cat) {
            if (strcasecmp($cat, $db_type) == 0) {
                $counts[$cat] += $res_c['c'];
                break;
            }
        }
    }
}

// ==========================================
// DYNAMIC UI TITLES & BACK URL
// ==========================================
$page_title = "Company Files";
$page_subtitle = "General Document Storage. Accessible to all personnel.";
$show_back_btn = false;
$back_url = "general_docs.php";

if ($view_archives) {
    $page_title = "Archived Company Files";
    $page_subtitle = "Historical and inactive company files. Search or restore if needed.";
    $show_back_btn = true;
} elseif ($type_filter != 'All') {
    $page_title = htmlspecialchars($type_filter);
    $page_subtitle = "Viewing files inside " . htmlspecialchars($type_filter);
    $show_back_btn = true;
}

if (!empty($search)) {
    $page_subtitle .= " (Search Results)";
}

// Build breadcrumbs with parent/child relationships
$breadcrumbs = [];
$breadcrumbs[] = ['label' => 'Company Files', 'url' => 'general_docs.php', 'active' => empty($view_archives) && $type_filter === 'All'];

if ($view_archives) {
    $breadcrumbs[] = ['label' => 'Archived', 'url' => 'general_docs.php?view_archives=1', 'active' => $type_filter === 'All'];
}

if ($type_filter !== 'All') {
    $breadcrumbs[] = ['label' => htmlspecialchars($type_filter), 'url' => 'general_docs.php' . ($view_archives ? '?view_archives=1&type=' : '?type=') . urlencode($type_filter), 'active' => true];
}

// If no specific filter is active, first breadcrumb is active
if (empty($view_archives) && $type_filter === 'All') {
    $breadcrumbs[0]['active'] = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Company Files - Fixie DRMS</title>
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
                            <?php echo $page_title; ?>
                        </h3>
                        <p class="text-muted mb-0 small"><?php echo $page_subtitle; ?></p>
                    </div>
                </div>
                
                <div class="d-flex gap-2 align-items-center">
                    <?php if($can_manage && !$view_archives): ?>
                    <button class="btn btn-primary fw-medium px-3 text-nowrap shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadGeneralModal" style="border-radius: 8px;">
                        <i class="fas fa-cloud-upload-alt me-2"></i> Upload New File
                    </button>
                    <?php endif; ?>

                    <div class="dropdown">
                        <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm mt-2">
                            <?php if(!$view_archives): ?>
                                <li><a class="dropdown-item" href="?view_archives=1"><i class="fas fa-archive text-secondary"></i> View Archives</a></li>
                            <?php endif; ?>
                            
                            <?php if($can_manage_folders && !$view_archives): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="fas fa-folder-plus text-info"></i> Create Folder</a></li>
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

            <div class="sleek-search shadow-sm">
                <form method="GET" class="d-flex w-100 align-items-center m-0">
                    <?php if($view_archives): ?><input type="hidden" name="view_archives" value="1"><?php endif; ?>
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">

                    <div class="input-group flex-grow-1 align-items-center">
                        <span class="input-group-text text-muted px-3"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control px-2" placeholder="Search filename..." value="<?php echo htmlspecialchars($search); ?>" style="font-size: 0.9rem;">
                        
                        <?php if(!empty($search)): ?>
                            <a href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>type=<?php echo urlencode($type_filter); ?>" class="input-group-text text-danger text-decoration-none px-3" title="Clear Search">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-<?php echo $view_archives ? 'secondary' : 'primary'; ?> px-4 fw-medium" style="border-radius: 6px; font-size: 0.9rem;">Search <?php echo $view_archives ? 'Archives' : ''; ?></button>

                    <div class="border-start mx-3" style="height: 24px;"></div>

                    <div class="dropdown flex-shrink-0 pe-2">
                        <button class="btn btn-light bg-transparent border-0 d-flex align-items-center gap-2 text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.9rem;">
                            <i class="fas fa-filter text-muted"></i>
                            <span class="d-none d-md-inline fw-medium text-secondary">
                                <?php echo $type_filter == 'All' ? 'All Categories' : htmlspecialchars($type_filter); ?>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm mt-2" style="max-height: 300px; overflow-y: auto;">
                            <?php if ($type_filter != 'All'): ?>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-primary mb-1" style="font-size:0.7rem;">Current Folder</h6></li>
                                <li><span class="dropdown-item py-2 small active fw-semibold d-flex align-items-center gap-2"><i class="fas fa-folder-open text-primary" style="font-size:0.8rem;"></i><?php echo htmlspecialchars($type_filter); ?></span></li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><a class="dropdown-item py-2 small text-secondary" href="?<?php echo $view_archives ? 'view_archives=1' : ''; ?>"><i class="fas fa-th-large" style="font-size:0.8rem;"></i>All Categories</a></li>
                                <?php if(count($general_categories) > 1): ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-muted mb-1" style="font-size:0.7rem;">Switch Folder</h6></li>
                                <?php foreach($general_categories as $t): if($t === $type_filter) continue; ?>
                                    <li><a class="dropdown-item" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($t); ?>"><?php echo htmlspecialchars($t); ?></a></li>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-primary mb-1" style="font-size:0.7rem;">Filter Categories</h6></li>
                                <li><a class="dropdown-item <?php echo $type_filter=='All'?'active':''; ?>" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=All"><i class="fas fa-th-large"></i>All Categories</a></li>
                                <?php foreach($general_categories as $t): ?>
                                    <li><a class="dropdown-item <?php echo $type_filter==$t?'active':''; ?>" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($t); ?>"><?php echo htmlspecialchars($t); ?></a></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </form>
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

        <?php if ($view_archives): ?>
            
            <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden; position: relative; z-index: 1;">
                <div class="table-responsive table-scrollable">
                    <table class="table align-middle w-100 mb-0">
                        <thead class="bg-light" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary border-bottom">File Name</th>
                                <th class="text-secondary border-bottom">Version</th>
                                <th class="text-secondary border-bottom">Category</th>
                                <th class="text-secondary border-bottom">Archived Date</th>
                                <th class="text-end pe-4 text-secondary border-bottom">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($archived_docs->num_rows > 0): ?>
                                <?php while($row = $archived_docs->fetch_assoc()): 
                                    $fileNameOnly = basename($row['file_path'] ?? $row['file_name']);
                                    $secureLink = "download.php?file=" . urlencode($fileNameOnly) . "&doc_id=" . intval($row['doc_id']);
                                    $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                    $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                ?>
                                <tr class="clickable-row border-bottom" onclick="if(!event.target.closest('.dropdown, a, button')) { viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); }">
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if($isImage): ?><img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white"><?php elseif($isPdf): ?><div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div><?php else: ?><div class="file-icon-md bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-file-alt"></i></div><?php endif; ?>
                                            <div class="fw-semibold text-muted text-break" style="font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($row['file_name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary px-2"><?php echo htmlspecialchars($row['doc_type']); ?></span></td>
                                    <td class="text-muted small text-nowrap"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></td>
                                    <td class="text-end pe-4">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li><a class="dropdown-item" href="<?php echo $secureLink; ?>" download><i class="fas fa-download text-secondary"></i> Download</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history text-info"></i> Version History</a></li>
                                                <?php if($can_manage): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-success fw-medium" href="#" onclick="showWarningModal('restore', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash-restore"></i> Restore</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted small">No archived files found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($type_filter == 'All' && empty($search)): ?>
            <div class="row g-3">
                <?php foreach($general_categories as $cat_name): ?>
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="folder-card position-relative p-3 h-100" onclick="if(!event.target.closest('.dropdown')) { window.location.href='?type=<?php echo urlencode($cat_name); ?>'; }">
                        
                        <?php if ($can_manage_folders): ?>
                        <div class="dropdown position-absolute top-0 end-0 mt-2 me-2">
                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <a class="dropdown-item text-danger fw-bold small py-2" href="#" onclick="event.preventDefault(); openDeleteFolderModal('<?php echo addslashes($cat_name); ?>');">
                                        <i class="fas fa-trash-alt"></i> Delete Folder
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex align-items-center gap-3">
                            <div class="folder-icon-box bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-folder fs-4"></i>
                            </div>
                            <div class="text-start text-truncate pe-3">
                                <h6 class="fw-bold text-dark mb-0 text-truncate" style="font-size: 0.95rem;"><?php echo htmlspecialchars($cat_name); ?></h6>
                                <small class="text-muted" style="font-size: 0.75rem;"><?php echo $counts[$cat_name]; ?> Files</small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>

            <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden; position: relative; z-index: 1;">
                <div class="table-responsive table-scrollable">
                    <table class="table align-middle w-100 mb-0">
                        <thead class="bg-white" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary border-bottom">File Name</th>
                                <th class="text-secondary border-bottom">Version</th>
                                <th class="text-secondary border-bottom">Category</th>
                                <th class="text-secondary border-bottom">Uploaded By</th>
                                <th class="text-secondary border-bottom">Date Uploaded</th>
                                <th class="text-end pe-4 text-secondary border-bottom">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($docs->num_rows > 0): ?>
                                <?php while($row = $docs->fetch_assoc()): 
                                    $fileNameOnly = basename($row['file_path']);
                                    $secureLink = "download.php?file=" . urlencode($fileNameOnly) . "&doc_id=" . intval($row['doc_id']);
                                    $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                    $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                ?>
                                <tr class="clickable-row border-bottom" onclick="if(!event.target.closest('.dropdown, a, button')) { viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>'); }">
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if($isImage): ?>
                                                <img src="<?php echo $secureLink; ?>" class="file-thumb-md bg-white">
                                            <?php elseif($isPdf): ?>
                                                <div class="file-icon-md bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-pdf"></i></div>
                                            <?php else: ?>
                                                <div class="file-icon-md bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-alt"></i></div>
                                            <?php endif; ?>
                                            
                                            <span class="fw-semibold text-dark text-break" style="font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($row['file_name']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary" style="font-size: 0.75rem;">v<?php echo $current_v; ?></span></td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-2 text-nowrap">
                                            <?php echo htmlspecialchars($row['doc_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark text-nowrap" style="font-size: 0.85rem;"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                        <small class="text-muted text-nowrap" style="font-size: 0.75rem;"><?php echo $row['uploader_role']; ?></small>
                                    </td>
                                    <td class="text-muted text-nowrap" style="font-size: 0.85rem;"><?php echo date('M d, Y h:i A', strtotime($row['uploaded_at'])); ?></td>
                                    <td class="text-end pe-4">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn-dots dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" data-bs-popper-config='{"strategy":"fixed"}'>
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li><a class="dropdown-item" href="<?php echo $secureLink; ?>" download><i class="fas fa-download text-secondary"></i> Download</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history text-info"></i> Version History</a></li>
                                                <?php if($can_manage): ?>
                                                <li><a class="dropdown-item" href="#" onclick="openVersionModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', '<?php echo $current_v; ?>', event)"><i class="fas fa-upload text-primary"></i> Upload New Version</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-warning" href="#" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive"></i> Archive Document</a></li>
                                                <li><a class="dropdown-item text-danger" href="#" onclick="showWarningModal('delete', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash"></i> Delete Document</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted small">No company files found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
                        <input type="hidden" name="source_page" value="../general_docs.php">

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
                            <textarea name="remarks" class="form-control" style="border-radius: 8px;" rows="2" placeholder="e.g. Revised company policy" required></textarea>
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

    <?php if($can_manage_folders && !$view_archives): ?>
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-folder-plus me-2 text-info"></i> Create New Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="general_docs.php" method="POST">
                    <div class="modal-body bg-white p-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="create_folder">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary mb-1">Folder Name <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-folder"></i></span>
                                <input type="text" name="new_folder_name" class="form-control border-start-0 ps-0" style="border-radius: 0 8px 8px 0;" placeholder="e.g., Health and Safety Protocols" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary mb-1">Retention Policy <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-shield-alt"></i></span>
                                <select name="policy_id" class="form-select border-start-0 ps-0" style="border-radius: 0 8px 8px 0;" required>
                                    <option value="" selected disabled>Select a policy to assign...</option>
                                    <?php foreach($policies as $p): ?>
                                        <option value="<?php echo $p['policy_id']; ?>">
                                            <?php echo htmlspecialchars($p['policy_name']) . ' (' . $p['retention_years'] . ' Yrs, ' . $p['retention_months'] . ' Mos)'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="p-2 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-2 d-flex align-items-start gap-2">
                                <i class="fas fa-info-circle text-primary mt-1" style="font-size: 0.85rem;"></i>
                                <p class="mb-0 text-primary" style="font-size: 0.75rem; line-height: 1.4;">Files uploaded inside this folder will automatically inherit the selected retention schedule for lifecycle tracking.</p>
                            </div>
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

    <div class="modal fade" id="uploadGeneralModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-cloud-upload-alt me-2 text-primary"></i> Upload Company File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/upload_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4 bg-white">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Document Name (Optional)</label>
                            <input type="text" name="document_name" class="form-control" style="border-radius: 8px;" placeholder="Example: Blank Company Letterhead">
                            <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">If left blank, the original filename of the uploaded file will be used.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">Select Folder Destination</label>
                            <select name="doc_type" class="form-select" style="border-radius: 8px;" required>
                                <?php foreach($general_categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label fw-semibold small text-secondary">Choose File</label>
                            <input type="file" name="document" class="form-control" style="border-radius: 8px;" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" required>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-medium px-4" style="border-radius: 8px;">Upload File</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_manage_folders): ?>
    <div class="modal fade confirm-modal" id="deleteFolderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-sm">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="display: none;"><i class="fas fa-exclamation-triangle me-2"></i> Delete Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="confirm-icon text-danger mb-3"><i class="fas fa-folder-minus"></i></div>
                    <h5 class="mb-2 fw-bold text-dark">Are you sure?</h5>
                    <p class="text-muted mb-0">You are about to permanently delete <strong id="deleteFolderDisplay"></strong>. This action cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-end bg-light border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <form action="general_docs.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="folder_name" id="deleteFolderName">
                        <button type="submit" class="btn btn-danger fw-medium">Delete Folder</button>
                    </form>
                </div>
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

        function openDeleteFolderModal(folderName) {
            document.getElementById('deleteFolderName').value = folderName;
            document.getElementById('deleteFolderDisplay').innerText = folderName;
            var delModal = new bootstrap.Modal(document.getElementById('deleteFolderModal'));
            delModal.show();
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
            if(event) event.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('systemWarningModal'));
            const icon = document.getElementById('warningModalIcon');
            const message = document.getElementById('warningModalMessage');
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            
            document.getElementById('warningModalAction').value = action;
            document.getElementById('warningModalDocId').value = docId;

            if (action === 'archive') {
                icon.className = 'fas fa-box-archive text-warning';
                message.innerText = 'Archive this file? You can restore it later from archives.';
                submitBtn.className = 'btn btn-warning text-dark fw-medium';
                submitBtn.innerText = 'Archive';
            } else if (action === 'delete') {
                icon.className = 'fas fa-trash text-danger';
                message.innerText = 'Permanently delete this file? This action cannot be undone.';
                submitBtn.className = 'btn btn-danger fw-medium';
                submitBtn.innerText = 'Delete';
            } else if (action === 'restore') {
                icon.className = 'fas fa-trash-restore text-success';
                message.innerText = 'Restore this file back to active files?';
                submitBtn.className = 'btn btn-success fw-medium';
                submitBtn.innerText = 'Restore';
            }
            modal.show();
        }
    </script>
</body>
</html>