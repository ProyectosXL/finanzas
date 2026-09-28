/**
 * ie-dashboard.js
 * KPIs, estructura de costos por canal, ranking de locales y alertas.
 *
 * El año anterior se calcula SIEMPRE (los KPIs llevan su variación y la
 * alerta de caída lo necesita); el toggle "Comparar" solo muestra u oculta las
 * flechas del ranking.
 *
 * EL GRÁFICO: barras apiladas del % sobre la venta de cada costo y del
 * resultado, una barra por canal. Colores categóricos en orden fijo (paleta
 * validada de la guía de visualización), leyenda siempre visible y, al lado,
 * la misma información como tabla: el color nunca es la única forma de leerlo.
 */
'use strict';

IE.tabs.dashboard = (function () {

    var payload = null;
    var grafico = null;

    /* Orden fijo: el color sigue a la serie, nunca a su posición */
    var COLORES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    var ICONO_SEM = { VERDE: 'bi-check-circle-fill', AMARILLO: 'bi-dash-circle-fill', NARANJA: 'bi-exclamation-circle-fill', ROJO: 'bi-x-circle-fill' };

    function kpis(p) {
        return '<div class="ie-kpis" id="ieKpis">' + p.kpis.map(function (k) {
            var v = k.ratio ? IE.fmtPct(k.v) : IE.fmtImporte(k.v, p.moneda);
            var aa = k.ratio ? IE.fmtPct(k.aa) : IE.fmtImporte(k.aa, p.moneda);

            return '<div class="ie-kpi"><div class="ie-kpi-label">' + IE.esc(k.etiqueta) + '</div>'
                + '<div class="ie-kpi-valor">' + v + '</div>'
                + '<div class="ie-kpi-var">' + IE.fmtVar(k['var'], k.ratio) + ' <small>vs. ' + aa + ' año anterior</small></div></div>';
        }).join('') + '</div>';
    }

    function tablaEstructura(p) {
        var e = p.estructura;
        var h = '<table class="ie-tabla-simple" id="ieTablaEstructura"><thead><tr><th>% sobre venta (1.9)</th>'
            + e.canales.map(function (c) { return '<th>' + IE.esc(c.etiqueta) + '</th>'; }).join('') + '</tr></thead><tbody>';

        e.segmentos.forEach(function (s, i) {
            h += '<tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' + COLORES[i] + ';margin-right:6px"></span>'
                + IE.esc(s) + '</td>' + e.canales.map(function (c) { return '<td>' + IE.fmtPct(c.valores[i]) + '</td>'; }).join('') + '</tr>';
        });

        h += '<tr style="font-weight:700"><td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' + COLORES[7]
            + ';margin-right:6px"></span>Resultado de explotación</td>'
            + e.canales.map(function (c) { return '<td>' + IE.fmtPct(c.resultado) + '</td>'; }).join('') + '</tr>';

        return h + '</tbody></table>';
    }

    function grafica(p) {
        if (typeof Chart === 'undefined') return;

        var e = p.estructura;
        var ds = e.segmentos.map(function (s, i) {
            return {
                label: s, backgroundColor: COLORES[i], borderColor: '#fff', borderWidth: 1, borderRadius: 2,
                data: e.canales.map(function (c) { return c.valores[i] === null ? 0 : +(c.valores[i] * 100).toFixed(2); })
            };
        });

        ds.push({
            label: 'Resultado', backgroundColor: COLORES[7], borderColor: '#fff', borderWidth: 1, borderRadius: 2,
            data: e.canales.map(function (c) { return c.resultado === null ? 0 : +(c.resultado * 100).toFixed(2); })
        });

        if (grafico) grafico.destroy();

        grafico = new Chart(document.getElementById('ieGrafico'), {
            type: 'bar',
            data: { labels: e.canales.map(function (c) { return c.etiqueta; }), datasets: ds },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: true },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, ticks: { callback: function (v) { return v + ' %'; } }, grid: { color: '#eef1f5' } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'DM Sans' } } },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y.toFixed(1).replace('.', ',') + ' %'; } } }
                }
            }
        });
    }

    function ranking(p, comparar) {
        var ind = [
            ['RENTABILIDAD', 'Rentabilidad total'], ['COSTO_MERCADERIA', 'Costo mercadería'], ['COMERCIALIZACION', 'Comercialización'],
            ['PERSONAL', 'Personal'], ['OCUPACION', 'Ocupación']
        ];

        var h = '<table class="ie-tabla-simple" id="ieTablaRanking"><thead><tr><th>#</th><th>Local</th>'
            + ind.map(function (x) { return '<th>' + x[1] + '</th>'; }).join('')
            + (comparar ? '<th>vs. año ant.</th>' : '') + '<th>Part. total</th><th>Part. locales</th></tr></thead><tbody>';

        p.ranking.forEach(function (l, i) {
            h += '<tr><td>' + (i + 1) + '</td><td>' + IE.esc(l.nombre) + ' <span class="ie-null">(' + l.nro + ')</span>'
                + (l.cerrada ? ' <span class="ie-badge ie-badge-pisado">cerrada</span>' : '') + '</td>';

            ind.forEach(function (x) {
                var col = l.colores[x[0]];
                var val = IE.fmtPct(l.valores[x[0]]);
                h += '<td>' + (col ? '<span class="ie-sem ie-sem-' + col + '" title="' + col.toLowerCase() + '"><i class="bi ' + ICONO_SEM[col] + '"></i>' + val + '</span>' : val) + '</td>';
            });

            if (comparar) {
                var d = l.deltaRentabilidad;
                var flecha = (d === null || d === undefined) ? IE.nulo
                    : (d > 0 ? '<span class="ie-pos"><i class="bi bi-arrow-up"></i> mejora</span>'
                        : (d < 0 ? '<span class="ie-neg"><i class="bi bi-arrow-down"></i> empeora</span>' : '='));
                h += '<td>' + flecha + ' ' + IE.fmtVar(d, true) + '</td>';
            }

            h += '<td>' + IE.fmtPct(l.partTotal) + '</td><td>' + IE.fmtPct(l.partLocales) + '</td></tr>';
        });

        return h + '</tbody></table>';
    }

    function alertas(p) {
        if (!p.alertas.length) return '<p class="ie-nota"><i class="bi bi-check-circle"></i> Sin alertas en el rango.</p>';

        var iconos = { negativo: 'bi-graph-down-arrow', caida: 'bi-arrow-down-right-circle', rojos: 'bi-stoplights', sinSeccion: 'bi-tags' };

        return '<ul class="ie-alertas" id="ieListaAlertas">' + p.alertas.map(function (a) {
            return '<li><i class="bi ' + (iconos[a.tipo] || 'bi-exclamation-triangle') + '"></i> <span>' + IE.esc(a.texto) + '</span>'
                + '<a href="#" data-ir-tab="' + IE.esc(a.link.tab) + '" data-ir-col="' + IE.esc(a.link.col || '') + '">Ver →</a></li>';
        }).join('') + '</ul>';
    }

    function dibujar() {
        if (!payload) return;
        var p = payload;
        var comparar = document.getElementById('ieComparar').checked;

        document.getElementById('ieCuerpo').innerHTML = kpis(p)
            + '<div class="ie-panel-grid">'
            + '<div class="ie-panel"><h3><i class="bi bi-bar-chart-steps"></i> Estructura de costos por canal (% sobre venta sin IVA)</h3>'
            + '<div class="ie-chart-box"><canvas id="ieGrafico" aria-label="Estructura de costos por canal"></canvas></div>'
            + '<div style="margin-top:14px;overflow-x:auto">' + tablaEstructura(p) + '</div></div>'
            + '<div class="ie-panel"><h3><i class="bi bi-bell"></i> Alertas</h3>' + alertas(p)
            + (p.caidaPp !== null ? '<p class="ie-nota">Caída de rentabilidad a partir de ' + p.caidaPp + ' puntos (se cambia en Parámetros).</p>' : '')
            + '</div></div>'
            + '<div class="ie-panel"><h3><i class="bi bi-trophy"></i> Ranking de Locales</h3>'
            + '<p class="ie-nota">Ordenado por rentabilidad total (resultado de explotación / 1.9 de cada local). Costos sobre 1.9. El color es el semáforo de Parámetros; el ícono lo repite para quien no distingue colores.</p>'
            + '<div style="overflow-x:auto">' + ranking(p, comparar) + '</div></div>';

        grafica(p);
        IE.exportable(true);
    }

    async function cargar() {
        var f = IE.filtros();
        if (!f) return;

        IE.exportable(false);
        IE.cargando('ieCuerpo');

        var p = await IE.post('Controller/InformeController.php', Object.assign({ action: 'informe', vista: 'dashboard' }, f));

        if (!p.ok) {
            payload = null;
            IE.pintarAvisos('ieAvisos', p.avisos || []);
            IE.estadoVacio('ieCuerpo', 'bi-exclamation-octagon', 'No se pudo armar el dashboard', IE.esc(p.message || 'Error desconocido.'));
            return;
        }

        IE.pintarAvisos('ieAvisos', p.avisos);

        if (p.vacio) {
            payload = null;
            IE.estadoVacio('ieCuerpo', 'bi-calendar-x', 'Sin resumen en el rango', 'Ningún mes del rango tiene el resumen generado.');
            return;
        }

        payload = p;
        IE.estado.moneda = p.moneda;
        document.getElementById('ieMeta').textContent = 'Período: ' + IE.rangoTexto(p.periodos) + ' · vs. ' + IE.rangoTexto(p.periodosAA)
            + ' · ' + (p.moneda === 'USD' ? 'U$S' : '$ ARS');
        dibujar();
    }

    function init() {
        payload = null;
        IE.estadoVacio('ieCuerpo', 'bi-speedometer2', 'Dashboard', 'Elegí el período y hacé clic en <strong>Aplicar</strong>.');
    }

    function exportar() {
        var f = IE.filtros(true) || {};
        var kp = document.createElement('table');
        kp.innerHTML = '<tr><th>Indicador</th><th>Actual</th><th>Año anterior</th><th>Variación</th></tr>'
            + payload.kpis.map(function (k) {
                var fm = k.ratio ? IE.fmtPct : function (v) { return IE.fmtImporte(v, payload.moneda); };
                return '<tr><td>' + IE.esc(k.etiqueta) + '</td><td>' + fm(k.v) + '</td><td>' + fm(k.aa) + '</td><td>' + IE.fmtVar(k['var'], k.ratio) + '</td></tr>';
            }).join('');

        var al = document.createElement('table');
        al.innerHTML = '<tr><th>Alerta</th></tr>' + payload.alertas.map(function (a) { return '<tr><td>' + IE.esc(a.texto) + '</td></tr>'; }).join('');

        IE.exportar([
            { tabla: kp, nombre: 'KPIs' },
            { tabla: document.getElementById('ieTablaEstructura'), nombre: 'Estructura de costos' },
            { tabla: document.getElementById('ieTablaRanking'), nombre: 'Ranking Locales' },
            { tabla: al, nombre: 'Alertas' }
        ], 'IE_Dashboard_' + (f.desde || '') + '_' + (f.hasta || ''));
    }

    // El toggle de comparar no vuelve a pedir datos: solo flechas
    document.addEventListener('change', function (e) {
        if (e.target.id === 'ieComparar' && IE.tabActual === 'dashboard' && payload) dibujar();
    });

    return { init: init, cargar: cargar, exportar: exportar, sinRecargaAlComparar: true };
})();
