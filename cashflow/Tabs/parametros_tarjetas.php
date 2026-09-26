<?php
/**
 * Parametros -> Tarjetas
 *
 * El maestro de tarjetas: de que tipo es cada una -y con eso, en que sub-pestana
 * de Financiero -> Pagos con Tarjetas y Otros aparece-, de que banco, de quien,
 * su % de cobertura y que dia del mes vence su resumen.
 *
 * Archivo y JS propios, como los demas modulos de esta pestana, y clases con
 * prefijo ptar-. Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO
 * el documento, asi que dos modulos compartiendo esas clases se pisarian el
 * guardado.
 *
 * EL BANCO Y EL USUARIO SE ELIGEN, NO SE TIPEAN. El banco sale de BANCO (Tango) y
 * el usuario de RO_V_CASHFLOW_USUARIOS_TARJETAS, que une directores y
 * supervisoras activos. El NOMBRE del usuario sale de ahi y no se edita: si se
 * pudiera, dos pantallas mostrarian dos nombres para el mismo ID y ninguno seria
 * "el nombre del usuario". Mismo criterio que NOM_PROVEE en el maestro de
 * fleteros.
 *
 * EL TIPO NO ES QUIEN ES EL USUARIO, y la pantalla lo dice: la gerenta de
 * administracion y finanzas figura en la vista al lado de las supervisoras y su
 * tarjeta es CORPORATIVA. Solo las de tipo SUPERVISORA se cruzan por nombre
 * contra el maestro de supervisoras.
 */
?>

<div class="ptar-modulo">

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div class="modulo-descripcion flex-grow-1" id="ptarDescripcion"></div>
        <button id="ptarBtnRefresh" class="btn btn-sm btn-outline-primary flex-shrink-0">
            <i class="fas fa-sync-alt me-1"></i> Actualizar
        </button>
    </div>

    <!-- Avisos: el script sin correr, la vista de usuarios que falta, BANCO que
         no responde, tarjetas con un usuario que ya no esta activo. La pantalla
         no rompe, avisa. -->
    <div id="ptarAvisos"></div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0">Tarjetas</h5>
                <small class="text-muted">
                    El <strong>tipo</strong> decide en qué sub-pestaña aparece la tarjeta y con
                    qué regla se estima. Es independiente de quién sea el usuario: sólo las de
                    tipo <em>Supervisora</em> se asocian a una supervisora por su nombre.
                </small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-success" data-exportar="ptarTabla"
                        data-exportar-nombre="Parametros_Tarjetas"
                        title="Exportar a Excel lo que se está viendo">
                    <i class="fas fa-file-excel me-1"></i> Exportar
                </button>
                <button id="ptarBtnNuevo" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Agregar tarjeta
                </button>
            </div>
        </div>

        <!-- ========================================================
             EL ALTA
             El banco y el usuario se eligen de su fuente; los últimos
             4 dígitos, el % y el día se tipean.
             ======================================================== -->
        <div class="card-body border-bottom" id="ptarFormNuevo" style="display: none;">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label form-label-sm" for="ptarTipo">Tipo</label>
                    <select id="ptarTipo" class="form-select form-select-sm"></select>
                </div>
                <!-- ========================================================
                     EL BANCO SE BUSCA, NO SE RECORRE
                     BANCO tiene 198 filas, así que un <select> obliga a
                     scrollear una lista de doscientos nombres para encontrar
                     uno. Mismo patrón que el alta del maestro de fleteros: se
                     escribe y se elige de las coincidencias.

                     SE BUSCA POR NOMBRE Y POR CÓDIGO, porque quien carga una
                     tarjeta puede acordarse de cualquiera de los dos.

                     FILTRA LO QUE YA ESTÁ CARGADO y no vuelve al servidor: los
                     198 bancos vienen en el payload, y una consulta por cada
                     letra tipeada sería ir a buscar algo que ya está acá.
                     ======================================================== -->
                <div class="col-md-3 position-relative">
                    <label class="form-label form-label-sm" for="ptarBanco">Banco</label>
                    <input type="text" id="ptarBanco" class="form-control form-control-sm"
                           placeholder="Nombre o código…" autocomplete="off">
                    <!-- El código elegido. Es lo que viaja al servidor: el texto
                         del input es para buscar, no el dato. -->
                    <input type="hidden" id="ptarBancoCod" value="">
                    <div id="ptarBancoSugerencias" class="list-group position-absolute w-100 shadow"
                         style="z-index: 1050; max-height: 260px; overflow-y: auto; display: none;">
                    </div>
                    <div class="form-text" id="ptarBancoElegido">
                        Se elige de la lista: el código se valida contra Tango.
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm" for="ptarUsuario">Usuario</label>
                    <select id="ptarUsuario" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="ptarUltimos4"
                           title="Los últimos 4 dígitos impresos en la tarjeta">
                        Últimos 4
                    </label>
                    <input type="text" id="ptarUltimos4" class="form-control form-control-sm"
                           maxlength="4" inputmode="numeric" placeholder="1234">
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="ptarPct"
                           title="En puntos: 5 es 5 %">
                        % cobert.
                    </label>
                    <input type="number" step="0.01" min="0" max="100" id="ptarPct"
                           class="form-control form-control-sm" value="0">
                </div>
                <div class="col-md-1">
                    <label class="form-label form-label-sm" for="ptarDia"
                           title="Día del mes en que vence el resumen">
                        Día vto.
                    </label>
                    <input type="number" step="1" min="1" max="31" id="ptarDia"
                           class="form-control form-control-sm">
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button id="ptarBtnAgregar" class="btn btn-sm btn-primary flex-fill">
                        <i class="fas fa-check"></i>
                    </button>
                    <button id="ptarBtnCancelar" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="param-hint mt-2">
                <strong>El día de vencimiento es obligatorio</strong>, y no tiene valor por
                defecto: es la fecha en la que se estima el pago de los meses sin resumen
                cargado, así que sin él la tarjeta no proyecta nada. Si el mes no tiene ese día
                se usa el último, y si cae en un día no hábil el débito pasa al
                <strong>primer hábil siguiente</strong>.
                <br>
                Los <strong>últimos 4 dígitos</strong> son optativos, salvo cuando el mismo
                usuario ya tiene otra tarjeta en el mismo banco: ahí pasan a ser obligatorios,
                porque son lo único que distingue a cuál de las dos corresponde cada resumen.
                <br>
                El <strong>% de cobertura</strong> va en puntos —5 es 5 %— y se aplica sobre la
                base estimada: la parte tarjeta en Gastos Supervisoras y los dos componentes en
                Tarjetas Socios. En <strong>Pagos Corporativos no multiplica las facturas</strong>
                —son deuda real de Tango— sino que entra como un renglón de cobertura aparte.
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <!-- data-orden="no": es un formulario, no un listado. -->
                <table id="ptarTabla" class="table table-hover mb-0" data-orden="no">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Tipo</th>
                            <th>Banco</th>
                            <th>Usuario</th>
                            <th class="text-center" style="width: 110px;">Últimos 4</th>
                            <th class="text-end" style="width: 110px;">% cobertura</th>
                            <th class="text-center" style="width: 110px;">Día vto.</th>
                            <th class="text-center" style="width: 100px;">Activa</th>
                            <th>Última edición</th>
                        </tr>
                    </thead>
                    <tbody id="ptarBody"></tbody>
                </table>
            </div>
        </div>

        <div class="card-body py-2 border-top">
            <small class="text-muted">
                <strong>Dar de baja no borra.</strong> Una tarjeta inactiva deja de proyectar y
                <strong>conserva sus resúmenes</strong>, que son el histórico con el que se
                explica con qué número se proyectó en su momento. Se puede reactivar.
                <br>
                <strong>Una tarjeta no tiene moneda.</strong> La misma tarjeta puede tener
                consumos en pesos y en dólares, así que la moneda vive en cada resumen. Los
                resúmenes se cargan desde las sub-pestañas de
                <em>Financiero › Pagos con Tarjetas y Otros</em>, sobre la fila de su tarjeta.
            </small>
        </div>
    </div>
</div>

<script src="Js/Parametros-Tarjetas.js?v=<?php echo time(); ?>"></script>
