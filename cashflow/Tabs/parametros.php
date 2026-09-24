<?php $tabName = 'Parámetros'; ?>
<link rel="stylesheet" href="Css/Parametros.css?v=<?php echo time(); ?>">

<div class="tab-parametros">

    <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
        <i class="fas fa-circle-info mt-1"></i>
        <div>
            <small>
                Los parámetros están agrupados por la pestaña que afectan.
                Ninguna de estas constantes está escrita en el código: cambiar un
                plazo de acreditación o la alícuota recalcula la proyección.
            </small>
        </div>
    </div>

    <div class="loading-spinner" id="loadingParametros">
        <div class="spinner"></div>
        <p>Cargando parámetros...</p>
    </div>

    <div id="wrapperParametros" style="display: none;">

        <!-- Avisos de configuración pendiente (migraciones sin correr) -->
        <div id="avisosParametros"></div>

        <!-- Sub-pestañas por módulo.
             La lista se genera desde Parametros::$modulos, así que PARA AGREGAR
             UN MÓDULO alcanza con declararlo ahí y sumar su tab-pane más abajo.
             getModulos() es estático y no toca la base. -->
        <?php
            require_once __DIR__ . '/../Class/Parametros.php';
            $modulosParam = Parametros::getModulos();
        ?>
        <ul class="nav nav-tabs mb-3" id="parametrosTabs" role="tablist">
            <?php foreach ($modulosParam as $i => $m): ?>
                <?php $slug = ucfirst(strtolower($m['codigo'])); ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $i === 0 ? 'active' : ''; ?>"
                            id="tabParam<?php echo $slug; ?>Btn" data-bs-toggle="tab"
                            data-bs-target="#paneParam<?php echo $slug; ?>" type="button" role="tab">
                        <i class="fas <?php echo htmlspecialchars($m['icono']); ?> me-1"></i>
                        <?php echo htmlspecialchars($m['nombre']); ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">
        <div class="tab-pane fade show active" id="paneParamVentas" role="tabpanel">

        <div class="modulo-descripcion mb-3" id="descripcionVentas"></div>

        <!-- ========================================================
             GENERALES
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Generales</h5>
                    <small class="text-muted">Alícuota de IVA, prechequeado y horizonte de proyección</small>
                </div>
                <button id="btnRefreshParametros" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3" id="gridGenerales">
                    <!-- Se genera dinámicamente -->
                </div>
            </div>
        </div>

        <!-- ========================================================
             MIX DE COBRO Y PLAZOS
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Mix de Cobro y Plazos</h5>
                    <small class="text-muted">
                        Porcentaje y días de acreditación por canal y medio de pago.
                        Los medios <strong>activos</strong> de cada canal deben sumar 100%;
                        los inhabilitados no se usan en la proyección ni aparecen en la
                        tabla de cobranza.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar. El
                         mix no se ordena (data-orden="no") pero sí se exporta:
                         es la configuración que explica la proyección. -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaMix"
                            data-exportar-nombre="Parametros_Mix_De_Cobro"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button id="btnNuevoMedio" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Agregar medio
                    </button>
                    <button id="btnGuardarMix" class="btn btn-sm btn-primary" disabled>
                        <i class="fas fa-floppy-disk me-1"></i> Guardar Mix
                    </button>
                </div>
            </div>

            <!-- Alta de medio de pago -->
            <div class="card-body border-bottom" id="formNuevoMedio" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Canal</label>
                        <select id="nuevoCanal" class="form-select form-select-sm">
                            <!-- Se genera dinámicamente -->
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Medio de Pago</label>
                        <input type="text" id="nuevoMedio" class="form-control form-control-sm"
                               maxlength="30" placeholder="Ej: Mercado Pago">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Días Acreditación</label>
                        <input type="number" id="nuevoDias" class="form-control form-control-sm"
                               min="0" step="1" value="0">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button id="btnAgregarMedio" class="btn btn-sm btn-primary flex-fill">
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button id="btnCancelarMedio" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div class="param-hint mt-2">
                    El medio nuevo entra <strong>inhabilitado y en 0%</strong>. Para usarlo,
                    activalo y reacomodá los porcentajes del canal hasta que sumen 100%.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- data-orden="no": no es un listado, es un formulario. Los
                         porcentajes tienen que sumar 100% POR CANAL, y las filas
                         de un canal vienen juntas justamente para poder leer esa
                         suma; ordenar por otra columna las desarma. -->
                    <table id="tablaMix" class="table table-hover mb-0" data-orden="no">
                        <thead>
                            <tr>
                                <th>Canal</th>
                                <th>Medio de Pago</th>
                                <th class="text-center" style="width: 110px;">Activo</th>
                                <th class="text-center" style="width: 170px;">% Mix</th>
                                <th class="text-center" style="width: 170px;">Días Acreditación</th>
                            </tr>
                        </thead>
                        <tbody id="mixBody">
                            <!-- Se genera dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             PARTICIPACIÓN FIJA DE RESPALDO
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Participación Fija de Respaldo</h5>
                    <small class="text-muted">
                        Se usa cuando el mes del año anterior no tiene datos o su venta total es cero.
                        Esos meses quedan marcados como estimados. Debe sumar 100%.
                    </small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span id="sumaRespaldo" class="suma-participacion">0,00%</span>
                    <button id="btnGuardarRespaldo" class="btn btn-sm btn-primary" disabled>
                        <i class="fas fa-floppy-disk me-1"></i> Guardar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3" id="gridRespaldo">
                    <!-- Se genera dinámicamente -->
                </div>
            </div>
        </div>

        </div><!-- /paneParamVentas -->

        <!-- Parámetros del módulo Saldos: bancos y cuentas, otros saldos y la
             gestión de caja de cada local. Mismo criterio que el editor de
             estructura: archivo y JS propios, y clases con prefijo sp- porque
             Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO el
             documento. -->
        <div class="tab-pane fade" id="paneParamSaldos" role="tabpanel">
            <?php include __DIR__ . '/parametros_saldos.php'; ?>
        </div>

        <!-- Parámetros del módulo Cob. Electrónicos: procesadoras de pago y las
             alícuotas de retención con las que se calcula el importe neto de
             cada acreditación. El id del pane sale de
             ucfirst(strtolower($codigo)), que es lo que usa el <li> generado
             arriba. Mismo criterio que Saldos: archivo y JS propios, y clases
             con prefijo pce-. -->
        <div class="tab-pane fade" id="paneParamCob_electronicos" role="tabpanel">
            <?php include __DIR__ . '/parametros_cob_electronicos.php'; ?>
        </div>

        <!-- Maestro de clientes que operan con venta cobrada anticipada. Acota
             el listado de Echeqs → Venta Cobrada Anticipada, que es de donde
             sale el neteo de cheques adelantados de Ventas. Mismo criterio que
             Saldos y Cob. Electrónicos: archivo y JS propios, y clases con
             prefijo ppq-. -->
        <div class="tab-pane fade" id="paneParamPrechequeado" role="tabpanel">
            <?php include __DIR__ . '/parametros_prechequeado.php'; ?>
        </div>

        <!-- Parámetros del módulo Cobranzas: PPP calculado y editable, y
             escalas de descuento por cliente. Mismo criterio que los anteriores:
             archivo y JS propios, y clases con prefijo pcob-. -->
        <div class="tab-pane fade" id="paneParamCobranzas" role="tabpanel">
            <?php include __DIR__ . '/parametros_cobranzas.php'; ?>
        </div>

        <!-- Listas de opciones del maestro de Proveedores Locales: rubro
             económico, rubro, centro de costos, plazo y criterio de
             distribución. Antes eran texto libre, y el rubro económico no es
             cosmético: cada valor distinto crea una fila propia en el tablero.
             Mismo criterio que los anteriores: archivo y JS propios, y clases
             con prefijo pplo-. -->
        <div class="tab-pane fade" id="paneParamProv_locales" role="tabpanel">
            <?php include __DIR__ . '/parametros_prov_locales.php'; ?>
        </div>

        <!-- Parámetros del módulo Compras Exterior (código COMPRAS_PROY): cuántos meses, cuánta
             historia para la cuota y los tres días de la cadena de fechas.
             Mismo criterio que los anteriores: archivo y JS propios, y clases
             con prefijo pcpr-. -->
        <div class="tab-pane fade" id="paneParamCompras_proy" role="tabpanel">
            <?php include __DIR__ . '/parametros_compras_proy.php'; ?>
        </div>

        <!-- Estructura del tablero de Cashflow.
             Va en su propio archivo y con su propio JS: no comparte nada con
             los bloques de Ventas, y así un problema acá no puede llevarse
             puesta la pestaña que ya funciona. Sus clases llevan el prefijo
             cfe- porque Parametros.js busca .param-input, .mix-* y .respaldo-*
             en TODO el documento. -->
        <div class="tab-pane fade" id="paneParamCashflow" role="tabpanel">
            <?php include __DIR__ . '/parametros_estructura.php'; ?>
        </div>

        </div><!-- /tab-content -->

    </div><!-- /wrapperParametros -->
</div><!-- /tab-parametros -->

<script src="Js/Parametros.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Saldos.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Cob-Electronicos.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Prechequeado.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Cobranzas.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Prov_locales.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Estructura.js?v=<?php echo time(); ?>"></script>
