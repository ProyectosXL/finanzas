<!--
    Parámetros → Pre-chequeado.

    Va en su propio archivo y con su propio JS, igual que Parámetros → Saldos y
    Cob. Electrónicos: no comparte nada con los bloques de Ventas, así que un
    problema acá no puede llevarse puesta la pestaña que ya funciona. Sus clases
    llevan el prefijo ppq- porque Parametros.js busca .param-input, .mix-* y
    .respaldo-* en TODO el documento.

    QUÉ ES ESTE MAESTRO
    Los clientes que entregan los cheques antes de que se les facture. Es lo que
    ACOTA la sub-pestaña Echeqs → Venta Cobrada Anticipada: sin clientes
    cargados, esa pantalla está vacía a propósito, porque una pantalla que por
    defecto tilda los cheques de todos los clientes netearía contra ventas que
    nadie prepagó.

    DOS COSAS QUE ESTA PANTALLA TIENE QUE HACER VISIBLES:

      1. El código se valida contra el maestro de clientes de Tango ANTES de
         guardar. Un código tipeado mal no da error: da una lista vacía y nadie
         entiende por qué.
      2. Cuántos cheques vivos tiene hoy cada cliente cargado. Es la única forma
         de que alguien note que cargó un código que no trae nada.

    Nunca hay baja física: se inhabilita.
-->
<div class="ppq-prechequeado">

    <div class="modulo-descripcion mb-3" id="descripcionPpq"></div>

    <div id="avisosParamPpq"></div>

    <div class="loading-spinner" id="loadingParamPpq">
        <div class="spinner"></div>
        <p>Cargando clientes pre-chequeados...</p>
    </div>

    <div id="wrapperParamPpq" style="display: none;">

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Clientes con venta cobrada anticipada</h5>
                    <small class="text-muted">
                        Sus cheques aparecen en <strong>Echeqs → Venta Cobrada Anticipada</strong>
                        ya <strong>tildados</strong>, y lo tildado se resta de la cobranza
                        proyectada de Ventas. La columna <strong>Cheques vivos</strong> dice
                        cuántos trae hoy cada uno: en cero, o el cliente no tiene cheques o el
                        código está mal. <strong>Días de pre-chequeado</strong> es cuántos días
                        antes del cheque se emite la factura: es lo que corre el neteo a la fecha
                        estimada de la venta.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaPpqClientes"
                            data-exportar-nombre="Parametros_Prechequeado"
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                    <button id="btnRefreshParamPpq" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sync-alt me-1"></i> Actualizar
                    </button>
                    <button id="btnNuevoClientePpq" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus me-1"></i> Agregar cliente
                    </button>
                </div>
            </div>

            <div class="card-body border-bottom" id="formClientePpq" style="display: none;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Código de cliente</label>
                        <input type="text" id="nuevoCodigoPpq" class="form-control form-control-sm"
                               maxlength="6" placeholder="Ej: FRCAST" autocomplete="off">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Razón social</label>
                        <!-- No se tipea: la trae Tango. Dos pantallas mostrando
                             dos nombres para el mismo código es peor que una sin
                             nombre. -->
                        <input type="text" id="razonSocialPpq" class="form-control form-control-sm"
                               readonly placeholder="Se busca con el código">
                    </div>
                    <div class="col-md-2">
                        <!-- Se pide EN EL ALTA: es parte de configurar al
                             cliente, no un dato opcional que se descubre
                             después. Cero es válido y significa "no desplazar",
                             pero tiene que ser una respuesta y no un olvido. -->
                        <label class="form-label form-label-sm">Días de pre-chequeado</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="diasPpq" class="form-control form-control-sm"
                                   min="0" max="365" step="1" value="0">
                            <span class="input-group-text">días</span>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="btnBuscarClientePpq" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-magnifying-glass me-1"></i> Buscar
                        </button>
                        <button id="btnAgregarClientePpq" class="btn btn-sm btn-primary flex-fill" disabled>
                            <i class="fas fa-check me-1"></i> Agregar
                        </button>
                        <button id="btnCancelarClientePpq" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div class="param-hint mt-2" id="hintClientePpq">
                    El código se busca en el maestro de clientes de Tango y tiene que existir para
                    poder guardarlo. <strong>Se distinguen mayúsculas de minúsculas</strong>: la
                    columna es binaria, así que <code>frcast</code> y <code>FRCAST</code> no son el
                    mismo cliente.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaPpqClientes">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Código</th>
                                <th>Razón social</th>
                                <th class="text-center" style="width: 190px;"
                                    title="Cuántos días antes del cheque se emite la factura. En cero, el cheque no se desplaza.">
                                    Días de pre-chequeado
                                </th>
                                <th class="text-center" style="width: 140px;">Cheques vivos</th>
                                <th class="text-center" style="width: 160px;">Última edición</th>
                                <th class="text-center" style="width: 130px;">Activo</th>
                            </tr>
                        </thead>
                        <tbody id="bodyClientesPpq"></tbody>
                    </table>
                </div>
            </div>

            <div class="card-body border-top">
                <!--
                    Los clientes en CERO se ven igual que el resto, con la marca
                    "sin desplazar". Es a propósito: si alguien esperaba un
                    corrimiento y en Echeqs ve el cheque en su propia fecha, el
                    motivo es este y tiene que poder encontrarlo. No hay valor
                    global de respaldo.
                -->
                <div class="param-hint mb-2">
                    <strong>Un cliente en 0 días no desplaza nada:</strong> su cheque se netea en su
                    propia fecha. No hay un valor global de respaldo — el parámetro
                    <code>dias_prechequeado</code> quedó sin uso.
                </div>
                <div class="param-hint">
                    <strong>Dar de baja un cliente no borra nada.</strong> Queda inhabilitado, sus
                    cheques dejan de aparecer y dejan de netear, y las marcas por cheque que se
                    hubieran cargado siguen ahí por si el cliente vuelve. El neteo de Ventas se
                    recalcula solo en el próximo pedido del tablero.
                </div>
            </div>
        </div>

    </div><!-- /wrapperParamPpq -->
</div><!-- /ppq-prechequeado -->
