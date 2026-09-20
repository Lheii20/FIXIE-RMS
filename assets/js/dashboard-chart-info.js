(() => {
    'use strict';

    const explanations = Object.freeze({
        adminTrafficChart: 'Shows recorded system actions by day for up to 14 dates in the selected range. A spike in activity is a signal to review the audit log, not proof of a security incident.',
        adminRolesChart: 'Shows how active user accounts are distributed across roles. This is an account snapshot and does not follow the dashboard date filter.',
        adminActiveUsersChart: 'Ranks users by audit-log action count in the selected period. More actions do not automatically mean better performance.',
        adminRequestsChart: 'Shows user account requests grouped by current status within the selected period.',
        adminDisposalActionChart: 'Groups records marked Ready for Disposition by the action assigned in their retention policy. These are not yet approved destruction actions.',
        adminDisposalHistoryChart: 'Shows archive and delete-related audit events over recent recorded dates. This is an event history, not proof that a physical copy was destroyed.',

        gmActivityTrendChart: 'Compares recorded daily PO, document, payment or fulfilment, approval, and PR or quotation activity. Activity volume does not by itself mean a transaction is complete.',
        gmVolumeChart: 'Shows the distribution of registered documents across record categories in the selected period.',
        gmTurnaroundChart: 'Shows average elapsed hours from the first recorded PO transition to each displayed stage. It does not isolate the time spent inside that one stage.',
        gmLifecycleChart: 'Compares current document counts for active, archived, and ready-for-disposition states in the selected period.',
        gmRetrievalChart: 'Counts recorded view and download events using audit-log descriptions. Frequent access can indicate activity, but does not prove a record is more important; renamed records may appear separately.',
        gmDisposalActionChart: 'Groups records marked Ready for Disposition by the action assigned in their retention policy. Each record still needs an authorized decision.',
        gmDisposalHistoryChart: 'Shows archive and delete-related audit events over recent recorded dates. Check the record and certificate for the actual disposition outcome.',

        finRevenueChart: 'Shows approved PO value for the last 12 completed months. When enough history exists, the dotted line estimates the next three months; it is not collected revenue.',
        finCashflowChart: 'Compares recorded client payments with supplier fund releases by month. It excludes other company expenses, so it is not a complete cash-flow statement.',
        finMomChart: 'Shows month-to-month percentage change in approved PO value. A month with zero value cannot be used as a percentage baseline.',
        finTopClientsRadarChart: 'Compares recorded payments and outstanding balances for the leading delivered-PO clients in the selected period.',

        procTrendChart: 'Shows how many purchase orders were created on each recorded day in the selected period.',
        procStatusChart: 'Shows purchase orders grouped by their current workflow status for the selected period.',
        procCategoryChart: 'Ranks product categories by PO line-item value. This is order value, not verified supplier spending or cash released.',
        procBrandChart: 'Compares PO line-item value by brand, excluding Generic/Other. It does not represent amounts already paid to suppliers.',

        scDeliveryTrendChart: 'Shows the daily count of recorded completed PO deliveries in the selected period.',
        scStatusChart: 'Shows purchase orders currently in delivery-requested, scheduled handoff, or delivered status.',
        scClientChart: 'Ranks clients by the number of delivered POs in the selected period, not by order value.',
        scProofChart: 'Compares delivered-PO counts with POs that have active proof files. The counts use different event dates, so open individual POs to verify true proof coverage.',

        salesTrendChart: 'Compares submitted and approved Purchase Request counts by recorded day. Use the PR list to inspect individual requests.',
        salesPrStatusChart: 'Shows Purchase Requests grouped by their current status in the selected period.',
        salesTopCatChart: 'Shows requested item quantity by category for non-rejected PRs. It measures units, not peso value.',
        salesTopClientsChart: 'Ranks clients by Purchase Request transaction count in the selected period, not by sales or collections.'
    });

    function initialize() {
        const canvases = document.querySelectorAll('.corp-widget .chart-box canvas[id]');
        if (!canvases.length) return;

        const popover = document.createElement('div');
        popover.id = 'dashboardChartInfo';
        popover.className = 'drms-chart-info-popover';
        popover.setAttribute('role', 'region');
        popover.setAttribute('aria-label', 'Chart explanation');
        popover.hidden = true;

        const heading = document.createElement('strong');
        const description = document.createElement('p');
        popover.append(heading, description);
        document.body.appendChild(popover);

        let activeButton = null;

        function close(restoreFocus = false) {
            if (!activeButton) return;
            const previousButton = activeButton;
            activeButton = null;
            previousButton.setAttribute('aria-expanded', 'false');
            popover.classList.remove('is-open');
            popover.hidden = true;
            if (restoreFocus) previousButton.focus();
        }

        function position(button) {
            const anchor = button.getBoundingClientRect();
            const panel = popover.getBoundingClientRect();
            const gutter = 12;
            const preferredLeft = anchor.right - panel.width;
            const left = Math.max(gutter, Math.min(preferredLeft, window.innerWidth - panel.width - gutter));
            const below = anchor.bottom + 8;
            const above = anchor.top - panel.height - 8;
            const top = below + panel.height <= window.innerHeight - gutter
                ? below
                : (above >= gutter ? above : Math.max(gutter, window.innerHeight - panel.height - gutter));
            popover.style.left = `${left}px`;
            popover.style.top = `${top}px`;
        }

        function open(button, title, text) {
            if (activeButton) close();
            activeButton = button;
            button.setAttribute('aria-expanded', 'true');
            heading.textContent = title;
            description.textContent = text;
            popover.setAttribute('aria-label', `About ${title}`);
            popover.hidden = false;
            position(button);
            requestAnimationFrame(() => {
                if (activeButton === button) popover.classList.add('is-open');
            });
        }

        canvases.forEach((canvas) => {
            if (!Object.prototype.hasOwnProperty.call(explanations, canvas.id)) return;
            const widget = canvas.closest('.corp-widget');
            const header = widget && widget.querySelector('.corp-widget-header');
            const titleElement = header && header.querySelector('.corp-widget-title');
            if (!header || !titleElement || header.querySelector('.drms-chart-info-toggle')) return;

            const title = titleElement.textContent.trim().replace(/\s+/g, ' ');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'drms-chart-info-toggle';
            button.textContent = 'i';
            button.setAttribute('aria-label', `About ${title}`);
            button.setAttribute('aria-controls', popover.id);
            button.setAttribute('aria-expanded', 'false');
            header.classList.add('drms-chart-info-header');
            header.appendChild(button);

            button.addEventListener('click', () => {
                if (activeButton === button) {
                    close();
                } else {
                    open(button, title, explanations[canvas.id]);
                }
            });
        });

        document.addEventListener('pointerdown', (event) => {
            if (activeButton && !activeButton.contains(event.target) && !popover.contains(event.target)) {
                close();
            }
        });
        document.addEventListener('focusin', (event) => {
            if (activeButton && !activeButton.contains(event.target) && !popover.contains(event.target)) {
                close();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && activeButton) {
                close(true);
                event.preventDefault();
            }
        });
        document.addEventListener('scroll', (event) => {
            if (activeButton && !popover.contains(event.target)) close();
        }, true);
        window.addEventListener('resize', () => close());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
