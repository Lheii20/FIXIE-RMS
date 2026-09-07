<?php
// Shared by Company Files, Official Records and Virtual Cabinet. API enforces access.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$vc3IsCabinet = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'virtual_cabinet.php'
    || !empty($vc3FixtureCabinet);
?>
<div
    class="modal fade vcp"
    id="physicalRecordProfile"
    tabindex="-1"
    aria-labelledby="vcpHeading"
    aria-hidden="true"
    data-cabinet="<?= $vc3IsCabinet ? '1' : '0' ?>"
>
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <header class="modal-header vcp-modal-header">
                <div class="vcp-header-icon" aria-hidden="true">
                    <i class="fas fa-box-archive"></i>
                </div>
                <div class="vcp-heading">
                    <span class="vcp-eyebrow">Physical record control</span>
                    <h5 id="vcpHeading">Record profile</h5>
                    <p>Track the paper copy without changing the digital record.</p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close physical record profile"
                ></button>
            </header>

            <div class="modal-body">
                <div id="vcpMessage" role="status" aria-live="polite" hidden></div>

                <div id="vcpLoading" class="vcp-loading" role="status" aria-live="polite">
                    <span class="vcp-loading-spinner" aria-hidden="true"></span>
                    <div>
                        <strong>Loading physical record</strong>
                        <span>Retrieving its location, custody, and history.</span>
                    </div>
                </div>

                <div id="vcpContent" hidden>
                    <section id="vcpDigitalNotice" class="vcp-alert vcp-alert-warning" hidden>
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <div>
                            <h6>Digital file destroyed, physical copy retained</h6>
                            <p>The registered paper copy still requires location and custody tracking. Digital destruction does not confirm that the paper copy was disposed.</p>
                        </div>
                    </section>

                    <section class="vcp-overview" aria-label="Physical record summary">
                        <div class="vcp-overview-heading">
                            <div>
                                <span class="vcp-section-label">Record identity</span>
                                <h6>Filing and custody status</h6>
                            </div>
                            <span class="vcp-overview-hint">Current information</span>
                        </div>

                        <dl class="vcp-grid">
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-hashtag"></i></span>
                                <div><dt>Record number</dt><dd id="vcpNumber"></dd></div>
                            </div>
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-folder-tree"></i></span>
                                <div><dt>Classification</dt><dd id="vcpCategory"></dd></div>
                            </div>
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-location-dot"></i></span>
                                <div><dt>Filing position</dt><dd id="vcpState"></dd></div>
                            </div>
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-user-lock"></i></span>
                                <div><dt>Custody</dt><dd id="vcpCustody"></dd></div>
                            </div>
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-code-compare"></i></span>
                                <div><dt>Digital / physical version</dt><dd id="vcpVersions"></dd></div>
                            </div>
                            <div class="vcp-data-item">
                                <span class="vcp-data-icon"><i class="fas fa-circle-check"></i></span>
                                <div><dt>Version check</dt><dd id="vcpSync"></dd></div>
                            </div>
                        </dl>
                    </section>

                    <div class="vcp-position-grid">
                        <section class="vcp-position-card">
                            <span class="vcp-card-icon"><i class="fas fa-boxes-stacked"></i></span>
                            <div>
                                <span class="vcp-section-label">Storage</span>
                                <h6>Physical location</h6>
                                <p id="vcpPath"></p>
                            </div>
                        </section>
                        <section class="vcp-position-card">
                            <span class="vcp-card-icon"><i class="fas fa-user-check"></i></span>
                            <div>
                                <span class="vcp-section-label">Accountability</span>
                                <h6>Current holder</h6>
                                <p id="vcpHolderSummary"></p>
                            </div>
                        </section>
                    </div>

                    <section id="vcpActionPanel" class="vcp-action-panel">
                        <div class="vcp-section-heading">
                            <div>
                                <span class="vcp-section-label">Available actions</span>
                                <h6>What do you need to record?</h6>
                            </div>
                            <p>Choose only the action that happened to the real paper copy.</p>
                        </div>

                        <div id="vcpActions" class="vcp-actions">
                            <button type="button" class="vcp-action-card vcp-action-primary" id="vcpAssign" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-folder-plus"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">File copy</strong><small>Assign its actual physical folder.</small></span>
                                <i class="fas fa-chevron-right vcp-action-arrow" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="vcp-action-card" id="vcpTransfer" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-arrow-right-arrow-left"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">Transfer copy</strong><small>Move it to another physical folder.</small></span>
                                <i class="fas fa-chevron-right vcp-action-arrow" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="vcp-action-card" id="vcpCheckout" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-user-clock"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">Manage check-out</strong><small class="vcp-action-description">Record a borrower or returned copy.</small></span>
                                <i class="fas fa-chevron-right vcp-action-arrow" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="vcp-action-card" id="vcpReplace" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-arrows-rotate"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">Replace copy</strong><small>Synchronize paper with the latest file.</small></span>
                                <i class="fas fa-chevron-right vcp-action-arrow" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="vcp-action-card vcp-action-danger" id="vcpDispose" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-trash-can"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">Dispose copy</strong><small>Record completed physical destruction.</small></span>
                                <i class="fas fa-chevron-right vcp-action-arrow" aria-hidden="true"></i>
                            </button>
                            <a class="vcp-action-card" id="vcpCabinet" href="#" hidden>
                                <span class="vcp-action-icon"><i class="fas fa-table-cells-large"></i></span>
                                <span class="vcp-action-copy"><strong class="vcp-action-title">Virtual Cabinet</strong><small>Open full physical filing controls.</small></span>
                                <i class="fas fa-arrow-up-right-from-square vcp-action-arrow" aria-hidden="true"></i>
                            </a>
                        </div>
                    </section>

                    <form id="vcpForm" class="vcp-form" novalidate hidden>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                        <div class="vcp-form-heading">
                            <span class="vcp-form-icon"><i class="fas fa-pen-to-square"></i></span>
                            <div>
                                <span class="vcp-section-label">Action details</span>
                                <h6 id="vcpFormTitle"></h6>
                                <p id="vcpFormDescription"></p>
                            </div>
                        </div>

                        <div id="vcpFolderFields" hidden>
                            <div class="vcp-field-group vcp-folder-finder">
                                <label for="vcpFolderSearch">Find a physical folder</label>
                                <div class="vcp-input-icon vcp-folder-search">
                                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                    <input
                                        id="vcpFolderSearch"
                                        type="search"
                                        class="form-control"
                                        placeholder="Search by room, cabinet, drawer, folder, or code"
                                        maxlength="150"
                                        autocomplete="off"
                                        aria-controls="vcpFolderResults"
                                        aria-describedby="vcpFolderSearchStatus vcpFolderError"
                                    >
                                    <button type="button" id="vcpFolderSearchClear" class="vcp-folder-search-clear" aria-label="Clear physical folder search" hidden>
                                        <i class="fas fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="vcp-folder-search-meta">
                                    <span id="vcpFolderSearchStatus" role="status" aria-live="polite">Loading available folders…</span>
                                    <button type="button" id="vcpFolderBrowse" class="vcp-folder-browse">
                                        <i class="fas fa-folder-tree" aria-hidden="true"></i><span>Browse all</span>
                                    </button>
                                </div>
                                <div id="vcpFolderResults" class="vcp-folder-results" role="listbox" aria-label="Matching physical folders"></div>
                                <button type="button" id="vcpFolderShowMore" class="vcp-folder-show-more" hidden>
                                    <span>Show more folders</span><i class="fas fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                <select id="vcpFolder" name="folder" class="vcp-folder-native-select" tabindex="-1" aria-hidden="true" aria-describedby="vcpFolderError"></select>
                                <p id="vcpFolderError" class="vcp-field-error" role="alert" hidden></p>
                                <p id="vcpFolderEmpty" class="vcp-folder-empty" role="status" aria-live="polite" hidden></p>
                                <p class="vcp-help">Different record types may share the same room, cabinet, and drawer while using separate folders.</p>
                            </div>
                            <section id="vcpDestinationPreview" class="vcp-destination vcp-selected-folder" hidden>
                                <span class="vcp-card-icon"><i class="fas fa-circle-check"></i></span>
                                <div>
                                    <span class="vcp-section-label">Selected physical folder</span>
                                    <h6 id="vcpDestinationPath"></h6>
                                    <p id="vcpDestinationHelp"></p>
                                </div>
                            </section>
                        </div>

                        <div id="vcpBorrowFields" class="vcp-form-grid" hidden>
                            <div class="vcp-field-group">
                                <label for="vcpHolder">Person receiving the copy</label>
                                <select id="vcpHolder" name="holder_id" class="form-select" aria-describedby="vcpHolderError"></select>
                                <p id="vcpHolderError" class="vcp-field-error" role="alert" hidden></p>
                            </div>
                            <div class="vcp-field-group">
                                <label for="vcpReturnDate">Expected return date <span class="vcp-optional">Optional</span></label>
                                <input id="vcpReturnDate" name="expected_return" type="date" class="form-control">
                            </div>
                        </div>

                        <div id="vcpDisposalFields" hidden>
                            <section class="vcp-alert vcp-alert-danger">
                                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                <div>
                                    <h6>Permanent physical-copy action</h6>
                                    <p>Use this only after the real paper copy has been destroyed. Its active cabinet registration will be removed, while the disposal evidence and history remain available.</p>
                                </div>
                            </section>
                            <div class="vcp-form-grid">
                                <div class="vcp-field-group">
                                    <label for="vcpDisposalMethod">Physical disposal method</label>
                                    <select id="vcpDisposalMethod" name="disposal_method" class="form-select" aria-describedby="vcpDisposalMethodError">
                                        <option value="">Select disposal method</option>
                                        <option value="Cross-cut shredding">Cross-cut shredding</option>
                                        <option value="Pulverization">Pulverization</option>
                                        <option value="Authorized disposal service">Authorized disposal service</option>
                                        <option value="Other documented method">Other documented method</option>
                                    </select>
                                    <p id="vcpDisposalMethodError" class="vcp-field-error" role="alert" hidden></p>
                                </div>
                                <div class="vcp-field-group">
                                    <label for="vcpTypedConfirmation">Type <strong>DISPOSE</strong> to confirm</label>
                                    <input id="vcpTypedConfirmation" name="typed_confirmation" type="text" class="form-control" maxlength="20" autocomplete="off" spellcheck="false" placeholder="DISPOSE" aria-describedby="vcpTypedConfirmationError">
                                    <p id="vcpTypedConfirmationError" class="vcp-field-error" role="alert" hidden></p>
                                </div>
                            </div>
                        </div>

                        <div class="vcp-field-group">
                            <label for="vcpReason">Reason or remarks</label>
                            <textarea id="vcpReason" name="reason" class="form-control" maxlength="500" rows="3" placeholder="Briefly explain why this physical-copy action is being recorded." required aria-describedby="vcpReasonHelp vcpReasonError"></textarea>
                            <p id="vcpReasonError" class="vcp-field-error" role="alert" hidden></p>
                            <p class="vcp-help" id="vcpReasonHelp">Keep this factual. It will become part of the record history.</p>
                        </div>

                        <label class="vcp-confirm" for="vcpConfirmed">
                            <input type="checkbox" name="confirmed" value="1" required id="vcpConfirmed">
                            <span><strong>Confirm this action</strong><small id="vcpConfirmation"></small></span>
                        </label>
                        <p id="vcpConfirmedError" class="vcp-field-error vcp-confirm-error" role="alert" hidden></p>
                    </form>

                    <details id="vcpHistory" class="vcp-history">
                        <summary>
                            <span><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Physical record history</span>
                            <small>Latest 20 entries per activity</small>
                        </summary>
                        <div class="vcp-history-grid">
                            <section>
                                <h6><i class="fas fa-user-clock" aria-hidden="true"></i> Borrowing</h6>
                                <div id="vcpBorrowHistory"></div>
                            </section>
                            <section>
                                <h6><i class="fas fa-route" aria-hidden="true"></i> Filing movements</h6>
                                <div id="vcpMoveHistory"></div>
                            </section>
                        </div>
                    </details>
                </div>
            </div>

            <div id="vcpConfirmDialog" class="vcp-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="vcpConfirmTitle" aria-describedby="vcpConfirmText" hidden>
                <div class="vcp-confirm-backdrop" aria-hidden="true"></div>
                <section class="vcp-confirm-card" id="vcpConfirmCard">
                    <span class="vcp-confirm-icon" id="vcpConfirmIcon"><i class="fas fa-circle-question"></i></span>
                    <div class="vcp-confirm-copy">
                        <span class="vcp-section-label">Please confirm</span>
                        <h6 id="vcpConfirmTitle">Confirm physical record action</h6>
                        <p id="vcpConfirmText"></p>
                    </div>
                    <div class="vcp-confirm-buttons">
                        <button type="button" class="btn btn-outline-secondary" id="vcpConfirmDismiss">Cancel</button>
                        <button type="button" class="btn btn-primary" id="vcpConfirmAccept">Confirm and continue</button>
                    </div>
                </section>
            </div>
            <footer class="modal-footer vcp-modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="vcpClose">
                    Close
                </button>
                <a class="btn btn-outline-primary" href="#" id="vcpDigital" hidden>
                    <i class="fas fa-file-arrow-up"></i><span>View digital file</span>
                </a>
                <button type="button" class="btn btn-outline-secondary" id="vcpCancel" hidden>
                    <i class="fas fa-arrow-left"></i><span>Back to profile</span>
                </button>
                <button type="submit" class="btn btn-primary" form="vcpForm" id="vcpSave" hidden>
                    <i class="fas fa-check"></i><span>Save confirmation</span>
                </button>
            </footer>
        </div>
    </div>
</div>
