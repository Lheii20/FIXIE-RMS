<?php
/** @var string $shareActionPage */
/** @var array $all_users */
$shareFormAction = htmlspecialchars($shareActionPage, ENT_QUOTES, 'UTF-8');
$shareAllowEditors = $shareActionPage === 'general_docs.php';
?>
<div class="modal fade record-share-modal" id="shareDocumentModal" tabindex="-1" aria-labelledby="shareModalTitle" aria-hidden="true" data-share-allow-editors="<?php echo $shareAllowEditors ? '1' : '0'; ?>">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form action="<?php echo $shareFormAction; ?>" method="post" id="shareForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="share_document">
                <input type="hidden" name="doc_id" id="shareDocId">
                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string) $_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="modal-header">
                    <div class="record-share-title-wrap">
                        <h2 class="modal-title" id="shareModalTitle">Manage access</h2>
                        <p id="shareDocName" class="record-share-file-name"></p>
                        <span id="shareDocOwner" class="record-share-owner"></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <fieldset class="record-share-scope">
                        <legend>Who can open this file?</legend>
                        <label class="record-share-choice">
                            <input type="radio" name="access_type" value="Folder Default" data-share-scope checked>
                            <span><strong>People with folder access</strong><small>Keep the folder’s usual access. You can add other people below.</small></span>
                        </label>
                        <label class="record-share-choice">
                            <input type="radio" name="access_type" value="Restricted" data-share-scope>
                            <span><strong>Selected people only</strong><small>Only the people you choose below can open it.</small></span>
                        </label>
                    </fieldset>

                    <div class="record-share-people-heading">
                        <div><h3>People</h3><p id="sharePeopleHelp">Choose anyone who also needs access.</p></div>
                        <span id="shareSelectedCount" aria-live="polite">0 selected</span>
                    </div>
                    <label class="record-share-search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="sharePeopleSearch" placeholder="Find a person" aria-label="Find a person" autocomplete="off">
                    </label>
                    <div class="record-share-people-list" id="sharePeopleList">
                        <?php foreach ($all_users as $shareUser):
                            $shareUserId = (int) $shareUser['user_id'];
                            $shareUserName = htmlspecialchars((string) $shareUser['full_name'], ENT_QUOTES, 'UTF-8');
                            $shareUserRole = htmlspecialchars((string) $shareUser['role'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="record-share-person" data-share-person data-share-name="<?php echo $shareUserName; ?> <?php echo $shareUserRole; ?>">
                            <div class="record-share-person-details"><strong><?php echo $shareUserName; ?></strong><small><?php echo $shareUserRole; ?></small></div>
                            <select name="user_roles[<?php echo $shareUserId; ?>]" data-share-role data-user-id="<?php echo $shareUserId; ?>" aria-label="Access for <?php echo $shareUserName; ?>">
                                <option value="None">No extra access</option>
                                <option value="Viewer">Can view</option>
                                <?php if ($shareAllowEditors): ?>
                                <option value="Editor">Can edit</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$all_users): ?><p class="record-share-empty">No active people are available to add.</p><?php endif; ?>
                        <p class="record-share-empty" id="shareNoResults" hidden>No matching person found.</p>
                    </div>
                    <p class="record-share-summary" id="shareAccessSummary" aria-live="polite"></p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn record-share-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn record-share-save">Save access</button>
                </div>
            </form>
        </div>
    </div>
</div>
