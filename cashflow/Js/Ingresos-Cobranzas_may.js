/**
 * Ingresos - Cobranzas Mayoristas JavaScript
 * Con soporte para Resumen (predeterminado) y Detalle Facturas (aperturado por
 * comprobante) y proyección a 60 días (o plazo configurado en parámetros).
 *
 * "Detalle Facturas" es el nombre de cara al usuario; el identificador interno
 * sigue siendo `deepdive`, igual que en Cobranzas FR.
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

        // Mismo default que Cobranzas FR: COD_CLI y RAZON_SOC, en Resumen y en
        // Detalle Facturas. Ver Js/columnas-fijas.js.
        // La clave cambió con la columna Tipo, por el mismo motivo que en
        // Cobranzas FR: los índices guardados se corrieron un lugar.
        crearColumnasFijas({
            tabla: 'tablaCobranzasMay',
            control: 'colFijasCobMay',
            clave: 'cobranzas_may.sin_tipo',
            porDefecto: [0, 1]
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

        conectarFiltroEmision();

        cargarDatos();
    }

    /* ================================================================
       FILTRO POR FECHA DE EMISIÓN

       Es SERVER-SIDE, igual que en Cobranzas FR: los dos extremos se mandan
       como parámetros y el controller filtra los items antes de EjeVista.
       Filtrar escondiendo filas dejaría las columnas del eje, el pie de
       totales y las tarjetas describiendo el total sin filtrar.
       ================================================================ */

    function filtroEmision() {
        var desde = document.getElementById('fechaDesdeCobMay');
        var hasta = document.getElementById('fechaHastaCobMay');

        return {
            desde: desde && desde.value ? desde.value : '',
            hasta: hasta && hasta.value ? hasta.value : ''
        };
    }

    function conectarFiltroEmision() {
        var desde = document.getElementById('fechaDesdeCobMay');
        var hasta = document.getElementById('fechaHastaCobMay');
        var limpiar = document.getElementById('btnLimpiarFechasCobMay');

        [desde, hasta].forEach(function(inp) {
            if (inp) {
                inp.addEventListener('change', function() {
                    acotarExtremos();
                    cargarDatos();
                });
            }
        });

        if (limpiar) {
            limpiar.addEventListener('click', function() {
                if (desde) desde.value = '';
                if (hasta) hasta.value = '';

                acotarExtremos();
                cargarDatos();
            });
        }
    }

    /**
     * Cada extremo acota al otro, y el botón de limpiar sólo está activo si hay
     * algo que limpiar. El `max` y el `min` son una comodidad: el rango lo
     * valida de nuevo el servidor.
     */
    function acotarExtremos() {
        var desde = document.getElementById('fechaDesdeCobMay');
        var hasta = document.getElementById('fechaHastaCobMay');
        var limpiar = document.getElementById('btnLimpiarFechasCobMay');
        var f = filtroEmision();

        if (desde) desde.max = f.hasta;
        if (hasta) hasta.min = f.desde;
        if (limpiar) limpiar.disabled = !f.desde && !f.hasta;
    }

    /** El filtro aplicado, al lado del rótulo del período */
    function pintarFiltroPeriodo() {
        var el = document.getElementById('filtroPeriodoCobMay');

        if (!el) {
            return;
        }

        var r = (datosCobranzas && datosCobranzas.filtro_emision) || {};

        if (!r.desde && !r.hasta) {
            el.innerHTML = '';
            return;
        }

        var rango = r.desde && r.hasta
            ? 'emitidas del ' + formatDate(r.desde) + ' al ' + formatDate(r.hasta)
            : (r.desde
                ? 'emitidas desde el ' + formatDate(r.desde)
                : 'emitidas hasta el ' + formatDate(r.hasta));

        el.innerHTML = ' <span class="badge bg-secondary-subtle text-secondary-emphasis">'
            + '<i class="fas fa-filter me-1"></i>Filtro: ' + rango + '</span>';
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

    /**
     * Resumen o Detalle Facturas.
     *
     * Son sub-solapas anidadas (`nav nav-tabs`) y no un `btn-group`, así que
     * el estado activo es la clase `active` del `nav-link`. `modoVista` y el
     * `type=deepdive` del controller siguen igual.
     */
    function cambiarModo(modo) {
        if (modo === modoVista) return;
        modoVista = modo;

        var btnResumen = document.getElementById('btnVistaResumenCobMay');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCobMay');

        if (btnResumen) btnResumen.classList.toggle('active', modo === 'resumen');
        if (btnDeepDive) btnDeepDive.classList.toggle('active', modo === 'deepdive');

        cargarDatos();
    }

    function cargarDatos() {
        mostrarCargando(true);

        var f = filtroEmision();
        var url = 'Controller/IngresosController.php?action=getCobranzasMay'
            + '&type=' + encodeURIComponent(modoVista)
            + '&desde=' + encodeURIComponent(f.desde)
            + '&hasta=' + encodeURIComponent(f.hasta);

        fetch(url)
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
                    pintarFiltroPeriodo();
                    acotarExtremos();
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
            // Una factura vencida se distingue en toda la fila: su importe está
            // en la columna de hoy por ser el primer día del eje, no porque se
            // estime cobrarla hoy. Ver Ingresos::ubicarCobroVencido().
            html += '<tr class="fila-proyeccion-may' + (item.VENCIDA ? ' fila-vencida' : '') + '">';

            // El badge PROY se fue con la columna Tipo: todas las filas de
            // esta pestaña son proyección, así que decía lo mismo en todas. El
            // plazo con el que se proyectó queda en el title de COD_CLI, que
            // es lo único que permitía auditar la fecha de cobro.
            html += '<td title="' + escaparAttr(tituloPlazo(item)) + '">'
                + '<strong>' + escaparAttr(item.COD_CLI || '') + '</strong>'
                + marcaManual(item) + marcaVencida(item) + '</td>';

            // Recortado con puntos suspensivos (.col-texto) para que la fila
            // sea una sola línea; el nombre completo va en el title.
            var razon = item.RAZON_SOC || '';

            html += '<td class="col-texto" title="' + escaparAttr(razon) + '">'
                + escaparAttr(razon) + '</td>';
            
            // Columnas ocultables en resumen
            html += `<td class="center col-detail">${formatDate(item.FECHA)}</td>`;
            html += `<td class="center col-detail">${item.T_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.N_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.Desc || '0%'}</td>`;
            html += `<td class="center col-detail">${item.Dias || 60}</td>`;
            
            // Columnas siempre visibles
            html += `<td class="currency">${formatCurrency(item.importe_bruto)}</td>`;
            html += `<td class="currency fw-bold">${formatCurrency(item.importe_neto)}</td>`;
            
            // data-orden con la fecha cruda: el texto del badge de una vencida
            // es "Vencida 03/09/2026" y no se puede interpretar como fecha.
            // Ver Js/tabla-orden.js.
            html += celdaCobro(item);
            
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

        conectarEdicionFecha();
    }

    /**
     * De dónde sale la fecha de cobro, en el `title` de COD_CLI.
     *
     * Con fecha manual, el plazo del parámetro deja de describir la fila: lo
     * que importa es que alguien la cargó. Sin ella, lo que hay que poder
     * auditar es a cuántos días se proyectó.
     */
    function tituloPlazo(item) {
        if (item.FECHA_MANUAL) {
            return 'Fecha de cobro cargada a mano: no sale del plazo de '
                + (item.PLAZO || 60) + ' días. Mayoristas no tiene escala de descuento, '
                + 'así que el importe no cambia.';
        }

        return item.Dias
            ? 'Proyección a ' + item.Dias + ' días de la emisión.'
            : 'Proyección.';
    }

    /* ================================================================
       FACTURAS VENCIDAS

       Mismo criterio que Exportaciones Tasky y Cobranzas FR: una factura cuya
       fecha probable de cobro ya pasó no se descarta, se ubica en el primer
       día del eje y se marca con su fecha original a la vista. Ver
       Ingresos::ubicarCobroVencido().
       ================================================================ */

    /* ================================================================
       FECHA DE COBRO MANUAL

       Mismo circuito que Cobranzas FR, MISMA TABLA y mismos endpoints: la
       clave es el comprobante, y un comprobante es de franquicias o de
       mayoristas, nunca de los dos. Ver el encabezado de
       sql/cashflow_cobranzas_fecha_manual.sql.

       LA DIFERENCIA CON FR ESTÁ EN EL IMPORTE, y conviene tenerla presente
       antes de copiar cualquier otra cosa de aquella pestaña: allá la fecha
       recalcula los días, y con los días cambia el tramo de la escala de
       descuento y el importe neto. Acá NO hay escala de descuento —el neto es
       el bruto— así que lo único que cambia es en qué columna del eje cae la
       plata.

       Es editable SÓLO en Detalle Facturas, y no en Resumen: en Resumen la
       fila es un cliente y no un comprobante, así que no hay a qué comprobante
       atarle la fecha. Ahí se muestra un indicador de que alguna de sus
       facturas la tiene.
       ================================================================ */

    /** Si esta vista permite editar la fecha de cobro */
    function editable() {
        return modoVista === 'deepdive';
    }

    /** El indicador del Resumen: este cliente tiene alguna fecha cargada a mano */
    function marcaManual(item) {
        if (editable() || !item.FECHA_MANUAL) {
            return '';
        }

        return ' <i class="fas fa-hand-pointer text-primary cob-marca-manual" '
            + 'title="Alguna factura de este cliente tiene la fecha de cobro cargada a mano, '
            + 'así que no sale del plazo de vencimiento. El detalle está en Detalle '
            + 'Facturas."></i>';
    }

    /**
     * La celda de fecha de cobro: un input en Detalle Facturas, un badge en
     * Resumen.
     *
     * Una vencida dice cuál era su fecha, que es lo único que explica por qué
     * su importe está en la columna de hoy. Sin COBRO_ORIGINAL —el Resumen,
     * donde la fila es un cliente y las fechas difieren— el badge no tendría
     * fecha que mostrar y no agregaría nada sobre el color de la fila.
     *
     * data-orden lleva la fecha cruda: el texto de un badge de vencida es
     * "Vencida 03/09/2026" y no se puede interpretar como fecha. Ver
     * Js/tabla-orden.js.
     */
    function celdaCobro(item) {
        var orden = ' data-orden="' + escaparAttr(item.Cobro || '') + '"';

        if (!editable()) {
            if (item.VENCIDA && item.COBRO_ORIGINAL) {
                return '<td class="center"' + orden + '>'
                    + '<span class="badge-vencida-exp" title="Fecha de cobro '
                    + (item.FECHA_MANUAL ? 'pactada' : 'probable') + ' original: '
                    + formatDate(item.COBRO_ORIGINAL) + '. Vencida sin cobrar: '
                    + (item.FECHA_MANUAL
                        ? 'se respeta tal cual porque la cargó una persona'
                        : 'se ubica en el primer día del eje') + '">'
                    + '<i class="fas fa-triangle-exclamation me-1"></i>Vencida '
                    + formatDate(item.COBRO_ORIGINAL) + '</span></td>';
            }

            return '<td class="center"' + orden + '>'
                + '<span class="badge-cobro-may">' + formatDate(item.Cobro) + '</span></td>';
        }

        var manual = !!item.FECHA_MANUAL;
        var titulo = manual
            ? 'Fecha cargada a mano. El importe no cambia: mayoristas no tiene escala de '
                + 'descuento.'
            : 'Calculada como fecha de emisión + ' + (item.PLAZO || 60) + ' días. Se puede pisar.';

        if (item.VENCIDA) {
            titulo = 'Vencida: la fecha ' + (manual ? 'pactada' : 'probable') + ' era el '
                + formatDate(item.COBRO_ORIGINAL) + ' y ya pasó. '
                + (manual
                    ? 'Se respeta tal cual porque la cargó una persona.'
                    : 'El importe se muestra en el primer día del eje.');
        }

        // El `min` en hoy es una comodidad del navegador, no una garantía: el
        // endpoint valida la fecha de nuevo. Ver IngresosController.
        return '<td class="center cob-celda-cobro' + (manual ? ' cob-fecha-manual' : '') + '"'
            + orden + '>'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +     '<input type="date" class="form-control form-control-sm cob-input-fecha" '
            +         'value="' + (item.Cobro || '') + '" min="' + hoyISO() + '" '
            +         'title="' + escaparAttr(titulo) + '" '
            +         'data-tcomp="' + escaparAttr(item.T_COMP) + '" '
            +         'data-ncomp="' + escaparAttr(item.N_COMP) + '" '
            +         'data-cod="' + escaparAttr(item.COD_CLI) + '">'
            +     (manual
                    ? '<button class="btn btn-outline-secondary cob-btn-volver" type="button" '
                        + 'title="Volver a la fecha calculada con el plazo de vencimiento">'
                        + '<i class="fas fa-rotate-left"></i></button>'
                    : '')
            + '</div>'
            + '</td>';
    }

    function conectarEdicionFecha() {
        if (!editable()) {
            return;
        }

        document.querySelectorAll('#tableBodyCobMay .cob-input-fecha').forEach(function(inp) {
            inp.addEventListener('change', function() {
                if (!inp.value) {
                    return;
                }

                pedirFecha('saveFechaCobroManual', {
                    cod_cliente: inp.getAttribute('data-cod'),
                    t_comp: inp.getAttribute('data-tcomp'),
                    n_comp: inp.getAttribute('data-ncomp'),
                    fecha_cobro: inp.value
                }, 'Fecha de cobro guardada.');
            });
        });

        document.querySelectorAll('#tableBodyCobMay .cob-btn-volver').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var inp = btn.closest('.input-group').querySelector('.cob-input-fecha');

                pedirFecha('deleteFechaCobroManual', {
                    t_comp: inp.getAttribute('data-tcomp'),
                    n_comp: inp.getAttribute('data-ncomp')
                }, 'La fecha vuelve a calcularse con el plazo de vencimiento.');
            });
        });
    }

    /**
     * Guarda o borra y RECARGA todo.
     *
     * Se recarga la pestaña entera en vez de parchear la fila: la fecha cambia
     * en qué columna del eje cae el importe, los totales del pie y las
     * tarjetas. Reconstruir eso en el navegador sería reimplementar en JS la
     * cuenta que ya hace el backend, con el riesgo habitual de que las dos den
     * distinto.
     */
    function pedirFecha(accion, cuerpo, mensajeOk) {
        fetch('Controller/IngresosController.php?action=' + accion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (result.success) {
                Notificacion.exito(mensajeOk);
            } else {
                Notificacion.error(result.message);
            }

            cargarDatos();
        })
        .catch(function(err) {
            Notificacion.error('Error de conexión: ' + err.message);
        });
    }

    function hoyISO() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dia = String(d.getDate()).padStart(2, '0');

        return d.getFullYear() + '-' + m + '-' + dia;
    }

    /**
     * El indicador del Resumen: este cliente tiene alguna factura vencida.
     *
     * Dice "alguna" y no "todas". La marca la repone EjeVista::marcarAlguna()
     * en el controller, porque el agrupado descarta los campos que difieren
     * dentro del grupo.
     */
    function marcaVencida(item) {
        if (modoVista !== 'resumen' || !item.VENCIDA) {
            return '';
        }

        return ' <i class="fas fa-triangle-exclamation text-warning cob-marca-vencida" '
            + 'title="Alguna factura de este cliente tiene la fecha probable de cobro ya '
            + 'vencida: su importe se muestra en el primer día del eje. El detalle está '
            + 'en Detalle Facturas."></i>';
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

    function escaparAttr(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
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

    /** Exporta lo que se ve. Ver Js/tabla-export.js. */
    function exportarExcel() {
        exportarTabla('tablaCobranzasMay', 'Cobranzas_May');
    }
})();
