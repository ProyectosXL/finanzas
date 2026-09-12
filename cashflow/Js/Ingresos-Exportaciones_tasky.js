/**
 * Ingresos - Exportaciones Tasky JavaScript
 *
 * Una fila por factura pendiente en dólares a Tasky, proyectada a su fecha de
 * cobro estimada y valuada al dólar de HOY. El importe que va a la grilla y a
 * los indicadores es el de hoy; los dólares y la referencia de facturación
 * son columnas de la fila. Sin cotización, la columna en pesos queda vacía y
 * el backend avisa: acá no se calcula nada, sólo se dibuja lo que llega.
 */

(function() {
    'use strict';

    let datos = null;

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    let vistas = null;

    function inicializar() {
        console.log('Inicializando Ingresos - Exportaciones Tasky');

        vistas = crearEjeVistas({
            botones: 'vistasExpTasky',
            periodo: 'periodoExpTasky',
            alCambiar: generarTabla
        });

        // N_COMP y RAZON_SOCI fijas por defecto: son las que dicen de qué
        // factura es cada número al scrollear. Ver Js/columnas-fijas.js.
        crearColumnasFijas({
            tabla: 'tablaExportacionesTasky',
            control: 'colFijasExpTasky',
            clave: 'exportaciones_tasky',
            porDefecto: [1, 3]
        });

        conectar('btnRefreshExpTasky', 'click', cargarDatos);
        conectar('btnExportExpTasky', 'click', exportarExcel);
        conectar('busquedaExpTasky', 'keyup', filtrarTabla);

        cargarDatos();
    }

    function conectar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener(evento, fn);
        }
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    function cargarDatos() {
        mostrarCargando(true);

        fetch('Controller/IngresosController.php?action=getExportacionesTasky')
            .then(function(response) {
                if (!response.ok) throw new Error('Error HTTP: ' + response.status);
                return response.json();
            })
            .then(function(result) {
                if (result.success) {
                    datos = result.data;

                    vistas.usar(datos);

                    generarTabla();
                    calcularResumenes();
                    pintarAvisos();
                    mostrarCargando(false);
                } else {
                    mostrarError('Error al cargar datos: ' + result.message);
                }
            })
            .catch(function(error) {
                console.error('Error de conexión:', error);
                mostrarError('Error de conexión: ' + error.message);
            });
    }

    /**
     * Los avisos del backend: sin cotización, facturas vencidas, y lo que
     * quedó fuera del horizonte o sin fecha. Son avisos sobre los DATOS, así
     * que se pintan arriba de la tabla y no como notificación.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosExpTasky');

        if (!cont) {
            return;
        }

        var avisos = (datos && datos.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.map(escaparAttr).join(' ') + '</small></div>'
            : '';
    }

    function generarTabla() {
        if (!datos || !datos.filas) {
            mostrarError('No hay datos para mostrar');
            return;
        }

        generarEncabezados();
        generarFilasDatos();
        generarFilaTotales();
    }

    function generarEncabezados() {
        var headerRowSub = document.getElementById('headerRowSubExpTasky');
        var ejeHeader = document.getElementById('ejeHeaderExpTasky');
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

        ejeHeader.textContent = datos.vistas[vistas.activa()].label;
        ejeHeader.setAttribute('colspan', String(cols.length + 1));

        headerRowSub.innerHTML = headerHTML;
    }

    function generarFilasDatos() {
        var tableBody = document.getElementById('tableBodyExpTasky');
        var cols = vistas.columnas();
        var html = '';

        if (!datos.filas.length) {
            var descriptivas = ColumnasFijas.descriptivas(
                document.getElementById('tablaExportacionesTasky'));

            tableBody.innerHTML = '<tr><td colspan="' + (descriptivas.length + cols.length + 1)
                + '" class="text-center text-muted py-4">No hay facturas pendientes a Tasky.</td></tr>';
            return;
        }

        datos.filas.forEach(function(item) {
            var vencida = !!item.VENCIDA;

            html += '<tr class="fila-exportacion' + (vencida ? ' fila-vencida' : '') + '">';

            html += '<td class="center">' + formatDate(item.FECHA_EMIS) + '</td>';
            html += '<td><strong>' + escaparAttr(item.N_COMP) + '</strong></td>';
            html += '<td class="center">' + escaparAttr(item.COD_CLIENT) + '</td>';

            // Recortado con puntos suspensivos (.col-texto) para que la fila
            // sea una sola línea; el nombre completo va en el title.
            var razon = item.RAZON_SOCI || '';

            html += '<td class="col-texto" title="' + escaparAttr(razon) + '">'
                + escaparAttr(razon) + '</td>';

            html += '<td class="currency fw-bold">' + formatUsd(item.IMPORTE_USD) + '</td>';

            // Referencia histórica: cómo se facturó. Atenuada a propósito.
            html += '<td class="currency col-referencia">' + formatCotiz(item.COTIZ_FACT) + '</td>';
            html += '<td class="currency col-referencia">' + formatCurrency(item.IMPORTE_PESOS_FACT) + '</td>';

            // Valuación de hoy: la misma cotización para todas. Sin
            // cotización va un guión, no un cero.
            html += '<td class="currency">' + formatCotiz(item.COTIZ_HOY) + '</td>';
            html += '<td class="currency fw-bold">' + formatCurrency(item.IMPORTE_PESOS_HOY) + '</td>';

            html += '<td class="center">' + badgeCobro(item) + '</td>';

            // Columnas del eje temporal
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
     * La fecha de cobro estimada. Una factura vencida se marca y dice cuál
     * era su fecha: está ubicada en hoy por ser el primer día del eje, no
     * porque se estime cobrarla hoy.
     */
    function badgeCobro(item) {
        if (!item.Cobro) {
            return '<span class="text-muted" title="Sin fecha de emisión: no se puede estimar el cobro">&mdash;</span>';
        }

        if (item.VENCIDA) {
            return '<span class="badge-vencida-exp" title="Fecha de cobro estimada original: '
                + formatDate(item.COBRO_ORIGINAL) + '. Vencida sin cobrar: se ubica en el primer día del eje">'
                + '<i class="fas fa-triangle-exclamation me-1"></i>Vencida ' + formatDate(item.COBRO_ORIGINAL)
                + '</span>';
        }

        return '<span class="badge-cobro-exp" title="Emisión + ' + escaparAttr(item.DIAS) + ' días">'
            + formatDate(item.Cobro) + '</span>';
    }

    /**
     * Fila de totales: una celda por columna descriptiva. El rótulo va en
     * N_COMP, que es la primera columna fija por defecto, así queda a la vista
     * al scrollear. Ver la nota de columnas fijas en README-cashflow.md.
     */
    function generarFilaTotales() {
        var totalsRow = document.getElementById('totalsRowExpTasky');
        var cols = vistas.columnas();
        var visibles = filasFiltradas();

        var descriptivas = ColumnasFijas.descriptivas(
            document.getElementById('tablaExportacionesTasky'));

        var totalUsd = 0;
        var totalPesosHoy = 0;

        visibles.forEach(function(item) {
            totalUsd += Number(item.IMPORTE_USD) || 0;
            totalPesosHoy += Number(item.IMPORTE_PESOS_HOY) || 0;
        });

        // Qué celda lleva cada total se resuelve por el NOMBRE de la columna
        // en el encabezado, no por índice: un índice escrito en duro se
        // desactualiza en silencio en cuanto alguien agrega una columna.
        var porNombre = {
            'N_COMP': '<td class="total-label">TOTALES</td>',
            'Importe USD': '<td class="currency">' + formatUsd(totalUsd) + '</td>',
            'Importe pesos hoy': '<td class="currency">'
                + (datos.cotizacion_hoy ? formatCurrency(totalPesosHoy) : '<span class="text-muted">&mdash;</span>')
                + '</td>'
        };

        var html = '';

        descriptivas.forEach(function(col) {
            html += porNombre[col.nombre] || '<td></td>';
        });

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

    function filtrarTabla() {
        var term = document.getElementById('busquedaExpTasky').value.toLowerCase();
        var rows = document.querySelectorAll('#tableBodyExpTasky tr');

        rows.forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });

        generarFilaTotales();
    }

    /** Las filas que pasan el buscador */
    function filasFiltradas() {
        var input = document.getElementById('busquedaExpTasky');
        var term = input ? input.value.toLowerCase() : '';

        if (!term) {
            return datos.filas;
        }

        return datos.filas.filter(function(item) {
            return (item.N_COMP || '').toLowerCase().includes(term)
                || (item.RAZON_SOCI || '').toLowerCase().includes(term)
                || (item.COD_CLIENT || '').toLowerCase().includes(term);
        });
    }

    /**
     * Indicadores de cabecera: el total en dólares y la cotización son un
     * hecho de hoy y no varían con la vista; los tres de pesos miden
     * exactamente las columnas de cada vista, como en todo el módulo.
     */
    function calcularResumenes() {
        var totales = (datos && datos.totales) || {};
        var filas = (datos && datos.filas) || [];
        var totalUsd = 0;
        var vencidas = 0;

        filas.forEach(function(item) {
            totalUsd += Number(item.IMPORTE_USD) || 0;

            if (item.VENCIDA) {
                vencidas++;
            }
        });

        texto('totalUsdExpTasky', formatUsd(totalUsd));
        texto('rotuloUsdExpTasky', filas.length + ' factura' + (filas.length === 1 ? '' : 's')
            + (vencidas ? ', ' + vencidas + ' vencida' + (vencidas === 1 ? '' : 's') : ''));

        var cotiz = datos.cotizacion_hoy;
        var elCotiz = document.getElementById('cotizHoyExpTasky');

        if (elCotiz) {
            elCotiz.innerHTML = cotiz ? formatCurrency(cotiz) : '<span class="text-danger">Sin cotización</span>';
        }

        document.getElementById('total4semanasExpTasky').innerHTML =
            formatCurrency(totales.total_tramo || 0);
        document.getElementById('total11mesesExpTasky').innerHTML =
            formatCurrency(totales.total_meses || 0);
        document.getElementById('totalGeneralExpTasky').innerHTML =
            formatCurrency(totales.total_horizonte || 0);

        var vs = (datos && datos.vistas) || {};

        texto('rotulo4semanasExpTasky', vs.dias ? vs.dias.periodo : '');
        texto('rotulo11mesesExpTasky', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneralExpTasky', vs.completo ? vs.completo.periodo : '');

        texto('subtituloExpTasky', 'Cobro estimado a emisión + ' + (datos.dias_cobro || '')
            + ' días, valuadas al dólar de hoy');

        document.getElementById('summarySectionExpTasky').style.display = 'flex';
    }

    /* ---- Formatos ---------------------------------------------------- */

    /** null o undefined es "no hay dato" y se muestra como guión, no como cero */
    function formatCurrency(value) {
        if (value === null || value === undefined || value === '') {
            return '<span class="text-muted">&mdash;</span>';
        }

        var num = parseFloat(value) || 0;
        var formatted = num.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        if (num < 0) {
            return '<span class="text-danger">$ ' + formatted + '</span>';
        }

        return '$ ' + formatted;
    }

    function formatUsd(value) {
        var num = parseFloat(value) || 0;
        var formatted = num.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        if (num < 0) {
            return '<span class="text-danger">USD ' + formatted + '</span>';
        }

        return 'USD ' + formatted;
    }

    function formatCotiz(value) {
        if (value === null || value === undefined || value === '' || !(parseFloat(value) > 0)) {
            return '<span class="text-muted">&mdash;</span>';
        }

        return parseFloat(value).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }

    function escaparAttr(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDate(dateString) {
        if (dateString === undefined || dateString === null || dateString === '') return '';
        var parts = String(dateString).split(' ')[0].split('-');
        if (parts.length !== 3) return dateString;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function mostrarCargando(mostrar) {
        document.getElementById('loadingSpinnerExpTasky').style.display = mostrar ? 'flex' : 'none';
        document.getElementById('tableWrapperExpTasky').style.display = mostrar ? 'none' : 'block';
    }

    function mostrarError(mensaje) {
        mostrarCargando(false);

        if (window.Notificacion) {
            Notificacion.error(mensaje);
        } else {
            alert(mensaje);
        }
    }

    /** Exporta lo que se ve. Ver Js/tabla-export.js. */
    function exportarExcel() {
        exportarTabla('tablaExportacionesTasky', 'Exportaciones_Tasky');
    }
})();
