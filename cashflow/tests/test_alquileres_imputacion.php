<?php
/**
 * La imputacion de facturas de alquiler (Class/AlquileresImputacion.php): que
 * reemplaza, cuanto se paga y cuando.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA FACTURA Y LA PROYECCION SUMAN LAS DOS. Un renglon que no saca lo que
 *      cubre cuenta el alquiler dos veces; uno que saca de mas (otro concepto,
 *      otro local, otro mes) lo esconde.
 *
 *   2. EL REPARTO POR LOCAL NO CIERRA. La suma de las piezas tiene que dar el
 *      pendiente de la factura al centavo, y la retencion la suya.
 *
 *   3. UNA FACTURA VENCIDA SE APILA EN EL PRIMER DIA DEL EJE.
 *
 *   4. CUANDO TANGO LA PAGA, EL MES VUELVE A PROYECTARSE. La imputacion pagada
 *      sigue reemplazando.
 *
 *   5. SE GUARDA UNA IMPUTACION CON UNA FACTURA QUE CAMBIO. El servidor revalida
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
$CTX = AlquileresFacturas::contexto(['SAPALA', 'SAUNIC'], [], AlquileresFacturas::condicion(true, ['REAL'], true));

/** Un pago de la proyeccion */
function pagoP($nro, $mes, $concepto, $aPagar, $grupo = null) {
    return ['nro' => $nro, 'mes_pago' => $mes, 'fecha' => $mes . '-10', 'concepto' => $concepto,
            'grupo' => $grupo ?? AlquileresConceptos::grupo($concepto), 'mes_concepto' => $mes,
            'origen' => AlquileresCalculo::ESTIMADO, 'a_pagar' => $aPagar, 'neto' => $aPagar, 'iva' => 0.0,
            'percepcion' => 0.0, 'retencion' => 0.0, 'nota_dia' => '', 'detalle' => []];
}

/** Una cuota pendiente, como la devuelve getPendientes() */
function cuotaP($cod, $n, $pend, $vto = '2026-11-20', $extra = []) {
    return array_merge(['COD_PROVEE' => $cod, 'RAZON_SOC' => 'N ' . $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n,
        'FECHA_VTO' => $vto, 'FECHA_EMIS' => '2026-11-02', 'IMPORTE_VTO' => $pend, 'IMPORTE_PENDIENTE' => $pend,
        'NETO_GRAVADO_COMP' => null, 'IMPORTE_COMP' => $pend, 'LEYENDA' => ''], $extra);
}

/** Una imputacion guardada, como la devuelve AlquileresImputaciones::vigentes() */
function imputacionP($id, $cuotas, $renglones, $retenciones = [], $estados = []) {
    $facturas = [];
    foreach ($cuotas as $i => $c) {
        $facturas[] = ['ID' => $id * 100 + $i, 'COD_PROVEE' => $c['COD_PROVEE'], 'T_COMP' => $c['T_COMP'],
            'N_COMP' => $c['N_COMP'], 'FECHA_VTO_TANGO' => $c['FECHA_VTO'], 'PENDIENTE' => $c['IMPORTE_PENDIENTE'],
            'NETO_GRAVADO' => null, 'RETENCION' => $retenciones[$i] ?? 0.0, 'RETENCION_SUGERIDA' => null,
            'ESTADO' => $estados[$i] ?? 'PENDIENTE', 'FECHA_PAGADA' => null];
    }
    $ren = [];
    foreach ($renglones as $k => $r) {
        $ren[] = $r + ['orden' => $k + 1, 'todos_de' => null];
    }
    return ['id' => $id, 'usuario' => 'ANA', 'fecha' => '2026-11-09 10:00', 'observacion' => null,
            'facturas' => $facturas, 'renglones' => $ren];
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

/* La proyeccion: dos locales de IRSA (1 y 2) y uno de Cencosud (3). */
$PROY = [
    pagoP(1, '2026-11', 1, 1000.0), pagoP(1, '2026-11', 8, 500.0), pagoP(1, '2026-11', 11, 300.0),
    pagoP(1, '2026-11', 7, 200.0), pagoP(1, '2026-12', 1, 1050.0),
    pagoP(2, '2026-11', 1, 3000.0), pagoP(2, '2026-11', 11, 600.0),
    pagoP(3, '2026-11', 8, 800.0), pagoP(3, '2026-11', 'EXTRA:5', 90.0, AlquileresConceptos::EXTRA)
];

seccion('Una factura, un local, los fijos');

$f1 = cuotaP('SAPALA', 'A1', 1600.0);
$r = aplicarP($PROY, [$f1], [imputacionP(1, [$f1], [['conceptos' => ['1', '2', '3', '8'], 'locales' => [1],
    'mes' => '2026-11', 'importe' => 1600.0]], [32.0])]);

chequear('saca los fijos del local 1 en noviembre (1 y 8)', 2, count($r['reemplazados']));
chequear('las expensas y el variable del local 1 siguen proyectados', 500.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 1 && $p['mes_pago'] === '2026-11' && !esFactura($p); }));
chequear('diciembre del local 1 no se toca', 1050.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 1 && $p['mes_pago'] === '2026-12'; }));
chequear('el local 2 no se toca', 3600.0, sumaDe($r['pagos'], function ($p) { return $p['nro'] === 2; }));
chequear('la factura: pendiente menos retencion', 1568.0, sumaDe($r['pagos'], 'esFactura'));
chequear('manda lo real: 1.600 contra 1.500 proyectados, sin saldo', 1568.0 + 500.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 1 && $p['mes_pago'] === '2026-11'; }));
$pz = array_values(array_filter($r['pagos'], 'esFactura'))[0];
chequear('en la fecha de la factura, no en la de la proyeccion', '2026-11-20', $pz['fecha']);
chequear('con origen Factura y de que imputacion y renglon es', [AlquileresCalculo::FACTURA, 1, 1],
    [$pz['origen'], $pz['detalle']['imputacion'], $pz['detalle']['renglon']]);
chequear('en el grupo de sus conceptos', AlquileresConceptos::FIJOS, $pz['grupo']);
chequear('la grilla dice Imputada', AlquileresImputacion::IMPUTADA, $r['facturas'][0]['estado']);
chequear('con su a pagar', 1568.0, $r['facturas'][0]['a_pagar']);

$menor = cuotaP('SAPALA', 'A1', 900.0);
$rm = aplicarP($PROY, [$menor], [imputacionP(1, [$menor], [['conceptos' => ['1', '8'], 'locales' => [1],
    'mes' => '2026-11', 'importe' => 900.0]])]);
chequear('real menor que lo proyectado: manda lo real', 900.0, sumaDe($rm['pagos'], 'esFactura'));

seccion('Una factura a N locales con un importe total: reparto proporcional');

$f2 = cuotaP('SAPALA', 'B1', 8000.0);
$r = aplicarP($PROY, [$f2], [imputacionP(2, [$f2], [['conceptos' => ['1'], 'locales' => [1, 2],
    'mes' => '2026-11', 'importe' => 8000.0]], [80.0])]);
$porLocal = [];
foreach (array_filter($r['pagos'], 'esFactura') as $p) { $porLocal[$p['nro']] = ($porLocal[$p['nro']] ?? 0) + $p['neto']; }
chequear('1.000 y 3.000 proyectados: 2.000 y 6.000 de la factura', [1 => 2000.0, 2 => 6000.0], $porLocal);
chequear('la retencion se reparte con los mismos pesos: el total es el de la factura', 7920.0,
    sumaDe($r['pagos'], 'esFactura'));

$f3 = cuotaP('SAPALA', 'B2', 100.0);
$r = aplicarP($PROY, [$f3], [imputacionP(3, [$f3], [['conceptos' => ['3'], 'locales' => [1, 2],
    'mes' => '2026-11', 'importe' => 100.0]])]);
chequear('nada proyectado (concepto 3): partes iguales', [50.0, 50.0],
    array_column(array_values(array_filter($r['pagos'], 'esFactura')), 'neto'));
chequear('y se avisa', true, (bool) array_filter($r['avisos'], function ($a) {
    return strpos($a['texto'], 'partes iguales') !== false; }));

$f4 = cuotaP('SAPALA', 'B3', 100.0);
$r = aplicarP($PROY, [$f4], [imputacionP(4, [$f4], [['conceptos' => ['1'], 'locales' => [1, 2],
    'mes' => '2026-11', 'importe' => 100.0]])]);
chequear('los centavos cierran: 33,33 + ... da 100 exacto', 100.0, sumaDe($r['pagos'], 'esFactura'));

seccion('Dos facturas de dos razones sociales, tres renglones');

$fa = cuotaP('SAPALA', 'C1', 3000.0, '2026-11-20');
$fb = cuotaP('SAUNIC', 'C2', 1000.0, '2026-12-04');
$imp = imputacionP(5, [$fa, $fb], [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 1200.0],
    ['conceptos' => ['11'], 'locales' => [1, 2], 'mes' => '2026-11', 'importe' => 1800.0],
    ['conceptos' => ['8', 'EXTRA'], 'locales' => [3], 'mes' => '2026-11', 'importe' => 1000.0]
], [60.0, 20.0]);
$r = aplicarP($PROY, [$fa, $fb], [$imp]);
chequear('la de IRSA cae el 20/11 por su pendiente menos retencion', 2940.0,
    sumaDe($r['pagos'], function ($p) { return esFactura($p) && $p['fecha'] === '2026-11-20'; }));
chequear('la de Cencosud el 04/12', 980.0,
    sumaDe($r['pagos'], function ($p) { return esFactura($p) && $p['fecha'] === '2026-12-04'; }));
chequear('el renglon 3 saca el VMA y el extraordinario de Cencosud', 0.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 3 && !esFactura($p); }));
chequear('y cae en un grupo aparte: abarca dos grupos', AlquileresImputacion::GRUPO_VARIOS,
    array_values(array_filter($r['pagos'], function ($p) { return esFactura($p) && $p['nro'] === 3; }))[0]['grupo']);
chequear('el total de la imputacion es el de las facturas', 3920.0, sumaDe($r['pagos'], 'esFactura'));

seccion('Otros (no proyectados): suma y no reemplaza');

$fo = cuotaP('SAPALA', 'D1', 70.0);
$r = aplicarP($PROY, [$fo], [imputacionP(6, [$fo], [['conceptos' => ['OTROS'], 'locales' => [1],
    'mes' => '2026-11', 'importe' => 70.0]])]);
chequear('no saca nada de la proyeccion', 0, count($r['reemplazados']));
chequear('y suma en real', 70.0, sumaDe($r['pagos'], 'esFactura'));
chequear('en su grupo', AlquileresImputacion::GRUPO_OTROS,
    array_values(array_filter($r['pagos'], 'esFactura'))[0]['grupo']);

seccion('Sin imputar: no suma, y se avisa');

$fs = cuotaP('SAPALA', 'E1', 1234.5);
$r = aplicarP($PROY, [$fs], []);
chequear('ninguna factura en la lista', 0.0, sumaDe($r['pagos'], 'esFactura'));
chequear('la proyeccion entera', sumaDe($PROY, function () { return true; }), sumaDe($r['pagos'], function () { return true; }));
chequear('la grilla: Sin imputar', AlquileresImputacion::SIN_IMPUTAR, $r['facturas'][0]['estado']);
chequear('el aviso, en atencion, con cuantas y cuanto', [Aviso::WARNING,
    '1 factura(s) de alquiler sin imputar por $ 1.234,50: no suman hasta que se imputen.'],
    [$r['avisos'][0]['nivel'], $r['avisos'][0]['texto']]);
chequear('la tarjeta', ['sin_imputar_n' => 1, 'sin_imputar_importe' => 1234.5],
    array_intersect_key($r['kpis'], array_flip(['sin_imputar_n', 'sin_imputar_importe'])));

seccion('Deshacer: la proyeccion vuelve');

/* Deshacer es darla de baja: deja de estar entre las vigentes. */
$r = aplicarP($PROY, [$f1], []);
chequear('sin la imputacion, los fijos del local 1 vuelven', 1500.0,
    sumaDe($r['pagos'], function ($p) { return $p['nro'] === 1 && in_array($p['concepto'], [1, 8], true)
        && $p['mes_pago'] === '2026-11'; }));

seccion('Las fechas');

$vtos = [AlquileresImputacion::claveCuota($f1) => ['FECHA_VTO' => '2026-12-15']];
chequear('el vencimiento editado reemplaza al de Tango', '2026-12-15', AlquileresImputacion::vtoAConsiderar($f1, $vtos));
chequear('sin editar (o con el ↺), el de Tango', '2026-11-20', AlquileresImputacion::vtoAConsiderar($f1, []));
$r = aplicarP($PROY, [$f1], [imputacionP(1, [$f1], [['conceptos' => ['1'], 'locales' => [1],
    'mes' => '2026-11', 'importe' => 1600.0]])], ['vtos' => $vtos]);
chequear('la factura se paga en el vencimiento editado', '2026-12-15',
    array_values(array_filter($r['pagos'], 'esFactura'))[0]['fecha']);

$d = AlquileresImputacion::fechaDePago('2026-11-05', $HAB, $HOY);
chequear('vencida: el siguiente habil desde hoy, no el primer dia del eje', ['2026-11-10', true], [$d['fecha'], $d['vencido']]);
chequear('y lo anota', true, strpos(AlquileresImputacion::notaFecha($d), 'vencía el 05/11/2026') !== false);
$d = AlquileresImputacion::fechaDePago('2026-09-15', $HAB, $HOY);
chequear('vencida hace dos meses tambien', '2026-11-10', $d['fecha']);
$d = AlquileresImputacion::fechaDePago('2026-11-21', $HAB, $HOY);
chequear('un sabado pasa al lunes', ['2026-11-23', true], [$d['fecha'], $d['corrida']]);

seccion('Pagada en Tango');

$fp = cuotaP('SAPALA', 'P1', 1600.0);
$impP = imputacionP(7, [$fp], [['conceptos' => ['1', '8'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 1600.0]]);
$tango = ['SAPALA|P1' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-11-09']]];
$r = aplicarP($PROY, [], [$impP], ['en_tango' => $tango]);
chequear('salio de pendientes: no se paga otra vez', 0.0, sumaDe($r['pagos'], 'esFactura'));
chequear('pero sigue reemplazando: los fijos de noviembre no vuelven', 2, count($r['reemplazados']));
chequear('y se marca Pagada con la fecha de Tango', [['id' => 700, 'fecha' => '2026-11-09']],
    array_map(function ($p) { return ['id' => $p['id'], 'fecha' => $p['fecha']]; }, $r['pagadas_nuevas']));
chequear('la grilla la muestra Pagada', AlquileresImputacion::PAGADA, $r['facturas'][0]['estado']);
$impP2 = imputacionP(7, [$fp], $impP['renglones'], [], ['PAGADA']);
chequear('ya marcada, no se vuelve a marcar', [], aplicarP($PROY, [], [$impP2], ['en_tango' => null])['pagadas_nuevas']);

seccion('Desaparecida sin pago (anulada) y sin dato de Tango');

$r = aplicarP($PROY, [], [$impP], ['en_tango' => []]);
chequear('anulada: deja de reemplazar, la proyeccion vuelve', 0, count($r['reemplazados']));
chequear('con aviso de atencion para imputar la nueva', true, (bool) array_filter($r['avisos'], function ($a) {
    return $a['nivel'] === Aviso::WARNING && strpos($a['texto'], 'Imputá la factura nueva') !== false; }));
$r = aplicarP($PROY, [], [$impP], ['en_tango' => null]);
chequear('sin dato de Tango: tampoco reemplaza, y avisa', [0, true], [count($r['reemplazados']),
    (bool) array_filter($r['avisos'], function ($a) { return strpos($a['texto'], 'No se pudo leer en Tango') === 0; })]);

seccion('El pendiente cambia sin cancelarse');

$fc = cuotaP('SAPALA', 'Q1', 1000.0);
$impC = imputacionP(8, [$fc], [['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 1000.0]], [50.0]);
$r = aplicarP($PROY, [cuotaP('SAPALA', 'Q1', 800.0)], [$impC]);
chequear('se paga el pendiente nuevo menos la retencion', 750.0, sumaDe($r['pagos'], 'esFactura'));
chequear('y se avisa', true, (bool) array_filter($r['avisos'], function ($a) {
    return strpos($a['texto'], 'Cambió en Tango el pendiente') === 0; }));

seccion('Suspendida: un proveedor excluido o dado de baja');

$ctxExcl = AlquileresFacturas::contexto(['SAPALA'], ['SAPALA'], AlquileresFacturas::condicion(true, ['REAL'], true));
$r = aplicarP($PROY, [], [imputacionP(9, [$f1], [['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11',
    'importe' => 1600.0]])], ['ctx' => $ctxExcl]);
chequear('no se lee: no reemplaza ni suma', [0, 0.0], [count($r['reemplazados']), sumaDe($r['pagos'], 'esFactura')]);

seccion('La validacion de una imputacion');

$locales = [1 => ['cod_provee' => 'SAPALA', 'nombre' => 'ABASTO', 'pct_iva' => 21.0, 'pct_percepcion' => 3.0,
                  'pct_retencion' => 2.0],
            2 => ['cod_provee' => 'SAPALA', 'nombre' => 'ALTO PALERMO', 'pct_iva' => 21.0, 'pct_percepcion' => 3.0,
                  'pct_retencion' => 2.0],
            3 => ['cod_provee' => 'SAUNIC', 'nombre' => 'UNICENTER', 'pct_iva' => 21.0, 'pct_percepcion' => 3.0,
                  'pct_retencion' => 1.0]];
$pendAhora = [AlquileresImputacion::claveCuota($fa) => $fa, AlquileresImputacion::claveCuota($fb) => $fb];
$borrador = function ($pendA, $pendB, $renglones) {
    return ['facturas' => [
        ['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'C1', 'vto_tango' => '2026-11-20', 'pendiente' => $pendA, 'retencion' => 60],
        ['cod_provee' => 'SAUNIC', 't_comp' => 'FAC', 'n_comp' => 'C2', 'vto_tango' => '2026-12-04', 'pendiente' => $pendB, 'retencion' => null]],
        'renglones' => $renglones];
};
$e = ['pendientes' => $pendAhora, 'imputaciones' => [], 'pagos' => $PROY, 'locales' => $locales];
$ok = AlquileresImputacion::validar($borrador(3000, 1000, [
    ['conceptos' => ['1', '11'], 'locales' => [1, 2], 'mes' => '2026-11', 'importe' => 3000],
    ['conceptos' => ['8'], 'todos_de' => 'SAUNIC', 'mes' => '2026-11', 'importe' => 1000.5]]), $e);
chequear('cierra con ±$1: se puede guardar', [true, []], [$ok['ok'], $ok['errores']]);
chequear('"todos los de la razon social" son sus locales', [3], $ok['renglones'][1]['locales']);
chequear('la retencion sugerida sale de la alicuota de los locales sobre el neto', true,
    $ok['facturas'][1]['retencion_sugerida'] > 0);
chequear('sin neto en Tango, el neto sale del importe con el IVA y la percepcion de los locales', 'LOCALES',
    $ok['facturas'][1]['neto_de']);

$mal = AlquileresImputacion::validar($borrador(3000, 1000, [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3500]]), $e);
chequear('la suma de los renglones no da: no se guarda', false, $mal['ok']);
chequear('y dice la diferencia', -500.0, $mal['diferencia']);

$cambio = AlquileresImputacion::validar($borrador(2900, 1000, [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3900]]), $e);
chequear('una factura que cambio de pendiente entre que se armo y se guardo: frena', [false, true],
    [$cambio['ok'], strpos(implode(' ', $cambio['errores']), 'cambió de pendiente') !== false]);

$ajeno = AlquileresImputacion::validar($borrador(3000, 1000, [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3000],
    ['conceptos' => ['OTROS', '8'], 'locales' => [3], 'mes' => '2026-11', 'importe' => 1000]]), $e);
chequear('Otros va solo en su renglon', false, $ajeno['ok']);

$soloA = ['pendientes' => [AlquileresImputacion::claveCuota($fa) => $fa]] + $e;
$fuera = AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'C1',
    'vto_tango' => '2026-11-20', 'pendiente' => 3000, 'retencion' => 0]], 'renglones' => [
    ['conceptos' => ['8'], 'locales' => [3], 'mes' => '2026-11', 'importe' => 3000]]], $soloA);
chequear('un local de otra razon social no se puede imputar', false, $fuera['ok']);

$re = AlquileresImputacion::validar($borrador(3000, 1000, [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 4000]]),
    ['imputaciones' => [$imp]] + $e);
chequear('reimputar: reemplaza la imputacion anterior', [5], $re['reemplaza']);

$retMal = AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'C1',
    'vto_tango' => '2026-11-20', 'pendiente' => 3000, 'retencion' => 3001]], 'renglones' => [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3000]]], $soloA);
chequear('la retencion no puede pasar el pendiente', false, $retMal['ok']);

$sinRet = AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'C1',
    'vto_tango' => '2026-11-20', 'pendiente' => 3000, 'retencion' => null]], 'renglones' => [
    ['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3000]]], $soloA, true);
chequear('al guardar, la retencion es obligatoria (0 es un valor)', [false, true],
    [$sinRet['ok'], strpos(implode(' ', $sinRet['errores']), 'Falta la retención') !== false]);
chequear('en la previsualizacion no: se sugiere', true, AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA',
    't_comp' => 'FAC', 'n_comp' => 'C1', 'vto_tango' => '2026-11-20', 'pendiente' => 3000, 'retencion' => null]],
    'renglones' => [['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3000]]], $soloA)['ok']);

seccion('Dar de baja una razon social con imputaciones de facturas pendientes');

$impBaja = imputacionP(10, [$fa], [['conceptos' => ['1'], 'locales' => [1], 'mes' => '2026-11', 'importe' => 3000.0]]);
$impBaja['usuario'] = 'ANA';
$bl = AlquileresImputacion::bloqueanBaja('SAPALA', [$impBaja], [AlquileresImputacion::claveCuota($fa) => true]);
chequear('la factura sigue pendiente: bloquea', 1, count($bl));
$msg = AlquileresImputacion::mensajeBajaBloqueada('SAPALA', $bl);
chequear('el mensaje lista factura, mes que cubre y quien la cargo', [true, true, true],
    [strpos($msg, 'FAC C1') !== false, strpos($msg, '2026-11') !== false, strpos($msg, 'ANA') !== false]);
chequear('ya pagada (no esta en pendientes): no bloquea', [], AlquileresImputacion::bloqueanBaja('SAPALA', [$impBaja], []));
chequear('de otra razon social: no bloquea', [],
    AlquileresImputacion::bloqueanBaja('SAUNIC', [$impBaja], [AlquileresImputacion::claveCuota($fa) => true]));

seccion('El cableado: permisos, endpoints y pantalla');

require_once __DIR__ . '/../Class/AuthCashflow.php';
$ctrl = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
$jsF = file_get_contents(__DIR__ . '/../Js/Alquileres-Facturas.js');
$tabA = file_get_contents(__DIR__ . '/../Tabs/alquileres.php');

foreach (['editarVtoFactura', 'deshacerVtoFactura', 'previsualizarImputacion', 'guardarImputacion',
          'deshacerImputacion'] as $accion) {
    chequear($accion . ' pide cashflow.editar.alquileres, el controller la atiende y el JS la pide',
        [[['alquileres', null]], 1, true],
        [AuthCashflow::ESCRITURAS['Alquileres'][$accion] ?? null, substr_count($ctrl, "case '" . $accion . "':"),
         strpos($jsF, $accion) !== false]);
}

chequear('guardar revalida contra los pendientes de ahora, con la retencion obligatoria', true,
    strpos($ctrl, 'AlquileresImputacion::validar($data, $e, true)') !== false);
chequear('la baja de una razon social pasa por el bloqueo', true,
    strpos($ctrl, 'AlquileresImputacion::bloqueanBaja(') !== false);
chequear('las pagadas se marcan al cargar, con el usuario del sistema', [true, true],
    [strpos($ctrl, 'marcarPagadas($c[\'pagadas_nuevas\'])') !== false,
     strpos(file_get_contents(__DIR__ . '/../Class/AlquileresImputaciones.php'), 'self::usuarioSistema()') !== false]);
chequear('la sub-pestaña Facturas ya no es un placeholder', [false, true, true],
    [strpos($tabA, 'Disponible en la próxima entrega') !== false, strpos($tabA, 'id="tablaAlqFact"') !== false,
     strpos($tabA, 'id="modalAlqImputar"') !== false]);
chequear('la grilla se exporta y tiene el switch Ver pagadas', [true, true],
    [strpos($tabA, 'data-exportar="tablaAlqFact"') !== false, strpos($tabA, 'id="alqFactVerPagadas"') !== false]);
chequear('sin permiso de edicion no se dibujan tildes, vencimiento ni Imputar', true,
    strpos($jsF, "return Permisos.puedeEditar('alqFactBody') && datos && datos.tablas;") !== false);
chequear('Guardar exige la misma previsualizacion: cualquier cambio la invalida', true,
    strpos($jsF, "previsto !== JSON.stringify(b)") !== false);

seccion('La lista de conceptos y el CHECK dicen lo mismo');

preg_match("/CONCEPTO IN \(([^)]*)\)/", file_get_contents(__DIR__ . '/../sql/cashflow_alquileres_facturas.sql'), $m);
preg_match_all("/'([A-Z0-9]+)'/", $m[1], $v);
chequear('CONCEPTOS = el CHECK de la tabla', AlquileresImputacion::CONCEPTOS, $v[1]);
chequear('son los que se proyectan, mas extraordinario y otros', array_merge(
    array_map('strval', AlquileresConceptos::proyectados()), ['EXTRA', 'OTROS']), AlquileresImputacion::CONCEPTOS);

seccion('El reparto a centavos');

chequear('cierra exacto con resto mayor', 100.0, array_sum(AlquileresImputacion::repartirCentavos(100.0, [1, 1, 1])));
chequear('con pesos en cero, partes iguales', [5.0, 5.0], array_values(AlquileresImputacion::repartirCentavos(10.0, [0, 0])));
