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
        var btnExport = document.getElementById('btnExport');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }
        if (btnExport) {
            btnExport.addEventListener('click', exportarExcel);
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
        generarFilaTotales();
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
     * Va en el pie junto al período, y no es decoración: un importe en pesos
     * que no se puede atar a una cotización identificada y fechada no se puede
     * auditar contra nada. Es el mismo criterio con el que Dólares Cuenta
     * Comitente muestra la fecha y la punta de su cotización.
     */
    function pintarOrigenCotizacion() {
        var el = document.getElementById('cotizProvExt');

        if (!el) {
            return;
        }

        var c = (datosProveedores && datosProveedores.cotizacion) || {};

        if (!c.disponible) {
            el.innerHTML = '<span class="text-danger">'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + 'Sin curva de dólar futuro: los importes no se pueden expresar en pesos.'
                + '</span>';
            return;
        }

        el.textContent = 'Valuado con dólar futuro ROFEX'
            + (c.ultimo_mes ? ', curva hasta ' + c.ultimo_mes : '')
            // Sólo el día: 'actualizada' viene como 'Y-m-d H:i:s' y formatDate
            // espera una fecha pelada.
            + (c.actualizada
                ? ' (actualizada el ' + formatDate(String(c.actualizada).substring(0, 10)) + ')'
                : '')
            + (c.editable ? '.' : '. La corrección manual está apagada: falta el script.');
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
        html += '<tr>';
        
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
        
        // FECHA_EST_PAGO - Editable
        var fechaPagoEfectiva = item.FECHA_PAGO_EFECTIVA || item.FECHA_EST_PAGO || '-';
        var esEditada = item.FECHA_PAGO_EDIT != null;
        var fechaOriginal = item.FECHA_EST_PAGO || '-';
        
        html += `<td class="center fecha-pago-cell ${esEditada ? 'fecha-editada' : ''}" 
                     data-id="${item.ID}" 
                     data-fecha-orig="${item.FECHA_EST_PAGO || ''}" 
                     data-fecha-edit="${item.FECHA_PAGO_EDIT || ''}"
                     onclick="editarFechaPago(this)">
                    <div class="fecha-pago-display">
                        <span class="fecha-value">${formatDate(fechaPagoEfectiva)}</span>
                        ${esEditada ? '<span class="badge-fecha-editada">Editada</span>' : ''}
                        <i class="fas fa-pen fecha-pago-icon"></i>
                    </div>
                    ${esEditada ? `
                        <div class="fecha-tooltip">
                            <span class="fecha-tooltip-label">Fecha Original</span>
                            <span class="fecha-tooltip-value">${formatDate(fechaOriginal)}</span>
                        </div>
                    ` : ''}
                 </td>`;

        // Con qué dólar se valuó la fila, y el importe que sale de eso.
        html += celdaCotizacion(item);
        html += celdaImporteArs(item);

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

/**
 * La celda del dólar aplicado: qué cotización se usó, de qué mes, y por qué.
 *
 * SE MARCAN LOS DOS CASOS ESPECIALES y se explican en el tooltip. El texto lo
 * escribe el backend -DolarFuturo::explicar()- y no este archivo: lo usan el
 * tablero y la pestaña, y dos textos parecidos se desincronizan.
 *
 * SIN COTIZACIÓN SE DIBUJA UN GUIÓN, no un cero. Es la diferencia entre "no hay
 * dato" y "el dato es cero", y es el criterio de todo el módulo.
 *
 * @param {Object} item Fila del payload
 * @returns {string} HTML de la celda
 */
function celdaCotizacion(item) {
    var editable = !!(datosProveedores.cotizacion && datosProveedores.cotizacion.editable);
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
        cuerpo = '<span class="cotiz-valor">' + formatCotiz(item.COTIZ_USD) + '</span>'
            + '<span class="cotiz-simbolo">'
            + escaparAttrProv(item.COTIZ_ORIGEN === 'OVERRIDE'
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
        + ' title="' + escaparAttrProv(detalle
            + (editable ? ' Hacé clic para corregirla sólo para este contenedor; '
                + 'dejala vacía para volver a la curva.' : ''))
        + '"' + (editable ? ' onclick="editarCotizacion(this)"' : '') + '>'
        + cuerpo + '</td>';
}

/**
 * El importe en pesos de la fila: el FOB por la cotización que le tocó.
 *
 * EN BLANCO CUANDO NO SE PUDO VALUAR, con el motivo en el tooltip. Un cero diría
 * que este contenedor no se paga, que es una afirmación que nadie hizo.
 *
 * @param {Object} item Fila del payload
 * @returns {string} HTML de la celda
 */
function celdaImporteArs(item) {
    if (item.IMPORTE_ARS === null || item.IMPORTE_ARS === undefined) {
        return '<td class="currency cotiz-sin" title="'
            + escaparAttrProv(item.COTIZ_DETALLE || '') + '">—</td>';
    }

    return '<td class="currency importe-ars">' + formatCurrency(item.IMPORTE_ARS) + '</td>';
}

/**
 * Genera la fila de totales
 */
function generarFilaTotales() {
    var totalsRow = document.getElementById('totalsRow');

    if (!totalsRow) {
        return;
    }

    // Los totales salen del payload y no se recalculan acá recorriendo los
    // items: recalcularlos era una tercera copia de la regla de "día O mes", y
    // una copia que se puede desincronizar de las celdas que tiene arriba.
    var totales = datosProveedores.totales || {};

    /* Ocho columnas fijas más las dos de la valuación. El pie NO totaliza la
       cotización: promediar cotizaciones de meses distintos daría un número que
       no es el tipo de cambio de nada. Lo que sí suma es la columna en pesos. */
    var html = '<td colspan="9" class="total-label">TOTALES</td>'
        + '<td class="currency" title="' + escaparAttrProv('Suma el importe de TODAS las filas '
            + 'de la tabla, incluidas las que caen fuera del horizonte. Por eso puede no '
            + 'coincidir con el total de las columnas, que sólo cubre el período. La '
            + 'diferencia está en los avisos de arriba.') + '">'
        + formatCurrency(sumaImporteArs()) + '</td>';

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
 * Permite editar la fecha de pago
 * @param {HTMLElement} cell Celda donde se hizo click
 */
window.editarFechaPago = function(cell) {
    // Evitar edición múltiple
    if (cell.querySelector('input')) {
        return;
    }
    
    var idMg = cell.dataset.id;
    var fechaOrig = cell.dataset.fechaOrig;
    var fechaEdit = cell.dataset.fechaEdit || fechaOrig;
    
    // Guardar contenido original
    var originalContent = cell.innerHTML;
    
    // Crear input date
    var input = document.createElement('input');
    input.type = 'date';
    input.className = 'fecha-pago-input';
    
    // Normalizar la fecha para evitar problemas de zona horaria
    if (fechaEdit || fechaOrig) {
        var fechaParaInput = fechaEdit || fechaOrig;
        // Asegurar formato YYYY-MM-DD sin conversión de timezone
        if (fechaParaInput && fechaParaInput !== '-') {
            input.value = fechaParaInput.split('T')[0]; // Tomar solo la parte de fecha
        }
    }
    
    // Reemplazar contenido con input
    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();
    
    // Handler para guardar
    var guardarFecha = function() {
        var nuevaFecha = input.value;
        
        if (!nuevaFecha) {
            cell.innerHTML = originalContent;
            return;
        }
        
        // Mostrar loading
        cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        // Enviar al servidor
        fetch('Controller/ComexController.php?action=updateFechaPago', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id_mg: idMg,
                fecha_pago_orig: fechaOrig,
                fecha_pago_edit: nuevaFecha
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                /* SI EL CAMBIO DE MES DESCARTÓ LA COTIZACIÓN CARGADA A MANO,
                   hay que decirlo: el usuario movió una fecha y va a ver
                   cambiar un importe por una segunda razón que no pidió. El
                   backend lo resuelve en la misma transacción y lo informa
                   acá. Ver Comex::updateFechaPago(). */
                if (result.data && result.data.cotizacion_descartada) {
                    alert(result.message);
                }

                // Recargar datos para reflejar el cambio
                cargarDatos();
            } else {
                alert('Error al guardar: ' + result.message);
                cell.innerHTML = originalContent;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión al guardar la fecha');
            cell.innerHTML = originalContent;
        });
    };
    
    // Events
    input.addEventListener('blur', guardarFecha);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            guardarFecha();
        } else if (e.key === 'Escape') {
            cell.innerHTML = originalContent;
        }
    });
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
 * Suma el importe en pesos de todas las filas de la tabla.
 *
 * Las que no se pudieron valuar suman cero acá y se informan aparte, en dólares:
 * es la única moneda en la que existen, y meterlas en este total las haría
 * desaparecer. Ver Comex::avisosValuacion().
 *
 * @returns {number}
 */
function sumaImporteArs() {
    return ((datosProveedores && datosProveedores.filas) || []).reduce(function(a, f) {
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

/**
 * Formatea una cotización.
 *
 * Con DOS decimales y no con los cuatro que guarda la base: son los que se leen,
 * y el valor exacto ya está en el tooltip y en el campo de edición. Cuatro
 * decimales en una columna angosta no se leen y no deciden nada.
 */
function formatCotiz(value) {
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

/**
 * Exporta la tabla a Excel
 */
/** Exporta lo que se ve. Ver Js/tabla-export.js. */
function exportarExcel() {
    exportarTabla('tablaProveedoresExterior', 'Proveedores_Exterior');
}

})(); // Fin del IIFE
