<?php
/**
 * Un resumen para varias tarjetas: las adicionales de una misma cuenta.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL RESUMEN SUMA UNA VEZ POR TARJETA. Cargado en una supervisora y una
 *      corporativa del mismo banco, entraria en SUPERVISORAS y en CORPORATIVAS:
 *      la fila del tablero lo contaria dos veces y cerraria igual.
 *
 *   2. EL RESUMEN NO PISA LA ESTIMACION DE LAS ADICIONALES. La titular lo
 *      proyecta, y las otras siguen proyectando su estimacion arriba de un
 *      resumen que ya las incluye.
 *
 *   3. SE MEZCLAN TARJETAS DE DOS CUENTAS. Un resumen con tarjetas de bancos
 *      distintos, o que vencen otro dia, pisaria la estimacion de una tarjeta
 *      con el importe de otra.
 *
 * Todo lo de aca es PURO.
 */

require_once __DIR__ . '/../Class/TarjetasResumen.php';
require_once __DIR__ . '/../Class/TarjetasSupervisoras.php';
require_once __DIR__ . '/../Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../Class/Providers/TarjetasProvider.php';
require_once __DIR__ . '/../Class/Horizonte.php';

function tarjetaRC($id, $tipo, $banco = '025', $dia = 5, $activa = true, $pct = 0.0) {
    return ['ID' => $id, 'TIPO' => $tipo, 'TIPO_NOMBRE' => ucfirst(strtolower($tipo)),
            'COD_BANCO' => $banco, 'DIA_VENCIMIENTO' => $dia, 'ACTIVA' => $activa,
            'PCT_COBERTURA' => $pct, 'ROTULO' => '•••• ' . $id . ' · banco ' . $banco];
}

/** Que lance, y que el mensaje diga lo que tiene que decir */
function lanzaConRC($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

$T = [
    1 => tarjetaRC(1, 'SUPERVISORA'),
    2 => tarjetaRC(2, 'SUPERVISORA'),
    7 => tarjetaRC(7, 'CORPORATIVA', '025', 5, true, 10.0),
    8 => tarjetaRC(8, 'CORPORATIVA', '015'),
    9 => tarjetaRC(9, 'CORPORATIVA', '025', 12),
    10 => tarjetaRC(10, 'SOCIO'),
    11 => tarjetaRC(11, 'CORPORATIVA', '025', 5, false)
];

/* ================================================================
   QUE TARJETAS PUEDEN IR JUNTAS
   ================================================================ */
seccion('un resumen compartido: mismo banco y mismo dia');

chequear('una sola tarjeta es su titular, de cualquier tipo',
    ['titular' => 10, 'adicionales' => []], TarjetasResumen::validarTarjetas([10], null, $T));

chequear('supervisora y corporativa del mismo banco y dia, mezcladas',
    ['titular' => 7, 'adicionales' => [1, 2]],
    TarjetasResumen::validarTarjetas([1, 7, 2], 7, $T));

chequear('una tarjeta repetida cuenta una vez',
    ['titular' => 1, 'adicionales' => [7]], TarjetasResumen::validarTarjetas([1, 7, 7], 1, $T));

lanzaConRC('distinto banco: rechazado', function () use ($T) {
    TarjetasResumen::validarTarjetas([7, 8], 7, $T);
}, 'mismo banco');

lanzaConRC('distinto dia de vencimiento: rechazado', function () use ($T) {
    TarjetasResumen::validarTarjetas([7, 9], 7, $T);
}, 'mismo día');

lanzaConRC('sin titular entre las elegidas: rechazado', function () use ($T) {
    TarjetasResumen::validarTarjetas([1, 7], 2, $T);
}, 'titular');

lanzaConRC('un socio no comparte resumen', function () use ($T) {
    TarjetasResumen::validarTarjetas([1, 10], 1, $T);
}, 'Supervisora y Corporativa');

lanzaConRC('una tarjeta de baja no entra a un compartido', function () use ($T) {
    TarjetasResumen::validarTarjetas([7, 11], 7, $T);
}, 'dada de baja');

lanzaConRC('una tarjeta que no existe', function () use ($T) {
    TarjetasResumen::validarTarjetas([7, 99], 7, $T);
}, 'no está cargada');

lanzaConRC('ninguna tarjeta', function () use ($T) {
    TarjetasResumen::validarTarjetas([], null, $T);
}, 'al menos una');

/* ================================================================
   EL MISMO RESUMEN EN CADA TARJETA, TITULAR UNA SOLA
   ================================================================ */
seccion('el resumen aparece en todas las tarjetas que incluye');

$resumen = ['ID' => 50, 'ID_TARJETA' => 7, 'MES' => '2026-11', 'IMPORTE_ARS' => 900000.0,
            'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2026-11-05', 'PAGADO' => false];

$mapa = TarjetasResumen::conCompartidos([7 => ['2026-11' => $resumen]], [50 => [1, 2]]);

chequear('en la titular', true, $mapa[7]['2026-11']['ES_TITULAR']);
chequear('y en las dos adicionales, sin ser titular', [false, false],
    [$mapa[1]['2026-11']['ES_TITULAR'], $mapa[2]['2026-11']['ES_TITULAR']]);
chequear('es el mismo resumen', [50, 50, 50],
    [$mapa[7]['2026-11']['ID'], $mapa[1]['2026-11']['ID'], $mapa[2]['2026-11']['ID']]);
chequear('con sus tres tarjetas, la titular primero', [7, 1, 2], $mapa[1]['2026-11']['TARJETAS']);
chequear('marcado compartido', true, $mapa[2]['2026-11']['COMPARTIDO']);

$solo = TarjetasResumen::conCompartidos([7 => ['2026-11' => $resumen]], []);

chequear('uno sin adicionales no es compartido', false, $solo[7]['2026-11']['COMPARTIDO']);
chequear('y es su titular', true, $solo[7]['2026-11']['ES_TITULAR']);

chequear('ocupados: las tres tarjetas apuntan al mismo resumen', [50 => [7, 1]],
    TarjetasResumen::ocupados([7, 1], '2026-11', $mapa));
chequear('ocupados: otro mes no esta ocupado', [],
    TarjetasResumen::ocupados([7, 1], '2026-12', $mapa));

$nota = TarjetasResumen::notaCompartido($mapa[1]['2026-11'], 1, $T);

chequear('la nota dice con quien se comparte', true,
    strpos($nota, 'Resumen compartido con: •••• 7') === 0);
chequear('y donde se proyecta: en la sub-pestana de la titular', true,
    strpos($nota, 'Se proyecta una sola vez, en Tarjetas Pagos Corporativos') !== false);

/* ================================================================
   PISA LA ESTIMACION DE TODAS, SUMA EN LA TITULAR
   ================================================================ */
seccion('pisa la estimacion de todas las tarjetas incluidas');

$HOY = '2026-10-31';
$vto = ['fecha' => '2026-11-05', 'mes' => '2026-11', 'faltan' => []];

$sup = TarjetasSupervisoras::tarjetaDelMes(400000.0, $T[1], $vto, $mapa[1]['2026-11'], $HOY);

chequear('supervisora adicional: no proyecta la estimacion ni el resumen', false, $sup['proyecta']);
chequear('se marca como compartido', 'RESUMEN_COMPARTIDO', $sup['origen']);
chequear('y muestra el importe del resumen, informativo', 900000.0, $sup['importe']);

$supTit = TarjetasSupervisoras::tarjetaDelMes(400000.0, $T[1], $vto,
    TarjetasResumen::conCompartidos([1 => ['2026-11' => array_merge($resumen, ['ID_TARJETA' => 1])]],
        [50 => [7]])[1]['2026-11'], $HOY);

chequear('supervisora titular: proyecta el resumen entero', [true, 900000.0, 'RESUMEN'],
    [$supTit['proyecta'], $supTit['importe'], $supTit['origen']]);

$res = TarjetasCorporativas::resumenesAProyectar([7 => $T[7]], $mapa, ['2026-11'], $HOY);

chequear('corporativa titular: el resumen entra', true, $res[0]['proyecta']);

$mapaSup = TarjetasResumen::conCompartidos(
    [1 => ['2026-11' => array_merge($resumen, ['ID_TARJETA' => 1])]], [50 => [7]]);
$resAd = TarjetasCorporativas::resumenesAProyectar([7 => $T[7]], $mapaSup, ['2026-11'], $HOY);

chequear('corporativa adicional: se lista pero no entra', [1, false],
    [count($resAd), $resAd[0]['proyecta']]);
chequear('y dice por que', true, strpos($resAd[0]['motivo'], 'compartido') !== false);

/* Las facturas vinculadas a la corporativa adicional quedan cubiertas: el resumen
   de la titular ya las incluye. */
$fact = [['COD_PROVEE' => 'OGAAA', 'RAZON_SOC' => 'A', 'T_COMP' => 'FAC', 'N_COMP' => 'A1',
          'FECHA_VTO' => '2026-11-03', 'IMPORTE_PENDIENTE' => 50000,
          'FORMA_PAGO_VIGENTE' => 'TARJETA CORP']];
$claveF = TarjetasCorporativas::clave($fact[0]);
$habiles = [];

for ($d = new DateTime('2026-10-01'); $d <= new DateTime('2026-12-31'); $d->modify('+1 day')) {
    $habiles[$d->format('Y-m-d')] = intval($d->format('N')) <= 5;
}

$r = TarjetasCorporativas::resolver($fact, [$claveF => 7], [], [7 => $T[7]], $mapaSup, $habiles,
    ['2026-11'], $HOY);

chequear('la factura de la adicional queda cubierta por el resumen compartido',
    TarjetasCorporativas::CUBIERTA, $r['filas'][0]['MOTIVO']);
chequear('y sin cobertura aparte: el resumen la reemplaza', [],
    TarjetasCorporativas::cobertura($r['filas'], [7 => $T[7]], $mapaSup, $habiles, ['2026-11'], $HOY));

/* ================================================================
   EL INVARIANTE, CON EL COMPARTIDO ADENTRO
   ================================================================ */
seccion('en el tablero suma una sola vez, y el invariante se cumple');

class PagosTarjetasDobleRC {
    public $datos;
    function __construct($d) { $this->datos = $d; }
    public function calcular($h) { return $this->datos; }
}

class TarjetasProviderDobleRC extends TarjetasProvider {
    public $doble;
    protected function modulo() { return $this->doble; }
}

/* La supervisora adicional no lleva pago de tarjeta (no proyecta); la corporativa
   titular lleva el resumen. Es exactamente lo que arma PagosTarjetas con los dos
   resultados de arriba. */
$pagosSup = [];

if ($sup['proyecta']) {
    $pagosSup[] = ['parte' => 'TARJETA', 'supervisora' => 'ANA', 'mes' => '2026-11',
                   'fecha' => $sup['fecha'], 'importe' => $sup['importe'], 'origen' => $sup['origen']];
}

$pagosCorp = [];

foreach ($res as $x) {
    if ($x['proyecta']) {
        $pagosCorp[] = ['tipo' => 'RESUMEN', 'fecha' => $x['fecha'], 'importe' => $x['importe'],
                        'id_tarjeta' => $x['id_tarjeta'], 'mes' => $x['mes']];
    }
}

$datosRC = [
    'hoy' => $HOY, 'meses' => ['2026-10', '2026-11'],
    'tablas' => ['tarjetas' => true, 'resumen' => true, 'factura' => true, 'exclusion' => true,
                 'gastos' => true, 'vista_usuarios' => true],
    'tarjetas' => [], 'avisos' => [],
    'supervisoras' => ['pagos' => $pagosSup, 'avisos' => [], 'filas' => [], 'sin_tarjeta' => 0.0],
    'corporativas' => ['pagos' => $pagosCorp, 'pagos_excluidos' => [], 'avisos' => [],
                       'filas' => [], 'cobertura' => [], 'resumenes' => $res],
    'socios' => ['pagos' => [], 'avisos' => [], 'filas' => [], 'cartel' => '']
];

$prov = new TarjetasProviderDobleRC('TARJETAS');
$prov->doble = new PagosTarjetasDobleRC($datosRC);
$s = $prov->series(new Horizonte(28, 12, [], new DateTime($HOY)));

$malas = [];

foreach (['dias', 'meses'] as $rama) {
    foreach ($s['TOTAL'][$rama] as $k => $v) {
        if (abs($v - ($s['SUPERVISORAS'][$rama][$k] + $s['CORPORATIVAS'][$rama][$k]
                      + $s['SOCIOS'][$rama][$k])) > 0.001) {
            $malas[] = $rama . '|' . $k;
        }
    }
}

$total = array_sum($s['TOTAL']['dias']) + array_sum($s['TOTAL']['meses']);

chequear('el invariante se cumple en todas las columnas', [], $malas);
chequear('el resumen de 900.000 suma una sola vez', 900000.0, $total);
chequear('en la serie de la titular', 900000.0,
    array_sum($s['CORPORATIVAS']['dias']) + array_sum($s['CORPORATIVAS']['meses']));
chequear('y nada en la otra', 0.0,
    (float) (array_sum($s['SUPERVISORAS']['dias']) + array_sum($s['SUPERVISORAS']['meses'])));

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script del compartido');

$sqlRC = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_resumen_compartido.sql');

chequear('crea la tabla dentro de una guarda', true,
    strpos($sqlRC, "OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA', 'U') IS NULL") !== false);
chequear('una tarjeta, un resumen vigente por mes', true,
    strpos($sqlRC, 'ON dbo.RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA (ID_TARJETA, MES)') !== false);

foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
    chequear("no tiene $prohibido", false, stripos($sqlRC, $prohibido) !== false);
}

foreach (['USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF', 'USUARIO_BAJA', 'FECHA_BAJA'] as $c) {
    chequear("lleva $c", true, strpos($sqlRC, $c) !== false);
}
