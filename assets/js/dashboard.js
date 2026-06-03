function viewDocument(filename, title) {
    document.getElementById('documentViewerTitle').innerHTML = '<i class="fas fa-file-alt me-2"></i>' + title;
    document.getElementById('documentViewerFrame').src = 'uploads/' + encodeURIComponent(filename);
    document.getElementById('documentDownloadBtn').href = 'download.php?file=' + encodeURIComponent(filename);
    
    var viewerModal = new bootstrap.Modal(document.getElementById('documentViewerModal'));
    viewerModal.show();
}

$(document).ready(function() {
    $('#documentViewerModal').on('hidden.bs.modal', function () {
        $('#documentViewerFrame').attr('src', '');
    });
});

// ==========================================
// EMPTY DATA / NO TRANSACTION HANDLER
// ==========================================
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
        msgDiv.innerHTML = `
            <i class="fas fa-inbox opacity-50"></i>
            <span class="text-muted">No transactions recorded for this period.</span>
        `;
        container.appendChild(msgDiv);
        return true; 
    } else {
        canvas.style.display = 'block';
        return false; 
    }
}

// ==========================================
// GLOBAL CHART.JS PROFESSIONAL DEFAULTS
// ==========================================
Chart.defaults.font.family = "'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#858796'; 
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(255, 255, 255, 0.95)';
Chart.defaults.plugins.tooltip.titleColor = '#1e293b';
Chart.defaults.plugins.tooltip.bodyColor = '#475569';
Chart.defaults.plugins.tooltip.borderColor = 'rgba(0, 0, 0, 0.08)';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.padding = 12;
Chart.defaults.plugins.tooltip.boxPadding = 6;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.displayColors = true;
Chart.defaults.plugins.tooltip.intersect = false;
Chart.defaults.animation.duration = 1000;
Chart.defaults.animation.easing = 'easeOutQuart';


document.addEventListener("DOMContentLoaded", function() {
    
    // ==========================================
    // SLEEK FLATPICKR INITIALIZATION
    // ==========================================
    if(document.getElementById('customDateRange')) {
        const startInput = document.getElementById('startDate');
        const endInput = document.getElementById('endDate');
        
        let defaultDates = [];
        if (startInput.value && endInput.value) {
            defaultDates = [startInput.value, endInput.value];
        }

        flatpickr("#customDateRange", {
            mode: "range",
            altInput: true,
            altFormat: "M d, Y", // Clean display: May 01, 2026 to May 20, 2026
            dateFormat: "Y-m-d", // System format
            defaultDate: defaultDates,
            showMonths: 1, 
            animate: true,
            onChange: function(selectedDates, dateStr, instance) {
                // Hintay maging dalawa (Start and End) ang napiling date bago isubmit
                if (selectedDates.length === 2) {
                    const start = flatpickr.formatDate(selectedDates[0], "Y-m-d"); 
                    const end = flatpickr.formatDate(selectedDates[1], "Y-m-d");   
                    
                    startInput.value = start;
                    endInput.value = end;
                    
                    document.getElementById('periodFilterForm').submit();
                }
            }
        });
    }

    // ==========================================
    // API URL BUILDER (INCLUDES START & END)
    // ==========================================
    const urlParams = new URLSearchParams(window.location.search);
    const currentPeriod = urlParams.get('period') || 'all';
    const startParam = urlParams.get('start') || '';
    const endParam = urlParams.get('end') || '';
    
    function getApiUrl(action) {
        return `api/dss_data.php?action=${action}&period=${currentPeriod}&start=${startParam}&end=${endParam}`;
    }

    // 1. REVENUE FORECAST (Combo Chart: Bar + Dashed Line)
    if(document.getElementById('revenueForecastChart')) {
        fetch(getApiUrl('revenue_forecast')).then(r => r.json()).then(data => {
            if(data.error) return;
            
            const hasData = data.historical && data.historical.labels.length > 0;
            if (handleNoDataState('revenueForecastChart', hasData)) return;

            const ctx = document.getElementById('revenueForecastChart').getContext('2d');
            
            let gradientBlue = ctx.createLinearGradient(0, 0, 0, 400);
            gradientBlue.addColorStop(0, 'rgba(67, 97, 238, 0.7)');
            gradientBlue.addColorStop(1, 'rgba(67, 97, 238, 0.1)');

            const allLabels = [...data.historical.labels, ...data.forecast.labels];
            const nullPadding = new Array(data.historical.data.length - 1).fill(null);
            const lastHistorical = data.historical.data[data.historical.data.length - 1];
            const forecastData = [...nullPadding, lastHistorical, ...data.forecast.data];
            
            new Chart(ctx, { 
                type: 'bar', 
                data: { 
                    labels: allLabels, 
                    datasets: [
                        { 
                            type: 'bar',
                            label: 'Actual Revenue', 
                            data: data.historical.data, 
                            backgroundColor: gradientBlue, 
                            borderRadius: { topLeft: 6, topRight: 6 },
                            borderWidth: 0
                        }, 
                        { 
                            type: 'line',
                            label: 'Predicted Forecast', 
                            data: forecastData, 
                            borderColor: '#f72585', 
                            backgroundColor: 'transparent',
                            borderDash: [5, 5], 
                            pointBackgroundColor: '#f72585',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            fill: false, 
                            tension: 0.4 
                        }
                    ] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, padding: 20 } }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { 
                            grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)', drawBorder: false },
                            beginAtZero: true
                        }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 2. TOP CLIENTS (Polar Area Chart)
        fetch(getApiUrl('top_clients')).then(r => r.json()).then(data => {
            if(data.error) return;

            const hasData = data.labels && data.labels.length > 0;
            if (handleNoDataState('topClientsChart', hasData)) return;

            const ctx = document.getElementById('topClientsChart').getContext('2d');
            const polarColors = [
                'rgba(67, 97, 238, 0.75)', 
                'rgba(58, 12, 163, 0.75)', 
                'rgba(76, 201, 240, 0.75)', 
                'rgba(247, 37, 133, 0.75)', 
                'rgba(114, 9, 183, 0.75)'
            ];

            new Chart(ctx, { 
                type: 'polarArea', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Total Revenue', 
                        data: data.data, 
                        backgroundColor: polarColors,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } 
                    },
                    scales: {
                        r: { display: false }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 3. TOP CATEGORIES (Radar Chart)
        fetch(getApiUrl('top_categories')).then(r => r.json()).then(data => {
            if(data.error) return;

            const hasData = data.labels && data.labels.length > 0 && !isDataEmpty(data.data);
            if (handleNoDataState('topCategoriesChart', hasData)) return;

            const ctx = document.getElementById('topCategoriesChart').getContext('2d');
            
            new Chart(ctx, { 
                type: 'radar', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Sales Revenue',
                        data: data.data, 
                        backgroundColor: 'rgba(67, 97, 238, 0.2)', 
                        borderColor: '#4361ee',
                        pointBackgroundColor: '#f72585',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        borderWidth: 2, 
                        fill: true
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        r: { 
                            angleLines: { color: 'rgba(0,0,0,0.1)' },
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            pointLabels: { font: { size: 11 }, color: '#475569' },
                            ticks: { display: false }
                        }
                    }
                } 
            });
        }).catch(e => console.error(e));
    }

    // 4. BOTTLENECK ANALYSIS (Smooth Area Flow)
    if(document.getElementById('bottleneckChart')) {
        fetch(getApiUrl('bottleneck_analysis')).then(r => r.json()).then(data => {
            if(data.error) return;

            const hasData = data.labels && data.labels.length > 0 && !isDataEmpty(data.data);
            if (handleNoDataState('bottleneckChart', hasData)) return;

            const ctx = document.getElementById('bottleneckChart').getContext('2d');
            
            let areaGradient = ctx.createLinearGradient(0, 0, 0, 300);
            areaGradient.addColorStop(0, 'rgba(239, 35, 60, 0.3)');
            areaGradient.addColorStop(1, 'rgba(239, 35, 60, 0.0)');

            new Chart(ctx, { 
                type: 'line', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Avg. Hours', 
                        data: data.data, 
                        backgroundColor: areaGradient,
                        borderColor: '#ef233c',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.5, 
                        pointBackgroundColor: '#ef233c',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)' }, beginAtZero: true }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 5. WORKLOAD DISTRIBUTION (Exploded Radial)
        fetch(getApiUrl('workload_distribution')).then(r => r.json()).then(data => {
            if(data.error) return;

            const hasData = data.labels && data.labels.length > 0 && !isDataEmpty(data.data);
            if (handleNoDataState('workloadChart', hasData)) return;

            const ctx = document.getElementById('workloadChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Pending Documents', 
                        data: data.data, 
                        backgroundColor: ['#20c997', '#0dcaf0', '#ffc107', '#6f42c1'], 
                        borderColor: 'transparent',
                        borderWidth: 0
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    cutout: '80%', 
                    spacing: 8,    
                    borderRadius: 20, 
                    plugins: { 
                        legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } 
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 6. REJECTION RATE (Gauge / Speedometer Chart)
        fetch(getApiUrl('rejection_rate')).then(r => r.json()).then(data => {
            if(data.error) return;

            const hasData = data.data && !isDataEmpty(data.data);
            if (handleNoDataState('rejectionChart', hasData)) return;

            const ctx = document.getElementById('rejectionChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        data: data.data, 
                        backgroundColor: ['#2a9d8f', '#ef233c'], 
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    rotation: -90,      
                    circumference: 180, 
                    cutout: '75%',      
                    borderRadius: 5,
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
                    }
                } 
            });
        }).catch(e => console.error(e));
    }
});