(() => {
    'use strict';

    const feedback = document.querySelector('[data-declaration-feedback]');
    if (feedback && window.DRMSFeedback) {
        window.DRMSFeedback.toast(feedback.textContent, feedback.dataset.declarationFeedback, 3000);
        feedback.remove();
    }

    const url = new URL(location.href);
    url.searchParams.delete('error');
    url.searchParams.delete('success');
    history.replaceState(null, '', url.pathname + url.search);

    function showFormError(form, message) {
        const error = form.querySelector('.dec-error');
        if (!error) return;
        error.textContent = message;
        error.hidden = !message;
        if (message) error.focus();
    }

    function setBusy(form, button) {
        form.querySelectorAll('button').forEach(item => { item.disabled = true; });
        if (button) button.textContent = 'Saving…';
    }

    function actionFor(form, submitter) {
        if (submitter?.name === 'action') return submitter.value;
        return form.querySelector('[name=action]')?.value || '';
    }

    document.querySelectorAll('[data-declaration-form]').forEach(form => {
        let busy = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;

            const button = event.submitter;
            const action = actionFor(form, button);
            const value = name => (form.elements.namedItem(name)?.value || '').trim();
            const signedOfficialDeclaration = action === 'declare_official' &&
                form.elements.e_signature_client_confirmed?.value === '1';
            let message = '';

            if (action === 'submit') {
                if (!value('external_name') || value('signer_role') !== 'GM') {
                    message = 'Enter the signatory name. Official Record requests are reviewed by the General Manager.';
                } else if (!form.elements.signed_copy_confirmed?.checked) {
                    message = 'Confirm that the uploaded copy already contains the required signatures.';
                } else if (value('external_date') && !form.elements.external_date.validity.valid) {
                    message = 'Enter a valid signature date that is not in the future.';
                }
            }
            if (action === 'return' && !value('remarks')) {
                message = 'Enter the reason for returning this request.';
            }
            if (action === 'declare_official' && !signedOfficialDeclaration) {
                message = 'Use the electronic-signature confirmation before filing this Official Record.';
            }
            if (!message && !signedOfficialDeclaration && !window.DRMSFeedback) {
                message = 'The confirmation service is unavailable. Refresh the page before submitting.';
            }
            showFormError(form, message);
            if (message) return;

            if (signedOfficialDeclaration) {
                busy = true;
                setBusy(form, null);
                HTMLFormElement.prototype.submit.call(form);
                return;
            }

            busy = true;
            try {
                const approved = await window.DRMSFeedback.confirm({
                    title: action === 'return' ? 'Return this request?' : action === 'cancel' ? 'Cancel your request?' : 'Submit for verification?',
                    message: 'Your decision will be recorded and the request status will be updated.',
                    confirmText: action === 'return' ? 'Return request' : action === 'cancel' ? 'Cancel request' : 'Submit request',
                    cancelText: 'Go back',
                    tone: action === 'return' || action === 'cancel' ? 'warning' : 'info'
                });
                if (!approved) {
                    busy = false;
                    return;
                }
                if (button?.hasAttribute('formaction')) form.action = button.getAttribute('formaction');
                if (button?.name === 'action') {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = button.name; input.value = button.value;
                    form.append(input);
                }
                setBusy(form, button);
                HTMLFormElement.prototype.submit.call(form);
            } catch (_) {
                busy = false;
                showFormError(form, 'The action could not be submitted. Refresh and try again.');
            }
        });
    });

    const decisionForm = document.getElementById('declarationDecisionForm') ||
        document.querySelector('[data-declaration-form] [data-declaration-sign]')?.closest('form');
    if (!decisionForm) return;

    const actionInput = document.getElementById('declarationDecisionAction');
    const signButton = decisionForm.querySelector('[data-declaration-sign]');
    const returnButton = decisionForm.querySelector('[data-declaration-return]');

    if (signButton && actionInput) {
        signButton.addEventListener('click', () => {
            if (!window.DRMSESignature) {
                showFormError(decisionForm, 'The electronic-signature component is unavailable. Refresh the page and try again.');
                return;
            }
            actionInput.value = 'declare_official';
            decisionForm.action = 'actions/document_handler.php';
            window.DRMSESignature.open({
                form: decisionForm,
                title: 'Sign Official Record declaration',
                subtitle: 'Confirm that the reviewed signed copy may be locked and filed as an Official Record.',
                recordLabel: decisionForm.closest('.dec-panel')?.querySelector('h2')?.textContent?.trim() || 'Official Record declaration',
                stage: 'Official Declaration',
                consent: 'I reviewed this exact document version and verified that it contains the required signatures. I authorize filing it as an Official Record through my electronic signature.'
            });
        });
    }

    if (returnButton && actionInput) {
        returnButton.addEventListener('click', () => {
            actionInput.value = 'return';
            decisionForm.action = 'actions/official_declaration_handler.php';
            if (decisionForm.requestSubmit) decisionForm.requestSubmit();
            else decisionForm.submit();
        });
    }
})();
