/**
 * kpis-plegables.js — las tarjetas de indicadores, en una franja compacta.
 *
 * POR QUÉ. Las tarjetas KPI ocupan casi un tercio de la pantalla en cada
 * pestaña, y quien vuelve a la misma pestaña todos los días ya sabe qué mide
 * cada una: necesita el número, no el ícono ni el pie. Cada bloque de tarjetas
 * tiene un botón chico para pasar a una franja con el título y el valor de cada
 * tarjeta, y volver.
 *
 * ES UNA VARIANTE DE CSS SOBRE LAS MISMAS TARJETAS, y es la decisión que
 * importa: cada pestaña escribe sus valores por id (total4semanas,
 * cfKpiApertura…) y les pone sus clases de color (cf-negativo…). Una franja
 * aparte con copias de los valores mostraría un número viejo la primera vez que
 * alguien se olvide de actualizarla. Acá no hay copia: la clase .kpis-compacto
 * esconde el ícono y el pie y pone la tarjeta en un renglón.
 *
 * EL PIE PASA A SER EL TOOLTIP, y se lee del pie en el momento de pasar el
 * mouse, no al plegar: el pie también lo escribe la pestaña (el período que
 * mide, por ejemplo) y cambia con cada recarga.
 *
 *     <div class="row g-3 mb-4" id="summarySection" data-kpis="kpisCronoNac" style="display: none;">
 *
 * El valor de data-kpis es la CLAVE del bloque: la de localStorage
 * ('kpis_plegables_<clave>'). No se usa el id porque hay ids repetidos entre
 * pestañas -summarySection está en Proveedores Exterior y en Crono- y una
 * pestaña plegaría la otra.
 *
 *  - POR DEFECTO, EXPANDIDO: es lo que había.
 *  - SE RECUERDA POR BLOQUE, con try/catch: en un navegador que bloquea
 *    localStorage funciona igual y lo único que se pierde es que se recuerde.
 *  - NO TOCA LA VISIBILIDAD DEL BLOQUE: las pestañas lo esconden hasta tener
 *    datos (display: none en el HTML) y el botón vive adentro del bloque, así
 *    que aparece y desaparece con él.
 *  - Se conecta solo: al cargar la página y cada vez que cambia #tabContent
 *    -una pestaña nueva, o tarjetas que una pestaña dibuja por JS, como las de
 *    Fondos-. Si la pestaña reescribe el bloque entero, el botón se repone.
 *
 * LA PARTE PURA ESTÁ ESCRITA EN ES3 para poder correrla con cscript
 * (tests/js/kpis_plegables.wsf), igual que Js/avisos-panel.js.
 */

var KpisPlegables = (function() {
    'use strict';

    var PREFIJO = 'kpis_plegables_';

    /* ================================================================
       LA PARTE PURA (ES3)
       ================================================================ */

    /** La clave de localStorage de un bloque */
    function clave(nombre) {
        return PREFIJO + String(nombre === null || nombre === undefined ? '' : nombre);
    }

    /**
     * Si el bloque arranca compacto: sólo si el usuario lo dejó así. Sin
     * preferencia -o con una que no se pudo leer-, expandido.
     *
     * @param {boolean|null} preferencia true compacto, false expandido, null nunca
     */
    function compactoAlInicio(preferencia) {
        return preferencia === true;
    }

    /**
     * El texto del pie como tooltip: sin espacios de más, y vacío si el pie
     * no dice nada (algunos arrancan con &nbsp; hasta que llegan los datos).
     */
    function textoPie(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/[\s ]+/g, ' ')
            .replace(/^ | $/g, '');
    }

    /* ================================================================
       EN EL NAVEGADOR
       ================================================================ */

    function leer(nombre) {
        try {
            var v = localStorage.getItem(clave(nombre));

            return v === null ? null : v === '1';
        } catch (e) {
            return null;
        }
    }

    function guardar(nombre, compacto) {
        try {
            localStorage.setItem(clave(nombre), compacto ? '1' : '0');
        } catch (e) {
            // Se pliega igual; lo único que se pierde es que se recuerde.
        }
    }

    function boton(compacto) {
        var b = document.createElement('button');

        b.type = 'button';
        b.className = 'kpis-toggle';
        pintarBoton(b, compacto);

        return b;
    }

    function pintarBoton(b, compacto) {
        var texto = compacto ? 'Mostrar las tarjetas completas' : 'Contraer las tarjetas a una franja';

        b.setAttribute('aria-expanded', compacto ? 'false' : 'true');
        b.setAttribute('aria-label', texto);
        b.title = texto;
        b.innerHTML = '<i class="fas ' + (compacto ? 'fa-chevron-down' : 'fa-chevron-up') + '"></i>';
    }

    /** Pone o saca el modo compacto, y el tooltip de cada tarjeta con él */
    function aplicar(bloque, compacto) {
        bloque.classList.toggle('kpis-compacto', compacto);

        var b = bloque.querySelector(':scope > .kpis-toggle');

        if (b) {
            pintarBoton(b, compacto);
        }

        // Expandido, el pie se ve: el tooltip sobra y se saca.
        if (!compacto) {
            Array.prototype.forEach.call(bloque.querySelectorAll('.kpi-card[data-kpis-tooltip]'),
                function(card) {
                    card.removeAttribute('title');
                    card.removeAttribute('data-kpis-tooltip');
                });
        }
    }

    /** El botón, si la pestaña reescribió el bloque y se lo llevó */
    function asegurarBoton(bloque) {
        if (bloque.querySelector(':scope > .kpis-toggle')) {
            return;
        }

        var nombre = bloque.getAttribute('data-kpis');
        var b = boton(bloque.classList.contains('kpis-compacto'));

        b.addEventListener('click', function() {
            var compacto = !bloque.classList.contains('kpis-compacto');

            aplicar(bloque, compacto);
            guardar(nombre, compacto);
        });

        bloque.appendChild(b);
    }

    function conectarBloque(bloque) {
        if (!bloque.hasAttribute('data-kpis-conectado')) {
            bloque.setAttribute('data-kpis-conectado', '1');
            aplicar(bloque, compactoAlInicio(leer(bloque.getAttribute('data-kpis'))));
        }

        asegurarBoton(bloque);
    }

    /**
     * Conecta los bloques que todavía no lo están, y repone el botón de los
     * que lo perdieron. Se puede llamar las veces que haga falta.
     *
     * @param {Element|Document} [raiz]
     */
    function conectar(raiz) {
        Array.prototype.forEach.call((raiz || document).querySelectorAll('[data-kpis]'),
            conectarBloque);
    }

    /* El tooltip: el pie de la tarjeta, leído al pasar el mouse. Va en la
       tarjeta y no en el valor, así un title que la pestaña le haya puesto al
       valor sigue mandando. */
    function alPasar(ev) {
        var card = ev.target && ev.target.closest
            ? ev.target.closest('.kpis-compacto .kpi-card') : null;

        if (!card) {
            return;
        }

        var pie = card.querySelector('.kpi-card-footer');
        var texto = pie ? textoPie(pie.textContent) : '';

        if (texto) {
            card.title = texto;
            card.setAttribute('data-kpis-tooltip', '1');
        }
    }

    function iniciar() {
        conectar(document);
        document.addEventListener('mouseover', alPasar);

        var tab = document.getElementById('tabContent');

        if (tab && window.MutationObserver) {
            new MutationObserver(function() { conectar(tab); })
                .observe(tab, { childList: true, subtree: true });
        }
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', iniciar);
        } else {
            iniciar();
        }
    }

    return {
        conectar: conectar,

        // La parte pura, para tests/js/kpis_plegables.wsf
        clave: clave,
        compactoAlInicio: compactoAlInicio,
        textoPie: textoPie
    };
})();
