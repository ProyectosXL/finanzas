/**
 * Cob. Electrónicos JavaScript
 *
 * Acreditaciones de las procesadoras de pago: alta inline, edición, baja lógica,
 * filtros y el cuadro por día y por mes que consume el tablero.
 *
 * LA TASA Y EL NETO NO SE EDITAN NI VIAJAN AL SERVIDOR. Del formulario salen la
 * procesadora, el importe bruto, la fecha y las observaciones. La vista previa
 * del neto que se dibuja al tipear es sólo eso: el número que vale es el que
 * devuelve el guardado, calculado por CobElectronicos::importeNeto() con la
 * alícuota vigente a la fecha de acreditación.
 *
 * Un movimiento POSTERIOR al horizonte se ve igual, en su fila, marcado y con el
 * motivo: es plata informada que el tablero todavía no muestra, y esconderla
 * haría que el total de la pantalla y el del tablero no cerraran sin
 * explicación.
 *
 * Las acreditaciones YA OCURRIDAS, en cambio, no se muestran por defecto: ya
 * pasaron, ya entraron a la cuenta y las informa el saldo bancario. Se traen con
 * el switch, y ahí se marcan de forma neutra —no son un problema—.
 *
 * El importador va en dos pasos: se sube el archivo, se muestran las
 * diferencias contra lo cargado y recién después se escribe. Es lo que permite
 * actualizar seguido sin comparar fila por fila.
 */

(function() {
    'use strict';

    var URL_COBEL = 'Controller/CobElectronicosController.php';

    var datos = null;

    // Id del movimiento que se está editando en la grilla, o null
    var editando = null;

    // Lo último que devolvió la previsualización del importador. Se guarda para
    // poder confirmarlo, pero el servidor vuelve a calcular el diff igual: esto
    // es lo que se está mostrando, no lo que se va a escribir.
    var importacion = null;

    function inicializar() {
        conectar('btnRefreshCobel', function() { cargar(); });
        conectar('btnNuevoMovimiento', function() { modoAlta(true); });
        conectar('btnCancelarMovimiento', function() { modoAlta(false); });
        conectar('btnAgregarMovimiento', agregarMovimiento);
        conectar('btnFiltrar', function() { cargar(); });
        conectar('btnLimpiarFiltro', limpiarFiltro);

        conectar('btnImportar', function() { panelImportar(true); });
        conectar('btnCerrarImportar', function() { panelImportar(false); });
        conectar('btnPrevisualizar', previsualizar);

        var verAcreditadas = document.getElementById('verAcreditadas');

        if (verAcreditadas) {
            verAcreditadas.addEventListener('change', function() { cargar(); });
        }

        ['nuevoProcesadora', 'nuevoBruto', 'nuevoFecha'].forEach(function(id) {
            var el = document.getElementById(id);

            if (el) {
                el.addEventListener('input', pintarPreview);
                el.addEventListener('change', pintarPreview);
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
        mostrar('loadingCobel', true, 'flex');
        mostrar('wrapperCobel', false);

        pedirJson(URL_COBEL + '?action=getPestana' + queryFiltros())
            .then(function(data) {
                datos = data;
                editando = null;

                pintarAvisos();
                pintarKpi();
                pintarProcesadoras();
                pintarTabla();
                pintarPorProcesadora();
                pintarCuadros();
                pintarPreview();

                mostrar('loadingCobel', false);
                mostrar('wrapperCobel', true);
            })
            .catch(function(error) {
                mostrar('loadingCobel', false);
                avisar('No se pudieron cargar las acreditaciones: ' + error.message);
            });
    }

    function queryFiltros() {
        var proc = valor('filtroProcesadora');
        var desde = valor('filtroDesde');
        var hasta = valor('filtroHasta');
        var verAcreditadas = document.getElementById('verAcreditadas');

        var partes = [];

        if (proc && proc !== '0') {
            partes.push('id_procesadora=' + encodeURIComponent(proc));
        }

        if (desde) {
            partes.push('desde=' + encodeURIComponent(desde));
        }

        if (hasta) {
            partes.push('hasta=' + encodeURIComponent(hasta));
        }

        if (verAcreditadas && verAcreditadas.checked) {
            partes.push('incluir_acreditadas=1');
        }

        return partes.length ? ('&' + partes.join('&')) : '';
    }

    function limpiarFiltro() {
        setValor('filtroProcesadora', '0');
        setValor('filtroDesde', '');
        setValor('filtroHasta', '');

        var verAcreditadas = document.getElementById('verAcreditadas');

        if (verAcreditadas) {
            verAcreditadas.checked = false;
        }

        cargar();
    }

    /* ================================================================
       INDICADORES
       ================================================================ */

    function pintarKpi() {
        var t = datos.totales || {};

        // Estado vacío honesto: 'sin cargar' y no '$ 0,00'. Un cero se leería
        // como "no hay acreditaciones previstas", que no es lo mismo que "no se
        // cargó ninguna".
        var hay = (t.movimientos > 0);

        texto('cobelTotalBruto', hay ? pesos(t.bruto) : 'sin cargar');
        texto('cobelTotalRetenido', hay ? pesos(t.retenido) : 'sin cargar');
        texto('cobelTotalNeto', hay ? pesos(t.neto) : 'sin cargar');

        texto('cobelDetalleBruto', hay
            ? (t.movimientos + ' movimiento(s) informados')
            : 'Todavía no hay movimientos cargados');

        texto('cobelDetalleNeto', hay
            ? ('Es lo que el tablero consume · retención ' + tasaMedia(t))
            : 'Lo que entra a la cuenta');

        // Sólo lo POSTERIOR al eje. Lo ya acreditado no cuenta acá: no le falta
        // al tablero, lo informa el saldo bancario.
        texto('cobelFueraEje', (t.fuera_eje_movimientos > 0) ? pesos(t.fuera_eje) : '—');
        texto('cobelDetalleFuera', (t.fuera_eje_movimientos > 0)
            ? (t.fuera_eje_movimientos + ' movimiento(s) más allá del eje del tablero')
            : 'Todo lo pendiente entra al horizonte');

        var eje = datos.eje || {};

        texto('cobelEje', eje.desde
            ? ('Horizonte: ' + fecha(eje.desde) + ' a ' + fecha(eje.hasta))
            : '');
        texto('cobelDesde', eje.desde ? fecha(eje.desde) : 'hoy');
    }

    /** La retención efectiva del conjunto, para el pie de la tarjeta del neto */
    function tasaMedia(t) {
        if (!t.bruto) {
            return '0%';
        }

        return porcentaje(t.retenido / t.bruto);
    }

    /* ================================================================
       PROCESADORAS
       ================================================================ */

    function pintarProcesadoras() {
        var procs = datos.procesadoras || [];

        // En el alta sólo se ofrecen las activas CON alícuota vigente: son las
        // únicas que pueden calcular un neto, y ofrecer las otras llevaría a un
        // rechazo del servidor después de tipear todo el movimiento.
        var cargables = procs.filter(function(p) {
            return p.activo === 1 && p.conceptos > 0;
        });

        var sel = document.getElementById('nuevoProcesadora');

        if (sel) {
            sel.innerHTML = cargables.length
                ? cargables.map(function(p) {
                      return '<option value="' + p.id + '" data-tasa="' + p.tasa + '">' +
                             escapar(p.razon_social) + ' — retención ' + porcentaje(p.tasa) +
                             '</option>';
                  }).join('')
                : '<option value="">No hay procesadoras habilitadas</option>';
        }

        var btn = document.getElementById('btnNuevoMovimiento');

        if (btn) {
            btn.disabled = !cargables.length;
            btn.title = cargables.length
                ? ''
                : 'No hay ninguna procesadora activa con alícuotas vigentes. Cargalas en ' +
                  'Parámetros → Cob. Electrónicos.';
        }

        // El filtro sí ofrece todas: puede haber movimientos de una procesadora
        // que después se inhabilitó, y no poder filtrarlos los esconde.
        var filtro = document.getElementById('filtroProcesadora');

        if (filtro) {
            var actual = filtro.value;

            filtro.innerHTML = '<option value="0">Todas</option>' +
                procs.map(function(p) {
                    return '<option value="' + p.id + '">' + escapar(p.razon_social) +
                           (p.activo === 1 ? '' : ' (inhabilitada)') + '</option>';
                }).join('');

            filtro.value = actual || String((datos.filtros || {}).id_procesadora || 0);
        }
    }

    /* ================================================================
       TABLA DE MOVIMIENTOS
       ================================================================ */

    function pintarTabla() {
        var html = '';

        (datos.filas || []).forEach(function(f) {
            html += (editando === f.id) ? filaEdicion(f) : filaLectura(f);
        });

        if (!(datos.filas || []).length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                   'No hay acreditaciones cargadas' +
                   (tieneFiltro() ? ' con este filtro.' : ' todavía.') + '</td></tr>';
        }

        document.getElementById('bodyCobel').innerHTML = html;

        var t = datos.totales || {};

        document.getElementById('footCobel').innerHTML = (t.movimientos > 0)
            ? '<tr class="cobel-fila-total">' +
                  '<td class="fw-bold">TOTALES</td>' +
                  '<td class="text-end fw-bold">' + pesos(t.bruto) + '</td>' +
                  '<td class="text-center text-muted">retención ' + tasaMedia(t) + '</td>' +
                  '<td class="text-center text-muted">' + pesos(t.retenido) + '</td>' +
                  '<td class="text-end fw-bold">' + pesos(t.neto) + '</td>' +
                  '<td colspan="2"></td>' +
              '</tr>'
            : '';

        conectarFilas();
    }

    function filaLectura(f) {
        // Dos marcas distintas y no una: una acreditación posterior al eje es
        // algo que hay que mirar (el tablero no la muestra todavía), y una ya
        // acreditada no es un problema, ya pasó.
        var clase = (f.ubicacion_eje === 'POSTERIOR') ? ' class="cobel-fuera-eje"'
            : ((f.ubicacion_eje === 'ANTERIOR') ? ' class="cobel-acreditada"' : '');

        return '<tr data-mov="' + f.id + '"' + clase + '>' +
            '<td class="fw-semibold">' + escapar(f.procesadora) + subtitulo(f) + '</td>' +
            '<td class="text-end cobel-num">' + pesos(f.importe_bruto) + '</td>' +
            '<td class="text-center">' + fecha(f.fecha_acreditacion) + marcaEje(f) + '</td>' +
            // La tasa y el neto son del servidor: se muestran, no se editan.
            '<td class="text-center cobel-num cobel-calculado" ' +
                'title="Tasa con la que se calculó este movimiento. Queda guardada: editar la ' +
                'alícuota no reescribe lo ya informado.">' + porcentaje(f.tasa_aplicada) + '</td>' +
            '<td class="text-end cobel-num cobel-neto">' + pesos(f.importe_neto) + '</td>' +
            '<td class="text-center"><span class="cobel-origen">' +
                etiquetaOrigen(f.origen_dato) + '</span></td>' +
            '<td class="text-center">' +
                '<button class="btn btn-sm btn-link cobel-editar" data-mov="' + f.id + '" ' +
                    'title="Editar el importe bruto o la fecha"><i class="fas fa-pen"></i></button>' +
                '<button class="btn btn-sm btn-link text-danger cobel-baja" data-mov="' + f.id + '" ' +
                    'title="Dar de baja: no se borra, queda inhabilitado y sale del tablero">' +
                    '<i class="fas fa-ban"></i></button>' +
            '</td>' +
        '</tr>';
    }

    /**
     * Fila en edición: sólo el bruto, la fecha y las observaciones.
     *
     * La tasa y el neto quedan como texto atenuado con la leyenda de que los
     * recalcula el servidor: convertirlos en inputs sería ofrecer editar un
     * número que el servidor va a descartar.
     */
    function filaEdicion(f) {
        return '<tr data-mov="' + f.id + '" class="cobel-editando">' +
            '<td class="fw-semibold">' + escapar(f.procesadora) +
                '<div class="cobel-subtitulo">La procesadora no se cambia: si está mal, dá de ' +
                'baja el movimiento y cargalo de nuevo.</div></td>' +
            '<td>' +
                '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm ' +
                    'text-end cobel-input-bruto" value="' + f.importe_bruto + '">' +
            '</td>' +
            '<td>' +
                '<input type="date" class="form-control form-control-sm cobel-input-fecha" ' +
                    'value="' + (f.fecha_acreditacion || '') + '">' +
            '</td>' +
            '<td class="text-center text-muted cobel-num" colspan="2">' +
                'la tasa y el neto se recalculan al guardar' +
            '</td>' +
            '<td>' +
                '<input type="text" class="form-control form-control-sm cobel-input-obs" ' +
                    'maxlength="200" placeholder="Observaciones" value="' +
                    escapar(f.observaciones || '') + '">' +
            '</td>' +
            '<td class="text-center">' +
                '<button class="btn btn-sm btn-link text-success cobel-guardar" data-mov="' +
                    f.id + '" title="Guardar"><i class="fas fa-check"></i></button>' +
                '<button class="btn btn-sm btn-link cobel-cancelar" title="Cancelar">' +
                    '<i class="fas fa-xmark"></i></button>' +
            '</td>' +
        '</tr>';
    }

    /** Lo que hay que saber de la fila y no entra en una columna */
    function subtitulo(f) {
        var partes = [];

        if (f.observaciones) {
            partes.push(escapar(f.observaciones));
        }

        if (f.id_externo) {
            partes.push('Liquidación ' + escapar(f.id_externo));
        }

        if (f.archivo_origen) {
            partes.push('Archivo ' + escapar(f.archivo_origen));
        }

        return partes.length ? '<div class="cobel-subtitulo">' + partes.join(' · ') + '</div>' : '';
    }

    /**
     * La marca de por qué un movimiento no entra al tablero.
     *
     * Se dice el motivo y no sólo "fuera del horizonte", y las dos marcas son
     * distintas a propósito: una fecha pasada no es un error de carga ni algo
     * que haya que resolver —es plata que ya entró y que el saldo bancario ya
     * informa—, mientras que una fecha posterior al eje sí es plata que el
     * tablero todavía no puede mostrar.
     */
    function marcaEje(f) {
        if (f.ubicacion_eje === 'ANTERIOR') {
            return '<div class="cobel-marca-neutra" title="Ya se acreditó: esa plata está en la ' +
                   'cuenta y la informa el saldo bancario de la pestaña Saldos. Sumarla acá la ' +
                   'contaría dos veces.">ya acreditada</div>';
        }

        if (f.ubicacion_eje === 'POSTERIOR') {
            return '<div class="cobel-marca" title="El horizonte del tablero no llega a esta ' +
                   'fecha, así que no hay columna donde mostrarla.">' +
                   'posterior al horizonte</div>';
        }

        return '';
    }

    function conectarFilas() {
        document.querySelectorAll('.cobel-editar').forEach(function(b) {
            b.addEventListener('click', function() {
                editando = parseInt(b.dataset.mov, 10);
                pintarTabla();
            });
        });

        document.querySelectorAll('.cobel-cancelar').forEach(function(b) {
            b.addEventListener('click', function() {
                editando = null;
                pintarTabla();
            });
        });

        document.querySelectorAll('.cobel-guardar').forEach(function(b) {
            b.addEventListener('click', function() {
                guardarMovimiento(parseInt(b.dataset.mov, 10), b);
            });
        });

        document.querySelectorAll('.cobel-baja').forEach(function(b) {
            b.addEventListener('click', function() {
                bajaMovimiento(parseInt(b.dataset.mov, 10), b);
            });
        });
    }

    /* ================================================================
       ALTA, EDICIÓN Y BAJA
       ================================================================ */

    function modoAlta(activo) {
        mostrar('formMovimiento', activo);

        if (!activo) {
            return;
        }

        setValor('nuevoBruto', '');
        setValor('nuevoObservaciones', '');

        if (!valor('nuevoFecha')) {
            setValor('nuevoFecha', (datos && datos.hoy) ? datos.hoy : '');
        }

        pintarPreview();
    }

    /**
     * Vista previa del neto que va a quedar guardado.
     *
     * Espeja la fórmula del servidor (neto = bruto × (1 − tasa)) para que el
     * número se mueva mientras se tipea, y lo dice explícitamente: el cálculo
     * que vale es el del servidor, con la alícuota vigente a la fecha elegida.
     * Acá se usa la tasa vigente a HOY, que es la que trae el payload; si la
     * fecha de acreditación cae en otra vigencia, el servidor va a usar esa otra
     * y el guardado lo informa.
     */
    function pintarPreview() {
        var cont = document.getElementById('previewNeto');

        if (!cont || !datos) {
            return;
        }

        var sel = document.getElementById('nuevoProcesadora');
        var bruto = parseFloat(valor('nuevoBruto')) || 0;
        var tasa = (sel && sel.selectedOptions.length)
            ? (parseFloat(sel.selectedOptions[0].dataset.tasa) || 0)
            : 0;

        if (bruto <= 0) {
            cont.innerHTML = '<span class="text-muted">El importe neto lo calcula el servidor: ' +
                'bruto × (1 − tasa de retención vigente). No se tipea.</span>';
            return;
        }

        var neto = bruto * (1 - tasa);

        cont.innerHTML = '<i class="fas fa-calculator me-1"></i>' +
            'Neto estimado <strong>' + pesos(neto) + '</strong> ' +
            '(bruto ' + pesos(bruto) + ' − retención ' + porcentaje(tasa) + ' = ' +
            pesos(bruto - neto) + '). ' +
            '<span class="text-muted">El definitivo lo calcula el servidor con la alícuota ' +
            'vigente a la fecha de acreditación.</span>';
    }

    function agregarMovimiento() {
        var idProc = valor('nuevoProcesadora');
        var bruto = parseFloat(valor('nuevoBruto')) || 0;
        var fecha = valor('nuevoFecha');

        if (!idProc) {
            avisar('Elegí una procesadora.');
            return;
        }

        if (bruto <= 0) {
            avisar('El importe bruto tiene que ser mayor a cero.');
            return;
        }

        if (!fecha) {
            avisar('La fecha de acreditación es obligatoria: sin ella el movimiento no se puede ' +
                   'ubicar en el tablero.');
            return;
        }

        conBoton('btnAgregarMovimiento', function() {
            // Del cliente salen sólo los datos de entrada. La tasa y el neto los
            // resuelve el servidor.
            return pedirJson(URL_COBEL + '?action=addMovimiento', {
                id_procesadora: parseInt(idProc, 10),
                importe_bruto: bruto,
                fecha_acreditacion: fecha,
                observaciones: valor('nuevoObservaciones')
            }).then(function(r) {
                mostrar('formMovimiento', false);
                notificar('Movimiento guardado. Neto ' + pesos(r.neto) +
                          ', con retención ' + porcentaje(r.tasa) + '.');
                cargar();
            });
        }, 'No se pudo guardar el movimiento');
    }

    function guardarMovimiento(id, boton) {
        var fila = document.querySelector('tr[data-mov="' + id + '"]');

        if (!fila) {
            return;
        }

        var bruto = parseFloat(fila.querySelector('.cobel-input-bruto').value) || 0;
        var fecha = fila.querySelector('.cobel-input-fecha').value;
        var obs = fila.querySelector('.cobel-input-obs').value;

        if (bruto <= 0) {
            avisar('El importe bruto tiene que ser mayor a cero.');
            return;
        }

        if (!fecha) {
            avisar('La fecha de acreditación es obligatoria.');
            return;
        }

        conBotonEl(boton, function() {
            return pedirJson(URL_COBEL + '?action=saveMovimiento', {
                id: id,
                importe_bruto: bruto,
                fecha_acreditacion: fecha,
                observaciones: obs
            }).then(function(r) {
                notificar('Movimiento actualizado. Neto ' + pesos(r.neto) +
                          ', recalculado con retención ' + porcentaje(r.tasa) + '.');
                cargar();
            });
        }, 'No se pudo guardar el movimiento');
    }

    function bajaMovimiento(id, boton) {
        var fila = (datos.filas || []).filter(function(f) { return f.id === id; })[0];

        if (!fila) {
            return;
        }

        if (!confirm('¿Dar de baja el movimiento de ' + fila.procesadora + ' del ' +
                     fecha(fila.fecha_acreditacion) + ' por ' + pesos(fila.importe_bruto) +
                     ' brutos?\n\nNo se borra: queda inhabilitado y sale del tablero.')) {
            return;
        }

        conBotonEl(boton, function() {
            return pedirJson(URL_COBEL + '?action=bajaMovimiento', { id: id })
                .then(function() {
                    notificar('Movimiento dado de baja.');
                    cargar();
                });
        }, 'No se pudo dar de baja el movimiento');
    }

    /* ================================================================
       IMPORTADOR

       Dos pasos: previsualizar (no escribe nada) y confirmar. El diff que se
       muestra lo calcula el servidor, y lo vuelve a calcular al confirmar
       contra el estado real de la base: acá no se decide nada.
       ================================================================ */

    function panelImportar(abrir) {
        mostrar('panelImportar', abrir);

        if (!abrir) {
            importacion = null;
            document.getElementById('resultadoImportar').innerHTML = '';
        }
    }

    function previsualizar() {
        var input = document.getElementById('archivoImportar');

        if (!input || !input.files || !input.files.length) {
            avisar('Elegí el archivo .csv que querés importar.');
            return;
        }

        var datosForm = new FormData();

        datosForm.append('archivo', input.files[0]);

        // El período que cubre el archivo es opcional: sólo amplía la ventana en
        // la que se pueden proponer bajas. Ver la nota de la pestaña.
        datosForm.append('periodo_desde', valor('periodoDesde'));
        datosForm.append('periodo_hasta', valor('periodoHasta'));

        conBoton('btnPrevisualizar', function() {
            return subirArchivo(URL_COBEL + '?action=previsualizarImportacion', datosForm)
                .then(function(diff) {
                    importacion = diff;
                    pintarDiff(diff);
                })
                .catch(function(error) {
                    // El error se muestra EN EL PANEL y no en un alert: los
                    // mensajes del parser dicen la línea y qué corregir, y en un
                    // alert no se pueden leer con el archivo al lado.
                    importacion = null;
                    document.getElementById('resultadoImportar').innerHTML =
                        '<div class="alert alert-danger py-2 px-3 mt-3 mb-0"><small>' +
                        '<i class="fas fa-circle-exclamation me-1"></i>' +
                        escapar(error.message) + '</small></div>';
                });
        }, 'No se pudo leer el archivo');
    }

    /**
     * Dibuja el diff: primero el resumen, después sólo las filas que cambian.
     *
     * Las que no cambian y las ya acreditadas van como número y no como lista:
     * son la mayoría de un archivo, y listarlas taparía las tres que importan.
     */
    function pintarDiff(diff) {
        var r = diff.resumen || {};
        var cont = document.getElementById('resultadoImportar');
        var html = '';

        html += '<div class="cobel-diff mt-3">';

        var ventana = diff.ventana || {};

        html += '<div class="cobel-diff-titulo">' +
            '<i class="fas fa-code-compare me-1"></i>' +
            escapar(diff.archivo || 'archivo') + ' · ' + (r.filas || 0) + ' fila(s) leídas' +
            (diff.rango && diff.rango.desde
                ? (' · acreditaciones del ' + fecha(diff.rango.desde) + ' al ' +
                   fecha(diff.rango.hasta))
                : '') +
            '</div>';

        // Qué ventana se usó para buscar bajas, y si la declaró el usuario o se
        // infirió. Es lo que explica por qué una baja aparece o no aparece.
        if (ventana.desde) {
            html += '<div class="cobel-diff-aviso">Se buscaron bajas entre el ' +
                fecha(ventana.desde) + ' y el ' + fecha(ventana.hasta) +
                (ventana.declarada
                    ? ' (el período que declaraste).'
                    : ' (inferido de las fechas del archivo; si la procesadora dio de baja la ' +
                      'primera o la última acreditación del período, completá desde/hasta para ' +
                      'poder verla).') +
                '</div>';
        }

        html += '<div class="cobel-chips">' +
            chip('altas', r.altas, 'nuevas', 'ok') +
            chip('cambios', r.cambios, 'con cambios', 'aviso') +
            chip('sin_cambios', r.sin_cambios, 'ya cargadas igual', 'neutro') +
            chip('ya_acreditadas', r.ya_acreditadas, 'ya acreditadas', 'neutro') +
            chip('bajas', r.bajas, 'ya no vienen', 'aviso') +
            chip('errores', r.errores, 'con problemas', 'error') +
            '</div>';

        if (r.altas > 0 || r.cambios > 0) {
            html += '<div class="cobel-diff-totales">' +
                'Neto que se agrega: <strong>' + pesos(r.neto_altas) + '</strong>' +
                (r.cambios > 0
                    ? (' · diferencia de neto en los cambios: <strong>' +
                       pesos(r.neto_diferencia) + '</strong>')
                    : '') +
                '</div>';
        }

        (diff.avisos || []).forEach(function(a) {
            html += '<div class="cobel-diff-aviso">· ' + escapar(a) + '</div>';
        });

        html += tablaDiff(diff);
        html += tablaBajas(diff);

        // El botón se habilita con lo que dijo el servidor, no con una cuenta
        // hecha acá: es el servidor el que valida y el que va a rechazar.
        var puede = !!diff.puede_importar;

        html += '<div class="d-flex align-items-center gap-3 mt-3 flex-wrap">';

        if ((r.bajas || 0) > 0) {
            html += '<div class="form-check">' +
                '<input class="form-check-input" type="checkbox" id="aplicarBajas">' +
                '<label class="form-check-label" for="aplicarBajas">' +
                'Dar de baja los ' + r.bajas + ' que el archivo no trae' +
                '</label></div>';
        }

        html += '<button id="btnConfirmarImportar" class="btn btn-sm btn-success"' +
            (puede ? '' : ' disabled') + '>' +
            '<i class="fas fa-check me-1"></i> Confirmar importación</button>';

        if (!puede) {
            html += '<span class="text-muted"><small>' +
                ((r.errores > 0)
                    ? 'Corregí las filas con problemas y volvé a subir el archivo.'
                    : 'No hay nada que aplicar.') +
                '</small></span>';
        }

        html += '</div></div>';

        cont.innerHTML = html;

        conectar('btnConfirmarImportar', confirmarImportacion);
    }

    function chip(clave, cantidad, rotulo, tono) {
        if (!cantidad) {
            return '';
        }

        return '<span class="cobel-chip cobel-chip-' + tono + '">' +
               '<strong>' + cantidad + '</strong> ' + rotulo + '</span>';
    }

    /** Sólo las filas que cambian algo o que están mal */
    function tablaDiff(diff) {
        var filas = (diff.filas || []).filter(function(f) {
            return f.estado === 'ALTA' || f.estado === 'CAMBIO' || f.estado === 'ERROR';
        });

        if (!filas.length) {
            return '';
        }

        var html = '<div class="table-responsive cobel-diff-tabla">' +
            '<table class="table table-sm mb-0"><thead><tr>' +
            '<th style="width: 60px;">Línea</th><th>Procesadora</th>' +
            '<th class="text-end">Importe bruto</th><th class="text-center">Acreditación</th>' +
            '<th class="text-end">Neto</th><th>Qué pasa</th>' +
            '</tr></thead><tbody>';

        filas.forEach(function(f) {
            var clase = (f.estado === 'ERROR') ? 'cobel-fila-error'
                : (f.estado === 'CAMBIO' ? 'cobel-fila-cambio' : 'cobel-fila-alta');

            html += '<tr class="' + clase + '">' +
                '<td>' + f.linea + '</td>' +
                '<td>' + escapar(f.procesadora || '—') +
                    (f.id_externo
                        ? '<div class="cobel-subtitulo">Liq. ' + escapar(f.id_externo) + '</div>'
                        : '') + '</td>' +
                '<td class="text-end cobel-num">' +
                    (f.estado === 'ERROR' ? '—' : pesos(f.importe_bruto)) + '</td>' +
                '<td class="text-center">' +
                    (f.fecha_acreditacion ? fecha(f.fecha_acreditacion) : '—') + '</td>' +
                '<td class="text-end cobel-num">' +
                    (f.estado === 'ERROR' ? '—' : pesos(f.importe_neto)) +
                    (f.estado === 'CAMBIO'
                        ? '<div class="cobel-subtitulo">antes ' + pesos(f.neto_anterior) +
                          ' · ' + signo(f.diferencia) + '</div>'
                        : '') + '</td>' +
                '<td><span class="cobel-estado cobel-estado-' + f.estado.toLowerCase() + '">' +
                    etiquetaEstado(f.estado) + '</span> ' +
                    '<span class="cobel-motivo">' + escapar(f.motivo || '') + '</span></td>' +
            '</tr>';
        });

        return html + '</tbody></table></div>';
    }

    /** Los cargados que el archivo no trae. Se listan siempre: la baja es opt-in */
    function tablaBajas(diff) {
        var bajas = diff.bajas || [];

        if (!bajas.length) {
            return '';
        }

        var html = '<div class="cobel-diff-subtitulo">Cargados que el archivo no trae</div>' +
            '<div class="table-responsive cobel-diff-tabla">' +
            '<table class="table table-sm mb-0"><thead><tr>' +
            '<th>Procesadora</th><th class="text-center">Acreditación</th>' +
            '<th class="text-end">Importe bruto</th><th class="text-end">Neto</th>' +
            '<th>Origen</th>' +
            '</tr></thead><tbody>';

        bajas.forEach(function(b) {
            html += '<tr class="cobel-fila-baja">' +
                '<td>' + escapar(b.procesadora) + '</td>' +
                '<td class="text-center">' + fecha(b.fecha_acreditacion) + '</td>' +
                '<td class="text-end cobel-num">' + pesos(b.importe_bruto) + '</td>' +
                '<td class="text-end cobel-num">' + pesos(b.importe_neto) + '</td>' +
                '<td>' + etiquetaOrigen(b.origen_dato) + '</td>' +
            '</tr>';
        });

        return html + '</tbody></table></div>';
    }

    function confirmarImportacion() {
        if (!importacion) {
            return;
        }

        var bajas = document.getElementById('aplicarBajas');
        var aplicarBajas = !!(bajas && bajas.checked);

        if (aplicarBajas && !confirm('Se van a dar de baja ' +
                (importacion.bajas || []).length + ' movimiento(s) que el archivo no trae.\n\n' +
                'No se borran: quedan inhabilitados y salen del tablero. Si el archivo era ' +
                'parcial, cancelá y volvé a subirlo completo.')) {
            return;
        }

        // Se mandan los valores TAL COMO VINIERON EN EL ARCHIVO, y TODAS las
        // filas, incluidas las que tienen problemas. Dos motivos:
        //
        //   · el servidor vuelve a calcular el mismo diff con la misma entrada,
        //     así que lo que se aplica es lo que se mostró;
        //   · el "con un solo error no se importa nada" lo hace cumplir el
        //     servidor y no este archivo. Si acá se filtraran las filas con
        //     problemas, el resto se importaría y quedaría un archivo cargado a
        //     medias, que es justo lo que la regla evita.
        //
        // La tasa y el neto no viajan: los calcula el servidor.
        var filas = (importacion.filas || []).map(function(f) {
            var c = f.crudo || {};

            return {
                linea: f.linea,
                procesadora: c.procesadora,
                importe_bruto: c.importe_bruto,
                fecha_acreditacion: c.fecha_acreditacion,
                id_externo: c.id_externo,
                observaciones: c.observaciones
            };
        });

        conBoton('btnConfirmarImportar', function() {
            return pedirJson(URL_COBEL + '?action=confirmarImportacion', {
                filas: filas,
                archivo: importacion.archivo,
                aplicar_bajas: aplicarBajas,
                // El mismo período con el que se previsualizó: si no, las bajas
                // que se aplican no serían las que se mostraron.
                periodo_desde: valor('periodoDesde'),
                periodo_hasta: valor('periodoHasta')
            }).then(function(r) {
                var a = r.aplicado || {};

                panelImportar(false);
                setValor('archivoImportar', '');
                notificar('Importación aplicada: ' + (a.altas || 0) + ' alta(s), ' +
                    (a.cambios || 0) + ' cambio(s)' +
                    (a.bajas ? (' y ' + a.bajas + ' baja(s)') : '') + '.');
                cargar();
            });
        }, 'No se pudo importar');
    }

    /**
     * Sube un archivo y desenvuelve el sobre {success, data|message}.
     *
     * No usa pedirJson() porque eso serializa el cuerpo como JSON, y un archivo
     * va como multipart. El manejo del sobre es el mismo, incluido leer el texto
     * antes de parsearlo: si PHP emite un fatal, el error que llega es legible.
     */
    function subirArchivo(url, formData) {
        return fetch(url, { method: 'POST', body: formData })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('Error HTTP: ' + r.status);
                }

                return r.text();
            })
            .then(function(texto) {
                var resultado;

                try {
                    resultado = JSON.parse(texto);
                } catch (e) {
                    console.error('Respuesta no JSON:', texto);
                    throw new Error('Respuesta inválida del servidor. Revisá la consola.');
                }

                if (!resultado.success) {
                    throw new Error(resultado.message || 'Error desconocido');
                }

                return resultado.data;
            });
    }

    function etiquetaEstado(estado) {
        var estados = {
            'ALTA': 'nueva',
            'CAMBIO': 'cambia',
            'SIN_CAMBIOS': 'igual',
            'YA_ACREDITADA': 'ya acreditada',
            'ERROR': 'problema'
        };

        return estados[estado] || estado;
    }

    /** '+ $ 1.000,00' / '− $ 1.000,00', para una diferencia */
    function signo(valor) {
        var n = parseFloat(valor) || 0;

        return (n >= 0 ? '+ ' : '− ') + pesos(Math.abs(n));
    }

    /* ================================================================
       TOTALES Y CUADROS
       ================================================================ */

    function pintarPorProcesadora() {
        var filas = datos.por_procesadora || [];

        document.getElementById('bodyPorProcesadora').innerHTML = filas.length
            ? filas.map(function(p) {
                  return '<tr>' +
                      '<td class="fw-semibold">' + escapar(p.procesadora) + '</td>' +
                      '<td class="text-center">' + p.movimientos + '</td>' +
                      '<td class="text-end cobel-num">' + pesos(p.bruto) + '</td>' +
                      '<td class="text-end cobel-num text-muted">' + pesos(p.retenido) + '</td>' +
                      '<td class="text-end cobel-num cobel-neto">' + pesos(p.neto) + '</td>' +
                  '</tr>';
              }).join('')
            : '<tr><td colspan="5" class="text-center text-muted py-4">sin cargar</td></tr>';

        var t = datos.totales || {};

        document.getElementById('footPorProcesadora').innerHTML = (t.movimientos > 0)
            ? '<tr class="cobel-fila-total">' +
                  '<td class="fw-bold">TOTAL GENERAL</td>' +
                  '<td class="text-center fw-bold">' + t.movimientos + '</td>' +
                  '<td class="text-end fw-bold">' + pesos(t.bruto) + '</td>' +
                  '<td class="text-end fw-bold">' + pesos(t.retenido) + '</td>' +
                  '<td class="text-end fw-bold">' + pesos(t.neto) + '</td>' +
              '</tr>'
            : '';
    }

    function pintarCuadros() {
        var dias = datos.por_dia || [];
        var meses = datos.por_mes || [];
        var eje = datos.eje || {};

        document.getElementById('bodyPorDia').innerHTML = dias.length
            ? dias.map(function(d) {
                  var anterior = (eje.desde && d.fecha < eje.desde);
                  var fuera = !d.entra_al_tablero && !anterior;

                  return '<tr' + (fuera ? ' class="cobel-fuera-eje"'
                                        : (anterior ? ' class="cobel-acreditada"' : '')) + '>' +
                      '<td>' + fecha(d.fecha) +
                          (fuera
                              ? ' <span class="cobel-marca-inline">posterior</span>'
                              : (anterior
                                  ? ' <span class="cobel-marca-inline-neutra">ya acreditada</span>'
                                  : '')) + '</td>' +
                      '<td class="text-center">' + d.movimientos + '</td>' +
                      '<td class="text-end cobel-num">' + pesos(d.neto) + '</td>' +
                  '</tr>';
              }).join('')
            : '<tr><td colspan="3" class="text-center text-muted py-3">sin cargar</td></tr>';

        document.getElementById('bodyPorMes').innerHTML = meses.length
            ? meses.map(function(m) {
                  return '<tr>' +
                      '<td>' + escapar(m.label) + '</td>' +
                      '<td class="text-center">' + m.movimientos + '</td>' +
                      '<td class="text-end cobel-num">' + pesos(m.neto) + '</td>' +
                  '</tr>';
              }).join('')
            : '<tr><td colspan="3" class="text-center text-muted py-3">sin cargar</td></tr>';

        texto('cobelDesde', eje.desde ? fecha(eje.desde) : 'hoy');
    }

    /* ================================================================
       AVISOS Y UTILIDADES
       ================================================================ */

    /**
     * Los avisos no son decoración: son lo que evita leer un cero como si fuera
     * un dato, y lo que explica por qué el total de esta pantalla puede no ser
     * el que muestra el tablero.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosCobel');
        var avisos = (datos && datos.avisos) || [];

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3">' +
                  '<i class="fas fa-triangle-exclamation me-1"></i>' +
                  '<small><ul class="mb-0 ps-3">' +
                  avisos.map(function(a) { return '<li>' + escapar(a) + '</li>'; }).join('') +
                  '</ul></small></div>'
            : '';
    }

    /** Confirmación de un guardado puntual, arriba de la tabla y se va sola */
    function notificar(mensaje) {
        var cont = document.getElementById('avisosCobel');

        if (!cont) {
            return;
        }

        var div = document.createElement('div');

        div.className = 'alert alert-success py-2 px-3 mb-3';
        div.innerHTML = '<i class="fas fa-circle-check me-1"></i><small>' +
                        escapar(mensaje) + '</small>';

        cont.appendChild(div);

        setTimeout(function() {
            if (div.parentNode) {
                div.parentNode.removeChild(div);
            }
        }, 8000);
    }

    function tieneFiltro() {
        var f = datos.filtros || {};

        return !!(f.id_procesadora || f.desde || f.hasta);
    }

    function conBoton(id, accion, mensajeError) {
        conBotonEl(document.getElementById(id), accion, mensajeError);
    }

    /** Corre una acción mostrando el estado en el botón */
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

    function etiquetaOrigen(origen) {
        var origenes = {
            'MANUAL': 'Manual',
            'ARCHIVO': 'Archivo',
            'API': 'API'
        };

        return origenes[origen] || origen || 'Manual';
    }

    function pesos(valor) {
        return '$ ' + (parseFloat(valor) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** 0.031 -> '3,1%'. Se muestran hasta cuatro decimales, sin ceros de relleno */
    function porcentaje(tasa) {
        var n = (parseFloat(tasa) || 0) * 100;

        return n.toLocaleString('es-AR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 4
        }) + '%';
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fecha(valor) {
        if (!valor) {
            return '—';
        }

        var p = String(valor).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(valor);
    }

    function texto(id, valor) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = valor;
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
