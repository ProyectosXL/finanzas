<?php $tabName = 'Cobranzas May'; ?>
<link rel="stylesheet" href="Css/Ingresos-Cobranzas_may.css?v=<?php echo time(); ?>">

<div class="tab-cobranzas_may">

    <!-- Avisos de importes fuera del horizonte o sin fecha -->
    <div id="avisosCobMay"></div>

    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4" id="summarySectionCobMay" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanasCobMay">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo4semanasCobMay">Tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Meses</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total11mesesCobMay">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo11mesesCobMay">Después del tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Período Completo</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneralCobMay">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloGeneralCobMay">Todo el horizonte</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0" id="tituloMatrizCobMay">Cobranzas May &mdash; Pendientes Proyectados</h5>
                    <small class="text-muted" id="subtituloMatrizCobMay">Facturas pendientes proyectadas por fecha probable de cobro</small>
                </div>
                <div class="search-box-container ms-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaCobMay" class="form-control border-start-0 ps-0" placeholder="Buscar cliente o comprobante..." style="min-width: 230px;">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="btn-group" role="group">
                    <button id="btnVistaResumenCobMay" class="btn btn-sm btn-outline-primary active">
                        <i class="fas fa-list me-1"></i> Resumen
                    </button>
                    <button id="btnVistaDeepDiveCobMay" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search-plus me-1"></i> Deep Dive
                    </button>
                </div>

                <!-- Los tres botones los dibuja Js/eje-vistas.js -->
                <div id="vistasCobMay"></div>

                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasCobMay"></div>

                <button id="btnRefreshCobMay" class="btn btn-sm btn-outline-primary" title="Actualizar datos">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExportCobMay" class="btn btn-sm btn-success" title="Exportar a Excel">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Período que se está midiendo -->
        <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
            <small class="text-muted" id="periodoCobMay"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinnerCobMay">
                <div class="spinner"></div>
                <p>Cargando matriz de cobranzas mayoristas...</p>
            </div>
            
            <!-- .tabla-temporal: header de dos filas fijo arriba, pie de
                 totales fijo abajo y columnas descriptivas fijas a la
                 izquierda. Ver Css/main.css. -->
            <div class="table-wrapper table-responsive tabla-temporal" id="tableWrapperCobMay" style="display: none;">
                <table id="tablaCobranzasMay" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th rowspan="2">Tipo</th>
                            <th rowspan="2">COD_CLI</th>
                            <th rowspan="2" class="col-texto">RAZON_SOC</th>
                            <th rowspan="2">FECHA</th>
                            <th rowspan="2">T_COMP</th>
                            <th rowspan="2">N_COMP</th>
                            <th rowspan="2">Desc</th>
                            <th rowspan="2">Dias</th>
                            <th rowspan="2">Importe Bruto</th>
                            <th rowspan="2" id="thImporteNetoCobMay">Importe Neto</th>
                            <th rowspan="2" id="thCobroCobMay">Cobro</th>
                            <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                            <th colspan="1" class="table-group-divider" id="mesActualHeaderCobMay">Días</th>
                        </tr>
                        <tr id="headerRowSubCobMay">
                            <!-- Los días se generan dinámicamente -->
                        </tr>
                    </thead>
                    <tbody id="tableBodyCobMay">
                        <!-- Las filas se generan dinámicamente -->
                    </tbody>
                    <tfoot class="table-light">
                        <tr id="totalsRowCobMay">
                            <td colspan="11" class="fw-bold text-end">TOTALES</td>
                            <!-- Los totales se generan dinámicamente -->
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Ingresos-Cobranzas_may.js?v=<?php echo time(); ?>"></script>
