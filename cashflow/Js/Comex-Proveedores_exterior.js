/**
 * Comex - Proveedores Exterior JavaScript
 * Incluye funcionalidad de edición de Fecha Est. Pago
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
        html += `<td class="currency">${formatCurrency(item.VALOR_FOB_DOLAR)}</td>`;
        
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
    var html = '<td colspan="8" class="total-label">TOTALES</td>';

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

/** Escapa un texto para meterlo en un atributo o en el cuerpo de una celda */
function escaparAttrProv(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

/**
 * Formatea un valor como moneda USD
 */
function formatCurrency(value) {
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
function exportarExcel() {
    // Crear una tabla temporal con todos los datos
    var tabla = document.getElementById('tablaProveedoresExterior').cloneNode(true);
    
    // Convertir a Excel usando una librería o método simple
    var html = tabla.outerHTML;
    var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    var url = URL.createObjectURL(blob);
    
    var a = document.createElement('a');
    a.href = url;
    a.download = 'Proveedores_Exterior_' + new Date().toISOString().split('T')[0] + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

})(); // Fin del IIFE
