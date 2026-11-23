<?php
/**
 * La regla de Llaves y Renovaciones (Class/LlavesCalculo.php): cuanto se paga
 * de llave, otros gastos, obras, aperturas y compromisos, y cuando.
 *
 * TODO CON DOBLES Y CON HOY FIJO (23/11/2026): la regla es pura, y una prueba
 * que dependiera de la fecha real o de la base caducaria sola.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN DATO QUE FALTA SE PROYECTA EN 0. Un local sin alquiler en el mes,
 *      sin razon social o sin % de impuestos tiene que quedar AFUERA, con su
 *      aviso; un 0 bajaria el egreso sin que nadie se entere.
 *   2. LA RENOVACION CARGADA Y LA ESTIMADA SE SUMAN. Con el contrato nuevo en
 *      la app, la estimacion tiene que desaparecer.
 *   3. LA LLAVE = 1 SE PAGA. Es la marca de "tiene llave", nunca un importe.
 *   4. UNA FACTURA O UNA MARCA DE PAGADA NO CORTA. Lo pagado se contaria dos
 *      veces.
 *   5. SE PROYECTA LO DE HOY O LO PASADO.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/LlavesCalculo.php';

const LL_HOY = '2026-11-23';

/** Los meses del horizonte de prueba: noviembre de 2026 a octubre de 2027. */
function llMeses() {
    $out = [];

    for ($i = 0; $i < 12; $i++) {
        $out[] = date('Y-m', strtotime('2026-11-01 +' . $i . ' month'));
    }

    return $out;
}

/** Un contrato de la app. */
function llContrato($id, $desde, $hasta, $llave, $comis = 0.0, $fpc = 0.0) {
    return ['id' => $id, 'desde' => $desde, 'hasta' => $hasta, 'llave' => $llave, 'comisiones' => $comis, 'fpc' => $fpc];
}

/** Un local de prueba: Distrito Arcos, vence el 31/12/2026, llave 33 M. */
function llLocal($extra = []) {
    return $extra + ['nro' => 78, 'nombre' => 'DISTRITO ARCOS', 'habilitado' => true, 'vigencia' => false,
                     'cod_provee' => 'SAARCO', 'operador' => 'ARCOS DEL GOURMET', 'pct_retencion' => 0.0, 'm2' => 151.24,
                     'contratos' => [llContrato(22, '2023-08-01', '2026-12-31', 33000000.0, 7380000.0, 2500000.0)]];
}

/** Los parametros: los defaults de Generales y el operador con 0 % de impuestos. */
function llPar($extra = []) {
    return array_replace_recursive([
        'tablas_ok' => true,
        'generales' => ['PCT_LLAVE' => 25.0, 'CUOTAS' => 4.0, 'DIA_PAGO' => 15.0],
        'operadores' => ['SAARCO' => ['pct_impuestos' => 0.0]],
        'locales' => [], 'pagadas' => [], 'obras' => [], 'aperturas' => [], 'compromisos' => []
    ], $extra);
}

function llEntrada($extra = []) {
    return $extra + [
        'hoy' => LL_HOY, 'meses' => llMeses(),
        /* El 1/1/2027 es feriado: la cuota pasa al lunes 4. */
        'habiles' => ['2027-01-01' => false],
        'locales' => [78 => llLocal()],
        'alquiler' => [78 => ['2027-01' => 18927137.0, '2026-11' => 2000000.0]],
        'par' => llPar(), 'curva' => [], 'facturas' => [], 'operadores' => [], 'locales_nombres' => [78 => 'DISTRITO ARCOS']
    ];
}

function llPagos($r, $concepto = null, $origen = null) {
    return array_values(array_filter($r['pagos'], function ($p) use ($concepto, $origen) {
        return ($concepto === null || $p['concepto'] === $concepto) && ($origen === null || $p['origen'] === $origen);
    }));
}

function llTextos($r) {
    return implode(' | ', array_column($r['avisos'], 'texto'));
}

seccion('LlavesCalculo: los meses de una vigencia');

chequear('01/08/2023 a 31/12/2026 son 41 meses', 41, LlavesCalculo::meses('2023-08-01', '2026-12-31'));
chequear('20/10/2026 a 19/10/2029 son 36', 36, LlavesCalculo::meses('2026-10-20', '2029-10-19'));
chequear('un mes y pico es 1', 1, LlavesCalculo::meses('2026-02-03', '2026-03-03'));

seccion('LlavesCalculo: la llave estimada con el % general, en 4 cuotas corridas al hábil');

$r = LlavesCalculo::proyectar(llEntrada());
$ll = llPagos($r, LlavesCalculo::LLAVE);
$esperada = round(0.25 * 18927137.0 * 41, 2);
chequear('4 cuotas de llave estimada', 4, count($ll));
chequear('las 4 suman 25 % × 1+2+8 de enero × 41 meses', $esperada, round(array_sum(array_column($ll, 'importe')), 2));
chequear('la primera, el 1/1 feriado, pasa al lunes 4/1', '2027-01-04', $ll[0]['fecha']);
chequear('las siguientes, el 1 de cada mes', ['2027-02-01', '2027-03-01', '2027-04-01'],
    array_column(array_slice($ll, 1), 'fecha'));
chequear('el origen es Estimado', LlavesCalculo::ESTIMADO, $ll[0]['origen']);
chequear('el tooltip explica la cuenta', true, strpos($ll[1]['detalle'], '25% (general)') !== false
    && strpos($ll[1]['detalle'], '41 meses') !== false && strpos($ll[1]['detalle'], 'cuota 2 de 4') !== false);
chequear('el estado es Vence en el horizonte', LlavesCalculo::VENCE, $r['locales'][78]['estado']);
chequear('aviso informativo de que vence', true, strpos(llTextos($r), 'Contratos que vencen dentro del horizonte: DISTRITO ARCOS (31/12/2026)') !== false);

seccion('LlavesCalculo: el % y los meses del local, las cuotas del operador y del local');

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_llave' => 28.0, 'cuotas' => 2]],
                               'locales' => ['78|22' => ['pct_llave' => 30.0, 'meses' => 36, 'cuotas' => 3]]])]);
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('el % y los meses del local pisan los del operador y los del vigente', round(0.30 * 18927137.0 * 36, 2),
    round(array_sum(array_column($ll, 'importe')), 2));
chequear('las cuotas del local pisan las del operador', 3, count($ll));

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_llave' => 28.0, 'cuotas' => 2]]])]);
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('sin el local, el % del operador', round(0.28 * 18927137.0 * 41, 2), round(array_sum(array_column($ll, 'importe')), 2));
chequear('sin el local, las cuotas del operador', 2, count($ll));

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['corrimiento' => -2]]])]);
$e['alquiler'][78]['2026-11'] = 2000000.0;
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('con corrimiento -2, la primera cuota va en noviembre (y la base es el alquiler de noviembre)',
    round(0.25 * 2000000.0 * 41 / 4, 2), $ll[0]['importe'] ?? null);

$e = llEntrada(['par' => llPar(['locales' => ['78|22' => ['llave_manual' => 100000000.0]]])]);
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('la llave a mano reemplaza el cálculo, con origen Manual', [100000000.0, LlavesCalculo::MANUAL],
    [round(array_sum(array_column($ll, 'importe')), 2), $ll[0]['origen']]);

seccion('LlavesCalculo: impuestos del operador y retención del local');

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_impuestos' => 31.0]]])]);
$e['locales'][78]['pct_retencion'] = 1.0;
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
$neto = LlavesCalculo::cuotas($esperada, 4)[0];
chequear('a pagar = neto + 31 % - 1 % del neto', round($neto + round($neto * 0.31, 2) - round($neto * 0.01, 2), 2), $ll[0]['importe']);
chequear('importes(): la misma regla de redondeo que Alquileres',
    ['neto' => 20000000.0, 'impuestos' => 6200000.0, 'bruto' => 26200000.0, 'retencion' => 200000.0, 'a_pagar' => 26000000.0],
    LlavesCalculo::importes(20000000, 31, 1));

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_impuestos' => null]]])]);
$r = LlavesCalculo::proyectar($e);
chequear('sin % de impuestos no se proyecta la llave (null, no 0)', [], llPagos($r, LlavesCalculo::LLAVE));
chequear('y queda en no_proyecta con el neto que falta', $esperada, $r['no_proyecta'][0]['importe'] ?? null);
chequear('con su aviso, que nombra al operador', true, strpos(llTextos($r), 'El operador ARCOS DEL GOURMET no tiene % de impuestos') !== false);

$e = llEntrada();
$e['locales'][78]['pct_retencion'] = null;
$r = LlavesCalculo::proyectar($e);
chequear('sin % de retención del local, tampoco', [], llPagos($r, LlavesCalculo::LLAVE));

seccion('LlavesCalculo: la renovación cargada reemplaza a la estimación');

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_impuestos' => 31.0]]])]);
$e['locales'][78]['pct_retencion'] = 1.0;
$e['locales'][78]['contratos'][] = llContrato(95, '2027-01-01', '2029-12-31', 80000000.0, 20000000.0, 5000000.0);
$r = LlavesCalculo::proyectar($e);
$ll = llPagos($r, LlavesCalculo::LLAVE);
chequear('estado Renovado', LlavesCalculo::RENOVADO, $r['locales'][78]['estado']);
chequear('no queda nada estimado', [], llPagos($r, null, LlavesCalculo::ESTIMADO));
chequear('la llave del contrato en 4 cuotas de 20 M + 31 % - 1 %', [26000000.0, 26000000.0, 26000000.0, 26000000.0],
    array_column($ll, 'importe'));
chequear('con origen Contrato cargado', LlavesCalculo::CONTRATO, $ll[0]['origen']);
$ot = llPagos($r, LlavesCalculo::OTROS);
chequear('comisiones + FPC: un pago, el día de la primera cuota (la firma)', [1, '2027-01-04', 32500000.0],
    [count($ot), $ot[0]['fecha'] ?? null, $ot[0]['importe'] ?? null]);
chequear('la renovación cargada se identifica por el contrato que renueva', '78|22', $ll[0]['clave']);

seccion('LlavesCalculo: llave = 1 es "tiene llave, monto desconocido"');

$e = llEntrada();
$e['locales'][78]['contratos'][] = llContrato(96, '2027-01-01', '2027-12-31', 1.0);
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('una renovación cargada con llave 1 se estima con sus meses (12), nunca $ 1',
    round(0.25 * 18927137.0 * 12, 2), round(array_sum(array_column($ll, 'importe')), 2));
chequear('y el tooltip lo explica', true, strpos($ll[0]['detalle'] ?? '', 'llave = 1') !== false);

$e = llEntrada();
$e['locales'][78]['contratos'] = [llContrato(87, '2025-12-10', '2026-12-31', 1.0)];
$ll = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE);
chequear('un vigente con llave 1 estima la renovación con sus 13 meses', round(0.25 * 18927137.0 * 13, 2),
    round(array_sum(array_column($ll, 'importe')), 2));

seccion('LlavesCalculo: qué locales estiman');

$e = llEntrada();
$e['locales'][78]['contratos'] = [llContrato(78, '2025-09-05', '2026-12-31', 0.0, 147979.0)];
$e['par'] = llPar(['operadores' => ['SAARCO' => ['pct_otros' => 40.0, 'escribano' => 500000.0]]]);
$r = LlavesCalculo::proyectar($e);
chequear('vigente sin llave: no estima llave (criterio de Costo de Ocupación)', [], llPagos($r, LlavesCalculo::LLAVE));
chequear('pero sí los otros gastos (el escribano; el % no tiene sobre qué)', [500000.0],
    array_column(llPagos($r, LlavesCalculo::OTROS, LlavesCalculo::ESTIMADO), 'importe'));

$e = llEntrada();
$e['locales'][78]['vigencia'] = true;
$r = LlavesCalculo::proyectar($e);
chequear('un local que proyecta según vigencia queda afuera', [LlavesCalculo::POR_VIGENCIA, []],
    [$r['locales'][78]['estado'], $r['pagos']]);

$e = llEntrada();
$e['locales'][78]['contratos'] = [];
$r = LlavesCalculo::proyectar($e);
chequear('sin contratos en la app: no proyecta y avisa', [LlavesCalculo::SIN_CONTRATO, true],
    [$r['locales'][78]['estado'], strpos(llTextos($r), 'Sin ningún contrato en la app de alquileres: DISTRITO ARCOS') !== false]);

seccion('LlavesCalculo: el contrato vencido sin renovar va al mes en curso');

$e = llEntrada();
$e['locales'][78]['contratos'] = [llContrato(8, '2023-07-01', '2026-09-30', 6300000.0)];
$r = LlavesCalculo::proyectar($e);
$ll = llPagos($r, LlavesCalculo::LLAVE);
chequear('estado Vencido sin renovar', LlavesCalculo::VENCIDO, $r['locales'][78]['estado']);
chequear('la primera cuota, el primer hábil después de hoy', '2026-11-24', $ll[0]['fecha'] ?? null);
chequear('la base es el alquiler del mes en curso', round(0.25 * 2000000.0 * LlavesCalculo::meses('2023-07-01', '2026-09-30') / 4, 2),
    $ll[0]['importe'] ?? null);
chequear('la segunda, en diciembre, el día siguiente al fin', '2026-12-01', $ll[1]['fecha'] ?? null);
chequear('aviso de atención con el texto pedido', true,
    strpos(llTextos($r), 'El contrato de DISTRITO ARCOS venció el 30/09/2026; si se renovó, cargá el contrato nuevo en la app de alquileres') !== false);

seccion('LlavesCalculo: No renueva');

$e = llEntrada(['par' => llPar(['locales' => ['78|22' => ['no_renueva' => true, 'motivo_no_renueva' => 'cierra']]])]);
$r = LlavesCalculo::proyectar($e);
chequear('no proyecta nada y el estado es No renueva', [[], LlavesCalculo::NO_RENUEVA], [$r['pagos'], $r['locales'][78]['estado']]);

seccion('LlavesCalculo: sin alquiler en el mes o sin razón social, null con aviso');

$e = llEntrada();
unset($e['alquiler'][78]['2027-01']);
$r = LlavesCalculo::proyectar($e);
chequear('sin alquiler proyectado en el mes, no hay llave', [], llPagos($r, LlavesCalculo::LLAVE));
chequear('queda en no_proyecta, sin importe inventado', [null, true],
    [array_key_exists('importe', $r['no_proyecta'][0] ?? []) ? $r['no_proyecta'][0]['importe'] : 'x', strpos($r['no_proyecta'][0]['motivo'] ?? '', 'sin alquiler') === 0]);
chequear('con su aviso', true, strpos(llTextos($r), 'Sin alquiler proyectado en Alquileres para el mes de la primera cuota: DISTRITO ARCOS (enero de 2027)') !== false);

$e = llEntrada();
$e['locales'][78]['cod_provee'] = null;
$r = LlavesCalculo::proyectar($e);
chequear('sin razón social no se proyecta nada', [], $r['pagos']);
chequear('y se avisa con el link a Parámetros › Alquileres', true, strpos(llTextos($r), 'Sin razón social en Parámetros › Alquileres: DISTRITO ARCOS') !== false);

seccion('LlavesCalculo: los otros gastos del operador');

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_otros' => 40.0, 'escribano' => 500000.0]]])]);
$ot = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::OTROS);
chequear('% × llave estimada + escribano, un pago en la firma', [round(0.40 * $esperada, 2) + 500000.0, '2027-01-04'],
    [$ot[0]['importe'] ?? null, $ot[0]['fecha'] ?? null]);

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['escribano' => 500000.0]]])]);
$r = LlavesCalculo::proyectar($e);
chequear('sin %: sólo el escribano, y avisa', [[500000.0], true],
    [array_column(llPagos($r, LlavesCalculo::OTROS), 'importe'), strpos(llTextos($r), 'Operador sin % de otros gastos') !== false]);

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_otros' => 40.0]]])]);
$r = LlavesCalculo::proyectar($e);
chequear('sin escribano: la parte del % se proyecta, el escribano no se suma, y avisa',
    [[round(0.40 * $esperada, 2)], true],
    [array_column(llPagos($r, LlavesCalculo::OTROS), 'importe'), strpos(llTextos($r), 'Operador sin escribano') !== false]);

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_otros' => 40.0, 'escribano' => 0.0]]])]);
$r = LlavesCalculo::proyectar($e);
chequear('un escribano en 0 cargado es 0, y no avisa', false, strpos(llTextos($r), 'Operador sin escribano') !== false);

seccion('LlavesCalculo: el segmento por m², en los bordes');

chequear('84 con corte 84 es chico', 'CHICO', LlavesCalculo::segmento(84.0, 84.0, 110.0));
chequear('84,01 es mediano', 'MEDIANO', LlavesCalculo::segmento(84.01, 84.0, 110.0));
chequear('110 es mediano', 'MEDIANO', LlavesCalculo::segmento(110.0, 84.0, 110.0));
chequear('110,01 es grande', 'GRANDE', LlavesCalculo::segmento(110.01, 84.0, 110.0));
chequear('sin m², null (no chico)', null, LlavesCalculo::segmento(null, 84.0, 110.0));
chequear('sin cortes confirmados, null', null, LlavesCalculo::segmento(100.0, null, 110.0));
chequear('los terciles de los m² de hoy (17 locales): ~84 y ~110', ['chico' => 83.94, 'mediano' => 110.46],
    LlavesCalculo::terciles([59.6, 60, 60.83, 66.6, 76, 83.71, 84.4, 84.5, 105.8, 107, 108.17, 111.61, 112.9, 113.1,
                             132.6, 151.24, 244.25]));

seccion('LlavesCalculo: la obra y la apertura en U$S');

$curva = ['2027-03' => ['clave' => '2027-03', 'simbolo' => 'DLR032027', 'cotizacion' => 1500.0]];
$gen = ['CORTE_CHICO' => 84.0, 'CORTE_MEDIANO' => 110.0, 'USD_OBRA_GRANDE' => 10000.0, 'USD_APERTURA_MEDIANO' => 50000.0];
$e = llEntrada(['curva' => $curva, 'par' => llPar(['generales' => $gen,
    'obras' => [['id' => 1, 'nro' => 78, 'mes' => '2027-03', 'segmento' => null, 'costo_usd' => null, 'observacion' => null],
                ['id' => 2, 'nro' => 78, 'mes' => '2027-08', 'segmento' => null, 'costo_usd' => 20000.0, 'observacion' => null]],
    'aperturas' => [['id' => 7, 'nombre' => 'Nuevo Córdoba', 'nro' => null, 'mes' => '2027-03', 'segmento' => 'MEDIANO',
                     'costo_usd' => null, 'observacion' => null]]])]);
$r = LlavesCalculo::proyectar($e);
$ob = llPagos($r, LlavesCalculo::OBRA);
chequear('obra de un grande por m² (151 m²): U$S 10.000 × 1.500, el 15/3', [15000000.0, '2027-03-15'],
    [$ob[0]['importe'] ?? null, $ob[0]['fecha'] ?? null]);
chequear('el tooltip dice el costo en dólares y la cotización', true,
    strpos($ob[0]['detalle'], 'U$S 10.000,00') !== false && strpos($ob[0]['detalle'], '1.500,00') !== false);
chequear('con costo propio y mes sin curva: el mes más cercano, aproximado', [30000000.0, true],
    [$ob[1]['importe'] ?? null, $ob[1]['tc_aproximado'] ?? null]);
chequear('el tooltip lo dice', true, strpos($ob[1]['detalle'], 'APROXIMADO') !== false);
$ap = llPagos($r, LlavesCalculo::APERTURA);
chequear('la apertura con el costo de su segmento', [75000000.0, 'Nuevo Córdoba'], [$ap[0]['importe'] ?? null, $ap[0]['local'] ?? null]);

$e['curva'] = [];
$r = LlavesCalculo::proyectar($e);
chequear('sin curva: no proyecta (null) y avisa con los dólares', [[], true],
    [llPagos($r, LlavesCalculo::OBRA), strpos(llTextos($r), 'Sin dólar futuro para el mes: DISTRITO ARCOS (Obra de imagen, 2027-03, U$S 10.000,00)') !== false]);

$e = llEntrada(['curva' => $curva, 'par' => llPar(['generales' => $gen,
    'obras' => [['id' => 1, 'nro' => 78, 'mes' => '2027-03', 'segmento' => null, 'costo_usd' => null, 'observacion' => null]]])]);
$e['locales'][78]['m2'] = null;
$r = LlavesCalculo::proyectar($e);
chequear('un local sin m² ni segmento no proyecta la obra y avisa', [[], true],
    [llPagos($r, LlavesCalculo::OBRA), strpos(llTextos($r), 'cargá los m² en el Gestor de Locales o el segmento a mano') !== false]);
$e['par']['obras'][0]['segmento'] = 'GRANDE';
chequear('con el segmento a mano, sí', [15000000.0], array_column(llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::OBRA), 'importe'));
$e['par']['obras'][0]['segmento'] = 'CHICO';
$r = LlavesCalculo::proyectar($e);
chequear('un segmento sin costo en U$S no proyecta y avisa', [[], true],
    [llPagos($r, LlavesCalculo::OBRA), strpos(llTextos($r), 'Segmento sin costo en U$S: OBRA chico') !== false]);

seccion('LlavesCalculo: los compromisos a mano, sueltos y en serie');

chequear('una serie de 3 desde el 31/1: el último día de los meses cortos',
    ['2027-01-31', '2027-02-28', '2027-03-31'], array_column(LlavesParametros::serie('2027-01-31', 100.0, 3), 'fecha'));
$comp = [];

foreach (LlavesParametros::serie('2026-12-10', 1000000.0, 4) as $c) {
    $comp[] = ['id' => 10 + $c['nro'], 'tipo' => 'ALQUILERES', 'cod_provee' => 'SAUNIC', 'nro' => 79, 'concepto' => 'Prenda',
               'fecha' => $c['fecha'], 'monto' => 1000000.0, 'forma' => 'ECHEQ', 'observacion' => null, 'id_serie' => 11,
               'cuota_nro' => $c['nro'], 'cuotas' => 4];
}

$comp[] = ['id' => 20, 'tipo' => 'PROVEEDOR', 'cod_provee' => 'SAUNIS', 'nro' => null, 'concepto' => 'Servicio comercial',
           'fecha' => '2026-11-20', 'monto' => 5.0, 'forma' => null, 'observacion' => null, 'id_serie' => null,
           'cuota_nro' => null, 'cuotas' => null];
$e = llEntrada(['par' => llPar(['compromisos' => $comp]), 'locales' => []]);
$cm = llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::COMPROMISO);
chequear('la prenda en 4 cuotas: las 4, con su fecha y su monto tal cual', [4, ['2026-12-10', '2027-01-10', '2027-02-10', '2027-03-10']],
    [count($cm), array_column($cm, 'fecha')]);
chequear('con origen Manual', LlavesCalculo::MANUAL, $cm[0]['origen']);

seccion('LlavesCalculo: "ya pagada" y las facturas cortan');

$e = llEntrada(['par' => llPar(['pagadas' => [['id' => 1, 'nro' => 78, 'id_contrato_ant' => 22, 'concepto' => 'LLAVE',
                                              'fecha' => '2026-08-31', 'monto' => 180525000.0]],
                               'operadores' => ['SAARCO' => ['pct_otros' => 40.0, 'escribano' => 0.0]]])]);
$r = LlavesCalculo::proyectar($e);
chequear('ya pagada la llave: no se estima', [], llPagos($r, LlavesCalculo::LLAVE));
chequear('los otros gastos no se cortan (otro concepto)', 1, count(llPagos($r, LlavesCalculo::OTROS)));

$e['locales'][78]['contratos'][] = llContrato(95, '2027-01-01', '2029-12-31', 80000000.0);
chequear('la marca sigue valiendo cuando se carga el contrato nuevo', [],
    llPagos(LlavesCalculo::proyectar($e), LlavesCalculo::LLAVE));

$fact = ['id' => 5, 'nro' => 78, 'concepto' => 'LLAVE', 'ant' => 22, 'contrato' => null, 'mes' => '2027-01',
         'pagada' => false, 'cod_provee' => 'SAARCO', 'comprobante' => 'FAC A0001400000427', 'texto' => '',
         'pagos' => [['fecha' => '2027-01-15', 'importe' => 90000000.0], ['fecha' => '2027-02-15', 'importe' => 90525000.0]]];
$e = llEntrada(['facturas' => [$fact]]);
$r = LlavesCalculo::proyectar($e);
chequear('la factura reemplaza toda la llave estimada de esa renovación', [], llPagos($r, LlavesCalculo::LLAVE, LlavesCalculo::ESTIMADO));
chequear('y paga lo suyo, como real, en sus fechas', [['2027-01-15', 90000000.0], ['2027-02-15', 90525000.0]],
    array_map(function ($p) { return [$p['fecha'], $p['importe']]; }, llPagos($r, LlavesCalculo::LLAVE, LlavesCalculo::FACTURA)));
$fact['pagada'] = true;
$r = LlavesCalculo::proyectar(llEntrada(['facturas' => [$fact]]));
chequear('una factura ya pagada no suma, pero corta la estimación', [[], []],
    [llPagos($r, LlavesCalculo::LLAVE, LlavesCalculo::FACTURA), llPagos($r, LlavesCalculo::LLAVE, LlavesCalculo::ESTIMADO)]);
$fact['concepto'] = 'OTROS';
$fact['pagada'] = false;
$r = LlavesCalculo::proyectar(llEntrada(['facturas' => [$fact]]));
chequear('una factura de otro concepto no corta la llave', 4, count(llPagos($r, LlavesCalculo::LLAVE, LlavesCalculo::ESTIMADO)));

seccion('LlavesCalculo: los pagos de hoy o anteriores no se proyectan');

$e = llEntrada(['par' => llPar(['operadores' => ['SAARCO' => ['pct_impuestos' => 0.0]]])]);
$e['locales'][78]['contratos'] = [llContrato(91, '2026-10-20', '2029-10-19', 80000000.0)];
$r = LlavesCalculo::proyectar($e);
chequear('del contrato del 20/10, sólo las cuotas de diciembre y enero', ['2026-12-21', '2027-01-20'],
    array_column(llPagos($r, LlavesCalculo::LLAVE), 'fecha'));
chequear('las de octubre y noviembre, ya pasadas, se avisan', true,
    strpos(llTextos($r), 'cuota 1 del 20/10/2026') !== false && strpos(llTextos($r), 'cuota 2 del 20/11/2026') !== false);
$e['locales'][78]['contratos'] = [llContrato(91, '2026-08-20', '2029-08-19', 80000000.0)];
chequear('una cuota de septiembre (antes del mes pasado) no se avisa', false,
    strpos(llTextos(LlavesCalculo::proyectar($e)), 'cuota 1 del 20/08/2026') !== false);
$e['par']['operadores']['SAARCO']['pct_impuestos'] = null;
$e['locales'][78]['contratos'] = [llContrato(30, '2020-01-01', '2023-01-14', 2200000.0),
                                  llContrato(31, '2023-01-15', '2029-01-14', 0.0)];
$r = LlavesCalculo::proyectar($e);
chequear('un contrato viejo con llave no genera avisos de impuestos ni de cuotas pasadas', [false, false, []],
    [strpos(llTextos($r), 'Cuotas de contratos firmados') !== false, strpos(llTextos($r), 'impuestos') !== false, $r['pagos']]);

seccion('LlavesSugeridos: el % de llave y de otros gastos por operador, con las renovaciones de la app');

require_once __DIR__ . '/../Class/LlavesSugeridos.php';

$contr = [
    6 => [llContrato(5, '2023-01-15', '2026-01-14', 8000000.0, 2100000.0, 750000.0),
          llContrato(79, '2026-01-15', '2029-01-14', 75000000.0, 29452304.0, 7500000.0)],
    /* Un contrato nuevo 183 días después: no es una renovación. */
    29 => [llContrato(16, '2021-12-01', '2024-11-30', 0.0), llContrato(41, '2025-06-01', '2029-05-31', 35000000.0, 1454003.0)],
    /* La marca de 1 no mide nada. */
    60 => [llContrato(76, '2024-02-01', '2026-01-31', 0.0), llContrato(81, '2026-02-01', '2029-01-31', 1.0, 1968736.0)]
];
$sug = LlavesSugeridos::porOperador([6 => 'SAPALA', 29 => 'SAROSA', 60 => 'SAUNIC'], $contr, [6 => ['2026-01' => 7491362.0]],
    [6 => 'AVELLANEDA']);
chequear('Avellaneda: 75 M / (7.491.362 × 36) = 27,81 %', 27.81, $sug['SAPALA']['pct_llave'] ?? null);
chequear('otros gastos: (29,45 M + 7,5 M) / 75 M = 49,27 %', 49.27, $sug['SAPALA']['pct_otros'] ?? null);
chequear('183 días de hueco no es una renovación', false, isset($sug['SAROSA']));
chequear('la llave = 1 no mide', false, isset($sug['SAUNIC']));

seccion('LlavesSugeridos: el % de impuestos, con la relación factura / contrato de Tango');

$fac = [
    ['COD_PROVEE' => 'SAPALA', 'T_COMP' => 'FAC', 'N_COMP' => 'A0003000011896', 'FECHA_EMIS' => '2025-10-24', 'IMPORTE' => 98250000.0, 'LEYENDA' => 'CUOTA INICIAL L6'],
    ['COD_PROVEE' => 'SAUNIC', 'T_COMP' => 'FAC', 'N_COMP' => 'A0193000139196', 'FECHA_EMIS' => '2025-08-19', 'IMPORTE' => 140466429.95, 'LEYENDA' => 'LLAVE L2'],
    ['COD_PROVEE' => 'SAELSE', 'T_COMP' => 'FAC', 'N_COMP' => 'A1', 'FECHA_EMIS' => '2025-07-01', 'IMPORTE' => 6352500.0, 'LEYENDA' => 'CONTRAPRESTACION ADIC L53'],
    ['COD_PROVEE' => 'SAELSE', 'T_COMP' => 'FAC', 'N_COMP' => 'A2', 'FECHA_EMIS' => '2025-08-01', 'IMPORTE' => 6352500.0, 'LEYENDA' => 'CONTRAPRESTACION ADIC CTA2 L53'],
    ['COD_PROVEE' => 'SAELSE', 'T_COMP' => 'FAC', 'N_COMP' => 'A3', 'FECHA_EMIS' => '2025-09-01', 'IMPORTE' => 6352500.0, 'LEYENDA' => 'CONTRAPRESTACION ADIC CTA3 L53'],
    ['COD_PROVEE' => 'SAELSE', 'T_COMP' => 'FAC', 'N_COMP' => 'A4', 'FECHA_EMIS' => '2025-10-01', 'IMPORTE' => 6352500.0, 'LEYENDA' => 'CONTRAPRESTACION ADIC CTA4 L53'],
    ['COD_PROVEE' => 'SAPALA', 'T_COMP' => 'FAC', 'N_COMP' => 'X', 'FECHA_EMIS' => '2025-10-24', 'IMPORTE' => 8594759.22, 'LEYENDA' => 'SELLADO L6']
];
$con = $contr + [2 => [llContrato(40, '2025-08-08', '2029-08-07', 102000000.0, 38409912.0)],
                 53 => [llContrato(37, '2025-07-01', '2029-06-30', 21000000.0, 50000000.0)]];
$imp = LlavesSugeridos::impuestos($fac, $con);
chequear('IRSA: cuota inicial L6 98,25 M sobre llave 75 M = 31 %', 31.0, $imp['SAPALA']['pct'] ?? null);
chequear('el sellado no es una llave', 1, count($imp['SAPALA']['cruces']));
chequear('Cencosud: LLAVE L2 = llave + comisiones, sin impuestos (0 %)', 0.0, $imp['SAUNIC']['pct'] ?? null);
chequear('Caballito: las 4 CTA juntas, 25,41 M / 21 M = 21 %', 21.0, $imp['SAELSE']['pct'] ?? null);
