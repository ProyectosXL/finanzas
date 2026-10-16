<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'PRECHEQUEADO');
?>
<!--
    Parámetros → Cheques (código de módulo PRECHEQUEADO, que no cambió: es la
    clave de los permisos ya asignados).

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

    <div class="cargando-slot" id="loadingParamPpq" data-cargando="Cargando clientes pre-chequeados…"></div>

    <div id="wrapperParamPpq" style="display: none;">

        <!--
            DEPÓSITO DE CHEQUES: el plazo y la tasa con los que un cheque de
            cartera depositado acredita en Echeqs › Cheques en Cartera.

            Va PRIMERO porque mueve plata del tablero: decide en qué día y por
            cuánto entra cada cheque depositado a la fila "Echeqs depositados".

            La tasa es el mismo mecanismo que las alícuotas de una procesadora de
            Cob. Electrónicos —conceptos libres con vigencias, la suma de las
            vigentes a la fecha de acreditación— pero con un juego PROPIO: un
            cambio en la tasa de Payway no puede mover el neto de un cheque.
        -->
        <div class="card mb-4" id="seccionDepositoPpq">
            <div class="card-header">
                <h5 class="mb-0">Depósito de cheques</h5>
                <small class="text-muted">
                    Un cheque que Tesorería deposita en Tango —con una boleta de depósito— entra
                    en <em>Echeqs depositados</em> el día que acredita, a este plazo de la boleta,
                    por el <strong>neto</strong>: el importe menos la tasa de retención vigente a
                    esa fecha. La fecha del cheque no interviene.
                </small>
            </div>

            <!-- Sin el script, la sección lo dice acá y no dibuja controles que
                 fallarían al guardar. -->
            <div class="card-body border-bottom" id="sinScriptDepositoPpq" style="display: none;"></div>

            <div id="cuerpoDepositoPpq">
                <div class="card-body border-bottom">
                    <div class="row g-3 align-items-start">
                        <div class="col-md-5">
                            <label class="form-label form-label-sm fw-semibold" for="plazoDepositoPpq">
                                Plazo de acreditación
                            </label>
                            <div class="input-group input-group-sm" style="max-width: 260px;">
                                <input type="number" id="plazoDepositoPpq" class="form-control text-center"
                                       min="1" step="1"
                                       <?php echo $edita ? '' : 'disabled'; ?>>
                                <span class="input-group-text">días hábiles</span>
                                <?php if ($edita): ?>
                                <button class="btn btn-outline-primary" id="btnGuardarPlazoPpq" type="button">
                                    Guardar
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="param-hint mt-1" id="plazoHintPpq">
                                Días hábiles bancarios después del depósito: con 2, un cheque
                                depositado el lunes acredita el miércoles y uno del viernes, el
                                martes. Se cuenta desde la fecha de la boleta de depósito de
                                Tango. Cambiarlo <strong>mueve también los que todavía no
                                acreditaron</strong>: la fecha se calcula al leer, no se guarda.
                            </div>
                            <div class="ppq-subtitulo mt-1" id="plazoAuditoriaPpq"></div>
                        </div>
                        <div class="col-md-7">
                            <div class="form-label form-label-sm fw-semibold mb-1">Tasa de retención vigente hoy</div>
                            <!-- Sin alícuotas vigentes dice "sin alícuotas" y no
                                 0%: un cero se leería como "el banco no retiene
                                 nada", y sin tasa un depositado no tiene neto. -->
                            <div id="tasaHoyPpq" ></div>
                        </div>
                    </div>
                </div>

                <!-- Lo que dejó el último guardado de una alícuota y la tasa total
                     vigente. Queda a la vista. -->
                <div id="resultadoDepositoPpq"></div>

                <div class="card-body border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold">Alícuotas de retención de los cheques</div>
                        <small class="text-muted">
                            Conceptos libres —hoy IIBB y SICREB— con vigencias.
                            <strong>Una alícuota no se edita</strong>: se carga una vigencia nueva
                            y la anterior queda en el histórico, que es lo que explica la tasa de
                            un depósito de ese período.
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-success" data-exportar="tablaPpqAlicuotas"
                                data-exportar-nombre="Parametros_Alicuotas_Cheques"
                                title="Exportar a Excel lo que se está viendo">
                            <i class="fas fa-file-excel me-1"></i> Exportar
                        </button>
                        <?php if ($edita): ?>
                        <button id="btnNuevaAlicuotaPpq" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-plus me-1"></i> Cargar alícuota
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($edita): ?>
                <div class="card-body border-bottom" id="formAlicuotaPpq" style="display: none;">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Concepto</label>
                            <input type="text" id="nuevaAliConceptoPpq" class="form-control form-control-sm"
                                   maxlength="30" placeholder="IIBB" list="conceptosPpq">
                            <datalist id="conceptosPpq"></datalist>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Alícuota</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.0001" min="0" max="99.9999"
                                       id="nuevaAliPorcentajePpq" class="form-control text-end"
                                       placeholder="2,5">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Vigencia desde</label>
                            <input type="date" id="nuevaAliVigenciaPpq" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button id="btnAgregarAlicuotaPpq" class="btn btn-sm btn-primary flex-fill">
                                <i class="fas fa-check me-1"></i> Guardar vigencia
                            </button>
                            <button id="btnCancelarAlicuotaPpq" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                    </div>

                    <!-- La suma resultante, antes de guardar. Si llega a 100% el
                         botón se bloquea: el neto saldría cero o negativo. La
                         validación que vale es la del servidor. -->
                    <div class="pce-suma mt-2" id="sumaAlicuotaPpq"></div>
                </div>
                <?php endif; ?>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="tablaPpqAlicuotas">
                            <thead>
                                <tr>
                                    <th>Concepto</th>
                                    <th class="text-end" style="width: 130px;">Alícuota</th>
                                    <th class="text-center" style="width: 150px;">Vigencia desde</th>
                                    <th class="text-center" style="width: 130px;">Estado</th>
                                    <th class="text-center" style="width: 180px;">Cargada</th>
                                    <th class="text-center" style="width: 110px;" data-orden="no">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="bodyAlicuotasPpq"></tbody>
                        </table>
                    </div>
                </div>

                <div class="card-body border-top">
                    <div class="param-hint">
                        <strong>El neto de un cheque depositado no se guarda</strong>: se calcula
                        cada vez que se mira, con las alícuotas vigentes a su acreditación. Guardar
                        o dar de baja una alícuota cambia solo el neto de los que todavía no
                        acreditaron; los que ya acreditaron no se proyectan más. Sin ninguna
                        alícuota vigente a su acreditación, un cheque depositado <strong>no se
                        proyecta</strong> y la pestaña Echeqs lo avisa como crítico.
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-0">Venta cobrada anticipada</h5>
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
                    <?php if ($edita): ?>
                    <button id="btnNuevoClientePpq" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus me-1"></i> Agregar cliente
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($edita): ?>
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
            <?php endif; ?>

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
