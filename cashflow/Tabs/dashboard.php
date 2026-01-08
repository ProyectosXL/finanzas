<!-- Tab: Dashboard -->
<div class="tab-dashboard">
    
    <!-- Dashboard Header -->
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="dashboard-title mb-1">Dashboard Financiero</h2>
                <p class="text-muted mb-0">Vista general de ingresos, gastos y proyecciones</p>
            </div>
            <div class="col-auto">
                <div class="date-range-badge">
                    <i class="fas fa-calendar-alt me-2"></i>
                    <span>Año 2025</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Primera fila: Ingresos & Gastos + Antigüedad por Cobrar -->
    <div class="row g-4 mb-4">
        <!-- Ingresos & Gastos -->
        <div class="col-lg-8">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Ingresos & Gastos</h5>
                    <div class="dashboard-legend">
                        <span class="legend-item">
                            <span class="legend-dot bg-success"></span>
                            Ingresos
                        </span>
                        <span class="legend-item">
                            <span class="legend-dot bg-danger"></span>
                            Gastos
                        </span>
                    </div>
                </div>
                <div class="dashboard-card-body">
                    <canvas id="ingresosGastosChart" height="80"></canvas>
                </div>
                <div class="dashboard-card-footer">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="footer-stat">
                                <div class="footer-stat-label">INGRESOS</div>
                                <div class="footer-stat-value text-success">$50,801.84</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="footer-stat">
                                <div class="footer-stat-label">GASTOS</div>
                                <div class="footer-stat-value text-danger">-$30,200.00</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="footer-stat">
                                <div class="footer-stat-label">RESULTADO</div>
                                <div class="footer-stat-value text-primary">$20,601.84</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Antigüedad por Cobrar -->
        <div class="col-lg-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Antigüedad por Cobrar</h5>
                </div>
                <div class="dashboard-card-body">
                    <div class="aging-list">
                        <div class="aging-item">
                            <div class="aging-label">Corriente</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-success" style="width: 75%"></div>
                            </div>
                            <div class="aging-value">$3,348.50</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">0-30</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-info" style="width: 50%"></div>
                            </div>
                            <div class="aging-value">$2,231.00</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">31-60</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-warning" style="width: 35%"></div>
                            </div>
                            <div class="aging-value">$1,862.90</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">61-90</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-orange" style="width: 25%"></div>
                            </div>
                            <div class="aging-value">$1,046.90</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">90+</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 20%"></div>
                            </div>
                            <div class="aging-value">$840.00</div>
                        </div>
                    </div>
                    <div class="total-footer mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>TOTAL POR COBRAR</strong>
                            <strong class="text-success">$9,329.30</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Segunda fila: Facturas & Cobros + Resumen de Gastos + Antigüedad por Pagar -->
    <div class="row g-4 mb-4">
        <!-- Facturas & Cobros -->
        <div class="col-lg-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Facturas & Cobros</h5>
                </div>
                <div class="dashboard-card-body">
                    <div class="status-bars">
                        <div class="status-bar-item mb-3">
                            <div class="status-bar-header">
                                <span class="status-label">COBRADO</span>
                                <span class="status-value text-success">$18,500.50</span>
                            </div>
                            <div class="progress" style="height: 24px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 55%"></div>
                            </div>
                        </div>
                        <div class="status-bar-item mb-3">
                            <div class="status-bar-header">
                                <span class="status-label">PENDIENTE</span>
                                <span class="status-value text-warning">$12,300.45</span>
                            </div>
                            <div class="progress" style="height: 24px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: 35%"></div>
                            </div>
                        </div>
                        <div class="status-bar-item">
                            <div class="status-bar-header">
                                <span class="status-label">EN ATRASO</span>
                                <span class="status-value text-danger">$3,550.00</span>
                            </div>
                            <div class="progress" style="height: 24px;">
                                <div class="progress-bar bg-danger" role="progressbar" style="width: 10%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resumen de Gastos -->
        <div class="col-lg-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Resumen de Gastos</h5>
                </div>
                <div class="dashboard-card-body">
                    <div class="chart-container-donut">
                        <canvas id="gastosDonutChart"></canvas>
                    </div>
                    <div class="category-legend mt-3">
                        <div class="category-item">
                            <span class="category-dot" style="background-color: #28a745;"></span>
                            <span class="category-name">Nómina</span>
                            <span class="category-value">$12,080</span>
                        </div>
                        <div class="category-item">
                            <span class="category-dot" style="background-color: #6ba5f7;"></span>
                            <span class="category-name">Mercadeo</span>
                            <span class="category-value">$6,040</span>
                        </div>
                        <div class="category-item">
                            <span class="category-dot" style="background-color: #ffc107;"></span>
                            <span class="category-name">Alquiler oficina</span>
                            <span class="category-value">$4,530</span>
                        </div>
                        <div class="category-item">
                            <span class="category-dot" style="background-color: #dc3545;"></span>
                            <span class="category-name">Misceláneo</span>
                            <span class="category-value">$3,412</span>
                        </div>
                        <div class="category-item">
                            <span class="category-dot" style="background-color: #6c757d;"></span>
                            <span class="category-name">Todo lo demás</span>
                            <span class="category-value">$4,138</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Antigüedad por Pagar -->
        <div class="col-lg-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Antigüedad por Pagar</h5>
                </div>
                <div class="dashboard-card-body">
                    <div class="aging-list">
                        <div class="aging-item">
                            <div class="aging-label">Corriente</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 18%"></div>
                            </div>
                            <div class="aging-value text-danger">$664.32</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">0-30</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 60%"></div>
                            </div>
                            <div class="aging-value text-danger">$2,231.00</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">31-60</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 24%"></div>
                            </div>
                            <div class="aging-value text-danger">$880.43</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">61-90</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 18%"></div>
                            </div>
                            <div class="aging-value text-danger">$660.00</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-label">90+</div>
                            <div class="aging-bar-container">
                                <div class="aging-bar bg-danger" style="width: 0%"></div>
                            </div>
                            <div class="aging-value text-danger">$0.00</div>
                        </div>
                    </div>
                    <div class="total-footer mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>TOTAL POR PAGAR</strong>
                            <strong class="text-danger">$4,235.75</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tercera fila: Flujo de Caja Proyectado -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title">Flujo de Caja Proyectado - 12 Meses</h5>
                    <div class="dashboard-legend">
                        <span class="legend-item">
                            <span class="legend-dot bg-primary"></span>
                            Saldo Acumulado
                        </span>
                    </div>
                </div>
                <div class="dashboard-card-body">
                    <canvas id="flujoCajaChart" height="60"></canvas>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
/* Dashboard Styles */
.tab-dashboard {
    padding: 24px;
}

.dashboard-header {
    margin-bottom: 24px;
}

.dashboard-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
}

.date-range-badge {
    background: var(--bg-light);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
    color: var(--text-secondary);
}

.dashboard-card {
    background: var(--bg-white);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.dashboard-card-header {
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-card-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.dashboard-legend {
    display: flex;
    gap: 16px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-secondary);
}

.legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.dashboard-card-body {
    padding: 20px;
    flex: 1;
}

.dashboard-card-footer {
    padding: 16px 20px;
    background: var(--bg-light);
    border-top: 1px solid var(--border-color);
}

.footer-stat-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.footer-stat-value {
    font-size: 18px;
    font-weight: 700;
}

/* Aging List Styles */
.aging-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.aging-item {
    display: grid;
    grid-template-columns: 80px 1fr 100px;
    align-items: center;
    gap: 12px;
}

.aging-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
}

.aging-bar-container {
    background: var(--bg-light);
    height: 24px;
    border-radius: 6px;
    overflow: hidden;
}

.aging-bar {
    height: 100%;
    border-radius: 6px;
    transition: width 0.3s ease;
}

.aging-bar.bg-orange {
    background-color: #fd7e14;
}

.aging-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    text-align: right;
}

.total-footer {
    font-size: 15px;
}

/* Status Bars */
.status-bars {
    display: flex;
    flex-direction: column;
}

.status-bar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.status-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    letter-spacing: 0.5px;
}

.status-value {
    font-size: 15px;
    font-weight: 700;
}

/* Category Legend */
.category-legend {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.category-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
}

.category-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.category-name {
    flex: 1;
    color: var(--text-secondary);
}

.category-value {
    font-weight: 600;
    color: var(--text-primary);
}

.chart-container-donut {
    max-width: 250px;
    margin: 0 auto;
}

/* Responsive */
@media (max-width: 991px) {
    .tab-dashboard {
        padding: 16px;
    }
    
    .aging-item {
        grid-template-columns: 70px 1fr 90px;
        gap: 8px;
    }
}
</style>

<script>
$(document).ready(function() {
    if ($('.tab-dashboard').length > 0) {
        initDashboardCharts();
    }
});

function initDashboardCharts() {
    // Gráfico de Ingresos & Gastos
    const ctxIngresos = document.getElementById('ingresosGastosChart');
    if (ctxIngresos) {
        new Chart(ctxIngresos, {
            type: 'bar',
            data: {
                labels: ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'],
                datasets: [
                    {
                        label: 'Ingresos',
                        data: [3200, 3100, 4500, 4800, 3500, 4200, 5200, 4600, 4400, 4500, 4100, 5000],
                        backgroundColor: '#28a745',
                        borderRadius: 6,
                        barThickness: 20
                    },
                    {
                        label: 'Gastos',
                        data: [2800, 2600, 3200, 2900, 2400, 2700, 3100, 2500, 2600, 2800, 2300, 2700],
                        backgroundColor: '#dc3545',
                        borderRadius: 6,
                        barThickness: 20
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 13 },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': $' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + (value/1000) + 'K';
                            }
                        },
                        grid: {
                            color: '#f0f0f0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Gráfico de Gastos (Donut)
    const ctxGastos = document.getElementById('gastosDonutChart');
    if (ctxGastos) {
        new Chart(ctxGastos, {
            type: 'doughnut',
            data: {
                labels: ['Nómina', 'Mercadeo', 'Alquiler oficina', 'Misceláneo', 'Todo lo demás'],
                datasets: [{
                    data: [40, 20, 15, 11, 14],
                    backgroundColor: [
                        '#28a745',
                        '#6ba5f7',
                        '#ffc107',
                        '#dc3545',
                        '#6c757d'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    // Gráfico de Flujo de Caja
    const ctxFlujo = document.getElementById('flujoCajaChart');
    if (ctxFlujo) {
        new Chart(ctxFlujo, {
            type: 'line',
            data: {
                labels: ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'],
                datasets: [{
                    label: 'Saldo Acumulado',
                    data: [5000, 8200, 12900, 17200, 19700, 23100, 27700, 31300, 34500, 38200, 41400, 45700],
                    borderColor: '#3366ff',
                    backgroundColor: 'rgba(51, 102, 255, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#3366ff',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Saldo: $' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + (value/1000) + 'K';
                            }
                        },
                        grid: {
                            color: '#f0f0f0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
}
</script>
