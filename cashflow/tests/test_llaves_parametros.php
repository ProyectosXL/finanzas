<?php
/**
 * Parametros -> Llaves y Renovaciones (Class/LlavesParametros.php): los
 * validadores, que son los que mandan, y el script 66.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. VACIO SE GUARDA COMO 0. Un escribano o un % de impuestos vacio es "sin
 *      dato" y avisa; 0 es un valor. Si el validador los confunde, la llave se
 *      proyecta sin impuestos sin que nadie lo haya decidido.
 *   2. UN NO RENUEVA SIN MOTIVO. Nadie sabe despues por que desaparecio una
 *      llave del flujo.
 *   3. UNA MARCA DE PAGADA A FUTURO. Lo que no se pago sigue proyectado.
 *   4. EL SCRIPT SIEMBRA DE MAS. Solo los tres defaults de Generales.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/LlavesParametros.php';

seccion('LlavesParametros: Generales');

chequear('el % de llave acepta coma decimal', 27.5, LlavesParametros::validarGeneral('PCT_LLAVE', '27,5'));
chequearLanza('el % de llave no se vacía', function () { LlavesParametros::validarGeneral('PCT_LLAVE', ''); });
chequearLanza('las cuotas son un entero de 1 a 60', function () { LlavesParametros::validarGeneral('CUOTAS', '0'); });
chequearLanza('el día de pago llega hasta 28', function () { LlavesParametros::validarGeneral('DIA_PAGO', '31'); });
chequear('un costo en U$S se puede vaciar', null, LlavesParametros::validarGeneral('USD_OBRA_CHICO', ''));
chequearLanza('el corte de mediano tiene que ser mayor que el de chico', function () {
    LlavesParametros::validarGeneral('CORTE_MEDIANO', '80', ['CORTE_CHICO' => 84.0]);
}, 'El corte de mediano tiene que ser mayor que el de chico (84,00 m²): si no, ningún local sería mediano.');
chequearLanza('una clave que no existe', function () { LlavesParametros::validarGeneral('OTRA', '1'); });

seccion('LlavesParametros: por operador, vacío no es 0');

$v = LlavesParametros::validarOperador(['pct_llave' => '', 'pct_otros' => '43,8', 'escribano' => '0',
                                        'pct_impuestos' => '', 'cuotas' => '', 'corrimiento' => '-3']);
chequear('vacío es null', [null, null, null], [$v['pct_llave'], $v['pct_impuestos'], $v['cuotas']]);
chequear('0 en el escribano es 0', 0.0, $v['escribano']);
chequear('el corrimiento puede ser negativo', -3, $v['corrimiento']);
chequearLanza('el corrimiento tiene tope', function () { LlavesParametros::validarOperador(['corrimiento' => '30']); });

seccion('LlavesParametros: por local');

chequearLanza('No renueva pide motivo', function () { LlavesParametros::validarLocal(['no_renueva' => '1']); });
$v = LlavesParametros::validarLocal(['no_renueva' => '1', 'motivo_no_renueva' => 'El local cierra', 'mes_primera_cuota' => '2027-03']);
chequear('No renueva con motivo y el mes de la primera cuota', [true, 'El local cierra', '2027-03'],
    [$v['no_renueva'], $v['motivo_no_renueva'], $v['mes_primera_cuota']]);
chequearLanza('un mes mal escrito', function () { LlavesParametros::validarLocal(['mes_primera_cuota' => '2027-13']); });

seccion('LlavesParametros: ya pagada');

$v = LlavesParametros::validarPagada(['concepto' => 'llave', 'fecha' => '2026-08-31', 'monto' => '180525000'], '2026-11-23');
chequear('llave pagada el 31/08', ['LLAVE', '2026-08-31', 180525000.0], [$v['concepto'], $v['fecha'], $v['monto']]);
chequearLanza('una fecha futura no está pagada', function () {
    LlavesParametros::validarPagada(['concepto' => 'LLAVE', 'fecha' => '2026-12-01', 'monto' => '1'], '2026-11-23');
});
chequearLanza('el monto es obligatorio y mayor que 0', function () {
    LlavesParametros::validarPagada(['concepto' => 'OTROS', 'fecha' => '2026-08-31', 'monto' => '0'], '2026-11-23');
});

seccion('LlavesParametros: obras, aperturas y compromisos');

$loc = [78 => ['nombre' => 'DISTRITO ARCOS']];
$v = LlavesParametros::validarObra(['nro' => '78', 'mes' => '2027-03', 'segmento' => '', 'costo_usd' => ''], $loc);
chequear('una obra sin segmento ni costo propio (usa los de m² y del segmento)', [78, '2027-03', null, null],
    [$v['nro'], $v['mes'], $v['segmento'], $v['costo_usd']]);
chequearLanza('una obra de un local que no está en la app', function () use ($loc) {
    LlavesParametros::validarObra(['nro' => '999', 'mes' => '2027-03'], $loc);
});
chequearLanza('una apertura pide el segmento', function () use ($loc) {
    LlavesParametros::validarApertura(['nombre' => 'Nuevo', 'mes' => '2027-03'], $loc);
});
$v = LlavesParametros::validarCompromiso(['tipo' => 'ALQUILERES', 'cod_provee' => 'saunic', 'concepto' => 'Prenda',
    'fecha' => '2026-12-10', 'monto' => '1000000', 'cuotas' => '4', 'forma' => 'ECHEQ'], ['SAUNIC' => true], [], $loc);
chequear('una prenda en 4 cuotas mensuales', ['SAUNIC', 4, '2027-03-10'],
    [$v['cod_provee'], count($v['cuotas']), $v['cuotas'][3]['fecha']]);
chequearLanza('el operador tiene que estar en su universo', function () use ($loc) {
    LlavesParametros::validarCompromiso(['tipo' => 'PROVEEDOR', 'cod_provee' => 'NOEXISTE', 'concepto' => 'X',
        'fecha' => '2026-12-10', 'monto' => '1'], [], ['SAUNIS' => true], $loc);
});

seccion('LlavesParametros: el script 66');

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_llaves.sql');
foreach (LlavesParametros::TABLAS as $t) {
    chequear("el script crea $t", true, strpos($sql, "CREATE TABLE dbo.$t") !== false);
}
chequear('siembra sólo los tres defaults de Generales', true,
    strpos($sql, "(VALUES ('PCT_LLAVE', 25.00), ('CUOTAS', 4.00), ('DIA_PAGO', 15.00))") !== false
    && substr_count($sql, 'INSERT INTO') === 1);
chequear('no hay bajas físicas', 0, preg_match_all('/\b(DELETE|DROP|TRUNCATE)\b/i',
    preg_replace(['#/\*.*?\*/#s', '#--[^\n]*#'], '', $sql)));
chequear('las claves de Generales del CHECK son las de la clase', true, array_reduce(array_keys(LlavesParametros::GENERALES),
    function ($ok, $k) use ($sql) { return $ok && strpos($sql, "'" . $k . "'") !== false; }, true));
