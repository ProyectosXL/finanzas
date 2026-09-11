/**
 * Parámetros - Cobranzas
 *
 * Dos cosas distintas en la misma pantalla, y conviene no confundirlas:
 *
 *   - La ESCALA DE DESCUENTO es UNA SOLA y vale para todos los clientes. Se
 *     edita entera y se guarda entera. Antes se cargaba por cliente y por medio
 *     de pago desde un modal por fila, lo que obligaba a repetir la misma
 *     escala en cada franquicia y dejaba a la mayoría sin ninguna.
 *   - El PPP es POR CLIENTE: se calcula con los últimos 3 cobros y se puede
 *     pisar a mano. Eso no cambió.
 *
 * El medio de pago sigue editable porque describe cómo opera el cliente, pero
 * ya NO entra en el cálculo del porcentaje.
 */

(function() {
    'use strict';

    let clientesConfig = [];
    let escala = [];

    function inicializar() {
        console.log('Inicializando Parámetros - Cobranzas');

        conectar('btnRefreshParamCob', 'click', cargarClientes);
        conectar('btnAgregarTramoEsc', 'click', agregarTramo);
        conectar('btnGuardarEscala', 'click', guardarEscala);

        var inputBusqueda = document.getElementById('busquedaParamCob');

        if (inputBusqueda) {
            inputBusqueda.addEventListener('keyup', filtrarClientes);
        }

        // Los dos plazos globales -mayoristas y exportaciones Tasky- se
        // guardan solos al cambiar, con el mismo endpoint.
        PLAZOS_GLOBALES.forEach(function(clave) {
            var input = document.querySelector('input[data-clave="' + clave + '"]');

            if (input) {
                input.addEventListener('change', function() {
                    guardarParametroGlobal(this);
                });
            }
        });

        cargarPlazosGlobales();
        cargarEscala();
        cargarClientes();
    }

    function conectar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener(evento, fn);
        }
    }

    /* ================================================================
       ESCALA DE DESCUENTO GENERAL
       ================================================================ */

    function cargarEscala() {
        fetch('Controller/ParametrosController.php?action=getEscalaDescuento')
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    escala = (result.data || []).map(function(t) {
                        return {
                            dias_desde: Number(t.dias_desde),
                            dias_hasta: Number(t.dias_hasta),
                            porcentaje_desc: Number(t.porcentaje_desc)
                        };
                    });

                    renderizarEscala();
                } else {
                    Notificacion.error('No se pudo leer la escala de descuento: ' + result.message);
                }
            })
            .catch(err => {
                Notificacion.error('Error de conexión al leer la escala: ' + err.message);
            });
    }

    function renderizarEscala() {
        var tbody = document.getElementById('tbodyEscalaCob');

        if (!tbody) {
            return;
        }

        if (!escala.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">'
                + 'La escala está vacía: todas las facturas irían con 0% de descuento.'
                + '</td></tr>';

            pintarAvisosEscala();
            return;
        }

        var html = '';

        escala.forEach(function(t, i) {
            html += '<tr data-i="' + i + '">'
                + '<td><input type="number" min="0" step="1" class="form-control form-control-sm esc-desde" '
                +     'value="' + t.dias_desde + '"></td>'
                + '<td><input type="number" min="0" step="1" class="form-control form-control-sm esc-hasta" '
                +     'value="' + t.dias_hasta + '"></td>'
                + '<td><div class="input-group input-group-sm">'
                +     '<input type="number" min="0" max="100" step="0.01" class="form-control esc-porc" '
                +         'value="' + t.porcentaje_desc + '">'
                +     '<span class="input-group-text">%</span>'
                + '</div></td>'
                + '<td class="text-center">'
                +     '<button class="btn btn-sm btn-outline-danger py-0 px-2 esc-baja" '
                +         'title="Quitar este tramo"><i class="fas fa-trash-alt"></i></button>'
                + '</td>'
                + '</tr>';
        });

        tbody.innerHTML = html;

        tbody.querySelectorAll('input').forEach(function(inp) {
            inp.addEventListener('input', leerEscalaDelDom);
        });

        tbody.querySelectorAll('.esc-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var i = Number(btn.closest('tr').getAttribute('data-i'));

                escala.splice(i, 1);
                renderizarEscala();
            });
        });

        pintarAvisosEscala();
    }

    /** Lee lo tipeado sin redibujar: si se redibujara, el input perdería el foco */
    function leerEscalaDelDom() {
        var filas = document.querySelectorAll('#tbodyEscalaCob tr[data-i]');

        filas.forEach(function(tr) {
            var i = Number(tr.getAttribute('data-i'));

            if (!escala[i]) {
                return;
            }

            escala[i].dias_desde = Number(tr.querySelector('.esc-desde').value);
            escala[i].dias_hasta = Number(tr.querySelector('.esc-hasta').value);
            escala[i].porcentaje_desc = Number(tr.querySelector('.esc-porc').value);
        });

        pintarAvisosEscala();
    }

    function agregarTramo() {
        // El tramo nuevo arranca donde termina el último, que es el caso
        // normal: extender la escala hacia plazos más largos.
        var desde = 0;

        escala.forEach(function(t) {
            if (t.dias_hasta + 1 > desde) {
                desde = t.dias_hasta + 1;
            }
        });

        escala.push({ dias_desde: desde, dias_hasta: desde + 9, porcentaje_desc: 0 });
        renderizarEscala();
    }

    /**
     * Espeja la validación del servidor (Ingresos::validarEscala) para poder
     * bloquear el botón y decir por qué. El servidor la corre igual: el JS sólo
     * explica, no autoriza.
     */
    function erroresEscala() {
        var errores = [];
        var lista = escala.slice().sort(function(a, b) { return a.dias_desde - b.dias_desde; });

        if (!lista.length) {
            return ['La escala no puede quedar vacía: sin tramos, todas las facturas '
                + 'irían con 0% de descuento.'];
        }

        lista.forEach(function(t) {
            if (t.dias_hasta < t.dias_desde) {
                errores.push('El tramo ' + t.dias_desde + '-' + t.dias_hasta
                    + ' termina antes de empezar.');
            }

            if (t.porcentaje_desc < 0 || t.porcentaje_desc > 100) {
                errores.push('El descuento del tramo ' + t.dias_desde + '-' + t.dias_hasta
                    + ' tiene que estar entre 0% y 100%.');
            }
        });

        if (lista[0].dias_desde !== 0) {
            errores.push('La escala tiene que arrancar en 0 días: hoy arranca en '
                + lista[0].dias_desde + ' y las facturas más nuevas quedarían sin descuento.');
        }

        for (var i = 1; i < lista.length; i++) {
            var ant = lista[i - 1];
            var act = lista[i];

            if (act.dias_desde <= ant.dias_hasta) {
                errores.push('Los tramos ' + ant.dias_desde + '-' + ant.dias_hasta + ' y '
                    + act.dias_desde + '-' + act.dias_hasta + ' se superponen: un mismo plazo '
                    + 'tendría dos descuentos.');
            } else if (act.dias_desde > ant.dias_hasta + 1) {
                errores.push('Entre ' + ant.dias_hasta + ' y ' + act.dias_desde + ' días no hay '
                    + 'tramo: esas facturas irían con 0% sin que nadie lo haya decidido.');
            }
        }

        return errores;
    }

    function pintarAvisosEscala() {
        var cont = document.getElementById('avisosEscalaCob');
        var btn = document.getElementById('btnGuardarEscala');
        var errores = erroresEscala();

        if (cont) {
            cont.innerHTML = errores.length
                ? '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                    + '<i class="fas fa-triangle-exclamation me-1"></i>'
                    + errores.join(' ') + '</small></div>'
                : '';
        }

        if (btn) {
            btn.disabled = errores.length > 0;
        }
    }

    function guardarEscala() {
        leerEscalaDelDom();

        if (erroresEscala().length) {
            return;
        }

        fetch('Controller/ParametrosController.php?action=saveEscalaDescuento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tramos: escala })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                escala = (result.data || []).map(function(t) {
                    return {
                        dias_desde: Number(t.dias_desde),
                        dias_hasta: Number(t.dias_hasta),
                        porcentaje_desc: Number(t.porcentaje_desc)
                    };
                });

                renderizarEscala();
                Notificacion.exito('Escala de descuento guardada.');
            } else {
                Notificacion.error('No se pudo guardar la escala: ' + result.message);
            }
        })
        .catch(err => {
            Notificacion.error('Error de conexión al guardar la escala: ' + err.message);
        });
    }

    /* ================================================================
       PLAZOS GLOBALES: MAYORISTAS Y EXPORTACIONES TASKY
       ================================================================ */

    /** Los parámetros de plazo que se editan en esta pantalla */
    var PLAZOS_GLOBALES = ['cobranzas_may_dias_vto', 'exportaciones_tasky_dias_cobro'];

    function cargarPlazosGlobales() {
        fetch('Controller/ParametrosController.php?action=getParametros')
            .then(res => res.json())
            .then(result => {
                if (!result.success || !result.data) {
                    return;
                }

                PLAZOS_GLOBALES.forEach(function(clave) {
                    var input = document.querySelector('input[data-clave="' + clave + '"]');
                    var p = result.data.find(function(x) { return x.CLAVE === clave; });

                    // Si el parámetro no está sembrado queda el valor por
                    // defecto del HTML, que es el mismo que usa el backend.
                    if (input && p && p.VALOR) {
                        input.value = p.VALOR;
                    }
                });
            })
            .catch(err => {
                console.error('Error al cargar los plazos globales de cobranzas:', err);
            });
    }

    function guardarParametroGlobal(input) {
        var clave = input.getAttribute('data-clave');
        var valor = parseInt(input.value, 10);

        if (isNaN(valor) || valor <= 0) {
            Notificacion.campoInvalido(input, 'El plazo tiene que ser un número entero mayor a 0.');
            return;
        }

        fetch('Controller/ParametrosController.php?action=saveParametro', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ clave: clave, valor: String(valor) })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var card = document.getElementById('card-' + clave);

                if (card) {
                    card.classList.add('bg-light-success');
                    setTimeout(function() {
                        card.classList.remove('bg-light-success');
                    }, 1200);
                }
            } else {
                Notificacion.error('No se pudo guardar el parámetro: ' + result.message);
            }
        })
        .catch(err => {
            Notificacion.error('Error de conexión al guardar el parámetro: ' + err.message);
        });
    }

    /* ================================================================
       PPP POR CLIENTE
       ================================================================ */

    function mostrarCargando(mostrar) {
        var spinner = document.getElementById('loadingParamCob');
        var wrapper = document.getElementById('wrapperTablaParamCob');

        if (spinner) spinner.style.display = mostrar ? 'flex' : 'none';
        if (wrapper) wrapper.style.display = mostrar ? 'none' : 'block';
    }

    function cargarClientes() {
        mostrarCargando(true);

        fetch('Controller/ParametrosController.php?action=getCobranzasClientesConfig')
            .then(res => res.json())
            .then(result => {
                mostrarCargando(false);

                if (result.success) {
                    clientesConfig = result.data || [];
                    renderizarTabla();
                } else {
                    Notificacion.error('No se pudo cargar la configuración de cobranzas: '
                        + result.message);
                }
            })
            .catch(err => {
                mostrarCargando(false);
                Notificacion.error('Error de conexión al cargar cobranzas: ' + err.message);
            });
    }

    function filtrarClientes() {
        var term = (document.getElementById('busquedaParamCob').value || '').toLowerCase().trim();

        document.querySelectorAll('#tbodyParamCob tr').forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    }

    function renderizarTabla() {
        var tbody = document.getElementById('tbodyParamCob');

        if (!tbody) {
            return;
        }

        if (clientesConfig.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">'
                + 'No se encontraron clientes</td></tr>';
            return;
        }

        var html = '';

        clientesConfig.forEach(function(c) {
            var cod = c.cod_cliente;
            var medio = c.medio_pago_default || 'ECHEQ';
            var pppCalc = c.ppp_calculado > 0
                ? c.ppp_calculado + ' días <small class="text-muted">(' + c.cant_cobros + ' cobros)</small>'
                : '<span class="text-muted">-</span>';
            var pppManVal = (c.ppp_manual !== null && c.ppp_manual !== undefined) ? c.ppp_manual : '';
            var pppEfectivo = c.ppp_efectivo > 0
                ? '<strong>' + c.ppp_efectivo + ' días</strong>'
                : '<span class="text-muted">30 días (defecto)</span>';

            html += '<tr data-cod="' + cod + '">'
                + '<td><code>' + cod + '</code></td>'
                + '<td><strong>' + escapar(c.razon_social) + '</strong></td>'
                + '<td class="text-center">'
                +     '<select class="form-select form-select-sm select-medio-pago mx-auto" '
                +         'style="max-width: 140px;" data-cod="' + cod + '" '
                +         'title="Informativo: no interviene en el descuento">'
                +         '<option value="ECHEQ"' + (medio === 'ECHEQ' ? ' selected' : '') + '>ECHEQ</option>'
                +         '<option value="TRANSFERENCIA"' + (medio === 'TRANSFERENCIA' ? ' selected' : '') + '>TRANSFERENCIA</option>'
                +     '</select>'
                + '</td>'
                + '<td class="text-center">' + pppCalc + '</td>'
                + '<td class="text-center">'
                +     '<div class="input-group input-group-sm justify-content-center" '
                +         'style="max-width: 140px; margin: 0 auto;">'
                +         '<input type="number" min="0" step="1" '
                +             'class="form-control form-control-sm text-center input-ppp-manual" '
                +             'value="' + pppManVal + '" '
                +             'placeholder="' + (c.ppp_calculado > 0 ? c.ppp_calculado : 30) + '" '
                +             'data-cod="' + cod + '">'
                +         '<button class="btn btn-outline-primary btn-save-ppp" '
                +             'title="Guardar PPP manual" data-cod="' + cod + '">'
                +             '<i class="fas fa-check"></i></button>'
                +     '</div>'
                + '</td>'
                + '<td class="text-center text-primary">' + pppEfectivo + '</td>'
                + '</tr>';
        });

        tbody.innerHTML = html;

        tbody.querySelectorAll('.select-medio-pago').forEach(function(sel) {
            sel.addEventListener('change', function() {
                guardarMedioPago(this.getAttribute('data-cod'), this.value);
            });
        });

        tbody.querySelectorAll('.btn-save-ppp').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cod = this.getAttribute('data-cod');
                var input = tbody.querySelector('.input-ppp-manual[data-cod="' + cod + '"]');

                guardarPPP(cod, input ? input.value : null);
            });
        });

        tbody.querySelectorAll('.input-ppp-manual').forEach(function(inp) {
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    guardarPPP(this.getAttribute('data-cod'), this.value);
                }
            });
        });
    }

    function guardarMedioPago(codCliente, medioPago) {
        fetch('Controller/ParametrosController.php?action=saveMedioPagoCliente', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cod_cliente: codCliente, medio_pago: medioPago })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var cli = clientesConfig.find(c => c.cod_cliente === codCliente);

                if (cli) {
                    cli.medio_pago_default = medioPago;
                }
            } else {
                Notificacion.error('No se pudo guardar el medio de pago: ' + result.message);
                cargarClientes();
            }
        })
        .catch(err => {
            Notificacion.error('Error de conexión al guardar el medio de pago: ' + err.message);
            cargarClientes();
        });
    }

    function guardarPPP(codCliente, valor) {
        var pppVal = (valor !== '' && valor !== null) ? parseInt(valor, 10) : null;

        fetch('Controller/ParametrosController.php?action=savePPPManual', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cod_cliente: codCliente, ppp_manual: pppVal })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var cli = clientesConfig.find(c => c.cod_cliente === codCliente);

                if (cli) {
                    cli.ppp_manual = pppVal;
                    cli.ppp_efectivo = (pppVal !== null && pppVal > 0)
                        ? pppVal
                        : (cli.ppp_calculado > 0 ? cli.ppp_calculado : 30);
                }

                renderizarTabla();
                Notificacion.exito('PPP actualizado.');
            } else {
                Notificacion.error('No se pudo guardar el PPP: ' + result.message);
            }
        })
        .catch(err => {
            Notificacion.error('Error de conexión al guardar el PPP: ' + err.message);
        });
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
})();
