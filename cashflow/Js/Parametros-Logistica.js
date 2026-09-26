/**
 * Parametros -> Logistica
 *
 * El maestro de fleteros: alta contra CPA01, las horas, el valor hora base y el
 * mes base.
 *
 * EL ALTA ES LA MISMA QUE LA DEL MAESTRO DE PROVEEDORES LOCALES
 * -------------------------------------------------------------
 * Se busca por codigo O por nombre y se elige de la lista, porque quien carga
 * un proveedor casi nunca se acuerda del codigo: se acuerda del nombre. El
 * nombre sale de CPA01 y no se tipea; si se pudiera tipear, dos pantallas
 * mostrarian dos nombres para el mismo codigo y ninguno seria "el nombre del
 * proveedor".
 *
 * QUE APAREZCA EN EL BUSCADOR NO AUTORIZA NADA: el alta vuelve a chequear el
 * codigo contra CPA01 en el servidor.
 *
 * SE GUARDA CONTRA EL CONTROLLER DEL MODULO Y NO CONTRA ParametrosController
 * --------------------------------------------------------------------------
 * La misma tabla se escribe tambien desde la planilla de Logistica Local. Dos
 * endpoints escribiendo lo mismo se desincronizan en la primera validacion que
 * alguien agregue de un solo lado. Es el mismo criterio con el que el modulo
 * CASHFLOW declara su propio endpoint.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/LogisticaController.php';
    var PARAMETROS = 'Controller/ParametrosController.php';

    /** Desde cuantas letras se busca. El backend lo confirma en el payload */
    var MIN_BUSQUEDA = 2;

    var fleteros = [];
    var tablaCreada = false;
    var tangoDisponible = false;
    var elegido = null;
    var debounce = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        /* La descripcion sale del payload de Parametros, que es donde esta
           declarado el modulo; los datos salen del controller de Logistica.
           Son dos pedidos porque son dos responsabilidades, y el de la
           descripcion no puede hacer fallar al de los datos. */
        pedir(PARAMETROS + '?action=getTodo')
            .then(function(d) {
                var modulo = buscarModulo(d.data, 'LOGISTICA');
                var desc = document.getElementById('plogDescripcion');

                if (desc && modulo && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' +
                        esc(modulo.descripcion);
                }
            })
            .catch(function() { /* La descripcion es contexto, no dato: si no
                                   llega, la pantalla sirve igual. */ });

        pedir(ENDPOINT + '?action=getFleteros')
            .then(function(d) {
                fleteros = d.data.filas || [];
                tablaCreada = !!d.data.tabla_creada;
                tangoDisponible = !!d.data.tango_disponible;
                MIN_BUSQUEDA = d.data.min_busqueda || MIN_BUSQUEDA;

                var avisos = [];

                if (d.data.aviso) { avisos.push(d.data.aviso); }

                if (!tangoDisponible) {
                    avisos.push('No se puede leer CPA01, así que no se pueden dar de alta ' +
                        'fleteros ni refrescar sus nombres. Los que ya están cargados se ven ' +
                        'con el nombre guardado.');
                }

                avisar(avisos);
                pintar();
            })
            .catch(function(e) {
                avisar(['No se pudieron leer los fleteros: ' + e.message]);
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
        var cuerpo = document.getElementById('plogBody');
        var btnNuevo = document.getElementById('plogBtnNuevo');

        if (btnNuevo) {
            btnNuevo.disabled = !tablaCreada || !tangoDisponible;
            btnNuevo.title = btnNuevo.disabled
                ? 'Falta correr sql/cashflow_logistica_fleteros.sql, o CPA01 no responde'
                : '';
        }

        if (!cuerpo) { return; }

        if (!fleteros.length) {
            cuerpo.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">' +
                (tablaCreada
                    ? 'Todavía no hay ningún fletero cargado. Agregalos con el botón de arriba.'
                    : 'Falta correr sql/cashflow_logistica_fleteros.sql contra la base central.') +
                '</td></tr>';

            return;
        }

        cuerpo.innerHTML = fleteros.map(function(f) {
            /* UN FLETERO QUE YA NO ESTA EN CPA01 SE MARCA. No se da de baja
               solo: puede ser un código que Tango depuró, y decidir eso es de
               una persona. Mismo criterio que ProveedoresTango::faltantes(). */
            var fueraDeTango = (f.NOMBRE_TANGO === null)
                ? ' <span class="badge bg-danger ms-1" title="Este código ya no está en CPA01, ' +
                  'o CPA01 no se pudo leer">fuera de Tango</span>' : '';

            /* LO QUE LE FALTA PARA PROYECTAR, dicho en la fila: un fletero
               cargado y sin horas se ve igual que uno completo si no se marca,
               y su mes aparece vacío en la planilla sin que acá nada lo
               anticipe. */
            var falta = [];

            if (f.HORAS_MES === null) { falta.push('horas'); }
            if (f.VALOR_HORA_BASE === null) { falta.push('valor hora'); }
            if (f.MES_BASE === null) { falta.push('mes base'); }

            var marcaFalta = (falta.length && f.ACTIVO)
                ? '<div><small class="text-warning-emphasis">' +
                  '<i class="fas fa-triangle-exclamation me-1"></i>No se proyecta: falta ' +
                  esc(falta.join(', ')) + '</small></div>' : '';

            return '<tr class="' + (f.ACTIVO ? '' : 'text-muted') + '">' +
                '<td>' +
                    '<span class="fw-semibold">' + esc(f.NOMBRE || f.COD_PROVEE) + '</span>' +
                    fueraDeTango +
                    '<div><small class="text-muted">' + esc(f.COD_PROVEE) + '</small></div>' +
                    marcaFalta +
                '</td>' +
                celdaNum(f.COD_PROVEE, 'horas', f.HORAS_MES, '0.5') +
                celdaNum(f.COD_PROVEE, 'valor_hora', f.VALOR_HORA_BASE, '0.01') +
                '<td class="text-center">' +
                    '<input type="month" class="form-control form-control-sm plog-input" ' +
                        'data-cod="' + esc(f.COD_PROVEE) + '" data-campo="mes_base" ' +
                        'value="' + (f.MES_BASE === null ? '' : esc(f.MES_BASE)) + '"' +
                        (tablaCreada ? '' : ' disabled') + '>' +
                '</td>' +
                '<td class="text-center">' +
                    '<div class="form-check form-switch d-inline-block">' +
                        '<input class="form-check-input plog-activo" type="checkbox" ' +
                            'data-cod="' + esc(f.COD_PROVEE) + '"' +
                            (f.ACTIVO ? ' checked' : '') +
                            (tablaCreada ? '' : ' disabled') + '>' +
                    '</div>' +
                '</td>' +
                '<td><small class="text-muted">' +
                    esc((f.FECHA_UPDATE || '').substring(0, 16)) +
                    (f.USUARIO ? ' — ' + esc(f.USUARIO) : '') +
                    (f.ACTIVO ? '' : '<div>De baja el ' +
                        esc((f.FECHA_BAJA || '').substring(0, 16)) + '</div>') +
                '</small></td>' +
            '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.plog-input').forEach(function(input) {
            input.addEventListener('change', function() { guardarCampo(input); });
        });

        cuerpo.querySelectorAll('.plog-activo').forEach(function(chk) {
            chk.addEventListener('change', function() {
                activar(chk.getAttribute('data-cod'), chk.checked);
            });
        });
    }

    function celdaNum(cod, campo, valor, paso) {
        return '<td class="text-end">' +
            '<input type="number" step="' + paso + '" min="0" ' +
                'class="form-control form-control-sm text-end plog-input" ' +
                'data-cod="' + esc(cod) + '" data-campo="' + campo + '" ' +
                'value="' + (valor === null ? '' : esc(valor)) + '"' +
                (tablaCreada ? '' : ' disabled') + '>' +
        '</td>';
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardarCampo(input) {
        var cod = input.getAttribute('data-cod');
        var campo = input.getAttribute('data-campo');
        var f = porCodigo(cod);

        if (!f) { return; }

        if (campo !== 'mes_base' && input.value !== '') {
            var n = Number(input.value);

            if (isNaN(n) || n <= 0) {
                Notificacion.campoInvalido(input,
                    'Tiene que ser un número mayor a 0. Un cero significaría que no se le paga, '
                    + 'y eso se expresa dando de baja al fletero.');

                return;
            }
        }

        /* SE MANDAN LOS TRES CAMPOS, no sólo el que cambió: el guardado escribe
           la fila entera, así que mandar uno solo borraría los otros dos. */
        pedir(ENDPOINT + '?action=saveFletero', {
            cod_provee: cod,
            horas: campo === 'horas' ? input.value : f.HORAS_MES,
            valor_hora: campo === 'valor_hora' ? input.value : f.VALOR_HORA_BASE,
            mes_base: campo === 'mes_base' ? input.value : f.MES_BASE
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

    function activar(cod, activo) {
        pedir(ENDPOINT + '?action=activarFletero', { cod_provee: cod, activo: activo })
            .then(function(d) {
                Notificacion.exito(d.message || 'Listo.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo cambiar el estado: ' + e.message);
                cargar();
            });
    }

    function porCodigo(cod) {
        for (var i = 0; i < fleteros.length; i++) {
            if (fleteros[i].COD_PROVEE === cod) { return fleteros[i]; }
        }

        return null;
    }

    /* ================================================================
       EL ALTA: BUSCADOR CONTRA CPA01
       ================================================================ */

    function mostrarForm(visible) {
        var form = document.getElementById('plogFormNuevo');

        if (form) { form.style.display = visible ? '' : 'none'; }

        if (!visible) { limpiarForm(); }
    }

    function limpiarForm() {
        elegido = null;

        valor('plogBuscar', '');
        valor('plogHoras', '');
        valor('plogValor', '');
        valor('plogMesBase', '');
        ocultarSugerencias();

        var pie = document.getElementById('plogElegido');

        if (pie) {
            pie.className = 'form-text';
            pie.textContent = 'El nombre sale de CPA01 y no se edita.';
        }

        var btn = document.getElementById('plogBtnAgregar');

        if (btn) { btn.disabled = true; }
    }

    function buscarEnTango() {
        var input = document.getElementById('plogBuscar');

        if (!input) { return; }

        var q = input.value.trim();

        /* SE ELIGE DE LA LISTA: tipear no alcanza. Mientras no haya un elegido,
           el botón queda apagado. Así no se puede dar de alta un código que
           nadie verificó. */
        elegido = null;

        var btn = document.getElementById('plogBtnAgregar');

        if (btn) { btn.disabled = true; }

        if (q.length < MIN_BUSQUEDA) {
            ocultarSugerencias();

            return;
        }

        clearTimeout(debounce);

        debounce = setTimeout(function() {
            pedir(ENDPOINT + '?action=buscarProveedorTango&q=' + encodeURIComponent(q))
                .then(function(d) { pintarSugerencias(d.data.filas || []); })
                .catch(function(e) {
                    pintarSugerencias([]);
                    Notificacion.error('No se pudo buscar en Tango: ' + e.message);
                });
        }, 250);
    }

    function pintarSugerencias(filas) {
        var caja = document.getElementById('plogSugerencias');

        if (!caja) { return; }

        if (!filas.length) {
            caja.innerHTML = '<div class="list-group-item small text-muted">' +
                'Ningún proveedor de Tango coincide.</div>';
            caja.style.display = '';

            return;
        }

        caja.innerHTML = filas.map(function(p) {
            return '<button type="button" class="list-group-item list-group-item-action small" ' +
                'data-cod="' + esc(p.COD_PROVEE) + '" data-nom="' + esc(p.NOM_PROVEE) + '">' +
                '<span class="fw-semibold">' + esc(p.NOM_PROVEE) + '</span>' +
                ' <span class="text-muted">' + esc(p.COD_PROVEE) + '</span>' +
            '</button>';
        }).join('');

        caja.style.display = '';

        caja.querySelectorAll('button').forEach(function(b) {
            b.addEventListener('click', function() {
                elegir(b.getAttribute('data-cod'), b.getAttribute('data-nom'));
            });
        });
    }

    function elegir(cod, nombre) {
        elegido = { cod: cod, nombre: nombre };

        valor('plogBuscar', nombre + ' (' + cod + ')');
        ocultarSugerencias();

        var pie = document.getElementById('plogElegido');

        if (pie) {
            pie.className = 'form-text text-success';
            pie.textContent = 'Elegido: ' + nombre + ' (' + cod + '). El nombre sale de CPA01.';
        }

        var btn = document.getElementById('plogBtnAgregar');

        if (btn) { btn.disabled = false; }

        var yaEsta = porCodigo(cod);

        if (yaEsta) {
            Notificacion.info(yaEsta.ACTIVO
                ? 'Ese proveedor ya está cargado como fletero: guardar va a corregir sus datos.'
                : 'Ese proveedor estaba dado de baja: guardar lo reactiva.');

            valor('plogHoras', yaEsta.HORAS_MES === null ? '' : yaEsta.HORAS_MES);
            valor('plogValor', yaEsta.VALOR_HORA_BASE === null ? '' : yaEsta.VALOR_HORA_BASE);
            valor('plogMesBase', yaEsta.MES_BASE === null ? '' : yaEsta.MES_BASE);
        }
    }

    function ocultarSugerencias() {
        var caja = document.getElementById('plogSugerencias');

        if (caja) { caja.style.display = 'none'; }
    }

    function agregar() {
        if (!elegido) {
            Notificacion.error('Elegí un proveedor de la lista.');

            return;
        }

        pedir(ENDPOINT + '?action=saveFletero', {
            cod_provee: elegido.cod,
            horas: texto('plogHoras'),
            valor_hora: texto('plogValor'),
            mes_base: texto('plogMesBase')
        })
            .then(function(d) {
                Notificacion.exito(d.message || 'Fletero guardado.');
                mostrarForm(false);
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar el fletero: ' + e.message);
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function avisar(lista) {
        var cont = document.getElementById('plogAvisos');

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
        enganchar('plogBtnRefresh', 'click', cargar);
        enganchar('plogBtnNuevo', 'click', function() { mostrarForm(true); });
        enganchar('plogBtnCancelar', 'click', function() { mostrarForm(false); });
        enganchar('plogBtnAgregar', 'click', agregar);
        enganchar('plogBuscar', 'input', buscarEnTango);

        // Cerrar el desplegable al hacer clic afuera.
        document.addEventListener('click', function(ev) {
            var caja = document.getElementById('plogSugerencias');
            var input = document.getElementById('plogBuscar');

            if (caja && input && !caja.contains(ev.target) && ev.target !== input) {
                ocultarSugerencias();
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
