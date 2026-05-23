<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$search = $_GET['search'] ?? '';

$valid_filters = ['all', 'Pending', 'Approved', 'Rejected'];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';

$sql = "SELECT p.*, u.full_name FROM purchase_requests p LEFT JOIN users u ON p.created_by = u.user_id WHERE 1=1";
$params = [];
$types = "";


if (!empty($search)) {
    $sql .= " AND (p.pr_number LIKE ? OR p.client_name LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($filter != 'all') {
    if ($filter == 'Pending') { $sql .= " AND p.status = 'Pending'"; }
    elseif ($filter == 'Approved') { $sql .= " AND p.status IN ('Approved', 'Converted_to_PO')"; }
    elseif ($filter == 'Rejected') { $sql .= " AND p.status = 'Rejected'"; }
}

$sql .= " ORDER BY p.date_created DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Purchase Requests - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Purchase Requests</h2>
                <p class="text-muted mb-0">Manage and track all internal PRs before PO creation.</p>
            </div>
            <?php if($role == 'Sales Staff'): ?>
                <a href="create_pr.php" class="btn btn-primary px-4 py-2 shadow-sm">
                    <i class="fas fa-plus me-2"></i> Create PR
                </a>
            <?php endif; ?>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4 p-3 bg-white">
            <form method="GET" action="pr_list.php" class="row g-2 align-items-center m-0" autocomplete="off">
                <div class="col-md-5 col-lg-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 bg-light" 
                               placeholder="Search PR Number or Client..." 
                               value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-3">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-filter text-muted"></i> Filter By</span>
                        <select name="filter" class="form-select bg-light" autocomplete="off">
                            <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                            <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Pending Approval</option>
                            <option value="Approved" <?php echo ($filter == 'Approved') ? 'selected' : ''; ?>>Approved / Converted</option>
                            <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-3 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm"><i class="fas fa-check me-1"></i> Apply</button>
                    <?php if(!empty($search) || $filter != 'all'): ?>
                        <a href="pr_list.php" class="btn btn-light border shadow-sm text-danger" title="Clear Filters"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive p-3">
                <table id="dataTable" class="table table-hover align-middle mb-0" style="width:100%;">
                    <thead class="bg-light text-uppercase small text-secondary">
                        <tr>
                            <th class="ps-3">PR Number</th>
                            <th>Client / Requestor</th>
                            <th>Amount (₱)</th>
                            <th>Date Created</th>
                            <th>Status & Approver</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold text-primary ps-3">#<?php echo htmlspecialchars($row['pr_number']); ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['client_name']); ?></div>
                                <small class="text-muted">By: <?php echo htmlspecialchars($row['full_name'] ?? 'System Admin'); ?></small>
                            </td>
                            <td class="fw-medium text-nowrap">₱ <?php echo number_format($row['amount'], 2); ?></td>
                            <td class="text-muted small text-nowrap"><?php echo date('M d, Y h:i A', strtotime($row['date_created'])); ?></td>
                            <td>
                                <?php if($row['status'] == 'Pending'): ?>
                                    <span class="badge bg-warning text-dark border border-warning">Pending Approval</span>
                                    <div class="small text-muted mt-1" style="font-size: 0.70rem;"><i class="fas fa-user-tie"></i> Assigned to: GM / President</div>
                                <?php elseif($row['status'] == 'Approved'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success">Approved</span>
                                <?php elseif($row['status'] == 'Rejected'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Rejected</span>
                                <?php elseif($row['status'] == 'Converted_to_PO'): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">Converted to PO</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3 text-nowrap">
                                <a href="view_pr.php?id=<?php echo $row['pr_id']; ?>" class="btn btn-sm btn-info text-white shadow-sm me-1" title="View Full Details">
                                    <i class="fas fa-eye"></i> View
                                </a>

                                <?php if($row['status'] == 'Approved' && $role == 'Procurement'): ?>
                                    <a href="create_po.php?pr_id=<?php echo $row['pr_id']; ?>" class="btn btn-sm btn-primary shadow-sm">
                                        <i class="fas fa-file-invoice"></i> Convert to PO
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#dataTable').DataTable({
                "order": [], 
                "bStateSave": false, 
                "pageLength": 10,
                "dom": '<"table-responsive"t><"d-flex justify-content-between align-items-center mt-3"ip>'
            });
        });
    </script>
</body>
</html>