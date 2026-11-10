/**
 * Parametros -> Alquileres
 *
 * Cuatro secciones: el dia de vencimiento general, las razones sociales dadas
 * de alta, los datos de cada local de la app de alquileres y los pagos
 * extraordinarios.
 *
 * LA VALIDACION MANDA EN EL SERVIDOR (AlquileresParametros). Aca solo se
 * explica y se bloquean botones: lo que el servidor rechaza vuelve con su
 * mensaje, tal cual, y la fila se recarga con lo guardado.
 *
 * LOS LOCALES SE GUARDAN POR FILA, como Parametros -> Tarjetas: al salir de un
 * campo viaja la fila entera. Guardar a medias se puede -un local queda
 * incompleto y no proyecta-; lo que no se puede es un dato invalido.
 *
 * SE GUARDA CONTRA EL CONTROLLER DEL MODULO (AlquileresController), que es el
 * mismo de la pestaña: las dos pantallas leen y escriben las mismas tablas.
 *
 * NADA DE alert(). Todo va a Notificacion, igual que el resto del modulo.
 */

(function() {
    'use strict';

    var ENDPOINT = 'Controller/AlquileresController.php';
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var MAX_SUGERENCIAS = 12;

    var datos = null;

    /* ================================================================
       CARGA
       ================================================================ */

    function cargar() {
        var descripcion = ParametrosCarga.pedir('ALQUILERES')
            .then(function(data) {
                var modulo = buscarModulo(data, 'ALQUILERES');
                var desc = document.getElementById('palqDescripcion');

                if (desc && modulo && modulo.descripcion) {
                    desc.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + esc(modulo.descripcion);
                }
            })
            .catch(function() { /* Contexto, no dato. */ });

        Cargando.mostrar('palqCargando');

        var pedido = pedir(ENDPOINT + '?action=getParametros')
            .then(function(d) {
                datos = d.data;
                AvisosPanel.pintar('palqAvisos', datos.avisos || []);
                pintarTodo();
            })
            .catch(function(e) {
                AvisosPanel.pintar('palqAvisos', [{ nivel: 'danger', texto: 'No se pudieron leer los parámetros de Alquileres: ' + e.message }]);

                return false;
            })
            .then(function(r) {
                Cargando.ocultar('palqCargando');

                return r;
            });

        return Promise.all([descripcion, pedido]).then(function(r) { return r[1]; });
    }

    function pintarTodo() {
        pintarGeneral();
        pintarRazones();
        pintarLocales();
        pintarExtraForm();
        pintarExtras();
        bloquearSinTabla();
    }

    /** Sin el script, nada se puede guardar: se dice por qué en cada control. */
    function bloquearSinTabla() {
        if (datos.tabla_creada) { return; }

        var titulo = 'Falta correr ' + datos.script;

        document.querySelectorAll('.palq-modulo input:not(#palqFiltroIncompletos), .palq-modulo select, .palq-modulo button:not([data-lectura])')
            .forEach(function(el) {
                el.disabled = true;
                el.title = titulo;
            });
    }

    /* ================================================================
       1. GENERALES
       ================================================================ */

    function pintarGeneral() {
        var g = datos.general;

        valor('palqDiaGeneral', g ? g.dia : '');
        texto('palqGeneralAudit', g ? Auditoria.linea('modif', g.usuario, g.fecha) : 'Sin cargar todavía.');
        Permisos.soloLectura('palqGeneral');
    }

    function guardarGeneral() {
        var dia = document.getElementById('palqDiaGeneral').value;

        escribir('guardarGeneral', { dia: dia });
    }

    /* ================================================================
       2. RAZONES SOCIALES
       ================================================================ */

    function pintarRazones() {
        var cuerpo = document.getElementById('palqRazonesBody');
        var razones = datos.razones || [];

        if (!razones.length) {
            cuerpo.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">'
                + 'Ninguna razón social dada de alta todavía. Se dan de alta con el buscador.</td></tr>';
            return;
        }

        cuerpo.innerHTML = razones.map(function(r) {
            return '<tr data-cod="' + esc(r.cod) + '">'
                + '<td>' + esc(r.cod) + '</td>'
                + '<td>' + esc(r.nombre)
                    + (r.en_rubro ? '' : ' <span class="badge bg-warning text-dark" title="Sigue de alta: '
                        + 'darla de baja es una decisión">ya no tiene rubro ALQUILER</span>')
                + '</td>'
                + '<td class="text-end">' + r.locales + '</td>'
                + '<td><input type="number" min="' + datos.dia_min + '" max="' + datos.dia_max + '" '
                    + 'class="form-control form-control-sm palq-razon" data-campo="dia" placeholder="general" '
                    + 'value="' + (r.dia === null ? '' : r.dia) + '"></td>'
                + '<td><input type="number" min="0" max="12" class="form-control form-control-sm palq-razon" '
                    + 'data-campo="diferimiento" value="' + r.diferimiento + '"></td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('modif', r.usuario_modif, r.fecha_modif)) + '</small></td>'
                + '<td class="text-center"><button class="btn btn-sm btn-link text-danger p-0 palq-razon-baja" '
                    + 'title="Dar de baja"><i class="fas fa-xmark"></i></button></td>'
                + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.palq-razon').forEach(function(input) {
            input.addEventListener('change', function() {
                var fila = input.closest('tr');

                escribir('guardarRazon', {
                    cod_provee: fila.getAttribute('data-cod'),
                    dia: fila.querySelector('[data-campo="dia"]').value,
                    diferimiento: fila.querySelector('[data-campo="diferimiento"]').value
                });
            });
        });

        cuerpo.querySelectorAll('.palq-razon-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var fila = btn.closest('tr');
                var cod = fila.getAttribute('data-cod');
                var r = razones.filter(function(x) { return x.cod === cod; })[0];

                Notificacion.confirmar({
                    titulo: 'Dar de baja la razón social',
                    mensaje: '¿' + r.nombre + ' deja de ser un alquiler?',
                    detalle: (r.locales ? 'Sus ' + r.locales + ' local(es) pasan a Incompletos hasta que se '
                        + 'les elija otra razón social. ' : '') + 'No se borra: queda en el historial.',
                    confirmar: 'Dar de baja',
                    peligro: true
                }).then(function(si) {
                    if (si) { escribir('bajaRazon', { cod_provee: cod }); }
                });
            });
        });

        Permisos.soloLectura(cuerpo);
    }

    function buscarRazon() {
        var input = document.getElementById('palqRazonBuscar');
        var caja = document.getElementById('palqRazonSugerencias');

        if (!input || !caja || !datos) { return; }

        var t = String(input.value || '').trim().toLowerCase();
        var lista = (datos.universo_razones || []).filter(function(p) {
            return !p.dada_de_alta && (t === '' || p.nombre.toLowerCase().indexOf(t) !== -1
                || p.cod.toLowerCase().indexOf(t) !== -1);
        }).slice(0, MAX_SUGERENCIAS);

        caja.innerHTML = lista.length
            ? lista.map(function(p) {
                return '<button type="button" class="list-group-item list-group-item-action py-1 palq-razon-op" '
                    + 'data-cod="' + esc(p.cod) + '" data-nombre="' + esc(p.nombre) + '">'
                    + '<span class="small">' + esc(p.nombre) + '</span> <small class="text-muted">'
                    + esc(p.cod) + (p.rubro_economico ? ' · ' + esc(p.rubro_economico) : '') + '</small></button>';
            }).join('')
            : '<div class="list-group-item small text-muted">Ningún proveedor con rubro ALQUILER sin dar de alta coincide.</div>';
        caja.style.display = '';

        caja.querySelectorAll('.palq-razon-op').forEach(function(btn) {
            btn.addEventListener('click', function() {
                ocultar('palqRazonSugerencias');
                Notificacion.confirmar({
                    titulo: 'Dar de alta como alquiler',
                    mensaje: '¿' + btn.getAttribute('data-nombre') + ' es el alquiler de algún local?',
                    detalle: 'Arranca sin diferimiento y con el día de vencimiento general. Queda disponible '
                        + 'para elegirla como razón social de los locales.',
                    confirmar: 'Dar de alta'
                }).then(function(si) {
                    if (si) {
                        valor('palqRazonBuscar', '');
                        escribir('altaRazon', { cod_provee: btn.getAttribute('data-cod') });
                    }
                });
            });
        });
    }

    /* ================================================================
       3. LOCALES
       ================================================================ */

    var ESTADO_BADGE = {
        PROYECTA: '<span class="badge bg-success">proyecta</span>',
        INCOMPLETO: '<span class="badge bg-warning text-dark">incompleto</span>',
        CERRADO_LAKERS: '<span class="badge bg-secondary">cerrado en Lakers</span>',
        FINALIZADO: '<span class="badge bg-secondary">contrato finalizado</span>'
    };

    function esIncompleto(l) {
        return l.estado === 'INCOMPLETO' || (l.faltan && l.faltan.length > 0);
    }

    function pintarLocales() {
        var cuerpo = document.getElementById('palqLocalesBody');
        var soloIncompletos = document.getElementById('palqFiltroIncompletos').checked;
        var locales = (datos.locales || []).slice();

        /* Los cerrados en Lakers van al final y aparte: no proyectan y no
           cuentan como incompletos. */
        locales.sort(function(a, b) {
            var ca = a.estado === 'CERRADO_LAKERS' ? 1 : 0;
            var cb = b.estado === 'CERRADO_LAKERS' ? 1 : 0;

            return (ca - cb) || a.nombre.localeCompare(b.nombre);
        });

        if (soloIncompletos) {
            locales = locales.filter(esIncompleto);
        }

        if (!locales.length) {
            cuerpo.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-3">'
                + (soloIncompletos ? 'Ningún local incompleto.' : 'No hay locales en la app de alquileres.')
                + '</td></tr>';
            return;
        }

        var opRazon = '<option value="">—</option>' + (datos.razones || []).map(function(r) {
            return '<option value="' + esc(r.cod) + '">' + esc(r.nombre) + '</option>';
        }).join('');
        var opFrec = '<option value="">—</option>' + Object.keys(datos.frecuencias).map(function(k) {
            return '<option value="' + k + '">' + esc(datos.frecuencias[k]) + '</option>';
        }).join('');
        var opMes = '<option value="">—</option>' + MESES.map(function(m, i) {
            return '<option value="' + (i + 1) + '">' + m + '</option>';
        }).join('');

        var cerradoVisto = false;

        cuerpo.innerHTML = locales.map(function(l) {
            var p = l.params || {};
            var cerrado = l.estado === 'CERRADO_LAKERS';
            var cabecera = '';

            if (cerrado && !cerradoVisto) {
                cerradoVisto = true;
                cabecera = '<tr class="table-light"><td colspan="11" class="small text-muted">'
                    + '<strong>Cerrados en Lakers</strong>: no proyectan ni cuentan como incompletos. Sus meses '
                    + 'cerrados en la app se siguen viendo en la pestaña.</td></tr>';
            }

            /* El IVA arranca con el general hasta que se guarda el del local. */
            var iva = (p.pct_iva === null || p.pct_iva === undefined) ? '' : p.pct_iva;
            var ivaSugerido = (iva === '' && datos.iva_general !== null) ? datos.iva_general : iva;

            return cabecera + '<tr data-nro="' + l.nro + '" class="' + (cerrado ? 'text-muted' : '') + '">'
                + '<td><strong>' + esc(l.nombre) + '</strong> <small class="text-muted">' + l.nro + '</small>'
                    + (l.nuevo && !cerrado ? ' <span class="badge bg-info text-dark" title="Sin 12 meses de venta en la app: '
                        + 'no entra al reparto de la venta del canal">nuevo</span>' : '') + '</td>'
                + '<td>' + (ESTADO_BADGE[l.estado] || '')
                    + (l.faltan && l.faltan.length ? '<div class="small text-danger">Falta: ' + esc(l.faltan.join(', ')) + '</div>' : '')
                + '</td>'
                + '<td>' + select('cod_provee', opRazon, p.cod_provee, cerrado) + '</td>'
                + '<td>' + select('frecuencia', opFrec, p.frecuencia, cerrado) + '</td>'
                + '<td>' + select('mes_ajuste', opMes, p.mes_ajuste, cerrado || String(p.frecuencia) === '1') + '</td>'
                + '<td>' + input('pct_iva', ivaSugerido, 'decimal', cerrado,
                        iva === '' ? 'Sugerido: la alícuota de Parámetros › Generales. Se guarda al tocar la fila.' : '') + '</td>'
                + '<td>' + input('pct_percepcion', p.pct_percepcion, 'decimal', cerrado, '0 es un valor; vacío es incompleto') + '</td>'
                + '<td>' + input('pct_retencion', p.pct_retencion, 'decimal', cerrado, 'Sobre el neto. 0 es un valor; vacío es incompleto') + '</td>'
                + '<td><input type="date" class="form-control form-control-sm palq-local" data-campo="fecha_fin" value="'
                    + esc(p.fecha_fin || '') + '"' + (cerrado ? ' disabled' : '') + ' title="Desde el mes siguiente deja de proyectar"></td>'
                + '<td>' + input('venta_mensual_estimada', p.venta_mensual_estimada, 'decimal', cerrado || !l.nuevo,
                        l.nuevo ? 'Neta, sin IVA' : 'Sólo para locales nuevos: este tiene 12 meses de venta') + '</td>'
                + '<td><small class="text-muted">' + (p.usuario ? esc(Auditoria.linea('modif', p.usuario, p.fecha)) : '') + '</small></td>'
                + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.palq-local').forEach(function(el) {
            el.addEventListener('change', function() { guardarLocal(el.closest('tr')); });
        });

        Permisos.soloLectura(cuerpo);
    }

    function select(campo, opciones, val, deshabilitado) {
        var html = '<select class="form-select form-select-sm palq-local" data-campo="' + campo + '"'
            + (deshabilitado ? ' disabled' : '') + '>' + opciones + '</select>';
        var v = (val === null || val === undefined) ? '' : String(val);

        return html.replace('value="' + esc(v) + '"', 'value="' + esc(v) + '" selected');
    }

    function input(campo, val, modo, deshabilitado, titulo) {
        return '<input type="text" class="form-control form-control-sm text-end palq-local" data-campo="' + campo + '"'
            + ' inputmode="' + modo + '" value="' + ((val === null || val === undefined) ? '' : esc(String(val))) + '"'
            + (deshabilitado ? ' disabled' : '') + (titulo ? ' title="' + esc(titulo) + '"' : '') + '>';
    }

    /**
     * La fila entera viaja al servidor, que valida y guarda. El JS solo avisa
     * antes lo que el servidor igual va a rechazar: un trimestral sin mes del
     * último ajuste.
     */
    function guardarLocal(fila) {
        var d = { nro: parseInt(fila.getAttribute('data-nro'), 10) };

        fila.querySelectorAll('.palq-local').forEach(function(el) {
            d[el.getAttribute('data-campo')] = el.value;
        });

        var mes = fila.querySelector('[data-campo="mes_ajuste"]');

        if (mes) {
            mes.disabled = (d.frecuencia === '1');
        }

        if (d.frecuencia && d.frecuencia !== '1' && !d.mes_ajuste) {
            Notificacion.advertencia('Con frecuencia ' + datos.frecuencias[d.frecuencia].toLowerCase()
                + ' hace falta el mes del último ajuste: es el que ancla el ciclo. Elegilo y se guarda la fila.');
            return;
        }

        escribir('guardarLocal', d);
    }

    /* ================================================================
       4. PAGOS EXTRAORDINARIOS
       ================================================================ */

    function pintarExtraForm() {
        var razon = document.getElementById('palqExtraRazon');

        if (!razon) { return; }

        razon.innerHTML = '<option value="">Elegí…</option>' + (datos.razones || []).map(function(r) {
            return '<option value="' + esc(r.cod) + '">' + esc(r.nombre) + '</option>';
        }).join('');

        document.getElementById('palqExtraMes').innerHTML = MESES.map(function(m, i) {
            return '<option value="' + (i + 1) + '"' + (i === 11 ? ' selected' : '') + '>' + m + '</option>';
        }).join('');

        document.getElementById('palqExtraConceptos').innerHTML = (datos.conceptos_extra || []).map(function(c) {
            return '<div class="form-check"><input class="form-check-input palq-extra-concepto" type="checkbox" '
                + 'value="' + c.id + '" id="palqExtraC' + c.id + '"' + (c.id === 8 ? ' checked' : '') + '>'
                + '<label class="form-check-label small" for="palqExtraC' + c.id + '">' + c.id + ' · '
                + esc(c.nombre) + '</label></div>';
        }).join('');

        localesDeLaRazon();
    }

    function localesDeLaRazon() {
        var cod = document.getElementById('palqExtraRazon').value;
        var sel = document.getElementById('palqExtraLocales');

        sel.innerHTML = (datos.locales || []).filter(function(l) {
            return l.params && l.params.cod_provee === cod && cod !== '';
        }).map(function(l) {
            return '<option value="' + l.nro + '">' + esc(l.nombre) + '</option>';
        }).join('');
        sel.disabled = document.getElementById('palqExtraTodos').checked;
    }

    function agregarExtra() {
        var d = {
            cod_provee: document.getElementById('palqExtraRazon').value,
            todos: document.getElementById('palqExtraTodos').checked,
            locales: Array.prototype.map.call(document.getElementById('palqExtraLocales').selectedOptions,
                function(o) { return parseInt(o.value, 10); }),
            conceptos: Array.prototype.map.call(document.querySelectorAll('.palq-extra-concepto:checked'),
                function(c) { return parseInt(c.value, 10); }),
            mes_base: document.getElementById('palqExtraMes').value,
            pct: document.getElementById('palqExtraPct').value,
            diferimiento: document.getElementById('palqExtraDifer').value
        };

        if (!d.cod_provee) {
            Notificacion.advertencia('Elegí la razón social.');
            return;
        }

        escribir('altaExtra', d);
    }

    function pintarExtras() {
        var cuerpo = document.getElementById('palqExtrasBody');
        var extras = datos.extras || [];
        var nombres = {};
        var locales = {};

        (datos.razones || []).forEach(function(r) { nombres[r.cod] = r.nombre; });
        (datos.locales || []).forEach(function(l) { locales[l.nro] = l.nombre; });

        if (!extras.length) {
            cuerpo.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Ningún pago extraordinario.</td></tr>';
            return;
        }

        cuerpo.innerHTML = extras.map(function(x) {
            var pago = MESES[(x.mes_base - 1 + x.diferimiento) % 12];

            return '<tr data-id="' + x.id + '">'
                + '<td>' + esc(nombres[x.cod_provee] || x.cod_provee)
                    + (nombres[x.cod_provee] ? '' : ' <span class="badge bg-warning text-dark">razón social dada de baja</span>') + '</td>'
                + '<td>' + (x.todos ? 'Todos' : esc(x.locales.map(function(n) { return locales[n] || n; }).join(', '))) + '</td>'
                + '<td>' + esc(x.conceptos.join(' + ')) + '</td>'
                + '<td>' + MESES[x.mes_base - 1] + '</td>'
                + '<td class="text-end">' + x.pct + ' %</td>'
                + '<td>' + pago + (x.diferimiento ? ' (' + x.diferimiento + ' mes/es después)' : '') + '</td>'
                + '<td><small class="text-muted">' + esc(Auditoria.linea('alta', x.usuario_alta, x.fecha_alta)) + '</small></td>'
                + '<td class="text-center"><button class="btn btn-sm btn-link text-danger p-0 palq-extra-baja" '
                    + 'title="Dar de baja"><i class="fas fa-xmark"></i></button></td>'
                + '</tr>';
        }).join('');

        cuerpo.querySelectorAll('.palq-extra-baja').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = parseInt(btn.closest('tr').getAttribute('data-id'), 10);

                Notificacion.confirmar({
                    titulo: 'Dar de baja el pago extraordinario',
                    mensaje: '¿Deja de repetirse?',
                    detalle: 'No se borra: queda en el historial.',
                    confirmar: 'Dar de baja',
                    peligro: true
                }).then(function(si) {
                    if (si) { escribir('bajaExtra', { id: id }); }
                });
            });
        });

        Permisos.soloLectura(cuerpo);
    }

    /* ================================================================
       UTILIDADES
       ================================================================ */

    /** Escribe y recarga: lo que se ve es siempre lo guardado. */
    function escribir(accion, cuerpo) {
        return pedir(ENDPOINT + '?action=' + accion, cuerpo)
            .then(function(d) {
                Notificacion.exito(d.message || 'Listo.');
                cargar();
            })
            .catch(function(e) {
                Notificacion.error(e.message);
                cargar();
            });
    }

    function buscarModulo(data, codigo) {
        if (!data || !data.modulos) { return null; }

        for (var i = 0; i < data.modulos.length; i++) {
            if (data.modulos[i].codigo === codigo) { return data.modulos[i]; }
        }

        return null;
    }

    function pedir(url, cuerpo) {
        var opciones = cuerpo
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) }
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

    function esc(t) {
        return String(t === null || t === undefined ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function valor(id, v) {
        var el = document.getElementById(id);

        if (el) { el.value = (v === null || v === undefined) ? '' : v; }
    }

    function texto(id, t) {
        var el = document.getElementById(id);

        if (el) { el.textContent = t || ''; }
    }

    function ocultar(id) {
        var el = document.getElementById(id);

        if (el) { el.style.display = 'none'; }
    }

    function enganchar(id, evento, fn) {
        var el = document.getElementById(id);

        if (el) { el.addEventListener(evento, fn); }
    }

    /* ================================================================
       ARRANQUE
       ================================================================ */

    function iniciar() {
        enganchar('palqBtnRefresh', 'click', cargar);
        enganchar('palqBtnGeneral', 'click', guardarGeneral);
        enganchar('palqRazonBuscar', 'input', buscarRazon);
        enganchar('palqRazonBuscar', 'focus', buscarRazon);
        enganchar('palqFiltroIncompletos', 'change', function() { if (datos) { pintarLocales(); } });
        enganchar('palqExtraRazon', 'change', localesDeLaRazon);
        enganchar('palqExtraTodos', 'change', localesDeLaRazon);
        enganchar('palqBtnExtra', 'click', agregarExtra);

        document.addEventListener('click', function(ev) {
            var caja = document.getElementById('palqRazonSugerencias');
            var input = document.getElementById('palqRazonBuscar');

            if (caja && input && !caja.contains(ev.target) && ev.target !== input) {
                ocultar('palqRazonSugerencias');
            }
        });

        /* El link "locales que no proyectan" de la pestaña Alquileres llega
           con el filtro pedido: se consume una vez. */
        if (window.cfAlquileresFiltro === 'INCOMPLETOS') {
            var f = document.getElementById('palqFiltroIncompletos');

            if (f) { f.checked = true; }
            window.cfAlquileresFiltro = null;
        }

        ParametrosCarga.registrar('ALQUILERES', cargar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
