/**
 * Logistica Local
 *
 * Una fila por fletero con sus pagos proyectados, sobre el mismo eje temporal
 * que el tablero. Todo es proyeccion: no hay parte real.
 *
 * LAS COLUMNAS Y LAS TRES VISTAS NO SE CALCULAN ACA
 * --------------------------------------------------
 * El backend arma el payload con EjeVista::armarAgrupado() y Js/eje-vistas.js
 * dibuja los botones, resuelve que columnas se ven y que total corresponde a
 * cada vista. Este archivo solo dibuja filas. Sin eso, la pestana tendria su
 * propia version de "que columnas van en cada vista", que es exactamente lo que
 * el modulo dejo de tener escrito cuatro veces.
 *
 * POR QUE HAY DOS LISTAS Y NO UNA
 * --------------------------------
 * El payload del eje trae SOLO lo que se proyecta: un fletero sin horas no
 * tiene ninguna fila ahi. La planilla completa viaja aparte y es la que dice
 * quienes son todos y que le falta a cada uno. La tabla se dibuja sobre la
 * planilla -asi el que no proyecta se VE, con su motivo- y los importes salen
 * del eje. Dibujar solo el eje esconderia justo a los que hay que arreglar.
 *
 * EL VALOR HORA NO SE EDITA
 * --------------------------
 * Lo editable es el valor BASE, las horas y el mes base. El valor hora de cada
 * mes sale siempre de la formula, y su tooltip dice de que ajuste viene y que
 * meses de inflacion se sumaron. Ese texto lo arma el backend, porque es la
 * explicacion de una cuenta que hace el backend.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/LogisticaController.php';

    /* POR QUÉ NO HAY UNA LISTA DE MOTIVOS ACÁ. Cada celda trae su propio
       'tooltip' ya redactado por el backend, porque es la explicación de una
       cuenta que hace el backend: de qué ajuste sale el valor, qué meses de
       inflación se sumaron, qué mes falta cargar. Una tabla de textos en el
       front tendría que repetir esa lógica para decir lo mismo, y se
       desactualizaría en el primer cambio de la fórmula. */

    var datos = null;
    var vistas = null;
    var filtro = '';

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        Cargando.mostrar('logSpinner');
        mostrar('logTableWrapper', false);
        mostrar('summaryLogistica', false);

        // Lo que el tablero dice de esta pestaña, en paralelo con los datos:
        // la grilla no lo espera. Ver Js/avisos-panel.js.
        AvisosPanel.pedirTablero('logistica_local');

        pedir(ENDPOINT + '?action=getPlanilla')
            .then(function(d) {
                datos = d.data;

                avisar(datos.avisos || [], true);
                vistas.usar(datos.eje);
                pintarCrono();
                pintarResumen();
                dibujar();

                Cargando.ocultar('logSpinner');
                mostrar('logTableWrapper', true);
                mostrar('summaryLogistica', true);
            })
            .catch(function(e) {
                Cargando.ocultar('logSpinner');
                avisar([{ nivel: 'danger', texto: 'No se pudo calcular la proyección de logística: ' + e.message }]);
            });
    }

    /* ================================================================
       LA PLANILLA
       ================================================================ */

    function dibujar() {
        if (!datos) { return; }

        var columnas = vistas.columnas();
        var filas = filtrar(datos.planilla.fleteros);

        pintarEncabezado(columnas);
        pintarFilas(filas, columnas);
        pintarPie(filas, columnas);
        pintarValorHora(filas);
    }

    function filtrar(fleteros) {
        if (filtro === '') { return fleteros; }

        var t = filtro.toLowerCase();

        return fleteros.filter(function(f) {
            return String(f.nombre || '').toLowerCase().indexOf(t) !== -1
                || String(f.cod_provee || '').toLowerCase().indexOf(t) !== -1;
        });
    }

    function pintarEncabezado(columnas) {
        var thead = document.getElementById('logThead');

        if (!thead) { return; }

        /* EL ENCABEZADO VA EN DOS FILAS, y no es decoración: Js/columnas-fijas.js
           deriva qué columnas son "descriptivas" —o sea, elegibles para quedar
           fijas al scrollear— de las celdas con rowspan que están ANTES del
           grupo temporal. Con un thead de una sola fila, el desplegable
           ofrecería también las doce columnas de meses.

           ARRIBA VAN SÓLO LAS DESCRIPTIVAS. El resto de la fila son los
           totales de cada columna del eje, arriba de su mes, y los pinta
           Js/eje-totales.js desde pintarPie(). Por eso Total está en la fila
           de los meses y no arriba con rowspan: encima de ella va el total
           general. Lleva total-column, que es como tabla-orden.js reconoce la
           columna Total aunque esta tabla no se ordene. */
        thead.innerHTML = '<tr>' +
            '<th rowspan="2" class="col-texto">Fletero</th>' +
            '<th rowspan="2" class="text-end" style="width: 110px;">Horas/mes</th>' +
            '<th rowspan="2" class="text-end" style="width: 140px;">Valor hora base</th>' +
            '<th rowspan="2" class="text-center" style="width: 120px;">Mes base</th>' +
        '</tr><tr>' +
            columnas.map(function(c) {
                // Sábado, domingo o feriado: lo decide el backend (vistas.marca())
                var marca = vistas.marca(c);

                return '<th class="text-end' + (marca.clase ? ' ' + marca.clase : '') + '"'
                    + (marca.titulo ? ' title="' + esc(marca.titulo) + '"' : '') + '>'
                    + esc(vistas.rotulo(c)) + '</th>';
            }).join('') +
            '<th class="text-end fw-bold total-column">Total</th>' +
        '</tr>';
    }

    function pintarFilas(filas, columnas) {
        var cuerpo = document.getElementById('logTableBody');

        if (!cuerpo) { return; }

        if (!filas.length) {
            cuerpo.innerHTML = '<tr><td colspan="' + (columnas.length + 5) +
                '" class="text-center text-muted py-4">' +
                (datos.planilla.fleteros.length
                    ? 'Ningún fletero coincide con la búsqueda.'
                    : 'No hay ningún fletero activo cargado. Se dan de alta en ' +
                      'Parámetros › Logística.') +
                '</td></tr>';

            return;
        }

        cuerpo.innerHTML = filas.map(function(f) {
            var eje = filaDelEje(f.cod_provee);
            var habitual = f.habitual !== false;

            return '<tr data-cod="' + esc(f.cod_provee) + '">' +
                '<td>' +
                    '<a href="#" class="fw-semibold text-decoration-none" data-detalle="' +
                        esc(f.cod_provee) + '">' + esc(f.nombre || f.cod_provee) + '</a>' +
                    '<div><small class="text-muted">' + esc(f.cod_provee) + '</small>' +
                        Auditoria.icono({ usuario: f.usuario, fecha: f.fecha_modif }) +
                        marcaHabitual(f) + '</div>' +
                '</td>' +
                (habitual
                    ? celdaNum(f.cod_provee, 'horas', f.horas_mes, '0.5', 'Horas por mes') +
                      celdaNum(f.cod_provee, 'valor_hora', f.valor_hora_base, '0.01',
                               'Valor hora base, ya ajustado') +
                      celdaMes(f.cod_provee, f.mes_base)
                    : celdaConservada(f.horas_mes, false) +
                      celdaConservada(f.valor_hora_base, true) +
                      celdaConservada(f.mes_base, false)) +
                columnas.map(function(c) {
                    var prox = proximoEn(f, c);

                    return '<td class="text-end ' + vistas.marca(c).clase +
                        (prox && prox.editado ? ' log-editado' : '') + '"' +
                        (prox ? ' title="' + esc(tooltipProximo(f, prox)) + '"' : '') + '>'
                        + (prox ? celdaProximo(f, eje, c) : celdaEje(f, eje, c)) + '</td>';
                }).join('') +
                '<td class="text-end fw-bold">' +
                    (eje ? importe(vistas.total(eje)) : '—') +
                '</td>' +
            '</tr>' +
            (habitual ? '' : filaPuntuales(f, columnas.length + 5));
        }).join('');

        cuerpo.querySelectorAll('.log-edit').forEach(function(input) {
            input.addEventListener('change', function() { guardarCampo(input); });
        });

        cuerpo.querySelectorAll('.log-habitual').forEach(function(input) {
            input.addEventListener('change', function() { cambiarHabitual(input); });
        });

        cuerpo.querySelectorAll('[data-proximo]').forEach(function(el) {
            el.addEventListener('click', function() { editarProximo(el.getAttribute('data-proximo')); });
        });

        cuerpo.querySelectorAll('[data-puntual-accion]').forEach(function(btn) {
            btn.addEventListener('click', function() { accionPuntual(btn); });
        });

        cuerpo.querySelectorAll('[data-detalle]').forEach(function(a) {
            a.addEventListener('click', function(ev) {
                ev.preventDefault();
                abrirDetalle(a.getAttribute('data-detalle'));
            });
        });
    }

    /**
     * Una celda del eje.
     *
     * UNA CELDA VACIA Y UN CERO NO SE DIBUJAN IGUAL. Si el mes no se pudo
     * calcular va un guion con el motivo en el tooltip; si se calculo y da cero,
     * va el cero. Es la misma distincion que el backend sostiene con null, y
     * perderla acá la anularía.
     */
    function celdaEje(fletero, eje, columna) {
        var mes = columna.indexOf('MES|') === 0
            ? columna.substring(4) : columna.substring(4, 11);
        var celda = fletero.meses[mes];

        if (celda && celda.motivo) {
            return '<span class="text-muted" title="' + esc(celda.tooltip) + '">—</span>';
        }

        if (!eje) { return '<span class="text-muted">—</span>'; }

        var v = vistas.valor(eje, columna);

        return (v === 0 || v === null || v === undefined)
            ? '<span class="text-muted">—</span>' : importe(v);
    }

    /* ================================================================
       HABITUAL O NO HABITUAL
       ================================================================ */

    /**
     * El interruptor "Habitual" con permiso y con el script corrido; sin eso,
     * sólo la marca de un no habitual.
     */
    function marcaHabitual(f) {
        var habitual = f.habitual !== false;

        if (datos.no_habituales && puedeEditar()) {
            return '<div class="form-check form-switch mb-0 mt-1" ' +
                'title="Habitual: se proyecta con horas × valor hora. No habitual: sólo sus pagos puntuales.">' +
                '<input class="form-check-input log-habitual" type="checkbox" role="switch" ' +
                    'id="logHab' + esc(f.cod_provee) + '" data-cod="' + esc(f.cod_provee) + '"' +
                    (habitual ? ' checked' : '') + '>' +
                '<label class="form-check-label small" for="logHab' + esc(f.cod_provee) + '">' +
                    'Habitual</label></div>';
        }

        return habitual ? '' : ' <span class="badge bg-secondary">no habitual</span>';
    }

    /**
     * Las horas, el valor hora y el mes base de un no habitual: se conservan
     * y se ven, pero no se piden ni se editan.
     */
    function celdaConservada(valor, plata) {
        var texto = (valor === null || valor === undefined || valor === '')
            ? '—'
            : (plata ? importeTexto(valor) : esc(valor));

        return '<td class="text-end text-muted" title="No habitual: no se calcula. Se conserva ' +
            'para cuando vuelva a ser habitual.">' + texto + '</td>';
    }

    function fleteroPorCodigo(cod) {
        var f = null;

        datos.planilla.fleteros.forEach(function(x) {
            if (x.cod_provee === cod) { f = x; }
        });

        return f;
    }

    /**
     * Pasar un fletero a habitual o a no habitual. LA CONFIRMACIÓN DICE QUÉ
     * CAMBIA EN LA PROYECCIÓN: al volver a habitual, cuántos pagos puntuales
     * futuros dejan de proyectarse y por cuánto.
     */
    function cambiarHabitual(input) {
        var f = fleteroPorCodigo(input.getAttribute('data-cod'));

        if (!f) { return; }

        var aHabitual = input.checked;
        var nombre = f.nombre || f.cod_provee;
        var detalle;

        if (aHabitual) {
            var futuros = (f.puntuales || []).filter(function(p) { return p.fecha > datos.hoy; });
            var total = 0;

            futuros.forEach(function(p) { total += Number(p.importe) || 0; });

            detalle = 'Se vuelve a proyectar con sus horas y su valor hora. ' + (futuros.length
                ? futuros.length + ' pago(s) puntual(es) futuro(s) por ' + importeTexto(total) +
                  ' dejan de proyectarse: quedan guardados y se ven en su fila.'
                : 'No tiene pagos puntuales futuros, así que no se pierde ninguno.');
        } else {
            detalle = 'Deja de proyectarse por cálculo. Sus horas, su valor hora y su mes base se ' +
                'conservan para cuando vuelva a ser habitual. Desde ahora se le cargan pagos ' +
                'puntuales, con importe y fecha.';
        }

        Notificacion.confirmar({
            titulo: aHabitual ? 'Volver a habitual' : 'Pasar a no habitual',
            mensaje: '¿' + (aHabitual ? 'Volver a habitual' : 'Pasar a no habitual') + ' a ' +
                nombre + '?',
            detalle: detalle,
            confirmar: aHabitual ? 'Volver a habitual' : 'Pasar a no habitual',
            peligro: true
        }).then(function(si) {
            if (!si) {
                input.checked = !aHabitual;

                return null;
            }

            /* SE MANDAN LOS TRES CAMPOS, como en guardarCampo(): el guardado
               escribe la fila entera. */
            return pedir(ENDPOINT + '?action=saveFletero', {
                cod_provee: f.cod_provee,
                horas: f.horas_mes,
                valor_hora: f.valor_hora_base,
                mes_base: f.mes_base,
                habitual: aHabitual
            }).then(function(d) {
                Notificacion.exito(d.message || 'Guardado.');
                cargar();
            });
        }).catch(function(e) {
            input.checked = !aHabitual;
            Notificacion.error('No se pudo cambiar: ' + e.message);
        });
    }

    /* ================================================================
       LOS PAGOS PUNTUALES DE UN NO HABITUAL
       ================================================================ */

    /** 'aaaa-mm-dd' del día siguiente a hoy: lo mínimo que acepta un pago puntual */
    function manana() {
        var p = String(datos.hoy).split('-');
        var d = new Date(Date.UTC(Number(p[0]), Number(p[1]) - 1, Number(p[2]) + 1));

        return d.toISOString().substring(0, 10);
    }

    /**
     * La fila de pagos puntuales, debajo del fletero. Los futuros se corrigen
     * y se dan de baja; los que ya pasaron quedan como historial, sin botones.
     */
    function filaPuntuales(f, colspan) {
        var edita = puedeEditar() && datos.no_habituales;
        var lista = f.puntuales || [];
        var html = '<strong>Pagos puntuales:</strong> ';

        html += lista.length ? lista.map(function(p) {
            var pasado = p.fecha <= datos.hoy;
            var quien = Auditoria.icono({ usuario: p.usuario, fecha: p.fecha_modif,
                                          alta: { usuario: p.usuario_alta, fecha: p.fecha_alta } });

            if (pasado || !edita) {
                return '<span class="log-puntual' + (pasado ? ' text-muted' : '') + '"' +
                    (pasado ? ' title="' + esc(p.motivo_excluido) + '"' : '') + '>' +
                    esc(fecha(p.fecha)) + ' · ' + importeTexto(p.importe) +
                    (pasado ? ' <span class="badge bg-light text-muted">historial</span>' : '') +
                    quien + '</span>';
            }

            return '<span class="log-puntual" data-id="' + p.id + '">' +
                '<input type="date" class="form-control form-control-sm" min="' + manana() + '" ' +
                    'value="' + esc(p.fecha) + '">' +
                '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm text-end" ' +
                    'value="' + esc(p.importe) + '">' +
                '<button class="btn btn-sm btn-outline-primary" data-puntual-accion="guardar" ' +
                    'data-cod="' + esc(f.cod_provee) + '" title="Guardar la corrección">' +
                    '<i class="fas fa-check"></i></button>' +
                '<button class="btn btn-sm btn-outline-danger" data-puntual-accion="baja" ' +
                    'data-cod="' + esc(f.cod_provee) + '" title="Dar de baja">' +
                    '<i class="fas fa-trash"></i></button>' + quien +
            '</span>';
        }).join('') : '<span class="text-muted me-3">Sin pagos puntuales cargados: no proyecta nada.</span>';

        if (edita) {
            html += '<span class="log-puntual" data-id="">' +
                '<input type="date" class="form-control form-control-sm" min="' + manana() + '" ' +
                    'title="De mañana en adelante: lo de hoy ya está en el saldo bancario">' +
                '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm text-end" ' +
                    'placeholder="Importe">' +
                '<button class="btn btn-sm btn-primary" data-puntual-accion="guardar" ' +
                    'data-cod="' + esc(f.cod_provee) + '"><i class="fas fa-plus me-1"></i>Agregar</button>' +
            '</span>';
        }

        return '<tr class="log-puntuales"><td colspan="' + colspan + '">' + html + '</td></tr>';
    }

    function accionPuntual(btn) {
        var caja = btn.closest('.log-puntual');
        var cod = btn.getAttribute('data-cod');
        var id = caja.getAttribute('data-id');
        var inputs = caja.querySelectorAll('input');

        if (btn.getAttribute('data-puntual-accion') === 'baja') {
            Notificacion.confirmar({
                titulo: 'Dar de baja el pago puntual',
                mensaje: '¿Dar de baja el pago del ' + fecha(inputs[0].value) + ' por ' +
                    importeTexto(inputs[1].value) + '?',
                detalle: 'Deja de proyectarse. No se borra: queda en el historial.',
                confirmar: 'Dar de baja',
                peligro: true
            }).then(function(si) {
                if (!si) { return null; }

                return pedir(ENDPOINT + '?action=bajaPagoPuntual', { id: Number(id), cod_provee: cod })
                    .then(function(d) { Notificacion.exito(d.message); cargar(); });
            }).catch(function(e) {
                Notificacion.error('No se pudo dar de baja: ' + e.message);
            });

            return;
        }

        /* La fecha mínima se avisa acá para no ir y volver, pero la que vale
           es la del servidor. */
        if (!inputs[0].value || inputs[0].value <= datos.hoy) {
            Notificacion.campoInvalido(inputs[0], 'La fecha tiene que ser de mañana en adelante: ' +
                'lo que se paga hoy ya está reflejado en el saldo bancario.');

            return;
        }

        if (!(Number(inputs[1].value) > 0)) {
            Notificacion.campoInvalido(inputs[1], 'El importe tiene que ser mayor a 0.');

            return;
        }

        pedir(ENDPOINT + '?action=savePagoPuntual', {
            id: id ? Number(id) : null,
            cod_provee: cod,
            fecha: inputs[0].value,
            importe: inputs[1].value
        }).then(function(d) {
            Notificacion.exito(d.message);
            cargar();
        }).catch(function(e) {
            Notificacion.error('No se pudo guardar el pago: ' + e.message);
        });
    }

    /* ================================================================
       EL IMPORTE DEL PRÓXIMO PAGO DE UN HABITUAL
       ================================================================ */

    /** El próximo pago del fletero, si cae en esa columna */
    function proximoEn(f, columna) {
        if (f.habitual === false || !f.proximo) { return null; }

        var pago = null;

        (f.pagos || []).forEach(function(p) { if (p.proximo) { pago = p; } });

        if (!pago) { return null; }

        var col = pago.en_tramo ? 'DIA|' + pago.fecha : 'MES|' + String(pago.fecha).substring(0, 7);

        return col === columna ? pago : null;
    }

    /** La celda del próximo pago: el importe y, con permiso, el lápiz */
    function celdaProximo(f, eje, columna) {
        var v = eje ? vistas.valor(eje, columna) : null;
        var texto = (v === null || v === undefined || v === 0)
            ? '<span class="text-muted">—</span>' : importe(v);

        if (!puedeEditar() || !datos.no_habituales) { return texto; }

        return '<span class="log-proximo" data-proximo="' + esc(f.cod_provee) + '">' + texto +
            ' <i class="fas fa-pen"></i></span>';
    }

    /**
     * El tooltip del próximo pago: de dónde sale la cuenta y, si se editó, el
     * calculado, el editado, quién y cuándo.
     */
    function tooltipProximo(f, p) {
        var celda = f.meses[p.mes] || {};
        var partes = (celda.pagos || []).length || 1;
        var calc = (p.importe_calculado === null || p.importe_calculado === undefined)
            ? 'sin cálculo (' + (celda.tooltip || 'faltan datos') + ')'
            : importeTexto(p.importe_calculado) + ' = ' +
              Number(f.horas_mes).toLocaleString('es-AR') + ' h × ' +
              importeTexto(celda.valor_hora) + (partes > 1 ? ' / ' + partes + ' pagos' : '');

        if (!p.editado) {
            return 'Próximo pago, ' + fecha(p.fecha) + '. Calculado: ' + calc + '.' +
                (puedeEditar() ? ' Hacé clic para cargar el importe real.' : '');
        }

        var e = p.edicion || {};

        return 'Próximo pago, ' + fecha(p.fecha) + '. Calculado: ' + calc + '. Editado: ' +
            importeTexto(p.importe) + ', por ' + (e.usuario || 'sin usuario registrado') +
            (e.fecha ? ' el ' + fecha(e.fecha) + ' ' + String(e.fecha).substring(11, 16) : '') +
            '. Vale sólo para este pago.' +
            (puedeEditar() ? ' Hacé clic para cambiarlo o volver al cálculo.' : '');
    }

    /** '61.234,56' o '61234.56' -> '61234.56'. La validación que vale es la del servidor */
    function normalizarImporte(t) {
        var s = String(t || '').replace(/\s|\$/g, '');

        if (s.indexOf(',') !== -1) {
            s = s.replace(/\./g, '').replace(',', '.');
        }

        return s;
    }

    function editarProximo(cod) {
        var f = fleteroPorCodigo(cod);
        var p = null;

        if (!f) { return; }

        (f.pagos || []).forEach(function(x) { if (x.proximo) { p = x; } });

        if (!p) { return; }

        Notificacion.pedirTexto({
            titulo: 'Importe del próximo pago',
            mensaje: (f.nombre || cod) + ', pago del ' + fecha(p.fecha) + '.',
            detalle: 'Cargá el importe real de este pago. Vale sólo para este pago: los ' +
                'siguientes se siguen calculando. ' + (p.editado
                    ? 'Dejalo vacío para volver al importe calculado.'
                    : 'El cálculo da ' + (p.importe_calculado === null ? '—'
                        : importeTexto(p.importe_calculado)) + '.'),
            etiqueta: 'Importe',
            placeholder: 'Por ejemplo: 61234,56',
            valor: p.editado ? String(p.importe).replace('.', ',') : '',
            opcional: !!p.editado,
            confirmar: 'Guardar'
        }).then(function(t) {
            if (t === null) { return null; }

            if (String(t).trim() === '') {
                return pedir(ENDPOINT + '?action=deshacerImporteProximo',
                    { cod_provee: cod, mes: p.mes, nro: p.nro });
            }

            return pedir(ENDPOINT + '?action=saveImporteProximo',
                { cod_provee: cod, mes: p.mes, nro: p.nro, importe: normalizarImporte(t) });
        }).then(function(d) {
            if (!d) { return; }

            Notificacion.exito(d.message);
            cargar();
        }).catch(function(e) {
            Notificacion.error('No se pudo guardar el importe: ' + e.message);
        });
    }

    /* Sin permiso de edicion, las horas, el valor hora y el mes base son
       texto: se ven igual, sin el input. Ver Js/permisos.js. */
    function puedeEditar() {
        return Permisos.puedeEditar(document.querySelector('.tab-logistica_local'));
    }

    function celdaNum(cod, campo, valor, paso, titulo) {
        if (!puedeEditar()) {
            return '<td class="text-end" title="' + esc(titulo) + '">' +
                (valor === null ? '<span class="text-muted">—</span>'
                    : Number(valor).toLocaleString('es-AR', { maximumFractionDigits: 2 })) +
                '</td>';
        }

        return '<td class="text-end">' +
            '<input type="number" step="' + paso + '" min="0" ' +
                'class="form-control form-control-sm text-end log-edit" ' +
                'data-cod="' + esc(cod) + '" data-campo="' + campo + '" ' +
                'title="' + esc(titulo) + '" ' +
                'value="' + (valor === null ? '' : esc(valor)) + '">' +
        '</td>';
    }

    function celdaMes(cod, mesBase) {
        if (!puedeEditar()) {
            var p = (mesBase || '').split('-');

            return '<td class="text-center">' + (p.length === 2 ? (p[1] + '/' + p[0])
                : '<span class="text-muted">—</span>') + '</td>';
        }

        return '<td class="text-center">' +
            '<input type="month" class="form-control form-control-sm log-edit" ' +
                'data-cod="' + esc(cod) + '" data-campo="mes_base" ' +
                'title="Desde qué mes rige el valor hora base. Fija el calendario de los ' +
                       'ajustes: mes base + 3, + 6, + 9…" ' +
                'value="' + (mesBase === null ? '' : esc(mesBase)) + '">' +
        '</td>';
    }

    function pintarPie(filas, columnas) {
        var pie = document.getElementById('logTfoot');

        if (!pie) { return; }

        /* EL PIE SUMA LO QUE SE VE, no el total general: con la búsqueda activa
           un total que incluyera a los filtrados no cerraría con las filas de
           arriba. */
        var ejes = filas.map(function(f) { return filaDelEje(f.cod_provee); })
                        .filter(function(e) { return !!e; });

        /* Los totales se cuentan UNA VEZ y van al pie y arriba de cada mes
           (Js/eje-totales.js), con los mismos formatos. */
        var valores = columnas.map(function(c) {
            var suma = 0;

            ejes.forEach(function(e) { suma += Number(vistas.valor(e, c)) || 0; });

            return suma;
        });

        var total = 0;

        ejes.forEach(function(e) { total += Number(vistas.total(e)) || 0; });

        pie.innerHTML = '<tr>' +
            '<td colspan="4" class="fw-bold text-end">TOTALES</td>' +
            valores.map(function(suma, i) {
                return '<td class="text-end fw-bold ' + vistas.marca(columnas[i]).clase + '">'
                    + celdaEje(suma) + '</td>';
            }).join('') +
            '<td class="text-end fw-bold">' + celdaTotal(total) + '</td>' +
        '</tr>';

        pintarEjeTotales('tablaLogistica', {
            columnas: columnas,
            valores: valores,
            total: total,
            formato: celdaEje,
            formatoTotal: celdaTotal
        });
    }

    /** Una celda de totales del eje: un guión gris en cero */
    function celdaEje(suma) {
        return suma === 0 ? '<span class="text-muted">—</span>' : importe(suma);
    }

    /** La del total general: guión sin gris, como el pie lo mostró siempre */
    function celdaTotal(total) {
        return total === 0 ? '—' : importe(total);
    }

    function filaDelEje(cod) {
        var filas = (datos.eje && datos.eje.filas) || [];

        for (var i = 0; i < filas.length; i++) {
            if (filas[i].cod_provee === cod) { return filas[i]; }
        }

        return null;
    }

    /* ================================================================
       EL VALOR HORA MES A MES
       ================================================================ */

    function pintarValorHora(filas) {
        var thead = document.getElementById('logVhThead');
        var cuerpo = document.getElementById('logVhBody');

        if (!thead || !cuerpo) { return; }

        var meses = datos.planilla.meses || [];

        thead.innerHTML = '<tr><th>Fletero</th>' +
            meses.map(function(m) {
                return '<th class="text-end">' + esc(rotuloMes(m)) + '</th>';
            }).join('') + '</tr>';

        cuerpo.innerHTML = filas.length
            ? filas.map(function(f) {
                return '<tr>' +
                    '<td class="fw-semibold">' + esc(f.nombre || f.cod_provee) + '</td>' +
                    meses.map(function(m) {
                        var c = f.meses[m];

                        if (!c || c.valor_hora === null) {
                            return '<td class="text-end text-muted" title="' +
                                esc(c ? c.tooltip : '') + '">—</td>';
                        }

                        /* EL MES DE AJUSTE SE MARCA. Sin la marca, una columna
                           con otro número se lee como un error de carga; con
                           ella se lee como lo que es. */
                        return '<td class="text-end' + (c.ajusta ? ' log-ajuste' : '') + '" ' +
                            'title="' + esc(c.tooltip) + '">' +
                            importe(c.valor_hora) +
                            (c.ajusta ? ' <i class="fas fa-arrow-up small"></i>' : '') +
                        '</td>';
                    }).join('') +
                '</tr>';
            }).join('')
            : '<tr><td colspan="' + (meses.length + 1) +
              '" class="text-center text-muted py-3">Sin fleteros.</td></tr>';
    }

    /* ================================================================
       EL DETALLE DE UN FLETERO
       ================================================================ */

    function abrirDetalle(cod) {
        var f = null;

        datos.planilla.fleteros.forEach(function(x) {
            if (x.cod_provee === cod) { f = x; }
        });

        if (!f) { return; }

        texto('logDetalleTitulo', (f.nombre || cod) + ' (' + cod + ')');

        var cuerpo = document.getElementById('logDetalleBody');

        if (cuerpo) {
            cuerpo.innerHTML = f.pagos.map(function(p) {
                var celda = f.meses[p.mes];

                var estado = p.excluido
                    ? '<span class="badge bg-secondary" title="' + esc(p.motivo_excluido) + '">' +
                      'no se proyecta</span>'
                    : (p.en_tramo
                        ? '<span class="badge bg-primary">columna del día</span>'
                        : '<span class="badge bg-info text-dark">columna del mes</span>');

                if (p.puntual) {
                    estado += ' <span class="badge bg-info text-dark">pago puntual</span>';
                }

                if (p.editado) {
                    estado += ' <span class="badge bg-warning text-dark" title="Calculado: ' +
                        esc(p.importe_calculado === null ? '—' : importeTexto(p.importe_calculado)) +
                        '">importe a mano</span>';
                }

                if (p.override) {
                    estado += ' <span class="badge bg-warning text-dark">fecha a mano</span>';
                } else if (p.corrida) {
                    estado += ' <span class="badge bg-light text-dark" ' +
                        'title="El día de pago no era hábil: se corrió al día hábil anterior">' +
                        'corrida</span>';
                }

                return '<tr class="' + (p.excluido ? 'text-muted' : '') + '">' +
                    '<td>' + esc(rotuloMes(p.mes)) + '</td>' +
                    '<td><small>' + esc(p.nombre || p.nro) + '</small></td>' +
                    '<td>' + esc(fecha(p.fecha)) + '</td>' +
                    '<td class="text-end">' +
                        (celda && celda.valor_hora !== null ? importe(celda.valor_hora) : '—') +
                    '</td>' +
                    '<td class="text-end">' +
                        (p.importe === null ? '—' : importe(p.importe)) + '</td>' +
                    '<td>' + estado + '</td>' +
                '</tr>';
            }).join('');
        }

        texto('logDetallePie', f.total === null
            ? 'No se proyecta nada de este fletero.'
            : 'Total proyectado: ' + importeTexto(f.total));

        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('modalDetalleFletero')).show();
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardarCampo(input) {
        var cod = input.getAttribute('data-cod');
        var campo = input.getAttribute('data-campo');
        var f = null;

        datos.planilla.fleteros.forEach(function(x) {
            if (x.cod_provee === cod) { f = x; }
        });

        if (!f) { return; }

        if (campo !== 'mes_base' && input.value !== '') {
            var n = Number(input.value);

            if (isNaN(n) || n <= 0) {
                Notificacion.campoInvalido(input,
                    'Tiene que ser un número mayor a 0. Un cero significaría que no se le paga, '
                    + 'y eso se expresa dando de baja al fletero en Parámetros › Logística.');

                return;
            }
        }

        /* SE MANDAN LOS TRES CAMPOS, no sólo el que cambió: guardarFletero()
           escribe la fila entera, así que mandar uno solo borraría los otros
           dos. */
        var cuerpo = {
            cod_provee: cod,
            horas: campo === 'horas' ? input.value : f.horas_mes,
            valor_hora: campo === 'valor_hora' ? input.value : f.valor_hora_base,
            mes_base: campo === 'mes_base' ? input.value : f.mes_base
        };

        pedir(ENDPOINT + '?action=saveFletero', cuerpo)
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

    /* ================================================================
       RESUMEN Y CRONOGRAMA
       ================================================================ */

    function pintarResumen() {
        var fleteros = datos.planilla.fleteros || [];
        var total = datos.planilla.totales.total;

        texto('logTotal', total === null ? '—' : importeTexto(total));
        texto('logFleteros', String(fleteros.length));

        /* CUENTA LOS QUE NO PROYECTAN NINGUN MES, que es el numero que importa:
           un fletero al que le falta la inflacion de marzo proyecta igual hasta
           febrero, y contarlo acá exageraría el problema. */
        var sinProyectar = fleteros.filter(function(f) {
            // Un no habitual sin pagos puntuales no proyecta, pero no le falta nada.
            return f.total === null && f.habitual !== false;
        }).length;

        texto('logSinProyectar', String(sinProyectar));

        var pie = document.getElementById('logTotalPie');

        if (pie) {
            /* QUE PERIODO MIDE el total de la tarjeta. La vista Meses no
               cubre el horizonte completo -sus columnas son solo los dias
               de fuera del tramo diario- asi que un total sin decir de que
               periodo es no describe nada. */
            pie.textContent = 'Sin IVA ni otros conceptos · ' + vistas.periodo();
        }
    }

    function pintarCrono() {
        var el = document.getElementById('logCronoTexto');

        if (!el) { return; }

        var enTramo = (datos.cronograma || []).filter(function(p) { return p.en_tramo; });

        /* Cuándo se paga lo dice la configuración del cronograma LOGISTICA, no
           un texto fijo: el día y la frecuencia se cambian en Parámetros ›
           Generales. */
        var regla = (datos.cronograma_config && datos.cronograma_config.descripcion) || '';
        var ayuda = document.getElementById('logRegla');

        if (ayuda) {
            ayuda.textContent = regla ? 'en partes iguales, ' + regla : '';
        }

        el.innerHTML = 'Se paga <strong>' + esc(regla || 'según el cronograma') + '</strong> de cada mes' +
            (enTramo.length
                ? '; dentro del tramo diario caen ' + enTramo.length + ' fecha(s).'
                : '.') +
            ' Las fechas se ven y se editan en Parámetros › Generales.';
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /**
     * El panel plegable de todas las pestañas (Js/avisos-panel.js). Los textos
     * sueltos cuentan como atención; un error de carga llega como
     * {nivel: 'danger'} y abre el panel solo.
     *
     * Los de la planilla van con los que el tablero dice de esta pestaña, sin
     * repetidos (conTablero); un error de carga va solo, para que la llegada
     * de los del tablero no lo pise.
     */
    function avisar(lista, conTablero) {
        if (conTablero) {
            AvisosPanel.pintarPestana('avisosLogistica', lista || [], { tab: 'logistica_local' });
        } else {
            AvisosPanel.pintar('avisosLogistica', lista || []);
        }
    }

    function importe(v) {
        if (v === null || v === undefined || isNaN(Number(v))) {
            return '<span class="text-muted">—</span>';
        }

        return importeTexto(v);
    }

    function importeTexto(v) {
        return '$ ' + Number(v).toLocaleString('es-AR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    /** '2026-09' -> 'Sep-26'. El mismo rótulo que usa el eje del tablero */
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

    function texto(id, t) {
        var el = document.getElementById(id);

        if (el) { el.textContent = t; }
    }

    function mostrar(id, visible) {
        var el = document.getElementById(id);

        if (el) { el.style.display = visible ? '' : 'none'; }
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
        vistas = crearEjeVistas({
            botones: 'vistasLogistica',
            periodo: 'periodoLogistica',
            alCambiar: dibujar
        });

        var btn = document.getElementById('btnRefreshLogistica');

        if (btn) { btn.addEventListener('click', cargar); }

        var buscar = document.getElementById('busquedaLogistica');

        if (buscar) {
            buscar.addEventListener('input', function() {
                filtro = buscar.value.trim();
                dibujar();
            });
        }

        /* Las columnas fijas las engancha el componente compartido. Con doce
           columnas de meses a la derecha, al scrollear se pierde de vista DE
           QUIÉN es cada número: por eso la de Fletero arranca fijada. Las
           elegibles las deriva el componente del encabezado, así que agregar
           una columna descriptiva no obliga a tocar esto. */
        if (typeof crearColumnasFijas === 'function') {
            crearColumnasFijas({
                tabla: 'tablaLogistica',
                control: 'colFijasLogistica',
                clave: 'logistica_local',
                porDefecto: [0]
            });
        }

        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
