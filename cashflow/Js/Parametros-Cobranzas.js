/**
 * Parámetros - Cobranzas
 *
 * Dos cosas distintas en la misma pantalla, y conviene no confundirlas:
 *
 *   - La ESCALA DE DESCUENTO es UNA SOLA y vale para todos los clientes. Se
 *     edita entera y se guarda entera. Antes se cargaba por cliente y por medio
 *     de pago desde un modal por fila, lo que obligaba a repetir la misma
 *     escala en cada franquicia y dejaba a la mayoría sin ninguna.
 *   - El PPP es POR GRUPO EMPRESARIO: se calcula con los recibos de Tango de
 *     los últimos 100 días de todos los locales del grupo, y se pisa a mano
 *     para el grupo entero. Un cliente sin grupo es su propio grupo. La tabla
 *     muestra una fila por grupo y debajo sus clientes, que son sólo las
 *     franquicias habilitadas en el directorio de sucursales.
 *
 * El medio de pago sigue editable por cliente porque describe cómo opera, pero
 * ya NO entra en el cálculo del porcentaje.
 *
 * La regla del PPP efectivo que vale es la del servidor (Ingresos::pppEfectivo);
 * acá se espeja sólo para que el número se mueva al guardar sin recargar.
 */

(function() {
    'use strict';

    let grupos = [];
    let avisos = [];
    let descartados = 0;
    let escala = [];

    /** Espejo de Ingresos::pppEfectivo(): manual > calculado > DIAS_PP_MAX > 30 */
    function pppEfectivo(manual, calculado, diasPpMax) {
        var candidatos = [manual, calculado, diasPpMax];

        for (var i = 0; i < candidatos.length; i++) {
            var n = parseInt(candidatos[i], 10);

            if (!isNaN(n) && n > 0) {
                return n;
            }
        }

        return 30;
    }

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
                    var data = result.data || {};

                    grupos = data.grupos || [];
                    avisos = data.avisos || [];
                    descartados = data.descartados || 0;

                    pintarAvisos();
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

    /**
     * Los avisos de la tarjeta: el script del PPP sin correr, o el directorio
     * de sucursales que no se pudo leer. No son decoración: explican por qué
     * un PPP está vacío o por qué aparecen franquicias dadas de baja.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParamCob');

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? avisos.map(function(a) {
                  return '<div class="alert alert-warning py-2 px-3 mb-2">'
                      + '<i class="fas fa-triangle-exclamation me-1"></i><small>'
                      + escapar(a) + '</small></div>';
              }).join('')
            : '';
    }

    /**
     * Filtro consciente de grupos: un grupo se muestra si él o alguno de sus
     * clientes matchea. Si matchea el grupo, se ven todos sus clientes; si no,
     * sólo los que matchean. Sin término, todo.
     */
    function filtrarClientes() {
        var term = (document.getElementById('busquedaParamCob').value || '').toLowerCase().trim();
        var tbody = document.getElementById('tbodyParamCob');

        if (!tbody) {
            return;
        }

        tbody.querySelectorAll('tr.pc-grupo').forEach(function(filaGrupo) {
            var agrup = filaGrupo.getAttribute('data-agrup');
            var clientes = tbody.querySelectorAll('tr.pc-cliente[data-agrup="' + agrup + '"]');
            var grupoMatchea = !term || filaGrupo.textContent.toLowerCase().includes(term);
            var algunCliente = false;

            clientes.forEach(function(fila) {
                var matchea = grupoMatchea || fila.textContent.toLowerCase().includes(term);

                fila.style.display = matchea ? '' : 'none';
                algunCliente = algunCliente || matchea;
            });

            filaGrupo.style.display = (grupoMatchea || algunCliente) ? '' : 'none';
        });
    }

    function renderizarTabla() {
        var tbody = document.getElementById('tbodyParamCob');
        var pie = document.getElementById('pieParamCob');

        if (!tbody) {
            return;
        }

        if (grupos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">'
                + 'No se encontraron franquicias</td></tr>';

            if (pie) {
                pie.textContent = '';
            }

            return;
        }

        var html = '';
        var totalClientes = 0;

        grupos.forEach(function(g) {
            html += filaGrupo(g);

            (g.clientes || []).forEach(function(c) {
                html += filaCliente(g, c);
                totalClientes++;
            });
        });

        tbody.innerHTML = html;

        if (pie) {
            pie.textContent = grupos.length + ' grupo(s) · ' + totalClientes + ' franquicia(s) habilitada(s)'
                + (descartados > 0
                    ? ' · ' + descartados + ' franquicia(s) de Tango sin sucursal habilitada no se listan'
                    : '');
        }

        tbody.querySelectorAll('.select-medio-pago').forEach(function(sel) {
            sel.addEventListener('change', function() {
                guardarMedioPago(this.getAttribute('data-cod'), this.value);
            });
        });

        tbody.querySelectorAll('.btn-save-ppp').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var agrup = this.getAttribute('data-agrup');
                var input = tbody.querySelector('.input-ppp-manual[data-agrup="' + agrup + '"]');

                guardarPPP(agrup, input ? input.value : null);
            });
        });

        tbody.querySelectorAll('.input-ppp-manual').forEach(function(inp) {
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    guardarPPP(this.getAttribute('data-agrup'), this.value);
                }
            });
        });

        // Un filtro tipeado sobrevive a un re-render (por ejemplo tras guardar)
        filtrarClientes();
    }

    /** La fila del grupo: es donde vive el PPP, calculado y manual */
    function filaGrupo(g) {
        var agrup = g.cod_agrup;
        var cant = (g.clientes || []).length;
        var pppCalc = g.ppp_calculado > 0
            ? g.ppp_calculado + ' días <small class="text-muted">(' + g.cant_recibos + ' recibo(s), '
                + g.cant_clientes_ppp + ' cliente(s))</small>'
            : '<span class="text-muted" title="Sin recibos de Tango en los últimos 100 días: se '
                + 'proyecta con el manual, o con el respaldo del cliente">-</span>';
        var pppManVal = (g.ppp_manual !== null && g.ppp_manual !== undefined) ? g.ppp_manual : '';

        return '<tr class="pc-grupo" data-agrup="' + escapar(agrup) + '">'
            + '<td><code>' + escapar(agrup) + '</code> '
            +     (g.es_grupo
                    ? '<span class="pc-badge pc-badge-grupo" title="Grupo empresario de GVA62">grupo</span>'
                    : '<span class="pc-badge" title="Cliente sin grupo empresario: es su propio grupo">sin grupo</span>')
            + '</td>'
            + '<td><strong>' + escapar(g.nombre_agrup) + '</strong> '
            +     '<small class="text-muted">' + cant + ' cliente(s)</small></td>'
            + '<td></td>'
            + '<td></td>'
            + '<td class="text-center">' + pppCalc + '</td>'
            + '<td class="text-center">'
            +     '<div class="input-group input-group-sm justify-content-center" '
            +         'style="max-width: 140px; margin: 0 auto;">'
            +         '<input type="number" min="0" step="1" '
            +             'class="form-control form-control-sm text-center input-ppp-manual" '
            +             'value="' + pppManVal + '" '
            +             'placeholder="' + (g.ppp_calculado > 0 ? g.ppp_calculado : 30) + '" '
            +             'title="Pisa el PPP calculado para todos los clientes del grupo. Vacío = volver al calculado" '
            +             'data-agrup="' + escapar(agrup) + '">'
            +         '<button class="btn btn-outline-primary btn-save-ppp" '
            +             'title="Guardar PPP manual del grupo" data-agrup="' + escapar(agrup) + '">'
            +             '<i class="fas fa-check"></i></button>'
            +     '</div>'
            + '</td>'
            + '<td class="text-center text-primary"><strong>' + g.ppp_efectivo + ' días</strong></td>'
            + '</tr>';
    }

    /** La fila de un cliente: hereda el PPP del grupo; lo suyo es el medio de pago */
    function filaCliente(g, c) {
        var cod = c.cod_cliente;
        var medio = c.medio_pago_default || 'ECHEQ';
        var sucursal = c.nro_sucursal
            ? '<code>' + escapar(c.nro_sucursal) + '</code> ' + escapar(c.desc_sucursal)
            : '<span class="text-muted">—</span>';

        // El efectivo del cliente sólo difiere del grupo cuando el grupo no
        // tiene ni manual ni calculado y entra el DIAS_PP_MAX del cliente.
        var distinto = (c.ppp_efectivo !== g.ppp_efectivo);

        return '<tr class="pc-cliente" data-agrup="' + escapar(g.cod_agrup) + '" data-cod="' + escapar(cod) + '">'
            + '<td class="ps-4"><code>' + escapar(cod) + '</code></td>'
            + '<td>' + escapar(c.razon_social) + '</td>'
            + '<td>' + sucursal + '</td>'
            + '<td class="text-center">'
            +     '<select class="form-select form-select-sm select-medio-pago mx-auto" '
            +         'style="max-width: 140px;" data-cod="' + escapar(cod) + '" '
            +         'title="Informativo: no interviene en el descuento">'
            +         '<option value="ECHEQ"' + (medio === 'ECHEQ' ? ' selected' : '') + '>ECHEQ</option>'
            +         '<option value="TRANSFERENCIA"' + (medio === 'TRANSFERENCIA' ? ' selected' : '') + '>TRANSFERENCIA</option>'
            +     '</select>'
            + '</td>'
            + '<td class="text-center text-muted"><small>hereda del grupo</small></td>'
            + '<td class="text-center text-muted">—</td>'
            + '<td class="text-center' + (distinto ? ' text-warning' : ' text-muted') + '"'
            +     (distinto ? ' title="El grupo no tiene PPP: se usa el respaldo del cliente (DIAS_PP_MAX o 30)"' : '')
            +     '>' + c.ppp_efectivo + ' días</td>'
            + '</tr>';
    }

    function buscarCliente(codCliente) {
        for (var i = 0; i < grupos.length; i++) {
            var cli = (grupos[i].clientes || []).find(c => c.cod_cliente === codCliente);

            if (cli) {
                return cli;
            }
        }

        return null;
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
                var cli = buscarCliente(codCliente);

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

    /**
     * Guarda el PPP manual de UN GRUPO y recalcula en memoria el efectivo del
     * grupo y de cada uno de sus clientes, con la misma regla del servidor.
     */
    function guardarPPP(codAgrup, valor) {
        var pppVal = (valor !== '' && valor !== null) ? parseInt(valor, 10) : null;

        if (pppVal !== null && (isNaN(pppVal) || pppVal < 0)) {
            Notificacion.advertencia('El PPP tiene que ser un número de días no negativo.');
            return;
        }

        fetch('Controller/ParametrosController.php?action=savePPPManualGrupo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cod_agrup: codAgrup, ppp_manual: pppVal })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var g = grupos.find(x => x.cod_agrup === codAgrup);

                if (g) {
                    g.ppp_manual = (pppVal !== null && pppVal > 0) ? pppVal : null;
                    g.ppp_efectivo = pppEfectivo(g.ppp_manual, g.ppp_calculado, null);

                    (g.clientes || []).forEach(function(c) {
                        c.ppp_efectivo = pppEfectivo(g.ppp_manual, g.ppp_calculado, c.dias_pp_max);
                    });
                }

                renderizarTabla();
                Notificacion.exito(result.message || 'PPP del grupo actualizado.');
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
