(() => {
    'use strict';
    const form = document.getElementById('profileInfoForm');
    if (!form) return;
    const edit = document.getElementById('profileEditButton');
    const actions = document.getElementById('profileEditActions');
    const cancel = document.getElementById('profileEditCancel');
    const save = document.getElementById('profileEditSave');
    const name = document.getElementById('profileFullName');
    const email = document.getElementById('profileEmail');
    const error = document.getElementById('profileEditError');
    const original = { name: name.value, email: email.value };
    let editing = false;
    let busy = false;
    const showError = (message, field) => {
        error.textContent = message;
        error.hidden = false;
        if (field) { field.setAttribute('aria-invalid', 'true'); field.focus(); }
    };
    const clearError = () => {
        error.hidden = true;
        error.textContent = '';
        name.removeAttribute('aria-invalid');
        email.removeAttribute('aria-invalid');
    };
    function setEditing(value) {
        editing = value;
        name.readOnly = !value;
        email.readOnly = !value;
        actions.hidden = !value;
        edit.hidden = value;
        edit.setAttribute('aria-expanded', String(value));
        form.classList.toggle('is-editing', value);
        clearError();
        if (value) name.focus();
    }
    edit.addEventListener('click', () => {
        if (form.dataset.canEdit === '1' && !busy) setEditing(true);
    });
    cancel.addEventListener('click', () => {
        if (busy) return;
        name.value = original.name;
        email.value = original.email;
        setEditing(false);
        edit.focus();
    });
    form.addEventListener('input', clearError);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!editing || busy || form.dataset.canEdit !== '1') return;
        clearError();
        name.value = name.value.trim();
        email.value = email.value.trim().toLowerCase();
        if (Array.from(name.value).length < 2 || Array.from(name.value).length > 100 || /[\x00-\x1f\x7f]/.test(name.value)) {
            showError('Enter a full name between 2 and 100 characters.', name); return;
        }
        if (email.value !== original.email.toLowerCase() && (!email.value || email.validity.typeMismatch || email.value.length > 100)) {
            showError('Enter a valid email address. Your current recovery email cannot be removed.', email); return;
        }
        if (name.value === original.name && email.value === original.email.toLowerCase()) {
            setEditing(false); edit.focus(); return;
        }
        if (!window.DRMSFeedback || typeof window.DRMSFeedback.confirm !== 'function') {
            showError('Confirmation is unavailable. Refresh this page and try again.'); return;
        }
        busy = true;
        save.disabled = true;
        cancel.disabled = true;
        name.readOnly = true;
        email.readOnly = true;
        try {
            const emailChanged = email.value !== original.email.toLowerCase();
            const confirmed = await window.DRMSFeedback.confirm({
                title: 'Save profile changes?',
                message: emailChanged
                    ? 'A code will be sent to your new email. Your name and email will update after verification, then stay locked for 30 days.'
                    : 'After saving, you can edit your name and email again in 30 days. Check your information before continuing.',
                confirmText: emailChanged ? 'Send verification code' : 'Save changes',
                cancelText: 'Review again',
                tone: 'warning'
            });
            if (confirmed) {
                save.textContent = emailChanged ? 'Sending code…' : 'Saving…';
                HTMLFormElement.prototype.submit.call(form);
                return;
            }
        } catch (_) {
            showError('Unable to open confirmation. Refresh this page and try again.');
        }
        busy = false;
        save.disabled = false;
        cancel.disabled = false;
        name.readOnly = false;
        email.readOnly = false;
    });
})();
