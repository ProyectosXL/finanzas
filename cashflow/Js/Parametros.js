/**
 * Parámetros JavaScript
 * La pestaña de nivel raíz y la sub-pestaña VENTAS: el editor del árbol del
 * mix de cobro y la participación fija de respaldo.
 *
 * Carga SÓLO el módulo VENTAS, recién cuando se abre su sub-pestaña: la carga
 * diferida, el wrapper de la pestaña y los avisos comunes de arriba los maneja
 * Js/parametros-carga.js, igual para todas las sub-pestañas. Los demás
 * módulos tienen cada uno su propio archivo y su propio JS.
 *
 * LOS PARÁMETROS GENERALES YA NO ESTÁN ACÁ: la alícuota, el horizonte y los
 * feriados se fueron a la sub-pestaña Generales (Js/Parametros-Generales.js).
 * No movían sólo esta pantalla, así que vivir bajo Ventas los describía mal.
 */

(function() {
    'use strict';

    var datos = null;

    // Módulo que se está renderizando. Hoy la pestaña sólo tiene Ventas; cuando
    // se sumen otros módulos, cada sub-pestaña resuelve el suyo con buscarModulo().
    var modulo = null;

    /**
     * Busca un módulo del payload por su código
     * @param {string} codigo Código del módulo (VENTAS, ...)
     */
    function buscarModulo(codigo) {
        if (!datos || !datos.modulos) {
            return null;
        }

        for (var i = 0; i < datos.modulos.length; i++) {
            if (datos.modulos[i].codigo === codigo) {
                return datos.modulos[i];
            }
        }

        return null;
    }

    /** Muestra bajo las sub-pestañas qué afecta el módulo activo */
    function pintarDescripcion() {
        var cont = document.getElementById('descripcionVentas');

        if (cont && modulo.descripcion) {
            cont.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + escapar(modulo.descripcion);
        }
    }

    function inicializar() {
        console.log('Inicializando Parámetros');

        var btnRefresh = document.getElementById('btnRefreshParametros');
        var btnGuardarRespaldo = document.getElementById('btnGuardarRespaldo');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargar);
        }

        // El editor del mix: agregar, guardar y los campos de cada nodo se
        // atienden en el tbody, que se redibuja entero en cada carga.
        engancharMix();

        if (btnGuardarRespaldo) {
            btnGuardarRespaldo.addEventListener('click', guardarRespaldo);
        }

        /* No carga al arrancar: carga cuando se abre su sub-pestaña, o ahora
           si es la primera que se ve. Ver Js/parametros-carga.js. */
        ParametrosCarga.registrar('VENTAS', cargar);
    }

    // Ejecutar cuando el DOM esté listo O inmediatamente si ya está listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }

    /* ================================================================
       CARGA
       ================================================================ */

    /**
     * Trae SÓLO el módulo VENTAS. Antes este archivo pedía getTodo entero y
     * manejaba el wrapper de toda la pestaña: Parámetros no se veía hasta que
     * cargaba Ventas, aunque lo que se abriera fuera otra sub-pestaña. Eso lo
     * hace ahora Js/parametros-carga.js, igual para todas, y también pinta los
     * avisos generales de arriba.
     *
     * @returns {Promise}
     */
    function cargar() {
        return ParametrosCarga.pedir('VENTAS')
            .then(function(data) {
                datos = data;
                // Los parámetros vienen agrupados por módulo: cada sub-pestaña
                // muestra los que afectan a esa pestaña de la aplicación.
                modulo = buscarModulo('VENTAS');

                if (!modulo) {
                    throw new Error('El backend no devolvió el módulo VENTAS');
                }

                pintarDescripcion();
                generarMix();
                generarRespaldo();
            })
            .catch(function(error) {
                mostrarError('Error al cargar los parámetros: ' + error.message);

                // Falló: que volver a la sub-pestaña lo reintente.
                return false;
            });
    }

    /* ================================================================
       GENERALES: YA NO ESTÁN ACÁ

       La alícuota, el horizonte y los feriados de comercio se fueron a la
       sub-pestaña Generales, que tiene su propio archivo y su propio JS
       (Js/Parametros-Generales.js). No movían sólo esta pantalla: el horizonte
       es el eje de todo el módulo.

       Lo que este archivo dibuja es lo que de verdad sólo afecta a Ventas: el
       mix de cobro y la participación fija de respaldo.
       ================================================================ */

    /* ================================================================
       MIX DE COBRO: EL EDITOR DEL ÁRBOL

       Un bloque por canal, con sus nodos en el orden del árbol. Cada nodo
       carga lo suyo —%, costo, tasa, días— y al lado, en gris, lo que la
       regla resuelve para él: el costo + tasa acumulado de su rama y de
       quién hereda los días.

       ESTA PANTALLA NO TIENE LA REGLA. Lo que muestra en gris, las sumas de
       cada grupo de hermanos y si se puede guardar salen del servidor:
       primero del payload (MixCobro::datosEditor()) y, mientras se edita, de
       previsualizarMixArbol, que resuelve el canal como quedaría con la
       MISMA función que usa el motor. Se pide al CAMBIAR de campo y no en
       cada tecla; mientras la respuesta no llega —o mientras hay un campo a
       medio tipear— Guardar queda bloqueado. Si el JS tuviera su copia de la
       regla, algún día diría que "Resto" hereda 7 días y el tablero lo
       acreditaría a otros.

       La validación que manda es la del servidor: saveMixArbol vuelve a
       resolver el árbol entero como quedaría antes de escribir nada.
       ================================================================ */

    /** Por canal: lo último que resolvió el servidor para lo que hay en pantalla */
    var vistaMix = {};

    /** Por canal: si hay un cambio que el servidor todavía no resolvió */
    var mixPendiente = {};

    /** Por canal: si hay cambios sin guardar */
    var mixSucio = {};

    /** Por canal: número del último pedido, para descartar respuestas viejas */
    var mixPedido = {};

    /** Demora entre un cambio y el pedido: junta los cambios de un mismo gesto */
    var mixTimer = {};

    /*
       EL MIX POR MES. Un canal en VARIABLE tiene, arriba del árbol, la fila de
       meses: Constante · Ene … Dic. El árbol muestra los valores del mes
       elegido —los suyos, o los del constante si no tiene mix propio— y lo que
       se guarda es ese mes. La estructura (nombres, niveles, nodos nuevos) se
       edita sólo en Constante: un mes guarda valores, no nodos.
     */

    /** Por canal: el mes que se está viendo (1 a 12), o null para el constante */
    var mesMix = {};

    /**
     * Por canal: los nodos apagados en el constante para los que se tildó
     * "Inhabilitarlo también en esos meses". La casilla se contesta nodo por
     * nodo, y el guardado manda la lista.
     */
    var apagarEnMeses = {};

    function datosMix() {
        return modulo.mix;
    }

    /** Si se dibujan controles: con permiso y con el árbol ya en su tabla */
    function mixEditable() {
        return !!datosMix().editable && Permisos.puedeEditar('mixBody');
    }

    /** Si se corrió el script del mix por mes */
    function mixPorMes() {
        return !!datosMix().por_mes;
    }

    function modoCanal(canal) {
        return (datosMix().modos || {})[canal] || 'CONSTANTE';
    }

    /** El mes que se ve de un canal: null en Constante, o si el canal no es variable */
    function mesDe(canal) {
        return (mixPorMes() && modoCanal(canal) === 'VARIABLE' && mesMix[canal]) ? mesMix[canal] : null;
    }

    /** Lo que el payload dice de un mes: cargado, quién y cuándo, y los valores */
    function infoMes(canal, mes) {
        return datosMix().meses[canal][mes];
    }

    function nombreMes(mes) {
        return datosMix().nombres_mes[mes - 1];
    }

    /** Los meses cargados de un canal, en orden */
    function mesesCargados(canal) {
        var v = [];

        for (var m = 1; m <= 12; m++) {
            if (infoMes(canal, m).cargado) { v.push(m); }
        }

        return v;
    }

    /**
     * Los nodos de un canal en el orden del árbol, con lo que resolvió la
     * regla. En un mes, los valores son los del mes; con `desdeConstante`, los
     * del constante (Copiar del constante).
     */
    function nodosDeCanal(canal, desdeConstante) {
        var resueltos = vistaInicial(canal).nodos;
        var mes = mesDe(canal);
        var crudos = {};

        datosMix().nodos.forEach(function(n) {
            crudos[n.clave] = (mes && !desdeConstante)
                ? Object.assign({}, n, infoMes(canal, mes).valores[n.clave] || {})
                : n;
        });

        return Object.keys(resueltos)
            .filter(function(k) { return resueltos[k].canal === canal; })
            .map(function(k) { return { crudo: crudos[k], resuelto: resueltos[k] }; });
    }

    /**
     * La vista inicial de un canal, armada del payload. Un mes cargado viene
     * ya resuelto (vistas_mes); uno sin cargar se ve como el constante.
     */
    function vistaInicial(canal) {
        var mes = mesDe(canal);
        var delMes = mes && (datosMix().vistas_mes || {})[canal] && datosMix().vistas_mes[canal][mes];

        if (delMes) {
            return Object.assign({}, delMes, { cambios: 0 });
        }

        var r = datosMix().resuelto;
        var nodos = {};

        Object.keys(r.nodos).forEach(function(k) {
            if (r.nodos[k].canal === canal) {
                nodos[k] = r.nodos[k];
            }
        });

        var errores = (datosMix().estructura || [])
            .filter(function(e) { return e.canal === canal; })
            .map(function(e) { return e.texto; })
            .concat(r.canales[canal].errores.map(function(e) { return e.texto; }));

        return {
            canal: canal,
            valido: errores.length === 0,
            errores: errores,
            nodos: nodos,
            grupos: r.canales[canal].grupos,
            cambios: 0
        };
    }

    function generarMix() {
        var mix = datosMix();
        var editable = mixEditable();
        var html = '';

        vistaMix = {};
        mixPendiente = {};
        mixSucio = {};
        apagarEnMeses = {};

        pintarAvisosMix();

        mix.canales.forEach(function(canal) {
            html += htmlCanal(canal, editable, false);
        });

        document.getElementById('mixBody').innerHTML = html;

        mix.canales.forEach(pintarVistaMix);
        pintarLeyendaMix();
        generarExportMix();
    }

    /**
     * Las filas de un canal: su encabezado, la fila de meses, sus errores y
     * sus nodos. Todas llevan data-bloque, para redibujar el canal solo —al
     * cambiar de mes— sin perder lo que se está editando en los otros.
     */
    function htmlCanal(canal, editable, desdeConstante) {
        var enMes = mesDe(canal) !== null;
        var html = '';

        vistaMix[canal] = vistaInicial(canal);
        mixPendiente[canal] = false;
        mixSucio[canal] = false;

        html += filaCanalMix(canal, editable);
        html += filaMesesMix(canal, editable);
        html += '<tr class="mixa-errores" data-errores="' + canal + '" data-bloque="' + canal + '" ' +
                    'style="display: none;"><td colspan="8"></td></tr>';

        nodosDeCanal(canal, desdeConstante).forEach(function(n) {
            html += filaNodoMix(n.crudo, n.resuelto, editable, enMes);
        });

        return html;
    }

    /** Redibuja un canal en su lugar, con el mes que tenga elegido */
    function redibujarCanal(canal, desdeConstante) {
        var cuerpo = document.getElementById('mixBody');
        var filas = cuerpo.querySelectorAll('tr[data-bloque="' + canal + '"]');

        cerrarAltaMix();

        if (!filas.length) {
            return;
        }

        filas[0].insertAdjacentHTML('beforebegin', htmlCanal(canal, mixEditable(), desdeConstante));
        filas.forEach(function(tr) { tr.remove(); });

        pintarVistaMix(canal);
        pintarLeyendaMix();
    }

    /** Si hay cambios sin guardar en el canal, pregunta antes de perderlos */
    function descartarCambios(canal, que) {
        if (!mixSucio[canal]) {
            return Promise.resolve(true);
        }

        return Notificacion.confirmar({
            mensaje: 'Hay cambios sin guardar en ' + titulo(canal) + '.',
            detalle: que + ' Esos cambios se pierden. Guardalos antes si los querés.',
            confirmar: 'Seguir igual'
        });
    }

    /**
     * La leyenda de colores, una para todos los canales: los tipos de los
     * nodos que se ven —inactivos incluidos, que también llevan su color—, en
     * el orden de NIVELES. Se recalcula al cambiar un nivel: el tipo nuevo
     * puede no estar, y el viejo puede quedar sin uso.
     */
    function pintarLeyendaMix() {
        var cont = document.getElementById('mixLeyenda');

        if (!cont) {
            return;
        }

        var codigos = [];

        document.querySelectorAll('#mixBody tr.mixa-nodo').forEach(function(tr) {
            codigos.push(tr.dataset.nivel);
        });

        cont.innerHTML = MixNiveles.leyenda(datosMix().niveles, codigos);
        cont.style.display = cont.innerHTML ? '' : 'none';
    }

    /** El aviso de qué script falta, si falta */
    function pintarAvisosMix() {
        var cont = document.getElementById('mixAvisos');

        if (!cont) {
            return;
        }

        // El panel plegable de todas las pestañas: ver Js/avisos-panel.js. El
        // contenedor se esconde sin avisos para no dejar su relleno vacío.
        AvisosPanel.pintar(cont, modulo.avisos || []);

        cont.style.display = (modulo.avisos || []).length ? '' : 'none';
    }

    function filaCanalMix(canal, editable) {
        var acciones = '';
        var mes = mesDe(canal);

        if (editable) {
            // Agregar es estructura: sólo en el constante
            acciones = (mes === null
                    ? '<button type="button" class="btn btn-sm btn-outline-primary me-2" ' +
                          'data-agregar="" data-canal="' + canal + '" ' +
                          'title="Agregar un nodo al primer nivel de ' + titulo(canal) + '">' +
                          '<i class="fas fa-plus me-1"></i> Agregar</button>'
                    : '') +
                       '<button type="button" class="btn btn-sm btn-primary" ' +
                           'data-guardar-canal="' + canal + '" disabled>' +
                           '<i class="fas fa-floppy-disk me-1"></i> Guardar ' +
                           (mes === null ? titulo(canal) : nombreMes(mes) + ' de ' + titulo(canal)) + '</button>';
        }

        return '<tr class="mixa-canal" data-canal-fila="' + canal + '" data-bloque="' + canal + '">' +
                   '<td colspan="2"><strong>' + titulo(canal) + '</strong> ' +
                       '<span class="mixa-suma" data-suma="' + canal + '"></span>' +
                       controlModoMix(canal, editable) + '</td>' +
                   '<td colspan="6" class="text-end">' +
                       '<small class="text-muted me-2" data-estado="' + canal + '"></small>' + acciones +
                   '</td>' +
               '</tr>';
    }

    /**
     * Constante / Variable. Con permiso, dos botones; sin permiso, el modo
     * como etiqueta. Sin el script del mix por mes, nada: todo es constante.
     */
    function controlModoMix(canal, editable) {
        if (!mixPorMes()) {
            return '';
        }

        var modo = modoCanal(canal);
        var guardados = mesesCargados(canal).length;
        var nota = (modo === 'CONSTANTE' && guardados)
            ? ' <small class="text-muted ms-1" title="Quedan guardados: vuelven si el canal se pasa a Variable">' +
              guardados + ' mes(es) con mix propio guardado(s), sin usar</small>'
            : '';

        if (!editable) {
            return ' <span class="badge mixa-modo-etiqueta ms-2">Mix ' +
                   (modo === 'VARIABLE' ? 'variable' : 'constante') + '</span>' + nota;
        }

        return ' <div class="btn-group btn-group-sm ms-2 mixa-modo" role="group" ' +
                   'aria-label="Mix de ' + titulo(canal) + '">' +
               [['CONSTANTE', 'Constante', 'El mismo mix todos los meses'],
                ['VARIABLE', 'Variable', 'Un mix propio para los meses que se carguen; el resto usa el constante']]
                   .map(function(o) {
                       var activo = o[0] === modo;

                       return '<button type="button" class="btn ' +
                              (activo ? 'btn-secondary' : 'btn-outline-secondary') + '" ' +
                              'data-modo="' + o[0] + '" data-canal="' + canal + '" ' +
                              'aria-pressed="' + activo + '" title="' + o[2] + '">' + o[1] + '</button>';
                   }).join('') +
               '</div>' + nota;
    }

    /**
     * La fila de meses de un canal en Variable: Constante · Ene … Dic, con
     * los meses que tienen mix propio marcados, y lo que se puede hacer con
     * el mes elegido. Sin permiso se eligen meses igual: se ve el árbol de
     * cada uno, sin controles.
     */
    function filaMesesMix(canal, editable) {
        if (!mixPorMes() || modoCanal(canal) !== 'VARIABLE') {
            return '';
        }

        var mes = mesDe(canal);
        var botones = '<button type="button" class="btn btn-sm ' +
                      (mes === null ? 'btn-primary' : 'btn-outline-primary') + '" ' +
                      'data-ver-mes="" data-canal="' + canal + '" ' +
                      'title="El mix que usan los meses sin mix propio. Acá se edita la estructura.">' +
                      'Constante</button>';

        for (var m = 1; m <= 12; m++) {
            var info = infoMes(canal, m);
            var clase = m === mes ? 'btn-primary'
                : (info.cargado ? 'btn-outline-primary mixa-mes-propio' : 'btn-outline-secondary mixa-mes-constante');
            var tit = nombreMes(m) + (info.cargado
                ? ': mix propio' + (info.usuario_modif
                    ? ' (' + Auditoria.linea('modif', info.usuario_modif, info.fecha_modif) + ')' : '')
                : ': usa el constante');

            botones += '<button type="button" class="btn btn-sm ' + clase + '" data-ver-mes="' + m + '" ' +
                       'data-canal="' + canal + '" title="' + escapar(tit) + '" aria-pressed="' + (m === mes) + '">' +
                       (info.cargado ? '<i class="fas fa-circle mixa-punto me-1" aria-hidden="true"></i>' : '') +
                       nombreMes(m).slice(0, 3) +
                       '<span class="visually-hidden">' + (info.cargado ? ' (mix propio)' : ' (constante)') + '</span>' +
                       '</button>';
        }

        var cargado = mes !== null && infoMes(canal, mes).cargado;
        var nota = mes === null
            ? 'Mix constante: lo usan los meses sin mix propio. Los nombres, los niveles y los nodos nuevos se editan acá.'
            : (cargado
                ? nombreMes(mes) + ' tiene mix propio: vale para todos los ' + nombreMes(mes).toLowerCase() +
                  ' del horizonte. Los nombres y los niveles son los del constante.'
                : nombreMes(mes) + ' usa el mix constante: lo que ves son sus valores. Cambiá algo y guardá ' +
                  'para que tenga mix propio.');
        var acciones = '';

        if (editable && mes !== null) {
            acciones =
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-copiar-constante="' + canal + '" ' +
                    'title="Pone en pantalla los valores del mix constante. No se guarda hasta que guardes.">' +
                    '<i class="fas fa-clone me-1"></i> Copiar del constante</button>' +
                (cargado
                    ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-copiar-meses="' + canal + '" ' +
                          'title="Copia lo guardado de ' + nombreMes(mes) + ' a los meses que elijas">' +
                          '<i class="fas fa-copy me-1"></i> Copiar a otros meses…</button>' +
                      '<button type="button" class="btn btn-sm btn-outline-danger" data-quitar-mes="' + canal + '" ' +
                          'title="' + nombreMes(mes) + ' vuelve al mix constante. Sus valores quedan guardados.">' +
                          '<i class="fas fa-rotate-left me-1"></i> Usar el constante en este mes</button>'
                    : '');
        }

        return '<tr class="mixa-meses" data-bloque="' + canal + '"><td colspan="8">' +
                   '<div class="d-flex flex-wrap align-items-center gap-2">' +
                       '<div class="btn-group btn-group-sm flex-wrap mixa-meses-botones" role="group" ' +
                           'aria-label="Mes del mix de ' + titulo(canal) + '">' + botones + '</div>' +
                       '<small class="text-muted mixa-meses-leyenda">' +
                           '<i class="fas fa-circle mixa-punto" aria-hidden="true"></i> mix propio · ' +
                           'sin marca: usa el constante</small>' +
                       (acciones ? '<div class="d-flex flex-wrap gap-2 ms-auto">' + acciones + '</div>' : '') +
                   '</div>' +
                   '<div class="param-hint mt-1">' + escapar(nota) + '</div>' +
               '</td></tr>';
    }

    /**
     * Una fila de nodo. Con permiso, inputs; sin permiso o sin el script,
     * texto con el valor en data-valor.
     *
     * El camino completo va en un texto que la pantalla no muestra
     * (visually-hidden): la exportación lo baja, así cada fila del Excel dice
     * de qué rama es.
     *
     * La fila lleva la clase de su TIPO de nivel (Js/mix-niveles.js): la barra
     * del nombre y el desplegable de Nivel toman el color de ahí. Es por tipo
     * y no por sangría porque se pueden saltear niveles. data-nivel es el
     * nivel que se ve, y de ahí sale la leyenda.
     *
     * EN UN MES (enMes), el nombre y el nivel van como texto y no hay "+":
     * un mes carga valores, la estructura es la del constante.
     */
    function filaNodoMix(n, r, editable, enMes) {
        var camino = titulo(n.CANAL) + ' › ' +
            r.camino.slice(0, -1).map(function(c) { return c.nombre; }).concat(['']).join(' › ');
        var sangria = '<span class="mixa-sangria" style="width: ' + (r.profundidad * 20) + 'px"></span>';
        var pct = function(v) { return (v === null || v === undefined) ? '' : (v * 100).toFixed(2); };
        var estructura = editable && !enMes;
        var html = '<tr class="mixa-nodo ' + MixNiveles.clase(n.NIVEL) + '" data-id="' + n.ID + '" ' +
                   'data-clave="' + n.clave + '" data-canal="' + n.CANAL + '" data-bloque="' + n.CANAL + '" ' +
                   'data-padre="' + (r.padre || '') + '" data-nivel="' + escapar(n.NIVEL) + '">';

        // ---- Nombre ---------------------------------------------------
        // La barra va después de la sangría, pegada al nombre: así se lee la
        // jerarquía y el tipo a la vez.
        html += '<td class="mixa-nombre"><span class="visually-hidden">' + escapar(camino) + '</span>' +
                sangria + MixNiveles.barra();

        if (estructura) {
            html += '<input type="text" class="form-control form-control-sm d-inline-block mixa-campo" ' +
                    'data-campo="nombre" maxlength="50" value="' + escapar(n.NOMBRE) + '">';
        } else {
            html += escapar(n.NOMBRE);
        }

        if (n.tiene_hijos) {
            html += ' <span class="mixa-suma" data-suma="' + n.clave + '"></span>';
        }

        if (estructura && n.niveles_hijo.length) {
            html += ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 mixa-mas" ' +
                    'data-agregar="' + n.ID + '" data-canal="' + n.CANAL + '" ' +
                    'title="Agregar un nodo debajo de ' + escapar(n.NOMBRE) + '">' +
                    '<i class="fas fa-plus"></i></button>';
        }

        html += '</td>';

        // ---- Nivel ----------------------------------------------------
        // Con hijos no se cambia: sus hijos quedarían con un nivel que quizás
        // ya no es posterior. Si quedó mal, se inhabilita y se crea otro.
        // El desplegable toma el color de la fila (mix-nivel-select); sin
        // permiso, el rótulo va en una etiqueta del mismo color. En los dos
        // casos el rótulo se lee: el color nunca es la única señal.
        if (estructura) {
            var opciones = n.niveles_permitidos.indexOf(n.NIVEL) === -1
                ? [n.NIVEL].concat(n.niveles_permitidos) : n.niveles_permitidos;

            html += '<td><select class="form-select form-select-sm mixa-campo mix-nivel-select" data-campo="nivel"' +
                    (n.tiene_hijos ? ' disabled title="Tiene nodos debajo: su nivel no se cambia"' : '') + '>' +
                    opciones.map(function(cod) {
                        return '<option value="' + cod + '"' + (cod === n.NIVEL ? ' selected' : '') + '>' +
                               escapar(rotuloNivel(cod)) + '</option>';
                    }).join('') + '</select></td>';
        } else {
            html += '<td>' + MixNiveles.etiqueta(n.NIVEL, rotuloNivel(n.NIVEL)) + '</td>';
        }

        // ---- %, costo, tasa y días --------------------------------------
        html += celdaNumeroMix('porcentaje', pct(n.PORCENTAJE), '%', editable, '0.01', '');
        html += celdaNumeroMix('costo', pct(n.COSTO), '%', editable, '0.01',
                               '<div class="mixa-hereda" data-hint-carga="' + n.clave + '"></div>');
        html += celdaNumeroMix('tasa', pct(n.TASA), '%', editable, '0.01', '');
        html += celdaNumeroMix('dias', n.DIAS_ACREDITACION === null ? '' : String(n.DIAS_ACREDITACION),
                               'días', editable, '1',
                               '<div class="mixa-hereda" data-hint-dias="' + n.clave + '"></div>');

        // ---- Activo ---------------------------------------------------
        var activo = parseInt(n.ACTIVO, 10) === 1;

        html += '<td class="text-center">' + (editable
            ? '<div class="form-check form-switch d-inline-block">' +
                '<input class="form-check-input mixa-campo" type="checkbox" role="switch" ' +
                    'data-campo="activo"' + (activo ? ' checked' : '') +
                    ' title="Inhabilitado, el nodo y todo lo que cuelga de él salen de la proyección">' +
              '</div>'
            : (activo ? 'Sí' : 'No')) + '</td>';

        // ---- Última edición -------------------------------------------
        // En un mes, la del valor del mes; en uno sin cargar, la del constante
        // con la aclaración, para que no se lea como una edición del mes.
        html += '<td><small class="text-muted">' +
                (enMes && n.propio === false ? 'del constante: ' : '') +
                escapar(Auditoria.linea('modif', n.USUARIO_MODIF, n.FECHA_MODIF)) + '</small></td>';

        return html + '</tr>';
    }

    function celdaNumeroMix(campo, valor, unidad, editable, paso, debajo) {
        if (!editable) {
            return '<td class="text-end">' + (valor === '' ? '<span class="text-muted">—</span>'
                : escapar(valor.replace('.', ',')) + (unidad === '%' ? '%'
                    : ' ' + (valor === '1' ? unidad.replace(/s$/, '') : unidad))) + debajo + '</td>';
        }

        return '<td>' +
                   '<div class="input-group input-group-sm">' +
                       '<input type="number" step="' + paso + '" min="0" class="form-control mixa-campo mix-input" ' +
                           'data-campo="' + campo + '" value="' + valor + '">' +
                       '<span class="input-group-text">' + unidad + '</span>' +
                   '</div>' + debajo +
               '</td>';
    }

    function rotuloNivel(codigo) {
        var n = datosMix().niveles.filter(function(x) { return x.codigo === codigo; })[0];

        return n ? n.rotulo : codigo;
    }

    /**
     * Pinta lo que resolvió el servidor para un canal: los valores heredados
     * en gris, la suma de cada grupo de hermanos, los nodos fuera de juego,
     * los errores y el estado de Guardar.
     */
    function pintarVistaMix(canal) {
        var v = vistaMix[canal];
        var cuerpo = document.getElementById('mixBody');

        Object.keys(v.nodos).forEach(function(clave) {
            var r = v.nodos[clave];
            var tr = cuerpo.querySelector('tr[data-clave="' + clave + '"]');

            if (!tr) { return; }

            // Fuera de juego: inactivo él o algún nodo de arriba
            tr.classList.toggle('mix-inactivo', !r.en_juego);

            var carga = cuerpo.querySelector('[data-hint-carga="' + clave + '"]');

            if (carga) {
                carga.textContent = r.carga > 0
                    ? 'rama: ' + formatPercent(r.carga) +
                      (r.tasa > 0 ? ' (costo ' + formatPercent(r.costo) + ' + tasa ' + formatPercent(r.tasa) + ')' : '')
                    : 'rama: sin costo';
            }

            var dias = cuerpo.querySelector('[data-hint-dias="' + clave + '"]');

            if (dias) {
                dias.classList.remove('mixa-falta');

                if (r.dias_propios !== null) {
                    dias.textContent = '';
                } else if (r.dias !== null) {
                    dias.textContent = 'hereda ' + r.dias + ' de ' + r.dias_de.nombre;
                } else {
                    dias.textContent = r.hoja ? 'sin días en toda la rama' : '';
                    dias.classList.toggle('mixa-falta', r.hoja);
                }
            }
        });

        // ---- Las sumas de cada grupo ---------------------------------------
        v.grupos.forEach(function(g) {
            var span = cuerpo.querySelector('[data-suma="' + (g.padre || canal) + '"]');

            if (!span) { return; }

            span.textContent = 'Σ ' + formatPercent(g.suma);
            span.className = 'mixa-suma ' + (!g.en_juego ? 'mixa-suma-fuera'
                : (g.activos === 0 ? 'mixa-suma-vacia' : (g.valido ? 'mixa-suma-ok' : 'mixa-suma-mal')));
            span.title = !g.en_juego
                ? 'Esta rama está fuera de juego: su suma no se valida'
                : (g.activos === 0
                    ? 'Sin nodos activos debajo: ' + (g.padre ? 'este nodo es una hoja' : 'el canal no cobra')
                    : (g.valido ? 'Los activos suman 100%' : 'Los activos de ' + g.rama + ' tienen que sumar 100%'));
        });

        // ---- Errores --------------------------------------------------------
        var filaErr = cuerpo.querySelector('[data-errores="' + canal + '"]');

        if (filaErr) {
            filaErr.style.display = v.errores.length ? '' : 'none';
            filaErr.cells[0].innerHTML = v.errores.length
                ? '<div class="alert alert-danger py-2 px-3 mb-0"><small>' +
                  v.errores.map(function(e) {
                      return '<div><i class="fas fa-circle-exclamation me-1"></i>' + escapar(e) + '</div>';
                  }).join('') + '</small></div>'
                : '';
        }

        actualizarGuardarMix(canal);
    }

    /** Guardar se habilita sólo con todo resuelto, válido y algo que guardar */
    function actualizarGuardarMix(canal) {
        var btn = document.querySelector('[data-guardar-canal="' + canal + '"]');
        var estado = document.querySelector('[data-estado="' + canal + '"]');
        var v = vistaMix[canal];
        var motivo = '';

        if (mixPendiente[canal]) {
            motivo = 'Revisando los cambios…';
        } else if (!v.valido) {
            motivo = 'No se puede guardar: ' + v.errores.length + ' problema(s), abajo.';
        } else if (!mixSucio[canal]) {
            motivo = '';
        }

        if (estado) {
            estado.textContent = motivo || (mixSucio[canal] ? 'Hay cambios sin guardar.' : '');
        }

        if (btn) {
            btn.disabled = !!mixPendiente[canal] || !v.valido || !mixSucio[canal];
            btn.title = motivo || (mixSucio[canal] ? '' : 'No hay cambios');
        }
    }

    /** Lo que hay en pantalla para un canal, como lo espera el servidor */
    function nodosEnPantalla(canal) {
        var lista = [];

        document.querySelectorAll('#mixBody tr.mixa-nodo[data-canal="' + canal + '"]').forEach(function(tr) {
            var leer = function(campo) {
                var el = tr.querySelector('[data-campo="' + campo + '"]');

                return el ? (el.type === 'checkbox' ? el.checked : el.value) : undefined;
            };
            // Los porcentajes se tipean en %, y el servidor los guarda en fracción.
            // Vacío viaja vacío: para el costo, la tasa y los días es "null".
            var fraccion = function(v) {
                return (v === '' || v === undefined) ? v : (parseFloat(v) / 100);
            };

            lista.push({
                id: parseInt(tr.dataset.id, 10),
                nombre: leer('nombre'),
                nivel: leer('nivel'),
                porcentaje: fraccion(leer('porcentaje')),
                costo: fraccion(leer('costo')),
                tasa: fraccion(leer('tasa')),
                dias: leer('dias'),
                activo: leer('activo')
            });
        });

        return lista;
    }

    /** Pide al servidor cómo quedaría el canal. Las respuestas viejas se descartan */
    function previsualizarMix(canal) {
        clearTimeout(mixTimer[canal]);
        mixPendiente[canal] = true;
        actualizarGuardarMix(canal);

        mixTimer[canal] = setTimeout(function() {
            var numero = (mixPedido[canal] || 0) + 1;

            mixPedido[canal] = numero;

            pedir('Controller/ParametrosController.php?action=previsualizarMixArbol',
                  { canal: canal, mes: mesDe(canal), nodos: nodosEnPantalla(canal) })
                .then(function(vista) {
                    if (mixPedido[canal] !== numero) { return; }

                    vistaMix[canal] = vista;
                    mixPendiente[canal] = false;
                    pintarVistaMix(canal);
                })
                .catch(function(error) {
                    if (mixPedido[canal] !== numero) { return; }

                    // Sin respuesta no se sabe si el árbol es válido: Guardar
                    // sigue bloqueado y se dice por qué.
                    var previa = vistaMix[canal];

                    vistaMix[canal] = {
                        canal: canal, nodos: previa.nodos, grupos: previa.grupos, cambios: 0,
                        valido: false,
                        errores: ['No se pudo revisar el árbol: ' + error.message]
                    };
                    mixPendiente[canal] = false;
                    pintarVistaMix(canal);
                });
        }, 250);
    }

    /**
     * Inhabilitar un nodo con ramas debajo saca de la proyección todas sus
     * hojas: se pregunta antes, diciendo cuántas.
     *
     * EN EL CONSTANTE, si el nodo sigue activo en meses con mix propio, se
     * pregunta siempre: el diálogo dice en cuántos y ofrece la casilla
     * "Inhabilitarlo también en esos meses", tildada. La respuesta queda en
     * apagarEnMeses y viaja con el guardado; el servidor valida cada mes como
     * quedaría y, si alguno queda mal, no guarda nada y dice cuál.
     */
    function confirmarInhabilitar(chk, canal) {
        var tr = chk.closest('tr');
        var clave = tr.dataset.clave;
        var id = parseInt(tr.dataset.id, 10);
        var v = vistaMix[canal];
        var hojas = Object.keys(v.nodos).filter(function(k) {
            var n = v.nodos[k];

            return n.hoja && k !== clave && n.camino.some(function(c) { return c.clave === clave; });
        }).length;
        var nombre = v.nodos[clave] ? v.nodos[clave].nombre : clave;
        var activoEn = mesDe(canal) !== null ? [] : mesesCargados(canal).filter(function(m) {
            var val = infoMes(canal, m).valores[clave];

            return val && parseInt(val.ACTIVO, 10) === 1;
        });

        apagarEnMeses[canal] = (apagarEnMeses[canal] || []).filter(function(x) { return x !== id; });

        var detalle = (hojas
            ? 'Saca ' + hojas + ' hoja(s) de la proyección. '
            : '') + 'No se borra nada: se puede volver a habilitar. Después hay que reacomodar '
            + 'los porcentajes de su grupo para que vuelvan a sumar 100%.';

        if (activoEn.length) {
            return Notificacion.elegirOpciones({
                titulo: 'Inhabilitar ' + nombre,
                mensaje: '¿Inhabilitar "' + nombre + '"' + (hojas ? ' y todo lo que cuelga de él?' : '?'),
                detalle: detalle + ' En el mix constante. Sigue activo en ' + activoEn.length
                    + ' mes(es) con mix propio: ' + activoEn.map(nombreMes).join(', ') + '.',
                opciones: [{ valor: 'meses', rotulo: 'Inhabilitarlo también en esos meses', marcada: true }],
                confirmar: 'Inhabilitar',
                peligro: true
            }).then(function(r) {
                if (r === null) {
                    return false;
                }

                if (r.indexOf('meses') !== -1) {
                    apagarEnMeses[canal].push(id);
                }

                return true;
            });
        }

        if (hojas === 0) {
            return Promise.resolve(true);
        }

        return Notificacion.confirmar({
            titulo: 'Inhabilitar ' + nombre,
            mensaje: '¿Inhabilitar "' + nombre + '" y todo lo que cuelga de él?',
            detalle: detalle,
            confirmar: 'Inhabilitar',
            peligro: true
        });
    }

    function alCambiarMix(e) {
        // El nivel del formulario de alta: sólo cambia su color, todavía no
        // es un nodo y no hay nada que previsualizar.
        var alta = e.target.closest('[data-alta="nivel"]');

        if (alta) {
            alta.className = MixNiveles.conClase(alta.className, alta.value);
            return;
        }

        var el = e.target.closest('.mixa-campo');

        if (!el) { return; }

        var tr = el.closest('tr');
        var canal = tr.dataset.canal;

        mixSucio[canal] = true;

        // Otro nivel es otro color, en el momento: no depende de la respuesta
        // del servidor, que dice si el árbol es válido, no de qué tipo es.
        if (el.dataset.campo === 'nivel') {
            tr.className = MixNiveles.conClase(tr.className, el.value);
            tr.dataset.nivel = el.value;
            pintarLeyendaMix();
        }

        if (el.dataset.campo === 'activo' && !el.checked) {
            confirmarInhabilitar(el, canal).then(function(ok) {
                if (!ok) {
                    el.checked = true;
                }

                previsualizarMix(canal);
            });

            return;
        }

        previsualizarMix(canal);
    }

    /** Mientras se tipea, Guardar se bloquea: el valor todavía no se revisó */
    function alTipearMix(e) {
        var el = e.target.closest('.mixa-campo');

        if (!el || el.type === 'checkbox' || el.tagName === 'SELECT') { return; }

        var canal = el.closest('tr').dataset.canal;

        mixSucio[canal] = true;
        mixPendiente[canal] = true;
        actualizarGuardarMix(canal);
    }

    function guardarMixCanal(canal) {
        if (mixPendiente[canal] || !vistaMix[canal].valido) {
            return;
        }

        var btn = document.querySelector('[data-guardar-canal="' + canal + '"]');
        var textoOriginal = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        var mes = mesDe(canal);
        // Los nodos tildados para apagar también en los meses, si siguen apagados
        var enMeses = (apagarEnMeses[canal] || []).filter(function(id) {
            var chk = document.querySelector('#mixBody tr.mixa-nodo[data-id="' + id + '"] [data-campo="activo"]');

            return chk && !chk.checked;
        });

        pedir('Controller/ParametrosController.php?action=saveMixArbol',
              { canal: canal, mes: mes, nodos: nodosEnPantalla(canal), inhabilitar_en_meses: enMeses })
            .then(function() {
                Notificacion.exito('Mix de ' + (mes ? nombreMes(mes) + ' de ' : '') + titulo(canal) + ' guardado.',
                    enMeses.length ? { detalle: 'También se inhabilitó en los meses con mix propio.' } : undefined);
                cargar();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo guardar el mix: ' + error.message);
                btn.innerHTML = textoOriginal;
                actualizarGuardarMix(canal);
            });
    }

    /* ---- Alta -------------------------------------------------------- */

    /**
     * El formulario de alta, en una fila debajo del padre (o del canal).
     * No pide porcentaje: lo nuevo entra INHABILITADO Y EN 0%, así no rompe
     * el 100% de su grupo. Los niveles que ofrece son los que el servidor dice
     * que admite ese lugar.
     */
    function abrirAltaMix(btn) {
        var canal = btn.dataset.canal;
        var idPadre = btn.dataset.agregar;
        var mix = datosMix();
        var padre = idPadre ? mix.nodos.filter(function(n) { return String(n.ID) === idPadre; })[0] : null;
        var niveles = padre ? padre.niveles_hijo : mix.niveles_primer[canal];
        var ancla = btn.closest('tr');

        cerrarAltaMix();

        var tr = document.createElement('tr');

        tr.className = 'mixa-alta';
        tr.dataset.canal = canal;
        tr.innerHTML =
            '<td colspan="8">' +
                '<div class="mixa-alta-titulo">Agregar ' +
                    (padre ? 'debajo de <strong>' + escapar(padre.NOMBRE) + '</strong>'
                           : 'al primer nivel de <strong>' + titulo(canal) + '</strong>') + '</div>' +
                '<div class="row g-2 align-items-end">' +
                    '<div class="col-md-3"><label class="form-label form-label-sm">Nombre</label>' +
                        '<input type="text" class="form-control form-control-sm" data-alta="nombre" maxlength="50"></div>' +
                    // El desplegable lleva el color del tipo elegido, con su
                    // propia clase: la fila del alta no es un nodo. Las
                    // opciones van con su rótulo y sin color, que pintarlas
                    // depende del navegador.
                    '<div class="col-md-2"><label class="form-label form-label-sm">Nivel</label>' +
                        '<select class="form-select form-select-sm mix-nivel-select ' +
                            MixNiveles.clase(niveles[0]) + '" data-alta="nivel">' +
                        niveles.map(function(cod) {
                            return '<option value="' + cod + '">' + escapar(rotuloNivel(cod)) + '</option>';
                        }).join('') + '</select></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm">Costo %</label>' +
                        '<input type="number" step="0.01" min="0" class="form-control form-control-sm" data-alta="costo"></div>' +
                    '<div class="col-md-1"><label class="form-label form-label-sm">Tasa %</label>' +
                        '<input type="number" step="0.01" min="0" class="form-control form-control-sm" data-alta="tasa"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm">Días</label>' +
                        '<input type="number" step="1" min="0" class="form-control form-control-sm" data-alta="dias" ' +
                        'placeholder="vacío: los de arriba"></div>' +
                    '<div class="col-md-2 d-flex gap-2">' +
                        '<button type="button" class="btn btn-sm btn-primary flex-fill" data-alta-confirmar>' +
                            '<i class="fas fa-check me-1"></i> Agregar</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-alta-cancelar>' +
                            '<i class="fas fa-xmark"></i></button>' +
                    '</div>' +
                '</div>' +
                '<div class="param-hint mt-2">Entra <strong>inhabilitado y en 0%</strong>, así no rompe ' +
                    'el 100% de su grupo. Para usarlo, encendelo y reacomodá los porcentajes de sus hermanos.</div>' +
            '</td>';

        tr.dataset.idPadre = idPadre || '';
        ancla.parentNode.insertBefore(tr, ancla.nextSibling);
        tr.querySelector('[data-alta="nombre"]').focus();
    }

    function cerrarAltaMix() {
        document.querySelectorAll('#mixBody tr.mixa-alta').forEach(function(tr) { tr.remove(); });
    }

    function confirmarAltaMix(tr) {
        var canal = tr.dataset.canal;
        var valor = function(c) { return tr.querySelector('[data-alta="' + c + '"]').value; };
        var fraccion = function(v) { return v === '' ? '' : parseFloat(v) / 100; };
        var nombre = valor('nombre').trim();

        if (!nombre) {
            Notificacion.campoInvalido(tr.querySelector('[data-alta="nombre"]'), 'Ingresá el nombre.');
            return;
        }

        // El alta recarga el mix: lo que se editó y no se guardó se perdería.
        var seguir = mixSucio[canal]
            ? Notificacion.confirmar({
                mensaje: 'Hay cambios sin guardar en ' + titulo(canal) + '.',
                detalle: 'Agregar recarga el mix y esos cambios se pierden. Guardalos antes si los querés.',
                confirmar: 'Agregar igual'
              })
            : Promise.resolve(true);

        seguir.then(function(ok) {
            if (!ok) { return; }

            var btn = tr.querySelector('[data-alta-confirmar]');

            btn.disabled = true;

            pedir('Controller/ParametrosController.php?action=addMixNodo', {
                canal: canal,
                id_padre: tr.dataset.idPadre || null,
                nombre: nombre,
                nivel: valor('nivel'),
                costo: fraccion(valor('costo')),
                tasa: fraccion(valor('tasa')),
                dias: valor('dias')
            })
            .then(function() {
                Notificacion.exito('"' + nombre + '" agregado a ' + titulo(canal) + '.', {
                    detalle: 'Queda inhabilitado y en 0%. Encendelo y reacomodá los porcentajes de su '
                           + 'grupo para que vuelvan a sumar 100%.'
                });
                cargar();
            })
            .catch(function(error) {
                Notificacion.error('No se pudo agregar: ' + error.message);
                btn.disabled = false;
            });
        });
    }

    /* ---- El mix por mes ---------------------------------------------- */

    /** Constante / Variable: se guarda en el momento, con confirmación */
    function cambiarModoMix(canal, modo) {
        if (modo === modoCanal(canal)) {
            return;
        }

        var cargados = mesesCargados(canal);
        var aVariable = modo === 'VARIABLE';
        var detalle = aVariable
            ? (cargados.length
                ? 'Los meses con mix propio (' + cargados.map(nombreMes).join(', ') + ') pasan a usar el suyo, '
                  + 'y la proyección de esos meses cambia. El resto sigue con el constante.'
                : 'Todavía no hay ningún mes con mix propio, así que la proyección no cambia hasta que '
                  + 'cargues alguno.')
            : (cargados.length
                ? 'Todos los meses vuelven al mix constante y la proyección de '
                  + cargados.map(nombreMes).join(', ') + ' cambia. Los meses cargados no se borran: '
                  + 'vuelven si pasás ' + titulo(canal) + ' otra vez a Variable.'
                : 'No hay meses con mix propio, así que la proyección no cambia.');

        descartarCambios(canal, 'Cambiar el modo recarga el mix.')
            .then(function(ok) {
                return ok && Notificacion.confirmar({
                    titulo: 'Mix de ' + titulo(canal),
                    mensaje: '¿Pasar ' + titulo(canal) + ' a mix ' + (aVariable ? 'variable' : 'constante') + '?',
                    detalle: detalle,
                    confirmar: aVariable ? 'Pasar a variable' : 'Pasar a constante'
                });
            })
            .then(function(ok) {
                if (!ok) { return; }

                return pedir('Controller/ParametrosController.php?action=saveMixModo',
                             { canal: canal, modo: modo })
                    .then(function() {
                        mesMix[canal] = null;
                        Notificacion.exito(titulo(canal) + ' pasa a mix ' + (aVariable ? 'variable' : 'constante') + '.');
                        cargar();
                    });
            })
            .catch(function(error) {
                Notificacion.error('No se pudo cambiar el modo: ' + error.message);
            });
    }

    /** Ver otro mes del canal (null = constante): redibuja sólo ese canal */
    function verMesMix(canal, mes) {
        if (mes === mesDe(canal)) {
            return;
        }

        descartarCambios(canal, 'Cambiar de mes muestra otros valores.').then(function(ok) {
            if (!ok) { return; }

            mesMix[canal] = mes;
            redibujarCanal(canal);
        });
    }

    /**
     * Pone en pantalla los valores del constante en el mes elegido. NO
     * GUARDA: queda como cualquier edición, revisada por el servidor y lista
     * para Guardar.
     */
    function copiarDelConstante(canal) {
        var mes = mesDe(canal);

        if (mes === null) { return; }

        descartarCambios(canal, 'Copiar del constante reemplaza lo que hay en pantalla.').then(function(ok) {
            if (!ok) { return; }

            redibujarCanal(canal, true);
            mixSucio[canal] = true;
            previsualizarMix(canal);
            Notificacion.info('Los valores del constante están en ' + nombreMes(mes) + '. Guardá para que queden.');
        });
    }

    /** Copia lo GUARDADO del mes elegido a los meses que se tilden */
    function copiarAOtrosMeses(canal) {
        var mes = mesDe(canal);

        if (mes === null) { return; }

        if (mixSucio[canal]) {
            Notificacion.advertencia('Guardá ' + nombreMes(mes) + ' antes de copiarlo: se copia lo guardado.');
            return;
        }

        var opciones = [];

        for (var m = 1; m <= 12; m++) {
            if (m !== mes) {
                opciones.push({
                    valor: m,
                    rotulo: nombreMes(m),
                    nota: infoMes(canal, m).cargado ? '(tiene mix propio: se pisa)' : ''
                });
            }
        }

        Notificacion.elegirOpciones({
            titulo: 'Copiar ' + nombreMes(mes) + ' de ' + titulo(canal),
            mensaje: '¿A qué meses copiás el mix de ' + nombreMes(mes) + '?',
            detalle: 'Cada mes elegido queda con exactamente los valores de ' + nombreMes(mes)
                + ', tenga o no mix propio. La proyección de esos meses cambia.',
            opciones: opciones,
            enColumnas: true,
            minimo: 1,
            invalido: 'Elegí al menos un mes.',
            confirmar: 'Copiar'
        }).then(function(destinos) {
            if (destinos === null) { return; }

            return pedir('Controller/ParametrosController.php?action=copiarMixMes',
                         { canal: canal, origen: mes, destinos: destinos })
                .then(function() {
                    Notificacion.exito(nombreMes(mes) + ' copiado a ' + destinos.map(nombreMes).join(', ') + '.');
                    cargar();
                });
        }).catch(function(error) {
            Notificacion.error('No se pudo copiar: ' + error.message);
        });
    }

    /** "Usar el constante en este mes": baja del mes, sin borrar sus valores */
    function usarConstanteEnMes(canal) {
        var mes = mesDe(canal);

        if (mes === null) { return; }

        descartarCambios(canal, 'El mes vuelve al constante.').then(function(ok) {
            return ok && Notificacion.confirmar({
                titulo: nombreMes(mes) + ' de ' + titulo(canal),
                mensaje: '¿Usar el mix constante en ' + nombreMes(mes) + '?',
                detalle: 'Mueve la proyección: las ventas de ' + nombreMes(mes).toLowerCase()
                    + ' se cobran con el mix constante. Los valores de ' + nombreMes(mes)
                    + ' quedan guardados: si lo volvés a cargar, se reactivan.',
                confirmar: 'Usar el constante',
                peligro: true
            });
        }).then(function(ok) {
            if (!ok) { return; }

            return pedir('Controller/ParametrosController.php?action=quitarMixMes', { canal: canal, mes: mes })
                .then(function() {
                    Notificacion.exito(nombreMes(mes) + ' vuelve a usar el mix constante.');
                    cargar();
                });
        }).catch(function(error) {
            Notificacion.error('No se pudo volver al constante: ' + error.message);
        });
    }

    /**
     * La tabla que baja Exportar, escondida: una fila por nodo del constante
     * y, en los canales en Variable, una por nodo y por cada uno de los doce
     * meses, diciendo si el mes tiene mix propio o usa el constante. Sale del
     * payload, así que baja lo GUARDADO, no lo que se está editando.
     */
    function generarExportMix() {
        var tabla = document.getElementById('tablaMixExport');

        if (!tabla) { return; }

        var mix = datosMix();
        var pct = function(v) {
            return (v === null || v === undefined || v === '') ? '' : (Number(v) * 100).toFixed(2).replace('.', ',') + '%';
        };
        var porClave = {};

        Object.keys(mix.resuelto.nodos).forEach(function(k) { porClave[k] = mix.resuelto.nodos[k]; });

        var crudos = {};

        mix.nodos.forEach(function(n) { crudos[n.clave] = n; });

        var fila = function(canal, mesTexto, origen, clave, v) {
            var r = porClave[clave];

            return '<tr><td>' + escapar(titulo(canal)) + '</td><td>' + escapar(mesTexto) + '</td><td>' +
                   escapar(origen) + '</td><td>' +
                   escapar(r.camino.map(function(c) { return c.nombre; }).join(' › ')) + '</td><td>' +
                   escapar(rotuloNivel(r.nivel)) + '</td><td>' + pct(v.PORCENTAJE) + '</td><td>' +
                   pct(v.COSTO) + '</td><td>' + pct(v.TASA) + '</td><td>' +
                   (v.DIAS_ACREDITACION === null || v.DIAS_ACREDITACION === undefined ? '' : v.DIAS_ACREDITACION) +
                   '</td><td>' + (parseInt(v.ACTIVO, 10) === 1 ? 'Sí' : 'No') + '</td></tr>';
        };
        var html = '<thead><tr><th>Canal</th><th>Mix</th><th>Origen</th><th>Camino</th><th>Nivel</th>' +
                   '<th>%</th><th>Costo %</th><th>Tasa %</th><th>Días</th><th>Activo</th></tr></thead><tbody>';

        mix.canales.forEach(function(canal) {
            var claves = Object.keys(porClave).filter(function(k) { return porClave[k].canal === canal; });

            claves.forEach(function(k) { html += fila(canal, 'Constante', 'Constante', k, crudos[k]); });

            if (mixPorMes() && modoCanal(canal) === 'VARIABLE') {
                for (var m = 1; m <= 12; m++) {
                    var info = infoMes(canal, m);

                    claves.forEach(function(k) {
                        html += fila(canal, nombreMes(m), info.cargado ? 'Mix propio' : 'Usa el constante', k,
                            info.valores[k] || crudos[k]);
                    });
                }
            }
        });

        tabla.innerHTML = html + '</tbody>';
    }

    /** Un solo juego de listeners en el tbody: el editor se redibuja entero al recargar */
    function engancharMix() {
        var cuerpo = document.getElementById('mixBody');

        if (!cuerpo) { return; }

        cuerpo.addEventListener('change', alCambiarMix);
        cuerpo.addEventListener('input', alTipearMix);
        cuerpo.addEventListener('click', function(e) {
            var agregar = e.target.closest('[data-agregar]');
            var guardar = e.target.closest('[data-guardar-canal]');
            var modo = e.target.closest('[data-modo]');
            var verMes = e.target.closest('[data-ver-mes]');
            var copiarConstante = e.target.closest('[data-copiar-constante]');
            var copiarMeses = e.target.closest('[data-copiar-meses]');
            var quitarMes = e.target.closest('[data-quitar-mes]');

            if (modo) {
                cambiarModoMix(modo.dataset.canal, modo.dataset.modo);
            } else if (verMes) {
                verMesMix(verMes.dataset.canal, verMes.dataset.verMes ? parseInt(verMes.dataset.verMes, 10) : null);
            } else if (copiarConstante) {
                copiarDelConstante(copiarConstante.dataset.copiarConstante);
            } else if (copiarMeses) {
                copiarAOtrosMeses(copiarMeses.dataset.copiarMeses);
            } else if (quitarMes) {
                usarConstanteEnMes(quitarMes.dataset.quitarMes);
            } else if (agregar) {
                abrirAltaMix(agregar);
            } else if (guardar) {
                guardarMixCanal(guardar.dataset.guardarCanal);
            } else if (e.target.closest('[data-alta-cancelar]')) {
                cerrarAltaMix();
            } else if (e.target.closest('[data-alta-confirmar]')) {
                confirmarAltaMix(e.target.closest('tr.mixa-alta'));
            }
        });
    }

    /* ================================================================
       PARTICIPACIÓN DE RESPALDO
       ================================================================ */

    function generarRespaldo() {
        var html = '';

        modulo.respaldo.forEach(function(param) {
            var canal = param.CLAVE.replace('respaldo_', '');

            html += '<div class="col-md-6 col-lg-3">' +
                        '<div class="param-card">' +
                            '<div class="param-clave">' + titulo(canal) +
                                Auditoria.icono({ usuario: param.USUARIO, fecha: param.FECHA_UPDATE }) + '</div>' +
                            '<div class="param-descripcion">' + escapar(param.DESCRIPCION || '') + '</div>' +
                            Permisos.segun('gridRespaldo',
                                '<div class="input-group input-group-sm">' +
                                    '<input type="number" step="0.01" class="form-control param-input respaldo-input" ' +
                                        'data-clave="' + param.CLAVE + '" ' +
                                        'value="' + (parseFloat(param.VALOR) * 100).toFixed(2) + '">' +
                                    '<span class="input-group-text">%</span>' +
                                '</div>',
                                '<span class="respaldo-input fw-semibold" data-clave="' + param.CLAVE + '" ' +
                                    'data-valor="' + (parseFloat(param.VALOR) * 100).toFixed(2) + '">' +
                                    formatPercent(parseFloat(param.VALOR)) + '</span>') +
                        '</div>' +
                    '</div>';
        });

        var gridRespaldo = document.getElementById('gridRespaldo');

        if (!gridRespaldo) {
            return;
        }

        gridRespaldo.innerHTML = html;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            input.addEventListener('input', validarRespaldo);
        });

        validarRespaldo();
    }

    function validarRespaldo() {
        var suma = 0;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            suma += (parseFloat(valorDe(input)) || 0) / 100;
        });

        var span = document.getElementById('sumaRespaldo');
        var valido = Math.abs(suma - 1) < 0.000001;

        span.textContent = formatPercent(suma);
        span.classList.toggle('suma-ok', valido);
        span.classList.toggle('suma-error', !valido);

        if (!valido) {
            var desvio = (suma - 1) * 100;
            span.textContent = formatPercent(suma) +
                ' (' + (desvio > 0 ? '+' : '') + desvio.toFixed(2) + ')';
        }

        var btnGuardarRespaldo = document.getElementById('btnGuardarRespaldo');

        if (btnGuardarRespaldo) {
            btnGuardarRespaldo.disabled = !valido;
        }

        return valido;
    }

    function guardarRespaldo() {
        if (!validarRespaldo()) {
            return;
        }

        var valores = {};

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            valores[input.dataset.clave] = (parseFloat(input.value) || 0) / 100;
        });

        var btn = document.getElementById('btnGuardarRespaldo');
        var textoOriginal = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        pedir('Controller/ParametrosController.php?action=saveRespaldo', { valores: valores })
            .then(function() {
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Guardado';
                setTimeout(function() {
                    btn.innerHTML = textoOriginal;
                    cargar();
                }, 900);
            })
            .catch(function(error) {
                Notificacion.error('No se pudieron guardar las participaciones de respaldo: '
                    + error.message);
                btn.disabled = false;
                btn.innerHTML = textoOriginal;
            });
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    function pedir(url, body) {
        var opciones = {};

        if (body) {
            opciones = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            };
        }

        return fetch(url, opciones)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }

                return response.text();
            })
            .then(function(text) {
                var result;

                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Respuesta no JSON:', text);
                    throw new Error('Respuesta inválida del servidor. Revisá la consola.');
                }

                if (!result.success) {
                    console.error('Error del servidor:', result);
                    throw new Error(result.message || 'Error desconocido');
                }

                return result.data;
            });
    }

    /**
     * El valor de un campo del formulario: el del input o, sin permiso de
     * edicion, el que la celda de texto trae en data-valor.
     */
    function valorDe(el) {
        return (el.tagName === 'INPUT') ? el.value : el.dataset.valor;
    }


    function formatPercent(value) {
        var num = (parseFloat(value) || 0) * 100;

        return num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 4
        }) + '%';
    }

    /**
     * LOCALES -> Locales. Lo usa el mix para los CANALES; los nombres de los
     * nodos se muestran tal cual se guardaron: son texto libre ("3 cuotas").
     */
    function titulo(texto) {
        return String(texto).toLowerCase().replace(/(^|\s)\S/g, function(c) {
            return c.toUpperCase();
        });
    }

    function escapar(texto) {
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function mostrarError(mensaje) {
        Notificacion.error(mensaje);
    }

})(); // Fin del IIFE
