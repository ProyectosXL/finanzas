<?php
/**
 * Parametros -> Generales
 *
 * Lo que afecta a TODO el modulo, y no a una pestana: el horizonte de
 * proyeccion, la alicuota, los feriados de comercio, la inflacion mensual
 * esperada y el cronograma de pagos. Mas las dos cotizaciones, que se muestran
 * y no se editan.
 *
 * Archivo y JS propios, como los demas modulos de esta pestana, y clases con
 * prefijo pgen-. Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO
 * el documento, asi que dos modulos compartiendo esas clases se pisarian el
 * guardado.
 */
?>

<div class="pgen-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="pgenDescripcion"></div>
        <button id="pgenBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos de configuración pendiente: el script sin correr, la migración
         sin hacer, el calendario que no responde. La pantalla no rompe, avisa. -->
    <div id="pgenAvisos"></div>

    <!-- ========================================================
         EL EJE Y LA ALÍCUOTA
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">El eje de proyección</h5>
            <small class="text-muted">
                Cuántas columnas diarias y mensuales tiene el tablero, la alícuota de IVA
                y los feriados de comercio. <strong>Mueven todas las pestañas</strong>, no
                sólo Ventas: el horizonte es el eje de todo el módulo.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3" id="pgenGrid">
                <!-- Se genera dinámicamente -->
            </div>
        </div>
    </div>

    <!-- ========================================================
         INFLACIÓN MENSUAL
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Inflación mensual esperada</h5>
                <small class="text-muted">
                    El % de cada mes calendario. Hoy lo usa el ajuste trimestral del valor
                    hora de Logística Local, que suma la inflación del mes del ajuste
                    <strong>y la de los dos anteriores</strong>, sin componer.
                </small>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <!-- Baja exactamente lo que se ve, con el mecanismo de siempre. -->
                <button class="btn btn-sm btn-outline-success" data-exportar="pgenTablaInflacion"
                        data-exportar-nombre="Parametros_Inflacion_Mensual"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- LAS DOS MODALIDADES. El selector no es cosmético: decide si se
             edita un campo o catorce. -->
        <div class="card-body border-bottom">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pgenModalidad">Modalidad</label>
                    <select id="pgenModalidad" class="form-select form-select-sm">
                        <option value="CONSTANTE">Constante — un único % para todos los meses</option>
                        <option value="VARIABLE">Variable — un % por mes</option>
                    </select>
                </div>
                <div class="col-md-4" id="pgenConstanteWrap">
                    <label class="form-label form-label-sm" for="pgenPctConstante">
                        % mensual
                    </label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" class="form-control" id="pgenPctConstante">
                        <span class="input-group-text">%</span>
                        <button class="btn btn-primary" id="pgenAplicarConstante">
                            <i class="fas fa-check me-1"></i> Aplicar a todos
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="param-hint mb-0">
                        Aplicar escribe ese % en <strong>cada mes</strong> de la grilla de
                        abajo, que es lo que el cálculo lee. Se hace así —y no resolviendo
                        la constante al calcular— para que los meses que van quedando atrás
                        conserven el valor con el que se proyectaron: si no, cambiar el %
                        movería hacia atrás un ajuste ya hecho.
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <!-- data-orden="no": no es un listado, es un formulario en orden
                     cronológico. Un ajuste suma tres meses consecutivos, y eso
                     sólo se lee si los meses están seguidos. -->
                <table id="pgenTablaInflacion" class="table table-sm table-hover mb-0"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th class="text-center" style="width: 170px;">% mensual</th>
                            <th class="text-center" style="width: 110px;">Cargado como</th>
                            <th>Última edición</th>
                            <th>Se proyecta</th>
                        </tr>
                    </thead>
                    <tbody id="pgenInflacionBody">
                        <!-- Se genera dinámicamente -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                Los <strong>tres primeros meses</strong> de la grilla ya pasaron y no se
                proyectan. Están para que un fletero cuyo valor hora base es anterior a hoy
                pueda resolver su primer ajuste: ese ajuste suma meses ya vencidos, y si no
                se pudieran cargar el mes quedaría sin proyectar para siempre.
                <strong>Ningún mes se borra</strong> al correrse la ventana.
            </small>
        </div>
    </div>

    <!-- ========================================================
         CRONOGRAMA DE PAGOS
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Cronograma de pagos</h5>
                <small class="text-muted">
                    El <strong>2do y el 4to viernes</strong> de cada mes. Si el viernes no es
                    hábil, el pago se corre al día hábil <strong>anterior</strong>.
                    Hoy lo usa sólo Logística Local.
                </small>
            </div>
            <button class="btn btn-sm btn-outline-success" data-exportar="pgenTablaCronograma"
                    data-exportar-nombre="Parametros_Cronograma_Pagos"
                    title="Exportar a Excel lo que se está viendo">
                <i class="fas fa-file-excel me-1"></i> Exportar
            </button>
        </div>

        <div class="card-body py-2 border-bottom">
            <small class="text-muted" id="pgenCronoTramo"></small>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pgenTablaCronograma" class="table table-sm table-hover mb-0"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th>Pago</th>
                            <th>Viernes</th>
                            <th>Calculada</th>
                            <th style="width: 180px;">Fecha de pago</th>
                            <th>Motivo</th>
                            <th class="text-center" style="width: 90px;"></th>
                        </tr>
                    </thead>
                    <tbody id="pgenCronogramaBody">
                        <!-- Se genera dinámicamente -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>Se editan sólo las fechas del tramo diario.</strong> Más allá de él
                el tablero muestra un total por mes, así que correr un pago tres días no
                cambia ninguna columna. Las fechas del resto del horizonte se calculan
                igual —y se ven abajo— porque son las que deciden qué mitad de un importe
                mensual cae dentro del tramo y cuál va a la columna del mes.
            </small>
        </div>
    </div>

    <!-- Las fechas del resto del horizonte: se ven, no se editan. -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Resto del horizonte</h5>
            <small class="text-muted">
                Calculadas, no editables. Cada mes aporta su total a la columna mensual del
                tablero.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pgenTablaCronoResto" class="table table-sm table-hover mb-0"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th>Pago 1 (2do viernes)</th>
                            <th>Pago 2 (4to viernes)</th>
                        </tr>
                    </thead>
                    <tbody id="pgenCronoRestoBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================
         COTIZACIONES — SOLO LECTURA
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                Cotizaciones
                <span class="badge bg-secondary ms-2">Solo lectura</span>
            </h5>
            <small class="text-muted">
                Las dos llegan por API y las mantiene otro proceso. <strong>No se editan
                desde acá</strong>: el único ajuste manual que existe en el módulo es el
                override de cotización por contenedor, en Comercio Exterior.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-7">
                    <h6 class="mb-1">Dólar futuro (ROFEX)</h6>
                    <div class="small text-muted mb-2" id="pgenDfPie"></div>
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table id="pgenTablaDolarFuturo" class="table table-sm mb-0"
                               data-orden="no">
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    <th>Símbolo</th>
                                    <th class="text-end">Cotización</th>
                                </tr>
                            </thead>
                            <tbody id="pgenDfBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <h6 class="mb-1">Dólar oficial BCRA</h6>
                    <div id="pgenBcra"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MOVER UNA FECHA DEL CRONOGRAMA
     Va en un modal propio y no en el diálogo genérico de
     Notificacion porque son DOS datos: la fecha y el motivo. El
     motivo es lo único que, meses después, explica por qué ese
     pago no cayó donde la cuenta decía.
     ============================================================ -->
<div class="modal fade" id="pgenModalFecha" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Mover la fecha de pago
                    <small class="text-muted ms-2" id="pgenFechaTitulo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">

                <!-- Contra qué se está cambiando. Sin esto, quien mueve la
                     fecha no ve de cuánto es la corrección. -->
                <div class="alert alert-light border py-2 px-3 small mb-3" id="pgenFechaContexto"></div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label form-label-sm" for="pgenFechaInput">
                            Fecha de pago
                        </label>
                        <input type="date" class="form-control form-control-sm" id="pgenFechaInput">
                        <div class="form-text">
                            La fecha cargada a mano <strong>no se corre</strong> al día hábil:
                            se respeta tal cual, porque la puso alguien que sabe algo que el
                            calendario no sabe.
                        </div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label form-label-sm" for="pgenFechaMotivo">Motivo</label>
                        <textarea class="form-control form-control-sm" id="pgenFechaMotivo"
                                  rows="3" maxlength="300"
                                  placeholder="Por qué este pago no va en la fecha calculada"></textarea>
                        <div class="form-text">
                            Obligatorio. Es lo único que después explica la fecha.
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="mb-2">
                    Historial
                    <small class="text-muted">— las fechas no se borran: se dan de baja</small>
                </h6>
                <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                    <table class="table table-sm mb-0" id="pgenTablaHistorialFecha">
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
                        <tbody id="pgenHistorialFechaBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-danger" id="pgenFechaVolver">
                    <i class="fas fa-rotate-left me-1"></i> Volver a la fecha calculada
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-secondary"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-sm btn-primary" id="pgenFechaGuardar">
                        <i class="fas fa-check me-1"></i> Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
