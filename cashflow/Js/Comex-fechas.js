/**
 * Comex — lo que comparten las dos pestañas: la celda de fecha editable, el
 * tilde de pagado, la celda del dólar aplicado, el importe en pesos y el
 * buscador.
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
 * LA CELDA DEL DÓLAR Y LA DEL IMPORTE EN PESOS TAMBIÉN VIVEN ACÁ, desde que
 * Crono Nacionalización descubrió que sus gastos estaban en dólares y pasó a
 * valuarse igual —feature/comex-nac-usd—. Vivían en Comex-Proveedores_exterior.js
 * y eran la única pestaña que las tenía. Lo compartido es la parte de SÓLO
 * LECTURA; Proveedores Exterior le agrega encima su edición del override, que
 * es lo único que de verdad es suyo. Ver el bloque "CON QUÉ DÓLAR SE VALUÓ LA
 * FILA" más abajo.
 *
 * TRES MARCAS, TRES COSAS DISTINTAS
 * ---------------------------------
 *   Manual /  esta fecha NO la calculó el sistema. En la FECHA DE PAGO dice
 *   Editada   "Manual" y sale del BIT FECHA_PAGO_CONF del maestro, el mismo que
 *             muestra la pantalla de Comercio Exterior: mientras esté en 1, el
 *             recálculo de +5 días no la toca. En la fecha de NACIONALIZACIÓN
 *             sigue diciendo "Editada" y sigue queriendo decir "esto lo movió el
 *             cashflow", porque ahí no hay BIT y es lo único que se puede afirmar
 *   Vencida   la fecha ya pasó, así que el importe NO ENTRA en ninguna columna
 *             del eje. No se lo reubica en hoy: ver Class/Comex.php
 *   Sin fecha no hay dónde ubicar el importe. Es otro problema que el vencido
 *             —uno se corrige, el otro se carga— y por eso es otra marca
 *
 * Las tres las decide el backend y viajan en la fila. El front no compara
 * ninguna fecha, por el mismo motivo por el que no le quedó ninguna aritmética
 * de fechas cuando el eje pasó a resolverse en PHP.
 *
 * POR QUÉ LA MARCA DE LA FECHA DE PAGO CAMBIÓ DE NOMBRE Y DE COLOR
 * ---------------------------------------------------------------
 * Porque cambió lo que afirma. "Editada" decía quién había tocado la fecha;
 * "Manual" dice que está fijada, la haya fijado esta pantalla o la otra. Una
 * fecha fijada desde Comercio Exterior no deja rastro de este lado y antes
 * quedaba sin marcar, indistinguible de una que calculó el sistema.
 *
 * AMARILLO Y NO VERDE, alineado con el badge de la pantalla de Comercio
 * Exterior, donde el par ya existía: verde lo que calcula el sistema, amarillo
 * lo que puso una persona.
 *
 * EL TOOLTIP DEPENDE DE QUIÉN LA MOVIÓ. Si hay un rastro vigente del cashflow
 * —RASTRO_VIGENTE— se muestra el de siempre, con qué decía antes y quién la
 * movió: tooltipRastro() no cambió. Si no lo hay, la fijaron del otro lado y se
 * dice eso, con lo que guardó el maestro. Atribuirle al cashflow una edición
 * que el cashflow no hizo sería el error que este módulo no se permite.
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
     * El tooltip de una fecha de pago que se fijó DESDE COMERCIO EXTERIOR.
     *
     * Existe porque tooltipRastro() dice "la movió el cashflow" y acá no fue el
     * cashflow: no hay rastro de este lado, y el único dato es lo que el maestro
     * guardó al fijarla. Decirlo con el texto del otro tooltip sería atribuirle
     * a esta pantalla una edición que no hizo.
     *
     * NO DICE QUÉ DECÍA ANTES, porque el maestro no lo guarda: eso vive en
     * RO_T_IMPORTACIONES_FECHAS_HIST, que es de la otra aplicación.
     */
    function tooltipFijadaEnComex(item) {
        var quien = item.FECHA_PAGO_CONF_USUARIO
            ? escapar(item.FECHA_PAGO_CONF_USUARIO)
            : 'desde Comercio Exterior';

        var cuando = item.FECHA_PAGO_CONF_FECHA ? fecha(item.FECHA_PAGO_CONF_FECHA) : '';

        return '<div class="fecha-tooltip">'
            + '<span class="fecha-tooltip-label">Fecha fijada a mano</span>'
            + '<span class="fecha-tooltip-value">' + quien + '</span>'
            + '<span class="fecha-tooltip-label">'
            + 'El recálculo automático no la toca'
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
        /* VENCIDA_PENDIENTE y no VENCIDA: una fecha vencida de un pago que YA
           SALIÓ —tildado, o cancelado en Comercio Exterior— no pide que nadie
           la corrija, así que no lleva el badge rojo ni el title de "cargale la
           fecha nueva". Queda la fecha sola, en la fila atenuada de pagada.
           Ver Comex::vencidaPendiente(). */
        var vencida = !!item.VENCIDA_PENDIENTE;
        var sinFecha = (valor === null || valor === '');

        /* La de pago dice "Manual" porque afirma otra cosa: que está fijada, no
           quién la tocó. La de nacionalización sigue diciendo "Editada". Ver el
           encabezado del archivo. */
        var esPago = (campo === 'PAGO');

        var clases = ['center', clase + '-cell', 'comex-fecha-cell'];

        if (editada) { clases.push(esPago ? 'fecha-manual' : 'fecha-editada'); }
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
            + (editada
                ? (esPago
                    ? '<span class="badge-fecha-manual">Manual</span>'
                    : '<span class="badge-fecha-editada">Editada</span>')
                : '')
            + (opts.editable ? '<i class="fas fa-pen ' + clase + '-icon"></i>' : '')
            + '</div>'
            /* El rastro del cashflow solo se muestra si describe lo que se ve.
               Una fecha marcada por el BIT que nadie movió desde acá lleva el
               tooltip del maestro, que es de dónde salió el dato. */
            + (editada
                ? (item.RASTRO_VIGENTE ? tooltipRastro(item) : tooltipFijadaEnComex(item))
                : '');

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

    /* ================================================================
       EL TILDE DE PAGADO

       Dice que ese egreso YA SE HIZO, así que sale de la proyección: la fila
       del tablero deja de contarlo. El importe no se pierde —sale por su
       propia serie— y se destilda desde acá si se marcó por error.

       ES UN TILDE QUE ACTÚA, no que selecciona. A diferencia del de Echeqs
       —donde el check elige filas y un botón confirma el lote con su motivo—
       acá cada clic guarda. La diferencia está en qué se está afirmando: allá
       es una decisión discutible que saca plata del disponible y necesita un
       motivo por escrito; acá es un hecho, "este pago se hizo", y pedir un
       paso de confirmación por cada contenedor convertiría en un trámite lo
       que es tildar una lista.

       Y se deshace con el mismo clic, que es lo que lo hace seguro.
       ================================================================ */

    /**
     * El HTML de la celda del tilde de pagado.
     *
     * @param {Object} item Fila del payload
     * @param {string} concepto 'PAGO' o 'NAC'
     * @param {Object} opts { editable, alMarcar }
     * @returns {string}
     */
    function celdaPagado(item, concepto, opts) {
        opts = opts || {};

        var pagado = !!item.PAGADO;

        /* Quién lo marcó y cuándo, en el title. Sin eso, una marca puesta en
           marzo que nadie recuerda es indistinguible de un dato del sistema. */
        var quien = item.PAGADO_USUARIO ? escapar(item.PAGADO_USUARIO) : 'desde el cashflow';
        var titulo = pagado
            ? ('Marcado como pagado ' + quien
                + (item.PAGADO_FECHA ? (' el ' + fecha(item.PAGADO_FECHA)) : '')
                + (item.PAGADO_OBS ? ('. ' + escapar(item.PAGADO_OBS)) : '')
                + '. No entra en la proyección; destildalo para que vuelva.')
            : (opts.editable
                ? 'Tildá si este pago ya se hizo: sale de la proyección y el tablero deja de '
                    + 'contarlo.'
                : '');

        /* data-orden porque la celda es un control y no un texto: sin esto,
           Js/tabla-orden.js ordenaría esta columna por nada. Ver
           README-cashflow.md. */
        return '<td class="center pagado-cell' + (pagado ? ' pagado-si' : '') + '"'
            + ' data-orden="' + (pagado ? '1' : '0') + '"'
            + (titulo ? (' title="' + escapar(titulo) + '"') : '') + '>'
            + '<input type="checkbox" class="form-check-input pagado-chk"'
            + ' data-id="' + item.ID + '"'
            + ' data-concepto="' + concepto + '"'
            + (pagado ? ' checked' : '')
            + (opts.editable ? '' : ' disabled')
            + (opts.editable && opts.alMarcar
                ? (' onchange="' + opts.alMarcar + '(this)"') : '')
            + '></td>';
    }

    /**
     * Guarda el tilde de pagado de una fila.
     *
     * EL CHECKBOX SE DESHABILITA MIENTRAS GUARDA. Sin eso, dos clics rápidos
     * mandan dos pedidos y el segundo puede llegar antes que el primero, con
     * lo que la fila queda en el estado contrario al que muestra la pantalla.
     *
     * SI FALLA, EL TILDE VUELVE SOLO a donde estaba: dejarlo tildado con el
     * guardado fallido diría que el importe salió del tablero cuando no salió.
     *
     * @param {HTMLElement} chk El checkbox
     * @param {Function} alGuardar Qué hacer después de guardar bien
     */
    function marcarPagado(chk, alGuardar) {
        var queda = chk.checked;

        chk.disabled = true;

        fetch('Controller/ComexController.php?action=marcarPagado', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_mg: chk.dataset.id,
                concepto: chk.dataset.concepto,
                pagado: queda
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (!result.success) {
                /* A Notificacion.error(), que NO se auto-cierra: el mensaje del
                   servidor es lo único que explica por qué el tilde volvió a
                   donde estaba. Ver el encabezado de Js/notificaciones.js. */
                Notificacion.error('No se pudo guardar el tilde de pagado: ' + result.message);
                chk.checked = !queda;
                chk.disabled = false;

                return;
            }

            if (typeof alGuardar === 'function') {
                alGuardar(result);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            Notificacion.error('Error de conexión al guardar el tilde de pagado: no se guardó '
                + 'nada y la casilla vuelve a donde estaba. ' + error.message);
            chk.checked = !queda;
            chk.disabled = false;
        });
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
                    /* A Notificacion.error(), que NO se auto-cierra: el mensaje
                       del servidor es lo único que explica por qué la fecha no
                       quedó guardada, y la celda ya volvió a lo que decía. */
                    Notificacion.error('No se pudo guardar la fecha: ' + result.message);
                    cell.innerHTML = original;
                    guardando = false;

                    return;
                }

                /* SE AVISA SIEMPRE, y no sólo cuando se descartó la cotización.
                   Mover esta fecha cambia un dato de OTRA aplicación, y eso no
                   se puede deducir mirando la grilla.

                   PERO NO SIEMPRE IGUAL, que era el problema de alert(): si el
                   cambio de mes descartó la cotización cargada a mano, cambió
                   ADEMÁS un importe que el usuario no tocó, y eso no es un
                   "listo". Va a advertencia, que dura más y se ve distinto. Con
                   los dos casos saliendo idénticos, el aviso que había que leer
                   se cerraba con el mismo reflejo que el de todos los días —el
                   problema 3 del encabezado de Js/notificaciones.js—. */
                if (result.cotizacion_descartada) {
                    Notificacion.advertencia(result.message,
                        { titulo: 'Se descartó la cotización cargada a mano' });
                } else {
                    Notificacion.exito(result.message);
                }

                if (typeof alGuardar === 'function') {
                    alGuardar(result);
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                Notificacion.error('Error de conexión al guardar la fecha: no se escribió nada '
                    + 'en el maestro de Comercio Exterior y la celda vuelve a lo que decía. '
                    + error.message);
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
       CON QUÉ DÓLAR SE VALUÓ LA FILA

       LAS DOS PESTAÑAS ESTÁN EN DÓLARES, Y LAS DOS LO MUESTRAN IGUAL.
       Proveedores Exterior valúa VALOR_FOB_DOLAR por el mes de la fecha de
       pago; Crono Nacionalización valúa IMPORTE_EST —los gastos de
       nacionalización, que también están en dólares— por el mes de la fecha de
       nacionalización. Son dos columnas con el mismo significado, el mismo
       tooltip y las mismas marcas, así que son el mismo código.

       LA PARTE DE SÓLO LECTURA ES LA COMPARTIDA. Proveedores Exterior le suma
       encima su comportamiento editable —el override por contenedor— pasando
       "editable". Crono Nacionalización no lo pasa y su celda no responde al
       clic: ahí la cotización SIEMPRE sale de la curva, y el porqué está en
       Comex::valuar(). Resumido: el override vive en una tabla con UNA fila por
       contenedor, y las dos pestañas miran ese mismo contenedor en dos fechas
       que caen en meses distintos de la curva.

       DOS MARCAS, DOS COSAS DISTINTAS
         naranja   la cotización la corrigió una persona para este contenedor
         punteado  el mes no está en la curva y se usó el más cercano

       El texto del tooltip lo escribe el backend —DolarFuturo::explicar()— y no
       este archivo: lo usan el tablero y las dos pestañas, y tres textos
       parecidos se desincronizan en la primera corrección.
       ================================================================ */

    /**
     * Una cotización, con DOS decimales y no con los cuatro que guarda la base.
     *
     * Son los que se leen; el valor exacto ya está en el tooltip y en el campo
     * de edición. Cuatro decimales en una columna angosta no se leen y no
     * deciden nada.
     */
    function cotiz(valor) {
        var n = parseFloat(valor) || 0;

        return '$ ' + n.toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * Un importe en pesos.
     *
     * Vive acá porque estas dos celdas se dibujan acá. Cada pestaña conserva su
     * formatCurrency() para las columnas del eje, que las dibuja ella.
     */
    function pesos(valor) {
        var n = parseFloat(valor) || 0;

        return '$ ' + n.toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * La celda del dólar aplicado: qué cotización se usó, de qué mes y por qué.
     *
     * SIN COTIZACIÓN SE DIBUJA UN GUIÓN, no un cero. Es la diferencia entre "no
     * hay dato" y "el dato es cero", y es el criterio de todo el módulo.
     *
     * @param {Object} item Fila del payload
     * @param {Object} [opts] { editable, alEditar }
     * @returns {string} HTML de la celda
     */
    function celdaCotizacion(item, opts) {
        opts = opts || {};

        var editable = !!opts.editable;
        var detalle = item.COTIZ_DETALLE || '';
        var clases = ['center', 'cotiz-cell'];

        if (item.COTIZ_ORIGEN === 'OVERRIDE') {
            clases.push('cotiz-override');
        } else if (item.COTIZ_ORIGEN === 'APROXIMADA') {
            clases.push('cotiz-aproximada');
        }

        if (editable) {
            clases.push('cotiz-editable');
        }

        var cuerpo;

        if (item.COTIZ_USD === null || item.COTIZ_USD === undefined) {
            cuerpo = '<span class="cotiz-sin">—</span>';
        } else {
            cuerpo = '<span class="cotiz-valor">' + cotiz(item.COTIZ_USD) + '</span>'
                + '<span class="cotiz-simbolo">'
                + escapar(item.COTIZ_ORIGEN === 'OVERRIDE'
                    ? 'a mano'
                    : (item.COTIZ_SIMBOLO || item.COTIZ_MES || ''))
                + '</span>';

            if (item.COTIZ_ORIGEN === 'APROXIMADA') {
                cuerpo += '<i class="fas fa-code-branch cotiz-marca" aria-hidden="true"></i>';
            } else if (item.COTIZ_ORIGEN === 'OVERRIDE') {
                cuerpo += '<i class="fas fa-pen cotiz-marca" aria-hidden="true"></i>';
            }
        }

        return '<td class="' + clases.join(' ') + '"'
            + ' data-id="' + item.ID + '"'
            + ' data-cotiz="' + (item.COTIZ_USD_EDIT === null || item.COTIZ_USD_EDIT === undefined
                ? '' : item.COTIZ_USD_EDIT) + '"'
            + ' title="' + escapar(detalle
                + (editable ? ' Hacé clic para corregirla sólo para este contenedor; '
                    + 'dejala vacía para volver a la curva.' : ''))
            + '"' + (editable && opts.alEditar
                ? (' onclick="' + opts.alEditar + '(this)"') : '') + '>'
            + cuerpo + '</td>';
    }

    /**
     * El importe en pesos de la fila: sus dólares por la cotización que le tocó.
     *
     * EN BLANCO CUANDO NO SE PUDO VALUAR, con el motivo en el tooltip. Un cero
     * diría que este contenedor no cuesta nada, que es una afirmación que nadie
     * hizo.
     *
     * @param {Object} item Fila del payload
     * @returns {string} HTML de la celda
     */
    function celdaImporteArs(item) {
        if (item.IMPORTE_ARS === null || item.IMPORTE_ARS === undefined) {
            return '<td class="currency cotiz-sin" title="'
                + escapar(item.COTIZ_DETALLE || '') + '">—</td>';
        }

        return '<td class="currency importe-ars">' + pesos(item.IMPORTE_ARS) + '</td>';
    }

    /**
     * De dónde sale el dólar con el que está valuada la tabla, para el pie.
     *
     * No es decoración: un importe en pesos que no se puede atar a una
     * cotización identificada y fechada no se puede auditar contra nada. Es el
     * mismo criterio con el que Dólares Cuenta Comitente muestra la fecha y la
     * punta de su cotización.
     *
     * LA NOTA DE LA CORRECCIÓN MANUAL SÓLO SALE DONDE ESA CORRECCIÓN EXISTE:
     * 'editable' viaja en el payload de Proveedores Exterior y no en el de
     * Crono Nacionalización. Decir "la corrección manual está apagada: falta el
     * script" en una pestaña donde no hay ninguna corrección que hacer mandaría
     * a correr un script que allá no cambia nada.
     *
     * @param {string} idEl Id del elemento del pie
     * @param {Object} c El bloque 'cotizacion' del payload
     */
    function origenCotizacion(idEl, c) {
        var el = document.getElementById(idEl);

        if (!el) {
            return;
        }

        c = c || {};

        if (!c.disponible) {
            el.innerHTML = '<span class="text-danger">'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + 'Sin curva de dólar futuro: los importes no se pueden expresar en pesos.'
                + '</span>';

            return;
        }

        el.textContent = 'Valuado con dólar futuro ROFEX'
            + (c.ultimo_mes ? ', curva hasta ' + c.ultimo_mes : '')
            /* Sólo el día: 'actualizada' viene como 'Y-m-d H:i:s' y el
               formateador de fechas espera una fecha pelada. */
            + (c.actualizada
                ? ' (actualizada el ' + fecha(String(c.actualizada).substring(0, 10)) + ')'
                : '')
            + (c.editable === false
                ? '. La corrección manual está apagada: falta el script.'
                : '.');
    }

    /* ================================================================
       CUANDO LA PESTAÑA NO PUDO CARGAR

       NO ES LO MISMO QUE UN GUARDADO FALLIDO, y por eso no se resuelve igual.
       Un guardado que falla es un aviso sobre UNA ACCIÓN: el usuario acaba de
       hacer algo, está mirando, y una notificación efímera alcanza. Acá no
       falló una acción: falló la carga entera, y lo que queda en pantalla es
       una tabla VACÍA. Alguien que llega treinta segundos después —o que
       vuelve de otra pestaña— ve un cronograma sin contenedores y no tiene
       dónde enterarse de por qué.

       Por eso el mensaje se pinta DENTRO de `tableWrapper`, que es donde está
       el vacío que hay que explicar, y se queda ahí hasta la próxima carga.
       La notificación se manda igual, como complemento, para que el que SÍ
       estaba mirando se entere en el momento.

       El README del módulo distingue "aviso sobre los datos" —que se pinta y
       no se cierra— de "notificación sobre una acción" —que es efímera—. Esto
       está en el medio y se resuelve con las dos cosas, no eligiendo la
       etiqueta más cómoda.

       NO SE PISA LA TABLA: el panel se inserta ANTES, como primer hijo del
       contenedor. Reemplazar el innerHTML del wrapper se llevaría puesto el
       <table>, y entonces "Actualizar" no tendría dónde dibujar cuando el
       servidor vuelva.

       LO USAN TAMBIÉN LOS CASOS DE "NO VINO NINGUNA FILA", que en estas dos
       pestañas llegan por el mismo camino. Por eso el panel dice QUÉ pasa —la
       tabla está vacía— y deja la causa en el mensaje del backend, en vez de
       afirmar que falló la carga.
       ================================================================ */

    /** La clase del panel, que es también cómo se lo encuentra para sacarlo */
    var CLASE_ERROR = 'comex-error-carga';

    /**
     * Pinta la falla de carga adentro de la tabla vacía, y además notifica.
     *
     * @param {string} mensaje Qué pasó
     * @param {string} [idWrapper] Contenedor de la tabla ('tableWrapper')
     */
    function errorDeCarga(mensaje, idWrapper) {
        var cont = document.getElementById(idWrapper || 'tableWrapper');

        if (cont) {
            var panel = cont.querySelector('.' + CLASE_ERROR);

            if (!panel) {
                panel = document.createElement('div');
                panel.className = CLASE_ERROR + ' alert alert-danger m-3';
                cont.insertBefore(panel, cont.firstChild);
            }

            /* EL ENCABEZADO NO AFIRMA LA CAUSA, sólo el hecho: a esta función
               llegan tanto "se cayó la conexión" como "el servidor no devolvió
               ninguna fila", y decir "no se pudieron cargar los datos" sobre lo
               segundo sería contar otra cosa. La causa la trae el mensaje, que
               es del backend.

               El mensaje puede venir con el trace, con saltos de línea: se
               escapa y se respetan los saltos, en vez de mandarlo como HTML. */
            panel.innerHTML = '<i class="fas fa-circle-exclamation me-2"></i>'
                + '<strong>La tabla quedó vacía.</strong> '
                + '<span style="white-space: pre-wrap;">' + escapar(mensaje) + '</span>'
                + '<div class="small mt-2 text-muted">Probá con Actualizar; si vuelve a pasar, '
                + 'lo de arriba es lo que contestó el servidor.</div>';
        }

        /* Y la notificación, para el que está mirando en este momento. No se
           auto-cierra: es un error. Ella misma lo manda a la consola. */
        if (window.Notificacion) {
            Notificacion.error(mensaje);
        } else {
            console.error(mensaje);
        }
    }

    /**
     * Saca el panel de la falla anterior.
     *
     * Se llama al EMPEZAR una carga: desde ese momento el panel describe algo
     * que ya no se sabe si sigue pasando, y un cartel rojo arriba de una tabla
     * que cargó bien es peor que no haberlo puesto.
     *
     * @param {string} [idWrapper]
     */
    function limpiarErrorDeCarga(idWrapper) {
        var cont = document.getElementById(idWrapper || 'tableWrapper');
        var panel = cont ? cont.querySelector('.' + CLASE_ERROR) : null;

        if (panel) {
            panel.parentNode.removeChild(panel);
        }
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
        return prendido(idSwitch);
    }

    /**
     * Si un interruptor de la pantalla está prendido.
     *
     * SIN INTERRUPTOR SE VE TODO. Una pestaña que no declara el control no
     * puede quedar escondiendo filas sin que nada lo diga.
     *
     * @param {string} idSwitch
     * @returns {boolean}
     */
    function prendido(idSwitch) {
        var chk = idSwitch ? document.getElementById(idSwitch) : null;

        return chk ? !!chk.checked : true;
    }

    /**
     * Si una fila pasa los tres filtros de la pantalla.
     *
     * LOS TRES SE EVALÚAN EN UN SOLO LUGAR, sobre la fila y sobre el item, y
     * por eso `filtrar()` y `visibles()` no pueden quedar diciendo cosas
     * distintas: esconder una fila que el pie sigue sumando es el defecto que
     * esta función existe para hacer imposible.
     *
     * @param {Object} estado { term, conVencidas, conPagados }
     * @param {Object} f { texto, vencida, pagado }
     * @returns {boolean}
     */
    function pasaFiltros(estado, f) {
        if (!estado.conVencidas && f.vencida) {
            return false;
        }

        if (!estado.conPagados && f.pagado) {
            return false;
        }

        return !estado.term || f.texto.indexOf(estado.term) !== -1;
    }

    /** El estado de los tres controles, leído de la pantalla */
    function estadoFiltros(idCampo, idSwitch, idSwitchPagados) {
        var campo = document.getElementById(idCampo);

        return {
            term: campo ? campo.value.toLowerCase() : '',
            conVencidas: prendido(idSwitch),
            conPagados: prendido(idSwitchPagados)
        };
    }

    /**
     * Esconde las filas de una tabla que el buscador o los interruptores dejan
     * afuera.
     *
     * @param {string} idCampo Id del input de búsqueda
     * @param {string} idCuerpo Id del tbody
     * @param {string} [idSwitch] Id del interruptor de ver vencidas
     * @param {string} [idSwitchPagados] Id del interruptor de ver pagados
     * @returns {number} Cuántas filas quedaron visibles
     */
    function filtrar(idCampo, idCuerpo, idSwitch, idSwitchPagados) {
        var estado = estadoFiltros(idCampo, idSwitch, idSwitchPagados);
        var filas = document.querySelectorAll('#' + idCuerpo + ' tr');
        var n = 0;

        for (var i = 0; i < filas.length; i++) {
            var pasa = pasaFiltros(estado, {
                texto: (filas[i].getAttribute('data-buscar') || '').toLowerCase(),
                vencida: filas[i].getAttribute('data-vencida') === '1',
                pagado: filas[i].getAttribute('data-pagado') === '1'
            });

            filas[i].style.display = pasa ? '' : 'none';

            if (pasa) {
                n++;
            }
        }

        return n;
    }

    /**
     * Los items que el buscador y los interruptores están dejando ver, o null
     * si no hay ningún filtro puesto.
     *
     * NULL Y NO LA LISTA ENTERA: sin filtro mandan los totales del payload, que
     * no se recalculan acá. Recalcularlos sería una tercera copia de la regla
     * de "día O mes", que además se podría desincronizar de las celdas que
     * tiene arriba.
     *
     * @param {string} idCampo
     * @param {Array} filas
     * @param {string} [idSwitch]
     * @param {string} [idSwitchPagados]
     * @returns {Array|null}
     */
    function visibles(idCampo, filas, idSwitch, idSwitchPagados) {
        var estado = estadoFiltros(idCampo, idSwitch, idSwitchPagados);

        if (!estado.term && estado.conVencidas && estado.conPagados) {
            return null;
        }

        return (filas || []).filter(function(item) {
            return pasaFiltros(estado, {
                texto: textoBuscable(item).toLowerCase(),
                vencida: !!item.VENCIDA_PENDIENTE,
                pagado: !!item.PAGADO
            });
        });
    }

    /**
     * Cuántas filas vencidas hay, para poder decir cuánto esconde el
     * interruptor.
     *
     * CUENTA LAS VENCIDAS PENDIENTES, no las vencidas a secas. Una vencida
     * que ya se pagó —tildada, o cancelada en Comercio Exterior— no hay que
     * corregirla, y la esconde el interruptor de pagados, no éste. Contarla acá
     * decía "10 vencidas escondidas" de diez filas que estaban todas pagadas.
     * Ver Comex::vencidaPendiente().
     *
     * @param {Array} filas
     * @returns {number}
     */
    function contarVencidas(filas) {
        return (filas || []).filter(function(item) { return !!item.VENCIDA_PENDIENTE; }).length;
    }

    /**
     * Cuántas filas marcadas como pagadas hay.
     *
     * NO SE CUENTAN LAS QUE ADEMÁS ESTÁN VENCIDAS por separado: una fila puede
     * estar en los dos grupos, y sumar los dos contadores daría más filas de
     * las que hay. Cada contador dice cuántas tiene SU condición, que es lo que
     * su interruptor esconde.
     *
     * @param {Array} filas
     * @returns {number}
     */
    function contarPagadas(filas) {
        return (filas || []).filter(function(item) { return !!item.PAGADO; }).length;
    }

    window.ComexFechas = {
        celda: celda,
        editar: editar,
        celdaPagado: celdaPagado,
        marcarPagado: marcarPagado,
        celdaCotizacion: celdaCotizacion,
        celdaImporteArs: celdaImporteArs,
        origenCotizacion: origenCotizacion,
        errorDeCarga: errorDeCarga,
        limpiarErrorDeCarga: limpiarErrorDeCarga,
        textoBuscable: textoBuscable,
        sumarColumnas: sumarColumnas,
        filtrar: filtrar,
        visibles: visibles,
        verVencidas: verVencidas,
        prendido: prendido,
        contarVencidas: contarVencidas,
        contarPagadas: contarPagadas,
        formatDate: fecha,
        escapar: escapar
    };

})();
