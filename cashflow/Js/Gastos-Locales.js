/**
 * Gastos Locales
 *
 * Una fila por local propio con su gasto diario y su proyección en el eje, y
 * al abrirla el detalle por cuenta de los 90 días. SÓLO LEE: el día de pago y
 * las cuentas excluidas se cambian en Parámetros › Generales.
 *
 * LAS COLUMNAS Y LAS TRES VISTAS NO SE CALCULAN ACÁ. El backend arma el
 * payload con EjeVista::armarAgrupado() y Js/eje-vistas.js resuelve qué
 * columnas se ven. Cada celda trae su explicación ya redactada por el backend
 * (GastosLocalesCalculo::explicar()): de cuántos días sale y con qué
 * inflación.
 *
 * LA TABLA SE DIBUJA SOBRE LA LISTA DE LOCALES, NO SOBRE EL EJE: un local sin
 * egresos en 90 días no tiene ninguna fila en el eje, y tiene que verse con su
 * cero y la leyenda.
 *
 * NADA DE alert(). Todo va a Notificacion y al panel de avisos.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/GastosLocalesController.php';

    var datos = null;
    var vistas = null;
    var filtro = '';
    var abiertos = {};

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('glSpinner');
        mostrar('glTableWrapper', false);
        mostrar('summaryGastosLocales', false);

        // Lo que el tablero dice de esta pestaña, en paralelo. Ver Js/avisos-panel.js.
        AvisosPanel.pedirTablero('gastos_locales');

        pedir(ENDPOINT + '?action=getPlanilla')
            .then(function(d) {
                datos = d.data;

                avisar(datos.avisos || [], true);
                vistas.usar(datos.eje);
                pintarCrono();
                pintarResumen();
                dibujar();

                Cargando.ocultar('glSpinner');
                mostrar('glTableWrapper', true);
                mostrar('summaryGastosLocales', true);
            })
            .catch(function(e) {
                Cargando.ocultar('glSpinner');
                avisar([{ nivel: 'danger', texto: 'No se pudieron calcular los gastos de los locales: ' + e.message }]);
            });
    }

    /* ================================================================
       LA GRILLA
       ================================================================ */

    function dibujar() {
        if (!datos) { return; }

        var columnas = vistas.columnas();
        var filas = filtrar(datos.locales || []);

        pintarEncabezado(columnas);
        pintarFilas(filas, columnas);
        pintarPie(filas, columnas);
        pintarResumen();
    }

    function filtrar(locales) {
        if (filtro === '') { return locales; }

        var t = filtro.toLowerCase();

        return locales.filter(function(l) {
            return String(l.nombre || '').toLowerCase().indexOf(t) !== -1
                || String(l.nro).indexOf(t) !== -1;
        });
    }

    function pintarEncabezado(columnas) {
        var thead = document.getElementById('glThead');

        if (!thead) { return; }

        /* Dos filas, como Logística: arriba sólo las descriptivas con rowspan
           (lo que Js/columnas-fijas.js ofrece fijar) y el lugar de los totales
           de cada columna, que pinta Js/eje-totales.js. */
        thead.innerHTML = '<tr>' +
            '<th rowspan="2" class="col-texto">Local</th>' +
            '<th rowspan="2" class="text-end" style="width: 130px;">Total 90 días</th>' +
            '<th rowspan="2" class="text-end" style="width: 120px;">Gasto diario</th>' +
        '</tr><tr>' +
            columnas.map(function(c) {
                var marca = vistas.marca(c);

                return '<th class="text-end' + (marca.clase ? ' ' + marca.clase : '') + '"'
                    + (marca.titulo ? ' title="' + esc(marca.titulo) + '"' : '') + '>'
                    + esc(vistas.rotulo(c)) + '</th>';
            }).join('') +
            '<th class="text-end fw-bold total-column">Total</th>' +
        '</tr>';
    }

    function pintarFilas(filas, columnas) {
        var cuerpo = document.getElementById('glTableBody');

        if (!cuerpo) { return; }

        if (!filas.length) {
            cuerpo.innerHTML = '<tr><td colspan="' + (columnas.length + 4) +
                '" class="text-center text-muted py-4">' +
                ((datos.locales || []).length ? 'Ningún local coincide con la búsqueda.'
                    : (datos.leido ? 'No hay locales propios habilitados.'
                        : 'No se pudieron leer los gastos: ver los avisos de arriba.')) +
                '</td></tr>';

            return;
        }

        cuerpo.innerHTML = filas.map(function(l) {
            var eje = filaDelEje(l.nro);
            var abierto = !!abiertos[l.nro];

            return '<tr data-nro="' + l.nro + '">' +
                '<td>' +
                    '<span class="gl-toggle text-muted" data-toggle="' + l.nro + '" ' +
                        'title="Ver el detalle por cuenta">' +
                        '<i class="fas fa-chevron-' + (abierto ? 'down' : 'right') + ' small"></i>' +
                    '</span> ' +
                    '<span class="fw-semibold">' + esc(l.nombre || ('Sucursal ' + l.nro)) + '</span>' +
                    ' <small class="text-muted">(' + l.nro + ')</small>' +
                    (l.sin_egresos
                        ? ' <span class="badge bg-light text-muted gl-sin-egresos">' +
                          esc(datos.sin_egresos) + '</span>'
                        : '') +
                '</td>' +
                '<td class="text-end">' + importe(l.total) + '</td>' +
                '<td class="text-end">' + importe(l.diario) + '</td>' +
                columnas.map(function(c) {
                    return '<td class="text-end ' + vistas.marca(c).clase + '"' +
                        tooltip(l.nro, c) + '>' + celdaEje(l, eje, c) + '</td>';
                }).join('') +
                '<td class="text-end fw-bold">' +
                    (eje ? importe(vistas.total(eje)) : (l.sin_egresos ? importe(0) : '—')) +
                '</td>' +
            '</tr>' +
            filasDetalle(l, columnas.length, abierto);
        }).join('');

        cuerpo.querySelectorAll('[data-toggle]').forEach(function(t) {
            t.addEventListener('click', function() {
                var nro = t.getAttribute('data-toggle');

                abiertos[nro] = !abiertos[nro];
                dibujar();
            });
        });

        cuerpo.querySelectorAll('[data-comprobantes]').forEach(function(b) {
            b.addEventListener('click', function(ev) {
                ev.preventDefault();
                abrirComprobantes(Number(b.getAttribute('data-comprobantes')));
            });
        });
    }

    /**
     * El detalle por cuenta, debajo del local. Va con display:none cuando está
     * cerrado, y no se omite: así Exportar baja lo que se ve (Js/tabla-export.js
     * se saltea lo oculto) y abrir no vuelve a pedir nada.
     */
    function filasDetalle(l, nCols, abierto) {
        var estilo = abierto ? '' : ' style="display: none;"';
        var cuentas = l.cuentas || [];

        if (!cuentas.length) {
            return '<tr class="gl-detalle"' + estilo + '><td class="gl-cuenta text-muted" colspan="' +
                (nCols + 4) + '">Sin egresos de caja en cuentas 5 en los últimos 90 días.</td></tr>';
        }

        return cuentas.map(function(c) {
            return '<tr class="gl-detalle"' + estilo + '>' +
                '<td class="gl-cuenta">' + esc(c.cod_cta) + ' — ' + esc(c.descripcion) +
                    ' <small class="text-muted">(' + c.movimientos + ' mov.)</small></td>' +
                '<td class="text-end">' + importe(c.total) + '</td>' +
                '<td class="text-end">' + importe(c.diario) + '</td>' +
                '<td colspan="' + (nCols + 1) + '"></td>' +
            '</tr>';
        }).join('') +
        '<tr class="gl-detalle"' + estilo + '><td class="gl-cuenta" colspan="' + (nCols + 4) + '">' +
            '<a href="#" data-comprobantes="' + l.nro + '">' +
            '<i class="fas fa-list me-1"></i>Ver los comprobantes de los 90 días</a></td></tr>';
    }

    /**
     * Una celda del eje. Un guión con su motivo si la inflación del mes falta;
     * el importe si se calculó, aunque sea cero (un local sin egresos).
     */
    function celdaEje(l, eje, columna) {
        var tips = ((datos.tooltips || {})[l.nro] || {})[columna];

        if (!eje) {
            return (l.sin_egresos && tips) ? importe(0) : '<span class="text-muted">—</span>';
        }

        var v = vistas.valor(eje, columna);

        if (v === null || v === undefined || (v === 0 && !tips)) {
            return '<span class="text-muted">—</span>';
        }

        return importe(v);
    }

    function tooltip(nro, columna) {
        var tips = ((datos.tooltips || {})[nro] || {})[columna];

        return tips && tips.length ? ' title="' + esc(tips.join(' · ')) + '"' : '';
    }

    function pintarPie(filas, columnas) {
        var pie = document.getElementById('glTfoot');

        if (!pie) { return; }

        /* EL PIE SUMA LO QUE SE VE. Sin búsqueda, es exactamente la fila Gastos
           Locales del tablero: las dos son la suma de los mismos locales. */
        var ejes = filas.map(function(l) { return filaDelEje(l.nro); })
                        .filter(function(e) { return !!e; });

        var valores = columnas.map(function(c) {
            var suma = 0;

            ejes.forEach(function(e) { suma += Number(vistas.valor(e, c)) || 0; });

            return suma;
        });

        var total = 0;
        var total90 = 0;
        var diario = 0;

        ejes.forEach(function(e) { total += Number(vistas.total(e)) || 0; });
        filas.forEach(function(l) { total90 += Number(l.total) || 0; diario += Number(l.diario) || 0; });

        pie.innerHTML = '<tr>' +
            '<td class="fw-bold text-end">TOTALES</td>' +
            '<td class="text-end fw-bold">' + importe(total90) + '</td>' +
            '<td class="text-end fw-bold">' + importe(diario) + '</td>' +
            valores.map(function(suma, i) {
                return '<td class="text-end fw-bold ' + vistas.marca(columnas[i]).clase + '">'
                    + celdaSuma(suma) + '</td>';
            }).join('') +
            '<td class="text-end fw-bold">' + (total === 0 ? '—' : importe(total)) + '</td>' +
        '</tr>';

        pintarEjeTotales('tablaGastosLocales', {
            columnas: columnas,
            valores: valores,
            total: total,
            formato: celdaSuma,
            formatoTotal: function(t) { return t === 0 ? '—' : importe(t); }
        });
    }

    function celdaSuma(suma) {
        return suma === 0 ? '<span class="text-muted">—</span>' : importe(suma);
    }

    function filaDelEje(nro) {
        var filas = (datos.eje && datos.eje.filas) || [];

        for (var i = 0; i < filas.length; i++) {
            if (String(filas[i].nro) === String(nro)) { return filas[i]; }
        }

        return null;
    }

    /* ================================================================
       LOS COMPROBANTES
       ================================================================ */

    function abrirComprobantes(nro) {
        var l = null;

        (datos.locales || []).forEach(function(x) { if (x.nro === nro) { l = x; } });

        texto('glCompTitulo', l ? (l.nombre + ' (' + nro + ')') : String(nro));
        texto('glCompPie', '');

        var cuerpo = document.getElementById('glCompBody');

        if (cuerpo) {
            cuerpo.innerHTML = '<tr><td colspan="6" class="text-muted">Leyendo…</td></tr>';
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalComprobantesLocal')).show();

        pedir(ENDPOINT + '?action=getComprobantes&nro=' + encodeURIComponent(nro))
            .then(function(d) {
                var lista = d.data || [];
                var suma = 0;

                if (!cuerpo) { return; }

                cuerpo.innerHTML = lista.length ? lista.map(function(c) {
                    suma += Number(c.monto) || 0;

                    return '<tr>' +
                        '<td>' + esc(fecha(c.fecha)) + '</td>' +
                        '<td>' + esc(c.cod_comp + ' ' + c.n_comp) + '</td>' +
                        '<td>' + esc(c.cod_cta) + ' <small class="text-muted">' + esc(c.cuenta) + '</small></td>' +
                        '<td>' + esc(c.leyenda) + '</td>' +
                        '<td><small>' + esc(c.usuario) + '</small></td>' +
                        '<td class="text-end">' + importe(c.monto) + '</td>' +
                    '</tr>';
                }).join('') : '<tr><td colspan="6" class="text-center text-muted py-3">' +
                    'Sin egresos de caja en cuentas 5 en los últimos 90 días.</td></tr>';

                texto('glCompPie', lista.length + ' comprobante(s) · total ' + importeTexto(suma));
            })
            .catch(function(e) {
                if (cuerpo) {
                    cuerpo.innerHTML = '<tr><td colspan="6" class="text-danger">' +
                        'No se pudieron leer los comprobantes: ' + esc(e.message) + '</td></tr>';
                }
            });
    }

    /* ================================================================
       RESUMEN Y CRONOGRAMA
       ================================================================ */

    function pintarResumen() {
        if (!datos || !datos.resumen) { return; }

        var r = datos.resumen;
        var v = datos.ventana || {};

        texto('glTotalVentana', datos.leido ? importeTexto(r.total_ventana) : '—');
        texto('glVentana', v.desde
            ? 'Del ' + fecha(v.desde) + ' al ' + fecha(v.hasta) + ' · ' + r.locales + ' locales' +
              (r.sin_egresos ? ' (' + r.sin_egresos + ' sin egresos)' : '')
            : 'Egresos de caja, cuentas 5');
        texto('glMensual', datos.leido ? importeTexto(r.mensual) : '—');
        texto('glDiario', 'Gasto diario ' + (datos.leido ? importeTexto(r.diario) : '—') + ' × 30');

        /* LO PROYECTADO MIDE LA VISTA ACTIVA, como el pie: el total del
           horizonte no es lo que muestra la vista Días. */
        var total = 0;

        ((datos.eje && datos.eje.filas) || []).forEach(function(e) {
            total += Number(vistas.total(e)) || 0;
        });

        texto('glProyectado', datos.leido ? importeTexto(total) : '—');
        texto('glProyectadoPie', 'Con inflación · ' + vistas.periodo());
    }

    function pintarCrono() {
        var el = document.getElementById('glCronoTexto');

        if (!el) { return; }

        var regla = (datos.cronograma_config && datos.cronograma_config.descripcion) || '';
        var semanas = datos.semanas || [];

        el.innerHTML = 'Se paga <strong>' + esc(regla || 'según el cronograma') + '</strong>' +
            (semanas.length ? ': ' + semanas.length + ' semana(s) en el tramo diario' : '') +
            '. Cuentas excluidas: <strong>' + esc(datos.cuentas_excluidas || 'ninguna') + '</strong>.' +
            ' El día y las cuentas se cambian en Parámetros › Generales.';
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function avisar(lista, conTablero) {
        if (conTablero) {
            AvisosPanel.pintarPestana('avisosGastosLocales', lista || [], { tab: 'gastos_locales' });
        } else {
            AvisosPanel.pintar('avisosGastosLocales', lista || []);
        }
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

    function pedir(url) {
        return fetch(url)
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
            botones: 'vistasGastosLocales',
            periodo: 'periodoGastosLocales',
            alCambiar: dibujar
        });

        var btn = document.getElementById('btnRefreshGastosLocales');

        if (btn) { btn.addEventListener('click', cargar); }

        var buscar = document.getElementById('busquedaGastosLocales');

        if (buscar) {
            buscar.addEventListener('input', function() {
                filtro = buscar.value.trim();
                dibujar();
            });
        }

        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({
                tabla: 'tablaGastosLocales',
                control: 'colFijasGastosLocales',
                clave: 'gastos_locales',
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
