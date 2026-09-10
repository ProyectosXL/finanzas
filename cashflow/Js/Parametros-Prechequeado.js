/**
 * Parámetros → Pre-chequeado
 *
 * Vive aparte de Parametros.js, igual que Parametros-Saldos.js y
 * Parametros-Cob-Electronicos.js: no comparte estado con los bloques de Ventas,
 * así que un problema acá no puede llevarse puesta la pestaña que ya funciona.
 * Pide su propio payload al mismo endpoint y se queda con el módulo
 * PRECHEQUEADO.
 *
 * DOS REGLAS QUE ESTA PANTALLA TIENE QUE HACER VISIBLES:
 *
 *   1. El código se valida contra el maestro de clientes de Tango ANTES de
 *      guardar, y el botón de agregar sigue bloqueado hasta que la búsqueda
 *      encuentre algo. Un código tipeado mal no da error: da una lista vacía en
 *      la otra pantalla y nadie entiende por qué.
 *   2. Cuántos cheques vivos trae hoy cada cliente. Un cero es lo único que
 *      delata un código que existe pero no es el que se quería cargar.
 *
 * La validación que vale es la del servidor; acá se espeja sólo para bloquear el
 * botón y explicar el motivo antes de guardar.
 */

(function() {
    'use strict';

    var URL_PARAM = 'Controller/ParametrosController.php';

    var modulo = null;

    /** El cliente que devolvió la última búsqueda, o null */
    var encontrado = null;

    function inicializar() {
        conectar('btnRefreshParamPpq', cargar);
        conectar('btnNuevoClientePpq', function() { alternar('formClientePpq'); });
        conectar('btnCancelarClientePpq', function() { mostrar('formClientePpq', false); });
        conectar('btnBuscarClientePpq', buscar);
        conectar('btnAgregarClientePpq', agregar);

        var codigo = document.getElementById('nuevoCodigoPpq');

        if (codigo) {
            // Cambiar el código invalida la búsqueda anterior: si no, se podría
            // guardar un código distinto del que se buscó.
            codigo.addEventListener('input', olvidarBusqueda);
            codigo.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    buscar();
                }
            });
        }

        cargar();
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
        mostrar('loadingParamPpq', true, 'flex');
        mostrar('wrapperParamPpq', false);

        pedirJson(URL_PARAM + '?action=getTodo')
            .then(function(data) {
                modulo = buscarModulo(data, 'PRECHEQUEADO');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo PRECHEQUEADO');
                }

                pintarDescripcion();
                pintarAvisos();
                pintarClientes();

                mostrar('loadingParamPpq', false);
                mostrar('wrapperParamPpq', true);
            })
            .catch(function(error) {
                mostrar('loadingParamPpq', false);
                avisar('Error al cargar los clientes pre-chequeados: ' + error.message);
            });
    }

    function buscarModulo(data, codigo) {
        if (!data || !data.modulos) {
            return null;
        }

        for (var i = 0; i < data.modulos.length; i++) {
            if (data.modulos[i].codigo === codigo) {
                return data.modulos[i];
            }
        }

        return null;
    }

    function pintarDescripcion() {
        var cont = document.getElementById('descripcionPpq');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + escapar(modulo.descripcion);
        }
    }

    /** Si el script SQL del módulo no se corrió, se avisa en vez de romper */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParamPpq');
        var avisos = modulo.avisos || [];

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? avisos.map(function(a) {
                  return '<div class="alert alert-warning py-2 px-3 mb-3">' +
                         '<i class="fas fa-triangle-exclamation me-1"></i><small>' +
                         escapar(a) + '</small></div>';
              }).join('')
            : '';
    }

    /* ================================================================
       LISTADO
       ================================================================ */

    function pintarClientes() {
        var clientes = modulo.prechequeado || [];

        document.getElementById('bodyClientesPpq').innerHTML = clientes.length
            ? clientes.map(filaCliente).join('')
            : '<tr><td colspan="5" class="text-center text-muted py-4">' +
              'Todavía no hay clientes cargados. Mientras tanto, la sub-pestaña Venta Cobrada ' +
              'Anticipada se muestra vacía y no se netea nada de Ventas.</td></tr>';

        document.querySelectorAll('.ppq-activo').forEach(function(c) {
            c.addEventListener('change', function() {
                cambiarEstado(c.dataset.codigo, c.checked, c);
            });
        });
    }

    function filaCliente(c) {
        var activo = (parseInt(c.ACTIVO, 10) === 1);
        var vivos = parseInt(c.CHEQUES_VIVOS, 10) || 0;

        return '<tr class="' + (activo ? '' : 'ppq-inactivo') + '">' +
            '<td class="fw-semibold">' + escapar(c.CLIENTE) + '</td>' +
            '<td>' + escapar(c.RAZON_SOCIAL || '—') + '</td>' +
            '<td class="text-center">' + celdaCheques(vivos, activo) + '</td>' +
            '<td class="text-center ppq-fecha">' +
                (c.FECHA_UPDATE ? fechaHora(c.FECHA_UPDATE) : '—') +
                subtituloUsuario(c.USUARIO) +
            '</td>' +
            '<td class="text-center">' +
                '<div class="form-check form-switch d-inline-block">' +
                    '<input class="form-check-input ppq-activo" type="checkbox" role="switch" ' +
                        'data-codigo="' + escapar(c.CLIENTE) + '"' + (activo ? ' checked' : '') +
                        ' title="' + (activo
                            ? 'Darlo de baja saca sus cheques del listado y del neteo. No se ' +
                              'borra nada: las marcas por cheque quedan.'
                            : 'Reactivarlo vuelve a traer sus cheques, ya tildados.') + '">' +
                '</div>' +
            '</td>' +
        '</tr>';
    }

    /**
     * Un cliente activo sin cheques vivos se marca: es lo único que delata un
     * código que existe en Tango pero no es el que se quería cargar.
     */
    function celdaCheques(vivos, activo) {
        if (!activo) {
            return '<span class="text-muted">—</span>';
        }

        if (vivos === 0) {
            return '<span class="ppq-sin-cheques" title="Este cliente no tiene ningún cheque con ' +
                   'fecha de hoy en adelante. Puede ser normal, o puede ser un código que no es ' +
                   'el que se quería cargar.">sin cheques</span>';
        }

        return '<span class="fw-semibold">' + vivos + '</span>';
    }

    function subtituloUsuario(usuario) {
        return '<div class="ppq-subtitulo">' +
               escapar(usuario || 'sin usuario') + '</div>';
    }

    /* ================================================================
       ALTA Y BAJA
       ================================================================ */

    function olvidarBusqueda() {
        encontrado = null;
        setValor('razonSocialPpq', '');
        habilitar('btnAgregarClientePpq', false);
    }

    function buscar() {
        var codigo = valor('nuevoCodigoPpq');

        if (!codigo) {
            avisar('Ingresá el código del cliente.');
            return;
        }

        conBotonEl(document.getElementById('btnBuscarClientePpq'), function() {
            return pedirJson(URL_PARAM + '?action=buscarClientePrecheq&codigo='
                    + encodeURIComponent(codigo))
                .then(function(data) {
                    if (!data || !data.encontrado) {
                        encontrado = null;
                        setValor('razonSocialPpq', '');
                        habilitar('btnAgregarClientePpq', false);
                        avisar('El código "' + codigo + '" no existe en el maestro de clientes de '
                             + 'Tango. Ojo con las mayúsculas: la comparación las distingue.');
                        return;
                    }

                    encontrado = data;
                    setValor('razonSocialPpq', data.razon_social);
                    habilitar('btnAgregarClientePpq', true);
                });
        }, 'No se pudo buscar el cliente');
    }

    function agregar() {
        var codigo = valor('nuevoCodigoPpq');

        // El botón está bloqueado hasta que la búsqueda encuentre algo, pero la
        // validación que vale es la del servidor: vuelve a buscar el código
        // antes de guardarlo.
        if (!encontrado || encontrado.codigo !== codigo) {
            avisar('Buscá el código primero: se guarda la razón social que devuelve Tango.');
            return;
        }

        conBotonEl(document.getElementById('btnAgregarClientePpq'), function() {
            return pedirJson(URL_PARAM + '?action=addClientePrecheq', { codigo: codigo })
                .then(function(data) {
                    setValor('nuevoCodigoPpq', '');
                    olvidarBusqueda();
                    mostrar('formClientePpq', false);

                    // Se dice cuántos cheques trajo: si es cero, el código
                    // existe pero probablemente no es el que se quería.
                    alert((data.reactivado ? 'Cliente reactivado: ' : 'Cliente agregado: ')
                        + data.cliente + ' - ' + data.razon_social + '. '
                        + (data.cheques_vivos > 0
                            ? 'Trae ' + data.cheques_vivos + ' cheque(s) vivos, que ya aparecen '
                              + 'tildados en Echeqs → Venta Cobrada Anticipada.'
                            : 'Hoy no tiene ningún cheque vivo. Revisá que el código sea el que '
                              + 'buscabas.'));

                    cargar();
                });
        }, 'No se pudo agregar el cliente');
    }

    function cambiarEstado(codigo, activo, checkbox) {
        var accion = activo ? 'addClientePrecheq' : 'bajaClientePrecheq';

        if (!activo && !confirm('¿Dar de baja a ' + codigo + '? Sus cheques dejan de aparecer y '
                + 'dejan de netear la cobranza de Ventas. No se borra nada.')) {
            checkbox.checked = true;
            return;
        }

        checkbox.disabled = true;

        pedirJson(URL_PARAM + '?action=' + accion, { codigo: codigo })
            .then(function() {
                cargar();
            })
            .catch(function(error) {
                // Reversión: dejar el switch como quedó haría creer que se
                // guardó un cambio que el tablero no va a ver.
                checkbox.checked = !activo;
                checkbox.disabled = false;
                avisar('No se pudo cambiar el estado, así que se dejó como estaba: '
                    + error.message);
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /** Corre una acción mostrando el estado en un botón */
    function conBotonEl(btn, accion, mensajeError) {
        if (!btn) {
            return;
        }

        var original = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        accion()
            .catch(function(error) {
                avisar(mensajeError + ': ' + error.message);
            })
            .then(function() {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    }

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function alternar(id) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = (el.style.display === 'none' || !el.style.display)
                ? 'block' : 'none';
        }
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function habilitar(id, activo) {
        var el = document.getElementById(id);

        if (el) {
            el.disabled = !activo;
        }
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? String(el.value).trim() : '';
    }

    function setValor(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.value = v;
        }
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fechaCorta(v) {
        if (!v) {
            return '—';
        }

        var p = String(v).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(v);
    }

    function fechaHora(v) {
        var s = String(v);

        return fechaCorta(s) + (s.length > 10 ? (' ' + s.slice(11, 16)) : '');
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function avisar(mensaje) {
        console.error(mensaje);
        alert(mensaje);
    }

})(); // Fin del IIFE
