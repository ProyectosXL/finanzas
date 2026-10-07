/**
 * eje-totales.js
 * El total de cada columna del eje, ARRIBA de su fecha. Quinto control
 * compartido de las tablas del módulo, junto a eje-vistas.js,
 * columnas-fijas.js, tabla-orden.js y tabla-export.js.
 *
 * POR QUÉ EXISTE
 * --------------
 * En una tabla con veintiocho columnas de días y cien filas, el total de una
 * fecha estaba en el pie: para saber cuánto entra el 8/9 había que bajar hasta
 * el final, y el número quedaba lejos de la fecha que lo explica. La fila de
 * arriba del encabezado tenía una sola celda con la leyenda "Días" o "Meses",
 * que ya dicen los botones de eje-vistas.js. Ese lugar ahora lleva, arriba de
 * cada fecha, su total.
 *
 * NO CALCULA NADA
 * ---------------
 * Recibe los totales YA CALCULADOS por la pestaña, que son los mismos con los
 * que la pestaña dibuja el pie, y la misma función con la que el pie escribe
 * cada celda. No suma filas ni lee la tabla: si este archivo hiciera su propia
 * cuenta, el día que una pestaña cambie qué filas suman —el buscador, "ver
 * excluidos", los cheques marcados— el pie diría una cosa y el encabezado otra,
 * y nada avisaría. Con una sola cuenta, los dos números son el mismo número.
 *
 * Por eso también el formato viene de la pestaña: cada pie tiene el suyo
 * —Comex deja en blanco lo que no es positivo, Tarjetas usa el formato corto,
 * Logística escribe un guión en el cero— y arriba se tiene que leer igual.
 *
 * CÓMO SE USA EN UNA PESTAÑA
 * --------------------------
 * En la función que dibuja el pie, con la misma cuenta:
 *
 *   pintarEjeTotales('tablaEcheqs', {
 *       columnas: cols,             // vistas.columnas(), las del eje visible
 *       valores: tot.columnas,      // un total por columna, en ese orden
 *       total: tot.total,           // se omite si la tabla no tiene columna Total
 *       formato: celdaEje,          // LA MISMA que usa el pie para cada columna
 *       formatoTotal: celdaTotal    // opcional; si falta, se usa `formato`
 *   });
 *
 * Como la llama la función del pie, se redibuja exactamente cuando el pie: al
 * buscar, al filtrar, al cambiar de solapa, de modo o de vista.
 *
 * QUÉ LE PIDE AL ENCABEZADO
 * -------------------------
 * Un `thead` de dos filas: arriba SÓLO las columnas descriptivas, con
 * `rowspan="2"`; abajo, una celda por columna del eje y al final la de Total,
 * si la tabla la tiene. Las celdas de totales se agregan al final de la fila
 * de arriba, y así cada una cae exactamente encima de su fecha.
 *
 * Si la fila de abajo no tiene tantas celdas como totales, NO SE PINTA NADA y
 * se avisa en la consola. Una fila de totales corrida un lugar no se ve como un
 * error: se ve como números, cada uno arriba de la fecha equivocada. Es
 * preferible que falte.
 *
 * CONVIVE CON LOS OTROS CUATRO
 * ----------------------------
 *   - tabla-orden.js: en un `thead` de dos filas ordena las celdas de arriba
 *     con rowspan y las que llevan `total-column` o `cf-col-total`. Las de
 *     totales no tienen ninguna de las dos cosas, así que no son ordenables, y
 *     la columna Total se sigue ordenando desde su encabezado de abajo.
 *   - columnas-fijas.js: las descriptivas son la corrida de celdas con rowspan
 *     del principio de la fila de arriba, y la corta la primera celda que no lo
 *     tiene. Antes la cortaba la celda de grupo y ahora la primera de totales:
 *     la cuenta es la misma.
 *   - tabla-export.js: las celdas llevan `data-exportar-omitir` y no bajan al
 *     Excel. El pie ya tiene los mismos números, y la planilla no tiene por qué
 *     tenerlos dos veces.
 *   - main.js: escribir acá dispara su MutationObserver, que reaplica el orden
 *     y las columnas fijas y vuelve a medir el alto de la primera fila del
 *     encabezado. No vuelve a llamar a este archivo, así que no hay bucle.
 *
 * LA REGLA PURA
 * -------------
 * Qué va en cada celda lo decide `EjeTotales.celdas()`, que no toca el DOM. Está
 * escrita sin nada posterior a ES3 —sin `map` ni `forEach`— para poder
 * verificarla fuera del navegador con `cscript`, el intérprete de JScript que
 * trae Windows: el proyecto no tiene `node` ni un corredor de JS. Ver
 * README-cashflow.md, "Los totales arriba del eje".
 */

var EjeTotales = (function() {
    'use strict';

    /** La clase de las celdas. El estilo está en Css/main.css */
    var CLASE = 'eje-total';

    /* ================================================================
       LA REGLA, SIN DOM
       ================================================================ */

    /**
     * Las celdas de la fila de totales, en orden: una por columna del eje y,
     * si hay total general, una más al final.
     *
     * `total` undefined o null quiere decir que la tabla NO TIENE columna Total
     * —Proveedores Locales—, y no que el total es cero: un cero se muestra con
     * el formato de la pestaña, una columna que no existe no lleva celda.
     *
     * @param {Array} valores Un total por columna del eje
     * @param {number} [total] El total general
     * @param {Function} [formato] Contenido de la celda de una columna
     * @param {Function} [formatoTotal] Contenido de la celda del total
     * @returns {Array} [{contenido, esTotal}]
     */
    function celdas(valores, total, formato, formatoTotal) {
        var fmt = (typeof formato === 'function') ? formato : texto;
        var fmtTotal = (typeof formatoTotal === 'function') ? formatoTotal : fmt;
        var lista = [];
        var i;

        for (i = 0; i < valores.length; i++) {
            lista.push({ contenido: String(fmt(valores[i])), esTotal: false });
        }

        if (tieneTotal(total)) {
            lista.push({ contenido: String(fmtTotal(total)), esTotal: true });
        }

        return lista;
    }

    function tieneTotal(total) {
        return total !== undefined && total !== null;
    }

    function texto(v) {
        return (v === undefined || v === null) ? '' : String(v);
    }

    /* ================================================================
       EL DOM
       ================================================================ */

    /**
     * Pinta los totales arriba de las fechas de una tabla.
     *
     * Las celdas se agregan con createElement y no reescribiendo la fila con
     * innerHTML: en la fila de arriba están las descriptivas, y alguna tiene un
     * control vivo —el tilde de "seleccionar todos" de Echeqs y Proveedores
     * Locales— que perdería su oyente si se lo volviera a escribir.
     *
     * @param {string|HTMLTableElement} tablaOId
     * @param {Object} opciones Ver el encabezado del archivo
     * @returns {boolean} Si se pintó
     */
    function pintar(tablaOId, opciones) {
        var tabla = (typeof tablaOId === 'string')
            ? document.getElementById(tablaOId) : tablaOId;
        var o = opciones || {};

        if (!tabla || !tabla.tHead || tabla.tHead.rows.length < 2) {
            return false;
        }

        var arriba = tabla.tHead.rows[0];
        var abajo = tabla.tHead.rows[1];

        quitar(arriba);

        var valores = o.valores || [];

        // Dos chequeos de alineación. El primero es contra la pestaña: si los
        // valores no son uno por columna, la cuenta se hizo sobre otras
        // columnas. El segundo es contra el encabezado ya dibujado.
        if (o.columnas && o.columnas.length !== valores.length) {
            avisar(tabla, o.columnas.length + ' columnas y ' + valores.length + ' totales');

            return false;
        }

        var lista = celdas(valores, o.total, o.formato, o.formatoTotal);

        if (abajo.cells.length !== lista.length) {
            avisar(tabla, 'la fila de fechas tiene ' + abajo.cells.length
                + ' celdas y hay ' + lista.length + ' totales');

            return false;
        }

        for (var i = 0; i < lista.length; i++) {
            var th = document.createElement('th');

            th.className = CLASE + (lista[i].esTotal ? ' ' + CLASE + '-general' : '');
            th.setAttribute('data-eje-total', '');
            th.setAttribute('data-exportar-omitir', '');
            th.title = lista[i].esTotal
                ? 'Total general. Es el mismo número del pie.'
                : 'Total de ' + textoCelda(abajo.cells[i]) + '. Es el mismo número del pie.';
            th.innerHTML = lista[i].contenido;

            arriba.appendChild(th);
        }

        return true;
    }

    /** Saca las celdas que pintó la vez anterior, y sólo esas */
    function quitar(fila) {
        for (var c = fila.cells.length - 1; c >= 0; c--) {
            if (fila.cells[c].hasAttribute('data-eje-total')) {
                fila.deleteCell(c);
            }
        }
    }

    function textoCelda(celda) {
        return (celda.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function avisar(tabla, motivo) {
        if (window.console && console.warn) {
            console.warn('eje-totales: no se pintan los totales de #' + tabla.id
                + ' para no mostrarlos corridos (' + motivo + ').');
        }
    }

    return {
        pintar: pintar,
        /* Expuesta para poder verificarla sin navegador: es la regla pura */
        celdas: celdas,
        CLASE: CLASE
    };
})();

/** Misma forma de uso que crearEjeVistas(), crearColumnasFijas() y exportarTabla() */
function pintarEjeTotales(tablaOId, opciones) {
    return EjeTotales.pintar(tablaOId, opciones);
}
