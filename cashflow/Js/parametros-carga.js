/**
 * parametros-carga.js — cada sub-pestaña de Parámetros carga recién cuando se
 * abre, y una vez.
 *
 * POR QUE
 * -------
 * Al entrar a Parámetros, nueve sub-pestañas pedían getTodo a la vez —y
 * getTodo resolvía los once módulos cada vez—, más Cobranzas con sus tres
 * pedidos propios. La pantalla esperaba a todo eso para mostrar una sola
 * sub-pestaña. Ahora se carga la PRIMERA QUE SE VE (la activa del DOM; hoy,
 * Generales), la pantalla aparece apenas termina, y cada una de las demás se
 * carga la primera vez que alguien hace clic en ella.
 *
 * EL CACHÉ DURA LO QUE SE ESTÁ EN PARÁMETROS. Volver a una sub-pestaña ya
 * abierta no pide nada: muestra lo que tenía. Ir a otra pestaña del menú y
 * volver recarga todo desde cero, porque la pestaña entera se vuelve a dibujar
 * por AJAX y este registro nace vacío con ella.
 *
 * ES EL PATRÓN QUE YA TENÍA Parametros-Estructura.js —shown.bs.tab y una
 * marca de "cargado"—, escrito una vez en lugar de nueve.
 *
 * QUÉ HACE CADA SUB-PESTAÑA
 * -------------------------
 *     ParametrosCarga.registrar('SALDOS', cargar);
 *
 * en lugar de llamar a cargar() al arrancar. cargar() tiene que devolver la
 * promesa de su pedido, que resuelve en false si falló —cada sub-pestaña
 * muestra su propio error, y con false volver a abrirla lo reintenta—. Para
 * pedir su módulo usa ParametrosCarga.pedir(), que
 * trae SÓLO ese (getTodo&modulo=) y de paso pinta los avisos generales de
 * arriba. Su botón Actualizar sigue llamando a su propio cargar(): recarga
 * sólo esa sub-pestaña, y guardar recarga lo que ya recargaba, sin invalidar
 * las otras.
 *
 * EL INDICADOR DE CARGA: la primera carga usa el de la pestaña
 * (#loadingParametros), que es el que se ve mientras el wrapper está oculto.
 * Las siguientes, el de cada panel: si el panel ya tiene su propio
 * .cargando-slot lo maneja su JS; si no, se le agrega uno acá.
 */

var ParametrosCarga = (function() {
    'use strict';

    var URL = 'Controller/ParametrosController.php';

    /** codigo -> { cargar, cargado, pendiente } */
    var registro = {};

    /** Si ya terminó la primera carga y se mostró la pestaña */
    var visible = false;

    /** Si la carga en curso ya trajo los avisos generales con su módulo */
    var avisosTraidos = false;

    /** 'COB_ELECTRONICOS' -> 'Cob_electronicos': el mismo slug que Tabs/parametros.php */
    function slug(codigo) {
        var c = String(codigo).toLowerCase();

        return c.charAt(0).toUpperCase() + c.slice(1);
    }

    function boton(codigo) {
        return document.getElementById('tabParam' + slug(codigo) + 'Btn');
    }

    function panel(codigo) {
        return document.getElementById('paneParam' + slug(codigo));
    }

    /**
     * El indicador de un panel que no tiene el suyo. Si lo tiene, null: no se
     * dibujan dos ruedas para la misma carga.
     */
    function indicador(codigo) {
        var p = panel(codigo);

        if (!p || p.querySelector('.cargando-slot')) {
            return null;
        }

        var id = 'cargandoParam' + slug(codigo);
        var el = document.getElementById(id);

        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.className = 'cargando-slot pcarga-slot';
            el.setAttribute('data-cargando', 'Cargando…');
            p.insertBefore(el, p.firstChild);
        }

        return id;
    }

    /** La pestaña aparece apenas termina la primera sub-pestaña, bien o mal */
    function mostrarPestana() {
        if (visible) {
            return;
        }

        visible = true;
        Cargando.ocultar('loadingParametros');

        var w = document.getElementById('wrapperParametros');

        if (w) {
            w.style.display = '';
        }
    }

    /**
     * Los avisos de arriba de la pestaña: scripts pendientes. Llegan con la
     * primera carga y se refrescan con cualquier Actualizar. Los pinta el panel
     * de avisos compartido cuando existe; si no, como antes.
     */
    function pintarAvisosGenerales(avisos) {
        var cont = document.getElementById('avisosParametros');

        if (!cont) {
            return;
        }

        if (typeof AvisosPanel !== 'undefined') {
            AvisosPanel.pintar(cont, avisos || []);
            return;
        }

        cont.innerHTML = (avisos || []).map(function(a) {
            return '<div class="alert alert-warning py-2 px-3 mb-2">'
                + '<i class="fas fa-triangle-exclamation me-1"></i><small>'
                + String(a).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</small></div>';
        }).join('');
    }

    /**
     * Pide UN módulo -getTodo&modulo=- y de paso pinta los avisos generales.
     * Devuelve la misma 'data' que getTodo: { canales, modulos: [el pedido],
     * avisos }, así que buscarModulo() de cada sub-pestaña sigue sirviendo.
     *
     * @param {string} codigo
     * @returns {Promise<Object>}
     */
    function pedir(codigo) {
        return pedirJson(URL + '?action=getTodo&modulo=' + encodeURIComponent(codigo))
            .then(function(data) {
                avisosTraidos = true;
                pintarAvisosGenerales(data.avisos);

                return data;
            });
    }

    /**
     * Los avisos generales solos, para las sub-pestañas que no leen su módulo
     * con getTodo (Cobranzas, Cashflow). Si fallan no pasa nada: son contexto.
     */
    function refrescarAvisos() {
        return pedirJson(URL + '?action=getAvisosGenerales')
            .then(function(data) { pintarAvisosGenerales(data.avisos); })
            .then(null, function() { /* contexto, no dato */ });
    }

    /**
     * Carga una sub-pestaña. Si ya está cargando, devuelve la misma promesa:
     * dos clics seguidos no son dos pedidos.
     *
     * Una carga que FALLA no queda como cargada: volver a la sub-pestaña lo
     * vuelve a intentar. Cómo se muestra el error es cosa de cada sub-pestaña.
     */
    function cargar(codigo) {
        var r = registro[codigo];

        if (!r) {
            return Promise.resolve();
        }

        if (r.pendiente) {
            return r.pendiente;
        }

        var slot = visible ? indicador(codigo) : null;

        if (slot) {
            Cargando.mostrar(slot);
        } else if (!visible) {
            Cargando.mostrar('loadingParametros');
        }

        avisosTraidos = false;

        var fin = function() {
            if (slot) { Cargando.ocultar(slot); }

            r.pendiente = null;
            mostrarPestana();
        };

        r.pendiente = Promise.resolve()
            .then(function() { return r.cargar(); })
            .then(function(resultado) {
                /* false es "fallé y ya lo mostré": cada sub-pestaña atrapa su
                   error para dibujarlo a su manera, así que un rechazo no
                   llegaría hasta acá. Sin la marca, una carga fallida quedaría
                   como hecha y volver a la sub-pestaña no la reintentaría. */
                r.cargado = (resultado !== false);
                fin();

                if (!avisosTraidos) { refrescarAvisos(); }
            }, function(e) {
                fin();
                console.error('Parámetros › ' + codigo + ':', e);
            });

        return r.pendiente;
    }

    /** La primera vez carga; después, nada: ya está a la vista */
    function asegurar(codigo) {
        var r = registro[codigo];

        return (r && r.cargado) ? Promise.resolve() : cargar(codigo);
    }

    /**
     * Registra una sub-pestaña. Si es la que está activa —la primera que se
     * ve— carga ahora; si no, la primera vez que se abra.
     *
     * @param {string} codigo El de Parametros::$modulos (GENERALES, SALDOS...)
     * @param {Function} fn Su cargar(); tiene que devolver una promesa
     */
    function registrar(codigo, fn) {
        registro[codigo] = { cargar: fn, cargado: false, pendiente: null };

        var b = boton(codigo);

        if (!b) {
            return;
        }

        b.addEventListener('shown.bs.tab', function() { asegurar(codigo); });

        if (b.classList.contains('active')) {
            asegurar(codigo);
        }
    }

    return {
        registrar: registrar,
        pedir: pedir,
        refrescarAvisos: refrescarAvisos,
        cargada: function(codigo) { return !!(registro[codigo] && registro[codigo].cargado); }
    };
})();
