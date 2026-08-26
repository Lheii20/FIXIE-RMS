<?php
require 'config/db_connect.php';
require 'config/functions.php';


// STRICT SECURITY: Redirect if not logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = (int) $_SESSION['user_id'];

// A role owns the shared queue, while this user owns their own inbox state.
ensure_user_notification_states($conn, $user_id, $role);

// Shared role notifications with personal read/pin/delete state.
$stmt = $conn->prepare("SELECT n.*, nus.is_read, nus.is_pinned
    FROM notifications n
    INNER JOIN notification_user_states nus ON nus.notif_id = n.notif_id
    WHERE n.target_role = ? AND nus.user_id = ? AND nus.is_deleted = 0
    ORDER BY nus.is_pinned DESC, n.created_at DESC");
$stmt->bind_param("si", $role, $user_id);
$stmt->execute();
$notifs = $stmt->get_result();

// Count unread
$unread_count = get_unread_notification_count($conn, $user_id, $role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Notifications - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/mobile-settings-admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-settings-admin.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
</head>
<body class="page-notifications">
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content fade-in" id="mainWrapper">
        <div class="container-fluid pt-1 notifications-shell" style="max-width: 1000px;"> 
            
            <div class="notif-header">
                <div>
                    <h5 class="fw-bold text-dark mb-1">Notifications</h5>
                    <p class="text-muted small mb-0"><span id="unreadCounter" class="fw-bold text-primary"><?php echo $unread_count; ?></span> unread alerts</p>
                </div>
                
                <div class="dropdown action-dropdown">
                    <button class="btn-dots bg-white border shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notification actions">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item text-secondary" href="#" onclick="toggleSelectMode(event)"><i class="far fa-check-square text-secondary"></i> Select Notifications</a></li>
                        <?php if($notifs->num_rows > 0): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-secondary" href="#" onclick="markAllAsRead(event)"><i class="fas fa-envelope-open-text text-secondary"></i> Mark all as read</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="filter-tabs">
                <button type="button" class="filter-btn active" id="filter-all" onclick="filterNotifs('all')">All</button>
                <button type="button" class="filter-btn text-secondary" id="filter-unread" onclick="filterNotifs('unread')">Unread</button>
                <button type="button" class="filter-btn text-secondary" id="filter-read" onclick="filterNotifs('read')">Read</button>
            </div>

            <div id="notificationContainer" class="notification-list">
                <?php if($notifs->num_rows > 0): ?>
                    <?php while($row = $notifs->fetch_assoc()): ?>
                        <?php 
                            $is_read = $row['is_read'] == 1;
                            $is_pinned = $row['is_pinned'] == 1;
                            $status_class = $is_read ? 'read' : 'unread';
                            if ($is_pinned) $status_class .= ' pinned';
                            
                            $msg_raw = $row['message'];
                            $msg_display = htmlspecialchars($msg_raw);
                            $target_url = "#";
                            $stored_target_url = trim((string) ($row['target_url'] ?? ''));
                            if (
                                $stored_target_url !== '' &&
                                preg_match(
                                    '/^[a-zA-Z0-9_\/-]+\.php(?:\?[a-zA-Z0-9_=&%.-]+)?$/',
                                    $stored_target_url
                                )
                            ) {
                                $target_url = $stored_target_url;
                            }
                            $icon = 'fa-bell'; 
                            $icon_style = 'icon-default';

                            $msg_lower = strtolower($msg_raw);
                             
                            // ICON DETERMINATION
                            if (strpos($msg_lower, 'collection') !== false) {
                                $icon = 'fa-comments-dollar';
                                $icon_style = strpos($msg_lower, 'overdue') !== false
                                    ? 'icon-danger'
                                    : 'icon-warning';
                            }
                            elseif (strpos($msg_lower, 'approved') !== false) { $icon = 'fa-check-circle'; $icon_style = 'icon-success'; }
                            elseif (strpos($msg_lower, 'rejected') !== false) { $icon = 'fa-times-circle'; $icon_style = 'icon-danger'; }
                            elseif (strpos($msg_lower, 'requesting to') !== false || strpos($msg_lower, 'account request') !== false) { $icon = 'fa-user-shield'; $icon_style = 'icon-warning'; }
                            elseif (strpos($msg_lower, 'alert') !== false) { $icon = 'fa-exclamation-triangle'; $icon_style = 'icon-warning'; }
                            elseif (strpos($msg_lower, 'delivered') !== false || strpos($msg_lower, 'transit') !== false) { $icon = 'fa-truck'; $icon_style = 'icon-info'; }
                            elseif (strpos($msg_lower, 'funded') !== false || strpos($msg_lower, 'payment') !== false) { $icon = 'fa-coins'; $icon_style = 'icon-primary'; }
                            
                            // ==========================================
                            // URL AND DISPLAY RESOLUTION ENGINE
                            // ==========================================
                            if ($target_url === '#') {
                                // 1. Solve the "PO #124" issue dynamically
                                if (preg_match('/PO\s*#(\d+)/i', $msg_raw, $m)) {
                                    $po_id_val = intval($m[1]);
                                    $get_po = $conn->query("SELECT po_number FROM purchase_orders WHERE po_id = $po_id_val");
                                    if ($get_po && $get_po->num_rows > 0) {
                                        $real_po = $get_po->fetch_assoc()['po_number'];
                                        $msg_display = htmlspecialchars(str_replace("PO #" . $po_id_val, $real_po, $msg_raw));
                                    }
                                    $target_url = "view_po.php?id=" . $po_id_val;
                                }
                                // 2. Direct exact PO Number format matches
                                elseif (preg_match('/(PO-202\d-\d{4})/i', $msg_raw, $m)) {
                                    $target_url = "po_list.php?search=" . urlencode($m[1]); 
                                }
                                // 3. Direct PR Number format matches
                                elseif (preg_match('/(PR-202\d-\d{4})/i', $msg_raw, $m)) {
                                    $target_url = "pr_list.php?search=" . urlencode($m[1]);
                                }
                                // 4. Document / Disposition alerts
                                elseif (stripos($msg_raw, 'retention alert') !== false || stripos($msg_raw, 'document') !== false) {
                                    $target_url = "documents.php?disposition=1";
                                }
                                // 5. Account Requests Routing
                                elseif (stripos($msg_raw, 'Account Request:') !== false || stripos($msg_raw, 'requesting to') !== false) {
                                    $target_url = "admin_requests.php";
                                }
                                elseif (stripos($msg_raw, 'Your account request') !== false) {
                                    $target_url = "settings.php";
                                }
                                // 6. Generic Routing
                                elseif (stripos($msg_raw, 'quotation') !== false) {
                                    $target_url = "quotations_list.php";
                                }
                                elseif (stripos($msg_raw, 'purchase request') !== false) {
                                    $target_url = "pr_list.php";
                                }
                                elseif (stripos($msg_raw, 'purchase order') !== false) {
                                    $target_url = "po_list.php";
                                }
                            }
                        ?>
                         
                        <div class="notif-card <?php echo $status_class; ?>" id="notif-<?php echo $row['notif_id']; ?>" onclick="handleNotifClick(<?php echo $row['notif_id']; ?>, <?php echo htmlspecialchars(json_encode($target_url), ENT_QUOTES, 'UTF-8'); ?>, event)">
                            <?php if($is_pinned): ?><i class="fas fa-thumbtack pin-indicator"></i><?php endif; ?>
                            
                            <div class="d-flex align-items-center w-100 notification-card-main">
                                <div class="notif-checkbox-wrap">
                                    <input type="checkbox" class="custom-checkbox notif-check" value="<?php echo $row['notif_id']; ?>" onclick="event.stopPropagation(); updateSelectedCount();">
                                </div>

                                <div class="notif-icon <?php echo $icon_style; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="notif-content">
                                    <p class="notif-message"><?php echo $msg_display; ?></p>
                                    <span class="notif-time"><?php echo date('M d, Y • h:i A', strtotime($row['created_at'])); ?></span>
                                </div>
                                
                                <div class="dropdown action-dropdown" onclick="event.stopPropagation();">
                                    <button class="btn-dots" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window" aria-label="Open notification menu">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li><a class="dropdown-item fw-medium text-dark" href="#" onclick="pinNotif(<?php echo $row['notif_id']; ?>, event)"><i class="fas fa-thumbtack text-warning"></i> <?php echo $is_pinned ? 'Unpin from Top' : 'Pin to Top'; ?></a></li>
                                        <?php if(!$is_read): ?>
                                            <li class="notification-state-action"><a class="dropdown-item btn-read-action" href="#" onclick="markAsRead(<?php echo $row['notif_id']; ?>, event)"><i class="fas fa-envelope-open-text text-primary"></i> Mark as read</a></li>
                                        <?php else: ?>
                                            <li class="notification-state-action"><a class="dropdown-item btn-unread-action" href="#" onclick="markAsUnread(<?php echo $row['notif_id']; ?>, event)"><i class="fas fa-envelope text-primary"></i> Mark as unread</a></li>
                                            <li class="notification-action-divider"><hr class="dropdown-divider"></li>
                                            <li class="notification-delete-action"><a class="dropdown-item text-danger fw-bold" href="#" onclick="deleteNotif(<?php echo $row['notif_id']; ?>, event)"><i class="fas fa-trash-alt text-danger"></i> Delete Notification</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
            
            <div id="emptyState" class="text-center py-5 mt-3 border rounded-3 bg-white shadow-sm fade-in" hidden>
                <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                    <i class="fas fa-bell-slash text-muted opacity-50" style="font-size: 1.5rem;"></i>
                </div>
                <h6 class="fw-bold text-dark mt-2">No Notifications</h6>
                <p class="text-muted small">You're all caught up for this filter.</p>
            </div>
            
        </div>
    </div>

    <div class="glass-bar-container" id="bulkActionBar">
        <div class="glass-bar">
            <div class="d-flex align-items-center gap-2 border-end pe-3">
                <div class="form-check mb-0 d-flex align-items-center">
                    <input class="form-check-input custom-checkbox me-2" type="checkbox" id="selectAllBtn" onclick="toggleSelectAll()">
                    <label class="form-check-label fw-bold text-dark" for="selectAllBtn" style="font-size: 0.85rem; cursor: pointer; padding-top:2px;">Select All</label>
                </div>
                <span class="text-muted ms-2" id="selectedCountText" style="font-size:0.8rem; font-weight: 500;">0 Selected</span>
            </div>
            
            <div class="d-flex gap-1 ps-1">
                <button type="button" class="action-btn text-primary" onclick="executeBulkAction('bulk_read')" title="Mark as Read"><i class="fas fa-envelope-open-text"></i></button>
                <button type="button" class="action-btn text-warning" onclick="executeBulkAction('bulk_pin')" title="Pin/Unpin Selected"><i class="fas fa-thumbtack"></i></button>
                <button type="button" class="action-btn text-danger" onclick="executeBulkAction('bulk_delete')" title="Delete Selected"><i class="fas fa-trash"></i></button>
                <div class="border-start mx-2 my-1"></div>
                <button type="button" class="action-btn btn-close-select" onclick="toggleSelectMode(event)" title="Cancel Selection"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
        let isSelectMode = false;

        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 2300,
            timerProgressBar: true,
            customClass: {
                container: 'notification-toast-container',
                popup: 'small-toast'
            }
        });

        function showNotificationToast(icon, title) {
            Toast.fire({ icon, title });
        }

        function reloadWithNotificationToast(icon, title) {
            sessionStorage.setItem('notificationToast', JSON.stringify({ icon, title }));
            window.location.reload();
        }

        document.addEventListener('DOMContentLoaded', () => {
            filterNotifs(getCurrentFilter());

            const savedToast = sessionStorage.getItem('notificationToast');
            if (savedToast) {
                sessionStorage.removeItem('notificationToast');
                try {
                    const toastData = JSON.parse(savedToast);
                    showNotificationToast(toastData.icon || 'success', toastData.title || 'Action completed');
                } catch (error) {
                    sessionStorage.removeItem('notificationToast');
                }
            }
        });

        function setNotificationReadState(card, notifId, isRead) {
            if (!card) return;

            card.classList.toggle('read', isRead);
            card.classList.toggle('unread', !isRead);

            const dropdownUl = card.querySelector('.dropdown-menu');
            if (!dropdownUl) return;

            dropdownUl.querySelectorAll('.notification-state-action, .notification-action-divider, .notification-delete-action').forEach(item => item.remove());

            if (isRead) {
                dropdownUl.insertAdjacentHTML('beforeend', `
                    <li class="notification-state-action"><a class="dropdown-item btn-unread-action" href="#" onclick="markAsUnread(${notifId}, event)"><i class="fas fa-envelope text-primary"></i> Mark as unread</a></li>
                    <li class="notification-action-divider"><hr class="dropdown-divider"></li>
                    <li class="notification-delete-action"><a class="dropdown-item text-danger fw-bold" href="#" onclick="deleteNotif(${notifId}, event)"><i class="fas fa-trash-alt text-danger"></i> Delete Notification</a></li>
                `);
            } else {
                dropdownUl.insertAdjacentHTML('beforeend', `
                    <li class="notification-state-action"><a class="dropdown-item btn-read-action" href="#" onclick="markAsRead(${notifId}, event)"><i class="fas fa-envelope-open-text text-primary"></i> Mark as read</a></li>
                `);
            }
        }

        function toggleSelectMode(e) {
            if(e) e.preventDefault();
            isSelectMode = !isSelectMode;
            const container = document.getElementById('mainWrapper');
            const actionBar = document.getElementById('bulkActionBar');
            const selectAllBox = document.getElementById('selectAllBtn');
            
            if (isSelectMode) {
                container.classList.add('select-mode');
                actionBar.classList.add('show');
            } else {
                container.classList.remove('select-mode');
                actionBar.classList.remove('show');
                selectAllBox.checked = false;
                document.querySelectorAll('.notif-check').forEach(cb => cb.checked = false);
                updateSelectedCount();
            }
        }

        function toggleSelectAll() {
            const isChecked = document.getElementById('selectAllBtn').checked;
            document.querySelectorAll('.notif-check').forEach(cb => {
                const card = cb.closest('.notif-card');
                if (!card.hidden) {
                    cb.checked = isChecked;
                }
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const visibleChecked = Array.from(document.querySelectorAll('.notif-check')).filter(cb => {
                return cb.checked && !cb.closest('.notif-card').hidden;
            });
            
            const totalVisible = Array.from(document.querySelectorAll('.notif-check')).filter(cb => {
                return !cb.closest('.notif-card').hidden;
            });

            document.getElementById('selectedCountText').innerText = visibleChecked.length + ' Selected';
            
            const selectAllBox = document.getElementById('selectAllBtn');
            if(totalVisible.length > 0 && visibleChecked.length === totalVisible.length) {
                selectAllBox.checked = true;
            } else {
                selectAllBox.checked = false;
            }
        }

        function executeBulkAction(action) {
            const checkedBoxes = document.querySelectorAll('.notif-check:checked');
            if (checkedBoxes.length === 0) {
                Toast.fire({ icon: 'info', title: 'No notifications selected' });
                return;
            }

            if (action === 'bulk_delete') {
                let hasUnread = false;
                checkedBoxes.forEach(cb => {
                    if (cb.closest('.notif-card').classList.contains('unread')) {
                        hasUnread = true;
                    }
                });
                
                if (hasUnread) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Action Denied',
                        text: 'Hindi pwedeng i-delete ang unread notification. Paki-read muna bago i-delete.',
                        customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn btn btn-primary' }
                    });
                    return;
                }

                Swal.fire({
                    text: `Delete ${checkedBoxes.length} selected notifications?`,
                    icon: 'warning', width: '320px', padding: '1rem', showCancelButton: true,
                    confirmButtonColor: '#ef4444', cancelButtonColor: '#e2e8f0', confirmButtonText: 'Delete',
                    cancelButtonText: '<span style="color:#475569">Cancel</span>',
                    customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn', cancelButton: 'sleek-btn' }
                }).then((result) => {
                    if (result.isConfirmed) {
                        let ids = Array.from(checkedBoxes).map(cb => cb.value);
                        sendBulkRequest(action, ids);
                    }
                });
            } else {
                let ids = Array.from(checkedBoxes).map(cb => cb.value);
                sendBulkRequest(action, ids);
            }
        }

        function sendBulkRequest(action, ids) {
            let formData = new FormData();
            formData.append('action', action);
            formData.append('notif_ids', JSON.stringify(ids));
            formData.append('csrf_token', csrfToken);

            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const successMessages = {
                        bulk_read: 'Selected notifications marked read',
                        bulk_pin: 'Selected notifications updated',
                        bulk_delete: 'Selected notifications deleted'
                    };
                    reloadWithNotificationToast('success', successMessages[action] || 'Action completed');
                } else {
                    showNotificationToast('error', data.message || 'Action failed');
                }
            })
            .catch(() => showNotificationToast('error', 'Unable to complete the action'));
        }

        function handleNotifClick(notifId, url, event) {
            if (event.target.closest('.dropdown, .notif-check')) return;

            if (isSelectMode) {
                const cb = document.querySelector(`#notif-${notifId} .notif-check`);
                cb.checked = !cb.checked;
                updateSelectedCount();
                return;
            }

            let formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notif_id', notifId);
            formData.append('csrf_token', csrfToken);

            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .finally(() => {
                if (url !== '#' && url !== '') {
                    window.location.href = url; 
                } else {
                    const card = document.getElementById(`notif-${notifId}`);
                    if (card && card.classList.contains('unread')) {
                        setNotificationReadState(card, notifId, true);
                        updateCounter(-1);
                    }
                }
            });
        }

        function pinNotif(notifId, event) {
            event.preventDefault(); event.stopPropagation();
            const card = document.getElementById(`notif-${notifId}`);
            const wasPinned = card && card.classList.contains('pinned');
            let formData = new FormData();
            formData.append('action', 'pin');
            formData.append('notif_id', notifId);
            formData.append('csrf_token', csrfToken);
            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    reloadWithNotificationToast('success', wasPinned ? 'Removed from pinned' : 'Pinned to top');
                } else {
                    showNotificationToast('error', data.message || 'Unable to update notification');
                }
            })
            .catch(() => showNotificationToast('error', 'Unable to update notification'));
        }

        function deleteNotif(notifId, event) {
            event.preventDefault(); event.stopPropagation(); 
            Swal.fire({
                text: "Delete this notification?",
                icon: 'warning', width: '320px', padding: '1rem', showCancelButton: true,
                confirmButtonColor: '#ef4444', cancelButtonColor: '#e2e8f0', confirmButtonText: 'Delete',
                cancelButtonText: '<span style="color:#475569">Cancel</span>',
                customClass: { popup: 'sleek-popup', confirmButton: 'sleek-btn', cancelButton: 'sleek-btn' }
            }).then((result) => {
                if (result.isConfirmed) {
                    let formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('notif_id', notifId);
                    formData.append('csrf_token', csrfToken);

                    fetch('actions/notif_handler.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const card = document.getElementById(`notif-${notifId}`);
                            if (card.classList.contains('unread')) updateCounter(-1);
                            card.classList.add('fade-out');
                            setTimeout(() => { card.remove(); checkEmptyState(); }, 200);
                            showNotificationToast('success', 'Notification deleted');
                        } else {
                            showNotificationToast('error', data.message || 'Deletion error');
                        }
                    })
                    .catch(() => showNotificationToast('error', 'Unable to delete notification'));
                }
            });
        }

        function markAsRead(notifId, event) {
            event.preventDefault(); event.stopPropagation(); 
            let formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notif_id', notifId);
            formData.append('csrf_token', csrfToken);

            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const card = document.getElementById(`notif-${notifId}`);
                    setNotificationReadState(card, notifId, true);
                    updateCounter(-1);
                    filterNotifs(getCurrentFilter()); 
                    showNotificationToast('success', 'Marked as read');
                } else {
                    showNotificationToast('error', data.message || 'Unable to mark as read');
                }
            })
            .catch(() => showNotificationToast('error', 'Unable to mark as read'));
        }

        function markAsUnread(notifId, event) {
            event.preventDefault(); event.stopPropagation();
            const formData = new FormData();
            formData.append('action', 'mark_unread');
            formData.append('notif_id', notifId);
            formData.append('csrf_token', csrfToken);

            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const card = document.getElementById(`notif-${notifId}`);
                    setNotificationReadState(card, notifId, false);
                    updateCounter(1);
                    filterNotifs(getCurrentFilter());
                    showNotificationToast('success', 'Marked as unread');
                } else {
                    showNotificationToast('error', data.message || 'Unable to mark as unread');
                }
            })
            .catch(() => showNotificationToast('error', 'Unable to mark as unread'));
        }

        function markAllAsRead(e) {
            if(e) e.preventDefault();
            let formData = new FormData();
            formData.append('action', 'mark_all_read');
            formData.append('csrf_token', csrfToken);

            fetch('actions/notif_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    document.querySelectorAll('.notif-card.unread').forEach(card => {
                        setNotificationReadState(card, card.id.split('-')[1], true);
                    });
                    document.getElementById('unreadCounter').innerText = '0';
                    filterNotifs(getCurrentFilter()); 
                    showNotificationToast('success', 'All notifications marked read');
                } else {
                    showNotificationToast('error', data.message || 'Unable to mark all as read');
                }
            })
            .catch(() => showNotificationToast('error', 'Unable to mark all as read'));
        }

        function filterNotifs(filterType) {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active', 'text-white');
                btn.classList.add('text-secondary');
            });
            const activeBtn = document.getElementById('filter-' + filterType);
            if(activeBtn) {
                activeBtn.classList.remove('text-secondary');
                activeBtn.classList.add('active', 'text-white');
            }

            const cards = document.querySelectorAll('.notif-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const shouldShow = filterType === 'all'
                    || (filterType === 'unread' && card.classList.contains('unread'))
                    || (filterType === 'read' && card.classList.contains('read'));

                card.hidden = !shouldShow;
                if (shouldShow) visibleCount++;

                if (!shouldShow) {
                    const cb = card.querySelector('.notif-check');
                    if(cb) cb.checked = false;
                }
            });

            if (isSelectMode) updateSelectedCount();

            const emptyState = document.getElementById('emptyState');
            emptyState.hidden = visibleCount !== 0;
        }

        function getCurrentFilter() {
            if(document.getElementById('filter-unread').classList.contains('active')) return 'unread';
            if(document.getElementById('filter-read').classList.contains('active')) return 'read';
            return 'all';
        }

        function updateCounter(change) {
            const counter = document.getElementById('unreadCounter');
            let current = parseInt(counter.innerText) || 0;
            let newCount = Math.max(0, current + change);
            counter.innerText = newCount;
        }

        function checkEmptyState() {
            const container = document.getElementById('notificationContainer');
            const activeCards = Array.from(container.querySelectorAll('.notif-card')).filter(card => !card.hidden);
            document.getElementById('emptyState').hidden = activeCards.length !== 0;
        }
    </script>
</body>
</html>
