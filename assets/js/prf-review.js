(function () {
    'use strict';

    function cleanFeedbackQuery() {
        if (!window.history || !window.history.replaceState) {
            return;
        }

        const url = new URL(window.location.href);
        if (!url.searchParams.has('success') && !url.searchParams.has('error')) {
            return;
        }

        url.searchParams.delete('success');
        url.searchParams.delete('error');
        window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
    }

    function initializeAlerts() {
        document.querySelectorAll('[data-dismiss-prf-alert]').forEach(function (button) {
            button.addEventListener('click', function () {
                const alert = button.closest('[data-prf-review-alert]');
                if (alert) {
                    alert.remove();
                }
            });
        });

        cleanFeedbackQuery();
    }

    function initializeDecisionForm() {
        const form = document.getElementById('prfDecisionForm');
        if (!form) {
            return;
        }

        const actionInput = document.getElementById('prfDecisionAction');
        const remarksInput = document.getElementById('decisionRemarks');
        const remarksError = document.getElementById('decisionRemarksError');
        const decisionButtons = Array.from(form.querySelectorAll('[data-prf-decision]'));
        const prNumber = form.dataset.prNumber || 'this PRF';
        const decisionStage = form.dataset.decisionStage || 'the current stage';
        let submitting = false;

        function clearRemarksError() {
            if (remarksInput) {
                remarksInput.classList.remove('is-invalid');
                remarksInput.removeAttribute('aria-invalid');
            }

            if (remarksError) {
                remarksError.textContent = '';
                remarksError.classList.add('d-none');
            }
        }

        function showRemarksError(message) {
            if (remarksInput) {
                remarksInput.classList.add('is-invalid');
                remarksInput.setAttribute('aria-invalid', 'true');
                remarksInput.focus();
            }

            if (remarksError) {
                remarksError.textContent = message;
                remarksError.classList.remove('d-none');
            }
        }

        function lockDecisionForm(decision) {
            submitting = true;
            decisionButtons.forEach(function (button) {
                button.disabled = true;
            });

            const activeButton = decisionButtons.find(function (button) {
                return button.dataset.prfDecision === decision;
            });

            if (activeButton) {
                activeButton.innerHTML = decision === 'approve'
                    ? '<i class="fas fa-spinner fa-spin"></i> Approving…'
                    : '<i class="fas fa-spinner fa-spin"></i> Rejecting…';
            }
        }

        function submitDecision(button, decision) {
            if (!actionInput || !button.dataset.actionValue) {
                return;
            }

            actionInput.value = button.dataset.actionValue;
            lockDecisionForm(decision);
            form.submit();
        }

        function askForConfirmation(button, decision) {
            const isApproval = decision === 'approve';
            const isFinalApproval = isApproval && decisionStage === 'Owner Approval';
            const title = isFinalApproval
                ? 'Give final approval?'
                : (isApproval ? 'Approve this stage?' : 'Reject this PRF?');
            const message = isApproval
                ? (isFinalApproval
                    ? prNumber + ' will become an officially approved PRF after this final decision.'
                    : prNumber + ' will pass ' + decisionStage + ' and continue through the approval route.')
                : prNumber + ' will stop at ' + decisionStage + '. The Sales Staff will receive the recorded reason.';

            if (window.Swal) {
                window.Swal.fire({
                    title: title,
                    text: message,
                    icon: isApproval ? 'question' : 'warning',
                    showCancelButton: true,
                    confirmButtonText: isFinalApproval
                        ? 'Yes, give final approval'
                        : (isApproval ? 'Yes, approve stage' : 'Yes, reject PRF'),
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: isApproval ? '#2563eb' : '#dc2626',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: !isApproval,
                    customClass: {
                        popup: 'prf-review-confirmation'
                    }
                }).then(function (result) {
                    if (result.isConfirmed) {
                        submitDecision(button, decision);
                    }
                });
                return;
            }

            if (window.confirm(title + '\n\n' + message)) {
                submitDecision(button, decision);
            }
        }

        if (remarksInput) {
            remarksInput.addEventListener('input', clearRemarksError);
        }

        decisionButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (submitting) {
                    return;
                }

                clearRemarksError();

                const decision = button.dataset.prfDecision;
                const remarks = remarksInput ? remarksInput.value.trim() : '';

                if (remarks.length > 2000) {
                    showRemarksError('Decision remarks must not exceed 2,000 characters.');
                    return;
                }

                if (decision === 'reject' && remarks === '') {
                    showRemarksError('Enter a clear reason before rejecting this PRF.');
                    return;
                }

                askForConfirmation(button, decision);
            });
        });

        form.addEventListener('submit', function (event) {
            if (!submitting) {
                event.preventDefault();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeAlerts();
        initializeDecisionForm();
    });
}());
