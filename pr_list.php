  <?php 
  require 'config/db_connect.php'; 
  require 'config/functions.php';
  require_once 'config/workflow_access.php';
  require_once __DIR__ . '/config/frontend_assets.php';

  drms_require_workflow_roles([
      'Sales Staff',
      'Procurement',
      'GM',
      'President',
      'Finance',
  ]);

  $search = $_GET['search'] ?? '';
  $valid_filters = ['all', 'Pending', 'Approved', 'Converted_to_PO', 'Rejected'];
  $filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';
  $queue = (isset($_GET['queue']) && $_GET['queue'] === 'mine') ? 'mine' : '';

  $sql = "SELECT * FROM purchase_requests WHERE 1=1";
  $params = [];
  $types = "";

  // Role-specific operational queue used by dashboard handoff cards.
  if ($queue === 'mine') {
      if ($_SESSION['role'] === 'GM') {
          $sql .= " AND status = 'Pending'
                    AND current_approval_stage = 'GM Review'";
      } elseif ($_SESSION['role'] === 'Finance') {
          $sql .= " AND status = 'Pending'
                    AND current_approval_stage = 'Finance Review'";
      } elseif ($_SESSION['role'] === 'President') {
          $sql .= " AND status = 'Pending'
                    AND current_approval_stage = 'Owner Approval'";
      } elseif ($_SESSION['role'] === 'Procurement') {
          $sql .= " AND status = 'Approved'
                    AND current_approval_stage = 'Official Approved'
                    AND final_approved_by IS NOT NULL
                    AND final_approved_at IS NOT NULL";
      }
  }

  // Search Logic
  if (!empty($search)) {
      $sql .= " AND (pr_number LIKE ? OR client_name LIKE ?)";
      $searchParam = "%$search%";
      $params[] = $searchParam;
      $params[] = $searchParam;
      $types .= "ss";
  }

  // Filter Logic
  if ($filter != 'all') {
      $sql .= " AND status = ?";
      $params[] = $filter;
      $types .= "s";
  }

  $sql .= " ORDER BY date_created DESC";
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
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <link href="assets/css/bootstrap.min.css" rel="stylesheet">
      <link href="assets/css/style.css" rel="stylesheet">
      <link rel="stylesheet" href="assets/css/all.min.css">
      <link href="assets/css/compact-mobile-lists.css" rel="stylesheet">
      <link href="assets/css/mobile-drive-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-drive-lists.css'); ?>" rel="stylesheet">
      <?= drms_frontend_style_tags(['datatables-bs5-css']) ?>
      <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
      <link href="assets/css/transaction-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/transaction-lists.css'); ?>" rel="stylesheet">
  </head>
  <body class="page-pr-list workflow-ui">
      <?php include 'sidebar.php'; ?>
      <div class="main-content fade-in">
        
          <!-- Premium Header Area -->
          <div class="page-header">
              <div class="list-title-row d-flex align-items-center justify-content-between gap-2">
                  <div class="list-title-copy">
                      <h3 class="fw-bold mb-0 text-slate-900 tracking-tight">Purchase Requests</h3>
                      <span class="list-title-subtitle text-muted fs-sm d-none d-md-block mt-1">
                          <?php if ($queue === 'mine' && $_SESSION['role'] === 'Procurement'): ?>
                              Officially approved PRFs ready for PO conversion
                          <?php elseif ($queue === 'mine'): ?>
                              Purchase Requests currently assigned to your approval stage
                          <?php else: ?>
                              Review and manage all requested procurements
                          <?php endif; ?>
                      </span>
                  </div>
                  <?php if($_SESSION['role'] == 'Sales Staff'): ?>
                      <a href="quotations_list.php" class="mobile-list-create-action d-inline-flex d-md-none align-items-center justify-content-center" title="Create Purchase Request" aria-label="Create Purchase Request">
                          <svg class="mobile-list-create-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                              <path d="M12 5v14M5 12h14"></path>
                          </svg>
                          <span class="visually-hidden">Create Purchase Request</span>
                      </a>
                  <?php endif; ?>
              </div>

              <form method="GET" action="pr_list.php" class="sleek-filter-bar m-0">
                  <?php if ($queue === 'mine'): ?>
                      <input type="hidden" name="queue" value="mine">
                  <?php endif; ?>
                  <div class="sleek-search-group">
                      <i class="fas fa-search"></i>
                      <input type="text" name="search" class="sleek-search-input" placeholder="Search PR or Client..." value="<?php echo htmlspecialchars($search); ?>">
                  </div>
                
                  <select name="filter" class="sleek-select" onchange="this.form.submit()">
                      <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                      <option value="Pending" <?php echo ($filter == 'Pending') ? 'selected' : ''; ?>>Pending Review</option>
                      <option value="Approved" <?php echo ($filter == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                      <option value="Converted_to_PO" <?php echo ($filter == 'Converted_to_PO') ? 'selected' : ''; ?>>Converted to PO</option>
                      <option value="Rejected" <?php echo ($filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                  </select>

                  <?php if(!empty($search) || $filter != 'all' || $queue === 'mine'): ?>
                      <a href="pr_list.php" class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
                  <?php endif; ?>

                  <?php if($_SESSION['role'] == 'Sales Staff'): ?>
                      <a href="quotations_list.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center" title="Create Purchase Request" aria-label="Create Purchase Request">
                          <i class="fas fa-plus me-2"></i> Submit Request
                      </a>
                  <?php endif; ?>
              </form>
          </div>

          <!-- Data Grid Layout -->
          <div class="grid-card">
            
              <!-- Sleek Skeleton -->
              <div id="grid-skeleton" class="skeleton-wrapper">
                  <?php for($i=0; $i<6; $i++): ?>
                  <div class="skeleton-row border-bottom border-light pb-3">
                      <div class="skeleton-cell skeleton-box"></div>
                      <div class="flex-1">
                          <div class="skeleton-cell w-50 mb-2"></div>
                          <div class="skeleton-cell w-25 h-10px"></div>
                      </div>
                      <div class="skeleton-cell w-15-pct"></div>
                      <div class="skeleton-cell w-15-pct"></div>
                      <div class="skeleton-cell w-15-pct"></div>
                      <div class="skeleton-cell ms-auto w-8-pct"></div>
                  </div>
                  <?php endfor; ?>
              </div>

              <!-- Table Container -->
              <div id="grid-content" class="init-hidden">
                  <div class="table-responsive-custom">
                      <table id="dataTable" class="table premium-table">
                          <thead>
                              <tr>
                                  <th class="w-32-pct">Request Details</th>
                                  <th class="w-18-pct">Estimated Value</th>
                                  <th class="w-18-pct">Status</th>
                                  <th class="w-20-pct">Date Encoded</th>
                                  <th class="w-12-pct text-end pe-4">Actions</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if($result->num_rows > 0): ?>
                                  <?php while($row = $result->fetch_assoc()): 
                                      // PR Badge logic
                                      $s = $row['status'];
                                      $official_stages = [
                                          'GM Review',
                                          'Finance Review',
                                          'Owner Approval',
                                          'Official Approved',
                                      ];
                                      $is_official_pr = in_array(
                                          (string) ($row['current_approval_stage'] ?? ''),
                                          $official_stages,
                                          true
                                      );
                                      $display_status = $is_official_pr && $s === 'Pending'
                                          ? ($row['current_approval_stage'] ?? 'Pending Review')
                                          : str_replace('_', ' ', $s);
                                      $stage_role_map = [
                                          'GM Review' => 'GM',
                                          'Finance Review' => 'Finance',
                                          'Owner Approval' => 'President',
                                      ];
                                      $is_current_reviewer = $is_official_pr &&
                                          $s === 'Pending' &&
                                          isset($stage_role_map[$row['current_approval_stage']]) &&
                                          $_SESSION['role'] === $stage_role_map[$row['current_approval_stage']];
                                      $badge = 'bg-soft-warning';
                                      $icon = 'fa-clock';
                                    
                                      if($s == 'Approved') { $badge = 'bg-soft-success'; $icon = 'fa-check-circle'; }
                                      elseif($s == 'Converted_to_PO') { $badge = 'bg-soft-primary'; $icon = 'fa-file-invoice'; }
                                      elseif($s == 'Rejected') { $badge = 'bg-soft-danger'; $icon = 'fa-times-circle'; }
                                  ?>
                                  <tr>
                                      <td class="ps-4" data-label="Request Details">
                                          <div class="order-info-block">
                                              <div class="doc-icon-box">
                                                  <i class="fas fa-file-signature"></i>
                                              </div>
                                              <div class="doc-details">
      <span class="doc-title"><?php echo htmlspecialchars($row['pr_number']); ?></span>
      <span class="mobile-list-subline">
          <span class="data-label"><?php echo htmlspecialchars($row['client_name']); ?></span>
          <span class="mobile-list-status <?php echo $badge; ?>">
              <?php echo htmlspecialchars($display_status); ?>
          </span>
      </span>
  </div>
                                          </div>
                                      </td>
                                      <td class="currency-data" data-label="Estimated Value">
                                          ₱<?php echo number_format($row['amount'], 2); ?>
                                      </td>
                                      <td data-label="Status">
                                          <div class="badge-soft <?php echo $badge; ?>">
                                              <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($display_status); ?>
                                          </div>
                                      </td>
                                      <td data-label="Date Encoded">
                                          <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['date_created'])); ?></span>
                                          <span class="data-label"><?php echo date('h:i A', strtotime($row['date_created'])); ?></span>
                                      </td>
                                      <td class="text-end pe-4" data-label="Actions">
                                          <div class="action-flex">
                                              <a
                                                  href="view_pr.php?id=<?php echo $row['pr_id']; ?>"
                                                  class="btn-view-icon"
                                                  title="<?php echo $is_current_reviewer ? 'Review current approval stage' : 'View details'; ?>"
                                                  aria-label="<?php echo $is_current_reviewer ? 'Review current approval stage' : 'View purchase request details'; ?>"
                                              >
                                                  <i class="fas <?php echo $is_current_reviewer ? 'fa-clipboard-check' : 'fa-arrow-right'; ?>"></i>
                                              </a>
                                          </div>
                                      </td>
                                  </tr>
                                  <?php endwhile; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          </div>
      </div>

      <?= drms_frontend_script_tags(['jquery', 'bootstrap', 'datatables', 'datatables-bs5']) ?>
    
      <script>
          $(document).ready(function() {
              var table = $('#dataTable').DataTable({
                  "order": [], 
                  "bStateSave": false, 
                  "pageLength": 15,
                  "language": {
                      "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                      "infoEmpty": "No entries found",
                      "paginate": {
                          "previous": "<i class='fas fa-angle-left'></i>",
                          "next": "<i class='fas fa-angle-right'></i>"
                      }
                  },
                  "dom": '<"tx-table-scroll"t><"tx-pagination-bar d-flex justify-content-between align-items-center border-top"ip>',
                  "initComplete": function() {
                      setTimeout(() => {
                          $('#grid-skeleton').hide();
                          $('#grid-content').removeClass('init-hidden').addClass('is-ready');
                      }, 200); 
                  }
              });
          });

      </script>
  </body>
  </html>
