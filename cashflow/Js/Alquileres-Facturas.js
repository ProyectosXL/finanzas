/**
 * RRHH y Operativos -> Alquileres -> Facturas
 *
 * Las facturas pendientes de alquiler, su vencimiento a considerar y su
 * imputacion. Los datos llegan con la Proyeccion, en UN pedido (getProyeccion):
 * Js/Alquileres.js los recibe y llama a AlquileresFacturasTab.pintar().
 *
 * EL NAVEGADOR NO DECIDE NADA. Que reemplaza una imputacion, cuanto se paga y
 * cuando lo resuelve el servidor (Class/AlquileresImputacion.php). Aca se arma
 * el pedido, se suma para mostrar la diferencia mientras se carga -y bloquear
 * Guardar- y se pide la previsualizacion, que es la que vale: el servidor la
 * vuelve a correr al guardar contra los pendientes de ese momento.
 *
 * SIN PERMISO DE EDICION los controles de escritura no se dibujan (Permisos):
 * ni los tildes, ni el input de vencimiento, ni Imputar ni Deshacer.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/AlquileresController.php';
    var datos = null;
    var hoy = null;
    var seleccion = {};
    var renglones = [];
    var previsto = null;

    var ESTADOS_PENDIENTES = ['SIN_IMPUTAR', 'IMPUTADA', 'PARCIAL', 'SUSPENDIDA'];
    var ESTADOS_PAGADAS = ['PAGADA'];

    function puedeEditar() {
        return Permisos.puedeEditar('alqFactBody') && datos && datos.tablas;
    }

    /* ================================================================
       PINTAR
       ================================================================ */

    function pintar(d) {
        datos = d.facturas || { filas: [], imputaciones: [], kpis: {}, estados: {} };
        hoy = d.hoy;
        seleccion = {};
        llenarFiltros();
        pintarKpis(d);
        dibujar();
        dibujarImputaciones();
    }

    function llenarFiltros() {
        var razon = document.getElementById('alqFactRazon');
        var estado = document.getElementById('alqFactEstado');
        var r = razon.value;
        var e = estado.value;
        var razones = {};

        datos.filas.forEach(function(f) { razones[f.cod_provee] = f.razon || f.cod_provee; });

        razon.innerHTML = '<option value="">Todas las razones sociales</option>'
            + Object.keys(razones).sort().map(function(c) {
                return '<option value="' + esc(c) + '">' + esc(razones[c] + ' (' + c + ')') + '</option>';
            }).join('');
        estado.innerHTML = '<option value="">Todos los estados</option>'
            + Object.keys(datos.estados).map(function(k) {
                return '<option value="' + esc(k) + '">' + esc(datos.estados[k]) + '</option>';
            }).join('');
        razon.value = r;
        estado.value = e;
    }

    function pintarKpis(d) {
        var k = datos.kpis || {};

        texto('alqFactKpiSin', importe(k.sin_imputar_importe));
        texto('alqFactKpiSinPie', (k.sin_imputar_n || 0) + ' factura(s): no suman en ningún lado hasta que se imputen');
        texto('alqFactKpiImp', importe(k.imputadas_a_pagar));
        texto('alqFactKpiImpPie', (k.imputadas_n || 0) + ' factura(s) · pendiente − retención');
        texto('alqFactKpiReemp', String(datos.reemplazados || 0));
    }

    function visibles() {
        var r = document.getElementById('alqFactRazon').value;
        var e = document.getElementById('alqFactEstado').value;
        var pagadas = document.getElementById('alqFactVerPagadas').checked;

        return datos.filas.filter(function(f) {
            if (r && f.cod_provee !== r) { return false; }
            if (e && f.estado !== e) { return false; }
            if (!pagadas && ESTADOS_PAGADAS.indexOf(f.estado) !== -1) { return false; }

            return true;
        });
    }

    function dibujar() {
        var filas = visibles();
        var edita = puedeEditar();

        if (!filas.length) {
            html('alqFactBody', '<tr><td colspan="11" class="text-center text-muted py-4">'
                + (datos.filas.length ? 'Ninguna factura coincide con el filtro.'
                    : 'No hay facturas de alquiler pendientes. Si esperabas alguna, mirá los avisos de arriba.')
                + '</td></tr>');
            html('alqFactTfoot', '');
            actualizarBoton();

            return;
        }

        html('alqFactBody', filas.map(function(f) {
            var tildable = edita && ESTADOS_PENDIENTES.indexOf(f.estado) !== -1;

            return '<tr data-clave="' + esc(f.clave) + '">'
                + '<td class="text-center" data-exportar-omitir>' + (tildable
                    ? '<input type="checkbox" class="form-check-input alq-fact-sel"' + (seleccion[f.clave] ? ' checked' : '') + '>'
                    : '') + '</td>'
                + '<td title="' + esc(f.cod_provee) + '">' + esc(f.razon || f.cod_provee) + '</td>'
                + '<td title="' + esc(f.leyenda || '') + '">' + esc(f.t_comp + ' ' + f.n_comp) + '</td>'
                + '<td data-orden="' + esc(f.emision || '') + '">' + fecha(f.emision) + '</td>'
                + '<td data-orden="' + esc(f.vto_tango) + '">' + fecha(f.vto_tango) + '</td>'
                + celdaVto(f, edita)
                + '<td class="text-end" data-orden="' + (f.importe || 0) + '">' + importe(f.importe) + '</td>'
                + '<td class="text-end" data-orden="' + f.pendiente + '">' + importe(f.pendiente) + '</td>'
                + '<td class="text-end" data-orden="' + (f.retencion || 0) + '">'
                +   (f.retencion === null ? '<span class="text-muted">—</span>' : importe(f.retencion)) + '</td>'
                + '<td class="text-end fw-semibold" data-orden="' + f.a_pagar + '">' + importe(f.a_pagar) + '</td>'
                + '<td>' + chipEstado(f) + '</td>'
                + '</tr>';
        }).join(''));

        var suma = function(k) { return filas.reduce(function(a, f) { return a + (f[k] || 0); }, 0); };

        html('alqFactTfoot', '<tr class="fw-bold"><td></td><td colspan="5">Total (' + filas.length + ')</td>'
            + '<td class="text-end">' + importe(suma('importe')) + '</td>'
            + '<td class="text-end">' + importe(suma('pendiente')) + '</td>'
            + '<td class="text-end">' + importe(suma('retencion')) + '</td>'
            + '<td class="text-end">' + importe(suma('a_pagar')) + '</td><td></td></tr>');

        document.querySelectorAll('#alqFactBody .alq-fact-sel').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var clave = cb.closest('tr').getAttribute('data-clave');

                if (cb.checked) { seleccion[clave] = true; } else { delete seleccion[clave]; }

                actualizarBoton();
            });
        });

        document.querySelectorAll('#alqFactBody .alq-vto').forEach(function(input) {
            input.addEventListener('change', function() { editarVto(input); });
        });

        document.querySelectorAll('#alqFactBody .alq-vto-volver').forEach(function(btn) {
            btn.addEventListener('click', function() { guardar('deshacerVtoFactura', cuotaDe(btn)); });
        });

        actualizarBoton();
    }

    function chipEstado(f) {
        var cls = { SIN_IMPUTAR: 'bg-danger', IMPUTADA: 'bg-success', PARCIAL: 'bg-warning text-dark',
                    PAGADA: 'bg-secondary', ANULADA: 'bg-warning text-dark', SIN_DATO: 'bg-warning text-dark',
                    SUSPENDIDA: 'bg-warning text-dark' }[f.estado] || 'bg-light text-dark';
        var t = datos.estados[f.estado] || f.estado;
        var tip = f.imputacion ? 'Imputación ' + f.imputacion + '\n'
            + Auditoria.texto({ alta: { usuario: f.imputacion_usuario, fecha: f.imputacion_fecha } }) : '';

        if (f.estado === 'PAGADA' && f.fecha_pagada) { tip += '\nPagada en Tango el ' + fecha(f.fecha_pagada); }
        if (f.pendiente_al_imputar !== null && f.estado !== 'PAGADA' && Math.abs(f.pendiente_al_imputar - f.pendiente) > 0.005) {
            tip += '\nAl imputar el pendiente era ' + importeTexto(f.pendiente_al_imputar) + ': revisá la imputación.';
        }

        return '<span class="badge ' + cls + '" title="' + esc(tip) + '">' + esc(t) + '</span>';
    }

    /** El vencimiento a considerar: input que guarda al cambiar, mínimo hoy, y ↺ al de Tango */
    function celdaVto(f, edita) {
        var ed = f.vto_editado;
        var titulo = ed
            ? 'Cambiado en esta pestaña. En Tango vence el ' + fecha(f.vto_tango) + '.'
              + (ed.MOTIVO ? '\nMotivo: ' + ed.MOTIVO : '')
              + '\n' + Auditoria.texto({ alta: { usuario: ed.USUARIO_ALTA, fecha: ed.FECHA_ALTA } })
            : 'El de Tango. Es la fecha en que se paga si está imputada.';
        var nota = f.nota_fecha ? '<div class="small text-muted">' + esc(f.nota_fecha) + '</div>' : '';

        if (!edita || ESTADOS_PENDIENTES.indexOf(f.estado) === -1) {
            return '<td data-orden="' + esc(f.vto_considerar) + '" title="' + esc(titulo) + '">'
                + fecha(f.vto_considerar) + (ed ? ' <span class="badge bg-info text-dark">cambiado</span>' : '')
                + nota + '</td>';
        }

        return '<td data-orden="' + esc(f.vto_considerar) + '">'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +   '<input type="date" class="form-control form-control-sm alq-vto" value="' + esc(f.vto_considerar) + '"'
            +   ' min="' + esc(hoy) + '" data-cod="' + esc(f.cod_provee) + '" data-tcomp="' + esc(f.t_comp) + '"'
            +   ' data-ncomp="' + esc(f.n_comp) + '" data-vto-tango="' + esc(f.vto_tango) + '"'
            +   ' data-vigente="' + esc(f.vto_considerar) + '" title="' + esc(titulo) + '">'
            +   (ed ? '<button class="btn btn-outline-secondary alq-vto-volver" type="button" title="Volver al '
                    + 'vencimiento de Tango (' + esc(fecha(f.vto_tango)) + ')"><i class="fas fa-rotate-left"></i></button>' : '')
            + '</div>' + nota + '</td>';
    }

    function cuotaDe(el) {
        var input = el.closest('td').querySelector('.alq-vto');

        return { cod_provee: input.getAttribute('data-cod'), t_comp: input.getAttribute('data-tcomp'),
                 n_comp: input.getAttribute('data-ncomp'), vto_tango: input.getAttribute('data-vto-tango') };
    }

    /** Guardar el vencimiento a considerar. El motivo es opcional, como en Tarjetas. */
    function editarVto(input) {
        var nueva = input.value;

        if (!nueva || nueva === input.getAttribute('data-vigente')) { return; }

        if (nueva < hoy) {
            Notificacion.campoInvalido(input, 'El vencimiento a considerar no puede ser anterior a hoy.');
            input.value = input.getAttribute('data-vigente');

            return;
        }

        var cuota = cuotaDe(input);

        Notificacion.pedirTexto({
            titulo: 'Vencimiento a considerar',
            mensaje: cuota.t_comp + ' ' + cuota.n_comp + ' de ' + cuota.cod_provee + ': se paga el ' + fecha(nueva) + '.',
            detalle: 'En Tango vence el ' + fecha(cuota.vto_tango) + ' y ahí no cambia.',
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

    function actualizarBoton() {
        var b = document.getElementById('btnAlqImputar');

        if (!b) { return; }

        b.style.display = puedeEditar() ? '' : 'none';
        b.disabled = !Object.keys(seleccion).length;
    }

    /* ================================================================
       LAS IMPUTACIONES VIGENTES
       ================================================================ */

    function dibujarImputaciones() {
        var lista = datos.imputaciones || [];

        if (!lista.length) {
            html('alqImputaciones', '<p class="text-muted small m-3">Ninguna todavía.</p>');

            return;
        }

        html('alqImputaciones', '<table class="table table-sm small mb-0"><thead><tr><th>#</th><th>Facturas</th>'
            + '<th>Renglones</th><th>Cargó</th><th></th></tr></thead><tbody>'
            + lista.map(function(imp) {
                return '<tr' + (imp.suspendida ? ' class="table-warning"' : '') + '><td>' + imp.id + '</td>'
                    + '<td>' + imp.facturas.map(function(f) {
                        return esc(f.COD_PROVEE + ' ' + f.T_COMP + ' ' + f.N_COMP) + ' · ' + importe(f.PENDIENTE)
                            + ' · ret. ' + importe(f.RETENCION) + (f.ESTADO === 'PAGADA' ? ' <span class="badge bg-secondary">pagada</span>' : '');
                    }).join('<br>') + '</td>'
                    + '<td>' + imp.renglones.map(function(r) {
                        return r.orden + '. ' + esc(nombresConceptos(r.conceptos)) + ' · ' + esc(nombresLocales(r))
                            + ' · cubre ' + esc(rotuloMes(r.mes)) + ' · ' + importe(r.importe);
                    }).join('<br>') + '</td>'
                    + '<td>' + esc(imp.usuario || '—') + '<br><span class="text-muted">' + esc(imp.fecha || '') + '</span>'
                    + (imp.observacion ? '<br><em>' + esc(imp.observacion) + '</em>' : '') + '</td>'
                    + '<td class="text-end">' + (puedeEditar()
                        ? '<button class="btn btn-sm btn-outline-danger py-0 alq-imp-deshacer" data-id="' + imp.id + '">Deshacer</button>'
                        : '') + '</td></tr>';
            }).join('') + '</tbody></table>');

        document.querySelectorAll('#alqImputaciones .alq-imp-deshacer').forEach(function(b) {
            b.addEventListener('click', function() {
                var id = b.getAttribute('data-id');

                Notificacion.confirmar({
                    titulo: 'Deshacer la imputación ' + id,
                    mensaje: '¿Deshacer la imputación ' + id + '?',
                    detalle: 'Sus facturas vuelven a Sin imputar —y dejan de sumar— y la proyección que '
                        + 'reemplazaba vuelve. No se borra: queda en el historial.',
                    confirmar: 'Deshacer',
                    peligro: true
                }).then(function(ok) {
                    if (ok) { guardar('deshacerImputacion', { id: Number(id) }); }
                });
            });
        });
    }

    function nombresConceptos(lista) {
        var nombres = {};

        (datos.conceptos || []).forEach(function(c) { nombres[c.id] = c.nombre; });

        return lista.map(function(c) { return nombres[c] || c; }).join(', ');
    }

    function nombresLocales(r) {
        if (r.todos_de) { return 'todos los de ' + r.todos_de; }

        var nombres = {};

        (datos.locales || []).forEach(function(l) { nombres[l.nro] = l.nombre; });

        return r.locales.map(function(n) { return nombres[n] || n; }).join(', ');
    }

    /* ================================================================
       EL ARMADO DE UNA IMPUTACION
       ================================================================ */

    function tildadas() {
        return datos.filas.filter(function(f) { return seleccion[f.clave]; });
    }

    function abrirImputar() {
        var fs = tildadas();

        if (!fs.length) { return; }

        previsto = null;
        renglones = [];
        renglones = [nuevoRenglon(fs)];
        html('alqImpFacturas', fs.map(function(f, i) {
            return '<tr><td>' + esc(f.razon || f.cod_provee) + '</td><td>' + esc(f.t_comp + ' ' + f.n_comp) + '</td>'
                + '<td>' + fecha(f.vto_considerar) + '</td><td class="text-end">' + importe(f.pendiente) + '</td>'
                + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end alq-imp-ret" '
                + 'data-i="' + i + '" value="' + (f.retencion !== null ? f.retencion : '') + '" placeholder="sugerida al previsualizar"></td>'
                + '<td class="text-end alq-imp-apagar" data-i="' + i + '">' + importe(f.pendiente - (f.retencion || 0)) + '</td></tr>';
        }).join(''));

        document.querySelectorAll('#alqImpFacturas .alq-imp-ret').forEach(function(inp) {
            inp.addEventListener('input', cambio);
        });

        document.getElementById('alqImpObs').value = '';
        dibujarRenglones();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlqImputar')).show();
    }

    /** Un renglon nuevo: el mes del vencimiento de la primera factura, el resto por cargar */
    function nuevoRenglon(fs) {
        var falta = totalFacturas(fs) - renglones.reduce(function(a, r) { return a + (Number(r.importe) || 0); }, 0);

        return { conceptos: [], locales: [], todos_de: '', mes: (fs[0].vto_considerar || hoy).substring(0, 7),
                 importe: falta > 0 ? Math.round(falta * 100) / 100 : '' };
    }

    function totalFacturas(fs) {
        return (fs || tildadas()).reduce(function(a, f) { return a + f.pendiente; }, 0);
    }

    function razonesTildadas() {
        var r = {};

        tildadas().forEach(function(f) { r[f.cod_provee] = f.razon || f.cod_provee; });

        return r;
    }

    function dibujarRenglones() {
        var razones = razonesTildadas();
        var locales = (datos.locales || []).filter(function(l) { return razones[l.cod_provee]; });
        var nombresGrupo = datos.nombres_grupo || {};

        html('alqImpRenglones', renglones.map(function(r, i) {
            return '<div class="border rounded p-2 mb-2 alq-imp-renglon" data-i="' + i + '">'
                + '<div class="d-flex justify-content-between align-items-center mb-1"><strong class="small">Renglón ' + (i + 1) + '</strong>'
                + (renglones.length > 1 ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 alq-imp-quitar">Quitar</button>' : '')
                + '</div>'
                + '<div class="small mb-1">Atajos: ' + Object.keys(datos.atajos || {}).map(function(g) {
                    return '<button type="button" class="btn btn-sm btn-outline-secondary py-0 alq-imp-atajo" data-grupo="' + esc(g) + '">'
                        + esc(nombresGrupo[g] || g) + '</button>';
                }).join(' ') + '</div>'
                + '<div class="d-flex flex-wrap gap-2 small mb-2">' + (datos.conceptos || []).map(function(c) {
                    return '<label class="form-check-label"><input type="checkbox" class="form-check-input me-1 alq-imp-concepto" value="'
                        + esc(c.id) + '"' + (r.conceptos.indexOf(c.id) !== -1 ? ' checked' : '') + '>' + esc(c.nombre) + '</label>';
                }).join('') + '</div>'
                + '<div class="row g-2">'
                + '<div class="col-md-4"><label class="form-label small mb-0">Locales</label>'
                +   '<select class="form-select form-select-sm alq-imp-todos"><option value="">Elegir locales…</option>'
                +   Object.keys(razones).map(function(c) {
                        return '<option value="' + esc(c) + '"' + (r.todos_de === c ? ' selected' : '') + '>Todos los de '
                            + esc(razones[c]) + '</option>';
                    }).join('') + '</select>'
                +   '<select class="form-select form-select-sm mt-1 alq-imp-locales" multiple size="4"' + (r.todos_de ? ' disabled' : '') + '>'
                +   locales.map(function(l) {
                        return '<option value="' + l.nro + '"' + (r.locales.indexOf(l.nro) !== -1 ? ' selected' : '') + '>'
                            + esc(l.nombre) + '</option>';
                    }).join('') + '</select></div>'
                + '<div class="col-md-3"><label class="form-label small mb-0">Mes que cubre</label>'
                +   '<input type="month" class="form-control form-control-sm alq-imp-mes" value="' + esc(r.mes) + '">'
                +   '<div class="form-text">El mes de pago cuya proyección reemplaza.</div></div>'
                + '<div class="col-md-3"><label class="form-label small mb-0">Importe (con IVA y percepciones)</label>'
                +   '<input type="number" step="0.01" min="0" class="form-control form-control-sm text-end alq-imp-importe" value="'
                +   esc(r.importe) + '"></div>'
                + '</div></div>';
        }).join(''));

        document.querySelectorAll('#alqImpRenglones .alq-imp-renglon').forEach(function(div) {
            var i = Number(div.getAttribute('data-i'));
            var r = renglones[i];

            div.querySelectorAll('.alq-imp-concepto').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    r.conceptos = Array.prototype.map.call(div.querySelectorAll('.alq-imp-concepto:checked'),
                        function(x) { return x.value; });
                    cambio();
                });
            });

            div.querySelectorAll('.alq-imp-atajo').forEach(function(b) {
                b.addEventListener('click', function() {
                    var grupo = datos.atajos[b.getAttribute('data-grupo')] || [];
                    var todos = grupo.every(function(c) { return r.conceptos.indexOf(c) !== -1; });

                    r.conceptos = todos ? r.conceptos.filter(function(c) { return grupo.indexOf(c) === -1; })
                        : r.conceptos.concat(grupo.filter(function(c) { return r.conceptos.indexOf(c) === -1; }));
                    dibujarRenglones();
                    cambio();
                });
            });

            div.querySelector('.alq-imp-todos').addEventListener('change', function(ev) {
                r.todos_de = ev.target.value;
                dibujarRenglones();
                cambio();
            });

            div.querySelector('.alq-imp-locales').addEventListener('change', function(ev) {
                r.locales = Array.prototype.map.call(ev.target.selectedOptions, function(o) { return Number(o.value); });
                cambio();
            });

            div.querySelector('.alq-imp-mes').addEventListener('change', function(ev) { r.mes = ev.target.value; cambio(); });
            div.querySelector('.alq-imp-importe').addEventListener('input', function(ev) { r.importe = ev.target.value; cambio(); });

            var quitar = div.querySelector('.alq-imp-quitar');

            if (quitar) {
                quitar.addEventListener('click', function() {
                    renglones.splice(i, 1);
                    dibujarRenglones();
                    cambio();
                });
            }
        });

        cambio();
    }

    /** Cualquier cambio invalida la previsualizacion: Guardar se apaga hasta la proxima */
    function cambio() {
        previsto = null;
        document.getElementById('btnAlqImpGuardar').disabled = true;

        var fs = tildadas();
        var tf = totalFacturas(fs);
        var tr = renglones.reduce(function(a, r) { return a + (Number(r.importe) || 0); }, 0);
        var dif = Math.round((tr - tf) * 100) / 100;

        document.querySelectorAll('#alqImpFacturas .alq-imp-ret').forEach(function(inp) {
            var f = fs[Number(inp.getAttribute('data-i'))];
            var celda = document.querySelector('#alqImpFacturas .alq-imp-apagar[data-i="' + inp.getAttribute('data-i') + '"]');

            if (celda) { celda.textContent = importeTexto(f.pendiente - (Number(inp.value) || 0)); }
        });

        html('alqImpTotales', '<span>Facturas: <strong>' + importe(tf) + '</strong></span>'
            + '<span>Renglones: <strong>' + importe(tr) + '</strong></span>'
            + '<span class="' + (Math.abs(dif) > (datos.tolerancia || 1) ? 'text-danger' : 'text-success') + '">Diferencia: <strong>'
            + importe(dif) + '</strong></span>');
        html('alqImpResultado', '');
    }

    function borrador() {
        var fs = tildadas();
        var rets = document.querySelectorAll('#alqImpFacturas .alq-imp-ret');

        return {
            facturas: fs.map(function(f, i) {
                var v = rets[i] ? rets[i].value : '';

                return { cod_provee: f.cod_provee, t_comp: f.t_comp, n_comp: f.n_comp, vto_tango: f.vto_tango,
                         pendiente: f.pendiente, retencion: v === '' ? null : Number(v) };
            }),
            renglones: renglones.map(function(r) {
                return { conceptos: r.conceptos, locales: r.todos_de ? [] : r.locales, todos_de: r.todos_de || null,
                         mes: r.mes, importe: Number(r.importe) || 0 };
            }),
            observacion: document.getElementById('alqImpObs').value
        };
    }

    function previsualizar() {
        var b = borrador();

        pedirJson(ENDPOINT + '?action=previsualizarImputacion', b)
            .then(function(d) {
                var v = d.data;
                var rets = document.querySelectorAll('#alqImpFacturas .alq-imp-ret');

                /* La retencion sugerida completa las que estan vacias: se puede corregir. */
                (v.facturas || []).forEach(function(f, i) {
                    if (rets[i] && rets[i].value === '' && f.retencion_sugerida !== null) {
                        rets[i].value = f.retencion_sugerida;
                        rets[i].title = 'Sugerida: alícuota de retención de los locales sobre el neto '
                            + (f.neto_de === 'TANGO' ? 'gravado de Tango' : 'calculado con el IVA y la percepción de los locales')
                            + ' (' + importeTexto(f.neto_usado) + ').';
                    }
                });

                if (v.ok && b.facturas.some(function(f) { return f.retencion === null; })) {
                    /* Se completaron sugeridas: hay que volver a previsualizar con ellas. */
                    cambio();
                    html('alqImpResultado', '<div class="alert alert-info small py-2">Se completó la retención sugerida. '
                        + 'Revisala y volvé a previsualizar.</div>');

                    return;
                }

                html('alqImpResultado', resultado(v));
                previsto = v.ok ? JSON.stringify(borrador()) : null;
                document.getElementById('btnAlqImpGuardar').disabled = !v.ok;
            })
            .catch(function(e) { Notificacion.error('No se pudo previsualizar: ' + e.message); });
    }

    function resultado(v) {
        var h = '';

        if (v.errores && v.errores.length) {
            h += '<div class="alert alert-danger small py-2 mb-2"><ul class="mb-0">'
                + v.errores.map(function(e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>';
        }

        (v.avisos || []).forEach(function(a) {
            h += '<div class="alert alert-' + (a.nivel === 'warning' ? 'warning' : 'info') + ' small py-2 mb-2">' + esc(a.texto) + '</div>';
        });

        if (v.ok) {
            h += '<div class="small"><strong>Qué reemplaza</strong>, por renglón y local (lo proyectado de esos conceptos en ese mes):'
                + '<ul class="mb-0">' + v.renglones.map(function(r) {
                    return '<li>Renglón ' + r.orden + ' · cubre ' + esc(rotuloMes(r.mes)) + ': '
                        + Object.keys(r.pesos).map(function(nro) {
                            return esc(nombresLocales({ locales: [Number(nro)] })) + ' ' + importe(r.proyectado[nro])
                                + ' → ' + numero(r.pesos[nro] * 100, 1) + ' %';
                        }).join(', ') + (r.iguales ? ' <em>(partes iguales)</em>' : '') + '</li>';
                }).join('') + '</ul></div>';
        }

        return h;
    }

    function guardarImputacion() {
        var b = borrador();

        if (previsto === null || previsto !== JSON.stringify(b)) {
            Notificacion.error('Cambió algo desde la previsualización: volvé a previsualizar.');

            return;
        }

        pedirJson(ENDPOINT + '?action=guardarImputacion', b)
            .then(function(d) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlqImputar')).hide();
                Notificacion.exito(d.message || 'Imputación guardada.');
                seleccion = {};
                recargar();
            })
            .catch(function(e) { Notificacion.error(e.message); });
    }

    /* ================================================================
       UTILES
       ================================================================ */

    function guardar(accion, cuerpo) {
        pedirJson(ENDPOINT + '?action=' + accion, cuerpo)
            .then(function(d) { Notificacion.exito(d.message || 'Listo.'); recargar(); })
            .catch(function(e) { Notificacion.error('No se pudo guardar: ' + e.message); recargar(); });
    }

    function recargar() {
        if (window.AlquileresPestana) { window.AlquileresPestana.recargar(); }
    }

    function pedirJson(url, cuerpo) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) { throw new Error((d && d.message) || 'Respuesta inesperada del servidor'); }

                return d;
            });
    }

    function rotuloMes(m) {
        var n = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        return m ? n[Number(m.substring(5, 7)) - 1] + ' ' + m.substring(0, 4) : '';
    }

    function importe(v) {
        return (v === null || v === undefined) ? '<span class="text-muted">—</span>' : esc(importeTexto(v));
    }

    function importeTexto(v) {
        return (v === null || v === undefined) ? '—'
            : '$ ' + Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function numero(v, dec) {
        return Number(v).toLocaleString('es-AR', { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 2 });
    }

    function fecha(f) {
        return f ? f.substring(8, 10) + '/' + f.substring(5, 7) + '/' + f.substring(0, 4) : '—';
    }

    function texto(id, t) { var el = document.getElementById(id); if (el) { el.textContent = t; } }
    function html(id, h) { var el = document.getElementById(id); if (el) { el.innerHTML = h; } }

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function iniciar() {
        ['alqFactRazon', 'alqFactEstado', 'alqFactVerPagadas'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) { el.addEventListener('change', function() { if (datos) { dibujar(); } }); }
        });

        var b = document.getElementById('btnAlqImputar');
        if (b) { b.addEventListener('click', abrirImputar); }

        var r = document.getElementById('btnAlqImpRenglon');
        if (r) { r.addEventListener('click', function() { renglones.push(nuevoRenglon(tildadas())); dibujarRenglones(); }); }

        var p = document.getElementById('btnAlqImpPrevisualizar');
        if (p) { p.addEventListener('click', previsualizar); }

        var g = document.getElementById('btnAlqImpGuardar');
        if (g) { g.addEventListener('click', guardarImputacion); }

        var obs = document.getElementById('alqImpObs');
        if (obs) { obs.addEventListener('input', cambio); }
    }

    window.AlquileresFacturasTab = { pintar: pintar };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
