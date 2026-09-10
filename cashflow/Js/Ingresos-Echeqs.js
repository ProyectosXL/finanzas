/**
 * Echeqs JavaScript
 *
 * Dos sub-pestañas independientes:
 *   Cheques en Cartera       -> cheques de terceros en cartera, con eje temporal
 *   Venta Cobrada Anticipada -> cheques de clientes que pre-chequean, tildables
 *
 * La segunda es LAZY: se pide recién cuando se abre. Depende de un maestro que
 * puede no estar cargado y no tiene por qué demorar la que se abre primero.
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js. Acá no se calcula ninguna fecha ni
 * se decide qué columnas van en cada vista: eso lo resuelve Class/EjeVista.php y
 * viene en el payload.
 *
 * EL GUARDADO DE LAS MARCAS NO ES OPTIMISTA EN SILENCIO. El tilde se pinta
 * enseguida para que la pantalla responda, pero si el POST falla se revierte y
 * se muestra el error: una marca que se ve pero no se guardó desajusta la
 * proyección de Ventas sin que nadie se entere.
 */

(function() {
    'use strict';

    var URL_ECHEQS = 'Controller/EcheqsController.php';

    var datosCartera = null;
    var datosPre = null;
    var prePedido = false;

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    var vistas = null;

    /**
     * El de la sub-pestaña 2. Va SEPARADO del de cartera y no compartido: son
     * dos tablas con dos ejes distintos en pantalla al mismo tiempo, y un solo
     * controlador haría que cambiar de vista en una moviera la otra.
     */
    var vistasPre = null;

    function inicializar() {
        vistas = crearEjeVistas({
            botones: 'vistasEcheqs',
            periodo: 'periodoEcheqs',
            alCambiar: dibujarCartera
        });

        vistasPre = crearEjeVistas({
            botones: 'vistasPre',
            periodo: 'periodoPre',
            alCambiar: dibujarPrechequeado
        });

        // N° de cheque identifica la fila y Cliente es de quién es: son las dos
        // que uno busca cuando ya scrolleó hasta la columna de la fecha.
        crearColumnasFijas({
            tabla: 'tablaEcheqs',
            control: 'colFijasEcheqs',
            clave: 'echeqs_cartera',
            porDefecto: [1, 3]
        });

        // En pre-chequeado la primera columna es el tilde, que es lo que hay
        // que tener siempre a mano, y la cuarta es el cliente.
        crearColumnasFijas({
            tabla: 'tablaPrechequeado',
            control: 'colFijasPre',
            clave: 'echeqs_prechequeado',
            porDefecto: [0, 6]
        });

        conectar('btnRefreshEch', cargarCartera);
        conectar('btnExportEch', exportarCartera);
        conectar('btnRefreshPre', cargarPrechequeado);

        escuchar('busquedaEch', 'keyup', dibujarCartera);
        escuchar('busquedaPre', 'keyup', dibujarPrechequeado);
        escuchar('filtroClientePre', 'change', dibujarPrechequeado);

        conectar('marcarTodosPre', marcarVisibles);

        // La sub-pestaña 2 se pide la primera vez que se abre y no antes.
        var tabPre = document.getElementById('tabPrechequeadoBtn');

        if (tabPre) {
            tabPre.addEventListener('shown.bs.tab', function() {
                if (!prePedido) {
                    cargarPrechequeado();
                }
            });
        }

        cargarCartera();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       SUB-PESTAÑA 1 — CHEQUES EN CARTERA
       ================================================================ */

    function cargarCartera() {
        mostrar('loadingEch', true, 'flex');
        mostrar('wrapperEch', false);

        pedirJson(URL_ECHEQS + '?action=getEcheqsCartera')
            .then(function(data) {
                datosCartera = data;

                // El controlador de vistas se entera del eje nuevo antes de que
                // se dibuje la tabla.
                vistas.usar(datosCartera);

                pintarAvisos();
                pintarKpiCartera();
                dibujarCartera();

                mostrar('loadingEch', false);
                mostrar('wrapperEch', true);
            })
            .catch(function(error) {
                mostrar('loadingEch', false);
                avisar('No se pudieron cargar los cheques en cartera: ' + error.message);
            });
    }

    /**
     * Los tres indicadores miden los tres períodos de las tres vistas, así que
     * cada tarjeta se corresponde con un botón.
     */
    function pintarKpiCartera() {
        var totales = datosCartera.totales || {};
        var vs = datosCartera.vistas || {};

        texto('totalDiasEch', pesos(totales.total_tramo));
        texto('totalMesesEch', pesos(totales.total_meses));
        texto('totalGeneralEch', pesos(totales.total_horizonte));

        texto('rotuloDiasEch', vs.dias ? vs.dias.periodo : '');
        texto('rotuloMesesEch', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneralEch', vs.completo ? vs.completo.periodo : '');

        texto('detalleCarteraEch', (datosCartera.filas || []).length
            + ' cheque(s) de terceros, por fecha de pago');

        mostrar('summarySectionEch', true, 'flex');
    }

    function dibujarCartera() {
        if (!datosCartera || !datosCartera.filas) {
            return;
        }

        var cols = vistas.columnas();
        var filas = filasCarteraVisibles();

        pintarEncabezadoEje(cols);
        pintarFilasCartera(filas, cols);
        pintarTotalesCartera(filas, cols);
    }

    function pintarEncabezadoEje(cols) {
        var html = '';

        cols.forEach(function(col) {
            var esMes = vistas.esMes(col);
            var meta = vistas.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            html += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + escapar(titulo) + '"' : '') + '>'
                + escapar(vistas.rotulo(col)) + '</th>';
        });

        html += '<th class="total-column">Total</th>';

        var cabecera = document.getElementById('ejeHeaderEch');

        if (cabecera) {
            cabecera.textContent = datosCartera.vistas[vistas.activa()].label;
            cabecera.setAttribute('colspan', String(cols.length + 1));
        }

        document.getElementById('ejeSubHeaderEch').innerHTML = html;
    }

    function pintarFilasCartera(filas, cols) {
        var html = '';

        filas.forEach(function(f) {
            html += '<tr>';
            html += '<td class="center"><span class="badge-cobro">' + fecha(f.FECHA_PAGO) + '</span></td>';
            html += '<td class="center">' + numeroCheque(f.N_CHEQUE) + '</td>';
            html += '<td>' + escapar(f.BANCO) + '</td>';
            html += '<td>' + escapar(f.CLIENTE) + subtituloCodigo(f.COD_CLIENTE) + '</td>';
            html += '<td class="currency">' + pesos(f.IMPORTE) + '</td>';

            // Los importes por columna ya vienen resueltos: la regla de "día O
            // mes, nunca las dos" la aplicó el backend, una sola vez.
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(f, col)) || 0;

                html += '<td class="currency ' + (valor !== 0 ? 'cell-with-value' : '') + '">'
                    + (valor !== 0 ? pesos(valor) : '') + '</td>';
            });

            var total = vistas.total(f);

            html += '<td class="currency total-column">' + (total !== 0 ? pesos(total) : '') + '</td>';
            html += '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="' + (6 + cols.length) + '" class="text-center text-muted py-4">'
                 + (datosCartera.filas.length
                        ? 'Ningún cheque coincide con la búsqueda.'
                        : 'No hay cheques de terceros en cartera con fecha de hoy en adelante.')
                 + '</td></tr>';
        }

        document.getElementById('bodyEch').innerHTML = html;
    }

    /**
     * Pie de la tabla.
     *
     * Respeta el buscador, así que se suman las filas que pasan el filtro. Pero
     * se suman los importes YA AGRUPADOS por el backend, sin reinterpretar
     * ninguna fecha: la regla de "día O mes" sigue estando en un solo lugar.
     */
    function pintarTotalesCartera(filas, cols) {
        var html = '<td colspan="4" class="fw-bold text-end">TOTALES</td>';
        var bruto = 0;

        filas.forEach(function(f) {
            bruto += Number(f.IMPORTE) || 0;
        });

        html += '<td class="currency fw-bold">' + pesos(bruto) + '</td>';

        cols.forEach(function(col) {
            var total = 0;

            filas.forEach(function(f) {
                total += Number(vistas.valor(f, col)) || 0;
            });

            html += '<td class="currency ' + (total !== 0 ? 'cell-with-value' : '') + '">'
                + (total !== 0 ? pesos(total) : '') + '</td>';
        });

        var granTotal = 0;

        filas.forEach(function(f) {
            granTotal += vistas.total(f);
        });

        html += '<td class="currency total-column">' + (granTotal !== 0 ? pesos(granTotal) : '') + '</td>';

        document.getElementById('totalesEch').innerHTML = html;
    }

    /** El buscador mira cliente, banco y número de cheque */
    function filasCarteraVisibles() {
        var term = valor('busquedaEch').toLowerCase();

        if (!term) {
            return datosCartera.filas;
        }

        return datosCartera.filas.filter(function(f) {
            return coincide(f, term);
        });
    }

    function coincide(f, term) {
        return String(f.CLIENTE || '').toLowerCase().indexOf(term) !== -1
            || String(f.COD_CLIENTE || '').toLowerCase().indexOf(term) !== -1
            || String(f.BANCO || '').toLowerCase().indexOf(term) !== -1
            || String(f.N_CHEQUE || '').indexOf(term) !== -1;
    }

    function exportarCartera() {
        var tabla = document.getElementById('tablaEcheqs').cloneNode(true);
        var blob = new Blob([tabla.outerHTML], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');

        a.href = url;
        a.download = 'Echeqs_cartera_' + new Date().toISOString().slice(0, 10) + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /* ================================================================
       SUB-PESTAÑA 2 — VENTA COBRADA ANTICIPADA
       ================================================================ */

    function cargarPrechequeado() {
        prePedido = true;

        mostrar('loadingPre', true, 'flex');
        mostrar('wrapperPre', false);
        ocultarLuegoDe('avisoGuardadoPre', 6000);

        pedirJson(URL_ECHEQS + '?action=getEcheqsPrechequeado')
            .then(function(data) {
                datosPre = data;

                // El controlador de vistas se entera del eje nuevo antes de que
                // se dibuje la tabla.
                vistasPre.usar(datosPre);

                pintarAvisos();
                pintarAvisosEjePre();
                pintarFiltroClientes();
                dibujarPrechequeado();

                mostrar('loadingPre', false);
                mostrar('wrapperPre', true);
            })
            .catch(function(error) {
                mostrar('loadingPre', false);
                avisar('No se pudieron cargar los cheques pre-chequeados: ' + error.message);
            });
    }

    /**
     * El desplegable se arma con los clientes PRESENTES en el listado, no con
     * todo el maestro: un filtro que ofrece opciones que no devuelven nada se lee
     * como una pantalla rota.
     */
    function pintarFiltroClientes() {
        var select = document.getElementById('filtroClientePre');

        if (!select) {
            return;
        }

        var elegido = select.value;
        var html = '<option value="">Todos los clientes</option>';

        ((datosPre.resumen && datosPre.resumen.clientes) || []).forEach(function(c) {
            html += '<option value="' + escapar(c.codigo) + '">'
                + escapar(c.nombre || c.codigo) + ' (' + c.cheques + ')</option>';
        });

        select.innerHTML = html;
        select.value = elegido;
    }

    function dibujarPrechequeado() {
        if (!datosPre) {
            return;
        }

        var filas = filasPreVisibles();
        var cols = vistasPre.columnas();

        pintarEncabezadoEjePre(cols);
        pintarFilasPre(filas, cols);
        pintarPiePre(filas, cols);
        pintarKpiPre();
        sincronizarCabecera(filas);
    }

    /**
     * Lo que quedó fuera del eje. Con días de pre-chequeado altos la fecha
     * estimada de venta puede caer antes del inicio del eje, y ahí no hay
     * columna donde ubicar el importe. Se avisa, no se esconde: es el mismo
     * criterio de Ventas::repartirNeteo(), que además es la cuenta que de
     * verdad netea.
     */
    function pintarAvisosEjePre() {
        var cont = document.getElementById('avisosEjePre');

        if (!cont) {
            return;
        }

        var avisos = (datosPre && datosPre.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-0 rounded-0"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.map(escapar).join(' ') + '</small></div>'
            : '';
    }

    function pintarEncabezadoEjePre(cols) {
        var html = '';

        cols.forEach(function(col) {
            var esMes = vistasPre.esMes(col);
            var meta = vistasPre.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            html += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + escapar(titulo) + '"' : '') + '>'
                + escapar(vistasPre.rotulo(col)) + '</th>';
        });

        html += '<th class="total-column">Total</th>';

        var grupo = document.getElementById('grupoEjePre');

        if (grupo && datosPre.vistas) {
            grupo.textContent = datosPre.vistas[vistasPre.activa()].label;
            grupo.setAttribute('colspan', String(cols.length + 1));
        }

        document.getElementById('headerEjePre').innerHTML = html;
    }

    /** Cuántas columnas descriptivas tiene la tabla de pre-chequeado */
    var COLS_DESC_PRE = 10;

    function pintarFilasPre(filas, cols) {
        var html = '';

        filas.forEach(function(f) {
            var heredada = (f.ORIGEN_MARCA === 'cliente');
            var dias = Number(f.DIAS_PRECHEQUEADO) || 0;

            html += '<tr class="' + (f.MARCADO ? 'ech-marcado' : 'ech-sin-marcar') + '"'
                 + ' data-id="' + f.ID_SBA14 + '">';

            html += '<td class="text-center">'
                 + '<input type="checkbox" class="form-check-input ech-marca" '
                 + 'data-id="' + f.ID_SBA14 + '"' + (f.MARCADO ? ' checked' : '') + '>'
                 + '</td>';

            // La fecha estimada de venta es donde cae el importe en la grilla.
            // Va primera y destacada; la del cheque queda al lado como
            // referencia, que es el dato duro de Tango.
            html += '<td class="center"><span class="ech-fecha-estimada" '
                 + 'title="Fecha del cheque menos los días de pre-chequeado del cliente. '
                 + 'Es donde este importe netea la cobranza proyectada de Ventas.">'
                 + fecha(f.FECHA_VENTA_EST) + '</span></td>';

            // Los días efectivos: sin esto, un cliente en cero se ve igual que
            // uno configurado y no hay forma de saber por qué las dos fechas
            // coinciden.
            html += '<td class="text-center">'
                 + (dias > 0
                        ? '<span class="ech-dias-precheq">−' + dias + ' d</span>'
                        : '<span class="ech-sin-dias" title="Este cliente no tiene días de '
                          + 'pre-chequeado cargados, así que el cheque se netea en su propia '
                          + 'fecha. Se configura en Parámetros → Pre-chequeado.">0</span>')
                 + '</td>';

            html += '<td class="center">' + fecha(f.FECHA_CHEQUE) + '</td>';
            html += '<td class="center">' + numeroCheque(f.N_CHEQUE) + '</td>';
            html += '<td>' + escapar(f.BANCO) + '</td>';
            html += '<td>' + escapar(f.CLIENTE) + subtituloCodigo(f.COD_CLIENTE) + '</td>';
            html += '<td class="text-center">' + badgeEstado(f.ESTADO) + '</td>';
            html += '<td class="currency">' + pesos(f.IMPORTE) + '</td>';

            // Un tilde que el usuario no puso y no sabe de dónde salió es peor
            // que no tenerlo: acá se dice de dónde viene cada uno.
            html += '<td class="text-center">'
                 + (heredada
                        ? '<span class="ech-origen-cliente" title="Viene del maestro: este cliente '
                          + 'opera con venta cobrada anticipada, así que sus cheques entran '
                          + 'tildados. Nadie tocó este cheque en particular.">del cliente</span>'
                        : '<span class="ech-origen-cheque" title="'
                          + escapar(detalleMarca(f)) + '">'
                          + (f.MARCADO ? 'tildado a mano' : 'destildado a mano') + '</span>')
                 + '</td>';

            // Los importes por columna ya vienen resueltos del backend, sobre
            // la fecha estimada de venta.
            cols.forEach(function(col) {
                var v = Number(vistasPre.valor(f, col)) || 0;

                html += '<td class="currency ' + (v !== 0 ? 'cell-with-value' : '') + '">'
                    + (v !== 0 ? pesos(v) : '') + '</td>';
            });

            var total = vistasPre.total(f);

            html += '<td class="currency total-column">'
                + (total !== 0 ? pesos(total) : '') + '</td>';

            html += '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="' + (COLS_DESC_PRE + cols.length + 1) + '" '
                 + 'class="text-center text-muted py-4">' + mensajeVacio() + '</td></tr>';
        }

        document.getElementById('bodyPre').innerHTML = html;

        document.querySelectorAll('.ech-marca').forEach(function(c) {
            c.addEventListener('change', function() {
                marcar([parseInt(c.dataset.id, 10)], c.checked, [c]);
            });
        });
    }

    /**
     * El mensaje del listado vacío distingue los tres motivos. "Sin clientes
     * configurados" no es lo mismo que "no hay cheques", y ninguno de los dos es
     * "el filtro no encontró nada".
     */
    function mensajeVacio() {
        if (!datosPre.filas.length) {
            return (datosPre.resumen && datosPre.resumen.cheques === 0
                    && (!datosPre.resumen.clientes || !datosPre.resumen.clientes.length))
                ? 'No hay clientes configurados. Cargalos en Parámetros → Pre-chequeado.'
                : 'Los clientes configurados no tienen cheques vivos con fecha de hoy en adelante.';
        }

        return 'Ningún cheque coincide con el filtro.';
    }

    function pintarPiePre(filas, cols) {
        var porEstado = {};
        var totalVisible = 0;
        var marcadoVisible = 0;

        filas.forEach(function(f) {
            var importe = Number(f.IMPORTE) || 0;

            totalVisible += importe;

            if (!f.MARCADO) {
                return;
            }

            marcadoVisible += importe;

            if (!porEstado[f.ESTADO]) {
                porEstado[f.ESTADO] = { cheques: 0, importe: 0 };
            }

            porEstado[f.ESTADO].cheques++;
            porEstado[f.ESTADO].importe += importe;
        });

        // El desglose por estado es lo que hace verificable el punto abierto:
        // los cheques que ya salieron de cartera netean sin que ninguna fila del
        // tablero los sume.
        var detalle = Object.keys(porEstado).sort().map(function(e) {
            return '<span class="ech-estado-total">' + badgeEstado(e) + ' '
                + porEstado[e].cheques + ' cheque(s) · ' + pesos(porEstado[e].importe)
                + '</span>';
        }).join(' ');

        // La grilla del pie suma SOLO los cheques marcados: son los únicos que
        // netean. Mostrar ahí el total del listado haría creer que se resta
        // también lo destildado.
        var porColumna = cols.map(function(col) {
            var total = 0;

            filas.forEach(function(f) {
                if (f.MARCADO) {
                    total += Number(vistasPre.valor(f, col)) || 0;
                }
            });

            return total;
        });

        var granTotal = 0;

        filas.forEach(function(f) {
            if (f.MARCADO) {
                granTotal += vistasPre.total(f);
            }
        });

        var celdasEje = porColumna.map(function(t) {
            return '<td class="currency ' + (t !== 0 ? 'cell-with-value' : '') + '">'
                + (t !== 0 ? pesos(t) : '') + '</td>';
        }).join('')
            + '<td class="currency total-column">'
            + (granTotal !== 0 ? pesos(granTotal) : '') + '</td>';

        var vacias = cols.map(function() { return '<td></td>'; }).join('') + '<td></td>';
        var anchoTotal = COLS_DESC_PRE + cols.length + 1;

        document.getElementById('footPre').innerHTML =
            '<tr class="ech-fila-total">' +
                '<td colspan="8" class="fw-bold text-end">MARCADO (visible)</td>' +
                '<td class="currency fw-bold">' + pesos(marcadoVisible) + '</td>' +
                '<td></td>' +
                celdasEje +
            '</tr>' +
            '<tr>' +
                '<td colspan="8" class="text-end text-muted">Total del listado visible</td>' +
                '<td class="currency text-muted">' + pesos(totalVisible) + '</td>' +
                '<td></td>' +
                vacias +
            '</tr>' +
            (detalle
                ? '<tr><td colspan="' + anchoTotal + '" class="ech-detalle-estados">'
                    + detalle + '</td></tr>'
                : '');
    }

    function pintarKpiPre() {
        var r = datosPre.resumen || {};
        var porEstado = r.por_estado || {};
        var enCartera = porEstado.C ? porEstado.C.importe : 0;
        var fuera = 0;
        var chequesFuera = 0;

        Object.keys(porEstado).forEach(function(e) {
            if (e !== 'C') {
                fuera += porEstado[e].importe;
                chequesFuera += porEstado[e].cheques;
            }
        });

        texto('totalMarcadoPre', pesos(r.importe_marcado));
        texto('detalleMarcadoPre', (r.marcados || 0) + ' de ' + (r.cheques || 0) + ' cheques marcados');
        texto('totalListadoPre', pesos(r.importe_total));
        texto('detalleListadoPre', (r.cheques || 0) + ' cheque(s) en el listado');
        texto('totalEnCarteraPre', pesos(enCartera));
        texto('totalFueraCarteraPre', pesos(fuera));
        texto('detalleFueraCarteraPre', chequesFuera + ' cheque(s) ya aplicados o depositados');

        mostrar('summaryPreEch', true, 'flex');
    }

    /** El filtro por cliente y el buscador de texto se combinan */
    function filasPreVisibles() {
        var cliente = valor('filtroClientePre');
        var term = valor('busquedaPre').toLowerCase();

        return datosPre.filas.filter(function(f) {
            if (cliente && f.COD_CLIENTE !== cliente) {
                return false;
            }

            return !term || coincide(f, term);
        });
    }

    /* ---------------- Marcado ---------------- */

    /**
     * El checkbox del encabezado afecta SÓLO LO VISIBLE según el filtro actual, y
     * dice cuántas filas va a tocar antes de hacerlo: destildar doscientos
     * cheques sin querer no se deshace de a uno.
     */
    function marcarVisibles() {
        var cabecera = document.getElementById('marcarTodosPre');
        var destino = cabecera.checked;
        var filas = filasPreVisibles().filter(function(f) {
            return !!f.MARCADO !== destino;
        });

        if (!filas.length) {
            sincronizarCabecera(filasPreVisibles());
            return;
        }

        var accion = destino ? 'Tildar ' : 'Destildar ';

        if (!confirm(accion + filas.length + ' cheque(s) por '
                + pesosPlano(sumar(filas)) + '?')) {
            sincronizarCabecera(filasPreVisibles());
            return;
        }

        marcar(filas.map(function(f) { return f.ID_SBA14; }), destino, []);
    }

    /**
     * Manda las marcas al servidor. En UNA sola llamada, aunque sean doscientas:
     * el servidor las aplica en una transacción, así que o entran todas o no
     * entra ninguna.
     *
     * @param {Array} ids Ids de SBA14
     * @param {boolean} destino Estado al que van
     * @param {Array} checkboxes Checkboxes a revertir si el POST falla
     */
    function marcar(ids, destino, checkboxes) {
        if (!ids.length) {
            return;
        }

        checkboxes.forEach(function(c) { c.disabled = true; });

        pedirJson(URL_ECHEQS + '?action=marcarCheques', { ids: ids, marcado: destino })
            .then(function(data) {
                // Se recarga en vez de parchear en memoria: el servidor devuelve
                // el estado efectivo y puede haber rechazado alguno de los ids
                // por quedar fuera del listado.
                texto('avisoGuardadoPre', data.tocados + ' cheque(s) '
                    + (destino ? 'tildados' : 'destildados') + '. Ventas ya usa este neteo.');
                mostrar('avisoGuardadoPre', true, 'inline-block');

                cargarPrechequeado();
            })
            .catch(function(error) {
                // Reversión: la pantalla vuelve a lo que el servidor tiene
                // guardado. Dejar el tilde puesto haría creer que se guardó.
                checkboxes.forEach(function(c) {
                    c.checked = !destino;
                    c.disabled = false;
                });

                avisar('No se pudo guardar la marca, así que se dejó como estaba: '
                    + error.message);
            });
    }

    /**
     * El checkbox del encabezado refleja lo visible: tildado si está todo
     * tildado, indeterminado si hay de los dos.
     */
    function sincronizarCabecera(filas) {
        var cabecera = document.getElementById('marcarTodosPre');

        if (!cabecera) {
            return;
        }

        var marcados = filas.filter(function(f) { return !!f.MARCADO; }).length;

        cabecera.checked = (filas.length > 0 && marcados === filas.length);
        cabecera.indeterminate = (marcados > 0 && marcados < filas.length);
        cabecera.title = filas.length
            ? (cabecera.checked ? 'Destildar los ' : 'Tildar los ') + filas.length
              + ' cheque(s) que se están viendo'
            : 'No hay cheques visibles';
    }

    function sumar(filas) {
        return filas.reduce(function(acum, f) {
            return acum + (Number(f.IMPORTE) || 0);
        }, 0);
    }

    /* ================================================================
       AVISOS Y UTILIDADES
       ================================================================ */

    /**
     * Los avisos no son decoración: son lo que evita leer un cero como si fuera
     * un dato. Se juntan los de las dos sub-pestañas en un solo bloque arriba.
     */
    function pintarAvisos() {
        var avisos = []
            .concat((datosCartera && datosCartera.warnings) || [])
            .concat((datosPre && datosPre.avisos) || []);

        var cont = document.getElementById('avisosEcheqs');

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3">'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + '<small><ul class="mb-0 ps-3">'
                + avisos.map(function(a) { return '<li>' + escapar(a) + '</li>'; }).join('')
                + '</ul></small></div>'
            : '';
    }

    function badgeEstado(estado) {
        var titulos = {
            'C': 'En cartera: el cheque todavía está en la casa y suma al disponible',
            'A': 'Aplicado: ya salió de cartera, depositado en el banco o endosado a un proveedor'
        };

        return '<span class="ech-estado ech-estado-' + escapar(estado) + '" title="'
            + escapar(titulos[estado] || 'Estado de Tango') + '">' + escapar(estado) + '</span>';
    }

    function detalleMarca(f) {
        var quien = f.MARCA_USUARIO || 'sin usuario (todavía no hay login)';

        return (f.MARCADO ? 'Tildado' : 'Destildado') + ' por ' + quien
            + (f.MARCA_FECHA ? ' el ' + fechaHora(f.MARCA_FECHA) : '');
    }

    function subtituloCodigo(codigo) {
        return codigo ? '<div class="ech-subtitulo">' + escapar(codigo) + '</div>' : '';
    }

    /**
     * N_CHEQUE es float(53) en Tango. Se muestra sin decimales y sin separador de
     * miles: es un identificador, no una cantidad, y '1.23457E+7' es un bug
     * visible.
     */
    function numeroCheque(n) {
        var v = parseInt(n, 10);

        return isNaN(v) ? '—' : String(v);
    }

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function escuchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener(evento, fn);
        }
    }

    function ocultarLuegoDe(id, ms) {
        var el = document.getElementById(id);

        if (!el || el.style.display === 'none') {
            return;
        }

        setTimeout(function() { mostrar(id, false); }, ms);
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? String(el.value) : '';
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = v;
        }
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function pesos(v) {
        var n = parseFloat(v) || 0;

        return (n < 0 ? '<span class="text-danger">' : '') + pesosPlano(n)
            + (n < 0 ? '</span>' : '');
    }

    function pesosPlano(v) {
        return '$ ' + (parseFloat(v) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fecha(v) {
        if (!v) {
            return '—';
        }

        var p = String(v).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(v);
    }

    function fechaHora(v) {
        var s = String(v);

        return fecha(s) + (s.length > 10 ? (' ' + s.slice(11, 16)) : '');
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function avisar(mensaje) {
        console.error(mensaje);
        alert(mensaje);
    }

})(); // Fin del IIFE
