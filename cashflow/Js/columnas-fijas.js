/**
 * columnas-fijas.js
 * Qué columnas descriptivas quedan FIJAS mientras la tabla scrollea a lo ancho.
 * Es el compañero de eje-vistas.js: un control compartido por todas las
 * pestañas con eje temporal, en vez de una solución por pantalla.
 *
 * POR QUÉ EXISTE
 * --------------
 * El CSS de .tabla-temporal ya fijaba columnas, pero cableadas: la primera por
 * `:first-child` y una segunda por la clase `.col-medio`. Eso alcanzaba para la
 * tabla de cobranza de Ventas y para nada más. En Cobranzas FR, con veintiocho
 * columnas de días a la derecha, al scrollear se perdían de vista el código y
 * la razón social, que es justamente lo que dice de quién es cada número.
 *
 * Acá NO se decide el layout: el CSS resuelve `position: sticky` a partir de
 * las clases `.col-fija-1` … `.col-fija-4`, y `ajustarStickyHeaders()` de
 * main.js mide los anchos reales y publica `--col-fija-N-left`. Este archivo
 * decide QUIÉN lleva cada clase y dibuja el desplegable para elegirlo.
 *
 * LA ELECCIÓN SOBREVIVE A LA RECARGA
 * ----------------------------------
 * Se guarda en localStorage con una clave por pestaña. Fijar columnas es una
 * preferencia de cómo mirar la tabla, no un filtro de datos: que se pierda en
 * cada F5 la vuelve inservible para el trabajo largo.
 *
 * CÓMO SE USA EN UNA PESTAÑA
 * --------------------------
 *   var fijas = crearColumnasFijas({
 *       tabla: 'tablaCobranzasFR',   // id de la <table>
 *       control: 'colFijasCob',      // id del contenedor del desplegable
 *       clave: 'cobranzas_fr',       // clave de localStorage
 *       porDefecto: [0, 1, 2]        // índices de columna que arrancan fijas
 *   });
 *
 *   fijas.aplicar();                 // después de cada redibujo de la tabla
 *
 * `aplicar()` se llama sola cuando la tabla se redibuja por AJAX: main.js tiene
 * un MutationObserver sobre #tabContent que reaplica todos los controles vivos.
 * Una pestaña no tiene que acordarse de llamarla en cada punto de redibujo.
 */

var ColumnasFijas = (function() {
    'use strict';

    /** Tope de columnas fijas. Ver la nota del CSS sobre por qué cuatro. */
    var MAXIMO = 4;

    var PREFIJO_STORAGE = 'cashflow.colfijas.';

    /** Controles vivos, para poder reaplicarlos cuando cambia el DOM */
    var controles = [];

    /* ================================================================
       QUÉ COLUMNAS SON "DESCRIPTIVAS"
       ================================================================ */

    /**
     * Las columnas elegibles son la corrida de celdas del encabezado que están
     * ANTES del grupo de columnas temporales: las que abarcan las dos filas del
     * thead (rowspan) y ocupan una sola columna.
     *
     * Se deriva del encabezado en vez de declararla en cada pestaña porque la
     * lista declarada se desactualiza en silencio cuando alguien agrega una
     * columna, y el síntoma sería una columna fija corrida un lugar.
     *
     * El corte es la primera celda que no cumple: en todas estas tablas es el
     * encabezado de grupo del eje temporal, que lleva colspan. Sin ese corte, la
     * columna "Total" —que también tiene rowspan y vive al final— entraría en la
     * lista.
     *
     * @param {HTMLTableElement} tabla
     * @returns {Array} [{indice, nombre}]
     */
    function descriptivas(tabla) {
        var thead = tabla && tabla.tHead;
        var fila = thead && thead.rows[0];

        if (!fila) {
            return [];
        }

        var cols = [];
        var i;

        // Encabezado de UNA sola fila: no hay eje temporal agrupado, así que
        // todas las celdas son columnas comunes. Es el caso de las tablas de
        // análisis de Ventas.
        if (thead.rows.length < 2) {
            for (i = 0; i < fila.cells.length; i++) {
                cols.push({
                    indice: i,
                    nombre: (fila.cells[i].textContent || '').trim() || ('Columna ' + (i + 1))
                });
            }

            return cols;
        }

        for (i = 0; i < fila.cells.length; i++) {
            var th = fila.cells[i];

            if ((th.rowSpan || 1) < 2 || (th.colSpan || 1) > 1) {
                break;
            }

            cols.push({
                indice: i,
                nombre: (th.textContent || '').trim() || ('Columna ' + (i + 1))
            });
        }

        return cols;
    }

    /* ================================================================
       APLICAR LA SELECCIÓN AL DOM
       ================================================================ */

    /** Saca todas las marcas de columna fija de una tabla */
    function limpiar(tabla) {
        var marcadas = tabla.querySelectorAll(
            '.col-fija-1, .col-fija-2, .col-fija-3, .col-fija-4');

        for (var i = 0; i < marcadas.length; i++) {
            for (var n = 1; n <= MAXIMO; n++) {
                marcadas[i].classList.remove('col-fija-' + n);
            }
        }
    }

    /**
     * Marca las columnas elegidas en thead, tbody y tfoot.
     *
     * @param {HTMLTableElement} tabla
     * @param {Array} indices Índices de columna, en orden
     */
    function marcar(tabla, indices) {
        limpiar(tabla);

        if (!indices.length) {
            return;
        }

        var orden = indices.slice().sort(function(a, b) { return a - b; });
        var posicion = {};

        orden.forEach(function(idx, i) {
            posicion[idx] = i + 1;   // col-fija-1, col-fija-2, ...
        });

        // Encabezado: la primera fila del thead es la de las celdas con rowspan
        var cabecera = tabla.tHead && tabla.tHead.rows[0];

        if (cabecera) {
            orden.forEach(function(idx) {
                if (cabecera.cells[idx]) {
                    cabecera.cells[idx].classList.add('col-fija-' + posicion[idx]);
                }
            });
        }

        // Cuerpo: una celda por columna, así que el índice es directo
        var cuerpos = tabla.tBodies;

        for (var b = 0; b < cuerpos.length; b++) {
            var filas = cuerpos[b].rows;

            for (var f = 0; f < filas.length; f++) {
                var celdas = filas[f].cells;

                orden.forEach(function(idx) {
                    if (celdas[idx]) {
                        celdas[idx].classList.add('col-fija-' + posicion[idx]);
                    }
                });
            }
        }

        marcarPie(tabla, orden, posicion);
    }

    /**
     * El pie es el caso raro y por eso va aparte.
     *
     * En unas tablas el pie tiene una celda por columna (Ventas: Canal, Medio
     * de Pago, y después los importes) y ahí se fija igual que el cuerpo. En
     * otras el rótulo "TOTALES" es UNA celda con colspan sobre todo el bloque
     * descriptivo (Cobranzas FR).
     *
     * Una celda que se pasa del bloque fijo NO se fija: quedaría anclada a la
     * izquierda con el ancho de todas las columnas que abarca, tapando los
     * importes que uno está mirando. Es preferible que el rótulo se vaya con el
     * scroll —sigue pegado abajo— a que estacione una banda sobre los números.
     */
    function marcarPie(tabla, orden, posicion) {
        var pie = tabla.tFoot;

        if (!pie) {
            return;
        }

        var ultimaFija = orden[orden.length - 1];

        for (var f = 0; f < pie.rows.length; f++) {
            var celdas = pie.rows[f].cells;
            var indice = 0;

            for (var c = 0; c < celdas.length; c++) {
                var span = celdas[c].colSpan || 1;
                var hasta = indice + span - 1;

                // Solo si la celda entra ENTERA en el bloque fijo
                if (hasta <= ultimaFija && posicion[indice] !== undefined) {
                    celdas[c].classList.add('col-fija-' + posicion[indice]);
                }

                indice += span;
            }
        }
    }

    /* ================================================================
       PERSISTENCIA
       ================================================================ */

    function leerGuardado(clave) {
        try {
            var crudo = window.localStorage.getItem(PREFIJO_STORAGE + clave);

            if (!crudo) {
                return null;
            }

            var v = JSON.parse(crudo);

            return Array.isArray(v) ? v.map(Number) : null;
        } catch (e) {
            // localStorage puede estar bloqueado (modo privado, política del
            // navegador). La pantalla tiene que seguir funcionando con el
            // default, no romperse por una preferencia.
            return null;
        }
    }

    function guardar(clave, indices) {
        try {
            window.localStorage.setItem(PREFIJO_STORAGE + clave, JSON.stringify(indices));
        } catch (e) {
            /* sin persistencia, pero la selección igual se aplica */
        }
    }

    /* ================================================================
       EL CONTROL
       ================================================================ */

    function crear(opciones) {
        var seleccion = null;

        /* Último HTML dibujado del desplegable. Sirve para NO volver a escribir
           innerHTML cuando no cambió nada: el MutationObserver de main.js mira
           #tabContent por childList, así que un innerHTML idéntico se cuenta
           igual como cambio y el par observer -> reaplicar -> pintar no pararía
           nunca. */
        var ultimoControl = null;

        function tabla() {
            return document.getElementById(opciones.tabla);
        }

        function columnas() {
            var t = tabla();

            return t ? descriptivas(t) : [];
        }

        /**
         * La selección efectiva: la guardada si sigue siendo válida contra las
         * columnas que la tabla tiene HOY, si no el default.
         *
         * La validación importa: si alguien agregó o sacó una columna, un índice
         * guardado apunta a otra cosa y el usuario vería fijada una columna que
         * no eligió.
         */
        function resolver() {
            var disponibles = columnas().map(function(c) { return c.indice; });

            if (seleccion === null) {
                seleccion = opciones.clave ? leerGuardado(opciones.clave) : null;

                if (seleccion === null) {
                    seleccion = (opciones.porDefecto || []).slice();
                }
            }

            var valida = seleccion.filter(function(i) {
                return disponibles.indexOf(i) !== -1;
            });

            return valida.slice(0, MAXIMO);
        }

        var api = {
            /** Marca las columnas elegidas. Idempotente: limpia antes de marcar. */
            aplicar: function() {
                var t = tabla();

                if (!t) {
                    return;
                }

                marcar(t, resolver());
                pintarControl();
            },

            /** @returns {Array} Índices de las columnas fijas */
            seleccion: resolver,

            /** Cambia la selección desde código y la persiste */
            usar: function(indices) {
                seleccion = (indices || []).slice(0, MAXIMO);

                if (opciones.clave) {
                    guardar(opciones.clave, seleccion);
                }

                api.aplicar();
                ajustar();
            },

            /** Id de la tabla que maneja este control */
            idTabla: function() {
                return opciones.tabla;
            }
        };

        /* ---- El desplegable ------------------------------------------- */

        function pintarControl() {
            if (!opciones.control) {
                return;
            }

            var cont = document.getElementById(opciones.control);

            if (!cont) {
                return;
            }

            var cols = columnas();

            // Con una sola columna descriptiva no hay nada que elegir: el
            // desplegable sería un control de una opción. Se fija esa y listo.
            if (cols.length < 2) {
                if (ultimoControl !== '') {
                    cont.innerHTML = '';
                    ultimoControl = '';
                }

                return;
            }

            var elegidas = resolver();
            var html = '<div class="dropdown d-inline-block">'
                + '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" '
                + 'data-bs-toggle="dropdown" data-bs-auto-close="outside" '
                + 'title="Elegí qué columnas quedan fijas al scrollear a lo ancho">'
                + '<i class="fas fa-thumbtack me-1"></i>Columnas fijas'
                + '<span class="badge bg-secondary ms-1">' + elegidas.length + '</span>'
                + '</button>'
                + '<ul class="dropdown-menu p-2 cf-menu-columnas">';

            cols.forEach(function(col) {
                var marcada = elegidas.indexOf(col.indice) !== -1;
                var tope = !marcada && elegidas.length >= MAXIMO;
                var id = 'colfija_' + opciones.tabla + '_' + col.indice;

                html += '<li><label class="dropdown-item d-flex align-items-center gap-2 '
                    + (tope ? 'text-muted' : '') + '" for="' + id + '">'
                    + '<input type="checkbox" class="form-check-input mt-0" id="' + id + '" '
                    + 'data-indice="' + col.indice + '"'
                    + (marcada ? ' checked' : '') + (tope ? ' disabled' : '') + '>'
                    + '<span>' + escapar(col.nombre) + '</span>'
                    + '</label></li>';
            });

            html += '<li><hr class="dropdown-divider"></li>'
                + '<li><small class="text-muted px-2">Hasta ' + MAXIMO
                + ' columnas. La elección se recuerda en este navegador.</small></li>'
                + '</ul></div>';

            if (html === ultimoControl && cont.firstChild) {
                return;
            }

            cont.innerHTML = html;
            ultimoControl = html;

            cont.querySelectorAll('input[data-indice]').forEach(function(chk) {
                chk.addEventListener('change', function() {
                    var idx = Number(chk.getAttribute('data-indice'));
                    var actual = resolver();
                    var pos = actual.indexOf(idx);

                    if (chk.checked && pos === -1) {
                        actual.push(idx);
                    } else if (!chk.checked && pos !== -1) {
                        actual.splice(pos, 1);
                    }

                    actual.sort(function(a, b) { return a - b; });
                    api.usar(actual);
                });
            });
        }

        controles.push(api);
        api.aplicar();

        return api;
    }

    /* ================================================================
       REAPLICACIÓN AUTOMÁTICA
       ================================================================ */

    /**
     * Vuelve a marcar todas las tablas manejadas. La llama main.js cuando el
     * MutationObserver ve que #tabContent cambió, que es lo que pasa cuando una
     * pestaña redibuja su tabla por AJAX o cambia de vista Días/Meses/Completo.
     *
     * Los controles cuya tabla ya no está en el DOM se descartan: son los de la
     * pestaña anterior.
     */
    function reaplicar() {
        controles = controles.filter(function(c) {
            return document.getElementById(c.idTabla()) !== null;
        });

        controles.forEach(function(c) {
            c.aplicar();
        });

        porDefectoEnTablasSinControl();
    }

    /**
     * Toda tabla temporal SIN control explícito conserva su primera columna
     * descriptiva fija, que es lo que hacía el CSS viejo con `:first-child`.
     *
     * Existe para que agregar el mecanismo nuevo no le saque el comportamiento a
     * las tablas que nadie pidió cambiar —las tablitas de análisis de Ventas,
     * por ejemplo—. El default es el mínimo razonable: sin él, una tabla ancha
     * quedaría sin ninguna referencia al scrollear.
     */
    function porDefectoEnTablasSinControl() {
        var manejadas = controles.map(function(c) { return c.idTabla(); });
        var tablas = document.querySelectorAll('.tabla-temporal table');

        for (var i = 0; i < tablas.length; i++) {
            var t = tablas[i];

            if (t.id && manejadas.indexOf(t.id) !== -1) {
                continue;
            }

            var cols = descriptivas(t);

            if (cols.length) {
                marcar(t, [cols[0].indice]);
            }
        }
    }

    /** Pide a main.js que vuelva a medir los anchos */
    function ajustar() {
        if (typeof window.ajustarStickyHeaders === 'function') {
            window.ajustarStickyHeaders();
        }
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    return {
        crear: crear,
        reaplicar: reaplicar,
        descriptivas: descriptivas,
        MAXIMO: MAXIMO
    };
})();

/** Misma forma de uso que crearEjeVistas() */
function crearColumnasFijas(opciones) {
    return ColumnasFijas.crear(opciones);
}
