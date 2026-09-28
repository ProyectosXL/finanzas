/**
 * ie-parametros.js
 * La pestaña Parámetros: estructura de filas, categoría de los rubros,
 * umbrales del semáforo y el parámetro de alerta.
 *
 * Sin 'ie.editar' todo se ve y nada se puede guardar: los campos se dibujan
 * deshabilitados, y el controller rechaza igual cualquier guardado (403).
 */
'use strict';

IE.tabs.parametros = (function () {

    var d = null;
    var seccion = 'estructura';
    var URL = 'Controller/ParametrosController.php';

    function dis() {
        return d && d.puedeEditar ? '' : ' disabled';
    }

    function auditoria(u, f) {
        return u ? '<span class="ie-null" style="font-size:11px">' + IE.esc(u) + (f ? ' · ' + IE.esc(f.substring(0, 16)) : '') + '</span>' : '';
    }

    /* ── Estructura ── */
    function estructura() {
        if (!d.estructura) return '<p class="ie-nota">Sin estructura: corré sql/ie_estructura.sql.</p>';

        var h = '<p class="ie-nota"><i class="bi bi-info-circle"></i> Se editan el orden, la etiqueta, si se ve y si está activa. '
            + 'Las fórmulas no se editan: viven en el código. Una fila RUBROS_DE_SECCION se expande en todos los rubros de su categoría.</p>'
            + '<table class="ie-tabla-simple"><thead><tr><th>Orden</th><th>Clave</th><th>Tipo</th><th>Sección</th><th>Etiqueta</th>'
            + '<th>Visible</th><th>Activa</th><th>Último cambio</th><th></th></tr></thead><tbody>';

        d.estructura.forEach(function (f) {
            var def = d.catalogo[f.CLAVE] ? d.catalogo[f.CLAVE].etiqueta : '';

            h += '<tr class="' + (+f.ACTIVO ? '' : 'ie-fila-inactiva') + '" data-id="' + f.ID + '">'
                + '<td><input type="number" class="ie-input ie-input-sm" style="width:80px" name="orden" value="' + f.ORDEN + '"' + dis() + '></td>'
                + '<td>' + IE.esc(f.CLAVE) + '</td><td>' + IE.esc(f.TIPO) + '</td><td>' + IE.esc(f.SECCION || '') + '</td>'
                + '<td><input type="text" class="ie-input ie-input-sm" style="width:320px" name="etiqueta" maxlength="150" value="'
                + IE.esc(f.ETIQUETA || '') + '" placeholder="' + IE.esc(def) + '"' + dis() + '></td>'
                + '<td style="text-align:center"><input type="checkbox" name="visible"' + (+f.VISIBLE ? ' checked' : '') + dis() + '></td>'
                + '<td style="text-align:center"><input type="checkbox" name="activo"' + (+f.ACTIVO ? ' checked' : '') + dis() + '></td>'
                + '<td>' + auditoria(f.USUARIO_MOD, f.FECHA_MOD) + '</td>'
                + '<td><button class="ie-btn ie-btn-sec ie-btn-sm" data-guardar="fila"' + dis() + '><i class="bi bi-floppy"></i></button></td></tr>';
        });

        return h + '</tbody></table>';
    }

    /* ── Rubros ── */
    function rubros() {
        var h = '<div class="ie-aviso ie-aviso-warning"><i class="bi bi-exclamation-triangle"></i><div>'
            + 'La categoría (CAT_RUBRO_CONTABLE) es del maestro de Control de Gastos y la usa también <strong>Rentabilidad por Rubro</strong>: '
            + 'cambiarla acá mueve los dos informes. Cada cambio queda auditado. Los rubros de ventas y costo (1.x y 2) no se editan: la estructura los ubica por su código.</div></div>'
            + '<table class="ie-tabla-simple" style="margin-top:12px"><thead><tr><th>Código</th><th>Rubro</th><th>Categoría</th><th>Registros en el resumen</th><th>Estado</th><th></th></tr></thead><tbody>';

        d.rubros.forEach(function (r) {
            var editable = !r.fijo && r.enMaestro;
            var opciones = '<option value="">— sin categoría —</option>' + d.categorias.map(function (c) {
                return '<option' + (c === r.cat ? ' selected' : '') + '>' + IE.esc(c) + '</option>';
            }).join('');

            var estado = r.sinSeccion ? '<span class="ie-badge ie-badge-pisado">sin sección</span>'
                : (!r.enMaestro ? '<span class="ie-badge ie-badge-pisado">no está en el maestro</span>'
                    : (r.fijo ? '<span class="ie-null">ventas / costo</span>' : (r.activo === 0 ? '<span class="ie-null">inactivo</span>' : '')));

            h += '<tr class="' + (r.sinSeccion ? 'ie-fila-sin-seccion' : '') + '" data-cod="' + IE.esc(r.cod) + '"><td>' + IE.esc(r.cod) + '</td>'
                + '<td>' + IE.esc(r.nombre || '—') + '</td>'
                + '<td>' + (editable ? '<select class="ie-select ie-input-sm" name="cat"' + dis() + '>' + opciones + '</select>' : IE.esc(r.cat || '—')) + '</td>'
                + '<td>' + r.registros + '</td><td>' + estado + '</td>'
                + '<td>' + (editable ? '<button class="ie-btn ie-btn-sec ie-btn-sm" data-guardar="categoria"' + dis() + '><i class="bi bi-floppy"></i></button>' : '') + '</td></tr>';
        });

        return h + '</tbody></table>';
    }

    /* ── Umbrales ── */
    function umbrales() {
        if (!d.umbrales) return '<p class="ie-nota">Sin umbrales: corré sql/ie_umbrales.sql. El ranking se ve sin colores.</p>';

        var colores = ['VERDE', 'AMARILLO', 'NARANJA', 'ROJO'];
        var pct = function (v) { return v === null ? '' : String(+(v * 100).toFixed(2)).replace('.', ','); };

        var h = '<p class="ie-nota"><i class="bi bi-info-circle"></i> Límites en %. Vacío = abierto. Una banda sin límites no existe; '
            + 'un valor que no cae en ninguna queda sin color. Un valor justo en un límite toma la banda <strong>peor</strong>.</p>'
            + '<table class="ie-tabla-simple"><thead><tr><th>Indicador</th><th>Sentido</th>'
            + colores.map(function (c) { return '<th colspan="2"><span class="ie-sem ie-sem-' + c + '">' + c.toLowerCase() + '</span> desde / hasta</th>'; }).join('')
            + '<th>Último cambio</th><th></th></tr></thead><tbody>';

        Object.keys(d.umbrales).forEach(function (k) {
            var u = d.umbrales[k];

            h += '<tr data-id="' + u.id + '"><td>' + IE.esc(u.nombre) + '</td><td>' + (u.sentido === 'MAYOR_MEJOR' ? 'mayor es mejor' : 'menor es mejor') + '</td>';

            colores.forEach(function (c) {
                ['desde', 'hasta'].forEach(function (x) {
                    h += '<td><input type="text" class="ie-input ie-input-sm" style="width:58px" data-color="' + c + '" data-lim="' + x
                        + '" value="' + pct(u.bandas[c][x]) + '"' + dis() + '></td>';
                });
            });

            h += '<td>' + auditoria(u.usuario, u.fecha) + '</td><td><button class="ie-btn ie-btn-sec ie-btn-sm" data-guardar="umbral"' + dis()
                + '><i class="bi bi-floppy"></i></button></td></tr>';
        });

        return h + '</tbody></table>';
    }

    /* ── Alerta ── */
    function alerta() {
        if (!d.caidaPp) return '<p class="ie-nota">Falta el parámetro: corré sql/ie_umbrales.sql.</p>';

        return '<div class="ie-panel" style="max-width:560px"><h3><i class="bi bi-arrow-down-right-circle"></i> Caída de rentabilidad</h3>'
            + '<p class="ie-nota">Un local entra en las alertas del Dashboard si su rentabilidad cae más de estos puntos porcentuales contra el año anterior.</p>'
            + '<div class="ie-form-row" style="display:flex;gap:10px;align-items:center">'
            + '<input type="number" min="0" max="100" step="1" class="ie-input" id="ieCaida" style="width:100px" value="' + d.caidaPp.valor + '"' + dis() + '> puntos'
            + '<button class="ie-btn ie-btn-primario ie-btn-sm" data-guardar="caida"' + dis() + '><i class="bi bi-floppy"></i> Guardar</button>'
            + auditoria(d.caidaPp.usuario, d.caidaPp.fecha) + '</div></div>';
    }

    function dibujar() {
        var f = { estructura: estructura, rubros: rubros, umbrales: umbrales, alerta: alerta }[seccion];
        document.getElementById('ieCuerpo').innerHTML = f();
    }

    async function cargar() {
        IE.cargando('ieCuerpo', 'Leyendo parámetros…');
        var r = await IE.post(URL, { action: 'listar' });

        if (!r.ok) {
            IE.estadoVacio('ieCuerpo', 'bi-exclamation-octagon', 'No se pudieron leer los parámetros', IE.esc(r.message || ''));
            return;
        }

        d = r;
        IE.pintarAvisos('ieAvisos', r.avisos.map(function (t) { return { nivel: 'warning', texto: t, detalle: [] }; }));
        dibujar();
    }

    async function guardar(boton) {
        var tipo = boton.dataset.guardar;
        var tr = boton.closest('tr');
        var datos;

        if (tipo === 'fila') {
            datos = {
                action: 'guardar_fila', id: tr.dataset.id,
                orden: tr.querySelector('[name=orden]').value,
                etiqueta: tr.querySelector('[name=etiqueta]').value,
                visible: tr.querySelector('[name=visible]').checked ? '1' : '0',
                activo: tr.querySelector('[name=activo]').checked ? '1' : '0'
            };
        } else if (tipo === 'categoria') {
            var cat = tr.querySelector('[name=cat]').value;
            if (!cat) { alert('Elegí una categoría.'); return; }
            if (!confirm('La categoría de ' + tr.dataset.cod + ' la usa también Rentabilidad por Rubro. ¿Cambiarla a "' + cat + '"?')) return;
            datos = { action: 'guardar_categoria', cod: tr.dataset.cod, cat: cat };
        } else if (tipo === 'umbral') {
            var bandas = {};
            tr.querySelectorAll('input[data-color]').forEach(function (i) {
                bandas[i.dataset.color] = bandas[i.dataset.color] || {};
                bandas[i.dataset.color][i.dataset.lim] = i.value;
            });
            datos = { action: 'guardar_umbral', id: tr.dataset.id, bandas: JSON.stringify(bandas) };
        } else {
            datos = { action: 'guardar_caida', valor: document.getElementById('ieCaida').value };
        }

        boton.disabled = true;
        var r = await IE.post(URL, datos);
        boton.disabled = false;

        if (!r.ok) {
            alert(r.message || 'No se pudo guardar.');
            return;
        }

        await cargar();
    }

    function init() {
        d = null;
        seccion = 'estructura';

        document.querySelectorAll('.ie-param-nav [data-sec]').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('.ie-param-nav [data-sec]').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                seccion = b.dataset.sec;
                if (d) dibujar();
            });
        });

        document.getElementById('ieCuerpo').addEventListener('click', function (e) {
            var b = e.target.closest('[data-guardar]');
            if (b && !b.disabled) guardar(b);
        });

        cargar();
    }

    return { init: init, cargar: cargar, sinFiltros: true, irASeccion: function (s) {
        var b = document.querySelector('.ie-param-nav [data-sec="' + s + '"]');
        if (b) b.click();
    } };
})();
