<?php
/**
 * El paso al proximo dia habil.
 *
 * LO QUE HACEN HOY VENTAS Y TARJETAS, FIJADO ANTES DE UNIFICARLO
 * --------------------------------------------------------------
 * Ventas::proximoHabil() y TarjetasVencimiento::habilSiguiente() son dos copias
 * de la misma mecanica: correr una fecha al primer dia habil siguiente, con el
 * respaldo de lunes a viernes cuando la fecha no esta en RO_T_CALENDARIO. La
 * primera seccion fija lo que devuelve cada una HOY, con los mismos casos para
 * las dos, para que el refactor que las hace delegar en un solo helper no pueda
 * mover una fecha sin que se note. Estas pruebas no se tocan con el refactor:
 * tienen que pasar igual antes y despues.
 *
 * Hay DOS diferencias entre las dos, y tambien se fijan: si en el tope de
 * treinta dias no aparece ningun habil, Tarjetas LANZA y Ventas devuelve la
 * fecha del tope y sigue -Ventas no puede pasar a lanzar: la pantalla nunca se
 * cae-; y el aviso de calendario faltante de Ventas sale sin tildes.
 *
 * proximoHabil() es privada y de instancia -acumula el aviso de calendario en
 * $this->warnings-, asi que se prueba por reflexion y sin pasar por el
 * constructor, que abre la conexion. Es LOGICA PURA sobre un mapa: mismo
 * criterio que conSaldo() en test_comex_saldo_pendiente.php.
 */

require_once __DIR__ . '/../Class/Ventas.php';
require_once __DIR__ . '/../Class/TarjetasVencimiento.php';

/**
 * Mapa de dias habiles de lunes a viernes entre dos fechas, con los feriados
 * que se le pasen marcados como NO habiles.
 */
function calendarioDH($desde, $hasta, $feriados = []) {
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

/**
 * Lo que devuelve hoy Ventas::proximoHabil(), con los avisos que dejo.
 *
 * @return array ['fecha' => 'Y-m-d', 'warnings' => [...]]
 */
function ventasProximoHabil($fecha, $habiles) {
    $clase = new ReflectionClass('Ventas');
    $ventas = $clase->newInstanceWithoutConstructor();

    $m = new ReflectionMethod('Ventas', 'proximoHabil');
    $m->setAccessible(true);

    $w = new ReflectionProperty('Ventas', 'warnings');
    $w->setAccessible(true);

    $fechaHabil = $m->invoke($ventas, $fecha, $habiles);

    return ['fecha' => $fechaHabil, 'warnings' => $w->getValue($ventas)];
}

/* Octubre de 2026, que es el mes de las pruebas: el 9 es viernes, el 10 y el
   11 son fin de semana y el 12 es lunes. El calendario termina el 31/10, asi
   que noviembre es un mes sin datos. */
$octubre = calendarioDH('2026-10-01', '2026-10-31', ['2026-10-12']);
$puente = calendarioDH('2026-10-01', '2026-10-31', ['2026-10-09', '2026-10-12']);

/* ================================================================
   Lo que hacen hoy, caso por caso
   ================================================================ */

$casosDH = [
    'una fecha habil queda donde esta' =>
        ['2026-10-07', $octubre, '2026-10-07', false, []],
    'un lunes feriado pasa al martes' =>
        ['2026-10-12', $octubre, '2026-10-13', true, []],
    'un sabado antes de un lunes feriado pasa al martes' =>
        ['2026-10-10', $octubre, '2026-10-13', true, []],
    'feriados encadenados: viernes feriado, fin de semana y lunes feriado' =>
        ['2026-10-09', $puente, '2026-10-13', true, []],
    'un sabado al final del calendario cae en el lunes del mes sin datos' =>
        ['2026-10-31', $octubre, '2026-11-02', true, ['2026-11']],
    'una fecha fuera del calendario de lunes a viernes se toma como habil' =>
        ['2026-11-04', $octubre, '2026-11-04', false, ['2026-11']],
    'sin ningun calendario, un sabado pasa al lunes' =>
        ['2026-10-10', [], '2026-10-12', true, ['2026-10']],
    'cruce de ano dentro del calendario' =>
        ['2026-12-31', calendarioDH('2026-12-01', '2027-01-31', ['2026-12-31', '2027-01-01']),
         '2027-01-04', true, []]
];

foreach ($casosDH as $nombre => $c) {
    list($fecha, $habiles, $esperada, $corrida, $faltan) = $c;

    seccion($nombre);

    $t = TarjetasVencimiento::habilSiguiente($fecha, $habiles);
    $v = ventasProximoHabil($fecha, $habiles);

    chequear('Tarjetas: la fecha', $esperada, $t['fecha']);
    chequear('Tarjetas: si se corrio', $corrida, $t['corrida']);
    chequear('Tarjetas: los meses sin calendario', $faltan, $t['faltan']);

    chequear('Ventas: la misma fecha', $esperada, $v['fecha']);

    // El aviso de Ventas es uno por mes que falta, deduplicado. Su texto es
    // el de Tarjetas SIN TILDES: es la segunda diferencia entre las dos, y se
    // fija tal como esta hoy.
    chequear('Ventas: un aviso por mes sin calendario',
        array_map(function ($mes) {
            return 'RO_T_CALENDARIO no tiene datos para ' . $mes
                . '. Se asumen habiles los dias de lunes a viernes.';
        }, $faltan),
        $v['warnings']);
}

/* ================================================================
   La unica diferencia: sin ningun habil en el tope
   ================================================================ */
seccion('sin ningun dia habil en treinta dias');

// Un mapa roto: cuarenta dias seguidos marcados como no habiles.
$roto = [];

for ($i = 0; $i < 40; $i++) {
    $roto[date('Y-m-d', strtotime('2026-10-01 +' . $i . ' day'))] = false;
}

chequearLanza('Tarjetas lanza: devolver la fecha del tope escondería el mapa roto',
    function () use ($roto) {
        TarjetasVencimiento::habilSiguiente('2026-10-01', $roto);
    },
    'No se encontró ningún día hábil en los 30 días siguientes a 2026-10-01. '
        . 'Revisá RO_T_CALENDARIO.');

$v = ventasProximoHabil('2026-10-01', $roto);

chequear('Ventas no lanza: devuelve la fecha del tope, treinta dias despues',
    '2026-10-31', $v['fecha']);
chequear('y hoy no deja ningun aviso', [], $v['warnings']);
