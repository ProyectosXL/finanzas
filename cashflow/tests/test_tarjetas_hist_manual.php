<?php
/**
 * Gastos Supervisoras: quien tiene tarjeta Supervisora y no carga gastos en la
 * app de supervision, y su historico manual.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA PERSONA NO APARECE. El universo eran los nombres con gastos: alguien
 *      del equipo de ventas con tarjeta no tenia fila, y su gasto no estaba en
 *      ningun lado.
 *
 *   2. EL HISTORICO SE PROYECTA DISTINTO QUE UNA SUPERVISORA. Dividido por los
 *      meses con datos, sin inflacion, o desde otro mes base, daria un numero
 *      que no se puede comparar con el de las demas.
 *
 *   3. HISTORICO Y GASTOS SE SUMAN. Cuando la persona empieza a cargar gastos,
 *      el mismo gasto se contaria dos veces.
 *
 *   4. ALGUIEN FUERA DEL MAESTRO DE SUPERVISORAS QUE CARGA GASTOS NO PROYECTA.
 *      Con tarjeta Supervisora activa cuenta como activa.
 *
 * Todo lo de aca es PURO.
 */

require_once __DIR__ . '/../Class/TarjetasSupervisoras.php';
require_once __DIR__ . '/../Class/TarjetasHistManual.php';

function lanzaConHM($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

$HOY = '2026-10-31';

/* ================================================================
   LA VALIDACION DE LOS TRES MESES
   ================================================================ */
seccion('el historico manual son tres meses cerrados');

$tres = [['mes' => '2026-09', 'importe' => 300000], ['mes' => '2026-07', 'importe' => 0],
         ['mes' => '2026-08', 'importe' => 150000.5]];

chequear('entran, en orden cronologico, y un mes en cero es un dato',
    ['2026-07' => 0.0, '2026-08' => 150000.5, '2026-09' => 300000.0],
    TarjetasHistManual::validar($tres, $HOY));

lanzaConHM('dos meses: rechazado', function () use ($tres, $HOY) {
    TarjetasHistManual::validar(array_slice($tres, 0, 2), $HOY);
}, 'llegaron 2');

lanzaConHM('el mes en curso no es un mes cerrado', function () use ($HOY) {
    TarjetasHistManual::validar([['mes' => '2026-08', 'importe' => 1], ['mes' => '2026-09', 'importe' => 1],
                                 ['mes' => '2026-10', 'importe' => 1]], $HOY);
}, 'no es un mes cerrado');

lanzaConHM('un mes repetido', function () use ($HOY) {
    TarjetasHistManual::validar([['mes' => '2026-08', 'importe' => 1], ['mes' => '2026-08', 'importe' => 1],
                                 ['mes' => '2026-09', 'importe' => 1]], $HOY);
}, 'viene dos veces');

lanzaConHM('un importe vacio: pide un cero explicito', function () use ($HOY) {
    TarjetasHistManual::validar([['mes' => '2026-07', 'importe' => ''], ['mes' => '2026-08', 'importe' => 1],
                                 ['mes' => '2026-09', 'importe' => 1]], $HOY);
}, 'poné 0');

lanzaConHM('negativo: rechazado', function () use ($HOY) {
    TarjetasHistManual::validar([['mes' => '2026-07', 'importe' => -5], ['mes' => '2026-08', 'importe' => 1],
                                 ['mes' => '2026-09', 'importe' => 1]], $HOY);
}, 'negativo');

lanzaConHM('todo en cero no es un historico', function () use ($HOY) {
    TarjetasHistManual::validar([['mes' => '2026-07', 'importe' => 0], ['mes' => '2026-08', 'importe' => 0],
                                 ['mes' => '2026-09', 'importe' => 0]], $HOY);
}, 'suman cero');

/* ================================================================
   EL PROMEDIO: COMO UNA SUPERVISORA, TODO TARJETA
   ================================================================ */
seccion('el promedio del historico: sobre 3, todo con tarjeta');

$p = TarjetasSupervisoras::promedioManual('JOHANNA', ['2026-09' => 300000, '2026-07' => 0,
                                                      '2026-08' => 150000]);

chequear('se divide por 3, tambien con un mes en cero', 150000.0, $p['promedio']);
chequear('todo es tarjeta', [0.0, 1.0], [$p['pct_efectivo'], $p['pct_tarjeta']]);
chequear('el mes base es el ultimo cargado', '2026-09', $p['base']);
chequear('dice cuantos meses tienen gasto', 2, $p['meses_con_datos']);
chequear('y que es un historico manual', TarjetasSupervisoras::ORIGEN_MANUAL, $p['origen']);

/* ================================================================
   QUIEN ENTRA A LA GRILLA
   ================================================================ */
seccion('quien tiene tarjeta y no carga gastos tiene fila');

$porSup = [
    'JOHANNA PEREZ' => [['ID' => 21, 'ID_USUARIO' => 12, 'NOMBRE_USUARIO' => 'JOHANNA PEREZ',
                         'TIPO' => 'SUPERVISORA', 'ACTIVA' => true, 'DIA_VENCIMIENTO' => 5,
                         'PCT_COBERTURA' => 10.0]],
    'ANA SIN NADA' => [['ID' => 22, 'ID_USUARIO' => 13, 'NOMBRE_USUARIO' => 'ANA SIN NADA',
                        'TIPO' => 'SUPERVISORA', 'ACTIVA' => true, 'DIA_VENCIMIENTO' => 5,
                        'PCT_COBERTURA' => 0.0]],
    'SONIA PACIFICO' => [['ID' => 6, 'ID_USUARIO' => 1, 'NOMBRE_USUARIO' => 'SONIA PACIFICO',
                          'TIPO' => 'SUPERVISORA', 'ACTIVA' => true, 'DIA_VENCIMIENTO' => 5,
                          'PCT_COBERTURA' => 0.0]]
];

$conGastos = ['SONIA PACIFICO' => ['nombre' => 'SONIA PACIFICO', 'promedio' => 900000.0,
                                   'total' => 2700000.0]];
$historicos = [
    12 => ['meses' => ['2026-07' => 90000, '2026-08' => 90000, '2026-09' => 90000]],
    1 => ['meses' => ['2026-07' => 5, '2026-08' => 5, '2026-09' => 5]]
];

$u = TarjetasSupervisoras::universoManual($porSup, $conGastos, $historicos);

chequear('con historico y sin gastos: proyecta con el historico', 90000.0,
    $u['promedios']['JOHANNA PEREZ']['promedio']);
chequear('sin historico: tiene fila, sin proyeccion', TarjetasSupervisoras::SIN_HISTORICO,
    $u['promedios']['ANA SIN NADA']['motivo_fijo']);
chequear('quien tiene gastos no entra por aca: mandan los gastos', false,
    isset($u['promedios']['SONIA PACIFICO']));
chequear('y su historico queda en desuso, para avisarlo', ['SONIA PACIFICO'], $u['en_desuso']);
chequear('la fila sabe de que usuario es', 12, $u['promedios']['JOHANNA PEREZ']['id_usuario']);

/* ================================================================
   LA PROYECCION: INFLACION COMPUESTA DESDE EL ULTIMO MES CARGADO
   ================================================================ */
seccion('se proyecta igual que una supervisora');

$ventana = TarjetasSupervisoras::ventana($HOY);
$inflacion = ['2026-10' => 2.0, '2026-11' => 2.0, '2026-12' => 2.0];
$estados = TarjetasSupervisoras::estadosConTarjeta([], array_keys($u['promedios']), $porSup);
$est = TarjetasSupervisoras::estimar($u['promedios'], ['2026-10', '2026-11', '2026-12'],
    $inflacion, $ventana, $estados);

$j = $est['JOHANNA PEREZ'];

chequear('proyecta', true, $j['proyecta']);
chequear('octubre: un mes de inflacion sobre septiembre', 90000 * 1.02,
    round($j['meses']['2026-10']['estimado'], 6));
chequear('diciembre: compuesta, no sumada', round(90000 * 1.02 * 1.02 * 1.02, 6),
    round($j['meses']['2026-12']['estimado'], 6));
chequear('todo es tarjeta, nada en efectivo', [0.0, $j['meses']['2026-12']['estimado']],
    [$j['meses']['2026-12']['efectivo'], $j['meses']['2026-12']['tarjeta']]);
chequear('el tooltip dice que sale del historico manual', true,
    strpos($j['meses']['2026-10']['tooltip'], 'histórico manual jul-26 a sep-26') !== false);
chequear('la fila dice su origen', TarjetasSupervisoras::ORIGEN_MANUAL, $j['origen']);

chequear('sin historico no proyecta, y queda en blanco', [false, null],
    [$est['ANA SIN NADA']['proyecta'], $est['ANA SIN NADA']['meses']['2026-11']['estimado']]);

$sinInf = TarjetasSupervisoras::estimar($u['promedios'], ['2026-12'], ['2026-10' => 2.0], $ventana,
    $estados);

chequear('sin la inflacion de un mes, ese mes queda en null', [null, ['2026-11', '2026-12']],
    [$sinInf['JOHANNA PEREZ']['meses']['2026-12']['estimado'],
     $sinInf['JOHANNA PEREZ']['faltan_inflacion']]);

/* EL DEBITO, EN EL VENCIMIENTO DE SU TARJETA, CON SU COBERTURA */
$vto = ['fecha' => '2026-11-05', 'mes' => '2026-11', 'faltan' => []];
$tar = TarjetasSupervisoras::tarjetaDelMes($j['meses']['2026-11']['tarjeta'],
    $porSup['JOHANNA PEREZ'][0], $vto, null, $HOY);

chequear('el debito cae en el vencimiento de su tarjeta', '2026-11-05', $tar['fecha']);
chequear('con el 10 % de cobertura de su tarjeta', round(90000 * 1.02 * 1.02 * 1.10, 6),
    round($tar['importe'], 6));

$conResumen = TarjetasSupervisoras::tarjetaDelMes($j['meses']['2026-11']['tarjeta'],
    $porSup['JOHANNA PEREZ'][0], $vto,
    ['ID' => 9, 'IMPORTE_ARS' => 123456.0, 'FECHA_VENCIMIENTO' => '2026-11-04', 'PAGADO' => false],
    $HOY);

chequear('el resumen cargado pisa la estimacion', [123456.0, '2026-11-04', 'RESUMEN'],
    [$conResumen['importe'], $conResumen['fecha'], $conResumen['origen']]);

/* ================================================================
   FUERA DEL MAESTRO, CON TARJETA: ACTIVA
   ================================================================ */
seccion('una tarjeta Supervisora activa cuenta a la persona como activa');

$gastosJ = ['JOHANNA PEREZ' => ['nombre' => 'JOHANNA PEREZ', 'promedio' => 50000.0,
                                'total' => 150000.0]];
$estadosJ = TarjetasSupervisoras::estadosConTarjeta(
    ['JOHANNA PEREZ' => ['en_maestro' => false, 'activa' => false, 'id' => null]],
    ['JOHANNA PEREZ'], $porSup);

chequear('con gastos y fuera del maestro, pero con tarjeta: proyecta', TarjetasSupervisoras::OK,
    TarjetasSupervisoras::motivoDe('JOHANNA PEREZ', $gastosJ['JOHANNA PEREZ'], $estadosJ));

chequear('sin tarjeta sigue siendo SIN_SUPERVISORA', TarjetasSupervisoras::SIN_SUPERVISORA,
    TarjetasSupervisoras::motivoDe('OTRA', ['promedio' => 1.0, 'total' => 3.0],
        TarjetasSupervisoras::estadosConTarjeta(
            ['OTRA' => ['en_maestro' => false, 'activa' => false, 'id' => null]], ['OTRA'], $porSup)));

chequear('una inactiva en el maestro sigue inactiva aunque tenga tarjeta',
    TarjetasSupervisoras::INACTIVA,
    TarjetasSupervisoras::motivoDe('SONIA PACIFICO', ['promedio' => 1.0, 'total' => 3.0],
        TarjetasSupervisoras::estadosConTarjeta(
            ['SONIA PACIFICO' => ['en_maestro' => true, 'activa' => false, 'id' => 1]],
            ['SONIA PACIFICO'], $porSup)));

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script del historico manual');

$sqlHM = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_historico_manual.sql');

chequear('crea la tabla dentro de una guarda', true,
    strpos($sqlHM, "OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL', 'U') IS NULL") !== false);
chequear('un importe vigente por persona y mes', true,
    strpos($sqlHM, '(ID_USUARIO, MES)') !== false);

foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
    chequear("no tiene $prohibido", false, stripos($sqlHM, $prohibido) !== false);
}

foreach (['USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF', 'USUARIO_BAJA', 'FECHA_BAJA'] as $c) {
    chequear("lleva $c", true, strpos($sqlHM, $c) !== false);
}
