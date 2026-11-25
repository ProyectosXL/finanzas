/**
 * Parametros -> Prestamos
 *
 * Los valores generales que cada prestamo nuevo copia al darse de alta: tasas
 * de referencia, costos asociados, comision de cancelacion, simulador y
 * margen.
 *
 * LA VALIDACION MANDA EN EL SERVIDOR (PrestamosParametros). Aca solo se
 * explica y se bloquean botones: lo que el servidor rechaza vuelve con su
 * mensaje, tal cual, y la pantalla se recarga con lo guardado.
 *
 * LOS GENERALES Y LOS COSTOS SE GUARDAN AL SALIR DEL CAMPO. Los tramos se
 * guardan como juego, con su boton. NADA DE alert(): todo va a Notificacion.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/PrestamosController.php';
    var datos = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        var descripcion = ParametrosCarga.pedir('PRESTAMOS')
            .then(function(data) {
                var modulo = buscarModulo(data, 'PRESTAMOS');
                var desc = document.getElementById('pprDescripcion');

                if (desc && modulo && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + esc(modulo.descripcion);
                }
            })
            .catch(function() { /* Contexto, no dato. */ });

        Cargando.mostrar('pprCargando');

        var pedido = pedir(ENDPOINT + '?action=getParametros')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintar('pprAvisos', datos.avisos || []);
                pintarTodo();
            })
            .catch(function(e) {
                AvisosPanel.pintar('pprAvisos', [{ nivel: 'danger', texto: 'No se pudieron leer los parámetros de Préstamos: ' + e.message }]);

                return false;
            })
            .then(function(r) {
                Cargando.ocultar('pprCargando');

                return r;
            });

        return Promise.all([descripcion, pedido]).then(function(r) { return r[1]; });
    }

    function pintarTodo() {
        pintarGenerales();
        pintarReferencias();
        pintarTasasManual();
        pintarCostos();
        pintarTramos();
        Permisos.soloLectura(document.querySelector('.ppr-modulo'));
        bloquearSinTabla();
    }

    function bloquearSinTabla() {
        if (datos.tabla_creada) { return; }

        document.querySelectorAll('.ppr-modulo input, .ppr-modulo select, .ppr-modulo button:not([data-lectura])')
            .forEach(function(el) {
                el.disabled = true;
                el.title = 'Falta correr ' + datos.script;
            });
    }

    /* ================================================================
       GENERALES
       ================================================================ */

    function pintarGenerales() {
        var g = datos.generales || {};
        var meta = g._meta || {};

        document.querySelectorAll('.ppr-general').forEach(function(el) {
            var v = g[el.getAttribute('data-clave')];

            if (el.type === 'checkbox') {
                el.checked = !!Number(v);
            } else {
                el.value = (v === undefined || v === null) ? '' : (typeof v === 'number' ? numeroEditable(v) : v);
            }
        });

        document.querySelectorAll('.ppr-audit').forEach(function(el) {
            var m = meta[el.getAttribute('data-clave')];
            el.textContent = m ? Auditoria.linea('modif', m.usuario, m.fecha) : '';
        });

        mostrarTramos();
    }

    function mostrarTramos() {
        var modo = document.getElementById('pprCANCEL_MODO');
        var bloque = document.getElementById('pprTramosBloque');

        if (modo && bloque) { bloque.style.display = (modo.value === 'ESCALONADA') ? '' : 'none'; }
    }

    function guardarGeneral(el) {
        var valor = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value;

        escribir('guardarGeneral', { clave: el.getAttribute('data-clave'), valor: valor });
    }

    /* ================================================================
       TASAS DE REFERENCIA
       ================================================================ */

    function pintarReferencias() {
        var caja = document.getElementById('pprReferencias');
        var refs = datos.referencias || {};
        var html = '';

        Object.keys(refs).forEach(function(k) {
            var r = refs[k];
            html += '<div class="col-md-6"><div class="border rounded p-2 small">'
                + '<strong>' + esc(r.nombre) + '</strong><br>'
                + (r.ultimo
                    ? 'Última publicada: <strong>' + pct(r.ultimo.valor) + '</strong> (' + fechaCorta(r.ultimo.fecha) + ')<br>'
                      + 'Promedio últimos ' + datos.dias_promedio + ' días hábiles: <strong>' + pct(r.promedio.valor) + '</strong>'
                      + (r.promedio.suficiente ? '' : ' <span class="text-warning">(solo ' + r.promedio.n + ' publicaciones)</span>')
                    : '<span class="text-danger">Sin valores publicados.</span>')
                + '</div></div>';
        });

        caja.innerHTML = html;
    }

    function pintarTasasManual() {
        var cuerpo = document.getElementById('pprTasasManual');
        var filas = datos.tasas_manual || [];

        cuerpo.innerHTML = filas.length ? filas.map(function(t) {
            return '<tr><td>' + esc(t.referencia) + '</td><td>' + esc(t.mes) + '</td><td class="text-end">' + pct(t.valor) + '</td>'
                + '<td class="small text-muted">' + esc(Auditoria.linea('alta', t.usuario, t.fecha)) + '</td>'
                + '<td class="text-end">' + Permisos.siEdita(cuerpo, '<button class="btn btn-sm btn-outline-danger ppr-quitar-tasa" '
                    + 'data-ref="' + esc(t.referencia) + '" data-mes="' + esc(t.mes) + '" title="Quitar"><i class="fas fa-trash"></i></button>')
                + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="text-muted small">No hay valores cargados a mano: se usa el promedio publicado.</td></tr>';

        cuerpo.querySelectorAll('.ppr-quitar-tasa').forEach(function(b) {
            b.addEventListener('click', function() {
                escribir('guardarTasaManual', { referencia: b.getAttribute('data-ref'), mes: b.getAttribute('data-mes'), valor: '' });
            });
        });
    }

    function guardarTasa() {
        var mes = document.getElementById('pprTasaMes').value;
        var valor = document.getElementById('pprTasaValor').value;

        if (!mes) { Notificacion.campoInvalido('pprTasaMes', 'Elegí el mes.'); return; }
        if (valor === '') { Notificacion.campoInvalido('pprTasaValor', 'Escribí el valor de la tasa.'); return; }

        escribir('guardarTasaManual', { referencia: document.getElementById('pprTasaRef').value, mes: mes, valor: valor });
    }

    /* ================================================================
       COSTOS ASOCIADOS
       ================================================================ */

    function opciones(lista, actual) {
        return Object.keys(lista).map(function(k) {
            return '<option value="' + esc(k) + '"' + (k === actual ? ' selected' : '') + '>' + esc(lista[k]) + '</option>';
        }).join('');
    }

    function filaCosto(c) {
        var l = datos.listas;

        return '<tr data-id="' + (c.id || '') + '">'
            + '<td><input type="text" class="form-control form-control-sm ppr-costo" data-campo="nombre" value="' + esc(c.nombre || '') + '"></td>'
            + '<td style="width:110px"><input type="text" class="form-control form-control-sm text-end ppr-costo" data-campo="pct" inputmode="decimal" value="'
                + (c.pct === undefined ? '' : numeroEditable(c.pct)) + '"></td>'
            + '<td><select class="form-select form-select-sm ppr-costo" data-campo="base">' + opciones(l.bases, c.base || 'CAPITAL') + '</select></td>'
            + '<td><select class="form-select form-select-sm ppr-costo" data-campo="momento">' + opciones(l.momentos, c.momento || 'INICIAL') + '</select></td>'
            + '<td class="text-center"><input type="checkbox" class="form-check-input ppr-costo" data-campo="iva"' + (c.iva ? ' checked' : '') + '></td>'
            + '<td class="text-center"><input type="checkbox" class="form-check-input ppr-costo" data-campo="activo"' + (c.activo !== false ? ' checked' : '') + '></td>'
            + '<td class="small text-muted">' + (c.usuario ? esc(Auditoria.linea('modif', c.usuario, c.fecha)) : (c.id ? '' : 'Nuevo: se guarda al completar')) + '</td>'
            + '</tr>';
    }

    function pintarCostos() {
        var cuerpo = document.getElementById('pprCostos');
        cuerpo.innerHTML = (datos.costos || []).map(filaCosto).join('');
        engancharCostos(cuerpo);
    }

    function engancharCostos(cuerpo) {
        cuerpo.querySelectorAll('.ppr-costo').forEach(function(el) {
            if (el.getAttribute('data-enganchado')) { return; }

            el.setAttribute('data-enganchado', '1');
            el.addEventListener('change', function() { guardarCosto(el.closest('tr')); });
        });
    }

    function guardarCosto(tr) {
        var d = { id: tr.getAttribute('data-id') || '' };

        tr.querySelectorAll('.ppr-costo').forEach(function(el) {
            d[el.getAttribute('data-campo')] = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value;
        });

        if (!d.id && (d.nombre === '' || d.pct === '')) { return; }

        escribir('guardarCosto', d);
    }

    function agregarCosto() {
        var cuerpo = document.getElementById('pprCostos');
        cuerpo.insertAdjacentHTML('beforeend', filaCosto({ activo: true }));
        engancharCostos(cuerpo);
    }

    /* ================================================================
       TRAMOS
       ================================================================ */

    function filaTramo(t) {
        return '<tr>'
            + '<td><input type="text" class="form-control form-control-sm ppr-tramo" data-campo="desde" inputmode="decimal" value="' + (t.desde === undefined ? '' : numeroEditable(t.desde)) + '"></td>'
            + '<td><input type="text" class="form-control form-control-sm ppr-tramo" data-campo="pct_total" inputmode="decimal" value="' + (t.pct_total === undefined ? '' : numeroEditable(t.pct_total)) + '"></td>'
            + '<td><input type="text" class="form-control form-control-sm ppr-tramo" data-campo="pct_parcial" inputmode="decimal" value="' + (t.pct_parcial === undefined ? '' : numeroEditable(t.pct_parcial)) + '"></td>'
            + '<td>' + Permisos.siEdita(document.getElementById('pprTramos'), '<button class="btn btn-sm btn-outline-danger ppr-tramo-quitar" title="Quitar"><i class="fas fa-times"></i></button>') + '</td>'
            + '</tr>';
    }

    function pintarTramos() {
        var cuerpo = document.getElementById('pprTramos');
        var tramos = datos.tramos || [];
        cuerpo.innerHTML = tramos.length ? tramos.map(filaTramo).join('') : '';
        engancharQuitarTramo(cuerpo);
    }

    function engancharQuitarTramo(cuerpo) {
        cuerpo.querySelectorAll('.ppr-tramo-quitar').forEach(function(b) {
            b.onclick = function() { b.closest('tr').remove(); };
        });
    }

    function guardarTramos() {
        var tramos = [];

        document.querySelectorAll('#pprTramos tr').forEach(function(tr) {
            var t = {};

            tr.querySelectorAll('.ppr-tramo').forEach(function(el) { t[el.getAttribute('data-campo')] = el.value; });
            tramos.push(t);
        });

        escribir('guardarTramos', { tramos: tramos });
    }

    /* ================================================================
       AUXILIARES
       ================================================================ */

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
        var mods = (data && data.data && data.data.modulos) || (data && data.modulos) || [];

        for (var i = 0; i < mods.length; i++) {
            if (mods[i].codigo === codigo) { return mods[i]; }
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

    function numeroEditable(n) {
        return String(Math.round(Number(n) * 10000) / 10000).replace('.', ',');
    }

    function pct(n) {
        return (n === null || n === undefined) ? '—'
            : Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) + ' %';
    }

    function fechaCorta(f) {
        var p = String(f || '').split('-');

        return p.length === 3 ? p[2] + '/' + p[1] : (f || '');
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
        enganchar('pprBtnRefresh', 'click', cargar);
        enganchar('pprBtnTasa', 'click', guardarTasa);
        enganchar('pprBtnCosto', 'click', agregarCosto);
        enganchar('pprBtnTramos', 'click', guardarTramos);
        enganchar('pprBtnTramoAgregar', 'click', function() {
            var cuerpo = document.getElementById('pprTramos');
            cuerpo.insertAdjacentHTML('beforeend', filaTramo({}));
            engancharQuitarTramo(cuerpo);
        });
        enganchar('pprCANCEL_MODO', 'change', mostrarTramos);

        document.querySelectorAll('.ppr-general').forEach(function(el) {
            el.addEventListener('change', function() { guardarGeneral(el); });
        });

        ParametrosCarga.registrar('PRESTAMOS', cargar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
