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
    var COLS_CORP = 11;
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

        // Lo que el tablero dice de esta pestaña, en paralelo con los datos:
        // la grilla no lo espera. Ver Js/avisos-panel.js.
        AvisosPanel.pedirTablero('pagos_tarjetas');

        pedir(ENDPOINT + '?action=getDatos')
            .then(function(d) {
                datos = d.data;
                Cargando.ocultar('loadingTarj');
                mostrar('wrapperTarj', true);

                vistas.usar(datos.eje);
                avisar([]);
                avisarPartes();
                llenarTarjetasDelSelect();
                dibujar();
            })
            .catch(function(e) {
                Cargando.ocultar('loadingTarj');
                avisar([{ nivel: 'danger', texto: 'No se pudieron calcular los pagos con tarjetas: ' + e.message }]);
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

        encabezadoEje('headerEjeSup2', cols);

        texto('supCartel', s.cartel);

        /* Cuándo sale el efectivo lo dice la configuración del cronograma
           SUPERVISORAS, no un texto fijo: se cambia en Parámetros › Generales. */
        if (s.cronograma && s.cronograma.descripcion) {
            texto('supRegla', s.cronograma.descripcion);
        }

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
            pintarTotales('tablaSup', 'totalesSup', COLS_SUP, s.eje_total.totales, cols);
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
                    + marcaHistorico(f)
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

        pintarTotales('tablaSup', 'totalesSup', COLS_SUP, s.eje_total.totales, cols);
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

    /**
     * De dónde sale el promedio de quien no carga gastos: el histórico manual, o
     * que todavía no lo tiene. Con permiso, el botón para cargarlo.
     */
    function marcaHistorico(f) {
        if (f.origen !== 'MANUAL' && f.origen !== 'SIN_HISTORICO') { return ''; }

        var meses = f.historico ? Object.keys(f.historico).sort() : [];
        var detalle = meses.map(function(m) {
            return m + ': ' + plata(f.historico[m]);
        }).join(' · ');

        var marca = f.origen === 'MANUAL'
            ? ' <span class="badge bg-info text-dark" title="' + esc('No carga gastos en la app de '
                + 'supervisión: proyecta con el histórico manual. ' + detalle) + '">'
                + 'histórico manual</span>'
            : '';

        var boton = (datos.supervisoras.historico_tabla && Permisos.puedeEditar('bodySup'))
            ? ' <button class="btn btn-link btn-sm p-0 tarj-historico" data-nombre="' + esc(f.nombre)
                + '" title="' + (f.origen === 'MANUAL' ? 'Corregir' : 'Cargar')
                + ' el histórico manual de los últimos 3 meses">'
                + '<i class="fas fa-clock-rotate-left"></i></button>'
            : '';

        return marca + boton;
    }

    /**
     * Los resúmenes compartidos de esta tarjeta: una marca por mes, con quiénes
     * lo comparten y dónde se proyecta.
     */
    function marcasCompartido(lista) {
        return (lista || []).map(function(c) {
            return ' <span class="badge ' + (c.es_titular ? 'bg-primary' : 'bg-secondary')
                + '" title="' + esc(c.nota + ' Resumen de ' + c.mes + ': ' + plata(c.importe)
                    + ', vence el ' + String(c.fecha).split('-').reverse().join('/') + '.') + '">'
                + '<i class="fas fa-link me-1"></i>compartido ' + esc(c.mes)
                + (c.es_titular ? '' : ' · no suma acá') + '</span>';
        }).join('');
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
                + botonResumenes(f.tarjeta)
                + marcasCompartido(f.compartidos);
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
            SIN_IMPORTE: 'sus gastos suman cero',
            SIN_HISTORICO: 'no carga gastos: cargale el histórico'
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

        encabezadoEje('headerEjeCorp2', cols);

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
        /* "ENTRA AL FLUJO" INCLUYE LAS ESTIMACIONES MENSUALES, que suman en la
           serie CORPORATIVAS del tablero igual que una factura vinculada; el
           detalle dice cuánto es estimación, porque no es deuda de Tango. */
        var estimado = vistas.total(c.eje_estimaciones.totales);

        /* LOS SEGUROS SUMAN EN EL MISMO KPI —son parte de la serie
           CORPORATIVAS— y el detalle dice cuánto es de seguros. */
        var seguros = vistas.total(c.eje_polizas.totales);

        texto('corpTotal', plata(vistas.total(c.eje.totales)
            + vistas.total(c.eje_cobertura.totales) + vistas.total(c.eje_resumenes.totales)
            + estimado + seguros));
        texto('corpTotalDetalle', 'facturas + cobertura + resúmenes + estimaciones + seguros'
            + (estimado || seguros
                ? ' · de esto, ' + plata(estimado) + ' es estimación por proveedor y '
                  + plata(seguros) + ' es de seguros'
                : ''));
        texto('corpVencidas', plata(kpis.sinVincular));
        texto('corpVencidasDetalle', kpis.cuantasSinVincular
            + ' factura(s) que no entran al flujo: vinculalas a una tarjeta');
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

        pintarTotales('tablaCorp', 'totalesCorp', COLS_CORP, c.eje.totales, cols);
        dibujarExtras();
        dibujarVtosInertes();
        dibujarEstimaciones();
        dibujarSeguros();
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

        // Quién la vinculó o la excluyó, y cuándo (Js/auditoria.js)
        var aud = [f.VINCULO_FECHA || f.VINCULO_USUARIO
                ? 'Vinculada a la tarjeta por ' + (Auditoria.quien(f.VINCULO_USUARIO) || 'sin usuario registrado')
                    + (f.VINCULO_FECHA ? ' · ' + Auditoria.fecha(f.VINCULO_FECHA) : '') : '',
            f.EXCLUSION_FECHA || f.EXCLUSION_USUARIO
                ? 'Excluida por ' + (Auditoria.quien(f.EXCLUSION_USUARIO) || 'sin usuario registrado')
                    + (f.EXCLUSION_FECHA ? ' · ' + Auditoria.fecha(f.EXCLUSION_FECHA) : '') : '']
            .filter(function(l) { return l !== ''; }).join('\n');

        return '<tr class="' + clases.join(' ') + '" title="'
            + esc((f.EXPLICACION || '') + (aud ? '\n' + aud : '')) + '">'
            + '<td class="text-center">'
                // Sin permiso no hay que seleccionar: la seleccion es para escribir
                + Permisos.siEdita('bodyCorp',
                    '<input type="checkbox" class="form-check-input tarj-sel" '
                    + 'data-clave="' + esc(clave) + '"'
                    + (seleccion[clave] ? ' checked' : '') + '>')
            + '</td>'
            + '<td>' + esc(f.COD_PROVEE) + '</td>'
            + '<td class="col-texto">' + esc(f.RAZON_SOC) + '</td>'
            + '<td>' + esc(f.T_COMP) + '</td>'
            + '<td>' + esc(f.N_COMP) + '</td>'
            // Informativa: el vencimiento tal como está en Tango
            + '<td data-orden="' + esc(f.FECHA_VTO || '') + '"><small class="text-muted">'
                + fecha(f.FECHA_VTO) + '</small></td>'
            + celdaVtoCorp(f)
            + celdaDebito(f)
            + '<td>' + (f.TARJETA
                ? '<small>' + esc(f.TARJETA.ROTULO) + '</small>'
                : '<small class="text-muted">sin vincular</small>')
            + '</td>'
            + '<td class="currency">' + importe + '</td>'
            + '<td class="text-nowrap">' + estadoCorp(f)
                + (f.PROVEEDOR_PROYECTA
                    ? ' <i class="fas fa-repeat text-primary" title="El proveedor proyecta: '
                      + 'sus facturas del último mes se repiten en Estimaciones mensuales"></i>'
                    : '')
            + '</td>'
            + celdasEje(f, cols, true)
            + '<td class="currency fw-bold">' + plata(vistas.total(f)) + '</td>'
        + '</tr>';
    }

    /**
     * La fecha en que se paga: el débito de su tarjeta a partir del vencimiento,
     * o el próximo pago si ese débito ya pasó. Sin tarjeta es el vencimiento, y
     * se ve atenuado porque no suma.
     */
    function celdaDebito(f) {
        var titulo = f.EXPLICACION || '';

        if (!f.FECHA) {
            return '<td data-orden="9999-12-31" title="' + esc(titulo) + '">'
                + '<small class="text-muted">fuera del horizonte</small></td>';
        }

        return '<td data-orden="' + esc(f.FECHA) + '" title="' + esc(titulo) + '">'
            + (f.ID_TARJETA
                ? '<span class="fw-semibold">' + fecha(f.FECHA) + '</span>'
                : '<small class="text-muted">' + fecha(f.FECHA) + '</small>')
            + '</td>';
    }

    /** El estado de una factura, en una marca */
    function estadoCorp(f) {
        var marcas = {
            EXCLUIDA: ['bg-danger', 'excluida'],
            CUBIERTA: ['bg-info text-dark', 'cubierta por resumen'],
            SIN_TARJETA: ['bg-warning text-dark', 'sin tarjeta: no entra'],
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

        /* UN RESUMEN COMPARTIDO SE VE EN CADA TARJETA QUE INCLUYE, y entra sólo en
           la titular: en las otras dice dónde se proyecta. */
        c.resumenes.forEach(function(x) {
            filas.push({
                concepto: x.compartido ? 'Resumen compartido' : 'Resumen cargado',
                icono: x.compartido ? 'fa-link' : 'fa-file-invoice-dollar',
                tarjeta: rotuloTarjeta(x.id_tarjeta),
                mes: x.mes,
                fecha: x.fecha,
                base: null,
                importe: x.importe,
                proyecta: x.proyecta,
                motivo: x.motivo,
                tooltip: (x.nota ? x.nota + ' ' : '') + 'Reemplaza a las facturas vinculadas de '
                    + x.mes + ' y a su cobertura.',
                extra: x.pagado ? 'pagado' : (x.compartido && x.es_titular ? 'titular' : '')
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
                    : (x.concepto === 'Resumen compartido' && x.motivo
                        && x.motivo.indexOf('compartido') !== -1
                        ? '<span class="badge bg-secondary">informativo</span>'
                        : '<span class="badge bg-secondary">no entra</span>')) + '</td>'
                + '<td><small class="text-muted">' + esc(x.extra || x.motivo || '')
                    + '</small></td>'
            + '</tr>';
        }).join('');
    }

    function kpisCorp(filas) {
        var k = {sinVincular: 0, cuantasSinVincular: 0, excluidas: 0, cuantasExcluidas: 0};

        filas.forEach(function(f) {
            /* TODAS LAS NO VINCULADAS, vencidas o no: ninguna entra al flujo. */
            if (f.MOTIVO === 'SIN_TARJETA') {
                k.sinVincular += Number(f.IMPORTE) || 0;
                k.cuantasSinVincular++;
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

    /* ---- el vencimiento, editable ---- */

    /**
     * La celda VTO TANGO: el vencimiento que vale en esta pestaña.
     *
     * Quien carga en Tango pone ahí el vencimiento del RESUMEN en el que se paga
     * la cuota; si lo puso mal, se corrige acá sin tocar Tango y sin mover
     * Cuentas a Pagar Locales. Mismo patrón que la fecha de Proveedores Locales
     * y de Cobranzas FR: un input date que guarda en 'change' y un ↺ que vuelve
     * al de Tango.
     *
     * EDITADA SE VE DISTINTA, y el title dice el original de Tango y quién lo
     * cambió: una fecha corregida que se ve igual que una de Tango se lee como si
     * Tango dijera eso.
     *
     * Sin permiso se ve sólo la fecha. Sin el script, el input se dibuja apagado
     * nombrando el archivo.
     */
    function celdaVtoCorp(f) {
        var vigente = f.FECHA_VTO_VIGENTE || f.FECHA_VTO;
        var vencida = f.VENCIDA ? ' <span class="badge bg-danger">vencida</span>' : '';
        var titulo = f.VTO_EDITADO
            ? 'Vencimiento corregido en esta pestaña. En Tango vence el ' + fecha(f.FECHA_VTO) + '.'
              + (f.VTO_EDIT_MOTIVO ? '\nMotivo: ' + f.VTO_EDIT_MOTIVO : '')
              + '\n' + Auditoria.texto({ alta: { usuario: f.VTO_EDIT_USUARIO, fecha: f.VTO_EDIT_FECHA } })
            : 'Vencimiento de Tango: con él se calcula el débito. Corregilo para mandar la cuota '
              + 'a otro débito: sólo vale en esta pestaña.';

        if (!Permisos.puedeEditar('bodyCorp')) {
            return '<td data-orden="' + esc(vigente) + '" title="' + esc(titulo) + '"'
                + (f.VTO_EDITADO ? ' class="tarj-vto-editado"' : '') + '>'
                + fecha(vigente)
                + (f.VTO_EDITADO ? ' <span class="badge bg-info text-dark">corregido</span>' : '')
                + vencida + '</td>';
        }

        var sinTabla = !datos.tablas.factura_vto;

        return '<td data-orden="' + esc(vigente) + '"'
            + (f.VTO_EDITADO ? ' class="tarj-vto-editado"' : '') + '>'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +   '<input type="date" class="form-control form-control-sm tarj-vto" '
            +     'value="' + esc(vigente) + '" min="' + esc(datos.hoy) + '" '
            +     'data-cod="' + esc(f.COD_PROVEE) + '" data-tcomp="' + esc(f.T_COMP) + '" '
            +     'data-ncomp="' + esc(f.N_COMP) + '" data-vto-tango="' + esc(f.FECHA_VTO) + '" '
            +     'data-vigente="' + esc(vigente) + '" '
            +     'title="' + esc(sinTabla
                    ? 'Para corregir el vencimiento falta correr '
                      + 'sql/cashflow_tarjetas_vto_mensual.sql'
                    : titulo) + '"'
            +     (sinTabla ? ' disabled' : '') + '>'
            +   (f.VTO_EDITADO && !sinTabla
                  ? '<button class="btn btn-outline-secondary tarj-vto-volver" type="button" '
                    + 'title="Volver al vencimiento de Tango (' + esc(fecha(f.FECHA_VTO)) + ')">'
                    + '<i class="fas fa-rotate-left"></i></button>'
                  : '')
            + '</div>' + vencida + '</td>';
    }

    /** Los datos de la cuota de un control de la celda VTO */
    function cuotaDe(el) {
        var input = el.closest('td').querySelector('.tarj-vto');

        return {
            cod_provee: input.getAttribute('data-cod'),
            t_comp: input.getAttribute('data-tcomp'),
            n_comp: input.getAttribute('data-ncomp'),
            fecha_vto_tango: input.getAttribute('data-vto-tango')
        };
    }

    /**
     * Guardar el vencimiento corregido. El motivo se pide y es OPCIONAL:
     * corregir una fecha cargada con el resumen equivocado no necesita
     * explicación, y obligarla volvería la corrección un trámite. Cancelar deja
     * la celda como estaba.
     */
    function editarVto(input) {
        var nueva = input.value;

        if (!nueva || nueva === input.getAttribute('data-vigente')) { return; }

        if (nueva < datos.hoy) {
            Notificacion.campoInvalido(input, 'El vencimiento corregido no puede ser anterior a hoy.');
            input.value = input.getAttribute('data-vigente');

            return;
        }

        var cuota = cuotaDe(input);

        Notificacion.pedirTexto({
            titulo: 'Corregir el vencimiento',
            mensaje: cuota.t_comp + ' ' + cuota.n_comp + ' de ' + cuota.cod_provee + ': vence el '
                + fecha(nueva) + ' en esta pestaña.',
            detalle: 'En Tango vence el ' + fecha(cuota.fecha_vto_tango) + ' y ahí no cambia, ni '
                + 'en Cuentas a Pagar Locales.',
            etiqueta: 'Motivo',
            opcional: true,
            maxlargo: 300,
            confirmar: 'Guardar'
        }).then(function(motivo) {
            if (motivo === null) {
                input.value = input.getAttribute('data-vigente');

                return;
            }

            cuota.fecha = nueva;
            cuota.motivo = motivo;
            guardar('editarVtoFactura', cuota);
        });
    }

    /**
     * La lista de las correcciones que quedaron sin cuota: Tango cambió el
     * vencimiento. Va abajo de la grilla y sólo cuando hay alguna, con un botón
     * para darlas de baja: el aviso dice que existen y esto es dónde se arreglan.
     */
    function dibujarVtosInertes() {
        var caja = document.getElementById('vtosInertesCorp');

        if (!caja) { return; }

        var lista = datos.corporativas.vtos_inertes || [];

        if (!lista.length) {
            caja.innerHTML = '';
            caja.style.display = 'none';

            return;
        }

        caja.style.display = '';
        caja.innerHTML = '<small class="fw-semibold d-block mb-1">'
            + '<i class="fas fa-triangle-exclamation text-warning me-1"></i>'
            + 'Vencimientos corregidos que ya no se aplican: Tango cambió el vencimiento de la cuota'
            + '</small>'
            + lista.map(function(v) {
                return '<div class="d-flex align-items-center gap-2 small">'
                    + '<span>' + esc(v.T_COMP + ' ' + v.N_COMP + ' de ' + v.COD_PROVEE)
                    + ' · vto. Tango ' + fecha(v.FECHA_VTO_TANGO) + ' → corregido al '
                    + fecha(v.FECHA_VTO) + '</span>'
                    + Permisos.siEdita('bodyCorp',
                        '<button class="btn btn-sm btn-outline-secondary py-0 tarj-vto-inerte" '
                        + 'data-cod="' + esc(v.COD_PROVEE) + '" data-tcomp="' + esc(v.T_COMP) + '" '
                        + 'data-ncomp="' + esc(v.N_COMP) + '" '
                        + 'data-vto-tango="' + esc(v.FECHA_VTO_TANGO) + '">Dar de baja</button>')
                    + '</div>';
            }).join('');

        caja.querySelectorAll('.tarj-vto-inerte').forEach(function(btn) {
            btn.addEventListener('click', function() {
                guardar('deshacerVtoFactura', {
                    cod_provee: btn.getAttribute('data-cod'),
                    t_comp: btn.getAttribute('data-tcomp'),
                    n_comp: btn.getAttribute('data-ncomp'),
                    fecha_vto_tango: btn.getAttribute('data-vto-tango')
                });
            });
        });
    }

    /* ---- la sección Estimaciones mensuales ---- */

    /** Columnas descriptivas de la tabla de estimaciones, antes del eje */
    var COLS_EST = 6;

    /**
     * Una fila por estimación —una factura de origen que se repite—, con sus
     * meses en el eje y el estado de cada mes.
     *
     * VA APARTE DE LA GRILLA DE FACTURAS, por lo mismo que la cobertura y los
     * resúmenes: no son facturas, y mezclarlas haría que el total de PENDIENTE
     * dejara de significar algo.
     *
     * NO SE DESMARCA DESDE ACÁ: lo que proyecta es el PROVEEDOR, y se marca y se
     * desmarca en Parámetros › Tarjetas. El ícono de quién lo marcó es de esa
     * marca.
     */
    function dibujarEstimaciones() {
        var c = datos.corporativas;
        var cols = vistas.columnas();
        var cuerpo = document.getElementById('bodyCorpEst');
        var marcas = {};

        (c.proveedores_proyectan || []).forEach(function(p) { marcas[p.COD_PROVEE] = p; });

        encabezadoEje('headerEjeEst2', cols);

        if (!cuerpo) { return; }

        var lista = c.estimaciones || [];

        if (!lista.length) {
            cuerpo.innerHTML = filaVacia(COLS_EST + cols.length + 1, !datos.tablas.prov_proyecta
                ? 'Para marcar proveedores que proyectan falta correr '
                  + 'sql/cashflow_tarjetas_prov_proyecta.sql.'
                : ((c.proveedores_proyectan || []).length
                    ? 'Los proveedores que proyectan no tienen facturas pendientes vinculadas a una '
                      + 'tarjeta: hasta que se cargue la siguiente, no hay estimación.'
                    : 'Ningún proveedor proyecta. Se marcan en Parámetros › Tarjetas.'));
        } else {
            cuerpo.innerHTML = lista.map(function(e) {
                var eje = buscarFila(c.eje_estimaciones.filas, 'id_estimacion', e.ID) || {};
                var m = marcas[e.COD_PROVEE] || {};

                return '<tr class="' + (e.TARJETA_OK ? '' : 'tarj-sin-proyectar') + '">'
                    + '<td class="col-texto" title="' + esc(e.RAZON_SOC || '') + '">'
                        + esc(e.RAZON_SOC || e.COD_PROVEE)
                        + Auditoria.icono({ alta: { usuario: m.USUARIO_ALTA, fecha: m.FECHA_ALTA } })
                    + '</td>'
                    + '<td><small>' + esc(e.T_COMP + ' ' + e.N_COMP) + '<br>vto. Tango '
                        + fecha(e.FECHA_VTO_TANGO)
                        + (e.FECHA_VTO_ORIGEN && e.FECHA_VTO_ORIGEN !== e.FECHA_VTO_TANGO
                            ? ' · a considerar ' + fecha(e.FECHA_VTO_ORIGEN) : '')
                    + '</small></td>'
                    + '<td><small>' + esc(e.TARJETA ? e.TARJETA.ROTULO : 'tarjeta inexistente')
                        + (e.TARJETA_OK ? '' : ' <span class="badge bg-secondary">no proyecta</span>')
                    + '</small></td>'
                    + '<td class="currency">' + plata(e.IMPORTE) + '</td>'
                    + '<td class="text-center">' + esc(e.DIA) + '</td>'
                    + '<td>' + estadosEstimacion(e) + '</td>'
                    + celdasEje(eje, cols, true)
                    + '<td class="currency fw-bold">' + plata(vistas.total(eje)) + '</td>'
                + '</tr>';
            }).join('');
        }

        pintarTotales('tablaCorpEst', 'totalesCorpEst', COLS_EST, c.eje_estimaciones.totales, cols);
    }

    /* ---- la sección Seguros ---- */

    /** Columnas descriptivas de la tabla de seguros, antes del eje */
    var COLS_SEG = 6;

    /**
     * Una fila por póliza, con sus pagos en el eje. Los de la continuación
     * estimada se ven distintos —en itálica y atenuados— y lo dicen en el
     * tooltip, igual que el resto de los estados de cada pago.
     */
    function dibujarSeguros() {
        var c = datos.corporativas;
        var seg = c.seguros || {polizas: [], proveedores: [], facturas: {cuantas: 0}};
        var cols = vistas.columnas();
        var cuerpo = document.getElementById('bodyCorpSeg');

        encabezadoEje('headerEjeSeg2', cols);

        var f = seg.facturas || {};

        texto('corpSegurosFacturas', f.cuantas
            ? 'Hay ' + f.cuantas + ' vencimiento(s) de seguros pendientes en Tango, de '
              + f.comprobantes + ' factura(s), por ' + plata(f.importe) + '. No suman: los seguros '
              + 'se proyectan con las pólizas.'
            : 'No hay facturas de seguros pendientes en Tango.');

        apagar('btnNuevaPoliza', !seg.tabla_creada,
            'Para cargar pólizas falta correr sql/cashflow_tarjetas_polizas.sql');

        if (!cuerpo) { return; }

        if (!seg.polizas.length) {
            cuerpo.innerHTML = filaVacia(COLS_SEG + cols.length + 1, !seg.tabla_creada
                ? 'Para cargar pólizas falta correr sql/cashflow_tarjetas_polizas.sql.'
                : (seg.proveedores.length
                    ? 'No hay pólizas cargadas. Los proveedores de seguros no proyectan nada hasta '
                      + 'que se cargue su póliza.'
                    : 'No hay proveedores de seguros: en el maestro de Proveedores Locales tienen que '
                      + 'tener rubro SEGUROS y forma de pago TARJETA CORP.'));
        } else {
            cuerpo.innerHTML = seg.polizas.map(function(p) {
                var eje = buscarFila(c.eje_polizas.filas, 'id_poliza', p.ID) || {};
                var continua = (p.resueltos.pagos || []).some(function(x) { return x.continuacion; });
                var moneda = p.MONEDA === 'USD' ? 'U$S ' : '$ ';
                var importe = function(n) {
                    return moneda + Number(n || 0).toLocaleString('es-AR',
                        {minimumFractionDigits: 2, maximumFractionDigits: 2});
                };

                return '<tr>'
                    + '<td class="col-texto">' + esc(p.RAZON_SOC || p.COD_PROVEE)
                        + Auditoria.icono({ alta: { usuario: p.USUARIO_ALTA, fecha: p.FECHA_ALTA },
                                            modif: { usuario: p.USUARIO_MODIF, fecha: p.FECHA_MODIF } })
                        + Permisos.siEdita('bodyCorpSeg',
                            ' <button type="button" class="btn btn-sm btn-link p-0 tarj-pol-editar" '
                            + 'data-id="' + esc(p.ID) + '" title="Corregir la póliza">'
                            + '<i class="fas fa-pen"></i></button>'
                            + ' <button type="button" class="btn btn-sm btn-link text-danger p-0 '
                            + 'tarj-pol-baja" data-id="' + esc(p.ID) + '" title="Dar de baja">'
                            + '<i class="fas fa-xmark"></i></button>')
                    + '</td>'
                    + '<td><small>' + esc(p.POLIZA)
                        + (p.OBSERVACION ? '<br><span class="text-muted">' + esc(p.OBSERVACION) + '</span>' : '')
                    + '</small></td>'
                    + '<td><small>' + esc(p.TARJETA ? p.TARJETA.ROTULO : 'tarjeta inexistente') + '</small></td>'
                    + '<td class="currency">' + importe(p.IMPORTE_TOTAL) + '</td>'
                    + '<td><small>' + fecha(p.FECHA_INICIO) + ' al ' + fecha(p.FIN_VIGENCIA)
                        + '<br>' + p.VIGENCIA_MESES + ' mes(es), ' + p.GRACIA_MESES + ' de gracia · '
                        + '1er pago ' + esc(p.MES_PRIMER_PAGO)
                        + (continua && !p.SIGUIENTE
                            ? ' <span class="badge bg-light text-dark" title="La vigencia termina antes '
                              + 'del fin del horizonte: el ciclo se repite hasta que se cargue la póliza '
                              + 'siguiente">se continúa</span>'
                            : '')
                        + (p.SIGUIENTE ? ' <span class="badge bg-light text-dark">sigue la del '
                            + fecha(p.SIGUIENTE) + '</span>' : '')
                    + '</small></td>'
                    + '<td class="currency">' + importe(p.CUOTA) + '</td>'
                    + celdasEjeSeguro(eje, cols, p)
                    + '<td class="currency fw-bold">' + plata(vistas.total(eje)) + '</td>'
                + '</tr>';
            }).join('');
        }

        pintarTotales('tablaCorpSeg', 'totalesCorpSeg', COLS_SEG, c.eje_polizas.totales, cols);

        cuerpo.querySelectorAll('.tarj-pol-editar').forEach(function(btn) {
            btn.addEventListener('click', function() { formularioPoliza(btn.getAttribute('data-id')); });
        });

        cuerpo.querySelectorAll('.tarj-pol-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                Notificacion.confirmar({
                    titulo: 'Dar de baja la póliza',
                    mensaje: '¿Dar de baja esta póliza?',
                    detalle: 'No se borra: queda en el historial. Deja de proyectar, y si es la del '
                        + 'proveedor, el seguro no proyecta nada hasta que se cargue otra.',
                    confirmar: 'Dar de baja',
                    peligro: true
                }).then(function(si) {
                    if (si) { guardar('bajaPoliza', {id: Number(btn.getAttribute('data-id'))}); }
                });
            });
        });
    }

    /**
     * Las celdas de una póliza. El importe viene del eje; el pago de cada mes da
     * el tooltip y la marca de la continuación estimada.
     */
    function celdasEjeSeguro(eje, cols, p) {
        var porMes = {};

        (p.resueltos.pagos || []).forEach(function(x) { porMes[x.mes] = x; });

        return cols.map(function(col) {
            var v = vistas.valor(eje, col);
            var meta = vistas.meta(col);
            var mes = vistas.esMes(col) ? String(col).substring(4) : (meta ? meta.mes_clave : null);
            var pago = mes ? porMes[mes] : null;
            var hay = !(v === 0 || v === null);

            return '<td class="currency ' + vistas.marca(col).clase
                + (pago && pago.continuacion && hay ? ' tarj-continuacion' : '') + '"'
                + (pago && hay ? ' title="' + esc(pago.tooltip) + '"' : '') + '>'
                + (hay ? plataCorta(v) : '')
            + '</td>';
        }).join('');
    }

    /**
     * Alta o corrección de una póliza: el formulario con todos los campos. La
     * validación que vale es la del servidor (TarjetasSeguros::validar()).
     */
    function formularioPoliza(id) {
        var seg = datos.corporativas.seguros || {proveedores: [], polizas: []};
        var p = null;

        (seg.polizas || []).forEach(function(x) { if (String(x.ID) === String(id)) { p = x; } });

        var corporativas = datos.tarjetas.filter(function(t) {
            return t.TIPO === 'CORPORATIVA' && t.ACTIVA;
        });

        Notificacion.formulario({
            titulo: p ? 'Corregir la póliza ' + p.POLIZA : 'Cargar póliza',
            mensaje: 'Se paga en ' + 'cuotas iguales: el total dividido por la vigencia menos la gracia, '
                + 'una por mes desde el primer pago, en el débito de la tarjeta.',
            campos: [
                {nombre: 'proveedor', etiqueta: 'Proveedor', tipo: 'lista', obligatorio: true, ancho: 6,
                 valor: p ? p.COD_PROVEE : '',
                 opciones: seg.proveedores.map(function(s) {
                     return {valor: s.COD_PROVEE, rotulo: s.RAZON_SOC + ' (' + s.COD_PROVEE + ')'};
                 })},
                {nombre: 'poliza', etiqueta: 'Número o descripción de la póliza', tipo: 'texto',
                 obligatorio: true, ancho: 6, valor: p ? p.POLIZA : '',
                 ayuda: 'Con el proveedor identifica la póliza siguiente: usá el mismo al renovar.'},
                {nombre: 'id_tarjeta', etiqueta: 'Tarjeta', tipo: 'lista', obligatorio: true, ancho: 6,
                 valor: p ? p.ID_TARJETA : '',
                 opciones: corporativas.map(function(t) {
                     return {valor: t.ID, rotulo: t.ROTULO + ' · vence el ' + t.DIA_VENCIMIENTO};
                 }),
                 ayuda: 'De ella salen el día del débito y el % de cobertura.'},
                {nombre: 'moneda', etiqueta: 'Moneda', tipo: 'lista', obligatorio: true, ancho: 2,
                 valor: p ? p.MONEDA : 'ARS',
                 opciones: [{valor: 'ARS', rotulo: '$'}, {valor: 'USD', rotulo: 'U$S'}]},
                {nombre: 'importe_total', etiqueta: 'Importe total de la vigencia', tipo: 'importe',
                 obligatorio: true, positivo: true, ancho: 4, valor: p ? p.IMPORTE_TOTAL : ''},
                {nombre: 'fecha_inicio', etiqueta: 'Inicio de la vigencia', tipo: 'fecha', obligatorio: true,
                 ancho: 4, valor: p ? p.FECHA_INICIO : ''},
                {nombre: 'vigencia_meses', etiqueta: 'Vigencia (meses)', tipo: 'numero', obligatorio: true,
                 min: 1, max: 120, ancho: 2, valor: p ? p.VIGENCIA_MESES : 12},
                {nombre: 'gracia_meses', etiqueta: 'Gracia (meses)', tipo: 'numero', obligatorio: true,
                 min: 0, max: 119, ancho: 2, valor: p ? p.GRACIA_MESES : 0,
                 ayuda: 'Los meses del final en que no se paga.'},
                {nombre: 'mes_primer_pago', etiqueta: 'Mes del primer pago', tipo: 'mes', obligatorio: true,
                 ancho: 4, valor: p ? p.MES_PRIMER_PAGO : ''},
                {nombre: 'observacion', etiqueta: 'Observación', tipo: 'texto', largo: 500, ancho: 8,
                 valor: p ? (p.OBSERVACION || '') : ''}
            ],
            validar: function(v) {
                return (v.gracia_meses !== null && v.vigencia_meses !== null
                        && v.gracia_meses >= v.vigencia_meses)
                    ? 'La gracia tiene que ser menor que la vigencia: si no, no queda ningún pago.'
                    : null;
            },
            confirmar: p ? 'Guardar' : 'Cargar póliza'
        }).then(function(v) {
            if (v === null) { return; }

            v.id = p ? p.ID : null;
            guardar('guardarPoliza', v);
        });
    }

    /** El estado de cada mes de una estimación, en marcas cortas */
    function estadosEstimacion(e) {
        var marcas = {
            PROYECTA: ['bg-success', 'entra'],
            CUBIERTA: ['bg-info text-dark', 'cubierta por resumen'],
            PASADA: ['bg-secondary', 'ya pasó'],
            SIN_TARJETA: ['bg-secondary', 'sin tarjeta activa']
        };

        if (!e.meses || !e.meses.length) {
            return '<small class="text-muted">empieza en ' + esc(e.MES_DESDE) + '</small>';
        }

        return e.meses.map(function(m) {
            var mk = marcas[m.estado] || ['bg-secondary', m.estado];
            var texto = mk[1];

            return '<span class="badge ' + mk[0] + ' me-1" title="' + esc(fecha(m.fecha)) + '">'
                + esc(m.mes.substring(5) + '/' + m.mes.substring(2, 4)) + ' · ' + esc(texto)
                + '</span>';
        }).join('');
    }

    /* ---- selección y acciones masivas ---- */

    function engancharCorp(cuerpo) {
        cuerpo.querySelectorAll('.tarj-vto').forEach(function(input) {
            input.addEventListener('change', function() { editarVto(input); });
        });

        cuerpo.querySelectorAll('.tarj-vto-volver').forEach(function(btn) {
            btn.addEventListener('click', function() {
                guardar('deshacerVtoFactura', cuotaDe(btn));
            });
        });

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

        encabezadoEje('headerEjeSoc2', cols);

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
            pintarTotales('tablaSoc', 'totalesSoc', COLS_SOC, s.eje_total.totales, cols,
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

        pintarTotales('tablaSoc', 'totalesSoc', COLS_SOC, s.eje_total.totales, cols, 'TOTALES EN PESOS');
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
                        ? ' tarj-resumen' : '') + ' ' + vistas.marca(col).clase + '"'
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
                        ? Permisos.segun('bodyResumenes',
                            '<div class="form-check form-switch d-inline-block">'
                            + '<input class="form-check-input tarj-pagado" type="checkbox" '
                            + 'data-id="' + esc(r.ID) + '"' + (r.PAGADO ? ' checked' : '')
                            + ' title="Marcarlo como pagado lo saca del horizonte"></div>',
                            r.PAGADO ? '<i class="fas fa-check text-success" title="Pagado"></i>'
                                     : '<small class="text-muted">no</small>')
                        : '<small class="text-muted">—</small>')
                + '</td>'
                + '<td>'
                    + (r.COMPARTIDO
                        ? '<span class="badge ' + (r.ES_TITULAR ? 'bg-primary' : 'bg-secondary')
                          + ' me-1" title="' + esc('Incluye: ' + r.TARJETAS.map(rotuloTarjeta).join('; ')
                            + '. Titular: ' + rotuloTarjeta(r.ID_TITULAR) + '. Se proyecta una sola '
                            + 'vez, en la titular; darlo de baja lo saca de todas.') + '">'
                          + '<i class="fas fa-link me-1"></i>compartido · '
                          + (r.ES_TITULAR ? 'titular' : 'no suma acá') + '</span>'
                        : '')
                    + '<small>' + esc(r.OBSERVACION || '') + '</small></td>'
                // Quién y cuándo, con el formato de Js/auditoria.js
                + '<td><small class="text-muted">'
                    + esc(Auditoria.linea('modif', r.USUARIO_MODIF, r.FECHA_MODIF))
                    + (r.ACTIVO ? '' : '<div>' + esc(Auditoria.linea('baja', r.USUARIO_BAJA, r.FECHA_BAJA))
                        + '</div>')
                + '</small></td>'
                + '<td class="text-center">'
                    + (r.ACTIVO && Permisos.puedeEditar('bodyResumenes')
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

    /** Al menos uno de los dos importes: lo que no se ve campo por campo */
    function algunImporte(v) {
        return (v.importe_ars === null && v.importe_usd === null)
            ? 'Cargá al menos uno de los dos importes, en $ o en U$S. Pueden ir los dos.'
            : null;
    }

    /**
     * Las tarjetas que pueden compartir el resumen con la de esta fila: las
     * Supervisora y Corporativa activas. La de la fila viene elegida y marcada
     * como titular.
     *
     * SE OFRECEN TODAS Y NO SOLO LAS DEL MISMO BANCO: que sean del mismo banco y
     * del mismo día lo valida el servidor y lo dice con nombre. La nota de cada
     * una muestra banco y día para que se vea antes de elegir.
     */
    function opcionesTarjetasResumen(t, actual) {
        var elegidas = (actual && actual.TARJETAS) ? actual.TARJETAS.map(String) : [String(t.ID)];
        var titular = (actual && actual.ID_TITULAR) ? String(actual.ID_TITULAR) : String(t.ID);

        return datos.tarjetas
            .filter(function(x) {
                return x.ACTIVA && (x.TIPO === 'SUPERVISORA' || x.TIPO === 'CORPORATIVA');
            })
            .map(function(x) {
                return {
                    valor: String(x.ID),
                    rotulo: x.ROTULO,
                    nota: x.TIPO_NOMBRE + ' · vence el ' + x.DIA_VENCIMIENTO,
                    marcada: elegidas.indexOf(String(x.ID)) !== -1,
                    titular: String(x.ID) === titular
                };
            });
    }

    /**
     * Carga un resumen: un formulario con los campos separados.
     *
     * DESDE LA FILA DE UNA TARJETA viene con esa tarjeta elegida. En una
     * Supervisora o Corporativa se pueden sumar las adicionales de la misma
     * cuenta: el resumen es uno, se ve en todas y suma una sola vez, en la
     * titular. Un socio no comparte resumen.
     *
     * Hasta acá se cargaba como una línea con separadores, y un «|» de más corría
     * todos los campos.
     */
    function nuevoResumen() {
        if (!resumenDe) { return; }

        var compartible = (resumenDe.TIPO === 'SUPERVISORA' || resumenDe.TIPO === 'CORPORATIVA')
            && datos.tablas.resumen_compartido;
        var campos = [];

        if (compartible) {
            campos.push({
                nombre: 'tarjetas', etiqueta: 'Tarjeta(s) del resumen', tipo: 'casillas',
                obligatorio: true, titular: true, opciones: opcionesTarjetasResumen(resumenDe),
                ayuda: 'Si el banco manda un solo resumen para varias tarjetas de la misma cuenta, '
                    + 'elegilas todas y marcá la titular: pisa la estimación de todas y suma una '
                    + 'sola vez, en la titular. Tienen que ser del mismo banco y vencer el mismo día.'
            });
        }

        campos.push(
            {nombre: 'mes', etiqueta: 'Período', tipo: 'mes', obligatorio: true, ancho: 4,
             ayuda: 'El mes del vencimiento: es la estimación que pisa.'},
            {nombre: 'importe_ars', etiqueta: 'Importe en $', tipo: 'importe', positivo: true, ancho: 4},
            {nombre: 'importe_usd', etiqueta: 'Importe en U$S', tipo: 'importe', positivo: true, ancho: 4},
            {nombre: 'fecha_vencimiento', etiqueta: 'Vencimiento', tipo: 'fecha', obligatorio: true,
             ancho: 4},
            {nombre: 'observacion', etiqueta: 'Observación', tipo: 'texto', largo: 500, ancho: 8}
        );

        Notificacion.formulario({
            titulo: 'Cargar resumen de ' + resumenDe.ROTULO,
            mensaje: 'Pisa la estimación de ese período, con su importe y su fecha.',
            campos: campos,
            validar: algunImporte,
            confirmar: 'Cargar'
        }).then(function(v) {
            if (v === null) { return; }

            guardar('saveResumen', {
                id_tarjeta: resumenDe.ID,
                tarjetas: compartible ? v.tarjetas.elegidas : [resumenDe.ID],
                titular: compartible ? v.tarjetas.titular : resumenDe.ID,
                mes: v.mes,
                importe_ars: v.importe_ars,
                importe_usd: v.importe_usd,
                fecha_vencimiento: v.fecha_vencimiento,
                observacion: v.observacion
            });
        });
    }

    /**
     * La base histórica: los últimos 3 resúmenes de una, en una grilla de tres
     * renglones.
     *
     * VA EN UNA SOLA TRANSACCIÓN del lado del backend, así que o entran los tres o
     * ninguno: con dos, la tarjeta estimaría un promedio distinto sin que nadie se
     * entere. El servidor exige los tres.
     */
    function baseHistorica() {
        if (!resumenDe) { return; }

        Notificacion.formulario({
            titulo: 'Cargar base histórica de ' + resumenDe.ROTULO,
            mensaje: 'Los últimos 3 resúmenes. En cada uno, al menos un importe.',
            detalle: 'Entran como ya pagados, así que no suman al horizonte: son la base con la '
                + 'que se estima. O entran los tres o ninguno.',
            renglones: {
                cantidad: 3,
                obligatorios: true,
                campos: [
                    {nombre: 'mes', etiqueta: 'Período', tipo: 'mes', obligatorio: true},
                    {nombre: 'importe_ars', etiqueta: 'Importe en $', tipo: 'importe', positivo: true},
                    {nombre: 'importe_usd', etiqueta: 'Importe en U$S', tipo: 'importe', positivo: true},
                    {nombre: 'fecha_vencimiento', etiqueta: 'Vencimiento', tipo: 'fecha',
                     obligatorio: true}
                ]
            },
            validar: function(v) {
                for (var i = 0; i < v.renglones.length; i++) {
                    if (algunImporte(v.renglones[i])) {
                        return 'Renglón ' + (i + 1) + ': cargá al menos uno de los dos importes.';
                    }
                }

                return null;
            },
            confirmar: 'Cargar base'
        }).then(function(v) {
            if (v === null) { return; }

            guardar('cargarBase', {id_tarjeta: resumenDe.ID, resumenes: v.renglones});
        });
    }

    /**
     * El histórico manual de quien tiene tarjeta Supervisora y no carga gastos:
     * tres renglones de mes e importe, todo con tarjeta.
     *
     * VIENE CON LOS TRES MESES DE LA VENTANA, que son los que se promedian para
     * las supervisoras. Se pueden cambiar; el servidor exige tres meses cerrados.
     */
    function historicoManual(nombre) {
        var f = buscarFila(datos.supervisoras.filas, 'nombre', nombre);

        if (!f || !f.id_usuario) { return; }

        var meses = datos.supervisoras.ventana.meses;
        var previo = f.historico || {};
        var claves = Object.keys(previo).sort();
        var valores = [0, 1, 2].map(function(i) {
            var mes = claves[i] || meses[i];

            return {mes: mes, importe: previo[mes] !== undefined ? previo[mes] : ''};
        });

        Notificacion.formulario({
            titulo: 'Histórico manual de ' + f.nombre,
            mensaje: 'El gasto con tarjeta de los últimos 3 meses. Se proyecta el promedio, '
                + 'ajustado por inflación, en el vencimiento de su tarjeta.',
            detalle: 'Un mes sin gastos va en 0: igual se divide por 3. Si la persona empieza a '
                + 'cargar gastos en la app de supervisión, mandan los gastos y este histórico '
                + 'deja de usarse.',
            renglones: {
                cantidad: 3,
                obligatorios: true,
                /* Arranca con los meses de la ventana, o con lo que ya estaba
                   cargado si se está corrigiendo. */
                valores: valores,
                campos: [
                    {nombre: 'mes', etiqueta: 'Mes', tipo: 'mes', obligatorio: true},
                    {nombre: 'importe', etiqueta: 'Importe en $', tipo: 'importe', obligatorio: true}
                ]
            },
            confirmar: 'Cargar histórico'
        }).then(function(v) {
            if (v === null) { return; }

            guardar('cargarHistoricoManual', {id_usuario: f.id_usuario, meses: v.renglones});
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
    /**
     * La fila de las fechas.
     *
     * TOTAL PERÍODO vive en esta misma fila, al final, y viene del HTML con su
     * title: se la guarda, se reescriben las fechas y se la vuelve a poner. Así
     * conserva también las clases que le pone tabla-orden.js si está ordenada.
     *
     * La fila de arriba ya no lleva el período -lo dice el cartel- sino los
     * totales, y esos los pinta pintarTotales().
     */
    function encabezadoEje(idFila, cols) {
        var fila = document.getElementById(idFila);

        if (!fila) { return; }

        var total = fila.querySelector('th.total-column');

        fila.innerHTML = cols.map(function(col) {
            var meta = vistas.meta(col);
            // Sábado, domingo o feriado: lo decide el backend (vistas.marca())
            var marca = vistas.marca(col, meta && meta.feriado_comercio
                ? 'Feriado de comercio: sin venta estimada' : '');

            return '<th class="text-end' + (vistas.esMes(col) ? ' col-mes' : '')
                + (meta && meta.feriado_comercio ? ' col-feriado' : '')
                + (marca.clase ? ' ' + marca.clase : '') + '"'
                + (marca.titulo ? ' title="' + esc(marca.titulo) + '"' : '') + '>'
                + esc(vistas.rotulo(col)) + '</th>';
        }).join('');

        if (total) { fila.appendChild(total); }
    }

    function celdasEje(eje, cols, negrita) {
        return cols.map(function(col) {
            var v = vistas.valor(eje, col);

            return '<td class="currency' + (negrita ? '' : ' text-muted')
                + ' ' + vistas.marca(col).clase + '">'
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
     * La cuenta es una sola para las cuatro tablas: `colsDesc` celdas descriptivas
     * juntas en un colspan, las del eje una por una, y el total del período AL FINAL.
     *
     * Y los mismos números van arriba de cada fecha (Js/eje-totales.js): se leen
     * UNA VEZ de la fila de totales y se escriben con las mismas dos funciones,
     * celdaEje() y celdaTotal(). Por eso el pie y la fila de arriba no pueden
     * decir cosas distintas.
     *
     * @param {string} idTabla Id de la <table>
     * @param {string} id Id del <tr> del pie
     * @param {number} colsDesc Columnas descriptivas, antes del eje
     * @param {Object} totales La fila de totales del payload
     * @param {Array} cols Las columnas visibles del eje
     * @param {string} [rotulo] Qué dice la celda del rótulo
     */
    function pintarTotales(idTabla, id, colsDesc, totales, cols, rotulo) {
        var pie = document.getElementById(id);

        if (!pie) { return; }

        var valores = cols.map(function(col) { return vistas.valor(totales, col); });
        var total = vistas.total(totales);

        var html = '<td colspan="' + colsDesc + '" class="fw-bold text-end">'
            + esc(rotulo || 'TOTALES') + '</td>';

        valores.forEach(function(v, i) {
            html += '<td class="currency fw-bold ' + vistas.marca(cols[i]).clase + '">'
                + celdaEje(v) + '</td>';
        });

        // El total del período, en la última columna: la misma posición que en las
        // filas de arriba.
        html += '<td class="currency fw-bold">' + celdaTotal(total) + '</td>';

        pie.innerHTML = html;

        pintarEjeTotales(idTabla, {
            columnas: cols,
            valores: valores,
            total: total,
            formato: celdaEje,
            formatoTotal: celdaTotal
        });
    }

    /** Una celda de totales del eje: corta, y vacía en cero o sin dato */
    function celdaEje(v) {
        return (v === 0 || v === null) ? '' : plataCorta(v);
    }

    /** La del total del período: con signo y decimales, también en cero */
    function celdaTotal(total) {
        return plata(total);
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

        cuerpo.querySelectorAll('.tarj-historico').forEach(function(btn) {
            btn.addEventListener('click', function() {
                historicoManual(btn.getAttribute('data-nombre'));
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

    /**
     * El panel plegable de todas las pestañas (Js/avisos-panel.js). Los textos
     * sueltos cuentan como atención; un error de carga llega como
     * {nivel: 'danger'} y abre el panel solo.
     */
    function avisar(lista) {
        AvisosPanel.pintar('avisosTarj', lista || []);
    }

    /**
     * Un panel por sub-pestaña. Cada uno lleva los avisos generales de la
     * pestaña, los de su parte y los que el tablero dice de su sección
     * (Supervisoras, Corporativas o Socios); los del tablero sin sección van a
     * las tres. Hasta feature/cashflow-avisos-kpis-comex-nac-parcial la
     * pestaña mostraba sólo los generales, en un bloque arriba: los de cada
     * parte los decía sólo el tablero.
     */
    function avisarPartes() {
        var generales = datos.avisos || [];
        var parte = function(clave) {
            return generales.concat((datos[clave] && datos[clave].avisos) || []);
        };

        AvisosPanel.pintarPestana('avisosTarjSup', parte('supervisoras'),
            { tab: 'pagos_tarjetas', sub: 'sup' });
        AvisosPanel.pintarPestana('avisosTarjCorp', parte('corporativas'),
            { tab: 'pagos_tarjetas', sub: 'corp' });
        AvisosPanel.pintarPestana('avisosTarjSoc', parte('socios'),
            { tab: 'pagos_tarjetas', sub: 'soc' });
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
        enganchar('btnNuevaPoliza', 'click', function() { formularioPoliza(null); });
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

        /* TARJETAS CORPORATIVAS ABRE POR VENCIMIENTO ASCENDENTE: lo que vence
           primero, arriba. Es la columna VTO TANGO, que muestra el vencimiento
           vigente -el corregido, si lo hay- y ya lleva data-orden con esa fecha.
           El nombre es el data-orden-nombre del <th>, igual al rótulo a
           propósito: quien ya ordenó a mano conserva su orden. La clave es el
           id de la tabla, la misma del descubrimiento automático. Ver
           Js/tabla-orden.js. */
        if (typeof crearOrdenTabla === 'function') {
            crearOrdenTabla({tabla: 'tablaCorp', porDefecto: {columna: 'DÉBITO TARJETA', dir: 'asc'}});
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
