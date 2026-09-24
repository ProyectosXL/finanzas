/**
 * Parámetros → Cob. Electrónicos
 *
 * Vive aparte de Parametros.js, igual que Parametros-Saldos.js: no comparte
 * estado con los bloques de Ventas, así que un problema acá no puede llevarse
 * puesta la pestaña que ya funciona. Pide su propio payload al mismo endpoint y
 * se queda con el módulo COB_ELECTRONICOS.
 *
 * DOS REGLAS QUE ESTA PANTALLA TIENE QUE HACER VISIBLES:
 *
 *   1. Una procesadora no se puede activar sin alícuota vigente. El switch se
 *      bloquea y dice por qué, en lugar de dejar mandar un guardado que el
 *      servidor va a rechazar. Cuando SÍ se puede, el switch guarda al tocarlo
 *      y el estado queda escrito al lado ("Activa" / "Inactiva"): un switch
 *      apagado en una fila atenuada se leía como "no se puede tocar", y así fue
 *      como una procesadora con alícuotas cargadas quedó sin activar.
 *   2. Editar un porcentaje INSERTA una vigencia nueva. Por eso no hay inputs
 *      editables sobre las filas de alícuotas: se carga una vigencia nueva y la
 *      anterior queda a la vista, que es lo que explica la tasa de un
 *      movimiento viejo.
 *
 * La validación que vale es la del servidor; acá se espeja sólo para bloquear el
 * botón y explicar el motivo antes de tipear todo.
 */

(function() {
    'use strict';

    var URL_PARAM = 'Controller/ParametrosController.php';

    var modulo = null;

    function inicializar() {
        conectar('btnRefreshParamCobel', cargar);
        conectar('btnGuardarProcesadoras', guardarProcesadoras);

        // El switch de activación guarda al tocarlo. Se delega en el tbody y
        // se ata UNA vez: las filas se redibujan en cada cargar(), y atar por
        // fila duplicaría el handler.
        var bodyProcs = document.getElementById('bodyProcesadoras');

        if (bodyProcs) {
            bodyProcs.addEventListener('change', function(e) {
                if (e.target && e.target.classList.contains('pce-proc-activo')) {
                    cambiarActivo(e.target);
                }
            });
        }

        conectar('btnNuevaProcesadora', function() { alternar('formProcesadora'); });
        conectar('btnCancelarProcesadora', function() { mostrar('formProcesadora', false); });
        conectar('btnAgregarProcesadora', agregarProcesadora);

        conectar('btnNuevaAlicuota', function() {
            alternar('formAlicuota');
            pintarSuma();
        });
        conectar('btnCancelarAlicuota', function() { mostrar('formAlicuota', false); });
        conectar('btnAgregarAlicuota', agregarAlicuota);

        ['nuevaAliProcesadora', 'nuevaAliConcepto', 'nuevaAliPorcentaje',
         'nuevaAliVigencia'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) {
                el.addEventListener('input', pintarSuma);
                el.addEventListener('change', pintarSuma);
            }
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
        Cargando.mostrar('loadingParamCobel');
        mostrar('wrapperParamCobel', false);

        pedirJson(URL_PARAM + '?action=getTodo')
            .then(function(data) {
                modulo = buscarModulo(data, 'COB_ELECTRONICOS');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo COB_ELECTRONICOS');
                }

                pintarDescripcion();
                pintarAvisos();
                pintarProcesadoras();
                pintarAlicuotas();
                pintarSelectores();
                pintarSuma();

                Cargando.ocultar('loadingParamCobel');
                mostrar('wrapperParamCobel', true);
            })
            .catch(function(error) {
                Cargando.ocultar('loadingParamCobel');
                avisar('Error al cargar los parámetros de Cob. Electrónicos: ' + error.message);
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
        var cont = document.getElementById('descripcionCobel');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + escapar(modulo.descripcion);
        }
    }

    /** Si el script SQL del módulo no se corrió, se avisa en vez de romper */
    function pintarAvisos() {
        var cont = document.getElementById('avisosParamCobel');
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
       PROCESADORAS
       ================================================================ */

    function pintarProcesadoras() {
        var procs = modulo.procesadoras || [];

        document.getElementById('bodyProcesadoras').innerHTML = procs.length
            ? procs.map(filaProcesadora).join('')
            : '<tr><td colspan="4" class="text-center text-muted py-4">' +
              'Todavía no hay procesadoras cargadas.</td></tr>';
    }

    function filaProcesadora(p) {
        var activa = (parseInt(p.ACTIVO, 10) === 1);
        var tasa = tasaVigente(p.ID);
        var puedeActivar = (tasa.conceptos > 0);
        var bloqueado = (!activa && !puedeActivar);

        // Tres situaciones, tres textos: bloqueada dice por qué, activable
        // dice qué pasa al tocarla, activa dice qué pasa al apagarla.
        var titulo = bloqueado
            ? 'No se puede activar sin al menos una alícuota vigente: sus movimientos no ' +
              'podrían calcular el importe neto. Cargale una alícuota en la sección de abajo.'
            : (activa
                ? 'Inhabilitarla la saca del alta de movimientos, pero los ya cargados quedan ' +
                  'enteros. Se guarda al tocar el switch.'
                : 'Activarla la habilita en el alta de movimientos. Se guarda al tocar el switch.');

        return '<tr class="' + (activa ? '' : 'pce-inactiva') + '">' +
            '<td>' +
                '<input type="text" class="form-control form-control-sm pce-proc-nombre" ' +
                    'data-id="' + p.ID + '" maxlength="80" value="' + escapar(p.RAZON_SOCIAL) +
                    '">' +
            '</td>' +
            '<td class="text-center pce-num">' + textoTasa(tasa) + '</td>' +
            '<td class="text-center pce-fecha">' +
                (p.FECHA_UPDATE ? fechaHora(p.FECHA_UPDATE) : '—') + '</td>' +
            // La celda del estado no se atenúa (ver Parametros.css): un switch
            // apagado y gris se lee como "no se puede tocar".
            '<td class="text-center pce-celda-estado">' +
                '<div class="form-check form-switch d-inline-block">' +
                    '<input class="form-check-input pce-proc-activo" type="checkbox" role="switch" ' +
                        'data-id="' + p.ID + '"' +
                        (activa ? ' checked' : '') +
                        (bloqueado ? ' disabled' : '') +
                        ' title="' + titulo + '">' +
                '</div>' +
                '<span class="pce-estado ' + (activa ? 'pce-estado-activa' : 'pce-estado-inactiva') +
                    '" title="' + titulo + '">' +
                    (activa ? 'Activa' : (bloqueado ? 'Inactiva · sin alícuota' : 'Inactiva')) +
                '</span>' +
            '</td>' +
        '</tr>';
    }

    /**
     * Guarda el cambio de estado de UNA procesadora en el momento en que se
     * toca el switch.
     *
     * Manda sólo esa fila: el endpoint acepta una lista, y mandar todas
     * pisaría los nombres de las demás con lo que haya en pantalla. Si hay
     * nombres editados sin guardar, el toggle se rechaza y se avisa: el
     * guardado recarga la tabla y esos nombres se perderían en silencio.
     */
    function cambiarActivo(checkbox) {
        var id = parseInt(checkbox.dataset.id, 10);
        var nuevo = checkbox.checked;
        var proc = (modulo.procesadoras || []).filter(function(p) {
            return parseInt(p.ID, 10) === id;
        })[0];

        if (!proc) {
            return;
        }

        if (hayNombresSinGuardar()) {
            checkbox.checked = !nuevo;
            Notificacion.advertencia('Guardá primero los nombres editados con "Guardar procesadoras".', {
                detalle: 'Cambiar el estado recarga la tabla y se perderían.'
            });

            return;
        }

        var nombreInput = document.querySelector('.pce-proc-nombre[data-id="' + id + '"]');
        var razon = (nombreInput ? nombreInput.value.trim() : '') || proc.RAZON_SOCIAL;

        checkbox.disabled = true;

        pedirJson(URL_PARAM + '?action=saveProcesadorasCobel', {
            filas: [{ id: id, razon_social: razon, activo: nuevo }]
        })
            .then(function() {
                Notificacion.exito(nuevo
                    ? '"' + razon + '" activada: ya se puede elegir al cargar un movimiento.'
                    : '"' + razon + '" inhabilitada: sale del alta de movimientos, los ya cargados quedan.');
                cargar();
            })
            .catch(function(error) {
                checkbox.checked = !nuevo;
                checkbox.disabled = false;
                Notificacion.error('No se pudo cambiar el estado de "' + razon + '": ' + error.message);
            });
    }

    /** Si algún nombre de procesadora en pantalla difiere del guardado */
    function hayNombresSinGuardar() {
        var porId = {};

        (modulo.procesadoras || []).forEach(function(p) {
            porId[parseInt(p.ID, 10)] = String(p.RAZON_SOCIAL || '').trim();
        });

        var sucio = false;

        document.querySelectorAll('.pce-proc-nombre').forEach(function(i) {
            var id = parseInt(i.dataset.id, 10);

            if (porId[id] !== undefined && i.value.trim() !== porId[id]) {
                sucio = true;
            }
        });

        return sucio;
    }

    /**
     * La tasa vigente HOY de una procesadora, calculada sobre las alícuotas que
     * trae el payload.
     *
     * Es la misma regla del servidor: de cada concepto, la última vigencia con
     * fecha menor o igual a hoy, y la suma de todas. Se calcula acá porque es lo
     * que decide si el switch de activación se puede tocar, y hacerlo con otro
     * criterio dejaría la pantalla ofreciendo algo que el servidor rechaza.
     */
    function tasaVigente(idProcesadora) {
        var hoy = fechaHoy();
        var porConcepto = {};

        (modulo.alicuotas || []).forEach(function(a) {
            if (parseInt(a.ID_PROCESADORA, 10) !== parseInt(idProcesadora, 10)) {
                return;
            }

            if (parseInt(a.ACTIVO, 10) !== 1) {
                return;
            }

            var desde = String(a.VIGENCIA_DESDE || '').slice(0, 10);

            if (!desde || desde > hoy) {
                return;
            }

            var previa = porConcepto[a.CONCEPTO];

            // Desempate por ID: dos vigencias de la misma fecha son posibles
            // -corregir dos veces el mismo día-, y gana la insertada después.
            if (!previa || desde > previa.desde ||
                (desde === previa.desde && parseInt(a.ID, 10) > previa.id)) {
                porConcepto[a.CONCEPTO] = {
                    desde: desde,
                    id: parseInt(a.ID, 10),
                    alicuota: parseFloat(a.ALICUOTA) || 0
                };
            }
        });

        var conceptos = Object.keys(porConcepto);
        var tasa = 0;

        conceptos.forEach(function(c) {
            tasa += porConcepto[c].alicuota;
        });

        return { tasa: tasa, conceptos: conceptos.length, detalle: porConcepto };
    }

    function textoTasa(tasa) {
        if (!tasa.conceptos) {
            return '<span class="pce-sin-alicuota" title="Sin alícuotas vigentes no se puede ' +
                   'calcular el neto de un movimiento">sin alícuotas</span>';
        }

        var detalle = Object.keys(tasa.detalle).map(function(c) {
            return escapar(c) + ' ' + porcentaje(tasa.detalle[c].alicuota);
        }).join(' + ');

        return '<span class="fw-semibold">' + porcentaje(tasa.tasa) + '</span>' +
               '<div class="pce-detalle">' + detalle + '</div>';
    }

    function guardarProcesadoras() {
        var porId = {};

        document.querySelectorAll('.pce-proc-nombre').forEach(function(i) {
            var id = parseInt(i.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].razon_social = i.value.trim();
        });

        document.querySelectorAll('.pce-proc-activo').forEach(function(c) {
            var id = parseInt(c.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].activo = c.checked;
        });

        var filas = Object.keys(porId).map(function(k) { return porId[k]; });

        if (!filas.length) {
            Notificacion.advertencia('No hay procesadoras para guardar.');
            return;
        }

        var vacias = filas.filter(function(f) { return !f.razon_social; });

        if (vacias.length) {
            Notificacion.advertencia('Hay ' + vacias.length + ' procesadora(s) sin razón social.', {
                detalle: 'Es lo que las identifica en la pantalla de carga.'
            });

            return;
        }

        conBoton('btnGuardarProcesadoras',
            pedirJson(URL_PARAM + '?action=saveProcesadorasCobel', { filas: filas }),
            'No se pudieron guardar las procesadoras');
    }

    function agregarProcesadora() {
        var razon = valor('nuevaRazonSocial');

        if (!razon) {
            Notificacion.campoInvalido('nuevaRazonSocial',
                'Ingresá la razón social de la procesadora.');
            return;
        }

        conBotonEl(document.getElementById('btnAgregarProcesadora'), function() {
            return pedirJson(URL_PARAM + '?action=addProcesadoraCobel', { razon_social: razon })
                .then(function() {
                    setValor('nuevaRazonSocial', '');
                    mostrar('formProcesadora', false);
                    mostrarResultado('Procesadora agregada, y queda INACTIVA: cargale una '
                        + 'alícuota en la sección de abajo y después activala. Sin alícuota '
                        + 'vigente sus movimientos no podrían calcular el importe neto.', null);
                    cargar();
                });
        }, 'No se pudo agregar la procesadora');
    }

    /* ================================================================
       ALÍCUOTAS
       ================================================================ */

    function pintarAlicuotas() {
        var alicuotas = modulo.alicuotas || [];
        var vigentes = {};

        // Cuál es la vigencia que rige hoy para cada (procesadora, concepto):
        // las demás son historia, y la pantalla lo dice en vez de esconderlas.
        (modulo.procesadoras || []).forEach(function(p) {
            var t = tasaVigente(p.ID);

            Object.keys(t.detalle).forEach(function(c) {
                vigentes[p.ID + '|' + c] = t.detalle[c].id;
            });
        });

        document.getElementById('bodyAlicuotas').innerHTML = alicuotas.length
            ? alicuotas.map(function(a) {
                  return filaAlicuota(a, vigentes[a.ID_PROCESADORA + '|' + a.CONCEPTO] ===
                                         parseInt(a.ID, 10));
              }).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">' +
              'Todavía no hay alícuotas cargadas. Sin alícuotas no se puede dar de alta ningún ' +
              'movimiento.</td></tr>';
    }

    function filaAlicuota(a, esVigente) {
        var activa = (parseInt(a.ACTIVO, 10) === 1);

        var estado = !activa
            ? '<span class="pce-badge pce-badge-baja">dada de baja</span>'
            : (esVigente
                ? '<span class="pce-badge pce-badge-vigente">vigente</span>'
                : '<span class="pce-badge" title="Quedó reemplazada por una vigencia posterior. ' +
                  'Se muestra porque es lo que explica la tasa de los movimientos de ese ' +
                  'período.">histórica</span>');

        return '<tr class="' + (activa ? '' : 'pce-inactiva') + '">' +
            '<td class="fw-semibold">' + escapar(a.RAZON_SOCIAL) + '</td>' +
            '<td>' + escapar(a.CONCEPTO) + '</td>' +
            '<td class="text-end pce-num">' + porcentaje(a.ALICUOTA) + '</td>' +
            '<td class="text-center">' + fechaCorta(a.VIGENCIA_DESDE) + '</td>' +
            '<td class="text-center">' + estado + '</td>' +
            '<td class="text-center">' +
                (activa
                    ? '<button class="btn btn-sm btn-link pce-nueva-vigencia" ' +
                          'data-proc="' + a.ID_PROCESADORA + '" ' +
                          'data-concepto="' + escapar(a.CONCEPTO) + '" ' +
                          'title="Cargar una vigencia nueva de este concepto">' +
                          '<i class="fas fa-pen"></i></button>' +
                      '<button class="btn btn-sm btn-link text-danger pce-baja-alicuota" ' +
                          'data-id="' + a.ID + '" ' +
                          'title="Dar de baja esta vigencia: no se borra, deja de regir">' +
                          '<i class="fas fa-ban"></i></button>'
                    : '') +
            '</td>' +
        '</tr>';
    }

    function pintarSelectores() {
        var procs = modulo.procesadoras || [];
        var sel = document.getElementById('nuevaAliProcesadora');

        // El alta de alícuotas ofrece TODAS, incluidas las inactivas: cargarle
        // la primera alícuota a una procesadora nueva es justamente el paso que
        // le permite activarse.
        if (sel) {
            sel.innerHTML = procs.length
                ? procs.map(function(p) {
                      return '<option value="' + p.ID + '">' + escapar(p.RAZON_SOCIAL) +
                             (parseInt(p.ACTIVO, 10) === 1 ? '' : ' (inactiva)') + '</option>';
                  }).join('')
                : '<option value="">No hay procesadoras cargadas</option>';
        }

        // Los conceptos ya usados, como sugerencia. El campo sigue siendo libre:
        // el concepto no es un enum cerrado y una retención nueva tiene que
        // poder cargarse sin tocar código.
        var conceptos = {};

        (modulo.alicuotas || []).forEach(function(a) {
            conceptos[a.CONCEPTO] = true;
        });

        var lista = document.getElementById('conceptosCobel');

        if (lista) {
            lista.innerHTML = Object.keys(conceptos).sort().map(function(c) {
                return '<option value="' + escapar(c) + '"></option>';
            }).join('');
        }

        if (!valor('nuevaAliVigencia')) {
            setValor('nuevaAliVigencia', fechaHoy());
        }

        document.querySelectorAll('.pce-nueva-vigencia').forEach(function(b) {
            b.addEventListener('click', function() {
                mostrar('formAlicuota', true);
                setValor('nuevaAliProcesadora', b.dataset.proc);
                setValor('nuevaAliConcepto', b.dataset.concepto);
                setValor('nuevaAliVigencia', fechaHoy());
                pintarSuma();
            });
        });

        document.querySelectorAll('.pce-baja-alicuota').forEach(function(b) {
            b.addEventListener('click', function() {
                bajaAlicuota(parseInt(b.dataset.id, 10), b);
            });
        });
    }

    /**
     * La suma que va a quedar vigente con la alícuota que se está cargando.
     *
     * Bloquea el guardado si llega a 100%: con esa suma el importe neto saldría
     * cero o negativo, o sea que una cobranza restaría plata del tablero. El
     * servidor lo valida igual —es donde vale—, pero decirlo antes evita tipear
     * todo para que lo rechacen.
     */
    function pintarSuma() {
        var cont = document.getElementById('sumaAlicuota');
        var btn = document.getElementById('btnAgregarAlicuota');

        if (!cont || !modulo) {
            return;
        }

        var idProc = valor('nuevaAliProcesadora');
        var concepto = valor('nuevaAliConcepto');
        var nueva = (parseFloat(valor('nuevaAliPorcentaje')) || 0) / 100;

        if (!idProc || !concepto) {
            cont.innerHTML = '<span class="text-muted">Elegí la procesadora y el concepto para ' +
                'ver la tasa total que va a quedar vigente.</span>';

            if (btn) {
                btn.disabled = false;
            }

            return;
        }

        var t = tasaVigente(idProc);
        var suma = nueva;
        var partes = [escapar(concepto) + ' ' + porcentaje(nueva) + ' (nueva)'];

        Object.keys(t.detalle).forEach(function(c) {
            if (c === concepto) {
                return;
            }

            suma += t.detalle[c].alicuota;
            partes.push(escapar(c) + ' ' + porcentaje(t.detalle[c].alicuota));
        });

        var invalida = (suma >= 1);

        cont.innerHTML = '<i class="fas fa-calculator me-1"></i>' +
            'Tasa total resultante <strong class="' + (invalida ? 'text-danger' : '') + '">' +
            porcentaje(suma) + '</strong> (' + partes.join(' + ') + ')' +
            (invalida
                ? '<div class="text-danger mt-1">Con esta suma el importe neto saldría cero o ' +
                  'negativo: una cobranza restaría plata del tablero. Revisá los otros conceptos ' +
                  'antes de guardar este.</div>'
                : '<div class="text-muted mt-1">Un movimiento nuevo de esta procesadora va a ' +
                  'acreditar el ' + porcentaje(1 - suma) + ' de su importe bruto.</div>');

        if (btn) {
            btn.disabled = invalida;
        }
    }

    function agregarAlicuota() {
        var idProc = valor('nuevaAliProcesadora');
        var concepto = valor('nuevaAliConcepto');
        var porc = parseFloat(valor('nuevaAliPorcentaje'));
        var vigencia = valor('nuevaAliVigencia');

        // Cada validación marca SU campo: el formulario tiene cuatro y un
        // mensaje suelto obliga a adivinar cuál es el que falta.
        if (!idProc) {
            Notificacion.campoInvalido('nuevaAliProcesadora', 'Elegí una procesadora.');
            return;
        }

        if (!concepto) {
            Notificacion.campoInvalido('nuevaAliConcepto',
                'Ingresá el concepto de la retención (por ejemplo IIBB o SICREB).');
            return;
        }

        if (isNaN(porc) || porc < 0) {
            Notificacion.campoInvalido('nuevaAliPorcentaje',
                'La alícuota tiene que ser un número no negativo.');
            return;
        }

        if (!vigencia) {
            Notificacion.campoInvalido('nuevaAliVigencia',
                'Ingresá desde qué fecha rige esta alícuota.', {
                detalle: 'Es lo que permite cambiarla sin reescribir el neto de lo ya informado.'
            });
            return;
        }

        conBotonEl(document.getElementById('btnAgregarAlicuota'), function() {
            // La alícuota viaja como fracción, que es como la guarda la base:
            // 2,5% es 0,025. La conversión se hace en un solo lugar.
            return pedirJson(URL_PARAM + '?action=addAlicuotaCobel', {
                id_procesadora: parseInt(idProc, 10),
                concepto: concepto,
                alicuota: porc / 100,
                vigencia_desde: vigencia
            }).then(function(r) {
                mostrar('formAlicuota', false);
                setValor('nuevaAliPorcentaje', '');
                mostrarResultado('Alícuota guardada como vigencia nueva: la anterior queda en el '
                    + 'histórico y los movimientos que se calcularon con ella la conservan.', r);
                cargar();
            });
        }, 'No se pudo guardar la alícuota');
    }

    function bajaAlicuota(id, boton) {
        // La confirmación es asíncrona: no bloquea el hilo como confirm(), así
        // que lo que venía después se mueve adentro del then.
        Notificacion.confirmar({
            titulo: 'Dar de baja la vigencia',
            mensaje: '¿Dar de baja esta alícuota?',
            detalle: 'No se borra: deja de regir. Los movimientos ya acreditados conservan la '
                   + 'tasa con la que se calcularon, y los pendientes se recalculan con lo que '
                   + 'quede vigente.',
            confirmar: 'Dar de baja',
            peligro: true
        }).then(function(confirmado) {
            if (!confirmado) {
                return;
            }

            conBotonEl(boton, function() {
                return pedirJson(URL_PARAM + '?action=bajaAlicuotaCobel', { id: id })
                    .then(function(r) {
                        mostrarResultado('Alícuota dada de baja: deja de regir, pero la fila queda '
                            + 'en el histórico.', r);
                        cargar();
                    });
            }, 'No se pudo dar de baja la alícuota');
        });
    }

    /**
     * Muestra qué dejó el guardado, con el detalle del recálculo automático.
     *
     * Se informa SIEMPRE, incluso cuando no cambió nada: un recálculo que no se
     * informa es un cambio de importes en silencio, y era la mitad del problema
     * del Excel corriendo al revés.
     *
     * Va en un panel de la pantalla y no en un alert porque lo que hay que
     * mostrar es una TABLA —qué movimiento, con qué tasa antes y después, con
     * qué diferencia de neto— y una tabla en un alert no se puede leer ni
     * comparar. Además queda a la vista mientras se sigue trabajando, que es
     * cuando sirve.
     *
     * @param {string} titulo Qué se guardó
     * @param {Object} r Respuesta del servidor
     */
    function mostrarResultado(titulo, r) {
        var cont = document.getElementById('resultadoCobel');

        if (!cont) {
            return;
        }

        var rec = (r && r.recalculo) || {};
        var cambios = rec.cambios || [];
        var html = '';

        html += '<div class="pce-resultado">';
        html += '<div class="pce-resultado-titulo">' +
            '<i class="fas fa-circle-check me-1"></i>' + escapar(titulo) + '</div>';

        // Sin respuesta que detallar -un alta de procesadora, que todavia no
        // tiene movimientos- alcanza con el titulo. Decir "no cambio ningun
        // pendiente" ahi seria contestar una pregunta que nadie hizo.
        if (!r) {
            cont.innerHTML = html + '</div>';
            cont.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            return;
        }

        if (r.tasa_total !== undefined && r.tasa_total !== null) {
            html += '<div class="pce-resultado-linea">La tasa total vigente de esa procesadora ' +
                'es ahora del <strong>' + porcentaje(r.tasa_total) + '</strong>, así que una ' +
                'acreditación nueva va a acreditar el <strong>' +
                porcentaje(1 - parseFloat(r.tasa_total)) + '</strong> de su importe bruto.</div>';
        }

        if (!cambios.length) {
            html += '<div class="pce-resultado-linea">No cambió el importe neto de ningún ' +
                'movimiento pendiente.' +
                (rec.acreditados
                    ? (' Los ' + rec.acreditados + ' ya acreditados no se tocan: conservan la ' +
                       'tasa con la que se calcularon.')
                    : '') +
                '</div>';
        } else {
            html += '<div class="pce-resultado-linea">Se recalcularon <strong>' +
                cambios.length + '</strong> movimiento(s) pendiente(s), con una diferencia total ' +
                'de <strong>' + signo(rec.diferencia) + '</strong> en el neto.' +
                (rec.acreditados
                    ? (' Los ' + rec.acreditados + ' ya acreditados <strong>no se tocaron</strong>.')
                    : '') +
                '</div>';

            html += '<div class="table-responsive pce-resultado-tabla">' +
                '<table class="table table-sm mb-0"><thead><tr>' +
                '<th>Procesadora</th><th class="text-center">Acreditación</th>' +
                '<th class="text-end">Bruto</th><th class="text-center">Tasa</th>' +
                '<th class="text-end">Neto</th><th class="text-end">Diferencia</th>' +
                '</tr></thead><tbody>';

            cambios.forEach(function(c) {
                var baja = (parseFloat(c.diferencia) || 0) < 0;

                html += '<tr>' +
                    '<td>' + escapar(c.procesadora) + '</td>' +
                    '<td class="text-center">' + fechaCorta(c.fecha_acreditacion) + '</td>' +
                    '<td class="text-end pce-num">' + pesos(c.importe_bruto) + '</td>' +
                    '<td class="text-center pce-num">' + porcentaje(c.tasa_anterior) +
                        ' <i class="fas fa-arrow-right pce-flecha"></i> ' +
                        porcentaje(c.tasa_nueva) + '</td>' +
                    '<td class="text-end pce-num">' + pesos(c.neto_anterior) +
                        ' <i class="fas fa-arrow-right pce-flecha"></i> <strong>' +
                        pesos(c.neto_nuevo) + '</strong></td>' +
                    '<td class="text-end pce-num ' + (baja ? 'text-danger' : 'text-success') +
                        '">' + signo(c.diferencia) + '</td>' +
                '</tr>';
            });

            html += '</tbody></table></div>';
        }

        (rec.avisos || []).forEach(function(a) {
            html += '<div class="pce-resultado-aviso">· ' + escapar(a) + '</div>';
        });

        html += '</div>';

        cont.innerHTML = html;
        cont.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /** '+ $ 1.000,00' / '− $ 1.000,00', para una diferencia */
    function signo(valor) {
        var n = parseFloat(valor) || 0;

        return (n >= 0 ? '+ ' : '− ') + pesos(Math.abs(n));
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /** Muestra el estado del guardado en el botón y recarga si salió bien */
    function conBoton(id, promesa, mensajeError) {
        var btn = document.getElementById(id);

        if (!btn) {
            return;
        }

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

    /** Corre una acción mostrando el estado en un botón que no tiene id */
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

    /** Hoy en 'YYYY-MM-DD', en hora local y no en UTC */
    function fechaHoy() {
        var d = new Date();
        var mes = String(d.getMonth() + 1).padStart(2, '0');
        var dia = String(d.getDate()).padStart(2, '0');

        return d.getFullYear() + '-' + mes + '-' + dia;
    }

    function pesos(valor) {
        return '$ ' + (parseFloat(valor) || 0).toLocaleString('es-AR', {
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

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fechaCorta(valor) {
        if (!valor) {
            return '—';
        }

        var p = String(valor).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(valor);
    }

    function fechaHora(valor) {
        var s = String(valor);

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
