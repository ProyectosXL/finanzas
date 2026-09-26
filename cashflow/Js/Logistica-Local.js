/**
 * Logistica Local
 *
 * Una fila por fletero con sus pagos proyectados, sobre el mismo eje temporal
 * que el tablero. Todo es proyeccion: no hay parte real.
 *
 * LAS COLUMNAS Y LAS TRES VISTAS NO SE CALCULAN ACA
 * --------------------------------------------------
 * El backend arma el payload con EjeVista::armarAgrupado() y Js/eje-vistas.js
 * dibuja los botones, resuelve que columnas se ven y que total corresponde a
 * cada vista. Este archivo solo dibuja filas. Sin eso, la pestana tendria su
 * propia version de "que columnas van en cada vista", que es exactamente lo que
 * el modulo dejo de tener escrito cuatro veces.
 *
 * POR QUE HAY DOS LISTAS Y NO UNA
 * --------------------------------
 * El payload del eje trae SOLO lo que se proyecta: un fletero sin horas no
 * tiene ninguna fila ahi. La planilla completa viaja aparte y es la que dice
 * quienes son todos y que le falta a cada uno. La tabla se dibuja sobre la
 * planilla -asi el que no proyecta se VE, con su motivo- y los importes salen
 * del eje. Dibujar solo el eje esconderia justo a los que hay que arreglar.
 *
 * EL VALOR HORA NO SE EDITA
 * --------------------------
 * Lo editable es el valor BASE, las horas y el mes base. El valor hora de cada
 * mes sale siempre de la formula, y su tooltip dice de que ajuste viene y que
 * meses de inflacion se sumaron. Ese texto lo arma el backend, porque es la
 * explicacion de una cuenta que hace el backend.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/LogisticaController.php';

    /** Los dos pagos del mes */
    var NOMBRE_PAGO = { 1: '2do viernes', 2: '4to viernes' };

    /* POR QUÉ NO HAY UNA LISTA DE MOTIVOS ACÁ. Cada celda trae su propio
       'tooltip' ya redactado por el backend, porque es la explicación de una
       cuenta que hace el backend: de qué ajuste sale el valor, qué meses de
       inflación se sumaron, qué mes falta cargar. Una tabla de textos en el
       front tendría que repetir esa lógica para decir lo mismo, y se
       desactualizaría en el primer cambio de la fórmula. */

    var datos = null;
    var vistas = null;
    var filtro = '';

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('logSpinner');
        mostrar('logTableWrapper', false);
        mostrar('summaryLogistica', false);

        pedir(ENDPOINT + '?action=getPlanilla')
            .then(function(d) {
                datos = d.data;

                avisar(datos.avisos || []);
                vistas.usar(datos.eje);
                pintarCrono();
                pintarResumen();
                dibujar();

                Cargando.ocultar('logSpinner');
                mostrar('logTableWrapper', true);
                mostrar('summaryLogistica', true);
            })
            .catch(function(e) {
                Cargando.ocultar('logSpinner');
                avisar(['No se pudo calcular la proyección de logística: ' + e.message]);
            });
    }

    /* ================================================================
       LA PLANILLA
       ================================================================ */

    function dibujar() {
        if (!datos) { return; }

        var columnas = vistas.columnas();
        var filas = filtrar(datos.planilla.fleteros);

        pintarEncabezado(columnas);
        pintarFilas(filas, columnas);
        pintarPie(filas, columnas);
        pintarValorHora(filas);
    }

    function filtrar(fleteros) {
        if (filtro === '') { return fleteros; }

        var t = filtro.toLowerCase();

        return fleteros.filter(function(f) {
            return String(f.nombre || '').toLowerCase().indexOf(t) !== -1
                || String(f.cod_provee || '').toLowerCase().indexOf(t) !== -1;
        });
    }

    function pintarEncabezado(columnas) {
        var thead = document.getElementById('logThead');

        if (!thead) { return; }

        /* EL ENCABEZADO VA EN DOS FILAS, y no es decoración: Js/columnas-fijas.js
           deriva qué columnas son "descriptivas" —o sea, elegibles para quedar
           fijas al scrollear— de las celdas con rowspan que están ANTES del
           grupo temporal. Con un thead de una sola fila, el desplegable
           ofrecería también las doce columnas de meses. */
        thead.innerHTML = '<tr>' +
            '<th rowspan="2" class="col-texto">Fletero</th>' +
            '<th rowspan="2" class="text-end" style="width: 110px;">Horas/mes</th>' +
            '<th rowspan="2" class="text-end" style="width: 140px;">Valor hora base</th>' +
            '<th rowspan="2" class="text-center" style="width: 120px;">Mes base</th>' +
            '<th colspan="' + columnas.length + '" class="table-group-divider text-center">' +
                esc(vistas.periodo() || 'Proyección') +
            '</th>' +
            '<th rowspan="2" class="text-end fw-bold">Total</th>' +
        '</tr><tr>' +
            columnas.map(function(c) {
                return '<th class="text-end">' + esc(vistas.rotulo(c)) + '</th>';
            }).join('') +
        '</tr>';
    }

    function pintarFilas(filas, columnas) {
        var cuerpo = document.getElementById('logTableBody');

        if (!cuerpo) { return; }

        if (!filas.length) {
            cuerpo.innerHTML = '<tr><td colspan="' + (columnas.length + 5) +
                '" class="text-center text-muted py-4">' +
                (datos.planilla.fleteros.length
                    ? 'Ningún fletero coincide con la búsqueda.'
                    : 'No hay ningún fletero activo cargado. Se dan de alta en ' +
                      'Parámetros › Logística.') +
                '</td></tr>';

            return;
        }

        cuerpo.innerHTML = filas.map(function(f) {
            var eje = filaDelEje(f.cod_provee);

            return '<tr data-cod="' + esc(f.cod_provee) + '">' +
                '<td>' +
                    '<a href="#" class="fw-semibold text-decoration-none" data-detalle="' +
                        esc(f.cod_provee) + '">' + esc(f.nombre || f.cod_provee) + '</a>' +
                    '<div><small class="text-muted">' + esc(f.cod_provee) + '</small></div>' +
                '</td>' +
                celdaNum(f.cod_provee, 'horas', f.horas_mes, '0.5', 'Horas por mes') +
                celdaNum(f.cod_provee, 'valor_hora', f.valor_hora_base, '0.01',
                         'Valor hora base, ya ajustado') +
                celdaMes(f.cod_provee, f.mes_base) +
                columnas.map(function(c) {
                    return '<td class="text-end">' + celdaEje(f, eje, c) + '</td>';
                }).join('') +
                '<td class="text-end fw-bold">' +
                    (eje ? importe(vistas.total(eje)) : '—') +
                '</td>' +
            '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.log-edit').forEach(function(input) {
            input.addEventListener('change', function() { guardarCampo(input); });
        });

        cuerpo.querySelectorAll('[data-detalle]').forEach(function(a) {
            a.addEventListener('click', function(ev) {
                ev.preventDefault();
                abrirDetalle(a.getAttribute('data-detalle'));
            });
        });
    }

    /**
     * Una celda del eje.
     *
     * UNA CELDA VACIA Y UN CERO NO SE DIBUJAN IGUAL. Si el mes no se pudo
     * calcular va un guion con el motivo en el tooltip; si se calculo y da cero,
     * va el cero. Es la misma distincion que el backend sostiene con null, y
     * perderla acá la anularía.
     */
    function celdaEje(fletero, eje, columna) {
        var mes = columna.indexOf('MES|') === 0
            ? columna.substring(4) : columna.substring(4, 11);
        var celda = fletero.meses[mes];

        if (celda && celda.motivo) {
            return '<span class="text-muted" title="' + esc(celda.tooltip) + '">—</span>';
        }

        if (!eje) { return '<span class="text-muted">—</span>'; }

        var v = vistas.valor(eje, columna);

        return (v === 0 || v === null || v === undefined)
            ? '<span class="text-muted">—</span>' : importe(v);
    }

    function celdaNum(cod, campo, valor, paso, titulo) {
        return '<td class="text-end">' +
            '<input type="number" step="' + paso + '" min="0" ' +
                'class="form-control form-control-sm text-end log-edit" ' +
                'data-cod="' + esc(cod) + '" data-campo="' + campo + '" ' +
                'title="' + esc(titulo) + '" ' +
                'value="' + (valor === null ? '' : esc(valor)) + '">' +
        '</td>';
    }

    function celdaMes(cod, mesBase) {
        return '<td class="text-center">' +
            '<input type="month" class="form-control form-control-sm log-edit" ' +
                'data-cod="' + esc(cod) + '" data-campo="mes_base" ' +
                'title="Desde qué mes rige el valor hora base. Fija el calendario de los ' +
                       'ajustes: mes base + 3, + 6, + 9…" ' +
                'value="' + (mesBase === null ? '' : esc(mesBase)) + '">' +
        '</td>';
    }

    function pintarPie(filas, columnas) {
        var pie = document.getElementById('logTfoot');

        if (!pie) { return; }

        /* EL PIE SUMA LO QUE SE VE, no el total general: con la búsqueda activa
           un total que incluyera a los filtrados no cerraría con las filas de
           arriba. */
        var ejes = filas.map(function(f) { return filaDelEje(f.cod_provee); })
                        .filter(function(e) { return !!e; });

        var total = 0;

        ejes.forEach(function(e) { total += Number(vistas.total(e)) || 0; });

        pie.innerHTML = '<tr>' +
            '<td colspan="4" class="fw-bold text-end">TOTALES</td>' +
            columnas.map(function(c) {
                var suma = 0;

                ejes.forEach(function(e) { suma += Number(vistas.valor(e, c)) || 0; });

                return '<td class="text-end fw-bold">' +
                    (suma === 0 ? '<span class="text-muted">—</span>' : importe(suma)) + '</td>';
            }).join('') +
            '<td class="text-end fw-bold">' + (total === 0 ? '—' : importe(total)) + '</td>' +
        '</tr>';
    }

    function filaDelEje(cod) {
        var filas = (datos.eje && datos.eje.filas) || [];

        for (var i = 0; i < filas.length; i++) {
            if (filas[i].cod_provee === cod) { return filas[i]; }
        }

        return null;
    }

    /* ================================================================
       EL VALOR HORA MES A MES
       ================================================================ */

    function pintarValorHora(filas) {
        var thead = document.getElementById('logVhThead');
        var cuerpo = document.getElementById('logVhBody');

        if (!thead || !cuerpo) { return; }

        var meses = datos.planilla.meses || [];

        thead.innerHTML = '<tr><th>Fletero</th>' +
            meses.map(function(m) {
                return '<th class="text-end">' + esc(rotuloMes(m)) + '</th>';
            }).join('') + '</tr>';

        cuerpo.innerHTML = filas.length
            ? filas.map(function(f) {
                return '<tr>' +
                    '<td class="fw-semibold">' + esc(f.nombre || f.cod_provee) + '</td>' +
                    meses.map(function(m) {
                        var c = f.meses[m];

                        if (!c || c.valor_hora === null) {
                            return '<td class="text-end text-muted" title="' +
                                esc(c ? c.tooltip : '') + '">—</td>';
                        }

                        /* EL MES DE AJUSTE SE MARCA. Sin la marca, una columna
                           con otro número se lee como un error de carga; con
                           ella se lee como lo que es. */
                        return '<td class="text-end' + (c.ajusta ? ' log-ajuste' : '') + '" ' +
                            'title="' + esc(c.tooltip) + '">' +
                            importe(c.valor_hora) +
                            (c.ajusta ? ' <i class="fas fa-arrow-up small"></i>' : '') +
                        '</td>';
                    }).join('') +
                '</tr>';
            }).join('')
            : '<tr><td colspan="' + (meses.length + 1) +
              '" class="text-center text-muted py-3">Sin fleteros.</td></tr>';
    }

    /* ================================================================
       EL DETALLE DE UN FLETERO
       ================================================================ */

    function abrirDetalle(cod) {
        var f = null;

        datos.planilla.fleteros.forEach(function(x) {
            if (x.cod_provee === cod) { f = x; }
        });

        if (!f) { return; }

        texto('logDetalleTitulo', (f.nombre || cod) + ' (' + cod + ')');

        var cuerpo = document.getElementById('logDetalleBody');

        if (cuerpo) {
            cuerpo.innerHTML = f.pagos.map(function(p) {
                var celda = f.meses[p.mes];

                var estado = p.excluido
                    ? '<span class="badge bg-secondary" title="' + esc(p.motivo_excluido) + '">' +
                      'no se proyecta</span>'
                    : (p.en_tramo
                        ? '<span class="badge bg-primary">columna del día</span>'
                        : '<span class="badge bg-info text-dark">columna del mes</span>');

                if (p.override) {
                    estado += ' <span class="badge bg-warning text-dark">fecha a mano</span>';
                } else if (p.corrida) {
                    estado += ' <span class="badge bg-light text-dark" ' +
                        'title="El viernes no era hábil: se corrió al día hábil anterior">' +
                        'corrida</span>';
                }

                return '<tr class="' + (p.excluido ? 'text-muted' : '') + '">' +
                    '<td>' + esc(rotuloMes(p.mes)) + '</td>' +
                    '<td><small>' + esc(NOMBRE_PAGO[p.nro] || p.nro) + '</small></td>' +
                    '<td>' + esc(fecha(p.fecha)) + '</td>' +
                    '<td class="text-end">' +
                        (celda && celda.valor_hora !== null ? importe(celda.valor_hora) : '—') +
                    '</td>' +
                    '<td class="text-end">' +
                        (p.importe === null ? '—' : importe(p.importe)) + '</td>' +
                    '<td>' + estado + '</td>' +
                '</tr>';
            }).join('');
        }

        texto('logDetallePie', f.total === null
            ? 'No se proyecta nada de este fletero.'
            : 'Total proyectado: ' + importeTexto(f.total));

        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('modalDetalleFletero')).show();
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardarCampo(input) {
        var cod = input.getAttribute('data-cod');
        var campo = input.getAttribute('data-campo');
        var f = null;

        datos.planilla.fleteros.forEach(function(x) {
            if (x.cod_provee === cod) { f = x; }
        });

        if (!f) { return; }

        if (campo !== 'mes_base' && input.value !== '') {
            var n = Number(input.value);

            if (isNaN(n) || n <= 0) {
                Notificacion.campoInvalido(input,
                    'Tiene que ser un número mayor a 0. Un cero significaría que no se le paga, '
                    + 'y eso se expresa dando de baja al fletero en Parámetros › Logística.');

                return;
            }
        }

        /* SE MANDAN LOS TRES CAMPOS, no sólo el que cambió: guardarFletero()
           escribe la fila entera, así que mandar uno solo borraría los otros
           dos. */
        var cuerpo = {
            cod_provee: cod,
            horas: campo === 'horas' ? input.value : f.horas_mes,
            valor_hora: campo === 'valor_hora' ? input.value : f.valor_hora_base,
            mes_base: campo === 'mes_base' ? input.value : f.mes_base
        };

        pedir(ENDPOINT + '?action=saveFletero', cuerpo)
            .then(function(d) {
                Notificacion.exito(d.message || 'Guardado.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar: ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                cargar();
            });
    }

    /* ================================================================
       RESUMEN Y CRONOGRAMA
       ================================================================ */

    function pintarResumen() {
        var fleteros = datos.planilla.fleteros || [];
        var total = datos.planilla.totales.total;

        texto('logTotal', total === null ? '—' : importeTexto(total));
        texto('logFleteros', String(fleteros.length));

        /* CUENTA LOS QUE NO PROYECTAN NINGUN MES, que es el numero que importa:
           un fletero al que le falta la inflacion de marzo proyecta igual hasta
           febrero, y contarlo acá exageraría el problema. */
        var sinProyectar = fleteros.filter(function(f) { return f.total === null; }).length;

        texto('logSinProyectar', String(sinProyectar));

        var pie = document.getElementById('logTotalPie');

        if (pie) {
            /* QUE PERIODO MIDE el total de la tarjeta. La vista Meses no
               cubre el horizonte completo -sus columnas son solo los dias
               de fuera del tramo diario- asi que un total sin decir de que
               periodo es no describe nada. */
            pie.textContent = 'Sin IVA ni otros conceptos · ' + vistas.periodo();
        }
    }

    function pintarCrono() {
        var el = document.getElementById('logCronoTexto');

        if (!el) { return; }

        var enTramo = (datos.cronograma || []).filter(function(p) { return p.en_tramo; });

        el.innerHTML = 'Se paga el <strong>2do y el 4to viernes</strong> de cada mes' +
            (enTramo.length
                ? '; dentro del tramo diario caen ' + enTramo.length + ' fecha(s).'
                : '.') +
            ' Las fechas se ven y se editan en Parámetros › Generales.';
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function avisar(lista) {
        var cont = document.getElementById('avisosLogistica');

        if (!cont) { return; }

        cont.innerHTML = (!lista || !lista.length) ? '' :
            '<div class="alert alert-warning py-2 px-3 mb-3 small">' +
                '<i class="fas fa-triangle-exclamation me-1"></i>' +
                lista.map(esc).join('<br>') +
            '</div>';
    }

    function importe(v) {
        if (v === null || v === undefined || isNaN(Number(v))) {
            return '<span class="text-muted">—</span>';
        }

        return importeTexto(v);
    }

    function importeTexto(v) {
        return '$ ' + Number(v).toLocaleString('es-AR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    /** '2026-09' -> 'Sep-26'. El mismo rótulo que usa el eje del tablero */
    function rotuloMes(mes) {
        var abrev = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                     'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var p = String(mes || '').split('-');

        if (p.length < 2) { return String(mes || ''); }

        return abrev[Number(p[1]) - 1] + '-' + p[0].substring(2);
    }

    /** '2026-12-24' -> '24/12/2026' */
    function fecha(f) {
        if (!f) { return '—'; }

        var p = String(f).substring(0, 10).split('-');

        return (p.length < 3) ? String(f) : p[2] + '/' + p[1] + '/' + p[0];
    }

    function texto(id, t) {
        var el = document.getElementById(id);

        if (el) { el.textContent = t; }
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) { el.style.display = visible ? '' : 'none'; }
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
                headers: { 'Content-Type': 'application/json' },
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
            botones: 'vistasLogistica',
            periodo: 'periodoLogistica',
            alCambiar: dibujar
        });

        var btn = document.getElementById('btnRefreshLogistica');

        if (btn) { btn.addEventListener('click', cargar); }

        var buscar = document.getElementById('busquedaLogistica');

        if (buscar) {
            buscar.addEventListener('input', function() {
                filtro = buscar.value.trim();
                dibujar();
            });
        }

        /* Las columnas fijas las engancha el componente compartido. Con doce
           columnas de meses a la derecha, al scrollear se pierde de vista DE
           QUIÉN es cada número: por eso la de Fletero arranca fijada. Las
           elegibles las deriva el componente del encabezado, así que agregar
           una columna descriptiva no obliga a tocar esto. */
        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({
                tabla: 'tablaLogistica',
                control: 'colFijasLogistica',
                clave: 'logistica_local',
                porDefecto: [0]
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
