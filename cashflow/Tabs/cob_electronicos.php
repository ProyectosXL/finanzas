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

      · Las acreditaciones ya ocurridas —fecha anterior al inicio del
        horizonte— NO se muestran ni se avisan: ya pasaron, ya entraron a la
        cuenta y están informadas en el saldo bancario de la pestaña Saldos. Se
        ven con el switch "Ver también las ya acreditadas".

      · La carga se puede hacer a mano o importando el archivo de la
        procesadora. El importador muestra las diferencias contra lo cargado
        ANTES de escribir: es lo que permite actualizar seguido sin comparar
        fila por fila.

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

        <!-- Sólo lo POSTERIOR al horizonte: es lo único que el tablero deja de
             mostrar teniendo que mostrarlo. Lo ya acreditado no cuenta acá,
             porque no falta en el tablero: lo informa el saldo bancario. -->
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Posterior al Horizonte</span>
                    <div class="kpi-card-icon" style="background: rgba(220,53,69,.1); color: #dc3545;">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                </div>
                <div class="kpi-card-value kpi-card-value-sm" id="cobelFueraEje">—</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cobelDetalleFuera">
                        Netos con fecha más allá del eje
                    </span>
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
                <!-- Exportar lo que se ve. Lo engancha Js/tabla-export.js por el
                     data-exportar: no hace falta JS en la pestaña. -->
                <button class="btn btn-sm btn-success" data-exportar="tablaCobel"
                        data-exportar-nombre="Cobranzas_Electronicas_Acreditaciones"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
                <button id="btnRefreshCobel" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnImportar" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-file-import me-1"></i> Importar planilla
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

        <!-- ========================================================
             IMPORTADOR
             ========================================================
             Dos pasos: previsualizar y confirmar. El primero no escribe nada.
             Es lo que permite actualizar seguido sin tener que comparar a mano
             qué cambió respecto de lo ya cargado.
        -->
        <div class="card-body border-bottom cobel-importador" id="panelImportar"
             style="display: none;">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h6 class="mb-1">Importar desde planilla</h6>
                    <small class="text-muted">
                        Bajá la plantilla, pegá las acreditaciones y subila. Se muestra
                        <strong>qué cambiaría</strong> y recién después se escribe. Excel guarda
                        CSV desde <em>Archivo → Guardar como → CSV UTF-8</em>.
                    </small>
                </div>
                <a href="Controller/CobElectronicosController.php?action=plantilla"
                   class="btn btn-sm btn-outline-secondary" download>
                    <i class="fas fa-file-arrow-down me-1"></i> Descargar plantilla
                </a>
            </div>

            <div class="row g-2 align-items-end mt-2">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Archivo (.csv)</label>
                    <input type="file" id="archivoImportar" class="form-control form-control-sm"
                           accept=".csv,.txt,text/csv">
                </div>
                <!--
                    El período que cubre el archivo es OPCIONAL y sirve para una
                    sola cosa: detectar las acreditaciones que la procesadora dio
                    de baja. Si la que desapareció era la primera o la última del
                    período, su fecha ya no está en el archivo, así que el rango
                    que se puede inferir se encoge y ese movimiento queda justo
                    afuera. Declararlo —el usuario sabe qué exportó— es lo que
                    permite verlo. Nunca se adivina.
                -->
                <div class="col-md-2">
                    <label class="form-label form-label-sm">El archivo cubre desde</label>
                    <input type="date" id="periodoDesde" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">hasta</label>
                    <input type="date" id="periodoHasta" class="form-control form-control-sm">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button id="btnPrevisualizar" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye me-1"></i> Ver diferencias
                    </button>
                    <button id="btnCerrarImportar" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="cobel-nota mt-2">
                Columnas: <strong>PROCESADORA</strong>, <strong>IMPORTE_BRUTO</strong> y
                <strong>FECHA_ACREDITACION</strong> son obligatorias;
                <strong>ID_EXTERNO</strong> (el número de liquidación) y
                <strong>OBSERVACIONES</strong> son opcionales. El importe neto y la tasa
                <strong>no van en el archivo</strong>: los calcula el servidor. Las filas ya
                acreditadas se ignoran, y si hay un solo error no se importa nada.
                Completar <em>desde / hasta</em> sólo hace falta para detectar acreditaciones que
                la procesadora dio de baja al principio o al final del período: si no se completa,
                el período se infiere de las fechas del archivo.
            </div>

            <!-- El resultado de la previsualización y la confirmación -->
            <div id="resultadoImportar"></div>
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
                    <!-- Las ya acreditadas no se muestran por defecto: ya
                         pasaron -o pasan hoy- y no hay nada que hacer con
                         ellas. El switch existe para poder auditarlas, no
                         para trabajar. -->
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="verAcreditadas">
                            <label class="form-check-label cobel-eje" for="verAcreditadas">
                                Ver también las ya acreditadas (fecha de hoy o anterior).
                                No entran al tablero: esa plata ya está —o va a estar hoy— en
                                el saldo bancario de la pestaña Saldos.
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cargando-slot" id="loadingCobel" data-cargando="Cargando acreditaciones…"></div>

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
        <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Totales por procesadora</h5>
                <small class="text-muted">
                    En bruto y en neto. La diferencia son las retenciones que la procesadora descuenta
                    y que nunca llegan al banco.
                </small>
            </div>
            <!-- Un botón por tabla, en su propia card-header: son cuadros
                 distintos y bajar "la pestaña" no querría decir nada. -->
            <button class="btn btn-sm btn-success" data-exportar="tablaCobelProcesadoras"
                    data-exportar-nombre="Cobranzas_Electronicas_Por_Procesadora"
                    title="Exportar a Excel lo que se está viendo">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="tablaCobelProcesadoras">
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
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="cobel-subtitulo">Por día</h6>
                        <button class="btn btn-sm btn-success" data-exportar="tablaCobelPorDia"
                                data-exportar-nombre="Cobranzas_Electronicas_Por_Dia"
                                title="Exportar a Excel lo que se está viendo">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                    <div class="table-responsive cobel-cuadro">
                        <table class="table table-sm table-hover mb-0" id="tablaCobelPorDia">
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
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="cobel-subtitulo">Por mes</h6>
                        <button class="btn btn-sm btn-success" data-exportar="tablaCobelPorMes"
                                data-exportar-nombre="Cobranzas_Electronicas_Por_Mes"
                                title="Exportar a Excel lo que se está viendo">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                    <div class="table-responsive cobel-cuadro">
                        <table class="table table-sm table-hover mb-0" id="tablaCobelPorMes">
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
                mismo total. Lo pendiente arranca <strong>mañana</strong>
                (<span id="cobelDesde" class="fw-semibold">mañana</span>): lo de hoy y lo
                anterior ya está —o va a estar hoy— en el saldo bancario de la pestaña Saldos,
                así que sumarlo acá lo contaría dos veces. Una fila
                marcada es una acreditación con fecha <strong>posterior</strong> al eje, que el
                tablero todavía no puede mostrar.
            </div>
        </div>
    </div>

</div><!-- /tab-cobel -->

<script src="Js/Cob-Electronicos.js?v=<?php echo time(); ?>"></script>
