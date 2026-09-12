/**
 * Otros Ingresos → Saldo de Inversiones
 *
 * Mismo circuito que Dólares Cuenta Comitente: formulario mínimo, un importe
 * vigente por fecha e historial de las cargas pisadas. Lo que cambia es la
 * moneda.
 *
 * SE CARGA EN PESOS Y NO SE CONVIERTE NADA. El saldo se informa en pesos, así
 * que entra al tablero tal como se carga. Por eso esta pantalla no muestra
 * ninguna cotización y la de dólares sí: allá el número que se ve no es el que
 * llega al tablero.
 *
 * EL HISTORIAL ES PARTE DE LA PANTALLA, no una auditoría escondida: cargar una
 * fecha que ya existe pisa el importe vigente, y esa columna es lo único que
 * después explica por qué el número de esa fecha cambió.
 */

(function() {
    'use strict';

    var URL_OTROS = 'Controller/OtrosIngresosController.php';

    var datos = null;
    var modalHistorial = null;

    function inicializar() {
        conectar('btnGuardarInv', guardar);
        conectar('btnRefreshInv', cargar);

        var modalEl = document.getElementById('modalHistorialInv');

        if (modalEl && typeof bootstrap !== 'undefined') {
            modalHistorial = new bootstrap.Modal(modalEl);
        }

        // La fecha arranca en hoy, que es la carga normal. Se puede cambiar a
        // una pasada: la carga describe cuánto había un día dado, y ese día
        // pudo haber sido la semana pasada.
        var fecha = document.getElementById('fechaInv');

        if (fecha && !fecha.value) {
            fecha.value = hoyISO();
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
        mostrar('loadingInv', true, 'flex');
        mostrar('wrapperInv', false);

        pedirJson(URL_OTROS + '?action=getSaldoInversiones')
            .then(function(data) {
                datos = data;

                pintarAvisos();
                pintarFilas();
                pintarKpi();

                mostrar('loadingInv', false);
                mostrar('wrapperInv', true);
            })
            .catch(function(error) {
                mostrar('loadingInv', false);
                Notificacion.error('No se pudieron cargar los importes: ' + error.message);
            });
    }

    /**
     * Los avisos del backend. Distinguen "falta correr el script" de "todavía
     * nadie cargó nada": los dos dejan la grilla vacía y no significan lo
     * mismo.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosInv');
        var avisos = (datos && datos.avisos) || [];

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? avisos.map(function(a) {
                  return '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                       + '<i class="fas fa-triangle-exclamation me-1"></i>'
                       + escapar(a) + '</small></div>';
              }).join('')
            : '';
    }

    function pintarFilas() {
        var filas = (datos && datos.filas) || [];
        var html = '';

        filas.forEach(function(f) {
            var versiones = Number(f.VERSIONES) || 1;

            html += '<tr>'
                + '<td class="fw-semibold" data-orden="' + escapar(f.FECHA) + '">'
                +     fechaCorta(f.FECHA) + '</td>'
                + '<td class="currency fw-bold">' + pesos(f.IMPORTE_ARS) + '</td>'
                + '<td class="text-center inv-alta">' + escapar(f.FECHA_ALTA || '—')
                +     subtituloUsuario(f.USUARIO) + '</td>'
                + '<td class="text-center" data-orden="' + versiones + '">'
                +     celdaHistorial(f.FECHA, versiones) + '</td>'
                + '<td></td>'
                + '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="5" class="text-center text-muted py-4">'
                 + 'Todavía no hay importes cargados. La fila del tablero muestra cero.'
                 + '</td></tr>';
        }

        document.getElementById('bodyInv').innerHTML = html;

        document.querySelectorAll('.inv-historial').forEach(function(b) {
            b.addEventListener('click', function() {
                abrirHistorial(b.getAttribute('data-fecha'));
            });
        });

        pintarPie(filas);
    }

    /**
     * El enlace al historial sólo aparece cuando hay más de una carga: un botón
     * que la mitad de las veces abre un modal con una sola fila se lee como una
     * pantalla que no funciona.
     */
    function celdaHistorial(fecha, versiones) {
        if (versiones < 2) {
            return '<span class="text-muted small">carga única</span>';
        }

        return '<button class="btn btn-sm btn-outline-secondary py-0 px-2 inv-historial" '
            + 'data-fecha="' + escapar(fecha) + '" '
            + 'title="Esta fecha se cargó ' + versiones + ' veces. Acá está por qué el número '
            + 'era otro.">'
            + '<i class="fas fa-clock-rotate-left me-1"></i>' + versiones + ' versiones'
            + '</button>';
    }

    function pintarPie(filas) {
        var total = 0;

        filas.forEach(function(f) {
            total += Number(f.IMPORTE_ARS) || 0;
        });

        document.getElementById('footInv').innerHTML = filas.length
            ? '<tr><td class="fw-bold text-end">TOTAL</td>'
                + '<td class="currency fw-bold">' + pesos(total) + '</td>'
                + '<td colspan="3"></td></tr>'
            : '';
    }

    function pintarKpi() {
        var filas = (datos && datos.filas) || [];
        var total = 0;

        filas.forEach(function(f) {
            total += Number(f.IMPORTE_ARS) || 0;
        });

        // Las filas vienen de la más nueva a la más vieja.
        var ultima = filas.length ? filas[0] : null;

        texto('ultimoImporteInv', ultima ? pesosPlano(ultima.IMPORTE_ARS) : '$ 0,00');
        texto('ultimaFechaInv', ultima ? ('Al ' + fechaCorta(ultima.FECHA)) : 'Sin cargas');
        texto('totalInv', pesosPlano(total));
        texto('detalleTotalInv', filas.length + ' fecha(s) con importe vigente');

        mostrar('summaryInv', true, 'flex');
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardar() {
        var fecha = valor('fechaInv');
        var importe = valor('importeInv');

        if (!fecha) {
            Notificacion.campoInvalido('fechaInv', 'Elegí la fecha del saldo.');
            return;
        }

        if (importe === '' || isNaN(Number(importe))) {
            Notificacion.campoInvalido('importeInv', 'Ingresá el importe en pesos.', {
                detalle: '0 es un valor válido: significa que ese día no había saldo '
                       + 'invertido.'
            });

            return;
        }

        if (Number(importe) < 0) {
            Notificacion.campoInvalido('importeInv',
                'El importe no puede ser negativo: restaría del tablero en vez de sumar.');

            return;
        }

        var btn = document.getElementById('btnGuardarInv');

        btn.disabled = true;

        pedirJson(URL_OTROS + '?action=saveSaldoInversiones',
                { fecha: fecha, importe_ars: Number(importe) })
            .then(function(data) {
                btn.disabled = false;
                setValor('importeInv', '');

                if (data && data.piso) {
                    Notificacion.exito('Saldo actualizado.', {
                        detalle: 'La carga anterior de esa fecha queda en el historial.'
                    });
                } else {
                    Notificacion.exito('Saldo cargado.');
                }

                cargar();
            })
            .catch(function(error) {
                btn.disabled = false;
                Notificacion.error('No se pudo guardar: ' + error.message);
            });
    }

    /* ================================================================
       HISTORIAL
       ================================================================ */

    function abrirHistorial(fecha) {
        pedirJson(URL_OTROS + '?action=getHistorialInversiones&fecha='
                + encodeURIComponent(fecha))
            .then(function(filas) {
                texto('historialFechaInv', fechaCorta(fecha));

                document.getElementById('bodyHistorialInv').innerHTML =
                    (filas || []).map(function(f) {
                        return '<tr class="' + (f.VIGENTE ? '' : 'inv-pisada') + '">'
                            + '<td class="currency">' + pesos(f.IMPORTE_ARS) + '</td>'
                            + '<td class="text-center">'
                            +     (f.VIGENTE
                                    ? '<span class="badge bg-success">vigente</span>'
                                    : '<span class="badge bg-secondary">pisada</span>')
                            + '</td>'
                            + '<td class="text-center inv-alta">' + escapar(f.FECHA_ALTA || '—')
                            +     subtituloUsuario(f.USUARIO) + '</td>'
                            + '</tr>';
                    }).join('');

                if (modalHistorial) {
                    modalHistorial.show();
                }
            })
            .catch(function(error) {
                Notificacion.error('No se pudo leer el historial: ' + error.message);
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = v;
        }
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? String(el.value).trim() : '';
    }

    function setValor(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.value = v;
        }
    }

    function pesos(v) {
        return '<span class="inv-importe">' + pesosPlano(v) + '</span>';
    }

    function pesosPlano(v) {
        return '$ ' + (Number(v) || 0).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fechaCorta(v) {
        if (!v) {
            return '—';
        }

        var p = String(v).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(v);
    }

    function hoyISO() {
        var d = new Date();

        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    function subtituloUsuario(usuario) {
        return '<div class="inv-subtitulo">' + escapar(usuario || 'sin usuario') + '</div>';
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
