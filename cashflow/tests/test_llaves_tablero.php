<?php
/**
 * Llaves y Renovaciones en el tablero (Providers/LlavesProvider.php) y el
 * invariante con la pestaña.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA PESTAÑA Y EL TABLERO DICEN COSAS DISTINTAS. Suman la misma lista de
 *      pagos; columna por columna tienen que dar lo mismo, con todos los
 *      origenes adentro (contrato, estimado, manual, factura, obra en U$S).
 *   2. REAL + PROYECTADO NO ES PAGOS, o una anotacion suma.
 *   3. LA FILA VIEJA Y LAS NUEVAS QUEDAN ACTIVAS A LA VEZ: doble conteo.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Llaves.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';
require_once __DIR__ . '/../Class/Providers/LlavesProvider.php';

$h = new Horizonte(28, 12, [], new DateTime('2026-11-23'));
$meses = array_column($h->meses(), 'clave');

/* Un escenario con todos los origenes: Arcos estimado (con su obra en U$S),
   DOT con el contrato cargado, una factura de Abasto marcada como llave en dos
   pagos y una prenda a mano en serie. */
$entrada = [
    'hoy' => $h->hoy(), 'meses' => $meses, 'habiles' => [],
    'locales' => [
        78 => ['nro' => 78, 'nombre' => 'DISTRITO ARCOS', 'habilitado' => true, 'vigencia' => false, 'cod_provee' => 'SAARCO',
               'operador' => 'ARCOS DEL GOURMET', 'pct_retencion' => 1.0, 'm2' => 151.24,
               'contratos' => [['id' => 22, 'desde' => '2023-08-01', 'hasta' => '2026-12-31', 'llave' => 33000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]]],
        66 => ['nro' => 66, 'nombre' => 'DOT', 'habilitado' => true, 'vigencia' => false, 'cod_provee' => 'SAPAMA',
               'operador' => 'PANAMERICAN MALL', 'pct_retencion' => 0.0, 'm2' => 108.17,
               'contratos' => [['id' => 32, 'desde' => '2023-10-20', 'hasta' => '2026-10-19', 'llave' => 11600000.0, 'comisiones' => 0.0, 'fpc' => 0.0],
                               ['id' => 91, 'desde' => '2026-10-20', 'hasta' => '2029-10-19', 'llave' => 80000000.0, 'comisiones' => 23049194.0, 'fpc' => 8388411.0]]],
        7 => ['nro' => 7, 'nombre' => 'ABASTO', 'habilitado' => true, 'vigencia' => false, 'cod_provee' => 'SAPALA',
              'operador' => 'IRSA', 'pct_retencion' => 0.0, 'm2' => 111.61,
              'contratos' => [['id' => 34, 'desde' => '2024-07-01', 'hasta' => '2027-06-30', 'llave' => 40000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]]]
    ],
    'alquiler' => [78 => ['2027-01' => 18927137.0], 7 => ['2027-07' => 13340896.0]],
    'par' => ['tablas_ok' => true,
              'generales' => ['PCT_LLAVE' => 25.0, 'CUOTAS' => 4.0, 'DIA_PAGO' => 15.0, 'CORTE_CHICO' => 84.0, 'CORTE_MEDIANO' => 110.0,
                              'USD_OBRA_GRANDE' => 10000.0],
              'operadores' => ['SAARCO' => ['pct_impuestos' => 21.0, 'pct_comisiones' => 30.0, 'pct_fpc' => 10.0, 'escribano' => 300000.0],
                               'SAPAMA' => ['pct_impuestos' => 24.5], 'SAPALA' => ['pct_impuestos' => 31.0]],
              'locales' => [], 'pagadas' => [],
              'obras' => [['id' => 1, 'nro' => 78, 'mes' => '2027-03', 'segmento' => null, 'costo_usd' => null, 'observacion' => null]],
              'aperturas' => [],
              'compromisos' => array_map(function ($c) {
                  return ['id' => 10 + $c['nro'], 'tipo' => 'ALQUILERES', 'cod_provee' => 'SAUNIC', 'nro' => 79, 'concepto' => 'Prenda',
                          'fecha' => $c['fecha'], 'monto' => 1000000.0, 'forma' => 'ECHEQ', 'observacion' => null, 'id_serie' => 11,
                          'cuota_nro' => $c['nro'], 'cuotas' => 4];
              }, LlavesParametros::serie('2026-11-25', 1000000.0, 4))],
    'curva' => ['2027-03' => ['clave' => '2027-03', 'simbolo' => 'DLR032027', 'cotizacion' => 1500.0]],
    'facturas' => [['id' => 3, 'nro' => 7, 'concepto' => 'LLAVE', 'ant' => 34, 'contrato' => null, 'mes' => '2027-07',
                    'pagada' => false, 'cod_provee' => 'SAPALA', 'comprobante' => 'FAC A0003000013817', 'texto' => '',
                    'pagos' => [['fecha' => '2026-11-27', 'importe' => 46375000.0], ['fecha' => '2027-01-27', 'importe' => 46375000.0]]],
                   /* Las comisiones de DOT, facturadas: reemplazan sólo sus comisiones (el FPC del #91 sigue). */
                   ['id' => 4, 'nro' => 66, 'concepto' => 'COMISIONES', 'ant' => 32, 'contrato' => 91, 'mes' => '2026-10',
                    'pagada' => false, 'cod_provee' => 'SAPAMA', 'comprobante' => 'FAC A0002000000100', 'texto' => '',
                    'pagos' => [['fecha' => '2026-12-10', 'importe' => 20000000.0], ['fecha' => '2027-01-11', 'importe' => 8687000.0]]],
                   /* El FPC de la renovación estimada de Arcos, facturado. */
                   ['id' => 5, 'nro' => 78, 'concepto' => 'FPC', 'ant' => 22, 'contrato' => null, 'mes' => '2027-01',
                    'pagada' => false, 'cod_provee' => 'SAARCO', 'comprobante' => 'FAC A0001400000600', 'texto' => '',
                    'pagos' => [['fecha' => '2027-01-15', 'importe' => 3000000.0]]]],
    'operadores' => [], 'locales_nombres' => [78 => 'DISTRITO ARCOS', 66 => 'DOT', 7 => 'ABASTO', 79 => 'PARQUE BROWN']
];

$c = LlavesCalculo::proyectar($entrada);
$c['tablas_ok'] = true;
$origenes = array_values(array_unique(array_column($c['pagos'], 'origen')));
sort($origenes);

seccion('El escenario tiene todos los orígenes');

chequear('contrato, estimado, factura y manual', ['CONTRATO', 'ESTIMADO', 'FACTURA', 'MANUAL'], $origenes);
chequear('y los conceptos nuevos: comisiones estimadas en 4 cuotas y el escribano en la firma (Arcos)', [4, 1], [
    count(array_filter($c['pagos'], function ($p) { return $p['concepto'] === 'COMISIONES' && $p['origen'] === 'ESTIMADO'; })),
    count(array_filter($c['pagos'], function ($p) { return $p['concepto'] === 'ESCRIBANO'; }))]);
chequear('las facturas de comisiones y de FPC reemplazan sólo su concepto', [0, 0, 2, 1], [
    count(array_filter($c['pagos'], function ($p) { return $p['nro'] === 66 && $p['concepto'] === 'COMISIONES' && $p['origen'] !== 'FACTURA'; })),
    count(array_filter($c['pagos'], function ($p) { return $p['nro'] === 78 && $p['concepto'] === 'FPC' && $p['origen'] !== 'FACTURA'; })),
    count(array_filter($c['pagos'], function ($p) { return $p['concepto'] === 'COMISIONES' && $p['origen'] === 'FACTURA'; })),
    count(array_filter($c['pagos'], function ($p) { return $p['concepto'] === 'FPC' && $p['origen'] === 'FACTURA'; }))]);
chequear('y una obra en U$S', true, count(array_filter($c['pagos'], function ($p) { return $p['concepto'] === 'OBRA'; })) === 1);
chequear('la factura de Abasto reemplazó su llave estimada', [], array_values(array_filter($c['pagos'], function ($p) {
    return $p['nro'] === 7 && $p['concepto'] === 'LLAVE' && $p['origen'] === 'ESTIMADO'; })));

seccion('El invariante: la pestaña y el tablero suman lo mismo, columna por columna');

$series = LlavesProvider::armarSeries($h, $c['pagos']);
$p = Llaves::armarPestana($c, $h, ['nombres' => $entrada['locales_nombres'], 'facturas' => []]);
$tot = $p['eje']['totales'];
$distintas = [];

foreach (['dias', 'meses'] as $rama) {
    foreach ($series['PAGOS'][$rama] as $k => $v) {
        if (abs($v - ($tot[$rama][$k] ?? 0.0)) > 0.005) {
            $distintas[] = $rama . '|' . $k;
        }
    }
}

chequear('PAGOS del tablero = el pie de la pestaña, en cada columna', [], $distintas);
$sumaSerie = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']), 2); };
chequear('y en el total', round($tot['total_horizonte'], 2), $sumaSerie($series['PAGOS']));

$partes = [];

foreach (['dias', 'meses'] as $rama) {
    foreach ($series['PAGOS'][$rama] as $k => $v) {
        if (abs($series['REAL'][$rama][$k] + $series['PROYECTADO'][$rama][$k] - $v) > 0.005) {
            $partes[] = $rama . '|' . $k;
        }
    }
}

chequear('REAL + PROYECTADO = PAGOS en cada columna', [], $partes);
chequear('REAL son sólo las facturas (llave de Abasto, comisiones de DOT, FPC de Arcos)', 92750000.0 + 28687000.0 + 3000000.0, $sumaSerie($series['REAL']));
chequear('lo estimado se anota en su celda, sin sumar', true, !empty($series['PROYECTADO']['detalle'])
    && $sumaSerie($series['PROYECTADO']) === round(array_sum(array_column(array_filter($c['pagos'], function ($x) {
        return $x['origen'] !== 'FACTURA'; }), 'importe')), 2));

$notas = implode(' | ', array_column($series['PROYECTADO']['detalle'], 'nota'));
chequear('el tooltip de lo estimado nombra el concepto (Comisiones), no «Otros gastos»', [true, false],
    [strpos($notas, 'DISTRITO ARCOS Comisiones') !== false, strpos($notas, 'Otros gastos') !== false]);
$idsP = array_column($p['filas'], 'id');
chequear('la pestaña abre el local en Llave, Comisiones, Escribano y otros y Obra (los que tienen pagos)', [true, true, true, true],
    array_map(function ($c) use ($idsP) { return in_array('C|ARCOS DEL GOURMET|78|' . $c, $idsP, true); },
        ['LLAVE', 'COMISIONES', 'ESCRIBANO', 'OBRA']));
chequear('y el FPC facturado de Arcos va bajo FPC; sin marca vieja no hay fila de «Otros gastos»', [true, false],
    [in_array('C|ARCOS DEL GOURMET|78|FPC', $idsP, true), in_array('C|ARCOS DEL GOURMET|78|OTROS', $idsP, true)]);

seccion('El tablero: el módulo y la fila agrupada');

$meta = CashflowRegistry::meta('LLAVES_RENOV');
chequear('LLAVES_RENOV está disponible, con su proveedor', [true, 'LlavesProvider'],
    [CashflowRegistry::disponible('LLAVES_RENOV'), $meta['clase']]);
chequear('PAGOS es el total de REAL y PROYECTADO', ['PAGOS' => ['REAL', 'PROYECTADO']], $meta['componentes']);

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_llaves_fila.sql');
chequear('el script crea las dos filas, agrupadas', [true, true, true], [
    (bool) preg_match("/'LLAVES_RENOV_REAL'.*'LLAVES_RENOV', 'REAL'/s", $sql),
    (bool) preg_match("/'LLAVES_RENOV_PROY'.*'LLAVES_RENOV', 'PROYECTADO'/s", $sql),
    strpos($sql, "GRUPO = ''LLAVES_RENOV''") !== false]);
chequear('inhabilita la de PAGOS en la misma transacción', true,
    (bool) preg_match("/BEGIN TRANSACTION;.*SET ACTIVO = 0.*ORIGEN_SERIE = 'PAGOS'.*COMMIT TRANSACTION;/s", $sql));
chequear('y controla el doble conteo al pie', true, strpos($sql, "ORIGEN_SERIE = 'PAGOS_ALQUILERES'") !== false);

$sec = [['CODIGO' => 'COSTOS_INDIRECTOS', 'NOMBRE' => 'CI', 'ROL' => 'MOVIMIENTO', 'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]];
$fila = function ($id, $codigo, $serie, $orden, $activo, $extra = []) {
    return array_merge(['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => 'COSTOS_INDIRECTOS',
        'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'LLAVES_RENOV', 'ORIGEN_SERIE' => $serie,
        'ORDEN' => $orden, 'ACTIVO' => $activo, 'GRUPO' => null, 'NATURALEZA' => null, 'GRUPO_NOMBRE' => null], $extra);
};
$provs = ['LLAVES_RENOV' => $meta + ['codigo' => 'LLAVES_RENOV']];
$nuevas = [
    $fila(17, 'LLAVES_RENOV', 'PAGOS', 30, 0),
    $fila(70, 'LLAVES_RENOV_REAL', 'REAL', 31, 1, ['GRUPO' => 'LLAVES_RENOV', 'NATURALEZA' => 'REAL', 'GRUPO_NOMBRE' => 'Llaves y renovaciones']),
    $fila(71, 'LLAVES_RENOV_PROY', 'PROYECTADO', 32, 1, ['GRUPO' => 'LLAVES_RENOV', 'NATURALEZA' => 'PROYECTADO'])
];
chequear('real y proyectado agrupados, la vieja inhabilitada: válido', [], CashflowEstructura::validar($sec, $nuevas, $provs)['errores']);
$tres = $nuevas;
$tres[0]['ACTIVO'] = 1;
chequear('con la de PAGOS activa también: el validador lo rechaza', true,
    !empty(CashflowEstructura::validar($sec, $tres, $provs)['errores']));
