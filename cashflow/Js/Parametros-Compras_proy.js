/**
 * Parametros -> Compras Exterior (codigo interno COMPRAS_PROY)
 *
 * Los siete parametros del modulo, y el dibujo de la cadena de fechas que los
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

    /** Como se dibuja y se valida cada uno */
    var FORMATO = {
        compras_proy_meses: { tipo: 'entero', sufijo: 'meses', min: 1, max: 60 },
        compras_proy_anios_cuota: { tipo: 'entero', sufijo: 'años', min: 1, max: 10 },
        compras_proy_base_cuota: { tipo: 'opciones', opciones: ['IMPORTE', 'UNIDADES'] },
        compras_proy_nac_pct: { tipo: 'decimal', sufijo: '%', paso: '0.01', min: 0, max: 300 },
        compras_proy_dia_llegada: { tipo: 'entero', sufijo: 'del mes', min: 1, max: 31 },
        compras_proy_dias_pago: { tipo: 'entero', sufijo: 'días antes', min: 0, max: 365 },
        compras_proy_dias_nac: { tipo: 'entero', sufijo: 'días antes', min: 0, max: 365 },
        comex_fecha_mov_desde: { tipo: 'fecha' }
    };

    var ETIQUETAS = {
        compras_proy_meses: 'Meses a proyectar',
        compras_proy_anios_cuota: 'Años de historia para la cuota',
        compras_proy_base_cuota: 'Base de la cuota',
        compras_proy_nac_pct: 'Nacionalización sobre el FOB',
        compras_proy_dia_llegada: 'Día de llegada dentro del mes',
        compras_proy_dias_pago: 'Días entre el pago del FOB y la recepción',
        compras_proy_dias_nac: 'Días entre la nacionalización y la recepción',
        comex_fecha_mov_desde: 'Contenedores desde (fecha de movimiento)'
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
        compras_proy_dias_nac:
            'Es el más chico de los dos, así que los últimos meses de la ventana aportan su FOB ' +
            'y no su nacionalización: ésa cae fuera del horizonte. El tablero lo avisa con su ' +
            'importe.'
    };

    var valores = {};

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

                avisar(avisos);

                pintar();
                pintarCadena();
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

        grid.innerHTML = ORDEN.concat([CLAVE_FECHA_MOV]).map(function(clave) {
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
