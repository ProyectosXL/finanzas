<?php $tabName = 'Crono Nacionalización'; ?>
<link rel="stylesheet" href="Css/Comex-Crono_nacionalizacion.css?v=<?php echo time(); ?>">

<div class="tab-crono_nacionalizacion">

    <!-- Lo que quedó fuera del horizonte o sin fecha. Antes se descartaba en
         silencio, así que la tabla podía informar de menos sin decirlo. -->
    <div id="avisosCronoNac"></div>

    <!-- KPI Cards Row.
         Las tres tarjetas miden los tres períodos de las tres vistas, así que
         cada una se corresponde con un botón. El rótulo lo escribe el JS con el
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
                <div class="kpi-card-value" id="total4semanas">$ 0.00</div>
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
                <div class="kpi-card-value" id="total11meses">$ 0.00</div>
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
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneral">$ 0.00</div>
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
                    <h5 class="mb-0">Cronograma de Nacionalización</h5>
                    <small class="text-muted">
                        Contenedores ordenados por fecha de nacionalización
                        <i class="fas fa-info-circle ms-1"
                           title="Click en la fecha de nacionalización para editarla. Se guarda en el maestro de Comercio Exterior, así que la ve también esa aplicación. Las vencidas están marcadas: su importe no entra en ninguna columna hasta que se les cargue una fecha nueva."></i>
                    </small>
                </div>
                <!-- Mismo marcado y mismo comportamiento que el buscador de
                     Echeqs y el de Proveedores Exterior: la misma tabla ancha
                     con el mismo problema, y un control que se ve distinto en
                     cada pantalla se lee como otro control. Busca SÓLO
                     Proveedor, Contenedor y Orden de Compra: son los tres
                     campos por los que se busca un contenedor, y mirar toda la
                     fila haría que un importe o una fecha den falsos positivos.
                     Ver Js/Comex-fechas.js. -->
                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaCronoNac"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar proveedor, contenedor u orden de compra…"
                               style="min-width: 260px;">
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- Los tres botones los dibuja Js/eje-vistas.js a partir del
                     eje que resolvió el backend. -->
                <div id="vistasCronoNac"></div>
                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasCronoNac"></div>
                <button id="btnRefresh" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <!-- Lo engancha Js/tabla-export.js por el data-exportar, igual
                     que el resto del módulo: antes era un #btnExport con su
                     listener y una función envoltorio en el JS de la pestaña,
                     que no hacían nada que el atributo no haga. Y saca del clon
                     las filas que el buscador escondió, así que Exportar baja
                     exactamente lo que se está viendo. -->
                <button class="btn btn-sm btn-success" data-exportar="tablaCronoNacionalizacion"
                        data-exportar-nombre="Crono_Nacionalizacion">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Qué período se está midiendo. La vista Meses no cubre el horizonte
             completo, y sin esto su total se lee como el total de todo. -->
        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="periodoCronoNac"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p>Cargando datos...</p>
            </div>
            
            <div class="table-wrapper" id="tableWrapper" style="display: none;">
                <div class="table-responsive tabla-temporal">
                    <table id="tablaCronoNacionalizacion" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2">Fecha Est. EMB</th>
                                <th rowspan="2" class="col-texto">Proveedor</th>
                                <th rowspan="2">Contenedor</th>
                                <th rowspan="2">Orden Compra</th>
                                <th rowspan="2">Despachante</th>
                                <th rowspan="2">Importe Est.</th>
                                <th rowspan="2">ETD</th>
                                <th rowspan="2">ETA</th>
                                <th rowspan="2">
                                    Fecha Nac. 
                                    <i class="fas fa-pen-to-square ms-1" style="font-size: 10px;" 
                                       title="Click para editar"></i>
                                </th>
                                <!-- El rótulo y el colspan los pone el JS según
                                     la vista activa. -->
                                <th colspan="1" class="table-group-divider" id="periodoHeader">Días</th>
                            </tr>
                            <tr id="headerRowSub">
                                <!-- Los días/meses se generan dinámicamente -->
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Las filas se generan dinámicamente -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr id="totalsRow">
                                <td colspan="9" class="fw-bold text-end">TOTALES</td>
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
<script src="Js/Comex-Crono_nacionalizacion.js?v=<?php echo time(); ?>"></script>
