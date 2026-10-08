<?php
/**
 * Saldos bancarios de Interbanking: que registro se toma, que nombre se
 * muestra, que se avisa y como entra al tablero.
 *
 * Todo sobre los helpers puros de SaldosInterbanking y Saldos, sin base: la
 * fecha de hoy se inyecta, asi que las pruebas no caducan. La ultima seccion
 * mira la base real y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../Class/SaldosInterbanking.php';
require_once __DIR__ . '/../Class/Saldos.php';

const IB_HOY = '2026-10-08';

/** Un registro de BI_T_SALDOS_INTERBANKING, como lo devuelve leerUltimos() */
function ibReg($id, $fecha, $saldo, $creado = false, $banco = '007', $cuenta = '100', $moneda = 'ARS') {
    return [
        'ID' => $id,
        'FECHA_OPERACION' => $fecha,
        'NRO_BANCO' => $banco,
        'NRO_CUENTA' => $cuenta,
        'TIPO_CUENTA' => 'CC',
        'MONEDA' => $moneda,
        'SALDO_CONTABLE' => $saldo,
        'CREATED_AT' => ($creado === false) ? $fecha . ' 10:30:00' : $creado
    ];
}

/** Los textos de una lista de avisos con un nivel dado, juntos */
function ibAvisos($avisos, $nivel = null) {
    $t = [];

    foreach ($avisos as $a) {
        if ($nivel === null || $a['nivel'] === $nivel) {
            $t[] = $a['texto'];
        }
    }

    return implode(' | ', $t);
}

function ibContiene($texto, $aguja) {
    return strpos($texto, $aguja) !== false;
}

$tangoIb = ['007' => 'DE GALICIA Y BS.AS.', '014' => 'PROVINCIA DE BS.AS.', '015' => ' ICBC'];

// ============================================================================
seccion('que registro se toma');
// ============================================================================

$e = SaldosInterbanking::elegirRegistro([
    ibReg(1, '2026-10-07', 100.0),
    ibReg(2, IB_HOY, 200.0)
]);

chequear('el de hoy', 2, $e['usado']['ID']);
chequear('el de hoy no tapa a nadie', false, $e['tapado']);

$e = SaldosInterbanking::elegirRegistro([
    ibReg(1, '2026-10-02', 100.0),
    ibReg(2, '2026-10-05', 150.0)
]);

chequear('sin registro de hoy, el ultimo que haya', 2, $e['usado']['ID']);
chequear('con su fecha', '2026-10-05', $e['usado']['FECHA_OPERACION']);

$e = SaldosInterbanking::elegirRegistro([
    ibReg(1, IB_HOY, 100.0, IB_HOY . ' 10:30:00'),
    ibReg(2, IB_HOY, 200.0, IB_HOY . ' 15:00:00')
]);

chequear('dos del mismo dia: gana el CREATED_AT mas nuevo', 2, $e['usado']['ID']);

$e = SaldosInterbanking::elegirRegistro([
    ibReg(7, IB_HOY, 300.0, IB_HOY . ' 10:30:00'),
    ibReg(5, IB_HOY, 100.0, IB_HOY . ' 10:30:00')
]);

chequear('mismo dia y mismo CREATED_AT: gana el ID mas alto', 7, $e['usado']['ID']);

$e = SaldosInterbanking::elegirRegistro([
    ibReg(5, IB_HOY, 100.0, IB_HOY . ' 10:30:00'),
    ibReg(7, IB_HOY, 300.0, null)
]);

chequear('un CREATED_AT nulo ordena como el mas viejo, igual que en SQL', 5, $e['usado']['ID']);

$e = SaldosInterbanking::elegirRegistro([
    ibReg(1, '2026-10-07', 100.0),
    ibReg(2, IB_HOY, null)
]);

chequear('el mas nuevo sin contable no tapa al anterior', 1, $e['usado']['ID']);
chequear('y se sabe que paso', true, $e['tapado']);
chequear('el mas nuevo sigue siendo el de hoy', 2, $e['mas_nuevo']['ID']);

$e = SaldosInterbanking::elegirRegistro([ibReg(1, '2026-10-07', null), ibReg(2, IB_HOY, null)]);

chequear('sin ningun contable no se usa nada', null, $e['usado']);
chequear('y no cuenta como tapado: no hay nada que tapar', false, $e['tapado']);

$e = SaldosInterbanking::elegirRegistro([ibReg(1, '2026-10-07', 0.0)]);

chequear('un contable en cero es un dato, no un nulo', 0.0, $e['usado']['SALDO_CONTABLE']);

// ============================================================================
seccion('el nombre del banco');
// ============================================================================

chequear('sin alias, DESC_BANCO',
    'DE GALICIA Y BS.AS.', SaldosInterbanking::nombreBanco('007', $tangoIb)['nombre']);
chequear('con alias, el alias',
    'Galicia', SaldosInterbanking::nombreBanco('007', $tangoIb, 'Galicia')['nombre']);
chequear('un alias de puros espacios no cuenta',
    'DE GALICIA Y BS.AS.', SaldosInterbanking::nombreBanco('007', $tangoIb, '   ')['nombre']);
chequear('DESC_BANCO va sin los espacios de Tango',
    'ICBC', SaldosInterbanking::nombreBanco('015', $tangoIb)['nombre']);
chequear('un banco que no esta en Tango: "Banco <nro>"',
    'Banco 191', SaldosInterbanking::nombreBanco('191', $tangoIb)['nombre']);
chequear('y se sabe que no esta', false, SaldosInterbanking::nombreBanco('191', $tangoIb)['en_tango']);
chequear('el NRO_BANCO se cruza con trim (char(3) contra varchar(10))',
    'DE GALICIA Y BS.AS.', SaldosInterbanking::nombreBanco(' 007 ', $tangoIb)['nombre']);
chequear('la clave de cuenta va con trim y la moneda en mayusculas',
    '007|100|ARS', SaldosInterbanking::claveCuenta('007 ', ' 100', 'ars'));

// ============================================================================
seccion('las filas y los avisos');
// ============================================================================

$ultimos = [
    ibReg(1, IB_HOY, 1000.0, false, '007', '100'),
    ibReg(2, IB_HOY, null, false, '014', '200'),
    ibReg(3, '2026-10-07', 500.0, false, '015', '300'),
    ibReg(4, IB_HOY, null, false, '015', '300'),
    ibReg(5, IB_HOY, 70.0, false, '191', '400'),
    ibReg(6, IB_HOY, 99.0, false, '029', '500', null)
];

$r = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY, 'tango' => $tangoIb]);
$porClave = [];

foreach ($r['filas'] as $f) {
    $porClave[$f['clave']] = $f;
}

chequear('una fila por cuenta con moneda', 4, count($r['filas']));
chequear('la cuenta se muestra "banco · numero"',
    'DE GALICIA Y BS.AS. · 100', $porClave['007|100|ARS']['nombre']);
chequear('es de tipo Banco y de origen Interbanking',
    'BANCO/INTERBANKING', $porClave['007|100|ARS']['tipo'] . '/' . $porClave['007|100|ARS']['origen']);
chequear('el saldo es el contable', 1000.0, $porClave['007|100|ARS']['saldo']);
chequear('"cargado el" es el CREATED_AT', IB_HOY . ' 10:30:00', $porClave['007|100|ARS']['fecha_carga']);

chequear('sin ningun contable: sin dato', false, $porClave['014|200|ARS']['cargada']);
chequear('sin dato es null, no cero', null, $porClave['014|200|ARS']['saldo']);
chequear('y el aviso es critico',
    true, ibContiene(ibAvisos($r['avisos'], Aviso::DANGER), 'PROVINCIA DE BS.AS. · 200'));

chequear('el de hoy sin contable toma el de ayer', 500.0, $porClave['015|300|ARS']['saldo']);
chequear('con la fecha de ayer', '2026-10-07', $porClave['015|300|ARS']['fecha_saldo']);
chequear('y el aviso de atencion dice las dos fechas',
    true, ibContiene(ibAvisos($r['avisos'], Aviso::WARNING),
        'el registro del 08/10 vino sin saldo contable; se muestra el del 07/10'));

chequear('el banco fuera de Tango se muestra igual', 'Banco 191 · 400', $porClave['191|400|ARS']['nombre']);
chequear('y avisa en atencion',
    true, ibContiene(ibAvisos($r['avisos'], Aviso::WARNING), 'El banco 191'));

chequear('una cuenta sin moneda no se toma', false, isset($porClave['029|500|']));
chequear('y avisa en critico, sin asumir pesos',
    true, ibContiene(ibAvisos($r['avisos'], Aviso::DANGER), '500 llegó de Interbanking sin moneda'));
chequear('todos los avisos van a la seccion Saldo Inicial',
    true, count(array_filter($r['avisos'], function ($a) {
        return $a['seccion'] !== 'Saldo Inicial';
    })) === 0);

// ============================================================================
seccion('la lectura caida');
// ============================================================================

$caida = SaldosInterbanking::armarCuentasBancarias(null,
    ['hoy' => IB_HOY, 'tango' => $tangoIb, 'error' => 'no existe la tabla']);

chequear('sin filas de Interbanking', 0, count($caida['filas']));
chequear('con un aviso critico que dice que el disponible no las incluye',
    true, ibContiene(ibAvisos($caida['avisos'], Aviso::DANGER),
        'No se pudieron leer los saldos bancarios de Interbanking (no existe la tabla); el '
        . 'disponible no los incluye'));

// Lo manual sigue entrando: la serie arma con las filas de las cargas aunque
// Interbanking no haya devuelto ninguna.
$hIb = new Horizonte(28, 12, [], new DateTime(IB_HOY));
$manuales = [
    ['origen' => Saldos::ORIGEN_CARGA, 'tipo' => 'EFECTIVO_CENTRAL', 'moneda' => 'ARS',
     'saldo' => 300.0, 'fecha_saldo' => IB_HOY, 'cargada' => true],
    ['origen' => Saldos::ORIGEN_CARGA, 'tipo' => 'MERCADO_PAGO', 'moneda' => 'ARS',
     'saldo' => 700.0, 'fecha_saldo' => IB_HOY, 'cargada' => true]
];
$serieCaida = Saldos::armarSerieDisponible(array_merge($manuales, $caida['filas']), $hIb);

chequear('el efectivo y Mercado Pago siguen entrando',
    1000.0, floatval(array_sum($serieCaida['serie']['dias'])));

// ============================================================================
seccion('la consulta va contra la tabla de BI sola');
// ============================================================================

$srcIb = file_get_contents(__DIR__ . '/../Class/SaldosInterbanking.php');
preg_match('/function leerUltimos\(\).*?\n    }\n/s', $srcIb, $m);
$leer = isset($m[0]) ? $m[0] : '';

chequear('usa ROW_NUMBER()', true, ibContiene($leer, 'ROW_NUMBER() OVER'));
chequear('particiona por banco, cuenta y moneda',
    true, ibContiene($leer, 'PARTITION BY NRO_BANCO, NRO_CUENTA, MONEDA'));
chequear('ordena por fecha, CREATED_AT e ID',
    true, ibContiene($leer, 'ORDER BY FECHA_OPERACION DESC, CREATED_AT DESC, ID DESC'));
chequear('no tiene ningun JOIN', false, (bool) preg_match('/\bJOIN\b/i', $leer));
chequear('usa el saldo contable y no otro',
    false, (bool) preg_match('/SALDO_OPERATIVO|SALDO_PROYECTADO|SALDO_DIA/', $leer));

// ============================================================================
seccion('alias e inhabilitacion de bancos');
// ============================================================================

$bancosIb = [
    '007' => ['ALIAS' => 'Galicia', 'ACTIVO' => 1],
    '014' => ['ALIAS' => null, 'ACTIVO' => 0]
];

$conParams = SaldosInterbanking::armarCuentasBancarias($ultimos,
    ['hoy' => IB_HOY, 'tango' => $tangoIb, 'bancos' => $bancosIb]);
$porClave = [];

foreach ($conParams['filas'] as $f) {
    $porClave[$f['clave']] = $f;
}

chequear('el alias se muestra en la cuenta', 'Galicia · 100', $porClave['007|100|ARS']['nombre']);
chequear('el banco inactivo no se lista', false, isset($porClave['014|200|ARS']));
chequear('ni avisa que no tiene saldo contable',
    false, ibContiene(ibAvisos($conParams['avisos']), '200'));
chequear('un banco sin fila esta activo', true, isset($porClave['015|300|ARS']));
chequear('sin fila tambien es activo para bancoActivo()',
    true, SaldosInterbanking::bancoActivo('191', $bancosIb));
chequear('sin parametros (script sin correr), todos activos',
    true, SaldosInterbanking::bancoActivo('014', []));

// Galicia suma 1.000; inactivo, la serie baja exactamente eso.
$soloCargadas = function ($filas) {
    return array_filter($filas, function ($f) { return $f['cargada']; });
};
$activo007 = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY,
    'tango' => $tangoIb, 'bancos' => []]);
$inactivo007 = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY,
    'tango' => $tangoIb, 'bancos' => ['007' => ['ALIAS' => null, 'ACTIVO' => 0]]]);
$sumaActivo = array_sum(Saldos::armarSerieDisponible($soloCargadas($activo007['filas']), $hIb)['serie']['dias']);
$sumaInactivo = array_sum(Saldos::armarSerieDisponible($soloCargadas($inactivo007['filas']), $hIb)['serie']['dias']);

chequear('el banco inactivo no aporta al tablero', 1000.0, floatval($sumaActivo - $sumaInactivo));

// Un banco fuera de Tango con alias ya no avisa: su nombre no depende de Tango.
$conAlias191 = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY,
    'tango' => $tangoIb, 'bancos' => ['191' => ['ALIAS' => 'Credicoop', 'ACTIVO' => 1]]]);
chequear('un banco fuera de Tango con alias no avisa',
    false, ibContiene(ibAvisos($conAlias191['avisos']), 'El banco 191'));

// Inactivo y sin moneda: tampoco avisa.
$inactivoSinMoneda = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY,
    'tango' => $tangoIb, 'bancos' => ['029' => ['ALIAS' => null, 'ACTIVO' => 0]]]);
chequear('un banco inactivo no avisa ni la cuenta sin moneda',
    false, ibContiene(ibAvisos($inactivoSinMoneda['avisos']), 'sin moneda'));

// ============================================================================
seccion('guardado de bancos: solo lo que cambio');
// ============================================================================

$actuales = [
    '007' => ['ALIAS' => 'Galicia', 'ACTIVO' => 1],
    '027' => ['ALIAS' => null, 'ACTIVO' => 0]
];
$conocidosIb = ['007', '014', '015', '027'];

$c = SaldosInterbanking::resolverBancos($actuales, $conocidosIb, [
    ['nro_banco' => '007', 'alias' => 'Galicia', 'activo' => true],
    ['nro_banco' => '014', 'alias' => '', 'activo' => true],
    ['nro_banco' => '015', 'alias' => 'ICBC', 'activo' => true],
    ['nro_banco' => '027', 'alias' => '', 'activo' => false]
]);

chequear('solo se escribe el que cambio', ['015'], array_column($c, 'nro_banco'));
chequear('y se sabe que no tenia fila (UPSERT)', false, $c[0]['existe']);

$c = SaldosInterbanking::resolverBancos($actuales, $conocidosIb, [
    ['nro_banco' => '007', 'alias' => '   ', 'activo' => true]
]);
chequear('un alias vacio se guarda null', null, $c[0]['alias']);

$c = SaldosInterbanking::resolverBancos($actuales, $conocidosIb, [
    ['nro_banco' => '007', 'alias' => '  Galicia  ', 'activo' => 'true']
]);
chequear('los espacios alrededor no son un cambio', 0, count($c));

$c = SaldosInterbanking::resolverBancos($actuales, $conocidosIb, [
    ['nro_banco' => '027', 'alias' => null, 'activo' => true]
]);
chequear('reactivar es un cambio', true, $c[0]['activo']);

$c = SaldosInterbanking::resolverBancos($actuales, $conocidosIb, [
    ['nro_banco' => '014', 'alias' => null, 'activo' => false]
]);
chequear('inactivar un banco sin fila es un cambio', false, $c[0]['activo']);

chequearLanza('un alias de mas de 100 caracteres se rechaza', function () use ($actuales, $conocidosIb) {
    SaldosInterbanking::resolverBancos($actuales, $conocidosIb,
        [['nro_banco' => '007', 'alias' => str_repeat('x', 101), 'activo' => true]]);
});
chequear('uno de 100 justos se acepta',
    100, mb_strlen(SaldosInterbanking::validarAlias(str_repeat('ñ', 100))));
chequearLanza('un banco que no viene ni tiene fila se rechaza', function () use ($actuales, $conocidosIb) {
    SaldosInterbanking::resolverBancos($actuales, $conocidosIb,
        [['nro_banco' => '999', 'alias' => 'X', 'activo' => true]]);
});
chequear('uno que ya no viene pero tiene fila se puede editar',
    1, count(SaldosInterbanking::resolverBancos($actuales, ['007'],
        [['nro_banco' => '027', 'alias' => 'Supervielle', 'activo' => false]])));

preg_match('/function guardarBancos\(.*?\n    }\n/s', $srcIb, $m);
$guardar = isset($m[0]) ? $m[0] : '';
chequear('el guardado valida el usuario de escritura',
    true, ibContiene($guardar, 'AuthCashflow::usuarioDeEscritura('));
chequear('y escribe en una transaccion',
    true, ibContiene($guardar, 'sqlsrv_begin_transaction') && ibContiene($guardar, 'sqlsrv_rollback'));

// ============================================================================
seccion('el saldo que no es de hoy: en la pestana');
// ============================================================================

$porClave = [];

foreach ($r['filas'] as $f) {
    $porClave[$f['clave']] = $f;
}

chequear('un saldo de hoy no se marca', false, $porClave['007|100|ARS']['no_es_de_hoy']);
chequear('uno de ayer se marca "no es de hoy"', true, $porClave['015|300|ARS']['no_es_de_hoy']);
chequear('una cuenta sin dato no se marca: ya tiene su aviso',
    false, $porClave['014|200|ARS']['no_es_de_hoy']);
chequear('la regla es estricta: un sabado sin registro del dia tambien se marca',
    true, SaldosInterbanking::noEsDeHoy('2026-10-09', '2026-10-10'));

$avisoHoy = SaldosInterbanking::avisoNoEsDeHoy($r['filas']);
chequear('el aviso de la pestana es de atencion', Aviso::WARNING, $avisoHoy['nivel']);
chequear('y lista cada cuenta con su fecha',
    true, ibContiene($avisoHoy['texto'], 'ICBC · 300 (07/10)'));
chequear('sin cuentas viejas no hay aviso',
    null, SaldosInterbanking::avisoNoEsDeHoy([$porClave['007|100|ARS']]));

// ============================================================================
seccion('el saldo que no es de hoy: en el tablero');
// ============================================================================

$filaIb = function ($saldo, $fecha, $moneda = 'ARS') {
    return ['origen' => SaldosInterbanking::ORIGEN_INTERBANKING, 'tipo' => 'BANCO',
            'moneda' => $moneda, 'saldo' => $saldo, 'fecha_saldo' => $fecha, 'cargada' => true];
};
$filaCarga = function ($saldo, $fecha, $moneda = 'ARS', $tipo = 'MERCADO_PAGO') {
    return ['origen' => Saldos::ORIGEN_CARGA, 'tipo' => $tipo,
            'moneda' => $moneda, 'saldo' => $saldo, 'fecha_saldo' => $fecha, 'cargada' => true];
};

$d = Saldos::armarSerieDisponible([$filaIb(1000.0, IB_HOY)], $hIb);
chequear('un saldo de hoy va a la columna de hoy', 1000.0, $d['serie']['dias'][IB_HOY]);
chequear('y no avisa nada', 0, count($d['avisos_con_nivel']));

$d = Saldos::armarSerieDisponible([$filaIb(1000.0, '2026-10-06'), $filaIb(500.0, '2026-10-07')], $hIb);
chequear('uno viejo de Interbanking va a la primera columna', 1500.0, $d['serie']['dias'][IB_HOY]);
chequear('con aviso WARNING', Aviso::WARNING, $d['avisos_con_nivel'][0]['nivel']);
chequear('y ningun DANGER', '', ibAvisos($d['avisos_con_nivel'], Aviso::DANGER));
chequear('que dice cuantas cuentas, cuanta plata y la fecha mas vieja',
    true, ibContiene($d['avisos'][0], '2 cuenta(s) bancaria(s) por $ 1.500,00')
        && ibContiene($d['avisos'][0], '06/10/2026'));
chequear('en la seccion Saldo Inicial', 'Saldo Inicial', $d['avisos_con_nivel'][0]['seccion']);
chequear('sin pedir que se actualice una carga',
    false, ibContiene($d['avisos'][0], 'Actualizá la carga'));

$d = Saldos::armarSerieDisponible([$filaCarga(700.0, '2026-10-01')], $hIb);
chequear('lo manual viejo conserva su aviso critico', Aviso::DANGER, $d['avisos_con_nivel'][0]['nivel']);
chequear('el de siempre', true, ibContiene($d['avisos'][0], 'Actualizá la carga de saldos'));

$d = Saldos::armarSerieDisponible([['nombre' => 'sin origen', 'moneda' => 'ARS', 'saldo' => 10.0,
    'fecha_saldo' => '2026-10-01']], $hIb);
chequear('una fila sin origen se trata como una carga', Aviso::DANGER, $d['avisos_con_nivel'][0]['nivel']);

$d = Saldos::armarSerieDisponible([$filaIb(1000.0, '2026-10-06'), $filaCarga(700.0, '2026-10-01')], $hIb);
chequear('mezclados, cada uno con su aviso', 'danger,warning',
    implode(',', array_map(function ($a) { return $a['nivel']; }, $d['avisos_con_nivel'])));
chequear('y los dos en la primera columna', 1700.0, $d['serie']['dias'][IB_HOY]);

$d = Saldos::armarSerieDisponible([$filaCarga(100.0, IB_HOY, 'USD', 'BANCO')], $hIb, ['2026-10' => 1400.0]);
chequear('un banco manual en USD entra con su cotizacion', 140000.0, $d['serie']['dias'][IB_HOY]);

// ============================================================================
seccion('la carga vieja habla solo de las cargas manuales');
// ============================================================================

chequear('el aviso de carga vieja dice que es la manual', true, ibContiene(
    implode(' ', Saldos::avisosAntiguedad('2026-09-01', 7, IB_HOY)), 'última carga manual'));
chequear('y nombra lo que se carga a mano', true, ibContiene(
    implode(' ', Saldos::avisosAntiguedad('2026-09-01', 7, IB_HOY)), 'bancos manuales'));

// ============================================================================
seccion('respaldo manual: que saldo se usa');
// ============================================================================

/** Un respaldo vigente, como lo devuelve leerRespaldos() */
function ibResp($id, $fecha, $saldo, $banco = '014', $cuenta = '200', $moneda = 'ARS') {
    return ['ID' => $id, 'NRO_BANCO' => $banco, 'NRO_CUENTA' => $cuenta, 'MONEDA' => $moneda,
            'FECHA_SALDO' => $fecha, 'SALDO_CONTABLE' => $saldo, 'OBSERVACION' => 'del extracto',
            'ID_REEMPLAZA' => null, 'USUARIO_ALTA' => 'tesoreria', 'FECHA_ALTA' => $fecha . ' 09:00:00'];
}

$ibViejo = ['FECHA_OPERACION' => '2026-10-06'];
$ibHoy = ['FECHA_OPERACION' => IB_HOY];

chequear('sin Interbanking, el respaldo',
    'RESPALDO', SaldosInterbanking::resolverSaldo(null, ibResp(1, '2026-10-07', 5.0)));
chequear('un respaldo mas nuevo gana a un Interbanking viejo',
    'RESPALDO', SaldosInterbanking::resolverSaldo($ibViejo, ibResp(1, '2026-10-07', 5.0)));
chequear('un Interbanking mas nuevo gana al respaldo',
    'INTERBANKING', SaldosInterbanking::resolverSaldo($ibHoy, ibResp(1, '2026-10-07', 5.0)));
chequear('a igual fecha gana Interbanking',
    'INTERBANKING', SaldosInterbanking::resolverSaldo($ibHoy, ibResp(1, IB_HOY, 5.0)));
chequear('sin ninguno de los dos, nada', null, SaldosInterbanking::resolverSaldo(null, null));

$conResp = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY, 'tango' => $tangoIb,
    'respaldos' => ['014|200|ARS' => ibResp(9, IB_HOY, 35000000.0)]]);
$porClave = [];

foreach ($conResp['filas'] as $f) {
    $porClave[$f['clave']] = $f;
}

$prov = $porClave['014|200|ARS'];
chequear('sin dato de Interbanking se usa el respaldo', 35000000.0, $prov['saldo']);
chequear('con origen RESPALDO', 'RESPALDO', $prov['origen']);
chequear('y suma: queda cargada', true, $prov['cargada']);
chequear('"cargado el" es el alta del respaldo', IB_HOY . ' 09:00:00', $prov['fecha_carga']);
chequear('y se sabe quien lo cargo', 'tesoreria', $prov['usuario_carga']);
chequear('la falla de la integracion se acusa en atencion',
    true, ibContiene(ibAvisos($conResp['avisos'], Aviso::WARNING),
        'Interbanking no trae el saldo contable de PROVINCIA DE BS.AS. · 200; se usa la carga '
        . 'manual del 08/10'));
chequear('y ya no hay critico por esa cuenta',
    false, ibContiene(ibAvisos($conResp['avisos'], Aviso::DANGER), 'PROVINCIA'));
chequear('el respaldo vigente viaja en la fila para el dialogo', 9, $prov['respaldo']['id']);

// Interbanking mas nuevo: el respaldo queda en la fila pero no se usa
$respViejo = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY, 'tango' => $tangoIb,
    'respaldos' => ['007|100|ARS' => ibResp(3, '2026-10-07', 1.0, '007', '100')]]);
$porClave = [];

foreach ($respViejo['filas'] as $f) {
    $porClave[$f['clave']] = $f;
}

chequear('con Interbanking de hoy, el respaldo de ayer no se usa',
    1000.0, $porClave['007|100|ARS']['saldo']);
chequear('pero se sigue mostrando en el dialogo', 3, $porClave['007|100|ARS']['respaldo']['id']);
chequear('y no avisa falla de integracion',
    false, ibContiene(ibAvisos($respViejo['avisos']), 'no trae el saldo contable de DE GALICIA'));

$inactivoResp = SaldosInterbanking::armarCuentasBancarias($ultimos, ['hoy' => IB_HOY,
    'tango' => $tangoIb, 'bancos' => ['014' => ['ALIAS' => null, 'ACTIVO' => 0]],
    'respaldos' => ['014|200|ARS' => ibResp(9, IB_HOY, 35000000.0)]]);
chequear('un banco inactivo no usa el respaldo',
    false, in_array('014|200|ARS', array_column($inactivoResp['filas'], 'clave'), true));

$caidaResp = SaldosInterbanking::armarCuentasBancarias(null, ['hoy' => IB_HOY, 'tango' => $tangoIb,
    'respaldos' => ['014|200|ARS' => ibResp(9, IB_HOY, 35000000.0)]]);
chequear('con la lectura caida el respaldo sigue entrando', 35000000.0, $caidaResp['filas'][0]['saldo']);
chequear('y el critico de la lectura sigue', true,
    ibContiene(ibAvisos($caidaResp['avisos'], Aviso::DANGER), 'No se pudieron leer los saldos bancarios'));
chequear('sin avisos por cuenta: el critico ya lo dice', 1, count($caidaResp['avisos']));

$soloResp = SaldosInterbanking::armarCuentasBancarias([], ['hoy' => IB_HOY, 'tango' => $tangoIb,
    'respaldos' => ['014|999|ARS' => ibResp(4, IB_HOY, 10.0, '014', '999')]]);
chequear('una cuenta con respaldo que BI dejo de traer se sigue mostrando',
    10.0, $soloResp['filas'][0]['saldo']);

$respVie = SaldosInterbanking::armarCuentasBancarias([], ['hoy' => IB_HOY, 'tango' => $tangoIb,
    'respaldos' => ['014|200|ARS' => ibResp(4, '2026-10-05', 10.0)]]);
chequear('"no es de hoy" aplica igual al respaldo', true, $respVie['filas'][0]['no_es_de_hoy']);

$d = Saldos::armarSerieDisponible($respVie['filas'], $hIb);
chequear('un respaldo viejo va a la primera columna con WARNING, no DANGER',
    'warning', implode(',', array_map(function ($a) { return $a['nivel']; }, $d['avisos_con_nivel'])));

// ============================================================================
seccion('respaldo manual: validacion');
// ============================================================================

$respOk = ['nro_banco' => '014', 'nro_cuenta' => '200', 'moneda' => 'ars',
           'fecha_saldo' => IB_HOY, 'saldo' => '35000000.5', 'observacion' => '  '];
$v = SaldosInterbanking::validarRespaldo($respOk, IB_HOY);

chequear('un respaldo de hoy se acepta', 35000000.5, $v['saldo']);
chequear('la moneda va en mayusculas', 'ARS', $v['moneda']);
chequear('una observacion vacia va null', null, $v['observacion']);
chequear('un saldo negativo (descubierto) se acepta', -10.0,
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['saldo' => '-10']), IB_HOY)['saldo']);

chequearLanza('no se acepta una fecha futura', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['fecha_saldo' => '2026-10-09']), IB_HOY);
});
chequearLanza('ni un importe vacio', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['saldo' => '']), IB_HOY);
});
chequearLanza('ni un importe que no es numero', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['saldo' => 'mucho']), IB_HOY);
});
chequearLanza('ni una fecha invalida', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['fecha_saldo' => '2026-02-30']), IB_HOY);
});
chequearLanza('ni una observacion de mas de 500 caracteres', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['observacion' => str_repeat('x', 501)]), IB_HOY);
});
chequearLanza('ni sin cuenta', function () use ($respOk) {
    SaldosInterbanking::validarRespaldo(array_merge($respOk, ['nro_cuenta' => ' ']), IB_HOY);
});

// ============================================================================
seccion('respaldo manual: el historial no se borra');
// ============================================================================

preg_match('/function guardarRespaldo\(.*?\n    }\n/s', $srcIb, $m);
$guardarResp = isset($m[0]) ? $m[0] : '';
preg_match('/function quitarRespaldo\(.*?\n    }\n/s', $srcIb, $m);
$quitarResp = isset($m[0]) ? $m[0] : '';

chequear('reemplazar da de baja el vigente con usuario y fecha',
    true, ibContiene($guardarResp, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA'));
chequear('e inserta el nuevo apuntando al anterior', true, ibContiene($guardarResp, 'ID_REEMPLAZA'));
chequear('todo en una transaccion',
    true, ibContiene($guardarResp, 'sqlsrv_begin_transaction') && ibContiene($guardarResp, 'sqlsrv_rollback'));
chequear('quitar da de baja', true, ibContiene($quitarResp, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA'));
chequear('ninguna de las dos borra', false, (bool) preg_match('/DELETE/i', $guardarResp . $quitarResp));
chequear('las dos validan el usuario de escritura', true,
    ibContiene($guardarResp, 'usuarioDeEscritura') && ibContiene($quitarResp, 'usuarioDeEscritura'));
chequear('el guardado rechaza un banco inactivo', true, ibContiene($guardarResp, 'bancoActivo('));

$sqlIb = file_get_contents(__DIR__ . '/../sql/cashflow_saldos_interbanking.sql');
chequear('el script deja un solo respaldo vigente por cuenta', true,
    (bool) preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CASHFLOW_SALDOS_BANCO_MANUAL_VIGENTE\s+'
        . 'ON dbo\.RO_T_CASHFLOW_SALDOS_BANCO_MANUAL \(NRO_BANCO, NRO_CUENTA, MONEDA\)\s+WHERE VIGENTE = 1/',
        $sqlIb));

$authSrc = file_get_contents(__DIR__ . '/../Class/AuthCashflow.php');
chequear('las acciones del respaldo piden el permiso de carga de saldos', true,
    ibContiene($authSrc, "'guardarRespaldoBanco' => [['saldos', null]]")
    && ibContiene($authSrc, "'quitarRespaldoBanco' => [['saldos', null]]"));

// ============================================================================
seccion('bancos sin Interbanking: carga manual');
// ============================================================================

chequear('el alta de un banco manual advierte que no sea uno de Interbanking',
    true, ibContiene((string) Saldos::advertenciaAltaCuenta('BANCO'), 'ya viene por Interbanking'));
chequear('el alta de otro tipo no advierte nada', null, Saldos::advertenciaAltaCuenta('MERCADO_PAGO'));
chequear('ni la de un fondo', null, Saldos::advertenciaAltaCuenta('BANCO', 'INVERSION'));

$srcSaldos = file_get_contents(__DIR__ . '/../Class/Saldos.php');
preg_match('/function getSaldosActuales\(\).*?\n    }\n/s', $srcSaldos, $m);
chequear('los bancos manuales siguen entrando por getSaldosActuales(): no se filtra el tipo',
    false, (bool) preg_match("/TIPO\s*(<>|!=|NOT IN)/", isset($m[0]) ? $m[0] : ''));
chequear('addCuenta() sigue aceptando BANCO',
    true, ibContiene($srcSaldos, "\$tipos = ['BANCO', 'MERCADO_PAGO', 'EFECTIVO_CENTRAL', 'OTRO'];"));

$pc = file_get_contents(__DIR__ . '/../Controller/ParametrosController.php');
chequear('la respuesta del alta lleva la advertencia',
    true, ibContiene($pc, "'advertencia' => Saldos::advertenciaAltaCuenta("));
$jsPs = file_get_contents(__DIR__ . '/../Js/Parametros-Saldos.js');
chequear('y el navegador la muestra', true, ibContiene($jsPs, 'Notificacion.advertencia(data.advertencia)'));

// Un banco manual en USD y uno de Interbanking en ARS: la serie suma cada uno
// en su moneda, y la pestana muestra el total en dolares aparte.
$mixto = [$filaCarga(71000.0, IB_HOY, 'USD', 'BANCO'), $filaIb(1000.0, IB_HOY)];
$d = Saldos::armarSerieDisponible($mixto, $hIb, ['2026-10' => 1400.0]);
$t = Saldos::totalesPorMoneda($mixto);
chequear('el banco manual en USD entra al tablero con su cotizacion',
    71000.0 * 1400.0 + 1000.0, $d['serie']['dias'][IB_HOY]);
chequear('y la pestana lo muestra en dolares, sin convertir', 71000.0, $t['USD']['total']);

// ============================================================================
seccion('el script de borrado de las cuentas bancarias manuales');
// ============================================================================

$sqlBorrar = file_get_contents(__DIR__ . '/../sql/cashflow_saldos_borrar_bancos_manuales.sql');

chequear('arranca en simulacion', true, (bool) preg_match('/DECLARE @SIMULAR BIT = 1;/', $sqlBorrar));
chequear('la simulacion corta antes de crear o borrar nada', true,
    strpos($sqlBorrar, 'IF @SIMULAR = 1') < strpos($sqlBorrar, 'CREATE TABLE dbo.')
    && strpos($sqlBorrar, 'IF @SIMULAR = 1') < strpos($sqlBorrar, 'DELETE D'));
chequear('@CONSERVAR trae BTG Uy por defecto', true, ibContiene($sqlBorrar, "('BTG Uy')"));
chequear('lo que esta en @CONSERVAR no entra en lo que se borra', true,
    (bool) preg_match("/WHERE C\.TIPO = 'BANCO'\s+AND NOT EXISTS \(SELECT 1 FROM @CONSERVAR K/", $sqlBorrar));
chequear('se puede conservar por nombre o por ID', true,
    ibContiene($sqlBorrar, 'K.VALOR = C.NOMBRE OR K.VALOR = CAST(C.ID AS VARCHAR(12))'));
chequear('no nombra ningun otro tipo de cuenta', false,
    (bool) preg_match("/EFECTIVO_CENTRAL|MERCADO_PAGO|'OTRO'/", $sqlBorrar));
chequear('el borrado de cuentas vuelve a filtrar TIPO = BANCO', true,
    (bool) preg_match("/DELETE C\s+FROM dbo\.RO_T_CASHFLOW_SALDOS_CUENTA C\s+WHERE C\.ID IN \(SELECT ID FROM #BORRAR\)\s+AND C\.TIPO = 'BANCO'/", $sqlBorrar));
chequear('busca aplicaciones de cobertura CTA_<id>', true,
    ibContiene($sqlBorrar, "SELECT ''CTA_'' + CAST(B.ID AS VARCHAR(12)) FROM #BORRAR B"));
chequear('y toda FK al catalogo salvo la del detalle', true,
    ibContiene($sqlBorrar, "FKC.referenced_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA')")
    && ibContiene($sqlBorrar, "FKC.parent_object_id <> OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DETALLE')"));
chequear('con referencias aborta antes de la simulacion y del borrado', true,
    (bool) preg_match('/IF EXISTS \(SELECT 1 FROM #REFERENCIAS\)\s+BEGIN.*?RAISERROR\(.ABORTADO.*?RETURN;\s+END/s', $sqlBorrar)
    && strpos($sqlBorrar, 'FROM #REFERENCIAS)') < strpos($sqlBorrar, 'IF @SIMULAR = 1'));
chequear('borra en una transaccion: primero el detalle, despues las cuentas', true,
    strpos($sqlBorrar, 'BEGIN TRANSACTION') < strpos($sqlBorrar, 'DELETE D')
    && strpos($sqlBorrar, 'DELETE D') < strpos($sqlBorrar, 'DELETE C')
    && strpos($sqlBorrar, 'DELETE C') < strpos($sqlBorrar, 'COMMIT TRANSACTION'));
chequear('no borra cabeceras de carga', false,
    (bool) preg_match('/DELETE\s+\w*\s*FROM dbo\.RO_T_CASHFLOW_SALDOS_CARGA/', $sqlBorrar));
chequear('deja la constancia dentro de la misma transaccion', true,
    strpos($sqlBorrar, 'INSERT INTO dbo.RO_T_CASHFLOW_SALDOS_DEPURACION') > strpos($sqlBorrar, 'DELETE C')
    && strpos($sqlBorrar, 'INSERT INTO dbo.RO_T_CASHFLOW_SALDOS_DEPURACION') < strpos($sqlBorrar, 'COMMIT TRANSACTION'));
chequear('y corta ante cualquier error (XACT_ABORT)', true, ibContiene($sqlBorrar, 'SET XACT_ABORT ON;'));

// ============================================================================
seccion('sin depurar no se lee Interbanking');
// ============================================================================

$sinDep = SaldosInterbanking::avisoSinDepurar();
chequear('el aviso es critico', Aviso::DANGER, $sinDep['nivel']);
chequear('y dice que script falta',
    true, ibContiene($sinDep['texto'], 'sql/cashflow_saldos_borrar_bancos_manuales.sql'));

preg_match('/public function getCuentasBancarias\(.*?\n    }\n/s', $srcIb, $m);
$getCb = isset($m[0]) ? $m[0] : '';
chequear('la constancia se pregunta antes de leer BI', true,
    strpos($getCb, '$this->depurado()') !== false
    && strpos($getCb, '$this->depurado()') < strpos($getCb, '$this->leerUltimos()'));
chequear('sin constancia no devuelve filas', true,
    (bool) preg_match("/if \(!\\\$depurado\) \{\s+return \['filas' => \[\]/", $getCb));
preg_match('/function guardarRespaldo\(.*?\n    }\n/s', $srcIb, $m);
chequear('el respaldo tampoco se carga sin depurar',
    true, ibContiene(isset($m[0]) ? $m[0] : '', 'if (!$this->depurado())'));

// ============================================================================
seccion('Parametros: la lista de bancos de Interbanking');
// ============================================================================

$lista = SaldosInterbanking::armarBancos($ultimos, ['tango' => $tangoIb,
    'bancos' => ['014' => ['ALIAS' => 'Provincia', 'ACTIVO' => 0, 'USUARIO_MODIF' => 'tesoreria',
                           'FECHA_MODIF' => '2026-10-08 12:00:00'],
                 '044' => ['ALIAS' => null, 'ACTIVO' => 1, 'USUARIO_MODIF' => 'x', 'FECHA_MODIF' => null]],
    'respaldos' => ['014|200|ARS' => ibResp(9, IB_HOY, 1.0)]]);
$porNro = [];

foreach ($lista as $b) {
    $porNro[$b['nro_banco']] = $b;
}

chequear('una fila por banco, ordenada por numero',
    ['007', '014', '015', '029', '044', '191'], array_column($lista, 'nro_banco'));
chequear('el banco inactivo esta en la lista, para poder reactivarlo', false, $porNro['014']['activo']);
chequear('con su alias', 'Provincia', $porNro['014']['alias']);
chequear('el nombre en Tango sale de DESC_BANCO, sin espacios', 'ICBC', $porNro['015']['desc_banco']);
chequear('un banco fuera de Tango no tiene nombre en Tango', null, $porNro['191']['desc_banco']);
chequear('un banco con fila que ya no viene sigue en la lista', true, isset($porNro['044']));
chequear('y sin cuentas', 0, count($porNro['044']['cuentas']));
chequear('el ultimo dato es la fecha mas nueva entre sus cuentas', IB_HOY, $porNro['015']['ultimo_dato']);
chequear('una cuenta con respaldo lleva la marca', true, $porNro['014']['cuentas'][0]['con_respaldo']);
chequear('y el banco tambien', true, $porNro['014']['con_respaldo']);
chequear('la cuenta sin moneda se lista igual', null, $porNro['029']['cuentas'][0]['moneda']);
chequear('un banco sin fila esta activo y sin alias', 'activo/sin alias',
    ($porNro['007']['activo'] ? 'activo' : 'inactivo') . '/' . ($porNro['007']['alias'] === null ? 'sin alias' : 'alias'));
chequear('y se sabe que no tiene fila (Ultima edicion vacia)', false, $porNro['007']['con_fila']);

$sinBi = SaldosInterbanking::armarBancos(null, ['tango' => $tangoIb,
    'bancos' => ['027' => ['ALIAS' => null, 'ACTIVO' => 0, 'USUARIO_MODIF' => null, 'FECHA_MODIF' => null]]]);
chequear('sin BI la lista sale de los bancos con fila', ['027'], array_column($sinBi, 'nro_banco'));
