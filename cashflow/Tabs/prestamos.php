<?php $tabName = 'Préstamos'; ?>
<link rel="stylesheet" href="Css/Prestamos.css?v=<?php echo time(); ?>">
<?php
/* Sin permiso de edicion de la pestaña no se dibuja ningun control de
   escritura: ni el alta, ni editar, ni corregir cuotas, ni cancelar, ni
   simular. */
$edita = AuthCashflow::puedeEditar('prestamos');
?>
<!--
    Financiero › Préstamos. Los préstamos bancarios en pesos o en dólares,
    vigentes y simulados: su cronograma, su proyección en el cashflow, la
    cancelación anticipada y cuánto haría falta pedir. Los valores generales
    (costos, comisión, tasas) están en Parámetros › Préstamos. Ver
    README-prestamos.md.
-->
<div class="tab-prestamos"<?php echo AuthCashflow::atributoEdicion('prestamos'); ?>>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <small class="text-muted">
            Los costos, la comisión de cancelación y las tasas de referencia por defecto están en
            <a href="#" class="pr-ir-parametros">Parámetros › Préstamos</a>. Un préstamo simulado no suma en el tablero oficial.
        </small>
        <button id="btnRefreshPr" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <div id="avisosPrestamos"></div>
    <div class="cargando-slot" id="prCargando" data-cargando="Calculando los préstamos…"></div>

    <div data-kpis="kpisPrestamos" class="row g-3 mb-3" id="kpisPrestamos" style="display: none;">
        <div class="col-md-6 col-xl">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Deuda vigente</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-building-columns"></i></div>
                </div>
                <div class="kpi-card-value" id="prKpiDeuda">$ 0</div>
                <div class="kpi-card-footer"><span class="text-muted" id="prKpiDeudaPie">Saldo de capital a hoy, en pesos</span></div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Cuotas del horizonte</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-calendar-days"></i></div>
                </div>
                <div class="kpi-card-value" id="prKpiCuotas">$ 0</div>
                <div class="kpi-card-footer"><span class="text-muted">Capital, intereses y gastos de los vigentes</span></div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Próxima cuota</span>
                    <div class="kpi-card-icon green"><i class="fas fa-clock"></i></div>
                </div>
                <div class="kpi-card-value" id="prKpiProxima">—</div>
                <div class="kpi-card-footer"><span class="text-muted" id="prKpiProximaPie"></span></div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Necesidad de financiamiento</span>
                    <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card-value" id="prKpiNecesidad">—</div>
                <div class="kpi-card-footer"><span class="text-muted" id="prKpiNecesidadPie">Se calcula a pedido, en Simulador</span></div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Préstamos simulados</span>
                    <div class="kpi-card-icon purple"><i class="fas fa-flask"></i></div>
                </div>
                <div class="kpi-card-value" id="prKpiSimulados">0</div>
                <div class="kpi-card-footer"><span class="text-muted" id="prKpiSimuladosPie">No suman en el tablero oficial</span></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="prestamosTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabPrListaBtn" data-bs-toggle="tab" data-bs-target="#panePrLista" type="button" role="tab">
                <i class="fas fa-list me-1"></i> Préstamos
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabPrCronoBtn" data-bs-toggle="tab" data-bs-target="#panePrCrono" type="button" role="tab">
                <i class="fas fa-table-list me-1"></i> Cronograma
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabPrProyBtn" data-bs-toggle="tab" data-bs-target="#panePrProy" type="button" role="tab">
                <i class="fas fa-calendar-alt me-1"></i> Proyección
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabPrSimBtn" data-bs-toggle="tab" data-bs-target="#panePrSim" type="button" role="tab">
                <i class="fas fa-flask me-1"></i> Simulador
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ============================================================
         1 — PRÉSTAMOS: la lista, con sus acciones.
         ============================================================ -->
    <div class="tab-pane fade show active" id="panePrLista" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Préstamos</h5>
                    <small class="text-muted">Vigentes, simulados, cancelados (por cancelación total anticipada) y finalizados
                        (ya se pagó la última cuota).</small>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                    <select id="prFiltroEstado" class="form-select form-select-sm" style="width: auto;"></select>
                    <button class="btn btn-sm btn-success" data-exportar="tablaPrLista" data-exportar-nombre="Prestamos"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <?php if ($edita): ?>
                    <button id="btnPrNuevo" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Nuevo préstamo</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaPrLista" class="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Préstamo</th><th>Banco</th><th>Moneda</th><th class="text-end">Capital</th><th>Tasa</th>
                                <th>Sistema</th><th class="text-end">Cuotas</th><th>Estado</th><th class="text-end">Saldo a hoy</th>
                                <th>Próxima cuota</th><th class="text-end">TEA</th><th class="text-end">CFT</th>
                                <th data-orden="no" data-exportar-omitir></th>
                            </tr>
                        </thead>
                        <tbody id="prListaBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         2 — CRONOGRAMA del préstamo elegido, con las correcciones.
         ============================================================ -->
    <div class="tab-pane fade" id="panePrCrono" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h5 class="mb-0">Cronograma</h5>
                    <select id="prCronoSel" class="form-select form-select-sm" style="width: auto; min-width: 240px;"></select>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <?php if ($edita): ?>
                    <button id="btnPrCancelar" class="btn btn-sm btn-outline-danger"><i class="fas fa-hand-holding-dollar me-1"></i> Cancelación anticipada</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-success" data-exportar="tablaPrCrono" data-exportar-nombre="Prestamo_Cronograma"
                            title="Exportar a Excel">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body py-2 border-bottom small" id="prCronoInfo"></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaPrCrono" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                        <thead id="prCronoHead"></thead>
                        <tbody id="prCronoBody"></tbody>
                        <tfoot class="table-light" id="prCronoFoot"></tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer small text-muted" id="prCronoPie"></div>
        </div>
        <div class="card mb-4" id="prCancelacionesCard" style="display: none;">
            <div class="card-header"><h6 class="mb-0">Cancelaciones registradas</h6></div>
            <div class="card-body p-0">
                <table id="tablaPrCancelaciones" class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-end">Monto adelantado</th><th>Cómo sigue</th><th>Registrada</th><th></th></tr></thead>
                    <tbody id="prCancelacionesBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================
         3 — PROYECCIÓN con el eje del cashflow.
         ============================================================ -->
    <div class="tab-pane fade" id="panePrProy" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-0">Proyección por préstamo</h5>
                        <small class="text-muted">El desembolso entra en positivo; capital, intereses y gastos salen en negativo.
                            Lo anterior a hoy no se muestra: ya está en el saldo de los bancos.</small>
                    </div>
                    <div id="vistasPr"></div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="prProySim">
                        <label class="form-check-label small" for="prProySim">Incluir simulaciones</label>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <div id="colFijasPr"></div>
                    <button id="btnExpandirPr" class="btn btn-sm btn-outline-secondary" title="Abrir o cerrar todo">
                        <i class="fas fa-up-down me-1"></i> Abrir todo
                    </button>
                    <button id="btnExportarPr" class="btn btn-sm btn-success" title="Exportar a Excel con todo abierto">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body py-2 border-bottom">
                <small class="text-muted" id="periodoPr"></small>
                <span class="ms-2 small text-warning fw-semibold" id="prProySimAviso" style="display: none;">
                    <i class="fas fa-flask me-1"></i>Incluye préstamos simulados: no es el cashflow oficial
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-wrapper table-responsive" id="prProyWrapper">
                    <table id="tablaPrProy" class="table table-hover table-sm mb-0" data-orden="no">
                        <thead id="prProyHead"></thead>
                        <tbody id="prProyBody"></tbody>
                        <tfoot class="table-light" id="prProyFoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         4 — SIMULADOR: cuánto haría falta pedir, y con y sin un simulado.
         ============================================================ -->
    <div class="tab-pane fade" id="panePrSim" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Cuánto haría falta pedir</h5>
                    <small class="text-muted">Mira el saldo acumulado <strong>con cobertura</strong> del tablero oficial (sin
                        simulaciones) y busca el primer día en negativo y el mínimo del horizonte. Corre el tablero entero, así que
                        tarda: se calcula a pedido.</small>
                </div>
                <button id="btnPrNecesidad" class="btn btn-sm btn-primary"><i class="fas fa-calculator me-1"></i> Calcular</button>
            </div>
            <div class="card-body">
                <div class="cargando-slot" id="prSimCargando" data-cargando="Corriendo el tablero oficial…"></div>
                <div id="prNecesidad" class="small text-muted">Todavía no se calculó.</div>
                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="fas fa-circle-info me-1"></i>
                    El monto sugerido no considera las cuotas del propio préstamo, que pueden generar faltantes después. Para eso
                    está la comparación con y sin, abajo.
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Saldo del cashflow con y sin un préstamo simulado</h5>
                    <small class="text-muted">El saldo acumulado con cobertura al cierre de cada mes, sin el préstamo y con él.</small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <select id="prCompararSel" class="form-select form-select-sm" style="width: auto; min-width: 220px;"></select>
                    <button id="btnPrComparar" class="btn btn-sm btn-outline-primary"><i class="fas fa-scale-balanced me-1"></i> Comparar</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="cargando-slot" id="prCompCargando" data-cargando="Corriendo el tablero con y sin el préstamo…"></div>
                <div class="table-responsive">
                    <table id="tablaPrComparar" class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Mes</th><th class="text-end">Sin el préstamo</th><th class="text-end">Con el préstamo</th><th class="text-end">Diferencia</th></tr></thead>
                        <tbody id="prCompararBody"><tr><td colspan="4" class="text-muted small p-3">Elegí un préstamo simulado y tocá Comparar.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    </div>

    <?php if ($edita): ?>
    <!-- ALTA, EDICIÓN Y PASO A VIGENTE: el mismo formulario. -->
    <div class="modal fade" id="modalPrPrestamo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="prFormTitulo">Nuevo préstamo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label small mb-0" for="prFNombre">Nombre / descripción</label>
                            <input type="text" id="prFNombre" class="form-control form-control-sm" maxlength="150"></div>
                        <div class="col-md-6"><label class="form-label small mb-0" for="prFCuenta">Banco (cuenta del desembolso y de las cuotas)</label>
                            <select id="prFCuenta" class="form-select form-select-sm"></select></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFMoneda">Moneda</label>
                            <select id="prFMoneda" class="form-select form-select-sm"></select></div>
                        <div class="col-md-3"><label class="form-label small mb-0" for="prFCapital">Capital</label>
                            <input type="text" id="prFCapital" class="form-control form-control-sm" inputmode="decimal"></div>
                        <div class="col-md-3"><label class="form-label small mb-0" for="prFDesembolso">Fecha de desembolso</label>
                            <input type="date" id="prFDesembolso" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFEstado">Estado</label>
                            <select id="prFEstado" class="form-select form-select-sm">
                                <option value="SIMULADO">Simulado</option><option value="VIGENTE">Vigente</option>
                            </select></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFForma">Forma de pago</label>
                            <select id="prFForma" class="form-select form-select-sm"></select></div>

                        <div class="col-md-2"><label class="form-label small mb-0" for="prFTipoTasa">Tasa</label>
                            <select id="prFTipoTasa" class="form-select form-select-sm">
                                <option value="FIJA">Fija</option><option value="VARIABLE">Variable</option>
                            </select></div>
                        <div class="col-md-2 pr-fija"><label class="form-label small mb-0" for="prFTna">TNA (%)</label>
                            <input type="text" id="prFTna" class="form-control form-control-sm" inputmode="decimal"></div>
                        <div class="col-md-2 pr-variable"><label class="form-label small mb-0" for="prFReferencia">Referencia</label>
                            <select id="prFReferencia" class="form-select form-select-sm"></select></div>
                        <div class="col-md-2 pr-variable"><label class="form-label small mb-0" for="prFSpread">Spread (puntos)</label>
                            <input type="text" id="prFSpread" class="form-control form-control-sm" inputmode="decimal"></div>
                        <div class="col-md-4 pr-variable"><label class="form-label small mb-0" for="prFCriterio">Proyectar con</label>
                            <select id="prFCriterio" class="form-select form-select-sm"></select></div>
                        <div class="col-12 pr-variable small text-muted" id="prFTasaInfo"></div>

                        <div class="col-md-2"><label class="form-label small mb-0" for="prFSistema">Sistema</label>
                            <select id="prFSistema" class="form-select form-select-sm"></select></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFCuotas">Cuotas (de capital)</label>
                            <input type="number" id="prFCuotas" class="form-control form-control-sm" min="1" max="360"></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFFrecCap">Frecuencia de capital</label>
                            <select id="prFFrecCap" class="form-select form-select-sm"></select></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFFrecInt">Frecuencia de intereses</label>
                            <select id="prFFrecInt" class="form-select form-select-sm"></select></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFPrimera">Primera cuota</label>
                            <input type="date" id="prFPrimera" class="form-control form-control-sm" title="Vacío: desembolso + un período"></div>
                        <div class="col-md-2"><label class="form-label small mb-0" for="prFGracia">Períodos de gracia</label>
                            <input type="number" id="prFGracia" class="form-control form-control-sm" min="0"></div>
                        <div class="col-12 small text-muted" id="prFAyudaSistema"></div>

                        <div class="col-12 mt-2"><h6 class="mb-1">Costos asociados</h6>
                            <small class="text-muted d-block mb-1">Copiados de Parámetros: cambiá solo lo que este contrato tenga distinto.</small>
                            <table id="tablaPrFCostos" class="table table-sm align-middle mb-1">
                                <thead><tr><th>Costo</th><th style="width:100px">%</th><th>Base</th><th>Momento</th><th class="text-center">IVA</th><th></th></tr></thead>
                                <tbody id="prFCostos"></tbody>
                            </table>
                            <button type="button" id="prFCostoAgregar" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus me-1"></i> Agregar costo</button>
                        </div>

                        <div class="col-12 mt-2"><h6 class="mb-1">Comisión de cancelación anticipada</h6></div>
                        <div class="col-md-3"><select id="prFComModo" class="form-select form-select-sm"></select></div>
                        <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">Total</span>
                            <input type="text" id="prFComTotal" class="form-control" inputmode="decimal"><span class="input-group-text">%</span></div></div>
                        <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">Parcial</span>
                            <input type="text" id="prFComParcial" class="form-control" inputmode="decimal"><span class="input-group-text">%</span></div></div>
                        <div class="col-md-3 d-flex align-items-center"><div class="form-check">
                            <input type="checkbox" class="form-check-input" id="prFComIva"><label class="form-check-label small" for="prFComIva">Lleva IVA</label></div></div>
                        <div class="col-12" id="prFTramosBloque">
                            <table id="tablaPrFTramos" class="table table-sm align-middle mb-1" style="max-width: 560px">
                                <thead><tr><th>Desde (% del plazo)</th><th>% total</th><th>% parcial</th><th></th></tr></thead>
                                <tbody id="prFTramos"></tbody>
                            </table>
                            <button type="button" id="prFTramoAgregar" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus me-1"></i> Agregar tramo</button>
                        </div>

                        <div class="col-12 mt-2"><input type="text" id="prFObs" class="form-control form-control-sm" maxlength="300" placeholder="Observación (opcional)"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnPrGuardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- CORRECCIÓN DE UNA CUOTA: para que coincida con el cronograma del banco. -->
    <div class="modal fade" id="modalPrCuota" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Corregir la cuota <span id="prCuotaNro"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Escribí lo que dice el cronograma del banco. Lo corregido pisa lo calculado y queda
                        marcado; un campo que no cambiás no se guarda.</p>
                    <div class="row g-2" id="prCuotaCampos"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger me-auto" id="btnPrCuotaDeshacer">Deshacer todas las correcciones de la cuota</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnPrCuotaGuardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- CANCELACIÓN ANTICIPADA: simular antes de registrar. -->
    <div class="modal fade" id="modalPrCancelar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancelación anticipada · <span id="prCanNombre"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small" id="prCanUsd" style="display: none;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Precancelar un préstamo en dólares puede requerir cumplir la normativa cambiaria del BCRA. Verificalo con el banco antes de registrarla.
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3"><label class="form-label small mb-0" for="prCanTipo">Tipo</label>
                            <select id="prCanTipo" class="form-select form-select-sm"><option value="TOTAL">Total</option><option value="PARCIAL">Parcial</option></select></div>
                        <div class="col-md-3"><label class="form-label small mb-0" for="prCanFecha">Fecha</label>
                            <input type="date" id="prCanFecha" class="form-control form-control-sm"></div>
                        <div class="col-md-3 pr-can-parcial"><label class="form-label small mb-0" for="prCanMonto">Capital a adelantar</label>
                            <input type="text" id="prCanMonto" class="form-control form-control-sm" inputmode="decimal"></div>
                        <div class="col-md-3 pr-can-parcial"><label class="form-label small mb-0" for="prCanModo">Después</label>
                            <select id="prCanModo" class="form-select form-select-sm">
                                <option value="REDUCIR_CUOTA">Reducir la cuota (mismo plazo)</option>
                                <option value="REDUCIR_PLAZO">Reducir el plazo (misma cuota)</option>
                            </select></div>
                        <div class="col-12"><input type="text" id="prCanObs" class="form-control form-control-sm" maxlength="300" placeholder="Observación (opcional)"></div>
                        <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary" id="btnPrCanSimular">
                            <i class="fas fa-calculator me-1"></i> Simular</button></div>
                    </div>
                    <div id="prCanResultado" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-danger" id="btnPrCanRegistrar" disabled>Registrar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="Js/Prestamos.js?v=<?php echo time(); ?>"></script>
