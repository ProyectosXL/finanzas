/**
 * Parametros -> Generales
 *
 * Lo que afecta a TODO el modulo: el eje de proyeccion, la alicuota, los
 * feriados de comercio, la inflacion mensual y el cronograma de pagos. Mas las
 * dos cotizaciones, que se dibujan y no se editan.
 *
 * POR QUE LA GRILLA DE INFLACION MUESTRA MESES QUE YA PASARON
 * -----------------------------------------------------------
 * Un ajuste trimestral suma la inflacion de su mes y la de los dos anteriores,
 * asi que un valor hora cuya base es anterior a hoy necesita meses vencidos. Si
 * la grilla arrancara el mes que viene, ese dato no habria donde cargarlo y el
 * mes quedaria sin proyectar para siempre, avisando que falta un numero que la
 * pantalla no ofrece tipear. Los meses pasados van marcados: se editan, pero no
 * se proyectan.
 *
 * POR QUE EL CRONOGRAMA VA EN DOS TABLAS
 * ---------------------------------------
 * Arriba, las fechas del TRAMO DIARIO: son las unicas donde el dia exacto mueve
 * una columna del tablero, y son las unicas editables. Abajo, el resto del
 * horizonte: se calculan igual porque son las que deciden que mitad de un
 * importe mensual cae dentro del tramo, pero editarlas no cambiaria ningun
 * numero. Una sola tabla con la mitad de las filas editables y la otra mitad no
 * se lee como un error de la pantalla.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/ParametrosController.php';

    /** Los parametros del eje, en el orden en que se leen */
    var ORDEN = ['horizonte_dias', 'horizonte_meses', 'alicuota_iva', 'feriados_comercio'];

    /**
     * Como se dibuja y se valida cada uno.
     *
     * 'porcentaje' se MUESTRA en % y se GUARDA en tasa: la alicuota vive como
     * 0,21 en la base. La inflacion hace lo contrario -vive en puntos- y por eso
     * esto va dicho: son dos criterios distintos en la misma pantalla.
     */
    var FORMATO = {
        horizonte_dias: { tipo: 'entero', sufijo: 'días', paso: '1', min: 0, max: 365 },
        horizonte_meses: { tipo: 'entero', sufijo: 'meses', paso: '1', min: 1, max: 60 },
        alicuota_iva: { tipo: 'porcentaje', sufijo: '%', paso: '0.01', min: 0, max: 100 },
        feriados_comercio: { tipo: 'texto', sufijo: '', paso: null }
    };

    var ETIQUETAS = {
        horizonte_dias: 'Columnas diarias',
        horizonte_meses: 'Columnas mensuales',
        alicuota_iva: 'Alícuota de IVA',
        feriados_comercio: 'Feriados de comercio'
    };

    /**
     * La nota que va debajo de cada campo.
     *
     * NO REPITE LA DESCRIPCION, que ya viene de la base: dice la CONSECUENCIA de
     * tocarlo, que es lo que no se deduce del nombre.
     */
    var HINTS = {
        horizonte_dias:
            'El tramo diario arranca HOY. Subirlo mueve importes de una columna mensual a ' +
            'una diaria, no los agrega: un importe va a un día O al mes, nunca a los dos.',
        horizonte_meses:
            'La vista Meses NO cubre el horizonte completo: cada columna mensual acumula ' +
            'sólo los días de ese mes que quedan fuera del tramo diario.',
        alicuota_iva:
            'Se aplica sobre la venta neta proyectada de los cuatro canales. Se tipea en % ' +
            'y se guarda como tasa.',
        feriados_comercio:
            'Formato MM-DD separado por coma. Son los únicos días del año sin venta ' +
            'estimada, y el divisor de la venta diaria los descuenta, así que el total ' +
            'del mes no cambia.'
    };

    /** Los dos nombres de pago, para no repetir el condicional */
    var NOMBRE_PAGO = { 1: '2do viernes', 2: '4to viernes' };

    var valores = {};
    var inflacion = null;
    var cronograma = null;
    var editando = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        pedir(ENDPOINT + '?action=getTodo')
            .then(function(d) {
                var modulo = buscarModulo(d.data, 'GENERALES');

                if (!modulo) {
                    avisar(['El módulo Generales no está declarado en Parámetros.']);

                    return;
                }

                var desc = document.getElementById('pgenDescripcion');

                if (desc && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' +
                        esc(modulo.descripcion);
                }

                valores = {};

                (modulo.generales || []).forEach(function(p) { valores[p.CLAVE] = p; });

                inflacion = modulo.inflacion || null;
                cronograma = modulo.cronograma || null;

                avisar(modulo.avisos || []);
                pintarGrid();
                pintarInflacion();
                pintarCronograma();
                pintarCotizaciones(modulo.cotizaciones);
            })
            .catch(function(e) {
                avisar(['No se pudieron leer los parámetros generales: ' + e.message]);
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

    /* ================================================================
       EL EJE Y LA ALICUOTA
       ================================================================ */

    function pintarGrid() {
        var grid = document.getElementById('pgenGrid');

        if (!grid) { return; }

        /* SE DIBUJA LO QUE VINO, en el orden declarado y con lo que no esté en
           ORDEN al final: una clave que alguien agregue al módulo tiene que
           verse, aunque este archivo no la conozca. Lo contrario es un
           parámetro que existe y no aparece en ningún lado. */
        var claves = ORDEN.filter(function(c) { return valores[c]; });

        Object.keys(valores).forEach(function(c) {
            if (claves.indexOf(c) === -1) { claves.push(c); }
        });

        if (!claves.length) {
            grid.innerHTML = '<div class="col-12"><div class="param-hint">' +
                'No hay ningún parámetro cargado en este módulo.</div></div>';

            return;
        }

        grid.innerHTML = claves.map(function(clave) {
            var p = valores[clave];
            var fmt = FORMATO[clave] || { tipo: 'texto', sufijo: '', paso: null };
            var valor = p.VALOR;

            if (fmt.tipo === 'porcentaje') {
                valor = (parseFloat(valor) * 100).toFixed(2);
            }

            var input = (fmt.tipo === 'texto')
                ? '<input type="text" class="form-control pgen-input text-start" ' +
                      'data-clave="' + esc(clave) + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + esc(valor) + '">'
                : '<input type="number" step="' + fmt.paso + '" class="form-control pgen-input" ' +
                      'data-clave="' + esc(clave) + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + esc(valor) + '">';

            return '<div class="col-md-6 col-lg-3">' +
                '<div class="param-card" id="pgen-card-' + esc(clave) + '">' +
                    '<div class="param-clave">' + esc(ETIQUETAS[clave] || etiqueta(clave)) + '</div>' +
                    '<div class="param-descripcion">' + esc(p.DESCRIPCION || '') + '</div>' +
                    '<div class="input-group input-group-sm">' +
                        input +
                        (fmt.sufijo
                            ? '<span class="input-group-text">' + esc(fmt.sufijo) + '</span>'
                            : '') +
                    '</div>' +
                    '<div class="param-hint">' + esc(HINTS[clave] || '') + '</div>' +
                '</div>' +
            '</div>';
        }).join('');

        grid.querySelectorAll('.pgen-input').forEach(function(input) {
            input.addEventListener('change', function() { guardarParametro(input); });
        });
    }

    function guardarParametro(input) {
        var clave = input.getAttribute('data-clave');
        var tipo = input.getAttribute('data-tipo');
        var fmt = FORMATO[clave] || {};
        var valor = input.value;
        var etiq = ETIQUETAS[clave] || etiqueta(clave);

        if (tipo === 'porcentaje' || tipo === 'entero') {
            var n = Number(valor);

            /* SE VALIDA ANTES DE MANDAR, y el mensaje dice el rango: un
               horizonte de cero meses no falla en la base -es un número
               válido- pero deja el tablero sin ninguna columna. */
            if (valor === '' || isNaN(n) || n < fmt.min || n > fmt.max) {
                Notificacion.campoInvalido(input,
                    etiq + ' tiene que ser un número entre ' + fmt.min + ' y ' + fmt.max + '.');

                return;
            }

            if (tipo === 'entero' && Math.floor(n) !== n) {
                Notificacion.campoInvalido(input, etiq + ' tiene que ser un entero.');

                return;
            }

            valor = (tipo === 'porcentaje') ? String(n / 100) : String(n);
        }

        pedir(ENDPOINT + '?action=saveParametro', { clave: clave, valor: valor })
            .then(function() {
                if (valores[clave]) { valores[clave].VALOR = valor; }

                destacar('pgen-card-' + clave);

                /* EL HORIZONTE MUEVE EL CRONOGRAMA, así que se vuelve a pedir
                   todo. Sin esto, la tabla de arriba seguiría mostrando el
                   tramo diario viejo y las fechas editables serían otras. */
                if (clave === 'horizonte_dias' || clave === 'horizonte_meses') {
                    cargar();
                }
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar ' + etiq + ': ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                cargar();
            });
    }

    /* ================================================================
       INFLACION
       ================================================================ */

    function pintarInflacion() {
        var cuerpo = document.getElementById('pgenInflacionBody');
        var selector = document.getElementById('pgenModalidad');
        var pctInput = document.getElementById('pgenPctConstante');

        if (!cuerpo || !inflacion) { return; }

        if (selector) { selector.value = inflacion.modalidad || 'VARIABLE'; }

        if (pctInput && inflacion.pct_constante !== null) {
            pctInput.value = inflacion.pct_constante;
        }

        mostrarConstante(inflacion.modalidad === 'CONSTANTE');

        var editable = !!inflacion.tabla_creada;

        cuerpo.innerHTML = (inflacion.meses || []).map(function(m) {
            var input = '<input type="number" step="0.01" class="form-control form-control-sm ' +
                'text-end pgen-infla" data-mes="' + esc(m.mes) + '" ' +
                'value="' + (m.porcentaje === null ? '' : esc(m.porcentaje)) + '"' +
                (editable ? '' : ' disabled') + '>';

            /* UN MES SIN CARGAR SE MARCA, y no se muestra como cero: la
               diferencia entre "no hay dato" y "el dato es cero" es lo que
               decide si un ajuste se puede calcular. */
            var falta = (m.porcentaje === null)
                ? '<span class="badge bg-warning text-dark ms-2">sin cargar</span>' : '';

            return '<tr class="' + (m.proyecta ? '' : 'table-light') + '">' +
                '<td class="fw-semibold">' + esc(rotuloMes(m.mes)) + falta + '</td>' +
                '<td><div class="input-group input-group-sm">' + input +
                    '<span class="input-group-text">%</span></div></td>' +
                '<td class="text-center"><small class="text-muted">' +
                    esc(m.modalidad || '—') + '</small></td>' +
                '<td><small class="text-muted">' +
                    (m.fecha_update ? esc(m.fecha_update.substring(0, 16)) : '—') +
                    (m.usuario ? ' — ' + esc(m.usuario) : '') + '</small></td>' +
                '<td><small class="text-muted">' +
                    (m.proyecta ? 'Sí' : 'Ya pasó: sólo sirve para un ajuste viejo') +
                    '</small></td>' +
            '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.pgen-infla').forEach(function(input) {
            input.addEventListener('change', function() { guardarInflacion(input); });
        });
    }

    function mostrarConstante(visible) {
        var wrap = document.getElementById('pgenConstanteWrap');

        if (wrap) { wrap.style.visibility = visible ? 'visible' : 'hidden'; }
    }

    function guardarInflacion(input) {
        var mes = input.getAttribute('data-mes');
        var n = Number(input.value);

        if (input.value === '' || isNaN(n)) {
            Notificacion.campoInvalido(input,
                'El porcentaje de ' + rotuloMes(mes) + ' tiene que ser un número.');

            return;
        }

        pedir(ENDPOINT + '?action=saveInflacionMes', { mes: mes, porcentaje: n })
            .then(function() { cargar(); })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar la inflación de ' + rotuloMes(mes) +
                    ': ' + e.message, { detalle: 'El campo vuelve al último valor guardado.' });
                cargar();
            });
    }

    function guardarModalidad() {
        var selector = document.getElementById('pgenModalidad');

        if (!selector) { return; }

        var modalidad = selector.value;

        mostrarConstante(modalidad === 'CONSTANTE');

        pedir(ENDPOINT + '?action=saveParametro',
              { clave: 'inflacion_modalidad', valor: modalidad })
            .then(function() {
                if (inflacion) { inflacion.modalidad = modalidad; }

                /* CAMBIAR LA MODALIDAD NO TOCA NINGÚN MES. Sólo cambia cómo se
                   edita: lo que el cálculo lee sigue siendo la grilla. Para que
                   la constante rija hay que apretar Aplicar. */
                Notificacion.exito('Modalidad guardada.', {
                    detalle: modalidad === 'CONSTANTE'
                        ? 'Los meses siguen con el valor que tenían: para que el % único rija, ' +
                          'apretá "Aplicar a todos".'
                        : 'Ahora cada mes se edita por separado.'
                });
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar la modalidad: ' + e.message);
                cargar();
            });
    }

    function aplicarConstante() {
        var input = document.getElementById('pgenPctConstante');

        if (!input) { return; }

        var n = Number(input.value);

        if (input.value === '' || isNaN(n)) {
            Notificacion.campoInvalido(input, 'El porcentaje tiene que ser un número.');

            return;
        }

        pedir(ENDPOINT + '?action=aplicarInflacionConstante', { porcentaje: n })
            .then(function(d) {
                Notificacion.exito((d.message || 'Inflación aplicada.'));
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo aplicar el porcentaje: ' + e.message);
            });
    }

    /* ================================================================
       CRONOGRAMA
       ================================================================ */

    function pintarCronograma() {
        var cuerpo = document.getElementById('pgenCronogramaBody');
        var resto = document.getElementById('pgenCronoRestoBody');
        var pie = document.getElementById('pgenCronoTramo');

        if (!cuerpo || !cronograma) { return; }

        var pagos = cronograma.pagos || [];
        var enTramo = pagos.filter(function(p) { return p.en_tramo; });
        var fuera = pagos.filter(function(p) { return !p.en_tramo; });

        if (pie) {
            pie.innerHTML = cronograma.fin_tramo
                ? 'Tramo diario: del <strong>' + esc(fecha(cronograma.hoy)) + '</strong> al ' +
                  '<strong>' + esc(fecha(cronograma.fin_tramo)) + '</strong>. ' +
                  'Hay <strong>' + enTramo.length + '</strong> pago(s) del cronograma adentro.'
                : 'El horizonte no tiene tramo diario (columnas diarias en 0), así que no hay ' +
                  'ninguna fecha editable: todos los pagos van a la columna de su mes.';
        }

        var editable = !!cronograma.tabla_creada;

        cuerpo.innerHTML = enTramo.length
            ? enTramo.map(function(p) { return filaCrono(p, editable); }).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-3">' +
              'Ningún pago del cronograma cae dentro del tramo diario.</td></tr>';

        cuerpo.querySelectorAll('[data-editar]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirModal(btn.getAttribute('data-mes'), Number(btn.getAttribute('data-nro')));
            });
        });

        if (!resto) { return; }

        /* El resto va de a un renglón por MES con sus dos fechas al lado: es
           como se lee un cronograma, y con una fila por pago la tabla duplica
           el largo sin decir nada más. */
        var porMes = {};

        fuera.forEach(function(p) {
            porMes[p.mes] = porMes[p.mes] || {};
            porMes[p.mes][p.nro] = p;
        });

        resto.innerHTML = Object.keys(porMes).sort().map(function(mes) {
            return '<tr>' +
                '<td class="fw-semibold">' + esc(rotuloMes(mes)) + '</td>' +
                '<td>' + celdaResto(porMes[mes][1]) + '</td>' +
                '<td>' + celdaResto(porMes[mes][2]) + '</td>' +
            '</tr>';
        }).join('');
    }

    function filaCrono(p, editable) {
        var marca = '';

        if (p.override) {
            marca = '<span class="badge bg-info text-dark ms-2">a mano</span>';
        } else if (p.corrida) {
            marca = '<span class="badge bg-warning text-dark ms-2" ' +
                'title="El viernes no es hábil: el pago se corrió al día hábil anterior">' +
                'corrida</span>';
        }

        return '<tr>' +
            '<td class="fw-semibold">' + esc(rotuloMes(p.mes)) + '</td>' +
            '<td><small class="text-muted">' + esc(NOMBRE_PAGO[p.nro]) + '</small></td>' +
            '<td><small class="text-muted">' + esc(fecha(p.teorica)) + '</small></td>' +
            '<td><small class="text-muted">' + esc(fecha(p.calculada)) + '</small></td>' +
            '<td class="fw-semibold">' + esc(fecha(p.fecha)) + marca + '</td>' +
            '<td><small class="text-muted">' + esc(p.motivo || '—') + '</small></td>' +
            '<td class="text-center">' +
                '<button class="btn btn-sm btn-outline-secondary" data-editar="1" ' +
                    'data-mes="' + esc(p.mes) + '" data-nro="' + p.nro + '"' +
                    (editable ? '' : ' disabled title="Falta correr sql/cashflow_parametros_generales.sql"') +
                    '><i class="fas fa-pen"></i></button>' +
            '</td>' +
        '</tr>';
    }

    function celdaResto(p) {
        if (!p) { return '—'; }

        return esc(fecha(p.fecha)) +
            (p.override ? ' <span class="badge bg-info text-dark">a mano</span>' : '') +
            (p.corrida ? ' <span class="badge bg-warning text-dark">corrida</span>' : '');
    }

    /* ================================================================
       EL MODAL DE UNA FECHA
       ================================================================ */

    function abrirModal(mes, nro) {
        var pago = (cronograma.pagos || []).filter(function(p) {
            return p.mes === mes && p.nro === nro;
        })[0];

        if (!pago) { return; }

        editando = pago;

        texto('pgenFechaTitulo', rotuloMes(mes) + ' — ' + NOMBRE_PAGO[nro]);

        var contexto = document.getElementById('pgenFechaContexto');

        if (contexto) {
            contexto.innerHTML =
                'El ' + esc(NOMBRE_PAGO[nro]) + ' de ' + esc(rotuloMes(mes)) + ' es el ' +
                '<strong>' + esc(fecha(pago.teorica)) + '</strong>. ' +
                (pago.teorica !== pago.calculada
                    ? 'No es hábil, así que el cálculo lo corre al <strong>' +
                      esc(fecha(pago.calculada)) + '</strong>.'
                    : 'Es hábil, así que el cálculo lo deja ahí.');
        }

        valor('pgenFechaInput', pago.override ? pago.fecha : pago.calculada);
        valor('pgenFechaMotivo', pago.motivo || '');

        var volver = document.getElementById('pgenFechaVolver');

        if (volver) { volver.disabled = !pago.override; }

        cargarHistorial(mes, nro);
        modal().show();
    }

    function cargarHistorial(mes, nro) {
        var cuerpo = document.getElementById('pgenHistorialFechaBody');

        if (!cuerpo) { return; }

        cuerpo.innerHTML = '<tr><td colspan="7" class="text-muted">Leyendo…</td></tr>';

        pedir(ENDPOINT + '?action=getHistorialCronograma&mes=' + encodeURIComponent(mes) +
              '&nro=' + nro)
            .then(function(d) {
                var filas = (d.data || []);

                cuerpo.innerHTML = filas.length
                    ? filas.map(function(f) {
                        return '<tr class="' + (f.vigente ? '' : 'text-muted') + '">' +
                            '<td>' + (f.vigente
                                ? '<span class="badge bg-success">vigente</span>'
                                : '<span class="badge bg-secondary">de baja</span>') + '</td>' +
                            '<td>' + esc(fecha(f.fecha)) + '</td>' +
                            '<td>' + esc(fecha(f.fecha_calculada)) + '</td>' +
                            '<td><small>' + esc(f.motivo || '—') + '</small></td>' +
                            '<td><small>' + esc(f.usuario || '—') + '</small></td>' +
                            '<td><small>' + esc((f.fecha_alta || '').substring(0, 16)) + '</small></td>' +
                            '<td><small>' + esc((f.fecha_baja || '—').substring(0, 16)) + '</small></td>' +
                        '</tr>';
                    }).join('')
                    : '<tr><td colspan="7" class="text-muted">' +
                      'Esta fecha nunca se movió a mano.</td></tr>';
            })
            .catch(function(e) {
                cuerpo.innerHTML = '<tr><td colspan="7" class="text-danger">' +
                    esc(e.message) + '</td></tr>';
            });
    }

    function guardarFecha() {
        if (!editando) { return; }

        var input = document.getElementById('pgenFechaInput');
        var motivo = document.getElementById('pgenFechaMotivo');

        if (!input || !input.value) {
            Notificacion.campoInvalido(input, 'Elegí una fecha.');

            return;
        }

        /* EL MOTIVO ES OBLIGATORIO. Meses después es lo único que explica por
           qué ese pago no cayó donde la cuenta decía. Mismo criterio que la
           exclusión de cheques y el ajuste de compras proyectadas. */
        if (!motivo || !motivo.value.trim()) {
            Notificacion.campoInvalido(motivo, 'El motivo es obligatorio.');

            return;
        }

        pedir(ENDPOINT + '?action=saveFechaCronograma', {
            mes: editando.mes,
            nro: editando.nro,
            fecha: input.value,
            fecha_calculada: editando.calculada,
            motivo: motivo.value.trim()
        })
            .then(function(d) {
                modal().hide();
                Notificacion.exito(d.message || 'Fecha guardada.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar la fecha: ' + e.message);
            });
    }

    function volverACalculado() {
        if (!editando) { return; }

        pedir(ENDPOINT + '?action=quitarFechaCronograma',
              { mes: editando.mes, nro: editando.nro })
            .then(function(d) {
                modal().hide();
                Notificacion.exito(d.message || 'La fecha volvió al valor calculado.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo volver a la fecha calculada: ' + e.message);
            });
    }

    function modal() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('pgenModalFecha'));
    }

    /* ================================================================
       COTIZACIONES — SOLO LECTURA
       ================================================================ */

    function pintarCotizaciones(cot) {
        if (!cot) { return; }

        var df = cot.dolar_futuro || {};
        var cuerpo = document.getElementById('pgenDfBody');
        var pie = document.getElementById('pgenDfPie');

        if (pie) {
            /* CUÁNDO SE ACTUALIZÓ VA SIEMPRE, y no sólo cuando falla: una curva
               de hace tres semanas tiene exactamente el mismo aspecto que la de
               hoy. */
            pie.innerHTML = df.disponible
                ? 'Actualizada el <strong>' +
                  esc((df.actualizada || '').substring(0, 16)) + '</strong>. ' +
                  'Origen: <code>' + esc(df.origen || '') + '</code>. Llega por API.'
                : '<span class="text-danger">' +
                  '<i class="fas fa-triangle-exclamation me-1"></i>' +
                  'La curva no está disponible' +
                  (df.error ? ': ' + esc(df.error) : '') +
                  '. Los pagos en dólares de Comercio Exterior se muestran sin valuar.' +
                  '</span>';
        }

        if (cuerpo) {
            cuerpo.innerHTML = (df.curva || []).length
                ? df.curva.map(function(m) {
                    return '<tr>' +
                        '<td>' + esc(rotuloMes(m.clave)) + '</td>' +
                        '<td><small class="text-muted">' + esc(m.simbolo || '—') + '</small></td>' +
                        '<td class="text-end">' + moneda(m.cotizacion) + '</td>' +
                    '</tr>';
                }).join('')
                : '<tr><td colspan="3" class="text-center text-muted py-3">' +
                  'Sin datos de la curva.</td></tr>';
        }

        var bcra = cot.bcra || {};
        var caja = document.getElementById('pgenBcra');

        if (!caja) { return; }

        caja.innerHTML = (bcra.valor === null || bcra.valor === undefined)
            ? '<div class="alert alert-warning py-2 px-3 small mb-0">' +
              '<i class="fas fa-triangle-exclamation me-1"></i>' +
              esc(bcra.error || 'No hay cotización disponible.') + '</div>'
            : '<div class="param-card">' +
                  '<div class="param-clave">Mes en curso — ' + esc(rotuloMes(bcra.mes)) + '</div>' +
                  '<div class="h4 mb-1">' + moneda(bcra.valor) + '</div>' +
                  /* LA FECHA Y LA PUNTA SON PARTE DEL DATO. El cierre del mes en
                     curso no existe todavía, así que lo que se muestra es la
                     última cotización conocida: sin su día, el número no se
                     puede auditar contra nada. Y un importe valuado sin decir
                     con qué punta se compara contra el comprador y parece estar
                     mal. */
                  '<div class="param-descripcion">Del <strong>' + esc(fecha(bcra.fecha)) +
                      '</strong>, punta <strong>' + esc(bcra.punta_nombre || '') + '</strong></div>' +
                  '<div class="param-hint">' +
                      'Es la última cotización conocida a hoy, no el cierre del mes: el cierre ' +
                      'de este mes todavía no existe. La punta compradora es la que usa todo ' +
                      'el cashflow; la única pantalla que valúa a vendedor es Dólares Cuenta ' +
                      'Comitente, y por eso ésa no cierra contra las demás.' +
                  '</div>' +
              '</div>';
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function avisar(lista) {
        var cont = document.getElementById('pgenAvisos');

        if (!cont) { return; }

        cont.innerHTML = (!lista || !lista.length) ? '' :
            '<div class="alert alert-warning py-2 px-3 mb-3 small">' +
                '<i class="fas fa-triangle-exclamation me-1"></i>' +
                lista.map(esc).join('<br>') +
            '</div>';
    }

    /** '2026-09' -> 'Sep-26'. Es el mismo rótulo que usa el eje del tablero */
    function rotuloMes(mes) {
        var abrev = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                     'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var p = String(mes || '').split('-');

        if (p.length < 2) { return String(mes || ''); }

        return abrev[Number(p[1]) - 1] + '-' + p[0].substring(2);
    }

    /** '2026-12-24' -> '24/12/2026' */
    function fecha(f) {
        if (!f) { return '—'; }

        var p = String(f).substring(0, 10).split('-');

        return (p.length < 3) ? String(f) : p[2] + '/' + p[1] + '/' + p[0];
    }

    function moneda(v) {
        if (v === null || v === undefined || isNaN(Number(v))) { return '—'; }

        return '$ ' + Number(v).toLocaleString('es-AR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    /** horizonte_dias -> Horizonte Dias. Sólo para una clave que este JS no conozca */
    function etiqueta(clave) {
        return String(clave).replace(/_/g, ' ')
            .replace(/(^|\s)\S/g, function(c) { return c.toUpperCase(); });
    }

    function texto(id, t) {
        var el = document.getElementById(id);

        if (el) { el.textContent = t; }
    }

    function valor(id, v) {
        var el = document.getElementById(id);

        if (el) { el.value = (v === null || v === undefined) ? '' : v; }
    }

    function destacar(id) {
        var el = document.getElementById(id);

        if (!el) { return; }

        el.classList.add('param-guardado');
        setTimeout(function() { el.classList.remove('param-guardado'); }, 1200);
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
        enganchar('pgenBtnRefresh', 'click', cargar);
        enganchar('pgenModalidad', 'change', guardarModalidad);
        enganchar('pgenAplicarConstante', 'click', aplicarConstante);
        enganchar('pgenFechaGuardar', 'click', guardarFecha);
        enganchar('pgenFechaVolver', 'click', volverACalculado);

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
