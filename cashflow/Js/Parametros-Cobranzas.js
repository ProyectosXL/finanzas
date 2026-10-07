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
    let afuera = {};
    let escala = [];

    /*
     * QUÉ GRUPOS ESTÁN ABIERTOS, EN MEMORIA Y EN NINGÚN OTRO LADO.
     *
     * La tarjeta abre siempre con todo contraído: son más de ochenta grupos y
     * lo que se viene a hacer es tocar el PPP de uno. No va a `localStorage`
     * porque no es una preferencia sino lo que el usuario está mirando ahora,
     * y un default guardado ahí sería indistinguible de una elección. Vive
     * acá, y no en el DOM, porque guardar un PPP redibuja la tabla entera: si
     * el estado estuviera en las filas, el guardado cerraría lo que el usuario
     * acababa de abrir.
     *
     * `enBusqueda` es el mismo mapa pero para mientras hay texto en el
     * buscador: ahí todo grupo con coincidencias se ve abierto, y el chevron
     * sólo lo cierra por esa búsqueda. Es un mapa APARTE a propósito: como
     * buscar no toca `abiertos`, al vaciar el buscador cada grupo vuelve solo
     * al estado que tenía antes, sin tener que sacar ni restaurar una foto.
     */
    let abiertos = {};
    let enBusqueda = null;

    /** Si se puede excluir: sin la tabla de exclusiones, el switch va deshabilitado */
    let exclusionDisponible = true;

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
        conectar('btnGruposParamCob', 'click', alternarTodos);

        var inputBusqueda = document.getElementById('busquedaParamCob');

        if (inputBusqueda) {
            inputBusqueda.addEventListener('input', buscar);
        }

        // Un solo listener sobre el tbody, que sobrevive a cada redibujo: los
        // chevrones se reemplazan con la tabla y engancharlos uno por uno
        // obligaría a hacerlo de nuevo cada vez.
        var tbody = document.getElementById('tbodyParamCob');

        if (tbody) {
            tbody.addEventListener('click', function(e) {
                var chevron = e.target.closest('.pc-chevron');

                if (chevron) {
                    alternarGrupo(chevron.getAttribute('data-agrup'));
                }
            });
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

                    // Quién cargó la escala vigente y cuándo (Js/auditoria.js)
                    var aud = document.getElementById('audEscalaCob');

                    if (aud) {
                        aud.innerHTML = result.auditoria ? Auditoria.icono({ alta: result.auditoria }) : '';
                    }
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

        // Sin permiso de edicion, la escala como texto (Js/permisos.js)
        Permisos.soloLectura(tbody);

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

                // Con el valor ya puesto: sin permiso, como texto (Js/permisos.js)
                PLAZOS_GLOBALES.forEach(function(c) { Permisos.soloLectura('card-' + c); });
            })
            .catch(err => {
                console.error('Error al cargar los plazos globales de cobranzas:', err);
                PLAZOS_GLOBALES.forEach(function(c) { Permisos.soloLectura('card-' + c); });
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
        var wrapper = document.getElementById('wrapperTablaParamCob');

        if (mostrar) { Cargando.mostrar('loadingParamCob'); } else { Cargando.ocultar('loadingParamCob'); }
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
                    afuera = data.afuera || {};
                    exclusionDisponible = data.exclusion_disponible !== false;

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

    /* ================================================================
       GRUPOS: EXPANDIR, CONTRAER Y BUSCAR

       Es el mismo criterio que los renglones agrupados del tablero
       (Js/Cashflow.js): los clientes se ESCONDEN con una clase de
       `display: none` y no se sacan del DOM, así Exportar baja lo que se ve
       y el orden de la tabla los mueve con su grupo aunque estén ocultos.
       ================================================================ */

    function terminoBusqueda() {
        var input = document.getElementById('busquedaParamCob');

        return input ? (input.value || '').toLowerCase().trim() : '';
    }

    /**
     * El buscador cambió. Cada búsqueda nueva arranca con todos los grupos
     * que coinciden abiertos -es lo que se espera al buscar un cliente: verlo-,
     * y al vaciarlo vuelve a valer `abiertos`, que buscar no tocó.
     */
    function buscar() {
        enBusqueda = terminoBusqueda() ? {} : null;
        aplicarVisibilidad();
    }

    /** El mapa que manda ahora: el de la búsqueda, o el de siempre */
    function estadoVigente() {
        return enBusqueda || abiertos;
    }

    /**
     * Si un grupo se ve abierto ahora. Buscando, un grupo está abierto salvo
     * que lo hayan cerrado en esta búsqueda; sin buscar, cerrado salvo que lo
     * hayan abierto.
     */
    function estaAbierto(agrup) {
        return enBusqueda ? enBusqueda[agrup] !== false : abiertos[agrup] === true;
    }

    function alternarGrupo(agrup) {
        estadoVigente()[agrup] = !estaAbierto(agrup);
        aplicarVisibilidad();
    }

    /**
     * Un solo botón para los dos gestos, con la regla de `cfBtnGrupos`: si
     * queda alguno cerrado abre todos; si están todos abiertos los cierra. Con
     * dos botones uno siempre está de más. Actúa sobre los grupos que se ven:
     * buscando, abrir uno que la búsqueda escondió no mostraría nada.
     */
    function alternarTodos() {
        var visibles = gruposVisibles();
        var abrir = visibles.some(function(agrup) { return !estaAbierto(agrup); });
        var estado = estadoVigente();

        visibles.forEach(function(agrup) { estado[agrup] = abrir; });
        aplicarVisibilidad();
    }

    function gruposVisibles() {
        var tbody = document.getElementById('tbodyParamCob');

        if (!tbody) {
            return [];
        }

        return Array.prototype.filter.call(tbody.querySelectorAll('tr.pc-grupo'), function(tr) {
            return !tr.classList.contains('pc-oculta');
        }).map(function(tr) {
            return tr.getAttribute('data-agrup');
        });
    }

    /**
     * Pone cada fila visible u oculta según el buscador y el estado de su
     * grupo. Es la ÚNICA función que esconde filas de la tabla: si la búsqueda
     * y los grupos escondieran cada uno por su lado, el que corre segundo
     * pisaría al primero.
     *
     * Con texto en el buscador:
     *   - un grupo que coincide por su código o su nombre se ve con todos sus
     *     clientes;
     *   - si no, se ve con los clientes que coinciden, y se esconde si no
     *     coincide ninguno.
     * Sin texto, todos los grupos, y sus clientes sólo si está abierto.
     *
     * Se compara por atributo en JS y no armando un selector con el código
     * adentro: un código con comillas rompería el selector.
     */
    function aplicarVisibilidad() {
        var tbody = document.getElementById('tbodyParamCob');

        if (!tbody) {
            return;
        }

        var term = terminoBusqueda();
        var clientesPorGrupo = {};

        tbody.querySelectorAll('tr.pc-cliente').forEach(function(fila) {
            var agrup = fila.getAttribute('data-agrup');

            (clientesPorGrupo[agrup] = clientesPorGrupo[agrup] || []).push(fila);
        });

        tbody.querySelectorAll('tr.pc-grupo').forEach(function(filaGrupo) {
            var agrup = filaGrupo.getAttribute('data-agrup');
            var clientes = clientesPorGrupo[agrup] || [];
            var abierto = estaAbierto(agrup);
            var grupoVisible = true;

            if (term) {
                var grupoMatchea = textoBuscable(filaGrupo).includes(term);
                var alguno = false;

                clientes.forEach(function(fila) {
                    var matchea = grupoMatchea || textoBuscable(fila).includes(term);

                    fila.classList.toggle('pc-oculta', !(matchea && abierto));
                    alguno = alguno || matchea;
                });

                grupoVisible = grupoMatchea || alguno;
            } else {
                clientes.forEach(function(fila) {
                    fila.classList.toggle('pc-oculta', !abierto);
                });
            }

            filaGrupo.classList.toggle('pc-oculta', !grupoVisible);
            pintarChevron(filaGrupo, abierto);
        });

        actualizarBotonGrupos();
    }

    /**
     * Lo que se busca de una fila: su texto, sin los indicadores del grupo.
     * "3 cliente(s)" no es un dato que alguien busque, y con él tipear "cli"
     * abría todos los grupos.
     */
    function textoBuscable(fila) {
        var clon = fila.cloneNode(true);

        clon.querySelectorAll('.pc-indicador').forEach(function(el) { el.remove(); });

        return clon.textContent.toLowerCase();
    }

    function pintarChevron(filaGrupo, abierto) {
        var chevron = filaGrupo.querySelector('.pc-chevron');

        if (!chevron) {
            return;
        }

        chevron.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        chevron.setAttribute('title', abierto ? 'Contraer los clientes del grupo'
            : 'Ver los clientes del grupo');

        var icono = chevron.querySelector('i');

        if (icono) {
            icono.className = 'fas fa-chevron-' + (abierto ? 'down' : 'right');
        }
    }

    function actualizarBotonGrupos() {
        var btn = document.getElementById('btnGruposParamCob');

        if (!btn) {
            return;
        }

        var visibles = gruposVisibles();

        // Sin grupos a la vista el botón no hace nada, y un botón que no hace
        // nada se aprieta y parece que falló.
        btn.style.display = visibles.length ? '' : 'none';

        var abrir = visibles.some(function(agrup) { return !estaAbierto(agrup); });

        btn.innerHTML = '<i class="fas fa-' + (abrir ? 'angles-down' : 'angles-up')
            + ' me-1"></i> ' + (abrir ? 'Expandir todo' : 'Contraer todo');
        btn.setAttribute('title', abrir
            ? 'Ver los clientes de todos los grupos'
            : 'Dejar sólo una fila por grupo');
    }

    function renderizarTabla() {
        var tbody = document.getElementById('tbodyParamCob');
        var pie = document.getElementById('pieParamCob');

        if (!tbody) {
            return;
        }

        if (grupos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">'
                + 'No se encontraron franquicias</td></tr>';

            if (pie) {
                pie.textContent = '';
            }

            actualizarBotonGrupos();

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

        Permisos.soloLectura(tbody);

        if (pie) {
            pie.textContent = grupos.length + ' grupo(s) · ' + totalClientes + ' franquicia(s) habilitada(s)'
                + textoAfuera();
        }

        conectarExclusion(tbody);

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

        // Lo que estaba abierto y lo que se estaba buscando sobreviven al
        // redibujo que hace guardar un PPP: el estado está en memoria, no en
        // las filas que se acaban de reemplazar.
        aplicarVisibilidad();
    }

    /**
     * Cuántos clientes [FL] de Tango no se listan, por motivo. Es un pie y no
     * un aviso: que una franquicia dada de baja no aparezca es la regla. Los
     * importes de esas facturas se avisan en Cobranzas FR y en el tablero,
     * que es donde esa plata falta.
     */
    function textoAfuera() {
        var partes = [];
        var inh = afuera.INHABILITADA || 0;
        var sinEstado = afuera.SIN_ESTADO || 0;
        var sinDir = afuera.SIN_DIRECTORIO || 0;

        if (inh > 0) {
            partes.push(inh + ' inhabilitada(s)');
        }

        if (sinEstado > 0) {
            partes.push(sinEstado + ' con el estado sin cargar en el directorio');
        }

        if (sinDir > 0) {
            partes.push(sinDir + ' sin cargar en el directorio');
        }

        return partes.length
            ? ' · No se listan ' + partes.join(', ') + ' (clientes [FL] de Tango).'
            : '';
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

        // El chevron es un <button> para que sea un control de verdad -foco
        // con el teclado, y TablaExport lo saca del clon-. Lleva data-lectura
        // porque Permisos.soloLectura() saca los botones de la grilla, y
        // abrir un grupo no es editar. El clic en el resto de la fila no hace
        // nada: ahí está el input del PPP manual.
        var chevron = '<button type="button" class="pc-chevron" data-lectura '
            + 'data-agrup="' + escapar(agrup) + '" aria-expanded="false" '
            + 'title="Ver los clientes del grupo"><i class="fas fa-chevron-right"></i></button>';

        return '<tr class="pc-grupo" data-agrup="' + escapar(agrup) + '">'
            + '<td class="text-nowrap">' + chevron + '<code>' + escapar(agrup) + '</code> '
            +     (g.es_grupo
                    ? '<span class="pc-badge pc-badge-grupo" title="Grupo empresario de GVA62">grupo</span>'
                    : '<span class="pc-badge" title="Cliente sin grupo empresario: es su propio grupo">sin grupo</span>')
            + '</td>'
            + '<td><strong>' + escapar(g.nombre_agrup) + '</strong> '
            +     '<small class="text-muted pc-indicador">' + cant + ' cliente(s)</small>'
            +     indicadorExcluidos(g) + '</td>'
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
            + '<td></td>'
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

        // data-orden-sigue: el cliente viaja pegado a su grupo cuando se ordena
        // por una columna (Js/tabla-orden.js). Sin él, ordenar por razón
        // social mezclaba los clientes de todos los grupos y cada uno quedaba
        // debajo de un PPP que no es el suyo. Oculto o no, se mueve igual: la
        // clase .pc-oculta viaja con la fila.
        return '<tr class="pc-cliente' + (c.excluido ? ' pc-excluido' : '') + '" data-orden-sigue data-agrup="' + escapar(g.cod_agrup) + '" data-cod="' + escapar(cod) + '">'
            + '<td class="ps-4 text-nowrap"><code>' + escapar(cod) + '</code>' + iconoExcluido(c) + '</td>'
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
            + '<td class="text-center">' + celdaExcluir(c) + '</td>'
            + '</tr>';
    }

    /* ================================================================
       EXCLUIR UN CLIENTE DE COBRANZAS FRANQUICIAS

       Sus facturas salen de las dos solapas de Cobranzas FR y del tablero.
       Excluir pide un motivo -obligatorio, y lo valida de nuevo el servidor-
       y volver a incluir pide confirmación: las dos cosas mueven plata del
       tablero, y ninguna puede dispararse con un clic suelto. El PPP del
       grupo no cambia: mide cómo paga el grupo, no si se le cobra.
       ================================================================ */

    /**
     * "N excluido(s)" en la fila del grupo, para verlo con el grupo contraído.
     * Es un .pc-indicador: no entra en la búsqueda, como "N cliente(s)".
     */
    function indicadorExcluidos(g) {
        var n = g.cant_excluidos || 0;

        return n > 0
            ? ' <span class="pc-badge pc-badge-excluido pc-indicador" title="Clientes del grupo '
                + 'excluidos de Cobranzas Franquicias: sus facturas no se cobran por este circuito">'
                + n + ' excluido(s)</span>'
            : '';
    }

    /** El texto del tooltip de un cliente excluido: motivo, quién y cuándo */
    function textoExcluido(c) {
        var e = c.excluido;

        return 'Excluido de Cobranzas Franquicias: sus facturas no entran en Cobranzas FR ni en '
            + 'el tablero.\nMotivo: ' + (e.motivo || '')
            + '\n' + Auditoria.texto({ alta: { usuario: e.usuario, fecha: e.fecha } });
    }

    function iconoExcluido(c) {
        if (!c.excluido) {
            return '';
        }

        var t = escapar(textoExcluido(c));

        return ' <i class="fas fa-circle-info pc-icono-excluido" title="' + t + '" aria-label="' + t + '"></i>';
    }

    /**
     * La celda Excluir. Con permiso, un switch; sin permiso no se dibuja
     * ningún control, sólo el dato -quien sólo lee tiene que ver que está
     * excluido-. Sin la tabla de exclusiones el switch va deshabilitado y el
     * aviso de arriba dice qué script falta.
     */
    function celdaExcluir(c) {
        var tbody = document.getElementById('tbodyParamCob');
        var excluido = !!c.excluido;
        var lectura = excluido ? '<span class="pc-badge pc-badge-excluido">excluido</span>' : '';

        var title = !exclusionDisponible
            ? 'Todavía no se puede excluir: falta correr el script de la tabla (ver el aviso de arriba)'
            : (excluido ? 'Volver a incluir en Cobranzas Franquicias' : 'Excluir de Cobranzas Franquicias');

        var control = '<div class="form-check form-switch d-inline-block m-0">'
            + '<input class="form-check-input pc-switch-excluir" type="checkbox" role="switch" '
            +     'data-cod="' + escapar(c.cod_cliente) + '"'
            +     (excluido ? ' checked' : '') + (exclusionDisponible ? '' : ' disabled')
            +     ' title="' + escapar(title) + '">'
            + '</div>';

        return Permisos.segun(tbody, control, lectura);
    }

    function conectarExclusion(tbody) {
        tbody.querySelectorAll('.pc-switch-excluir').forEach(function(sw) {
            sw.addEventListener('change', function() {
                var cod = this.getAttribute('data-cod');

                // El switch vuelve a donde estaba hasta que el servidor
                // confirme: si se cancela el diálogo o falla el guardado, no
                // puede quedar diciendo algo que no pasó.
                this.checked = !this.checked;

                if (this.checked) {
                    pedirInclusion(cod);
                } else {
                    pedirExclusion(cod);
                }
            });
        });
    }

    function pedirExclusion(cod) {
        var cli = buscarCliente(cod);
        var nombre = cli ? cod + ' — ' + cli.razon_social : cod;

        Notificacion.pedirTexto({
            titulo: 'Excluir de Cobranzas Franquicias',
            peligro: true,
            mensaje: nombre,
            detalle: 'Todas sus facturas -las emitidas y las que vengan- salen de Real a Cobrar, '
                + 'de Pendientes Proyectados y del tablero, y quedan informadas aparte con este '
                + 'motivo. El PPP de su grupo no cambia.',
            etiqueta: 'Motivo',
            placeholder: 'Ej.: en gestión judicial, refinancia por fuera, se cobra por otro circuito…',
            maxlargo: 200,
            invalido: 'Escribí el motivo: es lo único que después explica por qué falta esa plata '
                + 'en el tablero.',
            confirmar: 'Excluir'
        }).then(function(motivo) {
            if (motivo !== null) {
                guardarExclusion('excluirClienteCobranza', { cod_cliente: cod, motivo: motivo });
            }
        });
    }

    function pedirInclusion(cod) {
        var cli = buscarCliente(cod);
        var motivo = cli && cli.excluido ? cli.excluido.motivo : '';

        Notificacion.confirmar({
            titulo: 'Volver a incluir en Cobranzas Franquicias',
            mensaje: '¿Devolver ' + cod + ' a Cobranzas Franquicias?',
            detalle: 'Sus facturas vuelven a las dos solapas y al tablero.'
                + (motivo ? ' Estaba excluido por: ' + motivo + '.' : '')
                + ' La exclusión no se borra: queda en el historial, dada de baja.',
            confirmar: 'Volver a incluir'
        }).then(function(ok) {
            if (ok) {
                guardarExclusion('incluirClienteCobranza', { cod_cliente: cod });
            }
        });
    }

    /**
     * Guarda y recarga la tarjeta: el servidor dice quién y cuándo, y la
     * cuenta de excluidos del grupo. Los grupos abiertos siguen abiertos: el
     * estado está en memoria.
     */
    function guardarExclusion(accion, cuerpo) {
        fetch('Controller/ParametrosController.php?action=' + accion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                Notificacion.exito(result.message);
                cargarClientes();
            } else {
                Notificacion.error(result.message);
            }
        })
        .catch(err => {
            Notificacion.error('Error de conexión: ' + err.message);
        });
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
