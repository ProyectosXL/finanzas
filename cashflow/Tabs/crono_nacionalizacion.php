<?php $tabName = 'Crono Nacionalización'; ?>
<link rel="stylesheet" href="Css/Comex-Crono_nacionalizacion.css">

<div class="tab-crono_nacionalizacion">
    
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
                <div class="kpi-card-value" id="total4semanas">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Pagos de nacionalización</span>
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
                <div class="kpi-card-value" id="total11meses">$ 0.00</div>
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
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneral">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Todas las nacionalizaciones</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Cronograma de Nacionalización</h5>
                <small class="text-muted">
                    Gestión de fechas de pago de nacionalización 
                    <i class="fas fa-info-circle ms-1" 
                       title="Click en la fecha de nacionalización para editarla. Las fechas editadas se muestran con fondo amarillo."></i>
                </small>
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
                <div class="table-responsive tabla-temporal">
                    <table id="tablaCronoNacionalizacion" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2">Fecha Est. EMB</th>
                                <th rowspan="2">Proveedor</th>
                                <th rowspan="2">Contenedor</th>
                                <th rowspan="2">Orden Compra</th>
                                <th rowspan="2">Despachante</th>
                                <th rowspan="2">Importe Est.</th>
                                <th rowspan="2">ETD</th>
                                <th rowspan="2">ETA</th>
                                <th rowspan="2">
                                    Fecha Nac. 
                                    <i class="fas fa-pen-to-square ms-1" style="font-size: 10px;" 
                                       title="Click para editar"></i>
                                </th>
                                <th colspan="28" class="table-group-divider" id="periodoHeader">Período</th>
                            </tr>
                            <tr id="headerRowSub">
                                <!-- Los días/meses se generan dinámicamente -->
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Las filas se generan dinámicamente -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr id="totalsRow">
                                <td colspan="9" class="fw-bold text-end">TOTALES</td>
                                <!-- Los totales se generan dinámicamente -->
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../Components/help_modal_comex.php'; ?>

<script src="Js/Comex-Crono_nacionalizacion.js?v=<?php echo time(); ?>"></script>
