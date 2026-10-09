<?php $tabName = 'Parámetros'; ?>
<link rel="stylesheet" href="Css/Parametros.css?v=<?php echo time(); ?>">

<div class="tab-parametros">

    <div class="alert alert-info d-flex align-items-start gap-2 py-2 px-3 mb-3">
        <i class="fas fa-circle-info mt-1"></i>
        <div>
            <small>
                Los parámetros están agrupados por la pestaña que afectan.
                Ninguna de estas constantes está escrita en el código: cambiar un
                plazo de acreditación o la alícuota recalcula la proyección.
            </small>
        </div>
    </div>

    <div class="cargando-slot" id="loadingParametros" data-cargando="Cargando parámetros…"></div>

    <div id="wrapperParametros" style="display: none;">

        <!-- Avisos de configuración pendiente (migraciones sin correr) -->
        <div id="avisosParametros"></div>

        <!-- Sub-pestañas por módulo.
             La lista se genera desde Parametros::$modulos, así que PARA AGREGAR
             UN MÓDULO alcanza con declararlo ahí y sumar su tab-pane más abajo.
             getModulos() es estático y no toca la base. -->
        <?php
            require_once __DIR__ . '/../Class/Parametros.php';
            $modulosParam = Parametros::getModulos();
        ?>
        <ul class="nav nav-tabs mb-3" id="parametrosTabs" role="tablist">
            <?php foreach ($modulosParam as $i => $m): ?>
                <?php $slug = ucfirst(strtolower($m['codigo'])); ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $i === 0 ? 'active' : ''; ?>"
                            id="tabParam<?php echo $slug; ?>Btn" data-bs-toggle="tab"
                            data-bs-target="#paneParam<?php echo $slug; ?>" type="button" role="tab">
                        <i class="fas <?php echo htmlspecialchars($m['icono']); ?> me-1"></i>
                        <?php echo htmlspecialchars($m['nombre']); ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">

        <!-- Lo que afecta a TODAS las pestañas: el horizonte, la alícuota, los
             feriados, la inflación mensual y el cronograma de pagos; más las
             dos cotizaciones, que se muestran y no se editan. Va PRIMERA por
             eso mismo. Mismo criterio que los demás módulos: archivo y JS
             propios, y clases con prefijo pgen- porque Parametros.js busca
             .param-input, .mix-* y .respaldo-* en TODO el documento. -->
        <div class="tab-pane fade show active" id="paneParamGenerales" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'GENERALES'); ?>>
            <?php include __DIR__ . '/parametros_generales.php'; ?>
        </div>

        <div class="tab-pane fade" id="paneParamVentas" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'VENTAS'); ?>>
            <?php /* La sub-pestaña Ventas vive en este archivo: su permiso se toma acá. */ $edita = AuthCashflow::puedeEditar('parametros', 'VENTAS'); ?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="modulo-descripcion flex-grow-1" id="descripcionVentas"></div>
            <!-- El botón vivía en la tarjeta Generales, que se fue a su propia
                 sub-pestaña. Recarga el payload entero, así que sigue sirviendo
                 para los dos bloques que quedan. -->
            <button id="btnRefreshParametros" class="btn btn-sm btn-outline-primary flex-shrink-0">
                <i class="fas fa-sync-alt me-1"></i> Actualizar
            </button>
        </div>

        <!-- La tarjeta "Generales" ESTUVO ACÁ y se fue a la sub-pestaña
             Generales: el horizonte y los feriados mueven las diez pantallas
             con eje temporal, y bajo Ventas parecían mover una sola. Lo que
             queda abajo es lo que de verdad sólo afecta a esta pestaña. -->

        <!-- ========================================================
             MIX DE COBRO Y PLAZOS: EL ÁRBOL
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Mix de Cobro y Plazos</h5>
                    <small class="text-muted">
                        Un árbol por canal: Medio de pago › Tipo de tarjeta › Procesadora › Cuotas, y en
                        Ecommerce un Marketplace arriba de todo. Cada nodo carga <strong>lo suyo</strong>:
                        su % entre sus hermanos, su costo, su tasa y sus días. Los hermanos
                        <strong>activos</strong> suman 100%; el costo y la tasa se suman a lo largo
                        de la rama, y los días los pone el nivel más cercano que los tenga.
                    </small>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <!-- Lo engancha Js/tabla-export.js por el data-exportar. Baja lo
                         que se ve, con el camino completo de cada nodo: cada nombre
                         lleva su camino en un texto que la pantalla no muestra. -->
                    <button class="btn btn-sm btn-outline-success" data-exportar="tablaMix"
                            data-exportar-nombre="Parametros_Mix_De_Cobro" data-lectura
                            title="Exportar a Excel lo que se está viendo">
                        <i class="fas fa-file-excel me-1"></i> Exportar
                    </button>
                </div>
            </div>

            <!-- Qué script falta, si falta: sin él, el mix se muestra sólo para consulta -->
            <div id="mixAvisos" class="px-3 pt-3"></div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <!-- data-orden="no": es un árbol. Ordenar por una columna
                         separaría cada nodo de su rama y de su grupo de hermanos,
                         que es lo que tiene que sumar 100%.
                         Agregar, Guardar y el formulario de alta los dibuja el JS
                         en la fila de cada canal y de cada nodo, sólo con permiso
                         de edición y con el script corrido. -->
                    <table id="tablaMix" class="table table-hover mb-0" data-orden="no">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th style="width: 150px;">Nivel</th>
                                <th class="text-center" style="width: 120px;">%</th>
                                <th class="text-center" style="width: 160px;"
                                    title="Lo que cobra este nivel. En gris, el acumulado de costo + tasa de toda la rama">Costo %</th>
                                <th class="text-center" style="width: 120px;">Tasa %</th>
                                <th class="text-center" style="width: 170px;"
                                    title="Vacío: los define un nivel de arriba. En gris, de dónde los hereda">Días</th>
                                <th class="text-center" style="width: 80px;">Activo</th>
                                <th style="width: 200px;">Última edición</th>
                            </tr>
                        </thead>
                        <tbody id="mixBody">
                            <!-- Se genera dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             PARTICIPACIÓN FIJA DE RESPALDO
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Participación Fija de Respaldo</h5>
                    <small class="text-muted">
                        Se usa cuando el mes del año anterior no tiene datos o su venta total es cero.
                        Esos meses quedan marcados como estimados. Debe sumar 100%.
                    </small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span id="sumaRespaldo" class="suma-participacion">0,00%</span>
                    <?php if ($edita): ?>
                    <button id="btnGuardarRespaldo" class="btn btn-sm btn-primary" disabled>
                        <i class="fas fa-floppy-disk me-1"></i> Guardar
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3" id="gridRespaldo">
                    <!-- Se genera dinámicamente -->
                </div>
            </div>
        </div>

        </div><!-- /paneParamVentas -->

        <!-- Parámetros del módulo Saldos: bancos y cuentas, otros saldos y la
             gestión de caja de cada local. Mismo criterio que el editor de
             estructura: archivo y JS propios, y clases con prefijo sp- porque
             Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO el
             documento. -->
        <div class="tab-pane fade" id="paneParamSaldos" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'SALDOS'); ?>>
            <?php include __DIR__ . '/parametros_saldos.php'; ?>
        </div>

        <!-- Parámetros del módulo Cob. Electrónicos: procesadoras de pago y las
             alícuotas de retención con las que se calcula el importe neto de
             cada acreditación. El id del pane sale de
             ucfirst(strtolower($codigo)), que es lo que usa el <li> generado
             arriba. Mismo criterio que Saldos: archivo y JS propios, y clases
             con prefijo pce-. -->
        <div class="tab-pane fade" id="paneParamCob_electronicos" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'COB_ELECTRONICOS'); ?>>
            <?php include __DIR__ . '/parametros_cob_electronicos.php'; ?>
        </div>

        <!-- Maestro de clientes que operan con venta cobrada anticipada. Acota
             el listado de Echeqs → Venta Cobrada Anticipada, que es de donde
             sale el neteo de cheques adelantados de Ventas. Mismo criterio que
             Saldos y Cob. Electrónicos: archivo y JS propios, y clases con
             prefijo ppq-. -->
        <div class="tab-pane fade" id="paneParamPrechequeado" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'PRECHEQUEADO'); ?>>
            <?php include __DIR__ . '/parametros_prechequeado.php'; ?>
        </div>

        <!-- Parámetros del módulo Cobranzas: PPP calculado y editable, y
             escalas de descuento por cliente. Mismo criterio que los anteriores:
             archivo y JS propios, y clases con prefijo pcob-. -->
        <div class="tab-pane fade" id="paneParamCobranzas" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'COBRANZAS'); ?>>
            <?php include __DIR__ . '/parametros_cobranzas.php'; ?>
        </div>

        <!-- Maestro de fleteros: quiénes son, cuántas horas por mes trabajan y
             cuánto vale su hora. El alta busca el código en CPA01, igual que el
             alta manual del maestro de Proveedores Locales. Mismo criterio que
             los anteriores: archivo y JS propios, y clases con prefijo plog-. -->
        <div class="tab-pane fade" id="paneParamLogistica" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'LOGISTICA'); ?>>
            <?php include __DIR__ . '/parametros_logistica.php'; ?>
        </div>

        <!-- Listas de opciones del maestro de Proveedores Locales: rubro
             económico, rubro, centro de costos, plazo y criterio de
             distribución. Antes eran texto libre, y el rubro económico no es
             cosmético: cada valor distinto crea una fila propia en el tablero.
             Mismo criterio que los anteriores: archivo y JS propios, y clases
             con prefijo pplo-. -->
        <div class="tab-pane fade" id="paneParamProv_locales" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'PROV_LOCALES'); ?>>
            <?php include __DIR__ . '/parametros_prov_locales.php'; ?>
        </div>

        <!-- Maestro de tarjetas: de qué tipo es cada una —y con eso, en qué
             sub-pestaña de Financiero › Pagos con Tarjetas y Otros aparece—, de
             qué banco, de quién, su % de cobertura y qué día del mes vence su
             resumen. Va PEGADA a Prov. Locales, igual que Logística: las
             facturas de Pagos Corporativos son facturas pendientes de Tango de
             proveedores locales. Mismo criterio que los anteriores: archivo y JS
             propios, y clases con prefijo ptar-. -->
        <div class="tab-pane fade" id="paneParamTarjetas" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'TARJETAS'); ?>>
            <?php include __DIR__ . '/parametros_tarjetas.php'; ?>
        </div>

        <!-- Parámetros del módulo Compras Exterior (código COMPRAS_PROY): cuántos meses, cuánta
             historia para la cuota y los tres días de la cadena de fechas.
             Mismo criterio que los anteriores: archivo y JS propios, y clases
             con prefijo pcpr-. -->
        <div class="tab-pane fade" id="paneParamCompras_proy" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'COMPRAS_PROY'); ?>>
            <?php include __DIR__ . '/parametros_compras_proy.php'; ?>
        </div>

        <!-- Estructura del tablero de Cashflow.
             Va en su propio archivo y con su propio JS: no comparte nada con
             los bloques de Ventas, y así un problema acá no puede llevarse
             puesta la pestaña que ya funciona. Sus clases llevan el prefijo
             cfe- porque Parametros.js busca .param-input, .mix-* y .respaldo-*
             en TODO el documento. -->
        <div class="tab-pane fade" id="paneParamCashflow" role="tabpanel"<?php echo AuthCashflow::atributoEdicion('parametros', 'CASHFLOW'); ?>>
            <?php include __DIR__ . '/parametros_estructura.php'; ?>
        </div>

        </div><!-- /tab-content -->

    </div><!-- /wrapperParametros -->
</div><!-- /tab-parametros -->

<script src="Js/Parametros.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Generales.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Saldos.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Cob-Electronicos.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Prechequeado.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Cobranzas.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Prov_locales.js?v=<?php echo time(); ?>"></script>
<script src="Js/Parametros-Estructura.js?v=<?php echo time(); ?>"></script>
