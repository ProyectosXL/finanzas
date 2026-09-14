<?php $tabName = 'Saldos'; ?>
<link rel="stylesheet" href="Css/Saldos.css?v=<?php echo time(); ?>">

<!--
    Pestaña Saldos. Dos sub-pestañas que son dos cosas distintas:

      Saldos         -> alimenta la fila "Saldo Inicial" del tablero. Carga
                        periódica (los lunes) y en parte manual.
      Saldos Locales -> alimenta la fila "Caja Locales". Sale de una consulta
                        contra Tango que se actualiza sola todos los días.

    Se piden por separado a propósito: la segunda consulta el servidor de
    locales, que puede estar caído, y en ese caso la primera tiene que seguir
    dibujándose igual.

    REGLA TRANSVERSAL DEL RELEVAMIENTO: se muestra la fecha de carga de cada
    dato, para ver cuál es la última actualización. Por eso cada fila tiene su
    propia columna de fecha y no alcanza con un cartel arriba.
-->
<div class="tab-saldos">

    <div id="avisosSaldos"></div>

    <ul class="nav nav-tabs mb-3" id="saldosTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabSaldosBtn" data-bs-toggle="tab"
                    data-bs-target="#paneSaldos" type="button" role="tab">
                <i class="fas fa-building-columns me-1"></i> Saldos
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabLocalesBtn" data-bs-toggle="tab"
                    data-bs-target="#paneLocales" type="button" role="tab">
                <i class="fas fa-store me-1"></i> Saldos Locales
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ============================================================
         PESTAÑA 1 — SALDOS
         ============================================================ -->
    <div class="tab-pane fade show active" id="paneSaldos" role="tabpanel">

        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Total en Pesos</span>
                        <div class="kpi-card-icon blue"><i class="fas fa-sack-dollar"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalArs">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="cuentasArs">0 cuentas</span>
                    </div>
                </div>
            </div>

            <!-- Los dólares NO se convierten para mostrar: la pestaña cierra
                 con un total en cada moneda. La conversión existe sólo para lo
                 que el proveedor le entrega al Cashflow, que exige pesos. -->
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Total en Dólares</span>
                        <div class="kpi-card-icon green"><i class="fas fa-dollar-sign"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalUsd">US$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="cuentasUsd">0 cuentas</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Última Carga</span>
                        <div class="kpi-card-icon orange"><i class="fas fa-clock-rotate-left"></i></div>
                    </div>
                    <div class="kpi-card-value kpi-card-value-sm" id="ultimaCargaSaldos">—</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="ultimaCargaSaldosDetalle">Sin cargas</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Efectivo Tesorería</span>
                        <div class="kpi-card-icon purple"><i class="fas fa-cash-register"></i></div>
                    </div>
                    <div class="kpi-card-value kpi-card-value-sm" id="efectivoCentral">—</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="efectivoCentralDetalle">
                            Saldo actual de la consulta
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Saldos por cuenta</h5>
                    <small class="text-muted">
                        Cada fila muestra su <strong>último saldo conocido</strong> con la fecha en
                        que se cargó. Los datos no se pisan: cada carga es un registro nuevo.
                    </small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <!-- Filtro por tipo de cuenta. Es de pantalla: los KPI, la
                         tabla y el pie muestran lo filtrado, pero lo que se
                         guarda y lo que consume el tablero es siempre todo.
                         Mientras se carga se deshabilita: una carga con filas
                         escondidas las guardaria en cero. -->
                    <select id="filtroTipoSaldos" class="form-select form-select-sm w-auto"
                            title="Filtra la tabla y los totales por tipo de cuenta">
                        <option value="">Todos los tipos</option>
                        <option value="BANCO">Banco</option>
                        <option value="MERCADO_PAGO">Mercado Pago</option>
                        <option value="EFECTIVO_CENTRAL">Efectivo</option>
                        <option value="OTRO">Otro</option>
                    </select>
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-success" data-exportar="tablaSaldos"
                            data-exportar-nombre="Saldos_Por_Cuenta"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button id="btnRefreshSaldos" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnNuevaCarga" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus me-1"></i> Nueva carga
                    </button>
                    <button id="btnGuardarCarga" class="btn btn-sm btn-success" style="display: none;">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar carga
                    </button>
                    <button id="btnCancelarCarga" class="btn btn-sm btn-outline-secondary" style="display: none;">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom" id="formCarga" style="display: none;">
                <div class="alert alert-info py-2 px-3 mb-3">
                    <small>
                        <i class="fas fa-circle-info me-1"></i>
                        El <strong>efectivo de tesorería</strong> no se tipea: lo vuelve a leer el
                        sistema de su consulta al guardar. Los saldos bancarios y de Mercado Pago
                        se cargan a mano; el formulario se mantiene aunque más adelante entre la
                        API de Interbanking, como respaldo ante una falla de la integración.
                    </small>
                </div>
                <label class="form-label form-label-sm">Observaciones de la carga</label>
                <input type="text" id="observacionesCarga" class="form-control form-control-sm"
                       maxlength="500" placeholder="Opcional: de dónde salieron los saldos, quién los pasó…">
            </div>

            <div class="card-body p-0">
                <div class="loading-spinner" id="loadingSaldos">
                    <div class="spinner"></div>
                    <p>Cargando saldos...</p>
                </div>

                <div class="table-responsive" id="wrapperSaldos" style="display: none;">
                    <table class="table table-hover mb-0" id="tablaSaldos">
                        <thead>
                            <tr>
                                <th>Cuenta</th>
                                <th>Tipo</th>
                                <th class="text-center">Moneda</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-center">Fecha del saldo</th>
                                <th class="text-center">Origen</th>
                                <th class="text-center">Cargado el</th>
                            </tr>
                        </thead>
                        <tbody id="bodySaldos"></tbody>
                        <tfoot class="table-light" id="footSaldos"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PESTAÑA 2 — SALDOS LOCALES
         ============================================================ -->
    <div class="tab-pane fade" id="paneLocales" role="tabpanel">

        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Saldo en Caja</span>
                        <div class="kpi-card-icon blue"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalCajaLocales">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="detalleLocales">0 locales</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Reserva de Caja</span>
                        <div class="kpi-card-icon orange"><i class="fas fa-vault"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalReserva">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted">Mínimo que conservan los locales</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Neto a Depositar</span>
                        <div class="kpi-card-icon green"><i class="fas fa-arrow-right-to-bracket"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalNeto">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted">Saldo − reserva, sin impuestos</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card kpi-card-destacada">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Aporta al Cashflow</span>
                        <div class="kpi-card-icon purple"><i class="fas fa-table-cells"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalAporta">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="detalleAporta">Sólo los que depositan</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Caja de los locales propios</h5>
                    <!--
                        Qué guarda el botón, dicho en la pantalla. El saldo sale
                        de la consulta y sólo se tipea cuando ésta no trajo el
                        cierre: queda como manual fechado ayer y manda hasta que
                        la consulta traiga uno más nuevo. Gestión y Reserva son
                        los dos valores que no están en Tango.
                    -->
                    <small class="text-muted">
                        El <strong>saldo</strong> sale de la consulta: las filas
                        <span class="sal-leyenda-desactualizado">resaltadas</span> no tienen el
                        cierre de ayer, y ahí se puede tipear el saldo real; al guardar queda como
                        <em>manual</em> y manda hasta que la consulta traiga uno más nuevo.
                        <strong>Gestión</strong> y <strong>Reserva</strong> se editan acá:
                        <em>Guardar</em> las deja como valor por defecto del local —es el mismo
                        dato que Parámetros → Saldos— y guarda la foto del día en el histórico.
                        Sólo los locales en <em>Deposita</em> entran al cashflow, y un neto
                        negativo aporta cero.
                    </small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span id="avisoGuardadoLocales" class="sal-aviso-guardado" style="display: none;"></span>
                    <!-- Lo engancha Js/tabla-export.js. Los campos editables de
                         Gestión y Reserva se exportan como su valor en texto, no
                         como un <input>. -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaLocales"
                            data-exportar-nombre="Saldos_Caja_Locales"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button id="btnRefreshLocales" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnGuardarLocales" class="btn btn-sm btn-success"
                            title="Guarda la gestión y la reserva del local, los saldos tipeados a mano, y la foto del día en el histórico">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="loading-spinner" id="loadingLocales">
                    <div class="spinner"></div>
                    <p>Consultando la caja de los locales...</p>
                </div>

                <div class="table-responsive" id="wrapperLocales" style="display: none;">
                    <table class="table table-hover mb-0" id="tablaLocales">
                        <thead>
                            <tr>
                                <th>Local</th>
                                <th class="text-end">Saldo en caja</th>
                                <th class="text-center">Fecha del saldo</th>
                                <th class="text-center" style="width: 150px;">Gestión</th>
                                <th class="text-center" style="width: 170px;">Reserva de caja</th>
                                <th class="text-end">Neto a depositar</th>
                                <th class="text-end">Aporta al cashflow</th>
                            </tr>
                        </thead>
                        <tbody id="bodyLocales"></tbody>
                        <tfoot class="table-light" id="footLocales"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    </div><!-- /tab-content -->
</div><!-- /tab-saldos -->

<script src="Js/Saldos.js?v=<?php echo time(); ?>"></script>
