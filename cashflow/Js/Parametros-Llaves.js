/**
 * Parametros -> Llaves y Renovaciones
 *
 * Siete secciones: generales, por operador, por local, ya pagadas, obras,
 * aperturas y compromisos.
 *
 * LA VALIDACION MANDA EN EL SERVIDOR (LlavesParametros). Aca solo se explica y
 * se bloquean botones: lo que el servidor rechaza vuelve con su mensaje, tal
 * cual, y la pantalla se recarga con lo guardado.
 *
 * GENERALES, OPERADORES Y LOCALES SE GUARDAN AL SALIR DEL CAMPO, de a una
 * clave o de a una fila, como Parametros -> Alquileres. Obras, aperturas,
 * compromisos y ya pagadas son altas: se cargan con su formulario y se dan de
 * baja; no se editan.
 *
 * LO SUGERIDO VA COMO PLACEHOLDER, en gris, con el tooltip de donde sale. Un
 * placeholder no se guarda: lo confirma una persona escribiendolo (o con el
 * boton de los cortes).
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/LlavesController.php';
    var datos = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        var descripcion = ParametrosCarga.pedir('LLAVES_RENOV')
            .then(function(data) {
                var modulo = buscarModulo(data, 'LLAVES_RENOV');
                var desc = document.getElementById('pllDescripcion');

                if (desc && modulo && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + esc(modulo.descripcion);
                }
            })
            .catch(function() { /* Contexto, no dato. */ });

        Cargando.mostrar('pllCargando');

        var pedido = pedir(ENDPOINT + '?action=getParametros')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintar('pllAvisos', datos.avisos || []);
                pintarTodo();
            })
            .catch(function(e) {
                AvisosPanel.pintar('pllAvisos', [{ nivel: 'danger', texto: 'No se pudieron leer los parámetros de Llaves y Renovaciones: ' + e.message }]);

                return false;
            })
            .then(function(r) {
                Cargando.ocultar('pllCargando');

                return r;
            });

        return Promise.all([descripcion, pedido]).then(function(r) { return r[1]; });
    }

    function pintarTodo() {
        pintarGenerales();
        pintarOperadores();
        pintarLocales();
        pintarFormularios();
        pintarPagadas();
        pintarObras();
        pintarAperturas();
        pintarCompromisos();
        Permisos.soloLectura(document.querySelector('.pll-modulo'));
        bloquearSinTabla();
    }

    /** Sin el script, nada se puede guardar: se dice por qué en cada control. */
    function bloquearSinTabla() {
        if (datos.tabla_creada) { return; }

        document.querySelectorAll('.pll-modulo input, .pll-modulo select, .pll-modulo button:not([data-lectura])')
            .forEach(function(el) {
                el.disabled = true;
                el.title = 'Falta correr ' + datos.script;
            });
    }

    /* ================================================================
       1. GENERALES
       ================================================================ */

    function pintarGenerales() {
        var g = datos.generales || {};
        var meta = g._meta || {};

        document.querySelectorAll('.pll-general').forEach(function(el) {
            var clave = el.getAttribute('data-clave');
            el.value = (g[clave] === undefined || g[clave] === null) ? '' : numeroEditable(g[clave]);
        });

        document.querySelectorAll('.pll-audit').forEach(function(el) {
            var m = meta[el.getAttribute('data-clave')];
            el.textContent = m ? Auditoria.linea('modif', m.usuario, m.fecha) : '';
        });

        var s = datos.cortes_sugeridos;
        var caja = document.getElementById('pllCortesSugeridos');
        caja.textContent = s
            ? 'Sugeridos: los terciles de los m² de los ' + datos.m2_locales + ' locales habilitados que tienen m² — chico hasta '
              + numero(s.chico) + ' m², mediano hasta ' + numero(s.mediano) + ' m², grande más. Los confirma una persona.'
            : 'No hay m² suficientes para sugerir cortes.';

        ['CORTE_CHICO', 'CORTE_MEDIANO'].forEach(function(c) {
            var el = document.getElementById('pll' + c);
            if (el && s) { el.placeholder = numero(c === 'CORTE_CHICO' ? s.chico : s.mediano); }
        });
    }

    function guardarGeneral(el) {
        escribir('guardarGeneral', { clave: el.getAttribute('data-clave'), valor: el.value });
    }

    function confirmarCortes() {
        var s = datos.cortes_sugeridos;

        if (!s) { return; }

        /* El chico primero: el servidor valida que el mediano sea mayor. */
        pedir(ENDPOINT + '?action=guardarGeneral', { clave: 'CORTE_MEDIANO', valor: String(s.mediano) })
            .then(function() { return pedir(ENDPOINT + '?action=guardarGeneral', { clave: 'CORTE_CHICO', valor: String(s.chico) }); })
            .then(function() { Notificacion.exito('Cortes confirmados: chico hasta ' + numero(s.chico) + ' m², mediano hasta ' + numero(s.mediano) + ' m².'); cargar(); })
            .catch(function(e) { Notificacion.error(e.message); cargar(); });
    }

    /* ================================================================
       2. POR OPERADOR
       ================================================================ */

    function pintarOperadores() {
        var cuerpo = document.getElementById('pllOperadoresBody');
        var ops = datos.operadores || [];

        if (!ops.length) {
            cuerpo.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">No hay razones sociales dadas de alta '
                + 'en Parámetros › Alquileres.</td></tr>';
            return;
        }

        var g = datos.generales || {};
        /* Sin el script 73 el % de comisiones y el de FPC no tienen donde
           guardarse: se ven deshabilitados y nombran el script. */
        var separados = !!(datos.ajustes && datos.ajustes.pct_separados);
        var SIN_73 = 'Falta correr ' + (datos.script_ajustes || 'el script de ajustes') + ' contra la base central.';

        cuerpo.innerHTML = ops.map(function(o) {
            var v = o.valores || {};
            var sug = o.sugerido || {};
            var imp = o.impuestos || {};

            return '<tr data-cod="' + esc(o.cod) + '">'
                + '<td><strong>' + esc(o.nombre) + '</strong> <small class="text-muted">' + esc(o.cod) + '</small></td>'
                + '<td><small>' + esc(o.locales.join(', ') || '—') + '</small></td>'
                + '<td>' + campo('pct_llave', v.pct_llave, sug.pct_llave !== undefined && sug.pct_llave !== null
                    ? numero(sug.pct_llave) : numero(g.PCT_LLAVE), tipRenovaciones(sug, 'pct')) + '</td>'
                + '<td>' + campo('pct_comisiones', v.pct_comisiones, sug.pct_comisiones !== undefined && sug.pct_comisiones !== null
                    ? numero(sug.pct_comisiones) : 'sin dato', separados ? tipRenovaciones(sug, 'comisiones') : SIN_73, !separados) + '</td>'
                + '<td>' + campo('pct_fpc', v.pct_fpc, sug.pct_fpc !== undefined && sug.pct_fpc !== null
                    ? numero(sug.pct_fpc) : 'sin dato', separados ? tipRenovaciones(sug, 'fpc') : SIN_73, !separados) + '</td>'
                + '<td>' + campo('escribano', v.escribano, 'sin dato', 'Vacío = sin dato (avisa). Cargá 0 si no hay.') + '</td>'
                + '<td>' + campo('pct_impuestos', v.pct_impuestos, imp.pct !== undefined && imp.pct !== null ? numero(imp.pct) : 'sin dato',
                    tipImpuestos(imp)) + '</td>'
                + '<td>' + campo('cuotas', v.cuotas, numero(g.CUOTAS), 'Vacío = las generales') + '</td>'
                + '<td>' + campo('corrimiento', v.corrimiento, '0', 'Vacío = 0. Negativo = la primera cuota antes del inicio') + '</td>'
                + '<td><small class="text-muted">' + (v.usuario ? esc(Auditoria.linea('modif', v.usuario, v.fecha)) : '') + '</small></td>'
                + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('input').forEach(function(el) {
            el.addEventListener('change', function() { guardarFila('guardarOperador', el.closest('tr'), { cod_provee: el.closest('tr').getAttribute('data-cod') }); });
        });
    }

    function tipRenovaciones(sug, campo) {
        var rs = (sug && sug.renovaciones) || [];

        if (!rs.length) { return 'Sin renovaciones de este operador en la app para sugerir.'; }

        return 'Sugerido con las renovaciones de la app: ' + rs.map(function(r) {
            return r.local + ' #' + r.id + ' (' + fecha(r.desde) + ', ' + r.meses + ' meses): '
                + (campo === 'pct'
                    ? (r.pct === null ? 'sin alquiler del primer mes' : 'llave ' + plata(r.llave) + ' / (' + plata(r.alquiler) + ' × ' + r.meses + ') = ' + numero(r.pct) + '%')
                    : (campo === 'comisiones' ? 'comisiones ' : 'FPC ') + plata(r[campo]) + ' / llave ' + plata(r.llave) + ' = '
                      + numero(r['pct_' + campo]) + '%');
        }).join('; ') + '.';
    }

    function tipImpuestos(imp) {
        var cs = (imp && imp.cruces) || [];

        if (!cs.length) { return 'Sin facturas de llave en Tango (últimos 24 meses) para sugerir.'; }

        return 'Sugerido con la relación factura / contrato de Tango (las facturas no dicen cómo se paga: sólo cuánto se '
            + 'facturó): ' + cs.map(function(c) {
                return c.local + ' #' + c.id + ': facturado ' + plata(c.facturado) + ' / llave ' + plata(c.llave)
                    + (c.juntas ? ' (llave + comisiones juntas, sin impuestos)' : '') + ' → ' + numero(c.pct) + '% ['
                    + c.facturas.join(' · ') + ']';
            }).join('; ') + '.';
    }

    function campo(nombre, valor, placeholder, titulo, deshabilitado) {
        return '<input type="text" class="form-control form-control-sm" data-campo="' + nombre + '" inputmode="decimal" '
            + 'value="' + (valor === null || valor === undefined ? '' : esc(numeroEditable(valor))) + '" '
            + 'placeholder="' + esc(placeholder === undefined || placeholder === null ? '' : placeholder) + '" '
            + 'title="' + esc(titulo || '') + '"' + (deshabilitado ? ' disabled' : '') + '>';
    }

    /**
     * El tooltip de lo que no se edita en una renovacion ya cargada.
     *
     * LA REGLA: con el contrato nuevo cargado, lo que dice CUANTO (no renueva,
     * llave a mano, % propio, meses) deja de valer, porque manda el importe del
     * contrato; lo que dice COMO SE PAGA (cuotas y 1.a cuota) sigue valiendo.
     * Ver LlavesCalculo::proyectarRenovacion(). Por eso esas cuatro columnas se
     * ven, con lo que tenian, pero deshabilitadas: editarlas no cambiaria nada.
     */
    var MANDA_EL_CONTRATO = 'El contrato nuevo ya está cargado en la app: manda el contrato. Este valor no se usa; '
        + 'sólo valen las Cuotas y la 1.ª cuota.';

    /* ================================================================
       3. POR LOCAL
       ================================================================ */

    function pintarLocales() {
        var cuerpo = document.getElementById('pllLocalesBody');
        /* Las estimadas y las CARGADAS: de una cargada siguen valiendo las cuotas
           y el mes de la 1.a cuota, asi que tienen que poder verse y cambiarse. */
        var rens = (datos.renovaciones || []).filter(function(r) { return r.tipo === 'ESTIMADA' || r.tipo === 'CARGADA'; });
        var g = datos.generales || {};

        if (!rens.length) {
            cuerpo.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">Ningún local vence dentro del '
                + 'horizonte, está vencido sin renovación cargada ni tiene una renovación cargada con pagos por delante.</td></tr>';
            return;
        }

        cuerpo.innerHTML = rens.map(function(r) {
            var p = r.parametros || {};
            var v = r.vigente;
            var cargada = r.tipo === 'CARGADA';
            var dis = cargada ? ' disabled title="' + esc(MANDA_EL_CONTRATO) + '"' : '';

            return '<tr data-nro="' + r.nro + '" data-contrato="' + r.ant + '"' + (cargada ? ' class="pll-cargada"' : '') + '>'
                + '<td><strong>' + esc(r.local) + '</strong><br><small class="text-muted">' + esc(r.operador || 'sin razón social') + '</small></td>'
                + '<td>' + contratoTexto(r) + '</td>'
                + '<td><span class="badge ' + (r.estado === 'VENCIDO' ? 'bg-warning text-dark' : 'bg-light text-dark border') + '">'
                    + esc(r.nombre_estado) + '</span>' + (r.tiene_llave || cargada ? '' : '<br><small class="text-muted" title="Criterio de Costo de Ocupación">sin llave: no se estima</small>') + '</td>'
                + '<td><div class="d-flex gap-1 align-items-center"><input type="checkbox" class="form-check-input" data-campo="no_renueva"'
                    + (p.no_renueva ? ' checked' : '') + dis + '>'
                    + '<input type="text" class="form-control form-control-sm" data-campo="motivo_no_renueva" maxlength="300" placeholder="motivo" value="'
                    + esc(p.motivo_no_renueva || '') + '"' + dis + '></div></td>'
                + '<td>' + campo('llave_manual', p.llave_manual, 'calculada', cargada ? MANDA_EL_CONTRATO : 'Reemplaza el cálculo', cargada) + '</td>'
                + '<td>' + campo('pct_llave', p.pct_llave, numero(g.PCT_LLAVE), cargada ? MANDA_EL_CONTRATO : 'Vacío = el del operador o el general', cargada) + '</td>'
                + '<td>' + campo('meses', p.meses, r.meses_defecto, cargada ? MANDA_EL_CONTRATO : 'Vacío = los del último contrato con llave', cargada) + '</td>'
                + '<td>' + campo('cuotas', p.cuotas, '', 'Vacío = las del operador o las generales') + '</td>'
                + '<td><input type="month" class="form-control form-control-sm" data-campo="mes_primera_cuota" value="' + esc(p.mes_primera_cuota || '')
                    + '" title="Vacío = ' + esc(r.mes1_defecto) + '"></td>'
                + '<td><small class="text-muted">' + (p.usuario ? esc(Auditoria.linea('modif', p.usuario, p.fecha)) : '') + '</small></td>'
                + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('input').forEach(function(el) {
            el.addEventListener('change', function() {
                var fila = el.closest('tr');
                var chk = fila.querySelector('[data-campo="no_renueva"]');

                /* Sin motivo no se guarda un no renueva: se espera al motivo. */
                if (chk.checked && !fila.querySelector('[data-campo="motivo_no_renueva"]').value.trim()) {
                    Notificacion.advertencia('Escribí el motivo de que no renueva: se guarda con él.');
                    return;
                }

                guardarFila('guardarLocal', fila, { nro: fila.getAttribute('data-nro'), id_contrato: fila.getAttribute('data-contrato') });
            });
        });
    }

    /**
     * El contrato que rige la renovacion: en una cargada, el NUEVO (el que se
     * paga) y debajo, en chico, a cual renueva; en una estimada, el vigente.
     */
    function contratoTexto(r) {
        var c = r.tipo === 'CARGADA' ? r.contrato : r.vigente;

        return '<small>#' + c.id + ' · ' + fecha(c.desde) + ' a ' + fecha(c.hasta) + '<br>llave ' + llaveTexto(c.llave)
            + (r.tipo === 'CARGADA' ? '<br><span class="text-muted">renueva al #' + r.vigente.id + '</span>' : '') + '</small>';
    }

    function llaveTexto(v) {
        if (v === null || v === undefined) { return 'sin dato'; }
        if (Math.abs(v - 1) < 0.005) { return '1 (tiene llave, monto desconocido)'; }

        return plata(v);
    }

    function guardarFila(accion, fila, base) {
        var cuerpo = base;

        fila.querySelectorAll('[data-campo]').forEach(function(el) {
            cuerpo[el.getAttribute('data-campo')] = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
        });

        escribir(accion, cuerpo);
    }

    /* ================================================================
       FORMULARIOS (opciones)
       ================================================================ */

    function pintarFormularios() {
        var locales = datos.locales || [];
        var segs = datos.segmentos || {};

        opciones('pllPagadaRen', (datos.renovaciones || []).map(function(r) {
            return { valor: r.nro + '|' + r.ant, texto: r.local + ' — ' + (r.tipo === 'ESTIMADA'
                ? 'renovación del contrato que vence el ' + fecha(r.vigente.hasta)
                : 'contrato #' + r.contrato.id + ' del ' + fecha(r.contrato.desde)) };
        }), 'Elegí la renovación');
        opciones('pllObraLocal', locales.map(function(l) {
            return { valor: l.nro, texto: l.nombre + (l.m2 === null ? ' (sin m²)' : ' (' + numero(l.m2) + ' m²'
                + (l.segmento ? ', ' + segs[l.segmento].toLowerCase() : '') + ')') };
        }), 'Elegí el local');
        opciones('pllObraSegmento', objeto(segs), 'Por sus m²');
        opciones('pllApSegmento', objeto(segs), 'Elegí');
        opciones('pllCoLocal', locales.map(function(l) { return { valor: l.nro, texto: l.nombre }; }), 'Sin local');
        opciones('pllCoForma', objeto(datos.formas), 'Sin especificar');

        var dl = document.getElementById('pllCoOperadores');

        if (dl) {
            dl.innerHTML = (datos.proveedores || []).map(function(p) {
                return '<option value="' + esc(p.nombre + ' (' + p.cod + ')' + (p.tipo === 'ALQUILERES' ? ' · Alquileres' : '')) + '">';
            }).join('');
        }
    }

    function operadorElegido() {
        var t = (document.getElementById('pllCoOperador').value || '').trim();
        var lista = datos.proveedores || [];

        for (var i = 0; i < lista.length; i++) {
            var p = lista[i];
            if (t === p.nombre + ' (' + p.cod + ')' + (p.tipo === 'ALQUILERES' ? ' · Alquileres' : '')) { return p; }
        }

        return null;
    }

    /* ================================================================
       4. YA PAGADAS
       ================================================================ */

    function pintarPagadas() {
        var cuerpo = document.getElementById('pllPagadasBody');
        var ps = datos.pagadas || [];
        var nombres = nombresLocales();

        cuerpo.innerHTML = ps.length ? ps.map(function(p) {
            return '<tr data-id="' + p.id + '"><td>' + esc(nombres[p.nro] || p.nro) + '</td>'
                + '<td><small>contrato #' + p.id_contrato_ant + '</small></td>'
                + '<td>' + esc(datos.conceptos_pagada[p.concepto] || p.concepto) + '</td>'
                + '<td>' + fecha(p.fecha) + '</td><td class="text-end">' + plata(p.monto) + '</td>'
                + '<td><small>' + esc(p.comprobante || '') + (p.observacion ? ' — ' + esc(p.observacion) : '') + '</small></td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('alta', p.usuario, p.fecha_alta)) + '</small></td>'
                + '<td>' + baja('bajaPagada', p.id, 'la marca de pagada', 'Lo proyectado de ese concepto vuelve.') + '</td></tr>';
        }).join('') : '<tr><td colspan="8" class="text-center text-muted py-3">Ninguna renovación marcada como pagada.</td></tr>';
        engancharBajas(cuerpo);
    }

    function marcarPagada() {
        var ren = document.getElementById('pllPagadaRen').value;

        if (!ren) { Notificacion.advertencia('Elegí la renovación.'); return; }

        if (!conceptosTildados('pll-pag-concepto', 'pllPagadaMonto').length) {
            Notificacion.advertencia('Tildá qué se pagó: la llave, los otros gastos o los dos.');
            return;
        }

        escribir('altaPagada', {
            nro: ren.split('|')[0], id_contrato_ant: ren.split('|')[1],
            conceptos: conceptosTildados('pll-pag-concepto', 'pllPagadaMonto'),
            fecha: document.getElementById('pllPagadaFecha').value,
            comprobante: document.getElementById('pllPagadaComp').value,
            observacion: document.getElementById('pllPagadaObs').value
        });
    }

    /**
     * Los conceptos tildados de un formulario de "Ya pagada", cada uno con su
     * monto (el input con el prefijo + el concepto). Los dos vienen tildados:
     * lo normal es marcar la renovacion entera.
     */
    function conceptosTildados(clase, prefijoMonto) {
        return Array.prototype.filter.call(document.querySelectorAll('.' + clase), function(c) { return c.checked; })
            .map(function(c) { return { concepto: c.value, monto: document.getElementById(prefijoMonto + c.value).value }; });
    }

    /* ================================================================
       5 Y 6. OBRAS Y APERTURAS
       ================================================================ */

    function pintarObras() {
        var cuerpo = document.getElementById('pllObrasBody');
        var os = datos.obras || [];
        var nombres = nombresLocales();
        var segs = datos.segmentos || {};

        cuerpo.innerHTML = os.length ? os.map(function(o) {
            return '<tr><td>' + esc(nombres[o.nro] || o.nro) + '</td><td>' + esc(o.mes) + '</td>'
                + '<td>' + (o.segmento ? esc(segs[o.segmento]) : '<span class="text-muted">por m²</span>') + '</td>'
                + '<td class="text-end">' + (o.costo_usd === null ? '<span class="text-muted">el del segmento</span>' : 'U$S ' + numero(o.costo_usd)) + '</td>'
                + '<td><small>' + esc(o.observacion || '') + '</small></td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('alta', o.usuario, o.fecha)) + '</small></td>'
                + '<td>' + baja('bajaObra', o.id, 'la obra', '') + '</td></tr>';
        }).join('') : '<tr><td colspan="7" class="text-center text-muted py-3">Ninguna obra cargada.</td></tr>';
        engancharBajas(cuerpo);
    }

    function agregarObra() {
        escribir('altaObra', {
            nro: document.getElementById('pllObraLocal').value, mes: document.getElementById('pllObraMes').value,
            segmento: document.getElementById('pllObraSegmento').value, costo_usd: document.getElementById('pllObraUsd').value,
            observacion: document.getElementById('pllObraObs').value
        });
    }

    function pintarAperturas() {
        var cuerpo = document.getElementById('pllAperturasBody');
        var as = datos.aperturas || [];
        var segs = datos.segmentos || {};

        cuerpo.innerHTML = as.length ? as.map(function(a) {
            return '<tr><td>' + esc(a.nombre) + '</td><td>' + (a.nro === null ? '—' : a.nro) + '</td><td>' + esc(a.mes) + '</td>'
                + '<td>' + esc(segs[a.segmento] || a.segmento) + '</td>'
                + '<td class="text-end">' + (a.costo_usd === null ? '<span class="text-muted">el del segmento</span>' : 'U$S ' + numero(a.costo_usd)) + '</td>'
                + '<td><small>' + esc(a.observacion || '') + '</small></td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('alta', a.usuario, a.fecha)) + '</small></td>'
                + '<td>' + baja('bajaApertura', a.id, 'la apertura', '') + '</td></tr>';
        }).join('') : '<tr><td colspan="8" class="text-center text-muted py-3">Ninguna apertura cargada.</td></tr>';
        engancharBajas(cuerpo);
    }

    function agregarApertura() {
        escribir('altaApertura', {
            nombre: document.getElementById('pllApNombre').value, nro: document.getElementById('pllApNro').value,
            mes: document.getElementById('pllApMes').value, segmento: document.getElementById('pllApSegmento').value,
            costo_usd: document.getElementById('pllApUsd').value, observacion: document.getElementById('pllApObs').value
        });
    }

    /* ================================================================
       7. COMPROMISOS
       ================================================================ */

    function pintarCompromisos() {
        var cuerpo = document.getElementById('pllCompromisosBody');
        var cs = datos.compromisos || [];
        var nombres = nombresLocales();
        var ops = {};

        (datos.proveedores || []).forEach(function(p) { ops[p.cod] = p.nombre; });

        cuerpo.innerHTML = cs.length ? cs.map(function(c) {
            return '<tr><td>' + esc(ops[c.cod_provee] || c.cod_provee) + ' <small class="text-muted">' + esc(c.cod_provee) + '</small></td>'
                + '<td>' + esc(c.nro === null ? '—' : (nombres[c.nro] || c.nro)) + '</td><td>' + esc(c.concepto) + '</td>'
                + '<td>' + fecha(c.fecha) + '</td><td class="text-end">' + plata(c.monto) + '</td>'
                + '<td>' + (c.cuotas ? c.cuota_nro + ' de ' + c.cuotas : '') + '</td>'
                + '<td>' + esc(c.forma ? datos.formas[c.forma] : '') + '</td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('alta', c.usuario, c.fecha_alta)) + '</small></td>'
                + '<td class="text-nowrap">' + baja('bajaCompromiso', c.id, 'la cuota', '')
                    + (c.id_serie ? ' ' + baja('bajaCompromiso', c.id, 'la serie entera (' + c.cuotas + ' cuotas)', '', true) : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="9" class="text-center text-muted py-3">Ningún compromiso cargado.</td></tr>';
        engancharBajas(cuerpo);
    }

    function agregarCompromiso() {
        var op = operadorElegido();

        if (!op) { Notificacion.advertencia('Elegí el operador de la lista: una razón social de Alquileres o un proveedor del maestro.'); return; }

        escribir('altaCompromiso', {
            tipo: op.tipo, cod_provee: op.cod, nro: document.getElementById('pllCoLocal').value,
            concepto: document.getElementById('pllCoConcepto').value, fecha: document.getElementById('pllCoFecha').value,
            monto: document.getElementById('pllCoMonto').value, cuotas: document.getElementById('pllCoCuotas').value,
            forma: document.getElementById('pllCoForma').value, observacion: document.getElementById('pllCoObs').value
        });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function baja(accion, id, que, detalle, serie) {
        return '<button class="btn btn-sm btn-link text-danger p-0 pll-baja" data-accion="' + accion + '" data-id="' + id + '"'
            + (serie ? ' data-serie="1"' : '') + ' data-que="' + esc(que) + '" data-detalle="' + esc(detalle) + '" '
            + 'title="Dar de baja ' + esc(que) + '"><i class="fas ' + (serie ? 'fa-layer-group' : 'fa-trash-alt') + '"></i></button>';
    }

    function engancharBajas(cuerpo) {
        cuerpo.querySelectorAll('.pll-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                Notificacion.confirmar({
                    titulo: 'Dar de baja',
                    mensaje: '¿Dar de baja ' + btn.getAttribute('data-que') + '?',
                    detalle: (btn.getAttribute('data-detalle') || '') + ' No se borra: queda en el historial.',
                    confirmar: 'Dar de baja',
                    peligro: true
                }).then(function(si) {
                    if (si) {
                        escribir(btn.getAttribute('data-accion'), { id: btn.getAttribute('data-id'), serie: btn.getAttribute('data-serie') === '1' });
                    }
                });
            });
        });
    }

    function nombresLocales() {
        var out = {};

        (datos.locales || []).forEach(function(l) { out[l.nro] = l.nombre; });

        return out;
    }

    function opciones(id, lista, vacio) {
        var el = document.getElementById(id);

        if (!el) { return; }

        el.innerHTML = (vacio !== undefined ? '<option value="">' + esc(vacio) + '</option>' : '')
            + lista.map(function(o) { return '<option value="' + esc(o.valor) + '">' + esc(o.texto) + '</option>'; }).join('');
    }

    function objeto(o) {
        return Object.keys(o || {}).map(function(k) { return { valor: k, texto: o[k] }; });
    }

    /** Escribe y recarga: lo que se ve es siempre lo guardado. */
    function escribir(accion, cuerpo) {
        return pedir(ENDPOINT + '?action=' + accion, cuerpo)
            .then(function(d) {
                Notificacion.exito(d.message || 'Listo.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error(e.message);
                cargar();
            });
    }

    function buscarModulo(data, codigo) {
        if (!data || !data.modulos) { return null; }

        for (var i = 0; i < data.modulos.length; i++) {
            if (data.modulos[i].codigo === codigo) { return data.modulos[i]; }
        }

        return null;
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

    function numero(n) {
        return (n === null || n === undefined || n === '') ? '' : Number(n).toLocaleString('es-AR', { maximumFractionDigits: 2 });
    }

    /** Un numero como se escribe en un input: coma decimal, sin miles. */
    function numeroEditable(n) {
        return String(Math.round(Number(n) * 100) / 100).replace('.', ',');
    }

    function plata(n) {
        return '$ ' + Number(n || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 });
    }

    function fecha(f) {
        return f ? String(f).substr(8, 2) + '/' + String(f).substr(5, 2) + '/' + String(f).substr(0, 4) : '';
    }

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
        enganchar('pllBtnRefresh', 'click', cargar);
        enganchar('pllBtnCortes', 'click', confirmarCortes);
        enganchar('pllBtnPagada', 'click', marcarPagada);

        /* Un concepto sin tildar no pide monto. */
        document.querySelectorAll('.pll-pag-concepto').forEach(function(c) {
            c.addEventListener('change', function() { document.getElementById('pllPagadaMonto' + c.value).disabled = !c.checked; });
        });
        enganchar('pllBtnObra', 'click', agregarObra);
        enganchar('pllBtnApertura', 'click', agregarApertura);
        enganchar('pllBtnCompromiso', 'click', agregarCompromiso);

        document.querySelectorAll('.pll-general').forEach(function(el) {
            el.addEventListener('change', function() { guardarGeneral(el); });
        });

        ParametrosCarga.registrar('LLAVES_RENOV', cargar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
