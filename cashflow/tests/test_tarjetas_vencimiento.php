<?php
/**
 * El vencimiento del resumen de una tarjeta, y la inflacion COMPUESTA.
 *
 * Las dos cosas van en el mismo archivo porque son las dos funciones puras que
 * comparten las tres sub-pestanas de Pagos con Tarjetas y Otros: donde cae el
 * debito, y como se lleva un promedio historico al mes en que se va a pagar.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL CORRIMIENTO VA PARA EL LADO EQUIVOCADO. El modulo tiene las dos
 *      direcciones: un pago a un fletero se corre al dia habil ANTERIOR y el
 *      debito de una tarjeta al SIGUIENTE. Invertida, el debito sale el jueves
 *      anterior en vez del lunes siguiente: el tablero no falla, muestra otra
 *      columna, y en un cruce de mes la muestra en otro MES.
 *
 *   2. EL MES CORTO. Una tarjeta que vence el 31 no vence el 31 de febrero, que
 *      no existe. Sin acotar, la fecha se desborda al mes siguiente y el debito
 *      de febrero cae en marzo. Y el orden de los dos pasos importa: acotar
 *      primero y correr despues, porque correr un 31 de febrero parte de una
 *      fecha que no existe.
 *
 *   3. LA INFLACION SE SUMA EN VEZ DE COMPONERSE. El modulo tiene las dos
 *      cuentas -acumulada() suma sin componer porque reproduce un ajuste pactado;
 *      compuesta() multiplica porque proyecta- y dan distinto: 6 % contra
 *      6,12 % en un trimestre al 2 %. Usar la equivocada no falla, solo proyecta
 *      de menos y cada vez mas a medida que el mes se aleja.
 *
 *   4. UN MES DE INFLACION QUE FALTA SE TOMA COMO CERO. Eso proyecta DE MENOS y
 *      nadie tiene donde enterarse. Tiene que dar null, que es la regla del
 *      modulo entero.
 *
 * No toca la base: el mapa de dias habiles y el de inflacion se arman aca. Eso
 * es lo que permite poner un feriado justo donde hace falta, que contra
 * RO_T_CALENDARIO habria que esperar a que el calendario tuviera el caso.
 */

require_once __DIR__ . '/../Class/TarjetasVencimiento.php';
require_once __DIR__ . '/../Class/Inflacion.php';
require_once __DIR__ . '/../Class/Horizonte.php';
/* CronogramaPagos entra para poder CONTRASTAR contra el: las dos reglas corren
   en direcciones opuestas y piden rangos de calendario opuestos, y eso se fija
   comparando las dos y no describiendo una. */
require_once __DIR__ . '/../Class/CronogramaPagos.php';

/**
 * Mapa de dias habiles de lunes a viernes entre dos fechas, con los feriados que
 * se le pasen marcados como NO habiles.
 *
 * Es la misma idea que calendarioCP() de test_cronograma_pagos.php, y va
 * duplicada a proposito: las dos pruebas tienen que poder cambiar su calendario
 * sin tocar la otra, y un helper compartido entre archivos que se cargan en el
 * mismo proceso ademas choca por el nombre.
 */
function calendarioTV($desde, $hasta, $feriados = []) {
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
   EL DIA DEL MES, SIN CORRIMIENTO
   ================================================================ */
seccion('el dia del mes cuando es habil');

$habiles = calendarioTV('2026-01-01', '2028-12-31');

$r = TarjetasVencimiento::delMes(10, '2026-09', $habiles);

chequear('el 10 de septiembre de 2026 es jueves y no se mueve', '2026-09-10', $r['fecha']);
chequear('la teorica es la misma', '2026-09-10', $r['teorica']);
chequear('no se corrio', false, $r['corrida']);
chequear('y no hizo falta el ultimo dia del mes', false, $r['ultimo_dia']);
chequear('el mes viaja con la fecha', '2026-09', $r['mes']);
chequear('y el dia pedido tambien, para poder explicar la cuenta', 10, $r['dia_pedido']);

/* ================================================================
   MESES CORTOS

   Una tarjeta que vence el 31 no vence el 31 de febrero. Se usa el ULTIMO dia
   del mes, y recien despues se corre al habil siguiente.
   ================================================================ */
seccion('meses cortos: el dia 31 en un mes que no lo tiene');

// Noviembre 2026 tiene 30 dias, y el 30 es LUNES: se acota y no se corre.
$r = TarjetasVencimiento::delMes(31, '2026-11', $habiles);

chequear('el 31 de noviembre es el 30', '2026-11-30', $r['fecha']);
chequear('y se marca que se uso el ultimo dia', true, $r['ultimo_dia']);
chequear('sin corrimiento, porque el 30 es lunes', false, $r['corrida']);

// Febrero 2026 tiene 28 dias y el 28 es SABADO. Se acota Y se corre, en ese
// orden, y termina en MARZO: los dos efectos juntos y un cruce de mes.
$r = TarjetasVencimiento::delMes(31, '2026-02', $habiles);

chequear('el 31 de febrero de 2026 se acota al 28', '2026-02-28', $r['teorica']);
chequear('que es sabado, asi que el debito cae el lunes 2 de marzo',
    '2026-03-02', $r['fecha']);
chequear('se marca el ultimo dia', true, $r['ultimo_dia']);
chequear('y se marca el corrimiento', true, $r['corrida']);

/* EL ANIO BISIESTO. Febrero 2028 tiene 29 dias, asi que el 29 EXISTE y no es un
   ultimo dia forzado, y el 30 SI lo es. La distincion importa porque
   'ultimo_dia' es lo que el tooltip usa para explicar la fecha: decirlo cuando
   no paso seria explicar algo que no ocurrio. */
chequear('el 29 de febrero de 2028 existe: no se acota nada',
    false, TarjetasVencimiento::delMes(29, '2028-02', $habiles)['ultimo_dia']);
chequear('y cae martes, asi que no se mueve',
    '2028-02-29', TarjetasVencimiento::delMes(29, '2028-02', $habiles)['fecha']);
chequear('el 30 de febrero de 2028 si se acota al 29',
    '2028-02-29', TarjetasVencimiento::delMes(30, '2028-02', $habiles)['fecha']);
chequear('y se marca', true, TarjetasVencimiento::delMes(30, '2028-02', $habiles)['ultimo_dia']);

/* ================================================================
   EL CORRIMIENTO AL HABIL SIGUIENTE

   Es la direccion CONTRARIA a CronogramaPagos::habilAnterior(), y es lo que esta
   prueba existe para fijar.
   ================================================================ */
seccion('fin de semana: se corre hacia ADELANTE');

// 15/02/2026 es domingo.
$r = TarjetasVencimiento::delMes(15, '2026-02', $habiles);

chequear('un domingo pasa al lunes siguiente', '2026-02-16', $r['fecha']);
chequear('y NO al viernes anterior, que es lo que hace el cronograma de pagos',
    true, $r['fecha'] > $r['teorica']);

// 10/10/2026 es sabado.
chequear('un sabado pasa al lunes, saltando el domingo',
    '2026-10-12', TarjetasVencimiento::delMes(10, '2026-10', $habiles)['fecha']);

seccion('feriado, y feriado encadenado');

/* El 25/12/2026 es VIERNES y feriado: 26 sabado, 27 domingo, asi que el debito
   cae el lunes 28. Tres dias no habiles seguidos. */
$conNavidad = calendarioTV('2026-01-01', '2027-12-31', ['2026-12-25']);

chequear('el 25/12/2026 es feriado y viernes: el debito cae el lunes 28',
    '2026-12-28', TarjetasVencimiento::delMes(25, '2026-12', $conNavidad)['fecha']);

/* EL CRUCE DE ANIO, con la cadena mas larga posible del calendario real: el
   31/12/2026 es jueves, el 01/01/2027 es viernes y feriado, y despues viene el
   fin de semana. Un vencimiento el 31 termina el LUNES 4 DE ENERO DE 2027: otro
   dia, otro mes y otro anio que la fecha teorica. */
$conAnioNuevo = calendarioTV('2026-01-01', '2027-12-31', ['2026-12-31', '2027-01-01']);

$r = TarjetasVencimiento::delMes(31, '2026-12', $conAnioNuevo);

chequear('la teorica es el 31/12/2026', '2026-12-31', $r['teorica']);
chequear('y con el 31 y el 1 feriados el debito cae el lunes 4 de enero de 2027',
    '2027-01-04', $r['fecha']);
chequear('se marca corrida', true, $r['corrida']);
chequear('y NO se marca ultimo dia: diciembre tiene 31', false, $r['ultimo_dia']);

/* Sin los feriados, el mismo dia NO se mueve: el 31/12/2026 es jueves. Esto es
   lo que distingue "se corre porque no es habil" de "se corre siempre". */
chequear('sin feriados, el 31/12/2026 es jueves y no se mueve',
    '2026-12-31', TarjetasVencimiento::delMes(31, '2026-12', $habiles)['fecha']);

/* El 28/02/2027 es DOMINGO y febrero 2027 tiene 28 dias: el dia existe, asi que
   NO es un ultimo dia forzado, pero igual cruza a marzo por el corrimiento. Los
   dos efectos son independientes y esta es la prueba de que no se confunden. */
$r = TarjetasVencimiento::delMes(28, '2027-02', $habiles);

chequear('el 28/02/2027 existe: no se acota', false, $r['ultimo_dia']);
chequear('pero es domingo, asi que cruza al lunes 1 de marzo', '2027-03-01', $r['fecha']);

/* ================================================================
   EL FALLBACK DE CALENDARIO

   RO_T_CALENDARIO llega hasta el 31/12/2027. Una fecha que no este se asume
   habil de lunes a viernes y se AVISA, deduplicado por mes: lo que no puede
   pasar es que los feriados dejen de correr fechas sin que nadie se entere.
   ================================================================ */
seccion('sin calendario, lunes a viernes y aviso');

$r = TarjetasVencimiento::delMes(15, '2028-07', []);

chequear('el 15/07/2028 es sabado: con el mapa vacio se corre al lunes 17',
    '2028-07-17', $r['fecha']);
chequear('y avisa por el mes que falta', ['2028-07'], $r['faltan']);

chequear('el aviso tiene la misma redaccion que el de Ventas y el del cronograma',
    CronogramaPagos::avisosCalendario(['2028-07']),
    TarjetasVencimiento::avisosCalendario(['2028-07']));

/* El corrimiento que cruza de mes con el mapa vacio avisa por LOS DOS meses que
   tocó, no solo por el primero: son dos meses que hay que extender. */
$r = TarjetasVencimiento::delMes(31, '2028-03', []);

chequear('el 31/03/2028 es viernes: no se mueve y avisa por marzo',
    '2028-03-31', $r['fecha']);
chequear('un solo mes en faltan', ['2028-03'], $r['faltan']);

/* Treinta dias no habiles seguidos no existen. Si el mapa los declara, el mapa
   esta mal y devolver la fecha del tope escondería el problema. */
$todoFeriado = [];
$cursor = new DateTime('2026-06-01');

while ($cursor <= new DateTime('2026-08-31')) {
    $todoFeriado[$cursor->format('Y-m-d')] = false;
    $cursor->modify('+1 day');
}

chequearLanza('un calendario sin ningun dia habil lanza en vez de inventar una fecha',
    function () use ($todoFeriado) {
        TarjetasVencimiento::delMes(10, '2026-06', $todoFeriado);
    });

/* ================================================================
   VARIOS MESES DE UNA, Y EL PROXIMO VENCIMIENTO

   proximoDesde() es lo que ubica una factura de tarjeta corporativa YA VENCIDA:
   una tarjeta se paga una vez por mes, asi que esa factura sale en el proximo
   vencimiento de SU tarjeta y no apilada en el primer dia del eje.
   ================================================================ */
seccion('paraMeses y el proximo vencimiento');

$meses = ['2026-09', '2026-10', '2026-11', '2026-12'];
$p = TarjetasVencimiento::paraMeses(10, $meses, $habiles);

chequear('devuelve una fecha por mes', 4, count($p['fechas']));
chequear('indexada por mes', '2026-10-12', $p['fechas']['2026-10']['fecha']);
chequear('y sin meses faltantes con el calendario completo', [], $p['faltan']);

/* Parado el 26/09/2026, el vencimiento del 10 de septiembre YA PASO: el proximo
   es el de octubre. Es exactamente el caso de una factura vencida. */
$r = TarjetasVencimiento::proximoDesde(10, '2026-09-26', $meses, $habiles);

chequear('el proximo vencimiento posterior al 26/09 es el de octubre',
    '2026-10-12', $r['fecha']);
chequear('y dice de que mes es', '2026-10', $r['mes']);

/* ESTRICTAMENTE POSTERIOR. Un vencimiento que cae hoy ya se debito, igual que el
   pago de hoy de un fletero no se proyecta. */
chequear('un vencimiento que cae HOY no cuenta como proximo',
    '2026-11-10', TarjetasVencimiento::proximoDesde(10, '2026-10-12', $meses, $habiles)['fecha']);

chequear('el dia anterior si lo toma',
    '2026-10-12', TarjetasVencimiento::proximoDesde(10, '2026-10-11', $meses, $habiles)['fecha']);

/* SIN NINGUNO POSTERIOR DEVUELVE null. No se estira el calendario para
   encontrar uno: si el eje se termina antes, ese importe cae fuera del horizonte
   y eso es lo que hay que informar, no inventarle una fecha. */
chequear('si ningun mes del eje da una fecha posterior, es null',
    null, TarjetasVencimiento::proximoDesde(10, '2027-01-01', $meses, $habiles));

/* ================================================================
   VALIDACION DEL DIA
   ================================================================ */
seccion('el dia del mes se valida en el backend');

chequear('el 1 vale', 1, TarjetasVencimiento::validarDia(1));
chequear('el 31 tambien', 31, TarjetasVencimiento::validarDia('31'));

foreach ([0, 32, -5, 1000] as $malo) {
    chequearLanza("el dia $malo se rechaza", function () use ($malo) {
        TarjetasVencimiento::validarDia($malo);
    });
}

chequearLanza('un dia vacio se rechaza', function () {
    TarjetasVencimiento::validarDia('');
});

chequearLanza('un dia que no es numero se rechaza', function () {
    TarjetasVencimiento::validarDia('quince');
});

chequearLanza('un dia con decimales se rechaza: no existe el 15,5 de octubre',
    function () { TarjetasVencimiento::validarDia(15.5); });

/* ================================================================
   EL RANGO DE CALENDARIO VA HACIA ADELANTE

   Al contrario del cronograma de pagos, que pide un mes ANTES del eje porque se
   corre hacia atras. Si el rango terminara en fin(), un dia 31 del ultimo mes
   corrido por un feriado caeria fuera del mapa y dispararia el fallback con un
   aviso que no describe ningun problema real.
   ================================================================ */
seccion('el rango de calendario que hace falta');

$h = new Horizonte(28, 12, [], new DateTime('2026-09-26'));
$rango = TarjetasVencimiento::rangoCalendario($h);

chequear('arranca el primer dia del primer mes del eje', '2026-09-01', $rango['desde']);
chequear('el eje termina el 31/08/2027', '2027-08-31', $h->fin());
chequear('y el rango sigue 30 dias mas, para el corrimiento',
    '2027-09-30', $rango['hasta']);
chequear('o sea que termina DESPUES del fin del eje', true, $rango['hasta'] > $h->fin());

/* El cronograma de pagos pide el rango al reves, y las dos cosas son correctas.
   Esto lo deja escrito en una prueba para que nadie los unifique. */
$rangoCrono = CronogramaPagos::rangoCalendario($h);

chequear('el cronograma arranca ANTES del eje', true, $rangoCrono['desde'] < $rango['desde']);
chequear('y termina en fin(), sin cola', $h->fin(), $rangoCrono['hasta']);

/* ================================================================
   EL TEXTO QUE EXPLICA LA FECHA

   Lo arma el backend, porque es la explicacion de una cuenta que hace el
   backend. Con el texto en el front, cambiar la regla obligaria a cambiarla en
   dos lados y el segundo se olvida.
   ================================================================ */
seccion('el tooltip explica de donde sale la fecha');

$texto = TarjetasVencimiento::explicar(
    TarjetasVencimiento::delMes(31, '2026-02', $habiles));

chequear('dice que se uso el ultimo dia', true, strpos($texto, 'último') !== false);
chequear('y que se corrio por no ser habil', true, strpos($texto, 'hábil siguiente') !== false);

$texto = TarjetasVencimiento::explicar(
    TarjetasVencimiento::delMes(10, '2026-09', $habiles));

chequear('una fecha que no se movio lo dice', true, strpos($texto, 'es hábil') !== false);
chequear('y no habla de corrimiento', false, strpos($texto, 'hábil siguiente') !== false);

/* ================================================================
   LA INFLACION COMPUESTA

   Es la otra funcion pura que comparten las tres sub-pestanas: llevar un
   promedio historico a moneda del mes en que se va a pagar.
   ================================================================ */
seccion('inflacion compuesta: el factor');

// 2 % constante en todos los meses, que es lo que hay cargado hoy en la base.
$dosPorCiento = [];

foreach (['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
          '2027-01', '2027-02', '2027-03'] as $m) {
    $dosPorCiento[$m] = 2;
}

/* EL MES BASE NO SE AJUSTA. El promedio ya esta en moneda del mes base, asi que
   el producto arranca en el mes siguiente y para el mes base vale 1. Ajustarlo
   inflaria el mes que sirvio de referencia. */
chequear('el mes base vale factor 1', 1.0,
    Inflacion::compuesta($dosPorCiento, '2026-08', '2026-08')['factor']);

chequear('el mes siguiente es 1,02', 1.02,
    Inflacion::compuesta($dosPorCiento, '2026-08', '2026-09')['factor']);

// Tres meses al 2 % compuesto: 1,02^3 = 1,061208.
chequear('tres meses al 2 % dan 1,061208', 1.061208,
    Inflacion::compuesta($dosPorCiento, '2026-08', '2026-11')['factor']);

/* LA DIFERENCIA CON acumulada() ESCRITA EN UNA PRUEBA. Las dos miran el mismo
   trimestre al mismo 2 % y dan distinto, y las dos estan bien: acumulada()
   reproduce un ajuste PACTADO -"la suma de la inflacion del trimestre", 6 %- y
   compuesta() calcula una PROYECCION -6,1208 %-. Si alguien las unifica, esta
   prueba lo dice. */
chequear('acumulada() del mismo trimestre da 6 %, sin componer', 6.0,
    Inflacion::acumulada($dosPorCiento, '2026-11')['pct']);
chequear('y compuesta() da 6,1208 %: son dos cuentas distintas y las dos valen',
    6.1208, (Inflacion::compuesta($dosPorCiento, '2026-08', '2026-11')['factor'] - 1) * 100);

seccion('inflacion variable, y el cruce de anio');

$variable = ['2026-09' => 2, '2026-10' => 3, '2026-11' => 4,
             '2026-12' => 1.5, '2027-01' => 2.5, '2027-02' => 3];

// 1,02 * 1,03 * 1,04 = 1,092624
chequear('con 2, 3 y 4 % el factor es 1,092624', 1.092624,
    Inflacion::compuesta($variable, '2026-08', '2026-11')['factor']);

// Cruce de anio: 1,015 * 1,025 * 1,03
chequear('cruzando el anio, dic + ene + feb', 1.015 * 1.025 * 1.03,
    Inflacion::compuesta($variable, '2026-11', '2027-02')['factor']);

chequear('el detalle trae los tres meses del camino',
    ['2026-12' => 1.5, '2027-01' => 2.5, '2027-02' => 3.0],
    Inflacion::compuesta($variable, '2026-11', '2027-02')['meses']);

seccion('un mes anterior al base no se deflaciona');

/* Devuelve 1 y no un factor menor. Deflacionar seria afirmar cuanto valia ese
   gasto ANTES de la ventana que se midio, que es algo que nadie midio. */
$r = Inflacion::compuesta($dosPorCiento, '2026-08', '2026-06');

chequear('un mes anterior al base da factor 1', 1.0, $r['factor']);
chequear('y no recorre ningun mes', [], $r['meses']);

seccion('un mes de inflacion que falta da null, nunca cero');

/* SIN 2026-10 CARGADO. El factor de noviembre no se puede calcular, y lo que NO
   puede pasar es que octubre cuente como 0 %: eso daria 1,0404 en vez de
   1,061208, o sea un egreso proyectado DE MENOS, y nadie tendria donde
   enterarse. */
$conHueco = ['2026-09' => 2, '2026-11' => 2, '2026-12' => 2];

$r = Inflacion::compuesta($conHueco, '2026-08', '2026-11');

chequear('el factor es null', null, $r['factor']);
chequear('y dice QUE mes falta', ['2026-10'], $r['faltan']);
chequear('el detalle marca el mes faltante en null', null, $r['meses']['2026-10']);
chequear('y trae los que si estan', 2.0, $r['meses']['2026-09']);

/* El mes ANTERIOR al hueco se sigue resolviendo: lo que se cae es el mes que
   necesita el dato que falta y los posteriores, no toda la serie. */
chequear('septiembre, que no necesita octubre, se resuelve igual', 1.02,
    Inflacion::compuesta($conHueco, '2026-08', '2026-09')['factor']);

/* Un mes cargado en NULL cuenta como faltante, no como cero: es el estado de una
   fila que existe y todavia no se tipeo. */
chequear('un mes con valor null tambien falta', ['2026-10'],
    Inflacion::compuesta(['2026-09' => 2, '2026-10' => null], '2026-08', '2026-10')['faltan']);

seccion('varios meses de una');

$p = Inflacion::compuestaParaMeses($dosPorCiento, '2026-08',
    ['2026-09', '2026-10', '2026-11']);

chequear('un factor por mes', 3, count($p['factores']));
chequear('indexado por mes', 1.0404, $p['factores']['2026-10']['factor']);
chequear('sin faltantes', [], $p['faltan']);

/* LOS FALTANTES SE JUNTAN Y NO SE REPITEN. Doce meses que dependen todos del
   mismo mes ausente tienen que producir UN aviso, no doce: doce avisos que dicen
   lo mismo esconden el resto. */
$p = Inflacion::compuestaParaMeses($conHueco, '2026-08',
    ['2026-09', '2026-10', '2026-11', '2026-12']);

chequear('el mes que falta se informa una sola vez', ['2026-10'], $p['faltan']);
chequear('septiembre se resuelve', 1.02, $p['factores']['2026-09']['factor']);
chequear('y los tres que dependen de octubre quedan en null',
    [null, null, null],
    [$p['factores']['2026-10']['factor'], $p['factores']['2026-11']['factor'],
     $p['factores']['2026-12']['factor']]);

seccion('el aviso dice si el mes se puede tipear o no');

/* LA VENTANA EDITABLE ARRANCA DOS MESES ANTES DEL MES EN CURSO. Un mes mas viejo
   que eso no tiene donde cargarse, y el aviso tiene que decirlo: mandar a
   alguien a cargar un mes que la pantalla no muestra es mandarlo a buscar un
   campo que no existe. */
$aviso = Inflacion::avisoFaltan(['2026-10'], '2026-09-26');

chequear('un mes de la ventana manda a Parametros', true,
    strpos($aviso, 'Parámetros › Generales') !== false);
chequear('y no dice que este fuera de la ventana', false,
    strpos($aviso, 'FUERA') !== false);

$aviso = Inflacion::avisoFaltan(['2026-04'], '2026-09-26');

chequear('un mes viejo avisa que esta FUERA de la ventana editable', true,
    strpos($aviso, 'FUERA') !== false);
chequear('y nombra el mes', true, strpos($aviso, '2026-04') !== false);

chequear('sin faltantes no hay aviso', '', Inflacion::avisoFaltan([], '2026-09-26'));

seccion('los meses se validan');

foreach (['2026-13', '2026-00', '26-01', '2026', 'octubre', ''] as $malo) {
    chequearLanza("el mes '$malo' se rechaza en compuesta()", function () use ($malo) {
        Inflacion::compuesta(['2026-09' => 2], $malo, '2026-09');
    });
}

foreach (['2026-13', '26-01', ''] as $malo) {
    chequearLanza("el mes '$malo' se rechaza en delMes()", function () use ($malo) {
        TarjetasVencimiento::delMes(10, $malo, []);
    });
}
