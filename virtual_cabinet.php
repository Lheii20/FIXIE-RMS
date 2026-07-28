<?php
require 'config/db_connect.php';
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$page_title = "Virtual Cabinet";
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'];
$unread_count = get_unread_notification_count($conn, (int)$_SESSION['user_id'], $role);

$can_view_audit = false;
if (isset($_SESSION['user_id'])) {
    $can_view_audit = has_permission($conn, $_SESSION['user_id'], 'can_view_audit_logs');
}

// Fetch Entire Cabinet Hierarchy
$tree = [];
$hier_q = $conn->query("
    SELECT b.id as b_id, b.name as b_name, 
           r.id as r_id, r.name as r_name,
           c.id as c_id, c.name as c_name,
           d.id as d_id, d.name as d_name
    FROM virt_buildings b
    LEFT JOIN virt_rooms r ON b.id = r.building_id
    LEFT JOIN virt_cabinets c ON r.id = c.room_id
    LEFT JOIN virt_drawers d ON c.id = d.cabinet_id
    ORDER BY b.name, r.name, c.name, d.name
");

if ($hier_q) {
    while($row = $hier_q->fetch_assoc()) {
        if(!isset($tree[$row['b_id']])) {
            $tree[$row['b_id']] = ['name' => $row['b_name'], 'rooms' => []];
        }
        if($row['r_id']) {
            if(!isset($tree[$row['b_id']]['rooms'][$row['r_id']])) {
                $tree[$row['b_id']]['rooms'][$row['r_id']] = ['name' => $row['r_name'], 'cabinets' => []];
            }
            if($row['c_id']) {
                if(!isset($tree[$row['b_id']]['rooms'][$row['r_id']]['cabinets'][$row['c_id']])) {
                    $tree[$row['b_id']]['rooms'][$row['r_id']]['cabinets'][$row['c_id']] = ['name' => $row['c_name'], 'drawers' => []];
                }
                if($row['d_id']) {
                    $tree[$row['b_id']]['rooms'][$row['r_id']]['cabinets'][$row['c_id']]['drawers'][$row['d_id']] = $row['d_name'];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Virtual Cabinet - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body.bg-f8f9fa { overflow: hidden !important; }
        .main-content {
            display: flex; flex-direction: column; height: 100vh !important;
            padding-top: 75px !important; padding-bottom: 15px !important;
            overflow: hidden !important; background-color: #f8f9fa;
        }
        
        /* Sleek Hierarchy Styling */
        .cabinet-sidebar {
            background: #ffffff; border-right: 1px solid #e2e8f0;
            overflow-y: auto; height: 100%; border-radius: 12px 0 0 12px;
        }
        .cabinet-sidebar::-webkit-scrollbar { width: 6px; }
        .cabinet-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        
        .cabinet-main-view {
            background: #f8fafc; overflow-y: auto; height: 100%;
            border-radius: 0 12px 12px 0; padding: 1.5rem;
        }

        /* Accordion Enhancements */
        .accordion-button:not(.collapsed) {
            background-color: #eff6ff !important; color: #1d4ed8 !important; box-shadow: none !important;
        }
        .accordion-button:focus { border-color: transparent !important; box-shadow: none !important; }
        .drawer-item {
            padding: 10px 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: all 0.2s ease;
        }
        .drawer-item:hover, .drawer-item.active {
            background-color: #f8fafc; border-left: 3px solid #3b82f6; color: #1e293b; font-weight: 600;
        }

        /* Content Tiles */
        .physical-folder-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 15px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .physical-folder-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #cbd5e1; }
        
        .document-list-item {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 12px 15px; margin-bottom: 10px; transition: all 0.2s ease;
        }
        .document-list-item:hover { border-color: #94a3b8; }
        
        .status-badge.stored { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-badge.borrowed { background-color: #fef9c3; color: #166534; border: 1px solid #fef08a; color: #a16207; }
        .status-badge.returned { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    </style>
</head>
<body class="bg-f8f9fa">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold mb-0 text-dark letter-spacing-tight"><i class="fas fa-boxes text-primary me-2"></i> Virtual Cabinet</h3>
            <p class="text-muted mb-0 small">Visual mapping of your physical paper documents and storage locations.</p>
        </div>
        <button class="btn btn-primary fw-bold shadow-sm rounded-pill px-4" disabled>
            <i class="fas fa-plus me-2"></i> Add Storage Unit
        </button>
    </div>

    <!-- Split View Container -->
    <div class="d-flex flex-grow-1 shadow-sm rounded-4 border border-light overflow-hidden">
        
        <!-- Left: Storage Hierarchy -->
        <div class="col-lg-3 col-md-4 cabinet-sidebar p-3">
            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 px-2">Storage Hierarchy</h6>
            
            <div class="accordion accordion-flush border border-light rounded-3 overflow-hidden" id="buildingAccordion">
                
                <?php if(empty($tree)): ?>
                    <div class="p-4 text-center text-muted fs-sm">No storage locations configured.</div>
                <?php else: ?>
                    <?php foreach($tree as $b_id => $b_data): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button fw-bold text-dark fs-sm py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseB_<?php echo $b_id; ?>" aria-expanded="true">
                                <i class="fas fa-building text-secondary me-2"></i> <?php echo htmlspecialchars($b_data['name']); ?>
                            </button>
                        </h2>
                        <div id="collapseB_<?php echo $b_id; ?>" class="accordion-collapse collapse show" data-bs-parent="#buildingAccordion">
                            <div class="accordion-body p-0">
                                <?php foreach($b_data['rooms'] as $r_id => $r_data): ?>
                                    <!-- Room -->
                                    <div class="px-3 py-2 bg-light border-bottom border-top fw-bold text-secondary fs-xs">
                                        <i class="fas fa-door-open me-1"></i> <?php echo htmlspecialchars($r_data['name']); ?>
                                    </div>
                                    
                                    <!-- Cabinets & Drawers -->
                                    <div class="accordion accordion-flush" id="cabinetAccordion_<?php echo $r_id; ?>">
                                        <?php foreach($r_data['cabinets'] as $c_id => $c_data): ?>
                                        <div class="accordion-item border-0 border-bottom">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed fw-medium text-dark fs-sm py-2 px-4" type="button" data-bs-toggle="collapse" data-bs-target="#collapseC_<?php echo $c_id; ?>">
                                                    <i class="fas fa-server text-info me-2 opacity-75"></i> <?php echo htmlspecialchars($c_data['name']); ?>
                                                </button>
                                            </h2>
                                            <div id="collapseC_<?php echo $c_id; ?>" class="accordion-collapse collapse" data-bs-parent="#cabinetAccordion_<?php echo $r_id; ?>">
                                                <?php foreach($c_data['drawers'] as $d_id => $d_name): ?>
                                                    <?php 
                                                    $breadCrumb = htmlspecialchars($b_data['name'] . " > " . $r_data['name'] . " > " . $c_data['name'] . " > " . $d_name); 
                                                    ?>
                                                    <div class="drawer-item fs-sm text-muted ps-5" onclick="loadDrawerContents(<?php echo $d_id; ?>, this, '<?php echo addslashes($breadCrumb); ?>')">
                                                        <i class="fas fa-window-minimize me-2"></i> <?php echo htmlspecialchars($d_name); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>

        <!-- Right: Drawer Contents & Documents -->
        <div class="col-lg-9 col-md-8 cabinet-main-view" id="mainViewContent">
            
            <!-- Breadcrumb location -->
            <div class="d-flex align-items-center text-muted fs-xs fw-bold text-uppercase letter-spacing-tight mb-4">
                <i class="fas fa-map-marker-alt text-danger me-2 fs-6"></i>
                <span id="viewBreadcrumb">Select a drawer to view contents</span>
            </div>

            <!-- Initial Placeholder -->
            <div id="placeholderView" class="text-center py-5 mt-5">
                <i class="fas fa-inbox fa-4x text-muted opacity-25 mb-3"></i>
                <h5 class="fw-bold text-dark">No Selection</h5>
                <p class="text-muted fs-sm">Navigate the storage hierarchy on the left to locate physical files.</p>
            </div>

            <!-- Loader -->
            <div id="loadingView" class="text-center py-5 mt-5 d-none">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted fs-sm mt-3 fw-bold">Fetching records...</p>
            </div>

            <!-- Folders View -->
            <div id="foldersView" class="d-none">
                <h5 class="fw-bold text-dark mb-4">Drawer Contents</h5>
                <div class="row g-3" id="foldersContainer">
                    <!-- Folders injected via AJAX -->
                </div>
            </div>

            <!-- Documents View -->
            <div id="documentsView" class="d-none">
                <div class="d-flex align-items-center mb-4 pb-3 border-bottom">
                    <button class="btn btn-sm btn-white border shadow-sm rounded-circle me-3" style="width: 35px; height: 35px;" onclick="backToFolders()">
                        <i class="fas fa-arrow-left text-secondary"></i>
                    </button>
                    <div>
                        <h5 class="fw-bold text-dark mb-0" id="selectedFolderName">Folder Name</h5>
                        <p class="text-muted fs-xs mb-0">Mapped Physical Documents</p>
                    </div>
                </div>

                <div id="documentsContainer">
                    <!-- Documents injected via AJAX -->
                </div>
            </div>
            
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function formatBreadcrumb(str) {
        return str.replace(/ > /g, ' <i class="fas fa-chevron-right mx-2 small opacity-50"></i> ');
    }

    function getFileIcon(fileName) {
        const ext = fileName.split('.').pop().toLowerCase();
        if (['pdf'].includes(ext)) return '<i class="fas fa-file-pdf text-danger fs-3 me-3 opacity-75"></i>';
        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return '<i class="far fa-image text-info fs-3 me-3 opacity-75"></i>';
        if (['doc', 'docx'].includes(ext)) return '<i class="fas fa-file-word text-primary fs-3 me-3 opacity-75"></i>';
        if (['xls', 'xlsx'].includes(ext)) return '<i class="fas fa-file-excel text-success fs-3 me-3 opacity-75"></i>';
        return '<i class="fas fa-file text-secondary fs-3 me-3 opacity-75"></i>';
    }

    function getStatusBadge(status) {
        if(status === 'Stored') return '<span class="badge status-badge stored px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> Stored</span>';
        if(status === 'Borrowed') return '<span class="badge status-badge borrowed px-3 py-2 rounded-pill"><i class="fas fa-hand-holding me-1"></i> Borrowed</span>';
        return '<span class="badge status-badge returned px-3 py-2 rounded-pill"><i class="fas fa-undo me-1"></i> Returned</span>';
    }

    function loadDrawerContents(drawerId, element, breadcrumbStr) {
        document.getElementById('viewBreadcrumb').innerHTML = formatBreadcrumb(breadcrumbStr);
        
        document.querySelectorAll('.drawer-item').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        document.getElementById('placeholderView').classList.add('d-none');
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('foldersView').classList.add('d-none');
        document.getElementById('loadingView').classList.remove('d-none');

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_folders', drawer_id: drawerId },
            success: function(response) {
                document.getElementById('loadingView').classList.add('d-none');
                
                if (response.status === 'success') {
                    let html = '';
                    if (response.data.length === 0) {
                        html = '<div class="col-12 text-center text-muted fs-sm py-4">This drawer is currently empty.</div>';
                    } else {
                        response.data.forEach(f => {
                            let iconObj = f.type === 'Archive Box' 
                                ? '<div class="bg-warning bg-opacity-10 text-warning rounded-3 d-flex align-items-center justify-content-center border border-warning border-opacity-25" style="width: 40px; height: 40px;"><i class="fas fa-archive fs-5"></i></div>'
                                : '<div class="bg-primary bg-opacity-10 text-primary rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="fas fa-folder-open fs-5"></i></div>';
                            
                            html += `
                            <div class="col-xl-4 col-md-6">
                                <div class="physical-folder-card" onclick="loadDocumentList(${f.id}, '${f.name}')">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        ${iconObj}
                                        <span class="badge bg-light text-secondary border">${f.type}</span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">${f.name}</h6>
                                    <p class="text-muted fs-xs mb-0">Contains ${f.doc_count} mapped documents</p>
                                </div>
                            </div>`;
                        });
                    }
                    document.getElementById('foldersContainer').innerHTML = html;
                    document.getElementById('foldersView').classList.remove('d-none');
                }
            }
        });
    }

    function loadDocumentList(folderId, folderName) {
        document.getElementById('foldersView').classList.add('d-none');
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('loadingView').classList.remove('d-none');
        document.getElementById('selectedFolderName').innerText = folderName;

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_documents', folder_id: folderId },
            success: function(response) {
                document.getElementById('loadingView').classList.add('d-none');
                
                if (response.status === 'success') {
                    let html = '';
                    if (response.data.length === 0) {
                        html = '<div class="text-center text-muted fs-sm py-4 border rounded bg-white">No documents are currently mapped to this physical location.</div>';
                    } else {
                        response.data.forEach(d => {
                            let icon = getFileIcon(d.file_name);
                            let statusBadge = getStatusBadge(d.status);
                            let refText = d.po_id ? `PO Reference ID: ${d.po_id}` : `Folder: ${d.category}`;
                            
                            html += `
                            <div class="document-list-item d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    ${icon}
                                    <div>
                                        <h6 class="fw-bold text-dark fs-sm mb-1">${d.file_name}</h6>
                                        <div class="text-muted fs-xs"><i class="fas fa-tag me-1"></i> ${refText}</div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    ${statusBadge}
                                    <div class="text-muted fs-xs mt-2">Updated ${d.last_updated_formatted}</div>
                                </div>
                            </div>`;
                        });
                    }
                    document.getElementById('documentsContainer').innerHTML = html;
                    document.getElementById('documentsView').classList.remove('d-none');
                }
            }
        });
    }

    function backToFolders() {
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('foldersView').classList.remove('d-none');
    }
</script>
</body>
</html>