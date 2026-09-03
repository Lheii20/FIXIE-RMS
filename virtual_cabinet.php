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
$current_user_id = (int) $_SESSION['user_id'];
$records_allowed = $role !== 'Admin' ? 1 : 0;
$is_top_mgmt = $records_allowed === 1 && has_permission($conn, $current_user_id, 'can_view_all_folders') ? 1 : 0;
$can_manage_physical = $records_allowed === 1 && (
    has_permission($conn, $current_user_id, 'can_manage_folders') || $is_top_mgmt === 1
);
$shared_user_pattern = '%"user_' . $current_user_id . '"%';
if ($can_manage_physical && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$unread_count = get_unread_notification_count($conn, (int)$_SESSION['user_id'], $role);

$can_view_audit = false;
if (isset($_SESSION['user_id'])) {
    $can_view_audit = has_permission($conn, $_SESSION['user_id'], 'can_view_audit_logs');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Virtual Cabinet - Fixie DRMS</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<link href="assets/css/all.min.css" rel="stylesheet">
<link href="assets/css/storage-location-manager.css?v=vc2-1" rel="stylesheet">
<link href="assets/css/physical-records.css?v=vc4d-1" rel="stylesheet">
</head>
<body class="bg-f8f9fa page-virtual-cabinet cabinet-redesign">
<?php include 'sidebar.php'; ?>
<!-- Scoped final layer; the shared application shell still owns the 1550px width and gutters. -->
<link href="assets/css/virtual-cabinet.css?v=<?= filemtime(__DIR__.'/assets/css/virtual-cabinet.css') ?>" rel="stylesheet">
<main class="main-content">
  <header class="vc5-header">
    <div class="vc5-header-main">
      <div class="vc5-heading"><h1>Virtual Cabinet</h1><p>Find a physical copy, confirm its location, and track who has it.</p></div>
      <?php if ($can_manage_physical): ?><button type="button" class="btn btn-primary vc5-manage" data-bs-toggle="modal" data-bs-target="#storageLocationManager"><i class="fas fa-sliders-h" aria-hidden="true"></i><span>Manage locations</span></button><?php endif; ?>
    </div>
    <div class="vc5-metrics" aria-label="Storage and record totals">
      <div class="vc5-metric"><i class="fas fa-archive" aria-hidden="true"></i><span>Cabinets</span><strong data-location-stat="cabinet">—</strong></div>
      <div class="vc5-metric"><i class="fas fa-layer-group" aria-hidden="true"></i><span>Drawers</span><strong data-location-stat="drawer">—</strong></div>
      <div class="vc5-metric"><i class="fas fa-folder" aria-hidden="true"></i><span>Folders</span><strong data-location-stat="folder">—</strong></div>
      <div class="vc5-metric"><i class="fas fa-file-alt" aria-hidden="true"></i><span>Working</span><strong data-copy-stat="working">—</strong></div>
      <div class="vc5-metric"><i class="fas fa-certificate" aria-hidden="true"></i><span>Official</span><strong data-copy-stat="official">—</strong></div>
      <div class="vc5-metric vc5-metric-custody"><i class="fas fa-hand-holding" aria-hidden="true"></i><span>Borrowed</span><strong data-copy-stat="borrowed">—</strong></div>
    </div>
  </header>

  <div class="vc3-workspace" id="vc3Workspace" data-redesign="vc5c">
    <aside class="vc3-tree" aria-label="Physical storage hierarchy">
      <div class="vc5-tree-heading"><h2>Storage locations</h2><p>Select a folder to view its copies.</p>
        <div class="vc5-location-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="vc3LocationSearch" aria-label="Find a storage location" placeholder="Find a location…" maxlength="150" autocomplete="off"></div>
      </div>
      <div id="vc3Tree" class="vc5-tree-scroll">Loading storage locations…</div>
    </aside>
    <section class="vc3-panel" aria-labelledby="vc3Title">
      <div class="vc3-toolbar">
        <div class="vc5-panel-heading"><h2 id="vc3Title">All physical copies</h2><p id="vc3Path">Across all storage locations</p></div>
        <div class="vc5-toolbar-actions">
          <?php if ($records_allowed): ?><form id="vc3ExportForm" class="vc3-export" action="actions/export_physical_inventory.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="scope" id="vc3ExportScope" value="all"><input type="hidden" name="custody" id="vc3ExportCustody" value="all"><input type="hidden" name="query" id="vc3ExportQuery" value="">
            <button type="submit" class="btn btn-outline-primary" id="vc3Export"><i class="fas fa-download" aria-hidden="true"></i><span>Export current view</span></button>
          </form><?php endif; ?>
          <button type="button" class="btn btn-outline-secondary" id="vc3Refresh"><i class="fas fa-sync-alt" aria-hidden="true"></i><span>Refresh</span></button>
        </div>
        <div class="vc3-search" role="search" aria-label="Search and filter physical copies">
          <i class="fas fa-search vc5-search-icon" aria-hidden="true"></i>
          <input type="search" id="vc3Search" aria-label="Search physical copies" placeholder="Search record, holder or classification…" maxlength="150" autocomplete="off">
          <button type="button" id="vc3Clear" aria-label="Clear record search">Clear</button>
          <div class="vc5-custody-select"><i class="fas fa-sliders-h" aria-hidden="true"></i><label class="visually-hidden" for="vc3Custody">Custody filter</label><select id="vc3Custody" aria-label="Filter physical copies by custody"><option value="all">All custody</option><option value="borrowed">Borrowed</option><option value="overdue">Overdue</option><option value="due_soon">Due in 3 days</option><option value="no_due_date">No return date</option></select></div>
        </div>
      </div>
      <div id="vc3Error" class="vc3-error" role="alert" hidden></div>
      <p class="vc5-table-hint">Scroll sideways in the table to see all columns.</p>
      <div class="vc3-list" id="vc3List" tabindex="0" role="region" aria-label="Physical copies table">
        <table><caption class="visually-hidden">Registered physical copies in the selected location and custody filter</caption><thead><tr><th scope="col">Record</th><th scope="col">Physical location</th><th scope="col">Custody</th><th scope="col">Action</th></tr></thead><tbody id="vc3Rows"></tbody></table>
      </div>
      <footer class="vc3-pagination"><span id="vc3Count" role="status" aria-live="polite">Loading…</span><nav aria-label="Physical copies pagination"><button type="button" class="btn btn-outline-secondary" id="vc3Prev" disabled><i class="fas fa-chevron-left" aria-hidden="true"></i><span>Previous</span></button><span id="vc3Page">—</span><button type="button" class="btn btn-outline-secondary" id="vc3Next" disabled><span>Next</span><i class="fas fa-chevron-right" aria-hidden="true"></i></button></nav></footer>
    </section>
  </div>
</main>
<?php require __DIR__ . '/includes/physical_record_profile.php'; ?>
<?php require __DIR__ . '/includes/storage_location_manager.php'; ?>
<script src="assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
<script src="assets/js/storage-location-manager.js?v=vc2-1"></script>
<script src="assets/js/physical-record-profile.js?v=vc4c-1"></script>
<script src="assets/js/physical-cabinet.js?v=<?= filemtime(__DIR__.'/assets/js/physical-cabinet.js') ?>"></script>
</body></html>
