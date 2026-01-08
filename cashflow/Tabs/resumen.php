<!-- Tab: Resumen -->
<div class="tab-resumen">
    
    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Saldo Disponible</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
                <div class="kpi-card-value">-</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Actualizado al <?php echo date('d/m/Y'); ?></span>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Ingresos Proyectados</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-arrow-trend-up"></i>
                    </div>
                </div>
                <div class="kpi-card-value">-</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Próximos 30 días</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Egresos Proyectados</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-arrow-trend-down"></i>
                    </div>
                </div>
                <div class="kpi-card-value">-</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Próximos 30 días</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Flujo Neto</span>
                    <div class="kpi-card-icon red">
                        <i class="fas fa-scale-balanced"></i>
                    </div>
                </div>
                <div class="kpi-card-value">-</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Proyección mensual</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row g-3">
        <!-- Resumen por Categoría -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-layer-group me-2"></i>Resumen por Categoría</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Categoría</th>
                                    <th class="text-end">Ingresos</th>
                                    <th class="text-end">Egresos</th>
                                    <th class="text-end">Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><i class="fas fa-arrow-trend-up me-2 text-muted"></i>Ingresos</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                                <tr>
                                    <td><i class="fas fa-ship me-2 text-muted"></i>Comercio Exterior</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                                <tr>
                                    <td><i class="fas fa-truck me-2 text-muted"></i>Proveedores</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                                <tr>
                                    <td><i class="fas fa-users me-2 text-muted"></i>RRHH y Operativos</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                                <tr>
                                    <td><i class="fas fa-landmark me-2 text-muted"></i>Financiero</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td>TOTAL</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Panel Lateral -->
        <div class="col-lg-4">
            <!-- Vencimientos Próximos -->
            <div class="card mb-3">
                <div class="card-header">
                    <i class="fas fa-clock me-2"></i>Vencimientos Próximos
                </div>
                <div class="card-body">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-hard-hat fa-2x mb-2"></i>
                        <p class="mb-0 small">En construcción</p>
                    </div>
                </div>
            </div>
            
            <!-- Alertas -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-bell me-2"></i>Actividades</span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="dropdown">
                            Mostrar: Semana <i class="fas fa-chevron-down ms-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#">Hoy</a></li>
                            <li><a class="dropdown-item active" href="#">Semana</a></li>
                            <li><a class="dropdown-item" href="#">Mes</a></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Hoy -->
                    <div class="activity-section">
                        <div class="activity-section-title">Hoy</div>
                        <div class="activity-item activity-danger">
                            <div class="activity-icon">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">En atraso</div>
                                <div class="activity-description">
                                    Pago pendiente para la <a href="#" class="activity-link">factura #1234</a> de Terra Performance Inc.
                                </div>
                                <div class="activity-time">Hace 5 días</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Esta semana -->
                    <div class="activity-section">
                        <div class="activity-section-title">Esta semana</div>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Pago entrante</div>
                                <div class="activity-description">
                                    Tienes un <a href="#" class="activity-link">pago entrante</a> de Joey Martín.
                                </div>
                            </div>
                        </div>
                        
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Conciliación bancaria</div>
                                <div class="activity-description">
                                    Tu cuenta de cheques del Banco Popular necesita <a href="#" class="activity-link">conciliación</a>.
                                </div>
                            </div>
                        </div>
                        
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Vencimiento próximo</div>
                                <div class="activity-description">
                                    <a href="#" class="activity-link">Pago de alquiler</a> vence el 10/01/2026.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div>
