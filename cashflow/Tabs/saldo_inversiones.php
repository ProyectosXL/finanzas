<?php $tabName = 'Saldo de Inversiones'; ?>
<link rel="stylesheet" href="Css/OtrosIngresos-Saldo_inversiones.css?v=<?php echo time(); ?>">

<!--
    Otros Ingresos → Saldo de Inversiones.

    Mismo circuito que Dólares Cuenta Comitente y a propósito: formulario
    mínimo, sin baja física e historial por fecha. Lo que cambia es la moneda.

    SE CARGA EN PESOS. El campo es IMPORTE_ARS y no hay conversión: lo que se
    carga es lo que entra al tablero. Los dólares de la cuenta comitente hacen
    lo contrario —se guardan en dólares para no congelar la valuación— porque
    ahí el dato ES en dólares. Acá el saldo se informa en pesos, así que no hay
    nada que valuar. La decisión y cómo darla vuelta están arriba de
    sql/cashflow_saldo_inversiones.sql.

    EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA. Cargar una fecha que ya
    existe no hace UPDATE: da de baja la anterior e inserta una nueva. El
    historial es lo único que explica por qué el número de ayer era otro.
-->
<div class="tab-saldo_inversiones">

    <div id="avisosInv"></div>

    <div class="row g-3 mb-4" id="summaryInv" style="display: none;">
        <div class="col-md-6 col-lg-4">
            <div class="kpi-card kpi-card-destacada">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Última carga</span>
                    <div class="kpi-card-icon green"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="kpi-card-value" id="ultimoImporteInv">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="ultimaFechaInv">Sin cargas</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total cargado</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-coins"></i></div>
                </div>
                <div class="kpi-card-value" id="totalInv">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleTotalInv">0 fecha(s) con importe vigente</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Cargar saldo</h5>
            <small class="text-muted">
                Fecha e importe <strong>en pesos</strong>. No hay conversión: el saldo se
                informa en pesos, así que entra al tablero tal como se carga.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="fechaInv">Fecha</label>
                    <input type="date" id="fechaInv" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="importeInv">Importe en pesos</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" id="importeInv" class="form-control form-control-sm"
                               min="0" step="0.01" placeholder="0,00">
                    </div>
                </div>
                <div class="col-md-3">
                    <button id="btnGuardarInv" class="btn btn-sm btn-primary">
                        <i class="fas fa-save me-1"></i> Guardar
                    </button>
                    <button id="btnRefreshInv" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                </div>
            </div>
            <div class="param-hint mt-2">
                Cargar una fecha que ya tiene importe <strong>no lo edita</strong>: la carga
                anterior queda en el historial y la nueva pasa a ser la vigente. Es lo único que
                después explica por qué el número de esa fecha cambió.
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Importes vigentes</h5>
                <small class="text-muted">
                    Un importe por fecha. Las cargas pisadas no se borran: se ven desde
                    <em>Historial</em>.
                </small>
            </div>
            <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
            <button class="btn btn-sm btn-success" data-exportar="tablaInversiones"
                    data-exportar-nombre="Saldo_Inversiones"
                    title="Exportar a Excel lo que se está viendo">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>
        <div class="card-body p-0">
            <div class="loading-spinner" id="loadingInv">
                <div class="spinner"></div>
                <p>Cargando importes...</p>
            </div>

            <div class="table-responsive" id="wrapperInv" style="display: none;">
                <table class="table table-hover mb-0" id="tablaInversiones">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Fecha</th>
                            <th class="text-end" style="width: 200px;">Importe (ARS)</th>
                            <th class="text-center" style="width: 200px;">Cargado el</th>
                            <th class="text-center" style="width: 160px;">Historial</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="bodyInv"></tbody>
                    <tfoot class="table-light" id="footInv"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Historial de una fecha: por qué el número de ayer era otro -->
    <div class="modal fade" id="modalHistorialInv" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        <i class="fas fa-clock-rotate-left me-1"></i>
                        Historial de <span id="historialFechaInv"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-sm mb-0" id="tablaHistorialInv">
                        <thead class="table-light">
                            <tr>
                                <th class="text-end">Importe (ARS)</th>
                                <th class="text-center" style="width: 110px;">Estado</th>
                                <th class="text-center" style="width: 180px;">Cargado el</th>
                            </tr>
                        </thead>
                        <tbody id="bodyHistorialInv"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-success"
                            data-exportar="tablaHistorialInv"
                            data-exportar-nombre="Saldo_Inversiones_Historial"
                            title="Exportar a Excel el historial de esta fecha">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/OtrosIngresos-Saldo_inversiones.js?v=<?php echo time(); ?>"></script>
