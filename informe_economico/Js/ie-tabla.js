/**
 * ie-tabla.js
 * Dibuja la cascada como tabla. La usan IE por Canales, IE por Locales y
 * Evolución Mensual: las tres tienen las mismas filas y cambian las columnas
 * y lo que va dentro de cada una.
 *
 * Una columna del servidor se abre en SUB-COLUMNAS, según la pestaña:
 *
 *   Canales  importe + %            (+ año anterior + var %  con comparativo)
 *   Locales  importe (+ % si "Mostrar %")  (+ var %          con comparativo)
 *   Mensual  cada mes: una sola, la que elige el selector (actual / AA / var)
 *            Total: actual (+ año anterior + var %            con comparativo)
 *
 * No se calcula nada acá: todo número viene del servidor. Esta capa decide
 * solo qué se muestra y cómo.
 */
'use strict';

IE.Tabla = (function () {

    var ROTULOS = { v: 'Importe', pct: '% s/1.9', aa: 'Año ant.', var: 'Var %' };

    function subcolumnas(col, p, opts) {
        if (opts.modo === 'canales') {
            return p.comparar ? ['v', 'pct', 'aa', 'var'] : ['v', 'pct'];
        }

        if (opts.modo === 'locales') {
            var s = ['v'];
            if (opts.pct) s.push('pct');
            if (p.comparar) s.push('var');
            return s;
        }

        // mensual
        if (col.grupo === 'MES') {
            return p.comparar ? [opts.modoMes || 'v'] : ['v'];
        }

        return p.comparar ? ['v', 'aa', 'var'] : ['v'];
    }

    function claseColumna(col) {
        if (col.grupo === 'TOTAL') return 'ie-col-total';
        if (col.grupo === 'SUBTOTAL') return 'ie-col-subtotal';
        if (col.grupo === 'SIN') return 'ie-col-sin';
        return '';
    }

    function claseFila(f) {
        if (f.tipo === 'RUBRO') return 'ie-tr-rubro';
        if (f.enfasis === 'resultado') return 'ie-tr-resultado';
        if (f.enfasis === 'contribucion') return 'ie-tr-contribucion';
        if (f.enfasis === 'total') return 'ie-tr-total';
        if (f.enfasis === 'subtotal') return 'ie-tr-subtotal';
        if (f.tipo === 'RATIO') return 'ie-tr-ratio';
        return '';
    }

    function contenido(sub, f, c, moneda) {
        if (!c) return IE.nulo;
        var esRatio = f.tipo === 'RATIO';

        switch (sub) {
            case 'pct': return esRatio ? '' : '<span class="ie-pct">' + IE.fmtPct(c.pct) + '</span>';
            case 'aa': return IE.fmtValor(c.aa, f.formato, moneda);
            case 'var': return IE.fmtVar(c['var'], esRatio);
            default: return IE.fmtValor(c.v, f.formato, moneda);
        }
    }

    /** El tooltip de una celda editada: original, anterior inmediato, quién, cuándo y por qué */
    function tooltip(marcas) {
        return marcas.map(function (m) {
            return 'Registro ' + m.id + ' — editado (' + m.tipo + ')\n'
                + 'Original: ' + IE.fmtExacto(m.original) + '\n'
                + 'Anterior: ' + IE.fmtExacto(m.anterior) + ' → Actual: ' + IE.fmtExacto(m.nuevo) + '\n'
                + m.usuario + ', ' + m.fecha + '\n'
                + 'Motivo: ' + m.motivo;
        }).join('\n\n') + '\n(importes en pesos)';
    }

    /**
     * @param {string} idCuerpo contenedor
     * @param {object} p payload de InformeController (action=informe)
     * @param {object} opts {modo, pct, modoMes}
     */
    function dibujar(idCuerpo, p, opts) {
        var cont = document.getElementById(idCuerpo);
        var cols = p.columnas;
        var subs = cols.map(function (c) { return subcolumnas(c, p, opts); });
        var total = subs.reduce(function (a, s) { return a + s.length; }, 0);
        var editar = !!(p.puedeEditar && p.edicionDisponible);
        var h = [];

        h.push('<div class="ie-tabla-wrap"><table class="ie-tabla" id="ieTabla"><thead><tr><th rowspan="2" class="ie-col-rubro">Rubro</th>');

        cols.forEach(function (c, i) {
            var sub = c.sub ? '<span class="ie-th-sub">' + IE.esc(c.sub) + '</span>' : '';

            if (c.cerrada) sub += '<span class="ie-th-sub">cerrada</span>';

            h.push('<th class="ie-th-grupo ' + claseColumna(c) + (c.cerrada ? ' ie-cerrada' : '') + '" colspan="' + subs[i].length
                + '" data-col="' + IE.esc(c.clave) + '">' + IE.esc(c.etiqueta) + sub + '</th>');
        });

        h.push('</tr><tr>');

        cols.forEach(function (c, i) {
            subs[i].forEach(function (s, j) {
                var rot = ROTULOS[s];
                if (opts.modo === 'mensual' && c.grupo === 'MES' && s === 'v') rot = p.comparar ? 'Actual' : 'Importe';
                h.push('<th class="' + claseColumna(c) + (j === 0 ? ' ie-inicio-grupo' : '') + '">' + rot + '</th>');
            });
        });

        h.push('</tr></thead><tbody>');

        p.filas.forEach(function (f) {
            if (f.tipo === 'TITULO') {
                // El rotulo va en la celda fija de RUBRO, para que se siga
                // viendo al scrollear a lo ancho; el resto es una sola celda.
                h.push('<tr class="ie-tr-titulo' + (f.alerta ? ' ie-tr-alerta' : '') + '"><td class="ie-col-rubro">'
                    + IE.esc(f.etiqueta) + '</td><td colspan="' + total + '"></td></tr>');
                return;
            }

            h.push('<tr class="' + claseFila(f) + '"><td class="ie-col-rubro">' + IE.esc(f.etiqueta) + '</td>');

            cols.forEach(function (c, i) {
                var celda = f.valores[c.clave];
                var marcas = (f.cod && p.marcas && p.marcas[c.clave]) ? p.marcas[c.clave][f.cod] : null;

                subs[i].forEach(function (s, j) {
                    var cls = [claseColumna(c)];
                    var attrs = '';

                    if (j === 0) cls.push('ie-inicio-grupo');

                    if (s === 'v' && f.tipo === 'RUBRO') {
                        if (editar && c.editable) {
                            cls.push('ie-editable');
                            attrs += ' data-col="' + IE.esc(c.clave) + '" data-cod="' + IE.esc(f.cod) + '"';
                        }

                        if (marcas && marcas.length) {
                            cls.push('ie-editada');
                            attrs += ' title="' + IE.esc(tooltip(marcas)) + '"';
                        }
                    }

                    h.push('<td class="' + cls.join(' ') + '"' + attrs + '>' + contenido(s, f, celda, p.moneda) + '</td>');
                });
            });

            h.push('</tr>');
        });

        h.push('</tbody></table></div>');
        cont.innerHTML = h.join('');

        cont.querySelectorAll('.ie-editable').forEach(function (td) {
            td.addEventListener('click', function () {
                var fila = p.filas.find(function (x) { return x.cod === td.dataset.cod && x.tipo === 'RUBRO'; });
                IE.Edicion.abrir(p, td.dataset.col, td.dataset.cod, fila ? fila.etiqueta : td.dataset.cod);
            });
        });
    }

    /** Lleva la vista a una columna y la resalta: lo usan los links de las alertas */
    function resaltarColumna(clave) {
        var th = document.querySelector('#ieTabla th[data-col="' + clave + '"]');
        if (!th) return;
        th.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        th.classList.add('ie-resaltada');
        setTimeout(function () { th.classList.remove('ie-resaltada'); }, 3000);
    }

    return { dibujar: dibujar, resaltarColumna: resaltarColumna };
})();
