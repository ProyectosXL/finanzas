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
 *
 * LOS GASTOS DE NACIONALIZACIÓN ESTÁN EN DÓLARES, Y LA GRILLA ESTÁ EN PESOS
 * -------------------------------------------------------------------------
 * Esta pestaña mostraba IMPORTE_EST con el signo de pesos adelante y el tablero
 * ubicaba ese número en columnas de pesos. Era un error de moneda: IMPORTE_EST
 * suma los conceptos 3 a 10 de la estimación, que Comercio Exterior calcula
 * como porcentajes del CIF, y el CIF arranca en VALOR_FOB_DOLAR. El porqué
 * completo, y cómo se verificó contra la base, están en el encabezado de
 * Class/Providers/ComexProvider.php.
 *
 * Ahora la fila trae tres columnas —el gasto en dólares, con qué dólar se valuó
 * y el importe en pesos— y lo que se ubica en el eje es el importe en PESOS.
 * La valuación no se hace acá: viene resuelta del backend, fila por fila, con
 * la curva de dólar futuro ROFEX del mes de la fecha de NACIONALIZACIÓN. En el
 * navegador no queda ninguna multiplicación, por el mismo motivo por el que no
 * quedó ninguna aritmética de fechas.
 *
 * LA COTIZACIÓN ACÁ NO SE EDITA. En Proveedores Exterior se puede corregir por
 * contenedor; acá no, y es una decisión: ese override vive en una tabla con UNA
 * fila por contenedor, y las dos pestañas valúan el mismo contenedor en dos
 * fechas que caen en meses distintos de la curva. Ver Comex::valuar().
 *
 * EL PAGO PARCIAL (feature/cashflow-avisos-kpis-comex-nac-parcial)
 * ----------------------------------------------------------------
 * Una parte de la nacionalización se puede pagar y dejar un saldo, que se
 * sigue proyectando en su fecha. El tilde sigue siendo el pago TOTAL. La base,
 * el saldo y lo que suma cada fila llegan resueltos del servidor
 * (Comex::repartoNacionalizacion()); acá se dibujan las cuatro columnas, el
 * diálogo y el historial. Ver README-comex.md, sección 13.
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

        var verCostos = document.getElementById('verCostosCronoNac');

        if (verCostos) {
            verCostos.addEventListener('change', filtrarTabla);
        }

        conectarDialogoPago();

        vistas = crearEjeVistas({
            botones: 'vistasCronoNac',
            periodo: 'periodoCronoNac',
            alCambiar: generarTabla
        });

        /* Proveedor y Contenedor, igual que Proveedores Exterior: es la misma
           fila mirada desde el otro lado del circuito. La primera columna es
           una fecha, que no identifica nada por sí sola.

           LA CLAVE CAMBIÓ CON LAS DOS COLUMNAS NUEVAS. columnas-fijas.js guarda
           la elección en localStorage COMO ÍNDICES, y sólo valida que el índice
           siga existiendo: no tiene forma de saber que la columna 6 pasó de ser
           ETD a ser el dólar aplicado. Quien tuviera fijada una columna a la
           derecha del importe la habría visto correrse dos lugares, sin nada
           que lo explicara. Con la clave nueva esa preferencia se descarta una
           vez y se vuelve al default, que es lo único que no puede mentir. */
        crearColumnasFijas({
            tabla: 'tablaCronoNacionalizacion',
            control: 'colFijasCronoNac',
            clave: 'crono_nacionalizacion_v2',
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

        // Lo que el tablero dice de esta pestaña, en paralelo con los datos:
        // la grilla no lo espera. Ver Js/avisos-panel.js.
        AvisosPanel.pedirTablero('crono_nacionalizacion');

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
                        pintarOrigenCotizacion();
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
     * Los avisos de la pestaña -con nivel, los mismos que emite el tablero más
     * los que apagan una edición- junto con los que el tablero dice de ella,
     * que llegan después. Sin repetidos: ver AvisosPanel.combinar().
     */
    function pintarAvisos() {
        AvisosPanel.pintarPestana('avisosCronoNac',
            (datosCrono && datosCrono.warnings) || [], { tab: 'crono_nacionalizacion' });
    }

    /**
     * De dónde sale el dólar con el que está valuada la tabla.
     *
     * El texto lo escribe Js/Comex-fechas.js, igual que en Proveedores
     * Exterior: es la misma frase sobre el mismo origen. Acá no dice nada de
     * corregir la cotización a mano, porque en esta pestaña no se corrige.
     */
    function pintarOrigenCotizacion() {
        ComexFechas.origenCotizacion('cotizCronoNac',
            (datosCrono && datosCrono.cotizacion) || {});
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

        if (!headerRowSub) {
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

        // La fila de arriba ya no lleva la leyenda de la vista -la dicen los
        // botones- sino los totales, y esos los pinta generarFilaTotales().
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

            // VENCIDA_PENDIENTE: una vencida ya pagada no es una fecha a
            // corregir. Ver Comex::vencidaPendiente().
            /* PAGADA ES EL TILDE O LOS PAGOS PARCIALES QUE CUBREN LA BASE: las
               dos salieron de la proyección y las esconde el mismo interruptor.
               data-costos es lo que mira "Ver con costos reales", y lo decide el
               servidor: una fila con costos reales que suma no lo trae. */
            var pagada = ComexFechas.esPagada(item);

            if (item.VENCIDA_PENDIENTE) { clases.push('fila-vencida'); }
            if (pagada) { clases.push('fila-pagada'); }
            if (item.OCULTA_POR_COSTOS) { clases.push('fila-costos-reales'); }

            html += '<tr data-buscar="' + escaparAttrCrono(textoBuscable(item)) + '"'
                + ' data-indice="' + index + '"'
                + (item.VENCIDA_PENDIENTE ? ' data-vencida="1"' : '')
                + (pagada ? ' data-pagado="1"' : '')
                + (item.OCULTA_POR_COSTOS ? ' data-costos="1"' : '')
                + (clases.length ? (' class="' + clases.join(' ') + '"') : '') + '>';

            // Columnas fijas
            html += `<td class="center">${formatDate(item.FECHA_EST_EMB)}</td>`;
            // Recortado con puntos suspensivos (.col-texto); el nombre
            // completo va en el title. Ver Css/main.css.
            html += `<td class="col-texto" title="${escaparAttrCrono(item.PROVEEDOR)}">${escaparAttrCrono(item.PROVEEDOR)}</td>`;
            html += `<td class="center">${item.CONTENEDOR || ''}</td>`;
            html += `<td class="center">${item.ORDEN_COMPRA || ''}</td>`;
            html += `<td>${item.DESPACHANTE || ''}</td>`;

            /* TRES COLUMNAS Y NO UNA, en el mismo orden que Proveedores
               Exterior: el gasto en DÓLARES —que es como está cargado—, con qué
               dólar se valuó, y el importe en pesos que sale de eso. Hasta
               feature/comex-nac-usd era una sola celda que mostraba
               IMPORTE_EST con el signo de pesos adelante.

               Las dos últimas las arma Js/Comex-fechas.js. Acá la cotización es
               de SÓLO LECTURA: no se pasa `editable`, porque el override por
               contenedor es de la otra pestaña. Ver Comex::valuar(). */
            html += `<td class="currency">${formatUSD(item.IMPORTE_EST)}</td>`;
            html += ComexFechas.celdaCotizacion(item);
            html += ComexFechas.celdaImporteArs(item);
            
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
            /* EL TILDE ES LA ÚNICA ENTRADA PARA REGISTRAR UN PAGO: al tocarlo
               pregunta si se pagó completa o una parte (elegirPagoCronoNac()).
               Se puede tocar si hay alguna de las dos cosas para ofrecer -el
               tilde de pagado o el pago parcial-; la pregunta dice cuál falta.
               Sólo acá: en Proveedores Exterior el tilde queda como está. */
            html += ComexFechas.celdaPagado(item, 'NAC', {
                editable: !!datosCrono.pagado_editable || !!datosCrono.pago_parcial_editable,
                alMarcar: 'elegirPagoCronoNac',
                tooltip: 'Tocá para registrar el pago: te pregunta si se pagó completa o una parte.'
            });

            html += celdasPagoParcial(item, index);


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
            'verVencidasCronoNac', 'verPagadosCronoNac', idSwitchCostos());

        generarFilaTotales();
        pintarEstadoVencidas();
        pintarEstadoPagados();
        pintarEstadoCostos();
    }

    /**
     * El interruptor de costos reales, sólo si la pantalla lo muestra: sin
     * sql/cashflow_comex_nac_pago.sql esos contenedores no vienen, el
     * interruptor no se dibuja y no tiene que esconder nada.
     */
    function idSwitchCostos() {
        return (datosCrono && datosCrono.pago_parcial_editable) ? 'verCostosCronoNac' : null;
    }

    /** Los items que el buscador y los interruptores dejan ver, o null si no hay filtro */
    function filasVisibles() {
        return ComexFechas.visibles('busquedaCronoNac',
            (datosCrono && datosCrono.filas) || [],
            'verVencidasCronoNac', 'verPagadosCronoNac', idSwitchCostos());
    }

    /**
     * Cuántas filas esconde "Ver con costos reales", al lado del interruptor.
     *
     * SE DICE SIEMPRE, como en los otros dos. Esconderlas no cambia ningún
     * número: son contenedores con costos reales que no suman. Los que sí
     * suman -con un pago parcial y saldo- no se esconden y no se cuentan.
     */
    function pintarEstadoCostos() {
        var caja = document.getElementById('switchCostosCronoNac');
        var el = document.getElementById('estadoCostosCronoNac');

        if (caja) {
            caja.style.display = (datosCrono && datosCrono.pago_parcial_editable) ? '' : 'none';
        }

        if (!el) {
            return;
        }

        var n = ComexFechas.contarCostos((datosCrono && datosCrono.filas) || []);
        var viendo = ComexFechas.prendido('verCostosCronoNac');

        el.textContent = (n === 0)
            ? 'ninguno'
            : (viendo
                ? (n === 1 ? 'se ve 1' : ('se ven los ' + n))
                : (n + ' escondido' + (n === 1 ? '' : 's')));

        el.title = 'Contenedores a los que Comercio Exterior ya les cargó los costos reales de '
            + 'nacionalización: se toman como pagados y no suman. Prendé el interruptor para '
            + 'registrarles un pago parcial; el que tenga saldo se ve siempre, porque suma.';
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

        /* Siete descriptivas —hasta la cotización— y después la columna en
           PESOS, que sí se suma. El pie NO totaliza la columna en dólares ni la
           cotización: promediar cotizaciones de meses distintos no sería el
           tipo de cambio de nada, y un total en dólares al lado de uno en pesos
           invita a sumarlos. Lo que entra al cashflow es la columna en pesos.

           La del tilde tampoco totaliza: contar cuántas están tildadas en el
           pie de una tabla de importes no significaría nada, y el conteo ya
           está al lado del interruptor. */
        var filasPie = (visibles === null) ? ((datosCrono && datosCrono.filas) || []) : visibles;
        var sumaCampo = function(campo) {
            return filasPie.reduce(function(a, f) {
                return a + (f.DUPLICA_GRUPO ? 0 : (Number(f[campo]) || 0));
            }, 0);
        };

        var html = '<td colspan="7" class="total-label">TOTALES</td>'
            + '<td class="currency" title="' + escaparAttrCrono('Suma el importe en pesos de las '
                + 'filas que se están viendo, incluidas las que caen fuera del horizonte —las '
                + 'vencidas y las posteriores al último mes—. Por eso puede no coincidir con el '
                + 'total de las columnas, que sólo cubre el período. La diferencia está en los '
                + 'avisos de arriba.')
            + '">'
            + formatCurrency(sumaImporteArs(visibles)) + '</td>'
            /* ETD, ETA y la fecha de nacionalización, que no suman nada. */
            + '<td></td><td></td><td></td>'
            + '<td></td>'
            /* Costo, pagado y saldo de los contenedores que se ven -las hijas
               no, que repetirían el de su contenedor-; la fecha del saldo, no. */
            + '<td class="currency">' + formatCurrency(sumaCampo('BASE_ARS')) + '</td>'
            + '<td class="currency">' + formatCurrency(sumaCampo('NAC_PAGADO_ARS')) + '</td>'
            + '<td class="currency">' + formatCurrency(sumaCampo('SALDO_ARS')) + '</td>'
            + '<td></td>';

        /* Los totales del eje se leen UNA VEZ de la fila de totales y van al
           pie y arriba de cada fecha (Js/eje-totales.js), con el mismo
           formato. */
        var cols = vistas.columnas();
        var tot = totalesEje(totales, cols);

        tot.columnas.forEach(function(valor) {
            html += '<td class="currency ' + (valor > 0 ? 'cell-with-value' : '') + '">'
                + celdaEje(valor) + '</td>';
        });

        html += '<td class="currency total-column">' + celdaEje(tot.total) + '</td>';

        totalsRow.innerHTML = html;

        pintarEjeTotales('tablaCronoNacionalizacion', {
            columnas: cols,
            valores: tot.columnas,
            total: tot.total,
            formato: celdaEje
        });
    }

    /**
     * Los totales del eje: uno por columna y el general de la vista activa, de
     * la fila de totales que ya armó el pie -la del payload o la de las filas
     * visibles-. Es la cuenta del pie Y de la fila de arriba.
     *
     * @param {Object} totales Fila de totales, con sus `dias` y `meses`
     * @param {Array} cols vistas.columnas()
     * @returns {{columnas: Array, total: number}}
     */
    function totalesEje(totales, cols) {
        return {
            columnas: cols.map(function(col) {
                return Number(vistas.valor(totales, col)) || 0;
            }),
            total: vistas.total(totales)
        };
    }

    /**
     * El contenido de una celda de totales del eje. Sólo lo positivo: es lo
     * que el pie mostró siempre, y arriba se tiene que leer igual.
     */
    function celdaEje(valor) {
        return valor > 0 ? formatCurrency(valor) : '';
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
     * El tilde de Pagada: DESTILDAR vuelve a proyectar, como siempre; TILDAR
     * pregunta qué se pagó.
     *
     *   - Pagada completa: el tilde de siempre (ComexFechas.marcarPagado()),
     *     que saca la nacionalización entera de la proyección.
     *   - Fue un pago parcial: abre el diálogo del pago parcial, y el tilde
     *     queda sin marcar -lo que falta se sigue proyectando-.
     *   - Cancelar, la cruz, Escape o clickear afuera: nada, el tilde vuelve a
     *     como estaba.
     *
     * POR QUÉ UNA PREGUNTA Y NO DOS CONTROLES. Hasta acá había un tilde para el
     * pago total y un botón aparte para el parcial, y elegir el correcto pedía
     * leer los tooltips. Es el mismo hecho -"pagué la nacionalización"- y va
     * por la misma puerta.
     *
     * SE RECARGA TODO después de guardar: cambian la columna del eje, el total,
     * los contadores y los avisos.
     *
     * @param {HTMLElement} chk
     */
    window.elegirPagoCronoNac = function(chk) {
        if (!chk.checked) {
            if (datosCrono && datosCrono.pagado_editable) {
                ComexFechas.marcarPagado(chk, cargarDatos);
            } else {
                chk.checked = true;
            }

            return;
        }

        // El tilde no queda marcado hasta que se elija "Pagada completa".
        chk.checked = false;

        var item = ((datosCrono && datosCrono.filas) || []).filter(function(f) {
            return String(f.ID) === String(chk.dataset.id);
        })[0];

        if (!item) {
            return;
        }

        tildePendiente = { chk: chk, item: item };

        var total = !!datosCrono.pagado_editable;
        var parcial = puedePagoParcial() && item.BASE_ARS !== null && !item.DUPLICA_GRUPO;

        poner('tildeNacContenedor', (item.CONTENEDOR || '') + ' · OC ' + (item.ORDEN_COMPRA || '')
            + ' · ' + (item.PROVEEDOR || ''));
        document.getElementById('tildeNacTotal').disabled = !total;
        document.getElementById('tildeNacParcial').disabled = !parcial;
        poner('tildeNacTotalNo', total ? ''
            : 'No disponible: falta sql/cashflow_comex_pagado.sql (ver el aviso de arriba).');
        poner('tildeNacParcialNo', parcial ? '' : (item.DUPLICA_GRUPO
            ? 'No disponible en esta fila: el pago parcial se registra en la fila de la OC principal del contenedor.'
            : (item.BASE_ARS === null && datosCrono.pago_parcial_editable
                ? 'No disponible: el contenedor no tiene costo estimado ni real, así que no hay contra qué calcular el saldo.'
                : 'No disponible: falta sql/cashflow_comex_nac_pago.sql (ver el aviso de arriba).')));

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTildeNac')).show();
    };

    /** La fila cuyo tilde abrió la pregunta, mientras está abierta */
    var tildePendiente = null;

    /**
     * Suma el importe en pesos de las filas que se están viendo.
     *
     * RESPETA EL BUSCADOR Y LOS INTERRUPTORES, igual que las columnas del eje:
     * si el pie sumara todo mientras la tabla muestra tres filas, esta celda y
     * la del eje que tiene al lado dirían números de dos universos distintos sin
     * que nada lo indique. Es la misma función que Proveedores Exterior.
     *
     * Las que no se pudieron valuar suman cero acá y se informan aparte, en
     * dólares: es la única moneda en la que existen, y meterlas en este total
     * las haría desaparecer. Ver Comex::avisosValuacion().
     *
     * @param {Array|null} visibles Las filas filtradas, o null si no hay filtro
     * @returns {number}
     */
    function sumaImporteArs(visibles) {
        var filas = (visibles === null || visibles === undefined)
            ? ((datosCrono && datosCrono.filas) || [])
            : visibles;

        return filas.reduce(function(a, f) {
            return a + (Number(f.IMPORTE_ARS) || 0);
        }, 0);
    }

    /** Escapa un texto para meterlo en un atributo o en el cuerpo de una celda */
    function escaparAttrCrono(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Formatea un valor como moneda ARS.
     *
     * Es la moneda del eje, del pie y de las tarjetas. Los gastos en dólares
     * son el dato de origen y tienen su propia función, que lo dice en el
     * nombre: mismo reparto que Proveedores Exterior.
     */
    function formatCurrency(value) {
        var num = parseFloat(value) || 0;
        return '$ ' + num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** Formatea un valor en dólares: el gasto estimado, como está cargado */
    function formatUSD(value) {
        var num = parseFloat(value) || 0;
        return 'U$S ' + num.toLocaleString('es-AR', {
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
     * Muestra u oculta el spinner de carga.
     *
     * Al EMPEZAR una carga se saca el panel de la falla anterior: desde ese
     * momento describe algo que ya no se sabe si sigue pasando, y un cartel
     * rojo arriba de una tabla que cargó bien es peor que no haberlo puesto.
     */
    function mostrarCargando(mostrar) {
        if (mostrar) { Cargando.mostrar('loadingSpinner'); } else { Cargando.ocultar('loadingSpinner'); }
        document.getElementById('tableWrapper').style.display = mostrar ? 'none' : 'block';

        if (mostrar) {
            ComexFechas.limpiarErrorDeCarga();
        }
    }

    /**
     * La pestaña no pudo cargar.
     *
     * NO ALCANZA CON NOTIFICAR. Lo que queda en pantalla es una tabla vacía, y
     * un mensaje efímero no la explica para el que llega treinta segundos
     * después. El panel se pinta adentro de la tabla y la notificación va
     * igual, como complemento. Las dos cosas las hace Js/Comex-fechas.js,
     * compartido con la otra pestaña: es la misma falla con la misma
     * consecuencia.
     *
     * Acá además lo llaman los tres casos de "no hay nada para mostrar" de
     * generarTabla(), que terminan igual: una tabla vacía que alguien tiene que
     * poder interpretar.
     */
    function mostrarError(mensaje) {
        mostrarCargando(false);
        ComexFechas.errorDeCarga(mensaje);
    }

    /* ================================================================
       EL PAGO PARCIAL

       Las reglas -la base, el saldo, en qué fecha cae y qué suma- las decide
       el servidor (Comex::repartoNacionalizacion()) y llegan resueltas en la
       fila. Acá se dibujan, y el diálogo repite la cuenta del saldo SÓLO para
       mostrar el antes y el después mientras se escribe: lo que se guarda lo
       valida y lo calcula Comex::validarPagoNac().
       ================================================================ */

    /** Si el usuario puede registrar pagos parciales (tablas + permiso) */
    function puedePagoParcial() {
        return !!(datosCrono && datosCrono.pago_parcial_editable)
            && Permisos.puedeEditar(document.querySelector('.tab-crono_nacionalizacion'));
    }

    /**
     * Las cuatro celdas del pago parcial: costo, pagado, saldo y fecha del
     * saldo. Una OC hija del contenedor muestra guiones: todo lo del
     * contenedor está en la fila titular, y repetirlo acá lo haría sumar dos
     * veces a la vista.
     */
    function celdasPagoParcial(item, index) {
        if (item.DUPLICA_GRUPO) {
            var t = escaparAttrCrono('OC del mismo contenedor que otra fila: el costo, los pagos '
                + 'y el saldo van en la fila de la OC principal.');

            return '<td class="center text-muted" title="' + t + '">—</td>'
                + '<td class="center text-muted" title="' + t + '">—</td>'
                + '<td class="center text-muted" title="' + t + '">—</td>'
                + '<td class="center text-muted" title="' + t + '">—</td>';
        }

        var real = item.BASE_ORIGEN === 'REAL';
        var tipCosto = real
            ? 'Costo real cargado en Comercio Exterior. Incluye el flete internacional y no los '
                + 'impuestos que suma la estimación: al pasar de estimado a real el saldo puede '
                + 'cambiar por eso, no sólo por precisión.'
            : 'Estimación de Comercio Exterior valuada con la curva del mes de nacionalización.';

        var costo = '<td class="currency" data-orden="' + (Number(item.BASE_ARS) || 0) + '"'
            + ' title="' + escaparAttrCrono(tipCosto) + '">'
            + (item.BASE_ARS === null ? '<span class="text-muted">sin costo</span>'
                : formatCurrency(item.BASE_ARS))
            + ' <span class="badge ' + (real ? 'bg-dark' : 'bg-light text-dark border') + ' costo-origen">'
            + (real ? 'real' : 'estimado') + '</span></td>';

        var botones = '';

        if (item.NAC_CON_PARCIALES) {
            botones += ' <i class="fas fa-list-ul text-primary ms-1 pago-nac-historial" role="button"'
                + ' data-indice="' + index + '" title="Ver los pagos de este contenedor"></i>';
        }


        var pagado = '<td class="currency" data-orden="' + (Number(item.NAC_PAGADO_ARS) || 0) + '">'
            + (item.NAC_CON_PARCIALES ? formatCurrency(item.NAC_PAGADO_ARS) : '')
            + botones + '</td>';

        var saldo;

        if (item.CUBIERTA_PARCIALES) {
            saldo = '<td class="center pagado-parcial-ok" data-orden="0"'
                + (item.NAC_SOBREPAGO_ARS > 0
                    ? ' title="' + escaparAttrCrono('Pagado de más: ' + formatCurrency(item.NAC_SOBREPAGO_ARS)) + '"'
                    : '')
                + '>Pagado con pagos parciales'
                + (item.NAC_SOBREPAGO_ARS > 0 ? ' <i class="fas fa-triangle-exclamation text-warning"></i>' : '')
                + '</td>';
        } else {
            saldo = '<td class="currency" data-orden="' + (Number(item.SALDO_ARS) || 0) + '">'
                + (item.SALDO_ARS === null ? '' : formatCurrency(item.SALDO_ARS)) + '</td>';
        }

        /* LA FECHA DEL SALDO SE EDITA CON UN CLICK EN LA CELDA, igual que la
           de nacionalización, y con el lápiz a la vista. Sólo con pagos
           parciales: sin ellos es la de nacionalización -en gris- y se edita
           en su columna. */
        var editaFecha = item.NAC_CON_PARCIALES && puedePagoParcial() && !item.CUBIERTA_PARCIALES;
        var fechaSaldo = item.NAC_CON_PARCIALES
            ? (formatDate(item.FECHA_SALDO_EFECTIVA)
                + (item.FECHA_SALDO_CARGADA ? '' : ' <small class="text-muted">(la de nac.)</small>')
                + (editaFecha ? ' <i class="fas fa-pen-to-square text-primary ms-1"></i>' : ''))
            : '<span class="text-muted">' + formatDate(item.FECHA_SALDO_EFECTIVA) + '</span>';

        return costo + pagado + saldo
            + '<td class="center' + (item.NAC_CON_PARCIALES && item.VENCIDA_PENDIENTE ? ' text-danger' : '')
            + (editaFecha ? ' pago-nac-fecha' : '') + '"'
            + (editaFecha ? ' data-indice="' + index + '" role="button" title="Click para cambiar la fecha del saldo"' : '')
            + ' data-orden="' + escaparAttrCrono(item.FECHA_SALDO_EFECTIVA || '') + '">' + fechaSaldo + '</td>';
    }

    /** Hoy como 'aaaa-mm-dd', en la hora local */
    function hoyIso() {
        var d = new Date();

        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-'
            + ('0' + d.getDate()).slice(-2);
    }

    /** El item de la fila de un botón o un ícono */
    function itemDe(el) {
        var i = Number(el.getAttribute('data-indice'));

        return (datosCrono && datosCrono.filas && datosCrono.filas[i]) || null;
    }

    var itemPago = null;

    /** Los listeners del diálogo y de los botones de la tabla, una vez */
    function conectarDialogoPago() {
        var cuerpo = document.getElementById('tableBody');

        if (cuerpo) {
            cuerpo.addEventListener('click', function(ev) {
                var t = ev.target;

                if (t.closest('.pago-nac-historial')) {
                    abrirPagosNac(itemDe(t.closest('.pago-nac-historial')));
                } else if (t.closest('.pago-nac-fecha')) {
                    cambiarFechaSaldo(itemDe(t.closest('.pago-nac-fecha')));
                }
            });
        }

        ['pagoNacFecha', 'pagoNacMoneda', 'pagoNacImporte', 'pagoNacImporteUsd',
         'pagoNacCotizacion', 'pagoNacFechaSaldo'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) {
                el.addEventListener('input', recalcularDialogo);
                el.addEventListener('change', recalcularDialogo);
            }
        });

        /* Las dos respuestas de la pregunta del tilde. Cerrar el diálogo de
           cualquier otra forma no hace nada: el tilde ya volvió a su lugar. */
        var botonTotal = document.getElementById('tildeNacTotal');
        var botonParcial = document.getElementById('tildeNacParcial');
        var modalTilde = document.getElementById('modalTildeNac');

        if (botonTotal) {
            botonTotal.addEventListener('click', function() {
                var p = tildePendiente;

                tildePendiente = null;
                bootstrap.Modal.getOrCreateInstance(modalTilde).hide();

                if (p) {
                    p.chk.checked = true;
                    ComexFechas.marcarPagado(p.chk, cargarDatos);
                }
            });
        }

        if (botonParcial) {
            botonParcial.addEventListener('click', function() {
                var p = tildePendiente;

                tildePendiente = null;
                bootstrap.Modal.getOrCreateInstance(modalTilde).hide();

                if (p) {
                    abrirPagoNac(p.item);
                }
            });
        }

        var guardar = document.getElementById('pagoNacGuardar');

        if (guardar) {
            guardar.addEventListener('click', guardarPagoNac);
        }
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? el.value : '';
    }

    function poner(id, texto) {
        var el = document.getElementById(id);

        if (el) { el.textContent = texto; }
    }

    /** Si la fecha del saldo es obligatoria: nacionalización vencida o sin fecha */
    function saldoObligatorio(item) {
        var nac = item && item.FECHA_NAC_EFECTIVA;

        return !nac || nac < hoyIso();
    }

    function abrirPagoNac(item) {
        if (!item) { return; }

        itemPago = item;

        poner('pagoNacContenedor', (item.CONTENEDOR || '') + ' · OC ' + (item.ORDEN_COMPRA || '')
            + ' · ' + (item.PROVEEDOR || '') + ' · nacionalización '
            + formatDate(item.FECHA_NAC_EFECTIVA));
        poner('pagoNacBaseRotulo', item.BASE_ORIGEN === 'REAL' ? 'Costo real' : 'Costo estimado');

        document.getElementById('pagoNacFecha').value = hoyIso();
        document.getElementById('pagoNacFecha').max = hoyIso();
        document.getElementById('pagoNacMoneda').value = 'ARS';
        document.getElementById('pagoNacImporte').value = '';
        document.getElementById('pagoNacImporteUsd').value = '';
        document.getElementById('pagoNacCotizacion').value = '';
        document.getElementById('pagoNacFechaSaldo').value = item.FECHA_SALDO_CARGADA || '';
        document.getElementById('pagoNacFechaSaldo').min = hoyIso();
        document.getElementById('pagoNacObs').value = '';

        poner('pagoNacFechaSaldoNota', saldoObligatorio(item)
            ? '(obligatoria: la fecha de nacionalización ya venció)'
            : '(opcional: sin ella, el saldo cae el ' + formatDate(item.FECHA_NAC_EFECTIVA) + ')');

        recalcularDialogo();

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagoNac')).show();
    }

    /**
     * El importe en pesos del pago que se está cargando: el tipeado, o los
     * dólares por la cotización redondeado al centavo, igual que
     * Comex::enPesos(). Es sólo para mostrar.
     */
    function importePesos() {
        if (valor('pagoNacMoneda') === 'USD') {
            var usd = Number(valor('pagoNacImporteUsd'));
            var cot = Number(valor('pagoNacCotizacion'));

            return (usd > 0 && cot > 0) ? Math.round(usd * cot * 100) / 100 : 0;
        }

        return Number(valor('pagoNacImporte')) || 0;
    }

    /** Lo que falta para poder registrar, o '' si está todo */
    function faltaEnDialogo() {
        var hoy = hoyIso();
        var fecha = valor('pagoNacFecha');
        var saldo = valor('pagoNacFechaSaldo');

        if (!fecha) { return 'Falta la fecha de pago.'; }
        if (fecha > hoy) { return 'La fecha de pago no puede ser futura: un pago que todavía no se hizo no es un pago.'; }
        if (valor('pagoNacMoneda') === 'USD' && !(Number(valor('pagoNacCotizacion')) > 0)) {
            return 'Falta la cotización con la que se pagaron los dólares.';
        }
        if (!(importePesos() > 0)) { return 'El importe tiene que ser mayor a cero.'; }
        if (!saldo && saldoObligatorio(itemPago)) {
            return 'La fecha de nacionalización ya venció: cargá la fecha en la que se va a pagar el saldo.';
        }
        if (saldo && saldo < hoy) { return 'La fecha del saldo tiene que ser hoy o posterior.'; }

        return '';
    }

    function recalcularDialogo() {
        if (!itemPago) { return; }

        var usd = valor('pagoNacMoneda') === 'USD';

        document.querySelectorAll('#modalPagoNac .pago-nac-usd').forEach(function(el) {
            el.style.display = usd ? '' : 'none';
        });
        document.querySelectorAll('#modalPagoNac .pago-nac-ars').forEach(function(el) {
            el.style.display = usd ? 'none' : '';
        });

        var imp = importePesos();
        var base = Number(itemPago.BASE_ARS) || 0;
        var antes = Number(itemPago.NAC_PAGADO_ARS) || 0;
        var tol = Number(datosCrono.tolerancia_saldo) || 1;
        var saldoDe = function(pagado) {
            var dif = base - pagado;

            return (pagado > 0 && dif <= tol) ? 0 : dif;
        };

        poner('pagoNacEquivalente', usd && imp > 0 ? ('Equivale a ' + formatCurrency(imp)
            + ': es lo que se resta del saldo.') : '');
        poner('pagoNacBase', formatCurrency(base));
        poner('pagoNacBase2', formatCurrency(base));
        poner('pagoNacPagadoAntes', formatCurrency(antes));
        poner('pagoNacPagadoDespues', formatCurrency(antes + imp));
        poner('pagoNacSaldoAntes', formatCurrency(saldoDe(antes)));

        var despues = saldoDe(antes + imp);
        var sobra = (antes + imp) - base;

        poner('pagoNacSaldoDespues', despues === 0
            ? ('$ 0,00 · pagada' + (sobra > tol ? (' · pagado de más: ' + formatCurrency(sobra)) : ''))
            : formatCurrency(despues));

        var falta = faltaEnDialogo();

        poner('pagoNacError', falta);
        document.getElementById('pagoNacGuardar').disabled = falta !== '';
    }

    function guardarPagoNac() {
        if (!itemPago || faltaEnDialogo() !== '') { return; }

        var boton = document.getElementById('pagoNacGuardar');
        var usd = valor('pagoNacMoneda') === 'USD';

        boton.disabled = true;

        fetch('Controller/ComexController.php?action=registrarPagoNac', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_mg: itemPago.ID,
                fecha_pago: valor('pagoNacFecha'),
                moneda: valor('pagoNacMoneda'),
                importe: usd ? null : valor('pagoNacImporte'),
                importe_usd: usd ? valor('pagoNacImporteUsd') : null,
                cotizacion: usd ? valor('pagoNacCotizacion') : null,
                fecha_saldo: valor('pagoNacFechaSaldo') || null,
                observacion: valor('pagoNacObs')
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (!r.success) {
                // Lo que dice el servidor es lo que manda: se muestra en el diálogo.
                poner('pagoNacError', r.message);
                boton.disabled = false;

                return;
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagoNac')).hide();
            Notificacion.exito(r.message);
            cargarDatos();
        })
        .catch(function(e) {
            poner('pagoNacError', 'Error de conexión: no se guardó nada. ' + e.message);
            boton.disabled = false;
        });
    }

    /** La lista de pagos del contenedor, con su historial y la anulación */
    function abrirPagosNac(item) {
        if (!item) { return; }

        var cuerpo = document.getElementById('pagosNacCuerpo');

        cuerpo.innerHTML = '<div class="text-muted small">Cargando…</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagosNac')).show();

        fetch('Controller/ComexController.php?action=getPagosNac&id_mg=' + encodeURIComponent(item.ID))
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (!r.success) {
                    cuerpo.innerHTML = '<div class="text-danger small">' + escaparAttrCrono(r.message) + '</div>';

                    return;
                }

                cuerpo.innerHTML = htmlPagosNac(item, r.data);

                cuerpo.querySelectorAll('.pago-nac-anular').forEach(function(b) {
                    b.addEventListener('click', function() { anularPagoNac(item, b.getAttribute('data-id')); });
                });
            })
            .catch(function(e) {
                cuerpo.innerHTML = '<div class="text-danger small">No se pudieron leer los pagos: '
                    + escaparAttrCrono(e.message) + '</div>';
            });
    }

    function htmlPagosNac(item, d) {
        var edita = puedePagoParcial();
        var html = '<div class="small text-muted mb-2">' + escaparAttrCrono((item.CONTENEDOR || '')
            + ' · OC ' + (item.ORDEN_COMPRA || '')) + '. Un pago anulado deja de restar y queda acá.</div>'
            + '<table class="table table-sm small"><thead><tr>'
            + '<th>Fecha de pago</th><th>Moneda</th><th class="text-end">U$S</th>'
            + '<th class="text-end">Cotización</th><th class="text-end">Pesos</th><th>Observación</th>'
            + '<th>Cargado</th><th></th></tr></thead><tbody>';

        (d.pagos || []).forEach(function(p) {
            var vigente = Number(p.VIGENTE) === 1;

            html += '<tr class="' + (vigente ? '' : 'text-muted text-decoration-line-through') + '">'
                + '<td>' + formatDate(p.FECHA_PAGO) + '</td>'
                + '<td>' + (p.MONEDA === 'USD' ? 'Dólares' : 'Pesos') + '</td>'
                + '<td class="text-end">' + (p.IMPORTE_USD !== null ? formatUSD(p.IMPORTE_USD) : '') + '</td>'
                + '<td class="text-end">' + (p.COTIZACION !== null ? Number(p.COTIZACION).toLocaleString('es-AR') : '') + '</td>'
                + '<td class="text-end">' + formatCurrency(p.IMPORTE_ARS) + '</td>'
                + '<td>' + escaparAttrCrono(p.OBSERVACION || '') + '</td>'
                + '<td>' + escaparAttrCrono(Auditoria.quien(p.USUARIO_ALTA)) + ' · ' + Auditoria.fecha(p.FECHA_ALTA)
                + (vigente ? '' : '<br>Anulado por ' + escaparAttrCrono(Auditoria.quien(p.USUARIO_BAJA))
                    + ' · ' + Auditoria.fecha(p.FECHA_BAJA)) + '</td>'
                + '<td>' + (vigente && edita
                    ? '<button type="button" class="btn btn-outline-danger btn-xs pago-nac-anular" data-id="'
                        + p.ID + '">Anular</button>' : '') + '</td></tr>';
        });

        html += '</tbody></table>';

        if ((d.fechas_saldo || []).length) {
            html += '<div class="fw-bold small mb-1">Fecha del saldo</div><ul class="small mb-0">';

            d.fechas_saldo.forEach(function(s) {
                html += '<li class="' + (Number(s.VIGENTE) === 1 ? '' : 'text-muted') + '">'
                    + formatDate(s.FECHA_SALDO)
                    + (s.FECHA_ANTERIOR ? ' (antes ' + formatDate(s.FECHA_ANTERIOR) + ')' : '')
                    + ' · ' + escaparAttrCrono(Auditoria.quien(s.USUARIO_ALTA)) + ' · ' + Auditoria.fecha(s.FECHA_ALTA)
                    + (Number(s.VIGENTE) === 1 ? ' · <strong>vigente</strong>' : '') + '</li>';
            });

            html += '</ul>';
        }

        return html;
    }

    function anularPagoNac(item, id) {
        Notificacion.confirmar({
            titulo: 'Anular el pago',
            mensaje: '¿Anular este pago parcial?',
            detalle: 'Deja de restar: el saldo vuelve a subir y se proyecta otra vez. El pago no se '
                + 'borra, queda en el historial como anulado.',
            confirmar: 'Anular',
            peligro: true
        }).then(function(si) {
            if (!si) { return; }

            fetch('Controller/ComexController.php?action=anularPagoNac', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (!r.success) {
                    Notificacion.error('No se pudo anular el pago: ' + r.message);

                    return;
                }

                Notificacion.exito(r.message);
                abrirPagosNac(item);
                cargarDatos();
            })
            .catch(function(e) {
                Notificacion.error('Error de conexión al anular el pago: no se anuló. ' + e.message);
            });
        });
    }

    /** Cambia la fecha del saldo sin cargar un pago nuevo; queda en el historial */
    function cambiarFechaSaldo(item) {
        if (!item) { return; }

        Notificacion.pedirFecha({
            titulo: 'Fecha del saldo',
            mensaje: 'Cuándo se espera pagar lo que falta de la nacionalización de ' + (item.CONTENEDOR || '') + '.',
            etiqueta: 'Fecha estimada de pago del saldo',
            valor: item.FECHA_SALDO_CARGADA || '',
            min: hoyIso(),
            antesDelMin: 'Tiene que ser hoy o posterior: es cuándo se espera pagar lo que falta.',
            confirmar: 'Cambiar'
        }).then(function(fecha) {
            if (!fecha) { return; }

            fetch('Controller/ComexController.php?action=cambiarFechaSaldoNac', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_mg: item.ID, fecha: fecha })
            })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (!r.success) {
                    Notificacion.error('No se pudo cambiar la fecha del saldo: ' + r.message);

                    return;
                }

                Notificacion.exito(r.message);
                cargarDatos();
            })
            .catch(function(e) {
                Notificacion.error('Error de conexión: la fecha no se cambió. ' + e.message);
            });
        });
    }

    /* NO HAY exportarExcel(). Era una función de una línea que llamaba a
       exportarTabla(), más su listener sobre #btnExport: el botón ahora declara
       data-exportar en el HTML y lo engancha Js/tabla-export.js solo, que es
       como funciona el resto del módulo. El export sigue bajando lo que se ve,
       ahora también respetando el buscador: TablaExport saca del clon las filas
       con display:none. */

})(); // Fin del IIFE
