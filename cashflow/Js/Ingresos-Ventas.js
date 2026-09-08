/**
 * Ingresos - Ventas JavaScript
 * Análisis de ventas y proyección de ventas / cobranzas.
 *
 * El cálculo pesado vive en PHP: acá sólo se pinta la grilla ya resuelta que
 * devuelve VentasController.
 *
 * FECHAS: el backend manda siempre strings 'Y-m-d' y los rótulos de columna ya
 * vienen armados. Nunca se hace new Date(string) sobre un ISO con hora, que es
 * de donde salían los corrimientos de un día.
 */

(function() {
    'use strict';

    var datosAnalisis = null;
    var datosProyeccion = null;
    var vistaActual = 'semanas'; // 'semanas' o 'meses'

    // Etiqueta en reposo del botón de guardar participación. El botón vive en el
    // card-header, FUERA del bloque que se regenera al recalcular, así que su
    // estado no se repone solo: hay que reponerlo explícitamente.
    var HTML_BTN_GUARDAR_PARTIC = '<i class="fas fa-floppy-disk me-1"></i> Guardar';

    function inicializar() {
        console.log('Inicializando Ingresos - Ventas');

        var btnRefreshAnalisis = document.getElementById('btnRefreshAnalisis');
        var btnExportAnalisis = document.getElementById('btnExportAnalisis');
        var btnRefreshProyeccion = document.getElementById('btnRefreshProyeccion');
        var btnExportProyeccion = document.getElementById('btnExportProyeccion');
        var btnVistaSemanas = document.getElementById('btnVistaSemanasProy');
        var btnVistaMeses = document.getElementById('btnVistaMesesProy');
        var btnGuardarPartic = document.getElementById('btnGuardarParticipacion');
        var tabProyeccionBtn = document.getElementById('tabProyeccionBtn');

        if (btnRefreshAnalisis) {
            btnRefreshAnalisis.addEventListener('click', cargarAnalisis);
        }
        if (btnExportAnalisis) {
            btnExportAnalisis.addEventListener('click', function() {
                exportarExcel('tablaAnalisis', 'Analisis_Ventas');
            });
        }
        if (btnRefreshProyeccion) {
            btnRefreshProyeccion.addEventListener('click', cargarProyeccion);
        }
        if (btnExportProyeccion) {
            btnExportProyeccion.addEventListener('click', function() {
                exportarExcel('tablaVenta', 'Proyeccion_Ventas');
            });
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
        if (btnGuardarPartic) {
            btnGuardarPartic.addEventListener('click', guardarParticipacion);
        }

        // La proyección se calcula recién cuando se abre la sub-pestaña
        if (tabProyeccionBtn) {
            tabProyeccionBtn.addEventListener('shown.bs.tab', function() {
                if (!datosProyeccion) {
                    cargarProyeccion();
                }
            });
        }

        cargarAnalisis();
    }

    // Ejecutar cuando el DOM esté listo O inmediatamente si ya está listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       ANÁLISIS DE VENTAS
       ================================================================ */

    function cargarAnalisis() {
        mostrar('loadingAnalisis', true);
        mostrar('wrapperAnalisis', false);

        pedir('Controller/VentasController.php?action=getAnalisisVentas&anioDesde=2025')
            .then(function(data) {
                datosAnalisis = data;
                generarTablaTendencias();
                generarTablaAnalisis();
                generarTablaFacturacion();
                mostrar('loadingAnalisis', false);
                mostrar('wrapperAnalisis', true);
                ajustarStickyHeaders();
            })
            .catch(function(error) {
                mostrar('loadingAnalisis', false);
                mostrarError('Error al cargar el análisis de ventas: ' + error.message);
            });
    }

    /**
     * Tendencia de los últimos meses contra el mismo período del año anterior.
     *
     * Sale del histórico DIARIO, no del mensual: el mes en curso se compara
     * contra los mismos días del año anterior. Contra el mes entero la variación
     * daría siempre hundida, porque enfrentaría los días transcurridos contra
     * treinta.
     *
     * Los importes son NETOS: la Venta Proyectada de la tabla de abajo lleva
     * IVA, así que las dos tablas no se comparan entre sí. El th-sub lo dice en
     * cada columna, que es el mismo recurso que ya usa el análisis.
     */
    function generarTablaTendencias() {
        var filas = datosAnalisis.tendencias || [];
        var totales = datosAnalisis.tendencias_totales;
        var corte = datosAnalisis.tendencias_dia_corte;

        document.getElementById('tendenciasHeader').innerHTML =
            '<th class="col-canal">Mes</th>' +
            '<th class="text-end">Venta Neta' +
                '<span class="th-sub">neto s/ IVA</span></th>' +
            '<th class="text-end">Mismo Período Año Anterior' +
                '<span class="th-sub">neto s/ IVA</span></th>' +
            '<th class="text-center">Var. Interanual</th>';

        var elCorte = document.getElementById('tendenciasCorte');

        if (!filas.length) {
            // La tabla diaria es nueva: si el SP todavía no corrió, el bloque
            // sale vacío pero el resto de la pantalla sigue andando.
            document.getElementById('tendenciasBody').innerHTML =
                '<tr><td colspan="4" class="text-center text-muted py-4">' +
                'No hay histórico diario cargado. Corré el SP RO_SP_CASHFLOW_VENTAS_HIST_DIA.</td></tr>';
            document.getElementById('tendenciasTotals').innerHTML = '';
            elCorte.textContent = '';
            return;
        }

        var ultima = filas[filas.length - 1];

        elCorte.innerHTML = '&middot; ' +
            (ultima.parcial ? 'parcial al ' : 'al ') + fechaCorta(corte);

        var html = '';

        filas.forEach(function(fila) {
            // El rango de días es lo que explica por qué un mes parcial tiene un
            // importe más chico: sin eso el número se lee como una caída.
            var rango = 'días 1 al ' + fila.dias;

            html += '<tr' + (fila.parcial ? ' class="fila-parcial"' : '') + '>';
            html += '<td class="col-canal fw-semibold">' + fila.label +
                    (fila.parcial
                        ? ' <span class="badge-parcial" title="Mes incompleto: ' + rango + '">parcial</span>'
                        : '') +
                    '</td>';

            html += '<td class="currency" title="' + fila.label + ' — ' + rango + '">' +
                    formatCurrency(fila.neto) + '</td>';

            html += '<td class="currency text-muted" title="' + fila.label_anio_anterior +
                    ' — días 1 al ' + fila.dias_anio_anterior + '">' +
                    formatCurrency(fila.neto_anio_anterior) + '</td>';

            html += '<td class="text-center">' + badgeVariacion(fila.variacion) + '</td>';
            html += '</tr>';
        });

        document.getElementById('tendenciasBody').innerHTML = html;

        document.getElementById('tendenciasTotals').innerHTML =
            '<td class="col-canal total-label">TOTALES</td>' +
            '<td class="currency" title="Neto sin IVA">' +
                formatCurrency(totales.neto) + '</td>' +
            '<td class="currency" title="Neto sin IVA">' +
                formatCurrency(totales.neto_anio_anterior) + '</td>' +
            '<td class="text-center">' + badgeVariacion(totales.variacion) + '</td>';
    }

    /** '2026-09-06' -> '06/09'. Se corta el string: nunca new Date() sobre un ISO. */
    function fechaCorta(iso) {
        if (!iso) {
            return '';
        }

        var p = String(iso).split('-');

        return p[2] + '/' + p[1];
    }

    /**
     * Tabla de proyección por mes: 12 meses (el actual + 11), sin apertura
     * por canal.
     *
     *   Venta Proyectada = Venta Año Anterior × (1 + Variación) × (1 + IVA)
     *   Var. Interanual  = Año Anterior / Año Previo − 1
     */
    function generarTablaAnalisis() {
        var filas = datosAnalisis.proyeccion;
        var totales = datosAnalisis.proyeccion_totales;
        var iva = datosAnalisis.alicuota_iva || 0;

        // Las tres columnas de importe NO están en la misma base: las dos
        // históricas son netas y la proyectada lleva IVA. Se aclara en el
        // encabezado, que era de donde salía la confusión.
        var header =
            '<th class="col-canal">Mes-Año</th>' +
            '<th class="text-end">Año Previo' +
                '<span class="th-sub">neto s/ IVA</span></th>' +
            '<th class="text-end">Año Anterior' +
                '<span class="th-sub">neto s/ IVA &middot; base</span></th>' +
            '<th class="text-center">Var. Interanual</th>' +
            '<th class="text-center">% de Variación ' +
                '<i class="fas fa-pen-to-square ms-1" style="font-size: 10px;" title="Click para editar"></i>' +
                '<span class="th-sub">9 = +9% sobre el año anterior</span></th>' +
            '<th class="text-end">Venta Proyectada' +
                '<span class="th-sub th-sub-iva">CON IVA ' + formatPercentCorto(iva) + '</span></th>';

        document.getElementById('analisisHeader').innerHTML = header;

        if (!filas.length) {
            document.getElementById('analisisBody').innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-4">' +
                'No hay historial cargado. Corré el SP SJ_CASHFLOW_VENTAS_HIST.</td></tr>';
            document.getElementById('analisisTotals').innerHTML = '';
            return;
        }

        var html = '';

        filas.forEach(function(fila) {
            html += '<tr>';
            html += '<td class="col-canal fw-semibold">' + fila.label + '</td>';

            html += '<td class="currency text-muted" title="' + fila.label_anio_previo + '">' +
                    formatCurrency(fila.neto_anio_previo) + '</td>';

            html += '<td class="currency" title="' + fila.label_anio_anterior +
                    ' — base de la proyección">' +
                    formatCurrency(fila.neto_anio_anterior) + '</td>';

            html += '<td class="text-center">' + badgeVariacion(fila.variacion) + '</td>';
            html += celdaIndice(fila);

            // El tooltip muestra la cuenta completa: de dónde sale la diferencia
            // contra el neto del año anterior.
            var cuenta = formatCurrency(fila.neto_anio_anterior) +
                         ' × (1 + ' + formatPercentCorto(fila.indice) + ')' +
                         ' × (1 + IVA ' + formatPercentCorto(iva) + ')' +
                         ' = ' + formatCurrency(fila.venta_proyectada);

            html += '<td class="currency fw-semibold cell-with-value" title="' + cuenta + '">' +
                    formatCurrency(fila.venta_proyectada) +
                    (fila.estimado
                        ? ' <i class="fas fa-triangle-exclamation text-warning ms-1" ' +
                          'title="El mismo mes del año anterior no tiene datos: mes estimado."></i>'
                        : '') +
                    '</td>';

            html += '</tr>';
        });

        document.getElementById('analisisBody').innerHTML = html;

        var totalsHtml =
            '<td class="col-canal total-label">TOTALES</td>' +
            '<td class="currency" title="Neto sin IVA">' +
                formatCurrency(totales.neto_anio_previo) + '</td>' +
            '<td class="currency" title="Neto sin IVA">' +
                formatCurrency(totales.neto_anio_anterior) + '</td>' +
            '<td colspan="2"></td>' +
            '<td class="currency" title="Con IVA ' + formatPercentCorto(iva) + '">' +
                formatCurrency(totales.con_iva) + '</td>';

        document.getElementById('analisisTotals').innerHTML = totalsHtml;
    }

    /**
     * Celda del % de variación.
     * Se guarda contra el (año, mes) del MES PROYECTADO, que es la misma clave
     * que usa el motor de proyección.
     */
    function celdaIndice(fila) {
        var editado = !!fila.indice_editado;
        var valor = fila.indice || 0;

        return '<td class="center indice-cell ' + (editado ? 'fecha-editada' : '') + '"' +
               ' data-anio="' + fila.anio + '"' +
               ' data-mes="' + fila.mes + '"' +
               ' data-indice="' + valor + '"' +
               ' onclick="editarIndice(this)">' +
                   '<div class="indice-display">' +
                       '<span class="indice-value">' + formatPercent(valor) + '</span>' +
                       (editado ? '<span class="badge-fecha-editada">Editado</span>' : '') +
                       '<i class="fas fa-pen indice-icon"></i>' +
                   '</div>' +
                   '<div class="fecha-tooltip">' +
                       '<span class="fecha-tooltip-label">Proyecta</span>' +
                       '<span class="fecha-tooltip-value">' + fila.label + '</span>' +
                   '</div>' +
               '</td>';
    }

    /**
     * Bloque de control de facturación: facturas y remitos por mes, sin
     * apertura por canal. No entra en la proyección.
     */
    function generarTablaFacturacion() {
        var filas = datosAnalisis.facturacion;
        var totales = datosAnalisis.facturacion_totales;

        if (!filas.length) {
            document.getElementById('facturacionBody').innerHTML =
                '<tr><td colspan="4" class="text-center text-muted py-4">' +
                'No hay facturación cargada en el período.</td></tr>';
            document.getElementById('facturacionTotals').innerHTML = '';
            return;
        }

        var html = '';

        filas.forEach(function(fila) {
            html += '<tr>';
            html += '<td class="col-canal fw-semibold">' + fila.label + '</td>';
            html += '<td class="currency">' + formatCurrency(fila.facturas) + '</td>';
            html += '<td class="currency">' + formatCurrency(fila.remitos) + '</td>';
            html += '<td class="currency fw-semibold">' + formatCurrency(fila.total) + '</td>';
            html += '</tr>';
        });

        document.getElementById('facturacionBody').innerHTML = html;

        document.getElementById('facturacionTotals').innerHTML =
            '<td class="col-canal total-label">TOTALES</td>' +
            '<td class="currency">' + formatCurrency(totales.facturas) + '</td>' +
            '<td class="currency">' + formatCurrency(totales.remitos) + '</td>' +
            '<td class="currency">' + formatCurrency(totales.total) + '</td>';
    }

    /**
     * Edición del % de variación, mismo patrón de celda editable que Comex.
     *
     * Se tipea el porcentaje (9) y se guarda la tasa (0,09). El sufijo "%" del
     * input-group está para que esa convención se vea sin tener que deducirla:
     * era de donde salía la duda de si había que ingresar 9 o 1,09.
     */
    window.editarIndice = function(cell) {
        if (cell.querySelector('input')) {
            return;
        }

        var anio = cell.dataset.anio;
        var mes = cell.dataset.mes;
        var actual = parseFloat(cell.dataset.indice) || 0;
        var originalContent = cell.innerHTML;

        var input = document.createElement('input');
        input.type = 'number';
        input.step = '0.01';
        input.className = 'form-control indice-input';
        // Se edita en porcentaje y se guarda en tasa
        input.value = (actual * 100).toFixed(2);

        // Mismo input-group que la participación del tramo. El listener sigue
        // colgado del input, no del grupo, así que el blur/Enter no cambia:
        // hasta un click sobre el sufijo dispara el blur y guarda.
        var sufijo = document.createElement('span');
        sufijo.className = 'input-group-text';
        sufijo.textContent = '%';

        var grupo = document.createElement('div');
        grupo.className = 'input-group input-group-sm';
        grupo.appendChild(input);
        grupo.appendChild(sufijo);

        cell.innerHTML = '';
        cell.appendChild(grupo);
        input.focus();
        input.select();

        var guardar = function() {
            if (input.dataset.guardando) {
                return;
            }

            input.dataset.guardando = '1';

            var nuevo = parseFloat(input.value);

            if (isNaN(nuevo)) {
                cell.innerHTML = originalContent;
                return;
            }

            cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            pedir('Controller/VentasController.php?action=saveIndice', {
                anio: parseInt(anio, 10),
                mes: parseInt(mes, 10),
                indice: nuevo / 100
            })
            .then(function() {
                // Recalcular sin recargar la página: se refresca el análisis y,
                // si la proyección ya estaba calculada, también la proyección.
                cargarAnalisis();

                if (datosProyeccion) {
                    cargarProyeccion();
                }
            })
            .catch(function(error) {
                alert('Error al guardar el % de variación: ' + error.message);
                cell.innerHTML = originalContent;
            });
        };

        input.addEventListener('blur', guardar);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                guardar();
            } else if (e.key === 'Escape') {
                input.dataset.guardando = '1';
                cell.innerHTML = originalContent;
            }
        });
    };

    /* ================================================================
       PROYECCIÓN
       ================================================================ */

    function cargarProyeccion() {
        mostrar('loadingProyeccion', true);
        mostrar('wrapperVenta', false);
        mostrar('cardCobranza', false);
        mostrar('cardParticipacion', false);
        mostrar('summaryProyeccion', false);

        // Venta y cobranza se piden en paralelo: son dos acciones distintas del
        // controller y ninguna depende del resultado de la otra.
        Promise.all([
            pedir('Controller/VentasController.php?action=getProyeccionVentas'),
            pedir('Controller/VentasController.php?action=getProyeccionCobranzas')
        ])
        .then(function(res) {
            datosProyeccion = res[0];
            datosProyeccion.cobranza = res[1].cobranza;
            datosProyeccion.mix = res[1].mix;
            datosProyeccion.kpi = res[1].kpi;
            datosProyeccion.warnings = res[1].warnings || [];

            generarParticipacion();
            generarTablaVenta();
            generarTablaCobranza();
            generarKpis();
            generarWarnings();

            mostrar('loadingProyeccion', false);
            mostrar('wrapperVenta', true);
            mostrar('cardCobranza', true);
            mostrar('cardParticipacion', true);
            mostrar('summaryProyeccion', true, 'flex');
            ajustarStickyHeaders();
        })
        .catch(function(error) {
            mostrar('loadingProyeccion', false);
            // Si el recálculo falla, el bloque no se re-renderiza: hay que soltar
            // el botón acá o queda con el spinner para siempre.
            resetBotonParticipacion();
            mostrarError('Error al calcular la proyección: ' + error.message);
        });
    }

    function cambiarVista(vista) {
        vistaActual = vista;

        var btnSemanas = document.getElementById('btnVistaSemanasProy');
        var btnMeses = document.getElementById('btnVistaMesesProy');

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

        if (datosProyeccion) {
            generarTablaVenta();
            generarTablaCobranza();
            ajustarStickyHeaders();
        }
    }

    /** Columnas del eje temporal según la vista activa */
    function columnas() {
        if (vistaActual === 'semanas') {
            return datosProyeccion.dias.map(function(d) {
                return {
                    clave: d.fecha,
                    label: d.label,
                    feriado: d.feriado_comercio,
                    css: 'day-column'
                };
            });
        }

        return datosProyeccion.meses.map(function(m) {
            return {
                clave: m.clave,
                label: m.label,
                feriado: false,
                css: 'month-column'
            };
        });
    }

    /** Rama de la grilla que corresponde a la vista activa */
    function rama(grilla) {
        return (vistaActual === 'semanas') ? grilla.dias : grilla.meses;
    }

    function ramaTotal(grilla) {
        return (vistaActual === 'semanas') ? grilla.total_dias : grilla.total_meses;
    }

    /**
     * Encabezado de columnas, compartido por Venta y Cobranza.
     *
     * @param conTooltip Sólo Venta lo pide: el tooltip describe la apertura por
     *        canal de la venta de esa columna, que en Cobranza no aplica.
     */
    function generarEncabezado(idHeaderSub, idPeriodoHeader, conTooltip) {
        var cols = columnas();
        var headerHTML = '';

        cols.forEach(function(col, i) {
            // El tooltip es de columnas de MES: en la vista de semanas las
            // columnas son días y no hay participación que abrir.
            var tip = (conTooltip && vistaActual === 'meses')
                ? tooltipParticipacion(col.clave, i, cols.length)
                : '';

            headerHTML += '<th class="' + col.css + (col.feriado ? ' col-feriado' : '') +
                          (tip ? ' th-participacion' : '') + '"' +
                          (col.feriado ? ' title="Feriado de comercio: sin venta estimada"' : '') +
                          '>' + col.label + tip + '</th>';
        });

        document.getElementById(idHeaderSub).innerHTML = headerHTML;

        var periodoHeader = document.getElementById(idPeriodoHeader);
        periodoHeader.setAttribute('colspan', cols.length);
        periodoHeader.textContent = (vistaActual === 'semanas')
            ? 'Próximos ' + datosProyeccion.horizonte_dias + ' Días (4 Semanas)'
            : 'Próximos ' + datosProyeccion.horizonte_meses + ' Meses';

        return cols;
    }

    /**
     * Días que la columna de un mes realmente cubre.
     *
     * NO es "el mes menos el tramo": la ventana de proyección arranca HOY, así
     * que los días anteriores del mes en curso no aportan nada. Tomarlos como
     * cubiertos haría que el tooltip prometiera un rango que el importe no
     * contiene.
     *
     * @return array Números de día, en orden
     */
    function diasDeColumnaMes(clave) {
        var partes = clave.split('-');
        // Constructor numérico, no parseo de ISO: el día 0 del mes siguiente es
        // el último del mes pedido.
        var ultimo = new Date(parseInt(partes[0], 10), parseInt(partes[1], 10), 0).getDate();
        var hoy = datosProyeccion.generado;

        var enTramo = {};

        datosProyeccion.dias.forEach(function(d) {
            enTramo[d.fecha] = true;
        });

        var lista = [];

        for (var d = 1; d <= ultimo; d++) {
            // Comparación de strings 'Y-m-d': ordena igual que las fechas y
            // evita new Date() sobre un ISO.
            var fecha = clave + '-' + (d < 10 ? '0' + d : d);

            if (fecha < hoy || enTramo[fecha]) {
                continue;
            }

            lista.push(d);
        }

        return lista;
    }

    /**
     * Tooltip de participación por canal de una columna de mes.
     *
     * El % sale del cociente de los importes y no de participacion.mensual: los
     * overrides mensuales se guardan sin la validación del 100% que sí tiene el
     * tramo, así que la participación guardada podría no sumar 100 al lado de
     * importes que sí lo hacen. El cociente siempre concuerda con los números
     * que el tooltip muestra al lado.
     *
     * Dentro del tramo rige participacion.tramo28, pero acá no entra: la columna
     * del mes sólo contiene los días de FUERA del tramo, y todos ellos se
     * calcularon con la participación mensual.
     */
    function tooltipParticipacion(clave, indice, cantidad) {
        var venta = datosProyeccion.venta;
        var canales = datosProyeccion.canales;
        var total = (venta.total_meses && venta.total_meses[clave]) || 0;
        var dias = diasDeColumnaMes(clave);

        var mes = null;

        datosProyeccion.meses.forEach(function(m) {
            if (m.clave === clave) {
                mes = m;
            }
        });

        var label = mes ? mes.label : clave;
        var cuerpo;

        if (!dias.length) {
            // El primer mes puede quedar entero dentro del tramo de 28 días.
            cuerpo = '<div class="tip-vacio">Todos sus días están en el tramo ' +
                     'de 28 días: se muestran en las columnas diarias.</div>';
        } else if (total <= 0) {
            cuerpo = '<div class="tip-vacio">Sin venta estimada en la columna.</div>';
        } else {
            cuerpo = '';

            canales.forEach(function(canal) {
                var importe = (venta.meses[canal] && venta.meses[canal][clave]) || 0;

                cuerpo += '<div class="tip-fila">' +
                              '<span class="tip-canal">' + titulo(canal) + '</span>' +
                              '<span class="tip-importe">' + formatCurrency(importe) + '</span>' +
                              '<span class="tip-pct">' + formatPercentCorto(importe / total) + '</span>' +
                          '</div>';
            });

            cuerpo += '<div class="tip-fila tip-total">' +
                          '<span class="tip-canal">Total</span>' +
                          '<span class="tip-importe">' + formatCurrency(total) + '</span>' +
                          '<span class="tip-pct">100%</span>' +
                      '</div>';
        }

        // El rango es lo que hace entendible un mes recortado: sin él, el importe
        // más chico se lee como una caída de venta y no como menos días.
        var rango;

        if (!dias.length) {
            rango = 'sin días propios';
        } else if (dias.length === diasDelMes(clave)) {
            rango = 'mes completo';
        } else {
            rango = 'días ' + dias[0] + ' al ' + dias[dias.length - 1] +
                    ' &middot; fuera del tramo de 28 días';
        }

        // Las últimas columnas se anclan a la derecha o el tooltip se corta
        // contra el borde del contenedor, que tiene overflow.
        var alineacion = (indice >= cantidad - 3) ? ' tip-derecha' : '';

        return '<div class="participacion-tooltip' + alineacion + '">' +
                   '<div class="tip-titulo">' + label +
                       '<span class="tip-rango">' + rango + '</span></div>' +
                   cuerpo +
               '</div>';
    }

    /** Cantidad de días de un mes 'YYYY-MM' */
    function diasDelMes(clave) {
        var partes = clave.split('-');

        return new Date(parseInt(partes[0], 10), parseInt(partes[1], 10), 0).getDate();
    }

    function generarTablaVenta() {
        var cols = generarEncabezado('ventaHeaderSub', 'ventaPeriodoHeader', true);
        var canales = datosProyeccion.canales;
        var grilla = rama(datosProyeccion.venta);
        var totales = ramaTotal(datosProyeccion.venta);

        var html = '';

        canales.forEach(function(canal) {
            html += '<tr>';
            html += '<td class="col-canal fw-semibold">' + titulo(canal) + '</td>';

            cols.forEach(function(col) {
                var valor = (grilla[canal] && grilla[canal][col.clave]) || 0;
                html += celdaValor(valor, col.feriado);
            });

            html += '</tr>';
        });

        document.getElementById('ventaBody').innerHTML = html;

        var totalsHtml = '<td class="col-canal total-label">TOTAL VENTA</td>';

        cols.forEach(function(col) {
            totalsHtml += celdaValor(totales[col.clave] || 0, col.feriado);
        });

        document.getElementById('ventaTotals').innerHTML = totalsHtml;
    }

    function generarTablaCobranza() {
        var cols = generarEncabezado('cobHeaderSub', 'cobPeriodoHeader');
        var canales = datosProyeccion.canales;
        var cob = datosProyeccion.cobranza;
        var grilla = rama(cob);
        var subtotales = (vistaActual === 'semanas') ? cob.subtotal_dias : cob.subtotal_meses;
        var totales = ramaTotal(cob);
        var neteo = (vistaActual === 'semanas')
            ? cob.neteo_prechequeado.dias
            : cob.neteo_prechequeado.meses;

        var html = '';

        canales.forEach(function(canal) {
            var filasCanal = cob.filas.filter(function(f) { return f.canal === canal; });

            if (!filasCanal.length) {
                return;
            }

            filasCanal.forEach(function(fila) {
                html += '<tr>';
                html += '<td class="col-canal">' + titulo(canal) + '</td>';
                html += '<td class="col-medio">' + titulo(fila.medio_pago) + '</td>';
                html += '<td class="text-center text-muted">' + formatPercent(fila.porcentaje) + '</td>';
                html += '<td class="text-center text-muted">' + fila.dias_acreditacion + '</td>';

                cols.forEach(function(col) {
                    var valor = (grilla[fila.clave] && grilla[fila.clave][col.clave]) || 0;
                    html += celdaValor(valor, false);
                });

                html += '</tr>';
            });

            // Subtotal del canal
            html += '<tr class="fila-subtotal">';
            html += '<td class="col-canal" colspan="2">Subtotal ' + titulo(canal) + '</td>';
            html += '<td colspan="2"></td>';

            cols.forEach(function(col) {
                var valor = (subtotales[canal] && subtotales[canal][col.clave]) || 0;
                html += celdaValor(valor, false);
            });

            html += '</tr>';
        });

        document.getElementById('cobBody').innerHTML = html;

        // Pie: cobranza bruta, neteo de prechequeado y cobranza neta
        var foot = '<tr class="fila-total">';
        foot += '<td class="col-canal total-label" colspan="4">COBRANZA PROYECTADA</td>';

        cols.forEach(function(col) {
            foot += celdaValor(totales[col.clave] || 0, false);
        });

        foot += '</tr>';

        foot += '<tr class="fila-neteo">';
        foot += '<td class="col-canal" colspan="4">' +
                'Neteo cheques adelantados ' +
                '<i class="fas fa-plug-circle-xmark ms-1" ' +
                'title="Circuito cableado y apagado: la vista origen todavía no existe, así que el neteo devuelve cero."></i>' +
                '</td>';

        cols.forEach(function(col) {
            foot += '<td class="currency text-muted">' + formatCurrency(-(neteo[col.clave] || 0)) + '</td>';
        });

        foot += '</tr>';

        foot += '<tr class="fila-total fila-neta">';
        foot += '<td class="col-canal total-label" colspan="4">COBRANZA NETA</td>';

        cols.forEach(function(col) {
            var neto = (totales[col.clave] || 0) - (neteo[col.clave] || 0);
            foot += celdaValor(neto, false);
        });

        foot += '</tr>';

        document.getElementById('cobFoot').innerHTML = foot;
    }

    function celdaValor(valor, esFeriado) {
        if (esFeriado) {
            return '<td class="currency col-feriado" title="Feriado de comercio">&mdash;</td>';
        }

        return '<td class="currency ' + (valor > 0 ? 'cell-with-value' : '') + '">' +
               (valor > 0 ? formatCurrency(valor) : '') + '</td>';
    }

    function generarKpis() {
        var kpi = datosProyeccion.kpi || {};

        document.getElementById('kpiVentaTramo').textContent = formatCurrency(kpi.venta_tramo || 0);
        document.getElementById('kpiCobranzaTramo').textContent = formatCurrency(kpi.cobranza_tramo || 0);
        document.getElementById('kpiVentaHorizonte').textContent = formatCurrency(kpi.venta_horizonte || 0);
        document.getElementById('kpiCobranzaHorizonte').textContent = formatCurrency(kpi.cobranza_horizonte || 0);
    }

    function generarWarnings() {
        var cont = document.getElementById('warningsProyeccion');
        var warnings = datosProyeccion.warnings || [];

        if (!warnings.length) {
            cont.innerHTML = '';
            return;
        }

        var html = '';

        warnings.forEach(function(w) {
            html += '<div class="alert alert-warning py-2 px-3 mb-2">' +
                    '<i class="fas fa-triangle-exclamation me-1"></i><small>' + w + '</small></div>';
        });

        cont.innerHTML = html;
    }

    /* ================================================================
       PARTICIPACIÓN DEL TRAMO DE 28 DÍAS
       ================================================================ */

    function generarParticipacion() {
        var partic = datosProyeccion.participacion.tramo28;
        var canales = datosProyeccion.canales;
        var html = '';

        canales.forEach(function(canal) {
            var p = partic[canal];
            var editado = (p.edit !== null && p.edit !== undefined);

            html += '<div class="col-md-6 col-lg-3">' +
                        '<div class="partic-card' + (editado ? ' partic-editada' : '') + '">' +
                            '<div class="partic-canal">' + titulo(canal) + '</div>' +
                            '<div class="partic-calc">' +
                                '<span class="partic-label">Calculado</span>' +
                                '<span class="partic-valor">' + formatPercent(p.calc) + '</span>' +
                            '</div>' +
                            '<div class="partic-edit">' +
                                '<span class="partic-label">Editado</span>' +
                                '<div class="input-group input-group-sm">' +
                                    '<input type="number" step="0.0001" class="form-control partic-input" ' +
                                        'data-canal="' + canal + '" ' +
                                        'data-calc="' + p.calc + '" ' +
                                        'value="' + (p.efectivo * 100).toFixed(4) + '">' +
                                    '<span class="input-group-text">%</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
        });

        document.getElementById('gridParticipacion').innerHTML = html;

        // El botón está fuera de este grid, así que no se repone con el innerHTML
        resetBotonParticipacion();

        mostrar('avisoTramoEstimado', !!datosProyeccion.participacion.tramo28_estimado);

        // Validación en vivo de la suma
        var inputs = document.querySelectorAll('.partic-input');

        inputs.forEach(function(input) {
            input.addEventListener('input', validarParticipacion);
        });

        validarParticipacion();
    }

    /**
     * La suma de los cuatro canales debe dar exactamente 100%.
     * Si no da, se bloquea el guardado y se muestra el desvío.
     */
    function validarParticipacion() {
        var inputs = document.querySelectorAll('.partic-input');
        var suma = 0;

        inputs.forEach(function(input) {
            suma += (parseFloat(input.value) || 0) / 100;
        });

        var span = document.getElementById('sumaParticipacion');
        var btn = document.getElementById('btnGuardarParticipacion');
        var valido = Math.abs(suma - 1) < 0.000001;

        span.textContent = formatPercent(suma);
        span.classList.toggle('suma-ok', valido);
        span.classList.toggle('suma-error', !valido);

        if (valido) {
            span.title = 'La suma es exactamente 100%';
        } else {
            var desvio = (suma - 1) * 100;
            span.title = 'Desvío: ' + (desvio > 0 ? '+' : '') + desvio.toFixed(4) + ' puntos';
            span.textContent = formatPercent(suma) +
                ' (' + (desvio > 0 ? '+' : '') + desvio.toFixed(4) + ')';
        }

        btn.disabled = !valido;

        return valido;
    }

    function guardarParticipacion() {
        if (!validarParticipacion()) {
            return;
        }

        var inputs = document.querySelectorAll('.partic-input');
        var valores = {};

        inputs.forEach(function(input) {
            valores[input.dataset.canal] = {
                calc: parseFloat(input.dataset.calc) || 0,
                edit: (parseFloat(input.value) || 0) / 100
            };
        });

        var ancla = datosProyeccion.participacion.tramo28_ancla; // 'YYYY-MM'
        var btn = document.getElementById('btnGuardarParticipacion');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        pedir('Controller/VentasController.php?action=saveParticipacion', {
            tipo: 'TRAMO28',
            anio: parseInt(ancla.substring(0, 4), 10),
            mes: parseInt(ancla.substring(5, 7), 10),
            valores: valores
        })
        .then(function() {
            // El guardado salió bien: se marca con el check y se recalcula venta
            // y cobranza sin recargar la página. La etiqueta en reposo la repone
            // generarParticipacion() cuando termina de re-renderizar.
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Guardado';
            cargarProyeccion();
        })
        .catch(function(error) {
            alert('Error al guardar la participación: ' + error.message);
            resetBotonParticipacion();
            validarParticipacion();
        });
    }

    /**
     * Devuelve el botón de guardar participación a su estado de reposo.
     * Se llama en cada render y también si falla el recálculo, para que nunca
     * quede colgado mostrando el spinner.
     */
    function resetBotonParticipacion() {
        var btn = document.getElementById('btnGuardarParticipacion');

        if (btn) {
            btn.innerHTML = HTML_BTN_GUARDAR_PARTIC;
        }
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /**
     * Wrapper de fetch con el manejo de errores del proyecto.
     * @param {string} url URL del controller
     * @param {object} body Body JSON opcional (si viene, va por POST)
     */
    function pedir(url, body) {
        var opciones = {};

        if (body) {
            opciones = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            };
        }

        return fetch(url, opciones)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }

                return response.text();
            })
            .then(function(text) {
                var result;

                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Respuesta no JSON:', text);
                    throw new Error('Respuesta inválida del servidor. Revisá la consola.');
                }

                if (!result.success) {
                    console.error('Error del servidor:', result);
                    throw new Error(result.message || 'Error desconocido');
                }

                return result.data;
            });
    }

    function formatCurrency(value) {
        var num = parseFloat(value) || 0;

        return '$ ' + num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatPercent(value) {
        var num = (parseFloat(value) || 0) * 100;

        return num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 4
        }) + '%';
    }

    /** Porcentaje sin decimales sobrantes: 0.21 -> "21%", 0.215 -> "21,5%" */
    function formatPercentCorto(value) {
        var num = (parseFloat(value) || 0) * 100;

        return num.toLocaleString('es-AR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }) + '%';
    }

    function badgeVariacion(variacion) {
        if (variacion === null || variacion === undefined) {
            return '<span class="text-muted">&mdash;</span>';
        }

        var pct = variacion * 100;
        var clase = (pct >= 0) ? 'variacion-positiva' : 'variacion-negativa';
        var icono = (pct >= 0) ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';

        return '<span class="badge-variacion ' + clase + '">' +
               '<i class="fas ' + icono + ' me-1"></i>' +
               (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%</span>';
    }

    /** LOCALES -> Locales, GO CUOTAS -> Go Cuotas */
    function titulo(texto) {
        return String(texto).toLowerCase().replace(/(^|\s)\S/g, function(c) {
            return c.toUpperCase();
        });
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function mostrarError(mensaje) {
        console.error(mensaje);
        alert(mensaje);
    }

    /**
     * Recalcula el offset superior de la segunda fila del thead.
     * El helper es compartido y vive en main.js.
     */
    function ajustarStickyHeaders() {
        if (typeof window.ajustarStickyHeaders === 'function') {
            window.ajustarStickyHeaders();
        }
    }

    function exportarExcel(idTabla, nombre) {
        var tabla = document.getElementById(idTabla);

        if (!tabla) {
            return;
        }

        var html = tabla.outerHTML;
        var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);

        var a = document.createElement('a');
        a.href = url;
        a.download = nombre + '_' + new Date().toISOString().split('T')[0] + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

})(); // Fin del IIFE
