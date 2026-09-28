<?php
/**
 * locales.php
 * IE por Locales: una columna por local propio con datos, subtotal LOCALES,
 * canales, aperturas de Ecommerce con su subtotal, Otros y Total. "Mostrar %"
 * es propio de esta pestana (apagado por defecto: con treinta columnas, el %
 * al lado de cada una duplica el ancho).
 */
?>
<div class="ie-barra-tab">
    <div class="ie-titulo">Informe Económico por Local</div>
    <label class="ie-switch"><input type="checkbox" id="iePct"> Mostrar %</label>
    <div class="ie-meta" id="ieMeta"></div>
</div>
<div class="ie-avisos" id="ieAvisos"></div>
<div id="ieCuerpo" data-vista="locales"></div>
