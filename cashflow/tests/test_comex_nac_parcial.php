<?php
/**
 * Crono Nacionalizacion: el pago parcial y el saldo pendiente.
 *
 * Las reglas son puras y se prueban sin base: el saldo
 * (Comex::saldoNacionalizacion()), lo que cada fila aporta a cada serie
 * (Comex::repartoNacionalizacion()), las series y su invariante
 * (ComexProvider::seriesNacionalizacion()), la validacion de un pago
 * (Comex::validarPagoNac()) y la clave del contenedor
 * (Comex::contenedorDe(), pagosPorContenedor(), copiaCostoReal()).
 */

require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Providers/ComexProvider.php';

$hoyNP = '2026-10-20';
$hNP = new Horizonte(10, 3, [], new DateTime($hoyNP));

/** Una fila de Crono, como sale de la consulta, con lo que se le pase */
function filaNP($extra = []) {
    return array_merge([
        'ID' => 1, 'GRUPO_ID' => 1, 'DUPLICA_GRUPO' => false,
        'IMPORTE_ARS' => 1000000.0, 'TIENE_COSTOS' => false, 'COSTO_REAL_ARS' => null,
        'NAC_PAGADO_ARS' => 0.0, 'NAC_PAGOS_CANT' => 0, 'NAC_FECHA_SALDO' => null,
        'FECHA_NAC_EFECTIVA' => '2026-10-25', 'PAGADO' => false
    ], $extra);
}

function repNP($extra = []) {
    return Comex::repartoNacionalizacion(filaNP($extra), $GLOBALS['hoyNP']);
}

/** NAC + PAGADAS - TODO en cada columna: tiene que dar cero en todas */
function descuadreNP($series) {
    $peor = 0.0;

    foreach (['dias', 'meses'] as $r) {
        foreach ($series['NACIONALIZACION_TODO'][$r] as $k => $todo) {
            $d = abs($series['NACIONALIZACION'][$r][$k] + $series['NACIONALIZACION_PAGADAS'][$r][$k] - $todo);
            $peor = max($peor, $d);
        }
    }

    return $peor;
}

/** Cuanto pone una serie en una fecha del tramo diario */
function enNP($serie, $fecha) {
    return isset($serie['dias'][$fecha]) ? round($serie['dias'][$fecha], 2) : null;
}

seccion('el saldo: base menos lo pagado, nunca negativo');

$s = Comex::saldoNacionalizacion(1000, 400);
chequear('saldo = base - pagado', 600.0, $s['saldo']);
chequear('lo imputado es la base menos el saldo', 400.0, $s['imputado']);
chequear('sin sobrepago', 0.0, $s['sobrepago']);
chequear('no cubierto', false, $s['cubierto']);

$s = Comex::saldoNacionalizacion(1000, 1300);
chequear('si se pago de mas el saldo es cero', 0.0, $s['saldo']);
chequear('y el sobrepago se informa en pesos', 300.0, $s['sobrepago']);
chequear('y queda cubierto', true, $s['cubierto']);

chequear('sin base no hay saldo: null y no cero', null, Comex::saldoNacionalizacion(null, 100)['saldo']);

seccion('la tolerancia es un peso, no un centavo de dolar');

chequear('medio peso de diferencia: cubierto', true, Comex::saldoNacionalizacion(1000, 999.50)['cubierto']);
chequear('un peso justo: cubierto', true, Comex::saldoNacionalizacion(1000, 999)['cubierto']);
chequear('un peso y medio: sigue habiendo saldo', false, Comex::saldoNacionalizacion(1000, 998.50)['cubierto']);
chequear('medio peso de mas no es sobrepago', 0.0, Comex::saldoNacionalizacion(1000, 1000.50)['sobrepago']);
chequear('no es la de Proveedores Exterior', true, Comex::TOLERANCIA_SALDO_NAC !== Comex::TOLERANCIA_SALDO);

seccion('la base: estimada o real');

$est = repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 250000]);
chequear('sin costos reales la base es la estimacion en pesos', 1000000.0, $est['BASE_ARS']);
chequear('marcada como estimada', 'ESTIMADO', $est['BASE_ORIGEN']);
chequear('saldo con base estimada', 750000.0, $est['SALDO_ARS']);

$real = repNP(['TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 800000, 'NAC_PAGOS_CANT' => 1,
               'NAC_PAGADO_ARS' => 250000]);
chequear('con costos reales la base es el costo real', 800000.0, $real['BASE_ARS']);
chequear('marcada como real', 'REAL', $real['BASE_ORIGEN']);
chequear('saldo con base real', 550000.0, $real['SALDO_ARS']);

seccion('varios pagos, y uno anulado');

$pagos = [
    ['ID_MG' => 7, 'IMPORTE_ARS' => 100000, 'VIGENTE' => 1],
    ['ID_MG' => 7, 'IMPORTE_ARS' => 50000, 'VIGENTE' => 1],
    ['ID_MG' => 7, 'IMPORTE_ARS' => 30000, 'VIGENTE' => 0]
];
$porC = Comex::pagosPorContenedor($pagos, [7 => null]);
chequear('varios pagos se suman', 150000.0, $porC[7]['ars']);
chequear('el anulado deja de restar y no cuenta', 2, $porC[7]['cant']);
chequear('el saldo con los dos vigentes', 850000.0,
    repNP(['NAC_PAGOS_CANT' => $porC[7]['cant'], 'NAC_PAGADO_ARS' => $porC[7]['ars']])['SALDO_ARS']);

seccion('pago en dolares: se resta el equivalente en pesos');

$usd = Comex::validarPagoNac(['fecha_pago' => '2026-10-10', 'moneda' => 'USD', 'importe_usd' => '1000',
    'cotizacion' => '1450,50', 'fecha_saldo' => '2026-11-30'], $hoyNP, '2026-10-25');
chequear('el equivalente en pesos lo calcula el servidor', 1450500.0, $usd['importe_ars']);
chequear('y guarda los dolares', 1000.0, $usd['importe_usd']);
chequear('y la cotizacion', 1450.50, $usd['cotizacion']);
chequearLanza('en dolares sin cotizacion, no', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-10', 'moneda' => 'USD', 'importe_usd' => 1000],
        '2026-10-20', '2026-10-25');
}, 'Falta la cotización con la que se pagaron los dólares.');

seccion('lo que se valida al registrar un pago');

chequearLanza('una fecha de pago futura se rechaza', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-21', 'moneda' => 'ARS', 'importe' => 100],
        '2026-10-20', '2026-10-25');
}, 'La fecha de pago no puede ser futura: un pago que todavía no se hizo no es un pago. '
    . 'Lo que falta se proyecta como saldo.');
chequearLanza('importe cero, no', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-20', 'moneda' => 'ARS', 'importe' => 0],
        '2026-10-20', '2026-10-25');
}, 'El importe tiene que ser mayor a cero.');
chequearLanza('nacionalizacion vencida y sin fecha del saldo: se rechaza', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-10', 'moneda' => 'ARS', 'importe' => 100],
        '2026-10-20', '2026-10-01');
}, 'La fecha de nacionalización ya venció: cargá la fecha en la que se va a pagar el saldo, si '
    . 'no lo que falta no entra en ninguna columna.');
chequearLanza('sin fecha de nacionalizacion, tambien', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-10', 'moneda' => 'ARS', 'importe' => 100],
        '2026-10-20', null);
});
chequearLanza('una fecha del saldo pasada, no', function () {
    Comex::validarPagoNac(['fecha_pago' => '2026-10-10', 'moneda' => 'ARS', 'importe' => 100,
        'fecha_saldo' => '2026-10-19'], '2026-10-20', '2026-10-25');
});

$sinFecha = Comex::validarPagoNac(['fecha_pago' => '2026-10-20', 'moneda' => 'ARS', 'importe' => '100'],
    $hoyNP, '2026-10-25');
chequear('no vencida y sin fecha del saldo: se acepta', null, $sinFecha['fecha_saldo']);
chequear('y el saldo cae en la fecha de nacionalizacion', '2026-10-25',
    repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 100])['FECHA_SALDO_EFECTIVA']);
chequear('con fecha del saldo, cae en esa', '2026-11-30',
    repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 100, 'NAC_FECHA_SALDO' => '2026-11-30'])['FECHA_SALDO_EFECTIVA']);

seccion('el tilde sigue siendo "pagado total"');

$filasT = [repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 300000, 'NAC_FECHA_SALDO' => '2026-10-28',
                  'PAGADO' => true])];
$serT = ComexProvider::seriesNacionalizacion($hNP, $filasT);
chequear('tildado con parciales: no suma nada', 0.0, array_sum($serT['NACIONALIZACION']['dias']));
chequear('el saldo va a PAGADAS, en la fecha del saldo', 700000.0,
    enNP($serT['NACIONALIZACION_PAGADAS'], '2026-10-28'));
chequear('y lo imputado tambien, en la de nacionalizacion', 300000.0,
    enNP($serT['NACIONALIZACION_PAGADAS'], '2026-10-25'));

$filasD = [repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 300000, 'NAC_FECHA_SALDO' => '2026-10-28'])];
$serD = ComexProvider::seriesNacionalizacion($hNP, $filasD);
chequear('destildado vuelve el saldo, en su fecha', 700000.0, enNP($serD['NACIONALIZACION'], '2026-10-28'));
chequear('y PAGADAS se queda con lo imputado', 300000.0,
    array_sum($serD['NACIONALIZACION_PAGADAS']['dias']));

seccion('pagos que cubren la base: se comporta como pagado');

$cub = repNP(['NAC_PAGOS_CANT' => 2, 'NAC_PAGADO_ARS' => 1000000]);
chequear('cubierto por pagos parciales', true, $cub['CUBIERTA_PARCIALES']);
chequear('no suma', 0.0, $cub['IMPORTE_EJE']);
chequear('no es una vencida a corregir aunque la fecha pase', false,
    Comex::vencidaPendiente(array_merge($cub, ['VENCIDA' => true])));
$serC = ComexProvider::seriesNacionalizacion($hNP, [$cub]);
chequear('la base entera va a PAGADAS', 1000000.0, array_sum($serC['NACIONALIZACION_PAGADAS']['dias']));

seccion('pagado de mas al pasar de estimado a real');

/* Se pago sobre la estimacion de $ 1.000.000; Comercio Exterior cargo los
   costos reales y dieron $ 900.000: quedan $ 100.000 pagados de mas. */
$baja = repNP(['TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 900000, 'NAC_PAGOS_CANT' => 1,
               'NAC_PAGADO_ARS' => 1000000]);
chequear('el saldo se muestra en cero', 0.0, $baja['SALDO_ARS']);
chequear('el sobrepago, en pesos', 100000.0, $baja['NAC_SOBREPAGO_ARS']);
chequear('no suma', 0.0, $baja['IMPORTE_EJE']);
$avBaja = Comex::avisosPagosParcialesConNivel([$baja]);
chequear('la pestana avisa "pagado de más", como atencion', true,
    $avBaja[1]['nivel'] === 'warning'
    && strpos($avBaja[1]['texto'], 'pagado de más: $ 100.000,00') !== false);
chequear('y lo emite la lista que tambien lee el tablero', true,
    strpos(file_get_contents(__DIR__ . '/../Class/Comex.php'),
        'self::avisosPagosParcialesConNivel($filas),') !== false
    && strpos(file_get_contents(__DIR__ . '/../Class/Providers/ComexProvider.php'),
        '$comex->avisosNacionalizacionConNivel($filas)') !== false);

$avInfo = Comex::avisosPagosParcialesConNivel([repNP(['NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 250000])]);
chequear('el informativo dice cuanto se pago y cuanto falta', 'info', $avInfo[0]['nivel']);
chequear('con el texto acordado', '1 contenedor(es) con pagos parciales: $ 250.000,00 pagados, '
    . '$ 750.000,00 de saldo pendiente.', $avInfo[0]['texto']);

seccion('los que tienen costos reales');

$sin = repNP(['TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 800000]);
chequear('sin pagos parciales no suman en ninguna serie', [0.0, 0.0, 0.0],
    [$sin['IMPORTE_EJE'], $sin['NAC_TODO_SALDO'], $sin['NAC_IMPUTADO_PROY']]);
chequear('y se esconden detras del interruptor', true, $sin['OCULTA_POR_COSTOS']);
chequear('y no son vencidas a corregir', false, Comex::vencidaPendiente(array_merge($sin, ['VENCIDA' => true])));
chequear('con un pago parcial y saldo, suman el saldo', 550000.0, $real['IMPORTE_EJE']);
chequear('y se ven siempre', false, $real['OCULTA_POR_COSTOS']);
chequear('cubiertos, no suman y vuelven a esconderse', true,
    repNP(['TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 800000, 'NAC_PAGOS_CANT' => 1,
           'NAC_PAGADO_ARS' => 800000])['OCULTA_POR_COSTOS']);

seccion('el invariante cierra columna por columna, con saldos en otra fecha');

$mezcla = [
    // Saldo en otra fecha, dentro del tramo diario.
    repNP(['ID' => 1, 'NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 300000, 'NAC_FECHA_SALDO' => '2026-10-28']),
    // Saldo en un mes del tramo mensual.
    repNP(['ID' => 2, 'NAC_PAGOS_CANT' => 2, 'NAC_PAGADO_ARS' => 100000, 'NAC_FECHA_SALDO' => '2026-12-15']),
    // Nacionalizacion vencida: lo imputado no suma, el saldo si, en su fecha.
    repNP(['ID' => 3, 'FECHA_NAC_EFECTIVA' => '2026-10-01', 'NAC_PAGOS_CANT' => 1,
           'NAC_PAGADO_ARS' => 200000, 'NAC_FECHA_SALDO' => '2026-10-27']),
    // Tildado, sin parciales.
    repNP(['ID' => 4, 'PAGADO' => true]),
    // Con costos reales y parcial.
    repNP(['ID' => 5, 'TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 500000, 'NAC_PAGOS_CANT' => 1,
           'NAC_PAGADO_ARS' => 100000, 'NAC_FECHA_SALDO' => '2026-10-29']),
    // Sin parciales, como siempre.
    repNP(['ID' => 6, 'FECHA_NAC_EFECTIVA' => '2026-10-26']),
    // Pagado de mas.
    repNP(['ID' => 7, 'NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 1200000]),
    // Saldo con la fecha vencida: no suma en ninguna.
    repNP(['ID' => 8, 'FECHA_NAC_EFECTIVA' => '2026-10-01', 'NAC_PAGOS_CANT' => 1,
           'NAC_PAGADO_ARS' => 200000, 'NAC_FECHA_SALDO' => '2026-10-05'])
];
$serM = ComexProvider::seriesNacionalizacion($hNP, $mezcla);
chequear('NAC + PAGADAS = TODO en todas las columnas', 0.0, descuadreNP($serM));
chequear('el saldo del 1 cae el 28', 700000.0 + 0.0, enNP($serM['NACIONALIZACION'], '2026-10-28'));
chequear('y lo imputado del 1 en su fecha de nacionalizacion, en TODO', true,
    enNP($serM['NACIONALIZACION_TODO'], '2026-10-25') >= 300000.0);
chequear('el saldo vencido del 8 no suma, y se ve como vencida', [0.0, true],
    [$mezcla[7]['IMPORTE_EJE'], Comex::vencidaPendiente($mezcla[7])]);

seccion('una sola clave: el contenedor');

chequear('una principal es su propio contenedor', 10, Comex::contenedorDe(10, [10 => null]));
chequear('una hija es el de su principal', 10, Comex::contenedorDe(11, [11 => 10]));
chequear('el costo real es la copia de la principal, no la suma del grupo', 10,
    Comex::copiaCostoReal(10, [10, 11, 12]));
chequear('si la principal no tiene copia, la de ID mas chico', 11, Comex::copiaCostoReal(10, [12, 11]));
chequear('sin copias, ninguna', null, Comex::copiaCostoReal(10, []));

/* Un grupo de principal + hija, las dos con su copia del detalle (Comex copia
   el detalle entero en cada OC): la base es UNA copia, y la hija no suma. */
$principal = repNP(['ID' => 10, 'GRUPO_ID' => 10, 'TIENE_COSTOS' => true, 'COSTO_REAL_ARS' => 53162889.39,
                    'NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 10000000, 'NAC_FECHA_SALDO' => '2026-10-28']);
$hija = repNP(['ID' => 11, 'GRUPO_ID' => 10, 'DUPLICA_GRUPO' => true, 'TIENE_COSTOS' => true,
               'COSTO_REAL_ARS' => 53162889.39, 'NAC_PAGOS_CANT' => 1, 'NAC_PAGADO_ARS' => 10000000,
               'NAC_FECHA_SALDO' => '2026-10-28']);
$serG = ComexProvider::seriesNacionalizacion($hNP, [$principal, $hija]);
chequear('el grupo proyecta el saldo una sola vez', 43162889.39,
    round(array_sum($serG['NACIONALIZACION']['dias']), 2));
chequear('la hija no aporta a ninguna serie', [0.0, 0.0, 0.0],
    [$hija['IMPORTE_EJE'], $hija['NAC_TODO_SALDO'], $hija['NAC_IMPUTADO_PROY']]);
chequear('y el invariante cierra igual', 0.0, descuadreNP($serG));

/* EL CASO DE LA OC QUE CAMBIA DE GRUPO. El pago se grabo contra la OC 20, que
   era principal. Despues, en Comercio Exterior, la 20 paso a ser hija de la 30:
   el pago sigue en el contenedor, que ahora es el de la 30. */
$antes = Comex::pagosPorContenedor([['ID_MG' => 20, 'IMPORTE_ARS' => 400000, 'VIGENTE' => 1]],
    [20 => null, 30 => null]);
$despues = Comex::pagosPorContenedor([['ID_MG' => 20, 'IMPORTE_ARS' => 400000, 'VIGENTE' => 1]],
    [20 => 30, 30 => null]);
chequear('antes de reagrupar, el pago es del contenedor 20', [20], array_keys($antes));
chequear('despues, del contenedor 30: se resuelve al leer', [30], array_keys($despues));
chequear('y aparece en su fila titular', 600000.0,
    repNP(['ID' => 30, 'GRUPO_ID' => 30, 'NAC_PAGOS_CANT' => $despues[30]['cant'],
           'NAC_PAGADO_ARS' => $despues[30]['ars']])['SALDO_ARS']);

$srcNP = file_get_contents(__DIR__ . '/../Class/Comex.php');
chequear('el SQL tambien resuelve el contenedor al leer, por el maestro', 2,
    substr_count($srcNP, '= " . self::OC_PRINCIPAL . ") NPG')
    + substr_count($srcNP, 'AND COALESCE(SE.ID_PADRE, SE.ID) = " . self::OC_PRINCIPAL . "'));
chequear('el historial del contenedor, igual', 2,
    substr_count($srcNP, 'WHERE COALESCE(NE.ID_PADRE, NE.ID) = ?')
    + substr_count($srcNP, 'WHERE COALESCE(SE.ID_PADRE, SE.ID) = ?'));
chequear('al grabar, contra la principal que resuelve el servidor', true,
    strpos($srcNP, '$principal = $this->principalDe(isset($datos[\'id_mg\']) ? $datos[\'id_mg\'] : 0);') !== false);

seccion('sin el script, nada cambia');

/* Sin las tablas no hay pagos (NAC_PAGOS_CANT = 0) ni filas con costos reales
   en la lista: repartoNacionalizacion() tiene que dar EXACTAMENTE lo que daban
   las dos reglas de antes. */
$casos = [
    filaNP(), filaNP(['PAGADO' => true]), filaNP(['FECHA_NAC_EFECTIVA' => '2026-10-01']),
    filaNP(['FECHA_NAC_EFECTIVA' => '2026-10-01', 'PAGADO' => true]), filaNP(['IMPORTE_ARS' => null]),
    filaNP(['FECHA_NAC_EFECTIVA' => null])
];
$iguales = true;

foreach ($casos as $c) {
    $c['VENCIDA'] = Comex::estaVencida($c['FECHA_NAC_EFECTIVA'], $hoyNP);
    $r = Comex::repartoNacionalizacion($c, $hoyNP);

    $iguales = $iguales
        && $r['IMPORTE_EJE'] === Comex::aporteAlEje($c)
        && $r['NAC_TODO_SALDO'] === Comex::importeProyectable($c)
        && $r['NAC_PAGADAS_SALDO'] === ($c['PAGADO'] ? Comex::importeProyectable($c) : 0.0)
        && $r['NAC_IMPUTADO_PROY'] === 0.0
        && $r['FECHA_SALDO_EFECTIVA'] === $c['FECHA_NAC_EFECTIVA']
        && $r['VENCIDA'] === $c['VENCIDA'];
}

chequear('sin pagos parciales, cada fila aporta lo mismo que antes', true, $iguales);
chequear('sin el script, Crono sigue dejando afuera los que tienen costos', true,
    strpos($srcNP, "(\$this->tieneNacPago() ? '1 = 1' : 'NOT ' . self::sqlTieneCostos())") !== false);
chequear('y la pestana dice que script falta', true,
    strpos($srcNP, 'sql/cashflow_comex_nac_pago.sql contra la base central y el botón aparece solo') !== false);

seccion('permisos: los mismos que el tilde');

require_once __DIR__ . '/../Class/AuthCashflow.php';
foreach (['registrarPagoNac', 'anularPagoNac', 'cambiarFechaSaldoNac'] as $accion) {
    chequear($accion . ' pide lo mismo que marcarPagado', AuthCashflow::ESCRITURAS['Comex']['marcarPagado'],
        AuthCashflow::ESCRITURAS['Comex'][$accion]);
}
chequear('el historial es una lectura', true, in_array('getPagosNac', AuthCashflow::LECTURAS['Comex'], true));

seccion('la pantalla');

$jsCrono = file_get_contents(__DIR__ . '/../Js/Comex-Crono_nacionalizacion.js');
$jsExt = file_get_contents(__DIR__ . '/../Js/Comex-Proveedores_exterior.js');
$jsFechas = file_get_contents(__DIR__ . '/../Js/Comex-fechas.js');
$tabCrono = file_get_contents(__DIR__ . '/../Tabs/crono_nacionalizacion.php');
$ayuda = file_get_contents(__DIR__ . '/../Components/help_modal_comex.php');

chequear('el tooltip "pagaste una parte" va en el tilde de NAC', true,
    strpos($jsCrono, "tooltip: 'Usalo cuando la nacionalización se pagó completa. Si pagaste una '") !== false);
chequear('y en Proveedores Exterior el tilde queda como esta', false,
    strpos($jsExt, 'tooltip:') !== false);
chequear('sin permiso de edicion no se dibuja el boton ni la anulacion', true,
    strpos($jsCrono, "Permisos.puedeEditar(document.querySelector('.tab-crono_nacionalizacion'))") !== false
    && strpos($jsCrono, 'if (puedePagoParcial() && item.BASE_ARS !== null) {') !== false
    && strpos($jsCrono, '(vigente && edita') !== false);
chequear('sin el script tampoco: el boton pide pago_parcial_editable', true,
    strpos($jsCrono, "return !!(datosCrono && datosCrono.pago_parcial_editable)") !== false);
foreach (['Costo ($)', 'Pagado ($)', 'Saldo ($)', 'Fecha del saldo'] as $col) {
    chequear('la tabla que se exporta tiene la columna ' . $col, true, strpos($tabCrono, $col) !== false);
}
chequear('el pie cubre las cuatro columnas nuevas', true, strpos($tabCrono, 'colspan="16"') !== false);
chequear('el interruptor "Ver con costos reales" pasa por el filtro compartido', true,
    strpos($jsCrono, "'verVencidasCronoNac', 'verPagadosCronoNac', idSwitchCostos());") !== false
    && strpos($jsFechas, 'if (!estado.conCostos && f.costos) {') !== false);
chequear('el encabezado del dialogo explica cuando usar el tilde', true,
    strpos($tabCrono, 'Registrá lo que pagaste. Lo que falta se sigue proyectando como saldo en la') !== false);
chequear('la ayuda tiene "Cómo registrar un pago", con su ejemplo', true,
    strpos($ayuda, 'Cómo registrar un pago') !== false && strpos($ayuda, '$ 6.000.000 el 30/11') !== false);
chequear('el tooltip de la marca real dice que la composicion cambia', true,
    strpos($jsCrono, 'Incluye el flete internacional y no los') !== false);
