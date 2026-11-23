/**
 * La ayuda funcional del cashflow: el Centro de ayuda (Tabs/ayuda.php).
 *
 * Los textos no están acá ni en el HTML: son los archivos de Ayuda/*.md, que
 * el servidor lee, valida y convierte en HTML ya escapado
 * (Controller/AyudaController.php, Class/AyudaRender.php). Este archivo sólo
 * pide, pinta y navega. Lo que llega como HTML del servidor (las secciones, la
 * bajada, los fragmentos con el término resaltado) se inserta tal cual porque
 * ya pasó por el renderizador; todo lo demás se escapa acá con esc().
 *
 * LA DIRECCIÓN SE PUEDE COMPARTIR: ?ayuda=<artículo>#<sección>. index.php ve
 * el parámetro y abre el Centro como pestaña inicial; acá se lee para saber
 * qué mostrar, y se actualiza con history.pushState al navegar, así la barra
 * de direcciones dice siempre dónde se está. Ver AyudaRender::direccion().
 *
 * Se carga en el <head> de index.php, como avisos-panel.js: el Centro puede
 * llegar incluido en línea (cuando la dirección trae ?ayuda=) o por AJAX.
 * Usa pedirJson() de main.js, Cargando y Notificacion, que existen cuando se
 * llama a iniciar() (después de DOMContentLoaded).
 */
var AyudaCentro = (function() {
    'use strict';

    var URL = 'Controller/AyudaController.php';

    /** getCentro, mientras el Centro esté abierto: volver al inicio no pide nada */
    var centro = null;

    /** El artículo pintado, para no volver a pedirlo al cambiar de sección */
    var actual = null;

    var observador = null;
    var temporizador = null;
    var busqueda = 0;

    function el(id) {
        return document.getElementById(id);
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ================================================================
       LA DIRECCIÓN
       ================================================================ */

    /** El artículo de la dirección: null sin ?ayuda=, '' en el inicio */
    function articuloDeDireccion() {
        var m = /[?&]ayuda=([^&#]*)/.exec(window.location.search);

        return m === null ? null : decodeURIComponent(m[1]);
    }

    function anclaDeDireccion() {
        return decodeURIComponent(window.location.hash.replace(/^#/, ''));
    }

    /** ?ayuda=articulo#ancla; la misma regla que AyudaRender::direccion() */
    function direccion(articulo, ancla) {
        return '?ayuda=' + encodeURIComponent(articulo || '') + (ancla ? '#' + encodeURIComponent(ancla) : '');
    }

    /** La dirección completa, para copiar */
    function absoluta(relativa) {
        return window.location.origin + window.location.pathname + relativa;
    }

    /* ================================================================
       ARRANQUE Y NAVEGACIÓN
       ================================================================ */

    function iniciar() {
        if (!el('ayudaCentro')) {
            return;
        }

        centro = null;
        actual = null;
        conectar();
        mostrarSegunDireccion();
    }

    function mostrarSegunDireccion() {
        var art = articuloDeDireccion();

        if (art) {
            abrirArticulo(art, anclaDeDireccion());
        } else {
            // Abierto desde el menú: la barra queda con ?ayuda= para que
            // copiarla también lleve al Centro.
            if (art === null && window.history.replaceState) {
                window.history.replaceState(null, '', direccion(''));
            }

            mostrarInicio();
        }
    }

    /**
     * Ir a un artículo (o al inicio, con nombre vacío) desde cualquier lado:
     * el Centro, el panel lateral o un enlace de un artículo.
     *
     * Si el Centro no está abierto, se cambia la dirección primero y después
     * se abre la pestaña como lo hace el menú: el Centro, al iniciar, lee la
     * dirección y sabe a dónde ir.
     */
    function ir(articulo, ancla) {
        var dir = direccion(articulo, ancla);

        if (window.history.pushState) {
            window.history.pushState(null, '', dir);
        }

        if (el('ayudaCentro')) {
            mostrarSegunDireccion();
            return;
        }

        var link = document.querySelector('.menu-link[data-tab="ayuda"]');

        if (link) {
            link.click();
        } else {
            window.location.href = dir;
        }
    }

    function conectar() {
        var raiz = el('ayudaCentro');

        raiz.addEventListener('click', function(e) {
            var a = e.target.closest('[data-ayuda-ir], .ayuda-ir-inicio');

            if (a && raiz.contains(a)) {
                e.preventDefault();
                ir(a.getAttribute('data-ayuda-ir') || '', a.getAttribute('data-ancla') || '');
                return;
            }

            var c = e.target.closest('[data-copiar]');

            if (c && raiz.contains(c)) {
                e.preventDefault();
                copiar(absoluta(c.getAttribute('data-copiar')));
                return;
            }

            var f = e.target.closest('.ayuda-frecuente');

            if (f) {
                el('ayudaBuscar').value = f.getAttribute('data-buscar');
                buscar();
                el('ayudaBuscar').focus();
            }
        });

        el('ayudaBuscar').addEventListener('input', function() {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 250);
        });
    }

    // Atrás y adelante del navegador, mientras se está en el Centro.
    window.addEventListener('popstate', function() {
        if (el('ayudaCentro') && articuloDeDireccion() !== null) {
            mostrarSegunDireccion();
        }
    });

    function falla(e) {
        Cargando.ocultar('ayudaCargando');
        el('ayudaFalla').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>'
            + 'No se pudo cargar la ayuda: ' + esc(e && e.message ? e.message : e) + '</div>';
    }

    /* ================================================================
       EL INICIO
       ================================================================ */

    function mostrarInicio() {
        el('ayudaArticulo').style.display = 'none';
        el('ayudaFalla').innerHTML = '';
        actual = null;

        if (centro) {
            pintarInicio();
            return;
        }

        Cargando.mostrar('ayudaCargando', { titulo: 'Cargando la ayuda…' });

        pedirJson(URL + '?action=getCentro')
            .then(function(d) {
                centro = d;
                Cargando.ocultar('ayudaCargando');
                pintarInicio();
            })['catch'](falla);
    }

    function chip(estado, etiqueta) {
        return '<span class="ayuda-chip ayuda-chip-' + esc(estado) + '">' + esc(etiqueta) + '</span>';
    }

    function pintarInicio() {
        var html = '';
        var i;
        var j;

        for (i = 0; i < centro.generales.length; i++) {
            var g = centro.generales[i];

            html += '<div class="col-md-6 col-xl-3"><a class="ayuda-tarjeta" href="' + esc(direccion(g.articulo))
                + '" data-ayuda-ir="' + esc(g.articulo) + '"><span class="ayuda-tarjeta-icono" aria-hidden="true"><i class="fas '
                + esc(g.icono) + '"></i></span><span class="ayuda-tarjeta-titulo">' + esc(g.titulo)
                + '</span><span class="ayuda-tarjeta-bajada">' + g.bajada + '</span>'
                + (g.estado !== 'completa' ? chip(g.estado, g.etiqueta) : '') + '</a></div>';
        }

        el('ayudaGenerales').innerHTML = html;

        html = '';

        for (i = 0; i < centro.modulos.length; i++) {
            var m = centro.modulos[i];

            html += '<div class="col-md-6 col-xl-4"><div class="ayuda-modulo"><h4><i class="fas ' + esc(m.icono)
                + ' me-2"></i>' + esc(m.nombre) + '</h4><ul>';

            for (j = 0; j < m.items.length; j++) {
                var it = m.items[j];

                // Sin archivo no hay a dónde ir: se ve, pero no se abre.
                html += '<li>' + (it.articulo
                    ? '<a href="' + esc(direccion(it.articulo)) + '" data-ayuda-ir="' + esc(it.articulo) + '">'
                        + esc(it.nombre) + '</a>'
                    : '<span class="ayuda-modulo-sin" aria-disabled="true">' + esc(it.nombre) + '</span>')
                    + chip(it.estado, it.etiqueta) + '</li>';
            }

            html += '</ul></div></div>';
        }

        el('ayudaModulos').innerHTML = html;

        html = '';

        for (i = 0; i < centro.preguntas.length; i++) {
            var p = centro.preguntas[i];

            html += '<a class="ayuda-pregunta-link" href="' + esc(p.enlace) + '" data-ayuda-ir="general_tablero"'
                + ' data-ancla="' + esc(p.ancla) + '"><i class="fas fa-chevron-right me-2"></i>' + esc(p.titulo) + '</a>';
        }

        el('ayudaPreguntas').innerHTML = html || '<p class="text-muted">Todavía no hay preguntas frecuentes.</p>';
        el('ayudaInicio').style.display = '';
        window.scrollTo(0, 0);
    }

    /* ================================================================
       LA BÚSQUEDA
       ================================================================ */

    function buscar() {
        var q = el('ayudaBuscar').value.replace(/^\s+|\s+$/g, '');
        var cont = el('ayudaResultados');
        var n = ++busqueda;

        if (q.length < 2) {
            cont.innerHTML = '';
            return;
        }

        pedirJson(URL + '?action=buscar&q=' + encodeURIComponent(q))
            .then(function(r) {
                // Una respuesta vieja que llega tarde no pisa la de lo último escrito.
                if (n !== busqueda) {
                    return;
                }

                if (!r.length) {
                    cont.innerHTML = '<p class="ayuda-sin-resultados">No encontramos nada con «' + esc(q) + '».</p>';
                    return;
                }

                var html = '';

                for (var i = 0; i < r.length; i++) {
                    var x = r[i];
                    var ruta = [];

                    for (var k = 0; k < x.ruta.length; k++) {
                        ruta.push(esc(x.ruta[k]));
                    }

                    html += '<a class="ayuda-resultado" href="' + esc(x.enlace) + '" data-ayuda-ir="' + esc(x.articulo)
                        + '" data-ancla="' + esc(x.ancla) + '"><span class="ayuda-resultado-ruta">' + ruta.join(' › ')
                        + '</span><span class="ayuda-resultado-titulo">' + x.titulo_html + '</span>'
                        + '<span class="ayuda-resultado-fragmento">' + x.fragmento + '</span></a>';
                }

                cont.innerHTML = html;
            })['catch'](function(e) {
                if (n === busqueda) {
                    cont.innerHTML = '<p class="text-danger small">No se pudo buscar: ' + esc(e.message) + '</p>';
                }
            });
    }

    /* ================================================================
       EL ARTÍCULO
       ================================================================ */

    function abrirArticulo(nombre, ancla) {
        el('ayudaInicio').style.display = 'none';
        el('ayudaFalla').innerHTML = '';

        if (actual === nombre) {
            el('ayudaArticulo').style.display = '';
            irAncla(ancla);
            return;
        }

        el('ayudaArticulo').style.display = 'none';
        Cargando.mostrar('ayudaCargando', { titulo: 'Abriendo el artículo…' });

        pedirJson(URL + '?action=getArticulo&nombre=' + encodeURIComponent(nombre))
            .then(function(a) {
                Cargando.ocultar('ayudaCargando');
                actual = nombre;
                pintarArticulo(a);
                irAncla(ancla);
            })['catch'](falla);
    }

    function pintarArticulo(a) {
        el('ayudaRutaCategoria').textContent = ' › ' + a.categoria;
        el('ayudaRutaTitulo').textContent = ' › ' + a.titulo;
        el('ayudaTitulo').textContent = a.titulo;
        el('ayudaBajada').innerHTML = a.bajada;
        document.querySelector('#ayudaArticulo .ayuda-copiar').setAttribute('data-copiar', a.direccion);

        el('ayudaEstado').innerHTML = a.estado !== 'completa'
            ? '<p class="ayuda-estado">' + chip(a.estado, a.etiqueta) + ' Actualizado el ' + esc(a.actualizado)
                + '. El texto todavía lo están validando quienes usan la pantalla.</p>'
            : '';

        // Un artículo con errores se muestra igual: lo que está mal se ve
        // como texto, y acá se dice qué.
        var errores = '';

        for (var e = 0; e < a.errores.length; e++) {
            errores += '<li>' + esc(a.errores[e]) + '</li>';
        }

        el('ayudaErrores').innerHTML = errores
            ? '<div class="alert alert-warning small"><i class="fas fa-triangle-exclamation me-2"></i>'
                + 'Este artículo tiene errores de formato y puede verse incompleto:<ul class="mb-0">' + errores + '</ul></div>'
            : '';

        var indice = '';
        var i;

        for (i = 0; i < a.indice.length; i++) {
            indice += '<a href="#' + esc(a.indice[i].ancla) + '" class="ayuda-indice-' + a.indice[i].nivel
                + '" data-ancla="' + esc(a.indice[i].ancla) + '">' + esc(a.indice[i].titulo) + '</a>';
        }

        el('ayudaIndice').innerHTML = indice;

        el('ayudaFicha').innerHTML = '<dt>Usuarios</dt><dd>' + esc(a.usuarios) + '</dd>'
            + '<dt>Actualizado</dt><dd>' + esc(a.actualizado) + '</dd>'
            + '<dt>Responsable</dt><dd>' + esc(a.responsable) + '</dd>';

        var cuerpo = '';

        for (i = 0; i < a.secciones.length; i++) {
            cuerpo += a.secciones[i].html;
        }

        el('ayudaCuerpo').innerHTML = cuerpo;

        // Cada título lleva su botón para copiar la dirección de la sección.
        var titulos = el('ayudaCuerpo').querySelectorAll('h2[id], h3[id]');

        for (i = 0; i < titulos.length; i++) {
            titulos[i].insertAdjacentHTML('beforeend', ' <button type="button" class="ayuda-copiar-seccion"'
                + ' data-copiar="' + esc(direccion(a.articulo, titulos[i].id)) + '"'
                + ' title="Copiar la dirección de esta sección" aria-label="Copiar la dirección de esta sección">'
                + '<i class="fas fa-link"></i></button>');
        }

        // Ancho: el índice abierto al costado; angosto, arriba y plegado.
        el('ayudaIndiceDetalle').open = window.innerWidth >= 992;

        el('ayudaArticulo').style.display = '';
        seguirSecciones();
    }

    /** Va a una sección o pregunta; una pregunta se abre */
    function irAncla(ancla) {
        var destino = ancla ? document.getElementById(ancla) : null;

        if (!destino || !el('ayudaCuerpo').contains(destino)) {
            window.scrollTo(0, 0);
            return;
        }

        if (destino.tagName === 'DETAILS') {
            destino.open = true;
        }

        destino.scrollIntoView({ block: 'start' });
    }

    /**
     * Marca en el índice la sección que se está leyendo.
     *
     * IntersectionObserver y no un cálculo en el scroll: no hace falta saber
     * qué elemento scrollea (acá es la ventana) ni medir en cada movimiento.
     * La franja de arriba deja afuera el encabezado fijo de la página.
     */
    function seguirSecciones() {
        if (observador) {
            observador.disconnect();
        }

        if (!window.IntersectionObserver) {
            return;
        }

        observador = new IntersectionObserver(function(entradas) {
            for (var i = 0; i < entradas.length; i++) {
                if (entradas[i].isIntersecting) {
                    marcarIndice(entradas[i].target.id);
                }
            }
        }, { rootMargin: '-90px 0px -65% 0px' });

        var titulos = el('ayudaCuerpo').querySelectorAll('h2[id], h3[id]');

        for (var j = 0; j < titulos.length; j++) {
            observador.observe(titulos[j]);
        }
    }

    function marcarIndice(ancla) {
        var links = el('ayudaIndice').querySelectorAll('a');

        for (var i = 0; i < links.length; i++) {
            links[i].classList.toggle('activo', links[i].getAttribute('data-ancla') === ancla);
        }
    }

    /**
     * Copia un texto al portapapeles.
     *
     * navigator.clipboard sólo existe en un contexto seguro (https o
     * localhost); el cashflow puede estar en http en la red interna, así que
     * sin él se usa el camino viejo de un textarea y execCommand.
     */
    function copiar(texto) {
        var ok = function() { Notificacion.exito('Dirección copiada.'); };
        var viejo = function() {
            var t = document.createElement('textarea');

            t.value = texto;
            t.setAttribute('readonly', '');
            t.style.position = 'fixed';
            t.style.opacity = '0';
            document.body.appendChild(t);
            t.select();

            try {
                if (document.execCommand('copy')) {
                    ok();
                } else {
                    Notificacion.advertencia('No se pudo copiar. La dirección es: ' + texto);
                }
            } catch (e) {
                Notificacion.advertencia('No se pudo copiar. La dirección es: ' + texto);
            }

            document.body.removeChild(t);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(ok, viejo);
        } else {
            viejo();
        }
    }

    return {
        iniciar: iniciar,
        ir: ir,
        direccion: direccion,
        copiar: copiar,
        esc: esc
    };
})();

/**
 * El panel lateral: la ayuda de la pestaña abierta, sin salir de ella
 * (Components/ayuda_panel.php, que se abre con el botón "Ayuda" del
 * encabezado).
 *
 * QUÉ PESTAÑA ESTÁ ABIERTA lo dice el atributo data-tab del <body>, que pone
 * index.php y actualiza updateHeader() de main.js en cada cambio de pestaña.
 * No hay una segunda variable que mantener.
 *
 * CARGA CUANDO SE ABRE, Y UNA VEZ POR PESTAÑA, como las sub-pestañas de
 * Parámetros: abrir el panel en una pestaña lo pide; volver a abrirlo ahí, no.
 * El caché dura lo que la página. Si el panel queda abierto y se cambia de
 * pestaña, se pide la ayuda de la nueva.
 *
 * EL BUSCADOR le pregunta al servidor qué secciones y preguntas contienen el
 * texto y las recibe con el texto marcado: el criterio sin acentos ni
 * mayúsculas está escrito una sola vez, en PHP, con su prueba.
 */
var AyudaPanel = (function() {
    'use strict';

    var URL = 'Controller/AyudaController.php';
    var cache = {};
    var tabMostrada = null;
    var temporizador = null;
    var busqueda = 0;
    var esc = AyudaCentro.esc;

    function el(id) {
        return document.getElementById(id);
    }

    function tabActual() {
        return document.body.getAttribute('data-tab') || '';
    }

    function panel() {
        return bootstrap.Offcanvas.getOrCreateInstance(el('ayudaPanel'));
    }

    function abierto() {
        return el('ayudaPanel').classList.contains('show');
    }

    function abrir() {
        panel().show();
        cargar(tabActual());
    }

    function cargar(tab) {
        el('ayudaPanelBuscar').value = '';
        tabMostrada = tab;

        if (cache[tab]) {
            pintar(tab, cache[tab], '');
            return;
        }

        el('ayudaPanelTitulo').textContent = 'Ayuda';
        el('ayudaPanelEstado').innerHTML = '';
        el('ayudaPanelCuerpo').innerHTML = '';
        el('ayudaPanelPreguntas').innerHTML = '';
        el('ayudaPanelErrores').innerHTML = '';
        Cargando.mostrar('ayudaPanelCargando', { titulo: 'Cargando la ayuda…' });

        pedirJson(URL + '?action=getPanel&tab=' + encodeURIComponent(tab))
            .then(function(d) {
                Cargando.ocultar('ayudaPanelCargando');
                cache[tab] = d;

                // Si mientras tanto se cambió de pestaña, esto ya no se pinta.
                if (tabMostrada === tab) {
                    pintar(tab, d, '');
                }
            })['catch'](function(e) {
                Cargando.ocultar('ayudaPanelCargando');
                el('ayudaPanelCuerpo').innerHTML = '<div class="alert alert-danger small">No se pudo cargar la ayuda: '
                    + esc(e.message) + '</div>';
            });
    }

    function pintar(tab, d, q) {
        tabMostrada = tab;
        el('ayudaPanelTitulo').textContent = d.titulo;

        if (!d.encontrado) {
            el('ayudaPanelEstado').innerHTML = '';
            el('ayudaPanelErrores').innerHTML = '';
            el('ayudaPanelPreguntas').innerHTML = '';
            el('ayudaPanelBuscar').style.display = 'none';
            el('ayudaPanelCompleto').style.display = 'none';
            el('ayudaPanelCuerpo').innerHTML = '<div class="text-center text-muted py-4">'
                + '<i class="fas fa-book fa-2x mb-2 d-block"></i><p class="mb-2">Esta pestaña todavía no tiene ayuda.</p>'
                + '<a href="?ayuda=" class="ayuda-panel-ir" data-articulo="">Ir al Centro de ayuda</a></div>';
            return;
        }

        el('ayudaPanelBuscar').style.display = '';
        el('ayudaPanelCompleto').style.display = '';
        el('ayudaPanelCompleto').setAttribute('href', d.completo);
        el('ayudaPanelCompleto').setAttribute('data-articulo', d.articulo);

        // El estado sólo si no es Completa: lo normal no se marca.
        el('ayudaPanelEstado').innerHTML = d.estado !== 'completa'
            ? '<span class="ayuda-chip ayuda-chip-' + esc(d.estado) + '">' + esc(d.etiqueta) + '</span> '
                + '<span class="text-muted">actualizado el ' + esc(d.actualizado) + '</span>'
            : '';

        var errores = '';

        for (var e = 0; e < d.errores.length; e++) {
            errores += '<li>' + esc(d.errores[e]) + '</li>';
        }

        el('ayudaPanelErrores').innerHTML = errores
            ? '<div class="alert alert-warning small py-2">Este artículo tiene errores de formato y puede verse '
                + 'incompleto:<ul class="mb-0">' + errores + '</ul></div>'
            : '';

        var html = '';
        var hay = false;
        var i;

        for (i = 0; i < d.secciones.length; i++) {
            var s = d.secciones[i];

            hay = hay || s.coincide;
            html += '<div class="' + (s.coincide ? '' : 'ayuda-oculta') + '">' + s.html + '</div>';
        }

        var preguntas = '';

        for (i = 0; i < d.preguntas.length; i++) {
            var p = d.preguntas[i];

            if (p.coincide) {
                hay = true;
                preguntas += '<a href="' + esc(p.enlace) + '" class="ayuda-panel-ir" data-articulo="' + esc(d.articulo)
                    + '" data-ancla="' + esc(p.ancla) + '"><i class="fas fa-chevron-right me-2 small"></i>'
                    + p.titulo_html + '</a>';
            }
        }

        if (q && !hay) {
            html += '<p class="text-muted small">Nada de esta ayuda dice «' + esc(q) + '». Probá en el '
                + '<a href="?ayuda=" class="ayuda-panel-ir" data-articulo="">Centro de ayuda</a>.</p>';
        }

        el('ayudaPanelCuerpo').innerHTML = html;
        el('ayudaPanelPreguntas').innerHTML = preguntas
            ? '<h2 class="h6 fw-bold mt-4">Preguntas frecuentes</h2>' + preguntas
            : '';
    }

    function buscar() {
        var tab = tabMostrada;
        var q = el('ayudaPanelBuscar').value.replace(/^\s+|\s+$/g, '');
        var n = ++busqueda;

        if (!cache[tab]) {
            return;
        }

        if (q.length < 2) {
            pintar(tab, cache[tab], '');
            return;
        }

        pedirJson(URL + '?action=getPanel&tab=' + encodeURIComponent(tab) + '&q=' + encodeURIComponent(q))
            .then(function(d) {
                // Una respuesta vieja, o de otra pestaña, no se pinta.
                if (n === busqueda && tabMostrada === tab) {
                    pintar(tab, d, q);
                }
            })['catch'](function() {
                // Si la búsqueda falla, el artículo sigue entero a la vista.
            });
    }

    /** Ir al Centro de ayuda desde el panel: cierra el panel y navega. */
    function irAlCentro(articulo, ancla) {
        panel().hide();
        AyudaCentro.ir(articulo, ancla);
    }

    function conectar() {
        var boton = el('btnAyudaPestana');

        if (!boton || !el('ayudaPanel')) {
            return;
        }

        boton.addEventListener('click', function() {
            if (abierto() && tabMostrada === tabActual()) {
                panel().hide();
            } else {
                abrir();
            }
        });

        el('ayudaPanel').addEventListener('click', function(e) {
            var a = e.target.closest('.ayuda-panel-ir, .ayuda-enlace, #ayudaPanelCompleto, #ayudaPanelCentro');

            if (!a) {
                return;
            }

            e.preventDefault();

            if (a.id === 'ayudaPanelCentro') {
                irAlCentro('', '');
            } else {
                irAlCentro(a.getAttribute('data-articulo') || '', a.getAttribute('data-ancla') || '');
            }
        });

        el('ayudaPanelBuscar').addEventListener('input', function() {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 300);
        });

        // Con el panel abierto, cambiar de pestaña cambia la ayuda.
        if (window.MutationObserver) {
            new MutationObserver(function() {
                var tab = tabActual();

                if (tab === 'ayuda') {
                    // En el Centro no hay panel: ya se está en la ayuda.
                    if (abierto()) {
                        panel().hide();
                    }
                } else if (abierto() && tab !== tabMostrada) {
                    cargar(tab);
                }
            }).observe(document.body, { attributes: true, attributeFilter: ['data-tab'] });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', conectar);
    } else {
        conectar();
    }

    return {
        abrir: abrir
    };
})();
