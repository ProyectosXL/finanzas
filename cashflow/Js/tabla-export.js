/**
 * tabla-export.js
 * Exportar una tabla a Excel. Cuarto control compartido de las tablas del
 * módulo, junto a eje-vistas.js, columnas-fijas.js y tabla-orden.js.
 *
 * POR QUÉ EXISTE
 * --------------
 * `exportarExcel()` estaba copiada cinco veces —Cobranzas FR, Cobranzas May,
 * Exportaciones Tasky, Crono Nacionalización, Ventas y el tablero—, con seis
 * diferencias entre las copias: una envolvía en `<html>` con `charset` y las
 * otras no (y sin eso Excel abre las razones sociales con acentos rotos), una
 * clonaba la tabla y las demás exportaban el nodo vivo, y ninguna sacaba los
 * `<input>` ni los botones. La mitad de las pestañas con tabla no tenía botón.
 *
 * EXPORTA LO QUE SE VE
 * --------------------
 * Y eso es lo único defendible: quien aprieta *Exportar* después de buscar un
 * cliente, filtrar por fecha de emisión y ordenar por importe espera bajar eso
 * y no la tabla entera. Sale del DOM vivo, así que el orden aplicado y el filtro
 * server-side vienen puestos; lo que hay que hacer a mano es sacar del clon las
 * filas y las columnas que el CSS está escondiendo —el buscador esconde filas
 * con `display: none`, y el modo Resumen esconde columnas con una regla de
 * `nth-child`—. Excel no interpreta ese CSS: si no se sacan, en la planilla
 * aparece todo.
 *
 * LO QUE NO ES TEXTO NO ENTRA
 * ---------------------------
 * Del clon se van los `<input>`, `<select>`, `<button>`, `<i>` y `<svg>`,
 * dejando el VALOR como texto. La celda de fecha de cobro editable de Cobranzas
 * FR es el caso que lo pide: exportada tal cual, la columna llega a Excel con un
 * control de formulario en vez de una fecha.
 *
 * CÓMO SE USA
 * -----------
 * Desde el HTML, y entonces no hace falta una línea de JS:
 *
 *   <button class="btn btn-sm btn-success" data-exportar="tablaSaldos"
 *           data-exportar-nombre="Saldos">
 *       <i class="fas fa-file-excel me-1"></i> Exportar
 *   </button>
 *
 * `reaplicar()` engancha todos los botones con `data-exportar`, y la llama
 * main.js cuando el MutationObserver ve que #tabContent cambió. Donde hay más de
 * una tabla va un botón por tabla, en su propia `card-header`.
 *
 * O desde el JS de la pestaña, para los botones que ya existían:
 *
 *   exportarTabla('tablaCobranzasFR', 'Cobranzas_FR');
 *
 * EL NOMBRE DEL ARCHIVO
 * ---------------------
 * `<Pestaña>_<YYYY-MM-DD>.xls`. Si no se declara un nombre se usa el título de
 * la página, que sale del menú (`data-encabezado`): así una pestaña nueva no
 * exporta un archivo llamado `undefined` ni hay que acordarse de declararlo en
 * dos lugares.
 */

var TablaExport = (function() {
    'use strict';

    /** Lo que se saca del clon: no es texto y Excel no lo entiende */
    var SELECTOR_CONTROLES = 'input, select, textarea, button, i, svg, .dropdown';

    /* ================================================================
       EL CLON QUE SE EXPORTA
       ================================================================ */

    /**
     * Prepara el clon de la tabla: saca lo que no se ve y convierte a texto lo
     * que no es texto.
     *
     * Recorre la tabla VIVA y el clon en paralelo, porque la visibilidad sólo se
     * puede preguntar sobre el nodo que está en el documento: un clon suelto no
     * tiene estilo calculado, así que `getComputedStyle` sobre el clon diría que
     * todo se ve.
     *
     * @param {HTMLTableElement} viva
     * @returns {HTMLTableElement} El clon, ya limpio
     */
    function prepararClon(viva) {
        var clon = viva.cloneNode(true);
        var filasVivas = viva.querySelectorAll('tr');
        var filasClon = clon.querySelectorAll('tr');

        // De atrás para adelante: sacar un nodo corre los índices de los que
        // siguen, y así los que quedan por revisar no se mueven.
        for (var f = filasVivas.length - 1; f >= 0; f--) {
            var viva_f = filasVivas[f];
            var clon_f = filasClon[f];

            if (!clon_f) {
                continue;
            }

            if (oculto(viva_f)) {
                clon_f.parentNode.removeChild(clon_f);
                continue;
            }

            for (var c = viva_f.cells.length - 1; c >= 0; c--) {
                if (oculto(viva_f.cells[c]) && clon_f.cells[c]) {
                    clon_f.deleteCell(c);
                }
            }
        }

        aTexto(clon);

        return clon;
    }

    function oculto(el) {
        return window.getComputedStyle(el).display === 'none';
    }

    /**
     * Reemplaza los controles por su valor en texto.
     *
     * Un `<input type="date">` se exporta como `dd/mm/aaaa` y no como su `value`
     * ISO: lo que se baja tiene que decir lo mismo que lo que está en pantalla.
     * Un botón o un ícono no tienen valor y simplemente se van.
     *
     * @param {HTMLElement} clon
     */
    function aTexto(clon) {
        var controles = clon.querySelectorAll(SELECTOR_CONTROLES);

        for (var i = 0; i < controles.length; i++) {
            var el = controles[i];

            if (!el.parentNode) {
                continue;   // ya salió con un padre anterior
            }

            var valor = '';

            if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    valor = el.checked ? 'Sí' : 'No';
                } else {
                    valor = comoFecha(el.value);
                }
            }

            el.parentNode.replaceChild(clon.ownerDocument.createTextNode(valor), el);
        }
    }

    /** 'aaaa-mm-dd' -> 'dd/mm/aaaa'; cualquier otra cosa vuelve igual */
    function comoFecha(valor) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(valor === null
            || valor === undefined ? '' : valor).trim());

        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : String(valor || '');
    }

    /* ================================================================
       EL ARCHIVO
       ================================================================ */

    /**
     * Baja una tabla como .xls.
     *
     * La técnica es la que ya usaba el módulo: un blob de HTML con el tipo MIME
     * de Excel. No genera un xlsx de verdad —eso pide una librería, y no se
     * suma ninguna—, pero Excel lo abre con las columnas separadas y los
     * formatos de la tabla, que es para lo que se usa.
     *
     * El `<meta charset>` NO es decoración: sin él Excel abre las razones
     * sociales con los acentos rotos. Sólo una de las cinco copias lo tenía.
     *
     * @param {string} idTabla
     * @param {string} [nombre] Base del nombre del archivo
     * @returns {boolean} Si se pudo exportar
     */
    function exportar(idTabla, nombre) {
        var tabla = document.getElementById(idTabla);

        if (!tabla) {
            return false;
        }

        var html = '<html><head><meta charset="utf-8"></head><body>'
            + prepararClon(tabla).outerHTML
            + '</body></html>';

        var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');

        a.href = url;
        a.download = nombreArchivo(nombre);

        // Tiene que estar en el documento para que el click cuente en Firefox.
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        return true;
    }

    /** `<Pestaña>_<YYYY-MM-DD>.xls` */
    function nombreArchivo(nombre) {
        return slug(nombre || tituloPestana() || 'Tabla') + '_' + hoyISO() + '.xls';
    }

    /**
     * El título de la página, que lo pone updateHeader() a partir del menú. Es
     * la única fuente del nombre de la pestaña que no hay que mantener a mano en
     * un segundo lugar.
     */
    function tituloPestana() {
        var el = document.getElementById('pageTitle');

        return el ? (el.textContent || '').trim() : '';
    }

    /**
     * Un nombre de archivo sin acentos, sin espacios y sin nada raro.
     * Se descomponen los acentos y se tiran las marcas, así "Cobranzas
     * Electrónicas" queda "Cobranzas_Electronicas" y no "Cobranzas_Electr_nicas".
     */
    function slug(texto) {
        return String(texto)
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^A-Za-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function hoyISO() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dia = String(d.getDate()).padStart(2, '0');

        return d.getFullYear() + '-' + m + '-' + dia;
    }

    /* ================================================================
       LOS BOTONES DECLARADOS EN EL HTML
       ================================================================ */

    /**
     * Engancha todos los botones con `data-exportar`. La llama main.js cuando el
     * MutationObserver ve que #tabContent cambió, así que una pestaña nueva no
     * tiene que acordarse de nada.
     *
     * El oyente se marca con una propiedad del elemento y no con un atributo:
     * las mutaciones de atributos no las observa el MutationObserver, así que no
     * puede realimentarse. Es la misma precaución de tabla-orden.js.
     */
    function reaplicar() {
        var botones = document.querySelectorAll('[data-exportar]');

        for (var i = 0; i < botones.length; i++) {
            var b = botones[i];

            if (b.__exportConectado) {
                continue;
            }

            b.__exportConectado = true;

            b.addEventListener('click', function(ev) {
                var btn = ev.currentTarget;

                exportar(btn.getAttribute('data-exportar'),
                    btn.getAttribute('data-exportar-nombre'));
            });
        }
    }

    return {
        exportar: exportar,
        reaplicar: reaplicar,
        /* Expuestos para poder verificarlos desde la consola */
        prepararClon: prepararClon,
        nombreArchivo: nombreArchivo
    };
})();

/** Misma forma de uso que los otros controles compartidos */
function exportarTabla(idTabla, nombre) {
    return TablaExport.exportar(idTabla, nombre);
}
