<?php
/**
 * Corporativas: la proyeccion por proveedor, que reemplaza a "marcar mensual".
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. SE REPITEN FACTURAS DE DOS MESES. La de octubre todavia no se pago y ya se
 *      cargo la de noviembre: si las dos se repiten, el abono se cuenta dos veces
 *      todos los meses del horizonte.
 *
 *   2. UNA SIN VINCULAR CORTA LA REPETICION. No suma, asi que cortar dejaria el
 *      mes sin nada: no corta, y se avisa para que la vinculen.
 *
 *   3. LA ESTIMACION NO SE COMPORTA COMO UNA FACTURA VINCULADA. Sin cobertura, o
 *      sumando arriba del resumen cargado, el mes se cuenta mal.
 *
 * Todo lo de aca es PURO.
 */

require_once __DIR__ . '/../Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../Class/TarjetasProvProyecta.php';

$HABP = [];

for ($d = new DateTime('2026-08-01'); $d <= new DateTime('2027-03-31'); $d->modify('+1 day')) {
    $HABP[$d->format('Y-m-d')] = intval($d->format('N')) <= 5;
}

$MP = ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'];
$HP = '2026-09-26';

/* La tarjeta vence el 10, con 10 % de cobertura. */
$TP = [7 => ['ID' => 7, 'TIPO' => 'CORPORATIVA', 'PCT_COBERTURA' => 10.0, 'DIA_VENCIMIENTO' => 10,
             'ACTIVA' => true, 'ROTULO' => '•••• 1234'],
       8 => ['ID' => 8, 'TIPO' => 'CORPORATIVA', 'PCT_COBERTURA' => 0.0, 'DIA_VENCIMIENTO' => 10,
             'ACTIVA' => false, 'ROTULO' => '•••• 9999']];

$PROV = ['OGABO' => ['COD_PROVEE' => 'OGABO', 'RAZON_SOC' => 'ABONO SA']];

function fpTC($n, $vto, $importe, $cod = 'OGABO') {
    return ['COD_PROVEE' => $cod, 'RAZON_SOC' => 'ABONO SA', 'T_COMP' => 'FAC', 'N_COMP' => $n,
            'FECHA_VTO' => $vto, 'IMPORTE_PENDIENTE' => $importe, 'IMPORTE_VTO' => $importe,
            'FORMA_PAGO_VIGENTE' => 'TARJETA CORP'];
}

/** Resuelve las facturas y devuelve las estimaciones de los proveedores marcados */
function estimarPP($facturas, $vinculos, $excluidas = [], $resumenes = [], $prov = null) {
    global $TP, $HABP, $MP, $HP, $PROV;

    $v = [];

    foreach ($vinculos as $n => $t) {
        foreach ($facturas as $f) {
            if ($f['N_COMP'] === $n) {
                $v[TarjetasCorporativas::clave($f)] = $t;
            }
        }
    }

    $ex = [];

    foreach ($excluidas as $n) {
        foreach ($facturas as $f) {
            if ($f['N_COMP'] === $n) {
                $ex[TarjetasCorporativas::clave($f)] = ['MOTIVO' => 'x'];
            }
        }
    }

    $r = TarjetasCorporativas::resolver($facturas, $v, $ex, $TP, $resumenes, $HABP, $MP, $HP);

    return ['filas' => $r['filas'],
            'est' => TarjetasCorporativas::estimacionesPorProveedor($prov === null ? $PROV : $prov,
                $r['filas'], $TP, $resumenes, $HABP, $MP, $HP)['estimaciones']];
}

/** Lo que proyecta cada mes, sumando todas las estimaciones */
function porMesPP($est) {
    $v = [];

    foreach ($est as $e) {
        foreach ($e['meses'] as $m) {
            if ($m['proyecta']) {
                $v[$m['mes']] = (isset($v[$m['mes']]) ? $v[$m['mes']] : 0) + $m['importe'];
            }
        }
    }

    return $v;
}

/* ================================================================
   LOS EJEMPLOS DE LA ESPECIFICACION
   ================================================================ */
seccion('dos facturas pendientes en el mismo mes: se repiten las dos');

$r = estimarPP([fpTC('A1', '2026-10-05', 100000), fpTC('A2', '2026-10-06', 50000)],
    ['A1' => 7, 'A2' => 7]);

chequear('octubre son las facturas reales, y suman', [true, true],
    [$r['filas'][0]['PROYECTA'], $r['filas'][1]['PROYECTA']]);
chequear('desde noviembre se repiten las dos: 150.000 por mes',
    ['2026-11' => 150000.0, '2026-12' => 150000.0, '2027-01' => 150000.0, '2027-02' => 150000.0],
    porMesPP($r['est']));
chequear('en el vencimiento de su tarjeta', '2026-11-10', $r['est'][0]['meses'][0]['fecha']);
chequear('con el importe de la cuota, sin inflacion', 100000.0, $r['est'][0]['meses'][3]['importe']);

seccion('facturas de dos meses pendientes: cada una cuenta en su mes, se repite la ultima');

$r = estimarPP([fpTC('A1', '2026-10-05', 100000), fpTC('B1', '2026-11-05', 120000)],
    ['A1' => 7, 'B1' => 7]);

chequear('se repite solo la de noviembre', ['B1'], array_column($r['est'], 'N_COMP'));
chequear('desde diciembre, por su importe: el abono no se duplica',
    ['2026-12' => 120000.0, '2027-01' => 120000.0, '2027-02' => 120000.0], porMesPP($r['est']));

seccion('el ultimo mes es el del vencimiento ORIGINAL de Tango, no el corregido');

/* Dos boletas: una vence el 28/09 y otra el 21/10. Las dos tienen el vencimiento
   corregido al debito del resumen, el 02/11. Con el corregido, las dos serian de
   noviembre y se repetirian las dos; con el de Tango, el ultimo mes es octubre y
   se repite solo la del 21/10. La correccion sigue moviendo su fecha de pago. */
$bSep = fpTC('L1', '2026-09-28', 1000000);
$bOct = fpTC('L2', '2026-10-21', 400000);
$corrPP = [];

foreach ([$bSep, $bOct] as $b) {
    $corrPP[TarjetasCorporativas::claveCuota($b)] = ['FECHA_VTO' => '2026-11-02', 'MOTIVO' => null,
        'USUARIO_ALTA' => 'u', 'FECHA_ALTA' => '2026-10-01 10:00'];
}

$rc = TarjetasCorporativas::resolver([$bSep, $bOct],
    [TarjetasCorporativas::clave($bSep) => 7, TarjetasCorporativas::clave($bOct) => 7], [], $TP, [],
    $HABP, $MP, $HP, $corrPP);
$estC = TarjetasCorporativas::estimacionesPorProveedor($PROV, $rc['filas'], $TP, [], $HABP, $MP,
    $HP)['estimaciones'];

chequear('se repite solo la del ultimo mes de Tango', ['L2'], array_column($estC, 'N_COMP'));
chequear('la correccion sigue valiendo para su fecha de pago: el debito de noviembre',
    ['2026-11-10', '2026-11'], [$rc['filas'][1]['FECHA'], $rc['filas'][1]['MES_PAGO']]);
chequear('y se repite desde el mes siguiente a ese pago', '2026-12', $estC[0]['MES_DESDE']);

seccion('una factura cancelada deja de proyectar');

/* SE PAGO: sale de pendientes, y con ella sus estimaciones. Hasta que se cargue la
   siguiente no hay proyeccion: es lo esperado. */
chequear('sin pendientes no hay estimaciones', [],
    estimarPP([], [])['est']);

seccion('una excluida corta la repeticion pero no se repite');

$r = estimarPP([fpTC('A1', '2026-10-05', 100000), fpTC('B1', '2026-11-05', 120000)],
    ['A1' => 7, 'B1' => 7], ['B1']);

chequear('la de noviembre excluida: no se repite nada', [], $r['est']);

$r = estimarPP([fpTC('A1', '2026-10-05', 100000), fpTC('A2', '2026-10-06', 50000)],
    ['A1' => 7, 'A2' => 7], ['A2']);

chequear('en el mismo mes, solo se repite la que no esta excluida', ['A1'],
    array_column($r['est'], 'N_COMP'));

seccion('una sin vincular no corta la repeticion, y se avisa');

$r = estimarPP([fpTC('A1', '2026-10-05', 100000), fpTC('B1', '2026-11-05', 120000)],
    ['A1' => 7]);

chequear('la de noviembre sin vincular no corta: sigue la de octubre', ['A1'],
    array_column($r['est'], 'N_COMP'));
chequear('y no suma, asi que no duplica nada', false, $r['filas'][1]['PROYECTA']);
chequear('el aviso dice que hacer',
    ['ABONO SA tiene una factura sin vincular; vinculala para que reemplace la proyección.'],
    TarjetasCorporativas::avisosPorProveedor($PROV, $r['filas']));
chequear('una no vinculada no se repite nunca', [],
    estimarPP([fpTC('A1', '2026-10-05', 100000)], [])['est']);

seccion('solo los proveedores marcados');

chequear('un proveedor sin marcar no proyecta', [],
    estimarPP([fpTC('A1', '2026-10-05', 100000)], ['A1' => 7], [], [], [])['est']);

/* ================================================================
   SE COMPORTA COMO UNA FACTURA VINCULADA
   ================================================================ */
seccion('la estimacion se comporta como una factura vinculada');

$r = estimarPP([fpTC('A1', '2026-08-05', 100000)], ['A1' => 7]);

/* VENCIDA: se reubica al 12/10 (proximo pago), y se repite desde noviembre. */
chequear('una vencida se repite desde el mes siguiente a su pago', '2026-11',
    $r['est'][0]['MES_DESDE']);

$res = [7 => ['2026-12' => ['ID' => 5, 'MES' => '2026-12', 'IMPORTE_ARS' => 900000.0,
                            'FECHA_VENCIMIENTO' => '2026-12-09', 'PAGADO' => false]]];
$r = estimarPP([fpTC('A1', '2026-10-05', 100000)], ['A1' => 7], [], $res);
$dic = $r['est'][0]['meses'][1];

chequear('el resumen cargado la pisa: cubierta y no suma', ['2026-12', 'CUBIERTA', false],
    [$dic['mes'], $dic['estado'], $dic['proyecta']]);

$cob = TarjetasCorporativas::cobertura(TarjetasCorporativas::estimacionesComoFilas($r['est']),
    $TP, $res, $HABP, $MP, $HP);

chequear('genera cobertura con el % de su tarjeta', 10000.0, $cob[0]['importe']);
chequear('y no en el mes del resumen', false, in_array('2026-12', array_column($cob, 'mes'), true));

/* UNA ESTIMACION CON FECHA HASTA HOY NO SE PROYECTA. Con hoy a fin de octubre, la
   del 10/10 de la factura de septiembre ya paso. */
$pasada = TarjetasCorporativas::estimacionesPorProveedor($PROV,
    TarjetasCorporativas::resolver([fpTC('A1', '2026-09-05', 1000)],
        [TarjetasCorporativas::clave(fpTC('A1', '2026-09-05', 1000)) => 7], [], $TP, [], $HABP,
        ['2026-09', '2026-10', '2026-11'], '2026-09-01')['filas'],
    $TP, [], $HABP, ['2026-09', '2026-10', '2026-11'], '2026-10-15')['estimaciones'];

chequear('una estimacion con fecha hasta hoy no se proyecta', ['PASADA', 'PROYECTA'],
    array_column($pasada[0]['meses'], 'estado'));

$r = estimarPP([fpTC('A1', '2026-10-05', 100000)], ['A1' => 8]);

chequear('si su tarjeta esta inactiva, no proyecta y se avisa', [false, 1],
    [$r['est'] ? $r['est'][0]['TARJETA_OK'] : null,
     count(TarjetasCorporativas::avisosEstimaciones($r['est']))]);

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script de los proveedores que proyectan');

$sqlPP = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_prov_proyecta.sql');

chequear('crea la tabla dentro de una guarda', true,
    strpos($sqlPP, "OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA', 'U') IS NULL") !== false);
chequear('uno vigente por proveedor', true,
    strpos($sqlPP, 'ON dbo.RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA (COD_PROVEE)') !== false);
chequear('no toca la tabla de marcas mensuales', false,
    strpos($sqlPP, 'UPDATE dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL') !== false);

foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
    chequear("no tiene $prohibido", false, stripos($sqlPP, $prohibido) !== false);
}

foreach (['USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF', 'USUARIO_BAJA', 'FECHA_BAJA'] as $c) {
    chequear("lleva $c", true, strpos($sqlPP, $c) !== false);
}

seccion('la migracion de las marcas mensuales');

chequear('migra las marcas vigentes con usuario MIGRACION', true,
    strpos($sqlPP, "SELECT m.COD_PROVEE, MAX(m.RAZON_SOC), 1, 'MIGRACION', 'MIGRACION'") !== false
    && strpos($sqlPP, 'WHERE m.VIGENTE = 1') !== false);
chequear('una por proveedor, aunque tenga dos marcas', true,
    strpos($sqlPP, 'GROUP BY m.COD_PROVEE') !== false);
chequear('reejecutable: no revive un proveedor que alguien desmarco', true,
    strpos($sqlPP, "(p.VIGENTE = 1 OR p.USUARIO_ALTA = 'MIGRACION')") !== false);
