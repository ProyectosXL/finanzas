<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'CALENDARIO');
?>
<?php
/**
 * Parametros -> Calendario
 *
 * Todo lo que es una FECHA y mueve a todas las pestañas: el horizonte de
 * proyeccion, los feriados bancarios (RO_T_CALENDARIO mas los ajustes de
 * Class/CalendarioAjustes.php), los feriados de comercio y el cronograma de
 * pagos, que vino entero desde Generales.
 *
 * Archivo y JS propios (Js/Parametros-Calendario.js), como los demas modulos de
 * esta pestana, y clases con prefijo pcal-. Parametros.js busca .param-input,
 * .mix-* y .respaldo-* en TODO el documento, asi que dos modulos compartiendo
 * esas clases se pisarian el guardado.
 */
?>

<div class="pcal-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="pcalDescripcion"></div>
        <button id="pcalBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos de configuración pendiente: el script sin correr, el calendario
         que no responde. La pantalla no rompe, avisa. -->
    <div id="pcalAvisos"></div>

    <!-- ========================================================
         HORIZONTE DE PROYECCIÓN
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Horizonte de proyección</h5>
            <small class="text-muted">
                Cuántas columnas diarias y mensuales tiene el tablero. <strong>Mueven todas
                las pestañas</strong>, no sólo una: el horizonte es el eje de todo el módulo.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3" id="pcalGrid">
                <!-- Se genera dinámicamente -->
            </div>
        </div>
    </div>

    <!-- ========================================================
         FERIADOS: BANCARIOS Y DE COMERCIO
         Dos conceptos distintos en la misma card: el bancario corre
         fechas al día hábil; el de comercio es un día sin venta.
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Feriados</h5>
            <small class="text-muted">
                Son <strong>dos cosas distintas</strong>. Un <strong>feriado bancario</strong>
                es un día que el banco no opera: lo que cae ese día —un cobro, un pago, un
                cheque— se corre al día hábil. Un <strong>feriado de comercio</strong> es un día
                sin venta estimada.
            </small>
        </div>

        <!-- BANCARIOS -->
        <div class="card-body pb-0">
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-2">
                <div>
                    <h6 class="mb-1">Feriados bancarios</h6>
                    <small class="text-muted d-block">
                        Los de lunes a viernes de <code>RO_T_CALENDARIO</code> (<em>Calendario
                        bancario</em>), que es externa y no se toca, más los que se agregan o se
                        quitan acá (<em>Ajuste</em>). El ajuste vale para <strong>todo el
                        cashflow</strong>: el cronograma de pagos, Ventas, Echeqs, Tarjetas,
                        Saldos y la marca de los días no hábiles del eje.
                    </small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <label class="form-label form-label-sm mb-0" for="pcalAnio">Año</label>
                    <select id="pcalAnio" class="form-select form-select-sm"
                            style="width: 100px;"></select>
                    <?php if ($edita): ?>
                    <button class="btn btn-sm btn-primary" id="pcalAgregarFeriado">
                        <i class="fas fa-plus me-1"></i> Agregar feriado
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-success" data-exportar="pcalTablaFeriados"
                            data-exportar-nombre="Parametros_Feriados_Bancarios"
                            title="Exportar a Excel los feriados bancarios del año">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                <!-- data-orden="no": es un calendario, se lee en orden de fecha. -->
                <table id="pcalTablaFeriados" class="table table-sm table-hover mb-0 align-middle"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Fecha</th>
                            <th style="width: 120px;">Vale</th>
                            <th style="width: 170px;">Origen</th>
                            <th>Descripción</th>
                            <th>Última edición</th>
                            <th class="text-center" style="width: 150px;"></th>
                        </tr>
                    </thead>
                    <tbody id="pcalFeriadosBody">
                        <!-- Se genera dinámicamente -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DE COMERCIO -->
        <div class="card-body border-top">
            <h6 class="mb-1">Feriados de comercio
                <span id="pcalComercioAuditoria"></span></h6>
            <small class="text-muted d-block mb-2">
                Los únicos días del año <strong>sin venta estimada</strong>. Se repiten todos los
                años. El divisor de la venta diaria los descuenta, así que el total del mes no
                cambia: la venta de ese día se reparte entre los demás.
            </small>
            <div id="pcalComercioLista" class="d-flex flex-wrap gap-2 mb-2">
                <!-- Se genera dinámicamente -->
            </div>
            <?php if ($edita): ?>
            <div class="d-flex gap-2 align-items-center" id="pcalComercioAlta">
                <input type="text" class="form-control form-control-sm" id="pcalComercioNuevo"
                       placeholder="DD/MM" maxlength="5" style="width: 90px;"
                       title="Día y mes, por ejemplo 25/12">
                <button class="btn btn-sm btn-outline-primary" id="pcalComercioAgregar">
                    <i class="fas fa-plus me-1"></i> Agregar
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================================
         CRONOGRAMA DE PAGOS — UNO POR CONCEPTO
         Tres tablas en la misma card, porque son tres caras del mismo
         dato: cómo se calcula cada cronograma, los pagos que se pueden
         mover a mano y el resto del horizonte, que sólo se mira.
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Cronograma de pagos</h5>
                <small class="text-muted">
                    <strong>Un cronograma por concepto</strong>, cada uno con su día de la
                    semana y su frecuencia: <em>quincenal</em> es el 2do y el 4to de ese día
                    del mes; <em>semanal</em>, todos los de ese día. Si el día no es hábil, el
                    pago se corre al día hábil <strong>anterior</strong>, salvo
                    <strong>Gastos Locales</strong>, que se corre al <strong>siguiente</strong>,
                    es siempre semanal y no se mueve a mano.
                </small>
            </div>
            <button class="btn btn-sm btn-outline-success" data-exportar="pcalTablaCronograma"
                    data-exportar-nombre="Parametros_Cronograma_Pagos"
                    title="Exportar a Excel los próximos pagos">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>

        <!-- 1. CÓMO SE CALCULA CADA UNO -->
        <div class="card-body pb-0">
            <h6 class="mb-1">Configuración</h6>
            <small class="text-muted d-block mb-2">
                Cambiar el día o la frecuencia de un concepto <strong>da de baja los pagos
                movidos a mano</strong> de ese concepto desde este mes: con otro día, el número
                de pago apunta a otra fecha. Antes de guardar se dice cuántos son.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pcalTablaCronoConfig" class="table table-sm table-hover mb-0 align-middle"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Lo usa</th>
                            <th style="width: 160px;">Día de la semana</th>
                            <th style="width: 160px;">Frecuencia</th>
                            <th>Se paga</th>
                        </tr>
                    </thead>
                    <tbody id="pcalCronoConfigBody"></tbody>
                </table>
            </div>
        </div>

        <!-- 2. LOS DOS PRÓXIMOS PAGOS DE CADA CONCEPTO: los únicos que se editan -->
        <div class="card-body pb-0 border-top">
            <h6 class="mb-1">Próximos pagos</h6>
            <small class="text-muted d-block mb-2">
                Los <strong>dos próximos</strong> de cada concepto. La fecha cargada a mano
                <strong>no se corre</strong> al día hábil: se respeta tal cual. El
                <strong>motivo es obligatorio</strong>: es lo único que después explica por qué
                ese pago no cayó donde la cuenta decía.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pcalTablaCronograma" class="table table-sm table-hover mb-0 align-middle"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Pago</th>
                            <th>Teórica</th>
                            <th>Calculada</th>
                            <th style="width: 170px;">Fecha de pago</th>
                            <th>Motivo</th>
                            <th class="text-center" style="width: 130px;"></th>
                        </tr>
                    </thead>
                    <tbody id="pcalCronogramaBody">
                        <!-- Se genera dinámicamente -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. EL RESTO DEL HORIZONTE: se ve, no se edita -->
        <div class="card-body pb-0 border-top">
            <h6 class="mb-1">Resto del horizonte</h6>
            <small class="text-muted d-block mb-2">
                Calculadas, no editables. Cada mes reparte su importe <strong>en partes
                iguales</strong> entre sus pagos: mitad y mitad en quincenal, un cuarto o un
                quinto en semanal.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pcalTablaCronoResto" class="table table-sm table-hover mb-0"
                       data-orden="no">
                    <thead>
                        <tr id="pcalCronoRestoHead">
                            <th>Mes</th>
                        </tr>
                    </thead>
                    <tbody id="pcalCronoRestoBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     HISTORIAL DE UNA FECHA DEL CRONOGRAMA
     La fecha y el motivo se editan en la fila; el modal queda sólo
     para ver qué fechas tuvo ese pago y quién las cambió.
     ============================================================ -->
<div class="modal fade" id="pcalModalFecha" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Historial de la fecha de pago
                    <small class="text-muted ms-2" id="pcalFechaTitulo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2">
                    Las fechas no se borran: se dan de baja.
                </div>
                <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                    <table class="table table-sm mb-0" id="pcalTablaHistorialFecha">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Calculada</th>
                                <th>Motivo</th>
                                <th>Usuario</th>
                                <th>Alta</th>
                                <th>Baja</th>
                            </tr>
                        </thead>
                        <tbody id="pcalHistorialFechaBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary"
                        data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     AGREGAR UN FERIADO BANCARIO
     La fecha y la descripción. "Marcar hábil" no usa el modal: se
     hace desde la fila del feriado del calendario bancario.
     ============================================================ -->
<div class="modal fade" id="pcalModalFeriado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pcalFeriadoTitulo">Agregar feriado bancario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label form-label-sm" for="pcalFeriadoFecha">Fecha</label>
                    <input type="date" class="form-control form-control-sm" id="pcalFeriadoFecha">
                    <div class="form-text">De lunes a viernes: sábado y domingo nunca son hábiles.</div>
                </div>
                <div class="mb-1">
                    <label class="form-label form-label-sm" for="pcalFeriadoDescripcion">
                        Descripción <span id="pcalFeriadoObligatoria" class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="pcalFeriadoDescripcion"
                           maxlength="200" placeholder="Qué feriado es">
                    <div class="form-text" id="pcalFeriadoNota">
                        <code>RO_T_CALENDARIO</code> no tiene el nombre del feriado: la descripción es
                        lo único que lo explica.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary"
                        data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-primary" id="pcalFeriadoGuardar">
                    <i class="fas fa-check me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>
