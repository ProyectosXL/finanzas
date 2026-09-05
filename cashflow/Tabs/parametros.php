<?php $tabName = 'Parámetros'; ?>
<link rel="stylesheet" href="Css/Parametros.css">

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
                        El mix de cada canal debe sumar 100%.
                    </small>
                </div>
                <button id="btnGuardarMix" class="btn btn-sm btn-primary" disabled>
                    <i class="fas fa-floppy-disk me-1"></i> Guardar Mix
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tablaMix" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Canal</th>
                                <th>Medio de Pago</th>
                                <th class="text-center" style="width: 180px;">% Mix</th>
                                <th class="text-center" style="width: 180px;">Días Acreditación</th>
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
