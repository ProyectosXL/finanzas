<!--
    Estructura del tablero de Cashflow.

    Panel de la sub-pestaña Cashflow de Parámetros. Se incluye desde
    Tabs/parametros.php y lo maneja Js/Parametros-Estructura.js, que es un
    archivo aparte con su propio estado.

    AISLAMIENTO: todas las clases de acá llevan el prefijo cfe-. Parametros.js
    busca .param-input, .mix-* y .respaldo-* en TODO el documento (no dentro de
    su panel), así que reusar esos nombres haría que los dos paneles se pisen
    en silencio.
-->

<div class="cfe">

    <div class="modulo-descripcion mb-3" id="cfeDescripcion"></div>

    <div id="cfeAvisos"></div>

    <div class="loading-spinner" id="cfeLoading">
        <div class="spinner"></div>
        <p>Cargando la estructura...</p>
    </div>

    <div id="cfeWrapper" style="display: none;">

        <!-- ========================================================
             FILAS DEL TABLERO
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="mb-0">Filas del tablero</h6>
                    <small class="text-muted">
                        El orden de la lista es el orden en que se dibujan.
                        Nada de esto está escrito en el código.
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="form-check form-switch mb-0 me-2">
                        <input class="form-check-input" type="checkbox" id="cfeVerInactivas">
                        <label class="form-check-label small text-muted" for="cfeVerInactivas">
                            Mostrar inhabilitadas
                        </label>
                    </div>
                    <button id="cfeBtnGuardar" class="btn btn-sm btn-primary" disabled>
                        <i class="fas fa-floppy-disk me-1"></i> Guardar
                    </button>
                </div>
            </div>

            <!-- Resumen de la validación: qué impide guardar y por qué -->
            <div id="cfeValidacion"></div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="cfeTabla">
                        <thead>
                            <tr>
                                <th style="width: 70px;">Orden</th>
                                <th>Nombre</th>
                                <th style="width: 150px;">Código</th>
                                <th style="width: 170px;">Sección</th>
                                <th style="width: 150px;">Tipo</th>
                                <th style="width: 320px;">Origen de datos</th>
                                <th class="text-center" style="width: 90px;">Computa</th>
                                <th class="text-center" style="width: 80px;">Activa</th>
                            </tr>
                        </thead>
                        <tbody id="cfeFilas"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================
             ALTA DE FILA
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Agregar fila</h6>
                <small class="text-muted">
                    Entra inhabilitada, para que no pueda romper un tablero que estaba bien.
                    Después le elegís el origen y la activás.
                </small>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small">Nombre</label>
                        <input type="text" class="form-control form-control-sm" id="cfeNuevoNombre"
                               maxlength="80" placeholder="Ej: Servicios de logística">
                        <small class="text-muted">Código interno: <code id="cfeNuevoCodigo">—</code></small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Sección</label>
                        <select class="form-select form-select-sm" id="cfeNuevaSeccion"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Tipo</label>
                        <select class="form-select form-select-sm" id="cfeNuevoTipo"></select>
                    </div>
                    <div class="col-md-2">
                        <button id="cfeBtnAgregarFila" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fas fa-plus me-1"></i> Agregar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================
             SECCIONES
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Secciones</h6>
                <small class="text-muted">
                    El rol define cómo participa la sección en el cálculo.
                    Inhabilitar una sección saca del tablero todas sus filas.
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="cfeTablaSecciones">
                        <thead>
                            <tr>
                                <th style="width: 70px;">Orden</th>
                                <th>Nombre</th>
                                <th style="width: 190px;">Código</th>
                                <th style="width: 180px;">Rol</th>
                                <th style="width: 190px;">Depende de</th>
                                <th class="text-center" style="width: 80px;">Activa</th>
                            </tr>
                        </thead>
                        <tbody id="cfeSecciones"></tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">Nueva sección</label>
                        <input type="text" class="form-control form-control-sm" id="cfeNuevaSeccionNombre"
                               maxlength="80" placeholder="Ej: Costos Financieros">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Rol</label>
                        <select class="form-select form-select-sm" id="cfeNuevaSeccionRol"></select>
                    </div>
                    <div class="col-md-2">
                        <button id="cfeBtnAgregarSeccion" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fas fa-plus me-1"></i> Agregar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================
             ORÍGENES DE DATOS DISPONIBLES
             ======================================================== -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Orígenes de datos</h6>
                <small class="text-muted">
                    Los módulos registrados. Los que todavía no están construidos
                    rinden cero y el tablero lo avisa.
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="cfeTablaProviders">
                        <thead>
                            <tr>
                                <th>Módulo</th>
                                <th>Series que expone</th>
                                <th style="width: 90px;">Moneda</th>
                                <th style="width: 130px;">Estado</th>
                            </tr>
                        </thead>
                        <tbody id="cfeProviders"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
