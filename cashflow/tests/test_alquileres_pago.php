<?php
/**
 * Cuanto se paga de alquiler y cuando (Class/AlquileresPago.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA RETENCION SE CALCULA SOBRE EL TOTAL. Es sobre el neto; sobre el total
 *      con IVA retiene de mas y el a pagar sale corto.
 *
 *   2. UN VARIABLE DIFERIDO CAE EN EL MES DE LA VENTA. Con IRSA (2 meses), la
 *      venta de septiembre se paga en noviembre; y para eso la venta de un mes
 *      ANTERIOR al horizonte tiene que entrar.
 *
 *   3. UN VENCIMIENTO EN SABADO SE PAGA EL SABADO. O un vencimiento que ya paso
 *      se apila en el primer dia del eje.
 *
 *   4. EL EXTRAORDINARIO DE CENCOSUD NO SE REPITE, O TOMA EL VMA EQUIVOCADO.
 *      Con diciembre cerrado, es el VMA cerrado.
 *
 *   5. UN LOCAL CON UN DATO FALTANTE PROYECTA IGUAL, o un local cerrado en
 *      Lakers figura como incompleto.
 *
 * No toca la base.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresPago.php';

/** Calendario de lunes a viernes, con los feriados que se pasen */
function calendario($desde, $hasta, $feriados = []) {
    $mapa = [];
    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $mapa[$d] = intval(date('N', strtotime($d))) <= 5 && !in_array($d, $feriados, true);
    }
    return $mapa;
}

$habiles = calendario('2026-09-01', '2028-02-29', ['2026-12-08', '2027-01-01']);
$hoy = '2026-11-10';   // martes
$local = ['pct_iva' => 21.0, 'pct_percepcion' => 3.0, 'pct_retencion' => 2.0, 'fecha_fin' => null, 'cod_provee' => 'SAUNIC'];

seccion('Cuanto: neto, IVA, percepcion y retencion');

chequear('a pagar = neto + IVA + percepcion - retencion, retencion sobre el neto',
    ['neto' => 1000.0, 'iva' => 210.0, 'percepcion' => 30.0, 'retencion' => 20.0, 'a_pagar' => 1220.0],
    AlquileresPago::importes(1000.0, $local));
chequear('con un IVA de 23 % (otros cargos)', 1250.0,
    AlquileresPago::importes(1000.0, ['pct_iva' => 23.0, 'pct_percepcion' => 3.0, 'pct_retencion' => 1.0])['a_pagar']);
chequear('con un IVA de 19 %', 1190.0,
    AlquileresPago::importes(1000.0, ['pct_iva' => 19.0, 'pct_percepcion' => 0.0, 'pct_retencion' => 0.0])['a_pagar']);
chequear('a centavos', ['neto' => 333.33, 'iva' => 70.0, 'a_pagar' => 403.33],
    array_intersect_key(AlquileresPago::importes(333.333, ['pct_iva' => 21.0, 'pct_percepcion' => 0.0, 'pct_retencion' => 0.0]),
        array_flip(['neto', 'iva', 'a_pagar'])));

seccion('Cuando: el dia');

$d = AlquileresPago::diaPago('2026-12', 12, $habiles, $hoy);
chequear('vence un sabado: pasa al lunes', ['2026-12-14', true, false], [$d['fecha'], $d['corrida'], $d['vencido']]);
chequear('y la nota lo dice', true, strpos(AlquileresPago::notaDia($d), 'no es hábil') !== false);
$f = AlquileresPago::diaPago('2026-12', 8, $habiles, $hoy);
chequear('vence un feriado (8/12): pasa al 9', '2026-12-09', $f['fecha']);
$v = AlquileresPago::diaPago('2026-11', 5, $habiles, $hoy);
chequear('ya vencio en el mes en curso: siguiente habil desde hoy', ['2026-11-10', true], [$v['fecha'], $v['vencido']]);
chequear('y la nota lo dice', true, strpos(AlquileresPago::notaDia($v), 'todavía no hay factura') !== false);
$sab = AlquileresPago::diaPago('2026-11', 5, $habiles, '2026-11-14');
chequear('si hoy es sabado, desde hoy es el lunes', '2026-11-16', $sab['fecha']);
chequear('un habil que no se movio no tiene nota', '', AlquileresPago::notaDia(AlquileresPago::diaPago('2026-12', 10, $habiles, $hoy)));

seccion('Cuando: los meses de concepto');

chequear('con diferimiento 2 entran los dos meses anteriores al horizonte',
    ['2026-09', '2026-10', '2026-11', '2026-12'], AlquileresPago::mesesDeConcepto(['2026-11', '2026-12'], 2));
chequear('sin diferimiento, solo el horizonte', ['2026-11'], AlquileresPago::mesesDeConcepto(['2026-11'], 0));

/* Una proyeccion armada a mano: los meses de concepto con sus conceptos. */
function proyeccion() {
    return ['meses' => [
        '2026-09' => ['origen' => AlquileresCalculo::APP_CERRADA, 'conceptos' => [8 => ['importe' => 1000.0], 7 => ['importe' => 50.0]]],
        '2026-10' => ['origen' => AlquileresCalculo::VENTA_REAL, 'conceptos' => [8 => ['importe' => 1010.0], 7 => ['importe' => 60.0, 'venta' => 9e6, 'pct' => 8.0]]],
        '2026-11' => ['origen' => AlquileresCalculo::ESTIMADO, 'conceptos' => [8 => ['importe' => 1020.0], 11 => ['importe' => 300.0], 7 => ['importe' => 70.0]]],
        '2026-12' => ['origen' => AlquileresCalculo::APP_CERRADA, 'conceptos' => [8 => ['importe' => 2000.0], 1 => ['importe' => 400.0], 7 => ['importe' => 80.0]]],
        '2027-01' => ['origen' => AlquileresCalculo::ESTIMADO, 'conceptos' => [8 => ['importe' => 2020.0], 7 => ['importe' => 90.0]]],
        '2027-11' => ['origen' => AlquileresCalculo::ESTIMADO, 'conceptos' => [8 => ['importe' => 3000.0]]],
        '2027-12' => ['origen' => AlquileresCalculo::ESTIMADO, 'conceptos' => [8 => ['importe' => 3100.0], 1 => ['importe' => 100.0]]],
        '2028-01' => ['origen' => AlquileresCalculo::ESTIMADO, 'conceptos' => []]
    ]];
}

/** Los pagos indexados por concepto y mes de pago */
function porConcepto($pagos) {
    $out = [];
    foreach ($pagos as $p) {
        $out[$p['concepto']][$p['mes_pago']] = $p;
    }
    return $out;
}

$horizonte = ['2026-11', '2026-12', '2027-01'];
$base = [
    'local' => $local,
    'razon' => ['dia' => null, 'diferimiento' => 0],
    'dia_general' => 10,
    'proyeccion' => proyeccion(),
    'extras' => [],
    'meses_pago' => $horizonte,
    'habiles' => $habiles,
    'hoy' => $hoy
];

seccion('Cuando: diferimiento 0 y 2');

$sin = porConcepto(AlquileresPago::pagosLocal($base));
chequear('sin diferir: el 7 de noviembre se paga en noviembre', 70.0, $sin[7]['2026-11']['neto']);
chequear('sin diferir: la venta de septiembre no entra', false, isset($sin[7]['2026-09']));
chequear('el dia general (10) si la razon no tiene', '2026-11-10', $sin[8]['2026-11']['fecha']);

$irsa = $base;
$irsa['razon'] = ['dia' => 15, 'diferimiento' => 2];
$con = porConcepto(AlquileresPago::pagosLocal($irsa));
chequear('con 2 meses: en noviembre se paga el 7 de septiembre (antes del horizonte)',
    [50.0, '2026-09', AlquileresCalculo::APP_CERRADA],
    [$con[7]['2026-11']['neto'], $con[7]['2026-11']['mes_concepto'], $con[7]['2026-11']['origen']]);
chequear('en enero, el de noviembre', '2026-11', $con[7]['2027-01']['mes_concepto']);
chequear('el VMA no se difiere', '2026-11', $con[8]['2026-11']['mes_concepto']);
chequear('el dia de la razon social gana sobre el general (15/11 es domingo: 16)', '2026-11-16', $con[8]['2026-11']['fecha']);
chequear('las expensas se pagan en su mes', 300.0, $con[11]['2026-11']['neto']);
chequear('el a pagar lleva sus impuestos', 366.0, $con[11]['2026-11']['a_pagar']);

seccion('Extraordinarios: el ejemplo de Cencosud');

$cencosud = ['id' => 7, 'cod_provee' => 'SAUNIC', 'todos' => true, 'locales' => [],
             'pct' => 75.0, 'conceptos' => [8], 'mes_base' => 12, 'diferimiento' => 1];
$ex = $base;
$ex['extras'] = [$cencosud];
$ex['meses_pago'] = ['2026-11', '2026-12', '2027-01', '2027-12', '2028-01'];
$pe = porConcepto(AlquileresPago::pagosLocal($ex));

chequear('en enero: 75 % del VMA de diciembre', 1500.0, $pe['EXTRA:7']['2027-01']['neto']);
chequear('diciembre esta cerrado: toma el VMA cerrado', [2000.0, AlquileresCalculo::APP_CERRADA],
    [$pe['EXTRA:7']['2027-01']['detalle']['base_total'], $pe['EXTRA:7']['2027-01']['origen']]);
chequear('con IVA, percepcion y retencion', 1500 * 1.22, $pe['EXTRA:7']['2027-01']['a_pagar']);
chequear('el 1/1 es feriado: el pago va al 11/01 (vence el 10, domingo)', '2027-01-11', $pe['EXTRA:7']['2027-01']['fecha']);
chequear('se repite al año siguiente, con el VMA proyectado', 3100 * 0.75, $pe['EXTRA:7']['2028-01']['neto']);
chequear('solo en enero', ['2027-01', '2028-01'], array_keys($pe['EXTRA:7']));

$dos = $ex;
$dos['extras'] = [['conceptos' => [1, 8]] + $cencosud];
chequear('sobre 1 + 8: 75 % de (2000 + 400)', 1800.0, porConcepto(AlquileresPago::pagosLocal($dos))['EXTRA:7']['2027-01']['neto']);

chequear('todos los locales: alcanza a cualquier local de la razon', true, AlquileresPago::alcanza($cencosud, 99, 'SAUNIC'));
chequear('no alcanza a otra razon social', false, AlquileresPago::alcanza($cencosud, 99, 'SAPALA'));
$algunos = ['todos' => false, 'locales' => [2, 78]] + $cencosud;
chequear('un subconjunto: alcanza a los elegidos', true, AlquileresPago::alcanza($algunos, 78, 'SAUNIC'));
chequear('y no a los demas', false, AlquileresPago::alcanza($algunos, 3, 'SAUNIC'));

seccion('Fecha de fin');

$fin = $base;
$fin['local']['fecha_fin'] = '2026-11-30';
$pf = porConcepto(AlquileresPago::pagosLocal($fin));
chequear('noviembre todavia se paga', true, isset($pf[8]['2026-11']));
chequear('desde diciembre no', false, isset($pf[8]['2026-12']));

seccion('El estado de un local');

$razones = ['SAUNIC' => ['dia' => null, 'diferimiento' => 2]];
$completo = ['cod_provee' => 'SAUNIC', 'frecuencia' => 3, 'mes_ajuste' => 9, 'pct_iva' => 21.0,
             'pct_percepcion' => 0.0, 'pct_retencion' => 0.0, 'fecha_fin' => null];

chequear('completo: proyecta', ['estado' => 'PROYECTA', 'faltan' => []],
    AlquileresPago::estadoLocal($completo, true, $razones, 10, '2026-11'));
chequear('percepcion y retencion en 0 son validas', 'PROYECTA',
    AlquileresPago::estadoLocal($completo, true, $razones, 10, '2026-11')['estado']);
chequear('cerrado en Lakers: no proyecta y no es incompleto', ['estado' => 'CERRADO_LAKERS', 'faltan' => []],
    AlquileresPago::estadoLocal($completo, false, $razones, 10, '2026-11'));
chequear('fecha de fin pasada: finalizado, no incompleto', 'FINALIZADO',
    AlquileresPago::estadoLocal(['fecha_fin' => '2026-10-31'] + $completo, true, $razones, 10, '2026-11')['estado']);
chequear('sin fila en Parametros: falta todo', ['razón social', 'frecuencia de actualización', '% IVA', '% percepción', '% retención'],
    AlquileresPago::estadoLocal(null, true, $razones, 10, '2026-11')['faltan']);
chequear('sin razon social', ['razón social'],
    AlquileresPago::estadoLocal(['cod_provee' => null] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('razon social dada de baja', ['razón social dada de baja'],
    AlquileresPago::estadoLocal(['cod_provee' => 'SAPALA'] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('sin frecuencia', ['frecuencia de actualización'],
    AlquileresPago::estadoLocal(['frecuencia' => null] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('trimestral sin mes del ultimo ajuste', ['mes del último ajuste'],
    AlquileresPago::estadoLocal(['mes_ajuste' => null] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('mensual sin mes del ultimo ajuste: proyecta', 'PROYECTA',
    AlquileresPago::estadoLocal(['frecuencia' => 1, 'mes_ajuste' => null] + $completo, true, $razones, 10, '2026-11')['estado']);
chequear('sin % IVA', ['% IVA'],
    AlquileresPago::estadoLocal(['pct_iva' => null] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('percepcion vacia es incompleto, no 0', ['% percepción'],
    AlquileresPago::estadoLocal(['pct_percepcion' => null] + $completo, true, $razones, 10, '2026-11')['faltan']);
chequear('sin dia de vencimiento en ningun lado', ['día de vencimiento (ni la razón social ni el general)'],
    AlquileresPago::estadoLocal($completo, true, $razones, null, '2026-11')['faltan']);
chequear('con dia propio de la razon no hace falta el general', 'PROYECTA',
    AlquileresPago::estadoLocal($completo, true, ['SAUNIC' => ['dia' => 5, 'diferimiento' => 0]], null, '2026-11')['estado']);
