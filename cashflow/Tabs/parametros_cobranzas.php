<?php
/**
 * Sub-pestaña Parámetros -> Cobranzas
 * Gestión de Plazos Promedio de Pago (PPP) y Escalas de Descuento por Cliente
 */
?>

<div class="modulo-descripcion mb-3">
    La <strong>escala de descuento</strong> es una sola y vale para todos los clientes. Lo que sí es por cliente es el <strong>Plazo Promedio de Pago (PPP)</strong>, que se calcula con los últimos 3 cobros y se puede pisar a mano. Acá también se editan el <strong>Plazo de Vencimiento Mayorista</strong> y el <strong>Plazo de Cobro de Exportaciones Tasky</strong>.
</div>

<div class="row g-3 mb-4">

    <!-- Plazos globales: Mayoristas y Exportaciones Tasky. Los dos son el
         mismo mecanismo -fecha de emisión + días- y por eso van juntos. -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0">Plazos de Cobro</h5>
                <small class="text-muted">Parámetros globales: días a sumar a la fecha de emisión</small>
            </div>
            <div class="card-body">
                <!-- Cada tarjeta en su propia columna: .param-card lleva
                     height: 100%, y dos apiladas sueltas en el mismo
                     card-body se pisan. -->
                <div class="row g-3">
                    <div class="col-12">
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

                    <div class="col-12">
                        <div class="param-card" id="card-exportaciones_tasky_dias_cobro">
                            <div class="param-clave">Plazo de Cobro Exportaciones Tasky</div>
                            <div class="param-descripcion">Días a sumar a la fecha de emisión de las facturas en dólares a Tasky para estimar su fecha de cobro. Una factura cuya fecha estimada ya pasó se muestra como vencida, en el primer día del eje.</div>
                            <div class="input-group input-group-sm">
                                <input type="number" step="1" min="1" class="form-control param-input" data-clave="exportaciones_tasky_dias_cobro" data-tipo="entero" value="30">
                                <span class="input-group-text">días</span>
                            </div>
                            <div class="param-hint">Predeterminado: 30 días. Si el parámetro no está sembrado, la pestaña usa ese valor.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!--
        EDITOR DE LA ESCALA GENERAL

        Antes la escala se cargaba por cliente, desde un modal por fila de la
        grilla. En la práctica la escala comercial es una sola, así que eso
        obligaba a repetir la misma carga por cada franquicia y dejaba a la
        mayoría sin escala, cayendo a un porcentaje de respaldo distinto.

        Va arriba y no en la grilla porque es un parámetro del negocio y no un
        atributo de un cliente.
    -->
    <div class="col-lg-8">
        <div class="card h-100" id="cardEscalaCob">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Escala de Descuento</h5>
                    <small class="text-muted">
                        Una sola escala para todos los clientes. <strong>No depende del medio de pago.</strong>
                        Los días son los que van de la emisión a la fecha de cobro.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button id="btnAgregarTramoEsc" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Agregar tramo
                    </button>
                    <button id="btnGuardarEscala" class="btn btn-sm btn-primary">
                        <i class="fas fa-save me-1"></i> Guardar escala
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Los errores de solapamiento y de huecos van acá: son un
                     aviso sobre el dato, no una notificación de acción. -->
                <div id="avisosEscalaCob"></div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="tablaEscalaCob">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 140px;">Días desde</th>
                                <th style="width: 140px;">Días hasta</th>
                                <th style="width: 160px;">% Descuento</th>
                                <th style="width: 60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="tbodyEscalaCob"></tbody>
                    </table>
                </div>

                <small class="text-muted d-block mt-2">
                    Los tramos tienen que arrancar en 0 y encadenarse sin huecos ni superposiciones.
                    El último conviene dejarlo abierto (por ejemplo hasta 9999): un plazo sin tramo
                    va con 0% sin que nadie lo haya decidido.
                </small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div>
                <h5 class="mb-0">Gestión de Cobranza Franquicias</h5>
                <small class="text-muted">
                    Plazo Promedio de Pago por cliente. El descuento sale de la escala general de arriba,
                    y el <em>Medio de Pago</em> quedó como dato informativo del cliente: ya no interviene en el cálculo.
                </small>
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
                    </tr>
                </thead>
                <tbody id="tbodyParamCob">
                    <!-- Filas dinámicas -->
                </tbody>
            </table>
        </div>
    </div>
</div>
