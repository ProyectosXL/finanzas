<?php $tabName = 'Ventas'; ?>
<link rel="stylesheet" href="Css/Ingresos-Ventas.css">

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

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Venta Neta Histórica por Mes y Canal</h5>
                        <small class="text-muted">
                            Importe neto sin IVA, sólo facturas
                            <i class="fas fa-info-circle ms-1"
                               title="Click en el índice de variación para editarlo. El índice proyecta el mismo mes del año siguiente."></i>
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
                <div class="card-body p-0">
                    <div class="loading-spinner" id="loadingAnalisis">
                        <div class="spinner"></div>
                        <p>Cargando datos...</p>
                    </div>

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
            </div>

            <!-- Bloque de control: remitos. NO entra en la proyección. -->
            <div class="card mb-4 bloque-control">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-check me-1"></i>
                        Control de Remitos
                    </h5>
                    <small class="text-muted">
                        Total de remitos por mes y canal. <strong>No entra en la proyección</strong>:
                        existe únicamente para contrastar el total contra el tablero.
                    </small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive tabla-temporal">
                        <table id="tablaRemitos" class="table table-hover mb-0">
                            <thead>
                                <tr id="remitosHeader"></tr>
                            </thead>
                            <tbody id="remitosBody"></tbody>
                            <tfoot class="table-light">
                                <tr id="remitosTotals"></tr>
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
                            <span class="text-muted">Horizonte completo</span>
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
                               title="Los días del tramo se muestran en columnas diarias. La columna del mes acumula únicamente los días que quedaron fuera del tramo: nada se cuenta dos veces."></i>
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <div class="btn-group" role="group">
                            <button id="btnVistaSemanasProy" class="btn btn-sm btn-primary">
                                <i class="fas fa-calendar-week me-1"></i> Semanas
                            </button>
                            <button id="btnVistaMesesProy" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-calendar-alt me-1"></i> Meses
                            </button>
                        </div>
                        <button id="btnRefreshProyeccion" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-sync-alt me-1"></i> Actualizar
                        </button>
                        <button id="btnExportProyeccion" class="btn btn-sm btn-success">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="loading-spinner" id="loadingProyeccion">
                        <div class="spinner"></div>
                        <p>Calculando proyección...</p>
                    </div>

                    <div id="wrapperVenta" style="display: none;">
                        <div class="table-responsive tabla-temporal">
                            <table id="tablaVenta" class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="col-canal">Canal</th>
                                        <th colspan="28" class="table-group-divider" id="ventaPeriodoHeader">Período</th>
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
                <div class="card-header">
                    <h5 class="mb-0">Cobranza Proyectada</h5>
                    <small class="text-muted">
                        Por canal y medio de pago, con la fecha real de acreditación
                        <i class="fas fa-info-circle ms-1"
                           title="Cada día de venta se acredita a los días del medio de pago y, si cae en día no laboral, se corre al próximo día hábil bancario. Que los lunes acumulen más es efecto del corrimiento del sábado y el domingo."></i>
                    </small>
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
                                    <th colspan="28" class="table-group-divider" id="cobPeriodoHeader">Período</th>
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
