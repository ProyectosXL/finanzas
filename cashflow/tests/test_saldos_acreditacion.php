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

/* ================================================================
   Donde se edita: Parametros -> Saldos -> Locales
   ================================================================ */

/** El codigo fuente de un metodo, para las pruebas que miran que nombra */
function fuenteSA($clase, $metodo) {
    $r = new ReflectionMethod($clase, $metodo);

    return implode('', array_slice(file($r->getFileName()),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
}

$paramSA = [
    10 => ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CENTRO', 'GESTION' => 'DEPOSITA',
           'RESERVA' => 200000.0, 'DIA_ACREDITACION' => 1],
    40 => ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES', 'GESTION' => 'DEPOSITA',
           'RESERVA' => 150000.0, 'DIA_ACREDITACION' => null],
    55 => ['NRO_SUCURSAL' => 55, 'DESC_SUCURSAL' => 'NORTE', 'GESTION' => 'ENVIA',
           'RESERVA' => 100000.0, 'DIA_ACREDITACION' => 3]
];

$grillaSA = [
    ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => 200000, 'dia_acreditacion' => 1],
    ['nro_sucursal' => 40, 'gestion' => 'DEPOSITA', 'reserva' => 150000, 'dia_acreditacion' => null],
    ['nro_sucursal' => 55, 'gestion' => 'ENVIA', 'reserva' => 100000, 'dia_acreditacion' => 3]
];

seccion('el guardado de Parametros escribe solo lo que cambio');

$r = Saldos::resolverParametrosLocales($paramSA, $grillaSA, true);

// La grilla manda los veinte locales. Sin el diff, cada guardado les sellaba
// USUARIO_MODIF y FECHA_MODIF a todos.
chequear('reenviar la grilla tal cual no es ningun cambio', 0, count($r['cambios']));

$tocadaSA = $grillaSA;
$tocadaSA[1]['dia_acreditacion'] = '2';   // a FLORES se le carga el martes

$r = Saldos::resolverParametrosLocales($paramSA, $tocadaSA, true);

chequear('cargar un dia es un cambio, y es el unico', [40], array_column($r['cambios'], 'nro_sucursal'));
chequear('con el dia validado como entero', 2, $r['cambios'][0]['dia_acreditacion']);
chequear('y la gestion y la reserva que ya tenia', ['DEPOSITA', 150000.0],
    [$r['cambios'][0]['gestion'], $r['cambios'][0]['reserva']]);

$tocadaSA = $grillaSA;
$tocadaSA[0]['dia_acreditacion'] = '';

chequear('vaciar el dia es un cambio: es como se borra uno mal cargado', [null],
    array_column(Saldos::resolverParametrosLocales($paramSA, $tocadaSA, true)['cambios'],
        'dia_acreditacion'));

$sinClaveSA = $grillaSA;
unset($sinClaveSA[0]['dia_acreditacion']);
$sinClaveSA[0]['reserva'] = 250000;

$r = Saldos::resolverParametrosLocales($paramSA, $sinClaveSA, true);

chequear('una pantalla que no manda el dia no lo toca: conserva el que tenia', 1,
    $r['cambios'][0]['dia_acreditacion']);

chequearLanza('un dia invalido se rechaza diciendo de que local', function () use ($paramSA, $grillaSA) {
    $g = $grillaSA;
    $g[2]['dia_acreditacion'] = 6;
    Saldos::resolverParametrosLocales($paramSA, $g, true);
}, 'Local 55: El día de acreditación tiene que ser de lunes (1) a viernes (5). Se recibió "6".');

chequearLanza('un local que no esta en los parametros se rechaza', function () use ($paramSA) {
    Saldos::resolverParametrosLocales($paramSA, [['nro_sucursal' => 99, 'gestion' => 'DEPOSITA',
        'reserva' => 0]], true);
}, 'El local 99 no está en los parámetros. Usá Sincronizar con locales y volvé a guardar.');

chequearLanza('una gestion invalida, como en la pestana 2', function () use ($paramSA) {
    Saldos::resolverParametrosLocales($paramSA, [['nro_sucursal' => 10, 'gestion' => 'X',
        'reserva' => 0]], true);
});

seccion('sin el script, se ignora solo el dia');

$tocadaSA = $grillaSA;
$tocadaSA[0]['dia_acreditacion'] = 4;     // el dia no se puede guardar
$tocadaSA[1]['reserva'] = 175000;        // la reserva si

$r = Saldos::resolverParametrosLocales($paramSA, $tocadaSA, false);

chequear('la reserva se guarda igual', [40], array_column($r['cambios'], 'nro_sucursal'));
chequear('el dia no entra en el diff: no hay columna donde escribirlo', 1, count($r['cambios']));
// Sin columna no hay "dia de antes" contra el cual comparar: todo dia que
// llega se descarta y se informa, el que se toco (10) y el que vino igual (55).
chequear('y los locales que mandaron un dia vuelven, para pedir el script', [10, 55],
    $r['dia_ignorado']);

$r = Saldos::resolverParametrosLocales($paramSA, [['nro_sucursal' => 40, 'gestion' => 'DEPOSITA',
    'reserva' => 150000, 'dia_acreditacion' => null]], false);

chequear('un sin dia no es nada que ignorar', [], $r['dia_ignorado']);

chequear('el aviso de la respuesta nombra el script', true, strpos(
    file_get_contents(__DIR__ . '/../Controller/ParametrosController.php'),
    "'El día de acreditación no se guardó (locales '") !== false);

seccion('la pestana 2 no le borra el dia a nadie');

// resolverOverrides() rearmaba cada parametro con solo gestion y reserva: a
// todos los locales que manda la pantalla -que son todos- les borraba el dia,
// y la foto se guardaba sin dia.
$r = Saldos::resolverOverrides($paramSA, [
    ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => 300000]
]);

chequear('el dia queda en el parametro resultante', 1, $r['params'][10]['DIA_ACREDITACION']);
chequear('con la reserva nueva', 300000.0, $r['params'][10]['RESERVA']);
chequear('y los que no se tocaron, tambien', 3, $r['params'][55]['DIA_ACREDITACION']);

seccion('la escritura');

$guardarSA = fuenteSA('Saldos', 'guardarParametrosLocales');

chequear('valida el usuario antes que nada', 1,
    preg_match('/\{\s*\$usuario = AuthCashflow::usuarioDeEscritura\(\$usuario\);/', $guardarSA));
chequear('escribe en una transaccion', [1, 1, 1], [
    substr_count($guardarSA, 'sqlsrv_begin_transaction'),
    substr_count($guardarSA, 'sqlsrv_commit'),
    substr_count($guardarSA, 'sqlsrv_rollback')
]);
chequear('por el unico escritor del parametro', 1,
    substr_count($guardarSA, '$this->guardarSucursalEnTransaccion('));
chequear('y saveSucursal(), que abria una conexion por fila, ya no existe', false,
    method_exists('Saldos', 'saveSucursal'));

$escritorSA = fuenteSA('Saldos', 'guardarSucursalEnTransaccion');

chequear('el escritor sella la auditoria', 1, substr_count($escritorSA, 'Auditoria::SET_MODIF'));
chequear('y escribe el dia solo si se le pide, en el UPDATE y en el alta', [1, 1], [
    substr_count($escritorSA, "\$setDia = \$escribirDia ? 'DIA_ACREDITACION = ?, ' : '';"),
    substr_count($escritorSA, "(\$escribirDia ? 'DIA_ACREDITACION, ' : '')")
]);

seccion('la sincronizacion no pisa el dia');

$sincroSA = fuenteSA('Saldos', 'sincronizarSucursales');

chequear('ninguna sentencia de la sincronizacion nombra el dia', 0,
    substr_count($sincroSA, 'DIA_ACREDITACION'));
chequear('el alta no lo nombra: un local nuevo entra sin dia', 1, preg_match(
    '/INSERT INTO RO_T_CASHFLOW_SALDOS_SUCURSAL\s+\(NRO_SUCURSAL, DESC_SUCURSAL, GESTION, RESERVA, ACTIVO,/',
    $sincroSA));
chequear('y sus UPDATE tampoco tocan gestion ni reserva', 0,
    preg_match('/UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL\s+SET[^"]*\b(GESTION|RESERVA)\b/', $sincroSA));

seccion('la pantalla de Parametros');

$jsParamSA = file_get_contents(__DIR__ . '/../Js/Parametros-Saldos.js');
$tabParamSA = file_get_contents(__DIR__ . '/../Tabs/parametros_saldos.php');

chequear('la columna esta en la grilla', 1,
    substr_count($tabParamSA, '<th class="text-center" style="width: 190px;">Día de acreditación / envío</th>'));
chequear('el selector ofrece sin dia', 1, substr_count($jsParamSA, '— sin día —'));
chequear('sin el script queda apagado', 1,
    substr_count($jsParamSA, "(creada ? '' : ' disabled')"));
chequear('y un selector apagado no manda el dia', 1,
    substr_count($jsParamSA, "querySelectorAll('.sp-suc-dia:not([disabled])')"));
chequear('el aviso del dia ignorado se muestra', 1,
    substr_count($jsParamSA, 'Notificacion.advertencia(r.aviso_dia)'));
chequear('el payload dice si el script se corrio', 1, substr_count(
    file_get_contents(__DIR__ . '/../Class/Parametros.php'),
    "\$modulo['acreditacion_creada'] = \$this->saldos()->acreditacionCreada();"));

/* ================================================================
   Donde se muestra: Saldos -> Saldos Locales
   ================================================================ */

/* Cuatro locales, vistos el miercoles 7/10/2026 con el lunes 12 feriado:
     10 Deposita, lunes   -> el lunes es feriado: martes 13/10
     20 Deposita, viernes -> viernes 9/10
     30 Deposita, sin dia -> se avisa
     40 Deposita, sin dia, caja bajo la reserva: aporta cero, y se avisa igual
     55 Envia,    miercoles -> hoy, informativo; no aporta */
$consultaSA = [
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CENTRO', 'FECHA' => '2026-10-06', 'COD_CTA' => '1', 'SALDO_CIER' => 700000],
    ['NRO_SUCURSAL' => 20, 'DESC_SUCURSAL' => 'OESTE',  'FECHA' => '2026-10-06', 'COD_CTA' => '2', 'SALDO_CIER' => 500000],
    ['NRO_SUCURSAL' => 30, 'DESC_SUCURSAL' => 'SUR',    'FECHA' => '2026-10-06', 'COD_CTA' => '3', 'SALDO_CIER' => 300000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES', 'FECHA' => '2026-10-06', 'COD_CTA' => '4', 'SALDO_CIER' => 100000],
    ['NRO_SUCURSAL' => 55, 'DESC_SUCURSAL' => 'NORTE',  'FECHA' => '2026-10-06', 'COD_CTA' => '5', 'SALDO_CIER' => 900000]
];

$paramLocSA = [
    10 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 100000, 'DIA_ACREDITACION' => 1],
    20 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 100000, 'DIA_ACREDITACION' => 5],
    30 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 100000, 'DIA_ACREDITACION' => null],
    40 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 150000, 'DIA_ACREDITACION' => null],
    55 => ['GESTION' => 'ENVIA',    'RESERVA' => 100000, 'DIA_ACREDITACION' => 3]
];

$ctxSA = ['hoy' => '2026-10-07', 'habiles' => $calOct];
$armadoSA = Saldos::armarSaldosLocales($consultaSA, $paramLocSA, [], '2026-10-06', $ctxSA);
$filaSA = array_column($armadoSA['filas'], null, 'nro_sucursal');

seccion('cada fila trae su proxima fecha de acreditacion');

chequear('el lunes feriado pasa al martes', '2026-10-13', $filaSA[10]['acreditacion']['fecha']);
chequear('con la etiqueta de la celda', 'Mar · 13/10', $filaSA[10]['acreditacion']['etiqueta']);
chequear('marcada como corrida', true, $filaSA[10]['acreditacion']['corrida']);
chequear('el viernes es este viernes', '2026-10-09', $filaSA[20]['acreditacion']['fecha']);
chequear('sin dia, la fecha es null', null, $filaSA[30]['acreditacion']['fecha']);
chequear('y la celda dice sin dia', 'sin día', $filaSA[30]['acreditacion']['etiqueta']);
chequear('Envia tambien tiene su fecha: hoy es miercoles', '2026-10-07',
    $filaSA[55]['acreditacion']['fecha']);

// La gestion se puede cambiar en la pantalla antes de guardar: el tooltip
// tiene que poder seguirla sin pedirle nada al servidor.
chequear('trae el tooltip para las dos gestiones', [
    'Envía el miércoles 07/10. Es informativo: el local está en Envía y no aporta al cashflow.',
    'Acredita el miércoles 07/10.'
], [$filaSA[55]['acreditacion']['explicacion']['ENVIA'],
    $filaSA[55]['acreditacion']['explicacion']['DEPOSITA']]);

seccion('los locales en Deposita sin dia se avisan');

$avisoSinDiaSA = array_values(array_filter($armadoSA['avisos_con_nivel'], function ($a) {
    return strpos($a['texto'], 'no tienen día de acreditación cargado') !== false;
}));

chequear('un solo aviso, de atencion', [1, 'warning'],
    [count($avisoSinDiaSA), $avisoSinDiaSA ? $avisoSinDiaSA[0]['nivel'] : null]);
chequear('lista todos los de Deposita sin dia, tambien el que hoy aporta cero',
    '2 local(es) en Deposita no tienen día de acreditación cargado: 30 SUR, 40 FLORES. Lo que '
        . 'aportan se imputa hoy, en la primera columna. Cargalo en Parámetros → Saldos → Locales.',
    $avisoSinDiaSA ? $avisoSinDiaSA[0]['texto'] : null);
chequear('el de 40 aporta cero: la caja esta bajo la reserva', 0, $filaSA[40]['aporta']);
chequear('el total cuenta los sin dia', 2, $armadoSA['totales']['sin_dia']);

$envSinDiaSA = $paramLocSA;
$envSinDiaSA[55]['DIA_ACREDITACION'] = null;
$r = Saldos::armarSaldosLocales($consultaSA, $envSinDiaSA, [], '2026-10-06', $ctxSA);

chequear('un local en Envia sin dia no entra al aviso: no aporta', false,
    strpos(implode(' ', $r['avisos']), '55 NORTE') !== false);
chequear('pero se ve como sin dia en la pantalla', 'sin día',
    array_column($r['filas'], null, 'nro_sucursal')[55]['acreditacion']['etiqueta']);

seccion('el calendario que falta se avisa una vez');

$r = Saldos::armarSaldosLocales($consultaSA, $paramLocSA, [], '2026-10-28',
    ['hoy' => '2026-10-29', 'habiles' => $calOct]);

chequear('noviembre no esta en el calendario',
    ['RO_T_CALENDARIO no tiene datos para 2026-11. Se asumen hábiles los días de lunes a viernes.'],
    array_values(array_filter($r['avisos'], function ($a) {
        return strpos($a, 'RO_T_CALENDARIO') === 0;
    })));

$r = Saldos::armarSaldosLocales($consultaSA, $paramLocSA, [], '2026-10-06',
    ['hoy' => '2026-10-07', 'habiles' => $rotoSA]);
$criticosSA = array_values(array_filter($r['avisos_con_nivel'], function ($a) {
    return $a['nivel'] === 'danger';
}));

chequear('un mapa sin habiles es critico, y nombra los locales', true,
    count($criticosSA) === 1 && strpos($criticosSA[0]['texto'], '10 CENTRO, 20 OESTE, 55 NORTE') !== false);

seccion('sin el script, la pestana funciona como antes');

$r = Saldos::armarSaldosLocales($consultaSA, $paramLocSA, [], '2026-10-06', null);

chequear('las filas no traen acreditacion', [null, null],
    [$r['filas'][0]['acreditacion'], $r['filas'][4]['acreditacion']]);
chequear('y no se avisa ningun local sin dia: el aviso es el del script', false,
    strpos(implode(' ', $r['avisos']), 'día de acreditación cargado') !== false);
chequear('los importes no cambian', $armadoSA['totales']['aporta'], $r['totales']['aporta']);

$pestanaSA = fuenteSA('Saldos', 'getPestanaLocales');
$contextoSA = fuenteSA('Saldos', 'contextoAcreditacion');

chequear('la pestana arma con el contexto de acreditacion', 1,
    substr_count($pestanaSA, '$contexto = $this->contextoAcreditacion($hoy);'));
chequear('y dice si el script se corrio', 1,
    substr_count($pestanaSA, "'acreditacion_creada' => \$contexto['acreditacion'] !== null"));
chequear('el contexto pide el calendario por CronogramaDatos::habilesEntre()', 1,
    substr_count($contextoSA, '$crono->habilesEntre($rango[\'desde\'], $rango[\'hasta\'])'));
chequear('y sin el script dice cual correr', 1,
    substr_count($contextoSA, 'corré sql/cashflow_saldos_dia_acreditacion.sql'));

seccion('la pantalla de Saldos Locales');

$jsSalSA = file_get_contents(__DIR__ . '/../Js/Saldos.js');
$tabSalSA = file_get_contents(__DIR__ . '/../Tabs/saldos.php');

chequear('la columna esta en la tabla', 1, substr_count($tabSalSA, '>Acreditación / envío</th>'));
chequear('es de solo lectura: no hay selector del dia', 0, substr_count($jsSalSA, 'sal-dia'));
chequear('sin dia se resalta', 1, substr_count($jsSalSA, "(a.dia === null ? 'sal-sin-dia' : 'sal-fecha-acred')"));
chequear('el tooltip sigue a la gestion de la pantalla', 1,
    substr_count($jsSalSA, "f.acreditacion.explicacion[deposita ? 'DEPOSITA' : 'ENVIA']"));
chequear('las filas vacias ocupan las ocho columnas', 1,
    substr_count($jsSalSA, "'<tr><td colspan=\"8\" class=\"text-center text-muted py-4\">' +\n"
        . "                   'La consulta no devolvió ningún local.</td></tr>'")
    + substr_count($jsSalSA, "'<tr><td colspan=\"8\" class=\"text-center text-muted py-4\">' +\r\n"
        . "                   'La consulta no devolvió ningún local.</td></tr>'"));

/* ================================================================
   El tablero: Caja Locales se imputa en la fecha de acreditacion
   ================================================================ */

// El eje arranca el miercoles 7/10/2026: 28 dias y 12 meses.
$hSA = new Horizonte(28, 12, [], new DateTime('2026-10-07'));

$serieSA = Saldos::armarSerieLocales($armadoSA['filas'], $hSA);
$sinDiaSerieSA = Saldos::armarSerieLocales(
    Saldos::armarSaldosLocales($consultaSA, $paramLocSA, [], '2026-10-06', null)['filas'], $hSA);

seccion('cada local va a su fecha de acreditacion');

// Aportes: 10 CENTRO 600.000 (lunes feriado -> martes 13), 20 OESTE 400.000
// (viernes 9), 30 SUR 200.000 (sin dia -> hoy), 40 FLORES 0 (bajo la
// reserva), 55 NORTE en Envia, que no aporta.
chequear('dos locales con dias distintos caen en dos columnas', [400000.0, 600000.0],
    [$serieSA['dias']['2026-10-09'], $serieSA['dias']['2026-10-13']]);
chequear('el lunes feriado no tiene nada: la plata entra el martes', 0.0,
    floatval($serieSA['dias']['2026-10-12']));
chequear('el local sin dia cae en la primera columna', 200000.0, $serieSA['dias']['2026-10-07']);
chequear('el de Envia no aporta: con dia hoy, la primera columna tiene solo al sin dia',
    200000.0, $serieSA['dias']['2026-10-07']);
chequear('y nada mas en el resto del eje', 1200000.0,
    array_sum($serieSA['dias']) + array_sum($serieSA['meses']));

seccion('el total no cambia, solo la distribucion');

chequear('el mismo total que sin dia de acreditacion',
    array_sum($sinDiaSerieSA['dias']) + array_sum($sinDiaSerieSA['meses']),
    array_sum($serieSA['dias']) + array_sum($serieSA['meses']));
chequear('que antes iba entero a la primera columna', 1200000.0,
    $sinDiaSerieSA['dias']['2026-10-07']);
chequear('y que es el aporte de la pestana', floatval($armadoSA['totales']['aporta']),
    floatval(array_sum($serieSA['dias']) + array_sum($serieSA['meses'])));
chequear('ni fuera del horizonte ni sin fecha', [0.0, 0.0],
    [floatval($serieSA['fuera_horizonte']), floatval($serieSA['sin_fecha'])]);

seccion('lo que no cambia');

// Un saldo sin fecha va a sin_fecha aunque el local tenga dia: imputarlo
// ahora cambiaria el total, que esta regla no toca.
$sinFechaSA = [['nro_sucursal' => 10, 'fecha_saldo' => null, 'aporta' => 50000,
    'acreditacion' => ['fecha' => '2026-10-13']]];

chequear('un saldo sin fecha va a sin_fecha aunque tenga dia', 50000.0,
    floatval(Saldos::armarSerieLocales($sinFechaSA, $hSA)['sin_fecha']));

// El aviso de "saldo de fecha vieja en la primera columna" habla solo de lo
// que fue a la primera columna por falta de dia.
chequear('el aviso de la primera columna cuenta solo al local sin dia', true,
    strpos(implode(' ', Aviso::textos($serieSA['warnings'])), '$ 200.000,00') === 0);

seccion('una fecha de acreditacion fuera del eje');

// Un eje solo mensual de un mes, visto el 29/10: el lunes es el 2/11, que el
// eje no cubre.
$hCortoSA = new Horizonte(0, 1, [], new DateTime('2026-10-29'));
$fueraSA = [['nro_sucursal' => 10, 'fecha_saldo' => '2026-10-28', 'aporta' => 300000,
    'acreditacion' => ['fecha' => Saldos::proximaFechaAcreditacion(1, '2026-10-29', $calOct)['fecha']]]];
$serieFueraSA = Saldos::armarSerieLocales($fueraSA, $hCortoSA);

chequear('queda fuera de horizonte, y el motor lo informa', 300000.0,
    floatval($serieFueraSA['fuera_horizonte']));
chequear('no se apila en la primera columna', 0.0, floatval(array_sum($serieFueraSA['meses'])));

seccion('el tablero usa la misma funcion que la pestana');

$provSA = file_get_contents(__DIR__ . '/../Class/Providers/SaldosProvider.php');

chequear('pide el contexto con el hoy del eje', 1,
    substr_count($provSA, '$contexto = $saldos->contextoAcreditacion($h->hoy());'));
chequear('arma los locales con el', 1, preg_match(
    '/Saldos::armarSaldosLocales\(\$consulta, \$params, \$manuales,\s+Saldos::ayer\(\$h->hoy\(\)\), \$acreditacion\);/',
    $provSA));
chequear('sube los avisos del contexto a Caja Locales', 1, substr_count($provSA,
    "\$this->avisarTodos(\$contexto['avisos'], Aviso::WARNING, self::SECCION_LOCALES);"));
chequear('y si el contexto falla, no tumba la serie', 1,
    substr_count($provSA, 'No se pudo calcular la fecha de acreditación de los locales ('));

/* ================================================================
   El historico: la foto guarda el dia y la fecha efectivos
   ================================================================ */
seccion('la foto del dia');

$cargaSA = fuenteSA('Saldos', 'guardarCargaLocales');

chequear('arma con el mismo contexto que la pestana y el tablero', 1,
    substr_count($cargaSA, "\$acreditacion = \$this->contextoAcreditacion(\$hoy)['acreditacion'];"));
chequear('y lo pasa a las dos armadas: la de los manuales y la de la foto', 2,
    substr_count($cargaSA, '$ayer, $acreditacion);'));
chequear('las columnas del dia van solo con el script', 1, preg_match(
    "/if \(\\\$conDia\) \{\s+\\\$columnas\[\] = 'DIA_ACREDITACION';\s+\\\$columnas\[\] = 'FECHA_ACREDITACION';/",
    $cargaSA));
chequear('columnas y valores se arman juntos: un ? por columna', 1, substr_count($cargaSA,
    "implode(', ', array_fill(0, count(\$columnas), '?'))"));
chequear('el valor es el dia efectivo y la fecha calculada de la fila', [1, 1], [
    substr_count($cargaSA, "\$valores[] = isset(\$f['acreditacion']['dia']) ? \$f['acreditacion']['dia'] : null;"),
    substr_count($cargaSA, "\$valores[] = isset(\$f['acreditacion']['fecha']) ? \$f['acreditacion']['fecha'] : null;")
]);

/* ================================================================
   Contra la base
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$saldosSA = new Saldos();
$creadaSA = $saldosSA->acreditacionCreada();

chequear('acreditacionCreada() responde', true, is_bool($creadaSA));

$paramBaseSA = $saldosSA->getParametrosSucursales(false);

chequear('el parametro trae siempre la clave del dia', 0,
    count(array_filter($paramBaseSA, function ($p) { return !array_key_exists('DIA_ACREDITACION', $p); })));

if (!$creadaSA) {
    chequear('sin el script, el dia es null para todos', 0,
        count(array_filter($paramBaseSA, function ($p) { return $p['DIA_ACREDITACION'] !== null; })));
}

$ctxBaseSA = $saldosSA->contextoAcreditacion(date('Y-m-d'));

chequear('el contexto es null exactamente cuando falta el script', !$creadaSA,
    $ctxBaseSA['acreditacion'] === null);

