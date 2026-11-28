/**
 * RRHH y Operativos -> Alquileres -> Facturas
 *
 * Las facturas pendientes de alquiler, su vencimiento a considerar y su
 * imputacion. Los datos llegan con la Proyeccion, en UN pedido (getProyeccion):
 * Js/Alquileres.js los recibe y llama a AlquileresFacturasTab.pintar().
 *
 * EL NAVEGADOR NO DECIDE NADA. Que reemplaza una imputacion, cuanto se paga y
 * cuando lo resuelve el servidor (Class/AlquileresImputacion.php). Aca se arma
 * el pedido -facturas de UNA razon social, todos sus locales o uno, el mes y
 * las retenciones por tipo-, se suman los totales en vivo para guiar y
 * bloquear botones, y se pide la previsualizacion, que es la que vale: el
 * servidor la vuelve a correr al guardar contra los pendientes de ese momento.
 *
 * UNA SOLA RAZON SOCIAL: al tildar la primera factura, las de las otras
 * razones quedan deshabilitadas, con el porque en el tooltip.
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
    var retenciones = [];
    var previsto = null;

    var ESTADOS_PENDIENTES = ['SIN_IMPUTAR', 'IMPUTADA', 'PARCIAL', 'SUSPENDIDA'];
    var ESTADOS_PAGADAS = ['PAGADA', 'PAGADA_ANTERIOR'];
    /* Las que se pueden tildar e imputar: las pendientes y las ya pagadas del mes que nadie imputo. */
    var ESTADOS_IMPUTABLES = ESTADOS_PENDIENTES.concat(['PAGADA_SIN_IMPUTAR']);

    /** Con permiso de edición: cambiar el vencimiento a considerar (script 53). */
    function puedeEditar() {
        return Permisos.puedeEditar('alqFactBody') && !!datos;
    }

    /**
     * Imputar y deshacer piden además las tablas del script 60. Sin ellas la
     * sub-pestaña carga, avisa en crítico nombrando el script y no deja imputar.
     */
    function puedeImputar() {
        return puedeEditar() && !!datos.tablas;
    }

    /** Excluir y volver a incluir piden la tabla del script 61. */
    function puedeExcluir() {
        return puedeEditar() && !!datos.tabla_exclusion;
    }

    /** Las que se pueden excluir: las que no suman ni estan imputadas. */
    var ESTADOS_EXCLUIBLES = ['SIN_IMPUTAR', 'PAGADA_SIN_IMPUTAR'];

    /* LAS QUE TILDA "TILDAR TODAS": las sin imputar. Una imputada (o parcial,
       o suspendida) se puede tildar a mano, pero el servidor la rechaza
       ("deshacé primero la #N"): si el tilde masivo las sumara, toda
       imputación armada con él fallaría. Son las mismas que se excluyen, asi
       que "Excluir tildadas" tambien queda habilitado. */
    var ESTADOS_TILDAR_TODAS = ESTADOS_EXCLUIBLES;

    /* ================================================================
       PINTAR
       ================================================================ */

    function pintar(d) {
        datos = d.facturas || { filas: [], imputaciones: [], kpis: {}, estados: {} };
        hoy = d.hoy;
        seleccion = {};
        /* Una recarga trae las filas de nuevo: las pagadas anteriores se vuelven a pedir si «Ver pagadas» sigue tildado. */
        pagadasCargadas = false;
        var vp = document.getElementById('alqFactVerPagadas');
        if (vp && vp.checked) { setTimeout(cargarPagadas, 0); }
        html('alqFactSinScript', datos.tablas ? '' : '<div class="alert alert-danger small py-2">'
            + '<strong>No se puede imputar:</strong> falta correr ' + esc(datos.script) + ' contra la base central. '
            + 'Las facturas se ven pero no suman, y la proyección no se reemplaza con nada.</div>');
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
        texto('alqFactKpiSinPie', (k.sin_imputar_n || 0) + ' factura(s): no suman en ningún lado hasta que se imputen'
            + (k.pagadas_sin_imputar_n ? ' · ' + k.pagadas_sin_imputar_n + ' pagada(s) del mes sin imputar por '
                + importeTexto(k.pagadas_sin_imputar_importe) : ''));
        texto('alqFactKpiImp', importe(k.imputadas_a_pagar));
        texto('alqFactKpiImpPie', (k.imputadas_n || 0) + ' factura(s) · pendiente − retención');
        texto('alqFactKpiReemp', String(datos.reemplazados || 0));
        texto('alqFactKpiExcl', importeTexto(k.excluidas_importe || 0));
        texto('alqFactKpiExclPie', (k.excluidas_n || 0) + ' factura(s) que no se pagan por acá: no suman');
    }

    function visibles() {
        var r = document.getElementById('alqFactRazon').value;
        var e = document.getElementById('alqFactEstado').value;
        var pagadas = document.getElementById('alqFactVerPagadas').checked;
        var excluidas = document.getElementById('alqFactVerExcluidas');
        var verExcluidas = excluidas ? excluidas.checked : false;

        return datos.filas.filter(function(f) {
            if (r && f.cod_provee !== r) { return false; }
            if (e && f.estado !== e) { return false; }
            /* Las pagadas del mes se ven siempre; las anteriores, con "Ver pagadas". */
            if (!pagadas && !f.del_mes && ESTADOS_PAGADAS.indexOf(f.estado) !== -1) { return false; }
            /* Las excluidas no suman en ningun lado: se ven con "Ver excluidas". */
            if (!verExcluidas && f.estado === 'EXCLUIDA' && e !== 'EXCLUIDA') { return false; }
            /* Las marcadas como llave las cuenta Llaves y Renovaciones: se ven con "Ver excluidas". */
            if (!verExcluidas && f.estado === 'LLAVE' && e !== 'LLAVE') { return false; }

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

        var razon = razonTildada();

        html('alqFactBody', filas.map(function(f) {
            var tildable = puedeImputar() && ESTADOS_IMPUTABLES.indexOf(f.estado) !== -1;
            /* UNA SOLA RAZON SOCIAL POR IMPUTACION: con una tildada, las de
               otra razón no se pueden tildar. El servidor lo vuelve a validar. */
            var otra = tildable && razon !== null && f.cod_provee !== razon;

            return '<tr data-clave="' + esc(f.clave) + '">'
                + '<td class="text-center" data-exportar-omitir>' + (tildable
                    ? '<input type="checkbox" class="form-check-input alq-fact-sel"' + (seleccion[f.clave] ? ' checked' : '')
                        + (otra ? ' disabled title="Ya tildaste facturas de ' + esc(razon) + ': una imputación es de una '
                            + 'sola razón social. Imputá esta aparte."' : '') + '>'
                    : '') + '</td>'
                + '<td title="' + esc(f.cod_provee) + '">' + esc(f.razon || f.cod_provee) + '</td>'
                + '<td title="' + esc(f.leyenda || '') + '">' + esc(f.t_comp + ' ' + f.n_comp) + '</td>'
                + '<td data-orden="' + esc(f.emision || '') + '">' + fecha(f.emision) + '</td>'
                + '<td data-orden="' + esc(f.vto_tango) + '">' + fecha(f.vto_tango) + '</td>'
                + celdaVto(f, edita)
                + '<td class="text-end" data-orden="' + (f.importe || 0) + '">' + importe(f.importe) + '</td>'
                + '<td class="text-end" data-orden="' + f.pendiente + '">' + importe(f.pendiente) + '</td>'
                + '<td class="text-end" data-orden="' + (f.retencion || 0) + '"' + tituloRetencion(f) + '>'
                +   (f.retencion === null ? '<span class="text-muted">—</span>' : importe(f.retencion)) + '</td>'
                + '<td class="text-end fw-semibold" data-orden="' + f.a_pagar + '">' + importe(f.a_pagar) + '</td>'
                + '<td>' + chipEstado(f) + accionExclusion(f) + accionLlave(f) + '</td>'
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

                var antes = razonTildada();

                if (cb.checked) { seleccion[clave] = true; } else { delete seleccion[clave]; }

                /* Cambia qué razón social está tildada: se redibuja para
                   habilitar o deshabilitar las de las otras. */
                if (antes !== razonTildada()) { dibujar(); }

                actualizarBoton();
            });
        });

        document.querySelectorAll('#alqFactBody .alq-fact-excluir').forEach(function(btn) {
            btn.addEventListener('click', function() {
                excluir([cuotaDeFila(btn)]);
            });
        });

        document.querySelectorAll('#alqFactBody .alq-fact-llave').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var clave = btn.closest('tr').getAttribute('data-clave');

                abrirLlave(datos.filas.filter(function(x) { return x.clave === clave; }));
            });
        });

        document.querySelectorAll('#alqFactBody .alq-fact-desllave').forEach(function(btn) {
            btn.addEventListener('click', function() { deshacerLlave(Number(btn.getAttribute('data-id'))); });
        });

        document.querySelectorAll('#alqFactBody .alq-fact-incluir').forEach(function(btn) {
            btn.addEventListener('click', function() {
                incluir([cuotaDeFila(btn)]);
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

    /**
     * La retención de una cuota imputada es SU PARTE del total de retenciones
     * de la imputación (repartido por pendiente). El tooltip abre los tipos.
     */
    function tituloRetencion(f) {
        var r = f.imputacion_resumen;

        if (!r || f.retencion === null) { return ''; }

        return ' title="' + esc('Su parte de las retenciones de la imputación #' + r.id + ', repartidas entre sus '
            + 'facturas por el pendiente.\nRetenciones de la imputación: '
            + (r.retenciones.length ? r.retenciones.map(function(x) { return x.nombre + ' ' + importeTexto(x.importe); }).join(', ')
                : 'ninguna') + ' (total ' + importeTexto(r.total_retenciones) + ')') + '"';
    }

    /** Excluir (una sin imputar) o volver a incluir (una excluida), por fila */
    function accionExclusion(f) {
        if (!puedeExcluir()) { return ''; }

        if (ESTADOS_EXCLUIBLES.indexOf(f.estado) !== -1) {
            return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 alq-fact-excluir" data-exportar-omitir '
                + 'title="No se paga por este circuito (por ejemplo, un pago de contrato): sale de todos los totales">'
                + 'Excluir</button>';
        }

        if (f.estado === 'EXCLUIDA') {
            return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 alq-fact-incluir" data-exportar-omitir '
                + 'title="Vuelve a Sin imputar. La exclusión queda en el historial">Volver a incluir</button>';
        }

        return '';
    }

    function cuotaDeFila(el) {
        var f = datos.filas.filter(function(x) { return x.clave === el.closest('tr').getAttribute('data-clave'); })[0];

        return { cod_provee: f.cod_provee, t_comp: f.t_comp, n_comp: f.n_comp, vto_tango: f.vto_tango,
                 a_imputar: f.a_imputar };
    }

    /**
     * Excluir una o varias cuotas con UN motivo, obligatorio: una factura que
     * sale del cashflow tiene que decir por qué. El servidor lo vuelve a validar.
     */
    function excluir(cuotas) {
        var total = cuotas.reduce(function(a, c) { return a + (c.a_imputar || 0); }, 0);

        Notificacion.pedirTexto({
            titulo: 'Excluir factura(s) de alquiler',
            mensaje: cuotas.length + ' factura(s) por ' + importeTexto(total) + ' dejan de sumar en Alquileres y en el '
                + 'tablero, y no vuelven a Proveedores Locales.',
            detalle: 'Es para las que no se pagan por este circuito (por ejemplo, un pago de contrato). Se puede volver '
                + 'a incluir: queda en el historial.',
            etiqueta: 'Motivo',
            maxlargo: 300,
            invalido: 'El motivo es obligatorio.',
            confirmar: 'Excluir',
            peligro: true
        }).then(function(motivo) {
            if (motivo === null) { return; }

            seleccion = {};
            guardar('excluirFacturas', { cuotas: cuotas, motivo: motivo });
        });
    }

    function incluir(cuotas) {
        Notificacion.confirmar({
            titulo: 'Volver a incluir',
            mensaje: '¿Vuelve a Sin imputar?',
            detalle: 'Mientras no se impute no suma. La exclusión queda en el historial.',
            confirmar: 'Volver a incluir'
        }).then(function(si) {
            if (si) { guardar('incluirFacturas', { cuotas: cuotas }); }
        });
    }

    function chipEstado(f) {
        var cls = { SIN_IMPUTAR: 'bg-danger', IMPUTADA: 'bg-success', PARCIAL: 'bg-warning text-dark',
                    PAGADA: 'bg-secondary', ANULADA: 'bg-warning text-dark', SIN_DATO: 'bg-warning text-dark',
                    SUSPENDIDA: 'bg-warning text-dark', PAGADA_SIN_IMPUTAR: 'bg-danger',
                    EXCLUIDA: 'bg-dark', LLAVE: 'bg-info text-dark', PAGADA_ANTERIOR: 'bg-secondary' }[f.estado] || 'bg-light text-dark';
        var t = datos.estados[f.estado] || f.estado;
        var r = f.imputacion_resumen;
        var tip = f.imputacion ? 'Imputación #' + f.imputacion
            + (r ? ' · cubre ' + rotuloMes(r.mes) + ' · ' + textoLocales(r) : '') + '\n'
            + Auditoria.texto({ alta: { usuario: f.imputacion_usuario, fecha: f.imputacion_fecha } }) : '';

        if ((f.estado === 'PAGADA' || f.estado === 'PAGADA_SIN_IMPUTAR') && f.fecha_pagada) {
            tip += '\nPagada en Tango el ' + fecha(f.fecha_pagada);
        }
        if (f.estado === 'EXCLUIDA' && f.excluida) {
            tip += 'No se paga por este circuito: no suma en ningún lado.\nMotivo: ' + f.excluida.motivo + '\n'
                + Auditoria.texto({ alta: { usuario: f.excluida.usuario, fecha: f.excluida.fecha } });
        }
        if (f.estado === 'LLAVE' && f.llave) {
            tip += 'Marcada como ' + ((datos.nombres_llave || {})[f.llave.concepto] || f.llave.concepto).toLowerCase()
                + ' de ' + f.llave.local + ', ' + rotuloMes(f.llave.mes) + (f.llave.pagos ? ', en ' + f.llave.pagos + ' pago(s)' : '')
                + ': la cuenta Llaves y Renovaciones, no suma acá.\n'
                + Auditoria.texto({ alta: { usuario: f.llave.usuario, fecha: f.llave.fecha } });
        }
        if (f.estado === 'PAGADA_ANTERIOR') {
            tip += 'Pagada en Tango' + (f.fecha_pagada ? ' el ' + fecha(f.fecha_pagada) : '') + ' y sin imputar. Se puede marcar '
                + 'como llave para cortar lo proyectado (no suma).';
        }
        if (f.estado === 'PAGADA_SIN_IMPUTAR') {
            tip += '\nNo se paga otra vez, pero la proyección de lo que cubre sigue en el flujo hasta que se impute '
                + '(por su importe, ' + importeTexto(f.a_imputar) + ').';
        }
        if (f.pendiente_al_imputar !== null && f.estado !== 'PAGADA' && Math.abs(f.pendiente_al_imputar - f.pendiente) > 0.005) {
            tip += '\nAl imputar el pendiente era ' + importeTexto(f.pendiente_al_imputar) + ': revisá la imputación.';
        }

        return '<span class="badge ' + cls + '" title="' + esc(tip) + '">' + esc(t) + '</span>';
    }

    /**
     * El vencimiento a considerar: la fecha manual, o el día de Parámetros de la
     * razón social en el mes del vencimiento de Tango. Input que guarda al
     * cambiar, mínimo hoy, y ↺ a la de Parámetros.
     */
    function celdaVto(f, edita) {
        var ed = f.vto_editado;
        var sinEditar = f.vto_origen === 'TANGO'
            ? 'el de Tango (la razón social no tiene día de vencimiento en Parámetros, ni hay uno general)'
            : 'el ' + fecha(f.vto_parametro) + ', por el día de vencimiento de la razón social en Parámetros';
        var titulo = ed
            ? 'Cambiado en esta pestaña. Sin el cambio sería ' + sinEditar + '. En Tango vence el '
              + fecha(f.vto_tango) + '.'
              + (ed.MOTIVO ? '\nMotivo: ' + ed.MOTIVO : '')
              + '\n' + Auditoria.texto({ alta: { usuario: ed.USUARIO_ALTA, fecha: ed.FECHA_ALTA } })
            : (f.vto_origen === 'TANGO' ? 'El de Tango: la razón social no tiene día de vencimiento en '
                + 'Parámetros, ni hay uno general.'
              : 'El día de vencimiento de la razón social en Parámetros (o el general), en el mes del '
                + 'vencimiento de Tango (' + fecha(f.vto_tango) + ').')
              + '\nEs la fecha en que se paga si está imputada.';
        var chip = ed ? ' <span class="badge bg-info text-dark">cambiado</span>'
            : (f.vto_origen === 'TANGO' ? ' <span class="badge bg-light text-dark border">Tango</span>' : '');
        var nota = f.nota_fecha ? '<div class="small text-muted">' + esc(f.nota_fecha) + '</div>' : '';

        if (!edita || ESTADOS_PENDIENTES.indexOf(f.estado) === -1) {
            return '<td data-orden="' + esc(f.vto_considerar) + '" title="' + esc(titulo) + '">'
                + fecha(f.vto_considerar) + chip + nota + '</td>';
        }

        return '<td data-orden="' + esc(f.vto_considerar) + '">'
            + '<div class="input-group input-group-sm flex-nowrap">'
            +   '<input type="date" class="form-control form-control-sm alq-vto" value="' + esc(f.vto_considerar) + '"'
            +   ' min="' + esc(hoy) + '" data-cod="' + esc(f.cod_provee) + '" data-tcomp="' + esc(f.t_comp) + '"'
            +   ' data-ncomp="' + esc(f.n_comp) + '" data-vto-tango="' + esc(f.vto_tango) + '"'
            +   ' data-vto-base="' + esc(f.vto_parametro) + '"'
            +   ' data-vigente="' + esc(f.vto_considerar) + '" title="' + esc(titulo) + '">'
            +   (ed ? '<button class="btn btn-outline-secondary alq-vto-volver" type="button" title="Volver al '
                    + 'vencimiento sin editar (' + esc(fecha(f.vto_parametro)) + ')"><i class="fas fa-rotate-left"></i></button>' : '')
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
            detalle: 'En Tango vence el ' + fecha(cuota.vto_tango) + ' y ahí no cambia. Elegir el '
                + fecha(input.getAttribute('data-vto-base')) + ' quita la fecha manual.',
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

    /** Las filas que se ven y que "tildar todas" tildaría. */
    function candidatasTodas() {
        if (!puedeImputar()) { return []; }

        return visibles().filter(function(f) { return ESTADOS_TILDAR_TODAS.indexOf(f.estado) !== -1; });
    }

    /**
     * TILDAR TODAS, sobre lo que se ve con el filtro. UNA IMPUTACIÓN ES DE UNA
     * SOLA RAZÓN SOCIAL: la casilla se habilita sólo si las que se ven son
     * todas de la misma y, si ya hay algo tildado, de esa. Sin permiso de
     * imputar no se ve, como los tildes de cada fila.
     */
    function pintarSelTodas() {
        var chk = document.getElementById('alqFactSelTodas');

        if (!chk) { return; }

        chk.style.display = puedeImputar() ? '' : 'none';

        var fs = candidatasTodas();
        var razones = {};
        var tildada = razonTildada();

        fs.forEach(function(f) { razones[f.cod_provee] = f.razon || f.cod_provee; });

        var cods = Object.keys(razones);
        var elegidas = fs.filter(function(f) { return !!seleccion[f.clave]; }).length;

        chk.disabled = cods.length !== 1 || (tildada !== null && tildada !== cods[0]);
        chk.checked = !chk.disabled && elegidas === fs.length;
        chk.indeterminate = !chk.disabled && elegidas > 0 && elegidas < fs.length;
        chk.title = !fs.length ? 'No hay facturas sin imputar a la vista'
            : cods.length > 1 ? 'Se ven facturas de más de una razón social: filtrá por una para tildar todas'
            : chk.disabled ? 'Ya tildaste facturas de ' + tildada + ': una imputación es de una sola razón social'
            : (chk.checked ? 'Destildar' : 'Tildar') + ' las ' + fs.length + ' factura(s) sin imputar de '
                + razones[cods[0]] + ' que se ven';
    }

    function actualizarBoton() {
        var b = document.getElementById('btnAlqImputar');
        var x = document.getElementById('btnAlqExcluir');
        var ll = document.getElementById('btnAlqLlave');

        /* El encabezado sigue a lo tildado: cada cambio de tilde pasa por acá. */
        pintarSelTodas();

        if (b) {
            b.style.display = puedeImputar() ? '' : 'none';
            b.disabled = !Object.keys(seleccion).length;
        }

        /* EXCLUIR LAS TILDADAS: solo si todas son excluibles (sin imputar). */
        if (x) {
            var fs = tildadas();

            x.style.display = puedeExcluir() ? '' : 'none';
            x.disabled = !fs.length || fs.some(function(f) { return ESTADOS_EXCLUIBLES.indexOf(f.estado) === -1; });
        }

        /* MARCAR LAS TILDADAS COMO LLAVE: también sólo si todas son marcables. */
        if (ll) {
            var ts = tildadas();

            ll.style.display = puedeMarcarLlave() ? '' : 'none';
            ll.disabled = !ts.length || ts.some(function(f) { return ESTADOS_MARCABLES.indexOf(f.estado) === -1; });
        }
    }

    /* ================================================================
       ES LLAVE / RENOVACIÓN (script 68)

       La cuota sale de Alquileres, no vuelve a Proveedores Locales y la cuenta
       Llaves y Renovaciones. Se pide el local, el concepto y el mes; de a UNA
       factura pendiente, en cuántos pagos se paga (la factura no es el pago).
       Lo que vale es la previsualización del servidor (LlavesFacturas::validar()).
       ================================================================ */

    /** Las que se pueden marcar: las que no suman ni están imputadas, y las pagadas de meses anteriores. */
    var ESTADOS_MARCABLES = ['SIN_IMPUTAR', 'PAGADA_SIN_IMPUTAR', 'PAGADA_ANTERIOR'];
    var marcando = [];
    var pagosLlave = [];
    var previstaLlave = null;
    var pagadasCargadas = false;

    function puedeMarcarLlave() {
        return puedeEditar() && !!datos.tabla_llaves;
    }

    function accionLlave(f) {
        if (!puedeMarcarLlave()) { return ''; }

        if (ESTADOS_MARCABLES.indexOf(f.estado) !== -1) {
            return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 alq-fact-llave" data-exportar-omitir '
                + 'title="Es una llave, una renovación o una obra: sale de Alquileres y la cuenta Llaves y Renovaciones">'
                + 'Es llave</button>';
        }

        if (f.estado === 'LLAVE' && f.llave) {
            return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 alq-fact-desllave" data-exportar-omitir '
                + 'data-id="' + f.llave.id + '" title="Vuelve a Sin imputar en Alquileres; lo proyectado en Llaves vuelve">'
                + 'Deshacer</button>';
        }

        return '';
    }

    function abrirLlave(fs) {
        if (!fs.length) { return; }

        marcando = fs;
        pagosLlave = [];
        previstaLlave = null;

        html('alqLlaveFacturas', fs.map(function(f) {
            return '<tr><td title="' + esc(f.leyenda || '') + '">' + esc(f.razon || f.cod_provee) + ' · '
                + esc(f.t_comp + ' ' + f.n_comp) + (f.leyenda ? ' <small class="text-muted">«' + esc(f.leyenda) + '»</small>' : '')
                + '</td><td>' + fecha(f.vto_considerar || f.vto_tango) + '</td><td class="text-end">' + importe(f.a_imputar)
                + (f.estado !== 'SIN_IMPUTAR' ? ' <span class="badge bg-secondary" title="Ya pagada en Tango: no suma, '
                    + 'corta lo proyectado">pagada</span>' : '') + '</td></tr>';
        }).join(''));

        var loc = datos.locales_app || {};
        document.getElementById('alqLlaveLocal').innerHTML = '<option value="">Elegí el local</option>'
            + Object.keys(loc).sort(function(a, b) { return String(loc[a]).localeCompare(String(loc[b])); }).map(function(n) {
                return '<option value="' + esc(n) + '">' + esc(loc[n] + ' (' + n + ')') + '</option>';
            }).join('');

        var con = datos.conceptos_llave || {};
        document.getElementById('alqLlaveConcepto').innerHTML = Object.keys(con).map(function(k) {
            return '<option value="' + esc(k) + '">' + esc(con[k]) + '</option>';
        }).join('');

        document.getElementById('alqLlaveMes').value = datos.mes_actual || '';
        document.getElementById('alqLlaveRetencion').value = '';
        document.getElementById('alqLlaveObs').value = '';

        /* REPARTIR SÓLO DE A UNA FACTURA PENDIENTE: con varias, o una ya pagada,
           cada una va entera en su vencimiento a considerar. */
        var reparte = fs.length === 1 && fs[0].estado === 'SIN_IMPUTAR';
        document.getElementById('alqLlavePagosCaja').style.display = reparte ? '' : 'none';
        dibujarPagosLlave();
        cambioLlave();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlqLlave')).show();
    }

    function dibujarPagosLlave() {
        var suma = pagosLlave.reduce(function(a, p) { return a + (Number(String(p.importe).replace(',', '.')) || 0); }, 0);

        html('alqLlavePagos', pagosLlave.length ? '<table class="table table-sm small mb-1"><thead><tr><th>#</th><th>Fecha</th>'
            + '<th class="text-end">Importe</th><th></th></tr></thead><tbody>' + pagosLlave.map(function(p, i) {
                return '<tr><td>' + (i + 1) + '</td>'
                    + '<td><input type="date" class="form-control form-control-sm alq-llave-pago" data-i="' + i + '" data-campo="fecha" value="' + esc(p.fecha) + '"></td>'
                    + '<td><input type="text" class="form-control form-control-sm text-end alq-llave-pago" data-i="' + i + '" data-campo="importe" inputmode="decimal" value="' + esc(p.importe) + '"></td>'
                    + '<td><button type="button" class="btn btn-link btn-sm text-danger p-0 alq-llave-quitar" data-i="' + i + '"><i class="fas fa-times"></i></button></td></tr>';
            }).join('') + '</tbody><tfoot><tr class="fw-semibold"><td colspan="2">Suman</td><td class="text-end">'
            + importe(suma) + '</td><td></td></tr></tfoot></table>'
            : '<small class="text-muted">Sin pagos: va entera en su vencimiento a considerar.</small>');

        document.querySelectorAll('#alqLlavePagos .alq-llave-pago').forEach(function(el) {
            el.addEventListener('change', function() {
                pagosLlave[Number(el.getAttribute('data-i'))][el.getAttribute('data-campo')] = el.value;
                dibujarPagosLlave();
                cambioLlave();
            });
        });

        document.querySelectorAll('#alqLlavePagos .alq-llave-quitar').forEach(function(btn) {
            btn.addEventListener('click', function() {
                pagosLlave.splice(Number(btn.getAttribute('data-i')), 1);
                dibujarPagosLlave();
                cambioLlave();
            });
        });
    }

    /** Cualquier cambio invalida la previsualización: Marcar se apaga hasta volver a previsualizar. */
    function cambioLlave() {
        previstaLlave = null;
        document.getElementById('btnAlqLlaveGuardar').disabled = true;
        html('alqLlaveResultado', '');
    }

    function borradorLlave() {
        return {
            cuotas: marcando.map(function(f) { return { cod_provee: f.cod_provee, t_comp: f.t_comp, n_comp: f.n_comp, vto_tango: f.vto_tango }; }),
            nro: document.getElementById('alqLlaveLocal').value,
            concepto: document.getElementById('alqLlaveConcepto').value,
            mes: document.getElementById('alqLlaveMes').value,
            retencion: document.getElementById('alqLlavePagosCaja').style.display === 'none' ? '' : document.getElementById('alqLlaveRetencion').value,
            pagos: pagosLlave.map(function(p) { return { fecha: p.fecha, importe: String(p.importe).replace(',', '.') }; }),
            observacion: document.getElementById('alqLlaveObs').value
        };
    }

    function previsualizarLlave(repartir) {
        var b = borradorLlave();

        if (repartir) { b.pagos = []; }

        pedirJson(ENDPOINT + '?action=previsualizarLlave', b)
            .then(function(d) {
                var v = d.data;

                if (repartir) {
                    pagosLlave = (v.pagos_defecto || []).map(function(p) { return { fecha: p.fecha, importe: String(p.importe) }; });
                    dibujarPagosLlave();
                    cambioLlave();

                    return;
                }

                previstaLlave = v.ok ? v : null;
                document.getElementById('btnAlqLlaveGuardar').disabled = !v.ok;
                html('alqLlaveResultado', v.ok
                    ? '<div class="alert alert-success small py-2 mb-0">' + esc(marcando.length + ' factura(s) por ' + importeTexto(v.total)
                        + ' como ' + ((datos.nombres_llave || {})[v.concepto] || v.concepto).toLowerCase() + ' de ' + v.local
                        + (v.renovacion ? ': reemplaza lo proyectado de la ' + v.renovacion : ', obra de ' + v.mes)
                        + '. ' + (v.pagada ? 'Ya pagada: no suma, sólo corta lo proyectado.'
                            : (v.pagos.length ? 'Se paga en ' + v.pagos.length + ' pago(s).' : 'Va entera en su vencimiento a considerar.'))) + '</div>'
                    : '<div class="alert alert-danger small py-2 mb-0">' + v.errores.map(esc).join('<br>') + '</div>');
            })
            .catch(function(e) { Notificacion.error(e.message); });
    }

    function guardarLlave() {
        if (!previstaLlave) { return; }

        pedirJson(ENDPOINT + '?action=marcarLlave', borradorLlave())
            .then(function(d) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlqLlave')).hide();
                seleccion = {};
                Notificacion.exito(d.message || 'Listo.');
                recargar();
            })
            .catch(function(e) { Notificacion.error(e.message); });
    }

    function deshacerLlave(id) {
        Notificacion.confirmar({
            titulo: 'Deshacer la marca de llave',
            mensaje: '¿La factura vuelve a Sin imputar en Alquileres?',
            detalle: 'Lo proyectado en Llaves y Renovaciones vuelve. La marca queda en el historial.',
            confirmar: 'Deshacer'
        }).then(function(si) {
            if (si) { guardar('deshacerLlave', { id: id }); }
        });
    }

    /**
     * LAS PAGADAS DE MESES ANTERIORES (los últimos 12) se piden al tildar «Ver
     * pagadas», una vez: se ven para marcarlas como llave, que corta la
     * estimación sin sumar.
     */
    function cargarPagadas() {
        if (pagadasCargadas || !puedeMarcarLlave()) { return; }

        pagadasCargadas = true;
        pedirJson(ENDPOINT + '?action=getPagadasLlave', {})
            .then(function(d) {
                var ya = {};

                datos.filas.forEach(function(f) { ya[f.clave] = true; });
                (d.data.filas || []).forEach(function(p) {
                    if (ya[p.clave]) { return; }

                    datos.filas.push({ clave: p.clave, cod_provee: p.cod_provee, razon: p.razon, t_comp: p.t_comp,
                        n_comp: p.n_comp, leyenda: p.leyenda, emision: p.emision, vto_tango: p.vto_tango,
                        vto_considerar: p.vto_tango, vto_origen: 'TANGO', importe: p.importe, pendiente: 0,
                        a_imputar: p.a_imputar, retencion: null, a_pagar: 0, estado: 'PAGADA_ANTERIOR', del_mes: false,
                        fecha_pagada: p.fecha_pagada, pendiente_al_imputar: null });
                });
                datos.estados.PAGADA_ANTERIOR = 'Pagada, de un mes anterior';
                llenarFiltros();
                dibujar();
            })
            .catch(function(e) { pagadasCargadas = false; Notificacion.error('No se pudieron leer las pagadas: ' + e.message); });
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

        html('alqImputaciones', '<table class="table table-sm small mb-0"><thead><tr><th>#</th><th>Razón social</th>'
            + '<th>Cubre</th><th>Facturas</th><th>Retenciones</th><th class="text-end">A pagar</th><th>Cargó</th><th></th>'
            + '</tr></thead><tbody>'
            + lista.map(function(imp) {
                return '<tr' + (imp.suspendida ? ' class="table-warning"' : '') + '><td>' + imp.id + '</td>'
                    + '<td>' + esc(imp.razon || imp.cod_provee) + ' <span class="text-muted">' + esc(imp.cod_provee) + '</span></td>'
                    + '<td>' + esc(rotuloMes(imp.mes)) + (imp.fuera_horizonte ? ' <span class="text-muted">(fuera del horizonte)</span>' : '')
                    + '<br><span class="text-muted">' + esc(textoLocales(imp)) + '</span></td>'
                    + '<td>' + Object.keys(imp.facturas).map(function(k) {
                        var f = imp.facturas[k];

                        return esc(f.comprobante) + ' · ' + importe(f.pendiente)
                            + (f.estado === 'PAGADA' ? ' <span class="badge bg-secondary">pagada</span>' : '');
                    }).join('<br>') + '</td>'
                    + '<td>' + (imp.retenciones.length ? imp.retenciones.map(function(r) {
                        return esc(r.nombre) + ' ' + importe(r.importe);
                    }).join('<br>') : '<span class="text-muted">sin retenciones</span>') + '</td>'
                    + '<td class="text-end fw-semibold">' + importe(imp.total_a_pagar) + '</td>'
                    + '<td>' + esc(imp.usuario || '—') + '<br><span class="text-muted">' + esc(imp.fecha || '') + '</span>'
                    + (imp.observacion ? '<br><em>' + esc(imp.observacion) + '</em>' : '') + '</td>'
                    + '<td class="text-end">' + (puedeImputar()
                        ? '<button class="btn btn-sm btn-outline-danger py-0 alq-imp-deshacer" data-id="' + imp.id + '">Deshacer</button>'
                        : '') + '</td></tr>';
            }).join('') + '</tbody></table>');

        document.querySelectorAll('#alqImputaciones .alq-imp-deshacer').forEach(function(b) {
            b.addEventListener('click', function() {
                var id = b.getAttribute('data-id');

                Notificacion.confirmar({
                    titulo: 'Deshacer la imputación #' + id,
                    mensaje: '¿Deshacer la imputación #' + id + '?',
                    detalle: 'Sus facturas vuelven a Sin imputar —y dejan de sumar— y la proyección que '
                        + 'reemplazaba vuelve. No se borra: queda en el historial. Para corregirla, se arma de nuevo.',
                    confirmar: 'Deshacer',
                    peligro: true
                }).then(function(ok) {
                    if (ok) { guardar('deshacerImputacion', { id: Number(id) }); }
                });
            });
        });
    }

    /** "Todos los locales de X (N)" o la lista de locales */
    function textoLocales(imp) {
        var nombres = imp.locales.map(function(l) { return l.nombre; }).join(', ');

        return imp.todos ? 'todos los locales (' + nombres + ')' : nombres;
    }

    /* ================================================================
       EL ARMADO DE UNA IMPUTACION

       Facturas de UNA razón social, todos sus locales o uno, un mes y las
       retenciones por tipo. No se eligen conceptos: la imputación pisa todo
       lo que esa razón social factura en esos locales ese mes.
       ================================================================ */

    function tildadas() {
        return datos.filas.filter(function(f) { return seleccion[f.clave]; });
    }

    /** La razón social de lo tildado: una sola. Null si no hay nada tildado. */
    function razonTildada() {
        var fs = tildadas();

        return fs.length ? fs[0].cod_provee : null;
    }

    /** Los locales de una razón social: donde es principal o adicional */
    function localesDe(cod) {
        return (datos.locales || []).filter(function(l) {
            return (l.razones || [l.cod_provee]).indexOf(cod) !== -1;
        });
    }

    function abrirImputar() {
        var fs = tildadas();

        if (!fs.length) { return; }

        var cod = fs[0].cod_provee;
        var locales = localesDe(cod);

        previsto = null;
        retenciones = [];

        html('alqImpRazon', esc((fs[0].razon || cod) + ' (' + cod + ')'));
        html('alqImpFacturas', fs.map(function(f) {
            return '<tr><td>' + esc(f.t_comp + ' ' + f.n_comp) + '</td><td>' + fecha(f.vto_considerar) + '</td>'
                + '<td class="text-end">' + importe(f.a_imputar)
                + (f.estado === 'PAGADA_SIN_IMPUTAR' ? ' <span class="badge bg-secondary" title="Ya pagada en Tango: '
                    + 'no se paga otra vez, pero reemplaza el mes">pagada</span>' : '') + '</td></tr>';
        }).join(''));

        document.getElementById('alqImpLocal').innerHTML = '<option value="">Todos los locales de '
            + esc(fs[0].razon || cod) + ' (' + locales.length + ')</option>'
            + locales.map(function(l) { return '<option value="' + l.nro + '">' + esc(l.nombre) + '</option>'; }).join('');

        document.getElementById('alqImpMes').innerHTML = (datos.meses || []).map(function(m) {
            return '<option value="' + esc(m.clave) + '"' + (m.clave === datos.mes_actual ? ' selected' : '') + '>'
                + esc(m.nombre) + (m.clave < datos.mes_actual ? ' (mes anterior: no pisa nada del horizonte)' : '')
                + '</option>';
        }).join('');

        document.getElementById('alqImpObs').value = '';
        dibujarRetenciones();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAlqImputar')).show();
    }

    function totalFacturas() {
        return Math.round(tildadas().reduce(function(a, f) { return a + f.a_imputar; }, 0) * 100) / 100;
    }

    /**
     * Las retenciones, una línea por tipo. EL DESPLEGABLE NO OFRECE UN TIPO YA
     * ELEGIDO EN OTRA LÍNEA: un tipo va una sola vez (el servidor lo vuelve a
     * validar). Sin retenciones es válido.
     */
    function dibujarRetenciones() {
        var tipos = datos.tipos_retencion || [];

        html('alqImpRetenciones', retenciones.length ? retenciones.map(function(r, i) {
            var usados = retenciones.filter(function(x, j) { return j !== i && x.tipo; }).map(function(x) { return String(x.tipo); });

            return '<div class="row g-2 mb-1 alq-imp-ret" data-i="' + i + '">'
                + '<div class="col-md-6"><select class="form-select form-select-sm alq-imp-ret-tipo">'
                +   '<option value="">Tipo de retención…</option>'
                +   tipos.filter(function(t) { return usados.indexOf(String(t.id)) === -1; }).map(function(t) {
                        return '<option value="' + t.id + '"' + (String(r.tipo) === String(t.id) ? ' selected' : '') + '>'
                            + esc(t.nombre) + '</option>';
                    }).join('') + '</select></div>'
                + '<div class="col-md-4"><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end '
                +   'alq-imp-ret-importe" value="' + esc(r.importe) + '" placeholder="Importe"></div>'
                + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-link text-danger alq-imp-ret-quitar">'
                +   'Quitar</button></div></div>';
        }).join('') : '<div class="small text-muted">Sin retenciones.</div>');

        document.querySelectorAll('#alqImpRetenciones .alq-imp-ret').forEach(function(div) {
            var i = Number(div.getAttribute('data-i'));

            div.querySelector('.alq-imp-ret-tipo').addEventListener('change', function(ev) {
                retenciones[i].tipo = ev.target.value;
                dibujarRetenciones();
            });
            div.querySelector('.alq-imp-ret-importe').addEventListener('input', function(ev) {
                retenciones[i].importe = ev.target.value;
                cambio();
            });
            div.querySelector('.alq-imp-ret-quitar').addEventListener('click', function() {
                retenciones.splice(i, 1);
                dibujarRetenciones();
            });
        });

        var mas = document.getElementById('btnAlqImpRetencion');

        if (mas) {
            mas.disabled = retenciones.length >= tipos.length;
            mas.title = mas.disabled ? 'Ya están todos los tipos de retención' : 'Agregar una retención';
        }

        cambio();
    }

    /**
     * Los totales en vivo y lo que bloquea Guardar: facturas, retenciones y a
     * pagar. Cualquier cambio invalida la previsualización.
     */
    function cambio() {
        previsto = null;
        document.getElementById('btnAlqImpGuardar').disabled = true;

        var tf = totalFacturas();
        var tr = Math.round(retenciones.reduce(function(a, r) { return a + (Number(r.importe) || 0); }, 0) * 100) / 100;
        var excede = tr > tf + 0.005;

        html('alqImpTotales', '<span>Total facturas: <strong>' + importe(tf) + '</strong></span>'
            + '<span>Total retenciones: <strong>' + importe(tr) + '</strong></span>'
            + '<span class="' + (excede ? 'text-danger' : '') + '">Total a pagar: <strong>' + importe(tf - tr) + '</strong>'
            + (excede ? ' — las retenciones superan las facturas' : '') + '</span>');
        html('alqImpResultado', '');
        document.getElementById('btnAlqImpPrevisualizar').disabled = excede;
    }

    function borrador() {
        var local = document.getElementById('alqImpLocal').value;

        return {
            facturas: tildadas().map(function(f) {
                return { cod_provee: f.cod_provee, t_comp: f.t_comp, n_comp: f.n_comp, vto_tango: f.vto_tango,
                         pendiente: f.a_imputar };
            }),
            todos: local === '',
            local: local === '' ? null : Number(local),
            mes: document.getElementById('alqImpMes').value,
            retenciones: retenciones.map(function(r) { return { tipo: Number(r.tipo) || 0, importe: Number(r.importe) || 0 }; }),
            observacion: document.getElementById('alqImpObs').value
        };
    }

    function previsualizar() {
        pedirJson(ENDPOINT + '?action=previsualizarImputacion', borrador())
            .then(function(d) {
                var v = d.data;

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
            h += '<div class="small"><strong>Qué reemplaza:</strong> todo lo proyectado de ' + esc(v.cod_provee)
                + ' en ' + v.locales.length + ' local(es) en ' + esc(rotuloMes(v.mes)) + ': ' + importe(v.proyectado)
                + ' (' + v.pisa_n + ' pago(s)). Manda lo real: se paga ' + importe(v.total_a_pagar) + ', sin saldo.'
                + '<ul class="mb-0 mt-1">' + v.facturas.map(function(f) {
                    return '<li>' + esc(f.nombre) + ': ' + importe(f.pendiente) + ' − retenciones ' + importe(f.retencion)
                        + ' = ' + importe(f.a_pagar) + (f.pagada ? ' <em>(ya pagada en Tango: no se paga otra vez)</em>' : '')
                        + '</li>';
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
        ['alqFactRazon', 'alqFactEstado', 'alqFactVerPagadas', 'alqFactVerExcluidas'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) { el.addEventListener('change', function() { if (datos) { dibujar(); } }); }
        });

        var todas = document.getElementById('alqFactSelTodas');
        if (todas) {
            todas.addEventListener('change', function() {
                if (!datos) { return; }

                candidatasTodas().forEach(function(f) {
                    if (todas.checked) { seleccion[f.clave] = true; } else { delete seleccion[f.clave]; }
                });
                /* Se redibuja: cambian los tildes de las filas y, con ellos, qué
                   razones sociales quedan deshabilitadas. */
                dibujar();
            });
        }

        var b = document.getElementById('btnAlqImputar');
        if (b) { b.addEventListener('click', abrirImputar); }

        var x = document.getElementById('btnAlqExcluir');
        if (x) {
            x.addEventListener('click', function() {
                excluir(tildadas().map(function(f) {
                    return { cod_provee: f.cod_provee, t_comp: f.t_comp, n_comp: f.n_comp, vto_tango: f.vto_tango,
                             a_imputar: f.a_imputar };
                }));
            });
        }

        var ll = document.getElementById('btnAlqLlave');
        if (ll) { ll.addEventListener('click', function() { abrirLlave(tildadas()); }); }

        var vp = document.getElementById('alqFactVerPagadas');
        if (vp) { vp.addEventListener('change', function() { if (vp.checked && datos) { cargarPagadas(); } }); }

        ['alqLlaveLocal', 'alqLlaveConcepto', 'alqLlaveMes', 'alqLlaveRetencion', 'alqLlaveObs'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('change', cambioLlave); }
        });

        var lp = document.getElementById('btnAlqLlavePago');
        if (lp) { lp.addEventListener('click', function() { pagosLlave.push({ fecha: '', importe: '' }); dibujarPagosLlave(); cambioLlave(); }); }

        var lr = document.getElementById('btnAlqLlaveRepartir');
        if (lr) { lr.addEventListener('click', function() { previsualizarLlave(true); }); }

        var lv = document.getElementById('btnAlqLlavePrevisualizar');
        if (lv) { lv.addEventListener('click', function() { previsualizarLlave(false); }); }

        var lg = document.getElementById('btnAlqLlaveGuardar');
        if (lg) { lg.addEventListener('click', guardarLlave); }

        var r = document.getElementById('btnAlqImpRetencion');
        if (r) { r.addEventListener('click', function() { retenciones.push({ tipo: '', importe: '' }); dibujarRetenciones(); }); }

        ['alqImpLocal', 'alqImpMes'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('change', cambio); }
        });

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
