/**
 * Permisos de edicion en el navegador.
 *
 * Cada pestaña -y en Parametros, cada sub-pestaña- lleva en su raiz el atributo
 * data-puede-editar="1" o "0", que escribe el PHP con
 * AuthCashflow::atributoEdicion(). Lo que el HTML trae ya resuelto lo resuelve
 * el PHP: si no se puede editar, el boton no se dibuja. Esto es para lo que
 * arma el JS -celdas editables, botones de cada fila, iconos de lapiz-, que no
 * pasa por el PHP.
 *
 * UN SOLO LUGAR Y NO UN if SUELTO EN CADA ARCHIVO. Cada JS pregunta por el
 * elemento en el que esta dibujando, y la respuesta sale del ancestro mas
 * cercano con el atributo. Asi una sub-pestaña de Parametros contesta por si
 * misma, sin que el JS sepa como se llama su permiso.
 *
 * ES COMODIDAD, NO SEGURIDAD. Ocultar un boton no impide llamar al endpoint: lo
 * que lo impide es el 403 del servidor. Por eso, sin el atributo -una pestaña
 * vieja, o un elemento suelto- se contesta que NO: dibujar menos nunca abre
 * nada, y dibujar de mas solo termina en un 403 que el usuario no entiende.
 */
(function () {
    'use strict';

    /**
     * La raiz con el permiso para un elemento, o null.
     *
     * @param {Element|string} el El elemento, o su id
     * @returns {Element|null}
     */
    function raiz(el) {
        if (typeof el === 'string') {
            el = document.getElementById(el);
        }

        if (!el || !el.closest) {
            return null;
        }

        return el.closest('[data-puede-editar]');
    }

    /**
     * Si en el lugar donde esta el elemento se puede editar.
     *
     * @param {Element|string} el El elemento, o su id
     * @returns {boolean}
     */
    function puedeEditar(el) {
        var r = raiz(el);

        return !!r && r.getAttribute('data-puede-editar') === '1';
    }

    /**
     * El HTML de un control de edicion, o nada si no se puede editar.
     *
     * Es la forma corta para el caso mas comun: un boton o un icono que se
     * concatena en una fila.
     *
     * @param {Element|string} el Donde se va a dibujar
     * @param {string} html
     * @returns {string}
     */
    function siEdita(el, html) {
        return puedeEditar(el) ? html : '';
    }

    /**
     * El HTML editable o el de solo lectura, segun el permiso.
     *
     * Para una celda que con permiso es un input y sin permiso es el valor:
     * quien solo lee tiene que ver el dato igual, no una celda vacia.
     *
     * @param {Element|string} el
     * @param {string} editable
     * @param {string} lectura
     * @returns {string}
     */
    function segun(el, editable, lectura) {
        return puedeEditar(el) ? editable : lectura;
    }

    /** dd/mm/aaaa de un 'aaaa-mm-dd', o mm/aaaa de un 'aaaa-mm' */
    function fechaLegible(v) {
        var p = String(v || '').split('-');

        if (p.length === 3) { return p[2].substring(0, 2) + '/' + p[1] + '/' + p[0]; }
        if (p.length === 2) { return p[1] + '/' + p[0]; }

        return v || '';
    }

    /** El texto que muestra un control, sin el control */
    function textoDe(el) {
        var tag = el.tagName;

        if (tag === 'SELECT') {
            var opt = el.options[el.selectedIndex];

            return opt ? opt.text : '';
        }

        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked ? 'Sí' : 'No';
        }

        if (el.type === 'date' || el.type === 'month') {
            return fechaLegible(el.value);
        }

        // Un input-group suma su sufijo (%, días, U$S) al lado del valor
        var grupo = el.closest('.input-group');
        var extra = grupo ? grupo.querySelector('.input-group-text') : null;
        var valor = (el.value === '' || el.value === undefined) ? '—' : el.value;

        return extra ? valor + ' ' + extra.textContent : valor;
    }

    /**
     * Pasa a solo lectura lo que un JS acaba de dibujar, si no se puede editar.
     *
     * PARA LAS GRILLAS QUE SON UN FORMULARIO -las de Parametros, donde cada
     * celda es un input-, en lugar de un if en cada celda. Despues de dibujar,
     * el JS llama a esto sobre el contenedor de la grilla:
     *
     *   - cada input, select o textarea se reemplaza por su valor como texto,
     *     con el valor original en data-valor y sus clases y data-* intactos,
     *     asi el codigo que lee la grilla la sigue encontrando;
     *   - los botones se sacan, salvo los marcados data-lectura (un historial,
     *     un detalle: lo que se puede mirar sin poder cambiar).
     *
     * No toca nada fuera del contenedor: los filtros y el buscador de la
     * pantalla siguen andando.
     *
     * @param {Element|string} contenedor
     * @returns {boolean} Si se paso a solo lectura
     */
    function soloLectura(contenedor) {
        if (typeof contenedor === 'string') {
            contenedor = document.getElementById(contenedor);
        }

        if (!contenedor || puedeEditar(contenedor)) {
            return false;
        }

        contenedor.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (el.type === 'hidden') { return; }

            var span = document.createElement('span');

            span.className = el.className.replace(/\bform-(control|select|check-input)(-sm)?\b/g, '').trim();
            span.classList.add('permiso-lectura');
            span.textContent = textoDe(el);

            Object.keys(el.dataset).forEach(function(k) { span.dataset[k] = el.dataset[k]; });
            span.dataset.valor = (el.type === 'checkbox' || el.type === 'radio')
                ? (el.checked ? '1' : '0') : el.value;

            if (el.title) { span.title = el.title; }

            // El input-group y el switch quedan sin sentido: se reemplazan enteros
            var envoltorio = el.closest('.input-group, .form-check');

            if (envoltorio && contenedor.contains(envoltorio)) {
                envoltorio.replaceWith(span);
            } else {
                el.replaceWith(span);
            }
        });

        contenedor.querySelectorAll('button:not([data-lectura])').forEach(function(b) {
            b.remove();
        });

        return true;
    }

    window.Permisos = {
        puedeEditar: puedeEditar,
        siEdita: siEdita,
        segun: segun,
        soloLectura: soloLectura
    };
})();
