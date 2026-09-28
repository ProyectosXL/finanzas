<?php
/**
 * modal_edicion.php
 * El contenedor del modal de edicion de importes. Lo llena Js/ie-edicion.js
 * con el detalle de la celda: un renglon por registro de la tabla.
 */
?>
<div id="ieModalEdicion" class="ie-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ieEdTitulo">
    <div class="ie-modal ie-modal-ancho">
        <div class="ie-modal-header">
            <i class="bi bi-pencil-square" style="font-size:22px;color:var(--ie-accent)"></i>
            <div>
                <h2 id="ieEdTitulo">Registros de la celda</h2>
                <p id="ieEdSubtitulo"></p>
            </div>
            <button class="ie-cerrar" type="button" data-cerrar="ieModalEdicion" title="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="ie-modal-body" id="ieEdCuerpo"></div>
        <div class="ie-modal-footer">
            <span class="ie-nota" style="margin:0 auto 0 0;">
                <i class="bi bi-exclamation-triangle"></i>
                Se escribe en RO_T_RESUMEN_FINAL_IE: lo ven también Rentabilidad por Rubro y cualquier otro lector. Un reproceso lo pisa.
            </span>
            <button class="ie-btn ie-btn-sec" type="button" data-cerrar="ieModalEdicion">Cerrar</button>
        </div>
    </div>
</div>
