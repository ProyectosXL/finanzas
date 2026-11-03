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
