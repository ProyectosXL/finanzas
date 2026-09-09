<?php $tabName = 'Cob. Electrónicos'; ?>
<link rel="stylesheet" href="Css/Cob-Electronicos.css?v=<?php echo time(); ?>">

<!--
    Pestaña Cob. Electrónicos.

    Las acreditaciones que las procesadoras de pago (Payway, Mercado Pago) van a
    depositar en nuestro banco. Alimenta la fila "Cobranzas Pagos Electrónicos"
    de la sección Disponibilidades del tablero.

    Una sola pestaña, sin sub-pestañas. Las procesadoras y sus alícuotas se
    administran en Parámetros → Cob. Electrónicos.

    LO QUE HAY QUE ENTENDER MIRANDO ESTA PANTALLA:

      · El importe BRUTO y la FECHA son los datos de entrada, y son lo único
        editable. La TASA y el NETO los calcula el servidor y no se pueden
        tipear: aceptarlos del navegador permitiría guardar cualquier número
        como si fuera el calculado.

      · Un movimiento con fecha anterior al inicio del horizonte NO entra al
        tablero, y no es un error: ya se acreditó, así que esa plata ya está
        informada en el saldo bancario de la pestaña Saldos. Se ve igual, en su
        fila, marcada.

      · Esta fila NO se cruza con "Cobros s/ ventas estimadas" de Ventas, por
        decisión del negocio. No hay deducción ni prorrateo entre las dos.
-->
<div class="tab-cobel">

    <div id="avisosCobel"></div>

    <!-- ============================================================
         INDICADORES
         ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Importe Bruto</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-file-invoice-dollar"></i></div>
                </div>
                <div class="kpi-card-value" id="cobelTotalBruto">sin cargar</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cobelDetalleBruto">Lo que informa la procesadora</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Retenciones</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-scissors"></i></div>
                </div>
                <div class="kpi-card-value" id="cobelTotalRetenido">sin cargar</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">IIBB, SICREB y lo que se cargue</span>
                </div>
            </div>
        </div>

        <!-- El neto es el único número de esta pantalla que el tablero consume -->
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card kpi-card-destacada">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Importe Neto</span>
                    <div class="kpi-card-icon purple"><i class="fas fa-table-cells"></i></div>
                </div>
                <div class="kpi-card-value" id="cobelTotalNeto">sin cargar</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cobelDetalleNeto">Lo que entra a la cuenta</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Fuera del Horizonte</span>
                    <div class="kpi-card-icon" style="background: rgba(220,53,69,.1); color: #dc3545;">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                </div>
                <div class="kpi-card-value kpi-card-value-sm" id="cobelFueraEje">—</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cobelDetalleFuera">Netos que el tablero no muestra</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MOVIMIENTOS
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Acreditaciones informadas</h5>
                <small class="text-muted">
                    El <strong>importe bruto</strong> y la <strong>fecha de acreditación</strong>
                    son los datos de entrada. La <strong>tasa</strong> y el
                    <strong>importe neto</strong> los calcula el servidor con la alícuota vigente
                    de esa procesadora <em>a la fecha de acreditación</em>, y quedan guardados con
                    el movimiento: editar un porcentaje no reescribe lo ya informado.
                </small>
            </div>
            <div class="d-flex gap-2">
                <button id="btnRefreshCobel" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnNuevoMovimiento" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus me-1"></i> Nuevo movimiento
                </button>
            </div>
        </div>

        <!-- Alta inline -->
        <div class="card-body border-bottom" id="formMovimiento" style="display: none;">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Procesadora</label>
                    <select id="nuevoProcesadora" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Importe bruto</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0.01" id="nuevoBruto"
                               class="form-control text-end">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Fecha de acreditación</label>
                    <input type="date" id="nuevoFecha" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Observaciones</label>
                    <input type="text" id="nuevoObservaciones" class="form-control form-control-sm"
                           maxlength="200" placeholder="Opcional">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button id="btnAgregarMovimiento" class="btn btn-sm btn-primary flex-fill">
                        <i class="fas fa-check me-1"></i> Agregar
                    </button>
                    <button id="btnCancelarMovimiento" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- El neto que se va a guardar, antes de guardarlo. Es una vista
                 previa de lo que resuelve el servidor: el número que vale es el
                 que devuelve el guardado. -->
            <div class="cobel-preview mt-2" id="previewNeto"></div>
        </div>

        <div class="card-body p-0">
            <div class="cobel-filtros border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Procesadora</label>
                        <select id="filtroProcesadora" class="form-select form-select-sm">
                            <option value="0">Todas</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Acreditación desde</label>
                        <input type="date" id="filtroDesde" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">hasta</label>
                        <input type="date" id="filtroHasta" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="btnFiltrar" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-filter me-1"></i> Filtrar
                        </button>
                        <button id="btnLimpiarFiltro" class="btn btn-sm btn-outline-secondary">
                            Limpiar
                        </button>
                    </div>
                    <div class="col-md-2 text-md-end">
                        <span class="cobel-eje" id="cobelEje"></span>
                    </div>
                </div>
            </div>

            <div class="loading-spinner" id="loadingCobel">
                <div class="spinner"></div>
                <p>Cargando acreditaciones...</p>
            </div>

            <div class="table-responsive" id="wrapperCobel" style="display: none;">
                <table class="table table-hover mb-0" id="tablaCobel">
                    <thead>
                        <tr>
                            <th>Procesadora</th>
                            <th class="text-end">Importe bruto</th>
                            <th class="text-center">Fecha de acreditación</th>
                            <th class="text-center">Tasa aplicada</th>
                            <th class="text-end">Importe neto</th>
                            <th class="text-center">Origen</th>
                            <th class="text-center" style="width: 110px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="bodyCobel"></tbody>
                    <tfoot class="table-light" id="footCobel"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TOTALES POR PROCESADORA
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Totales por procesadora</h5>
            <small class="text-muted">
                En bruto y en neto. La diferencia son las retenciones que la procesadora descuenta
                y que nunca llegan al banco.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Procesadora</th>
                            <th class="text-center">Movimientos</th>
                            <th class="text-end">Importe bruto</th>
                            <th class="text-end">Retenciones</th>
                            <th class="text-end">Importe neto</th>
                        </tr>
                    </thead>
                    <tbody id="bodyPorProcesadora"></tbody>
                    <tfoot class="table-light" id="footPorProcesadora"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================
         EL CUADRO QUE CONSUME EL TABLERO
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Acreditaciones por día y por mes</h5>
            <small class="text-muted">
                Es la agrupación que consume el tablero: <strong>suma de netos</strong> por fecha de
                acreditación, sin corrimiento a día hábil ni tratamiento de feriados. Reemplaza al
                bloque de columnas de vencimientos del Excel, que no se migró.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-6">
                    <h6 class="cobel-subtitulo">Por día</h6>
                    <div class="table-responsive cobel-cuadro">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th class="text-center">Mov.</th>
                                    <th class="text-end">Neto acreditado</th>
                                </tr>
                            </thead>
                            <tbody id="bodyPorDia"></tbody>
                        </table>
                    </div>
                </div>

                <div class="col-lg-6">
                    <h6 class="cobel-subtitulo">Por mes</h6>
                    <div class="table-responsive cobel-cuadro">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    <th class="text-center">Mov.</th>
                                    <th class="text-end">Neto acreditado</th>
                                </tr>
                            </thead>
                            <tbody id="bodyPorMes"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="cobel-nota mt-3">
                <i class="fas fa-circle-info me-1"></i>
                Las dos agrupaciones son la misma suma de netos vista de dos formas, así que dan el
                mismo total. Las filas marcadas quedan <strong>fuera del horizonte</strong> del
                tablero: las anteriores a
                <span id="cobelDesde" class="fw-semibold">hoy</span> ya se acreditaron y están
                informadas en el saldo bancario de la pestaña Saldos, así que sumarlas acá las
                contaría dos veces.
            </div>
        </div>
    </div>

</div><!-- /tab-cobel -->

<script src="Js/Cob-Electronicos.js?v=<?php echo time(); ?>"></script>
