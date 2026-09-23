/**
 * tabla-orden.js
 * Ordenar una tabla haciendo click en el encabezado. Es el tercer control
 * compartido de las tablas del módulo, junto a eje-vistas.js y
 * columnas-fijas.js: uno solo para todas las pestañas, y no una solución por
 * pantalla.
 *
 * POR QUÉ EXISTE
 * --------------
 * Ninguna de las treinta y seis tablas del módulo se podía ordenar. DataTables
 * está cargado y lo haría, pero toma el control del `<table>` entero —paginado,
 * su propio buscador, su propio redibujo— y estas tablas ya tienen resuelto
 * todo eso de otra forma: el eje temporal, las columnas fijas, el header y el
 * pie fijos, el buscador propio y el filtro server-side. Enchufarlo pelearía
 * con los cuatro.
 *
 * ORDENAR NO ES FILTRAR NI RECALCULAR
 * -----------------------------------
 * Este archivo mueve filas de un `tbody` y nada más. No toca los importes, no
 * toca los totales y no toca la visibilidad de ninguna fila: una fila escondida
 * por el buscador o por el filtro por fecha de emisión SIGUE ESCONDIDA después
 * de ordenar, porque su `display` viaja con ella. Y el `tfoot` no se ordena
 * nunca: los totales van al pie, que es de donde no se tienen que mover.
 *
 * QUÉ COLUMNAS SON ORDENABLES
 * ---------------------------
 * En una tabla con eje temporal el `thead` tiene DOS filas: arriba las columnas
 * descriptivas (con `rowspan="2"`) y el encabezado de grupo del eje, y abajo las
 * veintiocho columnas de días. Ordenables son las descriptivas más la columna
 * `Total`. Las columnas de días no: ordenar por "lo que entra el 8/9" es una
 * pregunta legítima, pero con veintiocho encabezados clickeables al lado se
 * aprieta uno sin querer, y el rótulo de esa columna es tan chico que no hay
 * dónde mostrar el indicador. Se derivan del encabezado y no se declaran por
 * pestaña, por el mismo motivo que en columnas-fijas.js: una lista declarada se
 * desactualiza en silencio.
 *
 * EL TIPO SE DETECTA POR EL CONTENIDO
 * -----------------------------------
 * Se ordena por el VALOR y no por el string formateado, que es lo único útil:
 * `$ 1.000.000,00` es menor que `$ 9,00` como texto. Se reconocen importes en
 * formato es-AR (`$ 1.234,56`, `USD -1.000,00`, `8%`), fechas (`dd/mm/aaaa` y
 * `aaaa-mm-dd`), rótulos de mes (`Sep-26`, los de `Horizonte::labelMes()`) y
 * texto. El tipo lo decide la MAYORÍA de los valores de la columna: un `N/A`
 * suelto en una columna de fechas no la convierte en una columna de texto.
 *
 * Cuando el texto de la celda no sirve —un badge, un ícono, una celda con un
 * `<input>`— la pestaña pone `data-orden` en el `<td>` con el valor real. Un
 * `<input>` se resuelve solo: se ordena por su `value`.
 *
 * CÓMO SE USA
 * -----------
 * Casi nunca hay que llamarla. `reaplicar()` habilita el orden en TODA tabla con
 * `<thead>` e `id` que esté dentro de `#tabContent`, y usa el id como clave de
 * la preferencia. Las pestañas sólo intervienen en dos casos:
 *
 *   - Para declarar opciones (filas ancla, orden con el que abre):
 *
 *       crearOrdenTabla({
 *           tabla: 'cfTabla',
 *           clave: 'cashflow',
 *           anclas: '.cf-seccion, .cf-tipo-subtotal',
 *           porDefecto: { columna: 'cobro', dir: 'asc' }
 *       });
 *
 *     Si se omite `clave`, es el id de la tabla: el mismo que usa el
 *     descubrimiento automático, así que declarar opciones sobre una tabla que
 *     ya se ordenaba sola NO le cambia la clave ni le pierde la preferencia.
 *
 *   - Para declarar que una fila VIAJA PEGADA a la anterior, con
 *     `data-orden-sigue` en el `<tr>`. Ver *Las filas que viajan pegadas*.
 *
 *   - Para NO ordenarse, con `data-orden="no"` en la `<table>`. Es para las
 *     tablas donde el orden de las filas ES el dato: el editor de la estructura
 *     del tablero, que se reordena con botones, y las tablas con una columna de
 *     acumulado, que sólo se lee en orden cronológico.
 *
 * LAS FILAS QUE VIAJAN PEGADAS
 * ----------------------------
 * Hay tablas donde una fila no es un dato suelto sino el detalle de la de
 * arriba: en el tablero, las dos partes —real y proyectada— de un concepto
 * agrupado cuelgan de la fila que muestra su suma. Ordenar por importe las
 * separaría, y el resultado no sería un error visible: quedaría un cuadro con
 * la pinta de siempre en el que la parte real de un concepto aparece debajo de
 * otro.
 *
 * Un `<tr data-orden-sigue>` no se ordena: se queda pegado a la fila anterior
 * que sí se ordena, y se mueve con ella. Varias seguidas viajan todas, en su
 * orden.
 *
 * NO ES LO MISMO QUE UN ANCLA, y la diferencia es la que importa acá: un ancla
 * se queda QUIETA y parte el cuerpo en bloques —es lo que hace que un SUBTOTAL
 * cierre su sección—; una fila pegada se MUEVE, pero nunca sola. Marcar las
 * partes como ancla las clavaría en su lugar mientras la fila agrupada se va a
 * ordenar a otro lado, que es exactamente lo que había que evitar.
 *
 * Una fila pegada al principio del cuerpo, sin nadie adelante, se ordena como
 * cualquier otra: no hay de quién colgar.
 *
 * LA PREFERENCIA SE GUARDA POR NOMBRE DE COLUMNA, NO POR ÍNDICE
 * -------------------------------------------------------------
 * En `localStorage`, con una clave por tabla. Guardar el índice sería repetir el
 * error que este módulo ya pagó dos veces: si alguien agrega o saca una columna,
 * el índice guardado apunta a otra cosa y la tabla abre ordenada por una columna
 * que el usuario no eligió. Con el nombre, una columna que ya no existe hace que
 * la preferencia se descarte, que es lo correcto.
 *
 * Con una excepción: una columna que CAMBIA DE RÓTULO sin dejar de ser la misma
 * columna. El `<th id="thCobroCob">` de Cobranzas FR dice "F. Prob. Cobro" en
 * Pendientes Proyectados y "Cobro" en Real a Cobrar, y es la misma fecha en las
 * dos. Ordenar por ella en una solapa y perder el orden al pasar a la otra no es
 * "la columna ya no existe": es la misma columna con otro nombre. Para eso el
 * `<th>` puede declarar `data-orden-nombre` con un nombre estable, y el control
 * lo usa en lugar del texto. Es el mismo idioma de escape que `data-orden` en el
 * `<td>` y `data-orden="no"` en la `<table>`: opcional, y lo que no lo declara
 * se sigue nombrando por su texto.
 *
 * ABRIR CON UN ORDEN, SIN PISARLE LA ELECCIÓN AL USUARIO
 * ------------------------------------------------------
 * `porDefecto: { columna: 'cobro', dir: 'asc' }` es con qué abre la tabla
 * CUANDO EL USUARIO NO ELIGIÓ NADA. No se guarda en `localStorage` —guardarlo
 * sería indistinguible de una elección a mano— y lo pisa cualquier click en un
 * encabezado. El nombre es el mismo que usa la preferencia: el
 * `data-orden-nombre` si la columna lo declara, y si no su texto.
 *
 * Pasa por el mismo `columnaActiva()` que la preferencia guardada, así que
 * hereda su guarda: SI LA COLUMNA NO ESTÁ VISIBLE, NO SE APLICA. Eso es lo que
 * hace que el default de Cobranzas FR valga en *Detalle Facturas* y no en
 * *Resumen*, donde la columna de cobro está oculta por CSS, sin que haga falta
 * una clave de preferencia por modo ni que el control sepa qué es un modo.
 *
 * SIN ORDEN TAMBIÉN ES UNA ELECCIÓN
 * ----------------------------------
 * El tercer click deja la tabla sin ordenar, y eso se guarda —como
 * `{"columna": null}`— en vez de borrar la clave. Borrarla haría que "saqué el
 * orden a mano" y "nunca elegí nada" quedaran iguales, y en una tabla con
 * `porDefecto` el orden que el usuario acaba de sacar reaparecería en la
 * recarga siguiente. En una tabla sin `porDefecto` las dos cosas dan el mismo
 * resultado, que es el de siempre.
 *
 * NO PUEDE ENTRAR EN BUCLE CON EL MutationObserver
 * ------------------------------------------------
 * `main.js` observa `#tabContent` por `childList`, y mover filas ES un cambio de
 * `childList`: reordenar dispara el observer, que vuelve a llamar acá. Por eso
 * `aplicar()` no escribe en el DOM si las filas ya están en el orden pedido, y
 * el indicador del encabezado es una CLASE y no un `<i>` agregado —las
 * mutaciones de atributos no se observan—. Con eso la segunda pasada no muta
 * nada y la cadena se corta. Es la misma precaución que toma columnas-fijas.js
 * con el `innerHTML` de su desplegable.
 */

var OrdenTabla = (function() {
    'use strict';

    var PREFIJO_STORAGE = 'cashflow.orden.';

    /** Controles vivos, para poder reaplicarlos cuando cambia el DOM */
    var controles = [];

    /** Las mismas abreviaturas que Horizonte::labelMes() */
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun',
                 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /* ================================================================
       LEER UNA CELDA
       ================================================================ */

    /**
     * Qué valor tiene una celda a los efectos del orden.
     *
     * El `data-orden` del `<td>` manda: es el escape para cuando el texto no
     * sirve (un badge que dice "Vencida 03/09", un ícono, una celda vacía con
     * el dato en el tooltip).
     *
     * Una celda con un campo de formulario se ordena por su `value`: su
     * `textContent` es vacío, así que sin esto la columna de fecha de cobro
     * editable de Cobranzas FR no se podría ordenar.
     *
     * @param {HTMLTableCellElement} celda
     * @returns {string}
     */
    function valorCrudo(celda) {
        if (!celda) {
            return '';
        }

        var dato = celda.getAttribute('data-orden');

        if (dato !== null) {
            return dato.trim();
        }

        var campo = celda.querySelector('input, select, textarea');

        if (campo) {
            return String(campo.value === null || campo.value === undefined
                ? '' : campo.value).trim();
        }

        return (celda.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /**
     * Un importe o un número en formato es-AR. Devuelve null si no es un número.
     *
     *   '$ 1.234,56'    -> 1234.56
     *   'USD -1.000,00' -> -1000
     *   '8%'            -> 8
     *   '30'            -> 30
     *
     * El punto es ambiguo: en `1.234` separa miles y en `1.5` es decimal. Se
     * resuelve por la forma —grupos de exactamente tres dígitos son miles— y no
     * suponiendo una de las dos, porque las dos aparecen: los importes salen de
     * `toLocaleString('es-AR')` y algún dato crudo de `data-orden` puede venir
     * con punto decimal.
     *
     * @param {string} texto
     * @returns {number|null}
     */
    function aNumero(texto) {
        var t = String(texto).replace(/[^\d,.\-]/g, '');

        if (t === '' || t === '-' || t === '.' || t === ',') {
            return null;
        }

        if (t.indexOf(',') !== -1) {
            // Hay coma: es es-AR, el punto separa miles.
            t = t.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) {
            // Sin coma, pero con grupos de tres: también son miles.
            t = t.replace(/\./g, '');
        }

        if (!/^-?\d+(\.\d+)?$/.test(t)) {
            return null;
        }

        return parseFloat(t);
    }

    /**
     * Una fecha, como número comparable `aaaammdd`. Acepta las dos formas que
     * aparecen: `dd/mm/aaaa`, que es lo que se muestra, y `aaaa-mm-dd`, que es
     * lo que suele llevar un `data-orden`.
     *
     * @param {string} texto
     * @returns {number|null}
     */
    function aFecha(texto) {
        var t = String(texto).trim();
        var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(t);

        if (m) {
            return Number(m[3]) * 10000 + Number(m[2]) * 100 + Number(m[1]);
        }

        m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t);

        if (m) {
            return Number(m[1]) * 10000 + Number(m[2]) * 100 + Number(m[3]);
        }

        return null;
    }

    /**
     * Un rótulo de mes de los que arma `Horizonte::labelMes()`: 'Sep-26' o
     * '2026-09'. Devuelve `aaaamm`.
     *
     * Existe porque si no, las tablas mensuales de Ventas se ordenarían
     * alfabéticamente: Abr, Ago, Dic, Ene… que es exactamente el defecto que
     * este archivo tiene que evitar.
     *
     * @param {string} texto
     * @returns {number|null}
     */
    function aMes(texto) {
        var t = String(texto).trim().toLowerCase();
        var m = /^([a-záéíóú]{3})-(\d{2})$/.exec(t);

        if (m) {
            var i = MESES.indexOf(m[1]);

            return (i === -1) ? null : (2000 + Number(m[2])) * 100 + (i + 1);
        }

        m = /^(\d{4})-(\d{1,2})$/.exec(t);

        return m ? Number(m[1]) * 100 + Number(m[2]) : null;
    }

    var PARSERS = {
        'fecha': aFecha,
        'mes': aMes,
        'numero': aNumero,
        'texto': function(v) { return v === '' ? null : v; }
    };

    /**
     * De qué tipo es una columna, según sus valores.
     *
     * Decide por MAYORÍA de los valores no vacíos y no por unanimidad: un 'N/A'
     * suelto en una columna de fechas —que es lo que deja getCobranzasFR()
     * cuando el comprobante no aparece en GVA12— la volvería una columna de
     * texto, y entonces las fechas se ordenarían por el día.
     *
     * @param {Array} valores
     * @returns {string} 'fecha' | 'mes' | 'numero' | 'texto'
     */
    function tipoDeColumna(valores) {
        var conValor = valores.filter(function(v) { return v !== ''; });

        if (!conValor.length) {
            return 'texto';
        }

        var mitad = conValor.length / 2;
        var candidatos = ['fecha', 'mes', 'numero'];

        for (var i = 0; i < candidatos.length; i++) {
            var tipo = candidatos[i];
            var cuantos = conValor.filter(function(v) {
                return PARSERS[tipo](v) !== null;
            }).length;

            if (cuantos > mitad) {
                return tipo;
            }
        }

        return 'texto';
    }

    /* ================================================================
       QUÉ COLUMNAS SE PUEDEN ORDENAR
       ================================================================ */

    /**
     * Recorre el `thead` armando la grilla real de celdas, que es la única forma
     * de saber a qué columna del `tbody` corresponde cada `<th>`: con `rowspan` y
     * `colspan` mezclados, el índice de la celda dentro de su fila no es el
     * índice de la columna.
     *
     * @param {HTMLTableElement} tabla
     * @returns {Array} [{th, indice, fila, colSpan, rowSpan}]
     */
    function celdasEncabezado(tabla) {
        var thead = tabla && tabla.tHead;

        if (!thead) {
            return [];
        }

        var ocupadas = {};
        var celdas = [];

        for (var r = 0; r < thead.rows.length; r++) {
            var fila = thead.rows[r];
            var col = 0;

            for (var c = 0; c < fila.cells.length; c++) {
                var th = fila.cells[c];

                while (ocupadas[r + '|' + col]) {
                    col++;
                }

                var rs = th.rowSpan || 1;
                var cs = th.colSpan || 1;

                for (var i = 0; i < rs; i++) {
                    for (var j = 0; j < cs; j++) {
                        ocupadas[(r + i) + '|' + (col + j)] = true;
                    }
                }

                celdas.push({ th: th, indice: col, fila: r, colSpan: cs, rowSpan: rs });
                col += cs;
            }
        }

        return celdas;
    }

    /**
     * Las columnas ordenables.
     *
     * Con un `thead` de una sola fila son todas las que ocupan una columna. Con
     * dos filas son las DESCRIPTIVAS —las que abarcan las dos filas— más la
     * columna `Total`, que vive abajo o al final según la tabla y se reconoce por
     * su clase. Las columnas de días quedan afuera a propósito: ver el
     * encabezado del archivo.
     *
     * @param {HTMLTableElement} tabla
     * @returns {Array} [{th, indice, nombre}]
     */
    function ordenables(tabla) {
        var thead = tabla && tabla.tHead;

        if (!thead || !thead.rows.length) {
            return [];
        }

        var unaFila = (thead.rows.length < 2);

        return celdasEncabezado(tabla).filter(function(c) {
            if (c.colSpan > 1) {
                return false;
            }

            if (unaFila) {
                return true;
            }

            if (c.fila === 0 && c.rowSpan > 1) {
                return true;
            }

            return c.th.classList.contains('total-column')
                || c.th.classList.contains('cf-col-total');
        }).map(function(c) {
            // `data-orden-nombre` manda sobre el texto: es para las columnas que
            // cambian de rótulo sin dejar de ser la misma columna. Ver el
            // encabezado del archivo.
            return {
                th: c.th,
                indice: c.indice,
                nombre: (c.th.getAttribute('data-orden-nombre') || '').trim()
                    || (c.th.textContent || '').replace(/\s+/g, ' ').trim()
                    || ('Columna ' + (c.indice + 1))
            };
        });
    }

    /** Si el encabezado está oculto, ordenar por él no se vería */
    function visible(th) {
        return window.getComputedStyle(th).display !== 'none';
    }

    /* ================================================================
       LAS FILAS Y SUS ANCLAS
       ================================================================ */

    /**
     * Una fila ANCLA no se mueve, y además parte el cuerpo en bloques: lo que
     * hay entre dos anclas se ordena por separado.
     *
     * Son dos casos:
     *
     *   - Una fila con UNA sola celda con `colspan`. No es un dato: es un texto
     *     —el rótulo de la sección del tablero, el "No hay facturas pendientes"
     *     del listado vacío, el corte por estado del pie de Echeqs—. Ordenarla
     *     junto con los datos la mandaría al medio de la tabla.
     *   - Las que declara la pestaña con `anclas`. Es lo que hace que el tablero
     *     se pueda ordenar sin romperse: sus subtotales y sus filas de arrastre
     *     significan lo que significan POR DONDE ESTÁN —`FLUJO_NETO` suma las
     *     filas que están por encima—, así que quedan clavadas y lo que se
     *     ordena son las filas de movimiento de cada sección.
     *
     * @param {HTMLTableRowElement} fila
     * @param {string|null} selectorAnclas
     * @returns {boolean}
     */
    function esAncla(fila, selectorAnclas) {
        if (fila.cells.length === 1 && (fila.cells[0].colSpan || 1) > 1) {
            return true;
        }

        return !!selectorAnclas && fila.matches(selectorAnclas);
    }

    /**
     * Una fila PEGADA no se ordena por su cuenta: viaja con la fila anterior.
     *
     * Es para las filas que son el detalle de la de arriba —las dos partes de
     * un concepto agrupado del tablero—, donde separarlas no da un error
     * visible sino un cuadro que se lee mal. Ver el encabezado.
     *
     * @param {HTMLTableRowElement} fila
     * @returns {boolean}
     */
    function esPegada(fila) {
        return fila.hasAttribute('data-orden-sigue');
    }

    /**
     * El orden natural es el que tenía la tabla cuando la dibujó el backend, y
     * es a lo que se vuelve en el tercer click.
     *
     * Se marca con una propiedad del elemento y no con un atributo: no toca el
     * DOM, no aparece en el HTML que se exporta a Excel y vive exactamente lo
     * que vive la fila. Cuando la pestaña redibuja el `tbody`, las filas nuevas
     * llegan sin la marca y el orden natural se vuelve a tomar del backend.
     */
    function marcarNatural(filas) {
        var falta = false;

        for (var i = 0; i < filas.length; i++) {
            if (filas[i].__ordenNatural === undefined) {
                falta = true;
                break;
            }
        }

        if (!falta) {
            return;
        }

        for (var j = 0; j < filas.length; j++) {
            filas[j].__ordenNatural = j;
        }
    }

    /* ================================================================
       PERSISTENCIA
       ================================================================ */

    /**
     * Lo que el usuario eligió para esta tabla. Distingue tres cosas, y las tres
     * importan cuando hay un `porDefecto`:
     *
     *   {columna:'Cobro', dir:'asc'} -> eligió ordenar por esa columna
     *   {columna: null}              -> eligió NO ordenar (el tercer click)
     *   null                         -> no eligió nada, o lo guardado ya no sirve
     *
     * @returns {Object|null}
     */
    function leerGuardado(clave) {
        try {
            var crudo = window.localStorage.getItem(PREFIJO_STORAGE + clave);

            if (!crudo) {
                return null;
            }

            var v = JSON.parse(crudo);

            if (!v || typeof v !== 'object') {
                return null;
            }

            if (v.columna === null) {
                return { columna: null, dir: 'asc' };
            }

            return (v.columna && (v.dir === 'asc' || v.dir === 'desc')) ? v : null;
        } catch (e) {
            // localStorage puede estar bloqueado (modo privado, política del
            // navegador). La tabla tiene que abrir sin orden, no romperse por
            // una preferencia.
            return null;
        }
    }

    /**
     * El "sin orden" se escribe, no se borra: es una elección y tiene que poder
     * distinguirse de no haber elegido nunca. Ver el encabezado del archivo.
     */
    function guardar(clave, estado) {
        try {
            window.localStorage.setItem(PREFIJO_STORAGE + clave,
                JSON.stringify(estado === null ? { columna: null } : estado));
        } catch (e) {
            /* sin persistencia, pero el orden igual se aplica */
        }
    }

    /**
     * El `porDefecto` de las opciones, validado. Devuelve null si no hay o si
     * está mal escrito: una opción con una falta de tipeo tiene que dejar la
     * tabla como estaba, no ordenarla por una columna inexistente.
     *
     * @returns {Object|null}
     */
    function defaultDeOpciones(opciones) {
        var d = opciones.porDefecto;

        if (!d || !d.columna) {
            return null;
        }

        return { columna: d.columna, dir: (d.dir === 'desc') ? 'desc' : 'asc' };
    }

    /* ================================================================
       EL CONTROL
       ================================================================ */

    function crear(opciones) {
        var clave = opciones.clave || opciones.tabla;
        var guardado = leerGuardado(clave);

        // El default sólo entra si el usuario no eligió nada. Un {columna:null}
        // guardado ES una elección -sacar el orden- y gana igual que ganaría una
        // columna elegida. Y no se guarda: mientras siga siendo el default, la
        // tabla no tiene preferencia escrita.
        var estado = guardado
            ? (guardado.columna ? guardado : null)
            : defaultDeOpciones(opciones);

        function tabla() {
            return document.getElementById(opciones.tabla);
        }

        /** La columna ordenable que coincide con el estado guardado, si sigue estando */
        function columnaActiva(cols) {
            if (!estado) {
                return null;
            }

            for (var i = 0; i < cols.length; i++) {
                if (cols[i].nombre === estado.columna && visible(cols[i].th)) {
                    return cols[i];
                }
            }

            return null;
        }

        /**
         * Ordena el cuerpo si hace falta.
         *
         * NO ESCRIBE EN EL DOM SI YA ESTÁ ORDENADO, y eso no es una
         * optimización: mover filas dispara el MutationObserver de main.js, que
         * vuelve a llamar acá. Sin esta guarda, el par observer -> ordenar no
         * pararía nunca.
         */
        var api = {
            aplicar: function() {
                var t = tabla();

                if (!t || !t.tHead || t.getAttribute('data-orden') === 'no') {
                    return;
                }

                var cols = ordenables(t);
                var activa = columnaActiva(cols);

                marcarEncabezado(t, cols, activa);
                conectar(t);

                var movio = false;

                for (var b = 0; b < t.tBodies.length; b++) {
                    movio = ordenarCuerpo(t.tBodies[b], activa) || movio;
                }

                if (movio) {
                    // Las celdas se llevan sus clases al moverse, pero el
                    // desplazamiento de las columnas fijas se mide sobre la
                    // tabla y conviene volver a medirlo.
                    if (window.ColumnasFijas) {
                        window.ColumnasFijas.reaplicar();
                    }
                }
            },

            /** Cambia el orden por código, como si se hubiera clickeado el encabezado */
            usar: function(nombreColumna, dir) {
                estado = nombreColumna ? { columna: nombreColumna, dir: dir || 'asc' } : null;

                guardar(clave, estado);
                api.aplicar();
            },

            /** @returns {Object|null} La columna y la dirección activas */
            estado: function() {
                return estado ? { columna: estado.columna, dir: estado.dir } : null;
            },

            /** Id de la tabla que maneja este control */
            idTabla: function() {
                return opciones.tabla;
            }
        };

        /* ---- El ciclo asc -> desc -> sin orden ------------------------ */

        function siguiente(nombre) {
            if (!estado || estado.columna !== nombre) {
                return { columna: nombre, dir: 'asc' };
            }

            return (estado.dir === 'asc') ? { columna: nombre, dir: 'desc' } : null;
        }

        function conectar(t) {
            if (t.__ordenConectado || !t.tHead) {
                return;
            }

            t.__ordenConectado = true;

            // Delegado en el thead y no en cada <th>: las pestañas regeneran el
            // encabezado en cada redibujo, y volver a enganchar celda por celda
            // dejaría oyentes duplicados.
            t.tHead.addEventListener('click', function(ev) {
                var th = ev.target.closest ? ev.target.closest('th') : null;

                if (!th || !t.tHead.contains(th)) {
                    return;
                }

                var cols = ordenables(t);

                for (var i = 0; i < cols.length; i++) {
                    if (cols[i].th === th) {
                        estado = siguiente(cols[i].nombre);
                        guardar(clave, estado);
                        api.aplicar();

                        return;
                    }
                }
            });
        }

        /* ---- El indicador del encabezado ----------------------------- */

        /**
         * El indicador va en CLASES y no agregando un `<i>`: las mutaciones de
         * atributos no las observa el MutationObserver de main.js, y un hijo
         * nuevo en el `<th>` sí. La flecha la dibuja el `::after` de
         * Css/main.css.
         */
        function marcarEncabezado(t, cols, activa) {
            var todas = t.tHead.querySelectorAll('th');

            for (var i = 0; i < todas.length; i++) {
                todas[i].classList.remove('orden-col', 'orden-asc', 'orden-desc');
                todas[i].removeAttribute('aria-sort');
            }

            cols.forEach(function(col) {
                col.th.classList.add('orden-col');

                if (activa && col.th === activa.th) {
                    col.th.classList.add(estado.dir === 'desc' ? 'orden-desc' : 'orden-asc');
                    col.th.setAttribute('aria-sort',
                        estado.dir === 'desc' ? 'descending' : 'ascending');
                }
            });
        }

        /* ---- Ordenar ------------------------------------------------- */

        /**
         * @returns {boolean} Si hubo que mover algo
         */
        function ordenarCuerpo(cuerpo, activa) {
            var filas = Array.prototype.slice.call(cuerpo.rows);

            if (filas.length < 2) {
                return false;
            }

            marcarNatural(filas);

            var destino = activa
                ? ordenadas(filas, activa, estado.dir, opciones.anclas)
                : filas.slice().sort(function(a, b) {
                    return a.__ordenNatural - b.__ordenNatural;
                });

            var igual = true;

            for (var i = 0; i < filas.length; i++) {
                if (filas[i] !== destino[i]) {
                    igual = false;
                    break;
                }
            }

            if (igual) {
                return false;
            }

            // appendChild sobre una fila que ya está en el cuerpo la mueve: no
            // hace falta vaciar nada, y así no se pierde el foco de un input
            // que esté dentro de una fila que no se movió.
            for (var j = 0; j < destino.length; j++) {
                cuerpo.appendChild(destino[j]);
            }

            return true;
        }

        return api;
    }

    /* ================================================================
       EL ORDEN, SIN DOM: ES LA PARTE QUE DECIDE
       ================================================================ */

    /**
     * Las filas ordenadas por una columna, respetando las anclas.
     *
     * Se ordena cada bloque de filas entre anclas por separado, y las anclas
     * quedan en su posición. Sin bloques, un subtotal del tablero terminaría en
     * el medio de otra sección.
     *
     * @param {Array} filas
     * @param {Object} columna {indice, nombre}
     * @param {string} dir 'asc' | 'desc'
     * @param {string|null} selectorAnclas
     * @returns {Array} Las mismas filas, en otro orden
     */
    function ordenadas(filas, columna, dir, selectorAnclas) {
        var valores = filas
            .filter(function(f) { return !esAncla(f, selectorAnclas); })
            .map(function(f) { return valorCrudo(f.cells[columna.indice]); });

        var tipo = tipoDeColumna(valores);
        var parser = PARSERS[tipo];
        var resultado = [];

        /* Cada UNIDAD es una fila que se ordena más las que viajan pegadas a
           ella. Ordenar unidades y no filas sueltas es todo el mecanismo: la
           unidad se mueve entera y sus partes nunca se separan de su fila. */
        var bloque = [];

        function volcar() {
            bloque.sort(function(a, b) {
                return comparar(
                    parser(valorCrudo(a[0].cells[columna.indice])),
                    parser(valorCrudo(b[0].cells[columna.indice])),
                    dir,
                    a[0].__ordenNatural - b[0].__ordenNatural
                );
            });

            bloque.forEach(function(unidad) {
                resultado = resultado.concat(unidad);
            });

            bloque = [];
        }

        filas.forEach(function(f) {
            if (esAncla(f, selectorAnclas)) {
                volcar();
                resultado.push(f);

                return;
            }

            // Sin nadie adelante en el bloque no hay de quién colgar, así que
            // se ordena como cualquier otra fila.
            if (esPegada(f) && bloque.length) {
                bloque[bloque.length - 1].push(f);

                return;
            }

            bloque.push([f]);
        });

        volcar();

        return resultado;
    }

    /**
     * Compara dos valores ya interpretados.
     *
     * LO VACÍO VA SIEMPRE AL FINAL, en las dos direcciones. Un vacío es ausencia
     * de dato, y en la punta de la tabla ocuparía justo el lugar donde uno está
     * buscando el máximo o el mínimo. Por eso el descarte se hace antes de dar
     * vuelta el signo.
     *
     * El desempate es el orden natural, así que el resultado es el mismo sin
     * depender de que el `sort` del navegador sea estable.
     *
     * @param {*} a Valor interpretado, o null
     * @param {*} b
     * @param {string} dir 'asc' | 'desc'
     * @param {number} desempate
     * @returns {number}
     */
    function comparar(a, b, dir, desempate) {
        if (a === null && b === null) {
            return desempate;
        }

        if (a === null) {
            return 1;
        }

        if (b === null) {
            return -1;
        }

        var cmp;

        if (typeof a === 'number' && typeof b === 'number') {
            cmp = a - b;
        } else {
            cmp = String(a).localeCompare(String(b), 'es',
                { numeric: true, sensitivity: 'base' });
        }

        if (cmp === 0) {
            return desempate;
        }

        return (dir === 'desc') ? -cmp : cmp;
    }

    /* ================================================================
       REAPLICACIÓN AUTOMÁTICA Y DESCUBRIMIENTO
       ================================================================ */

    /**
     * Vuelve a aplicar el orden de todas las tablas manejadas, y habilita el
     * orden en las que todavía no lo tienen. La llama main.js cuando el
     * MutationObserver ve que #tabContent cambió.
     *
     * SE DESCUBREN SOLAS, y es lo que hace que el control esté en las treinta y
     * seis tablas del módulo sin una llamada por pestaña. Una lista de pestañas
     * acá no cubriría la tabla que alguien agregue mañana, que es el mismo
     * motivo por el que columnas-fijas.js deriva sus columnas del encabezado en
     * vez de declararlas.
     *
     * La condición es tener `id`: es la clave con la que se guarda la
     * preferencia, y una clave por posición se mudaría de tabla en cuanto
     * alguien agregue una arriba.
     */
    function reaplicar() {
        controles = controles.filter(function(c) {
            return document.getElementById(c.idTabla()) !== null;
        });

        descubrir();

        controles.forEach(function(c) {
            c.aplicar();
        });
    }

    function descubrir() {
        var manejadas = controles.map(function(c) { return c.idTabla(); });
        var cont = document.getElementById('tabContent');
        var tablas = (cont || document).querySelectorAll('table[id]');

        for (var i = 0; i < tablas.length; i++) {
            var t = tablas[i];

            if (!t.tHead || t.getAttribute('data-orden') === 'no') {
                continue;
            }

            if (manejadas.indexOf(t.id) !== -1) {
                continue;
            }

            controles.push(crear({ tabla: t.id, clave: t.id }));
            manejadas.push(t.id);
        }
    }

    return {
        crear: function(opciones) {
            // Una pestaña que declara opciones reemplaza al control que el
            // descubrimiento automático pudo haber creado para esa tabla: el
            // declarado sabe cuáles son sus filas ancla y el automático no.
            controles = controles.filter(function(c) {
                return c.idTabla() !== opciones.tabla;
            });

            var api = crear(opciones);

            controles.push(api);
            api.aplicar();

            return api;
        },
        reaplicar: reaplicar,
        ordenables: ordenables,
        /* Expuestos para poder verificarlos desde la consola: son las reglas que
           deciden el orden, y todas son puras. */
        aNumero: aNumero,
        aFecha: aFecha,
        aMes: aMes,
        tipoDeColumna: tipoDeColumna
    };
})();

/** Misma forma de uso que crearEjeVistas() y crearColumnasFijas() */
function crearOrdenTabla(opciones) {
    return OrdenTabla.crear(opciones);
}
