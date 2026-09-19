/**
 * Comex — la celda de fecha editable y el buscador, para las dos pestañas.
 *
 * POR QUÉ ESTO NO ESTÁ COPIADO EN LOS DOS ARCHIVOS
 * ------------------------------------------------
 * Proveedores Exterior y Crono Nacionalización son la misma fila mirada desde
 * los dos lados del circuito: mismo contenedor, misma grilla, y desde
 * feature/comex-fecha-maestra la misma escritura —las dos editan una columna de
 * RO_T_IMPORTACIONES_ENCABEZADO y las dos dejan el mismo rastro—.
 *
 * Estaban copiadas, y ya habían divergido: la de pago avisaba cuando el cambio
 * de mes descartaba la cotización y la de nacionalización no tenía ese aviso ni
 * el `guardando` que evita que el blur dispare un segundo guardado. Con el
 * maestro de por medio esa divergencia deja de ser cosmética: son dos gestos
 * distintos para escribir sobre la tabla de otra aplicación.
 *
 * Es el mismo criterio con el que el módulo ya tiene una sola exportarTabla()
 * en vez de las siete copias que tenía. Lo que queda en cada pestaña es lo que
 * de verdad es distinto: qué campo edita, qué columnas dibuja y qué hace
 * después de guardar.
 *
 * TRES MARCAS, TRES COSAS DISTINTAS
 * ---------------------------------
 *   Editada   esta fecha del maestro la puso alguien desde el cashflow. El
 *             tooltip dice quién, cuándo y qué decía antes
 *   Vencida   la fecha ya pasó, así que el importe NO ENTRA en ninguna columna
 *             del eje. No se lo reubica en hoy: ver Class/Comex.php
 *   Sin fecha no hay dónde ubicar el importe. Es otro problema que el vencido
 *             —uno se corrige, el otro se carga— y por eso es otra marca
 *
 * Las tres las decide el backend y viajan en la fila. El front no compara
 * ninguna fecha, por el mismo motivo por el que no le quedó ninguna aritmética
 * de fechas cuando el eje pasó a resolverse en PHP.
 */

(function() {
    'use strict';

    /**
     * De qué campo de la fila sale la fecha efectiva de cada campo editable.
     *
     * Declarado y no derivado del nombre: son dos claves que ya existían en el
     * payload de cada pestaña y arman los dos nombres con reglas distintas
     * (FECHA_PAGO_EFECTIVA, FECHA_NAC_EFECTIVA). Un armado por concatenación
     * andaría de casualidad y se rompería con el tercer campo.
     */
    var EFECTIVA = {
        PAGO: 'FECHA_PAGO_EFECTIVA',
        NAC: 'FECHA_NAC_EFECTIVA'
    };

    /** Cómo se nombra cada fecha en los mensajes */
    var NOMBRE = {
        PAGO: 'fecha estimada de pago',
        NAC: 'fecha de nacionalización'
    };

    /** Escapa un texto para meterlo en un atributo o en el cuerpo de una celda */
    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    /** dd/mm/aaaa, sin pasar por new Date(string) */
    function fecha(valor) {
        if (!valor || valor === '-') {
            return '-';
        }

        var p = String(valor).split('T')[0].split(' ')[0].split('-');

        return (p.length !== 3) ? '-' : (p[2] + '/' + p[1] + '/' + p[0]);
    }

    /**
     * El tooltip del rastro: quién movió esta fecha, cuándo y qué decía antes.
     *
     * SIN LOGIN TODAVÍA, así que el usuario llega null y se dice "desde el
     * cashflow" sin nombre. Decir "editada por null" sería peor que no decir
     * quién; la costura ya está puesta para el día que haya login.
     */
    function tooltipRastro(item) {
        var quien = item.EDIT_USUARIO
            ? escapar(item.EDIT_USUARIO)
            : 'desde el cashflow';

        var cuando = item.EDIT_FECHA ? fecha(item.EDIT_FECHA) : '';

        return '<div class="fecha-tooltip">'
            + '<span class="fecha-tooltip-label">'
            + (item.EDIT_ANTERIOR ? 'Antes decía' : 'Estaba vacía, la cargó')
            + '</span>'
            + '<span class="fecha-tooltip-value">'
            + (item.EDIT_ANTERIOR ? fecha(item.EDIT_ANTERIOR) : quien)
            + '</span>'
            + '<span class="fecha-tooltip-label">'
            + (item.EDIT_ANTERIOR ? ('La movió ' + quien) : 'Cuándo')
            + (cuando ? (' · ' + cuando) : '') + '</span>'
            + '</div>';
    }

    /**
     * El HTML de una celda de fecha editable.
     *
     * @param {Object} item Fila del payload
     * @param {string} campo 'PAGO' o 'NAC'
     * @param {Object} opts { clase, editable, alEditar }
     * @returns {string}
     */
    function celda(item, campo, opts) {
        opts = opts || {};

        var clase = opts.clase || 'fecha-pago';
        var valor = item[EFECTIVA[campo]] || null;
        var editada = !!item.EDITADA;
        var vencida = !!item.VENCIDA;
        var sinFecha = (valor === null || valor === '');

        var clases = ['center', clase + '-cell', 'comex-fecha-cell'];

        if (editada) { clases.push('fecha-editada'); }
        if (vencida) { clases.push('fecha-vencida'); }
        if (sinFecha) { clases.push('fecha-sin'); }
        if (opts.editable) { clases.push('fecha-editable'); }

        /* El title explica la consecuencia y no el estado: lo que hay que
           saber mirando la celda no es "esto venció" sino "por esto tu importe
           no está en ninguna columna". */
        var titulo = vencida
            ? ('La ' + NOMBRE[campo] + ' ya venció, así que este importe no entra en ninguna '
               + 'columna del eje. No se lo reubica en hoy: cargale la fecha nueva y entra.')
            : (sinFecha
                ? ('Sin ' + NOMBRE[campo] + ' no hay dónde ubicar este importe en el tiempo.')
                : '');

        if (opts.editable) {
            titulo += (titulo ? ' ' : '')
                + 'Hacé clic para editarla. Se guarda en el maestro de Comercio Exterior, '
                + 'así que la ve también esa aplicación.';
        }

        var cuerpo = '<div class="' + clase + '-display">'
            + '<span class="fecha-value">' + (sinFecha ? '—' : fecha(valor)) + '</span>'
            + (vencida ? '<span class="badge-fecha-vencida">Vencida</span>' : '')
            + (sinFecha ? '<span class="badge-fecha-sin">Sin fecha</span>' : '')
            + (editada ? '<span class="badge-fecha-editada">Editada</span>' : '')
            + (opts.editable ? '<i class="fas fa-pen ' + clase + '-icon"></i>' : '')
            + '</div>'
            + (editada ? tooltipRastro(item) : '');

        return '<td class="' + clases.join(' ') + '"'
            + ' data-id="' + item.ID + '"'
            + ' data-campo="' + campo + '"'
            + ' data-fecha="' + escapar(sinFecha ? '' : String(valor).split('T')[0]) + '"'
            /* El orden de la columna sale del valor y no del texto: la celda
               lleva badges, y sin esto Js/tabla-orden.js ordenaría por
               "Vencida 03/09/2026". Ver README-cashflow.md. */
            + ' data-orden="' + escapar(sinFecha ? '' : String(valor).split('T')[0]) + '"'
            + (titulo ? (' title="' + escapar(titulo) + '"') : '')
            + (opts.editable ? (' onclick="' + opts.alEditar + '(this)"') : '')
            + '>' + cuerpo + '</td>';
    }

    /**
     * Abre el editor de una celda de fecha y la guarda contra el maestro.
     *
     * MISMA MECÁNICA QUE LA COTIZACIÓN —clic, input, Enter o blur para guardar,
     * Esc para cancelar— porque son las dos cosas editables de estas grillas y
     * aprender dos gestos distintos para lo mismo no tiene ninguna ventaja.
     *
     * EL GUARDADO NO SE DISPARA DOS VECES. Al reemplazar el contenido de la
     * celda, el input pierde el foco y eso dispara el blur: sin la guarda,
     * Enter mandaba dos pedidos. La copia de esta función que vivía en Crono
     * Nacionalización no la tenía.
     *
     * @param {HTMLElement} cell
     * @param {Function} alGuardar Qué hacer después de guardar bien
     */
    function editar(cell, alGuardar) {
        if (cell.querySelector('input')) {
            return;
        }

        var original = cell.innerHTML;

        var input = document.createElement('input');

        input.type = 'date';
        input.className = 'comex-fecha-input';
        input.value = cell.dataset.fecha || '';

        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();

        var guardando = false;

        var guardar = function() {
            if (guardando) {
                return;
            }

            guardando = true;

            var nueva = input.value;

            /* VACÍO NO BORRA, y no es un olvido: estas dos fechas son del
               maestro de Comercio Exterior y las usa también esa aplicación.
               Vaciarlas desde acá sería sacarle un dato a una pantalla que no
               es ésta. El backend lo rechaza igual. */
            if (!nueva || nueva === (cell.dataset.fecha || '')) {
                cell.innerHTML = original;
                return;
            }

            cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('Controller/ComexController.php?action=updateFecha', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_mg: cell.dataset.id,
                    campo: cell.dataset.campo,
                    fecha: nueva
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (!result.success) {
                    alert('No se pudo guardar la fecha: ' + result.message);
                    cell.innerHTML = original;
                    guardando = false;

                    return;
                }

                /* SE AVISA SIEMPRE, y no sólo cuando se descartó la cotización.
                   Mover esta fecha cambia un dato de OTRA aplicación, y eso no
                   se puede deducir mirando la grilla. */
                alert(result.message);

                if (typeof alGuardar === 'function') {
                    alGuardar(result);
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert('Error de conexión al guardar la fecha');
                cell.innerHTML = original;
                guardando = false;
            });
        };

        input.addEventListener('blur', guardar);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                guardar();
            } else if (e.key === 'Escape') {
                guardando = true;   // que el blur que viene no dispare el guardado
                cell.innerHTML = original;
            }
        });
    }

    /* ================================================================
       EL BUSCADOR

       Client-side y sin ir al servidor, igual que el de Echeqs: esconde filas
       con display:none. La tabla ya está entera en el navegador, así que un
       round-trip por cada tecla sería trabajo puro.

       BUSCA SÓLO PROVEEDOR, CONTENEDOR Y ORDEN DE COMPRA. Son los tres campos
       por los que alguien busca un contenedor. Mirar el textContent de la fila
       entera —que es lo que hace Cobranzas May— acá daría falsos positivos
       contra los importes de las columnas del eje: tipear "2026" traería todo,
       y tipear un número de tres cifras, cualquier fila que tenga ese número
       adentro de un importe.

       INSENSIBLE A MAYÚSCULAS, NO A ACENTOS. Es lo que hace Echeqs y todo el
       resto del módulo; agregar el plegado de acentos acá sólo haría que estos
       dos buscadores se comporten distinto de los otros cuatro.
       ================================================================ */

    /** Los tres campos por los que se busca, concatenados */
    function textoBuscable(item) {
        return [item.PROVEEDOR, item.CONTENEDOR, item.ORDEN_COMPRA]
            .map(function(v) { return v === null || v === undefined ? '' : String(v); })
            .join(' ');
    }

    /**
     * Suma una lista de items en la MISMA forma que trae `payload.totales`,
     * para que la fila de totales no tenga que saber de dónde salió el número.
     *
     * Suma clave por clave lo que el backend ya resolvió —las ramas `dias` y
     * `meses` y los tres totales de las tres vistas—: no decide en qué columna
     * cae nada, que es la parte que no se puede duplicar.
     *
     * @param {Array} items
     * @returns {Object} Con la forma de `payload.totales`
     */
    function sumarColumnas(items) {
        var t = {
            dias: {}, meses: {},
            total_tramo: 0, total_meses: 0, total_horizonte: 0
        };

        items.forEach(function(item) {
            ['dias', 'meses'].forEach(function(rama) {
                var mapa = item[rama] || {};

                Object.keys(mapa).forEach(function(clave) {
                    t[rama][clave] = (t[rama][clave] || 0) + (Number(mapa[clave]) || 0);
                });
            });

            t.total_tramo += Number(item.total_tramo) || 0;
            t.total_meses += Number(item.total_meses) || 0;
            t.total_horizonte += Number(item.total_horizonte) || 0;
        });

        return t;
    }

    /* ================================================================
       LAS VENCIDAS NO SE VEN POR DEFECTO

       Una fecha vencida es un dato a corregir, y hasta que alguien la corrija
       ese contenedor no participa del período que la pantalla proyecta: sus
       celdas del eje están vacías. En el trabajo normal —mirar qué se paga de
       acá en adelante— son ruido, y en esta grilla son MUCHAS: al 19/09/2026,
       27 de 76 filas en Proveedores Exterior.

       Pero tienen que poder mirarse, porque son justamente las que hay que
       arreglar. Por eso hay un interruptor y no un filtro fijo, y por eso
       CUÁNTO ESCONDE SE DICE SIEMPRE, prendido o apagado: una tabla que
       esconde filas sin decirlo se lee como que esos contenedores no existen.

       Es el mismo criterio de "Ver excluidos" de Echeqs y de "Ver excluidas"
       de Proveedores Locales, con una diferencia que importa: allá lo
       escondido es plata que ya se decidió que NO entra, así que las tarjetas
       la descuentan. Acá es una preferencia de cómo mirar la tabla, no un
       filtro de datos: las tarjetas siguen midiendo el cronograma completo,
       igual que con el buscador.
       ================================================================ */

    /**
     * Si el interruptor de ver vencidas está prendido.
     *
     * SIN INTERRUPTOR EN LA PANTALLA, SE VEN TODAS. Una pestaña que no declara
     * el control no puede quedar escondiendo filas sin que nada lo diga: es la
     * situación de Crono Nacionalización, que no lo tiene.
     *
     * @param {string} idSwitch
     * @returns {boolean}
     */
    function verVencidas(idSwitch) {
        var chk = idSwitch ? document.getElementById(idSwitch) : null;

        return chk ? !!chk.checked : true;
    }

    /**
     * Esconde las filas de una tabla que el buscador o el interruptor dejan
     * afuera.
     *
     * @param {string} idCampo Id del input de búsqueda
     * @param {string} idCuerpo Id del tbody
     * @param {string} [idSwitch] Id del interruptor de ver vencidas
     * @returns {number} Cuántas filas quedaron visibles
     */
    function filtrar(idCampo, idCuerpo, idSwitch) {
        var campo = document.getElementById(idCampo);
        var term = campo ? campo.value.toLowerCase() : '';
        var conVencidas = verVencidas(idSwitch);
        var filas = document.querySelectorAll('#' + idCuerpo + ' tr');
        var n = 0;

        for (var i = 0; i < filas.length; i++) {
            var texto = (filas[i].getAttribute('data-buscar') || '').toLowerCase();
            var vencida = filas[i].getAttribute('data-vencida') === '1';
            var pasa = (conVencidas || !vencida)
                && (!term || texto.indexOf(term) !== -1);

            filas[i].style.display = pasa ? '' : 'none';

            if (pasa) {
                n++;
            }
        }

        return n;
    }

    /**
     * Los items que el buscador y el interruptor están dejando ver, o null si
     * no hay ningún filtro puesto.
     *
     * NULL Y NO LA LISTA ENTERA: sin filtro mandan los totales del payload, que
     * no se recalculan acá. Recalcularlos sería una tercera copia de la regla
     * de "día O mes", que además se podría desincronizar de las celdas que
     * tiene arriba.
     *
     * @param {string} idCampo
     * @param {Array} filas
     * @param {string} [idSwitch]
     * @returns {Array|null}
     */
    function visibles(idCampo, filas, idSwitch) {
        var campo = document.getElementById(idCampo);
        var term = campo ? campo.value.toLowerCase() : '';
        var conVencidas = verVencidas(idSwitch);

        if (!term && conVencidas) {
            return null;
        }

        return (filas || []).filter(function(item) {
            if (!conVencidas && item.VENCIDA) {
                return false;
            }

            return !term || textoBuscable(item).toLowerCase().indexOf(term) !== -1;
        });
    }

    /**
     * Cuántas filas vencidas hay, para poder decir cuánto esconde el
     * interruptor.
     *
     * @param {Array} filas
     * @returns {number}
     */
    function contarVencidas(filas) {
        return (filas || []).filter(function(item) { return !!item.VENCIDA; }).length;
    }

    window.ComexFechas = {
        celda: celda,
        editar: editar,
        textoBuscable: textoBuscable,
        sumarColumnas: sumarColumnas,
        filtrar: filtrar,
        visibles: visibles,
        verVencidas: verVencidas,
        contarVencidas: contarVencidas,
        formatDate: fecha,
        escapar: escapar
    };

})();
