/**
 * ie-comun.js
 * Lo que comparten todas las pestañas: el pedido al servidor, el formato de
 * números, los avisos, la exportación a Excel y los modales.
 *
 * FORMATO: es-AR, igual que rentabilidad_rubro. Un null es "—" (falta el dato
 * o fue una división por cero) y 'NA' es "no aplica" (la fila no existe en
 * esa columna). Nunca se muestra un 0 que el servidor no mandó.
 */
'use strict';

var IE = window.IE || {};
window.IE = IE;

IE.NA = 'NA';
IE.tabs = IE.tabs || {};
IE.estado = { moneda: 'ARS' };

/* ── Pedidos ─────────────────────────────────────────────────────────────── */

/**
 * POST con FormData; devuelve el JSON. Los errores del servidor (4xx/5xx)
 * también vienen en JSON con 'message', y se devuelven igual para que la
 * pestaña los muestre.
 */
IE.post = async function (url, datos) {
    var fd = new FormData();

    Object.keys(datos || {}).forEach(function (k) {
        fd.append(k, datos[k]);
    });

    var r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    var texto = await r.text();

    try {
        var json = JSON.parse(texto);
        json._status = r.status;
        return json;
    } catch (e) {
        return { ok: false, _status: r.status, message: 'Respuesta inválida del servidor (' + r.status + ').' };
    }
};

/* ── Formato ─────────────────────────────────────────────────────────────── */

IE.esc = function (s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
};

function miles(n, dec) {
    var s = Math.abs(n).toFixed(dec || 0).split('.');
    s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (n < 0 ? '-' : '') + s.join(',');
}

IE.nulo = '<span class="ie-null">—</span>';
IE.noAplica = '<span class="ie-na">no aplica</span>';

/** Importe sin decimales, con el signo de la moneda en pantalla */
IE.fmtImporte = function (v, moneda) {
    if (v === IE.NA) return IE.noAplica;
    if (v === null || v === undefined) return IE.nulo;
    var t = (moneda || IE.estado.moneda) === 'USD' ? 'U$S ' : '$ ';
    var s = t + miles(Math.round(v));
    return v < 0 ? '<span class="ie-neg">' + s + '</span>' : s;
};

/** Importe exacto, con dos decimales: el del modal de edición */
IE.fmtExacto = function (v) {
    if (v === null || v === undefined) return '—';
    return '$ ' + miles(v, 2);
};

IE.fmtPct = function (v, dec) {
    if (v === IE.NA) return IE.noAplica;
    if (v === null || v === undefined) return IE.nulo;
    var s = miles(v * 100, dec === undefined ? 1 : dec) + ' %';
    return v < 0 ? '<span class="ie-neg">' + s + '</span>' : s;
};

/** Coeficiente de mark up: veces, no porcentaje */
IE.fmtCoef = function (v) {
    if (v === IE.NA) return IE.noAplica;
    if (v === null || v === undefined) return IE.nulo;
    return miles(v, 2) + 'x';
};

/** Variación: % para importes, puntos porcentuales para ratios */
IE.fmtVar = function (v, esRatio) {
    if (v === IE.NA) return IE.noAplica;
    if (v === null || v === undefined) return IE.nulo;
    var s = (v > 0 ? '+' : '') + miles(v * 100, 1) + (esRatio ? ' pp' : ' %');
    var cls = v > 0 ? 'ie-pos' : (v < 0 ? 'ie-neg' : '');
    return '<span class="' + cls + '">' + s + '</span>';
};

IE.fmtValor = function (v, formato, moneda) {
    if (formato === 'pct') return IE.fmtPct(v);
    if (formato === 'coef') return IE.fmtCoef(v);
    return IE.fmtImporte(v, moneda);
};

IE.nombreMes = function (p) {
    var m = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    var x = String(p).split('-');
    return m[parseInt(x[0], 10) - 1] + ' ' + x[1];
};

IE.rangoTexto = function (periodos) {
    if (!periodos || !periodos.length) return '';
    var a = IE.nombreMes(periodos[0]);
    var b = IE.nombreMes(periodos[periodos.length - 1]);
    return a === b ? a : a + ' — ' + b;
};

/* ── Avisos ──────────────────────────────────────────────────────────────── */

IE.pintarAvisos = function (id, avisos) {
    var el = document.getElementById(id);
    if (!el) return;

    var iconos = { info: 'bi-info-circle', warning: 'bi-exclamation-triangle', danger: 'bi-exclamation-octagon' };

    el.innerHTML = (avisos || []).map(function (a) {
        var det = '';

        if (a.detalle && a.detalle.length) {
            det = '<details><summary>Ver detalle (' + a.detalle.length + ')</summary><ul>'
                + a.detalle.map(function (d) { return '<li>' + IE.esc(d) + '</li>'; }).join('') + '</ul></details>';
        }

        var link = '';

        if (a.link) {
            link = ' <a href="#" data-ir-tab="' + IE.esc(a.link.tab) + '" data-ir-col="' + IE.esc(a.link.col || '') + '">Ir →</a>';
        }

        return '<div class="ie-aviso ie-aviso-' + a.nivel + '"><i class="bi ' + (iconos[a.nivel] || iconos.info) + '"></i>'
            + '<div>' + IE.esc(a.texto) + link + det + '</div></div>';
    }).join('');
};

IE.cargando = function (id, texto) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = '<div class="ie-cargando"><div class="ie-spinner"></div><p>' + IE.esc(texto || 'Calculando…') + '</p></div>';
};

IE.estadoVacio = function (id, icono, titulo, texto) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = '<div class="ie-estado"><div class="ie-estado-icon"><i class="bi ' + icono + '"></i></div>'
        + '<h3>' + IE.esc(titulo) + '</h3><p>' + texto + '</p></div>';
};

/* ── Excel (SheetJS) ─────────────────────────────────────────────────────── */

/**
 * Exporta tablas del DOM tal como se ven: se clona cada una, se sacan filas y
 * columnas ocultas y se deja solo el texto de cada celda (sin íconos ni
 * botones). Así el archivo tiene el mismo layout, los mismos % y el mismo
 * comparativo que la pantalla.
 *
 * @param {Array} hojas [{tabla: HTMLTableElement, nombre: 'Canales'}]
 * @param {string} archivo base del nombre
 */
IE.exportar = function (hojas, archivo) {
    if (typeof XLSX === 'undefined') {
        alert('No se pudo cargar la librería de Excel (SheetJS).');
        return;
    }

    var wb = XLSX.utils.book_new();

    hojas.forEach(function (h) {
        if (!h.tabla) return;
        var clon = h.tabla.cloneNode(true);

        clon.querySelectorAll('td, th').forEach(function (c) {
            c.innerHTML = IE.esc(c.textContent.replace(/\s+/g, ' ').trim());
        });

        var ws = XLSX.utils.table_to_sheet(clon, { raw: true });
        XLSX.utils.book_append_sheet(wb, ws, h.nombre.substring(0, 31));
    });

    var d = new Date();
    var hoy = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    XLSX.writeFile(wb, archivo + '_' + hoy + '.xlsx');
};

/* ── Modales ─────────────────────────────────────────────────────────────── */

IE.abrirModal = function (id) {
    document.getElementById(id).style.display = 'flex';
    document.body.style.overflow = 'hidden';
};

IE.cerrarModal = function (id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
};

document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-cerrar]');
    if (c) IE.cerrarModal(c.getAttribute('data-cerrar'));

    if (e.target.classList && e.target.classList.contains('ie-overlay')) IE.cerrarModal(e.target.id);
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ie-overlay').forEach(function (m) {
        if (m.style.display === 'flex') IE.cerrarModal(m.id);
    });
});

document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('ieBtnInfo');
    if (b) b.addEventListener('click', function () { IE.abrirModal('ieModalInfo'); });
});
