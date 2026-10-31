<?php $tabName = 'Gastos Locales'; ?>
<link rel="stylesheet" href="Css/Gastos-Locales.css?v=<?php echo time(); ?>">

<!-- SOLO LECTURA: el día de pago y las cuentas excluidas se cambian en
     Parámetros › Generales, con su permiso. Por eso no hay controles de
     edición acá y la raíz no depende de ningún permiso de escritura. -->
<div class="tab-gastos_locales"<?php echo AuthCashflow::atributoEdicion('gastos_locales'); ?>>

    <div id="avisosGastosLocales"></div>

    <!-- Las tres tarjetas: de dónde sale el promedio, cuánto es por mes y
         cuánto se proyecta en lo que se está mirando. -->
    <div data-kpis="summaryGastosLocales" class="row g-3 mb-4" id="summaryGastosLocales" style="display: none;">
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total de los últimos 90 días</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-cash-register"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="glTotalVentana">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="glVentana">Egresos de caja, cuentas 5</span>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Promedio mensual</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-calendar-days"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="glMensual">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="glDiario">Gasto diario × 30</span>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Proyectado</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="glProyectado">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="glProyectadoPie">Con inflación</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         LA GRILLA: UNA FILA POR LOCAL
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <h5 class="mb-0">Gastos de caja por local</h5>
                    <small class="text-muted">
                        Promedio de los últimos 90 días, llevado a cada mes con la inflación
                        <i class="fas fa-info-circle ms-1"
                           title="Se toman los egresos de caja de los locales propios en las cuentas que empiezan con 5, salvo las excluidas en Parámetros › Generales. El gasto diario es el total de los 90 días dividido 90. En las semanas del tramo diario se paga una semana de gasto (7 días) el día elegido en el cronograma; después, un importe por mes con los días que quedan. Todo es proyección."></i>
                    </small>
                </div>

                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaGastosLocales"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar local…" style="min-width: 220px;">
                    </div>
                </div>

                <div id="vistasGastosLocales"></div>
            </div>
            <div class="d-flex gap-2">
                <div id="colFijasGastosLocales"></div>
                <button id="btnRefreshGastosLocales" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button class="btn btn-sm btn-success" data-exportar="tablaGastosLocales"
                        data-exportar-nombre="Gastos_Locales"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="periodoGastosLocales"></small>
            <small class="text-muted ms-2" id="glCronoTexto"></small>
        </div>

        <div class="card-body p-0">
            <div class="cargando-slot" id="glSpinner" data-cargando="Leyendo los gastos de caja de los locales…"></div>

            <div class="table-wrapper" id="glTableWrapper" style="display: none;">
                <div class="table-responsive">
                    <!-- data-orden="no": la fila de un local lleva su detalle
                         abajo, y ordenar las separaría. -->
                    <table id="tablaGastosLocales" class="table table-hover mb-0" data-orden="no">
                        <thead id="glThead"></thead>
                        <tbody id="glTableBody"></tbody>
                        <tfoot class="table-light" id="glTfoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>En las semanas del tramo diario</strong> se paga una semana de gasto
                (7 días) el día del cronograma; si ese día no es hábil, se paga el día hábil
                <strong>siguiente</strong>. <strong>En las columnas de los meses</strong> va el
                gasto de los días que quedan fuera de esas semanas, así que un mes partido por el
                final del tramo muestra sólo su parte. Ningún día se cuenta dos veces.
                <strong>Un pago con fecha de hoy o anterior no se proyecta</strong>: ya está en el
                saldo que abre el tablero. Pasá el mouse por un importe para ver de dónde sale.
            </small>
        </div>
    </div>

    <!-- ============================================================
         LOS COMPROBANTES DE UN LOCAL
         ============================================================ -->
    <div class="modal fade" id="modalComprobantesLocal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Egresos de caja
                        <small class="text-muted ms-2" id="glCompTitulo"></small>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table id="tablaComprobantesLocal" class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Comprobante</th>
                                    <th>Cuenta</th>
                                    <th>Leyenda</th>
                                    <th>Usuario</th>
                                    <th class="text-end">Importe</th>
                                </tr>
                            </thead>
                            <tbody id="glCompBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <small class="text-muted" id="glCompPie"></small>
                    <button type="button" class="btn btn-sm btn-success"
                            data-exportar="tablaComprobantesLocal"
                            data-exportar-nombre="Gastos_Locales_Comprobantes">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/Gastos-Locales.js?v=<?php echo time(); ?>"></script>
