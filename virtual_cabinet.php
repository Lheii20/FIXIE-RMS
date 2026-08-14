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
        SUM(CASE WHEN status != 'Archived' AND (record_phase IN ('Working', 'For Review', 'Draft', 'Under Review', 'Pending Approval', 'Needs Revision', 'Rejected', 'Approved') OR record_phase IS NULL) THEN 1 ELSE 0 END) as working,
        SUM(CASE WHEN status != 'Archived' AND record_phase = 'Official' THEN 1 ELSE 0 END) as official,
        SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived,
        SUM(CASE WHEN d.doc_id IN (SELECT document_id FROM virt_document_locations WHERE status = 'Borrowed') THEN 1 ELSE 0 END) as borrowed
    FROM documents d 
    WHERE status != 'Recycled' AND (record_phase != 'Converted' OR record_phase IS NULL) AND category IN (SELECT sub_category FROM document_categories WHERE drawer_id IS NOT NULL)
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
    
</head>
<body class="bg-f8f9fa page-virtual-cabinet">
<?php include 'sidebar.php'; ?>

<div class="main-content fade-in">
    <!-- HEADER & SMART SEARCH -->
    <div class="vc-page-header d-flex flex-wrap justify-content-between align-items-end mb-3 gap-3">
        <div>
            <h3 class="fw-bold mb-0 text-dark letter-spacing-tight"><i class="fas fa-boxes-stacked text-primary me-2"></i> Virtual Cabinet</h3>
            <p class="text-muted mb-0 small">Physical records location management and tracking system.</p>
        </div>
        
        <!-- Smart Search Bar -->
        <div class="vc-smart-search-wrap position-relative" style="width: 450px; max-width: 100%;">
            <div class="input-group input-group-sm sleek-search shadow-sm rounded-pill overflow-hidden bg-white border border-secondary border-opacity-25">
                <span class="input-group-text bg-transparent border-0 text-primary ps-3"><i class="fas fa-search"></i></span>
                <input type="text" id="smartSearchInput" class="form-control border-0 shadow-none px-2 py-2 fs-sm fw-medium" placeholder="Search Document Title, Record No., Folder, Uploader..." onkeyup="performSmartSearch(this.value)" autocomplete="off">
            </div>
            <!-- Search Results Dropdown -->
            <ul class="dropdown-menu shadow-lg border-0 rounded-3 w-100 mt-2 p-2" id="smartSearchResults" style="max-height: 400px; overflow-y: auto; display: none; position: absolute; z-index: 1050;">
                <!-- Results injected via JS -->
            </ul>
        </div>
    </div>

    <!-- ENTERPRISE SUMMARY DASHBOARD -->
    <div class="vc-stats-grid row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card">
                <div class="vc-stat-icon bg-light text-secondary border"><i class="fas fa-server"></i></div>
                <div><div class="text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Cabinets</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $cab_stat; ?></div></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card">
                <div class="vc-stat-icon bg-light text-secondary border"><i class="fas fa-window-minimize"></i></div>
                <div><div class="text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Drawers</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $draw_stat; ?></div></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card">
                <div class="vc-stat-icon bg-light text-secondary border"><i class="fas fa-folder"></i></div>
                <div><div class="text-muted fs-xs fw-bold text-uppercase letter-spacing-tight">Folders</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $f_stat; ?></div></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card border-primary border-opacity-25">
                <div class="vc-stat-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-tools"></i></div>
                <div><div class="text-primary fs-xs fw-bold text-uppercase letter-spacing-tight">Working</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $doc_stats['working'] ?? 0; ?></div></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card border-success border-opacity-25">
                <div class="vc-stat-icon bg-success bg-opacity-10 text-success"><i class="fas fa-certificate"></i></div>
                <div><div class="text-success fs-xs fw-bold text-uppercase letter-spacing-tight">Official</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $doc_stats['official'] ?? 0; ?></div></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="vc-stat-card border-warning border-opacity-50">
                <div class="vc-stat-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-hand-holding"></i></div>
                <div><div class="text-warning fs-xs fw-bold text-uppercase letter-spacing-tight">Borrowed</div><div class="fs-5 fw-bold text-dark lh-1 mt-1"><?php echo $doc_stats['borrowed'] ?? 0; ?></div></div>
            </div>
        </div>
    </div>

    <!-- Split View Container -->
    <div class="vc-cabinet-shell d-flex flex-grow-1 shadow-sm rounded-4 border border-light overflow-hidden">
        <button class="vc-mobile-location-toggle d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#cabinetLocationTree" aria-expanded="false" aria-controls="cabinetLocationTree">
            <span><i class="fas fa-folder-tree me-2"></i>Browse storage location</span>
            <i class="fas fa-chevron-down vc-toggle-chevron" aria-hidden="true"></i>
        </button>
        
        <!-- Left: Storage Hierarchy -->
        <div class="col-lg-3 col-md-4 cabinet-sidebar p-3 collapse d-md-block" id="cabinetLocationTree">
            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 px-2">Storage Hierarchy</h6>
            
            <div class="accordion tree-accordion" id="buildingAccordion">
                
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
                                    <div class="px-4 py-2 bg-f8f9fa border-bottom fw-bold text-secondary fs-xs text-uppercase letter-spacing-tight">
                                        <i class="fas fa-door-open me-1 opacity-75"></i> <?php echo htmlspecialchars($r_data['name']); ?>
                                    </div>
                                    
                                    <!-- Cabinets & Drawers -->
                                    <div class="accordion tree-accordion ps-2" id="cabinetAccordion_<?php echo $r_id; ?>">
                                        <?php foreach($r_data['cabinets'] as $c_id => $c_data): ?>
                                        <div class="accordion-item border-0 border-bottom">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed fw-medium text-dark fs-sm py-2 px-4" type="button" data-bs-toggle="collapse" data-bs-target="#collapseC_<?php echo $c_id; ?>">
                                                    <i class="fas fa-boxes-stacked text-info me-2 opacity-75"></i> <?php echo htmlspecialchars($c_data['name']); ?>
                                                </button>
                                            </h2>
                                            <div id="collapseC_<?php echo $c_id; ?>" class="accordion-collapse collapse" data-bs-parent="#cabinetAccordion_<?php echo $r_id; ?>">
                                                <?php foreach($c_data['drawers'] as $d_id => $d_name): ?>
                                                    <?php 
                                                    $breadCrumb = htmlspecialchars($b_data['name'] . " > " . $r_data['name'] . " > " . $c_data['name'] . " > " . $d_name); 
                                                    ?>
                                                    <div class="drawer-item fs-sm text-muted ps-5" onclick="loadDrawerContents(<?php echo $d_id; ?>, this, '<?php echo addslashes($breadCrumb); ?>')">
                                                        <i class="fas fa-box me-2"></i> <?php echo htmlspecialchars($d_name); ?>
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
        <div class="col-lg-9 col-md-8 cabinet-main-view vc-main-panel" id="mainViewContent">
            
            <!-- Breadcrumb location -->
            <div class="vc-location-breadcrumb d-flex align-items-center text-muted fs-xs fw-bold text-uppercase letter-spacing-tight mb-4">
                <i class="fas fa-location-dot text-danger me-2 fs-6"></i>
                <span id="viewBreadcrumb">Select a drawer to view contents</span>
            </div>

            <!-- Initial Placeholder -->
            <div id="placeholderView" class="vc-placeholder text-center py-5 mt-5">
                <i class="fas fa-inbox fa-4x text-muted opacity-25 mb-3"></i>
                <h5 class="fw-bold text-dark">No Selection</h5>
                <p class="text-muted fs-sm">Navigate the storage hierarchy on the left to locate physical files.</p>
            </div>

            <!-- Loader -->
            <div id="loadingView" class="vc-loading text-center py-5 mt-5 d-none">
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
                <div class="vc-documents-header d-flex align-items-center mb-4 pb-3 border-bottom">
                    <button class="btn btn-sm btn-white border shadow-sm rounded-circle me-3 d-inline-flex align-items-center justify-content-center" style="width: 35px; height: 35px;" onclick="backToFolders()" aria-label="Back to folders">
                        <i class="fas fa-arrow-left text-secondary d-block" aria-hidden="true"></i>
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
        window.systemToast = Swal.mixin({
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
            window.systemToast.fire({ icon: toastType, title: toastMsg });
            
            const url = new URL(window.location);
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url);
        }

        // System Location Tracker
        window.activeDrawerId = null;
        window.activeFolderId = null;
        window.pendingFolderClick = null;
        window.pendingDocHighlight = null;
        window.pendingDocModal = false;

        flatpickr("#plReturnDate", {
            dateFormat: "Y-m-d", 
            altInput: true,
            altInputClass: "form-control custom-date-btn shadow-sm fw-medium fs-sm w-100 py-2 rounded-3", 
            altFormat: "F j, Y", 
            minDate: "today",
            disableMobile: true,
            position: "auto"
            // FIX: Tinanggal ang "appendTo" para kusang pumunta sa <body class="page-virtual-cabinet"> ang calendar.
            // Ito ay mag-aayos sa month navigation clicks!
        });

        const urlParams = new URLSearchParams(window.location.search);
        const drawerParam = urlParams.get('drawer');
        const folderParam = urlParams.get('folder');
        const docParam = urlParams.get('doc');
        const reopenProfile = urlParams.get('reopen') === 'profile';

        window.pendingDocHighlight = docParam;
        window.pendingDocModal = reopenProfile;

        if (drawerParam) {
            window.pendingFolderClick = folderParam;
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
        } else if (docParam && reopenProfile) {
            setTimeout(() => {
                openDocumentProfile(docParam);
                clearProfileRestoreFlag();
                window.pendingDocHighlight = null;
                window.pendingDocModal = false;
            }, 150);
        }
    });

    function clearProfileRestoreFlag() {
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('reopen');
        window.history.replaceState({}, document.title, cleanUrl);
    }

    function formatBreadcrumb(str) {
        return str.replace(/ > /g, ' <i class="fas fa-chevron-right mx-2 small opacity-50"></i> ');
    }

    function getFileIcon(fileName) {
        const ext = fileName.split('.').pop().toLowerCase();
        if (['pdf'].includes(ext)) return '<i class="fas fa-file-pdf text-danger fs-3 opacity-75"></i>';
        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return '<i class="fas fa-file-image text-info fs-3 opacity-75"></i>';
        if (['doc', 'docx'].includes(ext)) return '<i class="fas fa-file-word text-primary fs-3 opacity-75"></i>';
        if (['xls', 'xlsx'].includes(ext)) return '<i class="fas fa-file-excel text-success fs-3 opacity-75"></i>';
        return '<i class="fas fa-file-lines text-secondary fs-3 opacity-75"></i>';
    }

    function getStatusBadge(status) {
        if(status === 'Stored') return '<span class="badge status-badge vc-physical-status stored px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> Stored</span>';
        if(status === 'Borrowed') return '<span class="badge status-badge vc-physical-status borrowed px-3 py-2 rounded-pill"><i class="fas fa-hand-holding me-1"></i> Borrowed</span>';
        return '<span class="badge status-badge vc-physical-status returned px-3 py-2 rounded-pill"><i class="fas fa-undo me-1"></i> Returned</span>';
    }

    function loadDrawerContents(drawerId, element, breadcrumbStr) {
        window.activeDrawerId = drawerId;
        window.activeFolderId = null;
        
        document.getElementById('viewBreadcrumb').innerHTML = formatBreadcrumb(breadcrumbStr);
        
        document.querySelectorAll('.drawer-item').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        if (window.matchMedia('(max-width: 767.98px)').matches) {
            const locationTree = document.getElementById('cabinetLocationTree');
            if (locationTree && locationTree.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(locationTree, { toggle: false }).hide();
            }
        }

        document.getElementById('placeholderView').classList.add('d-none');
        document.getElementById('documentsView').classList.add('d-none');
        document.getElementById('foldersView').classList.add('d-none');
        document.getElementById('loadingView').classList.remove('d-none');

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_folders', drawer_id: drawerId, t: new Date().getTime() },
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
                                ? '<div class="vc-folder-icon bg-warning bg-opacity-10 text-warning rounded-3 d-flex align-items-center justify-content-center border border-warning border-opacity-25" style="width: 40px; height: 40px;"><i class="fas fa-box-archive fs-5"></i></div>'
                                : '<div class="vc-folder-icon bg-primary bg-opacity-10 text-primary rounded-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="fas fa-folder fs-5"></i></div>';
                            
                            html += `
                            <div class="vc-folder-col col-xl-4 col-md-6">
                                <div class="physical-folder-card h-100 d-flex flex-column" onclick="loadDocumentList(${f.id}, '${f.name}')">
                                    <div class="vc-folder-top d-flex justify-content-between align-items-center mb-3">
                                        ${iconObj}
                                        <span class="vc-folder-type badge bg-light text-secondary border px-2 py-1 fs-xs fw-medium rounded-pill">${f.type}</span>
                                    </div>
                                    <h6 class="vc-folder-name fw-bold text-dark mb-1 text-truncate" title="${f.name}">${f.name}</h6>
                                    <div class="vc-folder-meta text-muted fs-xs mb-3">${f.doc_count} total physical files</div>
                                    
                                    <!-- Detailed Folder Stats -->
                                    <div class="vc-folder-stats d-flex gap-2 mt-auto">
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle rounded-pill fw-medium" title="Working Docs"><i class="fas fa-tools me-1"></i> ${f.working_count}</span>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill fw-medium" title="Official Records"><i class="fas fa-certificate me-1"></i> ${f.official_count}</span>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle rounded-pill fw-medium" title="Archived"><i class="fas fa-archive me-1"></i> ${f.archived_count}</span>
                                    </div>
                                    <i class="fas fa-chevron-right vc-row-chevron d-md-none" aria-hidden="true"></i>
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

    function loadDocumentList(folderId, folderName, silentRefresh = false) {
        window.activeFolderId = folderId;

        if (!silentRefresh) {
            document.getElementById('foldersView').classList.add('d-none');
            document.getElementById('documentsView').classList.add('d-none');
            document.getElementById('loadingView').classList.remove('d-none');
        }
        document.getElementById('selectedFolderName').innerText = folderName;

        $.ajax({
            url: 'actions/cabinet_fetcher.php',
            type: 'GET',
            data: { action: 'get_documents', folder_id: folderId, t: new Date().getTime() },
            dataType: 'json',
            success: function(response) {
                if (!silentRefresh) {
                    document.getElementById('loadingView').classList.add('d-none');
                }
                
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
                                lifecycleBadge = '<span class="vc-lifecycle-badge badge bg-secondary text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-archive"></i> Archived</span>';
                            } else if (d.disposition_status === 'Ready for Disposition') {
                                lifecycleBadge = '<span class="vc-lifecycle-badge badge bg-danger text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-trash-alt"></i> For Disposition</span>';
                            } else if (d.record_phase === 'Official') {
                                lifecycleBadge = '<span class="vc-lifecycle-badge badge bg-success text-white ms-2" style="font-size: 0.65rem;"><i class="fas fa-certificate"></i> Official</span>';
                            } else {
                                lifecycleBadge = '<span class="vc-lifecycle-badge badge bg-primary bg-opacity-10 text-primary border border-primary-subtle ms-2" style="font-size: 0.65rem;"><i class="fas fa-tools"></i> Working</span>';
                            }
                            
                            // IDINAGDAG ANG target-doc ID DITO
                            html += `
                            <div id="target-doc-${d.doc_id}" class="document-list-item vc-document-row d-flex justify-content-between align-items-center" onclick="openDocumentProfile(${d.doc_id}); this.classList.remove('highlight-target-file');" style="cursor:pointer;" title="Click to view full Physical Profile">
                                <div class="vc-document-main d-flex align-items-center flex-grow-1 overflow-hidden pe-3">
                                    <div class="vc-document-icon bg-light border rounded-3 d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 46px; height: 46px;">
                                        ${icon.replace('fs-3', 'fs-4 mb-0 me-0')}
                                    </div>
                                    <div class="vc-document-copy overflow-hidden w-100">
                                        <div class="vc-document-title-row d-flex align-items-center mb-1">
                                            <h6 class="fw-bold text-dark fs-sm mb-0 text-truncate me-2">${d.file_name}</h6>
                                            ${lifecycleBadge}
                                        </div>
                                        <div class="vc-document-meta text-muted fs-xs text-truncate"><i class="fas fa-folder text-secondary opacity-75 me-1"></i> ${d.category} <span class="mx-1 opacity-50">•</span> <i class="fas fa-tag text-secondary opacity-75 me-1"></i> ${refText}</div>
                                    </div>
                                </div>
                                <div class="vc-document-status text-end flex-shrink-0">
                                    ${physicalStatusBadge}
                                    <div class="vc-document-updated text-muted fs-xs mt-2 fw-medium">Updated ${d.last_updated_formatted}</div>
                                </div>
                                <i class="fas fa-chevron-right vc-row-chevron d-md-none" aria-hidden="true"></i>
                            </div>`;
                        });
                    }
                    document.getElementById('documentsContainer').innerHTML = html;
                    document.getElementById('documentsView').classList.remove('d-none');

                    // Restore the same document and profile modal after a status update.
                    if (window.pendingDocHighlight) {
                        const pendingDocId = window.pendingDocHighlight;
                        const shouldReopenProfile = window.pendingDocModal;
                        setTimeout(() => {
                            let targetEl = document.getElementById('target-doc-' + pendingDocId);
                            if (targetEl) {
                                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                targetEl.classList.add('highlight-target-file');
                            }

                            if (shouldReopenProfile) {
                                openDocumentProfile(pendingDocId);
                                clearProfileRestoreFlag();
                            }

                            window.pendingDocHighlight = null;
                            window.pendingDocModal = false;
                        }, 300);
                    }

                } else {
                    document.getElementById('documentsContainer').innerHTML = `<div class="alert alert-danger border"><b>Database Error:</b> ${response.message}</div>`;
                    document.getElementById('documentsView').classList.remove('d-none');
                }
            },
            error: function(xhr) {
                if (!silentRefresh) {
                    document.getElementById('loadingView').classList.add('d-none');
                    document.getElementById('documentsContainer').innerHTML = `<div class="alert alert-danger border"><b>Server Error:</b> Backend crashed. Check the Network Tab.</div>`;
                    document.getElementById('documentsView').classList.remove('d-none');
                }
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
                                // Modern Status Badge
                                let badge = d.physical_status === 'Stored' 
                                    ? '<span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill px-2 py-1"><i class="fas fa-check-circle me-1"></i>Stored</span>' 
                                    : '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning-subtle rounded-pill px-2 py-1"><i class="fas fa-hand-holding me-1"></i>Borrowed</span>';
                                
                                // Sleek & Truncated Physical Path (Shows only Drawer and Folder as Pills)
                                let pthHtml = '';
                                if (d.full_physical_path) {
                                    let pArr = d.full_physical_path.split(' > ');
                                    let folderName = pArr.pop() || '';
                                    let drawerName = pArr.pop() || '';
                                    
                                    pthHtml = `
                                    <div class="d-flex align-items-center w-100" title="${d.full_physical_path}">
                                        <i class="fas fa-location-dot text-danger me-2"></i>
                                        <span class="badge bg-f8f9fa text-secondary border px-2 py-1 fw-bold rounded-pill"><i class="fas fa-box me-1 opacity-50"></i>${drawerName}</span>
                                        <i class="fas fa-chevron-right text-muted opacity-50 mx-1" style="font-size:0.6rem;"></i>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1 fw-bold rounded-pill"><i class="fas fa-folder me-1"></i>${folderName}</span>
                                    </div>`;
                                } else {
                                    pthHtml = `<div class="d-flex align-items-center"><i class="fas fa-location-dot text-danger me-2"></i><span class="badge bg-light text-muted border px-2 py-1 fw-medium rounded-pill"><i class="fas fa-circle-info me-1 opacity-50"></i>Not Mapped</span></div>`;
                                }
                                
                                // Redesigned Dropdown Item Layout
                                html += `
                                <li>
                                    <a class="dropdown-item py-3 border-bottom hover-bg-light transition-all" href="#" onclick="openDocumentProfile(${d.doc_id})">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-bold text-dark fs-sm text-truncate pe-3"><i class="fas fa-file-lines text-primary me-2 fs-6"></i>${d.file_name}</span>
                                            <span class="flex-shrink-0">${badge}</span>
                                        </div>
                                        <div class="d-flex align-items-start text-muted fs-xs w-100 overflow-hidden">
                                            ${pthHtml}
                                        </div>
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
                    
                    document.getElementById('profDigitalVersion').innerText = 'v' + parseFloat(d.current_version).toFixed(1);
                    document.getElementById('profPhysicalVersion').innerText = d.physical_status === 'Digital' ? 'N/A' : 'v' + parseFloat(d.physical_version).toFixed(1);
                    
                    let syncEl = document.getElementById('profSyncStatus');
                    syncEl.innerText = d.sync_status;
                    syncEl.className = 'fw-bold py-2 ';
                    if (d.sync_status === 'Replacement Required') syncEl.classList.add('text-danger');
                    else if (d.sync_status === 'Up To Date') syncEl.classList.add('text-success');
                    else if (d.sync_status === 'Borrowed') syncEl.classList.add('text-warning');
                    else syncEl.classList.add('text-muted');

                    let replaceForm = document.getElementById('formReplacePhysical');
                    if (replaceForm) {
                        if (d.sync_status === 'Replacement Required') {
                            replaceForm.classList.remove('d-none');
                            document.getElementById('replacePhysicalDocId').value = d.doc_id;
                        } else {
                            replaceForm.classList.add('d-none');
                        }
                    }

                    if (d.full_physical_path) {
                        let pathArr = d.full_physical_path.split(' > ');
                        // Map specific icons to their hierarchy levels
                        const icons = ['fa-building', 'fa-door-open', 'fa-boxes-stacked', 'fa-box', 'fa-folder'];
                        
                        let formattedPath = '<div class="d-flex flex-wrap align-items-center gap-2">';
                        pathArr.forEach((step, idx) => {
                            let icon = icons[idx] || 'fa-location-dot';
                            let isLast = idx === (pathArr.length - 1);
                            
                            // Highlight the final folder with primary color, others as sleek white pills
                            let badgeClass = isLast ? 'bg-primary text-white shadow-sm border border-primary' : 'bg-f8f9fa text-dark border shadow-sm';
                            let iconClass = isLast ? 'text-white opacity-75' : 'text-primary opacity-75';
                            
                            formattedPath += `<span class="badge ${badgeClass} px-3 py-2 fs-xs fw-bold rounded-pill"><i class="fas ${icon} ${iconClass} me-1"></i> ${step}</span>`;
                            
                            if (!isLast) {
                                formattedPath += `<i class="fas fa-chevron-right text-secondary opacity-50 mx-1" style="font-size: 0.7rem;"></i>`;
                            }
                        });
                        formattedPath += '</div>';
                        document.getElementById('profPath').innerHTML = formattedPath;
                    } else {
                        document.getElementById('profPath').innerHTML = '<div class="text-muted fs-sm py-2"><i class="fas fa-info-circle me-2 text-secondary"></i>This record is not mapped to a physical cabinet.</div>';
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

                    let targetPage = (d.record_phase === 'Official') ? 'documents.php' : 'general_docs.php';
                    let parentParam = d.parent_category ? encodeURIComponent(d.parent_category) : '';
                    let typeParam = d.category ? encodeURIComponent(d.category) : '';
                    document.getElementById('profOpenDigital').href = `${targetPage}?parent=${parentParam}&type=${typeParam}&doc=${d.doc_id}`;

                    let latestB = res.borrow_history.length > 0 ? res.borrow_history[0] : null;
                    document.getElementById('profCheckoutBtn').onclick = function() {
                        
                        // DAGDAG: HARANGIN KUNG HINDI UPDATED ANG PHYSICAL COPY
                        if (d.sync_status === 'Replacement Required') {
                            Swal.fire({
                                title: '<span class="fs-5 fw-bold text-dark letter-spacing-tight mt-2">Action Blocked</span>',
                                html: '<p class="text-muted fs-sm mb-0">The physical copy you are about to lend is <b class="text-danger">OUTDATED</b>.<br><br>Please print and replace it with the latest digital version in the cabinet first.</p>',
                                icon: 'error',
                                width: 400,
                                padding: '1.5rem',
                                confirmButtonText: 'Understood',
                                customClass: {
                                    popup: 'rounded-4 shadow-lg border-0',
                                    confirmButton: 'btn btn-primary btn-sm fw-bold px-4 rounded-pill w-100',
                                },
                                buttonsStyling: false
                            });
                            return; // Pigilan ang pagbukas ng form
                        }
                        
                        // Kung okay naman, ituloy ang checkout form
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
                                <tr class="border-top border-light"><td class="text-muted py-2">Digital Version</td><td class="fw-bold text-primary py-2" id="profDigitalVersion">v1.0</td></tr>
                                <tr><td class="text-muted py-2">Physical Version</td><td class="fw-bold text-secondary py-2" id="profPhysicalVersion">v1.0</td></tr>
                                <tr><td class="text-muted py-2">Sync Status</td><td class="fw-bold py-2" id="profSyncStatus">Up To Date</td></tr>
                            </table>
                            <h6 class="fw-bold text-muted text-uppercase fs-xs letter-spacing-tight mb-3 border-bottom pb-2 border-top pt-4">Physical Storage Path</h6>
                            <div class="p-3 bg-white border border-secondary border-opacity-10 rounded-4 shadow-sm mb-0">
                                <div id="profPath" class="lh-base w-100" style="word-break: break-word;">
                                    <div class="text-muted fs-sm"><i class="fas fa-spinner fa-spin me-2"></i>Fetching location...</div>
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
            <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between flex-wrap gap-2">
                <div class="vc-profile-primary-actions d-flex justify-content-between gap-2 w-100">
                    <button type="button" id="profCheckoutBtn" class="btn btn-outline-primary fw-bold rounded-pill px-4 shadow-sm transition-all">
                        <i class="fas fa-handshake me-2"></i> Manage Check-out
                    </button>
                    <a href="#" id="profOpenDigital" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm transition-all">
                        <i class="fas fa-desktop me-2"></i> View Digital File
                    </a>
                </div>
                <form action="actions/physical_location_handler.php" method="POST" id="formReplacePhysical" class="m-0 d-none">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="replace_physical_copy">
                    <input type="hidden" name="doc_id" id="replacePhysicalDocId">
                    <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                    <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm transition-all" onclick="confirmReplacePhysical()">
                        <i class="fas fa-sync-alt me-2"></i> Replace Physical Copy
                    </button>
                </form>
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
        
        // Fallback URL keeps the same drawer and folder if JavaScript is unavailable.
        const returnState = new URLSearchParams();
        if (window.activeDrawerId) returnState.set('drawer', window.activeDrawerId);
        if (window.activeFolderId) returnState.set('folder', window.activeFolderId);
        const returnQuery = returnState.toString();
        document.getElementById('plReturnUrl').value = '../virtual_cabinet.php' + (returnQuery ? '?' + returnQuery : '');
        
        toggleCheckoutFields();
        new bootstrap.Modal(document.getElementById('physicalLocationModal')).show();
    }

    async function submitPhysicalStatusForm(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonHtml = submitButton.innerHTML;
        const formData = new FormData(form);
        const handlerUrl = new URL(form.getAttribute('action'), window.location.href);

        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving';

        try {
            const response = await fetch(handlerUrl.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                redirect: 'follow',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const responseUrl = new URL(response.url, window.location.href);
            const errorMessage = responseUrl.searchParams.get('error');
            const successMessage = responseUrl.searchParams.get('success');

            if (!response.ok || errorMessage || !successMessage) {
                throw new Error(errorMessage || 'Unable to save the physical status. Please try again.');
            }

            const modalElement = document.getElementById('physicalLocationModal');
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) modalInstance.hide();

            window.systemToast.fire({ icon: 'success', title: successMessage });

            if (window.activeFolderId) {
                const activeFolderName = document.getElementById('selectedFolderName').innerText;
                loadDocumentList(window.activeFolderId, activeFolderName, true);
            }
        } catch (error) {
            window.systemToast.fire({ icon: 'error', title: error.message });
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHtml;
        }
    }

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
<!-- FIX: Tinanggal ang tabindex="-1" para hindi i-block ng Bootstrap ang calendar buhat sa labas -->
<div class="modal fade sleek-modal" id="physicalLocationModal" aria-hidden="true" style="z-index: 1060;">
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
                    <i class="fas fa-file-lines text-primary fs-5 me-2 flex-shrink-0"></i>
                    <span class="fw-bold text-truncate" id="plDocName"></span>
                </div>
                <form action="actions/physical_location_handler.php" method="POST" id="physicalLocationStatusForm" onsubmit="submitPhysicalStatusForm(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_location">
                    <input type="hidden" name="doc_id" id="plDocId">
                    <input type="hidden" name="return_url" id="plReturnUrl" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">

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

                    <div class="vc-checkout-actions d-flex justify-content-end gap-2 pt-2">
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
