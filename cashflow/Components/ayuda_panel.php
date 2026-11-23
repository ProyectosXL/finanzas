<!--
    El panel lateral con la ayuda de la pestaña abierta. Lo abre el botón
    "Ayuda" del encabezado (Components/header.php) y lo llena Js/ayuda.js
    (AyudaPanel) con el artículo de la pestaña: Para qué sirve, De dónde salen
    los datos, La pantalla y las preguntas frecuentes.

    UNO SOLO PARA TODAS LAS PESTAÑAS, en index.php y no en cada una: las
    pestañas se reemplazan por AJAX y el panel tiene que sobrevivir.

    data-bs-backdrop="false" y data-bs-scroll="true": sin fondo oscuro y sin
    bloquear el desplazamiento, así la pestaña sigue visible y se puede
    seguir usando con la ayuda abierta al costado. Bootstrap tampoco encierra
    el foco en el panel con esa combinación.
-->
<div class="offcanvas offcanvas-end ayuda-panel" tabindex="-1" id="ayudaPanel"
     aria-labelledby="ayudaPanelTitulo" data-bs-backdrop="false" data-bs-scroll="true">
    <div class="offcanvas-header flex-column align-items-stretch gap-2">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <div>
                <div class="small text-muted"><i class="fas fa-circle-question me-1"></i>Ayuda de la pestaña</div>
                <h5 class="offcanvas-title" id="ayudaPanelTitulo">Ayuda</h5>
                <div id="ayudaPanelEstado" class="small"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <input type="search" id="ayudaPanelBuscar" class="form-control form-control-sm"
               placeholder="Buscar en esta ayuda…" aria-label="Buscar en la ayuda de esta pestaña" autocomplete="off">
    </div>
    <div class="offcanvas-body">
        <div id="ayudaPanelCargando"></div>
        <div id="ayudaPanelErrores"></div>
        <div id="ayudaPanelCuerpo" class="ayuda-contenido"></div>
        <div id="ayudaPanelPreguntas" class="ayuda-panel-preguntas"></div>
    </div>
    <div class="ayuda-panel-pie">
        <a href="?ayuda=" class="btn btn-sm btn-primary" id="ayudaPanelCompleto">
            <i class="fas fa-book-open me-1"></i>Ver el artículo completo
        </a>
        <a href="?ayuda=" class="btn btn-sm btn-outline-secondary" id="ayudaPanelCentro">
            <i class="fas fa-life-ring me-1"></i>Ir al Centro de ayuda
        </a>
    </div>
</div>
