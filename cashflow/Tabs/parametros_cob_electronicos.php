<!--
    Parámetros → Cob. Electrónicos.

    Va en su propio archivo y con su propio JS, igual que Parámetros → Saldos y
    el editor de estructura: no comparte nada con los bloques de Ventas, así que
    un problema acá no puede llevarse puesta la pestaña que ya funciona. Sus
    clases llevan el prefijo pce- porque Parametros.js busca .param-input, .mix-*
    y .respaldo-* en TODO el documento.

    Dos secciones:
      1. Procesadoras — ABM. Una procesadora nueva entra INACTIVA y no se puede
         activar hasta tener al menos una alícuota vigente: activarla vacía
         habilitaría altas de movimientos que después no pueden calcular neto.
      2. Alícuotas por procesadora — editar un % INSERTA una vigencia nueva, no
         pisa la anterior, así los movimientos ya informados conservan su tasa.

    Nunca hay baja física: se inhabilita.
-->
<div class="pce-cobel">

    <div class="modulo-descripcion mb-3" id="descripcionCobel"></div>

    <div id="avisosParamCobel"></div>

    <div class="loading-spinner" id="loadingParamCobel">
        <div class="spinner"></div>
        <p>Cargando parámetros de Cob. Electrónicos...</p>
    </div>

    <div id="wrapperParamCobel" style="display: none;">

        <!-- ========================================================
             PROCESADORAS
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Procesadoras</h5>
                    <small class="text-muted">
                        Quién nos deposita: Payway, Mercado Pago y las que aparezcan. La columna
                        <strong>Tasa vigente</strong> es la suma de sus alícuotas de hoy, que es
                        con lo que se va a calcular el neto de un movimiento nuevo. Las
                        procesadoras se <strong>inhabilitan</strong>, nunca se borran.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button id="btnRefreshParamCobel" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnNuevaProcesadora" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Agregar procesadora
                    </button>
                    <button id="btnGuardarProcesadoras" class="btn btn-sm btn-primary">
                        <i class="fas fa-floppy-disk me-1"></i> Guardar procesadoras
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom" id="formProcesadora" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Razón social</label>
                        <input type="text" id="nuevaRazonSocial"
                               class="form-control form-control-sm" maxlength="80"
                               placeholder="Ej: Payway">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="btnAgregarProcesadora" class="btn btn-sm btn-primary flex-fill">
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button id="btnCancelarProcesadora" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div class="param-hint mt-2">
                    La procesadora entra <strong>inactiva</strong>, y no se puede activar hasta que
                    tenga al menos una alícuota vigente. Activarla vacía habilitaría acreditaciones
                    que después no pueden calcular el importe neto —que es exactamente lo que dejó
                    tres filas del Excel sin fórmula—. Es un criterio distinto al de una cuenta de
                    Saldos, que nace activa porque no rompe ningún invariante.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Razón social</th>
                                <th class="text-center" style="width: 200px;">Tasa vigente hoy</th>
                                <th class="text-center" style="width: 150px;">Última edición</th>
                                <th class="text-center" style="width: 110px;">Activa</th>
                            </tr>
                        </thead>
                        <tbody id="bodyProcesadoras"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             ALÍCUOTAS
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Alícuotas por procesadora</h5>
                    <small class="text-muted">
                        Las retenciones que la procesadora descuenta de la acreditación. El
                        concepto es libre: hoy son <strong>IIBB</strong> y <strong>SICREB</strong>,
                        que son las dos que estaban escondidas en una celda del Excel.
                        <strong>Editar un porcentaje inserta una vigencia nueva</strong> y no pisa
                        la anterior: los movimientos ya informados conservan la tasa con la que se
                        calcularon.
                    </small>
                </div>
                <button id="btnNuevaAlicuota" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Cargar alícuota
                </button>
            </div>

            <div class="card-body border-bottom" id="formAlicuota" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Procesadora</label>
                        <select id="nuevaAliProcesadora" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Concepto</label>
                        <input type="text" id="nuevaAliConcepto" class="form-control form-control-sm"
                               maxlength="30" placeholder="IIBB" list="conceptosCobel">
                        <datalist id="conceptosCobel"></datalist>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Alícuota</label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.0001" min="0" max="99.9999"
                                   id="nuevaAliPorcentaje" class="form-control text-end"
                                   placeholder="2,5">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm">Vigencia desde</label>
                        <input type="date" id="nuevaAliVigencia" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="btnAgregarAlicuota" class="btn btn-sm btn-primary flex-fill">
                            <i class="fas fa-check me-1"></i> Guardar vigencia
                        </button>
                        <button id="btnCancelarAlicuota" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- La suma resultante, antes de guardar. Si llega a 100% el
                     botón se bloquea: el neto saldría cero o negativo. -->
                <div class="pce-suma mt-2" id="sumaAlicuota"></div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Procesadora</th>
                                <th>Concepto</th>
                                <th class="text-end" style="width: 130px;">Alícuota</th>
                                <th class="text-center" style="width: 150px;">Vigencia desde</th>
                                <th class="text-center" style="width: 130px;">Estado</th>
                                <th class="text-center" style="width: 110px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="bodyAlicuotas"></tbody>
                    </table>
                </div>
            </div>

            <div class="card-body border-top">
                <div class="param-hint">
                    <strong>Guardar una alícuota recalcula los movimientos pendientes</strong> de
                    esa procesadora —los que tienen fecha de acreditación de hoy en adelante— y el
                    mensaje dice cuántos cambiaron y por cuánta plata. Los movimientos
                    <strong>ya acreditados no se tocan</strong>: su plata entró con la tasa que
                    entró, y recalcularlos sería reescribir la historia.
                </div>
            </div>
        </div>

    </div><!-- /wrapperParamCobel -->
</div><!-- /pce-cobel -->
