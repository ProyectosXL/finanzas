<?php
/**
 * Saldos > Saldos: el saldo de cada cuenta se edita desde su fila, y el
 * manual de hoy manda todo el dia.
 *
 * La regla es una sola (SaldoManual::resolver()) y la usan Interbanking, las
 * cuentas por consulta y las cuentas sin fuente automatica. Todo sin base, con
 * hoy inyectado.
 */

require_once __DIR__ . '/../Class/SaldoManual.php';

const ED_HOY = '2026-10-30';
const ED_AYER = '2026-10-29';
const ED_ANTEAYER = '2026-10-28';

// ============================================================================
seccion('la regla: el manual de hoy manda todo el dia');
// ============================================================================

chequear('manual de hoy contra un automatico de hoy (aunque sea mas nuevo): manda el manual',
    SaldoManual::MANUAL, SaldoManual::resolver(ED_HOY, ED_HOY, ED_HOY));
chequear('manual de hoy contra un automatico viejo: manda el manual',
    SaldoManual::MANUAL, SaldoManual::resolver(ED_AYER, ED_HOY, ED_HOY));
chequear('manual de hoy sin automatico: manda el manual',
    SaldoManual::MANUAL, SaldoManual::resolver(null, ED_HOY, ED_HOY));
chequear('manual de ayer contra un automatico de hoy: manda el automatico',
    SaldoManual::AUTOMATICO, SaldoManual::resolver(ED_HOY, ED_AYER, ED_HOY));
chequear('manual de ayer sin automatico: el manual, como respaldo',
    SaldoManual::MANUAL, SaldoManual::resolver(null, ED_AYER, ED_HOY));
chequear('manual de ayer contra un automatico de anteayer: gana la fecha mas nueva',
    SaldoManual::MANUAL, SaldoManual::resolver(ED_ANTEAYER, ED_AYER, ED_HOY));
chequear('manual de ayer contra un automatico de ayer: a igual fecha (que no es hoy), el automatico',
    SaldoManual::AUTOMATICO, SaldoManual::resolver(ED_AYER, ED_AYER, ED_HOY));
chequear('sin manual, el automatico', SaldoManual::AUTOMATICO, SaldoManual::resolver(ED_HOY, null, ED_HOY));
chequear('sin ninguno de los dos, nada', null, SaldoManual::resolver(null, null, ED_HOY));
chequear('una fecha con hora se compara por dia',
    SaldoManual::MANUAL, SaldoManual::resolver(ED_HOY . ' 15:00:00', ED_HOY . ' 09:00:00', ED_HOY));
chequear('una fecha vacia es "sin dato"', SaldoManual::AUTOMATICO, SaldoManual::resolver(ED_AYER, '', ED_HOY));

chequear('es de hoy', true, SaldoManual::esDeHoy(ED_HOY . ' 10:00:00', ED_HOY));
chequear('no es de hoy', false, SaldoManual::esDeHoy(ED_AYER, ED_HOY));
chequear('sin fecha no es de hoy', false, SaldoManual::esDeHoy(null, ED_HOY));

// ============================================================================
seccion('la validacion de lo que se carga');
// ============================================================================

$v = SaldoManual::validar(['saldo' => '1234.567', 'observacion' => '  cheques por acreditar  ']);
chequear('el importe se redondea a centavos', 1234.57, $v['saldo']);
chequear('la observacion se recorta', 'cheques por acreditar', $v['observacion']);
chequear('un negativo se acepta (descubierto)', -50.0, SaldoManual::validar(['saldo' => '-50'])['saldo']);
chequear('un cero es un dato', 0.0, SaldoManual::validar(['saldo' => '0'])['saldo']);
chequear('sin observacion va null', null, SaldoManual::validar(['saldo' => '1', 'observacion' => ' '])['observacion']);
chequear('la fecha que mande el navegador no se toma: no esta en lo validado', false,
    array_key_exists('fecha_saldo', SaldoManual::validar(['saldo' => '1', 'fecha_saldo' => '2020-01-01'])));

chequearLanza('un importe vacio se rechaza', function () {
    SaldoManual::validar(['saldo' => '']);
});
chequearLanza('uno que no es numero tambien', function () {
    SaldoManual::validar(['saldo' => 'mucho']);
});
chequearLanza('sin importe tambien', function () {
    SaldoManual::validar([]);
});
chequearLanza('una observacion de mas de 500 caracteres tambien', function () {
    SaldoManual::validar(['saldo' => '1', 'observacion' => str_repeat('x', 501)]);
});

require_once __DIR__ . '/../Class/Saldos.php';
require_once __DIR__ . '/../Class/SaldosInterbanking.php';

/** Una fila del catalogo como la arma getSaldosActuales(), con su ultimo saldo guardado */
function edFila($id, $tipo, $nombre, $origenCuenta, $saldo = null, $fecha = null, $origenDato = 'MANUAL',
                $cargaPropia = true) {
    $cargada = ($fecha !== null);

    return ['id_cuenta' => $id, 'origen' => Saldos::ORIGEN_CARGA, 'tipo' => $tipo, 'nombre' => $nombre,
            'moneda' => 'ARS', 'origen_cuenta' => $origenCuenta, 'cargada' => $cargada,
            'saldo' => $cargada ? $saldo : 0, 'fecha_saldo' => $fecha, 'origen_dato' => $origenDato,
            'usuario_carga' => 'tesoreria',
            'manual' => ($cargada && $origenDato === 'MANUAL') ? [
                'id' => 50 + $id, 'fecha_saldo' => $fecha, 'saldo' => $saldo,
                'observacion' => 'cheques por acreditar', 'usuario' => 'tesoreria',
                'fecha_alta' => $fecha . ' 09:00:00', 'carga_propia' => $cargaPropia] : null,
            'automatico' => null];
}

/** Lo que devuelve leerConsulta() para Mercado Pago */
function edMp($saldo, $fecha) {
    return ['saldo' => $saldo, 'fecha' => $fecha, 'fuente' => Saldos::TABLA_MP, 'momento' => $fecha . ' 11:00:00'];
}

function edTextos($avisos) {
    return implode(' | ', array_map(function ($a) { return is_array($a) ? $a['texto'] : $a; }, $avisos));
}

// ============================================================================
seccion('Mercado Pago: el manual de hoy manda sobre la consulta');
// ============================================================================

$mpManualHoy = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_HOY)],
    [3 => edMp(2091049.45, ED_HOY)], ED_HOY);
$f = $mpManualHoy['filas'][0];
chequear('con un manual de hoy manda el manual, aunque la consulta traiga otro de hoy', 500.0, $f['saldo']);
chequear('no se muestra como en vivo', false, $f['en_vivo']);
chequear('lo que trae la consulta viaja igual, para el tooltip', 2091049.45, $f['automatico']['saldo']);
chequear('el manual es de hoy y manda', [true, true], [$f['manual']['de_hoy'], $f['manual']['manda']]);
chequear('sin avisos', [], $mpManualHoy['avisos']);
chequear('sigue siendo una fila por consulta: no pide "actualizá"', Saldos::ORIGEN_CONSULTA, $f['origen']);

$mpManualAyer = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_AYER)],
    [3 => edMp(2091049.45, ED_HOY)], ED_HOY);
chequear('con un manual de ayer vuelve a mandar la consulta', 2091049.45, $mpManualAyer['filas'][0]['saldo']);
chequear('en vivo', true, $mpManualAyer['filas'][0]['en_vivo']);
chequear('y el manual queda en la fila sin mandar', false, $mpManualAyer['filas'][0]['manual']['manda']);

$mpFalla = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_AYER)],
    [3 => ['error' => 'la base no responde']], ED_HOY);
chequear('si la lectura falla, se usa el ultimo manual', 500.0, $mpFalla['filas'][0]['saldo']);
chequear('marcado "no es de hoy"', true, $mpFalla['filas'][0]['no_es_de_hoy']);
chequear('con aviso de atencion', 'warning', $mpFalla['avisos'][0]['nivel']);

$mpFallaHoy = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_HOY)],
    [3 => ['error' => 'la base no responde']], ED_HOY);
chequear('si falla con un manual de hoy, el manual y sin aviso: manda igual',
    [500.0, 0], [$mpFallaHoy['filas'][0]['saldo'], count($mpFallaHoy['avisos'])]);

// La regla unica: con el proceso de Mercado Pago atrasado, un manual mas
// nuevo que el ultimo registro gana, como en Interbanking.
$mpAtrasado = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_AYER)],
    [3 => edMp(100.0, ED_ANTEAYER)], ED_HOY);
chequear('con la consulta atrasada, el manual mas nuevo manda', 500.0, $mpAtrasado['filas'][0]['saldo']);
chequear('y se avisa en atencion, con las dos fechas', true,
    strpos(edTextos($mpAtrasado['avisos']), 'se usa el saldo manual del 29/10/2026, más nuevo que el último de '
        . Saldos::TABLA_MP . ' (28/10/2026)') !== false);

// Una foto vieja de "Nueva carga" no es un manual: no le gana a la consulta
$mpFoto = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA', 500.0, ED_HOY, 'CONSULTA')],
    [3 => edMp(2091049.45, ED_HOY)], ED_HOY);
chequear('una foto de hoy de las cargas viejas no es un manual: manda la consulta',
    2091049.45, $mpFoto['filas'][0]['saldo']);

// Quitado el manual de hoy, la fila llega sin manual (o con el de un dia anterior)
$mpQuitado = Saldos::aplicarConsultas([edFila(3, 'MERCADO_PAGO', 'Mercado Pago', 'CONSULTA')],
    [3 => edMp(2091049.45, ED_HOY)], ED_HOY);
chequear('quitado el manual de hoy, vuelve a mandar la consulta', 2091049.45, $mpQuitado['filas'][0]['saldo']);

// ============================================================================
seccion('cuentas sin fuente automatica: el ultimo manual');
// ============================================================================

$sinFuente = Saldos::aplicarManuales([
    edFila(7, 'BANCO', 'BTG Uy', 'MANUAL', 71000.0, ED_HOY),
    edFila(8, 'OTRO', 'Otra', 'MANUAL', 10.0, ED_AYER),
    edFila(9, 'OTRO', 'Nunca', 'MANUAL')
], ED_HOY);
chequear('el manual de hoy manda y es de hoy', [71000.0, true, true, false],
    [$sinFuente[0]['saldo'], $sinFuente[0]['manual']['de_hoy'], $sinFuente[0]['manual']['manda'],
     $sinFuente[0]['no_es_de_hoy']]);
chequear('el de ayer sigue mandando, marcado "no es de hoy"', [10.0, true, true],
    [$sinFuente[1]['saldo'], $sinFuente[1]['manual']['manda'], $sinFuente[1]['no_es_de_hoy']]);
chequear('una que nunca se cargo sigue sin cargar y sin marca', [false, false],
    [$sinFuente[2]['cargada'], $sinFuente[2]['no_es_de_hoy']]);

$conConsulta = Saldos::aplicarManuales($mpManualHoy['filas'], ED_HOY);
chequear('aplicarManuales() no toca las filas por consulta', $mpManualHoy['filas'], $conConsulta);

// Dos manuales hoy, o un manual quitado: lo resuelve la lectura (la ultima
// carga vigente de la cuenta). Lo que llega aca es la que quedo.
$dosHoy = Saldos::aplicarManuales([edFila(8, 'OTRO', 'Otra', 'MANUAL', 30.0, ED_HOY)], ED_HOY);
chequear('dos ediciones hoy: la lectura trae la ultima, y manda', 30.0, $dosHoy[0]['saldo']);

$srcSaldos = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Saldos.php'));
preg_match('/function getSaldosActuales\(\).*?\n    }\n/s', $srcSaldos, $m);
$lectura = isset($m[0]) ? $m[0] : '';
chequear('la lectura toma la ultima de cada cuenta, entre las cargas vigentes', true,
    strpos($lectura, 'C.ACTIVO = 1') !== false
    && strpos($lectura, 'ORDER BY D.FECHA_SALDO DESC, C.FECHA_CARGA DESC, D.ID DESC') !== false);

// ============================================================================
seccion('que se edita y que se quita');
// ============================================================================

$filaIbManual = ['origen_cuenta' => 'INTERBANKING', 'tipo' => 'BANCO', 'nombre' => 'GALICIA · 100',
    'manual' => ['id' => 4, 'fecha_saldo' => ED_ANTEAYER, 'de_hoy' => false, 'manda' => false]];
$efectivo = edFila(1, 'EFECTIVO_CENTRAL', 'Efectivo Tesorería Casa Central', 'CONSULTA', 1.0, ED_HOY, 'CONSULTA');
$otraHoy = Saldos::aplicarManuales([edFila(8, 'OTRO', 'Otra', 'MANUAL', 30.0, ED_HOY)], ED_HOY)[0];
$otraAyer = Saldos::aplicarManuales([edFila(8, 'OTRO', 'Otra', 'MANUAL', 30.0, ED_AYER)], ED_HOY)[0];
$otraMixta = Saldos::aplicarManuales([edFila(8, 'OTRO', 'Otra', 'MANUAL', 30.0, ED_HOY, 'MANUAL', false)], ED_HOY)[0];

$e = Saldos::marcarEditables([$efectivo, $filaIbManual, $otraHoy, $otraAyer, $otraMixta], true, true);
chequear('el efectivo de tesoreria no se edita', false, $e[0]['editable']);
chequear('Interbanking y las del catalogo si', [true, true, true],
    [$e[1]['editable'], $e[2]['editable'], $e[3]['editable']]);
chequear('Mercado Pago tambien', true, Saldos::marcarEditables($mpManualHoy['filas'], true, true)[0]['editable']);
chequear('en Interbanking se quita el manual vigente, aunque no sea de hoy', true, $e[1]['manual']['quitable']);
chequear('en el catalogo se quita el manual de hoy', true, $e[2]['manual']['quitable']);
chequear('el de ayer no: es el historico', false, $e[3]['manual']['quitable']);
chequear('ni uno de hoy de las viejas "Nueva carga": tiene todas las cuentas', false, $e[4]['manual']['quitable']);

$sinScript = Saldos::marcarEditables([$filaIbManual, $otraHoy], false, true);
chequear('sin el script, el catalogo no se edita y Interbanking si', [true, false],
    [$sinScript[0]['editable'], $sinScript[1]['editable']]);
$sinTablaIb = Saldos::marcarEditables([$filaIbManual], true, false);
chequear('sin la tabla del manual de Interbanking, sus filas no se editan', false, $sinTablaIb[0]['editable']);

// ============================================================================
seccion('el efectivo de tesoreria se rechaza en el servidor');
// ============================================================================

$motivo = null;

try {
    Saldos::validarEdicionCuenta(['ID' => 1, 'NOMBRE' => 'Efectivo', 'TIPO' => 'EFECTIVO_CENTRAL',
        'CLASE' => 'CTA_CORRIENTE']);
} catch (Exception $ex) {
    $motivo = $ex->getMessage();
}

chequear('el efectivo de tesoreria no admite saldo manual, y dice por que', true,
    $motivo !== null && strpos($motivo, 'no se edita: se lee en vivo de SBA05') !== false);
chequearLanza('una cuenta inexistente o inhabilitada tampoco', function () {
    Saldos::validarEdicionCuenta(null);
});
chequearLanza('un fondo tampoco: su saldo es su cuenta corriente', function () {
    Saldos::validarEdicionCuenta(['ID' => 5, 'NOMBRE' => 'FCI', 'TIPO' => 'OTRO', 'CLASE' => 'INVERSION']);
});

$aceptadas = 0;

foreach (['MERCADO_PAGO', 'BANCO', 'OTRO'] as $t) {
    Saldos::validarEdicionCuenta(['ID' => 2, 'NOMBRE' => 'x', 'TIPO' => $t, 'CLASE' => 'CTA_CORRIENTE']);
    $aceptadas++;
}

chequear('Mercado Pago, un banco sin Interbanking y Otros si', 3, $aceptadas);

preg_match('/function guardarSaldoManual\(.*?\n    }\n/s', $srcSaldos, $m);
$guardar = isset($m[0]) ? $m[0] : '';
preg_match('/function quitarSaldoManual\(.*?\n    }\n/s', $srcSaldos, $m);
$quitar = isset($m[0]) ? $m[0] : '';

chequear('el guardado valida la cuenta antes de escribir', true,
    strpos($guardar, 'self::validarEdicionCuenta($cuenta)') !== false
    && strpos($guardar, 'self::validarEdicionCuenta($cuenta)') < strpos($guardar, 'sqlsrv_begin_transaction'));
chequear('y el quitar tambien', true,
    strpos($quitar, 'self::validarEdicionCuenta($cuenta)') !== false
    && strpos($quitar, 'self::validarEdicionCuenta($cuenta)') < strpos($quitar, 'sqlsrv_begin_transaction'));
chequear('las dos exigen usuario de escritura', true,
    strpos($guardar, 'usuarioDeEscritura') !== false && strpos($quitar, 'usuarioDeEscritura') !== false);
chequear('reemplazar da de baja con usuario y fecha, y la nueva lo nombra', true,
    strpos($guardar, 'SET ACTIVO = 0, " . Auditoria::SET_BAJA') !== false
    && strpos($guardar, '$reemplaza') !== false);
chequear('la fecha del detalle es hoy, la del servidor', true,
    strpos($guardar, '[$idCarga, $cuenta[\'ID\'], $hoy,') !== false);
chequear('ninguna borra', false, (bool) preg_match('/DELETE/i', $guardar . $quitar));
chequear('Nueva carga ya no existe', false, strpos($srcSaldos, 'function guardarCargaSaldos(') !== false);

$sqlEd = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../sql/cashflow_saldos_edicion_fila.sql'));
chequear('el script agrega ID_REEMPLAZA solo si falta', true,
    strpos($sqlEd, "ELSE IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'ID_REEMPLAZA') IS NULL") !== false);
chequear('y no borra nada', false, (bool) preg_match('/\b(DELETE|DROP|TRUNCATE)\b/i', $sqlEd));

// ============================================================================
seccion('el KPI "Saldo manual más viejo" y la alerta por cuenta');
// ============================================================================

$kpi = Saldos::avisosAntiguedad([
    $otraHoy,
    Saldos::aplicarManuales([edFila(7, 'BANCO', 'BTG Uy', 'MANUAL', 71000.0, '2026-09-16')], ED_HOY)[0],
    $mpManualHoy['filas'][0],
    array_merge($filaIbManual, ['manual' => ['de_hoy' => true, 'manda' => true], 'origen' => 'RESPALDO'])
], 7, ED_HOY);
chequear('el mas viejo es BTG Uy, con su fecha y los dias', ['BTG Uy', '2026-09-16', 44],
    [$kpi['mas_viejo']['nombre'], $kpi['mas_viejo']['fecha'], $kpi['mas_viejo']['dias']]);
chequear('cuenta los manuales de hoy de todas las cuentas', 3, $kpi['manuales_hoy']);
chequear('avisa solo el que pasa los dias de alerta', 1, count($kpi['avisos']));
chequear('con su nombre y su fecha', true,
    strpos($kpi['avisos'][0], 'BTG Uy: el último saldo manual es del 16/09/2026, hace 44 días') !== false);
chequear('sin cuentas manuales, no hay mas viejo', null, Saldos::avisosAntiguedad([], 7, ED_HOY)['mas_viejo']);

// ============================================================================
seccion('el total de la pestana sigue siendo el Saldo Inicial del tablero');
// ============================================================================

$hEd = new Horizonte(5, 2, [], new DateTime(ED_HOY));
$ibHoyManual = SaldosInterbanking::armarCuentasBancarias([
    ['ID' => 1, 'FECHA_OPERACION' => ED_HOY, 'NRO_BANCO' => '007', 'NRO_CUENTA' => '100', 'TIPO_CUENTA' => 'CC',
     'MONEDA' => 'ARS', 'SALDO_OPERATIVO_ACTUAL' => 1000.0, 'CREATED_AT' => ED_HOY . ' 15:00:00']
], ['hoy' => ED_HOY, 'tango' => ['007' => 'GALICIA'], 'respaldos' => ['007|100|ARS' => [
    'ID' => 4, 'NRO_BANCO' => '007', 'NRO_CUENTA' => '100', 'MONEDA' => 'ARS', 'FECHA_SALDO' => ED_HOY,
    'SALDO_CONTABLE' => 1500.0, 'OBSERVACION' => null, 'ID_REEMPLAZA' => null, 'USUARIO_ALTA' => 'tesoreria',
    'FECHA_ALTA' => ED_HOY . ' 09:00:00']]]);
$todas = array_merge($mpManualHoy['filas'], [$otraHoy], $ibHoyManual['filas']);
$totalPestana = Saldos::totalesPorMoneda(array_filter($todas, function ($f) { return $f['cargada']; }));
$serie = Saldos::armarSerieDisponible($todas, $hEd);
chequear('con manuales de hoy en los tres tipos de cuenta, el total de la pestana es la serie del tablero',
    $totalPestana['ARS']['total'], array_sum($serie['serie']['dias']));
chequear('y es la suma de los manuales', 500.0 + 30.0 + 1500.0, $totalPestana['ARS']['total']);
