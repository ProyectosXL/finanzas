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
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p>Cargando datos...</p>
            </div>
            
            <div class="table-wrapper" id="tableWrapper" style="display: none;">
                <div class="table-responsive tabla-temporal">
                    <table id="tablaProveedoresExterior" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2" class="col-texto">Proveedor</th>
                                <th rowspan="2">Contenedor</th>
                                <th rowspan="2">Orden Compra</th>
                                <th rowspan="2">Despachante</th>
                                <!-- El FOB en dólares queda como REFERENCIA. Es
                                     el dato del contenedor y es con lo que se
                                     chequea contra la factura del proveedor; lo
                                     que entra al cashflow es la columna en
                                     pesos. -->
                                <th rowspan="2">Valor FOB (USD)</th>
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
                                <td colspan="10" class="fw-bold text-end">TOTALES</td>
                                <!-- Los totales se generan dinámicamente -->
                            </tr>
                        </tfoot>
                    </table>
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
