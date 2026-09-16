<?php $tabName = 'Dólares Cuenta Comitente'; ?>
<link rel="stylesheet" href="Css/OtrosIngresos-Dolares_comitente.css?v=<?php echo time(); ?>">

<!--
    Otros Ingresos → Dólares Cuenta Comitente.

    En el Excel original esta fila la tipeaba una persona. Acá se carga, y el
    formulario es deliberadamente mínimo: fecha e importe en dólares. Nada más.

    SE CARGAN DÓLARES, NO PESOS. La conversión la hace el proveedor con el
    oficial del BCRA en cada lectura del tablero. Guardar pesos congelaría la
    valuación al momento de la carga.

    LA CUENTA SE MUESTRA ABIERTA: USD × cotización (con su fecha) = pesos. El
    total en pesos del pie es exactamente el que va a la fila del cashflow, así
    que ese número se puede auditar fila por fila desde acá.

    LA COTIZACIÓN ES LA ÚLTIMA CONOCIDA A LA FECHA DE LA CARGA, no el cierre del
    mes. El mes en curso no tiene cierre todavía, y el de un mes viejo valuaría
    con una cotización de semanas después. Ver Class/Cotizacion.php.

    Y ES LA PUNTA VENDEDORA. Es la única pestaña del cashflow que no valúa con la
    compradora: estos dólares están en una cuenta y se miden contra lo que
    costaría reponerlos. Ventas, Saldos, Exportaciones Tasky y Comex siguen con
    comprador. La consecuencia —que este total NO cierre contra los de las otras
    pantallas— es deliberada, y por eso la punta se dice fila por fila y en el
    pie del KPI: si no, alguien compara contra el BCRA comprador y concluye que
    está mal.

    EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA. Cargar una fecha que ya
    existe no hace UPDATE: da de baja la anterior e inserta una nueva. El
    historial es lo único que explica por qué el número de ayer era otro.
-->
<div class="tab-dolares_comitente">

    <div id="avisosDol"></div>

    <div class="row g-3 mb-4" id="summaryDol" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card kpi-card-destacada">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Última carga</span>
                    <div class="kpi-card-icon green"><i class="fas fa-dollar-sign"></i></div>
                </div>
                <div class="kpi-card-value" id="ultimoImporteDol">US$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="ultimaFechaDol">Sin cargas</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total cargado</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-coins"></i></div>
                </div>
                <div class="kpi-card-value" id="totalDol">US$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleTotalDol">0 fecha(s) con importe vigente</span>
                </div>
            </div>
        </div>

        <!-- El número que efectivamente va al tablero. Está acá y no sólo en el
             pie de la tabla porque es el que se compara contra el cashflow, y
             bajar a buscarlo al final de la grilla es justo lo que hace que
             nadie lo compare. -->
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">En pesos, al tablero</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-scale-balanced"></i></div>
                </div>
                <div class="kpi-card-value" id="totalArsDol">$ 0,00</div>
                <div class="kpi-card-footer">
                    <!-- El texto lo escribe el JS: nombra la punta con la que
                         valuó el backend, no con una escrita acá. -->
                    <span class="text-muted" id="detalleArsDol">Valuado al oficial del BCRA</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Cargar importe</h5>
            <small class="text-muted">
                Fecha e importe en dólares. La conversión a pesos la hace el tablero con la
                <strong>última cotización oficial del BCRA anterior o igual a esa fecha, punta
                vendedora</strong>: no se guarda ningún importe en pesos. La grilla de abajo
                muestra con cuál se valuó cada carga.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="fechaDol">Fecha</label>
                    <input type="date" id="fechaDol" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="importeDol">Importe en dólares</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">US$</span>
                        <input type="number" id="importeDol" class="form-control form-control-sm"
                               min="0" step="0.01" placeholder="0,00">
                    </div>
                </div>
                <div class="col-md-3">
                    <button id="btnGuardarDol" class="btn btn-sm btn-primary">
                        <i class="fas fa-save me-1"></i> Guardar
                    </button>
                    <button id="btnRefreshDol" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                </div>
            </div>
            <div class="param-hint mt-2">
                Cargar una fecha que ya tiene importe <strong>no lo edita</strong>: la carga
                anterior queda en el historial y la nueva pasa a ser la vigente. Es lo único que
                después explica por qué el número de esa fecha cambió.
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Importes vigentes</h5>
                <small class="text-muted">
                    Un importe por fecha. Las cargas pisadas no se borran: se ven desde
                    <em>Historial</em>.
                </small>
            </div>
            <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
            <button class="btn btn-sm btn-success" data-exportar="tablaDolares"
                    data-exportar-nombre="Dolares_Comitente"
                    title="Exportar a Excel lo que se está viendo">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>
        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingDol">
                <div class="spinner"></div>
                <p>Cargando importes...</p>
            </div>

            <div class="table-responsive" id="wrapperDol" style="display: none;">
                <table class="table table-hover mb-0" id="tablaDolares">
                    <thead>
                        <tr>
                            <!-- LA CUENTA VA ABIERTA: dólares, a cuánto, de qué
                                 día, y cuánto da en pesos. El total en pesos que
                                 va al tablero tiene que poder atarse fila por
                                 fila a esta grilla; con una sola columna de
                                 dólares, el número del cashflow no se puede
                                 auditar contra nada. -->
                            <th style="width: 130px;">Fecha</th>
                            <th class="text-end" style="width: 150px;">Importe (USD)</th>
                            <!-- La punta se muestra fila por fila, y sale del
                                 backend. Es la única pestaña del cashflow que
                                 valúa con el VENDEDOR, así que su total no
                                 cierra contra el de las otras: si no lo dijera,
                                 alguien lo compara contra el BCRA comprador y
                                 concluye que está mal. -->
                            <th class="text-center" style="width: 190px;">
                                Cotización usada
                                <i class="fas fa-circle-info text-muted ms-1"
                                   title="La última cotización oficial del BCRA con fecha anterior o igual a la de la carga, punta VENDEDORA. No es el cierre del mes: el mes en curso todavía no tiene cierre. El resto del cashflow valúa con la punta compradora."></i>
                            </th>
                            <th class="text-end" style="width: 170px;">Importe (ARS)</th>
                            <th class="text-center" style="width: 170px;">Cargado el</th>
                            <th class="text-center" style="width: 150px;">Historial</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="bodyDol"></tbody>
                    <tfoot class="table-light" id="footDol"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Historial de una fecha: por qué el número de ayer era otro -->
    <div class="modal fade" id="modalHistorialDol" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        <i class="fas fa-clock-rotate-left me-1"></i>
                        Historial de <span id="historialFechaDol"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-sm mb-0" id="tablaHistorialDol">
                        <thead class="table-light">
                            <tr>
                                <th class="text-end">Importe (USD)</th>
                                <th class="text-center" style="width: 110px;">Estado</th>
                                <th class="text-center" style="width: 180px;">Cargado el</th>
                            </tr>
                        </thead>
                        <tbody id="bodyHistorialDol"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <!-- El historial también se exporta: es lo que explica por
                         qué el número de ayer era otro, y eso se comparte. -->
                    <button type="button" class="btn btn-sm btn-success"
                            data-exportar="tablaHistorialDol"
                            data-exportar-nombre="Dolares_Comitente_Historial"
                            title="Exportar a Excel el historial de esta fecha">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/OtrosIngresos-Dolares_comitente.js?v=<?php echo time(); ?>"></script>
