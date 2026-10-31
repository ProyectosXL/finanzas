<?php
/**
 * El motor de cobranza de Ventas con el mix por mes (Ventas::cobranzaPorHojas()
 * con un arbol por mes de venta, y Ventas::avisosDelMixPorMes()).
 *
 * Lo que fija: que el mes que decide es el de la VENTA y no el de la
 * acreditacion, y EL INVARIANTE -con todos los canales en constante, la
 * cobranza es identica, celda por celda, a la de un solo arbol-.
 *
 * Sin base: la venta, el eje y el calendario se arman a mano.
 */

require_once __DIR__ . '/../Class/Ventas.php';

/** Una fila cruda de RO_T_CASHFLOW_VENTAS_MIX_NODO */
function nodoVM($id, $canal, $padre, $nivel, $nombre, $porc, $costo = null, $tasa = null,
                $dias = null, $activo = 1) {
    return ['ID' => $id, 'CANAL' => $canal, 'ID_PADRE' => $padre, 'NIVEL' => $nivel,
            'NOMBRE' => $nombre, 'PORCENTAJE' => $porc, 'COSTO' => $costo, 'TASA' => $tasa,
            'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo, 'ORDEN' => $id];
}

/** El constante: Locales 70% debito a 7 dias, 30% credito a 15 dias */
function arbolVM() {
    return MixCobro::normalizar([
        nodoVM(1, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 1.0),
        nodoVM(2, 'LOCALES', 1, 'TIPO_TARJETA', 'Débito', 0.7, 0.03, null, 7),
        nodoVM(3, 'LOCALES', 1, 'TIPO_TARJETA', 'Crédito', 0.3, 0.05, null, 15),
        nodoVM(4, 'LOCALES', 1, 'TIPO_TARJETA', 'Prepaga', 0.0, null, null, 3, 0),
        nodoVM(5, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 40),
        nodoVM(6, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 60),
        nodoVM(7, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 1.0),
        nodoVM(8, 'ECOMMERCE', 7, 'MEDIO_PAGO', 'Tarjeta', 1.0, null, null, 2)
    ]);
}

/**
 * Locales en $modo con diciembre cargado: 20% debito / 50% credito / 30%
 * prepaga, que en diciembre se enciende.
 */
function configVM($modo = 'VARIABLE') {
    $v = function ($nodo, $porc, $costo, $dias, $activo = 1) {
        return ['ID_MIX_MES' => 10, 'ID_NODO' => $nodo, 'PORCENTAJE' => $porc, 'COSTO' => $costo,
                'TASA' => null, 'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo];
    };

    return MixCobro::armarConfig(
        [['CANAL' => 'LOCALES', 'MODO' => $modo]],
        [['ID' => 10, 'CANAL' => 'LOCALES', 'MES' => 12, 'ACTIVO' => 1]],
        [$v(1, 1.0, null, null), $v(2, 0.2, 0.03, 7), $v(3, 0.5, 0.05, 15), $v(4, 0.3, null, 3)]
    );
}

/* ---- El escenario ----------------------------------------------------------
   Tramo diario del 23/11 al 06/12/2026; meses de noviembre a enero. Lunes a
   viernes habiles. Venta de Locales: 1.000 por dia del 23/11 al 31/12. */
$diasVM = [];
$cursor = new DateTime('2026-11-23');

for ($i = 0; $i < 14; $i++) {
    $diasVM[] = ['fecha' => $cursor->format('Y-m-d')];
    $cursor->modify('+1 day');
}

$mesesVM = [['clave' => '2026-11'], ['clave' => '2026-12'], ['clave' => '2027-01']];
$diasSetVM = array_flip(array_column($diasVM, 'fecha'));
$habilesVM = [];
$cursor = new DateTime('2026-11-01');

while ($cursor->format('Y-m-d') <= '2027-03-31') {
    $habilesVM[$cursor->format('Y-m-d')] = intval($cursor->format('N')) <= 5;
    $cursor->modify('+1 day');
}

$corrimientoVM = function ($fecha) use ($habilesVM) {
    return DiasHabiles::siguiente($fecha, $habilesVM)['fecha'];
};

$ventaVM = [];
$cursor = new DateTime('2026-11-23');

while ($cursor->format('Y-m-d') <= '2026-12-31') {
    $ventaVM[$cursor->format('Y-m-d')] = ['LOCALES' => 1000.0, 'FRANQUICIAS' => 0.0,
                                          'MAYORISTAS' => 0.0, 'ECOMMERCE' => 0.0];
    $cursor->modify('+1 day');
}

/** La cobranza del escenario con una venta, una config y los arboles por mes */
function cobranzaVM($venta, $config) {
    global $diasVM, $mesesVM, $diasSetVM, $corrimientoVM;

    $arboles = MixCobro::resolverPorMes(arbolVM(), $config, Ventas::mesesDeVenta($venta));

    // El de pantalla es el del primer mes de venta, como el del mes en curso
    // en el motor
    return Ventas::cobranzaPorHojas($venta, reset($arboles), $diasVM, $mesesVM, $diasSetVM, $corrimientoVM,
        $arboles);
}

// ============================================================================

seccion('el mes que decide es el de la venta, no el de la acreditacion');

// El lunes 30/11: el credito se acredita 15 dias despues, el martes 15/12.
$soloNov30 = ['2026-11-30' => ['LOCALES' => 1000.0]];
$c = cobranzaVM($soloNov30, configVM());

chequear('la venta del 30/11 se acredita en diciembre', 1000 * 0.3, $c['meses']['N3']['2026-12']);
chequear('con el 30% de credito de noviembre, no el 50% de diciembre', 300.0,
    $c['subtotal_meses']['LOCALES']['2026-12'] - $c['meses']['N2']['2026-12']);
chequear('y prepaga, que solo esta en juego en diciembre, ni siquiera es fila: no hay ventas de diciembre',
    false, isset($c['meses']['N4']));
chequear('su costo es el del credito de noviembre: 300 x 5%', 15.0, round($c['costo']['meses']['N3']['2026-12'], 6));

// El jueves 31/12, la misma cuenta del otro lado: mix de diciembre.
$c = cobranzaVM(['2026-12-31' => ['LOCALES' => 1000.0]], configVM());

chequear('la venta del 31/12 se acredita en enero con el mix de diciembre', [500.0, 200.0, 300.0],
    [$c['meses']['N3']['2027-01'], $c['meses']['N2']['2027-01'], $c['meses']['N4']['2027-01']]);

seccion('la cobranza de diciembre cambia, la de noviembre no');

$cons = cobranzaVM($ventaVM, MixCobro::configVacia());
$var = cobranzaVM($ventaVM, configVM());

chequear('los dias de noviembre del tramo: identicos', array_slice($cons['total_dias'], 0, 7, true),
    array_slice($var['total_dias'], 0, 7, true));
chequear('la cobranza de credito de las ventas de diciembre sube', true,
    $var['meses']['N3']['2027-01'] > $cons['meses']['N3']['2027-01']);
chequear('el total del horizonte es el mismo: el mix reparte la venta, no la cambia', true,
    abs($var['total_horizonte'] - $cons['total_horizonte']) < 1e-6);

// ============================================================================

seccion('el invariante: con todo en constante, la cobranza es identica');

$unArbol = Ventas::cobranzaPorHojas($ventaVM, MixCobro::resolver(arbolVM()), $diasVM, $mesesVM,
    $diasSetVM, $corrimientoVM);

chequear('sin config: identica a la de un solo arbol, al ultimo bit', true,
    $unArbol === cobranzaVM($ventaVM, MixCobro::configVacia()));
chequear('con meses cargados y el canal en constante: tambien', true,
    $unArbol === cobranzaVM($ventaVM, configVM('CONSTANTE')));
chequear('y sin por_mes: el payload es el de siempre', false,
    isset(cobranzaVM($ventaVM, configVM('CONSTANTE'))['por_mes']));

// ============================================================================

seccion('lo que se aplico a cada mes, para la pantalla');

chequear('con un mes propio, viaja por_mes', [11, 12], array_keys($var['por_mes']));
chequear('diciembre: credito al 50%, y Locales con mix propio', [0.5, true],
    [$var['por_mes'][12]['nodos']['N3']['porcentaje_efectivo'], $var['por_mes'][12]['canales']['LOCALES']['mes_propio']]);
chequear('noviembre: credito al 30%, con el constante', [0.3, false],
    [$var['por_mes'][11]['nodos']['N3']['porcentaje_efectivo'], $var['por_mes'][11]['canales']['LOCALES']['mes_propio']]);
chequear('prepaga es una fila: esta en juego en diciembre', true,
    in_array('N4', array_column($var['arbol'], 'clave'), true));
chequear('pero en noviembre no', false, $var['por_mes'][11]['nodos']['N4']['en_juego']);
chequear('sin el mix por mes, prepaga no se dibuja', false,
    in_array('N4', array_column($cons['arbol'], 'clave'), true));
chequear('los meses propios de cada canal', ['LOCALES' => [12], 'FRANQUICIAS' => [], 'MAYORISTAS' => [],
    'ECOMMERCE' => []], Ventas::mesesPropios(MixCobro::resolverPorMes(arbolVM(), configVM(), [11, 12])));
chequear('los meses del anio con venta', [11, 12], Ventas::mesesDeVenta($ventaVM));

// ============================================================================

seccion('los avisos de un mes invalido dicen el mes');

$ventaCanal = Ventas::ventaPorCanal($ventaVM);
$arbolesCons = MixCobro::resolverPorMes(arbolVM(), MixCobro::configVacia(), [11, 12]);

chequear('todo en constante: exactamente avisosDelMix()', Ventas::avisosDelMix($arbolesCons[11], $ventaCanal, 'NODO'),
    Ventas::avisosDelMixPorMes($arbolesCons, $ventaVM, 'NODO'));

// Diciembre roto por una edicion directa: los activos suman 80%
$cfgRota = configVM();
$cfgRota['meses']['LOCALES'][12]['valores'][3]['PORCENTAJE'] = 0.3;
$avisos = Ventas::avisosDelMixPorMes(MixCobro::resolverPorMes(arbolVM(), $cfgRota, [11, 12]), $ventaVM, 'NODO');
$textos = array_column($avisos, 'texto');

chequear('un aviso, critico', [1, Aviso::DANGER], [count($avisos), $avisos[0]['nivel']]);
chequear('empieza por el mes', 0, strpos($textos[0], 'Diciembre: Los medios en juego de Locales › Tarjeta suman 80,00%'));
chequear('con la plata de la venta de diciembre: 31 dias x 1.000 x 20%', true,
    strpos($textos[0], '$ 6.200,00') !== false);
chequear('y dice donde corregirlo', true, strpos($textos[0], 'Mix de Cobro y Plazos, en Diciembre.') !== false);

// Un error del constante se dice una vez, con la venta de todos los meses que lo usan
$arbolSinDias = MixCobro::normalizar(array_map(function ($n) {
    return $n['ID'] === 6 ? array_merge($n, ['DIAS_ACREDITACION' => null]) : $n;
}, arbolVM()));
$ventaMay = array_map(function ($v) { return array_merge($v, ['MAYORISTAS' => 100.0]); }, $ventaVM);
$avisosC = Ventas::avisosDelMixPorMes(MixCobro::resolverPorMes($arbolSinDias, configVM(), [11, 12]), $ventaMay, 'NODO');

chequear('un error del constante va una sola vez, sin mes', [1, false],
    [count($avisosC), strpos($avisosC[0]['texto'], 'Diciembre') !== false]);
chequear('con la venta de toda la ventana: 39 dias x 100', true, strpos($avisosC[0]['texto'], '$ 3.900,00') !== false);

seccion('Cobranza Proyectada muestra el arbol de cada mes, sin recargar');

$jsVM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Ingresos-Ventas.js'));
$tabVM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Tabs/ventas.php'));
$claseVM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Ventas.php'));

chequear('el contenedor del selector, fuera de la tabla', true,
    strpos($tabVM, '<div id="cobMixMes"') !== false && strpos($tabVM, '<div id="cobMixMes"') < strpos($tabVM, '<table id="tablaCobranza"'));
chequear('cambiar de mes redibuja con lo que ya vino: no pide nada', true,
    strpos($jsVM, "mesCobranza = parseInt(e.target.value, 10);\n            generarTablaCobranza();") !== false);
chequear('las columnas descriptivas salen del mes elegido', true,
    strpos($jsVM, 'var d = delMes ? Object.assign({}, n, delMes.datos.nodos[n.clave]) : n;') !== false);
chequear('sin por_mes, el selector no aparece', true, strpos($jsVM, "if (!porMes) {\n            cont.style.display = 'none';") !== false);
chequear('el motor manda el mes en curso y los meses propios', [1, 1], [
    substr_count($claseVM, "'meses_propios' => self::mesesPropios(\$arboles),"),
    substr_count($claseVM, "'mes_hoy' => \$mesHoy,")
]);
