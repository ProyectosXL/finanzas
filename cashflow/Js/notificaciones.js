/**
 * notificaciones.js
 * Avisos de una ACCION DEL USUARIO: se guardó, falló, falta completar un campo,
 * ¿confirmás esta baja?
 *
 * POR QUE EXISTE
 * --------------
 * Todo esto se hacía con alert() y confirm() del navegador, y eso trae cuatro
 * problemas que no son de estética:
 *
 *   1. BLOQUEAN EL HILO. Nada se sigue dibujando hasta que alguien aprieta
 *      Aceptar, así que un guardado que ya terminó parece colgado.
 *   2. NO SE PUEDEN LEER. Un alert no tiene formato: un mensaje del servidor con
 *      un detalle largo sale como un bloque de texto plano con \n.
 *   3. NO DISTINGUEN GRAVEDAD. Un "guardado correctamente" y un "no se pudo
 *      guardar" salen exactamente iguales, y el segundo se cierra con el mismo
 *      reflejo que el primero.
 *   4. NO SE PARECEN A LA PLATAFORMA. Salen con el chrome del navegador, con el
 *      nombre del host arriba.
 *
 * NO ES LO MISMO QUE UN "AVISO" DEL MODULO. En este código un aviso es un
 * mensaje del backend sobre LOS DATOS -"$ 1.200 quedaron fuera del horizonte"-,
 * y esos se pintan en la pantalla, quedan a la vista mientras se trabaja y no se
 * cierran. Lo de acá es sobre UNA ACCION, es efímero y se descarta. Por eso son
 * dos cosas separadas y con nombres distintos.
 *
 * COMO SE USA
 * -----------
 *   Notificacion.exito('Parámetro guardado');
 *   Notificacion.error('No se pudo guardar: ' + e.message);
 *   Notificacion.advertencia('Revisá el porcentaje del canal');
 *   Notificacion.campoInvalido('nuevoMedio', 'Ingresá el nombre del medio de pago');
 *
 *   Notificacion.confirmar({
 *       titulo: 'Dar de baja la vigencia',
 *       mensaje: '¿Dar de baja esta alícuota?',
 *       detalle: 'No se borra: deja de regir.',
 *       confirmar: 'Dar de baja',
 *       peligro: true
 *   }).then(function(si) { if (si) { ... } });
 *
 * DOS DECISIONES QUE VALE LA PENA CONOCER
 * ---------------------------------------
 * UN ERROR NO SE AUTO-CIERRA. Los éxitos desaparecen solos porque el usuario ya
 * sabe lo que hizo; un error trae el mensaje del servidor, que es lo único que
 * explica por qué el dato no está guardado, y que se borre a los cuatro segundos
 * es perderlo. Se cierra a mano.
 *
 * EL TEMPORIZADOR SE PAUSA AL PASAR EL MOUSE POR ENCIMA, así un mensaje largo no
 * se escapa mientras se lo está leyendo.
 *
 * Se carga desde index.php y no desde cada pestaña: las pestañas se reemplazan
 * enteras por AJAX, y el contenedor de los mensajes tiene que sobrevivir a ese
 * reemplazo. Mismo criterio que Js/eje-vistas.js.
 */

var Notificacion = (function() {
    'use strict';

    /**
     * Cuánto dura cada tipo en pantalla, en milisegundos. 0 = hasta que la
     * cierren. Ver la nota del encabezado sobre por qué el error es 0.
     */
    var DURACION = {
        exito: 4000,
        info: 5000,
        advertencia: 7000,
        error: 0
    };

    var ESTILO = {
        exito: { icono: 'fa-circle-check', clase: 'cf-noti-exito' },
        info: { icono: 'fa-circle-info', clase: 'cf-noti-info' },
        advertencia: { icono: 'fa-triangle-exclamation', clase: 'cf-noti-advertencia' },
        error: { icono: 'fa-circle-exclamation', clase: 'cf-noti-error' }
    };

    /**
     * Cuántos mensajes se muestran a la vez. Con más que esto la pila tapa la
     * pantalla, y los de abajo no se leen igual: al llegar al tope se cierra el
     * más viejo.
     */
    var TOPE = 4;

    var contenedor = null;

    /* ================================================================
       API
       ================================================================ */

    var api = {
        /**
         * Algo salió bien. Se cierra solo.
         * @param {string} mensaje
         * @param {Object} [opciones] {titulo, detalle, duracion}
         */
        exito: function(mensaje, opciones) {
            return mostrar('exito', mensaje, opciones);
        },

        /**
         * Algo falló. NO se cierra solo: el mensaje del servidor es lo único que
         * explica por qué el dato no quedó guardado.
         * @param {string} mensaje
         * @param {Object} [opciones]
         */
        error: function(mensaje, opciones) {
            console.error(mensaje);

            return mostrar('error', mensaje, opciones);
        },

        /**
         * Falta algo o hay que mirar un dato antes de seguir.
         * @param {string} mensaje
         * @param {Object} [opciones]
         */
        advertencia: function(mensaje, opciones) {
            return mostrar('advertencia', mensaje, opciones);
        },

        /**
         * Información neutra sobre lo que acaba de pasar.
         * @param {string} mensaje
         * @param {Object} [opciones]
         */
        info: function(mensaje, opciones) {
            return mostrar('info', mensaje, opciones);
        },

        /**
         * Un campo del formulario está mal o vacío.
         *
         * Marca el campo, le da el foco y ADEMAS avisa. El mensaje solo no
         * alcanza -no dice cuál de los seis campos es- y la marca sola tampoco
         * -no dice qué tiene de malo-. La marca se limpia cuando el usuario
         * empieza a corregir.
         *
         * @param {string|HTMLElement} campo Id del campo, o el campo mismo:
         *        varios formularios generan sus inputs sin id y trabajan con la
         *        referencia
         * @param {string} mensaje Qué le falta
         * @param {Object} [opciones] {titulo, detalle, duracion}
         */
        campoInvalido: function(campoOId, mensaje, opciones) {
            var campo = (typeof campoOId === 'string')
                ? document.getElementById(campoOId)
                : campoOId;

            if (campo) {
                campo.classList.add('is-invalid');
                campo.focus();

                if (typeof campo.select === 'function' && campo.value) {
                    campo.select();
                }

                var limpiar = function() {
                    campo.classList.remove('is-invalid');
                    campo.removeEventListener('input', limpiar);
                    campo.removeEventListener('change', limpiar);
                };

                campo.addEventListener('input', limpiar);
                campo.addEventListener('change', limpiar);
            }

            return mostrar('advertencia', mensaje, opciones);
        },

        /**
         * Pide confirmación antes de una acción que cuesta deshacer.
         *
         * @param {Object} opciones
         * @param {string} opciones.mensaje La pregunta
         * @param {string} [opciones.titulo] Encabezado
         * @param {string} [opciones.detalle] Qué pasa exactamente si se confirma
         * @param {string} [opciones.confirmar] Texto del botón que confirma
         * @param {string} [opciones.cancelar] Texto del botón que cancela
         * @param {boolean} [opciones.peligro] Pinta de rojo el botón que confirma
         * @returns {Promise<boolean>} true si confirmó
         */
        confirmar: confirmar,

        /**
         * Pide un TEXTO antes de una acción que lo necesita para poder
         * explicarse después. Es `confirmar()` con un campo adentro.
         *
         * EXISTE PORQUE window.prompt NO ALCANZA: no se puede dar formato, no
         * entra un detalle largo, no valida nada y se ve como un error del
         * navegador en vez de como una decisión del sistema.
         *
         * DEVUELVE null AL CANCELAR Y EL TEXTO AL CONFIRMAR, y nunca las dos
         * cosas mezcladas: `confirmar()` devuelve un booleano porque su
         * respuesta es sí o no, y ésta devuelve el texto porque su respuesta es
         * el texto. Un `false` que a veces es `''` obligaría a cada llamador a
         * distinguir dos ausencias distintas.
         *
         * @param {Object} opciones Las de confirmar(), más:
         * @param {string} [opciones.etiqueta] Rótulo del campo
         * @param {string} [opciones.placeholder]
         * @param {string} [opciones.valor] Con qué arranca
         * @param {number} [opciones.maxlargo] Tope de caracteres
         * @param {boolean} [opciones.opcional] Si se permite dejarlo vacío
         * @param {string} [opciones.invalido] Qué decir si está vacío y no debía
         * @returns {Promise<string|null>} El texto, o null si canceló
         */
        pedirTexto: pedirTexto,

        /**
         * Pide una FECHA antes de una acción que la necesita. Es `confirmar()`
         * con un calendario adentro.
         *
         * MISMO CONTRATO QUE pedirTexto(): devuelve null al cancelar y la fecha
         * —'aaaa-mm-dd', que es como la manda y la espera el backend— al
         * confirmar. Nunca un Date: el módulo entero mueve fechas como string
         * para no pasar por `new Date(string)`, que es de donde salen los
         * corrimientos de un día.
         *
         * NO ES UN CAMPO MÁS EN LA BARRA DE ACCIONES. La fecha se elige adentro
         * del diálogo que dice cuántas filas y por cuánta plata se van a tocar,
         * porque ese número es lo que hace notar que se seleccionó de más, y
         * hay que leerlo ANTES de elegir la fecha, no después.
         *
         * @param {Object} opciones Las de confirmar(), más:
         * @param {string} [opciones.etiqueta] Rótulo del campo
         * @param {string} [opciones.valor] Con qué arranca, 'aaaa-mm-dd'
         * @param {string} [opciones.min] Fecha mínima aceptada, 'aaaa-mm-dd'.
         *        Se VALIDA al confirmar, no sólo se pone en el input: el `min`
         *        del input limita el calendario pero deja tipear cualquier cosa.
         * @param {string} [opciones.antesDelMin] Qué decir si es anterior a `min`
         * @param {string} [opciones.max] Fecha máxima aceptada, 'aaaa-mm-dd'. Se
         *        valida al confirmar, igual que `min`.
         * @param {string} [opciones.despuesDelMax] Qué decir si es posterior a `max`
         * @param {string} [opciones.invalido] Qué decir si quedó vacía
         * @param {Object} [opciones.texto] Un texto opcional junto a la fecha
         *        —una observación—: {etiqueta, placeholder, maxlargo}. CON ESTO
         *        DEVUELVE {fecha, texto} en lugar de la fecha sola: la fecha y su
         *        porqué se piden en el mismo diálogo, no en dos seguidos.
         * @returns {Promise<string|Object|null>} 'aaaa-mm-dd' (o {fecha, texto}),
         *          o null si canceló
         */
        pedirFecha: pedirFecha,

        /**
         * Pide que se TILDEN OPCIONES antes de una acción. Es `confirmar()`
         * con casillas adentro: una sola ("Inhabilitarlo también en esos
         * meses") o varias ("a qué meses copiar").
         *
         * MISMO CONTRATO QUE pedirTexto(): null al cancelar, y al confirmar la
         * lista de los `valor` tildados -vacía si no se tildó ninguno y
         * `minimo` es 0-.
         *
         * @param {Object} opciones Las de confirmar(), más:
         * @param {Array} opciones.opciones [{valor, rotulo, marcada?, nota?}]
         * @param {string} [opciones.etiqueta] Rótulo arriba de las casillas
         * @param {number} [opciones.minimo] Cuántas hay que tildar, como mínimo (0)
         * @param {string} [opciones.invalido] Qué decir si no llegan al mínimo
         * @param {boolean} [opciones.enColumnas] Las casillas en tres columnas
         * @returns {Promise<Array|null>}
         */
        elegirOpciones: elegirOpciones,

        /**
         * Pide VARIOS CAMPOS en un diálogo: un formulario. Es `confirmar()` con
         * campos tipados adentro, y opcionalmente una grilla de renglones.
         *
         * EXISTE PORQUE UNA LÍNEA CON SEPARADORES NO ES UN FORMULARIO. Los
         * resúmenes de tarjeta se cargaban como "2026-10 | 450000 |  | 2026-10-12":
         * un separador de más corre todos los campos y el error aparece recién en
         * el servidor, sin decir cuál era.
         *
         * MISMO CONTRATO QUE LOS OTROS: null al cancelar, y al confirmar un objeto
         * {nombre: valor}. Los importes vuelven como número (o null si quedaron
         * vacíos): se tipean con el formato del módulo —1.234.567,89— y se
         * convierten acá, una sola vez. Con grilla, los renglones vuelven en
         * `renglones`, sin los que quedaron enteros en blanco.
         *
         * LA VALIDACIÓN QUE VALE ES LA DEL SERVIDOR. Esto marca el campo y no
         * cierra el diálogo, para que se corrija donde se tipea.
         *
         * @param {Object} opciones Las de confirmar(), más:
         * @param {Array} [opciones.campos] [{nombre, etiqueta, tipo, obligatorio,
         *        valor, placeholder, ayuda, ancho, positivo, opciones, titular}].
         *        tipo: 'mes', 'importe', 'fecha', 'texto', 'numero' (entero, con
         *        min y max), 'lista' (un desplegable, con `opciones`
         *        [{valor, rotulo}]), 'casillas'. Con
         *        'casillas', `opciones` es [{valor, rotulo, nota, marcada}] y
         *        `titular: true` agrega a cada una un botón para marcar cuál es
         *        la principal: vuelve {elegidas: [...], titular: valor}.
         * @param {Object} [opciones.renglones] {cantidad, campos, obligatorios,
         *        valores}: una grilla de `cantidad` renglones con esos campos. Con
         *        `obligatorios`, tienen que venir todos. `valores` es una lista de
         *        {campo: valor} con lo que trae cada renglón al abrir.
         * @param {Function} [opciones.validar] (valores) => null | 'mensaje':
         *        lo que no se puede ver campo por campo (al menos un importe).
         * @returns {Promise<Object|null>}
         */
        formulario: formulario,

        /**
         * Los importes tipeados con el formato del módulo: punto de miles y
         * coma decimal. Puros y sin Intl, para poder probarlos con cscript.
         */
        importe: {
            parsear: parsearImporte,
            formatear: formatearImporte
        },

        /** Cierra todo lo que haya en pantalla */
        limpiar: function() {
            if (contenedor) {
                contenedor.innerHTML = '';
            }
        }
    };

    /* ================================================================
       LOS MENSAJES
       ================================================================ */

    /**
     * El contenedor vive colgado de <body> y no de #tabContent, que main.js
     * reemplaza entero al cambiar de pestaña: un mensaje sobre el guardado que
     * acaba de terminar no puede desaparecer porque alguien navegó.
     */
    function raiz() {
        if (contenedor && document.body.contains(contenedor)) {
            return contenedor;
        }

        contenedor = document.createElement('div');
        contenedor.className = 'cf-notificaciones';
        // 'polite' y no 'assertive': el lector de pantalla lo anuncia cuando
        // termina lo que está diciendo, sin interrumpir al usuario.
        contenedor.setAttribute('aria-live', 'polite');
        contenedor.setAttribute('aria-atomic', 'true');

        document.body.appendChild(contenedor);

        return contenedor;
    }

    function mostrar(tipo, mensaje, opciones) {
        opciones = opciones || {};

        var cont = raiz();
        var estilo = ESTILO[tipo] || ESTILO.info;
        var duracion = (opciones.duracion !== undefined) ? opciones.duracion : DURACION[tipo];

        while (cont.children.length >= TOPE) {
            cont.removeChild(cont.firstChild);
        }

        var noti = document.createElement('div');

        noti.className = 'cf-noti ' + estilo.clase;
        noti.setAttribute('role', tipo === 'error' ? 'alert' : 'status');

        noti.innerHTML =
            '<i class="fas ' + estilo.icono + ' cf-noti-icono" aria-hidden="true"></i>' +
            '<div class="cf-noti-cuerpo">' +
                (opciones.titulo
                    ? '<div class="cf-noti-titulo">' + escapar(opciones.titulo) + '</div>'
                    : '') +
                '<div class="cf-noti-mensaje">' + escapar(mensaje) + '</div>' +
                (opciones.detalle
                    ? '<div class="cf-noti-detalle">' + escapar(opciones.detalle) + '</div>'
                    : '') +
            '</div>' +
            '<button type="button" class="cf-noti-cerrar" aria-label="Cerrar">' +
                '<i class="fas fa-xmark" aria-hidden="true"></i>' +
            '</button>' +
            (duracion > 0 ? '<div class="cf-noti-barra"></div>' : '');

        cont.appendChild(noti);

        noti.querySelector('.cf-noti-cerrar').addEventListener('click', function() {
            cerrar(noti);
        });

        if (duracion > 0) {
            programar(noti, duracion);
        }

        return noti;
    }

    /**
     * El temporizador de un mensaje, con pausa al pasar el mouse por encima.
     *
     * Sin la pausa, un mensaje de dos renglones se escapa justo cuando el
     * usuario llevó la vista hasta él. La barra de progreso usa la misma
     * duración, así que lo que se ve es lo que falta de verdad.
     */
    function programar(noti, duracion) {
        var barra = noti.querySelector('.cf-noti-barra');
        var temporizador = null;

        if (barra) {
            barra.style.animationDuration = duracion + 'ms';
        }

        function arrancar(ms) {
            temporizador = setTimeout(function() {
                cerrar(noti);
            }, ms);
        }

        noti.addEventListener('mouseenter', function() {
            clearTimeout(temporizador);

            if (barra) {
                barra.style.animationPlayState = 'paused';
            }
        });

        noti.addEventListener('mouseleave', function() {
            // Al salir se reinicia el reloj entero en vez de continuar el
            // resto: el usuario dejó de leer recién ahora.
            //
            // La barra se reinicia CON él. Si sólo se despausara, seguiría desde
            // donde quedó y se vaciaría antes de que el mensaje se cierre, que
            // es peor que no tener barra: estaría mostrando un tiempo que no es.
            if (barra) {
                barra.style.animation = 'none';
                void barra.offsetWidth;          // fuerza el reflow que reinicia
                barra.style.animation = '';
                barra.style.animationDuration = duracion + 'ms';
            }

            arrancar(duracion);
        });

        arrancar(duracion);
    }

    function cerrar(noti) {
        if (!noti || noti.classList.contains('cf-noti-saliendo')) {
            return;
        }

        noti.classList.add('cf-noti-saliendo');

        setTimeout(function() {
            if (noti.parentNode) {
                noti.parentNode.removeChild(noti);
            }
        }, 200);
    }

    /* ================================================================
       LA CONFIRMACION
       ================================================================ */

    function confirmar(opciones) {
        opciones = opciones || {};

        // Sin Bootstrap no hay modal, y una baja no puede quedar sin preguntar:
        // se cae al confirm del navegador, que es feo pero pregunta.
        if (!window.bootstrap || !bootstrap.Modal) {
            var texto = opciones.mensaje + (opciones.detalle ? '\n\n' + opciones.detalle : '');

            return Promise.resolve(window.confirm(texto));
        }

        return abrirDialogo(opciones, {
            cuerpo: '',
            alConfirmar: function() { return true; },
            alCancelar: false
        });
    }

    function pedirTexto(opciones) {
        opciones = opciones || {};

        /* Sin Bootstrap se cae al prompt del navegador. Es feo -por eso este
           diálogo existe- pero preguntar es lo que no puede faltar: el texto es
           obligatorio justamente porque después nadie puede explicar la acción
           sin él. */
        if (!window.bootstrap || !bootstrap.Modal) {
            var previo = window.prompt(
                opciones.mensaje + (opciones.detalle ? '\n\n' + opciones.detalle : ''),
                opciones.valor || '');

            if (previo === null) {
                return Promise.resolve(null);
            }

            previo = String(previo).trim();

            return Promise.resolve((previo === '' && !opciones.opcional) ? null : previo);
        }

        var id = 'cf-dlg-campo-' + Math.random().toString(36).slice(2);
        var max = opciones.maxlargo || 200;

        var cuerpo =
            '<div class="mt-3">' +
                '<label class="form-label form-label-sm" for="' + id + '">' +
                    escapar(opciones.etiqueta || 'Motivo') +
                    (opciones.opcional ? ' <span class="text-muted">(opcional)</span>' : '') +
                '</label>' +
                '<textarea id="' + id + '" class="form-control form-control-sm cf-dlg-campo" ' +
                    'rows="2" maxlength="' + max + '" ' +
                    'placeholder="' + escapar(opciones.placeholder || '') + '">' +
                    escapar(opciones.valor || '') +
                '</textarea>' +
                '<div class="d-flex justify-content-between align-items-center mt-1">' +
                    '<small class="invalid-feedback d-block cf-dlg-error"></small>' +
                    '<small class="text-muted cf-dlg-contador"></small>' +
                '</div>' +
            '</div>';

        return abrirDialogo(opciones, {
            cuerpo: cuerpo,
            foco: '.cf-dlg-campo',

            /* Devuelve el texto, o undefined para NO cerrar: un campo
               obligatorio vacío tiene que decir por qué en el mismo lugar donde
               se escribe, no cerrar y fallar después contra el servidor. */
            alConfirmar: function(modal) {
                var campo = modal.querySelector('.cf-dlg-campo');
                var v = String(campo.value || '').trim();

                if (v === '' && !opciones.opcional) {
                    campo.classList.add('is-invalid');
                    modal.querySelector('.cf-dlg-error').textContent =
                        opciones.invalido || 'Escribí el motivo: es lo único que después '
                            + 'explica esta decisión.';
                    campo.focus();

                    return undefined;
                }

                return v;
            },

            alCancelar: null,

            alAbrir: function(modal) {
                var campo = modal.querySelector('.cf-dlg-campo');
                var contador = modal.querySelector('.cf-dlg-contador');

                var pintar = function() {
                    contador.textContent = campo.value.length + '/' + max;
                    campo.classList.remove('is-invalid');
                };

                campo.addEventListener('input', pintar);
                pintar();
            }
        });
    }

    function elegirOpciones(opciones) {
        opciones = opciones || {};

        var lista = opciones.opciones || [];
        var minimo = opciones.minimo || 0;

        // Sin Bootstrap: un confirm por la acción entera, con lo marcado por
        // defecto. Es lo que menos sorprende -lo que el diálogo proponía-.
        if (!window.bootstrap || !bootstrap.Modal) {
            var marcadas = lista.filter(function(o) { return o.marcada; })
                .map(function(o) { return o.valor; });

            return Promise.resolve(
                (marcadas.length >= minimo && window.confirm(opciones.mensaje
                    + (opciones.detalle ? '\n\n' + opciones.detalle : ''))) ? marcadas : null);
        }

        var base = 'cf-dlg-opc-' + Math.random().toString(36).slice(2);
        var cuerpo =
            '<div class="mt-3">' +
                (opciones.etiqueta
                    ? '<div class="form-label form-label-sm">' + escapar(opciones.etiqueta) + '</div>'
                    : '') +
                '<div class="' + (opciones.enColumnas ? 'row row-cols-3 g-1' : '') + '">' +
                lista.map(function(o, i) {
                    return '<div class="' + (opciones.enColumnas ? 'col ' : '') + 'form-check">' +
                        '<input class="form-check-input cf-dlg-opcion" type="checkbox" ' +
                            'id="' + base + i + '" data-indice="' + i + '"' + (o.marcada ? ' checked' : '') + '>' +
                        '<label class="form-check-label" for="' + base + i + '">' + escapar(o.rotulo) +
                            (o.nota ? ' <small class="text-muted">' + escapar(o.nota) + '</small>' : '') +
                        '</label></div>';
                }).join('') +
                '</div>' +
                '<small class="invalid-feedback d-block cf-dlg-error"></small>' +
            '</div>';

        return abrirDialogo(opciones, {
            cuerpo: cuerpo,
            foco: '.cf-dlg-opcion',

            alConfirmar: function(modal) {
                var elegidas = [];

                modal.querySelectorAll('.cf-dlg-opcion').forEach(function(chk) {
                    if (chk.checked) {
                        elegidas.push(lista[parseInt(chk.dataset.indice, 10)].valor);
                    }
                });

                if (elegidas.length < minimo) {
                    modal.querySelector('.cf-dlg-error').textContent =
                        opciones.invalido || 'Elegí al menos ' + minimo + '.';
                    return undefined;
                }

                return elegidas;
            },
            alCancelar: null
        });
    }

    function pedirFecha(opciones) {
        opciones = opciones || {};

        /* Sin Bootstrap se cae al prompt del navegador, igual que los otros dos:
           es feo —por eso este diálogo existe— pero preguntar es lo que no puede
           faltar. Se pide en el mismo formato que viaja al backend en vez de en
           dd/mm/aaaa: convertir dos formatos a mano en el camino de respaldo es
           donde aparece el corrimiento de un día. */
        if (!window.bootstrap || !bootstrap.Modal) {
            var previo = window.prompt(
                opciones.mensaje + (opciones.detalle ? '\n\n' + opciones.detalle : '')
                    + '\n\nFecha (aaaa-mm-dd):',
                opciones.valor || '');

            if (previo === null) {
                return Promise.resolve(null);
            }

            previo = String(previo).trim();

            var sirve = esFecha(previo) && !(opciones.min && previo < opciones.min)
                && !(opciones.max && previo > opciones.max);

            if (!sirve) {
                return Promise.resolve(null);
            }

            return Promise.resolve(opciones.texto ? { fecha: previo, texto: '' } : previo);
        }

        var id = 'cf-dlg-fecha-' + Math.random().toString(36).slice(2);
        var t = opciones.texto || null;
        var maxTexto = (t && t.maxlargo) || 200;

        var cuerpo =
            '<div class="mt-3">' +
                '<label class="form-label form-label-sm" for="' + id + '">' +
                    escapar(opciones.etiqueta || 'Fecha') +
                '</label>' +
                '<input type="date" id="' + id + '" ' +
                    'class="form-control form-control-sm cf-dlg-fecha" ' +
                    (opciones.min ? 'min="' + escapar(opciones.min) + '" ' : '') +
                    (opciones.max ? 'max="' + escapar(opciones.max) + '" ' : '') +
                    'value="' + escapar(opciones.valor || '') + '">' +
                '<small class="invalid-feedback d-block cf-dlg-error"></small>' +
            '</div>' +
            (t
                ? '<div class="mt-2">' +
                      '<label class="form-label form-label-sm" for="' + id + '-t">' +
                          escapar(t.etiqueta || 'Observación') +
                          ' <span class="text-muted">(opcional)</span>' +
                      '</label>' +
                      '<textarea id="' + id + '-t" class="form-control form-control-sm cf-dlg-texto" ' +
                          'rows="2" maxlength="' + maxTexto + '" ' +
                          'placeholder="' + escapar(t.placeholder || '') + '"></textarea>' +
                  '</div>'
                : '');

        return abrirDialogo(opciones, {
            cuerpo: cuerpo,
            foco: '.cf-dlg-fecha',

            /* Igual que en pedirTexto(): undefined NO cierra. Un campo vacío
               tiene que decir por qué en el mismo lugar donde se completa, en
               vez de cerrar y fallar después contra el servidor.

               El formato se chequea aunque el input sea type="date": un
               navegador sin soporte lo degrada a texto, y ahí entra cualquier
               cosa. La validación que vale igual es la del backend. */
            alConfirmar: function(modal) {
                var campo = modal.querySelector('.cf-dlg-fecha');
                var v = String(campo.value || '').trim();

                if (!esFecha(v)) {
                    campo.classList.add('is-invalid');
                    modal.querySelector('.cf-dlg-error').textContent =
                        opciones.invalido || 'Elegí una fecha.';
                    campo.focus();

                    return undefined;
                }

                /* EL `min` SE VALIDA ACÁ, no sólo en el input. El atributo
                   limita el calendario, pero una fecha anterior tipeada a mano
                   pasa igual y el diálogo la devolvía como buena: el error
                   aparecía recién en el servidor, después de cerrar. Las
                   fechas viajan como 'aaaa-mm-dd', así que compararlas como
                   texto es compararlas como fechas. El servidor valida de
                   nuevo: esto explica y bloquea, no autoriza. */
                if (opciones.min && v < opciones.min) {
                    var p = String(opciones.min).split('-');

                    campo.classList.add('is-invalid');
                    modal.querySelector('.cf-dlg-error').textContent =
                        opciones.antesDelMin || ('La fecha no puede ser anterior al '
                            + p[2] + '/' + p[1] + '/' + p[0] + '.');
                    campo.focus();

                    return undefined;
                }

                // El `max`, igual que el `min`: el atributo limita el
                // calendario y deja tipear cualquier cosa.
                if (opciones.max && v > opciones.max) {
                    var q = String(opciones.max).split('-');

                    campo.classList.add('is-invalid');
                    modal.querySelector('.cf-dlg-error').textContent =
                        opciones.despuesDelMax || ('La fecha no puede ser posterior al '
                            + q[2] + '/' + q[1] + '/' + q[0] + '.');
                    campo.focus();

                    return undefined;
                }

                if (t) {
                    return { fecha: v, texto: String(modal.querySelector('.cf-dlg-texto').value || '').trim() };
                }

                return v;
            },

            alCancelar: null,

            alAbrir: function(modal) {
                var campo = modal.querySelector('.cf-dlg-fecha');

                campo.addEventListener('input', function() {
                    campo.classList.remove('is-invalid');
                });
            }
        });
    }

    /* ================================================================
       EL FORMULARIO
       ================================================================ */

    function formulario(opciones) {
        opciones = opciones || {};

        var base = 'cf-dlg-form-' + Math.random().toString(36).slice(2);
        var campos = opciones.campos || [];
        var grilla = opciones.renglones || null;

        // Sin Bootstrap no hay dónde poner varios campos: se dice y no se carga.
        if (!window.bootstrap || !bootstrap.Modal) {
            mostrar('error', 'No se puede abrir el formulario: falta Bootstrap en la página.');

            return Promise.resolve(null);
        }

        var cuerpo = '<div class="row g-2 mt-2">'
            + campos.map(function(c) { return campoHtml(c, base, null); }).join('')
            + '</div>';

        if (grilla) {
            cuerpo += '<div class="table-responsive mt-3"><table class="table table-sm mb-0 cf-dlg-grilla">'
                + '<thead><tr><th style="width: 32px;"></th>'
                + grilla.campos.map(function(c) {
                    return '<th class="small">' + escapar(c.etiqueta) + '</th>';
                }).join('')
                + '</tr></thead><tbody>';

            for (var i = 0; i < grilla.cantidad; i++) {
                cuerpo += '<tr data-renglon="' + i + '"><td class="small text-muted align-middle">'
                    + (i + 1) + '</td>'
                    + grilla.campos.map(function(c) {
                        var inicial = (grilla.valores && grilla.valores[i]
                                       && grilla.valores[i][c.nombre] !== undefined)
                            ? grilla.valores[i][c.nombre] : c.valor;
                        var conValor = {};

                        for (var k in c) { conValor[k] = c[k]; }
                        conValor.valor = inicial;

                        return '<td>' + campoHtml(conValor, base, i) + '</td>';
                    }).join('')
                    + '</tr>';
            }

            cuerpo += '</tbody></table></div>';
        }

        cuerpo += '<small class="invalid-feedback d-block cf-dlg-error mt-2"></small>';

        return abrirDialogo(opciones, {
            cuerpo: cuerpo,
            foco: '.cf-dlg-form-campo',

            alConfirmar: function(modal) {
                var error = modal.querySelector('.cf-dlg-error');
                var valores = {};
                var primero = null;

                error.textContent = '';

                var marcar = function(el, mensaje) {
                    el.classList.add('is-invalid');

                    if (!primero) {
                        primero = el;
                        error.textContent = mensaje;
                    }
                };

                campos.forEach(function(c) {
                    valores[c.nombre] = leerCampo(modal, c, base, null, marcar);
                });

                if (grilla) {
                    valores.renglones = [];

                    for (var i = 0; i < grilla.cantidad; i++) {
                        var r = {};
                        var vacio = true;

                        grilla.campos.forEach(function(c) {
                            var el = modal.querySelector('[data-campo="' + c.nombre + '"][data-renglon="' + i + '"]');

                            if (el && String(el.value || '').trim() !== '') {
                                vacio = false;
                            }
                        });

                        /* UN RENGLÓN ENTERO EN BLANCO NO SE VALIDA, salvo que la
                           grilla los pida todos: ahí el blanco es justamente lo
                           que falta. */
                        if (vacio && !grilla.obligatorios) {
                            continue;
                        }

                        grilla.campos.forEach(function(c) {
                            r[c.nombre] = leerCampo(modal, c, base, i, marcar);
                        });

                        valores.renglones.push(r);
                    }
                }

                if (!primero && typeof opciones.validar === 'function') {
                    var msg = opciones.validar(valores);

                    if (msg) {
                        error.textContent = msg;

                        return undefined;
                    }
                }

                if (primero) {
                    primero.focus();

                    return undefined;
                }

                return valores;
            },

            alCancelar: null,

            alAbrir: function(modal) {
                modal.querySelector('.modal-dialog').classList.add('modal-lg');

                modal.querySelectorAll('.cf-dlg-form-campo').forEach(function(el) {
                    el.addEventListener('input', function() {
                        el.classList.remove('is-invalid');
                    });
                });

                /* EL IMPORTE SE REESCRIBE CON EL FORMATO DEL MÓDULO AL SALIR del
                   campo, así se ve el número que se va a guardar. Mientras se
                   tipea no se toca: mover el cursor en el medio de una palabra
                   es la peor forma de ayudar. */
                modal.querySelectorAll('[data-tipo="importe"]').forEach(function(el) {
                    el.addEventListener('blur', function() {
                        var n = parsearImporte(el.value);

                        if (n !== null && !isNaN(n)) {
                            el.value = formatearImporte(n);
                        }
                    });
                });
            }
        });
    }

    /** El HTML de un campo, suelto o en un renglón de la grilla */
    function campoHtml(c, base, renglon) {
        var id = base + '-' + c.nombre + (renglon === null ? '' : '-' + renglon);
        var datos = ' data-campo="' + escapar(c.nombre) + '" data-tipo="' + escapar(c.tipo) + '"'
            + (renglon === null ? '' : ' data-renglon="' + renglon + '"');
        var valor = (c.valor === undefined || c.valor === null) ? '' : c.valor;
        var etiqueta = renglon === null
            ? '<label class="form-label form-label-sm mb-1" for="' + id + '">' + escapar(c.etiqueta)
              + (c.obligatorio ? '' : ' <span class="text-muted">(opcional)</span>') + '</label>'
            : '';
        var ayuda = (renglon === null && c.ayuda)
            ? '<div class="form-text">' + escapar(c.ayuda) + '</div>' : '';
        var control;

        if (c.tipo === 'casillas') {
            control = '<div class="cf-dlg-casillas border rounded p-2" style="max-height: 220px; overflow-y: auto;"'
                + datos + '>'
                + (c.opciones || []).map(function(o, i) {
                    return '<div class="d-flex align-items-center gap-2">'
                        + '<input class="form-check-input cf-dlg-casilla" type="checkbox" id="' + id + i + '"'
                        + ' data-indice="' + i + '"' + (o.marcada ? ' checked' : '') + '>'
                        + '<label class="form-check-label flex-grow-1 small" for="' + id + i + '">'
                        + escapar(o.rotulo)
                        + (o.nota ? ' <span class="text-muted">' + escapar(o.nota) + '</span>' : '')
                        + '</label>'
                        + (c.titular
                            ? '<label class="small text-nowrap"><input type="radio" class="form-check-input me-1 cf-dlg-titular" '
                              + 'name="' + id + '-titular" data-indice="' + i + '"'
                              + (o.titular ? ' checked' : '') + '>titular</label>'
                            : '')
                        + '</div>';
                }).join('')
                + '</div>';

            return '<div class="col-12">' + etiqueta + control + ayuda + '</div>';
        }

        if (c.tipo === 'lista') {
            control = '<select id="' + id + '" class="form-select form-select-sm cf-dlg-form-campo"' + datos + '>'
                + '<option value="">' + escapar(c.placeholder || 'Elegí…') + '</option>'
                + (c.opciones || []).map(function(o) {
                    return '<option value="' + escapar(o.valor) + '"'
                        + (String(o.valor) === String(valor) ? ' selected' : '') + '>'
                        + escapar(o.rotulo) + '</option>';
                }).join('')
                + '</select>';
        } else if (c.tipo === 'texto' && renglon === null && c.largo) {
            control = '<textarea id="' + id + '" class="form-control form-control-sm cf-dlg-form-campo" rows="2"'
                + datos + ' maxlength="' + c.largo + '" placeholder="' + escapar(c.placeholder || '') + '">'
                + escapar(valor) + '</textarea>';
        } else {
            var tipo = {mes: 'month', fecha: 'date', numero: 'number'}[c.tipo] || 'text';

            control = '<input type="' + tipo + '" id="' + id + '" class="form-control form-control-sm cf-dlg-form-campo'
                + (c.tipo === 'importe' ? ' text-end' : '') + '"' + datos
                + (c.tipo === 'importe' ? ' inputmode="decimal" autocomplete="off"' : '')
                + (c.tipo === 'mes' ? ' placeholder="aaaa-mm"' : '')
                + (c.placeholder ? ' placeholder="' + escapar(c.placeholder) + '"' : '')
                + ' value="' + escapar(c.tipo === 'importe' && valor !== '' ? formatearImporte(valor) : valor) + '">';
        }

        return renglon === null
            ? '<div class="col-md-' + (c.ancho || 6) + '">' + etiqueta + control + ayuda + '</div>'
            : control;
    }

    /**
     * Lee y valida un campo. Marca el que está mal y devuelve el valor ya
     * convertido: número para un importe, 'aaaa-mm' para un mes.
     */
    function leerCampo(modal, c, base, renglon, marcar) {
        var sel = '[data-campo="' + c.nombre + '"]' + (renglon === null ? ':not([data-renglon])'
            : '[data-renglon="' + renglon + '"]');
        var el = modal.querySelector(sel);
        var donde = (renglon === null) ? '' : ' (renglón ' + (renglon + 1) + ')';

        if (!el) { return null; }

        if (c.tipo === 'casillas') {
            var elegidas = [];
            var titular = null;
            var opciones = c.opciones || [];

            el.querySelectorAll('.cf-dlg-casilla').forEach(function(chk) {
                if (chk.checked) { elegidas.push(opciones[parseInt(chk.dataset.indice, 10)].valor); }
            });

            el.querySelectorAll('.cf-dlg-titular').forEach(function(r) {
                if (r.checked) { titular = opciones[parseInt(r.dataset.indice, 10)].valor; }
            });

            if (c.obligatorio && !elegidas.length) {
                marcar(el, 'Elegí al menos una en «' + c.etiqueta + '».');
            } else if (c.titular && elegidas.length > 1
                       && (titular === null || elegidas.indexOf(titular) === -1)) {
                marcar(el, 'Marcá cuál es la titular, entre las elegidas.');
            }

            return c.titular ? {elegidas: elegidas, titular: elegidas.length === 1 ? elegidas[0] : titular}
                             : elegidas;
        }

        var v = String(el.value || '').trim();

        if (v === '') {
            if (c.obligatorio) {
                marcar(el, 'Falta «' + c.etiqueta + '»' + donde + '.');
            }

            return null;
        }

        if (c.tipo === 'importe') {
            var n = parsearImporte(v);

            if (n === null || isNaN(n)) {
                marcar(el, '«' + c.etiqueta + '»' + donde + ' no es un importe: escribilo como 1.234.567,89.');

                return null;
            }

            if (c.positivo && n <= 0) {
                marcar(el, '«' + c.etiqueta + '»' + donde + ' tiene que ser mayor a cero. Si no '
                    + 'corresponde, dejalo vacío.');
            }

            return n;
        }

        if (c.tipo === 'numero') {
            var entero = Number(v);

            if (!/^-?\d+$/.test(v)
                || (c.min !== undefined && entero < c.min) || (c.max !== undefined && entero > c.max)) {
                marcar(el, '«' + c.etiqueta + '»' + donde + ' tiene que ser un número entero'
                    + (c.min !== undefined ? ' de ' + c.min : '')
                    + (c.max !== undefined ? ' a ' + c.max : '') + '.');

                return null;
            }

            return entero;
        }

        if (c.tipo === 'mes' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(v)) {
            marcar(el, '«' + c.etiqueta + '»' + donde + ' tiene que ser un mes, aaaa-mm.');
        }

        if (c.tipo === 'fecha' && !esFecha(v)) {
            marcar(el, '«' + c.etiqueta + '»' + donde + ' no es una fecha válida.');
        }

        return v;
    }

    /**
     * '1.234.567,89' => 1234567.89. null si está vacío, NaN si no es un número.
     *
     * LA COMA ES EL DECIMAL Y EL PUNTO EL MILES, que es como se lee todo el
     * módulo. Sin coma, un punto seguido de uno o dos dígitos al final se toma
     * como decimal ('320.50' en un importe en dólares), y grupos de tres como
     * miles ('1.500' son mil quinientos): son las dos formas en que alguien lo
     * tipea sin pensar.
     */
    function parsearImporte(texto) {
        var t = String(texto === null || texto === undefined ? '' : texto)
            .replace(/\$/g, '').replace(/U\$S/gi, '').replace(/\s/g, '');

        if (t === '') { return null; }

        if (t.indexOf(',') !== -1) {
            t = t.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) {
            t = t.replace(/\./g, '');
        }

        if (!/^-?\d+(\.\d+)?$/.test(t)) { return NaN; }

        return parseFloat(t);
    }

    /** 1234567.891 => '1.234.567,89'. '' para null */
    function formatearImporte(n) {
        if (n === null || n === undefined || n === '' || isNaN(Number(n))) { return ''; }

        var centavos = Math.round(Math.abs(Number(n)) * 100);
        var entero = String(Math.floor(centavos / 100));
        var dec = String(centavos % 100);

        if (dec.length < 2) { dec = '0' + dec; }

        var miles = '';

        while (entero.length > 3) {
            miles = '.' + entero.substring(entero.length - 3) + miles;
            entero = entero.substring(0, entero.length - 3);
        }

        return (Number(n) < 0 ? '-' : '') + entero + miles + ',' + dec;
    }

    /** 'aaaa-mm-dd' y que exista en el calendario */
    function esFecha(v) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
            return false;
        }

        // Se compara contra lo que devuelve Date: así el 31 de febrero, que
        // JavaScript corre solo al 3 de marzo, no pasa como válido.
        var p = v.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));

        return d.getFullYear() === Number(p[0])
            && d.getMonth() === Number(p[1]) - 1
            && d.getDate() === Number(p[2]);
    }

    /**
     * El armazón que comparten los tres.
     *
     * Está escrito una vez porque lo delicado no es el HTML: es que cerrar con
     * la cruz, con Escape o clickeando afuera TAMBIÉN sea una respuesta, y que
     * sea la negativa. Dos copias de eso se desincronizan en la primera
     * corrección.
     *
     * @param {Object} opciones Las del llamador
     * @param {Object} pieza {cuerpo, alConfirmar, alCancelar, foco, alAbrir}
     * @returns {Promise}
     */
    function abrirDialogo(opciones, pieza) {
        var textoCancelar = opciones.cancelar || 'Cancelar';
        var textoConfirmar = opciones.confirmar || 'Confirmar';

        return new Promise(function(resolver) {
            var modal = document.createElement('div');

            modal.className = 'modal fade cf-confirmar';
            modal.setAttribute('tabindex', '-1');
            modal.innerHTML =
                '<div class="modal-dialog modal-dialog-centered modal-sm-plus">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<h5 class="modal-title">' +
                                '<i class="fas ' +
                                    (opciones.peligro ? 'fa-triangle-exclamation text-danger'
                                                      : 'fa-circle-question text-primary') +
                                    ' me-2"></i>' +
                                escapar(opciones.titulo || 'Confirmar') +
                            '</h5>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" ' +
                                'aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<p class="mb-0">' + escapar(opciones.mensaje || '') + '</p>' +
                            (opciones.detalle
                                ? '<p class="cf-confirmar-detalle mt-2 mb-0">' +
                                  escapar(opciones.detalle) + '</p>'
                                : '') +
                            (opciones.html || '') +
                            pieza.cuerpo +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" ' +
                                'data-bs-dismiss="modal">' + escapar(textoCancelar) + '</button>' +
                            '<button type="button" class="btn btn-sm ' +
                                (opciones.peligro ? 'btn-danger' : 'btn-primary') +
                                ' cf-confirmar-si">' + escapar(textoConfirmar) + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);

            var instancia = new bootstrap.Modal(modal);
            var respuesta = pieza.alCancelar;

            modal.querySelector('.cf-confirmar-si').addEventListener('click', function() {
                var r = pieza.alConfirmar(modal);

                // undefined = la validación dijo que no, y el diálogo se queda
                // abierto con el error a la vista.
                if (r === undefined) {
                    return;
                }

                respuesta = r;
                instancia.hide();
            });

            modal.addEventListener('shown.bs.modal', function() {
                /* El foco arranca en Cancelar cuando la respuesta es sí o no: es
                   una acción que cuesta deshacer y un Enter reflejo tiene que no
                   hacer nada. Cuando hay un campo que completar, arranca en el
                   campo: ahí el Enter no llega al botón de confirmar. */
                var destino = pieza.foco
                    ? modal.querySelector(pieza.foco)
                    : modal.querySelector('[data-bs-dismiss="modal"].btn');

                if (destino) {
                    destino.focus();
                }
            });

            // Se resuelve acá y no en cada botón: cerrar con la cruz, con Escape
            // o clickeando afuera también es una respuesta, y es que no.
            modal.addEventListener('hidden.bs.modal', function() {
                instancia.dispose();

                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }

                resolver(respuesta);
            });

            if (pieza.alAbrir) {
                pieza.alAbrir(modal);
            }

            instancia.show();
        });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /** Los mensajes traen texto del servidor: nunca se inyecta como HTML */
    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    return api;
})();
