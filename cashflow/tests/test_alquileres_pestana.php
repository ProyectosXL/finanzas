<?php
/**
 * La pestaña Alquileres de punta a punta (Alquileres::calcular() y
 * armarPestana()), con la app y los parametros reemplazados por dobles.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA PESTAÑA DICE UN TOTAL Y LOS PAGOS OTRO. La grilla agrupa la lista de
 *      pagos de AlquileresPago; si sumara por su cuenta, el dia que cambie una
 *      regla diria otra cosa que el tablero.
 *
 *   2. UN DIFERIDO SE PIERDE EN EL BORDE DEL HORIZONTE. Con IRSA (2 meses), la
 *      venta de septiembre -antes del horizonte, y cerrada en la app- se paga
 *      en noviembre.
 *
 *   3. UN LOCAL INCOMPLETO, O CERRADO EN LAKERS, PROYECTA.
 *
 *   4. EL PIE DE VENTAS NO CIERRA CONTRA EL CANAL.
 *
 * No toca la base.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Alquileres.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Menu.php';

/** 12 meses de venta neta, de oct-25 a sep-26 */
function ventana12($importe) {
    $v = [];
    for ($m = '2025-10'; $m <= '2026-09'; $m = Inflacion::mesMas($m, 1)) {
        $v[$m] = (float) $importe;
    }
    return $v;
}

class PestAppDoble extends AlquileresApp {
    public function tablasFaltantes() { return []; }
    public function locales() {
        return [
            3 => ['nro' => 3, 'nombre' => 'ALTO PALERMO', 'habilitado' => true],    // IRSA, difiere 2
            2 => ['nro' => 2, 'nombre' => 'UNICENTER', 'habilitado' => true],       // Cencosud, extraordinario
            10 => ['nro' => 10, 'nombre' => 'SOLAR', 'habilitado' => true],         // incompleto
            40 => ['nro' => 40, 'nombre' => 'FLORES 1', 'habilitado' => false]      // cerrado en Lakers
        ];
    }
    public function mesesCerrados() {
        $m = [];
        for ($x = '2024-10'; $x <= '2026-09'; $x = Inflacion::mesMas($x, 1)) { $m[] = $x; }
        return $m;
    }
    public function detalleCerrados($cerrados, $desde) {
        $d = [];
        foreach ($cerrados as $m) {
            if ($m < $desde) { continue; }
            $d[3][$m] = [8 => 1000000.0, 9 => 150000.0, 11 => 400000.0, 6 => 0.0];
            $d[2][$m] = [8 => 2000000.0, 9 => 200000.0, 11 => 300000.0, 7 => 0.0];
            $d[10][$m] = [8 => 500000.0, 11 => 100000.0];
            $d[40][$m] = [1 => 900000.0, 11 => 50000.0];
        }
        $d[3]['2026-09'][6] = 77777.0;   // el 6 de septiembre, cerrado en la app
        return $d;
    }
    public function porcentajes() { return [3 => [6 => 7.0, 9 => 15.0], 2 => [7 => 8.0, 9 => 10.0]]; }
    public function ventasNetas($desde) {
        return [3 => ventana12(600), 2 => ventana12(300), 10 => ventana12(100), 40 => ventana12(1000)]
            + [];
    }
    public function ventasBrutas($desde) { return []; }
}

class PestParDoble extends AlquileresParametros {
    public function tablasFaltantes() { return []; }
    public function general() { return ['dia' => 10, 'usuario' => 'x', 'fecha' => null]; }
    public function razonesVigentes() {
        return ['SAPALA' => ['cod' => 'SAPALA', 'razon' => 'IRSA', 'dia' => null, 'diferimiento' => 2],
                'SAUNIC' => ['cod' => 'SAUNIC', 'razon' => 'CENCOSUD', 'dia' => 15, 'diferimiento' => 0]];
    }
    public function razones($soloVigentes = true) { return []; }
    public function extras() {
        return [['id' => 9, 'cod_provee' => 'SAUNIC', 'todos' => true, 'locales' => [], 'conceptos' => [8],
                 'mes_base' => 12, 'pct' => 75.0, 'diferimiento' => 1]];
    }
    public function locales() {
        $base = ['frecuencia' => 1, 'mes_ajuste' => null, 'pct_iva' => 21.0, 'pct_percepcion' => 3.0,
                 'pct_retencion' => 2.0, 'fecha_fin' => null, 'venta_mensual_estimada' => null];
        return [3 => ['cod_provee' => 'SAPALA'] + $base, 2 => ['cod_provee' => 'SAUNIC'] + $base,
                10 => ['cod_provee' => 'SAPALA', 'pct_iva' => null] + $base];
    }
}

class PestAlquileres extends Alquileres {
    protected function universoRazones(&$avisos) {
        return ['SAPALA' => ['cod' => 'SAPALA', 'nombre' => 'IRSA', 'rubro_economico' => null],
                'SAUNIC' => ['cod' => 'SAUNIC', 'nombre' => 'CENCOSUD', 'rubro_economico' => null]];
    }
    protected function ivaGeneral() { return 21.0; }
}

$h = new Horizonte(0, 4, [], new DateTime('2026-11-10'));   // DateTime: con un string, Horizonte toma el reloj real y la prueba caduca
$meses = array_column($h->meses(), 'clave');
$habiles = [];
for ($d = '2026-08-01'; $d <= '2027-04-30'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $habiles[$d] = intval(date('N', strtotime($d))) <= 5;
}
$inflacion = array_fill_keys(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], 1.0);
$canal = array_fill_keys($meses, 1210000.0);
$ins = ['inflacion' => $inflacion, 'canal' => $canal, 'iva' => 0.21, 'habiles' => $habiles, 'avisos' => []];

$alq = new PestAlquileres('2026-11-10', new PestAppDoble(), new PestParDoble());
$c = $alq->calcular($h, $ins);
$p = Alquileres::armarPestana($c, $h);

/** Los pagos de un local y concepto, por mes de pago */
function pagosDe($c, $nro, $concepto) {
    $out = [];
    foreach ($c['pagos'] as $x) {
        if ($x['nro'] === $nro && (string) $x['concepto'] === (string) $concepto) {
            $out[$x['mes_pago']] = $x;
        }
    }
    return $out;
}

seccion('Alquileres: el horizonte y los meses de concepto');

chequear('el horizonte arranca en el mes en curso', '2026-11', $meses[0]);
chequear('con IRSA en 2 meses, se calculan sep y oct', ['2026-09', '2026-10'], array_slice($c['meses_concepto'], 0, 2));

seccion('Alquileres: el diferido de IRSA');

$c6 = pagosDe($c, 3, 6);
chequear('en noviembre se paga el 6 de septiembre, cerrado en la app',
    [77777.0, '2026-09', AlquileresCalculo::APP_CERRADA],
    [$c6['2026-11']['neto'], $c6['2026-11']['mes_concepto'], $c6['2026-11']['origen']]);
chequear('en enero, el de noviembre (estimado)', ['2026-11', AlquileresCalculo::ESTIMADO],
    [$c6['2027-01']['mes_concepto'], $c6['2027-01']['origen']]);
chequear('el VMA de noviembre, en noviembre, ajustado dos meses (oct y nov)', round(1000000 * 1.01 * 1.01, 2),
    pagosDe($c, 3, 8)['2026-11']['neto']);
chequear('el dia general (10/11) ya paso: el pago va a hoy, que es habil', '2026-11-10',
    pagosDe($c, 3, 8)['2026-11']['fecha']);

seccion('Alquileres: el extraordinario de Cencosud');

$ex = pagosDe($c, 2, 'EXTRA:9');
chequear('cae en enero', ['2027-01'], array_keys($ex));
chequear('75 % del VMA de diciembre proyectado', round(0.75 * 2000000 * pow(1.01, 3), 2), $ex['2027-01']['neto']);
chequear('el dia de la razon social (15/01 es viernes)', '2027-01-15', $ex['2027-01']['fecha']);

seccion('Alquileres: quien no proyecta');

$nros = array_values(array_unique(array_column($c['pagos'], 'nro')));
sort($nros);
chequear('solo proyectan los completos', [2, 3], $nros);
$estados = array_column($p['locales'], 'estado', 'nro');
chequear('Solar, sin IVA, es incompleto', AlquileresPago::INCOMPLETO, $estados[10]);
chequear('Flores 1 es cerrado en Lakers', AlquileresPago::CERRADO_LAKERS, $estados[40]);
chequear('las tarjetas: 2 proyectan, 1 incompleto', [2, 1],
    [$p['kpis']['locales_proyectan'], $p['kpis']['locales_incompletos']]);

seccion('Alquileres: la pestaña dice lo mismo que la lista de pagos');

$suma = round(array_sum(array_column($c['pagos'], 'a_pagar')), 2);
$sumaFilas = round(array_sum(array_map(function ($l) { return $l['total']['a_pagar']; }, $p['locales'])), 2);
chequear('la tarjeta del horizonte es la suma de los pagos', $suma, $p['kpis']['total_horizonte']);
chequear('y la suma de los renglones de los locales', $suma, $sumaFilas);
$mesActual = round(array_sum(array_map(function ($x) { return $x['mes_pago'] === '2026-11' ? $x['a_pagar'] : 0; }, $c['pagos'])), 2);
chequear('la del mes en curso, la de noviembre', $mesActual, $p['kpis']['total_mes_actual']);

$unicenter = array_values(array_filter($p['locales'], function ($l) { return $l['nro'] === 2; }))[0];
chequear('los grupos en su orden', ['FIJOS', 'FONDO', 'EXPENSAS', 'VARIABLES', 'EXTRA'],
    array_column($unicenter['grupos'], 'grupo'));
$fijos = $unicenter['grupos'][0];
chequear('un fijo en 0 todo el horizonte no se muestra (solo el VMA)', ['8'], array_column($fijos['conceptos'], 'id'));
chequear('la celda de un concepto trae el detalle del pago', true,
    isset($fijos['conceptos'][0]['meses']['2026-11']['pagos'][0]['fecha']));
chequear('el renglon del local muestra el a pagar, el grupo el neto', true,
    $unicenter['meses']['2026-11']['a_pagar'] > $fijos['meses']['2026-11']['neto']);

seccion('Alquileres: la grilla por razon social');

$porCod = array_column($p['razones'], null, 'cod');
chequear('una razon social por renglon, ordenadas por nombre, "Sin razon social" al final',
    ['CENCOSUD', 'IRSA'], array_column(array_filter($p['razones'], function ($r) { return !$r['sin_razon']; }), 'nombre'));
chequear('bajo IRSA, sus locales: Alto Palermo y Solar (incompleto, con su estado)', [3, 10],
    array_column($porCod['SAPALA']['locales'], 'nro'));
chequear('Flores 1, sin fila en Parametros, va a "Sin razon social"', [40],
    array_column(array_values(array_filter($p['razones'], function ($r) { return $r['sin_razon']; }))[0]['locales'], 'nro'));
$sumaRazones = round(array_sum(array_map(function ($r) { return $r['total']['a_pagar']; }, $p['razones'])), 2);
chequear('las razones suman el total de la pestaña al centavo', $p['kpis']['total_horizonte'], $sumaRazones);
chequear('y la razon, lo de sus locales', $porCod['SAPALA']['total']['a_pagar'],
    round(array_sum(array_map(function ($l) { return $l['total']['a_pagar']; }, $porCod['SAPALA']['locales'])), 2));

/* SAN JUSTO: SASANJ es la principal; SAUCCS factura las expensas (11) y el
   6, con su propio dia (20) y diferimiento (1). */
class PestAppSJ extends PestAppDoble {
    public function locales() { return parent::locales() + [80 => ['nro' => 80, 'nombre' => 'SAN JUSTO', 'habilitado' => true]]; }
    public function detalleCerrados($cerrados, $desde) {
        $d = parent::detalleCerrados($cerrados, $desde);
        foreach ($cerrados as $m) {
            if ($m >= $desde) { $d[80][$m] = [8 => 2741029.0, 9 => 328923.0, 11 => 3323532.0, 6 => 100.0]; }
        }
        return $d;
    }
    public function porcentajes() { return parent::porcentajes() + [80 => [6 => 6.5, 9 => 12.0]]; }
    public function ventasNetas($desde) { return parent::ventasNetas($desde) + [80 => ventana12(200)]; }
}

class PestParSJ extends PestParDoble {
    public function razonesVigentes() {
        return parent::razonesVigentes() + [
            'SASANJ' => ['cod' => 'SASANJ', 'razon' => 'CONTINENTAL URBANA', 'dia' => null, 'diferimiento' => 1],
            'SAUCCS' => ['cod' => 'SAUCCS', 'razon' => 'URBANA CENTROS COMERCIALES', 'dia' => 20, 'diferimiento' => 2]];
    }
    public function locales() {
        $l = parent::locales();
        $l[80] = ['cod_provee' => 'SASANJ', 'adicionales' => ['SAUCCS'], 'conceptos_razon' => ['SAUCCS' => [6, 11]]] + $l[3];
        return $l;
    }
}

$cSJ = (new PestAlquileres('2026-11-10', new PestAppSJ(), new PestParSJ()))->calcular($h, $ins);
$pSJ = Alquileres::armarPestana($cSJ, $h);
$rSJ = array_column($pSJ['razones'], null, 'cod');
$conceptosBajo = function ($cod) use ($rSJ) {
    $ids = [];
    foreach ($rSJ[$cod]['locales'] as $l) {
        if ($l['nro'] !== 80) { continue; }
        foreach ($l['grupos'] as $g) { $ids = array_merge($ids, array_column($g['conceptos'], 'id')); }
    }
    sort($ids);
    return $ids;
};
chequear('San Justo aparece bajo las dos razones sociales', [true, true],
    [in_array(80, array_column($rSJ['SASANJ']['locales'], 'nro'), true),
     in_array(80, array_column($rSJ['SAUCCS']['locales'], 'nro'), true)]);
chequear('bajo SASANJ, el VMA y el FPC s/VMA', ['8', '9'], $conceptosBajo('SASANJ'));
chequear('bajo SAUCCS, lo que se le marco: el 6 y las expensas', ['6', '11'], $conceptosBajo('SAUCCS'));
$exp = pagosDe($cSJ, 80, 11)['2026-11'];
chequear('las expensas de SAUCCS vencen su dia (20/11)', ['SAUCCS', '2026-11-20'], [$exp['cod_provee'], $exp['fecha']]);
chequear('y el VMA, el general (10/11, que es hoy)', ['SASANJ', '2026-11-10'],
    [pagosDe($cSJ, 80, 8)['2026-11']['cod_provee'], pagosDe($cSJ, 80, 8)['2026-11']['fecha']]);
chequear('el 6 se difiere con SAUCCS (2): en noviembre se paga el de septiembre', '2026-09',
    pagosDe($cSJ, 80, 6)['2026-11']['mes_concepto']);
chequear('la suma por razon sigue siendo el total', $pSJ['kpis']['total_horizonte'],
    round(array_sum(array_map(function ($r) { return $r['total']['a_pagar']; }, $pSJ['razones'])), 2));

seccion('Alquileres: la venta y su pie');

$pie = $p['ventas']['pie'];
chequear('en los meses estimados la diferencia contra el canal es 0', [0.0, 0.0, 0.0, 0.0],
    array_map(function ($m) use ($pie) { return $pie[$m]['diferencia']; }, $meses));
chequear('antes del horizonte no hay canal con que comparar', null, $pie['2026-09']['canal']);
$part = $c['ventas']['participacion'];
chequear('Flores 1, cerrado en Lakers, no entra al denominador', false, isset($part[40]));
chequear('Solar, incompleto pero abierto, si', true, isset($part[10]));

seccion('Alquileres: la pestaña esta cableada');

$tab = file_get_contents(__DIR__ . '/../Tabs/alquileres.php');
chequear('ya no es un placeholder', false, strpos($tab, 'tab_placeholder') !== false);
chequear('Facturas ya no es "la proxima entrega": tiene su grilla', [false, true],
    [strpos($tab, 'Disponible en la próxima entrega') !== false, strpos($tab, 'id="tablaAlqFact"') !== false]);
chequear('la raiz lleva el permiso de la pestaña', true, strpos($tab, "AuthCashflow::atributoEdicion('alquileres')") !== false);
chequear('las tres sub-pestañas', 3, substr_count($tab, 'data-bs-toggle="tab"'));
$estadoMenu = null;
foreach (Menu::estructuraCompleta()['categorias'] as $cat) {
    foreach ($cat['items'] as $t) {
        if ($t['tab'] === 'alquileres') {
            $estadoMenu = $t['estado'];
        }
    }
}
chequear('en el menu esta en DATOS', Menu::DATOS, $estadoMenu);
chequear('getProyeccion es una lectura', true, in_array('getProyeccion', AuthCashflow::LECTURAS['Alquileres'], true));
$ctrl = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
chequear('y pide ver la pestaña', true, (bool) preg_match("/case 'getProyeccion':\s*autorizarLectura\('alquileres'\);/", $ctrl));

seccion('El tablero: REAL, PROYECTADO y PAGOS, de la misma lista');

require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';
require_once __DIR__ . '/../Class/Providers/AlquileresProvider.php';

class PestAlquileresConInsumos extends PestAlquileres {
    public $ins;
    public function insumos($h) { return $this->ins; }
}

class PestProveedor extends AlquileresProvider {
    public $alq;
    protected function modulo($h) { return $this->alq; }
}

$hDias = new Horizonte(28, 4, [], new DateTime('2026-11-10'));
$alqDias = new PestAlquileresConInsumos('2026-11-10', new PestAppDoble(), new PestParDoble());

/* Con UNA FACTURA IMPUTADA: tiene que haber de las dos partes. Se arma sobre
   la proyeccion real de los dobles: noviembre de Cencosud en el local de su
   primer pago, reemplazado por una factura que vence el 20/11. */
$proySola = $alqDias->calcular($hDias, $ins)['pagos'];
$blanco = array_values(array_filter($proySola, function ($p) { return $p['mes_pago'] === '2026-11' && $p['cod_provee'] === 'SAUNIC'; }))[0];
$facturaT = ['COD_PROVEE' => 'SAUNIC', 'RAZON_SOC' => 'CENCOSUD', 'T_COMP' => 'FAC', 'N_COMP' => 'A0193000155679',
             'FECHA_VTO' => '2026-11-20', 'FECHA_EMIS' => '2026-11-02', 'IMPORTE_VTO' => 1000.0,
             'IMPORTE_PENDIENTE' => 1000.0, 'NETO_GRAVADO_COMP' => null, 'IMPORTE_COMP' => 1000.0, 'LEYENDA' => ''];
$sinImputarT = $facturaT;
$sinImputarT['N_COMP'] = 'A0193000155680';
$insF = $ins + ['facturas' => [
    'ctx' => AlquileresFacturas::contexto(['SAUNIC'], [], AlquileresFacturas::condicion(true, ['REAL'], true)),
    'pendientes' => [$facturaT, $sinImputarT], 'excluidas' => [], 'vtos' => [], 'en_tango' => [], 'avisos' => [],
    /* La imputacion de Cencosud en noviembre, en el local del primer pago: pisa
       todo lo de Cencosud ahi ese mes. */
    'imputaciones' => [['id' => 1, 'cod_provee' => 'SAUNIC', 'mes' => '2026-11', 'todos' => false,
        'locales' => [$blanco['nro']], 'usuario' => 'ANA', 'fecha' => '2026-11-09 10:00', 'observacion' => null,
        'total_facturas' => 1000.0, 'total_retenciones' => 20.0,
        'facturas' => [['ID' => 1, 'COD_PROVEE' => 'SAUNIC', 'T_COMP' => 'FAC', 'N_COMP' => 'A0193000155679',
            'FECHA_VTO_TANGO' => '2026-11-20', 'PENDIENTE' => 1000.0, 'RETENCION' => 20.0, 'PAGADA_AL_IMPUTAR' => false,
            'ESTADO' => 'PENDIENTE', 'FECHA_PAGADA' => null]],
        'retenciones' => [['tipo' => 1, 'importe' => 20.0]]]],
    'tipos' => [1 => 'Ganancias'], 'tabla_imputacion' => true
]];
$alqDias->ins = $insF;
$prov = new PestProveedor('ALQUILERES');
$prov->alq = $alqDias;
$series = $prov->series($hDias);
$cF = $alqDias->calcular($hDias, $insF);
$pestDias = Alquileres::armarPestana($cF, $hDias);

chequear('el proveedor rinde REAL, PROYECTADO y PAGOS', [true, true, true],
    [isset($series['REAL']), isset($series['PROYECTADO']), isset($series['PAGOS'])]);
chequear('REAL es la factura: pendiente menos retencion, el 20/11', 980.0, $series['REAL']['dias']['2026-11-20']);
chequear('lo que la factura cubre ya no esta en PROYECTADO', false, (bool) array_filter($cF['pagos'],
    function ($p) use ($blanco) { return $p['origen'] !== AlquileresCalculo::FACTURA && $p['nro'] === $blanco['nro']
        && $p['concepto'] === $blanco['concepto'] && $p['mes_pago'] === '2026-11'; }));
chequear('la factura sin imputar no esta en ninguna serie', 980.0,
    round(array_sum($series['REAL']['dias']) + array_sum($series['REAL']['meses']), 2));

$porDia = [];
foreach ($cF['pagos'] as $p) { $porDia[$p['fecha']] = round(($porDia[$p['fecha']] ?? 0) + $p['a_pagar'], 2); }
$malDia = [];
foreach ($hDias->dias() as $d) {
    $tab = round(($series['REAL']['dias'][$d['fecha']] ?? 0) + ($series['PROYECTADO']['dias'][$d['fecha']] ?? 0), 2);
    if (abs($tab - ($porDia[$d['fecha']] ?? 0)) > 0.001 || abs($tab - $series['PAGOS']['dias'][$d['fecha']]) > 0.001) {
        $malDia[] = $d['fecha'];
    }
}
chequear('POR DIA, REAL + PROYECTADO = PAGOS = la lista de pagos de la pestaña', [], $malDia);
$totalSerie = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'], 2); };
chequear('y en total, lo mismo que la pestaña', $pestDias['kpis']['total_horizonte'],
    round($totalSerie($series['REAL']) + $totalSerie($series['PROYECTADO']), 2));
chequear('la pestaña dice cuanto sin imputar', [1, 1000.0],
    [$pestDias['facturas']['kpis']['sin_imputar_n'], $pestDias['facturas']['kpis']['sin_imputar_importe']]);

$cenc = array_column($pestDias['razones'], null, 'cod')['SAUNIC'];
chequear('la factura va en "Facturas imputadas", al final de Cencosud, no en un local', [980.0, 1],
    [$cenc['facturas']['meses']['2026-11']['a_pagar'], count($cenc['facturas']['imputaciones'])]);
chequear('ese mes el local queda sin proyeccion de Cencosud', false,
    isset(array_column($cenc['locales'], null, 'nro')[$blanco['nro']]['meses']['2026-11']));
chequear('con la factura, las razones siguen sumando el total de la pestaña', $pestDias['kpis']['total_horizonte'],
    round(array_sum(array_map(function ($r) { return $r['total']['a_pagar']; }, $pestDias['razones'])), 2));

/* SIN IMPUTACIONES (sin el script 60): la pestaña y el tablero siguen
   sumando lo mismo, todo proyectado. */
$insSin = $insF;
$insSin['facturas']['imputaciones'] = [];
$insSin['facturas']['tabla_imputacion'] = false;
$alqDias->ins = $insSin;
$provSin = new PestProveedor('ALQUILERES');
$provSin->alq = $alqDias;
$seriesSin = $provSin->series($hDias);
$pestSin = Alquileres::armarPestana($alqDias->calcular($hDias, $insSin), $hDias);
chequear('sin imputaciones: REAL vacio y PAGOS = la pestaña', [0.0, $pestSin['kpis']['total_horizonte']],
    [$totalSerie($seriesSin['REAL']), $totalSerie($seriesSin['PAGOS'])]);
chequear('sin el script 60 la sub-pestaña no deja imputar', false, $pestSin['facturas']['tablas']);

$movidos = array_filter($cF['pagos'], function ($x) { return $x['nota_dia'] !== ''; });
chequear('un pago corrido de dia anota su celda con detalle', true,
    !empty($movidos) && isset($series['PAGOS']['detalle'][claveColumna($hDias, array_values($movidos)[0]['fecha'])]));

/** La columna del eje de una fecha: su dia si esta en el tramo, si no su mes */
function claveColumna($h, $fecha) {
    return in_array($fecha, array_column($h->dias(), 'fecha'), true) ? 'DIA|' . $fecha : 'MES|' . substr($fecha, 0, 7);
}

seccion('El tablero: el modulo activo y la fila agrupada');

$meta = CashflowRegistry::meta('ALQUILERES');
chequear('ALQUILERES esta disponible', true, CashflowRegistry::disponible('ALQUILERES'));
chequear('PAGOS es el total de REAL y PROYECTADO', ['PAGOS' => ['REAL', 'PROYECTADO']], $meta['componentes']);

$sql55 = file_get_contents(__DIR__ . '/../sql/cashflow_alquileres_fila.sql');
chequear('el script crea las dos filas, agrupadas', [true, true, true], [
    (bool) preg_match("/'ALQUILERES_REAL'.*'ALQUILERES', 'REAL'/s", $sql55),
    (bool) preg_match("/'ALQUILERES_PROY'.*'ALQUILERES', 'PROYECTADO'/s", $sql55),
    strpos($sql55, "GRUPO = ''ALQUILERES''") !== false]);
chequear('inhabilita la de PAGOS en la misma transaccion', true,
    (bool) preg_match("/BEGIN TRANSACTION;.*SET ACTIVO = 0.*ORIGEN_SERIE = 'PAGOS'.*COMMIT TRANSACTION;/s", $sql55));
chequear('y controla que Cuentas a Pagar Locales no lleve las facturas', true,
    strpos($sql55, "ORIGEN_SERIE = 'PAGOS_ALQUILERES'") !== false);

/* LA FILA AGRUPADA CON EL VALIDADOR DE VERDAD: las dos nuevas activas y la vieja
   inhabilitada es valido; las tres activas, doble conteo. */
$sec = [['CODIGO' => 'COSTOS_INDIRECTOS', 'NOMBRE' => 'CI', 'ROL' => 'MOVIMIENTO', 'ID_PADRE' => null,
         'ORDEN' => 10, 'ACTIVO' => 1]];
$filaA = function ($id, $codigo, $serie, $orden, $activo, $extra = []) {
    return array_merge(['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => 'COSTOS_INDIRECTOS',
        'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'ALQUILERES', 'ORIGEN_SERIE' => $serie,
        'ORDEN' => $orden, 'ACTIVO' => $activo, 'GRUPO' => null, 'NATURALEZA' => null, 'GRUPO_NOMBRE' => null], $extra);
};
$provs = ['ALQUILERES' => $meta + ['codigo' => 'ALQUILERES']];
$nuevas = [
    $filaA(16, 'ALQUILERES', 'PAGOS', 20, 0),
    $filaA(60, 'ALQUILERES_REAL', 'REAL', 21, 1, ['GRUPO' => 'ALQUILERES', 'NATURALEZA' => 'REAL', 'GRUPO_NOMBRE' => 'Alquileres']),
    $filaA(61, 'ALQUILERES_PROY', 'PROYECTADO', 22, 1, ['GRUPO' => 'ALQUILERES', 'NATURALEZA' => 'PROYECTADO'])
];
$v = CashflowEstructura::validar($sec, $nuevas, $provs);
chequear('real y proyectado agrupados, la vieja inhabilitada: valido', [], $v['errores']);
$tres = $nuevas;
$tres[0]['ACTIVO'] = 1;
chequear('con la de PAGOS activa tambien: el validador lo rechaza', true,
    !empty(CashflowEstructura::validar($sec, $tres, $provs)['errores']));

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base: la estructura real no se mira');
} else {
    require_once __DIR__ . '/../../class/conexion.php';
    $cid = (new Conexion())->conectar('central');
    $st = sqlsrv_query($cid, "SELECT ORIGEN_SERIE FROM dbo.RO_T_CASHFLOW_CONF_FILA
                              WHERE ORIGEN_PROVIDER = 'ALQUILERES' AND ACTIVO = 1");
    $activas = [];
    while ($st && ($row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC))) { $activas[] = $row['ORIGEN_SERIE']; }
    chequear('en la estructura real, PROYECTADO activa solo con REAL y sin PAGOS', true,
        !in_array('PROYECTADO', $activas, true) || (in_array('REAL', $activas, true) && !in_array('PAGOS', $activas, true)));
}


seccion('La fecha de fin es el vencimiento del contrato: avisa, no corta');

$contratos = Alquileres::avisoContratos([
    ['nombre' => 'DISTRITO ARCOS', 'habilitado' => true, 'params' => [],
     'contratos' => [['desde' => '2023-08-01', 'hasta' => '2026-12-31']]],
    ['nombre' => 'VIEJO', 'habilitado' => true, 'params' => [],
     'contratos' => [['desde' => '2023-01-01', 'hasta' => '2025-10-31'], ['desde' => '2025-11-01', 'hasta' => '2026-10-31']]],
    ['nombre' => 'LEJOS', 'habilitado' => true, 'params' => [], 'contratos' => [['desde' => '2026-01-01', 'hasta' => '2029-01-01']]],
    ['nombre' => 'CERRADO', 'habilitado' => false, 'params' => [], 'contratos' => [['desde' => '2025-01-01', 'hasta' => '2026-01-01']]],
    ['nombre' => 'TEMPORAL', 'habilitado' => true, 'params' => ['modo' => 'VIGENCIA'],
     'contratos' => [['desde' => '2026-02-03', 'hasta' => '2026-03-03']]],
    ['nombre' => 'SIN', 'habilitado' => true, 'params' => [], 'contratos' => []]
], '2026-11-17', '2027-10');
chequear('vencido: atencion, con el ultimo contrato; ni el cerrado ni el temporal', [Aviso::WARNING, true, false, false],
    [$contratos[0]['nivel'], strpos($contratos[0]['texto'], 'VIEJO (31/10/2026)') !== false,
     strpos($contratos[0]['texto'], 'CERRADO') !== false, strpos($contratos[0]['texto'], 'TEMPORAL') !== false]);
chequear('por vencer dentro del horizonte: informativo, y el de 2029 no', [Aviso::INFO, true, false],
    [$contratos[1]['nivel'], strpos($contratos[1]['texto'], 'DISTRITO ARCOS') !== false,
     strpos($contratos[1]['texto'], 'LEJOS') !== false]);
