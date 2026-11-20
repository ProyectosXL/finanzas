<?php
/**
 * La imputacion de facturas de alquiler por razon social
 * (Class/AlquileresImputacion.php): que reemplaza, cuanto se paga y cuando.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA FACTURA Y LA PROYECCION SUMAN LAS DOS. Una imputacion que no saca lo
 *      que cubre cuenta el alquiler dos veces; una que saca de mas (otra razon
 *      social en el mismo local, otro local, otro mes) lo esconde.
 *
 *   2. LAS RETENCIONES NO CIERRAN. Repartidas entre las cuotas, tienen que dar
 *      el total a pagar al centavo.
 *
 *   3. DOS IMPUTACIONES PISAN LO MISMO, o una cuota se imputa dos veces.
 *
 *   4. UNA FACTURA VENCIDA SE APILA EN EL PRIMER DIA DEL EJE.
 *
 *   5. CUANDO TANGO LA PAGA, EL MES VUELVE A PROYECTARSE. La imputacion pagada
 *      sigue reemplazando.
 *
 *   6. SE GUARDA UNA IMPUTACION CON UNA FACTURA QUE CAMBIO. El servidor revalida
 *      contra los pendientes de ahora.
 *
 * No toca la base.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresImputacion.php';

function calendarioImp($desde, $hasta, $feriados = []) {
    $m = [];
    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $m[$d] = intval(date('N', strtotime($d))) <= 5 && !in_array($d, $feriados, true);
    }
    return $m;
}

$HAB = calendarioImp('2026-09-01', '2027-06-30');
$HOY = '2026-11-10';   // martes
$MESES = ['2026-11', '2026-12', '2027-01'];
$CTX = AlquileresFacturas::contexto(['SAPALA', 'SAUNIC', 'SASANJ', 'SAUCCS'], [],
    AlquileresFacturas::condicion(true, ['REAL'], true));

/** Un pago de la proyeccion, de una razon social */
function pagoP($nro, $cod, $mes, $concepto, $aPagar, $grupo = null) {
    return ['nro' => $nro, 'cod_provee' => $cod, 'mes_pago' => $mes, 'fecha' => $mes . '-10', 'concepto' => $concepto,
            'grupo' => $grupo ?? AlquileresConceptos::grupo($concepto), 'mes_concepto' => $mes,
            'origen' => AlquileresCalculo::ESTIMADO, 'a_pagar' => $aPagar, 'neto' => $aPagar, 'iva' => 0.0,
            'percepcion' => 0.0, 'retencion' => 0.0, 'nota_dia' => '', 'detalle' => []];
}

/** Una cuota pendiente, como la devuelve getPendientes() */
function cuotaP($cod, $n, $pend, $vto = '2026-11-20', $extra = []) {
    return array_merge(['COD_PROVEE' => $cod, 'RAZON_SOC' => 'N ' . $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n,
        'FECHA_VTO' => $vto, 'FECHA_EMIS' => '2026-11-02', 'IMPORTE_VTO' => $pend, 'IMPORTE_PENDIENTE' => $pend,
        'LEYENDA' => ''], $extra);
}

/** Una imputacion guardada, como la devuelve AlquileresImputaciones::vigentes() */
function imputacionP($id, $cod, $mes, $locales, $cuotas, $retenciones = [], $estados = [], $tipos = []) {
    $facturas = [];
    foreach ($cuotas as $i => $c) {
        $facturas[] = ['ID' => $id * 100 + $i, 'COD_PROVEE' => $c['COD_PROVEE'], 'T_COMP' => $c['T_COMP'],
            'N_COMP' => $c['N_COMP'], 'FECHA_VTO_TANGO' => $c['FECHA_VTO'], 'PENDIENTE' => $c['IMPORTE_PENDIENTE'],
            'RETENCION' => $retenciones[$i] ?? 0.0, 'PAGADA_AL_IMPUTAR' => false,
            'ESTADO' => $estados[$i] ?? 'PENDIENTE', 'FECHA_PAGADA' => null];
    }
    return ['id' => $id, 'cod_provee' => $cod, 'mes' => $mes, 'todos' => false, 'locales' => $locales,
            'usuario' => 'ANA', 'fecha' => '2026-11-09 10:00', 'observacion' => null,
            'total_facturas' => array_sum(array_column($facturas, 'PENDIENTE')),
            'total_retenciones' => array_sum(array_column($facturas, 'RETENCION')),
            'facturas' => $facturas, 'retenciones' => $tipos];
}

function aplicarP($pagos, $pend, $imps, $extra = []) {
    global $HAB, $HOY, $MESES, $CTX;
    return AlquileresImputacion::aplicar(array_merge(['pagos' => $pagos, 'meses_pago' => $MESES,
        'pendientes' => $pend, 'excluidas' => [], 'vtos' => [], 'imputaciones' => $imps, 'en_tango' => [],
        'ctx' => $CTX, 'habiles' => $HAB, 'hoy' => $HOY], $extra));
}

function sumaDe($pagos, $filtro) {
    return round(array_sum(array_map(function ($p) { return $p['a_pagar']; }, array_filter($pagos, $filtro))), 2);
}

function esFactura($p) { return $p['origen'] === AlquileresCalculo::FACTURA; }

/* LA PROYECCION: dos locales de IRSA (1 y 2), uno de Cencosud (3) con un
   extraordinario, y San Justo (80), donde SASANJ factura el VMA (8) y el 9, y
   SAUCCS las expensas (11), el 6 y su extraordinario. */
$PROY = [
    pagoP(1, 'SAPALA', '2026-11', 1, 1000.0), pagoP(1, 'SAPALA', '2026-11', 8, 500.0),
    pagoP(1, 'SAPALA', '2026-11', 11, 300.0), pagoP(1, 'SAPALA', '2026-11', 7, 200.0),
    pagoP(1, 'SAPALA', '2026-11', 'EXTRA:4', 70.0, AlquileresConceptos::EXTRA),
    pagoP(1, 'SAPALA', '2026-12', 1, 1050.0),
    pagoP(2, 'SAPALA', '2026-11', 1, 3000.0), pagoP(2, 'SAPALA', '2026-11', 11, 600.0),
    pagoP(3, 'SAUNIC', '2026-11', 8, 800.0),
    pagoP(3, 'SAUNIC', '2026-11', 'EXTRA:5', 90.0, AlquileresConceptos::EXTRA),
    pagoP(80, 'SASANJ', '2026-11', 8, 2741.0), pagoP(80, 'SASANJ', '2026-11', 9, 329.0),
    pagoP(80, 'SAUCCS', '2026-11', 11, 3324.0), pagoP(80, 'SAUCCS', '2026-11', 6, 4590.0),
    pagoP(80, 'SAUCCS', '2026-11', 'EXTRA:6', 50.0, AlquileresConceptos::EXTRA)
];

$LOCALES = [1 => ['nombre' => 'ABASTO', 'razones' => ['SAPALA']],
            2 => ['nombre' => 'ALTO PALERMO', 'razones' => ['SAPALA']],
            3 => ['nombre' => 'UNICENTER', 'razones' => ['SAUNIC']],
            80 => ['nombre' => 'SAN JUSTO', 'razones' => ['SASANJ', 'SAUCCS']]];
$TIPOS = [1 => 'Ganancias', 2 => 'IIBB Santa Fe', 3 => 'IIBB CABA', 4 => 'IIBB Bs As'];
$MESES_IMP = ['2026-10', '2026-11', '2026-12', '2027-01'];

seccion('Una factura, todos los locales de la razon social, el mes en curso');

$f1 = cuotaP('SAPALA', 'A1', 6000.0);
$r = aplicarP($PROY, [$f1], [imputacionP(1, 'SAPALA', '2026-11', [1, 2], [$f1], [300.0])]);
$noviembreIrsa = function ($p) { return $p['cod_provee'] === 'SAPALA' && $p['mes_pago'] === '2026-11' && !esFactura($p); };
chequear('pisa todos los conceptos y extraordinarios de IRSA en sus locales ese mes', 7, count($r['reemplazados']));
chequear('incluido el extraordinario', true, in_array('EXTRA:4', array_column($r['reemplazados'], 'concepto'), true));
chequear('y nada mas: diciembre, Cencosud y San Justo siguen', [0.0, 1050.0, 890.0, 11034.0],
    [sumaDe($r['pagos'], $noviembreIrsa),
     sumaDe($r['pagos'], function ($p) { return $p['mes_pago'] === '2026-12'; }),
     sumaDe($r['pagos'], function ($p) { return $p['cod_provee'] === 'SAUNIC'; }),
     sumaDe($r['pagos'], function ($p) { return $p['nro'] === 80; })]);
$fac = array_values(array_filter($r['pagos'], 'esFactura'));
chequear('entra la factura: pendiente menos retencion, en su vencimiento, sin local', [1, 5700.0, '2026-11-20', null, 'SAPALA'],
    [count($fac), $fac[0]['a_pagar'], $fac[0]['fecha'], $fac[0]['nro'], $fac[0]['cod_provee']]);
chequear('y va a la linea de facturas, no a un grupo de conceptos', AlquileresImputacion::GRUPO_FACTURAS, $fac[0]['grupo']);

seccion('Manda lo real: menor o mayor que lo proyectado, sin saldo');

$chica = cuotaP('SAPALA', 'A2', 100.0);
$r = aplicarP($PROY, [$chica], [imputacionP(2, 'SAPALA', '2026-11', [1, 2], [$chica])]);
chequear('menor: noviembre de IRSA es solo la factura', 100.0,
    sumaDe($r['pagos'], function ($p) { return $p['cod_provee'] === 'SAPALA' && $p['mes_pago'] === '2026-11'; }));
$grande = cuotaP('SAPALA', 'A3', 99999.0);
$r = aplicarP($PROY, [$grande], [imputacionP(3, 'SAPALA', '2026-11', [1, 2], [$grande])]);
chequear('mayor: tambien, sin saldo negativo', 99999.0,
    sumaDe($r['pagos'], function ($p) { return $p['cod_provee'] === 'SAPALA' && $p['mes_pago'] === '2026-11'; }));

seccion('Un solo local: los otros locales de la razon social siguen proyectados');

$r = aplicarP($PROY, [$f1], [imputacionP(4, 'SAPALA', '2026-11', [2], [$f1])]);
chequear('pisa Alto Palermo (2) entero', 2, count($r['reemplazados']));
chequear('Abasto (1) sigue proyectado', 2070.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 1 && $p['mes_pago'] === '2026-11'; }));

seccion('San Justo: una factura de SAUCCS no pisa lo de SASANJ');

$fu = cuotaP('SAUCCS', 'U1', 8000.0);
$r = aplicarP($PROY, [$fu], [imputacionP(5, 'SAUCCS', '2026-11', [80], [$fu])]);
chequear('pisa las expensas, el 6 y el extraordinario de SAUCCS', ['11', '6', 'EXTRA:6'],
    array_map('strval', array_column($r['reemplazados'], 'concepto')));
chequear('el VMA y el 9 de SASANJ siguen proyectados', 3070.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 80 && !esFactura($p); }));

seccion('Retenciones: se reparten entre las cuotas por su pendiente, al centavo');

$e = ['pendientes' => [], 'imputaciones' => [], 'locales' => $LOCALES, 'tipos' => $TIPOS, 'meses' => $MESES_IMP,
      'pagos' => $PROY];
$c1 = cuotaP('SAPALA', 'R1', 1000.0);
$c2 = cuotaP('SAPALA', 'R2', 2000.0, '2026-12-04');
$c3 = cuotaP('SAPALA', 'R3', 3333.33, '2026-12-14');
foreach ([$c1, $c2, $c3] as $c) { $e['pendientes'][AlquileresImputacion::claveCuota($c)] = $c; }
$fac = function ($c) {
    return ['cod_provee' => $c['COD_PROVEE'], 't_comp' => 'FAC', 'n_comp' => $c['N_COMP'], 'vto_tango' => $c['FECHA_VTO'],
            'pendiente' => $c['IMPORTE_PENDIENTE']];
};
$v = AlquileresImputacion::validar(['facturas' => [$fac($c1), $fac($c2), $fac($c3)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 1, 'importe' => 100.0], ['tipo' => 3, 'importe' => 33.34]]], $e);
chequear('valida', [true, []], [$v['ok'], $v['errores']]);
chequear('total a pagar = facturas - retenciones', [6333.33, 133.34, 6199.99],
    [$v['total_facturas'], $v['total_retenciones'], $v['total_a_pagar']]);
chequear('las partes de las cuotas suman exacto las retenciones', 133.34,
    round(array_sum(array_column($v['facturas'], 'retencion')), 2));
chequear('y los a pagar de las cuotas, el total a pagar', 6199.99, round(array_sum(array_column($v['facturas'], 'a_pagar')), 2));
chequear('en proporcion al pendiente', [21.05, 42.11, 70.18], array_column($v['facturas'], 'retencion'));
chequear('todos los locales de IRSA', [1, 2], $v['locales']);
chequear('la previsualizacion dice cuanto pisa', [5670.0, 7], [$v['proyectado'], $v['pisa_n']]);

$sinRet = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11', 'retenciones' => []], $e);
chequear('sin retenciones es valido', [true, 1000.0], [$sinRet['ok'], $sinRet['total_a_pagar']]);

$rep = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 1, 'importe' => 10], ['tipo' => 1, 'importe' => 20]]], $e);
chequear('un tipo repetido: error', [false, true], [$rep['ok'], strpos(implode(' ', $rep['errores']), 'está dos veces') !== false]);

$mayor = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 1, 'importe' => 1000.01]]], $e);
chequear('retenciones mayores al total: error', [false, true],
    [$mayor['ok'], strpos(implode(' ', $mayor['errores']), 'no pueden superar') !== false]);

$igual = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 1, 'importe' => 1000.0]]], $e);
chequear('iguales al total (a pagar 0): se permite', [true, 0.0], [$igual['ok'], $igual['total_a_pagar']]);

$baja = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 9, 'importe' => 10]]], $e);
chequear('un tipo dado de baja (no esta entre los vigentes): no se ofrece ni se acepta', false, $baja['ok']);

$cero = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11',
    'retenciones' => [['tipo' => 2, 'importe' => 0]]], $e);
chequear('una linea en 0: error (se quita)', false, $cero['ok']);

seccion('Las reglas de que se puede imputar');

$cu = cuotaP('SAUNIC', 'X1', 500.0);
$e['pendientes'][AlquileresImputacion::claveCuota($cu)] = $cu;
$dos = AlquileresImputacion::validar(['facturas' => [$fac($c1), $fac($cu)], 'todos' => true, 'mes' => '2026-11'], $e);
chequear('facturas de dos razones sociales: error', [false, true],
    [$dos['ok'], strpos(implode(' ', $dos['errores']), 'una sola razón social') !== false]);

$ajeno = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => false, 'local' => 3, 'mes' => '2026-11'], $e);
chequear('un local que no es de la razon social: error', false, $ajeno['ok']);

$fsj = cuotaP('SAUCCS', 'S1', 8000.0);
$e['pendientes'][AlquileresImputacion::claveCuota($fsj)] = $fsj;
$adic = AlquileresImputacion::validar(['facturas' => [$fac($fsj)], 'todos' => true, 'mes' => '2026-11'], $e);
chequear('"todos los locales" de una adicional: donde es adicional', [true, [80]], [$adic['ok'], $adic['locales']]);
chequear('y pisa solo lo suyo en San Justo', [7964.0, 3], [$adic['proyectado'], $adic['pisa_n']]);

$mesMal = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-08'], $e);
chequear('un mes fuera del anterior y del horizonte: error', false, $mesMal['ok']);
$anterior = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-10'], $e);
chequear('el mes anterior se puede: no pisa nada, y lo dice', [true, 0, true],
    [$anterior['ok'], $anterior['pisa_n'], (bool) array_filter($anterior['avisos'], function ($a) {
        return strpos($a['texto'], 'no pisa nada') !== false; })]);

$impVig = imputacionP(20, 'SAPALA', '2026-11', [2], [$c2]);
$sup = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-11'],
    ['imputaciones' => [$impVig]] + $e);
chequear('misma razon, mismo mes y un local en comun: error que nombra la otra', [false, true],
    [$sup['ok'], strpos(implode(' ', $sup['errores']), 'imputación #20') !== false]);
$otro = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => false, 'local' => 1, 'mes' => '2026-11'],
    ['imputaciones' => [$impVig]] + $e);
chequear('mismo mes con locales distintos (una factura por local): se puede', true, $otro['ok']);
$otroMes = AlquileresImputacion::validar(['facturas' => [$fac($c1)], 'todos' => true, 'mes' => '2026-12'],
    ['imputaciones' => [$impVig]] + $e);
chequear('otro mes: se puede', true, $otroMes['ok']);

$ya = AlquileresImputacion::validar(['facturas' => [$fac($c2)], 'todos' => false, 'local' => 1, 'mes' => '2026-12'],
    ['imputaciones' => [$impVig]] + $e);
chequear('una cuota ya imputada: "deshacé primero la #N"', [false, true],
    [$ya['ok'], strpos(implode(' ', $ya['errores']), 'deshacé primero la #20') !== false]);

$cambio = AlquileresImputacion::validar(['facturas' => [['pendiente' => 900.0] + $fac($c1)], 'todos' => true,
    'mes' => '2026-11'], $e);
chequear('una factura que cambio de pendiente entre que se armo y se guardo: frena', [false, true],
    [$cambio['ok'], strpos(implode(' ', $cambio['errores']), 'cambió de pendiente') !== false]);

$nada = AlquileresImputacion::validar(['facturas' => [], 'todos' => true, 'mes' => '2026-11'], $e);
chequear('sin facturas: error', false, $nada['ok']);

seccion('Sin imputar: no suma, y se avisa');

$r = aplicarP($PROY, [$f1], []);
chequear('la proyeccion entera, sin la factura', [count($PROY), 0.0], [count($r['pagos']), sumaDe($r['pagos'], 'esFactura')]);
chequear('el aviso dice cuantas y cuanto', true, (bool) array_filter($r['avisos'], function ($a) {
    return strpos($a['texto'], '1 factura(s) de alquiler sin imputar por $ 6.000,00') === 0; }));

seccion('Deshacer: la proyeccion vuelve');

/* Deshacer es que la imputacion deja de estar entre las vigentes. */
$con = aplicarP($PROY, [$f1], [imputacionP(1, 'SAPALA', '2026-11', [1, 2], [$f1])]);
$sin = aplicarP($PROY, [$f1], []);
chequear('con la imputacion pisa; sin ella, la proyeccion es la de antes', [7, 0, round(array_sum(array_column($PROY, 'a_pagar')), 2)],
    [count($con['reemplazados']), count($sin['reemplazados']), sumaDe($sin['pagos'], function () { return true; })]);

seccion('Las fechas');

$vtos = [AlquileresImputacion::claveCuota($f1) => ['FECHA_VTO' => '2026-12-15']];
chequear('el vencimiento editado reemplaza al de Tango', '2026-12-15', AlquileresImputacion::vtoAConsiderar($f1, $vtos));
chequear('sin editar (o con el ↺), el de Tango', '2026-11-20', AlquileresImputacion::vtoAConsiderar($f1, []));
$r = aplicarP($PROY, [$f1], [imputacionP(1, 'SAPALA', '2026-11', [1], [$f1])], ['vtos' => $vtos]);
chequear('la factura se paga en el vencimiento editado', '2026-12-15',
    array_values(array_filter($r['pagos'], 'esFactura'))[0]['fecha']);

/* LA JERARQUIA: fecha manual > dia de Parametros (el de la razon social o el
   general) en el mes del vencimiento de Tango. El caso real: FAC A0005000027187
   de SAGALL vence en Tango el 20/11 y la razon social tiene dia 18. */
$dias = AlquileresImputacion::diasDeVencimiento(
    ['SAPALA' => ['dia' => 18], 'SAUNIC' => ['dia' => null], 'OTRA' => ['dia' => null]], 10);
chequear('el dia de cada razon social: el suyo, o el general', ['SAPALA' => 18, 'SAUNIC' => 10, 'OTRA' => 10], $dias);
chequear('sin dia propio ni general no esta en el mapa', [],
    AlquileresImputacion::diasDeVencimiento(['SAPALA' => ['dia' => null]], null));
chequear('sin editar, el dia de Parametros en el mes del vencimiento de Tango', '2026-11-18',
    AlquileresImputacion::vtoAConsiderar($f1, [], $dias));
chequear('aunque Tango venza antes del dia de Parametros', '2026-11-18',
    AlquileresImputacion::vtoAConsiderar(cuotaP('SAPALA', 'A9', 1.0, '2026-11-02'), [], $dias));
chequear('la fecha manual manda sobre la de Parametros', '2026-12-15',
    AlquileresImputacion::vtoAConsiderar($f1, $vtos, $dias));
chequear('el origen de cada fecha', [AlquileresImputacion::VTO_EDITADO, AlquileresImputacion::VTO_PARAMETRO,
    AlquileresImputacion::VTO_TANGO], [AlquileresImputacion::origenVto($f1, $vtos, $dias),
    AlquileresImputacion::origenVto($f1, [], $dias), AlquileresImputacion::origenVto(cuotaP('XXXX', 'X9', 1.0), [], $dias)]);
$r = aplicarP($PROY, [$f1], [imputacionP(1, 'SAPALA', '2026-11', [1], [$f1])], ['dias' => $dias]);
chequear('la factura imputada se paga el dia de Parametros', '2026-11-18',
    array_values(array_filter($r['pagos'], 'esFactura'))[0]['fecha']);

/* Dos cuotas, cada una en su vencimiento, con su parte de las retenciones. */
$r = aplicarP($PROY, [$c1, $c2], [imputacionP(6, 'SAPALA', '2026-11', [1, 2], [$c1, $c2], [10.0, 20.0])]);
$fs = array_values(array_filter($r['pagos'], 'esFactura'));
chequear('cada cuota se paga en su vencimiento, por pendiente menos su parte', [['2026-11-20', 990.0], ['2026-12-04', 1980.0]],
    array_map(function ($p) { return [$p['fecha'], $p['a_pagar']]; }, $fs));

$d = AlquileresImputacion::fechaDePago('2026-11-05', $HAB, $HOY);
chequear('vencida: el siguiente habil desde hoy, no el primer dia del eje', ['2026-11-10', true], [$d['fecha'], $d['vencido']]);
chequear('y lo anota', true, strpos(AlquileresImputacion::notaFecha($d), 'vencía el 05/11/2026') !== false);
$d = AlquileresImputacion::fechaDePago('2026-11-21', $HAB, $HOY);
chequear('un sabado pasa al lunes', ['2026-11-23', true], [$d['fecha'], $d['corrida']]);

seccion('Las facturas del mes, pagadas o no');

$pm = cuotaP('SAPALA', 'PM1', 0.0, '2026-11-12', ['IMPORTE_VTO' => 1500.0, 'FECHA_CANCELADO' => '2026-11-04']);
$r = aplicarP($PROY, [], [], ['pagadas_mes' => [$pm]]);
chequear('se ve en la grilla como Pagada, sin imputar', [AlquileresImputacion::PAGADA_SIN_IMPUTAR, 1500.0, true,
    '2026-11-04'], [$r['facturas'][0]['estado'], $r['facturas'][0]['a_imputar'], $r['facturas'][0]['del_mes'],
    $r['facturas'][0]['fecha_pagada']]);
chequear('no se paga otra vez, y la proyeccion sigue entera', [0.0, count($PROY)],
    [sumaDe($r['pagos'], 'esFactura'), count($r['pagos'])]);

$pmPend = AlquileresImputacion::comoPendiente($pm);
$vPm = AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'PM1',
    'vto_tango' => '2026-11-12', 'pendiente' => 1500.0]], 'todos' => true, 'mes' => '2026-11'],
    ['pendientes' => [AlquileresImputacion::claveCuota($pmPend) => $pmPend]] + $e);
chequear('una pagada sin imputar se imputa por el importe de la cuota', [true, 1500.0, true],
    [$vPm['ok'], $vPm['total_facturas'], $vPm['facturas'][0]['pagada']]);

$impPm = imputacionP(11, 'SAPALA', '2026-11', [1], [['FECHA_VTO' => '2026-11-12', 'IMPORTE_PENDIENTE' => 1500.0] + $pm]);
$r = aplicarP($PROY, [], [$impPm], ['pagadas_mes' => [$pm],
    'en_tango' => ['SAPALA|PM1' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-11-04']]]]);
chequear('imputada y pagada: reemplaza el mes y no se paga', [5, 0.0],
    [count($r['reemplazados']), sumaDe($r['pagos'], 'esFactura')]);

seccion('Pagada en Tango');

$fp = cuotaP('SAPALA', 'P1', 1600.0);
$impP = imputacionP(7, 'SAPALA', '2026-11', [1], [$fp]);
$tango = ['SAPALA|P1' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-11-09']]];
$r = aplicarP($PROY, [], [$impP], ['en_tango' => $tango]);
chequear('salio de pendientes: no se paga otra vez', 0.0, sumaDe($r['pagos'], 'esFactura'));
chequear('pero sigue reemplazando: noviembre de Abasto no vuelve', 5, count($r['reemplazados']));
chequear('y se marca Pagada con la fecha de Tango', [['id' => 700, 'fecha' => '2026-11-09']],
    array_map(function ($p) { return ['id' => $p['id'], 'fecha' => $p['fecha']]; }, $r['pagadas_nuevas']));
$impP2 = imputacionP(7, 'SAPALA', '2026-11', [1], [$fp], [], ['PAGADA']);
chequear('ya marcada, no se vuelve a marcar', [], aplicarP($PROY, [], [$impP2], ['en_tango' => null])['pagadas_nuevas']);

seccion('Anulada, sin dato de Tango, pendiente cambiado y suspendida');

$r = aplicarP($PROY, [], [$impP], ['en_tango' => []]);
chequear('anulada: deja de reemplazar, la proyeccion vuelve, con aviso', [0, true], [count($r['reemplazados']),
    (bool) array_filter($r['avisos'], function ($a) { return strpos($a['texto'], 'Imputá la factura nueva') !== false; })]);
$r = aplicarP($PROY, [], [$impP], ['en_tango' => null]);
chequear('sin dato de Tango: tampoco reemplaza, y avisa', [0, true], [count($r['reemplazados']),
    (bool) array_filter($r['avisos'], function ($a) { return strpos($a['texto'], 'No se pudo leer en Tango') === 0; })]);

$fc = cuotaP('SAPALA', 'Q1', 1000.0);
$r = aplicarP($PROY, [cuotaP('SAPALA', 'Q1', 800.0)], [imputacionP(8, 'SAPALA', '2026-11', [1], [$fc], [50.0])]);
chequear('pendiente cambiado: se paga el nuevo menos su retencion, y se avisa', [750.0, true],
    [sumaDe($r['pagos'], 'esFactura'), (bool) array_filter($r['avisos'], function ($a) {
        return strpos($a['texto'], 'Cambió en Tango el pendiente') === 0; })]);

$ctxExcl = AlquileresFacturas::contexto(['SAPALA'], ['SAPALA'], AlquileresFacturas::condicion(true, ['REAL'], true));
$r = aplicarP($PROY, [], [imputacionP(9, 'SAPALA', '2026-11', [1], [$f1])], ['ctx' => $ctxExcl]);
chequear('suspendida (proveedor excluido): no se lee, no reemplaza ni suma', [0, 0.0],
    [count($r['reemplazados']), sumaDe($r['pagos'], 'esFactura')]);

seccion('La grilla de Facturas y el resumen de la imputacion');

$r = aplicarP($PROY, [$c1, $c2], [imputacionP(6, 'SAPALA', '2026-11', [1, 2], [$c1, $c2], [10.0, 20.0],
    [], [['tipo' => 1, 'importe' => 25.0], ['tipo' => 9, 'importe' => 5.0]])],
    ['tipos' => $TIPOS + [9 => 'IIBB viejo'], 'nombres_locales' => [1 => 'ABASTO', 2 => 'ALTO PALERMO']]);
chequear('la columna Retencion es la parte de cada cuota', [10.0, 20.0], array_column($r['facturas'], 'retencion'));
$res = $r['imputaciones'][0];
chequear('el resumen trae las retenciones por tipo, con el nombre aunque este dado de baja',
    [['tipo' => 1, 'nombre' => 'Ganancias', 'importe' => 25.0], ['tipo' => 9, 'nombre' => 'IIBB viejo', 'importe' => 5.0]],
    $res['retenciones']);
chequear('los locales con su nombre, el total a pagar y quien la cargo', [['ABASTO', 'ALTO PALERMO'], 2970.0, 'ANA'],
    [array_column($res['locales'], 'nombre'), $res['total_a_pagar'], $res['usuario']]);
chequear('y si el mes queda fuera del horizonte', [false, true],
    [$res['fuera_horizonte'], aplicarP($PROY, [$c1], [imputacionP(30, 'SAPALA', '2026-10', [1], [$c1])])['imputaciones'][0]['fuera_horizonte']]);
$r10 = aplicarP($PROY, [$c1], [imputacionP(30, 'SAPALA', '2026-10', [1], [$c1])]);
chequear('el mes anterior no pisa nada del horizonte, y la factura se paga igual', [0, 1000.0],
    [count($r10['reemplazados']), sumaDe($r10['pagos'], 'esFactura')]);

seccion('Dar de baja una razon social con imputaciones de facturas pendientes');

$impBaja = imputacionP(10, 'SAPALA', '2026-11', [1], [$c1]);
$bl = AlquileresImputacion::bloqueanBaja('SAPALA', [$impBaja], [AlquileresImputacion::claveCuota($c1) => true]);
chequear('la factura sigue pendiente: bloquea', 1, count($bl));
$msg = AlquileresImputacion::mensajeBajaBloqueada('SAPALA', $bl);
chequear('el mensaje lista factura, mes que cubre y quien la cargo', [true, true, true],
    [strpos($msg, 'FAC R1') !== false, strpos($msg, '2026-11') !== false, strpos($msg, 'ANA') !== false]);
chequear('ya pagada (no esta en pendientes): no bloquea', [], AlquileresImputacion::bloqueanBaja('SAPALA', [$impBaja], []));

seccion('El reparto a centavos');

chequear('cierra exacto con resto mayor', 100.0, array_sum(AlquileresImputacion::repartirCentavos(100.0, [1, 1, 1])));
chequear('con pesos en cero, partes iguales', [5.0, 5.0], array_values(AlquileresImputacion::repartirCentavos(10.0, [0, 0])));

seccion('El modelo de renglones ya no esta');

$cls = file_get_contents(__DIR__ . '/../Class/AlquileresImputacion.php');
chequear('ni renglones, ni atajos, ni pesos por local, ni retencion sugerida', [false, false, false, false, false, false], [
    (bool) preg_match('/function (pesosLocales|ponderados|atajos|grupoDeRenglon)/', $cls),
    strpos($cls, 'GRUPO_VARIOS') !== false, strpos($cls, 'GRUPO_OTROS') !== false,
    strpos($cls, "const EXTRA") !== false, strpos($cls, "const OTROS") !== false,
    strpos($cls, 'retencion_sugerida') !== false]);

seccion('El cableado: permisos, endpoints, pantalla y script');

require_once __DIR__ . '/../Class/AuthCashflow.php';
require_once __DIR__ . '/../Class/AlquileresImputaciones.php';
$ctrl = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
$jsF = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Alquileres-Facturas.js'));
$tabA = file_get_contents(__DIR__ . '/../Tabs/alquileres.php');

foreach (['editarVtoFactura', 'deshacerVtoFactura', 'previsualizarImputacion', 'guardarImputacion',
          'deshacerImputacion'] as $accion) {
    chequear($accion . ' pide cashflow.editar.alquileres, el controller la atiende y el JS la pide',
        [[['alquileres', null]], 1, true],
        [AuthCashflow::ESCRITURAS['Alquileres'][$accion] ?? null, substr_count($ctrl, "case '" . $accion . "':"),
         strpos($jsF, $accion) !== false]);
}

chequear('guardar revalida contra los pendientes de ahora y exige el script 60', [true, true], [
    strpos($ctrl, '$v = AlquileresImputacion::validar($data, $e);') !== false,
    strpos($ctrl, "if (empty(\$e['tabla_imputacion'])) {") !== false]);
chequear('la baja de una razon social pasa por el bloqueo', true, strpos($ctrl, 'AlquileresImputacion::bloqueanBaja(') !== false);
chequear('el modal: locales, mes, + Retencion y totales; sin renglones', [true, true, true, true, false], [
    strpos($tabA, 'id="alqImpLocal"') !== false, strpos($tabA, 'id="alqImpMes"') !== false,
    strpos($tabA, 'id="btnAlqImpRetencion"') !== false, strpos($tabA, 'id="alqImpTotales"') !== false,
    strpos($tabA, 'alqImpRenglones') !== false]);
chequear('una sola razon social: las de otra se deshabilitan con tooltip', true,
    strpos($jsF, "var otra = tildable && razon !== null && f.cod_provee !== razon;") !== false);
chequear('tildar todas: la casilla va en el encabezado, sin bajar al Excel', true,
    (bool) preg_match('/<th[^>]*data-exportar-omitir>\s*<input type="checkbox"[^>]*id="alqFactSelTodas"/s', $tabA));
chequear('tildar todas toma solo las sin imputar que se ven (las imputadas el servidor las rechaza)', [true, true], [
    strpos($jsF, "var ESTADOS_TILDAR_TODAS = ESTADOS_EXCLUIBLES;") !== false,
    strpos($jsF, "return visibles().filter(function(f) { return ESTADOS_TILDAR_TODAS.indexOf(f.estado) !== -1; });") !== false]);
chequear('tildar todas se habilita solo con una razon social, y la de lo ya tildado', true,
    strpos($jsF, "chk.disabled = cods.length !== 1 || (tildada !== null && tildada !== cods[0]);") !== false);
chequear('el encabezado sigue a cada cambio de tilde', true,
    (bool) preg_match('/function actualizarBoton\(\) \{.*?pintarSelTodas\(\);/s', $jsF));
chequear('un tipo ya elegido no se ofrece en otra linea', true,
    strpos($jsF, "tipos.filter(function(t) { return usados.indexOf(String(t.id)) === -1; })") !== false);
chequear('sin el script 60, no se tilda ni se imputa, y se avisa nombrandolo', [true, true], [
    strpos($jsF, "return puedeEditar() && !!datos.tablas;") !== false,
    strpos($jsF, "falta correr ' + esc(datos.script)") !== false]);
chequear('Guardar exige la misma previsualizacion: cualquier cambio la invalida', true,
    strpos($jsF, "previsto !== JSON.stringify(b)") !== false);

$s60 = file_get_contents(__DIR__ . '/../' . AlquileresImputaciones::SCRIPT);
chequear('el script 60 borra las cinco tablas viejas, en el orden de las FK, contando antes', [true, true, true], [
    (bool) preg_match("/\(1, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL'\),\s*\(2, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO'\),"
        . "\s*\(3, 'RO_T_CASHFLOW_ALQ_IMP_RENGLON'\),\s*\(4, 'RO_T_CASHFLOW_ALQ_IMP_FACTURA'\),\s*\(5, 'RO_T_CASHFLOW_ALQ_IMPUTACION'\)/", $s60),
    strpos($s60, "SELECT @n = COUNT(*) FROM dbo.") !== false, strpos($s60, "N'DROP TABLE dbo.'") !== false]);
chequear('en una transaccion, y crea las cuatro nuevas', [true, true, true, true, true], [
    strpos($s60, 'BEGIN TRANSACTION;') !== false && strpos($s60, 'ROLLBACK TRANSACTION;') !== false,
    strpos($s60, 'CREATE TABLE dbo.' . AlquileresImputaciones::T_IMP) !== false,
    strpos($s60, 'CREATE TABLE dbo.' . AlquileresImputaciones::T_CUOTA) !== false,
    strpos($s60, 'CREATE TABLE dbo.' . AlquileresImputaciones::T_LOCAL) !== false,
    strpos($s60, 'CREATE TABLE dbo.' . AlquileresImputaciones::T_RETENCION) !== false]);
chequear('una cuota vigente una sola vez, y un tipo una vez por imputacion', [true, true], [
    (bool) preg_match('/UX_RO_T_CF_ALQIRC_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ALQ_IMPRS_CUOTA \(COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO\) WHERE VIGENTE = 1/', $s60),
    strpos($s60, 'PRIMARY KEY CLUSTERED (ID_IMPUTACION, ID_TIPO)') !== false]);
$s53 = file_get_contents(__DIR__ . '/../sql/cashflow_alquileres_facturas.sql');
chequear('el script 53 ya no crea las tablas viejas, pero si el vencimiento', [false, true], [
    (bool) preg_match('/CREATE TABLE dbo\.RO_T_CASHFLOW_ALQ_IMP/', $s53),
    strpos($s53, 'CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO') !== false]);
chequear('la condicion de seguridad mira la tabla que queda', 'RO_T_CASHFLOW_ALQ_FACTURA_VTO', AlquileresFacturas::TABLA_CONDICION);
chequear('y el script 55 tambien', true,
    strpos(file_get_contents(__DIR__ . '/../sql/cashflow_alquileres_fila.sql'), "IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO', 'U') IS NULL") !== false);

/* CONTRA LA BASE: con el script 60 corrido, las tablas viejas no estan y las
   nuevas si. Sin el, la pantalla avisa y no deja imputar (lo de arriba). */
if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $imps = new AlquileresImputaciones();

    if ($imps->tablaCreada()) {
        require_once __DIR__ . '/../../class/conexion.php';
        $cid = (new Conexion())->conectar('central');
        $st = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPUTACION', 'U') AS V");
        $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
        chequear('con el script 60 corrido: las tablas viejas ya no estan', null, $row['V']);
    } else {
        chequear('sin el script 60: el aviso nombra el script', true,
            strpos($imps->avisoSinTabla(), AlquileresImputaciones::SCRIPT) !== false);
    }
}
