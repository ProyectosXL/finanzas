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
 * LAS PESTAÑAS CON PROVEEDOR MUESTRAN TAMBIÉN LO QUE EL TABLERO DICE DE ELLAS:
 *
 *     AvisosPanel.pedirTablero('saldos');                  // en paralelo con los datos
 *     AvisosPanel.pintarPestana('avisosSaldos', propios, { tab: 'saldos', sub: 'saldos' });
 *
 * Pinta primero los propios y, cuando llegan los del tablero, redibuja con los
 * dos juntos y sin repetidos (combinar()). En las pestañas con un panel por
 * sub-pestaña, cada aviso del tablero va a la de su sección (SUB_DE_SECCION) y
 * los que no tienen sección van a todas.
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

    /* ----------------------------------------------------------------
       LOS AVISOS DEL TABLERO, TAMBIÉN EN SU PESTAÑA

       Cada pestaña con proveedor le pide al tablero los avisos de su grupo
       (Cashflow::avisosDePestana(), que corre el mismo código del motor) y
       los junta con los suyos. Lo puro está acá y se prueba en
       tests/js/avisos_panel.wsf.
       ---------------------------------------------------------------- */

    /** Lo que llegó del tablero por pestaña: {estado, avisos} */
    var tableros = {};

    /** Los paneles que muestran avisos del tablero: id -> {cont, propios, opciones} */
    var conTablero = {};

    /** El aviso que se suma si el pedido al tablero falla */
    var TEXTO_SIN_TABLERO = 'No se pudieron traer los avisos del tablero para esta pestaña.';

    /**
     * Cómo terminan los dos descartes de la grilla de una pestaña. SON LOS
     * DE EjeVista::FIN_FUERA_HORIZONTE y FIN_SIN_FECHA, letra por letra:
     * tests/test_avisos_pestana.php lo verifica.
     */
    var FINES_DESCARTE_EJE = [
        ' con fecha fuera del horizonte no se muestran en la tabla.',
        ' sin fecha asignada no se pueden ubicar en el tiempo y quedan fuera de la tabla.'
    ];

    /**
     * A qué sub-pestaña va cada sección, en las pestañas con un panel por
     * sub-pestaña. Las secciones son las que ponen los proveedores
     * (SaldosProvider, FondosProvider con Fondos::CLASES, TarjetasProvider);
     * tests/test_avisos_pestana.php verifica que coincidan. Echeqs no tiene
     * secciones: todo va a sus dos sub-pestañas. Proveedores Locales tiene un
     * solo panel sobre sus tres vistas, y no necesita mapa.
     */
    var SUB_DE_SECCION = {
        saldos: {
            'Saldo Inicial': 'saldos',
            'Caja Locales': 'locales',
            // 'Inversi\u00f3n' es 'Inversión': escrito así, cscript -que lee el
            // archivo como ANSI- y el navegador ven la misma clave.
            'Inversi\u00f3n': 'fondos',
            'Cuenta comitente': 'fondos'
        },
        pagos_tarjetas: {
            'Supervisoras': 'sup',
            'Corporativas': 'corp',
            'Socios': 'soc'
        }
    };

    /**
     * La clave con la que se comparan dos textos: sin espacios de más y sin
     * el punto final. NO se normalizan tildes ni mayúsculas: dos textos que
     * difieren en eso no son el mismo texto, y la regla es escribir cada
     * hecho una sola vez en el origen (ver Aviso.php), no adivinarlo acá.
     */
    function claveTexto(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/\s+/g, ' ')
            .replace(/^ | $/g, '')
            .replace(/\.$/, '');
    }

    /** Si un texto es uno de los dos descartes de la grilla (EjeVista) */
    function esDescarteDelEje(texto) {
        var t = String(texto === null || texto === undefined ? '' : texto);

        for (var i = 0; i < FINES_DESCARTE_EJE.length; i++) {
            var fin = FINES_DESCARTE_EJE[i];

            if (t.length >= fin.length && t.substring(t.length - fin.length) === fin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los avisos de una sub-pestaña: los de su sección, más los que no tienen
     * sección -o tienen una que el mapa no conoce-, que van a todas. Sin sub
     * (una pestaña con un solo panel), todos.
     *
     * @param {Array} avisos Normalizados
     * @param {Object|null} mapa sección -> sub-pestaña
     * @param {string|null} sub
     */
    function deSubPestana(avisos, mapa, sub) {
        if (!sub || !mapa) {
            return avisos;
        }

        var salida = [];

        for (var i = 0; i < avisos.length; i++) {
            var s = avisos[i].seccion;
            var destino = (s && mapa.hasOwnProperty(s)) ? mapa[s] : null;

            if (destino === null || destino === sub) {
                salida.push(avisos[i]);
            }
        }

        return salida;
    }

    /**
     * Los avisos propios de la pestaña junto con los del tablero, sin
     * repetidos.
     *
     *  - Si un aviso propio tiene el mismo texto que uno del tablero, queda
     *    UNO, con el nivel y la sección del tablero: el del tablero es el que
     *    calcula el motor, y es el que se ve igual en las dos pantallas.
     *  - Con los del tablero a la vista, la pestaña ESCONDE SUS DESCARTES DE
     *    LA GRILLA: el tablero ya dice lo mismo de su fila, con el nombre de la
     *    fila. Si el pedido falló, se queda con los suyos.
     *  - Si el pedido falló, se suma un aviso de atención que lo dice.
     *
     * Orden: los propios en su lugar -reemplazados por el del tablero si es
     * el mismo- y después los del tablero que la pestaña no tenía. El panel
     * después ordena por nivel y respeta este orden entre iguales.
     *
     * @param {Array} propios Textos u objetos
     * @param {Object|null} tablero {estado: 'cargando'|'ok'|'error', avisos}
     * @returns {Array} Normalizados
     */
    function combinar(propios, tablero) {
        var mios = normalizar(propios);
        var estado = tablero ? tablero.estado : null;
        var delTablero = (estado === 'ok') ? normalizar(tablero.avisos) : [];
        var porClave = {};
        var usado = {};
        var salida = [];
        var i, k;

        for (i = 0; i < delTablero.length; i++) {
            k = claveTexto(delTablero[i].texto);

            if (!porClave.hasOwnProperty(k)) {
                porClave[k] = i;
            }
        }

        for (i = 0; i < mios.length; i++) {
            if (estado === 'ok' && esDescarteDelEje(mios[i].texto)) {
                continue;
            }

            k = claveTexto(mios[i].texto);

            if (porClave.hasOwnProperty(k)) {
                if (!usado.hasOwnProperty(k)) {
                    usado[k] = true;
                    salida.push(delTablero[porClave[k]]);
                }
            } else {
                salida.push(mios[i]);
            }
        }

        for (i = 0; i < delTablero.length; i++) {
            k = claveTexto(delTablero[i].texto);

            if (!usado.hasOwnProperty(k)) {
                usado[k] = true;
                salida.push(delTablero[i]);
            }
        }

        if (estado === 'error') {
            salida.push({ nivel: 'warning', texto: TEXTO_SIN_TABLERO, seccion: null });
        }

        return salida;
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

        /* Quien pinta a mano -un error de carga, otra lista- deja de mostrar
           los avisos del tablero en ese panel: si no, cuando llegaran, el
           redibujo de pedirTablero() pisaría lo que se acaba de pintar. */
        if (cont && cont.id && conTablero.hasOwnProperty(cont.id)) {
            delete conTablero[cont.id];
        }

        dibujar(cont, avisos, opciones);
    }

    /** El dibujo del panel: lo comparten pintar() y pintarPestana() */
    function dibujar(cont, avisos, opciones) {
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

    /* ================================================================
       LOS AVISOS DEL TABLERO, EN EL NAVEGADOR
       ================================================================ */

    /**
     * Pinta los avisos propios de una pestaña junto con los del tablero.
     *
     * Es pintar() con dos opciones más: 'tab' (la pestaña del registro) y
     * 'sub' (la sub-pestaña, en las que tienen un panel por sub-pestaña). Si
     * los del tablero todavía no llegaron pinta los propios; cuando llegan,
     * pedirTablero() lo vuelve a pintar con los mismos propios.
     */
    function pintarPestana(contenedor, propios, opciones) {
        var cont = (typeof contenedor === 'string')
            ? document.getElementById(contenedor) : contenedor;

        if (!cont) {
            return;
        }

        opciones = opciones || {};
        conTablero[cont.id] = { cont: cont, propios: propios || [], opciones: opciones };

        var mapa = SUB_DE_SECCION.hasOwnProperty(opciones.tab) ? SUB_DE_SECCION[opciones.tab] : null;
        var tablero = tableros[opciones.tab] || null;
        var delTablero = (tablero && tablero.estado === 'ok')
            ? { estado: 'ok', avisos: deSubPestana(normalizar(tablero.avisos), mapa, opciones.sub || null) }
            : tablero;

        dibujar(cont, combinar(propios || [], delTablero), opciones);
    }

    /**
     * Pide al tablero los avisos de una pestaña. Se llama EN PARALELO con el
     * pedido de datos de la pestaña, nunca antes ni encadenado: la grilla no
     * espera por esto. El servidor libera la sesión apenas autoriza, así que
     * tampoco la frena del otro lado (AuthCashflow::liberarSesion()).
     *
     * Cuando la respuesta llega, vuelve a pintar todos los paneles de esa
     * pestaña con sus propios avisos de siempre. Si falla, la pestaña sigue
     * igual y el panel suma un aviso de atención.
     *
     * @param {string} tab
     * @returns {Promise}
     */
    function pedirTablero(tab) {
        tableros[tab] = { estado: 'cargando', avisos: [] };

        return fetch('Controller/CashflowController.php?action=getAvisosPestana&tab='
                + encodeURIComponent(tab), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (!r || !r.success || !r.data) {
                    throw new Error((r && r.message) || 'Respuesta inválida');
                }

                tableros[tab] = { estado: 'ok', avisos: r.data.avisos || [] };
            })
            // ['catch'] y no .catch: el archivo entero se carga en cscript
            // (ES3) para probar la parte pura, y ahí catch es palabra reservada.
            ['catch'](function() {
                tableros[tab] = { estado: 'error', avisos: [] };
            })
            .then(function() {
                for (var id in conTablero) {
                    if (conTablero.hasOwnProperty(id)
                        && conTablero[id].opciones.tab === tab
                        && document.body.contains(conTablero[id].cont)) {
                        pintarPestana(conTablero[id].cont, conTablero[id].propios,
                            conTablero[id].opciones);
                    }
                }
            });
    }

    return {
        pintar: pintar,
        pintarPestana: pintarPestana,
        pedirTablero: pedirTablero,

        // La parte pura de los avisos del tablero, para tests/js/avisos_panel.wsf
        claveTexto: claveTexto,
        esDescarteDelEje: esDescarteDelEje,
        deSubPestana: deSubPestana,
        combinar: combinar,
        SUB_DE_SECCION: SUB_DE_SECCION,
        TEXTO_SIN_TABLERO: TEXTO_SIN_TABLERO,

        // La parte pura, expuesta para tests/js/avisos_panel.wsf
        normalizar: normalizar,
        ordenar: ordenar,
        contar: contar,
        abiertoAlInicio: abiertoAlInicio,
        tituloPorDefecto: tituloPorDefecto
    };
})();
