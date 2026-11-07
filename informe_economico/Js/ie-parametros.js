/**
 * ie-parametros.js
 * La pestaña Parámetros: estructura de filas, rubros (alta, nombre,
 * categoría, baja y reactivación), umbrales del semáforo y el parámetro de
 * alerta.
 *
 * Sin 'ie.editar' todo se ve y nada se puede guardar: los campos se dibujan
 * deshabilitados, y el controller rechaza igual cualquier guardado (403).
 *
 * Las reglas de los rubros (formato del código, largos, nombre repetido,
 * coherencia con la categoría) viven en el servidor (Class/Rubros.php) y no
 * acá: el modal muestra lo que el servidor contesta. Así hay una sola versión
 * de cada regla, probada.
 */
'use strict';

IE.tabs.parametros = (function () {

    var d = null;
    var seccion = 'estructura';
    var URL = 'Controller/ParametrosController.php';

    var resaltar = null;   // el código del rubro recién guardado
    var mensajeOk = null;  // lo que se muestra arriba de la tabla después de guardar
    var modal = null;      // {tipo: 'alta', codFijo} | {tipo: 'estado', cod, activo}
    var modalListo = false;

    var AVISO_CONTROL_GASTOS = 'Control de Gastos no filtra los rubros inactivos: el rubro va a seguir apareciendo en sus desplegables '
        + 'y las cuentas relacionadas van a seguir imputándole gastos. Si no querés que reciba más importes, quitá o cambiá sus relaciones en Control de Gastos.';
    var MSJ_ALTA = 'El rubro ya aparece en el informe, en su sección. Para que tenga importes hay que relacionarle cuentas en '
        + 'Control de Gastos (Gestión de Relaciones Cuenta-Rubro) y procesar el período.';
    var MSJ_NOMBRE = 'El nombre se usa en el informe y en los desplegables de Control de Gastos. Los gastos ya clasificados conservan el nombre anterior.';

    function dis() {
        return d && d.puedeEditar ? '' : ' disabled';
    }

    /** Alta, nombre, baja y reactivación: además del permiso, piden el script 5 */
    function disRub() {
        if (!d || !d.puedeEditar) return ' disabled';
        return d.auditoriaRubros ? '' : ' disabled title="Falta correr ' + IE.esc(d.scriptAuditoriaRubros) + '"';
    }

    function auditoria(u, f) {
        return u ? '<span class="ie-null" style="font-size:11px">' + IE.esc(u) + (f ? ' · ' + IE.esc(f.substring(0, 16)) : '') + '</span>' : '';
    }

    function aviso(nivel, html) {
        var icono = { info: 'bi-check-circle', warning: 'bi-exclamation-triangle', danger: 'bi-x-octagon' }[nivel];
        return '<div class="ie-aviso ie-aviso-' + nivel + '"><i class="bi ' + icono + '"></i><div>' + html + '</div></div>';
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
        var h = (mensajeOk ? aviso('info', IE.esc(mensajeOk)) : '')
            + aviso('warning', 'El maestro de rubros es de <strong>Control de Gastos</strong>: un rubro nuevo y un cambio de nombre se ven también en sus desplegables, '
                + 'y la categoría (CAT_RUBRO_CONTABLE) la usa además <strong>Rentabilidad por Rubro</strong>: cambiarla acá mueve los dos informes. '
                + 'Control de Gastos no filtra los rubros dados de baja. Cada cambio queda auditado. Los rubros de ventas y costo (1.x y 2) no se editan: la estructura los ubica por su código.')
            + (d.puedeEditar && !d.auditoriaRubros
                ? '<p class="ie-nota"><i class="bi bi-lock"></i> Alta, nombre, baja y reactivación deshabilitados: falta correr <code>' + IE.esc(d.scriptAuditoriaRubros) + '</code>. La categoría se puede cambiar igual.</p>'
                : '')
            + '<div style="margin-top:12px"><button class="ie-btn ie-btn-primario ie-btn-sm" type="button" data-rubro="nuevo"' + disRub() + '><i class="bi bi-plus-lg"></i> Nuevo rubro</button></div>'
            + '<table class="ie-tabla-simple" style="margin-top:12px"><thead><tr><th>Código</th><th style="text-align:left">Rubro</th><th style="text-align:left">Categoría</th>'
            + '<th>Registros en el resumen</th><th>Estado</th><th>Último cambio</th><th></th></tr></thead><tbody>';

        d.rubros.forEach(function (r) {
            var editable = !r.fijo && r.enMaestro;
            var opciones = '<option value="">— sin categoría —</option>' + d.categorias.map(function (c) {
                return '<option' + (c === r.cat ? ' selected' : '') + '>' + IE.esc(c) + '</option>';
            }).join('');

            var estado = r.sinSeccion ? '<span class="ie-badge ie-badge-pisado">sin sección</span>'
                : (!r.enMaestro ? '<span class="ie-badge ie-badge-pisado">no está en el maestro</span>'
                    : (r.fijo ? '<span class="ie-null">ventas / costo</span>' : (r.activo === 0 ? '<span class="ie-null">dado de baja</span>' : '')));
            if (r.sinSeccion && !r.enMaestro) estado += ' <span class="ie-badge ie-badge-pisado">no está en el maestro</span>';

            var nombre = editable
                ? '<div class="ie-celda-edit"><input type="text" class="ie-input ie-input-sm" style="width:300px" name="nombre" maxlength="' + d.largos.nombre
                    + '" value="' + IE.esc(r.nombre || '') + '"' + disRub() + '>'
                    + '<button class="ie-btn ie-btn-sec ie-btn-sm" type="button" data-guardar="nombre" title="Guardar el nombre"' + disRub() + '><i class="bi bi-floppy"></i></button></div>'
                : (r.enMaestro ? IE.esc(r.nombre || '—')
                    : '<span class="ie-null">' + (r.nombreSugerido ? 'en el resumen: ' + IE.esc(r.nombreSugerido) : '—') + '</span>');

            var categoria = editable
                ? '<div class="ie-celda-edit"><select class="ie-select ie-input-sm" name="cat"' + dis() + '>' + opciones + '</select>'
                    + '<button class="ie-btn ie-btn-sec ie-btn-sm" type="button" data-guardar="categoria" title="Guardar la categoría"' + dis() + '><i class="bi bi-floppy"></i></button></div>'
                : IE.esc(r.cat || '—');

            var accion = '';
            if (editable) {
                accion = r.activo === 0
                    ? '<button class="ie-btn ie-btn-sec ie-btn-sm" type="button" data-rubro="reactivar"' + disRub() + '><i class="bi bi-arrow-counterclockwise"></i> Reactivar</button>'
                    : '<button class="ie-btn ie-btn-sec ie-btn-sm" type="button" data-rubro="baja"' + disRub() + '><i class="bi bi-slash-circle"></i> Dar de baja</button>';
            } else if (!r.enMaestro) {
                accion = '<button class="ie-btn ie-btn-primario ie-btn-sm" type="button" data-rubro="alta-resumen"' + disRub() + '><i class="bi bi-plus-lg"></i> Dar de alta</button>';
            }

            var clases = [r.sinSeccion ? 'ie-fila-sin-seccion' : '', r.activo === 0 ? 'ie-fila-inactiva' : '', r.cod === resaltar ? 'ie-fila-resaltada' : ''].join(' ');

            h += '<tr class="' + clases + '" data-cod="' + IE.esc(r.cod) + '"><td>' + IE.esc(r.cod) + '</td>'
                + '<td class="ie-izq">' + nombre + '</td>'
                + '<td class="ie-izq">' + categoria + '</td>'
                + '<td>' + r.registros + '</td><td>' + estado + '</td>'
                + '<td>' + (r.ultimo ? auditoria(r.ultimo.usuario, r.ultimo.fecha) : '') + '</td>'
                + '<td style="white-space:nowrap">' + accion + '</td></tr>';
        });

        return h + '</tbody></table>';
    }

    function rubro(cod) {
        return d.rubros.find(function (r) { return r.cod === cod; }) || null;
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

        // El rubro recién guardado queda a la vista: en una lista de ~55 se pierde
        if (seccion === 'rubros' && resaltar) {
            var tr = document.querySelector('#ieCuerpo tr[data-cod="' + CSS.escape(resaltar) + '"]');
            if (tr) tr.scrollIntoView({ block: 'center' });
        }
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

    /**
     * Manda un guardado. Si el servidor contesta que hay avisos para
     * confirmar (nombre repetido), pregunta y reenvía con confirmar=1.
     */
    async function enviarConfirmando(datos, pregunta) {
        var r = await IE.post(URL, datos);

        if (r.confirmar) {
            if (!confirm(r.avisos.join('\n\n') + '\n\n' + pregunta)) return null;
            datos.confirmar = '1';
            r = await IE.post(URL, datos);
        }

        return r;
    }

    async function guardar(boton) {
        var tipo = boton.dataset.guardar;
        var tr = boton.closest('tr');
        var datos;
        var pregunta = '¿Guardar igual?';

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
        } else if (tipo === 'nombre') {
            var nombre = tr.querySelector('[name=nombre]').value.trim();
            if (!confirm(MSJ_NOMBRE + '\n\n¿Cambiar el nombre de ' + tr.dataset.cod + ' a "' + nombre + '"?')) return;
            datos = { action: 'guardar_nombre', cod: tr.dataset.cod, nombre: nombre, confirmar: '0' };
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
        var r = await enviarConfirmando(datos, pregunta);
        boton.disabled = false;

        if (r === null) return;

        if (!r.ok) {
            alert(r.message || 'No se pudo guardar.');
            return;
        }

        mensajeOk = null;
        resaltar = tr && tr.dataset.cod ? tr.dataset.cod : null;
        await cargar();
    }

    /* ── Modal de rubros: alta y baja / reactivación ── */

    function el(id) { return document.getElementById(id); }

    function mensajeModal(nivel, html) {
        el('ieRubMsg').innerHTML = html ? aviso(nivel, html) : '';
    }

    /**
     * Alta. Con codFijo es "Dar de alta" un rubro que está en el resumen y no
     * en el maestro: el código no se edita (es el de sus registros) y el
     * nombre sugerido es el del resumen.
     */
    function abrirAlta(codFijo, nombreSugerido) {
        modal = { tipo: 'alta', codFijo: codFijo || null, propuesto: '', confirmado: false };

        el('ieRubTitulo').textContent = codFijo ? 'Dar de alta el rubro ' + codFijo : 'Nuevo rubro';
        el('ieRubSubtitulo').textContent = codFijo
            ? 'Está en el resumen pero no en el maestro: al darlo de alta deja de estar "sin sección" y suma en su sección.'
            : 'Se agrega al maestro de rubros, compartido con Control de Gastos.';

        el('ieRubCuerpo').innerHTML =
            '<label for="ieRubCat">Categoría</label>'
            + '<select class="ie-select" id="ieRubCat"><option value="">— elegí una categoría —</option>'
            + d.categorias.map(function (c) { return '<option>' + IE.esc(c) + '</option>'; }).join('') + '</select>'
            + '<label for="ieRubCod">Código</label>'
            + '<input type="text" class="ie-input" id="ieRubCod" maxlength="' + (d.largos.codigo + 4) + '" autocomplete="off" placeholder="Ej.: 7.19."'
            + (codFijo ? ' value="' + IE.esc(codFijo) + '" readonly' : '') + '>'
            + '<div class="ie-nota" id="ieRubCodNota">' + (codFijo ? 'Es el código de sus registros en el resumen: no se cambia.'
                : 'Elegí primero la categoría: el código se propone solo. Números separados por punto, con punto final, hasta ' + d.largos.codigo + ' caracteres.') + '</div>'
            + '<label for="ieRubNombre">Nombre</label>'
            + '<input type="text" class="ie-input" id="ieRubNombre" maxlength="' + d.largos.nombre + '" autocomplete="off" value="' + IE.esc(nombreSugerido || '') + '">'
            + '<div class="ie-nota">Hasta ' + d.largos.nombre + ' caracteres.' + (nombreSugerido ? ' Sugerido: el nombre más reciente en el resumen.' : '') + '</div>'
            + '<div id="ieRubMsg"></div>'
            + '<div id="ieRubLista"></div>';

        el('ieRubAceptar').innerHTML = '<i class="bi bi-plus-lg"></i> Dar de alta';
        el('ieRubAceptar').disabled = false;
        IE.abrirModal('ieModalRubro');
        el('ieRubCat').focus();
    }

    async function alElegirCategoria() {
        var cat = el('ieRubCat').value;
        modal.confirmado = false;
        mensajeModal();
        el('ieRubLista').innerHTML = '';

        if (!cat) return;

        el('ieRubLista').innerHTML = '<p class="ie-nota">Leyendo los rubros de la categoría…</p>';
        var r = await IE.post(URL, { action: 'proponer_codigo', cat: cat });

        if (!r.ok) {
            el('ieRubLista').innerHTML = '';
            mensajeModal('danger', IE.esc(r.message || 'No se pudo leer la categoría.'));
            return;
        }

        if (!modal.codFijo) {
            var cod = el('ieRubCod');

            // Se pisa solo si el usuario no escribió otro código a mano
            if (cod.value.trim() === '' || cod.value.trim() === modal.propuesto) {
                cod.value = r.codigo || '';
            }

            modal.propuesto = r.codigo || '';
            el('ieRubCodNota').textContent = r.codigo
                ? 'Propuesto: ' + r.codigo + ', el siguiente libre de la categoría. Lo podés cambiar.'
                : 'Esta categoría no tiene una numeración de la que proponer el siguiente: cargá el código a mano.';
        }

        var h = '<label>Rubros que ya tiene "' + IE.esc(cat) + '"' + (r.prefijo ? ' (empiezan con ' + IE.esc(r.prefijo) + ')' : '') + '</label>';

        if (!r.rubros.length) {
            h += '<p class="ie-nota">Ninguno.</p>';
        } else {
            h += '<table class="ie-tabla-simple"><thead><tr><th>Código</th><th style="text-align:left">Rubro</th></tr></thead><tbody>'
                + r.rubros.map(function (x) {
                    return '<tr class="' + (x.activo ? '' : 'ie-fila-inactiva') + '"><td>' + IE.esc(x.cod) + '</td><td class="ie-izq">'
                        + IE.esc(x.nombre) + (x.activo ? '' : ' <span class="ie-null">(dado de baja)</span>') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }

        el('ieRubLista').innerHTML = h;
    }

    async function guardarAlta() {
        var datos = {
            action: 'alta_rubro',
            cat: el('ieRubCat').value,
            cod: el('ieRubCod').value,
            nombre: el('ieRubNombre').value,
            confirmar: modal.confirmado ? '1' : '0'
        };

        el('ieRubAceptar').disabled = true;
        var r = await IE.post(URL, datos);
        el('ieRubAceptar').disabled = false;

        // Avisos que no bloquean (código fuera de la numeración, nombre
        // repetido): se muestran en el modal y el botón pasa a confirmar.
        if (r.confirmar) {
            modal.confirmado = true;
            mensajeModal('warning', r.avisos.map(IE.esc).join('<br>') + '<br><strong>Si es correcto, confirmá el alta.</strong>');
            el('ieRubAceptar').innerHTML = '<i class="bi bi-check-lg"></i> Confirmar y dar de alta';
            return;
        }

        if (!r.ok) {
            mensajeModal('danger', IE.esc(r.message || 'No se pudo dar de alta.'));
            return;
        }

        IE.cerrarModal('ieModalRubro');
        resaltar = r.cod;
        mensajeOk = 'Rubro ' + r.cod + ' dado de alta. ' + MSJ_ALTA;
        await cargar();
    }

    /** Cualquier cambio en el formulario vuelve a pedir la revisión del servidor */
    function alEditarAlta() {
        if (!modal || modal.tipo !== 'alta' || !modal.confirmado) return;
        modal.confirmado = false;
        mensajeModal();
        el('ieRubAceptar').innerHTML = '<i class="bi bi-plus-lg"></i> Dar de alta';
    }

    async function abrirEstado(cod, activo) {
        var r0 = rubro(cod);
        modal = { tipo: 'estado', cod: cod, activo: activo };

        el('ieRubTitulo').textContent = (activo ? 'Reactivar el rubro ' : 'Dar de baja el rubro ') + cod;
        el('ieRubSubtitulo').textContent = r0 ? (r0.nombre || '') + (r0.cat ? ' · ' + r0.cat : '') : '';
        el('ieRubCuerpo').innerHTML = '<p class="ie-nota">Leyendo cuentas relacionadas y registros…</p>';
        el('ieRubAceptar').innerHTML = activo ? '<i class="bi bi-arrow-counterclockwise"></i> Reactivar' : '<i class="bi bi-slash-circle"></i> Dar de baja';
        el('ieRubAceptar').disabled = true;
        IE.abrirModal('ieModalRubro');

        var r = await IE.post(URL, { action: 'info_baja', cod: cod });

        if (!r.ok) {
            el('ieRubCuerpo').innerHTML = aviso('danger', IE.esc(r.message || 'No se pudo leer el rubro.'));
            return;
        }

        var h = '<table class="ie-tabla-simple"><tbody>'
            + '<tr><td>Cuentas relacionadas en Control de Gastos</td><td><strong>' + r.relaciones + '</strong></td></tr>'
            + '<tr><td>Registros en el resumen</td><td><strong>' + r.registros + '</strong>'
            + (r.ultimoPeriodo ? ' <span class="ie-null">(último período: ' + IE.esc(IE.nombreMes(r.ultimoPeriodo)) + ')</span>' : '') + '</td></tr>'
            + '</tbody></table>';

        h += activo
            ? aviso('info', 'Vuelve a estar activo: el informe lo muestra en su sección aunque no tenga datos en el rango.')
            : aviso('warning', IE.esc(AVISO_CONTROL_GASTOS)
                + '<br><br>En el informe: si no tiene datos en el rango elegido deja de mostrarse; si los tiene, se sigue mostrando y sumando. No se borra nada.');

        h += '<label for="ieRubMotivo">Motivo (obligatorio)</label>'
            + '<textarea class="ie-input" id="ieRubMotivo" maxlength="' + d.largos.motivo + '"></textarea>'
            + '<div id="ieRubMsg"></div>';

        el('ieRubCuerpo').innerHTML = h;
        el('ieRubAceptar').disabled = false;
        el('ieRubMotivo').focus();
    }

    async function guardarEstado() {
        var motivo = el('ieRubMotivo').value.trim();

        if (!motivo) {
            mensajeModal('danger', 'El motivo es obligatorio.');
            return;
        }

        el('ieRubAceptar').disabled = true;
        var r = await IE.post(URL, { action: 'cambiar_estado', cod: modal.cod, activo: modal.activo ? '1' : '0', motivo: motivo });
        el('ieRubAceptar').disabled = false;

        if (!r.ok) {
            mensajeModal('danger', IE.esc(r.message || 'No se pudo guardar.'));
            return;
        }

        IE.cerrarModal('ieModalRubro');
        resaltar = modal.cod;
        mensajeOk = 'Rubro ' + modal.cod + (modal.activo ? ' reactivado.' : ' dado de baja. ' + AVISO_CONTROL_GASTOS);
        await cargar();
    }

    /** El modal vive fuera de la pestaña: sus eventos se enganchan una sola vez */
    function prepararModal() {
        if (modalListo) return;
        modalListo = true;

        el('ieRubAceptar').addEventListener('click', function () {
            if (!modal) return;
            if (modal.tipo === 'alta') guardarAlta();
            else guardarEstado();
        });

        el('ieRubCuerpo').addEventListener('change', function (e) {
            if (e.target.id === 'ieRubCat') {
                alEditarAlta();
                alElegirCategoria();
            }
        });

        el('ieRubCuerpo').addEventListener('input', function (e) {
            if (e.target.id === 'ieRubCod' || e.target.id === 'ieRubNombre') alEditarAlta();
        });
    }

    function accionRubro(boton) {
        var tipo = boton.dataset.rubro;
        var tr = boton.closest('tr');
        var cod = tr ? tr.dataset.cod : null;
        mensajeOk = null;

        if (tipo === 'nuevo') abrirAlta(null, '');
        else if (tipo === 'alta-resumen') abrirAlta(cod, (rubro(cod) || {}).nombreSugerido || '');
        else if (tipo === 'baja') abrirEstado(cod, false);
        else if (tipo === 'reactivar') abrirEstado(cod, true);
    }

    function init() {
        d = null;
        seccion = 'estructura';
        resaltar = null;
        mensajeOk = null;
        prepararModal();

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

            var a = e.target.closest('[data-rubro]');
            if (a && !a.disabled) accionRubro(a);
        });

        cargar();
    }

    return { init: init, cargar: cargar, sinFiltros: true, irASeccion: function (s) {
        var b = document.querySelector('.ie-param-nav [data-sec="' + s + '"]');
        if (b) b.click();
    } };
})();
