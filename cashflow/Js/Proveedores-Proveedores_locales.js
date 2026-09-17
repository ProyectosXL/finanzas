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

        var busqM = document.getElementById('busquedaMaestroProv');
        if (busqM) { busqM.addEventListener('input', pintarMaestro); }

        conectar('btnNuevoProv', function() { abrirForm(null); });
        conectar('btnGuardarProv', guardarProveedor);
        conectar('btnCancelarProv', cerrarForm);

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
                vistas.usar(datos);

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

    function cargarMaestro() {
        pedirJson('Controller/ProveedoresController.php?action=getMaestro')
            .then(function(data) {
                maestro = data;
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

        q = q.trim().toLowerCase();

        return filas.filter(function(f) {
            if (soloCronograma && !f.CRONOGRAMA) { return false; }
            if (soloVencidos && !f.SIN_FECHA_CARGADA) { return false; }
            if (q === '') { return true; }

            return [f.COD_PROVEE, f.RAZON_SOC, f.N_COMP, f.RUBRO_ECONOMICO, f.FORMA_PAGO]
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
        var k = datos.indicadores;

        if (!soloCronograma || !k.n_fuera_cronograma) {
            el.textContent = '';
            return;
        }

        var detalle = Object.keys(k.fuera_por_forma || {}).map(function(forma) {
            return forma + ' ' + plata(k.fuera_por_forma[forma]);
        }).join(' · ');

        el.innerHTML = '&nbsp;·&nbsp;<span class="prov-fuera-filtro">'
            + 'Quedan afuera ' + escapar(plata(k.fuera_cronograma)) + ' en '
            + k.n_fuera_cronograma + ' vencimiento(s)'
            + (detalle ? ' (' + escapar(detalle) + ')' : '')
            + ' — destildá el filtro para verlos.</span>';
    }

    function pintarGrilla() {
        if (!datos) { return; }

        var cols = vistas.columnas();

        document.getElementById('headerEjeProv').setAttribute('colspan', cols.length || 1);
        document.getElementById('headerSubProv').innerHTML = cols.map(function(c) {
            return '<th class="text-end">' + escapar(c.label) + '</th>';
        }).join('');

        var filas = filasVisibles();
        var html = '';

        filas.forEach(function(f) {
            var clases = ['prov-fila'];

            if (f.SIN_FECHA_CARGADA) { clases.push('prov-vencida'); }
            if (f.ORIGEN_FECHA === 'CARGADA') { clases.push('prov-con-fecha'); }
            if (f.EXCLUIDO) { clases.push('prov-excluido'); }

            html += '<tr class="' + clases.join(' ') + '">'
                + '<td title="' + escapar(tituloProveedor(f)) + '"><strong>'
                +     escapar(f.COD_PROVEE) + '</strong>' + marcaMaestro(f) + '</td>'
                + '<td class="col-texto" title="' + escapar(f.RAZON_SOC) + '">'
                +     escapar(f.RAZON_SOC) + '</td>'
                + '<td>' + celdaRubro(f) + '</td>'
                + '<td class="center">' + escapar(f.T_COMP) + '</td>'
                + '<td class="center">' + escapar(f.N_COMP) + '</td>'
                + '<td class="center">' + fechaCorta(f.FECHA_EMIS) + '</td>'
                + '<td class="center">' + celdaVto(f) + '</td>'
                + '<td class="currency fw-bold">' + plata(f.IMPORTE_PENDIENTE) + '</td>'
                + celdaFechaPago(f)
                + '<td class="center">' + celdaForma(f) + '</td>'
                + '<td class="center">' + celdaCronograma(f) + '</td>';

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
    }

    /**
     * Cuántas columnas descriptivas tiene la grilla, antes de las del eje.
     *
     * Está declarada una vez porque la usan el pie de totales y la fila de
     * "no hay resultados", y las dos se corren en silencio cuando se agrega una
     * columna: el síntoma es una tabla desalineada que nadie relaciona con el
     * cambio que la causó.
     */
    var COLS_DESC = 11;

    function pintarTotales(filas, cols) {
        var total = 0;
        var porCol = {};

        filas.forEach(function(f) {
            total += Number(f.IMPORTE_PENDIENTE) || 0;

            cols.forEach(function(c) {
                var k = c.rama + '|' + c.clave;
                porCol[k] = (porCol[k] || 0) + (Number(vistas.valor(f, c)) || 0);
            });
        });

        /* Las celdas del pie van en el mismo orden que el encabezado y suman
           COLS_DESC: un colspan mal contado corre el total debajo de otra
           columna y el número queda diciendo otra cosa. Siete descriptivas, el
           total, y las tres editables al final. */
        var html = '<td colspan="7" class="fw-bold text-end">TOTALES</td>'
            + '<td class="currency fw-bold">' + plata(total) + '</td>'
            + '<td colspan="' + (COLS_DESC - 8) + '"></td>';

        cols.forEach(function(c) {
            var v = porCol[c.rama + '|' + c.clave] || 0;

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

        return (origen[f.ORIGEN_FECHA] || '')
            + (f.EXCLUIDO ? ' Rubro Excluidos: se lista pero su fila del tablero se puede '
                + 'inhabilitar.' : '');
    }

    /** El proveedor no está en el maestro: su deuda no se puede abrir por rubro */
    function marcaMaestro(f) {
        if (f.EN_MAESTRO) { return ''; }

        return ' <i class="fas fa-circle-question prov-marca" title="'
            + escapar('Este proveedor no está en el maestro, así que su deuda no se puede abrir '
                + 'por rubro. Importá la hoja "Maestro proveedores" actualizada.') + '"></i>';
    }

    function celdaRubro(f) {
        if (!f.RUBRO_ECONOMICO) {
            return '<span class="text-muted small">sin clasificar</span>';
        }

        return '<span class="prov-rubro' + (f.EXCLUIDO ? ' prov-rubro-excluido' : '') + '">'
            + escapar(f.RUBRO_ECONOMICO) + '</span>';
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

    /**
     * La columna Forma.
     *
     * OJO: esta columna NO explica el filtro. Lo que entra al cronograma lo
     * decide la forma del MAESTRO —`FORMA_PAGO_MAESTRO`— y acá se muestra la
     * del pago registrado cuando hay uno. Que difieran es información: se le
     * pagó por una vía distinta de la habitual.
     */
    function celdaForma(f) {
        // Sin forma conocida. ENTRA AL FILTRO IGUAL —no se sabe cómo se paga, y
        // esconder deuda por un dato que falta es la peor razón para
        // esconderla— pero se marca, para que no se confunda con un echeq.
        if (!f.FORMA_PAGO && !f.FORMA_PAGO_ORIG) {
            return '<span class="prov-sin-forma" title="'
                + escapar('Sin forma de pago conocida' + (f.EN_MAESTRO
                    ? '.' : ': el proveedor no está en el maestro.')
                    + ' Se muestra igual, pero no se sabe si se paga por echeq o de otra '
                    + 'manera.') + '">sin forma</span>';
        }

        // Una forma que no matcheó contra la lista se muestra tal como vino y se
        // marca: es un typo de la planilla, y eso se arregla allá. Ya no hay un
        // segundo caso —una forma válida que quedó sin normalizar porque el
        // maestro es viejo—: la normalización se calcula al leer, contra la
        // lista de hoy.
        if (!f.FORMA_PAGO && f.FORMA_PAGO_ORIG) {
            return '<span class="prov-forma-rara" title="'
                + escapar('"' + f.FORMA_PAGO_ORIG + '" no está en la lista de formas válidas. '
                    + 'Corregilo en la planilla.')
                + '">' + escapar(f.FORMA_PAGO_ORIG) + '</span>';
        }

        // La forma del pago registrado difiere de la habitual del proveedor. No
        // es un error —el filtro sigue mirando la del maestro— pero es un dato:
        // o fue una excepción, o el maestro quedó viejo.
        if (f.FORMA_PAGO_MAESTRO && f.FORMA_PAGO !== f.FORMA_PAGO_MAESTRO) {
            return '<span class="small prov-forma-distinta" title="'
                + escapar('El pago se registró por ' + f.FORMA_PAGO + ', pero en el maestro '
                    + 'este proveedor es ' + f.FORMA_PAGO_MAESTRO + ', que es lo que decide '
                    + 'si entra al cronograma. Si la vía cambió de verdad, actualizá el '
                    + 'maestro.') + '">' + escapar(f.FORMA_PAGO)
                + ' <i class="fas fa-arrows-left-right prov-marca"></i></span>';
        }

        return '<span class="small">' + escapar(f.FORMA_PAGO) + '</span>';
    }

    /* ================================================================
       LA FORMA CON LA QUE SE TRATA ESTA FACTURA

       DOS COLUMNAS QUE NO SON LO MISMO, y ahora se ven las dos:

         Forma      UN HECHO: por qué vía salió o va a salir el pago. Lo trae
                    la importación de la planilla. Sólo se muestra.
         Cronograma UNA REGLA: con qué forma hay que tratar a ESTA factura para
                    decidir si entra al cashflow. Se elige acá, factura por
                    factura, y pisa a la del maestro sólo para ella.

       Vacío = "la del maestro", que es el caso normal y el que está en todas
       las filas hasta que alguien decida otra cosa.
       ================================================================ */

    function celdaCronograma(f) {
        if (!datos || !datos.forma_por_factura) {
            // Sin el script no se puede escribir el override. Se muestra lo que
            // decide -la del maestro- en vez de un desplegable que falla.
            return '<span class="small text-muted" title="'
                + escapar('Para fijar la forma por factura hace falta correr '
                    + 'sql/cashflow_prov_locales_forma_por_factura.sql.') + '">'
                + escapar(f.FORMA_PAGO_MAESTRO || '—') + '</span>';
        }

        var formas = Object.keys((datos && datos.formas_pago) || {});
        var actual = f.FORMA_PAGO_CRONOGRAMA || '';

        var opciones = '<option value=""'
            + (actual === '' ? ' selected' : '') + '>'
            + escapar(f.FORMA_PAGO_MAESTRO
                ? 'maestro: ' + f.FORMA_PAGO_MAESTRO
                : 'maestro: sin forma')
            + '</option>';

        formas.forEach(function(x) {
            opciones += '<option value="' + escapar(x) + '"'
                + (actual === x ? ' selected' : '') + '>' + escapar(x) + '</option>';
        });

        var titulo = actual === ''
            ? 'Decide la forma del maestro. Elegí otra para tratar SÓLO esta factura de otra '
                + 'manera: el maestro y las demás facturas de este proveedor no cambian.'
            : 'Esta factura se trata como ' + actual + ', pisando la del maestro'
                + (f.FORMA_PAGO_MAESTRO ? ' (' + f.FORMA_PAGO_MAESTRO + ')' : '')
                + '. El maestro no cambió. Volvé a "maestro:" para sacarlo.';

        return '<select class="form-select form-select-sm prov-select-crono'
            + (actual !== '' ? ' prov-crono-pisado' : '') + '"'
            + ' data-cod="' + escapar(f.COD_PROVEE) + '"'
            + ' data-t="' + escapar(f.T_COMP) + '"'
            + ' data-n="' + escapar(f.N_COMP) + '"'
            + ' title="' + escapar(titulo) + '">' + opciones + '</select>';
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
        document.querySelectorAll('#bodyProv .prov-select-crono').forEach(function(sel) {
            sel.addEventListener('change', function() {
                pedirPago('saveFormaCronograma', {
                    cod_provee: sel.getAttribute('data-cod'),
                    t_comp: sel.getAttribute('data-t'),
                    n_comp: sel.getAttribute('data-n'),
                    forma: sel.value
                });
            });
        });
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

        html += '<button class="btn btn-sm btn-primary" id="btnConfirmarProv">'
            +     '<i class="fas fa-check me-1"></i> Confirmar e importar</button>'
            +   '</div></div><div class="card-body">';

        html += '<div class="d-flex gap-2 flex-wrap mb-3">';
        chips.forEach(function(c) {
            html += '<span class="badge bg-' + c[2] + '">' + c[1] + ': ' + (r[c[0]] || 0) + '</span>';
        });
        html += '</div>';

        (d.avisos || []).forEach(function(a) {
            html += '<div class="alert alert-warning py-2 px-3 small mb-2">' + escapar(a) + '</div>';
        });

        html += tablaPreview(que, d);
        html += '</div></div>';

        cont.innerHTML = html;
        mostrar('previewProv', true);

        conectar('btnConfirmarProv', confirmarImportacion);
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

        if (sel && !sel.options.length) {
            sel.innerHTML = '<option value="">(sin forma)</option>'
                + ((maestro && maestro.formas_pago ? Object.keys(maestro.formas_pago) : [])
                    .map(function(f) {
                        return '<option value="' + escapar(f) + '">' + escapar(f) + '</option>';
                    }).join(''));
        }
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
        setValor('fpRubroEcoProv', f ? (f.RUBRO_ECONOMICO || '') : '');
        setValor('fpRubroProv', f ? (f.RUBRO || '') : '');
        setValor('fpCentroProv', f ? (f.CENTRO_COSTOS || '') : '');
        setValor('fpFormaProv', f ? (f.FORMA_PAGO || '') : '');
        setValor('fpPlazoProv', f ? (f.PLAZO_PAGO || '') : '');
        setValor('fpCriterioProv', f ? (f.CRITERIO_DISTRIB || '') : '');

        // El código es la clave: se puede tipear en un alta y no en una edición.
        var inpCod = document.getElementById('fpCodProv');

        if (inpCod) { inpCod.readOnly = !!f; }

        texto('hintProv', f
            ? 'Editando ' + f.COD_PROVEE + '. Guardar no modifica la fila: da de baja la '
                + 'versión vigente y carga una nueva, y la anterior queda en el historial.'
            : 'El código es el de Tango (hasta 6 caracteres) y es lo que hace que la deuda de '
                + 'este proveedor se pueda clasificar. La forma de pago decide si entra al '
                + 'cronograma del cashflow.');

        mostrar('formProvWrap', true);

        if (inpCod && !f) { inpCod.focus(); }
    }

    function cerrarForm() {
        mostrar('formProvWrap', false);
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
