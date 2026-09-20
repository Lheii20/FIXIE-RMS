(() => {
    'use strict';

    if (window.DRMSSkeleton) return;

    const line = (className = '') =>
        `<span class="drms-skeleton-line ${className}" aria-hidden="true"></span>`;

    const textAlternative = (label) =>
        `<span class="visually-hidden">${label}</span>`;

    const lines = (label = 'Loading content') =>
        `<div class="drms-skeleton-surface" role="status" aria-live="polite">
            ${textAlternative(label)}
            <div class="drms-skeleton-stack" aria-hidden="true">
                ${line('is-title')}${line()}${line('is-medium')}${line()}${line('is-short')}
            </div>
        </div>`;

    const table = (label = 'Loading records', rows = 5) => {
        const row = `<div class="drms-skeleton-table-row" aria-hidden="true">
            <div class="drms-skeleton-record">
                <span class="drms-skeleton-block drms-skeleton-record-icon"></span>
                <span class="drms-skeleton-record-copy">${line('is-medium')}${line('is-short')}</span>
            </div>
            <span class="drms-skeleton-stack">${line('is-medium')}${line('is-short')}</span>
            <span class="drms-skeleton-chip"></span>
            <span class="drms-skeleton-block"></span>
        </div>`;
        return `<div class="drms-skeleton-surface" role="status" aria-live="polite">
            ${textAlternative(label)}
            <div class="drms-skeleton-table">${row.repeat(Math.max(1, rows))}</div>
        </div>`;
    };

    const tree = (label = 'Loading storage locations', rows = 7) => {
        const row = `<span class="drms-skeleton-tree-row" aria-hidden="true">
            <span class="drms-skeleton-block"></span><span class="drms-skeleton-block"></span><span class="drms-skeleton-block"></span>
        </span>`;
        return `<div class="drms-skeleton-tree" role="status" aria-live="polite">
            ${textAlternative(label)}${row.repeat(Math.max(1, rows))}
        </div>`;
    };

    const profile = (label = 'Loading physical record') => {
        const card = `<span class="drms-skeleton-profile-card" aria-hidden="true">${line('is-short')}${line('is-medium')}</span>`;
        return `<div class="drms-skeleton-surface" role="status" aria-live="polite">
            ${textAlternative(label)}
            <div class="drms-skeleton-profile">
                <div class="drms-skeleton-stack" aria-hidden="true">${line('is-title')}${line('is-medium')}</div>
                <div class="drms-skeleton-profile-grid">${card.repeat(6)}</div>
            </div>
        </div>`;
    };

    const preview = (label = 'Loading document preview') =>
        `<div class="drms-skeleton-surface" role="status" aria-live="polite">
            ${textAlternative(label)}
            <div class="drms-skeleton-preview" aria-hidden="true">
                ${line()}${line()}<span class="drms-skeleton-block"></span>
            </div>
        </div>`;

    window.DRMSSkeleton = Object.freeze({ lines, table, tree, profile, preview });
})();
