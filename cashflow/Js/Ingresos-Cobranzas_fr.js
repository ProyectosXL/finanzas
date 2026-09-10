/**
 * Ingresos - Cobranzas FR JavaScript
 * Con soporte para Resumen (predeterminado) y Deep Dive (aperturado por comprobante)
 * y doble matriz: Real a Cobrar vs Pendientes Proyectados (con cálculo de PPP).
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js — ver la nota del encabezado de
 * Comex-Proveedores_exterior.js. Esta pestaña tenía el mismo criterio propio,
 * con las columnas calculadas en el navegador. Ahora el eje y los importes por
 * columna vienen resueltos de Class/EjeVista.php, sobre horizonte_dias y
 * horizonte_meses.
 */

(function() {
    'use strict';

    let datosCobranzas = null;
    let modoVista = 'resumen'; // 'resumen' o 'deepdive'
    let modoOrigen = 'real';   // 'real' o 'proyectado'

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    function inicializar() {
        console.log('Inicializando Ingresos - Cobranzas FR');

        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');
        var tabReal = document.getElementById('tabRealCobBtn');
        var tabProy = document.getElementById('tabProyCobBtn');
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

        if (tabReal) {
            tabReal.addEventListener('click', function() {
                cambiarOrigen('real');
            });
        }
        if (tabProy) {
            tabProy.addEventListener('click', function() {
                cambiarOrigen('proyectado');
            });
        }

        vistas = crearEjeVistas({
            botones: 'vistasCob',
            periodo: 'periodoCob',
            alCambiar: generarTabla
        });

        // Tipo, COD_CLI y RAZON_SOC fijas por defecto, en Resumen y en Deep
        // Dive: con veintiocho columnas de días a la derecha, sin ellas no se
        // ve de quién es el número que uno está mirando. Es la misma tabla en
        // los dos modos, así que un solo control las cubre.
        crearColumnasFijas({
            tabla: 'tablaCobranzasFR',
            control: 'colFijasCob',
            clave: 'cobranzas_fr',
            porDefecto: [0, 1, 2]
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
        
        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');
        
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

    function cambiarOrigen(origen) {
        if (origen === modoOrigen) return;
        modoOrigen = origen;

        var tabReal = document.getElementById('tabRealCobBtn');
        var tabProy = document.getElementById('tabProyCobBtn');
        var titulo = document.getElementById('tituloMatrizCob');
        var subtitulo = document.getElementById('subtituloMatrizCob');
        var thCobro = document.getElementById('thCobroCob');
        var thImporteNeto = document.getElementById('thImporteNetoCob');

        if (tabReal) tabReal.classList.toggle('active', origen === 'real');
        if (tabProy) tabProy.classList.toggle('active', origen === 'proyectado');

        if (origen === 'proyectado') {
            if (titulo) titulo.innerHTML = 'Cobranzas FR &mdash; <span class="text-warning-emphasis">Pendientes Proyectados</span>';
            if (subtitulo) subtitulo.textContent = 'Facturas pendientes calculadas con Plazo Promedio de Pago (PPP)';
            if (thCobro) thCobro.textContent = 'F. Prob. Cobro';
            if (thImporteNeto) thImporteNeto.textContent = 'Importe Neto Proy.';
        } else {
            if (titulo) titulo.innerHTML = 'Cobranzas FR &mdash; <span class="text-success">Real a Cobrar</span>';
            if (subtitulo) subtitulo.textContent = 'Propuestas de pago confirmadas por fecha de cobro';
            if (thCobro) thCobro.textContent = 'Cobro';
            if (thImporteNeto) thImporteNeto.textContent = 'Importe Neto';
        }

        cargarDatos();
    }

    function cargarDatos() {
        mostrarCargando(true);
        fetch(`Controller/IngresosController.php?action=getCobranzasFR&type=${modoVista}&origen=${modoOrigen}`)
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
            var esProy = (item.TIPO_REGISTRO === 'PROYECCION');
            var trClase = esProy ? 'fila-proyeccion' : '';
            
            html += `<tr class="${trClase}">`;
            
            // Columna Tipo
            if (esProy) {
                var pppInfo = item.PPP ? `PPP: ${item.PPP} días` : '';
                html += `<td class="center"><span class="badge-proyeccion" title="${pppInfo}"><i class="fas fa-clock me-1"></i>PROYECCIÓN</span></td>`;
            } else {
                html += `<td class="center"><span class="badge-real" title="Propuesta"><i class="fas fa-check me-1"></i>REAL</span></td>`;
            }

            html += `<td><strong>${item.COD_CLI || ''}</strong></td>`;
            html += `<td>${item.RAZON_SOC || ''}</td>`;
            
            // Columnas ocultables en resumen
            html += `<td class="center col-detail">${formatDate(item.FECHA)}</td>`;
            html += `<td class="center col-detail">${item.T_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.N_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.Desc || ''}</td>`;
            html += `<td class="center col-detail">${item.Dias || 0}</td>`;
            
            // Columnas siempre visibles
            html += `<td class="currency">${formatCurrency(item.importe_bruto)}</td>`;
            html += `<td class="currency fw-bold">${formatCurrency(item.importe_neto)}</td>`;
            
            var badgeCobroClase = esProy ? 'badge-proyeccion' : 'badge-cobro';
            html += `<td class="center"><span class="${badgeCobroClase}">${formatDate(item.Cobro)}</span></td>`;
            
            // Columnas del eje temporal
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(item, col)) || 0;
                var cellClass = '';
                if (valor != 0) {
                    cellClass = esProy ? 'cell-with-proy' : 'cell-with-value';
                }

                html += '<td class="currency ' + cellClass + '">'
                    + (valor != 0 ? formatCurrency(valor) : '') + '</td>';
            });

            var total = vistas.total(item);

            html += '<td class="currency total-column ' + (esProy ? 'text-warning-emphasis' : '') + '">'
                + (total != 0 ? formatCurrency(total) : '') + '</td>';

            html += '</tr>';
        });
        tableBody.innerHTML = html;
    }

    /**
     * Fila de totales.
     */
    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRowCob');
        var cols = vistas.columnas();
        var visibles = filasFiltradas();

        // Una celda por columna descriptiva en lugar de un colspan escrito en
        // duro. Dos motivos: el colspan se desactualiza cada vez que cambia la
        // cantidad de columnas -y desalinea todo el pie sin que nadie lo note-,
        // y una celda que abarca todo el bloque descriptivo no se puede fijar a
        // la izquierda sin tapar los importes. El rótulo va en la PRIMERA, que
        // es una de las fijas: así queda a la vista aunque se scrollee a lo
        // ancho.
        var descriptivas = ColumnasFijas.descriptivas(
            document.getElementById('tablaCobranzasFR'));

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
        var input = document.getElementById('busquedaCob');
        var term = input ? input.value.toLowerCase() : '';

        if (!term) {
            return datosCobranzas.filas;
        }

        return datosCobranzas.filas.filter(function(item) {
            return (item.COD_CLI || '').toLowerCase().includes(term)
                || (item.RAZON_SOC || '').toLowerCase().includes(term)
                || (item.TIPO_REGISTRO || '').toLowerCase().includes(term)
                || (item.N_COMP || '').toLowerCase().includes(term);
        });
    }

    /**
     * Indicadores de cabecera.
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
