<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'PRESTAMOS');
?>
<?php
/**
 * Parametros -> Prestamos
 *
 * Los valores GENERALES de los prestamos: cada prestamo nuevo los copia al
 * darse de alta, y un cambio aca no toca a los que ya existen. Seis bloques:
 * tasas de referencia, costos asociados, comision de cancelacion, condiciones
 * del simulador y margen, y monedas. Archivo y JS propios, clases con prefijo
 * ppr-.
 */
?>

<div class="ppr-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="pprDescripcion"></div>
        <button id="pprBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0" data-lectura>
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <div id="pprAvisos"></div>
    <div class="cargando-slot" id="pprCargando" data-cargando="Cargando los parámetros de Préstamos…"></div>

    <div class="alert alert-info small">
        <i class="fas fa-circle-info me-1"></i>
        Estos son los valores <strong>generales</strong>. Cada préstamo nuevo los copia al darse de alta, y en el préstamo
        se cambia solo lo que su contrato tenga distinto. <strong>Un cambio acá vale para los préstamos que se den de alta
        después</strong>: los que ya existen conservan sus condiciones. Cada cambio se guarda al salir del campo.
    </div>

    <!-- ================================================================
         1. TASAS DE REFERENCIA
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Tasas de referencia (TAMAR y BADLAR)</h5>
            <small class="text-muted">
                Las publica el BCRA y las trae un proceso de Sistemas todos los días a las 8:00; acá solo se leen. Un préstamo
                variable se proyecta con <strong>el promedio de los últimos días hábiles publicados</strong> más su spread (o con el
                último valor, si el préstamo lo pide). Un valor cargado a mano para un mes gana sobre el promedio en los períodos
                que empiezan ese mes.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3" id="pprReferencias"></div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pprDIAS_PROMEDIO">Días hábiles del promedio</label>
                    <input type="number" id="pprDIAS_PROMEDIO" class="form-control form-control-sm ppr-general" data-clave="DIAS_PROMEDIO" min="1" max="30">
                    <small class="text-muted ppr-audit" data-clave="DIAS_PROMEDIO"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pprANTIGUEDAD_MAX">Antigüedad tolerada del último valor (días hábiles)</label>
                    <input type="number" id="pprANTIGUEDAD_MAX" class="form-control form-control-sm ppr-general" data-clave="ANTIGUEDAD_MAX" min="1" max="30">
                    <small class="text-muted ppr-audit" data-clave="ANTIGUEDAD_MAX"></small>
                </div>
            </div>

            <h6 class="mt-4 mb-1">Valor proyectado a mano, por mes</h6>
            <small class="text-muted d-block mb-2">Opcional. En % nominal anual, como lo publica el BCRA.</small>
            <?php if ($edita): ?>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprTasaRef">Tasa</label>
                    <select id="pprTasaRef" class="form-select form-select-sm">
                        <option value="TAMAR">TAMAR</option>
                        <option value="BADLAR">BADLAR</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprTasaMes">Mes</label>
                    <input type="month" id="pprTasaMes" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprTasaValor">Valor (%)</label>
                    <input type="text" id="pprTasaValor" class="form-control form-control-sm" inputmode="decimal">
                </div>
                <div class="col-md-3">
                    <button id="pprBtnTasa" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Guardar</button>
                </div>
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table id="tablaPprTasas" class="table table-sm align-middle mb-0">
                    <thead><tr><th>Tasa</th><th>Mes</th><th class="text-end">Valor</th><th>Cargado</th><th></th></tr></thead>
                    <tbody id="pprTasasManual"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         2. COSTOS ASOCIADOS
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Costos asociados</h5>
            <small class="text-muted">
                <strong>Inicial</strong>: se calcula sobre el capital y se paga el día del desembolso.
                <strong>Por cuota</strong>: sobre el interés de cada cuota, o sobre el saldo de capital al inicio del período.
                Si lleva IVA, se le suma la alícuota de Parámetros › Generales. Al dar de alta un préstamo se copian los
                <strong>activos</strong>.
            </small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaPprCostos" class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Costo</th><th class="text-end">%</th><th>Base</th><th>Momento</th><th class="text-center">IVA</th>
                            <th class="text-center">Activo</th><th></th></tr>
                    </thead>
                    <tbody id="pprCostos"></tbody>
                </table>
            </div>
            <?php if ($edita): ?>
            <button id="pprBtnCosto" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-plus me-1"></i> Agregar costo</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================================
         3. COMISION DE CANCELACION ANTICIPADA
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Comisión de cancelación anticipada</h5>
            <small class="text-muted">
                Se cobra sobre el saldo de capital en la cancelación total y sobre el monto adelantado en la parcial. Puede ser fija o
                escalonada según el % del plazo transcurrido. No hay exención automática por plazo: esa regla es para personas
                humanas y no aplica a empresas.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprCANCEL_MODO">Tipo</label>
                    <select id="pprCANCEL_MODO" class="form-select form-select-sm ppr-general" data-clave="CANCEL_MODO">
                        <option value="FIJA">Fija</option>
                        <option value="ESCALONADA">Escalonada por tramos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprCANCEL_PCT_TOTAL">% cancelación total</label>
                    <input type="text" id="pprCANCEL_PCT_TOTAL" class="form-control form-control-sm ppr-general" data-clave="CANCEL_PCT_TOTAL" inputmode="decimal">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprCANCEL_PCT_PARCIAL">% cancelación parcial</label>
                    <input type="text" id="pprCANCEL_PCT_PARCIAL" class="form-control form-control-sm ppr-general" data-clave="CANCEL_PCT_PARCIAL" inputmode="decimal">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" id="pprCANCEL_IVA" class="form-check-input ppr-general" data-clave="CANCEL_IVA">
                        <label class="form-check-label" for="pprCANCEL_IVA">Lleva IVA</label>
                    </div>
                </div>
            </div>

            <div id="pprTramosBloque" class="mt-3">
                <h6 class="mb-1">Tramos (para la escalonada)</h6>
                <small class="text-muted d-block mb-2">Desde qué % del plazo transcurrido rige cada %. El primero empieza en 0.</small>
                <table id="tablaPprTramos" class="table table-sm align-middle mb-2" style="max-width: 640px">
                    <thead><tr><th>Desde (% del plazo)</th><th>% total</th><th>% parcial</th><th></th></tr></thead>
                    <tbody id="pprTramos"></tbody>
                </table>
                <?php if ($edita): ?>
                <button id="pprBtnTramoAgregar" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus me-1"></i> Agregar tramo</button>
                <button id="pprBtnTramos" class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i> Guardar tramos</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         4. SIMULADOR Y MARGEN
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Simulador</h5>
            <small class="text-muted">
                Las condiciones con las que se arma un préstamo simulado desde el botón <strong>Simular préstamo</strong>, y el margen de
                seguridad del monto sugerido: <strong>necesidad × (1 + margen) / (1 − % de costos iniciales)</strong>.
            </small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprSIM_SISTEMA">Sistema</label>
                    <select id="pprSIM_SISTEMA" class="form-select form-select-sm ppr-general" data-clave="SIM_SISTEMA">
                        <option value="FRANCES">Francés</option>
                        <option value="ALEMAN">Alemán</option>
                        <option value="BULLET">Bullet</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="pprSIM_CUOTAS">Cuotas</label>
                    <input type="number" id="pprSIM_CUOTAS" class="form-control form-control-sm ppr-general" data-clave="SIM_CUOTAS" min="1" max="360">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pprSIM_FRECUENCIA">Frecuencia</label>
                    <select id="pprSIM_FRECUENCIA" class="form-select form-select-sm ppr-general" data-clave="SIM_FRECUENCIA">
                        <option value="MENSUAL">Mensual</option>
                        <option value="TRIMESTRAL">Trimestral</option>
                        <option value="SEMESTRAL">Semestral</option>
                        <option value="ANUAL">Anual</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="pprSIM_TNA">TNA fija (%)</label>
                    <input type="text" id="pprSIM_TNA" class="form-control form-control-sm ppr-general" data-clave="SIM_TNA" inputmode="decimal">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="pprMARGEN">Margen de seguridad (%)</label>
                    <input type="text" id="pprMARGEN" class="form-control form-control-sm ppr-general" data-clave="MARGEN" inputmode="decimal">
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         5. MONEDAS
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Monedas</h5></div>
        <div class="card-body small">
            Pesos (ARS) y dólares (USD). Un préstamo en dólares se pasa a pesos con el dólar futuro del mes de cada pago, igual que
            Comercio Exterior y Llaves.
        </div>
    </div>
</div>

<script src="Js/Parametros-Prestamos.js?v=<?php echo time(); ?>"></script>
