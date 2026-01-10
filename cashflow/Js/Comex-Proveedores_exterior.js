/**
 * Comex - Proveedores Exterior JavaScript
 * Incluye funcionalidad de edición de Fecha Est. Pago
 */

(function() {
    'use strict';
    
    let datosProveedores = null;
    let totalesDias = {};
    let totalesMeses = {};
    let vistaActual = 'semanas'; // 'semanas' o 'meses'

    // Inicializar inmediatamente (para pestañas cargadas dinámicamente)
    function inicializar() {
        console.log('Inicializando Comex - Proveedores Exterior');
        
        // Verificar que los elementos existen antes de agregar listeners
        var btnRefresh = document.getElementById('btnRefresh');
        var btnExport = document.getElementById('btnExport');
        var btnVistaSemanas = document.getElementById('btnVistaSemanas');
        var btnVistaMeses = document.getElementById('btnVistaMeses');
        
        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }
        if (btnExport) {
            btnExport.addEventListener('click', exportarExcel);
        }
        if (btnVistaSemanas) {
            btnVistaSemanas.addEventListener('click', function() {
                cambiarVista('semanas');
            });
        }
        if (btnVistaMeses) {
            btnVistaMeses.addEventListener('click', function() {
                cambiarVista('meses');
            });
        }
        
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
     * Cambia entre vista de semanas y meses
     */
    function cambiarVista(vista) {
        vistaActual = vista;
        
        var btnSemanas = document.getElementById('btnVistaSemanas');
        var btnMeses = document.getElementById('btnVistaMeses');
        
        if (vista === 'semanas') {
            btnSemanas.classList.remove('btn-outline-secondary');
            btnSemanas.classList.add('btn-primary');
            btnMeses.classList.remove('btn-primary');
            btnMeses.classList.add('btn-outline-secondary');
        } else {
            btnMeses.classList.remove('btn-outline-secondary');
            btnMeses.classList.add('btn-primary');
            btnSemanas.classList.remove('btn-primary');
            btnSemanas.classList.add('btn-outline-secondary');
        }
        
        if (datosProveedores) {
            generarTabla();
        }
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
                        totalesDias = result.data.totales_dias || {};
                        totalesMeses = result.data.totales_meses || {};
                        
                        console.log('Datos cargados:', datosProveedores);
                        
                        generarTabla();
                        calcularResumenes();
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
        
        if (!datosProveedores.items) {
            console.error('datosProveedores.items no existe');
            mostrarError('Estructura de datos incorrecta');
            return;
        }
        
        if (datosProveedores.items.length === 0) {
            console.warn('No hay items para mostrar');
            mostrarError('No hay registros para el período seleccionado');
            return;
        }
        
        console.log('Generando tabla con', datosProveedores.items.length, 'items');
        
        generarEncabezados();
        generarFilasDatos();
        generarFilaTotales();
    }

/**
 * Genera los encabezados dinámicos de la tabla
 */
function generarEncabezados() {
    console.log('generarEncabezados() llamada - Vista:', vistaActual);
    
    const headerRowSub = document.getElementById('headerRowSub');
    const mesActualHeader = document.getElementById('mesActualHeader');
    
    if (!headerRowSub || !mesActualHeader) {
        console.error('Elementos de encabezado no encontrados');
        return;
    }
    
    let headerHTML = '';
    var hoy = new Date();
    
    if (vistaActual === 'semanas') {
        // Vista de 4 semanas = 28 días individuales
        mesActualHeader.textContent = 'Próximos 28 Días (4 Semanas)';
        mesActualHeader.setAttribute('colspan', '28');
        
        // Generar 28 columnas de días
        for (var i = 0; i < 28; i++) {
            var fecha = new Date(hoy);
            fecha.setDate(hoy.getDate() + i);
            var diaNum = fecha.getDate();
            var mesNum = fecha.getMonth() + 1;
            
            headerHTML += `<th class="day-column">${diaNum}/${mesNum}</th>`;
        }
        
    } else {
        // Vista de meses: solo 11 meses (sin días)
        mesActualHeader.textContent = 'Próximos 11 Meses';
        mesActualHeader.setAttribute('colspan', '11');
        
        // Generar encabezados de los próximos 11 meses
        for (var i = 0; i < 11; i++) {
            var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
            var mesAbrev = fechaMes.toLocaleDateString('es-ES', { month: 'short', year: '2-digit' });
            headerHTML += `<th class="month-column">${mesAbrev.charAt(0).toUpperCase() + mesAbrev.slice(1)}</th>`;
        }
    }
    
    headerRowSub.innerHTML = headerHTML;
    console.log('Encabezados generados correctamente');
}

/**
 * Genera las filas de datos
 */
function generarFilasDatos() {
    console.log('generarFilasDatos() llamada - Vista:', vistaActual);
    
    var tableBody = document.getElementById('tableBody');
    
    if (!tableBody) {
        console.error('tableBody no encontrado');
        return;
    }
    
    var hoy = new Date();
    hoy.setHours(0, 0, 0, 0); // Normalizar a medianoche
    
    var fecha28Dias = new Date(hoy);
    fecha28Dias.setDate(hoy.getDate() + 28);
    
    var html = '';
    
    datosProveedores.items.forEach(function(item, index) {
        html += '<tr>';
        
        // Columnas fijas
        html += `<td>${item.PROVEEDOR || ''}</td>`;
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
        
        var fechaPago = fechaPagoEfectiva !== '-' ? new Date(fechaPagoEfectiva) : null;
        if (fechaPago) fechaPago.setHours(0, 0, 0, 0);
        
        if (vistaActual === 'semanas') {
            // Vista semanas: 28 días individuales
            for (var i = 0; i < 28; i++) {
                var fechaDia = new Date(hoy);
                fechaDia.setDate(hoy.getDate() + i);
                
                var esMismoDia = false;
                if (fechaPago) {
                    esMismoDia = fechaPago.getTime() === fechaDia.getTime();
                }
                
                var valor = esMismoDia ? item.VALOR_FOB_DOLAR : 0;
                html += `<td class="currency ${valor > 0 ? 'cell-with-value' : ''}">
                            ${valor > 0 ? formatCurrency(valor) : ''}
                         </td>`;
            }
            
        } else {
            // Vista meses: solo 11 meses (excluyendo las primeras 4 semanas)
            var mesPago = fechaPagoEfectiva !== '-' ? fechaPagoEfectiva.substring(0, 7) : '';
            
            for (var i = 0; i < 11; i++) {
                var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
                var mesKey = fechaMes.getFullYear() + '-' + String(fechaMes.getMonth() + 1).padStart(2, '0');
                var esMesPago = mesPago === mesKey;
                
                var valorMes = 0;
                if (esMesPago) {
                    // Verificar que la fecha de pago no esté dentro de las primeras 4 semanas (28 días)
                    if (fechaPago && fechaPago >= fecha28Dias) {
                        valorMes = item.VALOR_FOB_DOLAR;
                    }
                }
                
                html += `<td class="currency ${valorMes > 0 ? 'cell-with-value' : ''}">
                            ${valorMes > 0 ? formatCurrency(valorMes) : ''}
                         </td>`;
            }
        }
        
        html += '</tr>';
    });
    
    tableBody.innerHTML = html;
    console.log('Filas de datos generadas:', datosProveedores.items.length, 'filas');
}

/**
 * Genera la fila de totales
 */
function generarFilaTotales() {
    var totalsRow = document.getElementById('totalsRow');
    var hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    
    var fecha28Dias = new Date(hoy);
    fecha28Dias.setDate(hoy.getDate() + 28);
    
    var html = '<td colspan="8" class="total-label">TOTALES</td>';
    
    if (vistaActual === 'semanas') {
        // Totales por día (28 días)
        for (var i = 0; i < 28; i++) {
            var fechaDia = new Date(hoy);
            fechaDia.setDate(hoy.getDate() + i);
            
            var totalDia = 0;
            if (datosProveedores && datosProveedores.items) {
                datosProveedores.items.forEach(function(item) {
                    var fechaPagoEfectiva = item.FECHA_PAGO_EFECTIVA || item.FECHA_EST_PAGO;
                    if (fechaPagoEfectiva) {
                        var fechaPago = new Date(fechaPagoEfectiva);
                        fechaPago.setHours(0, 0, 0, 0);
                        if (fechaPago.getTime() === fechaDia.getTime()) {
                            totalDia += parseFloat(item.VALOR_FOB_DOLAR) || 0;
                        }
                    }
                });
            }
            
            html += `<td class="currency ${totalDia > 0 ? 'cell-with-value' : ''}">
                        ${totalDia > 0 ? formatCurrency(totalDia) : ''}
                     </td>`;
        }
        
    } else {
        // Vista de meses: solo 11 meses (excluyendo primeras 4 semanas)
        for (var i = 0; i < 11; i++) {
            var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
            var mesKey = fechaMes.getFullYear() + '-' + String(fechaMes.getMonth() + 1).padStart(2, '0');
            
            var totalMes = 0;
            if (datosProveedores && datosProveedores.items) {
                datosProveedores.items.forEach(function(item) {
                    var fechaPagoEfectiva = item.FECHA_PAGO_EFECTIVA || item.FECHA_EST_PAGO;
                    if (fechaPagoEfectiva) {
                        var mesPago = fechaPagoEfectiva.substring(0, 7);
                        if (mesPago === mesKey) {
                            var fechaPago = new Date(fechaPagoEfectiva);
                            fechaPago.setHours(0, 0, 0, 0);
                            // Solo incluir si está después de las 4 semanas
                            if (fechaPago >= fecha28Dias) {
                                totalMes += parseFloat(item.VALOR_FOB_DOLAR) || 0;
                            }
                        }
                    }
                });
            }
            
            html += `<td class="currency ${totalMes > 0 ? 'cell-with-value' : ''}">
                        ${totalMes > 0 ? formatCurrency(totalMes) : ''}
                     </td>`;
        }
    }
    
    totalsRow.innerHTML = html;
}

/**
 * Calcula y muestra los resúmenes
 */
function calcularResumenes() {
    var hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    
    // Calcular fecha de fin de 4 semanas (28 días)
    var fecha28Dias = new Date(hoy);
    fecha28Dias.setDate(hoy.getDate() + 28);
    
    // Calcular fecha de fin de 11 meses
    var fecha11Meses = new Date(hoy.getFullYear(), hoy.getMonth() + 11, 31);
    
    var total4Semanas = 0;
    var total11Meses = 0;
    var totalGeneral = 0;
    
    if (datosProveedores && datosProveedores.items) {
        datosProveedores.items.forEach(function(item) {
            var valor = parseFloat(item.VALOR_FOB_DOLAR) || 0;
            totalGeneral += valor;
            
            var fechaPagoEfectiva = item.FECHA_PAGO_EFECTIVA || item.FECHA_EST_PAGO;
            if (fechaPagoEfectiva) {
                var fechaPago = new Date(fechaPagoEfectiva);
                fechaPago.setHours(0, 0, 0, 0);
                
                // Total 4 semanas: incluir si está dentro de los próximos 28 días
                if (fechaPago < fecha28Dias) {
                    total4Semanas += valor;
                }
                // Total 11 meses: incluir si está después de los 28 días y antes del fin de 11 meses
                else if (fechaPago < fecha11Meses) {
                    total11Meses += valor;
                }
            }
        });
    }
    
    document.getElementById('total4semanas').textContent = formatCurrency(total4Semanas);
    document.getElementById('total11meses').textContent = formatCurrency(total11Meses);
    document.getElementById('totalGeneral').textContent = formatCurrency(totalGeneral);
    document.getElementById('summarySection').style.display = 'flex';
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
