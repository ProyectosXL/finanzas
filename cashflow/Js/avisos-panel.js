/**
 * avisos-panel.js — los avisos de una pestaña en un panel que se pliega.
 *
 * ES EL PANEL "SOBRE ESTOS NÚMEROS" DEL TABLERO, extraído de Js/Cashflow.js
 * para que lo usen todas las pestañas. Antes cada una pintaba sus avisos a su
 * manera —un cartel amarillo con una lista, avisos pegados con un espacio,
 * uno por renglón— y siempre abiertos: en pestañas con cinco o seis avisos de
 * configuración, la tabla empezaba media pantalla más abajo todos los días.
 * Es el patrón de informe_economico/Js/ie-comun.js (IE.pintarAvisos()).
 *
 *     AvisosPanel.pintar('avisosSaldos', avisos);
 *
 * EL PANEL
 * --------
 *  - El título dice "Avisos (N)" y lleva un chip por nivel con su cantidad
 *    (crítico, atención, info), así que cerrado sigue diciendo qué hay.
 *  - ARRANCA CERRADO, salvo que haya un CRÍTICO: ahí se abre solo, y no se lo
 *    puede esconder recordando "cerrado". Un crítico es justamente el aviso que
 *    dice que el número está mal.
 *  - RECUERDA abierto o cerrado POR CONTENEDOR, en localStorage, con try/catch:
 *    en un navegador que lo bloquea, el panel funciona igual y lo único que se
 *    pierde es que se recuerde.
 *  - Los avisos van ORDENADOS POR NIVEL —crítico, atención, info—, y dentro de
 *    cada nivel en el orden en que llegaron.
 *  - UN TEXTO SUELTO, SIN NIVEL, CUENTA COMO ATENCIÓN: la mayoría de las
 *    pestañas todavía manda textos. Es el mismo criterio que Aviso::nivel() en
 *    el backend, que toma como warning un nivel que no reconoce.
 *  - SIN AVISOS, el contenedor queda vacío, como antes.
 *
 * El tablero lo usa con GRUPOS —uno por pestaña, que ya llegan agrupados y
 * ordenados del backend (Aviso::agrupar())— y su título de siempre. Las demás
 * pestañas, con una sola lista.
 *
 * LO QUE NO VA ACÁ: los carteles explicativos fijos de cada pestaña, los
 * bloqueos de una importación, los avisos de la previsualización, los
 * resúmenes de filtro y las notificaciones de una acción. Esos se tienen que
 * ver siempre.
 *
 * LA PARTE PURA ESTÁ ESCRITA EN ES3 para poder correrla con cscript
 * (tests/js/avisos_panel.wsf): nada de map, forEach, trim ni Object.keys ahí.
 */

var AvisosPanel = (function() {
    'use strict';

    var NIVELES = ['danger', 'warning', 'info'];

    /** Íconos de Font Awesome (la free 6.4.2 que carga index.php) */
    var ICONO = {
        danger: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };

    /* ================================================================
       LA PARTE PURA (ES3)
       ================================================================ */

    function esNivel(n) {
        return n === 'danger' || n === 'warning' || n === 'info';
    }

    /**
     * Cualquier lista de avisos -textos sueltos u objetos {nivel, texto,
     * seccion}- como objetos. Un texto suelto, o un nivel que no se reconoce,
     * es atención. Los vacíos se descartan.
     *
     * @param {Array} lista
     * @returns {Array} [{nivel, texto, seccion}]
     */
    function normalizar(lista) {
        var salida = [];

        for (var i = 0; lista && i < lista.length; i++) {
            var a = lista[i];
            var texto, nivel, seccion = null;

            if (a !== null && typeof a === 'object') {
                texto = a.texto;
                nivel = esNivel(a.nivel) ? a.nivel : 'warning';
                seccion = a.seccion || null;
            } else {
                texto = a;
                nivel = 'warning';
            }

            texto = (texto === undefined || texto === null) ? '' : String(texto);

            if (/^\s*$/.test(texto)) {
                continue;
            }

            salida.push({ nivel: nivel, texto: texto, seccion: seccion });
        }

        return salida;
    }

    /**
     * Ordenados por nivel -crítico, atención, info- y, dentro de cada uno, en el
     * orden en que llegaron: el orden del backend también dice algo, y un sort
     * no estable lo mezclaría.
     */
    function ordenar(avisos) {
        var salida = [];

        for (var n = 0; n < NIVELES.length; n++) {
            for (var i = 0; i < avisos.length; i++) {
                if (avisos[i].nivel === NIVELES[n]) {
                    salida.push(avisos[i]);
                }
            }
        }

        return salida;
    }

    /** Cuántos hay de cada nivel */
    function contar(avisos) {
        var c = { danger: 0, warning: 0, info: 0 };

        for (var i = 0; i < avisos.length; i++) {
            c[avisos[i].nivel]++;
        }

        return c;
    }

    /**
     * Si el panel arranca abierto: con un crítico, siempre; si no, como lo dejó
     * el usuario. Cerrado por defecto.
     *
     * @param {Object} cuenta La de contar()
     * @param {boolean|null} preferencia true abierto, false cerrado, null nunca
     */
    function abiertoAlInicio(cuenta, preferencia) {
        return cuenta.danger > 0 || preferencia === true;
    }

    /** "Avisos (3)" */
    function tituloPorDefecto(total) {
        return 'Avisos (' + total + ')';
    }

    /* ================================================================
       EN EL NAVEGADOR
       ================================================================ */

    function leerPreferencia(clave) {
        try {
            var v = localStorage.getItem(clave);

            return v === null ? null : v === '1';
        } catch (e) {
            return null;
        }
    }

    function guardarPreferencia(clave, abierto) {
        try {
            localStorage.setItem(clave, abierto ? '1' : '0');
        } catch (e) {
            // Se abre igual; lo único que se pierde es que se recuerde.
        }
    }

    function escapar(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Los chips con la cantidad de cada nivel, sólo de los que hay */
    function chips(cuenta) {
        var html = '';

        for (var i = 0; i < NIVELES.length; i++) {
            var n = NIVELES[i];

            if (cuenta[n]) {
                html += '<span class="avisos-panel-chip aviso-' + n + '">'
                    + '<i class="fas ' + ICONO[n] + '"></i> ' + cuenta[n] + '</span>';
            }
        }

        return html;
    }

    function unAviso(a) {
        return '<div class="avisos-panel-aviso aviso-' + a.nivel + '">'
            + '<i class="fas ' + ICONO[a.nivel] + '"></i>'
            + '<div class="avisos-panel-texto">'
            + (a.seccion ? '<strong>' + escapar(a.seccion) + ':</strong> ' : '')
            + escapar(a.texto)
            + '</div></div>';
    }

    function unGrupo(g, opciones) {
        var clave = opciones.claveGrupo ? opciones.claveGrupo(g.grupo) : null;
        var abierto = abiertoAlInicio(g.cuenta || {}, clave ? leerPreferencia(clave) : null);

        return '<details class="avisos-panel-grupo"'
            + (clave ? ' data-clave="' + escapar(clave) + '"' : '')
            + ' data-grupo="' + escapar(g.grupo) + '"' + (abierto ? ' open' : '') + '>'
            + '<summary>'
            + '<span class="avisos-panel-grupo-nombre">' + escapar(g.nombre) + '</span>'
            + '<span class="avisos-panel-cuenta">(' + g.avisos.length + ')</span>'
            + chips(g.cuenta || {})
            + (opciones.linkGrupo ? opciones.linkGrupo(g) : '')
            + '</summary>'
            + '<div class="avisos-panel-lista">'
            + normalizar(g.avisos).map(unAviso).join('')
            + '</div></details>';
    }

    /**
     * Pinta el panel en un contenedor.
     *
     * @param {string|Element} contenedor El id o el elemento
     * @param {Array} avisos Textos sueltos u objetos {nivel, texto, seccion}.
     *        Se ignora si vienen 'grupos' en las opciones
     * @param {Object} [opciones]
     *        clave       la de localStorage; por defecto 'avisos_panel_<id>'
     *        titulo      function(total) -> texto del título
     *        grupos      [{grupo, nombre, cuenta, avisos}] (el tablero)
     *        claveGrupo  function(grupo) -> clave de localStorage del grupo
     *        linkGrupo   function(g) -> html extra en el título del grupo
     *        alConectar  function(cont) -> después de pintar, para enganchar
     *                    lo que el llamador haya puesto
     */
    function pintar(contenedor, avisos, opciones) {
        var cont = (typeof contenedor === 'string')
            ? document.getElementById(contenedor) : contenedor;

        if (!cont) {
            return;
        }

        opciones = opciones || {};

        var clave = opciones.clave || ('avisos_panel_' + (cont.id || 'sin_id'));
        var titulo = opciones.titulo || tituloPorDefecto;
        var cuenta, total, cuerpo;

        if (opciones.grupos) {
            var grupos = opciones.grupos;

            cuenta = { danger: 0, warning: 0, info: 0 };
            total = 0;

            grupos.forEach(function(g) {
                NIVELES.forEach(function(n) { cuenta[n] += (g.cuenta && g.cuenta[n]) || 0; });
                total += g.avisos.length;
            });

            cuerpo = '<div class="avisos-panel-grupos">'
                + grupos.map(function(g) { return unGrupo(g, opciones); }).join('')
                + '</div>';
        } else {
            var lista = ordenar(normalizar(avisos));

            cuenta = contar(lista);
            total = lista.length;
            cuerpo = '<div class="avisos-panel-lista">' + lista.map(unAviso).join('') + '</div>';
        }

        if (total === 0) {
            cont.innerHTML = '';
            return;
        }

        var abierto = abiertoAlInicio(cuenta, leerPreferencia(clave));

        cont.innerHTML = '<details class="avisos-panel"' + (abierto ? ' open' : '') + '>'
            + '<summary><span class="avisos-panel-titulo">' + escapar(titulo(total)) + '</span>'
            + chips(cuenta) + '</summary>'
            + cuerpo
            + '</details>';

        var panel = cont.querySelector('.avisos-panel');

        panel.addEventListener('toggle', function() {
            guardarPreferencia(clave, panel.open);
        });

        Array.prototype.forEach.call(cont.querySelectorAll('.avisos-panel-grupo[data-clave]'),
            function(d) {
                d.addEventListener('toggle', function() {
                    guardarPreferencia(d.getAttribute('data-clave'), d.open);
                });
            });

        if (opciones.alConectar) {
            opciones.alConectar(cont);
        }
    }

    return {
        pintar: pintar,

        // La parte pura, expuesta para tests/js/avisos_panel.wsf
        normalizar: normalizar,
        ordenar: ordenar,
        contar: contar,
        abiertoAlInicio: abiertoAlInicio,
        tituloPorDefecto: tituloPorDefecto
    };
})();
