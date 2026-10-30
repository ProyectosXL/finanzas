<?php
/**
 * Horizonte: el eje temporal compartido por Ventas y el Cashflow.
 *
 * Se usa siempre un dia de referencia FIJO para que las pruebas no dependan de
 * cuando se corren.
 */

require_once __DIR__ . '/../Class/Horizonte.php';

$hoy = new DateTime('2026-09-06');

/* ================================================================
   28 dias / 12 meses. El mes en curso queda FUERA de la secuencia
   porque solo contiene dias que ya pasaron (del 1 al 5 de septiembre).
   ================================================================ */
seccion('28 dias / 12 meses, hoy = 2026-09-06');

$h = new Horizonte(28, 12, ['12-25', '01-01', '09-26'], $hoy);

chequear('cantidad de columnas diarias', 28, count($h->dias()));
chequear('cantidad de columnas mensuales', 12, count($h->meses()));
chequear('primer dia', '2026-09-06', $h->dias()[0]['fecha']);
chequear('ultimo dia', '2026-10-03', $h->dias()[27]['fecha']);
chequear('primer mes', '2026-09', $h->meses()[0]['clave']);
chequear('ultimo mes', '2027-08', $h->meses()[11]['clave']);
chequear('rotulo del primer mes', 'Sep-26', $h->meses()[0]['label']);
chequear('rotulo del primer dia', '6/9', $h->dias()[0]['label']);
chequear('fin del eje', '2027-08-31', $h->fin());

// El tramo va del 06/09 al 03/10, asi que el feriado 09-26 SI entra;
// el 25/12 y el 01/01 quedan afuera.
$feriados = array_values(array_map(
    function ($d) { return $d['fecha']; },
    array_filter($h->dias(), function ($d) { return $d['feriado_comercio']; })
));
chequear('feriados de comercio marcados dentro del tramo', ['2026-09-26'], $feriados);

seccion('secuencia cronologica');

$seq = $h->secuencia();

chequear('largo de la secuencia (28 dias + 11 meses)', 39, count($seq));
chequear('primer elemento', 'DIA|2026-09-06', $seq[0]);
chequear('ultimo dia del tramo', 'DIA|2026-10-03', $seq[27]);
chequear('despues del tramo arranca el eje mensual', 'MES|2026-10', $seq[28]);
chequear('ultimo elemento', 'MES|2027-08', $seq[38]);
chequear('el mes en curso NO esta en la secuencia', false, $h->enSecuencia('MES|2026-09'));
chequear('un mes futuro SI esta', true, $h->enSecuencia('MES|2026-10'));

seccion('a que columna va cada fecha');

chequear('un dia del tramo', 'DIA|2026-09-10', $h->columna('2026-09-10'));
chequear('un dia del mes en curso fuera del tramo', 'MES|2026-09', $h->columna('2026-09-02'));
chequear('un dia de un mes futuro', 'MES|2027-03', $h->columna('2027-03-15'));
chequear('una fecha fuera del eje', null, $h->columna('2028-01-01'));

/* ================================================================
   La inversion de orden: con 20 dias el tramo cierra el 25/09 y el
   resto de septiembre cae DESPUES del tramo. Es la razon por la que
   recorrer las columnas en el orden en que se dibujan daria mal el
   arrastre del saldo.
   ================================================================ */
seccion('20 dias: el mes en curso va DESPUES del tramo');

$h2 = new Horizonte(20, 12, [], $hoy);
$seq2 = $h2->secuencia();

chequear('ultimo dia del tramo', '2026-09-25', $h2->dias()[19]['fecha']);
chequear('largo de la secuencia (20 dias + 12 meses)', 32, count($seq2));
chequear('el mes en curso entra DESPUES de los dias', 'MES|2026-09', $seq2[20]);
chequear('y despues sigue octubre', 'MES|2026-10', $seq2[21]);
chequear('con este horizonte el mes en curso SI esta en la secuencia',
    true, $h2->enSecuencia('MES|2026-09'));
chequear('pero en el eje dibujado sigue siendo la primera columna mensual',
    '2026-09', $h2->meses()[0]['clave']);

/* ================================================================
   Agrupamiento
   ================================================================ */
seccion('agrupar()');

$items = [
    ['F' => '2026-09-10', 'IMP' => 100],                    // dentro del tramo
    ['F' => '2026-09-10', 'IMP' => 50],                     // mismo dia, se suma
    ['F' => '2026-11-20', 'IMP' => 200],                    // mes futuro
    ['F' => '2026-09-02', 'IMP' => 30],                     // mes en curso, fuera del tramo
    ['F' => '2030-01-01', 'IMP' => 999],                    // fuera del eje
    ['F' => null,         'IMP' => 7],                      // sin fecha
    ['F' => new DateTime('2026-09-11'), 'IMP' => 25],       // DateTime, como devuelve sqlsrv
];

$serie = $h->agrupar($items, 'F', 'IMP');

chequear('dos importes del mismo dia se suman', 150.0, $serie['dias']['2026-09-10']);
chequear('un DateTime se normaliza a Y-m-d', 25.0, $serie['dias']['2026-09-11']);
chequear('mes futuro', 200.0, $serie['meses']['2026-11']);
chequear('dia del mes en curso fuera del tramo va al mes', 30.0, $serie['meses']['2026-09']);
chequear('lo que cae fuera del eje se informa, no se descarta', 999.0, $serie['fuera_horizonte']);
chequear('lo que no tiene fecha se informa', 7.0, $serie['sin_fecha']);
chequear('un dia del tramo NO se cuenta tambien en su mes', 0, $serie['meses']['2026-10']);
chequear('la serie trae todas las claves diarias', 28, count($serie['dias']));
chequear('la serie trae todas las claves mensuales', 12, count($serie['meses']));

$conFactor = $h->agrupar([['F' => '2026-09-10', 'IMP' => 100]], 'F', 'IMP', 1500);
chequear('el factor se aplica (tipo de cambio)', 150000.0, $conFactor['dias']['2026-09-10']);

/* ================================================================
   Solo mensual: lo que usa el Analisis de Ventas
   ================================================================ */
seccion('solo mensual');

$h4 = new Horizonte(0, 12, [], $hoy);

chequear('sin columnas diarias', 0, count($h4->dias()));
chequear('12 columnas mensuales', 12, count($h4->meses()));
chequear('la secuencia son los 12 meses', 12, count($h4->secuencia()));
chequear('sin tramo diario, el mes en curso SI entra', 'MES|2026-09', $h4->secuencia()[0]);

/* ================================================================
   Validaciones y helpers
   ================================================================ */
seccion('validaciones y helpers');

chequearLanza('horizonte de meses en cero lanza',
    function () { new Horizonte(28, 0, [], new DateTime('2026-09-06')); },
    'El horizonte de meses debe ser mayor a cero');

chequearLanza('horizonte de dias negativo lanza',
    function () { new Horizonte(-1, 12, [], new DateTime('2026-09-06')); },
    'El horizonte de dias no puede ser negativo');

chequear('labelMes enero', 'Ene-27', Horizonte::labelMes(2027, 1));
chequear('labelMes diciembre', 'Dic-25', Horizonte::labelMes(2025, 12));
chequear('normalizarFecha con DateTime', '2026-05-04',
    Horizonte::normalizarFecha(new DateTime('2026-05-04 13:22:00')));
chequear('normalizarFecha con string con hora', '2026-05-04',
    Horizonte::normalizarFecha('2026-05-04 13:22:00'));
chequear('normalizarFecha con null', null, Horizonte::normalizarFecha(null));
chequear('normalizarFecha con cadena vacia', null, Horizonte::normalizarFecha(''));

seccion('tramo diario mas largo que el eje mensual');

$h6 = new Horizonte(90, 1, [], $hoy);

chequear('fin() lo manda el tramo diario', '2026-12-04', $h6->fin());
chequear('los 90 dias entran en la secuencia', 90, count($h6->secuencia()));

/* ================================================================
   Los dias no habiles del tramo diario
   ================================================================ */
seccion('dias no habiles: la marca de cada columna diaria');

// Del jueves 08/10/2026 al miercoles 14/10/2026. El calendario de la prueba
// tiene el lunes 12 como no laborable y no tiene el 14.
$cal = [
    '2026-10-08' => true,  '2026-10-09' => true,
    '2026-10-10' => false, '2026-10-11' => false,
    '2026-10-12' => false, '2026-10-13' => true
];

$hNh = new Horizonte(7, 1, [], new DateTime('2026-10-08'));

$marcas = function ($h) {
    return array_combine(array_column($h->dias(), 'fecha'), array_column($h->dias(), 'no_habil'));
};

chequear('sin calendario, el respaldo: solo los fines de semana', [
    '2026-10-08' => null, '2026-10-09' => null,
    '2026-10-10' => 'FIN_DE_SEMANA', '2026-10-11' => 'FIN_DE_SEMANA',
    '2026-10-12' => null, '2026-10-13' => null, '2026-10-14' => null
], $marcas($hNh));

$hNh->marcarCalendario($cal);

chequear('con calendario: el lunes no laborable es feriado', [
    '2026-10-08' => null, '2026-10-09' => null,
    '2026-10-10' => 'FIN_DE_SEMANA', '2026-10-11' => 'FIN_DE_SEMANA',
    '2026-10-12' => 'FERIADO', '2026-10-13' => null, '2026-10-14' => null
], $marcas($hNh));

chequear('los textos del tooltip', [null, null, 'Fin de semana', 'Fin de semana', 'Feriado', null, null],
    array_column($hNh->dias(), 'no_habil_texto'));

chequear('dia de semana habil', null, Horizonte::noHabil('2026-10-13', $cal));
chequear('sabado', 'FIN_DE_SEMANA', Horizonte::noHabil('2026-10-10', $cal));
chequear('domingo', 'FIN_DE_SEMANA', Horizonte::noHabil('2026-10-11', $cal));
chequear('feriado en dia de semana', 'FERIADO', Horizonte::noHabil('2026-10-12', $cal));
// Mismo criterio que Echeqs::correrAHabil(): el feriado en fin de semana se
// dice fin de semana.
chequear('feriado en fin de semana: se rotula fin de semana', 'FIN_DE_SEMANA',
    Horizonte::noHabil('2026-11-21', ['2026-11-21' => false]));
chequear('un sabado que el calendario da como laboral sigue siendo fin de semana', 'FIN_DE_SEMANA',
    Horizonte::noHabil('2026-10-10', ['2026-10-10' => true]));
chequear('fecha de semana fuera del calendario: habil, sin marca', null,
    Horizonte::noHabil('2026-10-14', $cal));
chequear('fecha de fin de semana fuera del calendario: se marca igual', 'FIN_DE_SEMANA',
    Horizonte::noHabil('2026-11-21', $cal));
chequear('calendario vacio (no se pudo leer): solo fines de semana', null,
    Horizonte::noHabil('2026-10-12', []));

chequear('el feriado de comercio no cambia con la marca',
    [false, false, false, false, false, false, false],
    array_column($hNh->dias(), 'feriado_comercio'));
chequear('marcar no toca las columnas mensuales', ['clave', 'anio', 'mes', 'label'],
    array_keys($hNh->meses()[0]));

seccion('dias no habiles: el calendario sale de la unica lectura');

$srcHorizonte = file_get_contents(__DIR__ . '/../Class/Horizonte.php');
$srcParametrosH = file_get_contents(__DIR__ . '/../Class/Parametros.php');
$srcVentasH = file_get_contents(__DIR__ . '/../Class/Ventas.php');

chequear('Horizonte pide el calendario por Ventas::getDiasHabiles()', 1,
    substr_count($srcHorizonte, '(new Ventas())->getDiasHabiles($desde, $hasta)'));
chequear('RO_T_CALENDARIO se consulta en un solo lugar del modulo', 1,
    array_sum(array_map(function ($f) {
        return substr_count(file_get_contents($f), 'FROM RO_T_CALENDARIO');
    }, array_merge(glob(__DIR__ . '/../Class/*.php'), glob(__DIR__ . '/../Controller/*.php')))));
chequear('la consulta no lee ES_FERIADO: Feriado es DIA_LABORAL = 0', false,
    strpos($srcVentasH, 'ES_FERIADO') !== false);
chequear('la validacion de Parametros no espera al calendario', 1,
    substr_count($srcParametrosH, 'Horizonte::desdeParametros($this, $map, false)'));
chequear('el eje propio de Ventas tambien se marca', 1,
    substr_count($srcVentasH, '->conCalendario();'));
