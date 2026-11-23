/**
 * RRHH y Operativos -> Llaves y Renov. Contratos
 *
 * Tres sub-pestañas de UN pedido (getProyeccion): Proyeccion (operador ->
 * local -> concepto, aperturas y compromisos), Contratos (la hoja "Detalles de
 * vencimientos") y Facturas (las marcadas como llave en Alquileres).
 *
 * EL NAVEGADOR NO DECIDE NADA: cuanto se paga y cuando lo resuelve el servidor
 * (Class/LlavesCalculo.php) y la grilla viene armada (Llaves::armarPestana()).
 * Aca se dibuja con las tres vistas del eje (eje-vistas.js), columnas fijas,
 * totales arriba del eje y exportar.
 *
 * LO ESTIMADO SE VE DISTINTO: italica y atenuado, con el chip del origen.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/LlavesController.php';
    var datos = null;
    var vistas = null;
    var abiertos = {};
    var filtro = '';

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('llSpinner');
        mostrar('llWrapper', false);
        mostrar('kpisLlaves', false);

        pedir(ENDPOINT + '?action=getProyeccion')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintarPestana('avisosLlaves', datos.avisos || [], { tab: 'llaves_renov' });
                vistas.usar(datos.eje);
                pintarKpis();
                pintarLeyenda();
                dibujar();
                dibujarContratos();
                dibujarFacturas();
                Cargando.ocultar('llSpinner');
                mostrar('llWrapper', true);
                mostrar('kpisLlaves', true);
            })
            .catch(function(e) {
                Cargando.ocultar('llSpinner');
                AvisosPanel.pintar('avisosLlaves', [{ nivel: 'danger', texto: 'No se pudo proyectar llaves y renovaciones: ' + e.message }]);
            });
    }

    function pintarKpis() {
        var k = datos.kpis;

        texto('llKpiTotal', plata(k.total));
        texto('llKpiRen', String(k.renovaciones_n));
        texto('llKpiRenPie', plata(k.renovaciones_importe) + ' de llave y otros gastos');
        texto('llKpiEst', plata(k.estimado));
        texto('llKpiEstPie', 'contra ' + plata(k.firme) + ' en un contrato o una factura y ' + plata(k.manual) + ' cargado a mano');
        texto('llKpiSinDato', String(k.sin_dato_n));
        var pie = document.getElementById('llKpiSinDatoPie');
        if (pie) { pie.title = (k.sin_dato || []).join(', '); }
    }

    function pintarLeyenda() {
        var o = datos.origenes || {};

        document.getElementById('leyendaLl').innerHTML = Object.keys(o).map(function(k) {
            return '<span class="ll-chip ll-chip-' + k + '" data-etiqueta="' + esc(o[k]) + '"></span>';
        }).join(' ') + ' <span class="ll-chip ll-chip-MIXTO" data-etiqueta="mixto"></span>';
    }

    /* ================================================================
       1. PROYECCIÓN
       ================================================================ */

    function dibujar() {
        if (!datos) { return; }

        var columnas = vistas.columnas();
        var filas = visibles();

        document.getElementById('llThead').innerHTML = '<tr><th rowspan="2" class="col-texto">Operador · local · concepto</th></tr><tr>'
            + columnas.map(function(c) {
                var m = vistas.marca(c);

                return '<th class="text-end' + (m.clase ? ' ' + m.clase : '') + '"' + (m.titulo ? ' title="' + esc(m.titulo) + '"' : '') + '>'
                    + esc(vistas.rotulo(c)) + '</th>';
            }).join('') + '<th class="text-end fw-bold total-column">Total</th></tr>';

        if (!filas.length) {
            document.getElementById('llBody').innerHTML = '<tr><td colspan="' + (columnas.length + 2) + '" class="text-center text-muted py-4">'
                + (datos.filas.length ? 'Nada coincide con la búsqueda.' : 'No hay nada que proyectar en el horizonte. Mirá los avisos de arriba.')
                + '</td></tr>';
        } else {
            document.getElementById('llBody').innerHTML = filas.map(function(f) { return filaHtml(f, columnas); }).join('');
        }

        document.querySelectorAll('#llBody [data-toggle]').forEach(function(t) {
            t.addEventListener('click', function() {
                var id = t.getAttribute('data-toggle');
                abiertos[id] = !abiertos[id];
                dibujar();
            });
        });

        pintarPie(columnas);
    }

    /** Las filas que se ven: las hijas, sólo con el padre abierto; con búsqueda, las que coinciden y su camino. */
    function visibles() {
        var porId = {};

        datos.filas.forEach(function(f) { porId[f.id] = f; });

        var coincide = {};

        if (filtro) {
            var t = filtro.toLowerCase();

            datos.filas.forEach(function(f) {
                if ((f.nombre + ' ' + f.sub).toLowerCase().indexOf(t) !== -1) {
                    var x = f;

                    while (x) { coincide[x.id] = true; x = x.padre ? porId[x.padre] : null; }
                }
            });
        }

        return datos.filas.filter(function(f) {
            if (filtro && !coincide[f.id] && !(f.padre && coincide[f.padre] && f.nivel === 2)) { return false; }

            var p = f.padre;

            while (p) {
                if (!abiertos[p] && !filtro) { return false; }
                p = porId[p] ? porId[p].padre : null;
            }

            return true;
        });
    }

    function filaHtml(f, columnas) {
        var tieneHijas = datos.filas.some(function(x) { return x.padre === f.id; });
        var abierto = !!abiertos[f.id] || !!filtro;

        return '<tr class="ll-n' + f.nivel + (f.tipo === 'GRUPO' ? ' ll-grupo' : '') + '" data-id="' + esc(f.id) + '">'
            + '<td>' + (tieneHijas ? '<span class="ll-toggle text-muted" data-toggle="' + esc(f.id) + '"><i class="fas fa-chevron-'
                + (abierto ? 'down' : 'right') + ' small"></i></span> ' : '<span class="ll-toggle"></span> ')
            + esc(f.nombre) + (f.sub ? ' <small class="text-muted">' + esc(f.sub) + '</small>' : '') + '</td>'
            + columnas.map(function(c) {
                var v = vistas.valor(f.eje, c);
                var cel = (f.celdas || {})[c];
                var m = vistas.marca(c).clase || '';

                if (!v) { return '<td class="text-end ' + m + '"><span class="text-muted">—</span></td>'; }

                return '<td class="text-end ' + m + (cel && cel.origen === 'ESTIMADO' ? ' ll-estimado' : '') + '"'
                    + (cel ? ' title="' + esc(cel.tooltip + '\nOrigen: ' + cel.chip) + '" data-origen="' + esc(cel.chip) + '"' : '') + '>'
                    + plata(v) + (cel ? '<span class="ll-chip ll-chip-' + cel.origen + '" data-etiqueta="' + esc(cel.chip) + '"></span>' : '')
                    + '</td>';
            }).join('')
            + '<td class="text-end fw-bold">' + plata(vistas.total(f.eje)) + '</td></tr>';
    }

    function pintarPie(columnas) {
        var tot = datos.eje.totales;
        var valores = columnas.map(function(c) { return Number(vistas.valor(tot, c)) || 0; });
        var total = Number(vistas.total(tot)) || 0;

        document.getElementById('llTfoot').innerHTML = '<tr><td class="fw-bold text-end">TOTALES</td>'
            + valores.map(function(v, i) { return '<td class="text-end fw-bold ' + (vistas.marca(columnas[i]).clase || '') + '">' + celdaSuma(v) + '</td>'; }).join('')
            + '<td class="text-end fw-bold">' + celdaSuma(total) + '</td></tr>';

        pintarEjeTotales('tablaLlaves', { columnas: columnas, valores: valores, total: total, formato: celdaSuma, formatoTotal: celdaSuma });
    }

    function abrirTodo(abrir) {
        abiertos = {};

        if (abrir) { datos.filas.forEach(function(f) { abiertos[f.id] = true; }); }

        dibujar();
    }

    /** Exportar abre todo: tabla-export.js baja sólo lo que se ve. */
    function exportar() {
        var previo = JSON.parse(JSON.stringify(abiertos));

        abrirTodo(true);
        exportarTabla('tablaLlaves', 'Llaves_Renovaciones');
        abiertos = previo;
        dibujar();
    }

    /* ================================================================
       2. CONTRATOS
       ================================================================ */

    function dibujarContratos() {
        var sel = document.getElementById('llContEstado');
        var est = sel.value;
        var estados = datos.estados || {};

        sel.innerHTML = '<option value="">Todos los estados</option>' + Object.keys(estados).map(function(k) {
            return '<option value="' + esc(k) + '">' + esc(estados[k]) + '</option>';
        }).join('');
        sel.value = est;

        var edita = Permisos.puedeEditar('llContBody');
        var filas = (datos.contratos || []).filter(function(c) { return !est || c.estado === est; });

        document.getElementById('llContBody').innerHTML = filas.length ? filas.map(function(c) {
            var v = c.vigente || {};
            var ren = c.renovacion;
            var badge = { VENCIDO: 'bg-warning text-dark', VENCE: 'bg-info text-dark', RENOVADO: 'bg-success',
                          NO_RENUEVA: 'bg-secondary', SIN_CONTRATO: 'bg-warning text-dark' }[c.estado] || 'bg-light text-dark border';
            var notas = (c.notas || []).concat((c.pagadas || []).map(function(p) {
                return 'Ya pagada (' + (p.concepto === 'LLAVE' ? 'llave' : 'otros gastos') + ') el ' + fecha(p.fecha) + ': ' + plata(p.monto);
            }));

            return '<tr data-nro="' + c.nro + '">'
                + '<td><strong>' + esc(c.nombre) + '</strong> <small class="text-muted">' + c.nro + '</small>'
                    + (notas.length ? '<div class="ll-nota">' + notas.map(esc).join('<br>') + '</div>' : '') + '</td>'
                + '<td>' + esc(c.operador || '—') + '</td>'
                + '<td><span class="badge ' + badge + '">' + esc(c.nombre_estado) + '</span></td>'
                + '<td>' + fecha(v.desde) + '</td><td>' + fecha(v.hasta) + '</td>'
                + '<td class="text-end">' + (c.meses || '') + '</td>'
                + '<td class="text-end">' + llave(v.llave) + '</td>'
                + '<td class="text-end">' + (v.comisiones === undefined || v.comisiones === null ? '—' : plata(v.comisiones)) + '</td>'
                + '<td class="text-end">' + (v.fpc === undefined || v.fpc === null ? '—' : plata(v.fpc)) + '</td>'
                + '<td>' + (ren ? 'Sí: #' + ren.id + ' desde ' + fecha(ren.desde) + ', llave ' + llave(ren.llave) : 'No') + '</td>'
                + '<td class="text-end">' + (c.m2 === null ? '—' : numero(c.m2)) + '</td>'
                + '<td>' + esc(c.segmento ? c.segmento.charAt(0) + c.segmento.slice(1).toLowerCase() : '—') + '</td>'
                + '<td data-exportar-omitir>' + (edita && c.renovaciones.some(function(r) { return r.ant; })
                    ? '<button type="button" class="btn btn-link btn-sm p-0 ll-pagada" title="La llave o los otros gastos de una renovación ya salieron de caja">Ya pagada</button>' : '')
                + '</td></tr>';
        }).join('') : '<tr><td colspan="13" class="text-center text-muted py-3">Ningún local con ese estado.</td></tr>';

        document.querySelectorAll('#llContBody .ll-pagada').forEach(function(b) {
            b.addEventListener('click', function() { abrirPagada(Number(b.closest('tr').getAttribute('data-nro'))); });
        });
    }

    function llave(v) {
        if (v === null || v === undefined) { return '—'; }
        if (Math.abs(v - 1) < 0.005) { return '<span title="Tiene llave, monto desconocido: se estima">1 *</span>'; }

        return plata(v);
    }

    var pagadaNro = null;

    function abrirPagada(nro) {
        var c = (datos.contratos || []).filter(function(x) { return x.nro === nro; })[0];

        if (!c) { return; }

        pagadaNro = nro;
        texto('llPagLocal', c.nombre);
        document.getElementById('llPagRen').innerHTML = c.renovaciones.filter(function(r) { return r.ant; }).map(function(r) {
            return '<option value="' + r.ant + '">' + esc(r.tipo === 'ESTIMADA' ? 'Renovación estimada desde ' + fecha(r.inicio)
                : 'Contrato #' + r.contrato + ' desde ' + fecha(r.inicio)) + '</option>';
        }).join('');
        ['llPagFecha', 'llPagMonto', 'llPagComp', 'llPagObs'].forEach(function(id) { document.getElementById(id).value = ''; });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLlPagada')).show();
    }

    function guardarPagada() {
        pedir(ENDPOINT + '?action=altaPagada', {
            nro: pagadaNro, id_contrato_ant: document.getElementById('llPagRen').value,
            concepto: document.getElementById('llPagConcepto').value, fecha: document.getElementById('llPagFecha').value,
            monto: document.getElementById('llPagMonto').value, comprobante: document.getElementById('llPagComp').value,
            observacion: document.getElementById('llPagObs').value
        }).then(function(d) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLlPagada')).hide();
            Notificacion.exito(d.message || 'Listo.');
            cargar();
        }).catch(function(e) { Notificacion.error(e.message); });
    }

    /* ================================================================
       3. FACTURAS
       ================================================================ */

    function dibujarFacturas() {
        var fs = datos.facturas || [];

        document.getElementById('llFactBody').innerHTML = fs.length ? fs.map(function(f) {
            return '<tr><td>' + esc(f.comprobante) + ' <small class="text-muted">' + esc(f.cod_provee) + '</small></td>'
                + '<td>' + esc(f.local) + '</td><td>' + esc(f.nombre_concepto) + '</td><td>' + esc(f.mes) + '</td>'
                + '<td>' + (f.pagada ? '<span class="badge bg-secondary">Pagada: no suma</span>' : '<span class="badge bg-dark">Pendiente</span>') + '</td>'
                + '<td class="text-end">' + (f.pagada ? '—' : plata(f.total)) + '</td>'
                + '<td><small>' + (f.pagos || []).map(function(p) { return fecha(p.fecha) + ' ' + plata(p.importe); }).join(' · ') + '</small></td></tr>';
        }).join('') : '<tr><td colspan="7" class="text-center text-muted py-3">Ninguna factura marcada como llave. Se marcan en Alquileres › Facturas.</td></tr>';
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function irAParametros() {
        var item = document.querySelector('.menu-link[data-tab="parametros"]');

        if (!item) { return; }

        window.cfSubTabDestino = 'LLAVES_RENOV';
        item.click();
    }

    function pedir(url, cuerpo) {
        var op = cuerpo ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) } : {};

        return fetch(url, op)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) { throw new Error((d && d.message) || 'Respuesta inesperada del servidor'); }

                return d;
            });
    }

    function celdaSuma(v) { return v === 0 ? '<span class="text-muted">—</span>' : plata(v); }
    function plata(n) { return '$ ' + Number(n || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 }); }
    function numero(n) { return Number(n).toLocaleString('es-AR', { maximumFractionDigits: 2 }); }
    function fecha(f) { return f ? String(f).substr(8, 2) + '/' + String(f).substr(5, 2) + '/' + String(f).substr(0, 4) : '—'; }
    function texto(id, t) { var el = document.getElementById(id); if (el) { el.textContent = t; } }
    function mostrar(id, v) { var el = document.getElementById(id); if (el) { el.style.display = v ? '' : 'none'; } }

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function enganchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) { el.addEventListener(evento, fn); }
    }

    /* ================================================================
       ARRANQUE
       ================================================================ */

    function iniciar() {
        vistas = crearEjeVistas({ botones: 'vistasLl', periodo: 'periodoLl', alCambiar: dibujar, inicial: 'completo' });

        enganchar('btnRefreshLl', 'click', cargar);
        enganchar('btnExportarLl', 'click', exportar);
        enganchar('btnExpandirLl', 'click', function() { abrirTodo(!Object.keys(abiertos).length); });
        enganchar('llContEstado', 'change', dibujarContratos);
        enganchar('btnLlPagGuardar', 'click', guardarPagada);

        var buscar = document.getElementById('busquedaLl');

        if (buscar) {
            buscar.addEventListener('input', function() { filtro = buscar.value.trim(); dibujar(); });
        }

        document.querySelectorAll('.ll-ir-parametros').forEach(function(a) {
            a.addEventListener('click', function(e) { e.preventDefault(); irAParametros(); });
        });

        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({ tabla: 'tablaLlaves', control: 'colFijasLl', clave: 'llaves_renov', porDefecto: [0] });
        }

        AvisosPanel.pedirTablero('llaves_renov');
        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
