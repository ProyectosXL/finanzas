<?php
/**
 * Proveedores Locales: UN CHEQUE PARA VARIAS FACTURAS (pago agrupado).
 *
 * Un proveedor con muchas facturas chicas se paga con un solo cheque. En la
 * planilla son las filas de esas facturas, del mismo proveedor, con la misma
 * fecha y la misma forma: una trae el importe del cheque y las demas vienen
 * en 0, o vacias si ese importe supera el maximo de su propia factura.
 *
 * Todo sin base: la regla (agruparPagos(), unPagoAgrupado() por
 * compararImportacion()), lo que se escribe (camposDeImportacion()), lo que
 * se deshace (deshaceGrupos()), como entra al eje (abrirEnMedios()) y en que
 * serie cae cada peso. Lo que toca la base se fija leyendo el metodo.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';
require_once __DIR__ . '/../Class/Horizonte.php';

$hoy = '2026-10-13';

$cuerpoA = function ($metodo) {
    $r = new ReflectionMethod('Proveedores', $metodo);

    return implode('', array_slice(file(__DIR__ . '/../Class/Proveedores.php'),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script del pago agrupado');

$sqlA = file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_pago_agrupado.sql');

chequear('agrega GRUPO_PAGO, nullable y con la collation de Tango', true,
    strpos($sqlA, 'ADD GRUPO_PAGO VARCHAR(60) COLLATE Latin1_General_BIN NULL') !== false);
chequear('con un indice filtrado por las partes vigentes', true,
    strpos($sqlA, 'WHERE VIGENTE = 1 AND GRUPO_PAGO IS NOT NULL') !== false);
chequear('corta si falta el de N medios', true,
    strpos($sqlA, "AND definition LIKE '%>=%'") !== false && strpos($sqlA, 'SET NOEXEC ON') !== false
    && strpos($sqlA, 'cashflow_prov_locales_pago_n_medios.sql') !== false);
chequear('es reejecutable', true,
    strpos($sqlA, "IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO', 'GRUPO_PAGO') IS NULL") !== false
    && strpos($sqlA, "WHERE name = 'IX_RO_T_CF_PLMED_GRUPO'") !== false);
chequear('no guarda el total del cheque: es la suma de las partes', false,
    strpos($sqlA, 'IMPORTE_GRUPO') !== false || strpos($sqlA, 'TOTAL_GRUPO') !== false);
chequear('no hay bajas fisicas', false, stripos($sqlA, 'DELETE ') !== false);
chequear('la clase lo nombra', 'sql/cashflow_prov_locales_pago_agrupado.sql', Proveedores::SCRIPT_AGRUPADO);
chequear('la deteccion mira la columna, solo leyendo', true,
    strpos($cuerpoA('tablaAgrupadoCreada'), "'GRUPO_PAGO') AS C") !== false
    && strpos($cuerpoA('tablaAgrupadoCreada'), 'ALTER') === false);
chequear('la pestaña avisa nombrando el script', true,
    strpos($cuerpoA('getAvisosConNivel'), 'self::SCRIPT_AGRUPADO') !== false);

/* ================================================================
   LA PLANILLA
   ================================================================ */
$pend = function ($cod, $n, $importe, $vto, $extra = []) {
    return array_merge([
        'COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'RAZON_SOC' => 'EMBOTELLADORA BLANCO SRL',
        'IMPORTE_PENDIENTE' => $importe, 'IMPORTE_VTO' => $importe, 'IMPUTACIONES' => 0.0,
        'FECHA_VTO' => $vto, 'FECHA_EMIS' => '2026-09-01', 'PLAZO_DIAS' => null,
        'SIN_FECHA_CARGADA' => false, 'FORMA_PAGO' => 'ECHEQ',
        'FORMA_PAGO_VIGENTE' => 'ECHEQ', 'CRONOGRAMA' => true
    ], $extra);
};

/* Diez facturas de $ 10.000 netos de OGEMBO, que vencen del 01/11 al 10/11:
   E01 es la mas vieja. Mas una de $ 50.000 para la regla por proveedor. */
$diez = [];
for ($i = 1; $i <= 10; $i++) {
    $diez[] = $pend('OGEMBO', sprintf('E%02d', $i), 10000.0, sprintf('2026-11-%02d', $i));
}
$pendientes = array_merge($diez, [$pend('OGEMBO', 'F01', 50000.0, '2026-11-20')]);

$fila = function ($linea, $n, $importe = '', $fecha = '05/12/2026', $forma = 'ECHEQ', $cod = 'OGEMBO') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => ''];
};

/** El cheque en la fila de E01 y las otras nueve con $resto ('' o '0') */
$planilla = function ($importe, $resto = '') use ($fila) {
    $r = [$fila(2, 'E01', $importe)];
    for ($i = 2; $i <= 10; $i++) {
        $r[] = $fila($i + 1, sprintf('E%02d', $i), $resto);
    }

    return $r;
};

$con5 = ['porc' => 5.0, 'error' => null, 'pagos_crono' => null, 'compensada' => true];
$cap = ['partes' => Proveedores::MAX_PARTES, 'cuotas' => true, 'n_medios' => true, 'agrupado' => true];
$sinAgr = ['partes' => Proveedores::MAX_PARTES, 'cuotas' => true, 'n_medios' => true, 'agrupado' => false];

$comparar = function ($filas, $capacidad = null, $pagos = [], $aperturas = [], $enTango = [], $pends = null)
        use ($pendientes, $hoy, $con5, $cap) {
    return Proveedores::compararImportacion($filas, ($pends === null) ? $pendientes : $pends, $pagos, $hoy,
        $aperturas, true, null, $enTango, $con5, null, ($capacidad === null) ? $cap : $capacidad);
};

$porN = function ($c) {
    $r = [];
    foreach ($c['filas'] as $f) { $r[$f['n_comp']] = $f; }
    ksort($r);

    return $r;
};

seccion('diez facturas de $ 10.000, una fila con $ 100.000 y nueve vacias');

$c = $comparar($planilla('100000'));
$f = $porN($c);

chequear('las diez se cargan', array_fill(0, 10, 'ALTA'), array_values(array_column($f, 'estado')));
chequear('cada una con su neto: ninguna con sobrepago', array_fill(0, 10, 10000.0),
    array_values(array_column($f, 'pagado')));
chequear('ni excedente', 0.0, round(array_sum(array_column($f, 'excedente')), 2));
chequear('las partes suman el cheque', 100000.0, round(array_sum(array_column($f, 'pagado')), 2));
chequear('el chip: un pago, diez facturas, el importe y las nueve vacias', [1, 10, 100000.0, 9],
    [$c['resumen']['pagos_agrupados'], $c['resumen']['facturas_agrupadas'], $c['resumen']['importe_agrupado'],
     $c['resumen']['vacias_agrupadas']]);
chequear('el resumen cuenta diez altas, una por factura', 10, $c['resumen']['altas']);
chequear('el motivo dice el cheque, cuantas facturas, los netos y lo que le toca', true,
    strpos($f['E05']['motivo'], 'Pago agrupado: echeq de $ 100.000,00 del 05/12/2026 por 10 facturas '
        . '(netos $ 100.000,00). A esta factura le tocan $ 10.000,00.') !== false);
chequear('y la vacia dice por que se la tomo', true,
    strpos($f['E05']['motivo'], 'Tomada como parte del pago agrupado de $ 100.000,00 porque ese importe '
        . 'supera el máximo de su propia factura.') !== false);
chequear('la del cheque no lo dice: no vino vacia', false,
    strpos($f['E01']['motivo'], 'Tomada como parte') !== false);
chequear('todas con el mismo identificador de grupo', ['OGEMBO|2026-12-05|ECHEQ'],
    array_values(array_unique(array_map(function ($x) { return $x['pago_agrupado']['id']; }, $f))));
chequear('y la misma marca visual', ['L2'],
    array_values(array_unique(array_map(function ($x) { return $x['pago_agrupado']['etiqueta']; }, $f))));
chequear('el aviso del resumen lo dice', true,
    strpos(implode(' ', $c['avisos']), '1 pago(s) agrupado(s)') !== false);

seccion('lo mismo con las nueve en 0');

$c = $comparar($planilla('100000', '0'));
$f = $porN($c);

chequear('las diez se cargan, ninguna con sobrepago', [array_fill(0, 10, 'ALTA'), array_fill(0, 10, 10000.0)],
    [array_values(array_column($f, 'estado')), array_values(array_column($f, 'pagado'))]);
chequear('y no hay vacias tomadas', 0, $c['resumen']['vacias_agrupadas']);

seccion('$ 103.000 con 5%: el excedente de $ 3.000 en la ultima por vencimiento');

$f = $porN($comparar($planilla('103000')));

chequear('las nueve primeras, su neto', array_fill(0, 9, 10000.0),
    array_values(array_slice(array_column($f, 'pagado'), 0, 9)));
chequear('la ultima por vencimiento (E10) lleva el excedente', ['EXCEDENTE', 13000.0, 3000.0],
    [$f['E10']['clasificacion'], $f['E10']['pagado'], $f['E10']['excedente']]);
chequear('y lo dice', true, strpos($f['E10']['motivo'], 'Incluye el excedente de $ 3.000,00') !== false);

seccion('$ 60.000: se cubren las seis mas viejas, ninguna a medias');

$c = $comparar($planilla('60000'));
$f = $porN($c);

chequear('E01 a E06 pagadas', array_fill(0, 6, 'ALTA'), array_values(array_slice(array_column($f, 'estado'), 0, 6)));
chequear('E07 a E10 no cubiertas', array_fill(0, 4, 'NO_CUBIERTA'),
    array_values(array_slice(array_column($f, 'estado'), 6)));
chequear('ninguna a medias', 0, count(array_filter($f, function ($x) { return $x['clasificacion'] === 'PARCIAL'; })));
chequear('una no cubierta lo dice', true,
    strpos($f['E08']['motivo'], 'No alcanza para esta factura') !== false
    && strpos($f['E08']['motivo'], 'Sigue como si no estuviera en la planilla') !== false);
chequear('las no cubiertas no son errores: se cuentan aparte', [0, 4],
    [$c['resumen']['errores'], $c['resumen']['no_cubiertas']]);
chequear('y no se escriben', 6, $c['resumen']['altas']);

seccion('$ 65.000: la septima queda a medias, con su saldo');

$f = $porN($comparar($planilla('65000')));

chequear('E07 parcial: $ 5.000 pagados y $ 5.000 de saldo', ['PARCIAL', 5000.0, 5000.0],
    [$f['E07']['clasificacion'], $f['E07']['pagado'], $f['E07']['saldo']]);
chequear('con la fecha del saldo en el motivo', true,
    strpos($f['E07']['motivo'], 'queda un saldo de $ 5.000,00') !== false);
chequear('E08 a E10 no cubiertas', array_fill(0, 3, 'NO_CUBIERTA'),
    array_values(array_slice(array_column($f, 'estado'), 7)));
chequear('un parcial deja la tabla de pagos sin fecha', null,
    Proveedores::camposDeImportacion([$f['E07']])['pago']['FECHA_PAGO']);

seccion('vacias con otra fecha u otra forma no se agrupan');

$c = $comparar([$fila(2, 'E01', '100000'), $fila(3, 'E02', '', '06/12/2026'),
    $fila(4, 'E03', '', '05/12/2026', 'TRANSFERENCIA'), $fila(5, 'E04', '', '05/12/2026', '')]);

chequear('ningun pago agrupado', 0, $c['resumen']['pagos_agrupados']);
chequear('ninguna fila lleva la marca', 0, count(array_filter($c['filas'], function ($x) {
    return !empty($x['pago_agrupado']); })));

seccion('un importe que cabe en su propia factura no se lleva a las vacias');

/* El caso de la planilla del 13/11/2026: una factura con excedente y otra
   por el neto, el mismo dia y con la misma forma. */
$f = $porN($comparar([$fila(2, 'E01', '10400'), $fila(3, 'E02', '')]));

chequear('E01 con excedente, E02 por el neto: como siempre', [['EXCEDENTE', 'ALTA'], ['NETO', 'ALTA']],
    [[$f['E01']['clasificacion'], $f['E01']['estado']], [$f['E02']['clasificacion'], $f['E02']['estado']]]);
chequear('y sin marca de grupo', [false, false], [!empty($f['E01']['pago_agrupado']), !empty($f['E02']['pago_agrupado'])]);

seccion('un 0 fuera de un grupo sigue siendo error');

$c = $comparar([$fila(2, 'E01', '0')]);
chequear('error, como siempre', 'ERROR', $c['filas'][0]['estado']);

$c = $comparar([$fila(2, 'E01', '0'), $fila(3, 'E02', '0')]);
chequear('dos ceros sin ninguna fila con importe tampoco son un grupo', ['ERROR', 'ERROR'],
    array_column($c['filas'], 'estado'));

seccion('sin el script: como hoy, y el 0 nombra el script');

$c = $comparar($planilla('100000', '0'), $sinAgr);
chequear('no hay pago agrupado', 0, $c['resumen']['pagos_agrupados']);
chequear('los 0 son error y nombran el script', [true, true], [
    $porN($c)['E05']['estado'] === 'ERROR',
    strpos($porN($c)['E05']['motivo'], Proveedores::SCRIPT_AGRUPADO) !== false]);

$c = $comparar($planilla('100000', '0'), ['partes' => 2]);
chequear('sin decir la capacidad, tampoco agrupa ni nombra el script', [0, false], [
    $c['resumen']['pagos_agrupados'], strpos($porN($c)['E05']['motivo'], Proveedores::SCRIPT_AGRUPADO) !== false]);

seccion('una factura del grupo ya pagada en Tango queda fuera, y el resto se evalua sin ella');

$sinE03 = array_values(array_filter($pendientes, function ($p) { return $p['N_COMP'] !== 'E03'; }));
$tango = ['OGEMBO|E03' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-10']]];

$c = $comparar($planilla('90000'), null, [], [], $tango, $sinE03);
$f = $porN($c);

chequear('E03 sigue como ya pagada, con la fecha real', 'YA_PAGADA', $f['E03']['estado']);
chequear('y dice que queda fuera del pago', true,
    strpos($f['E03']['motivo'], 'Queda fuera del pago agrupado') !== false);
chequear('las otras nueve suman $ 90.000 de netos: el cheque las cubre justo', [9, 'NETO'],
    [count(array_filter($f, function ($x) { return $x['estado'] === 'ALTA'; })), $f['E10']['clasificacion']]);
chequear('el motivo dice cuantas quedan fuera', true,
    strpos($f['E01']['motivo'], '1 factura(s) del grupo quedan fuera (líneas 4)') !== false);

seccion('la fila del cheque con un error propio: el importe sigue siendo del grupo');

$sinE01 = array_values(array_filter($pendientes, function ($p) { return $p['N_COMP'] !== 'E01'; }));
$tango1 = ['OGEMBO|E01' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-10']]];

$f = $porN($comparar($planilla('90000', '0'), null, [], [], $tango1, $sinE01));
chequear('con 0, las otras nueve se pagan con el cheque', [9, 90000.0],
    [count(array_filter($f, function ($x) { return $x['estado'] === 'ALTA'; })),
     round(array_sum(array_map(function ($x) { return $x['estado'] === 'ALTA' ? $x['pagado'] : 0; }, $f)), 2)]);

$c = $comparar($planilla('90000', ''), null, [], [], $tango1, $sinE01);
chequear('con vacias no se agrupa: no se sabe el maximo de una factura que ya no esta', 0,
    $c['resumen']['pagos_agrupados']);

seccion('el grupo entra al total del proveedor como un comprobante');

/* El cheque paga $ 95.000 por $ 100.000 de netos y F01 se paga $ 55.000 por
   $ 50.000 (10%): el proveedor paga $ 150.000 por $ 150.000. */
$c = $comparar(array_merge($planilla('95000'), [$fila(20, 'F01', '55000', '06/12/2026')]));
$f = $porN($c);

chequear('se acepta por el total', 'TOTAL', $c['proveedores'][0]['resultado']);
chequear('las diez del grupo quedan compensadas, en proporcion: ninguna en negativo', [array_fill(0, 10, 'COMPENSADA'),
    array_fill(0, 10, 9500.0)], [array_values(array_column(array_slice($f, 0, 10), 'clasificacion')),
    array_values(array_column(array_slice($f, 0, 10), 'pagado'))]);
chequear('F01 tambien', ['COMPENSADA', 55000.0], [$f['F01']['clasificacion'], $f['F01']['pagado']]);
chequear('y todo suma lo que dice la planilla', 150000.0, round(array_sum(array_column($f, 'pagado')), 2));

$c = $comparar(array_merge($planilla('120000'), [$fila(20, 'F01', '50000', '06/12/2026')]));
chequear('si el total supera el maximo, error en todo el proveedor', [true, 'ERROR'],
    [count(array_filter($c['filas'], function ($x) { return $x['estado'] !== 'ERROR'; })) === 0,
     $c['proveedores'][0]['resultado']]);

seccion('una factura con varias filas no puede estar ademas en un grupo');

// El cheque cubre justo las otras nueve: E05 no cuenta en los netos.
$c = $comparar(array_merge($planilla('90000', '0'), [$fila(30, 'E05', '5000', '10/12/2026', 'TRANSFERENCIA')]));
$e05 = array_values(array_filter($c['filas'], function ($x) { return $x['n_comp'] === 'E05'; }));

chequear('sus dos filas en error', ['ERROR', 'ERROR'], array_column($e05, 'estado'));
chequear('diciendo por que', true, strpos($e05[0]['motivo'], 'Un pago agrupado cubre facturas enteras') !== false);
chequear('y el resto del grupo se carga sin ella', 9, $c['resumen']['facturas_agrupadas']);

/* ================================================================
   LO QUE SE ESCRIBE, Y REIMPORTAR
   ================================================================ */
seccion('lo que se escribe: la parte, la marca del grupo, la fecha y la forma');

$c = $comparar($planilla('103000'));
$f = $porN($c);
$campos = Proveedores::camposDeImportacion([$f['E10']]);

chequear('la tabla de pagos, con la fecha y la forma del cheque', ['2026-12-05', 'ECHEQ'],
    [$campos['pago']['FECHA_PAGO'], $campos['pago']['FORMA_PAGO']]);
chequear('sin tocar el override de forma', false, array_key_exists('FORMA_PAGO_CRONOGRAMA', $campos['pago']));
chequear('una parte con su importe, su clasificacion y el grupo', [1, 13000.0, 'EXCEDENTE', 'OGEMBO|2026-12-05|ECHEQ', 'ECHEQ'],
    [count($campos['medios']), $campos['medios'][0]['importe'], $campos['medios'][0]['clasificacion'],
     $campos['medios'][0]['grupo'], $campos['medios'][0]['forma']]);
chequear('una NETO tambien guarda su parte: es lo que la marca como agrupada', ['NETO', 10000.0],
    [Proveedores::camposDeImportacion([$f['E01']])['medios'][0]['clasificacion'],
     Proveedores::camposDeImportacion([$f['E01']])['medios'][0]['importe']]);

$aperturas = [];
$pagosAnt = [];
foreach ($f as $n => $x) {
    $k = Proveedores::clavePago('OGEMBO', 'FAC', $n);
    $cx = Proveedores::camposDeImportacion([$x]);
    $aperturas[$k] = $cx['medios'];
    $pagosAnt[$k] = ['FECHA_PAGO' => '2026-12-05', 'FORMA_PAGO' => 'ECHEQ', 'ESTADO' => 'PREVISTO'];
}

seccion('reimportar el mismo pago es SIN_CAMBIOS');

$c = $comparar($planilla('103000'), null, $pagosAnt, $aperturas);
chequear('las diez sin cambios', array_fill(0, 10, 'SIN_CAMBIOS'), array_values(array_column($porN($c), 'estado')));
chequear('y no se deshace nada', [], $c['deshace']);

seccion('reimportar una factura del grupo reemplaza el grupo entero');

$c = $comparar([$fila(2, 'E05', '', '20/12/2026', 'TRANSFERENCIA')], null, $pagosAnt, $aperturas);

chequear('E05 cambia', 'CAMBIO', $c['filas'][0]['estado']);
chequear('las otras nueve vuelven a su vencimiento', 9, count($c['deshace']));
chequear('el motivo lo dice', true,
    strpos($c['filas'][0]['motivo'], 'Reemplaza el pago agrupado anterior (echeq del 05/12/2026)') !== false
    && strpos($c['filas'][0]['motivo'], 'FAC E01') !== false);
chequear('y el aviso', true, strpos(implode(' ', $c['avisos']), '9 factura(s) de pagos agrupados anteriores') !== false);
chequear('confirmar frena si cambio lo que se deshace', 1,
    count(Proveedores::diferenciasDeshace($c['deshace'], array_slice($c['deshace'], 1))));
chequear('y no si es lo mismo', [], Proveedores::diferenciasDeshace($c['deshace'], array_reverse($c['deshace'])));

$c = $comparar($planilla('105000'), null, $pagosAnt, $aperturas);
chequear('reimportar el grupo con otro importe no deshace a ninguna: todas siguen', [[], 'CAMBIO'],
    [$c['deshace'], $porN($c)['E10']['estado']]);

seccion('confirmar frena si cambio la parte de una factura');

$visto = $comparar($planilla('100000'));
$ahora = $comparar($planilla('100000'), null, [], [], $tango, $sinE03);
chequear('otra factura del grupo paso a estar pagada en Tango: el reparto es otro', true,
    count(Proveedores::diferenciasConLoVisto($visto['filas'], $ahora['filas'])) > 0);

/* ================================================================
   EL EJE Y EL TABLERO
   ================================================================ */
$item = function ($cod, $n, $importe, $vto, $extra = []) use ($pend, $hoy) {
    $r = Proveedores::resolverFechaPago(null, $vto, '2026-09-01', null, $hoy, null, true);

    return Proveedores::conRetencion(array_merge($pend($cod, $n, $importe, $vto), [
        'Pago' => $r['fecha'], 'ORIGEN_FECHA' => $r['origen'], 'FUENTE_FECHA' => $r['origen'],
        'FECHA_PAGO' => null, 'SIN_FECHA_CARGADA' => $r['sin_fecha_cargada'], 'VENCIDA' => $r['vencida'],
        'EXCLUIDO' => false, 'EXCLUIDA_MANUAL' => false, 'EXCLUIDO_PROVEEDOR' => false,
        'EN_MAESTRO' => true, 'SERIE' => 'SIN_RUBRO', 'APERTURA' => null, 'APERTURA_DESCUADRADA' => null,
        'PAGO_IMPORTADO' => null, 'PAGO_AGRUPADO' => null, 'EXCEDENTE' => 0.0, 'PARTE_EXTRA' => false
    ], $extra), null, $hoy);
};

$universo = [];
foreach ($diez as $p) { $universo[] = $item('OGEMBO', $p['N_COMP'], 10000.0, $p['FECHA_VTO']); }
$universo[] = $item('OGOTRO', 'K01', 50000.0, '2026-11-10', ['CRONOGRAMA' => false]);

$aperturasDe = function ($c) {
    $r = [];
    foreach ($c['filas'] as $x) {
        if (!in_array($x['estado'], ['ALTA', 'CAMBIO', 'SIN_CAMBIOS'], true)) { continue; }
        $m = Proveedores::camposDeImportacion([$x])['medios'];
        foreach ($m as $i => $mm) { $m[$i] += ['usuario' => 'jperez', 'fecha_modif' => '2026-10-13 10:00:00',
            'estado_tango' => 'PREVISTO', 'fecha_real' => null, 'pendiente_real' => null]; }
        $r[Proveedores::clavePago($x['cod_provee'], $x['t_comp'], $x['n_comp'])] = $m;
    }

    return $r;
};

$enFecha = function ($filas, $fecha) {
    return round(array_sum(array_map(function ($x) use ($fecha) {
        return $x['Pago'] === $fecha ? $x['IMPORTE_A_PAGAR'] : 0; }, $filas)), 2);
};

seccion('eje: el cheque de $ 100.000 aparece una vez, en su fecha');

$ap = $aperturasDe($comparar($planilla('100000')));
$abiertas = Proveedores::abrirEnMedios($universo, $ap, $hoy, null, null);

chequear('una fila por factura, como siempre', 11, count($abiertas));
chequear('el 05/12 suma el cheque', 100000.0, $enFecha($abiertas, '2026-12-05'));
chequear('ninguna con excedente', 0.0, round(array_sum(array_column($abiertas, 'EXCEDENTE')), 2));
chequear('cada una sabe de que pago es: importe, fecha, forma y facturas', [100000.0, '2026-12-05', 'ECHEQ', 10],
    [$abiertas[0]['PAGO_AGRUPADO']['importe'], $abiertas[0]['PAGO_AGRUPADO']['fecha'],
     $abiertas[0]['PAGO_AGRUPADO']['forma'], count($abiertas[0]['PAGO_AGRUPADO']['facturas'])]);
chequear('y quien lo importo', 'jperez', $abiertas[0]['PAGO_AGRUPADO']['usuario']);
chequear('cada factura cuenta como un vencimiento', 11,
    count(array_filter($abiertas, ['Proveedores', 'cuentaComoVencimiento'])));

seccion('eje: con centavos, la suma da el cheque exacto');

$ap = $aperturasDe($comparar($planilla('100000,40')));
$abiertas = Proveedores::abrirEnMedios($universo, $ap, $hoy, null, null);

chequear('el 05/12 suma $ 100.000,40, al centavo', 100000.4, $enFecha($abiertas, '2026-12-05'));
chequear('los 40 centavos van en la ultima, como excedente con signo', 0.4,
    round(array_sum(array_column($abiertas, 'EXCEDENTE')), 2));

seccion('eje: $ 103.000, el excedente en la ultima; $ 65.000, el saldo con la jerarquia');

$abiertas = Proveedores::abrirEnMedios($universo, $aperturasDe($comparar($planilla('103000'))), $hoy, null, null);
chequear('el 05/12 suma el cheque', 103000.0, $enFecha($abiertas, '2026-12-05'));

$abiertas = Proveedores::abrirEnMedios($universo, $aperturasDe($comparar($planilla('65000'))), $hoy, null, null);
chequear('el 05/12 suma el cheque', 65000.0, $enFecha($abiertas, '2026-12-05'));
chequear('el saldo de E07 en su vencimiento, y E08 a E10 como si nada', 35000.0,
    round(array_sum(array_map(function ($x) { return $x['Pago'] !== '2026-12-05' && $x['COD_PROVEE'] === 'OGEMBO'
        ? $x['IMPORTE_A_PAGAR'] : 0; }, $abiertas)), 2));

seccion('el tablero: la pestaña y el tablero suman lo mismo, y las particiones cierran');

$hTab = new Horizonte(90, 3, [], new DateTime($hoy));
$repartir = new ReflectionMethod('ProveedoresProvider', 'repartir');
$repartir->setAccessible(true);
$provTab = new ProveedoresProvider('PROV_LOCALES');
$total = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'], 2); };

$filasTab = Proveedores::abrirEnMedios($universo, $aperturasDe($comparar($planilla('103000'))), $hoy, null, null);
$series = $repartir->invoke($provTab, $hTab, $filasTab);

chequear('por como se paga', $total($series['PAGOS_TODO']),
    round($total($series['PAGOS']) + $total($series['PAGOS_FUERA_CRONOGRAMA'])
        + $total($series['PAGOS_EXCLUIDOS_FACTURA']) + $total($series['PAGOS_EXCLUIDOS_PROVEEDOR']), 2));
chequear('por que rubro es', $total($series['PAGOS_TODO']),
    round($total($series['PAGOS_OPERATIVOS']) + $total($series['PAGOS_EXCLUIDOS']), 2));
chequear('la pestaña y el tablero suman lo mismo', round(array_sum(array_column($filasTab, 'IMPORTE_A_PAGAR')), 2),
    $total($series['PAGOS_TODO']));
chequear('el dia del cheque, el cheque', 103000.0, round($series['PAGOS']['dias']['2026-12-05'], 2));

$aud = $series['PAGOS_TODO']['auditoria'];
chequear('la auditoria: bruto + excedente = lo que suma la serie', round($aud['bruto'] + $aud['excedente'], 2),
    $total($series['PAGOS_TODO']));

seccion('el tablero: con la planilla vacia no se mueve un peso');

chequear('las series son identicas', $repartir->invoke($provTab, $hTab, $universo),
    $repartir->invoke($provTab, $hTab, Proveedores::abrirEnMedios($universo, [], $hoy, null, null)));

seccion('Tarjetas Pagos Corporativos y Alquileres no cambian');

$fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
chequear('el reparto sigue siendo solo con el horizonte', true,
    strpos($fuente, "if (\$h !== null) {\n            \$items = self::abrirEnMedios(") !== false);
chequear('y el pago agrupado vive adentro del reparto', true,
    strpos($cuerpoA('abrirEnMedios'), 'self::gruposDeAperturas($aperturas)') !== false);
chequear('IMPORTE_PENDIENTE sigue siendo el de Tango: el reparto no lo agranda', 100000.0,
    round(array_sum(array_map(function ($x) { return $x['COD_PROVEE'] === 'OGEMBO' ? $x['IMPORTE_PENDIENTE'] : 0; },
        Proveedores::abrirEnMedios($universo, $aperturasDe($comparar($planilla('103000'))), $hoy, null, null))), 2));

/* ================================================================
   LO QUE TOCA LA BASE
   ================================================================ */
seccion('lo que toca la base');

chequear('guardarApertura escribe la marca del grupo, y no sin el script', [true, true],
    [strpos($cuerpoA('guardarApertura'), "\$cols .= ', GRUPO_PAGO';") !== false,
     strpos($cuerpoA('guardarApertura'), 'self::SCRIPT_AGRUPADO') !== false]);
chequear('volver al vencimiento deshace el pago agrupado entero, en la transaccion', true,
    strpos($cuerpoA('deletePago'), '$this->facturasDelGrupo($cid, $clave)') !== false
    && strpos($cuerpoA('deletePago'), 'sqlsrv_begin_transaction') < strpos($cuerpoA('deletePago'), 'facturasDelGrupo'));
chequear('leyendo el grupo sin un JOIN de la tabla consigo misma', false,
    preg_match('/JOIN\s+dbo\./i', $cuerpoA('facturasDelGrupo')) === 1);
chequear('confirmar deshace los grupos anteriores en la misma transaccion', true,
    strpos($cuerpoA('aplicarImportacion'), "foreach (\$actual['deshace'] as \$d)") !== false
    && strpos($cuerpoA('aplicarImportacion'), "foreach (\$actual['deshace'] as \$d)")
        < strpos($cuerpoA('aplicarImportacion'), 'sqlsrv_commit'));
chequear('y compara lo que se deshace con lo que se vio, antes de abrir nada', true,
    strpos($cuerpoA('aplicarImportacion'), 'self::diferenciasDeshace(')
        < strpos($cuerpoA('aplicarImportacion'), 'sqlsrv_begin_transaction'));
chequear('sin bajas fisicas en la tabla de medios', false,
    stripos($cuerpoA('guardarApertura'), 'DELETE') !== false);
