/**
 * Parámetros → Prov. Locales
 *
 * Vive aparte de Parametros.js, igual que Parametros-Saldos.js y compañía: no
 * comparte estado con los bloques de Ventas, así que un problema acá no puede
 * llevarse puesta la pestaña que ya funciona. Pide su propio payload al mismo
 * endpoint y se queda con el módulo PROV_LOCALES.
 *
 * QUÉ ADMINISTRA
 * Las cinco listas de valores con las que se clasifica a cada proveedor local.
 * Antes eran texto libre con un datalist de sugerencias.
 *
 * TRES COSAS QUE ESTA PANTALLA TIENE QUE HACER VISIBLES:
 *
 *   1. CUÁNTOS PROVEEDORES usan cada valor. Sin ese número, dar de baja es a
 *      ciegas: no se sabe si se saca una opción que no usa nadie o una que
 *      tienen doscientos, que van a quedar todos marcados como fuera de lista.
 *      Es la misma razón por la que Pre-chequeado muestra los cheques vivos.
 *   2. Que RENOMBRAR NO CAMBIA EL MAESTRO. El maestro guarda el TEXTO, no un
 *      id: los proveedores cargados conservan el valor viejo. Propagar sería un
 *      UPDATE masivo que cambia de fila del tablero a cientos de proveedores
 *      desde una pantalla de configuración, sin diff y sin historial.
 *   3. Que el PLAZO es la única lista que el sistema usa para CALCULAR, y que
 *      sus días vacíos NO son cero: vacío es "este plazo no dice cuándo"
 *      (DÉBITO) y cero es "se paga el día de la factura" (CONTADO).
 *
 * LAS TARJETAS SE DIBUJAN DESDE ProveedoresOpciones::TIPOS, que viaja en el
 * payload. Agregar una sexta lista es agregarla ahí; acá no se toca nada.
 *
 * La validación que vale es la del servidor; acá se espeja sólo para avisar
 * antes de mandar.
 */

(function() {
    'use strict';

    var URL_PARAM = 'Controller/ParametrosController.php';

    var modulo = null;

    /** El bloque 'prov_locales_opciones' del módulo: tipos, listas y usos */
    var datos = null;

    function inicializar() {
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
        Cargando.mostrar('loadingParamPplo');
        mostrar('wrapperParamPplo', false);

        pedirJson(URL_PARAM + '?action=getTodo')
            .then(function(data) {
                modulo = buscarModulo(data, 'PROV_LOCALES');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo PROV_LOCALES');
                }

                datos = modulo.prov_locales_opciones
                    || { tipos: {}, listas: {}, usos: {}, tabla_creada: false };

                pintarDescripcion();
                pintarAvisos();
                pintarListas();

                Cargando.ocultar('loadingParamPplo');
                mostrar('wrapperParamPplo', true);
            })
            .catch(function(error) {
                Cargando.ocultar('loadingParamPplo');
                Notificacion.error('Error al cargar las listas de opciones: ' + error.message);
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
        var cont = document.getElementById('descripcionPplo');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>'
                + escapar(modulo.descripcion);
        }
    }

    /** Si el script SQL del módulo no se corrió, se avisa en vez de romper */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParamPplo');
        var avisos = modulo.avisos || [];

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? avisos.map(function(a) {
                  return '<div class="alert alert-warning py-2 px-3 mb-3">'
                      + '<i class="fas fa-triangle-exclamation me-1"></i><small>'
                      + escapar(a) + '</small></div>';
              }).join('')
            : '';
    }

    /* ================================================================
       LAS CINCO TARJETAS
       ================================================================ */

    function pintarListas() {
        var cont = document.getElementById('listasPplo');

        if (!cont) {
            return;
        }

        var tipos = datos.tipos || {};
        var html = '';

        Object.keys(tipos).forEach(function(tipo) {
            html += tarjeta(tipo, tipos[tipo]);
        });

        cont.innerHTML = html || '<div class="text-muted">No hay listas declaradas.</div>';

        conectarTarjetas();
    }

    function tarjeta(tipo, def) {
        var opciones = (datos.listas && datos.listas[tipo]) || [];
        var vigentes = opciones.filter(function(o) { return o.VIGENTE; });
        var esPlazo = (tipo === datos.tipo_plazo);

        return '<div class="card mb-4 pplo-card" data-tipo="' + escapar(tipo) + '">'
            + '<div class="card-header d-flex justify-content-between align-items-center '
            +      'flex-wrap gap-2">'
            +   '<div>'
            +     '<h5 class="mb-0">' + escapar(def.nombre)
            +       ' <span class="badge bg-secondary">' + vigentes.length + '</span></h5>'
            +     '<small class="text-muted">' + escapar(def.ayuda) + '</small>'
            +   '</div>'
            +   '<div class="d-flex gap-2">'
            +     '<button class="btn btn-sm btn-outline-success" data-exportar="tablaPplo_'
            +       escapar(tipo) + '" data-exportar-nombre="Opciones_' + escapar(tipo) + '"'
            +       ' title="Exportar a Excel lo que se está viendo">'
            +       '<i class="fas fa-file-excel me-1"></i> Exportar</button>'
            +     '<button class="btn btn-sm btn-primary pplo-nuevo">'
            +       '<i class="fas fa-plus me-1"></i> Agregar</button>'
            +   '</div>'
            + '</div>'

            /* El alta arranca oculta: cinco formularios abiertos a la vez
               convierten la pantalla en un muro de campos vacíos. */
            + '<div class="card-body border-bottom pplo-form" style="display: none;">'
            +   '<div class="row g-2 align-items-end">'
            +     '<div class="col-md-5">'
            +       '<label class="form-label form-label-sm">Valor</label>'
            +       '<input type="text" class="form-control form-control-sm pplo-nuevo-valor" '
            +         'maxlength="60" placeholder="Ej: ' + escapar(ejemplo(tipo)) + '">'
            +     '</div>'
            +     (esPlazo ? campoDias('pplo-nuevo-dias', '') : '')
            +     '<div class="col-md-4 d-flex gap-2">'
            +       '<button class="btn btn-sm btn-primary pplo-agregar">'
            +         '<i class="fas fa-check me-1"></i> Agregar</button>'
            +       '<button class="btn btn-sm btn-outline-secondary pplo-cancelar">'
            +         '<i class="fas fa-xmark"></i></button>'
            +     '</div>'
            +   '</div>'
            +   '<div class="param-hint mt-2">'
            +     (esPlazo
                    ? 'Los <strong>días</strong> son lo que el sistema usa para estimar la fecha '
                      + 'de pago cuando la factura no trae vencimiento. Dejalos '
                      + '<strong>vacíos</strong> si el plazo no dice cuándo se paga (DÉBITO): '
                      + 'vacío y cero <strong>no</strong> son lo mismo — cero significa que se '
                      + 'paga el día de la factura.'
                    : 'El valor nuevo va al <strong>final</strong> de la lista. Si ya existía y '
                      + 'estaba dado de baja, se reactiva en lugar de duplicarse.')
            +   '</div>'
            + '</div>'

            + '<div class="card-body p-0">'
            /* LO QUE LA COLUMNA "ORDEN" HACE, Y LO QUE DEJÓ DE HACER.

               Decidía el orden en el que se ofrecían los valores en el alta
               manual del maestro, y ya no: los desplegables son alfabéticos
               siempre, porque tienen buscador y en una lista larga el único
               orden en el que se puede buscar con la vista es ése.

               La columna no se sacó —la sigue usando el alta, que pone el valor
               nuevo al final— pero un control que parece hacer algo que no hace
               es peor que no tenerlo, así que dice lo que hace: ordena ESTA
               tabla. */
            +   '<div class="param-hint px-3 pt-2 pb-1">'
            +     '<strong>Orden</strong>: acomoda esta tabla, y nada más. En el alta manual del '
            +     'maestro los valores se ofrecen siempre en orden <strong>alfabético</strong>, '
            +     'porque el desplegable tiene buscador y en una lista larga es el único orden '
            +     'en el que se encuentra algo mirando. Poné arriba lo más usado si te sirve '
            +     'para administrarlo acá.'
            +   '</div>'
            +   '<div class="table-responsive">'
            /* data-orden="no": no es un listado, es el orden con el que
               administración acomoda esta tabla. Ordenar por otra columna lo
               desarma. */
            +     '<table id="tablaPplo_' + escapar(tipo) + '" class="table table-hover mb-0" '
            +       'data-orden="no">'
            +       '<thead><tr>'
            +         '<th style="width: 90px;">Orden</th>'
            +         '<th>Valor</th>'
            +         (esPlazo ? '<th class="text-center" style="width: 140px;">Días</th>' : '')
            +         '<th class="text-center" style="width: 120px;">Proveedores</th>'
            +         '<th class="text-center" style="width: 110px;">Vigente</th>'
            +       '</tr></thead>'
            +       '<tbody>' + filas(tipo, opciones, esPlazo) + '</tbody>'
            +     '</table>'
            +   '</div>'
            + '</div>'
            + '</div>';
    }

    function filas(tipo, opciones, esPlazo) {
        if (!opciones.length) {
            return '<tr><td colspan="' + (esPlazo ? 5 : 4) + '" '
                + 'class="text-center text-muted py-4">'
                + (datos.tabla_creada
                    ? 'Esta lista está vacía: el campo no se va a poder elegir en el alta '
                      + 'manual hasta que le cargues valores.'
                    : 'Falta correr sql/cashflow_prov_locales_opciones.sql.')
                + '</td></tr>';
        }

        var usos = (datos.usos && datos.usos[tipo]) || {};

        return opciones.map(function(o) {
            var cuantos = usos[o.VALOR] || 0;

            return '<tr class="' + (o.VIGENTE ? '' : 'pplo-baja') + '" data-id="' + o.ID + '">'
                + '<td><input type="number" class="form-control form-control-sm pplo-orden" '
                +   'value="' + o.ORDEN + '" min="0" step="1" data-previo="' + o.ORDEN + '" '
                +   'title="En qué posición aparece en ESTA tabla. No cambia el orden de los '
                +     'desplegables del alta manual, que son siempre alfabéticos."></td>'
                + '<td><input type="text" class="form-control form-control-sm pplo-valor" '
                +   'value="' + escapar(o.VALOR) + '" maxlength="60" '
                +   'data-previo="' + escapar(o.VALOR) + '" '
                +   'title="' + escapar('Renombrar NO cambia los proveedores que ya lo tienen: '
                      + 'el maestro guarda el texto, no un id. Van a quedar marcados como fuera '
                      + 'de lista hasta que alguien los edite.') + '"></td>'
                + (esPlazo ? '<td>' + celdaDias(o) + '</td>' : '')
                + '<td class="text-center">' + celdaUsos(cuantos) + '</td>'
                + '<td class="text-center">'
                +   '<div class="form-check form-switch d-inline-block">'
                +     '<input class="form-check-input pplo-vigente" type="checkbox"'
                +       (o.VIGENTE ? ' checked' : '')
                +       ' title="' + escapar('Dar de baja saca el valor de los desplegables. NO '
                          + 'lo borra: los proveedores que lo tienen lo conservan.') + '">'
                +   '</div>'
                + '</td>'
                + '</tr>';
        }).join('');
    }

    /**
     * Cuántos proveedores usan el valor.
     *
     * ES EL NÚMERO QUE HACE QUE DAR DE BAJA NO SEA A CIEGAS. En cero se marca
     * distinto: o el valor es nuevo, o nadie lo usa y se puede sacar sin que
     * nada quede fuera de lista.
     */
    function celdaUsos(cuantos) {
        if (!cuantos) {
            return '<span class="text-muted" title="'
                + escapar('Ningún proveedor vigente usa este valor: darlo de baja no deja a '
                    + 'nadie fuera de lista.')
                + '">—</span>';
        }

        return '<span class="badge bg-light text-dark" title="'
            + escapar(cuantos + ' proveedor(es) vigentes tienen este valor. Si lo das de baja o '
                + 'lo renombrás, quedan marcados como fuera de lista.')
            + '">' + cuantos + '</span>';
    }

    /**
     * Los días del plazo, editables.
     *
     * VACÍO NO ES CERO, y la celda tiene que decirlo: vacío es "este plazo no
     * dice cuándo se paga" —DÉBITO, y la jerarquía de fecha cae al escalón
     * siguiente— y cero es "se paga el día de la factura" —CONTADO—. Sin la
     * marca, las dos celdas se ven igual de vacías.
     */
    function celdaDias(o) {
        var sinDias = (o.PLAZO_DIAS === null || o.PLAZO_DIAS === undefined);

        return '<div class="input-group input-group-sm">'
            + '<input type="number" class="form-control form-control-sm text-center pplo-dias" '
            +   'min="0" max="365" step="1" value="' + (sinDias ? '' : o.PLAZO_DIAS) + '" '
            +   'data-previo="' + (sinDias ? '' : o.PLAZO_DIAS) + '" '
            +   'placeholder="—" '
            +   'title="' + escapar('Días desde la factura. Vacío = este plazo no dice cuándo '
                  + 'se paga (DÉBITO). Cero = se paga el día de la factura (CONTADO).') + '">'
            + '<span class="input-group-text pplo-dias-etiqueta">'
            +   (sinDias ? '<span class="pplo-sin-dias">no aplica</span>' : 'días')
            + '</span>'
            + '</div>';
    }

    /** Un ejemplo real de cada lista, para el placeholder del alta */
    function ejemplo(tipo) {
        return {
            RUBRO_ECONOMICO: 'Alquileres',
            RUBRO: 'Shoppings',
            CENTRO_COSTOS: 'Locales',
            PLAZO: '30 DIAS',
            CRITERIO_DISTRIB: '100% LOCALES'
        }[tipo] || '';
    }

    function campoDias(clase, valor) {
        return '<div class="col-md-3">'
            + '<label class="form-label form-label-sm">Días <small class="text-muted">'
            + '(vacío = no aplica)</small></label>'
            + '<input type="number" class="form-control form-control-sm ' + clase + '" '
            +   'min="0" max="365" step="1" value="' + valor + '" placeholder="—">'
            + '</div>';
    }

    /* ================================================================
       ACCIONES
       ================================================================ */

    function conectarTarjetas() {
        document.querySelectorAll('.pplo-card').forEach(function(card) {
            var tipo = card.dataset.tipo;
            var form = card.querySelector('.pplo-form');

            card.querySelector('.pplo-nuevo').addEventListener('click', function() {
                form.style.display = (form.style.display === 'none') ? 'block' : 'none';

                if (form.style.display === 'block') {
                    var inp = form.querySelector('.pplo-nuevo-valor');

                    if (inp) { inp.focus(); }
                }
            });

            card.querySelector('.pplo-cancelar').addEventListener('click', function() {
                form.style.display = 'none';
            });

            card.querySelector('.pplo-agregar').addEventListener('click', function() {
                agregar(card, tipo);
            });

            var nuevo = card.querySelector('.pplo-nuevo-valor');

            if (nuevo) {
                nuevo.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        agregar(card, tipo);
                    }
                });
            }

            card.querySelectorAll('tbody tr[data-id]').forEach(function(tr) {
                conectarFila(tr);
            });
        });
    }

    function conectarFila(tr) {
        var id = tr.dataset.id;

        var valor = tr.querySelector('.pplo-valor');
        var orden = tr.querySelector('.pplo-orden');
        var dias = tr.querySelector('.pplo-dias');
        var vigente = tr.querySelector('.pplo-vigente');

        alGuardar(valor, function() {
            if (valor.value.trim() === valor.getAttribute('data-previo')) { return; }

            if (valor.value.trim() === '') {
                valor.value = valor.getAttribute('data-previo');
                Notificacion.campoInvalido(valor, 'El valor no puede quedar vacío.');
                return;
            }

            guardar(id, { valor: valor.value.trim() }, valor);
        });

        alGuardar(orden, function() {
            if (orden.value === orden.getAttribute('data-previo')) { return; }

            guardar(id, { orden: parseInt(orden.value, 10) || 0 }, orden);
        });

        if (dias) {
            alGuardar(dias, function() {
                if (dias.value === dias.getAttribute('data-previo')) { return; }

                var n = dias.value.trim();

                if (n !== '' && (isNaN(parseInt(n, 10)) || parseInt(n, 10) < 0
                        || parseInt(n, 10) > 365)) {
                    dias.value = dias.getAttribute('data-previo');
                    Notificacion.campoInvalido(dias,
                        'Los días tienen que ser un entero entre 0 y 365.', {
                            detalle: 'Dejalo vacío si el plazo no dice cuándo se paga: vacío y '
                                   + 'cero no son lo mismo.'
                        });
                    return;
                }

                guardar(id, { plazo_dias: (n === '') ? null : parseInt(n, 10) }, dias);
            });
        }

        vigente.addEventListener('change', function() {
            pedirAccion('bajaOpcionProvLocal', { id: id, vigente: vigente.checked })
                .then(function(res) {
                    Notificacion.exito(res.message);
                    cargar();
                })
                .catch(function(error) {
                    vigente.checked = !vigente.checked;
                    Notificacion.error('No se pudo guardar: ' + error.message);
                });
        });
    }

    /** Guardar con Enter o al salir del campo: los dos gestos, como en el resto */
    function alGuardar(input, fn) {
        if (!input) { return; }

        input.addEventListener('change', fn);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                fn();
            }
        });
    }

    function agregar(card, tipo) {
        var inp = card.querySelector('.pplo-nuevo-valor');
        var dias = card.querySelector('.pplo-nuevo-dias');
        var v = (inp.value || '').trim();

        if (v === '') {
            Notificacion.campoInvalido(inp, 'Escribí el valor que querés agregar.');
            return;
        }

        var cuerpo = { tipo: tipo, valor: v };

        if (dias) {
            var n = (dias.value || '').trim();

            // null y no 0: vacío significa "este plazo no dice cuándo se paga".
            cuerpo.plazo_dias = (n === '') ? null : parseInt(n, 10);
        }

        pedirAccion('addOpcionProvLocal', cuerpo)
            .then(function(res) {
                Notificacion.exito(res.message);
                cargar();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo agregar: ' + error.message);
            });
    }

    function guardar(id, cambios, campo) {
        pedirAccion('saveOpcionProvLocal', Object.assign({ id: id }, cambios))
            .then(function(res) {
                Notificacion.exito(res.message);
                cargar();
            })
            .catch(function(error) {
                if (campo) {
                    campo.value = campo.getAttribute('data-previo');
                }

                Notificacion.error('No se pudo guardar: ' + error.message);
            });
    }

    /**
     * Como pedirJson(), pero devuelve la respuesta ENTERA y no sólo 'data'.
     *
     * El mensaje del servidor es la mitad de lo que hay que mostrar acá: es el
     * que explica que renombrar no toca el maestro, o que una baja no borra
     * nada. Repetir esos textos en el front sería mantenerlos en dos lugares.
     */
    function pedirAccion(accion, cuerpo) {
        return fetch(URL_PARAM + '?action=' + accion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
        .then(function(r) { return r.text(); })
        .then(function(texto) {
            var res;

            try {
                res = JSON.parse(texto);
            } catch (e) {
                console.error('Respuesta no JSON:', texto);
                throw new Error('Respuesta inválida del servidor. Revisá la consola.');
            }

            if (!res.success) {
                throw new Error(res.message || 'Error desconocido');
            }

            return res;
        });
    }

    /* ================================================================
       HELPERS
       ================================================================ */

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(); // Fin del IIFE
