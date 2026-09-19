/**
 * Echeqs JavaScript
 *
 * Dos sub-pestañas independientes:
 *   Cheques en Cartera       -> cheques de terceros en cartera, con eje temporal
 *   Venta Cobrada Anticipada -> cheques de clientes que pre-chequean, tildables
 *
 * La segunda es LAZY: se pide recién cuando se abre. Depende de un maestro que
 * puede no estar cargado y no tiene por qué demorar la que se abre primero.
 *
 * LAS TRES VISTAS LAS MANEJA eje-vistas.js. Acá no se calcula ninguna fecha ni
 * se decide qué columnas van en cada vista: eso lo resuelve Class/EjeVista.php y
 * viene en el payload.
 *
 * CADA SUB-PESTAÑA TIENE SU TILDE Y NO SON EL MISMO:
 *
 *   Cartera       EXCLUIR  se eligen con checks y se confirman juntos, con UN
 *                          motivo, desde la barra de selección. Saca el importe
 *                          de la fila del tablero.
 *   Prechequeado  MARCAR   el check actúa solo, porque tildar y destildar son
 *                          el trabajo de todos los días en esa pantalla.
 *
 * LA DIFERENCIA DE GESTO ES A PROPÓSITO: destildar un cheque pre-chequeado se
 * deshace destildándolo de nuevo, y sacar plata del disponible no puede
 * dispararse con un clic suelto ni quedar sin motivo.
 *
 * EL GUARDADO DE LAS MARCAS NO ES OPTIMISTA EN SILENCIO. El tilde se pinta
 * enseguida para que la pantalla responda, pero si el POST falla se revierte y
 * se muestra el error: una marca que se ve pero no se guardó desajusta la
 * proyección de Ventas sin que nadie se entere.
 */

(function() {
    'use strict';

    var URL_ECHEQS = 'Controller/EcheqsController.php';

    var datosCartera = null;
    var datosPre = null;
    var prePedido = false;

    /** Controlador de las tres vistas, compartido con el resto del módulo */
    var vistas = null;

    /**
     * El de la sub-pestaña 2. Va SEPARADO del de cartera y no compartido: son
     * dos tablas con dos ejes distintos en pantalla al mismo tiempo, y un solo
     * controlador haría que cambiar de vista en una moviera la otra.
     */
    var vistasPre = null;

    function inicializar() {
        vistas = crearEjeVistas({
            botones: 'vistasEcheqs',
            periodo: 'periodoEcheqs',
            alCambiar: dibujarCartera
        });

        vistasPre = crearEjeVistas({
            botones: 'vistasPre',
            periodo: 'periodoPre',
            alCambiar: dibujarPrechequeado
        });

        // N° de cheque identifica la fila y Cliente es de quién es: son las dos
        // que uno busca cuando ya scrolleó hasta la columna de la fecha.
        crearColumnasFijas({
            tabla: 'tablaEcheqs',
            control: 'colFijasEcheqs',
            clave: 'echeqs_cartera',
            porDefecto: [1, 3]
        });

        // En pre-chequeado la primera columna es el tilde, que es lo que hay
        // que tener siempre a mano, y la cuarta es el cliente.
        crearColumnasFijas({
            tabla: 'tablaPrechequeado',
            control: 'colFijasPre',
            clave: 'echeqs_prechequeado',
            porDefecto: [0, 6]
        });

        conectar('btnRefreshEch', cargarCartera);
        conectar('btnExportEch', exportarCartera);
        conectar('btnRefreshPre', cargarPrechequeado);

        conectar('selTodosEch', seleccionarVisibles);
        conectar('btnExcluirSelEch', function() { accionSeleccion(true); });
        conectar('btnIncluirSelEch', function() { accionSeleccion(false); });
        conectar('btnLimpiarSelEch', function() {
            seleccion = {};
            dibujarCartera();
        });

        escuchar('verExcluidosEch', 'change', dibujarCartera);
        escuchar('busquedaEch', 'keyup', dibujarCartera);
        escuchar('busquedaPre', 'keyup', dibujarPrechequeado);
        escuchar('filtroClientePre', 'change', dibujarPrechequeado);

        conectar('marcarTodosPre', marcarVisibles);

        // La sub-pestaña 2 se pide la primera vez que se abre y no antes.
        var tabPre = document.getElementById('tabPrechequeadoBtn');

        if (tabPre) {
            tabPre.addEventListener('shown.bs.tab', function() {
                if (!prePedido) {
                    cargarPrechequeado();
                }
            });
        }

        cargarCartera();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       SUB-PESTAÑA 1 — CHEQUES EN CARTERA
       ================================================================ */

    function cargarCartera() {
        mostrar('loadingEch', true, 'flex');
        mostrar('wrapperEch', false);

        pedirJson(URL_ECHEQS + '?action=getEcheqsCartera')
            .then(function(data) {
                datosCartera = data;

                // El controlador de vistas se entera del eje nuevo antes de que
                // se dibuje la tabla.
                vistas.usar(datosCartera);

                pintarAvisos();
                pintarKpiCartera();
                dibujarCartera();

                mostrar('loadingEch', false);
                mostrar('wrapperEch', true);
            })
            .catch(function(error) {
                mostrar('loadingEch', false);
                avisar('No se pudieron cargar los cheques en cartera: ' + error.message);
            });
    }

    /**
     * Los tres indicadores miden los tres períodos de las tres vistas, así que
     * cada tarjeta se corresponde con un botón.
     *
     * MUESTRAN EL NETO —sin los excluidos—, que es lo que de verdad entra al
     * cashflow y lo mismo que suma la fila del tablero. Los dos totales vienen
     * del backend: acá no se resta nada.
     */
    function pintarKpiCartera() {
        var totales = datosCartera.totales_netos || datosCartera.totales || {};
        var vs = datosCartera.vistas || {};

        texto('totalDiasEch', pesos(totales.total_tramo));
        texto('totalMesesEch', pesos(totales.total_meses));
        texto('totalGeneralEch', pesos(totales.total_horizonte));

        texto('rotuloDiasEch', vs.dias ? vs.dias.periodo : '');
        texto('rotuloMesesEch', vs.meses ? vs.meses.periodo : '');
        texto('rotuloGeneralEch', vs.completo ? vs.completo.periodo : '');

        /* CUENTA LOS QUE ENTRAN AL CASHFLOW, no los que hay. Con los excluidos
           escondidos, decir "390 cheque(s)" arriba de una tabla con 387 filas
           manda a buscar tres que no están, y el número tampoco describiría el
           importe de al lado, que ya viene neto. */
        var e = datosCartera.excluidos || {};
        var cobrables = (datosCartera.filas || []).length - (e.cheques || 0);

        texto('detalleCarteraEch', cobrables + ' cheque(s) de terceros, por fecha de pago'
            + (e.cheques ? ' · ' + e.cheques + ' excluido(s) aparte' : ''));

        mostrar('summarySectionEch', true, 'flex');
    }

    /**
     * Cuánta plata está excluida, y con qué motivos.
     *
     * SE DICE AUNQUE NO SE VEA, y sobre todo por eso: los excluidos están
     * escondidos por defecto, así que sin este cartel la única forma de notar
     * que falta un importe sería acordarse de prender el interruptor. Es el
     * mismo aviso que EcheqsProvider deja en el tablero.
     */
    function pintarExcluidos() {
        var cont = document.getElementById('excluidosEch');

        if (!cont) {
            return;
        }

        var e = (datosCartera && datosCartera.excluidos) || {};

        if (!e.cheques) {
            mostrar('excluidosEch', false);
            return;
        }

        var motivos = (e.motivos || []).slice(0, 3);
        var mas = (e.motivos || []).length - motivos.length;
        var viendo = verExcluidos();

        cont.innerHTML = '<small><i class="fas fa-ban me-1"></i>'
            + '<strong>' + e.cheques + ' cheque(s) por ' + escapar(pesosPlano(e.importe))
            + '</strong> están excluidos: esa plata no entra al cashflow y ya está '
            + 'descontada de las tarjetas de arriba.'
            + (motivos.length
                ? ' Motivos: ' + motivos.map(escapar).join('; ')
                  + (mas > 0 ? '; y ' + mas + ' más.' : '.')
                : '')
            + (viendo
                ? ' Se ven en la tabla, atenuados.'
                : ' Tildá <em>Ver excluidos</em> para revisarlos.')
            + '</small>';

        mostrar('excluidosEch', true);
    }

    function dibujarCartera() {
        if (!datosCartera || !datosCartera.filas) {
            return;
        }

        var cols = vistas.columnas();
        var filas = filasCarteraVisibles();

        pintarEncabezadoEje(cols);
        pintarFilasCartera(filas, cols);
        pintarTotalesCartera(filas, cols);
        pintarExcluidos();
        pintarSeleccion();
    }

    function pintarEncabezadoEje(cols) {
        var html = '';

        cols.forEach(function(col) {
            var esMes = vistas.esMes(col);
            var meta = vistas.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            html += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + escapar(titulo) + '"' : '') + '>'
                + escapar(vistas.rotulo(col)) + '</th>';
        });

        html += '<th class="total-column">Total</th>';

        var cabecera = document.getElementById('ejeHeaderEch');

        if (cabecera) {
            cabecera.textContent = datosCartera.vistas[vistas.activa()].label;
            cabecera.setAttribute('colspan', String(cols.length + 1));
        }

        document.getElementById('ejeSubHeaderEch').innerHTML = html;
    }

    /** Cuántas columnas descriptivas tiene la tabla de cartera */
    var COLS_DESC_ECH = 6;

    function pintarFilasCartera(filas, cols) {
        var html = '';

        filas.forEach(function(f) {
            // El excluido se atenúa y lleva el importe tachado: no entra al
            // cashflow, y eso tiene que verse sin leer la celda de la marca.
            html += '<tr' + (f.EXCLUIDO ? ' class="ech-excluido"' : '')
                 + ' data-id="' + f.ID_SBA14 + '">';
            html += '<td class="center"><span class="badge-cobro">' + fecha(f.FECHA_PAGO) + '</span></td>';
            html += '<td class="center">' + numeroCheque(f.N_CHEQUE) + '</td>';
            html += '<td>' + escapar(f.BANCO) + '</td>';
            html += '<td class="col-texto" title="' + escapar(f.CLIENTE) + '">'
                 + escapar(f.CLIENTE) + subtituloCodigo(f.COD_CLIENTE) + '</td>';
            html += '<td class="currency">' + pesos(f.IMPORTE) + '</td>';
            html += '<td class="text-center">' + celdaExcluir(f) + '</td>';

            // Los importes por columna ya vienen resueltos: la regla de "día O
            // mes, nunca las dos" la aplicó el backend, una sola vez.
            cols.forEach(function(col) {
                var valor = Number(vistas.valor(f, col)) || 0;

                html += '<td class="currency ' + (valor !== 0 ? 'cell-with-value' : '') + '">'
                    + (valor !== 0 ? pesos(valor) : '') + '</td>';
            });

            var total = vistas.total(f);

            html += '<td class="currency total-column">' + (total !== 0 ? pesos(total) : '') + '</td>';
            html += '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="' + (COLS_DESC_ECH + cols.length + 1) + '" '
                 + 'class="text-center text-muted py-4">' + mensajeVacioCartera() + '</td></tr>';
        }

        document.getElementById('bodyEch').innerHTML = html;

        conectarSeleccion();
    }

    /**
     * El mensaje del listado vacío distingue los motivos. "No hay cheques" no es
     * lo mismo que "el filtro no encontró nada", y ninguno de los dos es "están
     * todos escondidos porque están excluidos".
     */
    function mensajeVacioCartera() {
        if (!datosCartera.filas.length) {
            return 'No hay cheques de terceros en cartera con fecha de hoy en adelante.';
        }

        var e = datosCartera.excluidos || {};

        if (!verExcluidos() && e.cheques && e.cheques === datosCartera.filas.length) {
            return 'Todos los cheques en cartera están excluidos. Tildá "Ver excluidos" '
                 + 'para revisarlos.';
        }

        return 'Ningún cheque coincide con la búsqueda.';
    }

    /**
     * Pie de la tabla.
     *
     * Respeta el buscador, así que se suman las filas que pasan el filtro. Pero
     * se suman los importes YA AGRUPADOS por el backend, sin reinterpretar
     * ninguna fecha: la regla de "día O mes" sigue estando en un solo lugar.
     */
    function pintarTotalesCartera(filas, cols) {
        var html = '<td colspan="4" class="fw-bold text-end">TOTALES</td>';
        var bruto = 0;

        filas.forEach(function(f) {
            bruto += Number(f.IMPORTE) || 0;
        });

        html += '<td class="currency fw-bold">' + pesos(bruto) + '</td>';
        html += '<td></td>';

        cols.forEach(function(col) {
            var total = 0;

            filas.forEach(function(f) {
                total += Number(vistas.valor(f, col)) || 0;
            });

            html += '<td class="currency ' + (total !== 0 ? 'cell-with-value' : '') + '">'
                + (total !== 0 ? pesos(total) : '') + '</td>';
        });

        var granTotal = 0;

        filas.forEach(function(f) {
            granTotal += vistas.total(f);
        });

        html += '<td class="currency total-column">' + (granTotal !== 0 ? pesos(granTotal) : '') + '</td>';

        document.getElementById('totalesEch').innerHTML = html;
    }

    /** Si el interruptor de ver excluidos está prendido */
    function verExcluidos() {
        var chk = document.getElementById('verExcluidosEch');

        return !!(chk && chk.checked);
    }

    /**
     * El buscador mira cliente, banco y número de cheque, y el interruptor
     * decide si los excluidos entran.
     *
     * LOS EXCLUIDOS NO SE VEN POR DEFECTO: ya se decidió que esa plata no va a
     * entrar, así que en el trabajo normal son ruido. Lo que esconden se dice
     * arriba, siempre, y por eso esconderlos no es esconder nada.
     */
    function filasCarteraVisibles() {
        var term = valor('busquedaEch').toLowerCase();
        var verEx = verExcluidos();

        return datosCartera.filas.filter(function(f) {
            if (!verEx && f.EXCLUIDO) {
                return false;
            }

            return !term || coincide(f, term);
        });
    }

    function coincide(f, term) {
        return String(f.CLIENTE || '').toLowerCase().indexOf(term) !== -1
            || String(f.COD_CLIENTE || '').toLowerCase().indexOf(term) !== -1
            || String(f.BANCO || '').toLowerCase().indexOf(term) !== -1
            || String(f.N_CHEQUE || '').indexOf(term) !== -1;
    }

    /**
     * Exporta lo que se ve: el buscador y el orden aplicado ya están en el DOM,
     * y de los tildes de la grilla se encarga el componente compartido, que los
     * exporta como Sí/No y no como un checkbox. Ver Js/tabla-export.js.
     */
    function exportarCartera() {
        exportarTabla('tablaEcheqs', 'Echeqs_cartera');
    }

    /* ================================================================
       EXCLUIR CHEQUES DEL CASHFLOW

       Es plata que NO se va a poder cobrar: el cliente avisó que no lo cubre,
       el cheque quedó judicializado, está en gestión de cambio. El importe
       sale de la fila del tablero y queda informado aparte, con su motivo.

       APLICA SÓLO A CARTERA. El tilde de la otra sub-pestaña contesta otra
       pregunta —si esa venta ya se cobró— y ninguna decisión implica la otra.

       SE ELIGEN Y SE CONFIRMAN JUNTOS, con UN motivo para todos. Excluir los
       doce cheques de un cliente que entró en concurso es UNA decisión, y doce
       motivos distintos para una decisión son doce oportunidades de que digan
       cosas distintas. El caso se resuelve con el buscador: filtrar el
       cliente, "seleccionar todos los que se ven", y un motivo.

       ES EL GESTO DEL TILDADO MASIVO DE LA OTRA SUB-PESTAÑA —el check del
       encabezado toma todo lo visible— con una diferencia: acá el check de la
       fila SELECCIONA y no actúa. Sacar plata del disponible no puede
       dispararse con un clic suelto ni quedar sin motivo.
       ================================================================ */

    /** Ids de los cheques seleccionados. Sobrevive a los redibujos. */
    var seleccion = {};

    /** Por qué los dos botones de excluir pueden estar apagados */
    var FALTA_EXCLUIR = 'Para excluir cheques hace falta correr '
        + 'sql/cashflow_echeqs_excluir.sql contra la base central.';

    /**
     * La celda de selección.
     *
     * EL CHECK SE DIBUJA AUNQUE FALTE EL SCRIPT DE LA EXCLUSIÓN, y lo que se
     * apaga son los dos botones de la barra —eso lo hace pintarSeleccion()—.
     * Un check que no está no explica por qué no está; uno que está y una barra
     * que dice qué falta, sí.
     */
    function celdaExcluir(f) {
        var marca = f.EXCLUIDO
            ? '<div><span class="ech-badge-excluido" title="'
              + escapar('Excluido del cashflow: ' + (f.MOTIVO_EXCLUSION || 'sin motivo registrado')
                  + '\n' + detalleExclusion(f))
              + '">excluido</span></div>'
            : '';

        return '<input type="checkbox" class="form-check-input ech-sel"'
            + (seleccion[f.ID_SBA14] ? ' checked' : '')
            + ' data-id="' + f.ID_SBA14 + '"'
            + ' title="' + escapar('Seleccionar este cheque para excluirlo del cashflow o '
                + 'volver a incluirlo.') + '">' + marca;
    }

    /** Quién excluyó ese cheque y cuándo. Un tilde sin autor no lo explica nadie */
    function detalleExclusion(f) {
        return 'Excluido por ' + (f.EXCLUSION_USUARIO || 'sin usuario (todavía no hay login)')
            + (f.EXCLUSION_FECHA ? ' el ' + fechaHora(f.EXCLUSION_FECHA) : '');
    }

    /** Los cheques seleccionados que hoy están a la vista */
    function filasSeleccionadas() {
        return filasCarteraVisibles().filter(function(f) { return !!seleccion[f.ID_SBA14]; });
    }

    /**
     * La barra de acciones. Dice CUÁNTOS y CUÁNTO antes de que se apriete nada:
     * excluir saca plata del disponible, y el importe es el dato que hace que
     * alguien note que seleccionó de más.
     */
    function pintarSeleccion() {
        var sel = filasSeleccionadas();
        var total = 0;
        var yaExcluidos = 0;

        sel.forEach(function(f) {
            total += Number(f.IMPORTE) || 0;
            if (f.EXCLUIDO) { yaExcluidos++; }
        });

        mostrar('barraSelEch', sel.length > 0);
        sincronizarSelTodos();

        if (!sel.length) {
            return;
        }

        texto('selResumenEch', sel.length + ' cheque(s) seleccionado(s) · ' + pesosPlano(total)
            + (yaExcluidos ? ' · ' + yaExcluidos + ' ya excluido(s)' : ''));

        /* Cada botón se apaga cuando no tiene nada que hacer: "Excluir" con
           todo ya excluido, o "Volver a incluir" sin ninguno excluido. Un botón
           que se puede apretar y no cambia nada es peor que uno apagado.

           SIN EL SCRIPT los dos quedan apagados y lo dicen en el título: la
           pantalla sigue andando para todo lo demás, que no depende de él. */
        var puede = !!(datosCartera && datosCartera.excluir_cheque);
        var btnEx = document.getElementById('btnExcluirSelEch');
        var btnIn = document.getElementById('btnIncluirSelEch');

        if (btnEx) {
            btnEx.disabled = !puede || (yaExcluidos === sel.length);
            btnEx.title = puede ? '' : FALTA_EXCLUIR;
        }

        if (btnIn) {
            btnIn.disabled = !puede || (yaExcluidos === 0);
            btnIn.title = puede ? '' : FALTA_EXCLUIR;
        }
    }

    /**
     * El check del encabezado toma o suelta TODO LO VISIBLE según el buscador y
     * el interruptor de excluidos. Es el mismo gesto que el tildado masivo de la
     * otra sub-pestaña.
     *
     * ACÁ NO PREGUNTA NADA, a diferencia de allá: seleccionar no escribe, y la
     * pregunta viene después, en el diálogo del motivo, que además dice cuántos
     * y por cuánto.
     */
    function seleccionarVisibles() {
        var chk = document.getElementById('selTodosEch');
        var visibles = filasCarteraVisibles();

        if (chk && chk.checked) {
            visibles.forEach(function(f) { seleccion[f.ID_SBA14] = true; });
        } else {
            visibles.forEach(function(f) { delete seleccion[f.ID_SBA14]; });
        }

        dibujarCartera();
    }

    /** El check del encabezado refleja si TODO lo visible está seleccionado */
    function sincronizarSelTodos() {
        var chk = document.getElementById('selTodosEch');

        if (!chk) { return; }

        var visibles = filasCarteraVisibles();
        var elegidos = visibles.filter(function(f) { return !!seleccion[f.ID_SBA14]; }).length;

        chk.checked = (visibles.length > 0 && elegidos === visibles.length);
        chk.indeterminate = (elegidos > 0 && elegidos < visibles.length);
    }

    function conectarSeleccion() {
        document.querySelectorAll('#bodyEch .ech-sel').forEach(function(chk) {
            chk.addEventListener('change', function() {
                // parseInt y no el atributo crudo: las claves de un objeto son
                // strings igual, pero el resto del archivo indexa con
                // f.ID_SBA14, que es un número. Dos formas de escribir la misma
                // clave invitan a compararlas algún día con ===.
                var id = parseInt(chk.dataset.id, 10);

                if (chk.checked) {
                    seleccion[id] = true;
                } else {
                    delete seleccion[id];
                }

                pintarSeleccion();
            });
        });
    }

    /**
     * Excluye o vuelve a incluir lo seleccionado, con UN motivo para todos.
     *
     * El motivo se pide en un diálogo del módulo y no con el prompt del
     * navegador: acá hay que leer cuántos cheques y por cuánta plata antes de
     * escribir nada, y eso en un prompt no entra.
     */
    function accionSeleccion(excluir) {
        var sel = filasSeleccionadas();

        if (!sel.length) { return; }

        // Lo que ya está como se lo quiere dejar no se vuelve a escribir: sería
        // una versión idéntica en el historial y un número inflado en el
        // mensaje.
        var aplicar = sel.filter(function(f) { return !!f.EXCLUIDO !== excluir; });

        if (!aplicar.length) { return; }

        var total = 0;
        var clientes = {};

        aplicar.forEach(function(f) {
            total += Number(f.IMPORTE) || 0;
            clientes[f.COD_CLIENTE] = f.CLIENTE;
        });

        var codigos = Object.keys(clientes);
        var detalle = aplicar.length + ' cheque(s) por ' + pesosPlano(total)
            + (codigos.length === 1
                ? ', todos de ' + codigos[0] + ' — ' + clientes[codigos[0]]
                : ', de ' + codigos.length + ' clientes') + '.';

        if (!excluir) {
            Notificacion.confirmar({
                titulo: 'Volver a incluir en el cashflow',
                mensaje: '¿Devolver estos cheques al cashflow?',
                detalle: detalle + ' Sus importes vuelven a la fila del tablero. La exclusión '
                    + 'no se borra: queda en el historial, dada de baja.',
                confirmar: 'Volver a incluir'
            }).then(function(ok) {
                if (ok) { guardarExclusion(aplicar, false, null); }
            });

            return;
        }

        Notificacion.pedirTexto({
            titulo: 'Excluir del cashflow',
            peligro: true,
            mensaje: detalle,
            detalle: 'Sus importes salen de la fila del tablero y quedan informados aparte, '
                + 'con este motivo. El cheque sigue en cartera en Tango: lo que cambia es que '
                + 'el cashflow deja de contar esa plata.',
            etiqueta: 'Motivo (el mismo para todos)',
            placeholder: 'Ej.: el cliente avisó que no lo cubre, judicializado, en gestión de cambio…',
            maxlargo: 200,
            valor: valorComun(aplicar, 'MOTIVO_EXCLUSION'),
            invalido: 'Escribí el motivo: es lo único que después explica por qué falta ese '
                + 'importe en el disponible.',
            confirmar: 'Excluir ' + aplicar.length + ' cheque(s)'
        }).then(function(motivo) {
            if (motivo !== null) { guardarExclusion(aplicar, true, motivo); }
        });
    }

    /**
     * Si los seleccionados ya compartían un valor en ese campo, se ofrece de
     * arranque. Si hay dos distintos no se elige uno: el diálogo abre vacío,
     * porque proponer el del primero sería decidir por el usuario.
     */
    function valorComun(filas, campo) {
        var unico = null;

        for (var i = 0; i < filas.length; i++) {
            var m = filas[i][campo] || '';

            if (m === '') { continue; }
            if (unico !== null && unico !== m) { return ''; }

            unico = m;
        }

        return unico || '';
    }

    function guardarExclusion(filas, excluir, motivo) {
        var cuerpo = {
            excluir: excluir,
            ids: filas.map(function(f) { return f.ID_SBA14; })
        };

        if (motivo !== null) { cuerpo.motivo = motivo; }

        // La selección se limpia al guardar: los excluidos se esconden por
        // defecto, así que dejarlos seleccionados mantendría una barra hablando
        // de cheques que ya no están a la vista.
        seleccion = {};

        pedirJson(URL_ECHEQS + '?action=excluirCheques', cuerpo)
            .then(function(data) {
                // Se recarga en vez de parchear en memoria: el servidor devuelve
                // el estado efectivo y puede haber rechazado alguno de los ids
                // por haber salido de cartera.
                Notificacion.exito(excluir
                    ? data.tocados + ' cheque(s) excluidos. El tablero ya no cuenta esa plata.'
                    : data.tocados + ' cheque(s) incluidos de nuevo en el cashflow.');

                cargarCartera();
            })
            .catch(function(error) {
                // No hay nada que revertir en pantalla: el check sólo
                // selecciona, y la fila se repinta recién cuando el servidor
                // confirma. Lo que sí hay que hacer es decir que no se guardó.
                Notificacion.error('No se pudo guardar: ' + error.message);
                dibujarCartera();
            });
    }

    /* ================================================================
       SUB-PESTAÑA 2 — VENTA COBRADA ANTICIPADA
       ================================================================ */

    function cargarPrechequeado() {
        prePedido = true;

        mostrar('loadingPre', true, 'flex');
        mostrar('wrapperPre', false);
        ocultarLuegoDe('avisoGuardadoPre', 6000);

        pedirJson(URL_ECHEQS + '?action=getEcheqsPrechequeado')
            .then(function(data) {
                datosPre = data;

                // El controlador de vistas se entera del eje nuevo antes de que
                // se dibuje la tabla.
                vistasPre.usar(datosPre);

                pintarAvisos();
                pintarAvisosEjePre();
                pintarFiltroClientes();
                dibujarPrechequeado();

                mostrar('loadingPre', false);
                mostrar('wrapperPre', true);
            })
            .catch(function(error) {
                mostrar('loadingPre', false);
                avisar('No se pudieron cargar los cheques pre-chequeados: ' + error.message);
            });
    }

    /**
     * El desplegable se arma con los clientes PRESENTES en el listado, no con
     * todo el maestro: un filtro que ofrece opciones que no devuelven nada se lee
     * como una pantalla rota.
     */
    function pintarFiltroClientes() {
        var select = document.getElementById('filtroClientePre');

        if (!select) {
            return;
        }

        var elegido = select.value;
        var html = '<option value="">Todos los clientes</option>';

        ((datosPre.resumen && datosPre.resumen.clientes) || []).forEach(function(c) {
            html += '<option value="' + escapar(c.codigo) + '">'
                + escapar(c.nombre || c.codigo) + ' (' + c.cheques + ')</option>';
        });

        select.innerHTML = html;
        select.value = elegido;
    }

    function dibujarPrechequeado() {
        if (!datosPre) {
            return;
        }

        var filas = filasPreVisibles();
        var cols = vistasPre.columnas();

        pintarEncabezadoEjePre(cols);
        pintarFilasPre(filas, cols);
        pintarPiePre(filas, cols);
        pintarKpiPre();
        sincronizarCabecera(filas);
    }

    /**
     * Lo que quedó fuera del eje, que a esta altura es sólo lo POSTERIOR al
     * horizonte: son cheques que están en la tabla pero cuyo importe no tiene
     * columna donde ubicarse, y eso se avisa.
     *
     * Lo anterior a hoy no llega hasta acá. El filtro lo aplica PHP
     * —`Echeqs::ventaYaCobrada()`, en `cruzarPrechequeado()`— y esos cheques no
     * bajan: su venta ya se facturó y ya se cobró, así que no es plata que falte
     * mostrar. El corte va en PHP y no acá a propósito: los KPIs del encabezado
     * salen de `resumenPrechequeado()` sobre las mismas filas, y filtrar en el
     * navegador habría dejado la tabla corta y los totales largos.
     *
     * Es el mismo criterio de `Ventas::repartirNeteo()`, que además es la cuenta
     * que de verdad netea, y es literalmente la misma función.
     */
    function pintarAvisosEjePre() {
        var cont = document.getElementById('avisosEjePre');

        if (!cont) {
            return;
        }

        var avisos = (datosPre && datosPre.warnings) || [];

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-0 rounded-0"><small>'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + avisos.map(escapar).join(' ') + '</small></div>'
            : '';
    }

    function pintarEncabezadoEjePre(cols) {
        var html = '';

        cols.forEach(function(col) {
            var esMes = vistasPre.esMes(col);
            var meta = vistasPre.meta(col) || {};
            var clases = [esMes ? 'month-column' : 'day-column'];
            var titulo = '';

            if (esMes && meta.parcial) {
                clases.push('col-parcial');
                titulo = 'Este mes está recortado: sus primeros días están en el tramo diario';
            }

            html += '<th class="' + clases.join(' ') + '"'
                + (titulo ? ' title="' + escapar(titulo) + '"' : '') + '>'
                + escapar(vistasPre.rotulo(col)) + '</th>';
        });

        html += '<th class="total-column">Total</th>';

        var grupo = document.getElementById('grupoEjePre');

        if (grupo && datosPre.vistas) {
            grupo.textContent = datosPre.vistas[vistasPre.activa()].label;
            grupo.setAttribute('colspan', String(cols.length + 1));
        }

        document.getElementById('headerEjePre').innerHTML = html;
    }

    /** Cuántas columnas descriptivas tiene la tabla de pre-chequeado */
    var COLS_DESC_PRE = 10;

    function pintarFilasPre(filas, cols) {
        var html = '';

        filas.forEach(function(f) {
            var heredada = (f.ORIGEN_MARCA === 'cliente');
            var dias = Number(f.DIAS_PRECHEQUEADO) || 0;

            html += '<tr class="' + (f.MARCADO ? 'ech-marcado' : 'ech-sin-marcar') + '"'
                 + ' data-id="' + f.ID_SBA14 + '">';

            html += '<td class="text-center">'
                 + '<input type="checkbox" class="form-check-input ech-marca" '
                 + 'data-id="' + f.ID_SBA14 + '"' + (f.MARCADO ? ' checked' : '') + '>'
                 + '</td>';

            // LA DEL CHEQUE ES LA QUE UBICA EL IMPORTE EN LA GRILLA, así que va
            // primera y destacada. Es cuando entra la plata.
            html += '<td class="center"><span class="ech-fecha-cheque" '
                 + 'title="Fecha del cheque. Es la que ubica este importe en la grilla y en '
                 + 'el tablero: es cuando entra la plata.">'
                 + fecha(f.FECHA_CHEQUE) + '</span></td>';

            // La estimada queda en gris, como informativa: explica POR QUÉ este
            // cheque está en la lista -su venta todavía no ocurrió- y no dónde
            // cae. Mismo idioma que "Importe Factura" en Cobranzas May.
            html += '<td class="center"><span class="ech-fecha-estimada" '
                 + 'title="Fecha del cheque menos los días de pre-chequeado del cliente. '
                 + 'Es la que decide si este cheque se muestra: si ya pasó, esa venta se '
                 + 'facturó y se cobró. No es la que ubica el importe.">'
                 + fecha(f.FECHA_VENTA_EST) + '</span></td>';

            // Los días efectivos: sin esto, un cliente en cero se ve igual que
            // uno configurado y no hay forma de saber por qué las dos fechas
            // coinciden.
            html += '<td class="text-center">'
                 + (dias > 0
                        ? '<span class="ech-dias-precheq">−' + dias + ' d</span>'
                        : '<span class="ech-sin-dias" title="Este cliente no tiene días de '
                          + 'pre-chequeado cargados, así que su venta estimada es la propia '
                          + 'fecha del cheque. Se configura en Parámetros → Pre-chequeado.">0</span>')
                 + '</td>';

            html += '<td class="center">' + numeroCheque(f.N_CHEQUE) + '</td>';
            html += '<td>' + escapar(f.BANCO) + '</td>';
            html += '<td class="col-texto" title="' + escapar(f.CLIENTE) + '">'
                 + escapar(f.CLIENTE) + subtituloCodigo(f.COD_CLIENTE) + '</td>';
            html += '<td class="text-center">' + badgeEstado(f.ESTADO) + '</td>';
            html += '<td class="currency">' + pesos(f.IMPORTE) + '</td>';

            // Un tilde que el usuario no puso y no sabe de dónde salió es peor
            // que no tenerlo: acá se dice de dónde viene cada uno.
            html += '<td class="text-center">'
                 + (heredada
                        ? '<span class="ech-origen-cliente" title="Viene del maestro: este cliente '
                          + 'opera con venta cobrada anticipada, así que sus cheques entran '
                          + 'tildados. Nadie tocó este cheque en particular.">del cliente</span>'
                        : '<span class="ech-origen-cheque" title="'
                          + escapar(detalleMarca(f)) + '">'
                          + (f.MARCADO ? 'tildado a mano' : 'destildado a mano') + '</span>')
                 + '</td>';

            // Los importes por columna ya vienen resueltos del backend, sobre
            // la FECHA DEL CHEQUE.
            cols.forEach(function(col) {
                var v = Number(vistasPre.valor(f, col)) || 0;

                html += '<td class="currency ' + (v !== 0 ? 'cell-with-value' : '') + '">'
                    + (v !== 0 ? pesos(v) : '') + '</td>';
            });

            var total = vistasPre.total(f);

            html += '<td class="currency total-column">'
                + (total !== 0 ? pesos(total) : '') + '</td>';

            html += '</tr>';
        });

        if (!filas.length) {
            html = '<tr><td colspan="' + (COLS_DESC_PRE + cols.length + 1) + '" '
                 + 'class="text-center text-muted py-4">' + mensajeVacio() + '</td></tr>';
        }

        document.getElementById('bodyPre').innerHTML = html;

        document.querySelectorAll('.ech-marca').forEach(function(c) {
            c.addEventListener('change', function() {
                marcar([parseInt(c.dataset.id, 10)], c.checked, [c]);
            });
        });
    }

    /**
     * El mensaje del listado vacío distingue los tres motivos. "Sin clientes
     * configurados" no es lo mismo que "no hay cheques", y ninguno de los dos es
     * "el filtro no encontró nada".
     */
    function mensajeVacio() {
        if (!datosPre.filas.length) {
            return (datosPre.resumen && datosPre.resumen.cheques === 0
                    && (!datosPre.resumen.clientes || !datosPre.resumen.clientes.length))
                ? 'No hay clientes configurados. Cargalos en Parámetros → Pre-chequeado.'
                : 'Los clientes configurados no tienen cheques vivos con fecha de hoy en adelante.';
        }

        return 'Ningún cheque coincide con el filtro.';
    }

    function pintarPiePre(filas, cols) {
        var porEstado = {};
        var totalVisible = 0;
        var marcadoVisible = 0;

        filas.forEach(function(f) {
            var importe = Number(f.IMPORTE) || 0;

            totalVisible += importe;

            if (!f.MARCADO) {
                return;
            }

            marcadoVisible += importe;

            if (!porEstado[f.ESTADO]) {
                porEstado[f.ESTADO] = { cheques: 0, importe: 0 };
            }

            porEstado[f.ESTADO].cheques++;
            porEstado[f.ESTADO].importe += importe;
        });

        // El desglose por estado es lo que hace verificable el punto abierto:
        // los cheques que ya salieron de cartera netean sin que ninguna fila del
        // tablero los sume.
        var detalle = Object.keys(porEstado).sort().map(function(e) {
            return '<span class="ech-estado-total">' + badgeEstado(e) + ' '
                + porEstado[e].cheques + ' cheque(s) · ' + pesos(porEstado[e].importe)
                + '</span>';
        }).join(' ');

        // La grilla del pie suma SOLO los cheques marcados: son los únicos que
        // netean. Mostrar ahí el total del listado haría creer que se resta
        // también lo destildado.
        var porColumna = cols.map(function(col) {
            var total = 0;

            filas.forEach(function(f) {
                if (f.MARCADO) {
                    total += Number(vistasPre.valor(f, col)) || 0;
                }
            });

            return total;
        });

        var granTotal = 0;

        filas.forEach(function(f) {
            if (f.MARCADO) {
                granTotal += vistasPre.total(f);
            }
        });

        var celdasEje = porColumna.map(function(t) {
            return '<td class="currency ' + (t !== 0 ? 'cell-with-value' : '') + '">'
                + (t !== 0 ? pesos(t) : '') + '</td>';
        }).join('')
            + '<td class="currency total-column">'
            + (granTotal !== 0 ? pesos(granTotal) : '') + '</td>';

        var vacias = cols.map(function() { return '<td></td>'; }).join('') + '<td></td>';
        var anchoTotal = COLS_DESC_PRE + cols.length + 1;

        document.getElementById('footPre').innerHTML =
            '<tr class="ech-fila-total">' +
                '<td colspan="8" class="fw-bold text-end">MARCADO (visible)</td>' +
                '<td class="currency fw-bold">' + pesos(marcadoVisible) + '</td>' +
                '<td></td>' +
                celdasEje +
            '</tr>' +
            '<tr>' +
                '<td colspan="8" class="text-end text-muted">Total del listado visible</td>' +
                '<td class="currency text-muted">' + pesos(totalVisible) + '</td>' +
                '<td></td>' +
                vacias +
            '</tr>' +
            (detalle
                ? '<tr><td colspan="' + anchoTotal + '" class="ech-detalle-estados">'
                    + detalle + '</td></tr>'
                : '');
    }

    function pintarKpiPre() {
        var r = datosPre.resumen || {};
        var porEstado = r.por_estado || {};
        var enCartera = porEstado.C ? porEstado.C.importe : 0;
        var fuera = 0;
        var chequesFuera = 0;

        Object.keys(porEstado).forEach(function(e) {
            if (e !== 'C') {
                fuera += porEstado[e].importe;
                chequesFuera += porEstado[e].cheques;
            }
        });

        texto('totalMarcadoPre', pesos(r.importe_marcado));
        texto('detalleMarcadoPre', (r.marcados || 0) + ' de ' + (r.cheques || 0) + ' cheques marcados');
        texto('totalListadoPre', pesos(r.importe_total));
        texto('detalleListadoPre', (r.cheques || 0) + ' cheque(s) en el listado');
        texto('totalEnCarteraPre', pesos(enCartera));
        texto('totalFueraCarteraPre', pesos(fuera));
        texto('detalleFueraCarteraPre', chequesFuera + ' cheque(s) ya aplicados o depositados');

        mostrar('summaryPreEch', true, 'flex');
    }

    /** El filtro por cliente y el buscador de texto se combinan */
    function filasPreVisibles() {
        var cliente = valor('filtroClientePre');
        var term = valor('busquedaPre').toLowerCase();

        return datosPre.filas.filter(function(f) {
            if (cliente && f.COD_CLIENTE !== cliente) {
                return false;
            }

            return !term || coincide(f, term);
        });
    }

    /* ---------------- Marcado ---------------- */

    /**
     * El checkbox del encabezado afecta SÓLO LO VISIBLE según el filtro actual, y
     * dice cuántas filas va a tocar antes de hacerlo: destildar doscientos
     * cheques sin querer no se deshace de a uno.
     */
    function marcarVisibles() {
        var cabecera = document.getElementById('marcarTodosPre');
        var destino = cabecera.checked;
        var filas = filasPreVisibles().filter(function(f) {
            return !!f.MARCADO !== destino;
        });

        if (!filas.length) {
            sincronizarCabecera(filasPreVisibles());
            return;
        }

        var accion = destino ? 'Tildar ' : 'Destildar ';

        if (!confirm(accion + filas.length + ' cheque(s) por '
                + pesosPlano(sumar(filas)) + '?')) {
            sincronizarCabecera(filasPreVisibles());
            return;
        }

        marcar(filas.map(function(f) { return f.ID_SBA14; }), destino, []);
    }

    /**
     * Manda las marcas al servidor. En UNA sola llamada, aunque sean doscientas:
     * el servidor las aplica en una transacción, así que o entran todas o no
     * entra ninguna.
     *
     * @param {Array} ids Ids de SBA14
     * @param {boolean} destino Estado al que van
     * @param {Array} checkboxes Checkboxes a revertir si el POST falla
     */
    function marcar(ids, destino, checkboxes) {
        if (!ids.length) {
            return;
        }

        checkboxes.forEach(function(c) { c.disabled = true; });

        pedirJson(URL_ECHEQS + '?action=marcarCheques', { ids: ids, marcado: destino })
            .then(function(data) {
                // Se recarga en vez de parchear en memoria: el servidor devuelve
                // el estado efectivo y puede haber rechazado alguno de los ids
                // por quedar fuera del listado.
                texto('avisoGuardadoPre', data.tocados + ' cheque(s) '
                    + (destino ? 'tildados' : 'destildados') + '. Ventas ya usa este neteo.');
                mostrar('avisoGuardadoPre', true, 'inline-block');

                cargarPrechequeado();
            })
            .catch(function(error) {
                // Reversión: la pantalla vuelve a lo que el servidor tiene
                // guardado. Dejar el tilde puesto haría creer que se guardó.
                checkboxes.forEach(function(c) {
                    c.checked = !destino;
                    c.disabled = false;
                });

                avisar('No se pudo guardar la marca, así que se dejó como estaba: '
                    + error.message);
            });
    }

    /**
     * El checkbox del encabezado refleja lo visible: tildado si está todo
     * tildado, indeterminado si hay de los dos.
     */
    function sincronizarCabecera(filas) {
        var cabecera = document.getElementById('marcarTodosPre');

        if (!cabecera) {
            return;
        }

        var marcados = filas.filter(function(f) { return !!f.MARCADO; }).length;

        cabecera.checked = (filas.length > 0 && marcados === filas.length);
        cabecera.indeterminate = (marcados > 0 && marcados < filas.length);
        cabecera.title = filas.length
            ? (cabecera.checked ? 'Destildar los ' : 'Tildar los ') + filas.length
              + ' cheque(s) que se están viendo'
            : 'No hay cheques visibles';
    }

    function sumar(filas) {
        return filas.reduce(function(acum, f) {
            return acum + (Number(f.IMPORTE) || 0);
        }, 0);
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
            .concat((datosCartera && datosCartera.warnings) || [])
            .concat((datosPre && datosPre.avisos) || []);

        var cont = document.getElementById('avisosEcheqs');

        if (!cont) {
            return;
        }

        cont.innerHTML = avisos.length
            ? '<div class="alert alert-warning py-2 px-3 mb-3">'
                + '<i class="fas fa-triangle-exclamation me-1"></i>'
                + '<small><ul class="mb-0 ps-3">'
                + avisos.map(function(a) { return '<li>' + escapar(a) + '</li>'; }).join('')
                + '</ul></small></div>'
            : '';
    }

    function badgeEstado(estado) {
        var titulos = {
            'C': 'En cartera: el cheque todavía está en la casa y suma al disponible',
            'A': 'Aplicado: ya salió de cartera, depositado en el banco o endosado a un proveedor'
        };

        return '<span class="ech-estado ech-estado-' + escapar(estado) + '" title="'
            + escapar(titulos[estado] || 'Estado de Tango') + '">' + escapar(estado) + '</span>';
    }

    function detalleMarca(f) {
        var quien = f.MARCA_USUARIO || 'sin usuario (todavía no hay login)';

        return (f.MARCADO ? 'Tildado' : 'Destildado') + ' por ' + quien
            + (f.MARCA_FECHA ? ' el ' + fechaHora(f.MARCA_FECHA) : '');
    }

    function subtituloCodigo(codigo) {
        return codigo ? '<div class="ech-subtitulo">' + escapar(codigo) + '</div>' : '';
    }

    /**
     * N_CHEQUE es float(53) en Tango. Se muestra sin decimales y sin separador de
     * miles: es un identificador, no una cantidad, y '1.23457E+7' es un bug
     * visible.
     */
    function numeroCheque(n) {
        var v = parseInt(n, 10);

        return isNaN(v) ? '—' : String(v);
    }

    function conectar(id, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener('click', fn);
        }
    }

    function escuchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) {
            el.addEventListener(evento, fn);
        }
    }

    function ocultarLuegoDe(id, ms) {
        var el = document.getElementById(id);

        if (!el || el.style.display === 'none') {
            return;
        }

        setTimeout(function() { mostrar(id, false); }, ms);
    }

    function valor(id) {
        var el = document.getElementById(id);

        return el ? String(el.value) : '';
    }

    function texto(id, v) {
        var el = document.getElementById(id);

        if (el) {
            el.textContent = v;
        }
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function pesos(v) {
        var n = parseFloat(v) || 0;

        return (n < 0 ? '<span class="text-danger">' : '') + pesosPlano(n)
            + (n < 0 ? '</span>' : '');
    }

    function pesosPlano(v) {
        return '$ ' + (parseFloat(v) || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /** 'YYYY-MM-DD' -> 'dd/mm/yyyy', sin pasar por Date para no correr el huso */
    function fecha(v) {
        if (!v) {
            return '—';
        }

        var p = String(v).slice(0, 10).split('-');

        return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : String(v);
    }

    function fechaHora(v) {
        var s = String(v);

        return fecha(s) + (s.length > 10 ? (' ' + s.slice(11, 16)) : '');
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
