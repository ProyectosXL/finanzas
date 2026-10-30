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
