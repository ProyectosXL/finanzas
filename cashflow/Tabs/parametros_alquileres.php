<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'ALQUILERES');
?>
<?php
/**
 * Parametros -> Alquileres
 *
 * Cuatro secciones: el dia de vencimiento general, las razones sociales dadas
 * de alta, los datos de cada local de la app de alquileres y los pagos
 * extraordinarios. Archivo y JS propios, y clases con prefijo palq-.
 *
 * LAS RAZONES SOCIALES SE DAN DE ALTA. La seccion arranca vacia y el buscador
 * ofrece los proveedores con RUBRO = ALQUILER del maestro de Proveedores
 * Locales; de ahi se dan de alta los que son alquileres de locales. Que una
 * factura sea de alquiler lo va a decidir eso, no el rubro.
 *
 * NO HAY UNA LISTA PROPIA DE LOCALES: son los de la app de alquileres. Aca solo
 * se completan los datos que el cashflow necesita.
 */
?>

<div class="palq-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="palqDescripcion"></div>
        <button id="palqBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0" data-lectura>
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos: el script sin correr, locales incompletos, razones sociales que
         perdieron el rubro, proveedores con rubro que no están dados de alta. -->
    <div id="palqAvisos"></div>
    <div class="cargando-slot" id="palqCargando" data-cargando="Cargando los parámetros de Alquileres…"></div>

    <!-- ================================================================
         1. GENERALES
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Generales</h5>
            <small class="text-muted">
                El día del mes en que vence el alquiler, para las razones sociales que no tienen el
                suyo. Si cae en fin de semana o feriado, se paga el siguiente día hábil.
            </small>
        </div>
        <div class="card-body" id="palqGeneral">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label form-label-sm" for="palqDiaGeneral">Día de vencimiento</label>
                    <input type="number" id="palqDiaGeneral" class="form-control form-control-sm"
                           min="1" max="28" step="1" style="width: 110px;">
                </div>
                <?php if ($edita): ?>
                <div class="col-auto">
                    <button id="palqBtnGeneral" class="btn btn-sm btn-primary">
                        <i class="fas fa-save me-1"></i> Guardar
                    </button>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                    <small class="text-muted" id="palqGeneralAudit"></small>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         2. RAZONES SOCIALES
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Razones sociales</h5>
            <small class="text-muted">
                Sólo las <strong>dadas de alta</strong> son alquileres: se pueden elegir como razón
                social de un local o de un pago extraordinario, y (cuando lleguen las facturas) sus
                facturas salen de Proveedores Locales. Los <strong>meses de diferimiento</strong>
                corren el pago de los conceptos variables (6, 7, 14, 16 y 17): con 2, la venta de
                septiembre se paga en noviembre.
            </small>
        </div>

        <?php if ($edita): ?>
        <div class="card-body border-bottom">
            <div class="row g-2 align-items-end">
                <div class="col-md-6 position-relative">
                    <label class="form-label form-label-sm" for="palqRazonBuscar">Dar de alta</label>
                    <input type="text" id="palqRazonBuscar" class="form-control form-control-sm"
                           placeholder="Razón social o código…" autocomplete="off">
                    <div id="palqRazonSugerencias" class="list-group position-absolute w-100 shadow"
                         style="z-index: 1050; max-height: 260px; overflow-y: auto; display: none;">
                    </div>
                    <div class="form-text">
                        Los proveedores con RUBRO = ALQUILER en el maestro de Proveedores Locales.
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="palqRazonesTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr>
                            <th style="width: 90px;">Código</th>
                            <th>Razón social</th>
                            <th style="width: 80px;" class="text-end">Locales</th>
                            <th style="width: 130px;" title="Vacío = el general">Día de vto.</th>
                            <th style="width: 130px;">Meses de diferimiento</th>
                            <th>Último cambio</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="palqRazonesBody"></tbody>
                </table>
            </div>
        </div>
        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>Dar de baja no borra</strong>: queda en el historial, con quién y cuándo. Sus
                locales pasan a Incompletos hasta que se les elija otra.
            </small>
        </div>
    </div>

    <!-- ================================================================
         3. LOCALES
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Locales</h5>
                <small class="text-muted">
                    Los mismos locales que la app de alquileres. Cada cambio se guarda al salir del
                    campo, con tu usuario y la hora, y rige desde ese momento. La <strong>venta
                    mensual estimada</strong> (neta, sin IVA) es sólo para los locales nuevos, sin
                    12 meses de venta.
                </small>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="palqFiltroIncompletos">
                    <label class="form-check-label small" for="palqFiltroIncompletos">Incompletos</label>
                </div>
                <button class="btn btn-sm btn-outline-success" data-exportar="palqLocalesTabla" data-lectura
                        data-exportar-nombre="Parametros_Alquileres_Locales"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="palqLocalesTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr>
                            <th>Local</th>
                            <th>Estado</th>
                            <th style="min-width: 200px;">Razón social</th>
                            <th style="min-width: 120px;">Frecuencia</th>
                            <th style="min-width: 110px;">Mes últ. ajuste</th>
                            <th style="width: 90px;">% IVA</th>
                            <th style="width: 90px;">% Percep.</th>
                            <th style="width: 90px;">% Ret.</th>
                            <th style="width: 150px;">Fecha de fin</th>
                            <th style="width: 150px;" title="Neta, sin IVA. Sólo locales nuevos">Venta mensual est.</th>
                            <th>Último cambio</th>
                        </tr>
                    </thead>
                    <tbody id="palqLocalesBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         4. PAGOS EXTRAORDINARIOS
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Pagos extraordinarios</h5>
            <small class="text-muted">
                Un % sobre uno o varios conceptos de un mes del año, que se paga en ese mes más el
                diferimiento y <strong>se repite todos los años</strong> hasta que se da de baja.
                Ejemplo: Cencosud, 75 % del VMA (8) de diciembre, 1 mes de diferimiento: se paga en
                enero. Lleva el IVA, la percepción y la retención de cada local.
            </small>
        </div>

        <?php if ($edita): ?>
        <div class="card-body border-bottom" id="palqExtraForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="palqExtraRazon">Razón social</label>
                    <select id="palqExtraRazon" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="palqExtraLocales">Locales</label>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="palqExtraTodos" checked>
                        <label class="form-check-label small" for="palqExtraTodos">
                            Todos los de la razón social (también los que se agreguen después)
                        </label>
                    </div>
                    <select id="palqExtraLocales" class="form-select form-select-sm" multiple size="4" disabled></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Conceptos base</label>
                    <div id="palqExtraConceptos" class="border rounded px-2 py-1" style="max-height: 120px; overflow-y: auto;"></div>
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="palqExtraMes">Mes base</label>
                    <select id="palqExtraMes" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="palqExtraPct">% incremento</label>
                    <input type="text" id="palqExtraPct" class="form-control form-control-sm" inputmode="decimal" placeholder="75">
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="palqExtraDifer" title="El pago cae en el mes base más estos meses">Diferim.</label>
                    <input type="number" id="palqExtraDifer" class="form-control form-control-sm" min="0" max="12" value="1">
                </div>
                <div class="col-md-1">
                    <button id="palqBtnExtra" class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-plus me-1"></i> Agregar
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="palqExtrasTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr>
                            <th>Razón social</th>
                            <th>Locales</th>
                            <th>Conceptos base</th>
                            <th>Mes base</th>
                            <th class="text-end">%</th>
                            <th>Se paga</th>
                            <th>Alta</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="palqExtrasBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Parametros-Alquileres.js?v=<?php echo time(); ?>"></script>
