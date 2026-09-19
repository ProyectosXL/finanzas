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
                    <!-- De dónde sale el dólar con el que se valúa Proveedores
                         Exterior. Es la pregunta que más se hace sobre esa
                         pantalla desde que los importes están en pesos. -->
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-dolar" data-bs-toggle="tab" data-bs-target="#content-dolar" type="button">
                            <i class="fas fa-dollar-sign me-1"></i> El dólar
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="ayudaTabContent">
                    <!-- Tab: Editar Fechas -->
                    <div class="tab-pane fade show active" id="content-edicion" role="tabpanel">
                        <h6 class="mb-3"><i class="fas fa-pen text-primary me-2"></i>Cómo Editar Fechas</h6>
                        
                        <!-- ESTE AVISO DECÍA LO CONTRARIO. Hasta
                             feature/comex-fecha-maestra las fechas editadas
                             vivían sólo en una tabla del cashflow y el texto
                             prometía que "no modifican los datos originales del
                             sistema". Ahora se escriben sobre el maestro de
                             Comercio Exterior, y quien edita tiene que saberlo
                             ANTES de editar: una ayuda que promete que no pasa
                             nada es peor que no tener ayuda. Ver
                             README-comex.md. -->
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-triangle-exclamation me-2"></i>
                            <strong>La fecha que edite acá es la del sistema de Comercio Exterior.</strong>
                            Se guarda en el maestro de importaciones, así que el cambio lo ve
                            también esa aplicación. No es una copia del cashflow: hay una sola
                            fecha. Queda registrado quién la cambió, cuándo y qué decía antes.
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

                        <!-- TRES MARCAS, TRES COSAS DISTINTAS. Las dos últimas
                             son de feature/comex-fecha-maestra: desde que el
                             listado ya no corta por fecha de embarque, la grilla
                             trae también los contenedores vencidos y los que no
                             tienen fecha, y sin marca una fila con las celdas
                             del período vacías se lee como un contenedor sin
                             importe. -->
                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Identificación visual:</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <span class="badge-fecha-editada">Editada</span>
                                        <div>
                                            <strong>La movió alguien desde acá</strong>
                                            <br><small class="text-muted">Pasá el mouse por encima: dice quién, cuándo y qué decía antes</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <span class="badge-fecha-vencida">Vencida</span>
                                        <div>
                                            <strong>La fecha ya pasó</strong>
                                            <br><small class="text-muted">Ese importe no entra en ninguna columna del período. Cargale la fecha nueva y entra solo</small>
                                        </div>
                                    </div>
                                </div>
                                <!-- EL TILDE DE PAGADO. Va acá, entre las
                                     marcas, porque es lo que resuelve las filas
                                     vencidas: se marcan y dejan de pedir
                                     atención. -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <input type="checkbox" class="form-check-input" checked disabled>
                                        <div>
                                            <strong>Pagado</strong>
                                            <br><small class="text-muted">
                                                Tildá cuando el pago <strong>ya se hizo</strong>:
                                                sale de la proyección y la fila del tablero deja
                                                de contarlo. El importe no se pierde y se
                                                destilda con el mismo clic si fue un error. Queda
                                                registrado quién lo marcó y cuándo.
                                                <strong>Son dos tildes por contenedor</strong> —el
                                                pago al proveedor del exterior y el de
                                                nacionalización— y marcar uno no dice nada del
                                                otro.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <!-- LOS INTERRUPTORES, explicados donde se
                                     explican las marcas: quien abre la ayuda por
                                     las filas rojas es el mismo que se pregunta
                                     por qué no las ve. -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" disabled>
                                        </div>
                                        <div>
                                            <strong>Ver vencidas · Ver pagados</strong>
                                            <br><small class="text-muted">
                                                Las vencidas y las pagadas
                                                <strong>no se ven al abrir</strong>: al cashflow
                                                entra lo que falta mover de hoy en adelante, así
                                                que ninguna de las dos <strong>suma en ninguna
                                                columna</strong>. Los interruptores las traen de
                                                vuelta —para corregir una fecha o destildar lo
                                                que se marcó por error— y al lado de cada uno
                                                dice siempre cuántas filas esconde. Esconderlas
                                                no cambia ningún total.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <span class="badge-fecha-sin">Sin fecha</span>
                                        <div>
                                            <strong>No hay fecha cargada</strong>
                                            <br><small class="text-muted">Sin fecha no hay dónde ubicar el importe en el tiempo</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2 p-2 border rounded">
                                        <div style="background-color: #fff5f5; width: 30px; height: 30px; border-radius: 4px; border-left: 3px solid #d64545;"></div>
                                        <div>
                                            <strong>Fila rosada</strong>
                                            <br><small class="text-muted">Toda la fila, para encontrarla sin volver hasta la columna de la fecha</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="help-section">
                            <h6 class="fw-bold mb-2">Notas importantes:</h6>
                            <ul class="mb-0">
                                <li><strong>La fecha se guarda en el maestro de Comercio Exterior</strong>, así que el cambio lo ve también esa aplicación</li>
                                <li>Queda registrado quién la cambió, cuándo y qué decía antes</li>
                                <li>Desde acá <strong>no se puede vaciar</strong> una fecha: si hay que borrarla, se hace desde Comercio Exterior</li>
                                <li>El cronograma se actualiza automáticamente al guardar</li>
                                <li>Puede cambiar una fecha cuantas veces necesite: cada cambio queda en el historial</li>
                                <li>En Proveedores Exterior, si el pago pasa a <strong>otro mes</strong> y tenía una cotización cargada a mano, esa cotización se descarta y la fila vuelve a la curva de dólar futuro. La pantalla lo avisa</li>
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

                    <!-- Tab: El dólar -->
                    <div class="tab-pane fade" id="content-dolar" role="tabpanel">
                        <h6 class="mb-3">
                            <i class="fas fa-dollar-sign text-primary me-2"></i>
                            Con qué dólar se valúa cada contenedor
                        </h6>

                        <div class="alert alert-info mb-3">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>La tabla está en pesos.</strong> El cashflow es en pesos, y
                            esta pestaña es el detalle de una fila del tablero: si mostrara
                            dólares, sus totales no se podrían comparar contra la fila que
                            explica. El <strong>Valor FOB (USD)</strong> queda como referencia,
                            que es el dato con el que se chequea contra la factura del proveedor.
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">De dónde sale</h6>
                            <p class="mb-2">
                                De la <strong>curva de dólar futuro ROFEX</strong>: el mercado
                                publica a cuánto se puede comprar hoy un dólar de cada mes
                                futuro, y ese es el precio con el que se valúa cada contenedor
                                <strong>según el mes de su fecha estimada de pago</strong>.
                            </p>
                            <p class="mb-2">
                                Eso significa que <strong>dos contenedores que se pagan en meses
                                distintos se valúan a dos dólares distintos</strong>. La columna
                                <strong>Dólar aplicado</strong> dice cuál le tocó a cada uno, con
                                el símbolo del contrato (<code>DLR/NOV26</code>) y su valor.
                            </p>
                            <div class="alert alert-secondary mb-0 py-2">
                                <small>
                                    <strong>Es el único criterio.</strong> Antes se usaba un
                                    parámetro global, <code>comex_tipo_cambio_usd</code>: un solo
                                    número con el que se convertían por igual el pago del mes que
                                    viene y el de dentro de once meses. Ese parámetro se retiró de
                                    Parámetros a propósito —dos criterios de valuación conviviendo
                                    significan dos números distintos para el mismo contenedor, sin
                                    que nadie pueda decir cuál es cuál—. Su fila sigue en la base
                                    por si hay que reconstruir con qué número se proyectó antes.
                                </small>
                            </div>
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Las marcas de la columna</h6>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="p-2 border rounded" style="background-color: #fff8e1; border-left: 3px solid #ff9800 !important;">
                                        <strong><i class="fas fa-pen me-1"></i> Corregida a mano</strong>
                                        <br><small class="text-muted">
                                            Alguien le puso una cotización a <em>este</em>
                                            contenedor y esa manda sobre la curva. Se usa cuando la
                                            operación ya está cerrada a un tipo de cambio que el
                                            mercado no refleja.
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 border rounded" style="border-left: 3px dashed #7e57c2 !important;">
                                        <strong><i class="fas fa-code-branch me-1"></i> Mes fuera de la curva</strong>
                                        <br><small class="text-muted">
                                            El pago cae después del último mes que publica el
                                            ROFEX, así que se usó la cotización del mes más cercano
                                            que hay. El importe es una aproximación y por eso se
                                            marca.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="help-section mb-4">
                            <h6 class="fw-bold mb-2">Cómo corregir la cotización de un contenedor</h6>
                            <ol class="mb-2">
                                <li class="mb-2">
                                    <strong>Click en la celda</strong> de la columna "Dólar
                                    aplicado".
                                </li>
                                <li class="mb-2">
                                    <strong>Escribí el valor</strong> (acepta coma decimal:
                                    <code>1.450,75</code>) y <kbd>Enter</kbd>.
                                </li>
                                <li class="mb-2">
                                    <strong>Para volver a la curva</strong>, dejá el campo
                                    <strong>vacío</strong> y guardá. Es la única forma de deshacer
                                    una corrección.
                                </li>
                            </ol>
                            <div class="alert alert-warning mb-0 py-2">
                                <small>
                                    <i class="fas fa-triangle-exclamation me-1"></i>
                                    <strong>Si movés la fecha de pago a otro mes, la corrección se
                                    descarta</strong> y el contenedor vuelve a valuarse con la
                                    curva del mes nuevo. Una cotización cargada a mano es una
                                    afirmación sobre <em>un mes</em>: si el pago se corre a otro,
                                    conservarla valuaría el mes nuevo con un número pensado para el
                                    viejo. La pantalla lo avisa cuando pasa.
                                </small>
                            </div>
                        </div>

                        <div class="help-section">
                            <h6 class="fw-bold mb-2">Cuando un contenedor aparece sin importe</h6>
                            <p class="mb-0">
                                Un guión en la columna de pesos significa que
                                <strong>no se pudo valuar</strong>, y el motivo está en el tooltip
                                de la celda. Casi siempre es que <strong>el contenedor no tiene
                                fecha estimada de pago</strong>: sin fecha no hay mes, y sin mes no
                                hay cotización que pedirle a la curva. No se le aplica ningún tipo
                                de cambio inventado —un cero se leería como "este contenedor no se
                                paga"—, y el aviso de arriba de la tabla dice cuántos son y cuánto
                                suman <strong>en dólares</strong>. Cargales la fecha y el importe
                                aparece solo.
                            </p>
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
