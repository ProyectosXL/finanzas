<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'GENERALES');
?>
<?php
/**
 * Parametros -> Generales
 *
 * Lo que afecta a TODO el modulo, no es una fecha y no es de una pestana: la
 * alicuota, la inflacion mensual esperada y las cuentas excluidas de Gastos
 * Locales. Mas las dos cotizaciones, que se muestran y no se editan. Las
 * fechas -el horizonte, los feriados y el cronograma de pagos- se fueron a
 * Tabs/parametros_calendario.php.
 *
 * Archivo y JS propios, como los demas modulos de esta pestana, y clases con
 * prefijo pgen-. Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO
 * el documento, asi que dos modulos compartiendo esas clases se pisarian el
 * guardado.
 */
?>

<div class="pgen-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="pgenDescripcion"></div>
        <button id="pgenBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos de configuración pendiente: el script sin correr, la migración
         sin hacer, el calendario que no responde. La pantalla no rompe, avisa. -->
    <div id="pgenAvisos"></div>

    <!-- ========================================================
         LOS VALORES GENERALES (la alícuota)
         El horizonte y los feriados de comercio se fueron a
         Calendario. Hasta correr sql/cashflow_parametros_calendario.sql
         siguen figurando acá, y se dibujan igual: la grilla muestra
         todo lo que llega.
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Valores generales</h5>
            <small class="text-muted">
                La alícuota de IVA. <strong>Mueve todas las pestañas</strong> que proyectan
                venta, no sólo Ventas. El horizonte y los feriados están en
                Parámetros › Calendario.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3" id="pgenGrid">
                <!-- Se genera dinámicamente -->
            </div>
        </div>
    </div>

    <!-- ========================================================
         INFLACIÓN MENSUAL
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Inflación mensual esperada</h5>
                <small class="text-muted">
                    El % de cada mes calendario. Hoy lo usa el ajuste trimestral del valor
                    hora de Logística Local, que suma la inflación del mes del ajuste
                    <strong>y la de los dos anteriores</strong>, sin componer.
                </small>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <!-- Baja exactamente lo que se ve, con el mecanismo de siempre. -->
                <button class="btn btn-sm btn-outline-success" data-exportar="pgenTablaInflacion"
                        data-exportar-nombre="Parametros_Inflacion_Mensual"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>

        <!-- LAS DOS MODALIDADES. El selector no es cosmético: decide si se
             edita un campo o catorce. -->
        <div class="card-body border-bottom">
            <div class="row g-3 align-items-end">
                <?php if ($edita): ?>
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pgenModalidad">Modalidad</label>
                    <select id="pgenModalidad" class="form-select form-select-sm">
                        <option value="CONSTANTE">Constante — un único % para todos los meses</option>
                        <option value="VARIABLE">Variable — un % por mes</option>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($edita): ?>
                <div class="col-md-4" id="pgenConstanteWrap">
                    <label class="form-label form-label-sm" for="pgenPctConstante">
                        % mensual
                    </label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" class="form-control" id="pgenPctConstante">
                        <span class="input-group-text">%</span>
                        <button class="btn btn-primary" id="pgenAplicarConstante">
                            <i class="fas fa-check me-1"></i> Aplicar a todos
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <div class="param-hint mb-0">
                        Aplicar escribe ese % en <strong>cada mes</strong> de la grilla de
                        abajo, que es lo que el cálculo lee. Se hace así —y no resolviendo
                        la constante al calcular— para que los meses que van quedando atrás
                        conserven el valor con el que se proyectaron: si no, cambiar el %
                        movería hacia atrás un ajuste ya hecho.
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <!-- data-orden="no": no es un listado, es un formulario en orden
                     cronológico. Un ajuste suma tres meses consecutivos, y eso
                     sólo se lee si los meses están seguidos. -->
                <table id="pgenTablaInflacion" class="table table-sm table-hover mb-0"
                       data-orden="no">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th class="text-center" style="width: 170px;">% mensual</th>
                            <th class="text-center" style="width: 110px;">Cargado como</th>
                            <th>Última edición</th>
                            <th>Se proyecta</th>
                        </tr>
                    </thead>
                    <tbody id="pgenInflacionBody">
                        <!-- Se genera dinámicamente -->
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                Los <strong>tres primeros meses</strong> de la grilla ya pasaron y no se
                proyectan. Están para que un fletero cuyo valor hora base es anterior a hoy
                pueda resolver su primer ajuste: ese ajuste suma meses ya vencidos, y si no
                se pudieran cargar el mes quedaría sin proyectar para siempre.
                <strong>Ningún mes se borra</strong> al correrse la ventana.
            </small>
        </div>
    </div>

    <!-- ========================================================
         GASTOS LOCALES: LAS CUENTAS QUE NO ENTRAN EN EL PROMEDIO
         El día de pago está en el cronograma, en Calendario. Acá va lo otro
         que decide el número de la pestaña Proveedores › Gastos
         Locales: qué cuentas de caja se dejan afuera.
         ======================================================== -->
    <div class="card mb-4" id="pgenGastosLocales">
        <div class="card-header">
            <h5 class="mb-0">Gastos Locales</h5>
            <small class="text-muted">
                El promedio de los últimos 90 días toma los egresos de caja de los locales
                propios en las cuentas que empiezan con <strong>5</strong>. Las cuentas de esta
                lista <strong>no entran</strong>. El día de pago se elige en el cronograma
                de pagos, en Parámetros › Calendario.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label for="pgenCuentasExcluidas" class="form-label mb-1">
                        Cuentas excluidas
                        <span id="pgenCuentasAuditoria"></span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="pgenCuentasExcluidas"
                           placeholder="Por ejemplo: 522400, 531250"
                           title="Códigos de cuenta separados por coma. Vacío: no se excluye ninguna.">
                </div>
                <?php if ($edita): ?>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-primary" id="pgenGuardarCuentas">
                        <i class="fas fa-check me-1"></i> Guardar
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <small class="text-muted d-block mt-2" id="pgenCuentasNota">
                Códigos separados por coma. Si dejás la lista vacía, entran todas las cuentas 5.
            </small>
        </div>
    </div>

    <!-- ========================================================
         COTIZACIONES — SOLO LECTURA
         ======================================================== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                Cotizaciones
                <span class="badge bg-secondary ms-2">Solo lectura</span>
            </h5>
            <small class="text-muted">
                Las dos llegan por API y las mantiene otro proceso. <strong>No se editan
                desde acá</strong>: el único ajuste manual que existe en el módulo es el
                override de cotización por contenedor, en Comercio Exterior.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-7">
                    <h6 class="mb-1">Dólar futuro (ROFEX)</h6>
                    <div class="small text-muted mb-2" id="pgenDfPie"></div>
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table id="pgenTablaDolarFuturo" class="table table-sm mb-0"
                               data-orden="no">
                            <thead>
                                <tr>
                                    <th>Mes</th>
                                    <th>Símbolo</th>
                                    <th class="text-end">Cotización</th>
                                </tr>
                            </thead>
                            <tbody id="pgenDfBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <h6 class="mb-1">Dólar oficial BCRA</h6>
                    <div id="pgenBcra"></div>
                </div>
            </div>
        </div>
    </div>
</div>
