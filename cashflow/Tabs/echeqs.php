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
             con el período real, que depende del horizonte configurado. -->
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
                </div>
                <div class="d-flex gap-2">
                    <!-- No hay Resumen / Deep Dive: el grano natural de esta
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

            <div class="card-body p-0">
                <div class="loading-spinner" id="loadingEch">
                    <div class="spinner"></div>
                    <p>Cargando cheques en cartera...</p>
                </div>

                <!-- .tabla-temporal: header de dos filas fijo arriba y
                     columnas descriptivas fijas a la izquierda. Ver
                     Css/main.css. -->
                <div class="table-wrapper table-responsive tabla-temporal" id="wrapperEch" style="display: none;">
                    <table id="tablaEcheqs" class="table table-hover mb-0">
                        <thead>
                            <!--
                                Cinco columnas fijas y después una por cada
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
                        <small class="text-muted">
                            Entran <strong>tildados</strong>: estar en el maestro es haber optado
                            por la modalidad. Lo que se hace acá es <em>destildar</em> las
                            excepciones.
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
                </div>
            </div>

            <!-- Qué período se está midiendo. La vista Meses no cubre el
                 horizonte completo, y sin esto su total se lee como el de todo. -->
            <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
                <small class="text-muted" id="periodoPre"></small>
            </div>

            <!-- Lo que no entra en el eje se avisa, no se esconde: con días de
                 pre-chequeado altos la fecha estimada de venta puede caer antes
                 del inicio del eje. Es el mismo criterio de
                 Ventas::repartirNeteo(). -->
            <div id="avisosEjePre"></div>

            <div class="card-body p-0">
                <div class="loading-spinner" id="loadingPre">
                    <div class="spinner"></div>
                    <p>Cargando cheques pre-chequeados...</p>
                </div>

                <div class="table-wrapper table-responsive tabla-temporal" id="wrapperPre"
                     style="display: none;">
                    <table class="table table-hover mb-0" id="tablaPrechequeado">
                        <thead>
                            <!--
                                Nueve columnas descriptivas y después una por
                                cada columna del eje, que dibuja el JS.

                                FECHA VENTA ESTIMADA = fecha del cheque − días
                                del cliente, y es DONDE SE UBICA el importe en
                                la grilla: es la fecha en la que ese cheque
                                netea la cobranza proyectada de Ventas.

                                La fecha del cheque se conserva como referencia
                                —es el dato duro de Tango— y los días efectivos
                                van al lado para que se vea de dónde sale la
                                estimación. Un cliente en 0 días muestra las dos
                                fechas iguales, y eso es información: quiere
                                decir que no está configurado.
                            -->
                            <tr>
                                <th rowspan="2" class="text-center" style="width: 60px;">
                                    <!-- Marca o desmarca TODO LO VISIBLE según el
                                         filtro actual, y dice cuántas filas va a
                                         afectar antes de hacerlo. -->
                                    <input type="checkbox" class="form-check-input" id="marcarTodosPre"
                                           title="Marca o desmarca todo lo que se está viendo">
                                </th>
                                <th rowspan="2">Fecha venta estimada</th>
                                <th rowspan="2" class="text-center" style="width: 80px;"
                                    title="Días de pre-chequeado del cliente. En 0 el cheque no se desplaza.">Días</th>
                                <th rowspan="2">Fecha de pago</th>
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
