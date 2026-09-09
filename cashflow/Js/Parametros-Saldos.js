/**
 * Parámetros → Saldos
 *
 * Vive aparte de Parametros.js, igual que Parametros-Estructura.js: no comparte
 * estado con los bloques de Ventas, así que un problema acá no puede llevarse
 * puesta la pestaña que ya funciona. Pide su propio payload al mismo endpoint y
 * se queda con el módulo SALDOS.
 *
 * Nunca hay baja: se inhabilita con el switch de la columna Activo.
 */

(function() {
    'use strict';

    var URL_PARAM = 'Controller/ParametrosController.php';

    var modulo = null;

    // Cómo se edita cada parámetro general de este módulo
    var FORMATO = {
        'saldos_cta_tesoreria': {
            tipo: 'texto',
            sufijo: '',
            hint: 'Cuenta contable de SBA05 con el efectivo de la caja de tesorería. ' +
                  'Va como parámetro de la consulta: cambiarla no requiere tocar código.'
        },
        'saldos_dias_alerta_carga': {
            tipo: 'entero',
            sufijo: 'días',
            hint: 'Pasados estos días desde la última carga, la pestaña y el tablero avisan ' +
                  'que el disponible que se está mirando no es el de hoy.'
        }
    };

    function inicializar() {
        conectar('btnRefreshParamSaldos', cargar);
        conectar('btnGuardarCuentas', guardarCuentas);
        conectar('btnGuardarSucursales', guardarSucursales);
        conectar('btnSincronizarLocales', sincronizarLocales);

        document.querySelectorAll('.sp-btn-nueva').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var form = formDe(btn.dataset.tipo);
                mostrarEl(form, !form || form.style.display === 'none');
            });
        });

        document.querySelectorAll('.sp-btn-cancelar').forEach(function(btn) {
            btn.addEventListener('click', function() {
                mostrarEl(formDe(btn.dataset.tipo), false);
            });
        });

        document.querySelectorAll('.sp-btn-agregar').forEach(function(btn) {
            btn.addEventListener('click', function() {
                agregarCuenta(btn.dataset.tipo);
            });
        });

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
        mostrar('loadingParamSaldos', true, 'flex');
        mostrar('wrapperParamSaldos', false);

        pedirJson(URL_PARAM + '?action=getTodo')
            .then(function(data) {
                modulo = buscarModulo(data, 'SALDOS');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo SALDOS');
                }

                pintarDescripcion();
                pintarAvisos();
                pintarGenerales();
                pintarCuentas();
                pintarSucursales();

                mostrar('loadingParamSaldos', false);
                mostrar('wrapperParamSaldos', true);
            })
            .catch(function(error) {
                mostrar('loadingParamSaldos', false);
                avisar('Error al cargar los parámetros de Saldos: ' + error.message);
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
        var cont = document.getElementById('descripcionSaldos');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + escapar(modulo.descripcion);
        }
    }

    /** Si el script SQL del módulo no se corrió, se avisa en vez de romper */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParamSaldos');
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
       GENERALES
       ================================================================ */

    function pintarGenerales() {
        var html = '';

        (modulo.generales || []).forEach(function(p) {
            var fmt = FORMATO[p.CLAVE] || { tipo: 'texto', sufijo: '', hint: '' };

            var input = (fmt.tipo === 'entero')
                ? '<input type="number" step="1" min="0" class="form-control sp-param-input" ' +
                      'data-clave="' + p.CLAVE + '" data-tipo="entero" ' +
                      'value="' + escapar(p.VALOR) + '">'
                : '<input type="text" class="form-control sp-param-input text-start" ' +
                      'data-clave="' + p.CLAVE + '" data-tipo="texto" ' +
                      'value="' + escapar(p.VALOR) + '">';

            html += '<div class="col-md-6">' +
                        '<div class="param-card" id="sp-card-' + p.CLAVE + '">' +
                            '<div class="param-clave">' + etiqueta(p.CLAVE) + '</div>' +
                            '<div class="param-descripcion">' + escapar(p.DESCRIPCION || '') + '</div>' +
                            '<div class="input-group input-group-sm">' + input +
                                (fmt.sufijo
                                    ? '<span class="input-group-text">' + fmt.sufijo + '</span>'
                                    : '') +
                            '</div>' +
                            '<div class="param-hint">' + fmt.hint + '</div>' +
                        '</div>' +
                    '</div>';
        });

        var grid = document.getElementById('gridGeneralesSaldos');

        if (!grid) {
            return;
        }

        grid.innerHTML = html || '<div class="col-12 text-muted">' +
            'No hay parámetros generales cargados para este módulo. ' +
            'Corré sql/cashflow_saldos.sql.</div>';

        document.querySelectorAll('#gridGeneralesSaldos .sp-param-input').forEach(function(input) {
            input.addEventListener('change', function() {
                guardarGeneral(input);
            });
        });
    }

    function guardarGeneral(input) {
        var clave = input.dataset.clave;
        var valor = input.value;

        if (input.dataset.tipo === 'entero') {
            var ent = parseInt(valor, 10);

            if (isNaN(ent) || ent < 0) {
                avisar('El valor de ' + etiqueta(clave) + ' debe ser un entero no negativo');
                return;
            }

            valor = String(ent);
        } else if (String(valor).trim() === '') {
            avisar('El valor de ' + etiqueta(clave) + ' no puede quedar vacío');
            return;
        }

        pedirJson(URL_PARAM + '?action=saveParametro', { clave: clave, valor: valor })
            .then(function() {
                destacar('sp-card-' + clave);
            })
            .catch(function(error) {
                avisar('Error al guardar ' + etiqueta(clave) + ': ' + error.message);
                cargar();
            });
    }

    /* ================================================================
       CUENTAS
       ================================================================ */

    function pintarCuentas() {
        var cuentas = modulo.cuentas || [];
        var bancos = '';
        var otros = '';

        cuentas.forEach(function(c) {
            if (c.TIPO === 'BANCO') {
                bancos += filaBanco(c);
            } else {
                otros += filaOtro(c);
            }
        });

        document.getElementById('bodyBancos').innerHTML = bancos ||
            '<tr><td colspan="4" class="text-center text-muted py-4">' +
            'Todavía no hay bancos cargados.</td></tr>';

        document.getElementById('bodyOtros').innerHTML = otros ||
            '<tr><td colspan="5" class="text-center text-muted py-4">' +
            'Todavía no hay otros saldos cargados.</td></tr>';
    }

    function filaBanco(c) {
        var activo = (parseInt(c.ACTIVO, 10) === 1);

        return '<tr class="' + (activo ? '' : 'sp-inactiva') + '">' +
            '<td>' + inputNombre(c) + '</td>' +
            '<td class="text-center">' + selectMoneda(c) + '</td>' +
            '<td class="sp-api">' + datosApi(c) + '</td>' +
            '<td class="text-center">' + switchActivo(c) + '</td>' +
        '</tr>';
    }

    function filaOtro(c) {
        var activo = (parseInt(c.ACTIVO, 10) === 1);
        var consulta = (c.ORIGEN_DATO === 'CONSULTA');

        return '<tr class="' + (activo ? '' : 'sp-inactiva') + '">' +
            '<td>' + inputNombre(c) + '</td>' +
            '<td class="text-center">' + etiquetaTipo(c.TIPO) + '</td>' +
            '<td class="text-center">' + selectMoneda(c) + '</td>' +
            '<td class="text-center"><span class="sp-origen" title="' +
                (consulta
                    ? 'El saldo lo resuelve una consulta del sistema, no se tipea'
                    : 'El saldo se carga a mano desde la pestaña Saldos') +
                '">' + etiquetaOrigen(c.ORIGEN_DATO) + '</span></td>' +
            '<td class="text-center">' + switchActivo(c) + '</td>' +
        '</tr>';
    }

    function inputNombre(c) {
        return '<input type="text" class="form-control form-control-sm sp-cuenta-nombre" ' +
               'data-id="' + c.ID + '" maxlength="80" value="' + escapar(c.NOMBRE) + '">';
    }

    function selectMoneda(c) {
        return '<select class="form-select form-select-sm sp-cuenta-moneda" data-id="' + c.ID + '">' +
            '<option value="ARS"' + (c.MONEDA === 'ARS' ? ' selected' : '') + '>ARS</option>' +
            '<option value="USD"' + (c.MONEDA === 'USD' ? ' selected' : '') + '>USD</option>' +
        '</select>';
    }

    function switchActivo(c) {
        return '<div class="form-check form-switch d-inline-block">' +
            '<input class="form-check-input sp-cuenta-activo" type="checkbox" role="switch" ' +
                'data-id="' + c.ID + '"' +
                (parseInt(c.ACTIVO, 10) === 1 ? ' checked' : '') +
                ' title="Inhabilitar la saca de la pantalla de carga y del disponible, ' +
                'pero su histórico queda entero">' +
        '</div>';
    }

    /**
     * Los campos que va a devolver /accounts de Interbanking. Hoy están en
     * blanco: se muestran igual para que se vea qué va a completar la
     * integración y qué queda pendiente.
     */
    function datosApi(c) {
        var partes = [];

        if (c.BANK_ID) {
            partes.push('BCRA ' + escapar(c.BANK_ID));
        }

        if (c.ACCOUNT_TYPE) {
            partes.push(c.ACCOUNT_TYPE === 'CC' ? 'Cta. Cte.' : 'Caja de Ahorro');
        }

        if (c.ACCOUNT_NUMBER) {
            partes.push('N° ' + escapar(c.ACCOUNT_NUMBER));
        }

        if (c.CBU) {
            partes.push('CBU ' + escapar(c.CBU));
        }

        return partes.length
            ? partes.join(' · ')
            : '<span class="text-muted">pendiente de la integración con Interbanking</span>';
    }

    function guardarCuentas() {
        var porId = {};

        document.querySelectorAll('.sp-cuenta-nombre').forEach(function(i) {
            var id = parseInt(i.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].nombre = i.value.trim();
        });

        document.querySelectorAll('.sp-cuenta-moneda').forEach(function(s) {
            var id = parseInt(s.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].moneda = s.value;
        });

        document.querySelectorAll('.sp-cuenta-activo').forEach(function(c) {
            var id = parseInt(c.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].activo = c.checked;
        });

        var filas = Object.keys(porId).map(function(k) { return porId[k]; });
        var vacio = filas.filter(function(f) { return !f.nombre; });

        if (vacio.length) {
            avisar('Hay ' + vacio.length + ' cuenta(s) sin nombre. El nombre es lo que identifica '
                 + 'la cuenta en la pantalla de carga.');
            return;
        }

        conBoton('btnGuardarCuentas',
            pedirJson(URL_PARAM + '?action=saveCuentasSaldo', { filas: filas }),
            'No se pudieron guardar las cuentas');
    }

    function agregarCuenta(grupo) {
        var nombre = valorDe('.sp-nuevo-nombre', grupo);
        var moneda = valorDe('.sp-nueva-moneda', grupo);
        var tipo = (grupo === 'BANCO') ? 'BANCO' : valorDe('.sp-nuevo-tipo', grupo);

        if (!nombre) {
            avisar('Ingresá el nombre de la cuenta');
            return;
        }

        var btn = document.querySelector('.sp-btn-agregar[data-tipo="' + grupo + '"]');
        var original = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        pedirJson(URL_PARAM + '?action=addCuentaSaldo', {
            tipo: tipo,
            nombre: nombre,
            moneda: moneda
        })
        .then(function() {
            mostrarEl(formDe(grupo), false);
            cargar();
        })
        .catch(function(error) {
            avisar('Error al agregar la cuenta: ' + error.message);
        })
        .then(function() {
            btn.disabled = false;
            btn.innerHTML = original;
        });
    }

    /* ================================================================
       LOCALES
       ================================================================ */

    function pintarSucursales() {
        var html = '';

        (modulo.sucursales || []).forEach(function(s) {
            var activa = (parseInt(s.ACTIVO, 10) === 1);
            var envia = (s.GESTION === 'ENVIA');

            html += '<tr class="' + (activa ? '' : 'sp-inactiva') + '">';
            html += '<td class="fw-semibold">' + s.NRO_SUCURSAL + '</td>';
            html += '<td>' + escapar(s.DESC_SUCURSAL) +
                    (activa
                        ? ''
                        : '<div class="param-hint">Ya no está en la lista de locales propios ' +
                          'habilitados: queda inhabilitada, no se borra.</div>') +
                    '</td>';

            html += '<td class="text-center">' +
                        '<select class="form-select form-select-sm sp-suc-gestion" ' +
                            'data-suc="' + s.NRO_SUCURSAL + '" ' +
                            'title="Los locales en Envía se muestran en la pantalla pero su ' +
                            'efectivo no entra al cashflow">' +
                            '<option value="DEPOSITA"' + (envia ? '' : ' selected') + '>Deposita</option>' +
                            '<option value="ENVIA"' + (envia ? ' selected' : '') + '>Envía</option>' +
                        '</select>' +
                    '</td>';

            html += '<td>' +
                        '<div class="input-group input-group-sm">' +
                            '<span class="input-group-text">$</span>' +
                            '<input type="number" step="0.01" min="0" ' +
                                'class="form-control text-end sp-suc-reserva" ' +
                                'data-suc="' + s.NRO_SUCURSAL + '" value="' + s.RESERVA + '">' +
                        '</div>' +
                    '</td>';

            html += '<td class="text-center sp-fecha">' +
                    (s.FECHA_UPDATE ? fechaHora(s.FECHA_UPDATE) : '—') + '</td>';
            html += '</tr>';
        });

        document.getElementById('bodySucursales').innerHTML = html ||
            '<tr><td colspan="5" class="text-center text-muted py-4">' +
            'Todavía no hay locales cargados. Usá <strong>Sincronizar con locales</strong> ' +
            'para traerlos.</td></tr>';
    }

    function guardarSucursales() {
        var porNro = {};

        document.querySelectorAll('.sp-suc-gestion').forEach(function(s) {
            var nro = parseInt(s.dataset.suc, 10);
            porNro[nro] = porNro[nro] || { nro_sucursal: nro };
            porNro[nro].gestion = s.value;
        });

        document.querySelectorAll('.sp-suc-reserva').forEach(function(i) {
            var nro = parseInt(i.dataset.suc, 10);
            porNro[nro] = porNro[nro] || { nro_sucursal: nro };
            porNro[nro].reserva = parseFloat(i.value) || 0;
        });

        var filas = Object.keys(porNro).map(function(k) { return porNro[k]; });

        if (!filas.length) {
            avisar('No hay locales para guardar.');
            return;
        }

        var negativas = filas.filter(function(f) { return f.reserva < 0; });

        if (negativas.length) {
            avisar('La reserva de caja no puede ser negativa.');
            return;
        }

        conBoton('btnGuardarSucursales',
            pedirJson(URL_PARAM + '?action=saveSucursalesSaldo', { filas: filas }),
            'No se pudieron guardar los locales');
    }

    function sincronizarLocales() {
        var btn = document.getElementById('btnSincronizarLocales');
        var original = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sincronizando...';

        pedirJson(URL_PARAM + '?action=sincronizarSucursales')
            .then(function(r) {
                alert('Locales sincronizados: ' + r.altas + ' nuevos, ' + r.reactivadas +
                      ' reactivados, ' + r.bajas + ' inhabilitados.\n\n' +
                      'La gestión y la reserva ya cargadas no se tocaron.');
                cargar();
            })
            .catch(function(error) {
                avisar('No se pudieron sincronizar los locales: ' + error.message);
            })
            .then(function() {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /** Muestra el estado del guardado en el botón y recarga si salió bien */
    function conBoton(id, promesa, mensajeError) {
        var btn = document.getElementById(id);
        var original = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        promesa
            .then(function() {
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Guardado';
                setTimeout(function() {
                    btn.innerHTML = original;
                    btn.disabled = false;
                    cargar();
                }, 900);
            })
            .catch(function(error) {
                avisar(mensajeError + ': ' + error.message);
                btn.disabled = false;
                btn.innerHTML = original;
            });
    }

    function formDe(tipo) {
        return document.querySelector('.sp-form-nueva[data-tipo="' + tipo + '"]');
    }

    function valorDe(selector, tipo) {
        var el = document.querySelector(selector + '[data-tipo="' + tipo + '"]');

        return el ? String(el.value).trim() : '';
    }

    function etiquetaTipo(tipo) {
        var tipos = {
            'BANCO': 'Banco',
            'MERCADO_PAGO': 'Mercado Pago',
            'EFECTIVO_CENTRAL': 'Efectivo tesorería',
            'OTRO': 'Otro'
        };

        return tipos[tipo] || tipo;
    }

    function etiquetaOrigen(origen) {
        var origenes = {
            'API': 'Interbanking',
            'MANUAL': 'Manual',
            'CONSULTA': 'Consulta'
        };

        return origenes[origen] || origen;
    }

    /** saldos_cta_tesoreria -> Saldos Cta Tesoreria */
    function etiqueta(clave) {
        return String(clave).replace(/_/g, ' ').toLowerCase()
            .replace(/(^|\s)\S/g, function(c) { return c.toUpperCase(); });
    }

    function fechaHora(valor) {
        var s = String(valor);
        var p = s.slice(0, 10).split('-');

        if (p.length !== 3) {
            return s;
        }

        return p[2] + '/' + p[1] + '/' + p[0] + (s.length > 10 ? (' ' + s.slice(11, 16)) : '');
    }

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function mostrar(id, visible, display) {
        mostrarEl(document.getElementById(id), visible, display);
    }

    function mostrarEl(el, visible, display) {
        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function destacar(id) {
        var el = document.getElementById(id);

        if (!el) {
            return;
        }

        el.classList.add('param-guardado');
        setTimeout(function() {
            el.classList.remove('param-guardado');
        }, 1200);
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
