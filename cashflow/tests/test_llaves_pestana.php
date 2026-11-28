<?php
/**
 * La pestaña Llaves y Renov. Contratos de punta a punta, con dobles
 * (Llaves::armarPestana() sobre una proyeccion pura de LlavesCalculo), y su
 * cableado.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA GRILLA SUMA DOS VECES. Cada pago esta en tres niveles (operador,
 *      local, concepto); el pie tiene que contarlo una sola vez.
 *   2. LO ESTIMADO SE VE IGUAL QUE LO FIRME. La celda lleva su origen y el
 *      tooltip, la cuenta.
 *   3. LA PESTAÑA NO ESTA EN EL MENU, o no pide su permiso.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Llaves.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Menu.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

/** Un horizonte fijo: 23/11/2026, 28 dias y 12 meses, sin calendario. */
function lpHorizonte() {
    return new Horizonte(28, 12, [], new DateTime('2026-11-23'));
}

function lpEntrada($h) {
    return [
        'hoy' => $h->hoy(), 'meses' => array_column($h->meses(), 'clave'), 'habiles' => [],
        'locales' => [
            78 => ['nro' => 78, 'nombre' => 'DISTRITO ARCOS', 'habilitado' => true, 'vigencia' => false, 'cod_provee' => 'SAARCO',
                   'operador' => 'ARCOS DEL GOURMET', 'pct_retencion' => 0.0, 'm2' => 151.24,
                   'contratos' => [['id' => 22, 'desde' => '2023-08-01', 'hasta' => '2026-12-31', 'llave' => 33000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]]],
            66 => ['nro' => 66, 'nombre' => 'DOT', 'habilitado' => true, 'vigencia' => false, 'cod_provee' => 'SAPAMA',
                   'operador' => 'PANAMERICAN MALL', 'pct_retencion' => 0.0, 'm2' => 108.17,
                   'contratos' => [['id' => 32, 'desde' => '2023-10-20', 'hasta' => '2026-10-19', 'llave' => 11600000.0, 'comisiones' => 0.0, 'fpc' => 0.0],
                                   ['id' => 91, 'desde' => '2026-10-20', 'hasta' => '2029-10-19', 'llave' => 80000000.0, 'comisiones' => 0.0, 'fpc' => 0.0]]]
        ],
        'alquiler' => [78 => ['2027-01' => 18927137.0]],
        'par' => ['tablas_ok' => true, 'generales' => ['PCT_LLAVE' => 25.0, 'CUOTAS' => 4.0, 'DIA_PAGO' => 15.0],
                  'operadores' => ['SAARCO' => ['pct_impuestos' => 0.0], 'SAPAMA' => ['pct_impuestos' => 0.0]], 'locales' => [],
                  'pagadas' => [], 'obras' => [], 'aperturas' => [],
                  'compromisos' => [['id' => 1, 'tipo' => 'PROVEEDOR', 'cod_provee' => 'SAUNIS', 'nro' => null, 'concepto' => 'Servicio comercial',
                                     'fecha' => '2027-02-10', 'monto' => 1000000.0, 'forma' => null, 'observacion' => null,
                                     'id_serie' => null, 'cuota_nro' => null, 'cuotas' => null]]],
        'curva' => [], 'facturas' => [], 'operadores' => ['SAUNIS' => 'UNICENTER SA'],
        'locales_nombres' => [78 => 'DISTRITO ARCOS', 66 => 'DOT']
    ];
}

$h = lpHorizonte();
$c = LlavesCalculo::proyectar(lpEntrada($h));
$c['tablas_ok'] = true;
$p = Llaves::armarPestana($c, $h, ['nombres' => [78 => 'DISTRITO ARCOS', 66 => 'DOT'], 'facturas' => []]);

seccion('Llaves::armarPestana(): la grilla');

$ids = array_column($p['filas'], 'id');
chequear('operador, local y concepto', true, in_array('O|ARCOS DEL GOURMET', $ids, true)
    && in_array('L|ARCOS DEL GOURMET|78', $ids, true) && in_array('C|ARCOS DEL GOURMET|78|LLAVE', $ids, true));
chequear('y los compromisos aparte', true, in_array('G|COMPROMISO', $ids, true) && in_array('K|UNICENTER SA|Servicio comercial', $ids, true));
$sumaPagos = round(array_sum(array_column($c['pagos'], 'importe')), 2);
chequear('el pie cuenta cada pago una sola vez', $sumaPagos, round($p['eje']['totales']['total_horizonte'], 2));
chequear('y el total de la tarjeta es el mismo', $sumaPagos, $p['kpis']['total']);
$fila = array_values(array_filter($p['filas'], function ($f) { return $f['id'] === 'C|ARCOS DEL GOURMET|78|LLAVE'; }))[0];
$cel = array_values($fila['celdas'])[0];
chequear('una celda estimada lleva su origen', ['ESTIMADO', 'Estimado'], [$cel['origen'], $cel['chip']]);
chequear('y el tooltip, la cuenta', true, strpos($cel['tooltip'], '25% (general)') !== false);
$dot = array_values(array_filter($p['filas'], function ($f) { return $f['id'] === 'L|PANAMERICAN MALL|66'; }))[0];
chequear('un contrato cargado va con origen Contrato cargado', 'CONTRATO', array_values($dot['celdas'])[0]['origen']);

seccion('Llaves::armarPestana(): las tarjetas y los contratos');

chequear('dos renovaciones con pagos en el horizonte', 2, $p['kpis']['renovaciones_n']);
chequear('estimado + firme + manual = total', $p['kpis']['total'],
    round($p['kpis']['estimado'] + $p['kpis']['firme'] + $p['kpis']['manual'], 2));
$cont = array_column($p['contratos'], null, 'nro');
chequear('Arcos vence en el horizonte; DOT está vigente (#91)', ['VENCE', 'VIGENTE', 91],
    [$cont[78]['estado'], $cont[66]['estado'], $cont[66]['vigente']['id']]);

seccion('La pestaña: menú, permisos y endpoint');

$menu = Menu::tabs();
chequear('está en el menú', true, in_array('llaves_renov', $menu, true));
chequear('con datos (ya no es un placeholder)', true,
    strpos(file_get_contents(__DIR__ . '/../Class/Menu.php'), "'tab' => 'llaves_renov', 'nombre' => 'Llaves y Renov. Contratos','icono' => 'fa-key',            'estado' => self::DATOS]") !== false);
$tab = file_get_contents(__DIR__ . '/../Tabs/llaves_renov.php');
chequear('la raíz lleva el permiso de edición de la pestaña', true, strpos($tab, "AuthCashflow::atributoEdicion('llaves_renov')") !== false);
chequear('ya no incluye el placeholder', false, strpos($tab, 'tab_placeholder') !== false);
chequear('getProyeccion es una lectura de la pestaña', true, in_array('getProyeccion', AuthCashflow::LECTURAS['Llaves'], true)
    && strpos(file_get_contents(__DIR__ . '/../Controller/LlavesController.php'), "autorizarLectura('llaves_renov');") !== false);
chequear('"Ya pagada" se marca con el permiso de la pestaña o el de Parámetros',
    [['parametros', 'LLAVES_RENOV'], ['llaves_renov', null]], AuthCashflow::ESCRITURAS['Llaves']['altaPagada']);
chequear('las claves son cashflow.tab.llaves_renov y cashflow.editar.llaves_renov', true,
    AuthCashflow::permiteEditar(['cashflow.tab.llaves_renov' => true, 'cashflow.editar.llaves_renov' => true], false, 'llaves_renov')
    && !AuthCashflow::permiteEditar(['cashflow.editar.llaves_renov' => true], false, 'llaves_renov'));
$js = file_get_contents(__DIR__ . '/../Js/Llaves.js');
chequear('el JS usa las tres vistas, columnas fijas y los totales arriba del eje', true,
    strpos($js, 'crearEjeVistas(') !== false && strpos($js, 'crearColumnasFijas(') !== false && strpos($js, 'pintarEjeTotales(') !== false);

seccion('Proyección: la columna de nombres queda fija al scrollear');

chequear('la tabla está dentro de .tabla-temporal (el sticky de col-fija-N es de ese bloque)', 1,
    preg_match('/<div class="[^"]*\btabla-temporal\b[^"]*" id="llWrapper"[^>]*>\s*(<!--.*?-->\s*)?<table id="tablaLlaves"/s', $tab));
chequear('la primera columna es descriptiva (rowspan 2): columnas-fijas.js la toma', true,
    strpos($js, '<th rowspan="2" class="col-texto">Operador · local · concepto</th>') !== false);
chequear('y arranca fija: col-fija-1 en la columna 0', 1,
    preg_match("/crearColumnasFijas\(\{ tabla: 'tablaLlaves',[^}]*porDefecto: \[0\] \}\)/", $js));

seccion('Llaves::armarPestana(): la tarjeta Renovaciones cuenta y suma lo mismo, facturas incluidas');

/* DOT renueva con el #91 (la llave del contrato, proyectada) y tiene una
   factura de otros gastos imputada a esa misma renovacion: es UNA renovacion,
   y su importe entra en la suma. */
$eF = lpEntrada($h);
$eF['facturas'] = [['id' => 7, 'nro' => 66, 'concepto' => LlavesCalculo::OTROS, 'mes' => '2027-01', 'ant' => 32,
                    'contrato' => 91, 'cod_provee' => 'SAPAMA', 'comprobante' => 'A 0001-00000007', 'pagada' => false,
                    'pagos' => [['fecha' => '2027-01-11', 'importe' => 500000.0, 'nota' => '']], 'texto' => '']];
$cF = LlavesCalculo::proyectar($eF);
$cF['tablas_ok'] = true;
$pF = Llaves::armarPestana($cF, $h, ['nombres' => [78 => 'DISTRITO ARCOS', 66 => 'DOT'], 'facturas' => []]);
$llOt = array_filter($cF['pagos'], function ($x) {
    return in_array($x['concepto'], [LlavesCalculo::LLAVE, LlavesCalculo::OTROS], true);
});
chequear('la factura no suma una renovacion de mas', 2, $pF['kpis']['renovaciones_n']);
$filaVieja = array_values(array_filter($pF['filas'], function ($f) { return $f['id'] === 'C|PANAMERICAN MALL|66|OTROS'; }));
chequear('una marca vieja de «Otros gastos» tiene su propia fila', 'Otros gastos (marca anterior)', $filaVieja[0]['nombre'] ?? null);
chequear('y su importe esta en la suma, con lo proyectado', round(array_sum(array_column($llOt, 'importe')), 2),
    $pF['kpis']['renovaciones_importe']);
chequear('que incluye la factura', true, $pF['kpis']['renovaciones_importe'] - $p['kpis']['renovaciones_importe'] >= 500000.0 - 0.01);

chequear('el tooltip nombra los conceptos del alquiler', true,
    strpos($cel['tooltip'], 'alquiler + complementario + VMA de') !== false);
