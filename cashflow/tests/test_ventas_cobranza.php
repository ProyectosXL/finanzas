<?php
/**
 * El motor de cobranza de Ventas recorriendo las hojas del arbol del mix
 * (Ventas::cobranzaPorHojas()): bruto, costo y neto por hoja, con el
 * corrimiento a dia habil, y los avisos de un mix invalido.
 *
 * Sin base: la venta diaria, el eje y el calendario se arman a mano. El
 * calendario entra como la funcion de corrimiento, la misma mecanica que usa
 * proximoHabil() (DiasHabiles::siguiente()).
 */

require_once __DIR__ . '/../Class/Ventas.php';

/** Una fila cruda de RO_T_CASHFLOW_VENTAS_MIX_NODO */
function nodoVC($id, $canal, $padre, $nivel, $nombre, $porc, $costo = null, $tasa = null,
                $dias = null, $activo = 1) {
    return ['ID' => $id, 'CANAL' => $canal, 'ID_PADRE' => $padre, 'NIVEL' => $nivel,
            'NOMBRE' => $nombre, 'PORCENTAJE' => $porc, 'COSTO' => $costo, 'TASA' => $tasa,
            'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo, 'ORDEN' => $id];
}

/** El arbol de la especificacion, con costos */
function arbolVC($cambios = []) {
    $filas = [
        nodoVC(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 0.10, 0.031, null, 1),
        nodoVC(2, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 0.90),
        nodoVC(3, 'LOCALES', 2, 'TIPO_TARJETA', 'Débito', 0.20, 0.0318, null, 7),
        nodoVC(4, 'LOCALES', 2, 'TIPO_TARJETA', 'Crédito', 0.80),
        nodoVC(5, 'LOCALES', 4, 'PROCESADORA', 'Payway', 0.10, 0.049, null, 1),
        nodoVC(6, 'LOCALES', 4, 'PROCESADORA', 'Mercado Pago', 0.10, null, null, 18),
        nodoVC(7, 'LOCALES', 4, 'PROCESADORA', 'Fiserv', 0.60, 0.049, null, 7),
        nodoVC(8, 'LOCALES', 7, 'CUOTAS', '3 cuotas', 0.30, null, 0.009, 1),
        nodoVC(9, 'LOCALES', 7, 'CUOTAS', 'Resto', 0.70),
        nodoVC(10, 'LOCALES', 4, 'PROCESADORA', 'Promo Bancarias', 0.20, 0.049, null, 15),
        nodoVC(11, 'LOCALES', 2, 'TIPO_TARJETA', 'Prepaga', 0.0, null, null, 3),
        nodoVC(12, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Transferencia', 0.03, null, null, 30),
        nodoVC(13, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 0.97, null, null, 40),
        nodoVC(14, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 60),
        nodoVC(20, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 0.70),
        nodoVC(21, 'ECOMMERCE', 20, 'MEDIO_PAGO', 'Tarjeta', 1.0, null, null, 2),
        nodoVC(22, 'ECOMMERCE', null, 'MARKETPLACE', 'Mercado Libre', 0.30, 0.14),
        nodoVC(23, 'ECOMMERCE', 22, 'MEDIO_PAGO', 'Mercado Pago', 1.0, null, null, 18)
    ];

    foreach ($filas as $i => $f) {
        if (isset($cambios[$f['ID']])) {
            $filas[$i] = array_merge($f, $cambios[$f['ID']]);
        }
    }

    return MixCobro::normalizar($filas);
}

/* ---- El escenario ----------------------------------------------------------
   Tramo diario del lunes 05/10/2026 al domingo 18/10/2026, meses de octubre y
   noviembre. El lunes 12/10 es feriado bancario. Venta todos los dias de
   octubre, del 05 en adelante. */
$diasVC = [];
$cursor = new DateTime('2026-10-05');

for ($i = 0; $i < 14; $i++) {
    $diasVC[] = ['fecha' => $cursor->format('Y-m-d')];
    $cursor->modify('+1 day');
}

$mesesVC = [['clave' => '2026-10'], ['clave' => '2026-11']];
$diasSetVC = array_flip(array_column($diasVC, 'fecha'));

$habilesVC = [];
$cursor = new DateTime('2026-10-01');

while ($cursor->format('Y-m-d') <= '2027-03-31') {
    $habilesVC[$cursor->format('Y-m-d')] = intval($cursor->format('N')) <= 5;
    $cursor->modify('+1 day');
}

$habilesVC['2026-10-12'] = false;

$corrimientoVC = function ($fecha) use ($habilesVC) {
    return DiasHabiles::siguiente($fecha, $habilesVC)['fecha'];
};

$ventaVC = [];
$cursor = new DateTime('2026-10-05');

while ($cursor->format('Y-m-d') <= '2026-10-31') {
    $ventaVC[$cursor->format('Y-m-d')] = ['LOCALES' => 1000.0, 'FRANQUICIAS' => 500.0,
                                          'MAYORISTAS' => 200.0, 'ECOMMERCE' => 300.0];
    $cursor->modify('+1 day');
}

/** La cobranza del escenario con un arbol */
function cobranzaVC($nodos) {
    global $ventaVC, $diasVC, $mesesVC, $diasSetVC, $corrimientoVC;

    return Ventas::cobranzaPorHojas($ventaVC, MixCobro::resolver($nodos),
        $diasVC, $mesesVC, $diasSetVC, $corrimientoVC);
}

$c = cobranzaVC(arbolVC());

seccion('bruto, costo y neto por hoja, con el corrimiento a habil');

// 3 cuotas: 12,96% a 1 dia. Las ventas del viernes 09, sabado 10, domingo
// 11 y lunes 12 se acreditarian el 10, 11, 12 y 13; los tres primeros no son
// habiles, asi que las cuatro caen el martes 13.
chequear('3 cuotas: cuatro dias de venta caen el martes despues del feriado',
    4 * 1000 * 0.1296, $c['dias']['N8']['2026-10-13']);
chequear('el sabado, el domingo y el feriado no reciben nada', [0, 0, 0],
    [$c['dias']['N8']['2026-10-10'], $c['dias']['N8']['2026-10-11'], $c['dias']['N8']['2026-10-12']]);
chequear('el costo cae en la misma fecha que su bruto: 4,90% + 0,90%',
    4 * 1000 * 0.1296 * 0.058, $c['costo']['dias']['N8']['2026-10-13']);
chequear('y no en otra', 0, $c['costo']['dias']['N8']['2026-10-14'] - 1000 * 0.1296 * 0.058);
chequear('Resto hereda los 7 dias de Fiserv: la venta del lunes 05 se acredita el 12, feriado, y '
    . 'pasa al 13 junto con la del martes 06', 2 * 1000 * 0.3024, $c['dias']['N9']['2026-10-13']);
chequear('Mercado Pago (procesadora) sin costo: costo cero, neto igual al bruto',
    [0.0, $c['meses']['N6']['2026-10']], [$c['costo']['meses']['N6']['2026-10'], $c['neto']['meses']['N6']['2026-10']]);
chequear('Mercado Libre: la comision del marketplace la paga Mercado Pago',
    0.14, $c['costo']['meses']['N23']['2026-10'] / $c['meses']['N23']['2026-10']);

seccion('los nodos con hijos suman su rama');

$malFiserv = 0;
$malCanal = 0;

foreach (['dias', 'meses'] as $rama) {
    foreach ($c[$rama]['N7'] as $col => $v) {
        $malFiserv += abs($v - $c[$rama]['N8'][$col] - $c[$rama]['N9'][$col]);
    }

    foreach ($c['subtotal_' . $rama]['LOCALES'] as $col => $v) {
        $malCanal += abs($v - $c[$rama]['N1'][$col] - $c[$rama]['N2'][$col]);
    }
}

chequear('Fiserv = 3 cuotas + Resto en cada columna', 0.0, round($malFiserv, 6));
chequear('Locales = Efectivo + Tarjeta en cada columna', 0.0, round($malCanal, 6));
chequear('tambien el costo de una rama', $c['costo']['meses']['N8']['2026-10'] + $c['costo']['meses']['N9']['2026-10'],
    $c['costo']['meses']['N7']['2026-10']);

seccion('bruto - costo = neto en cada celda y en los totales');

$desvio = 0;

foreach (['dias', 'meses', 'subtotal_dias', 'subtotal_meses'] as $rama) {
    foreach ($c[$rama] as $clave => $cols) {
        foreach ($cols as $col => $v) {
            $desvio += abs($v - $c['costo'][$rama][$clave][$col] - $c['neto'][$rama][$clave][$col]);
        }
    }
}

foreach (['total_dias', 'total_meses'] as $rama) {
    foreach ($c[$rama] as $col => $v) {
        $desvio += abs($v - $c['costo'][$rama][$col] - $c['neto'][$rama][$col]);
    }
}

chequear('ninguna celda se desvia', 0.0, $desvio);
chequear('total del tramo', $c['total_tramo'] - $c['costo']['total_tramo'], $c['neto']['total_tramo']);
chequear('total del horizonte', $c['total_horizonte'] - $c['costo']['total_horizonte'],
    $c['neto']['total_horizonte']);
chequear('hay costo de verdad', true, $c['costo']['total_horizonte'] > 0);
chequear('Franquicias y Mayoristas sin costo', [0.0, 0.0],
    [(float) array_sum($c['costo']['subtotal_meses']['FRANQUICIAS']) + array_sum($c['costo']['subtotal_dias']['FRANQUICIAS']),
     (float) array_sum($c['costo']['subtotal_meses']['MAYORISTAS']) + array_sum($c['costo']['subtotal_dias']['MAYORISTAS'])]);

seccion('lo que cae fuera del horizonte se descarta');

// Mayoristas a 60 dias: la venta de octubre se acredita en diciembre, que no
// esta en el eje.
chequear('Mayoristas no tiene nada en el horizonte', 0,
    array_sum($c['subtotal_dias']['MAYORISTAS']) + array_sum($c['subtotal_meses']['MAYORISTAS']));

seccion('las filas: el arbol en juego, en orden de dibujo');

chequear('las de Locales, sin los nodos fuera de juego',
    ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7', 'N8', 'N9', 'N10', 'N11'],
    array_values(array_column(array_filter($c['arbol'], function ($f) {
        return $f['canal'] === 'LOCALES';
    }), 'clave')));
chequear('la hoja al 0% se muestra igual, en cero', [true, 0],
    [in_array('N11', array_column($c['filas'], 'clave'), true),
     array_sum($c['dias']['N11']) + array_sum($c['meses']['N11'])]);
chequear('cada hoja viaja con su camino, su % efectivo, su carga y sus dias',
    [['Tarjeta', 'Crédito', 'Fiserv', '3 cuotas'], 0.1296, 0.058, 1],
    (function ($f) {
        return [array_column($f['camino'], 'nombre'), round($f['porcentaje_efectivo'], 10), $f['carga'], $f['dias']];
    })(array_values(array_filter($c['arbol'], function ($f) { return $f['clave'] === 'N8'; }))[0]));

seccion('sin costos, el arbol migrado da exactamente la cobranza del mix plano');

// El mix plano de la base y el arbol en el que lo deja
// sql/cashflow_ventas_mix_nodo.sql, con los mismos IDs de origen.
$planoVC = MixCobro::desdeMixPlano([
    ['ID' => 1, 'CANAL' => 'LOCALES', 'MEDIO_PAGO' => 'CASH', 'PORCENTAJE' => 0.1, 'DIAS_ACREDITACION' => 1, 'ACTIVO' => 1, 'ORDEN' => 1],
    ['ID' => 2, 'CANAL' => 'LOCALES', 'MEDIO_PAGO' => 'TARJETA', 'PORCENTAJE' => 0.9, 'DIAS_ACREDITACION' => 2, 'ACTIVO' => 1, 'ORDEN' => 2],
    ['ID' => 3, 'CANAL' => 'LOCALES', 'MEDIO_PAGO' => 'GO CUOTAS', 'PORCENTAJE' => 0, 'DIAS_ACREDITACION' => 10, 'ACTIVO' => 0, 'ORDEN' => 3],
    ['ID' => 4, 'CANAL' => 'FRANQUICIAS', 'MEDIO_PAGO' => 'TRANSFERENCIA', 'PORCENTAJE' => 0.03, 'DIAS_ACREDITACION' => 30, 'ACTIVO' => 1, 'ORDEN' => 4],
    ['ID' => 5, 'CANAL' => 'FRANQUICIAS', 'MEDIO_PAGO' => 'ECHEQ', 'PORCENTAJE' => 0.97, 'DIAS_ACREDITACION' => 40, 'ACTIVO' => 1, 'ORDEN' => 5],
    ['ID' => 6, 'CANAL' => 'MAYORISTAS', 'MEDIO_PAGO' => 'CASH', 'PORCENTAJE' => 0, 'DIAS_ACREDITACION' => 1, 'ACTIVO' => 0, 'ORDEN' => 6],
    ['ID' => 7, 'CANAL' => 'MAYORISTAS', 'MEDIO_PAGO' => 'ECHEQ', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 60, 'ACTIVO' => 1, 'ORDEN' => 7],
    ['ID' => 8, 'CANAL' => 'ECOMMERCE', 'MEDIO_PAGO' => 'TARJETA', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 2, 'ACTIVO' => 1, 'ORDEN' => 8],
    ['ID' => 9, 'CANAL' => 'ECOMMERCE', 'MEDIO_PAGO' => 'GO CUOTAS', 'PORCENTAJE' => 0, 'DIAS_ACREDITACION' => 10, 'ACTIVO' => 0, 'ORDEN' => 9]
]);
$migradoVC = MixCobro::normalizar([
    nodoVC(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 0.1, null, null, 1),
    nodoVC(2, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 0.9, null, null, 2),
    nodoVC(3, 'LOCALES', null, 'MEDIO_PAGO', 'Go Cuotas', 0, null, null, 10, 0),
    nodoVC(4, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Transferencia', 0.03, null, null, 30),
    nodoVC(5, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 0.97, null, null, 40),
    nodoVC(6, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Efectivo', 0, null, null, 1, 0),
    nodoVC(7, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1, null, null, 60),
    nodoVC(10, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 1),
    nodoVC(11, 'ECOMMERCE', 10, 'MEDIO_PAGO', 'Tarjeta', 1, null, null, 2),
    nodoVC(12, 'ECOMMERCE', 10, 'MEDIO_PAGO', 'Go Cuotas', 0, null, null, 10, 0)
]);

chequear('el arbol migrado es valido', [], MixCobro::validarEstructura($migradoVC));

$cp = cobranzaVC($planoVC);
$cm = cobranzaVC($migradoVC);

// Comparacion ESTRICTA: arrays con ===, sin tolerancia. Mismo importe al
// ultimo bit en cada columna de cada canal.
chequear('subtotales diarios por canal, identicos', $cp['subtotal_dias'], $cm['subtotal_dias']);
chequear('subtotales mensuales por canal, identicos', $cp['subtotal_meses'], $cm['subtotal_meses']);
chequear('totales, identicos', [$cp['total_dias'], $cp['total_meses'], $cp['total_tramo'], $cp['total_horizonte']],
    [$cm['total_dias'], $cm['total_meses'], $cm['total_tramo'], $cm['total_horizonte']]);
chequear('la fila de costos da cero', [0.0, 0.0], [$cm['costo']['total_horizonte'], $cp['costo']['total_horizonte']]);
chequear('y el neto es el bruto', $cm['total_horizonte'], $cm['neto']['total_horizonte']);
chequear('Tarjeta de Locales: la misma fila con otra clave', $cp['dias']['M2'], $cm['dias']['N2']);

seccion('una hoja sin dias se excluye y se avisa como critico');

$sinDiasVC = arbolVC([7 => ['DIAS_ACREDITACION' => null]]);
$arbolSD = MixCobro::resolver($sinDiasVC);
$cSD = cobranzaVC($sinDiasVC);

chequear('Resto no cobra nada', 0, array_sum($cSD['dias']['N9']) + array_sum($cSD['meses']['N9']));
chequear('3 cuotas, con dias propios, sigue', true, array_sum($cSD['dias']['N8']) > 0);

$ventaCanalVC = Ventas::ventaPorCanal($ventaVC);
$avisosSD = Ventas::avisosDelMix($arbolSD, $ventaCanalVC, 'NODO');

chequear('la venta de Locales en la ventana', 27000.0, $ventaCanalVC['LOCALES']);
chequear('un aviso, critico', [1, 'danger'], [count($avisosSD), $avisosSD[0]['nivel']]);
chequear('dice la rama, cuanta venta queda afuera y donde se corrige', true,
    strpos($avisosSD[0]['texto'], 'Locales › Tarjeta › Crédito › Fiserv › Resto') !== false
    && strpos($avisosSD[0]['texto'], '30,24% de la venta del canal, $ 8.164,80') !== false
    && strpos($avisosSD[0]['texto'], 'Parámetros › Ventas › Mix de Cobro y Plazos') !== false);

seccion('un grupo que no suma 100% se calcula igual y se avisa');

$sinFiservVC = arbolVC([7 => ['ACTIVO' => 0]]);
$avisosSF = Ventas::avisosDelMix(MixCobro::resolver($sinFiservVC), $ventaCanalVC, 'NODO');

chequear('critico, con la rama y la plata', true, $avisosSF[0]['nivel'] === 'danger'
    && strpos($avisosSF[0]['texto'], 'Locales › Tarjeta › Crédito suman 40,00%') !== false
    && strpos($avisosSF[0]['texto'], '43,20% de la venta del canal, $ 11.664,00') !== false);
chequear('y se proyecta con lo que hay: sin Fiserv', true,
    cobranzaVC($sinFiservVC)['total_horizonte'] < $c['total_horizonte']);

$sobra = Ventas::avisosDelMix(MixCobro::resolver(arbolVC([1 => ['PORCENTAJE' => 0.2]])), $ventaCanalVC, 'NODO');

chequear('si sobra, dice que se proyecta de mas', true,
    strpos($sobra[0]['texto'], 'Se proyecta cobranza de más: el 10,00% de la venta del canal, $ 2.700,00') !== false);

seccion('un canal sin hojas se calcula igual y se avisa');

$sinMayVC = arbolVC([14 => ['ACTIVO' => 0]]);
$avisosSM = Ventas::avisosDelMix(MixCobro::resolver($sinMayVC), $ventaCanalVC, 'NODO');

chequear('critico, con toda su venta', true, $avisosSM[0]['nivel'] === 'danger'
    && strpos($avisosSM[0]['texto'], 'Mayoristas') !== false
    && strpos($avisosSM[0]['texto'], 'Queda afuera toda su venta: $ 5.400,00') !== false);
chequear('los demas canales siguen', true, array_sum(cobranzaVC($sinMayVC)['subtotal_dias']['LOCALES']) > 0);

seccion('el mix plano avisa que falta el script, sin alarma');

$avisosPl = Ventas::avisosDelMix(MixCobro::resolver($planoVC), $ventaCanalVC, 'PLANO');

chequear('un aviso informativo que nombra el script', [1, 'info', true],
    [count($avisosPl), $avisosPl[0]['nivel'],
     strpos($avisosPl[0]['texto'], 'sql/cashflow_ventas_mix_nodo.sql') !== false]);
chequear('con el arbol, ninguno', [], Ventas::avisosDelMix(MixCobro::resolver(arbolVC()), $ventaCanalVC, 'NODO'));
