<?php
require_once __DIR__ . '/../Class/AyudaBusqueda.php';
$tabName = 'Centro de ayuda';
?>
<!--
    Centro de ayuda: el buscador, los artículos generales, la ayuda de cada
    pestaña por categoría y el artículo completo.

    Los textos NO están acá: son los archivos de Ayuda/*.md, que lee
    Class/Ayuda.php y pide Js/ayuda.js (AyudaCentro). Esta pestaña es sólo el
    armazón. Ver "La ayuda funcional" en README-cashflow.md.

    La dirección se puede copiar y compartir: ?ayuda=<artículo>#<sección>
    abre directo acá (index.php la toma como pestaña inicial).
-->
<div class="tab-ayuda" id="ayudaCentro">

    <div class="cargando-slot" id="ayudaCargando" data-cargando="Cargando la ayuda…"></div>

    <!-- ===================== INICIO ===================== -->
    <div id="ayudaInicio" style="display: none;">

        <div class="ayuda-hero">
            <h2 class="ayuda-hero-titulo">¿En qué te ayudamos?</h2>
            <div class="ayuda-buscador">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="ayudaBuscar" class="form-control form-control-lg"
                       placeholder="Buscá en la ayuda: dólar, cuotas, ya pagada…"
                       aria-label="Buscar en la ayuda" autocomplete="off">
            </div>
            <div class="ayuda-frecuentes">
                <span class="text-muted small">Búsquedas frecuentes:</span>
                <?php foreach (AyudaBusqueda::FRECUENTES as $f): ?>
                    <button type="button" class="btn btn-sm btn-light ayuda-frecuente"
                            data-buscar="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></button>
                <?php endforeach; ?>
            </div>
            <div id="ayudaResultados" class="ayuda-resultados" aria-live="polite"></div>
        </div>

        <section class="ayuda-bloque">
            <h3 class="ayuda-bloque-titulo"><i class="fas fa-flag-checkered me-2"></i>Primeros pasos</h3>
            <div id="ayudaGenerales" class="row g-3"></div>
        </section>

        <section class="ayuda-bloque">
            <h3 class="ayuda-bloque-titulo"><i class="fas fa-layer-group me-2"></i>Ayuda por módulo</h3>
            <div id="ayudaModulos" class="row g-3"></div>
        </section>

        <section class="ayuda-bloque">
            <h3 class="ayuda-bloque-titulo"><i class="fas fa-circle-question me-2"></i>Preguntas frecuentes</h3>
            <div id="ayudaPreguntas" class="ayuda-preguntas-lista"></div>
        </section>
    </div>

    <!-- ===================== ARTÍCULO ===================== -->
    <div id="ayudaArticulo" style="display: none;">
        <nav aria-label="Ruta del artículo" class="ayuda-ruta">
            <a href="?ayuda=" class="ayuda-ir-inicio">Centro de ayuda</a>
            <span id="ayudaRutaCategoria"></span>
            <span id="ayudaRutaTitulo"></span>
        </nav>

        <div class="row g-4">
            <aside class="col-lg-3">
                <div class="ayuda-lateral">
                    <!-- En pantalla angosta queda arriba y plegado: lo abre o lo
                         cierra Js/ayuda.js según el ancho. -->
                    <details class="ayuda-indice" id="ayudaIndiceDetalle" open>
                        <summary>En este artículo</summary>
                        <nav id="ayudaIndice" aria-label="Secciones del artículo"></nav>
                    </details>
                    <dl class="ayuda-ficha" id="ayudaFicha"></dl>
                </div>
            </aside>

            <article class="col-lg-9 ayuda-articulo">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <h1 class="ayuda-titulo" id="ayudaTitulo"></h1>
                    <button type="button" class="btn btn-sm btn-outline-secondary ayuda-copiar"
                            data-copiar="" title="Copiar la dirección del artículo">
                        <i class="fas fa-link"></i> Copiar dirección
                    </button>
                </div>
                <p class="ayuda-bajada" id="ayudaBajada"></p>
                <div id="ayudaEstado"></div>
                <div id="ayudaErrores"></div>
                <div id="ayudaCuerpo" class="ayuda-contenido"></div>
            </article>
        </div>
    </div>

    <div id="ayudaFalla"></div>
</div>

<script>
    /* Js/ayuda.js se carga en el <head> de index.php. Esta pestaña puede
       llegar incluida en línea -la dirección ?ayuda= la hace inicial- y ahí
       todavía no están jQuery ni main.js: se espera a que termine la página. */
    (function() {
        function iniciar() { AyudaCentro.iniciar(); }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', iniciar);
        } else {
            iniciar();
        }
    })();
</script>
