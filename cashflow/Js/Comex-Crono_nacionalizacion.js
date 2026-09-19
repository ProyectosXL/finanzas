/**
 * Comex - Crono Nacionalización JavaScript
 * Gestión de cronograma de nacionalización con fechas editables
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js — ver la nota del encabezado de
 * Comex-Proveedores_exterior.js: esta pestaña tenía el mismo criterio propio,
 * con las columnas calculadas en el navegador y un backend que agrupaba por día
 * del mes en curso. Ahora el eje y los importes por columna vienen resueltos de
 * Class/EjeVista.php, sobre horizonte_dias y horizonte_meses.
 *
 * EL LISTADO VA POR FECHA DE NACIONALIZACIÓN
 * ------------------------------------------
 * Que es la que decide cuándo impacta el gasto. Antes el orden salía de un
 * COALESCE de cinco fechas y el corte era por fecha de embarque: la tabla se
 * leía por una fecha y se ordenaba por otra. Ahora las dos cosas son
 * FECHA_DESP_ADU, que viene del maestro de Comercio Exterior.
 *
 * SE VEN LOS VENCIDOS, Y EL CAMBIO ES GRANDE ACÁ. El filtro por embarque
 * escondía justamente los contenedores que tienen los gastos estimados
 * cargados —se cargan cuando el contenedor ya embarcó—, así que la fila del
 * tablero daba CERO y avisaba que ninguno tenía gastos. Verificado contra la
 * base el 19/09/2026: sin el filtro pasa a $ 561.423,77 dentro del horizonte.
 *
 * LA EDICIÓN ESCRIBE SOBRE EL MAESTRO. La celda y el editor viven en
 * Js/Comex-fechas.js, compartidos con Proveedores Exterior: es el mismo gesto
 * sobre la misma tabla, y las dos copias que había ya habían divergido.
 */

(function() {
    'use strict';

    let datosCrono = null;

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    // Inicializar inmediatamente (para pestañas cargadas dinámicamente)
    function inicializar() {
        console.log('Inicializando Comex - Crono Nacionalización');

        // Verificar que los elementos existen antes de agregar listeners
        var btnRefresh = document.getElementById('btnRefresh');
        var busqueda = document.getElementById('busquedaCronoNac');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }

        // El botón de Exportar ya no se engancha acá: lo toma
        // Js/tabla-export.js por su data-exportar, que es como funciona el
        // resto del módulo.

        if (busqueda) {
            busqueda.addEventListener('keyup', filtrarTabla);
        }

        /* El interruptor no recarga del servidor: las filas ya están todas en
           el navegador y esconderlas es una decisión de cómo mirar la tabla.
           Es el mismo camino que el buscador, así que los dos terminan en
           filtrarTabla() y no pueden quedar diciendo cosas distintas. */
        var verVencidas = document.getElementById('verVencidasCronoNac');

        if (verVencidas) {
            verVencidas.addEventListener('change', filtrarTabla);
        }

        var verPagados = document.getElementById('verPagadosCronoNac');

        if (verPagados) {
            verPagados.addEventListener('change', filtrarTabla);
        }

        vistas = crearEjeVistas({
            botones: 'vistasCronoNac',
            periodo: 'periodoCronoNac',
            alCambiar: generarTabla
        });

        // Proveedor y Contenedor, igual que Proveedores Exterior: es la misma
        // fila mirada desde el otro lado del circuito. La primera columna es
        // una fecha, que no identifica nada por sí sola.
        crearColumnasFijas({
            tabla: 'tablaCronoNacionalizacion',
            control: 'colFijasCronoNac',
            clave: 'crono_nacionalizacion',
            porDefecto: [1, 2]
        });

        // Cargar datos automáticamente
        cargarDatos();
    }

    // Ejecutar cuando el DOM esté listo O inmediatamente si ya está listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        // DOM ya está listo, ejecutar inmediatamente
        inicializar();
    }

    /**
     * Carga los datos desde el servidor
     */
    function cargarDatos() {
        mostrarCargando(true);
        
        fetch('Controller/ComexController.php?action=getCronoNacionalizacion')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                console.log('Respuesta del servidor:', text);
                try {
                    var result = JSON.parse(text);
                    if (result.success) {
                        datosCrono = result.data;

                        console.log('Datos cargados:', datosCrono);

                        // El controlador de vistas se entera del eje nuevo antes
                        // de que se dibuje la tabla.
                        vistas.usar(datosCrono);

                        generarTabla();
                        calcularResumenes();
                        pintarAvisos();
                        mostrarCargando(false);
                    } else {
                        console.error('Error al cargar datos:', result);
                        mostrarError('Error al cargar datos: ' + result.message + 
                                    (result.trace ? '\n\n' + result.trace : ''));
                    }
                } catch (e) {
                    console.error('Error al parsear JSON:', e);
                    console.error('Respuesta recibida:', text);
                    mostrarError('Error al procesar respuesta del servidor.\nRevise la consola para más detalles.');
                }
            })
            .catch(error => {
                console.error('Error de conexión:', error);
                mostrarError('Error de conexión: ' + error.message);
            });
    }

    /**
     * Genera la estructura de la tabla
     */
    function generarTabla() {
        console.log('generarTabla() llamada', datosCrono);
        
        if (!datosCrono) {
            console.error('datosCrono es null o undefined');
            mostrarError('No hay datos para mostrar');
            return;
        }
        
        if (!datosCrono.filas) {
            console.error('datosCrono.filas no existe');
            mostrarError('Estructura de datos incorrecta');
            return;
        }

        if (datosCrono.filas.length === 0) {
            console.warn('No hay items para mostrar');
            mostrarError('No hay registros para el período seleccionado');
            return;
        }

        console.log('Generando tabla con', datosCrono.filas.length, 'items');

        generarEncabezados();
        generarFilasDatos();

        // El buscador se reaplica sobre las filas recién dibujadas: cambiar de
        // vista o refrescar no puede hacer reaparecer lo que el usuario filtró,
        // con el campo de búsqueda todavía escrito. filtrarTabla() ya rehace los
        // totales, así que no hace falta llamarlos aparte.
        filtrarTabla();
    }

    /**
     * Los avisos del backend: lo que quedó fuera del horizonte o sin fecha.
     * Antes se descartaba en silencio.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosCronoNac');

        if (!cont) {
            return;
        }

        var avisos = (datosCrono && datosCrono.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.join(' ') + '</small></div>'
            : '';
    }

    /**
     * Genera los encabezados dinámicos de la tabla.
     *
     * Las columnas salen del eje que resolvió el backend: acá no se calcula
     * ninguna fecha, así que el encabezado no puede describir un período
     * distinto del que muestran las celdas.
     */
    function generarEncabezados() {
        const headerRowSub = document.getElementById('headerRowSub');
        const periodoHeader = document.getElementById('periodoHeader');

        if (!headerRowSub || !periodoHeader) {
            console.error('Elementos de encabezado no encontrados');
            return;
        }

        var cols = vistas.columnas();
        var headerHTML = '';

        cols.forEach(function(col) {
            var esMes = vistas.esMes(col);
            var meta = vistas.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            // Un mes recortado tiene que decirlo: un importe más chico son
            // menos días cubiertos, no una caída.
            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            headerHTML += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + titulo + '"' : '') + '>'
                + vistas.rotulo(col) + '</th>';
        });

        // El total suma EXACTAMENTE las columnas de la vista activa.
        headerHTML += '<th class="total-column">Total</th>';

        periodoHeader.textContent = datosCrono.vistas[vistas.activa()].label;
        periodoHeader.setAttribute('colspan', String(cols.length + 1));

        headerRowSub.innerHTML = headerHTML;
    }

    /**
     * Genera las filas de datos
     */
    function generarFilasDatos() {
        var tableBody = document.getElementById('tableBody');

        if (!tableBody) {
            console.error('tableBody no encontrado');
            return;
        }

        var cols = vistas.columnas();
        var html = '';

        datosCrono.filas.forEach(function(item, index) {
            // El texto que mira el buscador viaja en la fila, ya armado. Así
            // el filtro no depende del índice de ninguna columna —mover una
            // columna no lo rompe— y queda escrito en un solo lugar CUÁLES son
            // los tres campos por los que se busca.
            // data-vencida es lo que mira el interruptor "Ver vencidas". Va en
            // la fila y no se deduce de la clase: la clase es presentación y
            // podría cambiar; el atributo es el dato.
            var clases = [];

            if (item.VENCIDA) { clases.push('fila-vencida'); }
            if (item.PAGADO) { clases.push('fila-pagada'); }

            html += '<tr data-buscar="' + escaparAttrCrono(textoBuscable(item)) + '"'
                + (item.VENCIDA ? ' data-vencida="1"' : '')
                + (item.PAGADO ? ' data-pagado="1"' : '')
                + (clases.length ? (' class="' + clases.join(' ') + '"') : '') + '>';

            // Columnas fijas
            html += `<td class="center">${formatDate(item.FECHA_EST_EMB)}</td>`;
            // Recortado con puntos suspensivos (.col-texto); el nombre
            // completo va en el title. Ver Css/main.css.
            html += `<td class="col-texto" title="${escaparAttrCrono(item.PROVEEDOR)}">${escaparAttrCrono(item.PROVEEDOR)}</td>`;
            html += `<td class="center">${item.CONTENEDOR || ''}</td>`;
            html += `<td class="center">${item.ORDEN_COMPRA || ''}</td>`;
            html += `<td>${item.DESPACHANTE || ''}</td>`;
            html += `<td class="currency">${formatCurrency(item.IMPORTE_EST)}</td>`;
            
            // ETD con indicador de confirmación
            var etdConfirm = item.ETD_CONFIRM == 1;
            html += `<td class="center ${etdConfirm ? 'fecha-confirmada' : 'fecha-estimada'}">
                        ${formatDate(item.ETD)}
                        <span class="confirm-indicator ${etdConfirm ? 'confirmed' : 'estimated'}">${etdConfirm ? 'Conf' : 'Est'}</span>
                     </td>`;
            
            // ETA con indicador de confirmación
            var etaConfirm = item.ETA_CONFIRM == 1;
            html += `<td class="center ${etaConfirm ? 'fecha-confirmada' : 'fecha-estimada'}">
                        ${formatDate(item.ETA)}
                        <span class="confirm-indicator ${etaConfirm ? 'confirmed' : 'estimated'}">${etaConfirm ? 'Conf' : 'Est'}</span>
                     </td>`;
            
            // FECHA_NAC — editable, y ahora sobre el maestro de Comex.
            // La celda la arma Js/Comex-fechas.js, compartida con la otra
            // pestaña.
            html += ComexFechas.celda(item, 'NAC', {
                clase: 'fecha-nac',
                editable: !!datosCrono.fechas_editables,
                alEditar: 'editarFechaNac'
            });

            // El tilde de "ya se pagó". Es el otro pago del mismo contenedor:
            // marcar el del proveedor del exterior no dice nada de éste.
            html += ComexFechas.celdaPagado(item, 'NAC', {
                editable: !!datosCrono.pagado_editable,
                alMarcar: 'marcarPagadoCronoNac'
            });


            // Los importes por columna ya vienen resueltos: la regla de "día O
            // mes, nunca las dos" la aplicó el backend, una sola vez.
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(item, col)) || 0;

                html += '<td class="currency ' + (valor > 0 ? 'cell-with-value' : '') + '">'
                    + (valor > 0 ? formatCurrency(valor) : '') + '</td>';
            });

            var total = vistas.total(item);

            html += '<td class="currency total-column">'
                + (total > 0 ? formatCurrency(total) : '') + '</td>';

            html += '</tr>';
        });

        tableBody.innerHTML = html;
    }

    /* EL BUSCADOR vive en Js/Comex-fechas.js desde que Proveedores Exterior
       también lo tiene: es el mismo control sobre la misma grilla, y dos copias
       se desincronizan en la primera corrección. Lo que buscan, y por qué son
       esos tres campos y no el textContent de la fila, está escrito allá. */

    /** Los tres campos por los que se busca, concatenados */
    function textoBuscable(item) {
        return ComexFechas.textoBuscable(item);
    }

    /**
     * Esconde las filas que no coinciden y rehace los totales.
     *
     * Los totales se rehacen porque si no, el pie diría el total de todo
     * arriba de una tabla que muestra tres filas, y nada en la pantalla
     * diría que esos dos números miden cosas distintas.
     *
     * Las tarjetas de arriba NO se tocan, y es deliberado: miden el
     * cronograma completo, que es lo que se quiere saber aunque uno esté
     * mirando un contenedor. Es el mismo reparto que Cobranzas May.
     */
    function filtrarTabla() {
        ComexFechas.filtrar('busquedaCronoNac', 'tableBody',
            'verVencidasCronoNac', 'verPagadosCronoNac');

        generarFilaTotales();
        pintarEstadoVencidas();
        pintarEstadoPagados();
    }

    /** Los items que el buscador y los interruptores dejan ver, o null si no hay filtro */
    function filasVisibles() {
        return ComexFechas.visibles('busquedaCronoNac',
            (datosCrono && datosCrono.filas) || [],
            'verVencidasCronoNac', 'verPagadosCronoNac');
    }

    /**
     * Cuántas filas esconde el interruptor de pagadas.
     *
     * SE DICE SIEMPRE: una nacionalización marcada SALIÓ DE LA PROYECCIÓN, así
     * que si además desapareciera de la pantalla sin decirlo, nada explicaría
     * por qué el tablero cuenta menos.
     */
    function pintarEstadoPagados() {
        var el = document.getElementById('estadoPagadosCronoNac');

        if (!el) {
            return;
        }

        var n = ComexFechas.contarPagadas((datosCrono && datosCrono.filas) || []);

        if (n === 0) {
            el.textContent = 'ninguna marcada';
            el.title = 'Ninguna nacionalización está marcada como pagada: el tablero las '
                + 'proyecta a todas.';

            return;
        }

        var viendo = ComexFechas.prendido('verPagadosCronoNac');

        el.textContent = viendo
            ? (n === 1 ? 'se ve 1 pagada' : ('se ven las ' + n + ' pagadas'))
            : (n + ' pagada' + (n === 1 ? '' : 's') + ' escondida' + (n === 1 ? '' : 's'));

        el.title = 'Son nacionalizaciones marcadas como ya pagadas: salieron de la proyección '
            + 'y la fila del tablero no las cuenta. ' + (viendo
                ? 'Destildá la que se haya marcado por error.'
                : 'Prendé el interruptor para verlas y poder destildarlas.');
    }

    /**
     * Cuántas filas esconde el interruptor, al lado del interruptor.
     *
     * SE DICE SIEMPRE, prendido o apagado. Una tabla que esconde filas sin
     * decirlo se lee como que esos contenedores no existen, y acá son muchos:
     * al 19/09/2026, 24 de 76. Mismo criterio que Proveedores Exterior.
     *
     * ESCONDERLAS NO CAMBIA NINGÚN NÚMERO: una nacionalización vencida no suma
     * en ninguna columna del período, así que el interruptor saca de la vista
     * filas que ya valían cero.
     */
    function pintarEstadoVencidas() {
        var el = document.getElementById('estadoVencidasCronoNac');

        if (!el) {
            return;
        }

        var n = ComexFechas.contarVencidas((datosCrono && datosCrono.filas) || []);

        if (n === 0) {
            el.textContent = 'no hay vencidas';
            el.title = 'Ningún contenedor tiene la fecha de nacionalización vencida.';

            return;
        }

        var viendo = ComexFechas.verVencidas('verVencidasCronoNac');

        el.textContent = viendo
            ? (n === 1 ? 'se ve 1 vencida' : ('se ven las ' + n + ' vencidas'))
            : (n + ' vencida' + (n === 1 ? '' : 's') + ' escondida' + (n === 1 ? '' : 's'));

        el.title = viendo
            ? 'Están marcadas en rojo. No suman en ninguna columna del período: apagá el '
                + 'interruptor para sacarlas de la tabla.'
            : 'Son contenedores con la fecha de nacionalización ya vencida, que no suman en '
                + 'ninguna columna del período. Esconderlos no cambia ningún total. Prendé el '
                + 'interruptor para verlos y cargarles una fecha nueva.';
    }

    /**
     * Genera la fila de totales.
     *
     * SIN FILTRO los totales salen del payload y no se recalculan acá
     * recorriendo los items: recalcularlos era una tercera copia de la regla de
     * "día O mes", que además se podía desincronizar de las celdas que tiene
     * arriba.
     *
     * CON EL BUSCADOR ACTIVO hay que sumar los items visibles, porque el total
     * de todo arriba de una tabla filtrada es un número que no corresponde a
     * nada de lo que se está viendo. Sumar los valores POR COLUMNA que el
     * payload ya trae resueltos no es volver a implementar la regla de "día O
     * mes": es sumar exactamente las celdas que están dibujadas.
     */
    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRow');

        if (!totalsRow) {
            return;
        }

        var visibles = filasVisibles();
        var totales = (visibles === null)
            ? (datosCrono.totales || {})
            : ComexFechas.sumarColumnas(visibles);

        /* Nueve descriptivas más la del tilde, que no totaliza nada: contar
           cuántas están tildadas en el pie de una tabla de importes no
           significaría nada, y el conteo ya está al lado del interruptor. */
        var html = '<td colspan="9" class="total-label">TOTALES</td><td></td>';

        vistas.columnas().forEach(function(col) {
            var valor = Number(vistas.valor(totales, col)) || 0;

            html += '<td class="currency ' + (valor > 0 ? 'cell-with-value' : '') + '">'
                + (valor > 0 ? formatCurrency(valor) : '') + '</td>';
        });

        var total = vistas.total(totales);

        html += '<td class="currency total-column">'
            + (total > 0 ? formatCurrency(total) : '') + '</td>';

        totalsRow.innerHTML = html;
    }

    /**
     * Indicadores de cabecera.
     *
     * Los tres miden exactamente los tres períodos de las vistas, así que cada
     * tarjeta se corresponde con un botón. Antes se recalculaban acá con su
     * propia ventana de 28 días y 11 meses, que no era la del encabezado ni la
     * del backend.
     */
    function calcularResumenes() {
        var totales = (datosCrono && datosCrono.totales) || {};

        document.getElementById('total4semanas').textContent =
            formatCurrency(totales.total_tramo || 0);
        document.getElementById('total11meses').textContent =
            formatCurrency(totales.total_meses || 0);
        document.getElementById('totalGeneral').textContent =
            formatCurrency(totales.total_horizonte || 0);

        var vs = (datosCrono && datosCrono.vistas) || {};

        texto('rotulo4semanas', vs.dias ? vs.dias.periodo : '');
        texto('rotulo11meses', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneral', vs.completo ? vs.completo.periodo : '');

        document.getElementById('summarySection').style.display = 'flex';
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
    }

    /**
     * Permite editar la fecha de nacionalización.
     *
     * El editor entero vive en Js/Comex-fechas.js, compartido con Proveedores
     * Exterior: es el mismo gesto sobre el mismo maestro. Acá queda lo único
     * propio, que es qué hacer después de guardar.
     *
     * SE RECARGA TODO: la fecha nueva cambia la columna del eje en la que cae
     * el importe, el total, el orden del listado —que ahora es por esta misma
     * fecha— y los avisos de vencidos.
     *
     * @param {HTMLElement} cell Celda donde se hizo click
     */
    window.editarFechaNac = function(cell) {
        ComexFechas.editar(cell, cargarDatos);
    };

    /**
     * Marca o desmarca la nacionalización de un contenedor como pagada.
     *
     * SE RECARGA TODO: el tilde saca la fila de la proyección, así que cambian
     * su columna del eje, el total, el contador del interruptor y los avisos.
     *
     * @param {HTMLElement} chk
     */
    window.marcarPagadoCronoNac = function(chk) {
        ComexFechas.marcarPagado(chk, cargarDatos);
    };

    /** Escapa un texto para meterlo en un atributo o en el cuerpo de una celda */
    function escaparAttrCrono(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Formatea un valor como moneda ARS
     */
    function formatCurrency(value) {
        var num = parseFloat(value) || 0;
        return '$ ' + num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Formatea una fecha
     */
    function formatDate(dateString) {
        if (!dateString || dateString === '-') return '-';
        
        // Evitar problemas de timezone usando la fecha directamente
        var parts = dateString.split('T')[0].split('-');
        if (parts.length !== 3) return '-';
        
        var year = parts[0];
        var month = parts[1];
        var day = parts[2];
        
        return day + '/' + month + '/' + year;
    }

    /**
     * Muestra u oculta el spinner de carga
     */
    function mostrarCargando(mostrar) {
        document.getElementById('loadingSpinner').style.display = mostrar ? 'flex' : 'none';
        document.getElementById('tableWrapper').style.display = mostrar ? 'none' : 'block';
    }

    /**
     * Muestra un mensaje de error
     */
    function mostrarError(mensaje) {
        mostrarCargando(false);
        alert(mensaje);
    }

    /* NO HAY exportarExcel(). Era una función de una línea que llamaba a
       exportarTabla(), más su listener sobre #btnExport: el botón ahora declara
       data-exportar en el HTML y lo engancha Js/tabla-export.js solo, que es
       como funciona el resto del módulo. El export sigue bajando lo que se ve,
       ahora también respetando el buscador: TablaExport saca del clon las filas
       con display:none. */

})(); // Fin del IIFE
