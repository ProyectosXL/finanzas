<?php
/**
 * Parametros › Prestamos y la carga de un prestamo
 * (Class/PrestamosParametros.php): los validadores, la copia al alta y el
 * script 70.
 *
 * SIN BASE: las escrituras no las corre ninguna prueba (escriben en la base
 * viva). Se fijan los validadores, lo que se copia y el SQL leyendo el script.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. UN FRANCES CON FRECUENCIAS DISTINTAS se guarda y el cronograma sale
 *      cualquier cosa.
 *   2. UN CAMBIO EN PARAMETROS MUEVE LOS PRESTAMOS QUE YA EXISTEN.
 *   3. UN COSTO INICIAL SOBRE INTERESES (que el dia del desembolso no hay).
 *   4. UNA CANCELACION PARCIAL POR MAS QUE EL SALDO, o de un simulado.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/PrestamosParametros.php';

$cuentas = ['IB|11|123|ARS' => 'Galicia CC 123', 'CTA_12' => 'BTG Uy'];

/** Que lance y que el mensaje contenga un fragmento (chequearLanza compara entero). */
function ppLanzaCon($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance', 'no lanzo');
}

function ppDatos($extra = []) {
    return $extra + ['nombre' => 'Préstamo Galicia', 'cuenta_clave' => 'IB|11|123|ARS', 'moneda' => 'ARS', 'capital' => '10000000',
                     'fecha_desembolso' => '2026-12-15', 'tipo_tasa' => 'FIJA', 'tna' => '38,5', 'sistema' => 'FRANCES',
                     'cuotas' => '24', 'frec_capital' => 'MENSUAL', 'frec_intereses' => '', 'gracia' => ''];
}

seccion('el prestamo: lo que se acepta');
$d = ppDatos(['capital' => '10000000,50']);
$v = PrestamosParametros::validarPrestamo($d, $cuentas);
chequear('capital con coma decimal', 10000000.5, $v['capital']);
chequear('TNA en %', 38.5, $v['tna']);
chequear('la frecuencia de intereses, por defecto la de capital', 'MENSUAL', $v['frec_intereses']);
chequear('gracia vacia = 0', 0, $v['gracia']);
chequear('el nombre de la cuenta se guarda', 'Galicia CC 123', $v['cuenta_nombre']);
chequear('por defecto entra como simulado', 'SIMULADO', $v['estado']);
chequear('criterio de tasa por defecto: promedio', 'PROMEDIO', $v['criterio_tasa']);
$v = PrestamosParametros::validarPrestamo(ppDatos(['tipo_tasa' => 'VARIABLE', 'tna' => '', 'referencia' => 'tamar', 'spread' => '-1,5']), $cuentas);
chequear('variable: referencia + spread (puede ser negativo)', ['TAMAR', -1.5, null], [$v['referencia'], $v['spread'], $v['tna']]);
$v = PrestamosParametros::validarPrestamo(ppDatos(['sistema' => 'ALEMAN', 'cuotas' => '8', 'frec_capital' => 'TRIMESTRAL', 'frec_intereses' => 'MENSUAL']), $cuentas);
chequear('aleman con capital trimestral e intereses mensuales', ['TRIMESTRAL', 'MENSUAL'], [$v['frec_capital'], $v['frec_intereses']]);

seccion('el prestamo: lo que se rechaza en el servidor');
$casos = [
    'sin nombre' => ['nombre' => ''],
    'una cuenta que no es de Saldos' => ['cuenta_clave' => 'OTRA'],
    'capital cero' => ['capital' => '0'],
    'capital negativo' => ['capital' => '-5'],
    'sin fecha de desembolso' => ['fecha_desembolso' => ''],
    'fija sin TNA' => ['tna' => ''],
    'variable sin referencia' => ['tipo_tasa' => 'VARIABLE', 'referencia' => '', 'spread' => '2'],
    'una moneda que no es ARS ni USD' => ['moneda' => 'EUR'],
    'cuotas cero' => ['cuotas' => '0'],
    'frances con frecuencias distintas' => ['frec_intereses' => 'TRIMESTRAL'],
    'plazo no multiplo de los intereses' => ['sistema' => 'ALEMAN', 'cuotas' => '10', 'frec_intereses' => 'TRIMESTRAL'],
    'gracia igual a las cuotas' => ['gracia' => '24'],
    'primera cuota antes del desembolso' => ['fecha_primera' => '2026-12-01'],
    'forma de pago desconocida' => ['forma_pago' => 'CHEQUE']
];

foreach ($casos as $nombre => $cambio) {
    chequearLanza($nombre, function () use ($cambio, $cuentas) {
        PrestamosParametros::validarPrestamo(ppDatos($cambio), $cuentas);
    });
}

ppLanzaCon('el francés desacoplado dice que use alemán o bullet', function () use ($cuentas) {
    PrestamosParametros::validarPrestamo(ppDatos(['frec_intereses' => 'TRIMESTRAL']), $cuentas);
}, 'alemán o bullet');

seccion('costos');
$c = PrestamosParametros::validarCosto(['nombre' => 'Sellos', 'pct' => '1,2', 'base' => 'capital', 'momento' => 'inicial', 'iva' => '0']);
chequear('un costo inicial sobre capital', ['Sellos', 1.2, 'CAPITAL', 'INICIAL', false, true],
    [$c['nombre'], $c['pct'], $c['base'], $c['momento'], $c['iva'], $c['activo']]);
ppLanzaCon('un costo inicial sobre intereses se rechaza', function () {
    PrestamosParametros::validarCosto(['nombre' => 'X', 'pct' => '1', 'base' => 'INTERESES', 'momento' => 'INICIAL']);
}, 'inicial no puede calcularse sobre los intereses');
chequearLanza('un % mayor a 100', function () {
    PrestamosParametros::validarCosto(['nombre' => 'X', 'pct' => '101', 'base' => 'CAPITAL', 'momento' => 'INICIAL']);
});

seccion('comision de cancelacion y tramos');
$t = PrestamosParametros::validarTramos([['desde' => '50', 'pct_total' => '2', 'pct_parcial' => '1'], ['desde' => '0', 'pct_total' => '4', 'pct_parcial' => '3']]);
chequear('los tramos quedan ordenados', [0.0, 50.0], array_column($t, 'desde'));
chequearLanza('dos tramos con el mismo desde', function () {
    PrestamosParametros::validarTramos([['desde' => '0', 'pct_total' => '1', 'pct_parcial' => '1'], ['desde' => '0', 'pct_total' => '2', 'pct_parcial' => '1']]);
});
chequearLanza('escalonada sin tramo desde 0', function () {
    PrestamosParametros::validarComision(['modo' => 'ESCALONADA', 'tramos' => [['desde' => '10', 'pct_total' => '1', 'pct_parcial' => '1']]]);
});

seccion('generales');
chequear('dias del promedio', 5, PrestamosParametros::validarGeneral('DIAS_PROMEDIO', '5'));
chequear('margen con coma', 12.5, PrestamosParametros::validarGeneral('MARGEN', '12,5'));
chequear('sistema del simulador', 'ALEMAN', PrestamosParametros::validarGeneral('SIM_SISTEMA', 'aleman'));
chequear('la antiguedad tolerada es un parametro con 5 por defecto', 5, PrestamosParametros::GENERALES['ANTIGUEDAD_MAX']['defecto']);
chequearLanza('una clave que no existe', function () { PrestamosParametros::validarGeneral('OTRA', '1'); });
chequearLanza('cero dias de promedio', function () { PrestamosParametros::validarGeneral('DIAS_PROMEDIO', '0'); });
chequear('los defaults de la especificacion', [5, 10, 'FRANCES', 24, 'MENSUAL', 38, 'FIJA', 3, 3, 1],
    array_map(function ($k) { return PrestamosParametros::GENERALES[$k]['defecto']; },
        ['DIAS_PROMEDIO', 'MARGEN', 'SIM_SISTEMA', 'SIM_CUOTAS', 'SIM_FRECUENCIA', 'SIM_TNA', 'CANCEL_MODO', 'CANCEL_PCT_TOTAL', 'CANCEL_PCT_PARCIAL', 'CANCEL_IVA']));

seccion('se copian al alta y un cambio posterior no altera a los existentes');
$gen = ['CANCEL_MODO' => 'FIJA', 'CANCEL_PCT_TOTAL' => 3, 'CANCEL_PCT_PARCIAL' => 2, 'CANCEL_IVA' => 1];
$costosParam = [
    ['id' => 1, 'nombre' => 'Sellos', 'pct' => 1.2, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false, 'activo' => true],
    ['id' => 2, 'nombre' => 'IIBB', 'pct' => 7.0, 'base' => 'INTERESES', 'momento' => 'POR_CUOTA', 'iva' => false, 'activo' => false]
];
$copia = PrestamosParametros::copiaParaAlta($gen, $costosParam, []);
chequear('se copian solo los activos', ['Sellos'], array_column($copia['costos'], 'nombre'));
chequear('con el id del parametro de donde salieron', 1, $copia['costos'][0]['id_param']);
chequear('y la comision', [3.0, 2.0, true], [$copia['cancelacion']['pct_total'], $copia['cancelacion']['pct_parcial'], $copia['cancelacion']['iva']]);
$costosParam[0]['pct'] = 2.5;
chequear('cambiar Parametros despues no toca la copia', 1.2, $copia['costos'][0]['pct']);
/* Sin CRLF: con core.autocrlf la copia de trabajo los tiene, y las regex de
   abajo terminan en "\n    }\n". Sin normalizar no encuentran la funcion y
   fallan aunque el codigo este bien. */
$src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/PrestamosParametros.php'));
preg_match('/function editarPrestamo\(.*?\n    \}\n/s', $src, $m);
chequear('editar un prestamo no relee los costos de Parametros', false, strpos($m[0] ?? '', 'costosParam') !== false);
chequear('el calculo usa los costos del prestamo, no los de Parametros', false,
    strpos(file_get_contents(__DIR__ . '/../Class/PrestamosCalculo.php'), 'PARAM_COSTO') !== false);

seccion('correcciones y tasas manuales');
chequear('corregir el capital', ['concepto' => 'CAPITAL', 'importe' => 1500.5], PrestamosParametros::validarAjuste('capital', '1500,5', [7]));
chequear('corregir un costo del prestamo', 'COSTO:7', PrestamosParametros::validarAjuste('COSTO:7', '10', [7])['concepto']);
chequearLanza('un costo de otro prestamo', function () { PrestamosParametros::validarAjuste('COSTO:8', '10', [7]); });
chequearLanza('un importe negativo', function () { PrestamosParametros::validarAjuste('INTERES', '-1', []); });
chequear('tasa manual', ['referencia' => 'BADLAR', 'mes' => '2027-01', 'valor' => 25.0], PrestamosParametros::validarTasaManual('badlar', '2027-01', '25'));
chequear('vacia la quita', null, PrestamosParametros::validarTasaManual('TAMAR', '2027-01', '')['valor']);

seccion('cancelaciones: lo que valida el servidor');
$p = PrestamosCalculo::normalizar(['nombre' => 'X', 'capital' => 1000, 'fecha_desembolso' => '2026-01-15', 'cuotas' => 12,
                                   'frec_capital' => 'MENSUAL', 'sistema' => 'ALEMAN', 'tna' => 30]);
$ok = PrestamosParametros::validarCancelacion(['fecha' => '2026-06-01', 'tipo' => 'PARCIAL', 'monto' => '300', 'modo' => 'REDUCIR_PLAZO'],
    $p, 'VIGENTE', 600, '2027-01-15', false);
chequear('una parcial valida', [300.0, 'REDUCIR_PLAZO'], [$ok['monto'], $ok['modo']]);
chequear('una total no lleva monto ni modo', [null, null], array_values(array_intersect_key(
    PrestamosParametros::validarCancelacion(['fecha' => '2026-06-01', 'tipo' => 'TOTAL', 'monto' => '5', 'modo' => 'X'], $p, 'VIGENTE', 600, '2027-01-15', false),
    ['monto' => 1, 'modo' => 1])));
chequearLanza('de un simulado, no', function () use ($p) {
    PrestamosParametros::validarCancelacion(['fecha' => '2026-06-01', 'tipo' => 'TOTAL'], $p, 'SIMULADO', 600, '2027-01-15', false);
});
ppLanzaCon('parcial por el saldo entero: que sea total', function () use ($p) {
    PrestamosParametros::validarCancelacion(['fecha' => '2026-06-01', 'tipo' => 'PARCIAL', 'monto' => '600', 'modo' => 'REDUCIR_CUOTA'], $p, 'VIGENTE', 600, '2027-01-15', false);
}, 'cancelación total');
chequearLanza('antes del desembolso', function () use ($p) {
    PrestamosParametros::validarCancelacion(['fecha' => '2026-01-10', 'tipo' => 'TOTAL'], $p, 'VIGENTE', 600, '2027-01-15', false);
});
chequearLanza('con otra total ya registrada', function () use ($p) {
    PrestamosParametros::validarCancelacion(['fecha' => '2026-06-01', 'tipo' => 'TOTAL'], $p, 'VIGENTE', 600, '2027-01-15', true);
});

seccion('el script 70');
$sql = file_get_contents(__DIR__ . '/../sql/cashflow_prestamos.sql');
foreach (PrestamosParametros::TABLAS as $t) {
    chequear('crea ' . $t . ' si no existe', true, strpos($sql, "IF OBJECT_ID('dbo." . $t . "', 'U') IS NULL") !== false);
}
chequear('sin bajas fisicas ni DROP', 0, preg_match('/\b(DELETE\s+FROM|DROP\s+TABLE|TRUNCATE)\b/i', $sql));
chequear('cada tabla con la auditoria completa', 9, preg_match_all('/USUARIO_BAJA[ ]+VARCHAR/', $sql));
chequear('siembra los cinco costos', 5, preg_match_all("/\('(Comisión de otorgamiento|Impuesto de sellos|IVA sobre intereses|Ingresos Brutos sobre intereses|Comisión de aval SGR)'/u", $sql));
chequear('Ingresos Brutos y aval SGR entran inactivos', true,
    (bool) preg_match("/'Ingresos Brutos sobre intereses',\s+7\.0000, 'INTERESES', 'POR_CUOTA', 0, 0/", $sql)
    && (bool) preg_match("/'Comisión de aval SGR',\s+2\.0000, 'CAPITAL',\s+'INICIAL',\s+1, 0/", $sql));
foreach (PrestamosParametros::GENERALES as $k => $g) {
    chequear('siembra ' . $k . ' con su default', true, strpos($sql, "('" . $k . "', '" . $g['defecto'] . "')") !== false);
}
chequear('el CHECK de los costos rechaza inicial sobre intereses', true, strpos($sql, "NOT (MOMENTO = 'INICIAL' AND BASE = 'INTERESES')") !== false);
chequear('el CHECK del prestamo: frances con frecuencias iguales', true, strpos($sql, "SISTEMA <> 'FRANCES' OR FREC_CAPITAL = FREC_INTERESES") !== false);
chequear('el estado guardado es solo simulado o vigente', true, strpos($sql, "ESTADO IN ('SIMULADO', 'VIGENTE')") !== false);

seccion('editar no le cambia el ID a los costos que ya existen');
preg_match('/function sincronizarCostos\(.*?\n    \}\n/s', $src, $m2);
chequear('editarPrestamo sincroniza, no da de baja todos los costos', true,
    strpos($m[0] ?? '', 'sincronizarCostos(') !== false && strpos($m[0] ?? '', "darDeBaja(self::T_COSTO, 'ID_PRESTAMO = ? AND VIGENTE = 1'") === false);
chequear('los que vienen con su id se actualizan en su lugar', true, strpos($m2[0] ?? '', 'UPDATE dbo." . self::T_COSTO') !== false);
chequear('el formulario manda el id de cada costo', true,
    strpos(file_get_contents(__DIR__ . '/../Js/Prestamos.js'), "var c = { id: tr.getAttribute('data-id') || ''") !== false);
