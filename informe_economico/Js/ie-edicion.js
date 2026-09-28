/**
 * ie-edicion.js
 * El modal de edición de importes.
 *
 * Una fila por REGISTRO de la tabla: si la celda cubre varias sucursales o
 * varios meses (LOCALES x 5.1.1 en doce meses), aparecen todos; y los
 * registros repetidos, cada uno por separado. Se edita siempre por ID.
 *
 * Cada acción manda el importe que se está viendo: si en el medio cambió (otro
 * usuario, un reproceso), el servidor no escribe y avisa. Después de guardar
 * se recalcula la pestaña sin recargar la página.
 */
'use strict';

IE.Edicion = (function () {

    var ctx = null;   // {payload, col, cod, etiqueta}
    var datos = null; // el detalle que devolvió el servidor

    async function abrir(payload, col, cod, etiqueta) {
        ctx = { payload: payload, col: col, cod: cod, etiqueta: etiqueta };
        var c = payload.columnas.find(function (x) { return x.clave === col; });

        document.getElementById('ieEdTitulo').textContent = etiqueta;
        document.getElementById('ieEdSubtitulo').textContent = (c ? c.etiqueta : col) + ' · ' + IE.rangoTexto(payload.filtros[col].periodos);
        IE.abrirModal('ieModalEdicion');
        await refrescar();
    }

    async function refrescar() {
        IE.cargando('ieEdCuerpo', 'Leyendo registros…');
        var f = ctx.payload.filtros[ctx.col];

        var r = await IE.post('Controller/InformeController.php', {
            action: 'detalle',
            sucursales: JSON.stringify(f.sucursales),
            periodos: JSON.stringify(f.periodos),
            cod: ctx.cod
        });

        if (!r.ok) {
            document.getElementById('ieEdCuerpo').innerHTML = '<div class="ie-aviso ie-aviso-danger">' + IE.esc(r.message) + '</div>';
            return;
        }

        datos = r;
        pintar();
    }

    function sucursal(r) {
        return r.nroSucursal === null || Number(r.nroSucursal) === 0 ? 'Sin sucursal' : r.nroSucursal;
    }

    function pintar() {
        var h = [];

        if (datos.mesesExcluidos && datos.mesesExcluidos.length) {
            h.push('<div class="ie-aviso ie-aviso-warning">Meses sin resumen, que no se editan: '
                + IE.esc(datos.mesesExcluidos.map(IE.nombreMes).join(', ')) + '</div>');
        }

        if (!datos.registros.length) {
            h.push('<p class="ie-nota">No hay registros de este rubro en la celda. Desde acá no se crean registros: solo se corrige el importe de los que existen.</p>');
        } else {
            var total = datos.registros.reduce(function (a, r) { return a + r.importe; }, 0);

            h.push('<p class="ie-nota">' + datos.registros.length + ' registro(s) · total ' + IE.fmtExacto(total)
                + '. Los importes van en pesos, con dos decimales.</p>');
            h.push('<table class="ie-tabla-simple"><thead><tr><th>Período</th><th>Sucursal</th><th>ID</th><th>DESC_SUCURSAL</th>'
                + '<th>RUBRO_CONTABLE</th><th>Importe</th><th>Estado</th><th></th></tr></thead><tbody>');

            datos.registros.forEach(function (r, i) {
                var estado = r.pisado ? '<span class="ie-badge ie-badge-pisado" title="Un reproceso cambió el valor editado">pisado por reproceso</span>'
                    : (r.editado ? '<span class="ie-badge ie-badge-editado">editado</span>' : '');

                h.push('<tr><td>' + IE.esc(r.periodo) + '</td><td>' + IE.esc(sucursal(r)) + '</td><td>' + r.id + '</td><td>'
                    + IE.esc(r.descSucursal || '') + '</td><td>' + IE.esc(r.rubro || '') + '</td><td>' + IE.fmtExacto(r.importe)
                    + '</td><td>' + estado + '</td><td style="white-space:nowrap">'
                    + '<button class="ie-btn ie-btn-sec ie-btn-sm" data-accion="editar" data-i="' + i + '"><i class="bi bi-pencil"></i> Editar</button> '
                    + boton('deshacer', i, r.deshacerA, r.pisado, 'bi-arrow-counterclockwise', 'Deshacer')
                    + boton('restaurar', i, r.restaurarA, r.pisado, 'bi-arrow-repeat', 'Restaurar original')
                    + (r.historial.length ? ' <button class="ie-btn ie-btn-sec ie-btn-sm" data-accion="historial" data-i="' + i + '"><i class="bi bi-clock-history"></i> ' + r.historial.length + '</button>' : '')
                    + '</td></tr>');
                h.push('<tr class="ie-form-edicion" id="ieEdForm' + i + '" style="display:none"><td colspan="8"></td></tr>');
            });

            h.push('</tbody></table>');
        }

        if (datos.perdidas && datos.perdidas.length) {
            h.push('<h4 style="margin:18px 0 6px;font-size:13px"><i class="bi bi-archive"></i> Ediciones perdidas por un reproceso</h4>'
                + '<p class="ie-nota">El registro editado ya no existe: el período se volvió a generar desde Control de Gastos. Quedan como referencia; no se pueden deshacer ni restaurar.</p>'
                + tablaHistorial(datos.perdidas, true));
        }

        document.getElementById('ieEdCuerpo').innerHTML = h.join('');
    }

    function boton(accion, i, destino, pisado, icono, texto) {
        var dis = pisado || destino === null;
        var titulo = pisado ? 'Un reproceso pisó este registro: ya no aplica'
            : (destino === null ? (accion === 'deshacer' ? 'No hay nada para deshacer' : 'Ya está en el valor original')
                : 'Vuelve a ' + IE.fmtExacto(destino));

        return '<button class="ie-btn ie-btn-sec ie-btn-sm" data-accion="' + accion + '" data-i="' + i + '"'
            + (dis ? ' disabled' : '') + ' title="' + IE.esc(titulo) + '"><i class="bi ' + icono + '"></i> ' + texto + '</button> ';
    }

    function tablaHistorial(movs, conRegistro) {
        return '<table class="ie-tabla-simple ie-historial"><thead><tr>' + (conRegistro ? '<th>ID</th><th>Período</th><th>Sucursal</th>' : '')
            + '<th>Fecha</th><th>Usuario</th><th>Tipo</th><th>Anterior</th><th>Nuevo</th><th>Motivo</th></tr></thead><tbody>'
            + movs.map(function (m) {
                return '<tr>' + (conRegistro ? '<td>' + m.ID_REGISTRO + '</td><td>' + IE.esc(m.PERIODO) + '</td><td>'
                    + IE.esc(m.NRO_SUCURSAL === null ? 'Sin sucursal' : m.NRO_SUCURSAL) + '</td>' : '')
                    + '<td>' + IE.esc(m.FECHA) + '</td><td>' + IE.esc(m.USUARIO) + '</td><td>' + IE.esc(m.TIPO) + '</td><td>'
                    + IE.fmtExacto(m.IMPORTE_ANTERIOR) + '</td><td>' + IE.fmtExacto(m.IMPORTE_NUEVO) + '</td><td style="font-family:var(--ie-sans)">'
                    + IE.esc(m.MOTIVO) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    /** Abre el formulario de una acción debajo del registro */
    function formulario(accion, i) {
        var r = datos.registros[i];
        var tr = document.getElementById('ieEdForm' + i);
        var td = tr.firstElementChild;

        if (tr.style.display !== 'none' && tr.dataset.accion === accion) {
            tr.style.display = 'none';
            return;
        }

        tr.dataset.accion = accion;
        tr.style.display = '';

        if (accion === 'historial') {
            td.innerHTML = '<div class="ie-historial">Original (valor del proceso): ' + IE.fmtExacto(r.original) + '</div>' + tablaHistorial(r.historial, false);
            return;
        }

        var campos = '';

        if (accion === 'editar') {
            campos = '<label>Nuevo importe <input type="text" class="ie-input ie-input-sm" id="ieEdImporte' + i + '" value="'
                + r.importe.toFixed(2).replace('.', ',') + '" style="width:160px"></label>';
        } else {
            var destino = accion === 'deshacer' ? r.deshacerA : r.restaurarA;
            campos = '<span>' + (accion === 'deshacer' ? 'Deshacer' : 'Restaurar original') + ': '
                + IE.fmtExacto(r.importe) + ' → <strong>' + IE.fmtExacto(destino) + '</strong></span>';
        }

        td.innerHTML = '<div class="ie-form-row">' + campos
            + '<label style="flex:1">Motivo <input type="text" class="ie-input ie-input-sm" id="ieEdMotivo' + i + '" maxlength="500" style="width:100%" placeholder="Obligatorio"></label>'
            + '<button class="ie-btn ie-btn-primario ie-btn-sm" data-accion="confirmar" data-tipo="' + accion + '" data-i="' + i + '"><i class="bi bi-check2"></i> Guardar</button>'
            + '<span id="ieEdError' + i + '" class="ie-neg" style="font-size:12px"></span></div>';

        var foco = document.getElementById(accion === 'editar' ? 'ieEdImporte' + i : 'ieEdMotivo' + i);
        if (foco) foco.focus();
    }

    async function confirmar(tipo, i, boton) {
        var r = datos.registros[i];
        var motivo = document.getElementById('ieEdMotivo' + i).value.trim();
        var err = document.getElementById('ieEdError' + i);

        if (!motivo) {
            err.textContent = 'El motivo es obligatorio.';
            return;
        }

        var d = { action: tipo, id: r.id, visto: String(r.importe), motivo: motivo };

        if (tipo === 'editar') {
            var imp = document.getElementById('ieEdImporte' + i).value.trim();

            if (!/^-?[\d.]*\d(,\d{1,2})?$/.test(imp) && !/^-?\d+(\.\d{1,2})?$/.test(imp)) {
                err.textContent = 'Número con hasta 2 decimales (ej: 1.234,56).';
                return;
            }

            d.importe = imp;
        }

        boton.disabled = true;
        var res = await IE.post('Controller/InformeController.php', d);
        boton.disabled = false;

        if (!res.ok) {
            err.textContent = res.message || 'No se pudo guardar.';
            return;
        }

        await refrescar();

        // Recalcula la pestaña con el importe nuevo, sin recargar la página
        if (IE.recargarPestana) IE.recargarPestana();
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('#ieEdCuerpo [data-accion]');
        if (!b || b.disabled) return;

        var i = parseInt(b.dataset.i, 10);

        if (b.dataset.accion === 'confirmar') {
            confirmar(b.dataset.tipo, i, b);
        } else {
            formulario(b.dataset.accion, i);
        }
    });

    return { abrir: abrir };
})();
