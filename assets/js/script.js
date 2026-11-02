document.addEventListener('DOMContentLoaded', function() {
    const i18n = window.APP.i18n;
    const isRtl = document.documentElement.dir === 'rtl';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let expenseChart;
    let chartData;
    let currentChartType = 'pie';

    // DOM elements
    const expenseForm = document.getElementById('expenseForm');
    const chartToggles = document.querySelectorAll('.chart-btn');
    const chartEmpty = document.getElementById('chartEmpty');

    const money = value => `${i18n.currency} ${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // Theme-aware colors read from the CSS variables
    function themeColors() {
        const css = getComputedStyle(document.documentElement);
        return {
            text: css.getPropertyValue('--text').trim(),
            muted: css.getPropertyValue('--muted').trim(),
            grid: css.getPropertyValue('--border').trim(),
            surface: css.getPropertyValue('--surface').trim(),
            primary: css.getPropertyValue('--primary-color').trim(),
        };
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.replace('#', ''), 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    }

    // --- Count-up animation for the summary cards ---
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseFloat(el.dataset.count) || 0;
        const decimals = el.dataset.decimals !== undefined ? parseInt(el.dataset.decimals, 10) : 2;
        const format = v => v.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        if (reduceMotion || target === 0) {
            el.textContent = format(target);
            return;
        }
        const duration = 1200;
        const start = performance.now();
        function tick(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = format(target * eased);
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    });

    // --- Charts ---
    // Chart data follows the same filters as the history table
    function fetchChartData() {
        return fetch('process.php' + window.location.search)
            .then(response => response.json())
            .catch(error => console.error('Error fetching chart data:', error));
    }

    // Draws the period total in the middle of the doughnut
    const centerTextPlugin = {
        id: 'centerText',
        afterDraw(chart) {
            if (chart.config.type !== 'doughnut') return;
            const meta = chart.getDatasetMeta(0);
            if (!meta.data.length) return;
            const { x, y } = meta.data[0];
            const colors = themeColors();
            const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
            const progress = chart._centerProgress ?? 1;
            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.globalAlpha = progress;
            ctx.fillStyle = colors.muted;
            ctx.font = '600 13px Cairo, sans-serif';
            ctx.fillText(i18n.total, x, y - 14);
            ctx.fillStyle = colors.text;
            ctx.font = '700 18px Cairo, sans-serif';
            ctx.fillText(money(total * progress), x, y + 10);
            ctx.restore();
        }
    };

    function baseOptions(colors) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            locale: 'en-US',
            plugins: {
                legend: {
                    rtl: isRtl,
                    labels: { color: colors.text, font: { family: 'Cairo', size: 13 }, usePointStyle: true, padding: 14 }
                },
                tooltip: {
                    rtl: isRtl,
                    backgroundColor: colors.surface,
                    titleColor: colors.text,
                    bodyColor: colors.text,
                    borderColor: colors.grid,
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 10,
                    titleFont: { family: 'Cairo', weight: '700' },
                    bodyFont: { family: 'Cairo' },
                }
            }
        };
    }

    function renderChart(type) {
        if (!chartData) return;
        const canvas = document.getElementById('expenseChart');
        if (expenseChart) {
            expenseChart.destroy();
        }

        const total = chartData.data.reduce((a, b) => a + b, 0);
        chartEmpty.style.display = total === 0 ? 'flex' : 'none';
        canvas.style.visibility = total === 0 ? 'hidden' : 'visible';
        if (total === 0) return;

        const colors = themeColors();
        let config;
        switch (type) {
            case 'bar':
                config = getBarChartConfig(colors);
                break;
            case 'line':
                config = getLineChartConfig(colors);
                break;
            default:
                config = getDoughnutChartConfig(colors);
        }
        if (reduceMotion) {
            config.options.animation = false;
        }
        expenseChart = new Chart(canvas.getContext('2d'), config);
    }

    // Only categories that have spending, biggest first
    function nonEmptyCategories() {
        return chartData.labels
            .map((label, i) => ({ label, value: chartData.data[i], color: chartData.colors[i] }))
            .filter(item => item.value > 0)
            .sort((a, b) => b.value - a.value);
    }

    function getDoughnutChartConfig(colors) {
        const items = nonEmptyCategories();
        const options = baseOptions(colors);
        const narrow = window.innerWidth < 600;
        options.cutout = '62%';
        options.layout = { padding: 8 };
        options.plugins.legend.position = narrow ? 'bottom' : (isRtl ? 'left' : 'right');
        options.plugins.tooltip.callbacks = {
            label(context) {
                const sum = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = Math.round((context.raw / sum) * 100);
                return ` ${context.label}: ${money(context.raw)} (${percentage}%)`;
            }
        };
        options.animation = {
            animateRotate: true,
            animateScale: true,
            duration: 1100,
            easing: 'easeOutQuart',
            onProgress(anim) { anim.chart._centerProgress = anim.currentStep / anim.numSteps; },
            onComplete(anim) {
                if (anim.chart._centerProgress !== 1) {
                    anim.chart._centerProgress = 1;
                    anim.chart.draw();
                }
            },
        };

        return {
            type: 'doughnut',
            data: {
                labels: items.map(i => i.label),
                datasets: [{
                    data: items.map(i => i.value),
                    backgroundColor: items.map(i => i.color),
                    borderColor: colors.surface,
                    borderWidth: 3,
                    borderRadius: 6,
                    hoverOffset: 14,
                }]
            },
            options,
            plugins: [centerTextPlugin]
        };
    }

    function getBarChartConfig(colors) {
        const items = nonEmptyCategories();
        const options = baseOptions(colors);
        options.indexAxis = 'y';
        options.plugins.legend.display = false;
        options.plugins.tooltip.callbacks = { label: context => ' ' + money(context.raw) };
        options.scales = {
            x: {
                beginAtZero: true,
                reverse: isRtl,
                grid: { color: colors.grid },
                ticks: { color: colors.muted, maxTicksLimit: 6, maxRotation: 0, callback: value => value.toLocaleString('en-US') }
            },
            y: {
                position: isRtl ? 'right' : 'left',
                grid: { display: false },
                ticks: { color: colors.text, font: { family: 'Cairo', weight: '600' } }
            }
        };
        // Bars grow one after another
        options.animation = {
            duration: 900,
            easing: 'easeOutQuart',
            delay: context => (context.type === 'data' && context.mode === 'default' ? context.dataIndex * 110 : 0),
        };

        return {
            type: 'bar',
            data: {
                labels: items.map(i => i.label),
                datasets: [{
                    data: items.map(i => i.value),
                    backgroundColor: items.map(i => hexToRgba(i.color, 0.85)),
                    hoverBackgroundColor: items.map(i => i.color),
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.75,
                }]
            },
            options
        };
    }

    function getLineChartConfig(colors) {
        const trend = chartData.trend;
        const options = baseOptions(colors);
        const pointDelay = Math.max(8, 900 / Math.max(trend.data.length, 1));
        options.plugins.legend.display = false;
        options.interaction = { mode: 'index', intersect: false };
        options.plugins.tooltip.callbacks = { label: context => ' ' + money(context.raw) };
        options.scales = {
            x: {
                reverse: isRtl,
                grid: { display: false },
                ticks: { color: colors.muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
            },
            y: {
                beginAtZero: true,
                position: isRtl ? 'right' : 'left',
                grid: { color: colors.grid },
                ticks: { color: colors.muted, callback: value => i18n.currency + ' ' + value.toLocaleString('en-US') }
            }
        };
        // Draw the line progressively from start to end
        options.animations = {
            x: {
                type: 'number',
                easing: 'linear',
                duration: pointDelay,
                from: NaN,
                delay(ctx) {
                    if (ctx.type !== 'data' || ctx.xStarted) return 0;
                    ctx.xStarted = true;
                    return ctx.index * pointDelay;
                }
            },
            y: {
                type: 'number',
                easing: 'easeOutQuart',
                duration: pointDelay * 3,
                from: ctx => ctx.chart.scales.y.getPixelForValue(0),
                delay(ctx) {
                    if (ctx.type !== 'data' || ctx.yStarted) return 0;
                    ctx.yStarted = true;
                    return ctx.index * pointDelay;
                }
            }
        };

        return {
            type: 'line',
            data: {
                labels: trend.labels,
                datasets: [{
                    label: i18n.daily_spending,
                    data: trend.data,
                    borderColor: colors.primary,
                    borderWidth: 3,
                    fill: true,
                    cubicInterpolationMode: 'monotone',
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: colors.primary,
                    pointHoverBorderColor: colors.surface,
                    pointHoverBorderWidth: 3,
                    // Vertical gradient under the line
                    backgroundColor(context) {
                        const { ctx, chartArea } = context.chart;
                        if (!chartArea) return 'transparent';
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, hexToRgba(colors.primary, 0.35));
                        gradient.addColorStop(1, hexToRgba(colors.primary, 0));
                        return gradient;
                    }
                }]
            },
            options
        };
    }

    function initChart() {
        fetchChartData().then(data => {
            if (!data) return;
            chartData = data;
            renderChart(currentChartType);
        });
    }

    // Handle chart type toggles
    chartToggles.forEach(button => {
        button.addEventListener('click', function() {
            chartToggles.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            currentChartType = this.dataset.chartType;
            renderChart(currentChartType);
        });
    });

    // Re-color the chart when the theme changes
    document.addEventListener('themechange', () => renderChart(currentChartType));

    // --- History filters: only show the inputs the chosen period needs ---
    const periodSelect = document.getElementById('period');
    function updatePeriodFields() {
        document.querySelectorAll('[data-show-for]').forEach(field => {
            const visible = field.dataset.showFor === periodSelect.value;
            field.hidden = !visible;
            field.querySelectorAll('input').forEach(input => { input.disabled = !visible; });
        });
    }
    periodSelect.addEventListener('change', updatePeriodFields);
    updatePeriodFields();

    // Handle form submission
    expenseForm.addEventListener('submit', function(e) {
        e.preventDefault();

        fetch('process.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.APP.csrf },
            body: new FormData(expenseForm)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(i18n.error + ': ' + (data.error || i18n.went_wrong));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(i18n.went_wrong);
        });
    });

    // Balance and income forms (inside the modals)
    document.querySelectorAll('.ajax-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            formData.set('action', form.dataset.action);

            fetch('process.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.APP.csrf },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(i18n.error + ': ' + (data.error || data.message || i18n.went_wrong));
                }
            })
            .catch(() => alert(i18n.went_wrong));
        });
    });

    // Handle expense / income deletion
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.delete-btn');
        if (!button || !confirm(i18n.confirm_delete)) return;

        fetch('process.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.APP.csrf },
            body: 'id=' + encodeURIComponent(button.dataset.id) + '&type=' + encodeURIComponent(button.dataset.type || 'expense')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const row = button.closest('tr');
                row.style.transition = 'opacity 0.3s, transform 0.3s';
                row.style.opacity = '0';
                row.style.transform = 'translateX(' + (isRtl ? '-' : '') + '30px)';
                setTimeout(() => location.reload(), 300);
            }
        })
        .catch(error => console.error('Error:', error));
    });

    initChart();
});

// Ask for PIN before exporting; the server checks it and exports the current filters
function exportWithPIN() {
    const pin = prompt(window.APP.i18n.enter_pin);
    if (pin === null) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'export_excel.php';
    const params = new URLSearchParams(window.location.search);
    params.set('pin', pin);
    params.forEach((value, key) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

// --- Modals ---
function openModal(id) {
    const modal = document.getElementById(id);
    modal.style.display = 'flex';
    const firstInput = modal.querySelector('input');
    if (firstInput) firstInput.focus();
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.style.display = 'none';
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => { modal.style.display = 'none'; });
    }
});

function sendReport(period) {
    const i18n = window.APP.i18n;
    const modalContent = document.querySelector('#reportModal .modal-content');
    const originalContent = modalContent.innerHTML;

    modalContent.innerHTML = `
        <div style="padding:40px">
            <i class="fas fa-spinner fa-spin fa-3x" style="color:var(--primary-color)"></i>
            <p style="margin-top:15px; font-weight:bold;">${i18n.sending}</p>
        </div>
    `;

    fetch('send_report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.APP.csrf },
        body: JSON.stringify({ period: period })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            modalContent.innerHTML = `
                <div style="padding:20px; color: #10b981;">
                    <i class="fas fa-check-circle fa-4x" style="margin-bottom:15px"></i>
                    <h3 style="margin:0;">${i18n.sent}</h3>
                    <p style="margin-top:5px;">${i18n.check_inbox}</p>
                    <button class="modal-btn" onclick="closeModal('reportModal')" style="margin-top:20px">${i18n.close}</button>
                </div>
            `;
            setTimeout(() => { modalContent.innerHTML = originalContent; }, 4000);
        } else {
            alert(i18n.error + ': ' + (data.message || i18n.went_wrong));
            modalContent.innerHTML = originalContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(i18n.went_wrong);
        modalContent.innerHTML = originalContent;
    });
}
