<!--
    Parámetros → Saldos.

    Va en su propio archivo y con su propio JS, igual que el editor de
    estructura del Cashflow: no comparte nada con los bloques de Ventas, así que
    un problema acá no puede llevarse puesta la pestaña que ya funciona. Sus
    clases llevan el prefijo sp- porque Parametros.js busca .param-input, .mix-*
    y .respaldo-* en TODO el documento.

    Cinco secciones:
      1. Generales        — la cuenta contable de tesorería y el aviso de carga vieja
      2. Bancos y cuentas — ABM de cuentas bancarias, con moneda y clase
      3. Otros saldos     — Mercado Pago, efectivo de tesorería y lo que aparezca
      4. Fondos           — cuentas de inversión y comitente, con su saldo inicial
      5. Locales          — gestión y reserva de caja por sucursal

    Las secciones 2, 3 y 4 son el MISMO ABM sobre RO_T_CASHFLOW_SALDOS_CUENTA,
    separado por TIPO (banco / otro) y por CLASE (fondo o no). Una cuenta a la
    vista se carga como foto del saldo en la pestaña Saldos; un fondo lleva
    cuenta corriente y sus movimientos se cargan en Saldos → Fondos.

    Nunca hay baja física: se inhabilita.
-->
<div class="sp-saldos">

    <div class="modulo-descripcion mb-3" id="descripcionSaldos"></div>

    <div id="avisosParamSaldos"></div>

    <div class="loading-spinner" id="loadingParamSaldos">
        <div class="spinner"></div>
        <p>Cargando parámetros de Saldos...</p>
    </div>

    <div id="wrapperParamSaldos" style="display: none;">

        <!-- ========================================================
             GENERALES
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Generales</h5>
                    <small class="text-muted">
                        La cuenta contable del efectivo de tesorería y cada cuántos días se avisa
                        que la carga de saldos quedó vieja
                    </small>
                </div>
                <button id="btnRefreshParamSaldos" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3" id="gridGeneralesSaldos"></div>
            </div>
        </div>

        <!-- ========================================================
             BANCOS Y CUENTAS
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Bancos y cuentas</h5>
                    <small class="text-muted">
                        Una misma entidad puede tener saldos en pesos y en dólares, así que va una
                        fila por banco y moneda. Las cuentas se <strong>inhabilitan</strong>, nunca
                        se borran: su histórico queda entero.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaSpCuentas"
                            data-exportar-nombre="Parametros_Saldos_Cuentas"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button class="btn btn-sm btn-outline-primary sp-btn-nueva" data-tipo="BANCO">
                        <i class="fas fa-plus me-1"></i> Agregar banco
                    </button>
                    <button id="btnGuardarCuentas" class="btn btn-sm btn-primary">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar cuentas
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom sp-form-nueva" data-tipo="BANCO" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Nombre del banco</label>
                        <input type="text" class="form-control form-control-sm sp-nuevo-nombre"
                               data-tipo="BANCO" maxlength="80" placeholder="Ej: Banco Galicia">
                    </div>
                    <!-- La clase dice qué es la cuenta. Para un banco, cuenta
                         corriente o caja de ahorro; los fondos tienen su propia
                         sección abajo. El JS la deshabilita si el script que
                         crea la columna no se corrió. -->
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Clase</label>
                        <select class="form-select form-select-sm sp-nueva-clase" data-tipo="BANCO">
                            <option value="CTA_CORRIENTE">Cuenta corriente</option>
                            <option value="CAJA_AHORRO">Caja de ahorro</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Moneda</label>
                        <select class="form-select form-select-sm sp-nueva-moneda" data-tipo="BANCO">
                            <option value="ARS">Pesos (ARS)</option>
                            <option value="USD">Dólares (USD)</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-sm btn-primary flex-fill sp-btn-agregar" data-tipo="BANCO">
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button class="btn btn-sm btn-outline-secondary sp-btn-cancelar" data-tipo="BANCO">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div class="param-hint mt-2">
                    La cuenta entra <strong>activa y sin saldo cargado</strong>: se muestra como
                    "sin cargar" hasta la próxima carga, así que no puede informar de menos en
                    silencio. Los campos de Interbanking (CBU, número, tipo) los va a completar la
                    integración cuando exista.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSpCuentas">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th class="text-center" style="width: 170px;">Clase</th>
                                <th class="text-center" style="width: 120px;">Moneda</th>
                                <th>Datos de Interbanking</th>
                                <th class="text-center" style="width: 110px;">Activa</th>
                            </tr>
                        </thead>
                        <tbody id="bodyBancos"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             OTROS SALDOS
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Otros saldos</h5>
                    <small class="text-muted">
                        Mercado Pago, el efectivo de tesorería de casa central y lo que aparezca
                        después. Cada uno con su moneda.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaSpOtros"
                            data-exportar-nombre="Parametros_Saldos_Otros"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button class="btn btn-sm btn-outline-primary sp-btn-nueva" data-tipo="OTRO">
                        <i class="fas fa-plus me-1"></i> Agregar saldo
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom sp-form-nueva" data-tipo="OTRO" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Nombre</label>
                        <input type="text" class="form-control form-control-sm sp-nuevo-nombre"
                               data-tipo="OTRO" maxlength="80" placeholder="Ej: Mercado Pago">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Tipo</label>
                        <select class="form-select form-select-sm sp-nuevo-tipo" data-tipo="OTRO">
                            <option value="MERCADO_PAGO">Mercado Pago</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Moneda</label>
                        <select class="form-select form-select-sm sp-nueva-moneda" data-tipo="OTRO">
                            <option value="ARS">ARS</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-sm btn-primary flex-fill sp-btn-agregar" data-tipo="OTRO">
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button class="btn btn-sm btn-outline-secondary sp-btn-cancelar" data-tipo="OTRO">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSpOtros">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th class="text-center" style="width: 150px;">Tipo</th>
                                <th class="text-center" style="width: 170px;">Clase</th>
                                <th class="text-center" style="width: 110px;">Moneda</th>
                                <th class="text-center" style="width: 140px;">Origen del dato</th>
                                <th class="text-center" style="width: 110px;">Activo</th>
                            </tr>
                        </thead>
                        <tbody id="bodyOtros"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             FONDOS: INVERSION Y CUENTA COMITENTE

             Son cuentas del mismo catálogo, con CLASE de fondo. No entran al
             Saldo Inicial: son el stock de la sección Cobertura. Su saldo es
             saldo inicial + suscripciones − rescates, y los movimientos se
             cargan en Saldos → Fondos. Acá se dan de alta y se fija el punto
             de partida.
             ======================================================== -->
        <div class="card mb-4" id="cardSpFondos">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Fondos de inversión y cuentas comitente</h5>
                    <small class="text-muted">
                        No entran al Saldo Inicial: son el <strong>stock de cobertura</strong> del
                        tablero, y cada cuenta es un fondo del que se puede aplicar. El
                        <strong>saldo inicial</strong> es el saldo <em>al cierre</em> de su fecha:
                        los movimientos de esa fecha o anteriores ya están incluidos. La moneda de
                        un fondo con movimientos no se cambia, y un fondo no pasa a cuenta a la
                        vista ni al revés: se <strong>inhabilita</strong> y se crea otro.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaSpFondos"
                            data-exportar-nombre="Parametros_Saldos_Fondos"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button class="btn btn-sm btn-outline-primary sp-btn-nueva" data-tipo="FONDO">
                        <i class="fas fa-plus me-1"></i> Agregar fondo
                    </button>
                    <button id="btnGuardarFondos" class="btn btn-sm btn-primary">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar fondos
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom sp-form-nueva" data-tipo="FONDO" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Nombre</label>
                        <input type="text" class="form-control form-control-sm sp-nuevo-nombre"
                               data-tipo="FONDO" maxlength="80" placeholder="Ej: Fondo Alyc">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Clase</label>
                        <select class="form-select form-select-sm sp-nueva-clase" data-tipo="FONDO">
                            <option value="INVERSION">Inversión</option>
                            <option value="COMITENTE">Cuenta comitente</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label form-label-sm">Moneda</label>
                        <select class="form-select form-select-sm sp-nueva-moneda" data-tipo="FONDO">
                            <option value="ARS">ARS</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Saldo inicial</label>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm text-end sp-nuevo-saldo"
                               data-tipo="FONDO" placeholder="Opcional">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Al cierre del</label>
                        <input type="date" class="form-control form-control-sm sp-nueva-fecha"
                               data-tipo="FONDO">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-sm btn-primary flex-fill sp-btn-agregar" data-tipo="FONDO">
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button class="btn btn-sm btn-outline-secondary sp-btn-cancelar" data-tipo="FONDO">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div class="param-hint mt-2">
                    El fondo entra <strong>activo</strong> y desde ese momento es stock de
                    cobertura. Sin saldo inicial arranca de cero y la pestaña Saldos lo dice; con
                    saldo inicial, va siempre con su fecha. Los movimientos se cargan en
                    Saldos → Fondos.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSpFondos">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th class="text-center" style="width: 170px;">Clase</th>
                                <th class="text-center" style="width: 110px;">Moneda</th>
                                <th class="text-center" style="width: 180px;">Saldo inicial</th>
                                <th class="text-center" style="width: 160px;">Al cierre del</th>
                                <th class="text-center" style="width: 110px;">Activa</th>
                            </tr>
                        </thead>
                        <tbody id="bodySpFondos"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             LOCALES
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Locales</h5>
                    <small class="text-muted">
                        Gestión y reserva de caja por local. Son los valores por defecto de la
                        pestaña Saldos Locales, y se pueden pisar en cada carga.
                        <strong>Sólo los locales en Deposita</strong> entran al cashflow.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaSpLocales"
                            data-exportar-nombre="Parametros_Saldos_Locales"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button id="btnSincronizarLocales" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-rotate me-1"></i> Sincronizar con locales
                    </button>
                    <button id="btnGuardarSucursales" class="btn btn-sm btn-primary">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar locales
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSpLocales">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Nro.</th>
                                <th>Local</th>
                                <th class="text-center" style="width: 160px;">Gestión</th>
                                <th class="text-center" style="width: 190px;">Monto de reserva de caja</th>
                                <th class="text-center" style="width: 150px;">Última edición</th>
                            </tr>
                        </thead>
                        <tbody id="bodySucursales"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /wrapperParamSaldos -->
</div><!-- /sp-saldos -->
