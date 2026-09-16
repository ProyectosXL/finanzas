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
    var datosAcumulada = null;
    var datosBalance = null;

    /**
     * Las tres vistas del eje temporal de la grilla de proyección, manejadas por
     * Js/eje-vistas.js. Antes eran dos, Semanas y Meses, con el estado y los
     * botones acá.
     *
     * NO se confunde con vistaAnual: eso es otra dimensión, elige QUÉ se mide
     * (venta cashflow, acumulada o balance) y no sobre qué período.
     */
    var vistas = null;

    // Vista activa del card "Proyección de Venta por Mes":
    // 'cashflow' | 'acumulada' | 'balance'. Las tres NO comparten la base, así
    // que de esto dependen el subtítulo, el refresh y el export.
    var vistaAnual = 'cashflow';

    // Subtítulo de la vista Cashflow: se guarda el que viene del HTML en vez de
    // repetirlo acá, para que la vista existente quede exactamente igual.
    var subtituloCashflow = '';

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
        var btnGuardarPartic = document.getElementById('btnGuardarParticipacion');
        var tabProyeccionBtn = document.getElementById('tabProyeccionBtn');

        // Los dos botones viven en el card-header, que es compartido por las
        // tres vistas anuales: actúan sobre la que esté abierta.
        if (btnRefreshAnalisis) {
            btnRefreshAnalisis.addEventListener('click', refrescarVistaAnual);
        }
        if (btnExportAnalisis) {
            btnExportAnalisis.addEventListener('click', exportarVistaAnual);
        }
        if (btnRefreshProyeccion) {
            btnRefreshProyeccion.addEventListener('click', cargarProyeccion);
        }
        if (btnExportProyeccion) {
            btnExportProyeccion.addEventListener('click', function() {
                exportarExcel('tablaVenta', 'Proyeccion_Ventas');
            });
        }
        if (btnGuardarPartic) {
            btnGuardarPartic.addEventListener('click', guardarParticipacion);
        }

        vistas = crearEjeVistas({
            botones: 'vistasProy',
            periodo: 'periodoProy',
            alCambiar: cambiarVista
        });

        // Canal y Medio de Pago: es el par que identifica la fila, y es lo que
        // ya estaba fijo cuando el mecanismo estaba cableado en el CSS. Ahora
        // además se puede cambiar.
        //
        // Las otras tablas de la pestaña -tendencias, proyección por mes,
        // control de facturación- no llevan selector: tienen cuatro o cinco
        // columnas y no scrollean a lo ancho, así que elegir columnas fijas ahí
        // no resuelve nada. Conservan igual su primera columna fija, que es el
        // default automático de Js/columnas-fijas.js.
        crearColumnasFijas({
            tabla: 'tablaCobranza',
            control: 'colFijasCobranza',
            clave: 'ventas_cobranza',
            porDefecto: [0, 1]
        });

        // La proyección se calcula recién cuando se abre la sub-pestaña
        if (tabProyeccionBtn) {
            tabProyeccionBtn.addEventListener('shown.bs.tab', function() {
                if (!datosProyeccion) {
                    cargarProyeccion();
                }
            });
        }

        inicializarVistasAnuales();
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

    /* ================================================================
       VISTAS ANUALES DEL CARD "PROYECCIÓN DE VENTA POR MES"

       Tres pestañas dentro del card. Venta Cashflow es la que alimenta la
       proyección y abre por defecto; las otras dos son lecturas anuales del
       dato real y se piden recién cuando se abre su pestaña -mismo criterio
       lazy que la sub-pestaña Proyección-, así la pantalla que ya funcionaba no
       paga una consulta que quizás nadie mire.

       LAS TRES NO ESTÁN EN LA MISMA BASE: Cashflow compara netos contra una
       proyectada con IVA, Acumulada es toda neta y Balance es todo con IVA. El
       subtítulo del card y el th-sub de cada columna lo dicen.
       ================================================================ */

    /**
     * Por qué el acumulado en dólares no es el acumulado en pesos dividido por
     * un tipo de cambio. Es la diferencia entre una serie histórica en dólares y
     * una reexpresión a moneda de hoy, y va en el tooltip de la columna.
     */
    var TIP_VALUACION = 'Cada mes se valúa a SU propio tipo de cambio de cierre ' +
        'y el acumulado en dólares es la suma de los meses ya valuados. NO es el ' +
        'acumulado en pesos dividido por un tipo de cambio: eso sería reexpresar ' +
        'toda la serie a moneda de hoy, que es otra cuenta.';

    var TIP_BALANCE = 'Los meses cerrados son venta real neta grosada por IVA; ' +
        'el mes en curso y los siguientes son la venta proyectada, que ya lleva ' +
        'IVA. El mes en curso va SIEMPRE proyectado, aunque tenga venta cargada: ' +
        'un mes a medio facturar sumado contra meses completos hundiría el total.';

    function inicializarVistasAnuales() {
        var subtitulo = document.getElementById('ventaAnualSubtitulo');

        if (subtitulo) {
            subtituloCashflow = subtitulo.innerHTML;
        }

        colgarVistaAnual('tabVentaCashflowBtn', 'cashflow');
        colgarVistaAnual('tabVentaAcumuladaBtn', 'acumulada');
        colgarVistaAnual('tabVentaBalanceBtn', 'balance');
    }

    function colgarVistaAnual(idBoton, vista) {
        var btn = document.getElementById(idBoton);

        if (!btn) {
            return;
        }

        btn.addEventListener('shown.bs.tab', function() {
            vistaAnual = vista;
            actualizarSubtituloAnual();
            pintarWarningsAnuales();

            if (vista === 'acumulada' && !datosAcumulada) {
                cargarAcumulada();
            } else if (vista === 'balance' && !datosBalance) {
                cargarBalance();
            }

            ajustarStickyHeaders();
        });
    }

    /** El botón Actualizar recarga la vista abierta, no las tres */
    function refrescarVistaAnual() {
        if (vistaAnual === 'acumulada') {
            cargarAcumulada();
        } else if (vistaAnual === 'balance') {
            cargarBalance();
        } else {
            cargarAnalisis();
        }
    }

    function exportarVistaAnual() {
        if (vistaAnual === 'acumulada') {
            exportarExcel('tablaAcumulada', 'Venta_Acumulada');
        } else if (vistaAnual === 'balance') {
            exportarExcel('tablaBalance', 'Venta_Balance');
        } else {
            exportarExcel('tablaAnalisis', 'Analisis_Ventas');
        }
    }

    /**
     * Subtítulo del card: período y base de la vista activa.
     *
     * Es el lugar donde se declara que Acumulada es neta y Balance lleva IVA.
     * Sin eso, tres tablas de importes mensuales una al lado de la otra se leen
     * como comparables y no lo son.
     */
    function actualizarSubtituloAnual() {
        var el = document.getElementById('ventaAnualSubtitulo');

        if (!el) {
            return;
        }

        if (vistaAnual === 'acumulada') {
            el.innerHTML = subtituloAcumulada();
        } else if (vistaAnual === 'balance') {
            el.innerHTML = subtituloBalance();
        } else {
            el.innerHTML = subtituloCashflow;
        }
    }

    function subtituloAcumulada() {
        var anio = datosAcumulada ? datosAcumulada.anio : '';
        var corte = (datosAcumulada && datosAcumulada.dia_corte)
            ? ' &middot; parcial al ' + fechaCorta(datosAcumulada.dia_corte)
            : '';

        return 'Venta <strong>real</strong> acumulada ' +
               (anio ? 'del año ' + anio : 'del año en curso') +
               ' &middot; <strong>neta sin IVA</strong>, en pesos y en dólares' + corte +
               '<i class="fas fa-info-circle ms-1" title="' + TIP_VALUACION + '"></i>';
    }

    function subtituloBalance() {
        var iva = datosBalance ? (datosBalance.alicuota_iva || 0) : 0;
        var periodo = (datosBalance && datosBalance.inicio)
            ? fechaLarga(datosBalance.inicio) + ' al ' + fechaLarga(datosBalance.fin)
            : '1/8 al 31/7';

        return 'Año balance ' + periodo +
               ' &middot; real de los meses cerrados + proyectado &middot; ' +
               '<strong>todo CON IVA' + (datosBalance ? ' ' + formatPercentCorto(iva) : '') +
               '</strong>' +
               '<i class="fas fa-info-circle ms-1" title="' + TIP_BALANCE + '"></i>';
    }

    /** Warnings de la vista anual activa, con el mismo bloque de siempre */
    function pintarWarningsAnuales() {
        var datos = (vistaAnual === 'acumulada')
            ? datosAcumulada
            : ((vistaAnual === 'balance') ? datosBalance : null);

        pintarWarnings('warningsVentaAnual', datos ? datos.warnings : []);
    }

    /* ---------------------------------------------------------------
       VENTA ACUMULADA
       --------------------------------------------------------------- */

    function cargarAcumulada() {
        mostrar('loadingAcumulada', true);
        mostrar('wrapperAcumulada', false);

        pedir('Controller/VentasController.php?action=getVentaAcumulada')
            .then(function(data) {
                datosAcumulada = data;
                generarTablaAcumulada();
                actualizarSubtituloAnual();
                pintarWarningsAnuales();
                mostrar('loadingAcumulada', false);
                mostrar('wrapperAcumulada', true);
                ajustarStickyHeaders();
            })
            .catch(function(error) {
                mostrar('loadingAcumulada', false);
                mostrarError('Error al cargar la venta acumulada: ' + error.message);
            });
    }

    /**
     * Venta real acumulada del año calendario, neta sin IVA, en pesos y en
     * dólares.
     *
     * Las columnas de dólares se muestran sólo si hubo cotizaciones: si la vista
     * RO_V_DOLAR_OFICIAL_BCRA no está disponible, la tabla sale en pesos y el
     * warning de arriba dice por qué, en vez de mostrar una columna entera de
     * guiones.
     */
    function generarTablaAcumulada() {
        var filas = datosAcumulada.filas || [];
        var totales = datosAcumulada.totales;
        var conUsd = !!datosAcumulada.usd_disponible;

        var header =
            '<th class="col-canal">Mes</th>' +
            '<th class="text-end">Venta Neta' +
                '<span class="th-sub">neto s/ IVA</span></th>' +
            '<th class="text-end">Acumulado' +
                '<span class="th-sub">neto s/ IVA</span></th>';

        if (conUsd) {
            header +=
                '<th class="text-center" title="' + TIP_VALUACION + '">T/C' +
                    '<span class="th-sub">cierre del mes</span></th>' +
                '<th class="text-end" title="' + TIP_VALUACION + '">Venta Neta USD' +
                    '<span class="th-sub">neto s/ IVA &middot; a su T/C</span></th>' +
                '<th class="text-end" title="' + TIP_VALUACION + '">Acumulado USD' +
                    '<span class="th-sub">suma de meses valuados</span></th>';
        }

        document.getElementById('acumuladaHeader').innerHTML = header;

        var columnas = conUsd ? 6 : 3;

        if (!filas.length) {
            document.getElementById('acumuladaBody').innerHTML =
                '<tr><td colspan="' + columnas + '" class="text-center text-muted py-4">' +
                'Todavía no hay ningún mes con venta cargada en el año.</td></tr>';
            document.getElementById('acumuladaTotals').innerHTML = '';
            return;
        }

        var html = '';

        filas.forEach(function(fila) {
            // El mes en curso está incompleto: sale del histórico diario y el
            // badge dice hasta qué día llega. Sin eso, el importe más chico se
            // lee como una caída de venta.
            var tip = fila.parcial
                ? 'Mes en curso: días 1 al ' + fila.dias +
                  '. Sale del histórico diario, no de la tabla mensual.'
                : '';

            html += '<tr' + (fila.parcial ? ' class="fila-parcial"' : '') + '>';
            html += '<td class="col-canal fw-semibold">' + fila.label +
                    (fila.parcial
                        ? ' <span class="badge-parcial" title="' + tip + '">parcial al ' +
                          fechaCorta(datosAcumulada.dia_corte) + '</span>'
                        : '') +
                    '</td>';

            html += '<td class="currency"' + (tip ? ' title="' + tip + '"' : '') + '>' +
                    formatCurrency(fila.neto) + '</td>';
            html += '<td class="currency fw-semibold">' + formatCurrency(fila.acumulado) + '</td>';

            if (conUsd) {
                // Un mes sin cotización muestra un guion, no un cero: la
                // diferencia entre "no hay dato" y "el dato es cero".
                html += '<td class="text-center text-muted">' + numeroOGuion(fila.tc) + '</td>';
                html += '<td class="currency">' + usdOGuion(fila.neto_usd) + '</td>';
                html += '<td class="currency fw-semibold">' + usdOGuion(fila.acumulado_usd) + '</td>';
            }

            html += '</tr>';
        });

        document.getElementById('acumuladaBody').innerHTML = html;

        var totalsHtml =
            '<td class="col-canal total-label">TOTAL DEL AÑO</td>' +
            '<td class="currency" title="Neto sin IVA">' + formatCurrency(totales.neto) + '</td>' +
            '<td class="currency" title="Neto sin IVA">' + formatCurrency(totales.neto) + '</td>';

        if (conUsd) {
            // Si algún mes quedó sin cotización, el total en dólares no
            // contiene esos meses y hay que decirlo.
            var tipTotal = totales.meses_sin_tc > 0
                ? 'No incluye ' + totales.meses_sin_tc + ' mes(es) sin cotización.'
                : 'Suma de los meses valuados a su propio tipo de cambio de cierre.';

            totalsHtml +=
                '<td class="text-center text-muted">&mdash;</td>' +
                '<td class="currency" title="' + tipTotal + '">' +
                    usdOGuion(totales.neto_usd) + '</td>' +
                '<td class="currency" title="' + tipTotal + '">' +
                    usdOGuion(totales.neto_usd) + '</td>';
        }

        document.getElementById('acumuladaTotals').innerHTML = totalsHtml;
    }

    /* ---------------------------------------------------------------
       VENTA BALANCE
       --------------------------------------------------------------- */

    function cargarBalance() {
        mostrar('loadingBalance', true);
        mostrar('wrapperBalance', false);

        pedir('Controller/VentasController.php?action=getVentaBalance')
            .then(function(data) {
                datosBalance = data;
                generarTablaBalance();
                actualizarSubtituloAnual();
                pintarWarningsAnuales();
                mostrar('loadingBalance', false);
                mostrar('wrapperBalance', true);
                ajustarStickyHeaders();
            })
            .catch(function(error) {
                mostrar('loadingBalance', false);
                mostrarError('Error al cargar la venta del balance: ' + error.message);
            });
    }

    /**
     * Año balance 1/8 al 31/7: doce meses, real de los meses cerrados más
     * proyectado del resto.
     *
     * TODO CON IVA, y por eso las dos mitades son sumables. El pie muestra el
     * total y cuánto de él es real y cuánto proyectado: un total de balance sin
     * ese desglose no dice qué parte todavía puede cambiar.
     */
    function generarTablaBalance() {
        var filas = datosBalance.filas || [];
        var totales = datosBalance.totales;
        var iva = datosBalance.alicuota_iva || 0;
        var subIva = '<span class="th-sub th-sub-iva">CON IVA ' + formatPercentCorto(iva) + '</span>';

        document.getElementById('balanceHeader').innerHTML =
            '<th class="col-canal">Mes</th>' +
            '<th class="text-center">Origen' +
                '<span class="th-sub">real / proyectado</span></th>' +
            '<th class="text-end">Venta' + subIva + '</th>' +
            '<th class="text-end">Acumulado' + subIva + '</th>';

        if (!filas.length) {
            document.getElementById('balanceBody').innerHTML =
                '<tr><td colspan="4" class="text-center text-muted py-4">' +
                'No hay datos del año balance.</td></tr>';
            document.getElementById('balanceFoot').innerHTML = '';
            return;
        }

        var html = '';

        filas.forEach(function(fila) {
            var real = (fila.origen === 'REAL');

            html += '<tr' + (real ? '' : ' class="fila-proyectada"') + '>';
            html += '<td class="col-canal fw-semibold">' + fila.label + '</td>';
            html += '<td class="text-center">' + badgeOrigen(fila.origen) + '</td>';

            html += '<td class="currency' + (real ? '' : ' cell-with-value') + '">' +
                    formatCurrency(fila.venta) +
                    (fila.estimado
                        ? ' <i class="fas fa-triangle-exclamation text-warning ms-1" ' +
                          'title="El mismo mes del año anterior no tiene datos: mes estimado."></i>'
                        : '') +
                    '</td>';

            html += '<td class="currency fw-semibold">' + formatCurrency(fila.acumulado) + '</td>';
            html += '</tr>';
        });

        document.getElementById('balanceBody').innerHTML = html;

        var foot = '';

        foot += '<tr class="fila-desglose">';
        foot += '<td class="col-canal" colspan="2">Real &mdash; ' +
                totales.meses_real + ' mes(es) cerrado(s)</td>';
        foot += '<td class="currency">' + formatCurrency(totales.real) + '</td>';
        foot += '<td></td>';
        foot += '</tr>';

        foot += '<tr class="fila-desglose">';
        foot += '<td class="col-canal" colspan="2">Proyectado &mdash; ' +
                totales.meses_proyectado + ' mes(es), desde el mes en curso</td>';
        foot += '<td class="currency">' + formatCurrency(totales.proyectado) + '</td>';
        foot += '<td></td>';
        foot += '</tr>';

        foot += '<tr class="fila-total">';
        foot += '<td class="col-canal total-label" colspan="2">TOTAL BALANCE</td>';
        foot += '<td class="currency" title="Con IVA ' + formatPercentCorto(iva) + '">' +
                formatCurrency(totales.venta) + '</td>';
        foot += '<td class="currency" title="Con IVA ' + formatPercentCorto(iva) + '">' +
                formatCurrency(totales.venta) + '</td>';
        foot += '</tr>';

        document.getElementById('balanceFoot').innerHTML = foot;
    }

    function badgeOrigen(origen) {
        if (origen === 'REAL') {
            return '<span class="badge-origen badge-origen-real" ' +
                   'title="Mes cerrado: venta real neta grosada por IVA">Real</span>';
        }

        return '<span class="badge-origen badge-origen-proyectado" ' +
               'title="Venta proyectada, ya con IVA. El mes en curso va siempre acá.">' +
               'Proyectado</span>';
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

                // El balance también proyecta con el índice: si la pestaña ya
                // estaba cargada, quedaría mostrando la venta vieja. La
                // acumulada no, que es todo dato real.
                if (datosBalance) {
                    cargarBalance();
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

            // El controlador de vistas se entera del eje antes de que se
            // dibujen las grillas: es el que decide qué columnas se muestran.
            vistas.usar(datosProyeccion);

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

    function cambiarVista() {
        if (datosProyeccion) {
            generarTablaVenta();
            generarTablaCobranza();
            ajustarStickyHeaders();
        }
    }

    /**
     * Columnas del eje temporal según la vista activa.
     *
     * CADA COLUMNA LLEVA SU RAMA ('dias' o 'meses'). Antes la vista elegía una
     * rama entera y todas las columnas salían de ahí; con la vista Período
     * completo conviven las dos en la misma tabla, así que la rama pasa a ser
     * un dato de la columna y no del estado de la pantalla. Es lo que permite
     * que un mismo bucle dibuje las tres vistas.
     */
    function columnas() {
        var vista = vistas.activa();
        var cols = [];

        if (vista === 'dias' || vista === 'completo') {
            datosProyeccion.dias.forEach(function(d) {
                cols.push({
                    rama: 'dias',
                    clave: d.fecha,
                    label: d.label,
                    feriado: d.feriado_comercio,
                    css: 'day-column'
                });
            });
        }

        if (vista === 'meses' || vista === 'completo') {
            datosProyeccion.meses.forEach(function(m) {
                cols.push({
                    rama: 'meses',
                    clave: m.clave,
                    label: m.label,
                    feriado: false,
                    css: 'month-column'
                });
            });
        }

        return cols;
    }

    /**
     * Importe de una fila de la grilla en una columna.
     *
     * La rama la manda la COLUMNA, así que sirve igual para las tres vistas.
     *
     * @param grilla El bloque completo (venta o cobranza), con sus dos ramas
     * @param clave Canal o clave de fila
     * @param col Columna devuelta por columnas()
     */
    function valorEn(grilla, clave, col) {
        var r = grilla[col.rama];

        return (r && r[clave] && r[clave][col.clave]) || 0;
    }

    /** Total de una columna, de la rama que corresponda */
    function totalEn(grilla, col) {
        var t = grilla['total_' + col.rama];

        return (t && t[col.clave]) || 0;
    }

    /** Subtotal de un canal en una columna */
    function subtotalEn(grilla, canal, col) {
        var s = grilla['subtotal_' + col.rama];

        return (s && s[canal] && s[canal][col.clave]) || 0;
    }

    /* NO HAY neteoEn(). El neteo de cheques adelantados se dibujaba acá, en el
       pie, y pasó a ser una fila del TABLERO (serie VENTAS.NETEO_PRECHEQUEADO).
       Esta pestaña muestra la cobranza proyectada BRUTA y nada más: el neteo
       corrige el cuadro consolidado, que es donde se lee la caja, y mostrarlo
       también acá obligaba a mantener dos lugares que tenían que dar lo mismo.
       El dato sigue viajando en el payload, sin consumidor en esta pantalla. */

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
            // El tooltip abre la participación por canal y es de columnas de
            // MES: en una columna diaria no hay participación que abrir. Se
            // decide por la rama de la columna y no por la vista, así que en la
            // vista Período completo lo llevan sólo las mensuales.
            var tip = (conTooltip && col.rama === 'meses')
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
        periodoHeader.textContent = datosProyeccion.vistas
            ? datosProyeccion.vistas[vistas.activa()].label
            : '';

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
        var venta = datosProyeccion.venta;

        var html = '';

        canales.forEach(function(canal) {
            html += '<tr>';
            html += '<td class="col-canal fw-semibold">' + titulo(canal) + '</td>';

            cols.forEach(function(col) {
                html += celdaValor(valorEn(venta, canal, col), col.feriado);
            });

            html += '</tr>';
        });

        document.getElementById('ventaBody').innerHTML = html;

        var totalsHtml = '<td class="col-canal total-label">TOTAL VENTA</td>';

        cols.forEach(function(col) {
            totalsHtml += celdaValor(totalEn(venta, col), col.feriado);
        });

        document.getElementById('ventaTotals').innerHTML = totalsHtml;
    }

    function generarTablaCobranza() {
        var cols = generarEncabezado('cobHeaderSub', 'cobPeriodoHeader');
        var canales = datosProyeccion.canales;
        var cob = datosProyeccion.cobranza;

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
                    html += celdaValor(valorEn(cob, fila.clave, col), false);
                });

                html += '</tr>';
            });

            // Subtotal del canal
            html += '<tr class="fila-subtotal">';
            html += '<td class="col-canal" colspan="2">Subtotal ' + titulo(canal) + '</td>';
            html += '<td colspan="2"></td>';

            cols.forEach(function(col) {
                html += celdaValor(subtotalEn(cob, canal, col), false);
            });

            html += '</tr>';
        });

        document.getElementById('cobBody').innerHTML = html;

        // Pie: cobranza proyectada BRUTA, y nada más. El neteo de cheques
        // adelantados tenía acá su propia fila y una fila de cobranza neta
        // debajo; las dos se fueron al tablero, que es donde se lee la caja.
        var foot = '<tr class="fila-total">';
        foot += '<td class="col-canal total-label" colspan="4">COBRANZA PROYECTADA</td>';

        cols.forEach(function(col) {
            foot += celdaValor(totalEn(cob, col), false);
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
        pintarWarnings('warningsProyeccion', datosProyeccion.warnings);
    }

    /**
     * Bloque de avisos no fatales, compartido por la proyección y por las
     * vistas anuales: un origen de datos que todavía no existe en el entorno no
     * tiene que caer la pantalla, pero sí decirse.
     */
    function pintarWarnings(idContenedor, warnings) {
        var cont = document.getElementById(idContenedor);

        if (!cont) {
            return;
        }

        var lista = warnings || [];

        if (!lista.length) {
            cont.innerHTML = '';
            return;
        }

        var html = '';

        lista.forEach(function(w) {
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

    /** Importe en dólares. Se distingue del peso por el prefijo, no por el color. */
    function formatUsd(value) {
        var num = parseFloat(value) || 0;

        return 'US$ ' + num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Un valor ausente se pinta como guion y NO como cero.
     * En la venta acumulada eso distingue un mes sin cotización cargada de un
     * mes que efectivamente no vendió nada.
     */
    function usdOGuion(value) {
        if (value === null || value === undefined) {
            return '<span class="text-muted" title="Sin cotización para el mes">&mdash;</span>';
        }

        return formatUsd(value);
    }

    function numeroOGuion(value) {
        if (value === null || value === undefined) {
            return '<span title="Sin cotización para el mes">&mdash;</span>';
        }

        return (parseFloat(value) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** '2026-08-01' -> '01/08/2026'. Se corta el string: nunca new Date(). */
    function fechaLarga(iso) {
        if (!iso) {
            return '';
        }

        var p = String(iso).split('-');

        return p[2] + '/' + p[1] + '/' + p[0];
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

    /** Exporta lo que se ve. Ver Js/tabla-export.js. */
    function exportarExcel(idTabla, nombre) {
        exportarTabla(idTabla, nombre);
    }

})(); // Fin del IIFE
