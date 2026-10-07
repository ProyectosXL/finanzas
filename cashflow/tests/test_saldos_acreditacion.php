<?php
/**
 * Saldos: el dia de acreditacion de cada local.
 *
 * Cada local propio acredita -o envia- su efectivo un dia fijo de la semana, y
 * la fila Caja Locales del tablero imputa el aporte de cada uno en su PROXIMA
 * FECHA DE ACREDITACION. Lo delicado es el calendario -hoy es el dia, el dia es
 * feriado, feriados encadenados, cruce de mes y de ano, una fecha fuera de
 * RO_T_CALENDARIO- y que la serie del tablero reparta el mismo total que antes
 * en otras columnas. Todo vive en helpers estaticos puros de Saldos y se prueba
 * aca sin base; hoy se inyecta, asi que las pruebas no caducan.
 *
 * La ultima seccion toca la base y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../Class/Saldos.php';

/* ================================================================
   El script
   ================================================================ */
seccion('el script del dia de acreditacion');

$scriptDA = file_get_contents(__DIR__ . '/../sql/cashflow_saldos_dia_acreditacion.sql');
$completoDA = file_get_contents(__DIR__ . '/../sql/cashflow_saldos.sql');

chequear('agrega el dia al parametro del local', 1, preg_match(
    "/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_SUCURSAL\s+ADD DIA_ACREDITACION TINYINT NULL/",
    $scriptDA));
chequear('con un CHECK de lunes a viernes', 2,
    substr_count($scriptDA, 'CHECK (DIA_ACREDITACION BETWEEN 1 AND 5)'));
chequear('y la foto guarda el dia y la fecha', [1, 1], [
    preg_match("/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_LOCAL\s+ADD DIA_ACREDITACION TINYINT NULL/",
        $scriptDA),
    preg_match("/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_LOCAL\s+ADD FECHA_ACREDITACION DATE NULL/",
        $scriptDA)
]);

// Sin default, a proposito: un default pondria a todos los locales en el
// mismo dia y esconderia que falta cargarlo.
chequear('el dia no tiene DEFAULT', 0, preg_match('/DEFAULT/i',
    preg_replace('/\/\*.*?\*\//s', '', $scriptDA)));
chequear('es reejecutable: cada columna se agrega solo si falta', 3,
    preg_match_all("/COL_LENGTH\('dbo\.RO_T_CASHFLOW_SALDOS_(SUCURSAL|LOCAL)', "
        . "'(DIA|FECHA)_ACREDITACION'\) IS NULL/", $scriptDA));
chequear('y no borra ni pisa datos', 0,
    preg_match('/\b(DELETE|DROP|UPDATE|TRUNCATE)\b/i', preg_replace('/\/\*.*?\*\//s', '', $scriptDA)));
chequear('el mismo bloque va dentro de cashflow_saldos.sql', [1, 1, 1], [
    preg_match_all('/ADD DIA_ACREDITACION TINYINT NULL\s+CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_SUCURSAL_DIA/',
        $completoDA),
    preg_match_all('/ADD DIA_ACREDITACION TINYINT NULL\s+CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_DIA/',
        $completoDA),
    substr_count($completoDA, 'ADD FECHA_ACREDITACION DATE NULL')
]);

/* ================================================================
   La regla: la proxima fecha de acreditacion
   ================================================================ */

/**
 * Mapa de dias habiles de lunes a viernes entre dos fechas, con los feriados
 * que se le pasen marcados como NO habiles.
 */
function calendarioSA($desde, $hasta, $feriados = []) {
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

/* Octubre y noviembre de 2026, con el lunes 12/10 feriado. El 7/10 es
   miercoles. El calendario de $calOct termina el 31/10: noviembre es un mes
   sin datos. */
$calOct = calendarioSA('2026-10-01', '2026-10-31', ['2026-10-12']);
$calOctNov = calendarioSA('2026-10-01', '2026-11-30', ['2026-10-12']);

seccion('hoy es el dia');

$r = Saldos::proximaFechaAcreditacion(3, '2026-10-07', $calOct);

chequear('si hoy es el dia, la proxima fecha es hoy', '2026-10-07', $r['fecha']);
chequear('y no se corrio', false, $r['corrida']);
chequear('la celda dice el dia y la fecha', 'Mié · 07/10', Saldos::etiquetaAcreditacion($r));
chequear('y el tooltip, que acredita ese dia', 'Acredita el miércoles 07/10.',
    Saldos::explicarAcreditacion($r, 'DEPOSITA'));

seccion('un dia que ya paso esta semana es el de la semana que viene');

chequear('el martes, visto un miercoles, es el martes siguiente', '2026-10-13',
    Saldos::proximaFechaAcreditacion(2, '2026-10-07', $calOct)['fecha']);
chequear('el viernes de esta semana es este viernes', '2026-10-09',
    Saldos::proximaFechaAcreditacion(5, '2026-10-07', $calOct)['fecha']);
chequear('un sabado, el lunes es pasado manana', '2026-10-05',
    Saldos::proximaFechaAcreditacion(1, '2026-10-03', $calOct)['fecha']);

seccion('el dia cae feriado');

$r = Saldos::proximaFechaAcreditacion(1, '2026-10-07', $calOct);

chequear('la teorica es el lunes feriado', '2026-10-12', $r['teorica']);
chequear('y pasa al martes: es una acreditacion, va hacia adelante', '2026-10-13', $r['fecha']);
chequear('marcada como corrida', true, $r['corrida']);
chequear('la celda dice el dia en que entra la plata, no el cargado', 'Mar · 13/10',
    Saldos::etiquetaAcreditacion($r));
chequear('y el tooltip dice por que', 'El lunes 12/10 es feriado: pasa al martes 13/10.',
    Saldos::explicarAcreditacion($r, 'DEPOSITA'));

seccion('hoy es el dia y es feriado');

$r = Saldos::proximaFechaAcreditacion(1, '2026-10-12', $calOct);

chequear('la teorica es hoy', '2026-10-12', $r['teorica']);
chequear('pero hoy no se acredita: pasa a manana', '2026-10-13', $r['fecha']);

seccion('feriados encadenados');

$puenteSA = calendarioSA('2026-10-01', '2026-10-31', ['2026-10-09', '2026-10-12', '2026-10-13']);
$r = Saldos::proximaFechaAcreditacion(5, '2026-10-07', $puenteSA);

chequear('viernes, fin de semana, lunes y martes feriados: pasa al miercoles', '2026-10-14',
    $r['fecha']);
chequear('el tooltip nombra el dia cargado y el final',
    'El viernes 09/10 es feriado: pasa al miércoles 14/10.',
    Saldos::explicarAcreditacion($r, 'DEPOSITA'));

seccion('cruce de mes y de ano');

chequear('a fin de mes, el martes es el del mes siguiente', '2026-11-03',
    Saldos::proximaFechaAcreditacion(2, '2026-10-29', $calOctNov)['fecha']);

$calAnio = calendarioSA('2026-12-01', '2027-01-31', ['2026-12-25', '2027-01-01']);
$r = Saldos::proximaFechaAcreditacion(5, '2026-12-30', $calAnio);

chequear('el viernes 1/1 es feriado y pasa al lunes 4/1 del ano siguiente',
    ['2027-01-01', '2027-01-04'], [$r['teorica'], $r['fecha']]);

seccion('una fecha fuera del calendario');

$r = Saldos::proximaFechaAcreditacion(1, '2026-10-29', $calOct);

chequear('se toma habil de lunes a viernes, como en Ventas', '2026-11-02', $r['fecha']);
chequear('y dice que mes falta, para que se avise', ['2026-11'], $r['faltan']);
chequear('el tooltip lo dice',
    'Acredita el lunes 02/11. RO_T_CALENDARIO no tiene datos para 2026-11: se asumió hábil '
        . 'de lunes a viernes.',
    Saldos::explicarAcreditacion($r, 'DEPOSITA'));

// Sin ningun calendario -el servidor 'power' no respondio- vale el mismo
// respaldo: el dia no se corre, y el aviso de la lectura lo da quien lee.
chequear('sin ningun calendario, el lunes es el lunes', '2026-10-12',
    Saldos::proximaFechaAcreditacion(1, '2026-10-07', [])['fecha']);

seccion('un mapa sin ningun habil');

$rotoSA = [];

for ($i = 0; $i < 60; $i++) {
    $rotoSA[date('Y-m-d', strtotime('2026-10-01 +' . $i . ' day'))] = false;
}

$r = Saldos::proximaFechaAcreditacion(1, '2026-10-07', $rotoSA);

chequear('no lanza, y la fecha es null: no se inventa la del tope', null, $r['fecha']);
chequear('con la marca', true, $r['sin_habil']);
chequear('la celda no muestra una fecha', 'sin fecha', Saldos::etiquetaAcreditacion($r));

seccion('un local sin dia');

chequear('sin dia devuelve null', null, Saldos::proximaFechaAcreditacion(null, '2026-10-07', $calOct));
chequear('vacio, tambien', null, Saldos::proximaFechaAcreditacion('', '2026-10-07', $calOct));
chequear('la celda dice sin dia', 'sin día', Saldos::etiquetaAcreditacion(null));
chequear('y el tooltip dice que se imputa hoy y donde se carga',
    'Sin día de acreditación cargado: lo que aporta se imputa hoy, en la primera columna del '
        . 'tablero. Se carga en Parámetros → Saldos → Locales.',
    Saldos::explicarAcreditacion(null, 'DEPOSITA'));

seccion('un local en Envia');

$r = Saldos::proximaFechaAcreditacion(2, '2026-10-07', $calOct);

chequear('la celda se ve igual', 'Mar · 13/10', Saldos::etiquetaAcreditacion($r));
chequear('el tooltip dice que es informativo',
    'Envía el martes 13/10. Es informativo: el local está en Envía y no aporta al cashflow.',
    Saldos::explicarAcreditacion($r, 'ENVIA'));
chequear('y sin dia, tambien',
    'Sin día de envío cargado. Se carga en Parámetros → Saldos → Locales. Es informativo: el '
        . 'local está en Envía y no aporta al cashflow.',
    Saldos::explicarAcreditacion(null, 'ENVIA'));

seccion('el dia se valida en el servidor');

chequear('un dia valido', 4, Saldos::validarDiaAcreditacion('4'));
chequear('vacio es sin dia', null, Saldos::validarDiaAcreditacion(''));

foreach ([0, 6, 7, 'lunes', 2.5, '-1'] as $malo) {
    chequearLanza('se rechaza ' . var_export($malo, true), function () use ($malo) {
        Saldos::validarDiaAcreditacion($malo);
    });
}

seccion('el rango del calendario');

chequear('de hoy a seis dias mas el tope del corrimiento',
    ['desde' => '2026-10-07', 'hasta' => '2026-11-12'],
    Saldos::rangoCalendarioAcreditacion('2026-10-07'));
