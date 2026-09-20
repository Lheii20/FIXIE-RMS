(() => {
    'use strict';
    const tableElement = document.getElementById('usersTable');
    if (!tableElement || !window.jQuery) return;
    let latest = null;
    let pending = false;
    let lastSuccess = Date.now();
    let unavailable = false;

    function rows() {
        if (jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(tableElement)) {
            return jQuery(tableElement).DataTable().rows().nodes().toArray();
        }
        return Array.from(tableElement.querySelectorAll('tbody tr[data-user-id]'));
    }

    function paint() {
        if (unavailable) { paintUnavailable(); return; }
        if (!latest) return;
        rows().forEach((row) => {
            const id = row.dataset.userId;
            if (!Object.prototype.hasOwnProperty.call(latest, id)) return;
            const online = latest[id] === true;
            const dot = row.querySelector('.js-presence-dot');
            const label = row.querySelector('.js-presence-label');
            const mobile = row.querySelector('.js-presence-mobile');
            if (dot) {
                dot.classList.toggle('bg-success', online);
                dot.classList.toggle('bg-secondary', !online);
                dot.setAttribute('aria-label', online ? 'Online' : 'Offline');
            }
            if (label) {
                label.classList.toggle('text-success', online);
                label.classList.toggle('text-secondary', !online);
                label.innerHTML = online
                    ? '<i class="fas fa-circle me-1" aria-hidden="true"></i>Online'
                    : '<i class="far fa-circle me-1" aria-hidden="true"></i>Offline';
            }
            if (mobile) {
                mobile.classList.toggle('is-online', online);
                mobile.textContent = online ? 'Online' : 'Offline';
            }
        });
    }

    function paintUnavailable() {
        rows().forEach((row) => {
            const dot = row.querySelector('.js-presence-dot');
            const label = row.querySelector('.js-presence-label');
            const mobile = row.querySelector('.js-presence-mobile');
            if (dot) {
                dot.classList.remove('bg-success');
                dot.classList.add('bg-secondary');
                dot.setAttribute('aria-label', 'Status unavailable');
            }
            if (label) {
                label.classList.remove('text-success');
                label.classList.add('text-secondary');
                label.textContent = 'Status unavailable';
            }
            if (mobile) {
                mobile.classList.remove('is-online');
                mobile.textContent = 'Unknown';
            }
        });
    }

    async function refresh() {
        if (pending || document.hidden) return;
        pending = true;
        try {
            const response = await fetch('api/user_presence.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data.status !== 'valid' || !data.presence) return;
            latest = data.presence;
            lastSuccess = Date.now();
            unavailable = false;
            paint();
        } catch (_) {
            // Keep the last known state until the next successful poll.
        } finally {
            if (Date.now() - lastSuccess > 60000) {
                unavailable = true;
                paintUnavailable();
            }
            pending = false;
        }
    }

    jQuery(() => {
        jQuery(tableElement).on('draw.dt', paint);
        refresh();
        setInterval(refresh, 15000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    });
})();
