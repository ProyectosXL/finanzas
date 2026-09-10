<?php
/**
 * Sub-pestaña Parámetros -> Cobranzas
 * Gestión de Plazos Promedio de Pago (PPP) y Escalas de Descuento por Cliente
 */
?>

<div class="modulo-descripcion mb-3">
    Administración de <strong>Plazos Promedio de Pago (PPP)</strong> calculados automáticamente en base a los últimos 3 cobros y editables por cliente (Franquicias), junto con las <strong>Escalas de Descuento</strong> por tramos de días, y el <strong>Plazo de Vencimiento Mayorista</strong>.
</div>

<!-- Parámetros Mayoristas -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Cobranzas Mayoristas</h5>
        <small class="text-muted">Parámetro global de proyección para clientes mayoristas</small>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <div class="param-card" id="card-cobranzas_may_dias_vto">
                    <div class="param-clave">Plazo de Proyección Mayoristas</div>
                    <div class="param-descripcion">Días de plazo a sumar a la fecha de emisión de factura para calcular la fecha probable de cobro.</div>
                    <div class="input-group input-group-sm">
                        <input type="number" step="1" min="1" class="form-control param-input" data-clave="cobranzas_may_dias_vto" data-tipo="entero" value="60">
                        <span class="input-group-text">días</span>
                    </div>
                    <div class="param-hint">Días a sumar a la F. Emisión (predeterminado: 60 días).</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div>
                <h5 class="mb-0">Gestión de Cobranza Franquicias</h5>
                <small class="text-muted">Configuración de PPP y escalas de descuento para facturas proyectadas</small>
            </div>
            <div class="search-box-container ms-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" id="busquedaParamCob" class="form-control border-start-0 ps-0" placeholder="Buscar cliente..." style="min-width: 250px;">
                </div>
            </div>
        </div>
        <div>
            <button id="btnRefreshParamCob" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-sync-alt me-1"></i> Actualizar
            </button>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="loading-spinner" id="loadingParamCob" style="display: none;">
            <div class="spinner"></div>
            <p>Cargando configuración de clientes...</p>
        </div>

        <div class="table-responsive" id="wrapperTablaParamCob">
            <table class="table table-hover align-middle mb-0" id="tablaParamCob">
                <thead class="table-light">
                    <tr>
                        <th style="width: 120px;">Cód. Cliente</th>
                        <th>Razón Social</th>
                        <th class="text-center" style="width: 130px;" title="Medio de pago por defecto del cliente">Medio de Pago</th>
                        <th class="text-center" style="width: 140px;" title="Promedio de días de los últimos 3 cobros realizados">PPP Calculado</th>
                        <th class="text-center" style="width: 180px;" title="Plazo manual que pisa el PPP calculado">PPP Manual (Pisar)</th>
                        <th class="text-center" style="width: 130px;" title="Plazo que se utiliza efectivamente en la proyección">PPP Efectivo</th>
                        <th>Escala de Descuentos (Días &rarr; %)</th>
                        <th class="text-center" style="width: 140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tbodyParamCob">
                    <!-- Filas dinámicas -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal para Gestionar / Agregar Escala de Descuento -->
<div class="modal fade" id="modalEscalaCob" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title" id="modalEscalaCobTitulo">
                    <i class="fas fa-tags me-1"></i> Escalas de Descuento
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <strong id="modalEscalaClienteNombre"></strong>
                    <div class="text-muted small" id="modalEscalaClienteCod"></div>
                </div>

                <!-- Lista de tramos actuales -->
                <div class="mb-4">
                    <label class="form-label fw-bold small text-uppercase text-muted">Tramos configurados</label>
                    <div id="listaTramosActuales" class="list-group list-group-flush border rounded">
                        <!-- Tramos dinámicos -->
                    </div>
                </div>

                <!-- Formulario para nuevo tramo -->
                <div class="card bg-light p-3 border-0 rounded-3">
                    <h6 class="card-subtitle mb-2 text-primary fw-bold">
                        <i class="fas fa-plus-circle me-1"></i> Agregar nuevo tramo
                    </h6>
                    <form id="formNuevoTramoCob">
                        <input type="hidden" id="tramoId" value="0">
                        <input type="hidden" id="tramoCodCliente" value="">
                        
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label form-label-sm">Días Desde</label>
                                <input type="number" id="tramoDiasDesde" class="form-control form-control-sm" min="0" required placeholder="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label form-label-sm">Días Hasta</label>
                                <input type="number" id="tramoDiasHasta" class="form-control form-control-sm" min="0" required placeholder="20">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-12">
                                <label class="form-label form-label-sm">% Descuento</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" id="tramoPorcDesc" class="form-control form-control-sm" step="0.01" min="0" max="100" required placeholder="8.00">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-save me-1"></i> Guardar Tramo
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
