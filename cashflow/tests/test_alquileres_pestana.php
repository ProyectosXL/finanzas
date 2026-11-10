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

$h = new Horizonte(0, 4, [], '2026-11-10');
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
chequear('Facturas queda para la proxima entrega', true, strpos($tab, 'Disponible en la próxima entrega') !== false);
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
