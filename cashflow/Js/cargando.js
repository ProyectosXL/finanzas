/**
 * Cargando
 * El indicador de carga de TODAS las pestanas del cashflow.
 *
 * POR QUE UNO SOLO
 * ----------------
 * Habia diecinueve copias del mismo marcado (.loading-spinner) y diez copias
 * de su CSS, una por pestana, con prefijos distintos. Las pestanas que no
 * traian su copia -el tablero, Proyeccion, Proveedores Locales y las
 * sub-pestanas de Parametros- se veian bien solo si antes se habia abierto
 * otra cuyo CSS definia la regla sin prefijo. Y ninguna decia cuanto llevaba
 * esperando: con pedidos de 30 o 40 segundos, un circulo girando no distingue
 * "esta trabajando" de "se colgo".
 *
 * LA API
 * ------
 *   Cargando.mostrar(contenedor, { titulo, pasos: [...], overlay })
 *   Cargando.paso(contenedor, indice, estado)
 *   Cargando.ocultar(contenedor)
 *
 * 'contenedor' es un elemento o su id.
 *
 *   titulo   el texto grande. Si no viene, se usa el data-cargando del
 *            contenedor, y si tampoco, "Cargando…". Asi el texto de cada
 *            pestana vive en su HTML y no repetido en el JS.
 *   pasos    opcional: textos, o { texto, estado }. Cada paso arranca
 *            'pendiente' salvo que diga otro estado. Una pestana que hace
 *            varios pedidos marca cada uno al resolverse con paso().
 *   overlay  false (por defecto): el contenedor ES el lugar del indicador, y
 *            se muestra y se oculta entero.
 *            true: el contenedor es CONTENIDO YA DIBUJADO -una tabla-, y el
 *            indicador va encima, sin sacarla. Es para recargar sin que la
 *            tabla salte ni se pierda la posicion del scroll.
 *
 *   estados de un paso: 'pendiente' | 'en_curso' | 'listo' | 'error'
 *
 * Muestra los segundos que lleva, en vivo, y a los 10 s agrega "Esto puede
 * tardar un poco mas". El contador NO se anuncia a los lectores de pantalla
 * -anunciar cada segundo seria ruido-; el titulo, los pasos y la nota de los
 * 10 s si, porque van en una region role="status" aria-live="polite".
 *
 * Sin librerias: Bootstrap y Font Awesome ya estan en la pagina, y el giro es
 * CSS (Css/cargando.css), que respeta prefers-reduced-motion.
 */
(function(global) {
    'use strict';

    /** A partir de cuantos segundos se avisa que va a tardar */
    var SEGUNDOS_DEMORA = 10;

    var ESTADOS = {
        pendiente: { icono: '<i class="fa-regular fa-circle" aria-hidden="true"></i>', texto: 'pendiente' },
        en_curso: { icono: '<span class="cargando-mini" aria-hidden="true"></span>', texto: 'en curso' },
        listo: { icono: '<i class="fas fa-check" aria-hidden="true"></i>', texto: 'listo' },
        error: { icono: '<i class="fas fa-xmark" aria-hidden="true"></i>', texto: 'error' }
    };

    /** El estado de cada contenedor activo: timer, inicio, nodos */
    var activos = typeof WeakMap === 'function' ? new WeakMap() : null;

    function resolver(contenedor) {
        if (!contenedor) { return null; }

        return (typeof contenedor === 'string') ? document.getElementById(contenedor) : contenedor;
    }

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function leer(el) {
        return activos ? activos.get(el) : el.__cargando;
    }

    function guardar(el, estado) {
        if (activos) {
            if (estado) { activos.set(el, estado); } else { activos['delete'](el); }
        } else {
            el.__cargando = estado || undefined;
        }
    }

    function normalizarPasos(pasos) {
        return (pasos || []).map(function(p) {
            var paso = (typeof p === 'string') ? { texto: p } : (p || {});
            var estado = ESTADOS[paso.estado] ? paso.estado : 'pendiente';

            return { texto: paso.texto || '', estado: estado };
        });
    }

    function htmlPaso(p) {
        var e = ESTADOS[p.estado];

        return '<li class="cargando-paso cargando-paso--' + p.estado + '">' +
            '<span class="cargando-paso-icono">' + e.icono + '</span>' +
            '<span class="cargando-paso-texto">' + esc(p.texto) + '</span>' +
            '<span class="visually-hidden">: ' + e.texto + '</span>' +
        '</li>';
    }

    function html(titulo, pasos) {
        return '<div class="cargando-caja" role="status" aria-live="polite">' +
            '<div class="cargando-cabeza">' +
                '<span class="cargando-anillo" aria-hidden="true"></span>' +
                '<div class="cargando-textos">' +
                    '<div class="cargando-titulo">' + esc(titulo) + '</div>' +
                    '<div class="cargando-tiempo" aria-hidden="true">0 s</div>' +
                '</div>' +
            '</div>' +
            (pasos.length
                ? '<ul class="cargando-pasos">' + pasos.map(htmlPaso).join('') + '</ul>'
                : '') +
            '<div class="cargando-demora" hidden>Esto puede tardar un poco más.</div>' +
        '</div>';
    }

    /**
     * Muestra el indicador. Si ya estaba, lo reemplaza y el contador vuelve a
     * cero: es una carga nueva.
     */
    function mostrar(contenedor, opciones) {
        var el = resolver(contenedor);

        if (!el) { return; }

        opciones = opciones || {};
        ocultar(el);

        var titulo = opciones.titulo || (el.getAttribute('data-cargando') || '') || 'Cargando…';
        var pasos = normalizarPasos(opciones.pasos);
        var overlay = !!opciones.overlay;
        var nodo;

        if (overlay) {
            nodo = document.createElement('div');
            nodo.className = 'cargando cargando--overlay';
            nodo.innerHTML = html(titulo, pasos);
            el.classList.add('cargando-anfitrion');
            el.appendChild(nodo);
        } else {
            el.classList.add('cargando');
            el.innerHTML = html(titulo, pasos);
            el.hidden = false;
            el.style.display = '';
            nodo = el;
        }

        el.setAttribute('aria-busy', 'true');

        var estado = {
            nodo: nodo,
            overlay: overlay,
            pasos: pasos,
            inicio: Date.now(),
            timer: null
        };

        var tiempo = nodo.querySelector('.cargando-tiempo');
        var demora = nodo.querySelector('.cargando-demora');

        estado.timer = setInterval(function() {
            /* Si la pestana se reemplazo entera -loadTab pisa el contenido-,
               el nodo ya no esta en la pagina: el timer se corta solo. */
            if (!nodo.isConnected) {
                clearInterval(estado.timer);
                guardar(el, null);

                return;
            }

            var s = Math.floor((Date.now() - estado.inicio) / 1000);

            if (tiempo) { tiempo.textContent = s + ' s'; }

            if (demora && s >= SEGUNDOS_DEMORA && demora.hidden) {
                demora.hidden = false;
            }
        }, 1000);

        guardar(el, estado);
    }

    /**
     * Marca el estado de un paso. Un indice fuera de rango no hace nada.
     */
    function paso(contenedor, indice, estadoPaso) {
        var el = resolver(contenedor);
        var estado = el ? leer(el) : null;

        if (!estado || !ESTADOS[estadoPaso] || !estado.pasos[indice]) { return; }

        estado.pasos[indice].estado = estadoPaso;

        var li = estado.nodo.querySelectorAll('.cargando-paso')[indice];

        if (li) { li.outerHTML = htmlPaso(estado.pasos[indice]); }
    }

    /**
     * Saca el indicador y corta el contador. Sobre un contenedor sin
     * indicador no hace nada, asi que se puede llamar siempre al terminar.
     */
    function ocultar(contenedor) {
        var el = resolver(contenedor);

        if (!el) { return; }

        var estado = leer(el);

        if (estado) {
            clearInterval(estado.timer);

            if (estado.overlay) {
                if (estado.nodo.parentNode) { estado.nodo.parentNode.removeChild(estado.nodo); }
                el.classList.remove('cargando-anfitrion');
            }

            guardar(el, null);
        }

        if (el.classList.contains('cargando')) {
            el.innerHTML = '';
            el.style.display = 'none';
        }

        el.removeAttribute('aria-busy');
    }

    /** Si el contenedor tiene un indicador a la vista */
    function activo(contenedor) {
        var el = resolver(contenedor);

        return !!(el && leer(el));
    }

    global.Cargando = {
        mostrar: mostrar,
        paso: paso,
        ocultar: ocultar,
        activo: activo,
        SEGUNDOS_DEMORA: SEGUNDOS_DEMORA
    };
})(window);
