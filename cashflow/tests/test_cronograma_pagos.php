<?php
/**
 * El cronograma de pagos: 2do y 4to viernes, corrimiento al dia habil anterior
 * y override manual.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL CORRIMIENTO VA PARA EL LADO EQUIVOCADO. Ventas corre una acreditacion
 *      al PROXIMO dia habil; un pago se corre al ANTERIOR. Las dos reglas viven
 *      en el mismo modulo y se parecen mucho. Invertida, la plata sale el lunes
 *      siguiente en vez del jueves anterior: el tablero no falla, muestra otra
 *      columna, y en un cruce de mes la muestra en otro MES.
 *
 *   2. "EL CUARTO VIERNES" SE CONFUNDE CON "EL ULTIMO VIERNES". En un mes con
 *      cinco viernes los dos son distintos, y como la mayoria de los meses tiene
 *      cuatro, un error asi acierta tres de cada cuatro veces.
 *
 *   3. EL OVERRIDE SE CORRE AL DIA HABIL. Lo cargo una persona que sabe algo que
 *      el calendario no sabe. Corregirlo seria contradecirla en silencio.
 *
 *   4. EL FALLBACK DE CALENDARIO NO AVISA. RO_T_CALENDARIO llega hasta 2027: en
 *      cuanto el horizonte la pase, cada fecha se resuelve por lunes-a-viernes.
 *      Eso esta bien; lo que no puede pasar es que nadie se entere, porque los
 *      feriados dejan de correr pagos sin que nada cambie en pantalla.
 *
 * No toca la base: el mapa de dias habiles se arma aca.
 */

require_once __DIR__ . '/../Class/CronogramaPagos.php';
require_once __DIR__ . '/../Class/Horizonte.php';

/**
 * Mapa de dias habiles de lunes a viernes entre dos fechas, con los feriados
 * que se le pasen marcados como NO habiles.
 *
 * Se arma aca y no se lee de RO_T_CALENDARIO para que la prueba corra en
 * cualquier maquina y, sobre todo, para poder poner un feriado justo donde hace
 * falta: contra la base real habria que esperar a que el calendario tuviera el
 * caso.
 */
function calendarioCP($desde, $hasta, $feriados = []) {
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

/* La configuracion de Logistica, que es la que estas pruebas fijaron siempre:
   el 2do y el 4to viernes. Desde que el cronograma es por concepto, el viernes
   ya no esta en el codigo y hay que pasarlo. */
$VIE = CronogramaPagos::config(5, CronogramaPagos::QUINCENAL);

/* ================================================================
   LOS VIERNES DEL MES
   ================================================================ */
seccion('que viernes son el 2do y el 4to');

// Septiembre 2026: viernes 4, 11, 18 y 25. Cuatro viernes.
chequear('septiembre 2026 tiene cuatro viernes',
    ['2026-09-04', '2026-09-11', '2026-09-18', '2026-09-25'],
    CronogramaPagos::diasDelMes(2026, 9, 5));

chequear('y sus dos pagos son el 11 y el 25',
    [1 => '2026-09-11', 2 => '2026-09-25'],
    CronogramaPagos::teoricasDelMes(2026, 9, $VIE));

/* EL MES CON CINCO VIERNES. Octubre 2026 arranca jueves, asi que tiene viernes
   2, 9, 16, 23 y 30. El 4to es el 23 y el ULTIMO es el 30: si el codigo tomara
   "el ultimo" en vez de "el cuarto", este mes pagaria una semana mas tarde. */
seccion('un mes con cinco viernes');

chequear('octubre 2026 tiene cinco viernes',
    ['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'],
    CronogramaPagos::diasDelMes(2026, 10, 5));

chequear('el 4to viernes es el 23, no el ultimo (30)',
    '2026-10-23', CronogramaPagos::teoricasDelMes(2026, 10, $VIE)[2]);

chequear('y el 2do es el 9', '2026-10-09', CronogramaPagos::teoricasDelMes(2026, 10, $VIE)[1]);

// Mayo 2026 arranca viernes: 1, 8, 15, 22, 29. Otro mes de cinco, y con el
// primer viernes el dia 1, que es el caso limite del salto al primer viernes.
chequear('mayo 2026 arranca viernes y tiene cinco',
    ['2026-05-01', '2026-05-08', '2026-05-15', '2026-05-22', '2026-05-29'],
    CronogramaPagos::diasDelMes(2026, 5, 5));

chequear('sus pagos son el 8 y el 22',
    [1 => '2026-05-08', 2 => '2026-05-22'],
    CronogramaPagos::teoricasDelMes(2026, 5, $VIE));

// Febrero de 28 dias que arranca lunes es el mes mas corto posible en semanas:
// febrero 2027 arranca lunes y tiene exactamente cuatro viernes. Es la prueba
// de que "siempre son dos pagos" no depende de la suerte del calendario.
seccion('el mes mas corto posible sigue teniendo dos pagos');

chequear('febrero 2027 tiene exactamente cuatro viernes',
    4, count(CronogramaPagos::diasDelMes(2027, 2, 5)));

chequear('y sus dos pagos existen',
    [1 => '2027-02-12', 2 => '2027-02-26'],
    CronogramaPagos::teoricasDelMes(2027, 2, $VIE));

seccion('un mes invalido no se resuelve en silencio');

chequearLanza('el mes 13 lanza', function () {
    CronogramaPagos::diasDelMes(2026, 13, 5);
}, 'Mes invalido: 13');

/* ================================================================
   EL CORRIMIENTO AL DIA HABIL ANTERIOR
   ================================================================ */
seccion('un viernes feriado se corre HACIA ATRAS');

/* El caso real del horizonte: el 4to viernes de diciembre 2026 es el 25, que es
   Navidad. El pago se corre al jueves 24. */
$habilesDic = calendarioCP('2026-11-01', '2027-01-31', ['2026-12-25']);
$dic = CronogramaPagos::delMes('2026-12', $VIE, $habilesDic);

chequear('el 4to viernes de diciembre 2026 es el 25', '2026-12-25', $dic[1]['teorica']);
chequear('y como es feriado el pago va el 24', '2026-12-24', $dic[1]['fecha']);
chequear('la fila dice que se corrio', true, $dic[1]['corrida']);
chequear('el 2do viernes no se toco', '2026-12-11', $dic[0]['fecha']);
chequear('y no figura corrido', false, $dic[0]['corrida']);

/* NO SE CORRE AL LUNES SIGUIENTE. Es el error que esta prueba existe para
   atrapar: 2026-12-28 es el proximo habil, y seria la respuesta si el
   corrimiento fuera el de Ventas. */
chequear('NO se corrio al proximo habil', true, $dic[1]['fecha'] < $dic[1]['teorica']);

seccion('un feriado encadenado sigue retrocediendo');

// Jueves 24 y viernes 25 feriados: el pago cae el miercoles 23.
$habilesDoble = calendarioCP('2026-11-01', '2027-01-31', ['2026-12-24', '2026-12-25']);
$dicDoble = CronogramaPagos::delMes('2026-12', $VIE, $habilesDoble);

chequear('con el jueves tambien feriado, el pago va el miercoles 23',
    '2026-12-23', $dicDoble[1]['fecha']);

seccion('el corrimiento puede cruzar de mes y de anio');

/* El 2do viernes de enero 2027 es el 8. Si el 8, el 7, el 6, el 5, el 4 y el 1
   fueran no habiles, el pago retrocede hasta el 31 de diciembre de 2026: otro
   mes Y otro anio. Es un escenario inventado -no hay seis feriados seguidos en
   enero- pero es exactamente la aritmetica que hay que garantizar, porque
   restar dias con substr() sobre el string la rompe. */
$habilesEne = calendarioCP('2026-12-01', '2027-02-28',
    ['2027-01-08', '2027-01-07', '2027-01-06', '2027-01-05', '2027-01-04', '2027-01-01']);
$ene = CronogramaPagos::delMes('2027-01', $VIE, $habilesEne);

chequear('el 2do viernes de enero 2027 es el 8', '2027-01-08', $ene[0]['teorica']);
chequear('con la primera semana no habil retrocede a 2026-12-31',
    '2026-12-31', $ene[0]['fecha']);

seccion('sin ningun dia habil cerca, lanza en vez de inventar una fecha');

$sinHabiles = [];
$cursor = new DateTime('2026-11-01');

while ($cursor <= new DateTime('2026-12-31')) {
    $sinHabiles[$cursor->format('Y-m-d')] = false;
    $cursor->modify('+1 day');
}

chequearLanza('un mapa sin ningun habil lanza', function () use ($sinHabiles) {
    CronogramaPagos::habilAnterior('2026-12-25', $sinHabiles);
});

/* ================================================================
   EL FALLBACK CUANDO FALTA LA FECHA EN EL CALENDARIO
   ================================================================ */
seccion('una fecha ausente de RO_T_CALENDARIO no rompe: se asume lunes a viernes');

// Mapa vacio: ninguna fecha esta cargada.
$sinCalendario = CronogramaPagos::habilAnterior('2028-03-10', []);

chequear('un viernes sin dato se toma como habil', '2028-03-10', $sinCalendario['fecha']);
chequear('y el mes queda anotado como faltante', ['2028-03'], $sinCalendario['faltan']);

// Un domingo sin dato retrocede al viernes: el fallback tambien corre.
$domingo = CronogramaPagos::habilAnterior('2028-03-12', []);

chequear('un domingo sin dato retrocede al viernes', '2028-03-10', $domingo['fecha']);

chequear('el aviso tiene la misma redaccion que el de Ventas',
    'RO_T_CALENDARIO no tiene datos para 2028-03. Se asumen hábiles los días de lunes a viernes.',
    CronogramaPagos::avisosCalendario(['2028-03'])[0]);

/* ================================================================
   EL OVERRIDE
   ================================================================ */
seccion('un override manda sobre el calculo');

$overrides = ['2026-12' => [2 => ['fecha' => '2026-12-18', 'motivo' => 'Cierre de ano']]];
$dicOv = CronogramaPagos::delMes('2026-12', $VIE, $habilesDic, $overrides);

chequear('el pago 2 toma la fecha cargada a mano', '2026-12-18', $dicOv[1]['fecha']);
chequear('y queda marcado como override', true, $dicOv[1]['override']);
chequear('con su motivo', 'Cierre de ano', $dicOv[1]['motivo']);

/* LAS OTRAS DOS FECHAS SIGUEN VIAJANDO. Sin ellas la pantalla no puede decir de
   cuanto fue la correccion ni por que el calculo daba el 24. */
chequear('la teorica sigue siendo el 25', '2026-12-25', $dicOv[1]['teorica']);
chequear('y la calculada el 24', '2026-12-24', $dicOv[1]['calculada']);

chequear('el pago 1, sin override, no se marca', false, $dicOv[0]['override']);

seccion('el override NO se corre al dia habil');

// 2026-12-26 es sabado. Cargado a mano, se respeta tal cual.
$ovSabado = ['2026-12' => [2 => ['fecha' => '2026-12-26', 'motivo' => 'Acordado']]];
$dicSab = CronogramaPagos::delMes('2026-12', $VIE, $habilesDic, $ovSabado);

chequear('un sabado cargado a mano se respeta', '2026-12-26', $dicSab[1]['fecha']);

/* ================================================================
   EL CRONOGRAMA COMPLETO CONTRA UN HORIZONTE
   ================================================================ */
seccion('el cronograma de todo el horizonte');

/* Hoy = 25/09/2026, 28 dias de tramo diario (25/09 .. 22/10) y 12 meses.
   Es el horizonte real de la base al escribir esto. */
$hCP = new Horizonte(28, 12, [], new DateTime('2026-09-25'));
$habilesH = calendarioCP('2026-08-01', '2027-09-30',
    ['2026-11-20', '2026-12-25', '2027-01-01', '2027-03-26', '2027-04-02', '2027-07-09']);

$cronoH = CronogramaPagos::paraHorizonte($hCP, $VIE, $habilesH);

chequear('doce meses dan veinticuatro pagos', 24, count($cronoH['pagos']));
chequear('y no falta ningun mes de calendario', [], $cronoH['faltan']);

$porClave = [];

foreach ($cronoH['pagos'] as $p) {
    $porClave[$p['mes'] . '|' . $p['nro']] = $p;
}

/* EL CASO QUE DECIDE EN QUE COLUMNA VA CADA MITAD. El tramo diario va del 25/09
   al 22/10. Octubre queda PARTIDO: el pago del 9 cae adentro y el del 23
   afuera, asi que el segundo va a la columna mensual de Oct-26 y el primero a
   su columna diaria. Sin el cronograma de los meses que estan fuera del tramo,
   esto no se puede decidir. */
chequear('el pago 1 de octubre cae dentro del tramo diario',
    true, $porClave['2026-10|1']['en_tramo']);
chequear('y es el 9', '2026-10-09', $porClave['2026-10|1']['fecha']);
chequear('el pago 2 de octubre queda FUERA del tramo',
    false, $porClave['2026-10|2']['en_tramo']);
chequear('y es el 23', '2026-10-23', $porClave['2026-10|2']['fecha']);

// Los dos pagos de septiembre son anteriores o iguales a hoy: el 11 ya paso y
// el 25 es hoy. Los dos existen en el cronograma -el cronograma describe el
// calendario, no lo que falta pagar- y es el proveedor el que los descarta.
chequear('septiembre tiene sus dos pagos igual', '2026-09-11', $porClave['2026-09|1']['fecha']);
chequear('y el segundo es hoy', '2026-09-25', $porClave['2026-09|2']['fecha']);

// Los tres feriados reales que caen justo en un 2do o 4to viernes del horizonte.
chequear('25/12/2026 (4to viernes, Navidad) corre al 24',
    '2026-12-24', $porClave['2026-12|2']['fecha']);
chequear('26/03/2027 (4to viernes, Viernes Santo) corre al 25',
    '2027-03-25', $porClave['2027-03|2']['fecha']);
chequear('09/07/2027 (2do viernes, Independencia) corre al 8',
    '2027-07-08', $porClave['2027-07|1']['fecha']);

// 20/11/2026 es feriado y es viernes, pero es el TERCERO: no es un pago, asi
// que no mueve nada. Es el control de que no se corre lo que no hay que correr.
chequear('el feriado del 20/11 no toca ningun pago de noviembre',
    ['2026-11-13', '2026-11-27'],
    [$porClave['2026-11|1']['fecha'], $porClave['2026-11|2']['fecha']]);

seccion('el rango de calendario que hay que leer');

$rango = CronogramaPagos::rangoCalendario($hCP);

chequear('arranca un mes antes del primer mes del eje', '2026-08-01', $rango['desde']);
chequear('y llega hasta el fin del eje', $hCP->fin(), $rango['hasta']);

/* ================================================================
   UN CRONOGRAMA POR CONCEPTO: CUALQUIER DIA, DOS FRECUENCIAS
   ================================================================ */
seccion('quincenal: el 2do y el 4to de CUALQUIER dia de la semana');

/* Octubre 2026 arranca jueves. Para cada dia de la semana, el 2do y el 4to son
   el 2do y el 4to de la lista de ese dia: si el codigo siguiera teniendo el
   viernes escrito en algun lado, alguno de los siete daria otra cosa. */
foreach (CronogramaPagos::DIAS as $dia => $nombre) {
    $todos = CronogramaPagos::diasDelMes(2026, 10, $dia);
    $teo = CronogramaPagos::teoricasDelMes(2026, 10,
        CronogramaPagos::config($dia, CronogramaPagos::QUINCENAL));

    chequear('quincenal ' . $nombre . ': dos pagos, el 2do y el 4to',
        [1 => $todos[1], 2 => $todos[3]], $teo);

    $esDia = true;

    foreach ($todos as $f) {
        $esDia = $esDia && (intval(date('N', strtotime($f))) === $dia);
    }

    chequear('y todas las fechas de la lista son ' . $nombre, true, $esDia);
}

chequear('el 2do y 4to miercoles de octubre 2026 son el 14 y el 28',
    [1 => '2026-10-14', 2 => '2026-10-28'],
    CronogramaPagos::teoricasDelMes(2026, 10, CronogramaPagos::config(3, 'QUINCENAL')));

seccion('semanal: todos los de ese dia del mes, cuatro o cinco');

$LUN = CronogramaPagos::config(1, CronogramaPagos::SEMANAL);

// Agosto 2026 arranca sabado: lunes 3, 10, 17, 24 y 31. Cinco lunes.
chequear('agosto 2026 tiene cinco lunes y son cinco pagos',
    [1 => '2026-08-03', 2 => '2026-08-10', 3 => '2026-08-17', 4 => '2026-08-24',
     5 => '2026-08-31'],
    CronogramaPagos::teoricasDelMes(2026, 8, $LUN));

// Septiembre 2026: lunes 7, 14, 21 y 28. Cuatro.
chequear('septiembre 2026 tiene cuatro lunes y son cuatro pagos', 4,
    count(CronogramaPagos::teoricasDelMes(2026, 9, $LUN)));

$JUE = CronogramaPagos::config(4, CronogramaPagos::SEMANAL);
chequear('semanal sirve con cualquier dia: los jueves de octubre 2026 son cinco', 5,
    count(CronogramaPagos::teoricasDelMes(2026, 10, $JUE)));

$agosto = CronogramaPagos::delMes('2026-08', $LUN, calendarioCP('2026-07-01', '2026-09-30'));
chequear('cada pago dice en cuantas partes se reparte el mes', 5, $agosto[4]['cantidad']);
chequear('y se nombra por su ordinal', '5to lunes', $agosto[4]['nombre']);

seccion('la configuracion se valida y se dice en palabras');

chequearLanza('un dia 8 no existe', function () {
    CronogramaPagos::config(8, 'QUINCENAL');
}, "Día de la semana inválido: '8'. Va de 1 (lunes) a 7 (domingo).");

chequearLanza('una frecuencia mensual no existe', function () {
    CronogramaPagos::config(3, 'MENSUAL');
});

chequearLanza('un concepto que no existe se rechaza', function () {
    CronogramaPagos::validarConcepto('ALQUILERES');
});

chequear('quincenal se dice "el 2do y el 4to miércoles"', 'el 2do y el 4to miércoles',
    CronogramaPagos::describir(['dia' => 3, 'frecuencia' => 'QUINCENAL']));
chequear('semanal se dice "todos los lunes"', 'todos los lunes',
    CronogramaPagos::describir(['dia' => 1, 'frecuencia' => 'SEMANAL']));
chequear('el pago 2 quincenal de viernes es el "4to viernes"', '4to viernes',
    CronogramaPagos::nombrePago(2, $VIE));

seccion('los valores por defecto son los de la especificacion');

chequear('Proveedores Locales: miercoles quincenal',
    [3, 'QUINCENAL'],
    [CronogramaPagos::CONCEPTOS['PROV_LOCALES']['dia'],
     CronogramaPagos::CONCEPTOS['PROV_LOCALES']['frecuencia']]);
chequear('Logistica: viernes quincenal', [5, 'QUINCENAL'],
    [CronogramaPagos::CONCEPTOS['LOGISTICA']['dia'],
     CronogramaPagos::CONCEPTOS['LOGISTICA']['frecuencia']]);
chequear('Supervisoras: lunes semanal', [1, 'SEMANAL'],
    [CronogramaPagos::CONCEPTOS['SUPERVISORAS']['dia'],
     CronogramaPagos::CONCEPTOS['SUPERVISORAS']['frecuencia']]);

/* Sin el parametro va el defecto CON AVISO: un cronograma sin dia dejaria al
   consumidor sin fechas y su fila en cero sin explicacion. */
$sinParam = CronogramaPagos::configDesdeMapa([], 'SUPERVISORAS');
chequear('sin parametros va el defecto', [1, 'SEMANAL'],
    [$sinParam['dia'], $sinParam['frecuencia']]);
chequear('y lo dice nombrando el script', true,
    strpos($sinParam['aviso'], 'sql/cashflow_cronograma_conceptos.sql') !== false);

$conParam = CronogramaPagos::configDesdeMapa(
    ['cronograma_supervisoras_dia' => '2', 'cronograma_supervisoras_frecuencia' => 'quincenal'],
    'SUPERVISORAS');
chequear('con parametros manda el parametro, normalizado', [2, 'QUINCENAL', false, null],
    [$conParam['dia'], $conParam['frecuencia'], $conParam['defecto'], $conParam['aviso']]);

$malo = CronogramaPagos::configDesdeMapa(
    ['cronograma_logistica_dia' => '9', 'cronograma_logistica_frecuencia' => 'QUINCENAL'],
    'LOGISTICA');
chequear('un valor que no se entiende cae al defecto y avisa', [5, true, true],
    [$malo['dia'], $malo['defecto'], $malo['aviso'] !== null]);

chequear('las claves del parametro llevan el concepto en minuscula',
    ['dia' => 'cronograma_prov_locales_dia', 'frecuencia' => 'cronograma_prov_locales_frecuencia'],
    CronogramaPagos::clavesParametro('PROV_LOCALES'));

seccion('feriado en un dia de pago: al habil ANTERIOR, en cualquier concepto');

// Lunes 12/10/2026 feriado: el 2do lunes de octubre se corre al viernes 9.
$habOct = calendarioCP('2026-09-01', '2026-11-30', ['2026-10-12']);
$octLun = CronogramaPagos::delMes('2026-10', $LUN, $habOct);
chequear('el 2do lunes de octubre (12, feriado) se paga el viernes 9',
    '2026-10-09', $octLun[1]['fecha']);
chequear('y queda marcado corrido', true, $octLun[1]['corrida']);

// Miercoles 14/10 feriado: el 2do miercoles va el martes 13.
$habOct2 = calendarioCP('2026-09-01', '2026-11-30', ['2026-10-14']);
$octMie = CronogramaPagos::delMes('2026-10', CronogramaPagos::config(3, 'QUINCENAL'), $habOct2);
chequear('el 2do miercoles feriado (14) se paga el martes 13', '2026-10-13', $octMie[0]['fecha']);

/* UN PAGO PUEDE CAER EN EL MES ANTERIOR: febrero 2027 arranca lunes. Con el
   lunes 1 feriado, el 1er pago semanal de febrero se paga el viernes 29 de
   enero. Es correcto y es por esto que el calendario se lee desde un mes antes. */
$habFeb = calendarioCP('2027-01-01', '2027-03-31', ['2027-02-01']);
$feb = CronogramaPagos::delMes('2027-02', $LUN, $habFeb);
chequear('el 1er lunes de febrero 2027 feriado se paga el 29 de enero',
    '2027-01-29', $feb[0]['fecha']);
chequear('y sigue siendo un pago de febrero', '2027-02', $feb[0]['mes']);

seccion('el override de un concepto no afecta a los otros');

/* Los overrides se leen POR CONCEPTO (CronogramaDatos::overrides()), asi que a
   cada cronograma le llega solo el suyo. Aca se arma ese caso: el mismo mes y el
   mismo numero de pago movidos en Logistica, y Proveedores Locales sin nada. */
$habNov = calendarioCP('2026-10-01', '2026-12-31');
$ovLog = ['2026-11' => [1 => ['fecha' => '2026-11-12', 'motivo' => 'Adelanto']]];
$overridesPorConcepto = ['LOGISTICA' => $ovLog, 'PROV_LOCALES' => []];

$novLog = CronogramaPagos::delMes('2026-11', $VIE, $habNov, $overridesPorConcepto['LOGISTICA']);
$novProv = CronogramaPagos::delMes('2026-11', CronogramaPagos::config(3, 'QUINCENAL'), $habNov,
    $overridesPorConcepto['PROV_LOCALES']);

chequear('Logistica toma su override', ['2026-11-12', true],
    [$novLog[0]['fecha'], $novLog[0]['override']]);
chequear('Proveedores Locales, el mismo mes y nro, sigue en su 2do miercoles',
    ['2026-11-11', false], [$novProv[0]['fecha'], $novProv[0]['override']]);

seccion('el reparto de un importe mensual: partes iguales, sin redondear');

chequear('quincenal: mitad', 50.0, CronogramaPagos::parte(100.0, 2));
chequear('semanal de cuatro: un cuarto', 25.0, CronogramaPagos::parte(100.0, 4));
chequear('semanal de cinco: un quinto', 20.0, CronogramaPagos::parte(100.0, 5));

/* SIN REDONDEAR: 100 / 3 tiene infinitos decimales y las tres partes tienen que
   volver a sumar 100. Redondeadas a centavos sumarian 99,99. */
$tercio = CronogramaPagos::parte(100.0, 3);
chequear('las partes vuelven a sumar el importe exacto', true,
    abs(($tercio * 3) - 100.0) < 1e-9 && round($tercio, 2) * 3 != 100.0);
chequear('un importe que falta sigue faltando', null, CronogramaPagos::parte(null, 4));

seccion('el proximo pago a partir de una fecha base');

$hNov = new Horizonte(28, 3, [], new DateTime('2026-10-05'));
$cronoMie = CronogramaPagos::paraHorizonte($hNov, CronogramaPagos::config(3, 'QUINCENAL'),
    calendarioCP('2026-09-01', '2027-02-28', ['2026-10-28']), [], 1);

/* Octubre: 14 y 28 (feriado, corre al 27). Noviembre: 11 y 25. Diciembre: 9 y
   23. Mas el mes extra, enero 2027: 13 y 27. */
chequear('un mes extra agrega los pagos de enero', true,
    in_array('2027-01-13', array_column($cronoMie['pagos'], 'fecha'), true));
chequear('y esos pagos dicen que estan fuera del horizonte', false,
    $cronoMie['pagos'][count($cronoMie['pagos']) - 1]['en_horizonte']);

$prox = function ($base) use ($cronoMie) {
    $p = CronogramaPagos::proximoPago($base, $cronoMie['pagos'], '2026-10-05');

    return $p === null ? null : $p['fecha'];
};

chequear('vence entre dos pagos: va al siguiente', '2026-11-11', $prox('2026-10-30'));
chequear('vence el dia de pago: se paga ese dia', '2026-11-11', $prox('2026-11-11'));
chequear('vence entre el pago corrido (27) y el teorico (28): va al siguiente',
    '2026-11-11', $prox('2026-10-28'));
chequear('ya vencida: va al proximo pago desde hoy, no al primer dia del eje',
    '2026-10-14', $prox('2026-08-01'));
chequear('vence despues del ultimo pago: no hay', null, $prox('2027-02-01'));

$hoyPago = CronogramaPagos::proximoPago('2026-09-01', $cronoMie['pagos'], '2026-10-14');
chequear('un pago que es hoy cuenta', '2026-10-14', $hoyPago['fecha']);

/* POR FECHA, NO POR NUMERO: si el pago 2 de noviembre se adelanta a mano al 10,
   pasa a ser el proximo de una factura que vence el 2. */
$cronoOv = CronogramaPagos::paraHorizonte($hNov, CronogramaPagos::config(3, 'QUINCENAL'),
    calendarioCP('2026-09-01', '2027-01-31'),
    ['2026-11' => [2 => ['fecha' => '2026-11-10', 'motivo' => 'x']]]);
$pOv = CronogramaPagos::proximoPago('2026-11-02', $cronoOv['pagos'], '2026-10-05');
chequear('un override que se adelanta al otro pago gana por fecha', [2, '2026-11-10'],
    [$pOv['nro'], $pOv['fecha']]);

seccion('el rango de calendario con meses extra');

$rangoExtra = CronogramaPagos::rangoCalendario($hNov, 1);
chequear('llega hasta el fin del mes extra', '2027-01-31', $rangoExtra['hasta']);
