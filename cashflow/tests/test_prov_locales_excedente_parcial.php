<?php
/**
 * Proveedores Locales: el pago con EXCEDENTE y el pago PARCIAL.
 *
 * Todo sin base, como test_prov_locales_medios.php: el % llega por parametro,
 * la clasificacion (clasificarImporte()), la importacion (compararImportacion())
 * y como entra al eje (proyectarPagoImportado()) son estaticas y puras. Lo que
 * toca la base se fija leyendo el metodo.
 *
 * Las reglas, en una linea cada una:
 *   - el % es uno, general, >= 0 y < 100 con dos decimales; 0 es valido;
 *   - sin el % (o roto) no se acepta ningun excedente, y se avisa en critico;
 *   - neto ± $1 es NETO; hasta neto × (1 + %) + $1 es EXCEDENTE; menos que
 *     neto − $1 es PARCIAL; mas que el maximo, cero o negativo es ERROR;
 *   - lo que no es NETO se guarda, y se proyecta lo pagado.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Parametros.php';

/** Que lance, y que el mensaje diga lo que tiene que decir */
$lanza = function ($nombre, $fn, $dice) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $dice) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
};

/** El cuerpo de un metodo, para fijar lo que toca la base leyendolo */
$cuerpo = function ($archivo, $firma) {
    $fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/' . $archivo));
    $desde = substr($fuente, strpos($fuente, $firma));

    return substr($desde, 0, strpos($desde, "\n    }\n"));
};

/* ================================================================
   EL % DE EXCEDENTE PERMITIDO
   ================================================================ */
seccion('el %: 0, 5 y 12,5 se guardan');

chequear('0 es válido: no se acepta excedente', 0.0, Proveedores::validarPorcExcedente('0'));
chequear('5', 5.0, Proveedores::validarPorcExcedente(5));
chequear('12,5 con coma', 12.5, Proveedores::validarPorcExcedente('12,5'));
chequear('12.5 con punto', 12.5, Proveedores::validarPorcExcedente('12.5'));
chequear('99,99 es el máximo', 99.99, Proveedores::validarPorcExcedente('99,99'));

seccion('el %: -1, 100, vacío o texto se rechazan, y dicen por qué');

$lanza('−1', function () { Proveedores::validarPorcExcedente('-1'); }, 'de 0 a 99,99');
$lanza('100', function () { Proveedores::validarPorcExcedente('100'); }, 'menor a 100');
$lanza('150', function () { Proveedores::validarPorcExcedente(150); }, 'menor a 100');
$lanza('vacío', function () { Proveedores::validarPorcExcedente(''); }, 'de 0 a 99,99');
$lanza('texto', function () { Proveedores::validarPorcExcedente('cinco'); }, 'de 0 a 99,99');
$lanza('tres decimales', function () { Proveedores::validarPorcExcedente('5,125'); },
    'hasta dos decimales');
$lanza('con separador de miles', function () { Proveedores::validarPorcExcedente('1.000,5'); },
    'de 0 a 99,99');

seccion('el % guardado: lo que no sirve es null con el motivo, nunca un 5 de respaldo');

$p = Proveedores::porcExcedenteDesdeValor('5');
chequear('5 guardado vale 5', [5.0, null], [$p['porc'], $p['error']]);

$p = Proveedores::porcExcedenteDesdeValor('0');
chequear('0 guardado vale 0, no null', 0.0, $p['porc']);

$p = Proveedores::porcExcedenteDesdeValor(null);
chequear('sin la clave: null', null, $p['porc']);
chequear('y el motivo nombra el script y dónde cargarlo', true,
    strpos($p['error'], Proveedores::SCRIPT_EXCEDENTE) !== false
    && strpos($p['error'], 'Parámetros › Prov. Locales') !== false);

$p = Proveedores::porcExcedenteDesdeValor('abc');
chequear('roto: null', null, $p['porc']);
chequear('y el motivo muestra lo que está guardado', true, strpos($p['error'], '«abc»') !== false);

$p = Proveedores::porcExcedenteDesdeValor('120');
chequear('fuera de rango: null', null, $p['porc']);

seccion('el % tiene su propio camino de guardado');

chequear('Parametros repite la clave de Proveedores', Proveedores::CLAVE_PORC_EXCEDENTE,
    Parametros::CLAVE_PORC_EXCEDENTE_PROV);

$save = $cuerpo('Parametros.php', 'public function saveParametro(');

chequear('saveParametro() rechaza la clave antes de escribir', true,
    strpos($save, 'if ($clave === self::CLAVE_PORC_EXCEDENTE_PROV) {') !== false
    && strpos($save, 'if ($clave === self::CLAVE_PORC_EXCEDENTE_PROV) {') < strpos($save, 'UPDATE'));

$guardar = $cuerpo('Proveedores.php', 'public function guardarPorcExcedente(');

chequear('guardarPorcExcedente() valida con la misma regla', true,
    strpos($guardar, 'self::validarPorcExcedente($valor)') !== false);
chequear('firma con el usuario de escritura', true,
    strpos($guardar, 'AuthCashflow::usuarioDeEscritura($usuario)') !== false);
chequear('y deja quién y cuándo', true, strpos($guardar, 'Auditoria::SET_MODIF') !== false);

seccion('el script siembra el % en 5 sin pisarlo');

$sql = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . Proveedores::SCRIPT_EXCEDENTE));

chequear('lo siembra en 5', true,
    strpos($sql, "VALUES (''prov_locales_pct_excedente'', ''5''") !== false);
chequear('sólo si no existe', true,
    strpos($sql, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'prov_locales_pct_excedente')") !== false);
chequear('las aperturas que ya están quedan como NETO', true,
    strpos($sql, "DEFAULT ('NETO')") !== false);
chequear('la clasificación es una de tres', true,
    strpos($sql, "CHECK (CLASIFICACION IN ('NETO', 'EXCEDENTE', 'PARCIAL'))") !== false);

/* ================================================================
   LA CLASIFICACION DEL IMPORTE
   ================================================================ */
seccion('la clasificación: neto $1.000.000 y 5%');

$cl = function ($importe, $porc = 5.0, $neto = 1000000.0) {
    return Proveedores::clasificarImporte($importe, $neto, $porc, 'Falta el %.');
};

chequear('vacío es neto', 'NETO', $cl(null)['clasificacion']);
chequear('$1.000.000,80 es neto: el peso de tolerancia', 'NETO', $cl(1000000.80)['clasificacion']);
chequear('$999.999,20 también', 'NETO', $cl(999999.20)['clasificacion']);

$c = $cl(1030000);
chequear('$1.030.000 es excedente', 'EXCEDENTE', $c['clasificacion']);
chequear('de $30.000, un 3%', [30000.0, 3.0], [$c['excedente'], $c['porc']]);
chequear('el máximo es neto × 1,05', 1050000.0, $c['maximo']);

chequear('$1.050.000,90 es excedente: el peso de tolerancia sobre el máximo', 'EXCEDENTE',
    $cl(1050000.90)['clasificacion']);

$c = $cl(1051500);
chequear('$1.051.500 es error', 'ERROR', $c['clasificacion']);
chequear('y el motivo dice el máximo y el %', 'El importe ($ 1.051.500,00) supera el neto a pagar '
    . '($ 1.000.000,00) en $ 51.500,00 (5,15%): el máximo permitido es $ 1.050.000,00 (5%).', $c['motivo']);

$c = $cl(400000);
chequear('$400.000 es parcial, sin mínimo', 'PARCIAL', $c['clasificacion']);
chequear('con un saldo de $600.000', 600000.0, $c['saldo']);
chequear('$1 también es parcial', 'PARCIAL', $cl(1)['clasificacion']);

chequear('0 es error', 'ERROR', $cl(0)['clasificacion']);
chequear('negativo es error', 'ERROR', $cl(-5)['clasificacion']);
chequear('y dice que tiene que ser mayor a cero', true,
    strpos($cl(0)['motivo'], 'tiene que ser mayor a cero') !== false);

seccion('la clasificación con 0%: no se acepta excedente');

chequear('neto + $1,50 es error', 'ERROR', $cl(1000001.50, 0.0)['clasificacion']);
chequear('el máximo es el neto', true,
    strpos($cl(1030000, 0.0)['motivo'], 'el máximo permitido es $ 1.000.000,00 (0%)') !== false);
chequear('el neto ± $1 sigue siendo neto', 'NETO', $cl(1000001.0, 0.0)['clasificacion']);
chequear('y el parcial sigue siendo parcial', 'PARCIAL', $cl(400000, 0.0)['clasificacion']);

seccion('la clasificación sin %: el excedente es error, el parcial no');

$c = $cl(1030000, null);
chequear('$1.030.000 es error', 'ERROR', $c['clasificacion']);
chequear('y el motivo dice por qué', true, strpos($c['motivo'], 'Falta el %.') !== false);
chequear('$400.000 sigue siendo parcial', 'PARCIAL', $cl(400000, null)['clasificacion']);
chequear('y el neto, neto', 'NETO', $cl(1000000, null)['clasificacion']);

/* ================================================================
   LA PREVISUALIZACION
   ================================================================ */
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
    $pend('PROVA', 'A0001', 1000000.0),
    $pend('PROVR', 'R0001', 1000000.0),
    // En cuotas: tres vencimientos de 100.000.
    $pend('PROVC', 'C0001', 100000.0, '2026-10-01', ['SIN_FECHA_CARGADA' => true]),
    $pend('PROVC', 'C0001', 100000.0, '2026-11-01'),
    $pend('PROVC', 'C0001', 100000.0, '2026-12-01')
];

/** Una fila de la planilla */
$fila = function ($linea, $cod, $n, $fecha, $forma = '', $importe = '') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => ''];
};

$con5 = ['porc' => 5.0, 'error' => null, 'pagos_crono' => null];

$comparar = function ($filas, $exc, $aperturas = [], $retCfg = null, $pends = null) use ($pendientes, $hoy) {
    return Proveedores::compararImportacion($filas, ($pends === null) ? $pendientes : $pends, [], $hoy,
        $aperturas, true, $retCfg, [], $exc);
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

seccion('previsualización: un medio con excedente');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '1030000')], $con5);
$f = $c['filas'][0];

chequear('se carga', 'ALTA', $f['estado']);
chequear('con excedente', 'EXCEDENTE', $f['clasificacion']);
chequear('el motivo dice neto, excedente y %', 'Se paga $ 1.030.000,00: neto $ 1.000.000,00 + excedente '
    . '$ 30.000,00 (3%), que queda a cuenta del proveedor en Tango.', $f['motivo']);
chequear('el chip cuenta el comprobante y el excedente', [1, 30000.0],
    [$c['resumen']['con_excedente'], $c['resumen']['importe_excedente']]);
chequear('y el % viaja en la respuesta', 5.0, $c['porc_excedente']);
chequear('las cuotas no viajan al navegador', false, array_key_exists('_vtos', $f));

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '1100000')], $con5);
chequear('$1.100.000 queda en error', 'ERROR', $c['filas'][0]['estado']);
chequear('y el mensaje dice que el máximo es $1.050.000', true,
    strpos($c['filas'][0]['motivo'], 'el máximo permitido es $ 1.050.000,00 (5%)') !== false);

seccion('previsualización: un medio parcial');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '400000')], $con5);
$f = $c['filas'][0];

chequear('se carga', 'ALTA', $f['estado']);
chequear('como parcial', 'PARCIAL', $f['clasificacion']);
chequear('el motivo dice el saldo y en qué fecha se proyecta', 'Se paga $ 400.000,00 de un neto de '
    . '$ 1.000.000,00. El saldo de $ 600.000,00 se proyecta el 20/11/2026 (vencimiento).', $f['motivo']);
chequear('el chip cuenta el comprobante y lo pagado', [1, 400000.0],
    [$c['resumen']['pagos_parciales'], $c['resumen']['importe_parcial']]);

seccion('previsualización: sin el script es la de antes');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '1030000')], null);
chequear('un excedente es error', 'ERROR', $c['filas'][0]['estado']);
$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '400000')], null);
chequear('un parcial también', 'ERROR', $c['filas'][0]['estado']);
chequear('y no hay % que comparar', [false, null], [$c['con_excedente'], $c['porc_excedente']]);

seccion('previsualización: sin el %, se rechaza el excedente y se avisa en crítico');

$sinPorc = ['porc' => null, 'error' => 'Falta el % de excedente permitido.', 'pagos_crono' => null];
$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '1030000'),
                $fila(3, 'PROVR', 'R0001', '20/10/2026', '', '400000')], $sinPorc);
$porLinea = array_column($c['filas'], null, 'linea');

chequear('el excedente queda en error', 'ERROR', $porLinea[2]['estado']);
chequear('el parcial se carga igual', ['ALTA', 'PARCIAL'],
    [$porLinea[3]['estado'], $porLinea[3]['clasificacion']]);
chequear('y hay un aviso crítico que lo dice', true,
    count($c['criticos']) === 1 && strpos($c['criticos'][0], 'Falta el % de excedente') !== false);

seccion('previsualización: el total sin retenciones dentro del máximo es excedente');

// Neto $950.000: el total de $1.000.000 es un 5,26% más.
$c = $comparar([$fila(2, 'PROVR', 'R0001', '20/10/2026', '', '1000000')], $con5, [], $cfgRet);
chequear('con 5% supera el máximo y es error', 'ERROR', $c['filas'][0]['estado']);
chequear('y el motivo ayuda: coincide con el total sin retenciones', true,
    strpos($c['filas'][0]['motivo'], 'coincide con el total de la factura sin retenciones') !== false
    && strpos($c['filas'][0]['motivo'], 'el máximo permitido es $ 997.500,00') !== false);

$c = $comparar([$fila(2, 'PROVR', 'R0001', '20/10/2026', '', '1000000')],
    ['porc' => 6.0, 'error' => null, 'pagos_crono' => null], [], $cfgRet);
chequear('con 6% entra, como excedente', ['ALTA', 'EXCEDENTE', 50000.0],
    [$c['filas'][0]['estado'], $c['filas'][0]['clasificacion'], $c['filas'][0]['excedente']]);

seccion('previsualización: dos medios se clasifican por la suma');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '600000'),
                $fila(3, 'PROVA', 'A0001', '27/10/2026', 'TRANSFERENCIA', '420000')], $con5);

chequear('las dos se cargan, con excedente de $20.000',
    [['ALTA', 'EXCEDENTE', 20000.0], ['ALTA', 'EXCEDENTE', 20000.0]],
    array_map(function ($f) { return [$f['estado'], $f['clasificacion'], $f['excedente']]; }, $c['filas']));
chequear('el resumen cuenta un comprobante en dos medios y con excedente', [1, 1],
    [$c['resumen']['dos_medios'], $c['resumen']['con_excedente']]);

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '300000'),
                $fila(3, 'PROVA', 'A0001', '27/10/2026', 'TRANSFERENCIA', '200000')], $con5);

chequear('300.000 + 200.000 es parcial', ['PARCIAL', 'PARCIAL'], array_column($c['filas'], 'clasificacion'));
chequear('con saldo de $500.000', 500000.0, $c['filas'][0]['saldo']);

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '600000'),
                $fila(3, 'PROVA', 'A0001', '27/10/2026', 'TRANSFERENCIA', '500000')], $con5);
chequear('600.000 + 500.000 supera el máximo: error en las dos', ['ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));
chequear('y el motivo habla de la suma', true,
    strpos($c['filas'][0]['motivo'], 'los importes suman $ 1.100.000,00') !== false);

seccion('previsualización: un segundo parcial se compara contra lo que dejó el primero');

// Tango ya registró $400.000: quedan $600.000.
$despues = [$pend('PROVA', 'A0001', 600000.0)];

$c = $comparar([$fila(2, 'PROVA', 'A0001', '03/11/2026', '', '200000')], $con5, [], null, $despues);
chequear('$200.000 es parcial contra un neto de $600.000', true,
    $c['filas'][0]['clasificacion'] === 'PARCIAL'
    && strpos($c['filas'][0]['motivo'], 'de un neto de $ 600.000,00') !== false);

$c = $comparar([$fila(2, 'PROVA', 'A0001', '03/11/2026', '', '600000')], $con5, [], null, $despues);
chequear('$600.000 es el neto: cancela', 'NETO', $c['filas'][0]['clasificacion']);

seccion('previsualización: cuotas');

$c = $comparar([$fila(2, 'PROVC', 'C0001', '20/10/2026', '', '150000')], $con5);
$f = $c['filas'][0];

chequear('un medio en cuotas puede ser parcial', ['ALTA', 'PARCIAL'], [$f['estado'], $f['clasificacion']]);
chequear('el saldo empieza en la cuota que queda a medias', true,
    strpos($f['motivo'], 'El saldo de $ 150.000,00 se proyecta el 01/11/2026 (vencimiento), y el resto '
        . 'de las cuotas en sus fechas.') !== false);

$c = $comparar([$fila(2, 'PROVC', 'C0001', '20/10/2026', '', '310000')], $con5);
chequear('el tope es sobre el neto entero: 310.000 de 300.000 es excedente', 'EXCEDENTE',
    $c['filas'][0]['clasificacion']);

$c = $comparar([$fila(2, 'PROVC', 'C0001', '20/10/2026', 'ECHEQ', '100000'),
                $fila(3, 'PROVC', 'C0001', '27/10/2026', 'ECHEQ', '50000')], $con5);
chequear('dos medios con cuotas sigue siendo error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));

/* ================================================================
   LO QUE SE GUARDA
   ================================================================ */
seccion('lo que se guarda: un excedente con un medio');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'echeq', '1030000')], $con5);
$campos = Proveedores::camposDeImportacion($c['filas']);

chequear('la tabla de pagos lleva la fecha y la forma de la planilla', ['2026-10-20', 'ECHEQ', 'echeq'],
    [$campos['pago']['FECHA_PAGO'], $campos['pago']['FORMA_PAGO'], $campos['pago']['FORMA_PAGO_ORIG']]);
chequear('y se guarda una parte, con su clasificación y contra qué se comparó', [[
    'orden' => 1, 'fecha' => '2026-10-20', 'forma' => null, 'forma_orig' => null, 'importe' => 1030000.0,
    'clasificacion' => 'EXCEDENTE', 'pendiente_import' => 1000000.0, 'neto_import' => 1000000.0
]], $campos['medios']);
chequear('nunca escribe FORMA_PAGO_CRONOGRAMA', false, array_key_exists('FORMA_PAGO_CRONOGRAMA', $campos['pago']));

seccion('lo que se guarda: un parcial deja la tabla de pagos sin fecha');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '400000')], $con5);
$campos = Proveedores::camposDeImportacion($c['filas']);

chequear('sin fecha: el saldo va por la jerarquía de siempre', null, $campos['pago']['FECHA_PAGO']);
chequear('y la parte pagada con su fecha', ['2026-10-20', 400000.0, 'PARCIAL'],
    [$campos['medios'][0]['fecha'], $campos['medios'][0]['importe'], $campos['medios'][0]['clasificacion']]);

seccion('lo que se guarda: dos medios con excedente');

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', 'ECHEQ', '600000'),
                $fila(3, 'PROVA', 'A0001', '27/10/2026', 'TRANSFERENCIA', '420000')], $con5);
$campos = Proveedores::camposDeImportacion($c['filas']);

chequear('la tabla de pagos lleva la fecha más tardía, para conciliar', '2026-10-27',
    $campos['pago']['FECHA_PAGO']);
chequear('cada medio con su forma, su importe y EXCEDENTE',
    [['ECHEQ', 600000.0, 'EXCEDENTE'], ['TRANSFERENCIA', 420000.0, 'EXCEDENTE']],
    array_map(function ($m) { return [$m['forma'], $m['importe'], $m['clasificacion']]; }, $campos['medios']));

seccion('reimportar igual es SIN_CAMBIOS, y otro importe reemplaza');

$guardado = ['PROVA|FAC|A0001' => [['orden' => 1, 'fecha' => '2026-10-20', 'forma' => null,
    'importe' => 400000.0, 'clasificacion' => 'PARCIAL']]];

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '400000')], $con5, $guardado);
chequear('el mismo parcial es SIN_CAMBIOS', 'SIN_CAMBIOS', $c['filas'][0]['estado']);

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '450000')], $con5, $guardado);
chequear('otro importe es CAMBIO y dice qué reemplaza', true, $c['filas'][0]['estado'] === 'CAMBIO'
    && strpos($c['filas'][0]['motivo'], 'Reemplaza lo cargado (pago $ 400.000,00 el 20/10/2026)') !== false);

$c = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026')], $con5, $guardado);
chequear('una fila sin importe lo deshace: vuelve al neto', true, $c['filas'][0]['estado'] === 'CAMBIO'
    && strpos($c['filas'][0]['motivo'], 'Deja de ser un pago parcial') !== false
    && Proveedores::camposDeImportacion($c['filas'])['medios'] === null);

/* ================================================================
   LA CONFIRMACION
   ================================================================ */
seccion('confirmar: cambiar el % entre la previsualización y la confirmación frena');

$vistas = $comparar([$fila(2, 'PROVA', 'A0001', '20/10/2026', '', '1030000')], $con5)['filas'];

chequear('con el mismo %, nada cambió', [], Proveedores::diferenciasConLoVisto($vistas, $vistas, 5.0, 5.0));
chequear('con otro %, frena aunque ninguna línea cambie de estado',
    ['el % de excedente permitido pasó de 5% a 3%'],
    Proveedores::diferenciasConLoVisto($vistas, $vistas, 5.0, 3.0));
chequear('un % que no vino del navegador también frena', 1,
    count(Proveedores::diferenciasConLoVisto($vistas, $vistas, false, 5.0)));
chequear('sin el script no se compara el %', [], Proveedores::diferenciasConLoVisto($vistas, $vistas));

$actuales = $vistas;
$actuales[0]['clasificacion'] = 'NETO';
chequear('una línea que cambió de clasificación frena', ['línea 2: pasó de EXCEDENTE a NETO'],
    Proveedores::diferenciasConLoVisto($vistas, $actuales, 5.0, 5.0));

$aplicar = $cuerpo('Proveedores.php', 'public function aplicarImportacion(');

chequear('aplicarImportacion() vuelve a leer el % antes de comparar', true,
    strpos($aplicar, '$this->porcExcedente = null;') !== false
    && strpos($aplicar, '$this->porcExcedente = null;') < strpos($aplicar, 'self::compararImportacion('));
chequear('y le pasa a diferenciasConLoVisto() el visto y el de ahora', true,
    strpos($aplicar, "\$comparacion['porc_excedente']") !== false);

/* ================================================================
   LA PROYECCION: QUE SE PROYECTA, CUANTO Y CUANDO
   ================================================================ */
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';
require_once __DIR__ . '/../Class/Horizonte.php';

/** Una fila de getPendientes() con horizonte, sin fecha cargada y con su retención */
$item = function ($cod, $n, $importe, $vto, $retCfg = null, $extra = []) use ($pend, $hoy) {
    $r = Proveedores::resolverFechaPago(null, $vto, '2026-09-01', null, $hoy, null,
        isset($extra['CRONOGRAMA']) ? $extra['CRONOGRAMA'] : true);

    return Proveedores::conRetencion(array_merge($pend($cod, $n, $importe, $vto), [
        'Pago' => $r['fecha'], 'ORIGEN_FECHA' => $r['origen'], 'FUENTE_FECHA' => $r['origen'],
        'FECHA_PAGO' => null, 'SIN_FECHA_CARGADA' => $r['sin_fecha_cargada'], 'VENCIDA' => $r['vencida'],
        'EXCLUIDO' => false, 'EXCLUIDA_MANUAL' => false, 'EXCLUIDO_PROVEEDOR' => false,
        'EN_MAESTRO' => true, 'SERIE' => 'SIN_RUBRO', 'APERTURA' => null, 'APERTURA_DESCUADRADA' => null,
        'PAGO_IMPORTADO' => null, 'EXCEDENTE' => 0.0, 'PARTE_EXTRA' => false
    ], $extra), $retCfg, $hoy);
};

/** Una parte guardada, como la devuelve getAperturas() */
$medio = function ($orden, $fecha, $importe, $clasif, $pendImport, $netoImport, $forma = null, $extra = []) {
    return array_merge(['orden' => $orden, 'fecha' => $fecha, 'forma' => $forma, 'forma_orig' => null,
        'importe' => $importe, 'usuario' => 'jperez', 'fecha_modif' => '2026-10-13 10:00:00',
        'clasificacion' => $clasif, 'pendiente_import' => $pendImport, 'neto_import' => $netoImport,
        'estado_tango' => 'PREVISTO', 'fecha_real' => null, 'pendiente_real' => null], $extra);
};

$suma = function ($filas, $campo) {
    return round(array_sum(array_map(function ($f) use ($campo) { return floatval($f[$campo]); }, $filas)), 2);
};

seccion('proyección: excedente con un medio');

$items = [$item('PROVA', 'A0001', 1000000.0, '2026-11-20')];
$ap = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 1030000.0, 'EXCEDENTE', 1000000.0, 1000000.0)]];
$p = Proveedores::abrirEnMedios($items, $ap, $hoy);

chequear('una fila', 1, count($p));
chequear('en la fecha de la planilla, por neto + excedente', ['2026-10-20', 1030000.0, 30000.0],
    [$p[0]['Pago'], $p[0]['IMPORTE_A_PAGAR'], $p[0]['EXCEDENTE']]);
chequear('el pendiente sigue siendo el de Tango', 1000000.0, $p[0]['IMPORTE_PENDIENTE']);
chequear('y la fila dice que es un pago importado con excedente', ['EXCEDENTE', 'PAGADO', 30000.0, 3.0],
    [$p[0]['PAGO_IMPORTADO']['clasificacion'], $p[0]['PAGO_IMPORTADO']['tipo'],
     $p[0]['PAGO_IMPORTADO']['excedente'], $p[0]['PAGO_IMPORTADO']['porc_excedente']]);

seccion('proyección: excedente con dos medios, cada parte su importe');

$ap = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 600000.0, 'EXCEDENTE', 1000000.0, 1000000.0, 'ECHEQ'),
                             $medio(2, '2026-10-27', 420000.0, 'EXCEDENTE', 1000000.0, 1000000.0, 'TRANSFERENCIA')]];
$p = Proveedores::abrirEnMedios($items, $ap, $hoy);

chequear('dos filas, cada una en su fecha y por su importe: ninguna se lleva el resto',
    [['2026-10-20', 600000.0], ['2026-10-27', 420000.0]],
    array_map(function ($f) { return [$f['Pago'], $f['IMPORTE_A_PAGAR']]; }, $p));
chequear('el excedente suma $20.000, repartido en proporción', [20000.0, 11764.71],
    [$suma($p, 'EXCEDENTE'), $p[0]['EXCEDENTE']]);
chequear('los pendientes suman el de Tango al centavo', 1000000.0, $suma($p, 'IMPORTE_PENDIENTE'));
chequear('la segunda no cuenta como otro vencimiento', [true, false],
    array_map(['Proveedores', 'cuentaComoVencimiento'], $p));

seccion('proyección: parcial, lo pagado en la planilla y el saldo con la jerarquía');

$ap = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 400000.0, 'PARCIAL', 1000000.0, 1000000.0)]];
$p = Proveedores::abrirEnMedios($items, $ap, $hoy);

chequear('dos filas', 2, count($p));
chequear('lo pagado en la fecha de la planilla', ['2026-10-20', 400000.0, 'PAGADO', 'ARCHIVO'],
    [$p[0]['Pago'], $p[0]['IMPORTE_A_PAGAR'], $p[0]['PAGO_IMPORTADO']['tipo'], $p[0]['FUENTE_FECHA']]);
chequear('el saldo en su vencimiento, sin fecha cargada', ['2026-11-20', 600000.0, 'SALDO', null, 'VENCIMIENTO'],
    [$p[1]['Pago'], $p[1]['IMPORTE_A_PAGAR'], $p[1]['PAGO_IMPORTADO']['tipo'], $p[1]['FECHA_PAGO'],
     $p[1]['FUENTE_FECHA']]);
chequear('sin excedente', 0.0, $suma($p, 'EXCEDENTE'));
chequear('el comprobante se cuenta una vez', [true, false], array_map(['Proveedores', 'cuentaComoVencimiento'], $p));

$crono = [['fecha' => '2026-10-28', 'nombre' => '4to miércoles'], ['fecha' => '2026-11-25', 'nombre' => '4to miércoles']];
$p = Proveedores::abrirEnMedios($items, $ap, $hoy, $crono);
chequear('del cronograma: el saldo va al próximo día de pago', ['2026-11-25', true],
    [$p[1]['Pago'], $p[1]['PAGO_CRONO']]);

$vencido = [$item('PROVA', 'A0001', 1000000.0, '2026-10-01', null, ['CRONOGRAMA' => false])];
$p = Proveedores::abrirEnMedios($vencido, $ap, $hoy);
chequear('ya vencido y fuera del cronograma: el saldo va al primer día del eje, como siempre',
    [$hoy, true], [$p[1]['Pago'], $p[1]['SIN_FECHA_CARGADA']]);
$p = Proveedores::abrirEnMedios([$item('PROVA', 'A0001', 1000000.0, '2026-10-01')], $ap, $hoy, $crono);
chequear('ya vencido y del cronograma: el próximo día de pago', '2026-10-28', $p[1]['Pago']);

seccion('proyección: parcial con dos medios');

$ap = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 300000.0, 'PARCIAL', 1000000.0, 1000000.0, 'ECHEQ'),
                             $medio(2, '2026-10-27', 200000.0, 'PARCIAL', 1000000.0, 1000000.0, 'TRANSFERENCIA')]];
$p = Proveedores::abrirEnMedios($items, $ap, $hoy);

chequear('las dos partes y el saldo', [['2026-10-20', 300000.0, 'PAGADO'], ['2026-10-27', 200000.0, 'PAGADO'],
    ['2026-11-20', 500000.0, 'SALDO']],
    array_map(function ($f) { return [$f['Pago'], $f['IMPORTE_A_PAGAR'], $f['PAGO_IMPORTADO']['tipo']]; }, $p));

seccion('proyección: cuotas, la más vieja primero');

$cuotas = [$item('PROVC', 'C0001', 100000.0, '2026-12-01'), $item('PROVC', 'C0001', 100000.0, '2026-10-01'),
           $item('PROVC', 'C0001', 100000.0, '2026-11-01')];
$ap = ['PROVC|FAC|C0001' => [$medio(1, '2026-10-20', 150000.0, 'PARCIAL', 300000.0, 300000.0)]];
$p = Proveedores::abrirEnMedios($cuotas, $ap, $hoy);

chequear('la de octubre cubierta, la de noviembre partida y la de diciembre intacta', [
    ['2026-10-01', '2026-10-20', 100000.0, 'PAGADO'],
    ['2026-11-01', '2026-10-20', 50000.0, 'PAGADO'],
    ['2026-11-01', '2026-11-01', 50000.0, 'SALDO'],
    ['2026-12-01', '2026-12-01', 100000.0, 'SALDO']
], array_map(function ($f) {
    return [$f['FECHA_VTO'], $f['Pago'], $f['IMPORTE_A_PAGAR'], $f['PAGO_IMPORTADO']['tipo']];
}, $p));
chequear('tres vencimientos: la mitad que sobra no cuenta', 3,
    count(array_filter($p, ['Proveedores', 'cuentaComoVencimiento'])));
chequear('IMPORTE_VTO se reparte dentro de la cuota', 300000.0, $suma($p, 'IMPORTE_VTO'));

$ap = ['PROVC|FAC|C0001' => [$medio(1, '2026-10-20', 310000.0, 'EXCEDENTE', 300000.0, 300000.0)]];
$p = Proveedores::abrirEnMedios($cuotas, $ap, $hoy);

chequear('con excedente, todas en la planilla y el excedente en la última cubierta',
    [['2026-10-20', 100000.0, 0.0], ['2026-10-20', 100000.0, 0.0], ['2026-10-20', 110000.0, 10000.0]],
    array_map(function ($f) { return [$f['Pago'], $f['IMPORTE_A_PAGAR'], $f['EXCEDENTE']]; }, $p));

seccion('proyección: la retención se reparte y cierra al centavo');

$conRet = [$item('PROVR', 'R0001', 1000000.0, '2026-11-20', $cfgRet)];
$ap = ['PROVR|FAC|R0001' => [$medio(1, '2026-10-20', 400000.0, 'PARCIAL', 1000000.0, 950000.0)]];
$p = Proveedores::abrirEnMedios($conRet, $ap, $hoy, null, $cfgRet);

chequear('lo pagado: su neto y su parte de la retención', [400000.0, 21052.63, 421052.63],
    [$p[0]['IMPORTE_A_PAGAR'], $p[0]['RETENIDO'], $p[0]['IMPORTE_PENDIENTE']]);
chequear('el saldo: el resto del neto y de la retención', [550000.0, 28947.37, 578947.37],
    [$p[1]['IMPORTE_A_PAGAR'], $p[1]['RETENIDO'], $p[1]['IMPORTE_PENDIENTE']]);
chequear('impuesto por impuesto, sin perder un centavo', [30000.0, 20000.0], [
    round($p[0]['RETENIDO_POR_IMPUESTO']['IIBB'] + $p[1]['RETENIDO_POR_IMPUESTO']['IIBB'], 2),
    round($p[0]['RETENIDO_POR_IMPUESTO']['GANANCIAS'] + $p[1]['RETENIDO_POR_IMPUESTO']['GANANCIAS'], 2)]);
chequear('neto + retenciones = bruto, en cada fila y en total', [true, true, 1000000.0], [
    round($p[0]['IMPORTE_A_PAGAR'] + $p[0]['RETENIDO'], 2) === $p[0]['IMPORTE_PENDIENTE'],
    round($p[1]['IMPORTE_A_PAGAR'] + $p[1]['RETENIDO'], 2) === $p[1]['IMPORTE_PENDIENTE'],
    $suma($p, 'IMPORTE_PENDIENTE')]);

$ap = ['PROVR|FAC|R0001' => [$medio(1, '2026-10-20', 1000000.0, 'EXCEDENTE', 1000000.0, 950000.0)]];
$p = Proveedores::abrirEnMedios($conRet, $ap, $hoy, null, $cfgRet);
chequear('el excedente no lleva retención', [1000000.0, 50000.0, 50000.0, 1000000.0],
    [$p[0]['IMPORTE_A_PAGAR'], $p[0]['EXCEDENTE'], $p[0]['RETENIDO'], $p[0]['IMPORTE_PENDIENTE']]);

seccion('proyección: Tango registró el pago parcial');

// Se importaron $400.000 sobre $1.000.000 y Tango ya dejó $600.000.
$registrado = [$item('PROVA', 'A0001', 600000.0, '2026-11-20')];
$ap = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 400000.0, 'PARCIAL', 1000000.0, 1000000.0)]];
$p = Proveedores::abrirEnMedios($registrado, $ap, $hoy);

chequear('deja de proyectarse lo pagado: queda el pendiente de Tango, en su fecha',
    [1, 600000.0, '2026-11-20'], [count($p), $p[0]['IMPORTE_A_PAGAR'], $p[0]['Pago']]);
chequear('la fila dice que el parcial ya se registró', 'REGISTRADO', $p[0]['PAGO_IMPORTADO']['estado']);
chequear('sin aviso: bajar es Tango registrando el pago', 0,
    count(array_filter(Proveedores::avisosPendientesConNivel($p), function ($a) {
        return strpos($a['texto'], 'ya no coinciden con lo importado') !== false;
    })));

// Bajó por otro importe -Ganancias no fue proporcional-: igual es registrado.
$p = Proveedores::abrirEnMedios([$item('PROVA', 'A0001', 612345.0, '2026-11-20')], $ap, $hoy);
chequear('bajar en cualquier importe también es registrado', 'REGISTRADO', $p[0]['PAGO_IMPORTADO']['estado']);

$yaMarcado = ['PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 400000.0, 'PARCIAL', 1000000.0, 1000000.0, null,
    ['estado_tango' => 'REGISTRADO'])]];
$p = Proveedores::abrirEnMedios([$item('PROVA', 'A0001', 600000.0, '2026-11-20')], $yaMarcado, $hoy);
chequear('marcado REGISTRADO no vuelve atrás', ['REGISTRADO', 1], [$p[0]['PAGO_IMPORTADO']['estado'], count($p)]);

seccion('proyección: el pendiente subió, y se avisa');

$p = Proveedores::abrirEnMedios([$item('PROVA', 'A0001', 1100000.0, '2026-11-20')], $ap, $hoy);
chequear('se proyecta el pendiente de Tango con la jerarquía de siempre', [1, 1100000.0, '2026-11-20'],
    [count($p), $p[0]['IMPORTE_A_PAGAR'], $p[0]['Pago']]);
$avisos = array_values(array_filter(Proveedores::avisosPendientesConNivel($p), function ($a) {
    return strpos($a['texto'], 'ya no coinciden con lo importado') !== false;
}));
chequear('y la pestaña avisa en atención', ['warning', '1 comprobante(s) con pago parcial o excedente ya no '
    . 'coinciden con lo importado: se proyecta el pendiente de Tango. Reimportá para corregirlo.'],
    [$avisos[0]['nivel'], $avisos[0]['texto']]);

seccion('proyección: lo de antes no se mueve');

chequear('sin pagos importados, las filas salen idénticas', $items, Proveedores::abrirEnMedios($items, [], $hoy));

$dosNeto = [$item('PROVA', 'A0001', 200000.0, '2026-11-20', $cfgRet)];
$viejo = ['PROVA|FAC|A0001' => [
    ['orden' => 1, 'fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 150000.0],
    ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA', 'importe' => 40000.0]]];
$nuevo = ['PROVA|FAC|A0001' => [
    $medio(1, '2026-10-20', 150000.0, 'NETO', 200000.0, 190000.0, 'ECHEQ'),
    $medio(2, '2026-10-27', 40000.0, 'NETO', 200000.0, 190000.0, 'TRANSFERENCIA')]];

$antes = Proveedores::abrirEnMedios($dosNeto, $viejo, $hoy, null, $cfgRet);
$ahora = Proveedores::abrirEnMedios($dosNeto, $nuevo, $hoy, null, $cfgRet);
$sinInfo = function ($filas) {
    return array_map(function ($f) { unset($f['APERTURA']); return $f; }, $filas);
};
chequear('un NETO en dos medios da exactamente lo mismo que antes', $sinInfo($antes), $sinInfo($ahora));
chequear('con la retención entera en la 1/2, como siempre', [10000.0, 0.0],
    [$ahora[0]['RETENIDO'], $ahora[1]['RETENIDO']]);

$fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
chequear('sin el horizonte no se parte: Tarjetas Pagos Corporativos no cambia', true,
    strpos($fuente, "if (\$h !== null) {\n            \$items = self::abrirEnMedios(") !== false);

/* ================================================================
   EL TABLERO
   ================================================================ */
$hTab = new Horizonte(60, 3, [], new DateTime($hoy));
$repartir = new ReflectionMethod('ProveedoresProvider', 'repartir');
$repartir->setAccessible(true);
$provTab = new ProveedoresProvider('PROV_LOCALES');

$universo = [
    $item('PROVA', 'A0001', 1000000.0, '2026-11-20', $cfgRet),
    $item('PROVR', 'R0001', 1000000.0, '2026-11-20', $cfgRet),
    $item('PROVC', 'C0001', 100000.0, '2026-10-01', $cfgRet), $item('PROVC', 'C0001', 100000.0, '2026-11-01', $cfgRet),
    $item('PROVK', 'K0001', 50000.0, '2026-11-10', $cfgRet, ['CRONOGRAMA' => false]),
    $item('PROVX', 'X0001', 80000.0, '2026-11-15', $cfgRet, ['EXCLUIDA_MANUAL' => true, 'EXCLUIDO' => true])
];
$importados = [
    'PROVA|FAC|A0001' => [$medio(1, '2026-10-20', 980000.0, 'EXCEDENTE', 1000000.0, 950000.0)],
    'PROVR|FAC|R0001' => [$medio(1, '2026-10-20', 400000.0, 'PARCIAL', 1000000.0, 950000.0)],
    'PROVC|FAC|C0001' => [$medio(1, '2026-10-20', 120000.0, 'PARCIAL', 200000.0, 190000.0)],
    'PROVX|FAC|X0001' => [$medio(1, '2026-10-20', 80000.0, 'EXCEDENTE', 80000.0, 76000.0)]
];

$filasTab = Proveedores::abrirEnMedios($universo, $importados, $hoy, null, $cfgRet);
$series = $repartir->invoke($provTab, $hTab, $filasTab);

$enEje = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']), 2); };
$total = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'], 2); };

seccion('el tablero: las dos particiones cierran con excedentes y parciales');

chequear('por cómo se paga', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS']) + $enEje($series['PAGOS_FUERA_CRONOGRAMA'])
        + $enEje($series['PAGOS_EXCLUIDOS_FACTURA']) + $enEje($series['PAGOS_EXCLUIDOS_PROVEEDOR']), 2));
chequear('por qué rubro es', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS_OPERATIVOS']) + $enEje($series['PAGOS_EXCLUIDOS']), 2));

seccion('el tablero: la auditoría dice cuánto es excedente, y cierra');

$aud = $series['PAGOS_TODO']['auditoria'];
// PROVA paga 980.000 sobre un neto de 950.000; PROVX, 80.000 sobre 76.000.
chequear('el excedente del universo', 34000.0, round($aud['excedente'], 2));
chequear('bruto + excedente = PAGOS_TODO + retenciones', round($aud['bruto'] + $aud['excedente'], 2),
    round($total($series['PAGOS_TODO']) + $aud['retencion_IIBB'] + $aud['retencion_GANANCIAS'], 2));

foreach ($series as $codigo => $s) {
    $ret = 0.0;

    foreach ($s['auditoria'] as $k => $v) {
        if (strpos($k, 'retencion_') === 0) { $ret += $v; }
    }

    $exc = isset($s['auditoria']['excedente']) ? $s['auditoria']['excedente'] : 0.0;

    if (!chequear('en ' . $codigo . ' también', round($s['auditoria']['bruto'] + $exc, 2),
            round($total($s) + $ret, 2))) {
        break;
    }
}

chequear('una serie sin excedentes no tiene la clave', false,
    array_key_exists('excedente', $series['PAGOS_FUERA_CRONOGRAMA']['auditoria']));
chequear('el excedente de la excluida a mano va por su serie', 4000.0,
    round($series['PAGOS_EXCLUIDOS_FACTURA']['auditoria']['excedente'], 2));

seccion('el tablero: la pestaña y el tablero suman lo mismo');

chequear('lo que proyecta la grilla es PAGOS_TODO', $suma($filasTab, 'IMPORTE_A_PAGAR'), $total($series['PAGOS_TODO']));
chequear('y el día de la planilla tiene lo pagado de los tres, con el excedente', round(980000.0 + 400000.0 + 120000.0 + 80000.0, 2),
    round($series['PAGOS_TODO']['dias']['2026-10-20'], 2));

seccion('el tablero: con la planilla vacía no se mueve un peso');

$sinPlanilla = $repartir->invoke($provTab, $hTab, Proveedores::abrirEnMedios($universo, [], $hoy, null, $cfgRet));
$directo = $repartir->invoke($provTab, $hTab, $universo);
chequear('las series son idénticas', $directo, $sinPlanilla);
