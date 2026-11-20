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
 * LA GRILLA ES RAZON SOCIAL -> LOCAL -> GRUPO -> CONCEPTO. Un local con dos
 * razones sociales aparece bajo las dos, cada una con lo suyo (lo arma el
 * servidor: Alquileres::armarRazones()). Locales, grupos y conceptos llevan
 * data-orden-sigue, asi tabla-orden.js ordena por razon social sin separarlos,
 * y data-ancestros con las claves que tienen que estar abiertas para verse.
 *
 * EXPORTAR ABRE TODO. tabla-export.js baja lo que se ve, y la planilla tiene
 * que tener todas las razones con sus locales, grupos y conceptos: se abren,
 * se exporta y se vuelve a como estaba.
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
                /* Con los del tablero de su seccion: los mismos que el proveedor
                   ALQUILERES le dice al cuadro. */
                AvisosPanel.pintarPestana('avisosAlquileres', datos.avisos || [], { tab: 'alquileres' });
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

    /** Un local que no se muestra: cerrado en Lakers, o temporal terminado sin nada por pagar */
    function seMuestra(l) {
        return l.estado !== 'CERRADO_LAKERS' && !(l.estado === 'FINALIZADO' && !(l.total && l.total.a_pagar));
    }

    /**
     * Las razones sociales que se dibujan, con sus locales visibles. EL
     * BUSCADOR mira la razón social, su código y el nombre del local: si
     * coincide la razón, van todos sus locales; si coincide un local, sólo ese.
     */
    function razonesVisibles() {
        var t = filtro.toLowerCase();

        return (datos.razones || []).map(function(r) {
            var deLaRazon = t === '' || String(r.nombre).toLowerCase().indexOf(t) !== -1
                || String(r.cod || '').toLowerCase().indexOf(t) !== -1;
            var locales = r.locales.filter(function(l) {
                return seMuestra(l) && (deLaRazon || String(l.nombre).toLowerCase().indexOf(t) !== -1);
            });

            return { r: r, locales: locales };
        }).filter(function(x) { return x.locales.length > 0; });
    }

    function dibujar() {
        if (!datos) { return; }

        var meses = datos.meses;
        var razones = razonesVisibles();

        document.getElementById('alqThead').innerHTML = '<tr>'
            + '<th rowspan="2" class="col-texto">Razón social · local</th>'
            + '<th rowspan="2" class="col-texto">Código</th>'
            + '</tr><tr>'
            + meses.map(function(m) { return '<th class="text-end">' + esc(m.label) + '</th>'; }).join('')
            + '<th class="text-end fw-bold total-column">Total</th></tr>';

        var cuerpo = document.getElementById('alqBody');

        cuerpo.innerHTML = razones.length
            ? razones.map(function(x) { return filasRazon(x.r, x.locales, meses); }).join('')
            : '<tr><td colspan="' + (meses.length + 3) + '" class="text-center text-muted py-4">'
              + ((datos.razones || []).length ? 'Ninguna razón social ni local coincide con la búsqueda.'
                  : 'No hay locales que mostrar: ver los avisos de arriba.')
              + '</td></tr>';

        cuerpo.querySelectorAll('.alq-toggle').forEach(function(t) {
            t.addEventListener('click', function() {
                var clave = t.getAttribute('data-clave');

                abiertos[clave] = !abiertos[clave];
                aplicarAbiertos();
            });
        });

        aplicarAbiertos();
        pintarPie(razones, meses);
    }

    function toggle(clave) {
        return '<span class="alq-toggle" data-clave="' + clave + '"><i class="fas fa-caret-right"></i></span> ';
    }

    var SIN_TOGGLE = '<span class="alq-toggle-vacio" style="display:inline-block;width:1rem"></span> ';

    /**
     * La razón social y, colgando de ella, sus locales, sus grupos y sus
     * conceptos. Cada hijo lleva data-orden-sigue (tabla-orden.js lo mueve con
     * su razón) y en data-ancestros la lista de claves que tienen que estar
     * abiertas para que se vea.
     */
    function filasRazon(r, locales, meses) {
        var clave = 'R' + (r.cod || 'SIN');
        var h = '<tr class="alq-razon" data-clave="' + clave + '">'
            + '<td data-orden="' + esc(r.nombre) + '">' + toggle(clave) + '<strong>' + esc(r.nombre) + '</strong>'
                + (r.sin_razon ? ' <span class="badge bg-warning text-dark" title="Locales sin razón social dada de '
                    + 'alta: no proyectan. Se completan en Parámetros › Alquileres">sin razón social</span>' : '') + '</td>'
            + '<td>' + esc(r.cod || '') + '</td>'
            + celdas(r.meses, r.total, meses, 'a_pagar', null)
            + '</tr>';

        locales.forEach(function(l) { h += filasLocal(l, meses, clave); });

        return h;
    }

    function filasLocal(l, meses, padre) {
        var clave = padre + '-L' + l.nro;
        var estado = l.estado === 'PROYECTA'
            ? (l.faltan.length ? ' <span class="badge bg-warning text-dark" title="Falta: ' + esc(l.faltan.join(', ')) + '">parcial</span>' : '')
            : ' <span class="badge bg-warning text-dark" title="Falta: ' + esc(l.faltan.join(', ')) + '">' + esc(datos.estados[l.estado] || l.estado) + '</span>';

        var h = '<tr class="alq-local" data-orden-sigue data-ancestros="' + padre + '" data-clave="' + clave + '">'
            + '<td>' + (l.grupos.length ? toggle(clave) : SIN_TOGGLE) + esc(l.nombre) + estado + '</td>'
            + '<td>' + l.nro + '</td>'
            + celdas(l.meses, l.total, meses, 'a_pagar', null)
            + '</tr>';

        l.grupos.forEach(function(g) {
            var gClave = clave + '-' + g.grupo;

            h += '<tr class="alq-grupo" data-orden-sigue data-ancestros="' + padre + ' ' + clave + '" data-clave="' + gClave + '">'
                + '<td>' + toggle(gClave) + esc(g.nombre) + '</td><td></td>'
                + celdas(g.meses, g.total, meses, 'neto', null)
                + '</tr>';

            g.conceptos.forEach(function(k) {
                h += '<tr class="alq-concepto" data-orden-sigue data-ancestros="' + padre + ' ' + clave + ' ' + gClave + '">'
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
                    + (d.venta_origen ? ' (' + (d.venta_origen === 'REAL' ? 'real' : d.venta_origen === 'MANUAL' ? 'manual' : d.venta_origen === 'REAL_PROYECTADA' ? 'real hasta ayer + proyectada' : 'estimada') + ')' : '')
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

    /** Un hijo se ve si TODOS sus ancestros (razón, local, grupo) están abiertos. */
    function aplicarAbiertos() {
        document.querySelectorAll('#alqBody tr[data-ancestros]').forEach(function(tr) {
            var visible = tr.getAttribute('data-ancestros').split(' ').every(function(k) { return !!abiertos[k]; });

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
    /**
     * Sobre las razones sociales VISIBLES, con lo que tienen sus locales
     * visibles: con el buscador puesto, el pie suma lo que se ve. Sin
     * buscador, da el total de la pestaña (las razones suman la lista entera).
     */
    function pintarPie(razones, meses) {
        var valores = meses.map(function(m) {
            return razones.reduce(function(s, x) {
                return s + x.locales.reduce(function(t, l) { return t + ((l.meses[m.clave] || {}).a_pagar || 0); }, 0);
            }, 0);
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
            + '<th rowspan="2" class="text-end" title="Número de sucursal">Nro.</th>'
            + '<th rowspan="2" class="col-texto">Local</th>'
            + '<th rowspan="2" class="text-end" title="Participación en la venta neta real de los últimos 12 meses cerrados">Particip.</th>'
            + '</tr><tr>'
            + meses.map(function(m) {
                var antes = m < desde;

                return '<th class="text-end' + (antes ? ' alq-antes' : '') + '"'
                    + (antes ? ' title="Antes del horizonte: la pagan los variables diferidos"' : '') + '>'
                    + esc(rotuloMes(m)) + '</th>';
            }).join('') + '</tr>';

        /* ORDENADO POR NUMERO DE SUCURSAL por defecto; el encabezado lo cambia. */
        /* LOS CERRADOS EN LAKERS NO SE MUESTRAN: no pagan alquiler. */
        var filas = v.filas.filter(function(f) {
            return f.estado !== 'CERRADO_LAKERS' && !(f.estado === 'FINALIZADO'
                && !Object.keys(f.meses).some(function(m) { return f.meses[m]; }));
        })
            .sort(function(a, b) { return a.nro - b.nro; });

        document.getElementById('alqVentasBody').innerHTML = filas.map(function(f) {
            return '<tr' + (f.estado === 'CERRADO_LAKERS' ? ' class="text-muted"' : '') + '>'
                + '<td class="text-end" data-orden="' + f.nro + '">' + f.nro + '</td>'
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

        /* EL TOTAL DE LOS LOCALES, en todos los meses: real, estimada, manual o
           real + proyectada, neta o bruta según el selector. Las tres de abajo
           son el control del reparto contra el canal (bruta), que en los meses
           con venta real no tiene con qué comparar. */
        var total = meses.map(function(m) {
            return filas.reduce(function(s, f) { return s + ((f.meses[m] || {})[tipoVenta] || 0); }, 0);
        });

        document.getElementById('alqVentasTfoot').innerHTML =
            '<tr><td class="fw-bold text-end" colspan="3">Total locales (' + tipoVenta + ')</td>'
                + total.map(function(t) { return '<td class="text-end fw-bold">' + celdaSuma(t) + '</td>'; }).join('') + '</tr>'
            + '<tr class="text-muted"><td class="text-end" colspan="3">Estimadas + manuales, mes completo (bruta)</td>'
                + meses.map(function(m) { return '<td class="text-end">' + celdaSuma(pie[m].estimada_manual) + '</td>'; }).join('') + '</tr>'
            + '<tr class="text-muted"><td class="text-end" colspan="3">Canal Locales (Ventas, mes completo)</td>'
                + meses.map(function(m) { return '<td class="text-end">' + (pie[m].canal === null ? '<span class="text-muted">—</span>' : importe(pie[m].canal)) + '</td>'; }).join('') + '</tr>'
            + '<tr class="text-muted"><td class="text-end" colspan="3">Diferencia</td>'
                + meses.map(function(m) {
                    var d = pie[m].diferencia;

                    return '<td class="text-end' + (d !== null && Math.abs(d) >= 0.01 ? ' text-danger fw-bold' : '') + '">'
                        + (d === null ? '<span class="text-muted">—</span>' : importe(d)) + '</td>';
                }).join('') + '</tr>';

        pintarEjeTotales('tablaAlqVentas', { columnas: meses, valores: total, formato: celdaSuma });

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

            if (c.meses_propia) {
                t += '\nLocal nuevo: participación propia, sobre sus ' + c.meses_propia + ' meses de venta contra '
                    + 'los locales de 12 meses en esos mismos meses.';
            }

            if (c.canal_de_reales) {
                t += '\nMes sin venta proyectada del canal: el canal sale de la venta real de los demás locales.';
            }
        }

        if (c.origen === 'REAL_PROYECTADA') {
            t += '\n\nMes en curso: lo vendido hasta ayer + lo que falta vender.'
                + '\nReal: neta ' + importeTexto(c.real_neta) + ' · bruta ' + importeTexto(c.real_bruta)
                + '\nProyectada: neta ' + importeTexto(c.proyectada_neta) + ' · bruta ' + importeTexto(c.proyectada_bruta)
                + '\n= ' + (c.participacion !== null ? 'estimada' : 'manual') + ' del mes completo (bruta '
                + importeTexto(c.mes_completo_bruta) + ') × ' + c.dias_restantes + ' / ' + c.dias_mes + ' días que faltan, hoy incluido';
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
            crearColumnasFijas({ tabla: 'tablaAlqVentas', control: 'colFijasAlqVentas', clave: 'alquileres_ventas', porDefecto: [0, 1] });
        }

        AvisosPanel.pedirTablero('alquileres');
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
