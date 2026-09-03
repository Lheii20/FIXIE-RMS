<?php if (!empty($can_manage_physical)): ?>
<div class="modal fade vcm" id="storageLocationManager" tabindex="-1" aria-labelledby="vcmTitle" aria-hidden="true"
     data-endpoint="actions/storage_location_handler.php" data-token="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div><h2 class="modal-title" id="vcmTitle">Manage locations</h2><p class="vcm-subtitle">Organize physical storage independently of record categories.</p></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close location manager"></button>
      </div>
      <div id="vcmMessage" class="vcm-message" role="status" aria-live="polite" hidden></div>
      <section id="vcmList" class="vcm-list" aria-label="Location directory">
        <div class="vcm-toolbar">
          <div class="vcm-search">
            <label class="visually-hidden" for="vcmSearch">Search locations</label>
            <input type="search" id="vcmSearch" placeholder="Search name, code or location…" autocomplete="off">
            <label class="visually-hidden" for="vcmType">Location type</label>
            <select id="vcmType"><option value="">All locations</option><option value="building">Offices / sites</option><option value="room">Rooms</option><option value="cabinet">Cabinets</option><option value="drawer">Drawers</option><option value="box">Boxes</option><option value="folder" selected>Physical folders</option></select>
          </div>
          <button type="button" class="btn btn-outline-secondary" id="vcmRefresh">Refresh</button>
          <button type="button" class="btn btn-primary" id="vcmAdd">Add location</button>
        </div>
        <div class="vcm-table-scroll">
          <table class="vcm-table"><thead><tr><th scope="col">Name / code</th><th scope="col">Within</th><th scope="col">Usage</th><th scope="col" class="vcm-actions-head">Actions</th></tr></thead><tbody id="vcmRows"></tbody></table>
        </div>
        <div class="vcm-pager"><span id="vcmCount" role="status"></span><div><button type="button" class="btn btn-outline-secondary" id="vcmPrev">Previous</button><span id="vcmPage"></span><button type="button" class="btn btn-outline-secondary" id="vcmNext">Next</button></div></div>
      </section>
      <section id="vcmEditor" class="vcm-editor" hidden>
        <form id="vcmForm" class="vcm-form">
          <div class="vcm-form-grid">
            <div><label for="vcmEditType">Location type</label><select id="vcmEditType" class="form-select" required><option value="building">Office / site</option><option value="room">Room</option><option value="cabinet">Cabinet</option><option value="drawer">Drawer</option><option value="box">Box</option><option value="folder">Physical folder</option></select></div>
            <div><label for="vcmName">Name <span aria-hidden="true">*</span></label><input id="vcmName" class="form-control" required maxlength="120" autocomplete="off" placeholder="e.g. Purchase Orders — 2026"></div>
            <div class="vcm-span" id="vcmParentField"><label for="vcmParent">Inside / parent location <span aria-hidden="true">*</span></label><select id="vcmParent" class="form-select"></select><p class="vcm-help" id="vcmParentHelp"></p></div>
            <div id="vcmCodeField"><label for="vcmCode">Location code</label><input id="vcmCode" class="form-control" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,39}" placeholder="Leave blank to generate automatically"><p class="vcm-help">A permanent storage label, not a document record number.</p></div>
            <div id="vcmActiveField"><label for="vcmActive">Availability</label><select id="vcmActive" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="vcm-span"><label for="vcmReason">Reason / note <span id="vcmReasonRequired" aria-hidden="true">*</span></label><input id="vcmReason" class="form-control" maxlength="500" placeholder="Brief reason for this change"></div>
          </div>
          <p class="vcm-help vcm-boundary">Creating a physical folder does not create a record category or assign documents to it.</p>
        </form>
      </section>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="vcmCancel" hidden>Back to locations</button><button type="button" class="btn btn-outline-secondary" id="vcmClose" data-bs-dismiss="modal">Close</button><button type="submit" form="vcmForm" class="btn btn-primary" id="vcmSave" hidden>Save location</button></div>
    </div>
  </div>
</div>
<?php endif; ?>
