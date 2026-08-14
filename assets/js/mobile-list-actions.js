(() => {
    const mobileQuery = window.matchMedia('(max-width: 767.98px)');

    function restoreDesktopActions(row) {
        const actionCell = row.cells[row.cells.length - 1];
        const mobileMenu = actionCell.querySelector('.mobile-list-actions');

        if (!mobileMenu) return;

        const actionFlex = actionCell.querySelector('.action-flex');

        if (actionFlex) {
            mobileMenu.querySelectorAll('.mobile-action-menu > li > *').forEach(action => {
                actionFlex.appendChild(action);
            });

            actionFlex.hidden = false;
            actionFlex.classList.remove('single-mobile-action');
        }

        mobileMenu.remove();
    }

    function setMobileActions(row) {
        const actionCell = row.cells[row.cells.length - 1];
        const actionFlex = actionCell.querySelector('.action-flex');

        if (!actionFlex) return;

        const actions = Array.from(actionFlex.children);

        if (actions.length <= 1) {
            actionFlex.hidden = false;
            actionFlex.classList.add('single-mobile-action');
            return;
        }

        if (actionCell.querySelector('.mobile-list-actions')) return;

        const dropdown = document.createElement('div');
        dropdown.className = 'dropdown mobile-list-actions';

        dropdown.innerHTML = `
            <button class="btn mobile-action-trigger dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Open actions">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mobile-action-menu"></ul>
        `;

        const menu = dropdown.querySelector('.mobile-action-menu');

        actions.forEach(action => {
            const item = document.createElement('li');
            item.appendChild(action);
            menu.appendChild(item);
        });

        actionFlex.hidden = true;
        actionCell.appendChild(dropdown);
    }

    function updateListActions() {
        document.querySelectorAll('#dataTable.premium-table tbody tr').forEach(row => {
            if (row.cells.length < 2) return;

            if (mobileQuery.matches) {
                setMobileActions(row);
            } else {
                restoreDesktopActions(row);
            }
        });
    }

    function observeTable() {
        const table = document.querySelector('#dataTable.premium-table');
        if (!table || !table.tBodies[0]) return;

        new MutationObserver(() => {
            window.requestAnimationFrame(updateListActions);
        }).observe(table.tBodies[0], {
            childList: true,
            subtree: true
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateListActions();
        observeTable();
    });

    mobileQuery.addEventListener('change', updateListActions);
})();