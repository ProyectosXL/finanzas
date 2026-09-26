<?php $tabName = 'Pagos con Tarjetas y Otros'; ?>
<link rel="stylesheet" href="Css/Financiero-Pagos_tarjetas.css?v=<?php echo time(); ?>">

<!--
    Pestaña Financiero → Pagos con Tarjetas y Otros. Tres sub-pestañas que son
    tres circuitos distintos y comparten dos cosas: el maestro de tarjetas y los
    resúmenes.

      Gastos Supervisoras   se ESTIMA a partir del histórico de
                            RO_T_GASTOS_SUPERVISION: el promedio de los últimos 3
                            meses completos, ajustado por inflación, partido en
                            efectivo (2 pagos del cronograma) y tarjeta (el día de
                            vencimiento de su tarjeta).

      Pagos Corporativos    son facturas REALES de Tango —forma de pago vigente
                            TARJETA CORP— más la cobertura de cada tarjeta. Van
                            directo al flujo por su vencimiento.

      Tarjetas Socios       se ESTIMA con el promedio de los últimos 3 resúmenes,
                            en pesos y en dólares, con el componente en U$S
                            convertido con dos reglas de dólar.

    UN SOLO PEDIDO PARA LAS TRES, y no tres: los insumos son compartidos y
    PagosTarjetas los lee una vez. Además, con tres pedidos uno puede salir antes y
    otro después de que alguien cargue un resumen, y la pantalla mostraría dos
    momentos distintos a la vez.

    EL EJE ES EL DEL MÓDULO (EjeVista + crearEjeVistas), con las tres vistas
    Días / Meses / Período completo, y las tres sub-pestañas comparten el mismo:
    dibujan las mismas columnas, así que un eje por sub-pestaña serían tres copias
    del mismo dato.

    UN RESUMEN CARGADO PISA LA ESTIMACIÓN del mes, en las tres. No es una fila
    aparte: entre la fecha estimada y la del resumen hay unos cinco días, así que
    van en la misma fila y la celda queda marcada.
-->
<div class="tab-pagos_tarjetas">

    <div id="avisosTarj"></div>

    <!-- El lugar del indicador va EN UNA LINEA, con su id y su texto: es la forma
         que espera tests/test_cargando.php, y el data-cargando es lo que evita que
         el titulo quede en el genérico "Cargando…". -->
    <div class="cargando-slot" id="loadingTarj" data-cargando="Calculando pagos con tarjetas…"></div>

    <div id="wrapperTarj" style="display: none;">

        <!-- LAS TRES VISTAS Y LAS COLUMNAS FIJAS VAN ARRIBA DE LAS SUB-PESTAÑAS,
             y no adentro de cada una: es el mismo eje para las tres, así que un
             juego de botones por sub-pestaña dejaría a las tres pudiendo mostrar
             períodos distintos, y comparar dos de ellas exigiría recordar en qué
             vista quedó cada una. -->
        <div class="card mb-3">
            <div class="card-body py-2 d-flex justify-content-between align-items-center
                        flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <div id="vistasTarj"></div>
                    <small class="text-muted" id="periodoTarj"></small>
                </div>
                <div class="d-flex gap-2">
                    <button id="btnRefreshTarj" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" id="tarjetasTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tabSupBtn" data-bs-toggle="tab"
                        data-bs-target="#paneSup" type="button" role="tab">
                    <i class="fas fa-user-tie me-1"></i> Gastos Supervisoras
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tabCorpBtn" data-bs-toggle="tab"
                        data-bs-target="#paneCorp" type="button" role="tab">
                    <i class="fas fa-building-columns me-1"></i> Tarjetas Pagos Corporativos
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tabSocBtn" data-bs-toggle="tab"
                        data-bs-target="#paneSoc" type="button" role="tab">
                    <i class="fas fa-handshake me-1"></i> Tarjetas Socios
                </button>
            </li>
        </ul>

        <div class="tab-content">

        <!-- ============================================================
             1 — GASTOS SUPERVISORAS
             ============================================================ -->
        <div class="tab-pane fade show active" id="paneSup" role="tabpanel">

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Proyectado en el período</span>
                            <div class="kpi-card-icon blue"><i class="fas fa-sack-dollar"></i></div>
                        </div>
                        <div class="kpi-card-value" id="supTotal">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="supTotalDetalle">efectivo + tarjeta</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Supervisoras</span>
                            <div class="kpi-card-icon green"><i class="fas fa-users"></i></div>
                        </div>
                        <div class="kpi-card-value" id="supCuantas">0</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="supCuantasDetalle">
                                con gastos autorizados en la ventana
                            </span>
                        </div>
                    </div>
                </div>
                <!-- LA TERCERA TARJETA ES LA QUE IMPORTA: cuánta plata NO entra al
                     tablero por falta de una tarjeta cargada. Hoy son $ 60
                     millones, y sin este número la fila del tablero se lee como
                     completa. -->
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Sin tarjeta: no entra</span>
                            <div class="kpi-card-icon orange">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value kpi-card-value-sm" id="supSinTarjeta">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">
                                parte tarjeta sin dónde caer, en todo el horizonte
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">No se proyectan</span>
                            <div class="kpi-card-icon purple"><i class="fas fa-user-slash"></i></div>
                        </div>
                        <div class="kpi-card-value" id="supSinProyectar">0</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">inactivas o fuera del maestro</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EL CARTEL DE LA VENTANA. Lo arma el backend, porque describe una
                 cuenta que hace el backend: qué meses se promediaron, que siempre
                 se divide por 3 y desde qué mes se compone la inflación. -->
            <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
                <i class="fas fa-circle-info mt-1"></i>
                <small id="supCartel"></small>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center
                            flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">Por supervisora</h5>
                        <small class="text-muted">
                            El <strong>efectivo</strong> sale en 2 pagos iguales del cronograma de
                            <em>Parámetros › Generales</em>; la <strong>tarjeta</strong>, el día de
                            vencimiento de su tarjeta. Un resumen cargado pisa la estimación del
                            mes.
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <div id="colFijasSup"></div>
                        <button class="btn btn-sm btn-outline-success" data-exportar="tablaSup"
                                data-exportar-nombre="Gastos_Supervisoras">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-wrapper table-responsive tabla-temporal" id="wrapperSup">
                        <table id="tablaSup" class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2">SUPERVISORA</th>
                                    <th rowspan="2" class="text-end"
                                        title="Promedio mensual de la ventana. Siempre se divide por 3.">
                                        PROMEDIO BASE
                                    </th>
                                    <th rowspan="2" class="text-center"
                                        title="La proporción sale de la ventana entera, no de cada mes">
                                        % EFEC / % TARJ
                                    </th>
                                    <th rowspan="2">TARJETA ASOCIADA</th>
                                    <th id="headerEjeSup" class="text-center"></th>
                                    <!-- EL TOTAL VA AL FINAL, DESPUES DE LAS
                                         COLUMNAS QUE SUMA. Antes estaba antes del
                                         eje y confundía: se leía un total y recién
                                         después los meses de los que sale. Ahora se
                                         lee en el orden en que se arma.

                                         Y dice TOTAL PERÍODO, no TOTAL: suma las
                                         columnas que se están viendo, y la vista
                                         Meses cubre sólo los días de fuera del
                                         tramo diario, así que no es el total del
                                         horizonte. -->
                                    <th rowspan="2" class="text-end"
                                        title="Suma las columnas que se están viendo. La vista Meses cubre sólo los días de fuera del tramo diario, así que su total no es el del horizonte completo.">
                                        TOTAL PERÍODO
                                    </th>
                                </tr>
                                <tr id="headerEjeSup2"></tr>
                            </thead>
                            <tbody id="bodySup"></tbody>
                            <tfoot>
                                <tr id="totalesSup">
                                    <td colspan="4" class="fw-bold text-end">TOTALES</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="card-body py-2 border-top">
                    <small class="text-muted">
                        Cada supervisora se abre en sus dos <strong>subfilas</strong>:
                        <em>Efectivo</em> y <em>Tarjeta</em>. El tooltip de cada celda dice de
                        dónde sale el número: el promedio, el factor de inflación acumulado y la
                        proporción.
                        <br>
                        <strong>El presupuesto se ignora</strong>: dice cuánto se autorizó a
                        gastar, y el cashflow necesita cuánto se va a gastar. La base es sólo el
                        histórico de gastos <strong>autorizados</strong>.
                    </small>
                </div>
            </div>
        </div>

        <!-- ============================================================
             2 — TARJETAS PAGOS CORPORATIVOS
             ============================================================ -->
        <div class="tab-pane fade" id="paneCorp" role="tabpanel">

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Entra al flujo</span>
                            <div class="kpi-card-icon blue"><i class="fas fa-sack-dollar"></i></div>
                        </div>
                        <div class="kpi-card-value" id="corpTotal">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="corpTotalDetalle">
                                facturas + cobertura + resúmenes
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Vencidas sin vincular</span>
                            <div class="kpi-card-icon orange">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value kpi-card-value-sm" id="corpVencidas">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="corpVencidasDetalle">
                                no entran: sin tarjeta no hay fecha de pago
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Cobertura</span>
                            <div class="kpi-card-icon green"><i class="fas fa-shield-halved"></i></div>
                        </div>
                        <div class="kpi-card-value kpi-card-value-sm" id="corpCobertura">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">
                                gastos excepcionales, sobre las vinculadas
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Excluidas</span>
                            <div class="kpi-card-icon purple"><i class="fas fa-ban"></i></div>
                        </div>
                        <div class="kpi-card-value kpi-card-value-sm" id="corpExcluidas">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="corpExcluidasDetalle">
                                por una decisión, fuera del tablero
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
                <i class="fas fa-circle-info mt-1"></i>
                <small>
                    Son las facturas pendientes de Tango de proveedores cuya <strong>forma de pago
                    vigente</strong> es <code>TARJETA CORP</code> —el override por factura de
                    Proveedores Locales, o la del maestro—. <strong>Van directo al flujo</strong>
                    por su fecha de vencimiento de Tango: no hace falta vincularlas para que
                    entren.
                    <br>
                    Vincularlas a una tarjeta hace dos cosas: <strong>generan cobertura</strong>
                    y <strong>un resumen de esa tarjeta las puede reemplazar</strong>. Y una
                    tercera si están vencidas: sin tarjeta no hay fecha de pago, así que una
                    <strong>vencida sin vincular no se proyecta</strong> —una tarjeta se paga una
                    vez por mes, y apilarla en el primer día del eje afirmaría que se paga hoy—.
                </small>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center
                            flex-wrap gap-2">
                    <div class="d-flex gap-3 align-items-center flex-wrap">
                        <div>
                            <h5 class="mb-0">Facturas de tarjeta corporativa</h5>
                            <small class="text-muted" id="corpConteo"></small>
                        </div>

                        <!-- EL BUSCADOR. El universo completo ya está en la grilla
                             —son 135 vencimientos—, así que buscar una factura es
                             filtrar lo que ya está cargado y no ir de nuevo a la
                             base. Mira el código, la razón social y el número de
                             comprobante, que son las tres formas en las que alguien
                             se acuerda de una factura. -->
                        <div class="input-group input-group-sm" style="width: 260px;">
                            <span class="input-group-text">
                                <i class="fas fa-magnifying-glass"></i>
                            </span>
                            <input type="text" id="buscadorCorp" class="form-control"
                                   placeholder="Proveedor o comprobante…">
                        </div>
                    </div>

                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <!-- LAS VENCIDAS SE ESCONDEN POR DEFECTO, como en las dos
                             pestañas de Comex: hoy son 90 de 135 y llenan la grilla
                             de filas que no entran al flujo. Cuántas son y por
                             cuánto se dice siempre, arriba. -->
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="verVencidasCorp">
                            <label class="form-check-label small text-muted" for="verVencidasCorp">
                                Ver vencidas
                            </label>
                        </div>

                        <!-- LAS EXCLUIDAS TAMPOCO. Ya se decidió que no van al
                             cashflow, así que en el trabajo normal son ruido; pero
                             tienen que poder mirarse, porque una exclusión puesta en
                             marzo que nadie recuerda es lo que este interruptor
                             evita. -->
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="verExcluidasCorp">
                            <label class="form-check-label small text-muted"
                                   for="verExcluidasCorp">
                                Ver excluidas
                            </label>
                        </div>

                        <div id="colFijasCorp"></div>
                        <button class="btn btn-sm btn-outline-success" data-exportar="tablaCorp"
                                data-exportar-nombre="Tarjetas_Pagos_Corporativos">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
                    <small class="text-muted" id="escondidasCorp"></small>
                </div>

                <!-- LA BARRA DE SELECCIÓN. Aparece sólo cuando hay algo elegido, y
                     dice CUÁNTAS y CUÁNTO antes de que se apriete nada: vincular
                     cambia dónde cae la plata y excluir la saca del tablero, así que
                     el importe es el dato que hace notar que se seleccionó de más. -->
                <div id="barraSelCorp" class="card-body py-2 border-bottom prov-barra-sel"
                     style="display: none;">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="fw-semibold" id="selResumenCorp"></span>
                        <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                            <select id="selTarjetaCorp" class="form-select form-select-sm"
                                    style="width: 240px;"></select>
                            <button class="btn btn-sm btn-outline-primary" id="btnVincularCorp">
                                <i class="fas fa-link me-1"></i> Vincular a la tarjeta
                            </button>
                            <button class="btn btn-sm btn-outline-secondary"
                                    id="btnDesvincularCorp">
                                <i class="fas fa-link-slash me-1"></i> Desvincular
                            </button>
                            <button class="btn btn-sm btn-outline-danger" id="btnExcluirCorp">
                                <i class="fas fa-ban me-1"></i> Excluir
                            </button>
                            <button class="btn btn-sm btn-outline-success" id="btnIncluirCorp">
                                <i class="fas fa-rotate-left me-1"></i> Volver a incluir
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="btnLimpiarSelCorp">
                                Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-wrapper table-responsive tabla-temporal" id="wrapperCorp">
                        <table id="tablaCorp" class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="text-center" style="width: 46px;">
                                        <input type="checkbox" class="form-check-input"
                                               id="selTodasCorp"
                                               title="Seleccionar todo lo que se está viendo">
                                    </th>
                                    <th rowspan="2">PROVEEDOR</th>
                                    <th rowspan="2" class="col-texto">RAZON SOCIAL</th>
                                    <th rowspan="2">T_COMP</th>
                                    <th rowspan="2">N_COMP</th>
                                    <th rowspan="2">VTO TANGO</th>
                                    <th rowspan="2">TARJETA</th>
                                    <th rowspan="2" class="text-end">PENDIENTE</th>
                                    <th rowspan="2">ESTADO</th>
                                    <th id="headerEjeCorp" class="text-center"></th>
                                    <!-- ESTA GRILLA NO TENÍA COLUMNA DE TOTAL, y el
                                         pie sí dibujaba una: el pie quedaba una
                                         columna más ancho que el encabezado y corría
                                         el último total del eje. Ahora las dos
                                         tienen la misma cantidad.

                                         No es redundante con PENDIENTE: PENDIENTE es
                                         cuánto se debe, y esto es cuánto de eso cae
                                         en el período que se está viendo. Una
                                         factura con pendiente y total en cero es una
                                         que no entra a estas columnas, y eso es
                                         justamente lo que hay que poder ver. -->
                                    <th rowspan="2" class="text-end"
                                        title="Cuánto de esta factura cae en las columnas que se están viendo. En cero significa que no entra a este período, o que no se proyecta.">
                                        TOTAL PERÍODO
                                    </th>
                                </tr>
                                <tr id="headerEjeCorp2"></tr>
                            </thead>
                            <tbody id="bodyCorp"></tbody>
                            <tfoot>
                                <tr id="totalesCorp">
                                    <td colspan="9" class="fw-bold text-end">TOTALES</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="card-body py-2 border-top">
                    <small class="text-muted">
                        <strong>La cobertura y los resúmenes van al pie</strong>, en sus propias
                        filas: la cobertura es un gasto de la tarjeta y no de cada factura, así que
                        se ubica en el día de vencimiento de la tarjeta. El
                        <strong>% no multiplica las facturas</strong> —son deuda real de Tango y
                        alterarlas inventaría deuda— sino que entra como un renglón aparte.
                        <br>
                        <strong>Excluir acá no toca Cuentas a Pagar Locales</strong>: son dos
                        decisiones distintas sobre la misma factura.
                    </small>
                </div>
            </div>

            <!-- La cobertura y los resúmenes, aparte de la grilla de facturas:
                 no son facturas y ponerlos como filas más de la misma tabla haría
                 que el total de la columna PENDIENTE dejara de significar algo. -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Cobertura y resúmenes</h5>
                    <small class="text-muted">
                        Un <strong>resumen cargado reemplaza</strong> a las facturas vinculadas de
                        su mes <strong>y a su cobertura</strong>: ese importe ya las incluye.
                    </small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tablaCorpExtra" class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>CONCEPTO</th>
                                    <th>TARJETA</th>
                                    <th>MES</th>
                                    <th>FECHA</th>
                                    <th class="text-end">BASE</th>
                                    <th class="text-end">IMPORTE</th>
                                    <th>ESTADO</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="bodyCorpExtra"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             3 — TARJETAS SOCIOS
             ============================================================ -->
        <div class="tab-pane fade" id="paneSoc" role="tabpanel">

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Proyectado en pesos</span>
                            <div class="kpi-card-icon blue"><i class="fas fa-sack-dollar"></i></div>
                        </div>
                        <div class="kpi-card-value" id="socTotal">$ 0,00</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">
                                pesos + el componente en U$S ya convertido
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Tarjetas de socios</span>
                            <div class="kpi-card-icon green"><i class="fas fa-credit-card"></i></div>
                        </div>
                        <div class="kpi-card-value" id="socCuantas">0</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted" id="socCuantasDetalle">activas</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <span class="kpi-card-title">Sin base histórica</span>
                            <div class="kpi-card-icon orange">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                        </div>
                        <div class="kpi-card-value" id="socSinBase">0</div>
                        <div class="kpi-card-footer">
                            <span class="text-muted">no estiman: cargá sus últimos 3 resúmenes</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EL CARTEL DEL DÓLAR. Lo arma el backend y trae los valores reales:
                 la cotización del BCRA con su fecha y su punta, hasta dónde llega la
                 curva de futuros, y por qué la inflación no toca el componente en
                 dólares. -->
            <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
                <i class="fas fa-circle-info mt-1"></i>
                <small id="socCartel"></small>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center
                            flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">Por tarjeta</h5>
                        <small class="text-muted">
                            Cada tarjeta se abre en cuatro filas: <strong>U$S</strong>
                            (informativa, no suma en pesos), <strong>equivalente en $</strong>,
                            <strong>$</strong> y el <strong>total en pesos</strong>.
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <div id="colFijasSoc"></div>
                        <button class="btn btn-sm btn-outline-success" data-exportar="tablaSoc"
                                data-exportar-nombre="Tarjetas_Socios">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-wrapper table-responsive tabla-temporal" id="wrapperSoc">
                        <table id="tablaSoc" class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2">TARJETA</th>
                                    <th rowspan="2" class="text-end"
                                        title="Promedio de los últimos 3 resúmenes cargados">
                                        BASE
                                    </th>
                                    <th rowspan="2">CONCEPTO</th>
                                    <th id="headerEjeSoc" class="text-center"></th>
                                    <!-- Al final, como en las otras dos. -->
                                    <th rowspan="2" class="text-end"
                                        title="Suma las columnas que se están viendo. La fila en U$S está en dólares; las otras tres, en pesos.">
                                        TOTAL PERÍODO
                                    </th>
                                </tr>
                                <tr id="headerEjeSoc2"></tr>
                            </thead>
                            <tbody id="bodySoc"></tbody>
                            <tfoot>
                                <tr id="totalesSoc">
                                    <td colspan="3" class="fw-bold text-end">TOTALES EN PESOS</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="card-body py-2 border-top">
                    <small class="text-muted">
                        <strong>La base son los últimos 3 resúmenes</strong> de la tarjeta, de
                        cualquier origen, con período anterior al mes en curso. Al principio se
                        cargan de una con <em>Cargar base histórica</em>; después, los que se van
                        cargando mes a mes pasan a formar parte del histórico y la base se corre
                        sola.
                        <br>
                        Con menos de 3 se promedia lo que hay y se avisa: <strong>se divide por los
                        que hay y no por 3</strong>, porque un resumen que falta no es un mes sin
                        consumos.
                    </small>
                </div>
            </div>
        </div>

        </div><!-- /tab-content -->

        <!-- La grilla de resúmenes, compartida por las tres sub-pestañas: se abre
             desde la fila de una tarjeta. Es la misma tabla y el mismo circuito en
             los tres casos, así que una copia por sub-pestaña se desincronizaría en
             la primera corrección. -->
        <div class="card mb-4" id="cardResumenes" style="display: none;">
            <div class="card-header d-flex justify-content-between align-items-center
                        flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Resúmenes de <span id="resumenTarjeta"></span></h5>
                    <small class="text-muted">
                        Un resumen <strong>pisa la estimación</strong> de su mes, con su importe y
                        su fecha. Marcarlo como <strong>pagado lo saca del horizonte</strong>:
                        esa plata ya está reflejada en el saldo bancario.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button id="btnNuevoResumen" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Cargar resumen
                    </button>
                    <button id="btnBaseHistorica" class="btn btn-sm btn-outline-secondary"
                            title="Cargar los últimos 3 resúmenes de una, como base de la estimación">
                        <i class="fas fa-clock-rotate-left me-1"></i> Cargar base histórica
                    </button>
                    <button id="btnCerrarResumenes" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaResumenes" class="table table-sm table-hover mb-0"
                           data-orden="no">
                        <thead>
                            <tr>
                                <th style="width: 110px;">PERÍODO</th>
                                <th class="text-end">IMPORTE $</th>
                                <th class="text-end">IMPORTE U$S</th>
                                <th style="width: 140px;">VENCIMIENTO</th>
                                <th style="width: 130px;">ORIGEN</th>
                                <th class="text-center" style="width: 90px;">PAGADO</th>
                                <th>OBSERVACIÓN</th>
                                <th>AUDITORÍA</th>
                                <th style="width: 60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="bodyResumenes"></tbody>
                    </table>
                </div>
            </div>

            <div class="card-body py-2 border-top">
                <small class="text-muted">
                    <strong>Dar de baja un resumen no lo borra</strong>: queda en el historial, y
                    ese mes vuelve a proyectarse con la estimación. Al menos uno de los dos
                    importes es obligatorio, y pueden venir los dos: la misma tarjeta tiene
                    consumos en pesos y en dólares.
                </small>
            </div>
        </div>
    </div>
</div>

<script src="Js/Financiero-Pagos_tarjetas.js?v=<?php echo time(); ?>"></script>
