<?php
// ==========================================
// GLOBAL SESSION TIMEOUT ENFORCER
// ==========================================
if (isset($_SESSION['user_id'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL)");
    
    $timeout_query = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout'");
    $timeout_mins = ($timeout_query && $timeout_query->num_rows > 0) ? intval($timeout_query->fetch_assoc()['setting_value']) : 30;
    
    $timeout_secs = $timeout_mins * 60;
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_secs) {
        session_unset();
        session_destroy();
        
        echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                Swal.fire({
                    icon: "warning",
                    title: "Session Expired",
                    text: "For your security, you have been automatically logged out due to inactivity.",
                    confirmButtonColor: "#0f172a",
                    confirmButtonText: "Return to Login",
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    customClass: {
                        popup: "sleek-swal-popup",
                        title: "sleek-swal-title",
                        confirmButton: "sleek-swal-btn"
                    },
                    backdrop: "rgba(15, 23, 42, 0.85)"
                }).then(() => {
                    window.location.href = "index.php";
                });
            });
        </script>';
        exit();
    }
    $_SESSION['last_activity'] = time(); 
}

$role = $_SESSION['role'];
$unread_count = get_unread_notification_count($conn, (int)$_SESSION['user_id'], $role);

$can_view_audit = false;
if (isset($_SESSION['user_id'])) {
    $can_view_audit = has_permission($conn, $_SESSION['user_id'], 'can_view_audit_logs');
}
?>

<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'User';
$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id > 0 && isset($conn) && !defined('DRMS_AUDIT_REQUEST_CAPTURED')) {
    $full_url = $_SERVER['REQUEST_URI'];
    $current_time = time();
    
    if (!isset($_SESSION['last_url']) || $_SESSION['last_url'] !== $full_url || ($current_time - ($_SESSION['last_log_time'] ?? 0)) > 10) {
        
        $action_type = "";
        $desc = "";

        if ($current_page == 'view_po.php' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $po_q = $conn->query("SELECT po_number FROM purchase_orders WHERE po_id = $id");
            $po_num = ($po_q && $po_q->num_rows > 0) ? $po_q->fetch_assoc()['po_number'] : "#$id";
            $desc = "Viewed details of Purchase Order: $po_num";
            $action_type = "VIEW_PO";
        } elseif ($current_page == 'view_pr.php' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $pr_q = $conn->query("SELECT pr_number FROM purchase_requests WHERE pr_id = $id");
            $pr_num = ($pr_q && $pr_q->num_rows > 0) ? $pr_q->fetch_assoc()['pr_number'] : "#$id";
            $desc = "Viewed details of Purchase Request: $pr_num";
            $action_type = "VIEW_PR";
        }

        if (!empty($action_type) && !empty($desc)) {
            if (function_exists('log_audit_action')) {
                log_audit_action($conn, $user_id, $action_type, $desc);
            } else {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $ins = $conn->prepare("INSERT INTO audit_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
                if($ins) {
                    $ins->bind_param("isss", $user_id, $action_type, $desc, $ip);
                    $ins->execute();
                }
            }
        }
        
        $_SESSION['last_url'] = $full_url;
        $_SESSION['last_log_time'] = $current_time;
    }
}
?>

<nav class="saas-navbar shadow-sm d-print-none">
    <div class="saas-nav-container">
        <!-- Mobile Menu Toggle Button (Hamburger Icon) -->
        <button type="button" id="mobileNavToggle" class="mobile-nav-toggle d-print-none" aria-label="Open navigation" aria-expanded="false" aria-controls="mobileSideNav">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Brand Logo -->
        <a href="dashboard.php" class="saas-brand">
            <img src="assets/images/fixie_logo.png" alt="Fixie Logo">
            <div class="saas-brand-text">
                <h6 class="m-0 fw-bold">FIXIE DRMS</h6>
            </div>
        </a>

        <!-- Search Trigger (Desktop Only) -->
        <div class="saas-search-trigger" onclick="openCommandPalette()" role="button" tabindex="0">
            <i class="fas fa-search"></i>
            <span class="d-none d-sm-inline">Search or jump to...</span>
            <kbd class="d-none d-md-inline-block">Ctrl K</kbd>
        </div>

        <!-- Desktop Navigation Menu -->
        <div class="saas-nav-menu">
            
            <div class="d-none d-lg-flex align-items-center text-muted fw-medium border-end pe-3 me-2 fs-095rem">
                <i class="far fa-calendar-alt me-2 text-primary"></i><?php echo date('M d, Y'); ?>
            </div>

            <a href="dashboard.php" class="saas-nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>

            <?php 
            $ops_roles = ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance', 'Supply Chain'];
            if(in_array($role, $ops_roles)): 
            ?>
            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['pr_list.php', 'create_pr.php', 'view_pr.php', 'po_list.php', 'create_po.php', 'view_po.php', 'quotations_list.php', 'create_quotation.php', 'view_quotation.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> Operations <i class="fas fa-chevron-down ms-1 fs-xs"></i>
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

            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['documents.php', 'general_docs.php', 'virtual_cabinet.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-folder-open"></i> Records <i class="fas fa-chevron-down ms-1 fs-xs"></i>
                </a>
                <div class="saas-dropdown shadow-sm">
                    <a href="documents.php"><i class="fas fa-archive"></i> Official Records</a>
                    <a href="general_docs.php"><i class="fas fa-building"></i> Company Files</a>
                    <a href="virtual_cabinet.php"><i class="fas fa-boxes"></i> Virtual Cabinet</a>
                </div>
            </div>

            <?php if($role == 'Admin' || $can_view_audit): ?>
            <div class="saas-nav-item has-dropdown">
                <a href="#" class="saas-nav-link <?php echo (in_array($current_page, ['admin_users.php', 'admin_requests.php', 'audit_logs.php', 'admin_backup.php', 'admin_settings.php'])) ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt"></i> <?php echo ($role == 'Admin') ? 'Admin' : 'System'; ?> <i class="fas fa-chevron-down ms-1 fs-xs"></i>
                </a>
                <div class="saas-dropdown shadow-sm">
                    <?php if($role == 'Admin'): ?>
                        <a href="admin_users.php"><i class="fas fa-users"></i> User Management</a>
                        <a href="admin_requests.php"><i class="fas fa-key"></i> Access Requests</a>
                    <?php endif; ?>
                    
                    <?php if($role == 'Admin' || $can_view_audit): ?>
                        <a href="audit_logs.php"><i class="fas fa-history"></i> System Audit Trail</a>
                    <?php endif; ?>

                    <?php if($role == 'Admin'): ?>
                        <a href="admin_backup.php"><i class="fas fa-database"></i> Backup & Restore</a>
                        <div class="dropdown-divider my-1"></div>
                        <a href="admin_settings.php"><i class="fas fa-cogs"></i> Global Settings</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notifications Icon with Badge -->
            <a href="notifications.php" class="saas-nav-icon <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm nav-badge-pill">
                        <?php echo $unread_count; ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- User Profile Dropdown -->
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
                <div class="saas-dropdown shadow-sm profile-dropdown-menu">
                    <div class="px-3 py-2 border-bottom mb-1 bg-light rounded-top">
                        <small class="d-block fw-bold text-dark text-truncate"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?></small>
                        <small class="text-muted fs-08rem text-uppercase"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Role'); ?></small>
                    </div>
                    <a href="settings.php"><i class="fas fa-cog"></i> Account Settings</a>
                    <a href="actions/auth.php?logout=true&csrf_token=<?php echo $_SESSION['csrf_token'] ?? ''; ?>" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Navigation Backdrop -->
<div id="mobileNavBackdrop" class="mobile-nav-backdrop d-print-none"></div>

<!-- Mobile Side Navigation (Hidden by default) -->
<aside id="mobileSideNav" class="mobile-side-nav d-print-none" aria-label="Mobile navigation">
    <div class="mobile-side-nav__header">
        <a href="dashboard.php" class="mobile-side-nav__brand">
            <img src="assets/images/fixie_logo.png" alt="Fixie Logo">
            <span>FIXIE DRMS</span>
        </a>
        <button type="button" id="mobileNavClose" class="mobile-side-nav__close" aria-label="Close navigation"><i class="fas fa-times"></i></button>
    </div>
    
    <div class="mobile-side-nav__profile">
        <strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'User'); ?></strong>
        <span><?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?></span>
    </div>
    
    <!-- Mobile Navigation Sections -->
    <div class="mobile-side-nav__section">
        <span class="mobile-side-nav__label">Workspace</span>
        <a href="dashboard.php" class="mobile-side-nav__link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i>Dashboard</a>
        <a href="notifications.php" class="mobile-side-nav__link <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>"><i class="fas fa-bell"></i>Notifications<?php if($unread_count > 0): ?> <span class="badge bg-danger ms-auto">{{$unread_count}}</span><?php endif; ?></a>
    </div>
    
    <?php if(in_array($role, $ops_roles)): ?>
    <div class="mobile-side-nav__section">
        <span class="mobile-side-nav__label">Operations</span>
        <?php if($role == 'Sales Staff'): ?><a href="quotations_list.php" class="mobile-side-nav__link <?php echo (in_array($current_page, ['quotations_list.php', 'create_quotation.php', 'view_quotation.php'])) ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i>Quotations</a><?php endif; ?>
        <?php if(in_array($role, ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'])): ?><a href="pr_list.php" class="mobile-side-nav__link <?php echo (in_array($current_page, ['pr_list.php', 'create_pr.php', 'view_pr.php'])) ? 'active' : ''; ?>"><i class="fas fa-clipboard-list"></i>Purchase Requests</a><?php endif; ?>
        <?php if(in_array($role, ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'])): ?><a href="po_list.php" class="mobile-side-nav__link <?php echo (in_array($current_page, ['po_list.php', 'create_po.php', 'view_po.php'])) ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i>Purchase Orders</a><?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="mobile-side-nav__section">
        <span class="mobile-side-nav__label">Records</span>
        <a href="documents.php" class="mobile-side-nav__link <?php echo ($current_page == 'documents.php') ? 'active' : ''; ?>"><i class="fas fa-archive"></i>Official Records</a>
        <a href="general_docs.php" class="mobile-side-nav__link <?php echo ($current_page == 'general_docs.php') ? 'active' : ''; ?>"><i class="fas fa-building"></i>Company Files</a>
        <a href="virtual_cabinet.php" class="mobile-side-nav__link <?php echo ($current_page == 'virtual_cabinet.php') ? 'active' : ''; ?>"><i class="fas fa-boxes"></i>Virtual Cabinet</a>
    </div>
    
    <?php if($role == 'Admin' || $can_view_audit): ?>
    <div class="mobile-side-nav__section">
        <span class="mobile-side-nav__label">System</span>
        <?php if($role == 'Admin'): ?><a href="admin_users.php" class="mobile-side-nav__link <?php echo ($current_page == 'admin_users.php') ? 'active' : ''; ?>"><i class="fas fa-users"></i>User Management</a><?php endif; ?>
        <?php if($role == 'Admin'): ?><a href="admin_requests.php" class="mobile-side-nav__link <?php echo ($current_page == 'admin_requests.php') ? 'active' : ''; ?>"><i class="fas fa-key"></i>Access Requests</a><?php endif; ?>
        <a href="audit_logs.php" class="mobile-side-nav__link <?php echo ($current_page == 'audit_logs.php') ? 'active' : ''; ?>"><i class="fas fa-history"></i>Audit Trail</a>
        <?php if($role == 'Admin'): ?><a href="admin_backup.php" class="mobile-side-nav__link <?php echo ($current_page == 'admin_backup.php') ? 'active' : ''; ?>"><i class="fas fa-database"></i>Backup</a><?php endif; ?>
        <?php if($role == 'Admin'): ?><a href="admin_settings.php" class="mobile-side-nav__link <?php echo ($current_page == 'admin_settings.php') ? 'active' : ''; ?>"><i class="fas fa-cogs"></i>Settings</a><?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="mobile-side-nav__footer">
        <a href="settings.php" class="mobile-side-nav__link"><i class="fas fa-cog"></i>Account Settings</a>
        <a href="actions/auth.php?logout=true&csrf_token=<?php echo $_SESSION['csrf_token'] ?? ''; ?>" class="mobile-side-nav__link mobile-side-nav__link--danger"><i class="fas fa-sign-out-alt"></i>Logout</a>
    </div>
</aside>

<!-- Command Palette Modal -->
<div id="commandPaletteOverlay" class="cp-overlay init-hidden">
    <div class="cp-modal fade-in">
        <div class="cp-header">
            <i class="fas fa-search cp-icon"></i>
            <input type="text" id="cpInput" placeholder="Type a command or search..." autocomplete="off">
            <kbd class="cp-esc" onclick="closeCommandPalette()">ESC</kbd>
        </div>
        <div class="cp-body">
            <ul id="cpList" class="cp-list">
                
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
                <li data-keywords="virtual cabinet physical records drawers folders boxes location">
                    <a href="virtual_cabinet.php">
                        <div class="cp-item-icon cp-icon-secondary"><i class="fas fa-boxes"></i></div> 
                        <div><div class="cp-item-title">Virtual Cabinet</div><small class="cp-item-desc">Track physical document locations</small></div>
                    </a>
                </li>
                <li data-keywords="settings account password profile">
                    <a href="settings.php">
                        <div class="cp-item-icon cp-icon-secondary"><i class="fas fa-cog"></i></div> 
                        <div><div class="cp-item-title">Settings</div><small class="cp-item-desc">Manage your account</small></div>
                    </a>
                </li>

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

                <?php if(in_array($role, ['Sales Staff', 'Procurement', 'GM', 'President', 'Finance'])): ?>
                    <li data-keywords="purchase requests pr list directory tracker">
                        <a href="pr_list.php">
                            <div class="cp-item-icon cp-icon-info"><i class="fas fa-clipboard-list"></i></div> 
                            <div><div class="cp-item-title">Purchase Requests</div><small class="cp-item-desc">View PR directory</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if(in_array($role, ['Procurement', 'GM', 'President', 'Finance', 'Supply Chain'])): ?>
                    <li data-keywords="purchase orders po list directory tracker">
                        <a href="po_list.php">
                            <div class="cp-item-icon cp-icon-info"><i class="fas fa-file-invoice"></i></div> 
                            <div><div class="cp-item-title">Purchase Orders</div><small class="cp-item-desc">View PO directory</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if($role == 'Procurement'): ?>
                    <li data-keywords="purchase order po create generate new buy">
                        <a href="create_po.php">
                            <div class="cp-item-icon cp-icon-success"><i class="fas fa-plus"></i></div> 
                            <div><div class="cp-item-title">Create PO</div><small class="cp-item-desc">Generate a new Purchase Order</small></div>
                        </a>
                    </li>
                <?php endif; ?>

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
                <?php endif; ?>

                <?php if($role == 'Admin' || $can_view_audit): ?>
                    <li data-keywords="audit logs history actions trail tracking">
                        <a href="audit_logs.php">
                            <div class="cp-item-icon cp-icon-warning"><i class="fas fa-history"></i></div> 
                            <div><div class="cp-item-title">System Audit Trail</div><small class="cp-item-desc">Review system activity</small></div>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if($role == 'Admin'): ?>
                    <li data-keywords="backup restore database sql server">
                        <a href="admin_backup.php">
                            <div class="cp-item-icon cp-icon-danger" style="color: #ef4444; background: rgba(239, 68, 68, 0.1);"><i class="fas fa-database"></i></div> 
                            <div><div class="cp-item-title">Backup & Restore</div><small class="cp-item-desc">Manage system database</small></div>
                        </a>
                    </li>
                    <li data-keywords="global system settings config timeout upload size admin">
                        <a href="admin_settings.php">
                            <div class="cp-item-icon cp-icon-warning"><i class="fas fa-cogs"></i></div> 
                            <div><div class="cp-item-title">Global Settings</div><small class="cp-item-desc">Configure system rules</small></div>
                        </a>
                    </li>
                <?php endif; ?>

            </ul>
            <div id="cpNoResults" class="text-center py-4 text-muted init-hidden">
                <i class="fas fa-search-minus fs-4 mb-2 opacity-50"></i>
                <p class="mb-0 fs-09rem">No matching commands found.</p>
            </div>
        </div>
        <div class="cp-footer">
            <span class="text-muted"><kbd>↑</kbd> <kbd>↓</kbd> navigate</span>
            <span class="text-muted"><kbd>Enter</kbd> select</span>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    requestAnimationFrame(function() { 
        if(document.body.classList.contains('sidebar-preload')){
            document.body.classList.remove('sidebar-preload'); 
        }
    });

    // Mobile Navigation Toggle
    const mobileToggle = document.getElementById('mobileNavToggle');
    const mobileClose = document.getElementById('mobileNavClose');
    const mobileBackdrop = document.getElementById('mobileNavBackdrop');
    
    const closeMobileNav = function () {
        document.body.classList.remove('mobile-nav-open');
        if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false');
    };
    
    const openMobileNav = function () {
        document.body.classList.add('mobile-nav-open');
        if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'true');
    };
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', openMobileNav);
        mobileToggle.addEventListener('touchstart', function(e) {
            e.preventDefault();
            openMobileNav();
        });
    }
    
    if (mobileClose) {
        mobileClose.addEventListener('click', closeMobileNav);
        mobileClose.addEventListener('touchstart', function(e) {
            e.preventDefault();
            closeMobileNav();
        });
    }
    
    if (mobileBackdrop) {
        mobileBackdrop.addEventListener('click', closeMobileNav);
        mobileBackdrop.addEventListener('touchstart', function(e) {
            e.preventDefault();
            closeMobileNav();
        });
    }
    
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.body.classList.contains('mobile-nav-open')) {
            closeMobileNav();
        }
    });
    
    // Close mobile nav when a link is clicked
    const mobileLinks = document.querySelectorAll('.mobile-side-nav__link');
    mobileLinks.forEach(link => {
        link.addEventListener('click', closeMobileNav);
    });
});

// Command Palette Functionality
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

// Real-time force logout checker
setInterval(function() {
    let apiPath = window.location.pathname.includes('/actions/') || window.location.pathname.includes('/api/') ? '../api/check_session.php' : 'api/check_session.php';
    
    fetch(apiPath, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'force_logout') {
            let rootPath = window.location.pathname.includes('/actions/') || window.location.pathname.includes('/api/') ? '../index.php' : 'index.php';
            window.location.href = rootPath + '?error=ForceLoggedOutByAdmin';
        }
    })
    .catch(error => {});
}, 5000); 
</script>