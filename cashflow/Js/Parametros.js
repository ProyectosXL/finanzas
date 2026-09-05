/**
 * Parámetros JavaScript
 * Pestaña de nivel raíz. Hoy expone los parámetros del módulo de Ventas
 * (generales, mix de cobro y participación de respaldo) y está pensada para ir
 * absorbiendo los de los demás módulos.
 */

(function() {
    'use strict';

    var datos = null;

    // Cómo se edita cada parámetro general. Los porcentajes se muestran en % y
    // se guardan en tasa; el resto va tal cual.
    var FORMATO = {
        'alicuota_iva':      { tipo: 'porcentaje', sufijo: '%',     paso: '0.01' },
        'dias_prechequeado': { tipo: 'entero',     sufijo: 'días',  paso: '1' },
        'horizonte_dias':    { tipo: 'entero',     sufijo: 'días',  paso: '1' },
        'horizonte_meses':   { tipo: 'entero',     sufijo: 'meses', paso: '1' },
        'feriados_comercio': { tipo: 'texto',      sufijo: '',      paso: null }
    };

    function inicializar() {
        console.log('Inicializando Parámetros');

        var btnRefresh = document.getElementById('btnRefreshParametros');
        var btnGuardarMix = document.getElementById('btnGuardarMix');
        var btnGuardarRespaldo = document.getElementById('btnGuardarRespaldo');

        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargar);
        }
        if (btnGuardarMix) {
            btnGuardarMix.addEventListener('click', guardarMix);
        }
        if (btnGuardarRespaldo) {
            btnGuardarRespaldo.addEventListener('click', guardarRespaldo);
        }

        cargar();
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

    function cargar() {
        mostrar('loadingParametros', true, 'flex');
        mostrar('wrapperParametros', false);

        pedir('Controller/ParametrosController.php?action=getTodo')
            .then(function(data) {
                datos = data;
                generarGenerales();
                generarMix();
                generarRespaldo();
                mostrar('loadingParametros', false);
                mostrar('wrapperParametros', true);
            })
            .catch(function(error) {
                mostrar('loadingParametros', false);
                mostrarError('Error al cargar los parámetros: ' + error.message);
            });
    }

    /* ================================================================
       GENERALES
       ================================================================ */

    function generarGenerales() {
        var html = '';

        datos.generales.forEach(function(param) {
            var fmt = FORMATO[param.CLAVE] || { tipo: 'texto', sufijo: '', paso: null };
            var valor = param.VALOR;

            if (fmt.tipo === 'porcentaje') {
                valor = (parseFloat(valor) * 100).toFixed(2);
            }

            var input = (fmt.tipo === 'texto')
                ? '<input type="text" class="form-control param-input text-start" ' +
                      'data-clave="' + param.CLAVE + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + escapar(valor) + '">'
                : '<input type="number" step="' + fmt.paso + '" class="form-control param-input" ' +
                      'data-clave="' + param.CLAVE + '" data-tipo="' + fmt.tipo + '" ' +
                      'value="' + escapar(valor) + '">';

            html += '<div class="col-md-6 col-lg-4">' +
                        '<div class="param-card" id="card-' + param.CLAVE + '">' +
                            '<div class="param-clave">' + etiqueta(param.CLAVE) + '</div>' +
                            '<div class="param-descripcion">' + escapar(param.DESCRIPCION || '') + '</div>' +
                            '<div class="input-group input-group-sm">' +
                                input +
                                (fmt.sufijo ? '<span class="input-group-text">' + fmt.sufijo + '</span>' : '') +
                            '</div>' +
                            '<div class="param-hint">' + hint(param.CLAVE) + '</div>' +
                        '</div>' +
                    '</div>';
        });

        document.getElementById('gridGenerales').innerHTML = html;

        // Guardado al salir del campo
        document.querySelectorAll('#gridGenerales .param-input').forEach(function(input) {
            input.addEventListener('change', function() {
                guardarGeneral(input);
            });
        });
    }

    function guardarGeneral(input) {
        var clave = input.dataset.clave;
        var tipo = input.dataset.tipo;
        var valor = input.value;

        if (tipo === 'porcentaje') {
            var num = parseFloat(valor);

            if (isNaN(num)) {
                alert('El valor de ' + etiqueta(clave) + ' debe ser numérico');
                return;
            }

            valor = String(num / 100);
        } else if (tipo === 'entero') {
            var ent = parseInt(valor, 10);

            if (isNaN(ent) || ent < 0) {
                alert('El valor de ' + etiqueta(clave) + ' debe ser un entero no negativo');
                return;
            }

            valor = String(ent);
        }

        pedir('Controller/ParametrosController.php?action=saveParametro', {
            clave: clave,
            valor: valor
        })
        .then(function() {
            destacar('card-' + clave);
        })
        .catch(function(error) {
            alert('Error al guardar ' + etiqueta(clave) + ': ' + error.message);
            cargar();
        });
    }

    /* ================================================================
       MIX DE COBRO
       ================================================================ */

    function generarMix() {
        var html = '';
        var canalAnterior = null;

        datos.mix.forEach(function(fila) {
            var esNuevoCanal = (fila.CANAL !== canalAnterior);

            // Cerrar el canal anterior con su fila de suma
            if (esNuevoCanal && canalAnterior !== null) {
                html += filaSumaCanal(canalAnterior);
            }

            canalAnterior = fila.CANAL;

            html += '<tr class="' + (esNuevoCanal ? 'mix-canal-inicio' : '') + '">';
            html += '<td class="fw-semibold">' + (esNuevoCanal ? titulo(fila.CANAL) : '') + '</td>';
            html += '<td>' + titulo(fila.MEDIO_PAGO) + '</td>';
            html += '<td>' +
                        '<div class="input-group input-group-sm">' +
                            '<input type="number" step="0.01" class="form-control mix-input mix-porcentaje" ' +
                                'data-id="' + fila.ID + '" data-canal="' + fila.CANAL + '" ' +
                                'value="' + (fila.PORCENTAJE * 100).toFixed(2) + '">' +
                            '<span class="input-group-text">%</span>' +
                        '</div>' +
                    '</td>';
            html += '<td>' +
                        '<div class="input-group input-group-sm">' +
                            '<input type="number" step="1" min="0" class="form-control mix-input mix-dias" ' +
                                'data-id="' + fila.ID + '" ' +
                                'value="' + fila.DIAS_ACREDITACION + '">' +
                            '<span class="input-group-text">días</span>' +
                        '</div>' +
                    '</td>';
            html += '</tr>';
        });

        // Fila de suma del último canal
        if (canalAnterior !== null) {
            html += filaSumaCanal(canalAnterior);
        }

        document.getElementById('mixBody').innerHTML = html;

        document.querySelectorAll('.mix-input').forEach(function(input) {
            input.addEventListener('input', validarMix);
        });

        validarMix();
    }

    function filaSumaCanal(canal) {
        return '<tr class="fila-suma-canal" data-canal-suma="' + canal + '">' +
                   '<td colspan="2" class="text-end">Suma ' + titulo(canal) + '</td>' +
                   '<td class="text-center" id="sumaMix-' + canal + '">0,00%</td>' +
                   '<td></td>' +
               '</tr>';
    }

    /**
     * El mix de cada canal debe sumar 100%. Si alguno no da, se bloquea el
     * guardado. La misma validación corre en el servidor.
     */
    function validarMix() {
        var sumas = {};

        document.querySelectorAll('.mix-porcentaje').forEach(function(input) {
            var canal = input.dataset.canal;
            sumas[canal] = (sumas[canal] || 0) + (parseFloat(input.value) || 0) / 100;
        });

        var todoValido = true;

        Object.keys(sumas).forEach(function(canal) {
            var valido = Math.abs(sumas[canal] - 1) < 0.000001;

            if (!valido) {
                todoValido = false;
            }

            var celda = document.getElementById('sumaMix-' + canal);
            var fila = document.querySelector('[data-canal-suma="' + canal + '"]');

            if (celda) {
                celda.textContent = formatPercent(sumas[canal]);
            }

            if (fila) {
                fila.classList.toggle('suma-valida', valido);
                fila.classList.toggle('suma-invalida', !valido);
            }
        });

        document.getElementById('btnGuardarMix').disabled = !todoValido;

        return todoValido;
    }

    function guardarMix() {
        if (!validarMix()) {
            return;
        }

        var porId = {};

        document.querySelectorAll('.mix-porcentaje').forEach(function(input) {
            var id = parseInt(input.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].porcentaje = (parseFloat(input.value) || 0) / 100;
        });

        document.querySelectorAll('.mix-dias').forEach(function(input) {
            var id = parseInt(input.dataset.id, 10);
            porId[id] = porId[id] || { id: id };
            porId[id].dias_acreditacion = parseInt(input.value, 10) || 0;
        });

        var filas = Object.keys(porId).map(function(k) { return porId[k]; });
        var btn = document.getElementById('btnGuardarMix');
        var textoOriginal = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Guardando...';

        pedir('Controller/ParametrosController.php?action=saveMixCobro', { filas: filas })
            .then(function() {
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Guardado';
                setTimeout(function() {
                    btn.innerHTML = textoOriginal;
                    cargar();
                }, 900);
            })
            .catch(function(error) {
                alert('Error al guardar el mix: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = textoOriginal;
            });
    }

    /* ================================================================
       PARTICIPACIÓN DE RESPALDO
       ================================================================ */

    function generarRespaldo() {
        var html = '';

        datos.respaldo.forEach(function(param) {
            var canal = param.CLAVE.replace('respaldo_', '');

            html += '<div class="col-md-6 col-lg-3">' +
                        '<div class="param-card">' +
                            '<div class="param-clave">' + titulo(canal) + '</div>' +
                            '<div class="param-descripcion">' + escapar(param.DESCRIPCION || '') + '</div>' +
                            '<div class="input-group input-group-sm">' +
                                '<input type="number" step="0.01" class="form-control param-input respaldo-input" ' +
                                    'data-clave="' + param.CLAVE + '" ' +
                                    'value="' + (parseFloat(param.VALOR) * 100).toFixed(2) + '">' +
                                '<span class="input-group-text">%</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
        });

        document.getElementById('gridRespaldo').innerHTML = html;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            input.addEventListener('input', validarRespaldo);
        });

        validarRespaldo();
    }

    function validarRespaldo() {
        var suma = 0;

        document.querySelectorAll('.respaldo-input').forEach(function(input) {
            suma += (parseFloat(input.value) || 0) / 100;
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

        document.getElementById('btnGuardarRespaldo').disabled = !valido;

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
                alert('Error al guardar las participaciones de respaldo: ' + error.message);
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

    function formatPercent(value) {
        var num = (parseFloat(value) || 0) * 100;

        return num.toLocaleString('es-AR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 4
        }) + '%';
    }

    /** alicuota_iva -> Alicuota Iva */
    function etiqueta(clave) {
        return titulo(String(clave).replace(/_/g, ' '));
    }

    function titulo(texto) {
        return String(texto).toLowerCase().replace(/(^|\s)\S/g, function(c) {
            return c.toUpperCase();
        });
    }

    function hint(clave) {
        var hints = {
            'alicuota_iva': 'Se aplica sobre la venta neta proyectada de los cuatro canales.',
            'dias_prechequeado': 'Días a restar a la fecha del cheque para obtener la fecha teórica de factura.',
            'horizonte_dias': 'Cantidad de columnas diarias de la proyección.',
            'horizonte_meses': 'Cantidad de columnas mensuales de la proyección.',
            'feriados_comercio': 'Formato MM-DD separado por coma. Únicos días del año sin venta estimada.'
        };

        return hints[clave] || '';
    }

    function escapar(texto) {
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function destacar(id) {
        var el = document.getElementById(id);

        if (!el) {
            return;
        }

        el.classList.add('param-guardado');
        setTimeout(function() {
            el.classList.remove('param-guardado');
        }, 1200);
    }

    function mostrar(id, visible, display) {
        var el = document.getElementById(id);

        if (el) {
            el.style.display = visible ? (display || 'block') : 'none';
        }
    }

    function mostrarError(mensaje) {
        console.error(mensaje);
        alert(mensaje);
    }

})(); // Fin del IIFE
