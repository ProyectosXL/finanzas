/**
 * eje-vistas.js
 * Las TRES VISTAS de una pantalla con eje temporal: Días, Meses y Período
 * completo. Es la contraparte de Class/EjeVista.php.
 *
 * POR QUÉ EXISTE
 * --------------
 * Cada pestaña tenía su propio par de botones, su propio estado de vista y su
 * propia forma de armar los encabezados. Eso dio tres comportamientos
 * distintos, y en tres de las pestañas el encabezado decía "Próximos 28 Días"
 * mientras la tabla mostraba los días del mes en curso.
 *
 * Acá NO se decide nada: qué columnas tiene cada vista y qué período mide lo
 * resuelve el backend en EjeVista y viene en el payload. Este archivo dibuja
 * los botones, mantiene cuál está activa y entrega las columnas visibles.
 * Si el criterio cambia, cambia en un solo lugar y en PHP.
 *
 * CÓMO SE USA EN UNA PESTAÑA
 * --------------------------
 *   var vistas = crearEjeVistas({
 *       botones: 'misBotones',        // id del contenedor donde van los botones
 *       periodo: 'miPeriodo',         // id del cartel que dice qué se está midiendo
 *       alCambiar: dibujarTabla       // se llama cuando el usuario cambia de vista
 *   });
 *
 *   vistas.usar(payload);             // cada vez que llegan datos del backend
 *   vistas.columnas()                 // ['DIA|2026-09-06', 'MES|2026-10', ...]
 *   vistas.rotulo(col)                // '6/9' o 'Oct-26'
 *   vistas.valor(fila, col)           // el importe de esa fila en esa columna
 *   vistas.total(fila)                // el total que corresponde a la vista activa
 *
 * EL TOTAL DEPENDE DE LA VISTA, y no es un detalle: la vista Meses cubre sólo
 * los días que quedaron fuera del tramo diario, así que su total NO es el del
 * horizonte. Mostrar siempre el total del horizonte haría que la columna Total
 * no cerrara con las columnas que se están viendo.
 */

/**
 * Crea el controlador de vistas de una pestaña.
 *
 * @param {Object} opciones
 * @param {string} opciones.botones  Id del contenedor de los botones
 * @param {string} [opciones.periodo] Id del elemento donde se escribe el período medido
 * @param {Function} opciones.alCambiar Callback cuando cambia la vista activa
 * @param {string} [opciones.inicial] Vista con la que abre; por defecto 'dias'
 * @returns {Object} El controlador
 */
function crearEjeVistas(opciones) {
    'use strict';

    var ORDEN = ['dias', 'meses', 'completo'];

    var ICONOS = {
        'dias': 'fa-calendar-day',
        'meses': 'fa-calendar-alt',
        'completo': 'fa-calendar-week'
    };

    var TITULOS = {
        'dias': 'Las columnas diarias del tramo',
        'meses': 'Las columnas mensuales. Cubren sólo los días que quedan fuera '
               + 'del tramo diario, así que su total no es el del horizonte completo',
        'completo': 'Las dos ramas juntas, en orden cronológico'
    };

    var datos = null;
    var activa = opciones.inicial || 'dias';

    /* ================================================================
       API
       ================================================================ */

    var api = {
        /** Carga un payload nuevo del backend y redibuja los botones */
        usar: function(payload) {
            datos = payload;

            // Si la vista activa se quedó sin columnas -por ejemplo Meses
            // cuando el tramo diario se comió todos los meses del horizonte-,
            // se cae a la primera que tenga. Dejarla activa mostraría una tabla
            // sin columnas y parecería que no hay datos.
            if (!api.columnas().length) {
                for (var i = 0; i < ORDEN.length; i++) {
                    if (api.columnasDe(ORDEN[i]).length) {
                        activa = ORDEN[i];
                        break;
                    }
                }
            }

            pintarBotones();
            pintarPeriodo();
        },

        /** @returns {string} La vista activa */
        activa: function() {
            return activa;
        },

        /** @returns {Array} Columnas de la vista activa */
        columnas: function() {
            return api.columnasDe(activa);
        },

        /** @returns {Array} Columnas de una vista puntual */
        columnasDe: function(vista) {
            if (!datos || !datos.vistas || !datos.vistas[vista]) {
                return [];
            }

            return datos.vistas[vista].columnas || [];
        },

        /** @returns {string} Qué período está midiendo la vista activa */
        periodo: function() {
            if (!datos || !datos.vistas || !datos.vistas[activa]) {
                return '';
            }

            return datos.vistas[activa].periodo || '';
        },

        /** @returns {boolean} Si la columna es mensual */
        esMes: function(col) {
            return String(col).indexOf('MES|') === 0;
        },

        /**
         * Rótulo corto de una columna, tomado del eje del payload para que
         * coincida exactamente con lo que calculó el backend.
         *
         * @returns {string} '6/9' o 'Oct-26'
         */
        rotulo: function(col) {
            var clave = String(col).substring(4);

            if (api.esMes(col)) {
                var m = buscar(datos && datos.meses, 'clave', clave);

                return m ? m.label : clave;
            }

            var d = buscar(datos && datos.dias, 'fecha', clave);

            return d ? d.label : clave;
        },

        /** @returns {Object|null} La entrada del eje de esa columna, con sus marcas */
        meta: function(col) {
            var clave = String(col).substring(4);

            return api.esMes(col)
                ? buscar(datos && datos.meses, 'clave', clave)
                : buscar(datos && datos.dias, 'fecha', clave);
        },

        /**
         * Importe de una fila en una columna.
         * Devuelve null y no cero cuando la fila tiene null ahí: es la
         * diferencia entre "esta columna no cubre ningún día futuro" y "el dato
         * es cero".
         */
        valor: function(fila, col) {
            var rama = api.esMes(col) ? 'meses' : 'dias';
            var clave = String(col).substring(4);

            if (!fila || !fila[rama] || fila[rama][clave] === undefined) {
                return 0;
            }

            return fila[rama][clave];
        },

        /**
         * Total que corresponde a la vista activa.
         *
         * Cada vista suma sus propias columnas: la de meses cubre sólo los días
         * de fuera del tramo, así que su total no es el del horizonte.
         */
        total: function(fila) {
            if (!fila) {
                return 0;
            }

            if (activa === 'meses') {
                return Number(fila.total_meses) || 0;
            }

            if (activa === 'completo') {
                return Number(fila.total_horizonte) || 0;
            }

            return Number(fila.total_tramo) || 0;
        },

        /** Cambia de vista por código, como si se hubiera apretado el botón */
        cambiar: cambiar
    };

    /* ================================================================
       BOTONES Y CARTEL DE PERÍODO
       ================================================================ */

    function pintarBotones() {
        var cont = document.getElementById(opciones.botones);

        if (!cont) {
            return;
        }

        var html = '<div class="btn-group btn-group-sm" role="group">';

        ORDEN.forEach(function(v) {
            // Una vista sin columnas se muestra deshabilitada en lugar de
            // esconderse: que el botón desaparezca y reaparezca según el
            // horizonte se lee como un error de la pantalla.
            var vacia = !api.columnasDe(v).length;
            var etiqueta = (datos && datos.vistas && datos.vistas[v])
                ? datos.vistas[v].label
                : v;

            html += '<button type="button" class="btn ev-btn '
                 + (activa === v ? 'btn-primary' : 'btn-outline-secondary') + '" '
                 + 'data-vista="' + v + '"' + (vacia ? ' disabled' : '')
                 + ' title="' + escaparAtributo(vacia
                       ? 'El horizonte configurado no deja columnas para esta vista'
                       : TITULOS[v]) + '">'
                 + '<i class="fas ' + ICONOS[v] + ' me-1"></i>'
                 + escaparAtributo(etiqueta)
                 + '</button>';
        });

        html += '</div>';
        cont.innerHTML = html;

        cont.querySelectorAll('.ev-btn').forEach(function(b) {
            b.addEventListener('click', function() {
                cambiar(b.getAttribute('data-vista'));
            });
        });
    }

    /**
     * El cartel que dice qué período se está midiendo.
     *
     * No es decoración: la vista Meses no cubre el horizonte completo, y sin
     * este cartel su total se lee como el total de todo.
     */
    function pintarPeriodo() {
        if (!opciones.periodo) {
            return;
        }

        var el = document.getElementById(opciones.periodo);

        if (!el) {
            return;
        }

        var cantidad = api.columnas().length;
        var unidad = (activa === 'meses') ? 'meses' : (activa === 'dias' ? 'días' : 'columnas');

        el.innerHTML = '<i class="fas fa-circle-info me-1"></i>Mostrando '
            + '<strong>' + cantidad + ' ' + unidad + '</strong>: '
            + escaparAtributo(api.periodo().charAt(0).toLowerCase() + api.periodo().slice(1))
            + '.';
    }

    function cambiar(nueva) {
        if (nueva === activa || !api.columnasDe(nueva).length) {
            return;
        }

        activa = nueva;

        pintarBotones();
        pintarPeriodo();

        if (typeof opciones.alCambiar === 'function') {
            opciones.alCambiar(activa);
        }
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function buscar(lista, campo, valor) {
        if (!lista) {
            return null;
        }

        for (var i = 0; i < lista.length; i++) {
            if (lista[i][campo] === valor) {
                return lista[i];
            }
        }

        return null;
    }

    function escaparAtributo(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    return api;
}
