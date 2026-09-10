/**
 * Ingresos - Cobranzas Mayoristas JavaScript
 * Con soporte para Resumen (predeterminado) y Deep Dive (aperturado por comprobante)
 * y proyección a 60 días (o plazo configurado en parámetros).
 */

(function() {
    'use strict';

    let datosCobranzas = null;
    let modoVista = 'resumen'; // 'resumen' o 'deepdive'

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    function inicializar() {
        console.log('Inicializando Ingresos - Cobranzas May');

        var btnResumen = document.getElementById('btnVistaResumenCobMay');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCobMay');
        var btnRefresh = document.getElementById('btnRefreshCobMay');
        var btnExport = document.getElementById('btnExportCobMay');

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

        vistas = crearEjeVistas({
            botones: 'vistasCobMay',
            periodo: 'periodoCobMay',
            alCambiar: generarTabla
        });

        // Mismo default que Cobranzas FR: Tipo, COD_CLI y RAZON_SOC, en
        // Resumen y en Deep Dive. Ver Js/columnas-fijas.js.
        crearColumnasFijas({
            tabla: 'tablaCobranzasMay',
            control: 'colFijasCobMay',
            clave: 'cobranzas_may',
            porDefecto: [0, 1, 2]
        });

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarDatos);
        }
        if (btnExport) {
            btnExport.addEventListener('click', exportarExcel);
        }

        // Buscador rápido
        var inputBusqueda = document.getElementById('busquedaCobMay');
        if (inputBusqueda) {
            inputBusqueda.addEventListener('keyup', function() {
                filtrarTabla();
            });
        }
        
        cargarDatos();
    }
    
    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
    }

    function filtrarTabla() {
        var term = document.getElementById('busquedaCobMay').value.toLowerCase();
        var rows = document.querySelectorAll('#tableBodyCobMay tr');
        
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            if (text.includes(term)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Recalcular totales visibles
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
        
        var btnResumen = document.getElementById('btnVistaResumenCobMay');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCobMay');
        
        if (modo === 'resumen') {
            btnResumen.classList.add('btn-primary', 'active');
            btnResumen.classList.remove('btn-outline-primary', 'btn-outline-secondary');
            btnDeepDive.classList.remove('btn-primary', 'active');
            btnDeepDive.classList.add('btn-outline-secondary');
        } else {
            btnDeepDive.classList.add('btn-primary', 'active');
            btnDeepDive.classList.remove('btn-outline-secondary');
            btnResumen.classList.remove('btn-primary', 'active');
            btnResumen.classList.add('btn-outline-secondary');
        }
        
        cargarDatos();
    }

    function cargarDatos() {
        mostrarCargando(true);
        fetch(`Controller/IngresosController.php?action=getCobranzasMay&type=${modoVista}`)
            .then(response => {
                if (!response.ok) throw new Error('Error HTTP: ' + response.status);
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    datosCobranzas = result.data;

                    vistas.usar(datosCobranzas);

                    generarTabla();
                    calcularResumenes();
                    pintarAvisos();
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

    /**
     * Los avisos del backend: lo que quedó fuera del horizonte o sin fecha.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosCobMay');

        if (!cont) {
            return;
        }

        var avisos = (datosCobranzas && datosCobranzas.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.join(' ') + '</small></div>'
            : '';
    }

    function generarTabla() {
        if (!datosCobranzas || !datosCobranzas.filas) {
            mostrarError('No hay datos para mostrar');
            return;
        }

        generarEncabezados();
        generarFilasDatos();
        generarFilaTotales();
    }

    /**
     * Encabezados de la tabla.
     */
    function generarEncabezados() {
        const headerRowSub = document.getElementById('headerRowSubCobMay');
        const mesActualHeader = document.getElementById('mesActualHeaderCobMay');
        const table = document.getElementById('tablaCobranzasMay');

        table.classList.toggle('modo-resumen', modoVista === 'resumen');

        var cols = vistas.columnas();
        var headerHTML = '';

        cols.forEach(function(col) {
            var esMes = vistas.esMes(col);
            var meta = vistas.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            headerHTML += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + titulo + '"' : '') + '>'
                + vistas.rotulo(col) + '</th>';
        });

        headerHTML += '<th class="total-column">Total</th>';

        mesActualHeader.textContent = datosCobranzas.vistas[vistas.activa()].label;
        mesActualHeader.setAttribute('colspan', String(cols.length + 1));

        headerRowSub.innerHTML = headerHTML;
    }

    function generarFilasDatos() {
        var tableBody = document.getElementById('tableBodyCobMay');
        var cols = vistas.columnas();
        var html = '';

        datosCobranzas.filas.forEach(function(item) {
            html += `<tr class="fila-proyeccion-may">`;
            
            var plazoInfo = item.Dias ? `Plazo: ${item.Dias} días` : '';
            html += `<td class="center"><span class="badge-may" title="${plazoInfo}"><i class="fas fa-clock me-1"></i>PROY</span></td>`;

            html += `<td><strong>${item.COD_CLI || ''}</strong></td>`;
            html += `<td>${item.RAZON_SOC || ''}</td>`;
            
            // Columnas ocultables en resumen
            html += `<td class="center col-detail">${formatDate(item.FECHA)}</td>`;
            html += `<td class="center col-detail">${item.T_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.N_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.Desc || '0%'}</td>`;
            html += `<td class="center col-detail">${item.Dias || 60}</td>`;
            
            // Columnas siempre visibles
            html += `<td class="currency">${formatCurrency(item.importe_bruto)}</td>`;
            html += `<td class="currency fw-bold">${formatCurrency(item.importe_neto)}</td>`;
            
            html += `<td class="center"><span class="badge-cobro-may">${formatDate(item.Cobro)}</span></td>`;
            
            // Columnas del eje temporal
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(item, col)) || 0;
                var cellClass = '';
                if (valor != 0) {
                    cellClass = 'cell-with-value';
                }

                html += '<td class="currency ' + cellClass + '">'
                    + (valor != 0 ? formatCurrency(valor) : '') + '</td>';
            });

            var total = vistas.total(item);

            html += '<td class="currency total-column">'
                + (total != 0 ? formatCurrency(total) : '') + '</td>';

            html += '</tr>';
        });
        tableBody.innerHTML = html;
    }

    /**
     * Fila de totales.
     */
    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRowCobMay');
        var cols = vistas.columnas();
        var visibles = filasFiltradas();

        // Una celda por columna descriptiva en lugar de un colspan en duro.
        // Ver la nota equivalente en Js/Ingresos-Cobranzas_fr.js.
        var descriptivas = ColumnasFijas.descriptivas(
            document.getElementById('tablaCobranzasMay'));

        var html = '<td class="total-label">TOTALES</td>';

        for (var i = 1; i < descriptivas.length; i++) {
            html += '<td></td>';
        }

        cols.forEach(function(col) {
            var total = 0;

            visibles.forEach(function(item) {
                total += Number(vistas.valor(item, col)) || 0;
            });

            html += '<td class="currency ' + (total != 0 ? 'cell-with-value' : '') + '">'
                + (total != 0 ? formatCurrency(total) : '') + '</td>';
        });

        var granTotal = 0;

        visibles.forEach(function(item) {
            granTotal += vistas.total(item);
        });

        html += '<td class="currency total-column">'
            + (granTotal != 0 ? formatCurrency(granTotal) : '') + '</td>';

        totalsRow.innerHTML = html;
    }

    /** Las filas que pasan el buscador */
    function filasFiltradas() {
        var input = document.getElementById('busquedaCobMay');
        var term = input ? input.value.toLowerCase() : '';

        if (!term) {
            return datosCobranzas.filas;
        }

        return datosCobranzas.filas.filter(function(item) {
            return (item.COD_CLI || '').toLowerCase().includes(term)
                || (item.RAZON_SOC || '').toLowerCase().includes(term)
                || (item.N_COMP || '').toLowerCase().includes(term);
        });
    }

    /**
     * Indicadores de cabecera.
     */
    function calcularResumenes() {
        var totales = (datosCobranzas && datosCobranzas.totales) || {};

        document.getElementById('total4semanasCobMay').textContent =
            formatCurrency(totales.total_tramo || 0);
        document.getElementById('total11mesesCobMay').textContent =
            formatCurrency(totales.total_meses || 0);
        document.getElementById('totalGeneralCobMay').textContent =
            formatCurrency(totales.total_horizonte || 0);

        var vs = (datosCobranzas && datosCobranzas.vistas) || {};

        texto('rotulo4semanasCobMay', vs.dias ? vs.dias.periodo : '');
        texto('rotulo11mesesCobMay', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneralCobMay', vs.completo ? vs.completo.periodo : '');

        document.getElementById('summarySectionCobMay').style.display = 'flex';
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
        // Una fila agrupada del Resumen no trae fecha: es distinta en cada
        // comprobante del cliente. Ver EjeVista::armarAgrupado().
        if (dateString === undefined || dateString === null || dateString === '') return '';
        if (dateString === '-' || dateString === 'N/A') return dateString;
        var parts = dateString.split(' ')[0].split('-');
        if (parts.length !== 3) return dateString;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function mostrarCargando(mostrar) {
        document.getElementById('loadingSpinnerCobMay').style.display = mostrar ? 'flex' : 'none';
        document.getElementById('tableWrapperCobMay').style.display = mostrar ? 'none' : 'block';
    }

    function mostrarError(mensaje) {
        mostrarCargando(false);
        alert(mensaje);
    }

    function exportarExcel() {
        var tabla = document.getElementById('tablaCobranzasMay').cloneNode(true);
        var html = tabla.outerHTML;
        var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'Cobranzas_May_' + new Date().toISOString().split('T')[0] + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
})();
