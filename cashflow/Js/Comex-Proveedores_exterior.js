/**
 * Comex - Proveedores Exterior JavaScript
 * Incluye funcionalidad de edición de Fecha Est. Pago y de la cotización
 *
 * LA GRILLA ESTÁ EN PESOS, Y EL DÓLAR ESTÁ A LA VISTA
 * ---------------------------------------------------
 * Antes esta pestaña mostraba dólares y el tablero mostraba pesos, convertidos
 * con un parámetro global. Eran dos pantallas del mismo módulo midiendo cosas
 * distintas: los totales de acá no se podían comparar contra la fila del
 * tablero que esta pestaña explica.
 *
 * Ahora las columnas del eje, los totales y las tarjetas están en PESOS, y cada
 * fila dice con QUÉ DÓLAR se valuó: el símbolo de la curva de futuros ROFEX
 * (DLR/NOV26) y su valor. El FOB en dólares queda como referencia, que es el
 * dato con el que se chequea contra la factura del proveedor.
 *
 * La cuenta no se hace acá: viene resuelta del backend -Class/Comex.php y
 * Class/DolarFuturo.php- fila por fila, y es la MISMA que consume el tablero.
 * En el navegador no queda ninguna multiplicación, por el mismo motivo por el
 * que no quedó ninguna aritmética de fechas.
 *
 * DOS MARCAS, DOS COSAS DISTINTAS
 * -------------------------------
 *   naranja  la cotización la corrigió una persona para este contenedor
 *   punteado el mes de pago no está en la curva y se usó el más cercano
 *
 * Las dos se explican en el tooltip. Un importe distinto del que esperaba el
 * usuario, sin nada que diga por qué, es indistinguible de un error.
 *
 * LA FECHA DE PAGO SE EDITA SOBRE EL MAESTRO DE COMERCIO EXTERIOR
 * ---------------------------------------------------------------
 * Antes se guardaba en una tabla del cashflow y la app de Comex no se enteraba.
 * Ahora el guardado escribe RO_T_IMPORTACIONES_ENCABEZADO.FECHA_EST_PAGO, así
 * que la fecha es una sola para las dos aplicaciones, y el mensaje de la
 * notificación lo dice: quien la mueve desde acá tiene que saber que la está
 * moviendo también allá.
 *
 * El badge de "Editada" cambió de significado con eso. Ya no dice "hay un valor
 * propio del cashflow que pisa al maestro" —eso dejó de existir— sino "esta
 * fecha del maestro la puso alguien desde acá", con quién y cuándo en el
 * tooltip. Lo calcula el backend comparando el rastro contra el maestro: si la
 * app de Comex movió la fecha después, el badge no aparece, porque el valor que
 * se ve ya no lo puso este módulo.
 *
 * SE VEN LOS VENCIDOS, Y SE MARCAN
 * --------------------------------
 * La consulta ya no corta por fecha de embarque, así que la grilla trae también
 * los contenedores ya embarcados: al 19/09/2026 eran 42 de 76, con más de mil
 * millones de pesos de pagos que el tablero no estaba contando.
 *
 * Un pago con la fecha vencida NO ENTRA EN NINGUNA COLUMNA del eje —no se lo
 * reubica en hoy; ver el encabezado de Class/Comex.php— así que su fila tiene
 * todas las celdas del eje vacías. Sin la marca, eso se lee como un contenedor
 * sin importe. Con la marca se lee como lo que es: una fecha para corregir, y
 * la celda de al lado es donde se corrige.
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js
 * ----------------------------------------
 * Antes esta pestaña armaba las columnas acá, con aritmética de fechas en el
 * navegador: 28 días desde hoy en la vista "Semanas" y 11 meses en la de
 * "Meses", con la regla de "día O mes" escrita por segunda vez para excluir el
 * tramo. Y el backend, en paralelo, agrupaba por día DEL MES EN CURSO — o sea
 * que el encabezado y los totales del servidor describían dos cosas distintas.
 *
 * Ahora el eje, las tres vistas y los importes por columna vienen resueltos del
 * backend (Class/EjeVista.php) sobre horizonte_dias y horizonte_meses, los
 * mismos parámetros que usa el tablero. Acá no queda ninguna cuenta de fechas.
 */

(function() {
    'use strict';

    let datosProveedores = null;

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    // Inicializar inmediatamente (para pestañas cargadas dinámicamente)
    function inicializar() {
        console.log('Inicializando Comex - Proveedores Exterior');

        // Verificar que los elementos existen antes de agregar listeners
        var btnRefresh = document.getElementById('btnRefresh');
        var busqueda = document.getElementById('busquedaProvExt');
        var verVencidas = document.getElementById('verVencidasProvExt');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }

        /* El interruptor no recarga del servidor: las filas ya están todas en
           el navegador y esconderlas es una decisión de cómo mirar la tabla.
           Es el mismo camino que el buscador, así que los dos terminan en
           filtrarTabla() y no pueden quedar diciendo cosas distintas. */
        if (verVencidas) {
            verVencidas.addEventListener('change', filtrarTabla);
        }

        var verPagados = document.getElementById('verPagadosProvExt');

        if (verPagados) {
            verPagados.addEventListener('change', filtrarTabla);
        }

        // El botón de Exportar ya no se engancha acá: lo toma
        // Js/tabla-export.js por su data-exportar, que es como funciona el
        // resto del módulo. Y así el export respeta el buscador, porque
        // TablaExport saca del clon las filas con display:none.

        if (busqueda) {
            busqueda.addEventListener('keyup', filtrarTabla);
        }

        vistas = crearEjeVistas({
            botones: 'vistasProvExt',
            periodo: 'periodoProvExt',
            alCambiar: generarTabla
        });

        // Proveedor y Contenedor: entre los dos identifican la fila, y son lo
        // que uno necesita tener a la vista al scrollear hasta la columna del
        // mes que le interesa.
        crearColumnasFijas({
            tabla: 'tablaProveedoresExterior',
            control: 'colFijasProvExt',
            clave: 'proveedores_exterior',
            porDefecto: [0, 1]
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
        
        fetch('Controller/ComexController.php?action=getProveedoresExterior')
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
                        datosProveedores = result.data;

                        console.log('Datos cargados:', datosProveedores);

                        // El controlador de vistas se entera del eje nuevo antes
                        // de que se dibuje la tabla: es el que decide qué
                        // columnas tiene la vista activa.
                        vistas.usar(datosProveedores);

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
        console.log('generarTabla() llamada', datosProveedores);
        
        if (!datosProveedores) {
            console.error('datosProveedores es null o undefined');
            mostrarError('No hay datos para mostrar');
            return;
        }

        if (!datosProveedores.filas) {
            console.error('datosProveedores.filas no existe');
            mostrarError('Estructura de datos incorrecta');
            return;
        }

        if (datosProveedores.filas.length === 0) {
            console.warn('No hay items para mostrar');
            mostrarError('No hay registros para el período seleccionado');
            return;
        }

        console.log('Generando tabla con', datosProveedores.filas.length, 'items');

        generarEncabezados();
        generarFilasDatos();

        // El buscador se reaplica sobre las filas recién dibujadas: cambiar de
        // vista o refrescar no puede hacer reaparecer lo que el usuario filtró,
        // con el campo de búsqueda todavía escrito. filtrarTabla() ya rehace
        // los totales, así que no hace falta llamarlos aparte.
        filtrarTabla();
    }

    /**
     * Los avisos del backend: lo que quedó fuera del horizonte o sin fecha.
     *
     * Antes esos importes se descartaban en silencio, así que la tabla podía
     * mostrar de menos sin que nadie se enterara.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosProvExt');

        if (!cont) {
            return;
        }

        var avisos = (datosProveedores && datosProveedores.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.join(' ') + '</small></div>'
            : '';
    }

    /**
     * De dónde sale el dólar con el que está valuada la tabla.
     *
     * El texto vive en Js/Comex-fechas.js desde que Crono Nacionalización
     * también se valúa con la curva: es la misma frase sobre el mismo origen, y
     * dos copias se desincronizan en la primera corrección. Acá queda el id del
     * elemento, que sí es de esta pestaña.
     */
    function pintarOrigenCotizacion() {
        ComexFechas.origenCotizacion('cotizProvExt',
            (datosProveedores && datosProveedores.cotizacion) || {});
    }

/**
 * Genera los encabezados dinámicos de la tabla.
 *
 * Las columnas salen del eje que resolvió el backend. Acá no se calcula
 * ninguna fecha: el rótulo de cada columna es el que ya viene en el payload,
 * así que el encabezado no puede describir un período distinto del que
 * muestran las celdas — que es exactamente lo que pasaba antes.
 */
function generarEncabezados() {
    const headerRowSub = document.getElementById('headerRowSub');
    const mesActualHeader = document.getElementById('mesActualHeader');

    if (!headerRowSub || !mesActualHeader) {
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

        // Un mes recortado tiene que decirlo: un importe más chico son menos
        // días cubiertos, no una caída de pagos.
        if (esMes && meta.parcial) {
            clases.push('col-parcial');
            titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
        }

        headerHTML += '<th class="' + clases.join(' ') + '"'
            + (titulo ? ' title="' + titulo + '"' : '') + '>'
            + vistas.rotulo(col) + '</th>';
    });

    // La columna Total cierra la tabla y suma EXACTAMENTE las columnas de la
    // vista activa, no siempre el horizonte completo.
    headerHTML += '<th class="total-column">Total</th>';

    mesActualHeader.textContent = datosProveedores.vistas[vistas.activa()].label;
    mesActualHeader.setAttribute('colspan', String(cols.length + 1));

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

    datosProveedores.filas.forEach(function(item, index) {
        // El texto que mira el buscador viaja en la fila, ya armado. Así el
        // filtro no depende del índice de ninguna columna —mover una columna no
        // lo rompe— y queda escrito en un solo lugar CUÁLES son los tres campos
        // por los que se busca. Mismo mecanismo que Crono Nacionalización.
        // data-vencida es lo que mira el interruptor "Ver vencidas". Va en la
        // fila y no se deduce de la clase: la clase es presentación y podría
        // cambiar; el atributo es el dato.
        var clases = [];

        if (item.VENCIDA) { clases.push('fila-vencida'); }
        if (item.PAGADO) { clases.push('fila-pagada'); }

        html += '<tr data-buscar="' + escaparAttrProv(textoBuscable(item)) + '"'
            + (item.VENCIDA ? ' data-vencida="1"' : '')
            + (item.PAGADO ? ' data-pagado="1"' : '')
            + (clases.length ? (' class="' + clases.join(' ') + '"') : '') + '>';

        // Columnas fijas
        // Recortado con puntos suspensivos (.col-texto); el nombre completo va
        // en el title. Ver Css/main.css.
        html += `<td class="col-texto" title="${escaparAttrProv(item.PROVEEDOR)}">${escaparAttrProv(item.PROVEEDOR)}</td>`;
        html += `<td class="center">${item.CONTENEDOR || ''}</td>`;
        html += `<td class="center">${item.ORDEN_COMPRA || ''}</td>`;
        html += `<td>${item.DESPACHANTE || ''}</td>`;
        html += `<td class="currency">${formatUSD(item.VALOR_FOB_DOLAR)}</td>`;
        
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
        
        // FECHA_EST_PAGO — editable, y ahora sobre el maestro de Comex.
        // La celda la arma Js/Comex-fechas.js, compartida con la otra pestaña.
        html += ComexFechas.celda(item, 'PAGO', {
            clase: 'fecha-pago',
            editable: !!datosProveedores.fechas_editables,
            alEditar: 'editarFechaPago'
        });

        // Con qué dólar se valuó la fila, y el importe que sale de eso. Las dos
        // celdas las arma Js/Comex-fechas.js, compartidas con la otra pestaña,
        // que desde feature/comex-nac-usd también valúa en dólares. Lo único
        // propio de acá es que la cotización se puede corregir.
        html += ComexFechas.celdaCotizacion(item, {
            editable: !!(datosProveedores.cotizacion && datosProveedores.cotizacion.editable),
            alEditar: 'editarCotizacion'
        });
        html += ComexFechas.celdaImporteArs(item);

        // El tilde de "ya se pagó". Lo dibuja Js/Comex-fechas.js, compartido
        // con la otra pestaña: es el mismo gesto sobre el otro pago del mismo
        // contenedor.
        html += ComexFechas.celdaPagado(item, 'PAGO', {
            editable: !!datosProveedores.pagado_editable,
            alMarcar: 'marcarPagadoProvExt'
        });

        // Los importes por columna ya vienen resueltos: la regla de "día O mes,
        // nunca las dos" la aplicó Horizonte::agrupar() en el backend, una sola
        // vez y para todas las pestañas.
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

/* LAS DOS CELDAS DE LA VALUACIÓN SE FUERON A Js/Comex-fechas.js.
   celdaCotizacion() y celdaImporteArs() vivían acá porque ésta era la única
   pestaña que valuaba en dólares. Desde feature/comex-nac-usd las dos lo hacen,
   así que el código es uno solo y lo único que quedó de este lado es el
   comportamiento editable —editarCotizacion(), más abajo—, que sigue siendo de
   esta pestaña. Ver el bloque "CON QUÉ DÓLAR SE VALUÓ LA FILA" del compartido. */

/**
 * Esconde las filas que el buscador o el interruptor dejan afuera, y rehace los
 * totales.
 *
 * Los totales se rehacen porque si no, el pie diría el total de todo arriba de
 * una tabla que muestra tres filas, y nada en la pantalla diría que esos dos
 * números miden cosas distintas.
 *
 * Las tarjetas de arriba NO se tocan, y es deliberado: miden el cronograma
 * completo, que es lo que se quiere saber aunque uno esté mirando un
 * contenedor. Es el mismo reparto que Crono Nacionalización y Cobranzas May.
 */
function filtrarTabla() {
    ComexFechas.filtrar('busquedaProvExt', 'tableBody',
        'verVencidasProvExt', 'verPagadosProvExt');

    generarFilaTotales();
    pintarEstadoVencidas();
    pintarEstadoPagados();
}

/** Los items que el buscador y los interruptores dejan ver, o null si no hay filtro */
function filasVisibles() {
    return ComexFechas.visibles('busquedaProvExt',
        (datosProveedores && datosProveedores.filas) || [],
        'verVencidasProvExt', 'verPagadosProvExt');
}

/**
 * Cuántas filas esconde el interruptor de pagados, al lado del interruptor.
 *
 * SE DICE SIEMPRE, igual que el de vencidas. Acá importa todavía más: un pago
 * marcado SALIÓ DE LA PROYECCIÓN, así que si además desapareciera de la
 * pantalla sin decirlo, nada explicaría por qué el tablero cuenta menos.
 */
function pintarEstadoPagados() {
    var el = document.getElementById('estadoPagadosProvExt');

    if (!el) {
        return;
    }

    var n = ComexFechas.contarPagadas((datosProveedores && datosProveedores.filas) || []);

    if (n === 0) {
        el.textContent = 'ninguno marcado';
        el.title = 'Ningún pago está marcado como hecho: el tablero los proyecta a todos.';

        return;
    }

    var viendo = ComexFechas.prendido('verPagadosProvExt');

    el.textContent = viendo
        ? (n === 1 ? 'se ve 1 pagado' : ('se ven los ' + n + ' pagados'))
        : (n + ' pagado' + (n === 1 ? '' : 's') + ' escondido' + (n === 1 ? '' : 's'));

    el.title = 'Son pagos marcados como ya hechos: salieron de la proyección y la fila del '
        + 'tablero no los cuenta. ' + (viendo
            ? 'Destildá el que se haya marcado por error.'
            : 'Prendé el interruptor para verlos y poder destildarlos.');
}

/**
 * Cuántas filas esconde el interruptor, al lado del interruptor.
 *
 * SE DICE SIEMPRE, prendido o apagado. Una tabla que esconde filas sin decirlo
 * se lee como que esos contenedores no existen, y acá son muchos: al
 * 19/09/2026, 27 de 76. Es el mismo criterio que el "Ver excluidos" de Echeqs.
 *
 * El importe no se repite acá: ya lo dicen los dos avisos de arriba, con el
 * detalle de cuánto entra igual en la columna del mes en curso y cuánto no
 * entra en ninguna.
 */
function pintarEstadoVencidas() {
    var el = document.getElementById('estadoVencidasProvExt');

    if (!el) {
        return;
    }

    var n = ComexFechas.contarVencidas((datosProveedores && datosProveedores.filas) || []);

    if (n === 0) {
        el.textContent = 'no hay vencidas';
        el.title = 'Ningún contenedor tiene la fecha estimada de pago vencida.';

        return;
    }

    var viendo = ComexFechas.verVencidas('verVencidasProvExt');

    el.textContent = viendo
        ? (n === 1 ? 'se ve 1 vencida' : ('se ven las ' + n + ' vencidas'))
        : (n + ' vencida' + (n === 1 ? '' : 's') + ' escondida' + (n === 1 ? '' : 's'));

    /* ESCONDERLAS NO CAMBIA NINGÚN NÚMERO, y eso es lo que hay que poder
       decir: un pago vencido no suma en ninguna columna —al cashflow entra lo
       que se paga de hoy en adelante, ver Comex::aporteAlEje()—, así que el
       interruptor saca de la vista filas que ya valían cero en el período. Las
       tarjetas y el pie dicen lo mismo con el interruptor prendido o apagado.

       Es la diferencia con el buscador, que sí puede dejar el pie midiendo
       algo distinto de las tarjetas. */
    el.title = viendo
        ? 'Están marcadas en rojo. No suman en ninguna columna del período: apagá el '
            + 'interruptor para sacarlas de la tabla.'
        : 'Son contenedores con la fecha estimada de pago ya vencida, que no suman en '
            + 'ninguna columna del período. Esconderlos no cambia ningún total. Prendé el '
            + 'interruptor para verlos y cargarles una fecha nueva.';
}

/** Los tres campos por los que busca el buscador, concatenados */
function textoBuscable(item) {
    return ComexFechas.textoBuscable(item);
}

/**
 * Genera la fila de totales.
 *
 * SIN FILTRO los totales salen del payload y no se recalculan acá recorriendo
 * los items: recalcularlos era una tercera copia de la regla de "día O mes", y
 * una copia que se puede desincronizar de las celdas que tiene arriba.
 *
 * CON EL BUSCADOR ACTIVO hay que sumar los items visibles, porque el total de
 * todo arriba de una tabla filtrada es un número que no corresponde a nada de
 * lo que se está viendo. Sumar los valores POR COLUMNA que el payload ya trae
 * resueltos no es volver a implementar la regla de "día O mes": es sumar
 * exactamente las celdas que están dibujadas.
 */
function generarFilaTotales() {
    var totalsRow = document.getElementById('totalsRow');

    if (!totalsRow) {
        return;
    }

    var visibles = filasVisibles();
    var totales = (visibles === null)
        ? (datosProveedores.totales || {})
        : ComexFechas.sumarColumnas(visibles);

    /* Ocho columnas fijas más las dos de la valuación. El pie NO totaliza la
       cotización: promediar cotizaciones de meses distintos daría un número que
       no es el tipo de cambio de nada. Lo que sí suma es la columna en pesos. */
    var html = '<td colspan="9" class="total-label">TOTALES</td>'
        + '<td class="currency" title="' + escaparAttrProv('Suma el importe de las filas que se '
            + 'están viendo, incluidas las que caen fuera del horizonte —las vencidas y las '
            + 'posteriores al último mes—. Por eso puede no coincidir con el total de las '
            + 'columnas, que sólo cubre el período. La diferencia está en los avisos de arriba.')
        + '">'
        + formatCurrency(sumaImporteArs(visibles)) + '</td>'
        /* La columna del tilde no totaliza nada: contar cuántos están tildados
           en el pie de una tabla de importes no significaría nada, y el
           conteo ya está al lado del interruptor. */
        + '<td></td>';

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
 * Los tres salen de los totales del payload y miden exactamente los tres
 * períodos de las vistas, así que cada tarjeta se corresponde con lo que
 * muestra un botón. Antes se recalculaban acá con su propia ventana de 28 días
 * y 11 meses, que no era la del encabezado ni la del backend.
 */
function calcularResumenes() {
    var totales = (datosProveedores && datosProveedores.totales) || {};

    document.getElementById('total4semanas').textContent =
        formatCurrency(totales.total_tramo || 0);
    document.getElementById('total11meses').textContent =
        formatCurrency(totales.total_meses || 0);
    document.getElementById('totalGeneral').textContent =
        formatCurrency(totales.total_horizonte || 0);

    // Los rótulos dicen el período real, que depende del horizonte configurado
    // y ya no de un "4 semanas / 11 meses" escrito a mano.
    var vs = (datosProveedores && datosProveedores.vistas) || {};

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
 * Permite editar la fecha de pago.
 *
 * El editor entero vive en Js/Comex-fechas.js, compartido con Crono
 * Nacionalización: es el mismo gesto sobre el mismo maestro. Acá queda lo único
 * propio, que es qué hacer después de guardar.
 *
 * SE RECARGA TODO. La fecha nueva cambia la columna del eje en la que cae el
 * importe, el total, los avisos de vencidos y —si el pago cambió de mes— la
 * cotización con la que se valúa la fila y su importe en pesos. Repintar sólo
 * la celda dejaría las otras cinco cosas diciendo lo anterior.
 *
 * @param {HTMLElement} cell Celda donde se hizo click
 */
window.editarFechaPago = function(cell) {
    ComexFechas.editar(cell, cargarDatos);
};

/**
 * Marca o desmarca el pago de un contenedor.
 *
 * SE RECARGA TODO. El tilde saca la fila de la proyección: cambian su columna
 * del eje, el total, el contador del interruptor y los avisos. Repintar sólo la
 * celda dejaría las otras cuatro cosas diciendo lo anterior.
 *
 * @param {HTMLElement} chk
 */
window.marcarPagadoProvExt = function(chk) {
    ComexFechas.marcarPagado(chk, cargarDatos);
};

/**
 * Permite corregir la cotización de UN contenedor.
 *
 * MISMA MECÁNICA QUE LA FECHA -clic, input, Enter o blur para guardar, Esc para
 * cancelar- a propósito: es la segunda cosa editable de esta grilla y aprender
 * dos gestos distintos para lo mismo no tiene ninguna ventaja.
 *
 * VACÍO BORRA EL OVERRIDE y la fila vuelve a la curva. Es la única forma de
 * deshacer una corrección, así que tiene que ser la misma acción y no un botón
 * aparte.
 *
 * NO TOCA LA TABLA MAESTRA DEL ROFEX: el override vive en la tabla del cashflow
 * y afecta a este contenedor, no a todos los del mes.
 *
 * @param {HTMLElement} cell Celda donde se hizo click
 */
window.editarCotizacion = function(cell) {
    if (cell.querySelector('input')) {
        return;
    }

    var idMg = cell.dataset.id;
    var originalContent = cell.innerHTML;

    var input = document.createElement('input');

    input.type = 'text';
    input.className = 'cotiz-input';
    input.value = cell.dataset.cotiz || '';
    input.placeholder = 'vacío = curva';

    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    var guardando = false;

    var guardar = function() {
        if (guardando) {
            return;
        }

        guardando = true;

        var nueva = input.value.trim();

        // Nada que hacer: ni se cargó ni se borró nada.
        if (nueva === (cell.dataset.cotiz || '')) {
            cell.innerHTML = originalContent;
            return;
        }

        cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        fetch('Controller/ComexController.php?action=updateCotizacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_mg: idMg, cotizacion: nueva })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                // Se recarga todo: cambia el importe de la fila, su columna del
                // eje, el total y los avisos. Repintar sólo la celda dejaría
                // las otras cuatro cosas diciendo lo anterior.
                cargarDatos();
            } else {
                alert('No se pudo guardar la cotización: ' + result.message);
                cell.innerHTML = originalContent;
                guardando = false;
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('Error de conexión al guardar la cotización');
            cell.innerHTML = originalContent;
            guardando = false;
        });
    };

    input.addEventListener('blur', guardar);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            guardar();
        } else if (e.key === 'Escape') {
            guardando = true;   // que el blur que viene no dispare el guardado
            cell.innerHTML = originalContent;
        }
    });
};

/** Escapa un texto para meterlo en un atributo o en el cuerpo de una celda */
function escaparAttrProv(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

/**
 * Suma el importe en pesos de las filas que se están viendo.
 *
 * RESPETA EL BUSCADOR, igual que las columnas del eje: si el pie sumara todo
 * mientras la tabla muestra tres filas, esta celda y la de al lado dirían
 * números de dos universos distintos sin que nada lo indique.
 *
 * Las que no se pudieron valuar suman cero acá y se informan aparte, en dólares:
 * es la única moneda en la que existen, y meterlas en este total las haría
 * desaparecer. Ver Comex::avisosValuacion().
 *
 * @param {Array|null} visibles Las filas filtradas, o null si no hay filtro
 * @returns {number}
 */
function sumaImporteArs(visibles) {
    var filas = (visibles === null || visibles === undefined)
        ? ((datosProveedores && datosProveedores.filas) || [])
        : visibles;

    return filas.reduce(function(a, f) {
        return a + (Number(f.IMPORTE_ARS) || 0);
    }, 0);
}

/**
 * Formatea un valor en PESOS, que es la moneda del eje y de los totales.
 *
 * Se llama formatCurrency porque es la moneda de la tabla: las columnas del
 * eje, el pie y las tarjetas están en pesos. Los dólares son la referencia y
 * tienen su propia función, que lo dice en el nombre.
 */
function formatCurrency(value) {
    var num = parseFloat(value) || 0;
    return '$ ' + num.toLocaleString('es-AR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/** Formatea un valor en dólares: el FOB, que queda como referencia */
function formatUSD(value) {
    var num = parseFloat(value) || 0;
    return 'U$S ' + num.toLocaleString('es-AR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/* NO HAY formatCotiz(). La única celda que formateaba cotizaciones se fue al
   archivo compartido y se llevó su formateador: dejarlo acá sin llamador sería
   la segunda copia esperando a que alguien la use. */

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
   data-exportar en el HTML y lo engancha Js/tabla-export.js solo, que es como
   funciona el resto del módulo. Es el mismo cambio que ya había hecho Crono
   Nacionalización, y acá se vuelve necesario: la pestaña tiene buscador, y
   TablaExport saca del clon las filas con display:none, así que Exportar baja
   lo que el buscador está dejando ver. */

})(); // Fin del IIFE
