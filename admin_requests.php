<?php 
require 'config/db_connect.php'; 
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Requests - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <h2 class="fw-bold mb-4">Security Requests</h2>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> Action completed. User updated.</div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold text-primary">Pending Approvals</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light small text-secondary">
                        <tr>
                            <th class="ps-4">Date</th>
                            <th>User</th>
                            <th>Request Type</th>
                            <th>Current Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $query = "SELECT r.*, u.full_name, u.role, u.status AS user_status 
                                  FROM user_requests r 
                                  JOIN users u ON r.user_id = u.user_id 
                                  WHERE r.status = 'Pending' 
                                  ORDER BY r.requested_at ASC";
                        $reqs = $conn->query($query);

                        if($reqs->num_rows > 0):
                            while($row = $reqs->fetch_assoc()): 
                        ?>
                        <tr>
                            <td class="ps-4 small text-muted"><?php echo date('M d, H:i', strtotime($row['requested_at'])); ?></td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                <small class="text-muted"><?php echo $row['role']; ?></small>
                            </td>
                            <td>
                                <?php if($row['request_type'] == 'Unlock Account'): ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-unlock me-1"></i> Unlock Account</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><?php echo $row['request_type']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['user_status'] == 'Pending_Approval'): ?>
                                    <span class="badge bg-danger">LOCKED</span>
                                <?php elseif($row['user_status'] == 'Active'): ?>
                                    <span class="badge bg-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $row['user_status']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <form action="actions/request_handler.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="manage_request">
                                    <input type="hidden" name="request_id" value="<?php echo $row['request_id']; ?>">
                                    
                                    <button name="decision" value="Approve" class="btn btn-sm btn-success fw-bold" onclick="return confirm('Approve request?');">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button name="decision" value="Reject" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject request?');">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No pending requests.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>