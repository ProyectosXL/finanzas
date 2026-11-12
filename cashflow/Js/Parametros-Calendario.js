/**
 * Parametros -> Calendario
 *
 * Todo lo que es una FECHA y mueve a todas las pestañas:
 *
 *   - el HORIZONTE: cuantas columnas diarias y mensuales tiene el eje;
 *   - los FERIADOS BANCARIOS: RO_T_CALENDARIO (externa, no se toca) mas los
 *     ajustes de RO_T_CASHFLOW_CALENDARIO_AJUSTE, que Ventas::getDiasHabiles()
 *     aplica para todo el modulo. Ver Class/CalendarioAjustes.php;
 *   - los FERIADOS DE COMERCIO: dias sin venta estimada. Se siguen guardando
 *     como el parametro 'feriados_comercio', CSV de MM-DD; aca se editan como
 *     lista de DD/MM, que es como se leen;
 *   - el CRONOGRAMA DE PAGOS, que vino entero desde Generales (antes pgen-).
 *
 * LOS DOS FERIADOS SON DOS CONCEPTOS, y por eso van en dos bloques de la misma
 * card: el bancario corre fechas al dia habil, el de comercio saca la venta de
 * un dia. Un dia puede ser una cosa, la otra o las dos.
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
 *      importe mensual cae dentro del tramo diario, pero no se edita.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/ParametrosController.php';

    /** Los parametros del eje, en el orden en que se leen */
    var ORDEN = ['horizonte_dias', 'horizonte_meses'];

    /** Como se dibuja y se valida cada uno */
    var FORMATO = {
        horizonte_dias: { tipo: 'entero', sufijo: 'días', paso: '1', min: 0, max: 365 },
        horizonte_meses: { tipo: 'entero', sufijo: 'meses', paso: '1', min: 1, max: 60 }
    };

    var ETIQUETAS = {
        horizonte_dias: 'Columnas diarias',
        horizonte_meses: 'Columnas mensuales'
    };

    /**
     * La nota que va debajo de cada campo: la CONSECUENCIA de tocarlo, que es
     * lo que no se deduce del nombre.
     */
    var HINTS = {
        horizonte_dias:
            'El tramo diario arranca HOY. Subirlo mueve importes de una columna mensual a ' +
            'una diaria, no los agrega: un importe va a un día O al mes, nunca a los dos.',
        horizonte_meses:
            'La vista Meses NO cubre el horizonte completo: cada columna mensual acumula ' +
            'sólo los días de ese mes que quedan fuera del tramo diario.'
    };

    /** Este no va en la grilla: tiene su propio bloque, como lista */
    var CLAVE_COMERCIO = 'feriados_comercio';

    /** Por el índice de Date.getDay(): 0 es domingo. Sólo para el rótulo */
    var DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    var valores = {};
    var feriados = null;
    var cronograma = null;

    /** El año que se está mirando en la grilla de feriados bancarios */
    var anioVisto = null;

    /** Cuantos pagos se editan POR CONCEPTO: los dos proximos */
    var PAGOS_EDITABLES = 2;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        return ParametrosCarga.pedir('CALENDARIO')
            .then(function(data) {
                var modulo = buscarModulo(data, 'CALENDARIO');

                if (!modulo) {
                    avisar([{ nivel: 'danger', texto: 'El módulo Calendario no está declarado en Parámetros.' }]);

                    return;
                }

                var desc = document.getElementById('pcalDescripcion');

                if (desc && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' +
                        esc(modulo.descripcion);
                }

                valores = {};

                (modulo.generales || []).forEach(function(p) { valores[p.CLAVE] = p; });

                feriados = modulo.feriados_bancarios || null;
                cronograma = modulo.cronograma || null;

                avisar(modulo.avisos || []);
                pintarGrid();
                pintarComercio();
                pintarCronograma();

                /* Si se estaba mirando otro año, se vuelve a ése: guardar un
                   feriado de 2027 y ver aparecer 2026 se lee como que no se
                   guardó. */
                if (feriados && anioVisto !== null && anioVisto !== feriados.anio) {
                    return cambiarAnio(anioVisto);
                }

                pintarFeriados();
            })
            .catch(function(e) {
                avisar([{ nivel: 'danger', texto: 'No se pudo leer el calendario: ' + e.message }]);

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

    /* ================================================================
       EL HORIZONTE
       ================================================================ */

    function pintarGrid() {
        var grid = document.getElementById('pcalGrid');

        if (!grid) { return; }

        /* SE DIBUJA LO QUE VINO, en el orden declarado y con lo que no esté en
           ORDEN al final -salvo los feriados de comercio, que tienen su
           bloque-: una clave que alguien agregue al módulo tiene que verse. */
        var claves = ORDEN.filter(function(c) { return valores[c]; });

        Object.keys(valores).forEach(function(c) {
            if (claves.indexOf(c) === -1 && c !== CLAVE_COMERCIO) { claves.push(c); }
        });

        if (!claves.length) {
            grid.innerHTML = '<div class="col-12"><div class="param-hint">' +
                'El horizonte todavía se edita en Parámetros › Generales: falta correr ' +
                'sql/cashflow_parametros_calendario.sql.</div></div>';

            return;
        }

        grid.innerHTML = claves.map(function(clave) {
            var p = valores[clave];
            var fmt = FORMATO[clave] || { tipo: 'texto', sufijo: '', paso: null };

            var input = (fmt.tipo === 'texto')
                ? '<input type="text" class="form-control pcal-input text-start" ' +
                      'data-clave="' + esc(clave) + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + esc(p.VALOR) + '">'
                : '<input type="number" step="' + fmt.paso + '" class="form-control pcal-input" ' +
                      'data-clave="' + esc(clave) + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + esc(p.VALOR) + '">';

            return '<div class="col-md-6 col-lg-3">' +
                '<div class="param-card" id="pcal-card-' + esc(clave) + '">' +
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

        grid.querySelectorAll('.pcal-input').forEach(function(input) {
            input.addEventListener('change', function() { guardarParametro(input); });
        });
    }

    function guardarParametro(input) {
        var clave = input.getAttribute('data-clave');
        var tipo = input.getAttribute('data-tipo');
        var fmt = FORMATO[clave] || {};
        var valor = input.value;
        var etiq = ETIQUETAS[clave] || etiqueta(clave);

        if (tipo === 'entero') {
            var n = Number(valor);

            /* SE VALIDA ANTES DE MANDAR, y el mensaje dice el rango: un
               horizonte de cero meses no falla en la base -es un número
               válido- pero deja el tablero sin ninguna columna. */
            if (valor === '' || isNaN(n) || n < fmt.min || n > fmt.max) {
                Notificacion.campoInvalido(input,
                    etiq + ' tiene que ser un número entre ' + fmt.min + ' y ' + fmt.max + '.');

                return;
            }

            if (Math.floor(n) !== n) {
                Notificacion.campoInvalido(input, etiq + ' tiene que ser un entero.');

                return;
            }

            valor = String(n);
        }

        pedir(ENDPOINT + '?action=saveParametro', { clave: clave, valor: valor })
            .then(function() {
                if (valores[clave]) { valores[clave].VALOR = valor; }

                destacar('pcal-card-' + clave);

                /* EL HORIZONTE MUEVE EL CRONOGRAMA, así que se vuelve a pedir
                   todo. Sin esto, la tabla de abajo seguiría mostrando el
                   tramo diario viejo y las fechas editables serían otras. */
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar ' + etiq + ': ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                cargar();
            });
    }

    /* ================================================================
       FERIADOS BANCARIOS
       ================================================================ */

    function pintarFeriados() {
        var cuerpo = document.getElementById('pcalFeriadosBody');
        var selector = document.getElementById('pcalAnio');
        var agregar = document.getElementById('pcalAgregarFeriado');

        if (!cuerpo || !feriados) { return; }

        anioVisto = feriados.anio;

        if (selector) {
            var anios = (feriados.anios || []).slice();

            if (anios.indexOf(feriados.anio) === -1) { anios.push(feriados.anio); }

            anios.sort();
            selector.innerHTML = anios.map(function(a) {
                return '<option value="' + a + '"' + (a === feriados.anio ? ' selected' : '') +
                    '>' + a + '</option>';
            }).join('');
        }

        // Sin la tabla de ajustes no hay dónde guardar: el aviso dice qué script falta.
        if (agregar) {
            agregar.disabled = !feriados.tabla_creada;
            agregar.title = feriados.tabla_creada ? '' : 'Falta correr sql/cashflow_parametros_calendario.sql';
        }

        var off = feriados.tabla_creada ? '' :
            ' disabled title="Falta correr sql/cashflow_parametros_calendario.sql"';

        cuerpo.innerHTML = (feriados.filas || []).length
            ? feriados.filas.map(function(f) { return filaFeriado(f, off); }).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-3">' +
              'No hay feriados de lunes a viernes en ' + esc(feriados.anio) + '.</td></tr>';

        Permisos.soloLectura(cuerpo);

        cuerpo.querySelectorAll('tr[data-fecha]').forEach(function(fila) {
            fila.querySelectorAll('[data-accion]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (btn.getAttribute('data-accion') === 'habil') {
                        abrirFeriado(fila.getAttribute('data-fecha'), true);
                    } else {
                        quitarFeriado(fila);
                    }
                });
            });
        });
    }

    function filaFeriado(f, off) {
        var esAjuste = (f.origen === 'AJUSTE');
        var vale = f.habil
            ? '<span class="badge bg-success">Hábil</span>'
            : '<span class="badge bg-danger">Feriado</span>';

        /* EL ORIGEN DICE QUÉ CAMBIÓ: un ajuste que pasa a hábil un feriado del
           banco, o uno que agrega un día que el banco no tiene como feriado. */
        var nota = '';

        if (esAjuste && f.habil) {
            nota = 'Feriado en el calendario bancario';
        } else if (esAjuste && f.externo === null) {
            nota = 'RO_T_CALENDARIO no tiene la fecha';
        } else if (esAjuste) {
            nota = 'Hábil en el calendario bancario';
        }

        var origen = esAjuste
            ? '<span class="badge bg-info text-dark">Ajuste</span>' +
              (nota ? '<div><small class="text-muted">' + esc(nota) + '</small></div>' : '')
            : '<span class="badge bg-secondary">Calendario bancario</span>';

        var accion = esAjuste
            ? '<button class="btn btn-sm btn-outline-danger" data-accion="quitar" ' +
              'title="Quitar el ajuste: vuelve a valer RO_T_CALENDARIO"' + off + '>' +
              '<i class="fas fa-rotate-left me-1"></i>Quitar ajuste</button>'
            : (!f.habil
                ? '<button class="btn btn-sm btn-outline-primary" data-accion="habil" ' +
                  'title="El banco opera este día: pasarlo a hábil"' + off + '>' +
                  '<i class="fas fa-briefcase me-1"></i>Marcar hábil</button>'
                : '');

        return '<tr data-fecha="' + esc(f.fecha) + '" data-id="' + esc(f.id || '') + '"' +
                (esAjuste ? ' class="table-info"' : '') + '>' +
            '<td class="fw-semibold">' + esc(fechaLarga(f.fecha)) + '</td>' +
            '<td>' + vale + '</td>' +
            '<td>' + origen + '</td>' +
            '<td>' + (f.descripcion ? esc(f.descripcion) : '<span class="text-muted">—</span>') + '</td>' +
            '<td><small class="text-muted">' +
                (esAjuste ? (esc(Auditoria.linea('modif', f.usuario, f.fecha_update)) || '—') : '—') +
            '</small></td>' +
            '<td class="text-center text-nowrap">' + accion + '</td>' +
        '</tr>';
    }

    function cambiarAnio(anio) {
        var cuerpo = document.getElementById('pcalFeriadosBody');

        if (cuerpo) {
            cuerpo.innerHTML = '<tr><td colspan="6" class="text-muted">Leyendo…</td></tr>';
        }

        return pedir(ENDPOINT + '?action=getFeriadosBancarios&anio=' + encodeURIComponent(anio))
            .then(function(d) {
                feriados = d.data;
                pintarFeriados();

                /* EL DE UN AÑO SIN CALENDARIO es de ese año, y el panel de
                   arriba es del módulo: va como advertencia. El de la tabla de
                   ajustes faltante ya está en el panel y no se repite. */
                (feriados.avisos || []).filter(function(a) {
                    return a.indexOf('no tiene datos para') !== -1;
                }).forEach(function(a) { Notificacion.advertencia(a); });
            })
            .catch(function(e) {
                Notificacion.error('No se pudieron leer los feriados de ' + anio + ': ' + e.message);
            });
    }

    /**
     * El modal sirve para los dos gestos: AGREGAR UN FERIADO (fecha libre,
     * descripción obligatoria) y MARCAR HÁBIL un feriado del banco (fecha fija,
     * descripción opcional). La regla que vale es la del servidor
     * (CalendarioAjustes::validar()); acá sólo se ayuda a no mandar algo que
     * se sabe que vuelve rechazado.
     */
    var modoHabil = false;

    function abrirFeriado(fecha, habil) {
        modoHabil = !!habil;

        var input = document.getElementById('pcalFeriadoFecha');
        var desc = document.getElementById('pcalFeriadoDescripcion');

        texto('pcalFeriadoTitulo', habil
            ? 'Marcar hábil el ' + fechaLarga(fecha)
            : 'Agregar feriado bancario');

        if (input) {
            input.value = fecha || '';
            input.disabled = !!habil;
        }

        if (desc) {
            desc.value = '';
            desc.placeholder = habil ? 'Por qué el banco opera este día (opcional)' : 'Qué feriado es';
        }

        var obligatoria = document.getElementById('pcalFeriadoObligatoria');

        if (obligatoria) { obligatoria.style.display = habil ? 'none' : ''; }

        modalFeriado().show();
    }

    function guardarFeriado() {
        var input = document.getElementById('pcalFeriadoFecha');
        var desc = document.getElementById('pcalFeriadoDescripcion');

        if (!input || !input.value) {
            Notificacion.campoInvalido(input, 'Elegí una fecha.');

            return;
        }

        /* EL FIN DE SEMANA NO SE MIRA ACÁ: el criterio vive en PHP
           (CalendarioAjustes::validar()), y un sábado vuelve rechazado con su
           motivo. Ver tests/test_ejevista.php. */
        if (!modoHabil && (!desc || !desc.value.trim())) {
            Notificacion.campoInvalido(desc, 'Escribí qué feriado es.');

            return;
        }

        var anio = Number(input.value.substring(0, 4));

        pedir(ENDPOINT + '?action=saveFeriadoBancario', {
            fecha: input.value,
            dia_laboral: modoHabil ? 1 : 0,
            descripcion: desc ? desc.value.trim() : ''
        })
            .then(function(d) {
                modalFeriado().hide();
                Notificacion.exito(d.message || 'Guardado.');

                // El cronograma también puede cambiar: un pago que caía ese día se corre.
                anioVisto = anio;
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar: ' + e.message);
            });
    }

    function quitarFeriado(fila) {
        var fecha = fila.getAttribute('data-fecha');

        Notificacion.confirmar({
            titulo: 'Quitar el ajuste',
            mensaje: '¿Quitar el ajuste del ' + fechaLarga(fecha) + '?',
            detalle: 'Vuelve a valer lo que dice RO_T_CALENDARIO para ese día, en todo el ' +
                'cashflow. El ajuste queda en el historial.',
            confirmar: 'Quitar',
            peligro: true
        })
            .then(function(si) {
                if (!si) { return null; }

                return pedir(ENDPOINT + '?action=quitarFeriadoBancario',
                             { id: Number(fila.getAttribute('data-id')) })
                    .then(function(d) {
                        Notificacion.exito(d.message || 'Ajuste quitado.');
                        cargar();
                    });
            })
            .catch(function(e) {
                Notificacion.error('No se pudo quitar el ajuste: ' + e.message);
            });
    }

    function modalFeriado() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('pcalModalFeriado'));
    }

    /* ================================================================
       FERIADOS DE COMERCIO
       Se guardan como antes -'feriados_comercio', CSV de MM-DD- y se
       muestran como DD/MM, que es como se leen.
       ================================================================ */

    function listaComercio() {
        var p = valores[CLAVE_COMERCIO];

        if (!p) { return null; }

        return String(p.VALOR || '').split(',').map(function(s) { return s.trim(); })
            .filter(function(s) { return s !== ''; }).sort();
    }

    function pintarComercio() {
        var cont = document.getElementById('pcalComercioLista');
        var alta = document.getElementById('pcalComercioAlta');
        var audit = document.getElementById('pcalComercioAuditoria');
        var lista = listaComercio();

        if (!cont) { return; }

        /* Sin migrar, el parámetro sigue en Generales y se edita allá: acá no
           se ofrece una segunda forma de cambiarlo. */
        if (lista === null) {
            cont.innerHTML = '<span class="param-hint">Todavía se editan en Parámetros › ' +
                'Generales: falta correr sql/cashflow_parametros_calendario.sql.</span>';

            if (alta) { alta.style.display = 'none'; }
            if (audit) { audit.innerHTML = ''; }

            return;
        }

        var edita = Permisos.puedeEditar(cont);

        if (alta) { alta.style.display = edita ? '' : 'none'; }

        if (audit) {
            audit.innerHTML = Auditoria.icono({ usuario: valores[CLAVE_COMERCIO].USUARIO,
                fecha: valores[CLAVE_COMERCIO].FECHA_UPDATE });
        }

        cont.innerHTML = lista.length
            ? lista.map(function(md) {
                return '<span class="badge bg-light text-dark border fs-6 fw-normal" data-md="' +
                    esc(md) + '">' + esc(ddmm(md)) +
                    (edita
                        ? ' <button type="button" class="btn-close ms-1" style="font-size: .6rem;" ' +
                          'title="Quitar" data-quitar="' + esc(md) + '"></button>'
                        : '') +
                    '</span>';
            }).join('')
            : '<span class="param-hint">No hay ningún feriado de comercio: todos los días ' +
              'tienen venta estimada.</span>';

        cont.querySelectorAll('[data-quitar]').forEach(function(b) {
            b.addEventListener('click', function() {
                var md = b.getAttribute('data-quitar');

                guardarComercio(lista.filter(function(x) { return x !== md; }),
                    'Se quitó el ' + ddmm(md) + ': ese día vuelve a tener venta estimada.');
            });
        });
    }

    function agregarComercio() {
        var input = document.getElementById('pcalComercioNuevo');
        var lista = listaComercio();

        if (!input || lista === null) { return; }

        var m = /^(\d{1,2})\/(\d{1,2})$/.exec(input.value.trim());
        var d = m ? Number(m[1]) : 0;
        var mes = m ? Number(m[2]) : 0;
        // 2024 es bisiesto: el 29/02 es un día que existe.
        var prueba = new Date(2024, mes - 1, d);

        if (!m || prueba.getMonth() !== mes - 1 || prueba.getDate() !== d) {
            Notificacion.campoInvalido(input, 'Escribí el día y el mes como DD/MM, por ejemplo 25/12.');

            return;
        }

        var md = pad(mes) + '-' + pad(d);

        if (lista.indexOf(md) !== -1) {
            Notificacion.campoInvalido(input, 'El ' + ddmm(md) + ' ya está en la lista.');

            return;
        }

        guardarComercio(lista.concat([md]).sort(),
            'Se agregó el ' + ddmm(md) + ': ese día no tiene venta estimada, todos los años.')
            .then(function(ok) { if (ok) { input.value = ''; } });
    }

    function guardarComercio(lista, mensaje) {
        var valor = lista.join(',');

        return pedir(ENDPOINT + '?action=saveParametro', { clave: CLAVE_COMERCIO, valor: valor })
            .then(function() {
                valores[CLAVE_COMERCIO].VALOR = valor;
                pintarComercio();
                Notificacion.exito(mensaje);

                return true;
            })
            .catch(function(e) {
                Notificacion.error('No se pudieron guardar los feriados de comercio: ' + e.message);
                cargar();

                return false;
            });
    }

    /** '12-25' -> '25/12' */
    function ddmm(md) {
        var p = String(md).split('-');

        return (p.length < 2) ? String(md) : p[1] + '/' + p[0];
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
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
        var cuerpo = document.getElementById('pcalCronoConfigBody');

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

            // Sólo las que el concepto admite: Gastos Locales es semanal fijo.
            var optFrec = (c.frecuencias || frecuencias).map(function(f) {
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
                '<td><select class="form-select form-select-sm pcal-crono-dia"' + off + '>' +
                    optDias + '</select></td>' +
                '<td><select class="form-select form-select-sm pcal-crono-frec"' + off + '>' +
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
        var dia = Number(fila.querySelector('.pcal-crono-dia').value);
        var frecuencia = fila.querySelector('.pcal-crono-frec').value;
        var nombre = (buscarConfig(concepto) || {}).nombre || concepto;

        pedir(ENDPOINT + '?action=contarOverridesCronograma&concepto=' +
              encodeURIComponent(concepto))
            .then(function(d) {
                var bajas = (d.data && d.data.bajas) || 0;
                var importes = (d.data && d.data.importes) || [];

                /* LOS IMPORTES EDITADOS DEL PRÓXIMO PAGO DE LOS FLETEROS, uno por
                   uno: quién y por cuánto, antes de guardar. Con otro día, el
                   número de pago apunta a otro pago y la edición se pierde. */
                var detalleImportes = importes.length
                    ? ' También se pierden ' + importes.length + ' importe(s) editado(s) del ' +
                      'próximo pago, que vuelven al calculado: ' +
                      importes.map(function(i) {
                          return (i.nombre || i.cod_provee) + ' (' + fecha(i.fecha) + ', ' +
                              plata(i.importe) + ')';
                      }).join('; ') + '.'
                    : '';

                return Notificacion.confirmar({
                    titulo: 'Cambiar el cronograma de ' + nombre,
                    mensaje: '¿Pagar ' + nombre + ' ' + describir(dia, frecuencia) +
                        ' de cada mes?',
                    detalle: (bajas > 0
                        ? 'Se dan de baja ' + bajas + ' pago(s) movido(s) a mano de ' + nombre +
                          ' desde este mes: con otro día apuntan a otra fecha. Quedan en el ' +
                          'historial.'
                        : 'No hay pagos movidos a mano de ' + nombre + ' desde este mes, así ' +
                          'que no se da de baja ninguno.') + detalleImportes,
                    confirmar: 'Cambiar',
                    peligro: bajas > 0 || importes.length > 0
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
        var cuerpo = document.getElementById('pcalCronogramaBody');

        if (!cuerpo) { return; }

        var html = '';

        conceptos().forEach(function(c) {
            var datos = (cronograma.conceptos || {})[c.concepto] || {};

            /* Un concepto cuyos pagos no se mueven a mano no va en esta tabla:
               sus fechas se ven abajo, con las demás. Con una fila apagada se
               leería como que falta correr un script. */
            if (c.overrides === false) { return; }

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
        var haciaDonde = (c && c.corrimiento === 'SIGUIENTE') ? 'siguiente' : 'anterior';
        var corrida = p.corrida
            ? ' <span class="badge bg-warning text-dark" ' +
              'title="El día de pago no es hábil: se corrió al día hábil ' + haciaDonde + '">' +
              'corrida</span>'
            : '';
        // En un concepto que se corre al siguiente, la fecha a mano que cae en un
        // no hábil también se corre: el input muestra la cargada y esto, la que vale.
        var ovCorrido = p.override_corrido
            ? ' <span class="badge bg-warning text-dark mt-1" ' +
              'title="La fecha cargada a mano no es hábil: se paga el día hábil siguiente">' +
              'se paga el ' + esc(fecha(p.fecha)) + '</span>'
            : '';
        var aMano = p.override
            ? '<span class="badge bg-info text-dark mt-1">a mano</span>' + ovCorrido +
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
                '<input type="date" class="form-control form-control-sm pcal-crono-fecha" ' +
                    'value="' + esc(p.override_fecha || p.fecha) + '"' + off + '>' + aMano +
            '</td>' +
            '<td>' +
                '<input type="text" class="form-control form-control-sm pcal-crono-motivo" ' +
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
        var head = document.getElementById('pcalCronoRestoHead');
        var cuerpo = document.getElementById('pcalCronoRestoBody');

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
                if (p.en_horizonte === false ||
                    (c.overrides !== false && proximos.indexOf(p) !== -1)) { return; }

                porMes[p.mes] = porMes[p.mes] || {};
                (porMes[p.mes][c.concepto] = porMes[p.mes][c.concepto] || []).push(p);
            });
        });

        cuerpo.innerHTML = Object.keys(porMes).sort().map(function(mes) {
            return '<tr><td class="fw-semibold">' + esc(rotuloMes(mes)) + '</td>' +
                conceptos().map(function(c) {
                    return '<td>' + celdaResto(porMes[mes][c.concepto], c) + '</td>';
                }).join('') +
            '</tr>';
        }).join('');
    }

    function celdaResto(pagos, c) {
        if (!pagos || !pagos.length) { return '—'; }

        var haciaDonde = (c && c.corrimiento === 'SIGUIENTE') ? 'siguiente' : 'anterior';

        return pagos.map(function(p) {
            return '<span class="text-nowrap" title="' + esc(p.nombre) + '">' +
                esc(fecha(p.fecha)) +
                (p.override ? ' <span class="badge bg-info text-dark">a mano</span>' : '') +
                (p.corrida ? ' <span class="badge bg-warning text-dark" title="El día de pago ' +
                    'no es hábil: se corrió al día hábil ' + haciaDonde + '">corrida</span>' : '') +
                '</span>';
        }).join('<br>');
    }

    /* ================================================================
       LA EDICION EN LA FILA Y EL HISTORIAL
       ================================================================ */

    function abrirHistorial(fila) {
        var concepto = fila.getAttribute('data-concepto');

        texto('pcalFechaTitulo', ((buscarConfig(concepto) || {}).nombre || concepto) + ' — ' +
            rotuloMes(fila.getAttribute('data-mes')) + ' — ' + fila.getAttribute('data-nombre'));
        cargarHistorial(concepto, fila.getAttribute('data-mes'),
            Number(fila.getAttribute('data-nro')));
        modal().show();
    }

    function cargarHistorial(concepto, mes, nro) {
        var cuerpo = document.getElementById('pcalHistorialFechaBody');

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
        var input = fila.querySelector('.pcal-crono-fecha');
        var motivo = fila.querySelector('.pcal-crono-motivo');

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
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('pcalModalFecha'));
    }


    /* ================================================================
       UTILIDADES
       ================================================================ */

    /**
     * El panel plegable de todas las pestañas (Js/avisos-panel.js). Los textos
     * sueltos cuentan como atención; un error de carga llega como
     * {nivel: 'danger'} y abre el panel solo.
     */
    function avisar(lista) {
        AvisosPanel.pintar('pcalAvisos', lista || []);
    }

    /** '2026-09' -> 'Sep-26'. Es el mismo rótulo que usa el eje del tablero */
    function rotuloMes(mes) {
        var abrev = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                     'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var p = String(mes || '').split('-');

        if (p.length < 2) { return String(mes || ''); }

        return abrev[Number(p[1]) - 1] + '-' + p[0].substring(2);
    }

    /** 1234.5 -> '$ 1.234,50' */
    function plata(v) {
        return '$ ' + Number(v).toLocaleString('es-AR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    /** '2026-12-24' -> '24/12/2026' */
    function fecha(f) {
        if (!f) { return '—'; }

        var p = String(f).substring(0, 10).split('-');

        return (p.length < 3) ? String(f) : p[2] + '/' + p[1] + '/' + p[0];
    }

    /** '2026-11-23' -> 'lunes 23/11/2026' */
    function fechaLarga(f) {
        var d = new Date(String(f).substring(0, 10) + 'T00:00:00');

        return (isNaN(d.getTime()) ? '' : DIAS_SEMANA[d.getDay()] + ' ') + fecha(f);
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
        enganchar('pcalBtnRefresh', 'click', cargar);
        enganchar('pcalAgregarFeriado', 'click', function() { abrirFeriado('', false); });
        enganchar('pcalFeriadoGuardar', 'click', guardarFeriado);
        enganchar('pcalComercioAgregar', 'click', agregarComercio);
        enganchar('pcalAnio', 'change', function() {
            cambiarAnio(Number(document.getElementById('pcalAnio').value));
        });

        /* No carga al arrancar: carga cuando se abre su sub-pestaña. Ver
           Js/parametros-carga.js. */
        ParametrosCarga.registrar('CALENDARIO', cargar);
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
