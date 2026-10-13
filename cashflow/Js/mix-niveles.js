/**
 * mix-niveles.js
 * El color del tipo de nivel de cada nodo del árbol del mix de cobro, para
 * las dos pantallas que lo muestran: el editor de Parámetros › Ventas › Mix de
 * Cobro y Plazos (Js/Parametros.js) y Cobranza Proyectada
 * (Js/Ingresos-Ventas.js).
 *
 * POR QUÉ EXISTE
 * --------------
 * En un árbol de cuatro o cinco niveles la sangría sola no alcanza, y además
 * engaña: se pueden saltear niveles, así que Mercado Pago debajo de Mercado
 * Libre (Medio de pago) queda a la misma sangría que Crédito (Tipo de
 * tarjeta). El color es POR TIPO y significa lo mismo en las dos pantallas.
 * Para que eso sea cierto, las dos arman las clases y la leyenda acá, y los
 * colores viven sólo en Css/mix-niveles.css.
 *
 * NO SABE CUÁLES SON LOS TIPOS
 * ----------------------------
 * Ni su orden ni sus rótulos: los recibe de la pestaña, que los trae del
 * servidor (`niveles`, armado de MixCobro::NIVELES). Si se agrega un tipo, la
 * leyenda lo muestra sin tocar este archivo; si todavía no tiene color, se ve
 * gris (el neutro de `.mix-nivel`) y tests/test_mix_niveles.php avisa.
 *
 * CÓMO SE USA
 * -----------
 *   '<tr class="mixa-nodo ' + MixNiveles.clase(n.NIVEL) + '">'   la fila
 *   MixNiveles.barra()                     la barra, después de la sangría
 *   MixNiveles.etiqueta(codigo, rotulo)    el rótulo con su color
 *   MixNiveles.leyenda(niveles, codigos)   los tipos en uso, en orden
 *   tr.className = MixNiveles.conClase(tr.className, nuevo)   al cambiar el nivel
 *
 * La barra y el desplegable no llevan clase propia de tipo: heredan las
 * variables de la fila, así cambiar el nivel es cambiar una clase.
 *
 * ES3
 * ---
 * Sin `map`, `filter` ni `forEach`, y sin tocar el DOM: así se verifica fuera
 * del navegador con `cscript` (tests/js/mix_niveles.wsf), igual que
 * EjeTotales.celdas(). El proyecto no tiene `node`.
 */

var MixNiveles = (function() {
    'use strict';

    /** Las clases de tipo empiezan así; la base, sin sufijo, es el neutro */
    var PREFIJO = 'mix-nivel-';

    /**
     * El código como sufijo de clase. Los códigos son de la lista del
     * servidor (MAYÚSCULAS y guión bajo), pero el valor termina dentro de un
     * atributo: lo que no es letra, número o guión se descarta.
     */
    function sufijo(codigo) {
        return String(codigo === null || codigo === undefined ? '' : codigo)
            .replace(/[^A-Za-z0-9_-]/g, '');
    }

    /** Las clases de una fila o etiqueta de ese tipo: la base y la del código */
    function clase(codigo) {
        return 'mix-nivel ' + PREFIJO + sufijo(codigo);
    }

    /**
     * Un className con el tipo cambiado: saca la clase de tipo que tenga y
     * pone la nueva, sin tocar las demás (mixa-nodo, mix-inactivo...). Es lo
     * que hace que la fila cambie de color en el momento en que se elige otro
     * nivel en el desplegable.
     */
    function conClase(className, codigo) {
        var partes = String(className || '').split(/\s+/);
        var quedan = [];

        for (var i = 0; i < partes.length; i++) {
            var p = partes[i];

            if (p !== '' && p !== 'mix-nivel' && p.indexOf(PREFIJO) !== 0) {
                quedan.push(p);
            }
        }

        quedan.push(clase(codigo));

        return quedan.join(' ');
    }

    /** La barra de color: la clase de la fila le da el tono */
    function barra() {
        return '<span class="mix-nivel-barra" aria-hidden="true"></span>';
    }

    /**
     * El rótulo con el color de su tipo. Lleva su propia clase de tipo
     * para valer igual fuera de una fila: en la leyenda.
     */
    function etiqueta(codigo, rotulo) {
        return '<span class="mix-nivel-etiqueta ' + clase(codigo) + '">' +
               escapar(rotulo === null || rotulo === undefined || rotulo === '' ? codigo : rotulo) +
               '</span>';
    }

    /**
     * Los tipos que se usan, en el orden de `niveles`.
     *
     * Sólo los que aparecen en `codigos`: un tipo que el árbol no usa no
     * explica ningún color de la pantalla. Un código que NO está en `niveles`
     * no debería existir —el CHECK de la columna lo impide—, pero si aparece
     * se ve gris en su fila, así que va al final, con el código como rótulo,
     * para que la leyenda explique también ese gris.
     *
     * @param {Array} niveles [{codigo, rotulo}] en el orden de la jerarquía
     * @param {Array} codigos El nivel de cada nodo dibujado, repetidos o no
     * @returns {Array} [{codigo, rotulo}]
     */
    function enUso(niveles, codigos) {
        var usados = {};
        var conocidos = {};
        var lista = [];
        var i;

        for (i = 0; i < (codigos || []).length; i++) {
            usados[codigos[i]] = true;
        }

        for (i = 0; i < (niveles || []).length; i++) {
            conocidos[niveles[i].codigo] = true;

            if (usados[niveles[i].codigo] === true) {
                lista.push({ codigo: niveles[i].codigo, rotulo: niveles[i].rotulo });
            }
        }

        for (i = 0; i < (codigos || []).length; i++) {
            var c = codigos[i];

            if (c !== null && c !== undefined && c !== '' && conocidos[c] !== true) {
                conocidos[c] = true;
                lista.push({ codigo: c, rotulo: c });
            }
        }

        return lista;
    }

    /**
     * La leyenda: una etiqueta por tipo en uso. Vacía si no hay ninguno, para
     * que la pestaña pueda esconder su contenedor.
     */
    function leyenda(niveles, codigos) {
        var tipos = enUso(niveles, codigos);
        var html = '';

        for (var i = 0; i < tipos.length; i++) {
            html += etiqueta(tipos[i].codigo, tipos[i].rotulo);
        }

        return html === '' ? '' : '<span class="mix-niveles-leyenda-titulo">Niveles</span>' + html;
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    return {
        clase: clase,
        conClase: conClase,
        barra: barra,
        etiqueta: etiqueta,
        enUso: enUso,
        leyenda: leyenda
    };
})();
