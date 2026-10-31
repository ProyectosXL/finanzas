/**
 * Parametros -> Compras Exterior (codigo interno COMPRAS_PROY)
 *
 * Los siete parametros del modulo -mas el corte de contenedores y los dos del
 * dolar BCRA de Comercio Exterior-, y el dibujo de la cadena de fechas que los
 * tres de dias producen.
 *
 * POR QUE LA CADENA SE DIBUJA
 * ---------------------------
 * "Día 15", "47 días" y "2 días" son tres numeros sueltos que no se leen solos.
 * Juntos son una cadena: la mercaderia llega el 15, el FOB se pago 47 dias
 * antes y la nacionalizacion se pago 2 dias antes. Quien toca uno de los tres
 * tiene que poder ver adonde se mueve la plata sin abrir la otra pestana.
 *
 * Y HAY UNA TRAMPA QUE LA CADENA HACE VISIBLE: subir los dias de pago corre el
 * egreso HACIA ATRAS en el eje, no hacia adelante. Es facil de leer al reves.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/ParametrosController.php';

    /** Los siete, en el orden en que se leen */
    var ORDEN = [
        'compras_proy_meses',
        'compras_proy_anios_cuota',
        'compras_proy_base_cuota',
        'compras_proy_nac_pct',
        'compras_proy_dia_llegada',
        'compras_proy_dias_pago',
        'compras_proy_dias_nac'
    ];

    /**
     * El de Comercio Exterior que vive en este módulo: desde qué fecha de
     * movimiento se consideran los contenedores. No es de la proyección, pero
     * corta el padrón cuyo pendiente la proyección descuenta, así que se mira
     * acá. Va aparte de ORDEN porque lo siembra otro script
     * (sql/cashflow_comex_fecha_mov_desde.sql) y el aviso de que falta tiene
     * que nombrar ése.
     */
    var CLAVE_FECHA_MOV = 'comex_fecha_mov_desde';

    /**
     * Los dos de la valuación de Comercio Exterior: hasta cuántos días se paga
     * con dólar BCRA y desde cuántos días se avisa que esa cotización es vieja.
     * Valen para las tres pantallas —Proveedores Exterior, Crono
     * Nacionalización y Proyección—, así que se miran acá. Van aparte porque
     * los siembra otro script (sql/cashflow_comex_dolar_bcra.sql).
     */
    var CLAVES_BCRA = ['comex_dias_bcra', 'comex_bcra_antiguedad_max'];

    /** Como se dibuja y se valida cada uno */
    var FORMATO = {
        compras_proy_meses: { tipo: 'entero', sufijo: 'meses', min: 1, max: 60 },
        compras_proy_anios_cuota: { tipo: 'entero', sufijo: 'años', min: 1, max: 10 },
        compras_proy_base_cuota: { tipo: 'opciones', opciones: ['IMPORTE', 'UNIDADES'] },
        compras_proy_nac_pct: { tipo: 'decimal', sufijo: '%', paso: '0.01', min: 0, max: 300 },
        compras_proy_dia_llegada: { tipo: 'entero', sufijo: 'del mes', min: 1, max: 31 },
        compras_proy_dias_pago: { tipo: 'entero', sufijo: 'días antes', min: 0, max: 365 },
        compras_proy_dias_nac: { tipo: 'entero', sufijo: 'días antes', min: 0, max: 365 },
        comex_fecha_mov_desde: { tipo: 'fecha' },
        // Los topes son los de DolarFuturo::DIAS_BCRA_MAX y ANTIGUEDAD_BCRA_MAX.
        comex_dias_bcra: { tipo: 'entero', sufijo: 'días', min: 0, max: 60 },
        comex_bcra_antiguedad_max: { tipo: 'entero', sufijo: 'días', min: 0, max: 30 }
    };

    var ETIQUETAS = {
        compras_proy_meses: 'Meses a proyectar',
        compras_proy_anios_cuota: 'Años de historia para la cuota',
        compras_proy_base_cuota: 'Base de la cuota',
        compras_proy_nac_pct: 'Nacionalización sobre el FOB',
        compras_proy_dia_llegada: 'Día de llegada dentro del mes',
        compras_proy_dias_pago: 'Días entre el pago del FOB y la recepción',
        compras_proy_dias_nac: 'Días entre la nacionalización y la recepción',
        comex_fecha_mov_desde: 'Contenedores desde (fecha de movimiento)',
        comex_dias_bcra: 'Días con dólar BCRA',
        comex_bcra_antiguedad_max: 'Antigüedad máxima del dólar BCRA'
    };

    /**
     * La nota que va debajo de cada campo.
     *
     * NO REPITE LA DESCRIPCION, que ya viene de la base: dice la CONSECUENCIA de
     * tocarlo, que es lo que no se deduce del nombre.
     */
    var HINTS = {
        compras_proy_meses:
            'El último mes NO lo fija este número: se deriva solo, y es el último mes cuyo pago ' +
            'de FOB todavía cae dentro del horizonte. Esto decide cuántos meses hacia atrás se ' +
            'muestran desde ahí.',
        compras_proy_anios_cuota:
            'Con un solo año la cuota es inestable: abril pesó 23,66 % en 2023 y 11,10 % en 2025. ' +
            'Con tres, se estabiliza.',
        compras_proy_base_cuota:
            'Se reparte plata, así que por importe. Las dos difieren hasta 4,5 puntos en un mes, ' +
            'porque el precio por unidad no es parejo entre meses.',
        compras_proy_nac_pct:
            'El inc_fob del presupuesto da 41 % y deja el 30 % del FOB en cero. Los contenedores ' +
            'reales dan entre 71 % y 106 %, con 89 % ponderado. La pestaña muestra los dos al lado.',
        compras_proy_dia_llegada:
            'Si el mes es más corto que el día elegido, se recorta al último: así el mes de ' +
            'recepción nunca se corre solo.',
        compras_proy_dias_pago:
            'Subirlo corre el egreso HACIA ATRÁS en el eje, no hacia adelante: el pago va ANTES ' +
            'de la recepción. Y alarga la ventana, porque entran meses de recepción más lejanos ' +
            'cuyo pago sigue cayendo adentro.',
        comex_fecha_mov_desde:
            'Proveedores Exterior y Crono Nacionalización traen sólo los contenedores cuya OC ' +
            'principal tiene fecha de movimiento desde este día, y lo mismo vale para lo ya ' +
            'cargado que descuenta la proyección. Lo anterior no se muestra ni se avisa. No puede ' +
            'ser una fecha futura.',
        comex_dias_bcra:
            'Un pago en dólares que sale dentro de estos días —o que ya venció— se pasa a pesos ' +
            'con el dólar oficial BCRA vendedor, la última cotización publicada: es un pago ' +
            'inminente. Los posteriores, con la curva ROFEX del mes de pago. Con 0, todo va con ' +
            'la curva. Vale para Proveedores Exterior, Crono Nacionalización y Proyección, y ' +
            'mueve el tablero.',
        comex_bcra_antiguedad_max:
            'Si la última cotización del BCRA tiene más de estos días corridos, se usa igual y ' +
            'las tres pantallas avisan. No cambia ningún número.',
        compras_proy_dias_nac:
            'Es el más chico de los dos, así que los últimos meses de la ventana aportan su FOB ' +
            'y no su nacionalización: ésa cae fuera del horizonte. El tablero lo avisa con su ' +
            'importe.'
    };

    var valores = {};

    /** La sección del % importado por rubro, como la manda el servidor */
    var rubros = null;

    /** Hay un recálculo en curso: el botón no se puede volver a disparar */
    var recalculando = false;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        /* Pide SOLO este modulo, y ParametrosCarga.pedir() ya devuelve
           'data': no la respuesta entera, como el pedir() de este archivo. */
        return ParametrosCarga.pedir('COMPRAS_PROY')
            .then(function(data) {
                var modulo = buscarModulo(data, 'COMPRAS_PROY');

                if (!modulo) {
                    avisar([{ nivel: 'danger', texto: 'El módulo Compras Exterior no está declarado en Parámetros.' }]);

                    return;
                }

                var desc = document.getElementById('pcprDescripcion');

                if (desc && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' +
                        esc(modulo.descripcion);
                }

                valores = {};

                (modulo.generales || []).forEach(function(p) { valores[p.CLAVE] = p; });

                /* FALTAN PARAMETROS = FALTA EL SCRIPT. No se rompe: se dice cuál
                   correr, y la proyección sigue andando con los valores
                   iniciales que el proveedor tiene escritos. */
                var faltan = ORDEN.filter(function(c) { return !valores[c]; });

                var avisos = faltan.length
                    ? ['Faltan ' + faltan.length + ' parámetro' + (faltan.length === 1 ? '' : 's') +
                       ' de este módulo. Corré sql/cashflow_compras_proyectadas.sql contra la ' +
                       'base central. Mientras tanto la proyección usa los valores iniciales y ' +
                       'la pestaña Comercio Exterior › Proyección lo avisa.']
                    : [];

                /* SIN EL PARÁMETRO DE LA FECHA EL CORTE SE APLICA IGUAL, con el
                   valor inicial que tiene el código (Comex::FECHA_MOV_DESDE_INICIAL).
                   Lo único que falta es poder cambiarlo, y eso se dice SÓLO acá: las
                   pestañas no avisan nada. */
                if (!valores[CLAVE_FECHA_MOV]) {
                    avisos.push('Falta el parámetro "Contenedores desde (fecha de movimiento)". ' +
                        'Corré sql/cashflow_comex_fecha_mov_desde.sql contra la base central. ' +
                        'Mientras tanto Proveedores Exterior y Crono Nacionalización traen los ' +
                        'contenedores desde el 01/01/2025, y no se puede cambiar.');
                }

                /* LO MISMO CON LOS DOS DEL DÓLAR BCRA: la regla se aplica igual,
                   con 7 y 3 (DolarFuturo::DIAS_BCRA_INICIAL). */
                if (CLAVES_BCRA.some(function(c) { return !valores[c]; })) {
                    avisos.push('Faltan "Días con dólar BCRA" y "Antigüedad máxima del dólar ' +
                        'BCRA". Corré sql/cashflow_comex_dolar_bcra.sql contra la base central. ' +
                        'Mientras tanto los pagos de los próximos 7 días van a dólar BCRA y se ' +
                        'avisa si la cotización tiene más de 3 días, y no se puede cambiar.');
                }

                /* Los del servidor traen los del % importado por rubro: los
                   mismos que ven el tablero y la pestaña Proyección. */
                avisar(avisos.concat(modulo.avisos || []));

                rubros = modulo.compras_proy_rubros || null;

                pintar();
                pintarCadena();
                pintarRubros();
            })
            .catch(function(e) {
                avisar([{ nivel: 'danger', texto: 'No se pudieron leer los parámetros: ' + e.message }]);

                // Falló: que volver a la sub-pestaña lo reintente.
                return false;
            });
    }

    /** Hoy como 'aaaa-mm-dd', en la hora local: toISOString() daría UTC */
    function hoyIso() {
        var d = new Date();

        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' +
            ('0' + d.getDate()).slice(-2);
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
       LOS CAMPOS
       ================================================================ */

    function pintar() {
        var grid = document.getElementById('pcprGrid');

        if (!grid) { return; }

        grid.innerHTML = ORDEN.concat([CLAVE_FECHA_MOV], CLAVES_BCRA).map(function(clave) {
            var p = valores[clave];

            if (!p) { return ''; }

            var fmt = FORMATO[clave];
            var input;

            if (fmt.tipo === 'opciones') {
                input = '<select class="form-select pcpr-input" data-clave="' + clave + '">' +
                    fmt.opciones.map(function(o) {
                        return '<option value="' + o + '"' +
                            (String(p.VALOR).toUpperCase() === o ? ' selected' : '') + '>' +
                            (o === 'IMPORTE' ? 'Importe (FOB prorrateado)' : 'Unidades') +
                            '</option>';
                    }).join('') +
                '</select>';
            } else if (fmt.tipo === 'fecha') {
                // El tope es hoy: una fecha futura dejaría afuera todos los
                // contenedores. El servidor lo valida igual (Comex::validarFechaMovDesde()).
                input = '<input type="date" class="form-control pcpr-input" ' +
                    'data-clave="' + clave + '" max="' + hoyIso() + '" ' +
                    'value="' + esc(p.VALOR) + '">';
            } else {
                input = '<input type="number" class="form-control pcpr-input" ' +
                    'data-clave="' + clave + '" ' +
                    'step="' + (fmt.paso || '1') + '" ' +
                    'min="' + fmt.min + '" max="' + fmt.max + '" ' +
                    'value="' + esc(p.VALOR) + '">';
            }

            return '<div class="col-md-6 col-lg-4">' +
                '<div class="param-card" id="pcpr-card-' + clave + '">' +
                    '<div class="param-clave">' + esc(ETIQUETAS[clave] || clave) +
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

        // Sin permiso de edicion, como texto (Js/permisos.js)
        Permisos.soloLectura(grid);

        grid.querySelectorAll('.pcpr-input').forEach(function(input) {
            input.addEventListener('change', function() { guardar(input); });
        });
    }

    function guardar(input) {
        var clave = input.getAttribute('data-clave');
        var fmt = FORMATO[clave];
        var valor = input.value;

        if (fmt.tipo === 'fecha') {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(valor)) {
                Notificacion.campoInvalido(input, ETIQUETAS[clave] + ' tiene que ser una fecha.');

                return;
            }

            if (valor > hoyIso()) {
                Notificacion.campoInvalido(input, ETIQUETAS[clave] + ' no puede ser una fecha ' +
                    'futura: dejaría afuera todos los contenedores.');

                return;
            }
        } else if (fmt.tipo !== 'opciones') {
            var n = Number(valor);

            /* SE VALIDA ANTES DE MANDAR, y el mensaje dice el rango: un día 45
               o un porcentaje negativo no fallan en la base -son números
               válidos- pero producen una proyección que no significa nada. */
            if (valor === '' || isNaN(n) || n < fmt.min || n > fmt.max) {
                Notificacion.campoInvalido(input,
                    ETIQUETAS[clave] + ' tiene que ser un número entre ' +
                    fmt.min + ' y ' + fmt.max + '.');

                return;
            }

            if (fmt.tipo === 'entero' && Math.floor(n) !== n) {
                Notificacion.campoInvalido(input, ETIQUETAS[clave] + ' tiene que ser un entero.');

                return;
            }

            valor = String(n);
        }

        pedir(ENDPOINT + '?action=saveParametro', { clave: clave, valor: valor })
            .then(function() {
                if (valores[clave]) { valores[clave].VALOR = valor; }

                destacar('pcpr-card-' + clave);
                pintarCadena();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar ' + ETIQUETAS[clave] + ': ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                cargar();
            });
    }

    /* ================================================================
       LA CADENA DE FECHAS
       ================================================================ */

    function pintarCadena() {
        var cont = document.getElementById('pcprCadena');

        if (!cont) { return; }

        var dia = entero('compras_proy_dia_llegada', 15);
        var diasPago = entero('compras_proy_dias_pago', 47);
        var diasNac = entero('compras_proy_dias_nac', 2);

        /* Se dibuja sobre un mes de ejemplo y no sobre uno real: lo que hay que
           entender es la FORMA de la cadena, y un mes concreto de la ventana
           obligaría a pedirle la ventana al servidor en una pantalla que sólo
           edita parámetros. */
        var hoy = new Date();
        var recepcion = new Date(hoy.getFullYear(), hoy.getMonth() + 3, 1);

        recepcion.setDate(Math.min(dia, diasDelMes(recepcion)));

        var pago = sumarDias(recepcion, -diasPago);
        var nac = sumarDias(recepcion, -diasNac);

        cont.innerHTML =
            paso('fa-money-bill-transfer', 'Se paga el FOB', fecha(pago),
                 diasPago + ' días antes de la llegada', 'text-danger') +
            flecha() +
            paso('fa-file-invoice-dollar', 'Se nacionaliza', fecha(nac),
                 diasNac + ' días antes de la llegada', 'text-warning') +
            flecha() +
            paso('fa-boxes-packing', 'Llega la mercadería', fecha(recepcion),
                 'Día ' + dia + ' del mes', 'text-success');
    }

    function paso(icono, titulo, cuando, detalle, color) {
        return '<div class="pcpr-paso">' +
            '<div class="pcpr-paso-icono ' + color + '"><i class="fas ' + icono + '"></i></div>' +
            '<div class="pcpr-paso-titulo">' + esc(titulo) + '</div>' +
            '<div class="pcpr-paso-fecha">' + esc(cuando) + '</div>' +
            '<div class="pcpr-paso-detalle">' + esc(detalle) + '</div>' +
        '</div>';
    }

    function flecha() {
        return '<div class="pcpr-flecha"><i class="fas fa-arrow-right"></i></div>';
    }

    function entero(clave, porDefecto) {
        var v = valores[clave] ? Number(valores[clave].VALOR) : NaN;

        return isNaN(v) ? porDefecto : v;
    }

    function diasDelMes(d) {
        return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    }

    function sumarDias(d, n) {
        var r = new Date(d.getTime());

        r.setDate(r.getDate() + n);

        return r;
    }

    function fecha(d) {
        return String(d.getDate()).padStart(2, '0') + '/' +
            String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
    }

    /* ================================================================
       EL % IMPORTADO POR RUBRO
       ================================================================ */

    /**
     * La card entera: la tabla, el botón de recálculo y las marcas.
     *
     * "FOB del presupuesto" es la suma de las temporadas con versión oficial
     * que leyó el último cálculo; "FOB proyectado" lo multiplica por el % de
     * HOY, así un cambio recién guardado se ve acá antes de recalcular. El
     * tooltip del FOB abre el detalle por temporada.
     */
    function pintarRubros() {
        var cont = document.getElementById('pcprRubros');

        if (!cont) { return; }

        if (!rubros || !rubros.disponible) {
            cont.innerHTML = '<div class="card mb-4"><div class="card-header"><h5 class="mb-0">' +
                '% importado por rubro</h5></div><div class="card-body text-muted">' +
                'Falta correr <code>sql/cashflow_compras_proy_rubro_pct.sql</code> contra la base ' +
                'central. Mientras tanto el job del presupuesto no recalcula y queda el último ' +
                'cálculo.</div></div>';

            return;
        }

        var filas = rubros.filas || [];
        var totalPres = 0;
        var totalProy = 0;

        var cuerpo = filas.map(function(f) {
            totalPres += Number(f.fob_presupuesto || 0);
            totalProy += Number(f.fob_proyectado || 0);

            var clase = !f.en_presupuesto ? 'pcpr-rubro-fuera'
                : (f.configurado && Number(f.pct_importado) === 0 ? 'pcpr-rubro-cero' : '');

            var marca = !f.en_presupuesto
                ? ' <span class="badge text-bg-light border pcpr-rubro-marca">no está en el ' +
                  'presupuesto vigente</span>'
                : (!f.configurado
                    ? ' <span class="badge text-bg-warning pcpr-rubro-marca">sin configurar ' +
                      '(100%)</span>'
                    : '');

            var quien = f.configurado
                ? esc(Auditoria.quien(f.usuario) || 'sin usuario registrado') +
                  '<div class="small text-muted">' + esc(Auditoria.fecha(f.fecha)) + '</div>'
                : '<span class="text-muted">—</span>';

            return '<tr class="' + clase + '" data-rubro="' + esc(f.rubro) + '">' +
                '<td class="fw-medium">' + esc(f.rubro) + marca + '</td>' +
                '<td style="width: 150px;">' +
                    '<div class="input-group input-group-sm">' +
                        '<input type="number" class="form-control text-end pcpr-rubro-pct" ' +
                            'min="0" max="100" step="0.01" ' +
                            (f.configurado ? '' : 'placeholder="100" ') +
                            'data-rubro="' + esc(f.rubro) + '" ' +
                            'data-previo="' + (f.configurado ? esc(f.pct_importado) : '') + '" ' +
                            'value="' + (f.configurado ? esc(f.pct_importado) : '') + '">' +
                        '<span class="input-group-text">%</span>' +
                    '</div>' +
                '</td>' +
                '<td class="small">' + quien + '</td>' +
                '<td class="text-end text-muted" title="' + esc(desglose(f, 'fob_presupuesto')) + '">' +
                    usd(f.fob_presupuesto) + '</td>' +
                '<td class="text-end fw-bold" title="' + esc(desglose(f, 'fob_proyectado')) + '">' +
                    usd(f.fob_proyectado) + '</td>' +
                '<td class="text-end">' +
                    '<button class="btn btn-sm btn-outline-secondary pcpr-rubro-hist" data-lectura ' +
                        'data-rubro="' + esc(f.rubro) + '" title="Ver el historial de cambios">' +
                        '<i class="fas fa-clock-rotate-left"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        if (!filas.length) {
            cuerpo = '<tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay ' +
                'rubros: el presupuesto por rubro se llena con la próxima corrida del job.</td></tr>';
        }

        cont.innerHTML =
            '<div class="card mb-4">' +
                '<div class="card-header d-flex justify-content-between align-items-center">' +
                    '<div>' +
                        '<h5 class="mb-0">% importado por rubro</h5>' +
                        '<small class="text-muted">Qué parte de cada rubro del presupuesto se ' +
                        'compra en el exterior. 100%: entra completo; 0%: no se proyecta. Un ' +
                        'cambio se ve en la proyección después de recalcularla.' +
                        (rubros.presupuesto_al ? ' FOB del presupuesto al ' +
                            esc(rubros.presupuesto_al) + '.' : '') + '</small>' +
                    '</div>' +
                    '<button id="pcprBtnRecalcular" class="btn btn-sm btn-outline-primary text-nowrap" ' +
                        'title="Corre el job del presupuesto con los % de esta tabla. Tarda unos segundos.">' +
                        '<i class="fas fa-calculator me-1"></i> Recalcular la proyección' +
                    '</button>' +
                '</div>' +
                '<div class="card-body p-0">' +
                    '<div class="table-responsive">' +
                        '<table class="table table-hover table-sm align-middle mb-0" id="pcprTablaRubros">' +
                            '<thead><tr>' +
                                '<th>Rubro</th>' +
                                '<th>% importado</th>' +
                                '<th>Cambiado por</th>' +
                                '<th class="text-end" title="Lo que dice el presupuesto de la ' +
                                    'app de compras, sin el %">FOB del presupuesto</th>' +
                                '<th class="text-end" title="FOB del presupuesto × % importado">' +
                                    'FOB proyectado</th>' +
                                '<th></th>' +
                            '</tr></thead>' +
                            '<tbody>' + cuerpo + '</tbody>' +
                            (filas.length
                                ? '<tfoot><tr class="fw-bold">' +
                                    '<td colspan="3">Total de las temporadas con versión oficial</td>' +
                                    '<td class="text-end text-muted">' + usd(totalPres) + '</td>' +
                                    '<td class="text-end">' + usd(totalProy) + '</td>' +
                                    '<td></td>' +
                                  '</tr></tfoot>'
                                : '') +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>';

        // Sin permiso: los % como texto, sin recalcular. El historial queda.
        Permisos.soloLectura(cont);

        cont.querySelectorAll('input.pcpr-rubro-pct').forEach(function(input) {
            input.addEventListener('change', function() { guardarRubro(input); });
        });

        cont.querySelectorAll('.pcpr-rubro-hist').forEach(function(btn) {
            btn.addEventListener('click', function() {
                abrirHistorial(btn.getAttribute('data-rubro'));
            });
        });

        var btn = document.getElementById('pcprBtnRecalcular');

        if (btn) {
            btn.addEventListener('click', function() { recalcular(btn); });
            pintarBotonRecalcular(btn);
        }
    }

    /** "VER 27-28: U$S 1.234,00 · INV 27: U$S 567,00" */
    function desglose(f, campo) {
        return (f.temporadas || []).map(function(t) {
            return t.temporada + ': ' + usd(t[campo]);
        }).join(' · ');
    }

    /**
     * Guarda el % de un rubro.
     *
     * SE VALIDA ANTES DE MANDAR, y el servidor lo valida igual
     * (ComprasProyectadasRubros::validar()): de 0 a 100, hasta dos decimales.
     */
    function guardarRubro(input) {
        var rubro = input.getAttribute('data-rubro');
        var previo = input.getAttribute('data-previo');
        var texto = String(input.value).trim().replace(',', '.');
        var n = Number(texto);

        if (texto === previo) { return; }

        if (texto === '' || isNaN(n) || n < 0 || n > 100) {
            Notificacion.campoInvalido(input, 'El % importado de ' + rubro + ' tiene que ser un ' +
                'número entre 0 y 100.');
            input.value = previo;

            return;
        }

        if (!/^\d+(\.\d{1,2})?$/.test(texto)) {
            Notificacion.campoInvalido(input, 'El % importado admite hasta dos decimales.');
            input.value = previo;

            return;
        }

        input.disabled = true;

        pedir(ENDPOINT + '?action=saveRubroPctComprasProy', { rubro: rubro, pct: texto })
            .then(function(d) {
                Notificacion.exito(d.message);
                cargar();
            })
            .catch(function(e) {
                Notificacion.error('No se pudo guardar el % de ' + rubro + ': ' + e.message, {
                    detalle: 'El campo vuelve al último valor guardado.'
                });
                input.disabled = false;
                input.value = previo;
            });
    }

    /**
     * Corre el job del presupuesto, con los % de la tabla.
     *
     * UNA SOLA VEZ: mientras corre, el botón queda deshabilitado y dice que
     * está calculando. El SP además tiene su propio lock: una segunda corrida
     * simultánea, desde otra pantalla, sale sin hacer nada y lo deja dicho.
     */
    function recalcular(btn) {
        if (recalculando) { return; }

        recalculando = true;
        pintarBotonRecalcular(btn);

        pedir(ENDPOINT + '?action=recalcularPresupuestoComprasProy', {})
            .then(function(d) {
                Notificacion.exito(d.message);
            })
            .catch(function(e) {
                Notificacion.error('No se pudo recalcular la proyección: ' + e.message, {
                    detalle: 'Se sigue usando el último cálculo bueno.'
                });
            })
            .then(function() {
                recalculando = false;
                cargar();
            });
    }

    function pintarBotonRecalcular(btn) {
        btn.disabled = recalculando;
        btn.innerHTML = recalculando
            ? '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Calculando…'
            : '<i class="fas fa-calculator me-1"></i> Recalcular la proyección';
    }

    function abrirHistorial(rubro) {
        var modalEl = document.getElementById('modalHistorialRubroPct');

        if (!modalEl || !window.bootstrap || !bootstrap.Modal) {
            Notificacion.error('No se puede abrir el historial: falta Bootstrap en la página.');

            return;
        }

        var tabla = document.getElementById('pcprHistTabla');
        var titulo = document.getElementById('pcprHistTitulo');

        if (titulo) { titulo.textContent = rubro; }
        if (tabla) { tabla.style.display = 'none'; }

        Cargando.mostrar('pcprHistSpinner');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        pedir(ENDPOINT + '?action=getHistorialRubroPct&rubro=' + encodeURIComponent(rubro))
            .then(function(d) {
                var lista = d.data || [];
                var body = document.getElementById('pcprHistBody');

                if (body) {
                    body.innerHTML = lista.length
                        ? lista.map(function(h) {
                            return '<tr class="' + (h.vigente ? 'fw-bold' : 'text-muted') + '">' +
                                '<td class="text-end">' + esc(pctTexto(h.pct)) + '</td>' +
                                '<td>' + esc(Auditoria.linea('alta', h.usuario, h.fecha_alta)) + '</td>' +
                                '<td>' + (h.vigente ? 'vigente'
                                    : esc(Auditoria.linea('baja', h.usuario_baja, h.fecha_baja))) +
                                '</td>' +
                            '</tr>';
                        }).join('')
                        : '<tr><td colspan="3" class="text-center text-muted py-3">Sin ' +
                          'configurar: nunca se le asignó un %, así que se proyecta al 100%.</td></tr>';
                }

                Cargando.ocultar('pcprHistSpinner');
                if (tabla) { tabla.style.display = ''; }
            })
            .catch(function(e) {
                Cargando.ocultar('pcprHistSpinner');
                Notificacion.error('No se pudo leer el historial: ' + e.message);
            });
    }

    function pctTexto(n) {
        return Number(n).toLocaleString('es-AR', { maximumFractionDigits: 2 }) + ' %';
    }

    function usd(n) {
        return 'U$S ' + Number(n || 0).toLocaleString('es-AR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
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
        AvisosPanel.pintar('pcprAvisos', lista || []);
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
        var btn = document.getElementById('pcprBtnRefresh');

        if (btn) { btn.addEventListener('click', cargar); }

        /* No carga al arrancar: carga cuando se abre su sub-pestaña, o ahora
           si es la primera que se ve. Ver Js/parametros-carga.js. */
        ParametrosCarga.registrar('COMPRAS_PROY', cargar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
