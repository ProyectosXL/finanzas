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
 * POR QUE EL CRONOGRAMA VA EN TRES TABLAS
 * ----------------------------------------
 * Hay un cronograma por concepto -Proveedores Locales, Logistica, Supervisoras-
 * y la card los muestra en tres tablas, una por pregunta:
 *
 *   1. La CONFIGURACION: el dia y la frecuencia de cada concepto. Cambiarla da de
 *      baja los pagos movidos a mano de ese concepto desde este mes, y antes de
 *      guardar se confirma diciendo cuantos.
 *   2. Los DOS PROXIMOS PAGOS de cada concepto: los unicos que se editan, en la
 *      misma fila (fecha y motivo).
 *   3. El RESTO DEL HORIZONTE: se calcula igual porque decide que parte de un
 *      importe mensual cae dentro del tramo diario, pero no se edita. Una sola
 *      tabla con unas filas editables y otras no se lee como un error de la
 *      pantalla.
 *
 * Los textos que decian "2do y 4to viernes" salen de la configuracion: la
 * descripcion la arma el backend (CronogramaPagos::describir()).
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

    var valores = {};
    var inflacion = null;
    var cronograma = null;

    /** Cuantos pagos se editan POR CONCEPTO: los dos proximos */
    var PAGOS_EDITABLES = 2;

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
                    '<div class="param-clave">' + esc(ETIQUETAS[clave] || etiqueta(clave)) +
                        Auditoria.icono({ usuario: p.USUARIO, fecha: p.FECHA_UPDATE }) + '</div>' +
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

        // Sin permiso de edicion, los valores como texto (Js/permisos.js)
        Permisos.soloLectura(grid);

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
                    (esc(Auditoria.linea('modif', m.usuario, m.fecha_update)) || '—') + '</small></td>' +
                '<td><small class="text-muted">' +
                    (m.proyecta ? 'Sí' : 'Ya pasó: sólo sirve para un ajuste viejo') +
                    '</small></td>' +
            '</tr>';
        }).join('');

        Permisos.soloLectura(cuerpo);

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
       CRONOGRAMA: UNO POR CONCEPTO
       ================================================================ */

    /** Los conceptos, en el orden en que vienen del backend */
    function conceptos() {
        return (cronograma && cronograma.configuraciones) || [];
    }

    function pintarCronograma() {
        if (!cronograma) { return; }

        pintarConfig();
        pintarProximos();
        pintarResto();
    }

    /**
     * Cómo se dice una configuración: "el 2do y el 4to miércoles" o "todos los
     * lunes".
     *
     * ES LA MISMA REDACCIÓN QUE CronogramaPagos::describir(), y está acá sólo
     * para la confirmación ANTES de guardar, cuando el backend todavía no la
     * armó. Lo que ya está guardado se muestra con la descripción del backend.
     */
    function describir(dia, frecuencia) {
        var nombre = (cronograma.dias || {})[dia] || '';

        if (frecuencia === 'SEMANAL') {
            return 'todos los ' + (/s$/.test(nombre) ? nombre : nombre + 's');
        }

        return 'el 2do y el 4to ' + nombre;
    }

    /* ---- 1. la configuración ---- */

    function pintarConfig() {
        var cuerpo = document.getElementById('pgenCronoConfigBody');

        if (!cuerpo) { return; }

        var dias = cronograma.dias || {};
        var frecuencias = cronograma.frecuencias || [];

        cuerpo.innerHTML = conceptos().length ? conceptos().map(function(c) {
            var off = c.configurable ? '' :
                ' disabled title="Falta correr sql/cashflow_cronograma_conceptos.sql"';

            var optDias = Object.keys(dias).map(function(d) {
                return '<option value="' + esc(d) + '"' +
                    (Number(d) === Number(c.dia) ? ' selected' : '') + '>' + esc(dias[d]) +
                    '</option>';
            }).join('');

            var optFrec = frecuencias.map(function(f) {
                return '<option value="' + esc(f) + '"' + (f === c.frecuencia ? ' selected' : '') +
                    '>' + esc(f === 'SEMANAL' ? 'Semanal' : 'Quincenal') + '</option>';
            }).join('');

            return '<tr data-concepto="' + esc(c.concepto) + '">' +
                '<td class="fw-semibold">' + esc(c.nombre) +
                    Auditoria.icono({ usuario: c.usuario, fecha: c.fecha_modif }) +
                    (c.defecto
                        ? ' <span class="badge bg-warning text-dark" ' +
                          'title="No hay parámetro cargado: se usa el valor por defecto">' +
                          'por defecto</span>'
                        : '') +
                '</td>' +
                '<td><small class="text-muted">' + esc(c.usa) + '</small></td>' +
                '<td><select class="form-select form-select-sm pgen-crono-dia"' + off + '>' +
                    optDias + '</select></td>' +
                '<td><select class="form-select form-select-sm pgen-crono-frec"' + off + '>' +
                    optFrec + '</select></td>' +
                '<td>' + esc(c.descripcion) + ' de cada mes</td>' +
            '</tr>';
        }).join('') :
            '<tr><td colspan="5" class="text-center text-muted py-3">' +
            'No se pudo resolver el cronograma.</td></tr>';

        // Sin permiso, el día y la frecuencia se ven como texto. Ver Js/permisos.js.
        Permisos.soloLectura(cuerpo);

        cuerpo.querySelectorAll('tr[data-concepto]').forEach(function(fila) {
            fila.querySelectorAll('select').forEach(function(sel) {
                sel.addEventListener('change', function() { cambiarConfig(fila); });
            });
        });
    }

    /**
     * Cambiar el día o la frecuencia de un concepto.
     *
     * PRIMERO PREGUNTA CUÁNTOS PAGOS MOVIDOS A MANO SE DAN DE BAJA, y lo dice en
     * la confirmación: con otro día el número de pago apunta a otra fecha, y el
     * override quedaría colgado de un pago que ya no es el mismo. Un cambio que
     * se lleva puestos tres pagos sin decirlo no lo decidió nadie entero.
     *
     * Si se cancela, la fila vuelve a lo guardado: un desplegable que muestra un
     * valor que no está guardado se lee como guardado.
     */
    function cambiarConfig(fila) {
        var concepto = fila.getAttribute('data-concepto');
        var dia = Number(fila.querySelector('.pgen-crono-dia').value);
        var frecuencia = fila.querySelector('.pgen-crono-frec').value;
        var nombre = (buscarConfig(concepto) || {}).nombre || concepto;

        pedir(ENDPOINT + '?action=contarOverridesCronograma&concepto=' +
              encodeURIComponent(concepto))
            .then(function(d) {
                var bajas = (d.data && d.data.bajas) || 0;

                return Notificacion.confirmar({
                    titulo: 'Cambiar el cronograma de ' + nombre,
                    mensaje: '¿Pagar ' + nombre + ' ' + describir(dia, frecuencia) +
                        ' de cada mes?',
                    detalle: bajas > 0
                        ? 'Se dan de baja ' + bajas + ' pago(s) movido(s) a mano de ' + nombre +
                          ' desde este mes: con otro día apuntan a otra fecha. Quedan en el ' +
                          'historial.'
                        : 'No hay pagos movidos a mano de ' + nombre + ' desde este mes, así ' +
                          'que no se da de baja ninguno.',
                    confirmar: 'Cambiar',
                    peligro: bajas > 0
                });
            })
            .then(function(si) {
                if (!si) {
                    pintarConfig();

                    return null;
                }

                return pedir(ENDPOINT + '?action=saveConfigCronograma', {
                    concepto: concepto, dia: dia, frecuencia: frecuencia
                }).then(function(d) {
                    Notificacion.exito(d.message || 'Cronograma guardado.');
                    cargar();
                });
            })
            .catch(function(e) {
                Notificacion.error('No se pudo cambiar el cronograma: ' + e.message);
                pintarConfig();
            });
    }

    function buscarConfig(concepto) {
        var lista = conceptos();

        for (var i = 0; i < lista.length; i++) {
            if (lista[i].concepto === concepto) { return lista[i]; }
        }

        return null;
    }

    /* ---- 2. los próximos pagos de cada concepto ---- */

    /**
     * Los dos próximos pagos de un concepto: los primeros cuya fecha -la que
     * vale o la calculada- no pasó todavía. Con la calculada también, para que
     * un pago adelantado a mano a ayer no desaparezca de la tabla editable sin
     * que nadie pueda volverlo atrás. Sólo del horizonte: los meses extra que
     * pide Proveedores Locales no se ofrecen.
     */
    function proximosDe(concepto) {
        var hoy = cronograma.hoy || '';
        var datos = (cronograma.conceptos || {})[concepto] || {};

        return (datos.pagos || []).filter(function(p) {
            return p.en_horizonte !== false && (p.fecha >= hoy || p.calculada >= hoy);
        }).slice(0, PAGOS_EDITABLES);
    }

    function pintarProximos() {
        var cuerpo = document.getElementById('pgenCronogramaBody');

        if (!cuerpo) { return; }

        var html = '';

        conceptos().forEach(function(c) {
            var datos = (cronograma.conceptos || {})[c.concepto] || {};

            proximosDe(c.concepto).forEach(function(p) {
                html += filaCrono(c, p, !!datos.editable);
            });
        });

        cuerpo.innerHTML = html ||
            '<tr><td colspan="7" class="text-center text-muted py-3">' +
            'No hay pagos próximos en el horizonte.</td></tr>';

        // El historial se puede mirar sin permiso de edicion: va con data-lectura
        Permisos.soloLectura(cuerpo);

        cuerpo.querySelectorAll('tr[data-mes]').forEach(function(fila) {
            var acciones = {
                guardar: function() { guardarFecha(fila); },
                volver: function() { volverACalculado(fila); },
                historial: function() { abrirHistorial(fila); }
            };

            fila.querySelectorAll('[data-accion]').forEach(function(btn) {
                btn.addEventListener('click', acciones[btn.getAttribute('data-accion')]);
            });
        });
    }

    function filaCrono(c, p, editable) {
        var off = editable ? '' : ' disabled';
        var corrida = p.corrida
            ? ' <span class="badge bg-warning text-dark" ' +
              'title="El día de pago no es hábil: se corrió al día hábil anterior">' +
              'corrida</span>'
            : '';
        var aMano = p.override
            ? '<span class="badge bg-info text-dark mt-1">a mano</span>' +
              Auditoria.icono({ alta: { usuario: p.usuario, fecha: p.fecha_alta } }) : '';
        var motivoOff = editable ? '' :
            ' title="Falta correr sql/cashflow_cronograma_conceptos.sql"';

        return '<tr data-concepto="' + esc(c.concepto) + '" data-mes="' + esc(p.mes) + '" ' +
                'data-nro="' + p.nro + '" data-nombre="' + esc(p.nombre) + '" ' +
                'data-calculada="' + esc(p.calculada) + '">' +
            '<td class="fw-semibold">' + esc(c.nombre) + '</td>' +
            '<td>' + esc(rotuloMes(p.mes)) +
                '<div><small class="text-muted">' + esc(p.nombre) + '</small></div></td>' +
            '<td><small class="text-muted">' + esc(fecha(p.teorica)) + '</small></td>' +
            '<td><small class="text-muted">' + esc(fecha(p.calculada)) + '</small>' + corrida + '</td>' +
            '<td>' +
                '<input type="date" class="form-control form-control-sm pgen-crono-fecha" ' +
                    'value="' + esc(p.fecha) + '"' + off + '>' + aMano +
            '</td>' +
            '<td>' +
                '<input type="text" class="form-control form-control-sm pgen-crono-motivo" ' +
                    'maxlength="300" placeholder="Por qué no va en la fecha calculada" ' +
                    'value="' + esc(p.motivo || '') + '"' + off + motivoOff + '>' +
            '</td>' +
            '<td class="text-center text-nowrap">' +
                '<button class="btn btn-sm btn-primary" data-accion="guardar" title="Guardar"' +
                    (editable ? '' : ' disabled') + '><i class="fas fa-check"></i></button> ' +
                '<button class="btn btn-sm btn-outline-danger" data-accion="volver" ' +
                    'title="Volver a la fecha calculada"' +
                    (editable && p.override ? '' : ' disabled') +
                    '><i class="fas fa-rotate-left"></i></button> ' +
                '<button class="btn btn-sm btn-outline-secondary" data-accion="historial" data-lectura ' +
                    'title="Historial"><i class="fas fa-clock-rotate-left"></i></button>' +
            '</td>' +
        '</tr>';
    }

    /* ---- 3. el resto del horizonte ---- */

    /**
     * Un renglón por MES y una columna por CONCEPTO, con todas las fechas del
     * mes en la celda: es como se lee un cronograma, y en semanal son cuatro o
     * cinco fechas. Una fila por pago triplicaría el largo sin decir más.
     */
    function pintarResto() {
        var head = document.getElementById('pgenCronoRestoHead');
        var cuerpo = document.getElementById('pgenCronoRestoBody');

        if (!head || !cuerpo) { return; }

        head.innerHTML = '<th>Mes</th>' + conceptos().map(function(c) {
            return '<th>' + esc(c.nombre) +
                '<div><small class="text-muted fw-normal">' + esc(c.descripcion) +
                '</small></div></th>';
        }).join('');

        var porMes = {};

        conceptos().forEach(function(c) {
            var proximos = proximosDe(c.concepto);
            var datos = (cronograma.conceptos || {})[c.concepto] || {};

            (datos.pagos || []).forEach(function(p) {
                if (p.en_horizonte === false || proximos.indexOf(p) !== -1) { return; }

                porMes[p.mes] = porMes[p.mes] || {};
                (porMes[p.mes][c.concepto] = porMes[p.mes][c.concepto] || []).push(p);
            });
        });

        cuerpo.innerHTML = Object.keys(porMes).sort().map(function(mes) {
            return '<tr><td class="fw-semibold">' + esc(rotuloMes(mes)) + '</td>' +
                conceptos().map(function(c) {
                    return '<td>' + celdaResto(porMes[mes][c.concepto]) + '</td>';
                }).join('') +
            '</tr>';
        }).join('');
    }

    function celdaResto(pagos) {
        if (!pagos || !pagos.length) { return '—'; }

        return pagos.map(function(p) {
            return '<span class="text-nowrap" title="' + esc(p.nombre) + '">' +
                esc(fecha(p.fecha)) +
                (p.override ? ' <span class="badge bg-info text-dark">a mano</span>' : '') +
                (p.corrida ? ' <span class="badge bg-warning text-dark">corrida</span>' : '') +
                '</span>';
        }).join('<br>');
    }

    /* ================================================================
       LA EDICION EN LA FILA Y EL HISTORIAL
       ================================================================ */

    function abrirHistorial(fila) {
        var concepto = fila.getAttribute('data-concepto');

        texto('pgenFechaTitulo', ((buscarConfig(concepto) || {}).nombre || concepto) + ' — ' +
            rotuloMes(fila.getAttribute('data-mes')) + ' — ' + fila.getAttribute('data-nombre'));
        cargarHistorial(concepto, fila.getAttribute('data-mes'),
            Number(fila.getAttribute('data-nro')));
        modal().show();
    }

    function cargarHistorial(concepto, mes, nro) {
        var cuerpo = document.getElementById('pgenHistorialFechaBody');

        if (!cuerpo) { return; }

        cuerpo.innerHTML = '<tr><td colspan="7" class="text-muted">Leyendo…</td></tr>';

        pedir(ENDPOINT + '?action=getHistorialCronograma&concepto=' + encodeURIComponent(concepto) +
              '&mes=' + encodeURIComponent(mes) + '&nro=' + nro)
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
                            '<td><small>' + esc(Auditoria.quien(f.usuario) || '—') + '</small></td>' +
                            '<td><small>' + esc((f.fecha_alta || '').substring(0, 16)) + '</small></td>' +
                            '<td><small>' + esc((f.fecha_baja || '—').substring(0, 16)) +
                                (f.usuario_baja ? ' · ' + esc(Auditoria.quien(f.usuario_baja)) : '') +
                            '</small></td>' +
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

    function guardarFecha(fila) {
        var input = fila.querySelector('.pgen-crono-fecha');
        var motivo = fila.querySelector('.pgen-crono-motivo');

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
            concepto: fila.getAttribute('data-concepto'),
            mes: fila.getAttribute('data-mes'),
            nro: Number(fila.getAttribute('data-nro')),
            fecha: input.value,
            fecha_calculada: fila.getAttribute('data-calculada'),
            motivo: motivo.value.trim()
        })
            .then(function(d) {
                Notificacion.exito(d.message || 'Fecha guardada.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar la fecha: ' + e.message);
            });
    }

    function volverACalculado(fila) {
        pedir(ENDPOINT + '?action=quitarFechaCronograma', {
            concepto: fila.getAttribute('data-concepto'),
            mes: fila.getAttribute('data-mes'),
            nro: Number(fila.getAttribute('data-nro'))
        })
            .then(function(d) {
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
