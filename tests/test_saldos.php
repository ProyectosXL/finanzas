<?php
/**
 * Modulo Saldos.
 *
 * Todo lo delicado de este modulo son CRITERIOS, no consultas: que sucursal
 * aporta, que pasa con un neto negativo, en que columna del eje cae un saldo y
 * cual es "la ultima carga". Por eso esos criterios viven en helpers estaticos
 * puros y se verifican aca sin base, al estilo de Ventas::armarTendencias().
 *
 * La ultima seccion si toca la base y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../cashflow/Class/Saldos.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';

/** Eje de referencia de todas las pruebas: 28 dias desde el 6/9/2026 + 12 meses */
$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));

/* ================================================================
   La ultima carga
   ================================================================ */
seccion('cual es la ultima carga');

chequear('sin cargas no hay ultima', null, Saldos::ultimaCarga([]));
chequear('una lista que no es lista tampoco', null, Saldos::ultimaCarga(null));

$cargas = [
    ['ID' => 1, 'FECHA_CARGA' => new DateTime('2026-09-01 09:00:00')],
    ['ID' => 2, 'FECHA_CARGA' => new DateTime('2026-09-08 09:00:00')],
    ['ID' => 3, 'FECHA_CARGA' => new DateTime('2026-09-05 18:30:00')]
];

chequear('gana la mas reciente, este donde este en la lista',
    2, Saldos::ultimaCarga($cargas)['ID']);

// El caso que rompe un MAX(FECHA): dos cargas el MISMO DIA. Pasa cuando alguien
// se equivoca y vuelve a cargar. Tiene que ganar la segunda, siempre.
$mismoDia = [
    ['ID' => 7, 'FECHA_CARGA' => new DateTime('2026-09-08 09:15:00')],
    ['ID' => 8, 'FECHA_CARGA' => new DateTime('2026-09-08 17:40:00')]
];

chequear('con dos cargas el mismo dia gana la mas tardia',
    8, Saldos::ultimaCarga($mismoDia)['ID']);
chequear('y no depende del orden de la lista',
    8, Saldos::ultimaCarga(array_reverse($mismoDia))['ID']);

// Y el caso peor: dos cargas el mismo dia con la MISMA marca de tiempo, que es
// lo que pasa si la fecha viene truncada a dia. Desempata el ID, que es un
// IDENTITY: el mayor es el insertado despues.
$empate = [
    ['ID' => 11, 'FECHA_CARGA' => '2026-09-08'],
    ['ID' => 12, 'FECHA_CARGA' => '2026-09-08']
];

chequear('con la misma marca de tiempo desempata el ID',
    12, Saldos::ultimaCarga($empate)['ID']);
chequear('el desempate tampoco depende del orden',
    12, Saldos::ultimaCarga(array_reverse($empate))['ID']);

/* ================================================================
   Consolidacion por sucursal
   ================================================================ */
seccion('la consulta se consolida por sucursal');

$consultaMulti = [
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-06',
     'COD_CTA_CUENTA_TESORERIA' => '4001', 'SALDO_MONEDA' => 100000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-07',
     'COD_CTA_CUENTA_TESORERIA' => '4002', 'SALDO_MONEDA' => 50000]
];

$agrupado = Saldos::agruparPorSucursal($consultaMulti);

chequear('dos cuentas de la misma sucursal dan una sola fila', 1, count($agrupado));
chequear('los saldos se suman', 150000.0, floatval($agrupado[40]['saldo']));
chequear('se informa cuantas cuentas se sumaron', 2, $agrupado[40]['cuentas']);
chequear('queda la fecha mas reciente de las dos', '2026-09-07', $agrupado[40]['fecha_saldo']);

/* ================================================================
   La tabla de la pestana 2 y sus tres reglas
   ================================================================ */
seccion('que aporta cada local al cashflow');

// Cuatro locales que cubren los cuatro casos: deposita con caja de sobra,
// deposita con caja por debajo de la reserva (el caso 40 FLORES 1 del
// relevamiento), envia, y uno sin parametro configurado.
$consulta = [
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CABILDO', 'FECHA' => '2026-09-08',
     'COD_CTA_CUENTA_TESORERIA' => '1001', 'SALDO_MONEDA' => 1000000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-08',
     'COD_CTA_CUENTA_TESORERIA' => '4001', 'SALDO_MONEDA' => 80000],
    ['NRO_SUCURSAL' => 55, 'DESC_SUCURSAL' => 'SALTA', 'FECHA' => '2026-09-08',
     'COD_CTA_CUENTA_TESORERIA' => '5501', 'SALDO_MONEDA' => 600000],
    ['NRO_SUCURSAL' => 90, 'DESC_SUCURSAL' => 'NUEVA', 'FECHA' => '2026-09-08',
     'COD_CTA_CUENTA_TESORERIA' => '9001', 'SALDO_MONEDA' => 300000]
];

$params = [
    10 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 200000],
    40 => ['GESTION' => 'DEPOSITA', 'RESERVA' => 150000],
    55 => ['GESTION' => 'ENVIA',    'RESERVA' => 100000]
    // La 90 no esta: es una sucursal nueva sin configurar
];

$armado = Saldos::armarSaldosLocales($consulta, $params);
$porNro = [];

foreach ($armado['filas'] as $f) {
    $porNro[$f['nro_sucursal']] = $f;
}

// Regla 2: el neto es saldo menos reserva y NADA MAS. Sin 0,6% de impuesto al
// debito ni 4% de IIBB: el importe acreditado, ya neto, aparece solo en el
// saldo bancario de la pestana 1, y descontarlo aca lo contaria dos veces.
chequear('el neto es saldo menos reserva, sin ajuste impositivo',
    800000.0, floatval($porNro[10]['neto']));
chequear('y es exactamente lo que aporta',
    800000.0, floatval($porNro[10]['aporta']));

// Si hubiera algun ajuste impositivo escondido, este numero no seria redondo.
chequear('no hay ningun coeficiente aplicado sobre el neto',
    floatval($porNro[10]['saldo']) - floatval($porNro[10]['reserva']),
    floatval($porNro[10]['aporta']));

// Regla 3: un neto negativo aporta CERO, no negativo. La sucursal no le manda
// plata al banco por tener poca caja.
chequear('un neto negativo se muestra tal cual', -70000.0, floatval($porNro[40]['neto']));
chequear('pero aporta cero, no negativo', 0.0, floatval($porNro[40]['aporta']));
chequear('y deja aviso', true, strpos(implode(' ', $armado['avisos']), 'FLORES 1') !== false);

// Regla 1: las de Envia se muestran pero no entran a la serie.
chequear('un local en Envia se muestra en la tabla', true, isset($porNro[55]));
chequear('con su neto calculado igual', 500000.0, floatval($porNro[55]['neto']));
chequear('pero no aporta nada al cashflow', 0.0, floatval($porNro[55]['aporta']));

// Una sucursal sin parametro se toma como Deposita con reserva cero y se avisa,
// en vez de esconderla: esconderla informaria de menos en silencio.
chequear('una sucursal sin parametro se toma como Deposita',
    'DEPOSITA', $porNro[90]['gestion']);
chequear('con reserva cero', 0.0, floatval($porNro[90]['reserva']));
chequear('y queda marcada', true, $porNro[90]['sin_parametro']);

chequear('el total que aporta es la suma de los que depositan y superan su reserva',
    1100000.0, floatval($armado['totales']['aporta']));
chequear('el total de saldo en caja incluye a todos', 1980000.0,
    floatval($armado['totales']['saldo']));
chequear('se cuentan los que depositan', 3, $armado['totales']['depositan']);
chequear('y los que envian', 1, $armado['totales']['envian']);

seccion('la serie de caja de locales');

$serieLoc = Saldos::armarSerieLocales($armado['filas'], $h);

// Regla 4: el importe se imputa en la fecha del saldo consultado, sin
// corrimiento a dia habil ni tratamiento de feriados. El 8/9/2026 es martes;
// tampoco hay que moverlo si cae fin de semana.
chequear('el importe cae en la fecha del saldo, sin corrimientos',
    1100000.0, $serieLoc['dias']['2026-09-08']);
chequear('y en ninguna otra columna diaria',
    1100000.0, array_sum($serieLoc['dias']));
chequear('ni en las columnas mensuales', 0.0, floatval(array_sum($serieLoc['meses'])));
chequear('nada quedo fuera del horizonte', 0.0, floatval($serieLoc['fuera_horizonte']));
chequear('la serie esta en pesos', 'ARS', $serieLoc['moneda_origen']);

// Un fin de semana tampoco se corre: si la sucursal deposito el domingo, el
// movimiento quedo registrado el domingo.
$domingo = [[
    'nro_sucursal' => 10, 'fecha_saldo' => '2026-09-13', 'aporta' => 500000
]];

chequear('un saldo de domingo tampoco se corre al lunes',
    500000.0, Saldos::armarSerieLocales($domingo, $h)['dias']['2026-09-13']);

// Lo que cae afuera del eje se informa: un tablero que muestra de menos sin
// decirlo es peor que uno que falla.
$viejo = [[
    'nro_sucursal' => 10, 'fecha_saldo' => '2026-08-30', 'aporta' => 400000
]];

chequear('un saldo anterior al eje se informa como fuera de horizonte',
    400000.0, floatval(Saldos::armarSerieLocales($viejo, $h)['fuera_horizonte']));

/* ================================================================
   La serie del disponible inicial
   ================================================================ */
seccion('el saldo inicial no se repite en todas las columnas');

// El caso que este modulo tiene que evitar: la fila DISPONIBLE es de tipo
// SALDO_INICIAL y el motor toma lo que hay en CADA columna como aporte de esa
// columna al arrastre. Repetir el saldo sumaria la misma plata 28 veces.
$cuentas = [
    ['nombre' => 'Banco Galicia', 'moneda' => 'ARS', 'saldo' => 100000000,
     'fecha_saldo' => '2026-09-08'],
    ['nombre' => 'Efectivo Central', 'moneda' => 'ARS', 'saldo' => 57226313,
     'fecha_saldo' => '2026-09-08']
];

$disp = Saldos::armarSerieDisponible($cuentas, $h);
$serie = $disp['serie'];

chequear('el saldo esta en la columna de su fecha',
    157226313.0, $serie['dias']['2026-09-08']);
chequear('y en cero en TODAS las demas columnas diarias',
    157226313.0, array_sum($serie['dias']));
chequear('y en cero en todas las mensuales', 0.0, floatval(array_sum($serie['meses'])));

$enCero = 0;

foreach ($serie['dias'] as $fecha => $v) {
    if ($fecha !== '2026-09-08' && floatval($v) === 0.0) {
        $enCero++;
    }
}

chequear('las otras 27 columnas diarias estan en cero', 27, $enCero);

seccion('un saldo con fecha vieja abre el horizonte');

// A diferencia de la serie de locales, un saldo viejo NO se descarta: es la
// plata que hay hoy en la cuenta y es la apertura del horizonte. Se imputa en
// la primera columna con un aviso que dice de que fecha es.
$viejoSaldo = [[
    'nombre' => 'Banco Galicia', 'moneda' => 'ARS', 'saldo' => 80000000,
    'fecha_saldo' => '2026-09-01'
]];

$dispViejo = Saldos::armarSerieDisponible($viejoSaldo, $h);

chequear('se imputa en la primera columna del eje',
    80000000.0, $dispViejo['serie']['dias']['2026-09-06']);
chequear('no queda fuera del horizonte',
    0.0, floatval($dispViejo['serie']['fuera_horizonte']));
chequear('y se informa el importe reubicado', 80000000.0, floatval($dispViejo['reubicado']));
chequear('con un aviso que nombra la fecha real',
    true, strpos(implode(' ', $dispViejo['avisos']), '01/09/2026') !== false);

seccion('los dolares se valuan con la cotizacion de su mes');

$conDolares = [
    ['nombre' => 'Banco Galicia', 'moneda' => 'ARS', 'saldo' => 1000000,
     'fecha_saldo' => '2026-09-08'],
    ['nombre' => 'Banco Galicia USD', 'moneda' => 'USD', 'saldo' => 10000,
     'fecha_saldo' => '2026-09-08']
];

$dispUsd = Saldos::armarSerieDisponible($conDolares, $h, ['2026-09' => 1500]);

chequear('los pesos se suman tal cual y los dolares convertidos',
    16000000.0, $dispUsd['serie']['dias']['2026-09-08']);
chequear('se informa con que tipo de cambio se convirtio',
    1500.0, floatval($dispUsd['serie']['tipo_cambio']));

// Un mes sin cotizacion NO se valua a cero: un cero se leeria como "no hay
// dolares". Los dolares quedan afuera del tablero y se avisa el importe.
$dispSinTc = Saldos::armarSerieDisponible($conDolares, $h, []);

chequear('sin cotizacion los dolares no entran al tablero',
    1000000.0, $dispSinTc['serie']['dias']['2026-09-08']);
chequear('y no se valuan a cero en silencio',
    10000.0, floatval($dispSinTc['usd_sin_cotizar']));
chequear('el aviso dice el importe en dolares',
    true, strpos(implode(' ', $dispSinTc['avisos']), 'US$') !== false);
chequear('sin conversion, el tipo de cambio queda en null',
    null, $dispSinTc['serie']['tipo_cambio']);

seccion('totales por moneda, sin mezclar');

$totales = Saldos::totalesPorMoneda($conDolares);

chequear('el total en pesos es solo de las cuentas en pesos',
    1000000.0, floatval($totales['ARS']['total']));
chequear('el total en dolares queda en dolares',
    10000.0, floatval($totales['USD']['total']));
chequear('con su conteo de cuentas', 1, $totales['ARS']['cuentas']);

seccion('avisos sobre la antiguedad de la carga');

chequear('sin ninguna carga se avisa', 1,
    count(Saldos::avisosAntiguedad(null, 7, '2026-09-08')));
chequear('una carga de hace 2 dias con alerta a 7 no avisa', 0,
    count(Saldos::avisosAntiguedad('2026-09-06', 7, '2026-09-08')));
chequear('una de hace 10 dias si avisa', 1,
    count(Saldos::avisosAntiguedad('2026-08-29', 7, '2026-09-08')));

/* ================================================================
   Registro y proveedor
   ================================================================ */
seccion('el proveedor esta registrado bajo los dos codigos');

chequear('SALDOS esta disponible', true, CashflowRegistry::disponible('SALDOS'));
chequear('CAJA_LOCALES tambien', true, CashflowRegistry::disponible('CAJA_LOCALES'));
chequear('la serie DISPONIBLE existe', true,
    CashflowRegistry::serieExiste('SALDOS', 'DISPONIBLE'));
chequear('la serie DEPOSITOS existe', true,
    CashflowRegistry::serieExiste('CAJA_LOCALES', 'DEPOSITOS'));

$provSaldos = CashflowRegistry::instanciar('SALDOS');
$provLocales = CashflowRegistry::instanciar('CAJA_LOCALES');

chequear('SALDOS se instancia', true, $provSaldos instanceof CashflowProvider);
chequear('CAJA_LOCALES se instancia', true, $provLocales instanceof CashflowProvider);
chequear('y son la misma clase servida bajo dos codigos',
    get_class($provSaldos), get_class($provLocales));
chequear('cada instancia conoce su codigo', 'CAJA_LOCALES', $provLocales->codigo());

/* ================================================================
   Contra datos reales
   ================================================================ */
seccion('el proveedor contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

// Lo que se verifica aca no es el importe -depende de lo que haya cargado- sino
// que un modulo con las tablas sin crear o con el servidor de locales caido
// rinda ceros y avise, en lugar de tumbar el tablero.
foreach (['SALDOS' => 'DISPONIBLE', 'CAJA_LOCALES' => 'DEPOSITOS'] as $codigo => $nombreSerie) {
    $prov = CashflowRegistry::instanciar($codigo);
    $series = $prov->series($h);

    chequear("$codigo devuelve exactamente su serie", [$nombreSerie], array_keys($series));
    chequear("$codigo completa las claves diarias del eje",
        28, count($series[$nombreSerie]['dias']));
    chequear("$codigo completa las mensuales", 12, count($series[$nombreSerie]['meses']));

    $noNumericos = array_filter($series[$nombreSerie]['dias'], function ($v) {
        return !is_int($v) && !is_float($v);
    });

    chequear("$codigo devuelve solo numeros", 0, count($noNumericos));
}
