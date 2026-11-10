<?php
/**
 * La venta por local de Alquileres (Class/AlquileresVenta.php) y la venta
 * mensual de un canal (Ventas::ventaMensualDeCanal()).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA SUMA DE LOS LOCALES NO DA LA VENTA DEL CANAL. Por redondeo, o
 *      porque la venta manual de un local nuevo se suma encima del canal en vez
 *      de restarse: la pestaña mostraria mas venta que la que proyecta Ventas,
 *      y los variables de todos los locales saldrian inflados.
 *
 *   2. UNA ESTIMADA PISA A LA REAL. El mes con venta en la app se usa tal cual.
 *
 *   3. LA NETA SE CALCULA CON OTRA ALICUOTA. Neta = bruta / (1 + IVA general).
 *
 *   4. UN LOCAL CERRADO SE QUEDA CON SU PARTE. Si entrara al denominador, su
 *      parte del canal no la pagaria nadie.
 *
 *   5. UN LOCAL NUEVO SIN VENTA MANUAL SE ESTIMA IGUAL. No tiene participacion:
 *      no tiene venta, y lo dice.
 *
 * No toca la base.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresVenta.php';
require_once __DIR__ . '/../Class/Ventas.php';

/** 12 meses de venta neta constante para un local */
function doceMeses($ventana, $importe) {
    return array_fill_keys($ventana, (float) $importe);
}

$ventana = [];
for ($m = '2025-10'; $m <= '2026-09'; $m = Inflacion::mesMas($m, 1)) {
    $ventana[] = $m;
}

seccion('Ventas: la venta mensual de un canal');

$proy = [
    'base_mensual' => [['clave' => '2026-11', 'con_iva' => 1000.0], ['clave' => '2026-12', 'con_iva' => 2000.0]],
    'participacion' => ['mensual' => [
        '2026-11' => ['LOCALES' => ['efectivo' => 0.5]],
        '2026-12' => ['LOCALES' => ['efectivo' => 0.25]]
    ]]
];
chequear('mes completo = base mensual x participacion mensual', ['2026-11' => 500.0, '2026-12' => 500.0],
    Ventas::ventaMensualDeCanal($proy, 'LOCALES'));

seccion('AlquileresVenta: la ventana');

chequear('los ultimos 12 cerrados', $ventana,
    AlquileresVenta::ventana(array_merge(['2025-08', '2025-09'], array_reverse($ventana))));

seccion('AlquileresVenta: real, estimada y manual');

$base = [
    'meses' => ['2026-10', '2026-11', '2026-12'],
    'locales' => [
        1 => ['activo' => true, 'activo_hasta' => null, 'manual' => null],
        2 => ['activo' => true, 'activo_hasta' => null, 'manual' => null],
        3 => ['activo' => true, 'activo_hasta' => null, 'manual' => null]
    ],
    'netas' => [1 => doceMeses($ventana, 100), 2 => doceMeses($ventana, 200), 3 => doceMeses($ventana, 700)],
    'brutas' => [],
    'ventana' => $ventana,
    'canal' => ['2026-10' => 999.0, '2026-11' => 1000000.0, '2026-12' => 1234567.89],
    'iva' => 0.21
];
$base['netas'][1]['2026-10'] = 50000.0;
$base['brutas'][1]['2026-10'] = 60500.0;

$r = AlquileresVenta::calcular($base);
chequear('participaciones sobre la neta de la ventana', [1 => 0.1, 2 => 0.2, 3 => 0.7], $r['participacion']);
chequear('la real gana sobre la estimada', ['REAL', 50000.0, 60500.0],
    [$r['ventas'][1]['2026-10']['origen'], $r['ventas'][1]['2026-10']['neta'], $r['ventas'][1]['2026-10']['bruta']]);
chequear('nov: el local 3 estima 70 % del canal', 700000.0, $r['ventas'][3]['2026-11']['bruta']);
chequear('nov: su neta es la bruta / 1,21', 700000.0 / 1.21, $r['ventas'][3]['2026-11']['neta']);
chequear('nov: la celda dice su origen y su participacion', ['ESTIMADA', 0.7, 1000000.0],
    [$r['ventas'][3]['2026-11']['origen'], $r['ventas'][3]['2026-11']['participacion'], $r['ventas'][3]['2026-11']['canal']]);

$sumaDic = 0.0;
foreach ([1, 2, 3] as $n) {
    $sumaDic += $r['ventas'][$n]['2026-12']['bruta'];
}
chequear('dic: la suma de los locales es el canal al centavo', 1234567.89, round($sumaDic, 2));

seccion('AlquileresVenta: un local nuevo');

$conNuevo = $base;
$conNuevo['locales'][4] = ['activo' => true, 'activo_hasta' => null, 'manual' => 100000.0];
$conNuevo['locales'][5] = ['activo' => true, 'activo_hasta' => null, 'manual' => null];
$conNuevo['netas'][4] = array_slice(doceMeses($ventana, 50), -8, null, true);
$n = AlquileresVenta::calcular($conNuevo);

chequear('los de menos de 12 meses son nuevos', [4, 5], $n['nuevos']);
chequear('el nuevo no entra al reparto', false, isset($n['participacion'][4]));
chequear('con venta manual: Manual, neta y bruta x 1,21', ['MANUAL', 100000.0, 121000.0],
    [$n['ventas'][4]['2026-11']['origen'], $n['ventas'][4]['2026-11']['neta'], $n['ventas'][4]['2026-11']['bruta']]);
chequear('la manual se resta del canal antes de repartir', round((1000000.0 - 121000.0) * 0.7, 2),
    $n['ventas'][3]['2026-11']['bruta']);

$sumaNov = 0.0;
foreach ([1, 2, 3, 4] as $k) {
    $sumaNov += $n['ventas'][$k]['2026-11']['bruta'];
}
chequear('estimadas + manuales = el canal, con un nuevo', 1000000.0, round($sumaNov, 2));
chequear('y lo mismo dicen los totales del mes', 1000000.0, round($n['estimada']['2026-11'] + $n['manual']['2026-11'], 2));
chequear('un nuevo sin venta manual no tiene venta', null, $n['ventas'][5]['2026-11']);

seccion('AlquileresVenta: un local cerrado no entra al denominador');

$cerrado = $base;
$cerrado['locales'][3]['activo'] = false;   // cerrado en Lakers
$c = AlquileresVenta::calcular($cerrado);
chequear('sin el 3, el 1 y el 2 se reparten un tercio y dos tercios', [1 => 1 / 3, 2 => 2 / 3], $c['participacion']);
chequear('el cerrado no tiene venta estimada', null, $c['ventas'][3]['2026-11']);
chequear('el 2 se queda con dos tercios del canal', round(1000000.0 * 2 / 3, 2), $c['ventas'][2]['2026-11']['bruta']);

$fin = $base;
$fin['locales'][3]['activo_hasta'] = '2026-11';   // fecha de fin en noviembre
$f = AlquileresVenta::calcular($fin);
chequear('con fecha de fin en nov: en nov todavia reparte', 700000.0, $f['ventas'][3]['2026-11']['bruta']);
chequear('en dic ya no', null, $f['ventas'][3]['2026-12']);
$sumaDicFin = $f['ventas'][1]['2026-12']['bruta'] + $f['ventas'][2]['2026-12']['bruta'];
chequear('y dic sigue cerrando contra el canal entre los que quedan', 1234567.89, round($sumaDicFin, 2));

seccion('AlquileresVenta: una sola de las dos ventas reales');

$unaSola = $base;
$unaSola['netas'][2]['2026-10'] = 100000.0;
$u = AlquileresVenta::calcular($unaSola);
chequear('falta la bruta: se deriva x 1,21 y se marca', [121000.0, true],
    [$u['ventas'][2]['2026-10']['bruta'], $u['ventas'][2]['2026-10']['derivada']]);

seccion('AlquileresVenta: repartir');

$rep = AlquileresVenta::repartir(100.0, ['a' => 1, 'b' => 1, 'c' => 1]);
chequear('tres tercios de 100 suman 100', 100.0, round(array_sum($rep), 2));
chequear('el centavo que sobra va a uno solo', [33.34, 33.33, 33.33], array_values($rep));
chequear('sin nadie a quien repartir: vacio', [], AlquileresVenta::repartir(100.0, []));
