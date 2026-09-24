/**
 * Comercio Exterior > Proyeccion (codigo interno compras_proyectadas)
 * La pantalla de la proyeccion de compras del exterior.
 *
 * NO CALCULA NADA. Todo lo que dibuja llega resuelto del servidor, del MISMO
 * proveedor que alimenta las dos filas del tablero. Con la cuenta en los dos
 * lados, la pestana y el tablero podrian mostrar dos estimaciones distintas del
 * mismo mes y nadie podria decir cual vale; es la misma decision que tomo
 * Comercio Exterior cuando la valuacion se mudo al getter.
 *
 * LA PANTALLA ES, SOBRE TODO, LA EXPLICACION DE UN CERO
 * -----------------------------------------------------
 * El tablero muestra dos filas con un numero. Lo que no puede mostrar es por
 * que un mes vale cero, y hay cinco motivos distintos que se ven igual:
 *
 *   SIN_PRESUPUESTO    la temporada de ese mes no tiene version oficial
 *   SIN_HISTORIA       la cuota no tiene datos para ese mes calendario
 *   CUBIERTO           lo ya comprado alcanza o supera lo proyectado
 *   AJUSTADO           alguien cargo el importe a mano
 *   AJUSTE_DESCARTADO  lo habia cargado, y cambio la version oficial
 *
 * Por eso el estado es una COLUMNA de la grilla y no un tooltip: es el dato por
 * el que se abre esta pantalla.
 *
 * NADA DE alert(). Todo aviso va a Notificacion o al contenedor de avisos de la
 * pantalla, igual que en el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/ComprasProyectadasController.php';

    /** Lo ultimo que devolvio el servidor, para no volver a pedirlo al filtrar */
    var datos = null;

    /** El control de columnas fijas de esta pestana */
    var colFijas = null;

    /* ================================================================
       ESTADOS
       ================================================================ */

    /**
     * Como se ve cada estado. El color NO es decoracion: separa los tres
     * grupos que hay que distinguir de un vistazo.
     *
     *   verde    el mes tiene su numero y esta bien
     *   azul     el numero lo puso una persona, o no hace falta ninguno
     *   rojo     falta algo y por eso el mes va en cero
     */
    var ESTADOS = {
        ESTIMADO: {
            texto: 'Estimado', clase: 'bg-success-subtle text-success-emphasis',
            icono: 'fa-circle-check',
            ayuda: 'Tiene versión oficial, cuota histórica y cotización.'
        },
        AJUSTADO: {
            texto: 'Ajustado', clase: 'bg-primary-subtle text-primary-emphasis',
            icono: 'fa-pen',
            ayuda: 'El importe lo cargó una persona y reemplaza a la estimación automática.'
        },
        CUBIERTO: {
            texto: 'Cubierto', clase: 'bg-info-subtle text-info-emphasis',
            icono: 'fa-ship',
            ayuda: 'Lo ya comprado alcanza o supera lo proyectado, así que no queda nada por ' +
                   'comprometer. El exceso NO se compensa contra otros meses.'
        },
        SIN_COTIZACION: {
            texto: 'Sin cotización', clase: 'bg-warning-subtle text-warning-emphasis',
            icono: 'fa-triangle-exclamation',
            ayuda: 'El mes de pago no está en la curva de dólar futuro: se valuó con el mes ' +
                   'más cercano. El importe en dólares es correcto.'
        },
        SIN_HISTORIA: {
            texto: 'Sin historia', clase: 'bg-danger-subtle text-danger-emphasis',
            icono: 'fa-clock-rotate-left',
            ayuda: 'No hay ni un movimiento de recepción de ese mes calendario en los años de ' +
                   'historia configurados, así que la cuota no tiene con qué repartirlo.'
        },
        SIN_PRESUPUESTO: {
            texto: 'Sin presupuesto', clase: 'bg-danger-subtle text-danger-emphasis',
            icono: 'fa-file-circle-xmark',
            ayuda: 'La temporada de este mes no tiene versión oficial en la app de compras. ' +
                   'El mes va en CERO: se está proyectando de menos.'
        },
        AJUSTE_DESCARTADO: {
            texto: 'Ajuste descartado', clase: 'bg-danger-subtle text-danger-emphasis',
            icono: 'fa-rotate-left',
            ayuda: 'Este mes tenía un ajuste manual, pero cambió la versión oficial de su ' +
                   'temporada desde que se cargó: el número se puso mirando otro presupuesto. ' +
                   'Volvió a la estimación automática.'
        }
    };

    /* ================================================================
       FORMATO
       ================================================================ */

    function usd(n) {
        if (n === null || n === undefined) { return '—'; }

        return 'U$S ' + Number(n).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function pesos(n) {
        if (n === null || n === undefined) { return '—'; }

        return '$ ' + Number(n).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function num(n, dec) {
        if (n === null || n === undefined) { return '—'; }

        return Number(n).toLocaleString('es-AR',
            { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 0 });
    }

    function pct(n) {
        if (n === null || n === undefined) { return '—'; }

        return Number(n).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';
    }

    /** Los textos vienen del servidor: nunca se inyectan como HTML */
    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fecha(iso) {
        if (!iso) { return '—'; }

        var p = String(iso).substring(0, 10).split('-');

        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }

    /* ================================================================
       CARGA
       ================================================================ */

    /**
     * Muestra el indicador de carga donde corresponde.
     *
     * LA PRIMERA VEZ va en el lugar de la tabla, que todavia no existe. DESPUES
     * va ENCIMA de la tabla ya dibujada (modo overlay): recargar no la saca, asi
     * que no salta ni se pierde el scroll, y mientras tanto se sigue viendo lo
     * que habia.
     *
     * @return {string} El id del contenedor que lo muestra, para marcar pasos
     */
    function indicador(opciones) {
        var wrapper = document.getElementById('cpTableWrapper');
        var dibujada = !!(datos && wrapper && wrapper.style.display !== 'none');

        if (dibujada) {
            Cargando.mostrar(wrapper, Object.assign({ overlay: true }, opciones));

            return 'cpTableWrapper';
        }

        if (wrapper) { wrapper.style.display = 'none'; }

        Cargando.mostrar('cpSpinner', opciones);

        return 'cpSpinner';
    }

    function finIndicador() {
        Cargando.ocultar('cpTableWrapper');
        Cargando.ocultar('cpSpinner');
    }

    /**
     * Lee la grilla y la dibuja.
     *
     * @param {boolean} sinIndicador true cuando ya hay uno a la vista -el de
     *        "Actualizar ahora", que tiene este pedido como ultimo paso-
     * @return {Promise}
     */
    function cargar(sinIndicador) {
        if (sinIndicador !== true) {
            indicador({ titulo: 'Calculando la proyección…' });
        }

        return fetch(ENDPOINT + '?action=getGrilla')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    /* LA FALLA DE CARGA SE PINTA ADENTRO DEL WRAPPER Y NO PISA
                       LA TABLA, igual que en las dos pestañas de Comex: si la
                       pisara, "Actualizar" no tendría dónde dibujar. */
                    pintarAvisos([(d && d.message) || 'No se pudo cargar la proyección.'], 'error');
                    finIndicador();

                    return;
                }

                datos = d;
                pintar();
            })
            .catch(function(e) {
                pintarAvisos(['No se pudo cargar la proyección: ' + e.message], 'error');
                finIndicador();
            });
    }

    function pintar() {
        pintarAvisos(datos.avisos, 'aviso');
        pintarNotas(datos.notas);
        pintarCabecera();
        pintarInsumos();
        pintarKpis();
        pintarGrilla();
        pintarVersiones();

        var wrapper = document.getElementById('cpTableWrapper');

        finIndicador();
        if (wrapper) { wrapper.style.display = 'block'; }

        if (colFijas) { colFijas.aplicar(); }

        filtrar();
    }

    /* ================================================================
       AVISOS
       ================================================================ */

    function pintarAvisos(lista, tipo) {
        var cont = document.getElementById('avisosComprasProy');

        if (!cont) { return; }

        if (!lista || !lista.length) {
            cont.innerHTML = '';

            return;
        }

        var clase = (tipo === 'error') ? 'alert-danger' : 'alert-warning';
        var icono = (tipo === 'error') ? 'fa-circle-exclamation' : 'fa-triangle-exclamation';

        cont.innerHTML =
            '<div class="alert ' + clase + ' py-2 px-3 mb-3">' +
                '<div class="d-flex align-items-start gap-2">' +
                    '<i class="fas ' + icono + ' mt-1"></i>' +
                    '<div class="flex-grow-1">' +
                        '<ul class="mb-0 ps-3 small">' +
                            lista.map(function(a) {
                                return '<li>' + esc(a) + '</li>';
                            }).join('') +
                        '</ul>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    /**
     * Las notas de reconciliacion, al pie y separadas de los avisos.
     *
     * La mas comun -los contenedores que se pagan fuera de la ventana- aparece
     * SIEMPRE y con casi todo el padron adentro. Mezclada arriba con los avisos
     * que si importan, ensenia a ignorar el bloque entero.
     */
    function pintarNotas(lista) {
        var cont = document.getElementById('cpNotas');

        if (!cont) { return; }

        if (!lista || !lista.length) {
            cont.innerHTML = '';

            return;
        }

        cont.innerHTML =
            '<div class="card"><div class="card-body py-2">' +
                '<small class="text-muted d-block mb-1">' +
                    '<i class="fas fa-circle-info me-1"></i>Por qué estos números no coinciden ' +
                    'con otras pantallas' +
                '</small>' +
                '<ul class="mb-0 ps-3 small text-muted">' +
                    lista.map(function(n) { return '<li>' + esc(n) + '</li>'; }).join('') +
                '</ul>' +
            '</div></div>';
    }

    /* ================================================================
       CABECERA: DE DONDE SALEN LOS NUMEROS
       ================================================================ */

    function pintarCabecera() {
        var v = document.getElementById('cpVentanaTexto');
        var p = document.getElementById('cpParametrosTexto');
        var par = datos.parametros || {};

        if (v) {
            var meses = datos.meses || [];
            var desde = meses.length ? meses[0].mes : '—';
            var hasta = meses.length ? meses[meses.length - 1].mes : '—';

            /* SE EXPLICA POR QUE EL ULTIMO MES ES ESE, porque no es el último
               del horizonte y sin esta línea parece arbitrario. */
            v.innerHTML = 'Ventana: <strong>' + esc(desde) + '</strong> a <strong>' +
                esc(hasta) + '</strong> (' + meses.length + ' meses de recepción). ' +
                'El último es el último mes cuyo <strong>pago</strong> todavía cae dentro del ' +
                'horizonte, que cierra el ' + fecha(datos.fin_horizonte) + '.';
        }

        if (p) {
            p.innerHTML = '· Llegada el día ' + esc(par.compras_proy_dia_llegada) +
                ', pago ' + esc(par.compras_proy_dias_pago) + ' días antes, ' +
                'nacionalización ' + esc(par.compras_proy_dias_nac) + ' días antes. ' +
                'Cuota sobre ' + esc(par.compras_proy_anios_cuota) + ' años por ' +
                (par.compras_proy_base_cuota === 'UNIDADES' ? 'unidades' : 'importe') + '. ' +
                'Nacionalización ' + pct(par.compras_proy_nac_pct) + ' del FOB.';
        }
    }

    /**
     * "Historia al dd/mm hh:mm · Presupuesto al dd/mm hh:mm".
     *
     * LOS DOS INSUMOS PESADOS LOS CALCULA UN JOB, no este pedido. Sin la fecha,
     * un presupuesto de ayer se lee como el de hoy. Si la ultima corrida fallo
     * se marca en rojo, con el error en el tooltip: el numero de arriba sigue
     * siendo el de la ultima corrida buena.
     */
    function pintarInsumos() {
        var cont = document.getElementById('cpInsumosTexto');
        var ins = datos.insumos || {};

        if (!cont) { return; }

        cont.innerHTML = [['historia', 'Historia'], ['presupuesto', 'Presupuesto']].map(function(par) {
            var i = ins[par[0]] || {};
            var texto = i.al ? par[1] + ' al ' + i.al : par[1] + ': sin calcular';
            var ayuda = i.al
                ? 'Calculado' + (i.usuario ? ' por ' + i.usuario : '') +
                  (i.filas !== null && i.filas !== undefined ? ' · ' + i.filas + ' filas' : '')
                : 'Nunca corrió su job: la fila va en cero.';
            var clase = (!i.al) ? 'text-danger' : (i.fallo ? 'text-danger' : 'text-muted');
            var icono = (!i.al || i.fallo) ? 'fa-triangle-exclamation' : 'fa-database';

            if (i.fallo) { ayuda += '\nLa última corrida falló: ' + i.fallo; }

            return '<span class="' + clase + ' me-3" title="' + esc(ayuda) + '">' +
                '<i class="fas ' + icono + ' me-1"></i>' + esc(texto) + '</span>';
        }).join('');
    }

    /**
     * El boton "Actualizar ahora": los dos SP, de a uno, y despues la grilla.
     *
     * DE A UNO Y EN ORDEN, y no los dos en paralelo: si el del presupuesto
     * falla -el linked server es lo mas fragil-, la historia ya quedo al dia y
     * la pantalla dice cual de los dos fallo.
     */
    function actualizarAhora() {
        var btn = document.getElementById('btnActualizarInsumos');
        var pasos = [
            { proceso: 'historia', texto: 'Historia de recepciones' },
            { proceso: 'presupuesto', texto: 'Presupuesto oficial' }
        ];

        if (btn) { btn.disabled = true; }

        /* TRES PASOS EN EL INDICADOR: los dos SP y la grilla. El tercero es el
           mismo getGrilla de siempre, que corre adentro de este indicador en
           vez de abrir otro. */
        var cont = indicador({
            titulo: 'Actualizando historia y presupuesto…',
            pasos: pasos.map(function(p) { return p.texto; }).concat(['Recalcular la grilla'])
        });

        var cadena = Promise.resolve();

        pasos.forEach(function(p, i) {
            cadena = cadena.then(function() {
                Cargando.paso(cont, i, 'en_curso');

                return fetch(ENDPOINT + '?action=actualizarInsumo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ proceso: p.proceso })
                })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || !d.success) {
                            Cargando.paso(cont, i, 'error');

                            throw new Error(p.texto + ': ' +
                                ((d && d.message) || 'respuesta inesperada del servidor'));
                        }

                        Cargando.paso(cont, i, 'listo');
                    }, function(e) {
                        Cargando.paso(cont, i, 'error');

                        throw e;
                    });
            });
        });

        cadena
            .then(function() {
                Notificacion.exito('Historia y presupuesto recalculados.');
            })
            .catch(function(e) {
                Notificacion.error('No se pudo actualizar: ' + e.message, {
                    detalle: 'La grilla se vuelve a leer igual, con lo último que quedó calculado.'
                });
            })
            .then(function() {
                Cargando.paso(cont, pasos.length, 'en_curso');

                return cargar(true);
            })
            .then(function() {
                if (btn) { btn.disabled = false; }
            });
    }

    function pintarKpis() {
        var t = datos.totales || {};
        var cont = document.getElementById('summaryComprasProy');

        setTexto('cpProyectado', usd(t.proyectado_usd));
        setTexto('cpCargado', usd(t.cargado_usd));
        setTexto('cpEstimacion', usd(t.estimacion_usd));
        setTexto('cpNacionalizacion', usd(t.nacionalizacion_usd));

        /* EL EXCESO SE DICE EN EL PIE DE "YA COMPRADO" y no se resta de ningún
           lado: no se compensa contra otros meses. */
        if (t.exceso_usd > 0.01) {
            setTexto('cpCargadoPie', 'Descontado · ' + usd(t.exceso_usd) +
                ' de exceso que no se compensa');
        } else {
            setTexto('cpCargadoPie', 'Descontado de lo proyectado');
        }

        var eje = datos.totales_eje || {};

        if (eje.NACIONALIZACION_PROYECTADA && eje.NACIONALIZACION_PROYECTADA.fuera_horizonte > 0) {
            setTexto('cpNacPie', 'Sobre la estimación · ' +
                pesos(eje.NACIONALIZACION_PROYECTADA.fuera_horizonte) + ' caen fuera del eje');
        } else {
            setTexto('cpNacPie', 'Sobre la estimación');
        }

        if (cont) { cont.style.display = 'flex'; }
    }

    function setTexto(id, texto) {
        var el = document.getElementById(id);

        if (el) { el.textContent = texto; }
    }

    /* ================================================================
       LA GRILLA DE COBERTURA
       ================================================================ */

    function pintarGrilla() {
        var body = document.getElementById('cpTableBody');

        if (!body) { return; }

        body.innerHTML = (datos.meses || []).map(function(m) {
            var e = ESTADOS[m.estado] || ESTADOS.ESTIMADO;
            var version = m.version
                ? ('v' + m.version.id_version + ' · ' + fecha(m.version.fecha_calculo))
                : '—';

            /* El buscador mira este atributo y no el texto de la fila: así un
               importe o una fecha no dan falsos positivos. Mismo criterio que
               el buscador de las dos pestañas de Comex. */
            var busca = [m.mes, m.temporada || '', e.texto].join(' ').toLowerCase();

            return '<tr data-busca="' + esc(busca) + '">' +
                '<td class="fw-medium">' + esc(m.mes) + '</td>' +
                '<td>' + esc(m.temporada || '—') + '</td>' +
                '<td class="small text-muted">' + esc(version) + '</td>' +
                '<td>' + badgeEstado(m, e) + '</td>' +
                '<td class="text-end">' + pct(m.cuota_pct) + '</td>' +
                '<td class="text-end">' + usd(m.proyectado_usd) + '</td>' +
                '<td class="text-end">' + celdaCargado(m) + '</td>' +
                '<td class="text-end fw-bold">' + usd(m.estimacion_usd) + '</td>' +
                '<td>' + fecha(m.pago) + '</td>' +
                '<td class="text-end">' + celdaDolar(m.COTIZ_FOB, m.COTIZ_FOB_ORIGEN,
                                                    m.COTIZ_FOB_DETALLE) + '</td>' +
                '<td class="text-end">' + pesos(m.IMPORTE_FOB_ARS) + '</td>' +
                '<td>' + fecha(m.nacionalizacion) + '</td>' +
                '<td class="text-end">' + usd(m.nacionalizacion_usd) + '</td>' +
                '<td class="text-end">' + pesos(m.IMPORTE_NAC_ARS) + '</td>' +
                '<td class="text-center">' + botonAjuste(m) + '</td>' +
            '</tr>';
        }).join('');

        body.querySelectorAll('[data-ajuste-mes]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirAjuste(btn.getAttribute('data-ajuste-mes'));
            });
        });

        var t = datos.totales || {};

        setTexto('cpTotProyectado', usd(t.proyectado_usd));
        setTexto('cpTotCargado', usd(t.cargado_usd));
        setTexto('cpTotEstimacion', usd(t.estimacion_usd));
        setTexto('cpTotNacUsd', usd(t.nacionalizacion_usd));

        var pagoArs = 0;
        var nacArs = 0;

        (datos.meses || []).forEach(function(m) {
            pagoArs += Number(m.IMPORTE_FOB_ARS || 0);
            nacArs += Number(m.IMPORTE_NAC_ARS || 0);
        });

        setTexto('cpTotPagoArs', pesos(pagoArs));
        setTexto('cpTotNacArs', pesos(nacArs));
    }

    function badgeEstado(m, e) {
        var ayuda = e.ayuda;

        if (m.estado === 'AJUSTADO' && m.ajuste) {
            ayuda += ' Cargado' + (m.ajuste.usuario ? ' por ' + m.ajuste.usuario : '') +
                (m.ajuste.fecha ? ' el ' + fecha(m.ajuste.fecha) : '') + '.';

            if (m.ajuste.motivo) { ayuda += ' Motivo: ' + m.ajuste.motivo; }
        }

        var html = '<span class="badge ' + e.clase + '" title="' + esc(ayuda) + '">' +
            '<i class="fas ' + e.icono + ' me-1"></i>' + esc(e.texto) + '</span>';

        /* Lo que no ganó el estado viaja como MARCA: un mes ajustado y además
           valuado con el mes más cercano tiene las dos cosas que contar, y
           'estado' es una sola columna. */
        (m.marcas || []).forEach(function(marca) {
            var mm = ESTADOS[marca];

            if (mm) {
                html += ' <span class="badge ' + mm.clase + '" title="' + esc(mm.ayuda) + '">' +
                    '<i class="fas ' + mm.icono + '"></i></span>';
            }
        });

        return html;
    }

    /**
     * Lo ya comprado, con el exceso pegado cuando lo hay.
     *
     * EL EXCESO SE MUESTRA EN DOLARES y no se resta de ningun lado: un egreso
     * negativo seria un ingreso que nadie afirmo, y encima compensado en
     * silencio contra el resto de la columna.
     */
    function celdaCargado(m) {
        if (!m.cargado_usd) { return '—'; }

        var html = usd(m.cargado_usd);

        if (m.exceso_usd > 0.01) {
            html += ' <span class="badge bg-warning-subtle text-warning-emphasis" ' +
                'title="Lo ya comprado supera a lo proyectado en este mes. El exceso NO se ' +
                'compensa contra otros meses: este mes va en cero y los demás no cambian.">+' +
                usd(m.exceso_usd) + '</span>';
        }

        if (m.contenedores && m.contenedores.length) {
            html += ' <span class="text-muted small" title="' +
                esc(m.contenedores.map(function(c) {
                    return c.contenedor + ' · OC emitida ' + fecha(c.fec_emisio) +
                        ' · ' + usd(c.pendiente_usd);
                }).join('\n')) + '">(' + m.contenedores.length + ')</span>';
        }

        return html;
    }

    /**
     * El boton de ajuste de cada mes.
     *
     * UN MES SIN VERSION OFICIAL NO SE PUEDE AJUSTAR, y el boton se dibuja
     * deshabilitado diciendo por que. No es una restriccion de pantalla: el
     * ajuste queda atado a la version oficial de su temporada, y sin una no
     * tendria forma de caducar -quedaria aplicandose para siempre sobre una
     * temporada que nadie presupuesto-. El servidor lo rechaza igual; esto es
     * para no ofrecer algo que va a fallar.
     */
    function botonAjuste(m) {
        if (!m.version) {
            return '<button class="btn btn-sm btn-outline-secondary" disabled ' +
                'title="Este mes no tiene versión oficial de presupuesto, así que no hay a qué ' +
                'atar un ajuste. Marcá una versión oficial en la app de compras.">' +
                '<i class="fas fa-ban"></i></button>';
        }

        var puesto = m.ajuste && m.ajuste.aplicado;
        var descartado = m.estado === 'AJUSTE_DESCARTADO';

        return '<button class="btn btn-sm ' +
            (puesto ? 'btn-primary' : (descartado ? 'btn-outline-danger' : 'btn-outline-secondary')) +
            '" data-ajuste-mes="' + esc(m.mes) + '" title="' +
            (puesto ? 'Ajustado a mano. Click para corregirlo o sacarlo.'
                    : (descartado ? 'Tenía un ajuste que se descartó. Click para ver el historial.'
                                  : 'Cargar un importe a mano para este mes.')) + '">' +
            '<i class="fas fa-pen"></i></button>';
    }

    /**
     * El dolar con el que se valuo la fila, con su origen en el tooltip.
     *
     * Es el mismo criterio que las dos pestanas de Comex: de donde sale el
     * dolar no es decoracion, es lo que permite auditar el importe en pesos
     * contra el mercado.
     */
    function celdaDolar(cotiz, origen, detalle) {
        if (cotiz === null || cotiz === undefined) { return '—'; }

        var marca = (origen === 'APROXIMADA')
            ? ' <i class="fas fa-triangle-exclamation text-warning"></i>' : '';

        return '<span title="' + esc(detalle || '') + '">' + num(cotiz, 2) + marca + '</span>';
    }

    /* ================================================================
       LAS VERSIONES OFICIALES
       ================================================================ */

    function pintarVersiones() {
        var body = document.getElementById('cpVersionesBody');

        if (!body) { return; }

        var versiones = datos.versiones || [];
        var nacPct = (datos.parametros || {}).compras_proy_nac_pct;

        if (!versiones.length) {
            body.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">' +
                'No hay ninguna versión oficial vigente. Todos los meses de la ventana van en ' +
                'cero: se está proyectando de menos.</td></tr>';

            return;
        }

        body.innerHTML = versiones.map(function(v) {
            var unitario = v.unidades > 0 ? v.fob_usd / v.unidades : null;

            /* EL CONTRASTE, FILA POR FILA. Si el inc_fob del presupuesto se
               acercara alguna vez al porcentaje que aplica el cashflow, esta
               columna es donde se vería primero. */
            var lejos = Math.abs(Number(v.inc_fob_pct) - Number(nacPct)) > 15;

            return '<tr>' +
                '<td class="fw-medium">' + esc(v.temporada) + '</td>' +
                '<td class="small text-muted">' + fecha(v.desde) + ' a ' + fecha(v.hasta) + '</td>' +
                '<td>v' + esc(v.id_version) + ' <span class="small text-muted">' +
                    esc(v.solapa) + '</span></td>' +
                '<td>' + fecha(v.fecha_calculo) + '</td>' +
                '<td class="text-end">' + num(v.unidades) + '</td>' +
                '<td class="text-end fw-bold">' + usd(v.fob_usd) + '</td>' +
                '<td class="text-end">' + usd(unitario) + '</td>' +
                '<td class="text-end ' + (lejos ? 'text-danger' : '') + '">' +
                    pct(v.inc_fob_pct) + '</td>' +
                '<td class="text-end">' + pct(nacPct) + '</td>' +
                '<td class="text-end">' +
                    '<button class="btn btn-sm btn-outline-secondary" ' +
                        'data-detalle-version="' + esc(v.id_version) + '" ' +
                        'data-detalle-nombre="' + esc(v.temporada) + '" ' +
                        'title="Ver el detalle por rubro">' +
                        '<i class="fas fa-list"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        body.querySelectorAll('[data-detalle-version]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirDetalle(btn.getAttribute('data-detalle-version'),
                    btn.getAttribute('data-detalle-nombre'));
            });
        });
    }

    /* ================================================================
       EL DETALLE POR RUBRO
       ================================================================ */

    function abrirDetalle(idVersion, nombre) {
        var modalEl = document.getElementById('modalDetalleVersion');

        if (!modalEl || !window.bootstrap || !bootstrap.Modal) {
            Notificacion.error('No se puede abrir el detalle: falta Bootstrap en la página.');

            return;
        }

        setTexto('cpDetalleTitulo', nombre + ' · versión ' + idVersion);

        var wrapper = document.getElementById('cpDetalleWrapper');

        Cargando.mostrar('cpDetalleSpinner');
        if (wrapper) { wrapper.style.display = 'none'; }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        fetch(ENDPOINT + '?action=getDetalleVersion&id_version=' + encodeURIComponent(idVersion))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    Notificacion.error((d && d.message) || 'No se pudo leer el detalle.');
                    Cargando.ocultar('cpDetalleSpinner');

                    return;
                }

                pintarDetalle(d.detalle || []);

                Cargando.ocultar('cpDetalleSpinner');
                if (wrapper) { wrapper.style.display = 'block'; }

                if (typeof TablaExport !== 'undefined' && TablaExport.reaplicar) {
                    TablaExport.reaplicar();
                }
            })
            .catch(function(e) {
                Notificacion.error('No se pudo leer el detalle: ' + e.message);
                Cargando.ocultar('cpDetalleSpinner');
            });
    }

    function pintarDetalle(filas) {
        var body = document.getElementById('cpDetalleBody');

        if (!body) { return; }

        if (!filas.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">' +
                'La versión no tiene filas con compra.</td></tr>';
            setTexto('cpDetallePie', '');

            return;
        }

        var totalFob = 0;
        var sinCosto = 0;

        body.innerHTML = filas.map(function(f) {
            totalFob += Number(f.fob_usd || 0);

            /* UNA FILA SIN COSTO NO SE MULTIPLICA POR CERO: se marca. Un NULL
               tratado como cero es una afirmación ("esa mercadería no cuesta
               nada") que nadie hizo. */
            if (f.costo_prom === null) { sinCosto++; }

            return '<tr' + (f.costo_prom === null ? ' class="table-warning"' : '') + '>' +
                '<td>' + esc(f.rubro) + '</td>' +
                '<td>' + esc(f.categoria || '—') + '</td>' +
                '<td class="text-end">' + num(f.compra) + '</td>' +
                '<td class="text-end">' + (f.costo_prom === null
                    ? '<span class="text-danger" title="Sin costo cargado: esta fila no aporta ' +
                      'FOB y se informa aparte">sin costo</span>'
                    : usd(f.costo_prom)) + '</td>' +
                '<td class="text-end">' + usd(f.fob_usd) + '</td>' +
                '<td class="text-end">' + pct(f.inc_fob) + '</td>' +
                '<td class="text-end">' + num(f.deficit) + '</td>' +
            '</tr>';
        }).join('');

        setTexto('cpDetallePie', filas.length + ' filas · FOB ' + usd(totalFob) +
            (sinCosto ? ' · ' + sinCosto + ' sin costo cargado' : ''));
    }

    /* ================================================================
       EL AJUSTE MANUAL
       ================================================================ */

    /** El mes que el modal esta editando */
    var mesAjuste = null;

    function abrirAjuste(mes) {
        var modalEl = document.getElementById('modalAjuste');

        if (!modalEl || !window.bootstrap || !bootstrap.Modal) {
            Notificacion.error('No se puede abrir el ajuste: falta Bootstrap en la página.');

            return;
        }

        var m = null;

        (datos.meses || []).forEach(function(x) { if (x.mes === mes) { m = x; } });

        if (!m) { return; }

        mesAjuste = mes;

        setTexto('cpAjusteTitulo', mes + ' · ' + (m.temporada || ''));

        /* CONTRA QUE SE ESTA PONIENDO EL NUMERO. Sin esto, quien carga el
           ajuste no ve qué está reemplazando, y el ajuste existe justamente
           para apartarse de esa cuenta. */
        var ctx = document.getElementById('cpAjusteContexto');

        if (ctx) {
            ctx.innerHTML =
                '<div><strong>Estimación automática:</strong> ' + usd(
                    m.proyectado_usd - Math.min(m.cargado_usd, m.proyectado_usd)) +
                ' — la cuota de ' + pct(m.cuota_pct) + ' sobre el presupuesto de ' +
                esc(m.temporada) + ' (' + usd(m.version ? m.version.fob_usd : 0) + ')' +
                (m.cargado_usd ? ', menos ' + usd(m.cargado_usd) + ' ya comprados' : '') + '.</div>' +
                '<div class="mt-1 text-muted">Queda atado a la versión <strong>v' +
                esc(m.version ? m.version.id_version : '') + '</strong>, calculada el ' +
                fecha(m.version ? m.version.fecha_calculo : null) +
                '. Si se marca otra versión oficial de ' + esc(m.temporada) +
                ', el ajuste deja de aplicarse y el mes vuelve a la estimación automática.</div>';
        }

        var inputImporte = document.getElementById('cpAjusteImporte');
        var inputMotivo = document.getElementById('cpAjusteMotivo');

        /* Se precarga el ajuste VIGENTE si lo hay, y si no la estimación: así
           corregir un número es tocarlo, y cargar uno nuevo arranca del valor
           que se está por reemplazar. */
        if (inputImporte) {
            inputImporte.value = (m.ajuste && m.ajuste.importe_usd !== undefined)
                ? m.ajuste.importe_usd
                : Number(m.estimacion_usd).toFixed(2);
        }

        if (inputMotivo) {
            inputMotivo.value = (m.ajuste && m.ajuste.motivo) ? m.ajuste.motivo : '';
        }

        var btnQuitar = document.getElementById('cpAjusteQuitar');

        if (btnQuitar) {
            btnQuitar.style.display = (m.ajuste ? '' : 'none');
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        cargarHistorial(mes);
    }

    function cargarHistorial(mes) {
        var body = document.getElementById('cpHistorialBody');

        if (!body) { return; }

        body.innerHTML = '<tr><td colspan="7" class="text-muted small">Cargando…</td></tr>';

        fetch(ENDPOINT + '?action=getHistorialAjustes&mes=' + encodeURIComponent(mes))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var lista = (d && d.success) ? d.historial : [];

                if (!lista.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-muted small">' +
                        'Este mes nunca tuvo un ajuste.</td></tr>';

                    return;
                }

                body.innerHTML = lista.map(function(a) {
                    return '<tr' + (a.vigente ? '' : ' class="text-muted"') + '>' +
                        '<td>' + (a.vigente
                            ? '<span class="badge bg-primary-subtle text-primary-emphasis">Vigente</span>'
                            : '<span class="badge bg-secondary-subtle text-secondary-emphasis">De baja</span>') +
                        '</td>' +
                        '<td class="text-end">' + usd(a.importe_usd) + '</td>' +
                        '<td>v' + esc(a.id_version) + '</td>' +
                        '<td class="small">' + esc(a.motivo) + '</td>' +
                        '<td class="small">' + esc(a.usuario || '—') + '</td>' +
                        '<td class="small">' + esc(a.fecha_alta || '—') + '</td>' +
                        '<td class="small">' + esc(a.fecha_baja || '—') + '</td>' +
                    '</tr>';
                }).join('');
            })
            .catch(function(e) {
                body.innerHTML = '<tr><td colspan="7" class="text-danger small">' +
                    esc('No se pudo leer el historial: ' + e.message) + '</td></tr>';
            });
    }

    function guardarAjuste() {
        var importe = document.getElementById('cpAjusteImporte');
        var motivo = document.getElementById('cpAjusteMotivo');

        if (!mesAjuste || !importe || !motivo) { return; }

        /* SE VALIDA ACA Y TAMBIEN EN EL SERVIDOR. Lo de acá es para no hacer un
           viaje que ya se sabe que falla; la validación que VALE es la del
           servidor, porque el endpoint es alcanzable sin pasar por este modal. */
        if (importe.value === '' || isNaN(Number(importe.value)) || Number(importe.value) < 0) {
            Notificacion.campoInvalido(importe,
                'El importe tiene que ser un número en dólares, y no puede ser negativo.');

            return;
        }

        if (motivo.value.trim() === '') {
            Notificacion.campoInvalido(motivo,
                'Hace falta el motivo: es lo único que después explica por qué ese mes no ' +
                'muestra la estimación automática.');

            return;
        }

        enviar('guardarAjuste', {
            mes: mesAjuste,
            importe_usd: Number(importe.value),
            motivo: motivo.value.trim()
        }, 'Ajuste guardado. El mes muestra el importe cargado y la nacionalización se ' +
           'recalculó sobre él.');
    }

    function quitarAjuste() {
        if (!mesAjuste) { return; }

        Notificacion.confirmar({
            titulo: 'Sacar el ajuste de ' + mesAjuste,
            mensaje: '¿Volver a la estimación automática?',
            detalle: 'El ajuste no se borra: queda en el historial con su fecha de baja.',
            confirmar: 'Sacar el ajuste',
            peligro: true
        }).then(function(ok) {
            if (!ok) { return; }

            enviar('quitarAjuste', { mes: mesAjuste },
                'El mes vuelve a la estimación automática.');
        });
    }

    function enviar(accion, cuerpo, exito) {
        fetch(ENDPOINT + '?action=' + accion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    throw new Error((d && d.message) || 'Respuesta inesperada del servidor');
                }

                var modalEl = document.getElementById('modalAjuste');

                if (modalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }

                Notificacion.exito(d.message || exito);

                /* SE RECARGA TODO y no se parchea la fila: el ajuste cambia la
                   estimación, la nacionalización, los KPIs y los totales del
                   eje. Parchear la celda dejaría el resto del cuadro diciendo
                   lo de antes. */
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar: ' + e.message);
            });
    }

    /* ================================================================
       BUSCADOR
       ================================================================ */

    function filtrar() {
        var input = document.getElementById('busquedaComprasProy');
        var body = document.getElementById('cpTableBody');

        if (!input || !body) { return; }

        var q = input.value.trim().toLowerCase();

        body.querySelectorAll('tr').forEach(function(tr) {
            var texto = tr.getAttribute('data-busca') || '';

            tr.style.display = (q === '' || texto.indexOf(q) !== -1) ? '' : 'none';
        });
    }

    /* ================================================================
       ARRANQUE
       ================================================================ */

    function iniciar() {
        var btn = document.getElementById('btnRefreshComprasProy');

        if (btn) { btn.addEventListener('click', cargar); }

        var btnInsumos = document.getElementById('btnActualizarInsumos');

        if (btnInsumos) { btnInsumos.addEventListener('click', actualizarAhora); }

        var input = document.getElementById('busquedaComprasProy');

        if (input) { input.addEventListener('input', filtrar); }

        var btnGuardar = document.getElementById('cpAjusteGuardar');
        var btnQuitar = document.getElementById('cpAjusteQuitar');

        if (btnGuardar) { btnGuardar.addEventListener('click', guardarAjuste); }
        if (btnQuitar) { btnQuitar.addEventListener('click', quitarAjuste); }

        if (typeof crearColumnasFijas === 'function') {
            colFijas = crearColumnasFijas({
                tabla: 'tablaComprasProy',
                control: 'colFijasComprasProy',
                clave: 'compras_proyectadas',
                /* Mes y temporada: son las dos que contestan "de qué fila
                   estoy leyendo el importe" cuando se scrollea a lo ancho. */
                porDefecto: [0, 1]
            });
        }

        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
