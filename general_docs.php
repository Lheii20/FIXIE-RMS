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
    
    // CREATE FOLDER (Admin, GM, President)
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
    
    // DELETE FOLDER (Strictly Admin Only)
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

// FETCH ACTIVE DOCS
$query = "
    SELECT d.*, u.full_name, u.role as uploader_role
    FROM documents d 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    $whereClause 
    ORDER BY d.uploaded_at DESC";

$stmt = $conn->prepare($query);
if(!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$docs = $stmt->get_result();

// FETCH ARCHIVED DOCS
$query_archived = "
    SELECT d.*, u.full_name, u.role as uploader_role
    FROM documents d 
    LEFT JOIN users u ON d.uploaded_by = u.user_id 
    WHERE d.po_id IS NULL AND d.doc_type IS NOT NULL AND (d.category IS NULL OR d.category = '') AND d.status = 'Archived'
    ORDER BY d.uploaded_at DESC LIMIT 50";
$archived_docs = $conn->query($query_archived);

// ==========================================
// DYNAMIC CATEGORIES MULA SA DATABASE
// ==========================================
$general_categories = [];
$folder_query = $conn->query("SELECT folder_name FROM company_folders ORDER BY folder_name ASC");
if ($folder_query) {
    while ($frow = $folder_query->fetch_assoc()) {
        $general_categories[] = $frow['folder_name'];
    }
}

$counts = array_fill_keys($general_categories, 0);
$stmt_c = $conn->query("SELECT doc_type, COUNT(*) as c FROM documents WHERE po_id IS NULL AND (category IS NULL OR category = '') AND status = 'Active' GROUP BY doc_type");
if ($stmt_c) {
    while($res_c = $stmt_c->fetch_assoc()) {
        if (array_key_exists($res_c['doc_type'], $counts)) {
            $counts[$res_c['doc_type']] = $res_c['c'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Company Files - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        /* NEW MODERN SLEEK UI CSS */
        .folder-card {
            border: 1px solid #e2e8f0; border-radius: 10px;
            transition: all 0.2s ease; background: #fff; cursor: pointer;
        }
        .folder-card:hover {
            border-color: #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        .folder-icon-box {
            width: 44px; height: 44px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .file-icon-md {
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            border-radius: 8px; font-size: 1.2rem;
        }
        .file-thumb-md {
            width: 40px; height: 40px; object-fit: cover;
            border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .clickable-row td { transition: background-color 0.2s ease; vertical-align: middle; }
        .clickable-row:hover td { background-color: #f8fafc !important; cursor: pointer; }
        .sleek-search {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 4px;
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
                <h3 class="fw-bold mb-1" style="letter-spacing: -0.5px;">Company Files</h3>
                <p class="text-muted mb-0 small">General Document Storage. Accessible to all personnel.</p>
            </div>
            
            <div class="d-flex gap-2 align-items-center">
                <?php if($can_manage): ?>
                <button class="btn btn-primary fw-medium px-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#uploadGeneralModal" style="border-radius: 8px;">
                    <i class="fas fa-cloud-upload-alt me-2"></i> Upload New File
                </button>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-light border text-secondary" style="border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="border-radius: 8px;">
                        <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#archivesGeneralModal"><i class="fas fa-archive me-2 text-secondary"></i> View Archives</a></li>
                        
                        <?php if($can_manage): ?>
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

        <div class="sleek-search shadow-sm mb-4" style="position: relative; z-index: 10;">
            <form method="GET" class="d-flex w-100 align-items-center m-0">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>">

                <div class="input-group flex-grow-1 align-items-center">
                    <span class="input-group-text text-muted px-3"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control px-2" placeholder="Search filename..." value="<?php echo htmlspecialchars($search); ?>" style="font-size: 0.9rem;">
                    
                    <?php if(!empty($search)): ?>
                        <a href="?type=<?php echo urlencode($type_filter); ?>" class="input-group-text text-danger text-decoration-none px-3" title="Clear Search">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn btn-primary px-4 fw-medium" style="border-radius: 6px; font-size: 0.9rem;">Search</button>

                <div class="border-start mx-3" style="height: 24px;"></div>

                <div class="dropdown flex-shrink-0 pe-2">
                    <button class="btn btn-light bg-transparent border-0 d-flex align-items-center gap-2 text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.9rem;">
                        <i class="fas fa-filter text-muted"></i>
                        <span class="d-none d-md-inline fw-medium text-secondary">
                            <?php echo $type_filter == 'All' ? 'All Categories' : htmlspecialchars($type_filter); ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="max-height: 300px; overflow-y: auto; border-radius: 8px;">
                        <li><h6 class="dropdown-header px-3 text-uppercase fw-bold text-primary mb-1">Filter by Category</h6></li>
                        <li><a class="dropdown-item py-2 small <?php echo $type_filter=='All'?'active':''; ?>" href="?search=<?php echo urlencode($search); ?>&type=All">All Categories</a></li>
                        <?php foreach($general_categories as $t): ?>
                            <li><a class="dropdown-item py-2 small <?php echo $type_filter==$t?'active':''; ?>" href="?search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($t); ?>"><?php echo htmlspecialchars($t); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </form>
        </div>

        <?php if ($type_filter == 'All' && empty($search)): ?>
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
            <?php if($type_filter != 'All'): ?>
                <div class="mb-4 d-flex align-items-center">
                    <a href="general_docs.php" class="btn btn-sm btn-light border text-secondary me-3 shadow-sm" style="border-radius: 6px;"><i class="fas fa-arrow-left"></i></a>
                    <h5 class="mb-0 fw-bold d-flex align-items-center"><i class="fas fa-folder-open text-primary me-2"></i> <?php echo htmlspecialchars($type_filter); ?></h5>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm" style="border-radius: 10px; overflow: hidden; position: relative; z-index: 1;">
                <div class="table-responsive p-3">
                    <table id="generalDocsTable" class="table align-middle mb-0 w-100">
                        <thead class="bg-white" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                            <tr>
                                <th class="ps-4 text-secondary border-bottom">File Name</th>
                                <th class="text-secondary border-bottom">Category</th>
                                <th class="text-secondary border-bottom">Uploaded By</th>
                                <th class="text-secondary border-bottom">Date Uploaded</th>
                                <th class="text-end pe-4 text-secondary border-bottom">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            while($row = $docs->fetch_assoc()): 
                                $fileNameOnly = basename($row['file_path']);
                                $secureLink = "download.php?file=" . $fileNameOnly;
                                $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
                                $isImage = in_array($ext, ['jpg','jpeg','png', 'gif']);
                                $isPdf = ($ext == 'pdf');
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
                                <td class="text-end pe-4 text-nowrap">
                                    <div class="btn-group">
                                        <a href="<?php echo $secureLink; ?>" download class="btn btn-sm btn-light border text-secondary" title="Download" onclick="event.stopPropagation();"><i class="fas fa-download"></i></a>
                                        
                                        <?php if($can_manage): ?>
                                        <button type="button" class="btn btn-sm btn-light border text-warning" title="Archive" onclick="showWarningModal('archive', <?php echo $row['doc_id']; ?>, event)">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                        
                                        <button type="button" class="btn btn-sm btn-light border text-danger" title="Delete" onclick="showWarningModal('delete', <?php echo $row['doc_id']; ?>, event)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if($can_manage): ?>
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

    <div class="modal fade" id="archivesGeneralModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-light border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-archive me-2 text-secondary"></i> Archived Company Files</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 bg-white">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-white" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">
                                <tr>
                                    <th class="ps-4 py-3 text-secondary border-bottom">File Name</th>
                                    <th class="text-secondary border-bottom">Category</th>
                                    <th class="text-secondary border-bottom">Archived Date</th>
                                    <th class="text-end pe-4 text-secondary border-bottom">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($archived_docs->num_rows > 0): ?>
                                    <?php while($row = $archived_docs->fetch_assoc()): ?>
                                    <tr class="border-bottom">
                                        <td class="ps-4">
                                            <div class="fw-semibold text-muted text-break" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['file_name']); ?></div>
                                        </td>
                                        <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><?php echo htmlspecialchars($row['doc_type']); ?></span></td>
                                        <td class="text-muted small text-nowrap"><?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?></td>
                                        <td class="text-end pe-4">
                                            <?php if($can_manage): ?>
                                                <button type="button" class="btn btn-sm btn-light border text-success" title="Restore" onclick="showWarningModal('restore', <?php echo $row['doc_id']; ?>, event)">
                                                    <i class="fas fa-trash-restore me-1"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Read Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted small">No archived company files.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
    
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            if ($('#generalDocsTable').length) {
                $('#generalDocsTable').DataTable({
                    "order": [], 
                    "bStateSave": false, 
                    "pageLength": 10,
                    "dom": '<"table-responsive"t><"d-flex justify-content-between align-items-center mt-3"ip>',
                    "language": {
                        "emptyTable": "No company files found in this category."
                    }
                });
            }
        });
        
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