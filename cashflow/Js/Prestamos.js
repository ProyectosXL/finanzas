/**
 * Financiero -> Prestamos
 *
 * Cuatro sub-pestañas de UN pedido (getPestana): Prestamos (la lista),
 * Cronograma (cuota por cuota, con las correcciones), Proyeccion (el eje del
 * cashflow) y Simulador (cuanto haria falta pedir, con y sin un simulado).
 *
 * EL NAVEGADOR NO DECIDE NADA: el cronograma lo calcula el servidor
 * (Class/PrestamosCalculo.php), la grilla viene armada (Prestamos::
 * armarProyeccion()) y toda validacion manda alla. Aca se dibuja.
 *
 * LO SIMULADO SE VE DISTINTO: italica y atenuado, como lo estimado en Llaves.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/PrestamosController.php';
    var datos = null;
    var vistas = null;
    var abiertos = {};
    var seleccionado = null;
    var form = { modo: 'alta', id: null };
    var cuotaEnEdicion = null;
    var simulacionOk = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('prCargando');
        mostrar('kpisPrestamos', false);

        return pedir(ENDPOINT + '?action=getPestana')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintarPestana('avisosPrestamos', datos.avisos || [], { tab: 'prestamos' });
                vistas.usar(datos.proyeccion);
                pintarKpis();
                dibujarLista();
                dibujarSelectores();
                dibujarCronograma();
                dibujarProyeccion();
                Cargando.ocultar('prCargando');
                mostrar('kpisPrestamos', true);
                bloquearSinTabla();
            })
            .catch(function(e) {
                Cargando.ocultar('prCargando');
                AvisosPanel.pintar('avisosPrestamos', [{ nivel: 'danger', texto: 'No se pudieron calcular los préstamos: ' + e.message }]);
            });
    }

    function bloquearSinTabla() {
        if (datos.tabla_creada) { return; }

        ['btnPrNuevo', 'btnPrCancelar'].forEach(function(id) {
            var b = document.getElementById(id);
            if (b) { b.disabled = true; b.title = 'Falta correr ' + datos.script; }
        });
    }

    function prestamo(id) {
        return (datos.prestamos || []).filter(function(p) { return p.id === Number(id); })[0] || null;
    }

    /* ================================================================
       TARJETAS
       ================================================================ */

    function pintarKpis() {
        var k = datos.kpis;

        texto('prKpiDeuda', plata(k.deuda));
        texto('prKpiDeudaPie', k.deuda_usd_sin_tc ? 'Más U$S ' + numero(k.deuda_usd_sin_tc) + ' sin dólar futuro para pasar a pesos'
            : 'Saldo de capital a hoy, en pesos');
        texto('prKpiCuotas', plata(k.cuotas_horizonte));
        texto('prKpiProxima', k.proxima ? plata(k.proxima.importe) : '—');
        texto('prKpiProximaPie', k.proxima ? fecha(k.proxima.fecha) + ' · ' + k.proxima.nombre : 'No hay cuotas por delante');
        texto('prKpiSimulados', String(k.simulados_n));
        texto('prKpiSimuladosPie', k.simulados_n ? plata(k.simulados_capital) + ' de capital · no suman en el tablero oficial'
            : 'No suman en el tablero oficial');
    }

    /* ================================================================
       1. LA LISTA
       ================================================================ */

    function dibujarLista() {
        var sel = document.getElementById('prFiltroEstado');
        var est = sel.value;
        var estados = datos.listas.estados;

        sel.innerHTML = '<option value="">Todos los estados</option>' + Object.keys(estados).map(function(k) {
            return '<option value="' + esc(k) + '">' + esc(estados[k]) + '</option>';
        }).join('');
        sel.value = est;

        var cuerpo = document.getElementById('prListaBody');
        var edita = Permisos.puedeEditar(cuerpo);
        var filas = (datos.prestamos || []).filter(function(p) { return !est || p.estado === est; });
        var badge = { VIGENTE: 'bg-success', SIMULADO: 'bg-info text-dark', CANCELADO: 'bg-secondary', FINALIZADO: 'bg-light text-dark border' };

        cuerpo.innerHTML = filas.length ? filas.map(function(p) {
            var acciones = '<button type="button" class="btn btn-link btn-sm p-0 me-2 pr-ver">Cronograma</button>';

            if (edita && datos.tabla_creada) {
                acciones += '<button type="button" class="btn btn-link btn-sm p-0 me-2 pr-editar">Editar</button>';
                if (p.puede_vigente) { acciones += '<button type="button" class="btn btn-link btn-sm p-0 me-2 pr-vigente">Pasar a vigente</button>'; }
                if (p.puede_cancelar) { acciones += '<button type="button" class="btn btn-link btn-sm p-0 me-2 pr-cancelar">Cancelar</button>'; }
                if (p.puede_eliminar) { acciones += '<button type="button" class="btn btn-link btn-sm p-0 text-danger pr-eliminar">Eliminar</button>'; }
            }

            return '<tr data-id="' + p.id + '"' + (p.simulado ? ' class="pr-simulado"' : '') + '>'
                + '<td><strong>' + esc(p.nombre) + '</strong>' + (p.cancelada_el ? '<div class="small text-muted">Cancelación total el ' + fecha(p.cancelada_el) + '</div>' : '') + '</td>'
                + '<td>' + esc(p.cuenta) + '</td><td>' + esc(p.moneda) + '</td>'
                + '<td class="text-end">' + plataMon(p.capital, p.moneda) + '</td>'
                + '<td>' + esc(p.tasa) + '</td>'
                + '<td>' + esc(p.sistema) + '<div class="small text-muted">' + esc(p.frecuencias) + '</div></td>'
                + '<td class="text-end">' + p.cuotas + '</td>'
                + '<td><span class="badge ' + (badge[p.estado] || 'bg-light text-dark') + '">' + esc(p.nombre_estado) + '</span>'
                    + (p.proyectable ? '' : ' <i class="fas fa-triangle-exclamation text-danger" title="No se proyecta: mirá los avisos"></i>') + '</td>'
                + '<td class="text-end">' + (p.saldo_hoy === null ? '—' : plataMon(p.saldo_hoy, p.moneda)) + '</td>'
                + '<td>' + (p.proxima ? fecha(p.proxima.fecha) + ' · ' + plataMon(p.proxima.total, p.moneda)
                    + (p.moneda === 'USD' && p.proxima.ars !== null ? '<div class="small text-muted">≈ ' + plata(p.proxima.ars) + '</div>' : '') : '—') + '</td>'
                + '<td class="text-end">' + porc(p.tea) + '</td><td class="text-end">' + porc(p.cft) + '</td>'
                + '<td data-exportar-omitir class="text-nowrap">' + acciones + '</td></tr>';
        }).join('') : '<tr><td colspan="13" class="text-center text-muted py-3">'
            + (datos.prestamos.length ? 'Ningún préstamo con ese estado.' : 'Todavía no hay préstamos cargados.') + '</td></tr>';

        cuerpo.querySelectorAll('tr[data-id]').forEach(function(tr) {
            var id = Number(tr.getAttribute('data-id'));
            var on = function(clase, fn) { var b = tr.querySelector(clase); if (b) { b.addEventListener('click', function() { fn(id); }); } };

            on('.pr-ver', verCronograma);
            on('.pr-editar', function(i) { abrirForm('editar', prestamo(i)); });
            on('.pr-vigente', function(i) { abrirForm('vigente', prestamo(i)); });
            on('.pr-cancelar', abrirCancelar);
            on('.pr-eliminar', eliminar);
        });
    }

    function verCronograma(id) {
        seleccionado = id;
        document.getElementById('prCronoSel').value = String(id);
        dibujarCronograma();
        bootstrap.Tab.getOrCreateInstance(document.getElementById('tabPrCronoBtn')).show();
    }

    function eliminar(id) {
        var p = prestamo(id);

        Notificacion.confirmar({
            titulo: 'Eliminar el préstamo simulado',
            mensaje: '¿Eliminar «' + p.nombre + '»?',
            detalle: 'Es una baja lógica: queda en el historial. Un préstamo vigente no se elimina, se cancela.',
            confirmar: 'Eliminar',
            peligro: true
        }).then(function(si) {
            if (si) { escribir('eliminarSimulado', { id: id }); }
        });
    }

    function dibujarSelectores() {
        var ps = datos.prestamos || [];
        var sel = document.getElementById('prCronoSel');

        if (seleccionado === null || !prestamo(seleccionado)) {
            seleccionado = ps.length ? ps[0].id : null;
        }

        sel.innerHTML = ps.length ? ps.map(function(p) {
            return '<option value="' + p.id + '">' + esc(p.nombre) + ' · ' + esc(p.nombre_estado) + '</option>';
        }).join('') : '<option value="">No hay préstamos</option>';

        if (seleccionado !== null) { sel.value = String(seleccionado); }

        var comp = document.getElementById('prCompararSel');
        var sims = ps.filter(function(p) { return p.simulado; });

        comp.innerHTML = sims.length ? sims.map(function(p) { return '<option value="' + p.id + '">' + esc(p.nombre) + '</option>'; }).join('')
            : '<option value="">No hay préstamos simulados</option>';
    }

    /* ================================================================
       2. CRONOGRAMA
       ================================================================ */

    function dibujarCronograma() {
        var p = seleccionado === null ? null : prestamo(seleccionado);
        var head = document.getElementById('prCronoHead');
        var body = document.getElementById('prCronoBody');
        var foot = document.getElementById('prCronoFoot');
        var btnCan = document.getElementById('btnPrCancelar');

        if (btnCan) { btnCan.style.display = (p && p.puede_cancelar) ? '' : 'none'; }

        if (!p) {
            head.innerHTML = '';
            foot.innerHTML = '';
            body.innerHTML = '<tr><td class="text-muted p-3">Todavía no hay préstamos cargados.</td></tr>';
            texto('prCronoInfo', '');
            texto('prCronoPie', '');
            mostrar('prCancelacionesCard', false);
            return;
        }

        var edita = Permisos.puedeEditar(body) && datos.tabla_creada;
        var costos = p.costos || [];

        document.getElementById('prCronoInfo').innerHTML = esc(p.cuenta) + ' · ' + esc(p.moneda) + ' ' + plataMon(p.capital, p.moneda)
            + ' · ' + esc(p.tasa) + (p.tna_actual !== null ? ' (TNA actual ' + porc(p.tna_actual / 100) + ')' : '')
            + ' · ' + esc(p.sistema) + ', ' + p.cuotas + ' cuotas, ' + esc(p.frecuencias)
            + ' · TEA ' + porc(p.tea) + ' · CFT ' + porc(p.cft)
            + ' · <span class="badge bg-light text-dark border">' + esc(p.nombre_estado) + '</span>'
            + (p.simulado ? ' <span class="text-info">No suma en el tablero oficial.</span>' : '')
            + infoReferencia(p.datos);

        head.innerHTML = '<tr><th>Nº</th><th>Vencimiento</th><th>Fecha de pago</th><th class="text-end">Días</th><th class="text-end">Tasa</th>'
            + '<th class="text-end">Saldo inicial</th><th class="text-end">Capital</th><th class="text-end">Interés</th>'
            + costos.map(function(c) { return '<th class="text-end">' + esc(c.nombre) + '</th>'; }).join('')
            + '<th class="text-end">Comisión</th><th class="text-end">Total</th><th></th>'
            + (edita ? '<th data-exportar-omitir></th>' : '') + '</tr>';

        var tot = { capital: 0, interes: 0, comision: 0, total: 0, costos: {} };
        var mon = p.moneda;

        body.innerHTML = p.cronograma.map(function(f) {
            var cor = f.corregido || {};
            var com = f.cancelacion ? f.cancelacion.comision + f.cancelacion.iva_comision : 0;
            var nro = f.tipo === 'DESEMBOLSO' ? 'Desemb.' : (f.tipo === 'CANCELACION' ? 'Cancel.' : f.nro);
            var marca = function(clave, html) {
                return cor[clave] ? '<span class="pr-corregido" title="Corregido por ' + esc(cor[clave].usuario || '—') + ' · '
                    + esc(cor[clave].fecha || '') + '">' + html + '</span>' : html;
            };

            tot.capital += f.capital; tot.interes += f.interes; tot.comision += com;
            tot.total += (f.tipo === 'DESEMBOLSO' ? f.total_costos : f.total);

            return '<tr class="pr-fila-' + f.tipo.toLowerCase() + (f.real ? ' pr-real' : '') + '">'
                + '<td>' + nro + '</td>'
                + '<td>' + fecha(f.vencimiento) + '</td>'
                + '<td>' + fecha(f.fecha_pago) + (f.corrida ? ' <span class="badge bg-warning text-dark" title="Vence en un día no hábil: se paga el hábil siguiente">corrida</span>' : '') + '</td>'
                + '<td class="text-end">' + (f.tipo === 'DESEMBOLSO' ? '' : f.dias) + '</td>'
                + '<td class="text-end">' + (f.tna === null ? '' : marca('TNA', porc(f.tna / 100))
                    + ' <span class="pr-origen" title="' + esc(f.detalle_tasa || '') + '">' + esc(datos.listas.origenes_tasa[f.origen_tasa] || '') + '</span>') + '</td>'
                + '<td class="text-end">' + (f.tipo === 'DESEMBOLSO' ? plataMon(f.desembolso, mon) : plataMon(f.saldo_inicial, mon)) + '</td>'
                + '<td class="text-end">' + marca('CAPITAL', celda(f.capital, mon)) + '</td>'
                + '<td class="text-end">' + marca('INTERES', celda(f.interes, mon)) + '</td>'
                + costos.map(function(c) {
                    var v = (f.costos || {})[c.id] || 0;
                    tot.costos[c.id] = (tot.costos[c.id] || 0) + v;

                    return '<td class="text-end">' + marca('COSTO:' + c.id, celda(v, mon)) + '</td>';
                }).join('')
                + '<td class="text-end">' + (com ? '<span title="' + esc(f.cancelacion.pct_comision + ' % al ' + f.cancelacion.transcurrido + ' % del plazo, más IVA ' + plataMon(f.cancelacion.iva_comision, mon)) + '">' + plataMon(com, mon) + '</span>' : '<span class="text-muted">—</span>') + '</td>'
                + '<td class="text-end fw-bold">' + plataMon(f.tipo === 'DESEMBOLSO' ? f.total_costos : f.total, mon) + '</td>'
                + '<td><span class="badge ' + (f.real ? 'bg-dark' : 'bg-light text-dark border') + '">' + (f.real ? 'Real' : 'Proyectado') + '</span></td>'
                + (edita ? '<td data-exportar-omitir>' + (f.tipo === 'CUOTA' ? '<button type="button" class="btn btn-link btn-sm p-0 pr-corregir" data-nro="' + f.nro + '">Corregir</button>' : '') + '</td>' : '')
                + '</tr>';
        }).join('');

        foot.innerHTML = '<tr><td colspan="6" class="text-end fw-bold">TOTALES</td>'
            + '<td class="text-end fw-bold">' + plataMon(tot.capital, mon) + '</td><td class="text-end fw-bold">' + plataMon(tot.interes, mon) + '</td>'
            + costos.map(function(c) { return '<td class="text-end fw-bold">' + plataMon(tot.costos[c.id] || 0, mon) + '</td>'; }).join('')
            + '<td class="text-end fw-bold">' + plataMon(tot.comision, mon) + '</td><td class="text-end fw-bold">' + plataMon(tot.total, mon) + '</td><td></td>'
            + (edita ? '<td></td>' : '') + '</tr>';

        body.querySelectorAll('.pr-corregir').forEach(function(b) {
            b.addEventListener('click', function() { abrirCuota(p, Number(b.getAttribute('data-nro'))); });
        });

        document.getElementById('prCronoPie').innerHTML = (p.criterio ? '<i class="fas fa-circle-info me-1"></i>' + esc(p.criterio) + ' ' : '')
            + 'Interés = saldo × TNA × días exactos / 365 entre las fechas de vencimiento (no las corridas por feriado). '
            + 'Lo <span class="pr-corregido">subrayado</span> está corregido a mano.'
            + (p.avisos && p.avisos.length ? '<div class="mt-1 text-warning">' + p.avisos.map(function(a) { return esc(a.texto); }).join('<br>') + '</div>' : '');

        dibujarCancelaciones(p, edita);
    }

    /** "Última TAMAR publicada: XX % (dd/mm) · Promedio últimos N días hábiles: XX %", para un variable. */
    function infoReferencia(d) {
        if (!d || d.tipo_tasa !== 'VARIABLE') { return ''; }

        var ref = (datos.tasas || {})[d.referencia];

        if (!ref || !ref.ultimo) { return '<div class="mt-1 text-danger">No hay valores publicados de ' + esc(d.referencia) + '.</div>'; }

        return '<div class="mt-1">Última ' + esc(ref.nombre) + ' publicada: <strong>' + porc(ref.ultimo.valor / 100) + '</strong> ('
            + fecha(ref.ultimo.fecha).substr(0, 5) + ') · Promedio últimos ' + datos.generales.DIAS_PROMEDIO + ' días hábiles: <strong>'
            + porc(ref.promedio.valor / 100) + '</strong></div>';
    }

    function dibujarCancelaciones(p, edita) {
        var cs = p.cancelaciones || [];

        mostrar('prCancelacionesCard', cs.length > 0);
        document.getElementById('prCancelacionesBody').innerHTML = cs.map(function(c) {
            return '<tr><td>' + fecha(c.fecha) + '</td><td>' + (c.tipo === 'TOTAL' ? 'Total' : 'Parcial') + '</td>'
                + '<td class="text-end">' + (c.monto === null ? '—' : plataMon(c.monto, p.moneda)) + '</td>'
                + '<td>' + (c.modo === 'REDUCIR_CUOTA' ? 'Reduce la cuota' : (c.modo === 'REDUCIR_PLAZO' ? 'Reduce el plazo' : '—')) + '</td>'
                + '<td class="small text-muted">' + esc(Auditoria.linea('alta', c.usuario, c.fecha_alta)) + '</td>'
                + '<td>' + (edita && c.deshacible ? '<button type="button" class="btn btn-link btn-sm p-0 pr-deshacer-can" data-id="' + c.id + '">Deshacer</button>'
                    : (c.deshacible ? '' : '<span class="small text-muted">ya ocurrió</span>')) + '</td></tr>';
        }).join('');

        document.querySelectorAll('.pr-deshacer-can').forEach(function(b) {
            b.addEventListener('click', function() {
                Notificacion.confirmar({ titulo: 'Deshacer la cancelación', mensaje: '¿Deshacer esta cancelación?',
                    detalle: 'Vuelven las cuotas que había dado de baja. Queda en el historial.', confirmar: 'Deshacer' })
                    .then(function(si) { if (si) { escribir('deshacerCancelacion', { id_cancelacion: Number(b.getAttribute('data-id')) }); } });
            });
        });
    }

    /* ---- Corregir una cuota ---- */

    function abrirCuota(p, nro) {
        var f = p.cronograma.filter(function(x) { return x.tipo === 'CUOTA' && x.nro === nro; })[0];

        if (!f) { return; }

        cuotaEnEdicion = { id: p.id, nro: nro, original: {} };
        texto('prCuotaNro', nro + ' · ' + p.nombre);

        var campos = [{ c: 'CAPITAL', n: 'Capital', v: f.capital }, { c: 'INTERES', n: 'Interés', v: f.interes }];

        if (f.tna !== null) { campos.push({ c: 'TNA', n: 'TNA (%)', v: f.tna }); }

        (p.costos || []).forEach(function(c) { campos.push({ c: 'COSTO:' + c.id, n: c.nombre, v: (f.costos || {})[c.id] || 0 }); });

        document.getElementById('prCuotaCampos').innerHTML = campos.map(function(x) {
            cuotaEnEdicion.original[x.c] = numeroEditable(x.v);

            return '<div class="col-6"><label class="form-label small mb-0">' + esc(x.n)
                + ((f.corregido || {})[x.c] ? ' <span class="pr-corregido">corregido</span>' : '') + '</label>'
                + '<input type="text" class="form-control form-control-sm pr-cuota-campo" data-concepto="' + esc(x.c) + '" inputmode="decimal" value="'
                + numeroEditable(x.v) + '"></div>';
        }).join('');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrCuota')).show();
    }

    function guardarCuota() {
        var lista = [];

        document.querySelectorAll('.pr-cuota-campo').forEach(function(el) {
            var c = el.getAttribute('data-concepto');

            if (el.value.trim() !== cuotaEnEdicion.original[c]) { lista.push({ concepto: c, importe: el.value.trim() }); }
        });

        if (!lista.length) { Notificacion.advertencia('No cambiaste ningún dato.'); return; }

        escribir('guardarAjuste', { id: cuotaEnEdicion.id, cuota: cuotaEnEdicion.nro, ajustes: lista }, 'modalPrCuota');
    }

    function deshacerCuota() {
        escribir('deshacerAjuste', { id: cuotaEnEdicion.id, cuota: cuotaEnEdicion.nro, concepto: '' }, 'modalPrCuota');
    }

    /* ---- Cancelación anticipada ---- */

    function abrirCancelar(id) {
        var p = prestamo(id === undefined || id === null || typeof id === 'object' ? seleccionado : id);

        if (!p) { return; }

        seleccionado = p.id;
        simulacionOk = null;
        texto('prCanNombre', p.nombre);
        mostrar('prCanUsd', p.moneda === 'USD');
        document.getElementById('prCanFecha').value = datos.hoy;
        document.getElementById('prCanMonto').value = '';
        document.getElementById('prCanObs').value = '';
        document.getElementById('prCanResultado').innerHTML = '';
        document.getElementById('btnPrCanRegistrar').disabled = true;
        mostrarParcial();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrCancelar')).show();
    }

    function datosCancelacion() {
        return { id: seleccionado, tipo: document.getElementById('prCanTipo').value, fecha: document.getElementById('prCanFecha').value,
                 monto: document.getElementById('prCanMonto').value, modo: document.getElementById('prCanModo').value,
                 observacion: document.getElementById('prCanObs').value };
    }

    function mostrarParcial() {
        var parcial = document.getElementById('prCanTipo').value === 'PARCIAL';

        document.querySelectorAll('.pr-can-parcial').forEach(function(el) { el.style.display = parcial ? '' : 'none'; });
        simulacionOk = null;
        var b = document.getElementById('btnPrCanRegistrar');
        if (b) { b.disabled = true; }
    }

    function simularCancelacion() {
        var d = datosCancelacion();

        pedir(ENDPOINT + '?action=simularCancelacion', d).then(function(r) {
            var c = r.data.comparacion;
            var mon = r.data.moneda;

            simulacionOk = JSON.stringify(d);
            document.getElementById('btnPrCanRegistrar').disabled = false;
            document.getElementById('prCanResultado').innerHTML = '<div class="row g-2 small">'
                + caja('Total a pagar el ' + fecha(d.fecha), plataMon(c.total_a_pagar, mon),
                    'Capital ' + plataMon(c.capital, mon) + ' + intereses devengados ' + plataMon(c.devengado, mon) + ' + comisión e IVA ' + plataMon(c.comision, mon)
                    + (c.fila && c.fila.total_costos ? ' + costos ' + plataMon(c.fila.total_costos, mon) : ''))
                + caja('Intereses y gastos que se ahorran', plataMon(c.ahorro_intereses, mon), 'Seguir pagando: ' + plataMon(c.intereses_sin, mon) + ' · cancelando: ' + plataMon(c.intereses_con, mon))
                + caja('Costo de la comisión (con IVA)', plataMon(c.comision, mon), '')
                + caja('Resultado', plataMon(c.ahorro_neto, mon), c.ahorro_neto >= 0 ? 'Conviene: se ahorra más de lo que cuesta la comisión.' : 'La comisión cuesta más de lo que se ahorra.')
                + '</div>'
                + '<h6 class="mt-3 mb-1">Cómo queda el cashflow (pagos del préstamo, en pesos)</h6>'
                + '<table class="table table-sm small mb-0"><thead><tr><th>Mes</th><th class="text-end">Seguir pagando</th><th class="text-end">Cancelando</th>'
                + '<th class="text-end">Diferencia</th><th class="text-end">Acumulada</th></tr></thead><tbody>'
                + c.por_mes.map(function(m) {
                    return '<tr><td>' + esc(m.mes) + (m.sin_pesos ? ' <span class="text-warning" title="Algún pago sin dólar futuro: no se pasó a pesos">*</span>' : '') + '</td>'
                        + '<td class="text-end">' + plata(m.sin) + '</td><td class="text-end">' + plata(m.con) + '</td>'
                        + '<td class="text-end ' + (m.diferencia < 0 ? 'text-danger' : 'text-success') + '">' + plata(m.diferencia) + '</td>'
                        + '<td class="text-end">' + plata(m.acumulado) + '</td></tr>';
                }).join('') + '</tbody></table>'
                + '<small class="text-muted">Diferencia positiva: ese mes el cashflow queda con más plata si se cancela.</small>';
        }).catch(function(e) {
            simulacionOk = null;
            document.getElementById('btnPrCanRegistrar').disabled = true;
            document.getElementById('prCanResultado').innerHTML = '<div class="alert alert-danger small mb-0">' + esc(e.message) + '</div>';
        });
    }

    function registrarCancelacion() {
        var d = datosCancelacion();

        if (simulacionOk !== JSON.stringify(d)) {
            Notificacion.advertencia('Cambiaste los datos: volvé a simular antes de registrar.');
            return;
        }

        Notificacion.confirmar({ titulo: 'Registrar la cancelación', mensaje: '¿Registrar la cancelación ' + (d.tipo === 'TOTAL' ? 'total' : 'parcial')
            + ' del ' + fecha(d.fecha) + '?', detalle: 'Se puede deshacer mientras la fecha no haya pasado.', confirmar: 'Registrar', peligro: true })
            .then(function(si) { if (si) { escribir('registrarCancelacion', d, 'modalPrCancelar'); } });
    }

    /* ================================================================
       ALTA, EDICIÓN Y PASO A VIGENTE
       ================================================================ */

    function opciones(lista, actual) {
        return Object.keys(lista).map(function(k) {
            return '<option value="' + esc(k) + '"' + (k === actual ? ' selected' : '') + '>' + esc(lista[k]) + '</option>';
        }).join('');
    }

    function abrirForm(modo, p) {
        var l = datos.listas;
        var g = datos.generales || {};
        var d = p ? p.datos : null;

        form = { modo: modo, id: p ? p.id : null };
        texto('prFormTitulo', modo === 'alta' ? 'Nuevo préstamo' : (modo === 'vigente' ? 'Pasar a vigente · ' + p.nombre : 'Editar · ' + p.nombre));
        texto('btnPrGuardar', modo === 'vigente' ? 'Pasar a vigente' : 'Guardar');

        document.getElementById('prFCuenta').innerHTML = '<option value="">Elegí la cuenta…</option>' + opciones(datos.cuentas || {}, d ? d.cuenta_clave : '');
        document.getElementById('prFMoneda').innerHTML = opciones(l.monedas, d ? d.moneda : 'ARS');
        document.getElementById('prFForma').innerHTML = '<option value="">—</option>' + opciones(l.formas_pago, d ? d.forma_pago : '');
        document.getElementById('prFReferencia').innerHTML = opciones(l.referencias, d ? d.referencia : 'TAMAR');
        document.getElementById('prFCriterio').innerHTML = opciones(l.criterios, d ? d.criterio_tasa : 'PROMEDIO');
        document.getElementById('prFSistema').innerHTML = opciones(l.sistemas, d ? d.sistema : g.SIM_SISTEMA);
        document.getElementById('prFFrecCap').innerHTML = opciones(l.frecuencias, d ? d.frec_capital : g.SIM_FRECUENCIA);
        document.getElementById('prFFrecInt').innerHTML = opciones(l.frecuencias, d ? d.frec_intereses : g.SIM_FRECUENCIA);
        document.getElementById('prFComModo').innerHTML = opciones(l.comision, d ? d.cancelacion.modo : (datos.alta.cancelacion || {}).modo);

        valor('prFNombre', d ? d.nombre : '');
        valor('prFCapital', d ? numeroEditable(d.capital) : '');
        valor('prFDesembolso', d ? d.fecha_desembolso : '');
        valor('prFTna', d ? (d.tna === null ? '' : numeroEditable(d.tna)) : numeroEditable(g.SIM_TNA));
        valor('prFSpread', d && d.spread !== null ? numeroEditable(d.spread) : '');
        valor('prFCuotas', d ? d.cuotas : g.SIM_CUOTAS);
        valor('prFPrimera', d && d.fecha_primera ? d.fecha_primera : '');
        valor('prFGracia', d ? d.gracia : 0);
        valor('prFObs', d && d.observacion ? d.observacion : '');
        valor('prFTipoTasa', d ? d.tipo_tasa : 'FIJA');

        var est = document.getElementById('prFEstado');
        est.value = (modo === 'vigente') ? 'VIGENTE' : (d ? d.estado : 'SIMULADO');
        est.disabled = (modo !== 'alta');

        var com = d ? d.cancelacion : (datos.alta.cancelacion || { pct_total: 0, pct_parcial: 0, iva: true, tramos: [] });
        valor('prFComTotal', numeroEditable(com.pct_total));
        valor('prFComParcial', numeroEditable(com.pct_parcial));
        document.getElementById('prFComIva').checked = !!com.iva;
        document.getElementById('prFTramos').innerHTML = (com.tramos || []).map(filaTramo).join('');

        var costos = d ? (p.costos || []) : (datos.alta.costos || []);
        document.getElementById('prFCostos').innerHTML = costos.map(filaCosto).join('');

        engancharQuitar();
        alternarTasa();
        alternarTramos();
        ayudaSistema();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrPrestamo')).show();
    }

    function filaCosto(c) {
        var l = datos.listas;

        /* data-id: el costo ya guardado del prestamo. Al editar se actualiza en su
           lugar, asi las correcciones COSTO:<id> de las cuotas siguen valiendo. */
        return '<tr data-id="' + (c.id || '') + '" data-id-param="' + (c.id_param || '') + '">'
            + '<td><input type="text" class="form-control form-control-sm pr-fc" data-campo="nombre" value="' + esc(c.nombre || '') + '"></td>'
            + '<td><input type="text" class="form-control form-control-sm pr-fc text-end" data-campo="pct" inputmode="decimal" value="' + (c.pct === undefined ? '' : numeroEditable(c.pct)) + '"></td>'
            + '<td><select class="form-select form-select-sm pr-fc" data-campo="base">' + opciones(l.bases, c.base || 'CAPITAL') + '</select></td>'
            + '<td><select class="form-select form-select-sm pr-fc" data-campo="momento">' + opciones(l.momentos, c.momento || 'POR_CUOTA') + '</select></td>'
            + '<td class="text-center"><input type="checkbox" class="form-check-input pr-fc" data-campo="iva"' + (c.iva ? ' checked' : '') + '></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger pr-quitar" title="Quitar"><i class="fas fa-times"></i></button></td></tr>';
    }

    function filaTramo(t) {
        return '<tr>'
            + '<td><input type="text" class="form-control form-control-sm pr-ft" data-campo="desde" inputmode="decimal" value="' + (t.desde === undefined ? '' : numeroEditable(t.desde)) + '"></td>'
            + '<td><input type="text" class="form-control form-control-sm pr-ft" data-campo="pct_total" inputmode="decimal" value="' + (t.pct_total === undefined ? '' : numeroEditable(t.pct_total)) + '"></td>'
            + '<td><input type="text" class="form-control form-control-sm pr-ft" data-campo="pct_parcial" inputmode="decimal" value="' + (t.pct_parcial === undefined ? '' : numeroEditable(t.pct_parcial)) + '"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger pr-quitar" title="Quitar"><i class="fas fa-times"></i></button></td></tr>';
    }

    function engancharQuitar() {
        document.querySelectorAll('#modalPrPrestamo .pr-quitar').forEach(function(b) { b.onclick = function() { b.closest('tr').remove(); }; });
    }

    function alternarTasa() {
        var fija = document.getElementById('prFTipoTasa').value === 'FIJA';

        document.querySelectorAll('#modalPrPrestamo .pr-fija').forEach(function(el) { el.style.display = fija ? '' : 'none'; });
        document.querySelectorAll('#modalPrPrestamo .pr-variable').forEach(function(el) { el.style.display = fija ? 'none' : ''; });

        var ref = (datos.tasas || {})[document.getElementById('prFReferencia').value];
        document.getElementById('prFTasaInfo').innerHTML = ref && ref.ultimo
            ? 'Última ' + esc(ref.nombre) + ' publicada: <strong>' + porc(ref.ultimo.valor / 100) + '</strong> (' + fecha(ref.ultimo.fecha).substr(0, 5) + ') · '
              + 'Promedio últimos ' + datos.generales.DIAS_PROMEDIO + ' días hábiles: <strong>' + porc(ref.promedio.valor / 100) + '</strong>'
            : 'No hay valores publicados de esa tasa.';
    }

    function alternarTramos() {
        document.getElementById('prFTramosBloque').style.display = document.getElementById('prFComModo').value === 'ESCALONADA' ? '' : 'none';
    }

    function ayudaSistema() {
        texto('prFAyudaSistema', 'El francés necesita capital e intereses con la misma frecuencia; con frecuencias distintas, alemán o bullet. '
            + 'Las cuotas son las de capital, la gracia se cuenta en períodos de capital, y el plazo tiene que ser múltiplo de la frecuencia de intereses. '
            + 'Bullet: todo el capital en la última cuota. Primera cuota vacía: el desembolso más un período de la frecuencia más corta.');
    }

    function leerForm() {
        var costos = [];

        document.querySelectorAll('#prFCostos tr').forEach(function(tr) {
            var c = { id: tr.getAttribute('data-id') || '', id_param: tr.getAttribute('data-id-param') || '' };

            tr.querySelectorAll('.pr-fc').forEach(function(el) { c[el.getAttribute('data-campo')] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
            costos.push(c);
        });

        var tramos = [];

        document.querySelectorAll('#prFTramos tr').forEach(function(tr) {
            var t = {};

            tr.querySelectorAll('.pr-ft').forEach(function(el) { t[el.getAttribute('data-campo')] = el.value; });
            tramos.push(t);
        });

        return {
            id: form.id,
            nombre: val('prFNombre'), cuenta_clave: val('prFCuenta'), moneda: val('prFMoneda'), capital: val('prFCapital'),
            fecha_desembolso: val('prFDesembolso'), estado: val('prFEstado'), forma_pago: val('prFForma'),
            tipo_tasa: val('prFTipoTasa'), tna: val('prFTna'), referencia: val('prFReferencia'), spread: val('prFSpread'),
            criterio_tasa: val('prFCriterio'), sistema: val('prFSistema'), cuotas: val('prFCuotas'),
            frec_capital: val('prFFrecCap'), frec_intereses: val('prFFrecInt'), fecha_primera: val('prFPrimera'),
            gracia: val('prFGracia'), observacion: val('prFObs'), costos: costos,
            cancelacion: { modo: val('prFComModo'), pct_total: val('prFComTotal'), pct_parcial: val('prFComParcial'),
                           iva: document.getElementById('prFComIva').checked ? 1 : 0, tramos: tramos }
        };
    }

    function guardarForm() {
        var d = leerForm();
        var accion = form.modo === 'alta' ? 'altaPrestamo' : (form.modo === 'vigente' ? 'pasarAVigente' : 'editarPrestamo');

        if (form.modo !== 'vigente') {
            escribir(accion, d, 'modalPrPrestamo');
            return;
        }

        Notificacion.confirmar({ titulo: 'Pasar a vigente', mensaje: '¿Pasar «' + d.nombre + '» a vigente?',
            detalle: 'Desde ahora suma en el tablero oficial. Un préstamo vigente no se elimina: se cancela.', confirmar: 'Pasar a vigente' })
            .then(function(si) { if (si) { escribir(accion, d, 'modalPrPrestamo'); } });
    }

    /* ================================================================
       3. PROYECCIÓN
       ================================================================ */

    function conSimulaciones() {
        var c = document.getElementById('prProySim');

        return !!(c && c.checked);
    }

    function dibujarProyeccion() {
        if (!datos) { return; }

        var columnas = vistas.columnas();
        var sim = conSimulaciones();
        var filas = (datos.proyeccion.filas || []).filter(function(f) {
            if (f.simulado && !sim) { return false; }

            return f.nivel === 0 || abiertos[f.padre];
        });

        mostrar('prProySimAviso', sim);

        document.getElementById('prProyHead').innerHTML = '<tr><th class="col-texto">Préstamo · concepto</th>'
            + columnas.map(function(c) {
                var m = vistas.marca(c);

                return '<th class="text-end' + (m.clase ? ' ' + m.clase : '') + '"' + (m.titulo ? ' title="' + esc(m.titulo) + '"' : '') + '>' + esc(vistas.rotulo(c)) + '</th>';
            }).join('') + '<th class="text-end fw-bold total-column">Total</th></tr>';

        document.getElementById('prProyBody').innerHTML = filas.length ? filas.map(function(f) {
            var hijas = f.nivel === 0;

            return '<tr class="pr-n' + f.nivel + (f.simulado ? ' pr-simulado' : '') + '">'
                + '<td>' + (hijas ? '<span class="pr-toggle text-muted" data-toggle="' + esc(f.id) + '"><i class="fas fa-chevron-' + (abiertos[f.id] ? 'down' : 'right') + ' small"></i></span> ' : '<span class="pr-toggle"></span> ')
                + esc(f.nombre) + (f.sub ? ' <small class="text-muted">' + esc(f.sub) + '</small>' : '') + '</td>'
                + columnas.map(function(c) {
                    var v = vistas.valor(f.eje, c);
                    var m = vistas.marca(c).clase || '';

                    return '<td class="text-end ' + m + '">' + (v ? celdaSigno(v) : '<span class="text-muted">—</span>') + '</td>';
                }).join('')
                + '<td class="text-end fw-bold">' + celdaSigno(vistas.total(f.eje)) + '</td></tr>';
        }).join('') : '<tr><td colspan="' + (columnas.length + 2) + '" class="text-center text-muted py-4">No hay pagos de préstamos en el horizonte.</td></tr>';

        document.querySelectorAll('#prProyBody [data-toggle]').forEach(function(t) {
            t.addEventListener('click', function() {
                var id = t.getAttribute('data-toggle');
                abiertos[id] = !abiertos[id];
                dibujarProyeccion();
            });
        });

        var tot = sim ? datos.proyeccion.totales_sim : datos.proyeccion.totales;
        var valores = columnas.map(function(c) { return Number(vistas.valor(tot, c)) || 0; });
        var total = Number(vistas.total(tot)) || 0;

        document.getElementById('prProyFoot').innerHTML = '<tr><td class="fw-bold text-end">TOTAL FINANCIAMIENTO</td>'
            + valores.map(function(v, i) { return '<td class="text-end fw-bold ' + (vistas.marca(columnas[i]).clase || '') + '">' + celdaSigno(v) + '</td>'; }).join('')
            + '<td class="text-end fw-bold">' + celdaSigno(total) + '</td></tr>';

        pintarEjeTotales('tablaPrProy', { columnas: columnas, valores: valores, total: total, formato: celdaSigno, formatoTotal: celdaSigno });
    }

    function abrirTodo(abrir) {
        abiertos = {};

        if (abrir) { (datos.proyeccion.filas || []).forEach(function(f) { if (f.nivel === 0) { abiertos[f.id] = true; } }); }

        dibujarProyeccion();
    }

    function exportar() {
        var previo = JSON.parse(JSON.stringify(abiertos));

        abrirTodo(true);
        exportarTabla('tablaPrProy', 'Prestamos_Proyeccion');
        abiertos = previo;
        dibujarProyeccion();
    }

    /* ================================================================
       4. SIMULADOR
       ================================================================ */

    function calcularNecesidad() {
        Cargando.mostrar('prSimCargando');

        pedir(ENDPOINT + '?action=calcularNecesidad').then(function(r) {
            var n = r.data;
            var caja = document.getElementById('prNecesidad');

            Cargando.ocultar('prSimCargando');
            datos.necesidad = n;

            if (!n.hay_faltante) {
                caja.innerHTML = '<div class="text-success"><i class="fas fa-circle-check me-1"></i>Con la cobertura, el saldo acumulado no queda en negativo en '
                    + 'todo el horizonte: no haría falta pedir un préstamo.</div>';
                texto('prKpiNecesidad', '$ 0');
                texto('prKpiNecesidadPie', 'No queda en negativo en el horizonte');
                return;
            }

            caja.innerHTML = '<div class="row g-2">'
                + caja2('Primer día en negativo', fecha(n.primer_negativo) + (n.aproximada ? ' *' : ''), n.aproximada ? 'Aproximada: cae en una columna mensual, y el tablero no sabe qué día del mes se pone en rojo.' : '')
                + caja2('Saldo mínimo', plata(n.minimo), 'el ' + (n.minimo_rotulo || ''))
                + caja2('Necesidad', plata(n.necesidad), 'Lo que falta en el peor momento')
                + caja2('Monto sugerido', plata(n.sugerido), 'Necesidad × (1 + ' + numero(n.margen) + ' %) / (1 − ' + numero(n.pct_iniciales) + ' % de costos iniciales)')
                + '</div>'
                + Permisos.siEdita(caja, '<div class="mt-3 d-flex gap-2 align-items-end flex-wrap">'
                    + '<div><label class="form-label small mb-0" for="prSimCuenta">Banco</label><select id="prSimCuenta" class="form-select form-select-sm">'
                    + opciones(datos.cuentas || {}, '') + '</select></div>'
                    + '<button type="button" class="btn btn-sm btn-primary" id="btnPrSimular"><i class="fas fa-flask me-1"></i> Simular préstamo</button>'
                    + '<small class="text-muted">Lo arma como simulado con ese monto, desembolso el ' + fecha(n.desembolso) + ' y las condiciones por defecto de Parámetros. Queda en la lista para ajustarlo.</small></div>');

            texto('prKpiNecesidad', plata(n.necesidad));
            texto('prKpiNecesidadPie', 'Para el ' + fecha(n.primer_negativo) + (n.aproximada ? ' (aprox.)' : '') + ' · sugerido ' + plata(n.sugerido));

            var b = document.getElementById('btnPrSimular');

            if (b) {
                b.addEventListener('click', function() {
                    escribir('simularPrestamo', { monto: n.sugerido, fecha: n.desembolso, cuenta_clave: val('prSimCuenta') });
                });
            }
        }).catch(function(e) {
            Cargando.ocultar('prSimCargando');
            document.getElementById('prNecesidad').innerHTML = '<div class="text-danger">' + esc(e.message) + '</div>';
        });
    }

    function comparar() {
        var id = val('prCompararSel');

        if (!id) { Notificacion.advertencia('No hay préstamos simulados para comparar.'); return; }

        Cargando.mostrar('prCompCargando');

        pedir(ENDPOINT + '?action=compararSimulado&id=' + encodeURIComponent(id)).then(function(r) {
            Cargando.ocultar('prCompCargando');
            document.getElementById('prCompararBody').innerHTML = (r.data.meses || []).map(function(m) {
                return '<tr><td>' + esc(m.rotulo) + '</td><td class="text-end' + (m.sin < 0 ? ' text-danger' : '') + '">' + plata(m.sin) + '</td>'
                    + '<td class="text-end' + (m.con < 0 ? ' text-danger' : '') + '">' + plata(m.con) + '</td>'
                    + '<td class="text-end">' + plata(m.con - m.sin) + '</td></tr>';
            }).join('') || '<tr><td colspan="4" class="text-muted small p-3">Sin columnas en el horizonte.</td></tr>';
        }).catch(function(e) {
            Cargando.ocultar('prCompCargando');
            Notificacion.error(e.message);
        });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function escribir(accion, cuerpo, modal) {
        return pedir(ENDPOINT + '?action=' + accion, cuerpo)
            .then(function(d) {
                if (modal) { bootstrap.Modal.getOrCreateInstance(document.getElementById(modal)).hide(); }
                Notificacion.exito(d.message || 'Listo.');
                if (accion === 'altaPrestamo' || accion === 'simularPrestamo') { seleccionado = d.data && d.data.id ? d.data.id : seleccionado; }
                cargar();
            })
            .catch(function(e) { Notificacion.error(e.message); });
    }

    function irAParametros() {
        var item = document.querySelector('.menu-link[data-tab="parametros"]');

        if (!item) { return; }

        window.cfSubTabDestino = 'PRESTAMOS';
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

    function caja(titulo, valorTxt, pie) {
        return '<div class="col-md-6"><div class="border rounded p-2"><div class="text-muted">' + esc(titulo) + '</div>'
            + '<div class="fs-6 fw-bold">' + valorTxt + '</div><div class="text-muted">' + esc(pie) + '</div></div></div>';
    }

    function caja2(titulo, valorTxt, pie) {
        return '<div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">' + esc(titulo) + '</div>'
            + '<div class="fs-6 fw-bold">' + valorTxt + '</div><div class="text-muted">' + esc(pie) + '</div></div></div>';
    }

    function celda(v, mon) { return v ? plataMon(v, mon) : '<span class="text-muted">—</span>'; }
    function celdaSigno(v) { return !v ? '<span class="text-muted">—</span>' : '<span class="' + (v < 0 ? 'text-danger' : '') + '">' + plata(v) + '</span>'; }
    function plata(n) { return '$ ' + Number(n || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 }); }
    function plataMon(n, mon) {
        return (mon === 'USD' ? 'U$S ' : '$ ') + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function porc(d) { return (d === null || d === undefined) ? '—' : (Number(d) * 100).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %'; }
    function numero(n) { return Number(n).toLocaleString('es-AR', { maximumFractionDigits: 2 }); }
    function numeroEditable(n) { return (n === null || n === undefined || n === '') ? '' : String(Math.round(Number(n) * 10000) / 10000).replace('.', ','); }
    function fecha(f) { return f ? String(f).substr(8, 2) + '/' + String(f).substr(5, 2) + '/' + String(f).substr(0, 4) : '—'; }
    function texto(id, t) { var el = document.getElementById(id); if (el) { el.textContent = t; } }
    function valor(id, v) { var el = document.getElementById(id); if (el) { el.value = (v === null || v === undefined) ? '' : v; } }
    function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
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
        vistas = crearEjeVistas({ botones: 'vistasPr', periodo: 'periodoPr', alCambiar: dibujarProyeccion, inicial: 'completo' });

        enganchar('btnRefreshPr', 'click', cargar);
        enganchar('prFiltroEstado', 'change', dibujarLista);
        enganchar('prCronoSel', 'change', function() { seleccionado = Number(val('prCronoSel')) || null; dibujarCronograma(); });
        enganchar('btnPrNuevo', 'click', function() { abrirForm('alta', null); });
        enganchar('btnPrGuardar', 'click', guardarForm);
        enganchar('prFTipoTasa', 'change', alternarTasa);
        enganchar('prFReferencia', 'change', alternarTasa);
        enganchar('prFComModo', 'change', alternarTramos);
        enganchar('prFFrecCap', 'change', function() { valor('prFFrecInt', val('prFFrecCap')); });
        enganchar('prFCostoAgregar', 'click', function() {
            document.getElementById('prFCostos').insertAdjacentHTML('beforeend', filaCosto({}));
            engancharQuitar();
        });
        enganchar('prFTramoAgregar', 'click', function() {
            document.getElementById('prFTramos').insertAdjacentHTML('beforeend', filaTramo({}));
            engancharQuitar();
        });
        enganchar('btnPrCuotaGuardar', 'click', guardarCuota);
        enganchar('btnPrCuotaDeshacer', 'click', deshacerCuota);
        enganchar('btnPrCancelar', 'click', function() { abrirCancelar(seleccionado); });
        enganchar('prCanTipo', 'change', mostrarParcial);
        enganchar('btnPrCanSimular', 'click', simularCancelacion);
        enganchar('btnPrCanRegistrar', 'click', registrarCancelacion);
        enganchar('prProySim', 'change', dibujarProyeccion);
        enganchar('btnExpandirPr', 'click', function() { abrirTodo(!Object.keys(abiertos).length); });
        enganchar('btnExportarPr', 'click', exportar);
        enganchar('btnPrNecesidad', 'click', calcularNecesidad);
        enganchar('btnPrComparar', 'click', comparar);

        document.querySelectorAll('.pr-ir-parametros').forEach(function(a) {
            a.addEventListener('click', function(e) { e.preventDefault(); irAParametros(); });
        });

        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({ tabla: 'tablaPrProy', control: 'colFijasPr', clave: 'prestamos', porDefecto: [0] });
        }

        AvisosPanel.pedirTablero('prestamos');
        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
