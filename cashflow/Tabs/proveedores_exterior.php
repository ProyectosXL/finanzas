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
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Proveedores Exterior</h5>
                <small class="text-muted">Importaciones pendientes ordenadas por fecha de pago</small>
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
                <button id="btnExport" class="btn btn-sm btn-success">
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

<script src="Js/Comex-Proveedores_exterior.js?v=<?php echo time(); ?>"></script>
