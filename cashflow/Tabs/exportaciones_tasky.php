<?php $tabName = 'Exportaciones Tasky'; ?>
<link rel="stylesheet" href="Css/Ingresos-Exportaciones_tasky.css?v=<?php echo time(); ?>">
<!--
    Tasky es la razón social del grupo en Uruguay: mismo grupo, otra empresa.
    Se le factura en dólares y estas son sus facturas pendientes de GVA12,
    proyectadas a una fecha de cobro estimada (emisión + plazo de Parámetros).

    TODAS se valúan a dólar de hoy, a propósito: la deuda está fija en dólares
    y valuarla a hoy es no suponer devaluación. Ver README-exportaciones-tasky.md.

    No hay Resumen / Deep Dive como en Cobranzas May: es un solo cliente, así
    que una fila por factura ya es el resumen.
-->
<div class="tab-exportaciones_tasky">

    <!-- Avisos: sin cotización, facturas vencidas, importes fuera del horizonte -->
    <div id="avisosExpTasky"></div>

    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4" id="summarySectionExpTasky" style="display: none;">
        <div class="col-md-6 col-lg">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Pendiente en USD</span>
                    <div class="kpi-card-icon purple">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalUsdExpTasky">USD 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloUsdExpTasky">Facturas pendientes</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Dólar de hoy</span>
                    <div class="kpi-card-icon purple">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="cotizHoyExpTasky">&mdash;</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Oficial BCRA, cierre del mes en curso. Valúa todas las facturas</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanasExpTasky">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo4semanasExpTasky">Tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Meses</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total11mesesExpTasky">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo11mesesExpTasky">Después del tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Período Completo</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneralExpTasky">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloGeneralExpTasky">Todo el horizonte</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0">Exportaciones Tasky &mdash; Facturas pendientes en dólares</h5>
                    <small class="text-muted" id="subtituloExpTasky">Cobro estimado a emisión + plazo, valuadas al dólar de hoy</small>
                </div>
                <div class="search-box-container ms-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaExpTasky" class="form-control border-start-0 ps-0" placeholder="Buscar comprobante o razón social..." style="min-width: 230px;">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap align-items-center">
                <!-- Los tres botones los dibuja Js/eje-vistas.js -->
                <div id="vistasExpTasky"></div>

                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasExpTasky"></div>

                <button id="btnRefreshExpTasky" class="btn btn-sm btn-outline-primary" title="Actualizar datos">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExportExpTasky" class="btn btn-sm btn-success" title="Exportar a Excel">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Período que se está midiendo -->
        <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
            <small class="text-muted" id="periodoExpTasky"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinnerExpTasky">
                <div class="spinner"></div>
                <p>Cargando facturas de exportación...</p>
            </div>

            <!-- .tabla-temporal: header de dos filas fijo arriba, pie de
                 totales fijo abajo y columnas descriptivas fijas a la
                 izquierda. Ver Css/main.css. -->
            <div class="table-wrapper table-responsive tabla-temporal" id="tableWrapperExpTasky" style="display: none;">
                <table id="tablaExportacionesTasky" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th rowspan="2">FECHA_EMIS</th>
                            <th rowspan="2">N_COMP</th>
                            <th rowspan="2">COD_CLIENT</th>
                            <th rowspan="2" class="col-texto">RAZON_SOCI</th>
                            <th rowspan="2">Importe USD</th>
                            <!-- Referencia histórica: cómo se facturó. No entra en ningún cálculo. -->
                            <th rowspan="2" class="col-referencia" title="Cotización con la que se facturó. Referencia histórica, no se usa para calcular">Cotiz. facturación</th>
                            <th rowspan="2" class="col-referencia" title="Importe en pesos al momento de facturar. Referencia histórica, no se usa para calcular">Importe pesos facturación</th>
                            <th rowspan="2" title="Dólar oficial BCRA de hoy: la misma cotización para todas las facturas">Cotiz. hoy</th>
                            <th rowspan="2" title="Importe USD × cotización de hoy. Es el que va a la grilla y al tablero">Importe pesos hoy</th>
                            <th rowspan="2">Fecha cobro estimada</th>
                            <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                            <th colspan="1" class="table-group-divider" id="ejeHeaderExpTasky">Días</th>
                        </tr>
                        <tr id="headerRowSubExpTasky">
                            <!-- Los días se generan dinámicamente -->
                        </tr>
                    </thead>
                    <tbody id="tableBodyExpTasky">
                        <!-- Las filas se generan dinámicamente -->
                    </tbody>
                    <tfoot class="table-light">
                        <tr id="totalsRowExpTasky">
                            <!-- Una celda por columna: las genera el JS -->
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Ingresos-Exportaciones_tasky.js?v=<?php echo time(); ?>"></script>
