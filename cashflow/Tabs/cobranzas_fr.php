<?php $tabName = 'Cobranzas FR'; ?>
<link rel="stylesheet" href="Css/Ingresos-Cobranzas_fr.css?v=<?php echo time(); ?>">

<div class="tab-cobranzas_fr">

    <!-- Avisos de importes fuera del horizonte o sin fecha -->
    <div id="avisosCob"></div>

    <!-- Solapas Principales: Real a Cobrar vs Pendientes Proyectados -->
    <ul class="nav nav-tabs mb-3" id="cobranzasFrTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabRealCobBtn" type="button" role="tab" data-origen="real">
                <i class="fas fa-check-circle text-success me-1"></i> Real a Cobrar
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabProyCobBtn" type="button" role="tab" data-origen="proyectado">
                <i class="fas fa-clock text-warning me-1"></i> Pendientes Proyectados
            </button>
        </li>
    </ul>

    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4" id="summarySectionCob" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanasCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo4semanasCob">Tramo diario</span>
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
                <div class="kpi-card-value" id="total11mesesCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo11mesesCob">Después del tramo diario</span>
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
                <div class="kpi-card-value" id="totalGeneralCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloGeneralCob">Todo el horizonte</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0" id="tituloMatrizCob">Cobranzas Franquicias &mdash; Real a Cobrar</h5>
                    <small class="text-muted" id="subtituloMatrizCob">Propuestas de pago confirmadas por fecha de cobro</small>
                </div>
                <div class="search-box-container ms-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaCob" class="form-control border-start-0 ps-0" placeholder="Buscar cliente o comprobante..." style="min-width: 230px;">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="btn-group" role="group">
                    <button id="btnVistaResumenCob" class="btn btn-sm btn-outline-primary active">
                        <i class="fas fa-list me-1"></i> Resumen
                    </button>
                    <button id="btnVistaDeepDiveCob" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search-plus me-1"></i> Deep Dive
                    </button>
                </div>

                <!-- Los tres botones los dibuja Js/eje-vistas.js -->
                <div id="vistasCob"></div>

                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasCob"></div>

                <button id="btnRefreshCob" class="btn btn-sm btn-outline-primary" title="Actualizar datos">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExportCob" class="btn btn-sm btn-success" title="Exportar a Excel">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Período que se está midiendo -->
        <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
            <small class="text-muted" id="periodoCob"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinnerCob">
                <div class="spinner"></div>
                <p>Cargando matriz de cobranzas...</p>
            </div>
            
            <!-- .tabla-temporal: header de dos filas fijo arriba, pie de
                 totales fijo abajo y columnas descriptivas fijas a la
                 izquierda. Ver Css/main.css. -->
            <div class="table-wrapper table-responsive tabla-temporal" id="tableWrapperCob" style="display: none;">
                <table id="tablaCobranzasFR" class="table table-hover mb-0">
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
                            <th rowspan="2" id="thImporteNetoCob">Importe Neto</th>
                            <th rowspan="2" id="thCobroCob">Cobro</th>
                            <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                            <th colspan="1" class="table-group-divider" id="mesActualHeaderCob">Días</th>
                        </tr>
                        <tr id="headerRowSubCob">
                            <!-- Los días se generan dinámicamente -->
                        </tr>
                    </thead>
                    <tbody id="tableBodyCob">
                        <!-- Las filas se generan dinámicamente -->
                    </tbody>
                    <tfoot class="table-light">
                        <tr id="totalsRowCob">
                            <td colspan="10" class="fw-bold text-end">TOTALES</td>
                            <!-- Los totales se generan dinámicamente -->
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Ingresos-Cobranzas_fr.js?v=<?php echo time(); ?>"></script>
