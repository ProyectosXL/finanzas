/**
 * Ingresos - Cobranzas FR JavaScript
 * Con soporte para Resumen (predeterminado) y Deep Dive (aperturado por comprobante)
 */

(function() {
    'use strict';
    
    let datosCobranzas = null;
    let vistaActual = 'semanas'; // 'semanas' o 'meses'
    let modoVista = 'resumen'; // 'resumen' o 'deepdive'

    function inicializar() {
        console.log('Inicializando Ingresos - Cobranzas FR');
        
        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');
        var btnVistaSemanas = document.getElementById('btnVistaSemanasCob');
        var btnVistaMeses = document.getElementById('btnVistaMesesCob');
        var btnRefresh = document.getElementById('btnRefreshCob');
        var btnExport = document.getElementById('btnExportCob');
        
        if (btnResumen) {
            btnResumen.addEventListener('click', function() {
                cambiarModo('resumen');
            });
        }
        if (btnDeepDive) {
            btnDeepDive.addEventListener('click', function() {
                cambiarModo('deepdive');
            });
        }
        
        if (btnVistaSemanas) {
            btnVistaSemanas.classList.add('btn-primary');
            btnVistaSemanas.addEventListener('click', function() {
                cambiarVista('semanas');
            });
        }
        if (btnVistaMeses) {
            btnVistaMeses.addEventListener('click', function() {
                cambiarVista('meses');
            });
        }

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }
        if (btnExport) {
            btnExport.addEventListener('click', exportarExcel);
        }

        // Buscador rápido
        var inputBusqueda = document.getElementById('busquedaCob');
        if (inputBusqueda) {
            inputBusqueda.addEventListener('keyup', function() {
                filtrarTabla();
            });
        }
        
        cargarDatos();
    }
    
    function filtrarTabla() {
        var term = document.getElementById('busquedaCob').value.toLowerCase();
        var rows = document.querySelectorAll('#tableBodyCob tr');
        
        rows.forEach(function(row) {
            var cod = row.cells[0].textContent.toLowerCase();
            var rs = row.cells[1].textContent.toLowerCase();
            if (cod.includes(term) || rs.includes(term)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Recalcular totales visibles si es necesario
        generarFilaTotales();
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    function cambiarModo(modo) {
        if (modo === modoVista) return;
        modoVista = modo;
        
        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');
        
        if (modo === 'resumen') {
            btnResumen.classList.replace('btn-outline-secondary', 'btn-primary');
            btnDeepDive.classList.replace('btn-primary', 'btn-outline-secondary');
        } else {
            btnDeepDive.classList.replace('btn-outline-secondary', 'btn-primary');
            btnResumen.classList.replace('btn-primary', 'btn-outline-secondary');
        }
        
        cargarDatos();
    }

    function cambiarVista(vista) {
        vistaActual = vista;
        var btnSemanas = document.getElementById('btnVistaSemanasCob');
        var btnMeses = document.getElementById('btnVistaMesesCob');
        
        if (vista === 'semanas') {
            btnSemanas.classList.replace('btn-outline-secondary', 'btn-primary');
            btnMeses.classList.replace('btn-primary', 'btn-outline-secondary');
        } else {
            btnMeses.classList.replace('btn-outline-secondary', 'btn-primary');
            btnSemanas.classList.replace('btn-primary', 'btn-outline-secondary');
        }
        
        if (datosCobranzas) {
            generarTabla();
        }
    }

    function cargarDatos() {
        mostrarCargando(true);
        fetch(`Controller/IngresosController.php?action=getCobranzasFR&type=${modoVista}`)
            .then(response => {
                if (!response.ok) throw new Error('Error HTTP: ' + response.status);
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    datosCobranzas = result.data;
                    generarTabla();
                    calcularResumenes();
                    mostrarCargando(false);
                } else {
                    mostrarError('Error al cargar datos: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error de conexión:', error);
                mostrarError('Error de conexión: ' + error.message);
            });
    }

    function generarTabla() {
        if (!datosCobranzas || !datosCobranzas.items) {
            mostrarError('No hay datos para mostrar');
            return;
        }
        
        generarEncabezados();
        generarFilasDatos();
        generarFilaTotales();
    }

    function generarEncabezados() {
        const headerRowSub = document.getElementById('headerRowSubCob');
        const mesActualHeader = document.getElementById('mesActualHeaderCob');
        const table = document.getElementById('tablaCobranzasFR');
        
        // Ajustar th's del thead principal
        const mainHeaderRow = table.querySelector('thead tr:first-child');
        const isResumen = (modoVista === 'resumen');
        
        // Columnas a ocultar: FECHA (idx 2), T_COMP (idx 3), N_COMP (idx 4), DESC (idx 5), DIAS (idx 6)
        // Usaremos CSS para ocultarlas de forma limpia
        table.classList.toggle('modo-resumen', isResumen);
        
        let headerHTML = '';
        var hoy = new Date();
        
        if (vistaActual === 'semanas') {
            mesActualHeader.textContent = 'Próximos 28 Días (4 Semanas)';
            mesActualHeader.setAttribute('colspan', '28');
            for (var i = 0; i < 28; i++) {
                var fecha = new Date(hoy);
                fecha.setDate(hoy.getDate() + i);
                headerHTML += `<th class="day-column">${fecha.getDate()}/${fecha.getMonth() + 1}</th>`;
            }
        } else {
            mesActualHeader.textContent = 'Próximos 11 Meses';
            mesActualHeader.setAttribute('colspan', '11');
            for (var i = 0; i < 11; i++) {
                var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
                var mesAbrev = fechaMes.toLocaleDateString('es-ES', { month: 'short', year: '2-digit' });
                headerHTML += `<th class="month-column">${mesAbrev.charAt(0).toUpperCase() + mesAbrev.slice(1)}</th>`;
            }
        }
        headerRowSub.innerHTML = headerHTML;
    }

    function generarFilasDatos() {
        var tableBody = document.getElementById('tableBodyCob');
        var hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        
        var html = '';
        const isResumen = (modoVista === 'resumen');
        
        datosCobranzas.items.forEach(function(item) {
            html += '<tr>';
            html += `<td>${item.COD_CLI || ''}</td>`;
            html += `<td>${item.RAZON_SOC || ''}</td>`;
            
            // Columnas ocultables en resumen
            html += `<td class="center col-detail">${formatDate(item.FECHA)}</td>`;
            html += `<td class="center col-detail">${item.T_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.N_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.Desc || ''}</td>`;
            html += `<td class="center col-detail">${item.Dias || 0}</td>`;
            
            // Columnas siempre visibles
            html += `<td class="currency">${formatCurrency(item.importe_bruto)}</td>`;
            html += `<td class="currency">${formatCurrency(item.importe_neto)}</td>`;
            html += `<td class="center"><span class="badge-cobro">${formatDate(item.Cobro)}</span></td>`;
            
            var fechaPago = item.Cobro ? new Date(item.Cobro) : null;
            if (fechaPago) fechaPago.setHours(0, 0, 0, 0);
            
            if (vistaActual === 'semanas') {
                for (var i = 0; i < 28; i++) {
                    var fechaDia = new Date(hoy);
                    fechaDia.setDate(hoy.getDate() + i);
                    var esMismoDia = fechaPago && fechaPago.getTime() === fechaDia.getTime();
                    var valor = esMismoDia ? item.importe_neto : 0;
                    html += `<td class="currency ${valor != 0 ? 'cell-with-value' : ''}">${valor != 0 ? formatCurrency(valor) : ''}</td>`;
                }
            } else {
                var mesPago = item.Cobro ? item.Cobro.substring(0, 7) : '';
                for (var i = 0; i < 11; i++) {
                    var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
                    var mesKey = fechaMes.getFullYear() + '-' + String(fechaMes.getMonth() + 1).padStart(2, '0');
                    var valorMes = (mesPago === mesKey) ? item.importe_neto : 0;
                    html += `<td class="currency ${valorMes != 0 ? 'cell-with-value' : ''}">${valorMes != 0 ? formatCurrency(valorMes) : ''}</td>`;
                }
            }
            html += '</tr>';
        });
        tableBody.innerHTML = html;
    }

    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRowCob');
        var hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        
        const isResumen = (modoVista === 'resumen');
        const colspan = isResumen ? 5 : 10;
        
        // Obtener solo filas visibles para el total
        var rows = Array.from(document.querySelectorAll('#tableBodyCob tr')).filter(r => r.style.display !== 'none');
        
        var html = `<td colspan="${colspan}" class="total-label">TOTALES</td>`;
        
        if (vistaActual === 'semanas') {
            for (var i = 0; i < 28; i++) {
                var fechaDia = new Date(hoy);
                fechaDia.setDate(hoy.getDate() + i);
                var totalDia = 0;
                
                rows.forEach(function(row, idx) {
                    var item = datosCobranzas.items[idx]; // Cuidado, esto asume que el orden es el mismo
                    // Mejor volver a calcular basado en los datos de las filas o pasar el item
                });
                
                // Opción más segura: Iterar sobre los datos originales filtrando por el buscador
                var term = document.getElementById('busquedaCob').value.toLowerCase();
                datosCobranzas.items.forEach(function(item) {
                    var cod = (item.COD_CLI || '').toLowerCase();
                    var rs = (item.RAZON_SOC || '').toLowerCase();
                    if (cod.includes(term) || rs.includes(term)) {
                        var fp = new Date(item.Cobro);
                        fp.setHours(0, 0, 0, 0);
                        if (fp.getTime() === fechaDia.getTime()) totalDia += parseFloat(item.importe_neto) || 0;
                    }
                });
                html += `<td class="currency ${totalDia != 0 ? 'cell-with-value' : ''}">${totalDia != 0 ? formatCurrency(totalDia) : ''}</td>`;
            }
        } else {
            var term = document.getElementById('busquedaCob').value.toLowerCase();
            for (var i = 0; i < 11; i++) {
                var fechaMes = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1);
                var mesKey = fechaMes.getFullYear() + '-' + String(fechaMes.getMonth() + 1).padStart(2, '0');
                var totalMes = 0;
                datosCobranzas.items.forEach(function(item) {
                    var cod = (item.COD_CLI || '').toLowerCase();
                    var rs = (item.RAZON_SOC || '').toLowerCase();
                    if (cod.includes(term) || rs.includes(term)) {
                        var mp = item.Cobro.substring(0, 7);
                        if (mp === mesKey) totalMes += parseFloat(item.importe_neto) || 0;
                    }
                });
                html += `<td class="currency ${totalMes != 0 ? 'cell-with-value' : ''}">${totalMes != 0 ? formatCurrency(totalMes) : ''}</td>`;
            }
        }
        totalsRow.innerHTML = html;
    }

    function calcularResumenes() {
        var hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        var fecha28Dias = new Date(hoy);
        fecha28Dias.setDate(hoy.getDate() + 28);
        var fecha11Meses = new Date(hoy.getFullYear(), hoy.getMonth() + 11, 31);
        
        var total4Semanas = 0;
        var total11Meses = 0;
        var totalGeneral = 0;
        
        datosCobranzas.items.forEach(function(item) {
            var valor = parseFloat(item.importe_neto) || 0;
            totalGeneral += valor;
            var fp = new Date(item.Cobro);
            fp.setHours(0, 0, 0, 0);
            if (fp < fecha28Dias) total4Semanas += valor;
            else if (fp < fecha11Meses) total11Meses += valor;
        });
        
        document.getElementById('total4semanasCob').textContent = formatCurrency(total4Semanas);
        document.getElementById('total11mesesCob').textContent = formatCurrency(total11Meses);
        document.getElementById('totalGeneralCob').textContent = formatCurrency(totalGeneral);
        document.getElementById('summarySectionCob').style.display = 'flex';
    }

    function formatCurrency(value) {
        var num = parseFloat(value) || 0;
        var formatted = num.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (num < 0) {
            return `<span class="text-danger">$ ${formatted}</span>`;
        }
        return '$ ' + formatted;
    }

    function formatDate(dateString) {
        if (!dateString || dateString === '-' || dateString === 'N/A') return dateString;
        var parts = dateString.split(' ')[0].split('-');
        if (parts.length !== 3) return dateString;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function mostrarCargando(mostrar) {
        document.getElementById('loadingSpinnerCob').style.display = mostrar ? 'flex' : 'none';
        document.getElementById('tableWrapperCob').style.display = mostrar ? 'none' : 'block';
    }

    function mostrarError(mensaje) {
        mostrarCargando(false);
        alert(mensaje);
    }

    function exportarExcel() {
        var tabla = document.getElementById('tablaCobranzasFR').cloneNode(true);
        var html = tabla.outerHTML;
        var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'Cobranzas_FR_' + new Date().toISOString().split('T')[0] + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
})();
