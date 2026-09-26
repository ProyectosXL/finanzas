<?php
/**
 * Gastos Supervisoras: la ventana, el promedio, la proporcion y cuando sale de
 * caja cada parte.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA VENTANA INCLUYE EL MES EN CURSO. Ese mes esta INCOMPLETO, asi que baja
 *      el promedio por los dias que todavia no pasaron, y lo baja mas cuanto mas
 *      cerca del dia 1 se mire la pantalla. El numero cambia solo, todos los dias,
 *      y nada en la pantalla lo explica.
 *
 *   2. SE DIVIDE POR LOS MESES CON DATOS EN VEZ DE POR TRES. Una supervisora que
 *      entro en agosto proyectaria su gasto de agosto TODOS los meses, o sea el
 *      triple de lo que corresponde. Un mes sin gastos tambien es un dato.
 *
 *   3. LA PROPORCION SE CALCULA POR MES. Un mes atipico -uno en el que
 *      casualmente no se uso efectivo- se proyectaria hacia adelante para siempre.
 *
 *   4. EL EFECTIVO SALE EN UN SOLO PAGO, O SE PROYECTA UNO QUE YA SE HIZO. Lo
 *      primero pone el doble en una columna y nada en la otra; lo segundo pide dos
 *      veces la misma plata, porque ese pago ya esta en el saldo bancario que abre
 *      el cuadro.
 *
 *   5. EL RESUMEN NO PISA LA ESTIMACION, o la pisa a medias. Si suman los dos, el
 *      mes se cuenta dos veces; si el resumen no manda la FECHA, el importe real
 *      sale en la columna de la estimacion.
 *
 *   6. UN RESUMEN PAGADO SIGUE EN EL HORIZONTE. Esa plata ya salio: proyectarla
 *      la pide dos veces.
 *
 * Todo lo de aca es PURO. Los gastos, la inflacion y el cronograma se arman a
 * mano, que es lo que permite probar la supervisora nueva, la inactiva y el mes
 * sin inflacion sin esperar a que la base tenga el caso.
 */

require_once __DIR__ . '/../Class/TarjetasSupervisoras.php';
require_once __DIR__ . '/../Class/GastosSupervision.php';
require_once __DIR__ . '/../Class/CronogramaPagos.php';

/** Inflacion constante en un rango de meses, para no repetir el mapa */
function inflacionTS($pct, $desde, $hasta) {
    $mapa = [];
    $m = $desde;

    while (Inflacion::distancia($m, $hasta) >= 0) {
        $mapa[$m] = $pct;
        $m = Inflacion::mesMas($m, 1);
    }

    return $mapa;
}

/** Mapa de dias habiles de lunes a viernes, con los feriados que se le pasen */
function calendarioTS($desde, $hasta, $feriados = []) {
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

/* ================================================================
   LA VENTANA: TRES MESES CALENDARIO COMPLETOS

   El mes en curso NO entra. Ver el punto 1 del encabezado.
   ================================================================ */
seccion('la ventana son los tres meses completos anteriores');

$v = TarjetasSupervisoras::ventana('2026-09-26');

chequear('parado el 26/09/2026 son junio, julio y agosto',
    ['2026-06', '2026-07', '2026-08'], $v['meses']);
chequear('el mes base es el ULTIMO de la ventana', '2026-08', $v['base']);
chequear('desde el 1 de junio', '2026-06-01', $v['desde']);
chequear('hasta el 31 de agosto', '2026-08-31', $v['hasta']);
chequear('y el rotulo se lee en orden', 'jun-26 a ago-26', $v['rotulo']);

/* EL MES EN CURSO NO ENTRA, Y SE VERIFICA EXPLICITAMENTE: es el error que cambia
   el promedio todos los dias. */
chequear('septiembre, el mes en curso, NO entra', false,
    in_array('2026-09', $v['meses'], true));

/* LA VENTANA ES LA MISMA TODO EL MES. Es la consecuencia de que el mes en curso
   no entre, y es lo que hace que el promedio no cambie solo entre el 1 y el 30. */
chequear('el 1 de septiembre da la misma ventana que el 26',
    $v['meses'], TarjetasSupervisoras::ventana('2026-09-01')['meses']);
chequear('y el 30 tambien',
    $v['meses'], TarjetasSupervisoras::ventana('2026-09-30')['meses']);

/* Y EL 1 DE OCTUBRE SE CORRE SOLA. No hay ningun parametro que actualizar. */
chequear('el 1 de octubre la ventana ya es jul-ago-sep',
    ['2026-07', '2026-08', '2026-09'], TarjetasSupervisoras::ventana('2026-10-01')['meses']);

seccion('el cruce de anio');

$v2 = TarjetasSupervisoras::ventana('2027-01-15');

chequear('en enero la ventana es oct, nov y dic del anio anterior',
    ['2026-10', '2026-11', '2026-12'], $v2['meses']);
chequear('el mes base es diciembre de 2026', '2026-12', $v2['base']);
chequear('desde el 1 de octubre', '2026-10-01', $v2['desde']);
chequear('hasta el 31 de diciembre', '2026-12-31', $v2['hasta']);

$v3 = TarjetasSupervisoras::ventana('2027-02-10');

chequear('en febrero cruza: nov, dic y enero', ['2026-11', '2026-12', '2027-01'], $v3['meses']);
chequear('y hasta el 31 de enero', '2027-01-31', $v3['hasta']);

/* EL ULTIMO DIA DE LA VENTANA SALE DEL CALENDARIO. Con un febrero de por medio no
   puede ser un 31 fijo, y con uno bisiesto tampoco un 28. */
chequear('una ventana que termina en febrero no bisiesto llega al 28',
    '2027-02-28', TarjetasSupervisoras::ventana('2027-03-05')['hasta']);
chequear('y una que termina en febrero bisiesto, al 29',
    '2028-02-29', TarjetasSupervisoras::ventana('2028-03-05')['hasta']);

/* ================================================================
   EL PROMEDIO Y LA PROPORCION
   ================================================================ */
seccion('el promedio se divide SIEMPRE por tres');

$v = TarjetasSupervisoras::ventana('2026-09-26');

/* Una supervisora con los tres meses: 300.000 de efectivo y 700.000 de tarjeta en
   total, o sea 1.000.000 en la ventana. */
$gastos = [
    ['supervisora' => 'ANA', 'mes' => '2026-06', 'efectivo' => 100000, 'tarjeta' => 200000],
    ['supervisora' => 'ANA', 'mes' => '2026-07', 'efectivo' => 100000, 'tarjeta' => 300000],
    ['supervisora' => 'ANA', 'mes' => '2026-08', 'efectivo' => 100000, 'tarjeta' => 200000]
];

$p = TarjetasSupervisoras::promedios($gastos, $v);

chequear('el total de la ventana', 1000000.0, $p['ANA']['total']);
chequear('el promedio es el total sobre tres', 333333.3333333, $p['ANA']['promedio']);
chequear('tres meses con datos', 3, $p['ANA']['meses_con_datos']);
chequear('% efectivo = 300.000 / 1.000.000', 0.3, $p['ANA']['pct_efectivo']);
chequear('% tarjeta es el complemento', 0.7, $p['ANA']['pct_tarjeta']);

/* LAS DOS PARTES SUMAN EXACTAMENTE UNO. El % tarjeta es el complemento y no una
   segunda division: con dos cocientes redondeados, la suma perderia un centavo
   por mes. */
chequear('los dos porcentajes suman exactamente 1',
    1.0, $p['ANA']['pct_efectivo'] + $p['ANA']['pct_tarjeta']);

seccion('una supervisora nueva: menos meses, y se divide por tres igual');

/* ES EL CASO QUE JUSTIFICA LA REGLA. Entro en agosto y gasto 900.000 ese mes. Su
   promedio mensual es 300.000, no 900.000: dos meses sin gastos son dos meses en
   los que no gasto nada, y eso es un dato. */
$nueva = [
    ['supervisora' => 'BEA', 'mes' => '2026-08', 'efectivo' => 90000, 'tarjeta' => 810000]
];

$p = TarjetasSupervisoras::promedios($nueva, $v);

chequear('el total es lo del unico mes', 900000.0, $p['BEA']['total']);
chequear('y el promedio se divide por TRES, no por uno', 300000.0, $p['BEA']['promedio']);
chequear('se informa que tiene un solo mes con datos', 1, $p['BEA']['meses_con_datos']);
chequear('la proporcion sale igual del total de la ventana', 0.1, $p['BEA']['pct_efectivo']);

/* Dos meses con datos, el otro caso intermedio. */
$dos = [
    ['supervisora' => 'CEL', 'mes' => '2026-07', 'efectivo' => 0, 'tarjeta' => 300000],
    ['supervisora' => 'CEL', 'mes' => '2026-08', 'efectivo' => 0, 'tarjeta' => 300000]
];

$p = TarjetasSupervisoras::promedios($dos, $v);

chequear('con dos meses, el promedio sigue siendo el total sobre tres',
    200000.0, $p['CEL']['promedio']);
chequear('y se informan los dos meses', 2, $p['CEL']['meses_con_datos']);

seccion('la proporcion sale de la ventana, no de cada mes');

/* UN MES SIN EFECTIVO NO HACE QUE LA SUPERVISORA NO USE EFECTIVO. Con la
   proporcion por mes, agosto proyectaria 0 % de efectivo para siempre; con la
   proporcion de la ventana, proyecta el 25 % que efectivamente usa. */
$irregular = [
    ['supervisora' => 'DORA', 'mes' => '2026-06', 'efectivo' => 100000, 'tarjeta' => 100000],
    ['supervisora' => 'DORA', 'mes' => '2026-07', 'efectivo' => 100000, 'tarjeta' => 100000],
    ['supervisora' => 'DORA', 'mes' => '2026-08', 'efectivo' => 0, 'tarjeta' => 200000]
];

$p = TarjetasSupervisoras::promedios($irregular, $v);

chequear('el % efectivo es el de la ventana entera', 0.3333333, $p['DORA']['pct_efectivo']);
chequear('y no el 0 % del ultimo mes', true, $p['DORA']['pct_efectivo'] > 0);

seccion('lo que queda fuera de la ventana no entra');

/* Un mes anterior a la ventana y el mes en curso se descartan: el primero ya no
   describe lo que se gasta hoy, el segundo esta incompleto. */
$conSobrantes = [
    ['supervisora' => 'ANA', 'mes' => '2026-03', 'efectivo' => 999999, 'tarjeta' => 999999],
    ['supervisora' => 'ANA', 'mes' => '2026-07', 'efectivo' => 100000, 'tarjeta' => 200000],
    ['supervisora' => 'ANA', 'mes' => '2026-09', 'efectivo' => 999999, 'tarjeta' => 999999]
];

$p = TarjetasSupervisoras::promedios($conSobrantes, $v);

chequear('solo cuenta el mes de la ventana', 300000.0, $p['ANA']['total']);
chequear('y el promedio es ese sobre tres', 100000.0, $p['ANA']['promedio']);

seccion('un total en cero no tiene proporcion');

/* CON TOTAL CERO LA PROPORCION ES INDEFINIDA -no es 0 % ni 100 %- y dividir daria
   un NaN que viajaria hasta el tablero. Queda en null, y el motivo lo dice. */
$enCero = [
    ['supervisora' => 'EVA', 'mes' => '2026-08', 'efectivo' => 0, 'tarjeta' => 0]
];

$p = TarjetasSupervisoras::promedios($enCero, $v);

chequear('el promedio es null y no cero', null, $p['EVA']['promedio']);
chequear('el % efectivo tambien', null, $p['EVA']['pct_efectivo']);
chequear('y el % tarjeta', null, $p['EVA']['pct_tarjeta']);

/* ================================================================
   LA ESTIMACION MES A MES
   ================================================================ */
seccion('la estimacion aplica la inflacion compuesta desde el mes base');

$inflacion = inflacionTS(2, '2026-06', '2027-12');
$estados = ['ANA' => ['en_maestro' => true, 'activa' => true, 'id' => 1]];

$gastos = [
    ['supervisora' => 'ANA', 'mes' => '2026-06', 'efectivo' => 100000, 'tarjeta' => 300000],
    ['supervisora' => 'ANA', 'mes' => '2026-07', 'efectivo' => 100000, 'tarjeta' => 300000],
    ['supervisora' => 'ANA', 'mes' => '2026-08', 'efectivo' => 100000, 'tarjeta' => 300000]
];

$p = TarjetasSupervisoras::promedios($gastos, $v);
$meses = ['2026-09', '2026-10', '2026-11'];
$e = TarjetasSupervisoras::estimar($p, $meses, $inflacion, $v, $estados);

chequear('el promedio es 400.000', 400000.0, $e['ANA']['promedio']);
chequear('proyecta', true, $e['ANA']['proyecta']);
chequear('el motivo es OK', TarjetasSupervisoras::OK, $e['ANA']['motivo']);

/* EL MES BASE ES AGOSTO, asi que septiembre lleva UN mes de inflacion y no cero:
   el promedio esta en moneda de agosto. */
chequear('septiembre lleva un mes de inflacion', 400000 * 1.02,
    $e['ANA']['meses']['2026-09']['estimado']);
chequear('octubre, dos', 400000 * 1.0404, $e['ANA']['meses']['2026-10']['estimado']);
chequear('noviembre, tres, compuestos', 400000 * 1.061208,
    $e['ANA']['meses']['2026-11']['estimado']);

/* LAS DOS PARTES SUMAN EL ESTIMADO, sin un centavo perdido. */
$c = $e['ANA']['meses']['2026-11'];

chequear('efectivo + tarjeta = estimado', $c['estimado'], $c['efectivo'] + $c['tarjeta']);
chequear('el efectivo es el 25 %', $c['estimado'] * 0.25, $c['efectivo']);
chequear('y la tarjeta el 75 %', $c['estimado'] * 0.75, $c['tarjeta']);

seccion('un mes sin inflacion queda en null, y los demas se proyectan igual');

/* SIN OCTUBRE CARGADO, octubre y noviembre no se pueden resolver -noviembre lo
   necesita para componer- y septiembre SI. Lo que se cae es lo que depende del
   dato que falta, no la fila entera. */
$conHueco = $inflacion;
unset($conHueco['2026-10']);

$e = TarjetasSupervisoras::estimar($p, $meses, $conHueco, $v, $estados);

chequear('septiembre se proyecta igual', 400000 * 1.02,
    $e['ANA']['meses']['2026-09']['estimado']);
chequear('octubre queda en null, NO en cero', null, $e['ANA']['meses']['2026-10']['estimado']);
chequear('noviembre tambien, porque se apoya en octubre',
    null, $e['ANA']['meses']['2026-11']['estimado']);
chequear('el motivo del mes lo dice',
    TarjetasSupervisoras::SIN_INFLACION, $e['ANA']['meses']['2026-10']['motivo']);
chequear('y la fila dice QUE mes cargar', ['2026-10'], $e['ANA']['faltan_inflacion']);

/* EL AVISO SE DEDUPLICA: dos meses que dependen del mismo mes ausente informan un
   solo mes faltante, no dos. Dos avisos que dicen lo mismo esconden el resto. */
chequear('el mes faltante se informa una sola vez', 1, count($e['ANA']['faltan_inflacion']));

seccion('quien no proyecta, y por que');

/* UNA SUPERVISORA INACTIVA no proyecta ningun mes, aunque tenga gastos en la
   ventana y la inflacion este completa: dejo de trabajar, asi que su gasto
   historico no describe lo que va a salir de caja. */
$e = TarjetasSupervisoras::estimar($p, $meses, $inflacion, $v,
    ['ANA' => ['en_maestro' => true, 'activa' => false, 'id' => 1]]);

chequear('una inactiva no proyecta', false, $e['ANA']['proyecta']);
chequear('el motivo es INACTIVA', TarjetasSupervisoras::INACTIVA, $e['ANA']['motivo']);
chequear('y ningun mes tiene importe', null, $e['ANA']['meses']['2026-09']['estimado']);

/* EL PROMEDIO SE SIGUE MOSTRANDO. Es lo que el aviso necesita para decir cuanto
   deja de proyectarse, y esconderlo obligaria a calcularlo a mano. */
chequear('pero el promedio se sigue informando', 400000.0, $e['ANA']['promedio']);

/* EL ESTADO MANDA SOBRE LA INFLACION: si no proyecta por su estado, decir "falta
   la inflacion de octubre" mandaria a cargar un dato que no cambiaria nada. */
$e = TarjetasSupervisoras::estimar($p, $meses, $conHueco, $v,
    ['ANA' => ['en_maestro' => true, 'activa' => false, 'id' => 1]]);

chequear('el motivo del mes es el estado, no la inflacion',
    TarjetasSupervisoras::INACTIVA, $e['ANA']['meses']['2026-10']['motivo']);
chequear('y no se pide cargar inflacion que no cambiaria nada',
    [], $e['ANA']['faltan_inflacion']);

/* UN NOMBRE QUE NO ESTA EN EL MAESTRO tampoco proyecta, y es un motivo distinto:
   no se puede saber si dejo de trabajar o si el nombre esta mal tipeado. */
$e = TarjetasSupervisoras::estimar($p, $meses, $inflacion, $v, []);

chequear('un nombre que no esta en el maestro no proyecta', false, $e['ANA']['proyecta']);
chequear('y el motivo lo distingue de una baja',
    TarjetasSupervisoras::SIN_SUPERVISORA, $e['ANA']['motivo']);

/* Un total en cero: tampoco proyecta, con su propio motivo. */
$pCero = TarjetasSupervisoras::promedios($enCero, $v);
$e = TarjetasSupervisoras::estimar($pCero, $meses, $inflacion, $v,
    ['EVA' => ['en_maestro' => true, 'activa' => true, 'id' => 9]]);

chequear('con total cero no proyecta', false, $e['EVA']['proyecta']);
chequear('y el motivo es SIN_IMPORTE', TarjetasSupervisoras::SIN_IMPORTE, $e['EVA']['motivo']);

/* ================================================================
   EL EFECTIVO: DOS PAGOS IGUALES DEL CRONOGRAMA
   ================================================================ */
seccion('el efectivo se reparte mitad y mitad');

$habiles = calendarioTS('2026-01-01', '2028-12-31');
$crono = CronogramaPagos::delMes('2026-11', $habiles);
$delMes = [];

foreach ($crono as $pago) {
    $delMes[$pago['nro']] = $pago;
}

$pagos = TarjetasSupervisoras::repartirEfectivo(100000, $delMes, '2026-09-26');

chequear('son dos pagos', 2, count($pagos));
chequear('cada uno es la mitad', 50000.0, $pagos[0]['importe']);
chequear('y el otro tambien', 50000.0, $pagos[1]['importe']);
chequear('las dos mitades suman el importe', 100000.0,
    $pagos[0]['importe'] + $pagos[1]['importe']);

/* NO SE REDONDEA LA MITAD. Con un importe impar, redondear haria que las dos
   mitades no sumen el total y esa diferencia se repetiria todos los meses. */
$impares = TarjetasSupervisoras::repartirEfectivo(100000.01, $delMes, '2026-09-26');

chequear('con centavos impares la mitad no se redondea', 50000.005, $impares[0]['importe']);
chequear('y las dos siguen sumando el total exacto', 100000.01,
    $impares[0]['importe'] + $impares[1]['importe']);

chequear('el 2do viernes de noviembre de 2026 es el 13', '2026-11-13', $pagos[0]['fecha']);
chequear('y el 4to el 27', '2026-11-27', $pagos[1]['fecha']);

seccion('un pago con fecha anterior o igual a hoy no se proyecta');

/* ESA PLATA YA SALIO y ya esta reflejada en el saldo bancario que abre el cuadro.
   Proyectarla seria pedir dos veces la misma plata. Mismo criterio que Logistica
   Local. */
$crono = CronogramaPagos::delMes('2026-09', $habiles);
$delSep = [];

foreach ($crono as $pago) {
    $delSep[$pago['nro']] = $pago;
}

chequear('el 2do viernes de septiembre de 2026 es el 11', '2026-09-11', $delSep[1]['fecha']);
chequear('y el 4to el 25', '2026-09-25', $delSep[2]['fecha']);

$pagos = TarjetasSupervisoras::repartirEfectivo(100000, $delSep, '2026-09-26');

chequear('el pago del 11, ya pasado, no se proyecta', false, $pagos[0]['proyecta']);
chequear('el del 25 tampoco', false, $pagos[1]['proyecta']);
chequear('y el motivo lo dice', 'Ya pasó: ese pago ya se hizo.', $pagos[0]['motivo']);

/* SE SIGUEN DEVOLVIENDO LOS DOS, con su importe: la fecha es un dato del
   cronograma y la pantalla tiene que poder mostrar que ese pago existio y por que
   no entra. Lo que cambia es 'proyecta'. */
chequear('pero los dos siguen en la lista con su importe', 50000.0, $pagos[0]['importe']);

/* EL PAGO QUE CAE HOY TAMPOCO SE PROYECTA, y el motivo lo distingue: es el caso
   limite, y con el tramo diario arrancando hoy la columna de hoy nunca recibe
   nada de la parte en efectivo. */
$pagos = TarjetasSupervisoras::repartirEfectivo(100000, $delSep, '2026-09-25');

chequear('el pago que cae hoy no se proyecta', false, $pagos[1]['proyecta']);
chequear('y su motivo lo distingue de "ya paso"',
    'Es hoy: ese pago ya se hizo.', $pagos[1]['motivo']);

/* Un dia antes si se proyecta: es lo que confirma que el corte es <= y no <. */
$pagos = TarjetasSupervisoras::repartirEfectivo(100000, $delSep, '2026-09-24');

chequear('el dia anterior si se proyecta', true, $pagos[1]['proyecta']);

seccion('sin importe estimado, los pagos se ven pero no proyectan');

$pagos = TarjetasSupervisoras::repartirEfectivo(null, $delMes, '2026-09-26');

chequear('siguen siendo dos', 2, count($pagos));
chequear('sin importe', null, $pagos[0]['importe']);
chequear('no proyectan', false, $pagos[0]['proyecta']);
chequear('y el motivo lo dice', 'No hay importe estimado para este mes.', $pagos[0]['motivo']);

/* ================================================================
   LA TARJETA: EL RESUMEN PISA LA ESTIMACION
   ================================================================ */
seccion('sin resumen se proyecta la estimacion, con la cobertura');

$tarjeta = ['ID' => 7, 'PCT_COBERTURA' => 10.0, 'DIA_VENCIMIENTO' => 10];
$vto = TarjetasVencimiento::delMes(10, '2026-11', $habiles);

$r = TarjetasSupervisoras::tarjetaDelMes(1000000, $tarjeta, $vto, null, '2026-09-26');

chequear('el origen es la estimacion', 'ESTIMACION', $r['origen']);
chequear('se aplica el 10 % de cobertura', 1100000.0, $r['importe']);
chequear('el estimado sin cobertura viaja aparte', 1000000.0, $r['estimado']);
chequear('la fecha es el dia de vencimiento de la tarjeta', '2026-11-10', $r['fecha']);
chequear('y se proyecta', true, $r['proyecta']);

/* SIN COBERTURA, el importe es el estimado. El 0 % es el caso normal. */
$sinCob = ['ID' => 7, 'PCT_COBERTURA' => 0.0, 'DIA_VENCIMIENTO' => 10];

chequear('con 0 % de cobertura el importe es el estimado', 1000000.0,
    TarjetasSupervisoras::tarjetaDelMes(1000000, $sinCob, $vto, null, '2026-09-26')['importe']);

seccion('con resumen cargado, manda el resumen: importe Y fecha');

/* EL RESUMEN PISA LA ESTIMACION EN LAS DOS COSAS. Si solo pisara el importe, el
   dato real saldria en la columna de la fecha estimada, que puede ser otra. */
$resumen = ['ID' => 44, 'MES' => '2026-11', 'IMPORTE_ARS' => 1234567.89,
            'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2026-11-14', 'PAGADO' => false];

$r = TarjetasSupervisoras::tarjetaDelMes(1000000, $tarjeta, $vto, $resumen, '2026-09-26');

chequear('el origen es el resumen', 'RESUMEN', $r['origen']);
chequear('el importe es el del resumen', 1234567.89, $r['importe']);
chequear('la fecha tambien es la del resumen', '2026-11-14', $r['fecha']);
chequear('y se proyecta', true, $r['proyecta']);

/* LA COBERTURA NO SE APLICA AL RESUMEN. El resumen es lo que el banco va a
   debitar; multiplicarlo por un porcentaje seria corregir un hecho. Con el 10 %
   aplicado daria 1.358.024,68. */
chequear('la cobertura NO se aplica sobre el resumen', 1234567.89, $r['importe']);

/* La estimacion sigue viajando, para poder mostrar contra que se compara. */
chequear('el estimado sigue viajando, para poder comparar', 1000000.0, $r['estimado']);

seccion('un resumen pagado sale del horizonte');

/* ESA PLATA YA SALIO. Proyectarla la pide dos veces, igual que un pago del
   cronograma con fecha pasada. */
$pagado = $resumen;
$pagado['PAGADO'] = true;

$r = TarjetasSupervisoras::tarjetaDelMes(1000000, $tarjeta, $vto, $pagado, '2026-09-26');

chequear('no se proyecta', false, $r['proyecta']);
chequear('se marca como pagado', true, $r['pagado']);
chequear('el importe se sigue informando', 1234567.89, $r['importe']);
chequear('y el motivo lo explica', true, strpos($r['motivo'], 'saldo bancario') !== false);

seccion('sin tarjeta no se proyecta nada, y no se inventa fecha');

/* APILARLO EN EL PRIMER DIA DEL EJE SERIA AFIRMAR QUE SE PAGA HOY. Sin tarjeta no
   hay dia de vencimiento, asi que no hay fecha: se avisa con el importe. */
$r = TarjetasSupervisoras::tarjetaDelMes(1000000, null, null, null, '2026-09-26');

chequear('la fecha es null', null, $r['fecha']);
chequear('no se proyecta', false, $r['proyecta']);
chequear('el importe se informa, para poder decir cuanto queda afuera',
    1000000.0, $r['importe']);
chequear('y el motivo es SIN_TARJETA', TarjetasSupervisoras::SIN_TARJETA, $r['motivo']);

seccion('una estimacion vencida sin resumen no se proyecta');

/* EL VENCIMIENTO DE ESE MES YA PASO Y EL RESUMEN NO SE CARGO: hay un dato que
   falta, no un pago que no va a salir. Proyectarlo en una fecha pasada lo pondria
   en una columna que ya no representa nada. */
$vtoPasado = TarjetasVencimiento::delMes(10, '2026-09', $habiles);

chequear('el 10 de septiembre de 2026 es jueves', '2026-09-10', $vtoPasado['fecha']);

$r = TarjetasSupervisoras::tarjetaDelMes(1000000, $tarjeta, $vtoPasado, null, '2026-09-26');

chequear('no se proyecta', false, $r['proyecta']);
chequear('y el motivo dice que falta el resumen', true,
    strpos($r['motivo'], 'todavía no se cargó el resumen') !== false);

/* CON RESUMEN CARGADO, ESE MISMO MES SI SE PROYECTA: el dato ya no falta. */
$resumenSep = ['ID' => 45, 'MES' => '2026-09', 'IMPORTE_ARS' => 900000.0,
               'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2026-09-30', 'PAGADO' => false];

chequear('con el resumen cargado, ese mes vuelve a proyectarse', true,
    TarjetasSupervisoras::tarjetaDelMes(1000000, $tarjeta, $vtoPasado, $resumenSep,
        '2026-09-26')['proyecta']);

/* ================================================================
   LA CLAVE DEL CRUCE POR NOMBRE

   RO_T_GASTOS_SUPERVISION.SUPERVISORA guarda el NOMBRE, no un ID, asi que el
   cruce con el maestro es por nombre. La clave tiene que ser la misma en los dos
   lados.
   ================================================================ */
seccion('el cruce por nombre normaliza igual en los dos lados');

chequear('mayusculas', 'SONIA PACIFICO', GastosSupervision::clave('Sonia Pacifico'));
chequear('y espacios de mas', 'SONIA PACIFICO', GastosSupervision::clave('  SONIA PACIFICO  '));
chequear('el mismo nombre tipeado distinto da la misma clave',
    GastosSupervision::clave('sonia pacifico'), GastosSupervision::clave('SONIA PACIFICO'));

/* LOS ACENTOS NO SE NORMALIZAN ACA, y es a proposito: la collation de las dos
   columnas (Modern_Spanish_CI_AI) ya es acento-insensible, y normalizar dos veces
   y de dos formas distintas es lo que deja de coincidir. */
chequear('los acentos se dejan como estan', 'JOSÉ', GastosSupervision::clave('josé'));

/* ================================================================
   EL CARTEL DE LA PESTANA

   Lo arma el backend, porque describe una cuenta que hace el backend.
   ================================================================ */
seccion('el cartel explica la ventana y el mes base');

$texto = TarjetasSupervisoras::explicarVentana($v);

chequear('nombra la ventana', true, strpos($texto, 'jun-26 a ago-26') !== false);
chequear('dice que son los autorizados', true, strpos($texto, 'autorizados') !== false);
chequear('explica la division por tres', true, strpos($texto, 'divide por 3') !== false);
chequear('y nombra el mes base de la inflacion', true, strpos($texto, '2026-08') !== false);

/* EL TOOLTIP DE UNA CELDA LLEVA LAS TRES COSAS que hay que poder verificar:
   promedio, factor acumulado y proporcion. */
$e = TarjetasSupervisoras::estimar($p, $meses, $inflacion, $v, $estados);
$tooltip = $e['ANA']['meses']['2026-11']['tooltip'];

chequear('el tooltip trae el promedio', true, strpos($tooltip, '400.000,00') !== false);
chequear('el factor de inflacion acumulado', true, strpos($tooltip, '1,0612') !== false);
chequear('dice que es compuesta', true, strpos($tooltip, 'compuesta') !== false);
chequear('y la proporcion', true, strpos($tooltip, '% efectivo') !== false);

/* Con menos de tres meses, el tooltip explica por que se divide por tres igual:
   es la pregunta que alguien va a hacer al ver el numero. */
$pNueva = TarjetasSupervisoras::promedios($nueva, $v);
$eNueva = TarjetasSupervisoras::estimar($pNueva, $meses, $inflacion, $v,
    ['BEA' => ['en_maestro' => true, 'activa' => true, 'id' => 2]]);

chequear('con un solo mes, el tooltip lo dice', true,
    strpos($eNueva['BEA']['meses']['2026-11']['tooltip'], '1 de los 3 meses') !== false);
