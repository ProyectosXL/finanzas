<?php $tabName = 'Crono Nacionalización'; ?>
<link rel="stylesheet" href="Css/Comex-Crono_nacionalizacion.css?v=<?php echo time(); ?>">

<div class="tab-crono_nacionalizacion"<?php echo AuthCashflow::atributoEdicion('crono_nacionalizacion'); ?>>

    <!-- Lo que quedó fuera del horizonte o sin fecha. Antes se descartaba en
         silencio, así que la tabla podía informar de menos sin decirlo. -->
    <div id="avisosCronoNac"></div>

    <!-- KPI Cards Row.
         Las tres tarjetas miden los tres períodos de las tres vistas, así que
         cada una se corresponde con un botón. El rótulo lo escribe el JS con el
         período real, que depende del horizonte configurado. -->
    <div data-kpis="kpisCronoNac" class="row g-3 mb-4" id="summarySection" style="display: none;">
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

                <!-- LAS VENCIDAS NO SE VEN POR DEFECTO, igual que en
                     Proveedores Exterior: una nacionalización con la fecha
                     vencida no suma en ninguna columna del período, así que en
                     el trabajo normal son ruido. Al 19/09/2026 son 24 de 76.

                     Esconder filas que ya valían cero no cambia ningún total;
                     el interruptor existe para poder ir a corregirles la fecha,
                     y CUÁNTO ESCONDE SE DICE AL LADO, siempre. -->
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="verVencidasCronoNac">
                    <label class="form-check-label small text-muted" for="verVencidasCronoNac">
                        Ver vencidas
                        <span class="text-muted fst-italic ms-1"
                              id="estadoVencidasCronoNac"></span>
                    </label>
                </div>

                <!-- LAS PAGADAS TAMPOCO SE VEN POR DEFECTO. Ya se decidió que
                     ese gasto no se espera más, así que en el trabajo normal
                     son ruido. El interruptor es desde donde se destilda lo que
                     se marcó por error, y CUÁNTO ESCONDE SE DICE AL LADO. -->
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="verPagadosCronoNac">
                    <label class="form-check-label small text-muted" for="verPagadosCronoNac">
                        Ver pagadas
                        <span class="text-muted fst-italic ms-1"
                              id="estadoPagadosCronoNac"></span>
                    </label>
                </div>

                <!-- LOS CONTENEDORES CON COSTOS REALES TAMPOCO SE VEN POR
                     DEFECTO. Comercio Exterior ya cargó lo que costó la
                     nacionalización, así que se toman como pagados y no suman;
                     están en la lista para poder registrarles un pago parcial.
                     El que tiene un pago parcial con saldo SUMA, y ése se ve
                     siempre: una fila que suma no se esconde. Lo decide el
                     servidor (OCULTA_POR_COSTOS) y el conteo va al lado. Sin
                     sql/cashflow_comex_nac_pago.sql no vienen, y el interruptor
                     no se muestra. -->
                <div class="form-check form-switch" id="switchCostosCronoNac" style="display: none;">
                    <input class="form-check-input" type="checkbox" id="verCostosCronoNac">
                    <label class="form-check-label small text-muted" for="verCostosCronoNac">
                        Ver con costos reales
                        <span class="text-muted fst-italic ms-1"
                              id="estadoCostosCronoNac"></span>
                    </label>
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
            <!-- De dónde sale el dólar con el que se valúa la tabla y hasta qué
                 mes llega la curva. No es decoración: es lo que permite auditar
                 los importes en pesos contra el mercado. Igual que en
                 Proveedores Exterior. -->
            <small class="text-muted ms-2" id="cotizCronoNac"></small>
        </div>

        <div class="card-body p-0">
            <div class="cargando-slot" id="loadingSpinner" data-cargando="Cargando datos…"></div>
            
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
                                <!-- EL GASTO ESTIMADO ESTÁ EN DÓLARES. Es la
                                     suma de los conceptos 3 a 10 de la
                                     estimación, que Comercio Exterior calcula
                                     como porcentajes del CIF, y el CIF arranca
                                     en el FOB en dólares. Queda como
                                     REFERENCIA —es el dato tal como se carga
                                     del otro lado—; lo que entra al cashflow es
                                     la columna en pesos. -->
                                <th rowspan="2">Importe Est. (USD)</th>
                                <!-- CON QUÉ DÓLAR SE VALUÓ ESTA FILA: la curva
                                     de dólar futuro ROFEX del mes de la fecha
                                     de NACIONALIZACIÓN, que no es la de pago.
                                     Acá NO se corrige a mano, a diferencia de
                                     Proveedores Exterior: el override es por
                                     contenedor y las dos pestañas lo mirarían
                                     en dos meses distintos. Ver
                                     Comex::valuar(). -->
                                <th rowspan="2">Dólar aplicado</th>
                                <th rowspan="2">Importe ($)</th>
                                <th rowspan="2">ETD</th>
                                <th rowspan="2">ETA</th>
                                <th rowspan="2">
                                    Fecha Nac.
                                    <i class="fas fa-pen-to-square ms-1" style="font-size: 10px;"
                                       title="Click para editar"></i>
                                </th>
                                <!-- EL TILDE DE "YA SE PAGÓ", sobre el gasto de
                                     nacionalización. Es el OTRO pago del mismo
                                     contenedor: marcar el del proveedor del
                                     exterior no dice nada de éste. -->
                                <th rowspan="2">
                                    Pagada
                                    <i class="fas fa-check-square ms-1" style="font-size: 10px;"
                                       title="Usalo cuando la nacionalización se pagó completa. Si pagaste una parte y queda saldo, usá «Pago parcial»."></i>
                                </th>
                                <!-- EL PAGO PARCIAL, en cuatro columnas. La base es
                                     en PESOS y es la de todo el contenedor: el
                                     costo real si Comercio Exterior ya lo cargó, o
                                     la estimación valuada. Ver Comex.php, "EL PAGO
                                     PARCIAL DE LA NACIONALIZACION". -->
                                <th rowspan="2">
                                    Costo ($)
                                    <i class="fas fa-info-circle ms-1" style="font-size: 10px;"
                                       title="Real: lo que cargó Comercio Exterior como costo de nacionalización. Estimado: la estimación en dólares, valuada con la curva del mes de nacionalización."></i>
                                </th>
                                <th rowspan="2">Pagado ($)</th>
                                <th rowspan="2">Saldo ($)</th>
                                <th rowspan="2">
                                    Fecha del saldo
                                    <i class="fas fa-info-circle ms-1" style="font-size: 10px;"
                                       title="Cuándo se espera pagar lo que falta. Sin pagos parciales es la de nacionalización."></i>
                                </th>
                                <!-- Acá terminan las descriptivas. El resto de
                                     esta fila son los totales de cada columna
                                     del eje, arriba de su fecha: los pinta
                                     Js/eje-totales.js desde generarFilaTotales(),
                                     con la misma cuenta que el pie. -->
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
                                <td colspan="16" class="fw-bold text-end">TOTALES</td>
                                <!-- Los totales se generan dinámicamente -->
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================
     EL PAGO PARCIAL DE LA NACIONALIZACIÓN

     Un modal y no Notificacion.pedirTexto(): son varios campos que dependen
     unos de otros -la moneda decide si se piden dólares y cotización, la fecha
     de nacionalización decide si la del saldo es obligatoria- y el diálogo
     tiene que mostrar el saldo antes y después mientras se escribe. Lo arma
     Js/Comex-Crono_nacionalizacion.js; el servidor valida todo de nuevo
     (Comex::validarPagoNac()).
     ================================================================ -->
<div class="modal fade" id="modalPagoNac" tabindex="-1" aria-labelledby="modalPagoNacTitulo" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPagoNacTitulo">Pago parcial de la nacionalización</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">
                    Registrá lo que pagaste. Lo que falta se sigue proyectando como saldo en la
                    fecha que indiques. <strong>Si pagaste todo, cerrá este diálogo y usá el tilde
                    «Pagado».</strong>
                </p>
                <div class="small text-muted mb-3" id="pagoNacContenedor"></div>

                <table id="tablaPagoNacResumen" class="table table-sm small mb-3 pago-nac-resumen">
                    <thead>
                        <tr><th></th><th class="text-end">Antes</th><th class="text-end">Después</th></tr>
                    </thead>
                    <tbody>
                        <tr><td id="pagoNacBaseRotulo">Costo</td>
                            <td class="text-end" id="pagoNacBase"></td>
                            <td class="text-end" id="pagoNacBase2"></td></tr>
                        <tr><td>Pagado</td>
                            <td class="text-end" id="pagoNacPagadoAntes"></td>
                            <td class="text-end" id="pagoNacPagadoDespues"></td></tr>
                        <tr class="fw-bold"><td>Saldo</td>
                            <td class="text-end" id="pagoNacSaldoAntes"></td>
                            <td class="text-end" id="pagoNacSaldoDespues"></td></tr>
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1" for="pagoNacFecha">Fecha de pago</label>
                        <input type="date" class="form-control form-control-sm" id="pagoNacFecha">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1" for="pagoNacMoneda">Moneda</label>
                        <select class="form-select form-select-sm" id="pagoNacMoneda">
                            <option value="ARS">Pesos</option>
                            <option value="USD">Dólares</option>
                        </select>
                    </div>
                    <div class="col-12 pago-nac-ars">
                        <label class="form-label small mb-1" for="pagoNacImporte">Importe ($)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="pagoNacImporte">
                    </div>
                    <div class="col-6 pago-nac-usd" style="display: none;">
                        <label class="form-label small mb-1" for="pagoNacImporteUsd">Importe (U$S)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="pagoNacImporteUsd">
                    </div>
                    <div class="col-6 pago-nac-usd" style="display: none;">
                        <label class="form-label small mb-1" for="pagoNacCotizacion">Cotización usada</label>
                        <input type="number" step="0.0001" min="0" class="form-control form-control-sm" id="pagoNacCotizacion">
                    </div>
                    <div class="col-12 pago-nac-usd small text-muted" style="display: none;" id="pagoNacEquivalente"></div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="pagoNacFechaSaldo">
                            Fecha estimada de pago del saldo
                            <span class="text-muted" id="pagoNacFechaSaldoNota"></span>
                        </label>
                        <input type="date" class="form-control form-control-sm" id="pagoNacFechaSaldo">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="pagoNacObs">Observación (opcional)</label>
                        <input type="text" maxlength="200" class="form-control form-control-sm" id="pagoNacObs">
                    </div>
                </div>
                <div class="small text-danger mt-2" id="pagoNacError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-primary" id="pagoNacGuardar">Registrar pago</button>
            </div>
        </div>
    </div>
</div>

<!-- Los pagos de un contenedor, vigentes y anulados, y cómo se fue moviendo la
     fecha del saldo. Desde acá se anula un pago -con confirmación- y se cambia
     la fecha del saldo sin cargar un pago nuevo. -->
<div class="modal fade" id="modalPagosNac" tabindex="-1" aria-labelledby="modalPagosNacTitulo" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPagosNacTitulo">Pagos parciales del contenedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="pagosNacCuerpo"></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../Components/help_modal_comex.php'; ?>

<!-- La celda de fecha editable y el buscador, compartidos por las dos
     pestanas de Comercio Exterior. Ver su encabezado. -->
<script src="Js/Comex-fechas.js?v=<?php echo time(); ?>"></script>
<script src="Js/Comex-Crono_nacionalizacion.js?v=<?php echo time(); ?>"></script>
