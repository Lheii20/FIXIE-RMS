/* Presentation-only palette for Chart.js dashboard canvases. */
(() => {
    'use strict';

    if (!window.Chart || document.documentElement.dataset.drmsTheme !== 'dark') return;

    const ink = '#b8c5d3';
    const grid = '#3d4652';
    const surface = '#282f38';

    const applyPalette = options => {
        if (!options || typeof options !== 'object') return;
        options.color = ink;
        if (!options.plugins || typeof options.plugins !== 'object') options.plugins = {};

        const legend = options.plugins.legend;
        if (legend && typeof legend === 'object') {
            if (!legend.labels || typeof legend.labels !== 'object') legend.labels = {};
            legend.labels.color = ink;
        }

        const tooltip = options.plugins.tooltip;
        if (tooltip !== false) {
            const tooltipOptions = tooltip && typeof tooltip === 'object' ? tooltip : {};
            tooltipOptions.backgroundColor = surface;
            tooltipOptions.titleColor = '#d4dce5';
            tooltipOptions.bodyColor = ink;
            tooltipOptions.borderColor = '#566171';
            tooltipOptions.borderWidth = 1;
            options.plugins.tooltip = tooltipOptions;
        }

        if (options.scales && typeof options.scales === 'object') {
            Object.values(options.scales).forEach(scale => {
                if (!scale || typeof scale !== 'object') return;
                if (!scale.ticks || typeof scale.ticks !== 'object') scale.ticks = {};
                scale.ticks.color = ink;
                if (!scale.grid || typeof scale.grid !== 'object') scale.grid = {};
                scale.grid.color = grid;
                if (scale.pointLabels && typeof scale.pointLabels === 'object') {
                    scale.pointLabels.color = ink;
                }
                if (scale.angleLines && typeof scale.angleLines === 'object') {
                    scale.angleLines.color = grid;
                }
                if (scale.title && typeof scale.title === 'object') {
                    scale.title.color = ink;
                }
            });
        }
    };

    window.Chart.register({
        id: 'drmsDashboardDarkPalette',
        beforeInit(chart) {
            applyPalette(chart.config.options);
            applyPalette(chart.options);

            if (['pie', 'doughnut', 'polarArea'].includes(chart.config.type)) {
                (chart.data.datasets || []).forEach(dataset => {
                    if (dataset.borderColor === '#fff' || dataset.borderColor === '#ffffff') {
                        dataset.borderColor = '#1f252d';
                    }
                });
            }
        }
    });
})();
