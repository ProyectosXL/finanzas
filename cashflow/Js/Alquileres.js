/**
 * RRHH y Operativos -> Alquileres
 *
 * Tres sub-pestañas -Proyeccion, Ventas y Facturas- que salen de UN pedido
 * (getProyeccion): tienen que describir el mismo estado. Facturas se dibuja en
 * Js/Alquileres-Facturas.js, con lo que este archivo le pasa al cargar.
 *
 * EL NAVEGADOR NO CALCULA NADA. Cuanto se paga, cuando y de donde sale lo
 * resuelve el servidor (AlquileresPago, la misma regla que va a usar el
 * tablero); aca solo se dibuja, se abre y se cierra, y se arma el tooltip con
 * lo que vino. Los totales del pie y los de arriba del eje salen de UNA cuenta
 * sobre las filas visibles (Js/eje-totales.js).
 *
 * LOS GRUPOS Y CONCEPTOS VIAJAN PEGADOS A SU LOCAL. Llevan data-orden-sigue,
 * asi tabla-orden.js ordena por local sin separarlos.
 *
 * EXPORTAR ABRE TODO. tabla-export.js baja lo que se ve, y la planilla tiene
 * que tener todos los locales con sus grupos y conceptos: se abren, se exporta
 * y se vuelve a como estaba.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/AlquileresController.php';
    var datos = null;
    var abiertos = {};
    var filtro = '';
    var tipoVenta = 'neta';

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('alqSpinner');
        mostrar('alqWrapper', false);
        mostrar('kpisAlquileres', false);

        pedir(ENDPOINT + '?action=getProyeccion')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintar('avisosAlquileres', datos.avisos || []);
                pintarKpis();
                dibujar();
                dibujarVentas();

                if (window.AlquileresFacturasTab) { window.AlquileresFacturasTab.pintar(datos); }

                Cargando.ocultar('alqSpinner');
                mostrar('alqWrapper', true);
                mostrar('kpisAlquileres', true);
            })
            .catch(function(e) {
                Cargando.ocultar('alqSpinner');
                AvisosPanel.pintar('avisosAlquileres', [{ nivel: 'danger', texto: 'No se pudo proyectar los alquileres: ' + e.message }]);
            });
    }

    function pintarKpis() {
        var k = datos.kpis;

        html('alqKpiTotal', importe(k.total_horizonte));
        html('alqKpiMes', importe(k.total_mes_actual));
        texto('alqKpiMesPie', 'A pagar en ' + rotuloMes(datos.mes_actual));
        texto('alqKpiProyectan', String(k.locales_proyectan));
        texto('alqKpiIncompletos', String(k.locales_incompletos));
    }

    /* ================================================================
       PROYECCION
       ================================================================ */

    function dibujar() {
        if (!datos) { return; }

        var meses = datos.meses;
        var locales = datos.locales.filter(function(l) {
            return l.estado !== 'CERRADO_LAKERS' && coincide(l);
        });

        document.getElementById('alqThead').innerHTML = '<tr>'
            + '<th rowspan="2" class="col-texto">Local</th>'
            + '<th rowspan="2" class="col-texto">Razón social</th>'
            + '</tr><tr>'
            + meses.map(function(m) { return '<th class="text-end">' + esc(m.label) + '</th>'; }).join('')
            + '<th class="text-end fw-bold total-column">Total</th></tr>';

        var cuerpo = document.getElementById('alqBody');

        cuerpo.innerHTML = locales.length
            ? locales.map(function(l) { return filasLocal(l, meses); }).join('')
            : '<tr><td colspan="' + (meses.length + 3) + '" class="text-center text-muted py-4">'
              + (datos.locales.length ? 'Ningún local coincide con la búsqueda.' : 'No hay locales que mostrar: ver los avisos de arriba.')
              + '</td></tr>';

        cuerpo.querySelectorAll('.alq-toggle').forEach(function(t) {
            t.addEventListener('click', function() {
                var clave = t.getAttribute('data-clave');

                abiertos[clave] = !abiertos[clave];
                aplicarAbiertos();
            });
        });

        aplicarAbiertos();
        pintarPie(locales, meses);

        var cerrados = datos.locales.filter(function(l) { return l.estado === 'CERRADO_LAKERS'; });

        mostrar('alqCerradosCard', cerrados.length > 0);
        texto('alqCerrados', cerrados.map(function(l) { return l.nombre; }).join(', '));
    }

    function coincide(l) {
        if (filtro === '') { return true; }

        var t = filtro.toLowerCase();

        return String(l.nombre).toLowerCase().indexOf(t) !== -1 || String(l.cod_provee || '').toLowerCase().indexOf(t) !== -1
            || razonDe(l).toLowerCase().indexOf(t) !== -1;
    }

    function razonDe(l) {
        return l.razon || l.cod_provee || '';
    }

    function filasLocal(l, meses) {
        var clave = 'L' + l.nro;
        var estado = l.estado === 'PROYECTA'
            ? (l.faltan.length ? ' <span class="badge bg-warning text-dark" title="Falta: ' + esc(l.faltan.join(', ')) + '">parcial</span>' : '')
            : ' <span class="badge bg-warning text-dark" title="Falta: ' + esc(l.faltan.join(', ')) + '">' + esc(datos.estados[l.estado] || l.estado) + '</span>';
        var tieneGrupos = l.grupos.length > 0;

        var h = '<tr class="alq-local" data-clave="' + clave + '">'
            + '<td data-orden="' + esc(l.nombre) + '">'
                + (tieneGrupos ? '<span class="alq-toggle" data-clave="' + clave + '"><i class="fas fa-caret-right"></i></span> ' : '<span class="alq-toggle-vacio" style="display:inline-block;width:1rem"></span> ')
                + esc(l.nombre) + estado + '</td>'
            + '<td>' + esc(razonDe(l)) + '</td>'
            + celdas(l.meses, l.total, meses, 'a_pagar', null)
            + '</tr>';

        l.grupos.forEach(function(g) {
            var gClave = clave + '-' + g.grupo;

            h += '<tr class="alq-grupo" data-orden-sigue data-padre="' + clave + '" data-clave="' + gClave + '">'
                + '<td><span class="alq-toggle" data-clave="' + gClave + '"><i class="fas fa-caret-right"></i></span> '
                    + esc(g.nombre) + '</td><td></td>'
                + celdas(g.meses, g.total, meses, 'neto', null)
                + '</tr>';

            g.conceptos.forEach(function(k) {
                h += '<tr class="alq-concepto" data-orden-sigue data-padre="' + gClave + '" data-abuelo="' + clave + '">'
                    + '<td>' + esc(k.nombre) + '</td><td></td>'
                    + celdas(k.meses, k.total, meses, 'neto', g.grupo)
                    + '</tr>';
            });
        });

        return h;
    }

    function celdas(porMes, total, meses, campo, grupo) {
        return meses.map(function(m) {
            var c = porMes[m.clave];

            if (!c) { return '<td class="text-end text-muted">—</td>'; }

            return '<td class="text-end" data-orden="' + c[campo] + '" title="' + esc(tooltip(c, grupo)) + '">'
                + importe(c[campo]) + '</td>';
        }).join('')
            + '<td class="text-end fw-bold" title="' + esc(tooltip(total, null)) + '">' + importe(total[campo]) + '</td>';
    }

    /** El desglose de una celda, con lo que explica cada pago. */
    function tooltip(c, grupo) {
        var t = 'Neto ' + importeTexto(c.neto) + '\nIVA ' + importeTexto(c.iva)
            + '\nPercepción ' + importeTexto(c.percepcion) + '\nRetención ' + importeTexto(c.retencion)
            + '\nA pagar ' + importeTexto(c.a_pagar);

        (c.pagos || []).forEach(function(p) {
            var d = p.detalle || {};

            t += '\n\nPago: ' + fecha(p.fecha) + (p.nota_dia ? ' — ' + p.nota_dia : '')
                + '\nOrigen: ' + (datos.origenes[p.origen] || p.origen);

            /* UNA FACTURA IMPUTADA: cual, que renglon, y que parte le toca a
               este local. El importe es el de la factura, con IVA. */
            if (p.origen === 'FACTURA') {
                t += '\nFactura ' + d.factura + ' de ' + (d.razon || d.cod_provee)
                    + '\nImputación ' + d.imputacion + ', renglón ' + d.renglon + ' · cubre ' + rotuloMes(d.mes_cubre)
                    + '\nImporte con IVA y percepciones (la factura no se abre en impuestos)'
                    + '\nParte de este local: ' + numero(d.peso * 100, 1) + ' % '
                    + (d.reparto === 'IGUAL' ? '(partes iguales: no había nada proyectado)'
                        : '(en proporción a lo proyectado)');
            }

            if (grupo === 'VARIABLES' && d.venta !== undefined) {
                t += '\nVenta del mes ' + rotuloMes(p.mes_concepto) + ': ' + importeTexto(d.venta)
                    + (d.venta_origen ? ' (' + (d.venta_origen === 'REAL' ? 'real' : d.venta_origen === 'MANUAL' ? 'manual' : 'estimada') + ')' : '')
                    + ' × ' + numero(d.pct) + ' %';
            } else if (grupo === 'VARIABLES') {
                t += '\nVenta del mes ' + rotuloMes(p.mes_concepto) + ' (importe de la app)';
            }

            if (grupo === 'FIJOS' && d.ajusta) {
                t += '\nAjusta este mes: ' + numero(d.pct_inflacion) + ' % (' + (d.ventana || []).map(rotuloMes).join(' + ') + ', sumado)';
            }

            if (grupo === 'FONDO' && d.regla) {
                t += '\n' + (d.regla === 'PCT' ? 'VMA del mes × ' + numero(d.pct) + ' % (como la app)'
                    : d.regla === 'PROPORCION' ? 'Misma proporción sobre el VMA que en el último cierre'
                    : 'Actualizado como un fijo');
            }

            if (grupo === 'EXPENSAS' && d.tasa !== undefined) {
                t += '\nVariación mensual ' + numero(d.tasa * 100, 2) + ' % ('
                    + (d.tasa_origen === 'LOCAL' ? 'mediana de ' + d.meses_historia + ' meses del local'
                        : 'tasa general: el local tiene ' + d.meses_historia + ' meses de historia') + ')';
            }

            if (grupo === 'EXTRA' && d.base) {
                t += '\n' + numero(d.pct) + ' % sobre ' + Object.keys(d.base).join(' + ') + ' de '
                    + rotuloMes(p.mes_concepto) + ' (' + importeTexto(d.base_total) + ')';
            }
        });

        return t;
    }

    function aplicarAbiertos() {
        document.querySelectorAll('#alqBody tr[data-padre]').forEach(function(tr) {
            var padre = tr.getAttribute('data-padre');
            var abuelo = tr.getAttribute('data-abuelo');
            var visible = !!abiertos[padre] && (!abuelo || !!abiertos[abuelo]);

            tr.style.display = visible ? '' : 'none';
        });

        document.querySelectorAll('#alqBody .alq-toggle').forEach(function(t) {
            var i = t.querySelector('i');

            if (i) { i.className = abiertos[t.getAttribute('data-clave')] ? 'fas fa-caret-down' : 'fas fa-caret-right'; }
        });
    }

    function abrirTodo(abrir) {
        document.querySelectorAll('#alqBody .alq-toggle').forEach(function(t) {
            abiertos[t.getAttribute('data-clave')] = abrir;
        });
        aplicarAbiertos();
    }

    function hayAbiertos() {
        return Object.keys(abiertos).some(function(k) { return abiertos[k]; });
    }

    /** Una sola cuenta para el pie y para los totales de arriba del eje. */
    function pintarPie(locales, meses) {
        var valores = meses.map(function(m) {
            return locales.reduce(function(s, l) { return s + ((l.meses[m.clave] || {}).a_pagar || 0); }, 0);
        });
        var total = valores.reduce(function(s, v) { return s + v; }, 0);

        document.getElementById('alqTfoot').innerHTML = '<tr>'
            + '<td class="fw-bold text-end" colspan="2">A PAGAR</td>'
            + valores.map(function(v) { return '<td class="text-end fw-bold">' + celdaSuma(v) + '</td>'; }).join('')
            + '<td class="text-end fw-bold">' + celdaSuma(total) + '</td></tr>';

        pintarEjeTotales('tablaAlquileres', {
            columnas: meses, valores: valores, total: total, formato: celdaSuma
        });
    }

    function exportar() {
        var previo = JSON.parse(JSON.stringify(abiertos));

        abrirTodo(true);
        exportarTabla('tablaAlquileres', 'Alquileres_Proyeccion');
        abiertos = previo;
        aplicarAbiertos();
    }

    /* ================================================================
       VENTAS
       ================================================================ */

    function dibujarVentas() {
        if (!datos) { return; }

        var v = datos.ventas;
        var desde = v.horizonte_desde;
        var meses = v.meses;

        document.getElementById('alqVentasThead').innerHTML = '<tr>'
            + '<th rowspan="2" class="col-texto">Local</th>'
            + '<th rowspan="2" class="text-end" title="Participación en la venta neta real de los últimos 12 meses cerrados">Particip.</th>'
            + '</tr><tr>'
            + meses.map(function(m) {
                var antes = m < desde;

                return '<th class="text-end' + (antes ? ' alq-antes' : '') + '"'
                    + (antes ? ' title="Antes del horizonte: la pagan los variables diferidos"' : '') + '>'
                    + esc(rotuloMes(m)) + '</th>';
            }).join('') + '</tr>';

        document.getElementById('alqVentasBody').innerHTML = v.filas.map(function(f) {
            return '<tr' + (f.estado === 'CERRADO_LAKERS' ? ' class="text-muted"' : '') + '>'
                + '<td>' + esc(f.nombre)
                    + (f.estado === 'CERRADO_LAKERS' ? ' <small>(cerrado en Lakers)</small>' : '')
                    + (f.nuevo && f.estado !== 'CERRADO_LAKERS' ? ' <span class="badge bg-info text-dark">nuevo</span>' : '') + '</td>'
                + '<td class="text-end" data-orden="' + (f.participacion || 0) + '">'
                    + (f.participacion === null ? '—' : numero(f.participacion * 100, 2) + ' %') + '</td>'
                + meses.map(function(m) {
                    var c = f.meses[m];

                    if (!c) { return '<td class="text-end text-muted">—</td>'; }

                    return '<td class="text-end alq-celda-' + c.origen + '" data-orden="' + c[tipoVenta] + '" title="'
                        + esc(tooltipVenta(c, m)) + '">' + importe(c[tipoVenta]) + '</td>';
                }).join('')
                + '</tr>';
        }).join('');

        var pie = v.pie;

        document.getElementById('alqVentasTfoot').innerHTML =
            '<tr><td class="fw-bold text-end" colspan="2">Estimadas + manuales (bruta)</td>'
                + meses.map(function(m) { return '<td class="text-end fw-bold">' + celdaSuma(pie[m].estimada_manual) + '</td>'; }).join('') + '</tr>'
            + '<tr><td class="fw-bold text-end" colspan="2">Canal Locales (Ventas, mes completo)</td>'
                + meses.map(function(m) { return '<td class="text-end">' + (pie[m].canal === null ? '<span class="text-muted">—</span>' : importe(pie[m].canal)) + '</td>'; }).join('') + '</tr>'
            + '<tr><td class="fw-bold text-end" colspan="2">Diferencia</td>'
                + meses.map(function(m) {
                    var d = pie[m].diferencia;

                    return '<td class="text-end' + (d !== null && Math.abs(d) >= 0.01 ? ' text-danger fw-bold' : '') + '">'
                        + (d === null ? '<span class="text-muted">—</span>' : importe(d)) + '</td>';
                }).join('') + '</tr>';

        pintarEjeTotales('tablaAlqVentas', {
            columnas: meses,
            valores: meses.map(function(m) {
                return v.filas.reduce(function(s, f) { return s + ((f.meses[m] || {})[tipoVenta] || 0); }, 0);
            }),
            formato: celdaSuma
        });

        texto('alqVentasPeriodo', v.ventana.length
            ? ' Participación sobre ' + rotuloMes(v.ventana[0]) + ' a ' + rotuloMes(v.ventana[v.ventana.length - 1]) + '.'
            : '');
    }

    function tooltipVenta(c, mes) {
        var t = rotuloMes(mes) + ' · ' + (datos.ventas.origenes[c.origen] || c.origen)
            + '\nNeta ' + importeTexto(c.neta) + '\nBruta ' + importeTexto(c.bruta);

        if (c.derivada) {
            t += '\nLa app tiene una sola de las dos: la otra sale con el IVA general.';
        }

        if (c.origen === 'ESTIMADA') {
            t += '\nParticipación ' + numero(c.participacion * 100, 2) + ' % de la venta del canal Locales ('
                + importeTexto(c.canal) + ')';
        }

        return t;
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function irAParametros(filtroDestino) {
        var item = document.querySelector('.menu-link[data-tab="parametros"]');

        if (!item) { return; }

        window.cfSubTabDestino = 'ALQUILERES';
        window.cfAlquileresFiltro = filtroDestino || null;
        item.click();
    }

    var MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    function rotuloMes(m) {
        if (!m) { return ''; }

        return MESES[parseInt(String(m).substring(5, 7), 10) - 1] + '-' + String(m).substring(2, 4);
    }

    function celdaSuma(v) {
        return (!v || Math.abs(v) < 0.005) ? '<span class="text-muted">—</span>' : importe(v);
    }

    function importe(v) {
        if (v === null || v === undefined || isNaN(Number(v))) { return '<span class="text-muted">—</span>'; }

        return importeTexto(v);
    }

    function importeTexto(v) {
        return '$ ' + Number(v || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function numero(v, dec) {
        return Number(v || 0).toLocaleString('es-AR', { maximumFractionDigits: dec === undefined ? 2 : dec });
    }

    /** '2026-12-24' -> '24/12/2026' */
    function fecha(f) {
        if (!f) { return '—'; }

        var p = String(f).substring(0, 10).split('-');

        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function texto(id, t) {
        var el = document.getElementById(id);

        if (el) { el.textContent = t; }
    }

    function html(id, h) {
        var el = document.getElementById(id);

        if (el) { el.innerHTML = h; }
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
        enganchar('btnRefreshAlq', 'click', cargar);
        enganchar('btnExportarAlq', 'click', exportar);
        enganchar('btnExpandirAlq', 'click', function() { abrirTodo(!hayAbiertos()); });

        var buscar = document.getElementById('busquedaAlq');

        if (buscar) {
            buscar.addEventListener('input', function() {
                filtro = buscar.value.trim();
                dibujar();
            });
        }

        document.querySelectorAll('input[name="alqVentaTipo"]').forEach(function(r) {
            r.addEventListener('change', function() {
                tipoVenta = r.value;
                dibujarVentas();
            });
        });

        document.querySelectorAll('.alq-ir-parametros').forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                irAParametros(a.getAttribute('data-filtro'));
            });
        });

        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({ tabla: 'tablaAlquileres', control: 'colFijasAlq', clave: 'alquileres', porDefecto: [0, 1] });
            crearColumnasFijas({ tabla: 'tablaAlqVentas', control: 'colFijasAlqVentas', clave: 'alquileres_ventas', porDefecto: [0] });
        }

        cargar();
    }

    /* La sub-pestaña Facturas recarga todo despues de escribir: sus datos
       salen del mismo pedido que la Proyeccion. */
    window.AlquileresPestana = { recargar: cargar };

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
