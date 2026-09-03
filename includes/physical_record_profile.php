<?php
// Shared by Company Files, Official Records and Virtual Cabinet. API enforces access.
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$vc3IsCabinet=basename($_SERVER['SCRIPT_NAME'] ?? '')==='virtual_cabinet.php' || !empty($vc3FixtureCabinet);
?>
<div class="modal fade vcp" id="physicalRecordProfile" tabindex="-1" aria-labelledby="vcpHeading" aria-hidden="true" data-cabinet="<?= $vc3IsCabinet?'1':'0' ?>">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><div class="vcp-heading"><span class="vcp-eyebrow">Physical record</span><h5 id="vcpHeading">Record profile</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close physical record"></button></div>
    <div class="modal-body">
      <div id="vcpMessage" role="status" aria-live="polite" hidden></div>
      <div id="vcpContent" hidden>
        <section id="vcpDigitalNotice" class="vcp-section" hidden><h6>Digital file destroyed · Physical copy retained</h6><p>The digital file is no longer available. The registered paper copy still needs custody and location tracking. Digital destruction is not confirmation of paper disposal. Version numbers below are the last recorded versions.</p></section>
        <dl class="vcp-grid">
          <div><dt>Record number</dt><dd id="vcpNumber"></dd></div><div><dt>Digital classification</dt><dd id="vcpCategory"></dd></div>
          <div><dt>Filing position</dt><dd id="vcpState"></dd></div><div><dt>Custody</dt><dd id="vcpCustody"></dd></div>
          <div><dt>Digital / physical version</dt><dd id="vcpVersions"></dd></div><div><dt>Version check</dt><dd id="vcpSync"></dd></div>
        </dl>
        <section class="vcp-section"><h6>Physical storage location</h6><p id="vcpPath"></p></section>
        <section class="vcp-section"><h6>Current holder</h6><p id="vcpHolderSummary"></p></section>
        <div id="vcpActions" class="vcp-actions">
          <button type="button" class="btn btn-primary" id="vcpAssign" hidden>Store in physical folder</button>
          <button type="button" class="btn btn-outline-primary" id="vcpTransfer" hidden>Transfer physical copy</button>
          <button type="button" class="btn btn-outline-primary" id="vcpCheckout" hidden>Manage check-out</button>
          <button type="button" class="btn btn-outline-secondary" id="vcpReplace" hidden>Replace physical copy</button>
          <button type="button" class="btn btn-outline-danger" id="vcpDispose" hidden>Dispose physical copy</button>
          <a class="btn btn-outline-primary" id="vcpCabinet" href="#" hidden>Manage in Virtual Cabinet</a>
        </div>
        <form id="vcpForm" hidden>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>">
          <h6 id="vcpFormTitle"></h6>
          <div id="vcpFolderFields" hidden>
            <label for="vcpFolderSearch">Find a physical folder</label><input id="vcpFolderSearch" type="search" class="form-control" placeholder="Search location or code" maxlength="150">
            <label for="vcpFolder">Physical folder</label><select id="vcpFolder" name="folder" class="form-select"></select>
            <p id="vcpFolderEmpty" class="vcp-help" role="status" aria-live="polite" hidden></p>
            <p class="vcp-help">Select the actual paper-file location. PR and PO may use different folders in the same drawer. Create missing folders through Manage locations.</p>
            <section id="vcpDestinationPreview" class="vcp-section" hidden><h6>Transfer destination</h6><p id="vcpDestinationPath"></p><p class="vcp-help">The source is the physical storage location shown above. This records a paper-copy move only; it does not move, rename, replace or delete the digital file.</p></section>
          </div>
          <div id="vcpBorrowFields" hidden>
            <label for="vcpHolder">Current holder</label><select id="vcpHolder" name="holder_id" class="form-select"></select>
            <label for="vcpReturnDate">Expected return date <span class="vcp-help">(optional)</span></label><input id="vcpReturnDate" name="expected_return" type="date" class="form-control">
          </div>
          <div id="vcpDisposalFields" hidden>
            <section class="vcp-section vcp-warning"><h6>Separate physical disposal</h6><p>This confirms that the real paper copy has already been destroyed. It removes the copy from the active cabinet but preserves its digital destruction certificate, last storage path, versions, histories, actor, time and integrity evidence.</p></section>
            <label for="vcpDisposalMethod">Physical disposal method</label>
            <select id="vcpDisposalMethod" name="disposal_method" class="form-select">
              <option value="">Select disposal method</option>
              <option value="Cross-cut shredding">Cross-cut shredding</option>
              <option value="Pulverization">Pulverization</option>
              <option value="Authorized disposal service">Authorized disposal service</option>
              <option value="Other documented method">Other documented method</option>
            </select>
            <label for="vcpTypedConfirmation">Type <strong>DISPOSE</strong> to confirm</label>
            <input id="vcpTypedConfirmation" name="typed_confirmation" type="text" class="form-control" maxlength="20" autocomplete="off" spellcheck="false" placeholder="DISPOSE">
          </div>
          <label for="vcpReason">Reason / remarks</label><textarea id="vcpReason" name="reason" class="form-control" maxlength="500" rows="2" required></textarea>
          <label class="vcp-confirm"><input type="checkbox" name="confirmed" value="1" required id="vcpConfirmed"><span id="vcpConfirmation"></span></label>
        </form>
        <details id="vcpHistory"><summary>Borrowing and filing history</summary><p class="vcp-help">Latest 20 entries per history.</p><h6>Borrowing</h6><div id="vcpBorrowHistory"></div><h6>Filing</h6><div id="vcpMoveHistory"></div></details>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="vcpClose">Close</button>
      <a class="btn btn-primary" href="#" id="vcpDigital" hidden>View digital file</a>
      <button type="button" class="btn btn-outline-secondary" id="vcpCancel" hidden>Back</button>
      <button type="submit" class="btn btn-primary" form="vcpForm" id="vcpSave" hidden>Save confirmation</button>
    </div>
  </div></div>
</div>
