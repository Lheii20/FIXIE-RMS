<?php 
require 'config/db_connect.php'; 
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];

$filter = $_GET['filter'] ?? 'all'; 
$query = "SELECT * FROM notifications WHERE target_role = '$role' AND is_read != 2";

if ($filter == 'unread') {
    $query .= " AND is_read = 0";
} elseif ($filter == 'read') {
    $query .= " AND is_read = 1";
}

$query .= " ORDER BY created_at DESC LIMIT 50";
$notifications = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Notifications - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .notif-row { position: relative; transition: background 0.2s; }
        .notif-row:hover { background-color: rgba(99, 102, 241, 0.05); }
        .stretched-link::after { position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 1; content: ""; }
        .action-buttons { position: relative; z-index: 10; cursor: pointer; }
        .btn-delete-icon { transition: all 0.2s; }
        .btn-delete-icon:hover { background-color: #dc3545; color: white !important; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Notifications</h2>
                <p class="text-muted mb-0">Manage your alerts and updates.</p>
            </div>
            <form action="actions/notif_handler.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-primary btn-sm bg-white shadow-sm">
                    <i class="fas fa-check-double me-2"></i> Mark All as Read
                </button>
            </form>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pt-3">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter == 'all' ? 'active fw-bold' : 'text-muted'; ?>" href="?filter=all">All (Last 50)</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter == 'unread' ? 'active fw-bold' : 'text-muted'; ?>" href="?filter=unread">Unread</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter == 'read' ? 'active fw-bold' : 'text-muted'; ?>" href="?filter=read">Read</a>
                    </li>
                </ul>
            </div>

            <div class="list-group list-group-flush">
                
                <?php if($notifications->num_rows > 0): ?>
                    <?php while($row = $notifications->fetch_assoc()): 
                        $is_unread = $row['is_read'] == 0;
                        $is_dss = (strpos($row['message'], 'Retention Alert:') === 0 || strpos($row['message'], 'DSS Alert:') === 0);
                        
                        if ($is_unread) {
                            $bg_class = $is_dss ? 'bg-warning bg-opacity-10 border-warning border-start border-4' : 'bg-primary bg-opacity-10';
                        } else {
                            $bg_class = 'bg-white';
                        }
                    ?>
                        <div class="list-group-item p-4 d-flex gap-3 align-items-center notif-row <?php echo $bg_class; ?>">
                            <div class="align-self-start mt-1">
                                <?php if($is_dss && $is_unread): ?>
                                    <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                <?php elseif($is_dss && !$is_unread): ?>
                                    <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center border" style="width: 38px; height: 38px;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                <?php elseif($is_unread): ?>
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center border" style="width: 38px; height: 38px;">
                                        <i class="fas fa-envelope-open"></i>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 fw-bold <?php echo $is_unread ? ($is_dss ? 'text-dark' : 'text-primary') : 'text-secondary'; ?>">
                                        <a href="actions/notif_handler.php?action=view&notif_id=<?php echo $row['notif_id']; ?>" class="text-decoration-none text-inherit stretched-link">
                                            <?php echo $is_dss ? 'Retention Alert' : 'System Alert'; ?>
                                        </a>
                                    </h6>
                                    <?php if($is_dss && $is_unread): ?>
                                        <small class="badge bg-warning text-dark fw-bold">System Generated</small>
                                    <?php else: ?>
                                        <small class="text-muted" style="font-size: 0.8rem;"><i class="far fa-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></small>
                                    <?php endif; ?>
                                </div>
                                <p class="mb-1 <?php echo $is_unread && $is_dss ? 'text-dark fw-medium' : 'text-secondary'; ?>">
                                    <?php echo htmlspecialchars($row['message']); ?>
                                </p>
                                
                                <small class="text-muted fst-italic" style="font-size: 0.75rem;">
                                    <?php echo $is_unread ? 'Click to view & mark as read' : 'Click to review records'; ?>
                                </small>
                            </div>

                            <div class="action-buttons ms-3 ps-3 border-start">
                                <form action="actions/notif_handler.php" method="POST" class="m-0" onsubmit="event.stopPropagation(); return confirm('Delete this notification permanently?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="notif_id" value="<?php echo $row['notif_id']; ?>">
                                    <button type="submit" class="btn btn-light text-danger rounded-circle d-flex align-items-center justify-content-center btn-delete-icon border shadow-sm" style="width: 40px; height: 40px;" title="Delete Notification">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-bell-slash fa-3x text-muted opacity-25 mb-3"></i>
                        <p class="text-muted">No notifications found in this filter.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>