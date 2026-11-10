<?php
/**
 * Los conceptos de alquiler de un local, mes a mes (Class/AlquileresCalculo.php
 * y Class/AlquileresConceptos.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL AJUSTE CAE EN OTRO MES. Trimestral con septiembre ajusta en
 *      diciembre, marzo, junio y septiembre. Correrlo un mes no cambia el total
 *      del año pero mueve plata de un mes a otro del cashflow.
 *
 *   2. EL AJUSTE SE COMPONE O NO CORRE LA BASE. Igual que el valor hora de
 *      Logistica: la ventana se SUMA sin componer y cada ajuste va sobre el
 *      valor que dejo el anterior. Con inflacion constante los errores se
 *      tapan; por eso todo va con inflacion variable.
 *
 *   3. FALTA UN MES DE INFLACION Y EL ALQUILER DESAPARECE. Se aplica lo que hay
 *      y se avisa: un alquiler no deja de pagarse por un dato faltante.
 *
 *   4. LAS EXPENSAS SE PROYECTAN CON UN PICO. La mediana de las variaciones
 *      ignora el mes raro; un cero no da -100 %.
 *
 *   5. UN VARIABLE DA NEGATIVO. Por debajo del VMA (o del 9) el resultado es 0,
 *      nunca negativo: un negativo restaria alquiler del cashflow.
 *
 *   6. EL 14 SE CALCULA SOBRE LA VENTA. Es sobre el 7 ya neto del VMA.
 *
 *   7. LA JERARQUIA SE INVIERTE. Un mes cerrado se lee tal cual, aunque la
 *      venta real diga otra cosa.
 *
 * No toca la base.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresCalculo.php';

/** Inflacion variable de 2026-07 a 2027-12: 1, 2, 3, 1, 2, 3... */
function inflacionVariable() {
    $mapa = [];
    $mes = '2026-07';

    for ($i = 0; $i < 18; $i++) {
        $mapa[$mes] = (float) (($i % 3) + 1);
        $mes = Inflacion::mesMas($mes, 1);
    }

    return $mapa;
}

$inf = inflacionVariable();
/* jul 1, ago 2, sep 3, oct 1, nov 2, dic 3, ene 1, feb 2, mar 3, abr 1, may 2,
   jun 3, jul 1, ago 2, sep 3, oct 1, nov 2, dic 3 */

seccion('AlquileresConceptos: una sola lista, por ID_CA');

chequear('los fijos son 1, 2, 3 y 8', [1, 2, 3, 8], AlquileresConceptos::delGrupo(AlquileresConceptos::FIJOS));
chequear('el fondo es el 9', [9], AlquileresConceptos::delGrupo(AlquileresConceptos::FONDO));
chequear('las expensas son el 11', [11], AlquileresConceptos::delGrupo(AlquileresConceptos::EXPENSAS));
chequear('los variables son 6, 7, 14, 16 y 17', [6, 7, 14, 16, 17], AlquileresConceptos::delGrupo(AlquileresConceptos::VARIABLES));
chequear('no se proyectan 10, 12, 13 y 15', [10, 12, 13, 15], AlquileresConceptos::delGrupo(AlquileresConceptos::NO_PROYECTA));
chequear('quedan fuera 4, 5 y 18', [4, 5, 18], AlquileresConceptos::delGrupo(AlquileresConceptos::FUERA));
chequear('los 18 conceptos de la app tienen grupo', 18, count(AlquileresConceptos::GRUPO));
chequear('se proyectan once', [1, 2, 3, 6, 7, 8, 9, 11, 14, 16, 17], AlquileresConceptos::proyectados());
chequear('solo los variables se difieren', [false, true, false],
    [AlquileresConceptos::seDifiere(8), AlquileresConceptos::seDifiere(7), AlquileresConceptos::seDifiere(11)]);

seccion('Fijos: en que meses ajusta cada frecuencia');

$ajustes = function ($frec, $mesAjuste) {
    $meses = [];
    for ($m = '2026-10'; $m <= '2027-09'; $m = Inflacion::mesMas($m, 1)) {
        if (AlquileresCalculo::ajusta($m, $frec, $mesAjuste)) {
            $meses[] = $m;
        }
    }
    return $meses;
};

chequear('mensual: todos los meses', 12, count($ajustes(1, null)));
chequear('bimestral con agosto: oct, dic, feb, abr, jun, ago',
    ['2026-10', '2026-12', '2027-02', '2027-04', '2027-06', '2027-08'], $ajustes(2, 8));
chequear('trimestral con septiembre: dic, mar, jun, sep',
    ['2026-12', '2027-03', '2027-06', '2027-09'], $ajustes(3, 9));
chequear('semestral con enero: ene y jul', ['2027-01', '2027-07'], $ajustes(6, 1));
chequear('sin mes del ultimo ajuste, un trimestral no ajusta', [], $ajustes(3, null));

seccion('Fijos: suma sin componer y la base se corre');

$base = [1 => 0.0, 2 => 0.0, 3 => 1000.0, 8 => 100000.0];

/* Mensual desde sep-26: oct 1 %, nov 2 %, dic 3 %, cada uno sobre el anterior. */
$m = AlquileresCalculo::fijos($base, '2026-09', '2026-12', 1, null, $inf);
chequear('mensual oct: 100000 x 1,01', 101000.0, $m['meses']['2026-10']['valores'][8]);
chequear('mensual nov: sobre octubre x 1,02', 103020.0, $m['meses']['2026-11']['valores'][8]);
chequear('mensual dic: sobre noviembre x 1,03', 106110.6, $m['meses']['2026-12']['valores'][8]);
chequear('la baulera ajusta igual', 1061.106, $m['meses']['2026-12']['valores'][3]);

/* Bimestral con agosto: oct = sep + oct = 4 %; dic = nov + dic = 5 %. */
$b = AlquileresCalculo::fijos($base, '2026-09', '2027-01', 2, 8, $inf);
chequear('bimestral oct: sep 3 + oct 1 = 4 %', 4.0, $b['meses']['2026-10']['pct']);
chequear('bimestral oct: 104000', 104000.0, $b['meses']['2026-10']['valores'][8]);
chequear('bimestral nov: no ajusta, queda igual', 104000.0, $b['meses']['2026-11']['valores'][8]);
chequear('bimestral dic: nov 2 + dic 3 = 5 %, sobre 104000', 109200.0, $b['meses']['2026-12']['valores'][8]);

/* Trimestral con septiembre: dic = oct + nov + dic = 6 %; mar = ene + feb + mar = 6 %. */
$t = AlquileresCalculo::fijos($base, '2026-09', '2027-03', 3, 9, $inf);
chequear('trimestral oct y nov no ajustan', [100000.0, 100000.0],
    [$t['meses']['2026-10']['valores'][8], $t['meses']['2026-11']['valores'][8]]);
chequear('trimestral dic: la ventana es oct, nov, dic', ['2026-10', '2026-11', '2026-12'], $t['meses']['2026-12']['ventana']);
chequear('trimestral dic: 6 % sumado, no 6,11 % compuesto', 106000.0, $t['meses']['2026-12']['valores'][8]);
chequear('trimestral mar: 6 % sobre 106000 (la base se corre)', 112360.0, $t['meses']['2027-03']['valores'][8]);

/* Semestral con enero: ene-27 = ago..ene = 2+3+1+2+3+1 = 12 %. */
$s = AlquileresCalculo::fijos($base, '2026-09', '2027-07', 6, 1, $inf);
chequear('semestral ene: suma de ago a ene, 12 %', 12.0, $s['meses']['2027-01']['pct']);
chequear('semestral ene: 112000', 112000.0, $s['meses']['2027-01']['valores'][8]);
chequear('semestral jul: feb..jul = 12 % sobre 112000', 125440.0, $s['meses']['2027-07']['valores'][8]);

seccion('Fijos: un ajuste entre el ultimo cerrado y hoy se aplica');

/* Ultimo cerrado sep-26, trimestral con octubre: ajusta en oct-26 (antes de
   hoy, nov-26) con ago + sep + oct = 6 %. El valor de noviembre ya lo trae. */
$h = AlquileresCalculo::fijos($base, '2026-09', '2026-11', 3, 10, $inf);
chequear('oct-26 ajusta aunque ya paso', true, $h['meses']['2026-10']['ajusta']);
chequear('noviembre trae el ajuste de octubre', 106000.0, $h['meses']['2026-11']['valores'][8]);

seccion('Fijos: falta la inflacion de un mes');

$sinNov = $inf;
unset($sinNov['2026-11']);
$f = AlquileresCalculo::fijos($base, '2026-09', '2026-12', 3, 9, $sinNov);
chequear('se aplica lo que hay: oct 1 + dic 3 = 4 %', 104000.0, $f['meses']['2026-12']['valores'][8]);
chequear('y dice que falta noviembre', ['2026-11'], $f['faltan']);
chequear('el aviso nombra el mes', true,
    strpos(Inflacion::avisoFaltan($f['faltan'], '2026-11-10'), '2026-11') !== false);

seccion('Fondo de promocion (9)');

chequear('con %9: VMA x %9, como la app', ['importe' => 15000.0, 'regla' => 'PCT'],
    AlquileresCalculo::fondo(100000.0, 15.0, 9000.0, 90000.0, 9999.0));
chequear('sin %9: la proporcion del ultimo cerrado', ['importe' => 10000.0, 'regla' => 'PROPORCION'],
    AlquileresCalculo::fondo(100000.0, null, 9000.0, 90000.0, 9999.0));
chequear('sin %9 y VMA 0: como un fijo', ['importe' => 9999.0, 'regla' => 'FIJO'],
    AlquileresCalculo::fondo(0.0, null, 9000.0, 0.0, 9999.0));

seccion('Expensas: la mediana de las variaciones');

/* 24 meses: crece 2 % todos los meses, salvo un pico en un mes que vuelve. */
$serie = [];
$v = 1000000.0;
for ($mes = '2024-10'; $mes <= '2026-09'; $mes = Inflacion::mesMas($mes, 1)) {
    $serie[$mes] = $v;
    $v *= 1.02;
}
$serie['2025-06'] *= 3;  // un mes con expensa extraordinaria

$te = AlquileresCalculo::tasaExpensas($serie, '2026-09');
chequear('24 meses con dato', 24, $te['meses']);
chequear('23 variaciones', 23, $te['variaciones']);
chequear('la mediana ignora el pico: 2 %', 0.02, round($te['tasa'], 10));
chequear('octubre: la de septiembre x 1,02', round($serie['2026-09'] * 1.02, 2),
    round(AlquileresCalculo::expensa($serie['2026-09'], $te['tasa'], 1), 2));
chequear('a tres meses, mes a mes, al centavo', round($serie['2026-09'] * 1.02 * 1.02 * 1.02, 2),
    round(AlquileresCalculo::expensa($serie['2026-09'], $te['tasa'], 3), 2));

$conCeros = $serie;
$conCeros['2025-03'] = 0.0;
unset($conCeros['2025-04']);
$tc = AlquileresCalculo::tasaExpensas($conCeros, '2026-09');
chequear('un cero y un hueco no rompen: siguen 22 meses con dato', 22, $tc['meses']);
chequear('y no generan variaciones de -100 % ni infinitas', 0.02, round($tc['tasa'], 10));

$corta = [];
$v = 500000.0;
for ($mes = '2026-02'; $mes <= '2026-09'; $mes = Inflacion::mesMas($mes, 1)) {
    $corta[$mes] = $v;
    $v *= 1.05;
}
$tCorta = AlquileresCalculo::tasaExpensas($corta, '2026-09');
chequear('con 8 meses', 8, $tCorta['meses']);

$general = AlquileresCalculo::tasaGeneral([
    ['tasa' => 0.01, 'meses' => 24], ['tasa' => 0.03, 'meses' => 12],
    ['tasa' => 0.02, 'meses' => 20], $tCorta
]);
chequear('la general es la mediana de los de 12 meses o mas (el de 8 no entra)', 0.02, $general);

$usada = AlquileresCalculo::tasaDelLocal($tCorta, $general);
chequear('el local de 8 meses usa la general', ['tasa' => 0.02, 'meses' => 8, 'origen' => 'GENERAL'], $usada);
chequear('uno de 24 usa la suya', 'LOCAL', AlquileresCalculo::tasaDelLocal($te, $general)['origen']);
chequear('la mediana de una lista par promedia los del medio', 2.5, AlquileresCalculo::mediana([4, 1, 3, 2]));

seccion('Variables: las formulas de la app');

$pct = [6 => 7.0, 7 => 8.0, 14 => 5.0, 16 => 1.05, 17 => 0.8];

$arriba = AlquileresCalculo::variables(60000000.0, 50000000.0, $pct, 1000000.0, 100000.0);
chequear('6 por encima del VMA: 60M x 7 % - 1M', 3200000.0, $arriba[6]['importe']);
chequear('7 por encima del VMA: 50M x 8 % - 1M', 3000000.0, $arriba[7]['importe']);
chequear('14 sobre el 7 ya neto: 3M x 5 %, no 50M x 5 %', 150000.0, $arriba[14]['importe']);
chequear('16 por encima del 9: 60M x 1,05 % - 100000', 530000.0, $arriba[16]['importe']);
chequear('17 por encima del 9: 50M x 0,8 % - 100000', 300000.0, $arriba[17]['importe']);
chequear('el 6 dice la venta y el % que uso', [60000000.0, 7.0], [$arriba[6]['venta'], $arriba[6]['pct']]);

$abajo = AlquileresCalculo::variables(10000000.0, 8000000.0, $pct, 1000000.0, 100000.0);
chequear('6 por debajo del VMA: 0, nunca negativo', 0.0, $abajo[6]['importe']);
chequear('7 por debajo del VMA: 0', 0.0, $abajo[7]['importe']);
chequear('14 con el 7 en 0: 0', 0.0, $abajo[14]['importe']);
chequear('16 por debajo del 9: 105000 - 100000 = 5000', 5000.0, $abajo[16]['importe']);
chequear('17 por debajo del 9: 0', 0.0, AlquileresCalculo::variables(1.0, 1000000.0, $pct, 0.0, 100000.0)[17]['importe']);

$sinPct = AlquileresCalculo::variables(60000000.0, 50000000.0, [6 => 7.0], 1000000.0, 0.0);
chequear('un local sin % de un concepto no lo proyecta', [6], array_keys($sinPct));
chequear('sin venta, no hay variables', [], AlquileresCalculo::variables(null, null, $pct, 1.0, 1.0));

/* Contra un cierre real de la app: Tortugas (11), 9-2026. Bruta 54.424.087
   (3.809.686 / 7 %), VMA 2.676.331, 9 = 401.449: 6 = 1.133.355; 16 =
   571.453 - 401.449 = 170.004 (la app trunca: 170.003). */
$tortugas = AlquileresCalculo::variables(3809686 / 0.07, null, [6 => 7.0, 16 => 1.05], 2676331.0, 401449.0);
chequear('Tortugas 9-2026, concepto 6, como la app', 1133355.0, round($tortugas[6]['importe']));
chequear('Tortugas 9-2026, concepto 16, a un peso de la app', true, abs($tortugas[16]['importe'] - 170003) <= 1.5);

seccion('La jerarquia: que pisa a que');

$cerrados = [
    '2026-08' => [1 => 0.0, 8 => 1000000.0, 9 => 150000.0, 11 => 500000.0, 7 => 77.0, 4 => 999.0, 13 => 5.0],
    '2026-09' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 8 => 1000000.0, 9 => 150000.0, 11 => 500000.0, 7 => 0.0]
];
$entrada = [
    'meses' => ['2026-09', '2026-10', '2026-11'],
    'cerrados' => $cerrados,
    'frecuencia' => 1,
    'mes_ajuste' => null,
    'pct' => [7 => 8.0, 9 => 15.0],
    'inflacion' => $inf,
    'tasa_expensas' => ['tasa' => 0.02, 'meses' => 24, 'origen' => 'LOCAL'],
    'ventas' => [
        '2026-09' => ['bruta' => 99e9, 'neta' => 99e9, 'origen' => 'REAL'],
        '2026-10' => ['bruta' => 24200000.0, 'neta' => 20000000.0, 'origen' => 'REAL'],
        '2026-11' => ['bruta' => 30250000.0, 'neta' => 25000000.0, 'origen' => 'ESTIMADA']
    ]
];
$p = AlquileresCalculo::proyectarLocal($entrada);

chequear('el ultimo cerrado es sep-26', '2026-09', $p['ultimo_cerrado']);
chequear('sep-26 cerrado gana sobre la venta real', AlquileresCalculo::APP_CERRADA, $p['meses']['2026-09']['origen']);
chequear('y se toma tal cual, aunque la venta diga otra cosa', 0.0, $p['meses']['2026-09']['conceptos'][7]['importe']);
chequear('oct-26 tiene venta real: origen Venta real', AlquileresCalculo::VENTA_REAL, $p['meses']['2026-10']['origen']);
chequear('oct-26: VMA ajustado 1 %', 1010000.0, $p['meses']['2026-10']['conceptos'][8]['importe']);
chequear('oct-26: 9 = VMA x 15 %', 151500.0, $p['meses']['2026-10']['conceptos'][9]['importe']);
chequear('oct-26: 7 = 20M x 8 % - VMA del mismo mes', 590000.0, $p['meses']['2026-10']['conceptos'][7]['importe']);
chequear('oct-26: expensa x 1,02', 510000.0, $p['meses']['2026-10']['conceptos'][11]['importe']);
chequear('nov-26 con venta estimada: origen Estimado', AlquileresCalculo::ESTIMADO, $p['meses']['2026-11']['origen']);
chequear('nov-26: expensa a dos meses', 520200.0, $p['meses']['2026-11']['conceptos'][11]['importe']);

$conFuera = AlquileresCalculo::proyectarLocal(['meses' => ['2026-08']] + $entrada);
chequear('de un cerrado no se toman el 4 ni el 13', false,
    isset($conFuera['meses']['2026-08']['conceptos'][4]) || isset($conFuera['meses']['2026-08']['conceptos'][13]));

/* Un periodo abierto nunca se lee: los cerrados que recibe la funcion son
   solo los cerrados (AlquileresApp::detalleCerrados), y un mes abierto
   anterior al ultimo cerrado no se estima ni se inventa. */
$hueco = AlquileresCalculo::proyectarLocal(['meses' => ['2026-07']] + $entrada);
chequear('un mes abierto anterior al ultimo cerrado no tiene dato', 'ABIERTO_ANTERIOR', $hueco['meses']['2026-07']['motivo']);
chequear('y no tiene conceptos', [], $hueco['meses']['2026-07']['conceptos']);

$nuevo = AlquileresCalculo::proyectarLocal(['cerrados' => []] + $entrada);
chequear('un local sin ningun cerrado no proyecta fijos ni expensas', 'SIN_CERRADO', $nuevo['meses']['2026-11']['motivo']);

$sinVenta = $entrada;
$sinVenta['ventas'] = [];
$sv = AlquileresCalculo::proyectarLocal($sinVenta);
chequear('sin venta: los fijos igual se proyectan', 1030200.0, $sv['meses']['2026-11']['conceptos'][8]['importe']);
chequear('sin venta: no hay variables y se dice por que', ['SIN_VENTA', false],
    [$sv['meses']['2026-11']['motivo'], isset($sv['meses']['2026-11']['conceptos'][7])]);
