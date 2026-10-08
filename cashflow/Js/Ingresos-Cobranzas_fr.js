/**
 * Ingresos - Cobranzas FR JavaScript
 * Con soporte para Resumen (predeterminado) y Detalle Facturas (aperturado por
 * comprobante) y doble matriz: Real a Cobrar vs Pendientes Proyectados (con
 * cálculo de PPP).
 *
 * "Detalle Facturas" es el nombre de cara al usuario; el identificador interno
 * sigue siendo `deepdive` y el controller sigue recibiendo `type=deepdive`.
 * Renombrar el contrato no habría cambiado nada en pantalla y habría tocado el
 * endpoint, el controller y las pruebas.
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

        // COD_CLI y RAZON_SOC fijas por defecto, en Resumen y en Detalle
        // Facturas: con veintiocho columnas de días a la derecha, sin ellas no
        // se ve de quién es el número que uno está mirando. Es la misma tabla
        // en los dos modos, así que un solo control las cubre.
        // La clave de localStorage cambió con la columna Tipo. Los índices
        // guardados se corrieron un lugar, y un `[0, 1, 2]` viejo dejaría
        // fijada FECHA —que en Resumen ni se muestra—: la validación de
        // columnas-fijas.js descarta los índices que ya no existen, pero el 2
        // sigue existiendo y apunta a otra cosa. Cambiar la clave descarta la
        // preferencia vieja, que es lo correcto: era sobre otra tabla.
        crearColumnasFijas({
            tabla: 'tablaCobranzasFR',
            control: 'colFijasCob',
            clave: 'cobranzas_fr.sin_tipo',
            porDefecto: [0, 1]
        });

        // La tabla abre por fecha de cobro ascendente: lo más antiguo arriba,
        // que es lo primero que hay que mirar de una cartera pendiente. Sin
        // esto abría en el orden en que vino del backend.
        //
        // 'cobro' es el data-orden-nombre del <th>, no su rótulo: el rótulo
        // cambia con la solapa y el default tiene que valer en las dos.
        //
        // Sólo aplica en Detalle Facturas, y no porque acá se diga: en Resumen
        // esa columna está oculta por CSS -la fila es un cliente con facturas
        // que se cobran en fechas distintas- y tabla-orden.js no ordena por una
        // columna que no se ve.
        //
        // La clave de la preferencia sigue siendo el id de la tabla, así que
        // quien ya tenga un orden elegido a mano lo conserva y este default no
        // se le aplica.
        crearOrdenTabla({
            tabla: 'tablaCobranzasFR',
            porDefecto: { columna: 'cobro', dir: 'asc' }
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

        conectarFiltroEmision();

        // Ver excluidos no vuelve al servidor: las filas excluidas ya llegaron,
        // aparte, y no suman en nada. Sólo cambia qué se dibuja.
        var chkExcluidos = document.getElementById('verExcluidosCob');
        if (chkExcluidos) {
            chkExcluidos.addEventListener('change', function() {
                generarTabla();
                pintarExcluidos();
                filtrarTabla();
            });
        }

        cargarDatos();
    }

    /* ================================================================
       FILTRO POR FECHA DE EMISIÓN

       Es SERVER-SIDE: los dos extremos se mandan como parámetros y el
       controller filtra los items antes de EjeVista. Si se filtrara acá
       escondiendo filas, las columnas del eje, el pie de totales y las
       tarjetas de indicadores seguirían mostrando el total sin filtrar.
       ================================================================ */

    function filtroEmision() {
        var desde = document.getElementById('fechaDesdeCob');
        var hasta = document.getElementById('fechaHastaCob');

        return {
            desde: desde && desde.value ? desde.value : '',
            hasta: hasta && hasta.value ? hasta.value : ''
        };
    }

    function conectarFiltroEmision() {
        var desde = document.getElementById('fechaDesdeCob');
        var hasta = document.getElementById('fechaHastaCob');
        var limpiar = document.getElementById('btnLimpiarFechasCob');

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
     * algo que limpiar.
     *
     * El `max` y el `min` son una comodidad del navegador, no una garantía: el
     * rango se valida de nuevo en el servidor, que es el único que puede.
     */
    function acotarExtremos() {
        var desde = document.getElementById('fechaDesdeCob');
        var hasta = document.getElementById('fechaHastaCob');
        var limpiar = document.getElementById('btnLimpiarFechasCob');
        var f = filtroEmision();

        if (desde) desde.max = f.hasta;
        if (hasta) hasta.min = f.desde;
        if (limpiar) limpiar.disabled = !f.desde && !f.hasta;
    }

    /** El filtro aplicado, al lado del rótulo del período */
    function pintarFiltroPeriodo() {
        var el = document.getElementById('filtroPeriodoCob');

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

    /**
     * Resumen o Detalle Facturas.
     *
     * Son sub-solapas anidadas (`nav nav-tabs`) y no un `btn-group`, así que
     * el estado activo es la clase `active` del `nav-link`. Lo que no cambió
     * es el flujo: `modoVista` sigue siendo `'resumen'` / `'deepdive'` y el
     * controller sigue recibiendo `type=deepdive`.
     */
    function cambiarModo(modo) {
        if (modo === modoVista) return;
        modoVista = modo;

        var btnResumen = document.getElementById('btnVistaResumenCob');
        var btnDeepDive = document.getElementById('btnVistaDeepDiveCob');

        if (btnResumen) btnResumen.classList.toggle('active', modo === 'resumen');
        if (btnDeepDive) btnDeepDive.classList.toggle('active', modo === 'deepdive');

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
            if (titulo) titulo.innerHTML = 'Cobranzas Franquicias &mdash; <span class="text-warning-emphasis">Pendientes Proyectados</span>';
            if (subtitulo) subtitulo.textContent = 'Facturas pendientes calculadas con Plazo Promedio de Pago (PPP)';
            if (thCobro) thCobro.textContent = 'F. Prob. Cobro';
            if (thImporteNeto) thImporteNeto.textContent = 'Importe Neto Proy.';
        } else {
            if (titulo) titulo.innerHTML = 'Cobranzas Franquicias &mdash; <span class="text-success">Real a Cobrar</span>';
            if (subtitulo) subtitulo.textContent = 'Propuestas de pago confirmadas por fecha de cobro';
            if (thCobro) thCobro.textContent = 'Cobro';
            if (thImporteNeto) thImporteNeto.textContent = 'Importe Neto';
        }

        cargarDatos();
    }

    function cargarDatos() {
        mostrarCargando(true);

        var f = filtroEmision();
        var url = 'Controller/IngresosController.php?action=getCobranzasFR'
            + '&type=' + encodeURIComponent(modoVista)
            + '&origen=' + encodeURIComponent(modoOrigen)
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

                    // El controlador de vistas se entera del eje nuevo antes de
                    // que se dibuje la tabla.
                    vistas.usar(datosCobranzas);

                    generarTabla();
                    calcularResumenes();
                    pintarAvisos();
                    pintarExcluidos();
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
        var cont = document.getElementById('avisosCob');

        if (!cont) {
            return;
        }

        var avisos = (datosCobranzas && datosCobranzas.warnings) || [];

        cont.innerHTML = (avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.join(' ') + '</small></div>'
            : '') + avisosUniverso();
    }

    /**
     * Los avisos del universo de franquicias, cada uno con su nivel y en su
     * propio cartel: el directorio caído es una atención -los números pueden
     * estar de más- y las facturas que quedan afuera son informativas -es la
     * regla-. En el mismo cartel amarillo que el resto no se distinguirían.
     * Cada uno nombra clientes, así que van separados y no en un solo párrafo.
     */
    function avisosUniverso() {
        var lista = (datosCobranzas && datosCobranzas.avisos_universo) || [];

        return lista.map(function(a) {
            var info = a.nivel === 'info';

            return '<div class="alert alert-' + (info ? 'info' : 'warning') + ' py-2 px-3 mb-3"><small>'
                + '<i class="fas fa-' + (info ? 'circle-info' : 'triangle-exclamation') + ' me-1"></i>'
                + escaparAttr(a.texto) + '</small></div>';
        }).join('');
    }

    /* ================================================================
       CLIENTES EXCLUIDOS A MANO

       Sus facturas llegan APARTE, en `filas_excluidas`: el backend arma el
       payload sin ellas, así que el eje, las tarjetas de arriba
       (`totales`) y el pie -que suma `filas`, ver filasFiltradas()- no las
       ven nunca. "Ver excluidos" sólo decide si se dibujan, atenuadas. Es la
       forma de que no puedan sumar ni por error: no están en la lista que
       se suma.
       ================================================================ */

    function verExcluidos() {
        var chk = document.getElementById('verExcluidosCob');

        return !!(chk && chk.checked);
    }

    /** Las filas que van a la tabla: las que se cobran, y las excluidas si se piden */
    function filasDibujadas() {
        var filas = datosCobranzas.filas || [];

        return verExcluidos() ? filas.concat(datosCobranzas.filas_excluidas || []) : filas;
    }

    /** El ícono de una fila excluida: motivo, quién y cuándo (Js/auditoria.js) */
    function marcaExcluido(item) {
        if (!item.EXCLUIDO) {
            return '';
        }

        var t = 'Cliente excluido de Cobranzas Franquicias: esta fila no suma en nada.\n'
            + 'Motivo: ' + (item.MOTIVO_EXCLUSION || 'sin motivo registrado') + '\n'
            + Auditoria.texto({ alta: { usuario: item.EXCLUSION_USUARIO, fecha: item.EXCLUSION_FECHA } });

        return ' <i class="fas fa-circle-info text-danger cob-marca-excluido" title="'
            + escaparAttr(t) + '"></i>';
    }

    /**
     * Cuánta plata está excluida, y con qué motivos.
     *
     * SE DICE AUNQUE NO SE VEA, y sobre todo por eso: los excluidos están
     * escondidos por defecto, así que sin este cartel la única forma de notar
     * que falta un importe sería acordarse de prender el interruptor. Es el
     * mismo aviso que IngresosProvider deja en el tablero.
     */
    function pintarExcluidos() {
        var cont = document.getElementById('excluidosCob');

        if (!cont) {
            return;
        }

        var e = (datosCobranzas && datosCobranzas.excluidos) || {};

        if (!e.facturas) {
            cont.style.display = 'none';
            cont.innerHTML = '';
            return;
        }

        var motivos = (e.motivos || []).slice(0, 5);
        var mas = (e.motivos || []).length - motivos.length;

        cont.innerHTML = '<small><i class="fas fa-ban me-1"></i>'
            + '<strong>' + e.facturas + ' factura(s) de ' + e.clientes + ' cliente(s) por '
            + formatCurrency(e.importe) + '</strong> están excluidas de Cobranzas Franquicias: '
            + 'esa plata no entra en esta pestaña ni en el tablero.'
            + (motivos.length
                ? ' Motivos: ' + motivos.map(escaparAttr).join('; ') + (mas > 0 ? '; y ' + mas + ' más.' : '.')
                : '')
            + (verExcluidos()
                ? ' Se ven en la tabla, atenuadas y sin sumar.'
                : ' Prendé <em>Ver excluidos</em> para revisarlas. Se excluyen y se vuelven a '
                    + 'incluir en Parámetros → Cobranzas.')
            + '</small>';

        cont.style.display = '';
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

        // La fila de arriba ya no lleva la leyenda de la vista -la dicen los
        // botones- sino los totales, y esos los pinta generarFilaTotales().
        headerRowSub.innerHTML = headerHTML;
    }

    function generarFilasDatos() {
        var tableBody = document.getElementById('tableBodyCob');
        var cols = vistas.columnas();
        var html = '';

        filasDibujadas().forEach(function(item) {
            var esProy = (item.TIPO_REGISTRO === 'PROYECCION');
            var clases = [];

            // Un cliente excluido a mano se ve atenuado: está para revisarlo,
            // no para sumarlo. Ver filasDibujadas().
            if (item.EXCLUIDO) {
                clases.push('fila-excluida');
            }

            if (esProy) {
                clases.push('fila-proyeccion');
            }

            // Una factura vencida se distingue en toda la fila: su importe está
            // en la columna de hoy por ser el primer día del eje, no porque se
            // estime cobrarla hoy. Ver Ingresos::ubicarCobroVencido().
            if (item.VENCIDA) {
                clases.push('fila-vencida');
            }

            html += '<tr class="' + clases.join(' ') + '">';

            // El badge REAL/PROYECCIÓN se fue con la columna Tipo: la solapa
            // activa ya dice cuál es el origen. Lo que sí distinguía —el color
            // de la fila y el PPP con el que se proyectó— sigue acá, sobre
            // COD_CLI.
            html += '<td title="' + escaparAttr(tituloOrigen(item, esProy)) + '">'
                + '<strong>' + escaparAttr(item.COD_CLI || '') + '</strong>'
                + marcaManual(item) + marcaVencida(item) + marcaExcluido(item) + '</td>';

            // El nombre se recorta con puntos suspensivos (.col-texto) para que
            // la fila sea una sola línea. El title lo devuelve completo: lo que
            // se recorta se puede pedir, no se pierde.
            var razon = item.RAZON_SOC || '';

            html += '<td class="col-texto" title="' + escaparAttr(razon) + '">'
                + escaparAttr(razon) + '</td>';
            
            // Columnas ocultables en resumen
            html += `<td class="center col-detail">${formatDate(item.FECHA)}</td>`;
            html += `<td class="center col-detail">${item.T_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.N_COMP || ''}</td>`;
            html += `<td class="center col-detail">${item.Desc || ''}</td>`;
            html += `<td class="center col-detail">${item.Dias || 0}</td>`;
            
            // Columnas siempre visibles
            html += `<td class="currency">${formatCurrency(item.importe_bruto)}</td>`;
            html += `<td class="currency fw-bold">${formatCurrency(item.importe_neto)}</td>`;
            
            html += celdaCobro(item, esProy);

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

        conectarEdicionFecha();
    }

    /**
     * De dónde sale la fila, en el `title` de COD_CLI.
     *
     * Es lo que quedó del badge de la columna Tipo. En una proyección lo que
     * importa es el PPP con el que se calculó la fecha, que es un promedio y no
     * un dato del comprobante: sin eso, la fecha de cobro no se puede auditar.
     */
    function tituloOrigen(item, esProy) {
        if (!esProy) {
            return 'Cobranza real: sale de una propuesta de pago aceptada.';
        }

        return item.PPP
            ? 'Proyección con el PPP del cliente: ' + item.PPP + ' días.'
            : 'Proyección.';
    }

    /* ================================================================
       FECHA DE COBRO MANUAL

       La fecha de cobro proyectada sale de FECHA_EMIS + PPP del cliente, que
       es un promedio. Cuando alguien ya sabe la fecha de una factura puntual,
       la carga acá y esa fecha manda.

       Es editable SÓLO en Pendientes Proyectados → Detalle Facturas, y no en
       Resumen: en Resumen la fila es un cliente y no un comprobante, así que
       no hay a qué comprobante atarle la fecha. En Resumen se muestra un
       indicador de que alguna de sus facturas la tiene.
       ================================================================ */

    /** Si esta vista es la de la fecha de cobro por comprobante */
    function vistaEditable() {
        return modoOrigen === 'proyectado' && modoVista === 'deepdive';
    }

    /** Si la fecha de cobro se puede editar: esta vista, y con permiso (Js/permisos.js) */
    function editable() {
        return vistaEditable() && Permisos.puedeEditar(document.querySelector('.tab-cobranzas_fr'));
    }

    /** El indicador del Resumen: este cliente tiene alguna fecha cargada a mano */
    function marcaManual(item) {
        if (vistaEditable() || !item.FECHA_MANUAL) {
            return '';
        }

        return ' <i class="fas fa-hand-pointer text-primary cob-marca-manual" '
            + 'title="Alguna factura de este cliente tiene la fecha de cobro cargada a mano, '
            + 'así que no sale del PPP. El detalle está en Detalle Facturas."></i>';
    }

    /**
     * El indicador del Resumen: este cliente tiene alguna factura vencida.
     *
     * Las dos marcas dicen "alguna", no "todas": la repone
     * EjeVista::marcarAlguna() en el controller, porque el agrupado descarta
     * los campos que difieren dentro del grupo.
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

    function celdaCobro(item, esProy) {
        // La fecha de una factura excluida es de sólo lectura: no se cobra
        // por este circuito, y pactarle una fecha no movería nada.
        if (!editable() || item.EXCLUIDO) {
            // Una vencida dice cuál era su fecha original: está dibujada en hoy
            // por ser el primer día del eje. Mismo badge que Exportaciones
            // Tasky, que es de donde sale el criterio.
            var vencida = item.VENCIDA ? badgeVencida(item) : '';

            // data-orden con la fecha cruda: el texto del badge de una vencida
            // es "Vencida 03/09/2026" y no se puede interpretar como fecha, así
            // que sin esto la columna se ordenaría como texto. Ver
            // Js/tabla-orden.js.
            if (vencida) {
                return '<td class="center" data-orden="' + escaparAttr(item.Cobro || '') + '">'
                    + vencida + '</td>';
            }

            var badge = esProy ? 'badge-proyeccion' : 'badge-cobro';

            // Una fecha cargada a mano dice quién y cuándo (Js/auditoria.js)
            return '<td class="center" data-orden="' + escaparAttr(item.Cobro || '') + '"'
                + (item.FECHA_MANUAL ? ' title="' + Auditoria.titulo({ usuario: item.MANUAL_USUARIO,
                    fecha: item.MANUAL_FECHA }) + '"' : '') + '>'
                + '<span class="' + badge + '">' + formatDate(item.Cobro) + '</span></td>';
        }

        var manual = !!item.FECHA_MANUAL;
        var titulo = manual
            ? 'Fecha cargada a mano. Los días y el descuento se recalculan sobre ella.\n'
                + Auditoria.titulo({ usuario: item.MANUAL_USUARIO, fecha: item.MANUAL_FECHA })
            : 'Calculada como fecha de emisión + PPP del cliente. Se puede pisar.';

        // Vencida: el input muestra dónde quedó ubicada, así que el title es el
        // único lugar donde cabe decir cuál era la fecha que venció.
        if (item.VENCIDA) {
            titulo = 'Vencida: la fecha ' + (manual ? 'pactada' : 'probable') + ' era el '
                + formatDate(item.COBRO_ORIGINAL) + ' y ya pasó. '
                + (manual
                    ? 'Se respeta tal cual porque la cargó una persona.'
                    : 'El importe se muestra en el primer día del eje.');
        }

        // El `min` en hoy es una comodidad del navegador, no una garantía: el
        // endpoint valida la fecha de nuevo. Ver IngresosController.
        return '<td class="center cob-celda-cobro' + (manual ? ' cob-fecha-manual' : '') + '">'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +     '<input type="date" class="form-control form-control-sm cob-input-fecha" '
            +         'value="' + (item.Cobro || '') + '" min="' + hoyISO() + '" '
            +         'title="' + titulo + '" '
            +         'data-tcomp="' + escaparAttr(item.T_COMP) + '" '
            +         'data-ncomp="' + escaparAttr(item.N_COMP) + '" '
            +         'data-cod="' + escaparAttr(item.COD_CLI) + '">'
            +     (manual
                    ? '<button class="btn btn-outline-secondary cob-btn-volver" type="button" '
                        + 'title="Volver a la fecha calculada con el PPP">'
                        + '<i class="fas fa-rotate-left"></i></button>'
                    : '')
            + '</div>'
            + '</td>';
    }

    /**
     * El badge de una factura vencida, con la fecha que venció en el title.
     *
     * Sin COBRO_ORIGINAL no se puede decir cuál era la fecha, y un badge que
     * dijera "Vencida" sin fecha no agrega nada sobre el color de la fila. Es
     * el caso del Resumen, donde la fila es un cliente: ahí la marca va al lado
     * del código y el detalle queda para Detalle Facturas.
     */
    function badgeVencida(item) {
        if (!item.COBRO_ORIGINAL) {
            return '';
        }

        var pactada = !!item.FECHA_MANUAL;

        return '<span class="badge-vencida-exp" title="Fecha de cobro '
            + (pactada ? 'pactada' : 'probable') + ' original: '
            + formatDate(item.COBRO_ORIGINAL) + '. Vencida sin cobrar: '
            + (pactada
                ? 'se respeta tal cual porque la cargó una persona'
                : 'se ubica en el primer día del eje') + '">'
            + '<i class="fas fa-triangle-exclamation me-1"></i>Vencida '
            + formatDate(item.COBRO_ORIGINAL) + '</span>';
    }

    function conectarEdicionFecha() {
        if (!editable()) {
            return;
        }

        document.querySelectorAll('#tableBodyCob .cob-input-fecha').forEach(function(inp) {
            inp.addEventListener('change', function() {
                guardarFechaManual(inp);
            });
        });

        document.querySelectorAll('#tableBodyCob .cob-btn-volver').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var inp = btn.closest('.input-group').querySelector('.cob-input-fecha');

                borrarFechaManual(inp);
            });
        });
    }

    function guardarFechaManual(inp) {
        if (!inp.value) {
            return;
        }

        pedirFecha('saveFechaCobroManual', {
            cod_cliente: inp.getAttribute('data-cod'),
            t_comp: inp.getAttribute('data-tcomp'),
            n_comp: inp.getAttribute('data-ncomp'),
            fecha_cobro: inp.value
        }, 'Fecha de cobro guardada.');
    }

    function borrarFechaManual(inp) {
        pedirFecha('deleteFechaCobroManual', {
            t_comp: inp.getAttribute('data-tcomp'),
            n_comp: inp.getAttribute('data-ncomp')
        }, 'La fecha vuelve a calcularse con el PPP del cliente.');
    }

    /**
     * Guarda o borra y RECARGA todo.
     *
     * Se recarga la pestaña entera en vez de parchear la fila: la fecha cambia
     * los días, el descuento, el importe neto, en qué columna del eje cae ese
     * importe y los totales del pie. Reconstruir eso en el navegador sería
     * reimplementar en JS la cuenta que ya hace el backend, con el riesgo
     * habitual de que las dos den distinto.
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
                cargarDatos();
            } else {
                Notificacion.error(result.message);
                cargarDatos();
            }
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

    function escaparAttr(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
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

        // Los totales del eje se cuentan UNA VEZ y van al pie y arriba de cada
        // fecha (Js/eje-totales.js), con el mismo formato. Contarlos dos veces
        // sería dejar que algún día no coincidan.
        var tot = totalesEje(visibles, cols);

        tot.columnas.forEach(function(total) {
            html += '<td class="currency ' + (total != 0 ? 'cell-with-value' : '') + '">'
                + celdaEje(total) + '</td>';
        });

        html += '<td class="currency total-column">' + celdaEje(tot.total) + '</td>';

        totalsRow.innerHTML = html;

        pintarEjeTotales('tablaCobranzasFR', {
            columnas: cols,
            valores: tot.columnas,
            total: tot.total,
            formato: celdaEje
        });
    }

    /**
     * Los totales del eje de unas filas: uno por columna y el general de la
     * vista activa. Es la cuenta del pie Y de la fila de arriba.
     *
     * @param {Array} filas Las que suman: filasFiltradas()
     * @param {Array} cols vistas.columnas()
     * @returns {{columnas: Array, total: number}}
     */
    function totalesEje(filas, cols) {
        var columnas = cols.map(function(col) {
            var total = 0;

            filas.forEach(function(item) {
                total += Number(vistas.valor(item, col)) || 0;
            });

            return total;
        });

        var total = 0;

        filas.forEach(function(item) {
            total += vistas.total(item);
        });

        return { columnas: columnas, total: total };
    }

    /** El contenido de una celda de totales del eje: vacía en cero */
    function celdaEje(total) {
        return total != 0 ? formatCurrency(total) : '';
    }

    /**
     * Las filas que pasan el buscador, y que SUMAN en el pie.
     *
     * Sale de `datosCobranzas.filas` y no de filasDibujadas(), a propósito:
     * con "Ver excluidos" prendido las facturas excluidas se ven, pero no
     * suman. Por eso tampoco se mira el DOM.
     */
    function filasFiltradas() {
        var input = document.getElementById('busquedaCob');
        var term = input ? input.value.toLowerCase() : '';

        if (!term) {
            return datosCobranzas.filas;
        }

        /* TIPO_REGISTRO ya no entra en la búsqueda, y tiene que no entrar:
           filtrarTabla() esconde filas mirando el textContent de la fila, y
           desde que se fue la columna Tipo el texto "REAL" o "PROYECCIÓN" no
           está en el DOM. Si siguiera acá, buscar "real" dejaría los totales
           de esas filas y escondería las filas: el pie no cerraría con la
           tabla. Las dos funciones tienen que mirar lo mismo. */
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
        // Una fila agrupada del Resumen no trae fecha de emisión ni de cobro:
        // son distintas en cada comprobante del cliente, así que
        // EjeVista::armarAgrupado() las descarta en vez de mostrar la de una
        // factura cualquiera. Ver la nota de esa función.
        if (dateString === undefined || dateString === null || dateString === '') return '';
        if (dateString === '-' || dateString === 'N/A') return dateString;
        var parts = dateString.split(' ')[0].split('-');
        if (parts.length !== 3) return dateString;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function mostrarCargando(mostrar) {
        if (mostrar) { Cargando.mostrar('loadingSpinnerCob'); } else { Cargando.ocultar('loadingSpinnerCob'); }
        document.getElementById('tableWrapperCob').style.display = mostrar ? 'none' : 'block';
    }

    function mostrarError(mensaje) {
        mostrarCargando(false);
        Notificacion.error(mensaje);
    }

    /**
     * Exporta lo que se ve: el buscador, el filtro por fecha de emisión y el
     * orden aplicado ya están en el DOM, y de las columnas del modo Resumen y
     * los `<input>` de la fecha editable se encarga el componente compartido.
     * Ver Js/tabla-export.js.
     */
    function exportarExcel() {
        exportarTabla('tablaCobranzasFR', 'Cobranzas_FR');
    }
})();
