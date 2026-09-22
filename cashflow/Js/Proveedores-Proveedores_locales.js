/**
 * Proveedores Locales — cuentas a pagar, importación y maestro.
 *
 * Acá NO se calcula ningún importe: el backend devuelve las filas ya resueltas
 * —con su categoría, su fecha ubicada en el eje y su pendiente— y esto las
 * pinta.
 *
 * LO QUE SÍ SE SUMA ACÁ es el pie de TOTALES y los cuatro indicadores, y por el
 * mismo motivo: los tres filtros —el buscador y los dos interruptores— son del
 * navegador, así que sólo acá se sabe qué filas se están viendo. Un número
 * arriba de una tabla describe esa tabla. Cuando el filtro esconde algo, cada
 * tarjeta dice además cuánto es el universo: ver pintarIndicadores().
 *
 * LA FECHA DE PAGO ES LO ÚNICO EDITABLE. Las cuentas a pagar salen de Tango y no
 * se tocan; lo que se carga es cuándo se piensa pagar cada comprobante. Es lo
 * que disuelve los importes vencidos apilados en el primer día del eje.
 *
 * Se carga de dos formas y las dos escriben lo mismo: celda por celda, y para
 * VARIAS DE UNA desde la barra de selección. La segunda existe porque lo
 * vencido sin fecha son cientos de vencimientos y el caso real no es una
 * factura, son las ocho de un proveedor: ver fecharSeleccion().
 *
 * NADA SE IMPORTA SIN VER EL DIFF ANTES. Las tres operaciones masivas —maestro,
 * pagos y conciliación— tienen dos pasos: primero se pide qué cambiaría y
 * después, con otra llamada, se aplica.
 *
 * FECHAS: el backend manda siempre 'Y-m-d'. Nunca se hace new Date(string), que
 * es de donde salen los corrimientos de un día.
 */

(function() {
    'use strict';

    var datos = null;
    var maestro = null;
    var vistas = null;
    var modoVista = 'cuentas';
    var preview = null;          // el último diff pedido, para poder confirmarlo

    function inicializar() {
        vistas = crearEjeVistas({
            botones: 'vistasProv',
            periodo: 'periodoProv',
            alCambiar: pintarGrilla
        });

        crearColumnasFijas({
            tabla: 'tablaProveedores',
            control: 'colFijasProv',
            clave: 'proveedores_locales',
            porDefecto: [0, 1]
        });

        conectar('btnVistaCuentasProv', function() { cambiarVista('cuentas'); });
        conectar('btnVistaImportarProv', function() { cambiarVista('importar'); });
        conectar('btnVistaMaestroProv', function() { cambiarVista('maestro'); });

        conectar('btnRefreshProv', cargar);
        conectar('btnExportProv', function() { exportarTabla('tablaProveedores', nombreExport()); });
        conectar('btnConciliarProv', previewConciliacion);

        conectar('btnPreviewPagosProv', function() { previewImportacion('Pagos'); });
        conectar('btnPreviewMaestroProv', function() { previewImportacion('Maestro'); });

        var busq = document.getElementById('busquedaProv');
        if (busq) { busq.addEventListener('input', pintarGrilla); }

        var soloV = document.getElementById('soloVencidosProv');
        if (soloV) { soloV.addEventListener('change', pintarGrilla); }

        var soloC = document.getElementById('soloCronogramaProv');
        if (soloC) { soloC.addEventListener('change', pintarGrilla); }

        var verEx = document.getElementById('verExcluidasProv');
        if (verEx) { verEx.addEventListener('change', pintarGrilla); }

        var busqM = document.getElementById('busquedaMaestroProv');
        if (busqM) { busqM.addEventListener('input', pintarMaestro); }

        conectar('btnNuevoProv', function() { abrirForm(null); });
        conectar('btnRefreshMaestroProv', actualizarMaestro);
        conectar('btnGuardarProv', guardarProveedor);
        conectar('btnCancelarProv', cerrarForm);
        conectarBuscadorTango();

        /* "Seleccionar todas las que se ven" es lo que hace que el caso normal
           —las ocho facturas de un proveedor— sea buscar el proveedor y tildar
           una vez. Es el mismo gesto que el marcado masivo de Echeqs, y sirve
           para las dos acciones de la barra: fecharlas y excluirlas. */
        var selTodas = document.getElementById('selTodasProv');

        if (selTodas) {
            selTodas.addEventListener('change', function() {
                filasVisibles().forEach(function(f) {
                    if (selTodas.checked) {
                        seleccion[claveFila(f)] = true;
                    } else {
                        delete seleccion[claveFila(f)];
                    }
                });

                pintarGrilla();
            });
        }

        conectar('btnFecharSelProv', fecharSeleccion);
        conectar('btnExcluirSelProv', function() { accionSeleccion(true); });
        conectar('btnIncluirSelProv', function() { accionSeleccion(false); });
        conectar('btnLimpiarSelProv', function() {
            seleccion = {};
            pintarGrilla();
        });

        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       SUB-SOLAPAS
       ================================================================ */

    function cambiarVista(modo) {
        if (modo === modoVista) { return; }

        modoVista = modo;

        var mapa = {
            cuentas: ['vistaCuentasProv', 'btnVistaCuentasProv'],
            importar: ['vistaImportarProv', 'btnVistaImportarProv'],
            maestro: ['vistaMaestroProv', 'btnVistaMaestroProv']
        };

        Object.keys(mapa).forEach(function(k) {
            mostrar(mapa[k][0], k === modo);
            var btn = document.getElementById(mapa[k][1]);
            if (btn) { btn.classList.toggle('active', k === modo); }
        });

        // Los indicadores describen las cuentas a pagar: en las otras dos
        // solapas dirían un número que no corresponde a lo que se está viendo.
        mostrar('summaryProv', modo === 'cuentas' && datos !== null);

        if (modo === 'maestro' && maestro === null) {
            cargarMaestro();
        }
    }

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        mostrar('loadingProv', true);
        mostrar('wrapperProv', false);

        pedirJson('Controller/ProveedoresController.php?action=getPendientes')
            .then(function(data) {
                datos = data;

                /* El conteo de cuotas se deriva de `datos`, así que muere con
                   la carga anterior. Si no se tira acá, una factura que en
                   Tango pasó de dos vencimientos a uno seguiría mostrando
                   "2 vtos." hasta que alguien recargue la página. */
                cuotasPorComp = null;

                vistas.usar(datos);

                /* La selección se poda contra lo que vino: un comprobante que se
                   canceló en Tango ya no está en la lista, y dejarlo
                   seleccionado haría que la próxima acción masiva lo mande al
                   servidor sin que nadie lo vea en pantalla. */
                podarSeleccion();

                pintarAvisos(datos.warnings, 'avisosProv');
                // Los indicadores los pinta pintarGrilla(): se miden sobre las
                // filas visibles, así que cambian con cada filtro.
                pintarGrilla();

                mostrar('loadingProv', false);
                mostrar('wrapperProv', true);
                mostrar('summaryProv', modoVista === 'cuentas');

                if (typeof window.ajustarStickyHeaders === 'function') {
                    window.ajustarStickyHeaders();
                }
            })
            .catch(function(error) {
                mostrar('loadingProv', false);
                mostrarError('No se pudieron cargar las cuentas a pagar: ' + error.message);
            });
    }

    /**
     * Vuelve a pedir el maestro SIN cancelar lo que alguien esté haciendo.
     *
     * NO PISA EL FORMULARIO ABIERTO —aplicarListas() no repuebla los campos
     * mientras está a la vista, ver su nota— y NO TOCA el buscador del maestro,
     * porque pintarMaestro() lee el filtro del input en vez de guardarlo. Las
     * dos cosas son deliberadas: actualizar es traer datos nuevos, no descartar
     * el trabajo a medio hacer.
     */
    function actualizarMaestro() {
        var btn = document.getElementById('btnRefreshMaestroProv');

        if (btn) { btn.disabled = true; }

        cargarMaestro().then(function() {
            if (btn) { btn.disabled = false; }
        });
    }

    function cargarMaestro() {
        return pedirJson('Controller/ProveedoresController.php?action=getMaestro')
            .then(function(data) {
                maestro = data;

                /* LOS AVISOS DEL MAESTRO SE PINTAN, y antes no: el backend los
                   venía produciendo —"el maestro está vacío", "falta el script
                   de la carga manual"— y esta pantalla no los leía nunca. El
                   síntoma es una función que no aparece sin que nada diga por
                   qué, que es indistinguible de una que no se construyó. */
                pintarAvisos(maestro.avisos, 'avisosMaestroProv');

                pintarMaestro();
                pintarFaltantes();
            })
            .catch(function(error) {
                mostrarError('No se pudo cargar el maestro: ' + error.message);
            });
    }

    /* ================================================================
       INDICADORES
       ================================================================ */

    /**
     * Los cuatro indicadores, MEDIDOS SOBRE LO QUE SE ESTÁ VIENDO.
     *
     * Antes salían del backend y se calculaban sobre TODOS los vencimientos,
     * mientras la grilla y el pie de TOTALES se calculaban sobre las filas
     * visibles. Con el filtro por forma de pago prendido —que es el default—
     * la tarjeta decía 549 vencimientos arriba de una tabla que mostraba 294, y
     * ni el buscador ni el interruptor de vencidos la movían.
     *
     * Un número arriba de una tabla describe esa tabla. Si describiera otra
     * cosa habría que decir cuál, y entonces no es un indicador de la pantalla.
     *
     * LO QUE EL FILTRO ESCONDE NO SE PIERDE: cuando lo que se ve difiere del
     * universo, el pie de cada tarjeta dice el total. Es la misma regla del
     * cartel de al lado del período —un filtro que esconde plata sin decir
     * cuánta es un filtro que miente—, aplicada a las tarjetas.
     *
     * El segundo indicador es el que importa: lo vencido sin fecha cargada. Ese
     * importe está dibujado en el primer día del eje porque no hay otro lugar
     * donde ponerlo, y NO significa que se pague hoy. La tarjeta se apaga
     * cuando llega a cero: mientras haya algo, tiene que verse.
     */
    function pintarIndicadores(filas) {
        var k = calcularIndicadores(filas);
        var u = datos.indicadores;          // el universo, tal como lo cuenta el backend

        texto('totalProv', plata(k.total));
        texto('detalleTotalProv', k.vencimientos + ' vencimiento(s)'
            + deTotal(k.vencimientos !== u.vencimientos, 'de ' + plata(u.total)
                + ' en ' + u.vencimientos));

        texto('vencidoProv', plata(k.vencido_sin_fecha));
        texto('detalleVencidoProv', (k.n_vencido_sin_fecha
            ? k.n_vencido_sin_fecha + ' comprobantes — se dibujan hoy, no se pagan hoy'
            : 'Nada pendiente de fechar')
            + deTotal(k.n_vencido_sin_fecha !== u.n_vencido_sin_fecha,
                'de ' + plata(u.vencido_sin_fecha) + ' en ' + u.n_vencido_sin_fecha));

        /* La tarjeta se apaga en verde sólo cuando NO queda nada en TODO el
           universo. Apagarla porque el filtro escondió lo que falta fechar
           diría que no hay trabajo por hacer justo cuando lo hay. */
        var card = document.getElementById('cardVencidoProv');
        if (card) { card.classList.toggle('prov-kpi-ok', !u.n_vencido_sin_fecha); }

        texto('conFechaProv', plata(k.con_fecha));
        texto('detalleConFechaProv', k.n_con_fecha + ' comprobante(s) con fecha'
            + deTotal(k.n_con_fecha !== u.n_con_fecha,
                'de ' + plata(u.con_fecha) + ' en ' + u.n_con_fecha));

        texto('proveedoresProv', String(k.proveedores));
        texto('detalleProveedoresProv', 'con deuda pendiente'
            + deTotal(k.proveedores !== u.proveedores, 'de ' + u.proveedores));
    }

    /** El sufijo que devuelve el universo cuando el filtro escondió algo */
    function deTotal(difiere, delUniverso) {
        return difiere ? ' · ' + delUniverso : '';
    }

    /**
     * Los mismos cuatro números, sobre las filas que se le pasen.
     *
     * Se suman acá y no en el backend porque el filtro es del navegador: es el
     * mismo motivo por el que el pie de TOTALES ya se calculaba acá. Lo que NO
     * se calcula acá es ningún importe —cada fila llega con el suyo resuelto—:
     * esto sólo los suma.
     */
    function calcularIndicadores(filas) {
        var k = { total: 0, vencimientos: filas.length, proveedores: 0,
                  vencido_sin_fecha: 0, n_vencido_sin_fecha: 0,
                  con_fecha: 0, n_con_fecha: 0 };

        var provs = {};

        filas.forEach(function(f) {
            var importe = Number(f.IMPORTE_PENDIENTE) || 0;

            k.total += importe;
            provs[f.COD_PROVEE] = true;

            if (f.ORIGEN_FECHA === 'CARGADA') {
                k.con_fecha += importe;
                k.n_con_fecha++;
            } else if (f.SIN_FECHA_CARGADA) {
                k.vencido_sin_fecha += importe;
                k.n_vencido_sin_fecha++;
            }
        });

        k.proveedores = Object.keys(provs).length;

        return k;
    }

    /* ================================================================
       LA GRILLA
       ================================================================ */

    /**
     * Las filas que se están viendo.
     *
     * EL FILTRO POR FORMA DE PAGO VIENE PRENDIDO. La grilla es la herramienta
     * para cargar fechas, y lo que se carga es lo que se paga decidiendo cuándo:
     * echeq y transferencia. Un débito automático se debita solo.
     *
     * Pero se puede apagar, y mientras está prendido se dice cuánto queda
     * afuera. Ese importe es deuda real que igual sale de la caja; esconderlo
     * sin decir cuánto es sería exactamente lo que este módulo evita con los
     * vencidos sin fecha.
     */
    function filasVisibles() {
        var filas = (datos && datos.filas) || [];
        var q = (document.getElementById('busquedaProv') || {}).value || '';
        var soloVencidos = (document.getElementById('soloVencidosProv') || {}).checked;
        var soloCronograma = (document.getElementById('soloCronogramaProv') || {}).checked;
        var verExcluidas = (document.getElementById('verExcluidasProv') || {}).checked;

        q = q.trim().toLowerCase();

        return filas.filter(function(f) {
            /* LAS EXCLUIDAS NO SE VEN POR DEFECTO: ya se decidió que no van al
               cashflow, así que en el trabajo normal son ruido. Cuánto esconde
               este filtro se dice al lado del período. */
            if (!verExcluidas && f.EXCLUIDA_MANUAL) { return false; }

            if (soloCronograma && !f.CRONOGRAMA) { return false; }
            if (soloVencidos && !f.SIN_FECHA_CARGADA) { return false; }
            if (q === '') { return true; }

            /* Se busca por la forma QUE DECIDE y no por la del pago registrado:
               es la que se ve en la columna, y buscar "CAJA" tiene que traer lo
               que la grilla muestra como CAJA. */
            return [f.COD_PROVEE, f.RAZON_SOC, f.N_COMP, f.RUBRO_ECONOMICO, f.RUBRO,
                    f.FORMA_PAGO_VIGENTE, f.MOTIVO_EXCLUSION]
                .join(' ').toLowerCase().indexOf(q) !== -1;
        });
    }

    /**
     * El nombre del archivo que se exporta dice QUÉ FILTRO estaba puesto.
     *
     * Bajar lo que se ve es lo correcto —es la tabla que el usuario está
     * mirando—, pero un archivo que no deja rastro del recorte es un archivo
     * que dentro de una semana nadie sabe si trae todo o una parte. Y acá el
     * caso normal ES el recortado: el filtro por forma de pago viene prendido.
     *
     * Va en el nombre y no en una fila adentro de la tabla porque la tabla es
     * el dato: agregarle una fila de encabezado la rompe para quien la abra con
     * un dinamizador.
     */
    function nombreExport() {
        var partes = ['Cuentas a Pagar'];
        var q = ((document.getElementById('busquedaProv') || {}).value || '').trim();

        if ((document.getElementById('soloCronogramaProv') || {}).checked) {
            partes.push('solo echeq y transferencia');
        }

        if ((document.getElementById('soloVencidosProv') || {}).checked) {
            partes.push('solo vencidos sin fecha');
        }

        // Se nombra cuando SE VEN, no cuando se esconden: esconderlas es el
        // caso normal y aclararlo siempre haria que el nombre no distinga nada.
        if ((document.getElementById('verExcluidasProv') || {}).checked) {
            partes.push('con las excluidas');
        }

        if (q !== '') { partes.push('buscando ' + q); }

        // Sin ningún filtro no hace falta aclarar nada: son todas.
        return partes.join(' - ');
    }

    /**
     * El cartel que dice cuánto queda fuera del filtro, desglosado por forma.
     *
     * Va al lado del período y no en un tooltip: es la contrapartida de haber
     * escondido filas, y tiene que leerse sin buscarla.
     */
    function pintarFueraDelFiltro() {
        var el = document.getElementById('fueraFiltroProv');

        if (!el) { return; }

        var soloCronograma = (document.getElementById('soloCronogramaProv') || {}).checked;
        var verExcluidas = (document.getElementById('verExcluidasProv') || {}).checked;
        var k = datos.indicadores;
        var partes = [];

        if (soloCronograma && k.n_fuera_cronograma) {
            var detalle = Object.keys(k.fuera_por_forma || {}).map(function(forma) {
                return forma + ' ' + plata(k.fuera_por_forma[forma]);
            }).join(' · ');

            partes.push('Quedan afuera ' + escapar(plata(k.fuera_cronograma)) + ' en '
                + k.n_fuera_cronograma + ' vencimiento(s)'
                + (detalle ? ' (' + escapar(detalle) + ')' : '')
                + ' — destildá <em>Sólo echeq y transferencia</em> para verlos.');
        }

        /* LO EXCLUIDO SE DICE AUNQUE NO SE VEA, y sobre todo por eso: esas
           facturas están escondidas por defecto, así que sin este cartel no hay
           ninguna pantalla donde alguien note que existen. Es la misma regla que
           el filtro de al lado: un filtro que esconde plata sin decir cuánta es
           un filtro que miente. */
        if (!verExcluidas && k.n_excluido_manual) {
            partes.push('Hay ' + k.n_excluido_manual + ' factura(s) excluida(s) a mano por '
                + escapar(plata(k.excluido_manual)) + ', escondidas y fuera del cashflow '
                + '— tildá <em>Ver excluidas</em> para revisarlas.');
        }

        el.innerHTML = partes.length
            ? '&nbsp;·&nbsp;<span class="prov-fuera-filtro">' + partes.join(' · ') + '</span>'
            : '';
    }

    function pintarGrilla() {
        if (!datos) { return; }

        var cols = vistas.columnas();

        /* EL RÓTULO SALE DE vistas.rotulo(), NO DE c.label. `columnas()` devuelve
           STRINGS —'DIA|2026-09-17', 'MES|2026-10'—, no objetos: `c.label` daba
           undefined y la fila de días y meses del encabezado salía toda vacía.
           Es la misma API que usan Cobranzas FR y Echeqs. */
        document.getElementById('headerEjeProv').setAttribute('colspan', cols.length || 1);
        document.getElementById('headerSubProv').innerHTML = cols.map(function(c) {
            var meta = vistas.meta(c) || {};
            var parcial = vistas.esMes(c) && meta.parcial;

            return '<th class="text-end"'
                + (parcial
                    ? ' title="' + escapar('Este mes está recortado: sus primeros días '
                        + 'están en el tramo diario') + '"'
                    : '')
                + '>' + escapar(vistas.rotulo(c)) + '</th>';
        }).join('');

        var filas = filasVisibles();
        var html = '';

        filas.forEach(function(f) {
            var clases = ['prov-fila'];

            if (f.SIN_FECHA_CARGADA) { clases.push('prov-vencida'); }
            if (f.ORIGEN_FECHA === 'CARGADA') { clases.push('prov-con-fecha'); }

            /* EL RUBRO "Excluidos" NO ATENÚA LA FILA, y antes sí.

               La atenuación decía "esto está afuera", y en esta pantalla no está
               afuera de nada: la fila del tablero usa la serie PAGOS, y
               seriesDeItem() reparte PAGOS por CÓMO se paga —cronograma o no— sin
               preguntar nunca por el rubro. Verificado contra la base: los 8
               vencimientos de OGRAZ con rubro Excluidos entran a PAGOS igual que
               cualquier otro, porque cobra por echeq. Tampoco los esconde el
               filtro ni los descuenta ninguna tarjeta.

               Sacarlos del tablero sigue siendo apuntar la fila a
               PAGOS_CRONO_OPERATIVOS desde Parámetros, y hoy eso no está hecho.
               Hasta que lo esté, "Excluidos" es una clasificación que alimenta
               otros cortes —PAGOS_EXCLUIDOS y su serie por rubro— y no una
               decisión sobre esta grilla. Una fila gris por un rubro que no
               cambia ningún número manda a buscar una plata que sí está.

               Lo que SÍ se sigue marcando es la exclusión POR FACTURA, abajo:
               ésa va a su propia serie y efectivamente sale de PAGOS. */
            if (f.EXCLUIDA_MANUAL) { clases.push('prov-excluida-mano'); }

            html += '<tr class="' + clases.join(' ') + '">'
                + '<td title="' + escapar(tituloProveedor(f)) + '"><strong>'
                +     escapar(f.COD_PROVEE) + '</strong>' + marcaMaestro(f) + '</td>'
                + '<td class="col-texto" title="' + escapar(f.RAZON_SOC) + '">'
                +     escapar(f.RAZON_SOC) + '</td>'
                + '<td>' + celdaRubro(f) + '</td>'
                + '<td>' + celdaRubroDetalle(f) + '</td>'
                + '<td class="center">' + escapar(f.T_COMP) + '</td>'
                + '<td class="center">' + escapar(f.N_COMP) + '</td>'
                + '<td class="center">' + fechaCorta(f.FECHA_EMIS) + '</td>'
                + '<td class="center">' + celdaVto(f) + '</td>'
                + celdaImporte(f)
                + '<td class="currency fw-bold">' + plata(f.IMPORTE_PENDIENTE) + '</td>'
                + celdaFechaPago(f)
                + '<td class="center">' + celdaForma(f) + '</td>'
                + '<td class="center">' + celdaExcluir(f) + '</td>';

            cols.forEach(function(c) {
                var v = Number(vistas.valor(f, c)) || 0;

                html += '<td class="currency' + (v !== 0 ? ' cell-with-value' : '') + '">'
                    + (v !== 0 ? plataCorta(v) : '') + '</td>';
            });

            html += '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="' + (COLS_DESC + cols.length) + '" '
                 + 'class="text-center text-muted py-4">No hay cuentas a pagar que coincidan '
                 + 'con el filtro.</td></tr>';
        }

        document.getElementById('bodyProv').innerHTML = html;

        pintarIndicadores(filas);
        pintarTotales(filas, cols);
        pintarFueraDelFiltro();
        conectarEdicion();
        pintarSeleccion();
    }

    /**
     * Cuántas columnas descriptivas tiene la grilla, antes de las del eje.
     *
     * Está declarada una vez porque la usan el pie de totales y la fila de
     * "no hay resultados", y las dos se corren en silencio cuando se agrega una
     * columna: el síntoma es una tabla desalineada que nadie relaciona con el
     * cambio que la causó.
     */
    var COLS_DESC = 13;

    function pintarTotales(filas, cols) {
        var total = 0;
        var totalImporte = 0;
        var porCol = {};

        /* LA CLAVE ES LA COLUMNA, que ya ES el string 'DIA|2026-09-17'. Antes se
           armaba con `c.rama + '|' + c.clave`, dos campos que no existen: TODAS
           las columnas caían en la clave 'undefined|undefined' y el pie mostraba
           el total del período repetido en cada una de las 28 columnas. Una fila
           de totales que miente es peor que una que falta. */
        filas.forEach(function(f) {
            total += Number(f.IMPORTE_PENDIENTE) || 0;
            totalImporte += Number(f.IMPORTE_VTO) || 0;

            cols.forEach(function(c) {
                porCol[c] = (porCol[c] || 0) + (Number(vistas.valor(f, c)) || 0);
            });
        });

        /* Las celdas del pie van en el mismo orden que el encabezado y suman
           COLS_DESC: un colspan mal contado corre el total debajo de otra
           columna y el número queda diciendo otra cosa. Ocho descriptivas, el
           importe, el pendiente, y las tres editables al final.

           EL TOTAL DE IMPORTES NO VA EN NEGRITA, igual que su columna: es la
           deuda ANTES de restarle lo imputado y no es un número del cashflow.
           Que los dos difieran dice cuánto hay imputado sin cancelar en lo que
           se está viendo, y con eso alcanza; ponerlo con el mismo peso que el
           pendiente invitaría a usarlo como total, que es justo lo que no es. */
        var html = '<td colspan="8" class="fw-bold text-end">TOTALES</td>'
            + '<td class="currency prov-importe">' + plata(totalImporte) + '</td>'
            + '<td class="currency fw-bold">' + plata(total) + '</td>'
            + '<td colspan="' + (COLS_DESC - 10) + '"></td>';

        cols.forEach(function(c) {
            var v = porCol[c] || 0;

            html += '<td class="currency fw-bold">' + (v !== 0 ? plataCorta(v) : '') + '</td>';
        });

        document.getElementById('totalesProv').innerHTML = html;
    }

    /** De dónde sale la fecha con la que el comprobante entra al eje */
    function tituloProveedor(f) {
        var origen = {
            CARGADA: 'La fecha de pago la cargó una persona.',
            VENCIMIENTO: 'Se proyecta a la fecha de vencimiento de Tango.',
            PLAZO: 'No tiene vencimiento: se proyecta con el plazo del maestro.',
            SIN_FECHA: 'No tiene vencimiento ni plazo: no se puede ubicar en el eje.'
        };

        /* Acá colgaba además un "Rubro Excluidos: se lista pero su fila del
           tablero se puede inhabilitar". Se fue con las otras dos marcas del
           rubro: decía lo mismo, y encima salía de f.EXCLUIDO —que junta el
           rubro con el tilde por factura—, así que una factura excluida a mano
           de otro rubro también anunciaba ser "Rubro Excluidos".

           Cuánto pesa ese rubro sobre el total lo sigue diciendo getAvisos(),
           que es donde un número agregado se lee una vez en lugar de repetirse
           en cada fila. */
        return origen[f.ORIGEN_FECHA] || '';
    }

    /** El proveedor no está en el maestro: su deuda no se puede abrir por rubro */
    function marcaMaestro(f) {
        if (f.EN_MAESTRO) { return ''; }

        return ' <i class="fas fa-circle-question prov-marca" title="'
            + escapar('Este proveedor no está en el maestro, así que su deuda no se puede abrir '
                + 'por rubro. Importá la hoja "Maestro proveedores" actualizada.') + '"></i>';
    }

    /**
     * El RUBRO ECONÓMICO del maestro, que es el que abre la deuda por serie en
     * el tablero.
     *
     * TODOS LOS RUBROS SE VEN IGUAL, y "Excluidos" tampoco es la excepción acá.
     * Se pintaba distinto por el mismo motivo por el que la fila se atenuaba
     * —ver pintarGrilla()— y era la misma afirmación equivocada: en esta
     * pantalla ese rubro no saca la deuda de ningún lado.
     *
     * Peor todavía, se pintaba con f.EXCLUIDO, que junta el rubro con el tilde
     * por factura: una factura excluida a mano de un proveedor de Alquileres
     * mostraba "Alquileres" con el color de Excluidos, que es decir algo que no
     * pasa. Para encontrarlas alcanza con escribir el rubro en el buscador.
     */
    function celdaRubro(f) {
        if (!f.RUBRO_ECONOMICO) {
            return '<span class="text-muted small">sin clasificar</span>';
        }

        return '<span class="prov-rubro">' + escapar(f.RUBRO_ECONOMICO) + '</span>';
    }

    /**
     * El RUBRO del maestro, que es OTRA columna: un segundo nivel de
     * clasificación dentro del económico.
     *
     * Va aparte y no concatenado al de al lado porque no hacen lo mismo: el
     * económico abre las series del tablero y éste es informativo. Juntarlos en
     * una celda haría que el que decide no se pueda leer solo.
     *
     * Se distingue del "sin clasificar" del económico: ahí el proveedor no está
     * en el maestro; acá está pero esa columna vino vacía, que es de lo más
     * común en la planilla.
     */
    function celdaRubroDetalle(f) {
        if (!f.RUBRO) {
            return '<span class="text-muted small">—</span>';
        }

        return '<span class="prov-rubro-detalle">' + escapar(f.RUBRO) + '</span>';
    }

    /**
     * El vencimiento. Cuando ya pasó y no hay fecha cargada, se marca: ese es
     * exactamente el importe que está apilado en el primer día del eje.
     */
    function celdaVto(f) {
        if (!f.SIN_FECHA_CARGADA) {
            return fechaCorta(f.FECHA_VTO);
        }

        return '<span class="badge-vencida-exp" title="'
            + escapar('Venció el ' + fechaCorta(f.FECHA_VTO) + ' y no tiene fecha de pago '
                + 'cargada, así que se dibuja en el primer día del eje. Eso no significa que '
                + 'se pague hoy: cargale la fecha.')
            + '"><i class="fas fa-triangle-exclamation me-1"></i>'
            + fechaCorta(f.FECHA_VTO) + '</span>';
    }

    /**
     * EL IMPORTE DEL VENCIMIENTO, ANTES DE RESTARLE LO IMPUTADO.
     *
     * POR QUÉ EXISTE ESTA COLUMNA
     * ---------------------------
     * Porque "Pendiente" sola no distingue una factura que nadie pagó de una
     * que se pagó y quedó corta, y esas dos cosas se resuelven distinto: la
     * primera se paga, la segunda se va a buscar por qué faltó.
     *
     * El caso que lo motivó: SABAMA A0000300137161, de $431.393,34, tiene la
     * O/P 0000100061675 imputada por $416.393,34 —la orden canceló siete
     * facturas del proveedor y en la última se quedó sin plata—. La grilla
     * mostraba "$15.000" y administración lo leyó como que el pago no estaba
     * imputado. Estaba: lo que faltaba era ese pedazo.
     *
     * NO ES SIEMPRE EL TOTAL DE LA FACTURA, y por eso la columna se llama
     * "Importe" y no "Total factura". Cada fila es un VENCIMIENTO, no un
     * comprobante —ver el encabezado de Class/Proveedores.php—, así que en una
     * factura en cuotas esto es el de ESTA cuota. Hoy son 6 comprobantes con
     * más de un vencimiento pendiente, y se marcan: sin la marca, ver $7,8
     * millones en una factura de $15,6 sería la misma sorpresa que esta columna
     * viene a sacar.
     *
     * SE VE ATENUADA A PROPÓSITO. La que decide y la que suma sigue siendo
     * "Pendiente"; ésta es el contexto para leerla. Si pesaran igual, dos
     * columnas de plata pegadas se confundirían y la que entra al cashflow
     * dejaría de leerse sola.
     */
    function celdaImporte(f) {
        var imputado = Number(f.IMPUTACIONES) || 0;
        var cuotas = cuotasDe(f);
        var marcas = '';

        /* IMPUTACIONES VIENE CON SIGNO Y SE SUMA AL IMPORTE: negativo lo baja
           —un pago, una nota de crédito— y positivo lo sube —una nota de débito
           es deuda NUEVA—. La tabla de signos está en Class/Proveedores.php y
           acá sólo se lee: por eso el texto se decide por el signo y no se
           asume que todo lo imputado sea un pago. */
        if (imputado < 0) {
            marcas += ' <i class="fas fa-circle-half-stroke prov-parcial" title="'
                + escapar('Ya se le imputaron ' + plata(-imputado) + ' que no lo cancelaron '
                    + 'entero: quedan ' + plata(f.IMPORTE_PENDIENTE) + ' sin pagar. El pago '
                    + 'está hecho y quedó corto — revisá en Tango si falta imputarle una '
                    + 'retención.') + '"></i>';
        } else if (imputado > 0) {
            marcas += ' <i class="fas fa-circle-plus prov-parcial" title="'
                + escapar('Tiene ' + plata(imputado) + ' imputados que SUMAN deuda —una nota '
                    + 'de débito—, así que se debe más que el importe original: '
                    + plata(f.IMPORTE_PENDIENTE) + '.') + '"></i>';
        }

        if (cuotas > 1) {
            marcas += ' <span class="prov-cuota" title="'
                + escapar('Este comprobante tiene ' + cuotas + ' vencimientos pendientes, uno '
                    + 'por fila. Éste es el importe de ESTE vencimiento, no el total de la '
                    + 'factura.') + '">' + cuotas + ' vtos.</span>';
        }

        return '<td class="currency prov-importe">' + plata(f.IMPORTE_VTO) + marcas + '</td>';
    }

    /**
     * Cuántos vencimientos pendientes tiene el comprobante de esta fila.
     *
     * SE CUENTA SOBRE TODO LO QUE VINO, no sobre lo que se está viendo. Con el
     * buscador puesto en un rubro, o con "sólo vencidos" tildado, parte de las
     * cuotas de una factura se esconde: contar las visibles diría "1 vto." de
     * una factura en doce cuotas, que es justo lo contrario de lo que la marca
     * tiene que avisar.
     *
     * El mapa se arma una vez por carga y no por fila: recorrerlo entero en
     * cada celda serían 470 pasadas sobre 470 filas.
     */
    var cuotasPorComp = null;

    function cuotasDe(f) {
        if (cuotasPorComp === null) {
            cuotasPorComp = {};

            ((datos && datos.filas) || []).forEach(function(x) {
                var k = x.COD_PROVEE + '|' + x.T_COMP + '|' + x.N_COMP;

                cuotasPorComp[k] = (cuotasPorComp[k] || 0) + 1;
            });
        }

        return cuotasPorComp[f.COD_PROVEE + '|' + f.T_COMP + '|' + f.N_COMP] || 1;
    }

    /**
     * La celda editable. El `min` no se pone: acá SÍ se aceptan fechas pasadas,
     * a diferencia de Cobranzas FR, porque el listado muestra todo lo pendiente
     * sin techo de antigüedad y una fecha de la semana pasada es una decisión
     * legítima —se pensó pagar y no se pagó—.
     */
    function celdaFechaPago(f) {
        var cargada = (f.ORIGEN_FECHA === 'CARGADA');
        var conciliado = (f.ESTADO_PAGO === 'CONCILIADO');

        var titulo = cargada
            ? 'Fecha cargada a mano.' + (conciliado
                ? ' Ya está CONCILIADA contra Tango: el comprobante se pagó.' : '')
            : 'Sin cargar: se proyecta al vencimiento. Escribí una fecha para reubicarla.';

        return '<td class="center prov-celda-fecha' + (cargada ? ' prov-fecha-cargada' : '') + '">'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +   '<input type="date" class="form-control form-control-sm prov-input-fecha" '
            +     'value="' + escapar(f.FECHA_PAGO || '') + '" '
            +     'title="' + escapar(titulo) + '" '
            +     'data-cod="' + escapar(f.COD_PROVEE) + '" '
            +     'data-tcomp="' + escapar(f.T_COMP) + '" '
            +     'data-ncomp="' + escapar(f.N_COMP) + '">'
            +   (cargada
                  ? '<button class="btn btn-outline-secondary prov-btn-borrar" type="button" '
                    + 'title="Volver a proyectar al vencimiento">'
                    + '<i class="fas fa-rotate-left"></i></button>'
                  : '')
            + '</div></td>';
    }

    /* ================================================================
       LA COLUMNA FORMA DE PAGO

       UNA SOLA COLUMNA, Y MUESTRA LA QUE DECIDE. Trae la del maestro —o la
       que quedó de la importación del maestro— y se puede editar; editarla
       guarda un override para ESA factura y no toca el maestro.

       Lo que se ve es lo que decide. Es la propiedad que importa en una
       columna que está al lado de los importes del cashflow: si mostrara una
       cosa y el tablero usara otra, no habría dónde notarlo.

       La forma con la que se REGISTRÓ el pago —el hecho que trae la planilla
       de pagos— sigue existiendo y no decide. Cuando difiere de la que decide
       se marca al lado con un ícono, en vez de ocupar una columna propia: es
       un caso raro —hoy, cero comprobantes— y una columna entera para eso
       obliga a leer dos celdas para contestar una sola pregunta.
       ================================================================ */

    /** Cómo se nombra la forma que trae el maestro, con sus casos raros */
    function formaDelMaestro(f) {
        if (f.FORMA_PAGO_MAESTRO) {
            return f.FORMA_PAGO_MAESTRO;
        }

        // Un valor que no matcheó contra la lista: es un typo de la planilla y
        // se muestra tal como vino, porque lo que hay que arreglar es allá.
        if (f.FORMA_PAGO_ORIG) {
            return f.FORMA_PAGO_ORIG + ' (?)';
        }

        return 'sin forma';
    }

    function celdaForma(f) {
        var delMaestro = formaDelMaestro(f);

        /* Sin el script del override no se puede escribir, así que se muestra
           lo que decide en texto en vez de un desplegable que falla al
           guardar. */
        if (!datos || !datos.forma_por_factura) {
            return textoForma(f, delMaestro);
        }

        /* FORMAS_PAGO es una LISTA de nombres, no un mapa: los nombres son los
           VALORES. Leerla con Object.keys devolvía 0..5 y el desplegable
           mostraba números. */
        var formas = (datos && datos.formas_pago) || [];
        var actual = f.FORMA_PAGO_CRONOGRAMA || '';

        /* La opción vacía NO es "vacío": es "la que trae el maestro", y se
           nombra. Así el caso normal —que es éste— muestra la forma real y de
           dónde sale, y volver a ella es lo que saca el override. */
        var opciones = '<option value=""' + (actual === '' ? ' selected' : '') + '>'
            + escapar(delMaestro + ' · maestro') + '</option>';

        formas.forEach(function(x) {
            opciones += '<option value="' + escapar(x) + '"'
                + (actual === x ? ' selected' : '') + '>' + escapar(x) + '</option>';
        });

        var titulo = actual === ''
            ? 'Viene del maestro (' + delMaestro + '). Elegí otra para tratar SÓLO esta '
                + 'factura de otra manera: el maestro y las demás facturas de este proveedor '
                + 'no cambian.'
            : 'Esta factura se trata como ' + actual + ', en lugar de la del maestro ('
                + delMaestro + '). El maestro no cambió. Volvé a "del maestro" para sacarlo.';

        return '<div class="prov-forma-celda">'
            + '<select class="form-select form-select-sm prov-select-forma'
            +   (actual !== '' ? ' prov-forma-pisada' : '')
            +   (!f.FORMA_PAGO_VIGENTE ? ' prov-forma-incierta' : '') + '"'
            +   ' data-cod="' + escapar(f.COD_PROVEE) + '"'
            +   ' data-t="' + escapar(f.T_COMP) + '"'
            +   ' data-n="' + escapar(f.N_COMP) + '"'
            +   ' title="' + escapar(titulo) + '">' + opciones + '</select>'
            + marcaPagoDistinto(f)
            + '</div>';
    }

    /** La misma columna cuando todavía no se puede editar */
    function textoForma(f, delMaestro) {
        var vigente = f.FORMA_PAGO_VIGENTE || delMaestro;

        return '<span class="small' + (!f.FORMA_PAGO_VIGENTE ? ' prov-sin-forma' : '') + '" '
            + 'title="' + escapar('Viene del maestro. Para poder cambiarla por factura hace '
                + 'falta correr sql/cashflow_prov_locales_forma_por_factura.sql.') + '">'
            + escapar(vigente) + '</span>' + marcaPagoDistinto(f);
    }

    /**
     * El pago se registró por una vía distinta de la que decide.
     *
     * No es un error y no cambia nada: es un dato. O fue una excepción, o el
     * maestro quedó viejo. Va como ícono al lado y no como columna: hoy no hay
     * ni un comprobante en este caso.
     */
    function marcaPagoDistinto(f) {
        if (!f.FORMA_PAGO || !f.FORMA_PAGO_VIGENTE
            || f.FORMA_PAGO === f.FORMA_PAGO_VIGENTE) {
            return '';
        }

        return ' <i class="fas fa-arrows-left-right prov-marca prov-forma-distinta" title="'
            + escapar('El pago se registró por ' + f.FORMA_PAGO + ', pero esta factura se '
                + 'trata como ' + f.FORMA_PAGO_VIGENTE + ', que es lo que decide si entra al '
                + 'cashflow. Si la vía cambió de verdad, actualizá el maestro o cambiá la '
                + 'forma de esta factura.') + '"></i>';
    }

    /* ================================================================
       EXCLUIR FACTURAS DEL CASHFLOW

       NO ES LO MISMO que el rubro "Excluidos" del maestro, que es por
       proveedor. Esto es por comprobante: una factura duplicada, una en
       disputa o una que se pagó por fuera de Tango no son un problema del
       proveedor.

       SE ELIGEN Y SE CONFIRMAN JUNTAS, con UN motivo para todas. Excluir ocho
       facturas del mismo proveedor es UNA decisión, y ocho motivos distintos
       para una decisión son ocho oportunidades de que digan cosas distintas.
       El caso se resuelve con el buscador: filtrar el proveedor, "seleccionar
       todas las que se ven", y un motivo.

       Por eso la columna es de SELECCIÓN y no un tilde que actúa solo: sacar
       plata del tablero no puede dispararse con un clic suelto.

       ESA COLUMNA HOY ALIMENTA DOS ACCIONES. La otra —poner la misma fecha de
       pago a todas— está más abajo y es la que se usa todos los días; ésta es
       la excepción. Las dos comparten la selección, el podado contra lo que
       vino del servidor y la barra que dice cuántas y por cuánto.
       ================================================================ */

    /** Claves de las facturas seleccionadas. Sobrevive a los redibujos. */
    var seleccion = {};

    /** Por qué los dos botones de excluir pueden estar apagados */
    var FALTA_EXCLUIR = 'Para excluir facturas hace falta correr '
        + 'sql/cashflow_prov_locales_excluir_factura.sql contra la base central.';

    function claveFila(f) {
        return f.COD_PROVEE + '|' + f.T_COMP + '|' + f.N_COMP;
    }

    /**
     * La celda de selección.
     *
     * EL CHECK SE DIBUJA AUNQUE FALTE EL SCRIPT DE LA EXCLUSIÓN. La selección
     * alimenta las dos acciones masivas y sólo una de las dos necesita ese
     * script: sin él se puede fechar igual, y lo que se apaga son los dos
     * botones de excluir —eso lo hace pintarSeleccion()—. Esconder el check
     * dejaría sin la acción de todos los días a quien no corrió un script que
     * no tiene nada que ver con ella.
     */
    function celdaExcluir(f) {
        var clave = claveFila(f);
        var marca = f.EXCLUIDA_MANUAL
            ? '<div><span class="prov-badge-excluida" title="'
              + escapar('Excluida del cashflow: '
                  + (f.MOTIVO_EXCLUSION || 'sin motivo registrado'))
              + '">excluida</span></div>'
            : '';

        return '<input type="checkbox" class="form-check-input prov-sel"'
            + (seleccion[clave] ? ' checked' : '')
            + ' data-clave="' + escapar(clave) + '"'
            + ' title="' + escapar('Seleccionar esta factura para ponerle fecha de pago, '
                + 'excluirla o volver a incluirla.') + '">' + marca;
    }

    /** Saca de la selección lo que ya no está en el listado */
    function podarSeleccion() {
        var vivas = {};

        ((datos && datos.filas) || []).forEach(function(f) { vivas[claveFila(f)] = true; });

        Object.keys(seleccion).forEach(function(k) {
            if (!vivas[k]) { delete seleccion[k]; }
        });
    }

    /** Las filas seleccionadas que hoy están a la vista */
    function filasSeleccionadas() {
        return filasVisibles().filter(function(f) { return !!seleccion[claveFila(f)]; });
    }

    /**
     * La barra de acciones. Dice CUÁNTAS y CUÁNTO antes de que se apriete nada:
     * fechar mueve esa plata de columna y excluir la saca del tablero, y el
     * importe es el dato que hace que alguien note que seleccionó de más.
     */
    function pintarSeleccion() {
        var sel = filasSeleccionadas();
        var total = 0;
        var yaExcluidas = 0;

        sel.forEach(function(f) {
            total += Number(f.IMPORTE_PENDIENTE) || 0;
            if (f.EXCLUIDA_MANUAL) { yaExcluidas++; }
        });

        mostrar('barraSelProv', sel.length > 0);

        if (!sel.length) {
            sincronizarSelTodas();
            return;
        }

        texto('selResumenProv', sel.length + ' factura(s) seleccionada(s) · ' + plata(total)
            + (yaExcluidas ? ' · ' + yaExcluidas + ' ya excluida(s)' : ''));

        /* Cada botón se apaga cuando no tiene nada que hacer: "Excluir" con
           todo ya excluido, o "Volver a incluir" sin ninguna excluida. Un botón
           que se puede apretar y no cambia nada es peor que uno apagado.

           SIN EL SCRIPT DE LA EXCLUSIÓN los dos quedan apagados y lo dicen en
           el título: la pantalla sigue andando para fechar, que es lo que no
           depende de ese script. */
        var puedeExcluir = !!(datos && datos.excluir_factura);
        var btnEx = document.getElementById('btnExcluirSelProv');
        var btnIn = document.getElementById('btnIncluirSelProv');

        if (btnEx) {
            btnEx.disabled = !puedeExcluir || (yaExcluidas === sel.length);
            btnEx.title = puedeExcluir ? '' : FALTA_EXCLUIR;
        }

        if (btnIn) {
            btnIn.disabled = !puedeExcluir || (yaExcluidas === 0);
            btnIn.title = puedeExcluir ? '' : FALTA_EXCLUIR;
        }

        sincronizarSelTodas();
    }

    /** El checkbox del encabezado refleja si TODO lo visible está seleccionado */
    function sincronizarSelTodas() {
        var chk = document.getElementById('selTodasProv');

        if (!chk) { return; }

        var visibles = filasVisibles();
        var elegidas = visibles.filter(function(f) { return !!seleccion[claveFila(f)]; }).length;

        chk.checked = (visibles.length > 0 && elegidas === visibles.length);
        chk.indeterminate = (elegidas > 0 && elegidas < visibles.length);
    }

    function conectarSeleccion() {
        document.querySelectorAll('#bodyProv .prov-sel').forEach(function(chk) {
            chk.addEventListener('change', function() {
                var k = chk.getAttribute('data-clave');

                if (chk.checked) {
                    seleccion[k] = true;
                } else {
                    delete seleccion[k];
                }

                pintarSeleccion();
            });
        });
    }

    /**
     * Excluye o incluye lo seleccionado, con UN motivo para todas.
     *
     * El motivo se pide en un diálogo del módulo y no con el prompt del
     * navegador: acá hay que leer cuántas facturas y por cuánta plata antes de
     * escribir nada, y eso en un prompt no entra.
     */
    function accionSeleccion(excluir) {
        var sel = filasSeleccionadas();

        if (!sel.length) { return; }

        // Lo que ya está como se lo quiere dejar no se vuelve a escribir: sería
        // una versión idéntica en la tabla y un número inflado en el mensaje.
        var aplicar = sel.filter(function(f) { return !!f.EXCLUIDA_MANUAL !== excluir; });

        if (!aplicar.length) { return; }

        var total = 0;
        var provs = {};

        aplicar.forEach(function(f) {
            total += Number(f.IMPORTE_PENDIENTE) || 0;
            provs[f.COD_PROVEE] = true;
        });

        var cuantosProv = Object.keys(provs).length;
        var detalle = aplicar.length + ' factura(s) por ' + plata(total)
            + (cuantosProv === 1
                ? ', todas de ' + aplicar[0].COD_PROVEE + ' — ' + aplicar[0].RAZON_SOC
                : ', de ' + cuantosProv + ' proveedores') + '.';

        if (!excluir) {
            Notificacion.confirmar({
                titulo: 'Volver a incluir en el cashflow',
                mensaje: '¿Devolver estas facturas al cashflow?',
                detalle: detalle + ' Sus importes vuelven a la fila del tablero y el motivo '
                    + 'de exclusión se borra.',
                confirmar: 'Volver a incluir'
            }).then(function(ok) {
                if (ok) { guardarExclusion(aplicar, false, null); }
            });

            return;
        }

        Notificacion.pedirTexto({
            titulo: 'Excluir del cashflow',
            peligro: true,
            mensaje: detalle,
            detalle: 'Sus importes salen de la fila del tablero y quedan informados aparte, '
                + 'con este motivo.',
            etiqueta: 'Motivo (el mismo para todas)',
            placeholder: 'Ej.: duplicada en Tango, en disputa, se pagó por fuera…',
            maxlargo: 200,
            valor: valorComun(aplicar, 'MOTIVO_EXCLUSION'),
            invalido: 'Escribí el motivo: es lo único que después explica por qué falta ese '
                + 'importe en el tablero.',
            confirmar: 'Excluir ' + aplicar.length + ' factura(s)'
        }).then(function(motivo) {
            if (motivo !== null) { guardarExclusion(aplicar, true, motivo); }
        });
    }

    /**
     * Si las seleccionadas ya compartían un valor en ese campo, se ofrece de
     * arranque. Si hay dos distintos no se elige uno: el diálogo abre vacío,
     * porque proponer el de la primera fila sería decidir por el usuario.
     *
     * Lo usan el motivo de la exclusión y la fecha del fechado masivo: es la
     * misma pregunta —"¿ya venían todas iguales?"— sobre dos columnas.
     */
    function valorComun(filas, campo) {
        var unico = null;

        for (var i = 0; i < filas.length; i++) {
            var m = filas[i][campo] || '';

            if (m === '') { continue; }
            if (unico !== null && unico !== m) { return ''; }

            unico = m;
        }

        return unico || '';
    }

    function guardarExclusion(filas, excluir, motivo) {
        var cuerpo = {
            excluida: excluir,
            comprobantes: filas.map(function(f) {
                return { cod_provee: f.COD_PROVEE, t_comp: f.T_COMP, n_comp: f.N_COMP };
            })
        };

        if (motivo !== null) { cuerpo.motivo = motivo; }

        // La selección se limpia al guardar: las filas excluidas se esconden por
        // defecto, así que dejarlas seleccionadas mantendría una barra hablando
        // de facturas que ya no están a la vista.
        seleccion = {};

        pedirPago('saveExclusion', cuerpo);
    }

    /* ================================================================
       LA MISMA FECHA DE PAGO PARA VARIAS FACTURAS

       ES EL MISMO GESTO QUE LA EXCLUSIÓN MASIVA: se seleccionan con los
       checks, se ve cuántas son y por cuánta plata, se elige la fecha y
       recién ahí se guarda. Y es la acción que se usa todos los días: lo
       que disuelve los vencimientos apilados en el primer día del eje es
       cargar fechas, y el caso real no es una factura sino las ocho de un
       proveedor al que se le decide una fecha de una vez.

       SÓLO LA FECHA. La forma del cronograma y la exclusión de cada factura
       son otras decisiones y este gesto no las toca — ni acá ni en el
       backend, que escribe una sola columna.
       ================================================================ */

    /**
     * Pone la misma fecha a todas las seleccionadas.
     *
     * NO SE FILTRA LO QUE "YA ESTÁ ASÍ", al revés que al excluir, y la
     * diferencia es cuándo se sabe el estado final: excluir tiene dos estados y
     * se conocen antes de preguntar nada, así que lo que ya está excluido se
     * saca del lote. Acá el estado final es la fecha, y no existe hasta que se
     * elige. Volver a escribir la misma fecha no es un error: es alguien
     * ratificando la decisión, y queda con su fecha de modificación.
     *
     * LO QUE SÍ SE DICE ANTES es cuántas de las elegidas ya tenían una fecha
     * cargada, porque esas son decisiones de alguien que este gesto pisa.
     */
    function fecharSeleccion() {
        var sel = filasSeleccionadas();

        if (!sel.length) { return; }

        var total = 0;
        var provs = {};
        var yaTenian = 0;
        var conciliadas = 0;

        sel.forEach(function(f) {
            total += Number(f.IMPORTE_PENDIENTE) || 0;
            provs[f.COD_PROVEE] = true;

            if (f.ORIGEN_FECHA === 'CARGADA') { yaTenian++; }
            if (f.ESTADO_PAGO === 'CONCILIADO') { conciliadas++; }
        });

        var cuantosProv = Object.keys(provs).length;

        var mensaje = sel.length + ' factura(s) por ' + plata(total)
            + (cuantosProv === 1
                ? ', todas de ' + sel[0].COD_PROVEE + ' — ' + sel[0].RAZON_SOC
                : ', de ' + cuantosProv + ' proveedores') + '.';

        var detalle = 'Sus importes pasan a la columna de esa fecha en el tablero. '
            + 'No se toca ni la forma de pago ni la exclusión de ninguna.';

        // Pisar la fecha que puso otro es legítimo, pero no puede ser una
        // sorpresa: el número va antes de elegir, no después de guardar.
        if (yaTenian) {
            detalle += ' ' + yaTenian + ' ya ten' + (yaTenian === 1 ? 'ía' : 'ían')
                + ' una fecha cargada y se pisa' + (yaTenian === 1 ? '' : 'n') + '.';
        }

        /* Una conciliada ya se pagó y Tango tiene la fecha real: cambiarle la
           previsión no la desconcilia ni toca ese dato, pero sí cambia el
           desvío que después dice si le acertamos a la fecha. */
        if (conciliadas) {
            detalle += ' ' + conciliadas + ' está(n) conciliada(s): se les cambia la '
                + 'previsión, no la fecha real en la que se pagaron.';
        }

        Notificacion.pedirFecha({
            titulo: 'Fecha de pago para varias facturas',
            mensaje: mensaje,
            detalle: detalle,
            etiqueta: 'Fecha de pago (la misma para todas)',
            /* Sin `min`, igual que la celda de la grilla: acá se aceptan fechas
               pasadas porque el listado no tiene techo de antigüedad y "se
               pensó pagar y no se pagó" es una decisión legítima. */
            valor: valorComun(sel, 'FECHA_PAGO'),
            invalido: 'Elegí la fecha en la que se piensan pagar: es lo único que saca '
                + 'estos importes del primer día del eje.',
            confirmar: 'Fechar ' + sel.length + ' factura(s)'
        }).then(function(fecha) {
            if (fecha !== null) { guardarFechaMasiva(sel, fecha); }
        });
    }

    function guardarFechaMasiva(filas, fecha) {
        /* LA SELECCIÓN NO SE LIMPIA, al revés que al excluir, y la diferencia
           es si las filas siguen a la vista: una excluida se esconde por
           defecto, así que la barra quedaría hablando de facturas que ya no
           están en la tabla. Una fechada sigue estando —se movió de columna—,
           y dejarla seleccionada es lo que permite corregir la fecha ahí mismo
           si el importe cayó donde no iba. Lo que ya no esté lo saca
           podarSeleccion() con lo que vuelva del servidor. */
        pedirPago('saveFechaMasiva', {
            fecha_pago: fecha,
            comprobantes: filas.map(function(f) {
                return { cod_provee: f.COD_PROVEE, t_comp: f.T_COMP, n_comp: f.N_COMP };
            })
        });
    }

    /* ================================================================
       EDICIÓN DE LA FECHA, DE A UNA
       ================================================================ */

    function conectarEdicion() {
        document.querySelectorAll('#bodyProv .prov-input-fecha').forEach(function(inp) {
            inp.addEventListener('change', function() {
                if (!inp.value) { return; }

                pedirPago('savePago', {
                    cod_provee: inp.getAttribute('data-cod'),
                    t_comp: inp.getAttribute('data-tcomp'),
                    n_comp: inp.getAttribute('data-ncomp'),
                    fecha_pago: inp.value
                });
            });
        });

        document.querySelectorAll('#bodyProv .prov-btn-borrar').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var inp = btn.closest('.input-group').querySelector('.prov-input-fecha');

                pedirPago('deletePago', {
                    cod_provee: inp.getAttribute('data-cod'),
                    t_comp: inp.getAttribute('data-tcomp'),
                    n_comp: inp.getAttribute('data-ncomp')
                });
            });
        });

        /* La forma del cronograma cambia en qué serie cae el importe: recargar
           entero es lo mismo que hace la fecha, y por lo mismo. El importe puede
           entrar o salir del filtro y desaparecer de la vista, y eso el mensaje
           lo dice. */
        /* Cambiar la forma cambia en qué serie cae el importe: recargar entero
           es lo mismo que hace la fecha, y por lo mismo. El importe puede entrar
           o salir del filtro y desaparecer de la vista, y eso el mensaje lo
           dice. Elegir "del maestro" manda vacío, que es lo que saca el
           override. */
        document.querySelectorAll('#bodyProv .prov-select-forma').forEach(function(sel) {
            sel.addEventListener('change', function() {
                pedirPago('saveFormaCronograma', {
                    cod_provee: sel.getAttribute('data-cod'),
                    t_comp: sel.getAttribute('data-t'),
                    n_comp: sel.getAttribute('data-n'),
                    forma: sel.value
                });
            });
        });

        conectarSeleccion();
    }

    /**
     * Guarda o borra y RECARGA todo.
     *
     * La fecha cambia en qué columna del eje cae el importe, los totales del pie
     * y los cuatro indicadores. Rehacer eso en el navegador sería reimplementar
     * en JS la cuenta que ya hace el backend.
     */
    function pedirPago(accion, cuerpo) {
        fetch('Controller/ProveedoresController.php?action=' + accion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                Notificacion.exito(res.message);
            } else {
                Notificacion.error(res.message);
            }

            cargar();
        })
        .catch(function(err) {
            Notificacion.error('Error de conexión: ' + err.message);
        });
    }

    /* ================================================================
       IMPORTACIÓN

       Dos pasos SIEMPRE: primero el diff, después aplicar. El diff se guarda
       en `preview` y se reenvía al confirmar, así el servidor aplica
       exactamente lo que el usuario vio y no lo que vuelva a calcular.
       ================================================================ */

    function previewImportacion(que) {
        var input = document.getElementById('archivo' + que + 'Prov');

        if (!input || !input.files || !input.files.length) {
            Notificacion.error('Elegí un archivo CSV primero.');
            return;
        }

        var form = new FormData();
        form.append('archivo', input.files[0]);

        fetch('Controller/ProveedoresController.php?action=preview' + que, {
            method: 'POST',
            body: form
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Notificacion.error(res.message);
                mostrar('previewProv', false);
                return;
            }

            preview = { que: que, data: res.data };
            pintarPreview(que, res.data);
        })
        .catch(function(err) {
            Notificacion.error('Error de conexión: ' + err.message);
        });
    }

    function pintarPreview(que, d) {
        var cont = document.getElementById('previewProv');
        var r = d.resumen;

        var chips = que === 'Pagos'
            ? [['altas', 'Altas', 'success'], ['cambios', 'Cambios', 'warning'],
               ['sin_cambios', 'Sin cambios', 'secondary'], ['errores', 'No se cargan', 'danger']]
            : [['altas', 'Altas', 'success'], ['cambios', 'Cambios', 'warning'],
               ['sin_cambios', 'Sin cambios', 'secondary'], ['errores', 'No se cargan', 'danger'],
               ['bajas', 'Bajas', 'dark']];

        var html = '<div class="card">'
            + '<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">'
            +   '<h6 class="mb-0">Esto es lo que cambiaría — todavía no se guardó nada</h6>'
            +   '<div class="d-flex gap-2 align-items-center">';

        // Las bajas del maestro se confirman aparte: una planilla filtrada por
        // error daría de baja medio maestro.
        if (que === 'Maestro' && r.bajas > 0) {
            html += '<div class="form-check form-switch me-2">'
                +     '<input class="form-check-input" type="checkbox" id="aplicarBajasProv">'
                +     '<label class="form-check-label small" for="aplicarBajasProv">'
                +       'Dar de baja los ' + r.bajas + ' que el archivo no trae</label>'
                +   '</div>';
        }

        /* NO SE PUEDE CONFIRMAR CON UN ORIGEN CAÍDO. El backend lo decide —es
           el que sabe si pudo leer CPA01 y las listas— y acá se apaga el botón
           y se dice por qué en el mismo lugar donde se lo iba a apretar. Un
           botón apagado sin motivo se lee como una pantalla rota, y el motivo
           sólo en los avisos de más abajo se lee después de haber intentado.

           La previsualización SE MUESTRA IGUAL: ver qué cambiaría no hace daño
           y sigue sirviendo para saber en qué estado está la planilla. */
        var bloqueos = (r.bloqueos || []);
        var frenado = (r.puede_confirmar === false) || bloqueos.length > 0;

        html += '<button class="btn btn-sm btn-primary" id="btnConfirmarProv"'
            +     (frenado ? ' disabled title="' + escapar(bloqueos.join(' ')) + '"' : '') + '>'
            +     '<i class="fas fa-check me-1"></i> Confirmar e importar</button>'
            +   '</div></div><div class="card-body">';

        html += '<div class="d-flex gap-2 flex-wrap mb-3">';
        chips.forEach(function(c) {
            html += '<span class="badge bg-' + c[2] + '">' + c[1] + ': ' + (r[c[0]] || 0) + '</span>';
        });
        html += '</div>';

        bloqueos.forEach(function(b) {
            html += '<div class="alert alert-danger py-2 px-3 small mb-2">'
                + '<strong>No se puede confirmar esta importación.</strong> ' + escapar(b)
                + '</div>';
        });

        (d.avisos || []).forEach(function(a) {
            /* Los bloqueos ya se pintaron arriba, en rojo y con su título: el
               backend los repite en los avisos para quien lea la respuesta sin
               pantalla, y repetirlos acá sería decir dos veces lo mismo. */
            if (a.indexOf('NO SE PUEDE CONFIRMAR') !== -1) { return; }

            html += '<div class="alert alert-warning py-2 px-3 small mb-2">' + escapar(a) + '</div>';
        });

        html += listaValoresFueraDeLista(d);
        html += tablaPreview(que, d);
        html += '</div></div>';

        cont.innerHTML = html;
        mostrar('previewProv', true);

        if (!frenado) {
            conectar('btnConfirmarProv', confirmarImportacion);
        }
    }

    /**
     * Los valores rechazados por no estar en las listas, agrupados por lista y
     * sin repetir.
     *
     * ES LO QUE HACE QUE EL CAMBIO SEA APLICABLE. Desde que un valor fuera de
     * lista deja la fila en ERROR, importar la planilla tal como está hoy puede
     * rechazar filas en masa si las cinco listas de Parámetros no reflejan todos
     * los valores en uso. Descubrirlos de a uno —corregir, reimportar, encontrar
     * el siguiente— serían decenas de vueltas sobre un archivo de 1.223 filas.
     *
     * Acá están todos juntos, agrupados por la lista a la que hay que agregarlos,
     * así se dan de alta en Parámetros de una sola pasada.
     *
     * Los junta el backend (`resumen.valores_fuera_de_lista`), que es el que
     * sabe comparar como comparan las listas: 'DEPOSITO SUR' y 'Deposito Sur'
     * son UN valor para dar de alta, no dos.
     */
    function listaValoresFueraDeLista(d) {
        var porTipo = (d.resumen && d.resumen.valores_fuera_de_lista) || {};
        var tipos = Object.keys(porTipo);

        if (!tipos.length) { return ''; }

        var nombres = d.opciones_tipos || {};

        return '<div class="alert alert-danger py-2 px-3 small mb-3">'
            + '<strong>Valores que hay que dar de alta en Parámetros → Prov. Locales</strong> '
            + '(o corregir en la planilla, si son un error de tipeo). Están agrupados y sin '
            + 'repetir para poder cargarlos de una sola pasada; después volvé a importar.'
            + tipos.map(function(t) {
                return '<div class="mt-2"><u>' + escapar(nombres[t] ? nombres[t].nombre : t)
                    + '</u>: ' + porTipo[t].map(function(v) {
                        return '<code>' + escapar(v) + '</code>';
                      }).join(' · ') + '</div>';
              }).join('')
            + '</div>';
    }

    function tablaPreview(que, d) {
        var filas = d.filas || [];

        // Lo que no se puede cargar va PRIMERO: es lo que hay que mirar antes de
        // confirmar. Después los cambios, y al final lo que no cambia.
        var orden = { ERROR: 0, CAMBIO: 1, ALTA: 2, SIN_CAMBIOS: 3 };

        filas = filas.slice().sort(function(a, b) {
            return (orden[a.estado] - orden[b.estado]) || (a.linea - b.linea);
        });

        var html = '<div class="table-responsive prov-preview-tabla"><table class="table table-sm mb-0">'
            + '<thead><tr><th>Línea</th><th>Clave</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';

        filas.forEach(function(f) {
            var clave = (que === 'Pagos')
                ? (f.cod_provee + ' ' + (f.t_comp || '') + ' ' + f.n_comp)
                : f.cod_provee + (f.nombre ? ' — ' + f.nombre : '');

            var detalle = escapar(f.motivo || '');

            if (f.cambios && f.cambios.length) {
                detalle += '<ul class="mb-0 small">' + f.cambios.map(function(c) {
                    return '<li>' + escapar(c.campo) + ': <s>' + escapar(c.antes || '(vacío)')
                        + '</s> → <strong>' + escapar(c.ahora || '(vacío)') + '</strong></li>';
                }).join('') + '</ul>';
            }

            /* El que pisa una versión cargada a mano se marca en la fila, no
               sólo en el aviso de arriba: entre trescientos cambios, el aviso
               dice cuántos son y esto dice cuáles. */
            var pisa = f.pisa_manual
                ? ' <span class="badge bg-warning text-dark" title="'
                  + escapar('Este proveedor se había editado a mano desde la pantalla. La '
                      + 'planilla manda, así que esta importación lo sobrescribe. La versión '
                      + 'manual queda en el historial.')
                  + '">pisa carga manual</span>'
                : '';

            /* EL CÓDIGO INEXISTENTE SE MARCA APARTE del resto de los errores.
               Es el único que se arregla mirando OTRO sistema —hay que ir a
               Tango a buscar el código de verdad— y no releyendo la planilla. */
            if (f.no_en_tango) {
                pisa += ' <span class="badge bg-danger" title="'
                    + escapar('El código no existe en CPA01, el maestro de proveedores de '
                        + 'Tango. Buscalo en Tango y corregí la planilla.')
                    + '">no está en Tango</span>';
            }

            /* UN VALOR FUERA DE LISTA ES UN ERROR Y LA FILA NO SE CARGA. Esto
               CAMBIÓ: antes se importaba igual, marcada en amarillo.

               La marca dice CUÁLES campos y con QUÉ valor, porque "hay algo
               fuera de lista" sin decir qué obliga a abrir la planilla y
               buscarlo. Y se sigue marcando aparte del motivo aunque casi
               siempre coincidan: cuando además el código no existe, el motivo
               lo ocupa CPA01 —que se arregla en otro sistema— y esta marca es
               lo único que dice que encima hay un valor que dar de alta. */
            var fuera = f.fuera_lista && Object.keys(f.fuera_lista);

            if (fuera && fuera.length) {
                var tipos = (d.opciones_tipos) || {};

                pisa += ' <span class="badge bg-danger" title="'
                    + escapar('NO se carga. El valor no se agrega solo a la lista: si es '
                        + 'correcto, cargalo en Parámetros → Prov. Locales; si es un typo, '
                        + 'corregilo en la planilla. '
                        + fuera.map(function(t) {
                            return (tipos[t] ? tipos[t].nombre : t) + ': "'
                                + f.fuera_lista[t] + '"';
                          }).join(' · '))
                    + '">fuera de lista (' + fuera.length + ')</span>';
            }

            html += '<tr class="prov-pre-' + f.estado.toLowerCase() + '">'
                + '<td>' + f.linea + '</td>'
                + '<td><code>' + escapar(clave) + '</code></td>'
                + '<td><span class="badge bg-' + colorEstado(f.estado) + '">'
                +   escapar(f.estado) + '</span>' + pisa + '</td>'
                + '<td class="small">' + detalle + '</td></tr>';
        });

        html += '</tbody></table></div>';

        if (que === 'Maestro' && (d.bajas || []).length) {
            html += '<h6 class="mt-3">Se darían de baja</h6>'
                + '<div class="small text-muted">'
                + d.bajas.map(function(b) {
                    return escapar(b.cod_provee + (b.nombre ? ' (' + b.nombre + ')' : ''));
                  }).join(' · ')
                + '</div>';
        }

        return html;
    }

    function colorEstado(e) {
        return { ALTA: 'success', CAMBIO: 'warning', SIN_CAMBIOS: 'secondary',
                 ERROR: 'danger' }[e] || 'secondary';
    }

    function confirmarImportacion() {
        if (!preview) { return; }

        var cuerpo = { comparacion: preview.data };
        var bajas = document.getElementById('aplicarBajasProv');

        if (bajas) { cuerpo.aplicar_bajas = bajas.checked; }

        fetch('Controller/ProveedoresController.php?action=aplicar' + preview.que, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                Notificacion.exito(res.message);
                mostrar('previewProv', false);
                preview = null;
                maestro = null;      // quedó viejo
                cargar();
            } else {
                Notificacion.error(res.message);
            }
        })
        .catch(function(err) {
            Notificacion.error('Error de conexión: ' + err.message);
        });
    }

    /* ================================================================
       CONCILIACIÓN
       ================================================================ */

    function previewConciliacion() {
        pedirJson('Controller/ProveedoresController.php?action=previewConciliacion')
            .then(function(d) {
                if (!d.resumen.concilia && !d.resumen.reabre) {
                    Notificacion.exito((d.avisos && d.avisos[0])
                        || 'Nada cambió respecto de Tango.');
                    return;
                }

                pintarPreviewConciliacion(d);
                cambiarVista('importar');
            })
            .catch(function(err) {
                Notificacion.error('No se pudo conciliar: ' + err.message);
            });
    }

    function pintarPreviewConciliacion(d) {
        var html = '<div class="card"><div class="card-header d-flex justify-content-between '
            + 'align-items-center flex-wrap gap-2">'
            + '<h6 class="mb-0">Conciliación contra Tango — todavía no se guardó nada</h6>'
            + '<button class="btn btn-sm btn-primary" id="btnConfirmarConcProv">'
            +   '<i class="fas fa-check me-1"></i> Confirmar</button>'
            + '</div><div class="card-body">';

        (d.avisos || []).forEach(function(a) {
            html += '<div class="alert alert-info py-2 px-3 small mb-2">' + escapar(a) + '</div>';
        });

        if (d.concilia.length) {
            html += '<h6 class="mt-2">Ya se pagaron</h6>'
                + '<div class="table-responsive prov-preview-tabla"><table class="table table-sm mb-0">'
                + '<thead><tr><th>Proveedor</th><th>Comprobante</th><th>Prevista</th>'
                + '<th>Real</th><th>Desvío</th></tr></thead><tbody>';

            d.concilia.forEach(function(c) {
                var desvio = (c.desvio_dias === null) ? '—'
                    : (c.desvio_dias > 0 ? '+' : '') + c.desvio_dias + ' d';

                html += '<tr><td>' + escapar(c.cod_provee) + '</td>'
                    + '<td><code>' + escapar(c.t_comp + ' ' + c.n_comp) + '</code></td>'
                    + '<td>' + fechaCorta(c.fecha_prevista) + '</td>'
                    + '<td>' + fechaCorta(c.fecha_cancelado) + '</td>'
                    + '<td class="' + (c.desvio_dias > 0 ? 'text-danger' : 'text-success')
                    +   '">' + desvio + '</td></tr>';
            });

            html += '</tbody></table></div>';
        }

        if (d.reabre.length) {
            html += '<h6 class="mt-3">Volvieron a estar pendientes</h6>'
                + '<div class="small text-muted">'
                + d.reabre.map(function(r) {
                    return escapar(r.cod_provee + ' ' + r.t_comp + ' ' + r.n_comp);
                  }).join(' · ')
                + '</div>';
        }

        html += '</div></div>';

        document.getElementById('previewProv').innerHTML = html;
        mostrar('previewProv', true);

        conectar('btnConfirmarConcProv', function() {
            pedirJson('Controller/ProveedoresController.php?action=aplicarConciliacion')
                .then(function() {
                    Notificacion.exito('Conciliación aplicada.');
                    mostrar('previewProv', false);
                    cargar();
                })
                .catch(function(err) {
                    Notificacion.error('No se pudo aplicar: ' + err.message);
                });
        });
    }

    /* ================================================================
       MAESTRO
       ================================================================ */

    /**
     * El control que evita que el maestro se desactualice sin que nadie se
     * entere: proveedores con deuda que no están en la planilla.
     */
    function pintarFaltantes() {
        var cont = document.getElementById('faltantesProv');
        var faltan = (datos && datos.faltantes) || [];
        var disc = (maestro && maestro.directores_no_excluidos) || [];
        var html = '';

        if (faltan.length) {
            var total = faltan.reduce(function(a, f) { return a + Number(f.IMPORTE); }, 0);

            html += '<div class="alert alert-warning">'
                + '<strong>' + faltan.length + ' proveedor(es) con deuda no están en el maestro</strong>'
                + ' — ' + plata(total) + ' sin poder clasificar.'
                + '<div class="small mt-2">'
                + faltan.slice(0, 12).map(function(f) {
                    return escapar(f.COD_PROVEE) + ' (' + plataCorta(f.IMPORTE) + ')';
                  }).join(' · ')
                + (faltan.length > 12 ? ' y ' + (faltan.length - 12) + ' más' : '')
                + '</div></div>';
        }

        if (disc.length) {
            html += '<div class="alert alert-info small">'
                + '<strong>' + disc.length + ' proveedor(es)</strong> figuran como egreso de '
                + 'directores en Tango pero el maestro no los marca como "Excluidos". '
                + 'Manda el maestro; esto es un control. '
                + disc.map(function(d) { return escapar(d.COD_PROVEE); }).join(' · ')
                + '</div>';
        }

        /* EL CONTROL AL REVÉS: clasificación sin proveedor.
           El de arriba busca deuda que no se puede clasificar; éste busca
           proveedores cargados cuyo código Tango no tiene, que no van a cruzar
           contra ninguna cuenta a pagar nunca. Casi siempre son códigos
           tipeados mal antes de que hubiera validación.
           SÓLO AVISA: no se da de baja nada. Una baja automática borraría la
           clasificación de una deuda que puede seguir existiendo. */
        var noTango = (maestro && maestro.no_en_tango) || [];

        if (noTango.length) {
            html += '<div class="alert alert-danger small">'
                + '<strong>' + noTango.length + ' proveedor(es) vigentes del maestro no existen '
                + 'en CPA01</strong>, el maestro de proveedores de Tango. No van a clasificar '
                + 'ninguna deuda: su código no cruza contra nada. Revisalos y corregí el código '
                + '(o dalos de baja, si ya no van). No se da de baja nada automáticamente.'
                + '<div class="mt-2">'
                + noTango.slice(0, 12).map(function(f) {
                    return '<code>' + escapar(f.COD_PROVEE) + '</code>'
                        + (f.NOMBRE ? ' ' + escapar(f.NOMBRE) : '')
                        + (f.ORIGEN === 'MANUAL' ? ' <em>(carga manual)</em>' : '');
                  }).join(' · ')
                + (noTango.length > 12 ? ' y ' + (noTango.length - 12) + ' más' : '')
                + '</div></div>';
        }

        cont.innerHTML = html;
    }

    function pintarMaestro() {
        if (!maestro) { return; }

        var q = ((document.getElementById('busquedaMaestroProv') || {}).value || '')
            .trim().toLowerCase();

        var filas = maestro.filas.filter(function(f) {
            if (q === '') { return true; }

            return [f.COD_PROVEE, f.NOMBRE, f.RUBRO_ECONOMICO, f.RUBRO, f.CENTRO_COSTOS]
                .join(' ').toLowerCase().indexOf(q) !== -1;
        });

        var editable = !!maestro.edicion_manual;

        var html = filas.map(function(f) {
            return '<tr>'
                + '<td><strong>' + escapar(f.COD_PROVEE) + '</strong></td>'
                + '<td class="col-texto" title="' + escapar(f.NOMBRE || '') + '">'
                +   escapar(f.NOMBRE || '') + '</td>'
                + '<td>' + escapar(f.RUBRO_ECONOMICO || '—') + '</td>'
                + '<td>' + escapar(f.RUBRO || '—') + '</td>'
                + '<td>' + escapar(f.CENTRO_COSTOS || '—') + '</td>'
                + '<td>' + celdaFormaMaestro(f) + '</td>'
                + '<td>' + escapar(f.PLAZO_PAGO || '—')
                +   (f.PLAZO_DIAS !== null ? ' <span class="text-muted small">('
                      + f.PLAZO_DIAS + ' d)</span>' : '') + '</td>'
                + '<td class="text-center">' + celdaOrigen(f) + '</td>'
                + '<td class="text-center"><span class="text-muted small">'
                +   escapar((f.FECHA_IMPORTACION || '').substring(0, 10)) + '</span></td>'
                + '<td class="text-center">'
                +   (editable
                        ? '<button class="btn btn-sm btn-outline-secondary py-0 px-2 prov-editar" '
                          + 'data-cod="' + escapar(f.COD_PROVEE) + '" title="Editar. No modifica '
                          + 'la fila: da de baja la vigente y carga una nueva, y la anterior '
                          + 'queda en el historial.">'
                          + '<i class="fas fa-pen"></i></button> '
                          + '<button class="btn btn-sm btn-outline-danger py-0 px-2 prov-baja" '
                          + 'data-cod="' + escapar(f.COD_PROVEE) + '" title="Dar de baja. Su '
                          + 'deuda queda sin clasificar; la versión sigue en el historial.">'
                          + '<i class="fas fa-xmark"></i></button>'
                        : '')
                + '</td>'
                + '</tr>';
        }).join('');

        if (!filas.length) {
            html = '<tr><td colspan="10" class="text-center text-muted py-4">'
                 + (maestro.filas.length
                    ? 'Ningún proveedor coincide con el filtro.'
                    : 'El maestro está vacío: importá la hoja "Maestro proveedores" o agregá '
                      + 'los proveedores de a uno.')
                 + '</td></tr>';
        }

        document.getElementById('bodyMaestroProv').innerHTML = html;

        document.querySelectorAll('.prov-editar').forEach(function(b) {
            b.addEventListener('click', function() { abrirForm(b.getAttribute('data-cod')); });
        });

        document.querySelectorAll('.prov-baja').forEach(function(b) {
            b.addEventListener('click', function() { darDeBaja(b.getAttribute('data-cod')); });
        });

        mostrar('btnNuevoProv', editable);
        pintarSugerencias();
    }

    /**
     * De dónde salió la versión vigente.
     *
     * Una fila MANUAL no es un problema, pero sí es información: la planilla
     * manda, así que ésa es una de las que la próxima importación va a pisar.
     * Verlo acá es lo que permite decidir si conviene cargarla también en el
     * Excel.
     */
    function celdaOrigen(f) {
        if (f.ORIGEN !== 'MANUAL') {
            return '<span class="text-muted small" title="Vino de la planilla.">planilla</span>';
        }

        return '<span class="prov-origen-manual" title="'
            + escapar('Se cargó o se editó desde esta pantalla. La planilla sigue siendo la '
                + 'fuente: la próxima importación lo va a pisar, y el diff lo avisa antes.')
            + '">a mano</span>';
    }

    /* ================================================================
       CARGA Y EDICION MANUAL DEL MAESTRO

       UN SOLO FORMULARIO para el alta y para la edición, porque son la misma
       operación: el backend da de baja la versión vigente e inserta una
       nueva. Dos formularios distintos insinuarían que editar modifica en el
       lugar, y en este módulo nada lo hace.

       El CÓDIGO no se puede cambiar al editar: es la clave con la que la fila
       cruza contra Tango y contra las fechas de pago ya cargadas. Cambiarlo
       sería dar de baja un proveedor y dar de alta otro, y eso son dos gestos
       distintos que tienen que verse como tales.
       ================================================================ */

    /** Los valores que ya existen en el maestro, para los datalist del form */
    function pintarSugerencias() {
        var r = (maestro && maestro.rubros) || {};
        var mapa = {
            listaRubroEcoProv: r.rubro_economico,
            listaRubroProv: r.rubro,
            listaCentroProv: r.centro_costos
        };

        Object.keys(mapa).forEach(function(id) {
            var el = document.getElementById(id);

            if (!el) { return; }

            el.innerHTML = Object.keys(mapa[id] || {}).map(function(v) {
                return '<option value="' + escapar(v) + '">';
            }).join('');
        });

        var sel = document.getElementById('fpFormaProv');

        /* Los nombres son los VALORES de la lista, no sus claves.

           SE ORDENA ALFABÉTICO ACÁ. La constante FORMAS_PAGO del backend está
           en orden de uso —TRANSFERENCIA y ECHEQ primero, que son las dos del
           cronograma— y ese orden ya no llega a la pantalla: es el mismo
           criterio que las otras cinco listas, y tener un desplegable ordenado
           por uso al lado de cinco ordenados alfabéticamente obliga a buscar de
           dos maneras distintas en el mismo formulario. */
        if (sel && !sel.options.length) {
            sel.innerHTML = '<option value="">(sin forma)</option>'
                + alfabetico((maestro && maestro.formas_pago) || []).map(function(f) {
                    return '<option value="' + escapar(f) + '">' + escapar(f) + '</option>';
                }).join('');
        }

        aplicarListas();
    }

    /**
     * Ordena valores alfabéticamente como los lee alguien que escribe en
     * castellano.
     *
     * CON sort() PELADO, 'Ñandú' y los acentos se van al final, porque compara
     * por código de caracter. localeCompare con 'es' y sensitivity 'base' es lo
     * que pone 'Ñandú' entre 'Nafta' y 'Obras', que es donde se lo busca.
     *
     * No muta el array que recibe: varios de los que llegan acá son los mismos
     * que guarda `maestro`.
     */
    function alfabetico(valores) {
        return (valores || []).slice().sort(function(a, b) {
            return String(a).localeCompare(String(b), 'es', { sensitivity: 'base' });
        });
    }

    /* ================================================================
       LAS CINCO LISTAS DE OPCIONES

       Rubro económico, rubro, centro de costos, plazo y criterio pasan de ser
       texto libre a ser desplegables poblados desde Parámetros → Prov.
       Locales. El motivo está en el encabezado de Class/ProveedoresOpciones.php,
       y el que importa es éste: CADA RUBRO ECONÓMICO DISTINTO CREA UNA FILA
       PROPIA EN EL TABLERO. Tipear "Alquileres " con un espacio al final no es
       un typo cosmético, es una fila del cuadro que nadie pidió.

       SIN LISTAS CARGADAS NO SE CAMBIA NADA. Si el script no se corrió, el
       backend manda 'opciones' en null y los campos siguen siendo texto libre
       con sugerencias, que es como funcionaban antes. Dibujar desplegables
       vacíos dejaría una pantalla donde no se puede cargar nada, y el aviso del
       maestro ya dice qué script falta.
       ================================================================ */

    /** Qué campo del formulario corresponde a cada lista */
    var CAMPOS_LISTA = {
        RUBRO_ECONOMICO: 'fpRubroEcoProv',
        RUBRO: 'fpRubroProv',
        CENTRO_COSTOS: 'fpCentroProv',
        PLAZO: 'fpPlazoProv',
        CRITERIO_DISTRIB: 'fpCriterioProv'
    };

    /**
     * Reemplaza los cinco campos de texto por desplegables con buscador.
     *
     * SE REEMPLAZA EL ELEMENTO, conservando el id: el resto del archivo lee y
     * escribe por id con valor()/setValor(), así que lo único que el reemplazo
     * tiene que respetar es responder a `.value` de lectura y de escritura. El
     * componente lo hace —ver crearSelectBuscable()— y por eso este cambio no
     * toca ni abrirForm() ni guardarProveedor().
     */
    function aplicarListas() {
        var listas = maestro && maestro.opciones;

        // null = no hay listas cargadas. Ver la nota de arriba.
        if (!listas) { return; }

        Object.keys(CAMPOS_LISTA).forEach(function(tipo) {
            var id = CAMPOS_LISTA[tipo];
            var el = document.getElementById(id);

            if (!el) { return; }

            /* Ya vienen alfabéticos del backend —lo ordena
               ProveedoresOpciones::vigentes()— y se vuelve a ordenar acá igual.
               No es desconfianza: es que el orden en el que se OFRECEN los
               valores es una decisión de esta pantalla, y dejarla escrita
               solamente en un ORDER BY la vuelve invisible para el que lee este
               archivo. Ordenar dos veces una lista de cincuenta no cuesta. */
            var valores = alfabetico(Object.keys(listas[tipo] || {}));

            /* UNA LISTA VACÍA NO REEMPLAZA NADA. Un desplegable con una sola
               opción vacía no deja cargar ese campo, y sería peor que el texto
               libre que había antes. Parámetros ya avisa que la lista está
               vacía. */
            if (!valores.length) { return; }

            /* YA ESTÁ CONVERTIDO. Sólo se repuebla si el formulario está
               CERRADO, y no es un detalle: pintarMaestro() —que llama acá—
               corre en cada tecla del buscador del maestro, y repoblar el campo
               le borra el valor elegido. Con el formulario abierto, eso sería
               vaciarle los campos a alguien mientras los está cargando, y en
               silencio. Las listas sólo cambian desde otra pestaña, así que
               esperar a que el formulario se cierre no atrasa nada. */
            if (el.buscable) {
                if (!visible('formProvWrap')) {
                    el.buscable.repoblar(valores);
                }

                return;
            }

            crearSelectBuscable(id, valores);
        });
    }

    /* ================================================================
       EL DESPLEGABLE CON BUSCADOR

       Los <select> nativos no tienen búsqueda, y las listas de rubro y de
       centro de costos son largas: encontrar un valor obliga a desplegar y
       recorrer con la vista.

       NO SE AGREGA NINGUNA LIBRERÍA. El patrón ya está resuelto a mano en este
       mismo archivo, en el autocomplete de códigos de Tango
       —conectarBuscadorTango(), pintarSugerenciasTango()— y en las clases
       .prov-tango-suge del CSS. Esto es lo mismo, contra una lista que ya está
       en memoria en vez de contra el servidor.

       SÓLO SE ELIGE DE LA LISTA: el <input> de arriba FILTRA, no carga. Lo que
       se tipea nunca se guarda. Es lo mismo que decide la importación desde que
       un valor fuera de lista deja la fila en ERROR, y sería incoherente que el
       alta manual aceptara por tipeo lo que la planilla tiene prohibido. Cuando
       el filtro no encuentra nada, el desplegable dice DÓNDE se dan de alta los
       valores: sin eso, el campo parece roto.

       SE COMPORTA COMO UN <select> PARA EL RESTO DEL ARCHIVO. Expone `.value`
       de lectura y de escritura, que es todo lo que usan valor() y setValor().
       Cualquier otra cosa obligaría a tocar abrirForm() y guardarProveedor().
       ================================================================ */

    /**
     * Arma el desplegable con buscador sobre el campo `id`, con esos valores.
     *
     * Reemplaza el elemento que estaba y le pasa el id, así el resto del archivo
     * lo sigue encontrando.
     *
     * @return El elemento nuevo, con `.value` y `.buscable`
     */
    function crearSelectBuscable(id, valores) {
        var viejo = document.getElementById(id);

        if (!viejo) { return null; }

        var lista = alfabetico(valores);

        /* El valor ELEGIDO, que es lo único que se guarda. El texto del input
           es otra cosa —lo que se está tipeando para filtrar— y confundirlos es
           exactamente lo que haría que se guarde lo tipeado. */
        var elegido = '';

        // Un valor guardado que la lista no ofrece. Ver elegirValor().
        var fueraDeLista = '';

        var caja = document.createElement('div');

        caja.id = id;
        caja.className = 'prov-buscable';

        var input = document.createElement('input');

        input.type = 'text';
        input.id = id + 'Filtro';
        input.className = 'form-control form-control-sm prov-buscable-campo';
        input.autocomplete = 'off';
        input.placeholder = '(sin definir)';
        input.title = viejo.title || '';

        var panel = document.createElement('div');

        panel.className = 'prov-buscable-suge';
        panel.style.display = 'none';

        caja.appendChild(input);
        caja.appendChild(panel);
        viejo.parentNode.replaceChild(caja, viejo);

        /* La etiqueta apuntaba al campo viejo. Sin esto, hacer clic en "Rubro
           económico" deja de enfocar el campo, que es una de esas cosas que
           nadie reporta y a todos les molesta. */
        var etiqueta = document.querySelector('label[for="' + id + '"]');

        if (etiqueta) { etiqueta.setAttribute('for', input.id); }

        var marcado = -1;      // la opción resaltada con las flechas
        var visibles = [];     // lo que el filtro dejó a la vista

        /* La opción vacía tiene que seguir existiendo: un campo sin rubro es
           válido —en la planilla real hay 84 filas así— y sin esta opción no
           habría forma de volver a dejarlo vacío después de elegir algo. */
        var SIN_DEFINIR = '(sin definir)';

        function texto(v) {
            return (v === '') ? '' : v;
        }

        function cerrar() {
            panel.style.display = 'none';
            panel.innerHTML = '';
            marcado = -1;

            // Lo tipeado se descarta: el campo vuelve a mostrar lo elegido.
            input.value = texto(elegido);
            input.placeholder = SIN_DEFINIR;
        }

        function opciones(filtro) {
            var q = sinAcentos(filtro);
            var todos = lista.slice();

            /* El valor fuera de lista va AL FINAL y no mezclado en el orden
               alfabético: no es una opción que se ofrezca, es la que ya estaba
               guardada. */
            if (fueraDeLista !== '') { todos.push(fueraDeLista); }

            var r = todos.filter(function(v) {
                return q === '' || sinAcentos(v).indexOf(q) !== -1;
            });

            // Vaciar el campo se ofrece siempre, y primero.
            r.unshift('');

            return r;
        }

        function pintar(filtro) {
            visibles = opciones(filtro);

            /* El resaltado se pierde al filtrar, a propósito: apunta a una
               posición de la lista anterior, y conservarlo dejaría marcado un
               valor distinto del que estaba marcado hace un momento. */
            marcado = -1;

            /* SIN RESULTADOS SE DICE DÓNDE SE AGREGAN LOS VALORES. El campo no
               acepta texto libre, así que "no hay nada" sin más se lee como que
               el buscador está roto. */
            if (visibles.length <= 1 && filtro !== '') {
                visibles = [];
                panel.innerHTML = '<div class="prov-tango-vacio">Ningún valor coincide. Los '
                    + 'valores se dan de alta en Parámetros → Prov. Locales.</div>';
                panel.style.display = 'block';
                marcado = -1;

                return;
            }

            panel.innerHTML = visibles.map(function(v, i) {
                var esFuera = (v !== '' && v === fueraDeLista);

                return '<button type="button" class="prov-buscable-item'
                    + (i === marcado ? ' prov-buscable-marcado' : '')
                    + (esFuera ? ' prov-buscable-fuera' : '') + '" data-i="' + i + '">'
                    + escapar(v === '' ? SIN_DEFINIR : v)
                    + (esFuera ? ' <em>(fuera de lista)</em>' : '')
                    + '</button>';
            }).join('');

            panel.style.display = 'block';

            Array.prototype.forEach.call(panel.querySelectorAll('.prov-buscable-item'),
                function(b) {
                    // mousedown y no click: el blur del input llega antes que el
                    // click y cerraría el panel debajo del mouse.
                    b.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        elegir(visibles[parseInt(b.getAttribute('data-i'), 10)]);
                    });
                });
        }

        function marcar(paso) {
            if (!visibles.length) { return; }

            marcado += paso;

            if (marcado < 0) { marcado = visibles.length - 1; }
            if (marcado >= visibles.length) { marcado = 0; }

            var items = panel.querySelectorAll('.prov-buscable-item');

            Array.prototype.forEach.call(items, function(b, i) {
                b.classList.toggle('prov-buscable-marcado', i === marcado);
            });

            if (items[marcado]) { items[marcado].scrollIntoView({ block: 'nearest' }); }
        }

        function elegir(v) {
            elegido = (v === null || v === undefined) ? '' : String(v);
            input.value = texto(elegido);
            pintarMarcaFuera();
            cerrar();
        }

        /* El campo en naranja cuando lo elegido no está en la lista. Es un dato
           que hay que ver sin abrir el tooltip: la fila se guardó cuando ese
           valor era válido, y hoy guardar la va a rechazar. */
        function pintarMarcaFuera() {
            var fuera = (elegido !== '' && elegido === fueraDeLista);

            input.classList.toggle('prov-fuera-lista', fuera);

            input.title = fuera
                ? 'Este valor no está en la lista de opciones. Se conserva tal como está '
                    + 'guardado y no se pierde al abrir el formulario, pero GUARDAR LO VA A '
                    + 'RECHAZAR: elegí uno de la lista, o agregá éste en '
                    + 'Parámetros → Prov. Locales.'
                : (viejo.title || '');
        }

        input.addEventListener('focus', function() {
            /* AL ENFOCAR, EL TEXTO SE VA AL PLACEHOLDER. No es cosmético: el
               input muestra el valor elegido, así que si se deja ahí, la primera
               tecla se AGREGA a lo que había —'Alquileres' + 'm'— y el filtro no
               encuentra nada nunca.

               Se mueve al placeholder en vez de seleccionarlo con select(),
               porque select() al enfocar lo deshace el clic del mouse que
               justamente acaba de dar el foco. Así el valor elegido se sigue
               viendo, en gris, y lo que se tipea filtra desde cero. Lo repone
               cerrar(). */
            input.placeholder = (elegido !== '') ? elegido : SIN_DEFINIR;
            input.value = '';
            pintar('');
        });

        input.addEventListener('input', function() { pintar(input.value); });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(1); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); marcar(-1); return; }

            if (e.key === 'Enter') {
                // Enter con algo marcado elige; sin nada marcado no hace nada,
                // y sobre todo NO guarda lo tipeado.
                e.preventDefault();

                if (marcado >= 0 && visibles[marcado] !== undefined) {
                    elegir(visibles[marcado]);
                }

                return;
            }

            // Esc cierra la lista sin cerrar el formulario entero, igual que en
            // el buscador de Tango.
            if (e.key === 'Escape') { cerrar(); }
        });

        // Un clic afuera cierra. Sin esto el panel queda flotando sobre la grilla.
        document.addEventListener('click', function(e) {
            if (!caja.contains(e.target)) { cerrar(); }
        });

        /* ES LO QUE LO VUELVE INTERCAMBIABLE CON UN <select>: valor() y
           setValor() leen y escriben `.value` por id, y todo el archivo pasa por
           ahí. Sin esto habría que tocar abrirForm() y guardarProveedor().

           El setter va por buscable.elegir() y no por el elegir() de adentro,
           para que escribir un valor que la lista no tiene lo conserve y lo
           marque igual que si hubiera entrado por elegirValor(). Si no, el mismo
           valor se comportaría distinto según por dónde entró. */
        Object.defineProperty(caja, 'value', {
            get: function() { return elegido; },
            set: function(v) { caja.buscable.elegir(v); }
        });

        caja.buscable = {
            repoblar: function(nuevos) {
                lista = alfabetico(nuevos);

                // Lo elegido puede haber dejado de estar en la lista.
                caja.buscable.elegir(elegido);
            },

            /**
             * Deja elegido un valor que puede NO estar en la lista.
             *
             * ES LA PARTE QUE NO SE PUEDE OMITIR. Un proveedor cargado antes de
             * que existieran las listas —o traído por una importación de cuando
             * un valor fuera de lista era advertencia y se guardaba igual—
             * tiene valores que el desplegable no ofrece. Descartarlo acá
             * dejaría el campo vacío EN SILENCIO, y guardar le borraría el
             * rubro al proveedor sin que nadie lo haya pedido.
             *
             * Así que el valor se agrega como opción al final, marcada, y el
             * campo se pinta en naranja.
             */
            elegir: function(v) {
                var valor = (v === null || v === undefined) ? '' : String(v);

                fueraDeLista = (valor !== '' && lista.indexOf(valor) === -1) ? valor : '';

                elegir(valor);
            }
        };

        elegir('');

        return caja;
    }

    /** Minúsculas y sin acentos, para que el filtro no distinga ni una cosa ni la otra */
    function sinAcentos(s) {
        var v = String(s === null || s === undefined ? '' : s).toLowerCase();

        return v.normalize ? v.normalize('NFD').replace(/[̀-ͯ]/g, '') : v;
    }

    /**
     * Deja elegido un valor en un campo del formulario, conservándolo aunque no
     * esté en su lista.
     *
     * Sin listas cargadas los campos siguen siendo <input> de texto libre —es
     * el comportamiento de antes— y ahí no hay nada que conservar: el valor
     * entra tal cual.
     */
    function elegirValor(id, valor) {
        var el = document.getElementById(id);

        if (!el) { return; }

        var v = valor || '';

        if (el.buscable) {
            el.buscable.elegir(v);
            return;
        }

        el.value = v;
    }

    /**
     * Abre el formulario. Con código, en modo edición y con los valores
     * cargados; sin código, vacío para un alta.
     */
    function abrirForm(cod) {
        var f = null;

        if (cod) {
            (maestro.filas || []).forEach(function(x) {
                if (x.COD_PROVEE === cod) { f = x; }
            });
        }

        setValor('fpCodProv', f ? f.COD_PROVEE : '');
        setValor('fpNombreProv', f ? (f.NOMBRE || '') : '');
        setValor('fpFormaProv', f ? (f.FORMA_PAGO || '') : '');

        /* Los cinco que salen de una lista pasan por elegirValor(), que conserva
           el valor guardado aunque la lista no lo tenga. Con setValor() a secas,
           un rubro fuera de lista dejaría el desplegable vacío en silencio y
           guardar le borraría el rubro al proveedor. */
        elegirValor('fpRubroEcoProv', f ? f.RUBRO_ECONOMICO : '');
        elegirValor('fpRubroProv', f ? f.RUBRO : '');
        elegirValor('fpCentroProv', f ? f.CENTRO_COSTOS : '');
        elegirValor('fpPlazoProv', f ? f.PLAZO_PAGO : '');
        elegirValor('fpCriterioProv', f ? f.CRITERIO_DISTRIB : '');

        // El código es la clave: se puede tipear en un alta y no en una edición.
        var inpCod = document.getElementById('fpCodProv');

        if (inpCod) { inpCod.readOnly = !!f; }

        cerrarSugerenciasTango();

        texto('hintProv', f
            ? 'Editando ' + f.COD_PROVEE + '. Guardar no modifica la fila: da de baja la '
                + 'versión vigente y carga una nueva, y la anterior queda en el historial.'
            : 'Buscá el proveedor por código o por nombre y elegilo de la lista: el código se '
                + 'valida contra CPA01, el maestro de Tango, y el nombre lo trae de ahí. Un '
                + 'código que no existe en Tango no cruza contra ninguna cuenta a pagar, así '
                + 'que no se puede cargar. La forma de pago decide si la deuda entra al '
                + 'cronograma del cashflow.');

        mostrar('formProvWrap', true);

        if (inpCod && !f) { inpCod.focus(); }
    }

    function cerrarForm() {
        mostrar('formProvWrap', false);
        cerrarSugerenciasTango();
    }

    /* ================================================================
       EL BUSCADOR CONTRA CPA01

       Quien carga un proveedor casi nunca se acuerda del código: se acuerda
       del nombre. Un campo que sólo acepte el código obliga a ir a Tango a
       buscarlo, que es justo el paso que esto viene a sacar.

       NO ES LA VALIDACIÓN. Elegir de la lista es una comodidad; lo que decide
       es el backend, que vuelve a chequear contra CPA01 al guardar. Este
       endpoint es alcanzable sin pasar por la pantalla.
       ================================================================ */

    /** Handle del debounce, para no consultar en cada tecla */
    var buscarTangoTimer = null;

    /** Lo último que se buscó, para no repetir la misma consulta */
    var ultimaBusquedaTango = '';

    function conectarBuscadorTango() {
        var inp = document.getElementById('fpCodProv');

        if (!inp) { return; }

        inp.addEventListener('input', function() {
            // Al tipear, el nombre que hubiera quedado de una elección anterior
            // deja de corresponder: se limpia en vez de mostrar el de otro.
            setValor('fpNombreProv', '');

            var q = inp.value.trim();

            if (buscarTangoTimer) { clearTimeout(buscarTangoTimer); }

            if (q.length < 2) {
                cerrarSugerenciasTango();
                return;
            }

            // 250 ms: alcanza para que una ráfaga de tecleo sea una consulta y
            // no se nota como espera.
            buscarTangoTimer = setTimeout(function() { buscarEnTango(q); }, 250);
        });

        // Esc cierra la lista sin cerrar el formulario entero.
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { cerrarSugerenciasTango(); }
        });

        // Un clic afuera la cierra. Sin esto queda flotando sobre la grilla.
        document.addEventListener('click', function(e) {
            var cont = document.getElementById('sugeProvTango');

            if (cont && !cont.contains(e.target) && e.target !== inp) {
                cerrarSugerenciasTango();
            }
        });
    }

    function buscarEnTango(q) {
        if (q === ultimaBusquedaTango) { return; }

        ultimaBusquedaTango = q;

        fetch('Controller/ProveedoresController.php?action=buscarProveedorTango&q='
                + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(res) {
                // Llegó tarde: el usuario ya está buscando otra cosa.
                if (q !== ultimaBusquedaTango) { return; }

                pintarSugerenciasTango(res.success ? (res.data.filas || []) : [], res.message);
            })
            .catch(function() {
                pintarSugerenciasTango([], 'No se pudo consultar Tango.');
            });
    }

    function pintarSugerenciasTango(filas, error) {
        var cont = document.getElementById('sugeProvTango');

        if (!cont) { return; }

        if (error) {
            cont.innerHTML = '<div class="prov-tango-vacio text-danger">' + escapar(error)
                + '</div>';
            cont.style.display = 'block';
            return;
        }

        if (!filas.length) {
            /* UN RESULTADO VACÍO SE DICE. Sin esto, un código que no existe se
               ve igual que uno que todavía no se terminó de tipear, y el
               usuario se entera recién al guardar. */
            cont.innerHTML = '<div class="prov-tango-vacio">Ningún proveedor de Tango coincide. '
                + 'Si el proveedor es nuevo, hay que darlo de alta en Tango primero.</div>';
            cont.style.display = 'block';
            return;
        }

        cont.innerHTML = filas.map(function(f) {
            return '<button type="button" class="prov-tango-item"'
                + ' data-cod="' + escapar(f.COD_PROVEE) + '"'
                + ' data-nombre="' + escapar(f.NOM_PROVEE) + '">'
                + '<code>' + escapar(f.COD_PROVEE) + '</code> '
                + escapar(f.NOM_PROVEE) + '</button>';
        }).join('');

        cont.style.display = 'block';

        Array.prototype.forEach.call(cont.querySelectorAll('.prov-tango-item'), function(b) {
            b.addEventListener('click', function() {
                setValor('fpCodProv', b.dataset.cod);
                setValor('fpNombreProv', b.dataset.nombre);
                cerrarSugerenciasTango();

                var eco = document.getElementById('fpRubroEcoProv');

                if (eco) { eco.focus(); }
            });
        });
    }

    function cerrarSugerenciasTango() {
        var cont = document.getElementById('sugeProvTango');

        if (cont) {
            cont.style.display = 'none';
            cont.innerHTML = '';
        }

        ultimaBusquedaTango = '';
    }

    function guardarProveedor() {
        var cod = (valor('fpCodProv') || '').trim();

        if (cod === '') {
            Notificacion.campoInvalido('fpCodProv', 'Poné el código del proveedor.');
            return;
        }

        var btn = document.getElementById('btnGuardarProv');

        btn.disabled = true;

        /* Se mandan las MISMAS claves que las columnas de importación: el
           backend normaliza con la misma función, así que un proveedor cargado
           a mano queda idéntico a uno traído por la planilla. */
        pedirJson('Controller/ProveedoresController.php?action=saveProveedor', {
                cod_provee: cod,
                nombre: valor('fpNombreProv'),
                rubro_economico: valor('fpRubroEcoProv'),
                rubro: valor('fpRubroProv'),
                centro_costos: valor('fpCentroProv'),
                forma_pago: valor('fpFormaProv'),
                plazo_pago: valor('fpPlazoProv'),
                criterio_distrib: valor('fpCriterioProv')
            })
            .then(function(data) {
                btn.disabled = false;
                cerrarForm();

                Notificacion.exito(data && data.estado === 'ALTA'
                    ? 'Proveedor ' + data.cod_provee + ' agregado.'
                    : 'Proveedor ' + data.cod_provee + ' actualizado.', {
                    detalle: 'La versión anterior queda en el historial. La planilla sigue '
                           + 'siendo la fuente: la próxima importación puede pisarlo.'
                });

                recargarMaestroYListado();
            })
            .catch(function(error) {
                btn.disabled = false;
                Notificacion.error('No se pudo guardar: ' + error.message);
            });
    }

    function darDeBaja(cod) {
        if (!window.confirm('¿Dar de baja a ' + cod + ' del maestro?\n\n'
                + 'Su deuda queda sin clasificar hasta que se lo vuelva a cargar. '
                + 'La versión actual sigue en el historial: no se borra nada.')) {
            return;
        }

        pedirJson('Controller/ProveedoresController.php?action=deleteProveedor',
                { cod_provee: cod })
            .then(function() {
                Notificacion.exito('Proveedor dado de baja.', {
                    detalle: 'Su deuda pasa a contarse como "sin rubro" en el tablero.'
                });

                recargarMaestroYListado();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo dar de baja: ' + error.message);
            });
    }

    /**
     * Tocar el maestro cambia el listado de cuentas a pagar: el rubro, la forma
     * de pago —y con ella si la deuda entra al cronograma— y el plazo salen de
     * ahí. Dejar el listado viejo en pantalla mostraría la clasificación
     * anterior sin decir que quedó vieja.
     */
    function recargarMaestroYListado() {
        maestro = null;
        cargarMaestro();
        cargar();
    }

    function celdaFormaMaestro(f) {
        if (!f.FORMA_PAGO && f.FORMA_PAGO_ORIG) {
            return '<span class="prov-forma-rara" title="'
                + escapar('No está en la lista de formas válidas.')
                + '">' + escapar(f.FORMA_PAGO_ORIG) + '</span>';
        }

        return escapar(f.FORMA_PAGO || '—');
    }

    /* ================================================================
       AYUDANTES
       ================================================================ */

    function pintarAvisos(avisos, id) {
        var cont = document.getElementById(id);

        if (!cont) { return; }

        if (!avisos || !avisos.length) {
            cont.innerHTML = '';
            return;
        }

        cont.innerHTML = '<div class="alert alert-warning alert-dismissible fade show" role="alert">'
            + '<div class="d-flex align-items-start">'
            +   '<i class="fas fa-circle-info me-2 mt-1"></i>'
            +   '<div><strong>Sobre estos números</strong><ul class="mb-0 mt-1">'
            +     avisos.map(function(a) { return '<li>' + escapar(a) + '</li>'; }).join('')
            +   '</ul></div></div>'
            + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) { el.addEventListener('click', fn); }
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) { el.style.display = visible ? '' : 'none'; }
    }

    /** Si un bloque está a la vista. Lo usa aplicarListas() para no pisar un
        formulario que alguien está cargando. */
    function visible(id) {
        var el = document.getElementById(id);

        return !!el && el.style.display !== 'none';
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) { el.textContent = v; }
    }

    /** El valor de un campo del formulario, ya recortado */
    function valor(id) {
        var el = document.getElementById(id);

        return el ? String(el.value).trim() : '';
    }

    function setValor(id, v) {
        var el = document.getElementById(id);

        if (el) { el.value = (v === null || v === undefined) ? '' : v; }
    }

    function mostrarError(mensaje) {
        var cont = document.getElementById('avisosProv');

        if (cont) {
            cont.innerHTML = '<div class="alert alert-danger">' + escapar(mensaje) + '</div>';
        }

        console.error(mensaje);
    }

    function plata(v) {
        return '$ ' + Number(v || 0).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function plataCorta(v) {
        return Number(v || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 });
    }

    /** El backend manda 'Y-m-d'. Se parte el string: nada de new Date(string). */
    function fechaCorta(v) {
        if (!v) { return '—'; }

        var p = String(v).substring(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : escapar(v);
    }

    function escapar(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
