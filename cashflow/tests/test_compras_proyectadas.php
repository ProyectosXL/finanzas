<?php
/**
 * Compras proyectadas del exterior: la temporada, la cuota y la ventana.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. ENERO SE VA A LA TEMPORADA EQUIVOCADA. El verano cruza el fin de anio,
 *      asi que enero de 2027 es VER 26-27 y no VER 27-28. Resuelto por el anio
 *      calendario, toda la compra de enero buscaria el presupuesto de la
 *      temporada que todavia no empezo: o no lo encuentra -y el mes queda en
 *      cero- o encuentra el de la siguiente y proyecta plata de otra compra.
 *      Las dos cosas dan un tablero que cierra igual.
 *
 *   2. LA CUOTA NO SUMA 100 % POR TEMPORADA. Lo que el presupuesto da es la
 *      compra de UNA temporada. Si el reparto se normaliza por anio, sus seis
 *      meses suman el 58 % y el 42 % restante desaparece del tablero sin que
 *      ninguna suma lo delate.
 *
 *   3. UN MES SIN HISTORIA SE CONFUNDE CON UN MES EN CERO. Son dos cosas
 *      distintas: "no entro nada en octubre en tres anios" es informacion, y
 *      "no hay ni un registro de octubre" es una laguna que tiene que salir
 *      marcada SIN_HISTORIA. Con las dos en cero, la laguna se proyecta como
 *      una afirmacion.
 *
 *   4. LA VENTANA SE CORTA EN EL MES EQUIVOCADO. El ultimo mes NO es el ultimo
 *      del horizonte: es el ultimo cuyo PAGO cae adentro, y el pago va X dias
 *      antes de la recepcion. Fijarlo dejaria afuera dos meses de pago que el
 *      cuadro si puede mostrar, y ademas quedaria mintiendo el dia que alguien
 *      mueva D o X desde Parametros, que son editables.
 *
 *   5. DOS MESES DE RECEPCION CAEN EN EL MISMO MES DE PAGO. Depende de D y de
 *      X. Si cada uno descontara por su cuenta lo ya comprado de ese mes, el
 *      descuento se aplicaria dos veces y la estimacion quedaria en cero sin
 *      que nada lo explique. Por eso el mes de pago viaja en cada fila.
 *
 * TODO SE PRUEBA SIN BASE, que es justamente por lo que estas reglas viven
 * afuera de las consultas. HOY entra por parametro en todos lados, asi que
 * ninguna de estas pruebas caduca sola.
 */

require_once __DIR__ . '/../Class/ComprasProyectadas.php';

/* ========================================================================
   1. TEMPORADAS
   ======================================================================== */

seccion('La temporada de una fecha');

chequear('agosto arranca el verano',
    'VER 26-27', ComprasProyectadas::temporada('2026-08-01')['codigo']);

chequear('diciembre sigue siendo el mismo verano',
    'VER 26-27', ComprasProyectadas::temporada('2026-12-31')['codigo']);

/* EL CASO QUE JUSTIFICA LA FUNCION. Enero pertenece al verano que arranco en
   agosto del anio ANTERIOR. Por el anio calendario daria VER 27-28. */
chequear('enero es del verano que arranco el anio pasado',
    'VER 26-27', ComprasProyectadas::temporada('2027-01-13')['codigo']);

chequear('el 31 de enero todavia es verano',
    'VER 26-27', ComprasProyectadas::temporada('2027-01-31')['codigo']);

chequear('el 1 de febrero ya es invierno',
    'INV 27', ComprasProyectadas::temporada('2027-02-01')['codigo']);

chequear('julio cierra el invierno',
    'INV 27', ComprasProyectadas::temporada('2027-07-31')['codigo']);

chequear('agosto abre el verano siguiente',
    'VER 27-28', ComprasProyectadas::temporada('2027-08-01')['codigo']);

chequear('alcanza con Y-m',
    'INV 27', ComprasProyectadas::temporada('2027-05')['codigo']);

chequear('una fecha que no sirve devuelve null',
    null, ComprasProyectadas::temporada('no es una fecha'));

seccion('Los limites de cada temporada');

$ver = ComprasProyectadas::temporada('2026-09-15');
chequear('el verano arranca el 01/08', '2026-08-01', $ver['desde']);
chequear('el verano termina el 31/01 del anio siguiente', '2027-01-31', $ver['hasta']);

$inv = ComprasProyectadas::temporada('2027-03-10');
chequear('el invierno arranca el 01/02', '2027-02-01', $inv['desde']);
chequear('el invierno termina el 31/07', '2027-07-31', $inv['hasta']);

/* El cambio de siglo no es teorico para el formato de dos digitos: VER 99-00
   tiene que dar 99-00 y no 99-100. */
chequear('el formato de dos digitos cruza el siglo',
    'VER 99-00', ComprasProyectadas::armarTemporada(ComprasProyectadas::VERANO, 1999)['codigo']);

seccion('Los seis meses de una temporada');

chequear('el verano cruza el fin de anio',
    ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'],
    ComprasProyectadas::mesesDeTemporada(ComprasProyectadas::temporada('2026-10')));

chequear('el invierno queda dentro del anio',
    ['2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07'],
    ComprasProyectadas::mesesDeTemporada(ComprasProyectadas::temporada('2027-05')));

/* Ida y vuelta: cada uno de los seis meses tiene que volver a la MISMA
   temporada de la que salio. Es lo que garantiza que ningun mes quede en dos
   temporadas ni en ninguna. */
$sueltos = [];

foreach (['2026-10', '2027-05', '2027-09'] as $m) {
    $t = ComprasProyectadas::temporada($m);

    foreach (ComprasProyectadas::mesesDeTemporada($t) as $mes) {
        if (ComprasProyectadas::temporada($mes)['codigo'] !== $t['codigo']) {
            $sueltos[] = $mes;
        }
    }
}

chequear('cada mes de una temporada vuelve a esa temporada', [], $sueltos);

/* Doce meses seguidos tienen que repartirse en exactamente dos temporadas, sin
   huecos ni superposiciones. */
$codigos = [];

for ($i = 0; $i < 12; $i++) {
    $codigos[ComprasProyectadas::temporada(
        ComprasProyectadas::sumarMeses('2026-08', $i))['codigo']] = true;
}

chequear('doce meses seguidos son dos temporadas y no tres',
    ['VER 26-27', 'INV 27'], array_keys($codigos));

/* ========================================================================
   2. LA CUOTA
   ======================================================================== */

/**
 * Historia escrita a mano: dos anios, los doce meses, pesos elegidos para que
 * las cuentas se puedan hacer de cabeza.
 *
 * Verano  (8,9,10,11,12,1): 10+20+30+20+10+10 = 100 por anio
 * Invierno (2,3,4,5,6,7):    5+25+20+20+15+15 = 100 por anio
 */
function historiaCompraProyectada($anios = [2024, 2025], $factor = 1.0) {
    $porMes = [8 => 10, 9 => 20, 10 => 30, 11 => 20, 12 => 10, 1 => 10,
               2 => 5, 3 => 25, 4 => 20, 5 => 20, 6 => 15, 7 => 15];

    $out = [];

    foreach ($anios as $i => $anio) {
        foreach ($porMes as $mes => $peso) {
            $out[] = ['anio' => $anio, 'mes' => $mes,
                      'peso' => $peso * ($i === 0 ? 1.0 : $factor)];
        }
    }

    return $out;
}

seccion('La cuota se normaliza POR TEMPORADA');

$cuota = ComprasProyectadas::cuota(historiaCompraProyectada());

$sumaVer = array_sum($cuota['VER']['pct']);
$sumaInv = array_sum($cuota['INV']['pct']);

chequear('los seis meses del verano suman 100', 100.0, round($sumaVer, 6));
chequear('los seis meses del invierno suman 100', 100.0, round($sumaInv, 6));

/* Agosto pesa 10 de 100 DENTRO del verano, no 10 de 200 sobre el anio. Si
   estuviera normalizado por anio daria 5 %, y la temporada repartiria la mitad
   de su presupuesto. */
chequear('agosto es el 10 % del verano y no el 5 % del anio',
    10.0, round($cuota['VER']['pct'][8], 6));

chequear('octubre se lleva el 30 % del verano',
    30.0, round($cuota['VER']['pct'][10], 6));

chequear('marzo se lleva el 25 % del invierno',
    25.0, round($cuota['INV']['pct'][3], 6));

/* Enero es del VERANO aunque su numero de mes sea el 1: tiene que aparecer en
   la cuota de verano y NO en la de invierno. */
chequear('enero esta en la cuota de verano', true, isset($cuota['VER']['pct'][1]));
chequear('enero NO esta en la cuota de invierno', false, isset($cuota['INV']['pct'][1]));

seccion('Los anios se suman, no se promedian');

/* Segundo anio con el TRIPLE de volumen y el mismo reparto: la cuota no se
   mueve, porque los dos anios reparten igual. */
chequear('un anio mas grande con el mismo reparto no mueve la cuota',
    30.0, round(ComprasProyectadas::cuota(
        historiaCompraProyectada([2024, 2025], 3.0))['VER']['pct'][10], 6));

/* Ahora dos anios que reparten DISTINTO y con volumenes distintos. El anio
   grande tiene que pesar mas que el chico: es la diferencia entre sumar y
   promediar, y es la decision documentada en cuota().
     2024 (chico):  agosto 10, octubre 90     -> total 100
     2025 (grande): agosto 900, octubre 100   -> total 1000
   Sumando:   agosto 910 / 1100 = 82,72 %
   Promediando porcentajes: (10 % + 90 %) / 2 = 50 % */
$desparejo = [
    ['anio' => 2024, 'mes' => 8, 'peso' => 10],
    ['anio' => 2024, 'mes' => 10, 'peso' => 90],
    ['anio' => 2025, 'mes' => 8, 'peso' => 900],
    ['anio' => 2025, 'mes' => 10, 'peso' => 100]
];

chequear('el anio de mas volumen pesa mas (suma, no promedio)',
    82.727273, round(ComprasProyectadas::cuota($desparejo)['VER']['pct'][8], 6));

seccion('El filtro de anios');

$conViejo = array_merge(
    historiaCompraProyectada([2024, 2025]),
    [['anio' => 2019, 'mes' => 8, 'peso' => 100000]]
);

chequear('un anio fuera del filtro no entra en la cuota',
    10.0, round(ComprasProyectadas::cuota($conViejo, [2024, 2025])['VER']['pct'][8], 6));

chequear('sin filtro, ese mismo anio si entra',
    true, ComprasProyectadas::cuota($conViejo)['VER']['pct'][8] > 90.0);

seccion('Un mes sin historia no es un mes en cero');

/* Verano SIN octubre: ni un registro. No es lo mismo que octubre en cero. */
$sinOctubre = [];

foreach (historiaCompraProyectada() as $mov) {
    if ($mov['mes'] !== 10) {
        $sinOctubre[] = $mov;
    }
}

$c = ComprasProyectadas::cuota($sinOctubre);

chequear('el mes sin historia queda en null y no en cero',
    null, $c['VER']['pct'][10]);

chequear('sale informado en sin_datos', [10], $c['VER']['sin_datos']);

/* Y el resto se renormaliza: el presupuesto de la temporada se reparte entero
   entre los meses que SI tienen historia. Sin esto, la temporada repartiria el
   70 % y el tablero proyectaria de menos sin que nada lo delate. */
chequear('los meses con historia siguen sumando 100',
    100.0, round(array_sum($c['VER']['pct']), 6));

chequear('agosto pasa de 10 a 14,29 al repartirse lo de octubre',
    14.285714, round($c['VER']['pct'][8], 6));

/* El invierno no se entera: la normalizacion es por temporada. */
chequear('la falta de octubre no toca al invierno',
    25.0, round($c['INV']['pct'][3], 6));

seccion('Un mes con peso CERO si es un cero');

$conCero = $sinOctubre;
$conCero[] = ['anio' => 2025, 'mes' => 10, 'peso' => 0];

$c0 = ComprasProyectadas::cuota($conCero);

chequear('un registro en cero deja el mes en 0 % y no en null',
    0.0, $c0['VER']['pct'][10]);

chequear('y no sale en sin_datos', [], $c0['VER']['sin_datos']);

seccion('Una temporada entera sin historia');

$soloVerano = [];

foreach (historiaCompraProyectada() as $mov) {
    if (in_array($mov['mes'], [8, 9, 10, 11, 12, 1], true)) {
        $soloVerano[] = $mov;
    }
}

$cv = ComprasProyectadas::cuota($soloVerano);

chequear('los seis meses del invierno quedan sin datos',
    [2, 3, 4, 5, 6, 7], $cv['INV']['sin_datos']);

chequear('su base es cero', 0.0, $cv['INV']['base']);

chequear('y ningun mes inventa un porcentaje',
    [null, null, null, null, null, null], array_values($cv['INV']['pct']));

seccion('pctDelMes ubica el mes en su temporada');

chequear('octubre de 2026 usa la cuota de verano',
    30.0, round(ComprasProyectadas::pctDelMes($cuota, '2026-10'), 6));

chequear('enero de 2027 tambien usa la de verano',
    10.0, round(ComprasProyectadas::pctDelMes($cuota, '2027-01'), 6));

chequear('marzo de 2027 usa la de invierno',
    25.0, round(ComprasProyectadas::pctDelMes($cuota, '2027-03'), 6));

chequear('un mes sin historia devuelve null',
    null, ComprasProyectadas::pctDelMes($c, '2026-10'));

/* ========================================================================
   3. LA VENTANA
   ======================================================================== */

seccion('El ultimo mes se deriva del pago, no del horizonte');

/* Los parametros reales al 23/09/2026: eje 2026-09 .. 2027-08, horizonte hasta
   el 31/08/2027, D = 15, X = 47 (la cadena de Comex: embarque +5 al pago,
   embarque +52 a la recepcion), Y = 2 (DIAS_DESP_REC). */
$v = ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 15, 47, 2);

chequear('el ultimo mes de recepcion es octubre de 2027, no agosto',
    '2027-10', $v['ultimo']);

/* La cuenta entera, escrita: recepcion 15/10/2027 menos 47 dias es 29/08/2027,
   que es el ultimo mes del horizonte. El mes siguiente ya se pasa. */
chequear('su pago cae en el ultimo mes del horizonte',
    '2027-08-29', ComprasProyectadas::restarDias('2027-10-15', 47));

chequear('el mes siguiente ya paga fuera del horizonte',
    true, ComprasProyectadas::restarDias('2027-11-15', 47) > '2027-08-31');

seccion('La ventana se mueve con los parametros');

/* Sin dias de anticipo, el ultimo mes de recepcion ES el ultimo del horizonte:
   es el caso que demuestra que el corrimiento lo produce X y no otra cosa. */
chequear('con X = 0 el ultimo mes es el del horizonte',
    '2027-08', ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 15, 0, 2)['ultimo']);

chequear('con X = 90 se corre dos meses mas',
    '2027-11', ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 15, 90, 2)['ultimo']);

/* D TAMBIEN LO MUEVE, y en el sentido contrario a X: un D mas tarde empuja el
   pago mas tarde, asi que entran MENOS meses. Con D = 28 la recepcion de
   octubre paga el 11/09 y ya no entra, asi que la ventana cierra en septiembre. */
chequear('con D = 28 entra un mes menos',
    '2027-09', ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 28, 47, 2)['ultimo']);

/* Con D = 1 el pago se adelanta dentro del mismo mes y el ultimo no cambia:
   noviembre paga el 15/09 y sigue afuera. Vale escribirlo porque es el caso que
   hace ver que el corte lo decide la FECHA de pago y no el dia del mes. */
chequear('con D = 1 el ultimo mes no cambia',
    '2027-10', ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 1, 47, 2)['ultimo']);

seccion('La ventana toma los ULTIMOS M meses');

$v6 = ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 15, 47, 2);

chequear('devuelve seis meses', 6, count($v6['meses']));

chequear('en orden cronologico y terminando en el ultimo',
    ['2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10'],
    array_column($v6['meses'], 'mes'));

$v3 = ComprasProyectadas::ventana('2026-09', '2027-08-31', 3, 15, 47, 2);

chequear('con M = 3 son los tres ultimos, no los tres primeros',
    ['2027-08', '2027-09', '2027-10'], array_column($v3['meses'], 'mes'));

chequear('con M = 0 no hay meses pero el ultimo se sigue derivando',
    '2027-10', ComprasProyectadas::ventana('2026-09', '2027-08-31', 0, 15, 47, 2)['ultimo']);

seccion('Las tres fechas de cada mes');

$oct = $v6['meses'][5];

chequear('el mes es octubre de 2027', '2027-10', $oct['mes']);
chequear('la recepcion va al dia D', '2027-10-15', $oct['recepcion']);
chequear('el pago va X dias antes', '2027-08-29', $oct['pago']);
chequear('la nacionalizacion va Y dias antes', '2027-10-13', $oct['nacionalizacion']);
chequear('y el mes de pago viaja resuelto', '2027-08', $oct['mes_pago']);

seccion('La ventana cruza dos temporadas');

$porTemporada = [];

foreach ($v6['meses'] as $m) {
    $porTemporada[$m['temporada']['codigo']][] = $m['mes'];
}

chequear('mayo a julio son INV 27',
    ['2027-05', '2027-06', '2027-07'], $porTemporada['INV 27']);

chequear('agosto a octubre son VER 27-28',
    ['2027-08', '2027-09', '2027-10'], $porTemporada['VER 27-28']);

/* Una ventana larga tiene que cruzar TRES temporadas sin que ningun mes quede
   sin temporada: es el caso que rompe cualquier atajo de "la temporada de la
   ventana". */
$v12 = ComprasProyectadas::ventana('2026-09', '2027-08-31', 12, 15, 47, 2);
$codigos12 = [];

foreach ($v12['meses'] as $m) {
    chequear('el mes ' . $m['mes'] . ' tiene temporada', true, $m['temporada'] !== null);
    $codigos12[$m['temporada']['codigo']] = true;
}

chequear('doce meses de ventana cruzan tres temporadas',
    ['VER 26-27', 'INV 27', 'VER 27-28'], array_keys($codigos12));

seccion('El dia D se recorta al largo del mes');

/* Con D = 31 y febrero, componer la fecha sin recortar daria el 2 o 3 de
   marzo: el mes de recepcion se correria solo y con el la temporada. */
chequear('D = 31 en febrero cae el 28',
    '2027-02-28', ComprasProyectadas::fechaEnMes('2027-02', 31));

chequear('y en un febrero bisiesto cae el 29',
    '2028-02-29', ComprasProyectadas::fechaEnMes('2028-02', 29));

chequear('D = 31 en abril cae el 30',
    '2027-04-30', ComprasProyectadas::fechaEnMes('2027-04', 31));

chequear('D = 31 en un mes de 31 no se toca',
    '2027-03-31', ComprasProyectadas::fechaEnMes('2027-03', 31));

$vD31 = ComprasProyectadas::ventana('2027-01', '2027-12-31', 3, 31, 0, 0);

chequear('con D = 31 ningun mes de la ventana se corre de mes',
    ['2027-10', '2027-11', '2027-12'], array_column($vD31['meses'], 'mes'));

seccion('Dos meses de recepcion en el mismo mes de pago');

/* CON LOS PARAMETROS REALES EL MAPEO ES UNO A UNO, y hay que poder afirmarlo:
   es lo que hace que hoy el descuento no se aplique dos veces. */
$porPago = ComprasProyectadas::porMesDePago($v6);

chequear('con D = 15 y X = 47 cada mes de pago tiene un solo mes de recepcion',
    [1, 1, 1, 1, 1, 1], array_values(array_map('count', $porPago)));

chequear('y son seis meses de pago distintos', 6, count($porPago));

/* PERO NO ES UNA PROPIEDAD DE LA REGLA, ES DE ESTOS PARAMETROS. Con X = 45 y
   D = 15, marzo paga el 29/01 y abril el 01/03: febrero no paga nada, y el
   mapeo deja de ser uno a uno. Con X = 44 los dos caen en el mismo mes. */
$vX44 = ComprasProyectadas::ventana('2027-01', '2027-12-31', 12, 15, 44, 2);
$pagos44 = [];

foreach ($vX44['meses'] as $m) {
    $pagos44[$m['mes']] = $m['pago'];
}

chequear('con X = 44 marzo paga el 30/01', '2027-01-30', $pagos44['2027-03']);
chequear('y abril paga el 02/03', '2027-03-02', $pagos44['2027-04']);

chequear('febrero de 2027 no recibe ningun pago',
    false, isset(ComprasProyectadas::porMesDePago($vX44)['2027-02']));

/* El caso que obliga a agrupar: dos meses de recepcion cayendo en el mismo mes
   de pago. Lo que se descuenta de ese mes tiene que consumirse una sola vez. */
$vChoque = ComprasProyectadas::ventana('2027-01', '2027-12-31', 12, 28, 58, 2);
$choques = [];

foreach (ComprasProyectadas::porMesDePago($vChoque) as $mesPago => $recepciones) {
    if (count($recepciones) > 1) {
        $choques[$mesPago] = $recepciones;
    }
}

chequear('hay una combinacion de parametros que junta dos recepciones en un mes de pago',
    true, count($choques) > 0);

chequear('y porMesDePago las devuelve juntas, no repetidas',
    2, count(reset($choques)));

seccion('Los bordes de la ventana');

/* Si ni el primer mes llega a pagar adentro del eje, no hay ventana. Pasa
   cuando el eje termina antes del primer pago: aca el horizonte cierra el 10/09
   y la recepcion de septiembre paga el 15/09. No es un error, y lo que importa
   es que NO se invente un mes: quien lo lea tiene que poder decir que no hay
   nada que proyectar. */
$corto = ComprasProyectadas::ventana('2026-09', '2026-09-10', 6, 15, 0, 2);

chequear('sin ningun mes que pague adentro, el ultimo es null', null, $corto['ultimo']);
chequear('y la ventana viene vacia', [], $corto['meses']);

/* AL REVES DE LO QUE PARECE: un X grande no achica la ventana, la agranda. El
   pago va X dias ANTES, asi que con 400 dias de anticipo entran meses de
   recepcion de mas de un anio adelante, y sus pagos igual caen adentro. Se
   escribe porque es facil leer el parametro al reves al tocarlo. */
chequear('un X grande estira la ventana hacia adelante, no la corta',
    '2027-10', ComprasProyectadas::ventana('2026-09', '2026-09-30', 6, 15, 400, 2)['ultimo']);

/* M mas grande que la historia disponible no es un problema de esta funcion:
   devuelve los M meses igual, y el que no tenga presupuesto lo va a decir el
   estado de cobertura. */
chequear('con M = 24 devuelve 24 meses',
    24, count(ComprasProyectadas::ventana('2026-09', '2027-08-31', 24, 15, 47, 2)['meses']));

chequear('un M negativo se trata como cero',
    [], ComprasProyectadas::ventana('2026-09', '2027-08-31', -5, 15, 47, 2)['meses']);

seccion('Las utilidades de fecha');

chequear('sumarMeses cruza el fin de anio', '2027-01', ComprasProyectadas::sumarMeses('2026-11', 2));
chequear('sumarMeses resta', '2026-11', ComprasProyectadas::sumarMeses('2027-01', -2));
chequear('sumarMeses con 12 da el mismo mes del anio siguiente',
    '2027-09', ComprasProyectadas::sumarMeses('2026-09', 12));
chequear('sumarMeses no desborda en el 31',
    '2027-02', ComprasProyectadas::sumarMeses('2027-01', 1));

chequear('restarDias cruza el mes', '2027-08-29', ComprasProyectadas::restarDias('2027-10-15', 47));
chequear('restarDias con negativo suma', '2027-10-17', ComprasProyectadas::restarDias('2027-10-15', -2));
chequear('restarDias cruza el anio', '2026-12-30', ComprasProyectadas::restarDias('2027-02-15', 47));
