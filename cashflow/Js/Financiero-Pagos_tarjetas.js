/**
 * Financiero -> Pagos con Tarjetas y Otros
 *
 * Tres sub-pestanas sobre el mismo eje: Gastos Supervisoras, Tarjetas Pagos
 * Corporativos y Tarjetas Socios.
 *
 * UN SOLO PEDIDO PARA LAS TRES
 * ----------------------------
 * getDatos trae todo. No es solo para ahorrar tiempo -los insumos son compartidos
 * y el backend los lee una vez-: con tres pedidos, uno puede salir antes y otro
 * despues de que alguien cargue un resumen, y la pantalla mostraria dos momentos
 * distintos a la vez.
 *
 * EL EJE LO RESUELVE EL BACKEND
 * -----------------------------
 * Las filas vienen de EjeVista con sus mapas 'dias' y 'meses' ya armados, asi que
 * aca NO se decide en que columna cae un importe. Esa regla -un importe va a un dia
 * O a su mes, nunca a los dos- vive en Horizonte y esta probada; repartir por mes en
 * el navegador seria una segunda version, y ademas perderia las columnas diarias.
 *
 * LAS TABLAS SE DIBUJAN SOBRE LA LISTA COMPLETA, NO SOBRE EL EJE
 * -------------------------------------------------------------
 * El payload del eje trae SOLO lo que se proyecta. Hoy, de seis supervisoras con
 * gastos, solo cuatro tienen pagos en efectivo -dos usan el 100 % tarjeta- asi que
 * el eje tiene cuatro filas y la grilla tiene que tener seis. Dibujar sobre el eje
 * escondería justo a las que hay que arreglar. Es la misma leccion que la planilla
 * de Logistica Local.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/TarjetasController.php';

    /**
     * Cuantas columnas descriptivas tiene cada grilla ANTES de las del eje.
     *
     * NO INCLUYEN LA DE TOTAL PERIODO, que va DESPUES del eje: el total se lee
     * despues de las columnas que suma, que es el orden en el que se arma. Antes
     * estaba antes del eje y confundia -se leia un total y recien despues los meses
     * de los que sale-.
     *
     * Asi que cada tabla tiene, en total, COLS_* + columnas del eje + 1.
     */
    var COLS_SUP = 4;
    var COLS_CORP = 9;
    var COLS_SOC = 3;

    var datos = null;
    var vistas = null;

    /** Que supervisoras y tarjetas estan expandidas. Sobrevive a los redibujos */
    var abiertas = {};

    /** Claves de las facturas seleccionadas. Sobrevive a los redibujos */
    var seleccion = {};

    /** La tarjeta cuyos resumenes se estan mirando, o null */
    var resumenDe = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('loadingTarj');

        pedir(ENDPOINT + '?action=getDatos')
            .then(function(d) {
                datos = d.data;
                Cargando.ocultar('loadingTarj');
                mostrar('wrapperTarj', true);

                vistas.usar(datos.eje);
                avisar(datos.avisos);
                llenarTarjetasDelSelect();
                dibujar();
            })
            .catch(function(e) {
                Cargando.ocultar('loadingTarj');
                avisar(['No se pudieron calcular los pagos con tarjetas: ' + e.message]);
            });
    }

    /** Redibuja las tres sub-pestanas. Lo llama tambien el cambio de vista */
    function dibujar() {
        if (!datos) { return; }

        dibujarSupervisoras();
        dibujarCorporativas();
        dibujarSocios();
    }

    /* ================================================================
       1 — GASTOS SUPERVISORAS
       ================================================================ */

    function dibujarSupervisoras() {
        var s = datos.supervisoras;
        var cols = vistas.columnas();

        encabezadoEje('headerEjeSup', 'headerEjeSup2', cols);

        texto('supCartel', s.cartel);
        texto('supCuantas', String(s.filas.length));
        texto('supSinTarjeta', plata(s.sin_tarjeta));

        var sinProyectar = s.filas.filter(function(f) { return !f.proyecta; }).length;
        texto('supSinProyectar', String(sinProyectar));

        var cuerpo = document.getElementById('bodySup');

        if (!cuerpo) { return; }

        if (!s.filas.length) {
            cuerpo.innerHTML = filaVacia(COLS_SUP + cols.length + 1, s.disponible
                ? 'Ninguna supervisora tiene gastos autorizados en la ventana '
                  + esc(s.ventana.rotulo) + '.'
                : 'No se pudieron leer los gastos de supervisión. Los avisos de arriba dicen '
                  + 'por qué.');
            pintarTotales('totalesSup', COLS_SUP, s.eje_total.totales, cols);
            texto('supTotal', plata(0));

            return;
        }

        var html = '';

        /* SE RECORRE LA LISTA COMPLETA -las seis- y para cada una se busca su fila
           del eje. Ver el encabezado: el eje solo trae lo que proyecta. */
        s.filas.forEach(function(f) {
            var eje = buscarFila(s.eje_total.filas, 'supervisora', f.nombre);
            var abierta = !!abiertas['sup|' + f.nombre];

            html += '<tr class="' + (f.proyecta ? '' : 'tarj-sin-proyectar') + '">'
                + '<td>'
                    + '<button class="btn btn-link btn-sm p-0 me-1 tarj-abrir" '
                        + 'data-clave="sup|' + esc(f.nombre) + '" '
                        + 'title="Ver las subfilas Efectivo y Tarjeta">'
                        + '<i class="fas fa-chevron-' + (abierta ? 'down' : 'right') + '"></i>'
                    + '</button>'
                    + '<span class="fw-semibold">' + esc(f.nombre) + '</span>'
                    + marcaMotivo(f)
                + '</td>'
                + '<td class="text-end">' + plataOGuion(f.promedio)
                    + (f.meses_con_datos < 3
                        ? '<div><small class="text-warning-emphasis" title="Igual se divide por '
                          + '3: un mes sin gastos también es un dato">'
                          + f.meses_con_datos + ' de 3 meses</small></div>'
                        : '')
                + '</td>'
                + '<td class="text-center"><small>' + pct(f.pct_efectivo) + ' / '
                    + pct(f.pct_tarjeta) + '</small></td>'
                + '<td>' + celdaTarjeta(f) + '</td>'
                + celdasEje(eje, cols, true)
                + '<td class="currency fw-bold">' + plata(vistas.total(eje)) + '</td>'
            + '</tr>';

            if (abierta) {
                html += subfilaParte(f, 'EFECTIVO', 'Efectivo', cols, s);
                html += subfilaParte(f, 'TARJETA', 'Tarjeta', cols, s);
            }
        });

        cuerpo.innerHTML = html;

        pintarTotales('totalesSup', COLS_SUP, s.eje_total.totales, cols);
        texto('supTotal', plata(vistas.total(s.eje_total.totales)));
        texto('supTotalDetalle', 'efectivo + tarjeta · ' + vistas.periodo());

        engancharAbrir(cuerpo);
    }

    /**
     * Una subfila Efectivo o Tarjeta.
     *
     * LA PARTE TARJETA SIN TARJETA SE MARCA Y NO SE ESCONDE: su importe existe
     * -esta estimado- y lo que falta es dónde ponerlo. Esconderla haría que la fila
     * de la supervisora pareciera completa.
     */
    function subfilaParte(f, parte, nombre, cols, s) {
        var eje = buscarFila(s.eje_partes.filas, 'clave_eje', f.nombre + '|' + parte);

        var marca = '';

        if (parte === 'TARJETA' && f.motivo_tarjeta) {
            marca = ' <span class="badge bg-warning text-dark">'
                + (f.motivo_tarjeta === 'SIN_TARJETA'
                    ? 'sin tarjeta: no entra al tablero'
                    : 'más de una tarjeta activa: no entra al tablero')
                + '</span>';
        }

        return '<tr class="tarj-subfila">'
            + '<td class="ps-4"><small class="text-muted">' + esc(nombre) + '</small>'
                + marca + '</td>'
            + '<td colspan="3"></td>'
            + celdasEje(eje, cols, false)
            + '<td class="currency"><small>' + plata(vistas.total(eje)) + '</small></td>'
        + '</tr>';
    }

    /** La tarjeta asociada a una supervisora, o por qué no hay una */
    function celdaTarjeta(f) {
        if (f.tarjeta) {
            return '<small>' + esc(f.tarjeta.ROTULO) + '</small>'
                + (Number(f.tarjeta.PCT_COBERTURA) > 0
                    ? ' <span class="badge bg-light text-dark">+'
                      + pct(Number(f.tarjeta.PCT_COBERTURA) / 100) + ' cob.</span>'
                    : '')
                + '<div><small class="text-muted">vence el '
                + esc(f.tarjeta.DIA_VENCIMIENTO) + '</small></div>'
                + botonResumenes(f.tarjeta);
        }

        if (f.tarjetas_activas > 1) {
            return '<small class="text-warning-emphasis">' + f.tarjetas_activas
                + ' tarjetas activas a su nombre</small>';
        }

        return '<small class="text-muted">sin tarjeta</small>';
    }

    /** Por qué una supervisora no proyecta */
    function marcaMotivo(f) {
        if (f.proyecta) { return ''; }

        var textos = {
            SIN_SUPERVISORA: 'no está en el maestro de supervisoras',
            INACTIVA: 'inactiva en el maestro',
            SIN_IMPORTE: 'sus gastos suman cero'
        };

        return ' <span class="badge bg-secondary" title="No se proyecta">'
            + esc(textos[f.motivo] || 'no se proyecta') + '</span>';
    }

    /* ================================================================
       2 — TARJETAS PAGOS CORPORATIVOS
       ================================================================ */

    function dibujarCorporativas() {
        var c = datos.corporativas;
        var cols = vistas.columnas();

        encabezadoEje('headerEjeCorp', 'headerEjeCorp2', cols);

        var todas = c.eje.filas;
        var visibles = filasCorpVisibles();

        /* CUÁNTO SE ESCONDE SE DICE SIEMPRE, también cuando los interruptores están
           apagados: un filtro que esconde plata sin decir cuánta es un filtro que
           miente. */
        var escondidas = todas.length - visibles.length;
        var impEscondidas = 0;

        todas.forEach(function(f) {
            if (visibles.indexOf(f) === -1) { impEscondidas += Number(f.IMPORTE) || 0; }
        });

        texto('corpConteo', todas.length + ' vencimiento(s) · ' + vistas.periodo());
        texto('escondidasCorp', escondidas > 0
            ? escondidas + ' fila(s) escondidas por los filtros, por ' + plata(impEscondidas)
              + '. Prendé los interruptores para verlas.'
            : '');

        var kpis = kpisCorp(todas);
        texto('corpTotal', plata(vistas.total(c.eje.totales)
            + vistas.total(c.eje_cobertura.totales) + vistas.total(c.eje_resumenes.totales)));
        texto('corpVencidas', plata(kpis.vencidasSinVincular));
        texto('corpVencidasDetalle', kpis.cuantasVencidas
            + ' factura(s): sin tarjeta no hay fecha de pago');
        texto('corpCobertura', plata(vistas.total(c.eje_cobertura.totales)));
        texto('corpExcluidas', plata(kpis.excluidas));
        texto('corpExcluidasDetalle', kpis.cuantasExcluidas
            + ' factura(s) fuera del tablero por una decisión');

        var cuerpo = document.getElementById('bodyCorp');

        if (!cuerpo) { return; }

        if (!visibles.length) {
            cuerpo.innerHTML = filaVacia(COLS_CORP + cols.length + 1, todas.length
                ? 'Ninguna factura coincide con los filtros.'
                : (c.disponible
                    ? 'No hay facturas pendientes de Tango con forma de pago vigente '
                      + 'TARJETA CORP.'
                    : 'No se pudieron leer las cuentas a pagar de Tango.'));
        } else {
            cuerpo.innerHTML = visibles.map(filaCorp).join('');
        }

        pintarTotales('totalesCorp', COLS_CORP, c.eje.totales, cols);
        dibujarExtras();
        engancharCorp(cuerpo);
        actualizarBarraSel();
    }

    function filaCorp(f) {
        var cols = vistas.columnas();
        var clave = f.CLAVE;

        /* LAS EXCLUIDAS SE VEN ATENUADAS Y CON EL IMPORTE TACHADO: son las dos
           marcas que hacen que no se las lea como plata que entra. */
        var clases = [];

        if (f.MOTIVO === 'EXCLUIDA') { clases.push('tarj-excluida'); }
        if (!f.PROYECTA) { clases.push('tarj-sin-proyectar'); }

        var importe = (f.MOTIVO === 'EXCLUIDA')
            ? '<s>' + plata(f.IMPORTE) + '</s>'
            : plata(f.IMPORTE);

        return '<tr class="' + clases.join(' ') + '" title="' + esc(f.EXPLICACION || '') + '">'
            + '<td class="text-center">'
                + '<input type="checkbox" class="form-check-input tarj-sel" '
                    + 'data-clave="' + esc(clave) + '"'
                    + (seleccion[clave] ? ' checked' : '') + '>'
            + '</td>'
            + '<td>' + esc(f.COD_PROVEE) + '</td>'
            + '<td class="col-texto">' + esc(f.RAZON_SOC) + '</td>'
            + '<td>' + esc(f.T_COMP) + '</td>'
            + '<td>' + esc(f.N_COMP) + '</td>'
            + '<td>' + fecha(f.FECHA_VTO)
                + (f.VENCIDA ? ' <span class="badge bg-danger">vencida</span>' : '')
            + '</td>'
            + '<td>' + (f.TARJETA
                ? '<small>' + esc(f.TARJETA.ROTULO) + '</small>'
                : '<small class="text-muted">sin vincular</small>')
            + '</td>'
            + '<td class="currency">' + importe + '</td>'
            + '<td>' + estadoCorp(f) + '</td>'
            + celdasEje(f, cols, true)
            + '<td class="currency fw-bold">' + plata(vistas.total(f)) + '</td>'
        + '</tr>';
    }

    /** El estado de una factura, en una marca */
    function estadoCorp(f) {
        var marcas = {
            EXCLUIDA: ['bg-danger', 'excluida'],
            CUBIERTA: ['bg-info text-dark', 'cubierta por resumen'],
            VENCIDA_SIN_TARJETA: ['bg-warning text-dark', 'vencida sin tarjeta: no entra'],
            SIN_FECHA: ['bg-secondary', 'sin fecha']
        };

        if (marcas[f.MOTIVO]) {
            return '<span class="badge ' + marcas[f.MOTIVO][0] + '">'
                + esc(marcas[f.MOTIVO][1]) + '</span>'
                + (f.MOTIVO === 'EXCLUIDA' && f.MOTIVO_EXCLUSION_TARJETAS
                    ? '<div><small class="text-muted">'
                      + esc(f.MOTIVO_EXCLUSION_TARJETAS) + '</small></div>'
                    : '');
        }

        if (f.REUBICADA) {
            return '<span class="badge bg-primary" title="Una tarjeta se paga una vez por mes">'
                + 'sale el ' + fecha(f.FECHA) + '</span>';
        }

        return '<span class="badge bg-success">entra</span>';
    }

    /** La cobertura y los resúmenes, al pie */
    function dibujarExtras() {
        var c = datos.corporativas;
        var cuerpo = document.getElementById('bodyCorpExtra');

        if (!cuerpo) { return; }

        var filas = [];

        c.cobertura.forEach(function(x) {
            filas.push({
                concepto: 'Cobertura gastos excepcionales',
                icono: 'fa-shield-halved',
                tarjeta: rotuloTarjeta(x.id_tarjeta),
                mes: x.mes,
                fecha: x.fecha,
                base: x.base,
                importe: x.importe,
                proyecta: x.proyecta,
                motivo: x.motivo,
                tooltip: x.tooltip,
                extra: x.facturas + ' factura(s) vinculadas · ' + pct(x.pct / 100)
            });
        });

        c.resumenes.forEach(function(x) {
            filas.push({
                concepto: 'Resumen cargado',
                icono: 'fa-file-invoice-dollar',
                tarjeta: rotuloTarjeta(x.id_tarjeta),
                mes: x.mes,
                fecha: x.fecha,
                base: null,
                importe: x.importe,
                proyecta: x.proyecta,
                motivo: x.motivo,
                tooltip: 'Reemplaza a las facturas vinculadas de ' + x.mes + ' y a su cobertura.',
                extra: x.pagado ? 'pagado' : ''
            });
        });

        if (!filas.length) {
            cuerpo.innerHTML = filaVacia(8, 'No hay cobertura ni resúmenes: la cobertura se '
                + 'calcula sobre las facturas vinculadas, y los resúmenes se cargan desde la '
                + 'fila de cada tarjeta.');

            return;
        }

        cuerpo.innerHTML = filas.map(function(x) {
            return '<tr class="' + (x.proyecta ? '' : 'tarj-sin-proyectar') + '" '
                + 'title="' + esc(x.tooltip) + '">'
                + '<td><i class="fas ' + x.icono + ' me-1 text-muted"></i>'
                    + esc(x.concepto) + '</td>'
                + '<td><small>' + esc(x.tarjeta) + '</small></td>'
                + '<td>' + esc(x.mes) + '</td>'
                + '<td>' + fecha(x.fecha) + '</td>'
                + '<td class="currency">' + plataOGuion(x.base) + '</td>'
                + '<td class="currency fw-bold">' + plata(x.importe) + '</td>'
                + '<td>' + (x.proyecta
                    ? '<span class="badge bg-success">entra</span>'
                    : '<span class="badge bg-secondary">no entra</span>') + '</td>'
                + '<td><small class="text-muted">' + esc(x.extra || x.motivo || '')
                    + '</small></td>'
            + '</tr>';
        }).join('');
    }

    function kpisCorp(filas) {
        var k = {vencidasSinVincular: 0, cuantasVencidas: 0, excluidas: 0, cuantasExcluidas: 0};

        filas.forEach(function(f) {
            if (f.MOTIVO === 'VENCIDA_SIN_TARJETA') {
                k.vencidasSinVincular += Number(f.IMPORTE) || 0;
                k.cuantasVencidas++;
            }

            if (f.MOTIVO === 'EXCLUIDA') {
                k.excluidas += Number(f.IMPORTE) || 0;
                k.cuantasExcluidas++;
            }
        });

        return k;
    }

    /**
     * Las facturas que hoy se ven: el buscador y los dos interruptores.
     *
     * LOS TRES FILTROS SON DE PRESENTACIÓN y no tocan ningún total del tablero: lo
     * que esconden se dice arriba, con el importe.
     */
    function filasCorpVisibles() {
        var q = (texto('buscadorCorp') || '').trim().toLowerCase();
        var verVencidas = marcado('verVencidasCorp');
        var verExcluidas = marcado('verExcluidasCorp');

        return datos.corporativas.eje.filas.filter(function(f) {
            if (!verExcluidas && f.MOTIVO === 'EXCLUIDA') { return false; }
            if (!verVencidas && f.VENCIDA) { return false; }

            if (q === '') { return true; }

            /* Se busca en las tres formas en las que alguien se acuerda de una
               factura: el código, la razón social y el número de comprobante. */
            return [f.COD_PROVEE, f.RAZON_SOC, f.N_COMP, f.T_COMP].some(function(v) {
                return String(v === null || v === undefined ? '' : v)
                    .toLowerCase().indexOf(q) !== -1;
            });
        });
    }

    /* ---- selección y acciones masivas ---- */

    function engancharCorp(cuerpo) {
        cuerpo.querySelectorAll('.tarj-sel').forEach(function(chk) {
            chk.addEventListener('change', function() {
                var clave = chk.getAttribute('data-clave');

                if (chk.checked) { seleccion[clave] = true; } else { delete seleccion[clave]; }

                actualizarBarraSel();
            });
        });
    }

    function seleccionadas() {
        return filasCorpVisibles().filter(function(f) { return !!seleccion[f.CLAVE]; });
    }

    /**
     * La barra dice CUÁNTAS y CUÁNTO antes de que se apriete nada.
     *
     * Vincular cambia dónde cae la plata y excluir la saca del tablero, así que el
     * importe es el dato que hace notar que se seleccionó de más.
     */
    function actualizarBarraSel() {
        var sel = seleccionadas();
        var barra = document.getElementById('barraSelCorp');

        if (!barra) { return; }

        barra.style.display = sel.length ? '' : 'none';

        if (!sel.length) { return; }

        var total = 0;
        var provs = {};

        sel.forEach(function(f) {
            total += Number(f.IMPORTE) || 0;
            provs[f.COD_PROVEE] = true;
        });

        texto('selResumenCorp', sel.length + ' factura(s) · ' + plata(total) + ' · '
            + Object.keys(provs).length + ' proveedor(es)');

        /* LOS BOTONES DICEN POR QUÉ ESTÁN APAGADOS. Sin las tablas no se puede
           vincular ni excluir, y un botón muerto sin motivo manda a adivinar. */
        apagar('btnVincularCorp', !datos.tablas.factura,
            'Falta correr sql/cashflow_tarjetas_facturas.sql');
        apagar('btnDesvincularCorp', !datos.tablas.factura,
            'Falta correr sql/cashflow_tarjetas_facturas.sql');
        apagar('btnExcluirCorp', !datos.tablas.exclusion,
            'Falta correr sql/cashflow_tarjetas_facturas.sql');
        apagar('btnIncluirCorp', !datos.tablas.exclusion,
            'Falta correr sql/cashflow_tarjetas_facturas.sql');
    }

    function vincular() {
        var sel = seleccionadas();
        var id = texto('selTarjetaCorp');

        if (!sel.length) { return; }

        if (!id) {
            Notificacion.error('Elegí la tarjeta a la que vincularlas.');

            return;
        }

        var total = 0;
        sel.forEach(function(f) { total += Number(f.IMPORTE) || 0; });

        Notificacion.confirmar({
            titulo: 'Vincular a una tarjeta',
            mensaje: sel.length + ' factura(s) por ' + plata(total) + '.',
            detalle: 'Van a generar cobertura con el % de esa tarjeta, y un resumen de esa '
                + 'tarjeta las va a reemplazar. Las que estén vencidas pasan a salir en el '
                + 'próximo vencimiento de la tarjeta.',
            confirmar: 'Vincular'
        }).then(function(ok) {
            if (!ok) { return; }

            guardar('vincularFacturas', {comprobantes: claves(sel), id_tarjeta: id});
        });
    }

    function desvincular() {
        var sel = seleccionadas().filter(function(f) { return !!f.ID_TARJETA; });

        if (!sel.length) {
            Notificacion.advertencia('Ninguna de las seleccionadas está vinculada.');

            return;
        }

        Notificacion.confirmar({
            titulo: 'Desvincular de su tarjeta',
            mensaje: sel.length + ' factura(s).',
            detalle: 'Dejan de generar cobertura y ningún resumen las va a reemplazar. Las que '
                + 'estén vencidas dejan de entrar al flujo: sin tarjeta no hay fecha de pago. '
                + 'No se borra nada, queda en el historial.',
            confirmar: 'Desvincular'
        }).then(function(ok) {
            if (!ok) { return; }

            guardar('desvincularFacturas', {comprobantes: claves(sel)});
        });
    }

    /**
     * Excluye o vuelve a incluir lo seleccionado, con UN motivo para todas.
     *
     * El motivo se pide en un diálogo del módulo y no con el prompt del navegador:
     * acá hay que leer cuántas facturas y por cuánta plata antes de escribir nada, y
     * eso en un prompt no entra. Mismo gesto que la exclusión masiva de Proveedores
     * Locales.
     */
    function accionExclusion(excluir) {
        var sel = seleccionadas().filter(function(f) {
            return !!f.EXCLUIDA_TARJETAS !== excluir;
        });

        if (!sel.length) {
            Notificacion.advertencia(excluir
                ? 'Las seleccionadas ya están excluidas.'
                : 'Ninguna de las seleccionadas está excluida.');

            return;
        }

        var total = 0;
        sel.forEach(function(f) { total += Number(f.IMPORTE) || 0; });

        var detalle = sel.length + ' factura(s) por ' + plata(total) + '.';

        if (!excluir) {
            Notificacion.confirmar({
                titulo: 'Volver a incluir',
                mensaje: '¿Devolver estas facturas al flujo?',
                detalle: detalle + ' Sus importes vuelven a la fila del tablero. El motivo de la '
                    + 'exclusión queda en el historial.',
                confirmar: 'Volver a incluir'
            }).then(function(ok) {
                if (ok) { guardar('incluirFacturas', {comprobantes: claves(sel)}); }
            });

            return;
        }

        Notificacion.pedirTexto({
            titulo: 'Excluir de Pagos con Tarjetas y Otros',
            peligro: true,
            mensaje: detalle,
            detalle: 'Salen de la fila del tablero y quedan informadas aparte, con este motivo. '
                + 'NO se excluyen de Cuentas a Pagar Locales: son dos decisiones distintas.',
            etiqueta: 'Motivo (el mismo para todas)',
            placeholder: 'Ej.: ya está estimada en Gastos Supervisoras, duplicada en Tango…',
            maxlargo: 200,
            invalido: 'Escribí el motivo: es lo único que después explica por qué falta ese '
                + 'importe en el tablero.',
            confirmar: 'Excluir ' + sel.length + ' factura(s)'
        }).then(function(motivo) {
            if (motivo !== null) {
                guardar('excluirFacturas', {comprobantes: claves(sel), motivo: motivo});
            }
        });
    }

    /** Las claves que el backend espera, una por comprobante */
    function claves(filas) {
        return filas.map(function(f) {
            return {cod_provee: f.COD_PROVEE, t_comp: f.T_COMP, n_comp: f.N_COMP};
        });
    }

    /* ================================================================
       3 — TARJETAS SOCIOS
       ================================================================ */

    function dibujarSocios() {
        var s = datos.socios;
        var cols = vistas.columnas();

        encabezadoEje('headerEjeSoc', 'headerEjeSoc2', cols);

        texto('socCartel', s.cartel);
        texto('socCuantas', String(s.filas.length));
        texto('socSinBase', String(s.filas.filter(function(f) {
            return f.motivo === 'SIN_BASE';
        }).length));
        texto('socTotal', plata(vistas.total(s.eje_total.totales)));

        var cuerpo = document.getElementById('bodySoc');

        if (!cuerpo) { return; }

        if (!s.filas.length) {
            cuerpo.innerHTML = filaVacia(COLS_SOC + cols.length + 1,
                'No hay ninguna tarjeta de tipo Socio activa. Se dan de alta en '
                + 'Parámetros › Tarjetas.');
            pintarTotales('totalesSoc', COLS_SOC, s.eje_total.totales, cols,
                'TOTALES EN PESOS');

            return;
        }

        var html = '';

        s.filas.forEach(function(f) {
            var id = f.tarjeta.ID;
            var abierta = !!abiertas['soc|' + id];

            var lineas = [
                {clave: 'eje_total', nombre: 'Total en pesos', clase: 'fw-bold', usd: false},
                {clave: 'eje_ars', nombre: 'Consumos en $', clase: '', usd: false},
                {clave: 'eje_usd_pesos', nombre: 'U$S equivalente en $', clase: '', usd: false},
                {clave: 'eje_usd', nombre: 'U$S (informativa)', clase: 'tarj-informativa',
                 usd: true}
            ];

            lineas.forEach(function(l, i) {
                var eje = buscarFila(s[l.clave].filas, 'id_tarjeta', id);
                var esPrimera = (i === 0);

                html += '<tr class="' + l.clase + (esPrimera ? '' : ' tarj-subfila')
                        + (f.motivo === 'SIN_BASE' ? ' tarj-sin-proyectar' : '') + '">';

                if (esPrimera) {
                    html += '<td rowspan="' + (abierta ? lineas.length : 1) + '">'
                        + '<button class="btn btn-link btn-sm p-0 me-1 tarj-abrir" '
                            + 'data-clave="soc|' + esc(id) + '" '
                            + 'title="Ver las filas en pesos y en dólares">'
                            + '<i class="fas fa-chevron-' + (abierta ? 'down' : 'right')
                            + '"></i></button>'
                        + '<span class="fw-semibold">' + esc(f.tarjeta.ROTULO) + '</span>'
                        + (f.motivo === 'SIN_BASE'
                            ? ' <span class="badge bg-warning text-dark">sin base histórica'
                              + '</span>'
                            : (f.motivo === 'BASE_INCOMPLETA'
                                ? ' <span class="badge bg-light text-dark" title="Se divide por '
                                  + 'los que hay, no por 3">' + f.base.cantidad + ' de 3'
                                  + '</span>'
                                : ''))
                        + botonResumenes(f.tarjeta)
                        + '</td>'
                        + '<td rowspan="' + (abierta ? lineas.length : 1)
                        + '" class="text-end"><small>'
                        + plataOGuion(f.base.promedio_ars) + '<br>'
                        + dolares(f.base.promedio_usd) + '</small></td>';
                }

                html += '<td><small>' + esc(l.nombre) + '</small></td>'
                    + celdasEjeSocios(eje, cols, f, l.usd)
                    /* La fila en U$S totaliza en DOLARES, como sus celdas: es
                       informativa y no suma en pesos, así que mostrarla con signo
                       de peso la haría sumable con la vista. */
                    + '<td class="currency' + (l.usd ? '' : ' fw-bold') + '">'
                    + (l.usd ? dolares(vistas.total(eje)) : plata(vistas.total(eje)))
                    + '</td>'
                + '</tr>';

                if (!abierta) { return false; }
            });
        });

        cuerpo.innerHTML = html;

        pintarTotales('totalesSoc', COLS_SOC, s.eje_total.totales, cols, 'TOTALES EN PESOS');
        engancharAbrir(cuerpo);
    }

    /**
     * Las celdas de una fila de socios.
     *
     * EL TOOLTIP DE CADA CELDA DICE CON QUÉ DÓLAR SE CONVIRTIÓ. Lo arma el backend
     * por mes, y acá se busca el mes de la columna: un importe en pesos que salió de
     * una conversión y no dice con qué se convirtió no se puede verificar contra
     * nada.
     */
    function celdasEjeSocios(eje, cols, fila, esUsd) {
        return cols.map(function(col) {
            var v = vistas.valor(eje, col);
            var meta = vistas.meta(col);
            var mes = vistas.esMes(col) ? String(col).substring(4)
                                        : (meta ? meta.mes_clave : null);
            var celda = (mes && fila.celdas[mes]) ? fila.celdas[mes] : null;

            return '<td class="currency' + (celda && celda.origen === 'RESUMEN'
                        ? ' tarj-resumen' : '') + '"'
                + (celda ? ' title="' + esc(celda.tooltip) + '"' : '') + '>'
                + (v === 0 || v === null ? '' : (esUsd ? dolaresCorto(v) : plataCorta(v)))
            + '</td>';
        }).join('');
    }

    /* ================================================================
       LOS RESÚMENES — compartidos por las tres sub-pestañas
       ================================================================ */

    function botonResumenes(tarjeta) {
        return ' <button class="btn btn-link btn-sm p-0 tarj-resumenes" '
            + 'data-id="' + esc(tarjeta.ID) + '" '
            + 'title="Ver y cargar los resúmenes de esta tarjeta">'
            + '<i class="fas fa-file-invoice-dollar"></i></button>';
    }

    function abrirResumenes(id) {
        resumenDe = buscarTarjeta(id);

        if (!resumenDe) { return; }

        mostrar('cardResumenes', true);
        texto('resumenTarjeta', resumenDe.ROTULO);

        apagar('btnNuevoResumen', !datos.tablas.resumen,
            'Falta correr sql/cashflow_tarjetas.sql');
        apagar('btnBaseHistorica', !datos.tablas.resumen,
            'Falta correr sql/cashflow_tarjetas.sql');

        pedir(ENDPOINT + '?action=getResumenes&id_tarjeta=' + encodeURIComponent(id))
            .then(function(d) { pintarResumenes(d.data.historial || []); })
            .catch(function(e) {
                Notificacion.error('No se pudieron leer los resúmenes: ' + e.message);
            });

        document.getElementById('cardResumenes').scrollIntoView({behavior: 'smooth',
            block: 'nearest'});
    }

    function pintarResumenes(filas) {
        var cuerpo = document.getElementById('bodyResumenes');

        if (!cuerpo) { return; }

        if (!filas.length) {
            cuerpo.innerHTML = filaVacia(9, 'Esta tarjeta todavía no tiene ningún resumen '
                + 'cargado. Con «Cargar base histórica» se cargan los últimos 3 de una.');

            return;
        }

        cuerpo.innerHTML = filas.map(function(r) {
            return '<tr class="' + (r.ACTIVO ? '' : 'tarj-sin-proyectar') + '">'
                + '<td>' + esc(r.MES) + '</td>'
                + '<td class="currency">' + plataOGuion(r.IMPORTE_ARS) + '</td>'
                + '<td class="currency">' + dolares(r.IMPORTE_USD) + '</td>'
                + '<td>' + fecha(r.FECHA_VENCIMIENTO) + '</td>'
                + '<td><small>' + esc(r.ORIGEN_NOMBRE) + '</small></td>'
                + '<td class="text-center">'
                    + (r.ACTIVO
                        ? '<div class="form-check form-switch d-inline-block">'
                          + '<input class="form-check-input tarj-pagado" type="checkbox" '
                          + 'data-id="' + esc(r.ID) + '"' + (r.PAGADO ? ' checked' : '')
                          + ' title="Marcarlo como pagado lo saca del horizonte"></div>'
                        : '<small class="text-muted">—</small>')
                + '</td>'
                + '<td><small>' + esc(r.OBSERVACION || '') + '</small></td>'
                + '<td><small class="text-muted">' + esc(r.FECHA_MODIF || '')
                    + (r.USUARIO_MODIF ? ' — ' + esc(r.USUARIO_MODIF) : '')
                    + (r.ACTIVO ? '' : '<div>De baja: ' + esc(r.FECHA_BAJA || '')
                        + (r.USUARIO_BAJA ? ' — ' + esc(r.USUARIO_BAJA) : '') + '</div>')
                + '</small></td>'
                + '<td class="text-center">'
                    + (r.ACTIVO
                        ? '<button class="btn btn-sm btn-link text-danger p-0 tarj-baja" '
                          + 'data-id="' + esc(r.ID) + '" title="Dar de baja: no se borra, y ese '
                          + 'mes vuelve a proyectarse con la estimación">'
                          + '<i class="fas fa-trash"></i></button>'
                        : '')
                + '</td>'
            + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.tarj-pagado').forEach(function(chk) {
            chk.addEventListener('change', function() {
                guardar('pagarResumen', {id: chk.getAttribute('data-id'), pagado: chk.checked});
            });
        });

        cuerpo.querySelectorAll('.tarj-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                Notificacion.confirmar({
                    titulo: 'Dar de baja el resumen',
                    mensaje: '¿Dar de baja este resumen?',
                    detalle: 'No se borra: queda en el historial. Ese mes vuelve a proyectarse '
                        + 'con la estimación.',
                    peligro: true,
                    confirmar: 'Dar de baja'
                }).then(function(ok) {
                    if (ok) { guardar('bajaResumen', {id: btn.getAttribute('data-id')}); }
                });
            });
        });
    }

    /**
     * Carga un resumen suelto.
     *
     * SE PIDEN LOS CUATRO DATOS EN UN DIÁLOGO y no con celdas editables en la
     * grilla: un resumen es un alta con cuatro campos de los que dos son
     * obligatorios entre sí —al menos un importe—, y eso en una grilla se descubre
     * recién al guardar.
     */
    function nuevoResumen() {
        if (!resumenDe) { return; }

        Notificacion.pedirTexto({
            titulo: 'Cargar resumen de ' + resumenDe.ROTULO,
            mensaje: 'Formato: período, importe en $, importe en U$S, vencimiento. Dejá vacío el '
                + 'importe que no corresponda.',
            detalle: 'Ejemplo: 2026-10 | 450000 | 320.50 | 2026-10-12 — Pisa la estimación de '
                + 'ese período, con su importe y su fecha.',
            etiqueta: 'período | $ | U$S | vencimiento',
            placeholder: '2026-10 | 450000 |  | 2026-10-12',
            invalido: 'Completá al menos el período, un importe y el vencimiento.',
            confirmar: 'Cargar'
        }).then(function(v) {
            if (v === null) { return; }

            var p = v.split('|').map(function(x) { return x.trim(); });

            if (p.length < 4) {
                Notificacion.error('Faltan datos. Se esperaban cuatro separados por «|»: '
                    + 'período, importe en $, importe en U$S y vencimiento.');

                return;
            }

            guardar('saveResumen', {
                id_tarjeta: resumenDe.ID,
                mes: p[0],
                importe_ars: p[1],
                importe_usd: p[2],
                fecha_vencimiento: p[3]
            });
        });
    }

    /**
     * La base histórica: los últimos 3 resúmenes de una.
     *
     * VA EN UNA SOLA TRANSACCIÓN del lado del backend, así que o entran los tres o
     * ninguno: con dos, la tarjeta estimaría un promedio distinto sin que nadie se
     * entere.
     */
    function baseHistorica() {
        if (!resumenDe) { return; }

        Notificacion.pedirTexto({
            titulo: 'Cargar base histórica de ' + resumenDe.ROTULO,
            mensaje: 'Los últimos 3 resúmenes, uno por línea: período | $ | U$S | vencimiento.',
            detalle: 'Entran como ya pagados, así que no suman al horizonte: son la base con la '
                + 'que se estima. O entran los tres o ninguno.',
            etiqueta: 'Un resumen por línea',
            placeholder: '2026-06 | 300000 | 1000 | 2026-06-15\n'
                + '2026-07 | 330000 | 1200 | 2026-07-15\n'
                + '2026-08 | 270000 | 800 | 2026-08-17',
            /* El diálogo del módulo ya usa un <textarea>, así que las tres líneas
               entran sin ninguna opción extra. maxlargo se sube porque el default
               son 200 caracteres y tres resúmenes no entran. */
            maxlargo: 600,
            invalido: 'Cargá al menos un resumen.',
            confirmar: 'Cargar base'
        }).then(function(v) {
            if (v === null) { return; }

            var resumenes = v.split('\n').map(function(l) { return l.trim(); })
                .filter(function(l) { return l !== ''; })
                .map(function(l) {
                    var p = l.split('|').map(function(x) { return x.trim(); });

                    return {mes: p[0], importe_ars: p[1], importe_usd: p[2],
                            fecha_vencimiento: p[3]};
                });

            if (!resumenes.length) { return; }

            guardar('cargarBase', {id_tarjeta: resumenDe.ID, resumenes: resumenes});
        });
    }

    /* ================================================================
       EL EJE — encabezados, celdas y totales
       ================================================================ */

    /**
     * El encabezado de las columnas del eje.
     *
     * LOS MESES SE MARCAN, porque una columna mensual y una diaria no miden lo
     * mismo: la del mes acumula sólo los días que quedaron fuera del tramo diario.
     */
    function encabezadoEje(idFila1, idFila2, cols) {
        var uno = document.getElementById(idFila1);
        var dos = document.getElementById(idFila2);

        if (uno) {
            uno.setAttribute('colspan', String(cols.length));
            uno.textContent = vistas.periodo();
        }

        if (dos) {
            dos.innerHTML = cols.map(function(col) {
                var meta = vistas.meta(col);

                return '<th class="text-end' + (vistas.esMes(col) ? ' col-mes' : '')
                    + (meta && meta.feriado_comercio ? ' col-feriado' : '') + '">'
                    + esc(vistas.rotulo(col)) + '</th>';
            }).join('');
        }
    }

    function celdasEje(eje, cols, negrita) {
        return cols.map(function(col) {
            var v = vistas.valor(eje, col);

            return '<td class="currency' + (negrita ? '' : ' text-muted') + '">'
                + (v === 0 || v === null ? '' : plataCorta(v)) + '</td>';
        }).join('');
    }

    /**
     * El pie de totales.
     *
     * TIENE QUE TENER EXACTAMENTE LAS MISMAS COLUMNAS QUE EL ENCABEZADO, y no es
     * cosmético: un colspan mal contado corre todos los totales del eje una celda y
     * cada número queda debajo de otro mes. Pasó en esta misma grilla —el pie de
     * Corporativas dibujaba una columna de total que el encabezado no tenía— y no se
     * ve como un error: se ve como números.
     *
     * La cuenta es una sola para las tres tablas: `colsDesc` celdas descriptivas
     * juntas en un colspan, las del eje una por una, y el total del período AL FINAL.
     *
     * @param {string} id Id del <tr> del pie
     * @param {number} colsDesc Columnas descriptivas, antes del eje
     * @param {Object} totales La fila de totales del payload
     * @param {Array} cols Las columnas visibles del eje
     * @param {string} [rotulo] Qué dice la celda del rótulo
     */
    function pintarTotales(id, colsDesc, totales, cols, rotulo) {
        var pie = document.getElementById(id);

        if (!pie) { return; }

        var html = '<td colspan="' + colsDesc + '" class="fw-bold text-end">'
            + esc(rotulo || 'TOTALES') + '</td>';

        cols.forEach(function(col) {
            var v = vistas.valor(totales, col);

            html += '<td class="currency fw-bold">'
                + (v === 0 || v === null ? '' : plataCorta(v)) + '</td>';
        });

        // El total del período, en la última columna: la misma posición que en las
        // filas de arriba.
        html += '<td class="currency fw-bold">' + plata(vistas.total(totales)) + '</td>';

        pie.innerHTML = html;
    }

    /** Busca la fila del eje de una clave. null si esa clave no proyecta nada */
    function buscarFila(filas, campo, valor) {
        for (var i = 0; i < filas.length; i++) {
            if (String(filas[i][campo]) === String(valor)) { return filas[i]; }
        }

        return null;
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function llenarTarjetasDelSelect() {
        var sel = document.getElementById('selTarjetaCorp');

        if (!sel) { return; }

        var corporativas = datos.tarjetas.filter(function(t) {
            return t.TIPO === 'CORPORATIVA' && t.ACTIVA;
        });

        sel.innerHTML = '<option value="">'
            + (corporativas.length ? 'Elegí la tarjeta…'
                : 'No hay tarjetas corporativas activas')
            + '</option>'
            + corporativas.map(function(t) {
                return '<option value="' + esc(t.ID) + '">' + esc(t.ROTULO) + '</option>';
            }).join('');
    }

    function buscarTarjeta(id) {
        for (var i = 0; i < datos.tarjetas.length; i++) {
            if (String(datos.tarjetas[i].ID) === String(id)) { return datos.tarjetas[i]; }
        }

        return null;
    }

    function rotuloTarjeta(id) {
        var t = buscarTarjeta(id);

        return t ? t.ROTULO : ('tarjeta ' + id);
    }

    function engancharAbrir(cuerpo) {
        cuerpo.querySelectorAll('.tarj-abrir').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var clave = btn.getAttribute('data-clave');

                if (abiertas[clave]) { delete abiertas[clave]; } else { abiertas[clave] = true; }

                dibujar();
            });
        });

        cuerpo.querySelectorAll('.tarj-resumenes').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirResumenes(btn.getAttribute('data-id'));
            });
        });
    }

    /** Guarda y recarga: cualquier escritura puede mover importes de las tres */
    function guardar(accion, cuerpo) {
        pedir(ENDPOINT + '?action=' + accion, cuerpo)
            .then(function(d) {
                Notificacion.exito(d.message || 'Listo.');
                seleccion = {};
                cargar();

                if (resumenDe) { abrirResumenes(resumenDe.ID); }
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar: ' + e.message);
                cargar();
            });
    }

    function avisar(lista) {
        var cont = document.getElementById('avisosTarj');

        if (!cont) { return; }

        cont.innerHTML = (!lista || !lista.length) ? '' :
            '<div class="alert alert-warning py-2 px-3 mb-3 small">'
            + '<i class="fas fa-triangle-exclamation me-1"></i>'
            + lista.map(esc).join('<br>')
            + '</div>';
    }

    function filaVacia(cols, mensaje) {
        return '<tr><td colspan="' + cols + '" class="text-center text-muted py-4">'
            + esc(mensaje) + '</td></tr>';
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) { el.style.display = visible ? '' : 'none'; }
    }

    function apagar(id, apagado, motivo) {
        var el = document.getElementById(id);

        if (!el) { return; }

        el.disabled = !!apagado;
        el.title = apagado ? motivo : '';
    }

    function marcado(id) {
        var el = document.getElementById(id);

        return el ? el.checked : false;
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (!el) { return ''; }

        if (valor === undefined) {
            return (el.value !== undefined && el.tagName !== 'SMALL') ? el.value : el.textContent;
        }

        el.textContent = valor;

        return valor;
    }

    function plata(n) {
        return '$ ' + Number(n || 0).toLocaleString('es-AR',
            {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function plataOGuion(n) {
        return (n === null || n === undefined) ? '<small class="text-muted">—</small>' : plata(n);
    }

    /** Corta, para las celdas del eje: en miles y sin decimales */
    function plataCorta(n) {
        return Number(n || 0).toLocaleString('es-AR', {maximumFractionDigits: 0});
    }

    function dolares(n) {
        return (n === null || n === undefined)
            ? '<small class="text-muted">—</small>'
            : 'US$ ' + Number(n).toLocaleString('es-AR',
                {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function dolaresCorto(n) {
        return Number(n || 0).toLocaleString('es-AR', {maximumFractionDigits: 0});
    }

    function pct(n) {
        return (n === null || n === undefined)
            ? '—'
            : (Number(n) * 100).toLocaleString('es-AR', {maximumFractionDigits: 1}) + '%';
    }

    function fecha(f) {
        if (!f) { return '<small class="text-muted">—</small>'; }

        var p = String(f).substring(0, 10).split('-');

        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function pedir(url, cuerpo) {
        var opciones = cuerpo
            ? {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(cuerpo)
            }
            : {};

        return fetch(url, opciones)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    throw new Error((d && d.message) || 'Respuesta inesperada del servidor');
                }

                return d;
            });
    }

    /* ================================================================
       ARRANQUE
       ================================================================ */

    function iniciar() {
        vistas = crearEjeVistas({
            botones: 'vistasTarj',
            periodo: 'periodoTarj',
            alCambiar: dibujar
        });

        enganchar('btnRefreshTarj', 'click', cargar);

        /* Los tres filtros de Corporativas sólo redibujan: no vuelven al servidor,
           porque el universo ya está cargado y filtrar es presentación. */
        enganchar('buscadorCorp', 'input', dibujarCorporativas);
        enganchar('verVencidasCorp', 'change', dibujarCorporativas);
        enganchar('verExcluidasCorp', 'change', dibujarCorporativas);

        enganchar('selTodasCorp', 'change', function() {
            var todas = document.getElementById('selTodasCorp').checked;

            filasCorpVisibles().forEach(function(f) {
                if (todas) { seleccion[f.CLAVE] = true; } else { delete seleccion[f.CLAVE]; }
            });

            dibujarCorporativas();
        });

        enganchar('btnVincularCorp', 'click', vincular);
        enganchar('btnDesvincularCorp', 'click', desvincular);
        enganchar('btnExcluirCorp', 'click', function() { accionExclusion(true); });
        enganchar('btnIncluirCorp', 'click', function() { accionExclusion(false); });
        enganchar('btnLimpiarSelCorp', 'click', function() {
            seleccion = {};
            dibujarCorporativas();
        });

        enganchar('btnNuevoResumen', 'click', nuevoResumen);
        enganchar('btnBaseHistorica', 'click', baseHistorica);
        enganchar('btnCerrarResumenes', 'click', function() {
            resumenDe = null;
            mostrar('cardResumenes', false);
        });

        /* LAS COLUMNAS FIJAS, una por grilla y con su propia clave: las tres tablas
           tienen columnas descriptivas distintas, así que una clave compartida haría
           que fijar dos columnas en una dejara mal la otra. Por defecto se fija la
           primera —la supervisora, el proveedor, la tarjeta—, que es la que dice de
           quién es la fila. */
        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({tabla: 'tablaSup', control: 'colFijasSup',
                clave: 'tarjetas_supervisoras', porDefecto: [0]});
            crearColumnasFijas({tabla: 'tablaCorp', control: 'colFijasCorp',
                clave: 'tarjetas_corporativas', porDefecto: [1]});
            crearColumnasFijas({tabla: 'tablaSoc', control: 'colFijasSoc',
                clave: 'tarjetas_socios', porDefecto: [0]});
        }

        cargar();
    }

    function enganchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) { el.addEventListener(evento, fn); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
