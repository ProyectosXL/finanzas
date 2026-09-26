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

require_once __DIR__ . '/../Class/Saldos.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';

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

// Las filas crudas vienen de RO_T_SALDOS_CIERRE_SBA29: el importe es el saldo
// de CIERRE (SALDO_CIER), no el de apertura.
$consultaMulti = [
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-06',
     'COD_CTA' => '4001', 'SALDO_CIER' => 100000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-07',
     'COD_CTA' => '4002', 'SALDO_CIER' => 50000]
];

$agrupado = Saldos::agruparPorSucursal($consultaMulti);

chequear('dos cuentas de la misma sucursal dan una sola fila', 1, count($agrupado));
chequear('los saldos se suman', 150000.0, floatval($agrupado[40]['saldo']));
chequear('se informa cuantas cuentas se sumaron', 2, $agrupado[40]['cuentas']);
chequear('queda la fecha mas reciente de las dos', '2026-09-07', $agrupado[40]['fecha_saldo']);

// Si la fila trajera tambien la apertura, se ignora: lo que hay para depositar
// es lo que quedo al cerrar.
$conApertura = [
    ['NRO_SUCURSAL' => 2, 'DESC_SUCURSAL' => 'UNICENTER', 'FECHA' => '2026-09-13',
     'COD_CTA' => '100102', 'SALDO_APE' => 2361000, 'SALDO_CIER' => 3394600]
];

chequear('el saldo es el de cierre, no el de apertura',
    3394600.0, floatval(Saldos::agruparPorSucursal($conApertura)[2]['saldo']));

/* ================================================================
   La tabla de la pestana 2 y sus tres reglas
   ================================================================ */
seccion('que aporta cada local al cashflow');

// Cuatro locales que cubren los cuatro casos: deposita con caja de sobra,
// deposita con caja por debajo de la reserva (el caso 40 FLORES 1 del
// relevamiento), envia, y uno sin parametro configurado.
$consulta = [
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CABILDO', 'FECHA' => '2026-09-08',
     'COD_CTA' => '1001', 'SALDO_CIER' => 1000000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-08',
     'COD_CTA' => '4001', 'SALDO_CIER' => 80000],
    ['NRO_SUCURSAL' => 55, 'DESC_SUCURSAL' => 'SALTA', 'FECHA' => '2026-09-08',
     'COD_CTA' => '5501', 'SALDO_CIER' => 600000],
    ['NRO_SUCURSAL' => 90, 'DESC_SUCURSAL' => 'NUEVA', 'FECHA' => '2026-09-08',
     'COD_CTA' => '9001', 'SALDO_CIER' => 300000]
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

/* ================================================================
   El saldo tipeado a mano cuando la consulta no trajo el cierre
   ================================================================ */
seccion('un saldo manual manda si es igual o mas nuevo que el de la consulta');

chequear('ayer es el dia anterior', '2026-09-13', Saldos::ayer('2026-09-14'));
chequear('y cruza el mes', '2026-08-31', Saldos::ayer('2026-09-01'));

// Cuatro locales el 14/9: la consulta trajo el cierre del 13/9 para 10 y 55,
// pero 40 quedo en el 10/9 (no viajo) y 90 quedo en el 12/9.
$consultaVieja = [
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CABILDO', 'FECHA' => '2026-09-13',
     'COD_CTA' => '1001', 'SALDO_CIER' => 1000000],
    ['NRO_SUCURSAL' => 40, 'DESC_SUCURSAL' => 'FLORES 1', 'FECHA' => '2026-09-10',
     'COD_CTA' => '4001', 'SALDO_CIER' => 80000],
    ['NRO_SUCURSAL' => 55, 'DESC_SUCURSAL' => 'SALTA', 'FECHA' => '2026-09-13',
     'COD_CTA' => '5501', 'SALDO_CIER' => 600000],
    ['NRO_SUCURSAL' => 90, 'DESC_SUCURSAL' => 'NUEVA', 'FECHA' => '2026-09-12',
     'COD_CTA' => '9001', 'SALDO_CIER' => 300000]
];

$manuales = [
    // Mas nuevo que la consulta: manda
    40 => ['NRO_SUCURSAL' => 40, 'FECHA_SALDO' => '2026-09-13', 'SALDO_MONEDA' => 250000,
           'FECHA_UPDATE' => '2026-09-14 09:00:00', 'USUARIO' => 'ana'],
    // Misma fecha que la consulta: manda igual, porque alguien lo tipeo
    55 => ['NRO_SUCURSAL' => 55, 'FECHA_SALDO' => '2026-09-13', 'SALDO_MONEDA' => 650000],
    // Mas viejo que la consulta: la consulta ya lo supero
    10 => ['NRO_SUCURSAL' => 10, 'FECHA_SALDO' => '2026-09-11', 'SALDO_MONEDA' => 1],
    // De un local que la consulta no devuelve: no inventa la fila
    77 => ['NRO_SUCURSAL' => 77, 'FECHA_SALDO' => '2026-09-13', 'SALDO_MONEDA' => 999]
];

$conManuales = Saldos::armarSaldosLocales($consultaVieja, $params, $manuales, '2026-09-13');
$porNroM = [];

foreach ($conManuales['filas'] as $f) {
    $porNroM[$f['nro_sucursal']] = $f;
}

chequear('un manual mas nuevo que la consulta manda', 250000.0, floatval($porNroM[40]['saldo']));
chequear('con su fecha', '2026-09-13', $porNroM[40]['fecha_saldo']);
chequear('y queda marcado como manual', 'MANUAL', $porNroM[40]['origen_saldo']);
chequear('conservando lo que decia la consulta', 80000.0, floatval($porNroM[40]['saldo_consulta']));
chequear('y de cuando era', '2026-09-10', $porNroM[40]['fecha_consulta']);
chequear('con quien lo cargo', 'ana', $porNroM[40]['manual']['usuario']);

chequear('a igual fecha gana el manual', 650000.0, floatval($porNroM[55]['saldo']));
chequear('y tambien queda marcado', 'MANUAL', $porNroM[55]['origen_saldo']);

chequear('un manual mas viejo que la consulta no manda', 1000000.0, floatval($porNroM[10]['saldo']));
chequear('y la fila queda como de la consulta', 'CONSULTA', $porNroM[10]['origen_saldo']);
chequear('sin dato de manual', null, $porNroM[10]['manual']);

chequear('un manual de un local que la consulta no devuelve no inventa la fila',
    false, isset($porNroM[77]));

// El neto y el aporte se calculan sobre el saldo EFECTIVO: es lo que el
// tablero va a usar, y es el motivo de poder tipearlo.
chequear('el neto sale del saldo efectivo', 100000.0, floatval($porNroM[40]['neto']));
chequear('y el aporte tambien', 100000.0, floatval($porNroM[40]['aporta']));
chequear('el total de caja usa los saldos efectivos', 2200000.0,
    floatval($conManuales['totales']['saldo']));
chequear('se cuentan los manuales', 2, $conManuales['totales']['manuales']);

seccion('un local cuyo saldo no es el de ayer queda marcado');

// Ayer es el 13/9: 10, 40 (por el manual) y 55 estan al dia; 90 quedo en el 12/9.
chequear('un saldo de ayer no esta desactualizado', false, $porNroM[10]['desactualizado']);
chequear('un manual de ayer tampoco', false, $porNroM[40]['desactualizado']);
chequear('un saldo anterior a ayer si', true, $porNroM[90]['desactualizado']);
chequear('se cuentan', 1, $conManuales['totales']['desactualizados']);
chequear('y se avisa con el local y su fecha', true,
    strpos(implode(' ', $conManuales['avisos']), '90 NUEVA (12/09/2026)') !== false);
chequear('diciendo que se puede cargar a mano', true,
    strpos(implode(' ', $conManuales['avisos']), 'a mano') !== false);

// Sin la fecha de referencia no se marca nada: el tablero de un dia pasado no
// tiene "ayer".
$sinAyer = Saldos::armarSaldosLocales($consultaVieja, $params, $manuales);

chequear('sin fecha de referencia nada queda desactualizado',
    0, $sinAyer['totales']['desactualizados']);

// Un saldo de HOY -la consulta corrio con el cierre de hoy- no esta
// desactualizado: es mas nuevo que ayer.
$deHoy = Saldos::armarSaldosLocales([
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CABILDO', 'FECHA' => '2026-09-14',
     'COD_CTA' => '1001', 'SALDO_CIER' => 1]
], [], [], '2026-09-13');

chequear('un saldo mas nuevo que ayer no esta desactualizado',
    false, $deHoy['filas'][0]['desactualizado']);

// Sin fecha tampoco se sabe de cuando es: cuenta como desactualizado.
$sinFecha = Saldos::armarSaldosLocales([
    ['NRO_SUCURSAL' => 10, 'DESC_SUCURSAL' => 'CABILDO', 'FECHA' => null,
     'COD_CTA' => '1001', 'SALDO_CIER' => 1]
], [], [], '2026-09-13');

chequear('un saldo sin fecha cuenta como desactualizado',
    true, $sinFecha['filas'][0]['desactualizado']);

seccion('solo se guarda como manual un saldo distinto del que manda');

// La pantalla manda TODOS los locales con su saldo. Sin el diff, cada guardado
// insertaria un manual por local y la consulta no volveria a mandar nunca.
$tipeados = [
    // Igual al efectivo (que es el manual del 13/9): no es nuevo
    ['nro_sucursal' => 40, 'gestion' => 'DEPOSITA', 'reserva' => 150000, 'saldo' => 250000],
    // Distinto del efectivo (la consulta): nuevo
    ['nro_sucursal' => 90, 'gestion' => 'DEPOSITA', 'reserva' => 0, 'saldo' => 320000],
    // Igual con diferencia de un centavo por el input: no es nuevo
    ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => 200000, 'saldo' => 1000000.004],
    // Sin saldo (pantalla vieja): no es nuevo
    ['nro_sucursal' => 55, 'gestion' => 'ENVIA', 'reserva' => 100000, 'saldo' => null],
    // Un local que no esta en la tabla: se ignora
    ['nro_sucursal' => 77, 'gestion' => 'DEPOSITA', 'reserva' => 0, 'saldo' => 5]
];

$nuevos = Saldos::saldosManualesNuevos($conManuales['filas'], $tipeados);

chequear('solo el saldo distinto del efectivo es nuevo', 1, count($nuevos));
chequear('y es el del local 90', 90, $nuevos[0]['nro_sucursal']);
chequear('con el importe tipeado', 320000.0, $nuevos[0]['saldo']);
chequear('y lo que decia la consulta, para poder explicarlo', 300000.0,
    $nuevos[0]['saldo_consulta']);
chequear('con su fecha', '2026-09-12', $nuevos[0]['fecha_consulta']);

chequear('sin ningun saldo tipeado no hay manuales nuevos', 0,
    count(Saldos::saldosManualesNuevos($conManuales['filas'], $sinTocarManual = [
        ['nro_sucursal' => 40, 'gestion' => 'DEPOSITA', 'reserva' => 150000]
    ])));

chequearLanza('un saldo negativo se rechaza', function () use ($conManuales) {
    Saldos::saldosManualesNuevos($conManuales['filas'],
        [['nro_sucursal' => 10, 'saldo' => -5]]);
}, 'El saldo en caja del local 10 no puede ser negativo');

chequearLanza('un saldo que no es numero se rechaza', function () use ($conManuales) {
    Saldos::saldosManualesNuevos($conManuales['filas'],
        [['nro_sucursal' => 10, 'saldo' => 'mucho']]);
}, 'El saldo en caja del local 10 no es un número');

seccion('editar gestion y reserva en la pestana guarda el parametro');

// La pantalla manda las 20 sucursales en cada guardado, no solo las que se
// tocaron. Sin el diff, cada guardado le pisaria FECHA_UPDATE y USUARIO a todas
// y la columna "Ultima edicion" de Parametros dejaria de significar algo.
$vigentes = [
    10 => ['NRO_SUCURSAL' => 10, 'GESTION' => 'DEPOSITA', 'RESERVA' => 200000],
    40 => ['NRO_SUCURSAL' => 40, 'GESTION' => 'DEPOSITA', 'RESERVA' => 150000],
    55 => ['NRO_SUCURSAL' => 55, 'GESTION' => 'ENVIA',    'RESERVA' => 100000]
];

$sinTocar = [
    ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => 200000],
    ['nro_sucursal' => 40, 'gestion' => 'DEPOSITA', 'reserva' => 150000],
    ['nro_sucursal' => 55, 'gestion' => 'ENVIA',    'reserva' => 100000]
];

$r = Saldos::resolverOverrides($vigentes, $sinTocar);

chequear('reenviar los mismos valores no cuenta como cambio', 0, count($r['cambios']));

$tocados = [
    ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => 300000],  // cambia reserva
    ['nro_sucursal' => 40, 'gestion' => 'ENVIA',    'reserva' => 150000],  // cambia gestion
    ['nro_sucursal' => 55, 'gestion' => 'ENVIA',    'reserva' => 100000]   // igual
];

$r = Saldos::resolverOverrides($vigentes, $tocados);

chequear('solo se escriben los que cambiaron', 2, count($r['cambios']));
chequear('el primero es el de la reserva nueva', 10, $r['cambios'][0]['nro_sucursal']);
chequear('con el valor nuevo', 300000.0, floatval($r['cambios'][0]['reserva']));
chequear('el segundo es el de la gestion nueva', 'ENVIA', $r['cambios'][1]['gestion']);

// El valor efectivo de la carga es el que quedo, no el vigente de antes: es lo
// que hace reproducible la foto del historico.
chequear('el parametro resultante lleva el valor nuevo',
    300000.0, floatval($r['params'][10]['RESERVA']));

// Una sucursal que la consulta devuelve pero que nunca se sincronizo no tiene
// parametro: editarla es un cambio, y el UPSERT le crea la fila. Si esto no
// contara como cambio, el UPDATE no afectaria nada y la edicion se perderia en
// silencio.
$r = Saldos::resolverOverrides([], [
    ['nro_sucursal' => 90, 'gestion' => 'ENVIA', 'reserva' => 50000]
]);

chequear('una sucursal sin parametro cuenta como cambio', 1, count($r['cambios']));

// La reserva da la vuelta por JSON y por un input numerico contra una columna
// DECIMAL(19,4): una comparacion estricta reportaria cambios que no existen.
$r = Saldos::resolverOverrides(
    [10 => ['NRO_SUCURSAL' => 10, 'GESTION' => 'DEPOSITA', 'RESERVA' => '200000.0000']],
    [['nro_sucursal' => 10, 'gestion' => 'deposita', 'reserva' => '200000']]
);

chequear('el mismo importe escrito distinto no es un cambio', 0, count($r['cambios']));

// Los valores invalidos cortan ANTES de abrir la transaccion de la carga.
chequearLanza('una gestion invalida no se guarda', function () use ($vigentes) {
    Saldos::resolverOverrides($vigentes, [
        ['nro_sucursal' => 10, 'gestion' => 'CUALQUIERA', 'reserva' => 0]
    ]);
});

chequearLanza('una reserva negativa tampoco', function () use ($vigentes) {
    Saldos::resolverOverrides($vigentes, [
        ['nro_sucursal' => 10, 'gestion' => 'DEPOSITA', 'reserva' => -1]
    ]);
});

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

// El caso que se veia en cero teniendo plata para depositar: la consulta trae
// el saldo de caja de AYER, porque es el ultimo que Tango registro. Esa plata
// sigue en el cajon del local y no llego al banco, asi que se imputa en la
// apertura del horizonte y no se descarta. Reubicar no es el corrimiento que el
// relevamiento prohibe: eso era mover una fecha DENTRO del eje por dia habil.
$ayer = [[
    'nro_sucursal' => 10, 'fecha_saldo' => '2026-09-05', 'aporta' => 400000
]];

$serieAyer = Saldos::armarSerieLocales($ayer, $h);

chequear('un saldo de caja anterior al eje se imputa en la primera columna',
    400000.0, $serieAyer['dias']['2026-09-06']);
chequear('y no se descarta como fuera de horizonte',
    0.0, floatval($serieAyer['fuera_horizonte']));
chequear('con un aviso que dice de que fecha es el saldo',
    true, strpos(implode(' ', $serieAyer['warnings']), '05/09/2026') !== false);

// Lo que cae DESPUES del eje si queda afuera: es una fecha futura que el
// horizonte no cubre, no un dato que ya es cierto hoy.
$futuro = [[
    'nro_sucursal' => 10, 'fecha_saldo' => '2028-01-15', 'aporta' => 400000
]];

chequear('un saldo posterior al eje si se informa como fuera de horizonte',
    400000.0, floatval(Saldos::armarSerieLocales($futuro, $h)['fuera_horizonte']));

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

// El enlace del tablero tiene que llevar a la sub-pestana que produjo el
// numero, no a la primera de la pestana.
chequear('CAJA_LOCALES apunta a la pestana Saldos',
    'saldos', CashflowRegistry::meta('CAJA_LOCALES')['tab']);
chequear('y a su sub-pestana de locales',
    'locales', CashflowRegistry::meta('CAJA_LOCALES')['subtab']);
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
