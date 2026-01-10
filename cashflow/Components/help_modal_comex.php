<!-- Modal de Ayuda COMEX -->
<div class="modal fade" id="modalAyudaComex" tabindex="-1" aria-labelledby="modalAyudaComexLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalAyudaComexLabel">
                    <i class="fas fa-circle-info me-2 text-primary"></i>
                    Ayuda - Comercio Exterior
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs de contenido -->
                <ul class="nav nav-tabs mb-3" id="ayudaTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-edicion" data-bs-toggle="tab" data-bs-target="#content-edicion" type="button">
                            <i class="fas fa-pen me-1"></i> Editar Fechas
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-vistas" data-bs-toggle="tab" data-bs-target="#content-vistas" type="button">
                            <i class="fas fa-table-columns me-1"></i> Vistas
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-indicadores" data-bs-toggle="tab" data-bs-target="#content-indicadores" type="button">
                            <i class="fas fa-tag me-1"></i> Indicadores
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="ayudaTabContent">
                    <!-- Tab: Editar Fechas -->
                    <div class="tab-pane fade show active" id="content-edicion" role="tabpanel">
                        <h6 class="mb-3"><i class="fas fa-pen text-primary me-2"></i>Cómo Editar Fechas</h6>
                        
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>Importante:</strong> Las fechas editadas se guardan separadamente y no modifican los datos originales del sistema.
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Pasos para editar:</h6>
                            <ol class="mb-0">
                                <li class="mb-2">
                                    <strong>Click en la celda:</strong> Haga click en cualquier celda de la columna "Fecha Est. Pago" o "Fecha Nac."
                                </li>
                                <li class="mb-2">
                                    <strong>Seleccionar fecha:</strong> Aparecerá un selector de fecha (datepicker) donde puede elegir la nueva fecha proyectada
                                </li>
                                <li class="mb-2">
                                    <strong>Guardar:</strong> Presione <kbd>Enter</kbd> o haga click fuera del campo para guardar automáticamente
                                </li>
                                <li class="mb-2">
                                    <strong>Cancelar:</strong> Presione <kbd>Esc</kbd> para cancelar la edición sin guardar
                                </li>
                            </ol>
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Identificación visual:</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <div style="background-color: #fff8e1; width: 30px; height: 30px; border-radius: 4px; border-left: 3px solid #ff9800;"></div>
                                        <div>
                                            <strong>Fondo Amarillo</strong>
                                            <br><small class="text-muted">Indica fecha editada</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <span class="badge-fecha-editada">Editada</span>
                                        <div>
                                            <strong>Badge Naranja</strong>
                                            <br><small class="text-muted">Marcador adicional</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="help-section">
                            <h6 class="fw-bold mb-2">Notas importantes:</h6>
                            <ul class="mb-0">
                                <li>Las fechas que usted edite se guardan automáticamente</li>
                                <li>Los datos originales del sistema no se modifican</li>
                                <li>El cronograma se actualiza automáticamente al guardar</li>
                                <li>Puede cambiar una fecha cuantas veces necesite</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Tab: Vistas -->
                    <div class="tab-pane fade" id="content-vistas" role="tabpanel">
                        <h6 class="mb-3"><i class="fas fa-table-columns text-primary me-2"></i>Vistas del Cronograma</h6>
                        
                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">
                                <i class="fas fa-calendar-week me-2 text-primary"></i>
                                Vista Semanas (28 días)
                            </h6>
                            <p class="mb-2">Muestra los próximos 28 días individuales (4 semanas completas)</p>
                            <ul class="mb-0">
                                <li>Cada columna representa un día específico</li>
                                <li>Los montos se distribuyen en la fecha exacta de pago</li>
                                <li>Ideal para planificación a corto plazo</li>
                                <li>Los totales aparecen en la fila inferior</li>
                            </ul>
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">
                                <i class="fas fa-calendar-alt me-2 text-warning"></i>
                                Vista Meses (11 meses)
                            </h6>
                            <p class="mb-2">Muestra los próximos 11 meses en columnas mensuales</p>
                            <ul class="mb-0">
                                <li>Cada columna representa un mes completo</li>
                                <li>Excluye los primeros 28 días (ya mostrados en vista Semanas)</li>
                                <li>Los montos se agrupan por mes</li>
                                <li>Ideal para planificación a mediano/largo plazo</li>
                            </ul>
                        </div>

                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Nota:</strong> Los 28 días de la vista Semanas NO se incluyen en la vista Meses para evitar duplicación de datos.
                        </div>
                    </div>

                    <!-- Tab: Indicadores -->
                    <div class="tab-pane fade" id="content-indicadores" role="tabpanel">
                        <h6 class="mb-3"><i class="fas fa-tag text-primary me-2"></i>Indicadores y Estados</h6>
                        
                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Estados de Confirmación:</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <div class="p-3 border rounded" style="background-color: #e8f5e9;">
                                        <span class="confirm-indicator confirmed">Conf</span>
                                        <div class="mt-2">
                                            <strong>Confirmada</strong>
                                            <p class="mb-0 small text-muted">
                                                La fecha ha sido confirmada oficialmente. Mayor certeza en el cronograma.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded" style="background-color: #fff8e1;">
                                        <span class="confirm-indicator estimated">Est</span>
                                        <div class="mt-2">
                                            <strong>Estimada</strong>
                                            <p class="mb-0 small text-muted">
                                                La fecha es una estimación y puede cambiar. Sujeta a confirmación.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">KPIs (Indicadores Clave):</h6>
                            <div class="mb-3">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="kpi-card-icon blue" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                                        <i class="fas fa-calendar-week"></i>
                                    </div>
                                    <div>
                                        <strong>Próximas 4 Semanas</strong>
                                        <br><small class="text-muted">Suma de pagos en los próximos 28 días</small>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="kpi-card-icon orange" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div>
                                        <strong>Próximos 11 Meses</strong>
                                        <br><small class="text-muted">Proyección desde el día 29 hasta 11 meses</small>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="kpi-card-icon green" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                                        <i class="fas fa-ship"></i>
                                    </div>
                                    <div>
                                        <strong>Total General</strong>
                                        <br><small class="text-muted">Suma de todos los pagos proyectados</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="help-section">
                            <h6 class="fw-bold mb-2">Códigos de color en celdas:</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="p-2 border rounded" style="background-color: #e3f2fd;">
                                        <strong style="color: #3366ff;">Azul Claro</strong>
                                        <br><small class="text-muted">Celdas con valores (montos activos)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 border rounded">
                                        <strong>Sin color</strong>
                                        <br><small class="text-muted">Celdas sin valores para ese día/mes</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Botón flotante de ayuda -->
<button class="btn btn-primary btn-help-float" data-bs-toggle="modal" data-bs-target="#modalAyudaComex" title="Ayuda">
    <i class="fas fa-circle-question"></i>
</button>

<style>
.btn-help-float {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    transition: all 0.3s ease;
}

.btn-help-float:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.help-section {
    padding-left: 8px;
}

.help-section kbd {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 12px;
}

@media (max-width: 768px) {
    .btn-help-float {
        bottom: 16px;
        right: 16px;
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
}
</style>
