<?php $tabName = 'Proveedores Locales'; ?>
<link rel="stylesheet" href="Css/Proveedores-Proveedores_locales.css?v=<?php echo time(); ?>">

<!--
    Proveedores Locales → Cuentas a Pagar.

    Las cuentas a pagar salen de Tango y no se editan acá: lo que se carga es
    CUÁNDO se piensa pagar cada comprobante. Esa fecha es lo único que disuelve
    los importes vencidos apilados en el primer día del eje.

    LAS TRES SUB-SOLAPAS son tres momentos distintos del mismo circuito:
      Cuentas a Pagar  el listado, con la fecha editable celda por celda
      Importar         las dos planillas, con previsualización del diff
      Maestro          qué es cada proveedor, y qué proveedores faltan

    SE LLAMA "CUENTAS A PAGAR" Y NO "PAGOS REALES". Lo que se carga es una
    PREVISIÓN; lo real lo dice Tango cuando el comprobante se cancela, y eso lo
    resuelve la conciliación. Un rótulo que dijera "reales" prometería un hecho
    donde hay un plan.
-->
<div class="tab-proveedores_locales">

    <div id="avisosProv"></div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4" id="summaryProv" style="display: none;">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Total a pagar</span>
                    <div class="kpi-card-icon blue"><i class="fas fa-file-invoice-dollar"></i></div>
                </div>
                <div class="kpi-card-value" id="totalProv">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleTotalProv">0 vencimientos</span>
                </div>
            </div>
        </div>

        <!-- EL INDICADOR QUE IMPORTA. Es el importe que está apilado en el
             primer día del eje por no tener fecha, y el único número que dice
             cuánto trabajo queda por hacer en esta pantalla. Va en rojo a
             propósito. -->
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card prov-kpi-pendiente" id="cardVencidoProv">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Vencido sin fecha</span>
                    <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card-value" id="vencidoProv">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleVencidoProv">Se dibuja hoy, no se paga hoy</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Con fecha cargada</span>
                    <div class="kpi-card-icon green"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="kpi-card-value" id="conFechaProv">$ 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleConFechaProv">0 comprobantes</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Proveedores</span>
                    <div class="kpi-card-icon orange"><i class="fas fa-store"></i></div>
                </div>
                <div class="kpi-card-value" id="proveedoresProv">0</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="detalleProveedoresProv">con deuda pendiente</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header prov-subtabs pt-2 pb-0 px-3">
            <ul class="nav nav-tabs card-header-tabs mb-0" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="btnVistaCuentasProv" type="button" role="tab">
                        <i class="fas fa-list me-1"></i> Cuentas a Pagar
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="btnVistaImportarProv" type="button" role="tab">
                        <i class="fas fa-file-import me-1"></i> Importar
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="btnVistaMaestroProv" type="button" role="tab">
                        <i class="fas fa-address-book me-1"></i> Maestro
                    </button>
                </li>
            </ul>
        </div>

        <!-- ============================================================
             CUENTAS A PAGAR
             ============================================================ -->
        <div id="vistaCuentasProv">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <h5 class="mb-0">Cuentas a Pagar Locales</h5>
                        <small class="text-muted">
                            Sale de Tango. Excluye a los proveedores del exterior, que entran
                            por Proveedores Exterior.
                        </small>
                    </div>
                    <div class="search-box-container ms-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="busquedaProv" class="form-control border-start-0 ps-0"
                                   placeholder="Buscar proveedor o comprobante..." style="min-width: 230px;">
                        </div>
                    </div>

                    <!-- Filtro rápido: lo vencido sin fecha es lo que hay que
                         trabajar, así que se puede aislar de un clic. -->
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="soloVencidosProv">
                        <label class="form-check-label small text-muted" for="soloVencidosProv">
                            Sólo vencidos sin fecha
                        </label>
                    </div>

                    <!-- LA GRILLA ABRE FILTRADA en lo que se paga por echeq o
                         transferencia, que es lo que se gestiona desde el
                         cronograma de pagos.

                         El interruptor está PRENDIDO por defecto y se puede
                         apagar: lo que queda afuera —débitos automáticos, caja,
                         tarjeta corporativa— es deuda real que igual sale, así
                         que tiene que haber una pantalla donde mirarla. Cuánto
                         es se dice al lado, siempre. -->
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="soloCronogramaProv" checked>
                        <label class="form-check-label small text-muted" for="soloCronogramaProv">
                            Sólo echeq y transferencia
                        </label>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <div id="vistasProv"></div>
                    <div id="colFijasProv"></div>
                    <button id="btnConciliarProv" class="btn btn-sm btn-outline-secondary"
                            title="Comparar contra Tango: qué de lo previsto ya se pagó">
                        <i class="fas fa-code-compare me-1"></i> Conciliar
                    </button>
                    <button id="btnRefreshProv" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnExportProv" class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>

            <div class="card-body py-2 border-bottom bg-light bg-opacity-50">
                <small class="text-muted" id="periodoProv"></small>
                <!-- Cuánto queda fuera del filtro, desglosado por forma de pago.
                     Un filtro que esconde plata sin decir cuánta es un filtro
                     que miente. -->
                <small class="text-muted ms-2" id="fueraFiltroProv"></small>
            </div>

            <div class="card-body p-0">
                <div class="loading-spinner" id="loadingProv">
                    <div class="spinner"></div>
                    <p>Cargando cuentas a pagar...</p>
                </div>

                <div class="table-wrapper table-responsive tabla-temporal" id="wrapperProv"
                     style="display: none;">
                    <table id="tablaProveedores" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2">PROVEEDOR</th>
                                <th rowspan="2" class="col-texto">RAZON SOCIAL</th>
                                <th rowspan="2">RUBRO</th>
                                <th rowspan="2">T_COMP</th>
                                <th rowspan="2">N_COMP</th>
                                <th rowspan="2">EMISION</th>
                                <th rowspan="2">VTO</th>
                                <th rowspan="2">Pendiente</th>
                                <th rowspan="2">Fecha de pago</th>
                                <th rowspan="2">Forma</th>
                                <th colspan="1" class="table-group-divider" id="headerEjeProv">Días</th>
                            </tr>
                            <tr id="headerSubProv"></tr>
                        </thead>
                        <tbody id="bodyProv"></tbody>
                        <tfoot class="table-light">
                            <tr id="totalesProv">
                                <td colspan="10" class="fw-bold text-end">TOTALES</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================================
             IMPORTAR
             ============================================================ -->
        <div id="vistaImportarProv" style="display: none;">
            <div class="card-body">
                <!-- ES CSV Y NO .xlsx, Y HAY QUE DECIRLO ACÁ. El motivo está en
                     Class/Planilla.php: leer un .xlsx necesita la extensión zip
                     de PHP, que en este servidor está instalada pero no
                     habilitada. Si el usuario no se entera acá, se entera
                     cuando falla. -->
                <div class="alert alert-info d-flex align-items-start" role="alert">
                    <i class="fas fa-circle-info me-2 mt-1"></i>
                    <div>
                        <strong>El archivo tiene que ser CSV, no .xlsx.</strong>
                        Abrí la planilla en Excel y guardala como
                        <em>Archivo → Guardar como → CSV UTF-8</em>. El contenido es el mismo.
                        Si subís un .xlsx, el sistema lo detecta y te lo dice, pero no puede
                        leerlo: este servidor no tiene habilitada la extensión que hace falta.
                        <br>
                        <span class="text-muted small">
                            Nada se guarda hasta que confirmes: primero vas a ver exactamente
                            qué cambiaría.
                        </span>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0">Fechas de pago</h6>
                                <small class="text-muted">
                                    Cód. proveedor, número de factura, fecha y forma de pago.
                                    Es lo que disuelve lo vencido sin fecha.
                                </small>
                            </div>
                            <div class="card-body">
                                <a href="Controller/ProveedoresController.php?action=plantillaPagos"
                                   class="btn btn-sm btn-outline-secondary mb-3">
                                    <i class="fas fa-download me-1"></i> Descargar plantilla
                                </a>
                                <input type="file" id="archivoPagosProv" accept=".csv,text/csv"
                                       class="form-control form-control-sm mb-2">
                                <button id="btnPreviewPagosProv" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i> Ver qué cambiaría
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0">Maestro de proveedores</h6>
                                <small class="text-muted">
                                    La hoja <em>Maestro proveedores</em> del Cronograma de Pagos.
                                    Es lo que permite abrir la deuda por rubro.
                                </small>
                            </div>
                            <div class="card-body">
                                <a href="Controller/ProveedoresController.php?action=plantillaMaestro"
                                   class="btn btn-sm btn-outline-secondary mb-3">
                                    <i class="fas fa-download me-1"></i> Descargar plantilla
                                </a>
                                <input type="file" id="archivoMaestroProv" accept=".csv,text/csv"
                                       class="form-control form-control-sm mb-2">
                                <button id="btnPreviewMaestroProv" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i> Ver qué cambiaría
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- La previsualización: qué se carga, qué cambia, qué no entra
                     y por qué. Nada se graba hasta confirmar acá. -->
                <div id="previewProv" class="mt-4" style="display: none;"></div>
            </div>
        </div>

        <!-- ============================================================
             MAESTRO
             ============================================================ -->
        <div id="vistaMaestroProv" style="display: none;">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="mb-0">Maestro de proveedores</h5>
                    <small class="text-muted">
                        Copia de la planilla que mantiene administración. La fuente sigue
                        siendo el Excel: esto es una copia reimportable.
                    </small>
                </div>
                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaMaestroProv"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar código, nombre o rubro..." style="min-width: 240px;">
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <!-- EL CONTROL QUE EVITA QUE EL MAESTRO SE DESACTUALICE SIN QUE
                     NADIE SE ENTERE: proveedores con deuda que no están en la
                     planilla. -->
                <div id="faltantesProv" class="px-3 pt-3"></div>

                <div class="table-responsive" id="wrapperMaestroProv">
                    <table id="tablaMaestro" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>CODIGO</th>
                                <th class="col-texto">NOMBRE</th>
                                <th>RUBRO ECONOMICO</th>
                                <th>RUBRO</th>
                                <th>CENTRO COSTOS</th>
                                <th>FORMA DE PAGO</th>
                                <th>PLAZO</th>
                                <th class="text-center">Historial</th>
                            </tr>
                        </thead>
                        <tbody id="bodyMaestroProv"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/Proveedores-Proveedores_locales.js?v=<?php echo time(); ?>"></script>
