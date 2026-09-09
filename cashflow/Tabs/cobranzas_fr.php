<?php $tabName = 'Cobranzas FR'; ?>
<link rel="stylesheet" href="Css/Ingresos-Cobranzas_fr.css?v=<?php echo time(); ?>">

<div class="tab-cobranzas_fr">

    <!-- Lo que quedó fuera del horizonte o sin fecha. Antes se descartaba en
         silencio, así que la tabla podía informar de menos sin decirlo. -->
    <div id="avisosCob"></div>

    <!-- KPI Cards Row.
         Las tres tarjetas miden los tres períodos de las tres vistas, así que
         cada una se corresponde con un botón. El rótulo lo escribe el JS con el
         período real, que depende del horizonte configurado. -->
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
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0">Cobranzas FR</h5>
                    <small class="text-muted">Proyectadas por fecha de pago</small>
                </div>
                <div class="search-box-container ms-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaCob" class="form-control border-start-0 ps-0" placeholder="Buscar cliente o código..." style="min-width: 250px;">
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <div class="btn-group" role="group">
                    <button id="btnVistaResumenCob" class="btn btn-sm btn-primary">
                        <i class="fas fa-list me-1"></i> Resumen
                    </button>
                    <button id="btnVistaDeepDiveCob" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search-plus me-1"></i> Deep Dive
                    </button>
                </div>
                <!-- Los tres botones los dibuja Js/eje-vistas.js. El grupo de
                     Resumen / Deep Dive de al lado es otra dimensión: cambia el
                     grano de las filas, no el período. -->
                <div id="vistasCob"></div>
                <button id="btnRefreshCob" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExportCob" class="btn btn-sm btn-success">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Qué período se está midiendo. La vista Meses no cubre el horizonte
             completo, y sin esto su total se lee como el total de todo. -->
        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="periodoCob"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinnerCob">
                <div class="spinner"></div>
                <p>Cargando datos...</p>
            </div>
            
            <div class="table-wrapper table-responsive" id="tableWrapperCob" style="display: none;">
                <table id="tablaCobranzasFR" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2">COD_CLI</th>
                                <th rowspan="2">RAZON_SOC</th>
                                <th rowspan="2">FECHA</th>
                                <th rowspan="2">T_COMP</th>
                                <th rowspan="2">N_COMP</th>
                                <th rowspan="2">Desc</th>
                                <th rowspan="2">Dias</th>
                                <th rowspan="2">Importe Bruto</th>
                                <th rowspan="2">Importe Neto</th>
                                <th rowspan="2">Cobro</th>
                                <!-- El rótulo y el colspan los pone el JS según
                                     la vista activa. -->
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
