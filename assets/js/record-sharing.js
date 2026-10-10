(function () {
    'use strict';

    function ready() {
        const modal = document.getElementById('shareDocumentModal');
        if (!modal) return;

        const form = document.getElementById('shareForm');
        const peopleSearch = document.getElementById('sharePeopleSearch');
        const people = Array.from(modal.querySelectorAll('[data-share-person]'));
        const roleSelects = Array.from(modal.querySelectorAll('[data-share-role]'));
        const scopes = Array.from(modal.querySelectorAll('[data-share-scope]'));
        const selectedCount = document.getElementById('shareSelectedCount');
        const summary = document.getElementById('shareAccessSummary');
        const help = document.getElementById('sharePeopleHelp');
        const noResults = document.getElementById('shareNoResults');
        const allowEditors = modal.dataset.shareAllowEditors === '1';

        function selectedScope() {
            return scopes.find(input => input.checked)?.value || 'Folder Default';
        }

        function refreshSummary() {
            const count = roleSelects.filter(select => select.value !== 'None').length;
            const restricted = selectedScope() === 'Restricted';
            selectedCount.textContent = count + ' selected';
            help.textContent = restricted
                ? (allowEditors ? 'Choose who can view or edit this file.' : 'Choose who can view this record.')
                : 'Add people who are not already covered by folder access.';
            roleSelects.forEach(select => {
                select.options[0].textContent = restricted ? 'No access' : 'No extra access';
                select.closest('[data-share-person]')?.classList.toggle('is-selected', select.value !== 'None');
            });
            summary.textContent = restricted
                ? (count ? 'Only the selected people, the owner, and management can open this file.' : 'No one is selected. Only the owner and management can open this file.')
                : (count ? 'People with folder access and the selected people can open this file.' : 'People with folder access can open this file.');
        }

        function filterPeople() {
            const query = peopleSearch.value.trim().toLocaleLowerCase();
            let visible = 0;
            people.forEach(row => {
                const matches = (row.dataset.shareName || '').toLocaleLowerCase().includes(query);
                row.hidden = !matches;
                if (matches) visible++;
            });
            noResults.hidden = visible > 0 || people.length === 0;
        }

        document.querySelectorAll('[data-record-share-open]').forEach(button => button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const docId = Number(button.dataset.shareDocId || 0);
            if (!Number.isInteger(docId) || docId < 1) return;
            document.getElementById('shareDocId').value = String(docId);
            document.getElementById('shareDocName').textContent = button.dataset.shareFileName || 'File';
            document.getElementById('shareDocOwner').textContent = 'Owner: ' + (button.dataset.shareOwner || 'Unknown');

            const accessType = button.dataset.shareAccessType === 'Restricted' ? 'Restricted' : 'Folder Default';
            scopes.forEach(input => { input.checked = input.value === accessType; });

            let permissions = {};
            try {
                const parsed = JSON.parse(button.dataset.sharePermissions || '{}');
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) permissions = parsed;
            } catch (error) {
                permissions = {};
            }
            roleSelects.forEach(select => {
                const role = permissions['user_' + select.dataset.userId];
                select.value = role === 'Editor' && !allowEditors
                    ? 'Viewer'
                    : (role === 'Viewer' || role === 'Editor' ? role : 'None');
            });
            peopleSearch.value = '';
            filterPeople();
            refreshSummary();
            const dropdownToggle = button.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]');
            if (dropdownToggle) bootstrap.Dropdown.getOrCreateInstance(dropdownToggle).hide();
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }));

        scopes.forEach(input => input.addEventListener('change', refreshSummary));
        roleSelects.forEach(select => select.addEventListener('change', refreshSummary));
        peopleSearch.addEventListener('input', filterPeople);
        form.addEventListener('submit', function () {
            const saveButton = form.querySelector('.record-share-save');
            if (saveButton) {
                saveButton.disabled = true;
                saveButton.textContent = 'Saving…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
