function isDataEmpty(dataArr) {
    if (!dataArr || dataArr.length === 0) return true;
    const sum = dataArr.reduce((acc, val) => acc + Number(val), 0);
    return sum === 0;
}

function handleNoDataState(chartId, hasData) {
    const canvas = document.getElementById(chartId);
    if (!canvas) return true;
    const container = canvas.parentElement;
    const existingMsg = container.querySelector('.no-data-message');
    if (existingMsg) existingMsg.remove();
    if (!hasData) {
        canvas.style.display = 'none';
        const msgDiv = document.createElement('div');
        msgDiv.className = 'no-data-message';
        msgDiv.innerHTML = `<i class="fas fa-inbox"></i><span>No records found for this period.</span>`;
        container.appendChild(msgDiv);
        return true; 
    } else {
        canvas.style.display = 'block';
        return false; 
    }
}

Chart.defaults.font.family = "'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
Chart.defaults.font.size = 13;
Chart.defaults.color = '#64748b'; 
Chart.defaults.plugins.tooltip.backgroundColor = '#ffffff';
Chart.defaults.plugins.tooltip.titleColor = '#0f172a';
Chart.defaults.plugins.tooltip.bodyColor = '#475569';
Chart.defaults.plugins.tooltip.borderColor = '#e2e8f0';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.padding = 12;
Chart.defaults.plugins.tooltip.boxPadding = 6;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.displayColors = true;
Chart.defaults.plugins.tooltip.intersect = false;
Chart.defaults.animation.duration = 1000;
Chart.defaults.animation.easing = 'easeOutQuart';


document.addEventListener("DOMContentLoaded", function() {
    
    // Gagamitin ng API ang mga current params na naka-set sa URL base sa pinindot na Filter
    const urlParams = new URLSearchParams(window.location.search);
    const currentPeriod = urlParams.get('period') || 'all';
    const startParam = urlParams.get('start') || '';
    const endParam = urlParams.get('end') || '';
    function getApiUrl(action) { return `api/dss_data.php?action=${action}&period=${currentPeriod}&start=${startParam}&end=${endParam}`; }

    if(document.getElementById('revenueForecastChart')) {
        fetch(getApiUrl('revenue_forecast')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('revenueForecastChart', data.historical && data.historical.labels.length > 0)) return;
            const ctx = document.getElementById('revenueForecastChart').getContext('2d');
            let gradientArea = ctx.createLinearGradient(0, 0, 0, 350);
            gradientArea.addColorStop(0, 'rgba(37, 99, 235, 0.4)'); 
            gradientArea.addColorStop(1, 'rgba(37, 99, 235, 0.01)');
            
            const allLabels = [...data.historical.labels, ...data.forecast.labels];
            const nullPadding = new Array(data.historical.data.length - 1).fill(null);
            const lastHistorical = data.historical.data[data.historical.data.length - 1];
            const forecastData = [...nullPadding, lastHistorical, ...data.forecast.data];
            
            new Chart(ctx, { 
                type: 'line', 
                data: { labels: allLabels, datasets: [
                    { label: 'System Revenue', data: data.historical.data, borderColor: '#2563eb', backgroundColor: gradientArea, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#2563eb', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }, 
                    { label: 'Predictive Forecast', data: forecastData, borderColor: '#94a3b8', backgroundColor: 'transparent', borderDash: [5, 5], borderWidth: 2, fill: false, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#94a3b8', pointBorderWidth: 2, pointRadius: 4 }
                ]}, 
                options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, padding: 20 } } }, scales: { x: { grid: { display: false } }, y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, beginAtZero: true, ticks: { callback: function(value) { return '₱' + value.toLocaleString(); } } } } } 
            });
        });
    }

    if(document.getElementById('topClientsChart')) {
        fetch(getApiUrl('top_clients')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('topClientsChart', data.labels && data.labels.length > 0)) return;
            const ctx = document.getElementById('topClientsChart').getContext('2d');
            new Chart(ctx, { 
                type: 'bar', 
                data: { labels: data.labels, datasets: [{ label: 'Revenue', data: data.data, backgroundColor: '#0ea5e9', borderRadius: 6, barPercentage: 0.6 }] }, 
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, ticks: { callback: function(value) { return '₱' + value; } } }, y: { grid: { display: false } } } } 
            });
        });
    }

    if(document.getElementById('topCategoriesChart')) {
        fetch(getApiUrl('top_categories')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('topCategoriesChart', data.labels && data.labels.length > 0 && !isDataEmpty(data.data))) return;
            const ctx = document.getElementById('topCategoriesChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#2563eb', '#0ea5e9', '#06b6d4', '#14b8a6', '#10b981', '#64748b'], borderWidth: 0 }] }, 
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 5, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 20 } }, tooltip: { callbacks: { label: function(context) { return ' ₱' + context.raw.toLocaleString(); } } } } } 
            });
        });
    }

    if(document.getElementById('bottleneckChart')) {
        fetch(getApiUrl('bottleneck_analysis')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('bottleneckChart', data.labels && data.labels.length > 0 && !isDataEmpty(data.data))) return;
            const ctx = document.getElementById('bottleneckChart').getContext('2d');
            let gradientArea = ctx.createLinearGradient(0, 0, 0, 350);
            gradientArea.addColorStop(0, 'rgba(139, 92, 246, 0.4)'); 
            gradientArea.addColorStop(1, 'rgba(139, 92, 246, 0.01)');
            new Chart(ctx, { 
                type: 'line', 
                data: { labels: data.labels, datasets: [{ label: 'Avg. Hours Delayed', data: data.data, backgroundColor: gradientArea, borderColor: '#8b5cf6', borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#8b5cf6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }] }, 
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { return ' ' + context.raw + ' hrs'; } } } }, scales: { x: { grid: { display: false } }, y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, beginAtZero: true } } } 
            });
        });
    }

    if(document.getElementById('workloadChart')) {
        fetch(getApiUrl('workload_distribution')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('workloadChart', data.labels && data.labels.length > 0 && !isDataEmpty(data.data))) return;
            const ctx = document.getElementById('workloadChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#0f172a', '#334155', '#475569', '#64748b', '#94a3b8'], borderWidth: 0 }] }, 
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 4, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } } 
            });
        });
    }

    if(document.getElementById('rejectionChart')) {
        fetch(getApiUrl('rejection_rate')).then(r => r.json()).then(data => {
            if(data.error) return;
            if (handleNoDataState('rejectionChart', data.data && !isDataEmpty(data.data))) return;
            const ctx = document.getElementById('rejectionChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#10b981', '#e2e8f0'], hoverBackgroundColor: ['#059669', '#cbd5e1'], borderWidth: 0 }] }, 
                options: { responsive: true, maintainAspectRatio: false, rotation: -90, circumference: 180, cutout: '80%', borderRadius: 10, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } } 
            });
        });
    }

    if(document.getElementById('docRetentionChart')) {
        fetch(getApiUrl('doc_retention_status')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('docRetentionChart', data.data && !isDataEmpty(data.data))) return;
            const ctx = document.getElementById('docRetentionChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#3b82f6', '#94a3b8', '#f43f5e'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 5, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } }
            });
        });
    }

    if(document.getElementById('adminUserRolesChart')) {
        fetch(getApiUrl('admin_user_roles')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('adminUserRolesChart', data.labels && data.labels.length > 0)) return;
            new Chart(document.getElementById('adminUserRolesChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#2563eb', '#4f46e5', '#8b5cf6', '#d946ef', '#f43f5e'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 4, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 20 } } } }
            });
        });
    }

    if(document.getElementById('adminAuditChart')) {
        fetch(getApiUrl('admin_audit_activity')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('adminAuditChart', data.labels && data.labels.length > 0)) return;
            let ctx = document.getElementById('adminAuditChart').getContext('2d');
            let gradient = ctx.createLinearGradient(0,0,0,300);
            gradient.addColorStop(0, 'rgba(14, 165, 233, 0.4)'); gradient.addColorStop(1, 'rgba(14, 165, 233, 0.01)');
            new Chart(ctx, {
                type: 'line',
                data: { labels: data.labels, datasets: [{ label: 'System Audits', data: data.data, borderColor: '#0ea5e9', backgroundColor: gradient, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#0ea5e9', pointBorderWidth: 2, pointRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' } }, x: { grid: { display: false } } } }
            });
        });
    }

    if(document.getElementById('salesPrStatusChart')) {
        fetch(getApiUrl('sales_pr_status')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('salesPrStatusChart', data.labels && data.labels.length > 0)) return;
            new Chart(document.getElementById('salesPrStatusChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#64748b'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 4, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } }
            });
        });
    }

    if(document.getElementById('salesPerformanceChart')) {
        fetch(getApiUrl('sales_performance')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('salesPerformanceChart', data.labels && data.labels.length > 0)) return;
            new Chart(document.getElementById('salesPerformanceChart').getContext('2d'), {
                type: 'bar',
                data: { labels: data.labels, datasets: [{ label: 'PRs Created', data: data.data, backgroundColor: '#10b981', borderRadius: 6 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { borderDash: [4, 4], color: '#f1f5f9' } }, x: { grid: { display: false } } } }
            });
        });
    }

    if(document.getElementById('procPoStatusChart')) {
        fetch(getApiUrl('proc_po_status')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('procPoStatusChart', data.labels && data.labels.length > 0)) return;
            new Chart(document.getElementById('procPoStatusChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: ['#f59e0b', '#06b6d4', '#10b981', '#3b82f6', '#ef4444'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '75%', borderRadius: 4, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 20 } } } }
            });
        });
    }

    if(document.getElementById('docsCategoryChart')) {
        fetch(getApiUrl('docs_category')).then(r=>r.json()).then(data=>{
            if(data.error) return;
            if(handleNoDataState('docsCategoryChart', data.labels && data.labels.length > 0)) return;
            new Chart(document.getElementById('docsCategoryChart').getContext('2d'), {
                type: 'bar',
                data: { labels: data.labels, datasets: [{ label: 'Active Documents', data: data.data, backgroundColor: '#8b5cf6', borderRadius: 4 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { borderDash: [4, 4], color: '#f1f5f9' } }, y: { grid: { display: false } } } }
            });
        });
    }
});