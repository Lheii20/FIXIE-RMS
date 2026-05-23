function openRenewModal(docId, fileName) {
    document.getElementById('renewDocId').value = docId;
    document.getElementById('renewFileName').innerText = fileName;
    var renewModal = new bootstrap.Modal(document.getElementById('renewModal'));
    renewModal.show();
}

function viewDocument(filename, title) {
    document.getElementById('documentViewerTitle').innerHTML = '<i class="fas fa-file-alt me-2"></i>' + title;
    document.getElementById('documentViewerFrame').src = 'uploads/' + encodeURIComponent(filename);
    document.getElementById('documentDownloadBtn').href = 'download.php?file=' + encodeURIComponent(filename);
    
    var viewerModal = new bootstrap.Modal(document.getElementById('documentViewerModal'));
    viewerModal.show();
}

// Custom System Alert Delete function
function confirmDelete(docId, fileName) {
    document.getElementById('deleteDocId').value = docId;
    document.getElementById('deleteFileName').innerText = fileName;
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

function openRetentionTab() {
    var retentionTabBtn = document.getElementById('retention-tab');
    if(retentionTabBtn) {
        var tab = new bootstrap.Tab(retentionTabBtn);
        tab.show();
        window.scrollTo({ top: 300, behavior: 'smooth' });
    }
}

$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'retention') {
        openRetentionTab();
        window.history.replaceState(null, null, window.location.pathname);
    }

    $('#documentViewerModal').on('hidden.bs.modal', function () {
        $('#documentViewerFrame').attr('src', '');
    });

    if($('#retentionTable').length) {
        $('#retentionTable').DataTable({
            "order": [[ 2, "asc" ]],
            "pageLength": 10,
            "language": {
                "search": "Filter Records:"
            }
        });
    }
});

// ==========================================
// GLOBAL CHART.JS PROFESSIONAL DEFAULTS
// ==========================================
Chart.defaults.font.family = "'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#858796'; // Softer text color
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(255, 255, 255, 0.98)';
Chart.defaults.plugins.tooltip.titleColor = '#212529';
Chart.defaults.plugins.tooltip.bodyColor = '#495057';
Chart.defaults.plugins.tooltip.borderColor = 'rgba(0, 0, 0, 0.1)';
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.padding = 12;
Chart.defaults.plugins.tooltip.boxPadding = 6;
Chart.defaults.plugins.tooltip.displayColors = true;
Chart.defaults.plugins.tooltip.intersect = false;

document.addEventListener("DOMContentLoaded", function() {
    
    // 1. REVENUE FORECAST (Line Chart)
    if(document.getElementById('revenueForecastChart')) {
        fetch('api/dss_data.php?action=revenue_forecast').then(r => r.json()).then(data => {
            if(data.error || !data.historical) return;
            const ctx = document.getElementById('revenueForecastChart').getContext('2d');
            
            // Create Gradient for Historical Data
            let gradientBlue = ctx.createLinearGradient(0, 0, 0, 400);
            gradientBlue.addColorStop(0, 'rgba(13, 110, 253, 0.4)');
            gradientBlue.addColorStop(1, 'rgba(13, 110, 253, 0.0)');

            const allLabels = [...data.historical.labels, ...data.forecast.labels];
            const nullPadding = new Array(data.historical.data.length - 1).fill(null);
            const lastHistorical = data.historical.data[data.historical.data.length - 1];
            const forecastData = [...nullPadding, lastHistorical, ...data.forecast.data];
            
            new Chart(ctx, { 
                type: 'line', 
                data: { 
                    labels: allLabels, 
                    datasets: [
                        { 
                            label: 'Actual Revenue', 
                            data: data.historical.data, 
                            borderColor: '#0d6efd', 
                            backgroundColor: gradientBlue, 
                            pointBackgroundColor: '#0d6efd',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            fill: true, 
                            tension: 0.4 
                        }, 
                        { 
                            label: 'Predicted Forecast', 
                            data: forecastData, 
                            borderColor: '#ffc107', 
                            backgroundColor: 'transparent',
                            borderDash: [5, 5], 
                            pointBackgroundColor: '#ffc107',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
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
                        x: { grid: { display: false } }, // Tinanggal ang patayong linya
                        y: { 
                            grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)', drawBorder: false },
                            beginAtZero: true
                        }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 2. TOP CLIENTS (Horizontal Bar Chart)
        fetch('api/dss_data.php?action=top_clients').then(r => r.json()).then(data => {
            if(data.error || !data.labels) return;
            const ctx = document.getElementById('topClientsChart').getContext('2d');
            new Chart(ctx, { 
                type: 'bar', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Total Revenue', 
                        data: data.data, 
                        backgroundColor: 'rgba(13, 110, 253, 0.85)', 
                        hoverBackgroundColor: '#0d6efd',
                        borderRadius: 6, // Mas smooth na kanto
                        barThickness: 18 
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    indexAxis: 'y',
                    plugins: { legend: { display: false } }, // Clean look, no legend needed
                    scales: {
                        x: { grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)' }, beginAtZero: true },
                        y: { grid: { display: false } }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 3. TOP CATEGORIES (Doughnut Chart) - PROFESSIONAL COLOR PALETTE
        fetch('api/dss_data.php?action=top_categories').then(r => r.json()).then(data => {
            if(data.error || !data.labels) return;
            const ctx = document.getElementById('topCategoriesChart').getContext('2d');
            
            // Corporate Spectrum (Shades of Primary, Cyan, Indigo, and accents that blend well)
            const professionalColors = [
                '#0d6efd', // Primary Blue
                '#0dcaf0', // Info Cyan
                '#6f42c1', // Indigo/Purple
                '#20c997', // Teal
                '#6ea8fe', // Soft Blue
                '#052c65'  // Dark Navy
            ];
            
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        data: data.data, 
                        backgroundColor: professionalColors, 
                        borderColor: '#ffffff', // Puting border para umangat ang bawat slice
                        borderWidth: 2, 
                        hoverOffset: 6 // Umuusli kapag hinover
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    cutout: '72%', // Mas manipis na donut para mukhang modern
                    plugins: {
                        legend: { 
                            position: 'right', // Nilagay sa kanan para madaling basahin
                            labels: { usePointStyle: true, padding: 15, font: {size: 11} } 
                        }
                    }
                } 
            });
        }).catch(e => console.error(e));
    }

    // 4. BOTTLENECK ANALYSIS (Horizontal Bar)
    if(document.getElementById('bottleneckChart')) {
        fetch('api/dss_data.php?action=bottleneck_analysis').then(r => r.json()).then(data => {
            if(data.error || !data.labels) return;
            const ctx = document.getElementById('bottleneckChart').getContext('2d');
            new Chart(ctx, { 
                type: 'bar', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Avg. Hours', 
                        data: data.data, 
                        backgroundColor: ['#adb5bd', '#ffc107', '#dc3545', '#198754'], // Softer alert colors
                        borderRadius: 6, 
                        barThickness: 22 
                    }] 
                }, 
                options: { 
                    indexAxis: 'y', 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)' }, beginAtZero: true },
                        y: { grid: { display: false } }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 5. WORKLOAD DISTRIBUTION (Bar Chart)
        fetch('api/dss_data.php?action=workload_distribution').then(r => r.json()).then(data => {
            if(data.error || !data.labels) return;
            const ctx = document.getElementById('workloadChart').getContext('2d');
            new Chart(ctx, { 
                type: 'bar', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        label: 'Pending Documents', 
                        data: data.data, 
                        backgroundColor: 'rgba(32, 201, 151, 0.85)', // Teal color para sa operations
                        hoverBackgroundColor: '#20c997',
                        borderRadius: 6, 
                        maxBarThickness: 40 
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { grid: { borderDash: [4, 4], color: 'rgba(0,0,0,0.05)' }, beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                } 
            });
        }).catch(e => console.error(e));

        // 6. REJECTION RATE (Doughnut Chart)
        fetch('api/dss_data.php?action=rejection_rate').then(r => r.json()).then(data => {
            if(data.error || !data.labels) return;
            const ctx = document.getElementById('rejectionChart').getContext('2d');
            new Chart(ctx, { 
                type: 'doughnut', 
                data: { 
                    labels: data.labels, 
                    datasets: [{ 
                        data: data.data, 
                        backgroundColor: ['#198754', '#dc3545'], // Success Green vs Danger Red
                        borderColor: '#ffffff',
                        borderWidth: 2, 
                        hoverOffset: 6
                    }] 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    cutout: '72%',
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
                    }
                } 
            });
        }).catch(e => console.error(e));
    }
});