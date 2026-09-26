<?php
/**
 * La planilla de Logistica Local: el reparto mitad/mitad, la exclusion de lo ya
 * pagado y la ubicacion de cada mitad en el eje.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN MES PARTIDO POR EL FINAL DEL TRAMO DIARIO LLEVA EL IMPORTE ENTERO EN
 *      SU COLUMNA MENSUAL. Con el tramo del 25/09 al 22/10, octubre tiene el
 *      pago del 9 adentro y el del 23 afuera: la columna Oct-26 tiene que
 *      llevar MEDIO importe, no el total. Con el total, ese mes se cuenta una
 *      vez y media y el cuadro cierra igual.
 *
 *   2. EL PAGO DE HOY SE PROYECTA. Esa plata ya salio y ya esta en el saldo
 *      bancario que abre el cuadro: proyectarla es pedirla dos veces. Y como el
 *      tramo diario arranca HOY, la columna existe y el importe entra sin que
 *      nada avise.
 *
 *   3. UN FLETERO SIN DATOS RINDE CERO. Un cero en un egreso se lee como "no
 *      hay que pagarle", que es lo contrario de lo que pasa.
 *
 *   4. LAS DOS MITADES NO SUMAN EL IMPORTE DEL MES. Redondear cada mitad a dos
 *      decimales hace que un importe impar pierda un centavo por mes, todos los
 *      meses.
 *
 * No toca la base: los fleteros, el cronograma y la inflacion se arman aca.
 */

require_once __DIR__ . '/../cashflow/Class/LogisticaPlanilla.php';
require_once __DIR__ . '/../cashflow/Class/CronogramaPagos.php';
require_once __DIR__ . '/../cashflow/Class/Horizonte.php';

/** Mapa de dias habiles de lunes a viernes, con los feriados que se le pasen */
if (!function_exists('calendarioLP')) {
    function calendarioLP($desde, $hasta, $feriados = []) {
        $mapa = [];
        $cursor = new DateTime($desde);
        $fin = new DateTime($hasta);

        while ($cursor <= $fin) {
            $f = $cursor->format('Y-m-d');
            $mapa[$f] = (intval($cursor->format('N')) <= 5) && !in_array($f, $feriados, true);
            $cursor->modify('+1 day');
        }

        return $mapa;
    }
}

/** Inflacion constante en un rango de meses */
if (!function_exists('inflacionLP')) {
    function inflacionLP($desde, $hasta, $pct) {
        require_once __DIR__ . '/../cashflow/Class/Inflacion.php';

        $mapa = [];
        $mes = $desde;

        while (Inflacion::distancia($mes, $hasta) >= 0) {
            $mapa[$mes] = $pct;
            $mes = Inflacion::mesMas($mes, 1);
        }

        return $mapa;
    }
}

/* El escenario: hoy 25/09/2026, tramo diario de 28 dias (25/09 .. 22/10) y doce
   meses. Es el horizonte real de la base al escribir esto. */
$hLP = new Horizonte(28, 12, [], new DateTime('2026-09-25'));
$habilesLP = calendarioLP('2026-08-01', '2027-10-31',
    ['2026-12-25', '2027-01-01', '2027-03-26', '2027-07-09']);
$cronoLP = CronogramaPagos::paraHorizonte($hLP, $habilesLP)['pagos'];
$inflaLP = inflacionLP('2026-07', '2027-12', 2.0);

/* Un fletero de numeros redondos: 100 horas a $1.000 = $100.000 por mes, o sea
   $50.000 por pago. Los numeros feos van en su propia seccion. */
$unFletero = [[
    'COD_PROVEE' => 'OGGIBA', 'NOMBRE' => 'BARONE GIANLUCA',
    'HORAS_MES' => 100, 'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09'
]];

$plan = LogisticaPlanilla::calcular($unFletero, $hLP, $cronoLP, $inflaLP);
$f = $plan['fleteros'][0];

/* ================================================================
   EL IMPORTE DEL MES Y SU REPARTO
   ================================================================ */
seccion('importe del mes = horas x valor hora del mes');

chequear('sep-26 vale el base: 100 x 1000', 100000.0, $f['meses']['2026-09']['importe_mes']);
chequear('oct-26 sigue en el trimestre base', 100000.0, $f['meses']['2026-10']['importe_mes']);
chequear('nov-26 tambien', 100000.0, $f['meses']['2026-11']['importe_mes']);

// dic-26 ajusta oct+nov+dic = 6 %: 1000 x 1,06 = 1060, por 100 horas.
chequear('dic-26 ajusta: 100 x 1060', 106000.0, $f['meses']['2026-12']['importe_mes']);
chequear('y la celda lo marca como mes de ajuste', true, $f['meses']['2026-12']['ajusta']);
chequear('mar-27 ajusta otra vez: 100 x 1123,60', 112360.0, $f['meses']['2027-03']['importe_mes']);

seccion('se paga mitad y mitad en los dos pagos del mes');

$oct = $f['meses']['2026-10'];

chequear('octubre tiene exactamente dos pagos', 2, count($oct['pagos']));
chequear('el primero es la mitad', 50000.0, $oct['pagos'][0]['importe']);
chequear('el segundo tambien', 50000.0, $oct['pagos'][1]['importe']);
chequear('y suman el importe del mes',
    $oct['importe_mes'], $oct['pagos'][0]['importe'] + $oct['pagos'][1]['importe']);

chequear('el pago 1 es el 2do viernes', '2026-10-09', $oct['pagos'][0]['fecha']);
chequear('y el pago 2 el 4to', '2026-10-23', $oct['pagos'][1]['fecha']);

seccion('las dos mitades suman el importe del mes tambien con centavos');

/* 37 horas a $1.333,33 da $49.333,21, que es impar en centavos. Si cada mitad
   se redondeara a dos decimales, las dos sumarian un centavo de menos TODOS los
   meses. */
$impar = LogisticaPlanilla::calcular(
    [['COD_PROVEE' => 'OGTAPI', 'NOMBRE' => 'TAPIA', 'HORAS_MES' => 37,
      'VALOR_HORA_BASE' => 1333.33, 'MES_BASE' => '2026-09']],
    $hLP, $cronoLP, $inflaLP);

$mesImpar = $impar['fleteros'][0]['meses']['2026-10'];

chequear('el importe del mes es impar en centavos', 49333.21, $mesImpar['importe_mes']);
chequear('y las dos mitades lo reconstruyen exacto',
    $mesImpar['importe_mes'],
    $mesImpar['pagos'][0]['importe'] + $mesImpar['pagos'][1]['importe']);

/* ================================================================
   LO QUE YA SE PAGO NO SE PROYECTA
   ================================================================ */
seccion('un pago con fecha anterior o igual a HOY no se proyecta');

$sep = $f['meses']['2026-09'];

// El 2do viernes de septiembre fue el 11: ya paso.
chequear('el pago del 11/09 esta excluido', true, $sep['pagos'][0]['excluido']);
chequear('y dice por que', 'Ya pasó: ese pago ya se hizo.', $sep['pagos'][0]['motivo_excluido']);

/* EL DE HOY TAMBIEN. El 4to viernes de septiembre es el 25, que es hoy: ese
   pago ya se hizo, aunque la columna de hoy exista en el eje. */
chequear('el pago de HOY (25/09) tambien esta excluido', true, $sep['pagos'][1]['excluido']);
chequear('y el motivo lo distingue del que ya paso',
    'Es hoy: ese pago ya se hizo.', $sep['pagos'][1]['motivo_excluido']);

/* EL IMPORTE DEL MES NO CAMBIA -septiembre se paga entero- pero lo PROYECTADO
   es cero: los dos numeros son distintos y los dos se muestran. */
chequear('el importe del mes de septiembre sigue siendo el entero',
    100000.0, $sep['importe_mes']);
chequear('pero no se proyecta nada de septiembre', null, $sep['importe_proy']);

chequear('octubre se proyecta entero', 100000.0, $oct['importe_proy']);

seccion('un solo pago excluido deja medio mes proyectado');

/* Si hoy fuera el 12/10, el pago del 9 ya paso y el del 23 no: octubre
   proyectaria la mitad. */
$hMedio = new Horizonte(28, 12, [], new DateTime('2026-10-12'));
$cronoMedio = CronogramaPagos::paraHorizonte($hMedio, $habilesLP)['pagos'];
$planMedio = LogisticaPlanilla::calcular($unFletero, $hMedio, $cronoMedio, $inflaLP);
$octMedio = $planMedio['fleteros'][0]['meses']['2026-10'];

chequear('el pago del 9 ya paso', true, $octMedio['pagos'][0]['excluido']);
chequear('el del 23 no', false, $octMedio['pagos'][1]['excluido']);
chequear('el importe del mes sigue entero', 100000.0, $octMedio['importe_mes']);
chequear('y se proyecta la mitad', 50000.0, $octMedio['importe_proy']);

/* ================================================================
   EL REPARTO EN EL EJE
   ================================================================ */
seccion('cada mitad va a su columna: un dia O un mes, nunca los dos');

/* LA PRUEBA QUE MAS IMPORTA DE ESTE ARCHIVO. El tramo va del 25/09 al 22/10:
   el pago del 9/10 cae ADENTRO y el del 23/10 AFUERA. La columna mensual de
   Oct-26 tiene que llevar SOLO la mitad de afuera. */
$serie = $hLP->serieVacia();
$fuera = 0;

foreach (LogisticaPlanilla::pagosAProyectar($plan) as $p) {
    if (!$hLP->acumular($serie, $p['fecha'], $p['importe'])) {
        $fuera += $p['importe'];
    }
}

chequear('el pago del 9/10 esta en su columna DIARIA', 50000.0, $serie['dias']['2026-10-09']);
chequear('y la columna mensual de Oct-26 lleva SOLO la otra mitad',
    50000.0, $serie['meses']['2026-10']);

/* Y NO EL IMPORTE ENTERO. Es el error que esta prueba existe para atrapar. */
chequear('NO lleva el importe entero de octubre',
    true, $serie['meses']['2026-10'] != 100000.0);

// Noviembre cae entero fuera del tramo: sus dos pagos van a su columna mensual.
chequear('noviembre, entero fuera del tramo, lleva su importe completo',
    100000.0, $serie['meses']['2026-11']);

// Septiembre: sus dos pagos estan excluidos, asi que su columna queda en cero.
chequear('septiembre no aporta nada: sus dos pagos ya se hicieron',
    0, $serie['meses']['2026-09']);

/* LA COLUMNA DE HOY NUNCA RECIBE NADA. El tramo arranca hoy y el pago de hoy
   esta excluido, asi que no hay forma de que entre algo. */
chequear('la columna de HOY queda vacia', 0, $serie['dias']['2026-09-25']);

seccion('las tres vistas siguen siendo sumables');

/* total_horizonte = total_tramo + total_meses, sin repetir nada. Es la
   propiedad que Horizonte garantiza y que este reparto no puede romper. */
$totalProyectado = 0;

foreach (LogisticaPlanilla::pagosAProyectar($plan) as $p) {
    $totalProyectado += $p['importe'];
}

chequear('lo acumulado en el eje mas lo que quedo afuera da el total proyectado',
    $totalProyectado, array_sum($serie['dias']) + array_sum($serie['meses']) + $fuera);

/* ================================================================
   LO QUE NO SE PUEDE PROYECTAR
   ================================================================ */
seccion('un fletero sin horas no se proyecta y se avisa');

$sinHoras = LogisticaPlanilla::calcular(
    [['COD_PROVEE' => 'OGDARI', 'NOMBRE' => 'RIOS DANIEL', 'HORAS_MES' => null,
      'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09']],
    $hLP, $cronoLP, $inflaLP);

$sh = $sinHoras['fleteros'][0];

chequear('el importe del mes queda en null, no en 0', null, $sh['meses']['2026-10']['importe_mes']);
chequear('y lo proyectado tambien', null, $sh['meses']['2026-10']['importe_proy']);
chequear('el motivo lo dice', LogisticaPlanilla::SIN_HORAS, $sh['meses']['2026-10']['motivo']);
chequear('el total del fletero queda en null', null, $sh['total']);

/* EL VALOR HORA SI SE CALCULA. Es un dato correcto y util: lo que falta es
   cuantas horas multiplicarlo, y la planilla lo muestra igual. */
chequear('pero el valor hora del mes si se calculo', 1000.0, $sh['meses']['2026-10']['valor_hora']);

chequear('hay un aviso que lo nombra', true, count($sh['avisos']) > 0);
chequear('y nombra al fletero', true,
    strpos(implode(' ', $sh['avisos']), 'RIOS DANIEL') !== false);
chequear('una sola vez, no una por mes', 1, count($sh['avisos']));

seccion('un fletero sin valor hora base tampoco');

$sinValor = LogisticaPlanilla::calcular(
    [['COD_PROVEE' => 'OGSEBA', 'NOMBRE' => 'BARONE SERGIO', 'HORAS_MES' => 100,
      'VALOR_HORA_BASE' => null, 'MES_BASE' => '2026-09']],
    $hLP, $cronoLP, $inflaLP);

$sv = $sinValor['fleteros'][0];

chequear('el valor hora queda en null', null, $sv['meses']['2026-10']['valor_hora']);
chequear('el importe tambien, nunca 0', null, $sv['meses']['2026-10']['importe_mes']);
/* El motivo sale de LogisticaValorHora y no de la planilla: la planilla sólo
   agrega el suyo (SIN_HORAS) y deja pasar los del valor hora, para que el front
   tenga una sola lista de motivos que dibujar. */
chequear('con su motivo', LogisticaValorHora::SIN_BASE, $sv['meses']['2026-10']['motivo']);

seccion('un fletero sin mes base tampoco');

$sinMes = LogisticaPlanilla::calcular(
    [['COD_PROVEE' => 'OGTAPI', 'NOMBRE' => 'TAPIA', 'HORAS_MES' => 100,
      'VALOR_HORA_BASE' => 1000, 'MES_BASE' => null]],
    $hLP, $cronoLP, $inflaLP);

$sm = $sinMes['fleteros'][0];

chequear('sin mes base no se sabe desde cuando rige: null',
    null, $sm['meses']['2026-10']['valor_hora']);
chequear('ni que mes ajusta', false, $sm['meses']['2026-10']['ajusta']);
chequear('el aviso lo explica', true,
    strpos(implode(' ', $sm['avisos']), 'mes base') !== false);

/* LAS FECHAS DEL CRONOGRAMA SE MUESTRAN IGUAL. La fecha de pago es un dato del
   cronograma y no del fletero: esconderla cuando falta el importe obliga a ir a
   buscarla a otra pantalla. */
chequear('los dos pagos se dibujan igual, sin importe',
    2, count($sm['meses']['2026-10']['pagos']));
chequear('con su fecha', '2026-10-09', $sm['meses']['2026-10']['pagos'][0]['fecha']);
chequear('y el importe en null', null, $sm['meses']['2026-10']['pagos'][0]['importe']);

seccion('un mes anterior al mes base no se proyecta');

$tardio = LogisticaPlanilla::calcular(
    [['COD_PROVEE' => 'OGGIBA', 'NOMBRE' => 'BARONE GIANLUCA', 'HORAS_MES' => 100,
      'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-12']],
    $hLP, $cronoLP, $inflaLP);

$t = $tardio['fleteros'][0];

chequear('sep-26 con base dic-26 queda en null', null, $t['meses']['2026-09']['importe_mes']);
chequear('nov-26 tambien', null, $t['meses']['2026-11']['importe_mes']);
chequear('con el motivo correcto', 'ANTES_DE_BASE', $t['meses']['2026-11']['motivo']);
chequear('dic-26 ya proyecta', 100000.0, $t['meses']['2026-12']['importe_mes']);
chequear('y el aviso dice cuantos meses quedaron afuera', true,
    strpos(implode(' ', $t['avisos']), 'anteriores a su mes base') !== false);

seccion('si falta la inflacion de un ajuste, se corta ahi y se avisa');

/* Falta noviembre: el ajuste de dic-26 no se puede calcular. */
$sinNov = $inflaLP;
unset($sinNov['2026-11']);

$corte = LogisticaPlanilla::calcular($unFletero, $hLP, $cronoLP, $sinNov);
$c = $corte['fleteros'][0];

chequear('oct-26 proyecta igual: el trimestre base no usa inflacion',
    100000.0, $c['meses']['2026-10']['importe_mes']);
chequear('dic-26 queda en null, no en 0', null, $c['meses']['2026-12']['importe_mes']);
chequear('mar-27 tambien, porque se apoya en dic-26', null, $c['meses']['2027-03']['importe_mes']);
chequear('el aviso nombra el mes que falta', true,
    strpos(implode(' ', $c['avisos']), '2026-11') !== false);

/* ================================================================
   TOTALES
   ================================================================ */
seccion('totales por fletero y total general');

$varios = LogisticaPlanilla::calcular([
    ['COD_PROVEE' => 'OGGIBA', 'NOMBRE' => 'A', 'HORAS_MES' => 100,
     'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09'],
    ['COD_PROVEE' => 'OGSEBA', 'NOMBRE' => 'B', 'HORAS_MES' => 50,
     'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09']
], $hLP, $cronoLP, $inflaLP);

chequear('hay dos filas', 2, count($varios['fleteros']));
chequear('el segundo proyecta la mitad que el primero',
    $varios['fleteros'][0]['total'] / 2, $varios['fleteros'][1]['total']);
chequear('el total general es la suma de los dos',
    $varios['fleteros'][0]['total'] + $varios['fleteros'][1]['total'],
    $varios['totales']['total']);
chequear('el total de octubre es la suma de los dos',
    150000.0, $varios['totales']['meses']['2026-10']);

/* UN MES QUE NINGUN FLETERO PUDO CALCULAR QUEDA EN null, no en cero: si
   aportara cero seria indistinguible de un mes que proyecta cero. */
chequear('septiembre, con los dos pagos ya hechos, queda en null',
    null, $varios['totales']['meses']['2026-09']);

seccion('sin ningun fletero, la planilla avisa');

$vacia = LogisticaPlanilla::calcular([], $hLP, $cronoLP, $inflaLP);

chequear('no hay filas', 0, count($vacia['fleteros']));
chequear('el total general es null y no 0', null, $vacia['totales']['total']);
chequear('y hay un aviso que lo dice', true,
    strpos(implode(' ', $vacia['avisos']), 'ningún fletero activo') !== false);

seccion('pagosAProyectar deja afuera lo excluido y lo que no se pudo calcular');

$aProy = LogisticaPlanilla::pagosAProyectar($plan);

chequear('ninguno esta excluido', [], array_values(array_filter(
    array_column($aProy, 'excluido'))));
chequear('ninguno tiene importe null', [], array_values(array_filter(
    array_column($aProy, 'importe'), function ($i) { return $i === null; })));

// 24 pagos en total menos los dos de septiembre, que ya se hicieron.
chequear('quedan 22 de los 24', 22, count($aProy));

chequear('de un fletero sin datos no queda ninguno',
    0, count(LogisticaPlanilla::pagosAProyectar($sinHoras)));
