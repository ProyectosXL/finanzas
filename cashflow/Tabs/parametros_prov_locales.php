<!--
    Parámetros → Prov. Locales.

    Va en su propio archivo y con su propio JS, igual que Saldos,
    Cob. Electrónicos, Pre-chequeado y Cobranzas: no comparte nada con los
    bloques de Ventas, así que un problema acá no puede llevarse puesta la
    pestaña que ya funciona. Sus clases llevan el prefijo pplo- porque
    Parametros.js busca .param-input, .mix-* y .respaldo-* en TODO el documento.

    QUÉ SON ESTAS CINCO LISTAS
    Los valores con los que se clasifica a cada proveedor local. Antes eran
    texto libre con un datalist de sugerencias: se podía escribir cualquier
    cosa. Y una de las cinco no es cosmética — CADA RUBRO ECONÓMICO DISTINTO
    CREA UNA FILA PROPIA EN EL TABLERO —, así que tipear "Alquileres " con un
    espacio al final no es un typo: es una fila del cuadro que nadie pidió.

    TRES COSAS QUE ESTA PANTALLA TIENE QUE HACER VISIBLES:

      1. CUÁNTOS PROVEEDORES usan cada valor. Sin ese número, dar de baja es a
         ciegas: no se sabe si se saca una opción que no usa nadie o una que
         tienen doscientos, que van a quedar todos fuera de lista.
      2. Que RENOMBRAR NO CAMBIA EL MAESTRO. El maestro guarda el texto, no un
         id: los proveedores cargados conservan el valor viejo.
      3. Que el PLAZO es la única lista que el sistema usa para calcular, y que
         sus días vacíos NO son cero.

    Nunca hay baja física: se da de baja y se puede reactivar.
-->
<div class="pplo-opciones">

    <div class="modulo-descripcion mb-3" id="descripcionPplo"></div>

    <div id="avisosParamPplo"></div>

    <div class="cargando-slot" id="loadingParamPplo" data-cargando="Cargando listas de opciones…"></div>

    <div id="wrapperParamPplo" style="display: none;">

        <div class="alert alert-secondary d-flex align-items-start gap-2 py-2 px-3 mb-3">
            <i class="fas fa-circle-info mt-1"></i>
            <div>
                <small>
                    Estas listas pueblan los desplegables del <strong>alta manual</strong> del
                    maestro de Proveedores Locales y validan su <strong>importación</strong>.
                    En la importación, un valor que no esté acá es una
                    <strong>advertencia</strong>: la fila se importa igual y se guarda tal como
                    vino, marcada — pero <strong>nunca se agrega solo a la lista</strong>.
                    Las cinco listas son <strong>independientes entre sí</strong>: elegir un
                    rubro económico no acota los rubros disponibles.
                    <br>
                    Sólo aplican a <strong>Proveedores Locales</strong>, no a Proveedores
                    Exterior.
                </small>
            </div>
        </div>

        <!-- Una tarjeta por lista. Las dibuja el JS a partir de
             ProveedoresOpciones::TIPOS, que es la única definición: agregar una
             lista sexta es agregarla ahí y acá no se toca nada. -->
        <div id="listasPplo"></div>

    </div><!-- /wrapperParamPplo -->
</div><!-- /pplo-opciones -->
