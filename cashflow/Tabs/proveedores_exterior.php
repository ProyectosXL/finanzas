<?php $tabName = 'Proveedores Exterior'; ?>
<link rel="stylesheet" href="Css/Comex-Proveedores_exterior.css">

<div class="tab-proveedores_exterior">
    
    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4" id="summarySection" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Próximas 4 Semanas</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanas">U$S 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Desde hoy</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Próximos 11 Meses</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total11meses">U$S 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Proyección anual</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total General</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-ship"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneral">U$S 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Todas las importaciones</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Proveedores Exterior</h5>
                <small class="text-muted">Importaciones pendientes ordenadas por ETD</small>
            </div>
            <div class="d-flex gap-2">
                <div class="btn-group" role="group">
                    <button id="btnVistaSemanas" class="btn btn-sm btn-primary">
                        <i class="fas fa-calendar-week me-1"></i> Semanas
                    </button>
                    <button id="btnVistaMeses" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-calendar-alt me-1"></i> Meses
                    </button>
                </div>
                <button id="btnRefresh" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExport" class="btn btn-sm btn-success">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p>Cargando datos...</p>
            </div>
            
            <div class="table-wrapper" id="tableWrapper" style="display: none;">
                <div class="table-responsive">
                    <table id="tablaProveedoresExterior" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2">Proveedor</th>
                                <th rowspan="2">Contenedor</th>
                                <th rowspan="2">Orden Compra</th>
                                <th rowspan="2">Despachante</th>
                                <th rowspan="2">Valor FOB (USD)</th>
                                <th rowspan="2">ETD</th>
                                <th rowspan="2">ETA</th>
                                <th rowspan="2">Fecha Est. Pago</th>
                                <th colspan="31" class="table-group-divider" id="mesActualHeader">Días del Mes</th>
                            </tr>
                            <tr id="headerRowSub">
                                <!-- Los días se generan dinámicamente -->
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Las filas se generan dinámicamente -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr id="totalsRow">
                                <td colspan="8" class="fw-bold text-end">TOTALES</td>
                                <!-- Los totales se generan dinámicamente -->
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/Comex-Proveedores_exterior.js?v=<?php echo time(); ?>"></script>
