<?php
/* Sin permiso de edicion de esta sub-pestaña los controles de escritura no
   se dibujan. Se define aca y no en parametros.php porque cada sub-pestaña
   tiene su permiso, y todas comparten el alcance de ese archivo. */
$edita = AuthCashflow::puedeEditar('parametros', 'LLAVES_RENOV');
?>
<?php
/**
 * Parametros -> Llaves y Renovaciones
 *
 * Siete secciones: generales, por operador, por local (las renovaciones en
 * curso), ya pagadas, obras de imagen, aperturas y compromisos a mano. Archivo
 * y JS propios, y clases con prefijo pll-.
 *
 * LOS CONTRATOS NO SE CARGAN ACA: salen de la app de alquileres. Aca solo va
 * lo que la app no tiene. Lo que se SUGIERE (los cortes por m², el % de cada
 * operador) no proyecta nada hasta que una persona lo confirma.
 */
?>

<div class="pll-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="pllDescripcion"></div>
        <button id="pllBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0" data-lectura>
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <div id="pllAvisos"></div>
    <div class="cargando-slot" id="pllCargando" data-cargando="Cargando los parámetros de Llaves y Renovaciones…"></div>

    <!-- ================================================================
         1. GENERALES
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Generales</h5>
            <small class="text-muted">
                La llave estimada es <strong>% × (alquiler + complementario + VMA del mes de la primera cuota,
                como lo proyecta Alquileres) × meses del contrato nuevo</strong>, en cuotas mensuales consecutivas. La obra y la
                apertura se pagan el día de pago del mes estimado, corrido al hábil siguiente. Cada cambio se
                guarda al salir del campo.
            </small>
        </div>
        <div class="card-body" id="pllGenerales">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pllPCT_LLAVE">% de llave sobre el alquiler</label>
                    <input type="text" id="pllPCT_LLAVE" class="form-control form-control-sm pll-general" data-clave="PCT_LLAVE" inputmode="decimal">
                    <small class="text-muted pll-audit" data-clave="PCT_LLAVE"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pllCUOTAS">Cuotas de la llave</label>
                    <input type="number" id="pllCUOTAS" class="form-control form-control-sm pll-general" data-clave="CUOTAS" min="1" max="60">
                    <small class="text-muted pll-audit" data-clave="CUOTAS"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pllDIA_PAGO">Día de pago de la obra y la apertura</label>
                    <input type="number" id="pllDIA_PAGO" class="form-control form-control-sm pll-general" data-clave="DIA_PAGO" min="1" max="28">
                    <small class="text-muted pll-audit" data-clave="DIA_PAGO"></small>
                </div>
            </div>

            <h6 class="mt-4 mb-1">Segmento por m² totales (venta + depósito + baulera)</h6>
            <small class="text-muted d-block mb-2" id="pllCortesSugeridos"></small>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pllCORTE_CHICO">Chico hasta (m²)</label>
                    <input type="text" id="pllCORTE_CHICO" class="form-control form-control-sm pll-general" data-clave="CORTE_CHICO" inputmode="decimal">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="pllCORTE_MEDIANO">Mediano hasta (m²)</label>
                    <input type="text" id="pllCORTE_MEDIANO" class="form-control form-control-sm pll-general" data-clave="CORTE_MEDIANO" inputmode="decimal">
                </div>
                <?php if ($edita): ?>
                <div class="col-md-3">
                    <button id="pllBtnCortes" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-check me-1"></i> Confirmar los sugeridos
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <h6 class="mt-4 mb-2">Costo en U$S por segmento</h6>
            <div class="table-responsive">
                <table id="pllCostosTabla" class="table table-sm align-middle mb-0" style="max-width: 640px;" data-orden="no">
                    <thead>
                        <tr><th></th><th>Chico</th><th>Mediano</th><th>Grande</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th class="fw-normal">Obra de imagen</th>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_OBRA_CHICO" inputmode="decimal"></td>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_OBRA_MEDIANO" inputmode="decimal"></td>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_OBRA_GRANDE" inputmode="decimal"></td>
                        </tr>
                        <tr>
                            <th class="fw-normal">Apertura</th>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_APERTURA_CHICO" inputmode="decimal"></td>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_APERTURA_MEDIANO" inputmode="decimal"></td>
                            <td><input type="text" class="form-control form-control-sm pll-general" data-clave="USD_APERTURA_GRANDE" inputmode="decimal"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         2. POR OPERADOR
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Por operador</h5>
            <small class="text-muted">
                Los operadores son las razones sociales dadas de alta en Parámetros › Alquileres. Vacío en el
                <strong>% de llave</strong> o en las <strong>cuotas</strong> es el general; vacío en el
                <strong>escribano</strong> o en el <strong>% de impuestos</strong> es «sin dato» y avisa (sin %
                de impuestos la llave no se proyecta): sólo 0 cargado es 0. El <strong>corrimiento</strong> mueve
                la primera cuota respecto del inicio del contrato nuevo (negativo = antes). En gris, lo sugerido
                por la historia: el tooltip dice de dónde sale.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllOperadoresTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr>
                            <th>Operador</th>
                            <th>Locales</th>
                            <th style="width: 110px;">% de llave</th>
                            <th style="width: 110px;" title="Comisiones / llave estimada. Se pagan en las mismas cuotas que la llave">% comisiones</th>
                            <th style="width: 110px;" title="FPC / llave estimada. Se paga en la firma">% FPC</th>
                            <th style="width: 140px;" title="Un importe en $ por renovación, sin impuestos">Escribano y otros</th>
                            <th style="width: 110px;" title="Lo que factura de más sobre la llave y los otros gastos">% impuestos</th>
                            <th style="width: 90px;">Cuotas</th>
                            <th style="width: 100px;" title="Meses respecto del inicio del contrato nuevo">Corrim.</th>
                            <th>Último cambio</th>
                        </tr>
                    </thead>
                    <tbody id="pllOperadoresBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         3. POR LOCAL: las renovaciones que se estiman
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Por local</h5>
            <small class="text-muted">
                Los locales con un contrato que vence dentro del horizonte o ya vencido, y los que ya tienen la
                renovación cargada en la app con pagos por delante. Lo que se carga acá vale para
                <strong>esa</strong> renovación. Cuando administración carga el contrato nuevo, lo que dice
                <strong>cuánto</strong> (no renueva, llave a mano, % propio, meses) deja de aplicarse, porque manda
                el importe del contrato, y esas columnas quedan deshabilitadas; lo que dice <strong>cómo se
                paga</strong> (cuotas y 1.ª cuota) sigue valiendo y se puede cambiar. Vacío = el valor de arriba.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllLocalesTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr>
                            <th>Local</th>
                            <th title="El contrato que rige: el nuevo, si la renovación está cargada; si no, el vigente">Contrato</th>
                            <th>Estado</th>
                            <th style="min-width: 220px;">No renueva</th>
                            <th style="width: 140px;" title="Reemplaza el cálculo">Llave a mano ($)</th>
                            <th style="width: 90px;">% propio</th>
                            <th style="width: 90px;" title="Por defecto, los del último contrato con llave">Meses</th>
                            <th style="width: 80px;">Cuotas</th>
                            <th style="width: 140px;" title="Por defecto, el del día siguiente al fin del contrato">1.ª cuota</th>
                            <th>Último cambio</th>
                        </tr>
                    </thead>
                    <tbody id="pllLocalesBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         4. YA PAGADAS
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Ya pagadas</h5>
            <small class="text-muted">
                La llave o los otros gastos (comisiones, FPC y escribano) de una renovación que <strong>ya salieron de
                caja</strong> (por ejemplo,
                una renovación pagada antes de que administración cargue el contrato nuevo). Ese concepto deja de
                proyectarse en esa renovación, cargada o estimada, y la marca sigue valiendo cuando se carga el
                contrato. No suma en el horizonte. El monto es opcional: sólo informa.
            </small>
        </div>
        <?php if ($edita): ?>
        <div class="card-body border-bottom" id="pllPagadaForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label form-label-sm" for="pllPagadaRen">Renovación</label>
                    <select id="pllPagadaRen" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="pllPagadaFecha">Fecha del pago</label>
                    <input type="date" id="pllPagadaFecha" class="form-control form-control-sm">
                </div>
                <!-- Los dos conceptos tildados por defecto: lo normal es marcar la
                     renovación entera; marcar sólo la llave deja los otros gastos
                     proyectados sin que nadie lo note. El monto de cada uno es
                     opcional: es informativo, no suma ni resta. -->
                <div class="col-md-2">
                    <div class="form-check mb-1">
                        <input class="form-check-input pll-pag-concepto" type="checkbox" id="pllPagadaLlave" value="LLAVE" checked>
                        <label class="form-check-label small" for="pllPagadaLlave">Llave ($)</label>
                    </div>
                    <input type="text" id="pllPagadaMontoLLAVE" class="form-control form-control-sm" inputmode="decimal" placeholder="opcional">
                </div>
                <div class="col-md-2">
                    <div class="form-check mb-1">
                        <input class="form-check-input pll-pag-concepto" type="checkbox" id="pllPagadaOtros" value="OTROS" checked>
                        <label class="form-check-label small" for="pllPagadaOtros" title="Comisiones, FPC y escribano">Otros gastos ($)</label>
                    </div>
                    <input type="text" id="pllPagadaMontoOTROS" class="form-control form-control-sm" inputmode="decimal" placeholder="opcional">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="pllPagadaComp">Comprobante</label>
                    <input type="text" id="pllPagadaComp" class="form-control form-control-sm" maxlength="40" placeholder="opcional">
                </div>
                <div class="col-md-10">
                    <input type="text" id="pllPagadaObs" class="form-control form-control-sm" maxlength="300" placeholder="Observación (opcional)">
                </div>
                <div class="col-md-2">
                    <button id="pllBtnPagada" class="btn btn-sm btn-primary w-100"><i class="fas fa-check me-1"></i> Marcar</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllPagadasTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead>
                        <tr><th>Local</th><th>Renovación de</th><th>Concepto</th><th>Fecha</th><th class="text-end">Monto</th>
                            <th>Comprobante</th><th>Alta</th><th style="width: 50px;"></th></tr>
                    </thead>
                    <tbody id="pllPagadasBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         5. OBRAS DE IMAGEN
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Obras de renovación de imagen</h5>
            <small class="text-muted">
                Costo en U$S del segmento (o el propio) × el dólar futuro del mes. El segmento vacío es el que dan los
                m² del local. Un local puede tener más de una obra, en meses distintos.
            </small>
        </div>
        <?php if ($edita): ?>
        <div class="card-body border-bottom" id="pllObraForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label form-label-sm" for="pllObraLocal">Local</label>
                    <select id="pllObraLocal" class="form-select form-select-sm"></select></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllObraMes">Mes estimado</label>
                    <input type="month" id="pllObraMes" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllObraSegmento">Segmento</label>
                    <select id="pllObraSegmento" class="form-select form-select-sm"></select></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllObraUsd">Costo propio (U$S)</label>
                    <input type="text" id="pllObraUsd" class="form-control form-control-sm" inputmode="decimal" placeholder="el del segmento"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllObraObs">Observación</label>
                    <input type="text" id="pllObraObs" class="form-control form-control-sm" maxlength="300"></div>
                <div class="col-md-1"><button id="pllBtnObra" class="btn btn-sm btn-primary w-100"><i class="fas fa-plus"></i></button></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllObrasTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead><tr><th>Local</th><th>Mes</th><th>Segmento</th><th class="text-end">Costo U$S</th><th>Observación</th>
                        <th>Alta</th><th style="width: 50px;"></th></tr></thead>
                    <tbody id="pllObrasBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         6. APERTURAS
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Aperturas de locales nuevos</h5>
            <small class="text-muted">
                Alta manual: el local todavía no existe en el sistema. Se vincula a un número de sucursal cuando
                exista (dar de baja y volver a cargar). El segmento es obligatorio.
            </small>
        </div>
        <?php if ($edita): ?>
        <div class="card-body border-bottom" id="pllAperturaForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label form-label-sm" for="pllApNombre">Nombre</label>
                    <input type="text" id="pllApNombre" class="form-control form-control-sm" maxlength="100"></div>
                <div class="col-md-1"><label class="form-label form-label-sm" for="pllApNro">Nro.</label>
                    <input type="number" id="pllApNro" class="form-control form-control-sm" placeholder="opc."></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllApMes">Mes estimado</label>
                    <input type="month" id="pllApMes" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllApSegmento">Segmento</label>
                    <select id="pllApSegmento" class="form-select form-select-sm"></select></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllApUsd">Costo propio (U$S)</label>
                    <input type="text" id="pllApUsd" class="form-control form-control-sm" inputmode="decimal" placeholder="el del segmento"></div>
                <div class="col-md-1"><label class="form-label form-label-sm" for="pllApObs">Obs.</label>
                    <input type="text" id="pllApObs" class="form-control form-control-sm" maxlength="300"></div>
                <div class="col-md-1"><button id="pllBtnApertura" class="btn btn-sm btn-primary w-100"><i class="fas fa-plus"></i></button></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllAperturasTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead><tr><th>Nombre</th><th>Nro.</th><th>Mes</th><th>Segmento</th><th class="text-end">Costo U$S</th>
                        <th>Observación</th><th>Alta</th><th style="width: 50px;"></th></tr></thead>
                    <tbody id="pllAperturasBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================================================================
         7. COMPROMISOS A MANO
         ================================================================ -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Compromisos a mano</h5>
            <small class="text-muted">
                Lo que ya está pactado y no sale de la app: prendas, sellados, servicio comercial, cuotas
                acordadas. Se proyectan en su fecha y por su monto, tal cual. Con más de una cuota, se cargan N
                cuotas mensuales del mismo importe desde la fecha.
            </small>
        </div>
        <?php if ($edita): ?>
        <div class="card-body border-bottom" id="pllCompromisoForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label form-label-sm" for="pllCoOperador">Operador</label>
                    <input type="text" id="pllCoOperador" class="form-control form-control-sm" list="pllCoOperadores"
                           placeholder="Razón social o proveedor…" autocomplete="off">
                    <datalist id="pllCoOperadores"></datalist></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllCoLocal">Local</label>
                    <select id="pllCoLocal" class="form-select form-select-sm"></select></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllCoConcepto">Concepto</label>
                    <input type="text" id="pllCoConcepto" class="form-control form-control-sm" maxlength="100" placeholder="Prenda, sellado…"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllCoFecha">Fecha</label>
                    <input type="date" id="pllCoFecha" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllCoMonto">Monto ($)</label>
                    <input type="text" id="pllCoMonto" class="form-control form-control-sm" inputmode="decimal"></div>
                <div class="col-md-1"><label class="form-label form-label-sm" for="pllCoCuotas">Cuotas</label>
                    <input type="number" id="pllCoCuotas" class="form-control form-control-sm" min="1" max="60" value="1"></div>
                <div class="col-md-2"><label class="form-label form-label-sm" for="pllCoForma">Forma de pago</label>
                    <select id="pllCoForma" class="form-select form-select-sm"></select></div>
                <div class="col-md-8"><input type="text" id="pllCoObs" class="form-control form-control-sm" maxlength="300" placeholder="Observación (opcional)"></div>
                <div class="col-md-2"><button id="pllBtnCompromiso" class="btn btn-sm btn-primary w-100"><i class="fas fa-plus me-1"></i> Agregar</button></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="pllCompromisosTabla" class="table table-sm table-hover mb-0 align-middle" data-orden="no">
                    <thead><tr><th>Operador</th><th>Local</th><th>Concepto</th><th>Fecha</th><th class="text-end">Monto</th>
                        <th>Cuota</th><th>Forma</th><th>Alta</th><th style="width: 80px;"></th></tr></thead>
                    <tbody id="pllCompromisosBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="Js/Parametros-Llaves.js?v=<?php echo time(); ?>"></script>
