<?php
/**
 * Corporativas: los seguros y sus polizas.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LAS FACTURAS DEL SEGURO Y LA POLIZA SUMAN LAS DOS. Es el mismo pago
 *      contado dos veces, en cuotas y como poliza.
 *
 *   2. LA CUOTA SE DIVIDE POR LA VIGENCIA Y NO POR LOS PAGOS. Con 12 meses y 2 de
 *      gracia, el total se paga en 10, no en 12: dividir por 12 proyecta de menos.
 *
 *   3. LA CONTINUACION SE AJUSTA COMPUESTA, O CON LA INFLACION EQUIVOCADA. Es la
 *      suma de los meses de la vigencia anterior, como un ajuste pactado.
 *
 *   4. LA POLIZA SIGUIENTE NO CORTA LA CONTINUACION de la anterior: el seguro se
 *      cuenta dos veces desde el inicio de la nueva.
 *
 * Todo lo de aca es PURO.
 */

require_once __DIR__ . '/../Class/TarjetasSeguros.php';
require_once __DIR__ . '/../Class/Inflacion.php';
require_once __DIR__ . '/../Class/Providers/TarjetasProvider.php';
require_once __DIR__ . '/../Class/Horizonte.php';

function lanzaConSG($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

$HABSG = [];

for ($d = new DateTime('2026-10-01'); $d <= new DateTime('2029-01-31'); $d->modify('+1 day')) {
    $HABSG[$d->format('Y-m-d')] = intval($d->format('N')) <= 5;
}

$MSG = [];

for ($m = '2026-11'; $m <= '2028-12'; $m = Inflacion::mesMas($m, 1)) {
    $MSG[] = $m;
}

$HSG = '2026-10-31';
$INFSG = [];

for ($m = '2026-10'; $m <= '2028-12'; $m = Inflacion::mesMas($m, 1)) {
    $INFSG[$m] = 2.0;
}

/* La tarjeta corporativa vence el 10, con 10 % de cobertura. */
$TSG = [7 => ['ID' => 7, 'TIPO' => 'CORPORATIVA', 'ACTIVA' => true, 'DIA_VENCIMIENTO' => 10,
              'PCT_COBERTURA' => 10.0, 'ROTULO' => '•••• 1234'],
        8 => ['ID' => 8, 'TIPO' => 'SUPERVISORA', 'ACTIVA' => true, 'DIA_VENCIMIENTO' => 10,
              'PCT_COBERTURA' => 0.0, 'ROTULO' => '•••• 5555']];

$SEG = TarjetasSeguros::proveedores([
    'OGRSA' => ['NOMBRE' => 'SEGUROS SURA', 'RUBRO' => 'Seguros', 'FORMA_PAGO' => 'TARJETA CORP'],
    'OGZURI' => ['NOMBRE' => 'ZURICH', 'RUBRO' => 'SEGUROS', 'FORMA_PAGO' => null],
    'OGMESE' => ['NOMBRE' => 'LA MERIDIONAL', 'RUBRO' => 'LOCALES', 'FORMA_PAGO' => 'TARJETA CORP'],
    'OGASEG' => ['NOMBRE' => 'ASEGURADORA', 'RUBRO' => 'SEGUROS', 'FORMA_PAGO' => 'TRANSFERENCIA']
]);

/** Una poliza armada a mano */
function polizaSG($id, $total, $moneda, $inicio, $vig, $gracia, $primer, $poliza = 'P-100') {
    return ['ID' => $id, 'COD_PROVEE' => 'OGRSA', 'RAZON_SOC' => 'SEGUROS SURA', 'POLIZA' => $poliza,
            'ID_TARJETA' => 7, 'IMPORTE_TOTAL' => $total, 'MONEDA' => $moneda,
            'FECHA_INICIO' => $inicio, 'VIGENCIA_MESES' => $vig, 'GRACIA_MESES' => $gracia,
            'MES_PRIMER_PAGO' => $primer];
}

/** Los datos del formulario de una poliza */
function datosSG($inicio, $vig, $gracia, $primer, $extra = []) {
    return array_merge(['proveedor' => 'OGRSA', 'poliza' => 'P-100', 'id_tarjeta' => 7,
        'importe_total' => 1200000, 'moneda' => 'ARS', 'fecha_inicio' => $inicio,
        'vigencia_meses' => $vig, 'gracia_meses' => $gracia, 'mes_primer_pago' => $primer], $extra);
}

function pagosSG($p, $extra = []) {
    global $TSG, $HABSG, $MSG, $HSG, $INFSG;

    $o = array_merge(['resumenes' => [], 'inflacion' => $INFSG, 'curva' => [], 'corte' => null,
                      'hoy' => $HSG, 'tarjeta' => $TSG[7]], $extra);

    return TarjetasSeguros::pagos($p, $o['tarjeta'], $o['resumenes'], $HABSG, $MSG, $o['hoy'],
        $o['inflacion'], $o['curva'], $o['corte']);
}

/** Mes => importe de los pagos */
function porMesSG($r) {
    $v = [];

    foreach ($r['pagos'] as $p) {
        $v[$p['mes']] = ($p['importe'] === null) ? null : round($p['importe'], 2);
    }

    return $v;
}

/* ================================================================
   QUIEN ES UN SEGURO
   ================================================================ */
seccion('un seguro es rubro SEGUROS y TARJETA CORP en el maestro');

chequear('solo el que cumple las dos cosas', ['OGRSA'], array_keys($SEG));
chequear('el rubro no distingue mayusculas', true, TarjetasSeguros::esSeguro(' seguros ', 'TARJETA CORP'));
chequear('sin la forma de pago del maestro no es un seguro', false,
    TarjetasSeguros::esSeguro('SEGUROS', null));

$rf = TarjetasSeguros::resumenFacturas([
    ['COD_PROVEE' => 'OGRSA', 'T_COMP' => 'FAC', 'N_COMP' => 'A1', 'IMPORTE_PENDIENTE' => 100],
    ['COD_PROVEE' => 'OGRSA', 'T_COMP' => 'FAC', 'N_COMP' => 'A1', 'IMPORTE_PENDIENTE' => 100],
    ['COD_PROVEE' => 'OGRSA', 'T_COMP' => 'FAC', 'N_COMP' => 'A2', 'IMPORTE_PENDIENTE' => 50]]);

chequear('la linea informativa: vencimientos, facturas e importe', [3, 2, 250.0],
    [$rf['cuantas'], $rf['comprobantes'], $rf['importe']]);

/* ================================================================
   LA POLIZA: CUOTA, PAGOS, PRIMER PAGO DISTINTO DEL INICIO
   ================================================================ */
seccion('12 meses con 2 de gracia y el primer pago dos meses despues del inicio');

$a = polizaSG(1, 1200000, 'ARS', '2026-11-01', 12, 2, '2027-01');
$ra = pagosSG($a, ['corte' => '2027-11-01']);

chequear('la cuota es el total dividido por 10', 120000.0, TarjetasSeguros::cuota(1200000, 12, 2));
chequear('son 10 pagos, de enero a octubre', ['2027-01', '2027-10', 10],
    [$ra['pagos'][0]['mes'], $ra['pagos'][9]['mes'], count($ra['pagos'])]);
chequear('cada uno de 120.000', [120000.0], array_values(array_unique(array_column($ra['pagos'], 'importe'))));
chequear('en el debito de la tarjeta de cada mes', ['2027-01-11', '2027-02-10'],
    [$ra['pagos'][0]['fecha'], $ra['pagos'][1]['fecha']]);
chequear('la vigencia termina el dia antes del aniversario', '2027-10-31',
    TarjetasSeguros::finVigencia('2026-11-01', 12));

seccion('la validacion de la poliza, en el servidor');

$okA = TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01'), $SEG, $TSG, []);

chequear('una poliza bien cargada pasa, normalizada', ['OGRSA', 'P-100', 12, 2, '2027-01', 'ARS'],
    [$okA['COD_PROVEE'], $okA['POLIZA'], $okA['VIGENCIA_MESES'], $okA['GRACIA_MESES'],
     $okA['MES_PRIMER_PAGO'], $okA['MONEDA']]);

lanzaConSG('el ultimo pago despues de la vigencia: rechazado, y dice por que', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 0, '2027-01'), $SEG, $TSG, []);
}, 'la vigencia termina el 31/10/2027');

lanzaConSG('un proveedor que no es seguro', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01', ['proveedor' => 'OGMESE']), $SEG, $TSG, []);
}, 'no es un seguro');

lanzaConSG('una tarjeta que no es corporativa', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01', ['id_tarjeta' => 8]), $SEG, $TSG, []);
}, 'tarjeta corporativa activa');

lanzaConSG('la gracia tiene que ser menor que la vigencia', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 3, 3, '2026-11'), $SEG, $TSG, []);
}, 'no quedaría ningún pago');

lanzaConSG('el importe mayor a cero', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01', ['importe_total' => 0]), $SEG, $TSG, []);
}, 'mayor a cero');

lanzaConSG('la moneda, $ o U$S', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01', ['moneda' => 'EUR']), $SEG, $TSG, []);
}, '$ o U$S');

lanzaConSG('el numero de poliza es obligatorio', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01', ['poliza' => '  ']), $SEG, $TSG, []);
}, 'Falta el número');

lanzaConSG('el primer pago no antes del inicio', function () use ($SEG, $TSG) {
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2026-10'), $SEG, $TSG, []);
}, 'anterior al mes de inicio');

seccion('las vigencias no se pisan');

$otras = [polizaSG(1, 1200000, 'ARS', '2026-11-01', 12, 2, '2027-01')];

lanzaConSG('superpuesta con otra del mismo proveedor y numero: rechazada', function () use ($SEG, $TSG, $otras) {
    TarjetasSeguros::validar(datosSG('2027-06-01', 12, 2, '2027-08', ['poliza' => 'p-100']), $SEG, $TSG, $otras);
}, 'se superpone');

chequear('la siguiente, desde el fin de la anterior, entra', '2027-11-01',
    TarjetasSeguros::validar(datosSG('2027-11-01', 12, 2, '2028-01'), $SEG, $TSG, $otras)['FECHA_INICIO']);
chequear('otro numero del mismo proveedor puede superponerse', 'P-200',
    TarjetasSeguros::validar(datosSG('2027-06-01', 12, 2, '2027-08', ['poliza' => 'P-200']), $SEG, $TSG, $otras)['POLIZA']);
chequear('la correccion de la misma poliza no choca consigo misma', 'P-100',
    TarjetasSeguros::validar(datosSG('2026-11-01', 12, 2, '2027-01'), $SEG, $TSG, $otras, 1)['POLIZA']);

/* ================================================================
   LA CONTINUACION EN PESOS: 1, 3 Y 12 MESES, INFLACION SUMADA
   ================================================================ */
seccion('la continuacion en pesos, con la inflacion sumada de la vigencia anterior');

$r1 = porMesSG(pagosSG(polizaSG(2, 100000, 'ARS', '2026-11-01', 1, 0, '2026-11')));

chequear('vigencia de 1 mes: cada mes el anterior por la inflacion de su mes',
    [100000.0, 102000.0, 104040.0], [$r1['2026-11'], $r1['2026-12'], $r1['2027-01']]);
chequear('y sigue hasta el fin del horizonte', true, isset($r1['2028-12']));

$r3 = porMesSG(pagosSG(polizaSG(3, 300000, 'ARS', '2026-11-01', 3, 0, '2026-11')));

chequear('vigencia de 3 meses: el ciclo sube la SUMA de los 3 meses, 6 % y no 6,12 %',
    [100000.0, 100000.0, 106000.0, 106000.0, 112360.0],
    [$r3['2026-11'], $r3['2027-01'], $r3['2027-02'], $r3['2027-04'], $r3['2027-05']]);

$r12 = porMesSG(pagosSG($a));

chequear('vigencia de 12 meses: el ciclo siguiente sube la suma de los 12, 24 %',
    [120000.0, 148800.0, 148800.0], [$r12['2027-10'], $r12['2028-01'], $r12['2028-10']]);
chequear('la gracia sigue al final del ciclo: noviembre y diciembre sin pagos', [false, false],
    [isset($r12['2027-11']), isset($r12['2027-12'])]);

$cont = pagosSG($a)['pagos'];

chequear('los pagos de la continuacion se marcan', [false, true],
    [$cont[0]['continuacion'], $cont[10]['continuacion']]);
chequear('y el tooltip lo dice', true, strpos($cont[10]['tooltip'], 'ESTIMADA') !== false);

seccion('si falta la inflacion de un mes, ese ciclo queda en null con aviso');

$sinMar = $INFSG;
unset($sinMar['2027-03']);
$rf = pagosSG(polizaSG(2, 100000, 'ARS', '2026-11-01', 1, 0, '2026-11'), ['inflacion' => $sinMar]);
$rfm = porMesSG($rf);

chequear('marzo se proyecta, abril (ajustado por marzo) queda en null y los siguientes tambien',
    [false, null, null], [$rfm['2027-03'] === null, $rfm['2027-04'], $rfm['2027-05']]);
chequear('dice que mes falta', ['2027-03'], $rf['faltan_inflacion']);
chequear('el estado lo dice', TarjetasSeguros::SIN_INFLACION, $rf['pagos'][5]['estado']);

seccion('la poliza siguiente corta la continuacion');

$sig = TarjetasSeguros::siguientes([$a, polizaSG(9, 1500000, 'ARS', '2027-11-01', 12, 2, '2028-01')]);

chequear('la siguiente es la del mismo proveedor y numero, desde el fin', [1 => '2027-11-01', 9 => null], $sig);
chequear('con la siguiente cargada, la anterior no se continua', '2027-10',
    max(array_keys(porMesSG(pagosSG($a, ['corte' => $sig[1]])))));

$corte1 = porMesSG(pagosSG(polizaSG(2, 100000, 'ARS', '2026-11-01', 1, 0, '2026-11'), ['corte' => '2027-03-01']));

chequear('una de un mes se continua hasta el mes anterior al inicio de la siguiente', '2027-02',
    max(array_keys($corte1)));

/* ================================================================
   EN DOLARES
   ================================================================ */
seccion('en dolares: cada pago con la curva del mes de su fecha');

$curva = ['2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26', 'cotizacion' => 1600.0],
          '2026-12' => ['clave' => '2026-12', 'simbolo' => 'DLR/DIC26', 'cotizacion' => 1650.0]];
$u = pagosSG(polizaSG(4, 1200, 'USD', '2026-11-01', 12, 0, '2026-11'), ['curva' => $curva]);

chequear('noviembre con la curva de noviembre', [100.0, 1600.0, 160000.0],
    [round($u['pagos'][0]['usd'], 2), $u['pagos'][0]['tc'], round($u['pagos'][0]['importe'], 2)]);
chequear('diciembre con la de diciembre', 165000.0, round($u['pagos'][1]['importe'], 2));
chequear('mas alla de la curva, el mes mas cercano, y lo dice', [1650.0, true],
    [$u['pagos'][2]['tc'], strpos($u['pagos'][2]['tooltip'], 'APROXIMADO') !== false]);

$uc = porMesSG(pagosSG(polizaSG(4, 1200, 'USD', '2026-11-01', 12, 0, '2026-11'), ['curva' => $curva]));

chequear('la continuacion en dolares repite el mismo importe en U$S', 165000.0, $uc['2027-11']);

$sinCurva = pagosSG(polizaSG(4, 1200, 'USD', '2026-11-01', 12, 0, '2026-11'));

chequear('sin curva: null y aviso, nunca cero', [null, TarjetasSeguros::SIN_COTIZACION, true],
    [$sinCurva['pagos'][0]['importe'], $sinCurva['pagos'][0]['estado'],
     in_array('2026-11', $sinCurva['faltan_cotizacion'], true)]);

/* ================================================================
   COMO UNA FACTURA VINCULADA: COBERTURA, RESUMEN, FECHA PASADA
   ================================================================ */
seccion('cada pago se comporta como una factura vinculada');

$res = ['2027-02' => ['ID' => 1, 'MES' => '2027-02', 'IMPORTE_ARS' => 9.0,
                      'FECHA_VENCIMIENTO' => '2027-02-09', 'PAGADO' => false]];
$rr = pagosSG($a, ['resumenes' => $res, 'corte' => '2027-11-01']);

chequear('el resumen cargado de la tarjeta lo pisa', [TarjetasSeguros::CUBIERTA, false],
    [$rr['pagos'][1]['estado'], $rr['pagos'][1]['proyecta']]);

$filasCob = TarjetasSeguros::comoFilas([array_merge($a, ['resueltos' => $rr])]);
$cob = TarjetasCorporativas::cobertura($filasCob, [7 => $TSG[7]], [7 => $res], $HABSG, $MSG, $HSG);

chequear('genera cobertura con el % de su tarjeta', 12000.0, round($cob[0]['importe'], 2));
chequear('y no en el mes del resumen', false, in_array('2027-02', array_column($cob, 'mes'), true));

$pas = pagosSG(polizaSG(5, 120000, 'ARS', '2026-10-01', 12, 0, '2026-10'), ['hoy' => '2026-11-15']);

chequear('un pago con fecha hasta hoy no se proyecta', [TarjetasSeguros::PASADA, TarjetasSeguros::PROYECTA],
    [$pas['pagos'][0]['estado'], $pas['pagos'][1]['estado']]);

$inact = pagosSG($a, ['tarjeta' => array_merge($TSG[7], ['ACTIVA' => false])]);

chequear('con la tarjeta inactiva no proyecta', TarjetasSeguros::SIN_TARJETA, $inact['pagos'][0]['estado']);

/* ================================================================
   LA INFLACION SUMADA
   ================================================================ */
seccion('Inflacion::sumada suma N meses sin componer');

chequear('12 meses al 2 %', 24.0, Inflacion::sumada($INFSG, '2026-11', 12)['pct']);
chequear('un mes', 2.0, Inflacion::sumada($INFSG, '2026-11', 1)['pct']);
chequear('un mes que falta da null y dice cual', [null, ['2029-01']],
    [Inflacion::sumada($INFSG, '2028-12', 2)['pct'], Inflacion::sumada($INFSG, '2028-12', 2)['faltan']]);

/* ================================================================
   LA PESTANA Y EL TABLERO
   ================================================================ */
seccion('las facturas de seguros quedan fuera y los seguros suman en CORPORATIVAS');

$fuentePT = file_get_contents(__DIR__ . '/../Class/PagosTarjetas.php');

chequear('las facturas de seguros se separan antes de resolver', true,
    strpos($fuentePT, '$facturas = $sinSeguros;') !== false);
chequear('un seguro no proyecta por proveedor', true,
    strpos($fuentePT, '$marcados = array_diff_key($marcados, $seguros);') !== false);
chequear('los pagos de las polizas van al eje como SEGURO', true,
    strpos($fuentePT, "'tipo' => 'SEGURO'") !== false);
chequear('y entran en la cobertura', true,
    strpos($fuentePT, 'TarjetasSeguros::comoFilas($salida[\'seguros\'][\'polizas\'])') !== false);

class PagosTarjetasDobleSG {
    public $d;
    function __construct($d) { $this->d = $d; }
    public function calcular($h) { return $this->d; }
}

class TarjetasProviderDobleSG extends TarjetasProvider {
    public $doble;
    protected function modulo() { return $this->doble; }
}

$pagosCorp = [['tipo' => 'FACTURA', 'fecha' => '2026-11-10', 'importe' => 1000.0]];

foreach ($ra['pagos'] as $pg) {
    $pagosCorp[] = ['tipo' => 'SEGURO', 'fecha' => $pg['fecha'], 'importe' => $pg['importe'],
                    'id_poliza' => 1, 'mes' => $pg['mes'], 'poliza' => 'P-100',
                    'proveedor' => 'SEGUROS SURA', 'continuacion' => $pg['continuacion']];
}

$datosSG = [
    'hoy' => $HSG, 'meses' => $MSG,
    'tablas' => ['tarjetas' => true, 'resumen' => true, 'factura' => true, 'exclusion' => true,
                 'gastos' => true, 'vista_usuarios' => true],
    'tarjetas' => [], 'avisos' => [],
    'supervisoras' => ['pagos' => [['parte' => 'EFECTIVO', 'supervisora' => 'ANA', 'mes' => '2026-11',
        'fecha' => '2026-11-16', 'importe' => 500.0]], 'avisos' => [], 'filas' => [], 'sin_tarjeta' => 0.0],
    'corporativas' => ['pagos' => $pagosCorp, 'pagos_excluidos' => [], 'avisos' => [], 'filas' => [],
                       'cobertura' => [], 'resumenes' => []],
    'socios' => ['pagos' => [['id_tarjeta' => 30, 'mes' => '2026-12', 'fecha' => '2026-12-15',
        'importe' => 700.0, 'origen' => 'ESTIMACION']], 'avisos' => [], 'filas' => [], 'cartel' => '']
];

$prov = new TarjetasProviderDobleSG('TARJETAS');
$prov->doble = new PagosTarjetasDobleSG($datosSG);
$s = $prov->series(new Horizonte(28, 26, [], new DateTime($HSG)));
$malas = [];

foreach (['dias', 'meses'] as $rama) {
    foreach ($s['TOTAL'][$rama] as $k => $v) {
        if (abs($v - ($s['SUPERVISORAS'][$rama][$k] + $s['CORPORATIVAS'][$rama][$k]
                      + $s['SOCIOS'][$rama][$k])) > 0.001) {
            $malas[] = $rama . '|' . $k;
        }
    }
}

$totalSG = array_sum($s['TOTAL']['dias']) + array_sum($s['TOTAL']['meses']);

chequear('el invariante se cumple con los seguros adentro', [], $malas);
chequear('los seguros suman en CORPORATIVAS, sin fila propia', 1000.0 + 1200000.0,
    array_sum($s['CORPORATIVAS']['dias']) + array_sum($s['CORPORATIVAS']['meses']));
chequear('y el total es la suma de las tres', 1000.0 + 1200000.0 + 500.0 + 700.0, $totalSG);
chequear('la celda anotada dice que es un seguro', true,
    strpos(json_encode($s['TOTAL']['detalle'], JSON_UNESCAPED_UNICODE), 'Seguro SEGUROS SURA, póliza P-100') !== false);

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script de las polizas');

$sqlSG = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_polizas.sql');

chequear('crea la tabla dentro de una guarda', true,
    strpos($sqlSG, "OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_POLIZA', 'U') IS NULL") !== false);
chequear('la gracia menor que la vigencia, tambien en la base', true,
    strpos($sqlSG, 'CHECK (GRACIA_MESES >= 0 AND GRACIA_MESES < VIGENCIA_MESES)') !== false);

foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
    chequear("no tiene $prohibido", false, stripos($sqlSG, $prohibido) !== false);
}

foreach (['USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF', 'USUARIO_BAJA', 'FECHA_BAJA'] as $c) {
    chequear("lleva $c", true, strpos($sqlSG, $c) !== false);
}
