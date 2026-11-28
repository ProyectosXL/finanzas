<?php
/**
 * Las facturas de alquiler marcadas como llave o renovacion
 * (Class/LlavesFacturas.php, AlquileresFacturas::destino() con A_LLAVES).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA FACTURA SUMA DOS VECES, O NINGUNA. Marcada como llave tiene que salir
 *      de Alquileres y NO volver a Proveedores Locales, y Llaves tiene que
 *      reemplazar con ella lo proyectado. Lo decide destino(), una sola regla.
 *   2. DESHACER NO LA DEVUELVE. Sin la marca, vuelve a Sin imputar.
 *   3. LOS PAGOS NO SUMAN LA FACTURA. Repartida en N pagos, tienen que sumar el
 *      pendiente menos la retencion.
 *   4. SE IMPUTA A OTRA RENOVACION. La factura de agosto de Arcos es de la
 *      renovacion de enero, no del contrato de 2023.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/LlavesFacturas.php';
require_once __DIR__ . '/../Class/AlquileresImputacion.php';

const LF_HOY = '2026-11-23';

function lfCuota($extra = []) {
    return $extra + ['COD_PROVEE' => 'SAARCO', 'NOM_PROVEE' => 'ARCOS DEL GOURMET SA', 'T_COMP' => 'FAC',
                     'N_COMP' => 'A0001400000500', 'FECHA_VTO' => '2026-12-10', 'FECHA_EMIS' => '2026-11-20',
                     'IMPORTE_VTO' => 180525000.0, 'IMPORTE_PENDIENTE' => 180525000.0, 'LEYENDA' => 'CUOTA INICIAL L78'];
}

function lfMarca($extra = []) {
    return $extra + ['ID' => 9, 'COD_PROVEE' => 'SAARCO', 'T_COMP' => 'FAC', 'N_COMP' => 'A0001400000500',
                     'FECHA_VTO_TANGO' => '2026-12-10', 'NRO_SUCURS' => 78, 'CONCEPTO' => 'LLAVE', 'MES' => '2027-01',
                     'ID_CONTRATO_ANT' => 22, 'ID_CONTRATO' => null, 'IMPORTE' => 180525000.0, 'RETENCION' => null,
                     'PAGADA_AL_MARCAR' => false, 'OBSERVACION' => null, 'USUARIO_ALTA' => 'tesoreria',
                     'FECHA_ALTA' => '2026-11-23 10:00', 'PAGOS' => []];
}

$contrArcos = [78 => [['id' => 22, 'desde' => '2023-08-01', 'hasta' => '2026-12-31', 'llave' => 33000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]],
               66 => [['id' => 32, 'desde' => '2023-10-20', 'hasta' => '2026-10-19', 'llave' => 11600000.0, 'comisiones' => 0.0, 'fpc' => 0.0],
                      ['id' => 91, 'desde' => '2026-10-20', 'hasta' => '2029-10-19', 'llave' => 80000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]]];

seccion('destino(): una factura marcada como llave, para todos');

$cuota = lfCuota();
$clave = AlquileresFacturas::claveCuota($cuota);
$cumple = AlquileresFacturas::condicion(true, ['REAL'], true);
$ctx = AlquileresFacturas::contexto(['SAARCO'], [], $cumple, [], [$clave => lfMarca()]);
$otra = lfCuota(['N_COMP' => 'A0001400000501']);

chequear('la marcada va a Llaves', AlquileresFacturas::A_LLAVES, AlquileresFacturas::destino($cuota, $ctx));
chequear('una sin marcar de la misma razón sigue en Alquileres', AlquileresFacturas::A_ALQUILERES,
    AlquileresFacturas::destino($otra, $ctx));
$partes = AlquileresFacturas::partir([$cuota, $otra], $ctx);
chequear('no está en Alquileres', [$otra], $partes[AlquileresFacturas::A_ALQUILERES]);
chequear('no vuelve a Proveedores Locales', [], AlquileresFacturas::deProveedoresLocales([$cuota], $ctx));
chequear('Proveedores Locales lo dice en su aviso', true,
    strpos(AlquileresFacturas::avisoFueraDeProvLocales($partes)[0]['texto'], 'marcadas como llave') !== false);
$sinCond = AlquileresFacturas::contexto(['SAARCO'], [], AlquileresFacturas::condicion(true, [], true), [], [$clave => lfMarca()]);
chequear('sin la condición de seguridad, todo queda en Proveedores Locales (también la marcada)',
    AlquileresFacturas::A_PROV_LOCALES, AlquileresFacturas::destino($cuota, $sinCond));
$ctxSin = AlquileresFacturas::contexto(['SAARCO'], [], $cumple, [], []);
chequear('deshacer la marca la devuelve a Alquileres (Sin imputar)', AlquileresFacturas::A_ALQUILERES,
    AlquileresFacturas::destino($cuota, $ctxSin));

$prov = file_get_contents(__DIR__ . '/../Class/Providers/ProveedoresProvider.php');
$alq = file_get_contents(__DIR__ . '/../Class/Alquileres.php');
chequear('el proveedor de Proveedores Locales usa destino() y deja la marcada fuera de su universo', true,
    strpos($prov, '$items = $partes[AlquileresFacturas::A_PROV_LOCALES];') !== false
    && strpos($prov, '$partes[AlquileresFacturas::A_LLAVES]') !== false);
chequear('Alquileres la toma de destino() como llave, no como suya', true,
    strpos($alq, "\$out['llaves_factura'] = \$partes[AlquileresFacturas::A_LLAVES];") !== false
    && strpos($alq, "\$out['pendientes'] = \$partes[AlquileresFacturas::A_ALQUILERES];") !== false);

seccion('La grilla de Alquileres: la marcada no suma y se ve para deshacer');

$a = AlquileresImputacion::aplicar([
    'pagos' => [], 'meses_pago' => ['2026-11', '2026-12'], 'pendientes' => [$otra], 'excluidas' => [], 'vtos' => [],
    'imputaciones' => [], 'en_tango' => [], 'ctx' => $ctx, 'habiles' => [], 'hoy' => LF_HOY,
    'llaves_factura' => [$cuota], 'llaves' => [$clave => lfMarca()], 'nombres_locales' => [78 => 'DISTRITO ARCOS']
]);
$fila = array_values(array_filter($a['facturas'], function ($f) { return $f['estado'] === AlquileresImputacion::LLAVE; }));
chequear('una fila con estado Llave / renovación, que no paga nada acá', [1, 0.0], [count($fila), $fila[0]['a_pagar'] ?? null]);
chequear('con el local, el concepto y el id para deshacer', [9, 'DISTRITO ARCOS', 'LLAVE'],
    [$fila[0]['llave']['id'] ?? null, $fila[0]['llave']['local'] ?? null, $fila[0]['llave']['concepto'] ?? null]);
chequear('la sin marcar sigue Sin imputar', 1, count(array_filter($a['facturas'], function ($f) {
    return $f['estado'] === AlquileresImputacion::SIN_IMPUTAR; })));

seccion('LlavesFacturas: la renovación a la que se imputa');

$r = LlavesFacturas::renovacionDeMes($contrArcos[78], '2026-08', LF_HOY);
chequear('Arcos, factura de agosto de 2026: la renovación estimada de enero, no el contrato de 2023', [22, null],
    [$r['ant'], $r['contrato']]);
$r = LlavesFacturas::renovacionDeMes($contrArcos[66], '2026-08', LF_HOY);
chequear('DOT, factura de agosto: el contrato #91 que empieza en octubre', [32, 91], [$r['ant'], $r['contrato']]);
chequear('sin contratos, null', null, LlavesFacturas::renovacionDeMes([], '2026-08', LF_HOY));

seccion('LlavesFacturas: validar');

$e = ['imputables' => [$clave => $cuota], 'imputadas' => [], 'excluidas' => [], 'llaves' => [],
      'locales' => [78 => 'DISTRITO ARCOS', 66 => 'DOT'], 'contratos' => $contrArcos, 'hoy' => LF_HOY];
$d = ['cuotas' => [['cod_provee' => 'SAARCO', 't_comp' => 'FAC', 'n_comp' => 'A0001400000500', 'vto_tango' => '2026-12-10']],
      'nro' => 78, 'concepto' => 'LLAVE', 'mes' => '2027-01'];
$v = LlavesFacturas::validar($d, $e);
chequear('una llave entera, válida, a la renovación del #22', [true, 22, null, []], [$v['ok'], $v['ant'], $v['contrato'], $v['pagos']]);
$v = LlavesFacturas::validar($d + ['pagos' => [['fecha' => '2027-01-15', 'importe' => '90000000'],
                                              ['fecha' => '2027-02-15', 'importe' => '90525000']]], $e);
chequear('en dos pagos que suman la factura', [true, 2], [$v['ok'], count($v['pagos'])]);
$v = LlavesFacturas::validar($d + ['pagos' => [['fecha' => '2027-01-15', 'importe' => '90000000']]], $e);
chequear('pagos que no suman la factura: error', false, $v['ok']);
$v = LlavesFacturas::validar($d + ['retencion' => '525000', 'pagos' => [['fecha' => '2027-01-15', 'importe' => '180000000']]], $e);
chequear('con retención, los pagos suman el pendiente menos la retención', true, $v['ok']);
$v = LlavesFacturas::validar($d + ['pagos' => [['fecha' => '2026-11-01', 'importe' => '180525000']]], $e);
chequear('un pago con fecha pasada: error', false, $v['ok']);
$v = LlavesFacturas::validar(['concepto' => 'OBRA'] + $d, $e);
chequear('una obra no tiene renovación: su clave es el local y el mes', [true, null], [$v['ok'], $v['ant']]);
$v = LlavesFacturas::validar(['nro' => 999] + $d, $e);
chequear('un local que no existe: error', false, $v['ok']);
$v = LlavesFacturas::validar($d, ['imputadas' => [$clave => 4]] + $e);
chequear('una imputada en Alquileres no se marca', true, !$v['ok'] && strpos(implode(' ', $v['errores']), 'deshacé primero') !== false);
$v = LlavesFacturas::validar($d, ['excluidas' => [$clave => []]] + $e);
chequear('una excluida no se marca (primero se vuelve a incluir)', false, $v['ok']);
$v = LlavesFacturas::validar($d, ['llaves' => [$clave => lfMarca()]] + $e);
chequear('una ya marcada no se marca otra vez', false, $v['ok']);
$pagada = AlquileresImputacion::comoPendiente(lfCuota(['IMPORTE_PENDIENTE' => 0.0, 'FECHA_CANCELADO' => '2026-08-31']));
$v = LlavesFacturas::validar($d, ['imputables' => [$clave => $pagada]] + $e);
chequear('una ya pagada se marca, sin pagos', [true, true], [$v['ok'], $v['pagada']]);
$v = LlavesFacturas::validar($d + ['pagos' => [['fecha' => '2027-01-15', 'importe' => '1']]], ['imputables' => [$clave => $pagada]] + $e);
chequear('una ya pagada no se reparte en pagos', false, $v['ok']);
$cuota2 = lfCuota(['N_COMP' => 'A0001400000502']);
$e2 = ['imputables' => [$clave => $cuota, AlquileresFacturas::claveCuota($cuota2) => $cuota2]] + $e;
$d2 = $d;
$d2['cuotas'][] = ['cod_provee' => 'SAARCO', 't_comp' => 'FAC', 'n_comp' => 'A0001400000502', 'vto_tango' => '2026-12-10'];
chequear('varias tildadas se marcan enteras', true, LlavesFacturas::validar($d2, $e2)['ok']);
chequear('pero no se reparten en pagos', false, LlavesFacturas::validar($d2 + ['pagos' => [['fecha' => '2027-01-15', 'importe' => '1']]], $e2)['ok']);

seccion('LlavesFacturas: los pagos por defecto');

$pd = LlavesFacturas::pagosDefecto(180525000.0, 3, '2026-12-10', ['2027-01-10' => false], LF_HOY);
chequear('3 cuotas mensuales desde el vencimiento, al hábil', ['2026-12-10', '2027-01-11', '2027-02-10'], array_column($pd, 'fecha'));
chequear('que suman exacto la factura', 180525000.0, round(array_sum(array_column($pd, 'importe')), 2));

seccion('LlavesFacturas: lo que ve la regla (paraCalculo)');

$pc = LlavesFacturas::paraCalculo([$clave => lfMarca()], [$clave => $cuota], [], ['SAARCO' => 15], [], LF_HOY);
chequear('pendiente sin pagos: entera en su vencimiento a considerar (el día 15 de la razón)',
    [false, [['2026-12-15', 180525000.0]]], [$pc['facturas'][0]['pagada'],
        array_map(function ($p) { return [$p['fecha'], $p['importe']]; }, $pc['facturas'][0]['pagos'])]);
$m = lfMarca(['PAGOS' => [['ORDEN' => 1, 'FECHA' => '2026-11-10', 'IMPORTE' => 90000000.0], ['ORDEN' => 2, 'FECHA' => '2027-01-15', 'IMPORTE' => 90525000.0]]]);
$pc = LlavesFacturas::paraCalculo([$clave => $m], [$clave => $cuota], [], [], [], LF_HOY);
chequear('con pagos: los suyos, y uno ya pasado al siguiente hábil desde hoy', ['2026-11-23', '2027-01-15'],
    array_column($pc['facturas'][0]['pagos'], 'fecha'));
$pc = LlavesFacturas::paraCalculo([$clave => lfMarca(['PAGADA_AL_MARCAR' => true])], [], [], [], [], LF_HOY);
chequear('pagada al marcar: no suma (pagada, sin pagos)', [true, []], [$pc['facturas'][0]['pagada'], $pc['facturas'][0]['pagos']]);
$pc = LlavesFacturas::paraCalculo([$clave => lfMarca()], [], [], [], [], LF_HOY);
chequear('si ya no está pendiente en Tango: pagada, con aviso', [true, 1], [$pc['facturas'][0]['pagada'], count($pc['avisos'])]);

seccion('LlavesFacturas: reemplaza lo proyectado de su local, concepto y renovación');

$pc = LlavesFacturas::paraCalculo([$clave => lfMarca()], [$clave => $cuota], [], [], [], LF_HOY);
$meses = [];

for ($i = 0; $i < 12; $i++) {
    $meses[] = date('Y-m', strtotime('2026-11-01 +' . $i . ' month'));
}

$entrada = [
    'hoy' => LF_HOY, 'meses' => $meses, 'habiles' => [],
    'locales' => [78 => ['nro' => 78, 'nombre' => 'DISTRITO ARCOS', 'habilitado' => true, 'vigencia' => false,
                         'cod_provee' => 'SAARCO', 'operador' => 'ARCOS', 'pct_retencion' => 0.0, 'm2' => null,
                         'contratos' => $contrArcos[78]]],
    'alquiler' => [78 => ['2027-01' => 18927137.0]],
    'par' => ['tablas_ok' => true, 'generales' => ['PCT_LLAVE' => 25.0, 'CUOTAS' => 4.0, 'DIA_PAGO' => 15.0],
              'operadores' => ['SAARCO' => ['pct_impuestos' => 0.0]], 'locales' => [], 'pagadas' => [], 'obras' => [],
              'aperturas' => [], 'compromisos' => []],
    'curva' => [], 'facturas' => [], 'operadores' => [], 'locales_nombres' => [78 => 'DISTRITO ARCOS']
];
$antes = LlavesCalculo::proyectar($entrada);
$entrada['facturas'] = $pc['facturas'];
$despues = LlavesCalculo::proyectar($entrada);
$suma = function ($r, $origen) {
    return round(array_sum(array_column(array_filter($r['pagos'], function ($p) use ($origen) {
        return $p['concepto'] === 'LLAVE' && $p['origen'] === $origen; }), 'importe')), 2);
};
chequear('sin la marca, la llave de Arcos se estima', true, $suma($antes, LlavesCalculo::ESTIMADO) > 0);
chequear('con la marca, no queda nada estimado de esa llave', 0.0, $suma($despues, LlavesCalculo::ESTIMADO));
chequear('y suma la factura, como real', 180525000.0, $suma($despues, LlavesCalculo::FACTURA));

seccion('LlavesFacturas: el script 68 y el cableado');

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_llaves_facturas.sql');
chequear('crea las dos tablas', true, strpos($sql, 'CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA (') !== false
    && strpos($sql, 'CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA_PAGO (') !== false);
chequear('una marca vigente por cuota', true, strpos($sql, 'UX_RO_T_CF_LLFAC_VIGENTE') !== false);
chequear('sin bajas físicas', 0, preg_match_all('/\b(DELETE|DROP|TRUNCATE)\b/i', preg_replace('#/\*.*?\*/#s', '', $sql)));
require_once __DIR__ . '/../Class/AuthCashflow.php';
$jsAf = file_get_contents(__DIR__ . '/../Js/Alquileres-Facturas.js');
$ctrlA = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
foreach (['previsualizarLlave', 'marcarLlave', 'deshacerLlave', 'getPagadasLlave'] as $acc) {
    chequear($acc . ': el JS la pide, el controller la atiende y está en el mapa', [true, true, true], [
        strpos($jsAf, $acc) !== false, strpos($ctrlA, "case '" . $acc . "':") !== false,
        isset(AuthCashflow::ESCRITURAS['Alquileres'][$acc]) || in_array($acc, AuthCashflow::LECTURAS['Alquileres'], true)]);
}
chequear('marcar pide editar Alquileres, como excluir', [['alquileres', null]], AuthCashflow::ESCRITURAS['Alquileres']['marcarLlave']);

seccion('LlavesFacturas: los conceptos Comisiones y FPC (script 73)');

chequear('se marca como Llave, Comisiones, FPC u Obra; «Otros gastos» ya no se ofrece',
    ['LLAVE' => 'Llave', 'COMISIONES' => 'Comisiones', 'FPC' => 'FPC', 'OBRA' => 'Obra'], LlavesFacturas::CONCEPTOS);
chequear('sin el script 73, Comisiones y FPC quedan fuera del desplegable', ['LLAVE' => 'Llave', 'OBRA' => 'Obra'],
    LlavesFacturas::conceptos(false));
chequear('una marca vieja se nombra como tal', 'Otros gastos (marca anterior)', LlavesFacturas::NOMBRES['OTROS']);
$vC = LlavesFacturas::validar(['concepto' => 'COMISIONES'] + $d, $e);
chequear('una factura de comisiones se valida e imputa a la renovación del mes', [true, 'COMISIONES', 22],
    [$vC['ok'], $vC['concepto'], $vC['ant']]);
$vC = LlavesFacturas::validar(['concepto' => 'FPC'] + $d, ['conceptos' => LlavesFacturas::conceptos(false)] + $e);
chequear('sin el 73, una de FPC no se marca y el error nombra el script', [false, true],
    [$vC['ok'], strpos(implode(' ', $vC['errores']), 'falta correr sql/cashflow_llaves_ajustes.sql') !== false]);
$vC = LlavesFacturas::validar(['concepto' => 'OTROS'] + $d, $e);
chequear('ya no se marca una nueva como «Otros gastos»', [false, 'Elegí el concepto: llave, comisiones, FPC u obra.'],
    [$vC['ok'], $vC['errores'][0] ?? null]);
$alqTab = file_get_contents(__DIR__ . '/../Tabs/alquileres.php');
chequear('el modal de Alquileres explica que cada concepto reemplaza sólo lo suyo', true,
    strpos($alqTab, 'La llave, las comisiones y el FPC reemplazan lo proyectado de ese concepto') !== false);
chequear('el tooltip de una marca usa el nombre de cada concepto, también la vieja', true,
    substr_count($jsAf, 'datos.nombres_llave') === 2);
