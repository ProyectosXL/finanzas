/**
 * Parámetros → Cheques (código de módulo PRECHEQUEADO)
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
 *
 * ARRIBA DEL MAESTRO, EL DEPÓSITO DE CHEQUES: el plazo de acreditación y las
 * alícuotas de retención con las que un cheque de cartera depositado acredita en
 * la fila Echeqs depositados. Es la pantalla de alícuotas de Cob. Electrónicos
 * con un juego propio y sin procesadora. LA TASA DE HOY Y CUÁL ALÍCUOTA ES LA
 * VIGENTE LAS DICE EL SERVIDOR (Echeqs::datosParametrosDeposito()): si acá se
 * resolvieran por su cuenta, la pantalla podría mostrar una tasa que el
 * depósito no usa.
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

        conectar('btnGuardarPlazoPpq', guardarPlazo);
        conectar('btnNuevaAlicuotaPpq', function() {
            alternar('formAlicuotaPpq');
            pintarSuma();
        });
        conectar('btnCancelarAlicuotaPpq', function() { mostrar('formAlicuotaPpq', false); });
        conectar('btnAgregarAlicuotaPpq', agregarAlicuota);

        ['nuevaAliConceptoPpq', 'nuevaAliPorcentajePpq', 'nuevaAliVigenciaPpq'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) {
                el.addEventListener('input', pintarSuma);
            }
        });

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

        /* No carga al arrancar: carga cuando se abre su sub-pestaña, o ahora
           si es la primera que se ve. Ver Js/parametros-carga.js. */
        ParametrosCarga.registrar('PRECHEQUEADO', cargar);
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
        Cargando.mostrar('loadingParamPpq');
        mostrar('wrapperParamPpq', false);

        return ParametrosCarga.pedir('PRECHEQUEADO')
            .then(function(data) {
                modulo = buscarModulo(data, 'PRECHEQUEADO');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo PRECHEQUEADO');
                }

                pintarDescripcion();
                pintarAvisos();
                pintarDeposito();
                pintarClientes();

                Cargando.ocultar('loadingParamPpq');
                mostrar('wrapperParamPpq', true);
            })
            .catch(function(error) {
                Cargando.ocultar('loadingParamPpq');
                avisar('Error al cargar los clientes pre-chequeados: ' + error.message);

                // Falló: que volver a la sub-pestaña lo reintente.
                return false;
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

    /**
     * El panel plegable de todas las pestañas (Js/avisos-panel.js). Los textos
     * sueltos cuentan como atención; un error de carga llega como
     * {nivel: 'danger'} y abre el panel solo.
     */
    function pintarAvisos() {
        AvisosPanel.pintar('avisosParamPpq', modulo.avisos || []);
    }

    /* ================================================================
       DEPÓSITO DE CHEQUES: PLAZO Y ALÍCUOTAS
       ================================================================ */

    /**
     * La sección del depósito. Sin el script no dibuja controles que fallarían
     * al guardar: dice qué falta, y el maestro de abajo sigue andando.
     */
    function pintarDeposito() {
        var d = modulo.deposito;
        var sinScript = document.getElementById('sinScriptDepositoPpq');

        if (!d || !d.tablas_creadas) {
            if (sinScript) {
                sinScript.innerHTML = '<span class="pce-sin-alicuota">sin configurar</span> ' +
                    '<span class="text-muted">' + escapar(d
                        ? 'Todavía no existen las tablas del depósito de cheques: corré ' +
                          'sql/cashflow_echeqs_deposito.sql contra la base central. Mientras ' +
                          'tanto no se puede depositar.'
                        : 'No se pudo leer el depósito de cheques: el motivo está en los avisos.') +
                    '</span>';
            }

            mostrar('sinScriptDepositoPpq', true);
            mostrar('cuerpoDepositoPpq', false);

            return;
        }

        mostrar('sinScriptDepositoPpq', false);
        mostrar('cuerpoDepositoPpq', true);

        pintarPlazo(d);
        pintarTasaHoy(d.tasa_hoy);
        pintarAlicuotas(d.alicuotas || []);
        pintarConceptos(d.alicuotas || []);

        if (!valor('nuevaAliVigenciaPpq')) {
            setValor('nuevaAliVigenciaPpq', d.hoy);
        }
    }

    function pintarPlazo(d) {
        var inp = document.getElementById('plazoDepositoPpq');
        var p = d.plazo || {};

        if (inp) {
            inp.max = d.plazo_max;
            // Un plazo roto se muestra tal cual está guardado, para poder
            // corregirlo: el motivo lo dice el aviso de arriba.
            inp.value = (p.dias !== null && p.dias !== undefined) ? p.dias : (p.valor || '');
            inp.setAttribute('data-previo', inp.value);
        }

        var aud = document.getElementById('plazoAuditoriaPpq');

        if (aud) {
            aud.textContent = p.fecha
                ? 'Último cambio: ' + (Auditoria.quien(p.usuario) || 'sin usuario registrado') +
                  ' · ' + Auditoria.fecha(p.fecha)
                : '';
        }
    }

    /**
     * La tasa de hoy con su desglose. Sin alícuotas vigentes dice "sin
     * alícuotas", no 0%: un cero se leería como que el banco no retiene nada.
     */
    function pintarTasaHoy(t) {
        var cont = document.getElementById('tasaHoyPpq');

        if (!cont) {
            return;
        }

        if (!t || t.tasa === null || t.tasa === undefined) {
            cont.innerHTML = '<span class="pce-sin-alicuota" title="Sin alícuotas vigentes no se ' +
                'puede calcular el neto de un depósito, así que no se puede depositar">sin ' +
                'alícuotas</span>';

            return;
        }

        cont.innerHTML = '<span class="fs-5 fw-semibold pce-num">' + porcentaje(t.tasa) +
            '</span><div class="pce-detalle">' + escapar(t.desglose) + '</div>' +
            '<div class="pce-detalle">Un cheque que acredita hoy entra por el ' +
            porcentaje(1 - t.tasa) + ' de su importe.</div>';
    }

    function pintarAlicuotas(alicuotas) {
        document.getElementById('bodyAlicuotasPpq').innerHTML = alicuotas.length
            ? alicuotas.map(filaAlicuota).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">Todavía no hay alícuotas ' +
              'cargadas. Sin alícuotas no se puede depositar ningún cheque.</td></tr>';

        Permisos.soloLectura('bodyAlicuotasPpq');

        document.querySelectorAll('.ppq-nueva-vigencia').forEach(function(b) {
            b.addEventListener('click', function() {
                mostrar('formAlicuotaPpq', true);
                setValor('nuevaAliConceptoPpq', b.dataset.concepto);
                setValor('nuevaAliVigenciaPpq', modulo.deposito.hoy);
                pintarSuma();
            });
        });

        document.querySelectorAll('.ppq-baja-alicuota').forEach(function(b) {
            b.addEventListener('click', function() {
                bajaAlicuota(parseInt(b.dataset.id, 10), b);
            });
        });
    }

    function filaAlicuota(a) {
        var activa = (parseInt(a.ACTIVO, 10) === 1);

        // Cuál rige hoy lo marca el servidor (VIGENTE_HOY). Una activa que no
        // rige es histórica -la reemplazó otra- o todavía no empezó.
        var estado = !activa
            ? '<span class="pce-badge pce-badge-baja">dada de baja</span>'
            : (a.VIGENTE_HOY
                ? '<span class="pce-badge pce-badge-vigente">vigente</span>'
                : (String(a.VIGENCIA_DESDE) > String(modulo.deposito.hoy)
                    ? '<span class="pce-badge" title="Empieza a regir en su fecha: los depósitos ' +
                      'que acrediten desde ese día se calculan con ella.">futura</span>'
                    : '<span class="pce-badge" title="Quedó reemplazada por una vigencia ' +
                      'posterior. Se muestra porque es lo que explica la tasa de los depósitos ' +
                      'de ese período.">histórica</span>'));

        return '<tr class="' + (activa ? '' : 'ppq-inactivo') + '">' +
            '<td class="fw-semibold">' + escapar(a.CONCEPTO) + '</td>' +
            '<td class="text-end pce-num">' + porcentaje(a.ALICUOTA) + '</td>' +
            '<td class="text-center">' + fechaCorta(a.VIGENCIA_DESDE) + '</td>' +
            '<td class="text-center">' + estado + '</td>' +
            '<td class="text-center ppq-fecha">' + (a.FECHA_ALTA ? fechaHora(a.FECHA_ALTA) : '—') +
                subtituloUsuario(a.USUARIO) + '</td>' +
            '<td class="text-center">' +
                (activa
                    ? '<button class="btn btn-sm btn-link ppq-nueva-vigencia" ' +
                          'data-concepto="' + escapar(a.CONCEPTO) + '" ' +
                          'title="Cargar una vigencia nueva de este concepto">' +
                          '<i class="fas fa-pen"></i></button>' +
                      '<button class="btn btn-sm btn-link text-danger ppq-baja-alicuota" ' +
                          'data-id="' + a.ID + '" ' +
                          'title="Dar de baja esta vigencia: no se borra, deja de regir">' +
                          '<i class="fas fa-ban"></i></button>'
                    : '') +
            '</td>' +
        '</tr>';
    }

    /**
     * Los conceptos ya usados, como sugerencia. El campo sigue siendo libre:
     * el concepto no es un enum y una retención nueva se carga sin tocar código.
     */
    function pintarConceptos(alicuotas) {
        var lista = document.getElementById('conceptosPpq');
        var conceptos = {};

        if (!lista) {
            return;
        }

        alicuotas.forEach(function(a) { conceptos[a.CONCEPTO] = true; });

        lista.innerHTML = Object.keys(conceptos).sort().map(function(c) {
            return '<option value="' + escapar(c) + '"></option>';
        }).join('');
    }

    /**
     * La suma que va a quedar con la alícuota que se está cargando, sobre las
     * vigentes HOY que marca el servidor. Bloquea el botón si llega a 100%: el
     * neto saldría cero o negativo. El servidor la vuelve a validar contra las
     * vigentes a la fecha elegida, que es donde vale.
     */
    function pintarSuma() {
        var cont = document.getElementById('sumaAlicuotaPpq');
        var btn = document.getElementById('btnAgregarAlicuotaPpq');

        if (!cont || !modulo || !modulo.deposito) {
            return;
        }

        var concepto = valor('nuevaAliConceptoPpq');
        var nueva = (parseFloat(valor('nuevaAliPorcentajePpq')) || 0) / 100;

        if (!concepto) {
            cont.innerHTML = '<span class="text-muted">Ingresá el concepto para ver la tasa total ' +
                'que va a quedar.</span>';

            if (btn) {
                btn.disabled = false;
            }

            return;
        }

        var suma = nueva;
        var partes = [escapar(concepto) + ' ' + porcentaje(nueva) + ' (nueva)'];

        (modulo.deposito.alicuotas || []).forEach(function(a) {
            if (!a.VIGENTE_HOY || a.CONCEPTO === concepto) {
                return;
            }

            suma += parseFloat(a.ALICUOTA) || 0;
            partes.push(escapar(a.CONCEPTO) + ' ' + porcentaje(a.ALICUOTA));
        });

        var invalida = (suma >= 1);

        cont.innerHTML = '<i class="fas fa-calculator me-1"></i>' +
            'Tasa total resultante <strong class="' + (invalida ? 'text-danger' : '') + '">' +
            porcentaje(suma) + '</strong> (' + partes.join(' + ') + ')' +
            (invalida
                ? '<div class="text-danger mt-1">Con esta suma el neto saldría cero o negativo: un ' +
                  'depósito restaría plata del tablero. Revisá los otros conceptos.</div>'
                : '<div class="text-muted mt-1">Un cheque depositado con esta tasa acredita el ' +
                  porcentaje(1 - suma) + ' de su importe.</div>');

        if (btn) {
            btn.disabled = invalida;
        }
    }

    function guardarPlazo() {
        var inp = document.getElementById('plazoDepositoPpq');
        var previo = inp.getAttribute('data-previo');
        var maximo = (modulo.deposito && modulo.deposito.plazo_max) || 30;
        var texto = String(inp.value).trim();
        var dias = parseInt(texto, 10);

        // Espejo de Echeqs::validarPlazo(), sólo para no mandar algo que el
        // servidor va a rechazar. El que vale es el del servidor.
        if (!/^\d+$/.test(texto) || dias < 1 || dias > maximo) {
            Notificacion.campoInvalido(inp, 'El plazo tiene que ser un entero de 1 a ' + maximo +
                ' días hábiles.', {
                detalle: 'Con 2, un cheque depositado el lunes acredita el miércoles.'
            });

            return;
        }

        if (texto === previo) {
            return;
        }

        conBotonEl(document.getElementById('btnGuardarPlazoPpq'), function() {
            return pedirJson(URL_PARAM + '?action=savePlazoDepositoEcheq', { dias: dias })
                .then(function() {
                    Notificacion.exito('Plazo de acreditación: ' + dias + ' día(s) hábil(es).', {
                        detalle: 'Vale también para los cheques depositados que todavía no ' +
                                 'acreditaron: su fecha se calcula al leer, desde la boleta.'
                    });

                    cargar();
                });
        }, 'No se pudo guardar el plazo');
    }

    function agregarAlicuota() {
        var concepto = valor('nuevaAliConceptoPpq');
        var porc = parseFloat(valor('nuevaAliPorcentajePpq'));
        var vigencia = valor('nuevaAliVigenciaPpq');

        if (!concepto) {
            Notificacion.campoInvalido('nuevaAliConceptoPpq',
                'Ingresá el concepto de la retención (por ejemplo IIBB o SICREB).');
            return;
        }

        if (isNaN(porc) || porc < 0) {
            Notificacion.campoInvalido('nuevaAliPorcentajePpq',
                'La alícuota tiene que ser un número no negativo.');
            return;
        }

        if (!vigencia) {
            Notificacion.campoInvalido('nuevaAliVigenciaPpq',
                'Ingresá desde qué fecha rige esta alícuota.', {
                detalle: 'Es lo que permite cambiarla sin reescribir el neto de lo ya acreditado.'
            });
            return;
        }

        conBotonEl(document.getElementById('btnAgregarAlicuotaPpq'), function() {
            // Viaja como fracción, que es como la guarda la base: 2,5% es 0,025.
            return pedirJson(URL_PARAM + '?action=addAlicuotaEcheq', {
                concepto: concepto,
                alicuota: porc / 100,
                vigencia_desde: vigencia
            }).then(function(r) {
                mostrar('formAlicuotaPpq', false);
                setValor('nuevaAliPorcentajePpq', '');
                mostrarResultado('Alícuota guardada como vigencia nueva: la anterior queda en el ' +
                    'histórico.', r);
                cargar();
            });
        }, 'No se pudo guardar la alícuota');
    }

    function bajaAlicuota(id, boton) {
        Notificacion.confirmar({
            titulo: 'Dar de baja la vigencia',
            mensaje: '¿Dar de baja esta alícuota?',
            detalle: 'No se borra: deja de regir. Los cheques depositados que todavía no ' +
                     'acreditaron se calculan con lo que quede vigente; si no queda ninguna, ' +
                     'no se proyectan y la pestaña Echeqs lo avisa como crítico.',
            confirmar: 'Dar de baja',
            peligro: true
        }).then(function(confirmado) {
            if (!confirmado) {
                return;
            }

            conBotonEl(boton, function() {
                return pedirJson(URL_PARAM + '?action=bajaAlicuotaEcheq', { id: id })
                    .then(function() {
                        mostrarResultado('Alícuota dada de baja: deja de regir, pero la fila ' +
                            'queda en el histórico.', null);
                        cargar();
                    });
            }, 'No se pudo dar de baja la alícuota');
        });
    }

    /**
     * Qué dejó el guardado: el título y la tasa total vigente de los cheques.
     *
     * NO HAY RECÁLCULO QUE MOSTRAR. El neto de un cheque depositado no se
     * guarda: se calcula al leer, con las alícuotas vigentes a su
     * acreditación, así que los que todavía no acreditaron toman lo nuevo
     * solos la próxima vez que se mira la pestaña o el tablero. Queda en un
     * panel a la vista, como el de Parámetros → Cob. Electrónicos.
     */
    function mostrarResultado(titulo, r) {
        var cont = document.getElementById('resultadoDepositoPpq');

        if (!cont) {
            return;
        }

        var html = '<div class="card-body border-bottom"><div class="pce-resultado mb-0">';

        html += '<div class="pce-resultado-titulo"><i class="fas fa-circle-check me-1"></i>' +
            escapar(titulo) + '</div>';

        if (r && r.tasa_total !== undefined && r.tasa_total !== null) {
            html += '<div class="pce-resultado-linea">La tasa total vigente de los cheques es ' +
                'ahora del <strong>' + porcentaje(r.tasa_total) + '</strong>.</div>';
        }

        html += '<div class="pce-resultado-linea">El neto de los cheques depositados que todavía ' +
            'no acreditaron se calcula con las alícuotas vigentes a su acreditación: cambia ' +
            'solo, en la próxima lectura.</div>';

        cont.innerHTML = html + '</div></div>';
        cont.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function pesos(v) {
        return '$ ' + (parseFloat(v) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** 0.025 -> '2,5%' */
    function porcentaje(alicuota) {
        var n = (parseFloat(alicuota) || 0) * 100;

        return n.toLocaleString('es-AR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 4
        }) + '%';
    }

    /* ================================================================
       LISTADO
       ================================================================ */

    function pintarClientes() {
        var clientes = modulo.prechequeado || [];

        document.getElementById('bodyClientesPpq').innerHTML = clientes.length
            ? clientes.map(filaCliente).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">' +
              'Todavía no hay clientes cargados. Mientras tanto, la sub-pestaña Venta Cobrada ' +
              'Anticipada se muestra vacía y no se netea nada de Ventas.</td></tr>';

        // Sin permiso de edicion, como texto (Js/permisos.js)
        Permisos.soloLectura('bodyClientesPpq');

        document.querySelectorAll('.ppq-activo').forEach(function(c) {
            c.addEventListener('change', function() {
                cambiarEstado(c.dataset.codigo, c.checked, c);
            });
        });

        document.querySelectorAll('.ppq-dias').forEach(function(inp) {
            inp.addEventListener('change', function() {
                guardarDias(inp);
            });

            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    guardarDias(inp);
                }
            });
        });
    }

    /**
     * Los días editables en la grilla.
     *
     * Un cliente en CERO se muestra igual que el resto, con la marca "sin
     * desplazar": si alguien esperaba un corrimiento y en Echeqs ve el cheque
     * en su propia fecha, el motivo es éste y tiene que poder encontrarlo. No
     * hay valor global de respaldo que lo tape.
     */
    function celdaDias(codigo, dias) {
        return '<div class="input-group input-group-sm mx-auto" style="max-width: 150px;">' +
            '<input type="number" class="form-control form-control-sm text-center ppq-dias" ' +
                'min="0" max="365" step="1" value="' + dias + '" ' +
                'data-codigo="' + escapar(codigo) + '" data-previo="' + dias + '" ' +
                'title="Días antes del cheque en que se emite la factura. ' +
                    'En 0 el cheque no se desplaza.">' +
            '<span class="input-group-text">' +
                (dias === 0
                    ? '<span class="ppq-sin-cheques" title="Este cliente no desplaza nada: ' +
                      'su cheque se netea en su propia fecha.">sin desplazar</span>'
                    : 'días') +
            '</span>' +
        '</div>';
    }

    function guardarDias(inp) {
        var codigo = inp.getAttribute('data-codigo');
        var previo = inp.getAttribute('data-previo');
        var dias = parseInt(inp.value, 10);

        if (isNaN(dias) || dias < 0 || dias > 365) {
            inp.value = previo;

            Notificacion.campoInvalido(inp, 'Los días tienen que ser un entero entre 0 y 365.', {
                detalle: 'Poné 0 si el cliente no adelanta: el cheque queda en su propia fecha.'
            });

            return;
        }

        if (String(dias) === String(previo)) {
            return;
        }

        inp.disabled = true;

        pedirJson(URL_PARAM + '?action=saveDiasPrecheq', { codigo: codigo, dias: dias })
            .then(function() {
                Notificacion.exito(codigo + ': ' + dias + ' día(s) de pre-chequeado.', {
                    detalle: 'La fecha estimada de venta y el neteo de Ventas se recalculan '
                           + 'con este plazo.'
                });

                cargar();
            })
            .catch(function(error) {
                // Reversión: dejar el número nuevo haría creer que se guardó un
                // plazo que el tablero no va a aplicar.
                inp.value = previo;
                inp.disabled = false;

                Notificacion.error('No se pudieron guardar los días: ' + error.message, {
                    detalle: 'El cliente quedó con ' + previo + ' día(s).'
                });
            });
    }

    function filaCliente(c) {
        var activo = (parseInt(c.ACTIVO, 10) === 1);
        var vivos = parseInt(c.CHEQUES_VIVOS, 10) || 0;
        var dias = parseInt(c.DIAS_PRECHEQUEADO, 10) || 0;

        return '<tr class="' + (activo ? '' : 'ppq-inactivo') + '">' +
            '<td class="fw-semibold">' + escapar(c.CLIENTE) + '</td>' +
            '<td>' + escapar(c.RAZON_SOCIAL || '—') + '</td>' +
            '<td class="text-center">' + celdaDias(c.CLIENTE, dias) + '</td>' +
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
               escapar(Auditoria.quien(usuario) || 'sin usuario registrado') + '</div>';
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
            Notificacion.campoInvalido('nuevoCodigoPpq', 'Ingresá el código del cliente.');
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

                        // No es un error del sistema: es un código que no existe.
                        // Va como advertencia sobre el campo, que es lo que hay
                        // que corregir.
                        Notificacion.campoInvalido('nuevoCodigoPpq',
                            'El código "' + codigo + '" no existe en el maestro de clientes de '
                            + 'Tango.', {
                            detalle: 'Ojo con las mayúsculas: la comparación las distingue.'
                        });

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
            Notificacion.advertencia('Buscá el código primero.', {
                detalle: 'Se guarda la razón social que devuelve Tango, no la que se tipee.'
            });

            return;
        }

        // Los días son parte del alta y no un dato que se descubre después: un
        // cliente cargado sin plazo queda en cero, y en la pantalla eso es
        // indistinguible de un cliente que realmente opera con cero.
        var dias = parseInt(valor('diasPpq'), 10);

        if (isNaN(dias) || dias < 0 || dias > 365) {
            Notificacion.campoInvalido('diasPpq',
                'Los días de pre-chequeado tienen que ser un entero entre 0 y 365.', {
                detalle: 'Poné 0 si el cliente no adelanta: el cheque queda en su propia fecha.'
            });

            return;
        }

        conBotonEl(document.getElementById('btnAgregarClientePpq'), function() {
            return pedirJson(URL_PARAM + '?action=addClientePrecheq',
                    { codigo: codigo, dias: dias })
                .then(function(data) {
                    setValor('nuevoCodigoPpq', '');
                    setValor('diasPpq', '0');
                    olvidarBusqueda();
                    mostrar('formClientePpq', false);

                    // Cuántos cheques trajo es lo que hay que decir: en cero, el
                    // código existe pero probablemente no es el que se quería, y
                    // eso no se nota de ninguna otra forma. Por eso ese caso va
                    // como advertencia y no como éxito.
                    var titulo = data.reactivado ? 'Cliente reactivado' : 'Cliente agregado';
                    var quien = data.cliente + ' — ' + data.razon_social;

                    if (data.cheques_vivos > 0) {
                        Notificacion.exito(quien, {
                            titulo: titulo,
                            detalle: 'Trae ' + data.cheques_vivos + ' cheque(s) vivos, que ya '
                                   + 'aparecen tildados en Echeqs → Venta Cobrada Anticipada.'
                        });
                    } else {
                        Notificacion.advertencia(quien, {
                            titulo: titulo + ', pero sin cheques',
                            detalle: 'Hoy no tiene ningún cheque vivo. Revisá que el código sea '
                                   + 'el que buscabas.'
                        });
                    }

                    cargar();
                });
        }, 'No se pudo agregar el cliente');
    }

    function cambiarEstado(codigo, activo, checkbox) {
        // Reactivar no se pregunta: no rompe nada y se deshace con el mismo
        // switch. Dar de baja sí, porque cambia números del tablero.
        var pregunta = activo
            ? Promise.resolve(true)
            : Notificacion.confirmar({
                titulo: 'Dar de baja el cliente',
                mensaje: '¿Dar de baja a ' + codigo + '?',
                detalle: 'Sus cheques dejan de aparecer en la pantalla y dejan de netear la '
                       + 'cobranza proyectada de Ventas. No se borra nada: las marcas por cheque '
                       + 'quedan por si el cliente vuelve.',
                confirmar: 'Dar de baja',
                peligro: true
            });

        pregunta.then(function(confirmado) {
            if (!confirmado) {
                checkbox.checked = true;
                return;
            }

            checkbox.disabled = true;

            return pedirJson(URL_PARAM + '?action=' + (activo ? 'addClientePrecheq'
                                                              : 'bajaClientePrecheq'),
                             { codigo: codigo })
                .then(function() {
                    Notificacion.exito(activo
                        ? codigo + ' vuelve a operar con venta cobrada anticipada.'
                        : codigo + ' quedó dado de baja.');

                    cargar();
                })
                .catch(function(error) {
                    // Reversión: dejar el switch como quedó haría creer que se
                    // guardó un cambio que el tablero no va a ver.
                    checkbox.checked = !activo;
                    checkbox.disabled = false;

                    Notificacion.error('No se pudo cambiar el estado: ' + error.message, {
                        detalle: 'El cliente quedó como estaba.'
                    });
                });
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

    /** Un error: no se cierra solo, porque trae el motivo del servidor */
    function avisar(mensaje) {
        Notificacion.error(mensaje);
    }

})(); // Fin del IIFE
