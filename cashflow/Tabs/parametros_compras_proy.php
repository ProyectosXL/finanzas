<?php
/**
 * Parametros -> Compras Proyectadas
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
</div>

<script src="Js/Parametros-Compras_proy.js?v=<?php echo time(); ?>"></script>
