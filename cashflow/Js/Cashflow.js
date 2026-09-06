/**
 * Cashflow - tablero de consolidación
 *
 * Acá NO se calcula nada: todo el trabajo lo hace el motor en PHP y esto sólo
 * pinta la grilla ya resuelta que devuelve CashflowController. Tampoco hay
 * ninguna fila ni sección escrita: la estructura viene en el payload.
 *
 * FECHAS: el backend manda siempre 'Y-m-d' y los rótulos de columna ya armados.
 * Nunca se hace new Date(string), que es de donde salen los corrimientos de un
 * día.
 *
 * null NO es cero. Una celda en null es una columna que no representa ningún
 * día futuro (la del mes en curso cuando el tramo diario arranca hoy): se pinta
 * con un guión. Un cero en Saldo Final se leería como "proyectamos cero pesos
 * de caja", que sería mentira.
 *
 * OJO CON EL ORDEN DE CARGA: ésta es la pestaña por defecto, así que index.php
 * la incluye del lado del servidor ARRIBA de los <script> de jQuery y Bootstrap.
 * Nada del nivel superior de este archivo puede tocar jQuery, Bootstrap ni el
 * DOM: todo va dentro de inicializar().
 */

(function() {
    'use strict';

    var datos = null;
    var vista = 'dias';   // 'dias' o 'meses'

    function inicializar() {
        var btnDias = document.getElementById('cfBtnDias');
        var btnMeses = document.getElementById('cfBtnMeses');
        var btnRefresh = document.getElementById('cfBtnRefresh');
        var btnExport = document.getElementById('cfBtnExport');

        if (btnDias) {
            btnDias.addEventListener('click', function() { cambiarVista('dias'); });
        }

        if (btnMeses) {
            btnMeses.addEventListener('click', function() { cambiarVista('meses'); });
        }

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargar);
        }

        if (btnExport) {
            btnExport.addEventListener('click', exportar);
        }

        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        mostrar('cfLoading', true);
        mostrar('cfWrapper', false);
        mostrar('cfKpis', false);

        pedirJson('Controller/CashflowController.php?action=getTablero')
            .then(function(data) {
                datos = data;

                pintarAvisos();
                pintarKpis();
                pintarGrilla();

                mostrar('cfLoading', false);
                mostrar('cfWrapper', true);
                mostrar('cfKpis', true);

                if (typeof window.ajustarStickyHeaders === 'function') {
                    window.ajustarStickyHeaders();
                }
            })
            .catch(function(error) {
                mostrar('cfLoading', false);
                mostrarError('No se pudo armar el tablero: ' + error.message);
            });
    }

    function cambiarVista(nueva) {
        if (vista === nueva || !datos) {
            return;
        }

        vista = nueva;

        var btnDias = document.getElementById('cfBtnDias');
        var btnMeses = document.getElementById('cfBtnMeses');

        activar(btnDias, vista === 'dias');
        activar(btnMeses, vista === 'meses');

        pintarKpis();
        pintarGrilla();

        if (typeof window.ajustarStickyHeaders === 'function') {
            window.ajustarStickyHeaders();
        }
    }

    function activar(boton, activo) {
        if (!boton) {
            return;
        }

        boton.classList.toggle('btn-primary', activo);
        boton.classList.toggle('btn-outline-secondary', !activo);
    }

    /* ================================================================
       AVISOS
       ================================================================ */

    function pintarAvisos() {
        var cont = document.getElementById('cfAvisos');

        if (!cont) {
            return;
        }

        if (!datos.warnings || datos.warnings.length === 0) {
            cont.innerHTML = '';
            return;
        }

        var items = datos.warnings.map(function(w) {
            return '<li>' + escapar(w) + '</li>';
        }).join('');

        cont.innerHTML =
            '<div class="alert alert-warning alert-dismissible fade show cf-avisos" role="alert">' +
                '<div class="d-flex align-items-start">' +
                    '<i class="fas fa-circle-info me-2 mt-1"></i>' +
                    '<div>' +
                        '<strong>Sobre estos números</strong>' +
                        '<ul class="mb-0 mt-1">' + items + '</ul>' +
                    '</div>' +
                '</div>' +
                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
    }

    /* ================================================================
       INDICADORES
       ================================================================ */

    function pintarKpis() {
        var k = datos.kpi;
        var enMeses = (vista === 'meses');

        kpi('cfKpiApertura', k.saldo_apertura);
        kpi('cfKpiIngresos', k.ingresos_tramo);
        kpi('cfKpiEgresos', k.egresos_tramo);
        kpi('cfKpiFlujo', k.flujo_tramo);

        // Los KPI de ingresos, egresos y flujo son SIEMPRE del tramo diario: es
        // el horizonte sobre el que se decide. El rótulo lo aclara.
        texto('cfKpiTramoLabel', 'Próximos ' + datos.horizonte_dias + ' días');

        kpi('cfKpiCierre', enMeses ? k.saldo_cierre_horizonte : k.saldo_cierre_tramo);
        texto('cfKpiCierreHorizonte', enMeses
            ? 'A ' + datos.horizonte_meses + ' meses'
            : 'A ' + datos.horizonte_dias + ' días');

        pintarSigno('cfKpiFlujo', k.flujo_tramo);
        pintarSigno('cfKpiCierre', enMeses ? k.saldo_cierre_horizonte : k.saldo_cierre_tramo);

        if (k.minimo) {
            kpi('cfKpiMinimo', k.minimo.valor);
            texto('cfKpiMinimoCuando', 'El ' + k.minimo.label);
            pintarSigno('cfKpiMinimo', k.minimo.valor);
        } else {
            texto('cfKpiMinimo', '—');
            texto('cfKpiMinimoCuando', 'Sin datos');
        }
    }

    /**
     * Escribe un indicador abreviado y deja el importe exacto en el tooltip.
     *
     * Los importes de este tablero llegan a los miles de millones y en una
     * tarjeta angosta se cortaban. Se muestran en millones, que es la unidad en
     * la que se lee un cashflow de este tamaño, y el número completo queda a un
     * hover de distancia.
     */
    function kpi(id, valor) {
        var el = document.getElementById(id);

        if (!el) {
            return;
        }

        el.textContent = plataCompacta(valor);
        el.setAttribute('title', plata(valor));
    }

    function pintarSigno(id, valor) {
        var el = document.getElementById(id);

        if (!el) {
            return;
        }

        el.classList.toggle('cf-negativo', Number(valor) < 0);
    }

    /* ================================================================
       GRILLA
       ================================================================ */

    /** Columnas de la vista actual, ya normalizadas */
    function columnas() {
        if (vista === 'meses') {
            return datos.meses.map(function(m) {
                return {
                    rama: 'meses',
                    clave: m.clave,
                    label: m.label,
                    enSecuencia: m.en_secuencia,
                    nota: m.parcial
                        ? 'Acumula sólo los días de este mes que quedan fuera del tramo diario'
                        : ''
                };
            });
        }

        return datos.dias.map(function(d) {
            return {
                rama: 'dias',
                clave: d.fecha,
                label: d.label,
                enSecuencia: d.en_secuencia,
                nota: d.feriado_comercio ? 'Feriado de comercio: sin venta estimada' : '',
                feriado: d.feriado_comercio
            };
        });
    }

    function pintarGrilla() {
        var cols = columnas();

        pintarEncabezado(cols);
        pintarFilas(cols);
    }

    function pintarEncabezado(cols) {
        var titulo = (vista === 'meses')
            ? 'Próximos ' + datos.horizonte_meses + ' meses'
            : 'Próximos ' + datos.horizonte_dias + ' días';

        // El rótulo del período va alineado a la izquierda y pegado como
        // segunda columna fija. Centrado sobre 28 columnas quedaba a unos
        // 1500px a la derecha, o sea fuera de la pantalla, y la fila se veía
        // vacía.
        document.getElementById('cfHeaderTop').innerHTML =
            '<th rowspan="2" class="cf-col-concepto">Concepto</th>' +
            '<th colspan="' + cols.length + '" class="table-group-divider cf-col-periodo">' +
                escapar(titulo) +
            '</th>' +
            '<th rowspan="2" class="text-end cf-col-total">Total</th>';

        document.getElementById('cfHeaderSub').innerHTML = cols.map(function(c) {
            var clases = ['text-end'];

            if (!c.enSecuencia) { clases.push('cf-col-fuera'); }
            if (c.feriado) { clases.push('cf-col-feriado'); }

            return '<th class="' + clases.join(' ') + '"' + tip(c.nota) + '>'
                + escapar(c.label) + '</th>';
        }).join('');
    }

    function pintarFilas(cols) {
        var secciones = {};

        datos.secciones.forEach(function(s) { secciones[s.codigo] = s; });

        var html = '';
        var seccionActual = null;
        var totalCols = cols.length + 2;

        datos.filas.forEach(function(f) {
            if (f.seccion !== seccionActual) {
                seccionActual = f.seccion;

                var s = secciones[seccionActual];

                html += '<tr class="cf-seccion">' +
                    '<td colspan="' + totalCols + '">' +
                        escapar(s ? s.nombre : seccionActual) +
                    '</td></tr>';
            }

            html += filaHtml(f, cols);
        });

        document.getElementById('cfBody').innerHTML = html;
        conectarEnlaces();
    }

    function filaHtml(f, cols) {
        var clases = ['cf-fila', 'cf-tipo-' + f.tipo.toLowerCase()];

        if (!f.computa && !f.derivada) { clases.push('cf-informativa'); }
        if (f.sin_datos) { clases.push('cf-sin-datos'); }

        var celdas = cols.map(function(c) {
            return celdaHtml(f[c.rama][c.clave], c);
        }).join('');

        var total = (vista === 'meses') ? f.total_horizonte : f.total_tramo;

        return '<tr class="' + clases.join(' ') + '">' +
            '<td class="cf-col-concepto" title="' + escapar(f.nombre) + '">' +
                conceptoHtml(f) +
            '</td>' +
            celdas +
            '<td class="text-end cf-col-total' + (Number(total) < 0 ? ' cf-negativo' : '') + '">' +
                plataCorta(total) +
            '</td></tr>';
    }

    /**
     * El nombre de la fila, con un enlace al módulo que la alimenta cuando lo
     * tiene. Es lo que hace navegable el tablero: desde el número consolidado se
     * llega al detalle que lo produce.
     */
    function conceptoHtml(f) {
        var nombre = escapar(f.nombre);
        var marca = '';

        if (f.sin_datos) {
            marca = ' <i class="fas fa-circle-info cf-marca" title="Todavía no hay datos para esta fila"></i>';
        } else if (!f.computa && !f.derivada) {
            marca = ' <i class="fas fa-eye cf-marca" title="Informativa: se muestra pero no entra en ninguna suma"></i>';
        } else if (f.moneda_origen === 'USD' && f.tipo_cambio) {
            marca = ' <i class="fas fa-dollar-sign cf-marca" title="Convertido de dólares a $ '
                + Number(f.tipo_cambio).toFixed(2) + '"></i>';
        }

        if (f.tab) {
            return '<a href="#" class="cf-link" data-ir-a="' + escapar(f.tab) + '">'
                + nombre + '</a>' + marca;
        }

        return nombre + marca;
    }

    function celdaHtml(valor, col) {
        var clases = ['text-end'];

        if (!col.enSecuencia) { clases.push('cf-col-fuera'); }
        if (col.feriado) { clases.push('cf-col-feriado'); }

        // null no es cero: es una columna que no representa ningún día futuro.
        if (valor === null || valor === undefined) {
            return '<td class="' + clases.join(' ') + ' cf-nulo" '
                + 'title="Esta columna no cubre ningún día futuro">—</td>';
        }

        var n = Number(valor);

        if (n === 0) {
            clases.push('cf-cero');
        } else if (n < 0) {
            clases.push('cf-negativo');
        }

        return '<td class="' + clases.join(' ') + '">' + plataCorta(n) + '</td>';
    }

    /**
     * Los enlaces del tablero disparan el click del ítem del menú lateral en
     * lugar de cargar la pestaña por su cuenta: así se reusa toda la navegación
     * de main.js, incluido el estado activo del menú.
     */
    function conectarEnlaces() {
        var enlaces = document.querySelectorAll('#cfBody .cf-link');

        Array.prototype.forEach.call(enlaces, function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();

                var destino = a.getAttribute('data-ir-a');
                var item = document.querySelector('.menu-link[data-tab="' + destino + '"]');

                if (item) {
                    item.click();
                }
            });
        });
    }

    /* ================================================================
       EXPORTAR
       ================================================================ */

    function exportar() {
        var tabla = document.getElementById('cfTabla');

        if (!tabla) {
            return;
        }

        var blob = new Blob(
            ['<html><head><meta charset="utf-8"></head><body>' + tabla.outerHTML + '</body></html>'],
            { type: 'application/vnd.ms-excel' }
        );

        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Cashflow_' + datos.generado + '.xls';
        a.click();
        URL.revokeObjectURL(a.href);
    }

    /* ================================================================
       AYUDANTES
       ================================================================ */

    function plata(v) {
        return '$ ' + Number(v || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** En millones a partir del millón; abajo de eso, el importe completo */
    function plataCompacta(v) {
        var n = Number(v || 0);

        if (n === 0) {
            return '$ 0';
        }

        if (Math.abs(n) < 1000000) {
            return '$ ' + n.toLocaleString('es-AR', { maximumFractionDigits: 0 });
        }

        return '$ ' + (n / 1000000).toLocaleString('es-AR', { maximumFractionDigits: 0 }) + ' M';
    }

    /** Sin decimales: con 40 columnas los centavos sólo hacen ruido */
    function plataCorta(v) {
        var n = Number(v || 0);

        if (n === 0) {
            return '0';
        }

        return n.toLocaleString('es-AR', { maximumFractionDigits: 0 });
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? '' : 'none';
        }
    }

    function mostrarError(mensaje) {
        var cont = document.getElementById('cfAvisos');

        if (cont) {
            cont.innerHTML = '<div class="alert alert-danger">' + escapar(mensaje) + '</div>';
        }

        console.error(mensaje);
    }

    function tip(texto) {
        return texto ? ' title="' + escapar(texto) + '"' : '';
    }

    function escapar(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
