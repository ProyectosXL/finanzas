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

    /**
     * Las tres vistas las maneja Js/eje-vistas.js, el mismo componente que usan
     * las pestañas de detalle: el tablero y la pestaña que explica una de sus
     * filas no pueden medir períodos distintos.
     *
     * ESTA PANTALLA DIBUJA UNA COLUMNA MÁS que las otras, y es a propósito: las
     * columnas que no representan ningún día futuro -la del mes en curso cuando
     * el tramo diario arranca hoy- se muestran con un guión sobre fondo gris,
     * porque las filas de arrastre tienen que poder decir "acá no hay posición
     * que mostrar". Las pestañas de detalle no tienen filas de arrastre y no las
     * necesitan. Por eso la lista de columnas se arma acá, sobre el eje, y del
     * componente compartido se toman el estado de la vista, el rótulo del
     * período y el total que corresponde.
     */
    var vistas = null;

    function inicializar() {
        var btnRefresh = document.getElementById('cfBtnRefresh');
        var btnExport = document.getElementById('cfBtnExport');

        vistas = crearEjeVistas({
            botones: 'cfVistas',
            alCambiar: cambiarVista
        });

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

                vistas.usar(datos);

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

    function cambiarVista() {
        if (!datos) {
            return;
        }

        pintarKpis();
        pintarGrilla();

        if (typeof window.ajustarStickyHeaders === 'function') {
            window.ajustarStickyHeaders();
        }
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

    /**
     * Los indicadores miden exactamente las columnas que se están mirando: el
     * backend los devuelve ya resueltos por vista y acá sólo se elige el bloque.
     *
     * El único que NO cambia es el Disponible Inicial: es con cuánto se arranca
     * hoy, un hecho del presente y no del período que se elige mirar.
     */
    function pintarKpis() {
        var vista = vistas.activa();
        var k = datos.kpi[vista];

        if (!k) {
            return;
        }

        kpi('cfKpiApertura', k.saldo_apertura);
        kpi('cfKpiIngresos', k.ingresos);
        kpi('cfKpiEgresos', k.egresos);
        kpi('cfKpiFlujo', k.flujo);
        kpi('cfKpiCierre', k.saldo_cierre);

        pintarSigno('cfKpiFlujo', k.flujo);
        pintarSigno('cfKpiCierre', k.saldo_cierre);

        // El pie de las tarjetas de flujo dice cuántas columnas se están sumando
        var pies = document.querySelectorAll('#cfKpis .cf-kpi-periodo');
        var unidad = (vista === 'meses') ? ' meses' : (vista === 'dias' ? ' días' : ' columnas');

        Array.prototype.forEach.call(pies, function(el) {
            el.textContent = k.columnas + unidad;
        });

        var cols = columnas();
        texto('cfKpiCierreCuando', cols.length ? 'Al ' + cols[cols.length - 1].label : '');

        if (k.minimo) {
            kpi('cfKpiMinimo', k.minimo.valor);
            texto('cfKpiMinimoCuando', 'El ' + k.minimo.label);
            pintarSigno('cfKpiMinimo', k.minimo.valor);
        } else {
            texto('cfKpiMinimo', '—');
            texto('cfKpiMinimoCuando', 'Sin datos');
        }

        pintarPeriodoActivo(k);
    }

    /**
     * Barra que enuncia el período medido. Es lo que evita leer un número
     * creyendo que cubre otro tramo: en la vista Meses las columnas mensuales
     * acumulan sólo los días que quedan FUERA del tramo diario, así que su total
     * no es el del horizonte completo.
     */
    function pintarPeriodoActivo(k) {
        var el = document.getElementById('cfPeriodoActivo');

        if (!el) {
            return;
        }

        var etiquetas = { dias: 'Días', meses: 'Meses', completo: 'Período completo' };

        el.innerHTML =
            '<i class="fas fa-calendar-check me-2"></i>'
            + '<strong>' + escapar(etiquetas[vistas.activa()]) + '</strong>'
            + ' &middot; los indicadores y la columna Total miden '
            + escapar(k.periodo.charAt(0).toLowerCase() + k.periodo.slice(1)) + '.';

        el.style.display = '';
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
        if (vistas.activa() === 'meses') {
            return colsMeses();
        }

        if (vistas.activa() === 'completo') {
            // El período entero, en orden cronológico: primero el tramo diario
            // y después el mensual, que arranca donde termina el diario.
            return colsDias().concat(colsMeses());
        }

        return colsDias();
    }

    function colsDias() {
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

    function colsMeses() {
        return datos.meses.map(function(m) {
            return {
                rama: 'meses',
                clave: m.clave,
                label: m.label,
                enSecuencia: m.en_secuencia,
                mensual: true,
                nota: m.parcial
                    ? 'Acumula sólo los días de este mes que quedan fuera del tramo diario'
                    : ''
            };
        });
    }

    /**
     * El total que corresponde a la vista: cada uno suma sus propias columnas.
     * Lo resuelve el componente compartido, así que el tablero y las pestañas de
     * detalle no pueden discrepar sobre qué total va con qué vista.
     */
    function totalDeVista(f) {
        return vistas.total(f);
    }

    function pintarGrilla() {
        var cols = columnas();

        pintarEncabezado(cols);
        pintarFilas(cols);
    }

    function pintarEncabezado(cols) {
        // El rótulo del período va alineado a la izquierda y pegado como
        // segunda columna fija. Centrado sobre 28 columnas quedaba a unos
        // 1500px a la derecha, o sea fuera de la pantalla, y la fila se veía
        // vacía.
        var vista = vistas.activa();
        var grupos = '';

        if (vista === 'completo') {
            // Dos rótulos, uno por tramo: en la vista completa conviven las dos
            // ramas del eje y hay que ver dónde termina una y arranca la otra.
            var nDias = datos.dias.length;

            grupos =
                '<th colspan="' + nDias + '" class="table-group-divider cf-col-periodo">'
                    + 'Próximos ' + datos.horizonte_dias + ' días'
                + '</th>'
                + '<th colspan="' + (cols.length - nDias) + '" '
                    + 'class="table-group-divider cf-col-periodo-2">'
                    + 'Meses siguientes'
                + '</th>';
        } else {
            var titulo = (vista === 'meses')
                ? 'Próximos ' + datos.horizonte_meses + ' meses'
                : 'Próximos ' + datos.horizonte_dias + ' días';

            grupos = '<th colspan="' + cols.length + '" '
                + 'class="table-group-divider cf-col-periodo">' + escapar(titulo) + '</th>';
        }

        document.getElementById('cfHeaderTop').innerHTML =
            '<th rowspan="2" class="cf-col-concepto">Concepto</th>' +
            grupos +
            '<th rowspan="2" class="text-end cf-col-total">Total</th>';

        document.getElementById('cfHeaderSub').innerHTML = cols.map(function(c, i) {
            return '<th class="' + clasesColumna(c, i).concat(['text-end']).join(' ') + '"'
                + tip(c.nota) + '>' + escapar(c.label) + '</th>';
        }).join('');
    }

    /**
     * Clases de una columna. En la vista completa se marca la primera columna
     * mensual, para que se vea donde termina el tramo diario.
     */
    function clasesColumna(c, i) {
        var clases = [];

        if (!c.enSecuencia) { clases.push('cf-col-fuera'); }
        if (c.feriado) { clases.push('cf-col-feriado'); }

        if (vistas.activa() === 'completo' && c.mensual && i === datos.dias.length) {
            clases.push('cf-inicio-meses');
        }

        return clases;
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

        var celdas = cols.map(function(c, i) {
            return celdaHtml(f[c.rama][c.clave], c, i);
        }).join('');

        var total = totalDeVista(f);

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

        // Una fila de arrastre muestra un número que NO viene de su módulo: es
        // el saldo que se acumula columna a columna. Sin decirlo, se lee como
        // si alguien hubiera cargado datos ahí.
        if (f.arrastre) {
            marca = ' <i class="fas fa-arrow-right-arrow-left cf-marca cf-marca-arrastre" title="'
                + escapar(textoArrastre(f)) + '"></i>';
        } else if (f.sin_datos) {
            marca = ' <i class="fas fa-circle-info cf-marca" title="Todavía no hay datos para esta fila"></i>';
        } else if (!f.computa && !f.derivada) {
            marca = ' <i class="fas fa-eye cf-marca" title="Informativa: se muestra pero no entra en ninguna suma"></i>';
        } else if (f.moneda_origen === 'USD' && f.tipo_cambio) {
            marca = ' <i class="fas fa-dollar-sign cf-marca" title="Convertido de dólares a $ '
                + Number(f.tipo_cambio).toFixed(2) + '"></i>';
        }

        if (f.tab) {
            // data-sub-tab lo lee el JS de la pestaña destino para abrirse en la
            // vista correcta: hay módulos con más de una, y llegar a la primera
            // deja al usuario sin el detalle del número que clickeó.
            return '<a href="#" class="cf-link" data-ir-a="' + escapar(f.tab) + '"'
                + (f.subtab ? ' data-sub-tab="' + escapar(f.subtab) + '"' : '') + '>'
                + nombre + '</a>' + marca;
        }

        return nombre + marca;
    }

    /**
     * Qué explicar en una fila de arrastre. Si además su módulo de origen no
     * existe, hay que decir las dos cosas: que el horizonte arranca en cero, y
     * que lo que se ve es la caja que se va acumulando.
     */
    function textoArrastre(f) {
        return 'Arrastre: la posición proyectada al cierre de cada columna. '
            + 'Es el saldo de apertura más todo lo que se movió hasta acá, '
            + 'no un dato cargado en esta fila.';
    }

    function celdaHtml(valor, col, i) {
        var clases = clasesColumna(col, i).concat(['text-end']);

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
     *
     * La sub-pestaña se deja anotada en window.cfSubTabDestino y NO se abre
     * desde acá: loadTab() carga por AJAX y no avisa cuándo terminó, así que el
     * destino todavía no está en el DOM. Lo lee el JS de la pestaña destino
     * cuando arranca, que es el único momento en que existe con certeza.
     */
    function conectarEnlaces() {
        var enlaces = document.querySelectorAll('#cfBody .cf-link');

        Array.prototype.forEach.call(enlaces, function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();

                var destino = a.getAttribute('data-ir-a');
                var item = document.querySelector('.menu-link[data-tab="' + destino + '"]');

                if (!item) {
                    return;
                }

                window.cfSubTabDestino = a.getAttribute('data-sub-tab') || null;
                item.click();
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
