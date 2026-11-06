<?php
/**
 * Proveedores Locales: EL EXCEDENTE SE VALIDA POR PROVEEDOR.
 *
 * Todo sin base, como test_prov_locales_excedente_parcial.php: la regla
 * (clasificarProveedor()), la importacion (compararImportacion()), lo que se
 * guarda (camposDeImportacion()) y como entra al eje (proyectarPagoImportado())
 * son estaticas y puras. Lo que toca la base se fija leyendo el metodo.
 *
 * La regla, en una linea: sobre todas las filas del proveedor en el archivo,
 * total pagado > maximo + $1 es error en todas; dentro del rango se acepta y
 * ninguna queda con saldo; por debajo del total neto, cada factura sola.
 */

require_once __DIR__ . '/../Class/Proveedores.php';

/** El cuerpo de un metodo, para fijar lo que toca la base leyendolo */
$cuerpo = function ($archivo, $firma) {
    $fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/' . $archivo));
    $desde = substr($fuente, strpos($fuente, $firma));

    return substr($desde, 0, strpos($desde, "\n    }\n"));
};

/** Dos facturas de neto 100.000, A y B, con lo que se paga de cada una */
$prov = function ($pagaA, $pagaB, $porc = 5.0) {
    return Proveedores::clasificarProveedor([
        ['clave' => 'A', 'neto' => 100000.0, 'pagado' => $pagaA],
        ['clave' => 'B', 'neto' => 100000.0, 'pagado' => $pagaB]
    ], $porc, ($porc === null) ? 'Falta el % de excedente permitido.' : null);
};

$clasif = function ($r) {
    return [$r['comprobantes']['A']['clasificacion'], $r['comprobantes']['B']['clasificacion']];
};

/* ================================================================
   LOS CINCO EJEMPLOS DE LA ESPECIFICACION (con 5%)
   ================================================================ */
seccion('ejemplo 1: 110.000 + 95.000 = 205.000, se acepta y se compensa');

$r = $prov(110000.0, 95000.0);
chequear('se acepta por el total', 'TOTAL', $r['resultado']);
chequear('totales, diferencia, % y máximo', [200000.0, 205000.0, 5000.0, 2.5, 210000.0],
    [$r['total_neto'], $r['total_pagado'], $r['diferencia'], $r['porc'], $r['maximo']]);
chequear('el excedente del proveedor es $ 5.000', 5000.0, $r['excedente']);
chequear('las dos quedan compensadas', ['COMPENSADA', 'COMPENSADA'], $clasif($r));
chequear('A +10.000 y B −5.000, sin saldo', [10000.0, -5000.0, 0.0, 0.0],
    [$r['comprobantes']['A']['excedente'], $r['comprobantes']['B']['excedente'],
     $r['comprobantes']['A']['saldo'], $r['comprobantes']['B']['saldo']]);
chequear('las diferencias suman el excedente del proveedor, una vez', 5000.0,
    round($r['comprobantes']['A']['excedente'] + $r['comprobantes']['B']['excedente'], 2));

seccion('ejemplo 2: 140.000 + 70.000 = 210.000, justo en el máximo');

$r = $prov(140000.0, 70000.0);
chequear('se acepta', 'TOTAL', $r['resultado']);
chequear('excedente $ 10.000 (5%)', [10000.0, 5.0], [$r['excedente'], $r['porc']]);
chequear('A con 40% de más queda compensada, no en error', ['COMPENSADA', 'COMPENSADA'], $clasif($r));

seccion('ejemplo 3: 120.000 + 100.000 = 220.000, supera el máximo');

$r = $prov(120000.0, 100000.0);
chequear('error del proveedor', 'ERROR', $r['resultado']);
chequear('el motivo dice pagado, neto, diferencia, % y máximo',
    'El proveedor paga $ 220.000,00 por facturas que suman un neto de $ 200.000,00: lo supera en '
    . '$ 20.000,00 (10%). El máximo permitido es $ 210.000,00 (5%). No se carga ninguna de sus filas.',
    $r['motivo']);

seccion('ejemplo 4: 110.000 + 50.000 = 160.000, por debajo: cada una sola');

$r = $prov(110000.0, 50000.0);
chequear('por factura', 'POR_FACTURA', $r['resultado']);
chequear('A en error (10% > 5%) y B parcial', ['ERROR', 'PARCIAL'], $clasif($r));
chequear('B con $ 50.000 pendientes', 50000.0, $r['comprobantes']['B']['saldo']);

seccion('ejemplo 5: 104.000 + 50.000 = 154.000, por debajo: cada una sola');

$r = $prov(104000.0, 50000.0);
chequear('por factura', 'POR_FACTURA', $r['resultado']);
chequear('A con excedente $ 4.000 y B parcial', ['EXCEDENTE', 'PARCIAL', 4000.0, 50000.0],
    array_merge($clasif($r), [$r['comprobantes']['A']['excedente'], $r['comprobantes']['B']['saldo']]));

/* ================================================================
   LOS BORDES
   ================================================================ */
seccion('dentro del rango sin nada que compensar: lo de siempre');

$r = $prov(104000.0, 100000.5);
chequear('se acepta', 'TOTAL', $r['resultado']);
chequear('no compensa', false, $r['compensa']);
chequear('A con excedente propio y B neto', ['EXCEDENTE', 'NETO'], $clasif($r));
chequear('el excedente del proveedor es el de A', 4000.5, $r['excedente']);

seccion('dentro del rango: la que coincide con su neto (±$1) sigue NETO');

$r = Proveedores::clasificarProveedor([
    ['clave' => 'A', 'neto' => 100000.0, 'pagado' => 110000.0],
    ['clave' => 'B', 'neto' => 100000.0, 'pagado' => 95000.0],
    ['clave' => 'C', 'neto' => 50000.0, 'pagado' => 50000.80]
], 5.0);
chequear('A y B compensadas, C neto', ['COMPENSADA', 'COMPENSADA', 'NETO'],
    array_column($r['comprobantes'], 'clasificacion'));

seccion('los bordes de $1');

chequear('total neto − $1 se acepta', 'TOTAL', $prov(110000.0, 89999.0)['resultado']);
chequear('total neto − $1,01 va por factura', 'POR_FACTURA', $prov(110000.0, 89998.99)['resultado']);
chequear('máximo + $1 se acepta', 'TOTAL', $prov(140000.0, 70001.0)['resultado']);
chequear('máximo + $1,01 es error', 'ERROR', $prov(140000.0, 70001.01)['resultado']);

seccion('un proveedor con una sola factura: lo mismo que clasificarImporte()');

foreach ([['neto', 100000.5], ['excedente', 104000.0], ['parcial', 60000.0], ['error', 106000.0]] as $caso) {
    $r = Proveedores::clasificarProveedor([['clave' => 'A', 'neto' => 100000.0, 'pagado' => $caso[1]]], 5.0);
    $sola = Proveedores::clasificarImporte($caso[1], 100000.0, 5.0);

    chequear($caso[0] . ': misma clasificación', $sola['clasificacion'],
        ($r['resultado'] === 'ERROR') ? 'ERROR' : $r['comprobantes']['A']['clasificacion']);
}

seccion('sin % cargado: no se acepta excedente, pero sí compensar');

$r = $prov(110000.0, 95000.0, null);
chequear('205.000 contra 200.000 es error', 'ERROR', $r['resultado']);
chequear('y el motivo dice por qué', true,
    strpos($r['motivo'], 'no se puede aceptar excedente: Falta el % de excedente permitido.') !== false);

$r = $prov(110000.0, 90000.0, null);
chequear('200.000 contra 200.000 se acepta, compensado', ['TOTAL', 'COMPENSADA', 'COMPENSADA', 0.0],
    array_merge([$r['resultado']], $clasif($r), [$r['excedente']]));
chequear('máximo = total neto', 200000.0, $r['maximo']);

$r = $prov(0.0 + 100000.0, 100000.0, 0.0);
chequear('con 0% y todo neto, se acepta sin excedente', ['TOTAL', 'NETO', 'NETO'],
    array_merge([$r['resultado']], $clasif($r)));

/* ================================================================
   LA PREVISUALIZACION: compararImportacion()
   ================================================================ */
$hoy = '2026-10-13';

/** Un pendiente, como lo devuelve getPendientes() */
$pend = function ($cod, $n, $importe, $vto = '2026-11-20', $extra = []) {
    return array_merge([
        'COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'RAZON_SOC' => 'Razón ' . $cod,
        'IMPORTE_PENDIENTE' => $importe, 'IMPORTE_VTO' => $importe, 'IMPUTACIONES' => 0.0,
        'FECHA_VTO' => $vto, 'FECHA_EMIS' => '2026-09-01', 'PLAZO_DIAS' => null,
        'SIN_FECHA_CARGADA' => false, 'FORMA_PAGO' => 'ECHEQ',
        'FORMA_PAGO_VIGENTE' => 'ECHEQ', 'CRONOGRAMA' => true
    ], $extra);
};

$pendientes = [
    $pend('P1', 'A1', 100000.0), $pend('P1', 'B1', 100000.0),
    $pend('P2', 'A2', 100000.0), $pend('P2', 'B2', 100000.0),
    $pend('P3', 'A3', 100000.0), $pend('P3', 'B3', 100000.0),
    $pend('P4', 'A4', 100000.0), $pend('P4', 'B4', 100000.0),
    $pend('P5', 'A5', 100000.0), $pend('P5', 'B5', 100000.0),
    $pend('SOLO', 'S1', 100000.0),
    $pend('DM', 'D1', 100000.0), $pend('DM', 'D2', 100000.0),
    $pend('FU', 'F1', 100000.0), $pend('FU', 'F2', 100000.0), $pend('FU', 'F3', 100000.0)
];

/** Una fila de la planilla */
$fila = function ($linea, $cod, $n, $fecha, $importe = '', $forma = '') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => ''];
};

$con5 = ['porc' => 5.0, 'error' => null, 'pagos_crono' => null];

$comparar = function ($filas, $exc = null, $enTango = [], $pagos = [], $aperturas = []) use ($pendientes, $hoy, $con5) {
    return Proveedores::compararImportacion($filas, $pendientes, $pagos, $hoy, $aperturas, true, null,
        $enTango, ($exc === null) ? $con5 : $exc);
};

$porLinea = function ($c) { return array_column($c['filas'], null, 'linea'); };

$subtotal = function ($c, $cod) {
    foreach ($c['proveedores'] as $p) {
        if ($p['cod_provee'] === $cod) { return $p; }
    }

    return null;
};

$estado = function ($f) { return $f['estado'] . ' ' . $f['clasificacion']; };

seccion('previsualización: los cinco ejemplos en una planilla');

$c = $comparar([
    $fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000'),
    $fila(4, 'P2', 'A2', '20/10/2026', '140000'), $fila(5, 'P2', 'B2', '20/10/2026', '70000'),
    $fila(6, 'P3', 'A3', '20/10/2026', '120000'), $fila(7, 'P3', 'B3', '20/10/2026', '100000'),
    $fila(8, 'P4', 'A4', '20/10/2026', '110000'), $fila(9, 'P4', 'B4', '20/10/2026', '50000'),
    $fila(10, 'P5', 'A5', '20/10/2026', '104000'), $fila(11, 'P5', 'B5', '20/10/2026', '50000')
]);
$l = $porLinea($c);

chequear('1: las dos se cargan compensadas', ['ALTA COMPENSADA', 'ALTA COMPENSADA'], [$estado($l[2]), $estado($l[3])]);
chequear('1: sin saldo, con la diferencia con signo', [0.0, 0.0, 10000.0, -5000.0],
    [$l[2]['saldo'], $l[3]['saldo'], $l[2]['excedente'], $l[3]['excedente']]);
chequear('1: el motivo dice que está compensada', 'Pagada — compensada en el total del proveedor: se paga '
    . '$ 95.000,00 contra un neto de $ 100.000,00 (−$ 5.000,00). No queda saldo pendiente.', $l[3]['motivo']);
chequear('1: los medios se guardan COMPENSADA con el importe de la planilla', ['COMPENSADA', 95000.0],
    [$l[3]['medios'][0]['clasificacion'], $l[3]['medios'][0]['importe']]);
chequear('2: las dos compensadas', ['ALTA COMPENSADA', 'ALTA COMPENSADA'], [$estado($l[4]), $estado($l[5])]);
chequear('3: error en las dos', ['ERROR', 'ERROR'], [$l[6]['estado'], $l[7]['estado']]);
chequear('3: el motivo es el del proveedor, en las dos', true,
    $l[6]['motivo'] === $l[7]['motivo'] && strpos($l[6]['motivo'], 'El máximo permitido es $ 210.000,00') !== false);
chequear('4: A en error y B parcial', ['ERROR', 'ALTA PARCIAL'], [$l[8]['estado'], $estado($l[9])]);
chequear('4: A con su propio motivo', true, strpos($l[8]['motivo'], 'supera el neto a pagar') !== false);
chequear('4: B con $ 50.000 de saldo', 50000.0, $l[9]['saldo']);
chequear('5: A excedente y B parcial', ['ALTA EXCEDENTE', 'ALTA PARCIAL'], [$estado($l[10]), $estado($l[11])]);

chequear('cada fila dice qué dijo el total de su proveedor', ['TOTAL', 'TOTAL', 'ERROR', 'POR_FACTURA', 'POR_FACTURA'],
    [$l[2]['regla_proveedor'], $l[5]['regla_proveedor'], $l[6]['regla_proveedor'], $l[8]['regla_proveedor'],
     $l[11]['regla_proveedor']]);

$s = $subtotal($c, 'P1');
chequear('el subtotal de P1', ['Razón P1', 2, 2, 0, 'TOTAL', 200000.0, 205000.0, 5000.0, 2.5, 210000.0, 5000.0],
    [$s['razon_social'], $s['filas'], $s['comprobantes'], $s['fuera'], $s['resultado'], $s['total_neto'],
     $s['total_pagado'], $s['diferencia'], $s['porc'], $s['maximo'], $s['excedente']]);

$r = $c['resumen'];
chequear('el resumen: proveedores con excedente (P1, P2, P5) y cuánto', [3, 19000.0],
    [$r['proveedores_con_excedente'], $r['importe_excedente']]);
chequear('el resumen: un proveedor rechazado, cuatro compensadas', [1, 4],
    [$r['proveedores_rechazados'], $r['compensadas']]);
chequear('el resumen: lo que se reubica es lo de la planilla, sin lo que no se carga',
    205000.0 + 210000.0 + 50000.0 + 154000.0, $r['importe_con_fecha']);
chequear('el resumen: errores por fila (P3 ×2, A de P4)', 3, $r['errores']);
chequear('y hay avisos de rechazados, excedente y compensadas', 3, count(array_filter($c['avisos'], function ($a) {
    return strpos($a, 'proveedor(es) pagan más que el máximo') !== false
        || strpos($a, 'proveedor(es) se pagan con excedente') !== false
        || strpos($a, 'se compensan en el total de su proveedor') !== false;
})));

seccion('previsualización: un proveedor con una sola factura, como hoy');

foreach ([['110000', 'ERROR', null], ['104000', 'ALTA', 'EXCEDENTE'], ['60000', 'ALTA', 'PARCIAL'],
          ['', 'ALTA', 'NETO']] as $caso) {
    $f = $comparar([$fila(2, 'SOLO', 'S1', '20/10/2026', $caso[0])])['filas'][0];
    chequear('importe ' . ($caso[0] === '' ? 'vacío' : $caso[0]), [$caso[1], $caso[2]],
        [$f['estado'], $f['estado'] === 'ERROR' ? null : $f['clasificacion']]);
}

$f = $comparar([$fila(2, 'SOLO', 'S1', '20/10/2026', '110000')])['filas'][0];
chequear('el error de una sola factura sigue con su motivo de siempre', true,
    strpos($f['motivo'], 'El importe ($ 110.000,00) supera el neto a pagar') === 0);

seccion('previsualización: una factura en dos medios y otra en uno');

$c = $comparar([
    $fila(2, 'DM', 'D1', '20/10/2026', '70000', 'ECHEQ'),
    $fila(3, 'DM', 'D1', '27/10/2026', '60000', 'TRANSFERENCIA'),
    $fila(4, 'DM', 'D2', '20/10/2026', '75000')
]);
$l = $porLinea($c);

chequear('D1 (130.000) y D2 (75.000) se compensan: 205.000 contra 200.000',
    ['ALTA COMPENSADA', 'ALTA COMPENSADA', 'ALTA COMPENSADA'], [$estado($l[2]), $estado($l[3]), $estado($l[4])]);
chequear('D1 sigue en dos medios, cada uno con su importe', [[1, 70000.0, 'COMPENSADA'], [2, 60000.0, 'COMPENSADA']],
    array_map(function ($m) { return [$m['orden'], $m['importe'], $m['clasificacion']]; }, $l[2]['medios']));
chequear('D1 +30.000, D2 −25.000', [30000.0, -25000.0], [$l[2]['excedente'], $l[4]['excedente']]);
chequear('el subtotal suma las tres filas', [3, 2, 205000.0], [$subtotal($c, 'DM')['filas'],
    $subtotal($c, 'DM')['comprobantes'], $subtotal($c, 'DM')['total_pagado']]);

$c = $comparar([
    $fila(2, 'DM', 'D1', '20/10/2026', '90000', 'ECHEQ'),
    $fila(3, 'DM', 'D1', '27/10/2026', '60000', 'TRANSFERENCIA'),
    $fila(4, 'DM', 'D2', '20/10/2026', '75000')
]);
chequear('con 225.000 los dos medios y la otra quedan en error', ['ERROR', 'ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));

seccion('previsualización: fechas distintas se suman igual');

$c = $comparar([
    $fila(2, 'FU', 'F1', '20/10/2026', '120000'),
    $fila(3, 'FU', 'F2', '03/11/2026', '90000'),
    $fila(4, 'FU', 'F3', '17/11/2026', '95000')
]);
chequear('305.000 contra 300.000: se acepta, y F1 con 20% queda compensada',
    ['ALTA COMPENSADA', 'ALTA COMPENSADA', 'ALTA COMPENSADA'], array_map($estado, $c['filas']));
chequear('cada una con su fecha', ['2026-10-20', '2026-11-03', '2026-11-17'],
    array_map(function ($f) { return $f['medios'][0]['fecha']; }, $c['filas']));

seccion('previsualización: sin % cargado');

$sinPorc = ['porc' => null, 'error' => 'Falta el % de excedente permitido.', 'pagos_crono' => null];
$c = $comparar([$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000')],
    $sinPorc);
chequear('205.000 contra 200.000 es error en las dos', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('y el motivo dice que falta el %', true,
    strpos($c['filas'][0]['motivo'], 'no se puede aceptar excedente: Falta el % de excedente') !== false);

$c = $comparar([$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '90000')],
    $sinPorc);
chequear('200.000 contra 200.000 se acepta, compensado', ['ALTA COMPENSADA', 'ALTA COMPENSADA'],
    array_map($estado, $c['filas']));
chequear('sin excedente del proveedor', [0, 0.0],
    [$c['resumen']['proveedores_con_excedente'], $c['resumen']['importe_excedente']]);

seccion('previsualización: lo que tiene un error propio queda fuera del total');

$enTango = ['FU|F9' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-01']]];
$c = $comparar([
    $fila(2, 'FU', 'F1', '20/10/2026', '110000'),
    $fila(3, 'FU', 'F2', '20/10/2026', '95000'),
    $fila(4, 'FU', 'F9', '20/10/2026', '1000'),       // ya pagada en Tango
    $fila(5, 'FU', 'F8', '20/10/2026', '1000'),       // no existe
    $fila(6, 'FU', 'F3', '20/10/2026', '0')           // importe cero
], null, $enTango);
$l = $porLinea($c);

chequear('F1 y F2 se compensan entre ellas', ['ALTA COMPENSADA', 'ALTA COMPENSADA'], [$estado($l[2]), $estado($l[3])]);
chequear('las otras quedan como estaban', ['YA_PAGADA', 'ERROR', 'ERROR'],
    [$l[4]['estado'], $l[5]['estado'], $l[6]['estado']]);
chequear('y se marcan fuera del total', [true, true, true, false],
    [$l[4]['fuera_del_total'], $l[5]['fuera_del_total'], $l[6]['fuera_del_total'], $l[2]['fuera_del_total']]);
$s = $subtotal($c, 'FU');
chequear('el subtotal: 5 filas, 3 fuera, el total sin ellas', [5, 3, 200000.0, 205000.0],
    [$s['filas'], $s['fuera'], $s['total_neto'], $s['total_pagado']]);

$c = $comparar([
    $fila(2, 'DM', 'D1', '20/10/2026', '70000', 'ECHEQ'),
    $fila(3, 'DM', 'D1', '27/10/2026', '60000', 'CAJA'),
    $fila(4, 'DM', 'D2', '20/10/2026', '75000')
]);
$l = $porLinea($c);
chequear('dos medios mal armados quedan fuera: D2 sola es parcial', ['ERROR', 'ERROR', 'ALTA PARCIAL'],
    [$l[2]['estado'], $l[3]['estado'], $estado($l[4])]);

seccion('previsualización: sin el script de COMPENSADA, se dice cuál correr');

$c = $comparar([$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000')],
    $con5 + ['compensada' => false]);
chequear('las dos en error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('y el motivo nombra el script', true,
    strpos($c['filas'][0]['motivo'], 'sql/cashflow_prov_locales_excedente_por_proveedor.sql') !== false);

$c = $comparar([$fila(2, 'P5', 'A5', '20/10/2026', '104000'), $fila(3, 'P5', 'B5', '20/10/2026', '50000')],
    $con5 + ['compensada' => false]);
chequear('lo que no compensa se carga igual', ['ALTA EXCEDENTE', 'ALTA PARCIAL'], array_map($estado, $c['filas']));

seccion('previsualización: sin el script del excedente, la de antes');

$c = Proveedores::compararImportacion([$fila(2, 'P1', 'A1', '20/10/2026', '110000'),
    $fila(3, 'P1', 'B1', '20/10/2026', '95000')], $pendientes, [], $hoy, [], true, null, [], null);
chequear('cada una contra su neto: error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('y sin subtotales', [], $c['proveedores']);

seccion('previsualización: reimportar igual es SIN_CAMBIOS');

$c = $comparar([$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000')]);
$ap = [];
foreach ($c['filas'] as $f) {
    $ap[Proveedores::clavePago($f['cod_provee'], $f['t_comp'], $f['n_comp'])] = $f['medios'];
}
$c2 = $comparar([$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000')],
    null, [], [], $ap);
chequear('las dos sin cambios', ['SIN_CAMBIOS', 'SIN_CAMBIOS'], array_column($c2['filas'], 'estado'));

/* ================================================================
   LA CONFIRMACION
   ================================================================ */
seccion('confirmación: aplica la misma regla');

$aplicar = $cuerpo('Proveedores.php', 'public function aplicarImportacion(');
chequear('vuelve a pasar por compararImportacion()', true, strpos($aplicar, 'self::compararImportacion(') !== false);
chequear('y compara contra lo que se vio', true, strpos($aplicar, 'self::diferenciasConLoVisto(') !== false);

seccion('confirmación: si el resultado de un proveedor cambió, no se aplica nada');

$dos = [$fila(2, 'P1', 'A1', '20/10/2026', '110000'), $fila(3, 'P1', 'B1', '20/10/2026', '95000')];
$vistas = $comparar($dos)['filas'];

chequear('la misma planilla, sin cambios', [], Proveedores::diferenciasConLoVisto($vistas, $comparar($dos)['filas']));

$difs = Proveedores::diferenciasConLoVisto([$vistas[0]], $comparar([$dos[0]])['filas']);
chequear('A que se vio compensada hoy, sola, da error', ['línea 2: ALTA → ERROR'], $difs);

$visto = $vistas;
$visto[0]['regla_proveedor'] = 'POR_FACTURA';
chequear('el mismo estado con otro resultado del proveedor también frena',
    ['línea 2: el total del proveedor pasó de evaluarse factura por factura a aceptarse por el total'],
    Proveedores::diferenciasConLoVisto($visto, $comparar($dos)['filas']));

$visto = $vistas;
$visto[1]['clasificacion'] = 'PARCIAL';
chequear('y la misma fila con otra clasificación, también', ['línea 3: pasó de PARCIAL a COMPENSADA'],
    Proveedores::diferenciasConLoVisto($visto, $comparar($dos)['filas']));
