<?php $tabName = 'Ventas'; ?>
<link rel="stylesheet" href="Css/Ingresos-Ventas.css?v=<?php echo time(); ?>">

<div class="tab-ventas">

    <!-- Sub-pestañas -->
    <ul class="nav nav-tabs mb-3" id="ventasTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabAnalisisBtn" data-bs-toggle="tab"
                    data-bs-target="#paneAnalisis" type="button" role="tab">
                <i class="fas fa-chart-column me-1"></i> Análisis de Ventas
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabProyeccionBtn" data-bs-toggle="tab"
                    data-bs-target="#paneProyeccion" type="button" role="tab">
                <i class="fas fa-chart-line me-1"></i> Proyección
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ============================================================
             SUB-PESTAÑA: ANÁLISIS DE VENTAS
             ============================================================ -->
        <div class="tab-pane fade show active" id="paneAnalisis" role="tabpanel">

            <!-- Tendencia de los últimos meses. Va colapsada: informa el pasado,
                 la pantalla es la proyección de abajo. -->
            <div class="card mb-4 card-colapsable">
                <div class="card-header p-0">
                    <button class="card-toggle collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#bloqueTendencias"
                            aria-expanded="false" aria-controls="bloqueTendencias">
                        <i class="fas fa-chevron-down card-toggle-chevron"></i>
                        <span class="card-toggle-texto">
                            <h5 class="mb-0">Tendencia &mdash; Últimos 6 Meses</h5>
                            <small class="text-muted">
                                Venta <strong>neta sin IVA</strong> contra el mismo período del año anterior
                                <span id="tendenciasCorte"></span>
                                <i class="fas fa-info-circle ms-1"
                                   title="El mes en curso se compara contra los MISMOS días del año anterior, no contra el mes entero. Los importes son netos sin IVA: no se comparan contra la Venta Proyectada, que sí lo lleva."></i>
                            </small>
                        </span>
                    </button>
                </div>
                <div class="collapse" id="bloqueTendencias">
                    <div class="card-body p-0">
                        <div class="table-responsive tabla-temporal">
                            <table id="tablaTendencias" class="table table-hover mb-0">
                                <thead>
                                    <tr id="tendenciasHeader"></tr>
                                </thead>
                                <tbody id="tendenciasBody"></tbody>
                                <tfoot class="table-light">
                                    <tr id="tendenciasTotals"></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 PROYECCIÓN DE VENTA POR MES — tres vistas anuales

                 El tercer nivel de navegación vive DENTRO del card. Venta
                 Cashflow es la que abre por defecto y es la que alimenta la
                 proyección; las otras dos son lecturas anuales del dato real y
                 se cargan recién cuando se abre su pestaña.

                 LAS TRES NO ESTÁN EN LA MISMA BASE: Cashflow compara netos
                 contra una proyectada con IVA, Acumulada es toda neta y Balance
                 es todo con IVA. Cada una lo dice en su subtítulo y en el th-sub
                 de sus columnas.
                 ============================================================ -->
            <div class="card mb-4" id="cardVentaAnual">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="mb-0">Proyección de Venta por Mes</h5>
                            <!-- El subtítulo cambia con la pestaña activa: es
                                 donde se declara el período y la base. -->
                            <small class="text-muted" id="ventaAnualSubtitulo">
                                Mes actual + 11 &middot;
                                <strong>Venta Proyectada = Año Anterior (neto) &times; (1 + Variación) &times; (1 + IVA)</strong>
                                <i class="fas fa-info-circle ms-1"
                                   title="Las columnas de años son NETAS sin IVA. La Venta Proyectada lleva IVA: por eso es mayor aunque la variación sea 0%. Click en el % para editarlo."></i>
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <button id="btnRefreshAnalisis" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-sync-alt me-1"></i> Actualizar
                            </button>
                            <button id="btnExportAnalisis" class="btn btn-sm btn-success">
                                <i class="fas fa-file-excel me-1"></i> Exportar
                            </button>
                        </div>
                    </div>

                    <ul class="nav nav-tabs nav-tabs-card mt-3" id="ventaAnualTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tabVentaCashflowBtn" data-bs-toggle="tab"
                                    data-bs-target="#paneVentaCashflow" type="button" role="tab">
                                <i class="fas fa-chart-line me-1"></i> Venta Cashflow
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tabVentaAcumuladaBtn" data-bs-toggle="tab"
                                    data-bs-target="#paneVentaAcumulada" type="button" role="tab">
                                <i class="fas fa-layer-group me-1"></i> Venta Acumulada
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tabVentaBalanceBtn" data-bs-toggle="tab"
                                    data-bs-target="#paneVentaBalance" type="button" role="tab">
                                <i class="fas fa-scale-balanced me-1"></i> Venta Balance
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-0">
                    <!-- Warnings no fatales de las vistas anuales: el tipo de
                         cambio o el histórico diario que todavía no están. -->
                    <div id="warningsVentaAnual"></div>

                    <div class="tab-content">

                        <!-- Venta Cashflow: la tabla que alimenta la proyección -->
                        <div class="tab-pane fade show active" id="paneVentaCashflow" role="tabpanel">
                            <div class="cargando-slot" id="loadingAnalisis" data-cargando="Cargando datos…"></div>

                            <div id="wrapperAnalisis" style="display: none;">
                                <div class="table-responsive tabla-temporal">
                                    <table id="tablaAnalisis" class="table table-hover mb-0">
                                        <thead>
                                            <tr id="analisisHeader">
                                                <!-- Se genera dinámicamente -->
                                            </tr>
                                        </thead>
                                        <tbody id="analisisBody">
                                            <!-- Se genera dinámicamente -->
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr id="analisisTotals">
                                                <!-- Se genera dinámicamente -->
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Venta Acumulada: año calendario, real, neta, $ y USD -->
                        <div class="tab-pane fade" id="paneVentaAcumulada" role="tabpanel">
                            <div class="cargando-slot" id="loadingAcumulada" data-cargando="Cargando venta acumulada…"></div>

                            <div id="wrapperAcumulada" style="display: none;">
                                <div class="table-responsive tabla-temporal">
                                    <!-- data-orden="no": tiene una columna de
                                         ACUMULADO, que sólo significa algo con
                                         los meses en orden. Ordenada por otra
                                         columna, esa columna se leería como una
                                         serie que sube y baja sin sentido. -->
                                    <table id="tablaAcumulada" class="table table-hover mb-0" data-orden="no">
                                        <thead>
                                            <tr id="acumuladaHeader"></tr>
                                        </thead>
                                        <tbody id="acumuladaBody"></tbody>
                                        <tfoot class="table-light">
                                            <tr id="acumuladaTotals"></tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Venta Balance: 1/8 al 31/7, real + proyectado, con IVA -->
                        <div class="tab-pane fade" id="paneVentaBalance" role="tabpanel">
                            <div class="cargando-slot" id="loadingBalance" data-cargando="Cargando venta del balance…"></div>

                            <div id="wrapperBalance" style="display: none;">
                                <div class="table-responsive tabla-temporal">
                                    <!-- data-orden="no" por el mismo motivo que
                                         tablaAcumulada: lleva el acumulado del
                                         balance. -->
                                    <table id="tablaBalance" class="table table-hover mb-0" data-orden="no">
                                        <thead>
                                            <tr id="balanceHeader"></tr>
                                        </thead>
                                        <tbody id="balanceBody"></tbody>
                                        <tfoot class="table-light" id="balanceFoot"></tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Bloque de control de facturación. NO entra en la proyección. -->
            <div class="card mb-4 bloque-control">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-check me-1"></i>
                        Control de Facturación
                    </h5>
                    <small class="text-muted">
                        Facturas y remitos por mes. <strong>No entra en la proyección</strong>:
                        existe únicamente para contrastar el total contra el tablero.
                    </small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive tabla-temporal">
                        <table id="tablaFacturacion" class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="col-canal">Mes-Año</th>
                                    <th class="text-end">Facturas</th>
                                    <th class="text-end">Remitos</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody id="facturacionBody"></tbody>
                            <tfoot class="table-light">
                                <tr id="facturacionTotals"></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================================
             SUB-PESTAÑA: PROYECCIÓN
             ============================================================ -->
        <div class="tab-pane fade" id="paneProyeccion" role="tabpanel">

            <!-- KPI Cards -->
            <div class="row g-3 mb-4" id="summaryProyeccion" style="display: none;">
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Venta Próximas 4 Semanas</span>
                            <div class="kpi-card-icon blue">
                                <i class="fas fa-calendar-week"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value" id="kpiVentaTramo">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">Venta estimada con IVA</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Cobranza Próximas 4 Semanas</span>
                            <div class="kpi-card-icon green">
                                <i class="fas fa-hand-holding-dollar"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value" id="kpiCobranzaTramo">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">Sobre ventas estimadas</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Venta 12 Meses</span>
                            <div class="kpi-card-icon orange">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value" id="kpiVentaHorizonte">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">Venta estimada con IVA</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Cobranza 12 Meses</span>
                            <div class="kpi-card-icon green">
                                <i class="fas fa-sack-dollar"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value" id="kpiCobranzaHorizonte">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">Horizonte completo</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aviso: sólo proyectada -->
            <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
                <i class="fas fa-circle-info mt-1"></i>
                <div>
                    <small>
                        Esta pestaña muestra <strong>únicamente cobranza proyectada sobre ventas futuras</strong>.
                        La cobranza real de facturas ya emitidas vive en Cobranzas FR y Cobranzas May;
                        en el cashflow consolidado se suman las dos.
                    </small>
                </div>
            </div>

            <div id="warningsProyeccion"></div>

            <!-- Bloque de participación del tramo de 28 días -->
            <div class="card mb-4" id="cardParticipacion" style="display: none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Participación por Canal &mdash; Tramo de 28 días</h5>
                        <small class="text-muted">
                            El % calculado sale del mismo período del año anterior. Podés editarlo:
                            la suma de los cuatro canales debe dar exactamente 100%.
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span id="sumaParticipacion" class="suma-participacion">0,0000%</span>
                        <button id="btnGuardarParticipacion" class="btn btn-sm btn-primary" disabled>
                            <i class="fas fa-floppy-disk me-1"></i> Guardar
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="avisoTramoEstimado" class="alert alert-warning py-2 px-3 mb-3" style="display: none;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        <small>
                            El mismo período del año anterior no tiene datos o su venta total es cero.
                            Se están usando los porcentajes fijos de respaldo y el tramo queda
                            <strong>marcado como estimado</strong>.
                        </small>
                    </div>
                    <div class="row g-3" id="gridParticipacion">
                        <!-- Se genera dinámicamente -->
                    </div>
                </div>
            </div>

            <!-- Bloque VENTA -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Venta Proyectada</h5>
                        <small class="text-muted">
                            Venta con IVA por canal
                            <i class="fas fa-info-circle ms-1"
                               title="Los días del tramo se muestran en columnas diarias. La columna del mes acumula únicamente los días que quedaron fuera del tramo: nada se cuenta dos veces. En la vista Meses, el encabezado de cada mes abre la participación por canal de esa columna."></i>
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <!-- Los tres botones los dibuja Js/eje-vistas.js, el
                             mismo componente que el tablero: la grilla de
                             proyección y la fila del Cashflow que sale de ella
                             no pueden medir períodos distintos.
                             Los botones gobiernan las DOS tablas de este bloque,
                             Venta y Cobranza. -->
                        <div id="vistasProy"></div>
                        <button id="btnRefreshProyeccion" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-sync-alt me-1"></i> Actualizar
                        </button>
                        <button id="btnExportProyeccion" class="btn btn-sm btn-success">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <!-- Qué período se está midiendo. La vista Meses no cubre el
                     horizonte completo: sus columnas acumulan sólo los días que
                     quedan fuera del tramo diario. -->
                <div class="card-body py-2 border-bottom">
                    <small class="text-muted" id="periodoProy"></small>
                </div>

                <div class="card-body p-0">
                    <div class="cargando-slot" id="loadingProyeccion" data-cargando="Calculando proyección…"></div>

                    <div id="wrapperVenta" style="display: none;">
                        <div class="table-responsive tabla-temporal">
                            <table id="tablaVenta" class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="col-canal">Canal</th>
                                        <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                                        <th colspan="1" class="table-group-divider" id="ventaPeriodoHeader">Período</th>
                                    </tr>
                                    <tr id="ventaHeaderSub"></tr>
                                </thead>
                                <tbody id="ventaBody"></tbody>
                                <tfoot class="table-light">
                                    <tr id="ventaTotals"></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bloque COBRANZA -->
            <div class="card mb-4" id="cardCobranza" style="display: none;">
                <div class="card-header d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-0">Cobranza Proyectada</h5>
                        <small class="text-muted">
                            Por canal y medio de pago, con la fecha real de acreditación
                            <i class="fas fa-info-circle ms-1"
                               title="Cada día de venta se acredita a los días del medio de pago y, si cae en día no laboral, se corre al próximo día hábil bancario. Que los lunes acumulen más es efecto del corrimiento del sábado y el domingo."></i>
                        </small>
                    </div>
                    <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                    <div id="colFijasCobranza"></div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive tabla-temporal">
                        <table id="tablaCobranza" class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="col-canal">Canal</th>
                                    <th rowspan="2" class="col-medio">Medio de Pago</th>
                                    <th rowspan="2" class="text-center">Mix</th>
                                    <th rowspan="2" class="text-center">Días</th>
                                    <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                                    <th colspan="1" class="table-group-divider" id="cobPeriodoHeader">Período</th>
                                </tr>
                                <tr id="cobHeaderSub"></tr>
                            </thead>
                            <tbody id="cobBody"></tbody>
                            <tfoot class="table-light" id="cobFoot"></tfoot>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="Js/Ingresos-Ventas.js?v=<?php echo time(); ?>"></script>
