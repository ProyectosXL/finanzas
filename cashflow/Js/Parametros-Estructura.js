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
            grupo: f.GRUPO || '',
            naturaleza: f.NATURALEZA || '',
            grupo_nombre: f.GRUPO_NOMBRE || '',
            activo: Number(f.ACTIVO) === 1
        };
    }

    function pintarTodo() {
        texto('cfeDescripcion', descripcionModulo());

        pintarAvisos();
        pintarSecciones();
        pintarGruposExistentes();
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
                html += '<tr class="cfe-grupo"><td colspan="10">'
                    + escapar(nombreSeccion(f.seccion)) + '</td></tr>';
            }

            html += filaHtml(f);
        });

        if (html === '') {
            html = '<tr><td colspan="10" class="text-center text-muted py-4">'
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
            + '<td>' + campoGrupo(f) + '</td>'
            + '<td>' + selectNaturaleza(f) + '</td>'
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
                    + escapar(p.nombre)
                    + (p.disponible ? (p.retirado ? ' (retirado)' : '') : ' (sin construir)')
                    + '</option>';
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

    /* ================================================================
       AGRUPAR DOS FILAS EN UN RENGLÓN DEL TABLERO

       Un concepto con parte real y parte proyectada se declara acá: las dos
       filas llevan el mismo GRUPO y cada una su NATURALEZA. El agrupamiento es
       POSICIONAL —lo que las junta es estar una al lado de la otra, en la misma
       sección y del mismo tipo—, así que moverlas con ↑ y ↓ las separa, y el
       validador lo avisa en cuanto pasa.
       ================================================================ */

    /** Si el tipo de la fila admite grupo. Lo dice el servidor, no una lista de acá. */
    function agrupable(f) {
        return (datos.tipos_sin_grupo || []).indexOf(f.tipo) === -1;
    }

    /**
     * El código del grupo y, debajo, cómo se llama en pantalla.
     *
     * Los dos en la misma celda porque son una sola decisión —"esta fila es
     * parte del concepto X, que se llama Y"— y una columna más se paga en una
     * tabla que ya tiene diez.
     *
     * El nombre lo puede declarar CUALQUIERA de las filas del grupo y gana la
     * primera; el campo lo dice en su placeholder, para que nadie lo tipee dos
     * veces creyendo que hace falta.
     */
    function campoGrupo(f) {
        if (!agrupable(f)) {
            return '<span class="text-muted small">'
                + '<i class="fas fa-minus me-1"></i>No se agrupa</span>';
        }

        if (!datos.columnas_grupo) {
            return '<span class="text-muted small">'
                + '<i class="fas fa-database me-1"></i>Falta el script</span>';
        }

        return '<input type="text" class="form-control form-control-sm cfe-grupo-cod mb-1" '
            + 'list="cfeGruposExistentes" maxlength="30" placeholder="sin grupo" '
            + 'title="Dos filas seguidas con el mismo código se muestran como un solo '
            + 'renglón que se abre" value="' + escapar(f.grupo) + '">'
            + '<input type="text" class="form-control form-control-sm cfe-grupo-nombre" '
            + 'maxlength="80" placeholder="nombre en pantalla (opcional)" '
            + 'title="Cómo se llama el renglón agrupado. Lo declara una sola de las filas '
            + 'del grupo; si ninguna lo hace, se muestra el código" '
            + 'value="' + escapar(f.grupo_nombre) + '">';
    }

    /**
     * Qué parte del concepto es la fila.
     *
     * Se puede declarar SIN grupo, y no es un descuido: una fila que es toda
     * proyectada y no se agrupa con nadie —Cobranzas Mayoristas,
     * Exportaciones— igual lo declara, y eso es lo que va a permitir después
     * una vista de "solo real" sin volver a tocar la configuración.
     */
    function selectNaturaleza(f) {
        if (!agrupable(f)) {
            return '<span class="text-muted">—</span>';
        }

        if (!datos.columnas_grupo) {
            return '<span class="text-muted">—</span>';
        }

        var opciones = ['<option value="">— sin declarar —</option>'].concat(
            (datos.naturalezas || []).map(function(n) {
                return '<option value="' + n + '"' + (n === f.naturaleza ? ' selected' : '')
                    + '>' + rotuloNaturaleza(n) + '</option>';
            })
        ).join('');

        return '<select class="form-select form-select-sm cfe-naturaleza">'
            + opciones + '</select>';
    }

    function rotuloNaturaleza(n) {
        return n === 'REAL' ? 'Real' : (n === 'PROYECTADO' ? 'Proyectado' : n);
    }

    /**
     * Los códigos de grupo que ya están en uso, para sugerirlos al tipear.
     *
     * No hay ningún alta de grupos: un grupo es el código que comparten dos
     * filas seguidas, así que la única lista que puede existir es la de los que
     * alguien ya escribió. Sin esto, la segunda fila de un grupo se tipea de
     * memoria y un dedazo la deja sola sin que se note hasta mirar el tablero.
     */
    function pintarGruposExistentes() {
        var lista = document.getElementById('cfeGruposExistentes');

        if (!lista) {
            return;
        }

        var vistos = {};

        filas.forEach(function(f) {
            if (f.grupo) { vistos[f.grupo] = true; }
        });

        lista.innerHTML = Object.keys(vistos).sort().map(function(c) {
            return '<option value="' + escapar(c) + '"></option>';
        }).join('');
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
            // El código se slugifica en el servidor igual que el de la fila,
            // pero se normaliza también acá: si no, dos filas que el usuario
            // cree del mismo grupo se ven distintas hasta que guarda, y el
            // aviso de "no quedan una al lado de la otra" no aparece.
            enlazar(tr, '.cfe-grupo-cod', 'input', function(el) {
                f.grupo = slug(el.value);
            }, false);
            enlazar(tr, '.cfe-grupo-nombre', 'input', function(el) {
                f.grupo_nombre = el.value;
            }, false);
            enlazar(tr, '.cfe-naturaleza', 'change', function(el) {
                f.naturaleza = el.value;
            }, false);

            // Al salir del campo se muestra el código tal como se va a
            // guardar. Reescribirlo en cada tecla pelearía con el cursor —un
            // espacio se vuelve guión bajo y el caret salta—, y no mostrarlo
            // nunca dejaría al usuario leyendo "cob fr" donde la base va a
            // tener COB_FR.
            var campoCod = tr.querySelector('.cfe-grupo-cod');

            if (campoCod) {
                campoCod.addEventListener('change', function() {
                    campoCod.value = f.grupo;
                    pintarGruposExistentes();
                });
            }
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

            // Retirado no es "sin construir": el módulo existe y sirve lo que
            // tiene cargado, pero ese dato ya no se mantiene. Se dice por qué.
            var estado = !p.disponible
                ? '<span class="badge bg-secondary-subtle text-secondary">Sin construir</span>'
                : (p.retirado
                    ? '<span class="badge bg-warning-subtle text-warning-emphasis" title="'
                        + escapar(p.retirado) + '">Retirado</span>'
                    : '<span class="badge bg-success-subtle text-success">Con datos</span>');

            return '<tr class="' + (p.disponible && !p.retirado ? '' : 'cfe-inactiva') + '">'
                + '<td>' + escapar(p.nombre) + '</td>'
                + '<td class="small">' + series + '</td>'
                + '<td>' + escapar(p.moneda) + '</td>'
                + '<td>' + estado + '</td></tr>';
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

        validarGrupos(advertencias);

        pintarValidacion(errores, advertencias);

        var btn = document.getElementById('cfeBtnGuardar');

        if (btn) {
            btn.disabled = (errores.length > 0);
        }
    }

    /**
     * Los avisos de agrupamiento, en vivo y mientras se mueven las filas.
     *
     * TODOS SON ADVERTENCIAS y ninguno bloquea el guardado, igual que del lado
     * del servidor: un grupo mal declarado hace que el tablero dibuje las
     * filas sueltas —muestra de más y nunca una suma que no corresponde—, así
     * que impedir guardar sería impedir el paso intermedio de cualquier
     * reacomodamiento. El que sí es error —un grupo en una fila derivada— no
     * puede pasar desde acá: a esas filas el editor ni les ofrece el campo.
     *
     * Espeja a CashflowEstructura::validarGrupos(), que es la que manda: el
     * servidor valida el estado resultante antes de escribir nada. Esto existe
     * para que el aviso aparezca al mover la fila y no después de guardar.
     *
     * @param {Array} advertencias Se le agregan los avisos
     */
    function validarGrupos(advertencias) {
        var corridas = [];
        var anterior = null;

        // La misma regla posicional de las otras dos capas: corridas de filas
        // ACTIVAS seguidas, de la misma sección y del mismo tipo. Una fila
        // inhabilitada no se dibuja, así que no parte nada.
        filas.forEach(function(f) {
            if (!f.activo) {
                return;
            }

            if (!f.grupo) {
                anterior = null;
                return;
            }

            var sigue = anterior && anterior.codigo === f.grupo
                && anterior.seccion === f.seccion && anterior.tipo === f.tipo;

            if (sigue) {
                anterior.filas.push(f);
                return;
            }

            anterior = { codigo: f.grupo, seccion: f.seccion, tipo: f.tipo, filas: [f] };
            corridas.push(anterior);
        });

        var porCodigo = {};

        corridas.forEach(function(c) {
            if (!porCodigo[c.codigo]) {
                porCodigo[c.codigo] = { codigo: c.codigo, corridas: [], filas: [] };
            }

            porCodigo[c.codigo].corridas.push(c);
            porCodigo[c.codigo].filas = porCodigo[c.codigo].filas.concat(c.filas);
        });

        Object.keys(porCodigo).forEach(function(cod) {
            var g = porCodigo[cod];
            var nombre = nombreDeGrupo(g.filas) || cod;

            if (g.corridas.length > 1) {
                advertencias.push('Las ' + g.filas.length + ' filas del grupo "' + nombre
                    + '" no quedan una al lado de la otra. El tablero las dibuja sueltas, '
                    + 'como hasta ahora, en vez de sumarlas en un renglón que no '
                    + 'correspondería.');
            }

            ['REAL', 'PROYECTADO'].forEach(function(nat) {
                var tiene = g.filas.some(function(f) { return f.naturaleza === nat; });

                if (!tiene) {
                    advertencias.push('El grupo "' + nombre + '" no tiene ninguna fila con '
                        + 'la parte ' + (nat === 'REAL' ? 'real' : 'proyectada') + '. Es '
                        + 'válido —puede ser transitorio— pero si no era la idea, revisá la '
                        + 'columna Parte.');
                }
            });

            var nombres = [];

            g.filas.forEach(function(f) {
                if (f.grupo_nombre && nombres.indexOf(f.grupo_nombre) === -1) {
                    nombres.push(f.grupo_nombre);
                }
            });

            if (nombres.length > 1) {
                advertencias.push('Las filas del grupo "' + cod + '" declaran nombres '
                    + 'distintos (' + nombres.join(', ') + '). Se muestra el de la primera: "'
                    + nombres[0] + '".');
            }
        });

        // Un nombre de grupo tipeado en una fila sin grupo no se muestra en
        // ningún lado, y es fácil de hacer: se completa el de abajo y se
        // olvida el de arriba.
        filas.forEach(function(f) {
            if (f.grupo_nombre && !f.grupo) {
                advertencias.push('La fila "' + f.nombre + '" declara el nombre de grupo "'
                    + f.grupo_nombre + '" pero no está en ningún grupo: ese nombre no se '
                    + 'muestra en ningún lado.');
            }
        });
    }

    /** El primer nombre de grupo declarado entre esas filas, o '' */
    function nombreDeGrupo(lista) {
        for (var i = 0; i < lista.length; i++) {
            if (lista[i].grupo_nombre) {
                return lista[i].grupo_nombre;
            }
        }

        return '';
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
