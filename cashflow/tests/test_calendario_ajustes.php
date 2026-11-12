<?php
/**
 * Los feriados bancarios editables: RO_T_CALENDARIO (externa, no se escribe)
 * mas los ajustes de RO_T_CASHFLOW_CALENDARIO_AJUSTE, aplicados al leer en
 * Ventas::getDiasHabiles().
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN AJUSTE QUE NO SE APLICA. El feriado se ve en Parametros y ningun
 *      pago se corre: nada falla, el tablero muestra la columna de siempre.
 *
 *   2. UNA TABLA QUE NO EXISTE ROMPE EL CALCULO. aplicar() esta en el camino de
 *      todo lo que corre una fecha; sin el script tiene que devolver el
 *      calendario de siempre, no lanzar.
 *
 *   3. UN AJUSTE QUE NO AJUSTA NADA. Un sabado, o un feriado que ya es
 *      feriado, en la grilla se leen como una correccion que nadie hizo.
 *
 * No toca la base: los ajustes se inyectan con una subclase.
 */

require_once __DIR__ . '/../Class/CalendarioAjustes.php';
require_once __DIR__ . '/../Class/DiasHabiles.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

/** Los ajustes vienen de una lista fija: no hay base. */
class CalendarioAjustesPrueba extends CalendarioAjustes {
    public $creada = true;
    public $ajustes = [];
    public $pedidos = 0;

    public function tablaCreada() {
        return $this->creada;
    }

    protected function vigentesEntre($desde, $hasta) {
        $this->pedidos++;

        return array_filter($this->ajustes, function ($f) use ($desde, $hasta) {
            return $f >= $desde && $f <= $hasta;
        }, ARRAY_FILTER_USE_KEY);
    }
}

// Semana del 23/11/2026: lunes 23 feriado en RO_T_CALENDARIO.
$externo = [
    '2026-11-20' => true,  // viernes
    '2026-11-21' => false, // sabado
    '2026-11-22' => false, // domingo
    '2026-11-23' => false, // lunes feriado
    '2026-11-24' => true,
    '2026-11-25' => true,
    '2026-11-26' => true,
    '2026-11-27' => true
];

seccion('aplicar(): los ajustes pisan al calendario externo');

$cal = new CalendarioAjustesPrueba();
$cal->ajustes = [
    '2026-11-24' => false, // feriado agregado: martes
    '2026-11-23' => true,  // el feriado del banco pasa a habil
    '2028-03-10' => false  // una fecha que RO_T_CALENDARIO no tiene
];

$mapa = $cal->aplicar($externo, '2026-11-20', '2026-11-27');

chequear('un feriado agregado: el martes deja de ser habil', false, $mapa['2026-11-24']);
chequear('un feriado del banco pasado a habil', true, $mapa['2026-11-23']);
chequear('lo demas queda como estaba', true, $mapa['2026-11-25'] && $mapa['2026-11-20']);
chequear('el sabado sigue no habil', false, $mapa['2026-11-21']);
chequear('fuera del rango no se agrega nada', false, isset($mapa['2028-03-10']));
chequear('el mapa sale en orden de fecha', array_keys($mapa),
    (function ($m) { $k = array_keys($m); sort($k); return $k; })($mapa));

$mapa2028 = $cal->aplicar([], '2028-03-01', '2028-03-31');
chequear('una fecha que la tabla externa no tiene se agrega', ['2028-03-10' => false], $mapa2028);

chequear('aplicarAjustes sin ajustes devuelve el mapa igual', $externo,
    CalendarioAjustes::aplicarAjustes($externo, []));

$sin = new CalendarioAjustesPrueba();
$sin->creada = false;
$sin->ajustes = ['2026-11-24' => false];

chequear('sin la tabla, el mapa queda intacto', $externo, $sin->aplicar($externo, '2026-11-20', '2026-11-27'));
chequear('y ni siquiera se consulta', 0, $sin->pedidos);
chequear('y avisa que script correr', true,
    strpos($sin->avisoSinTabla(), 'sql/cashflow_parametros_calendario.sql') !== false);

/** Una lectura que falla tampoco rompe: devuelve el calendario de siempre. */
class CalendarioAjustesRota extends CalendarioAjustesPrueba {
    protected function vigentesEntre($desde, $hasta) {
        throw new Exception('la base no responde');
    }
}

$logPrevio = ini_set('error_log', 'NUL');
chequear('con la lectura rota, el mapa queda intacto', $externo,
    (new CalendarioAjustesRota())->aplicar($externo, '2026-11-20', '2026-11-27'));
ini_set('error_log', $logPrevio);

seccion('DiasHabiles::siguiente() salta un feriado agregado');

$r = DiasHabiles::siguiente('2026-11-24', $mapa);
chequear('el martes agregado corre al miercoles', '2026-11-25', $r['fecha']);
chequear('y se marca como corrida', true, $r['corrida']);

$r = DiasHabiles::siguiente('2026-11-21', $mapa);
chequear('del sabado al lunes 23, que paso a habil', '2026-11-23', $r['fecha']);

$r = DiasHabiles::siguiente('2026-11-21', $externo);
chequear('sin el ajuste, el sabado iba al martes 24', '2026-11-24', $r['fecha']);

seccion('validar(): cada rechazo');

chequearLanza('una fecha que no existe', function () {
    CalendarioAjustes::validar('2026-02-30', 0, 'X', true);
});
chequearLanza('una fecha mal escrita', function () {
    CalendarioAjustes::validar('23/11/2026', 0, 'X', true);
});
chequearLanza('un sabado', function () {
    CalendarioAjustes::validar('2026-11-21', 0, 'X', true);
});
chequearLanza('un domingo pasado a habil', function () {
    CalendarioAjustes::validar('2026-11-22', 1, '', false);
});
chequearLanza('un feriado que ya es feriado en el calendario bancario', function () {
    CalendarioAjustes::validar('2026-11-23', 0, 'Soberania', false);
});
chequearLanza('un habil que ya es habil', function () {
    CalendarioAjustes::validar('2026-11-24', 1, '', true);
});
chequearLanza('una fecha sin calendario se toma habil: marcarla habil no cambia nada', function () {
    CalendarioAjustes::validar('2028-03-10', 1, '', null);
});
chequearLanza('un feriado sin descripcion', function () {
    CalendarioAjustes::validar('2026-11-24', 0, '   ', true);
});
chequearLanza('una descripcion de mas de 200', function () {
    CalendarioAjustes::validar('2026-11-24', 0, str_repeat('x', 201), true);
});
chequearLanza('un valor que no es ni feriado ni habil', function () {
    CalendarioAjustes::validar('2026-11-24', 2, 'X', true);
});

chequear('un feriado agregado valido', ['fecha' => '2026-11-24', 'dia_laboral' => 0,
    'descripcion' => 'Asueto bancario'],
    CalendarioAjustes::validar('2026-11-24', '0', ' Asueto bancario ', true));
chequear('pasar a habil un feriado, sin descripcion', ['fecha' => '2026-11-23', 'dia_laboral' => 1,
    'descripcion' => null],
    CalendarioAjustes::validar('2026-11-23', 1, '', false));
chequear('un feriado en una fecha que RO_T_CALENDARIO no tiene', 0,
    CalendarioAjustes::validar('2028-03-10', 0, 'Feriado nuevo', null)['dia_laboral']);

chequearLanza('guardar sin usuario no llega a la base', function () {
    (new CalendarioAjustesPrueba())->guardar('2026-11-24', 0, 'X', '');
});
chequearLanza('quitar sin usuario tampoco', function () {
    (new CalendarioAjustesPrueba())->quitar(1, null);
});

seccion('la grilla: origen de cada dia');

$filas = CalendarioAjustes::grilla($externo, [
    ['id' => 7, 'fecha' => '2026-11-24', 'dia_laboral' => 0, 'descripcion' => 'Asueto',
     'usuario' => 'ana', 'fecha_update' => '2026-11-12 10:00:00'],
    ['id' => 8, 'fecha' => '2026-11-23', 'dia_laboral' => 1, 'descripcion' => null,
     'usuario' => 'ana', 'fecha_update' => '2026-11-12 10:00:00']
]);

chequear('sin sabados ni domingos, en orden', ['2026-11-23', '2026-11-24'],
    array_column($filas, 'fecha'));
chequear('el feriado del banco pasado a habil sigue, como ajuste', ['AJUSTE', true, false, 8],
    [$filas[0]['origen'], $filas[0]['habil'], $filas[0]['externo'], $filas[0]['id']]);
chequear('el agregado, como ajuste feriado', ['AJUSTE', false, true],
    [$filas[1]['origen'], $filas[1]['habil'], $filas[1]['externo']]);

$soloBanco = CalendarioAjustes::grilla($externo, []);
chequear('sin ajustes, el feriado del banco con su origen', [['2026-11-23', 'CALENDARIO', false, null]],
    array_map(function ($f) { return [$f['fecha'], $f['origen'], $f['habil'], $f['id']]; }, $soloBanco));

seccion('el año y los feriados de comercio');

chequear('el año vacio es el actual', intval(date('Y')), CalendarioAjustes::validarAnio(''));
chequear('un año valido', 2027, CalendarioAjustes::validarAnio('2027'));
chequearLanza('un año que no es año', function () { CalendarioAjustes::validarAnio('27'); });

chequear('los feriados de comercio se ordenan y no se repiten', '01-01,05-01,09-28,12-25',
    Parametros::normalizarFeriadosComercio('12-25, 01-01,09-28,05-01,12-25'));
chequear('el 29 de febrero vale', '02-29', Parametros::normalizarFeriadosComercio('02-29'));
chequear('vacia es valida', '', Parametros::normalizarFeriadosComercio(' , '));
chequearLanza('un DD/MM no es MM-DD', function () { Parametros::normalizarFeriadosComercio('25/12'); });
chequearLanza('un dia que no existe', function () { Parametros::normalizarFeriadosComercio('12-32'); });

seccion('el cableado');

$leer = function ($ruta) {
    return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $ruta));
};
$ventas = $leer('Class/Ventas.php');

chequear('getDiasHabiles() es la base mas los ajustes', true,
    strpos($ventas, "return \$this->ajustesCalendario->aplicar(\n            \$this->getDiasHabilesBase(\$desde, \$hasta), \$desde, \$hasta);") !== false);
chequear('la lectura de RO_T_CALENDARIO sigue siendo una, en getDiasHabilesBase()', 1,
    preg_match('/function getDiasHabilesBase\(\$desde, \$hasta\) \{.*?FROM RO_T_CALENDARIO/s', $ventas));
chequear('CalendarioAjustes no escribe en RO_T_CALENDARIO', 0,
    preg_match('/(INSERT|UPDATE|DELETE)[^;]*RO_T_CALENDARIO\b/i', $leer('Class/CalendarioAjustes.php')));
chequear('las escrituras validan el usuario', 2,
    substr_count($leer('Class/CalendarioAjustes.php'), 'AuthCashflow::usuarioDeEscritura($usuario)'));

$sql = $leer('sql/cashflow_parametros_calendario.sql');
chequear('el script crea la tabla con su indice unico filtrado', true,
    strpos($sql, "ON dbo.RO_T_CASHFLOW_CALENDARIO_AJUSTE (FECHA)\n        WHERE VIGENTE = 1;") !== false);
chequear('y mueve los parametros sin tocar el VALOR', true,
    strpos($sql, "SET MODULO = 'CALENDARIO'") !== false && strpos($sql, 'SET VALOR') === false);
