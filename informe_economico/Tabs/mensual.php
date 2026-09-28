<?php
/**
 * mensual.php
 * Evolucion Mensual: las mismas filas, una columna por mes y el total, para
 * el canal elegido.
 *
 * Con el comparativo, cada mes muestra UNA cosa a la vez (actual, anio
 * anterior o variacion) segun el selector; el Total muestra las tres. Tres
 * columnas por mes con doce meses son casi cuarenta columnas: ilegible.
 */
?>
<div class="ie-barra-tab">
    <div class="ie-titulo">Evolución Mensual</div>
    <div class="ie-filtro">
        <label class="ie-filtro-label" for="ieCanal"><i class="bi bi-diagram-3"></i> Canal</label>
        <select id="ieCanal" class="ie-select">
            <option value="TODOS">Todos</option>
            <option value="LOCALES">Locales</option>
            <option value="FRANQUICIAS">Franquicias</option>
            <option value="MAYORISTAS">Mayoristas</option>
            <option value="ECOMMERCE">Ecommerce</option>
            <option value="OTROS">Otros ingresos</option>
        </select>
    </div>
    <div class="ie-filtro" id="ieModoMesWrap" style="display:none;">
        <span class="ie-filtro-label"><i class="bi bi-eye"></i> Cada mes muestra</span>
        <div class="ie-toggle-group">
            <input type="radio" name="ieModoMes" id="ieModoActual" value="v" checked><label for="ieModoActual">Actual</label>
            <input type="radio" name="ieModoMes" id="ieModoAA" value="aa"><label for="ieModoAA">Año anterior</label>
            <input type="radio" name="ieModoMes" id="ieModoVar" value="var"><label for="ieModoVar">Var %</label>
        </div>
    </div>
    <div class="ie-meta" id="ieMeta"></div>
</div>
<div class="ie-avisos" id="ieAvisos"></div>
<div id="ieCuerpo" data-vista="mensual"></div>
