/**
 * ie-informe.js
 * Las tres pestañas de tabla: IE por Canales, IE por Locales y Evolución
 * Mensual. Piden el informe, pintan los avisos y le pasan el payload a
 * IE.Tabla. Los toggles que no cambian datos (Mostrar %, qué muestra cada mes)
 * redibujan sin volver a pedir nada.
 */
'use strict';

IE.Informe = function (vista) {

    var payload = null;

    function opciones() {
        var pct = document.getElementById('iePct');
        var modo = document.querySelector('input[name="ieModoMes"]:checked');

        return {
            modo: vista,
            pct: !!(pct && pct.checked),
            modoMes: modo ? modo.value : 'v'
        };
    }

    /** p.tcc es {periodo: cierre}: cada mes se convierte con el suyo */
    function meta(p) {
        var el = document.getElementById('ieMeta');
        var tcc = p.moneda === 'USD' ? Object.assign({}, p.tcc || {}, p.tccAA || {}) : {};
        var meses = Object.keys(tcc);
        var fmt = function (v) { return v.toFixed(2).replace('.', ','); };
        var t = 'Período: ' + IE.rangoTexto(p.periodos) + ' · ';

        if (p.moneda !== 'USD') {
            t += '$ ARS';
        } else if (p.periodos.length === 1 && p.tcc) {
            t += 'U$S (TCC cierre ' + fmt(p.tcc[p.periodos[0]]) + ')';
        } else {
            t += 'U$S (TCC de cierre de cada mes)';
        }

        if (p.comparar) t += ' · vs. ' + IE.rangoTexto(p.periodosAA);

        el.textContent = t;
        el.title = meses.map(function (m) { return IE.nombreMes(m) + ': ' + fmt(tcc[m]); }).join('\n');
    }

    function dibujar() {
        if (!payload || payload.vacio) return;

        var wrap = document.getElementById('ieModoMesWrap');
        if (wrap) wrap.style.display = payload.comparar ? '' : 'none';

        IE.Tabla.dibujar('ieCuerpo', payload, opciones());
        IE.exportable(true);
    }

    async function cargar() {
        var f = IE.filtros();
        if (!f) return;

        IE.exportable(false);
        IE.cargando('ieCuerpo');

        var canal = document.getElementById('ieCanal');
        var datos = Object.assign({ action: 'informe', vista: vista }, f);
        if (canal) datos.canal = canal.value;

        var p = await IE.post('Controller/InformeController.php', datos);

        if (!p.ok) {
            payload = null;
            IE.pintarAvisos('ieAvisos', p.avisos || []);
            IE.estadoVacio('ieCuerpo', p.uruguay ? 'bi-globe-americas' : 'bi-exclamation-octagon',
                p.uruguay ? 'No disponible para Uruguay' : 'No se pudo armar el informe', IE.esc(p.message || 'Error desconocido.'));
            return;
        }

        payload = p;
        IE.estado.moneda = p.moneda;
        IE.pintarAvisos('ieAvisos', p.avisos);

        if (p.vacio) {
            document.getElementById('ieMeta').textContent = '';
            IE.estadoVacio('ieCuerpo', 'bi-calendar-x', 'Sin resumen en el rango',
                'Ningún mes del rango tiene el resumen de Control de Gastos generado.');
            return;
        }

        meta(p);
        dibujar();
    }

    function init() {
        payload = null;

        var pct = document.getElementById('iePct');
        if (pct) pct.addEventListener('change', dibujar);

        document.querySelectorAll('input[name="ieModoMes"]').forEach(function (r) {
            r.addEventListener('change', dibujar);
        });

        var canal = document.getElementById('ieCanal');
        if (canal) canal.addEventListener('change', cargar);

        IE.estadoVacio('ieCuerpo', 'bi-bar-chart-steps', 'Informe Económico',
            'Elegí el período y hacé clic en <strong>Aplicar</strong>.');
    }

    function exportar() {
        var nombres = { canales: 'IE_por_Canales', locales: 'IE_por_Locales', mensual: 'IE_Evolucion_Mensual' };
        var f = IE.filtros(true) || {};
        IE.exportar([{ tabla: document.getElementById('ieTabla'), nombre: nombres[vista] }],
            nombres[vista] + '_' + (f.desde || '') + '_' + (f.hasta || ''));
    }

    return { init: init, cargar: cargar, exportar: exportar, irAColumna: IE.Tabla.resaltarColumna };
};

IE.tabs.canales = IE.Informe('canales');
IE.tabs.locales = IE.Informe('locales');
IE.tabs.mensual = IE.Informe('mensual');
