<?php
/**
 * Proveedores Locales: N PAGOS POR COMPROBANTE.
 *
 * Un comprobante se paga en N filas de la planilla, con el mismo medio o con
 * medios distintos y en fechas distintas, tambien si esta en cuotas. Todo sin
 * base: la importacion, lo que se escribe, como entra al eje y en que serie
 * cae cada peso son estaticas y puras, y lo que se puede guardar con los
 * scripts corridos llega por parametro (capacidadImportacion()).
 *
 * Lo de dos medios sin el script -dos como maximo, cuotas con uno solo- lo
 * sigue fijando test_prov_locales_medios.php, que llama a la regla sin decirle
 * que el script esta: es el comportamiento de antes.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script de N medios');

$sqlN = file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_pago_n_medios.sql');

chequear('el CHECK de ORDEN pasa a ORDEN >= 1', true,
    strpos($sqlN, 'CHECK (ORDEN >= 1)') !== false);
chequear('y se reemplaza en una transaccion', true,
    strpos($sqlN, 'BEGIN TRANSACTION') !== false && strpos($sqlN, 'COMMIT TRANSACTION') !== false
    && strpos($sqlN, 'BEGIN TRANSACTION') < strpos($sqlN, 'DROP CONSTRAINT CK_RO_T_CF_PLMED_ORDEN'));
chequear('es reejecutable: pregunta si ya esta hecho', true,
    strpos($sqlN, "AND definition LIKE '%>=%')") !== false);
chequear('corta si falta el script del excedente', true,
    strpos($sqlN, "COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'CLASIFICACION') IS NULL") !== false
    && strpos($sqlN, 'SET NOEXEC ON') !== false);
chequear('el tope de verdad esta en el codigo, no en el CHECK', [50, false],
    [Proveedores::MAX_PARTES, strpos($sqlN, 'ORDEN <= ') !== false]);
chequear('la clase lo nombra', 'sql/cashflow_prov_locales_pago_n_medios.sql', Proveedores::SCRIPT_N_MEDIOS);

$cuerpoN = function ($metodo) {
    $src = file_get_contents(__DIR__ . '/../Class/Proveedores.php');
    $ini = strpos($src, 'function ' . $metodo . '(');

    return substr($src, $ini, strpos($src, "\n    }\n", $ini) - $ini);
};

chequear('la deteccion mira el CHECK, solo leyendo', true,
    strpos($cuerpoN('tablaNMediosCreada'), "definition LIKE '%>=%'") !== false
    && strpos($cuerpoN('tablaNMediosCreada'), 'ALTER') === false);
chequear('sin el script, el tope es el de antes', true,
    strpos($cuerpoN('capacidadImportacion'), 'self::PARTES_SIN_SCRIPT') !== false);
chequear('y la pestaña avisa nombrando el script', true,
    strpos($cuerpoN('getAvisosConNivel'), 'self::SCRIPT_N_MEDIOS') !== false);

/* ================================================================
   LA PLANILLA
   ================================================================ */
require_once __DIR__ . '/../Class/Horizonte.php';

$hoy = '2026-10-13';

/** Un pendiente, como lo devuelve getPendientes() */
$pend = function ($cod, $n, $importe, $vto = '2026-11-20', $extra = []) {
    return array_merge([
        'COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'RAZON_SOC' => 'N ' . $cod,
        'IMPORTE_PENDIENTE' => $importe, 'IMPORTE_VTO' => $importe, 'IMPUTACIONES' => 0.0,
        'FECHA_VTO' => $vto, 'FECHA_EMIS' => '2026-09-01', 'PLAZO_DIAS' => null,
        'SIN_FECHA_CARGADA' => false, 'FORMA_PAGO' => 'ECHEQ',
        'FORMA_PAGO_VIGENTE' => 'ECHEQ', 'CRONOGRAMA' => true
    ], $extra);
};

$pendientes = [
    $pend('MTDODI', 'A0000500001788', 300000.0),
    // En cuotas: tres vencimientos de 100.000.
    $pend('OGRSA', 'C0001', 100000.0, '2026-10-01', ['SIN_FECHA_CARGADA' => true]),
    $pend('OGRSA', 'C0001', 100000.0, '2026-11-01'),
    $pend('OGRSA', 'C0001', 100000.0, '2026-12-01'),
    // Otra factura del mismo proveedor, para la regla por proveedor.
    $pend('OGRSA', 'D0001', 100000.0, '2026-11-15')
];

$fila = function ($linea, $cod, $n, $fecha, $forma = '', $importe = '') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => ''];
};

$con5 = ['porc' => 5.0, 'error' => null, 'pagos_crono' => null, 'compensada' => true];
$conN = ['partes' => Proveedores::MAX_PARTES, 'cuotas' => true, 'n_medios' => true];
$sinN = ['partes' => Proveedores::PARTES_SIN_SCRIPT, 'cuotas' => false, 'n_medios' => false];

$comparar = function ($filas, $cap, $exc = null, $aperturas = [], $retCfg = null, $pends = null)
        use ($pendientes, $hoy, $con5) {
    return Proveedores::compararImportacion($filas, ($pends === null) ? $pendientes : $pends, [], $hoy,
        $aperturas, true, $retCfg, [], ($exc === null) ? $con5 : $exc, null, $cap);
};

$tresEcheqs = [
    $fila(2, 'MTDODI', 'A0000500001788', '05/12/2026', 'ECHEQ', '100000'),
    $fila(3, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '100000'),
    $fila(4, 'MTDODI', 'A0000500001788', '30/12/2026', 'ECHEQ', '100000')
];

seccion('tres echeqs en tres fechas: se aceptan, 1/3, 2/3 y 3/3');

$c = $comparar($tresEcheqs, $conN);
$porLinea = array_column($c['filas'], null, 'linea');

chequear('las tres se cargan', ['ALTA', 'ALTA', 'ALTA'], array_column($c['filas'], 'estado'));
chequear('el orden lo da la fecha', [3 => 1, 2 => 2, 4 => 3], array_column($c['filas'], 'parte', 'linea'));
chequear('y saben que son tres partes', [3, 3, 3], array_column($c['filas'], 'partes'));
chequear('el resumen cuenta UN comprobante', [1, 1], [$c['resumen']['altas'], $c['resumen']['varios_medios']]);
chequear('el motivo nombra los tres medios', true,
    strpos($porLinea[2]['motivo'], 'Se paga con tres medios') !== false);

$campos = Proveedores::camposDeImportacion($c['filas']);

chequear('tres partes en la apertura, en orden de fecha', ['2026-11-20', '2026-12-05', '2026-12-30'],
    array_column($campos['medios'], 'fecha'));
chequear('con orden 1, 2 y 3', [1, 2, 3], array_column($campos['medios'], 'orden'));
chequear('la tabla de pagos lleva la fecha mas tardia, como siempre', '2026-12-30',
    $campos['pago']['FECHA_PAGO']);

seccion('medios mezclados y repetidos el mismo dia: desempata el orden del archivo');

$c = $comparar([
    $fila(7, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '100000'),
    $fila(5, 'MTDODI', 'A0000500001788', '20/11/2026', 'TRANSFERENCIA', '150000'),
    $fila(9, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '50000')
], $conN);

chequear('se mezclan medios y se repiten', [5 => 1, 7 => 2, 9 => 3], array_column($c['filas'], 'parte', 'linea'));

seccion('la suma de N filas se clasifica: neto, excedente o parcial');

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '100000'),
    $fila(3, 'MTDODI', 'A0000500001788', '05/12/2026', 'ECHEQ', '100000'),
    $fila(4, 'MTDODI', 'A0000500001788', '30/12/2026', 'ECHEQ', '110000')
], $conN);
chequear('310.000 contra 300.000 con 5%: excedente', ['ALTA', 'EXCEDENTE', 10000.0],
    [$c['filas'][0]['estado'], $c['filas'][0]['clasificacion'], $c['filas'][0]['excedente']]);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '100000'),
    $fila(3, 'MTDODI', 'A0000500001788', '05/12/2026', 'ECHEQ', '50000'),
    $fila(4, 'MTDODI', 'A0000500001788', '30/12/2026', 'ECHEQ', '50000')
], $conN);
chequear('200.000 contra 300.000: parcial con su saldo', ['PARCIAL', 100000.0],
    [$c['filas'][0]['clasificacion'], $c['filas'][0]['saldo']]);
chequear('y un parcial deja la tabla de pagos sin fecha', null,
    Proveedores::camposDeImportacion($c['filas'])['pago']['FECHA_PAGO']);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '200000'),
    $fila(3, 'MTDODI', 'A0000500001788', '05/12/2026', 'ECHEQ', '200000'),
    $fila(4, 'MTDODI', 'A0000500001788', '30/12/2026', 'ECHEQ', '100000')
], $conN);
chequear('500.000 contra 300.000: error en las tres, con el maximo', [['ERROR', 'ERROR', 'ERROR'], true],
    [array_column($c['filas'], 'estado'), strpos($c['filas'][0]['motivo'], 'el máximo permitido') !== false]);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '100000'),
    $fila(3, 'MTDODI', 'A0000500001788', '05/12/2026', 'ECHEQ', '0'),
    $fila(4, 'MTDODI', 'A0000500001788', '30/12/2026', 'CAJA', '200000')
], $conN);
chequear('una fila en 0 y una forma que no va: error en las tres', ['ERROR', 'ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));
chequear('y el motivo dice todo', true,
    strpos($c['filas'][0]['motivo'], 'la línea 3 trae un importe de') !== false
    && strpos($c['filas'][0]['motivo'], 'la línea 4 trae «CAJA»') !== false
    && strpos($c['filas'][0]['motivo'], 'No se carga ninguna de las tres filas') !== false);

seccion('la validacion por proveedor sigue funcionando con N filas');

// OGRSA: la factura en cuotas se paga de menos en tres filas (280.000 contra
// 300.000) y la otra de mas (120.000 contra 100.000): el total cierra.
$c = $comparar([
    $fila(2, 'OGRSA', 'C0001', '20/10/2026', 'ECHEQ', '100000'),
    $fila(3, 'OGRSA', 'C0001', '27/10/2026', 'ECHEQ', '100000'),
    $fila(4, 'OGRSA', 'C0001', '03/11/2026', 'TRANSFERENCIA', '80000'),
    $fila(5, 'OGRSA', 'D0001', '03/11/2026', 'ECHEQ', '120000')
], $conN);
chequear('se acepta por el total y las dos quedan compensadas', ['TOTAL', ['COMPENSADA']],
    [$c['proveedores'][0]['resultado'], array_values(array_unique(array_column($c['filas'], 'clasificacion')))]);
chequear('y ninguna fila queda en error', 0, $c['resumen']['errores']);

seccion('un comprobante en cuotas en tres filas: se acepta con el script');

$cuotas = [
    $fila(2, 'OGRSA', 'C0001', '20/10/2026', 'ECHEQ', '120000'),
    $fila(3, 'OGRSA', 'C0001', '27/10/2026', 'ECHEQ', '90000'),
    $fila(4, 'OGRSA', 'C0001', '03/11/2026', 'TRANSFERENCIA', '90000')
];

$c = $comparar($cuotas, $conN);
chequear('las tres se cargan', ['ALTA', 'ALTA', 'ALTA'], array_column($c['filas'], 'estado'));
chequear('por el neto del comprobante entero', ['NETO', 300000.0],
    [$c['filas'][0]['clasificacion'], $c['filas'][0]['importe_pendiente']]);

$c = $comparar($cuotas, $sinN);
chequear('sin el script, error en las tres, como antes', ['ERROR', 'ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));
chequear('nombrando el script', true, strpos($c['filas'][0]['motivo'], Proveedores::SCRIPT_N_MEDIOS) !== false);

$c = $comparar(array_slice($cuotas, 0, 2), $sinN);
chequear('sin el script, dos filas en cuotas siguen siendo error, como antes', true,
    strpos($c['filas'][0]['motivo'], 'es un comprobante en cuotas: cargalo con un solo medio') !== false);

seccion('el tope: mas de MAX_PARTES filas es error, y sin el script el tope es dos');

$muchas = [];
for ($i = 0; $i <= Proveedores::MAX_PARTES; $i++) {
    $muchas[] = $fila($i + 2, 'MTDODI', 'A0000500001788', '20/11/2026', 'ECHEQ', '1000');
}

$c = $comparar($muchas, $conN);
chequear('51 filas: error en todas', [51, 51], [count($c['filas']), $c['resumen']['errores']]);
chequear('el motivo dice el tope', true, strpos($c['filas'][0]['motivo'], 'con 50 medios como máximo') !== false);

$c = $comparar($tresEcheqs, $sinN);
chequear('tres filas sin el script: error que nombra el script', [['ERROR', 'ERROR', 'ERROR'], true],
    [array_column($c['filas'], 'estado'), strpos($c['filas'][0]['motivo'], Proveedores::SCRIPT_N_MEDIOS) !== false]);

$c = Proveedores::compararImportacion($tresEcheqs, $pendientes, [], $hoy, [], true, null, [], $con5);
chequear('sin decir la capacidad es lo de antes, sin nombrar el script', [true, false],
    [$c['filas'][0]['estado'] === 'ERROR', strpos($c['filas'][0]['motivo'], Proveedores::SCRIPT_N_MEDIOS) !== false]);

seccion('reimportar N medios igual es SIN_CAMBIOS');

$ap = ['MTDODI|FAC|A0000500001788' => Proveedores::camposDeImportacion($comparar($tresEcheqs, $conN)['filas'])['medios']];
$c = Proveedores::compararImportacion(array_reverse($tresEcheqs), $pendientes,
    ['MTDODI|FAC|A0000500001788' => ['FECHA_PAGO' => '2026-12-30', 'FORMA_PAGO' => null, 'ESTADO' => 'PREVISTO']],
    $hoy, $ap, true, null, [], $con5, null, $conN);
chequear('en otro orden, es la misma apertura', ['SIN_CAMBIOS', 'SIN_CAMBIOS', 'SIN_CAMBIOS'],
    array_column($c['filas'], 'estado'));

/* ================================================================
   EL EJE
   ================================================================ */

/** Una fila de getPendientes() con horizonte, sin fecha cargada y con su retención */
$item = function ($cod, $n, $importe, $vto, $retCfg = null, $extra = []) use ($pend, $hoy) {
    $r = Proveedores::resolverFechaPago(null, $vto, '2026-09-01', null, $hoy, null, true);

    return Proveedores::conRetencion(array_merge($pend($cod, $n, $importe, $vto), [
        'Pago' => $r['fecha'], 'ORIGEN_FECHA' => $r['origen'], 'FUENTE_FECHA' => $r['origen'],
        'FECHA_PAGO' => null, 'SIN_FECHA_CARGADA' => $r['sin_fecha_cargada'], 'VENCIDA' => $r['vencida'],
        'EXCLUIDO' => false, 'EXCLUIDA_MANUAL' => false, 'EXCLUIDO_PROVEEDOR' => false,
        'EN_MAESTRO' => true, 'SERIE' => 'SIN_RUBRO', 'APERTURA' => null, 'APERTURA_DESCUADRADA' => null,
        'PAGO_IMPORTADO' => null, 'EXCEDENTE' => 0.0, 'PARTE_EXTRA' => false
    ], $extra), $retCfg, $hoy);
};

$medio = function ($orden, $fecha, $importe, $clasif, $pendImport, $netoImport, $forma = 'ECHEQ') {
    return ['orden' => $orden, 'fecha' => $fecha, 'forma' => $forma, 'forma_orig' => null,
        'importe' => $importe, 'usuario' => 'jperez', 'fecha_modif' => '2026-10-13 10:00:00',
        'clasificacion' => $clasif, 'pendiente_import' => $pendImport, 'neto_import' => $netoImport,
        'estado_tango' => 'PREVISTO', 'fecha_real' => null, 'pendiente_real' => null];
};

$porFecha = function ($filas) {
    $r = [];
    foreach ($filas as $f) {
        $r[$f['Pago']] = round((isset($r[$f['Pago']]) ? $r[$f['Pago']] : 0) + $f['IMPORTE_A_PAGAR'], 2);
    }
    ksort($r);

    return $r;
};

/* IIBB 3% y Ganancias 2% a todos: sobre $1.000.000, neto $950.000. */
$cfgRet = [
    'disponible' => true, 'error' => null,
    'impuestos' => [
        'IIBB' => ['ID' => 1, 'CODIGO' => 'IIBB', 'NOMBRE' => 'Iibb', 'ALCANCE' => 'TODOS',
                   'ORDEN' => 1, 'ACTIVO' => 1],
        'GANANCIAS' => ['ID' => 2, 'CODIGO' => 'GANANCIAS', 'NOMBRE' => 'Ganancias', 'ALCANCE' => 'TODOS',
                        'ORDEN' => 2, 'ACTIVO' => 1]
    ],
    'generales' => [
        ['ID' => 10, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.03, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
        ['ID' => 11, 'CONCEPTO' => 'GANANCIAS', 'ALICUOTA' => 0.02, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1]
    ],
    'por_proveedor' => [],
    'credito' => []
];

seccion('eje: tres echeqs, cada uno en su fecha, y la retencion entera en la 1/3');

$itemsD = [$item('MTDODI', 'A0000500001788', 1000000.0, '2026-11-20', $cfgRet)];
$apD = ['MTDODI|FAC|A0000500001788' => [
    $medio(1, '2026-11-20', 300000.0, 'NETO', 1000000.0, 950000.0),
    $medio(2, '2026-12-05', 300000.0, 'NETO', 1000000.0, 950000.0),
    $medio(3, '2026-12-30', 350000.0, 'NETO', 1000000.0, 950000.0)
]];
$abiertas = Proveedores::abrirEnMedios($itemsD, $apD, $hoy, null, $cfgRet);

chequear('tres filas, una por parte', 3, count($abiertas));
chequear('cada importe en su fecha', ['2026-11-20' => 300000.0, '2026-12-05' => 300000.0, '2026-12-30' => 350000.0],
    $porFecha($abiertas));
chequear('etiquetas 1/3, 2/3 y 3/3', ['1/3', '2/3', '3/3'], array_map(function ($f) {
    return $f['APERTURA']['parte'] . '/' . $f['APERTURA']['de'];
}, $abiertas));
chequear('la retencion entera en la primera', [50000.0, 0.0, 0.0], array_column($abiertas, 'RETENIDO'));
chequear('los pendientes suman el del comprobante: las series no ganan ni pierden un peso', 1000000.0,
    round(array_sum(array_column($abiertas, 'IMPORTE_PENDIENTE')), 2));
chequear('y solo la primera cuenta como vencimiento', [true, false, false],
    array_map(['Proveedores', 'cuentaComoVencimiento'], $abiertas));

seccion('eje: apertura descuadrada por un pago en Tango, con tres partes');

$desc = Proveedores::abrirEnMedios([$item('MTDODI', 'A0000500001788', 600000.0, '2026-11-20', $cfgRet,
    ['FECHA_PAGO' => '2026-12-30', 'Pago' => '2026-12-30'])], $apD, $hoy, null, $cfgRet);
chequear('no se parte: todo el saldo en la fecha mas tardia, marcada', [1, '2026-12-30', true],
    [count($desc), $desc[0]['Pago'], !empty($desc[0]['APERTURA_DESCUADRADA'])]);
chequear('y el aviso de la pestaña lo dice', true, strpos(implode(' ',
    Aviso::textos(Proveedores::avisosPendientesConNivel($desc))), 'abiertos en varios medios ya no coinciden') !== false);

seccion('eje: en cuotas, cubre primero las mas viejas y una cuota a medias se parte');

$itemsC = [
    $item('OGRSA', 'C0001', 100000.0, '2026-10-01'),
    $item('OGRSA', 'C0001', 100000.0, '2026-11-01'),
    $item('OGRSA', 'C0001', 100000.0, '2026-12-01')
];
$apC = ['OGRSA|FAC|C0001' => [
    $medio(1, '2026-10-20', 120000.0, 'NETO', 300000.0, 300000.0),
    $medio(2, '2026-10-27', 90000.0, 'NETO', 300000.0, 300000.0),
    $medio(3, '2026-11-03', 90000.0, 'NETO', 300000.0, 300000.0, 'TRANSFERENCIA')
]];
$enCuotas = Proveedores::abrirEnMedios($itemsC, $apC, $hoy, null, null);

chequear('cada medio proyecta su importe en su fecha',
    ['2026-10-20' => 120000.0, '2026-10-27' => 90000.0, '2026-11-03' => 90000.0], $porFecha($enCuotas));
chequear('la cuota 1 la cubre el primer medio; la 2 y la 3 quedan partidas entre dos', [
    ['2026-10-01', '2026-10-20', 100000.0], ['2026-11-01', '2026-10-20', 20000.0],
    ['2026-11-01', '2026-10-27', 80000.0], ['2026-12-01', '2026-10-27', 10000.0],
    ['2026-12-01', '2026-11-03', 90000.0]
], array_map(function ($f) { return [$f['FECHA_VTO'], $f['Pago'], $f['IMPORTE_A_PAGAR']]; }, $enCuotas));
chequear('cada fila dice su parte: 1/3, 1/3, 2/3, 2/3, 3/3', ['1/3', '1/3', '2/3', '2/3', '3/3'],
    array_map(function ($f) { return $f['PAGO_IMPORTADO']['parte'] . '/' . $f['PAGO_IMPORTADO']['de']; }, $enCuotas));
chequear('cuentan tres vencimientos, no cinco', 3,
    count(array_filter($enCuotas, ['Proveedores', 'cuentaComoVencimiento'])));
chequear('los pendientes suman el del comprobante', 300000.0,
    round(array_sum(array_column($enCuotas, 'IMPORTE_PENDIENTE')), 2));

seccion('eje: en cuotas con un parcial en tres filas, el saldo con la jerarquia');

$apP = ['OGRSA|FAC|C0001' => [
    $medio(1, '2026-10-20', 50000.0, 'PARCIAL', 300000.0, 300000.0),
    $medio(2, '2026-10-27', 60000.0, 'PARCIAL', 300000.0, 300000.0),
    $medio(3, '2026-11-03', 40000.0, 'PARCIAL', 300000.0, 300000.0)
]];
$parcial = Proveedores::abrirEnMedios($itemsC, $apP, $hoy, null, null);
$saldos = array_values(array_filter($parcial, function ($f) { return $f['PAGO_IMPORTADO']['tipo'] === 'SALDO'; }));

chequear('lo pagado, en sus fechas', ['2026-10-20' => 50000.0, '2026-10-27' => 60000.0, '2026-11-03' => 40000.0],
    $porFecha(array_filter($parcial, function ($f) { return $f['PAGO_IMPORTADO']['tipo'] === 'PAGADO'; })));
chequear('el saldo: media cuota 2 y la cuota 3 entera, en sus vencimientos',
    [['2026-11-01', 50000.0], ['2026-12-01', 100000.0]],
    array_map(function ($f) { return [$f['Pago'], $f['IMPORTE_A_PAGAR']]; }, $saldos));

seccion('eje: en cuotas con excedente, el excedente en la ultima cuota cubierta');

$apE = ['OGRSA|FAC|C0001' => [
    $medio(1, '2026-10-20', 100000.0, 'EXCEDENTE', 300000.0, 300000.0),
    $medio(2, '2026-10-27', 100000.0, 'EXCEDENTE', 300000.0, 300000.0),
    $medio(3, '2026-11-03', 110000.0, 'EXCEDENTE', 300000.0, 300000.0)
]];
$exc = Proveedores::abrirEnMedios($itemsC, $apE, $hoy, null, null);

chequear('cada medio proyecta su importe', ['2026-10-20' => 100000.0, '2026-10-27' => 100000.0,
    '2026-11-03' => 110000.0], $porFecha($exc));
chequear('el excedente, en la cuota de diciembre, con el ultimo medio', [['2026-12-01', 10000.0]],
    array_values(array_map(function ($f) { return [$f['FECHA_VTO'], $f['EXCEDENTE']]; },
        array_filter($exc, function ($f) { return $f['EXCEDENTE'] != 0; }))));

seccion('eje: con la planilla vacia no se mueve un peso, y las particiones cierran');

$hTab = new Horizonte(90, 3, [], new DateTime($hoy));
$repartir = new ReflectionMethod('ProveedoresProvider', 'repartir');
$repartir->setAccessible(true);
$provTab = new ProveedoresProvider('PROV_LOCALES');

$universo = array_merge($itemsD, $itemsC, [$item('OGXX', 'K0001', 50000.0, '2026-11-10', null,
    ['CRONOGRAMA' => false])]);
chequear('sin aperturas, las series son identicas', $repartir->invoke($provTab, $hTab, $universo),
    $repartir->invoke($provTab, $hTab, Proveedores::abrirEnMedios($universo, [], $hoy, null, $cfgRet)));

$filasTab = Proveedores::abrirEnMedios($universo, array_merge($apD, $apC), $hoy, null, $cfgRet);
$series = $repartir->invoke($provTab, $hTab, $filasTab);
$enEje = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'], 2); };

chequear('por como se paga', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS']) + $enEje($series['PAGOS_FUERA_CRONOGRAMA'])
        + $enEje($series['PAGOS_EXCLUIDOS_FACTURA']) + $enEje($series['PAGOS_EXCLUIDOS_PROVEEDOR']), 2));
chequear('por que rubro es', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS_OPERATIVOS']) + $enEje($series['PAGOS_EXCLUIDOS']), 2));
chequear('la pestaña y el tablero suman lo mismo', round(array_sum(array_column($filasTab, 'IMPORTE_A_PAGAR')), 2),
    $enEje($series['PAGOS_TODO']));
chequear('y el tablero no gana ni pierde un peso con las aperturas',
    $enEje($repartir->invoke($provTab, $hTab, Proveedores::abrirEnMedios($universo, [], $hoy, null, $cfgRet))['PAGOS_TODO']),
    $enEje($series['PAGOS_TODO']));

/* ================================================================
   EL CABLEADO
   ================================================================ */
seccion('el cableado del backend');

$ctrl = file_get_contents(__DIR__ . '/../Controller/ProveedoresController.php');

chequear('la previsualizacion le pasa la capacidad a la regla', true,
    strpos($ctrl, '$prov->capacidadImportacion()') !== false);
chequear('y la confirmacion recalcula con la misma', true,
    strpos($cuerpoN('aplicarImportacion'), '$this->capacidadImportacion()') !== false);
chequear('guardarApertura no escribe mas de dos partes sin el script', true,
    strpos($cuerpoN('guardarApertura'), 'self::SCRIPT_N_MEDIOS') !== false);
chequear('ya no existe el tope fijo de dos', false,
    strpos(file_get_contents(__DIR__ . '/../Class/Proveedores.php'), 'MAX_MEDIOS') !== false);
