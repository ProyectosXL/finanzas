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

    /**
     * El armazón que comparten los dos.
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
                   hacer nada. Cuando hay algo que escribir, arranca en el campo:
                   ahí el Enter no confirma, escribe. */
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
