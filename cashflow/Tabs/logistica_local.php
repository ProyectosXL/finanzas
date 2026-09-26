<?php $tabName = 'Logística Local'; ?>
<link rel="stylesheet" href="Css/Logistica-Local.css?v=<?php echo time(); ?>">

<div class="tab-logistica_local">

    <!-- Los avisos van arriba de todo a propósito. El primero puede ser que los
         fleteros NO estén excluidos de Cuentas a Pagar Locales, y en ese caso
         el tablero está contando su pago DOS VECES: una proyectada acá y otra
         por su deuda real. El cuadro cierra igual, así que nada lo delata salvo
         este cartel. -->
    <div id="avisosLogistica"></div>

    <!-- Las tres tarjetas contestan las tres preguntas de la pantalla: cuánto
         se proyecta, con cuántos fleteros, y cuántos no se pueden proyectar. -->
    <div class="row g-3 mb-4" id="summaryLogistica" style="display: none;">
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Proyectado</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-truck-fast"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="logTotal">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="logTotalPie">Sin IVA ni otros conceptos</span>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Fleteros activos</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="logFleteros">0</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Se dan de alta en Parámetros › Logística</span>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Sin proyectar</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="logSinProyectar">0</div>
                <div class="kpi-card-footer">
                    <!-- Nunca un cero donde falta un dato: es la regla de todo
                         el módulo y acá es lo que esta tarjeta cuenta. -->
                    <span class="text-muted">Les falta un dato: no se ponen en cero</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         LA PLANILLA
         Una fila por fletero, con sus datos editables a la izquierda
         y el eje temporal a la derecha.
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <h5 class="mb-0">Pagos proyectados por fletero</h5>
                    <small class="text-muted">
                        Horas por mes × valor hora del mes, mitad y mitad en el 2do y el 4to
                        viernes
                        <i class="fas fa-info-circle ms-1" id="logAyuda"
                           title="TODO es proyección: no hay parte real. El valor hora base rige su mes y los dos siguientes; cada tres meses hay un ajuste, cuyo % es la suma SIN componer de la inflación del mes del ajuste y de los dos anteriores. Los importes son finales: sin IVA ni otros conceptos."></i>
                    </small>
                </div>

                <!-- Mismo marcado que el buscador del resto del módulo: un
                     control que se ve distinto en cada pantalla se lee como
                     otro control. -->
                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaLogistica"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar fletero…" style="min-width: 220px;">
                    </div>
                </div>

                <!-- Las tres vistas del módulo: días, meses y período completo.
                     Los dibuja Js/eje-vistas.js. -->
                <div id="vistasLogistica"></div>
            </div>
            <div class="d-flex gap-2">
                <div id="colFijasLogistica"></div>
                <button id="btnRefreshLogistica" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <!-- Lo engancha Js/tabla-export.js por el data-exportar, igual
                     que el resto del módulo: baja exactamente lo que se ve. -->
                <button class="btn btn-sm btn-success" data-exportar="tablaLogistica"
                        data-exportar-nombre="Logistica_Local"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Qué período se está midiendo. Sin esto, el total del pie no dice de
             qué columnas sale. -->
        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="periodoLogistica"></small>
            <small class="text-muted ms-2" id="logCronoTexto"></small>
        </div>

        <div class="card-body p-0">
            <div class="cargando-slot" id="logSpinner" data-cargando="Calculando la proyección de logística…"></div>

            <div class="table-wrapper" id="logTableWrapper" style="display: none;">
                <div class="table-responsive">
                    <!-- data-orden="no": las columnas del eje son fechas, y el
                         orden de las filas lo decide el nombre del fletero. -->
                    <table id="tablaLogistica" class="table table-hover mb-0" data-orden="no">
                        <thead id="logThead">
                            <!-- Los encabezados los genera el JS: dependen de la
                                 vista activa. -->
                        </thead>
                        <tbody id="logTableBody"></tbody>
                        <tfoot class="table-light" id="logTfoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>Un mes que cae partido por el final del tramo diario</strong> reparte sus
                dos pagos en dos columnas distintas: el que queda adentro va a su columna del
                día y el que queda afuera, a la columna del mes. Por eso esa columna mensual
                puede mostrar medio importe y no es un error.
                <strong>Un pago con fecha anterior o igual a hoy no se proyecta</strong>: esa
                plata ya salió y ya está en el saldo que abre el tablero.
            </small>
        </div>
    </div>

    <!-- ============================================================
         EL VALOR HORA MES A MES
         Qué valor rige cada mes y de qué ajuste sale. Va en su propia
         tabla y no como otra fila de la planilla porque contesta otra
         pregunta: no cuánto se paga, sino por qué.
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Valor hora mes a mes</h5>
                <small class="text-muted">
                    Qué valor rige cada mes y de qué ajuste sale. Pasá el mouse por una celda
                    para ver los % que se sumaron y de qué meses salen.
                </small>
            </div>
            <button class="btn btn-sm btn-outline-success" data-exportar="tablaValorHora"
                    data-exportar-nombre="Logistica_Valor_Hora"
                    title="Exportar a Excel lo que se está viendo">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaValorHora" class="table table-sm table-hover mb-0" data-orden="no">
                    <thead id="logVhThead"></thead>
                    <tbody id="logVhBody"></tbody>
                </table>
            </div>
        </div>
        <div class="card-body py-2 border-top">
            <small class="text-muted">
                El valor hora <strong>siempre sale de la fórmula</strong>: no hay override
                manual. Lo editable es el valor base —lo que efectivamente se pactó— y la
                inflación de cada mes, en Parámetros › Generales. Un tercer lugar donde tocar
                el mismo número haría imposible contestar por qué un mes vale lo que vale.
            </small>
        </div>
    </div>

    <!-- ============================================================
         EL DETALLE DE UN FLETERO
         Los dos pagos de cada mes, con su fecha y su estado.
         ============================================================ -->
    <div class="modal fade" id="modalDetalleFletero" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Pagos del fletero
                        <small class="text-muted ms-2" id="logDetalleTitulo"></small>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table id="tablaDetalleFletero" class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    <th>Pago</th>
                                    <th>Fecha</th>
                                    <th class="text-end">Valor hora</th>
                                    <th class="text-end">Importe</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="logDetalleBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <small class="text-muted" id="logDetallePie"></small>
                    <button type="button" class="btn btn-sm btn-success"
                            data-exportar="tablaDetalleFletero"
                            data-exportar-nombre="Logistica_Detalle_Fletero">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/Logistica-Local.js?v=<?php echo time(); ?>"></script>
