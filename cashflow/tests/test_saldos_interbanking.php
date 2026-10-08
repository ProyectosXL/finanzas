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
