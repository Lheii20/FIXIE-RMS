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

// ==========================================
// ENTERPRISE DASHBOARD STATS CALCULATIONS
// ==========================================
$cab_stat = $conn->query("SELECT COUNT(*) as c FROM virt_cabinets")->fetch_assoc()['c'];
$draw_stat = $conn->query("SELECT COUNT(*) as c FROM virt_drawers")->fetch_assoc()['c'];
$f_stat = $conn->query("SELECT COUNT(*) as c FROM document_categories WHERE drawer_id IS NOT NULL AND sub_category != ''")->fetch_assoc()['c'];

$doc_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN record_phase IN ('Working', 'For Review', 'Converted') OR record_phase IS NULL THEN 1 ELSE 0 END) as working,
        SUM(CASE WHEN record_phase = 'Official' THEN 1 ELSE 0 END) as official,
        SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived,
        SUM(CASE WHEN d.doc_id IN (SELECT document_id FROM virt_document_locations WHERE status = 'Borrowed') THEN 1 ELSE 0 END) as borrowed
    FROM documents d 
    WHERE status != 'Recycled' AND category IN (SELECT sub_category FROM document_categories WHERE drawer_id IS NOT NULL)
")->fetch_assoc();

// Fetch All Users for Borrowing Dropdown
$all_users = [];
$u_query = $conn->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name ASC");
if ($u_query) {
    while($u = $u_query->fetch_assoc()) {
        $all_users[] = $u;
    }
}

// System Toast Notification Logic
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
    <title>Virtual Cabinet - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <!-- SweetAlert2 Toast CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Modern Flatpickr Calendar CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        
        /* Custom Date Picker Styling */
        .custom-date-btn {
            border: 1px solid #e2e8f0 !important;
            transition: all 0.2s ease;
            color: #475569;
            cursor: pointer;
            padding-left: 42px !important;
            background-color: #ffffff !important;
        }
        .custom-date-btn:hover, .custom-date-btn:focus {
            border-color: #94a3b8 !important;
            box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15) !important;
            outline: none;
        }

        /* Enterprise Flatpickr Calendar Styling */
        .flatpickr-calendar {
            width: 320px !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
            border-radius: 12px !important;
            z-index: 1070 !important;
            font-family: inherit !important;
            padding: 8px !important;
        }
        .flatpickr-day.selected {
            background: #0d6efd !important;
            border-color: #0d6efd !important;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3) !important;
        }
        .flatpickr-day:hover {
            background: #f1f5f9 !important;
        }

        /* VIRTUAL CABINET: Target File Highlighter */
        @keyframes pulseTargetItem {
            0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.5); background-color: #e0f2fe; border-color: #0d6efd; }
            70% { box-shadow: 0 0 0 10px rgba(13, 110, 253, 0); background-color: #e0f2fe; border-color: #0d6efd; }
            100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); background-color: #ffffff; border-color: #e2e8f0; }
        }
        .highlight-target-file {
            animation: pulseTargetItem 1.5s infinite !important; /* Infinite pulse para mapansin agad */
            border-left: 5px solid #0d6efd !important;
            background-color: #f0f9ff !important;
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="bg-f8f9fa">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <!-- HEADER & SMART SEARCH -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-3">
        <div>
            <h3 class="fw-bold mb-0 text-dark letter-spacing-tight"><i class="fas fa-boxes text-primary me-2"></i> Virtual Cabinet</h3>
            <p class="text-muted mb-0 small">Physical records location management and tracking system.</p>
        </div>
        
        <!-- Smart Search Bar -->
        <div class="position-relative" style="width: 450px; max-width: 100%;">
            <div class="input-group input-group-sm sleek-search shadow-sm rounded-pill overflow-hidden bg-white border border-secondary border-opacity-25">
                <span class="input-group-text bg-transparent border-0 text-primary ps-3"><i class="fas fa-search"></i></span>
                <input type="text" id="smartSearchInput" class="form-control border-0 shadow-none px-2 py-2 fs-sm fw-medium" placeholder="Search Document Title, Record No., Folder, Uploader..." onkeyup="performSmartSearch(this.value)" autocomplete="off">
                <button class="btn btn-primary fw-bold px-3 shadow-none" type="button" onclick="performSmartSearch(document.getElementById('smartSearchInput').value)">Locate File</button>
            </div>
            <!-- Search Results Dropdown -->
            <ul class="dropdown-menu shadow-lg border-0 rounded-3 w-100 mt-2 p-2" id="smartSearchResults" style="max-height: 400px; overflow-y: auto; display: none; position: absolute; z-index: 1050;">
                <!-- Results injected via JS -->
            </ul>
        </div>
    </div>

    <!-- ENTERPRISE SUMMARY DASHBOARD -->
    <div class="row g-2 mb-3">
        <div class="col"><div class="bg-white border rounded-3 p-2 text-center shadow-sm"><div class="text-muted fs-xs fw-bold text-uppercase">Cabinets</div><div class="fs-5 fw-bold text-dark"><?php echo $cab_stat; ?></div></div></div>
        <div class="col"><div class="bg-white border rounded-3 p-2 text-center shadow-sm"><div class="text-muted fs-xs fw-bold text-uppercase">Drawers</div><div class="fs-5 fw-bold text-dark"><?php echo $draw_stat; ?></div></div></div>
        <div class="col"><div class="bg-white border rounded-3 p-2 text-center shadow-sm"><div class="text-muted fs-xs fw-bold text-uppercase">Folders</div><div class="fs-5 fw-bold text-dark"><?php echo $f_stat; ?></div></div></div>
        <div class="col"><div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 p-2 text-center shadow-sm"><div class="text-primary fs-xs fw-bold text-uppercase">Working</div><div class="fs-5 fw-bold text-primary"><?php echo $doc_stats['working'] ?? 0; ?></div></div></div>
        <div class="col"><div class="bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 p-2 text-center shadow-sm"><div class="text-success fs-xs fw-bold text-uppercase">Official</div><div class="fs-5 fw-bold text-success"><?php echo $doc_stats['official'] ?? 0; ?></div></div></div>
        <div class="col"><div class="bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 p-2 text-center shadow-sm"><div class="text-warning fs-xs fw-bold text-uppercase">Borrowed</div><div class="fs-5 fw-bold text-warning"><?php echo $doc_stats['borrowed'] ?? 0; ?></div></div></div>
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
<!-- SweetAlert2 Toast JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Modern Flatpickr Calendar JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // System Consistent Toast Notification
    document.addEventListener("DOMContentLoaded", function() {
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
            
            const url = new URL(window.location);
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url);
        }

        // System Location Tracker
        window.activeDrawerId = null;
        window.activeFolderId = null;
        window.pendingFolderClick = null;

        flatpickr("#plReturnDate", {
            dateFormat: "Y-m-d", 
            altInput: true,
            altInputClass: "form-control custom-date-btn shadow-sm fw-medium fs-sm w-100 py-2 rounded-3", 
            altFormat: "F j, Y", 
            minDate: "today",
            disableMobile: true,
            appendTo: document.getElementById('physicalLocationModal'),
            position: "auto"
        });

        const urlParams = new URLSearchParams(window.location.search);
        const drawerParam = urlParams.get('drawer');
        const folderParam = urlParams.get('folder');

        if (drawerParam) {
            window.pendingFolderClick = folderParam;
            window.pendingDocHighlight = urlParams.get('doc'); // Binabasa ang Doc ID
            setTimeout(() => {
                let drawerEl = document.querySelector(`.drawer-item[onclick*="loadDrawerContents(${drawerParam}"]`);
                if(drawerEl) {
                    let accordionCollapse = drawerEl.closest('.accordion-collapse');
                    if(accordionCollapse && !accordionCollapse.classList.contains('show')) {
                        let btn = document.querySelector(`[data-bs-target="#${accordionCollapse.id}"]`);
                        if(btn) btn.click();
                    }
                    drawerEl.click();
                }
            }, 100);
        }
    });

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
        window.activeDrawerId = drawerId;
        window.activeFolderId = null;
        
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
            dataType: 'json',
            success: function(response) {
                document.getElementById('loadingView').classList.add('d-none');
                
                if (response.status === 'success') {
                    let html = '';
                    if (response.data.length === 0) {
                        html = '<div class="col-12 text-center text-muted fs-sm py-4">This drawer is currently empty. Assing folders to this drawer in Company Files first.</div>';
                    } else {
                        response.data.forEach(f => {
                            let iconObj = f.type === 'Archive Box' 
                                ? '<div class="bg-warning bg-opacity-10 text-warning rounded-3 d-flex align-items-center justify-content-center border border-warning border-opacity-25" style="width: 40px; height: 40px;"><i class="fas fa-archive fs-5"></i></div>'
                                : '<div class="bg-primary bg-opacity-10 text-primary rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="fas fa-folder-open fs-5"></i></div>';
                            
                            html += `
                            <div class="col-xl-4 col-md-6">
                                <div class="physical-folder-card h-100 d-flex flex-column" onclick="loadDocumentList(${f.id}, '${f.name}')">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        ${iconObj}
                                        <span class="badge bg-light text-secondary border">${f.type}</span>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-2 text-truncate" title="${f.name}">${f.name}</h6>
                                    
                                    <!-- Detailed Folder Stats -->
                                    <div class="d-flex gap-2 mt-auto pt-2 border-top border-light">
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle" title="Working Docs"><i class="fas fa-tools"></i> ${f.working_count}</span>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle" title="Official Records"><i class="fas fa-certificate"></i> ${f.official_count}</span>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle" title="Archived"><i class="fas fa-archive"></i> ${f.archived_count}</span>
                                    </div>
                                </div>
                            </div>`;
                        });
                    }
                    document.getElementById('foldersContainer').innerHTML = html;
                    document.getElementById('foldersView').classList.remove('d-none');
                    
                    if (window.pendingFolderClick) {
                        let fid = window.pendingFolderClick;
                        window.pendingFolderClick = null;
                        setTimeout(() => {
                            let folderCard = document.querySelector(`.physical-folder-card[onclick*="loadDocumentList(${fid}"]`);
                            if(folderCard) folderCard.click();
                        }, 200);
                    }
                } else {
                    document.getElementById('foldersContainer').innerHTML = `<div class="col-12 alert alert-danger border"><b>Database Error:</b> ${response.message}</div>`;
                    document.getElementById('foldersView').classList.remove('d-none');
                }
            },
            error: function(xhr) {
                document.getElementById('loadingView').classList.add('d-none');
                document.getElementById('foldersContainer').innerHTML = `<div class="col-12 alert alert-danger border"><b>Server Error:</b> Backend crashed. Check the Network Tab.</div>`;
                document.getElementById('foldersView').classList.remove('d-none');
                console.error("AJAX Error:", xhr.responseText);
            }
        });
    }

    function loadDocumentList(folderId, folderName) {
        window.activeFolderId = folderId;
        
        document.getElementById('foldersView').classList.add('d-none');
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('loadingView').classList.remove('d-none');
        document.getElementById('selectedFolderName').innerText = folderName;

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_documents', folder_id: folderId },
            dataType: 'json',
            success: function(response) {
                document.getElementById('loadingView').classList.add('d-none');
                
                if (response.status === 'success') {
                    let html = '';
                    if (response.data.length === 0) {
                        html = '<div class="text-center text-muted fs-sm py-4 border rounded bg-white">No documents are currently mapped to this physical location.</div>';
                    } else {
                        response.data.forEach(d => {
                            let icon = getFileIcon(d.file_name);
                            let physicalStatusBadge = getStatusBadge(d.status);
                            let refText = d.po_id ? `PO Reference: ${d.po_id}` : `Independent File`;
                            
                            let lifecycleBadge = '';
                            if (d.doc_status === 'Archived') {
                                lifecycleBadge = '<span class="badge bg-secondary text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-archive"></i> Archived</span>';
                            } else if (d.disposition_status === 'Ready for Disposition') {
                                lifecycleBadge = '<span class="badge bg-danger text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-trash-alt"></i> For Disposition</span>';
                            } else if (d.record_phase === 'Official') {
                                lifecycleBadge = '<span class="badge bg-success text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-certificate"></i> Official</span>';
                            } else {
                                lifecycleBadge = '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle ms-2" style="font-size: 0.65rem;"><i class="fas fa-tools"></i> Working</span>';
                            }
                            
                            // IDINAGDAG ANG target-doc ID DITO
                            html += `
                            <div id="target-doc-${d.doc_id}" class="document-list-item d-flex justify-content-between align-items-center" onclick="openDocumentProfile(${d.doc_id}); this.classList.remove('highlight-target-file');" style="cursor:pointer;" title="Click to view full Physical Profile">
                                <div class="d-flex align-items-center">
                                    ${icon}
                                    <div>
                                        <h6 class="fw-bold text-dark fs-sm mb-1">${d.file_name} ${lifecycleBadge}</h6>
                                        <div class="text-muted fs-xs"><i class="fas fa-folder me-1"></i> ${d.category} <span class="mx-1">•</span> <i class="fas fa-tag me-1"></i> ${refText}</div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    ${physicalStatusBadge}
                                    <div class="text-muted fs-xs mt-2">Updated ${d.last_updated_formatted}</div>
                                </div>
                            </div>`;
                        });
                    }
                    document.getElementById('documentsContainer').innerHTML = html;
                    document.getElementById('documentsView').classList.remove('d-none');

                    // SMART HIGHLIGHT & SCROLL LOGIC
                    if (window.pendingDocHighlight) {
                        setTimeout(() => {
                            let targetEl = document.getElementById('target-doc-' + window.pendingDocHighlight);
                            if (targetEl) {
                                // I-scroll pababa para makita agad
                                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                // I-apply ang kumikislap na CSS
                                targetEl.classList.add('highlight-target-file');
                            }
                            window.pendingDocHighlight = null; // Linisin para hindi mag-trigger ulit kapag pumasok sa ibang folder
                        }, 300); // 300ms delay para sure na tapos na i-render ang HTML
                    }

                } else {
                    document.getElementById('documentsContainer').innerHTML = `<div class="alert alert-danger border"><b>Database Error:</b> ${response.message}</div>`;
                    document.getElementById('documentsView').classList.remove('d-none');
                }
            },
            error: function(xhr) {
                document.getElementById('loadingView').classList.add('d-none');
                document.getElementById('documentsContainer').innerHTML = `<div class="alert alert-danger border"><b>Server Error:</b> Backend crashed. Check the Network Tab.</div>`;
                document.getElementById('documentsView').classList.remove('d-none');
                console.error("AJAX Error:", xhr.responseText);
            }
        });
    }

    function backToFolders() {
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('foldersView').classList.remove('d-none');
    }

    // ==========================================
    // ENTERPRISE SMART SEARCH LOGIC
    // ==========================================
    let searchTimeout;
    function performSmartSearch(query) {
        clearTimeout(searchTimeout);
        const resultsBox = document.getElementById('smartSearchResults');
        
        if (query.trim().length < 2) {
            resultsBox.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => {
            resultsBox.innerHTML = '<li class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Locating physical file...</li>';
            resultsBox.style.display = 'block';

            $.ajax({
                url: 'actions/cabinet_fetcher.php',
                type: 'GET',
                data: { action: 'smart_search', query: query },
                success: function(res) {
                    if (res.status === 'success') {
                        let html = '';
                        if (res.data.length === 0) {
                            html = '<li class="text-center py-3 text-muted">No physical records found matching your scan/search.</li>';
                        } else {
                            html = '<li class="px-3 py-2 bg-light border-bottom text-muted fs-xs fw-bold text-uppercase">Direct Physical Hits</li>';
                            res.data.forEach(d => {
                                let badge = d.physical_status === 'Stored' ? '<span class="badge bg-success">Stored</span>' : '<span class="badge bg-warning text-dark">Borrowed</span>';
                                let pth = d.full_physical_path ? d.full_physical_path.replace(/ > /g, ' <i class="fas fa-chevron-right text-muted small mx-1"></i> ') : 'No physical location assigned';
                                
                                html += `
                                <li>
                                    <a class="dropdown-item py-2 border-bottom hover-bg-light" href="#" onclick="openDocumentProfile(${d.doc_id})">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-bold text-dark fs-sm text-truncate"><i class="fas fa-file-alt text-primary me-2"></i>${d.file_name}</span>
                                            ${badge}
                                        </div>
                                        <div class="text-muted fs-xs text-wrap"><i class="fas fa-map-marker-alt text-danger me-1"></i> ${pth}</div>
                                    </a>
                                </li>`;
                            });
                        }
                        resultsBox.innerHTML = html;
                    }
                }
            });
        }, 400); 
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.position-relative')) {
            let resBox = document.getElementById('smartSearchResults');
            if(resBox) resBox.style.display = 'none';
        }
    });

    // ==========================================
    // DOCUMENT PROFILE MODAL LOGIC
    // ==========================================
    function openDocumentProfile(docId) {
        let resBox = document.getElementById('smartSearchResults');
        if(resBox) resBox.style.display = 'none';
        
        document.getElementById('profTitle').innerText = "Locating record...";
        document.getElementById('profBadges').innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
        new bootstrap.Modal(document.getElementById('documentProfileModal')).show();

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_document_profile', doc_id: docId },
            success: function(res) {
                if (res.status === 'success') {
                    const d = res.document;
                    
                    document.getElementById('profIcon').innerHTML = getFileIcon(d.file_name).replace('fs-3', 'fs-1');
                    document.getElementById('profTitle').innerText = d.file_name;
                    
                    let lcBadge = d.record_phase === 'Official' ? '<span class="badge bg-success px-2 py-1"><i class="fas fa-certificate me-1"></i> Official Record</span>' : '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1"><i class="fas fa-tools me-1"></i> Working Document</span>';
                    document.getElementById('profBadges').innerHTML = lcBadge + getStatusBadge(d.physical_status);

                    document.getElementById('profRecordNo').innerText = d.record_number || 'None (Working Copy)';
                    document.getElementById('profLifecycle').innerText = d.record_phase || 'Working Document';
                    document.getElementById('profOwner').innerText = d.owner_name;

                    if (d.full_physical_path) {
                        let pathArr = d.full_physical_path.split(' > ');
                        let formattedPath = pathArr.join('<br><i class="fas fa-long-arrow-alt-down text-muted my-1 ms-2"></i><br>');
                        document.getElementById('profPath').innerHTML = formattedPath;
                    } else {
                        document.getElementById('profPath').innerHTML = 'Not mapped to a physical cabinet.';
                    }

                    let borrowHtml = '';
                    if (d.physical_status === 'Borrowed') {
                        let latestB = res.borrow_history.length > 0 ? res.borrow_history[0] : null;
                        borrowHtml = `
                            <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-4 shadow-sm text-start">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px;"><i class="fas fa-hand-holding fs-xs"></i></div>
                                    <span class="fw-bold text-dark fs-sm">Currently Borrowed</span>
                                </div>
                                <div class="fs-xs text-muted mt-1 ps-1">Holder: <strong class="text-dark">${latestB ? latestB.current_holder_name : 'Unknown'}</strong></div>
                                <div class="fs-xs text-danger mt-1 ps-1 fw-medium">Expected Return: ${latestB && latestB.expected_return_date ? latestB.expected_return_date : 'Not set'}</div>
                            </div>
                        `;
                    } else {
                        borrowHtml = `
                            <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-4 shadow-sm text-start">
                                <div class="d-flex align-items-center mb-1">
                                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px;"><i class="fas fa-check-circle fs-xs"></i></div>
                                    <span class="fw-bold text-success fs-sm">Available in Cabinet</span>
                                </div>
                                <div class="fs-xs text-muted ps-1">File is securely stored and ready for checkout.</div>
                            </div>
                        `;
                    }
                    document.getElementById('profBorrowStatusBlock').innerHTML = borrowHtml;

                    let titleEl = document.querySelector('#profMovementList').previousElementSibling;
                    if(titleEl) titleEl.innerText = "Borrowing History";

                    let moveHtml = '';
                    if (!res.borrow_history || res.borrow_history.length === 0) {
                        moveHtml = '<div class="text-center text-muted fs-xs py-4"><i class="fas fa-history mb-2 fs-3 opacity-25"></i><br>No borrowing records yet.</div>';
                    } else {
                        res.borrow_history.forEach((m, index) => {
                            let recStatus;
                            
                            // SMART ALTERNATING LOGIC:
                            // Dahil ang cycle ay laging (Borrow -> Return -> Borrow), mabibilang natin ito pabalik.
                            if (d.physical_status === 'Borrowed') {
                                // Kung Borrowed ngayon: Ang Index 0 ay Borrow, Index 1 ay Return, Index 2 ay Borrow, etc. (Even numbers = Borrow)
                                recStatus = (index % 2 === 0) ? 'Borrowed' : 'Returned';
                            } else {
                                // Kung Stored/Returned ngayon: Ang Index 0 ay Return, Index 1 ay Borrow, Index 2 ay Return, etc. (Even numbers = Return)
                                recStatus = (index % 2 === 0) ? 'Returned' : 'Borrowed';
                            }
                            
                            let actionColor = (recStatus === 'Returned') ? 'text-success' : 'text-warning';
                            let actionIcon = (recStatus === 'Returned') ? 'fa-undo' : 'fa-hand-holding';
                            
                            let rawDate = m.action_date || m.created_at || m.borrowed_at || m.updated_at || m.last_updated || '';
                            let logDate = rawDate ? rawDate.split(' ')[0] : 'Unknown Date';
                            
                            moveHtml += `
                                <div class="mb-3 border-bottom pb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold fs-xs text-dark"><i class="fas fa-user-circle text-muted me-1"></i> ${m.current_holder_name || 'Unknown User'}</span>
                                        <span class="fs-xs fw-medium text-muted">${logDate}</span>
                                    </div>
                                    <div class="fs-xs text-muted"><i class="fas ${actionIcon} ${actionColor} me-1"></i> <span class="fw-bold ${actionColor}">${recStatus}</span> &bull; ${m.remarks || 'No remarks provided'}</div>
                                </div>
                            `;
                        });
                    }
                    document.getElementById('profMovementList').innerHTML = moveHtml;

                    if(d.record_phase === 'Official') {
                        document.getElementById('profOpenDigital').href = "documents.php?search=" + encodeURIComponent(d.file_name);
                    } else {
                        document.getElementById('profOpenDigital').href = "general_docs.php?search=" + encodeURIComponent(d.file_name);
                    }

                    let latestB = res.borrow_history.length > 0 ? res.borrow_history[0] : null;
                    document.getElementById('profCheckoutBtn').onclick = function() {
                        openPhysicalLocationModal(d.doc_id, d.file_name, d.physical_status, latestB);
                    };
                }
            }
        });
    }

</script>

<!-- ENTERPRISE DOCUMENT PROFILE MODAL (ENHANCED UI/UX) -->
<div class="modal fade sleek-modal" id="documentProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0 rounded-4 bg-f8f9fa overflow-hidden">
            <div class="modal-header bg-white border-bottom px-4 py-3">
                <div class="d-flex align-items-center w-100">
                    <div id="profIcon" class="me-3 flex-shrink-0"></div>
                    <div class="flex-grow-1 overflow-hidden pe-2">
                        <h5 class="fw-bold text-dark mb-1 text-truncate" id="profTitle">Loading...</h5>
                        <div id="profBadges" class="d-flex gap-2 align-items-center flex-wrap mt-1"></div>
                    </div>
                    <button type="button" class="btn-close shadow-none ms-3 flex-shrink-0" data-bs-dismiss="modal"></button>
                </div>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-7">
                        <div class="bg-white rounded-4 shadow-sm border p-4 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 border-bottom pb-2">Document Identity</h6>
                            <table class="table table-sm table-borderless fs-sm mb-4">
                                <tr><td class="text-muted py-2" width="130">Record No.</td><td class="fw-bold text-dark py-2" id="profRecordNo">N/A</td></tr>
                                <tr><td class="text-muted py-2">Lifecycle</td><td class="fw-bold text-dark py-2" id="profLifecycle">Working Document</td></tr>
                                <tr><td class="text-muted py-2">Owner</td><td class="fw-medium text-dark py-2" id="profOwner">System</td></tr>
                            </table>
                            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 border-bottom pb-2 border-top pt-2">Physical Storage Path</h6>
                            <div class="alert bg-light border p-3 d-flex align-items-center mb-0 rounded-3">
                                <i class="fas fa-map-marker-alt text-danger fs-3 me-3 flex-shrink-0"></i>
                                <div id="profPath" class="fw-bold text-dark fs-sm lh-base" style="word-break: break-word;">
                                    Fetching location...
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="bg-white rounded-4 shadow-sm border p-4 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 border-bottom pb-2">Borrowing Status</h6>
                            <div class="text-center mb-4" id="profBorrowStatusBlock"></div>
                            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 border-bottom pb-2">Borrowing History</h6>
                            <div id="profMovementList" class="flex-grow-1 overflow-auto pe-2" style="max-height: 160px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
                <button type="button" id="profCheckoutBtn" class="btn btn-outline-primary fw-bold rounded-pill px-4 shadow-sm transition-all">
                    <i class="fas fa-handshake me-2"></i> Manage Check-out
                </button>
                <a href="#" id="profOpenDigital" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm transition-all">
                    <i class="fas fa-desktop me-2"></i> View Digital File
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    // ==========================================
    // ENTERPRISE CHECKOUT LOGIC
    // ==========================================
    function openPhysicalLocationModal(docId, fileName, currentStatus, latestB = null) {
        const profileModalInstance = bootstrap.Modal.getInstance(document.getElementById('documentProfileModal'));
        if (profileModalInstance) profileModalInstance.hide();
        
        document.getElementById('plDocId').value = docId;
        document.getElementById('plDocName').innerText = fileName;
        
        const radioBorrowed = document.getElementById('statusBorrowed');
        const labelBorrowed = document.getElementById('statusBorrowedLabel');
        const radioReturned = document.getElementById('statusReturned');
        const labelReturned = document.getElementById('statusReturnedLabel');
        
        const holderBtn = document.getElementById('plHolderBtn');
        const returnDateInput = document.getElementById('plReturnDate');
        const remarksInput = document.getElementById('plRemarks');
        const fp = returnDateInput._flatpickr;

        if (currentStatus === 'Borrowed' && latestB) {
            setCheckoutHolder(latestB.current_holder_name || '');
            if (fp && latestB.expected_return_date) {
                fp.setDate(latestB.expected_return_date);
            } else if (fp) {
                fp.clear();
            }
            remarksInput.value = latestB.remarks || '';
            
            // MAHIGPIT NA KINANDADO: Hindi na pwedeng baguhin ang details ng kasalukuyang hiram
            holderBtn.disabled = true;
            holderBtn.classList.add('bg-light', 'text-dark', 'opacity-75');
            holderBtn.style.cursor = 'not-allowed';
            if(fp) fp.set('clickOpens', false);
            returnDateInput.setAttribute('readonly', 'true');
            returnDateInput.classList.add('bg-light');
            returnDateInput.style.cursor = 'not-allowed';
            remarksInput.setAttribute('readonly', 'true');
            remarksInput.classList.add('bg-light');
            remarksInput.style.cursor = 'not-allowed';

            // Radio: Borrowed disabled, Returned checked & enabled
            radioBorrowed.disabled = true;
            labelBorrowed.classList.add('text-muted', 'opacity-50');
            labelBorrowed.classList.remove('text-dark');

            radioReturned.disabled = false;
            radioReturned.checked = true;
            labelReturned.classList.remove('text-muted', 'opacity-50');
            labelReturned.classList.add('text-dark');
        } else {
            setCheckoutHolder('');
            if (fp) fp.clear();
            remarksInput.value = '';
            
            // UNLOCKED: Pwede pangalanan at lagyan ng detalye para sa bagong hiram
            holderBtn.disabled = false;
            holderBtn.classList.remove('bg-light', 'text-dark', 'opacity-75');
            holderBtn.style.cursor = 'pointer';
            if(fp) fp.set('clickOpens', true);
            returnDateInput.removeAttribute('readonly');
            returnDateInput.classList.remove('bg-light');
            returnDateInput.style.cursor = 'pointer';
            remarksInput.removeAttribute('readonly');
            remarksInput.classList.remove('bg-light');
            remarksInput.style.cursor = 'text';

            // Radio: Borrowed checked & enabled, Returned disabled
            radioBorrowed.disabled = false;
            radioBorrowed.checked = true;
            labelBorrowed.classList.remove('text-muted', 'opacity-50');
            labelBorrowed.classList.add('text-dark');

            radioReturned.disabled = true;
            labelReturned.classList.add('text-muted', 'opacity-50');
            labelReturned.classList.remove('text-dark');
        }
        
        // Buuin ang Return URL para hindi ka mawala pagkatapos mag-save
        let rUrl = '../virtual_cabinet.php';
        if(window.activeDrawerId) rUrl += '?drawer=' + window.activeDrawerId;
        if(window.activeFolderId) rUrl += '&folder=' + window.activeFolderId;
        document.querySelector('input[name="return_url"]').value = rUrl;
        
        toggleCheckoutFields();
        new bootstrap.Modal(document.getElementById('physicalLocationModal')).show();
    }

    function toggleCheckoutFields() {
        const status = document.querySelector('input[name="status"]:checked').value;
        const fields = document.getElementById('checkoutFields');
        const holderInput = document.getElementById('plHolder');
        
        if (status === 'Borrowed' || status === 'Returned') {
            fields.classList.remove('d-none');
            if (status === 'Borrowed') {
                holderInput.setAttribute('required', 'required');
            } else {
                holderInput.removeAttribute('required');
            }
        } else {
            fields.classList.add('d-none');
            holderInput.removeAttribute('required');
        }
    }

    function setCheckoutHolder(name) {
        const holderInput = document.getElementById('plHolder');
        const holderText = document.getElementById('plHolderText');
        
        if(name === '') {
            holderInput.value = '';
            holderText.innerText = 'Select Current Holder';
            holderText.classList.add('text-muted');
            holderText.classList.remove('text-dark');
        } else {
            holderInput.value = name;
            holderText.innerText = name;
            holderText.classList.remove('text-muted');
            holderText.classList.add('text-dark', 'fw-bold');
        }
    }
</script>

<!-- ENTERPRISE PHYSICAL CHECKOUT MODAL (ENHANCED UI/UX) -->
<div class="modal fade sleek-modal" id="physicalLocationModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4 bg-f8f9fa overflow-hidden">
            <div class="modal-header bg-white border-bottom px-4 py-3">
                <div>
                    <h5 class="modal-title fw-bold text-dark fs-5 letter-spacing-tight"><i class="fas fa-handshake text-primary me-2"></i>Physical Check-out</h5>
                    <p class="text-muted mb-0 fs-xs mt-1">Manage physical file borrowing and availability.</p>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert bg-white border text-dark fs-sm mb-4 shadow-sm rounded-3 d-flex align-items-center">
                    <i class="fas fa-file-alt text-primary fs-5 me-2 flex-shrink-0"></i>
                    <span class="fw-bold text-truncate" id="plDocName"></span>
                </div>
                <form action="actions/physical_location_handler.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_location">
                    <input type="hidden" name="doc_id" id="plDocId">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Physical Status <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 p-3 bg-white border rounded-3 shadow-sm">
                            <div class="form-check m-0">
                                <input class="form-check-input shadow-none" type="radio" name="status" id="statusBorrowed" value="Borrowed" onchange="toggleCheckoutFields()">
                                <label class="form-check-label fs-sm fw-medium text-dark" id="statusBorrowedLabel" for="statusBorrowed">Borrowed</label>
                            </div>
                            <div class="form-check m-0">
                                <input class="form-check-input shadow-none" type="radio" name="status" id="statusReturned" value="Returned" onchange="toggleCheckoutFields()">
                                <label class="form-check-label fs-sm fw-medium text-dark" id="statusReturnedLabel" for="statusReturned">Returned</label>
                            </div>
                        </div>
                    </div>

                    <!-- Extra fields for Borrowing -->
                    <div id="checkoutFields" class="d-none">
                        <div class="mb-3 position-relative">
                            <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Current Holder <span class="text-danger">*</span></label>
                            
                            <!-- CUSTOM DROPDOWN PARA SA CURRENT HOLDER -->
                            <div class="dropdown w-100">
                                <!-- Naka-hide na HTML5 input para gumana ang "required" pop-up ng browser nang hindi nasisira ang design -->
                                <input type="text" name="current_holder" id="plHolder" style="opacity: 0; position: absolute; bottom: 0; left: 50%; pointer-events: none; z-index: -1;">
                                
                                <button class="btn custom-select-btn d-flex justify-content-between align-items-center w-100 py-2 rounded-3 text-start shadow-sm bg-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="plHolderBtn">
                                    <span id="plHolderText" class="text-muted fw-medium fs-sm">Select Current Holder</span>
                                    <i class="fas fa-chevron-down text-secondary fs-xs"></i>
                                </button>
                                
                                <ul class="dropdown-menu shadow-lg border-0 rounded-4 mt-1 w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                    <?php foreach ($all_users as $u): ?>
                                        <li>
                                            <button type="button" class="dropdown-item rounded-3 fs-sm fw-medium py-2 text-dark" onclick="setCheckoutHolder('<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">
                                                <div class="d-flex align-items-center">
                                                    <div class="rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold text-white shadow-sm flex-shrink-0" style="width: 32px; height: 32px; font-size: 13px; background-color: #64748b;">
                                                        <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold mb-0 lh-sm"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                                        <div class="fs-xs text-muted fw-normal mt-1 lh-1"><?php echo htmlspecialchars($u['role']); ?></div>
                                                    </div>
                                                </div>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Expected Return Date</label>
                            <div class="position-relative">
                                <div class="position-absolute top-50 translate-middle-y text-primary opacity-75" style="left: 15px; pointer-events: none; z-index: 5;">
                                    <i class="far fa-calendar-alt"></i>
                                </div>
                                <input type="text" name="expected_return" id="plReturnDate" placeholder="Select Return Date" class="d-none">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted text-uppercase letter-spacing-tight">Remarks / Reason</label>
                            <textarea name="remarks" id="plRemarks" class="form-control shadow-none border-light bg-white fs-sm" rows="2" placeholder="e.g. Audit checking, External meeting..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2">
                        <button type="button" class="btn btn-light bg-white border fw-medium px-4 shadow-sm rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm rounded-pill">Save Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>