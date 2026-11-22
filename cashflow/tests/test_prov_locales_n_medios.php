<?php
/**
 * Proveedores Locales: N PAGOS POR COMPROBANTE.
 *
 * Un comprobante se paga en N filas de la planilla, con el mismo medio o con
 * medios distintos y en fechas distintas, tambien si esta en cuotas. Todo sin
 * base: la importacion, lo que se escribe, como entra al eje y en que serie
 * cae cada peso son estaticas y puras, y lo que se puede guardar con los
 * scripts corridos llega por parametro (capacidadImportacion()).
 *
 * Lo de dos medios sin el script -dos como maximo, cuotas con uno solo- lo
 * sigue fijando test_prov_locales_medios.php, que llama a la regla sin decirle
 * que el script esta: es el comportamiento de antes.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script de N medios');

$sqlN = file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_pago_n_medios.sql');

chequear('el CHECK de ORDEN pasa a ORDEN >= 1', true,
    strpos($sqlN, 'CHECK (ORDEN >= 1)') !== false);
chequear('y se reemplaza en una transaccion', true,
    strpos($sqlN, 'BEGIN TRANSACTION') !== false && strpos($sqlN, 'COMMIT TRANSACTION') !== false
    && strpos($sqlN, 'BEGIN TRANSACTION') < strpos($sqlN, 'DROP CONSTRAINT CK_RO_T_CF_PLMED_ORDEN'));
chequear('es reejecutable: pregunta si ya esta hecho', true,
    strpos($sqlN, "AND definition LIKE '%>=%')") !== false);
chequear('corta si falta el script del excedente', true,
    strpos($sqlN, "COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'CLASIFICACION') IS NULL") !== false
    && strpos($sqlN, 'SET NOEXEC ON') !== false);
chequear('el tope de verdad esta en el codigo, no en el CHECK', [50, false],
    [Proveedores::MAX_PARTES, strpos($sqlN, 'ORDEN <= ') !== false]);
chequear('la clase lo nombra', 'sql/cashflow_prov_locales_pago_n_medios.sql', Proveedores::SCRIPT_N_MEDIOS);

$cuerpoN = function ($metodo) {
    $src = file_get_contents(__DIR__ . '/../Class/Proveedores.php');
    $ini = strpos($src, 'function ' . $metodo . '(');

    return substr($src, $ini, strpos($src, "\n    }\n", $ini) - $ini);
};

chequear('la deteccion mira el CHECK, solo leyendo', true,
    strpos($cuerpoN('tablaNMediosCreada'), "definition LIKE '%>=%'") !== false
    && strpos($cuerpoN('tablaNMediosCreada'), 'ALTER') === false);
chequear('sin el script, el tope es el de antes', true,
    strpos($cuerpoN('capacidadImportacion'), 'self::PARTES_SIN_SCRIPT') !== false);
chequear('y la pestaña avisa nombrando el script', true,
    strpos($cuerpoN('getAvisosConNivel'), 'self::SCRIPT_N_MEDIOS') !== false);
