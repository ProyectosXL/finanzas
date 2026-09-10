<?php $tabName = 'Exportaciones Tasky'; ?>
<!--
    Tasky es la razón social del grupo en Uruguay.

    Por ahora sólo el ítem de menú y el aviso de sección en construcción: no hay
    formulario ni datos. Menu::esPlaceholder() lo detecta por este include y lo
    marca como pendiente en el menú, sin que haya que declarar nada aparte.

    En el tablero, la fila EXPORTACIONES sigue apuntando a un proveedor con
    'disponible' => false: se muestra en cero y el tablero avisa. Es a
    propósito, para que el cuadro tenga desde el primer día la forma completa
    del Excel y se vea qué falta.
-->
<div class="tab-exportaciones_tasky">
    <?php include __DIR__ . '/../Components/tab_placeholder.php'; ?>
</div>
