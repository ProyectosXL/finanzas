<?php
/**
 * La regla de los prestamos (Class/PrestamosCalculo.php): el cronograma, los
 * costos, la tasa variable, las correcciones y las cancelaciones.
 *
 * TODO CON DATOS ESCRITOS A MANO Y HOY FIJO (25/11/2026): la regla es pura.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. EL INTERES CON TNA / 12 en vez de dias exactos / 365.
 *   2. LA ULTIMA CUOTA NO CIERRA EL CAPITAL y queda un saldo de centavos (o
 *      de miles, con una correccion) que nadie ve.
 *   3. EL CORRIMIENTO AL HABIL CAMBIA LOS DIAS DE INTERES.
 *   4. LAS FRECUENCIAS DESACOPLADAS pagan el interes con el saldo equivocado.
 *   5. UNA CANCELACION COBRA INTERESES FUTUROS o se olvida la comision.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/PrestamosCalculo.php';

const PC_HOY = '2026-11-25';

function pcPrestamo($extra = []) {
    return $extra + [
        'id' => 1, 'nombre' => 'Prueba', 'moneda' => 'ARS', 'capital' => 1200000.0,
        'fecha_desembolso' => '2027-01-15', 'tipo_tasa' => 'FIJA', 'tna' => 36.0,
        'sistema' => 'ALEMAN', 'cuotas' => 12, 'frec_capital' => 'MENSUAL', 'frec_intereses' => 'MENSUAL',
        'gracia' => 0, 'estado' => 'VIGENTE',
        'cancelacion' => ['modo' => 'FIJA', 'pct_total' => 3.0, 'pct_parcial' => 3.0, 'iva' => true, 'tramos' => []]
    ];
}

function pcEntrada($p = [], $extra = []) {
    return $extra + ['hoy' => PC_HOY, 'prestamo' => pcPrestamo($p), 'costos' => [], 'iva' => 0.21,
                     'ajustes' => [], 'cancelaciones' => [], 'tasas' => [], 'habiles' => [], 'curva' => []];
}

function pcCuotas($r) {
    return array_values(array_filter($r['cuotas'], function ($f) { return $f['tipo'] === PrestamosCalculo::R_CUOTA; }));
}

function pcSuma($filas, $campo) {
    return round(array_sum(array_column($filas, $campo)), 2);
}

/* ====================================================================== */
seccion('las formulas de referencia');
chequear('TNA 29,69 %, 30 dias, $ 10.000.000 -> $ 244.027,40', 244027.40,
    round(PrestamosCalculo::interes(10000000, 0.2969, 30), 2));
chequear('es el 2,44 % del periodo', '2,44', number_format(PrestamosCalculo::interes(10000000, 0.2969, 30) / 100000, 2, ',', ''));
chequear('TEA de TNA 29,69 % con d = 30: 34,09 %', 0.3409, round(PrestamosCalculo::tea(0.2969, 30), 4));
chequear('dias exactos, no 30 por mes: enero tiene 31', 31, PrestamosCalculo::dias('2027-01-15', '2027-02-15'));
chequear('sumar meses al fin de mes: 31/01 + 1 = 28/02', '2027-02-28', PrestamosCalculo::sumarMeses('2027-01-31', 1));
chequear('y vuelve al 31 si el ancla es 31', '2027-03-31', PrestamosCalculo::sumarMeses('2027-02-28', 1, 31));

/* ====================================================================== */
seccion('aleman, mensual, sin gracia');
$r = PrestamosCalculo::proyectar(pcEntrada());
$c = pcCuotas($r);
chequear('12 cuotas', 12, count($c));
chequear('capital fijo de 100.000', 100000.0, $c[0]['capital']);
chequear('primer vencimiento: desembolso + 1 mes', '2027-02-15', $c[0]['vencimiento']);
chequear('interes de la 1: 1.200.000 x 36 % x 31 / 365', round(1200000 * 0.36 * 31 / 365, 2), $c[0]['interes']);
chequear('interes de la 2 sobre el saldo de 1.100.000 y 28 dias', round(1100000 * 0.36 * 28 / 365, 2), $c[1]['interes']);
chequear('el capital cierra exacto', 1200000.0, pcSuma($c, 'capital'));
chequear('la ultima deja saldo cero', 0.0, round($c[11]['saldo_inicial'] - $c[11]['capital'], 2));
chequear('futuro: todo proyectado', false, $c[0]['real']);

seccion('aleman con capital que no divide exacto: la ultima ajusta el redondeo');
$r = PrestamosCalculo::proyectar(pcEntrada(['capital' => 1000000.0, 'cuotas' => 7]));
$c = pcCuotas($r);
chequear('las primeras: 142.857,14', 142857.14, $c[0]['capital']);
chequear('la ultima: 142.857,16', 142857.16, $c[6]['capital']);
chequear('suma 1.000.000', 1000000.0, pcSuma($c, 'capital'));

seccion('aleman con gracia: 24 cuotas, 6 de gracia');
$r = PrestamosCalculo::proyectar(pcEntrada(['capital' => 1800000.0, 'cuotas' => 24, 'gracia' => 6]));
$c = pcCuotas($r);
chequear('24 cuotas en total', 24, count($c));
chequear('las 6 primeras sin capital', [0.0, 0.0, 0.0, 0.0, 0.0, 0.0], array_column(array_slice($c, 0, 6), 'capital'));
chequear('pero con interes sobre el capital total', round(1800000 * 0.36 * 31 / 365, 2), $c[0]['interes']);
chequear('desde la 7, capital / 18 = 100.000', 100000.0, $c[6]['capital']);
chequear('cierra', 1800000.0, pcSuma($c, 'capital'));

/* ====================================================================== */
seccion('frances, mensual');
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES']));
$c = pcCuotas($r);
$cuota = round(PrestamosCalculo::cuotaFrancesa(1200000, 0.36 * 30 / 365, 12), 2);
chequear('la cuota es constante (capital + interes), cuota 1', $cuota, round($c[0]['capital'] + $c[0]['interes'], 2));
chequear('cuota 6', $cuota, round($c[5]['capital'] + $c[5]['interes'], 2));
chequear('el interes va por dias exactos (31 en la 1)', round(1200000 * 0.36 * 31 / 365, 2), $c[0]['interes']);
chequear('el capital crece', true, $c[1]['capital'] > $c[0]['capital']);
chequear('la ultima cierra el capital exacto', 1200000.0, pcSuma($c, 'capital'));

seccion('frances con gracia');
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES', 'cuotas' => 24, 'gracia' => 6]));
$c = pcCuotas($r);
chequear('gracia: sin capital', 0.0, $c[5]['capital']);
$cuota = round(PrestamosCalculo::cuotaFrancesa(1200000, 0.36 * 30 / 365, 18), 2);
chequear('desde la 7, la cuota de 18 periodos', $cuota, round($c[6]['capital'] + $c[6]['interes'], 2));
chequear('cierra', 1200000.0, pcSuma($c, 'capital'));

seccion('frances trimestral: la tasa nominal del periodo es de 90 dias');
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES', 'cuotas' => 4, 'frec_capital' => 'TRIMESTRAL', 'frec_intereses' => 'TRIMESTRAL']));
$c = pcCuotas($r);
chequear('4 cuotas', 4, count($c));
chequear('cada 3 meses', '2027-07-15', $c[1]['vencimiento']);
chequear('cuota de 90 dias nominales', round(PrestamosCalculo::cuotaFrancesa(1200000, 0.36 * 90 / 365, 4), 2),
    round($c[0]['capital'] + $c[0]['interes'], 2));

seccion('frances solo con frecuencias sincronizadas');
chequearLanza('frances con capital trimestral e intereses mensuales se rechaza', function () {
    PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES', 'cuotas' => 4, 'frec_capital' => 'TRIMESTRAL']));
});

/* ====================================================================== */
seccion('bullet: 1 cuota anual con intereses mensuales = 12 meses');
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'BULLET', 'cuotas' => 1, 'frec_capital' => 'ANUAL', 'frec_intereses' => 'MENSUAL']));
$c = pcCuotas($r);
chequear('12 vencimientos', 12, count($c));
chequear('11 sin capital', 0.0, pcSuma(array_slice($c, 0, 11), 'capital'));
chequear('todo el capital en el ultimo', 1200000.0, $c[11]['capital']);
chequear('el ultimo vence a los 12 meses', '2028-01-15', $c[11]['vencimiento']);
chequear('intereses todos los meses sobre el total', round(1200000 * 0.36 * 28 / 365, 2), $c[1]['interes']);

/* ====================================================================== */
seccion('desacopladas: capital trimestral, intereses mensuales (aleman)');
$r = PrestamosCalculo::proyectar(pcEntrada(['cuotas' => 4, 'frec_capital' => 'TRIMESTRAL', 'frec_intereses' => 'MENSUAL']));
$c = pcCuotas($r);
chequear('12 vencimientos (de a la frecuencia mas corta)', 12, count($c));
chequear('capital solo en los meses 3, 6, 9 y 12', [0.0, 0.0, 300000.0, 0.0, 0.0, 300000.0],
    array_column(array_slice($c, 0, 6), 'capital'));
chequear('el interes del mes 4 es sobre 900.000', round(900000 * 0.36 * PrestamosCalculo::dias($c[2]['vencimiento'], $c[3]['vencimiento']) / 365, 2), $c[3]['interes']);

seccion('desacopladas: capital mensual, intereses trimestrales');
$r = PrestamosCalculo::proyectar(pcEntrada(['frec_intereses' => 'TRIMESTRAL']));
$c = pcCuotas($r);
chequear('interes cero en los meses que no son de intereses', [0.0, 0.0], [$c[0]['interes'], $c[1]['interes']]);
$esperado = round(1200000 * 0.36 * 31 / 365 + 1100000 * 0.36 * 28 / 365 + 1000000 * 0.36 * 31 / 365, 2);
chequear('el del mes 3 junta los tres tramos con su saldo', $esperado, $c[2]['interes']);
chequearLanza('plazo no multiplo de la frecuencia de intereses: se rechaza', function () {
    PrestamosCalculo::proyectar(pcEntrada(['cuotas' => 10, 'frec_intereses' => 'TRIMESTRAL']));
});

/* ====================================================================== */
seccion('costos iniciales y por cuota');
$costos = [
    ['id' => 1, 'nombre' => 'Sellos', 'pct' => 1.2, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false],
    ['id' => 2, 'nombre' => 'Aval SGR', 'pct' => 2.0, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => true],
    ['id' => 3, 'nombre' => 'IVA s/ intereses', 'pct' => 10.5, 'base' => 'INTERESES', 'momento' => 'POR_CUOTA', 'iva' => false],
    ['id' => 4, 'nombre' => 'Seguro s/ saldo', 'pct' => 0.1, 'base' => 'CAPITAL', 'momento' => 'POR_CUOTA', 'iva' => false]
];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['costos' => $costos]));
$d = $r['cuotas'][0];
chequear('el desembolso va bruto', 1200000.0, $d['desembolso']);
chequear('sellos 1,2 % del capital', 14400.0, $d['costos'][1]);
chequear('aval 2 % + IVA 21 %', 29040.0, $d['costos'][2]);
chequear('el dia del desembolso', '2027-01-15', $d['fecha_pago']);
$c = pcCuotas($r);
chequear('IVA 10,5 % sobre el interes de la cuota', round($c[0]['interes'] * 0.105, 2), $c[0]['costos'][3]);
chequear('seguro 0,1 % del saldo al inicio del periodo (cuota 2: 1.100.000)', 1100.0, $c[1]['costos'][4]);
chequear('el total de la cuota los suma', round($c[0]['capital'] + $c[0]['interes'] + $c[0]['costos'][3] + $c[0]['costos'][4], 2), $c[0]['total']);
$pagosDes = array_values(array_filter($r['pagos'], function ($p) { return $p['fecha'] === '2027-01-15'; }));
chequear('en los pagos: desembolso bruto y costos aparte en intereses y gastos', [
    ['DESEMBOLSO', 1200000.0], ['INTERESES', 43440.0]], array_map(function ($p) { return [$p['concepto'], $p['importe']]; }, $pagosDes));

/* ====================================================================== */
seccion('corrimiento al habil sin cambiar los dias de interes');
$r = PrestamosCalculo::proyectar(pcEntrada(['fecha_desembolso' => '2027-04-15', 'fecha_primera' => '2027-05-15']));
$c = pcCuotas($r);
chequear('el 15/05/2027 es sabado: se paga el lunes 17', '2027-05-17', $c[0]['fecha_pago']);
chequear('marcado como corrido', true, $c[0]['corrida']);
chequear('los dias de interes siguen siendo 30', 30, $c[0]['dias']);
chequear('el interes, con 30 dias', round(1200000 * 0.36 * 30 / 365, 2), $c[0]['interes']);
chequear('la cuota siguiente cuenta desde el 15/05, no desde el 17', 31, $c[1]['dias']);
$r = PrestamosCalculo::proyectar(pcEntrada(['fecha_desembolso' => '2027-04-15', 'fecha_primera' => '2027-05-14'], ['habiles' => ['2027-05-14' => false]]));
chequear('un feriado del calendario tambien corre', '2027-05-17', pcCuotas($r)[0]['fecha_pago']);

/* ====================================================================== */
seccion('real y proyectado');
$r = PrestamosCalculo::proyectar(pcEntrada(['fecha_desembolso' => '2026-09-25']));
$c = pcCuotas($r);
chequear('el desembolso pasado es real', true, $r['cuotas'][0]['real']);
chequear('la cuota del 25/10 es real', true, $c[0]['real']);
chequear('la del 25/11 (hoy) es real', true, $c[1]['real']);
chequear('la del 25/12 es proyectada', false, $c[2]['real']);
chequear('saldo a hoy: 1.200.000 - 2 cuotas', 1000000.0, $r['resumen']['saldo_hoy']);
chequear('proxima cuota: la del 25/12', '2026-12-25', $r['resumen']['proxima']['fecha']);

/* ====================================================================== */
seccion('correcciones por cuota');
$aj = [3 => ['CAPITAL' => ['importe' => 150000, 'usuario' => 'silvia', 'fecha' => '2026-11-25'],
             'INTERES' => ['importe' => 30000, 'usuario' => 'silvia', 'fecha' => '2026-11-25']]];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['ajustes' => $aj]));
$c = pcCuotas($r);
chequear('el capital corregido pisa el calculado', 150000.0, $c[2]['capital']);
chequear('el interes corregido tambien', 30000.0, $c[2]['interes']);
chequear('queda marcado con usuario', 'silvia', $c[2]['corregido']['CAPITAL']['usuario']);
chequear('la cuota 4 sigue con su interes sobre el saldo que quedo (850.000)',
    round(850000 * 0.36 * PrestamosCalculo::dias($c[2]['vencimiento'], $c[3]['vencimiento']) / 365, 2), $c[3]['interes']);
chequear('la ultima absorbe y el capital cierra', 1200000.0, pcSuma($c, 'capital'));
chequear('sin aviso: cierra', 0, count($r['avisos']));
$aj[12] = ['CAPITAL' => ['importe' => 100000, 'usuario' => 'dan', 'fecha' => '2026-11-25']];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['ajustes' => $aj]));
chequear('con la ultima tambien corregida no cierra: avisa', true,
    count($r['avisos']) === 1 && strpos($r['avisos'][0]['texto'], 'no cierr') === false && strpos($r['avisos'][0]['texto'], 'suma') !== false);
$r = PrestamosCalculo::proyectar(pcEntrada(['estado' => 'SIMULADO'], ['ajustes' => $aj]));
chequear('en un simulado, no', 0, count($r['avisos']));
$r = PrestamosCalculo::proyectar(pcEntrada([], ['ajustes' => [2 => ['TNA' => ['importe' => 48.0, 'usuario' => 'dan', 'fecha' => '2026-11-25']]]]));
$c = pcCuotas($r);
chequear('una TNA corregida: el interes de esa cuota con 48 %', round(1100000 * 0.48 * 28 / 365, 2), $c[1]['interes']);
chequear('origen corregida', PrestamosTasas::O_CORREGIDA, $c[1]['origen_tasa']);
chequear('la siguiente vuelve a la fija', PrestamosTasas::O_FIJA, $c[2]['origen_tasa']);
$r = PrestamosCalculo::proyectar(pcEntrada([], ['costos' => $costos, 'ajustes' => [1 => ['COSTO:3' => ['importe' => 999.99, 'usuario' => 'dan', 'fecha' => '2026-11-25']]]]));
chequear('un costo corregido', 999.99, pcCuotas($r)[0]['costos'][3]);

/* ====================================================================== */
seccion('cancelacion total');
$cancel = [['id' => 9, 'fecha' => '2027-04-01', 'tipo' => 'TOTAL', 'monto' => null, 'modo' => null]];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['cancelaciones' => $cancel, 'costos' => $costos]));
$c = pcCuotas($r);
$k = end($r['cuotas']);
chequear('las cuotas posteriores no van: quedan 2 (15/02 y 15/03)', 2, count($c));
chequear('el ultimo renglon es la cancelacion', 'CANCELACION', $k['tipo']);
chequear('paga el saldo (1.000.000)', 1000000.0, $k['capital']);
chequear('intereses devengados hasta ese dia, 17 dias exactos', round(1000000 * 0.36 * 17 / 365, 2), $k['interes']);
chequear('comision 3 % del saldo', 30000.0, $k['cancelacion']['comision']);
chequear('mas IVA', 6300.0, $k['cancelacion']['iva_comision']);
chequear('el IVA s/ intereses sobre lo devengado', round($k['interes'] * 0.105, 2), $k['costos'][3]);
chequear('el seguro sobre saldo no va en la cancelacion', false, isset($k['costos'][4]));
chequear('el total', round(1000000 + $k['interes'] + 30000 + 6300 + $k['costos'][3], 2), $k['total']);
chequear('el capital cierra con la cancelacion', 1200000.0, pcSuma($r['cuotas'], 'capital'));
chequear('futura: el prestamo sigue vigente', 'VIGENTE', $r['resumen']['estado']);
$r = PrestamosCalculo::proyectar(pcEntrada(['fecha_desembolso' => '2026-06-15'], ['cancelaciones' => [['id' => 9, 'fecha' => '2026-10-01', 'tipo' => 'TOTAL']]]));
chequear('ya ocurrida: cancelado', 'CANCELADO', $r['resumen']['estado']);
$r = PrestamosCalculo::proyectar(pcEntrada());
chequear('deshacer (sin la cancelacion) vuelven las 12', 12, count(pcCuotas($r)));

seccion('cancelacion parcial: reducir la cuota (aleman)');
$cancel = [['id' => 9, 'fecha' => '2027-04-01', 'tipo' => 'PARCIAL', 'monto' => 300000, 'modo' => 'REDUCIR_CUOTA']];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['cancelaciones' => $cancel]));
$c = pcCuotas($r);
$k = array_values(array_filter($r['cuotas'], function ($f) { return $f['tipo'] === 'CANCELACION'; }))[0];
chequear('mismo plazo: siguen las 12', 12, count($c));
chequear('adelanta 300.000', 300000.0, $k['capital']);
chequear('devengado hasta el 01/04', round(1000000 * 0.36 * 17 / 365, 2), $k['interes']);
chequear('comision 3 % de lo adelantado', 9000.0, $k['cancelacion']['comision']);
chequear('la cuota 3 cuenta el interes desde el 01/04 sobre 700.000', round(700000 * 0.36 * 14 / 365, 2), $c[2]['interes']);
chequear('la cuota baja: 700.000 / 10', 70000.0, $c[2]['capital']);
chequear('cierra', 1200000.0, pcSuma($r['cuotas'], 'capital'));

seccion('cancelacion parcial: reducir el plazo (aleman)');
$cancel[0]['modo'] = 'REDUCIR_PLAZO';
$r = PrestamosCalculo::proyectar(pcEntrada([], ['cancelaciones' => $cancel]));
$c = pcCuotas($r);
chequear('misma cuota de 100.000', 100000.0, $c[2]['capital']);
chequear('termina 3 cuotas antes: 9', 9, count($c));
chequear('cierra', 1200000.0, pcSuma($r['cuotas'], 'capital'));

seccion('cancelacion parcial en frances');
$cancel = [['id' => 9, 'fecha' => '2027-04-01', 'tipo' => 'PARCIAL', 'monto' => 300000, 'modo' => 'REDUCIR_CUOTA']];
$sin = pcCuotas(PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES'])));
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES'], ['cancelaciones' => $cancel]));
$c = pcCuotas($r);
chequear('reducir cuota: mismo plazo', 12, count($c));
chequear('y la cuota baja', true, ($c[4]['capital'] + $c[4]['interes']) < ($sin[4]['capital'] + $sin[4]['interes']));
chequear('cierra', 1200000.0, pcSuma($r['cuotas'], 'capital'));
$cancel[0]['modo'] = 'REDUCIR_PLAZO';
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES'], ['cancelaciones' => $cancel]));
$c = pcCuotas($r);
chequear('reducir plazo: menos cuotas', true, count($c) < 12);
chequear('con la misma cuota', round($sin[4]['capital'] + $sin[4]['interes'], 2), round($c[4]['capital'] + $c[4]['interes'], 2));
chequear('cierra', 1200000.0, pcSuma($r['cuotas'], 'capital'));

seccion('correcciones despues de una parcial: no se aplican y se avisa');
$aj = [6 => ['CAPITAL' => ['importe' => 1, 'usuario' => 'dan', 'fecha' => '2026-11-25']],
       1 => ['INTERES' => ['importe' => 5, 'usuario' => 'dan', 'fecha' => '2026-11-25']]];
$r = PrestamosCalculo::proyectar(pcEntrada([], ['cancelaciones' => $cancel, 'ajustes' => $aj]));
$c = pcCuotas($r);
chequear('la de la cuota 1 (anterior) se aplica', 5.0, $c[0]['interes']);
chequear('la de la cuota 6 (posterior) no', true, $c[5]['capital'] > 1);
chequear('el aviso la nombra', true, strpos($r['avisos'][0]['texto'], 'cuotas 6') !== false);

seccion('comision fija y por tramos');
$com = ['modo' => 'ESCALONADA', 'iva' => false, 'tramos' => [
    ['desde' => 0, 'pct_total' => 4, 'pct_parcial' => 3], ['desde' => 50, 'pct_total' => 2, 'pct_parcial' => 1.5],
    ['desde' => 75, 'pct_total' => 0, 'pct_parcial' => 0]]];
chequear('al 10 % del plazo: 4 %', 4.0, PrestamosCalculo::pctComision($com, 'TOTAL', 10));
chequear('al 50 % justo: 2 %', 2.0, PrestamosCalculo::pctComision($com, 'TOTAL', 50));
chequear('parcial al 60 %: 1,5 %', 1.5, PrestamosCalculo::pctComision($com, 'PARCIAL', 60));
chequear('al 80 %: 0', 0.0, PrestamosCalculo::pctComision($com, 'TOTAL', 80));
chequear('fija: la total', 3.0, PrestamosCalculo::pctComision(['modo' => 'FIJA', 'pct_total' => 3, 'pct_parcial' => 2], 'TOTAL', 80));
$r = PrestamosCalculo::proyectar(pcEntrada(['cancelacion' => $com], ['cancelaciones' => [['id' => 9, 'fecha' => '2027-04-01', 'tipo' => 'TOTAL']]]));
$k = end($r['cuotas']);
chequear('en el cronograma: el 01/04/2027 es ~21 % del plazo -> 4 %, sin IVA', [4.0, 40000.0, 0.0],
    [$k['cancelacion']['pct_comision'], $k['cancelacion']['comision'], $k['cancelacion']['iva_comision']]);

/* ====================================================================== */
seccion('tasa variable');
$serie = [];
foreach (['2026-11-16' => 24.0, '2026-11-17' => 25.0, '2026-11-18' => 26.0, '2026-11-19' => 27.0, '2026-11-20' => 28.0] as $f => $v) {
    $serie[$f] = $v;
}
$tasas = ['disponible' => true, 'n' => 5, 'series' => ['TAMAR' => $serie], 'manual' => []];
$pv = ['tipo_tasa' => 'VARIABLE', 'tna' => null, 'referencia' => 'TAMAR', 'spread' => 5.0];
$r = PrestamosCalculo::proyectar(pcEntrada($pv, ['tasas' => $tasas]));
$c = pcCuotas($r);
chequear('futuro: promedio 26 + spread 5 = 31', 31.0, $c[0]['tna']);
chequear('el interes con 31 %', round(1200000 * 0.31 * 31 / 365, 2), $c[0]['interes']);
chequear('origen promedio', 'PROMEDIO', $c[0]['origen_tasa']);
$tasas['manual'] = ['TAMAR' => ['2027-03' => 40.0]];
$r = PrestamosCalculo::proyectar(pcEntrada($pv, ['tasas' => $tasas]));
$c = pcCuotas($r);
chequear('el manual del mes de inicio del periodo (marzo -> cuota de abril)', [31.0, 31.0, 45.0, 31.0],
    array_column(array_slice($c, 0, 4), 'tna'));
$r = PrestamosCalculo::proyectar(pcEntrada($pv + ['sistema' => 'FRANCES'], ['tasas' => $tasas]));
$c = pcCuotas($r);
chequear('frances variable: la cuota se recalcula cuando cambia la tasa', true,
    abs(($c[2]['capital'] + $c[2]['interes']) - ($c[1]['capital'] + $c[1]['interes'])) > 1);
chequear('y cierra', 1200000.0, pcSuma($c, 'capital'));
$r = PrestamosCalculo::proyectar(pcEntrada($pv, ['tasas' => ['disponible' => false]]));
chequear('sin tasas: no se proyecta', [false, 0], [$r['proyectable'], count($r['pagos'])]);
chequear('y avisa en critico', Aviso::DANGER, $r['avisos'][0]['nivel']);

/* ====================================================================== */
seccion('en dolares');
$curva = ['2027-02' => ['clave' => '2027-02', 'simbolo' => 'DLR022027', 'cotizacion' => 1600.0],
          '2027-03' => ['clave' => '2027-03', 'simbolo' => 'DLR032027', 'cotizacion' => 1650.0]];
$r = PrestamosCalculo::proyectar(pcEntrada(['moneda' => 'USD', 'capital' => 12000.0, 'cuotas' => 2], ['curva' => $curva]));
$cap = array_values(array_filter($r['pagos'], function ($p) { return $p['concepto'] === 'CAPITAL'; }));
chequear('cada pago con el dolar futuro de su mes', [6000.0 * 1600, 6000.0 * 1650], array_column($cap, 'importe'));
chequear('el desembolso tambien se convierte (aproximado: la curva no llega a enero)', [true, 12000.0 * 1600],
    [$r['pagos'][0]['tc_aproximado'], $r['pagos'][0]['importe']]);
$r = PrestamosCalculo::proyectar(pcEntrada(['moneda' => 'USD', 'capital' => 12000.0, 'cuotas' => 2], ['curva' => []]));
chequear('sin curva: importe null y aviso', [null, true], [$r['pagos'][1]['importe'], strpos($r['avisos'][0]['texto'], 'no hay dólar futuro') !== false]);

/* ====================================================================== */
seccion('estados calculados');
$r = PrestamosCalculo::proyectar(pcEntrada(['fecha_desembolso' => '2025-01-15']));
chequear('vencio la ultima cuota: finalizado', 'FINALIZADO', $r['resumen']['estado']);
$r = PrestamosCalculo::proyectar(pcEntrada(['estado' => 'SIMULADO']));
chequear('simulado', 'SIMULADO', $r['resumen']['estado']);
chequear('los pagos de un simulado van marcados', true, $r['pagos'][0]['simulado']);

seccion('TEA y CFT');
$r = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES']));
chequear('TEA de 36 % mensual', round(PrestamosCalculo::tea(0.36, 30), 6), round($r['resumen']['tea'], 6));
chequear('sin costos, el CFT anda cerca de la TEA', true, abs($r['resumen']['cft'] - $r['resumen']['tea']) < 0.01);
$r2 = PrestamosCalculo::proyectar(pcEntrada(['sistema' => 'FRANCES'], ['costos' => $costos]));
chequear('con costos, el CFT es mayor', true, $r2['resumen']['cft'] > $r['resumen']['cft'] + 0.05);
