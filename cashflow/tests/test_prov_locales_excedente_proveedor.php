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
