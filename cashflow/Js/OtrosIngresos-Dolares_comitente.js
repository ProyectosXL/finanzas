/**
 * Otros Ingresos → Dólares Cuenta Comitente
 *
 * El formulario es mínimo a propósito: fecha e importe en dólares. Todo lo
 * demás lo resuelve el backend.
 *
 * NO SE CONVIERTE A PESOS ACÁ. La pantalla muestra dólares; la conversión al
 * oficial del BCRA la hace el proveedor cada vez que se arma el tablero. Si el
 * navegador mostrara pesos, tendría que replicar el criterio de valuación y las
 * dos cuentas se desincronizarían.
 *
 * EL HISTORIAL ES PARTE DE LA PANTALLA, no una auditoría escondida: cargar una
 * fecha que ya existe pisa el importe vigente, y esa columna es lo único que
 * después explica por qué el número de esa fecha cambió.
 */

(function() {
    'use strict';

    var URL_OTROS = 'Controller/OtrosIngresosController.php';

    var datos = null;
    var modalHistorial = null;

    function inicializar() {
        conectar('btnGuardarDol', guardar);
        conectar('btnRefreshDol', cargar);

        var modalEl = document.getElementById('modalHistorialDol');

        if (modalEl && typeof bootstrap !== 'undefined') {
            modalHistorial = new bootstrap.Modal(modalEl);
        }

        // La fecha arranca en hoy, que es la carga normal. Se puede cambiar a
        // una pasada: la carga describe cuántos dólares había un día dado, y
        // ese día puede haber sido la semana pasada.
        var fecha = document.getElementById('fechaDol');

        if (fecha && !fecha.value) {
            fecha.value = hoyISO();
        }

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
        mostrar('loadingDol', true, 'flex');
        mostrar('wrapperDol', false);

        pedirJson(URL_OTROS + '?action=getDolaresComitente')
            .then(function(data) {
                datos = data;

                pintarAvisos();
                pintarFilas();
                pintarKpi();

                mostrar('loadingDol', false);
                mostrar('wrapperDol', true);
            })
            .catch(function(error) {
                mostrar('loadingDol', false);
                Notificacion.error('No se pudieron cargar los importes: ' + error.message);
            });
    }

    /**
     * Los avisos del backend. Distinguen "falta correr el script" de "todavía
     * nadie cargó nada": los dos dejan la grilla vacía y no significan lo
     * mismo.
     */
    function pintarAvisos() {
        var cont = document.getElementById('avisosDol');
        var avisos = (datos && datos.avisos) || [];

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? avisos.map(function(a) {
                  return '<div class="alert alert-warning py-2 px-3 mb-3"><small>'
                       + '<i class="fas fa-triangle-exclamation me-1"></i>'
                       + escapar(a) + '</small></div>';
              }).join('')
            : '';
    }

    /**
     * La grilla, con el importe editable.
     *
     * EDITAR NO ES UN UPDATE, y la pantalla no tiene por qué saberlo: manda el
     * mismo `saveDolaresComitente` que el alta, y el backend da de baja la
     * versión anterior e inserta una nueva. Un endpoint de edición aparte
     * insinuaría que hay un camino que modifica en el lugar, y no lo hay.
     *
     * LA FECHA NO ES EDITABLE desde la grilla, y decide dos cosas: con qué
     * cotización se valúa esta carga, y cuál es la última —que es la que va al
     * tablero—. Editarla desde acá cambiaría el importe en pesos y podría mover
     * cuál es el saldo vigente, dos efectos que nadie pidió al corregir un
     * número. Para una fecha distinta se carga desde el formulario de arriba.
     *
     * LA ÚLTIMA SE MARCA. Es la única cuyo importe llega al tablero; sin la
     * marca, tres filas con tres importes se leen como tres cosas que suman.
     */
    function pintarFilas() {
        var filas = (datos && datos.filas) || [];
        var ultima = laVigente(filas);
        var html = '';

        filas.forEach(function(f, i) {
            var versiones = Number(f.VERSIONES) || 1;
            var crono = f.FECHA_CRONOGRAMA || f.FECHA;
            var esLaQueVale = (ultima !== null && f.FECHA === ultima.FECHA);

            html += '<tr data-fila="' + i + '" data-crono="' + escapar(crono) + '"'
                +     ' data-fecha="' + escapar(f.FECHA) + '"'
                +     ' data-usd="' + escapar(String(f.IMPORTE_USD)) + '"'
                +     (esLaQueVale ? ' class="dol-vigente"' : '') + '>'
                + '<td>' + fechaCorta(f.FECHA)
                +     (esLaQueVale
                          ? ' <span class="dol-badge-vigente" title="'
                            + escapar('Es la carga más reciente, así que es el saldo que el '
                                + 'tablero usa. Las anteriores son fotos de cómo venía.')
                            + '">al tablero</span>'
                          : '')
                + '</td>'
                + '<td><input type="number" step="0.01" min="0" '
                +     'class="form-control form-control-sm text-end dol-usd" '
                +     'value="' + escapar(String(f.IMPORTE_USD)) + '"></td>'
                + celdaCotizacion(f)
                + celdaPesos(f)
                + '<td class="text-center dol-alta">' + escapar(f.FECHA_ALTA || '—')
                +     subtituloUsuario(f.USUARIO) + '</td>'
                + '<td class="text-center">' + celdaHistorial(crono, versiones) + '</td>'
                + '<td class="text-center">'
                +     '<button class="btn btn-sm btn-primary dol-guardar" style="display:none;" '
                +     'title="Guarda una versión nueva. La anterior queda en el historial.">'
                +     '<i class="fas fa-save"></i></button>'
                + '</td>'
                + '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="7" class="text-center text-muted py-4">'
                 + 'Todavía no hay saldo cargado. La fila del tablero muestra cero.'
                 + '</td></tr>';
        }

        document.getElementById('bodyDol').innerHTML = html;

        document.querySelectorAll('.dol-historial').forEach(function(b) {
            b.addEventListener('click', function() {
                abrirHistorial(b.getAttribute('data-fecha'));
            });
        });

        conectarEdicion();
        pintarPie(filas);
    }

    /* ================================================================
       EDICION EN LA GRILLA

       El botón de guardar de cada fila aparece SÓLO cuando esa fila tiene
       algo cambiado. Un botón siempre activo invita a apretarlo, y acá
       apretarlo sin haber cambiado nada genera una versión idéntica a la
       anterior en el historial: ruido permanente sobre el registro que
       existe justamente para explicar los cambios.
       ================================================================ */

    /** La carga más reciente: el saldo que el tablero usa */
    function laVigente(filas) {
        var ultima = null;

        (filas || []).forEach(function(f) {
            if (ultima === null || f.FECHA > ultima.FECHA) {
                ultima = f;
            }
        });

        return ultima;
    }

    function conectarEdicion() {
        document.querySelectorAll('#bodyDol tr[data-fila]').forEach(function(tr) {
            var btn = tr.querySelector('.dol-guardar');
            var inp = tr.querySelector('.dol-usd');

            if (!btn || !inp) {
                return;
            }

            inp.addEventListener('input', function() { revisarFila(tr); });
            btn.addEventListener('click', function() { guardarFila(tr); });
        });
    }

    /** Si la fila difiere de lo que vino del backend, se puede guardar */
    function cambios(tr) {
        var usd = tr.querySelector('.dol-usd');

        return {
            usd: usd ? String(usd.value).trim() : '',
            usdOriginal: tr.getAttribute('data-usd'),
            fecha: tr.getAttribute('data-fecha'),

            /* La fecha de cronograma no se muestra ni se edita, pero SIGUE
               SIENDO la clave con la que el backend pisa la carga anterior. Se
               manda tal cual vino: sin esto, una fila que tuviera una distinta
               de su fecha se guardaría como una carga nueva en vez de pisar la
               suya. */
            crono: tr.getAttribute('data-crono')
        };
    }

    function revisarFila(tr) {
        var c = cambios(tr);
        var btn = tr.querySelector('.dol-guardar');

        // Comparado como número: '1000' y '1000.00' son el mismo importe, y
        // ofrecer guardar ahí sería ofrecer una versión que no cambia nada.
        var cambio = (c.usd !== '' && Number(c.usd) !== Number(c.usdOriginal));

        tr.classList.toggle('dol-editada', cambio);
        btn.style.display = cambio ? '' : 'none';
    }

    function guardarFila(tr) {
        var c = cambios(tr);

        if (c.usd === '' || isNaN(Number(c.usd))) {
            Notificacion.error('El importe en dólares no es un número.');
            return;
        }

        if (Number(c.usd) < 0) {
            Notificacion.error('El importe no puede ser negativo: restaría del tablero '
                + 'en vez de sumar.');

            return;
        }

        var btn = tr.querySelector('.dol-guardar');

        btn.disabled = true;

        // 'fecha' es la que la fila YA TENÍA: es la que valúa, y editar el
        // importe no tiene por qué cambiarle la cotización.
        // 'cronograma_anterior' es lo que le permite al backend retirar el día
        // de origen en la misma transacción: sin eso, mover una fila dejaría el
        // importe contado dos veces.
        pedirJson(URL_OTROS + '?action=saveDolaresComitente', {
                fecha: c.fecha,
                importe_usd: Number(c.usd),
                fecha_cronograma: c.crono
            })
            .then(function() {
                Notificacion.exito('Saldo actualizado.', {
                    detalle: 'La versión anterior queda en el historial.'
                });

                cargar();
            })
            .catch(function(error) {
                btn.disabled = false;
                Notificacion.error('No se pudo guardar: ' + error.message);
            });
    }

    /**
     * La cotización con la que se valuó la fila, CON SU FECHA Y CON SU PUNTA.
     *
     * La fecha es parte del dato, no un adorno. Es la última cotización oficial
     * anterior o igual a la de la carga, así que casi nunca es del mismo día:
     * un sábado se valúa con la del viernes, y hoy con la última que el BCRA
     * haya publicado. Sin decir de qué día es, el importe en pesos no se puede
     * explicar contra nada.
     *
     * Cuando la fecha de la cotización no coincide con la de la carga se marca,
     * porque es justo el caso en el que alguien podría suponer que el tipo de
     * cambio es el del día.
     *
     * LA PUNTA TAMBIÉN SE DICE, y por el mismo motivo. Ésta es la única pantalla
     * del cashflow que valúa con el VENDEDOR; el resto usa comprador. Un importe
     * a vendedor que no diga que es a vendedor se compara contra el BCRA
     * comprador y parece estar mal. Sale de la fila (TC_PUNTA) y no de una
     * constante de acá: la punta la decide el backend, y dos listas se
     * desincronizan.
     */
    function celdaCotizacion(f) {
        if (f.TC === null || f.TC === undefined) {
            return '<td class="text-center text-muted" '
                + 'title="No hay ninguna cotización oficial anterior a esta fecha. '
                + 'No se asume ningún tipo de cambio, así que este importe tampoco '
                + 'entra al tablero.">—</td>';
        }

        var mismaFecha = (f.TC_FECHA === f.FECHA);
        var punta = f.TC_PUNTA || '';

        return '<td class="text-center">'
            + '<span class="dol-tc">$ ' + Number(f.TC).toLocaleString('es-AR', {
                  minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>'
            + (punta
                ? ' <span class="dol-tc-punta" title="' + escapar('Punta ' + punta
                        + ' del dólar oficial del BCRA: lo que el banco cobra por un dólar. '
                        + 'Es la única pestaña del cashflow que valúa con esta punta —el '
                        + 'resto usa la compradora—, así que este importe no cierra contra '
                        + 'las otras pantallas, y es a propósito.')
                    + '">' + escapar(punta) + '</span>'
                : '')
            + '<div class="dol-tc-fecha' + (mismaFecha ? '' : ' dol-tc-anterior') + '" '
            +     'title="' + escapar(mismaFecha
                    ? 'Cotización oficial del BCRA de ese mismo día.'
                    : 'Ese día no tiene cotización publicada (fin de semana, feriado o '
                        + 'todavía sin cargar), así que se usa la última anterior. No se '
                        + 'inventa ningún valor intermedio.') + '">'
            +     'del ' + fechaCorta(f.TC_FECHA)
            + '</div></td>';
    }

    /**
     * El importe en pesos: USD x cotización. Es EXACTAMENTE el número que entra
     * al tablero, y sale de la misma cuenta que hace el proveedor
     * (OtrosIngresos::valuarDolares()), no de una multiplicación hecha acá.
     */
    function celdaPesos(f) {
        if (f.IMPORTE_ARS === null || f.IMPORTE_ARS === undefined) {
            return '<td class="currency text-muted" '
                + 'title="Sin cotización no hay importe en pesos. Un cero se leería como '
                + '&quot;estos dólares valen cero&quot;.">—</td>';
        }

        return '<td class="currency fw-bold" title="'
            + escapar('US$ ' + Number(f.IMPORTE_USD).toLocaleString('es-AR', {
                  minimumFractionDigits: 2, maximumFractionDigits: 2 })
                + '  x  $ ' + Number(f.TC).toLocaleString('es-AR', {
                  minimumFractionDigits: 2, maximumFractionDigits: 2 })
                + ' (' + (f.TC_PUNTA ? f.TC_PUNTA + ', ' : '')
                + 'del ' + fechaCorta(f.TC_FECHA) + ')')
            + '">$ ' + Number(f.IMPORTE_ARS).toLocaleString('es-AR', {
                  minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
    }

    /**
     * El enlace al historial sólo aparece cuando hay más de una carga: un botón
     * que la mitad de las veces abre un modal con una sola fila se lee como una
     * pantalla que no funciona.
     */
    function celdaHistorial(fecha, versiones) {
        if (versiones < 2) {
            return '<span class="text-muted small">carga única</span>';
        }

        return '<button class="btn btn-sm btn-outline-secondary py-0 px-2 dol-historial" '
            + 'data-fecha="' + escapar(fecha) + '" '
            + 'title="Esta fecha se cargó ' + versiones + ' veces. Acá está por qué el número '
            + 'era otro.">'
            + '<i class="fas fa-clock-rotate-left me-1"></i>' + versiones + ' versiones'
            + '</button>';
    }

    /**
     * El pie NO SUMA: muestra el saldo vigente, que es la carga más reciente.
     *
     * Sumar las cargas daría dólares que nunca estuvieron juntos en la cuenta.
     * Cada carga es una FOTO del saldo a esa fecha, y lo que va al tablero es la
     * última. Un pie que dijera TOTAL sobre una columna de fotos sería el error
     * más fácil de cometer leyendo esta pantalla, así que dice SALDO y nombra la
     * fecha de la que sale.
     */
    function pintarPie(filas) {
        var u = laVigente(filas);

        if (!filas.length || u === null) {
            document.getElementById('footDol').innerHTML = '';
            return;
        }

        var sinValuar = (u.IMPORTE_ARS === null || u.IMPORTE_ARS === undefined);

        var pesos = sinValuar
            ? '<td class="currency fw-bold text-muted" title="' + escapar('Esta carga no se '
                + 'pudo valuar: no hay cotización oficial anterior a su fecha. La fila del '
                + 'tablero se muestra en cero.') + '">—</td>'
            : '<td class="currency fw-bold">$ ' + Number(u.IMPORTE_ARS).toLocaleString('es-AR', {
                minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';

        /* Las celdas del pie van una por columna y en el mismo orden que el
           encabezado: Fecha | USD | Cotización | ARS | Cargado el | Historial |
           (acción). Un colspan mal contado corre el número debajo de otra
           columna y queda diciendo otra cosa. */
        document.getElementById('footDol').innerHTML =
            '<tr><td class="fw-bold text-end" title="' + escapar('Es la carga del '
                + fechaCorta(u.FECHA) + ', la más reciente. No es una suma: las anteriores '
                + 'son fotos de cómo venía el saldo.') + '">SALDO</td>'
            + '<td class="currency fw-bold">' + dolares(u.IMPORTE_USD) + '</td>'
            + '<td></td>'
            + pesos
            + '<td colspan="3"></td></tr>';
    }

    /**
     * ", punta vendedora" para el pie del KPI, sacado de las filas valuadas.
     *
     * Vuelve vacío si ninguna fila trae punta —no hay cargas, o no se pudo leer
     * el tipo de cambio—: nombrar una punta que no se usó sería peor que no
     * nombrar ninguna.
     */
    function sufijoPunta(filas) {
        for (var i = 0; i < filas.length; i++) {
            if (filas[i].TC_PUNTA) {
                return ', punta ' + filas[i].TC_PUNTA;
            }
        }

        return '';
    }

    /**
     * Las tarjetas describen EL SALDO, no la suma de las cargas.
     *
     * Antes la del medio decía "Total cargado" y sumaba todas. Con una sola
     * carga no se notaba; con dos decía US$ 137.000 arriba de una cuenta que
     * tiene 71.000, porque las cargas son fotos del mismo saldo y no depósitos.
     * Un número arriba de una tabla describe esa tabla.
     */
    function pintarKpi() {
        var filas = (datos && datos.filas) || [];
        var u = laVigente(filas);
        var sinValuar = (u !== null
            && (u.IMPORTE_ARS === null || u.IMPORTE_ARS === undefined));

        texto('ultimoImporteDol', u ? dolaresPlano(u.IMPORTE_USD) : 'US$ 0,00');
        texto('ultimaFechaDol', u ? ('Al ' + fechaCorta(u.FECHA)) : 'Sin cargas');

        texto('totalDol', String(filas.length));
        texto('detalleTotalDol', filas.length === 1
            ? 'una sola foto del saldo'
            : 'fotos del saldo · vale la más reciente');

        texto('totalArsDol', (u && !sinValuar)
            ? '$ ' + Number(u.IMPORTE_ARS).toLocaleString('es-AR', {
                minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '$ 0,00');

        /* Con qué punta se valuó, por lo mismo que en cada fila: este número es
           el que se compara contra el tablero y contra el BCRA, y es el único
           del módulo que no sale de la punta compradora. */
        texto('detalleArsDol', sinValuar
            ? 'Sin cotización para esa fecha: el tablero muestra cero'
            : 'Valuado al oficial del BCRA' + sufijoPunta(filas));

        mostrar('summaryDol', true, 'flex');
    }

    /* ================================================================
       GUARDADO
       ================================================================ */

    function guardar() {
        var fecha = valor('fechaDol');
        var importe = valor('importeDol');

        if (!fecha) {
            Notificacion.campoInvalido('fechaDol', 'Elegí la fecha del importe.');
            return;
        }

        if (importe === '' || isNaN(Number(importe))) {
            Notificacion.campoInvalido('importeDol', 'Ingresá el importe en dólares.', {
                detalle: '0 es un valor válido: significa que ese día no había dólares '
                       + 'en la cuenta.'
            });

            return;
        }

        if (Number(importe) < 0) {
            Notificacion.campoInvalido('importeDol',
                'El importe no puede ser negativo: restaría del tablero en vez de sumar.');

            return;
        }

        var btn = document.getElementById('btnGuardarDol');

        btn.disabled = true;

        pedirJson(URL_OTROS + '?action=saveDolaresComitente',
                { fecha: fecha, importe_usd: Number(importe) })
            .then(function(data) {
                btn.disabled = false;
                setValor('importeDol', '');

                if (data && data.piso) {
                    Notificacion.exito('Importe actualizado.', {
                        detalle: 'La carga anterior de esa fecha queda en el historial.'
                    });
                } else {
                    Notificacion.exito('Importe cargado.');
                }

                cargar();
            })
            .catch(function(error) {
                btn.disabled = false;
                Notificacion.error('No se pudo guardar: ' + error.message);
            });
    }

    /* ================================================================
       HISTORIAL
       ================================================================ */

    /**
     * El historial de un DÍA DEL CRONOGRAMA, que es la columna del tablero cuyo
     * número cambió. Las versiones de esa columna pueden haberse registrado en
     * días distintos, así que cada una trae su propia fecha de dato.
     */
    function abrirHistorial(fecha) {
        pedirJson(URL_OTROS + '?action=getHistorialDolares&fecha=' + encodeURIComponent(fecha))
            .then(function(filas) {
                texto('historialFechaDol', fechaCorta(fecha));

                document.getElementById('bodyHistorialDol').innerHTML =
                    (filas || []).map(function(f) {
                        return '<tr class="' + (f.VIGENTE ? '' : 'dol-pisada') + '">'
                            + '<td class="currency">' + dolares(f.IMPORTE_USD) + '</td>'
                            + '<td class="text-center dol-fecha-dato">'
                            +     fechaCorta(f.FECHA) + '</td>'
                            + '<td class="text-center">'
                            +     (f.VIGENTE
                                    ? '<span class="badge bg-success">vigente</span>'
                                    : '<span class="badge bg-secondary">pisada</span>')
                            + '</td>'
                            + '<td class="text-center dol-alta">' + escapar(f.FECHA_ALTA || '—')
                            +     subtituloUsuario(f.USUARIO) + '</td>'
                            + '</tr>';
                    }).join('');

                if (modalHistorial) {
                    modalHistorial.show();
                }
            })
            .catch(function(error) {
                Notificacion.error('No se pudo leer el historial: ' + error.message);
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = v;
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

    function dolares(v) {
        return '<span class="dol-importe">' + dolaresPlano(v) + '</span>';
    }

    function dolaresPlano(v) {
        return 'US$ ' + (Number(v) || 0).toLocaleString('es-AR',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fechaCorta(v) {
        if (!v) {
            return '—';
        }

        var p = String(v).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(v);
    }

    function hoyISO() {
        var d = new Date();

        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    function subtituloUsuario(usuario) {
        return '<div class="dol-subtitulo">' + escapar(usuario || 'sin usuario') + '</div>';
    }

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
