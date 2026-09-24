<?php $tabName = 'Proveedores Exterior'; ?>
<link rel="stylesheet" href="Css/Comex-Proveedores_exterior.css?v=<?php echo time(); ?>">

<div class="tab-proveedores_exterior">

    <!-- Lo que quedó fuera del horizonte o sin fecha, y lo que no se pudo
         valuar. Antes se descartaba en silencio, así que la tabla podía
         informar de menos sin decirlo. -->
    <div id="avisosProvExt"></div>

    <!-- KPI Cards Row.
         Las tres tarjetas miden los tres períodos de las tres vistas, así que
         cada una se corresponde con un botón. Su rótulo lo escribe el JS con el
         período real, que depende del horizonte configurado. -->
    <div class="row g-3 mb-4" id="summarySection" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanas">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo4semanas">Tramo diario</span>
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
                <div class="kpi-card-value" id="total11meses">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo11meses">Después del tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Período Completo</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-ship"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneral">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloGeneral">Todo el horizonte</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <h5 class="mb-0">Proveedores Exterior</h5>
                    <small class="text-muted">
                        Importaciones pendientes ordenadas por fecha de pago
                        <i class="fas fa-info-circle ms-1"
                           title="Click en la fecha estimada de pago para editarla. Se guarda en el maestro de Comercio Exterior, así que la ve también esa aplicación. Las vencidas están marcadas: su importe no entra en ninguna columna hasta que se les cargue una fecha nueva."></i>
                    </small>
                </div>
                <!-- Mismo marcado que el buscador de Crono Nacionalización, y
                     el mismo comportamiento: las dos pestañas son la misma fila
                     mirada desde los dos lados del circuito, y un control que se
                     ve distinto en cada pantalla se lee como otro control. Busca
                     SÓLO Proveedor, Contenedor y Orden de Compra: son los tres
                     campos por los que se busca un contenedor, y mirar toda la
                     fila haría que un importe o una fecha den falsos positivos.
                     Ver Js/Comex-fechas.js. -->
                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaProvExt"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar proveedor, contenedor u orden de compra…"
                               style="min-width: 260px;">
                    </div>
                </div>

                <!-- LAS VENCIDAS NO SE VEN POR DEFECTO. Una fecha de pago
                     vencida es un dato a corregir, y hasta que alguien la
                     corrija ese contenedor no participa del período que esta
                     pantalla proyecta: sus celdas del eje están vacías. En el
                     trabajo normal —mirar qué se paga de acá en adelante— son
                     ruido, y acá son muchas: al 19/09/2026, 27 de 76 filas.

                     Pero tienen que poder mirarse, porque son justamente las
                     que hay que arreglar. Por eso es un interruptor y no un
                     filtro fijo, y por eso CUÁNTO ESCONDE SE DICE AL LADO,
                     siempre: una tabla que esconde filas sin decirlo se lee
                     como que esos contenedores no existen. Mismo criterio que
                     "Ver excluidos" de Echeqs.

                     Sin `checked`: apagado es el estado por defecto, y que el
                     HTML lo diga por omisión evita que alguien lo cambie sin
                     querer moviendo el atributo. -->
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="verVencidasProvExt">
                    <label class="form-check-label small text-muted" for="verVencidasProvExt">
                        Ver vencidas
                        <span class="text-muted fst-italic ms-1"
                              id="estadoVencidasProvExt"></span>
                    </label>
                </div>

                <!-- LOS PAGADOS TAMPOCO SE VEN POR DEFECTO. Ya se decidió que
                     ese egreso no se espera más, así que en el trabajo normal
                     —mirar qué falta pagar— son ruido, igual que los excluidos
                     de Echeqs.

                     Pero tienen que poder mirarse: una marca puesta en marzo
                     que nadie recuerda es justamente lo que este interruptor
                     evita, y es desde donde se destilda lo que se marcó por
                     error. CUÁNTO ESCONDE SE DICE AL LADO, siempre. -->
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="verPagadosProvExt">
                    <label class="form-check-label small text-muted" for="verPagadosProvExt">
                        Ver pagados
                        <span class="text-muted fst-italic ms-1"
                              id="estadoPagadosProvExt"></span>
                    </label>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- Los tres botones los dibuja Js/eje-vistas.js a partir del
                     eje que resolvió el backend: la pestaña no decide ni cuáles
                     son ni qué columnas tiene cada uno. -->
                <div id="vistasProvExt"></div>
                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasProvExt"></div>
                <button id="btnRefresh" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <!-- Lo engancha Js/tabla-export.js por el data-exportar, igual
                     que el resto del módulo: antes era un #btnExport con su
                     listener y una función envoltorio en el JS de la pestaña,
                     que no hacían nada que el atributo no haga. Y saca del clon
                     las filas que el buscador escondió, así que Exportar baja
                     exactamente lo que se está viendo. -->
                <button class="btn btn-sm btn-success" data-exportar="tablaProveedoresExterior"
                        data-exportar-nombre="Proveedores_Exterior">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Qué período se está midiendo. No es decoración: la vista Meses no
             cubre el horizonte completo, y sin esto su total se lee como el
             total de todo. -->
        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="periodoProvExt"></small>
            <!-- De dónde sale el dólar con el que se valúa la tabla y hasta
                 qué mes llega la curva. No es decoración: es lo que permite
                 auditar los importes en pesos contra el mercado. -->
            <small class="text-muted ms-2" id="cotizProvExt"></small>
        </div>

        <div class="card-body p-0">
            <div class="cargando-slot" id="loadingSpinner" data-cargando="Cargando datos…"></div>
            
            <div class="table-wrapper" id="tableWrapper" style="display: none;">
                <div class="table-responsive tabla-temporal">
                    <table id="tablaProveedoresExterior" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2" class="col-texto">Proveedor</th>
                                <th rowspan="2">Contenedor</th>
                                <th rowspan="2">Orden Compra</th>
                                <th rowspan="2">Despachante</th>
                                <!-- LAS TRES COLUMNAS DEL SALDO, EN ESE ORDEN:
                                     cuánto vale el contenedor, cuánto se pagó
                                     ya y cuánto falta. Es la resta escrita de
                                     izquierda a derecha, que es lo que hace que
                                     el número de la punta no haya que creerlo:
                                     se ve de dónde sale.

                                     SON LOS MISMOS NÚMEROS QUE COMERCIO
                                     EXTERIOR. La cuenta está replicada acá
                                     —dos apps, dos despliegues— pero tiene que
                                     dar exactamente lo que muestra la pantalla
                                     de pagos de allá, hasta el centavo de
                                     tolerancia. Ver Class/Comex.php.

                                     LO QUE ENTRA AL CASHFLOW ES EL PENDIENTE,
                                     valuado en la columna en pesos. El FOB
                                     queda como referencia: es con lo que se
                                     chequea contra la factura del proveedor. -->
                                <th rowspan="2">FOB total (USD)</th>
                                <th rowspan="2">
                                    Pagado (USD)
                                    <i class="fas fa-list ms-1" style="font-size: 10px;"
                                       title="Click para ver los pagos cargados en Comercio Exterior"></i>
                                </th>
                                <th rowspan="2">Pendiente (USD)</th>
                                <th rowspan="2">ETD</th>
                                <th rowspan="2">ETA</th>
                                <th rowspan="2">
                                    Fecha Est. Pago
                                    <i class="fas fa-pen-to-square ms-1" style="font-size: 10px;"
                                       title="Click para editar"></i>
                                </th>
                                <!-- CON QUÉ DÓLAR SE VALUÓ ESTA FILA. Sale de
                                     la curva de dólar futuro ROFEX según el mes
                                     de la fecha de pago, y se puede corregir a
                                     mano para un contenedor puntual. Un importe
                                     en pesos que no diga con qué cotización
                                     salió no se puede auditar contra nada. -->
                                <th rowspan="2">
                                    Dólar aplicado
                                    <i class="fas fa-pen-to-square ms-1" style="font-size: 10px;"
                                       title="Click para corregir la cotización de este contenedor"></i>
                                </th>
                                <th rowspan="2">Importe ($)</th>
                                <!-- EL TILDE DE "YA SE PAGÓ". Saca la fila de
                                     la proyección: el tablero deja de contar
                                     ese importe, que sale por su propia serie.
                                     Es un dato del cashflow y NO se escribe en
                                     el maestro de Comercio Exterior. -->
                                <th rowspan="2">
                                    Pagado
                                    <i class="fas fa-check-square ms-1" style="font-size: 10px;"
                                       title="Tildá si el pago ya se hizo: sale de la proyección"></i>
                                </th>
                                <!-- El rótulo y el colspan los pone el JS según
                                     la vista activa, y las columnas salen del
                                     eje del backend. -->
                                <th colspan="1" class="table-group-divider" id="mesActualHeader">Días</th>
                            </tr>
                            <tr id="headerRowSub">
                                <!-- Las columnas se generan dinámicamente -->
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Las filas se generan dinámicamente -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr id="totalsRow">
                                <td colspan="12" class="fw-bold text-end">TOTALES</td>
                                <!-- Los totales se generan dinámicamente -->
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         LOS PAGOS DE UN CONTENEDOR, SOLO LECTURA

         El detalle detrás de la columna "Pagado (USD)": de qué pagos sale ese
         número. Existe porque desde feature/comex-saldo-pendiente el importe
         que proyecta una fila DEPENDE de lo que se haya cargado en la otra
         aplicación, y un número que cambió por algo que pasó en otra pantalla,
         sin forma de ver qué fue, es indistinguible de un error de ésta.

         NO SE CARGA NI SE EDITA NADA ACÁ, y no es una etapa pendiente: los
         pagos se registran en Comercio Exterior, que es el dueño del circuito.
         El pie del modal lo dice, para que nadie busque el botón que no está.
         ================================================================ -->
    <div class="modal fade" id="modalPagosComex" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        <i class="fas fa-receipt me-1"></i>
                        Pagos de <span id="pagosComexContenedor"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                </div>

                <!-- La resta, arriba y completa. El modal contesta "¿de dónde
                     sale el pendiente?", así que el pendiente tiene que estar
                     acá y no sólo en la fila de atrás. -->
                <div class="card-body py-2 border-bottom">
                    <small class="text-muted" id="pagosComexResumen"></small>
                </div>

                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" id="tablaPagosComex">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 110px;">Fecha</th>
                                    <th>Forma</th>
                                    <th>Medio</th>
                                    <th class="text-end" style="width: 150px;">Monto (USD)</th>
                                    <th class="text-center" style="width: 160px;">Cargado el</th>
                                </tr>
                            </thead>
                            <tbody id="bodyPagosComex"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <small class="text-muted me-auto">
                        <i class="fas fa-lock me-1"></i>
                        Sólo lectura: los pagos se cargan en Comercio Exterior.
                    </small>
                    <button type="button" class="btn btn-sm btn-secondary"
                            data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../Components/help_modal_comex.php'; ?>

<!-- La celda de fecha editable y el buscador, compartidos por las dos
     pestanas de Comercio Exterior. Ver su encabezado. -->
<script src="Js/Comex-fechas.js?v=<?php echo time(); ?>"></script>
<script src="Js/Comex-Proveedores_exterior.js?v=<?php echo time(); ?>"></script>
