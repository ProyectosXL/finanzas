<?php $tabName = 'Alquileres'; ?>
<link rel="stylesheet" href="Css/Alquileres.css?v=<?php echo time(); ?>">
<!--
    RRHH y Operativos › Alquileres. Cuánto se paga de alquiler por local y
    cuándo, proyectado desde la app de alquileres de administración.

    SOLO LECTURA EN ESTA ENTREGA: lo que se edita (razones sociales, datos de
    cada local, extraordinarios) está en Parámetros › Alquileres, con su
    permiso. La raíz lleva igual el atributo de edición de la pestaña, para
    las escrituras de la sub-pestaña Facturas cuando llegue.

    TODAVÍA NO ENTRA AL TABLERO: las facturas de alquiler siguen sumando en
    Cuentas a Pagar Locales, y contar también la proyección las duplicaría.
    Ver README-alquileres.md.
-->
<div class="tab-alquileres"<?php echo AuthCashflow::atributoEdicion('alquileres'); ?>>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <small class="text-muted">
            Los importes por concepto, los porcentajes y la venta salen de la app de alquileres; la
            razón social, la frecuencia de ajuste y las alícuotas de cada local, de
            <a href="#" class="alq-ir-parametros">Parámetros › Alquileres</a>.
        </small>
        <button id="btnRefreshAlq" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <div id="avisosAlquileres"></div>

    <!-- Las cuatro tarjetas: cuánto en el horizonte, cuánto este mes, cuántos
         locales proyectan y cuántos no (con el link al filtro Incompletos). -->
    <div data-kpis="kpisAlquileres" class="row g-3 mb-3" id="kpisAlquileres" style="display: none;">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">A pagar en el horizonte</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-building"></i></div>
                </div>
                <div class="kpi-card-value" id="alqKpiTotal">$ 0,00</div>
                <div class="kpi-card-footer"><span class="text-muted">Neto + IVA + percepción − retención</span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Mes en curso</span>
                    <div class="kpi-card-icon green"><i class="fas fa-calendar-day"></i></div>
                </div>
                <div class="kpi-card-value" id="alqKpiMes">$ 0,00</div>
                <div class="kpi-card-footer"><span class="text-muted" id="alqKpiMesPie"></span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Locales que proyectan</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-store"></i></div>
                </div>
                <div class="kpi-card-value" id="alqKpiProyectan">0</div>
                <div class="kpi-card-footer"><span class="text-muted">Con todos sus datos cargados</span></div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Locales incompletos</span>
                    <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card-value" id="alqKpiIncompletos">0</div>
                <div class="kpi-card-footer">
                    <a href="#" class="alq-ir-parametros" data-filtro="INCOMPLETOS">Completarlos en Parámetros</a>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="alquileresTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabAlqProyBtn" data-bs-toggle="tab"
                    data-bs-target="#paneAlqProy" type="button" role="tab">
                <i class="fas fa-calendar-alt me-1"></i> Proyección
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabAlqVentasBtn" data-bs-toggle="tab"
                    data-bs-target="#paneAlqVentas" type="button" role="tab">
                <i class="fas fa-chart-line me-1"></i> Ventas
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabAlqFactBtn" data-bs-toggle="tab"
                    data-bs-target="#paneAlqFact" type="button" role="tab">
                <i class="fas fa-file-invoice me-1"></i> Facturas
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ============================================================
         1 — PROYECCIÓN
         Un renglón por local con lo que se paga cada mes. Se abre en sus
         grupos y cada grupo en sus conceptos. El mes es el MES DE PAGO: un
         variable diferido aparece en el mes en que se paga.
         ============================================================ -->
    <div class="tab-pane fade show active" id="paneAlqProy" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-0">Proyección por local</h5>
                        <small class="text-muted">
                            El renglón del local es el <strong>a pagar</strong>; grupos y conceptos, el
                            <strong>neto</strong>. Pasá el mouse por un importe para ver el desglose.
                        </small>
                    </div>
                    <div class="search-box-container">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="busquedaAlq" class="form-control border-start-0 ps-0"
                                   placeholder="Buscar local o razón social…" style="min-width: 220px;">
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <div id="colFijasAlq"></div>
                    <button id="btnExpandirAlq" class="btn btn-sm btn-outline-secondary" title="Abrir o cerrar todos los locales">
                        <i class="fas fa-up-down me-1"></i> Abrir todo
                    </button>
                    <!-- No es data-exportar: antes de exportar abre todas las
                         filas, porque tabla-export.js baja sólo lo que se ve. -->
                    <button id="btnExportarAlq" class="btn btn-sm btn-success"
                            title="Exportar a Excel con todos los locales, grupos y conceptos abiertos">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="cargando-slot" id="alqSpinner" data-cargando="Proyectando los alquileres de los locales…"></div>
                <div class="table-wrapper table-responsive tabla-temporal" id="alqWrapper" style="display: none;">
                    <table id="tablaAlquileres" class="table table-hover mb-0">
                        <thead id="alqThead"></thead>
                        <tbody id="alqBody"></tbody>
                        <tfoot class="table-light" id="alqTfoot"></tfoot>
                    </table>
                </div>
            </div>
            <div class="card-body py-2 border-top">
                <small class="text-muted">
                    <strong>De dónde sale cada importe</strong>, de mayor a menor prioridad: un mes
                    <em>cerrado en la app</em> se toma tal cual; si no está cerrado pero ya tiene
                    <em>venta real</em>, el cashflow calcula los conceptos con esa venta; si no, todo es
                    <em>estimado</em>. Un período abierto en la app nunca se lee. Los pagos que ya
                    vencieron en el mes en curso y todavía no tienen factura van al siguiente día hábil
                    desde hoy.
                </small>
            </div>
        </div>

        <!-- Los cerrados en Lakers no proyectan y no son incompletos: van aparte. -->
        <div class="card mb-4" id="alqCerradosCard" style="display: none;">
            <div class="card-body py-2">
                <small class="text-muted"><strong>Cerrados en Lakers</strong> (no proyectan):
                    <span id="alqCerrados"></span>. Su venta real se sigue viendo en la sub-pestaña Ventas.</small>
            </div>
        </div>
    </div>

    <!-- ============================================================
         2 — VENTAS
         La venta de cada local con la que se calculan los variables, con su
         origen. Incluye los meses anteriores al horizonte que pagan los
         variables diferidos.
         ============================================================ -->
    <div class="tab-pane fade" id="paneAlqVentas" role="tabpanel">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Venta por local</h5>
                    <small class="text-muted">
                        <span class="badge alq-origen-REAL">Real</span> de la app ·
                        <span class="badge alq-origen-ESTIMADA">Estimada</span> la venta del canal Locales repartida por participación ·
                        <span class="badge alq-origen-MANUAL">Manual</span> la de Parámetros, para locales nuevos.
                        <span id="alqVentasPeriodo"></span>
                    </small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="alqVentaTipo" id="alqVentaNeta" value="neta" checked>
                        <label class="btn btn-outline-secondary" for="alqVentaNeta">Neta</label>
                        <input type="radio" class="btn-check" name="alqVentaTipo" id="alqVentaBruta" value="bruta">
                        <label class="btn btn-outline-secondary" for="alqVentaBruta">Bruta</label>
                    </div>
                    <div id="colFijasAlqVentas"></div>
                    <button class="btn btn-sm btn-success" data-exportar="tablaAlqVentas"
                            data-exportar-nombre="Alquileres_Ventas" title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-wrapper table-responsive tabla-temporal" id="alqVentasWrapper">
                    <table id="tablaAlqVentas" class="table table-hover table-sm mb-0">
                        <thead id="alqVentasThead"></thead>
                        <tbody id="alqVentasBody"></tbody>
                        <tfoot class="table-light" id="alqVentasTfoot"></tfoot>
                    </table>
                </div>
            </div>
            <div class="card-body py-2 border-top">
                <small class="text-muted">
                    <strong>El pie compara contra la pestaña Ventas.</strong> La venta del canal Locales es
                    la del <strong>mes completo</strong> (la base mensual por la participación mensual del
                    canal): los porcentajes del alquiler son sobre la venta del mes entero. Por eso, en el
                    mes en curso, es más que la que muestra la grilla diaria de Ventas, que arranca hoy. En
                    los meses estimados la suma de los locales (estimadas + manuales) da la del canal y la
                    diferencia es 0; en los meses con venta real no hay venta del canal con qué comparar.
                    La neta es la bruta dividida por 1 + el IVA de Parámetros › Generales.
                </small>
            </div>
        </div>
    </div>

    <!-- ============================================================
         3 — FACTURAS
         ============================================================ -->
    <div class="tab-pane fade" id="paneAlqFact" role="tabpanel">
        <div class="card mb-4">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-file-invoice fa-2x mb-3 d-block"></i>
                Disponible en la próxima entrega.
            </div>
        </div>
    </div>

    </div>
</div>

<script src="Js/Alquileres.js?v=<?php echo time(); ?>"></script>
