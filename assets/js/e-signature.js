(function () {
    'use strict';

    if (window.DRMSESignature) return;
    let modal, activeForm, restoreFocus;

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function ensure() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'drms-esign-modal';
        modal.hidden = true;
        modal.innerHTML = '<section class="drms-esign-dialog" role="dialog" aria-modal="true" aria-labelledby="drmsEsignTitle"><div class="drms-esign-dialog__head"><h2 id="drmsEsignTitle">Apply electronic signature</h2><p id="drmsEsignSubtitle"></p></div><div class="drms-esign-dialog__body"><div class="drms-esign-context" id="drmsEsignContext"></div><label class="drms-esign-choice"><input type="checkbox" id="drmsEsignConsent"><span id="drmsEsignConsentText"></span></label><p class="drms-esign-session-note"><i class="fas fa-user-shield"></i> Your active signed-in account will be recorded as the signatory.</p><div class="drms-esign-error" id="drmsEsignError"></div></div><div class="drms-esign-dialog__foot"><button type="button" class="btn btn-light border" data-esign-cancel>Cancel</button><button type="button" class="btn btn-primary" data-esign-submit><i class="fas fa-signature me-1"></i> Sign approval</button></div></section>';
        document.body.append(modal);
        modal.querySelector('[data-esign-cancel]').addEventListener('click', close);
        modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
        modal.querySelector('[data-esign-submit]').addEventListener('click', sign);
        document.addEventListener('keydown', function (event) {
            if (!modal.hidden && event.key === 'Escape') { event.preventDefault(); close(); }
        });
    }

    function error(message) {
        const node = modal.querySelector('#drmsEsignError');
        node.textContent = message || '';
        node.classList.toggle('is-visible', Boolean(message));
    }

    function putHidden(form, name, value) {
        let input = form.querySelector('input[name="' + name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.append(input);
        }
        input.value = value;
    }

    function open(options) {
        if (!(options && options.form instanceof HTMLFormElement)) throw new Error('A target approval form is required.');
        ensure();
        activeForm = options.form;
        restoreFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.querySelector('#drmsEsignTitle').textContent = options.title || 'Apply electronic signature';
        modal.querySelector('#drmsEsignSubtitle').textContent = options.subtitle || 'Confirm your assigned approval before it is recorded.';
        modal.querySelector('#drmsEsignContext').innerHTML = '<strong>' + escapeHtml(options.recordLabel || 'Approval record') + '</strong><br>' + escapeHtml(options.stage || 'Approval stage');
        modal.querySelector('#drmsEsignConsentText').textContent = options.consent || 'I confirm that I reviewed this record and authorize this approval under my assigned organizational role.';
        modal.querySelector('#drmsEsignConsent').checked = false;
        error('');
        modal.hidden = false;
        requestAnimationFrame(function () { modal.classList.add('is-visible'); });
        setTimeout(function () { modal.querySelector('#drmsEsignConsent').focus(); }, 60);
    }

    function close() {
        if (!modal || modal.hidden) return;
        modal.classList.remove('is-visible');
        setTimeout(function () {
            modal.hidden = true;
            if (restoreFocus && document.contains(restoreFocus)) restoreFocus.focus({ preventScroll: true });
        }, 160);
        activeForm = null;
    }

    function sign() {
        if (!activeForm) return;
        const consent = modal.querySelector('#drmsEsignConsent');
        if (!consent.checked) {
            error('Confirm the electronic-signature statement before signing.');
            consent.focus();
            return;
        }
        putHidden(activeForm, 'e_signature_confirmed', '1');
        putHidden(activeForm, 'e_signature_client_confirmed', '1');
        const form = activeForm;
        close();
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    }

    window.DRMSESignature = { open: open };
}());
