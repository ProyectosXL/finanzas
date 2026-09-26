/**
 * Parametros -> Tarjetas
 *
 * El maestro de tarjetas: alta contra BANCO y contra la vista de usuarios, y
 * edicion en linea del % de cobertura, el dia de vencimiento y los ultimos 4.
 *
 * EL BANCO Y EL USUARIO SE ELIGEN, NO SE TIPEAN
 * ---------------------------------------------
 * Los dos vienen en el payload, desde su fuente: BANCO (Tango) y
 * RO_V_CASHFLOW_USUARIOS_TARJETAS. El NOMBRE del usuario no viaja al servidor al
 * guardar: lo resuelve el backend desde la vista con el ID. Si se pudiera tipear,
 * dos pantallas mostrarian dos nombres para el mismo ID y ninguno seria "el
 * nombre del usuario". Mismo criterio que NOM_PROVEE en el maestro de fleteros.
 *
 * QUE APAREZCA EN EL DESPLEGABLE NO AUTORIZA NADA: el alta vuelve a chequear el
 * banco contra BANCO y el usuario contra la vista, en el servidor.
 *
 * EL TIPO ES INDEPENDIENTE DEL USUARIO
 * ------------------------------------
 * La vista une directores y supervisoras sin columna de tipo, asi que la pantalla
 * no puede -ni tiene que- deducir el tipo de quien sea el usuario. Lo elige una
 * persona. Solo las de tipo SUPERVISORA se cruzan por nombre contra el maestro de
 * supervisoras, y eso pasa en la sub-pestana, no aca.
 *
 * SE GUARDA CONTRA EL CONTROLLER DEL MODULO Y NO CONTRA ParametrosController
 * --------------------------------------------------------------------------
 * Las mismas tablas se leen y se escriben desde las tres sub-pestanas de
 * Financiero -> Pagos con Tarjetas y Otros, que cargan los resumenes contra la
 * misma tarjeta. Dos endpoints escribiendo lo mismo se desincronizan en la
 * primera validacion que alguien agregue de un solo lado. Es el mismo criterio
 * con el que Logistica y el modulo CASHFLOW declaran su propio endpoint.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/TarjetasController.php';
    var PARAMETROS = 'Controller/ParametrosController.php';

    var tarjetas = [];
    var tipos = {};

    /* LISTAS ORDENADAS, NO MAPAS. El backend las manda como arrays de objetos
       porque un mapa se convierte en un objeto JSON y Object.keys() NO respeta el
       orden en que se escribió: pone primero las claves que son índices de array,
       ordenadas numéricamente. De los 198 códigos de banco, 135 son enteros
       canónicos, así que el desplegable salía por número de banco y no por nombre.
       Ver Tarjetas::comoLista(). */
    var bancos = [];
    var usuarios = [];

    var tablaCreada = false;
    var vistaCreada = false;
    var bancosOk = false;

    /** Cuántas coincidencias se muestran a la vez en el buscador de bancos */
    var MAX_SUGERENCIAS = 12;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        /* Dos pedidos porque son dos responsabilidades: la descripcion del modulo
           vive en el payload de Parametros -que es donde el modulo esta
           declarado- y los datos en el controller del modulo. Que la descripcion
           no llegue no puede impedir ver la grilla. */
        pedir(PARAMETROS + '?action=getTodo')
            .then(function(d) {
                var modulo = buscarModulo(d.data, 'TARJETAS');
                var desc = document.getElementById('ptarDescripcion');

                if (desc && modulo && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' +
                        esc(modulo.descripcion);
                }
            })
            .catch(function() { /* Contexto, no dato. */ });

        pedir(ENDPOINT + '?action=getTarjetas')
            .then(function(d) {
                tarjetas = d.data.filas || [];
                tipos = d.data.tipos || {};
                bancos = d.data.bancos || [];
                usuarios = d.data.usuarios || [];
                tablaCreada = !!d.data.tabla_creada;
                vistaCreada = !!d.data.vista_creada;
                bancosOk = !!d.data.bancos_disponibles;

                avisar(d.data.avisos || []);
                llenarDesplegables();
                pintar();
            })
            .catch(function(e) {
                avisar(['No se pudieron leer las tarjetas: ' + e.message]);
            });
    }

    function buscarModulo(data, codigo) {
        if (!data || !data.modulos) { return null; }

        for (var i = 0; i < data.modulos.length; i++) {
            if (data.modulos[i].codigo === codigo) { return data.modulos[i]; }
        }

        return null;
    }

    /* ================================================================
       LA TABLA
       ================================================================ */

    function pintar() {
        var cuerpo = document.getElementById('ptarBody');
        var btnNuevo = document.getElementById('ptarBtnNuevo');

        /* EL BOTON DICE POR QUE ESTA APAGADO. Un boton deshabilitado sin motivo
           manda a adivinar; con el title, quien lo ve sabe que script correr o a
           quien pedirle la vista. */
        if (btnNuevo) {
            btnNuevo.disabled = !tablaCreada || !vistaCreada || !bancosOk;
            btnNuevo.title = !tablaCreada
                ? 'Falta correr sql/cashflow_tarjetas.sql contra la base central'
                : (!vistaCreada
                    ? 'Falta la vista RO_V_CASHFLOW_USUARIOS_TARJETAS, que no la crea este repo'
                    : (!bancosOk ? 'No se pudo leer el maestro de bancos de Tango' : ''));
        }

        if (!cuerpo) { return; }

        if (!tarjetas.length) {
            cuerpo.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">' +
                (tablaCreada
                    ? 'Todavía no hay ninguna tarjeta cargada. Agregalas con el botón de arriba.'
                    : 'Falta correr sql/cashflow_tarjetas.sql contra la base central.') +
                '</td></tr>';

            return;
        }

        cuerpo.innerHTML = tarjetas.map(function(t) {
            /* UN USUARIO QUE YA NO ESTA EN LA VISTA SE MARCA. No se da de baja
               solo: puede ser una supervisora que dejo de estar activa, y decidir
               que hacer con su tarjeta es de una persona. Mismo criterio que un
               fletero cuyo codigo ya no esta en CPA01. */
            var fueraDeVista = (vistaCreada && !t.USUARIO_EN_VISTA)
                ? ' <span class="badge bg-danger ms-1" title="Este usuario ya no está activo en ' +
                  'RO_V_CASHFLOW_USUARIOS_TARJETAS. Se ve con el nombre guardado.">' +
                  'fuera de la vista</span>' : '';

            var bancoSinNombre = (bancosOk && !t.DESC_BANCO)
                ? ' <span class="badge bg-warning text-dark ms-1" title="Este código de banco ya ' +
                  'no está en el maestro de Tango">banco desconocido</span>' : '';

            /* SOLO LAS DE TIPO SUPERVISORA SE CRUZAN POR NOMBRE, y la fila lo
               dice: sin esto, alguien podria leer el tipo como una propiedad del
               usuario y cargar Corporativa esperando que se asocie igual. */
            var notaTipo = (t.TIPO === 'SUPERVISORA')
                ? '<div><small class="text-muted" title="Se cruza por nombre contra ' +
                  'RO_T_SUPERVISORAS_COMERCIAL">se asocia por nombre</small></div>' : '';

            return '<tr class="' + (t.ACTIVA ? '' : 'text-muted') + '">' +
                '<td>' + celdaTipo(t) + notaTipo + '</td>' +
                '<td>' +
                    esc(t.DESC_BANCO || t.COD_BANCO) + bancoSinNombre +
                    '<div><small class="text-muted">' + esc(t.COD_BANCO) + '</small></div>' +
                '</td>' +
                '<td>' +
                    '<span class="fw-semibold">' + esc(t.NOMBRE_USUARIO) + '</span>' +
                    fueraDeVista +
                '</td>' +
                '<td class="text-center">' +
                    '<input type="text" maxlength="4" inputmode="numeric" ' +
                        'class="form-control form-control-sm text-center ptar-input" ' +
                        'data-id="' + esc(t.ID) + '" data-campo="ultimos_4" ' +
                        'placeholder="—" ' +
                        'value="' + esc(t.ULTIMOS_4 === null ? '' : t.ULTIMOS_4) + '"' +
                        (tablaCreada ? '' : ' disabled') + '>' +
                '</td>' +
                celdaNum(t, 'pct_cobertura', t.PCT_COBERTURA, '0.01', 0, 100) +
                celdaNum(t, 'dia_vencimiento', t.DIA_VENCIMIENTO, '1', 1, 31) +
                '<td class="text-center">' +
                    '<div class="form-check form-switch d-inline-block">' +
                        '<input class="form-check-input ptar-activa" type="checkbox" ' +
                            'data-id="' + esc(t.ID) + '"' +
                            (t.ACTIVA ? ' checked' : '') +
                            (tablaCreada ? '' : ' disabled') + '>' +
                    '</div>' +
                '</td>' +
                '<td><small class="text-muted">' +
                    esc(t.FECHA_MODIF || '') +
                    (t.USUARIO_MODIF ? ' — ' + esc(t.USUARIO_MODIF) : '') +
                    (t.ACTIVA ? '' : '<div>De baja</div>') +
                '</small></td>' +
            '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.ptar-input').forEach(function(input) {
            input.addEventListener('change', function() { guardarCampo(input); });
        });

        cuerpo.querySelectorAll('.ptar-tipo').forEach(function(sel) {
            sel.addEventListener('change', function() { guardarCampo(sel); });
        });

        cuerpo.querySelectorAll('.ptar-activa').forEach(function(chk) {
            chk.addEventListener('change', function() {
                activar(chk.getAttribute('data-id'), chk.checked);
            });
        });
    }

    /* El tipo se edita en linea porque es la clase de error que se descubre al
       ver la tarjeta en la sub-pestana equivocada, y obligar a dar de baja y
       cargar de nuevo perderia sus resumenes de vista. */
    function celdaTipo(t) {
        var opciones = Object.keys(tipos).map(function(k) {
            return '<option value="' + esc(k) + '"' + (t.TIPO === k ? ' selected' : '') + '>' +
                esc(tipos[k]) + '</option>';
        }).join('');

        return '<select class="form-select form-select-sm ptar-tipo" ' +
            'data-id="' + esc(t.ID) + '" data-campo="tipo"' +
            (tablaCreada ? '' : ' disabled') + '>' + opciones + '</select>';
    }

    function celdaNum(t, campo, valor, paso, min, max) {
        return '<td class="text-end">' +
            '<input type="number" step="' + paso + '" min="' + min + '" max="' + max + '" ' +
                'class="form-control form-control-sm text-end ptar-input" ' +
                'data-id="' + esc(t.ID) + '" data-campo="' + campo + '" ' +
                'value="' + esc(valor === null ? '' : valor) + '"' +
                (tablaCreada ? '' : ' disabled') + '>' +
        '</td>';
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardarCampo(input) {
        var id = input.getAttribute('data-id');
        var campo = input.getAttribute('data-campo');
        var t = porId(id);

        if (!t) { return; }

        /* La validacion de la pantalla es para no ir al servidor por un dedazo
           obvio; la que VALE es la del backend, que se ejecuta igual. */
        if (campo === 'dia_vencimiento') {
            var d = Number(input.value);

            if (input.value === '' || isNaN(d) || d < 1 || d > 31 || d !== Math.floor(d)) {
                Notificacion.campoInvalido(input,
                    'El día de vencimiento es obligatorio y tiene que ser un número entero ' +
                    'del 1 al 31. Sin él la tarjeta no proyecta nada.');

                return;
            }
        }

        if (campo === 'ultimos_4' && input.value !== '' && !/^\d{4}$/.test(input.value)) {
            Notificacion.campoInvalido(input,
                'Tienen que ser exactamente cuatro números, o quedar vacío.');

            return;
        }

        if (campo === 'pct_cobertura' && input.value !== '') {
            var p = Number(input.value);

            if (isNaN(p) || p < 0 || p > 100) {
                Notificacion.campoInvalido(input, 'El % va en puntos y entre 0 y 100: 5 es 5 %.');

                return;
            }
        }

        /* SE MANDAN TODOS LOS CAMPOS, no sólo el que cambió: el guardado escribe
           la fila entera, así que mandar uno solo borraría los otros. Mismo
           criterio que el maestro de fleteros. */
        pedir(ENDPOINT + '?action=saveTarjeta', {
            id: t.ID,
            tipo: campo === 'tipo' ? input.value : t.TIPO,
            cod_banco: t.COD_BANCO,
            id_usuario: t.ID_USUARIO,
            ultimos_4: campo === 'ultimos_4' ? input.value : t.ULTIMOS_4,
            pct_cobertura: campo === 'pct_cobertura' ? input.value : t.PCT_COBERTURA,
            dia_vencimiento: campo === 'dia_vencimiento' ? input.value : t.DIA_VENCIMIENTO
        })
            .then(function(d) {
                Notificacion.exito(d.message || 'Guardado.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar: ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                cargar();
            });
    }

    function activar(id, activa) {
        /* DAR DE BAJA NO BORRA NADA, así que no se pide confirmación: es
           reversible con el mismo interruptor y la tarjeta conserva sus
           resúmenes. Lo que sí hace es dejar de proyectar, y eso lo dice la
           notificación que contesta el servidor. */
        pedir(ENDPOINT + '?action=activarTarjeta', { id: id, activa: activa })
            .then(function(d) {
                Notificacion.exito(d.message || 'Listo.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo cambiar el estado: ' + e.message);
                cargar();
            });
    }

    function porId(id) {
        for (var i = 0; i < tarjetas.length; i++) {
            if (String(tarjetas[i].ID) === String(id)) { return tarjetas[i]; }
        }

        return null;
    }

    /* ================================================================
       EL ALTA
       ================================================================ */

    function llenarDesplegables() {
        /* El tipo sigue siendo un mapa de tres entradas: con tres, el orden es el
           que tiene y no hace falta nada más. */
        opcionesMapa('ptarTipo', tipos, 'Elegí el tipo…');

        /* Los usuarios son catorce, así que un desplegable alcanza; lo que hacía
           falta era que estuvieran ORDENADOS por nombre, y para eso el backend los
           manda como lista. El banco no: son 198 y se busca. */
        opcionesLista('ptarUsuario', usuarios, 'id', 'Elegí el usuario…');
    }

    function opcionesMapa(id, mapa, vacio) {
        var sel = document.getElementById(id);

        if (!sel) { return; }

        sel.innerHTML = '<option value="">' + esc(vacio) + '</option>'
            + Object.keys(mapa).map(function(k) {
                return '<option value="' + esc(k) + '">' + esc(mapa[k]) + '</option>';
            }).join('');
    }

    /** Un desplegable desde una LISTA, que es la que conserva el orden */
    function opcionesLista(id, lista, clave, vacio) {
        var sel = document.getElementById(id);

        if (!sel) { return; }

        sel.innerHTML = '<option value="">' + esc(vacio) + '</option>'
            + lista.map(function(x) {
                return '<option value="' + esc(x[clave]) + '">' + esc(x.nombre) + '</option>';
            }).join('');
    }

    /* ---- el buscador de bancos ----------------------------------------------
       Filtra los 198 bancos que ya vinieron en el payload. No vuelve al servidor:
       una consulta por cada letra tipeada sería ir a buscar algo que ya está acá.
       ------------------------------------------------------------------------- */

    /**
     * Los bancos que coinciden con lo que se escribió.
     *
     * BUSCA POR NOMBRE Y POR CODIGO, porque quien carga una tarjeta puede acordarse
     * de cualquiera de los dos.
     *
     * SIN NADA ESCRITO DEVUELVE LOS PRIMEROS, y no una lista vacía: al hacer foco
     * conviene ver que hay algo para elegir. Ya vienen ordenados por nombre.
     */
    function bancosQueCoinciden(q) {
        var t = String(q || '').trim().toLowerCase();

        if (t === '') { return bancos.slice(0, MAX_SUGERENCIAS); }

        return bancos.filter(function(b) {
            return b.nombre.toLowerCase().indexOf(t) !== -1
                || b.cod.toLowerCase().indexOf(t) !== -1;
        }).slice(0, MAX_SUGERENCIAS);
    }

    function buscarBanco() {
        var input = document.getElementById('ptarBanco');
        var caja = document.getElementById('ptarBancoSugerencias');

        if (!input || !caja) { return; }

        /* Escribir INVALIDA lo elegido: si no, corregir el texto después de haber
           elegido dejaría el código anterior guardado y se daría de alta la tarjeta
           en un banco que la pantalla ya no muestra. */
        valor('ptarBancoCod', '');
        marcarBancoElegido(null);

        var coincidencias = bancosQueCoinciden(input.value);

        if (!coincidencias.length) {
            caja.innerHTML = '<div class="list-group-item small text-muted">'
                + 'Ningún banco coincide. Se buscan por nombre y por código.</div>';
            caja.style.display = '';

            return;
        }

        caja.innerHTML = coincidencias.map(function(b) {
            return '<button type="button" class="list-group-item list-group-item-action '
                + 'py-1 ptar-banco-op" data-cod="' + esc(b.cod) + '" '
                + 'data-nombre="' + esc(b.nombre) + '">'
                + '<span class="small">' + esc(b.nombre) + '</span>'
                + ' <small class="text-muted">' + esc(b.cod) + '</small>'
            + '</button>';
        }).join('');

        caja.style.display = '';

        caja.querySelectorAll('.ptar-banco-op').forEach(function(btn) {
            btn.addEventListener('click', function() {
                elegirBanco(btn.getAttribute('data-cod'), btn.getAttribute('data-nombre'));
            });
        });
    }

    function elegirBanco(cod, nombre) {
        valor('ptarBanco', nombre);
        valor('ptarBancoCod', cod);
        marcarBancoElegido({cod: cod, nombre: nombre});
        ocultarSugerenciasBanco();
    }

    /**
     * El pie del campo dice si hay un banco elegido, y cuál.
     *
     * HACE FALTA porque el input muestra el NOMBRE y lo que viaja es el CÓDIGO: sin
     * esta marca, un texto que parece completo puede no tener ningún banco elegido
     * —por ejemplo si alguien lo escribió a mano sin tocar la lista— y eso se
     * descubriría recién al guardar.
     */
    function marcarBancoElegido(banco) {
        var pie = document.getElementById('ptarBancoElegido');

        if (!pie) { return; }

        if (banco === null) {
            pie.className = 'form-text';
            pie.textContent = 'Se elige de la lista: el código se valida contra Tango.';

            return;
        }

        pie.className = 'form-text text-success';
        pie.textContent = 'Banco ' + banco.cod + ' — ' + banco.nombre;
    }

    function ocultarSugerenciasBanco() {
        var caja = document.getElementById('ptarBancoSugerencias');

        if (caja) { caja.style.display = 'none'; }
    }

    function mostrarForm(visible) {
        var form = document.getElementById('ptarFormNuevo');

        if (form) { form.style.display = visible ? '' : 'none'; }

        if (!visible) { limpiarForm(); }
    }

    function limpiarForm() {
        valor('ptarTipo', '');
        valor('ptarBanco', '');
        valor('ptarBancoCod', '');
        valor('ptarUsuario', '');
        valor('ptarUltimos4', '');
        valor('ptarPct', '0');
        valor('ptarDia', '');
        marcarBancoElegido(null);
        ocultarSugerenciasBanco();
    }

    function agregar() {
        var faltan = [];

        if (!texto('ptarTipo')) { faltan.push('el tipo'); }

        /* SE MIRA EL CODIGO, NO EL TEXTO DEL BUSCADOR: escribir "santander" sin
           elegirlo de la lista deja el input con texto y el código vacío, y eso es
           un banco NO elegido. Sin esta distinción, el alta iría al servidor a
           fallar por un campo que en pantalla parecía completo. */
        if (!texto('ptarBancoCod')) { faltan.push('el banco (elegilo de la lista)'); }

        if (!texto('ptarUsuario')) { faltan.push('el usuario'); }
        if (!texto('ptarDia')) { faltan.push('el día de vencimiento'); }

        /* SE DICE TODO LO QUE FALTA DE UNA, no lo primero: cuatro intentos para
           descubrir cuatro campos vacíos es peor que un mensaje que los nombra. */
        if (faltan.length) {
            Notificacion.error('Falta ' + faltan.join(', ') + '.');

            return;
        }

        pedir(ENDPOINT + '?action=saveTarjeta', {
            tipo: texto('ptarTipo'),
            cod_banco: texto('ptarBancoCod'),
            id_usuario: texto('ptarUsuario'),
            ultimos_4: texto('ptarUltimos4'),
            pct_cobertura: texto('ptarPct'),
            dia_vencimiento: texto('ptarDia')
        })
            .then(function(d) {
                Notificacion.exito(d.message || 'Tarjeta guardada.');
                mostrarForm(false);
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar la tarjeta: ' + e.message);
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function avisar(lista) {
        var cont = document.getElementById('ptarAvisos');

        if (!cont) { return; }

        cont.innerHTML = (!lista || !lista.length) ? '' :
            '<div class="alert alert-warning py-2 px-3 mb-3 small">' +
                '<i class="fas fa-triangle-exclamation me-1"></i>' +
                lista.map(esc).join('<br>') +
            '</div>';
    }

    function valor(id, v) {
        var el = document.getElementById(id);

        if (el) { el.value = (v === null || v === undefined) ? '' : v; }
    }

    function texto(id) {
        var el = document.getElementById(id);

        return el ? el.value : '';
    }

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function pedir(url, cuerpo) {
        var opciones = cuerpo
            ? {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo)
            }
            : {};

        return fetch(url, opciones)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    throw new Error((d && d.message) || 'Respuesta inesperada del servidor');
                }

                return d;
            });
    }

    /* ================================================================
       ARRANQUE
       ================================================================ */

    function iniciar() {
        enganchar('ptarBtnRefresh', 'click', cargar);
        enganchar('ptarBtnNuevo', 'click', function() { mostrarForm(true); });
        enganchar('ptarBtnCancelar', 'click', function() { mostrarForm(false); });
        enganchar('ptarBtnAgregar', 'click', agregar);

        /* El buscador de bancos: se abre al escribir y también al hacer foco, para
           que se vea que hay una lista y no un campo libre. */
        enganchar('ptarBanco', 'input', buscarBanco);
        enganchar('ptarBanco', 'focus', buscarBanco);

        /* Cerrar el desplegable al hacer clic afuera. Mismo criterio que el
           autocomplete del maestro de fleteros: un panel que queda abierto tapa la
           fila de abajo del formulario. */
        document.addEventListener('click', function(ev) {
            var caja = document.getElementById('ptarBancoSugerencias');
            var input = document.getElementById('ptarBanco');

            if (caja && input && !caja.contains(ev.target) && ev.target !== input) {
                ocultarSugerenciasBanco();
            }
        });

        cargar();
    }

    function enganchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) { el.addEventListener(evento, fn); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
