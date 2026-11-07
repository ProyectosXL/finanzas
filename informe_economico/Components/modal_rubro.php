<?php
/**
 * modal_rubro.php
 * El modal de Parametros -> Rubros: alta de un rubro (nuevo, o uno que esta
 * en el resumen sin estar en el maestro) y baja / reactivacion. Lo llena
 * Js/ie-parametros.js; aca solo esta el marco, igual que modal_edicion.php.
 */
?>
<div id="ieModalRubro" class="ie-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ieRubTitulo">
    <div class="ie-modal">
        <div class="ie-modal-header">
            <i class="bi bi-tags" style="font-size:22px;color:var(--ie-accent)"></i>
            <div>
                <h2 id="ieRubTitulo">Rubro</h2>
                <p id="ieRubSubtitulo"></p>
            </div>
            <button class="ie-cerrar" type="button" data-cerrar="ieModalRubro" title="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="ie-modal-body ie-form-rubro" id="ieRubCuerpo"></div>
        <div class="ie-modal-footer">
            <button class="ie-btn ie-btn-sec" type="button" data-cerrar="ieModalRubro">Cancelar</button>
            <button class="ie-btn ie-btn-primario" type="button" id="ieRubAceptar">Guardar</button>
        </div>
    </div>
</div>
