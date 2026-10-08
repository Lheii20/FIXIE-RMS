(() => {
    'use strict';

    const explanations = Object.freeze({
        adminTrafficChart: 'Shows how many actions the system recorded each day, for up to 14 days. A busy day does not always mean there is a problem.',
        adminRolesChart: 'Shows how many active users have each role. Changing the date filter does not change these counts.',
        adminActiveUsersChart: 'Shows which users made the most recorded actions during the selected dates. More actions do not mean better work.',
        adminRequestsChart: 'Shows account requests by their current status during the selected dates.',
        adminDisposalActionChart: 'Shows what the retention rules say should happen to records in Ready for Disposition. No file is deleted just because it appears here.',
        adminDisposalHistoryChart: 'Shows recorded archive and delete actions. It does not confirm that a paper copy was destroyed.',

        gmActivityTrendChart: 'Shows daily activity for purchase orders, files, payments or delivery, approvals, and requests or quotations. An activity count does not mean the work is finished.',
        gmVolumeChart: 'Shows how many files are in each record category during the selected dates.',
        gmTurnaroundChart: 'Shows the average time from the first purchase order update to each step. Each bar includes earlier steps, not just that one step.',
        gmLifecycleChart: 'Shows how many records are active, archived, or ready for disposition during the selected dates.',
        gmRetrievalChart: 'Shows how often files were viewed or downloaded. A frequently opened file is not always more important. If a file was renamed, its counts may be split.',
        gmDisposalActionChart: 'Shows what the retention rules say should happen to records in Ready for Disposition. Each file still needs a review and decision.',
        gmDisposalHistoryChart: 'Shows recorded archive and delete actions. Open the record and its proof to confirm what happened.',

        finRevenueChart: 'Shows the value of approved purchase orders for the last 12 full months. The dotted line, when shown, is only an estimate for the next three months. It is not money received.',
        finCashflowChart: 'Shows client payments and money released to suppliers each month. Other company expenses are not included.',
        finMomChart: 'Shows whether the value of approved purchase orders went up or down from one month to the next. If the earlier month was zero, a percentage change cannot be shown.',
        finTopClientsRadarChart: 'Shows payments received and unpaid balances for clients with delivered purchase orders during the selected dates.',

        procTrendChart: 'Shows how many purchase orders were created each day during the selected dates.',
        procStatusChart: 'Shows how many purchase orders are in each current status during the selected dates.',
        procCategoryChart: 'Shows which product categories have the highest purchase order value. This is not money already paid to suppliers.',
        procBrandChart: 'Shows purchase order value by brand, except Generic/Other. This is not money already paid to suppliers.',

        scDeliveryTrendChart: 'Shows how many purchase orders were marked delivered each day during the selected dates.',
        scStatusChart: 'Shows purchase orders waiting for delivery, scheduled for handover, or already delivered.',
        scClientChart: 'Shows which clients have the most delivered purchase orders during the selected dates. It does not show their order value.',
        scProofChart: 'Shows delivered order counts and orders with proof files. The two counts use different dates, so they do not show the exact number of delivered orders with proof.',

        salesTrendChart: 'Shows how many purchase requests were sent and approved each day. Open the request list to check a specific request.',
        salesPrStatusChart: 'Shows how many purchase requests are in each current status during the selected dates.',
        salesTopCatChart: 'Shows the number of items requested in each category, excluding rejected requests. It does not show their price.',
        salesTopClientsChart: 'Shows which clients have the most purchase requests during the selected dates. It does not show sales or payments.'
    });

    // Suggestions only. Users must check the individual records before acting.
    const decisionGuides = Object.freeze({
        adminTrafficChart: 'If one day looks unusually busy, open the Audit Trail to see what happened. Check the accounts involved before changing access.',
        adminRolesChart: 'If a role has too much pending work, check who can take it. You may need to assign another user.',
        adminActiveUsersChart: 'If a user has unusual activity, check their actions in the Audit Trail. Do not judge their work by the count alone.',
        adminRequestsChart: 'Open pending requests. Check who asked and what access they need before approving or denying each one.',
        adminDisposalActionChart: 'Open each file in Ready for Disposition. The authorized reviewer should check its retention rule before choosing the allowed action.',
        adminDisposalHistoryChart: 'For any archive or deletion you need to check, open the record and its supporting proof.',

        gmActivityTrendChart: 'If activity suddenly rises or falls, check the related approval and file lists. Then decide if work needs to be reassigned.',
        gmVolumeChart: 'Check categories with many files first. They may need more filing help or a retention review.',
        gmTurnaroundChart: 'Open purchase orders that took the longest. Find where the delay happened before changing the approval or delivery process.',
        gmLifecycleChart: 'If archived or due-for-disposition files are piling up, schedule a review. Keep active files easy to find.',
        gmRetrievalChart: 'If a file is opened often, check whether staff can find it easily. Open its history before deciding it is important.',
        gmDisposalActionChart: 'Open each due file and check its retention rule. Choose the allowed action for that file; do not act from this chart alone.',
        gmDisposalHistoryChart: 'Before confirming a disposal is complete, check the file history and any disposal proof.',

        finRevenueChart: 'If approved order value is rising, plan for possible work and purchases. Check actual payments before spending money.',
        finCashflowChart: 'If more money went to suppliers than came from clients, check upcoming payments and collections. Include other expenses before making a budget decision.',
        finMomChart: 'If order value keeps rising or falling, open the orders to find out why before changing targets or purchase plans.',
        finTopClientsRadarChart: 'Start with clients who owe the most. Check each order due date and payment history before following up.',

        procTrendChart: 'If many orders arrive on certain days, plan enough staff time to review them and follow up on unfinished ones.',
        procStatusChart: 'If many orders stay in one status, open that list. Find what each order needs next before changing the process.',
        procCategoryChart: 'For categories with high order value, review supplier options. Check quantities and available funds before ordering more.',
        procBrandChart: 'If many orders depend on one brand, check whether approved alternatives are available. Check actual supplier payments separately.',

        scDeliveryTrendChart: 'If deliveries are busiest on certain days, check scheduled orders and staff availability before planning more handovers.',
        scStatusChart: 'Open orders waiting for delivery or handover. Check which ones are ready for the next step.',
        scClientChart: 'For clients with many deliveries, coordinate schedules with them. Do not treat the order count as sales value.',
        scProofChart: 'Open delivered orders one by one to find any missing proof. Ask for the needed file before marking the record complete.',

        salesTrendChart: 'If new requests are arriving faster than approvals, open pending requests and follow up with their reviewers.',
        salesPrStatusChart: 'Open pending or returned requests first. Read the reason before editing or sending one again.',
        salesTopCatChart: 'If many items are requested in one category, confirm the quantities with Procurement before planning stock or suppliers.',
        salesTopClientsChart: 'Follow up with clients who request often. Check their orders and payments before calling them top-paying clients.'
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
        const decisionLabel = document.createElement('span');
        decisionLabel.className = 'drms-chart-decision-label';
        decisionLabel.textContent = 'Possible decision';
        const decisionText = document.createElement('p');
        decisionText.className = 'drms-chart-decision-text';
        popover.append(heading, description, decisionLabel, decisionText);
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

        function open(button, title, text, decision) {
            if (activeButton) close();
            activeButton = button;
            button.setAttribute('aria-expanded', 'true');
            heading.textContent = title;
            description.textContent = text;
            decisionText.textContent = decision;
            popover.setAttribute('aria-label', `About ${title}`);
            popover.hidden = false;
            position(button);
            requestAnimationFrame(() => {
                if (activeButton === button) popover.classList.add('is-open');
            });
        }

        canvases.forEach((canvas) => {
            if (!Object.prototype.hasOwnProperty.call(explanations, canvas.id) || !Object.prototype.hasOwnProperty.call(decisionGuides, canvas.id)) return;
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
                    open(button, title, explanations[canvas.id], decisionGuides[canvas.id]);
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
