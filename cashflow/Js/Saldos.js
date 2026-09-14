/**
 * Saldos JavaScript
 *
 * Dos sub-pestañas independientes:
 *   Saldos         -> disponible inicial: efectivo de tesorería, bancos y Mercado Pago
 *   Saldos Locales -> caja de los locales propios
 *
 * Se piden por separado y la segunda es LAZY: se consulta recién cuando se abre
 * su pestaña. Esa consulta va contra el servidor de locales, que puede estar
 * caído, y no tiene por qué demorar ni romper la pestaña que se abre primero.
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

        // La pestaña 2 se pide la primera vez que se abre y no antes.
        var tabLocales = document.getElementById('tabLocalesBtn');

        if (tabLocales) {
            tabLocales.addEventListener('shown.bs.tab', function() {
                if (!localesPedidos) {
                    localesPedidos = true;
                    cargarLocales();
                }
            });
        }

        cargarSaldos();
        abrirSubTabPedida();
    }

    /**
     * Si se llegó acá desde la fila "Caja Locales" del tablero, se abre
     * directamente la sub-pestaña de locales.
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

        if (destino !== 'locales') {
            return;
        }

        var btn = document.getElementById('tabLocalesBtn');

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
       AVISOS Y UTILIDADES
       ================================================================ */

    /**
     * Los avisos no son decoración: son lo que evita leer un cero como si fuera
     * un dato. Se juntan los de las dos sub-pestañas en un solo bloque arriba.
     */
    function pintarAvisos() {
        var avisos = []
            .concat((datosSaldos && datosSaldos.avisos) || [])
            .concat((datosLocales && datosLocales.avisos) || []);

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
