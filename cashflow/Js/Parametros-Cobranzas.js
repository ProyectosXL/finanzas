/**
 * Parámetros - Cobranzas JavaScript
 * Administración de PPP calculado/manual y escalas de descuento por cliente
 */

(function() {
    'use strict';

    let clientesConfig = [];
    let clienteSeleccionado = null;
    let modalEscala = null;

    function inicializar() {
        console.log('Inicializando Parámetros - Cobranzas');

        var btnRefresh = document.getElementById('btnRefreshParamCob');
        if (btnRefresh) {
            btnRefresh.addEventListener('click', cargarClientes);
        }

        var inputBusqueda = document.getElementById('busquedaParamCob');
        if (inputBusqueda) {
            inputBusqueda.addEventListener('keyup', filtrarClientes);
        }

        var formTramo = document.getElementById('formNuevoTramoCob');
        if (formTramo) {
            formTramo.addEventListener('submit', guardarNuevoTramo);
        }

        var modalEl = document.getElementById('modalEscalaCob');
        if (modalEl && typeof bootstrap !== 'undefined') {
            modalEscala = new bootstrap.Modal(modalEl);
        }

        // Listener para el parámetro global de Mayoristas
        var inputMay = document.querySelector('input[data-clave="cobranzas_may_dias_vto"]');
        if (inputMay) {
            inputMay.addEventListener('change', function() {
                guardarParametroGlobal(this);
            });
        }

        // Cargar datos
        cargarParametroMayoristas();
        cargarClientes();
    }

    function cargarParametroMayoristas() {
        var inputMay = document.querySelector('input[data-clave="cobranzas_may_dias_vto"]');
        if (!inputMay) return;

        fetch('Controller/ParametrosController.php?action=getParametros')
            .then(res => res.json())
            .then(result => {
                if (result.success && result.data) {
                    var params = result.data;
                    var pMay = params.find(p => p.CLAVE === 'cobranzas_may_dias_vto');
                    if (pMay && pMay.VALOR) {
                        inputMay.value = pMay.VALOR;
                    }
                }
            })
            .catch(err => {
                console.error('Error al cargar parámetro cobranzas_may_dias_vto:', err);
            });
    }

    function guardarParametroGlobal(input) {
        var clave = input.getAttribute('data-clave');
        var valor = parseInt(input.value, 10);

        if (isNaN(valor) || valor <= 0) {
            alert('El valor de días debe ser un número entero mayor a 0.');
            return;
        }

        fetch('Controller/ParametrosController.php?action=saveParametro', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                clave: clave,
                valor: String(valor)
            })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var card = document.getElementById('card-' + clave);
                if (card) {
                    card.classList.add('bg-light-success');
                    setTimeout(function() {
                        card.classList.remove('bg-light-success');
                    }, 1200);
                }
            } else {
                alert('Error al guardar parámetro: ' + result.message);
            }
        })
        .catch(err => {
            alert('Error de conexión al guardar parámetro: ' + err.message);
        });
    }

    function mostrarCargando(mostrar) {
        var spinner = document.getElementById('loadingParamCob');
        var wrapper = document.getElementById('wrapperTablaParamCob');
        if (spinner) spinner.style.display = mostrar ? 'flex' : 'none';
        if (wrapper) wrapper.style.display = mostrar ? 'none' : 'block';
    }

    function cargarClientes() {
        mostrarCargando(true);
        fetch('Controller/ParametrosController.php?action=getCobranzasClientesConfig')
            .then(res => res.json())
            .then(result => {
                mostrarCargando(false);
                if (result.success) {
                    clientesConfig = result.data || [];
                    renderizarTabla();
                } else {
                    alert('Error al cargar configuración de cobranzas: ' + result.message);
                }
            })
            .catch(err => {
                mostrarCargando(false);
                console.error('Error:', err);
                alert('Error de conexión al cargar cobranzas: ' + err.message);
            });
    }

    function filtrarClientes() {
        var term = (document.getElementById('busquedaParamCob').value || '').toLowerCase().trim();
        var rows = document.querySelectorAll('#tbodyParamCob tr');
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(term) ? '' : 'none';
        });
    }

    function renderizarTabla() {
        var tbody = document.getElementById('tbodyParamCob');
        if (!tbody) return;

        if (clientesConfig.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No se encontraron clientes</td></tr>';
            return;
        }

        var html = '';
        clientesConfig.forEach(function(c) {
            var cod = c.cod_cliente;
            var medio = c.medio_pago_default || 'ECHEQ';
            var pppCalc = c.ppp_calculado > 0 ? `${c.ppp_calculado} días <small class="text-muted">(${c.cant_cobros} cobros)</small>` : '<span class="text-muted">-</span>';
            var pppManVal = (c.ppp_manual !== null && c.ppp_manual !== undefined) ? c.ppp_manual : '';
            var pppEfectivo = c.ppp_efectivo > 0 ? `<strong>${c.ppp_efectivo} días</strong>` : '<span class="text-muted">30 días (defecto)</span>';

            // Escalas configuradas del cliente
            var escalasHtml = '';
            var escalasCli = c.escalas || [];
            if (escalasCli.length > 0) {
                escalasCli.forEach(function(e) {
                    escalasHtml += `<span class="badge bg-light text-dark border me-1 mb-1">
                        ${e.dias_desde} a ${e.dias_hasta}d &rarr; <strong>${e.porcentaje_desc}%</strong>
                    </span>`;
                });
            } else {
                escalasHtml = `<span class="text-muted small">Sin escala específica (Máx: ${c.dias_pp_max}d &rarr; ${(c.desc_pp_max * 100).toFixed(0)}%)</span>`;
            }

            html += `<tr data-cod="${cod}">
                <td><code>${cod}</code></td>
                <td><strong>${c.razon_social}</strong></td>
                <td class="text-center">
                    <select class="form-select form-select-sm select-medio-pago mx-auto" style="max-width: 140px;" data-cod="${cod}">
                        <option value="ECHEQ" ${medio === 'ECHEQ' ? 'selected' : ''}>ECHEQ</option>
                        <option value="TRANSFERENCIA" ${medio === 'TRANSFERENCIA' ? 'selected' : ''}>TRANSFERENCIA</option>
                    </select>
                </td>
                <td class="text-center">${pppCalc}</td>
                <td class="text-center">
                    <div class="input-group input-group-sm justify-content-center" style="max-width: 140px; margin: 0 auto;">
                        <input type="number" min="0" step="1" class="form-control form-control-sm text-center input-ppp-manual" 
                               value="${pppManVal}" placeholder="${c.ppp_calculado > 0 ? c.ppp_calculado : 30}" data-cod="${cod}">
                        <button class="btn btn-outline-primary btn-save-ppp" title="Guardar PPP manual" data-cod="${cod}">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                </td>
                <td class="text-center text-primary">${pppEfectivo}</td>
                <td>${escalasHtml}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary btn-gestionar-escalas" data-cod="${cod}">
                        <i class="fas fa-tags me-1"></i> Escalas
                    </button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;

        // Listeners para cambiar medio de pago
        tbody.querySelectorAll('.select-medio-pago').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var cod = this.getAttribute('data-cod');
                var medio = this.value;
                guardarMedioPago(cod, medio);
            });
        });

        // Listeners para guardar PPP manual
        tbody.querySelectorAll('.btn-save-ppp').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cod = this.getAttribute('data-cod');
                var input = tbody.querySelector(`.input-ppp-manual[data-cod="${cod}"]`);
                guardarPPP(cod, input ? input.value : null);
            });
        });

        tbody.querySelectorAll('.input-ppp-manual').forEach(function(inp) {
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    var cod = this.getAttribute('data-cod');
                    guardarPPP(cod, this.value);
                }
            });
        });

        // Listeners para abrir modal de escalas
        tbody.querySelectorAll('.btn-gestionar-escalas').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cod = this.getAttribute('data-cod');
                abrirModalEscalas(cod);
            });
        });
    }

    function guardarMedioPago(codCliente, medioPago) {
        fetch('Controller/ParametrosController.php?action=saveMedioPagoCliente', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                cod_cliente: codCliente,
                medio_pago: medioPago
            })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                var cli = clientesConfig.find(c => c.cod_cliente === codCliente);
                if (cli) {
                    cli.medio_pago_default = medioPago;
                }
                renderizarTabla();
            } else {
                alert('Error al guardar medio de pago: ' + result.message);
                cargarClientes();
            }
        })
        .catch(err => {
            alert('Error de conexión al guardar medio de pago: ' + err.message);
            cargarClientes();
        });
    }

    function guardarPPP(codCliente, valor) {
        var pppVal = (valor !== '' && valor !== null) ? parseInt(valor, 10) : null;

        fetch('Controller/ParametrosController.php?action=savePPPManual', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                cod_cliente: codCliente,
                ppp_manual: pppVal
            })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                // Actualizar estado local
                var cli = clientesConfig.find(c => c.cod_cliente === codCliente);
                if (cli) {
                    cli.ppp_manual = pppVal;
                    cli.ppp_efectivo = (pppVal !== null && pppVal > 0) ? pppVal : (cli.ppp_calculado > 0 ? cli.ppp_calculado : 30);
                }
                renderizarTabla();
            } else {
                alert('Error al guardar PPP: ' + result.message);
            }
        })
        .catch(err => {
            alert('Error de conexión al guardar PPP: ' + err.message);
        });
    }

    function abrirModalEscalas(codCliente) {
        var cli = clientesConfig.find(c => c.cod_cliente === codCliente);
        if (!cli) return;

        clienteSeleccionado = cli;

        document.getElementById('modalEscalaClienteNombre').textContent = cli.razon_social;
        document.getElementById('modalEscalaClienteCod').textContent = 'Código: ' + cli.cod_cliente;
        document.getElementById('tramoCodCliente').value = cli.cod_cliente;
        document.getElementById('tramoDiasDesde').value = '';
        document.getElementById('tramoDiasHasta').value = '';
        document.getElementById('tramoPorcDesc').value = '';

        renderizarTramosModal();

        if (modalEscala) {
            modalEscala.show();
        } else {
            var modalEl = document.getElementById('modalEscalaCob');
            if (modalEl && typeof bootstrap !== 'undefined') {
                modalEscala = new bootstrap.Modal(modalEl);
                modalEscala.show();
            }
        }
    }

    function renderizarTramosModal() {
        var contenedor = document.getElementById('listaTramosActuales');
        if (!contenedor || !clienteSeleccionado) return;

        var escalas = clienteSeleccionado.escalas || [];
        if (escalas.length === 0) {
            contenedor.innerHTML = '<div class="p-3 text-muted text-center small">No hay tramos configurados para este cliente. Se aplicará el porcentaje por defecto.</div>';
            return;
        }

        var html = '';
        escalas.forEach(function(e) {
            html += `<div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                <div>
                    <span class="badge bg-primary me-2">${e.medio_pago || clienteSeleccionado.medio_pago_default || 'ECHEQ'}</span>
                    <strong>${e.dias_desde} a ${e.dias_hasta} días</strong>
                    <span class="text-success fw-bold ms-2">&rarr; ${e.porcentaje_desc}% Descuento</span>
                </div>
                <button class="btn btn-sm btn-outline-danger btn-eliminar-tramo py-0 px-2" data-id="${e.id}" title="Eliminar tramo">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>`;
        });

        contenedor.innerHTML = html;

        contenedor.querySelectorAll('.btn-eliminar-tramo').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                eliminarTramo(id);
            });
        });
    }

    function guardarNuevoTramo(e) {
        e.preventDefault();
        if (!clienteSeleccionado) return;

        var codCliente = document.getElementById('tramoCodCliente').value;
        var diasDesde = parseInt(document.getElementById('tramoDiasDesde').value, 10);
        var diasHasta = parseInt(document.getElementById('tramoDiasHasta').value, 10);
        var porcDesc = parseFloat(document.getElementById('tramoPorcDesc').value);
        var medioPago = clienteSeleccionado.medio_pago_default || 'ECHEQ';

        if (isNaN(diasDesde) || isNaN(diasHasta) || isNaN(porcDesc)) {
            alert('Por favor complete todos los campos numéricos correctamente.');
            return;
        }

        if (diasDesde < 0 || diasHasta < diasDesde) {
            alert('Días Desde debe ser mayor o igual a 0 y menor o igual a Días Hasta.');
            return;
        }

        fetch('Controller/ParametrosController.php?action=saveEscalaDescuento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: 0,
                cod_cliente: codCliente,
                medio_pago: medioPago,
                dias_desde: diasDesde,
                dias_hasta: diasHasta,
                porcentaje_desc: porcDesc
            })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                // Agregar al estado local
                if (!clienteSeleccionado.escalas) clienteSeleccionado.escalas = [];
                clienteSeleccionado.escalas.push({
                    id: result.data.id,
                    cod_cliente: codCliente,
                    medio_pago: medioPago,
                    dias_desde: diasDesde,
                    dias_hasta: diasHasta,
                    porcentaje_desc: porcDesc
                });

                // Ordenar tramos por dias_desde
                clienteSeleccionado.escalas.sort((a, b) => a.dias_desde - b.dias_desde);

                // Limpiar formulario y refrescar
                document.getElementById('tramoDiasDesde').value = '';
                document.getElementById('tramoDiasHasta').value = '';
                document.getElementById('tramoPorcDesc').value = '';

                renderizarTramosModal();
                renderizarTabla();
            } else {
                alert('Error al guardar tramo: ' + result.message);
            }
        })
        .catch(err => {
            alert('Error de conexión al guardar tramo: ' + err.message);
        });
    }

    function eliminarTramo(id) {
        if (!confirm('¿Seguro que deseas eliminar este tramo de descuento?')) return;

        fetch('Controller/ParametrosController.php?action=deleteEscalaDescuento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                if (clienteSeleccionado && clienteSeleccionado.escalas) {
                    clienteSeleccionado.escalas = clienteSeleccionado.escalas.filter(e => String(e.id) !== String(id));
                }
                renderizarTramosModal();
                renderizarTabla();
            } else {
                alert('Error al eliminar tramo: ' + result.message);
            }
        })
        .catch(err => {
            alert('Error de conexión al eliminar tramo: ' + err.message);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
})();
