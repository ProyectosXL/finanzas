<?php
/**
 * Cobertura automatica: el motor calcula cuanto rescatar de cada fondo para
 * que el saldo proyectado no quede abajo de cero.
 *
 * La primera mitad prueba CoberturaAutomatica::calcular() sola, con numeros:
 * es una funcion pura y ahi viven todas las reglas (que dispara un rescate,
 * cuanto se saca, de donde, como se redondea, cuando se devuelve). La segunda
 * mitad la enchufa al motor con una estructura controlada, para fijar como
 * llega el resultado a las filas del cuadro, al arrastre y a los avisos.
 *
 * Nada de esto toca la base.
 */

require_once __DIR__ . '/../cashflow/Class/CoberturaAutomatica.php';
require_once __DIR__ . '/../cashflow/Class/Cashflow.php';
require_once __DIR__ . '/../cashflow/Class/Cobertura.php';
require_once __DIR__ . '/../cashflow/Class/Providers/FondosProvider.php';
require_once __DIR__ . '/../cashflow/Class/Providers/CoberturaProvider.php';

/* ================================================================
   ARMADO
   Cuatro columnas, dos fondos: Inversiones en pesos con 1000, y la
   comitente con USD 10 a 1500. Los topes son constantes salvo que la
   prueba diga otra cosa.
   ================================================================ */
$cols = ['c1', 'c2', 'c3', 'c4'];

$fondo = function ($clave, $moneda, $tope, $tc = null, $manual = [], $clase = null, $orden = 0)
        use ($cols) {
    $f = ['clave' => $clave, 'moneda' => $moneda, 'tope' => [], 'tc' => [], 'manual' => $manual,
          'clase' => ($clase === null ? ($moneda === 'USD' ? 'COMITENTE' : 'INVERSION') : $clase),
          'orden' => $orden];

    foreach ($cols as $c) {
        $f['tope'][$c] = is_array($tope) ? (isset($tope[$c]) ? $tope[$c] : 0) : $tope;
        $f['tc'][$c] = is_array($tc) ? (isset($tc[$c]) ? $tc[$c] : null) : $tc;
    }

    return $f;
};

$inv = $fondo('CTA_1', 'ARS', 1000);
$usd = $fondo('CTA_2', 'USD', 10, 1500);

$auto = function ($r, $col, $clave, $que = 'ars') {
    return isset($r['automatico'][$col][$clave]) ? $r['automatico'][$col][$clave][$que] : 0.0;
};

/* ================================================================
   LO QUE DISPARA EL RESCATE ES EL SALDO ACUMULADO, NO EL FLUJO
   ================================================================ */
seccion('un dia con flujo negativo pero caja de sobra no rescata');

// Entra 500 el primer dia, sale 300 el segundo: el flujo del dia 2 es
// negativo, pero el saldo acumulado sigue en 200. Rescatar aca sacaria plata
// de una inversion que rinde, sin necesitarla.
$r = CoberturaAutomatica::calcular($cols, ['c1' => 500, 'c2' => -300], [$inv, $usd], []);

chequear('el saldo del dia 1 es el flujo', 500.0, $r['saldo']['c1']);
chequear('el saldo del dia 2 baja pero sigue positivo', 200.0, $r['saldo']['c2']);
chequear('no se rescato nada', [], $r['automatico']);
chequear('no falta nada', [], $r['faltante']);
chequear('los fondos quedan enteros', ['CTA_1' => 0.0, 'CTA_2' => 0.0], $r['consumido']);

seccion('rescate parcial: exactamente lo que falta para llegar a cero');

// Entra 500, sale 800: el acumulado queda en -300. Se rescatan 300, ni un
// peso mas, y el saldo cierra en cero.
$r = CoberturaAutomatica::calcular($cols, ['c1' => 500, 'c2' => -800], [$inv, $usd], []);

chequear('se rescatan 300 de Inversiones', 300.0, $auto($r, 'c2', 'CTA_1'));
chequear('y el saldo cierra en cero', 0.0, $r['saldo']['c2']);
chequear('nada de la comitente: Inversiones alcanzo', 0.0, $auto($r, 'c2', 'CTA_2'));
chequear('Inversiones queda con 300 consumidos', 300.0, $r['consumido']['CTA_1']);
chequear('sin faltante', [], $r['faltante']);

// El dia siguiente no se mueve nada: no hay excedente, no se devuelve nada.
chequear('un dia en cero no devuelve', 0.0, $auto($r, 'c3', 'CTA_1'));
chequear('y el saldo sigue en cero', 0.0, $r['saldo']['c3']);

seccion('agotamiento de Inversiones y paso a la comitente');

// Faltan 4000: Inversiones da sus 1000 enteros y el resto lo pone la
// comitente. 3000 / 1500 = 2 dolares justos.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -4000], [$inv, $usd], []);

chequear('Inversiones se agota', 1000.0, $auto($r, 'c1', 'CTA_1'));
chequear('la comitente pone los 3000 que faltan', 3000.0, $auto($r, 'c1', 'CTA_2'));
chequear('que son 2 dolares enteros', 2, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('el saldo cierra en cero', 0.0, $r['saldo']['c1']);
chequear('consumido por fondo, cada uno en su moneda', ['CTA_1' => 1000.0, 'CTA_2' => 2.0],
    $r['consumido']);

seccion('los dolares se venden enteros, redondeando hacia arriba');

// Faltan 3100 despues de Inversiones: 3100 / 1500 = 2,07 dolares. Se venden
// 3, no 2,07: con 2 la columna seguiria en rojo por 100 pesos. El saldo
// queda con el vuelto del redondeo: 4500 - 3100 = 1400.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -4100], [$inv, $usd], []);

chequear('se venden 3 dolares, no 2,07', 3, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('valuados a la cotizacion del dia', 4500.0, $auto($r, 'c1', 'CTA_2'));
chequear('el saldo queda con el vuelto del redondeo: -4100 + 1000 + 4500', 1400.0, $r['saldo']['c1']);
chequear('y no hay faltante', [], $r['faltante']);

// Con 1400 sobrantes al dia siguiente no se recompra nada: no alcanza para un
// dolar entero. Si se redondeara hacia arriba, el saldo quedaria en -100.
chequear('el vuelto no alcanza para recomprar un dolar', 0.0, $auto($r, 'c2', 'CTA_2'));
chequear('y el saldo sigue en 1400', 1400.0, $r['saldo']['c2']);

// Un cociente EXACTO no sube un dolar por error de punto flotante.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -4000], [$inv, $usd], []);
chequear('3000 / 1500 son 2 dolares, no 3', 2, $auto($r, 'c1', 'CTA_2', 'importe'));

// Con 66.000,50 dolares se pueden vender 66.000: los centavos quedan.
$fraccion = $fondo('CTA_2', 'USD', 3.5, 1000);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -9000], [$fraccion], []);
chequear('de USD 3,50 se venden 3 enteros', 3, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('y faltan los 6000 que no alcanzo', 6000.0, $r['faltante']['c1']);

seccion('sin cotizacion no se vende ni se recompra');

$sinTc = $fondo('CTA_2', 'USD', 10, ['c1' => null, 'c2' => 1500, 'c3' => 1500, 'c4' => 1500]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -2000, 'c2' => 5000], [$inv, $sinTc], []);

chequear('el dia sin cotizacion Inversiones pone lo suyo', 1000.0, $auto($r, 'c1', 'CTA_1'));
chequear('pero la comitente no: no hay con que valuar', 0.0, $auto($r, 'c1', 'CTA_2'));
chequear('y el faltante lo dice', 1000.0, $r['faltante']['c1']);

seccion('los dos fondos agotados: se aplica todo y queda el faltante');

$r = CoberturaAutomatica::calcular($cols, ['c1' => -20000, 'c2' => -500], [$inv, $usd], []);

chequear('Inversiones entera', 1000.0, $auto($r, 'c1', 'CTA_1'));
chequear('los 10 dolares enteros', 10, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('el saldo queda en rojo: -20000 + 1000 + 15000', -4000.0, $r['saldo']['c1']);
chequear('con el faltante informado', 4000.0, $r['faltante']['c1']);

// Al dia siguiente sigue faltando, y no hay de donde: el faltante crece y no
// se inventa nada.
chequear('el dia 2 no rescata nada', [], isset($r['automatico']['c2']) ? $r['automatico']['c2'] : []);
chequear('y el faltante acumula', 4500.0, $r['faltante']['c2']);
chequear('un fondo nunca queda en negativo', true,
    $r['consumido']['CTA_1'] <= 1000 && $r['consumido']['CTA_2'] <= 10);

/* ================================================================
   DEVOLUCION: LIFO, ACOTADA A LO RESCATADO
   ================================================================ */
seccion('la devolucion es LIFO y no devuelve mas de lo que saco');

// Dia 1: faltan 2500 -> 1000 de Inversiones + 1 dolar (1500). Sobran 0.
// Dia 2: entran 10000. Se devuelve primero a la comitente (fue la ultima), el
// dolar entero, y despues a Inversiones sus 1000. Los 7500 restantes se
// quedan en caja: no hay mas rescates que devolver.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -2500, 'c2' => 10000], [$inv, $usd], []);

chequear('dia 1: Inversiones pone 1000', 1000.0, $auto($r, 'c1', 'CTA_1'));
chequear('dia 1: la comitente pone 1 dolar', 1, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('dia 2: la comitente recupera su dolar primero', -1, $auto($r, 'c2', 'CTA_2', 'importe'));
chequear('en pesos', -1500.0, $auto($r, 'c2', 'CTA_2'));
chequear('dia 2: Inversiones recupera sus 1000', -1000.0, $auto($r, 'c2', 'CTA_1'));
chequear('y ni un peso mas: el saldo se queda con el resto', 7500.0, $r['saldo']['c2']);
chequear('los dos fondos quedan enteros', ['CTA_1' => 0.0, 'CTA_2' => 0.0], $r['consumido']);

// Dia 3 sigue con excedente y ya no hay nada que devolver: no se inventa una
// suscripcion.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -2500, 'c2' => 10000, 'c3' => 5000],
    [$inv, $usd], []);
chequear('con todo devuelto, un excedente nuevo no devuelve nada', [],
    isset($r['automatico']['c3']) ? $r['automatico']['c3'] : []);

seccion('la devolucion se acota al sobrante del dia');

// Dia 1: faltan 800 -> 800 de Inversiones. Dia 2: sobran 300 -> se devuelven
// 300, no 800. Dia 3: sobran 200 mas -> otros 200. Quedan 300 sin devolver.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -800, 'c2' => 300, 'c3' => 200], [$inv, $usd], []);

chequear('dia 2 devuelve solo el sobrante', -300.0, $auto($r, 'c2', 'CTA_1'));
chequear('y el saldo queda en cero', 0.0, $r['saldo']['c2']);
chequear('dia 3 devuelve el sobrante siguiente', -200.0, $auto($r, 'c3', 'CTA_1'));
chequear('Inversiones sigue con 300 consumidos', 300.0, $r['consumido']['CTA_1']);

// Con dolares, hacia abajo: 1600 sobrantes son 1 dolar, no 1,07.
$soloUsd = [$usd];
$r = CoberturaAutomatica::calcular($cols, ['c1' => -3000, 'c2' => 1600], $soloUsd, []);
chequear('dia 1: 2 dolares', 2, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('dia 2: con 1600 sobrantes se recompra 1 dolar', -1, $auto($r, 'c2', 'CTA_2', 'importe'));
chequear('y quedan 100 en caja', 100.0, $r['saldo']['c2']);

seccion('una pila con varios rescates se devuelve en orden inverso');

// Dia 1: 600 de Inversiones. Dia 2: faltan 4000 mas -> los 400 que quedaban
// de Inversiones y 3 dolares (la pila queda: Inversiones 1000, comitente 3).
// Dia 3 y 4: excedentes. La comitente esta arriba en la pila, asi que
// recupera sus dolares ANTES de que Inversiones vea un peso, aunque la
// devolucion a Inversiones no tenga redondeo y "entre" en el sobrante.
$r = CoberturaAutomatica::calcular($cols,
    ['c1' => -600, 'c2' => -4000, 'c3' => 3300, 'c4' => 3000], [$inv, $usd], []);

chequear('dia 1: 600 de Inversiones', 600.0, $auto($r, 'c1', 'CTA_1'));
chequear('dia 2: los 400 que quedaban de Inversiones', 400.0, $auto($r, 'c2', 'CTA_1'));
chequear('dia 2: 3 dolares (3600 / 1500 = 2,4 -> 3)', 3, $auto($r, 'c2', 'CTA_2', 'importe'));
chequear('dia 2: el saldo queda con el vuelto', 900.0, $r['saldo']['c2']);
// Dia 3: 900 + 3300 = 4200 sobrantes -> 2 dolares (3000); quedan 1200, que
// no alcanzan para el tercero y NO pasan a Inversiones: la pila manda.
chequear('dia 3: recompra 2 dolares', -2, $auto($r, 'c3', 'CTA_2', 'importe'));
chequear('dia 3: Inversiones no recibe nada, la comitente esta arriba en la pila',
    0.0, $auto($r, 'c3', 'CTA_1'));
chequear('dia 3: quedan 1200 en caja', 1200.0, $r['saldo']['c3']);
// Dia 4: 1200 + 3000 = 4200 -> el tercer dolar (1500) y despues 1000 a
// Inversiones (todo lo que se le saco). Quedan 1700.
chequear('dia 4: el ultimo dolar', -1, $auto($r, 'c4', 'CTA_2', 'importe'));
chequear('dia 4: y recien ahi Inversiones recupera sus 1000', -1000.0, $auto($r, 'c4', 'CTA_1'));
chequear('dia 4: el resto se queda en caja', 1700.0, $r['saldo']['c4']);
chequear('todo devuelto', ['CTA_1' => 0.0, 'CTA_2' => 0.0], $r['consumido']);

/* ================================================================
   LO MANUAL TIENE PRECEDENCIA
   ================================================================ */
seccion('una carga manual va primero y el motor cubre el remanente');

// Faltan 900. Alguien cargo 400 a mano de Inversiones ese dia: el motor solo
// pone los 500 que siguen faltando.
$invManual = $fondo('CTA_1', 'ARS', 1000, null, ['c1' => 400]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -900], [$invManual, $usd], ['c1' => 400]);

chequear('el motor rescata solo el remanente', 500.0, $auto($r, 'c1', 'CTA_1'));
chequear('el saldo cierra en cero', 0.0, $r['saldo']['c1']);
chequear('el fondo tiene consumidos los dos: 400 + 500', 900.0, $r['consumido']['CTA_1']);

// Lo manual descuenta del tope: con 400 a mano, al motor le quedan 600.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -2000], [$invManual, $usd], ['c1' => 400]);
chequear('el motor no puede pasar de lo que queda despues de lo manual', 600.0, $auto($r, 'c1', 'CTA_1'));
chequear('y el resto sale de la comitente: 1000 / 1500 -> 1 dolar', 1, $auto($r, 'c1', 'CTA_2', 'importe'));

seccion('una carga manual que cubre de sobra deja al motor sin nada que hacer');

$r = CoberturaAutomatica::calcular($cols, ['c1' => -900], [$fondo('CTA_1', 'ARS', 1000, null, ['c1' => 1000]), $usd],
    ['c1' => 1000]);

chequear('no hay rescate automatico', [], $r['automatico']);
chequear('el saldo queda con lo que sobro de lo manual', 100.0, $r['saldo']['c1']);

seccion('una carga manual negativa devuelve plata, y el motor cubre si eso deja rojo');

// Sin manual el saldo seria 200. Con -500 a mano (alguien decidio volver a
// invertir), queda en -300 y el motor rescata 300... del mismo fondo. Es lo
// que pidieron: lo manual manda y el motor tapa lo que siga faltando.
$invNeg = $fondo('CTA_1', 'ARS', 1000, null, ['c1' => -500]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => 200], [$invNeg, $usd], ['c1' => -500]);

chequear('el motor cubre los 300 que dejo la devolucion manual', 300.0, $auto($r, 'c1', 'CTA_1'));
chequear('el consumido neto del fondo es -200', -200.0, $r['consumido']['CTA_1']);

seccion('lo manual NO se devuelve solo: es una decision de alguien');

// 400 a mano el dia 1, y el dia 2 sobran 10000. El motor no rescato nada,
// asi que no devuelve nada: deshacer una carga manual en silencio seria peor
// que dejarla. Para eso esta el importe manual negativo.
$r = CoberturaAutomatica::calcular($cols, ['c1' => 100, 'c2' => 10000], [$invManual, $usd], ['c1' => 400]);

chequear('sin rescates automaticos no hay devoluciones', [], $r['automatico']);
chequear('el fondo sigue con los 400 manuales consumidos', 400.0, $r['consumido']['CTA_1']);

// Y si el motor rescato Y ademas hay una devolucion manual que ya repuso el
// fondo, el motor no devuelve de nuevo: no se le devuelve a un fondo entero.
$invRepuesto = $fondo('CTA_1', 'ARS', 1000, null, ['c2' => -300]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -300, 'c2' => 300, 'c3' => 5000],
    [$invRepuesto, $usd], ['c2' => -300]);

chequear('dia 1: el motor rescata 300', 300.0, $auto($r, 'c1', 'CTA_1'));
chequear('dia 2: el motor no devuelve, la devolucion manual ya lo hizo', 0.0, $auto($r, 'c2', 'CTA_1'));
chequear('dia 3: con excedente, el motor NO devuelve lo que ya esta devuelto', 0.0, $auto($r, 'c3', 'CTA_1'));
chequear('el fondo termina entero, no con 300 de mas', 0.0, $r['consumido']['CTA_1']);

/* ================================================================
   EL TOPE ES EL SALDO A ESA FECHA
   ================================================================ */
seccion('el tope de cada columna es el saldo del fondo a esa fecha');

// Una suscripcion prevista el dia 3 sube el tope de 1000 a 1800.
$crece = $fondo('CTA_1', 'ARS', ['c1' => 1000, 'c2' => 1000, 'c3' => 1800, 'c4' => 1800]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -1500, 'c3' => -600], [$crece], []);

chequear('dia 1: solo los 1000 que hay', 1000.0, $auto($r, 'c1', 'CTA_1'));
chequear('dia 1: faltan 500', 500.0, $r['faltante']['c1']);
chequear('dia 3: la suscripcion prevista habilita 800 mas, y se usan 800 (500 + 300 restantes)',
    800.0, $auto($r, 'c3', 'CTA_1'));
chequear('dia 3: faltan 300', 300.0, $r['faltante']['c3']);
chequear('el fondo nunca pasa su tope', 1800.0, $r['consumido']['CTA_1']);

seccion('un rescate previsto que se come lo ya usado deja el fondo sobregirado, y se informa');

// El dia 1 el motor usa 800 de 1000. El dia 2 hay un rescate previsto de 500
// (tope 500): lo consumido supera el tope en 300. El motor no puede deshacer
// lo del dia 1, asi que lo clava en cero y lo dice.
$achica = $fondo('CTA_1', 'ARS', ['c1' => 1000, 'c2' => 500, 'c3' => 500, 'c4' => 500]);
$r = CoberturaAutomatica::calcular($cols, ['c1' => -800, 'c3' => -100], [$achica], []);

chequear('dia 1 rescata 800', 800.0, $auto($r, 'c1', 'CTA_1'));
chequear('dia 2 queda sobregirado en 300', 300.0, $r['sobregirado']['CTA_1']['c2']);
chequear('dia 3 no puede rescatar nada mas', 0.0, $auto($r, 'c3', 'CTA_1'));
chequear('y falta', 100.0, $r['faltante']['c3']);

// Una carga manual mayor al tope tambien se informa (guardar() la rechaza,
// pero una vieja puede estar).
$r = CoberturaAutomatica::calcular($cols, ['c1' => -100], [$fondo('CTA_1', 'ARS', 1000, null, ['c1' => 1200])],
    ['c1' => 1200]);
chequear('lo manual que supera el tope figura como sobregiro', 200.0, $r['sobregirado']['CTA_1']['c1']);
chequear('y el motor no suma encima', [], $r['automatico']);

seccion('lo cargado a mano desde un fondo que no esta en la lista entra al saldo igual');

// Una aplicacion con una clave vieja: suma al saldo, no descuenta de nadie.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -900], [$inv], ['c1' => 400]);

chequear('el saldo la recibe', 500.0, $auto($r, 'c1', 'CTA_1'));
chequear('y el motor cubre el resto', 0.0, $r['saldo']['c1']);

seccion('sin fondos, el motor no inventa nada');

$r = CoberturaAutomatica::calcular($cols, ['c1' => -900], [], []);

chequear('no hay automatico', [], $r['automatico']);
chequear('el faltante es todo', 900.0, $r['faltante']['c1']);
chequear('el saldo queda en rojo', -900.0, $r['saldo']['c1']);

/* ================================================================
   EL ORDEN DE CONSUMO
   ================================================================ */
seccion('el orden de consumo: inversion antes que comitente, y el catalogo adentro');

$desordenados = [
    $fondo('CTA_9', 'USD', 1, 1500, [], 'COMITENTE', 10),
    $fondo('CTA_5', 'ARS', 1, null, [], 'INVERSION', 30),
    $fondo('CTA_7', 'ARS', 1, null, [], 'INVERSION', 20),
    $fondo('CTA_8', 'ARS', 1, null, [], 'INVERSION', 20),
    $fondo('CTA_1', 'ARS', 1, null, [], 'RARA', 0),
];

$orden = array_map(function ($f) { return $f['clave']; },
    CoberturaAutomatica::ordenDeConsumo($desordenados));

chequear('inversiones por orden de catalogo, despues la comitente, y una clase desconocida al final',
    ['CTA_7', 'CTA_8', 'CTA_5', 'CTA_9', 'CTA_1'], $orden);

// Y calcular() consume en el orden en que recibe la lista: es responsabilidad
// del llamador ordenarla. Con la comitente PRIMERA, se venderian dolares.
$r = CoberturaAutomatica::calcular($cols, ['c1' => -1500], [$usd, $inv], []);
chequear('calcular() respeta el orden recibido', 1, $auto($r, 'c1', 'CTA_2', 'importe'));
chequear('e Inversiones no se toca', 0.0, $auto($r, 'c1', 'CTA_1'));

/* ================================================================
   EN EL MOTOR

   Escenario: 3 dias + 3 meses, hoy = 2026-09-06. Estructura como la que
   deja sql/cashflow_cobertura_automatica.sql: dos filas de stock (una por
   clase) y dos filas de uso (una por clase), cada una apuntada a la serie
   de su clase.

       Disponible   saldo inicial 1000 el 06/09
       Cobros       100 el 06/09, 200 el 07/09, 300 en Oct
       Pagos        900 el 07/09, 2600 el 08/09
       Stock        Inversiones CTA_1 1000 (ARS) / Comitente CTA_2 USD 10 a 1500
   ================================================================ */
seccion('el motor: las filas de uso muestran manual mas automatico');

class EstructuraCobAuto {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosCobAuto extends Parametros {
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() {
        return ['horizonte_dias' => 3, 'horizonte_meses' => 3];
    }
    public function getFeriadosComercio($map = null) { return []; }
}

class CashflowCobAuto extends Cashflow {
    public $series = [];
    protected function pedirSeries($h, $filas) { return $this->series; }
}

$ha = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

$secA = function ($codigo, $rol, $orden, $padre = null) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $codigo, 'ROL' => $rol,
            'ID_PADRE' => $padre, 'ORDEN' => $orden, 'ACTIVO' => 1];
};

$filA = function ($id, $codigo, $seccion, $tipo, $orden, $prov = null, $serie = null, $computa = 1) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
            'TIPO' => $tipo, 'COMPUTA' => $computa, 'ORIGEN_PROVIDER' => $prov,
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1];
};

$serieA = function ($h, $dias = [], $meses = []) {
    $s = $h->serieVacia();
    foreach ($dias as $k => $v)  { $s['dias'][$k] = $v; }
    foreach ($meses as $k => $v) { $s['meses'][$k] = $v; }
    $s['moneda_origen'] = 'ARS';
    $s['tipo_cambio'] = null;
    $s['fuera_horizonte'] = 0;
    $s['sin_fecha'] = 0;
    $s['warnings'] = [];
    $s['detalle'] = [];
    $s['por_fondo'] = [];
    $s['fondos'] = [];
    $s['fondos_tope'] = [];
    $s['fondos_manual'] = [];
    return $s;
};

// Un tope constante en todas las columnas del eje, en la moneda del fondo.
$topeA = function ($h, $moneda, $clase, $saldo, $tc = null, $orden = 0) {
    $t = ['moneda' => $moneda, 'clase' => $clase, 'orden' => $orden, 'tope' => [], 'tc' => []];
    foreach ($h->dias() as $d)  { $t['tope']['DIA|' . $d['fecha']] = $saldo; $t['tc']['DIA|' . $d['fecha']] = $tc; }
    foreach ($h->meses() as $m) { $t['tope']['MES|' . $m['clave']] = $saldo; $t['tc']['MES|' . $m['clave']] = $tc; }
    return $t;
};

$ea = new EstructuraCobAuto();
$ea->secciones = [
    $secA('DISP', 'SALDO', 10),
    $secA('EGR', 'MOVIMIENTO', 30),
    $secA('RES', 'DERIVADO', 40),
    $secA('COB', 'DERIVADO', 50),
];
$ea->filas = [
    $filA(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    $filA(2, 'COBROS', 'DISP', 'INGRESO', 20, 'VENTAS', 'COBRANZA'),
    $filA(7, 'PAGOS', 'EGR', 'EGRESO', 10, 'COMEX_PROV_EXT', 'PAGOS'),
    $filA(10, 'FLUJO', 'RES', 'FLUJO_NETO', 10),
    $filA(11, 'STOCK_INV', 'COB', 'STOCK_COBERTURA', 10, 'FONDO_INVERSION', 'STOCK', 0),
    $filA(12, 'STOCK_COM', 'COB', 'STOCK_COBERTURA', 15, 'FONDO_COMITENTE', 'STOCK', 0),
    $filA(13, 'USO_INV', 'COB', 'USO_COBERTURA', 20, 'COBERTURA', 'USO_INVERSION'),
    $filA(14, 'USO_COM', 'COB', 'USO_COBERTURA', 25, 'COBERTURA', 'USO_COMITENTE'),
    $filA(15, 'FLUJO_COB', 'COB', 'FLUJO_NETO', 30),
    $filA(16, 'SALDO_FIN', 'COB', 'SALDO_FINAL', 40),
];

$stockInv = $serieA($ha, ['2026-09-06' => 1000]);
$stockInv['por_fondo'] = ['CTA_1' => 1000];
$stockInv['fondos'] = ['CTA_1' => 'Inversiones'];
$stockInv['fondos_tope'] = ['CTA_1' => $topeA($ha, 'ARS', 'INVERSION', 1000)];

$stockCom = $serieA($ha, ['2026-09-06' => 15000]);
$stockCom['por_fondo'] = ['CTA_2' => 15000];
$stockCom['fondos'] = ['CTA_2' => 'Cuenta comitente'];
$stockCom['fondos_tope'] = ['CTA_2' => $topeA($ha, 'USD', 'COMITENTE', 10, 1500)];

$usoInv = $serieA($ha);
$usoInv['fondos'] = ['CTA_1' => 'Inversiones'];

$usoCom = $serieA($ha);
$usoCom['fondos'] = ['CTA_2' => 'Cuenta comitente'];

$seriesBase = [
    'SALDOS' => ['DISPONIBLE' => $serieA($ha, ['2026-09-06' => 1000])],
    'VENTAS' => ['COBRANZA' => $serieA($ha, ['2026-09-06' => 100, '2026-09-07' => 200], ['2026-10' => 300])],
    'COMEX_PROV_EXT' => ['PAGOS' => $serieA($ha, ['2026-09-07' => 900, '2026-09-08' => 2600])],
    'FONDO_INVERSION' => ['STOCK' => $stockInv],
    'FONDO_COMITENTE' => ['STOCK' => $stockCom],
    'COBERTURA' => ['USO_INVERSION' => $usoInv, 'USO_COMITENTE' => $usoCom],
];

$correr = function ($series) use ($ea, $ha) {
    $m = new CashflowCobAuto($ea, new ParametrosCobAuto(), $ha);
    $m->series = $series;
    $t = $m->proyectar();
    $p = [];
    foreach ($t['filas'] as $f) { $p[$f['codigo']] = $f; }
    return [$t, $p];
};

list($ta, $pa) = $correr($seriesBase);

// 06/09: 1000 + 100 = 1100. 07/09: +200 - 900 = 400. Nada que cubrir.
chequear('06/09 no necesita nada', 0.0, $pa['USO_INV']['dias']['2026-09-06']);
chequear('07/09 tampoco: el flujo es negativo pero hay caja', 0.0, $pa['USO_INV']['dias']['2026-09-07']);
chequear('el saldo final del 07/09 es 400, sin tocar los fondos', 400.0, $pa['SALDO_FIN']['dias']['2026-09-07']);

// 08/09: -2600 -> saldo -2200. Inversiones pone 1000; faltan 1200 -> 1 dolar
// (1500). Saldo final 300.
chequear('08/09: Inversiones pone sus 1000', 1000.0, $pa['USO_INV']['dias']['2026-09-08']);
chequear('08/09: la comitente pone 1 dolar, en pesos', 1500.0, $pa['USO_COM']['dias']['2026-09-08']);
chequear('08/09: el saldo final queda con el vuelto del dolar', 300.0, $pa['SALDO_FIN']['dias']['2026-09-08']);

// Oct: +300 -> saldo 600 -> no alcanza para recomprar un dolar (1500), y la
// pila tiene la comitente arriba: Inversiones no recibe nada aunque entre.
chequear('Oct: el excedente no alcanza para un dolar y no salta a Inversiones',
    0.0, $pa['USO_INV']['meses']['2026-10']);
chequear('Oct: saldo final 600', 600.0, $pa['SALDO_FIN']['meses']['2026-10']);

seccion('el motor: el flujo con cobertura y el arrastre recogen lo calculado');

chequear('flujo sin cobertura del 08/09', -2600.0, $pa['FLUJO']['dias']['2026-09-08']);
chequear('flujo con cobertura = sin cobertura + los dos usos', -100.0, $pa['FLUJO_COB']['dias']['2026-09-08']);

$descuadre = array_values(array_filter($ta['warnings'], function ($w) {
    return strpos($w, 'no cierra') !== false;
}));
chequear('ni el arrastre ni la cobertura automatica dejan aviso de descuadre', [], $descuadre);

chequear('el KPI de cobertura del tramo suma lo automatico', 2500.0, $ta['kpi']['dias']['cobertura']);
chequear('el saldo minimo del tramo ya no es negativo', 300.0, $ta['kpi']['dias']['minimo']['valor']);

seccion('el motor: el desglose por columna distingue manual de calculado');

$d8 = $pa['USO_COM']['cobertura_columnas']['DIA|2026-09-08'];

chequear('la celda del 08/09 de la comitente no tiene nada manual', 0.0, $d8['manual']);
chequear('y 1500 calculados', 1500.0, $d8['automatico']);
chequear('en dolares: 1', 1.0, $d8['fondos']['CTA_2']['automatico']);
chequear('las columnas sin nada no figuran', false, isset($pa['USO_COM']['cobertura_columnas']['DIA|2026-09-06']));
chequear('cada fila de uso sabe que fondos aplica', ['CTA_2'], $pa['USO_COM']['fondos_fila']);
chequear('y la otra los suyos', ['CTA_1'], $pa['USO_INV']['fondos_fila']);

$cob = $pa['STOCK_INV']['cobertura'];

chequear('el resumen lleva lo automatico', 2500.0, $cob['automatico']);
chequear('y nada manual', 0.0, $cob['manual']);
chequear('lo aplicado es la suma', 2500.0, $cob['aplicado']);
chequear('por fondo, la comitente tiene 1500 automaticos', 1500.0, $cob['fondos']['CTA_2']['automatico']);
chequear('y se sabe que el motor la maneja', true, $cob['fondos']['CTA_2']['automatizable']);
chequear('con su moneda', 'USD', $cob['fondos']['CTA_2']['moneda']);
chequear('sin faltante', [], $cob['faltante']);

$avisosCob = array_values(array_filter($ta['warnings'], function ($w) {
    return strpos($w, 'Cobertura:') === 0;
}));
chequear('con todo cubierto no hay ningun aviso de cobertura', [], $avisosCob);

seccion('el motor: una carga manual va primero y queda separada de lo calculado');

// 400 a mano de Inversiones el 08/09: el motor pone 600 mas y la comitente
// sigue poniendo 1 dolar (faltan 1200).
$usoInvManual = $serieA($ha, ['2026-09-08' => 400]);
$usoInvManual['fondos'] = ['CTA_1' => 'Inversiones'];
$usoInvManual['por_fondo'] = ['CTA_1' => 400];
$usoInvManual['fondos_manual'] = ['CTA_1' => ['DIA|2026-09-08' => ['importe' => 400, 'ars' => 400]]];

list($tm, $pm) = $correr(array_merge($seriesBase, [
    'COBERTURA' => ['USO_INVERSION' => $usoInvManual, 'USO_COMITENTE' => $usoCom]
]));

chequear('la celda muestra manual + automatico: 400 + 600', 1000.0, $pm['USO_INV']['dias']['2026-09-08']);

$dm = $pm['USO_INV']['cobertura_columnas']['DIA|2026-09-08'];
chequear('el desglose dice 400 a mano', 400.0, $dm['manual']);
chequear('y 600 calculados', 600.0, $dm['automatico']);
chequear('por fondo tambien: lo manual', 400.0, $dm['fondos']['CTA_1']['manual']);
chequear('y lo calculado', 600.0, $dm['fondos']['CTA_1']['automatico']);
chequear('el resumen separa los dos', [400.0, 2100.0],
    [$pm['STOCK_INV']['cobertura']['manual'], $pm['STOCK_INV']['cobertura']['automatico']]);
chequear('y el saldo final es el mismo que sin la carga', 300.0, $pm['SALDO_FIN']['dias']['2026-09-08']);

seccion('el motor: si no alcanza, se aplica todo, queda el faltante y se avisa');

list($tf, $pf) = $correr(array_merge($seriesBase, [
    'COMEX_PROV_EXT' => ['PAGOS' => $serieA($ha, ['2026-09-08' => 30000])]
]));

chequear('Inversiones entera', 1000.0, $pf['USO_INV']['dias']['2026-09-08']);
chequear('los 10 dolares', 15000.0, $pf['USO_COM']['dias']['2026-09-08']);
chequear('el saldo final queda en rojo', -12700.0, $pf['SALDO_FIN']['dias']['2026-09-08']);
chequear('el faltante viaja en el resumen', 12700.0, $pf['STOCK_INV']['cobertura']['faltante']['DIA|2026-09-08']);

$avisoFalta = null;
foreach ($tf['warnings'] as $w) {
    if (strpos($w, 'Cobertura: aun aplicando') === 0) { $avisoFalta = $w; }
}

chequear('hay un aviso de que no alcanza', true, $avisoFalta !== null);
chequear('que dice cuanto falta', true, $avisoFalta !== null && strpos($avisoFalta, '12.700,00') !== false);

seccion('el motor: un fondo con stock y sin fila de uso no se toca, y se avisa');

// Sin la fila de la comitente (como antes de correr el script), el motor solo
// usa Inversiones y dice que la comitente quedo afuera.
$eaSinCom = new EstructuraCobAuto();
$eaSinCom->secciones = $ea->secciones;
$eaSinCom->filas = array_values(array_filter($ea->filas, function ($f) { return $f['CODIGO'] !== 'USO_COM'; }));

$ms = new CashflowCobAuto($eaSinCom, new ParametrosCobAuto(), $ha);
$ms->series = $seriesBase;
$ts = $ms->proyectar();
$ps = [];
foreach ($ts['filas'] as $f) { $ps[$f['codigo']] = $f; }

chequear('Inversiones pone lo suyo', 1000.0, $ps['USO_INV']['dias']['2026-09-08']);
chequear('y el saldo queda en rojo por lo que la comitente habria puesto', -1200.0, $ps['SALDO_FIN']['dias']['2026-09-08']);

$avisoSinFila = null;
foreach ($ts['warnings'] as $w) {
    if (strpos($w, 'ninguna fila de uso') !== false) { $avisoSinFila = $w; }
}

chequear('avisa que la comitente no tiene fila', true,
    $avisoSinFila !== null && strpos($avisoSinFila, '"Cuenta comitente"') !== false);
chequear('y nombra el script', true,
    $avisoSinFila !== null && strpos($avisoSinFila, 'cashflow_cobertura_automatica.sql') !== false);

seccion('el motor: una fila de uso informativa no calcula nada');

$eaInf = new EstructuraCobAuto();
$eaInf->secciones = $ea->secciones;
$eaInf->filas = array_map(function ($f) {
    if ($f['TIPO'] === 'USO_COBERTURA') { $f['COMPUTA'] = 0; }
    return $f;
}, $ea->filas);

$mi = new CashflowCobAuto($eaInf, new ParametrosCobAuto(), $ha);
$mi->series = $seriesBase;
$ti = $mi->proyectar();
$pi = [];
foreach ($ti['filas'] as $f) { $pi[$f['codigo']] = $f; }

chequear('sin filas que computen, no se rescata', 0.0, $pi['USO_INV']['dias']['2026-09-08']);
chequear('y el saldo final queda como estaba', -2200.0, $pi['SALDO_FIN']['dias']['2026-09-08']);

seccion('el motor: sin la serie de tope, el fondo no es automatizable');

// Un stock que reparte pero no dice el tope por columna (una fila que siga
// leyendo del proveedor retirado, por ejemplo): se muestra, descuenta lo
// manual, y el motor no rescata de ahi.
$stockViejo = $serieA($ha, ['2026-09-06' => 1000]);
$stockViejo['por_fondo'] = ['CTA_1' => 1000];
$stockViejo['fondos'] = ['CTA_1' => 'Inversiones'];

list($tv, $pv) = $correr(array_merge($seriesBase, ['FONDO_INVERSION' => ['STOCK' => $stockViejo]]));

chequear('Inversiones no se usa sola', 0.0, $pv['USO_INV']['dias']['2026-09-08']);
chequear('la comitente si: 2 dolares para los 2200', 3000.0, $pv['USO_COM']['dias']['2026-09-08']);
chequear('el resumen lo dice', false, $pv['STOCK_INV']['cobertura']['fondos']['CTA_1']['automatizable']);

/* ================================================================
   LA CARGA MANUAL NO PUEDE SUPERAR EL FONDO A ESA FECHA
   Cobertura::validarDisponible() es pura: recibe el saldo del fondo a la
   fecha y las aplicaciones vigentes, y decide.
   ================================================================ */
seccion('una carga manual que supera lo disponible en el fondo se rechaza, con el numero');

$aplicVigentes = [
    ['FECHA' => '2026-09-10', 'IMPORTE' => 300.0, 'ORIGEN' => 'CTA_1'],   // antes: descuenta
    ['FECHA' => '2026-09-22', 'IMPORTE' => 900.0, 'ORIGEN' => 'CTA_1'],   // misma fecha: se pisa, no descuenta
    ['FECHA' => '2026-09-25', 'IMPORTE' => 500.0, 'ORIGEN' => 'CTA_1'],   // despues: no descuenta
    ['FECHA' => '2026-09-10', 'IMPORTE' => 5.0, 'ORIGEN' => 'CTA_2'],     // otro fondo: no descuenta
];

chequear('disponible = saldo a la fecha menos lo aplicado a mano ANTES desde ese fondo',
    700.0, Cobertura::disponibleParaAplicar(1000, $aplicVigentes, '2026-09-22', 'CTA_1'));
chequear('la de la misma fecha no descuenta: se pisa al guardar', true,
    Cobertura::disponibleParaAplicar(1000, $aplicVigentes, '2026-09-22', 'CTA_1') === 700.0);
chequear('el otro fondo tiene su propia cuenta', 5.0,
    Cobertura::disponibleParaAplicar(10, $aplicVigentes, '2026-09-22', 'CTA_2'));

chequear('700 pasa justo', 700.0,
    Cobertura::validarDisponible(700, 1000, $aplicVigentes, '2026-09-22', 'CTA_1', 'Inversiones', 'ARS'));
chequearLanza('700,01 no', function () use ($aplicVigentes) {
    Cobertura::validarDisponible(700.01, 1000, $aplicVigentes, '2026-09-22', 'CTA_1', 'Inversiones', 'ARS');
});

try {
    Cobertura::validarDisponible(5000, 1000, $aplicVigentes, '2026-09-22', 'CTA_1', 'Inversiones', 'ARS');
    $msgDisp = '';
} catch (Exception $e) {
    $msgDisp = $e->getMessage();
}

// NO SE RECORTA EN SILENCIO: el mensaje dice cuanto hay y de donde sale.
chequear('el mensaje dice cuanto hay disponible', true, strpos($msgDisp, '$ 700,00 disponibles') !== false);
chequear('y el saldo del fondo', true, strpos($msgDisp, 'saldo del fondo $ 1.000,00') !== false);
chequear('y nombra el fondo y la fecha', true,
    strpos($msgDisp, '"Inversiones"') !== false && strpos($msgDisp, '22/09/2026') !== false);

try {
    Cobertura::validarDisponible(20, 10, $aplicVigentes, '2026-09-22', 'CTA_2', 'Cuenta comitente', 'USD');
    $msgUsd = '';
} catch (Exception $e) {
    $msgUsd = $e->getMessage();
}

chequear('en dolares habla en dolares', true, strpos($msgUsd, 'US$ 5,00 disponibles') !== false);

// Un negativo pasa siempre: devolver plata al fondo no tiene tope.
chequear('un negativo pasa aunque el fondo este vacio, y hasta sobregirado', -300.0,
    Cobertura::validarDisponible(-200, 0, $aplicVigentes, '2026-09-22', 'CTA_1', 'Inversiones', 'ARS'));

// Con el fondo ya sobregirado por cargas viejas, no entra ni un peso mas y el
// mensaje no muestra un disponible negativo, que no significa nada.
try {
    Cobertura::validarDisponible(1, 100, $aplicVigentes, '2026-09-22', 'CTA_1', 'Inversiones', 'ARS');
    $msgCero = '';
} catch (Exception $e) {
    $msgCero = $e->getMessage();
}

chequear('con el fondo sobregirado, hay $ 0,00 disponibles', true, strpos($msgCero, '$ 0,00 disponibles') !== false);

/* Y guardar() la usa: sin esto el mensaje de arriba seria letra muerta. */
$cuerpoGuardarAuto = (function () {
    $r = new ReflectionMethod('Cobertura', 'guardar');

    return implode('', array_slice(file(__DIR__ . '/../cashflow/Class/Cobertura.php'),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
})();

chequear('guardar() valida contra el disponible', true,
    strpos($cuerpoGuardarAuto, 'self::validarDisponible(') !== false);
chequear('y pisa por fecha Y fondo', true,
    strpos($cuerpoGuardarAuto, '$this->bajaVigentes($cid, $f, $org)') !== false);

/* ================================================================
   EL TOPE POR COLUMNA, DESDE LAS CUENTAS
   ================================================================ */
seccion('el saldo proyectado de un fondo: lo de hoy mas lo previsto hasta la fecha');

$cuentaP = ['SALDO_INICIAL' => 1000, 'FECHA_SALDO_INICIAL' => '2026-09-01'];
$movsP = [
    ['FECHA' => '2026-09-03', 'TIPO' => 'RESCATE', 'IMPORTE' => 100, 'VIGENTE' => 1],      // ya en el saldo de hoy
    ['FECHA' => '2026-09-10', 'TIPO' => 'RESCATE', 'IMPORTE' => 200, 'VIGENTE' => 1],      // previsto
    ['FECHA' => '2026-09-12', 'TIPO' => 'SUSCRIPCION', 'IMPORTE' => 500, 'VIGENTE' => 1],  // previsto
    ['FECHA' => '2026-09-12', 'TIPO' => 'SUSCRIPCION', 'IMPORTE' => 9999, 'VIGENTE' => 0], // dado de baja
];

// Hoy 06/09 el saldo es 900. Se pasa ese 900 y la funcion suma lo previsto.
chequear('a hoy es el saldo de hoy', 900.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-09-06'));
chequear('antes del rescate previsto, igual', 900.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-09-09'));
chequear('el dia del rescate previsto baja', 700.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-09-10'));
chequear('y la suscripcion prevista sube', 1200.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-09-30'));
chequear('un movimiento dado de baja no cuenta', 1200.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-12-31'));
chequear('hacia atras, el saldo que habia ese dia', 1000.0, Fondos::saldoProyectado(900, $cuentaP, $movsP, '2026-09-06', '2026-09-02'));
chequear('sin movimientos, constante', 900.0, Fondos::saldoProyectado(900, $cuentaP, [], '2026-09-06', '2026-12-31'));

seccion('la fecha de cada columna del eje');

$hf = new Horizonte(3, 3, [], new DateTime('2026-09-06'));
$fechasCol = FondosProvider::fechasPorColumna($hf);

chequear('una columna diaria es su dia', '2026-09-07', $fechasCol['DIA|2026-09-07']);
chequear('una mensual es el ULTIMO dia del mes', '2026-10-31', $fechasCol['MES|2026-10']);
chequear('el mes en curso tambien', '2026-09-30', $fechasCol['MES|2026-09']);
chequear('hay una fecha por columna', 6, count($fechasCol));

seccion('el bloque fondos_tope de una cuenta');

$cuentaUsd = ['MONEDA' => 'USD', 'CLASE' => 'COMITENTE', 'saldo' => 10,
              'SALDO_INICIAL' => 10, 'FECHA_SALDO_INICIAL' => '2026-09-01',
              'movimientos_vigentes' => [
                  ['FECHA' => '2026-09-08', 'TIPO' => 'RESCATE', 'IMPORTE' => 4, 'VIGENTE' => 1]
              ]];
$tcCol = [];
foreach ($fechasCol as $col => $fecha) { $tcCol[$fecha] = ['fecha' => '2026-09-05', 'valor' => 1500, 'punta' => 'TCV']; }
$tcCol['2026-09-07'] = null;

$bloque = FondosProvider::tope($cuentaUsd, 3, '2026-09-06', $fechasCol, $tcCol);

chequear('lleva moneda, clase y orden', ['USD', 'COMITENTE', 3], [$bloque['moneda'], $bloque['clase'], $bloque['orden']]);
chequear('el tope de hoy es el saldo', 10.0, $bloque['tope']['DIA|2026-09-06']);
chequear('el rescate previsto baja el tope desde su dia', 6.0, $bloque['tope']['DIA|2026-09-08']);
chequear('y las columnas mensuales lo arrastran', 6.0, $bloque['tope']['MES|2026-11']);
chequear('la cotizacion va por columna', 1500.0, $bloque['tc']['DIA|2026-09-06']);
chequear('y un dia sin cotizacion queda en null, no en cero', null, $bloque['tc']['DIA|2026-09-07']);

$bloqueArs = FondosProvider::tope(['MONEDA' => 'ARS', 'CLASE' => 'INVERSION', 'saldo' => 3000,
    'SALDO_INICIAL' => 3000, 'FECHA_SALDO_INICIAL' => null], 0, '2026-09-06', $fechasCol, null);

chequear('en pesos no hay cotizacion', [], $bloqueArs['tc']);
chequear('y sin movimientos el tope es constante', 3000.0, $bloqueArs['tope']['MES|2026-11']);

seccion('la cotizacion de muchas fechas en dos consultas');

class CotizacionLote extends Cotizacion {
    public $entre = [];
    public $llamadasUltima = 0;
    public $llamadasEntre = 0;
    public function __construct() { /* sin conexion */ }
    public function ultimaHasta($fecha, $punta = self::COMPRADOR) {
        $this->llamadasUltima++;
        return ['fecha' => '2026-09-05', 'valor' => 1500, 'punta' => $punta];
    }
    protected function entreFechas($desde, $hasta, $punta) {
        $this->llamadasEntre++;
        return $this->entre;
    }
}

$lote = new CotizacionLote();
$lote->entre = [['fecha' => '2026-09-08', 'valor' => 1600, 'punta' => 'TCV']];
$res = $lote->ultimasHasta(['2026-09-10', '2026-09-06', '2026-09-08', '2026-09-06'], Cotizacion::VENDEDOR);

chequear('una llamada hacia atras y una hacia adelante', [1, 1], [$lote->llamadasUltima, $lote->llamadasEntre]);
chequear('antes de la fila intermedia vale la ultima hacia atras', 1500.0, $res['2026-09-06']['valor']);
chequear('el dia de la fila intermedia, esa', 1600.0, $res['2026-09-08']['valor']);
chequear('y despues tambien', 1600.0, $res['2026-09-10']['valor']);
chequear('con la fecha de la que salio', '2026-09-08', $res['2026-09-10']['fecha']);
chequear('las repetidas no se duplican', 3, count($res));

$unaSola = new CotizacionLote();
$unaSola->ultimasHasta(['2026-09-06']);
chequear('con una sola fecha no se consulta hacia adelante', 0, $unaSola->llamadasEntre);

/* ================================================================
   EL PROVEEDOR DE COBERTURA: UNA SERIE POR CLASE, Y LO MANUAL POR COLUMNA
   ================================================================ */
seccion('el proveedor reparte lo manual por clase de fondo');

class CoberturaDePrueba extends Cobertura {
    public $origenesFalsos = [];
    public $filas = [];
    public function __construct() { /* sin conexion */ }
    public function getAvisos() { return []; }
    public function origenes() { return $this->origenesFalsos; }
    public function valuarAplicaciones($cotizacion = null) {
        $porOrigen = [];
        foreach ($this->filas as $a) {
            $porOrigen[$a['ORIGEN']] = (isset($porOrigen[$a['ORIGEN']]) ? $porOrigen[$a['ORIGEN']] : 0) + $a['IMPORTE_ARS'];
        }
        return ['filas' => $this->filas, 'por_origen' => $porOrigen, 'sin_cotizacion' => 0.0, 'error' => null];
    }
}

class CoberturaProviderDePrueba extends CoberturaProvider {
    public $falsa;
    protected function cobertura() { return $this->falsa; }
}

$hp = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

$falsa = new CoberturaDePrueba();
$falsa->origenesFalsos = [
    'CTA_1' => ['id' => 1, 'nombre' => 'Inversiones', 'moneda' => 'ARS', 'clase' => 'INVERSION', 'activo' => true],
    'CTA_2' => ['id' => 2, 'nombre' => 'Cuenta comitente', 'moneda' => 'USD', 'clase' => 'COMITENTE', 'activo' => true],
    'CTA_3' => ['id' => 3, 'nombre' => 'Fondo viejo', 'moneda' => 'ARS', 'clase' => 'INVERSION', 'activo' => false],
];
$falsa->filas = [
    ['FECHA' => '2026-09-07', 'IMPORTE' => 400.0, 'MONEDA' => 'ARS', 'ORIGEN' => 'CTA_1', 'IMPORTE_ARS' => 400.0],
    ['FECHA' => '2026-09-07', 'IMPORTE' => 2.0, 'MONEDA' => 'USD', 'ORIGEN' => 'CTA_2', 'IMPORTE_ARS' => 3000.0],
    ['FECHA' => '2026-10-15', 'IMPORTE' => 1.0, 'MONEDA' => 'USD', 'ORIGEN' => 'CTA_2', 'IMPORTE_ARS' => 1500.0],
    ['FECHA' => '2026-09-08', 'IMPORTE' => 50.0, 'MONEDA' => 'ARS', 'ORIGEN' => 'SUSCRIPCION', 'IMPORTE_ARS' => 50.0],
    ['FECHA' => '2020-01-01', 'IMPORTE' => 7.0, 'MONEDA' => 'ARS', 'ORIGEN' => 'CTA_1', 'IMPORTE_ARS' => 7.0],
];

$pp = new CoberturaProviderDePrueba('COBERTURA');
$pp->falsa = $falsa;
$sp = $pp->series($hp);

chequear('sirve las tres series', ['USO_INVERSION', 'USO_COMITENTE', 'APLICACION'], array_keys($sp));

$inv = $sp['USO_INVERSION'];
$com = $sp['USO_COMITENTE'];
$tot = $sp['APLICACION'];

chequear('lo de inversiones va a su serie', 400.0, $inv['dias']['2026-09-07']);
chequear('los dolares van a la comitente, ya en pesos, sin los 400 de inversiones',
    3000.0, $com['dias']['2026-09-07']);
chequear('en una columna mensual tambien', 1500.0, $com['meses']['2026-10']);
chequear('el total junta todo', 3400.0, $tot['dias']['2026-09-07']);

// LA SERIE NOMBRA TODAS LAS CUENTAS ACTIVAS DE SU CLASE, aunque no hayan
// aplicado nada: es lo que le dice al motor que fondos aplica esa fila.
chequear('la serie de inversion nombra sus cuentas activas', ['CTA_1' => 'Inversiones'], $inv['fondos']);
chequear('la inhabilitada no', false, isset($inv['fondos']['CTA_3']));
chequear('la comitente las suyas', ['CTA_2' => 'Cuenta comitente'], $com['fondos']);

// LO MANUAL POR COLUMNA, EN LAS DOS MONEDAS.
chequear('fondos_manual lleva el importe en la moneda del fondo y en pesos',
    ['importe' => 2.0, 'ars' => 3000.0], $com['fondos_manual']['CTA_2']['DIA|2026-09-07']);
chequear('en pesos las dos cifras coinciden',
    ['importe' => 400.0, 'ars' => 400.0], $inv['fondos_manual']['CTA_1']['DIA|2026-09-07']);
chequear('la columna mensual va con su id', true, isset($com['fondos_manual']['CTA_2']['MES|2026-10']));

// Una clave que no es de ninguna cuenta: a la serie de inversion (la fila
// que existia), suma al saldo, y se avisa.
chequear('la huerfana va a la serie de inversion', 50.0, $inv['dias']['2026-09-08']);
chequear('y reparte por su clave, para que el motor la vea', 50.0, $inv['por_fondo']['SUSCRIPCION']);
chequear('sin nombre: no es ninguna cuenta', false, isset($inv['fondos']['SUSCRIPCION']));
chequear('con aviso', true, strpos(implode(' ', $pp->warnings()), '50,00') !== false);

// Fuera del horizonte se informa, no se descarta.
chequear('lo de 2020 queda fuera del horizonte', 7.0, $inv['fuera_horizonte']);
chequear('y no entra en fondos_manual', false, isset($inv['fondos_manual']['CTA_1']['DIA|2020-01-01']));

/* ================================================================
   EL SCRIPT Y EL REGISTRO
   ================================================================ */
seccion('el script de la cobertura automatica');

$sqlAuto = __DIR__ . '/../sql/cashflow_cobertura_automatica.sql';

chequear('existe', true, file_exists($sqlAuto));

$txtAuto = file_get_contents($sqlAuto);

chequear('reapunta la fila de uso existente a USO_INVERSION', true,
    strpos($txtAuto, "SET ORIGEN_SERIE = 'USO_INVERSION'") !== false
    && strpos($txtAuto, "AND ORIGEN_SERIE = 'APLICACION'") !== false);
chequear('crea la fila de la comitente solo si no esta', true,
    strpos($txtAuto, "ORIGEN_SERIE = 'USO_COMITENTE')") !== false
    && strpos($txtAuto, "'USO_DOLARES_COMITENTE'") !== false);
chequear('entre los dos flujos netos: orden 25', true, preg_match("/'COBERTURA', 'USO_COMITENTE', 25, 1/", $txtAuto) === 1);
chequear('fija la clave fecha + fondo con un indice unico filtrado', true,
    strpos($txtAuto, 'CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COBAP_VIGENTE_FECHA_ORIGEN') !== false
    && strpos($txtAuto, 'WHERE VIGENTE = 1') !== false);
chequear('y antes controla que no haya duplicados', true, strpos($txtAuto, 'HAVING COUNT(*) > 1') !== false);
chequear('no borra nada', false, preg_match('/^\s*DELETE\s/mi', $txtAuto) === 1);

// El registro: las series existen, y el total esta relacionado con las partes.
$metaAuto = CashflowRegistry::meta('COBERTURA');

chequear('APLICACION es el total de las dos series por clase',
    ['USO_INVERSION', 'USO_COMITENTE'], $metaAuto['componentes']['APLICACION']);
chequear('las series del proveedor son las del registro', true,
    CashflowRegistry::serieExiste('COBERTURA', CoberturaProvider::SERIE_POR_CLASE['INVERSION'])
    && CashflowRegistry::serieExiste('COBERTURA', CoberturaProvider::SERIE_POR_CLASE['COMITENTE']));
chequear('hay una serie por clase de fondo', array_keys(Fondos::CLASES), array_merge(
    ['CTA_CORRIENTE', 'CAJA_AHORRO'], array_keys(CoberturaProvider::SERIE_POR_CLASE)));

// Activar el total junto con una parte lo rechaza el validador.
$filasDoble = [
    ['ID' => 1, 'CODIGO' => 'A', 'NOMBRE' => 'A', 'SECCION' => 'COB', 'TIPO' => 'USO_COBERTURA', 'COMPUTA' => 1,
     'ORIGEN_PROVIDER' => 'COBERTURA', 'ORIGEN_SERIE' => 'APLICACION', 'ORDEN' => 10, 'ACTIVO' => 1],
    ['ID' => 2, 'CODIGO' => 'B', 'NOMBRE' => 'B', 'SECCION' => 'COB', 'TIPO' => 'USO_COBERTURA', 'COMPUTA' => 1,
     'ORIGEN_PROVIDER' => 'COBERTURA', 'ORIGEN_SERIE' => 'USO_COMITENTE', 'ORDEN' => 20, 'ACTIVO' => 1],
];
$secDoble = [['CODIGO' => 'COB', 'NOMBRE' => 'Cobertura', 'ROL' => 'DERIVADO', 'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]];
$vDoble = CashflowEstructura::validar($secDoble, $filasDoble);

chequear('el total y una parte activas a la vez es un error de estructura', true,
    count(array_filter($vDoble['errores'], function ($e) { return strpos($e, 'dos veces') !== false; })) > 0);
