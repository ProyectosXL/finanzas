<?php
/**
 * Logistica Local: fleteros no habituales con pagos puntuales, y el importe
 * editado del proximo pago de un habitual.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. CON TODOS HABITUALES, LA PROYECCION CAMBIA. La marca nueva tiene que ser
 *      invisible para los fleteros de siempre: mismos pagos, mismas fechas,
 *      mismos importes que antes de que existiera.
 *
 *   2. UN NO HABITUAL SIGUE PROYECTANDO POR CALCULO, o un habitual proyecta sus
 *      puntuales encima del calculo: el mismo fletero se pagaria dos veces.
 *
 *   3. UN PAGO PUNTUAL DE HOY O DE AYER SE ACEPTA. Esa plata ya esta en el
 *      saldo bancario; cargada, la planilla no la proyectaria nunca y nadie
 *      sabria por que.
 *
 *   4. LA EDICION DEL PROXIMO PAGO SE CORRE A LOS SIGUIENTES. Vale solo para
 *      ese pago: los demas siguen con la cuenta.
 *
 * No toca la base.
 */

require_once __DIR__ . '/../Class/LogisticaPlanilla.php';
require_once __DIR__ . '/../Class/Logistica.php';
require_once __DIR__ . '/../Class/CronogramaPagos.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Inflacion.php';

$hNH = new Horizonte(28, 12, [], new DateTime('2026-09-25'));
$habNH = [];
$cNH = new DateTime('2026-08-01');

while ($cNH <= new DateTime('2027-10-31')) {
    $habNH[$cNH->format('Y-m-d')] = intval($cNH->format('N')) <= 5;
    $cNH->modify('+1 day');
}

$cronoNH = CronogramaPagos::paraHorizonte($hNH, CronogramaPagos::configDesdeMapa([], 'LOGISTICA'),
    $habNH)['pagos'];
$infNH = [];

for ($i = 0, $m = '2026-06'; $i < 20; $i++, $m = Inflacion::mesMas($m, 1)) {
    $infNH[$m] = 2.0;
}

$habitual = ['COD_PROVEE' => 'F1', 'NOMBRE' => 'UNO', 'HORAS_MES' => 100,
             'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09'];
$noHabitual = ['COD_PROVEE' => 'F9', 'NOMBRE' => 'NUEVE', 'HORAS_MES' => 50,
               'VALOR_HORA_BASE' => 2000, 'MES_BASE' => '2026-09', 'HABITUAL' => false];

$puntualesNH = [
    ['ID' => 1, 'COD_PROVEE' => 'F9', 'FECHA' => '2026-09-20', 'IMPORTE' => 11111],   // ya paso
    ['ID' => 2, 'COD_PROVEE' => 'F9', 'FECHA' => '2026-09-25', 'IMPORTE' => 22222],   // hoy
    ['ID' => 3, 'COD_PROVEE' => 'F9', 'FECHA' => '2026-10-07', 'IMPORTE' => 30000],   // tramo
    ['ID' => 4, 'COD_PROVEE' => 'F9', 'FECHA' => '2026-12-15', 'IMPORTE' => 45000.5], // mes
    ['ID' => 5, 'COD_PROVEE' => 'F1', 'FECHA' => '2026-10-20', 'IMPORTE' => 99999]    // de un habitual
];

/* ================================================================ */
seccion('con todos habituales, Logistica proyecta igual que antes');

$sinMarca = LogisticaPlanilla::calcular([$habitual], $hNH, $cronoNH, $infNH);
$conMarca = LogisticaPlanilla::calcular([array_merge($habitual, ['HABITUAL' => true])], $hNH,
    $cronoNH, $infNH, [], []);

$foto = function ($plan) {
    return array_map(function ($p) {
        return [$p['cod_provee'], $p['mes'], $p['nro'], $p['fecha'], round($p['importe'], 6),
                $p['excluido']];
    }, $plan['pagos']);
};

chequear('la marca HABITUAL no cambia ningun pago', json_encode($foto($sinMarca)),
    json_encode($foto($conMarca)));
chequear('ni el total', $sinMarca['totales']['total'], $conMarca['totales']['total']);
chequear('un fletero sin la marca es habitual', true, LogisticaPlanilla::esHabitual($habitual));
chequear('HABITUAL = 0 no lo es', false, LogisticaPlanilla::esHabitual(['HABITUAL' => 0]));

/* ================================================================ */
seccion('un no habitual proyecta solo sus pagos puntuales');

$planNH = LogisticaPlanilla::calcular([$habitual, $noHabitual], $hNH, $cronoNH, $infNH,
    $puntualesNH, []);
$f9 = $planNH['fleteros'][1];

chequear('queda marcado como no habitual', false, $f9['habitual']);
chequear('conserva sus horas y su valor hora, aunque no los use', [50.0, 2000.0, '2026-09'],
    [$f9['horas_mes'], $f9['valor_hora_base'], $f9['mes_base']]);
chequear('no avisa que le faltan datos', [], $f9['avisos']);

$aProyectar = array_values(array_filter(LogisticaPlanilla::pagosAProyectar($planNH),
    function ($p) { return $p['cod_provee'] === 'F9'; }));

chequear('proyecta los dos pagos futuros, en su fecha', [['2026-10-07', 30000.0], ['2026-12-15', 45000.5]],
    array_map(function ($p) { return [$p['fecha'], $p['importe']]; }, $aProyectar));
chequear('ningun pago calculado', [], array_values(array_filter($f9['pagos'],
    function ($p) { return empty($p['puntual']); })));
chequear('su total es la suma de los puntuales futuros', 75000.5, $f9['total']);

$porId = [];
foreach ($f9['pagos'] as $p) { $porId[$p['id']] = $p; }

chequear('el de ayer queda en el historial, sin proyectar', [true, 'Ya pasó: ese pago ya se hizo.'],
    [$porId[1]['excluido'], $porId[1]['motivo_excluido']]);
chequear('el de hoy tambien', [true, 'Es hoy: ese pago ya se hizo.'],
    [$porId[2]['excluido'], $porId[2]['motivo_excluido']]);

chequear('octubre lleva el pago puntual y no tiene motivo', [30000.0, null],
    [$f9['meses']['2026-10']['importe_proy'], $f9['meses']['2026-10']['motivo']]);
chequear('noviembre, sin pagos, dice por que esta vacio', 'NO_HABITUAL',
    $f9['meses']['2026-11']['motivo']);

$serieNH = $hNH->serieVacia();
foreach (LogisticaPlanilla::pagosAProyectar($planNH) as $p) {
    if ($p['cod_provee'] === 'F9') { $hNH->acumular($serieNH, $p['fecha'], $p['importe']); }
}
chequear('en el eje: el del 07/10 en su dia, el de diciembre en su mes', [30000.0, 45000.5],
    [$serieNH['dias']['2026-10-07'], $serieNH['meses']['2026-12']]);

seccion('los pagos puntuales de un habitual se ven pero no proyectan');

$f1 = $planNH['fleteros'][0];
chequear('el habitual sigue proyectando su calculo', $sinMarca['totales']['total'], $f1['total']);
chequear('su puntual va en la lista, marcado', [true, 'El fletero es habitual: sus pagos puntuales no se proyectan.'],
    [$f1['puntuales'][0]['excluido'], $f1['puntuales'][0]['motivo_excluido']]);
chequear('y no entra en los pagos a proyectar', 0, count(array_filter(
    LogisticaPlanilla::pagosAProyectar($planNH),
    function ($p) { return !empty($p['puntual']) && $p['cod_provee'] === 'F1'; })));

seccion('volver a habitual: cuantos pagos dejan de proyectar y por cuanto');

chequear('cuenta solo los futuros', ['cantidad' => 2, 'total' => 75000.5],
    LogisticaPlanilla::puntualesFuturos(array_slice($puntualesNH, 0, 4), '2026-09-25'));
chequear('sin puntuales, cero y cero', ['cantidad' => 0, 'total' => 0.0],
    LogisticaPlanilla::puntualesFuturos([], '2026-09-25'));

/* ================================================================ */
seccion('el pago puntual se valida en el servidor: de manana en adelante');

chequear('manana se acepta', ['fecha' => '2026-09-26', 'importe' => 1500.25],
    Logistica::validarPagoPuntual('2026-09-26', '1500.25', '2026-09-25'));
chequearLanza('hoy se rechaza, y el mensaje dice por que', function () {
    Logistica::validarPagoPuntual('2026-09-25', 100, '2026-09-25');
}, 'La fecha de un pago puntual tiene que ser de mañana en adelante. Lo que se paga hoy, o se '
    . 'pagó antes, ya está reflejado en el saldo bancario que abre el tablero: proyectarlo sería '
    . 'pedir dos veces la misma plata.');
chequearLanza('ayer se rechaza', function () {
    Logistica::validarPagoPuntual('2026-09-24', 100, '2026-09-25');
});
chequearLanza('una fecha que no existe se rechaza', function () {
    Logistica::validarPagoPuntual('2026-02-30', 100, '2026-01-01');
});
chequearLanza('importe cero se rechaza', function () {
    Logistica::validarPagoPuntual('2026-10-01', 0, '2026-09-25');
});
chequearLanza('importe negativo se rechaza', function () {
    Logistica::validarPagoPuntual('2026-10-01', -5, '2026-09-25');
});

/* ================================================================ */
seccion('el importe del proximo pago: solo ese pago');

$proxHab = LogisticaPlanilla::proximoPago($sinMarca['fleteros'][0]);
chequear('el proximo pago es el 2do viernes de octubre (el de hoy ya se hizo)',
    ['2026-10', 1, '2026-10-09'], [$proxHab['mes'], $proxHab['nro'], $proxHab['fecha']]);
chequear('la fila lo dice', ['mes' => '2026-10', 'nro' => 1, 'fecha' => '2026-10-09'],
    $sinMarca['fleteros'][0]['proximo']);

$edicion = [['COD_PROVEE' => 'F1', 'MES' => '2026-10', 'NRO_PAGO' => 1, 'IMPORTE' => 61234.56,
             'IMPORTE_CALCULADO' => 50000, 'FECHA_PAGO' => '2026-10-09',
             'USUARIO_ALTA' => 'RAMIRO', 'FECHA_ALTA' => '2026-09-25 10:00:00']];
$planEd = LogisticaPlanilla::calcular([$habitual], $hNH, $cronoNH, $infNH, [], $edicion);
$pagosEd = [];
foreach ($planEd['fleteros'][0]['pagos'] as $p) { $pagosEd[$p['mes'] . '|' . $p['nro']] = $p; }

chequear('el pago editado lleva el importe real', 61234.56, $pagosEd['2026-10|1']['importe']);
chequear('y conserva el calculado para el tooltip', [true, 50000.0],
    [$pagosEd['2026-10|1']['editado'], $pagosEd['2026-10|1']['importe_calculado']]);
chequear('con quien lo cargo', 'RAMIRO', $pagosEd['2026-10|1']['edicion']['usuario']);
chequear('el otro pago de octubre sigue con el calculo', [50000.0, false],
    [$pagosEd['2026-10|2']['importe'], $pagosEd['2026-10|2']['editado']]);
chequear('y los meses siguientes tambien', 50000.0, $pagosEd['2026-11|1']['importe']);

$sinEdTotal = $sinMarca['totales']['total'];
chequear('el total cambia exactamente la diferencia', $sinEdTotal + 11234.56,
    $planEd['totales']['total']);

$deshecho = LogisticaPlanilla::calcular([$habitual], $hNH, $cronoNH, $infNH, [], []);
chequear('deshacer (sin edicion vigente) vuelve al calculo', json_encode($foto($sinMarca)),
    json_encode($foto($deshecho)));

// Cuando la fecha pasa, la edicion queda solo como dato: el pago no proyecta.
$hDespues = new Horizonte(28, 12, [], new DateTime('2026-10-10'));
$cronoDesp = CronogramaPagos::paraHorizonte($hDespues, CronogramaPagos::configDesdeMapa([], 'LOGISTICA'),
    $habNH)['pagos'];
$planDesp = LogisticaPlanilla::calcular([$habitual], $hDespues, $cronoDesp, $infNH, [], $edicion);
$pDesp = null;
foreach ($planDesp['fleteros'][0]['pagos'] as $p) {
    if ($p['mes'] === '2026-10' && $p['nro'] === 1) { $pDesp = $p; }
}
chequear('pasada la fecha, el pago editado queda en el historial, sin proyectar', [true, true],
    [$pDesp['editado'], $pDesp['excluido']]);
chequear('y el proximo pasa a ser el siguiente', '2026-10-23',
    $planDesp['fleteros'][0]['proximo']['fecha']);

seccion('solo se edita el proximo: lo verifica el servidor');

chequear('el proximo se acepta', '2026-10-09',
    Logistica::exigirProximo($sinMarca, 'F1', '2026-10', 1)['fecha']);
chequearLanza('el siguiente no', function () use ($sinMarca) {
    Logistica::exigirProximo($sinMarca, 'F1', '2026-10', 2);
});
chequearLanza('un no habitual no tiene proximo calculado', function () use ($planNH) {
    Logistica::exigirProximo($planNH, 'F9', '2026-10', 1);
});
chequearLanza('un fletero que no esta en la planilla tampoco', function () use ($sinMarca) {
    Logistica::exigirProximo($sinMarca, 'XX', '2026-10', 1);
});

seccion('el cableado de las escrituras');

$fuenteLog = file_get_contents(__DIR__ . '/../Class/Logistica.php');
chequear('la edicion da de baja la anterior y guarda la nueva en una transaccion', true,
    strpos($fuenteLog, 'sqlsrv_begin_transaction($cid)') !== false
    && strpos($fuenteLog, '$this->bajaEdicion($cid, $cod, $prox[\'mes\'], $prox[\'nro\'], $usuario);') !== false);
chequear('deshacer y dar de baja no borran: VIGENTE = 0 con auditoria', true,
    substr_count($fuenteLog, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA') === 2
    && strpos($fuenteLog, 'DELETE') === false);
chequear('un pago puntual solo para un no habitual', true,
    strpos($fuenteLog, "if (\$fletero['HABITUAL']) {") !== false);

$fuenteCD3 = file_get_contents(__DIR__ . '/../Class/CronogramaDatos.php');
chequear('cambiar el cronograma de Logistica da de baja los importes editados, en la transaccion',
    true, strpos($fuenteCD3, "(\$concepto === 'LOGISTICA')\r\n                ? \$this->bajaImportesLogistica(\$cid,") !== false
    || strpos($fuenteCD3, "(\$concepto === 'LOGISTICA')\n                ? \$this->bajaImportesLogistica(\$cid,") !== false);

require_once __DIR__ . '/../Class/AuthCashflow.php';
chequear('las cuatro acciones nuevas piden editar Logistica Local', [true, true, true, true],
    array_map(function ($a) {
        return AuthCashflow::ESCRITURAS['Logistica'][$a] === [['logistica_local', null]];
    }, ['savePagoPuntual', 'bajaPagoPuntual', 'saveImporteProximo', 'deshacerImporteProximo']));

$sqlNH = file_get_contents(__DIR__ . '/../sql/cashflow_logistica_no_habituales.sql');
chequear('el script deja a todos como habituales', true,
    strpos($sqlNH, 'ADD HABITUAL BIT NOT NULL') !== false
    && strpos($sqlNH, 'DEFAULT (1) WITH VALUES') !== false);
chequear('las dos tablas con las seis columnas de auditoria', 2,
    min(substr_count($sqlNH, 'USUARIO_ALTA '), substr_count($sqlNH, 'USUARIO_BAJA ')));
