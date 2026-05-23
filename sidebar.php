<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'User';
$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id > 0 && isset($conn)) {
    $full_url = $_SERVER['REQUEST_URI'];
    $current_time = time();
    
    if (!isset($_SESSION['last_url']) || $_SESSION['last_url'] !== $full_url || ($current_time - ($_SESSION['last_log_time'] ?? 0)) > 5) {
        
        $action_type = "PAGE_VIEW";
        
        $clean_name = ucwords(str_replace(['.php', '_'], ['', ' '], $current_page));
        $desc = "Navigated to " . $clean_name . " Module";

        switch($current_page) {
            case 'dashboard.php': $desc = "Navigated to Main Dashboard"; break;
            case 'pr_list.php': $desc = "Browsed Purchase Requests Directory"; break;
            case 'create_pr.php': $desc = "Opened Create Purchase Request Form"; break;
            case 'quotations_list.php': $desc = "Browsed Quotations Tracker Directory"; break;
            case 'view_pr.php': 
                $id = $_GET['id'] ?? 'Unknown';
                $desc = "Viewed details of Purchase Request ID: $id"; 
                $action_type = "VIEW_RECORD";
                break;
            case 'po_list.php': $desc = "Browsed Purchase Orders Tracker"; break;
            case 'create_po.php': $desc = "Opened Generate PO Form"; break;
            case 'view_po.php':
                $id = $_GET['id'] ?? 'Unknown';
                $desc = "Viewed details & attachments of Purchase Order ID: $id"; 
                $action_type = "VIEW_RECORD";
                break;
            case 'documents.php': $desc = "Browsed Official Records & Retention List"; break;
            case 'general_docs.php': $desc = "Browsed Company Files & General Storage"; break;
            case 'admin_users.php': $desc = "Accessed User Management Control Panel"; break;
            case 'audit_logs.php': $desc = "Reviewed System Audit Trail"; break;
            case 'settings.php': $desc = "Opened Account Settings"; break;
            case 'notifications.php': $desc = "Viewed All Notifications list"; break;
            case 'admin_requests.php': $desc = "Accessed Account Requests Panel"; break;
        }

        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $desc .= " | Searched keyword: '" . $_GET['search'] . "'";
            $action_type = "SEARCH";
        }
        if (isset($_GET['filter']) && !empty($_GET['filter'])) {
            $desc .= " | Applied status filter: '" . $_GET['filter'] . "'";
        }
        if (isset($_GET['type']) && !empty($_GET['type']) && $_GET['type'] !== 'All') {
            $desc .= " | Filtered by Category: '" . $_GET['type'] . "'";
        }

        if (function_exists('log_audit_action')) {
            log_audit_action($conn, $user_id, $action_type, $desc);
        }
        
        $_SESSION['last_url'] = $full_url;
        $_SESSION['last_log_time'] = $current_time;
    }
}
?>

<script>
(function() {
    try {
        if (localStorage.getItem('fixie_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) {}
    document.body.classList.add('sidebar-preload');
})();
</script>

<nav class="sidebar pb-3" id="appSidebar">
    <button type="button" class="sidebar-edge-toggle" id="sidebarToggleBtn" aria-label="Toggle sidebar" aria-expanded="true">
        <i class="fas fa-angle-left"></i>
    </button>

    <div class="sidebar-brand">
        <div class="sidebar-brand-top">
            <div class="sidebar-brand-info">
                <div class="d-flex align-items-center justify-content-center sidebar-logo-wrap" style="width: 45px; height: 45px; flex-shrink: 0;">
                    <img src="assets/images/fixie_logo.png" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="text-white sidebar-brand-text"> 
                    <h6 class="m-0 fw-bold text-white text-uppercase" style="letter-spacing: 0.5px; line-height: 1.1; font-size: 0.9rem;">
                        Fixie Computer
                    </h6>
                    <span style="font-size: 0.65rem; color: var(--secondary); font-weight: 500; letter-spacing: 0.5px;">VENTURES</span>
                </div>
            </div>
        </div>
        <div class="sidebar-brand-divider"></div>
    </div>

    <div class="sidebar-scroll d-flex flex-column gap-0">
        <small class="sidebar-section-title">Main Overview</small>
        <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i><span class="nav-label">Dashboard</span>
        </a>

        <?php if($role == 'Sales Staff'): ?>
            <small class="sidebar-section-title">Sales & Requests</small>
            <a href="quotations_list.php" class="nav-link <?php echo ($current_page == 'quotations_list.php' || $current_page == 'create_quotation.php') ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i><span class="nav-label">Quotations Tracker</span>
            </a>
            <a href="pr_list.php" class="nav-link <?php echo ($current_page == 'pr_list.php' || $current_page == 'create_pr.php') ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i><span class="nav-label">Purchase Requests</span>
            </a>
        <?php endif; ?>

        <small class="sidebar-section-title">Record Management</small>
        <a href="documents.php" class="nav-link <?php echo $current_page == 'documents.php' ? 'active' : ''; ?>">
            <i class="fas fa-folder-open"></i><span class="nav-label">Official Records</span>
        </a>
        <a href="general_docs.php" class="nav-link <?php echo $current_page == 'general_docs.php' ? 'active' : ''; ?>">
            <i class="fas fa-building"></i><span class="nav-label">Company Files</span>
        </a>

        <?php if(in_array($role, ['Supply Chain'])): ?>
           <a href="po_list.php" class="nav-link <?php echo ($current_page == 'po_list.php' || $current_page == 'view_po.php') ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice"></i><span class="nav-label">Purchase Orders</span>
        </a>
        <?php endif; ?>

        <?php 
        if(in_array($role, ['Procurement', 'GM', 'President', 'Finance'])): 
        ?>
        <small class="sidebar-section-title">Procurement Module</small>
        <a href="pr_list.php" class="nav-link <?php echo ($current_page == 'pr_list.php' || $current_page == 'view_pr.php') ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i><span class="nav-label">Purchase Requests</span>
        </a>
        
        <a href="po_list.php" class="nav-link <?php echo ($current_page == 'po_list.php' || $current_page == 'view_po.php') ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice"></i><span class="nav-label">Purchase Orders</span>
        </a>
        
        <?php if($role == 'Procurement'): ?>
            <a href="create_po.php" class="nav-link <?php echo $current_page == 'create_po.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-square"></i><span class="nav-label">Create PO</span>
            </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if($role == 'Admin'): ?>
            <small class="sidebar-section-title">Administration</small>
            <a href="admin_users.php" class="nav-link <?php echo $current_page == 'admin_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i><span class="nav-label">Users Control</span>
            </a>
            <a href="audit_logs.php" class="nav-link <?php echo $current_page == 'audit_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i><span class="nav-label">System Audit Trail</span>
            </a>
            <a href="admin_requests.php" class="nav-link <?php echo $current_page == 'admin_requests.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i><span class="nav-label">Requests</span>
            </a>
        <?php endif; ?>

        <small class="sidebar-section-title">Account</small>
        <a href="settings.php" class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i><span class="nav-label">Settings</span>
        </a>
    </div> 
    
    <div class="mt-auto">
        <div class="p-2 rounded-1 sidebar-user-panel" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05);">
            <div class="d-flex align-items-center gap-2 mb-2 sidebar-user-row">
                <div class="bg-white text-primary rounded-1 d-flex align-items-center justify-content-center overflow-hidden" style="width: 36px; height: 36px; flex-shrink: 0;">
                    <?php if(!empty($_SESSION['avatar']) && file_exists($_SESSION['avatar'])): ?>
                        <img src="download.php?file=<?php echo basename($_SESSION['avatar']); ?>&type=avatar" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <span class="fw-bold fs-6 text-dark"><?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div style="line-height: 1.2; overflow: hidden;" class="sidebar-user-details">
                    <small class="d-block fw-bold text-white text-truncate" style="max-width: 130px; font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?></small>
                    <small style="color: var(--secondary); font-size: 0.65rem; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Role'); ?></small>
                </div>
            </div>
            <a href="actions/auth.php?logout=true&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-sm w-100 text-white rounded-1 sidebar-logout-btn" style="background: rgba(238, 93, 80, 0.1); border: 1px solid rgba(238, 93, 80, 0.2); font-weight: 600; padding: 0.3rem;">
                <i class="fas fa-sign-out-alt me-1 text-danger"></i><span class="logout-label">Logout</span>
            </a>
        </div>
    </div>
</nav>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var body = document.body;
    var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    var sidebarStateKey = 'fixie_sidebar_collapsed';

    function applySidebarState(isCollapsed) {
        body.classList.toggle('sidebar-collapsed', isCollapsed);
        if (sidebarToggleBtn) {
            var icon = sidebarToggleBtn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-angle-left', !isCollapsed);
                icon.classList.toggle('fa-angle-right', isCollapsed);
            }
            sidebarToggleBtn.setAttribute('aria-expanded', (!isCollapsed).toString());
        }
    }

    var savedSidebarState = localStorage.getItem(sidebarStateKey);
    applySidebarState(savedSidebarState === '1');
    requestAnimationFrame(function() {
        body.classList.remove('sidebar-preload');
    });

    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function() {
            var shouldCollapse = !body.classList.contains('sidebar-collapsed');
            applySidebarState(shouldCollapse);
            localStorage.setItem(sidebarStateKey, shouldCollapse ? '1' : '0');
        });
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
        var label = link.textContent.trim().replace(/\s+/g, ' ');
        if (label && !link.getAttribute('title')) {
            link.setAttribute('title', label);
        }
    });

    var tabEls = document.querySelectorAll('button[data-bs-toggle="pill"], a[data-bs-toggle="tab"], button[data-bs-toggle="tab"]');
    
    tabEls.forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function (event) {
            var tabName = event.target.innerText.trim();
            if(tabName) {
                fetch('api/log_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=PAGE_VIEW&desc=' + encodeURIComponent('Viewed inner tab: ' + tabName)
                }).catch(err => console.error('Tracking Error:', err));
            }
        });
    });
});
</script>