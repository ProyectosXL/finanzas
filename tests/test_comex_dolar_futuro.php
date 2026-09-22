<?php
/**
 * Comex - la valuacion con dolar futuro ROFEX, en LAS DOS PESTANAS.
 *
 * Lo que se prueba es lo que decide QUE NUMERO sale: con que cotizacion se
 * valua cada contenedor, que pasa cuando el mes no esta en la curva, que manda
 * cuando hay una correccion cargada a mano, y cuando esa correccion se
 * descarta.
 *
 * Y DESDE feature/comex-nac-usd, TAMBIEN CRONO NACIONALIZACION. Sus gastos
 * estaban en dolares y el tablero los ubicaba en columnas de pesos; ahora se
 * valuan con la misma funcion, por el mes de la fecha de NACIONALIZACION. Las
 * pruebas de esa pestana estan abajo, junto con la que fija que los defaults
 * de valuar() siguen siendo los de Proveedores Exterior: si alguien los
 * invirtiera, esa pestana valuaria por un campo que no tiene y todos sus
 * importes irian a cero sin que nada falle.
 *
 * TODO SIN BASE. La curva se pasa como argumento -es un mapa- y las reglas son
 * funciones puras, que es exactamente por que viven en DolarFuturo::resolver()
 * y en Comex::descartaCotizacion() y no dentro de la consulta. La lectura del
 * origen no se prueba aca: eso es un SELECT contra un servidor vinculado.
 */

require_once __DIR__ . '/../cashflow/Class/Comex.php';

/**
 * Una curva de juguete con tres meses consecutivos y un hueco deliberado.
 *
 * El hueco -no hay 2026-12- es lo que permite probar el caso "falta un mes
 * intermedio" sin fabricar una segunda curva.
 */
function curvaDePrueba() {
    return [
        '2026-09' => ['clave' => '2026-09', 'simbolo' => 'DLR/SEP26',
                      'cotizacion' => 1500.0, 'anio' => 2026, 'mes' => 9,
                      'actualizacion' => '2026-09-16 18:00:00'],
        '2026-10' => ['clave' => '2026-10', 'simbolo' => 'DLR/OCT26',
                      'cotizacion' => 1610.5, 'anio' => 2026, 'mes' => 10,
                      'actualizacion' => '2026-09-16 18:00:00'],
        '2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26',
                      'cotizacion' => 1725.0, 'anio' => 2026, 'mes' => 11,
                      'actualizacion' => '2026-09-16 18:00:00'],
        '2027-01' => ['clave' => '2027-01', 'simbolo' => 'DLR/ENE27',
                      'cotizacion' => 1980.0, 'anio' => 2027, 'mes' => 1,
                      'actualizacion' => '2026-09-16 18:00:00']
    ];
}

/* ================================================================
   LA COTIZACION SALE DEL MES DE LA FECHA DE PAGO

   Es la regla completa: no hay un tipo de cambio para toda la serie, hay uno
   por mes. Valuar todo con un solo numero es lo que hacia el parametro
   'comex_tipo_cambio_usd' que este cambio retira.
   ================================================================ */
seccion('cada fila se valua con el dolar de SU mes de pago');

$curva = curvaDePrueba();

$r = DolarFuturo::resolver($curva, '2026-10-20', null);

chequear('toma la cotizacion de octubre', 1610.5, $r['cotizacion']);
chequear('y dice de que simbolo salio', 'DLR/OCT26', $r['simbolo']);
chequear('el mes de la curva es el del pago', '2026-10', $r['mes_curva']);
chequear('sin aproximar', 'CURVA', $r['origen']);
chequear('y sin motivo de rechazo', null, $r['motivo']);

// El dia del mes no cambia nada: la curva es mensual.
$r = DolarFuturo::resolver($curva, '2026-10-01', null);
chequear('el dia 1 del mes usa la misma cotizacion', 1610.5, $r['cotizacion']);

$r = DolarFuturo::resolver($curva, '2026-10-31', null);
chequear('el ultimo dia del mes tambien', 1610.5, $r['cotizacion']);

// DOS FILAS DE MESES DISTINTOS SE VALUAN DISTINTO. Es lo que el parametro
// unico no podia hacer, y la razon entera del cambio.
$sep = DolarFuturo::resolver($curva, '2026-09-30', null);
$nov = DolarFuturo::resolver($curva, '2026-11-02', null);

chequear('septiembre y noviembre no valen lo mismo', true,
    $sep['cotizacion'] !== $nov['cotizacion']);

// Acepta lo que devuelve sqlsrv para una columna de fecha, no solo strings.
$r = DolarFuturo::resolver($curva, new DateTime('2026-11-15'), null);
chequear('un DateTime se resuelve igual', 1725.0, $r['cotizacion']);

/* ================================================================
   FUERA DE CURVA: SE APROXIMA, PERO SE DICE

   La curva llega hasta donde llega y el horizonte del tablero se configura
   desde Parametros, asi que puede pedir un mes posterior. No valuar seria
   esconder un pago que existe; aproximar en silencio seria mostrar un numero
   que nadie puede explicar.
   ================================================================ */
seccion('un mes posterior al final de la curva usa el mas cercano, marcado');

$r = DolarFuturo::resolver($curva, '2027-06-10', null);

chequear('se valua con el ultimo mes que hay', 1980.0, $r['cotizacion']);
chequear('que es enero del 27', '2027-01', $r['mes_curva']);
chequear('LA FILA QUEDA MARCADA', 'APROXIMADA', $r['origen']);

// El mes pedido viaja igual: sin el, la pantalla no puede decir de que mes a
// que mes se aproximo.
chequear('y conserva el mes que se pidio', '2027-06', $r['mes_pago']);

seccion('un hueco en el medio de la curva tambien aproxima');

// Diciembre no esta en la curva: entre noviembre (a 1 mes) y enero (a 1 mes)
// hay empate, y gana el anterior. Para un EGRESO es lo conservador: si hay que
// equivocarse, mejor no subestimar lo que queda en caja.
$r = DolarFuturo::resolver($curva, '2026-12-15', null);

chequear('usa noviembre y no enero', 1725.0, $r['cotizacion']);
chequear('lo dice', 'APROXIMADA', $r['origen']);
chequear('y nombra el mes que aplico', '2026-11', $r['mes_curva']);

// Hacia atras no deberia haber caso -la curva arranca en el mes en curso- pero
// si lo hubiera, se resuelve igual en vez de quedar sin valuar.
$r = DolarFuturo::resolver($curva, '2026-05-10', null);

chequear('un mes anterior al inicio usa el primero de la curva', 1500.0, $r['cotizacion']);
chequear('marcado como aproximado', 'APROXIMADA', $r['origen']);

seccion('la distancia se mide en meses, no comparando textos');

chequear('de diciembre a enero hay un mes', 1,
    DolarFuturo::distanciaMeses('2026-12', '2027-01'));
chequear('y hacia atras, menos uno', -1,
    DolarFuturo::distanciaMeses('2027-01', '2026-12'));
chequear('un anio entero son doce', 12,
    DolarFuturo::distanciaMeses('2026-03', '2027-03'));

/* ================================================================
   EL OVERRIDE MANUAL MANDA SOBRE LA CURVA

   Comercio Exterior puede tener cerrada una operacion a un tipo de cambio que
   el mercado no refleja. Para esa fila el numero correcto lo sabe una persona.
   ================================================================ */
seccion('la cotizacion cargada a mano le gana a la curva');

$r = DolarFuturo::resolver($curva, '2026-10-20', 1450.75);

chequear('se valua con el override', 1450.75, $r['cotizacion']);
chequear('y se marca como tal', 'OVERRIDE', $r['origen']);

// EL MES DE LA CURVA VIAJA IGUAL. Es contra que se compara el valor cargado a
// mano: sin eso la pantalla no puede decir de cuanto fue la correccion.
chequear('pero informa que decia la curva', '2026-10', $r['mes_curva']);
chequear('con su simbolo', 'DLR/OCT26', $r['simbolo']);

// El override tambien manda cuando el mes esta fuera de curva: es una
// afirmacion sobre esta fila, no sobre la curva.
$r = DolarFuturo::resolver($curva, '2027-08-01', 2100.0);

chequear('manda tambien fuera de curva', 2100.0, $r['cotizacion']);
chequear('y sigue siendo override, no aproximada', 'OVERRIDE', $r['origen']);

seccion('un override invalido no se aplica: vuelve a mandar la curva');

// Cero y negativo no son "sin override": valuarian el contenedor en cero o en
// negativo. Se descartan y la fila vuelve a la curva, que es el criterio por
// defecto.
foreach ([0, '0', -100, 'ocho', ''] as $malo) {
    $r = DolarFuturo::resolver($curva, '2026-10-20', $malo);

    chequear('override "' . var_export($malo, true) . '" no se usa', 1610.5, $r['cotizacion']);
    chequear('y el origen sigue siendo la curva', 'CURVA', $r['origen']);
}

seccion('el override acepta la coma decimal que tipea el usuario');

chequear('con coma', 1450.75, DolarFuturo::validarCotizacion('1450,75'));
chequear('con separador de miles y coma', 1450.75, DolarFuturo::validarCotizacion('1.450,75'));
chequear('con punto decimal', 1450.75, DolarFuturo::validarCotizacion('1450.75'));
chequear('vacio es no tener override', null, DolarFuturo::validarCotizacion(''));
chequear('null tambien', null, DolarFuturo::validarCotizacion(null));
chequear('cero no es un override valido', null, DolarFuturo::validarCotizacion('0'));

/* ================================================================
   SIN FECHA NO SE VALUA, Y NO SE INVENTA NADA

   Es el criterio de todo el modulo: null y aviso, nunca cero. Un cero se
   leeria como "este contenedor no se paga".
   ================================================================ */
seccion('sin fecha de pago no hay mes, y sin mes no hay cotizacion');

$r = DolarFuturo::resolver($curva, null, null);

chequear('no se valua', null, $r['cotizacion']);
chequear('y dice por que', 'SIN_FECHA', $r['motivo']);
chequear('sin inventar un origen', null, $r['origen']);

// VALE INCLUSO CON OVERRIDE CARGADO. El override dice a cuanto valuar, no
// CUANDO se paga: un importe que no se puede ubicar en el tiempo no entra en
// ninguna columna del eje igual.
$r = DolarFuturo::resolver($curva, null, 1800.0);

chequear('ni siquiera con override cargado', null, $r['cotizacion']);
chequear('sigue siendo sin fecha', 'SIN_FECHA', $r['motivo']);

seccion('sin curva no se valua nada');

$r = DolarFuturo::resolver([], '2026-10-20', null);

chequear('no se valua', null, $r['cotizacion']);
chequear('y dice que falta la curva', 'SIN_CURVA', $r['motivo']);

// Pero un override cargado sigue valiendo: es un dato de la fila y no depende
// de que el ROFEX se pueda leer.
$r = DolarFuturo::resolver([], '2026-10-20', 1500.0);

chequear('un override vale aunque no haya curva', 1500.0, $r['cotizacion']);
chequear('marcado como override', 'OVERRIDE', $r['origen']);
chequear('y sin simbolo, porque no hay curva que nombrar', null, $r['simbolo']);

/* ================================================================
   LA VALUACION DE UNA FILA ENTERA

   Es lo que consumen la pestaña Y el tablero, calculado una sola vez.
   ================================================================ */
seccion('la fila vuelve con su importe en pesos');

$fila = Comex::valuar([
    'ID' => 1,
    'VALOR_FOB_DOLAR' => 10000,
    'FECHA_PAGO_EFECTIVA' => '2026-11-10',
    'COTIZ_USD_EDIT' => null
], $curva);

chequear('10.000 dolares de noviembre', 17250000.0, $fila['IMPORTE_ARS']);
chequear('con la cotizacion a la vista', 1725.0, $fila['COTIZ_USD']);
chequear('y el simbolo', 'DLR/NOV26', $fila['COTIZ_SIMBOLO']);
chequear('el FOB en dolares no se toca', 10000, $fila['VALOR_FOB_DOLAR']);

seccion('una fila sin fecha vuelve con importe null, NO con cero');

$fila = Comex::valuar([
    'ID' => 2,
    'VALOR_FOB_DOLAR' => 8000,
    'FECHA_PAGO_EFECTIVA' => null,
    'COTIZ_USD_EDIT' => null
], $curva);

// UN CERO SE SUMARIA como si el contenedor no costara nada. Con null, el
// llamador puede contar cuantos son e informar cuanto suman en dolares.
chequear('no se valua', null, $fila['IMPORTE_ARS']);
chequear('y el dolar tampoco', null, $fila['COTIZ_USD']);
chequear('pero los dolares siguen ahi', 8000, $fila['VALOR_FOB_DOLAR']);

seccion('el override de la fila se normaliza al leer');

// Un '0.0000' de SQL Server es verdadero en PHP: sin normalizar, la grilla
// dibujaria la marca de "corregida a mano" en una fila que no lo esta.
$fila = Comex::valuar([
    'ID' => 3, 'VALOR_FOB_DOLAR' => 1000,
    'FECHA_PAGO_EFECTIVA' => '2026-09-05', 'COTIZ_USD_EDIT' => '0.0000'
], $curva);

chequear('un cero de la base no es un override', null, $fila['COTIZ_USD_EDIT']);
chequear('asi que manda la curva', 'CURVA', $fila['COTIZ_ORIGEN']);

/* ================================================================
   CRONO NACIONALIZACION SE VALUA IGUAL, CON OTRO CAMPO Y OTRA FECHA

   Los gastos de nacionalizacion -los conceptos 3 a 10 de la estimacion- estan
   EN DOLARES: se calculan como porcentajes del CIF y el CIF arranca en
   VALOR_FOB_DOLAR. Hasta feature/comex-nac-usd el tablero los ubicaba en
   columnas de pesos. El porque y la verificacion contra la base estan en el
   encabezado de Class/Providers/ComexProvider.php.

   Lo que se prueba aca es que la MISMA funcion los valua, con el campo y la
   fecha de esa pestana.
   ================================================================ */
seccion('el gasto de nacionalizacion se valua por SU fecha');

$fila = Comex::valuar([
    'ID' => 10,
    'IMPORTE_EST' => 1000,
    'FECHA_NAC_EFECTIVA' => '2026-11-10'
], $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('1.000 dolares de noviembre', 1725000.0, $fila['IMPORTE_ARS']);
chequear('con la cotizacion a la vista', 1725.0, $fila['COTIZ_USD']);
chequear('y el simbolo', 'DLR/NOV26', $fila['COTIZ_SIMBOLO']);
chequear('el importe en dolares no se toca', 1000, $fila['IMPORTE_EST']);

/* LOS NOMBRES DE SALIDA SON LOS MISMOS EN LAS DOS PESTANAS. No es cosmetico:
   avisosValuacion() y la celda del front los leen por nombre, asi que si esta
   pestana devolviera COTIZ_* con otro nombre los avisos irian vacios. */
chequear('el detalle sale con el nombre de siempre', true,
    isset($fila['COTIZ_DETALLE']) && $fila['COTIZ_DETALLE'] !== '');
chequear('y el mes de la curva tambien', '2026-11', $fila['COTIZ_MES']);

seccion('la fecha que manda es la de nacionalizacion, no la de pago');

/* EL MISMO CONTENEDOR SE VALUA DISTINTO EN CADA PESTANA, y es todo el punto: el
   pago al proveedor y la nacionalizacion caen en meses distintos, asi que les
   toca un punto distinto de la curva. Si la nacionalizacion mirara la fecha de
   pago, el gasto se valuaria con el dolar de un mes en el que no se mueve. */
$contenedor = [
    'ID' => 11,
    'VALOR_FOB_DOLAR' => 100,
    'IMPORTE_EST' => 100,
    'FECHA_PAGO_EFECTIVA' => '2026-09-15',
    'FECHA_NAC_EFECTIVA' => '2026-11-20'
];

$pago = Comex::valuar($contenedor, $curva);
$nac = Comex::valuar($contenedor, $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('el pago usa septiembre', 1500.0, $pago['COTIZ_USD']);
chequear('la nacionalizacion usa noviembre', 1725.0, $nac['COTIZ_USD']);
chequear('y los dos importes no son el mismo', true,
    $pago['IMPORTE_ARS'] !== $nac['IMPORTE_ARS']);

seccion('una nacionalizacion fuera de curva se aproxima, y se dice');

$fila = Comex::valuar([
    'ID' => 12, 'IMPORTE_EST' => 100, 'FECHA_NAC_EFECTIVA' => '2027-06-10'
], $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('se valua con el mes mas cercano', 1980.0, $fila['COTIZ_USD']);
chequear('que es enero del 27', '2027-01', $fila['COTIZ_MES']);
chequear('LA FILA QUEDA MARCADA', 'APROXIMADA', $fila['COTIZ_ORIGEN']);

// Y hacia atras, que es el caso real: hay nacionalizaciones vencidas de meses
// anteriores al inicio de la curva.
$fila = Comex::valuar([
    'ID' => 13, 'IMPORTE_EST' => 100, 'FECHA_NAC_EFECTIVA' => '2025-04-01'
], $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('una vencida vieja usa el primer mes de la curva', 1500.0, $fila['COTIZ_USD']);
chequear('tambien marcada', 'APROXIMADA', $fila['COTIZ_ORIGEN']);

seccion('sin fecha de nacionalizacion no se valua, NO se pone cero');

$fila = Comex::valuar([
    'ID' => 14, 'IMPORTE_EST' => 5000, 'FECHA_NAC_EFECTIVA' => null
], $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('no se valua', null, $fila['IMPORTE_ARS']);
chequear('y dice por que', 'SIN_FECHA', $fila['COTIZ_MOTIVO']);
chequear('pero los dolares siguen ahi', 5000, $fila['IMPORTE_EST']);

seccion('esta pestana no tiene override: siempre manda la curva');

/* La consulta de Crono Nacionalizacion NO trae COTIZ_USD_EDIT, asi que la
   valuacion sale siempre de la curva. Es una decision y no un olvido: esa
   columna tiene UNA fila por contenedor y las dos pestanas miran el mismo
   contenedor en dos meses distintos. Ver Comex::valuar(). */
$fila = Comex::valuar([
    'ID' => 15, 'IMPORTE_EST' => 100, 'FECHA_NAC_EFECTIVA' => '2026-10-20'
], $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

chequear('sale de la curva', 'CURVA', $fila['COTIZ_ORIGEN']);
chequear('y el override viaja en null', null, $fila['COTIZ_USD_EDIT']);

/* ================================================================
   LOS DEFAULTS SIGUEN SIENDO LOS DE PROVEEDORES EXTERIOR

   Es la prueba que evita la regresion silenciosa: valuar() se parametrizo para
   que la segunda pestana no necesitara una copia, y la llamada de
   getProveedoresExterior() no cambio. Si alguien invirtiera los defaults, esa
   pestana empezaria a valuar por un campo que no tiene y todos sus importes
   irian a cero sin que nada falle.
   ================================================================ */
seccion('con los defaults, Proveedores Exterior valua exactamente igual');

$deProveedor = [
    'ID' => 16,
    'VALOR_FOB_DOLAR' => 10000,
    'FECHA_PAGO_EFECTIVA' => '2026-11-10',
    'COTIZ_USD_EDIT' => null
];

$conDefaults = Comex::valuar($deProveedor, $curva);
$explicito = Comex::valuar($deProveedor, $curva, 'VALOR_FOB_DOLAR', 'FECHA_PAGO_EFECTIVA');

chequear('el importe es el de siempre', 17250000.0, $conDefaults['IMPORTE_ARS']);
chequear('y pasar los nombres a mano da lo mismo', $explicito, $conDefaults);

// El override sigue mandando por el camino por defecto: es la unica pestana que
// lo tiene y no se puede haber perdido en la parametrizacion.
$conOverride = Comex::valuar([
    'ID' => 17, 'VALOR_FOB_DOLAR' => 1000,
    'FECHA_PAGO_EFECTIVA' => '2026-10-20', 'COTIZ_USD_EDIT' => 1450.75
], $curva);

chequear('el override manda igual que antes', 1450750.0, $conOverride['IMPORTE_ARS']);
chequear('y se marca como tal', 'OVERRIDE', $conOverride['COTIZ_ORIGEN']);

seccion('los avisos hablan de la pestana que los pide');

/* Con los campos escritos adentro, el aviso de Crono Nacionalizacion habria
   sumado VALOR_FOB_DOLAR -que esa pestana no trae- y habria dicho "U$S 0,00",
   nombrando ademas la fecha de pago, que no es la que falta. */
$sinFecha = [
    Comex::valuar(['IMPORTE_EST' => 4000, 'FECHA_NAC_EFECTIVA' => null],
        $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA')
];

$avisos = Comex::avisosValuacion($sinFecha, '2027-01', 'IMPORTE_EST',
    'fecha de nacionalización');

chequear('hay un aviso', 1, count($avisos));
chequear('suma el importe de ESTA pestana', true,
    strpos($avisos[0], 'U$S 4.000,00') !== false);
chequear('y nombra la fecha que falta', true,
    strpos($avisos[0], 'fecha de nacionalización') !== false);

// Y el default sigue siendo el de Proveedores Exterior.
$avisosExt = Comex::avisosValuacion(
    [Comex::valuar(['VALOR_FOB_DOLAR' => 4000, 'FECHA_PAGO_EFECTIVA' => null], $curva)],
    '2027-01');

chequear('el default nombra la fecha de pago', true,
    strpos($avisosExt[0], 'fecha estimada de pago') !== false);

/* ================================================================
   EL OVERRIDE SE DESCARTA SI CAMBIA EL MES DE PAGO

   Un override es una afirmacion sobre UN MES. Si el pago se corre a otro,
   dejarlo valuaria el mes nuevo con un numero pensado para el viejo.
   ================================================================ */
seccion('mover la fecha a otro mes descarta la cotizacion cargada a mano');

$r = Comex::descartaCotizacion('2026-11-10', '2027-02-15', 1725.0);

chequear('se descarta', true, $r['cotizacion_descartada']);
chequear('y se informa cual era', 1725.0, $r['cotizacion_anterior']);
chequear('de que mes venia', '2026-11', $r['mes_anterior']);
chequear('y a cual va', '2027-02', $r['mes_nuevo']);

seccion('dentro del MISMO mes el override sobrevive');

// La afirmacion sigue valiendo: es sobre el mes, no sobre el dia.
$r = Comex::descartaCotizacion('2026-11-10', '2026-11-28', 1725.0);

chequear('no se descarta', false, $r['cotizacion_descartada']);

seccion('sin override no hay nada que descartar');

$r = Comex::descartaCotizacion('2026-11-10', '2027-02-15', null);

chequear('aunque cambie el mes', false, $r['cotizacion_descartada']);

// Un override invalido tampoco cuenta: no habia nada cargado que perder.
$r = Comex::descartaCotizacion('2026-11-10', '2027-02-15', 0);

chequear('un override en cero tampoco se descarta', false, $r['cotizacion_descartada']);

seccion('sin mes utilizable no se borra el trabajo de nadie');

// No se pudo AFIRMAR que cambio de mes, asi que no se descarta. Borrar ante la
// duda destruiria un dato cargado a mano por una comparacion que no se pudo
// hacer.
$r = Comex::descartaCotizacion(null, '2027-02-15', 1725.0);

chequear('si no se sabe de donde venia, se conserva', false, $r['cotizacion_descartada']);

$r = Comex::descartaCotizacion('2026-11-10', null, 1725.0);

chequear('si no se sabe adonde va, tambien', false, $r['cotizacion_descartada']);

/* ================================================================
   LOS AVISOS

   Son los que hacen que un tablero que informa de menos lo diga. Estaticos y
   puros, y los MISMOS que muestra la pestana: un solo texto.
   ================================================================ */
seccion('lo que no se pudo valuar se informa EN DOLARES');

$filas = [
    Comex::valuar(['VALOR_FOB_DOLAR' => 5000, 'FECHA_PAGO_EFECTIVA' => null,
                   'COTIZ_USD_EDIT' => null], $curva),
    Comex::valuar(['VALOR_FOB_DOLAR' => 3000, 'FECHA_PAGO_EFECTIVA' => null,
                   'COTIZ_USD_EDIT' => null], $curva),
    Comex::valuar(['VALOR_FOB_DOLAR' => 1000, 'FECHA_PAGO_EFECTIVA' => '2026-10-01',
                   'COTIZ_USD_EDIT' => null], $curva)
];

$avisos = Comex::avisosValuacion($filas, '2027-01');

chequear('hay un aviso', 1, count($avisos));
chequear('dice cuantas son', true, strpos($avisos[0], '2 contenedor(es)') !== false);

// EN DOLARES Y NO EN PESOS: decirlo en pesos exigiria valuarlo, que es
// justamente lo que no se pudo hacer.
chequear('y cuanto suman en dolares', true, strpos($avisos[0], 'U$S 8.000,00') !== false);

seccion('lo aproximado y lo corregido a mano tambien se informan');

$filas = [
    Comex::valuar(['VALOR_FOB_DOLAR' => 1000, 'FECHA_PAGO_EFECTIVA' => '2027-09-01',
                   'COTIZ_USD_EDIT' => null], $curva),
    Comex::valuar(['VALOR_FOB_DOLAR' => 1000, 'FECHA_PAGO_EFECTIVA' => '2026-10-01',
                   'COTIZ_USD_EDIT' => 1400], $curva)
];

$avisos = Comex::avisosValuacion($filas, '2027-01');

chequear('son dos avisos', 2, count($avisos));
chequear('el primero nombra hasta donde llega la curva', true,
    strpos($avisos[0], '2027-01') !== false);
chequear('el segundo, la correccion a mano', true,
    strpos($avisos[1], 'corregida a mano') !== false);

seccion('sin nada raro no hay ningun aviso');

$filas = [
    Comex::valuar(['VALOR_FOB_DOLAR' => 1000, 'FECHA_PAGO_EFECTIVA' => '2026-10-01',
                   'COTIZ_USD_EDIT' => null], $curva)
];

chequear('ningun aviso de valuacion', 0, count(Comex::avisosValuacion($filas, '2027-01')));

/* ================================================================
   EL PARAMETRO VIEJO YA NO SE OFRECE
   ================================================================ */
seccion('comex_tipo_cambio_usd quedo retirado');

require_once __DIR__ . '/../cashflow/Class/Parametros.php';

// LA FILA NO SE BORRA de RO_T_CASHFLOW_PARAMETROS -queda el valor con el que se
// proyecto en su momento- pero el formulario ya no la muestra: un campo
// editable que no cambia nada es peor que no tenerlo.
chequear('esta en la lista de retirados', true,
    in_array('comex_tipo_cambio_usd', Parametros::RETIRADOS, true));

// Y el provider ya no lo nombra: el dolar futuro es el unico criterio.
chequear('ComexProvider ya no declara el parametro', false,
    defined('ComexProvider::PARAM_TIPO_CAMBIO'));
