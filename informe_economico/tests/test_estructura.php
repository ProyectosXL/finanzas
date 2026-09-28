<?php
require_once __DIR__ . '/../Class/EstructuraFilas.php';
require_once __DIR__ . '/../Class/Rubros.php';
require_once __DIR__ . '/../Class/Cotizacion.php';

function cfgIE($clave, $tipo, $seccion = null, $etiqueta = '', $visible = 1, $activo = 1) {
    return ['CLAVE' => $clave, 'TIPO' => $tipo, 'SECCION' => $seccion, 'ETIQUETA' => $etiqueta, 'VISIBLE' => $visible, 'ACTIVO' => $activo];
}

$maestro = [
    '1.5.' => ['nombre' => 'Ventas Minoristas sin IVA', 'cat' => null, 'activo' => 1],
    '7.10.' => ['nombre' => 'Telefonia', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '7.9.' => ['nombre' => 'Movilidad', 'cat' => 'Gastos de Estructura', 'activo' => 1]
];
$clasif = Rubros::clasificar(['1.5.', '7.10.', '9.1.'], $maestro);
$config = [
    cfgIE('TIT_VENTAS', 'TITULO', null, '1. VENTAS'),
    cfgIE('1.5.', 'RUBRO'),
    cfgIE('V_1_9', 'CALCULO', null, 'Mi etiqueta'),
    cfgIE('SEC_ESTRUCTURA', 'RUBROS_DE_SECCION', 'Gastos de Estructura'),
    cfgIE('INVENTADA', 'CALCULO'),
    cfgIE('MARGEN_BRUTO', 'RATIO', null, '', 0),
    cfgIE('RENTABILIDAD_TOTAL', 'RATIO', null, '', 1, 0)
];
$x = EstructuraFilas::expandir($config, $clasif, $maestro);
$ids = array_column($x['filas'], 'id');

seccion('Expansion de la estructura');
chequear('Orden y expansion', ['TIT_VENTAS', 'R_1.5.', 'V_1_9', 'R_7.9.', 'R_7.10.', 'TIT_SIN_SECCION', 'R_9.1.'], $ids);
chequear('La etiqueta de la configuracion manda', 'Mi etiqueta', $x['filas'][2]['etiqueta']);
chequear('Rubro con nombre del maestro', '1.5. Ventas Minoristas sin IVA', $x['filas'][1]['etiqueta']);
chequear('Una clave desconocida se avisa, no se inventa', 1, count($x['avisos']));

seccion('Rubro sin seccion');
$ult = $x['filas'][count($x['filas']) - 1];
chequear('Va al bloque Sin seccion, al final', true, $ult['sinSeccion']);
chequear('Sin nombre en el maestro lo dice', '9.1. Rubro sin nombre en el maestro', $ult['etiqueta']);
$sinNada = EstructuraFilas::expandir($config, Rubros::clasificar(['1.5.'], $maestro), $maestro);
chequear('Sin rubros huerfanos no hay bloque', false, in_array('TIT_SIN_SECCION', array_column($sinNada['filas'], 'id'), true));

seccion('Cotizacion de cierre de cada mes');
chequear('El cierre de cada mes, sin promediar', ['tcc' => ['1-2026' => 1400.0, '2-2026' => 1500.0], 'faltantes' => []], Cotizacion::completar(['1-2026', '2-2026'], ['1-2026' => 1400.0, '2-2026' => 1500.0]));
chequear('Si falta un mes no hay USD', ['tcc' => null, 'faltantes' => ['2-2026']], Cotizacion::completar(['1-2026', '2-2026'], ['1-2026' => 1400.0]));
