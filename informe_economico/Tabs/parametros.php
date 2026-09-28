<?php
/**
 * parametros.php
 * Estructura de filas, categoria de rubros, umbrales del semaforo y el
 * parametro de alerta. Ver requiere ie.tab.parametros; guardar, ademas,
 * ie.editar (el controller lo vuelve a chequear).
 */
?>
<div class="ie-barra-tab">
    <div class="ie-titulo">Parámetros</div>
    <?php if (!$puedeEditar): ?>
        <span class="ie-nota"><i class="bi bi-lock"></i> Solo lectura: tu usuario no tiene el permiso ie.editar.</span>
    <?php endif; ?>
</div>
<div class="ie-param-nav">
    <button class="ie-btn ie-btn-sec ie-btn-sm active" data-sec="estructura" type="button"><i class="bi bi-list-ol"></i> Estructura</button>
    <button class="ie-btn ie-btn-sec ie-btn-sm" data-sec="rubros" type="button"><i class="bi bi-tags"></i> Rubros</button>
    <button class="ie-btn ie-btn-sec ie-btn-sm" data-sec="umbrales" type="button"><i class="bi bi-stoplights"></i> Umbrales del semáforo</button>
    <button class="ie-btn ie-btn-sec ie-btn-sm" data-sec="alerta" type="button"><i class="bi bi-bell"></i> Alerta</button>
</div>
<div class="ie-avisos" id="ieAvisos"></div>
<div id="ieCuerpo" data-vista="parametros"></div>
