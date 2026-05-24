<?php 
require 'config/db_connect.php'; 

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$can_manage = in_array($role, ['GM', 'President', 'Admin']);

// ==========================================
// FORM HANDLER: CREATE & DELETE COMPANY FOLDER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Validation Failed.");
    }
    
    if ($_POST['action'] === 'create_folder' && $can_manage) {
        $new_folder = trim($_POST['new_folder_name']);
        if (!empty($new_folder)) {
            $stmt_create = $conn->prepare("INSERT INTO company_folders (folder_name) VALUES (?)");
            $stmt_create->bind_param("s", $new_folder);
            if ($stmt_create->execute()) {
                header("Location: general_docs.php?success=" . urlencode("New folder created successfully."));
                exit();
            } else {
                header("Location: general_docs.php?error=" . urlencode("Failed to create folder."));
                exit();
            }
        }
    }
    
    if ($_POST['action'] === 'delete_folder') {
        if ($role !== 'Admin') {
            header("Location: general_docs.php?error=" . urlencode("Only the System Administrator can delete Company Folders."));
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
            header("Location: general_docs.php?error=" . urlencode("Cannot delete folder. The folder must be completely empty."));
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

        /* ACTION MENU DROPDOWN */
        .action-dropdown .dropdown-toggle::after { display: none; }
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
                        <button class="btn btn-white bg-white text-secondary" style="border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="border-radius: 8px;">
                            <?php if(!$view_archives): ?>
                                <li><a class="dropdown-item py-2" href="?view_archives=1"><i class="fas fa-archive me-2 text-secondary"></i> View Archives</a></li>
                            <?php endif; ?>
                            
                            <?php if($can_manage && !$view_archives): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="fas fa-folder-plus me-2 text-info"></i> Create Folder</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
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
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="max-height: 300px; overflow-y: auto; border-radius: 8px;">
                            <?php if ($type_filter != 'All'): ?>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-primary mb-1" style="font-size:0.7rem;">Current Folder</h6></li>
                                <li><span class="dropdown-item py-2 small active fw-semibold d-flex align-items-center gap-2"><i class="fas fa-folder-open text-primary" style="font-size:0.8rem;"></i><?php echo htmlspecialchars($type_filter); ?></span></li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><a class="dropdown-item py-2 small text-secondary" href="?<?php echo $view_archives ? 'view_archives=1' : ''; ?>"><i class="fas fa-th-large me-2" style="font-size:0.8rem;"></i>All Categories</a></li>
                                <?php if(count($general_categories) > 1): ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-muted mb-1" style="font-size:0.7rem;">Switch Folder</h6></li>
                                <?php foreach($general_categories as $t): if($t === $type_filter) continue; ?>
                                    <li><a class="dropdown-item py-2 small" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($t); ?>"><?php echo htmlspecialchars($t); ?></a></li>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-primary mb-1" style="font-size:0.7rem;">Filter Categories</h6></li>
                                <li><a class="dropdown-item py-2 small <?php echo $type_filter=='All'?'active':''; ?>" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=All"><i class="fas fa-th-large me-2" style="font-size:0.8rem;"></i>All Categories</a></li>
                                <?php foreach($general_categories as $t): ?>
                                    <li><a class="dropdown-item py-2 small <?php echo $type_filter==$t?'active':''; ?>" href="?<?php echo $view_archives ? 'view_archives=1&' : ''; ?>search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($t); ?>"><?php echo htmlspecialchars($t); ?></a></li>
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
                                    $secureLink = "download.php?file=" . urlencode($fileNameOnly);
                                    $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                    $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                ?>
                                <tr class="clickable-row border-bottom" onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>')">
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
                                        <div class="dropdown action-dropdown" onclick="event.stopPropagation();">
                                            <button class="btn btn-sm btn-link text-secondary dropdown-toggle px-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 32px; height: 32px; border-radius: 6px;">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 8px;">
                                                <li><a class="dropdown-item py-2" href="<?php echo $secureLink; ?>" download><i class="fas fa-download me-2 text-secondary"></i> Download</a></li>
                                                <li><a class="dropdown-item py-2" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history me-2 text-info"></i> Version History</a></li>
                                                <?php if($can_manage): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item py-2 text-success fw-medium" href="#" onclick="showWarningModal('restore', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash-restore me-2"></i> Restore</a></li>
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
                    <div class="folder-card position-relative p-3 h-100" onclick="window.location.href='?type=<?php echo urlencode($cat_name); ?>'">
                        
                        <?php if ($role === 'Admin'): ?>
                        <div class="dropdown position-absolute top-0 end-0 mt-2 me-2" onclick="event.stopPropagation();">
                            <button class="btn btn-sm btn-link text-muted p-0 border-0" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 8px;">
                                <li>
                                    <a class="dropdown-item text-danger fw-bold small py-2" href="#" onclick="event.stopPropagation(); openDeleteFolderModal('<?php echo addslashes($cat_name); ?>'); return false;">
                                        <i class="fas fa-trash-alt me-2"></i> Delete Folder
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
                                    $secureLink = "download.php?file=" . urlencode($fileNameOnly);
                                    $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                    $isPdf = ($ext == 'pdf');
                                    $current_v = !empty($row['current_version']) ? $row['current_version'] : '1.0';
                                ?>
                                <tr class="clickable-row border-bottom" onclick="viewFile('<?php echo $secureLink; ?>', '<?php echo $isImage ? 'image' : ($isPdf ? 'pdf' : 'other'); ?>')">
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
                                        <div class="dropdown action-dropdown" onclick="event.stopPropagation();">
                                            <button class="btn btn-sm btn-link text-secondary dropdown-toggle px-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 32px; height: 32px; border-radius: 6px;">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 8px;">
                                                <li><a class="dropdown-item py-2" href="<?php echo $secureLink; ?>" download><i class="fas fa-download me-2 text-secondary"></i> Download</a></li>
                                                <li><a class="dropdown-item py-2" href="#" onclick="openHistoryModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', event)"><i class="fas fa-history me-2 text-info"></i> Version History</a></li>
                                                <?php if($can_manage): ?>
                                                <li><a class="dropdown-item py-2" href="#" onclick="openVersionModal(<?php echo $row['doc_id']; ?>, '<?php echo addslashes($row['file_name']); ?>', '<?php echo $current_v; ?>', event)"><i class="fas fa-upload me-2 text-primary"></i> Upload New Version</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item py-2 text-warning" href="#" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-box-archive me-2"></i> Archive Document</a></li>
                                                <li><a class="dropdown-item py-2 text-danger" href="#" onclick="showWarningModal('delete', <?php echo $row['doc_id']; ?>, event)"><i class="fas fa-trash me-2"></i> Delete Document</a></li>
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

    <?php if($can_manage && !$view_archives): ?>
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
                            <label class="form-label fw-semibold small text-secondary">New Folder Name</label>
                            <input type="text" name="new_folder_name" class="form-control" style="border-radius: 8px;" placeholder="e.g. Health and Safety Protocols" required>
                            <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;"><i class="fas fa-info-circle"></i> Folders created here are accessible to all personnel.</small>
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
                            <label class="form-label fw-semibold small text-secondary">Category</label>
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

    <?php if ($role === 'Admin'): ?>
    <div class="modal fade" id="deleteFolderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="display: none;"><i class="fas fa-exclamation-triangle me-2"></i> Delete Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4 pt-2">
                    <div class="mb-3"><i class="fas fa-folder-minus fa-3x text-danger"></i></div>
                    <h5 class="mb-2 fw-bold text-dark">Are you sure?</h5>
                    <p class="text-muted small">You are about to permanently delete the folder <strong id="deleteFolderDisplay"></strong>.<br>This action cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-center bg-light border-top">
                    <button type="button" class="btn btn-light border px-4" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
                    <form action="general_docs.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="folder_name" id="deleteFolderName">
                        <button type="submit" class="btn btn-danger px-4 fw-medium" style="border-radius: 8px;">Yes, Delete Folder</button>
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

    <div class="modal fade" id="systemWarningModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px;">
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
            event.stopPropagation();
            document.getElementById('v_doc_id').value = id;
            document.getElementById('v_doc_name').innerText = name;
            document.getElementById('v_curr_ver').innerText = 'v' + currentV;
            new bootstrap.Modal(document.getElementById('uploadVersionModal')).show();
        }

        function openHistoryModal(id, name, event) {
            event.stopPropagation();
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
            event.stopPropagation();
            const modal = new bootstrap.Modal(document.getElementById('systemWarningModal'));
            const icon = document.getElementById('warningModalIcon');
            const message = document.getElementById('warningModalMessage');
            const submitBtn = document.getElementById('warningModalSubmitBtn');
            
            document.getElementById('warningModalAction').value = action;
            document.getElementById('warningModalDocId').value = docId;

            if (action === 'archive') {
                icon.className = 'fas fa-box-archive fa-3x text-warning';
                message.innerText = 'Are you sure you want to archive this file?';
                submitBtn.className = 'btn btn-warning text-dark px-4 fw-medium';
                submitBtn.innerText = 'Yes, Archive it';
            } else if (action === 'delete') {
                icon.className = 'fas fa-trash fa-3x text-danger';
                message.innerText = 'Are you sure you want to permanently delete this file? This action cannot be undone.';
                submitBtn.className = 'btn btn-danger px-4 fw-medium';
                submitBtn.innerText = 'Yes, Delete it';
            } else if (action === 'restore') {
                icon.className = 'fas fa-trash-restore fa-3x text-success';
                message.innerText = 'Are you sure you want to restore this file back to active?';
                submitBtn.className = 'btn btn-success px-4 fw-medium';
                submitBtn.innerText = 'Yes, Restore it';
            }
            modal.show();
        }
    </script>
</body>
</html>