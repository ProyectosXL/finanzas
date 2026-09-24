<?php $tabName = 'Proyección'; ?>
<link rel="stylesheet" href="Css/Compras-Proyectadas.css?v=<?php echo time(); ?>">

<div class="tab-compras_proyectadas">

    <!-- Los avisos de este módulo son la mitad de la pantalla, y van arriba de
         todo a propósito: el primero puede ser que NO HAY PRESUPUESTO que leer,
         y en ese caso las dos filas del tablero van en cero. Una fila de
         egresos en cero se lee como "no hay que pagar nada", que es lo
         contrario de lo que pasa. -->
    <div id="avisosComprasProy"></div>

    <!-- Las tres tarjetas contestan las tres preguntas de la pantalla, en
         orden: cuánto se proyecta, cuánto de eso ya está comprado, y cuánto
         queda por comprometer. -->
    <div class="row g-3 mb-4" id="summaryComprasProy" style="display: none;">
        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Proyectado</span>
                    <div class="kpi-card-icon blue">
                        <i class="fas fa-boxes-packing"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="cpProyectado">U$S 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cpProyectadoPie">FOB del presupuesto</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Ya comprado</span>
                    <div class="kpi-card-icon orange">
                        <i class="fas fa-ship"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="cpCargado">U$S 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cpCargadoPie">Descontado de lo proyectado</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Estimación FOB</span>
                    <div class="kpi-card-icon green">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="cpEstimacion">U$S 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cpEstimacionPie">Lo que falta comprometer</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-3">
            <div class="kpi-card">
                <div class="kpi-card-header">
                    <span class="kpi-card-title">Nacionalización</span>
                    <div class="kpi-card-icon purple">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="kpi-card-value" id="cpNacionalizacion">U$S 0,00</div>
                <div class="kpi-card-footer">
                    <span class="text-muted" id="cpNacPie">Sobre la estimación</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         LA GRILLA DE COBERTURA
         Un renglón por mes de la ventana, con su estado. El motor nunca
         deja un mes en cero sin decir por qué, y ésta es la pantalla
         donde ese "por qué" se lee.
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <h5 class="mb-0">Cobertura mes a mes</h5>
                    <small class="text-muted">
                        Un renglón por mes de recepción proyectado
                        <i class="fas fa-info-circle ms-1" id="cpAyudaCobertura"
                           title="Cada mes reparte el presupuesto de SU temporada con la cuota histórica de recepciones, y le descuenta lo que ya tiene contenedor cargado en Comercio Exterior. La estimación nunca es negativa: si lo comprado supera a lo proyectado, el mes queda CUBIERTO y el exceso se informa aparte."></i>
                    </small>
                </div>

                <!-- Mismo marcado que el buscador de las dos pestañas de Comex
                     y el de Echeqs: la misma tabla con el mismo problema, y un
                     control que se ve distinto en cada pantalla se lee como
                     otro control. Acá busca por mes, temporada y estado. -->
                <div class="search-box-container">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" id="busquedaComprasProy"
                               class="form-control border-start-0 ps-0"
                               placeholder="Buscar mes, temporada o estado…"
                               style="min-width: 240px;">
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- El selector de columnas fijas lo dibuja Js/columnas-fijas.js -->
                <div id="colFijasComprasProy"></div>
                <button id="btnRefreshComprasProy" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt me-1"></i> Actualizar
                </button>
                <!-- Lo engancha Js/tabla-export.js por el data-exportar, igual
                     que el resto del módulo: baja exactamente lo que se ve. -->
                <button class="btn btn-sm btn-success" data-exportar="tablaComprasProy"
                        data-exportar-nombre="Comex_Proyeccion"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- De dónde salen los números: la ventana, los parámetros con los que
             se derivó y hasta qué mes llega la curva. No es decoración: sin
             esto, el último mes de la grilla parece arbitrario. -->
        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="cpVentanaTexto"></small>
            <small class="text-muted ms-2" id="cpParametrosTexto"></small>
        </div>

        <div class="card-body p-0">
            <div class="loading-spinner" id="cpSpinner">
                <div class="spinner"></div>
                <p>Calculando la proyección...</p>
            </div>

            <div class="table-wrapper" id="cpTableWrapper" style="display: none;">
                <div class="table-responsive">
                    <table id="tablaComprasProy" class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Mes recepción</th>
                                <th>Temporada</th>
                                <th>Versión</th>
                                <th>Estado</th>
                                <th class="text-end">Cuota</th>
                                <th class="text-end">Proyectado U$S</th>
                                <th class="text-end">Ya comprado U$S</th>
                                <th class="text-end">Estimación U$S</th>
                                <th>Fecha pago</th>
                                <th class="text-end">Dólar pago</th>
                                <th class="text-end">Pago $</th>
                                <th>Fecha nac.</th>
                                <th class="text-end">Nac. U$S</th>
                                <th class="text-end">Nac. $</th>
                                <!-- EL AJUSTE VA AL FINAL Y NO AL LADO DE LA
                                     ESTIMACIÓN, a propósito: la columna que
                                     alimenta al tablero es Estimación, y el
                                     ajuste es lo que la pisa. Ponerlo antes
                                     haría leer el cuadro como si el ajuste
                                     fuera un insumo más de la cuenta. -->
                                <th class="text-center">Ajuste</th>
                            </tr>
                        </thead>
                        <tbody id="cpTableBody">
                            <!-- Las filas las genera el JS -->
                        </tbody>
                        <tfoot class="table-light">
                            <tr id="cpTotalsRow">
                                <td colspan="5" class="fw-bold text-end">TOTALES</td>
                                <td class="text-end fw-bold" id="cpTotProyectado">—</td>
                                <td class="text-end fw-bold" id="cpTotCargado">—</td>
                                <td class="text-end fw-bold" id="cpTotEstimacion">—</td>
                                <td colspan="2"></td>
                                <td class="text-end fw-bold" id="cpTotPagoArs">—</td>
                                <td></td>
                                <td class="text-end fw-bold" id="cpTotNacUsd">—</td>
                                <td class="text-end fw-bold" id="cpTotNacArs">—</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         LAS VERSIONES OFICIALES
         De dónde sale el presupuesto de cada temporada. Desde acá se
         abre el detalle por rubro.
         ============================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Presupuesto oficial por temporada</h5>
            <small class="text-muted">
                Lo que la app de compras tiene marcado como oficial y vigente. Hay
                <strong>una sola versión oficial por temporada</strong>: cada mes de la
                grilla busca la de la suya.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaVersionesComprasProy" class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Temporada</th>
                            <th>Período</th>
                            <th>Versión</th>
                            <th>Calculada</th>
                            <th class="text-end">Unidades</th>
                            <th class="text-end">FOB U$S</th>
                            <th class="text-end">U$S / unidad</th>
                            <!-- EL CONTRASTE QUE JUSTIFICA EL PARÁMETRO: el
                                 inc_fob del presupuesto toma dos valores (0 y
                                 50) y pondera 41 %, contra el 89 % que dan los
                                 contenedores reales. Se muestran los dos al
                                 lado para que la diferencia no haya que
                                 buscarla. -->
                            <th class="text-end">inc_fob del presupuesto</th>
                            <th class="text-end">% que aplica el cashflow</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cpVersionesBody">
                        <!-- Las filas las genera el JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Notas de reconciliación: por qué este número no coincide con otra
         pantalla. Van al pie y separadas de los avisos porque la más común
         aparece SIEMPRE, con casi todo el padrón adentro; mezclada arriba,
         enseña a ignorar el bloque entero. -->
    <div id="cpNotas"></div>
</div>

<!-- Modal del detalle por rubro de una versión -->
<div class="modal fade" id="modalDetalleVersion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Detalle del presupuesto
                    <small class="text-muted ms-2" id="cpDetalleTitulo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="cpDetalleSpinner" class="text-center py-4">
                    <div class="spinner"></div>
                </div>
                <div class="table-responsive" id="cpDetalleWrapper" style="display: none;">
                    <table id="tablaDetalleVersion" class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Rubro</th>
                                <th>Categoría</th>
                                <th class="text-end">Compra (u)</th>
                                <th class="text-end">Costo prom. U$S</th>
                                <th class="text-end">FOB U$S</th>
                                <th class="text-end">inc_fob</th>
                                <th class="text-end">Déficit cobertura</th>
                            </tr>
                        </thead>
                        <tbody id="cpDetalleBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted" id="cpDetallePie"></small>
                <button type="button" class="btn btn-sm btn-success"
                        data-exportar="tablaDetalleVersion"
                        data-exportar-nombre="Presupuesto_Detalle">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     EL AJUSTE MANUAL DE UN MES
     Dos campos y un historial. Va en un modal propio y no en el
     diálogo genérico de Notificacion porque son DOS datos: el
     importe y el motivo. El motivo es obligatorio, igual que en la
     exclusión de cheques: meses después es lo único que explica por
     qué ese mes no muestra la estimación automática.
     ============================================================ -->
<div class="modal fade" id="modalAjuste" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Ajuste manual
                    <small class="text-muted ms-2" id="cpAjusteTitulo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">

                <!-- Lo que el ajuste va a reemplazar. Sin esto, quien carga el
                     número no ve contra qué lo está poniendo. -->
                <div class="alert alert-light border py-2 px-3 small mb-3" id="cpAjusteContexto"></div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label form-label-sm" for="cpAjusteImporte">
                            Importe FOB en dólares
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">U$S</span>
                            <input type="number" step="0.01" min="0" class="form-control"
                                   id="cpAjusteImporte">
                        </div>
                        <div class="form-text">
                            Reemplaza la estimación del mes. La nacionalización se recalcula
                            sobre este importe. Cero es un valor válido: significa que ese mes
                            no se compra nada.
                        </div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label form-label-sm" for="cpAjusteMotivo">
                            Motivo
                        </label>
                        <textarea class="form-control form-control-sm" id="cpAjusteMotivo"
                                  rows="3" maxlength="300"
                                  placeholder="Por qué este mes no lleva la estimación automática"></textarea>
                        <div class="form-text">
                            Obligatorio. Es lo único que después explica el número.
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="mb-2">
                    Historial
                    <small class="text-muted">— los ajustes no se borran: se dan de baja</small>
                </h6>
                <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                    <table class="table table-sm mb-0" id="tablaHistorialAjuste">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th class="text-end">Importe U$S</th>
                                <th>Versión</th>
                                <th>Motivo</th>
                                <th>Usuario</th>
                                <th>Alta</th>
                                <th>Baja</th>
                            </tr>
                        </thead>
                        <tbody id="cpHistorialBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-danger" id="cpAjusteQuitar">
                    <i class="fas fa-rotate-left me-1"></i> Volver a la estimación automática
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-secondary"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-sm btn-primary" id="cpAjusteGuardar">
                        <i class="fas fa-check me-1"></i> Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="Js/Compras-Proyectadas.js?v=<?php echo time(); ?>"></script>
