<?php $tabName = 'Llaves y Renov. Contratos'; ?>
<link rel="stylesheet" href="Css/Llaves.css?v=<?php echo time(); ?>">
<?php
/* Sin permiso de edicion de la pestaña, el boton "Ya pagada" de Contratos no
   se dibuja. Lo demas se edita en Parametros > Llaves y Renovaciones, con su
   permiso. */
$edita = AuthCashflow::puedeEditar('llaves_renov');
?>
<!--
    RRHH y Operativos › Llaves y Renov. Contratos. Cuánto se paga de llave,
    de otros gastos de cada renovación de contrato, de obras de imagen y de
    aperturas, y cuándo. Reemplaza las hojas «LLves y Renov Contratos» y
    «Detalles de vencimientos» del Excel de Tesorería.

    Los contratos salen de la app de alquileres; el alquiler con el que se
    estima la llave, de la pestaña Alquileres; lo demás, de Parámetros › Llaves
    y Renovaciones. Las facturas reales se marcan como llave en Alquileres ›
    Facturas. Ver README-llaves-renovaciones.md.
-->
<div class="tab-llaves_renov"<?php echo AuthCashflow::atributoEdicion('llaves_renov'); ?>>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <small class="text-muted">
            Los contratos (vigencia, llave, comisiones, FPC) salen de la app de alquileres; el alquiler con el
            que se estima la llave, de la pestaña Alquileres; los porcentajes, las obras y lo pactado, de
            <a href="#" class="ll-ir-parametros">Parámetros › Llaves y Renovaciones</a>.
        </small>
        <button id="btnRefreshLl" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <div id="avisosLlaves"></div>

    <div data-kpis="kpisLlaves" class="row g-3 mb-3" id="kpisLlaves" style="display: none;">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total del horizonte</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-key"></i></div>
                </div>
                <div class="kpi-card-value" id="llKpiTotal">$ 0</div>
                <div class="kpi-card-footer"><span class="text-muted">Llaves, renovaciones, obras, aperturas y compromisos</span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Renovaciones en el horizonte</span>
                    <div class="kpi-card-icon green"><i class="fas fa-file-signature"></i></div>
                </div>
                <div class="kpi-card-value" id="llKpiRen">0</div>
                <div class="kpi-card-footer"><span class="text-muted" id="llKpiRenPie"></span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Estimado</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-wand-magic-sparkles"></i></div>
                </div>
                <div class="kpi-card-value" id="llKpiEst">$ 0</div>
                <div class="kpi-card-footer"><span class="text-muted" id="llKpiEstPie"></span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Locales sin dato</span>
                    <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card-value" id="llKpiSinDato">0</div>
                <div class="kpi-card-footer"><span class="text-muted" id="llKpiSinDatoPie">No proyectan algo por un dato que falta</span></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="llavesTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabLlProyBtn" data-bs-toggle="tab" data-bs-target="#paneLlProy" type="button" role="tab">
                <i class="fas fa-calendar-alt me-1"></i> Proyección
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabLlContBtn" data-bs-toggle="tab" data-bs-target="#paneLlCont" type="button" role="tab">
                <i class="fas fa-file-contract me-1"></i> Contratos
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabLlFactBtn" data-bs-toggle="tab" data-bs-target="#paneLlFact" type="button" role="tab">
                <i class="fas fa-file-invoice me-1"></i> Facturas
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ============================================================
         1 — PROYECCIÓN: operador → local → concepto, y aparte las
         aperturas y los compromisos a mano.
         ============================================================ -->
    <div class="tab-pane fade show active" id="paneLlProy" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-0">Proyección por operador</h5>
                        <small class="text-muted">
                            Cada operador se abre en sus locales y cada local en llave, otros gastos y obra. Lo
                            <em class="ll-estimado-muestra">estimado</em> va en itálica y atenuado. Pasá el mouse por
                            un importe para ver de dónde sale y la cuenta.
                        </small>
                    </div>
                    <div class="search-box-container">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="busquedaLl" class="form-control border-start-0 ps-0"
                                   placeholder="Buscar operador o local…" style="min-width: 200px;">
                        </div>
                    </div>
                    <div id="vistasLl"></div>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <div id="colFijasLl"></div>
                    <button id="btnExpandirLl" class="btn btn-sm btn-outline-secondary" title="Abrir o cerrar todo">
                        <i class="fas fa-up-down me-1"></i> Abrir todo
                    </button>
                    <button id="btnExportarLl" class="btn btn-sm btn-success" title="Exportar a Excel con todo abierto">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body py-2 border-bottom">
                <small class="text-muted" id="periodoLl"></small>
                <span class="ms-2 small" id="leyendaLl"></span>
            </div>
            <div class="card-body p-0">
                <div class="cargando-slot" id="llSpinner" data-cargando="Proyectando llaves y renovaciones…"></div>
                <!-- .tabla-temporal: el encabezado y el pie quedan fijos, y la columna
                     «Operador · local · concepto» (col-fija-1, Js/columnas-fijas.js) queda
                     fija al scrollear a lo ancho, como en Alquileres. -->
                <div class="table-wrapper table-responsive tabla-temporal" id="llWrapper" style="display: none;">
                    <!-- data-orden="no": cada fila lleva sus hijas abajo, y ordenar las separaría. -->
                    <table id="tablaLlaves" class="table table-hover table-sm mb-0" data-orden="no">
                        <thead id="llThead"></thead>
                        <tbody id="llBody"></tbody>
                        <tfoot class="table-light" id="llTfoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         2 — CONTRATOS: lo que hoy es la hoja «Detalles de vencimientos».
         ============================================================ -->
    <div class="tab-pane fade" id="paneLlCont" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Contratos por local</h5>
                    <small class="text-muted">El contrato vigente de cada local de la app de alquileres, si tiene la
                        renovación cargada y qué se proyecta de ella.</small>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
                    <select id="llContEstado" class="form-select form-select-sm" style="width: auto;"></select>
                    <button class="btn btn-sm btn-success" data-exportar="tablaLlContratos" data-exportar-nombre="Llaves_Contratos"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaLlContratos" class="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Local</th><th>Operador</th><th>Estado</th><th>Desde</th><th>Hasta</th>
                                <th class="text-end">Meses</th><th class="text-end">Llave</th><th class="text-end">Comisiones</th>
                                <th class="text-end">FPC</th><th>Renovación</th><th class="text-end">m²</th><th>Segmento</th>
                                <th data-orden="no" data-exportar-omitir></th>
                            </tr>
                        </thead>
                        <tbody id="llContBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         3 — FACTURAS: las marcadas como llave en Alquileres › Facturas.
         ============================================================ -->
    <div class="tab-pane fade" id="paneLlFact" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Facturas marcadas como llave o renovación</h5>
                <small class="text-muted">Se marcan y se deshacen en Alquileres › Facturas. Cada una reemplaza todo lo
                    proyectado de su local y su concepto en esa renovación; una ya pagada no suma.</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaLlFacturas" class="table table-sm table-hover mb-0 align-middle">
                        <thead><tr><th>Comprobante</th><th>Local</th><th>Concepto</th><th>Mes</th><th>Estado</th>
                            <th class="text-end">A pagar en el horizonte</th><th>Pagos</th></tr></thead>
                        <tbody id="llFactBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    </div>

    <!-- YA PAGADA: la llave o los otros gastos de una renovación que ya salieron
         de caja. Corta lo proyectado de ese concepto en esa renovación. -->
    <?php if ($edita): ?>
    <div class="modal fade" id="modalLlPagada" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ya pagada · <span id="llPagLocal"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label small mb-0" for="llPagRen">Renovación</label>
                            <select id="llPagRen" class="form-select form-select-sm"></select></div>
                        <!-- Los dos conceptos tildados por defecto: lo normal es marcar la
                             renovación entera, y marcar sólo la llave deja los otros
                             gastos proyectados sin que nadie lo note. -->
                        <div class="col-12"><span class="form-label small mb-0 d-block">Qué se pagó</span>
                            <div class="row g-2 align-items-center">
                                <div class="col-5"><div class="form-check mb-0">
                                    <input class="form-check-input ll-pag-concepto" type="checkbox" id="llPagLlave" value="LLAVE" checked>
                                    <label class="form-check-label small" for="llPagLlave">Llave</label></div></div>
                                <div class="col-7"><input type="text" id="llPagMontoLLAVE" class="form-control form-control-sm" inputmode="decimal" placeholder="Monto ($)"></div>
                                <div class="col-5"><div class="form-check mb-0">
                                    <input class="form-check-input ll-pag-concepto" type="checkbox" id="llPagOtros" value="OTROS" checked>
                                    <label class="form-check-label small" for="llPagOtros">Otros gastos</label></div></div>
                                <div class="col-7"><input type="text" id="llPagMontoOTROS" class="form-control form-control-sm" inputmode="decimal" placeholder="Monto ($)"></div>
                            </div></div>
                        <div class="col-md-6"><label class="form-label small mb-0" for="llPagFecha">Fecha del pago</label>
                            <input type="date" id="llPagFecha" class="form-control form-control-sm"></div>
                        <div class="col-md-6"><label class="form-label small mb-0" for="llPagComp">Comprobante</label>
                            <input type="text" id="llPagComp" class="form-control form-control-sm" maxlength="40" placeholder="opcional"></div>
                        <div class="col-12"><input type="text" id="llPagObs" class="form-control form-control-sm" maxlength="300" placeholder="Observación (opcional)"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnLlPagGuardar">Marcar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="Js/Llaves.js?v=<?php echo time(); ?>"></script>
