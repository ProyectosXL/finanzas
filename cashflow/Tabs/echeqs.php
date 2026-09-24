<?php $tabName = 'Echeqs'; ?>
<link rel="stylesheet" href="Css/Ingresos-Echeqs.css?v=<?php echo time(); ?>">

<!--
    Pestaña Echeqs. Dos sub-pestañas que son dos cosas distintas:

      Cheques en Cartera       -> cheques de terceros con ESTADO = 'C'. Alimentan
                                  la fila "Echeqs en cartera" de DISPONIBILIDADES.
      Venta Cobrada Anticipada -> cheques de clientes que pre-chequean. NO
                                  alimentan ninguna fila del tablero: su efecto
                                  es restar de la cobranza proyectada de Ventas.

    UN MISMO CHEQUE PUEDE ESTAR EN LAS DOS. Es correcto y da el número justo, y
    está dicho también en la leyenda de la segunda sub-pestaña porque es lo
    primero que alguien va a querer "arreglar".

    CADA UNA TIENE SU TILDE, Y NO SON EL MISMO:

      Cartera       EXCLUIR  "esta plata, ¿va a entrar?"  -> saca el importe de
                             la fila del tablero y lo manda a su propia serie
      Prechequeado  MARCAR   "esta venta, ¿ya se cobró?"  -> decide qué se resta
                             de la cobranza proyectada de Ventas

    Son dos decisiones independientes sobre el mismo cheque y ninguna implica la
    otra. Ver el encabezado de Class/Echeqs.php.

    La segunda se pide recién cuando se abre: depende de un maestro que puede no
    estar cargado, y no tiene por qué demorar la que se abre primero.
-->
<div class="tab-echeqs">

    <div id="avisosEcheqs"></div>

    <ul class="nav nav-tabs mb-3" id="echeqsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabCarteraBtn" data-bs-toggle="tab"
                    data-bs-target="#paneCartera" type="button" role="tab">
                <i class="fas fa-money-check-dollar me-1"></i> Cheques en Cartera
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabPrechequeadoBtn" data-bs-toggle="tab"
                    data-bs-target="#panePrechequeado" type="button" role="tab">
                <i class="fas fa-clock-rotate-left me-1"></i> Venta Cobrada Anticipada
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ============================================================
         SUB-PESTAÑA 1 — CHEQUES EN CARTERA
         ============================================================ -->
    <div class="tab-pane fade show active" id="paneCartera" role="tabpanel">

        <!-- Las tres tarjetas miden los tres períodos de las tres vistas, así
             que cada una se corresponde con un botón. El rótulo lo escribe el JS
             con el período real, que depende del horizonte configurado.

             MIDEN LO QUE ENTRA AL CASHFLOW, o sea SIN los cheques excluidos:
             son los mismos números que la fila del tablero. El total con los
             excluidos adentro diría que esa plata entra, que es justamente lo
             que el tilde niega. Cuánto se excluyó se dice abajo de cada
             tarjeta, y lo resta PHP: acá no se calcula nada. -->
        <div class="row g-3 mb-4" id="summarySectionEch" style="display: none;">
            <div class="col-md-6 col-lg-4">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Vista Días</span>
                        <div class="kpi-card-icon blue">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                    <div class="kpi-card-value" id="totalDiasEch">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="rotuloDiasEch">Tramo diario</span>
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
                    <div class="kpi-card-value" id="totalMesesEch">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="rotuloMesesEch">Después del tramo diario</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Período Completo</span>
                        <div class="kpi-card-icon green">
                            <i class="fas fa-money-check-dollar"></i>
                        </div>
                    </div>
                    <div class="kpi-card-value" id="totalGeneralEch">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="rotuloGeneralEch">Todo el horizonte</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- LO EXCLUIDO SE DICE AUNQUE NO SE VEA, y sobre todo por eso: los
             excluidos están escondidos por defecto, así que sin este cartel la
             única forma de notar que hay plata afuera sería acordarse de
             prender el interruptor. Mismo criterio que el aviso que deja
             EcheqsProvider en el tablero. -->
        <div id="excluidosEch" class="alert alert-secondary py-2 px-3 mb-4"
             style="display: none;"></div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <h5 class="mb-0">Cheques en cartera</h5>
                        <small class="text-muted" id="detalleCarteraEch">
                            De terceros, por fecha de pago
                        </small>
                    </div>
                    <div class="search-box-container ms-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="busquedaEch"
                                   class="form-control border-start-0 ps-0"
                                   placeholder="Buscar cliente, banco o N° de cheque…"
                                   style="min-width: 260px;">
                        </div>
                    </div>

                    <!-- LOS EXCLUIDOS NO SE VEN POR DEFECTO. Ya se decidió que
                         esa plata no va a entrar, así que en el trabajo normal
                         —mirar qué se va a cobrar— son ruido.

                         Pero tienen que poder mirarse: una exclusión puesta en
                         marzo que nadie recuerda es justamente lo que este
                         interruptor evita. Cuánto esconde se dice arriba,
                         siempre. Mismo criterio que "Ver excluidas" de
                         Proveedores Locales. -->
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="verExcluidosEch">
                        <label class="form-check-label small text-muted" for="verExcluidosEch">
                            Ver excluidos
                        </label>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <!-- No hay Resumen / Detalle Facturas: el grano natural de esta
                         pantalla es el cheque, y no hay nada que aperturar. -->
                    <div id="vistasEcheqs"></div>
                    <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                    <div id="colFijasEcheqs"></div>
                    <button id="btnRefreshEch" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnExportEch" class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>

            <!-- Qué período se está midiendo. La vista Meses no cubre el
                 horizonte completo, y sin esto su total se lee como el de todo. -->
            <div class="card-body py-2 border-bottom">
                <small class="text-muted" id="periodoEcheqs"></small>
            </div>

            <!-- LA BARRA DE SELECCIÓN. Aparece sólo cuando hay algo elegido:
                 una barra siempre visible con los botones apagados ocupa lugar
                 para decir que no se puede hacer nada.

                 Dice CUÁNTOS y CUÁNTO antes de que se apriete nada: excluir es
                 sacar plata del disponible, y el importe es el dato que hace
                 que alguien note que seleccionó de más. Es la misma barra de
                 Proveedores Locales y por el mismo motivo. -->
            <div id="barraSelEch" class="card-body py-2 border-bottom ech-barra-sel"
                 style="display: none;">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="fw-semibold" id="selResumenEch"></span>
                    <div class="d-flex gap-2 ms-auto">
                        <button class="btn btn-sm btn-outline-danger" id="btnExcluirSelEch">
                            <i class="fas fa-ban me-1"></i> Excluir del cashflow
                        </button>
                        <button class="btn btn-sm btn-outline-success" id="btnIncluirSelEch">
                            <i class="fas fa-rotate-left me-1"></i> Volver a incluir
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="btnLimpiarSelEch">
                            Limpiar selección
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="cargando-slot" id="loadingEch" data-cargando="Cargando cheques en cartera…"></div>

                <!-- .tabla-temporal: header de dos filas fijo arriba y
                     columnas descriptivas fijas a la izquierda. Ver
                     Css/main.css. -->
                <div class="table-wrapper table-responsive tabla-temporal" id="wrapperEch" style="display: none;">
                    <table id="tablaEcheqs" class="table table-hover mb-0">
                        <thead>
                            <!--
                                Seis columnas fijas y después una por cada
                                columna del eje, que dibuja el JS.

                                FECHA DE PAGO = FECHA DEL CHEQUE mientras no
                                exista una regla de acreditación. Por eso hay una
                                sola columna de fecha y una sola de importe, y no
                                las dos de cada una que tiene la planilla de
                                referencia: eran el mismo dato repetido.
                            -->
                            <tr>
                                <th rowspan="2">Fecha de pago</th>
                                <th rowspan="2">N° Cheque</th>
                                <th rowspan="2">Banco</th>
                                <th rowspan="2" class="col-texto">Cliente</th>
                                <th rowspan="2" class="text-end">Importe</th>
                                <!-- LA COLUMNA ES DE SELECCIÓN, no de estado, y
                                     alimenta las dos acciones de la barra.
                                     Ninguna puede dispararse con un clic suelto
                                     —sacan plata del disponible—: se eligen los
                                     cheques y se confirman juntos, viendo
                                     cuántos son y por cuánto.

                                     Es el gesto del tildado masivo de la otra
                                     sub-pestaña: el check del encabezado toma
                                     todo lo visible según el buscador.

                                     Que un cheque ESTÉ excluido se ve en la
                                     fila —atenuada y con el importe tachado— y
                                     en la marca de esta misma celda. -->
                                <th rowspan="2" class="text-center" style="width: 46px;">
                                    <input type="checkbox" class="form-check-input"
                                           id="selTodosEch"
                                           title="Seleccionar todos los cheques que se están viendo. Con el buscador puesto, son los de ese cliente.">
                                </th>
                                <th colspan="1" class="table-group-divider" id="ejeHeaderEch">Días</th>
                            </tr>
                            <tr id="ejeSubHeaderEch"></tr>
                        </thead>
                        <tbody id="bodyEch"></tbody>
                        <tfoot class="table-light">
                            <tr id="totalesEch"></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SUB-PESTAÑA 2 — VENTA COBRADA ANTICIPADA
         ============================================================ -->
    <div class="tab-pane fade" id="panePrechequeado" role="tabpanel">

        <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
            <i class="fas fa-circle-info mt-1"></i>
            <div>
                <small>
                    Estos clientes entregan los cheques <strong>antes</strong> de que se les
                    facture. Lo que está tildado se <strong>resta</strong> de la cobranza
                    proyectada de Ventas, para no cobrar dos veces la misma venta.
                    <br>
                    Un cheque en cartera aparece <strong>acá y en la otra sub-pestaña</strong>,
                    y así es como corresponde: suma una vez como disponibilidad
                    <em>(+ importe)</em> y resta una vez de la cobranza estimada
                    <em>(− importe)</em>, o sea que queda contado una sola vez.
                </small>
            </div>
        </div>

        <div class="row g-3 mb-4" id="summaryPreEch" style="display: none;">
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card kpi-card-destacada">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">A netear de Ventas</span>
                        <div class="kpi-card-icon purple"><i class="fas fa-arrow-down-short-wide"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalMarcadoPre">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="detalleMarcadoPre">0 cheques marcados</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">En el listado</span>
                        <div class="kpi-card-icon blue"><i class="fas fa-list-check"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalListadoPre">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="detalleListadoPre">0 cheques</span>
                    </div>
                </div>
            </div>

            <!-- El neteo va por tilde y no por estado: lo decide quien tilda.
                 Pero los dos casos no se comportan igual en el tablero, y estas
                 dos tarjetas son las que dejan ver de cuánto se está hablando:
                 un cheque en cartera cierra solo, uno ya aplicado resta sin que
                 ninguna fila lo sume. -->
            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Marcados en cartera</span>
                        <div class="kpi-card-icon green"><i class="fas fa-wallet"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalEnCarteraPre">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted">Estado C · cierran solos</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-card-title">Marcados fuera de cartera</span>
                        <div class="kpi-card-icon orange"><i class="fas fa-circle-exclamation"></i></div>
                    </div>
                    <div class="kpi-card-value" id="totalFueraCarteraPre">$ 0,00</div>
                    <div class="kpi-card-footer">
                        <span class="text-muted" id="detalleFueraCarteraPre">Ya no están en cartera</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-0">Cheques de clientes pre-chequeados</h5>
                        <!-- LA SEGUNDA LÍNEA NO ES OPCIONAL. La tabla esconde
                             los cheques cuya venta teórica ya pasó, y una tabla
                             que esconde filas sin decirlo se lee como que esos
                             cheques no existen. Quien busque uno que ayer estaba
                             tiene que poder entender por qué no está. -->
                        <small class="text-muted d-block">
                            Entran <strong>tildados</strong>: estar en el maestro es haber optado
                            por la modalidad. Lo que se hace acá es <em>destildar</em> las
                            excepciones.
                        </small>
                        <!-- Las dos fechas hacen dos cosas distintas y la
                             leyenda lo dice: la tabla tiene las dos columnas, y
                             sin esto el lector supone que la que ubica es la
                             misma que la que filtra. -->
                        <small class="text-muted d-block">
                            Se ven sólo los de <strong>venta teórica desde hoy</strong>. Los
                            anteriores corresponden a ventas ya facturadas y cobradas: están
                            fuera del cashflow y no hay nada que netear.
                        </small>
                        <small class="text-muted d-block">
                            Cada importe se ubica en la grilla por la <strong>fecha del
                            cheque</strong> —es cuando entra la plata—, no por la fecha
                            estimada de venta, que es la que decide si el cheque aparece acá.
                        </small>
                    </div>
                    <select id="filtroClientePre" class="form-select form-select-sm"
                            style="max-width: 260px;"
                            title="El flujo normal es filtrar un cliente y tildar o destildar todo lo visible">
                        <option value="">Todos los clientes</option>
                    </select>
                    <div class="search-box-container">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="busquedaPre"
                                   class="form-control border-start-0 ps-0"
                                   placeholder="Buscar cliente, banco o N° de cheque…"
                                   style="min-width: 240px;">
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span id="avisoGuardadoPre" class="ech-aviso-guardado" style="display: none;"></span>
                    <!-- Los tres botones los dibuja Js/eje-vistas.js -->
                    <div id="vistasPre"></div>
                    <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                    <div id="colFijasPre"></div>
                    <button id="btnRefreshPre" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar: no
                         hace falta una línea de JS en la pestaña. Exporta lo que
                         se ve —el buscador, el filtro de cliente y la vista del
                         eje ya vienen aplicados porque sale del DOM vivo— y los
                         tildes bajan como Sí/No, no como checkbox. -->
                    <button class="btn btn-sm btn-success" data-exportar="tablaPrechequeado"
                            data-exportar-nombre="Venta_Cobrada_Anticipada">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>

            <!-- Qué período se está midiendo. La vista Meses no cubre el
                 horizonte completo, y sin esto su total se lee como el de todo. -->
            <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
                <small class="text-muted" id="periodoPre"></small>
            </div>

            <!-- Lo que queda MÁS ALLÁ del horizonte se avisa: son cheques que
                 están en la tabla pero cuya fecha no tiene columna donde
                 ubicarse. Desde que el importe se ubica por la fecha del
                 cheque, ese caso es plata que el tablero DEBERÍA restar y no
                 resta: la venta que prepagaron sí puede estar proyectada
                 adentro del cuadro. Ventas::repartirNeteo() informa el mismo
                 importe en 'fuera_horizonte', así que las dos pantallas dicen
                 lo mismo.

                 Lo anterior a hoy ya no llega hasta acá —no se muestra, y lo
                 dice la leyenda de arriba—: eso sí es venta ya cobrada, no
                 plata que falte mostrar, y no avisa nada. Es el mismo criterio
                 de Ventas::repartirNeteo(). -->
            <div id="avisosEjePre"></div>

            <div class="card-body p-0">
                <div class="cargando-slot" id="loadingPre" data-cargando="Cargando cheques pre-chequeados…"></div>

                <div class="table-wrapper table-responsive tabla-temporal" id="wrapperPre"
                     style="display: none;">
                    <table class="table table-hover mb-0" id="tablaPrechequeado">
                        <thead>
                            <!--
                                Diez columnas descriptivas y después una por
                                cada columna del eje, que dibuja el JS.

                                DOS FECHAS, DOS FUNCIONES DISTINTAS, y por eso
                                el orden y el peso visual son los que son:

                                  FECHA DEL CHEQUE     decide DÓNDE cae el
                                                       importe en la grilla. Es
                                                       cuando entra la plata.
                                                       Va primera y destacada.

                                  FECHA VENTA ESTIMADA decide QUÉ cheques se
                                  (cheque − días)      muestran: si ya pasó, esa
                                                       venta se facturó y se
                                                       cobró, y el cheque no
                                                       está en esta lista. Va
                                                       en gris: explica por qué
                                                       la fila está acá, no
                                                       dónde cae.

                                Antes el importe se ubicaba por la estimada y
                                por eso iba primera. Los días efectivos van al
                                lado de la estimada, que es de donde sale: un
                                cliente en 0 días muestra las dos fechas
                                iguales, y eso es información —quiere decir que
                                no está configurado—.
                            -->
                            <tr>
                                <th rowspan="2" class="text-center" style="width: 60px;">
                                    <!-- Marca o desmarca TODO LO VISIBLE según el
                                         filtro actual, y dice cuántas filas va a
                                         afectar antes de hacerlo. -->
                                    <input type="checkbox" class="form-check-input" id="marcarTodosPre"
                                           title="Marca o desmarca todo lo que se está viendo">
                                </th>
                                <!-- Se llamaba "Fecha de pago". Con dos columnas
                                     de fecha al lado, el nombre del dato es lo
                                     único que las distingue de un vistazo. -->
                                <th rowspan="2"
                                    title="Fecha del cheque. Es la que ubica el importe en la grilla: es cuando entra la plata.">
                                    Fecha del cheque
                                </th>
                                <th rowspan="2" class="text-muted"
                                    title="Fecha del cheque menos los días de pre-chequeado del cliente. Informativa: es lo que explica por qué este cheque está en la lista —su venta todavía no ocurrió—, no dónde cae el importe.">
                                    Fecha venta estimada
                                </th>
                                <th rowspan="2" class="text-center text-muted" style="width: 80px;"
                                    title="Días de pre-chequeado del cliente. En 0 el cheque no se desplaza y las dos fechas coinciden.">Días</th>
                                <th rowspan="2">N° Cheque</th>
                                <th rowspan="2">Banco</th>
                                <th rowspan="2" class="col-texto">Cliente</th>
                                <th rowspan="2" class="text-center" style="width: 90px;">Estado</th>
                                <th rowspan="2" class="text-end">Importe</th>
                                <th rowspan="2" class="text-center" style="width: 160px;">Marca</th>
                                <!-- El rótulo y el colspan los pone el JS según la vista activa -->
                                <th colspan="1" class="table-group-divider" id="grupoEjePre">Días</th>
                            </tr>
                            <tr id="headerEjePre"></tr>
                        </thead>
                        <tbody id="bodyPre"></tbody>
                        <tfoot class="table-light" id="footPre"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    </div><!-- /tab-content -->
</div><!-- /tab-echeqs -->

<script src="Js/Ingresos-Echeqs.js?v=<?php echo time(); ?>"></script>
