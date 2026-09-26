<?php
/**
 * Parametros -> Logistica
 *
 * El maestro de fleteros: quien es fletero, cuantas horas por mes trabaja y
 * cuanto vale su hora.
 *
 * Archivo y JS propios, como los demas modulos de esta pestana, y clases con
 * prefijo plog-. Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO
 * el documento, asi que dos modulos compartiendo esas clases se pisarian el
 * guardado.
 *
 * EL ALTA ES LA MISMA QUE LA DEL MAESTRO DE PROVEEDORES LOCALES: se busca por
 * codigo o por nombre en CPA01 y se elige de la lista. El nombre sale de ahi y
 * no se tipea.
 */
?>

<div class="plog-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="plogDescripcion"></div>
        <button id="plogBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos: el script sin correr, CPA01 que no responde. La pantalla no
         rompe, avisa. -->
    <div id="plogAvisos"></div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Fleteros</h5>
                <small class="text-muted">
                    A cada uno se le paga por hora, con un régimen mensual constante. El valor
                    hora base es el <strong>ya ajustado</strong>, y rige desde su mes base y por
                    los dos meses siguientes.
                </small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-success" data-exportar="plogTabla"
                        data-exportar-nombre="Parametros_Fleteros"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
                <button id="plogBtnNuevo" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Agregar fletero
                </button>
            </div>
        </div>

        <!-- ========================================================
             EL ALTA
             Mismo patrón que el alta manual del maestro de Proveedores
             Locales: se busca en CPA01 por código o por nombre y se
             elige de la lista. El nombre NO se tipea.
             ======================================================== -->
        <div class="card-body border-bottom" id="plogFormNuevo" style="display: none;">
            <div class="row g-2 align-items-end">
                <div class="col-md-5 position-relative">
                    <label class="form-label form-label-sm" for="plogBuscar">
                        Proveedor de Tango
                    </label>
                    <input type="text" id="plogBuscar" class="form-control form-control-sm"
                           placeholder="Código o nombre, desde 2 letras…" autocomplete="off">
                    <!-- El desplegable del autocomplete -->
                    <div id="plogSugerencias" class="list-group position-absolute w-100 shadow"
                         style="z-index: 1050; max-height: 260px; overflow-y: auto; display: none;">
                    </div>
                    <div class="form-text" id="plogElegido">
                        El nombre sale de CPA01 y no se edita.
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="plogHoras">Horas por mes</label>
                    <input type="number" step="0.5" min="0" id="plogHoras"
                           class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="plogValor">Valor hora base</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" id="plogValor"
                               class="form-control">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="plogMesBase">Mes base</label>
                    <input type="month" id="plogMesBase" class="form-control form-control-sm">
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button id="plogBtnAgregar" class="btn btn-sm btn-primary flex-fill" disabled>
                        <i class="fas fa-check"></i>
                    </button>
                    <button id="plogBtnCancelar" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="param-hint mt-2">
                Los tres números son <strong>opcionales en el alta</strong>. Sin ellos el fletero
                queda cargado pero <strong>no se proyecta</strong>, y la pestaña Logística Local
                lo avisa: no se lo cuenta como cero.
                El <strong>mes base</strong> fija el calendario de los ajustes —mes base + 3,
                + 6, + 9…— así que no es la fecha de alta, es el mes del último ajuste pactado.
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <!-- data-orden="no": es un formulario, no un listado. -->
                <table id="plogTabla" class="table table-hover mb-0" data-orden="no">
                    <thead>
                        <tr>
                            <th>Fletero</th>
                            <th class="text-end" style="width: 130px;">Horas/mes</th>
                            <th class="text-end" style="width: 160px;">Valor hora base</th>
                            <th class="text-center" style="width: 150px;">Mes base</th>
                            <th class="text-center" style="width: 110px;">Activo</th>
                            <th>Última edición</th>
                        </tr>
                    </thead>
                    <tbody id="plogBody"></tbody>
                </table>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>Dar de baja no borra.</strong> Un fletero que dejó de trabajar conserva
                las horas y el valor hora con los que se proyectó, y se puede reactivar.
                <br>
                <strong>La exclusión de Cuentas a Pagar Locales se carga aparte</strong>, desde el
                maestro de Proveedores Locales. Mientras un fletero no esté excluido allá, el
                tablero cuenta su pago dos veces: una proyectada acá y otra por su deuda real.
                Logística Local lo avisa.
            </small>
        </div>
    </div>
</div>

<script src="Js/Parametros-Logistica.js?v=<?php echo time(); ?>"></script>
