<?php
/**
 * Corporativas: la fecha de una factura vinculada es el debito de su tarjeta a
 * partir de su vencimiento.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. SE CUENTA DESDE HOY Y NO DESDE EL VENCIMIENTO. Una factura en cuotas
 *      juntaria todas sus cuotas en el proximo debito, y el mes siguiente
 *      quedaria vacio.
 *
 *   2. UNA CUOTA QUE VENCE DESPUES DEL DIA DE LA TARJETA QUEDA EN SU MES. Se
 *      pagaria en un debito que ya se cerro: el banco la debita el mes que viene.
 *
 *   3. EL RESUMEN CARGADO NO FIJA EL DEBITO DE SU MES. Si el resumen vence antes
 *      que la cuota, la cuota tiene que ir al mes siguiente.
 *
 * Todo lo de aca es PURO.
 */

require_once __DIR__ . '/../Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../Class/TarjetasVencimiento.php';
require_once __DIR__ . '/../Class/Proveedores.php';

$HAB = [];

for ($d = new DateTime('2026-08-01'); $d <= new DateTime('2027-01-31'); $d->modify('+1 day')) {
    $HAB[$d->format('Y-m-d')] = intval($d->format('N')) <= 5;
}

$MS = ['2026-09', '2026-10', '2026-11', '2026-12'];
$HY = '2026-09-26';

/* La tarjeta vence el 10: 10/09 jueves, 10/10 sabado (pasa al 12), 10/11 martes,
   10/12 jueves. */
$TJ = [7 => ['ID' => 7, 'TIPO' => 'CORPORATIVA', 'PCT_COBERTURA' => 0.0, 'DIA_VENCIMIENTO' => 10,
             'ACTIVA' => true, 'ROTULO' => '•••• 1234']];

function fdTC($n, $vto, $importe = 100000) {
    return ['COD_PROVEE' => 'OGAAA', 'RAZON_SOC' => 'PROVEEDOR', 'T_COMP' => 'FAC', 'N_COMP' => $n,
            'FECHA_VTO' => $vto, 'IMPORTE_PENDIENTE' => $importe, 'IMPORTE_VTO' => $importe,
            'FORMA_PAGO_VIGENTE' => 'TARJETA CORP'];
}

function filaFD($factura, $vinculada = true, $resumenes = [], $vtos = []) {
    global $TJ, $HAB, $MS, $HY;

    $v = $vinculada ? [TarjetasCorporativas::clave($factura) => 7] : [];

    return TarjetasCorporativas::resolver([$factura], $v, [], $TJ, $resumenes, $HAB, $MS, $HY,
        $vtos)['filas'][0];
}

/* ================================================================
   EL DEBITO A PARTIR DEL VENCIMIENTO
   ================================================================ */
seccion('pagoDesde: el primer debito igual o posterior al vencimiento');

chequear('vence antes del dia de la tarjeta: el debito de su mes', '2026-10-12',
    TarjetasVencimiento::pagoDesde(10, '2026-10-05', [], $MS, $HAB)['fecha']);
chequear('vence despues del dia de la tarjeta: el del mes siguiente', '2026-11-10',
    TarjetasVencimiento::pagoDesde(10, '2026-10-20', [], $MS, $HAB)['fecha']);
chequear('vence el mismo dia del debito: ese debito', '2026-11-10',
    TarjetasVencimiento::pagoDesde(10, '2026-11-10', [], $MS, $HAB)['fecha']);
chequear('vence el dia teorico pero el debito se corrio: el corrido', '2026-10-12',
    TarjetasVencimiento::pagoDesde(10, '2026-10-11', [], $MS, $HAB)['fecha']);
chequear('vence despues del ultimo debito del horizonte: null', null,
    TarjetasVencimiento::pagoDesde(10, '2026-12-15', [], $MS, $HAB));

$res = ['2026-10' => ['FECHA_VENCIMIENTO' => '2026-10-07', 'PAGADO' => false]];

chequear('el resumen fija el debito de su mes', ['2026-10-07', 'RESUMEN'],
    array_values(array_intersect_key(TarjetasVencimiento::pagoDesde(10, '2026-10-02', $res, $MS, $HAB),
        ['fecha' => 1, 'origen' => 1])));
chequear('y si vence antes que la cuota, la cuota va al mes siguiente', '2026-11-10',
    TarjetasVencimiento::pagoDesde(10, '2026-10-09', $res, $MS, $HAB)['fecha']);

/* ================================================================
   EN LA GRILLA DE CORPORATIVAS
   ================================================================ */
seccion('la factura vinculada sale en el debito de su tarjeta');

$f = filaFD(fdTC('A1', '2026-10-05'));

chequear('cuota que vence en el mes, antes del dia de la tarjeta', ['2026-10-12', '2026-10', true],
    [$f['FECHA'], $f['MES_PAGO'], $f['PROYECTA']]);
chequear('conserva el vencimiento de Tango para la columna informativa', '2026-10-05', $f['FECHA_VTO']);
chequear('y el texto dice las dos fechas', true,
    strpos($f['EXPLICACION'], '05/10/2026') !== false && strpos($f['EXPLICACION'], '12/10/2026') !== false);

$f = filaFD(fdTC('A2', '2026-10-20'));

chequear('cuota que vence despues del dia de la tarjeta: va al mes siguiente',
    ['2026-11-10', '2026-11'], [$f['FECHA'], $f['MES_PAGO']]);

/* UNA FACTURA EN CUOTAS: cada cuota va al debito que le toca, no todas al proximo. */
$c1 = filaFD(fdTC('A3', '2026-10-05'));
$c2 = filaFD(fdTC('A3', '2026-11-05'));
$c3 = filaFD(fdTC('A3', '2026-12-05'));

chequear('una factura en cuotas: cada cuota en su debito',
    ['2026-10-12', '2026-11-10', '2026-12-10'], [$c1['FECHA'], $c2['FECHA'], $c3['FECHA']]);

/* VENCIDA: el debito a partir del vencimiento ya paso, y rige la regla de las
   vencidas -el proximo pago posterior a hoy-. */
$v = filaFD(fdTC('A4', '2026-08-15'));

chequear('vencida: sale en el proximo pago posterior a hoy', ['2026-10-12', true, true],
    [$v['FECHA'], $v['VENCIDA'], $v['REUBICADA']]);

/* CORREGIDA: el vencimiento corregido manda, y la mueve al debito siguiente. */
$cuota = fdTC('A5', '2026-10-05');
$corr = [TarjetasCorporativas::claveCuota($cuota) => ['FECHA_VTO' => '2026-10-15', 'MOTIVO' => null,
         'USUARIO_ALTA' => 'u', 'FECHA_ALTA' => '2026-09-26 10:00']];
$e = filaFD($cuota, true, [], $corr);

chequear('corregida al 15/10: pasa al debito de noviembre', ['2026-11-10', '2026-11', true],
    [$e['FECHA'], $e['MES_PAGO'], $e['VTO_EDITADO']]);

/* NO VINCULADA: sigue con el vencimiento de Tango, y no suma. */
$n = filaFD(fdTC('A6', '2026-10-05'), false);

chequear('no vinculada: el vencimiento de Tango, y no suma', ['2026-10-05', false],
    [$n['FECHA'], $n['PROYECTA']]);

/* FUERA DEL HORIZONTE: vence despues del ultimo debito. */
$x = filaFD(fdTC('A7', '2026-12-15'));

chequear('vence despues del ultimo debito del horizonte: sin fecha, y lo dice',
    [null, TarjetasCorporativas::SIN_FECHA, true],
    [$x['FECHA'], $x['MOTIVO'], strpos($x['EXPLICACION'], 'cae fuera del horizonte') !== false]);

/* EL RESUMEN DEL MES LA CUBRE: el debito de octubre es el del resumen. */
$resOct = [7 => ['2026-10' => ['ID' => 9, 'MES' => '2026-10', 'IMPORTE_ARS' => 1.0,
                               'FECHA_VENCIMIENTO' => '2026-10-07', 'PAGADO' => false]]];

chequear('cuota antes del resumen de su mes: cubierta por el', TarjetasCorporativas::CUBIERTA,
    filaFD(fdTC('A8', '2026-10-02'), true, $resOct)['MOTIVO']);
chequear('cuota despues del resumen de su mes: al debito de noviembre, no cubierta',
    ['2026-11-10', TarjetasCorporativas::OK],
    [filaFD(fdTC('A9', '2026-10-09'), true, $resOct)['FECHA'],
     filaFD(fdTC('A9', '2026-10-09'), true, $resOct)['MOTIVO']]);
