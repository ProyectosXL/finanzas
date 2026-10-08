<?php
$tabName = 'Cobranzas FR';
// Sin permiso de edición, los controles de la selección no se dibujan. El
// endpoint lo vuelve a exigir: ver Class/AuthCashflow.php.
$edita = AuthCashflow::puedeEditar('cobranzas_fr');
?>
<link rel="stylesheet" href="Css/Ingresos-Cobranzas_fr.css?v=<?php echo time(); ?>">

<div class="tab-cobranzas_fr"<?php echo AuthCashflow::atributoEdicion('cobranzas_fr'); ?>>

    <!-- Avisos de importes fuera del horizonte o sin fecha -->
    <div id="avisosCob"></div>

    <!-- Solapas Principales: Real a Cobrar vs Pendientes Proyectados -->
    <ul class="nav nav-tabs mb-3" id="cobranzasFrTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabRealCobBtn" type="button" role="tab" data-origen="real">
                <i class="fas fa-check-circle text-success me-1"></i> Real a Cobrar
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabProyCobBtn" type="button" role="tab" data-origen="proyectado">
                <i class="fas fa-clock text-warning me-1"></i> Pendientes Proyectados
            </button>
        </li>
    </ul>

    <!-- KPI Cards Row -->
    <div class="row g-3 mb-4" id="summarySectionCob" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vista Días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="total4semanasCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo4semanasCob">Tramo diario</span>
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
                <div class="kpi-card-value" id="total11mesesCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotulo11mesesCob">Después del tramo diario</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Período Completo</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="totalGeneralCob">$ 0.00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="rotuloGeneralCob">Todo el horizonte</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header Section con Botones -->
    <!-- Cuánta plata está excluida a mano, y con qué motivos. SE VE AUNQUE "Ver
         excluidos" esté apagado, y sobre todo por eso: los excluidos están
         escondidos por defecto, así que sin este cartel la única forma de
         notar que falta un importe sería acordarse de prender el interruptor.
         Mismo criterio que #excluidosEch de Echeqs y que el aviso que deja
         IngresosProvider en el tablero. -->
    <div id="excluidosCob" class="alert alert-secondary py-2 px-3 mb-3" style="display: none;"></div>

    <div class="card mb-4">

        <!-- Sub-solapas: Resumen vs Detalle Facturas. Van DENTRO del panel y
             debajo de las solapas principales, que es lo que las hace leer
             como anidadas: no son otro origen de datos, son dos formas de
             mirar la misma tabla. Se muestran en los dos orígenes; lo que
             sigue habilitado sólo en Pendientes Proyectados → Detalle
             Facturas es la edición de la fecha de cobro manual.

             El estado sigue viviendo en `modoVista`: lo que cambió es el
             control, no el flujo. -->
        <div class="card-header cob-subtabs pt-2 pb-0 px-3">
            <ul class="nav nav-tabs card-header-tabs mb-0" id="cobranzasFrSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="btnVistaResumenCob" type="button" role="tab">
                        <i class="fas fa-list me-1"></i> Resumen
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="btnVistaDeepDiveCob" type="button" role="tab">
                        <i class="fas fa-search-plus me-1"></i> Detalle Facturas
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0" id="tituloMatrizCob">Cobranzas Franquicias &mdash; Real a Cobrar</h5>
                    <small class="text-muted" id="subtituloMatrizCob">Propuestas de pago confirmadas por fecha de cobro</small>
                </div>
                <div class="search-box-container ms-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaCob" class="form-control border-start-0 ps-0" placeholder="Buscar cliente o comprobante..." style="min-width: 230px;">
                    </div>
                </div>

                <!-- Filtro por fecha de EMISIÓN. Es server-side: se manda como
                     parámetro y los items se filtran antes de EjeVista, así que
                     las columnas, el pie de totales y las tarjetas describen lo
                     que se está viendo. Ver Ingresos::validarRangoFechaEmision(). -->
                <div class="filtro-emision d-flex align-items-center gap-1">
                    <span class="text-muted small text-nowrap">Emisión</span>
                    <input type="date" id="fechaDesdeCob" class="form-control form-control-sm"
                           title="Desde esta fecha de emisión, inclusive">
                    <span class="text-muted small">a</span>
                    <input type="date" id="fechaHastaCob" class="form-control form-control-sm"
                           title="Hasta esta fecha de emisión, inclusive">
                    <button id="btnLimpiarFechasCob" class="btn btn-sm btn-outline-secondary"
                            title="Quitar el filtro por fecha de emisión" disabled>
                        <i class="fas fa-eraser"></i>
                    </button>
                </div>

                <!-- Los clientes excluidos en Parámetros -> Cobranzas NO SE VEN por
                     defecto: ya se decidió que esa plata no va. Prendido, sus
                     facturas aparecen atenuadas y no suman en nada -ni eje, ni
                     pie, ni tarjetas-. Vale en las dos solapas y en Resumen y
                     Detalle Facturas. Mismo criterio que "Ver excluidos" de
                     Echeqs. -->
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="verExcluidosCob">
                    <label class="form-check-label small text-muted text-nowrap" for="verExcluidosCob">
                        Ver excluidos
                    </label>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap align-items-center">
                <!-- Los tres botones los dibuja Js/eje-vistas.js -->
                <div id="vistasCob"></div>

                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasCob"></div>

                <button id="btnRefreshCob" class="btn btn-sm btn-outline-primary" title="Actualizar datos">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="btnExportCob" class="btn btn-sm btn-success" title="Exportar a Excel">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Período que se está midiendo. El filtro aplicado va en un elemento
             APARTE: eje-vistas.js reescribe #periodoCob cada vez que se cambia
             de vista, así que lo que se agregara ahí se perdería. -->
        <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
            <small class="text-muted" id="periodoCob"></small>
            <small class="text-muted" id="filtroPeriodoCob"></small>
        </div>

        <?php if ($edita): ?>
        <!-- LA BARRA DE SELECCIÓN. Es el gesto de Proveedores Locales: se
             eligen facturas, se lee cuántas son y por cuánta plata, y recién
             ahí se les pone una fecha o se las vuelve a la calculada.

             Sólo existe en Pendientes Proyectados → Detalle Facturas: en Real
             a Cobrar la fecha sale de la propuesta aceptada, y en Resumen la
             fila es un cliente y no un comprobante. Aparece sólo cuando hay
             algo elegido: una barra con los botones apagados ocupa lugar para
             decir que no se puede hacer nada.

             La fecha se elige adentro del diálogo, junto al número de facturas
             y al importe, y no acá: ese número es lo que hace notar que se
             seleccionó de más, y hay que leerlo antes de elegir. -->
        <div id="barraSelCob" class="card-body py-2 border-bottom cob-barra-sel" style="display: none;">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="fw-semibold" id="selResumenCob"></span>
                <div class="d-flex gap-2 ms-auto flex-wrap">
                    <button class="btn btn-sm btn-outline-primary" id="btnFecharSelCob">
                        <i class="fas fa-calendar-day me-1"></i> Poner fecha de cobro
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnVolverSelCob"
                            title="Borra la fecha cargada a mano de las seleccionadas que la tienen: vuelven a la fecha de emisión + PPP del grupo.">
                        <i class="fas fa-rotate-left me-1"></i> Volver a la fecha calculada
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnLimpiarSelCob">
                        Limpiar selección
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-body p-0">
            <div class="cargando-slot" id="loadingSpinnerCob" data-cargando="Cargando matriz de cobranzas…"></div>
            
            <!-- .tabla-temporal: header de dos filas fijo arriba, pie de
                 totales fijo abajo y columnas descriptivas fijas a la
                 izquierda. Ver Css/main.css. -->
            <div class="table-wrapper table-responsive tabla-temporal" id="tableWrapperCob" style="display: none;">
                <table id="tablaCobranzasFR" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <!-- No hay columna Tipo: la solapa activa ya dice si lo
                                 que se está viendo es real o proyectado, así que el
                                 badge REAL/PROYECCIÓN repetía el encabezado en cada
                                 fila. Lo que distinguía dentro de la vista "todos"
                                 sigue estando: el color de fila (.fila-proyeccion) y
                                 el PPP en el title de COD_CLI. -->
                            <!-- LA COLUMNA DE SELECCIÓN, para la fecha de cobro
                                 masiva. ESTÁ SIEMPRE EN EL DOM y se esconde
                                 donde no se puede seleccionar -Real a Cobrar,
                                 Resumen, sin permiso-, con la clase
                                 .con-seleccion de la tabla. Si se dibujara sólo
                                 en Detalle Facturas, los nth-child del modo
                                 Resumen y los índices de las columnas fijas
                                 cambiarían según la solapa. Es lo mismo que hace
                                 tablaCorp de Pagos con Tarjetas.

                                 data-orden="no": no hay valor por el cual
                                 ordenar, y tildar "todas" no tiene que ordenar
                                 la tabla. data-exportar-omitir: en el Excel
                                 sería una columna de "Sí/No". -->
                            <th rowspan="2" class="text-center col-seleccion" style="width: 46px;"
                                data-orden="no" data-exportar-omitir>
                                <?php if ($edita): ?>
                                <input type="checkbox" class="form-check-input" id="selTodasCob"
                                       title="Seleccionar todas las facturas que se están viendo. Con el buscador puesto, son las de ese cliente.">
                                <?php endif; ?>
                            </th>
                            <th rowspan="2">COD_CLI</th>
                            <th rowspan="2" class="col-texto">RAZON_SOC</th>
                            <th rowspan="2">FECHA</th>
                            <th rowspan="2">T_COMP</th>
                            <th rowspan="2">N_COMP</th>
                            <th rowspan="2">Desc</th>
                            <th rowspan="2">Dias</th>
                            <th rowspan="2">Importe Bruto</th>
                            <th rowspan="2" id="thImporteNetoCob">Importe Neto</th>
                            <!-- data-orden-nombre: el JS le cambia el rótulo
                                 según la solapa -"Cobro" en Real a Cobrar,
                                 "F. Prob. Cobro" en Pendientes Proyectados- y
                                 es la misma fecha en las dos. Sin un nombre
                                 estable, el orden guardado se perdería al
                                 cambiar de solapa. Ver Js/tabla-orden.js. -->
                            <th rowspan="2" id="thCobroCob" data-orden-nombre="cobro">Cobro</th>
                            <!-- Acá terminan las descriptivas. El resto de esta
                                 fila son los totales de cada columna del eje,
                                 arriba de su fecha: los pinta Js/eje-totales.js
                                 desde generarFilaTotales(), con la misma cuenta
                                 que el pie. -->
                        </tr>
                        <tr id="headerRowSubCob">
                            <!-- Los días se generan dinámicamente -->
                        </tr>
                    </thead>
                    <tbody id="tableBodyCob">
                        <!-- Las filas se generan dinámicamente -->
                    </tbody>
                    <tfoot class="table-light">
                        <tr id="totalsRowCob">
                            <td colspan="11" class="fw-bold text-end">TOTALES</td>
                            <!-- Los totales se generan dinámicamente -->
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Ingresos-Cobranzas_fr.js?v=<?php echo time(); ?>"></script>
