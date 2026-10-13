<?php
/**
 * Proveedores Locales: un comprobante pagado con DOS MEDIOS.
 *
 * Todo sin base: la importacion (compararImportacion()), lo que se escribe
 * (camposDeImportacion()), como entra al eje (abrirEnMedios()) y en que serie
 * cae cada peso (seriesDeItem()) son estaticas y puras, y las aperturas llegan
 * por parametro. Lo que toca la base -guardarApertura(), deletePago()- se fija
 * leyendo el metodo, como el resto de test_proveedores.php.
 *
 * Las reglas, en una linea cada una:
 *   - un medio: IMPORTE opcional, y si viene tiene que ser el pendiente (±$1);
 *   - dos medios: dos filas, ECHEQ o TRANSFERENCIA, IMPORTE en las dos, y que
 *     sumen el pendiente (±$1);
 *   - se cargan las dos o ninguna; tres filas es error en todas;
 *   - un comprobante en cuotas no se parte;
 *   - cada parte entra al eje con su importe y su fecha, y las series cierran.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';

$hoy = '2026-10-13';

/** Un pendiente, como lo devuelve getPendientes() sin horizonte */
$pend = function ($cod, $t, $n, $importe, $extra = []) {
    return array_merge([
        'COD_PROVEE' => $cod, 'T_COMP' => $t, 'N_COMP' => $n, 'RAZON_SOC' => 'N ' . $cod,
        'IMPORTE_PENDIENTE' => $importe, 'FECHA_VTO' => '2026-10-01',
        'SIN_FECHA_CARGADA' => true, 'FORMA_PAGO' => 'ECHEQ',
        'FORMA_PAGO_VIGENTE' => 'ECHEQ', 'CRONOGRAMA' => true
    ], $extra);
};

$pendientes = [
    $pend('MTDODI', 'FAC', 'A0000500001731', 200000.0),
    // Forma vigente CAJA: no es del cronograma.
    $pend('OGCAJA', 'FAC', 'A0000100000077', 1000.0,
          ['FORMA_PAGO' => 'CAJA', 'FORMA_PAGO_VIGENTE' => 'CAJA', 'CRONOGRAMA' => false]),
    // En cuotas: dos vencimientos del mismo comprobante.
    $pend('OGRSA', 'FAC', 'A0010000247110', 1000.0),
    $pend('OGRSA', 'FAC', 'A0010000247110', 3000.0, ['FECHA_VTO' => '2026-11-01'])
];

/** Una fila de la planilla */
$fila = function ($linea, $cod, $n, $fecha, $forma = '', $importe = '', $obs = '') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => $obs];
};

$comparar = function ($filas, $pagos = [], $aperturas = [], $conMedios = true) use ($pendientes, $hoy) {
    return Proveedores::compararImportacion($filas, $pendientes, $pagos, $hoy, $aperturas, $conMedios);
};

/* ================================================================
   UN MEDIO
   ================================================================ */
seccion('un medio sin importe toma el pendiente');

$c = $comparar([$fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'TRANSFERENCIA')]);

chequear('se carga', 'ALTA', $c['filas'][0]['estado']);
chequear('con todo lo pendiente', 200000.0, $c['filas'][0]['importe_pendiente']);
chequear('sin apertura: es un comprobante con una fecha', null,
    Proveedores::camposDeImportacion($c['filas'])['medios']);
chequear('y el resumen no cuenta dos medios', 0, $c['resumen']['dos_medios']);

seccion('un medio con importe: tiene que ser el pendiente, con un peso de tolerancia');

$c = $comparar([$fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', '', '199999,40')]);

chequear('a menos de un peso se carga', 'ALTA', $c['filas'][0]['estado']);

$c = $comparar([$fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', '', '150000')]);

chequear('con otro importe queda en error', 'ERROR', $c['filas'][0]['estado']);
chequear('y el motivo dice la diferencia', true,
    strpos($c['filas'][0]['motivo'], '$ -50.000,00') !== false
    && strpos($c['filas'][0]['motivo'], 'pendiente') !== false);

$c = $comparar([$fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', '', 'mil pesos')]);

chequear('un importe que no es un numero es un error, no "vacio"', 'ERROR', $c['filas'][0]['estado']);

/* ================================================================
   DOS MEDIOS
   ================================================================ */
seccion('dos medios que suman el pendiente se cargan');

$dos = [
    $fila(2, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '50000,80'),
    $fila(3, 'MTDODI', 'A0000500001731', '20/10/2026', 'echeq', '150000')
];

$c = $comparar($dos);

chequear('las dos filas se cargan', ['ALTA', 'ALTA'], array_column($c['filas'], 'estado'));
chequear('a 80 centavos del pendiente, dentro de la tolerancia', 0, $c['resumen']['errores']);
chequear('el resumen cuenta UN comprobante, no dos filas', 1, $c['resumen']['altas']);
chequear('y lo cuenta como abierto en dos medios', 1, $c['resumen']['dos_medios']);
chequear('lo que se reubica es el pendiente, una vez', 200000.0, $c['resumen']['importe_con_fecha']);
chequear('el aviso dice cuantos quedan en dos medios', true,
    strpos(implode(' ', $c['avisos']), '1 comprobante(s) quedan pagados con dos medios') !== false);

// 1/2 es la parte que se paga primero, aunque en el archivo venga segunda.
$porLinea = [];
foreach ($c['filas'] as $f) { $porLinea[$f['linea']] = $f; }

chequear('la de fecha mas temprana es la parte 1', 1, $porLinea[3]['parte']);
chequear('y la otra la 2', 2, $porLinea[2]['parte']);
chequear('las dos salen juntas, agrupadas por el comprobante', [3, 2],
    array_column($c['filas'], 'linea'));
chequear('la forma en minuscula matchea', 'ECHEQ', $porLinea[3]['forma_pago']);

seccion('lo que se escribe con dos medios');

$campos = Proveedores::camposDeImportacion($c['filas']);

chequear('dos medios en la apertura', 2, count($campos['medios']));
chequear('en orden, con su fecha, su forma y su importe', [
    ['orden' => 1, 'fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'forma_orig' => 'echeq',
     'importe' => 150000.0],
    ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA',
     'forma_orig' => 'TRANSFERENCIA', 'importe' => 50000.8]
], $campos['medios']);

/* LA FECHA MAS TARDIA VA A LA TABLA DE PAGOS. Es la que mide el desvio de la
   conciliacion y la que proyecta el saldo si el pendiente deja de coincidir. */
chequear('la tabla de pagos lleva la fecha mas tardia', '2026-10-27', $campos['pago']['FECHA_PAGO']);
chequear('y la forma en null: los medios estan en la apertura', null, $campos['pago']['FORMA_PAGO']);
chequear('sin tocar el override de forma', false,
    array_key_exists('FORMA_PAGO_CRONOGRAMA', $campos['pago']));

seccion('dos medios que no suman el pendiente: error en las dos, con la diferencia');

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '150000'),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '40000')
]);

chequear('las dos en error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('el motivo dice cuanto suman, el pendiente y la diferencia', true,
    strpos($c['filas'][0]['motivo'], '$ 190.000,00') !== false
    && strpos($c['filas'][0]['motivo'], '$ 200.000,00') !== false
    && strpos($c['filas'][0]['motivo'], '$ -10.000,00') !== false);
chequear('y es el mismo en las dos: el error es del comprobante', $c['filas'][0]['motivo'],
    $c['filas'][1]['motivo']);
chequear('se cuentan las dos filas como error', 2, $c['resumen']['errores']);

seccion('tres filas del mismo comprobante: error en todas');

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '100000'),
    $fila(5, 'MTDODI', 'A0000500001731', '27/10/2026', 'ECHEQ', '50000'),
    $fila(9, 'MTDODI', 'A0000500001731', '03/11/2026', 'TRANSFERENCIA', '50000')
]);

chequear('las tres en error', ['ERROR', 'ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('nombrando las lineas', true, strpos($c['filas'][2]['motivo'], 'líneas 2, 5 y 9') !== false);

seccion('con dos medios, la forma es ECHEQ o TRANSFERENCIA en las dos');

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '150000'),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'CAJA', '50000')
]);

chequear('CAJA en una deja en error las dos', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('nombrando la linea y lo que trae', true,
    strpos($c['filas'][0]['motivo'], 'la línea 3 trae «CAJA»') !== false);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', '', '150000'),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '50000')
]);

chequear('sin forma tambien', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('y lo dice', true, strpos($c['filas'][1]['motivo'], 'la línea 2 no trae forma') !== false);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', ''),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '200000')
]);

chequear('sin importe en una, error en las dos', ['ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));

seccion('una fila mala de dos: no se carga ninguna');

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', 'el jueves', 'ECHEQ', '150000'),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '50000')
]);

chequear('las dos en error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('la mala conserva su propio motivo', true,
    strpos($c['filas'][0]['motivo'], 'el jueves') !== false);
chequear('y la buena dice por que tampoco se carga', true,
    strpos($c['filas'][1]['motivo'], 'las dos filas o ninguna') !== false
    && strpos($c['filas'][1]['motivo'], 'la línea 2') !== false);
chequear('no queda nada para escribir', 0,
    $c['resumen']['altas'] + $c['resumen']['cambios']);

seccion('un comprobante en cuotas no se parte en dos medios');

$c = $comparar([
    $fila(2, 'OGRSA', 'A0010000247110', '20/10/2026', 'ECHEQ', '2000'),
    $fila(3, 'OGRSA', 'A0010000247110', '27/10/2026', 'TRANSFERENCIA', '2000')
]);

chequear('las dos en error', ['ERROR', 'ERROR'], array_column($c['filas'], 'estado'));
chequear('con el motivo', true,
    strpos($c['filas'][0]['motivo'], 'es un comprobante en cuotas: cargalo con un solo medio') !== false);

$c = $comparar([$fila(2, 'OGRSA', 'A0010000247110', '20/10/2026', 'ECHEQ')]);

chequear('con un solo medio se carga, como siempre', 'ALTA', $c['filas'][0]['estado']);

seccion('dos ECHEQ el mismo dia: desempata el orden del archivo');

$c = $comparar([
    $fila(4, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '120000'),
    $fila(7, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '80000')
]);

chequear('se carga: el mismo medio dos veces vale', ['ALTA', 'ALTA'],
    array_column($c['filas'], 'estado'));
chequear('la parte 1 es la que venia antes en el archivo', [4 => 1, 7 => 2],
    array_column($c['filas'], 'parte', 'linea'));
chequear('y la apertura lo respeta', [120000.0, 80000.0],
    array_column(Proveedores::camposDeImportacion($c['filas'])['medios'], 'importe'));

seccion('una forma vigente fuera del cronograma se acepta, y se avisa');

$c = $comparar([
    $fila(2, 'OGCAJA', 'A0000100000077', '20/10/2026', 'ECHEQ', '600'),
    $fila(3, 'OGCAJA', 'A0000100000077', '27/10/2026', 'TRANSFERENCIA', '400')
]);

chequear('se carga', ['ALTA', 'ALTA'], array_column($c['filas'], 'estado'));
chequear('con el aviso de que se sigue tratando como CAJA',
    'Se sigue tratando como CAJA; para que entre al cronograma, cambiá su forma en la grilla o el '
        . 'maestro.', $c['filas'][0]['aviso']);
chequear('en las dos partes', $c['filas'][0]['aviso'], $c['filas'][1]['aviso']);
chequear('y el resumen lo cuenta', 1, $c['resumen']['dos_medios_fuera_cronograma']);

$c = $comparar($dos);

chequear('del cronograma no hay aviso', null, $c['filas'][0]['aviso']);

/* ================================================================
   REIMPORTAR
   ================================================================ */
seccion('reimportar reemplaza la apertura entera');

$clave = Proveedores::clavePago('MTDODI', 'FAC', 'A0000500001731');
$aperturas = [$clave => Proveedores::camposDeImportacion($comparar($dos)['filas'])['medios']];
$pagos = [$clave => ['COD_PROVEE' => 'MTDODI', 'T_COMP' => 'FAC', 'N_COMP' => 'A0000500001731',
                     'FECHA_PAGO' => '2026-10-27', 'FORMA_PAGO' => null, 'ESTADO' => 'PREVISTO']];

// La misma planilla, con las filas al reves: es la misma apertura.
$c = $comparar(array_reverse($dos), $pagos, $aperturas);

chequear('reimportar igual es SIN_CAMBIOS', ['SIN_CAMBIOS', 'SIN_CAMBIOS'],
    array_column($c['filas'], 'estado'));
chequear('y no cuenta como alta ni cambio', 0, $c['resumen']['altas'] + $c['resumen']['cambios']);

$c = $comparar([$fila(2, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA')], $pagos,
    $aperturas);

chequear('con una fila deja de estar partido: es un cambio', 'CAMBIO', $c['filas'][0]['estado']);
chequear('aunque la fecha sea la de una de las partes', true,
    strpos($c['filas'][0]['motivo'], 'Deja de estar pagado con dos medios') !== false);
chequear('y lo que se escribe no tiene apertura: se da de baja', null,
    Proveedores::camposDeImportacion($c['filas'])['medios']);

$c = $comparar([
    $fila(2, 'MTDODI', 'A0000500001731', '20/10/2026', 'ECHEQ', '100000'),
    $fila(3, 'MTDODI', 'A0000500001731', '27/10/2026', 'TRANSFERENCIA', '100000')
], $pagos, $aperturas);

chequear('otros importes son un cambio de apertura', ['CAMBIO', 'CAMBIO'],
    array_column($c['filas'], 'estado'));
chequear('y dice como estaba', true, strpos($c['filas'][0]['motivo'], 'estaba: ECHEQ') !== false);

chequear('mismaApertura compara al centavo', false, Proveedores::mismaApertura(
    [['fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 100.0]],
    [['fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 100.01]]));

seccion('sin el script: las filas de dos medios en error, el resto igual');

$c = $comparar(array_merge($dos, [$fila(4, 'OGCAJA', 'A0000100000077', '20/10/2026')]), [], [],
    false);

chequear('las dos filas del comprobante en error', ['ERROR', 'ERROR'],
    [$c['filas'][0]['estado'], $c['filas'][1]['estado']]);
chequear('nombrando el script', true,
    strpos($c['filas'][0]['motivo'], 'sql/cashflow_prov_locales_pago_medio.sql') !== false);
chequear('y la fila de un medio se carga como siempre', 'ALTA', $c['filas'][2]['estado']);

/* ================================================================
   EL EJE
   ================================================================ */

/** Un pendiente como lo deja getPendientes() con horizonte, antes de abrirlo */
$item = function ($extra = []) {
    return array_merge([
        'COD_PROVEE' => 'MTDODI', 'RAZON_SOC' => 'DONNA', 'T_COMP' => 'FAC',
        'N_COMP' => 'A0000500001731', 'FECHA_EMIS' => '2026-09-01', 'FECHA_VTO' => '2026-10-01',
        'IMPORTE_VTO' => 210000.0, 'IMPUTACIONES' => -10000.0, 'IMPORTE_PENDIENTE' => 200000.0,
        'Pago' => '2026-10-27', 'PAGO_ORIGINAL' => '2026-10-27', 'ORIGEN_FECHA' => 'CARGADA',
        'PAGO_CRONO' => false, 'PAGO_BASE' => '2026-10-27', 'PAGO_CRONO_NOMBRE' => null,
        'PAGO_CRONO_A_MANO' => false, 'FUENTE_FECHA' => 'ARCHIVO', 'VENCIDA' => false,
        'SIN_FECHA_CARGADA' => false, 'FECHA_PAGO' => '2026-10-27', 'EXCLUIDO' => false,
        'EXCLUIDA_MANUAL' => false, 'EXCLUIDO_PROVEEDOR' => false, 'EN_MAESTRO' => true,
        'SERIE' => 'RUBRO_MERCADERIA', 'CRONOGRAMA' => true, 'FORMA_PAGO_MAESTRO' => 'ECHEQ',
        'APERTURA' => null, 'APERTURA_DESCUADRADA' => null
    ], $extra);
};

/* Una parte VENCIDA (el 05/10, antes de hoy) y una FUTURA (el 27/10). El
   cronograma paga el 14/10 y el 28/10. */
$apertura = [$clave => [
    ['orden' => 1, 'fecha' => '2026-10-05', 'forma' => 'ECHEQ', 'importe' => 150000.0,
     'usuario' => 'ROROZCO', 'fecha_modif' => '2026-10-10 10:00:00'],
    ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA', 'importe' => 49999.4,
     'usuario' => 'ROROZCO', 'fecha_modif' => '2026-10-10 10:00:00']
]];
$crono = [['fecha' => '2026-10-14', 'nombre' => '2do miércoles'],
          ['fecha' => '2026-10-28', 'nombre' => '4to miércoles']];

seccion('cada parte entra al eje con su importe y su fecha');

$partes = Proveedores::abrirEnMedios([$item()], $apertura, $hoy, $crono);

chequear('un comprobante abierto es una fila por parte', 2, count($partes));
chequear('la parte vencida del cronograma va al proximo dia de pago', '2026-10-14', $partes[0]['Pago']);
chequear('marcada como del cronograma', true, $partes[0]['PAGO_CRONO']);
chequear('la parte futura se respeta tal cual', '2026-10-27', $partes[1]['Pago']);
chequear('cada parte con su importe; la ultima se lleva el resto de la tolerancia',
    [150000.0, 50000.0], array_column($partes, 'IMPORTE_PENDIENTE'));
chequear('el comprobante suma exactamente lo mismo que sin abrir', 200000.0,
    array_sum(array_column($partes, 'IMPORTE_PENDIENTE')));
chequear('el importe y lo imputado se reparten sin mover el total', [210000.0, -10000.0], [
    round(array_sum(array_column($partes, 'IMPORTE_VTO')), 2),
    round(array_sum(array_column($partes, 'IMPUTACIONES')), 2)]);
chequear('cada parte dice cual es', [[1, 2], [2, 2]], [
    [$partes[0]['APERTURA']['parte'], $partes[0]['APERTURA']['de']],
    [$partes[1]['APERTURA']['parte'], $partes[1]['APERTURA']['de']]]);
chequear('y trae los dos medios y quien los cargo, para el tooltip', [2, 'ROROZCO'],
    [count($partes[1]['APERTURA']['medios']), $partes[1]['APERTURA']['usuario']]);
chequear('las dos son fecha de la planilla', ['ARCHIVO', 'ARCHIVO'],
    array_column($partes, 'FUENTE_FECHA'));

// Fuera del cronograma, la parte vencida conserva su fecha como siempre.
$fuera = Proveedores::abrirEnMedios([$item(['CRONOGRAMA' => false])], $apertura, $hoy, $crono);

chequear('si la forma vigente no es del cronograma, la parte vencida conserva su fecha',
    '2026-10-05', $fuera[0]['Pago']);

seccion('si el pendiente ya no coincide, todo el saldo en la fecha mas tardia');

// En Tango se imputo un pago parcial: el pendiente bajo a 120.000.
$parcial = Proveedores::abrirEnMedios([$item(['IMPORTE_PENDIENTE' => 120000.0])], $apertura, $hoy,
    $crono);

chequear('no se parte', 1, count($parcial));
chequear('se proyecta todo lo que queda', 120000.0, $parcial[0]['IMPORTE_PENDIENTE']);
chequear('en la fecha mas tardia, la que tiene la tabla de pagos', '2026-10-27', $parcial[0]['Pago']);
chequear('y queda marcado', true, !empty($parcial[0]['APERTURA_DESCUADRADA']));

$avisos = Aviso::textos(Proveedores::avisosPendientesConNivel($parcial));

chequear('la pestaña avisa, con el texto pedido', true, in_array('1 comprobante(s) abiertos en dos '
    . 'medios ya no coinciden con su pendiente: se proyecta el saldo en la fecha más tardía. '
    . 'Reimportá para corregirlo.', $avisos, true));
chequear('con nivel atencion', 'warning',
    Proveedores::avisosPendientesConNivel($parcial)[0]['nivel']);

// Si en Tango pasa a tener dos vencimientos, tampoco se parte.
$enCuotas = Proveedores::abrirEnMedios([$item(['IMPORTE_PENDIENTE' => 100000.0]),
    $item(['IMPORTE_PENDIENTE' => 100000.0, 'FECHA_VTO' => '2026-11-01'])], $apertura, $hoy, $crono);

chequear('con dos vencimientos tampoco se parte', [2, true, true], [count($enCuotas),
    !empty($enCuotas[0]['APERTURA_DESCUADRADA']), !empty($enCuotas[1]['APERTURA_DESCUADRADA'])]);

seccion('las dos particiones de PAGOS_TODO cierran con comprobantes abiertos');

$universo = array_merge(
    Proveedores::abrirEnMedios([$item()], $apertura, $hoy, $crono),
    [$item(['COD_PROVEE' => 'OGCAJA', 'N_COMP' => 'X1', 'IMPORTE_PENDIENTE' => 1000.0,
            'CRONOGRAMA' => false])],
    [$item(['COD_PROVEE' => 'OGEXCL', 'N_COMP' => 'X2', 'IMPORTE_PENDIENTE' => 700.0,
            'EXCLUIDO' => true, 'EXCLUIDA_MANUAL' => true])]
);

$porSerie = [];

foreach ($universo as $u) {
    foreach (ProveedoresProvider::seriesDeItem($u) as $s) {
        $porSerie[$s] = (isset($porSerie[$s]) ? $porSerie[$s] : 0) + $u['IMPORTE_PENDIENTE'];
    }
}

$s = function ($c) use ($porSerie) { return isset($porSerie[$c]) ? $porSerie[$c] : 0.0; };

chequear('el universo es todo lo pendiente', 201700.0, $s('PAGOS_TODO'));
chequear('por como se paga', $s('PAGOS_TODO'), $s('PAGOS') + $s('PAGOS_FUERA_CRONOGRAMA')
    + $s('PAGOS_EXCLUIDOS_FACTURA') + $s('PAGOS_EXCLUIDOS_PROVEEDOR'));
chequear('por que rubro es', $s('PAGOS_TODO'), $s('PAGOS_OPERATIVOS') + $s('PAGOS_EXCLUIDOS'));
chequear('las dos partes entran enteras a PAGOS', 200000.0, $s('PAGOS'));

seccion('excluir el comprobante excluye las dos partes');

$excl = Proveedores::abrirEnMedios([$item(['EXCLUIDO' => true, 'EXCLUIDA_MANUAL' => true])],
    $apertura, $hoy, $crono);

chequear('las dos partes quedan excluidas', [true, true], array_column($excl, 'EXCLUIDA_MANUAL'));
chequear('y las dos van a la serie de las excluidas a mano', [true, true], [
    in_array('PAGOS_EXCLUIDOS_FACTURA', ProveedoresProvider::seriesDeItem($excl[0]), true),
    in_array('PAGOS_EXCLUIDOS_FACTURA', ProveedoresProvider::seriesDeItem($excl[1]), true)]);

$exclProv = Proveedores::abrirEnMedios([$item(['EXCLUIDO' => true, 'EXCLUIDO_PROVEEDOR' => true])],
    $apertura, $hoy, $crono);

chequear('excluir al proveedor tambien', [true, true], [
    in_array('PAGOS_EXCLUIDOS_PROVEEDOR', ProveedoresProvider::seriesDeItem($exclProv[0]), true),
    in_array('PAGOS_EXCLUIDOS_PROVEEDOR', ProveedoresProvider::seriesDeItem($exclProv[1]), true)]);

seccion('el comprobante se cuenta una vez');

chequear('la primera parte cuenta como vencimiento', true,
    Proveedores::cuentaComoVencimiento($partes[0]));
chequear('la segunda no', false, Proveedores::cuentaComoVencimiento($partes[1]));
chequear('una fila sin apertura si', true, Proveedores::cuentaComoVencimiento($item()));

$avExcl = Aviso::textos(Proveedores::avisosPendientesConNivel($excl));
$avExclTxt = implode(' ', $avExcl);

/* El aviso de las del rubro excluido cuenta vencimientos: un comprobante abierto
   es UNO, aunque sean dos filas. */
chequear('los avisos lo cuentan una vez', true, strpos($avExclTxt, '1 vencimiento(s)') !== false);

/* ================================================================
   LOS GESTOS DE LA GRILLA
   ================================================================ */
seccion('el fechado masivo no toca los comprobantes abiertos');

$claves = [
    $clave => ['MTDODI', 'FAC', 'A0000500001731'],
    Proveedores::clavePago('OGCAJA', 'FAC', 'X1') => ['OGCAJA', 'FAC', 'X1']
];

list($quedan, $omitidos) = Proveedores::sinAbiertos($claves, $apertura);

chequear('el abierto se saltea', [Proveedores::clavePago('OGCAJA', 'FAC', 'X1')], array_keys($quedan));
chequear('y se cuenta', 1, $omitidos);

$fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
$cuerpoDe = function ($metodo) {
    $r = new ReflectionMethod('Proveedores', $metodo);

    return str_replace("\r\n", "\n", implode('', array_slice(file(__DIR__ . '/../Class/Proveedores.php'),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1)));
};

chequear('saveFechaMasiva filtra con sinAbiertos() antes de escribir', true,
    strpos($cuerpoDe('saveFechaMasiva'), 'self::sinAbiertos($claves, $this->getAperturas())')
        < strpos($cuerpoDe('saveFechaMasiva'), '$this->guardarLote('));
chequear('y savePago rechaza un comprobante abierto antes de escribir', true,
    strpos($cuerpoDe('savePago'), 'isset($this->getAperturas()[self::clavePago($cod, $t, $n)])')
        < strpos($cuerpoDe('savePago'), '$this->guardarPago('));

seccion('volver al vencimiento borra la apertura y deja lo demas');

$del = $cuerpoDe('deletePago');

chequear('da de baja la apertura entera', true,
    strpos($del, '$this->guardarApertura($cid, $clave[0], $clave[1], $clave[2], null, $usuario);') !== false);
chequear('dentro de la misma transaccion que la fecha', true,
    strpos($del, 'sqlsrv_begin_transaction') < strpos($del, '$this->guardarApertura(')
    && strpos($del, '$this->guardarApertura(') < strpos($del, 'SET FECHA_PAGO = NULL'));
chequear('la tabla de pagos sigue tocando solo la fecha y su fuente', 1,
    preg_match_all('/SET FECHA_PAGO = NULL"\s*\. \(in_array\(\'FUENTE_FECHA\'/', $del));
chequear('y borra la fila solo si quedo vacia: la exclusion, el override y la observacion quedan',
    true, strpos($del, 'self::condicionFilaVacia($opcionales)') !== false);

$apert = $cuerpoDe('guardarApertura');

chequear('la apertura no tiene bajas fisicas', false, stripos($apert, 'DELETE') !== false);
chequear('reemplazar es dar de baja las vigentes', true,
    strpos($apert, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA') !== false);
chequear('con el usuario de la escritura en el alta y la modificacion', true,
    strpos($apert, 'IMPORTE, USUARIO_ALTA, USUARIO_MODIF)') !== false);

seccion('la escritura de la apertura pasa por un solo lugar');

$escriturasMedio = '/(INSERT INTO|UPDATE|DELETE FROM) dbo\." \. self::TABLA_MEDIO/';

chequear('solo guardarApertura() escribe la tabla de medios: todas sus escrituras estan ahi',
    preg_match_all($escriturasMedio, $fuente), preg_match_all($escriturasMedio, $apert));
chequear('y son dos: la baja y el alta', 2, preg_match_all($escriturasMedio, $apert));
chequear('aplicarImportacion() la escribe en su transaccion, junto con la fila de pagos', true,
    strpos($cuerpoDe('aplicarImportacion'), 'sqlsrv_begin_transaction')
        < strpos($cuerpoDe('aplicarImportacion'), '$this->guardarApertura(')
    && strpos($cuerpoDe('aplicarImportacion'), '$this->guardarApertura(')
        < strpos($cuerpoDe('aplicarImportacion'), 'sqlsrv_commit'));

seccion('la conciliacion mide el desvio contra la fecha mas tardia');

/* conciliar() compara la FECHA_PAGO de la tabla de pagos contra la real, y
   para un comprobante abierto esa fecha es la mas tardia. */
chequear('conciliar usa la fecha de la tabla de pagos', true,
    strpos($cuerpoDe('conciliar'), "self::desvioDias(\$pago['FECHA_PAGO'],") !== false);
chequear('que para un abierto es la mas tardia de las dos', '2026-10-27',
    Proveedores::camposDeImportacion($comparar($dos)['filas'])['pago']['FECHA_PAGO']);
chequear('asi que pagar el 30/10 da 3 dias tarde, no 10', 3,
    Proveedores::desvioDias('2026-10-27', '2026-10-30'));

/* ================================================================
   LA CONFIRMACION NO LE CREE AL NAVEGADOR
   ================================================================ */
seccion('confirmar recalcula el diff y frena si cambio algo');

$visto = $comparar($dos)['filas'];

chequear('lo mismo que se vio no tiene diferencias', [],
    Proveedores::diferenciasConLoVisto($visto, $comparar($dos)['filas']));

// Entre previsualizar y confirmar, en Tango se pago una parte.
$despues = Proveedores::compararImportacion(array_column($visto, 'crudo'),
    [$pend('MTDODI', 'FAC', 'A0000500001731', 120000.0)], [], $hoy, [], true);

chequear('si hoy da otra cosa, se dice que linea cambio', true,
    in_array('línea 2: ALTA → ERROR', Proveedores::diferenciasConLoVisto($visto, $despues['filas']), true));
chequear('cada fila trae lo que vino en el archivo, para volver a compararla', '50000,80',
    $visto[1]['crudo']['importe']);
chequear('aplicarImportacion() recalcula antes de abrir la transaccion', true,
    strpos($cuerpoDe('aplicarImportacion'), 'self::diferenciasConLoVisto(')
        < strpos($cuerpoDe('aplicarImportacion'), 'sqlsrv_begin_transaction'));

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script y el codigo dicen lo mismo');

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_pago_medio.sql');

chequear('el CHECK de la forma es FORMAS_CRONOGRAMA', true,
    preg_match("/CHECK \(FORMA_PAGO IN \('ECHEQ', 'TRANSFERENCIA'\)\)/", $sql) === 1
    && ProveedoresCategorias::FORMAS_CRONOGRAMA === ['ECHEQ', 'TRANSFERENCIA']);
chequear('el ORDEN va de 1 a MAX_MEDIOS', true,
    strpos($sql, 'CHECK (ORDEN IN (1, 2))') !== false && Proveedores::MAX_MEDIOS === 2);
chequear('la clave con la collation de Tango', 3,
    preg_match_all('/(COD_PROVEE|T_COMP|N_COMP)\s+VARCHAR\(\d+\)\s+COLLATE Latin1_General_BIN NOT NULL/', $sql));
chequear('una parte vigente por orden, con un indice filtrado', true,
    strpos($sql, 'WHERE VIGENTE = 1;') !== false);
chequear('el esquema de auditoria del modulo', 6,
    preg_match_all('/^\s+(USUARIO_ALTA|FECHA_ALTA|USUARIO_MODIF|FECHA_MODIF|USUARIO_BAJA|FECHA_BAJA)\s/m', $sql));
chequear('y es reejecutable', true,
    strpos($sql, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'U') IS NULL") !== false);
chequear('la clase lo nombra', 'sql/cashflow_prov_locales_pago_medio.sql', Proveedores::SCRIPT_MEDIO);

/* ================================================================
   LA GRILLA
   ================================================================ */
seccion('la grilla: dos filas, una por parte, sin contar dos veces');

$js = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Proveedores-Proveedores_locales.js'));
$cuerpoJs = function ($fn) use ($js) {
    return preg_match('/    function ' . $fn . '\(.*?\n    \}\n/s', $js, $m) ? $m[0] : '';
};

$celdaAbierta = $cuerpoJs('celdaFechaAbierta');

chequear('la celda de un comprobante abierto existe', true, $celdaAbierta !== '');
chequear('y no tiene campo de fecha: no se edita', false, strpos($celdaAbierta, '<input') !== false);
chequear('lleva 1/2 y 2/2 como etiqueta, fuera del texto que se exporta', true,
    strpos($celdaAbierta, "f.APERTURA.parte + '/' + f.APERTURA.de") !== false
    && strpos($celdaAbierta, 'data-etiqueta="') !== false);
chequear('el tooltip dice los medios y quien los cargo', true,
    strpos($celdaAbierta, 'textoMedios(abierta)') !== false
    && strpos($celdaAbierta, 'Auditoria.texto({ usuario: abierta.usuario') !== false);
chequear('con el boton de volver, solo con permiso de edicion', true,
    preg_match("/if \(Permisos\.puedeEditar\('bodyProv'\)\) \{\s*html \+= ' <button class=\"btn btn-sm btn-outline-secondary prov-btn-borrar\"/", $celdaAbierta) === 1);
chequear('celdaFechaPago la usa para los abiertos', true,
    strpos($cuerpoJs('celdaFechaPago'), "if (esAbierto(f)) {\n            return celdaFechaAbierta(f);") !== false);

chequear('los indicadores cuentan el comprobante una vez', true,
    strpos($cuerpoJs('calcularIndicadores'), 'var cuenta = esParteExtra(f) ? 0 : 1;') !== false
    && strpos($cuerpoJs('calcularIndicadores'), 'k.vencimientos += cuenta;') !== false);
chequear('y suman su importe completo', true,
    strpos($cuerpoJs('calcularIndicadores'), 'k.total += importe;') !== false);
chequear('las cuotas cuentan vencimientos distintos, no filas', true,
    strpos($cuerpoJs('cuotasDe'), "var v = k + '|' + x.FECHA_VTO;") !== false);
chequear('la barra cuenta comprobantes y suma todas las partes', true,
    strpos($cuerpoJs('pintarSeleccion'), 'var sel = unaPorComprobante(filas);') !== false
    && preg_match('/filas\.forEach\(function\(f\) \{\s*total \+=/', $cuerpoJs('pintarSeleccion')) === 1);
chequear('tildar una parte tilda la otra', true,
    strpos($js, "otro.getAttribute('data-clave') === k") !== false);
chequear('excluir manda cada comprobante una vez', true,
    strpos($cuerpoJs('accionSeleccion'), 'var aplicar = unaPorComprobante(filas).filter(') !== false);
chequear('el fechado masivo saca los abiertos y dice cuantos son', true,
    strpos($cuerpoJs('fecharSeleccion'), 'var sel = todas.filter(function(f) { return !esAbierto(f); });') !== false
    && strpos($cuerpoJs('fecharSeleccion'), 'no se van a tocar') !== false);
chequear('la previsualizacion muestra los comprobantes en dos medios', true,
    strpos($js, "['dos_medios', 'En dos medios', 'info']") !== false);
chequear('con las dos partes juntas', true,
    strpos($cuerpoJs('tablaPreview'), '((a.grupo || a.linea) - (b.grupo || b.linea))') !== false);
chequear('y el aviso de la forma vigente', true, strpos($cuerpoJs('tablaPreview'), 'if (f.aviso) {') !== false);
