<?php
/**
 * La pestaña Financiero › Prestamos (Class/Prestamos.php): la lista, la
 * proyeccion, las tarjetas, la cancelacion simulada, los estados y el
 * cableado (menu, permisos, endpoint).
 *
 * SIN BASE: los resultados se arman con PrestamosCalculo (puro) y HOY FIJO
 * (25/11/2026).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. LO ANTERIOR A HOY SE PROYECTA y se cuenta dos veces con el saldo del
 *      banco.
 *   2. UN SIMULADO SE CUELA en el oficial (o no aparece con el interruptor).
 *   3. LA CANCELACION SIMULADA dice un ahorro que no es.
 *   4. SIN PERMISO se dibujan los controles de edicion.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Prestamos.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Menu.php';

const PP_HOY = '2026-11-25';

$h = new Horizonte(28, 12, [], new DateTime(PP_HOY));

/** Un resultado como lo arma Prestamos::calcular(), con el calculo de verdad. */
function ppRes($id, $p, $extra = []) {
    $p = $p + ['id' => $id, 'nombre' => 'P' . $id, 'moneda' => 'ARS', 'capital' => 1200000.0, 'fecha_desembolso' => '2026-11-25',
               'tipo_tasa' => 'FIJA', 'tna' => 36.0, 'sistema' => 'ALEMAN', 'cuotas' => 12, 'frec_capital' => 'MENSUAL',
               'frec_intereses' => 'MENSUAL', 'gracia' => 0, 'estado' => 'VIGENTE', 'cuenta_nombre' => 'Galicia (ARS)',
               'criterio_tasa' => 'PROMEDIO', 'referencia' => null, 'spread' => null, 'observacion' => null,
               'cancelacion' => ['modo' => 'FIJA', 'pct_total' => 3, 'pct_parcial' => 3, 'iva' => true, 'tramos' => []]];
    $d = ['prestamo' => $p, 'costos' => $extra['costos'] ?? [], 'ajustes' => [], 'cancelaciones' => $extra['cancelaciones'] ?? []];
    $r = PrestamosCalculo::proyectar(['hoy' => PP_HOY, 'prestamo' => $p, 'costos' => $d['costos'], 'iva' => 0.21, 'ajustes' => [],
        'cancelaciones' => $d['cancelaciones'], 'tasas' => [], 'habiles' => [], 'curva' => $extra['curva'] ?? []]);

    return ['datos' => $d, 'r' => $r];
}

$resultados = [
    1 => ppRes(1, ['fecha_desembolso' => '2026-09-25']),
    2 => ppRes(2, ['estado' => 'SIMULADO', 'fecha_desembolso' => '2026-12-10', 'capital' => 600000.0, 'cuotas' => 6]),
    3 => ppRes(3, ['fecha_desembolso' => '2025-01-15'])
];
$calc = ['resultados' => $resultados, 'avisos' => [], 'avisos_tasas' => [],
         'avisos_por' => [1 => [], 2 => [Aviso::nuevo(Aviso::WARNING, 'aviso del simulado')], 3 => []]];

seccion('los pagos del tablero: lo anterior a hoy no va, lo de hoy es real');
$pagos = Prestamos::pagosTablero($resultados, PP_HOY, false);
chequear('ninguno anterior a hoy', 0, count(array_filter($pagos, function ($p) { return $p['fecha'] < PP_HOY; })));
chequear('la cuota de hoy (25/11) entra como real', true, count(array_filter($pagos, function ($p) { return $p['fecha'] === PP_HOY && $p['real']; })) > 0);
chequear('el desembolso del 25/09 no va', 0, count(array_filter($pagos, function ($p) { return $p['concepto'] === 'DESEMBOLSO'; })));
chequear('el finalizado no aporta nada', 0, count(array_filter($pagos, function ($p) { return $p['prestamo'] === 3; })));
chequear('el simulado, con el interruptor apagado, fuera', 0, count(array_filter($pagos, function ($p) { return $p['prestamo'] === 2; })));
$conSim = Prestamos::pagosTablero($resultados, PP_HOY, true);
chequear('prendido, adentro', true, count(array_filter($conSim, function ($p) { return $p['prestamo'] === 2; })) > 0);
$soloUno = Prestamos::pagosTablero($resultados, PP_HOY, [2]);
chequear('con una lista de ids, solo esos simulados', count($conSim), count($soloUno));
chequear('un vigente entra siempre', true, Prestamos::incluido(['estado' => 'VIGENTE', 'id' => 9], false));
chequear('un simulado que no esta en la lista no', false, Prestamos::incluido(['estado' => 'SIMULADO', 'id' => 9], [2]));
chequear('los avisos de un simulado no llegan al tablero con el interruptor apagado', [], Prestamos::avisosTablero($calc, false));
chequear('prendido, si', 1, count(Prestamos::avisosTablero($calc, true)));

seccion('la lista');
$lista = Prestamos::armarLista($calc, ['DIAS_PROMEDIO' => 5], PP_HOY);
$por = [];
foreach ($lista as $l) { $por[$l['id']] = $l; }
chequear('tres prestamos', 3, count($lista));
chequear('estados: vigente, simulado, finalizado', ['VIGENTE', 'SIMULADO', 'FINALIZADO'], [$por[1]['estado'], $por[2]['estado'], $por[3]['estado']]);
chequear('el finalizado se llama Finalizado', 'Finalizado', $por[3]['nombre_estado']);
chequear('solo el simulado se elimina', [false, true, false], [$por[1]['puede_eliminar'], $por[2]['puede_eliminar'], $por[3]['puede_eliminar']]);
chequear('solo el vigente se cancela', [true, false, false], [$por[1]['puede_cancelar'], $por[2]['puede_cancelar'], $por[3]['puede_cancelar']]);
chequear('solo el simulado pasa a vigente', [false, true, false], [$por[1]['puede_vigente'], $por[2]['puede_vigente'], $por[3]['puede_vigente']]);
chequear('saldo a hoy: 1.200.000 - 2 cuotas', 1000000.0, $por[1]['saldo_hoy']);
chequear('proxima cuota: 25/12', '2026-12-25', $por[1]['proxima']['fecha']);
chequear('tasa fija en texto', 'Fija 36,00 %', $por[1]['tasa']);
chequear('trae el cronograma', 13, count($por[1]['cronograma']));
chequear('un fijo no lleva criterio de proyeccion', null, $por[1]['criterio']);
chequear('un variable si', 'Proyección calculada asumiendo constante la tasa de referencia (TAMAR) promedio de los últimos 5 días hábiles.',
    Prestamos::criterio(['tipo_tasa' => 'VARIABLE', 'referencia' => 'TAMAR', 'criterio_tasa' => 'PROMEDIO'], 5));
chequear('o con el ultimo valor', 'Proyección calculada asumiendo constante el último valor publicado de BADLAR.',
    Prestamos::criterio(['tipo_tasa' => 'VARIABLE', 'referencia' => 'BADLAR', 'criterio_tasa' => 'ULTIMO'], 5));
chequear('el filtro de estados incluye Finalizado', true, isset(Prestamos::listas()['estados']['FINALIZADO']));

seccion('la proyeccion: prestamo -> concepto, oficial y con simulados');
$pr = Prestamos::armarProyeccion($resultados, $h, PP_HOY);
$ids = array_column($pr['filas'], 'id');
chequear('el vigente con capital e intereses', true, in_array('C|1|CAPITAL', $ids, true) && in_array('C|1|INTERESES', $ids, true));
chequear('el simulado con su desembolso', true, in_array('C|2|DESEMBOLSO', $ids, true));
chequear('marcado como simulado', true, array_values(array_filter($pr['filas'], function ($f) { return $f['id'] === 'P|2'; }))[0]['simulado']);
chequear('el finalizado no tiene filas', false, in_array('P|3', $ids, true));
$fila = function ($id) use ($pr) { return array_values(array_filter($pr['filas'], function ($f) use ($id) { return $f['id'] === $id; }))[0]; };
chequear('el desembolso en positivo', true, $fila('C|2|DESEMBOLSO')['eje']['total_horizonte'] > 0);
chequear('el capital en negativo', true, $fila('C|1|CAPITAL')['eje']['total_horizonte'] < 0);
$oficial = array_sum(array_map(function ($p) { return ($p['concepto'] === 'DESEMBOLSO' ? 1 : -1) * $p['importe']; }, $pagos));
chequear('el total oficial es el de los pagos sin simulados', round($oficial, 2), round($pr['totales']['total_horizonte'], 2));
$todos = array_sum(array_map(function ($p) { return ($p['concepto'] === 'DESEMBOLSO' ? 1 : -1) * $p['importe']; }, $conSim));
chequear('y con simulados, el de todos', round($todos, 2), round($pr['totales_sim']['total_horizonte'], 2));

seccion('las tarjetas');
$k = Prestamos::kpis($resultados, $h, PP_HOY, null);
chequear('deuda vigente: el saldo a hoy del vigente', 1000000.0, $k['deuda']);
chequear('proxima cuota: hoy, la del 25/11', PP_HOY, $k['proxima']['fecha']);
chequear('simulados: 1', [1, 600000.0], [$k['simulados_n'], $k['simulados_capital']]);
chequear('la necesidad se calcula a pedido: null', null, $k['necesidad']);
$cuotasHorizonte = 0.0;
foreach ($pagos as $p) { if ($p['concepto'] !== 'DESEMBOLSO' && Horizonte::ubicar($h->serieVacia(), $p['fecha']) !== null) { $cuotasHorizonte += $p['importe']; } }
chequear('cuotas del horizonte: capital + intereses + gastos de los vigentes', round($cuotasHorizonte, 2), $k['cuotas_horizonte']);
$usd = [9 => ppRes(9, ['moneda' => 'USD', 'capital' => 1000.0, 'fecha_desembolso' => '2026-10-25'])];
$k = Prestamos::kpis($usd, $h, PP_HOY, 1500.0);
chequear('un vigente en dolares, a pesos con el dolar de hoy', round($usd[9]['r']['resumen']['saldo_hoy'] * 1500, 2), $k['deuda']);
$k = Prestamos::kpis($usd, $h, PP_HOY, null);
chequear('sin dolar, aparte', [0.0, $usd[9]['r']['resumen']['saldo_hoy']], [$k['deuda'], $k['deuda_usd_sin_tc']]);

seccion('cancelacion: simular antes de registrar');
$base = ppRes(5, ['fecha_desembolso' => '2027-01-15']);
$con = ppRes(5, ['fecha_desembolso' => '2027-01-15'], ['cancelaciones' => [['id' => null, 'fecha' => '2027-04-01', 'tipo' => 'TOTAL']]]);
$c = Prestamos::compararCancelacion($base['r'], $con['r'], '2027-04-01');
chequear('total a pagar: el renglon de la cancelacion', $con['r']['cuotas'][count($con['r']['cuotas']) - 1]['total'], $c['total_a_pagar']);
chequear('capital: el saldo', 1000000.0, $c['capital']);
chequear('comision con IVA', 36300.0, $c['comision']);
$intSin = 0.0;
foreach ($base['r']['cuotas'] as $f) { if ($f['tipo'] === 'CUOTA' && $f['vencimiento'] > '2027-04-01') { $intSin += $f['interes']; } }
chequear('intereses de seguir pagando: los de las cuotas posteriores', round($intSin, 2), $c['intereses_sin']);
chequear('cancelando: solo lo devengado', $c['devengado'], $c['intereses_con']);
chequear('ahorro = sin - con', round($c['intereses_sin'] - $c['intereses_con'], 2), $c['ahorro_intereses']);
chequear('neto = ahorro - comision', round($c['ahorro_intereses'] - 36300, 2), $c['ahorro_neto']);
chequear('como queda el cashflow: abril paga mas cancelando', true, $c['por_mes'][0]['diferencia'] < 0);
chequear('y despues paga menos', true, end($c['por_mes'])['diferencia'] > 0);
chequear('el acumulado termina en lo que se ahorra neto', round($c['ahorro_neto'], 0), round(end($c['por_mes'])['acumulado'], 0));
chequear('saldo a una fecha', 1000000.0, Prestamos::saldoA($base['r']['cuotas'], 1200000.0, '2027-04-01'));
chequear('el dia del vencimiento ya esta amortizado', 900000.0, Prestamos::saldoA($base['r']['cuotas'], 1200000.0, '2027-04-15'));

seccion('las cuentas bancarias: las de Saldos, sin los fondos');
$cuentas = Prestamos::cuentasDe([
    ['tipo' => 'BANCO', 'clave' => '11|123|ARS', 'nombre' => 'Galicia · 123', 'moneda' => 'ARS'],
    ['tipo' => 'BANCO', 'id_cuenta' => 12, 'clase' => 'CTA_CORRIENTE', 'nombre' => 'BTG Uy', 'moneda' => 'USD'],
    ['tipo' => 'BANCO', 'id_cuenta' => 13, 'clase' => 'INVERSION', 'nombre' => 'Fondo', 'moneda' => 'ARS'],
    ['tipo' => 'EFECTIVO_CENTRAL', 'id_cuenta' => 14, 'nombre' => 'Caja', 'moneda' => 'ARS']
]);
chequear('Interbanking y el catalogo, sin fondos ni efectivo', ['CTA_12' => 'BTG Uy (USD)', 'IB|11|123|ARS' => 'Galicia · 123 (ARS)'], $cuentas);

seccion('cableado: menu, permisos y endpoint');
chequear('el menu la declara con datos', Menu::DATOS, Menu::estado('prestamos', Menu::DATOS));
chequear('ya no es un placeholder', false, Menu::esPlaceholder('prestamos'));
$tab = file_get_contents(__DIR__ . '/../Tabs/prestamos.php');
chequear('la raiz lleva data-puede-editar', true, strpos($tab, "AuthCashflow::atributoEdicion('prestamos')") !== false);
chequear('los modales de edicion solo con permiso', true, (bool) preg_match('/<\?php if \(\$edita\): \?>\s*<!-- ALTA, EDICIÓN/u', $tab));
chequear('el boton de alta solo con permiso', true, (bool) preg_match('/<\?php if \(\$edita\): \?>\s*<button id="btnPrNuevo"/', $tab));
chequear('el de cancelacion tambien', true, (bool) preg_match('/<\?php if \(\$edita\): \?>\s*<button id="btnPrCancelar"/', $tab));
chequear('getPestana pide la lectura de la pestaña', true,
    (bool) preg_match("/case 'getPestana':\s*autorizarLectura\('prestamos'\);/", file_get_contents(__DIR__ . '/../Controller/PrestamosController.php')));
foreach (['altaPrestamo', 'editarPrestamo', 'pasarAVigente', 'eliminarSimulado', 'guardarAjuste', 'deshacerAjuste',
          'simularCancelacion', 'registrarCancelacion', 'deshacerCancelacion'] as $a) {
    chequear($a . ' pide editar Prestamos', [['prestamos', null]], AuthCashflow::destinosDe('Prestamos', $a));
}
chequear('registrar valida de nuevo en el servidor (no confia en la simulacion del navegador)', true,
    (bool) preg_match("/case 'registrarCancelacion':.*?simularCancelacion\(/s", file_get_contents(__DIR__ . '/../Controller/PrestamosController.php')));
$js = file_get_contents(__DIR__ . '/../Js/Prestamos.js');
chequear('la pestaña pide los avisos del tablero', true, strpos($js, "AvisosPanel.pedirTablero('prestamos')") !== false);
chequear('y los pinta con los suyos', true, strpos($js, "AvisosPanel.pintarPestana('avisosPrestamos'") !== false);
chequear('las acciones de la lista se dibujan solo con permiso', true, strpos($js, 'if (edita && datos.tabla_creada)') !== false);
