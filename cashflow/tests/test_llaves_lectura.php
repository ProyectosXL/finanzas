<?php
/**
 * La lectura de los m2 de los locales (Class/LocalesMedidas.php), que usa
 * Llaves y Renovaciones para el segmento de cada local.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL CASHFLOW ESCRIBE EN EL GESTOR DE LOCALES. La tabla es de
 *      abastecimiento: la clase no tiene que tener ni una sentencia de
 *      escritura.
 *
 *   2. UN LOCAL SIN MEDIDAS SE LEE COMO UN LOCAL CHICO. Costo de Ocupacion
 *      suma con ISNULL(..., 0); aca un local sin ninguna de las tres medidas es
 *      null, y Llaves avisa en vez de proyectarle la obra de un segmento.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/LocalesMedidas.php';

seccion('LocalesMedidas: los m2 totales');

chequear('venta + deposito + baulera', ['venta' => 188.25, 'deposito' => 56.0, 'baulera' => 0.0, 'total' => 244.25],
    LocalesMedidas::medidas('188.25', '56.00', '.00'));
chequear('una parte vacia no impide el total', 132.6, LocalesMedidas::medidas('132.60', null, null)['total']);
chequear('las tres vacias: null, no 0', null, LocalesMedidas::medidas(null, '', null)['total']);
chequear('las tres en 0 SI son 0: es un dato cargado', 0.0, LocalesMedidas::medidas(0, 0, 0)['total']);

seccion('LocalesMedidas: solo lee');

$codigo = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', file_get_contents(__DIR__ . '/../Class/LocalesMedidas.php'));
chequear('ninguna sentencia de escritura en el codigo', 0,
    preg_match_all('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC|DROP|ALTER|CREATE)\b/i', $codigo));

seccion('LocalesMedidas: contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $m = (new LocalesMedidas())->todas();
    chequear('hay locales con medidas', true, count($m) > 0);
    /* Gurruchaga (72): 188,25 de venta y 56 de deposito. Mientras no se recarguen. */
    chequear('Gurruchaga: 244,25 m2 totales', 244.25, $m[72]['total'] ?? null);
}
