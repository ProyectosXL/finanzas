<?php $tabName = 'Cashflow'; ?>
<link rel="stylesheet" href="Css/Cashflow.css?v=<?php echo time(); ?>">

<!--
    Tablero de consolidación del Flujo de Fondos.

    Acá no hay ninguna fila ni sección escrita: la grilla entera se dibuja desde
    la configuración que devuelve getTablero. Este archivo sólo aporta el
    esqueleto y los rótulos fijos.

    OJO: ésta es la pestaña por defecto, así que index.php la incluye del lado
    del servidor ARRIBA de los <script> de jQuery y Bootstrap. Por eso nada del
    nivel superior de Js/Cashflow.js puede tocar jQuery, Bootstrap ni el DOM:
    todo va dentro de inicializar().
-->

<div class="tab-cashflow">

    <!-- Indicadores -->
    <div class="row g-3 mb-3" id="cfKpis" style="display: none;">
        <!-- El Disponible Inicial es el ÚNICO que no cambia con la vista: es con
             cuánto se arranca hoy, un hecho del presente y no del período que se
             elige mirar. Lleva un ícono de ancla y el pie lo aclara. -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card cf-kpi-fijo">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Disponible Inicial</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-anchor"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiApertura">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted">Hoy, no varía con la vista</span>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Ingresos</span>
                    <div class="kpi-card-icon green"><i class="fas fa-arrow-trend-up"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiIngresos">$ 0,00</div>
                <div class="kpi-card-footer"><span class="cf-kpi-periodo"></span></div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Egresos</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-arrow-trend-down"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiEgresos">$ 0,00</div>
                <div class="kpi-card-footer"><span class="cf-kpi-periodo"></span></div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Flujo Neto</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-scale-balanced"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiFlujo">$ 0,00</div>
                <!-- El Flujo Neto mide lo que el negocio genera, así que NO
                     incluye la cobertura aplicada; el Saldo Final sí. Cuando hay
                     cobertura en el período, este pie lo dice: sin eso, las dos
                     tarjetas parecen no cerrar entre sí. -->
                <div class="kpi-card-footer">
                    <span class="cf-kpi-periodo"></span><span id="cfKpiFlujoCobertura"></span>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Saldo Final</span>
                    <div class="kpi-card-icon green"><i class="fas fa-flag-checkered"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiCierre">$ 0,00</div>
                <div class="kpi-card-footer"><span class="text-muted" id="cfKpiCierreCuando">&nbsp;</span></div>
            </div>
        </div>

        <!-- El peor saldo proyectado y cuándo: es el dato por el que se mira un
             tablero de tesorería. -->
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card" id="cfKpiMinimoCard">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Saldo Mínimo</span>
                    <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card-value" id="cfKpiMinimo">$ 0,00</div>
                <div class="kpi-card-footer"><span class="text-muted" id="cfKpiMinimoCuando">&nbsp;</span></div>
            </div>
        </div>
    </div>

    <!-- Qué período están midiendo los indicadores. Cambia con la vista, y es
         lo que evita leer un número creyendo que cubre otro tramo. -->
    <div class="cf-periodo-activo mb-3" id="cfPeriodoActivo" style="display: none;"></div>

    <!-- Avisos del motor: módulos sin construir, importes fuera del horizonte,
         errores de configuración. -->
    <div id="cfAvisos"></div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Cashflow</h5>
                <small class="text-muted" id="cfSubtitulo">Consolidado de todos los módulos</small>
            </div>
            <div class="d-flex gap-2">
                <!-- Los tres botones los dibuja Js/eje-vistas.js, el mismo
                     componente que usan las pestañas de detalle: el tablero y la
                     pestaña que explica una de sus filas no pueden ofrecer
                     vistas distintas ni medir períodos distintos. -->
                <div id="cfVistas"></div>
                <!-- Abre o cierra TODOS los conceptos con parte real y
                     proyectada. Arranca escondido y lo muestra Js/Cashflow.js
                     sólo si hay alguno dibujado: un botón que no hace nada se
                     aprieta y parece que falló. El rótulo también lo pone el
                     JS, porque depende de si queda alguno cerrado. -->
                <button id="cfBtnGrupos" class="btn btn-sm btn-outline-secondary"
                        style="display: none;">
                    <i class="fas fa-angles-down me-1"></i> Expandir todo
                </button>
                <button id="cfBtnRefresh" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <button id="cfBtnExport" class="btn btn-sm btn-success">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="cfLoading">
                <div class="spinner"></div>
                <p>Consolidando los módulos...</p>
            </div>

            <div class="table-responsive tabla-temporal" id="cfWrapper" style="display: none;">
                <table id="cfTabla" class="table table-hover mb-0">
                    <thead>
                        <tr id="cfHeaderTop"></tr>
                        <tr id="cfHeaderSub"></tr>
                    </thead>
                    <tbody id="cfBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Cashflow.js?v=<?php echo time(); ?>"></script>
