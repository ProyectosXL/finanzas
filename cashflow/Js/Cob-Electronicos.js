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
 * Un movimiento que queda fuera del horizonte se ve igual, en su fila, marcado
 * y con el motivo. Nunca se esconde: es plata informada que el tablero no
 * muestra, y esconderla haría que el total de la pantalla y el del tablero no
 * cerraran sin explicación.
 */

(function() {
    'use strict';

    var URL_COBEL = 'Controller/CobElectronicosController.php';

    var datos = null;

    // Id del movimiento que se está editando en la grilla, o null
    var editando = null;

    function inicializar() {
        conectar('btnRefreshCobel', function() { cargar(); });
        conectar('btnNuevoMovimiento', function() { modoAlta(true); });
        conectar('btnCancelarMovimiento', function() { modoAlta(false); });
        conectar('btnAgregarMovimiento', agregarMovimiento);
        conectar('btnFiltrar', function() { cargar(); });
        conectar('btnLimpiarFiltro', limpiarFiltro);

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

        return partes.length ? ('&' + partes.join('&')) : '';
    }

    function limpiarFiltro() {
        setValor('filtroProcesadora', '0');
        setValor('filtroDesde', '');
        setValor('filtroHasta', '');
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

        texto('cobelFueraEje', (t.fuera_eje_movimientos > 0) ? pesos(t.fuera_eje) : '—');
        texto('cobelDetalleFuera', (t.fuera_eje_movimientos > 0)
            ? (t.fuera_eje_movimientos + ' movimiento(s) que el tablero no muestra')
            : 'Todo lo cargado entra al horizonte');

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
        var fuera = !f.entra_al_tablero;

        return '<tr data-mov="' + f.id + '"' + (fuera ? ' class="cobel-fuera-eje"' : '') + '>' +
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
     * Se dice el motivo y no sólo "fuera del horizonte": una fecha pasada no es
     * un error de carga, es plata que ya entró y que el saldo bancario de la
     * pestaña Saldos ya informa. Sin el motivo, la marca se lee como un dato mal
     * cargado.
     */
    function marcaEje(f) {
        if (f.ubicacion_eje === 'ANTERIOR') {
            return '<div class="cobel-marca" title="Ya se acreditó: esa plata está en la cuenta ' +
                   'y la informa el saldo bancario de la pestaña Saldos. Sumarla acá la contaría ' +
                   'dos veces.">ya acreditada · fuera del horizonte</div>';
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
                  var fuera = !d.entra_al_tablero;

                  return '<tr' + (fuera ? ' class="cobel-fuera-eje"' : '') + '>' +
                      '<td>' + fecha(d.fecha) +
                          (fuera ? ' <span class="cobel-marca-inline">fuera</span>' : '') + '</td>' +
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
