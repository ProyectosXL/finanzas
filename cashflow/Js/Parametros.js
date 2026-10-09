/**
 * Parámetros JavaScript
 * La pestaña de nivel raíz y la sub-pestaña VENTAS: el editor del árbol del
 * mix de cobro y la participación fija de respaldo.
 *
 * Carga el payload de TODOS los módulos —cada sub-pestaña resuelve el suyo con
 * buscarModulo()— y es quien muestra el wrapper y los avisos comunes. Los demás
 * módulos tienen cada uno su propio archivo y su propio JS.
 *
 * LOS PARÁMETROS GENERALES YA NO ESTÁN ACÁ: la alícuota, el horizonte y los
 * feriados se fueron a la sub-pestaña Generales (Js/Parametros-Generales.js).
 * No movían sólo esta pantalla, así que vivir bajo Ventas los describía mal.
 */

(function() {
    'use strict';

    var datos = null;

    // Módulo que se está renderizando. Hoy la pestaña sólo tiene Ventas; cuando
    // se sumen otros módulos, cada sub-pestaña resuelve el suyo con buscarModulo().
    var modulo = null;

    /**
     * Busca un módulo del payload por su código
     * @param {string} codigo Código del módulo (VENTAS, ...)
     */
    function buscarModulo(codigo) {
        if (!datos || !datos.modulos) {
            return null;
        }

        for (var i = 0; i < datos.modulos.length; i++) {
            if (datos.modulos[i].codigo === codigo) {
                return datos.modulos[i];
            }
        }

        return null;
    }

    /** Avisos de configuración pendiente (por ejemplo, una migración sin correr) */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParametros');

        if (!cont) {
            return;
        }

        var avisos = (datos && datos.avisos) || [];

        if (!avisos.length) {
            cont.innerHTML = '';
            return;
        }

        var html = '';

        avisos.forEach(function(a) {
            html += '<div class="alert alert-warning py-2 px-3 mb-2">' +
                    '<i class="fas fa-triangle-exclamation me-1"></i><small>' +
                    escapar(a) + '</small></div>';
        });

        cont.innerHTML = html;
    }

    /** Muestra bajo las sub-pestañas qué afecta el módulo activo */
    function pintarDescripcion() {
        var cont = document.getElementById('descripcionVentas');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + escapar(modulo.descripcion);
        }
    }

    function inicializar() {
        console.log('Inicializando Parámetros');

        var btnRefresh = document.getElementById('btnRefreshParametros');
        var btnGuardarRespaldo = document.getElementById('btnGuardarRespaldo');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargar);
        }

        // El editor del mix: agregar, guardar y los campos de cada nodo se
        // atienden en el tbody, que se redibuja entero en cada carga.
        engancharMix();

        if (btnGuardarRespaldo) {
            btnGuardarRespaldo.addEventListener('click', guardarRespaldo);
        }

        cargar();
    }

    // Ejecutar cuando el DOM esté listo O inmediatamente si ya está listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('loadingParametros');
        mostrar('wrapperParametros', false);

        pedir('Controller/ParametrosController.php?action=getTodo')
            .then(function(data) {
                datos = data;
                // Los parámetros vienen agrupados por módulo: cada sub-pestaña
                // muestra los que afectan a esa pestaña de la aplicación.
                modulo = buscarModulo('VENTAS');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo VENTAS');
                }

                pintarAvisos();
                pintarDescripcion();
                generarMix();
                generarRespaldo();
                Cargando.ocultar('loadingParametros');
                mostrar('wrapperParametros', true);
            })
            .catch(function(error) {
                Cargando.ocultar('loadingParametros');
                mostrarError('Error al cargar los parámetros: ' + error.message);
            });
    }

    /* ================================================================
       GENERALES: YA NO ESTÁN ACÁ

       La alícuota, el horizonte y los feriados de comercio se fueron a la
       sub-pestaña Generales, que tiene su propio archivo y su propio JS
       (Js/Parametros-Generales.js). No movían sólo esta pantalla: el horizonte
       es el eje de todo el módulo.

       Lo que este archivo dibuja es lo que de verdad sólo afecta a Ventas: el
       mix de cobro y la participación fija de respaldo.
       ================================================================ */

    /* ================================================================
       MIX DE COBRO: EL EDITOR DEL ÁRBOL

       Un bloque por canal, con sus nodos en el orden del árbol. Cada nodo
       carga lo suyo —%, costo, tasa, días— y al lado, en gris, lo que la
       regla resuelve para él: el costo + tasa acumulado de su rama y de
       quién hereda los días.

       ESTA PANTALLA NO TIENE LA REGLA. Lo que muestra en gris, las sumas de
       cada grupo de hermanos y si se puede guardar salen del servidor:
       primero del payload (MixCobro::datosEditor()) y, mientras se edita, de
       previsualizarMixArbol, que resuelve el canal como quedaría con la
       MISMA función que usa el motor. Se pide al CAMBIAR de campo y no en
       cada tecla; mientras la respuesta no llega —o mientras hay un campo a
       medio tipear— Guardar queda bloqueado. Si el JS tuviera su copia de la
       regla, algún día diría que "Resto" hereda 7 días y el tablero lo
       acreditaría a otros.

       La validación que manda es la del servidor: saveMixArbol vuelve a
       resolver el árbol entero como quedaría antes de escribir nada.
       ================================================================ */

    /** Por canal: lo último que resolvió el servidor para lo que hay en pantalla */
    var vistaMix = {};

    /** Por canal: si hay un cambio que el servidor todavía no resolvió */
    var mixPendiente = {};

    /** Por canal: si hay cambios sin guardar */
    var mixSucio = {};

    /** Por canal: número del último pedido, para descartar respuestas viejas */
    var mixPedido = {};

    /** Demora entre un cambio y el pedido: junta los cambios de un mismo gesto */
    var mixTimer = {};

    function datosMix() {
        return modulo.mix;
    }

    /** Si se dibujan controles: con permiso y con el árbol ya en su tabla */
    function mixEditable() {
        return !!datosMix().editable && Permisos.puedeEditar('mixBody');
    }

    /** Los nodos de un canal en el orden del árbol, con lo que resolvió la regla */
    function nodosDeCanal(canal) {
        var resueltos = datosMix().resuelto.nodos;
        var crudos = {};

        datosMix().nodos.forEach(function(n) { crudos[n.clave] = n; });

        return Object.keys(resueltos)
            .filter(function(k) { return resueltos[k].canal === canal; })
            .map(function(k) { return { crudo: crudos[k], resuelto: resueltos[k] }; });
    }

    /** La vista inicial de un canal, armada del payload */
    function vistaInicial(canal) {
        var r = datosMix().resuelto;
        var nodos = {};

        Object.keys(r.nodos).forEach(function(k) {
            if (r.nodos[k].canal === canal) {
                nodos[k] = r.nodos[k];
            }
        });

        var errores = (datosMix().estructura || [])
            .filter(function(e) { return e.canal === canal; })
            .map(function(e) { return e.texto; })
            .concat(r.canales[canal].errores.map(function(e) { return e.texto; }));

        return {
            canal: canal,
            valido: errores.length === 0,
            errores: errores,
            nodos: nodos,
            grupos: r.canales[canal].grupos,
            cambios: 0
        };
    }

    function generarMix() {
        var mix = datosMix();
        var editable = mixEditable();
        var html = '';

        vistaMix = {};
        mixPendiente = {};
        mixSucio = {};

        pintarAvisosMix();

        mix.canales.forEach(function(canal) {
            vistaMix[canal] = vistaInicial(canal);
            html += filaCanalMix(canal, editable);
            html += '<tr class="mixa-errores" data-errores="' + canal + '" style="display: none;">' +
                        '<td colspan="8"></td></tr>';

            nodosDeCanal(canal).forEach(function(n) {
                html += filaNodoMix(n.crudo, n.resuelto, editable);
            });
        });

        document.getElementById('mixBody').innerHTML = html;

        mix.canales.forEach(pintarVistaMix);
    }

    /** El aviso de qué script falta, si falta */
    function pintarAvisosMix() {
        var cont = document.getElementById('mixAvisos');

        if (!cont) {
            return;
        }

        cont.innerHTML = (modulo.avisos || []).map(function(a) {
            return '<div class="alert alert-warning py-2 px-3 mb-0">' +
                   '<i class="fas fa-triangle-exclamation me-1"></i><small>' + escapar(a) + '</small></div>';
        }).join('');

        cont.style.display = (modulo.avisos || []).length ? '' : 'none';
    }

    function filaCanalMix(canal, editable) {
        var acciones = '';

        if (editable) {
            acciones = '<button type="button" class="btn btn-sm btn-outline-primary me-2" ' +
                           'data-agregar="" data-canal="' + canal + '" ' +
                           'title="Agregar un nodo al primer nivel de ' + titulo(canal) + '">' +
                           '<i class="fas fa-plus me-1"></i> Agregar</button>' +
                       '<button type="button" class="btn btn-sm btn-primary" ' +
                           'data-guardar-canal="' + canal + '" disabled>' +
                           '<i class="fas fa-floppy-disk me-1"></i> Guardar ' + titulo(canal) + '</button>';
        }

        return '<tr class="mixa-canal" data-canal-fila="' + canal + '">' +
                   '<td colspan="2"><strong>' + titulo(canal) + '</strong> ' +
                       '<span class="mixa-suma" data-suma="' + canal + '"></span></td>' +
                   '<td colspan="6" class="text-end">' +
                       '<small class="text-muted me-2" data-estado="' + canal + '"></small>' + acciones +
                   '</td>' +
               '</tr>';
    }

    /**
     * Una fila de nodo. Con permiso, inputs; sin permiso o sin el script,
     * texto con el valor en data-valor.
     *
     * El camino completo va en un texto que la pantalla no muestra
     * (visually-hidden): la exportación lo baja, así cada fila del Excel dice
     * de qué rama es.
     */
    function filaNodoMix(n, r, editable) {
        var camino = titulo(n.CANAL) + ' › ' +
            r.camino.slice(0, -1).map(function(c) { return c.nombre; }).concat(['']).join(' › ');
        var sangria = '<span class="mixa-sangria" style="width: ' + (r.profundidad * 20) + 'px"></span>';
        var pct = function(v) { return (v === null || v === undefined) ? '' : (v * 100).toFixed(2); };
        var html = '<tr class="mixa-nodo" data-id="' + n.ID + '" data-clave="' + n.clave + '" ' +
                   'data-canal="' + n.CANAL + '" data-padre="' + (r.padre || '') + '">';

        // ---- Nombre ---------------------------------------------------
        html += '<td class="mixa-nombre"><span class="visually-hidden">' + escapar(camino) + '</span>' + sangria;

        if (editable) {
            html += '<input type="text" class="form-control form-control-sm d-inline-block mixa-campo" ' +
                    'data-campo="nombre" maxlength="50" value="' + escapar(n.NOMBRE) + '">';
        } else {
            html += escapar(n.NOMBRE);
        }

        if (n.tiene_hijos) {
            html += ' <span class="mixa-suma" data-suma="' + n.clave + '"></span>';
        }

        if (editable && n.niveles_hijo.length) {
            html += ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 mixa-mas" ' +
                    'data-agregar="' + n.ID + '" data-canal="' + n.CANAL + '" ' +
                    'title="Agregar un nodo debajo de ' + escapar(n.NOMBRE) + '">' +
                    '<i class="fas fa-plus"></i></button>';
        }

        html += '</td>';

        // ---- Nivel ----------------------------------------------------
        // Con hijos no se cambia: sus hijos quedarían con un nivel que quizás
        // ya no es posterior. Si quedó mal, se inhabilita y se crea otro.
        if (editable) {
            var opciones = n.niveles_permitidos.indexOf(n.NIVEL) === -1
                ? [n.NIVEL].concat(n.niveles_permitidos) : n.niveles_permitidos;

            html += '<td><select class="form-select form-select-sm mixa-campo" data-campo="nivel"' +
                    (n.tiene_hijos ? ' disabled title="Tiene nodos debajo: su nivel no se cambia"' : '') + '>' +
                    opciones.map(function(cod) {
                        return '<option value="' + cod + '"' + (cod === n.NIVEL ? ' selected' : '') + '>' +
                               escapar(rotuloNivel(cod)) + '</option>';
                    }).join('') + '</select></td>';
        } else {
            html += '<td>' + escapar(rotuloNivel(n.NIVEL)) + '</td>';
        }

        // ---- %, costo, tasa y días --------------------------------------
        html += celdaNumeroMix('porcentaje', pct(n.PORCENTAJE), '%', editable, '0.01', '');
        html += celdaNumeroMix('costo', pct(n.COSTO), '%', editable, '0.01',
                               '<div class="mixa-hereda" data-hint-carga="' + n.clave + '"></div>');
        html += celdaNumeroMix('tasa', pct(n.TASA), '%', editable, '0.01', '');
        html += celdaNumeroMix('dias', n.DIAS_ACREDITACION === null ? '' : String(n.DIAS_ACREDITACION),
                               'días', editable, '1',
                               '<div class="mixa-hereda" data-hint-dias="' + n.clave + '"></div>');

        // ---- Activo ---------------------------------------------------
        var activo = parseInt(n.ACTIVO, 10) === 1;

        html += '<td class="text-center">' + (editable
            ? '<div class="form-check form-switch d-inline-block">' +
                '<input class="form-check-input mixa-campo" type="checkbox" role="switch" ' +
                    'data-campo="activo"' + (activo ? ' checked' : '') +
                    ' title="Inhabilitado, el nodo y todo lo que cuelga de él salen de la proyección">' +
              '</div>'
            : (activo ? 'Sí' : 'No')) + '</td>';

        // ---- Última edición -------------------------------------------
        html += '<td><small class="text-muted">' +
                escapar(Auditoria.linea('modif', n.USUARIO_MODIF, n.FECHA_MODIF)) + '</small></td>';

        return html + '</tr>';
    }

    function celdaNumeroMix(campo, valor, unidad, editable, paso, debajo) {
        if (!editable) {
            return '<td class="text-end">' + (valor === '' ? '<span class="text-muted">—</span>'
                : escapar(valor.replace('.', ',')) + (unidad === '%' ? '%'
                    : ' ' + (valor === '1' ? unidad.replace(/s$/, '') : unidad))) + debajo + '</td>';
        }

        return '<td>' +
                   '<div class="input-group input-group-sm">' +
                       '<input type="number" step="' + paso + '" min="0" class="form-control mixa-campo mix-input" ' +
                           'data-campo="' + campo + '" value="' + valor + '">' +
                       '<span class="input-group-text">' + unidad + '</span>' +
                   '</div>' + debajo +
               '</td>';
    }

    function rotuloNivel(codigo) {
        var n = datosMix().niveles.filter(function(x) { return x.codigo === codigo; })[0];

        return n ? n.rotulo : codigo;
    }

    /**
     * Pinta lo que resolvió el servidor para un canal: los valores heredados
     * en gris, la suma de cada grupo de hermanos, los nodos fuera de juego,
     * los errores y el estado de Guardar.
     */
    function pintarVistaMix(canal) {
        var v = vistaMix[canal];
        var cuerpo = document.getElementById('mixBody');

        Object.keys(v.nodos).forEach(function(clave) {
            var r = v.nodos[clave];
            var tr = cuerpo.querySelector('tr[data-clave="' + clave + '"]');

            if (!tr) { return; }

            // Fuera de juego: inactivo él o algún nodo de arriba
            tr.classList.toggle('mix-inactivo', !r.en_juego);

            var carga = cuerpo.querySelector('[data-hint-carga="' + clave + '"]');

            if (carga) {
                carga.textContent = r.carga > 0
                    ? 'rama: ' + formatPercent(r.carga) +
                      (r.tasa > 0 ? ' (costo ' + formatPercent(r.costo) + ' + tasa ' + formatPercent(r.tasa) + ')' : '')
                    : 'rama: sin costo';
            }

            var dias = cuerpo.querySelector('[data-hint-dias="' + clave + '"]');

            if (dias) {
                dias.classList.remove('mixa-falta');

                if (r.dias_propios !== null) {
                    dias.textContent = '';
                } else if (r.dias !== null) {
                    dias.textContent = 'hereda ' + r.dias + ' de ' + r.dias_de.nombre;
                } else {
                    dias.textContent = r.hoja ? 'sin días en toda la rama' : '';
                    dias.classList.toggle('mixa-falta', r.hoja);
                }
            }
        });

        // ---- Las sumas de cada grupo ---------------------------------------
        v.grupos.forEach(function(g) {
            var span = cuerpo.querySelector('[data-suma="' + (g.padre || canal) + '"]');

            if (!span) { return; }

            span.textContent = 'Σ ' + formatPercent(g.suma);
            span.className = 'mixa-suma ' + (!g.en_juego ? 'mixa-suma-fuera'
                : (g.activos === 0 ? 'mixa-suma-vacia' : (g.valido ? 'mixa-suma-ok' : 'mixa-suma-mal')));
            span.title = !g.en_juego
                ? 'Esta rama está fuera de juego: su suma no se valida'
                : (g.activos === 0
                    ? 'Sin nodos activos debajo: ' + (g.padre ? 'este nodo es una hoja' : 'el canal no cobra')
                    : (g.valido ? 'Los activos suman 100%' : 'Los activos de ' + g.rama + ' tienen que sumar 100%'));
        });

        // ---- Errores --------------------------------------------------------
        var filaErr = cuerpo.querySelector('[data-errores="' + canal + '"]');

        if (filaErr) {
            filaErr.style.display = v.errores.length ? '' : 'none';
            filaErr.cells[0].innerHTML = v.errores.length
                ? '<div class="alert alert-danger py-2 px-3 mb-0"><small>' +
                  v.errores.map(function(e) {
                      return '<div><i class="fas fa-circle-exclamation me-1"></i>' + escapar(e) + '</div>';
                  }).join('') + '</small></div>'
                : '';
        }

        actualizarGuardarMix(canal);
    }

    /** Guardar se habilita sólo con todo resuelto, válido y algo que guardar */
    function actualizarGuardarMix(canal) {
        var btn = document.querySelector('[data-guardar-canal="' + canal + '"]');
        var estado = document.querySelector('[data-estado="' + canal + '"]');
        var v = vistaMix[canal];
        var motivo = '';

        if (mixPendiente[canal]) {
            motivo = 'Revisando los cambios…';
        } else if (!v.valido) {
            motivo = 'No se puede guardar: ' + v.errores.length + ' problema(s), abajo.';
        } else if (!mixSucio[canal]) {
            motivo = '';
        }

        if (estado) {
            estado.textContent = motivo || (mixSucio[canal] ? 'Hay cambios sin guardar.' : '');
        }

        if (btn) {
            btn.disabled = !!mixPendiente[canal] || !v.valido || !mixSucio[canal];
            btn.title = motivo || (mixSucio[canal] ? '' : 'No hay cambios');
        }
    }

    /** Lo que hay en pantalla para un canal, como lo espera el servidor */
    function nodosEnPantalla(canal) {
        var lista = [];

        document.querySelectorAll('#mixBody tr.mixa-nodo[data-canal="' + canal + '"]').forEach(function(tr) {
            var leer = function(campo) {
                var el = tr.querySelector('[data-campo="' + campo + '"]');

                return el ? (el.type === 'checkbox' ? el.checked : el.value) : undefined;
            };
            // Los porcentajes se tipean en %, y el servidor los guarda en fracción.
            // Vacío viaja vacío: para el costo, la tasa y los días es "null".
            var fraccion = function(v) {
                return (v === '' || v === undefined) ? v : (parseFloat(v) / 100);
            };

            lista.push({
                id: parseInt(tr.dataset.id, 10),
                nombre: leer('nombre'),
                nivel: leer('nivel'),
                porcentaje: fraccion(leer('porcentaje')),
                costo: fraccion(leer('costo')),
                tasa: fraccion(leer('tasa')),
                dias: leer('dias'),
                activo: leer('activo')
            });
        });

        return lista;
    }

    /** Pide al servidor cómo quedaría el canal. Las respuestas viejas se descartan */
    function previsualizarMix(canal) {
        clearTimeout(mixTimer[canal]);
        mixPendiente[canal] = true;
        actualizarGuardarMix(canal);

        mixTimer[canal] = setTimeout(function() {
            var numero = (mixPedido[canal] || 0) + 1;

            mixPedido[canal] = numero;

            pedir('Controller/ParametrosController.php?action=previsualizarMixArbol',
                  { canal: canal, nodos: nodosEnPantalla(canal) })
                .then(function(vista) {
                    if (mixPedido[canal] !== numero) { return; }

                    vistaMix[canal] = vista;
                    mixPendiente[canal] = false;
                    pintarVistaMix(canal);
                })
                .catch(function(error) {
                    if (mixPedido[canal] !== numero) { return; }

                    // Sin respuesta no se sabe si el árbol es válido: Guardar
                    // sigue bloqueado y se dice por qué.
                    var previa = vistaMix[canal];

                    vistaMix[canal] = {
                        canal: canal, nodos: previa.nodos, grupos: previa.grupos, cambios: 0,
                        valido: false,
                        errores: ['No se pudo revisar el árbol: ' + error.message]
                    };
                    mixPendiente[canal] = false;
                    pintarVistaMix(canal);
                });
        }, 250);
    }

    /**
     * Inhabilitar un nodo con ramas debajo saca de la proyección todas sus
     * hojas: se pregunta antes, diciendo cuántas.
     */
    function confirmarInhabilitar(chk, canal) {
        var tr = chk.closest('tr');
        var clave = tr.dataset.clave;
        var v = vistaMix[canal];
        var hojas = Object.keys(v.nodos).filter(function(k) {
            var n = v.nodos[k];

            return n.hoja && k !== clave && n.camino.some(function(c) { return c.clave === clave; });
        }).length;

        if (hojas === 0) {
            return Promise.resolve(true);
        }

        var nombre = tr.querySelector('[data-campo="nombre"]').value;

        return Notificacion.confirmar({
            titulo: 'Inhabilitar ' + nombre,
            mensaje: '¿Inhabilitar "' + nombre + '" y todo lo que cuelga de él?',
            detalle: 'Saca ' + hojas + ' hoja(s) de la proyección. No se borra nada: se puede '
                   + 'volver a habilitar. Después hay que reacomodar los porcentajes de su grupo '
                   + 'para que vuelvan a sumar 100%.',
            confirmar: 'Inhabilitar',
            peligro: true
        });
    }

    function alCambiarMix(e) {
        var el = e.target.closest('.mixa-campo');

        if (!el) { return; }

        var canal = el.closest('tr').dataset.canal;

        mixSucio[canal] = true;

        if (el.dataset.campo === 'activo' && !el.checked) {
            confirmarInhabilitar(el, canal).then(function(ok) {
                if (!ok) {
                    el.checked = true;
                }

                previsualizarMix(canal);
            });

            return;
        }

        previsualizarMix(canal);
    }

    /** Mientras se tipea, Guardar se bloquea: el valor todavía no se revisó */
    function alTipearMix(e) {
        var el = e.target.closest('.mixa-campo');

        if (!el || el.type === 'checkbox' || el.tagName === 'SELECT') { return; }

        var canal = el.closest('tr').dataset.canal;

        mixSucio[canal] = true;
        mixPendiente[canal] = true;
        actualizarGuardarMix(canal);
    }

    function guardarMixCanal(canal) {
        if (mixPendiente[canal] || !vistaMix[canal].valido) {
            return;
        }

        var btn = document.querySelector('[data-guardar-canal="' + canal + '"]');
        var textoOriginal = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        pedir('Controller/ParametrosController.php?action=saveMixArbol',
              { canal: canal, nodos: nodosEnPantalla(canal) })
            .then(function() {
                Notificacion.exito('Mix de ' + titulo(canal) + ' guardado.');
                cargar();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo guardar el mix: ' + error.message);
                btn.innerHTML = textoOriginal;
                actualizarGuardarMix(canal);
            });
    }

    /* ---- Alta -------------------------------------------------------- */

    /**
     * El formulario de alta, en una fila debajo del padre (o del canal).
     * No pide porcentaje: lo nuevo entra INHABILITADO Y EN 0%, así no rompe
     * el 100% de su grupo. Los niveles que ofrece son los que el servidor dice
     * que admite ese lugar.
     */
    function abrirAltaMix(btn) {
        var canal = btn.dataset.canal;
        var idPadre = btn.dataset.agregar;
        var mix = datosMix();
        var padre = idPadre ? mix.nodos.filter(function(n) { return String(n.ID) === idPadre; })[0] : null;
        var niveles = padre ? padre.niveles_hijo : mix.niveles_primer[canal];
        var ancla = btn.closest('tr');

        cerrarAltaMix();

        var tr = document.createElement('tr');

        tr.className = 'mixa-alta';
        tr.dataset.canal = canal;
        tr.innerHTML =
            '<td colspan="8">' +
                '<div class="mixa-alta-titulo">Agregar ' +
                    (padre ? 'debajo de <strong>' + escapar(padre.NOMBRE) + '</strong>'
                           : 'al primer nivel de <strong>' + titulo(canal) + '</strong>') + '</div>' +
                '<div class="row g-2 align-items-end">' +
                    '<div class="col-md-3"><label class="form-label form-label-sm">Nombre</label>' +
                        '<input type="text" class="form-control form-control-sm" data-alta="nombre" maxlength="50"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm">Nivel</label>' +
                        '<select class="form-select form-select-sm" data-alta="nivel">' +
                        niveles.map(function(cod) {
                            return '<option value="' + cod + '">' + escapar(rotuloNivel(cod)) + '</option>';
                        }).join('') + '</select></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm">Costo %</label>' +
                        '<input type="number" step="0.01" min="0" class="form-control form-control-sm" data-alta="costo"></div>' +
                    '<div class="col-md-1"><label class="form-label form-label-sm">Tasa %</label>' +
                        '<input type="number" step="0.01" min="0" class="form-control form-control-sm" data-alta="tasa"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm">Días</label>' +
                        '<input type="number" step="1" min="0" class="form-control form-control-sm" data-alta="dias" ' +
                        'placeholder="vacío: los de arriba"></div>' +
                    '<div class="col-md-2 d-flex gap-2">' +
                        '<button type="button" class="btn btn-sm btn-primary flex-fill" data-alta-confirmar>' +
                            '<i class="fas fa-check me-1"></i> Agregar</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-alta-cancelar>' +
                            '<i class="fas fa-xmark"></i></button>' +
                    '</div>' +
                '</div>' +
                '<div class="param-hint mt-2">Entra <strong>inhabilitado y en 0%</strong>, así no rompe ' +
                    'el 100% de su grupo. Para usarlo, encendelo y reacomodá los porcentajes de sus hermanos.</div>' +
            '</td>';

        tr.dataset.idPadre = idPadre || '';
        ancla.parentNode.insertBefore(tr, ancla.nextSibling);
        tr.querySelector('[data-alta="nombre"]').focus();
    }

    function cerrarAltaMix() {
        document.querySelectorAll('#mixBody tr.mixa-alta').forEach(function(tr) { tr.remove(); });
    }

    function confirmarAltaMix(tr) {
        var canal = tr.dataset.canal;
        var valor = function(c) { return tr.querySelector('[data-alta="' + c + '"]').value; };
        var fraccion = function(v) { return v === '' ? '' : parseFloat(v) / 100; };
        var nombre = valor('nombre').trim();

        if (!nombre) {
            Notificacion.campoInvalido(tr.querySelector('[data-alta="nombre"]'), 'Ingresá el nombre.');
            return;
        }

        // El alta recarga el mix: lo que se editó y no se guardó se perdería.
        var seguir = mixSucio[canal]
            ? Notificacion.confirmar({
                mensaje: 'Hay cambios sin guardar en ' + titulo(canal) + '.',
                detalle: 'Agregar recarga el mix y esos cambios se pierden. Guardalos antes si los querés.',
                confirmar: 'Agregar igual'
              })
            : Promise.resolve(true);

        seguir.then(function(ok) {
            if (!ok) { return; }

            var btn = tr.querySelector('[data-alta-confirmar]');

            btn.disabled = true;

            pedir('Controller/ParametrosController.php?action=addMixNodo', {
                canal: canal,
                id_padre: tr.dataset.idPadre || null,
                nombre: nombre,
                nivel: valor('nivel'),
                costo: fraccion(valor('costo')),
                tasa: fraccion(valor('tasa')),
                dias: valor('dias')
            })
            .then(function() {
                Notificacion.exito('"' + nombre + '" agregado a ' + titulo(canal) + '.', {
                    detalle: 'Queda inhabilitado y en 0%. Encendelo y reacomodá los porcentajes de su '
                           + 'grupo para que vuelvan a sumar 100%.'
                });
                cargar();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo agregar: ' + error.message);
                btn.disabled = false;
            });
        });
    }

    /** Un solo juego de listeners en el tbody: el editor se redibuja entero al recargar */
    function engancharMix() {
        var cuerpo = document.getElementById('mixBody');

        if (!cuerpo) { return; }

        cuerpo.addEventListener('change', alCambiarMix);
        cuerpo.addEventListener('input', alTipearMix);
        cuerpo.addEventListener('click', function(e) {
            var agregar = e.target.closest('[data-agregar]');
            var guardar = e.target.closest('[data-guardar-canal]');

            if (agregar) {
                abrirAltaMix(agregar);
            } else if (guardar) {
                guardarMixCanal(guardar.dataset.guardarCanal);
            } else if (e.target.closest('[data-alta-cancelar]')) {
                cerrarAltaMix();
            } else if (e.target.closest('[data-alta-confirmar]')) {
                confirmarAltaMix(e.target.closest('tr.mixa-alta'));
            }
        });
    }

    /* ================================================================
       PARTICIPACIÓN DE RESPALDO
       ================================================================ */

    function generarRespaldo() {
        var html = '';

        modulo.respaldo.forEach(function(param) {
            var canal = param.CLAVE.replace('respaldo_', '');

            html += '<div class="col-md-6 col-lg-3">' +
                        '<div class="param-card">' +
                            '<div class="param-clave">' + titulo(canal) +
                                Auditoria.icono({ usuario: param.USUARIO, fecha: param.FECHA_UPDATE }) + '</div>' +
                            '<div class="param-descripcion">' + escapar(param.DESCRIPCION || '') + '</div>' +
                            Permisos.segun('gridRespaldo',
                                '<div class="input-group input-group-sm">' +
                                    '<input type="number" step="0.01" class="form-control param-input respaldo-input" ' +
                                        'data-clave="' + param.CLAVE + '" ' +
                                        'value="' + (parseFloat(param.VALOR) * 100).toFixed(2) + '">' +
                                    '<span class="input-group-text">%</span>' +
                                '</div>',
                                '<span class="respaldo-input fw-semibold" data-clave="' + param.CLAVE + '" ' +
                                    'data-valor="' + (parseFloat(param.VALOR) * 100).toFixed(2) + '">' +
                                    formatPercent(parseFloat(param.VALOR)) + '</span>') +
                        '</div>' +
                    '</div>';
        });

        var gridRespaldo = document.getElementById('gridRespaldo');

        if (!gridRespaldo) {
            return;
        }

        gridRespaldo.innerHTML = html;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            input.addEventListener('input', validarRespaldo);
        });

        validarRespaldo();
    }

    function validarRespaldo() {
        var suma = 0;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            suma += (parseFloat(valorDe(input)) || 0) / 100;
        });

        var span = document.getElementById('sumaRespaldo');
        var valido = Math.abs(suma - 1) < 0.000001;

        span.textContent = formatPercent(suma);
        span.classList.toggle('suma-ok', valido);
        span.classList.toggle('suma-error', !valido);

        if (!valido) {
            var desvio = (suma - 1) * 100;
            span.textContent = formatPercent(suma) +
                ' (' + (desvio > 0 ? '+' : '') + desvio.toFixed(2) + ')';
        }

        var btnGuardarRespaldo = document.getElementById('btnGuardarRespaldo');

        if (btnGuardarRespaldo) {
            btnGuardarRespaldo.disabled = !valido;
        }

        return valido;
    }

    function guardarRespaldo() {
        if (!validarRespaldo()) {
            return;
        }

        var valores = {};

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            valores[input.dataset.clave] = (parseFloat(input.value) || 0) / 100;
        });

        var btn = document.getElementById('btnGuardarRespaldo');
        var textoOriginal = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        pedir('Controller/ParametrosController.php?action=saveRespaldo', { valores: valores })
            .then(function() {
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Guardado';
                setTimeout(function() {
                    btn.innerHTML = textoOriginal;
                    cargar();
                }, 900);
            })
            .catch(function(error) {
                Notificacion.error('No se pudieron guardar las participaciones de respaldo: '
                    + error.message);
                btn.disabled = false;
                btn.innerHTML = textoOriginal;
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function pedir(url, body) {
        var opciones = {};

        if (body) {
            opciones = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            };
        }

        return fetch(url, opciones)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }

                return response.text();
            })
            .then(function(text) {
                var result;

                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Respuesta no JSON:', text);
                    throw new Error('Respuesta inválida del servidor. Revisá la consola.');
                }

                if (!result.success) {
                    console.error('Error del servidor:', result);
                    throw new Error(result.message || 'Error desconocido');
                }

                return result.data;
            });
    }

    /**
     * El valor de un campo del formulario: el del input o, sin permiso de
     * edicion, el que la celda de texto trae en data-valor.
     */
    function valorDe(el) {
        return (el.tagName === 'INPUT') ? el.value : el.dataset.valor;
    }


    function formatPercent(value) {
        var num = (parseFloat(value) || 0) * 100;

        return num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 4
        }) + '%';
    }

    /**
     * LOCALES -> Locales. Lo usa el mix para los CANALES; los nombres de los
     * nodos se muestran tal cual se guardaron: son texto libre ("3 cuotas").
     */
    function titulo(texto) {
        return String(texto).toLowerCase().replace(/(^|\s)\S/g, function(c) {
            return c.toUpperCase();
        });
    }

    function escapar(texto) {
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function mostrarError(mensaje) {
        Notificacion.error(mensaje);
    }

})(); // Fin del IIFE
