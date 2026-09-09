/**
 * Ingresos - Cobranzas FR JavaScript
 * Con soporte para Resumen (predeterminado) y Deep Dive (aperturado por comprobante)
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js — ver la nota del encabezado de
 * Comex-Proveedores_exterior.js. Esta pestaña tenía el mismo criterio propio,
 * con las columnas calculadas en el navegador. Ahora el eje y los importes por
 * columna vienen resueltos de Class/EjeVista.php, sobre horizonte_dias y
 * horizonte_meses.
 *
 * El modo Resumen / Deep Dive es OTRA cosa y no se toca: define el GRANO de las
 * filas (por cliente o por comprobante), no el período que se está mirando. Son
 * dos ejes independientes y se combinan.
 */

(function() {
    'use strict';

    let datosCobranzas = null;
    let modoVista = 'resumen'; // 'resumen' o 'deepdive'

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    function inicializar() {
        console.log('Inicializando Ingresos - Cobranzas FR');

        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');
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

        vistas = crearEjeVistas({
            botones: 'vistasCob',
            periodo: 'periodoCob',
            alCambiar: generarTabla
        });

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
    
    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
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

                    // El controlador de vistas se entera del eje nuevo antes de
                    // que se dibuje la tabla.
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
     * Antes se descartaba en silencio.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosCob');

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
     *
     * Las columnas del eje salen del payload; acá no se calcula ninguna fecha.
     * El modo Resumen / Deep Dive sigue manejándose con la clase del <table>,
     * porque es otra dimensión: cambia el grano de las filas, no el período.
     */
    function generarEncabezados() {
        const headerRowSub = document.getElementById('headerRowSubCob');
        const mesActualHeader = document.getElementById('mesActualHeaderCob');
        const table = document.getElementById('tablaCobranzasFR');

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
        var tableBody = document.getElementById('tableBodyCob');
        var cols = vistas.columnas();
        var html = '';

        datosCobranzas.filas.forEach(function(item) {
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
            
            // Los importes por columna ya vienen resueltos: la regla de "día O
            // mes, nunca las dos" la aplicó el backend, una sola vez.
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(item, col)) || 0;

                html += '<td class="currency ' + (valor != 0 ? 'cell-with-value' : '') + '">'
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
     *
     * Es la única pestaña cuyos totales NO salen directo del payload: acá el
     * pie tiene que respetar el buscador, así que se suman las filas que pasan
     * el filtro. Pero se suman los importes YA AGRUPADOS por el backend, no
     * reinterpretando fechas: la regla de "día O mes" sigue estando en un solo
     * lugar y lo único que se hace acá es filtrar.
     */
    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRowCob');
        var colspan = (modoVista === 'resumen') ? 5 : 10;
        var cols = vistas.columnas();
        var visibles = filasFiltradas();

        var html = `<td colspan="${colspan}" class="total-label">TOTALES</td>`;

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

    /** Las filas que pasan el buscador, con el mismo criterio que filtrarTabla() */
    function filasFiltradas() {
        var input = document.getElementById('busquedaCob');
        var term = input ? input.value.toLowerCase() : '';

        if (!term) {
            return datosCobranzas.filas;
        }

        return datosCobranzas.filas.filter(function(item) {
            return (item.COD_CLI || '').toLowerCase().includes(term)
                || (item.RAZON_SOC || '').toLowerCase().includes(term);
        });
    }

    /**
     * Indicadores de cabecera.
     *
     * Los tres miden los tres períodos de las vistas, así que cada tarjeta se
     * corresponde con un botón. Antes se recalculaban con su propia ventana de
     * 28 días y 11 meses, distinta de la del encabezado y la del backend.
     */
    function calcularResumenes() {
        var totales = (datosCobranzas && datosCobranzas.totales) || {};

        document.getElementById('total4semanasCob').textContent =
            formatCurrency(totales.total_tramo || 0);
        document.getElementById('total11mesesCob').textContent =
            formatCurrency(totales.total_meses || 0);
        document.getElementById('totalGeneralCob').textContent =
            formatCurrency(totales.total_horizonte || 0);

        var vs = (datosCobranzas && datosCobranzas.vistas) || {};

        texto('rotulo4semanasCob', vs.dias ? vs.dias.periodo : '');
        texto('rotulo11mesesCob', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneralCob', vs.completo ? vs.completo.periodo : '');

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
