/**
 * ie-main.js
 * Arranque: filtros comunes, cambio de pestaña, Aplicar, Excel y los links
 * de los avisos y alertas.
 *
 * Cada pestaña es un objeto en IE.tabs con init() (se llama al llegar su HTML)
 * y cargar() (Aplicar). Las pestañas de informe cargan solas al entrar si los
 * filtros son válidos, como rentabilidad_rubro.
 */
'use strict';

(function () {

    IE.tabActual = null;

    /* ── Filtros ── */

    function validar(p) {
        if (!/^\d{1,2}-\d{4}$/.test(p)) return false;
        var x = p.split('-').map(Number);
        return x[0] >= 1 && x[0] <= 12 && x[1] >= 2000 && x[1] <= 2100;
    }

    function mayor(a, b) {
        var x = a.split('-').map(Number);
        var y = b.split('-').map(Number);
        return x[1] > y[1] || (x[1] === y[1] && x[0] > y[0]);
    }

    function alerta(msg) {
        var el = document.getElementById('ieAlertaFiltros');
        if (!msg) { el.style.display = 'none'; return; }
        el.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + IE.esc(msg);
        el.style.display = 'flex';
    }

    /**
     * Los filtros comunes, validados como en rentabilidad_rubro. Devuelve null
     * (y marca el error) si no son válidos; con 'silencioso' no marca nada.
     */
    IE.filtros = function (silencioso) {
        var d = document.getElementById('ieDesde');
        var h = document.getElementById('ieHasta');
        var desde = d.value.trim();
        var hasta = h.value.trim();

        d.classList.remove('error');
        h.classList.remove('error');
        if (!silencioso) alerta(null);

        var err = null;

        if (!validar(desde)) { err = 'El período Desde no es válido. Use el formato M-AAAA (ej: 1-2026).'; d.classList.add('error'); }
        else if (!validar(hasta)) { err = 'El período Hasta no es válido. Use el formato M-AAAA (ej: 6-2026).'; h.classList.add('error'); }
        else if (mayor(desde, hasta)) { err = 'El período Desde no puede ser mayor que el período Hasta.'; }

        if (err) {
            if (!silencioso) alerta(err);
            return null;
        }

        return {
            desde: desde,
            hasta: hasta,
            moneda: document.querySelector('input[name="ieMoneda"]:checked').value,
            comparar: document.getElementById('ieComparar').checked ? '1' : '0',
            cerradas: document.getElementById('ieCerradas').checked ? '1' : '0'
        };
    };

    IE.exportable = function (si) {
        document.getElementById('ieBtnExportar').disabled = !si;
    };

    /* ── Pestañas ── */

    async function abrir(tab, despues) {
        var boton = document.querySelector('.ie-tab[data-tab="' + tab + '"]');
        if (!boton) return;

        IE.tabActual = tab;
        document.querySelectorAll('.ie-tab').forEach(function (b) { b.classList.toggle('active', b === boton); });
        document.getElementById('ieFiltros').style.display = boton.dataset.filtros === '1' ? '' : 'none';
        IE.exportable(false);

        var cont = document.getElementById('ieContenido');
        cont.innerHTML = '<div class="ie-cargando"><div class="ie-spinner"></div></div>';

        var fd = new FormData();
        fd.append('tab', tab);
        var r = await fetch('Controller/TabController.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        cont.innerHTML = await r.text();

        if (!r.ok || !IE.tabs[tab]) return;

        IE.tabs[tab].init();

        if (!IE.tabs[tab].sinFiltros && IE.filtros(true)) {
            await IE.tabs[tab].cargar();
        }

        if (despues) despues();
    }

    /** Recalcula la pestaña abierta: lo usa la edición después de guardar */
    IE.recargarPestana = function () {
        var t = IE.tabs[IE.tabActual];
        if (t && !t.sinFiltros) t.cargar();
    };

    /** Link de un aviso o una alerta: cambia de pestaña y lleva a la columna */
    IE.irA = function (tab, col) {
        var t = IE.tabs[tab];

        if (!document.querySelector('.ie-tab[data-tab="' + tab + '"]')) {
            alert('Tu usuario no tiene acceso a esa pestaña.');
            return;
        }

        abrir(tab, function () {
            if (tab === 'parametros' && t.irASeccion) t.irASeccion('rubros');
            else if (col && t.irAColumna) setTimeout(function () { t.irAColumna(col); }, 50);
        });
    };

    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-ir-tab]');
        if (!a) return;
        e.preventDefault();
        IE.irA(a.dataset.irTab, a.dataset.irCol);
    });

    /* ── Arranque ── */

    document.addEventListener('DOMContentLoaded', function () {
        // Por defecto, el mes anterior: el último que suele estar cerrado
        var hoy = new Date();
        var m = hoy.getMonth() === 0 ? 12 : hoy.getMonth();
        var a = hoy.getMonth() === 0 ? hoy.getFullYear() - 1 : hoy.getFullYear();
        document.getElementById('ieDesde').value = '1-' + a;
        document.getElementById('ieHasta').value = m + '-' + a;

        ['ieDesde', 'ieHasta'].forEach(function (id) {
            var i = document.getElementById(id);
            i.addEventListener('input', function () { i.value = i.value.replace(/[^\d-]/g, ''); });
            i.addEventListener('keydown', function (e) { if (e.key === 'Enter') document.getElementById('ieBtnAplicar').click(); });
        });

        document.getElementById('ieBtnAplicar').addEventListener('click', function () {
            var t = IE.tabs[IE.tabActual];
            if (t && IE.filtros()) t.cargar();
        });

        document.getElementById('ieBtnExportar').addEventListener('click', function () {
            var t = IE.tabs[IE.tabActual];
            if (t && t.exportar) t.exportar();
        });

        // Moneda, comparativo y cerradas cambian los datos: se vuelve a pedir.
        // En el Dashboard el comparativo solo muestra flechas (no recarga).
        ['ieComparar', 'ieCerradas'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', function () {
                var t = IE.tabs[IE.tabActual];
                if (!t || t.sinFiltros) return;
                if (id === 'ieComparar' && t.sinRecargaAlComparar) return;
                if (IE.filtros(true)) t.cargar();
            });
        });

        document.querySelectorAll('input[name="ieMoneda"]').forEach(function (r) {
            r.addEventListener('change', function () {
                var t = IE.tabs[IE.tabActual];
                if (t && !t.sinFiltros && IE.filtros(true)) t.cargar();
            });
        });

        document.querySelectorAll('.ie-tab').forEach(function (b) {
            b.addEventListener('click', function () { abrir(b.dataset.tab); });
        });

        var primera = document.querySelector('.ie-tab');
        if (primera) abrir(primera.dataset.tab);
    });
})();
