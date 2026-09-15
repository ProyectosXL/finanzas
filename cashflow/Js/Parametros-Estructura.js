/**
 * Parámetros - Estructura del Cashflow
 *
 * Editor de las secciones y filas del tablero. Es lo que hace que agregar,
 * renombrar, reordenar o inhabilitar una fila sea un cambio de datos y no de
 * código.
 *
 * VIVE APARTE DE Parametros.js A PROPÓSITO. Ese archivo tiene 730 líneas, todos
 * sus ids del DOM son globales y sus selectores de colección recorren TODO el
 * documento (validarMix suma sobre todos los .mix-porcentaje de la página, no
 * sobre los de su panel). Meter este editor ahí adentro los haría pisarse en
 * silencio. Acá el estado es propio y las clases llevan prefijo cfe-.
 *
 * CARGA PEREZOSA: la estructura se pide recién al abrir la sub-pestaña. Si se
 * pidiera junto con el resto, un problema leyendo la estructura se llevaría
 * puesta también la pestaña de Ventas.
 *
 * EL ORDEN NO SE MANDA. Los botones ↑ y ↓ mueven la fila en la pantalla y el
 * servidor renumera desde cero al guardar. Así el orden se repara solo y no
 * existen los órdenes duplicados ni los huecos.
 */

(function() {
    'use strict';

    var URL_BASE = 'Controller/CashflowEstructuraController.php';

    var datos = null;        // lo último que devolvió el servidor
    var secciones = [];      // estado editable
    var filas = [];          // estado editable
    var cargado = false;
    var verInactivas = true;

    function inicializar() {
        var boton = document.getElementById('tabParamCashflowBtn');

        if (!boton) {
            return;   // la sub-pestaña no está en esta página
        }

        boton.addEventListener('shown.bs.tab', function() {
            if (!cargado) {
                cargar();
            }
        });

        // Si la sub-pestaña ya estuviera activa al entrar (no pasa hoy, pero
        // pasaría si Cashflow quedara primero en el orden de módulos)
        if (boton.classList.contains('active')) {
            cargar();
        }
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
        mostrar('cfeLoading', true);
        mostrar('cfeWrapper', false);

        pedirJson(URL_BASE + '?action=getEstructura')
            .then(function(data) {
                datos = data;
                cargado = true;

                // Copias editables: así "cancelar" es recargar y no hay que
                // deshacer nada a mano.
                secciones = data.secciones.map(clonarSeccion);
                filas = data.filas.map(clonarFila);

                // El servidor ya las manda agrupadas, pero se reagrupa igual:
                // es lo que mantiene el invariante después de cada cambio de
                // sección, y así el encabezado de grupo nunca se repite.
                reordenarPorSeccion();

                pintarTodo();

                mostrar('cfeLoading', false);
                mostrar('cfeWrapper', true);
            })
            .catch(function(error) {
                mostrar('cfeLoading', false);
                avisoError('No se pudo cargar la estructura: ' + error.message);
            });
    }

    function clonarSeccion(s) {
        return {
            codigo: s.CODIGO,
            nombre: s.NOMBRE,
            rol: s.ROL,
            id_padre: s.ID_PADRE,
            activo: Number(s.ACTIVO) === 1
        };
    }

    function clonarFila(f) {
        return {
            id: Number(f.ID),
            codigo: f.CODIGO,
            nombre: f.NOMBRE,
            seccion: f.SECCION,
            tipo: f.TIPO,
            computa: Number(f.COMPUTA) === 1,
            origen_provider: f.ORIGEN_PROVIDER || '',
            origen_serie: f.ORIGEN_SERIE || '',
            activo: Number(f.ACTIVO) === 1
        };
    }

    function pintarTodo() {
        texto('cfeDescripcion', descripcionModulo());

        pintarAvisos();
        pintarSecciones();
        pintarFilas();
        pintarProviders();
        pintarSelectoresAlta();
        conectarAlta();
        validar();
    }

    function descripcionModulo() {
        return 'Definen qué secciones y qué filas tiene el tablero de Cashflow, '
            + 'y de qué módulo saca sus datos cada fila.';
    }

    function pintarAvisos() {
        var cont = document.getElementById('cfeAvisos');

        if (!cont) {
            return;
        }

        if (!datos.avisos || datos.avisos.length === 0) {
            cont.innerHTML = '';
            return;
        }

        cont.innerHTML = datos.avisos.map(function(a) {
            return '<div class="alert alert-warning py-2 px-3">'
                + '<i class="fas fa-triangle-exclamation me-2"></i>' + escapar(a) + '</div>';
        }).join('');
    }

    /* ================================================================
       FILAS
       ================================================================ */

    function pintarFilas() {
        var cuerpo = document.getElementById('cfeFilas');

        if (!cuerpo) {
            return;
        }

        var visibles = filas.filter(function(f) { return verInactivas || f.activo; });
        var seccionAnterior = null;
        var html = '';

        visibles.forEach(function(f) {
            if (f.seccion !== seccionAnterior) {
                seccionAnterior = f.seccion;
                html += '<tr class="cfe-grupo"><td colspan="8">'
                    + escapar(nombreSeccion(f.seccion)) + '</td></tr>';
            }

            html += filaHtml(f);
        });

        if (html === '') {
            html = '<tr><td colspan="8" class="text-center text-muted py-4">'
                + 'No hay filas configuradas.</td></tr>';
        }

        cuerpo.innerHTML = html;
        conectarFilas();
    }

    function filaHtml(f) {
        var derivada = datos.tipos_derivados.indexOf(f.tipo) !== -1;
        var idx = filas.indexOf(f);

        return '<tr class="cfe-fila' + (f.activo ? '' : ' cfe-inactiva') + '" data-id="' + f.id + '">'
            + '<td class="cfe-orden">'
                + botonMover(idx, -1, '&uarr;') + botonMover(idx, 1, '&darr;')
            + '</td>'
            + '<td><input type="text" class="form-control form-control-sm cfe-nombre" '
                + 'maxlength="80" value="' + escapar(f.nombre) + '"></td>'
            + '<td><code class="cfe-codigo">' + escapar(f.codigo) + '</code></td>'
            + '<td>' + selectSeccion(f) + '</td>'
            + '<td>' + selectTipo(f) + '</td>'
            + '<td>' + selectOrigen(f, derivada) + '</td>'
            + '<td class="text-center">' + checkComputa(f, derivada) + '</td>'
            + '<td class="text-center">'
                + '<div class="form-check form-switch d-inline-block">'
                + '<input class="form-check-input cfe-activo" type="checkbox"'
                + (f.activo ? ' checked' : '') + '></div>'
            + '</td>'
            + '</tr>';
    }

    function botonMover(idx, delta, simbolo) {
        return '<button type="button" class="btn btn-link btn-sm p-0 cfe-mover" '
            + 'data-idx="' + idx + '" data-delta="' + delta + '">' + simbolo + '</button>';
    }

    function selectSeccion(f) {
        var opciones = secciones.map(function(s) {
            return '<option value="' + escapar(s.codigo) + '"'
                + (s.codigo === f.seccion ? ' selected' : '') + '>'
                + escapar(s.nombre) + (s.activo ? '' : ' (inhabilitada)') + '</option>';
        }).join('');

        return '<select class="form-select form-select-sm cfe-seccion">' + opciones + '</select>';
    }

    function selectTipo(f) {
        var opciones = datos.tipos.map(function(t) {
            return '<option value="' + t + '"' + (t === f.tipo ? ' selected' : '') + '>'
                + rotuloTipo(t) + '</option>';
        }).join('');

        return '<select class="form-select form-select-sm cfe-tipo">' + opciones + '</select>';
    }

    /**
     * Dos desplegables encadenados: módulo y después serie. Se arman desde el
     * registro que manda el servidor, así que registrar un proveedor nuevo
     * alcanza para que aparezca acá.
     */
    function selectOrigen(f, derivada) {
        if (derivada) {
            return '<span class="text-muted small">'
                + '<i class="fas fa-calculator me-1"></i>La calcula el sistema</span>';
        }

        var opcProv = ['<option value="">— elegir módulo —</option>'].concat(
            datos.providers.map(function(p) {
                return '<option value="' + escapar(p.codigo) + '"'
                    + (p.codigo === f.origen_provider ? ' selected' : '') + '>'
                    + escapar(p.nombre) + (p.disponible ? '' : ' (sin construir)') + '</option>';
            })
        ).join('');

        var prov = buscarProvider(f.origen_provider);
        var opcSerie = ['<option value="">— serie —</option>'];

        if (prov) {
            Object.keys(prov.series).forEach(function(cod) {
                opcSerie.push('<option value="' + escapar(cod) + '"'
                    + (cod === f.origen_serie ? ' selected' : '') + '>'
                    + escapar(prov.series[cod]) + '</option>');
            });
        }

        return '<div class="d-flex gap-1">'
            + '<select class="form-select form-select-sm cfe-provider">' + opcProv + '</select>'
            + '<select class="form-select form-select-sm cfe-serie"'
                + (prov ? '' : ' disabled') + '>' + opcSerie.join('') + '</select>'
            + '</div>';
    }

    function checkComputa(f, derivada) {
        if (derivada) {
            return '<span class="text-muted">—</span>';
        }

        return '<input class="form-check-input cfe-computa" type="checkbox"'
            + (f.computa ? ' checked' : '')
            + ' title="Si se destilda, la fila se muestra pero no entra en ninguna suma">';
    }

    /**
     * Se relee TODO desde el DOM y se repinta. Con esta cantidad de filas es
     * instantáneo, y evita la clase de bug en que el estado y la pantalla se
     * separan.
     */
    function conectarFilas() {
        var cuerpo = document.getElementById('cfeFilas');

        if (!cuerpo) {
            return;
        }

        cuerpo.querySelectorAll('.cfe-mover').forEach(function(b) {
            b.addEventListener('click', function() {
                mover(Number(b.dataset.idx), Number(b.dataset.delta));
            });
        });

        cuerpo.querySelectorAll('tr.cfe-fila').forEach(function(tr) {
            var f = buscarFila(Number(tr.dataset.id));

            if (!f) {
                return;
            }

            enlazar(tr, '.cfe-nombre', 'input', function(el) { f.nombre = el.value; }, false);
            enlazar(tr, '.cfe-seccion', 'change', function(el) { f.seccion = el.value; }, true);
            enlazar(tr, '.cfe-tipo', 'change', function(el) { f.tipo = el.value; }, true);
            enlazar(tr, '.cfe-provider', 'change', function(el) {
                f.origen_provider = el.value;
                f.origen_serie = '';   // cambió el módulo: la serie anterior ya no aplica
            }, true);
            enlazar(tr, '.cfe-serie', 'change', function(el) { f.origen_serie = el.value; }, false);
            enlazar(tr, '.cfe-computa', 'change', function(el) { f.computa = el.checked; }, false);
            enlazar(tr, '.cfe-activo', 'change', function(el) { f.activo = el.checked; }, true);
        });
    }

    function enlazar(raiz, selector, evento, aplicar, repintar) {
        var el = raiz.querySelector(selector);

        if (!el) {
            return;
        }

        el.addEventListener(evento, function() {
            aplicar(el);

            if (repintar) {
                reordenarPorSeccion();
                pintarFilas();
            }

            validar();
        });
    }

    function mover(idx, delta) {
        var f = filas[idx];

        if (!f) {
            return;
        }

        // Sólo se mueve dentro de la misma sección: cambiar de sección es tarea
        // del desplegable, no de las flechas.
        var hermanas = [];

        filas.forEach(function(x, i) {
            if (x.seccion === f.seccion) {
                hermanas.push(i);
            }
        });

        var pos = hermanas.indexOf(idx);
        var destino = pos + delta;

        if (destino < 0 || destino >= hermanas.length) {
            return;
        }

        var a = hermanas[pos];
        var b = hermanas[destino];
        var tmp = filas[a];

        filas[a] = filas[b];
        filas[b] = tmp;

        pintarFilas();
        validar();
    }

    /** Mantiene las filas agrupadas por sección, en el orden de las secciones */
    function reordenarPorSeccion() {
        var pos = {};

        secciones.forEach(function(s, i) { pos[s.codigo] = i; });

        filas.sort(function(a, b) {
            var pa = pos[a.seccion] === undefined ? 999 : pos[a.seccion];
            var pb = pos[b.seccion] === undefined ? 999 : pos[b.seccion];

            return pa - pb;   // sort estable: no altera el orden dentro de la sección
        });
    }

    /* ================================================================
       SECCIONES
       ================================================================ */

    function pintarSecciones() {
        var cuerpo = document.getElementById('cfeSecciones');

        if (!cuerpo) {
            return;
        }

        cuerpo.innerHTML = secciones.map(function(s, i) {
            var padres = ['<option value="">— ninguna —</option>'].concat(
                secciones.filter(function(o) { return o.codigo !== s.codigo; })
                    .map(function(o) {
                        return '<option value="' + escapar(o.codigo) + '"'
                            + (o.codigo === s.id_padre ? ' selected' : '') + '>'
                            + escapar(o.nombre) + '</option>';
                    })
            ).join('');

            var roles = datos.roles.map(function(r) {
                return '<option value="' + r + '"' + (r === s.rol ? ' selected' : '') + '>'
                    + rotuloRol(r) + '</option>';
            }).join('');

            return '<tr class="cfe-seccion-fila' + (s.activo ? '' : ' cfe-inactiva') + '" '
                + 'data-codigo="' + escapar(s.codigo) + '">'
                + '<td class="cfe-orden">'
                    + '<button type="button" class="btn btn-link btn-sm p-0 cfe-mover-sec" '
                        + 'data-idx="' + i + '" data-delta="-1">&uarr;</button>'
                    + '<button type="button" class="btn btn-link btn-sm p-0 cfe-mover-sec" '
                        + 'data-idx="' + i + '" data-delta="1">&darr;</button>'
                + '</td>'
                + '<td><input type="text" class="form-control form-control-sm cfe-sec-nombre" '
                    + 'maxlength="80" value="' + escapar(s.nombre) + '"></td>'
                + '<td><code class="cfe-codigo">' + escapar(s.codigo) + '</code></td>'
                + '<td><select class="form-select form-select-sm cfe-sec-rol">' + roles + '</select></td>'
                + '<td><select class="form-select form-select-sm cfe-sec-padre">' + padres + '</select></td>'
                + '<td class="text-center">'
                    + '<div class="form-check form-switch d-inline-block">'
                    + '<input class="form-check-input cfe-sec-activo" type="checkbox"'
                    + (s.activo ? ' checked' : '') + '></div>'
                + '</td></tr>';
        }).join('');

        conectarSecciones();
    }

    function conectarSecciones() {
        var cuerpo = document.getElementById('cfeSecciones');

        if (!cuerpo) {
            return;
        }

        cuerpo.querySelectorAll('.cfe-mover-sec').forEach(function(b) {
            b.addEventListener('click', function() {
                var idx = Number(b.dataset.idx);
                var destino = idx + Number(b.dataset.delta);

                if (destino < 0 || destino >= secciones.length) {
                    return;
                }

                var tmp = secciones[idx];
                secciones[idx] = secciones[destino];
                secciones[destino] = tmp;

                reordenarPorSeccion();
                pintarSecciones();
                pintarFilas();
                validar();
            });
        });

        cuerpo.querySelectorAll('tr.cfe-seccion-fila').forEach(function(tr) {
            var s = buscarSeccion(tr.dataset.codigo);

            if (!s) {
                return;
            }

            enlazar(tr, '.cfe-sec-nombre', 'input', function(el) { s.nombre = el.value; }, false);
            enlazar(tr, '.cfe-sec-rol', 'change', function(el) { s.rol = el.value; }, false);
            enlazar(tr, '.cfe-sec-padre', 'change', function(el) {
                s.id_padre = el.value || null;
            }, false);
            enlazar(tr, '.cfe-sec-activo', 'change', function(el) {
                s.activo = el.checked;
                pintarSecciones();
                pintarFilas();
            }, false);
        });
    }

    /* ================================================================
       ORÍGENES DISPONIBLES
       ================================================================ */

    function pintarProviders() {
        var cuerpo = document.getElementById('cfeProviders');

        if (!cuerpo) {
            return;
        }

        cuerpo.innerHTML = datos.providers.map(function(p) {
            var series = Object.keys(p.series).map(function(c) {
                return escapar(p.series[c]);
            }).join('<br>');

            return '<tr class="' + (p.disponible ? '' : 'cfe-inactiva') + '">'
                + '<td>' + escapar(p.nombre) + '</td>'
                + '<td class="small">' + series + '</td>'
                + '<td>' + escapar(p.moneda) + '</td>'
                + '<td>' + (p.disponible
                    ? '<span class="badge bg-success-subtle text-success">Con datos</span>'
                    : '<span class="badge bg-secondary-subtle text-secondary">Sin construir</span>')
                + '</td></tr>';
        }).join('');
    }

    /* ================================================================
       ALTAS
       ================================================================ */

    function pintarSelectoresAlta() {
        opciones('cfeNuevaSeccion', secciones.map(function(s) {
            return { valor: s.codigo, texto: s.nombre };
        }));

        opciones('cfeNuevoTipo', datos.tipos.map(function(t) {
            return { valor: t, texto: rotuloTipo(t) };
        }));

        opciones('cfeNuevaSeccionRol', datos.roles.map(function(r) {
            return { valor: r, texto: rotuloRol(r) };
        }));
    }

    function opciones(id, lista) {
        var el = document.getElementById(id);

        if (!el) {
            return;
        }

        el.innerHTML = lista.map(function(o) {
            return '<option value="' + escapar(o.valor) + '">' + escapar(o.texto) + '</option>';
        }).join('');
    }

    var altaConectada = false;

    function conectarAlta() {
        if (altaConectada) {
            return;   // pintarTodo() puede correr más de una vez
        }

        altaConectada = true;

        var nombre = document.getElementById('cfeNuevoNombre');

        if (nombre) {
            // Vista previa del código interno. El servidor lo vuelve a
            // normalizar por su cuenta: esto es sólo para que se vea qué clave
            // va a quedar.
            nombre.addEventListener('input', function() {
                texto('cfeNuevoCodigo', slug(nombre.value) || '—');
            });
        }

        clic('cfeBtnAgregarFila', agregarFila);
        clic('cfeBtnAgregarSeccion', agregarSeccion);
        clic('cfeBtnGuardar', guardar);

        var ver = document.getElementById('cfeVerInactivas');

        if (ver) {
            ver.checked = verInactivas;
            ver.addEventListener('change', function() {
                verInactivas = ver.checked;
                pintarFilas();
            });
        }
    }

    function agregarFila() {
        var nombre = valor('cfeNuevoNombre');

        if (!nombre.trim()) {
            Notificacion.campoInvalido('cfeNuevoNombre', 'Poné un nombre para la fila.');
            return;
        }

        pedirJson(URL_BASE + '?action=addFila', {
            nombre: nombre,
            seccion: valor('cfeNuevaSeccion'),
            tipo: valor('cfeNuevoTipo')
        }).then(function() {
            document.getElementById('cfeNuevoNombre').value = '';
            texto('cfeNuevoCodigo', '—');

            // Las filas entran inhabilitadas: si no se dice, la fila "no
            // aparece" en el tablero y parece que el alta falló.
            Notificacion.exito('Fila agregada.', {
                detalle: 'Entra inhabilitada: una fila nueva no puede invalidar la estructura. '
                       + 'Habilitala y guardá cuando tenga origen de datos.'
            });

            cargado = false;
            cargar();
        }).catch(function(e) {
            Notificacion.error('No se pudo agregar la fila: ' + e.message);
        });
    }

    function agregarSeccion() {
        var nombre = valor('cfeNuevaSeccionNombre');

        if (!nombre.trim()) {
            Notificacion.campoInvalido('cfeNuevaSeccionNombre', 'Poné un nombre para la sección.');
            return;
        }

        pedirJson(URL_BASE + '?action=addSeccion', {
            nombre: nombre,
            rol: valor('cfeNuevaSeccionRol')
        }).then(function() {
            document.getElementById('cfeNuevaSeccionNombre').value = '';

            Notificacion.exito('Sección agregada.', {
                detalle: 'Entra inhabilitada, igual que las filas.'
            });

            cargado = false;
            cargar();
        }).catch(function(e) {
            Notificacion.error('No se pudo agregar la sección: ' + e.message);
        });
    }

    /* ================================================================
       VALIDACIÓN Y GUARDADO
       ================================================================ */

    /**
     * Espeja en el front la validación que corre el servidor, para poder
     * bloquear el botón y explicar por qué. El servidor la vuelve a correr
     * sobre el estado resultante: acá no se confía, sólo se anticipa.
     */
    function validar() {
        var errores = [];
        var advertencias = [];

        var activas = filas.filter(function(f) { return f.activo; });
        var seccionesActivas = {};

        secciones.forEach(function(s) {
            if (s.activo) {
                seccionesActivas[s.codigo] = s;
            }
        });

        var vistosCodigo = {};
        var origenes = {};
        var saldos = [];
        var sinConstruir = [];

        filas.forEach(function(f) {
            var clave = String(f.codigo).toUpperCase();

            if (vistosCodigo[clave]) {
                errores.push('El código de fila "' + f.codigo + '" está repetido.');
            }

            vistosCodigo[clave] = true;

            if (!String(f.nombre).trim()) {
                errores.push('Hay una fila sin nombre (' + f.codigo + ').');
            }
        });

        activas.forEach(function(f) {
            if (!seccionesActivas[f.seccion]) {
                errores.push('La fila "' + f.nombre + '" está activa pero su sección quedaría '
                    + 'inhabilitada: su importe desaparecería del tablero.');
                return;
            }

            if (datos.tipos_con_origen.indexOf(f.tipo) === -1) {
                return;
            }

            if (!f.origen_provider || !f.origen_serie) {
                errores.push('La fila "' + f.nombre + '" no tiene origen de datos, así que se '
                    + 'mostraría siempre en cero.');
                return;
            }

            var par = f.origen_provider + '|' + f.origen_serie;

            if (origenes[par]) {
                errores.push('La fila "' + f.nombre + '" lee el mismo origen que "'
                    + origenes[par] + '": el importe se contaría dos veces.');
            } else {
                origenes[par] = f.nombre;
            }

            var prov = buscarProvider(f.origen_provider);

            if (prov && !prov.disponible && sinConstruir.indexOf(prov.nombre) === -1) {
                sinConstruir.push(prov.nombre);
            }

            if (f.tipo === 'SALDO_INICIAL') {
                saldos.push(f.nombre);
            }
        });

        // Un aviso agrupado y no uno por fila: son doce módulos, y la lista
        // suelta empujaba la tabla fuera de la pantalla y tapaba los avisos que
        // sí piden una acción.
        if (sinConstruir.length > 0) {
            sinConstruir.sort();
            advertencias.push(sinConstruir.length + ' fila(s) se muestran en cero porque su '
                + 'módulo todavía no está construido: ' + sinConstruir.join(', ') + '.');
        }

        if (saldos.length > 1) {
            errores.push('Hay más de una fila de saldo inicial activa (' + saldos.join(', ')
                + '): el arrastre tomaría dos aperturas distintas.');
        }

        pintarValidacion(errores, advertencias);

        var btn = document.getElementById('cfeBtnGuardar');

        if (btn) {
            btn.disabled = (errores.length > 0);
        }
    }

    function pintarValidacion(errores, advertencias) {
        var cont = document.getElementById('cfeValidacion');

        if (!cont) {
            return;
        }

        var html = '';

        if (errores.length > 0) {
            html += bloque('danger', 'circle-xmark',
                'No se puede guardar hasta resolver esto', errores);
        }

        if (advertencias.length > 0) {
            html += bloque('warning', 'triangle-exclamation',
                'Se puede guardar, pero tené en cuenta', advertencias);
        }

        cont.innerHTML = html;
    }

    function bloque(tipo, icono, titulo, items) {
        return '<div class="alert alert-' + tipo + ' py-2 px-3 mb-0 rounded-0 border-0 border-bottom">'
            + '<div class="d-flex align-items-start">'
            + '<i class="fas fa-' + icono + ' me-2 mt-1"></i><div>'
            + '<strong>' + titulo + '</strong>'
            + '<ul class="mb-0 mt-1 small">'
            + items.map(function(e) { return '<li>' + escapar(e) + '</li>'; }).join('')
            + '</ul></div></div></div>';
    }

    function guardar() {
        var btn = document.getElementById('cfeBtnGuardar');

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Guardando...';
        }

        // El orden va implícito en la posición del arreglo: el servidor
        // renumera. Nunca se manda un campo ORDEN.
        pedirJson(URL_BASE + '?action=saveEstructura', {
            secciones: secciones,
            filas: filas
        }).then(function() {
            Notificacion.exito('Estructura guardada. El tablero ya usa este cuadro.');

            cargado = false;
            cargar();
            restaurarBoton(btn);
        }).catch(function(e) {
            // El servidor valida el estado resultante completo, así que su
            // mensaje enuncia la consecuencia de negocio -"su importe
            // desaparecería del tablero"- y hay que poder leerlo entero.
            Notificacion.error('No se guardó la estructura: ' + e.message, {
                titulo: 'El tablero quedó como estaba'
            });

            restaurarBoton(btn);
            validar();
        });
    }

    function restaurarBoton(btn) {
        if (btn) {
            btn.innerHTML = '<i class="fas fa-floppy-disk me-1"></i> Guardar';
            btn.disabled = false;
        }
    }

    /* ================================================================
       AYUDANTES
       ================================================================ */

    /**
     * El rótulo con el que se elige un tipo en el desplegable.
     *
     * La lista de tipos la manda el servidor (CashflowEstructura::TIPOS): acá
     * sólo se les pone nombre. Un tipo nuevo que no esté en este mapa se dibuja
     * con su código, así que aparece igual —feo, pero nunca ausente.
     */
    function rotuloTipo(t) {
        var r = {
            'SALDO_INICIAL': 'Saldo inicial',
            'INGRESO': 'Ingreso',
            'EGRESO': 'Egreso',
            'SUBTOTAL': 'Subtotal',
            'FLUJO_NETO': 'Flujo neto',
            'SALDO_FINAL': 'Saldo final',
            'STOCK_COBERTURA': 'Stock de cobertura (no va en ninguna fecha)',
            'USO_COBERTURA': 'Uso de cobertura (se edita en el tablero)'
        };

        return r[t] || t;
    }

    function rotuloRol(r) {
        var m = {
            'SALDO': 'Saldo (abre el arrastre)',
            'MOVIMIENTO': 'Movimiento (aporta flujo)',
            'DERIVADO': 'Derivado (sólo cálculos)'
        };

        return m[r] || r;
    }

    /** Vista previa del código interno; el servidor hace la normalización real */
    function slug(texto) {
        return String(texto)
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_|_$/g, '')
            .substring(0, 30);
    }

    function nombreSeccion(codigo) {
        var s = buscarSeccion(codigo);

        return s ? s.nombre : codigo;
    }

    function buscarSeccion(codigo) {
        for (var i = 0; i < secciones.length; i++) {
            if (secciones[i].codigo === codigo) {
                return secciones[i];
            }
        }

        return null;
    }

    function buscarFila(id) {
        for (var i = 0; i < filas.length; i++) {
            if (filas[i].id === id) {
                return filas[i];
            }
        }

        return null;
    }

    function buscarProvider(codigo) {
        if (!codigo || !datos) {
            return null;
        }

        for (var i = 0; i < datos.providers.length; i++) {
            if (datos.providers[i].codigo === codigo) {
                return datos.providers[i];
            }
        }

        return null;
    }

    function clic(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? el.value : '';
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = v;
        }
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? '' : 'none';
        }
    }

    function avisoError(mensaje) {
        var cont = document.getElementById('cfeAvisos');

        if (cont) {
            cont.innerHTML = '<div class="alert alert-danger">' + escapar(mensaje) + '</div>';
        }

        console.error(mensaje);
    }

    function escapar(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
