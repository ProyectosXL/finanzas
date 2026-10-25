<?php
/**
 * Parametros -> Compras Exterior (codigo interno COMPRAS_PROY)
 *
 * Archivo y JS propios, como los demas modulos de esta pestana, y clases con
 * prefijo pcpr-. Parametros.js busca .param-input en TODO el documento, asi que
 * dos modulos compartiendo esa clase se pisarian el guardado.
 */
?>

<div class="pcpr-modulo">

    <div class="modulo-descripcion mb-3" id="pcprDescripcion"></div>

    <!-- Avisos de configuracion pendiente: el script sin correr. La pantalla no
         rompe, avisa. -->
    <div id="pcprAvisos"></div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Cómo se proyecta</h5>
                <small class="text-muted">
                    Cuántos meses se proyectan, con cuánta historia se arma la cuota y
                    cuántos días antes de la llegada se paga cada cosa
                </small>
            </div>
            <button id="pcprBtnRefresh" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-sync-alt me-1"></i> Actualizar
            </button>
        </div>
        <div class="card-body">
            <div class="row g-3" id="pcprGrid">
                <!-- Se genera dinámicamente -->
            </div>
        </div>
    </div>

    <!-- LA CADENA, ESCRITA. Los tres parámetros de días no se leen solos: hay
         que verlos juntos para entender que mueven la misma fecha en dos
         direcciones. Sin esto, "47" y "2" son dos números sueltos. -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Cómo quedan las fechas</h5>
            <small class="text-muted">
                Con los valores cargados arriba, para el mes que se está mirando
            </small>
        </div>
        <div class="card-body">
            <div class="pcpr-cadena" id="pcprCadena">
                <!-- Se genera dinámicamente -->
            </div>
            <small class="text-muted d-block mt-3">
                Los valores iniciales salen de la cadena de Comercio Exterior
                (<code>RO_T_IMPORTACIONES_PARAM_CRONOGRAMA</code>): embarque + 5 al pago y
                embarque + 52 a la recepción. <strong>Pero no la leen</strong>: si allá
                cambian los días, acá no cambia nada hasta que alguien lo decida. Los
                contenedores reales tienen sus fechas editadas a mano una por una, así que
                esa cadena es un valor por defecto y no una regla.
            </small>
        </div>
    </div>

    <!-- EL % IMPORTADO POR RUBRO. Qué parte de cada rubro del presupuesto se
         compra en el exterior. Reemplaza al filtro de cuero que tenía la vista
         del presupuesto. La card entera la dibuja el JS: sin el script dice
         qué falta en vez de mostrar una tabla que fallaría al guardar. -->
    <div id="pcprRubros"></div>
</div>

<!-- Historial de un rubro: todos los % que tuvo, quién y cuándo. -->
<div class="modal fade" id="modalHistorialRubroPct" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Historial del % importado
                    <small class="text-muted ms-2" id="pcprHistTitulo"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0">
                <div class="cargando-slot" id="pcprHistSpinner" data-cargando="Leyendo el historial…"></div>
                <table class="table table-sm mb-0" id="pcprHistTabla" style="display: none;">
                    <thead>
                        <tr>
                            <th class="text-end">% importado</th>
                            <th>Desde</th>
                            <th>Hasta</th>
                        </tr>
                    </thead>
                    <tbody id="pcprHistBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Parametros-Compras_proy.js?v=<?php echo time(); ?>"></script>
