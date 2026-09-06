<?php $tabName = 'Parámetros'; ?>
<link rel="stylesheet" href="Css/Parametros.css?v=<?php echo time(); ?>">

<div class="tab-parametros">

    <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
        <i class="fas fa-circle-info mt-1"></i>
        <div>
            <small>
                Los valores de esta pestaña alimentan las fórmulas del módulo:
                no hay ninguna constante escrita en el código. Cambiar un plazo de
                acreditación o la alícuota recalcula la proyección de Ventas.
            </small>
        </div>
    </div>

    <div class="loading-spinner" id="loadingParametros">
        <div class="spinner"></div>
        <p>Cargando parámetros...</p>
    </div>

    <div id="wrapperParametros" style="display: none;">

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
                    <table id="tablaMix" class="table table-hover mb-0">
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

    </div>
</div>

<script src="Js/Parametros.js?v=<?php echo time(); ?>"></script>
