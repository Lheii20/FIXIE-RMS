<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'User';
$user_id = $_SESSION['user_id'] ?? 0;

// ==========================================
// SYSTEM AUDIT TRAIL & PAGE TRACKING LOGIC
// ==========================================
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

<!-- ==========================================
     SAAS TOP NAVBAR 
     ========================================== -->
<nav class="saas-navbar shadow-sm d-print-none">
    <div class="saas-nav-container">
        
        <!-- Left: Brand -->
        <a href="dashboard.php" class="saas-brand">
            <img src="assets/images/fixie_logo.png" alt="Fixie Logo">
            <div class="saas-brand-text d-none d-md-block">
                <h6 class="m-0 fw-bold">FIXIE COMPUTER</h6>
                <span>VENTURES</span>
            </div>
        </a>

        <!-- Center: Command Palette Trigger -->
        <div class="saas-search-trigger" onclick="openCommandPalette()">
            <i class="fas fa-search"></i>
            <span class="d-none d-sm-inline">Search or jump to...</span>
            <span class="d-inline d-sm-none">Search...</span>
            <kbd class="d-none d-md-inline-block">Ctrl K</kbd>
        </div>

        <!-- Right: Modules & Profile -->
        <div class="saas-nav-menu">
            
            <!-- Date Display (Inilipat dito sa Navbar mula sa Dashboard) -->
            <div class="d-none d-lg-flex align-items-center text-muted fw-medium border-end pe-3 me-2" style="font-size: 0.8rem;">
                <i class="far fa-calendar-alt me-2 text-primary"></i><?php echo date('M d, Y'); ?>
            </div>

            <a href="dashboard.php" class="saas-nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie me-1 d-none d-lg-inline"></i> Dashboard
            </a>

            <!-- Dropdown: Operations (Only visible to specific operational roles) -->
            <?php 
            $ops_roles = ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance', 'Supply Chain'];
            if(in_array($role, $ops_roles)): 
            ?>
            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['pr_list.php', 'create_pr.php', 'view_pr.php', 'po_list.php', 'create_po.php', 'view_po.php', 'quotations_list.php', 'create_quotation.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group me-1 d-none d-lg-inline"></i> Operations <i class="fas fa-chevron-down ms-1" style="font-size:0.6rem;"></i>
                </a>
                <div class="saas-dropdown shadow-sm">
                    <?php if($role == 'Sales Staff'): ?>
                        <a href="quotations_list.php"><i class="fas fa-file-invoice-dollar"></i> Quotations Tracker</a>
                    <?php endif; ?>
                    
                    <?php if(in_array($role, ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'])): ?>
                        <a href="pr_list.php"><i class="fas fa-clipboard-list"></i> Purchase Requests</a>
                    <?php endif; ?>
                    
                    <?php if(in_array($role, ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'])): ?>
                        <a href="po_list.php"><i class="fas fa-file-invoice"></i> Purchase Orders</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Dropdown: Records -->
            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['documents.php', 'general_docs.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-folder-open me-1 d-none d-lg-inline"></i> Records <i class="fas fa-chevron-down ms-1" style="font-size:0.6rem;"></i>
                </a>
                <div class="saas-dropdown shadow-sm">
                    <a href="documents.php"><i class="fas fa-archive"></i> Official Records</a>
                    <a href="general_docs.php"><i class="fas fa-building"></i> Company Files</a>
                </div>
            </div>

            <!-- Dropdown: Admin -->
            <?php if($role == 'Admin'): ?>
            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['admin_users.php', 'admin_requests.php', 'audit_logs.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt me-1 d-none d-lg-inline"></i> Admin <i class="fas fa-chevron-down ms-1" style="font-size:0.6rem;"></i>
                </a>
                <div class="saas-dropdown shadow-sm">
                    <a href="admin_users.php"><i class="fas fa-users"></i> User Management</a>
                    <a href="admin_requests.php"><i class="fas fa-key"></i> Access Requests</a>
                    <a href="audit_logs.php"><i class="fas fa-history"></i> System Audit Trail</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notification -->
            <a href="notifications.php" class="saas-nav-icon <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>" title="Notifications">
                <i class="fas fa-bell"></i>
            </a>

            <!-- Profile Dropdown -->
            <div class="saas-nav-item has-dropdown">
                <div class="saas-profile-trigger">
                    <?php if(!empty($_SESSION['avatar']) && file_exists($_SESSION['avatar'])): ?>
                        <img src="download.php?file=<?php echo basename($_SESSION['avatar']); ?>&type=avatar" alt="Profile">
                    <?php else: ?>
                        <div class="saas-avatar-placeholder text-primary fw-bold">
                            <?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="saas-dropdown shadow-sm" style="right: 0; left: auto; min-width: 200px;">
                    <div class="px-3 py-2 border-bottom mb-1 bg-light rounded-top">
                        <small class="d-block fw-bold text-dark text-truncate"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?></small>
                        <small class="text-muted" style="font-size: 0.7rem; text-transform: uppercase;"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Role'); ?></small>
                    </div>
                    <a href="settings.php"><i class="fas fa-cog"></i> Account Settings</a>
                    <a href="actions/auth.php?logout=true&csrf_token=<?php echo $_SESSION['csrf_token'] ?? ''; ?>" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ==========================================
     COMMAND PALETTE OVERLAY 
     ========================================== -->
<div id="commandPaletteOverlay" class="cp-overlay" style="display: none;">
    <div class="cp-modal fade-in">
        <div class="cp-header">
            <i class="fas fa-search cp-icon"></i>
            <input type="text" id="cpInput" placeholder="Type a command or search..." autocomplete="off">
            <kbd class="cp-esc" onclick="closeCommandPalette()">ESC</kbd>
        </div>
        <div class="cp-body">
            <ul id="cpList" class="cp-list">
                
                <!-- Universal Links (For Everyone) -->
                <li data-keywords="dashboard home main index stats analytics">
                    <a href="dashboard.php">
                        <div class="cp-item-icon cp-icon-primary"><i class="fas fa-chart-pie"></i></div> 
                        <div><div class="cp-item-title">Dashboard</div><small class="cp-item-desc">Go to main overview</small></div>
                    </a>
                </li>
                <li data-keywords="files documents records official retention">
                    <a href="documents.php">
                        <div class="cp-item-icon cp-icon-secondary"><i class="fas fa-archive"></i></div> 
                        <div><div class="cp-item-title">Official Records</div><small class="cp-item-desc">Browse company documents</small></div>
                    </a>
                </li>
                <li data-keywords="company files general storage">
                    <a href="general_docs.php">
                        <div class="cp-item-icon cp-icon-secondary"><i class="fas fa-folder"></i></div> 
                        <div><div class="cp-item-title">Company Files</div><small class="cp-item-desc">Access general files</small></div>
                    </a>
                </li>
                <li data-keywords="settings account password profile">
                    <a href="settings.php">
                        <div class="cp-item-icon cp-icon-secondary"><i class="fas fa-cog"></i></div> 
                        <div><div class="cp-item-title">Settings</div><small class="cp-item-desc">Manage your account</small></div>
                    </a>
                </li>

                <!-- Sales Staff Specific -->
                <?php if($role == 'Sales Staff'): ?>
                    <li data-keywords="quotation quotes create generate new price">
                        <a href="create_quotation.php">
                            <div class="cp-item-icon cp-icon-success"><i class="fas fa-plus"></i></div> 
                            <div><div class="cp-item-title">Create Quotation</div><small class="cp-item-desc">Generate a new quote</small></div>
                        </a>
                    </li>
                    <li data-keywords="quotations list quotes tracker">
                        <a href="quotations_list.php">
                            <div class="cp-item-icon cp-icon-info"><i class="fas fa-list"></i></div> 
                            <div><div class="cp-item-title">Quotations Directory</div><small class="cp-item-desc">View all quotes</small></div>
                        </a>
                    </li>
                    <li data-keywords="purchase request create new pr">
                        <a href="create_pr.php">
                            <div class="cp-item-icon cp-icon-success"><i class="fas fa-plus"></i></div> 
                            <div><div class="cp-item-title">Create PR</div><small class="cp-item-desc">Request for purchase</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- PR Visibility -->
                <?php if(in_array($role, ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'])): ?>
                    <li data-keywords="purchase requests pr list directory tracker">
                        <a href="pr_list.php">
                            <div class="cp-item-icon cp-icon-info"><i class="fas fa-clipboard-list"></i></div> 
                            <div><div class="cp-item-title">Purchase Requests</div><small class="cp-item-desc">View PR directory</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- PO Visibility -->
                <?php if(in_array($role, ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'])): ?>
                    <li data-keywords="purchase orders po list directory tracker">
                        <a href="po_list.php">
                            <div class="cp-item-icon cp-icon-info"><i class="fas fa-file-invoice"></i></div> 
                            <div><div class="cp-item-title">Purchase Orders</div><small class="cp-item-desc">View PO directory</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Procurement Specific -->
                <?php if($role == 'Procurement'): ?>
                    <li data-keywords="purchase order po create generate new buy">
                        <a href="create_po.php">
                            <div class="cp-item-icon cp-icon-success"><i class="fas fa-plus"></i></div> 
                            <div><div class="cp-item-title">Create PO</div><small class="cp-item-desc">Generate a new Purchase Order</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Admin Specific -->
                <?php if($role == 'Admin'): ?>
                    <li data-keywords="users manage accounts admin roles">
                        <a href="admin_users.php">
                            <div class="cp-item-icon cp-icon-warning"><i class="fas fa-users"></i></div> 
                            <div><div class="cp-item-title">User Management</div><small class="cp-item-desc">Control user accounts</small></div>
                        </a>
                    </li>
                    <li data-keywords="security requests unlock account access">
                        <a href="admin_requests.php">
                            <div class="cp-item-icon cp-icon-warning"><i class="fas fa-key"></i></div> 
                            <div><div class="cp-item-title">Security Requests</div><small class="cp-item-desc">Manage access requests</small></div>
                        </a>
                    </li>
                    <li data-keywords="audit logs history actions trail tracking">
                        <a href="audit_logs.php">
                            <div class="cp-item-icon cp-icon-warning"><i class="fas fa-history"></i></div> 
                            <div><div class="cp-item-title">System Audit Trail</div><small class="cp-item-desc">Review system activity</small></div>
                        </a>
                    </li>
                <?php endif; ?>

            </ul>
            <div id="cpNoResults" class="text-center py-4 text-muted" style="display: none;">
                <i class="fas fa-search-minus fs-4 mb-2 opacity-50"></i>
                <p class="mb-0" style="font-size: 0.8rem;">No matching commands found.</p>
            </div>
        </div>
        <div class="cp-footer">
            <span class="text-muted"><kbd>↑</kbd> <kbd>↓</kbd> navigate</span>
            <span class="text-muted"><kbd>Enter</kbd> select</span>
        </div>
    </div>
</div>

<script>
// ==========================================
// INTERNAL TAB LOGGING
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
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
    
    requestAnimationFrame(function() { 
        if(document.body.classList.contains('sidebar-preload')){
            document.body.classList.remove('sidebar-preload'); 
        }
    });
});

// ==========================================
// COMMAND PALETTE JAVASCRIPT
// ==========================================
const cpOverlay = document.getElementById('commandPaletteOverlay');
const cpInput = document.getElementById('cpInput');
const cpList = document.getElementById('cpList');
const cpItems = cpList.querySelectorAll('li');
const cpNoResults = document.getElementById('cpNoResults');
let currentFocus = -1;

function openCommandPalette() {
    cpOverlay.style.display = 'flex';
    cpInput.value = '';
    filterItems('');
    setTimeout(() => cpInput.focus(), 50);
}

function closeCommandPalette() {
    cpOverlay.style.display = 'none';
}

function filterItems(query) {
    let q = query.toLowerCase();
    let hasVisible = false;
    currentFocus = -1;
    removeActive();

    cpItems.forEach(item => {
        let text = item.innerText.toLowerCase();
        let keywords = item.getAttribute('data-keywords') ? item.getAttribute('data-keywords').toLowerCase() : '';
        
        if (text.includes(q) || keywords.includes(q)) {
            item.style.display = 'block';
            hasVisible = true;
        } else {
            item.style.display = 'none';
        }
    });

    cpNoResults.style.display = hasVisible ? 'none' : 'block';
}

function addActive(itemsArray) {
    if (!itemsArray || itemsArray.length === 0) return false;
    removeActive();
    if (currentFocus >= itemsArray.length) currentFocus = 0;
    if (currentFocus < 0) currentFocus = (itemsArray.length - 1);
    itemsArray[currentFocus].classList.add('cp-active');
    itemsArray[currentFocus].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function removeActive() {
    cpItems.forEach(item => item.classList.remove('cp-active'));
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        if (cpOverlay.style.display === 'none' || cpOverlay.style.display === '') {
            openCommandPalette();
        } else {
            closeCommandPalette();
        }
    }
    if (e.key === 'Escape' && cpOverlay.style.display === 'flex') {
        closeCommandPalette();
    }
});

cpInput.addEventListener('input', function(e) {
    filterItems(this.value);
});

cpInput.addEventListener('keydown', function(e) {
    let visibleItems = Array.from(cpItems).filter(item => item.style.display !== 'none');
    
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        currentFocus++;
        addActive(visibleItems);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        currentFocus--;
        addActive(visibleItems);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (currentFocus > -1) {
            if (visibleItems[currentFocus]) {
                visibleItems[currentFocus].querySelector('a').click();
            }
        } else if (visibleItems.length > 0) {
            visibleItems[0].querySelector('a').click(); 
        }
    }
});

cpOverlay.addEventListener('click', function(e) {
    if (e.target === cpOverlay) {
        closeCommandPalette();
    }
});
</script>