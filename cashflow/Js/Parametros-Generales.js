/**
 * Parametros -> Generales
 *
 * Lo que afecta a TODO el modulo y no es una fecha: la alicuota, la inflacion
 * mensual y las cuentas excluidas de Gastos Locales. Mas las dos cotizaciones,
 * que se dibujan y no se editan.
 *
 * LAS FECHAS SE FUERON A CALENDARIO (Js/Parametros-Calendario.js): el
 * horizonte, los feriados de comercio y el cronograma de pagos, con su card
 * entera. Hasta correr sql/cashflow_parametros_calendario.sql el horizonte y
 * los feriados de comercio siguen figurando en este modulo y se dibujan aca
 * igual, con la regla de la grilla: se ve todo lo que llega.
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
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/ParametrosController.php';

    /** Los parametros generales, en el orden en que se leen */
    var ORDEN = ['alicuota_iva'];

    /**
     * Como se dibuja y se valida cada uno.
     *
     * 'porcentaje' se MUESTRA en % y se GUARDA en tasa: la alicuota vive como
     * 0,21 en la base. La inflacion hace lo contrario -vive en puntos- y por eso
     * esto va dicho: son dos criterios distintos en la misma pantalla.
     */
    var FORMATO = {
        alicuota_iva: { tipo: 'porcentaje', sufijo: '%', paso: '0.01', min: 0, max: 100 }
    };

    var ETIQUETAS = {
        alicuota_iva: 'Alícuota de IVA'
    };

    /**
     * La nota que va debajo de cada campo.
     *
     * NO REPITE LA DESCRIPCION, que ya viene de la base: dice la CONSECUENCIA de
     * tocarlo, que es lo que no se deduce del nombre.
     */
    var HINTS = {
        alicuota_iva:
            'Se aplica sobre la venta neta proyectada de los cuatro canales. Se tipea en % ' +
            'y se guarda como tasa.'
    };

    var valores = {};
    var inflacion = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        return ParametrosCarga.pedir('GENERALES')
            .then(function(data) {
                var modulo = buscarModulo(data, 'GENERALES');

                if (!modulo) {
                    avisar([{ nivel: 'danger', texto: 'El módulo Generales no está declarado en Parámetros.' }]);

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

                avisar(modulo.avisos || []);
                pintarGrid();
                pintarInflacion();
                pintarGastosLocales(modulo.gastos_locales || null);
                pintarCotizaciones(modulo.cotizaciones);
            })
            .catch(function(e) {
                avisar([{ nivel: 'danger', texto: 'No se pudieron leer los parámetros generales: ' + e.message }]);

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
       LOS VALORES GENERALES
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

            /* SE VALIDA ANTES DE MANDAR, y el mensaje dice el rango: una
               alícuota de 2100 no falla en la base -es un número válido- pero
               multiplica la venta neta por veintidós. */
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

    /**
     * El panel plegable de todas las pestañas (Js/avisos-panel.js). Los textos
     * sueltos cuentan como atención; un error de carga llega como
     * {nivel: 'danger'} y abre el panel solo.
     */
    function avisar(lista) {
        AvisosPanel.pintar('pgenAvisos', lista || []);
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

    /* ================================================================
       GASTOS LOCALES: LAS CUENTAS EXCLUIDAS
       ================================================================ */

    function pintarGastosLocales(gl) {
        var input = document.getElementById('pgenCuentasExcluidas');
        var audit = document.getElementById('pgenCuentasAuditoria');

        if (!input) { return; }

        input.value = gl ? (gl.texto || '') : '';

        /* Sin el parámetro no hay dónde guardar: el campo muestra el defecto
           que se está usando y no se edita. Sin permiso, tampoco. */
        input.disabled = !gl || !gl.creado || !Permisos.puedeEditar(input);

        if (audit) {
            audit.innerHTML = gl && gl.creado
                ? Auditoria.icono({ usuario: gl.usuario, fecha: gl.fecha_modif })
                : (gl ? ' <span class="badge bg-warning text-dark" title="Falta correr ' +
                    'sql/cashflow_gastos_locales.sql">por defecto</span>' : '');
        }
    }

    function guardarCuentas() {
        var input = document.getElementById('pgenCuentasExcluidas');

        if (!input) { return; }

        pedir(ENDPOINT + '?action=saveCuentasGastosLocales', { cuentas: input.value })
            .then(function(d) {
                Notificacion.exito(d.message || 'Guardado.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.campoInvalido(input, e.message);
            });
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
        enganchar('pgenGuardarCuentas', 'click', guardarCuentas);

        /* No carga al arrancar: carga cuando se abre su sub-pestaña, o ahora
           si es la primera que se ve. Ver Js/parametros-carga.js. */
        ParametrosCarga.registrar('GENERALES', cargar);
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
