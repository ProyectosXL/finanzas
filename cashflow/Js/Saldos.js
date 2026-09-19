/**
 * Saldos JavaScript
 *
 * Tres sub-pestañas independientes:
 *   Saldos         -> disponible inicial: efectivo de tesorería, bancos y Mercado Pago
 *   Saldos Locales -> caja de los locales propios
 *   Fondos         -> las cuentas de inversión y comitente, con su cuenta corriente.
 *                     Son stock de cobertura, no disponibilidad.
 *
 * Se piden por separado y la segunda y la tercera son LAZY: se consultan recién
 * cuando se abre su pestaña. La de locales va contra un servidor que puede
 * estar caído, y no tiene por qué demorar ni romper la pestaña que se abre
 * primero.
 *
 * El saldo de cada fondo lo calcula el servidor (Fondos::saldoA()); acá no se
 * suma nada. Es la regla del módulo: el front no calcula.
 *
 * Los importes en dólares NO se convierten: la pantalla cierra con un total en
 * pesos y otro en dólares, cada uno en su moneda. La conversión existe sólo
 * para lo que el proveedor le entrega al Cashflow.
 */

(function() {
    'use strict';

    var URL_SALDOS = 'Controller/SaldosController.php';

    var datosSaldos = null;
    var datosLocales = null;
    var localesPedidos = false;

    var datosFondos = null;
    var fondosPedidos = false;
    var modalMovimientos = null;

    // Qué movimiento se está corrigiendo desde el formulario (null = alta).
    // Corregir no pisa: el servidor da de baja el anterior e inserta uno nuevo.
    var movReemplaza = null;

    // Modo carga de la pestaña 1: los saldos manuales pasan a ser editables.
    var enCarga = false;

    // Filtro por tipo de cuenta de la pestaña 1. '' es "todos". Es sólo de
    // pantalla: filtra la tabla, el pie y los KPI, nunca lo que se guarda.
    var filtroTipo = '';

    function inicializar() {
        conectar('btnRefreshSaldos', cargarSaldos);
        conectar('btnNuevaCarga', function() { modoCarga(true); });
        conectar('btnCancelarCarga', function() { modoCarga(false); });
        conectar('btnGuardarCarga', guardarCarga);

        var selTipo = document.getElementById('filtroTipoSaldos');

        if (selTipo) {
            selTipo.addEventListener('change', function() {
                filtroTipo = selTipo.value;

                if (datosSaldos) {
                    pintarKpiSaldos();
                    pintarTablaSaldos();
                }
            });
        }

        conectar('btnRefreshLocales', cargarLocales);
        conectar('btnGuardarLocales', guardarLocales);

        conectar('btnRefreshFondos', cargarFondos);
        conectar('btnNuevoMovimiento', function() { abrirFormMovimiento(null); });
        conectar('btnCancelarMovimiento', function() { mostrar('formMovimiento', false); });
        conectar('btnGuardarMovimiento', guardarMovimiento);

        var selCuenta = document.getElementById('movCuenta');

        if (selCuenta) {
            selCuenta.addEventListener('change', pintarMonedaMovimiento);
        }

        var modalEl = document.getElementById('modalMovimientos');

        if (modalEl && window.bootstrap && bootstrap.Modal) {
            modalMovimientos = new bootstrap.Modal(modalEl);
        }

        // Las pestañas 2 y 3 se piden la primera vez que se abren y no antes.
        var tabLocales = document.getElementById('tabLocalesBtn');

        if (tabLocales) {
            tabLocales.addEventListener('shown.bs.tab', function() {
                if (!localesPedidos) {
                    localesPedidos = true;
                    cargarLocales();
                }
            });
        }

        var tabFondos = document.getElementById('tabFondosBtn');

        if (tabFondos) {
            tabFondos.addEventListener('shown.bs.tab', function() {
                if (!fondosPedidos) {
                    fondosPedidos = true;
                    cargarFondos();
                }
            });
        }

        cargarSaldos();
        abrirSubTabPedida();
    }

    /**
     * Si se llegó acá desde la fila "Caja Locales" o desde una de stock de
     * cobertura del tablero, se abre directamente la sub-pestaña que produjo
     * ese número (locales o fondos).
     *
     * El destino lo deja Cashflow.js en window.cfSubTabDestino antes de navegar:
     * loadTab() carga la pestaña por AJAX y no avisa cuándo terminó, así que el
     * tablero no puede activar una pestaña que todavía no existe en el DOM. Se
     * consume una sola vez, para que un cambio de pestaña posterior no vuelva a
     * saltar acá.
     */
    function abrirSubTabPedida() {
        var destino = window.cfSubTabDestino;

        window.cfSubTabDestino = null;

        var botones = { locales: 'tabLocalesBtn', fondos: 'tabFondosBtn' };

        if (!botones[destino]) {
            return;
        }

        var btn = document.getElementById(botones[destino]);

        if (btn && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(btn).show();
        } else if (btn) {
            btn.click();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       PESTAÑA 1 — SALDOS
       ================================================================ */

    function cargarSaldos() {
        mostrar('loadingSaldos', true, 'flex');
        mostrar('wrapperSaldos', false);

        pedirJson(URL_SALDOS + '?action=getSaldos')
            .then(function(data) {
                datosSaldos = data;
                enCarga = false;

                // Una recarga termina cualquier carga en curso (por ejemplo la
                // que se acaba de guardar): el filtro vuelve a estar disponible.
                filtrarTipo(filtroTipo, false);

                pintarAvisos();
                pintarKpiSaldos();
                pintarTablaSaldos();
                botonesCarga(false);

                mostrar('loadingSaldos', false);
                mostrar('wrapperSaldos', true);
            })
            .catch(function(error) {
                mostrar('loadingSaldos', false);
                avisar('No se pudieron cargar los saldos: ' + error.message);
            });
    }

    /** Las filas que pasan el filtro por tipo. Sin filtro, todas. */
    function filasVisibles() {
        var filas = (datosSaldos && datosSaldos.filas) || [];

        if (!filtroTipo) {
            return filas;
        }

        return filas.filter(function(f) {
            return f.tipo === filtroTipo;
        });
    }

    /**
     * Totales por moneda de lo que se está viendo.
     *
     * Espeja Saldos::totalesPorMoneda() del servidor sobre las filas filtradas:
     * una cuenta sin cargar cuenta como cuenta y suma cero, igual que allá. Sin
     * filtro da lo mismo que el payload; con filtro, los KPI describen la tabla
     * que está abajo y no otra.
     */
    function totalesVisibles() {
        var totales = { ARS: { total: 0, cuentas: 0 }, USD: { total: 0, cuentas: 0 } };

        filasVisibles().forEach(function(f) {
            var m = (f.moneda || 'ARS').toUpperCase();

            if (!totales[m]) {
                totales[m] = { total: 0, cuentas: 0 };
            }

            totales[m].total += f.cargada ? (parseFloat(f.saldo) || 0) : 0;
            totales[m].cuentas++;
        });

        return totales;
    }

    function pintarKpiSaldos() {
        var t = totalesVisibles();
        var ars = t.ARS;
        var usd = t.USD;
        var sufijo = filtroTipo ? (' · ' + etiquetaTipo(filtroTipo)) : '';

        texto('totalArs', pesos(ars.total));
        texto('cuentasArs', ars.cuentas + ' cuenta(s) en pesos' + sufijo);
        texto('totalUsd', dolares(usd.total));
        texto('cuentasUsd', usd.cuentas + ' cuenta(s) en dólares' + sufijo);

        var uc = datosSaldos.ultima_carga;

        texto('ultimaCargaSaldos', uc ? fechaHora(uc.fecha_carga) : 'Sin cargas');
        texto('ultimaCargaSaldosDetalle', uc
            ? ((uc.usuario || 'sin usuario') + ' · ' + (uc.observaciones || 'sin observaciones'))
            : 'Todavía no se cargó ningún saldo');

        var ec = datosSaldos.efectivo_central;

        texto('efectivoCentral', ec ? pesos(ec.saldo) : 'No disponible');
        texto('efectivoCentralDetalle', ec
            ? ('Cuenta ' + ec.cuenta + ' · consultado el ' + fecha(ec.fecha))
            : 'No se pudo leer la consulta de tesorería');
    }

    function pintarTablaSaldos() {
        var html = '';
        var filas = filasVisibles();

        filas.forEach(function(f) {
            var consulta = (f.origen_cuenta === 'CONSULTA');

            html += '<tr data-cuenta="' + f.id_cuenta + '"' +
                    (f.cargada ? '' : ' class="sal-sin-cargar"') + '>';
            html += '<td class="fw-semibold">' + escapar(f.nombre) + detalleCuenta(f) + '</td>';
            html += '<td><span class="sal-badge">' + etiquetaTipo(f.tipo) + '</span></td>';
            html += '<td class="text-center">' + escapar(f.moneda) + '</td>';

            // Un guion y no un cero: "esta cuenta nunca se cargó" no es lo mismo
            // que "esta cuenta tiene cero pesos".
            html += '<td class="text-end sal-saldo">' + celdaSaldo(f, consulta) + '</td>';
            html += '<td class="text-center">' + (f.fecha_saldo ? fecha(f.fecha_saldo) : '—') + '</td>';
            html += '<td class="text-center"><span class="sal-origen">' +
                    etiquetaOrigen(consulta ? 'CONSULTA' : (f.origen_dato || 'MANUAL')) +
                    '</span></td>';

            // La fecha de carga de CADA dato, que es la regla transversal del
            // relevamiento: sirve para ver cuál es la última actualización.
            html += '<td class="text-center sal-fecha-carga">' +
                    (f.fecha_carga ? fechaHora(f.fecha_carga) : 'sin cargar') + '</td>';
            html += '</tr>';
        });

        if (!datosSaldos.filas.length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                   'No hay cuentas configuradas. Cargalas en Parámetros → Saldos.</td></tr>';
        } else if (!filas.length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                   'No hay cuentas de tipo ' + escapar(etiquetaTipo(filtroTipo)) + '.</td></tr>';
        }

        document.getElementById('bodySaldos').innerHTML = html;

        var t = totalesVisibles();

        document.getElementById('footSaldos').innerHTML =
            filaTotal('Total en pesos', pesos(t.ARS.total)) +
            filaTotal('Total en dólares', dolares(t.USD.total));

        if (enCarga) {
            document.querySelectorAll('.sal-input-saldo').forEach(function(i) {
                i.addEventListener('input', recalcularTotalesEdicion);
            });

            recalcularTotalesEdicion();
        }
    }

    /** Celda de saldo: texto cuando se mira, input cuando se está cargando */
    function celdaSaldo(f, consulta) {
        if (!enCarga) {
            if (!f.cargada) {
                return '<span class="text-muted" title="Esta cuenta todavía no tiene ningún ' +
                       'saldo cargado. No suma al disponible.">sin cargar</span>';
            }

            return importe(f.saldo, f.moneda);
        }

        if (consulta) {
            // El efectivo de tesorería no se tipea: lo resuelve el sistema al
            // guardar, releyendo su consulta de origen.
            var ec = datosSaldos.efectivo_central;

            return '<span class="sal-consulta" title="Lo resuelve la consulta del sistema al ' +
                   'guardar, no se carga a mano">' +
                   (ec ? pesos(ec.saldo) : 'no disponible') + '</span>';
        }

        return '<input type="number" step="0.01" class="form-control form-control-sm ' +
               'text-end sal-input-saldo" data-cuenta="' + f.id_cuenta + '" ' +
               'data-moneda="' + f.moneda + '" value="' + (f.cargada ? f.saldo : 0) + '">';
    }

    /** Los campos de la API que ya estén cargados, como subtítulo de la cuenta */
    function detalleCuenta(f) {
        var partes = [];

        if (f.bank_name && f.bank_name !== f.nombre) {
            partes.push(escapar(f.bank_name));
        }

        if (f.account_type) {
            partes.push(f.account_type === 'CC' ? 'Cta. Cte.' : 'Caja de Ahorro');
        }

        if (f.account_number) {
            partes.push('N° ' + escapar(f.account_number));
        }

        if (f.cbu) {
            partes.push('CBU ' + escapar(f.cbu));
        }

        if (f.mensaje) {
            // El error por cuenta que devuelve la API en una respuesta 200. Se
            // muestra: descartarlo haría leer "sin saldo" como "saldo cero".
            partes.push('<span class="sal-error">' + escapar(f.mensaje) + '</span>');
        }

        return partes.length ? '<div class="sal-subtitulo">' + partes.join(' · ') + '</div>' : '';
    }

    function modoCarga(activo) {
        enCarga = activo;
        botonesCarga(activo);
        mostrar('formCarga', activo);

        if (activo) {
            document.getElementById('observacionesCarga').value = '';
        }

        // La carga es SIEMPRE de todas las cuentas: guardarCarga() toma el
        // input de cada fila, y una fila que el filtro escondió no tiene
        // input, así que su saldo viajaría en cero. Se quita el filtro y se
        // bloquea el selector mientras dure la carga.
        filtrarTipo(activo ? '' : filtroTipo, activo);

        pintarKpiSaldos();
        pintarTablaSaldos();
    }

    /** Fija el filtro por tipo y, si se pide, bloquea el selector */
    function filtrarTipo(tipo, bloquear) {
        filtroTipo = tipo;

        var sel = document.getElementById('filtroTipoSaldos');

        if (sel) {
            sel.value = tipo;
            sel.disabled = !!bloquear;
        }
    }

    function botonesCarga(activo) {
        mostrar('btnNuevaCarga', !activo, 'inline-block');
        mostrar('btnGuardarCarga', activo, 'inline-block');
        mostrar('btnCancelarCarga', activo, 'inline-block');
    }

    /** Mientras se carga, el pie muestra los totales de lo que se está tipeando */
    function recalcularTotalesEdicion() {
        var totales = { ARS: 0, USD: 0 };

        datosSaldos.filas.forEach(function(f) {
            if (f.origen_cuenta === 'CONSULTA') {
                var ec = datosSaldos.efectivo_central;
                totales[f.moneda] = (totales[f.moneda] || 0) + (ec ? parseFloat(ec.saldo) : 0);
            }
        });

        document.querySelectorAll('.sal-input-saldo').forEach(function(i) {
            var m = i.dataset.moneda;
            totales[m] = (totales[m] || 0) + (parseFloat(i.value) || 0);
        });

        document.getElementById('footSaldos').innerHTML =
            filaTotal('Total en pesos', pesos(totales.ARS || 0)) +
            filaTotal('Total en dólares', dolares(totales.USD || 0));
    }

    function guardarCarga() {
        var filas = [];
        var hoy = new Date().toISOString().slice(0, 10);

        datosSaldos.filas.forEach(function(f) {
            if (f.origen_cuenta === 'CONSULTA') {
                // Va igual, pero sin saldo: el servidor lo relee de su consulta.
                filas.push({ id_cuenta: f.id_cuenta, saldo: 0, fecha_saldo: hoy });
                return;
            }

            var input = document.querySelector('.sal-input-saldo[data-cuenta="' + f.id_cuenta + '"]');

            filas.push({
                id_cuenta: f.id_cuenta,
                saldo: input ? (parseFloat(input.value) || 0) : 0,
                fecha_saldo: hoy
            });
        });

        if (!filas.length) {
            avisar('No hay ninguna cuenta para cargar.');
            return;
        }

        conBoton('btnGuardarCarga', function() {
            return pedirJson(URL_SALDOS + '?action=guardarCargaSaldos', {
                filas: filas,
                observaciones: document.getElementById('observacionesCarga').value
            }).then(function() {
                mostrar('formCarga', false);
                cargarSaldos();
            });
        }, 'No se pudo guardar la carga');
    }

    /* ================================================================
       PESTAÑA 2 — SALDOS LOCALES
       ================================================================ */

    function cargarLocales() {
        localesPedidos = true;
        mostrar('loadingLocales', true, 'flex');
        mostrar('wrapperLocales', false);

        // El aviso del guardado anterior no sobrevive a una recarga de datos:
        // se refiere a un guardado puntual, no al estado de la pantalla.
        ocultarLuegoDe('avisoGuardadoLocales', 6000);

        pedirJson(URL_SALDOS + '?action=getLocales')
            .then(function(data) {
                datosLocales = data;

                pintarAvisos();
                pintarTablaLocales();
                recalcularLocales();

                mostrar('loadingLocales', false);
                mostrar('wrapperLocales', true);
            })
            .catch(function(error) {
                mostrar('loadingLocales', false);
                avisar('No se pudo consultar la caja de los locales: ' + error.message);
            });
    }

    function pintarTablaLocales() {
        var html = '';
        var editable = !!datosLocales.manuales_disponibles;

        datosLocales.filas.forEach(function(f) {
            var envia = (f.gestion === 'ENVIA');
            var manual = (f.origen_saldo === 'MANUAL');
            var clases = [];

            if (envia) {
                clases.push('sal-envia');
            }

            // Resaltada: el saldo que manda no es el cierre de ayer. Es la
            // señal de que la consulta no lo trajo y hay que tipearlo.
            if (f.desactualizado) {
                clases.push('sal-desactualizado');
            }

            html += '<tr data-suc="' + f.nro_sucursal + '"' +
                    (clases.length ? ' class="' + clases.join(' ') + '"' : '') + '>';

            html += '<td class="fw-semibold">' + escapar(f.local) +
                    (f.sin_parametro
                        ? '<div class="sal-subtitulo sal-error">sin configurar en Parámetros: ' +
                          'se toma Deposita con reserva cero</div>'
                        : '') +
                    (f.cuentas > 1
                        ? '<div class="sal-subtitulo">' + f.cuentas + ' cuentas de tesorería: ' +
                          escapar(f.cod_cta) + '</div>'
                        : '') +
                    '</td>';

            html += '<td class="text-end">' + celdaSaldoLocal(f, editable, manual) + '</td>';
            html += '<td class="text-center">' + celdaFechaLocal(f) + '</td>';

            html += '<td class="text-center">' +
                        '<select class="form-select form-select-sm sal-gestion" ' +
                            'data-suc="' + f.nro_sucursal + '" ' +
                            'title="Los locales en Envía no entran al cashflow: su efectivo no ' +
                            'llega al banco por esta vía">' +
                            '<option value="DEPOSITA"' + (envia ? '' : ' selected') + '>Deposita</option>' +
                            '<option value="ENVIA"' + (envia ? ' selected' : '') + '>Envía</option>' +
                        '</select>' +
                    '</td>';

            html += '<td>' +
                        '<input type="number" step="0.01" min="0" ' +
                            'class="form-control form-control-sm text-end sal-reserva" ' +
                            'data-suc="' + f.nro_sucursal + '" value="' + f.reserva + '">' +
                    '</td>';

            html += '<td class="text-end sal-neto" data-suc="' + f.nro_sucursal + '"></td>';
            html += '<td class="text-end sal-aporta" data-suc="' + f.nro_sucursal + '"></td>';
            html += '</tr>';
        });

        if (!datosLocales.filas.length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                   'La consulta no devolvió ningún local.</td></tr>';
        }

        document.getElementById('bodyLocales').innerHTML = html;

        document.querySelectorAll('.sal-gestion, .sal-reserva, .sal-saldo-local').forEach(function(el) {
            el.addEventListener('input', recalcularLocales);
            el.addEventListener('change', recalcularLocales);
        });
    }

    /**
     * La celda del saldo en caja: un input cuando se puede tipear, texto si la
     * tabla de manuales todavía no existe. Debajo dice de dónde salió: si
     * manda un manual, qué decía la consulta y de cuándo, para que se vea que
     * se está pisando y por qué.
     */
    function celdaSaldoLocal(f, editable, manual) {
        var html = editable
            ? '<input type="number" step="0.01" min="0" ' +
                  'class="form-control form-control-sm text-end sal-saldo-local" ' +
                  'data-suc="' + f.nro_sucursal + '" data-original="' + f.saldo + '" ' +
                  'value="' + f.saldo + '" ' +
                  'title="Editalo sólo si la consulta no trajo el cierre: al guardar queda como ' +
                  'saldo manual de ayer y manda hasta que la consulta traiga uno más nuevo">'
            : '<span class="sal-saldo">' + pesos(f.saldo) + '</span>';

        if (manual) {
            var m = f.manual || {};

            html += '<div class="sal-subtitulo">' +
                    '<span class="sal-badge sal-badge-manual" title="Cargado a mano' +
                        (m.usuario ? ' por ' + escapar(m.usuario) : '') +
                        (m.fecha_update ? ' el ' + fechaHora(m.fecha_update) : '') + '">manual</span> ' +
                    'consulta: ' + pesos(f.saldo_consulta) +
                    (f.fecha_consulta ? ' del ' + fecha(f.fecha_consulta) : ' sin fecha') +
                    '</div>';
        }

        return html;
    }

    /** La fecha del saldo, y si no es la de ayer, dicho al lado */
    function celdaFechaLocal(f) {
        var html = f.fecha_saldo ? fecha(f.fecha_saldo) : '—';

        if (f.desactualizado) {
            html += '<div class="sal-subtitulo sal-error" title="La consulta no trajo el cierre ' +
                    'de ayer' + (datosLocales.ayer ? ' (' + fecha(datosLocales.ayer) + ')' : '') +
                    '. Si tenés el saldo real, cargalo en Saldo en caja.">no es de ayer</div>';
        }

        return html;
    }

    /**
     * Recalcula neto y aporte con lo que hay en pantalla.
     *
     * Espeja las tres reglas del servidor: el neto es saldo menos reserva sin
     * ningún ajuste impositivo, sólo los que depositan aportan, y un neto
     * negativo aporta cero. El cálculo que vale es el del servidor
     * (Saldos::armarSaldosLocales); esto es para que el número se mueva mientras
     * se edita.
     */
    /** El saldo en caja de un local tal como está en pantalla: el input, o el del payload */
    function saldoEnPantalla(f) {
        var inp = document.querySelector('.sal-saldo-local[data-suc="' + f.nro_sucursal + '"]');

        return inp ? (parseFloat(inp.value) || 0) : parseFloat(f.saldo);
    }

    function recalcularLocales() {
        var totales = { saldo: 0, reserva: 0, neto: 0, aporta: 0, envian: 0, editados: 0 };

        datosLocales.filas.forEach(function(f) {
            var sel = document.querySelector('.sal-gestion[data-suc="' + f.nro_sucursal + '"]');
            var inp = document.querySelector('.sal-reserva[data-suc="' + f.nro_sucursal + '"]');
            var inpSaldo = document.querySelector('.sal-saldo-local[data-suc="' + f.nro_sucursal + '"]');

            var gestion = sel ? sel.value : f.gestion;
            var reserva = inp ? (parseFloat(inp.value) || 0) : f.reserva;
            var saldo = saldoEnPantalla(f);

            // Un saldo distinto del que vino se marca: es lo que va a quedar
            // como manual al guardar.
            var editado = inpSaldo && Math.abs(saldo - parseFloat(inpSaldo.dataset.original)) > 0.005;

            if (inpSaldo) {
                inpSaldo.classList.toggle('sal-saldo-editado', !!editado);
            }

            if (editado) {
                totales.editados++;
            }

            var neto = saldo - reserva;
            var deposita = (gestion === 'DEPOSITA');
            var aporta = (deposita && neto > 0) ? neto : 0;

            var celdaNeto = document.querySelector('.sal-neto[data-suc="' + f.nro_sucursal + '"]');
            var celdaAporta = document.querySelector('.sal-aporta[data-suc="' + f.nro_sucursal + '"]');

            if (celdaNeto) {
                celdaNeto.innerHTML = pesos(neto);
                celdaNeto.classList.toggle('sal-negativo', neto < 0);
            }

            if (celdaAporta) {
                celdaAporta.innerHTML = pesos(aporta);
                celdaAporta.classList.toggle('sal-no-aporta', aporta === 0);
                celdaAporta.title = deposita
                    ? (neto < 0
                        ? 'La caja está por debajo de la reserva: no aporta, pero tampoco resta'
                        : '')
                    : 'Está en Envía: su efectivo no llega al banco por esta vía';
            }

            var fila = document.querySelector('tr[data-suc="' + f.nro_sucursal + '"]');

            if (fila) {
                fila.classList.toggle('sal-envia', !deposita);
            }

            totales.saldo += saldo;
            totales.reserva += reserva;
            totales.neto += neto;
            totales.aporta += aporta;

            if (!deposita) {
                totales.envian++;
            }
        });

        var t = datosLocales.totales || {};
        var detalle = datosLocales.filas.length + ' local(es) propios';

        // Cuántos no tienen el cierre de ayer: es lo que hay que ir a tipear.
        if (t.desactualizados > 0) {
            detalle += ' · ' + t.desactualizados + ' sin saldo de ayer';
        }

        if (totales.editados > 0) {
            detalle += ' · ' + totales.editados + ' editado(s) sin guardar';
        } else if (t.manuales > 0) {
            detalle += ' · ' + t.manuales + ' cargado(s) a mano';
        }

        texto('totalCajaLocales', pesos(totales.saldo));
        texto('totalReserva', pesos(totales.reserva));
        texto('totalNeto', pesos(totales.neto));
        texto('totalAporta', pesos(totales.aporta));
        texto('detalleLocales', detalle);
        texto('detalleAporta', totales.envian > 0
            ? (totales.envian + ' en Envía quedan afuera')
            : 'Todos los locales depositan');

        document.getElementById('footLocales').innerHTML =
            '<tr class="sal-fila-total">' +
                '<td class="fw-bold">TOTALES</td>' +
                '<td class="text-end fw-bold">' + pesos(totales.saldo) + '</td>' +
                '<td></td><td></td>' +
                '<td class="text-end fw-bold">' + pesos(totales.reserva) + '</td>' +
                '<td class="text-end fw-bold">' + pesos(totales.neto) + '</td>' +
                '<td class="text-end fw-bold">' + pesos(totales.aporta) + '</td>' +
            '</tr>';
    }

    function guardarLocales() {
        var filas = datosLocales.filas.map(function(f) {
            var sel = document.querySelector('.sal-gestion[data-suc="' + f.nro_sucursal + '"]');
            var inp = document.querySelector('.sal-reserva[data-suc="' + f.nro_sucursal + '"]');
            var inpSaldo = document.querySelector('.sal-saldo-local[data-suc="' + f.nro_sucursal + '"]');

            return {
                nro_sucursal: f.nro_sucursal,
                gestion: sel ? sel.value : f.gestion,
                reserva: inp ? (parseFloat(inp.value) || 0) : f.reserva,
                // El saldo viaja tal como está; el servidor decide si es nuevo
                // comparándolo con el que manda hoy. Sin input (tabla de
                // manuales sin crear) no viaja, y el servidor relee la consulta.
                saldo: inpSaldo ? (parseFloat(inpSaldo.value) || 0) : null
            };
        });

        if (!filas.length) {
            avisar('No hay locales para guardar.');
            return;
        }

        conBoton('btnGuardarLocales', function() {
            return pedirJson(URL_SALDOS + '?action=guardarCargaLocales', { filas: filas })
                .then(function(data) {
                    // Lo que importa avisar es lo que el tablero va a usar de
                    // ahora en más: los parámetros que cambiaron y los saldos
                    // que se cargaron a mano. La foto del histórico se guarda
                    // siempre y no es noticia.
                    var partes = [];

                    if (data && data.parametros > 0) {
                        partes.push(data.parametros + ' local(es) con la gestión o la reserva ' +
                            'actualizadas.');
                    }

                    if (data && data.saldos_manuales > 0) {
                        partes.push(data.saldos_manuales + ' saldo(s) en caja cargados a mano: ' +
                            'mandan hasta que la consulta traiga un cierre más nuevo.');
                    }

                    if (partes.length) {
                        texto('avisoGuardadoLocales', partes.join(' ') + ' El tablero ya usa estos valores.');
                        mostrar('avisoGuardadoLocales', true, 'inline-block');
                    }

                    cargarLocales();
                });
        }, 'No se pudo guardar la carga de locales');
    }

    /* ================================================================
       PESTAÑA 3 — FONDOS
       ================================================================ */

    function cargarFondos() {
        fondosPedidos = true;
        mostrar('loadingFondos', true, 'flex');
        mostrar('wrapperFondos', false);
        mostrar('formMovimiento', false);

        pedirJson(URL_SALDOS + '?action=getFondos')
            .then(function(data) {
                datosFondos = data;

                pintarAvisos();
                pintarKpiFondos();
                pintarTablaFondos();
                pintarSelectorCuentas();

                // Sin el script no hay dónde cargar: el botón lo dice en vez de
                // dejar abrir un formulario que va a fallar al guardar.
                var btn = document.getElementById('btnNuevoMovimiento');

                if (btn) {
                    btn.disabled = !data.creado || !data.cuentas.length;
                    btn.title = !data.creado
                        ? 'Falta correr sql/cashflow_saldos_cuentas_fondo.sql'
                        : (!data.cuentas.length
                            ? 'No hay ninguna cuenta de fondo dada de alta'
                            : '');
                }

                mostrar('loadingFondos', false);
                mostrar('wrapperFondos', true);
            })
            .catch(function(error) {
                mostrar('loadingFondos', false);
                Notificacion.error('No se pudieron cargar los fondos: ' + error.message);
            });
    }

    /**
     * Una tarjeta por clase y moneda, con lo que el servidor ya totalizó. No se
     * mezclan monedas: un fondo en pesos y uno en dólares son dos tarjetas.
     */
    function pintarKpiFondos() {
        var cont = document.getElementById('kpiFondos');

        if (!cont) {
            return;
        }

        var totales = (datosFondos && datosFondos.totales) || [];
        var iconos = { INVERSION: 'blue', COMITENTE: 'green' };

        if (!totales.length) {
            cont.innerHTML = '<div class="col-12"><div class="kpi-card">' +
                '<div class="kpi-card-header"><span class="kpi-card-title">Fondos</span></div>' +
                '<div class="kpi-card-value kpi-card-value-sm">Sin cuentas de fondo</div>' +
                '<div class="kpi-card-footer"><span class="text-muted">Se dan de alta en ' +
                'Parámetros → Saldos</span></div></div></div>';

            return;
        }

        cont.innerHTML = totales.map(function(t) {
            return '<div class="col-md-6 col-lg-3"><div class="kpi-card">' +
                '<div class="kpi-card-header">' +
                    '<span class="kpi-card-title">' + escapar(etiquetaClase(t.clase)) +
                        ' en ' + escapar(t.moneda) + '</span>' +
                    '<div class="kpi-card-icon ' + (iconos[t.clase] || 'blue') + '">' +
                        '<i class="fas fa-chart-line"></i></div>' +
                '</div>' +
                '<div class="kpi-card-value">' + importe(t.saldo, t.moneda) + '</div>' +
                '<div class="kpi-card-footer"><span class="text-muted">' +
                    t.cuentas + ' cuenta(s) · saldo a hoy · stock de cobertura</span></div>' +
                '</div></div>';
        }).join('');
    }

    function pintarTablaFondos() {
        var html = '';
        var cuentas = (datosFondos && datosFondos.cuentas) || [];

        cuentas.forEach(function(c) {
            var sinInicial = (c.SALDO_INICIAL === null || c.SALDO_INICIAL === undefined);

            html += '<tr data-cuenta="' + c.ID + '"' + (sinInicial ? ' class="sal-sin-cargar"' : '') + '>';
            html += '<td class="fw-semibold">' + escapar(c.NOMBRE) +
                    (c.posteriores > 0
                        ? '<div class="sal-subtitulo">' + c.posteriores + ' movimiento(s) con ' +
                          'fecha futura, fuera del saldo de hoy</div>'
                        : '') +
                    (c.incluidos > 0
                        ? '<div class="sal-subtitulo">' + c.incluidos + ' movimiento(s) ' +
                          'anteriores al saldo inicial, ya incluidos en él</div>'
                        : '') +
                    '</td>';
            html += '<td><span class="sal-badge">' + escapar(etiquetaClase(c.CLASE)) + '</span></td>';
            html += '<td class="text-center">' + escapar(c.MONEDA) + '</td>';

            // "sin saldo inicial" y no un cero: arranca de cero porque nadie lo
            // cargó, y eso el tablero lo avisa.
            html += '<td class="text-end sal-saldo">' + (sinInicial
                ? '<span class="text-muted" title="Sin saldo inicial: el saldo arranca de cero ' +
                  'y sólo cuenta los movimientos. Se carga en Parámetros → Saldos.">sin saldo inicial</span>'
                : importe(c.SALDO_INICIAL, c.MONEDA) +
                  '<div class="sal-subtitulo">al ' + fecha(c.FECHA_SALDO_INICIAL) + '</div>') + '</td>';
            html += '<td class="text-end sal-saldo">' + importe(c.suscripciones, c.MONEDA) + '</td>';
            html += '<td class="text-end sal-saldo">' + importe(c.rescates, c.MONEDA) + '</td>';
            html += '<td class="text-end sal-saldo fw-bold">' + importe(c.saldo, c.MONEDA) + '</td>';
            html += '<td class="text-center">' + (c.ultimo_movimiento ? fecha(c.ultimo_movimiento) : '—') + '</td>';
            html += '<td class="text-center">' +
                    '<button class="btn btn-sm btn-outline-secondary sal-btn-movimientos" ' +
                        'data-cuenta="' + c.ID + '" title="Ver y corregir los movimientos de esta cuenta">' +
                        '<i class="fas fa-list me-1"></i>' + c.movimientos + '</button>' +
                    '</td>';
            html += '</tr>';
        });

        if (!cuentas.length) {
            html = '<tr><td colspan="9" class="text-center text-muted py-4">' +
                   (datosFondos && datosFondos.creado
                       ? 'No hay cuentas de inversión ni comitente. Cargalas en Parámetros → Saldos.'
                       : 'Falta correr sql/cashflow_saldos_cuentas_fondo.sql.') + '</td></tr>';
        }

        document.getElementById('bodyFondos').innerHTML = html;

        // El pie repite los totales del servidor, uno por clase y moneda.
        document.getElementById('footFondos').innerHTML =
            ((datosFondos && datosFondos.totales) || []).map(function(t) {
                return '<tr class="sal-fila-total">' +
                    '<td colspan="6" class="fw-bold text-end">' + escapar(etiquetaClase(t.clase)) +
                        ' en ' + escapar(t.moneda) + '</td>' +
                    '<td class="text-end fw-bold">' + importe(t.saldo, t.moneda) + '</td>' +
                    '<td colspan="2"></td></tr>';
            }).join('');

        document.querySelectorAll('.sal-btn-movimientos').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirMovimientos(parseInt(btn.dataset.cuenta, 10));
            });
        });
    }

    function pintarSelectorCuentas() {
        var sel = document.getElementById('movCuenta');

        if (!sel) {
            return;
        }

        sel.innerHTML = ((datosFondos && datosFondos.cuentas) || []).map(function(c) {
            return '<option value="' + c.ID + '" data-moneda="' + escapar(c.MONEDA) + '">' +
                escapar(c.NOMBRE) + ' (' + escapar(etiquetaClase(c.CLASE)) + ', ' +
                escapar(c.MONEDA) + ')</option>';
        }).join('');

        pintarMonedaMovimiento();
    }

    /** La moneda al lado del importe es la de la cuenta elegida: no se elige */
    function pintarMonedaMovimiento() {
        var sel = document.getElementById('movCuenta');
        var opt = sel && sel.options[sel.selectedIndex];

        texto('movMoneda', (opt && opt.dataset.moneda === 'USD') ? 'US$' : '$');
    }

    /**
     * Abre el formulario, vacío para un alta o cargado con el movimiento que
     * se corrige. La corrección viaja con id_reemplaza: el servidor da de baja
     * el anterior e inserta el nuevo, en una transacción.
     */
    function abrirFormMovimiento(mov) {
        movReemplaza = mov ? mov.ID : null;

        var sel = document.getElementById('movCuenta');

        if (mov && sel) {
            sel.value = String(mov.ID_CUENTA);
        }

        if (sel) {
            sel.disabled = !!mov;
        }

        pintarMonedaMovimiento();

        document.getElementById('movFecha').value = mov ? mov.FECHA : ((datosFondos && datosFondos.hoy) || '');
        document.getElementById('movTipo').value = mov ? mov.TIPO : 'SUSCRIPCION';
        document.getElementById('movImporte').value = mov ? mov.IMPORTE : '';
        document.getElementById('movObservacion').value = mov ? (mov.OBSERVACION || '') : '';

        texto('movAyuda', mov
            ? 'Estás corrigiendo el movimiento del ' + fecha(mov.FECHA) + ': el anterior queda ' +
              'en el historial, dado de baja, y éste lo reemplaza.'
            : 'El importe va siempre en positivo: el signo lo pone el tipo. Un movimiento ' +
              'con fecha futura se lista pero no entra al saldo de hoy.');

        if (modalMovimientos) {
            modalMovimientos.hide();
        }

        mostrar('formMovimiento', true);
        document.getElementById('movImporte').focus();
    }

    function guardarMovimiento() {
        var idCuenta = document.getElementById('movCuenta').value;
        var fechaMov = document.getElementById('movFecha').value;
        var importeMov = document.getElementById('movImporte').value;

        if (!idCuenta) {
            Notificacion.campoInvalido('movCuenta', 'Elegí la cuenta.');
            return;
        }

        if (!fechaMov) {
            Notificacion.campoInvalido('movFecha', 'Elegí la fecha del movimiento.');
            return;
        }

        if (importeMov === '' || isNaN(Number(importeMov)) || Number(importeMov) <= 0) {
            Notificacion.campoInvalido('movImporte',
                'El importe tiene que ser mayor que cero: el signo lo pone el tipo.');
            return;
        }

        var corrige = movReemplaza;

        conBoton('btnGuardarMovimiento', function() {
            return pedirJson(URL_SALDOS + '?action=guardarMovimientoFondo', {
                id_cuenta: parseInt(idCuenta, 10),
                fecha: fechaMov,
                tipo: document.getElementById('movTipo').value,
                importe: Number(importeMov),
                observacion: document.getElementById('movObservacion').value,
                id_reemplaza: corrige
            }).then(function() {
                Notificacion.exito(corrige ? 'Movimiento corregido.' : 'Movimiento cargado.', {
                    detalle: 'El saldo del fondo y el stock del tablero ya lo reflejan.'
                });
                movReemplaza = null;
                mostrar('formMovimiento', false);
                cargarFondos();
            });
        }, 'No se pudo guardar el movimiento');
    }

    /**
     * El historial completo de una cuenta, en el modal: vigentes, pisados y
     * dados de baja. Un pisado se ve tachado y dice cuál lo reemplazó.
     */
    function abrirMovimientos(idCuenta) {
        var cuenta = ((datosFondos && datosFondos.cuentas) || []).filter(function(c) {
            return c.ID === idCuenta;
        })[0];

        if (!cuenta) {
            return;
        }

        texto('movimientosCuenta', cuenta.NOMBRE);
        document.getElementById('bodyMovimientos').innerHTML =
            '<tr><td colspan="7" class="text-center text-muted py-3">Cargando...</td></tr>';

        if (modalMovimientos) {
            modalMovimientos.show();
        }

        pedirJson(URL_SALDOS + '?action=getMovimientosFondo&id_cuenta=' + idCuenta)
            .then(function(movs) {
                pintarMovimientos(cuenta, movs);
            })
            .catch(function(error) {
                Notificacion.error('No se pudieron leer los movimientos: ' + error.message);
            });
    }

    function pintarMovimientos(cuenta, movs) {
        var hoy = (datosFondos && datosFondos.hoy) || '';
        var desde = cuenta.FECHA_SALDO_INICIAL || null;
        var html = '';

        movs.forEach(function(m) {
            var vigente = (parseInt(m.VIGENTE, 10) === 1);
            var incluido = vigente && desde && m.FECHA <= desde;
            var futuro = vigente && hoy && m.FECHA > hoy;
            var estado, titulo;

            if (!vigente) {
                // Dado de baja a secas, o corregido por otro: la diferencia es
                // si alguien apunta a este como su reemplazado.
                var nuevo = reemplazadoPor(movs, m.ID);

                estado = (nuevo === null) ? 'Dado de baja' : 'Corregido';
                titulo = 'No cuenta para el saldo.' +
                    (m.FECHA_BAJA ? ' Dado de baja el ' + fechaHora(m.FECHA_BAJA) + '.' : '') +
                    (nuevo !== null ? ' Lo reemplaza el movimiento #' + nuevo + '.' : '');
            } else if (incluido) {
                estado = 'En saldo inicial';
                titulo = 'Anterior o igual a la fecha del saldo inicial (' + fecha(desde) +
                    '): ya está incluido en él y no se vuelve a sumar.';
            } else if (futuro) {
                estado = 'Futuro';
                titulo = 'Posterior a hoy: no entra al saldo de hoy ni al stock del tablero.';
            } else {
                estado = 'Vigente';
                titulo = 'Cuenta para el saldo de hoy.';
            }

            var suscripcion = (m.TIPO === 'SUSCRIPCION');

            html += '<tr class="' + (vigente ? '' : 'sal-mov-baja') +
                    (incluido || futuro ? ' sal-mov-fuera' : '') + '">';
            html += '<td class="text-center">' + fecha(m.FECHA) + '</td>';
            html += '<td><span class="sal-badge ' + (suscripcion ? 'sal-badge-mas' : 'sal-badge-menos') + '">' +
                    (suscripcion ? '+ Suscripción' : '− Rescate') + '</span></td>';
            html += '<td class="text-end sal-saldo">' + importe(m.IMPORTE, m.MONEDA) + '</td>';
            html += '<td class="sal-obs">' + escapar(m.OBSERVACION || '') + '</td>';
            html += '<td class="text-center"><span class="sal-origen" title="' + escapar(titulo) + '">' +
                    estado + '</span></td>';
            html += '<td class="text-center sal-fecha-carga">' + fechaHora(m.FECHA_ALTA) +
                    (m.USUARIO ? '<div class="sal-subtitulo">' + escapar(m.USUARIO) + '</div>' : '') + '</td>';
            html += '<td class="text-center text-nowrap">' + (vigente
                ? '<button class="btn btn-sm btn-outline-primary sal-btn-corregir" data-id="' + m.ID +
                      '" title="Corregir: da de baja este movimiento e inserta uno nuevo">' +
                      '<i class="fas fa-pen"></i></button> ' +
                  '<button class="btn btn-sm btn-outline-danger sal-btn-baja" data-id="' + m.ID +
                      '" title="Dar de baja: queda en el historial, tachado">' +
                      '<i class="fas fa-ban"></i></button>'
                : '') + '</td>';
            html += '</tr>';
        });

        if (!movs.length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-3">' +
                   'Esta cuenta todavía no tiene movimientos.</td></tr>';
        }

        var cuerpo = document.getElementById('bodyMovimientos');

        cuerpo.innerHTML = html;

        cuerpo.querySelectorAll('.sal-btn-corregir').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var mov = movs.filter(function(m) { return m.ID === parseInt(btn.dataset.id, 10); })[0];

                if (mov) {
                    abrirFormMovimiento(mov);
                }
            });
        });

        cuerpo.querySelectorAll('.sal-btn-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var mov = movs.filter(function(m) { return m.ID === parseInt(btn.dataset.id, 10); })[0];

                if (!mov) {
                    return;
                }

                Notificacion.confirmar({
                    titulo: 'Dar de baja el movimiento',
                    mensaje: '¿Dar de baja el movimiento del ' + fecha(mov.FECHA) + ' por ' +
                        importe(mov.IMPORTE, mov.MONEDA) + '?',
                    detalle: 'No se borra: queda en el historial de la cuenta, tachado, y deja ' +
                        'de contar para el saldo.',
                    confirmar: 'Dar de baja',
                    peligro: true
                }).then(function(ok) {
                    if (!ok) {
                        return;
                    }

                    pedirJson(URL_SALDOS + '?action=bajaMovimientoFondo', {
                        id_cuenta: cuenta.ID, id: mov.ID
                    }).then(function() {
                        Notificacion.exito('Movimiento dado de baja.');
                        abrirMovimientos(cuenta.ID);
                        cargarFondos();
                    }).catch(function(error) {
                        Notificacion.error('No se pudo dar de baja: ' + error.message);
                    });
                });
            });
        });
    }

    /** El ID del movimiento que reemplazó a otro, o null si nadie lo reemplazó */
    function reemplazadoPor(movs, id) {
        for (var i = 0; i < movs.length; i++) {
            if (movs[i].ID_REEMPLAZA === id) {
                return movs[i].ID;
            }
        }

        return null;
    }

    function etiquetaClase(clase) {
        var clases = (datosFondos && datosFondos.clases) || {};

        return clases[clase] || clase;
    }

    /* ================================================================
       AVISOS Y UTILIDADES
       ================================================================ */

    /**
     * Los avisos no son decoración: son lo que evita leer un cero como si fuera
     * un dato. Se juntan los de las tres sub-pestañas en un solo bloque arriba.
     */
    function pintarAvisos() {
        var avisos = []
            .concat((datosSaldos && datosSaldos.avisos) || [])
            .concat((datosLocales && datosLocales.avisos) || [])
            .concat((datosFondos && datosFondos.avisos) || []);

        var cont = document.getElementById('avisosSaldos');

        if (!cont) {
            return;
        }

        if (!avisos.length) {
            cont.innerHTML = '';
            return;
        }

        cont.innerHTML = '<div class="alert alert-warning py-2 px-3 mb-3">' +
            '<i class="fas fa-triangle-exclamation me-1"></i>' +
            '<small><ul class="mb-0 ps-3">' +
            avisos.map(function(a) { return '<li>' + escapar(a) + '</li>'; }).join('') +
            '</ul></small></div>';
    }

    /** Corre una acción mostrando el estado en el botón */
    function conBoton(id, accion, mensajeError) {
        var btn = document.getElementById(id);
        var original = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

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

    /** Esconde un cartel pasado un rato, si todavía está visible */
    function ocultarLuegoDe(id, ms) {
        var el = document.getElementById(id);

        if (!el || el.style.display === 'none') {
            return;
        }

        setTimeout(function() {
            mostrar(id, false);
        }, ms);
    }

    function filaTotal(rotulo, valor) {
        return '<tr class="sal-fila-total">' +
                   '<td colspan="3" class="fw-bold text-end">' + rotulo + '</td>' +
                   '<td class="text-end fw-bold">' + valor + '</td>' +
                   '<td colspan="3"></td>' +
               '</tr>';
    }

    function etiquetaTipo(tipo) {
        var tipos = {
            'BANCO': 'Banco',
            'MERCADO_PAGO': 'Mercado Pago',
            'EFECTIVO_CENTRAL': 'Efectivo',
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

    function importe(valor, moneda) {
        return (moneda === 'USD') ? dolares(valor) : pesos(valor);
    }

    function pesos(valor) {
        return '$ ' + numero(valor);
    }

    function dolares(valor) {
        return 'US$ ' + numero(valor);
    }

    function numero(valor) {
        return (parseFloat(valor) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fecha(valor) {
        if (!valor) {
            return '—';
        }

        var p = String(valor).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(valor);
    }

    /** 'YYYY-MM-DD HH:MM:SS' -> 'dd/mm/yyyy HH:MM' */
    function fechaHora(valor) {
        if (!valor) {
            return '—';
        }

        var s = String(valor);

        return fecha(s) + (s.length > 10 ? (' ' + s.slice(11, 16)) : '');
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
        }
    }

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

    function avisar(mensaje) {
        console.error(mensaje);
        alert(mensaje);
    }

})(); // Fin del IIFE
